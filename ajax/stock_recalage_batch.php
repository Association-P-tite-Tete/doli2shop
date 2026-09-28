<?php
/**
 * @file        ajax/stock_recalage_batch.php
 * @brief       AJAX endpoint — recalage initial en masse du stock Dolibarr → Shopify (Story 52-2)
 *
 * Action admin EXPLICITE (jamais automatique) : pousse le stock Dolibarr absolu de tous les
 * produits mappés vers Shopify, par lots paginés (rate-limit aware), en réutilisant
 * StockRecalageService (qui réutilise lui-même ShopifyStockTrigger::updateShopifyStock(), sans
 * dupliquer sa logique de push).
 *
 * Actions :
 * - get_count      : nombre de produits mappés concernés (comptage préalable + avertissement UI)
 * - recalage_batch : traite un lot (curseur/offset), retourne progression + compteurs
 * - finish         : persiste l'état « dernier recalage » (horodatage + compteurs cumulés)
 *
 * @package     Doli2Shop
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.4.2
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

dol_include_once('/doli2shop/class/stockrecalageservice.class.php');

$action = GETPOST('action', 'alphanohtml');

// Story 49-9 : scope boutique optionnel (0 = toutes les boutiques de l'entité)
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

$recalageService = new StockRecalageService($db, $conf->entity);

// ===========================================
// ACTION: get_count — nombre de produits mappés concernés (AC #2)
// ===========================================
if ($action == 'get_count') {
    try {
        $total = $recalageService->countMappedProducts($storeId);
        echo json_encode(array(
            'success' => true,
            'total' => $total,
            // Story 53-3 (site 9) : cohérence statut/gate ajax — $storeId (résolu ci-dessus) est
            // désormais transmis, alors qu'avant cette story isEnabled() ignorait le scoping réel.
            'enabled' => $recalageService->isEnabled($storeId),
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('success' => false, 'message' => $e->getMessage()), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: recalage_batch — traite un lot (AC #1, #3, #4, #7)
// ===========================================
if ($action == 'recalage_batch') {
    // Extend time limit for batch processing (pattern sync_products_batch.php)
    @set_time_limit(0);

    $offset = GETPOSTINT('offset');

    try {
        // Story 53-3 (site 9) : idem get_count — gate anticipé avec le $storeId réel du lot.
        if (!$recalageService->isEnabled($storeId)) {
            echo json_encode(array(
                'success' => true,
                'processed' => 0,
                'pushed' => 0,
                'skipped' => 0,
                'errors' => 0,
                'hasMore' => false,
                'cursor' => null,
                'errorDetails' => array(),
                'message' => 'Synchronisation stocks Dolibarr → Shopify désactivée dans la configuration',
            ), JSON_HEX_TAG | JSON_HEX_AMP);
            exit;
        }

        $batchResult = $recalageService->processBatch($offset, $storeId);

        echo json_encode(array(
            'success' => true,
            'processed' => $batchResult['processed'],
            'pushed' => $batchResult['pushed'],
            'skipped' => $batchResult['skipped'],
            'errors' => $batchResult['errors'],
            'hasMore' => $batchResult['hasMore'],
            'cursor' => (string) $batchResult['nextOffset'],
            'errorDetails' => $batchResult['errorDetails'],
            'message' => '',
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'processed' => 0,
            'pushed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'hasMore' => false,
            'cursor' => null,
            'errorDetails' => array(),
            'message' => $e->getMessage(),
        ), JSON_HEX_TAG | JSON_HEX_AMP);
    }
    exit;
}

// ===========================================
// ACTION: finish — persiste l'état « dernier recalage » (AC #6)
// ===========================================
if ($action == 'finish') {
    // MEDIUM-1 (review 52-2) : l'état « dernier recalage » est déjà persisté côté SERVEUR à la fin
    // de CHAQUE lot (StockRecalageService::processBatch → persistBatchProgress). finish ne fait plus
    // confiance aux totaux du navigateur (perdus si l'onglet se ferme en cours de passe) : c'est un
    // simple accusé de fin de passe. L'état persisté reflète le dernier lot réellement traité.
    echo json_encode(array('success' => true), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Unknown action
http_response_code(400);
echo json_encode(array('success' => false, 'message' => 'Unknown action'), JSON_HEX_TAG | JSON_HEX_AMP);
exit;
