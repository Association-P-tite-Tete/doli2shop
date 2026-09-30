<?php
/**
 * @file        ajax/discount_repair_batch.php
 * @brief       AJAX endpoint — recorrection batch des remises % sous-évaluées à l'import (Hotfix 2.4.5, AC4)
 *
 * Action admin EXPLICITE (jamais automatique) : recorrige les commandes Shopify importées AVANT
 * le fix v2.2.9 dont la ligne de remise globale en pourcentage est sous-évaluée d'un facteur
 * (1 + TVA) — cf. remontée #1025 Nicolas Graillon. Réutilise DiscountRepairService, deux temps
 * obligatoires (scan/aperçu SANS écriture, puis application EXPLICITE confirm=1) — pattern
 * ajax/stock_recalage_batch.php (Story 52-2) et VariantRepairService (hotfix 2.4.4 2/2).
 *
 * Actions :
 * - get_count   : nombre de commandes candidates (comptage préalable)
 * - scan_batch  : DRY-RUN, aucune écriture — aperçu détaillé (classification + montants)
 * - repair_batch: applique les corrections (confirm=1 obligatoire côté requête)
 * - finish      : accusé de fin de passe (aucun état supplémentaire à persister ici)
 *
 * @package     Doli2Shop
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.4.5
 * @link        https://doli2shop.ptitetete.org
 */

// Story 59-7 : ne pas faire tourner le jeton CSRF sur ce endpoint AJAX. Un appel déclenché
// automatiquement ou de façon répétée/chaînée (ce script est appelé en boucle — get_count,
// scan_batch, repair_batch) périmait les liens d'action affichés par d'autres pages du module
// ouvertes dans la même session dès que MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif
// (investigation 59-1). NOTOKENRENEWAL désactive la ROTATION (main.inc.php:333-349) ; la
// VÉRIFICATION (verifToken(), plus bas) reste intégralement active — cet endpoint continue de
// rejeter tout appel sans jeton valide.
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

// Security check — admin only (pattern ajax/stock_recalage_batch.php)
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

dol_include_once('/doli2shop/class/discountrepairservice.class.php');

$action = GETPOST('action', 'alphanohtml');

// Scope boutique optionnel (0 = toutes les boutiques de l'entité) — pattern Story 49-9
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

$repairService = new DiscountRepairService($db, $conf->entity);

// ===========================================
// ACTION: get_count — nombre de commandes candidates
// ===========================================
if ($action == 'get_count') {
    try {
        $total = $repairService->countCandidateOrders($storeId);
        echo json_encode(array(
            'success' => true,
            'total' => $total,
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(array('success' => false, 'message' => $e->getMessage()), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: scan_batch — DRY-RUN, aucune écriture (aperçu avant confirmation)
// ===========================================
if ($action == 'scan_batch') {
    @set_time_limit(0);

    $cursor = GETPOSTINT('cursor');
    $limit = GETPOSTINT('limit');
    if ($limit <= 0) {
        $limit = DiscountRepairService::DEFAULT_BATCH_SIZE;
    }

    try {
        $batchResult = $repairService->scanBatch($cursor, $storeId, $limit);

        // Aplati les montants pour affichage (rows contient déjà des types scalaires simples)
        echo json_encode(array(
            'success' => true,
            'rows' => $batchResult['rows'],
            'hasMore' => $batchResult['hasMore'],
            'cursor' => (string) $batchResult['nextCursor'],
            'message' => '',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'rows' => array(),
            'hasMore' => false,
            'cursor' => null,
            'message' => $e->getMessage(),
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: repair_batch — applique les corrections (confirm=1 obligatoire)
// ===========================================
if ($action == 'repair_batch') {
    @set_time_limit(0);

    // Garde-fou explicite : jamais d'application accidentelle. confirm=1 doit être posé
    // délibérément côté UI (bouton "Appliquer" distinct du bouton "Aperçu").
    $confirm = GETPOSTINT('confirm');
    if ($confirm !== 1) {
        http_response_code(400);
        echo json_encode(array(
            'success' => false,
            'message' => 'Paramètre confirm=1 requis pour appliquer les corrections',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }

    $cursor = GETPOSTINT('cursor');
    $limit = GETPOSTINT('limit');
    if ($limit <= 0) {
        $limit = DiscountRepairService::DEFAULT_BATCH_SIZE;
    }

    try {
        $batchResult = $repairService->repairBatch($cursor, $storeId, $limit);

        // Story 58-2 (AC1) : verrou de réentrance déjà détenu (autre onglet/exécution en cours
        // pour la même entité) — DISTINCT d'un résultat à zéro légitime (aucun candidat). Réponse
        // HTTP 200 + success=false : le JS existant (admin/discount_repair.php) affiche déjà
        // data.message dans cette branche, sans aucune modification nécessaire.
        if (!empty($batchResult['lockBusy'])) {
            echo json_encode(array(
                'success' => false,
                'processed' => 0,
                'corrected' => 0,
                'manualRequired' => 0,
                'manualReview' => 0,
                'notAffected' => 0,
                'errors' => 0,
                'errorDetails' => array(),
                'hasMore' => false,
                'cursor' => (string) $batchResult['nextCursor'],
                'message' => $langs->trans('DiscountRepairAlreadyRunning'),
            ), JSON_HEX_TAG | JSON_HEX_AMP);
            exit;
        }

        echo json_encode(array(
            'success' => true,
            'processed' => $batchResult['processed'],
            'corrected' => $batchResult['corrected'],
            'manualRequired' => $batchResult['manualRequired'],
            'manualReview' => $batchResult['manualReview'],
            'notAffected' => $batchResult['notAffected'],
            'errors' => $batchResult['errors'],
            'errorDetails' => $batchResult['errorDetails'],
            'hasMore' => $batchResult['hasMore'],
            'cursor' => (string) $batchResult['nextCursor'],
            'message' => '',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'processed' => 0,
            'corrected' => 0,
            'manualRequired' => 0,
            'manualReview' => 0,
            'notAffected' => 0,
            'errors' => 0,
            'errorDetails' => array(),
            'hasMore' => false,
            'cursor' => null,
            'message' => $e->getMessage(),
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: finish — accusé de fin de passe (aucun état "dernier run" additionnel côté serveur)
// ===========================================
if ($action == 'finish') {
    echo json_encode(array('success' => true), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(array('success' => false, 'message' => 'Unknown action'), JSON_HEX_TAG | JSON_HEX_AMP);
exit;
