<?php
/**
 * @file        ajax/wizard_sync.php
 * @brief       AJAX endpoint for wizard step 3 — batch product synchronization
 *
 * Handles two actions:
 * - get_count: Returns the total number of Shopify products via GraphQL
 * - sync_batch: Imports a batch of 50 products and returns stats + cursor for next batch
 *
 * @package     Doli2Shop
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.2.0
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

// Security check — admin only (Story 6.3 security requirement)
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

$action = GETPOST('action', 'aZ09');

// ===========================================
// ACTION: get_count — Return total Shopify product count (Task 2.4)
// ===========================================

if ($action == 'get_count') {
	dol_include_once('/doli2shop/class/shopifyapi.class.php');

	// Story 62-6 (AC1, CRITICAL/MEDIUM review 3 couches 2026-09-23) : toute la logique (comptage
	// GraphQL, vérification `errors`/`data` absent, message d'échec générique jamais exposé au
	// client) vit désormais dans ShopifyApi::buildProductsCountAjaxResponse() — point unique
	// partagé avec ajax/sync_products_batch.php, testé à l'exécution (voir
	// ShopifyApiGraphQLDataPathTest, AjaxGetCountGraphQLFailureGuardTest). Ce fichier se contente
	// de construire $shopifyApi et d'échoer le retour tel quel. Avant ce correctif, l'échec
	// renvoyait $e->getMessage() brut au client, lequel contient le json_encode() intégral de la
	// réponse Shopify — désormais aligné sur le pattern déjà en place dans
	// ajax/sync_products_batch.php depuis la Story 63-19/LOW 10 (détail complet en
	// dol_syslog LOG_ERR, message générique côté JSON).
	try {
		$shopifyApi = new ShopifyApi($db);
		$result = ShopifyApi::buildProductsCountAjaxResponse($shopifyApi, 'wizard_sync.php (get_count)');
	} catch (Exception $e) {
		$result = ShopifyApi::buildProductsCountAjaxFailure('wizard_sync.php (get_count)', $e);
	}

	http_response_code($result['httpCode']);
	echo json_encode($result['body'], JSON_HEX_TAG | JSON_HEX_AMP);
	exit;
}

// ===========================================
// ACTION: sync_batch — Import a batch of products (Task 2.2, 2.3)
// ===========================================

if ($action == 'sync_batch') {
	// Extend time limit for batch processing (Task 2.6)
	@set_time_limit(0);

	dol_include_once('/doli2shop/class/shopifyproductimporter.class.php');

	$cursor = GETPOST('cursor', 'alphanohtml');
	if (empty($cursor)) {
		$cursor = null;
	}

	try {
		$importer = new ShopifyProductImporter($db, $user, $conf->entity);
		$result = $importer->importProducts(50, $cursor, false);

		// Build response (Task 2.3, L2 fix: include total field per spec)
		$response = array(
			'success' => !empty($result['success']),
			'processed' => 0,
			'total' => 0,
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors' => 0,
			'hasMore' => !empty($result['hasNextPage']),
			'cursor' => isset($result['endCursor']) ? $result['endCursor'] : null,
			'errorDetails' => array(),
			'message' => isset($result['message']) ? $result['message'] : '',
		);

		// Fill stats from importer result
		if (isset($result['stats'])) {
			$response['created'] = (int) ($result['stats']['created'] ?? 0);
			$response['updated'] = (int) ($result['stats']['updated'] ?? 0);
			$response['skipped'] = (int) ($result['stats']['skipped'] ?? 0);
			$response['errors'] = (int) ($result['stats']['errors'] ?? 0);
			$response['processed'] = $response['created'] + $response['updated'] + $response['skipped'] + $response['errors'];
		}

		// Collect error details from importer
		if (!empty($importer->errors)) {
			foreach ($importer->errors as $err) {
				$response['errorDetails'][] = $err;
			}
		}

		echo json_encode($response, JSON_HEX_TAG | JSON_HEX_AMP);

	} catch (Exception $e) {
		// API failure (Task 2.5)
		http_response_code(500);
		echo json_encode(array(
			'success' => false,
			'processed' => 0,
			'created' => 0,
			'updated' => 0,
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

// Unknown action
http_response_code(400);
echo json_encode(array('success' => false, 'message' => 'Unknown action'), JSON_HEX_TAG | JSON_HEX_AMP);
exit;
