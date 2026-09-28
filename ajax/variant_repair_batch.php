<?php
/**
 * @file        ajax/variant_repair_batch.php
 * @brief       AJAX endpoint — réparation batch des imports existants en vraies déclinaisons
 *              Dolibarr (Hotfix 2.4.4, 2/2)
 *
 * Action admin EXPLICITE (jamais automatique) : rattache a posteriori les produits déjà
 * importés comme fiches Dolibarr séparées à leur parent via une vraie déclinaison
 * (ProductCombination), en réutilisant VariantRepairService (qui réutilise lui-même
 * ShopifyProductImporter::linkDolibarrVariant(), sans dupliquer sa logique).
 *
 * Actions :
 * - get_count     : nombre de candidats concernés (comptage préalable + avertissement UI),
 *                   PILOTÉ PAR fix_labels (HIGH-A, Story 63-17 code review) — le total renvoyé
 *                   doit coïncider avec ce que `repair_batch` va réellement traiter pour le même
 *                   fix_labels ; attachTotal/labelOnlyTotal renvoyés en plus pour permettre au JS
 *                   d'expliquer un total à 0 plutôt que de le taire
 * - repair_batch  : traite un lot (cursor/limit/store_id/fix_labels), retourne progression + rapport
 *                   (cursor = pagination PAR CLÉ sur fk_product, PAS un offset numérique — corrigé
 *                   post-review 3-couches, cf. VariantRepairService::repairBatch())
 * - finish        : accusé de fin de passe (l'état « dernière réparation » est déjà persisté
 *                   côté serveur à la fin de chaque lot, cf. VariantRepairService::persistBatchProgress())
 *
 * @package     Doli2Shop
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.4.4
 * @link        https://doli2shop.ptitetete.org
 */

// Story 59-7 : ne pas faire tourner le jeton CSRF sur ce endpoint AJAX. Un appel déclenché
// automatiquement ou de façon répétée/chaînée (traitement par lots, plusieurs appels successifs)
// périmait les liens d'action affichés par d'autres pages du module ouvertes dans la même
// session dès que MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif (investigation 59-1).
// NOTOKENRENEWAL désactive la ROTATION (main.inc.php:333-349) ; la VÉRIFICATION (verifToken(),
// plus bas) reste intégralement active — cet endpoint continue de rejeter tout appel sans jeton
// valide.
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'Include of main fails'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// Security check — admin only
if (!$user->admin) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Access forbidden'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Load translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// CSRF token verification
// Hotfix 2.4.6 (2/2) : ce endpoint réimplémentait en dur la comparaison au mauvais jeton
// (`newToken()` = jeton de la PROCHAINE requête, jamais celui réellement soumis) — cassé en
// silence dès que MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégué au helper
// partagé verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
if (!verifToken()) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Invalid CSRF token'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Set JSON response headers
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

dol_include_once('/doli2shop/class/variantrepairservice.class.php');

$action = GETPOST('action', 'alphanohtml');

// Scope boutique optionnel (0 = toutes les boutiques de l'entité), pattern Story 49-9
$storeId = GETPOSTINT('store_id');
if ($storeId > 0) {
    dol_include_once('/doli2shop/class/storeservice.class.php');
    $storeService = new StoreService($db);
    $scopedStore = $storeService->fetch($storeId);
    if ($scopedStore === null || (int) $scopedStore->entity !== (int) $conf->entity) {
        http_response_code(403);
        echo json_encode(array('success' => false, 'message' => 'Invalid store_id'), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
} else {
    $storeId = 0;
}

$repairService = new VariantRepairService($db, $user, $conf->entity);

// ===========================================
// ACTION: get_count — nombre de candidats concernés (AC #3)
// ===========================================
if ($action == 'get_count') {
    try {
        // Correction post-code-review (HIGH-A, Story 63-17) : AVANT ce correctif, ce total était
        // TOUJOURS le pool combiné (includeLabelOnly=true), indépendamment de l'état de la case
        // "Corriger aussi les libellés" côté écran — alors que `repair_batch` (plus bas) filtre
        // RÉELLEMENT le lot traité sur ce même `fix_labels`. Scénario exact du rapport client
        // (DataImpuls) : 0 candidat "rattachement", N candidats "libellés", case décochée par
        // défaut → le compteur annonçait N, le bouton restait actif, mais `repair_batch` avec
        // fix_labels=0 ne traitait RIEN (pool "rattachement" vide) → rapport final "0/0/0" sans
        // explication. Le total renvoyé ici DOIT désormais coïncider avec ce que `repair_batch`
        // va réellement traiter pour le MÊME `fix_labels` — d'où `attachTotal`/`labelOnlyTotal`
        // renvoyés en plus, pour que le JS puisse expliquer un total à 0 plutôt que de le taire
        // (cf admin/sync_products.php, VariantRepairNothingToDoCheckLabels).
        $fixLabelsForCount = (bool) GETPOSTINT('fix_labels');
        $attachTotal = $repairService->countRepairCandidates($storeId, false);
        $combinedTotal = $repairService->countRepairCandidates($storeId, true);
        $labelOnlyTotal = max(0, $combinedTotal - $attachTotal);
        $total = $fixLabelsForCount ? $combinedTotal : $attachTotal;

        echo json_encode(array(
            'success' => true,
            'total' => $total,
            'attachTotal' => $attachTotal,
            'labelOnlyTotal' => $labelOnlyTotal,
            'enabled' => $repairService->isEnabled(),
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('success' => false, 'message' => $e->getMessage()), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: repair_batch — traite un lot (AC #1, #2, #5)
// ===========================================
if ($action == 'repair_batch') {
    // Extend time limit for batch processing (pattern stock_recalage_batch.php)
    @set_time_limit(0);

    // CORRECTION post-review 3-couches : curseur de pagination PAR CLÉ (dernier fk_product vu),
    // PLUS un offset numérique — cf. VariantRepairService::repairBatch() docblock (CRITICAL).
    $cursor = GETPOSTINT('cursor');
    $limit = GETPOSTINT('limit');
    if ($limit <= 0) {
        $limit = VariantRepairService::DEFAULT_BATCH_SIZE;
    }
    $fixLabels = (bool) GETPOSTINT('fix_labels');

    try {
        $batchResult = $repairService->repairBatch($cursor, $limit, $storeId, $fixLabels);

        echo json_encode(array(
            'success' => true,
            'processed' => $batchResult['processed'],
            'repaired' => $batchResult['repaired'],
            'skipped' => $batchResult['skipped'],
            'errors' => $batchResult['errors'],
            'labelsFixed' => $batchResult['labelsFixed'],
            'unfixable' => $batchResult['unfixable'],
            'hasMore' => $batchResult['hasMore'],
            'cursor' => (string) $batchResult['nextCursor'],
            'errorDetails' => $batchResult['errorDetails'],
            'message' => '',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'processed' => 0,
            'repaired' => 0,
            'skipped' => 0,
            'errors' => 0,
            'labelsFixed' => 0,
            'unfixable' => 0,
            'hasMore' => false,
            'cursor' => null,
            'errorDetails' => array(),
            'message' => $e->getMessage(),
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: finish — accusé de fin de passe (état déjà persisté par lot côté serveur)
// ===========================================
if ($action == 'finish') {
    echo json_encode(array('success' => true), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(array('success' => false, 'message' => 'Unknown action'), JSON_HEX_TAG | JSON_HEX_AMP);
exit;
