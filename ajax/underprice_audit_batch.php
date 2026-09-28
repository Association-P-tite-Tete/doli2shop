<?php
/**
 * @file        ajax/underprice_audit_batch.php
 * @brief       AJAX endpoint — audit (jamais de correction) des commandes Shopify importées sous-
 *              évaluées avant le correctif e825f456, story
 *              reprise-des-commandes-importees-en-prix-hors-taxes
 *
 * Outil admin EXPLICITE, JAMAIS automatique, et surtout : JAMAIS correcteur. Réutilise
 * UnderpricedOrderAuditService, deux temps (scan/aperçu SANS écriture, puis audit EXPLICITE
 * confirm=1 qui ne persiste QUE le verdict — aucune commande/facture/paiement n'est modifié) —
 * pattern ajax/discount_repair_batch.php (Hotfix 2.4.5).
 *
 * Actions :
 * - get_count   : nombre de commandes candidates (jamais encore auditées)
 * - scan_batch  : DRY-RUN, aucune écriture — aperçu détaillé (verdict + montants)
 * - audit_batch : persiste le verdict (confirm=1 obligatoire côté requête) — jamais de correction
 * - finish      : accusé de fin de passe (aucun état supplémentaire à persister ici)
 *
 * @package     Doli2Shop
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.5.4
 * @link        https://doli2shop.ptitetete.org
 */

// Cf. ajax/discount_repair_batch.php (Story 59-7) : ce endpoint est appelé en boucle (get_count,
// scan_batch, audit_batch) — désactiver la ROTATION du jeton CSRF évite de périmer les liens
// d'action d'autres pages ouvertes dans la même session. La VÉRIFICATION reste intégralement
// active (verifToken(), plus bas).
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

// CSRF token verification — helper partagé, jamais de comparaison en dur à newToken() (Hotfix 2.4.6)
if (!verifToken()) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Invalid CSRF token'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Set JSON response headers
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

dol_include_once('/doli2shop/class/underpricedorderauditservice.class.php');

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

// AC5 : piste d'audit — l'utilisateur déclencheur n'est persisté que par audit_batch() (scan_batch
// ne persiste rien, get_count/finish ne concernent aucune commande).
$auditService = new UnderpricedOrderAuditService($db, $conf->entity, (int) $user->id);

// ===========================================
// ACTION: get_count — nombre de commandes candidates
// ===========================================
if ($action == 'get_count') {
    try {
        $total = $auditService->countCandidateOrders($storeId);
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
        $limit = UnderpricedOrderAuditService::DEFAULT_BATCH_SIZE;
    }

    try {
        $batchResult = $auditService->scanBatch($cursor, $storeId, $limit);

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
// ACTION: audit_batch — persiste le verdict (confirm=1 obligatoire) — JAMAIS de correction
// ===========================================
if ($action == 'audit_batch') {
    @set_time_limit(0);

    // Garde-fou explicite : jamais de persistance accidentelle. confirm=1 doit être posé
    // délibérément côté UI (bouton "Lancer l'audit" distinct du bouton "Aperçu").
    $confirm = GETPOSTINT('confirm');
    if ($confirm !== 1) {
        http_response_code(400);
        echo json_encode(array(
            'success' => false,
            'message' => 'Paramètre confirm=1 requis pour enregistrer l\'audit',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }

    $cursor = GETPOSTINT('cursor');
    $limit = GETPOSTINT('limit');
    if ($limit <= 0) {
        $limit = UnderpricedOrderAuditService::DEFAULT_BATCH_SIZE;
    }

    try {
        $batchResult = $auditService->auditBatch($cursor, $storeId, $limit);

        if (!empty($batchResult['lockBusy'])) {
            echo json_encode(array(
                'success' => false,
                'processed' => 0,
                'alreadyCorrect' => 0,
                'manualRequired' => 0,
                'manualReview' => 0,
                'errors' => 0,
                'errorDetails' => array(),
                'totalDeltaTtc' => 0,
                'totalDeltaCurrencies' => array(),
                'hasMore' => false,
                'cursor' => (string) $batchResult['nextCursor'],
                'message' => $langs->trans('UnderpriceAuditAlreadyRunning'),
            ), JSON_HEX_TAG | JSON_HEX_AMP);
            exit;
        }

        echo json_encode(array(
            'success' => true,
            'processed' => $batchResult['processed'],
            'alreadyCorrect' => $batchResult['alreadyCorrect'],
            'manualRequired' => $batchResult['manualRequired'],
            'manualReview' => $batchResult['manualReview'],
            'errors' => $batchResult['errors'],
            'errorDetails' => $batchResult['errorDetails'],
            'totalDeltaTtc' => $batchResult['totalDeltaTtc'],
            // Edge Case Hunter (12/09) : devises réellement comptées dans totalDeltaTtc — permet
            // au JS de refuser un total unique quand un lot mélange plusieurs boutiques en devises
            // différentes (storeId=0) au lieu d'afficher un montant sans signification.
            'totalDeltaCurrencies' => $batchResult['totalDeltaCurrencies'],
            'hasMore' => $batchResult['hasMore'],
            'cursor' => (string) $batchResult['nextCursor'],
            'message' => '',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'processed' => 0,
            'alreadyCorrect' => 0,
            'manualRequired' => 0,
            'manualReview' => 0,
            'errors' => 0,
            'errorDetails' => array(),
            'totalDeltaTtc' => 0,
            'totalDeltaCurrencies' => array(),
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
