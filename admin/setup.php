<?php
/**
 * @file        admin/setup.php
 * @brief       Setup page for ShopifyIntegration module configuration
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       1.0.0
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
} 

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Libraries
dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/categories/class/categorie.class.php');
dol_include_once('/core/lib/date.lib.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/core/class/html.formfile.class.php');
dol_include_once('/product/class/html.formproduct.class.php');
dol_include_once('/core/lib/security2.lib.php');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once '../lib/doli2shop.lib.php';
require_once '../class/shopifyapi.class.php';
require_once '../class/configurationMigrator.class.php';
require_once '../class/healthchecker.class.php';
require_once '../class/storesettings.class.php'; // Story 49-4 — per-store settings

/**
 * Translate Shopify sales channel names to current language
 * @param string $channelName Original channel name from Shopify
 * @param Translate $langs Language object
 * @return string Translated channel name
 * @since 2.0.33
 */
function translateChannelName($channelName, $langs)
{
    // Common Shopify channel mappings to translation keys
    $channelMappings = [
        'Online Store' => 'ChannelOnlineStore',
        'Point of Sale' => 'ChannelPointOfSale',
        'Google & YouTube' => 'ChannelGoogleYouTube',
        'Inbox' => 'ChannelInbox',
        'Facebook & Instagram' => 'ChannelFacebookInstagram',
        'Shop App' => 'ChannelShopApp',
        'Buy Button' => 'ChannelBuyButton'
    ];

    // Return translation if mapping exists, otherwise return original name
    if (isset($channelMappings[$channelName])) {
        return $langs->trans($channelMappings[$channelName]);
    }

    return $channelName;
}

/**
 * Retourne un badge HTML « personnalisée » ou « héritée » pour un champ per-store.
 *
 * - Boutique par défaut → retourne '' (elle est la source globale, pas d'indicateur).
 * - Boutique secondaire + override existant (clé présente dans le cache getAllForStore) → badge « personnalisée ».
 * - Boutique secondaire + pas d'override (fallback global) → badge « héritée ».
 *
 * Pré-requis : $viewStoreSettings->getAllForStore($viewStoreId) doit avoir été appelé avant
 * (cache déjà peuplé — aucune requête SQL supplémentaire dans cette fonction).
 *
 * @param  StoreSettings $storeSettings Instance StoreSettings (cache déjà peuplé)
 * @param  int           $storeId       ID boutique active
 * @param  string        $key           Clé normalisée MAJUSCULES (ex. 'VENDOR', 'ORDER_PREFIX')
 * @param  bool          $isDefault     Boutique par défaut ?
 * @return string                       HTML du badge (vide si boutique par défaut)
 * @since  2.3.9
 */
function doli2shopPerStoreBadge(StoreSettings $storeSettings, int $storeId, string $key, bool $isDefault): string
{
    global $langs;
    if ($isDefault) {
        return '';
    }
    // hasOverride() : true si ligne DB non-null ('' inclus = override explicite)
    if ($storeSettings->hasOverride($storeId, $key)) {
        return ' <span class="badge badge-success" style="font-size:0.75em;padding:2px 6px;" title="' . dol_escape_htmltag($langs->trans('PerStoreOverrideTitle')) . '">'
            . dol_escape_htmltag($langs->trans('PerStoreOverrideTitle')) . '</span>';
    }
    return ' <span class="badge badge-secondary opacitymedium" style="font-size:0.75em;padding:2px 6px;" title="' . dol_escape_htmltag($langs->trans('GlobalInheritedTitle')) . '">'
        . dol_escape_htmltag($langs->trans('GlobalInheritedTitle')) . '</span>';
}

// FIX #152 v2.1.2: Access control MUST be checked BEFORE any business logic
// This prevents security issues and potential errors before permission verification
if (!$user->admin) {
    accessforbidden();
}

// Initialize hooks and translations
$hookmanager->initHooks(array('shopifysetup', 'globalsetup'));
$langs->loadLangs(array(
    "admin",
    "doli2shop@doli2shop",
    "orders",
    "deliveries",
    "bills",
    "companies"
));

// Get available sales channels for collections configuration
// FIX #152 v2.1.2: Moved AFTER access control + improved error handling
$availablePublications = [];
if (getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME') && getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN')) {
    try {
        $shopifyApi = new ShopifyApi($db);
        // Check if API initialization was successful
        if (!empty($shopifyApi->error)) {
            // Configuration invalid → continue without publications (non-blocking)
            dol_syslog("ShopifyApi initialization warning: " . $shopifyApi->error, LOG_WARNING);
        } else {
            $availablePublications = $shopifyApi->getPublications();
        }
    } catch (Exception $e) {
        // API error → continue without publications (non-blocking)
        dol_syslog("Could not fetch publications from Shopify: " . $e->getMessage(), LOG_WARNING);
        // Inform user with non-blocking warning
        setEventMessages($langs->trans("WarningCouldNotFetchSalesChannels"), null, 'warnings');
    }
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$current_tab = GETPOST('tab', 'alpha') ?: 'settings';

// Story 22.4: Redirect old shipping/payment tabs to orders tab (backward compat)
if ($current_tab == 'shipping') {
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=orders#section-shipping');
	exit;
}
if ($current_tab == 'payment') {
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=orders#section-payment');
	exit;
}

$error = 0;

// Story 48-4 : setup.php (config avancée) gère les mappings de la BOUTIQUE PAR DÉFAUT.
// Scoper par fk_store évite d'effacer les règles des boutiques secondaires (gérées par le wizard).
require_once dirname(__FILE__) . '/../class/storeservice.class.php';
$setupStoreService   = new StoreService($db);
$setupDefaultStore   = $setupStoreService->getDefault();
$setupDefaultStoreId = ($setupDefaultStore !== null) ? (int) $setupDefaultStore->rowid : 0;
$setupStoreFilter    = ($setupDefaultStoreId > 0) ? (" AND fk_store = ".$setupDefaultStoreId) : " AND fk_store = 0";

// Story 22.4: Dedicated save actions for shipping and payment (now in orders tab)
// Hotfix 2.4.6 (2/2) : ce garde et les 4 suivants réimplémentaient en dur la comparaison au
// mauvais jeton (`GETPOST('token') == newToken()`, où `newToken()` = jeton de la PROCHAINE
// requête, jamais celui réellement soumis) — cassés en silence dès que
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégués au helper partagé
// verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
// Review 3 couches (CRITICAL) : les blocs d'action ci-dessous sont gardés par `&& verifToken()`.
// Sans branche `else`, un jeton refusé (session expirée, onglet resté ouvert, rotation CSRF
// consommée par une requête concurrente) les faisait tous sauter EN SILENCE — la page se
// rechargeait à l'identique, sans succès ni erreur. C'est très exactement le symptôme que ce
// hotfix corrige par ailleurs : on le neutralise ici aussi, en signalant le refus une seule fois.
if (!empty($action) && !empty($_POST) && !verifToken()) {
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'save_shipping_mapping' && $user->admin && verifToken()) {
	$db->begin();
	$shiperr = 0;

	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."doli2shop_shipping_rules WHERE entity = ".(int)$conf->entity.$setupStoreFilter)) {
		$shiperr++;
	}

	// Title rules (à la commande)
	$titlePatterns = GETPOST('title_shopify_pattern', 'array');
	$titleMethods = GETPOST('title_dol_shipping_method_id', 'array');
	$titleDays = GETPOST('title_delivery_days', 'array');
	if (is_array($titlePatterns)) {
		foreach ($titlePatterns as $k => $pattern) {
			if (!empty($pattern) && !empty($titleMethods[$k])) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_shipping_rules SET
					entity = ".(int)$conf->entity.",
					fk_store = ".(int)$setupDefaultStoreId.",
					rule_type = 'title',
					shopify_pattern = '".$db->escape($pattern)."',
					dol_shipping_method_id = ".(int)$titleMethods[$k].",
					delivery_days = ".(int)$titleDays[$k].",
					active = 1";
				if (!$db->query($sql)) {
					$shiperr++;
				}
			}
		}
	}

	// Tracking rules (au fulfillment)
	$trackingPatterns = GETPOST('tracking_shopify_pattern', 'array');
	$trackingMethods = GETPOST('tracking_dol_shipping_method_id', 'array');
	$trackingDays = GETPOST('tracking_delivery_days', 'array');
	if (is_array($trackingPatterns)) {
		foreach ($trackingPatterns as $k => $pattern) {
			if (!empty($pattern) && !empty($trackingMethods[$k])) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_shipping_rules SET
					entity = ".(int)$conf->entity.",
					fk_store = ".(int)$setupDefaultStoreId.",
					rule_type = 'tracking',
					shopify_pattern = '".$db->escape($pattern)."',
					dol_shipping_method_id = ".(int)$trackingMethods[$k].",
					delivery_days = ".(int)$trackingDays[$k].",
					active = 1";
				if (!$db->query($sql)) {
					$shiperr++;
				}
			}
		}
	}

	if ($shiperr) {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans("ShippingMappingSaved"), null, 'mesgs');
	}
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=orders#section-shipping');
	exit;
}

if ($action == 'save_payment_mapping' && $user->admin && verifToken()) {
	$db->begin();
	$payerr = 0;

	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."doli2shop_payments WHERE entity = ".(int)$conf->entity.$setupStoreFilter)) {
		$payerr++;
	}

	$shopify_methods = GETPOST('shopify_payment_method', 'array');
	$dol_payment_ids = GETPOST('dolibarr_payment_id', 'array');
	if (is_array($shopify_methods)) {
		foreach ($shopify_methods as $k => $method) {
			if (!empty($method) && !empty($dol_payment_ids[$k])) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_payments SET
					shopify_payment_method = '".$db->escape($method)."',
					dolibarr_payment_id = ".(int)$dol_payment_ids[$k].",
					active = 1,
					fk_store = ".(int)$setupDefaultStoreId.",
					entity = ".(int)$conf->entity;
				if (!$db->query($sql)) {
					$payerr++;
				}
			}
		}
	}

	if ($payerr) {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans("PaymentMappingSaved"), null, 'mesgs');
	}
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=orders#section-payment');
	exit;
}

// Initialize API client
$shopifyApi = new ShopifyApi($db);

// Purge sync table action
if ($action == 'purge_sync_table' && $user->admin && verifToken()) {
    $db->begin();
    
    try {
        // Delete all records from the sync table for current entity
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products WHERE entity = " . (int)$conf->entity;
        $resql = $db->query($sql);
        
        if (!$resql) {
            throw new Exception($db->lasterror());
        }
        
        $deleted = $db->affected_rows($resql);

        // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC1) : la purge doit purger
        // VRAIMENT. llx_doli2shop_image_hashes n'était jamais touché par cette action alors que
        // c'est lui qui décide (à tort) de sauter l'envoi d'images — un client qui purge doit
        // repartir d'un état réellement vierge, hash inclus.
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_image_hashes WHERE entity = " . (int)$conf->entity;
        $resql = $db->query($sql);

        if (!$resql) {
            throw new Exception($db->lasterror());
        }

        // Reset all product timestamps to force resync
        $sql = "UPDATE " . MAIN_DB_PREFIX . "product SET tms = NOW() WHERE entity = " . (int)$conf->entity;
        $resql = $db->query($sql);

        if (!$resql) {
            throw new Exception($db->lasterror());
        }

        // Reset auto_increment only if we deleted all records from the table
        // Check if there are any remaining records from other entities
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $resql = $db->query($sql);
        
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj->count == 0) {
                // Table is completely empty, reset auto_increment
                $sql = "ALTER TABLE " . MAIN_DB_PREFIX . "doli2shop_products AUTO_INCREMENT = 1";
                $resql = $db->query($sql);
                if (!$resql) {
                    // Log error but don't fail the transaction
                    dol_syslog("Failed to reset auto_increment: " . $db->lasterror(), LOG_WARNING);
                } else {
                    dol_syslog("Reset auto_increment to 1 after complete table purge", LOG_INFO);
                }
            }
        }
        
        $db->commit();
        setEventMessages($langs->trans("SyncTablePurged", $deleted), null, 'mesgs');
        
        // Redirect to avoid resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $current_tab);
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        dol_syslog("setup.php: SQL error purging sync table: " . $e->getMessage(), LOG_ERR);
        setEventMessages($langs->trans("ErrorPurgingSyncTable"), null, 'errors');
    }
}

// Purge specific product action
if ($action == 'purge_specific_product' && $user->admin && verifToken()) {
    $product_id = GETPOST('product_id', 'int');
    
    if ($product_id) {
        $db->begin();
        
        try {
            // Verify product exists
            $sql = "SELECT p.ref FROM " . MAIN_DB_PREFIX . "product p WHERE p.rowid = " . (int)$product_id . " AND p.entity = " . (int)$conf->entity;
            $resql = $db->query($sql);
            
            if (!$resql || $db->num_rows($resql) == 0) {
                throw new Exception($langs->trans("ProductNotFound", $product_id));
            }
            
            $obj = $db->fetch_object($resql);
            $product_ref = $obj->ref;
            
            // Get all variant IDs if any
            // Check both direct children and through product_attribute_combination
            $sql = "SELECT DISTINCT pac.fk_product_child as rowid 
                    FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac 
                    WHERE pac.fk_product_parent = " . (int)$product_id;
            $sql .= " UNION ";
            $sql .= "SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE fk_parent = " . (int)$product_id . " AND entity = " . (int)$conf->entity;
            $resql = $db->query($sql);
            
            $product_ids = [$product_id];
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $product_ids[] = $obj->rowid;
                }
            }
            
            // Log the variant IDs found
            dol_syslog("Purging product " . $product_ref . " (ID: " . $product_id . ") with " . (count($product_ids) - 1) . " variants: " . implode(',', $product_ids), LOG_INFO);
            
            // Delete from sync table
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products WHERE fk_product IN (" . implode(',', $product_ids) . ") AND entity = " . (int)$conf->entity;
            $resql = $db->query($sql);
            
            if (!$resql) {
                throw new Exception($db->lasterror());
            }
            
            $deleted = $db->affected_rows($resql);

            // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC1) : même raison que
            // purge_sync_table ci-dessus — llx_doli2shop_image_hashes n'est stocké que sur la
            // ligne du produit PARENT (jamais sur une variante isolée), mais $product_ids inclut
            // déjà ce produit parent ; l'IN() ne matche donc simplement rien pour les IDs de
            // variante, sans effet de bord.
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_image_hashes WHERE fk_product IN (" . implode(',', $product_ids) . ") AND entity = " . (int)$conf->entity;
            $resql = $db->query($sql);

            if (!$resql) {
                throw new Exception($db->lasterror());
            }

            // Reset product timestamps to force resync
            $sql = "UPDATE " . MAIN_DB_PREFIX . "product SET tms = NOW() WHERE rowid IN (" . implode(',', $product_ids) . ") AND entity = " . (int)$conf->entity;
            $resql = $db->query($sql);

            if (!$resql) {
                throw new Exception($db->lasterror());
            }

            $db->commit();
            setEventMessages($langs->trans("ProductSyncPurged", $product_ref, $deleted), null, 'mesgs');
            
        } catch (Exception $e) {
            $db->rollback();
            dol_syslog("setup.php: SQL error purging product sync: " . $e->getMessage(), LOG_ERR);
            setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans("ProductRefRequired"), null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"] . "?tab=" . $current_tab);
    exit;
}

// Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC4) : porte de sortie explicite —
// forcer le renvoi complet des images d'UN produit, sans purge globale ni SQL manuel. C'est
// l'action qui aurait évité à Xavier Hubier (Europe Loisirs, 13/09/2026) trois tentatives
// infructueuses : purger/supprimer côté Shopify puis resynchroniser ne repart JAMAIS d'un état
// vierge côté hash tant que cette action (ou purge_specific_product ci-dessus) n'a pas tourné.
// Plus étroite que purge_specific_product : ne touche PAS llx_doli2shop_products (le mapping
// Shopify reste intact), seulement le hash d'images — puis déclenche un renvoi immédiat, pas
// une simple purge en attente du prochain cycle CRON.
if ($action == 'force_resync_product_images' && $user->admin && verifToken()) {
    $product_id = GETPOST('product_id', 'int');

    if ($product_id) {
        $product_ref = null;

        // Étape 1 : effacer le hash d'images stocké — transaction dédiée, séparée de l'appel
        // ImportProducts ci-dessous (qui gère ses propres begin()/commit()/rollback() internes).
        $db->begin();

        try {
            // Verify product exists
            $sql = "SELECT p.ref FROM " . MAIN_DB_PREFIX . "product p WHERE p.rowid = " . (int)$product_id . " AND p.entity = " . (int)$conf->entity;
            $resql = $db->query($sql);

            if (!$resql || $db->num_rows($resql) == 0) {
                throw new Exception($langs->trans("ProductNotFound", $product_id));
            }

            $obj = $db->fetch_object($resql);
            $product_ref = $obj->ref;

            // Le hash composite est stocké UNIQUEMENT sur la ligne du produit PARENT (jamais sur
            // une déclinaison isolée) — cf. ImportProducts::syncProductAllImages(). Effacer cette
            // ligne suffit à empêcher tout saut d'envoi au prochain cycle, immédiat ci-dessous.
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_image_hashes WHERE fk_product = " . (int)$product_id . " AND entity = " . (int)$conf->entity;
            $resql = $db->query($sql);

            if (!$resql) {
                throw new Exception($db->lasterror());
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            dol_syslog("setup.php: Error forcing image resync (hash reset): " . $e->getMessage(), LOG_ERR);
            setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
            $product_ref = null;
        }

        // Étape 2 : renvoi IMMÉDIAT (pas d'attente du prochain cycle CRON) — c'est ce que Xavier
        // a essayé de faire manuellement trois fois sans succès, faute de cette porte de sortie.
        if ($product_ref !== null) {
            try {
                dol_include_once('/doli2shop/class/importproducts.class.php');
                // Code review 3 couches (Blind Hunter, 2026-09-18) — HIGH : sans la boutique
                // COURANTE (multi-boutiques Epic 47), ImportProducts retombe sur la boutique par
                // défaut (chemin historique de ShopifyApi::loadConfiguration()) quelle que soit
                // la boutique réellement affichée dans cet écran — un admin qui force le renvoi
                // depuis l'onglet d'une boutique secondaire renverrait alors les images vers la
                // boutique par défaut, jamais vers celle qu'il regarde. Même pattern que
                // ajax/sync_products_batch.php ($scopedStore) / admin/sync_products.php
                // ($currentAdminStore) — cf. lib/doli2shop.lib.php::doli2shopGetCurrentAdminStore().
                $forceResyncStore = doli2shopGetCurrentAdminStore($db);
                $importProducts = new ImportProducts($db, (int) $conf->entity, $forceResyncStore);
                $numImagesResynced = $importProducts->importProductsManual([(int)$product_id]);

                // Code review 3 couches (Blind Hunter, 2026-09-18) — HIGH : importProductsManual()
                // renvoie 0 (rien synchronisé — ex. aucun mapping Shopify pour CETTE boutique) ou
                // -1 (exception interne déjà rattrapée) SANS lever d'exception PHP. Afficher
                // systématiquement "renvoi forcé" ici, y compris dans ces deux cas, aurait
                // reproduit — dans le bouton même censé y échapper — le défaut exact que cette
                // story corrige : un diagnostic qui ne signale aucune erreur pendant que rien ne
                // part (cf. Xavier Hubier, Europe Loisirs, 13/09/2026).
                if ($numImagesResynced > 0) {
                    setEventMessages($langs->trans("ProductImagesResyncForced", $product_ref), null, 'mesgs');
                } else {
                    $resyncErrorDetail = !empty($importProducts->error) ? $importProducts->error : '';
                    dol_syslog("setup.php: force_resync_product_images - Nothing synced for product "
                        . $product_ref . " (fk_product=" . (int)$product_id . ", numSynced=" . $numImagesResynced
                        . "): " . $resyncErrorDetail, LOG_ERR);
                    setEventMessages($langs->trans("ProductImagesResyncFailed", $product_ref), null, 'errors');
                }
            } catch (Exception $e) {
                dol_syslog("setup.php: Error forcing image resync (resync): " . $e->getMessage(), LOG_ERR);
                setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
            }
        }
    } else {
        setEventMessages($langs->trans("ProductRefRequired"), null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"] . "?tab=" . $current_tab);
    exit;
}


// Get shipping methods from Dolibarr dictionary
$shippingmethods = array();
$sql = "SELECT rowid, code, libelle FROM ".MAIN_DB_PREFIX."c_shipment_mode WHERE active = 1 ORDER BY libelle ASC";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $shippingmethods[$obj->rowid] = $obj->libelle;
    }
    $db->free($resql);
} else {
    dol_syslog("Doli2Shop setup.php: Error fetching shipping methods: ".$db->lasterror(), LOG_ERR);
}

// Get payment methods
$paymentmethods = array();
$sql = "SELECT id, code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE active = 1";
$resql = $db->query($sql);
while ($obj = $db->fetch_object($resql)) {
    $paymentmethods[$obj->id] = $obj->libelle . ' (' . $obj->code . ')';
}


/* * Actions */
if ($action == 'updateConfig') {
    // Story 34.5 : protection CSRF + admin (le formulaire poste déjà un token, cf. newToken())
    if (!$user->admin) {
        accessforbidden();
    }
    // Hotfix 2.4.6 (2/2) : réimplémentait en dur la comparaison au mauvais jeton (`newToken()` =
    // jeton de la PROCHAINE requête, jamais celui réellement soumis) — cassé en silence dès que
    // MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégué au helper partagé
    // verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
    if (!verifToken()) {
        setEventMessages($langs->trans("D2SInvalidCsrf"), null, 'errors');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . urlencode($current_tab));
        exit;
    }
    $db->begin();
    try {
        // Résolution boutique admin active (per-store P1 — Story 49-4)
        $perStoreCurrentAdminStore = doli2shopGetCurrentAdminStore($db);
        $perStoreStoreId = (int)($perStoreCurrentAdminStore->rowid ?? 0);
        $perStoreIsDefault = !empty($perStoreCurrentAdminStore->is_default);
        $perStoreSettings = new StoreSettings($db);

        // Vérifier les modules requis
        $required_modules = array(
            'product' => $langs->trans("Products"),
            'societe' => $langs->trans("ThirdParties"),
            'commande' => $langs->trans("Orders"),
            'facture' => $langs->trans("Invoices"),
            'stock' => $langs->trans("Stock"),
            'expedition' => $langs->trans("Shipments")
        );

        $missing_modules = array();
        foreach ($required_modules as $module_name => $module_label) {
            if (!isModEnabled($module_name)) {
                $missing_modules[] = $module_label;
            }
        }

        if (!empty($missing_modules)) {
            print '<div class="warning">';
            print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("MissingRequiredModules") . ': <br>';
            print '<ul>';
            foreach ($missing_modules as $module) {
                print '<li>' . $module . '</li>';
            }
            print '</ul>';
            print $langs->trans("PleaseActivateModulesFirst");
            print ' <a href="'.DOL_URL_ROOT.'/admin/modules.php">' . $langs->trans("GoToModulesPage") . '</a>';
            print '</div>';
        }

        // Configuration is now managed via Dolibarr constants (v2.0.27)

        // Validation des champs requis selon l'onglet actif
        if ($current_tab == 'settings') {
            // Review 49-9 HIGH-2 : pour une boutique SECONDAIRE, location_id peut être encore vide
            // (boutique en cours de configuration) — ne pas bloquer la sauvegarde de l'onglet.
            $required_fields = array(
                'shopify_store_hostname',
                'shopify_access_token',
                'shopify_api_key',
                'shopify_api_secret_key',
                'dolibarr_procate'
            );
            if ($perStoreIsDefault || $perStoreStoreId <= 0) {
                $required_fields[] = 'shopify_location_id';
            }
            foreach ($required_fields as $field) {
                if (empty(GETPOST($field))) {
                    throw new Exception($langs->trans("ErrorFieldRequired") . ': ' . $langs->trans($field));
                }
            }
        } elseif ($current_tab == 'orders') {
            $required_fields = array(
                'order_origin',
                'payment_terms',
                'default_shipping_method_id',
                'shipping_product_id',
                'order_bank_account',
                'default_warehouse_id'
            );
            // Story 35.2 : ces champs sont des IDs ; l'option vide vaut 0 OU -1 selon le
            // composant Dolibarr (selectWarehouses/selectShippingMethod émettent -1).
            // empty('-1') === false → tester <= 0 pour bloquer réellement les deux cas.
            foreach ($required_fields as $field) {
                if (GETPOSTINT($field) <= 0) {
                    throw new Exception($langs->trans("ErrorFieldRequired") . ': ' . $langs->trans($field));
                }
            }
        }


        // Préparer les valeurs à mettre à jour
        $values = array();
        if ($current_tab == 'settings') {
            global $dolibarr_main_url_root;
            $values = array(
                'shopify_store_hostname' => GETPOST('shopify_store_hostname', 'alphanohtml') ?: '',
                'shopify_access_token' => GETPOST('shopify_access_token', 'alphanohtml') ?: '',
                'shopify_api_key' => GETPOST('shopify_api_key', 'alphanohtml') ?: '',
                'shopify_api_secret_key' => GETPOST('shopify_api_secret_key', 'alphanohtml') ?: '',
                'shopify_location_id' => GETPOST('shopify_location_id', 'alphanohtml') ?: '',
                'dolibarr_procate' => GETPOST('dolibarr_procate', 'int') ?: '0',
                'dolibarr_customer_category' => GETPOST('dolibarr_customer_category', 'int') ?: '0',
                'typent_with_company' => (int) GETPOST('typent_with_company', 'int'),
                'typent_without_company' => (int) GETPOST('typent_without_company', 'int'),
                'max_orders_per_sync' => GETPOST('max_orders_per_sync', 'int') ?: 10,
                'products_per_cron_update' => GETPOST('products_per_cron_update', 'int') ?: 10,
            );

            // v2.2.1: Alertes proactives (Story 30.1) — sauvegardées via constantes Dolibarr
            dolibarr_set_const($db, 'DOLI2SHOP_ALERT_ENABLED', GETPOST('alert_enabled', 'int') ? '1' : '0', 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, 'DOLI2SHOP_ALERT_EMAIL', GETPOST('alert_email', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, 'DOLI2SHOP_ALERT_THRESHOLD', GETPOST('alert_threshold', 'int') ?: '3', 'chaine', 0, '', $conf->entity);
            // Reconstruire alert_types depuis les checkboxes
            $alertTypes = [];
            if (GETPOST('alert_type_webhook', 'int')) {
                $alertTypes[] = 'webhook';
            }
            if (GETPOST('alert_type_health', 'int')) {
                $alertTypes[] = 'health';
            }
            if (GETPOST('alert_type_sync', 'int')) {
                $alertTypes[] = 'sync';
            }
            dolibarr_set_const($db, 'DOLI2SHOP_ALERT_TYPES', implode(',', $alertTypes) ?: 'webhook,health', 'chaine', 0, '', $conf->entity);
            // Validation whitelist cooldown (patch code review 30.2)
            $cooldownInput = GETPOST('alert_cooldown', 'int');
            $allowedCooldowns = [15, 60, 240, 1440];
            $cooldownValue = in_array($cooldownInput, $allowedCooldowns, true) ? (string) $cooldownInput : '60';
            dolibarr_set_const($db, 'DOLI2SHOP_ALERT_COOLDOWN', $cooldownValue, 'chaine', 0, '', $conf->entity);

            // Story 49-9 : routage sauvegarde location_id par boutique
            // - secondaire → UNIQUEMENT colonne llx_doli2shop_stores.location_id (jamais la constante globale)
            // - défaut → constante globale (via $values, chemin actuel) + stores.location_id pour cohérence
            $locationIdVal = GETPOST('shopify_location_id', 'alphanohtml') ?: '';
            if ($perStoreStoreId > 0 && !$perStoreIsDefault) {
                // Story 58-3 (AC2) : ce retour n'était jusqu'ici jamais testé — une boutique
                // supprimée entre la résolution du contexte admin et ce point (ou hors entité)
                // passait pour un succès silencieux. -1 = erreur SQL, -2 = boutique inexistante/
                // hors entité (0 = no-op légitime, PAS une erreur, cf. StoreService::update()).
                if ($setupStoreService->update($perStoreStoreId, array('location_id' => $locationIdVal)) < 0) {
                    $error++;
                    setEventMessages($langs->trans("StoreUpdateError"), null, 'errors');
                }
                unset($values['shopify_location_id']); // ne pas écraser la constante globale
            } elseif ($perStoreStoreId > 0 && $perStoreIsDefault) {
                if ($setupStoreService->update($perStoreStoreId, array('location_id' => $locationIdVal)) < 0) {
                    $error++;
                    setEventMessages($langs->trans("StoreUpdateError"), null, 'errors');
                }
            }
        } elseif ($current_tab == 'orders') {
            $values = array(
                'order_prefix' => GETPOST('order_prefix', 'alphanohtml') ?: '',
                'delivery_delay' => GETPOST('delivery_delay', 'int') ?: '2',
                'delivery_delay_type' => GETPOST('delivery_delay_type', 'aZ09') ?: 'working',
                'order_origin' => GETPOST('order_origin', 'int') ?: '0',
                'payment_terms' => GETPOST('payment_terms', 'int') ?: '0',
                'default_delivery_days' => GETPOST('default_delivery_days', 'int') ?: '3',
                'default_shipping_method_id' => GETPOST('default_shipping_method_id', 'int') ?: '0',
                'shipping_product_id' => GETPOST('shipping_product_id', 'int') ?: 0,
                'tip_product_id' => GETPOST('tip_product_id', 'int') ?: 0,
                'order_bank_account' => GETPOST('order_bank_account', 'int') ?: 0,
                'default_warehouse_id' => GETPOST('default_warehouse_id', 'int') ?: 0,
                'auto_create_invoice' => empty(GETPOST('auto_create_invoice', 'int')) ? 0 : 1, // v2.2.0: Story 2.2
                'auto_validate_invoice' => empty(GETPOST('auto_validate_invoice', 'int')) ? 0 : 1, // v2.2.0: Story 12.1
                'auto_create_payment' => empty(GETPOST('auto_create_payment', 'int')) ? 0 : 1, // v2.2.0: Story 12.3
                'auto_create_expedition' => empty(GETPOST('auto_create_expedition', 'int')) ? 0 : 1, // v2.2.0: Story 2.3
                'auto_close_order' => empty(GETPOST('auto_close_order', 'int')) ? 0 : 1, // v2.2.2: option absente du setup standard (existait uniquement dans le wizard)
                'buying_price_source' => GETPOST('buying_price_source', 'aZ09') ?: 'pmp', // v2.2.0: Story 20.1
                'bundle_lines_behavior' => (int) GETPOST('bundle_lines_behavior', 'int'), // v2.2.2: Story 24.2
                'sync_non_paid_orders' => empty(GETPOST('sync_non_paid_orders', 'int')) ? 0 : 1, // Story 49-4
                'auto_classify_billed' => empty(GETPOST('auto_classify_billed', 'int')) ? 0 : 1, // Story 49-4
            );

            // Détecter si l'utilisateur vient d'activer l'import historique
            $newHistoricalEnabled = empty(GETPOST('historical_import_enabled', 'int')) ? 0 : 1;
            $currentHistoricalEnabled = getDolGlobalBool('DOLI2SHOP_HISTORICAL_IMPORT_ENABLED') ? 1 : 0;
            $isEnablingHistorical = ($newHistoricalEnabled == 1 && $currentHistoricalEnabled == 0);

            $newHistoricalStartDate = GETPOST('historical_import_start_date', 'alpha');

            // Story 63-18 (AC3, Task 1bis) : historical_import_completed(_date)/_total_count/
            // _processed_count/_skipped_count sont retirés de $values. Ce sont des champs
            // d'AFFICHAGE SEUL (aucun <input> de ces noms dans le formulaire, cf. balayage complet
            // des champs de l'onglet orders — sweep confirmé par le Dev, aucun autre champ de cette
            // classe dans setup.php) : les laisser dans $values les faisait passer par
            // ConfigurationMigrator::saveConfigurationValue(), qui FORCE l'écriture même pour '0'/vide
            // (cf. son commentaire) — un GETPOST() sur un champ absent du POST renvoie toujours '' et
            // écrasait donc ces compteurs à zéro à CHAQUE enregistrement de l'onglet orders, y compris
            // ceux écrits entre-temps par le CRON (Task 2). Leur seule remise à zéro légitime reste la
            // transition d'activation ci-dessous, écrite directement via dolibarr_set_const() —
            // exactement comme RESUME_DATE le fait déjà juste après.
            // Amendement review 3 couches du 24/08 (HIGH) : _failed_count rejoint ce même traitement
            // — même classe de défaut (champ d'affichage seul, aucun <input> correspondant), même
            // garde-fou (HistoricalImportCountersNotOverwrittenGuardTest).
            $values = array_merge($values, [
                'historical_import_enabled' => $newHistoricalEnabled,
                'historical_import_start_date' => $newHistoricalStartDate,
                'historical_import_end_date' => GETPOST('historical_import_end_date', 'alpha'),
            ]);

            // Si l'utilisateur vient d'activer l'import historique, reset des compteurs et flags
            if ($isEnablingHistorical) {
                // Story 63-18 (Task 1bis) : écriture DIRECTE, hors boucle générique $values — ces
                // constantes ne doivent être remises à zéro qu'ICI (transition désactivé → activé),
                // jamais à un simple ré-enregistrement de l'onglet orders.
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED", '0', 'yesno', 0, '', $conf->entity);
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED_DATE", '0', 'entier', 0, '', $conf->entity);
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_TOTAL_COUNT", '0', 'entier', 0, '', $conf->entity);
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_PROCESSED_COUNT", '0', 'entier', 0, '', $conf->entity);
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_COUNT", '0', 'entier', 0, '', $conf->entity);
                // Amendement review 24/08 (HIGH) : même traitement que les 3 compteurs ci-dessus —
                // champ d'affichage seul, jamais dans $values (cf. HistoricalImportCountersNotOverwrittenGuardTest).
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_FAILED_COUNT", '0', 'entier', 0, '', $conf->entity);
                // Amendement story reglage-commandes-non-payees-ignore-par-les-webhooks (HIGH n°3) :
                // même traitement — sous-ensemble diagnostic de SKIPPED_COUNT, champ d'affichage seul.
                dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_UNPAID_COUNT", '0', 'entier', 0, '', $conf->entity);

                // NOUVEAU v2.0.36: Copier START_DATE vers RESUME_DATE pour démarrer l'import
                if (!empty($newHistoricalStartDate)) {
                    dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE", $newHistoricalStartDate, 'chaine', 0, '', $conf->entity);
                    dol_syslog("Historical import activated - RESUME_DATE initialized with: " . $newHistoricalStartDate, LOG_INFO);
                } else {
                    // Effacer RESUME_DATE si pas de date de départ
                    dolibarr_del_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE", $conf->entity);
                    dol_syslog("Historical import activated - RESUME_DATE cleared (no start date)", LOG_INFO);
                }

                dol_syslog("Historical import activated - Reset counters and completion flags", LOG_INFO);

                // FIX #152 v2.1.2: Activer automatiquement le CRON historique
                // Charger la classe Cronjob pour gérer le CRON via API native
                require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';

                // Vérifier si le CRON existe avant de l'activer
                $sql = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "cronjob
                        WHERE module_name = 'doli2shop'
                        AND classesname = '/doli2shop/class/shopifyhistoricalimportcron.class.php'
                        AND entity = " . (int)$conf->entity;
                $resql = $db->query($sql);

                if ($resql && $db->num_rows($resql) > 0) {
                    // CRON existe - l'activer via API native Dolibarr (FIX #152 v2.1.2)
                    $obj = $db->fetch_object($resql);
                    $cronjob = new Cronjob($db);

                    if ($cronjob->fetch($obj->rowid) > 0) {
                        $result = $cronjob->setStatut(1);  // 1 = Cronjob::STATUS_ENABLED

                        if ($result > 0) {
                            // FIX #152 v2.1.2: Utiliser reprogram_jobs() pour calculer datenextrun automatiquement
                            // Reset processing et PID pour garantir démarrage propre
                            $cronjob->processing = 0;
                            $cronjob->pid = null;
                            $cronjob->datenextrun = null; // Force recalcul par reprogram_jobs()

                            // Reprogrammer avec méthode native Dolibarr
                            // ATTENTION: reprogram_jobs() attend STRING (login), pas objet User !
                            $resultReprogram = $cronjob->reprogram_jobs($user->login, dol_now());

                            if ($resultReprogram > 0) {
                                dol_syslog("Historical import CRON activated and reprogrammed (rowid=" . $obj->rowid . ") - Next run: " . dol_print_date($cronjob->datenextrun, 'dayhour'), LOG_INFO);
                            } else {
                                dol_syslog("WARNING: Failed to reprogram CRON, using manual fallback", LOG_WARNING);
                                // Fallback manuel si reprogram_jobs échoue
                                $nextRun = dol_now() + (10 * 60);
                                $cronjob->datenextrun = $nextRun;
                                $cronjob->update($user);
                            }
                        } else {
                            dol_syslog("ERROR: Failed to activate historical import CRON: " . $cronjob->error, LOG_ERR);
                            setEventMessages($langs->trans("Error") . ": " . $cronjob->error, null, 'errors');
                        }
                    } else {
                        dol_syslog("ERROR: Failed to fetch historical import CRON (rowid=" . $obj->rowid . ")", LOG_ERR);
                        setEventMessages($langs->trans("Error") . ": Cannot fetch CRON job", null, 'errors');
                    }
                } else {
                    // CRON n'existe pas - le créer
                    $cronjob = new Cronjob($db);
                    $cronjob->label = 'ShopifyIntegration-HistoricalImport';
                    $cronjob->jobtype = 'method';
                    $cronjob->module_name = 'doli2shop';
                    $cronjob->classesname = '/doli2shop/class/shopifyhistoricalimportcron.class.php';
                    $cronjob->objectname = 'ShopifyHistoricalImportCron';
                    $cronjob->methodename = 'executeCron';
                    $cronjob->params = '';
                    $cronjob->note = 'CRON for historical order import';
                    $cronjob->frequency = 30;
                    $cronjob->unitfrequency = 60; // minutes
                    $cronjob->status = 1; // Activé
                    $cronjob->entity = $conf->entity;
                    $cronjob->priority = 50;

                    // FIX v2.1.2: Programmer prochaine exécution = maintenant + 10 minutes
                    $cronjob->datenextrun = dol_now() + (10 * 60); // timestamp actuel + 10 minutes

                    $cronid = $cronjob->create($user);
                    if ($cronid > 0) {
                        dol_syslog("Historical import CRON created and activated (rowid=" . $cronid . ") - Next run: " . dol_print_date($cronjob->datenextrun, 'dayhour'), LOG_INFO);
                    } else {
                        dol_syslog("ERROR: Failed to create historical import CRON: " . $cronjob->error, LOG_ERR);
                    }
                }

                // FIX #152 v2.1.2: Activer aussi le CRON cleanup qui désactivera le CRON historique automatiquement
                $sql_cleanup = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "cronjob
                                WHERE module_name = 'doli2shop'
                                AND classesname = '/doli2shop/class/shopifyhistoricalimportcleanupcron.class.php'
                                AND entity = " . (int)$conf->entity;
                $resql_cleanup = $db->query($sql_cleanup);

                if ($resql_cleanup && $db->num_rows($resql_cleanup) > 0) {
                    $obj_cleanup = $db->fetch_object($resql_cleanup);
                    $cronjob_cleanup = new Cronjob($db);

                    if ($cronjob_cleanup->fetch($obj_cleanup->rowid) > 0) {
                        $result_cleanup = $cronjob_cleanup->setStatut(1);  // Activer le cleanup

                        if ($result_cleanup > 0) {
                            // FIX v2.1.2: Programmer prochaine exécution = maintenant + 15 minutes (après le historical)
                            $nextRunCleanup = dol_now() + (15 * 60); // timestamp actuel + 15 minutes
                            $sqlNextRunCleanup = "UPDATE " . MAIN_DB_PREFIX . "cronjob
                                                  SET datenextrun = " . $nextRunCleanup . ",
                                                      processing = 0,
                                                      pid = NULL
                                                  WHERE rowid = " . (int)$cronjob_cleanup->id;
                            $db->query($sqlNextRunCleanup);

                            dol_syslog("Cleanup CRON activated (rowid=" . $obj_cleanup->rowid . ") - Next run: " . dol_print_date($nextRunCleanup, 'dayhour'), LOG_INFO);
                        } else {
                            dol_syslog("WARNING: Failed to activate cleanup CRON: " . $cronjob_cleanup->error, LOG_WARNING);
                        }
                    }
                    $db->free($resql_cleanup);
                } else {
                    dol_syslog("WARNING: Cleanup CRON not found - will be created on next module activation", LOG_WARNING);
                    if ($resql_cleanup) $db->free($resql_cleanup);
                }

                setEventMessages($langs->trans("HistoricalImportActivatedAndReset"), null, 'mesgs');
            }

            // Story 63-18 (AC1, AC2, Task 1) : la copie START_DATE -> RESUME_DATE ci-dessus ne
            // s'exécutait QUE sur la transition désactivé -> activé ($isEnablingHistorical). Un
            // utilisateur dont l'import est DÉJÀ actif et qui corrige la date de début ne voyait
            // donc aucun effet — le CRON continuait sur l'ancien point de reprise. On applique ici
            // le même réalignement dès que START_DATE change, tant que l'import reste activé (le
            // calcul pur vit dans doli2shopComputeHistoricalResumeRealignment(), lib/doli2shop.lib.php,
            // pour rester testable sans dépendances Dolibarr — cf. test/unit/HistoricalImportResumeRealignmentTest.php).
            if ($newHistoricalEnabled == 1 && !$isEnablingHistorical) {
                $oldHistoricalStartDate = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_START_DATE', '');
                $currentResumeDate = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE', '');

                $realignment = doli2shopComputeHistoricalResumeRealignment(
                    $newHistoricalStartDate,
                    $oldHistoricalStartDate,
                    $currentResumeDate
                );

                if ($realignment['shouldRealign']) {
                    if ($realignment['clearResumeDate']) {
                        dolibarr_del_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE", $conf->entity);
                        dol_syslog("Historical import start date cleared while active - RESUME_DATE cleared", LOG_INFO);
                    } else {
                        // AC1 : un point de reprise plus ancien que la nouvelle date de début serait
                        // avancé par ce réalignement (des commandes intermédiaires seraient sautées) —
                        // à dire explicitement à l'écran, jamais en silence.
                        if ($realignment['warnAdvanced']) {
                            setEventMessages(
                                sprintf(
                                    $langs->trans("HistoricalImportResumeDateAdvancedWarning"),
                                    $currentResumeDate,
                                    $realignment['newResumeDate']
                                ),
                                null,
                                'warnings'
                            );
                        }
                        dolibarr_set_const($db, "DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE", $realignment['newResumeDate'], 'chaine', 0, '', $conf->entity);
                        dol_syslog("Historical import start date changed while active - RESUME_DATE realigned to: " . $realignment['newResumeDate'], LOG_INFO);
                    }
                }
            }

            // FIX v2.1.2: Gérer la DÉSACTIVATION de l'import historique
            $isDisablingHistorical = ($newHistoricalEnabled == 0 && $currentHistoricalEnabled == 1);
            if ($isDisablingHistorical) {
                dol_syslog("Historical import disabled - Deactivating CRONs", LOG_INFO);

                // Désactiver le CRON historique
                require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';

                $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cronjob
                        WHERE module_name = 'doli2shop'
                        AND classesname = '/doli2shop/class/shopifyhistoricalimportcron.class.php'
                        AND entity = " . (int)$conf->entity;
                $resql = $db->query($sql);

                if ($resql && $db->num_rows($resql) > 0) {
                    $obj = $db->fetch_object($resql);
                    $cronjob = new Cronjob($db);

                    if ($cronjob->fetch($obj->rowid) > 0) {
                        $result = $cronjob->setStatut(0);  // 0 = Cronjob::STATUS_DISABLED

                        if ($result > 0) {
                            // FIX v2.1.2: Force reset complet si CRON gelé en jaune
                            $sqlReset = "UPDATE " . MAIN_DB_PREFIX . "cronjob
                                         SET processing = 0, datelastresult = NULL, pid = NULL,
                                             lastoutput = 'CRON disabled and reset by user'
                                         WHERE rowid = " . (int)$cronjob->id;
                            $db->query($sqlReset);

                            dol_syslog("Historical import CRON deactivated and reset (rowid=" . $obj->rowid . ")", LOG_INFO);
                        } else {
                            dol_syslog("ERROR: Failed to deactivate historical import CRON: " . $cronjob->error, LOG_ERR);
                            setEventMessages($langs->trans("Error") . ": " . $cronjob->error, null, 'errors');
                        }
                    } else {
                        dol_syslog("ERROR: Failed to fetch historical import CRON (rowid=" . $obj->rowid . ")", LOG_ERR);
                    }
                } else {
                    dol_syslog("WARNING: Historical import CRON not found", LOG_WARNING);
                }

                // FIX #152 v2.1.2: Désactiver aussi le CRON cleanup
                $sql_cleanup = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cronjob
                                WHERE module_name = 'doli2shop'
                                AND classesname = '/doli2shop/class/shopifyhistoricalimportcleanupcron.class.php'
                                AND entity = " . (int)$conf->entity;
                $resql_cleanup = $db->query($sql_cleanup);

                if ($resql_cleanup && $db->num_rows($resql_cleanup) > 0) {
                    $obj_cleanup = $db->fetch_object($resql_cleanup);
                    $cronjob_cleanup = new Cronjob($db);

                    if ($cronjob_cleanup->fetch($obj_cleanup->rowid) > 0) {
                        $result_cleanup = $cronjob_cleanup->setStatut(0);

                        if ($result_cleanup > 0) {
                            // FIX v2.1.2: Force reset complet si CRON gelé en jaune
                            $sqlResetCleanup = "UPDATE " . MAIN_DB_PREFIX . "cronjob
                                                SET processing = 0, datelastresult = NULL, pid = NULL,
                                                    lastoutput = 'CRON disabled and reset by user'
                                                WHERE rowid = " . (int)$cronjob_cleanup->id;
                            $db->query($sqlResetCleanup);

                            dol_syslog("Cleanup CRON deactivated and reset (rowid=" . $obj_cleanup->rowid . ")", LOG_INFO);
                        }
                    }
                    $db->free($resql_cleanup);
                }

                setEventMessages($langs->trans("HistoricalImportDisabled"), null, 'mesgs');
            }
        } elseif ($current_tab == 'products') {
            // v2.1.6: Validation direction obligatoire
            $syncDirection = GETPOST('sync_products_direction', 'aZ09');
            if (empty($syncDirection)) {
                setEventMessages($langs->trans("ErrorSyncDirectionRequired"), null, 'errors');
                $error++;
            } else {
                $values = array(
                    'sync_products_direction' => $syncDirection,
                    'sync_products_conflict_resolution' => GETPOST('sync_products_conflict_resolution', 'aZ09') ?: 'dolibarr_wins',
                    'sync_product_prices' => empty(GETPOST('sync_product_prices', 'int')) ? 0 : 1,
                    'sync_price_level' => GETPOST('sync_price_level', 'int'),
                    'price_priority_ttc' => empty(GETPOST('price_priority_ttc', 'int')) ? 0 : 1,
                    'sync_product_descriptions' => empty(GETPOST('sync_product_descriptions', 'int')) ? 0 : 1,
                    'sync_product_images' => empty(GETPOST('sync_product_images', 'int')) ? 0 : 1,
                    'sync_product_stocks' => empty(GETPOST('sync_product_stocks', 'int')) ? 0 : 1,
                    'sync_product_attributes' => empty(GETPOST('sync_product_attributes', 'int')) ? 0 : 1,
                    'sync_products_only_active' => empty(GETPOST('sync_products_only_active', 'int')) ? 0 : 1,
                    'sync_product_collections' => empty(GETPOST('sync_product_collections', 'int')) ? 0 : 1,
                    'include_parent_categories' => empty(GETPOST('include_parent_categories', 'int')) ? 0 : 1,
                    'sync_collections_direction' => GETPOST('sync_collections_direction', 'aZ09') ?: 'dol_to_shop',
                    'sync_collections_conflict_resolution' => GETPOST('sync_collections_conflict_resolution', 'aZ09') ?: 'dolibarr_priority',
                    'collections_sales_channels' => GETPOST('collections_sales_channels', 'array') ? implode(',', GETPOST('collections_sales_channels', 'array')) : '',
                    'use_virtual_stock' => empty(GETPOST('use_virtual_stock', 'int')) ? 0 : 1,
                    'inventory_policy_continue_selling' => empty(GETPOST('inventory_policy_continue_selling', 'int')) ? 0 : 1,
                    'vendor' => GETPOST('shopify_vendor', 'alphanohtml')
                );

                // v2.1.6: Mise à jour des CRONs produits selon la direction choisie
                updateProductCronsStatus($conf->entity, $syncDirection);

                setEventMessages($langs->trans("ProductSyncOptionsSaved"), null, 'mesgs');
            }
        }

        // Mise à jour avec conservation des valeurs existantes (Story 49-4 : routage per-store)
        if (!empty($values)) {
            $migrator = new ConfigurationMigrator($db);
            $success = true;

            // Clés per-store par onglet — source unique via helper (Story 49-10)
            $perStoreKeysList = doli2shopGetPerStoreKeysByTab($current_tab);

            foreach ($values as $fieldName => $value) {
                if (in_array($fieldName, $perStoreKeysList, true)) {
                    if ($perStoreIsDefault || $perStoreStoreId <= 0) {
                        // Boutique par défaut ou aucune boutique → écriture dans les constantes globales
                        $result = $migrator->saveConfigurationValue($fieldName, $value, $conf->entity);
                        if (!$result) {
                            $success = false;
                            error_log("Failed to save per-store (default) field: $fieldName");
                        }
                    } else {
                        // Boutique secondaire → écriture dans StoreSettings (override per-store)
                        // Clé StoreSettings = fieldName normalisé en MAJUSCULES (cohérent avec VIEW + overlay)
                        // Ex. 'vendor' → 'VENDOR' (DOLI2SHOP_VENDOR), 'order_prefix' → 'ORDER_PREFIX', etc.
                        $perStoreSettingKey = strtoupper($fieldName);
                        $res = $perStoreSettings->set($perStoreStoreId, $perStoreSettingKey, $value);
                        if ($res < 0) {
                            $success = false;
                            error_log("Failed to save per-store override field: $fieldName");
                        }
                    }
                } else {
                    // Review 49-9 MEDIUM-3 : depuis une boutique SECONDAIRE, ne JAMAIS réécrire les
                    // credentials globaux (champs hidden postés avec les valeurs de la défaut ; un HTML
                    // modifié pourrait sinon altérer la config globale depuis l'onglet d'une secondaire).
                    $globalCredentialFields = array('shopify_store_hostname', 'shopify_access_token', 'shopify_api_key', 'shopify_api_secret_key');
                    if (!$perStoreIsDefault && $perStoreStoreId > 0 && in_array($fieldName, $globalCredentialFields, true)) {
                        continue;
                    }
                    // Champ global (credentials, API, historique) → constantes Dolibarr
                    $result = $migrator->saveConfigurationValue($fieldName, $value, $conf->entity);
                    if (!$result) {
                        $success = false;
                        error_log("Failed to save global field: $fieldName");
                    }
                }
            }

            if (!$success) {
                throw new Exception("Failed to save one or more configuration values via constants system");
            }
        }

        $db->commit();
        
        // Auto-activation des CRONs si la configuration est complète
        updateCronStatus($conf->entity);
        
        // Review 3 couches (MEDIUM) : ne pas annoncer « enregistré » quand une écriture a
        // échoué. Le compteur $error était incrémenté (et l'erreur affichée) mais jamais relu :
        // l'utilisateur voyait un message d'erreur ET un message de succès sur le même écran.
        if ($error > 0) {
            setEventMessages($langs->trans("SetupSavedWithErrors"), null, 'warnings');
        } else {
            setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
        }
    } catch (Exception $e) {
        $db->rollback();
        setEventMessages($e->getMessage(), null, 'errors');
        $error++;
    }
}

// Handler reset personnalisations d'un onglet (Story 49-10)
if ($action == 'reset_tab_overrides' && $user->admin && verifToken()) {
    $resetViewStore = doli2shopGetCurrentAdminStore($db);
    $resetStoreId = (int)($resetViewStore->rowid ?? 0);
    $resetIsDefault = !empty($resetViewStore->is_default);
    if (!$resetIsDefault && $resetStoreId > 0) {
        $tabToReset = GETPOST('tab', 'alpha') ?: 'settings';
        $keysToReset = doli2shopGetPerStoreKeysByTab($tabToReset);
        $resetSettings = new StoreSettings($db);
        foreach ($keysToReset as $rKey) {
            $resetSettings->delete($resetStoreId, strtoupper($rKey));
        }
        StoreSettings::clearCache($resetStoreId);
        setEventMessages($langs->trans('ResetTabOverridesDone'), null, 'mesgs');
    }
    header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=' . urlencode(GETPOST('tab', 'alpha') ?: 'settings') . '&store_id=' . (int)$resetStoreId);
    exit;
}

/* * View */
$form = new Form($db);
$formproduct = new FormProduct($db);
$formcompany = new FormCompany($db);

// Per-store reading for display (Tasks 5-6, Story 49-4)
$viewAdminStore = doli2shopGetCurrentAdminStore($db);
$viewStoreId = (int)($viewAdminStore->rowid ?? 0);
$viewStoreIsDefault = !empty($viewAdminStore->is_default); // MEDIUM-5 : badge personnalisée/héritée
$viewStoreSettings = new StoreSettings($db);
$viewStoreSettings->getAllForStore($viewStoreId); // précharge cache pour éviter N+1

$page_name = "Doli2ShopSetup";
llxHeader('', $langs->trans($page_name));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Load module CSS (fichier dynamique PHP — hotfix v2.2.3, le fichier static .css n'existe pas dans le pack)
print '<link rel="stylesheet" type="text/css" href="../css/doli2shop.css.php">';

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class="doli2shop-page">';

// Subheader
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Résoudre la boutique active pour la barre de contexte (Story 49-1)
$currentAdminStore = doli2shopGetCurrentAdminStore($db);

// Variables scopées boutique pour la bannière OAuth (Story 49-9)
if ($currentAdminStore !== null && !$currentAdminStore->is_default) {
    $oauthShopDomain = (string) $currentAdminStore->shop_domain;
    $oauthConnectedAt = (string) ($currentAdminStore->datec ?? '');
    $currentAdminStoreIsSecondary = true;
} else {
    $oauthShopDomain = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME');
    $oauthConnectedAt = getDolGlobalString('DOLI2SHOP_OAUTH_CONNECTED_AT', '');
    $currentAdminStoreIsSecondary = false;
}

// Configuration header
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, $current_tab, $langs->trans($page_name), -1, 'doli2shop@doli2shop');

// Barre de contexte boutique — affichée en haut de la section par-boutique (Story 49-10)
doli2shopRenderAdminTopBar($db);

// Message d'installation retiré en v2.1.7 - L'app est maintenant sur le Shopify App Store
// La connexion se fait directement via le bouton "Connecter à Shopify"

// Configuration now managed via Dolibarr constants (v2.0.27)
// All configuration is retrieved directly using getDolGlobalString/Int/Bool functions

// Check if Shopify API is configured (v2.0.27)
$shopify_configured = !empty(getDolGlobalString('DOLI2SHOP_API_KEY')) && !empty(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN'));

// ========================================
// v2.2.0 Story 4.2: Health Dashboard Widget
// Replaces v2.1.8 webhook detection banner
// ========================================
if ($shopify_configured && !$currentAdminStoreIsSecondary) {
    $healthChecker = new HealthChecker($db);
    $healthData = $healthChecker->getHealthStatus();

    if (!empty($healthChecker->errors)) {
        // AC #5: Graceful degradation if HealthChecker fails
        print '<div class="warning" style="margin-bottom: 15px; padding: 10px; border-left: 4px solid #ff9800;">';
        print '<i class="fas fa-exclamation-triangle"></i> ';
        print '<strong>' . dol_escape_htmltag($langs->trans("HealthLoadError")) . '</strong>';
        print '</div>';
    } else {
        $healthStatus = $healthData['status'];
        $healthLastSync = doli2shopFormatRelativeTime($healthData['last_sync_timestamp']);
        $isFreshInstall = ($healthData['webhooks_total'] == 0 && $healthData['orders_30d'] == 0 && $healthData['products_synced'] == 0);

        // Status label and link
        if ($isFreshInstall) {
            $healthLabel = $langs->trans("HealthReadyToSync");
            $healthLink = dol_buildpath('/doli2shop/admin/setup_guide.php', 1);
        } elseif ($healthStatus == 'green') {
            $healthLabel = $langs->trans("HealthStatusGreen");
            $healthLink = '';
        } elseif ($healthStatus == 'orange') {
            $healthLabel = $langs->trans("HealthStatusOrange");
            $healthLink = dol_buildpath('/doli2shop/admin/webhook_events.php', 1) . '?filter_result=error';
        } else {
            $healthLabel = $langs->trans("HealthStatusRed");
            $healthLink = dol_buildpath('/doli2shop/admin/webhook_events.php', 1) . '?filter_result=error';
        }

        // --- Health Indicator ---
        print '<div class="d2s-health-widget">';

        if (!empty($healthLink)) {
            print '<a href="' . dol_escape_htmltag($healthLink) . '" class="d2s-health-indicator d2s-health-indicator--' . dol_escape_htmltag($healthStatus) . '">';
        } else {
            print '<div class="d2s-health-indicator d2s-health-indicator--' . dol_escape_htmltag($healthStatus) . '">';
        }
        print '<span class="d2s-health-pill"></span>';
        print '<span class="d2s-health-text">' . dol_escape_htmltag($healthLabel) . '</span>';
        if (!$isFreshInstall) {
            print '<span class="d2s-health-sync">' . dol_escape_htmltag($healthLastSync) . '</span>';
        } else {
            print '<span class="d2s-health-sync">';
            print '<i class="fas fa-arrow-right"></i> ' . dol_escape_htmltag($langs->trans("HealthConfigWizardLink"));
            print '</span>';
        }
        if (!empty($healthLink)) {
            print '</a>';
        } else {
            print '</div>';
        }

        // Story 37.2 : accès rapide au wizard de vérification si la santé est dégradée
        if (!$isFreshInstall && ($healthStatus == 'orange' || $healthStatus == 'red')) {
            print '<div style="margin: 8px 0 4px;">';
            print '<a href="' . dol_buildpath('/doli2shop/admin/setup_verification.php', 1) . '" class="butAction" style="margin:0;">';
            print '🩺 ' . dol_escape_htmltag($langs->trans("VerificationDashboardCheckNow"));
            print '</a>';
            print '</div>';
        }

        // --- 4 KPI Cards (AC #2) ---
        print '<div class="d2s-kpi-row">';

        // Card 1: Orders 30d
        print '<div class="d2s-kpi-card">';
        print '<i class="fas fa-shopping-cart d2s-kpi-icon"></i>';
        print '<span class="d2s-kpi-value">' . (int) $healthData['orders_30d'] . '</span>';
        print '<span class="d2s-kpi-label">' . $langs->trans("HealthKpiOrders") . '</span>';
        print '</div>';

        // Card 2: Products synced
        print '<div class="d2s-kpi-card">';
        print '<i class="fas fa-cubes d2s-kpi-icon"></i>';
        print '<span class="d2s-kpi-value">' . (int) $healthData['products_synced'] . '</span>';
        print '<span class="d2s-kpi-label">' . $langs->trans("HealthKpiProducts") . '</span>';
        print '</div>';

        // Card 3: Errors 24h (red highlight if > 0)
        $errorCardClass = 'd2s-kpi-card';
        if ($healthData['errors_24h'] > 0) {
            $errorCardClass .= ' d2s-kpi-card--error';
        }
        print '<div class="' . $errorCardClass . '">';
        print '<i class="fas fa-exclamation-triangle d2s-kpi-icon"></i>';
        print '<span class="d2s-kpi-value">' . (int) $healthData['errors_24h'] . '</span>';
        print '<span class="d2s-kpi-label">' . $langs->trans("HealthKpiErrors") . '</span>';
        print '</div>';

        // Card 4: Webhooks active/total
        print '<div class="d2s-kpi-card">';
        print '<i class="fas fa-plug d2s-kpi-icon"></i>';
        print '<span class="d2s-kpi-value">' . (int) $healthData['webhooks_active'] . '/' . (int) $healthData['webhooks_total'] . '</span>';
        print '<span class="d2s-kpi-label">' . $langs->trans("HealthKpiWebhooks") . '</span>';
        print '</div>';

        print '</div>'; // d2s-kpi-row
        print '</div>'; // d2s-health-widget
    }
}
if ($shopify_configured && $currentAdminStoreIsSecondary) {
    // Story 49-9 : HealthChecker non disponible pour les boutiques secondaires (pas d'appel API au chargement)
    $healthTabUrl = dol_buildpath('/doli2shop/admin/health.php', 1);
    print '<div class="d2s-health-widget d2s-health-widget--secondary" style="margin-bottom: 16px; padding: 12px 16px; border: 1px solid #d0d0d0; border-radius: 6px; background: #f8f8f8;">';
    print '<span class="opacitymedium"><i class="fas fa-info-circle"></i> ' . dol_escape_htmltag($langs->trans("HealthNotAvailablePerStore")) . '</span>';
    print ' &nbsp; <a href="' . dol_escape_htmltag($healthTabUrl) . '">' . dol_escape_htmltag($langs->trans("HealthTab")) . '</a>';
    print '</div>';
}

// Bouton reset personnalisations de l'onglet (Story 49-10) — rendu HORS du form principal
// (review 49-10 MEDIUM : forms imbriqués = HTML invalide). Un seul bloc pour les 3 onglets.
$resetTabs = array('settings', 'orders', 'products');
if (in_array($current_tab, $resetTabs, true) && !$viewStoreIsDefault && $viewStoreId > 0
    && ($shopify_configured || $current_tab == 'settings')) {
    print '<div style="margin-bottom:8px;text-align:right;">';
    print '<form method="post" action="' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') . '" style="display:inline;">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="reset_tab_overrides">';
    print '<input type="hidden" name="tab" value="' . dol_escape_htmltag($current_tab) . '">';
    print '<input type="hidden" name="store_id" value="' . (int)$viewStoreId . '">';
    print '<button type="submit" class="butActionDelete" style="font-size:0.85em;padding:3px 8px;" onclick="return confirm(\'' . dol_escape_js($langs->trans('ResetTabOverridesConfirm')) . '\')">';
    print dol_escape_htmltag($langs->trans('ResetTabOverrides'));
    print '</button>';
    print '</form>';
    print '</div>';
}

// Setup form
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="updateConfig">';
print '<input type="hidden" name="tab" value="'.$current_tab.'">';

if ($current_tab == 'settings' || !$shopify_configured) {

    print '<div class="opacitymedium">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("CompleteSettingsFirstToAccessOtherTabs");
    print '</div>';

    // ===========================================
    // OAuth Connection Banner (v2.1.7)
    // ===========================================
    $oauth_connected_at = $oauthConnectedAt;
    $is_oauth_connected = !empty($oauthShopDomain) && (!empty($oauth_connected_at) || $currentAdminStoreIsSecondary);

    print '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; padding: 20px; margin: 15px 0; color: white;">';
    print '<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">';

    print '<div>';
    print '<h3 style="margin: 0 0 5px 0; color: white;"><i class="fab fa-shopify"></i> ' . $langs->trans("ShopifyOAuthConnection") . '</h3>';

    if ($is_oauth_connected) {
        $connected_shop = $oauthShopDomain;
        print '<p style="margin: 0; opacity: 0.9;">';
        print '<i class="fas fa-check-circle"></i> ' . $langs->trans("ConnectedTo") . ': <strong>' . dol_escape_htmltag($connected_shop) . '</strong>';
        if (!empty($oauth_connected_at) && strtotime($oauth_connected_at) !== false) {
            print ' <span style="opacity: 0.7;">(' . $langs->trans("ConnectedOn") . ' ' . dol_print_date(strtotime($oauth_connected_at), 'dayhour') . ')</span>';
        }
        print '</p>';
    } else {
        print '<p style="margin: 0; opacity: 0.9;">' . $langs->trans("OAuthNotConnected") . '</p>';
    }

    print '</div>';

    print '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';
    $btn_text = $is_oauth_connected ? $langs->trans("ReconnectViaOAuth") : $langs->trans("ConnectViaOAuth");
    $reconnectUrl = dol_buildpath('/doli2shop/admin/connect_shopify.php', 1);
    if ($currentAdminStoreIsSecondary && $currentAdminStore !== null) {
        $reconnectUrl .= '?store_context=reconnect&store_id=' . (int) $currentAdminStore->rowid;
    }
    print '<a href="' . dol_escape_htmltag($reconnectUrl) . '" class="button" style="background: white; color: #667eea; border: none; padding: 10px 20px; font-weight: bold;">';
    print '<i class="fab fa-shopify"></i> ' . $btn_text;
    print '</a>';
    // Disconnect button (only if connected)
    if ($is_oauth_connected) {
        // Story deconnexion-oauth-ignore-le-multi-boutiques (AC1, Validate réserve n°5) : store_id
        // toujours porté explicitement, boutique par défaut comprise — comme Reconnecter (:1271-1272)
        // et reset_tab_overrides (:1222) — pour que disconnect_oauth.php n'ait jamais à deviner la
        // boutique via $_SESSION['doli2shop_admin_store'].
        $disconnectUrl = dol_buildpath('/doli2shop/admin/disconnect_oauth.php', 1);
        if ($currentAdminStore !== null) {
            $disconnectUrl .= '?store_id=' . (int) $currentAdminStore->rowid;
        }
        print '<a href="' . dol_escape_htmltag($disconnectUrl) . '" class="button" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.5); padding: 10px 20px;">';
        print '<i class="fas fa-unlink"></i> ' . $langs->trans("DisconnectShopify");
        print '</a>';
    }
    print '</div>';

    print '</div>';
    print '</div>';

    // OAuth success message
    if (GETPOST('oauth_success', 'int')) {
        print '<div class="info" style="margin-bottom: 15px;">';
        print '<i class="fas fa-check-circle" style="color: #27ae60;"></i> ';
        print $langs->trans("OAuthConnectionSuccessful");
        print '</div>';
    }
    // ===========================================
    // End OAuth Banner
    // ===========================================

    print '<table class="noborder centpercent">';

    // Shopify configuration section
    print '<tr class="liste_titre">';
    print '<th colspan="2">' . $langs->trans("ShopifyConfiguration") . '</th>';
    print '</tr>';

    // OAuth connected: show connection status and hide manual fields
    if ($is_oauth_connected) {
        // Connection status banner
        print '<tr class="oddeven">';
        print '<td colspan="2" style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px;">';
        print '<i class="fas fa-check-circle" style="color: #28a745;"></i> ';
        print '<strong>' . $langs->trans("OAuthConnectionActive") . '</strong><br>';
        print '<span class="opacitymedium">' . $langs->trans("OAuthFieldsAutoFilled") . '</span>';
        print '</td>';
        print '</tr>';

        // Show connected store (read-only display, not input) — scopé boutique (Story 49-9)
        $store_hostname = $oauthShopDomain;
        if (!empty($store_hostname)) {
            print '<tr class="oddeven">';
            print '<td class="titlefieldcreate">' . $langs->trans("ConnectedStore") . '</td>';
            print '<td><strong>' . htmlspecialchars($store_hostname, ENT_QUOTES, 'UTF-8') . '</strong></td>';
            print '</tr>';
        }

        // Hidden inputs to preserve values on form submission
        print '<input type="hidden" name="shopify_store_hostname" value="' . htmlspecialchars(getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME'), ENT_QUOTES, 'UTF-8') . '">';
        print '<input type="hidden" name="shopify_access_token" value="' . htmlspecialchars(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN'), ENT_QUOTES, 'UTF-8') . '">';
        print '<input type="hidden" name="shopify_api_key" value="' . htmlspecialchars(getDolGlobalString('DOLI2SHOP_API_KEY'), ENT_QUOTES, 'UTF-8') . '">';
        print '<input type="hidden" name="shopify_api_secret_key" value="' . htmlspecialchars(getDolGlobalString('DOLI2SHOP_API_SECRET_KEY'), ENT_QUOTES, 'UTF-8') . '">';
    } else {
        // Manual configuration: show all fields (only when NOT using OAuth)
        print '<tr class="oddeven">';
        print '<td colspan="2" class="opacitymedium" style="font-style: italic; background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px;">';
        print '<i class="fas fa-exclamation-triangle" style="color: #856404;"></i> ' . $langs->trans("ManualConfigurationMode");
        print '</td>';
        print '</tr>';

        // Story 35.3 : avertissement Partner Custom App (cause ACCESS_DENIED plans Basic/Starter)
        print '<tr class="oddeven">';
        print '<td colspan="2" style="background: #fff3e0; border-left: 4px solid #ff9800; padding: 10px;">';
        print '<i class="fas fa-exclamation-triangle" style="color: #e65100;"></i> <strong>' . $langs->trans("PartnerAppWarning") . '</strong><br>';
        print '<span style="font-size: 13px;">' . $langs->trans("PartnerAppWarningDesc") . ' ';
        print '<a href="https://doli2shop.ptitetete.org/documentation" target="_blank" rel="noopener noreferrer">' . $langs->trans("PartnerAppLearnMore") . '</a></span>';
        print '</td>';
        print '</tr>';

        // Shopify Store Hostname
        print '<tr class="pair">';
        print '<td class="titlefieldcreate required">' . $langs->trans("ShopifyStoreHostname") . '</td>';
        print '<td><input type="text" name="shopify_store_hostname" value="' . getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME') . '" size="40"> <span class="help-icon" title="' . $langs->trans("ShopifyStoreHostnameHelp") . '">?</span></td>';
        print '</tr>';

        // Access token
        print '<tr class="impair">';
        print '<td class="titlefieldcreate required">' . $langs->trans("ShopifyAccessToken") . '</td>';
        print '<td><input type="text" name="shopify_access_token" value="' . getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN') . '" size="40"> <span class="help-icon" title="' . $langs->trans("ShopifyAccessTokenHelp") . '">?</span></td>';
        print '</tr>';

        // API Key
        print '<tr class="pair">';
        print '<td class="titlefieldcreate required">' . $langs->trans("ShopifyApiKey") . '</td>';
        print '<td><input type="text" name="shopify_api_key" value="' . getDolGlobalString('DOLI2SHOP_API_KEY') . '" size="40"> <span class="help-icon" title="' . $langs->trans("ShopifyApiKeyHelp") . '">?</span></td>';
        print '</tr>';

        // API Secret Key
        print '<tr class="impair">';
        print '<td class="titlefieldcreate required">' . $langs->trans("ShopifyApiSecretKey") . '</td>';
        print '<td><input type="text" name="shopify_api_secret_key" value="' . getDolGlobalString('DOLI2SHOP_API_SECRET_KEY') . '" size="40"> <span class="help-icon" title="' . $langs->trans("ShopifyApiSecretKeyHelp") . '">?</span></td>';
        print '</tr>';
    }
    
    // Location ID
    print '<tr class="pair">';
    print '<td class="titlefieldcreate required">'.$langs->trans("ShopifyLocationId").'</td>';
    $locationIdDisplay = $currentAdminStoreIsSecondary && $currentAdminStore !== null
        ? (string) ($currentAdminStore->location_id ?? '')
        : getDolGlobalString('DOLI2SHOP_LOCATION_ID');
    print '<td><input type="text" name="shopify_location_id" value="'.dol_escape_htmltag($locationIdDisplay).'" size="40"> <span class="help-icon" title="'.$langs->trans("ShopifyLocationIdHelp").'">?</span></td>';
    print '</tr>';

    // Story 57-5 : section « Configuration Dolibarr » (clé API self + URL hôte self, lecture seule)
    // retirée — ni l'une ni l'autre ne sert plus à rien dans le module depuis 57-7 (accès direct
    // base+disque pour les images). Les constantes elles-mêmes ne sont ni supprimées ni migrées.

    // Dolibarr Product Category
    print '<tr class="impair">';
    print '<td class="titlefieldcreate required">'.$langs->trans("DolibarrProductCategory") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'DOLIBARR_PROCATE', $viewStoreIsDefault) . '</td>';
    print '<td>';
    $categories = $form->select_all_categories(Categorie::TYPE_PRODUCT, '', 'parent', 64, 0, 1);

    // Ajouter une option vide au début si aucune catégorie n'est sélectionnée
    $current_procate_value = $viewStoreSettings->get($viewStoreId, 'DOLIBARR_PROCATE', '');
    if (empty($current_procate_value) || $current_procate_value === '0') {
        $categories = array('' => $langs->trans("SelectProductCategory")) + $categories;
    }

    print $form->selectarray('dolibarr_procate', $categories, $current_procate_value);
    print ' <span class="help-icon" title="'.$langs->trans("DolibarrProductCategoryHelp").'">?</span></td>';
    print '</tr>';

    // Dolibarr Customer Category
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("DolibarrCustomerCategory") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'DOLIBARR_CUSTOMER_CATEGORY', $viewStoreIsDefault) . '</td>';
    print '<td>';
    $customer_categories = $form->select_all_categories(Categorie::TYPE_CUSTOMER, '', 'parent', 64, 0, 1);

    // Ajouter une option vide au début car cette catégorie est optionnelle
    $current_customer_category_value = $viewStoreSettings->get($viewStoreId, 'DOLIBARR_CUSTOMER_CATEGORY', '');
    $customer_categories = array('' => $langs->trans("None")) + $customer_categories;

    print $form->selectarray('dolibarr_customer_category', $customer_categories, $current_customer_category_value);
    print ' <span class="help-icon" title="'.$langs->trans("DolibarrCustomerCategoryHelp").'">?</span></td>';
    print '</tr>';

    // v2.2.0: Mapping type de tiers B2B/B2C (Story 13.1)
    print '<tr class="liste_titre">';
    print '<th colspan="2">'.$langs->trans("ThirdPartyTypeMapping").'</th>';
    print '</tr>';

    // Type de tiers quand company est renseigné (B2B)
    // Note: c_typent IDs start at 1 in Dolibarr, so key '0' is safe for "Do not set"
    $typentArray = $formcompany->typent_array(0);
    $typentOptions = array('0' => $langs->trans("DoNotSet")) + $typentArray;

    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("TypentWithCompany") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'TYPENT_WITH_COMPANY', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print $form->selectarray('typent_with_company', $typentOptions, $viewStoreSettings->getInt($viewStoreId, 'TYPENT_WITH_COMPANY', 0));
    print ' <span class="help-icon" title="'.$langs->trans("TypentWithCompanyHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // Type de tiers quand company est vide (B2C)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("TypentWithoutCompany") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'TYPENT_WITHOUT_COMPANY', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print $form->selectarray('typent_without_company', $typentOptions, $viewStoreSettings->getInt($viewStoreId, 'TYPENT_WITHOUT_COMPANY', 0));
    print ' <span class="help-icon" title="'.$langs->trans("TypentWithoutCompanyHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // Dans l'onglet Settings
    print '<tr class="liste_titre">';
    print '<th colspan="2" class="titlefieldcreate">'.$langs->trans("SyncSettings").'</th>';
    print '</tr>';

    // Nombre maximum de commandes par synchronisation
    print '<tr>';
    print '<td class="titlefieldcreate">'.$langs->trans("MaxOrdersPerSync") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'MAX_ORDERS_PER_SYNC', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<input type="number" name="max_orders_per_sync" value="'.($viewStoreSettings->getInt($viewStoreId, 'MAX_ORDERS_PER_SYNC', 10)).'" min="1" max="100" class="flat width75">';
    print ' <span class="help-icon" title="'.$langs->trans("MaxOrdersPerSyncHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // Nombre de produits mis à jour par cycle cron
    print '<tr>';
    print '<td class="titlefieldcreate">'.$langs->trans("ProductsPerCronUpdate") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'PRODUCTS_PER_CRON_UPDATE', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<input type="number" name="products_per_cron_update" value="'.($viewStoreSettings->getInt($viewStoreId, 'PRODUCTS_PER_CRON_UPDATE', 10)).'" min="1" max="1000" class="flat width75">';
    print ' <span class="help-icon" title="'.$langs->trans("ProductsPerCronUpdateHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // Option déplacée vers l'onglet "Synchronisation des produits"
    // print '<tr>';
    // print '<td class="titlefieldcreate">'.$langs->trans("UseVirtualStock").'</td>';
    // print '<td>';
    // print ' <span class="help-icon" title="'.$langs->trans("UseVirtualStockHelp").'">?</span>';
    // print '</td>';
    // print '</tr>';


    print '</table>';

    // ===========================================
    // Section Alertes proactives (Story 30.1)
    // ===========================================
    print '<br>';
    print load_fiche_titre($langs->trans('AlertsTitle'), '', '');
    print '<table class="noborder centpercent">';

    // Toggle activer/désactiver alertes
    print '<tr class="liste_titre">';
    print '<th colspan="2">' . $langs->trans("AlertsDesc") . '</th>';
    print '</tr>';

    // Activation globale
    print '<tr class="oddeven">';
    print '<td class="titlefieldcreate">' . $langs->trans("AlertEnabled") . '</td>';
    print '<td>';
    print '<input type="checkbox" name="alert_enabled" value="1"' . (getDolGlobalInt('DOLI2SHOP_ALERT_ENABLED') ? ' checked' : '') . '>';
    print ' <span class="help-icon" title="' . $langs->trans("AlertEnabledHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Email destinataire
    print '<tr class="oddeven">';
    print '<td class="titlefieldcreate">' . $langs->trans("AlertEmail") . '</td>';
    print '<td>';
    print '<input type="email" name="alert_email" value="' . dol_escape_htmltag(getDolGlobalString('DOLI2SHOP_ALERT_EMAIL')) . '" class="flat minwidth300" placeholder="admin@example.com">';
    print ' <span class="help-icon" title="' . $langs->trans("AlertEmailHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Seuil d'erreurs
    print '<tr class="oddeven">';
    print '<td class="titlefieldcreate">' . $langs->trans("AlertThreshold") . '</td>';
    print '<td>';
    print '<input type="number" name="alert_threshold" value="' . getDolGlobalInt('DOLI2SHOP_ALERT_THRESHOLD', 3) . '" min="1" max="50" class="flat width75">';
    print ' <span class="help-icon" title="' . $langs->trans("AlertThresholdHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Types d'alertes activées
    $alertTypes = getDolGlobalString('DOLI2SHOP_ALERT_TYPES', 'webhook,health');
    $alertTypesArray = array_map('trim', explode(',', $alertTypes));

    print '<tr class="oddeven">';
    print '<td class="titlefieldcreate">' . $langs->trans("AlertTypes") . '</td>';
    print '<td>';
    print '<label><input type="checkbox" name="alert_type_webhook" value="1"' . (in_array('webhook', $alertTypesArray) ? ' checked' : '') . '> ' . $langs->trans("AlertTypeWebhook") . '</label><br>';
    print '<label><input type="checkbox" name="alert_type_health" value="1"' . (in_array('health', $alertTypesArray) ? ' checked' : '') . '> ' . $langs->trans("AlertTypeHealth") . '</label><br>';
    print '<label><input type="checkbox" name="alert_type_sync" value="1"' . (in_array('sync', $alertTypesArray) ? ' checked' : '') . '> ' . $langs->trans("AlertTypeSync") . '</label>';
    print '</td>';
    print '</tr>';

    // Cooldown (throttling) — Story 30.2
    $currentCooldown = getDolGlobalInt('DOLI2SHOP_ALERT_COOLDOWN', 60);
    print '<tr class="oddeven">';
    print '<td class="titlefieldcreate">' . $langs->trans("AlertCooldown") . '</td>';
    print '<td>';
    print '<select name="alert_cooldown" class="flat">';
    $cooldownOptions = [15, 60, 240, 1440];
    foreach ($cooldownOptions as $val) {
        print '<option value="' . $val . '"' . ($val == $currentCooldown ? ' selected' : '') . '>' . $langs->trans("AlertCooldown" . $val) . '</option>';
    }
    print '</select>';
    print ' <span class="help-icon" title="' . $langs->trans("AlertCooldownHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    print '</table>';
    // ===========================================
    // End Section Alertes
    // ===========================================

    // v2.1.6-fix: Bouton Enregistrer pour l'onglet settings
    print '<br><center>';
    print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
    print '</center>';

} elseif ($current_tab == 'orders' && $shopify_configured) {

    // Story 35.2 : légende champs obligatoires
    print '<p class="opacitymedium"><span class="fieldrequired">*</span> '.$langs->trans("RequiredFields").'</p>';

    print '<table class="noborder centpercent">';

    // Order configuration section
    print '<tr class="liste_titre">';
    print '<th colspan="2">'.$langs->trans("OrderConfiguration").'</th>';
    print '</tr>';
    
    // Order prefix
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("OrderPrefix").'</td>';
    print '<td><input type="text" name="order_prefix" value="'.htmlspecialchars($viewStoreSettings->get($viewStoreId, 'ORDER_PREFIX', ''), ENT_QUOTES, 'UTF-8').'" size="10"> <span class="help-icon" title="'.$langs->trans("OrderPrefixHelp").'">?</span></td>';
    print '</tr>';
    
    // Delivery delay
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("DeliveryDelay").'</td>';
    print '<td>';
    print '<input type="number" name="delivery_delay" value="'.($viewStoreSettings->getInt($viewStoreId, 'DELIVERY_DELAY', 7)).'" size="5"> <span class="help-icon" title="'.$langs->trans("DeliveryDelayHelp").'">?</span>';
    print ' '.$form->selectarray('delivery_delay_type', array(
        'working' => $langs->trans("WorkingDays"),
        'calendar' => $langs->trans("CalendarDays")
    ), $viewStoreSettings->get($viewStoreId, 'DELIVERY_DELAY_TYPE', 'working'));
    print '  <span class="help-icon" title="'.$langs->trans("DeliveryDelayTypeHelp").'">?</span></td>';
    print '</tr>';
    
    // Order origin
    print '<tr class="pair">';
    print '<td class="titlefieldcreate fieldrequired">'.$langs->trans("OrderOrigin").'</td>';
    print '<td>';
    print $form->selectInputReason($viewStoreSettings->getInt($viewStoreId, 'ORDER_ORIGIN', 0), 'order_origin', -1, -1, 1);
    print ' <span class="help-icon" title="'.$langs->trans("OrderOriginHelp").'">?</span></td>';
    print '</tr>';
    
    // Payment terms
    print '<tr class="impair">';
    print '<td class="titlefieldcreate fieldrequired">'.$langs->trans("PaymentTerms").'</td>';
    print '<td>';
    print $form->getSelectConditionsPaiements($viewStoreSettings->getInt($viewStoreId, 'PAYMENT_TERMS', 0), 'payment_terms', -1, -1, 1);
    print ' <span class="help-icon" title="'.$langs->trans("PaymentTermsHelp").'">?</span></td>';
    print '</tr>';

    // Default shipping method
    print '<tr class="pair">';
    print '<td class="titlefieldcreate fieldrequired">'.$langs->trans("DefaultShippingMethod").'</td>';
    print '<td>';
    print $form->selectShippingMethod($viewStoreSettings->getInt($viewStoreId, 'DEFAULT_SHIPPING_METHOD_ID', 0),'default_shipping_method_id','',2,1);
    print ' <span class="help-icon" title="'.$langs->trans("DefaultShippingMethodHelp").'">?</span></td>';
    print '</tr>';

    // v2.2.0 Story 20.1: Source du prix d'achat (PMP ou prix de revient)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("BuyingPriceSource").'</td>';
    print '<td>';
    print $form->selectarray('buying_price_source', array(
        'pmp' => $langs->trans("BuyingPriceSourcePMP"),
        'cost_price' => $langs->trans("BuyingPriceSourceCostPrice")
    ), $viewStoreSettings->get($viewStoreId, 'BUYING_PRICE_SOURCE', 'pmp'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
    print ' <span class="help-icon" title="'.$langs->trans("BuyingPriceSourceHelp").'">?</span></td>';
    print '</tr>';

    // Sync non-paid orders (for Ankorstore and similar marketplaces)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("SyncNonPaidOrders") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'SYNC_NON_PAID_ORDERS', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<input type="checkbox" name="sync_non_paid_orders" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_NON_PAID_ORDERS', 0) ? 'checked' : '') . '>';
    print ' <span class="help-icon" title="'.$langs->trans("SyncNonPaidOrdersHelp").'">?</span></td>';
    print '</tr>';

    // Auto-classement facturé (Story 49-4 code review HIGH-2 + MEDIUM-5)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("AutoClassifyBilled") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'AUTO_CLASSIFY_BILLED', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<input type="checkbox" name="auto_classify_billed" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'AUTO_CLASSIFY_BILLED', 1) ? 'checked' : '') . '>';
    print ' <span class="help-icon" title="'.$langs->trans("AutoClassifyBilledHelp").'">?</span></td>';
    print '</tr>';

    // Shipping Service Product
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("ShippingProduct").'</td>';
    print '<td>';
    $form->select_produits($viewStoreSettings->getInt($viewStoreId, 'SHIPPING_PRODUCT_ID', 0), 'shipping_product_id', 1, 0, 0, 1, 2, '', 0, array(), 0, '1', 0, 'maxwidth300');
    print ' <span class="help-icon" title="'.$langs->trans("ShippingProductIdHelp").'">?</span></td>';
    print '</tr>';

    // Tip Service Product
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("TipProduct").'</td>';
    print '<td>';
    $form->select_produits($viewStoreSettings->getInt($viewStoreId, 'TIP_PRODUCT_ID', 0), 'tip_product_id', 1, 0, 0, 1, 2, '', 0, array(), 0, '1', 0, 'maxwidth300');
    print ' <span class="help-icon" title="'.$langs->trans("TipProductIdHelp").'">?</span></td>';
    print '</tr>';

    // v2.2.2 Story 24.2: Comportement face aux lignes bundles/cadeaux (apps Shopify tierces)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("BundleLinesBehavior").'</td>';
    print '<td>';
    print $form->selectarray('bundle_lines_behavior', array(
        0 => $langs->trans("BundleLinesBehaviorIgnore"),
        1 => $langs->trans("BundleLinesBehaviorComment"),
        2 => $langs->trans("BundleLinesBehaviorImport")
    ), $viewStoreSettings->getInt($viewStoreId, 'BUNDLE_LINES_BEHAVIOR', 0), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
    print ' <span class="help-icon" title="'.$langs->trans("BundleLinesBehaviorHelp").'">?</span></td>';
    print '</tr>';

    // Default Warehouse
    print '<tr class="pair">';
    print '<td class="titlefieldcreate fieldrequired">'.$langs->trans("DefaultWarehouse").'</td>';
    print '<td>';
    print $formproduct->selectWarehouses($viewStoreSettings->getInt($viewStoreId, 'DEFAULT_WAREHOUSE_ID', 0), 'default_warehouse_id', '', 1);
    print ' <span class="help-icon" title="'.$langs->trans("DefaultWarehouseIdHelp").'">?</span></td>';
    print '</tr>';

    // Default delivery days
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("DefaultDeliveryDays").'</td>';
    print '<td><input type="number" name="default_delivery_days" value="'.($viewStoreSettings->getInt($viewStoreId, 'DEFAULT_DELIVERY_DAYS', 3)).'" min="1" max="30"> <span class="help-icon" title="'.$langs->trans("DefaultDeliveryDaysHelp").'">?</span></td>';
    print '</tr>';

    // Order Bank Account
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("OrderBankAccount").'</td>';
    print '<td>';
    $form->select_comptes($viewStoreSettings->getInt($viewStoreId, 'ORDER_BANK_ACCOUNT', 0), 'order_bank_account', 0, '', 1);
    print ' <span class="help-icon" title="'.$langs->trans("OrderBankAccountHelp").'">?</span></td>';
    print '</tr>';
    
    // v2.2.0: Auto-création facture brouillon (Story 2.2)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate"><strong>'.$langs->trans("AutoCreateInvoice").'</strong>' . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'AUTO_CREATE_INVOICE', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="auto_create_invoice" value="1" '.($viewStoreSettings->getInt($viewStoreId, 'AUTO_CREATE_INVOICE', 1) ? 'checked' : '').'>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="'.$langs->trans("AutoCreateInvoiceHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // v2.2.0: Auto-validation facture (Story 12.1)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate"><strong>'.$langs->trans("AutoValidateInvoice").'</strong>' . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'AUTO_VALIDATE_INVOICE', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="auto_validate_invoice" value="1" '.($viewStoreSettings->getInt($viewStoreId, 'AUTO_VALIDATE_INVOICE', 1) ? 'checked' : '').'>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="'.$langs->trans("AutoValidateInvoiceHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // v2.2.0: Auto-création paiement (Story 12.3)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate"><strong>'.$langs->trans("AutoCreatePayment").'</strong>' . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'AUTO_CREATE_PAYMENT', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="auto_create_payment" value="1" '.($viewStoreSettings->getInt($viewStoreId, 'AUTO_CREATE_PAYMENT', 1) ? 'checked' : '').'>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="'.$langs->trans("AutoCreatePaymentHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // v2.2.0: Auto-création bon d'expédition (Story 2.3)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate"><strong>'.$langs->trans("AutoCreateExpedition").'</strong>' . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'AUTO_CREATE_EXPEDITION', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="auto_create_expedition" value="1" '.($viewStoreSettings->getInt($viewStoreId, 'AUTO_CREATE_EXPEDITION', 0) ? 'checked' : '').'>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="'.$langs->trans("AutoCreateExpeditionHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    // v2.2.2: Auto-clôture commande quand toutes les lignes sont expédiées
    // (option présente dans le wizard mais oubliée dans setup.php standard)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate"><strong>'.$langs->trans("AutoCloseOrder").'</strong>' . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'AUTO_CLOSE_ORDER', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="auto_close_order" value="1" '.($viewStoreSettings->getInt($viewStoreId, 'AUTO_CLOSE_ORDER', 1) ? 'checked' : '').'>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="'.$langs->trans("AutoCloseOrderHelp").'">?</span>';
    print '</td>';
    print '</tr>';

    print '</table>';

    // Story 35.2 : validation des selects obligatoires à la soumission.
    // Les select* de Dolibarr sont enveloppés par select2 (select natif masqué) →
    // l\'attribut HTML5 "required" ne déclenche jamais. On valide donc sur "submit"
    // en vérifiant la valeur (l\'option vide vaut 0 ou -1 selon le composant).
    print '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var fields = ["order_origin", "payment_terms", "default_shipping_method_id", "default_warehouse_id"];
        var el0 = document.querySelector(\'[name="order_origin"]\');
        var form = el0 ? el0.closest("form") : null;
        if (!form) { return; }
        form.addEventListener("submit", function(e) {
            for (var i = 0; i < fields.length; i++) {
                var el = document.querySelector(\'[name="\' + fields[i] + \'"]\');
                if (el && (parseInt(el.value, 10) || 0) <= 0) {
                    e.preventDefault();
                    alert(' . json_encode($langs->transnoentitiesnoconv("RequiredFieldsMissing")) . ');
                    if (window.jQuery && jQuery(el).data("select2")) { jQuery(el).select2("open"); } else { el.focus(); }
                    return false;
                }
            }
        });
    });
    </script>';

    // v2.2.0: Section CRON OrdersImport entièrement supprimée (toggle, statut, table)

    // Historical Orders Import section
    print '<br>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th colspan="2">'.$langs->trans("HistoricalOrdersImport").'</th>';
    print '</tr>';
    
    // Enable historical import
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("EnableHistoricalImport").'</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="historical_import_enabled" value="1" '.(getDolGlobalBool('DOLI2SHOP_HISTORICAL_IMPORT_ENABLED') ? 'checked' : '').'>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="'.$langs->trans("EnableHistoricalImportHelp").'">?</span></td>';
    print '</tr>';
    
    // Historical import start date
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("HistoricalImportStartDate").'</td>';
    print '<td>';

    // Récupération des deux dates séparément
    $originalStartDate = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_START_DATE');
    $resumeDate = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE');

    // Formatage pour l'affichage dans le champ date (YYYY-MM-DD seulement)
    $displayStartDate = '';
    if (!empty($originalStartDate)) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $originalStartDate, $matches)) {
            $displayStartDate = $matches[1];
        } else {
            $displayStartDate = $originalStartDate;
        }
    }

    print '<input type="date" name="historical_import_start_date" value="'.$displayStartDate.'">';

    // Affichage informatif du point de reprise si différent de la date originale
    if (!empty($resumeDate) && $resumeDate != $originalStartDate) {
        print '<br><small class="opacitymedium">';
        print '<strong>'.$langs->trans("HistoricalImportResumePoint").'</strong> ' . $resumeDate;
        print '<br><em>'.$langs->trans("HistoricalImportWillContinue").'</em>';
        print '</small>';
    } elseif (!empty($originalStartDate)) {
        print '<br><small class="opacitymedium">';
        print '<em>'.$langs->trans("HistoricalImportWillStart").'</em>';
        print '</small>';
    }

    print ' <span class="help-icon" title="'.$langs->trans("HistoricalImportStartDateHelp").'">?</span></td>';
    print '</tr>';
    
    // Historical import end date
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("HistoricalImportEndDate").'</td>';
    print '<td>';
    print '<input type="date" name="historical_import_end_date" value="'.getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_END_DATE').'">';
    print ' <span class="help-icon" title="'.$langs->trans("HistoricalImportEndDateHelp").'">?</span></td>';
    print '</tr>';
    
    // Historical import completed status (read-only)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">'.$langs->trans("HistoricalImportCompleted").'</td>';
    print '<td>';
    if (getDolGlobalBool('DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED')) {
        print '<span class="badge badge-status4">'.$langs->trans("Yes").'</span>';

        // Date de fin d'import (timestamp)
        $completedDate = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED_DATE');
        if ($completedDate > 0) {
            print ' <em>('.dol_print_date($completedDate, 'dayhour').')</em>';
        }
    } else {
        print '<span class="badge badge-status1">'.$langs->trans("No").'</span>';
    }
    print ' <span class="help-icon" title="'.$langs->trans("HistoricalImportCompletedHelp").'">?</span></td>';
    print '</tr>';

    // Amendement review 3 couches du 24/08 (CRITICAL — "relier les deux signaux") : le flag
    // "reconnexion Shopify requise" (posé par ShopifyApi::markReconnectRequired() sur un 401
    // persistant) ne s'affichait jusqu'ici que sur admin/stores.php, diagnostic.php et health.php —
    // JAMAIS sur cet écran, alors que c'est précisément l'information manquante quand l'import
    // historique reste bloqué (jeton mort) sans qu'aucune erreur ne soit visible ici. Ce CRON
    // n'opère que sur la boutique par défaut (cf. commentaire hotfix dans
    // shopifyhistoricalimportcron.class.php) : lire la constante globale suffit, sans logique
    // multi-boutique.
    if (getDolGlobalInt('DOLI2SHOP_TOKEN_RECONNECT_REQUIRED', 0) == 1) {
        $reconnectShopLabel = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME') ?: $langs->trans("DefaultStore");
        print '<tr class="impair">';
        print '<td class="titlefieldcreate"></td>';
        // Style aligné sur admin/stores.php:622 (même clé, même triangle d'alerte).
        print '<td><span style="color: #dc3545; font-weight: bold;">&#9888; '.$langs->trans("OAuthTokenReconnectRequired", $reconnectShopLabel).'</span></td>';
        print '</tr>';
    }

    // Statistiques d'import historique
    $totalCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_TOTAL_COUNT', 0);
    $processedCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_PROCESSED_COUNT', 0);
    $skippedCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_COUNT', 0);
    // Amendement review 24/08 (HIGH) : FAILED_COUNT n'entrait auparavant dans AUCUN affichage — un
    // import qui échoue à 100% sur une plage donnée n'avait aucune trace à l'écran.
    $failedCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_FAILED_COUNT', 0);
    // Amendement story reglage-commandes-non-payees-ignore-par-les-webhooks (HIGH n°3) : SOUS-ENSEMBLE
    // diagnostique de $skippedCount (jamais additionné à part — handledTotal/pourcentage inchangés),
    // isolant les commandes refusées par le gate financier (non payée, réglage désactivé) d'un
    // doublon ordinaire — sans quoi les deux étaient indiscernables à l'écran.
    $skippedUnpaidCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_UNPAID_COUNT', 0);

    print '<tr class="pair">';
    print '<td class="titlefieldcreate">'.$langs->trans("HistoricalImportStatistics").'</td>';
    print '<td>';

    if ($totalCount > 0 || $processedCount > 0 || $skippedCount > 0 || $failedCount > 0) {
        $percentage = $totalCount > 0 ? round(($processedCount / $totalCount) * 100, 1) : 0;
        $handledTotal = $processedCount + $skippedCount;

        // Affichage avec détail importé/skippé
        print '<div style="margin-bottom: 5px;">';
        print '<strong>'.$processedCount.' '.$langs->trans("HistoricalImportedWord").'</strong>';
        if ($skippedCount > 0) {
            print ' + <span style="color: #856404;">'.$skippedCount.' '.$langs->trans("HistoricalSkippedWord").'</span>';
            if ($skippedUnpaidCount > 0) {
                // Sous-ensemble de $skippedCount (pas additionné) : combien de ces "ignorées" sont
                // en réalité un refus explicite du gate financier, pas un doublon.
                print ' <span style="color: #856404; font-size: 0.85em;">('.$skippedUnpaidCount.' '.$langs->trans("HistoricalUnpaidWord").')</span>';
            }
        }
        if ($failedCount > 0) {
            print ' + <span style="color: #dc3545;">'.$failedCount.' '.$langs->trans("HistoricalFailedWord").'</span>';
        }
        // Story 63-18 (AC4) : TOTAL_COUNT n'est jamais estimé (Shopify ne fournit pas de total
        // fiable sans appel supplémentaire, cf. fetchHistoricalOrdersBatch()) — tant qu'il reste à
        // 0, afficher un décompte SANS total ni pourcentage plutôt qu'un "/ 0 (0%)" trompeur.
        if ($totalCount > 0) {
            print ' / <strong>'.$totalCount.' '.$langs->trans("HistoricalTotalWord").'</strong>';
            if ($percentage > 0) {
                print ' <span class="badge badge-info">('.$percentage.'%)</span>';
            }
        }
        print ' <span class="help-icon" title="'.$langs->trans("HistoricalImportStatisticsHelp").'">?</span>';
        print '</div>';

        // Détail explicatif
        if ($skippedCount > 0) {
            print '<div style="font-size: 0.9em; color: #6c757d; margin-bottom: 5px;">';
            print '<em>('.sprintf($langs->trans("HistoricalOrdersProcessedDetail"), $handledTotal, $processedCount, $skippedCount).')</em>';
            print '</div>';
        }

        // Barre de progression visuelle 3 couleurs
        if ($totalCount > 0) {
            // Calcul des pourcentages pour chaque section
            $processedPercentage = round(($processedCount / $totalCount) * 100, 1);
            $skippedPercentage = round(($skippedCount / $totalCount) * 100, 1);
            $remainingPercentage = round((($totalCount - $processedCount - $skippedCount) / $totalCount) * 100, 1);

            print '<div style="width: 300px; height: 20px; background-color: #f0f0f0; border: 1px solid #ccc; border-radius: 4px; overflow: hidden; display: flex; margin-top: 8px;">';

            // Section verte : Commandes importées
            if ($processedPercentage > 0) {
                print '<div style="width: '.$processedPercentage.'%; height: 100%; background-color: #28a745;" title="'.sprintf($langs->trans("HistoricalOrdersImportedTitle"), $processedCount, $processedPercentage).'"></div>';
            }

            // Section orange : Commandes ignorées (déjà présentes)
            if ($skippedPercentage > 0) {
                print '<div style="width: '.$skippedPercentage.'%; height: 100%; background-color: #fd7e14;" title="'.sprintf($langs->trans("HistoricalOrdersSkippedTitle"), $skippedCount, $skippedPercentage).'"></div>';
            }

            // Section grise : Commandes non traitées
            if ($remainingPercentage > 0) {
                print '<div style="width: '.$remainingPercentage.'%; height: 100%; background-color: #6c757d;" title="'.sprintf($langs->trans("HistoricalOrdersRemainingTitle"), ($totalCount - $processedCount - $skippedCount), $remainingPercentage).'"></div>';
            }

            print '</div>';

            // Légende de la progress bar
            print '<div style="margin-top: 8px; font-size: 0.85em; color: #6c757d;">';
            print '<span style="display: inline-block; width: 12px; height: 12px; background-color: #28a745; margin-right: 5px; border-radius: 2px;"></span>'.ucfirst($langs->trans("HistoricalImportedWord")).' ';
            print '<span style="display: inline-block; width: 12px; height: 12px; background-color: #fd7e14; margin-left: 10px; margin-right: 5px; border-radius: 2px;"></span>'.ucfirst($langs->trans("HistoricalSkippedWord")).' ';
            print '<span style="display: inline-block; width: 12px; height: 12px; background-color: #6c757d; margin-left: 10px; margin-right: 5px; border-radius: 2px;"></span>'.ucfirst($langs->trans("HistoricalRemainingWord"));
            print '</div>';
        }
    } else {
        print '<em>'.$langs->trans("HistoricalImportNoData").'</em>';
    }
    print '</td>';
    print '</tr>';

    print '</table>';

    // v2.1.6: Bouton Enregistrer pour l'onglet orders
    print '<br><center>';
    print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
    print '</center>';

    // Story 22.4: Close the main orders form before opening dedicated shipping/payment forms
    print '</form>';

    // Story 22.4: Shipping section merged into orders tab
    print '<br>';
    print '<h2 id="section-shipping">' . dol_escape_htmltag($langs->trans("ShippingMethodsMapping")) . '</h2>';

    print '<form method="POST" action="' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '" id="d2s-form-shipping">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="save_shipping_mapping">';

    if (empty($shippingmethods)) {
        print '<div class="warning">';
        print '<i class="fa fa-exclamation-triangle"></i> ';
        print '<strong>' . $langs->trans("NoDolibarrShippingMethodsAvailable") . '</strong><br>';
        print $langs->trans("NoDolibarrShippingMethodsAvailableHelp");
        print '</div><br>';
    }

    // Story 48-4 : setup.php gère la boutique par défaut → scope l'affichage des règles
    $mappingStoreId = $setupDefaultStoreId;
    include dol_buildpath('/doli2shop/admin/_shipping_mapping_table.inc.php', 0);

    print '<br><center>';
    print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
    print '</center>';
    print '</form>';

    // Story 22.4: Payment section merged into orders tab
    print '<br>';
    print '<h2 id="section-payment">' . dol_escape_htmltag($langs->trans("PaymentMethodsMapping")) . '</h2>';

    print '<form method="POST" action="' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '" id="d2s-form-payment">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="save_payment_mapping">';

    if (empty($paymentmethods)) {
        print '<div class="warning">';
        print '<i class="fa fa-exclamation-triangle"></i> ';
        print '<strong>' . $langs->trans("NoDolibarrPaymentMethodsAvailable") . '</strong><br>';
        print $langs->trans("NoDolibarrPaymentMethodsAvailableHelp");
        print '</div><br>';
    }

    print '<div class="opacitymedium">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("PaymentMethodsMappingDesc");
    print '</div>';

    print '<table id="payment-mappings" class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th class="titlefieldcreate">' . $langs->trans("ShopifyPaymentMethod") . '</th>';
    print '<th>' . $langs->trans("DolibarrPaymentMethod") . '</th>';
    print '<th><a class="butActionNew" id="addPaymentMapping" href="#"' . (!empty($paymentmethods) ? '' : ' style="pointer-events:none;opacity:0.5"') . '><i class="fa fa-plus-circle" title="' . $langs->trans("AddPaymentMapping") . '"></i></a></th>';
    print '</tr>';

    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_payments WHERE entity = " . (int)$conf->entity . " AND active = 1" . $setupStoreFilter;
    $resql = $db->query($sql);
    $has_rows = false;
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $has_rows = true;
            print '<tr>';
            print '<td><input type="text" name="shopify_payment_method[]" value="' . dol_escape_htmltag($obj->shopify_payment_method) . '" class="minwidth300" placeholder="' . $langs->trans("Example") . ': card, bank, paypal"></td>';
            print '<td><select name="dolibarr_payment_id[]" class="flat minwidth200">';
            foreach ($paymentmethods as $id => $label) {
                print '<option value="' . (int)$id . '"' . ($obj->dolibarr_payment_id == $id ? ' selected' : '') . '>' . dol_escape_htmltag($label) . '</option>';
            }
            print '</select></td>';
            print '<td class="center"><i class="fa fa-trash payment-delete" title="' . $langs->trans("Delete") . '"></i></td>';
            print '</tr>';
        }
        $db->free($resql);
    }

    if (!$has_rows) {
        print '<tr class="oddeven payment-empty-msg"><td colspan="3" class="opacitymedium center">';
        print '<i class="fa fa-info-circle"></i> ' . $langs->trans("NoPaymentMethodMappingDefined") . '<br>';
        print $langs->trans("ClickAddButtonToCreate");
        print '</td></tr>';
    }

    print '</table>';

    print '<script>';
    print 'document.addEventListener("DOMContentLoaded", function() {';
    print '  var paymentOptionsHtml = \'';
    foreach ($paymentmethods as $id => $label) {
        print '<option value="' . dol_escape_htmltag($id) . '">' . dol_escape_htmltag($label) . '</option>';
    }
    print '\';';
    print '  function attachPaymentDeleteHandlers() {';
    print '    document.querySelectorAll(".payment-delete").forEach(function(btn) {';
    print '      btn.onclick = function() {';
    print '        if (confirm("' . $langs->trans("ConfirmDeleteMapping") . '")) { this.closest("tr").remove(); }';
    print '      };';
    print '    });';
    print '  }';
    print '  attachPaymentDeleteHandlers();';
    print '  document.getElementById("addPaymentMapping").addEventListener("click", function(e) {';
    print '    e.preventDefault();';
    print '    var emptyRow = document.querySelector(".payment-empty-msg");';
    print '    if (emptyRow) emptyRow.remove();';
    print '    var table = document.getElementById("payment-mappings");';
    print '    var newRow = table.insertRow(-1);';
    print '    var cellHtml = \'<td><input type="text" name="shopify_payment_method[]" class="minwidth300" placeholder="' . $langs->trans("Example") . ': card, bank, paypal"></td>\';';
    print '    cellHtml += \'<td><select name="dolibarr_payment_id[]" class="flat minwidth200">\' + paymentOptionsHtml + \'</select></td>\';';
    print '    cellHtml += \'<td class="center"><i class="fa fa-trash payment-delete" title="' . $langs->trans("Delete") . '"></i></td>\';';
    print '    newRow.innerHTML = cellHtml;';
    print '    attachPaymentDeleteHandlers();';
    print '  });';
    print '});';
    print '</script>';

    print '<br><center>';
    print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
    print '</center>';
    print '</form>';

} elseif ($current_tab == 'products' && $shopify_configured) {

    // Product Synchronization Options

    print '<div class="opacitymedium">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("ProductSyncExplanation") . '</p>';
    print '</div>';
    
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th colspan="2">' . $langs->trans("ProductSyncOptions") . '</th>';
    print '</tr>';

    // Direction de synchronisation globale des produits (v2.1.6)
    $currentDirection = $viewStoreSettings->get($viewStoreId, 'SYNC_PRODUCTS_DIRECTION', '');
    $directionNotConfigured = empty($currentDirection);

    // Avertissement si direction non configurée
    if ($directionNotConfigured) {
        print '<tr class="pair">';
        print '<td colspan="2">';
        print '<div class="warning" style="padding: 10px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 10px;">';
        print '<i class="fa fa-exclamation-triangle" style="color: #856404;"></i> ';
        print '<strong style="color: #856404;">' . $langs->trans("SyncDirectionNotConfigured") . '</strong>';
        print '</div>';
        print '</td>';
        print '</tr>';
    }

    print '<tr class="pair">';
    print '<td class="titlefieldcreate"><strong>' . $langs->trans("SyncProductsDirection") . ' <span class="fieldrequired">*</span></strong></td>';
    print '<td>';
    print '<select name="sync_products_direction" id="sync_products_direction" class="flat minwidth200" required>';
    print '<option value="">' . $langs->trans("SelectSyncDirection") . '</option>';
    print '<option value="none"' . ($currentDirection == 'none' ? ' selected' : '') . '>' . $langs->trans("NoSyncDisabled") . '</option>';
    print '<option value="dolibarr_to_shopify"' . ($currentDirection == 'dolibarr_to_shopify' ? ' selected' : '') . '>' . $langs->trans("DolibarrToShopifyOnly") . '</option>';
    print '<option value="shopify_to_dolibarr"' . ($currentDirection == 'shopify_to_dolibarr' ? ' selected' : '') . '>' . $langs->trans("ShopifyToDolibarrOnly") . '</option>';
    print '<option value="both"' . ($currentDirection == 'both' ? ' selected' : '') . '>' . $langs->trans("BothDirections") . '</option>';
    print '</select>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductsDirectionHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Info message selon la direction choisie
    print '<tr class="impair">';
    print '<td colspan="2">';
    print '<div id="sync_direction_info" class="opacitymedium" style="padding: 5px 10px; background-color: #f0f8ff; border-radius: 4px;">';
    print '<i class="fa fa-info-circle"></i> <span id="sync_direction_message">';
    if ($currentDirection == 'none') {
        print $langs->trans("SyncProductsDirectionNoneInfo");
    } elseif ($currentDirection == 'dolibarr_to_shopify') {
        print $langs->trans("SyncProductsDirectionDolibarrInfo");
    } elseif ($currentDirection == 'shopify_to_dolibarr') {
        print $langs->trans("SyncProductsDirectionShopifyInfo");
    } elseif ($currentDirection == 'both') {
        print $langs->trans("SyncProductsDirectionBothInfo");
    } else {
        print $langs->trans("SelectSyncDirectionFirst");
    }
    print '</span></div>';
    print '</td>';
    print '</tr>';

    // v2.1.6: Résolution des conflits (toujours visible)
    $currentConflictResolution = $viewStoreSettings->get($viewStoreId, 'SYNC_PRODUCTS_CONFLICT_RESOLUTION', 'dolibarr_wins');
    print '<tr class="impair" id="conflict_resolution_row">';
    print '<td class="titlefieldcreate"><strong>' . $langs->trans("SyncProductsConflictResolution") . '</strong></td>';
    print '<td>';
    print '<select name="sync_products_conflict_resolution" id="sync_products_conflict_resolution" class="flat minwidth200">';
    print '<option value="dolibarr_wins"' . ($currentConflictResolution == 'dolibarr_wins' ? ' selected' : '') . '>' . $langs->trans("DolibarrWins") . '</option>';
    print '<option value="shopify_wins"' . ($currentConflictResolution == 'shopify_wins' ? ' selected' : '') . '>' . $langs->trans("ShopifyWins") . '</option>';
    print '<option value="newest_wins"' . ($currentConflictResolution == 'newest_wins' ? ' selected' : '') . '>' . $langs->trans("NewestWins") . '</option>';
    print '<option value="manual"' . ($currentConflictResolution == 'manual' ? ' selected' : '') . '>' . $langs->trans("ManualResolution") . '</option>';
    print '</select>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductsConflictResolutionHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Vendeur Shopify pour export produits
    $existingVendors = [];
    try {
        $existingVendors = $shopifyApi->getVendors(500);
    } catch (Exception $e) {
        // Silencieux - pas de vendeurs si erreur API
    }
    $currentVendor = $viewStoreSettings->get($viewStoreId, 'VENDOR', '');

    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("ShopifyVendor") . doli2shopPerStoreBadge($viewStoreSettings, $viewStoreId, 'VENDOR', $viewStoreIsDefault) . '</td>';
    print '<td>';
    print '<input type="text" name="shopify_vendor" id="shopify_vendor" list="vendor_list" value="' . dol_escape_htmltag($currentVendor) . '" size="40" placeholder="' . $langs->trans("ShopifyVendorPlaceholder") . '">';
    print '<datalist id="vendor_list">';
    foreach ($existingVendors as $vendor) {
        print '<option value="' . dol_escape_htmltag($vendor) . '">';
    }
    print '</datalist>';
    print ' <span class="help-icon" title="' . $langs->trans("ShopifyVendorHelp") . '">?</span>';
    if (count($existingVendors) > 0) {
        print '<br><span class="opacitymedium" style="font-size: 0.9em;">📋 ' . count($existingVendors) . ' ' . $langs->trans("ExistingVendorsFound") . '</span>';
    }
    print '</td>';
    print '</tr>';

    // Séparateur visuel
    print '<tr class="liste_titre">';
    print '<th colspan="2">' . $langs->trans("SyncOptionsDetail") . '</th>';
    print '</tr>';

    // Story 58-5 (AC1) : ne pousser vers Shopify QUE les produits "En Vente" (tosell=1) à la
    // création — OFF par défaut, rétrocompatibilité stricte. Ne filtre jamais le RETRAIT d'un
    // produit déjà mappé (invariant AC3, cf. ImportProducts::shouldSkipInactiveProductCreation()).
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductsOnlyActive") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="sync_products_only_active" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCTS_ONLY_ACTIVE', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductsOnlyActiveHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Option pour synchroniser les prix
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductPrices") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" id="sync_product_prices" name="sync_product_prices" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_PRICES', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductPricesHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Sélection du niveau de prix (uniquement si le module produit est en mode multiprix)
    if (getDolGlobalInt('PRODUIT_MULTIPRICES')) {
        print '<tr class="impair">';
        print '<td class="titlefieldcreate">' . $langs->trans("PriceLevel") . '</td>';
        print '<td style="padding-left: 15px;">';
        print '<select name="sync_price_level" id="sync_price_level" class="flat"' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_PRICES', 0) ? '' : ' disabled') . '>';
        
        // Récupérer le nombre de niveaux de prix configurés
        $nbPriceLevels = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT', 5);
        
        // En mode multiprices, on commence directement au niveau 1
        for ($i = 1; $i <= $nbPriceLevels; $i++) {
            $pricelevel_label = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$i) ?: $langs->trans("PriceLevel") . ' ' . $i;
            print '<option value="' . $i . '"' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRICE_LEVEL', 1) == $i ? ' selected' : '') . '>' . $pricelevel_label . '</option>';
        }
        
        print '</select>';
        print ' <span class="help-icon" title="' . $langs->trans("PriceLevelHelp") . '">?</span>';
        print '</td>';
        print '</tr>';
    } else {
        // Mode prix unique - champ caché pour maintenir la valeur à 0
        print '<input type="hidden" name="sync_price_level" value="0">';
    }
    
    // Option pour la priorité des prix TTC vs HT+TVA (uniquement si mode multiprix)
    if (getDolGlobalInt('PRODUIT_MULTIPRICES')) {
        print '<tr class="pair">';
        print '<td class="titlefieldcreate">' . $langs->trans("PricePriorityTTC") . '</td>';
        print '<td style="padding-left: 15px;">';
        print '<label class="toggle-switch">';
        print '<input type="checkbox" name="price_priority_ttc" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'PRICE_PRIORITY_TTC', 0) ? 'checked' : '') . '>';
        print '<span class="toggle-slider"></span>';
        print '</label>';
        print ' <span class="help-icon" title="' . $langs->trans("PricePriorityTTCHelp") . '">?</span>';
        print '</td>';
        print '</tr>';
    }
    
    // Option pour synchroniser les descriptions
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductDescriptions") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="sync_product_descriptions" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_DESCRIPTIONS', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductDescriptionsHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Option pour synchroniser les images
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductImages") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="sync_product_images" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_IMAGES', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductImagesHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Option pour synchroniser les stocks
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductStocks") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" id="sync_product_stocks" name="sync_product_stocks" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_STOCKS', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductStocksHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Option pour utiliser le stock virtuel (déplacée ici)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("UseVirtualStock") . '</td>';
    print '<td style="padding-left: 15px;">';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" id="use_virtual_stock" name="use_virtual_stock" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'USE_VIRTUAL_STOCK', 0) ? 'checked' : '') . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_STOCKS', 0) ? '' : ' disabled') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("UseVirtualStockHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Option pour la politique inventaire (continuer a vendre en cas de rupture)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">' . $langs->trans("InventoryPolicyContinueSelling") . '</td>';
    print '<td style="padding-left: 15px;">';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" id="inventory_policy_continue_selling" name="inventory_policy_continue_selling" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'INVENTORY_POLICY_CONTINUE_SELLING', 0) ? 'checked' : '') . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_STOCKS', 0) ? '' : ' disabled') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("InventoryPolicyContinueSellingHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Option pour synchroniser les attributs
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductAttributes") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="sync_product_attributes" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_ATTRIBUTES', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductAttributesHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Option pour synchroniser les collections
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">' . $langs->trans("SyncProductCollections") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" id="sync_product_collections" name="sync_product_collections" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_COLLECTIONS', 0) ? 'checked' : '') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncProductCollectionsHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Option pour inclure automatiquement les catégories parentes (sous-option)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate" style="padding-left: 15px;">' . $langs->trans("IncludeParentCategories") . '</td>';
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" id="include_parent_categories" name="include_parent_categories" value="1" ' . ($viewStoreSettings->getInt($viewStoreId, 'INCLUDE_PARENT_CATEGORIES', 0) ? 'checked' : '') . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_COLLECTIONS', 0) ? '' : ' disabled') . '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print ' <span class="help-icon" title="' . $langs->trans("IncludeParentCategoriesHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Direction de synchronisation des collections (sous-option)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate" style="padding-left: 15px;">' . $langs->trans("SyncCollectionsDirection") . '</td>';
    print '<td>';
    print '<select name="sync_collections_direction" id="sync_collections_direction" class="flat"' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_COLLECTIONS', 0) ? '' : ' disabled') . '>';
    $currentCollDir = $viewStoreSettings->get($viewStoreId, 'SYNC_COLLECTIONS_DIRECTION', 'dol_to_shop');
    print '<option value="dol_to_shop"' . ($currentCollDir == 'dol_to_shop' ? ' selected' : '') . '>' . $langs->trans("DolibarrToShopify") . '</option>';
    print '<option value="shop_to_dol"' . ($currentCollDir == 'shop_to_dol' ? ' selected' : '') . '>' . $langs->trans("ShopifyToDolibarr") . '</option>';
    print '<option value="bidirectional"' . ($currentCollDir == 'bidirectional' ? ' selected' : '') . '>' . $langs->trans("Bidirectional") . '</option>';
    print '</select>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncCollectionsDirectionHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Résolution de conflits (seulement si bidirectionnel)
    print '<tr class="impair">';
    print '<td class="titlefieldcreate" style="padding-left: 15px;">' . $langs->trans("SyncCollectionsConflictResolution") . '</td>';
    print '<td>';
    $currentCollConflict = $viewStoreSettings->get($viewStoreId, 'SYNC_COLLECTIONS_CONFLICT_RESOLUTION', 'dolibarr_priority');
    print '<select name="sync_collections_conflict_resolution" id="sync_collections_conflict_resolution" class="flat"' . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_COLLECTIONS', 0) && $currentCollDir == 'bidirectional' ? '' : ' disabled') . '>';
    print '<option value="dolibarr_priority"' . ($currentCollConflict == 'dolibarr_priority' ? ' selected' : '') . '>' . $langs->trans("DolibarrPriority") . '</option>';
    print '<option value="shopify_priority"' . ($currentCollConflict == 'shopify_priority' ? ' selected' : '') . '>' . $langs->trans("ShopifyPriority") . '</option>';
    print '<option value="newest_wins"' . ($currentCollConflict == 'newest_wins' ? ' selected' : '') . '>' . $langs->trans("NewestWins") . '</option>';
    print '</select>';
    print ' <span class="help-icon" title="' . $langs->trans("SyncCollectionsConflictResolutionHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Canaux de ventes pour les collections (v2.0.33)
    print '<tr class="pair">';
    print '<td class="titlefieldcreate" style="padding-left: 15px;">' . $langs->trans("CollectionsSalesChannels") . '</td>';
    print '<td>';

    // Parse currently selected channels
    $selectedChannels = array_filter(explode(',', $viewStoreSettings->get($viewStoreId, 'COLLECTIONS_SALES_CHANNELS', '')));

    if (!empty($availablePublications)) {
        print '<div style="max-height: 150px; overflow-y: auto; border: 1px solid #ccc; padding: 5px;">';
        foreach ($availablePublications as $pub) {
            $isSelected = in_array($pub['id'], $selectedChannels);
            print '<label style="display: block; margin-bottom: 3px;">';
            print '<input type="checkbox" name="collections_sales_channels[]" value="' . htmlspecialchars($pub['id']) . '"';
            print ($isSelected ? ' checked' : '') . ($viewStoreSettings->getInt($viewStoreId, 'SYNC_PRODUCT_COLLECTIONS', 0) ? '' : ' disabled') . '>';
            print ' ' . htmlspecialchars(translateChannelName(html_entity_decode($pub['name'], ENT_QUOTES, 'UTF-8'), $langs));
            if (!empty($pub['app_title'])) {
                print ' <small>(' . htmlspecialchars(html_entity_decode($pub['app_title'], ENT_QUOTES, 'UTF-8')) . ')</small>';
            }
            print '</label>';
        }
        print '</div>';
        print '<div class="opacitymedium" style="margin-top: 5px;">';
        print '<small>' . $langs->trans("CollectionsSalesChannelsNote") . '</small>';
        print '</div>';
    } else {
        print '<em>' . $langs->trans("NoSalesChannelsAvailable") . '</em>';
        print '<br><small>' . $langs->trans("ConfigureShopifyConnectionFirst") . '</small>';
    }

    print ' <span class="help-icon" title="' . $langs->trans("CollectionsSalesChannelsHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    print '</table>';
    
    // Boutons pour tout sélectionner / tout désélectionner
    print '<div class="tabsAction" style="text-align: right;">';
    print '<a class="butAction" id="selectAllOptions" href="#">' . $langs->trans("SelectAllOptions") . '</a>';
    print '<a class="butActionDelete" id="unselectAllOptions" href="#">' . $langs->trans("UnselectAllOptions") . '</a>';
    print '</div>';
    
    print '<div class="opacitymedium info">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("SyncOptionsNote");
    print '</div>';
    ?>
    
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Fonction pour activer/désactiver les champs dépendants
        function updateDependentFields() {
            // Gestion du niveau de prix
            var syncPricesCheckbox = document.getElementById("sync_product_prices");
            var priceLevelSelect = document.getElementById("sync_price_level");
            if (priceLevelSelect) {
                priceLevelSelect.disabled = !syncPricesCheckbox.checked;
            }
            
            // Gestion du stock virtuel et de la politique inventaire
            var syncStocksCheckbox = document.getElementById("sync_product_stocks");
            var virtualStockCheckbox = document.getElementById("use_virtual_stock");
            var inventoryPolicyCheckbox = document.getElementById("inventory_policy_continue_selling");
            
            if (virtualStockCheckbox) {
                virtualStockCheckbox.disabled = !syncStocksCheckbox.checked;
                // Si stocks désactivés, décocher aussi stock virtuel
                if (!syncStocksCheckbox.checked) {
                    virtualStockCheckbox.checked = false;
                }
            }
            
            if (inventoryPolicyCheckbox) {
                inventoryPolicyCheckbox.disabled = !syncStocksCheckbox.checked;
                // Si stocks desactives, decocher aussi la politique inventaire
                if (!syncStocksCheckbox.checked) {
                    inventoryPolicyCheckbox.checked = false;
                }
            }
            
            // Gestion des collections
            var syncCollectionsCheckbox = document.getElementById("sync_product_collections");
            var includeParentCategoriesCheckbox = document.getElementById("include_parent_categories");
            var collectionsDirectionSelect = document.getElementById("sync_collections_direction");
            var conflictResolutionSelect = document.getElementById("sync_collections_conflict_resolution");

            if (includeParentCategoriesCheckbox) {
                includeParentCategoriesCheckbox.disabled = !syncCollectionsCheckbox.checked;
            }

            if (collectionsDirectionSelect) {
                collectionsDirectionSelect.disabled = !syncCollectionsCheckbox.checked;
            }

            if (conflictResolutionSelect) {
                var isBidirectional = collectionsDirectionSelect && collectionsDirectionSelect.value === "bidirectional";
                conflictResolutionSelect.disabled = !syncCollectionsCheckbox.checked || !isBidirectional;
            }
        }
        
        // Attacher les événements aux checkboxes
        var syncPricesCheckbox = document.getElementById("sync_product_prices");
        var syncStocksCheckbox = document.getElementById("sync_product_stocks");
        var syncCollectionsCheckbox = document.getElementById("sync_product_collections");
        var collectionsDirectionSelect = document.getElementById("sync_collections_direction");
        
        if (syncPricesCheckbox) {
            syncPricesCheckbox.addEventListener("change", updateDependentFields);
        }
        
        if (syncStocksCheckbox) {
            syncStocksCheckbox.addEventListener("change", updateDependentFields);
        }
        
        if (syncCollectionsCheckbox) {
            syncCollectionsCheckbox.addEventListener("change", updateDependentFields);
        }
        
        if (collectionsDirectionSelect) {
            collectionsDirectionSelect.addEventListener("change", updateDependentFields);
        }
        
        // Sélectionner toutes les options
        document.getElementById("selectAllOptions").addEventListener("click", function(e) {
            e.preventDefault();
            var checkboxes = document.querySelectorAll(\'input[type="checkbox"][name^="sync_product_"], input[type="checkbox"][name="include_parent_categories"], input[type="checkbox"][name="use_virtual_stock"], input[type="checkbox"][name="inventory_policy_continue_selling"]\');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = true;
            });
            // Mettre à jour les champs dépendants
            updateDependentFields();
        });
        
        // Désélectionner toutes les options
        document.getElementById("unselectAllOptions").addEventListener("click", function(e) {
            e.preventDefault();
            var checkboxes = document.querySelectorAll(\'input[type="checkbox"][name^="sync_product_"], input[type="checkbox"][name="use_virtual_stock"], input[type="checkbox"][name="inventory_policy_continue_selling"]\');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });
            // Mettre à jour les champs dépendants
            updateDependentFields();
        });
        
        // v2.1.6: Gestion de la direction de synchronisation
        var syncDirectionSelect = document.getElementById("sync_products_direction");
        var directionMessageSpan = document.getElementById("sync_direction_message");

        // Messages traduits pour chaque direction
        var directionMessages = {
            "": "<?php print dol_escape_js($langs->trans("SelectSyncDirectionFirst")); ?>",
            "none": "<?php print dol_escape_js($langs->trans("SyncProductsDirectionNoneInfo")); ?>",
            "dolibarr_to_shopify": "<?php print dol_escape_js($langs->trans("SyncProductsDirectionDolibarrInfo")); ?>",
            "shopify_to_dolibarr": "<?php print dol_escape_js($langs->trans("SyncProductsDirectionShopifyInfo")); ?>",
            "both": "<?php print dol_escape_js($langs->trans("SyncProductsDirectionBothInfo")); ?>"
        };

        function updateDirectionInfo() {
            if (syncDirectionSelect && directionMessageSpan) {
                var direction = syncDirectionSelect.value;
                directionMessageSpan.textContent = directionMessages[direction] || directionMessages[""];
            }
        }

        if (syncDirectionSelect) {
            syncDirectionSelect.addEventListener("change", updateDirectionInfo);
        }

        // Initialiser l\'état des champs au chargement
        updateDependentFields();
        updateDirectionInfo();
    });
    </script>

    <?php
    // v2.1.6: Bouton Enregistrer déplacé ici (avant section Maintenance)
    print '<br><center>';
    print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
    print '</center>';
    print '</form>';

    // Section Maintenance (en dehors du formulaire car utilise des actions AJAX)
    // Récupérer la configuration pour avoir la catégorie par défaut (fix recherche maintenance)
    $migrator = new ConfigurationMigrator($db);
    $configArray = $migrator->getConfiguration($conf->entity);
    $defaultCategoryId = $configArray['dolibarr_procate'] ?? 0;

    print '<br>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th colspan="2">' . $langs->trans("Maintenance") . '</th>';
    print '</tr>';
    
    // Purge complète de la table de synchronisation
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">' . $langs->trans("PurgeSyncTable") . '</td>';
    print '<td>';
    print '<a class="butActionDelete" href="#" onclick="confirmPurgeSync(); return false;">' . $langs->trans("PurgeAllProducts") . '</a>';
    print ' <span class="help-icon" title="' . $langs->trans("PurgeSyncTableHelp") . '">?</span>';
    print '</td>';
    print '</tr>';
    
    // Purge d'un produit spécifique
    print '<tr class="pair">';
    print '<td class="titlefieldcreate">' . $langs->trans("PurgeSpecificProduct") . '</td>';
    print '<td>';

    // FIX #146 v2.1.2: Dropdown filtre par type de produit
    print '<label for="product_type_filter" style="margin-right: 10px;">' . $langs->trans("ProductType") . ':</label>';
    print '<select name="product_type_filter" id="product_type_filter" class="flat maxwidth200" style="margin-right: 10px;">';
    print '<option value="all" selected>' . $langs->trans("ProductsAndServices") . '</option>';
    print '<option value="0">' . $langs->trans("ProductsOnly") . '</option>';
    print '<option value="1">' . $langs->trans("ServicesOnly") . '</option>';
    print '</select>';
    print '<br><br>';

    // Use field with ajax autocomplete for products in the configured category and subcategories
    print '<input type="text" id="search_product_ref" name="search_product_ref" value="" class="minwidth300" placeholder="' . $langs->trans("SearchProductByRef") . '" />';
    print '<input type="hidden" id="product_to_purge_id" name="product_to_purge_id" value="" />';
    print '<div id="product_ref_result" style="margin-top: 5px; color: #666;"></div>';

    // Ajouter une petite indication pour l'utilisateur
    print '<div class="opacitymedium" style="font-size: 0.9em; margin-top: 5px;">' . $langs->trans("TypeAtLeast2CharsToSearch") . '</div>';

    print ' <a class="butActionDelete" href="#" onclick="confirmPurgeProduct(); return false;">' . $langs->trans("PurgeProduct") . '</a>';
    print ' <span class="help-icon" title="' . $langs->trans("PurgeSpecificProductHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC4) : porte de sortie explicite
    // — forcer le renvoi complet des images du MÊME produit sélectionné ci-dessus, sans purge
    // globale ni SQL. Plus étroit et plus rapide que "Purger ce produit" : ne touche pas le
    // mapping Shopify, seulement le hash d'images, et renvoie immédiatement.
    print '<tr class="impair">';
    print '<td class="titlefieldcreate">' . $langs->trans("ForceResyncProductImages") . '</td>';
    print '<td>';
    print '<div class="opacitymedium" style="font-size: 0.9em; margin-bottom: 5px;">' . $langs->trans("ForceResyncProductImagesUseSameSearch") . '</div>';
    print '<a class="butAction" href="#" onclick="confirmForceResyncImages(); return false;">' . $langs->trans("ForceResyncProductImages") . '</a>';
    print ' <span class="help-icon" title="' . $langs->trans("ForceResyncProductImagesHelp") . '">?</span>';
    print '</td>';
    print '</tr>';

    print '</table>';
    
    print '<script>
    /* Translation variables - Define all translations at the top */
    var SEARCH_TEXT = "' . dol_escape_js($langs->trans("Searching")) . '";
    var NO_PRODUCTS_FOUND_TEXT = "' . dol_escape_js($langs->trans("NoProductsFound")) . '";
    var SEARCH_ERROR_TEXT = "' . dol_escape_js($langs->trans("SearchError")) . '";
    
    /* Utility function to decode HTML entities in strings */
    function decodeHtml(html) {
        var txt = document.createElement("textarea");
        txt.innerHTML = html;
        return txt.value;
    }
    function confirmPurgeSync() {
        var confirmMessage = "' . dol_escape_js($langs->trans("ConfirmPurgeSyncTable")) . '";
        
        $("<div></div>").dialog({
            title: "' . dol_escape_js($langs->trans("Confirm")) . '",
            resizable: false,
            modal: true,
            width: 450,
            buttons: {
                "' . dol_escape_js($langs->trans("Yes")) . '": function() {
                    $(this).dialog("close");
                    var form = document.createElement("form");
                    form.method = "POST";
                    form.action = "' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '";
                    
                    var input = document.createElement("input");
                    input.type = "hidden";
                    input.name = "action";
                    input.value = "purge_sync_table";
                    
                    var token = document.createElement("input");
                    token.type = "hidden";
                    token.name = "token";
                    token.value = "' . newToken() . '";
                    
                    var tab = document.createElement("input");
                    tab.type = "hidden";
                    tab.name = "tab";
                    tab.value = "products";
                    
                    form.appendChild(input);
                    form.appendChild(token);
                    form.appendChild(tab);
                    document.body.appendChild(form);
                    form.submit();
                },
                "' . dol_escape_js($langs->trans("No")) . '": function() {
                    $(this).dialog("close");
                }
            }
        }).html(confirmMessage);
    }
    
    function confirmPurgeProduct() {
        var productId = document.getElementById("product_to_purge_id").value;
        var productRef = document.getElementById("search_product_ref").value;
        if (!productId || !productRef) {
            $.jnotify("' . dol_escape_js($langs->trans("PleaseSelectProduct")) . '", "error");
            return;
        }
        
        var confirmMessage = "' . dol_escape_js($langs->trans("ConfirmPurgeProduct")) . ' " + productRef + " ' . dol_escape_js($langs->trans("AndItsVariants")) . '";
        
        $("<div></div>").dialog({
            title: "' . dol_escape_js($langs->trans("Confirm")) . '",
            resizable: false,
            modal: true,
            width: 450,
            buttons: {
                "' . dol_escape_js($langs->trans("Yes")) . '": function() {
                    $(this).dialog("close");
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '";
            
            var actionInput = document.createElement("input");
            actionInput.type = "hidden";
            actionInput.name = "action";
            actionInput.value = "purge_specific_product";
            
            var productInput = document.createElement("input");
            productInput.type = "hidden";
            productInput.name = "product_id";
            productInput.value = productId;
            
            var token = document.createElement("input");
            token.type = "hidden";
            token.name = "token";
            token.value = "' . newToken() . '";
            
            var tab = document.createElement("input");
            tab.type = "hidden";
            tab.name = "tab";
            tab.value = "products";
            
            form.appendChild(actionInput);
            form.appendChild(productInput);
            form.appendChild(token);
            form.appendChild(tab);
            document.body.appendChild(form);
            form.submit();
                },
                "' . dol_escape_js($langs->trans("No")) . '": function() {
                    $(this).dialog("close");
                }
            }
        }).html(confirmMessage);
    }

    // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC4) : même produit sélectionné
    // que confirmPurgeProduct() ci-dessus (mêmes champs #product_to_purge_id/#search_product_ref),
    // action distincte et plus étroite (hash d\'images seulement, renvoi immédiat).
    function confirmForceResyncImages() {
        var productId = document.getElementById("product_to_purge_id").value;
        var productRef = document.getElementById("search_product_ref").value;
        if (!productId || !productRef) {
            $.jnotify("' . dol_escape_js($langs->trans("PleaseSelectProduct")) . '", "error");
            return;
        }

        var confirmMessage = "' . dol_escape_js($langs->trans("ConfirmForceResyncProductImages")) . ' " + productRef;

        $("<div></div>").dialog({
            title: "' . dol_escape_js($langs->trans("Confirm")) . '",
            resizable: false,
            modal: true,
            width: 450,
            buttons: {
                "' . dol_escape_js($langs->trans("Yes")) . '": function() {
                    $(this).dialog("close");
                    var form = document.createElement("form");
                    form.method = "POST";
                    form.action = "' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '";

                    var actionInput = document.createElement("input");
                    actionInput.type = "hidden";
                    actionInput.name = "action";
                    actionInput.value = "force_resync_product_images";

                    var productInput = document.createElement("input");
                    productInput.type = "hidden";
                    productInput.name = "product_id";
                    productInput.value = productId;

                    var token = document.createElement("input");
                    token.type = "hidden";
                    token.name = "token";
                    token.value = "' . newToken() . '";

                    var tab = document.createElement("input");
                    tab.type = "hidden";
                    tab.name = "tab";
                    tab.value = "products";

                    form.appendChild(actionInput);
                    form.appendChild(productInput);
                    form.appendChild(token);
                    form.appendChild(tab);
                    document.body.appendChild(form);
                    form.submit();
                },
                "' . dol_escape_js($langs->trans("No")) . '": function() {
                    $(this).dialog("close");
                }
            }
        }).html(confirmMessage);
    }

    // Autocomplete for product selection
    $(document).ready(function() {
        $("#search_product_ref").autocomplete({
            source: function(request, response) {
                // FIX #146 v2.1.2: Récupérer le filtre type de produit
                var productTypeFilter = $("#product_type_filter").val();

                $.ajax({
                    url: "../ajax/search_products.php",
                    type: "POST",
                    dataType: "json",
                    data: {
                        term: request.term,
                        category_id: ' . (int)$defaultCategoryId . ',
                        product_type: productTypeFilter,  // FIX #146 v2.1.2
                        token: "' . currentToken() . '"
                    },
                    /* Show loading spinner while search is in progress */
                    beforeSend: function() {
                        $("#product_ref_result").html("<div><i class=\"fa fa-spinner fa-spin\"></i> " + SEARCH_TEXT + "...</div>");
                    },
                    /* Handle successful search response */
                    success: function(data) {
                        $("#product_ref_result").html("");
                        
                        if (!data || data.length === 0) {
                            $("#product_ref_result").html("<div><i class=\"fa fa-exclamation-circle\"></i> " + NO_PRODUCTS_FOUND_TEXT + "</div>");
                        }
                        
                        response(data);
                    },
                    /* Handle errors during search */
                    error: function(xhr, status, error) {
                        $("#product_ref_result").html("<div><i class=\"fa fa-exclamation-triangle\"></i> " + SEARCH_ERROR_TEXT + "</div>");
                        console.error("Ajax error:", status, error);
                        response([]);
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $("#product_to_purge_id").val(ui.item.id);
                $("#search_product_ref").val(ui.item.label);
                $("#product_ref_result").html("<div>" + ui.item.label + "</div>");
                return false;
            },
            change: function(event, ui) {
                if (!ui.item) {
                    $("#product_to_purge_id").val("");
                    $("#product_ref_result").html("");
                }
            }
        });
    });
    </script>';
    
} else {
    print '<div class="warning">'.$langs->trans("ShopifyConfigurationRequired").'</div>';
}

// v2.1.6: Bouton Enregistrer et fermeture formulaire déplacés avant section Maintenance

print dol_get_fiche_end();

/**
 * Check if product configuration is complete for CRON activation
 *
 * @param int $entity Entity ID
 * @return bool True if configuration is complete
 */
function isProductConfigurationComplete($entity)
{
    global $db;
    
    try {
        // Check required Shopify settings using constants (v2.0.27)
        if (empty(getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '', $entity)) ||
            empty(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN', '', $entity)) ||
            empty(getDolGlobalString('DOLI2SHOP_LOCATION_ID', '', $entity))) {
            return false;
        }

        // Check required product settings
        if (empty(getDolGlobalInt('DOLI2SHOP_DOLIBARR_PROCATE', 0, $entity))) {
            return false;
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if order configuration is complete for CRON activation
 *
 * @param int $entity Entity ID
 * @return bool True if configuration is complete
 */
function isOrderConfigurationComplete($entity)
{
    global $db;
    
    try {
        // Check required Shopify settings using constants (v2.0.27)
        if (empty(getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '', $entity)) ||
            empty(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN', '', $entity)) ||
            empty(getDolGlobalString('DOLI2SHOP_LOCATION_ID', '', $entity))) {
            return false;
        }

        // Check required order settings
        if (empty(getDolGlobalInt('DOLI2SHOP_ORDER_ORIGIN', 0, $entity)) ||
            empty(getDolGlobalInt('DOLI2SHOP_PAYMENT_TERMS', 0, $entity)) ||
            empty(getDolGlobalInt('DOLI2SHOP_DEFAULT_SHIPPING_METHOD_ID', 0, $entity)) ||
            empty(getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE_ID', 0, $entity))) {
            return false;
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Update CRON status based on configuration completeness
 *
 * @param int $entity Entity ID
 */
function updateCronStatus($entity)
{
    global $db, $langs;
    
    try {
        // Check configuration status for both CRONs
        $productConfigComplete = isProductConfigurationComplete($entity);
        $orderConfigComplete = isOrderConfigurationComplete($entity);
        
        // Define CRON job names and their required configuration status
        $cronJobs = [
            'ShopifyIntegration-ProductsSync' => $productConfigComplete,
            'ShopifyIntegration-OrdersSync' => $orderConfigComplete
        ];
        
        $activatedCrons = [];
        $deactivatedCrons = [];
        
        foreach ($cronJobs as $cronLabel => $shouldBeActive) {
            // Find the CRON job by label pattern
            $sql = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "cronjob 
                   WHERE label LIKE '%" . $db->escape($cronLabel) . "%' 
                   AND entity = " . (int)$entity;
            
            $result = $db->query($sql);
            if ($result && $db->num_rows($result) > 0) {
                $obj = $db->fetch_object($result);
                $currentStatus = $obj->status;
                $cronId = $obj->rowid;
                
                // Update status if needed
                if ($shouldBeActive && $currentStatus == 0) {
                    // Activate CRON
                    $updateSql = "UPDATE " . MAIN_DB_PREFIX . "cronjob 
                                 SET status = 1 
                                 WHERE rowid = " . (int)$cronId;
                    
                    if ($db->query($updateSql)) {
                        $activatedCrons[] = str_replace('ShopifyIntegration-', '', $cronLabel);
                    }
                } elseif (!$shouldBeActive && $currentStatus == 1) {
                    // Deactivate CRON
                    $updateSql = "UPDATE " . MAIN_DB_PREFIX . "cronjob 
                                 SET status = 0 
                                 WHERE rowid = " . (int)$cronId;
                    
                    if ($db->query($updateSql)) {
                        $deactivatedCrons[] = str_replace('ShopifyIntegration-', '', $cronLabel);
                    }
                }
            }
        }
        
        // Show user feedback about CRON status changes
        if (!empty($activatedCrons)) {
            $message = $langs->trans("CronAutomaticallyActivated", implode(', ', $activatedCrons));
            setEventMessages($message, null, 'mesgs');
        }
        
        if (!empty($deactivatedCrons)) {
            $message = $langs->trans("CronAutomaticallyDeactivated", implode(', ', $deactivatedCrons));
            setEventMessages($message, null, 'warnings');
        }
        
    } catch (Exception $e) {
        dol_syslog("Error updating CRON status: " . $e->getMessage(), LOG_ERR);
    }
}

/**
 * Mise à jour des CRONs produits selon la direction de synchronisation choisie
 * v2.1.6 - Gestion bidirectionnelle des produits
 *
 * @param int    $entity     Entité Dolibarr
 * @param string $direction  Direction: 'dolibarr_to_shopify', 'shopify_to_dolibarr', 'both'
 * @return void
 */
function updateProductCronsStatus($entity, $direction)
{
    global $db, $langs;

    try {
        // Définition des CRONs produits et leur statut selon la direction
        // Doli2ShopProductsExport = Export Dolibarr → Shopify (ImportProductsCron)
        // Doli2ShopProductsImport = Import Shopify → Dolibarr (ShopifyProductImportCron)
        $cronConfigs = [
            'Doli2ShopProductsExport' => [
                'active_for' => ['dolibarr_to_shopify', 'both'],
                'name' => 'ProductsExport (Dolibarr→Shopify)'
            ],
            'Doli2ShopProductsImport' => [
                'active_for' => ['shopify_to_dolibarr', 'both'],
                'name' => 'ProductsImport (Shopify→Dolibarr)'
            ]
        ];

        $activatedCrons = [];
        $deactivatedCrons = [];

        foreach ($cronConfigs as $cronLabel => $config) {
            $shouldBeActive = in_array($direction, $config['active_for']);

            // Recherche du CRON par label
            $sql = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "cronjob
                   WHERE label = '" . $db->escape($cronLabel) . "'
                   AND entity = " . (int)$entity;

            $result = $db->query($sql);
            if ($result && $db->num_rows($result) > 0) {
                $obj = $db->fetch_object($result);
                $currentStatus = (int)$obj->status;
                $cronId = $obj->rowid;

                // Mise à jour si nécessaire
                if ($shouldBeActive && $currentStatus == 0) {
                    // Activer le CRON
                    $updateSql = "UPDATE " . MAIN_DB_PREFIX . "cronjob
                                 SET status = 1
                                 WHERE rowid = " . (int)$cronId;

                    if ($db->query($updateSql)) {
                        $activatedCrons[] = $config['name'];
                        dol_syslog("CRON {$config['name']} activé (direction: $direction)", LOG_INFO);
                    }
                } elseif (!$shouldBeActive && $currentStatus == 1) {
                    // Désactiver le CRON
                    $updateSql = "UPDATE " . MAIN_DB_PREFIX . "cronjob
                                 SET status = 0
                                 WHERE rowid = " . (int)$cronId;

                    if ($db->query($updateSql)) {
                        $deactivatedCrons[] = $config['name'];
                        dol_syslog("CRON {$config['name']} désactivé (direction: $direction)", LOG_INFO);
                    }
                }
            }
        }

        // Feedback utilisateur
        if (!empty($activatedCrons)) {
            $message = $langs->trans("CronProductsActivated", implode(', ', $activatedCrons));
            setEventMessages($message, null, 'mesgs');
        }

        if (!empty($deactivatedCrons)) {
            $message = $langs->trans("CronProductsDeactivated", implode(', ', $deactivatedCrons));
            setEventMessages($message, null, 'warnings');
        }

    } catch (Exception $e) {
        dol_syslog("Erreur mise à jour CRONs produits: " . $e->getMessage(), LOG_ERR);
    }
}

// v2.2.0: Fonction updateOrdersCronStatus() supprimée — CRON OrdersImport supprimé

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

// Page end
llxFooter();
$db->close();