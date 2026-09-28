<?php
/**
 * @file        admin/syncoptions.php
 * @brief       Sync Options administration page for ShopifyIntegration module
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
 * @since       2.0.0
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
require_once '../lib/doli2shop.lib.php';
require_once '../class/shopifyapi.class.php';
require_once '../class/LoggerTrait.php';
// SyncFlowPolicy::DEFAULT_STOCKS_DIRECTION : source unique du défaut du sens du stock
// (story sens-du-stock-par-defaut-dolibarr-vers-shopify).
require_once '../class/syncflowpolicy.class.php';

// Classe pour la gestion des logs
class SyncOptionsManager
{
    use LoggerTrait;
}

// Load translation files required by the page
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$error = 0;

// Initialize objects
$shopifyApi = new ShopifyApi($db);
$setupManager = new SyncOptionsManager();

/*
 * Actions
 */

if ($action == 'update' && !empty($_POST)) {
    // Verify CSRF token
    if (!verifToken()) {
        setEventMessages($langs->trans("InvalidToken"), null, 'errors');
        $action = '';
    } else {
        $setupManager->log("Démarrage mise à jour des options de synchronisation", LOG_INFO);

    try {
        // Récupérer les valeurs du formulaire
        $sync_products_direction = GETPOST('sync_products_direction', 'alphanohtml');
        $sync_orders_direction = GETPOST('sync_orders_direction', 'alphanohtml');
        $sync_payments_direction = GETPOST('sync_payments_direction', 'alphanohtml');
        $sync_shipping_direction = GETPOST('sync_shipping_direction', 'alphanohtml');
        $sync_stocks_direction = GETPOST('sync_stocks_direction', 'alphanohtml');
        $conflict_resolution_strategy = GETPOST('conflict_resolution_strategy', 'alphanohtml');

        // Valider les valeurs
        $valid_directions = array('none', 'shopify_to_dolibarr', 'dolibarr_to_shopify', 'both');
        $valid_strategies = array('shopify_wins', 'dolibarr_wins', 'newest_wins', 'oldest_wins', 'manual_resolution');

        if (!in_array($sync_products_direction, $valid_directions)) {
            throw new Exception($langs->trans("InvalidValue").': '.$langs->trans("SyncProductsDirection"));
        }

        if (!in_array($sync_orders_direction, $valid_directions)) {
            throw new Exception($langs->trans("InvalidValue").': '.$langs->trans("SyncOrdersDirection"));
        }

        if (!in_array($sync_payments_direction, $valid_directions)) {
            throw new Exception($langs->trans("InvalidValue").': '.$langs->trans("SyncPaymentsDirection"));
        }

        if (!in_array($sync_shipping_direction, $valid_directions)) {
            throw new Exception($langs->trans("InvalidValue").': '.$langs->trans("SyncShippingDirection"));
        }

        if (!in_array($sync_stocks_direction, $valid_directions)) {
            throw new Exception($langs->trans("InvalidValue").': '.$langs->trans("SyncStocksDirection"));
        }

        if (!in_array($conflict_resolution_strategy, $valid_strategies)) {
            throw new Exception($langs->trans("InvalidValue").': '.$langs->trans("ConflictResolutionStrategy"));
        }

        // Sauvegarder dans les constantes Dolibarr (table llx_const)
        // Remplace l'ancienne table llx_doli2shop_storedetails supprimée en v2.1.1
        // ⚠️ LIMITE CONNUE (story sens-du-stock-par-defaut-dolibarr-vers-shopify, AC2) : ce
        // formulaire n'a qu'un bouton Enregistrer qui écrit les SIX constantes en bloc, y compris
        // 'both' pour le stock si c'est la valeur affichée à l'écran au moment de la sauvegarde. Un
        // client qui valide cet écran pour changer autre chose (commandes, stratégie de conflit)
        // se retrouve donc avec une ligne DOLI2SHOP_SYNC_STOCKS_DIRECTION="both" EXPLICITE en
        // base, indistinguable d'un choix délibéré sur le stock. Le nouveau défaut
        // (SyncFlowPolicy::DEFAULT_STOCKS_DIRECTION) ne protège donc QUE les installations n'ayant
        // jamais touché cet écran — pas une garantie fiable pour toutes les installations "both".
        $result = 0;
        $result += dolibarr_set_const($db, 'DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', $sync_products_direction, 'chaine', 0, '', $conf->entity);
        $result += dolibarr_set_const($db, 'DOLI2SHOP_SYNC_ORDERS_DIRECTION', $sync_orders_direction, 'chaine', 0, '', $conf->entity);
        $result += dolibarr_set_const($db, 'DOLI2SHOP_SYNC_PAYMENTS_DIRECTION', $sync_payments_direction, 'chaine', 0, '', $conf->entity);
        $result += dolibarr_set_const($db, 'DOLI2SHOP_SYNC_SHIPPING_DIRECTION', $sync_shipping_direction, 'chaine', 0, '', $conf->entity);
        $result += dolibarr_set_const($db, 'DOLI2SHOP_SYNC_STOCKS_DIRECTION', $sync_stocks_direction, 'chaine', 0, '', $conf->entity);
        $result += dolibarr_set_const($db, 'DOLI2SHOP_CONFLICT_RESOLUTION_STRATEGY', $conflict_resolution_strategy, 'chaine', 0, '', $conf->entity);

        if ($result < 0) {
            throw new Exception("Erreur lors de la sauvegarde des constantes");
        }

        $setupManager->log("Options de synchronisation mises à jour avec succès", LOG_INFO);

        // Activer/désactiver les CRONs produits selon la direction choisie
        if ($sync_products_direction) {
            require_once DOL_DOCUMENT_ROOT . '/cron/class/cronjob.class.php';

            // Déterminer quels CRONs doivent être actifs
            // Si "none" sélectionné, désactiver tous les CRONs produits
            if ($sync_products_direction == 'none') {
                $activateExportCron = false;
                $activateImportCron = false;
            } else {
                $activateExportCron = in_array($sync_products_direction, ['dolibarr_to_shopify', 'both']);
                $activateImportCron = in_array($sync_products_direction, ['shopify_to_dolibarr', 'both']);
            }

            $setupManager->log("Mise à jour CRONs - Export: " . ($activateExportCron ? 'actif' : 'inactif') . ", Import: " . ($activateImportCron ? 'actif' : 'inactif'), LOG_DEBUG);

            $cronExportUpdated = false;
            $cronImportUpdated = false;

            // CRON Export (ImportProductsCron - Dolibarr → Shopify)
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cronjob";
            $sql .= " WHERE module_name = 'doli2shop'";
            $sql .= " AND classesname LIKE '%importproductscron%'";
            $sql .= " AND entity = " . (int)$conf->entity;

            $resExport = $db->query($sql);
            if ($resExport === false) {
                $setupManager->log("Erreur SQL recherche CRON export: " . $db->lasterror(), LOG_ERR);
            } elseif ($db->num_rows($resExport) > 0) {
                $obj = $db->fetch_object($resExport);
                $cronjob = new Cronjob($db);
                if ($cronjob->fetch($obj->rowid) > 0) {
                    $cronjob->status = $activateExportCron ? 1 : 0;
                    $updateResult = $cronjob->update($user);
                    if ($updateResult > 0) {
                        $cronExportUpdated = true;
                        $setupManager->log("CRON Export mis à jour (status=" . $cronjob->status . ")", LOG_DEBUG);
                    } else {
                        $setupManager->log("Erreur mise à jour CRON Export: " . implode(', ', $cronjob->errors), LOG_WARNING);
                    }
                }
            } else {
                $setupManager->log("CRON Export non trouvé dans la base", LOG_WARNING);
            }

            // CRON Import (ShopifyProductImportCron - Shopify → Dolibarr)
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cronjob";
            $sql .= " WHERE module_name = 'doli2shop'";
            $sql .= " AND classesname LIKE '%shopifyproductimportcron%'";
            $sql .= " AND entity = " . (int)$conf->entity;

            $resImport = $db->query($sql);
            if ($resImport === false) {
                $setupManager->log("Erreur SQL recherche CRON import: " . $db->lasterror(), LOG_ERR);
            } elseif ($db->num_rows($resImport) > 0) {
                $obj = $db->fetch_object($resImport);
                $cronjob = new Cronjob($db);
                if ($cronjob->fetch($obj->rowid) > 0) {
                    $cronjob->status = $activateImportCron ? 1 : 0;
                    $updateResult = $cronjob->update($user);
                    if ($updateResult > 0) {
                        $cronImportUpdated = true;
                        $setupManager->log("CRON Import mis à jour (status=" . $cronjob->status . ")", LOG_DEBUG);
                    } else {
                        $setupManager->log("Erreur mise à jour CRON Import: " . implode(', ', $cronjob->errors), LOG_WARNING);
                    }
                }
            } else {
                $setupManager->log("CRON Import non trouvé dans la base", LOG_WARNING);
            }

            // Message de confirmation CRONs
            if ($cronExportUpdated || $cronImportUpdated) {
                if ($sync_products_direction == 'none') {
                    setEventMessages($langs->trans("CronSyncDisabled"), null, 'warnings');
                } elseif ($activateExportCron && $activateImportCron) {
                    setEventMessages($langs->trans("CronBothDirectionsActivated"), null, 'mesgs');
                } elseif ($activateExportCron) {
                    setEventMessages($langs->trans("CronExportOnlyActivated"), null, 'mesgs');
                } elseif ($activateImportCron) {
                    setEventMessages($langs->trans("CronImportOnlyActivated"), null, 'mesgs');
                }
            }
        }

        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    } catch (Exception $e) {
        $setupManager->log("Erreur lors de la mise à jour des options de synchronisation: " . $e->getMessage(), LOG_ERR);
        setEventMessages($e->getMessage(), null, 'errors');
        $error++;
    }
    } // End CSRF verification else block
}

/*
 * View
 */

llxHeader('', $langs->trans("SyncDirectionConfiguration"));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Subheader

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class="doli2shop-page">';
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans("SyncDirectionConfiguration"), $linkback, 'title_setup');

// Configuration header
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'syncoptions', $langs->trans("ShopifyIntegration"), -1, 'shopify_color@doli2shop');
doli2shopRenderAdminTopBar($db);

// Lecture des valeurs actuelles depuis les constantes Dolibarr
$sync_products_direction = getDolGlobalString('DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', 'both');
$sync_orders_direction = getDolGlobalString('DOLI2SHOP_SYNC_ORDERS_DIRECTION', 'shopify_to_dolibarr');
$sync_payments_direction = getDolGlobalString('DOLI2SHOP_SYNC_PAYMENTS_DIRECTION', 'shopify_to_dolibarr');
$sync_shipping_direction = getDolGlobalString('DOLI2SHOP_SYNC_SHIPPING_DIRECTION', 'both');
$sync_stocks_direction = getDolGlobalString('DOLI2SHOP_SYNC_STOCKS_DIRECTION', SyncFlowPolicy::DEFAULT_STOCKS_DIRECTION);
$conflict_resolution_strategy = getDolGlobalString('DOLI2SHOP_CONFLICT_RESOLUTION_STRATEGY', 'newest_wins');

// AC3 (story sens-du-stock-par-defaut-dolibarr-vers-shopify) : l'installation est-elle
// RÉELLEMENT concernée par le changement de défaut ? getDolGlobalString() sans défaut renvoie
// '' UNIQUEMENT quand aucune ligne llx_const n'existe pour cette clé (jamais pour une valeur
// explicite, y compris 'both' — cf. StoreSettings::get(), même sentinelle). Seule une
// installation sans ligne du tout voit son comportement changer ; une ligne explicite ('both',
// 'shopify_to_dolibarr' ou 'dolibarr_to_shopify') n'est, elle, pas affectée par le nouveau défaut
// et n'a pas besoin de la mention ci-dessous.
$stocks_direction_was_never_set = (getDolGlobalString('DOLI2SHOP_SYNC_STOCKS_DIRECTION') === '');

// Check if Shopify API is configured
// FIX (story ecran-directions-de-synchronisation-mort-et-casse, AC1) : le garde testait
// DOLI2SHOP_SHOPIFY_STORE_HOSTNAME / DOLI2SHOP_SHOPIFY_ACCESS_TOKEN, deux constantes fantômes qui
// n'existent nulle part ailleurs dans le module — le formulaire affichait donc invariablement
// "Configuration Shopify requise". Vraies constantes (modèle : admin/setup.php:150), 21 usages.
$shopify_configured = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME') && getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN');

if (!$shopify_configured) {
    print '<div class="warning">'.$langs->trans("ShopifyConfigurationRequired").'</div>';
} else {
    print '<div class="opacitymedium">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("SyncDirectionExplanation");
    print '</div><br>';

    // Point capital (story ecran-directions-de-synchronisation-mort-et-casse) : réparer l'écran
    // sans le dire donnerait un réglage commandes/paiements/expéditions "Dolibarr → Shopify" qui ne
    // fait rien — OrderSyncToShopify::isOutboundOrderApiAvailable() (class/ordersynctoshopify.class.php)
    // renvoie toujours false (createOrder/updateOrder/markOrderAsPaid/createFulfillment n'existent
    // pas sur ShopifyApi), cf. docs/planning-artifacts/analyse-synchronisation-evenementielle-webhooks-vs-crons.md:16,59.
    // Le sens du STOCK n'est PAS concerné : il est sain dans les deux sens (même analyse, §2).
    print '<div class="warning">';
    print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("OutboundOrderPaymentShippingNotImplementedWarning");
    print '</div><br>';

    // AC3 (story sens-du-stock-par-defaut-dolibarr-vers-shopify, décision du mainteneur du
    // 12/09/2026) : le défaut du sens du STOCK a changé en v2.5.4 ('both' → 'dolibarr_to_shopify').
    // Mention réservée aux installations réellement concernées (aucune ligne llx_const du tout,
    // cf. $stocks_direction_was_never_set ci-dessus) — ne pas l'afficher à un client qui a déjà
    // un réglage explicite ('both' inclus : son comportement ne change pas, la ligne existe et
    // continue d'être respectée telle quelle par AC2).
    if ($stocks_direction_was_never_set) {
        print '<div class="info">';
        print '<i class="fa fa-info-circle"></i> ' . $langs->trans("StocksDirectionDefaultChangedNotice");
        print '</div><br>';
    }

    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update">';

    // Tableau des options de synchronisation
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans("SynchronizationType").'</th>';
    print '<th>'.$langs->trans("Direction").'</th>';
    print '<th>'.$langs->trans("Description").'</th>';
    print '</tr>';

    // Direction synchronisation produits
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("SyncProductsDirection").'</td>';
    print '<td>';
    print '<select name="sync_products_direction" class="flat">';
    print '<option value="none"'.($sync_products_direction == 'none' ? ' selected' : '').'>'.$langs->trans("NoSynchronization").'</option>';
    print '<option value="shopify_to_dolibarr"'.($sync_products_direction == 'shopify_to_dolibarr' ? ' selected' : '').'>'.$langs->trans("ShopifyToDolibarr").'</option>';
    print '<option value="dolibarr_to_shopify"'.($sync_products_direction == 'dolibarr_to_shopify' ? ' selected' : '').'>'.$langs->trans("DolibarrToShopify").'</option>';
    print '<option value="both"'.($sync_products_direction == 'both' ? ' selected' : '').'>'.$langs->trans("BothDirections").'</option>';
    print '</select>';
    print '</td>';
    print '<td>';
    print '<span id="products_direction_help_none" class="opacitymedium'.($sync_products_direction != 'none' ? ' hideobject' : '').'">'.$langs->trans("NoSynchronizationHelp").'</span>';
    print '<span id="products_direction_help_shopify_to_dolibarr" class="opacitymedium'.($sync_products_direction != 'shopify_to_dolibarr' ? ' hideobject' : '').'">'.$langs->trans("ShopifyToDolibarrHelp").'</span>';
    print '<span id="products_direction_help_dolibarr_to_shopify" class="opacitymedium'.($sync_products_direction != 'dolibarr_to_shopify' ? ' hideobject' : '').'">'.$langs->trans("DolibarrToShopifyHelp").'</span>';
    print '<span id="products_direction_help_both" class="opacitymedium'.($sync_products_direction != 'both' ? ' hideobject' : '').'">'.$langs->trans("BothDirectionsHelp").'</span>';
    print '</td>';
    print '</tr>';

    // Direction synchronisation commandes
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("SyncOrdersDirection").'</td>';
    print '<td>';
    print '<select name="sync_orders_direction" class="flat">';
    print '<option value="shopify_to_dolibarr"'.($sync_orders_direction == 'shopify_to_dolibarr' ? ' selected' : '').'>'.$langs->trans("ShopifyToDolibarr").'</option>';
    print '<option value="dolibarr_to_shopify"'.($sync_orders_direction == 'dolibarr_to_shopify' ? ' selected' : '').'>'.$langs->trans("DolibarrToShopify").'</option>';
    print '<option value="both"'.($sync_orders_direction == 'both' ? ' selected' : '').'>'.$langs->trans("BothDirections").'</option>';
    print '</select>';
    print '</td>';
    print '<td>';
    print '<span id="orders_direction_help_shopify_to_dolibarr" class="opacitymedium'.($sync_orders_direction != 'shopify_to_dolibarr' ? ' hideobject' : '').'">'.$langs->trans("ShopifyToDolibarrHelp").'</span>';
    // Sortant commandes = no-op (voir avertissement en tête d'écran) : help text distinct de
    // DolibarrToShopifyHelp/BothDirectionsHelp partagé par products/stocks (qui, eux, fonctionnent).
    print '<span id="orders_direction_help_dolibarr_to_shopify" class="opacitymedium'.($sync_orders_direction != 'dolibarr_to_shopify' ? ' hideobject' : '').'">'.$langs->trans("OutboundNotImplementedHelp").'</span>';
    print '<span id="orders_direction_help_both" class="opacitymedium'.($sync_orders_direction != 'both' ? ' hideobject' : '').'">'.$langs->trans("OutboundNotImplementedBothHelp").'</span>';
    print '</td>';
    print '</tr>';

    // Direction synchronisation paiements
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("SyncPaymentsDirection").'</td>';
    print '<td>';
    print '<select name="sync_payments_direction" class="flat">';
    print '<option value="shopify_to_dolibarr"'.($sync_payments_direction == 'shopify_to_dolibarr' ? ' selected' : '').'>'.$langs->trans("ShopifyToDolibarr").'</option>';
    print '<option value="dolibarr_to_shopify"'.($sync_payments_direction == 'dolibarr_to_shopify' ? ' selected' : '').'>'.$langs->trans("DolibarrToShopify").'</option>';
    print '<option value="both"'.($sync_payments_direction == 'both' ? ' selected' : '').'>'.$langs->trans("BothDirections").'</option>';
    print '</select>';
    print '</td>';
    print '<td>';
    print '<span id="payments_direction_help_shopify_to_dolibarr" class="opacitymedium'.($sync_payments_direction != 'shopify_to_dolibarr' ? ' hideobject' : '').'">'.$langs->trans("ShopifyToDolibarrHelp").'</span>';
    // Sortant paiements = no-op (voir avertissement en tête d'écran).
    print '<span id="payments_direction_help_dolibarr_to_shopify" class="opacitymedium'.($sync_payments_direction != 'dolibarr_to_shopify' ? ' hideobject' : '').'">'.$langs->trans("OutboundNotImplementedHelp").'</span>';
    print '<span id="payments_direction_help_both" class="opacitymedium'.($sync_payments_direction != 'both' ? ' hideobject' : '').'">'.$langs->trans("OutboundNotImplementedBothHelp").'</span>';
    print '</td>';
    print '</tr>';

    // Direction synchronisation expéditions
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("SyncShippingDirection").'</td>';
    print '<td>';
    print '<select name="sync_shipping_direction" class="flat">';
    print '<option value="shopify_to_dolibarr"'.($sync_shipping_direction == 'shopify_to_dolibarr' ? ' selected' : '').'>'.$langs->trans("ShopifyToDolibarr").'</option>';
    print '<option value="dolibarr_to_shopify"'.($sync_shipping_direction == 'dolibarr_to_shopify' ? ' selected' : '').'>'.$langs->trans("DolibarrToShopify").'</option>';
    print '<option value="both"'.($sync_shipping_direction == 'both' ? ' selected' : '').'>'.$langs->trans("BothDirections").'</option>';
    print '</select>';
    print '</td>';
    print '<td>';
    print '<span id="shipping_direction_help_shopify_to_dolibarr" class="opacitymedium'.($sync_shipping_direction != 'shopify_to_dolibarr' ? ' hideobject' : '').'">'.$langs->trans("ShopifyToDolibarrHelp").'</span>';
    // Sortant expéditions = no-op (voir avertissement en tête d'écran).
    print '<span id="shipping_direction_help_dolibarr_to_shopify" class="opacitymedium'.($sync_shipping_direction != 'dolibarr_to_shopify' ? ' hideobject' : '').'">'.$langs->trans("OutboundNotImplementedHelp").'</span>';
    print '<span id="shipping_direction_help_both" class="opacitymedium'.($sync_shipping_direction != 'both' ? ' hideobject' : '').'">'.$langs->trans("OutboundNotImplementedBothHelp").'</span>';
    print '</td>';
    print '</tr>';

    // Direction synchronisation stocks
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("SyncStocksDirection").'</td>';
    print '<td>';
    print '<select name="sync_stocks_direction" class="flat">';
    print '<option value="shopify_to_dolibarr"'.($sync_stocks_direction == 'shopify_to_dolibarr' ? ' selected' : '').'>'.$langs->trans("ShopifyToDolibarr").'</option>';
    print '<option value="dolibarr_to_shopify"'.($sync_stocks_direction == 'dolibarr_to_shopify' ? ' selected' : '').'>'.$langs->trans("DolibarrToShopify").'</option>';
    print '<option value="both"'.($sync_stocks_direction == 'both' ? ' selected' : '').'>'.$langs->trans("BothDirections").'</option>';
    print '</select>';
    print '</td>';
    print '<td>';
    // AC3 (story ecran-directions-de-synchronisation-mort-et-casse) : libellés SPÉCIFIQUES au
    // stock, pas les génériques ShopifyToDolibarrHelp/DolibarrToShopifyHelp/BothDirectionsHelp
    // partagés avec les produits — c'est le réglage où une confusion de sens coûte le plus cher
    // (stock écrasé dans le mauvais sens = stock faux en boutique). "both" doit rendre évident
    // qu'un webhook d'inventaire Shopify écrit directement dans Dolibarr.
    print '<span id="stocks_direction_help_shopify_to_dolibarr" class="opacitymedium'.($sync_stocks_direction != 'shopify_to_dolibarr' ? ' hideobject' : '').'">'.$langs->trans("StocksShopifyToDolibarrHelp").'</span>';
    print '<span id="stocks_direction_help_dolibarr_to_shopify" class="opacitymedium'.($sync_stocks_direction != 'dolibarr_to_shopify' ? ' hideobject' : '').'">'.$langs->trans("StocksDolibarrToShopifyHelp").'</span>';
    print '<span id="stocks_direction_help_both" class="opacitymedium'.($sync_stocks_direction != 'both' ? ' hideobject' : '').'">'.$langs->trans("StocksBothDirectionsHelp").'</span>';
    print '</td>';
    print '</tr>';

    // Stratégie de résolution des conflits
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("ConflictResolutionStrategy").'</td>';
    print '<td>';
    print '<select name="conflict_resolution_strategy" class="flat">';
    print '<option value="shopify_wins"'.($conflict_resolution_strategy == 'shopify_wins' ? ' selected' : '').'>'.$langs->trans("ShopifyWins").'</option>';
    print '<option value="dolibarr_wins"'.($conflict_resolution_strategy == 'dolibarr_wins' ? ' selected' : '').'>'.$langs->trans("DolibarrWins").'</option>';
    print '<option value="newest_wins"'.($conflict_resolution_strategy == 'newest_wins' ? ' selected' : '').'>'.$langs->trans("NewestWins").'</option>';
    print '<option value="oldest_wins"'.($conflict_resolution_strategy == 'oldest_wins' ? ' selected' : '').'>'.$langs->trans("OldestWins").'</option>';
    print '<option value="manual_resolution"'.($conflict_resolution_strategy == 'manual_resolution' ? ' selected' : '').'>'.$langs->trans("ManualResolution").'</option>';
    print '</select>';
    print '</td>';
    print '<td>';
    print '<span id="conflict_help_shopify_wins" class="opacitymedium'.($conflict_resolution_strategy != 'shopify_wins' ? ' hideobject' : '').'">'.$langs->trans("ShopifyWinsHelp").'</span>';
    print '<span id="conflict_help_dolibarr_wins" class="opacitymedium'.($conflict_resolution_strategy != 'dolibarr_wins' ? ' hideobject' : '').'">'.$langs->trans("DolibarrWinsHelp").'</span>';
    print '<span id="conflict_help_newest_wins" class="opacitymedium'.($conflict_resolution_strategy != 'newest_wins' ? ' hideobject' : '').'">'.$langs->trans("NewestWinsHelp").'</span>';
    print '<span id="conflict_help_oldest_wins" class="opacitymedium'.($conflict_resolution_strategy != 'oldest_wins' ? ' hideobject' : '').'">'.$langs->trans("OldestWinsHelp").'</span>';
    print '<span id="conflict_help_manual_resolution" class="opacitymedium'.($conflict_resolution_strategy != 'manual_resolution' ? ' hideobject' : '').'">'.$langs->trans("ManualResolutionHelp").'</span>';
    print '</td>';
    print '</tr>';

    print '</table>';

    // Options par défaut et dépendances
    print '<div class="info">';
    print '<i class="fa fa-info-circle"></i> ';
    print $langs->trans("SyncOptionsDependencies") . '<br>';
    print '<ul>';
    print '<li>' . $langs->trans("PaymentsSyncRequiresOrders") . '</li>';
    print '<li>' . $langs->trans("ShippingSyncRequiresOrders") . '</li>';
    print '</ul>';
    print '</div>';

    // Script JavaScript pour mettre à jour dynamiquement les descriptions d'aide
    print '<script>';
    print 'document.addEventListener("DOMContentLoaded", function() {
        // Fonction pour mettre à jour les textes d\'aide en fonction de la sélection
        function updateHelpText(selectElement, prefix) {
            var value = selectElement.value;
            document.querySelectorAll("span[id^=\'" + prefix + "_help_\']").forEach(function(el) {
                el.classList.add("hideobject");
            });
            var helpElement = document.getElementById(prefix + "_help_" + value);
            if (helpElement) {
                helpElement.classList.remove("hideobject");
            }
        }

        // Attacher les événements aux sélecteurs
        var selectElements = {
            "sync_products_direction": "products_direction",
            "sync_orders_direction": "orders_direction",
            "sync_payments_direction": "payments_direction",
            "sync_shipping_direction": "shipping_direction",
            "sync_stocks_direction": "stocks_direction",
            "conflict_resolution_strategy": "conflict"
        };

        Object.keys(selectElements).forEach(function(selectName) {
            var select = document.querySelector("select[name=\'" + selectName + "\']");
            if (select) {
                select.addEventListener("change", function() {
                    updateHelpText(this, selectElements[selectName]);
                });
            }
        });
    });';
    print '</script>';

    print '<br><center>';
    print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
    print '</center>';

    print '</form>';
}

print dol_get_fiche_end();

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

// Page end
llxFooter();
$db->close();
