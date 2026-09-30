<?php
/**
 * @file        ajax/offsale_products_batch.php
 * @brief       AJAX endpoint — traitement des produits hors vente déjà poussés (Story 58-5, AC4)
 *
 * Action admin EXPLICITE (jamais automatique) : traite les produits Dolibarr passés hors vente
 * (tosell=0) mais déjà mappés/poussés sur Shopify — laisser en brouillon / archiver / supprimer,
 * au choix de l'administrateur. Réutilise OffSaleProductsService, deux temps obligatoires
 * (scan/aperçu SANS écriture, puis application EXPLICITE confirm=1) — pattern
 * ajax/discount_repair_batch.php (Hotfix 2.4.5, AC4).
 *
 * Actions :
 * - get_count   : nombre de produits candidats (comptage préalable)
 * - scan_batch  : DRY-RUN, aucune écriture — aperçu (référence, libellé, statut Shopify relu)
 * - repair_batch: applique l'action choisie (confirm=1 obligatoire ; confirm_delete=1 EN PLUS
 *                 pour l'action "delete", irréversible — double confirmation distincte, AC4)
 * - finish      : accusé de fin de passe (aucun état supplémentaire à persister ici)
 *
 * @package     Doli2Shop
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.5.0
 * @link        https://doli2shop.ptitetete.org
 */

// Story 59-7 : ne pas faire tourner le jeton CSRF sur ce endpoint AJAX. Un appel déclenché
// automatiquement ou de façon répétée/chaînée (ce script est appelé en boucle — get_count,
// scan_batch, repair_batch) périmait les liens d'action affichés par d'autres pages du module
// ouvertes dans la même session dès que MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif.
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

// Security check — admin only (pattern ajax/discount_repair_batch.php)
if (!$user->admin) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Access forbidden'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Load translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// CSRF token verification — verifToken() UNIQUEMENT (jamais de comparaison à newToken(), qui
// désigne le jeton de la PROCHAINE requête — hotfix 2.4.6, cf. ajax/discount_repair_batch.php).
if (!verifToken()) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Invalid CSRF token'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Set JSON response headers
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

dol_include_once('/doli2shop/class/offsaleproductsservice.class.php');

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

$offsaleService = new OffSaleProductsService($db, $conf->entity);

// ===========================================
// ACTION: get_count — nombre de produits candidats
// ===========================================
if ($action == 'get_count') {
    try {
        $total = $offsaleService->countCandidates($storeId);
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
        $limit = OffSaleProductsService::DEFAULT_BATCH_SIZE;
    }

    try {
        $batchResult = $offsaleService->scanBatch($cursor, $storeId, $limit);

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
// ACTION: repair_batch — applique l'action choisie (confirm=1 obligatoire)
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
            'message' => 'Paramètre confirm=1 requis pour appliquer le traitement',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }

    $offsaleAction = GETPOST('offsale_action', 'alphanohtml');
    if (!in_array($offsaleAction, array(
        OffSaleProductsService::ACTION_LEAVE_DRAFT,
        OffSaleProductsService::ACTION_ARCHIVE,
        OffSaleProductsService::ACTION_DELETE,
    ), true)) {
        http_response_code(400);
        echo json_encode(array(
            'success' => false,
            'message' => 'Paramètre offsale_action invalide',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }

    // AC4 : double confirmation DISTINCTE obligatoire pour l'action irréversible "delete" — en
    // PLUS de confirm=1. Vérifiée ici (niveau HTTP) ET dans le service (défense en profondeur).
    $confirmDelete = (GETPOSTINT('confirm_delete') === 1);

    $cursor = GETPOSTINT('cursor');
    $limit = GETPOSTINT('limit');
    if ($limit <= 0) {
        $limit = OffSaleProductsService::DEFAULT_BATCH_SIZE;
    }

    try {
        $batchResult = $offsaleService->repairBatch($cursor, $storeId, $limit, $offsaleAction, $confirmDelete);

        if (!empty($batchResult['confirmRequired'])) {
            http_response_code(400);
            echo json_encode(array(
                'success' => false,
                'message' => $langs->trans('OffSaleDeleteConfirmRequired'),
            ), JSON_HEX_TAG | JSON_HEX_AMP);
            exit;
        }

        // CRITICAL multi-boutiques (Code Review 3-couches post-58-5) : licenseBlocked est
        // désormais posé PAR GROUPE fk_store (cf. OffSaleProductsService::processStoreGroup()) —
        // un lot storeId=0 peut mélanger une boutique bloquée et une boutique autorisée (ex. la
        // boutique par défaut, jamais bloquée). On ne coupe donc la réponse en 403 QUE si RIEN
        // n'a été traité dans ce lot ; sinon les candidats de la boutique bloquée apparaissent
        // dans errorDetails et le lot continue normalement (pagination incluse).
        if (!empty($batchResult['licenseBlocked']) && (int) $batchResult['processed'] === 0) {
            http_response_code(403);
            echo json_encode(array(
                'success' => false,
                'message' => $langs->trans('OffSaleLicenseBlocked'),
            ), JSON_HEX_TAG | JSON_HEX_AMP);
            exit;
        }

        if (!empty($batchResult['lockBusy'])) {
            echo json_encode(array(
                'success' => false,
                'processed' => 0,
                'leftDraft' => 0,
                'archived' => 0,
                'deleted' => 0,
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
            'leftDraft' => $batchResult['leftDraft'],
            'archived' => $batchResult['archived'],
            'deleted' => $batchResult['deleted'],
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
            'leftDraft' => 0,
            'archived' => 0,
            'deleted' => 0,
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
