<?php
/**
 * @file        ajax/sync_products_batch.php
 * @brief       AJAX endpoint for manual product synchronization — batch processing with report
 *
 * Handles two actions:
 * - get_count: Returns the total number of products to synchronize (direction-aware)
 * - sync_batch: Synchronizes a batch of products according to configured direction
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

$action = GETPOST('action', 'alphanohtml');
$direction = GETPOST('direction', 'alphanohtml');
$searchRef = GETPOST('search_ref', 'alphanohtml');

// Story 49-9 : scope boutique optionnel
$storeId = GETPOST('store_id', 'int');
$scopedStore = null;
if ($storeId > 0) {
	dol_include_once('/doli2shop/class/storeservice.class.php');
	$storeService = new StoreService($db);
	$scopedStore = $storeService->fetch($storeId);
	if ($scopedStore === null || (int) $scopedStore->entity !== (int) $conf->entity) {
		http_response_code(403);
		echo json_encode(array('success' => false, 'message' => 'Invalid store_id'), JSON_HEX_TAG | JSON_HEX_AMP);
		exit;
	}
}

// Story 53-5 (site 4a) : gate via SyncFlowPolicy, déplacé APRÈS la résolution du store (storeId
// per-store désormais effectif — avant cette story, l'ancienne garde globale tournait AVANT la
// résolution et ignorait donc tout override per-store). Formule OU (product_create OU
// product_update) alignée sur le gate d'entrée grossier de ShopifyProductImporter::isImportEnabled()
// (site 2a) : ce batch mêle créations et mises à jour (onlyNew=false passé à importProducts()),
// la granularité fine (bloquer uniquement les créations ou les mises à jour) reste posée au point
// de décision fin (site 2b, ShopifyProductImporter::processProduct()).
dol_include_once('/doli2shop/class/syncflowpolicy.class.php');
$syncFlowPolicy = new SyncFlowPolicy($db, $conf->entity);

if ($direction == 'shopify_to_dolibarr'
	&& !$syncFlowPolicy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_CREATE, 'shopify_to_dolibarr', $storeId)
	&& !$syncFlowPolicy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_UPDATE, 'shopify_to_dolibarr', $storeId)) {
	http_response_code(403);
	echo json_encode(array('success' => false, 'message' => 'Product sync from Shopify is not enabled in configuration'), JSON_HEX_TAG | JSON_HEX_AMP);
	exit;
}
if ($direction == 'dolibarr_to_shopify'
	&& !$syncFlowPolicy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_CREATE, 'dolibarr_to_shopify', $storeId)
	&& !$syncFlowPolicy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_UPDATE, 'dolibarr_to_shopify', $storeId)) {
	http_response_code(403);
	echo json_encode(array('success' => false, 'message' => 'Product sync to Shopify is not enabled in configuration'), JSON_HEX_TAG | JSON_HEX_AMP);
	exit;
}

/**
 * Build the WHERE clause for Dolibarr product queries (DRY helper — M3 fix)
 *
 * Story 63-19 (AC1) : le périmètre catégorie est désormais l'ARBRE complet (racine +
 * descendants), calculé par ProductScopeHelper — PARTAGÉ avec le CRON
 * (class/importproducts.class.php), admin/sync_products.php et ajax/search_products.php. Avant
 * cette story, ce endpoint filtrait "catégorie exacte" (cp.fk_categorie = $defaultCategoryId),
 * divergent du CRON qui traite l'arbre.
 *
 * @param DoliDB  $db                Database handler
 * @param int     $entityId          Entity ID
 * @param int     $defaultCategoryId Catégorie racine configurée — HIGH 5 (code review 63-19) :
 *                                   n'est plus utilisé pour DÉCIDER d'appliquer ou non le filtre
 *                                   catégorie (il l'est TOUJOURS désormais, voir $scopeCategoryIds).
 *                                   Conservé dans la signature pour compat des call sites existants.
 * @param int[]   $scopeCategoryIds  Arbre de catégories dans le périmètre (racine + descendants,
 *                                   déjà filtré par entité — ProductScopeHelper::getCategoryTreeIds()).
 *                                   Un tableau VIDE (racine non configurée, introuvable, ou d'une
 *                                   autre entité) applique tout de même le filtre — via
 *                                   ProductScopeHelper::buildCategoryInClause(), qui renvoie "(0)" et
 *                                   ne matche jamais rien : périmètre vide, JAMAIS "tout le catalogue"
 *                                   par accident.
 *
 *                                   HIGH 5 (code review) : avant ce correctif, "catégorie non
 *                                   configurée" (defaultCategoryId <= 0) désactivait CE filtre —
 *                                   ce endpoint synchronisait alors silencieusement TOUT LE CATALOGUE
 *                                   `tosell = 1`, pendant que admin/sync_products.php affichait "0"
 *                                   (bloc de stats sauté) et que le CRON traitait ZÉRO produit
 *                                   (IN (0)) : trois comportements différents pour la même
 *                                   configuration — exactement le symptôme que cette story corrige.
 *                                   Le filtre s'applique désormais TOUJOURS, unifiant les 3 sites sur
 *                                   "catégorie non configurée = périmètre vide, jamais tout le
 *                                   catalogue".
 * @param string  $searchRef         Search term (empty = no filter)
 * @return string SQL WHERE clause (starts with " WHERE ")
 */
function buildDolibarrProductWhereClause($db, $entityId, $defaultCategoryId, array $scopeCategoryIds, $searchRef)
{
	$where = " WHERE p.entity = " . (int) $entityId;
	$where .= " AND p.tosell = 1";
	$where .= " AND (p.fk_parent IS NULL OR p.fk_parent = 0)";
	$where .= " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac WHERE pac.fk_product_child = p.rowid)";
	$where .= " AND cp.fk_categorie IN " . ProductScopeHelper::buildCategoryInClause($scopeCategoryIds);

	// Apply search filter
	if (!empty($searchRef)) {
		$escapedTerm = $db->escape($searchRef);
		if (is_numeric($searchRef)) {
			$where .= " AND (p.ref = '" . $escapedTerm . "' OR p.ref LIKE '%" . $escapedTerm . "%' OR p.label LIKE '%" . $escapedTerm . "%' OR p.barcode LIKE '%" . $escapedTerm . "%')";
		} else {
			$where .= " AND (p.ref LIKE '%" . $escapedTerm . "%' OR p.label LIKE '%" . $escapedTerm . "%' OR p.barcode LIKE '%" . $escapedTerm . "%')";
		}
	}

	return $where;
}

/**
 * Build the FROM clause for Dolibarr product queries (DRY helper)
 *
 * @return string SQL FROM clause (starts with " FROM ")
 */
function buildDolibarrProductFromClause()
{
	return " FROM " . MAIN_DB_PREFIX . "product as p"
		. " INNER JOIN " . MAIN_DB_PREFIX . "categorie_product as cp ON cp.fk_product = p.rowid";
}

// ===========================================
// ACTION: get_count — Return total product count to synchronize (Task 2.2)
// ===========================================

if ($action == 'get_count') {
	try {
		if ($direction == 'shopify_to_dolibarr') {
			// Shopify -> Dolibarr : count via GraphQL
			// Story 62-6 (AC1, CRITICAL review 3 couches 2026-09-23) — cf. commentaire jumeau
			// dans ajax/wizard_sync.php : toute la logique (comptage GraphQL, vérification
			// `errors`/`data` absent, message d'échec générique) vit désormais dans
			// ShopifyApi::buildProductsCountAjaxResponse() — point unique partagé, testé à
			// l'exécution. Avant ce correctif, un jeton mort et un catalogue réellement vide
			// produisaient exactement le même 'success' => true, 'total' => 0.
			dol_include_once('/doli2shop/class/shopifyapi.class.php');
			$shopifyApi = ($scopedStore !== null) ? ShopifyApi::forStore($db, $scopedStore) : new ShopifyApi($db);

			$result = ShopifyApi::buildProductsCountAjaxResponse($shopifyApi, 'sync_products_batch.php (get_count)');
			if ($result['httpCode'] === 200) {
				$result['body']['direction'] = 'shopify_to_dolibarr';
			}

			http_response_code($result['httpCode']);
			echo json_encode($result['body'], JSON_HEX_TAG | JSON_HEX_AMP);
			exit;

		} else {
			// Dolibarr -> Shopify : count via SQL
			dol_include_once('/doli2shop/class/shopifyapi.class.php');
			dol_include_once('/doli2shop/class/configurationMigrator.class.php');
			dol_include_once('/doli2shop/class/productscopehelper.class.php');

			$shopifyApi = ($scopedStore !== null) ? ShopifyApi::forStore($db, $scopedStore) : new ShopifyApi($db);
			$config = $shopifyApi->getConfig();
			$defaultCategoryId = isset($config->dolibarr_procate) ? (int) $config->dolibarr_procate : 0;
			// Story 63-19 (AC1) : périmètre catégorie = arbre complet, helper partagé (cf. en-tête
			// de buildDolibarrProductWhereClause() ci-dessus)
			$productScopeHelper = new ProductScopeHelper($db);
			$scopeCategoryIds = $productScopeHelper->getCategoryTreeIds($defaultCategoryId, $conf->entity);
			// HIGH 3 / HIGH 5 (code review) : diagnostic partagé, cf. ProductScopeHelper::logEmptyScopeDiagnostics()
			$productScopeHelper->logEmptyScopeDiagnostics($defaultCategoryId, $conf->entity, $scopeCategoryIds);

			$sql = "SELECT COUNT(DISTINCT p.rowid) as total";
			$sql .= buildDolibarrProductFromClause();
			$sql .= buildDolibarrProductWhereClause($db, $conf->entity, $defaultCategoryId, $scopeCategoryIds, $searchRef);

			$resql = $db->query($sql);
			$total = 0;
			if ($resql) {
				$obj = $db->fetch_object($resql);
				$total = (int) $obj->total;
				$db->free($resql);
			}

			echo json_encode(array(
				'success' => true,
				'total' => $total,
				'direction' => 'dolibarr_to_shopify',
			), JSON_HEX_TAG | JSON_HEX_AMP);
		}

	} catch (Exception $e) {
		// LOW 10 (code review 63-19) : ne plus renvoyer $e->getMessage() brut au client — même
		// classe de défaut que 63-20 (JsonPayloadGuard) a corrigée côté website. Détail complet en
		// LOG_ERR (jamais perdu), message générique côté JSON (endpoint admin, mais peut exposer du
		// détail SQL/schéma sans nécessité).
		dol_syslog("sync_products_batch.php (get_count): " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
		http_response_code(500);
		echo json_encode(array(
			'success' => false,
			'message' => 'An internal error occurred while counting products. See server logs for details.',
		), JSON_HEX_TAG | JSON_HEX_AMP);
	}

	exit;
}

// ===========================================
// ACTION: sync_batch — Synchronize a batch of products (Task 2.3)
// ===========================================

if ($action == 'sync_batch') {
	// Extend time limit for batch processing
	@set_time_limit(0);

	// Task 3: Check if stock sync is enabled (AC4) — Story 53-5 (site 4b) : champ JSON informatif,
	// migré vers SyncFlowPolicy (finalise le retrait de l'ancienne classe de garde de ce fichier)
	$stockSyncEnabled = $syncFlowPolicy->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'dolibarr_to_shopify', $storeId)
		|| $syncFlowPolicy->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'shopify_to_dolibarr', $storeId);

	try {
		$response = array(
			'success' => true,
			'processed' => 0,
			'total' => 0,
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors' => 0,
			'hasMore' => false,
			'cursor' => null,
			'errorDetails' => array(),
			'stockSyncEnabled' => $stockSyncEnabled,
			'message' => '',
		);

		if ($direction == 'shopify_to_dolibarr') {
			// ---- Shopify -> Dolibarr : use ShopifyProductImporter ----
			dol_include_once('/doli2shop/class/shopifyproductimporter.class.php');

			$cursor = GETPOST('cursor', 'alphanohtml');
			if (empty($cursor)) {
				$cursor = null;
			}

			// Story 53-5 (site 4c) : fix bug scope pré-existant — l'importeur ne recevait pas
			// $scopedStore (symétrique du chemin d2s ci-dessous, review 49-9)
			$importer = new ShopifyProductImporter($db, $user, $conf->entity, $scopedStore);
			$result = $importer->importProducts(50, $cursor, false);

			$response['success'] = !empty($result['success']);
			$response['hasMore'] = !empty($result['hasNextPage']);
			$response['cursor'] = isset($result['endCursor']) ? $result['endCursor'] : null;
			$response['message'] = isset($result['message']) ? $result['message'] : '';

			if (isset($result['stats'])) {
				$response['created'] = (int) (isset($result['stats']['created']) ? $result['stats']['created'] : 0);
				$response['updated'] = (int) (isset($result['stats']['updated']) ? $result['stats']['updated'] : 0);
				$response['skipped'] = (int) (isset($result['stats']['skipped']) ? $result['stats']['skipped'] : 0);
				$response['errors'] = (int) (isset($result['stats']['errors']) ? $result['stats']['errors'] : 0);
				$response['processed'] = $response['created'] + $response['updated'] + $response['skipped'] + $response['errors'];
			}

			// Collect error details
			if (!empty($importer->errors)) {
				foreach ($importer->errors as $err) {
					$response['errorDetails'][] = $err;
				}
			}

		} else {
			// ---- Dolibarr -> Shopify : use importProducts::importProductsManual ----
			dol_include_once('/doli2shop/class/importproducts.class.php');
			dol_include_once('/doli2shop/class/shopifyapi.class.php');
			dol_include_once('/doli2shop/class/configurationMigrator.class.php');
			dol_include_once('/doli2shop/class/productscopehelper.class.php');

			$offset = GETPOSTINT('offset');

			// Journal de RUN, provenance MANUEL. La synchronisation manuelle est découpée en lots
			// AJAX : chaque lot est un processus distinct, donc l'identifiant de run circule dans la
			// requête et la réponse plutôt que de vivre en mémoire. Le premier lot ouvre le run, le
			// dernier le referme.
			dol_include_once('/doli2shop/class/runjournal.class.php');
			$incomingRunId = GETPOST('run_id', 'alphanohtml');
			if (!RunJournal::resume($incomingRunId, RunJournal::ORIGIN_MANUAL)) {
				RunJournal::start((int) $conf->entity, RunJournal::ORIGIN_MANUAL, 'Synchronisation produits depuis l\'ecran');
			}
			$response['runId'] = RunJournal::currentRunId();

			$shopifyApi = ($scopedStore !== null) ? ShopifyApi::forStore($db, $scopedStore) : new ShopifyApi($db);
			$config = $shopifyApi->getConfig();
			$defaultCategoryId = isset($config->dolibarr_procate) ? (int) $config->dolibarr_procate : 0;
			// Story 63-19 (AC1) : périmètre catégorie = arbre complet, helper partagé
			$productScopeHelper = new ProductScopeHelper($db);
			$scopeCategoryIds = $productScopeHelper->getCategoryTreeIds($defaultCategoryId, $conf->entity);
			// HIGH 3 / HIGH 5 (code review) : diagnostic partagé, cf. ProductScopeHelper::logEmptyScopeDiagnostics()
			$productScopeHelper->logEmptyScopeDiagnostics($defaultCategoryId, $conf->entity, $scopeCategoryIds);

			// Get batch of product IDs using shared WHERE clause (M3 fix)
			$sql = "SELECT DISTINCT p.rowid";
			$sql .= buildDolibarrProductFromClause();
			$sql .= buildDolibarrProductWhereClause($db, $conf->entity, $defaultCategoryId, $scopeCategoryIds, $searchRef);
			$sql .= " ORDER BY p.rowid";
			$sql .= " LIMIT 10 OFFSET " . (int) $offset;

			$productIds = array();
			$resql = $db->query($sql);
			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					$productIds[] = $obj->rowid;
				}
				$db->free($resql);
			}

			if (!empty($productIds)) {
				// Review 49-9 HIGH-1 : scoper aussi la direction dolibarr->shopify sur la boutique demandée
				$importProducts = new importProducts($db, (int) $conf->entity, $scopedStore);
				$result = $importProducts->importProductsManual($productIds);

				if ($result > 0) {
					$response['created'] = $result;
					$response['processed'] = $result;
				} elseif ($result == 0) {
					$response['skipped'] = count($productIds);
					$response['processed'] = count($productIds);
				} else {
					$response['errors'] = count($productIds);
					$response['processed'] = count($productIds);
					if (!empty($importProducts->error)) {
						$response['errorDetails'][] = $importProducts->error;
					}
				}

				// Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC3) : rendre visible,
				// sur CET écran (celui que Xavier Hubier a utilisé trois fois sans succès), les
				// renvois d'images forcés malgré un hash Dolibarr identique — le signal que la
				// destination Shopify avait divergé (produit/médias supprimés ou recréés).
				$response['imagesResyncForced'] = (int) $importProducts->imagesResyncForcedCount;

				// Le pendant du précédent : le produit n'est pas reparti sans photo parce que
				// Shopify a refusé, mais parce que le module n'a rien trouvé à envoyer. Les deux
				// donnent le même écran vide côté client et appellent des gestes opposés.
				$response['imagesNoSourceFound'] = (int) $importProducts->imagesNoSourceFoundCount;
				$response['imagesNoSourceFoundRefs'] = array_values($importProducts->imagesNoSourceFoundRefs);

				// Hotfix 2.5.7 (re-review 27/09/2026, MEDIUM) : même convention que les compteurs
				// d'images ci-dessus — ces deux compteurs existaient déjà (résumé LOG_ERR/LOG_WARNING
				// et sortie CRON, cf. ImportProductsCron::runSyncForStore()) mais restaient invisibles
				// sur CET écran de synchronisation manuelle. `stockSyncFailedCount` : articles non
				// synchronisés ce cycle (userErrors Shopify OU référence de stock non résolue).
				// `stockSyncLocationErrorCount` : sous-ensemble STRUCTUREL (emplacement Shopify
				// invalide/introuvable) — un problème de configuration, pas un simple aléa réseau.
				$response['stockSyncFailed'] = (int) $importProducts->stockSyncFailedCount;
				$response['stockSyncLocationErrors'] = (int) $importProducts->stockSyncLocationErrorCount;

				// Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3) : même
				// convention — articles ACTUELLEMENT plafonnés (référence de stock non résolue
				// depuis N cycles consécutifs), distinct de stockSyncLocationErrors (erreur GraphQL
				// GLOBALE d'emplacement, pas ce cas).
				$response['stockLocationCapped'] = (int) $importProducts->stockLocationCappedCount;
			}

			// Check if there are more products (L1 fix: alias renamed to 'total')
			$sqlCount = "SELECT COUNT(DISTINCT p.rowid) as total";
			$sqlCount .= buildDolibarrProductFromClause();
			$sqlCount .= buildDolibarrProductWhereClause($db, $conf->entity, $defaultCategoryId, $scopeCategoryIds, $searchRef);

			$resqlCount = $db->query($sqlCount);
			$totalProducts = 0;
			if ($resqlCount) {
				$objCount = $db->fetch_object($resqlCount);
				$totalProducts = (int) $objCount->total;
				$db->free($resqlCount);
			}

			$nextOffset = $offset + count($productIds);
			$response['hasMore'] = ($nextOffset < $totalProducts);

			// Dernier lot : le run se referme avec son bilan. Sans cette ligne de fin, un run
			// interrompu et un run terminé seraient indistinguables — la symétrie de la ligne de
			// démarrage.
			if (empty($response['hasMore'])) {
				RunJournal::finish(
					(int) $conf->entity,
					array(
						'produits' => (int) ($response['processed'] ?? 0),
						'erreurs' => (int) ($response['errors'] ?? 0),
						'images_renvoyees' => (int) ($response['imagesResyncForced'] ?? 0),
						'images_sans_source' => (int) ($response['imagesNoSourceFound'] ?? 0),
					),
					empty($response['errors']) ? ActionLogger::RESULT_SUCCESS : ActionLogger::RESULT_ERROR
				);
			}
			$response['cursor'] = (string) $nextOffset;
		}

		echo json_encode($response, JSON_HEX_TAG | JSON_HEX_AMP);

	} catch (Exception $e) {
		// LOW 10 (code review 63-19) : même correctif que ci-dessus (get_count) — détail complet
		// en LOG_ERR, message générique côté JSON.
		dol_syslog("sync_products_batch.php (sync_batch): " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
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
			'stockSyncEnabled' => false,
			'message' => 'An internal error occurred while synchronizing products. See server logs for details.',
		), JSON_HEX_TAG | JSON_HEX_AMP);
	}

	exit;
}

// Unknown action
http_response_code(400);
echo json_encode(array('success' => false, 'message' => 'Unknown action'), JSON_HEX_TAG | JSON_HEX_AMP);
exit;
