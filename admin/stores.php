<?php
/**
 * @file        admin/stores.php
 * @brief       Interface d'administration des boutiques Shopify (multi-boutiques)
 *
 * Permet de lister, ajouter, modifier, activer/désactiver, supprimer
 * les boutiques Shopify configurées dans Doli2Shop, et d'enregistrer
 * les webhooks pour chaque boutique.
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.2.0
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once '../lib/doli2shop.lib.php';
require_once '../class/storeservice.class.php';
require_once '../class/shopifywebhooks.class.php';
require_once '../class/shopifyapi.class.php';

// Load translation files required by the page
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Security check — admin only
if (!$user->admin) {
    accessforbidden();
}

$action  = GETPOST('action', 'aZ09');
$storeId = GETPOST('store_id', 'int');

$error = 0;

// Initialize StoreService
$storeService = new StoreService($db);

/*
 * Actions — toutes protégées par CSRF token
 */

// Modifier une boutique — config NON-credentials uniquement.
// Story 48-6 (conformité Shopify) : domaine + access_token + api_key/secret viennent de
// l'OAuth UNIQUEMENT (jamais de saisie manuelle). La création se fait via le flux OAuth
// (« Connecter une boutique » → connect_shopify → oauth_receive crée la ligne).
// Story 59-2 (AC1/AC2) : les 8 actions de cette page faisaient toutes sauter leur bloc EN SILENCE
// sur un jeton refusé (aucun message, aucune trace, aucun effet) — exactement le symptôme qui a
// empêché Nicolas Graillon de rattacher sa licence de boutique. Chaque garde ci-dessous affiche un
// message explicite ET journalise le refus (LOG_WARNING, action nommée + utilisateur) avant
// l'exécution du bloc d'action correspondant plus bas.
if ($action == 'update' && !empty($_POST) && !verifToken()) {
    dol_syslog("stores.php: action 'update' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'update' && !empty($_POST) && verifToken()) {
    // Story 48-2 : la fiche est découpée en sous-onglets, chaque onglet éditable poste SON
    // propre formulaire avec un marqueur `update_section`. On ne met à jour QUE les champs de
    // la section postée (update() n'agit que sur les clés présentes) → pas d'écrasement croisé.
    // Chaque sous-onglet poste STRICTEMENT sa propre section. Pas de chemin « section vide » :
    // l'ancien formulaire global n'existe plus, et un POST sans section connue ne doit RIEN
    // écrire (sinon GETPOST('fk_categorie') = 0 effacerait la catégorie — review 48-2 M1).
    $updateSection = GETPOST('update_section', 'aZ09');
    $data = array();

    if ($updateSection === 'connection') {
        $data['label']  = GETPOST('label', 'alphanohtml');
        $data['active'] = GETPOST('active', 'int') ? 1 : 0;
    } elseif ($updateSection === 'shipping') {
        $data['location_id'] = GETPOST('location_id', 'alphanohtml');
    } elseif ($updateSection === 'categories') {
        // catégorie produit liée éditable (0 = aucune → NULL) — Story 48-3
        $postCategorie = GETPOST('fk_categorie', 'int');
        $data['fk_categorie'] = $postCategorie > 0 ? (int) $postCategorie : null;
        // Catégories commande/facture/devis — Story 49-5
        $postCategorieOrder    = GETPOST('fk_categorie_order',    'int');
        $postCategorieInvoice  = GETPOST('fk_categorie_invoice',  'int');
        $postCategorieProposal = GETPOST('fk_categorie_proposal', 'int');
        $data['fk_categorie_order']    = $postCategorieOrder    > 0 ? (int) $postCategorieOrder    : null;
        $data['fk_categorie_invoice']  = $postCategorieInvoice  > 0 ? (int) $postCategorieInvoice  : null;
        $data['fk_categorie_proposal'] = $postCategorieProposal > 0 ? (int) $postCategorieProposal : null;
    }

    if (empty($data)) {
        setEventMessages($langs->trans("StoreUpdateError"), null, 'errors');
    } else {
        // Story 49-5 : capturer l'ancien label AVANT l'update (pour renommage catégories)
        $oldStore   = $storeService->fetch((int) $storeId);
        $oldLabel   = ($oldStore !== null) ? (string) $oldStore->label : '';

        $result = $storeService->update((int) $storeId, $data);
        if ($result >= 0) {
            setEventMessages($langs->trans("StoreUpdated"), null, 'mesgs');

            // Story 49-5 AC-3 : renommage automatique des catégories si le label a changé
            if ($updateSection === 'connection' && isset($data['label']) && $data['label'] !== $oldLabel && $oldStore !== null) {
                $nbRenamed = doli2shopRenameStoreCategories($db, $oldStore, $data['label']);
                if ($nbRenamed > 0) {
                    setEventMessages(sprintf($langs->trans("StoreCategoriesRenamed"), $nbRenamed), null, 'mesgs');
                }
            }

            // Revenir sur la fiche au bon sous-onglet ($updateSection est forcément l'une des 3 ici)
            header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $storeId.'&subtab='.$updateSection);
            exit;
        } else {
            setEventMessages($langs->trans("StoreUpdateError"), null, 'errors');
        }
    }
    $action = 'edit'; // rester sur la fiche en cas d'erreur
}

// Activer ou désactiver une boutique
if ($action == 'toggle_active' && !verifToken()) {
    dol_syslog("stores.php: action 'toggle_active' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'toggle_active' && verifToken()) {
    $store = $storeService->fetch((int) $storeId);
    if ($store !== null) {
        $newActive = ((int) $store->active === 1) ? 0 : 1;
        $result = $storeService->update((int) $storeId, array('active' => $newActive));
        if ($result >= 0) {
            $msg = $newActive ? 'StoreActivated' : 'StoreDeactivated';
            setEventMessages($langs->trans($msg), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("StoreUpdateError"), null, 'errors');
        }
    }
    $action = '';
}

// Supprimer une boutique — garde serveur : boutique par défaut interdite
if ($action == 'delete' && !verifToken()) {
    dol_syslog("stores.php: action 'delete' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'delete' && verifToken()) {
    $store = $storeService->fetch((int) $storeId);
    if ($store !== null && (int) $store->is_default === 1) {
        setEventMessages($langs->trans("CannotDeleteDefaultStore"), null, 'errors');
        $error++;
    } elseif ($store !== null) {
        $result = $storeService->delete((int) $storeId);
        if ($result > 0) {
            setEventMessages($langs->trans("StoreDeleted"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("StoreDeleteError"), null, 'errors');
        }
    }
    $action = '';
}

// Story 49-5 : Actualiser les infos Shopify à la demande (jamais au chargement — perf)
if ($action == 'refresh_shopify_info' && !empty($_POST) && !verifToken()) {
    dol_syslog("stores.php: action 'refresh_shopify_info' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'refresh_shopify_info' && !empty($_POST) && verifToken()) {
    $targetStore = $storeService->fetch((int) $storeId);
    if ($targetStore !== null) {
        try {
            $shopifyApi = ShopifyApi::forStore($db, $targetStore);
            $shopInfo   = $shopifyApi->getShopInfo();
            // Review 49-5 MEDIUM : getShopInfo() null (token révoqué/réseau) → échec explicite
            // (sinon message de succès trompeur + table vide).
            if (empty($shopInfo)) {
                throw new Exception($langs->trans("StoreShopifyInfoUnavailable"));
            }
            $connTest = $shopifyApi->testShopifyConnection();
            // Review 49-5 HIGH : scopes est un TABLEAU → implode (pas de (string) qui donne "Array").
            $scopes = (isset($connTest['scopes']) && is_array($connTest['scopes']))
                ? implode(', ', $connTest['scopes'])
                : (isset($connTest['scopes']) ? (string) $connTest['scopes'] : '');
            $_SESSION['doli2shop_shopify_info_'.(int) $storeId] = array(
                'name'     => isset($shopInfo['name'])     ? (string) $shopInfo['name']     : '',
                'plan'     => isset($shopInfo['plan'])     ? (string) $shopInfo['plan']     : '',
                'currency' => isset($shopInfo['currency']) ? (string) $shopInfo['currency'] : '',
                'scopes'   => $scopes,
                'fetched'  => date('Y-m-d H:i:s'),
            );
            setEventMessages($langs->trans("StoreShopifyInfo"), null, 'mesgs');
        } catch (Exception $e) {
            dol_syslog("Doli2Shop: refresh_shopify_info error store#".(int)$storeId.": ".$e->getMessage(), LOG_WARNING);
            // Non bloquant : on reste sur la page avec un avertissement
            setEventMessages($e->getMessage(), null, 'warnings');
        }
    }
    header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $storeId.'&subtab=connection');
    exit;
}

// Story 49-5 : Sauvegarde du mapping transporteurs par boutique
if ($action == 'save_shipping_mapping_store' && !empty($_POST) && !verifToken()) {
    dol_syslog("stores.php: action 'save_shipping_mapping_store' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'save_shipping_mapping_store' && !empty($_POST) && verifToken()) {
    $targetStoreId = GETPOST('store_id', 'int');
    $targetStore   = $storeService->fetch((int) $targetStoreId);
    if ($targetStore === null) {
        setEventMessages($langs->trans("StoreNotFound"), null, 'errors');
    } else {
        $db->begin();
        $shiperr = 0;

        // DELETE des règles existantes pour cette boutique uniquement
        if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."doli2shop_shipping_rules"
            ." WHERE entity = ".(int) $conf->entity
            ." AND fk_store = ".(int) $targetStore->rowid)) {
            $shiperr++;
        }

        // Title rules (à la commande)
        $titlePatterns = GETPOST('title_shopify_pattern', 'array');
        $titleMethods  = GETPOST('title_dol_shipping_method_id', 'array');
        $titleDays     = GETPOST('title_delivery_days', 'array');
        if (is_array($titlePatterns)) {
            foreach ($titlePatterns as $k => $pattern) {
                if (!empty($pattern) && !empty($titleMethods[$k])) {
                    $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_shipping_rules SET"
                        ." entity = ".(int) $conf->entity.","
                        ." fk_store = ".(int) $targetStore->rowid.","
                        ." rule_type = 'title',"
                        ." shopify_pattern = '".$db->escape($pattern)."',"
                        ." dol_shipping_method_id = ".(int) $titleMethods[$k].","
                        ." delivery_days = ".(int) $titleDays[$k].","
                        ." active = 1";
                    if (!$db->query($sqlIns)) {
                        $shiperr++;
                    }
                }
            }
        }

        // Tracking rules (au fulfillment)
        $trackingPatterns = GETPOST('tracking_shopify_pattern', 'array');
        $trackingMethods  = GETPOST('tracking_dol_shipping_method_id', 'array');
        $trackingDays     = GETPOST('tracking_delivery_days', 'array');
        if (is_array($trackingPatterns)) {
            foreach ($trackingPatterns as $k => $pattern) {
                if (!empty($pattern) && !empty($trackingMethods[$k])) {
                    $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_shipping_rules SET"
                        ." entity = ".(int) $conf->entity.","
                        ." fk_store = ".(int) $targetStore->rowid.","
                        ." rule_type = 'tracking',"
                        ." shopify_pattern = '".$db->escape($pattern)."',"
                        ." dol_shipping_method_id = ".(int) $trackingMethods[$k].","
                        ." delivery_days = ".(int) $trackingDays[$k].","
                        ." active = 1";
                    if (!$db->query($sqlIns)) {
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
            setEventMessages($langs->trans("StoreShippingMappingSaved"), null, 'mesgs');
        }
    }
    header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $storeId.'&subtab=shipping');
    exit;
}

// Enregistrer les webhooks pour une boutique spécifique
if ($action == 'register_webhooks' && !verifToken()) {
    dol_syslog("stores.php: action 'register_webhooks' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'register_webhooks' && verifToken()) {
    $store = $storeService->fetch((int) $storeId);
    if ($store === null) {
        setEventMessages($langs->trans("StoreNotFound"), null, 'errors');
        $error++;
    } else {
        // B3.3 : $shopifyWebhooks sans store = dispatcher uniquement. La vraie instance avec
        // credentials est créée dans syncWebhooksForStore() via ShopifyApi::forStore().
        $shopifyWebhooks = new ShopifyWebhooks($db);
        $result = $shopifyWebhooks->syncWebhooksForStore($store);
        if ($result) {
            setEventMessages($langs->trans("StoreWebhooksRegistered"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("StoreWebhooksRegisterError"), implode(', ', $shopifyWebhooks->errors), 'errors');
        }
    }
    // Review 48-2 M2 : si lancé depuis la fiche (onglet Webhooks), y revenir au lieu de la liste.
    if (GETPOST('subtab', 'aZ09') === 'webhooks' && $storeId > 0) {
        header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $storeId.'&subtab=webhooks');
        exit;
    }
    $action = '';
}

// Story 49-8 — Vérifier la licence d'une boutique (AC #1-#4)
// Seul chemin qui écrit réellement license_status en DB (les CRONs ne le font plus pour is_default).
if ($action == 'verify_license' && !empty($_POST) && !verifToken()) {
    dol_syslog("stores.php: action 'verify_license' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'verify_license' && !empty($_POST) && verifToken()) {
    if (!$user->admin) {
        accessforbidden();
    }
    $verifyStoreId = GETPOST('store_id', 'int');
    $verifyStore   = $storeService->fetch((int) $verifyStoreId);
    if ($verifyStore === null) {
        setEventMessages($langs->trans("StoreNotFound"), null, 'errors');
        $action = 'edit';
    } else {
        $mappedStatus = 'unknown';
        try {
            // Review 49-8 MEDIUM : purger le cache licence (TTL 5 min) AVANT la vérification
            // manuelle — « Vérifier maintenant » doit interroger le serveur, pas le cache.
            if ((int) $verifyStore->is_default === 1) {
                dolibarr_del_const($db, 'DOLI2SHOP_LICENSE_STATUS_EXPIRY_' . (int) $conf->entity, $conf->entity);
            } elseif (!empty($verifyStore->shop_domain)) {
                dolibarr_del_const($db, 'DOLI2SHOP_LICENSE_STATUS_EXPIRY_' . md5($verifyStore->shop_domain), $conf->entity);
            }

            // AC #3 : boutique par défaut → flux global (sans shopDomain)
            // AC #4 : boutique secondaire → flux par domaine
            if ((int) $verifyStore->is_default === 1) {
                $licResult = doli2shopGetLicenseStatus();
            } else {
                $licResult = doli2shopGetLicenseStatus($verifyStore->shop_domain);
            }
            // AC #1 : mapper le résultat → valid / invalid / unknown
            // Mapping : valid=true → 'valid' ; status ∈ {invalid,not_found,expired} → 'invalid' ;
            // tout autre cas (api_error, parse_error, etc.) → 'unknown'.
            // CORRECTION VALIDATE : erreur réseau / exception → TOUJOURS 'unknown' (jamais 'invalid').
            if (isset($licResult['valid']) && $licResult['valid'] === true) {
                $mappedStatus = 'valid';
            } elseif (isset($licResult['status']) && in_array($licResult['status'], array('invalid', 'not_found', 'expired'), true)) {
                $mappedStatus = 'invalid';
            } else {
                $mappedStatus = 'unknown';
            }
        } catch (\Throwable $t) {
            // Exception réseau ou inattendue → 'unknown' (JAMAIS 'invalid' — un souci réseau ne
            // doit pas faire apparaître une boutique licenciée comme invalide — CORRECTION VALIDATE).
            $mappedStatus = 'unknown';
            dol_syslog("Doli2Shop: verify_license store#" . (int) $verifyStoreId . " exception: " . $t->getMessage(), LOG_WARNING);
        }

        // AC #1 : persister via StoreService::setLicenseStatus() (met à jour license_status + license_checked=NOW())
        $storeService->setLicenseStatus((int) $verifyStoreId, $mappedStatus);

        // AC #2 : setEventMessages selon le résultat — succès ou warning si unknown
        if ($mappedStatus === 'valid') {
            setEventMessages(sprintf($langs->trans("StoreLicenseVerifySuccess"), $langs->trans("StoreLicenseStatusValid")), null, 'mesgs');
        } elseif ($mappedStatus === 'invalid') {
            setEventMessages(sprintf($langs->trans("StoreLicenseVerifySuccess"), $langs->trans("StoreLicenseStatusInvalid")), null, 'mesgs');
        } else {
            setEventMessages(sprintf($langs->trans("StoreLicenseVerifyError"), $langs->trans("StoreLicenseStatusUnknown")), null, 'warnings');
        }

        // AC #2 : redirect → badge rafraîchi immédiatement
        header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $verifyStoreId.'&subtab=license');
        exit;
    }
}

// Story 49-12 — Activer une licence DoliStore via numéro de série (AC #2, #3, #7)
if ($action == 'activate_store_serial' && !empty($_POST) && !verifToken()) {
    dol_syslog("stores.php: action 'activate_store_serial' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'activate_store_serial' && !empty($_POST) && verifToken()) {
    if (!$user->admin) {
        accessforbidden();
    }
    $targetStoreId = GETPOST('store_id', 'int');
    $rawSerial     = strtoupper(trim(GETPOST('serial_number', 'alphanohtml')));
    $targetStore   = $storeService->fetch((int) $targetStoreId);

    if ($targetStore === null) {
        setEventMessages($langs->transnoentities("StoreNotFound"), null, 'errors');
        header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $targetStoreId.'&subtab=license');
        exit;
    }

    // AC #3a : validation format SI-YYYY-XXXX-XXXX
    if (!preg_match('/^SI-\d{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $rawSerial)) {
        setEventMessages($langs->transnoentities("StoreSerialFormatInvalid"), null, 'errors');
        header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $targetStoreId.'&subtab=license');
        exit;
    }

    $shopDomainForSerial = $targetStore->shop_domain;

    // FIX 6 (CR 49-12 LOW) : boutique sans domaine → guard avant cURL (l'API renverrait un 400 cryptique)
    if (empty($shopDomainForSerial)) {
        setEventMessages($langs->transnoentities("StoreSerialNoDomain"), null, 'errors');
        header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $targetStoreId.'&subtab=license');
        exit;
    }

    // AC #3b : appel cURL POST billing.php?action=link-serial (timeout 15s, SSL vérifié)
    $linkSerialResult = null;
    $linkSerialHttpCode = 0;
    try {
        // Story 50-9 : télémétrie (domaine/version Dolibarr/version module/version PHP)
        // ajoutée via doli2shopBuildTelemetry() — jamais de sentinelle générique, clé omise
        // si non résolue.
        $linkSerialPayload = json_encode(array_merge(
            doli2shopBuildTelemetry(),
            array(
                'serial_number' => $rawSerial,
                'shop_domain'   => $shopDomainForSerial,
            )
        ));

        // FIX 7 (CR 49-12 LOW) : json_encode() peut retourner false (données non sérialisables)
        // → guard avant de passer false à CURLOPT_POSTFIELDS
        if ($linkSerialPayload === false) {
            dol_syslog('Doli2Shop: activate_store_serial - json_encode failed store#' . (int) $targetStoreId, LOG_ERR);
            setEventMessages($langs->transnoentities("StoreSerialActivateError"), null, 'errors');
            header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $targetStoreId.'&subtab=license');
            exit;
        }

        $chLink = curl_init();
        curl_setopt_array($chLink, array(
            // Review 3 couches 27/09 (finding HIGH n°1) : URL de production rendue surchargeable —
            // point de résolution unique, cf. doli2shopGetBillingApiEndpoint() (lib/doli2shop.lib.php).
            CURLOPT_URL            => doli2shopGetBillingApiEndpoint() . '?action=link-serial',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $linkSerialPayload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'User-Agent: Doli2Shop/' . DOLI2SHOP_MODULE_VERSION,
            ),
            CURLOPT_SSL_VERIFYPEER => true,
        ));

        $linkSerialResponse = curl_exec($chLink);
        $linkSerialHttpCode = (int) curl_getinfo($chLink, CURLINFO_HTTP_CODE);
        $linkSerialCurlError = curl_error($chLink);
        curl_close($chLink);

        if (!$linkSerialCurlError && $linkSerialHttpCode > 0) {
            $linkSerialResult = json_decode($linkSerialResponse, true);
        } else {
            // Erreur réseau → message générique (jamais le serial)
            dol_syslog('Doli2Shop: activate_store_serial - cURL error store#' . (int) $targetStoreId . ': ' . $linkSerialCurlError, LOG_WARNING);
        }
    } catch (\Throwable $eLinkSerial) {
        dol_syslog('Doli2Shop: activate_store_serial - exception store#' . (int) $targetStoreId . ': ' . $eLinkSerial->getMessage(), LOG_WARNING);
    }

    // AC #3c & #3d : traitement réponse
    if ($linkSerialHttpCode === 200 && isset($linkSerialResult['success']) && $linkSerialResult['success'] === true) {
        // Succès : enregistrer le serial + marquer licence valid
        $storeService->setSerialNumber((int) $targetStoreId, $rawSerial);
        $storeService->setLicenseStatus((int) $targetStoreId, 'valid');
        setEventMessages($langs->transnoentities("StoreSerialActivateSuccess"), null, 'mesgs');
    } else {
        // Erreur API : message retourné par l'API (sans le serial en clair)
        $apiErrorMsg = '';
        if (!empty($linkSerialResult['message'])) {
            $apiErrorMsg = $linkSerialResult['message'];
        } elseif (!empty($linkSerialResult['error'])) {
            $apiErrorMsg = $linkSerialResult['error'];
        } elseif ($linkSerialHttpCode > 0) {
            $apiErrorMsg = 'HTTP ' . $linkSerialHttpCode;
        }
        $displayMsg = $langs->transnoentities("StoreSerialActivateError");
        if (!empty($apiErrorMsg)) {
            $displayMsg .= ' : ' . dol_escape_htmltag($apiErrorMsg);
        }
        setEventMessages($displayMsg, null, 'errors');
    }

    // AC #3e : redirect vers sous-onglet license dans tous les cas
    header('Location: '.$_SERVER["PHP_SELF"].'?action=edit&store_id='.(int) $targetStoreId.'&subtab=license');
    exit;
}

/*
 * VIEW
 */

llxHeader('', $langs->trans("StoresTab"), '');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("StoresManagement"), $linkback, 'title_setup');

// NE PAS SUPPRIMER cet appel : effet de bord volontaire — il persiste en session la
// boutique active quand « Configurer → » arrive avec store_id en GET (contexte 49-1).
doli2shopGetCurrentAdminStore($db);

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'stores', $langs->trans("ShopifyIntegration"), -1, 'shopify_color@doli2shop');

// Pas de barre de contexte ici : cette page EST le point d'entrée de gestion des
// boutiques (la liste affiche déjà toutes les boutiques, « Configurer » place le contexte).

// ============================================================================
// Formulaire Ajouter / Modifier
// ============================================================================

// Édition d'une boutique : config NON-credentials uniquement.
// Story 48-6 (conformité) : domaine en lecture seule (défini par l'OAuth), aucun champ
// access_token / api_key / api_secret. Les credentials se (re)connectent via OAuth.
if ($action == 'edit' && $storeId > 0) {
    $editStore = $storeService->fetch((int) $storeId);
    if ($editStore === null) {
        setEventMessages($langs->trans("StoreNotFound"), null, 'errors');
    } else {
        // Story 48-2 : fiche boutique à SOUS-ONGLETS. Chaque onglet éditable poste son propre
        // formulaire (update_section) → pas d'écrasement croisé des autres champs.
        $subtab = GETPOST('subtab', 'aZ09');
        if (!in_array($subtab, array('connection', 'categories', 'shipping', 'webhooks', 'license'), true)) {
            $subtab = 'connection';
        }

        print '<h3>'.$langs->transnoentities("EditStore").' : '.htmlspecialchars($editStore->label, ENT_QUOTES, 'UTF-8').'</h3>';

        // Barre de sous-onglets de la fiche boutique
        $storeHead = array();
        $sh = 0;
        $subBase = dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=edit&store_id='.(int) $storeId.'&subtab=';
        foreach (array(
            'connection' => 'StoreTabConnection',
            'categories' => 'StoreTabCategories',
            'shipping'   => 'StoreTabShipping',
            'webhooks'   => 'StoreTabWebhooks',
            'license'    => 'StoreTabLicense',
        ) as $tabCode => $tabLabel) {
            $storeHead[$sh][0] = $subBase.$tabCode;
            $storeHead[$sh][1] = $langs->trans($tabLabel);
            $storeHead[$sh][2] = $tabCode;
            $sh++;
        }
        print dol_get_fiche_head($storeHead, $subtab, '', -1, '');

        // ---------------------------------------------------------------------
        // SOUS-ONGLET : CONNEXION
        // ---------------------------------------------------------------------
        if ($subtab === 'connection') {
            // Story 49-5 AC-1 : date de connexion OAuth (lecture seule, au-dessus du formulaire)
            print '<table class="border centpercent" style="margin-bottom:10px;">';
            print '<tr><td class="titlefield">'.$langs->trans("StoreConnectedAt").'</td><td>';
            if ((int) $editStore->is_default === 1) {
                $connectedAt = getDolGlobalString('DOLI2SHOP_OAUTH_CONNECTED_AT');
            } else {
                $connectedAt = getDolGlobalString('DOLI2SHOP_OAUTH_CONNECTED_AT_'.(int) $editStore->rowid);
            }
            if (!empty($connectedAt)) {
                print dol_print_date($db->jdate($connectedAt), 'dayhour');
            } else {
                print '<span class="opacitymedium">'.$langs->transnoentities("StoreOAuthNotConnected").'</span>';
            }
            print '</td></tr>';
            print '</table>';

            // Story 51-1 (T7) / Story 51-4 (FIX) : badges "reconnexion requise" et "migration
            // jeton requise" — remontés ICI (juste sous le statut de connexion, tout en haut de
            // l'onglet Connexion, visibles sans scroll) suite au constat 51-4 : l'emplacement
            // précédent (sous le formulaire) était un recoin peu visible pour la checklist
            // mainteneur. Condition normalisée via helper UNIQUE doli2shopStoreTokenMigrationRequired()
            // (lib/doli2shop.lib.php) — réutilisé par la liste des boutiques ci-dessous.
            // Purement informatifs : n'affectent jamais l'activation de la boutique (invariant
            // Epic 47 — la boutique par défaut n'est jamais bloquée automatiquement).
            if (!empty($editStore->token_reconnect_required)) {
                print '<div class="warning" style="margin:10px 0;">';
                print '&#9888; ' . sprintf($langs->trans("OAuthTokenReconnectRequired"), dol_escape_htmltag($editStore->shop_domain));
                print '</div>';
            } elseif (doli2shopStoreTokenMigrationRequired($editStore)) {
                print '<div class="info" style="margin:10px 0;">';
                print '<strong>' . $langs->trans("StoreTokenMigrationRequiredBadge") . '</strong> — ';
                print $langs->trans("StoreTokenMigrationRequiredHint");
                print '</div>';
            }

            // Story 49-5 AC-1 : infos Shopify (affichées uniquement si session renseignée après clic bouton)
            $shopifyInfoSession = isset($_SESSION['doli2shop_shopify_info_'.(int) $storeId])
                ? $_SESSION['doli2shop_shopify_info_'.(int) $storeId]
                : null;
            if ($shopifyInfoSession !== null) {
                print '<div style="margin-bottom:10px;">';
                print '<strong>'.$langs->transnoentities("StoreShopifyInfo").'</strong>';
                print '<table class="border centpercent" style="margin-top:4px;">';
                print '<tr><td class="titlefield">'.$langs->transnoentities("StoreShopifyName").'</td>';
                print '<td>'.htmlspecialchars($shopifyInfoSession['name'], ENT_QUOTES, 'UTF-8').'</td></tr>';
                print '<tr><td>'.$langs->transnoentities("StoreShopifyPlan").'</td>';
                print '<td>'.htmlspecialchars($shopifyInfoSession['plan'], ENT_QUOTES, 'UTF-8').'</td></tr>';
                print '<tr><td>'.$langs->transnoentities("StoreShopifyCurrency").'</td>';
                print '<td>'.htmlspecialchars($shopifyInfoSession['currency'], ENT_QUOTES, 'UTF-8').'</td></tr>';
                if (!empty($shopifyInfoSession['scopes'])) {
                    print '<tr><td>'.$langs->transnoentities("StoreShopifyScopes").'</td>';
                    print '<td><code style="font-size:11px;">'.htmlspecialchars($shopifyInfoSession['scopes'], ENT_QUOTES, 'UTF-8').'</code></td></tr>';
                }
                print '</table>';
                print '</div>';
            }

            // Bouton « Actualiser les infos Shopify » — POST à la demande (jamais au chargement)
            print '<div style="margin-bottom:10px;">';
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="refresh_shopify_info">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<button type="submit" class="button" style="font-size:12px;">&#8635; '.$langs->transnoentities("StoreShopifyInfoRefresh").'</button>';
            print '</form>';
            print '</div>';

            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="update">';
            print '<input type="hidden" name="update_section" value="connection">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<table class="border centpercent">';
            print '<tr><td class="titlefield">'.$langs->trans("StoreLabel").'</td>';
            print '<td><input type="text" name="label" class="flat minwidth200" required maxlength="255" value="'.htmlspecialchars($editStore->label, ENT_QUOTES, 'UTF-8').'"></td></tr>';
            // Domaine : LECTURE SEULE (défini par l'OAuth — jamais saisi à la main)
            print '<tr><td>'.$langs->trans("StoreDomain").'</td>';
            print '<td>'.htmlspecialchars($editStore->shop_domain, ENT_QUOTES, 'UTF-8');
            print ' <span class="opacitymedium">('.$langs->trans("StoreDomainFromOAuth").')</span></td></tr>';
            $checkedActive = ((int) $editStore->active === 1) ? ' checked' : '';
            print '<tr><td>'.$langs->trans("StoreActive").'</td>';
            print '<td><input type="checkbox" name="active" value="1"'.$checkedActive.'></td></tr>';
            print '</table>';
            print '<div class="center" style="margin-top:8px;">';
            print '<input type="submit" class="button" value="'.$langs->transnoentities("Save").'">';
            print '</div>';
            print '</form>';

            // Story 51-4 (FIX) : badges déplacés en haut de l'onglet (juste sous le statut de
            // connexion) — cf. bloc au-dessus du formulaire. Ne pas les redupliquer ici.

            // (Re)connexion OAuth — jamais de saisie manuelle (form POST, token hors URL — review 48-6 H1)
            print '<div class="center" style="margin:14px 0;">';
            print '<form method="POST" action="'.dol_buildpath('/doli2shop/admin/connect_shopify.php', 1).'" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="connect">';
            print '<input type="hidden" name="store_context" value="reconnect">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<button type="submit" class="button">&#128279; '.$langs->transnoentities("StoreReconnectOAuth").'</button>';
            print '</form>';
            print '</div>';
        }

        // ---------------------------------------------------------------------
        // SOUS-ONGLET : CATÉGORIES & TAGS
        // ---------------------------------------------------------------------
        elseif ($subtab === 'categories') {
            // Catégorie produit liée — éditable (tag parent, isolation produits ; many-to-many)
            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="update">';
            print '<input type="hidden" name="update_section" value="categories">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<table class="border centpercent">';
            print '<tr><td class="titlefield">'.$langs->trans("StoreLinkedProductCategory").'</td><td>';
            $formStore = new Form($db);
            print $formStore->select_all_categories(Categorie::TYPE_PRODUCT, (int) ($editStore->fk_categorie ?? 0), 'fk_categorie', 0, 0, 0);
            print '<br><span class="opacitymedium">'.$langs->trans("StoreProductCategoryHelp").'</span></td></tr>';
            print '</table>';
            print '<div class="center" style="margin-top:8px;">';
            print '<input type="submit" class="button" value="'.$langs->transnoentities("Save").'">';
            print '</div>';
            print '</form>';

            // Story 49-5 AC-2 : Tags documents (commande / facture / devis) — maintenant ÉDITABLES
            print '<br><strong>'.$langs->trans("StoreDocumentTags").'</strong>';
            print '<div class="opacitymedium" style="margin-bottom:6px;">'.$langs->trans("StoreDocumentTagsHelp").'</div>';
            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="update">';
            print '<input type="hidden" name="update_section" value="categories">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<input type="hidden" name="fk_categorie" value="'.(int) ($editStore->fk_categorie ?? 0).'">';
            print '<table class="noborder centpercent">';
            print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("StoreOrderCategory").'</td><td>';
            print $formStore->select_all_categories(Categorie::TYPE_ORDER, (int) ($editStore->fk_categorie_order ?? 0), 'fk_categorie_order', 0, 0, 0);
            print '</td></tr>';
            print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("StoreInvoiceCategory").'</td><td>';
            print $formStore->select_all_categories(Categorie::TYPE_INVOICE, (int) ($editStore->fk_categorie_invoice ?? 0), 'fk_categorie_invoice', 0, 0, 0);
            print '</td></tr>';
            // Hotfix 2.4.5 (review 3 couches, CRITICAL) : Categorie::TYPE_PROPOSAL n'a été
            // introduite dans le core Dolibarr qu'en octobre 2025, alors que le module se déclare
            // compatible à partir de Dolibarr 18. Un accès direct provoquait ici exactement le même
            // fatal « Undefined class constant » que celui corrigé côté StoreCategoryHelper — mais
            // sur un simple clic dans l'onglet « Catégories & tags » d'une boutique, donc un
            // HTTP 500 en pleine navigation admin. La ligne est simplement masquée quand la
            // constante n'existe pas : la catégorie devis n'est de toute façon pas créable.
            if (defined('Categorie::TYPE_PROPOSAL')) {
                print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("StoreProposalCategory").'</td><td>';
                print $formStore->select_all_categories(constant('Categorie::TYPE_PROPOSAL'), (int) ($editStore->fk_categorie_proposal ?? 0), 'fk_categorie_proposal', 0, 0, 0);
                print '</td></tr>';
            } else {
                print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("StoreProposalCategory").'</td><td>';
                print '<span class="opacitymedium">'.$langs->trans("StoreProposalCategoryUnsupported").'</span>';
                print '</td></tr>';
            }
            print '</table>';
            print '<div class="center" style="margin-top:8px;">';
            print '<input type="submit" class="button" value="'.$langs->transnoentities("Save").'">';
            print '</div>';
            print '</form>';
        }

        // ---------------------------------------------------------------------
        // SOUS-ONGLET : EXPÉDITION
        // ---------------------------------------------------------------------
        elseif ($subtab === 'shipping') {
            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="update">';
            print '<input type="hidden" name="update_section" value="shipping">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<table class="border centpercent">';
            print '<tr><td class="titlefield">'.$langs->trans("ShopifyLocationId").'</td>';
            print '<td><input type="text" name="location_id" class="flat minwidth200" maxlength="255" value="'.htmlspecialchars($editStore->location_id ?? '', ENT_QUOTES, 'UTF-8').'"></td></tr>';
            print '</table>';
            print '<div class="center" style="margin-top:8px;">';
            print '<input type="submit" class="button" value="'.$langs->transnoentities("Save").'">';
            print '</div>';
            print '</form>';

            // Story 49-5 AC-4 : mapping transporteurs scopé sur cette boutique
            print '<br><strong>'.$langs->transnoentities("StoreShippingMappingTitle").'</strong>';
            print '<br><br>';

            // Charger les méthodes d'expédition Dolibarr (même requête que setup.php)
            $shippingmethods = array();
            $sqlShipMethods = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."c_shipment_mode WHERE active = 1 ORDER BY label";
            $resShipMethods = $db->query($sqlShipMethods);
            if ($resShipMethods) {
                while ($objSm = $db->fetch_object($resShipMethods)) {
                    $shippingmethods[(int) $objSm->rowid] = $objSm->label;
                }
                $db->free($resShipMethods);
            }

            // Positionner $mappingStoreId pour que l'include filtre sur cette boutique
            $mappingStoreId = (int) $editStore->rowid;

            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="save_shipping_mapping_store">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            include dol_buildpath('/doli2shop/admin/_shipping_mapping_table.inc.php', 0);
            print '<div class="center" style="margin-top:8px;">';
            print '<input type="submit" class="button" value="'.$langs->transnoentities("Save").'">';
            print '</div>';
            print '</form>';
        }

        // ---------------------------------------------------------------------
        // SOUS-ONGLET : WEBHOOKS
        // ---------------------------------------------------------------------
        elseif ($subtab === 'webhooks') {
            $targetApiVersion = '';
            $whProbe = new ShopifyWebhooks($db);
            if (method_exists($whProbe, 'getApiVersion')) {
                $targetApiVersion = (string) $whProbe->getApiVersion();
            }

            // Bouton ré-enregistrement des webhooks de CETTE boutique
            print '<div class="tabsAction">';
            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=edit&store_id='.(int) $storeId.'&subtab=webhooks" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="register_webhooks">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<button type="submit" class="butAction">'.$langs->transnoentities("RegisterStoreWebhooks").'</button>';
            print '</form>';
            print '</div>';

            $sqlWh  = "SELECT topic, status, api_version FROM ".MAIN_DB_PREFIX."doli2shop_webhooks";
            $sqlWh .= " WHERE fk_store = ".((int) $storeId)." AND entity = ".((int) $conf->entity);
            $sqlWh .= " ORDER BY topic ASC";
            $resWh = $db->query($sqlWh);
            if ($resWh && $db->num_rows($resWh) > 0) {
                print '<table class="noborder centpercent">';
                print '<tr class="liste_titre"><th>'.$langs->trans("WebhookTopic").'</th><th>'.$langs->trans("Status").'</th><th>'.$langs->trans("WebhookApiVersion").'</th></tr>';
                while ($wh = $db->fetch_object($resWh)) {
                    print '<tr class="oddeven">';
                    print '<td>'.htmlspecialchars($wh->topic, ENT_QUOTES, 'UTF-8').'</td>';
                    if ((int) $wh->status === 1) {
                        print '<td><span class="badge badge-status4">'.$langs->trans("Active").'</span></td>';
                    } else {
                        print '<td><span class="badge badge-status8">'.$langs->trans("Inactive").'</span></td>';
                    }
                    $whVer = (string) ($wh->api_version ?? '');
                    $outdated = ($targetApiVersion !== '' && $whVer !== '' && $whVer !== $targetApiVersion);
                    print '<td>';
                    if ($whVer === '') {
                        print '<span class="opacitymedium">-</span>';
                    } elseif ($outdated) {
                        print '<span style="color:#f0ad4e;font-weight:bold;" title="'.htmlspecialchars($langs->transnoentities("StoreWebhookOutdatedApi", $targetApiVersion), ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($whVer, ENT_QUOTES, 'UTF-8').' &#9888;</span>';
                    } else {
                        print htmlspecialchars($whVer, ENT_QUOTES, 'UTF-8');
                    }
                    print '</td></tr>';
                }
                print '</table>';
            } else {
                print '<div class="opacitymedium">'.$langs->trans("StoreNoWebhooks").'</div>';
            }
            if ($resWh) {
                $db->free($resWh);
            }

            // Story 49-8 B2 : encart informatif conditionnel Partner Dashboard.
            // Affiché uniquement si au moins un webhook a api_version != $targetApiVersion.
            // Explique que la version de livraison est dictée par la version d'app Shopify Partner Dashboard,
            // et que le ré-enregistrement ne peut pas la modifier.
            if ($targetApiVersion !== '') {
                $sqlWhMismatch  = "SELECT COUNT(*) AS cnt FROM ".MAIN_DB_PREFIX."doli2shop_webhooks";
                $sqlWhMismatch .= " WHERE fk_store = ".((int) $storeId)." AND entity = ".((int) $conf->entity);
                $sqlWhMismatch .= " AND api_version != '' AND api_version != '".$db->escape($targetApiVersion)."'";
                $resWhMismatch = $db->query($sqlWhMismatch);
                $mismatchCount = 0;
                if ($resWhMismatch) {
                    $objMismatch = $db->fetch_object($resWhMismatch);
                    $mismatchCount = (int) ($objMismatch->cnt ?? 0);
                    $db->free($resWhMismatch);
                }
                if ($mismatchCount > 0) {
                    print '<div class="info" style="margin-top:12px;">';
                    print htmlspecialchars($langs->transnoentities("StoreWebhookApiVersionHint", $targetApiVersion), ENT_QUOTES, 'UTF-8');
                    print ' <a href="https://partners.shopify.com/" target="_blank" rel="noopener noreferrer">partners.shopify.com</a>';
                    print '</div>';
                }
            }
        }

        // ---------------------------------------------------------------------
        // SOUS-ONGLET : LICENCE
        // ---------------------------------------------------------------------
        elseif ($subtab === 'license') {
            // Story 49-8 A2 : bouton « Vérifier maintenant » — POST protégé par token CSRF.
            // Toujours affichable : boutique par défaut → flux global ; secondaire → flux par domaine.
            print '<div class="tabsAction">';
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="verify_license">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<button type="submit" class="butAction">'.$langs->transnoentities("StoreLicenseVerifyNow").'</button>';
            print '</form>';
            print '</div>';

            print '<table class="border centpercent">';
            print '<tr><td class="titlefield">'.$langs->trans("StoreLicenseStatus").'</td><td>';
            $licenseStatus = isset($editStore->license_status) ? $editStore->license_status : 'unknown';
            switch ($licenseStatus) {
                case 'valid':
                    print '<span class="badge badge-status4">'.$langs->trans("StoreLicenseStatusValid").'</span>';
                    break;
                case 'invalid':
                    print '<span class="badge badge-status8">'.$langs->trans("StoreLicenseStatusInvalid").'</span>';
                    break;
                default:
                    print '<span class="badge badge-status0">'.$langs->trans("StoreLicenseStatusUnknown").'</span>';
                    break;
            }
            print '</td></tr>';
            print '<tr><td>'.$langs->trans("StoreLicenseLastCheck").'</td><td>';
            print !empty($editStore->license_checked) ? dol_print_date($db->jdate($editStore->license_checked), 'dayhour') : '<span class="opacitymedium">-</span>';
            print '</td></tr>';
            if ((int) $editStore->is_default === 1) {
                print '<tr><td>'.$langs->trans("StoreDefault").'</td><td><span class="badge badge-status1">'.$langs->trans("Default").'</span> <span class="opacitymedium">'.$langs->trans("StoreDefaultNeverBlocked").'</span></td></tr>';
            }
            print '</table>';

            // Story 49-12 — Formulaire activation numéro de série DoliStore (AC #2)
            // Visible même si une licence est déjà active (pour mise à jour).
            print '<br>';
            print '<strong>' . $langs->transnoentities("StoreSerialActivateTitle") . '</strong>';
            print '<br>';

            // Hint serial tronqué (CORRECTION VALIDATE P5 : SI-{year}-****-{last4})
            if (!empty($editStore->serial_number)) {
                // Format : SI-YYYY-XXXX-XXXX → SI-YYYY-****-XXXX
                $snParts = explode('-', $editStore->serial_number);
                if (count($snParts) === 4) {
                    $snTruncated = $snParts[0] . '-' . $snParts[1] . '-****-' . $snParts[3];
                } else {
                    $snTruncated = '****';
                }
                print '<p class="opacitymedium">'
                    . $langs->transnoentities("StoreSerialCurrentHint")
                    . ' : ' . dol_escape_htmltag($snTruncated)
                    . '</p>';
            }

            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="activate_store_serial">';
            print '<input type="hidden" name="store_id" value="'.(int) $storeId.'">';
            print '<input type="text" name="serial_number"'
                . ' placeholder="SI-YYYY-XXXX-XXXX"'
                . ' pattern="SI-[0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"'
                . ' style="text-transform:uppercase;"'
                . ' class="flat minwidth250"'
                . ' maxlength="50">'; // FIX 5 (CR 49-12 LOW) : aligné sur VARCHAR(50)
            print ' <button type="submit" class="butAction">'
                . $langs->transnoentities("StoreSerialActivateButton")
                . '</button>';
            print '</form>';
            print '<p class="opacitymedium">' . $langs->transnoentities("StoreSerialLinkOnDoliStore") . '</p>';
        }

        print dol_get_fiche_end();

        print '<div style="margin-top:10px;"><a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">'.$langs->trans("BackToList").'</a></div>';
        print '<br>';
    }
}

// ============================================================================
// Liste des boutiques
// ============================================================================

$stores = $storeService->getAll();

print '<h3>'.$langs->trans("StoresList").'</h3>';

// Bouton Ajouter une boutique
print '<div class="tabsAction">';
// Story 48-6 : ajouter une boutique = lancer l'OAuth (les credentials viennent de Shopify, jamais saisis).
// H1 (review 48-6) : form POST → token CSRF hors URL + résistant à la rotation.
print '<form method="POST" action="'.dol_buildpath('/doli2shop/admin/connect_shopify.php', 1).'" style="display:inline;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="connect">';
print '<input type="hidden" name="store_context" value="new">';
print '<button type="submit" class="butAction">&#128279; '.$langs->transnoentities("StoreConnectNewOAuth").'</button>';
print '</form>';
print '</div>';

if (empty($stores)) {
    print '<div class="opacitymedium">'.$langs->trans("NoStoreConfigured").'</div>';
} else {
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans("StoreLabel").'</th>';
    print '<th>'.$langs->trans("StoreDomain").'</th>';
    print '<th>'.$langs->trans("StoreActive").'</th>';
    print '<th>'.$langs->trans("StoreDefault").'</th>';
    print '<th>'.$langs->trans("StoreLicenseStatus").'</th>';
    print '<th class="right">'.$langs->trans("Actions").'</th>';
    print '</tr>';

    foreach ($stores as $store) {
        $trClass = ((int) $store->active === 1) ? 'oddeven' : 'oddeven opacitylow';
        print '<tr class="'.$trClass.'">';

        // Label
        print '<td>'.htmlspecialchars($store->label, ENT_QUOTES, 'UTF-8').'</td>';

        // shop_domain
        print '<td>'.htmlspecialchars($store->shop_domain, ENT_QUOTES, 'UTF-8').'</td>';

        // Active badge
        if ((int) $store->active === 1) {
            print '<td><span class="badge badge-status4">'.$langs->trans("Active").'</span></td>';
        } else {
            print '<td><span class="badge badge-status8">'.$langs->trans("Inactive").'</span></td>';
        }

        // Défaut badge
        if ((int) $store->is_default === 1) {
            print '<td><span class="badge badge-status1">'.$langs->trans("Default").'</span></td>';
        } else {
            print '<td></td>';
        }

        // Licence badge
        $licenseStatus = isset($store->license_status) ? $store->license_status : 'unknown';
        print '<td>';
        switch ($licenseStatus) {
            case 'valid':
                print '<span class="badge badge-status4">'.$langs->trans("StoreLicenseStatusValid").'</span>';
                break;
            case 'invalid':
                print '<span class="badge badge-status8">'.$langs->trans("StoreLicenseStatusInvalid").'</span>';
                break;
            default:
                print '<span class="badge badge-status0">'.$langs->trans("StoreLicenseStatusUnknown").'</span>';
                break;
        }
        // Story 51-4 (FIX) : badge "migration jeton requise" absent de la liste — cause racine du
        // bug (le badge n'existait QUE sur la fiche). Même helper normalisé que la fiche
        // (doli2shopStoreTokenMigrationRequired(), lib/doli2shop.lib.php) — cohérent avec le style
        // d'icône warning déjà utilisé pour les webhooks obsolètes (onglet Webhooks de la fiche).
        if (doli2shopStoreTokenMigrationRequired($store)) {
            print '<br><span style="color:#f0ad4e;font-weight:bold;white-space:nowrap;" title="'
                .htmlspecialchars($langs->transnoentities("StoreTokenMigrationRequiredHint"), ENT_QUOTES, 'UTF-8').'">'
                .'&#9888; '.$langs->trans("StoreTokenMigrationRequiredBadge").'</span>';
            // Story 51-4 (retour mainteneur) : l'ACTION de migration doit être visible pour les
            // clients directement depuis la liste — même form POST éprouvé que la fiche (48-6 H1 :
            // jamais de token en URL). Le re-OAuth via le proxy à jour délivre un jeton expirable.
            print '<br><form method="POST" action="'.dol_buildpath('/doli2shop/admin/connect_shopify.php', 1).'" style="display:inline;">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="connect">';
            print '<input type="hidden" name="store_context" value="reconnect">';
            print '<input type="hidden" name="store_id" value="'.(int) $store->rowid.'">';
            print '<button type="submit" class="buttonpayment button-small" style="font-size:0.85em;padding:2px 8px;">&#128279; '.$langs->transnoentities("StoreReconnectOAuth").'</button>';
            print '</form>';
        }
        print '</td>';

        // Actions
        print '<td class="right nowrap">';

        // Configurer → : lien GET pur vers setup.php avec store_id (session posée par doli2shopGetCurrentAdminStore au chargement)
        $configureUrl = dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=settings&store_id=' . (int) $store->rowid;
        print '<a class="butActionSmall" href="' . $configureUrl . '">';
        print $langs->trans("ConfigureStore");
        print '</a> ';

        // Modifier
        print '<a class="butActionSmall" href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=edit&store_id='.(int) $store->rowid.'">';
        print $langs->trans("EditStore");
        print '</a> ';

        // Activer / Désactiver
        // transnoentities() et non trans() : trans() renvoie deja les entites HTML
        // (« D&eacute;sactiver »), que le htmlspecialchars() ci-dessous re-encodait —
        // le bouton affichait alors l'entite en toutes lettres dans chaque langue accentuee.
        $toggleLabel = ((int) $store->active === 1) ? $langs->transnoentities("Deactivate") : $langs->transnoentities("Activate");
        print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="toggle_active">';
        print '<input type="hidden" name="store_id" value="'.(int) $store->rowid.'">';
        print '<button type="submit" class="butActionSmall">'.htmlspecialchars($toggleLabel, ENT_QUOTES, 'UTF-8').'</button>';
        print '</form> ';

        // Enregistrer les webhooks
        print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" style="display:inline;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="register_webhooks">';
        print '<input type="hidden" name="store_id" value="'.(int) $store->rowid.'">';
        print '<button type="submit" class="butActionSmall">'.$langs->transnoentities("RegisterStoreWebhooks").'</button>';
        print '</form> ';

        // Supprimer — boutique par défaut interdite
        if ((int) $store->is_default !== 1) {
            print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" style="display:inline;" onsubmit="return confirm(\''.dol_escape_js($langs->trans("ConfirmDeleteStore")).'\');">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="delete">';
            print '<input type="hidden" name="store_id" value="'.(int) $store->rowid.'">';
            print '<button type="submit" class="butActionSmallDelete">'.$langs->transnoentities("DeleteStore").'</button>';
            print '</form>';
        } else {
            // Bouton de suppression désactivé pour la boutique par défaut
            print '<button type="button" class="butActionSmallDelete" disabled title="'.$langs->transnoentities("CannotDeleteDefaultStore").'">';
            print $langs->transnoentities("DeleteStore");
            print '</button>';
        }

        print '</td>';
        print '</tr>';
    }

    print '</table>';
}

print dol_get_fiche_end();
print '</div>';

llxFooter();
$db->close();
