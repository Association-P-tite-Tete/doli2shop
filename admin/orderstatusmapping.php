<?php
/**
 * Copyright (C) 2022-2025 P'tite Tête <doli2shop@ptitetete.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       admin/orderstatusmapping.php
 * \ingroup    doli2shop
 * \brief      Page to configure order status mapping for Shopify integration
 */

/**
 * Décide si les deux DELETE de tête de l'action `restore_defaults` autorisent un commit(), ou si
 * l'échec de l'un des deux impose un rollback().
 *
 * Fonction PURE (aucun effet de bord : n'appelle ni $db->commit() ni $db->rollback(), ne lit/écrit
 * aucune variable externe) — extraite pour que
 * test/unit/OrderStatusMappingWriteActionsSqliteTest.php exerce la VRAIE décision de production au
 * lieu d'en recopier une copie dans le corps du test (finding de revue du 09/09/2026,
 * Verification Gap Reviewer : muter le `||` d'origine en `&&` ne faisait plus échouer aucun test,
 * car les deux tests réimplémentaient ce contrôle de flux au lieu d'appeler le code réel).
 *
 * @param  bool $deleteShop2dolSucceeded Résultat du DELETE sur doli2shop_order_status_shop2dol
 * @param  bool $deleteDol2shopSucceeded Résultat du DELETE sur doli2shop_order_status_dol2shop
 * @return bool true = les deux DELETE ont réussi (poursuivre vers le commit), false = annuler
 * @since  2.5.3
 */
if (!function_exists('doli2shopShouldCommitRestoreDefaultsDeletes')) {
    function doli2shopShouldCommitRestoreDefaultsDeletes($deleteShop2dolSucceeded, $deleteDol2shopSucceeded)
    {
        return $deleteShop2dolSucceeded && $deleteDol2shopSucceeded;
    }
}

/**
 * Décide si la définition PHP des valeurs par défaut (obtenue AVANT toute suppression, cf. AC2)
 * est réellement utilisable pour restaurer les deux tables — garde défensive AC4 : si jamais cette
 * définition devenait vide (régression future), aucune suppression ne doit être tentée.
 *
 * Fonction PURE, mêmes garanties de test que doli2shopShouldCommitRestoreDefaultsDeletes()
 * ci-dessus (extraite pour que la vraie décision soit exercée par les tests, pas une copie).
 *
 * @param  array $shop2dolDefaults Résultat de OrderStatusMapper::getDefaultShopifyToDolibarrMappingsDefinition()
 * @param  array $dol2shopDefaults Résultat de OrderStatusMapper::getDefaultDolibarrToShopifyMappingsDefinition()
 * @return bool  true = source utilisable (on peut poursuivre vers la suppression puis la réinsertion)
 * @since  2.5.3
 */
if (!function_exists('doli2shopHasUsableRestoreDefaultsSource')) {
    function doli2shopHasUsableRestoreDefaultsSource(array $shop2dolDefaults, array $dol2shopDefaults)
    {
        return !empty($shop2dolDefaults) && !empty($dol2shopDefaults);
    }
}

// Permet à test/unit/OrderStatusMappingWriteActionsSqliteTest.php de faire un require_once de ce
// fichier pour obtenir la fonction pure ci-dessus SANS exécuter le bootstrap Dolibarr (main.inc.php,
// absent de l'environnement PHPUnit) ni atteindre le header()/exit qui termine chaque action plus
// bas. Cette constante n'est jamais définie ailleurs qu'au tout début de ce fichier de test :
// aucun effet en production ni sur une exécution HTTP réelle de cette page.
if (defined('DOLI2SHOP_ORDERSTATUSMAPPING_TEST_BOOTSTRAP_SKIP')) {
    return;
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists(__DIR__."/../main.inc.php")) {
    $res = @include __DIR__."/../main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../main.inc.php")) {
    $res = @include __DIR__."/../../main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../../main.inc.php")) {
    $res = @include __DIR__."/../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

global $langs, $user, $db;

// Libraries
dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/commande/class/commande.class.php');
require_once __DIR__.'/../lib/doli2shop.lib.php';
require_once __DIR__.'/../class/sqlutils.class.php';
require_once __DIR__.'/../class/orderstatusmapper.class.php';

// Translations
$langs->loadLangs(array('admin', 'doli2shop@doli2shop'));

// Access control
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOST('id', 'int');

$shopifyFinancialStatus = GETPOST('shopify_financial_status', 'alpha');
$shopifyFulfillmentStatus = GETPOST('shopify_fulfillment_status', 'alpha');
$dolibarrStatus = GETPOST('dolibarr_status', 'int');
$priority = GETPOST('priority', 'int') ?: 10;
$description = GETPOST('description', 'alphanohtml');
$isDefault = GETPOST('default_mapping', 'int') ? 1 : 0;
$isActive = GETPOST('active', 'int') ? 1 : 0;

// Migration v2.0.31: Utilisation de entity seul, storeId fixé à 1 pour compatibilité
$storeId = 1;

// Initialize technical errors array
$errors = array();

/*
 * Actions
 */

// CSRF Protection for all actions
if ($action && !verifToken()) {
    accessforbidden('Invalid CSRF token');
}

// Add a new mapping entry from Shopify to Dolibarr
if ($action == 'add_shopify_to_dolibarr' && !empty($shopifyFinancialStatus) && !empty($shopifyFulfillmentStatus) && !empty($dolibarrStatus)) {
    $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol 
            (entity, fk_shopify_store, shopify_financial_status, shopify_fulfillment_status, dolibarr_status, 
             default_mapping, priority, description, active)
            VALUES (" . $conf->entity . ", " . $storeId . ", '" . $db->escape($shopifyFinancialStatus) . "', 
                   '" . $db->escape($shopifyFulfillmentStatus) . "', " . $dolibarrStatus . ", 
                   " . $isDefault . ", " . $priority . ", '" . $db->escape($description) . "', " . $isActive . ")";
                   
    $result = SqlUtils::executeQuery($db, $sql, "orderstatusmapping.php::add_shopify_to_dolibarr", false);
    if (!$result) {
        dol_syslog("orderstatusmapping.php: SQL error adding Shopify to Dolibarr mapping: " . $db->lasterror(), LOG_ERR);
        setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
    } else {
        setEventMessages($langs->trans("OrderStatusMappingSaved"), null);
    }

    header("Location: " . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1));
    exit;
}

// Add a new mapping entry from Dolibarr to Shopify
if ($action == 'add_dolibarr_to_shopify' && !empty($shopifyFinancialStatus) && !empty($shopifyFulfillmentStatus) && !empty($dolibarrStatus)) {
    $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop 
            (entity, fk_shopify_store, dolibarr_status, shopify_financial_status, shopify_fulfillment_status, 
             default_mapping, priority, description, active)
            VALUES (" . $conf->entity . ", " . $storeId . ", " . $dolibarrStatus . ", 
                   '" . $db->escape($shopifyFinancialStatus) . "', '" . $db->escape($shopifyFulfillmentStatus) . "', 
                   " . $isDefault . ", " . $priority . ", '" . $db->escape($description) . "', " . $isActive . ")";
                   
    $result = SqlUtils::executeQuery($db, $sql, "orderstatusmapping.php::add_dolibarr_to_shopify", false);
    if (!$result) {
        dol_syslog("orderstatusmapping.php: SQL error adding Dolibarr to Shopify mapping: " . $db->lasterror(), LOG_ERR);
        setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
    } else {
        setEventMessages($langs->trans("OrderStatusMappingSaved"), null);
    }

    header("Location: " . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1));
    exit;
}

// Delete a mapping entry
if ($action == 'delete' && !empty($id)) {
    $direction = GETPOST('direction', 'alpha');
    
    if ($direction === 'shopify_to_dolibarr') {
        $table = MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol";
    } else {
        $table = MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop";
    }
    
    $sql = "DELETE FROM " . $table . " WHERE rowid = " . $id;
    $result = SqlUtils::executeQuery($db, $sql, "orderstatusmapping.php::delete", false);

    if (!$result) {
        dol_syslog("orderstatusmapping.php: SQL error deleting mapping: " . $db->lasterror(), LOG_ERR);
        setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
    } else {
        setEventMessages($langs->trans("MappingDeleted"), null);
    }
    
    header("Location: " . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1));
    exit;
}

// Restore default mappings
if ($action == 'restore_defaults' && $confirm == 'yes') {
    // AC1/AC2 (story restaurer-les-correspondances-de-statut-vide-les-tables) : la source des
    // valeurs par défaut est une définition PHP unique, partagée avec le seeding d'installation
    // (OrderStatusMapper::ensureDefaultMappings(), appelé depuis modDoli2Shop::init()). Ces deux
    // appels sont de simples lectures PHP en mémoire (aucun accès base) : ils sont obtenus AVANT
    // $db->begin() et la moindre suppression, PAR CONSTRUCTION — pas par chance.
    $defaultShop2dolMappings = OrderStatusMapper::getDefaultShopifyToDolibarrMappingsDefinition();
    $defaultDol2shopMappings = OrderStatusMapper::getDefaultDolibarrToShopifyMappingsDefinition();

    if (!doli2shopHasUsableRestoreDefaultsSource($defaultShop2dolMappings, $defaultDol2shopMappings)) {
        // AC4, garde défensive : si la définition PHP était vidée par une régression future, on
        // ne supprime RIEN — jamais de suppression sans savoir déjà quoi réinsérer.
        dol_syslog("orderstatusmapping.php: restore_defaults abandonné — définition des valeurs par défaut vide ou invalide", LOG_ERR);
        setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
        header("Location: " . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1));
        exit;
    }

    $db->begin();
    $success = true;
    $insertedShop2dol = 0;
    $insertedDol2shop = 0;

    // First, delete existing mappings
    $sql1 = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol WHERE entity = " . $conf->entity;
    $sql2 = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop WHERE entity = " . $conf->entity;

    $result1 = SqlUtils::executeQuery($db, $sql1, "orderstatusmapping.php::restore_defaults (delete shop2dol)", false);
    $result2 = SqlUtils::executeQuery($db, $sql2, "orderstatusmapping.php::restore_defaults (delete dol2shop)", false);

    if (!doli2shopShouldCommitRestoreDefaultsDeletes($result1, $result2)) {
        $success = false;
        dol_syslog("orderstatusmapping.php: SQL error deleting existing mappings: " . $db->lasterror(), LOG_ERR);
    } else {
        // AC1/AC6 : réinsertion depuis la définition PHP unique ci-dessus — plus aucune lecture
        // d'un fichier SQL (sql/update_2.1.0_add_order_status_mapping.sql n'a jamais existé).
        foreach ($defaultShop2dolMappings as $mapping) {
            $sqlInsertShop2dol = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol
                    (entity, fk_shopify_store, shopify_financial_status, shopify_fulfillment_status, dolibarr_status, default_mapping, priority, description, active)
                    VALUES (?, 1, ?, ?, ?, ?, ?, ?, 1)";
            $result = SqlUtils::executeQuery(
                $db,
                $sqlInsertShop2dol,
                "orderstatusmapping.php::restore_defaults (insert shop2dol)",
                false,
                [
                    (int) $conf->entity,
                    $mapping['shopify_financial_status'],
                    $mapping['shopify_fulfillment_status'],
                    (int) $mapping['dolibarr_status'],
                    (int) $mapping['default_mapping'],
                    (int) $mapping['priority'],
                    (string) $mapping['description'],
                ]
            );
            if (!$result) {
                $success = false;
                break;
            }
            $insertedShop2dol++;
        }

        if ($success) {
            foreach ($defaultDol2shopMappings as $mapping) {
                $sqlInsertDol2shop = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop
                        (entity, fk_shopify_store, dolibarr_status, shopify_financial_status, shopify_fulfillment_status, default_mapping, priority, description, active)
                        VALUES (?, 1, ?, ?, ?, ?, ?, ?, 1)";
                $result = SqlUtils::executeQuery(
                    $db,
                    $sqlInsertDol2shop,
                    "orderstatusmapping.php::restore_defaults (insert dol2shop)",
                    false,
                    [
                        (int) $conf->entity,
                        (int) $mapping['dolibarr_status'],
                        $mapping['shopify_financial_status'],
                        $mapping['shopify_fulfillment_status'],
                        (int) $mapping['default_mapping'],
                        (int) $mapping['priority'],
                        (string) $mapping['description'],
                    ]
                );
                if (!$result) {
                    $success = false;
                    break;
                }
                $insertedDol2shop++;
            }
        }

        // AC4, seconde garde structurelle : même si chaque INSERT individuel a réussi, refuser le
        // commit si la repopulation totale est nulle — c'est exactement le symptôme d'origine
        // (DELETE commité, réinsertion silencieusement vide). Avec la définition réelle
        // ci-dessus (non vide, vérifiée plus haut) ce cas ne devrait jamais survenir ; il
        // caractérise la propriété plutôt que de compter sur la définition pour ne jamais changer.
        if ($success && ($insertedShop2dol + $insertedDol2shop) === 0) {
            $success = false;
            dol_syslog("orderstatusmapping.php: restore_defaults — aucune ligne insérée après suppression, commit refusé", LOG_ERR);
        }
    }

    if ($success) {
        $db->commit();
        // AC5 : le résultat est dit à l'utilisateur — combien de correspondances ont été
        // rétablies, jamais un succès silencieux sur zéro ligne.
        setEventMessages($langs->trans("DefaultMappingsRestoredCount", $insertedShop2dol + $insertedDol2shop), null);
    } else {
        $db->rollback();
        dol_syslog("orderstatusmapping.php: SQL error restoring default mappings: " . $db->lasterror(), LOG_ERR);
        setEventMessages($langs->trans("ErrorDatabaseQuery"), null, 'errors');
    }

    header("Location: " . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1));
    exit;
}

/*
 * View
 */

$title = $langs->trans("OrderStatusMapping");
llxHeader('', $title);

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Subheader

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class=\"doli2shop-page\">';
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Configuration header
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'orderstatusmapping', $langs->trans("ShopifyIntegration"), -1, 'doli2shop@doli2shop');
doli2shopRenderAdminTopBar($db);

// Check if Shopify configuration is completed
$shopify_store_hostname = getDolGlobalString('SHOPIFY_STORE_HOSTNAME');
$shopify_access_token = getDolGlobalString('SHOPIFY_ACCESS_TOKEN');

if (empty($shopify_store_hostname) || empty($shopify_access_token)) {
    print '<div class="error">' . $langs->trans("ShopifyConfigurationRequired") . '</div>';
    print dol_get_fiche_end();
    llxFooter();
    exit;
}

// Shopify Financial Status options (from API documentation)
$shopifyFinancialStatuses = array(
    'PAID' => $langs->trans('PAID'),
    'PARTIALLY_PAID' => $langs->trans('PARTIALLY_PAID'),
    'PENDING' => $langs->trans('PENDING'),
    'VOIDED' => $langs->trans('VOIDED'),
    'AUTHORIZED' => $langs->trans('AUTHORIZED'),
    'EXPIRED' => $langs->trans('EXPIRED'),
    'REFUNDED' => $langs->trans('REFUNDED'),
    'PARTIALLY_REFUNDED' => $langs->trans('PARTIALLY_REFUNDED')
);

// Shopify Fulfillment Status options (from API documentation)
$shopifyFulfillmentStatuses = array(
    'FULFILLED' => $langs->trans('FULFILLED'),
    'PARTIALLY_FULFILLED' => $langs->trans('PARTIALLY_FULFILLED'),
    'UNFULFILLED' => $langs->trans('UNFULFILLED'),
    // Story 51-2 (Code Review LOW — FIX 8) : enum OrderDisplayFulfillmentStatus ajouté par le
    // changelog Shopify ~03/07/2026 (commande 100% service/digital, aucun fulfillment attendu),
    // déjà mappé côté module vers 'not_required' (mapFulfillmentStatus(), T3.4) — ajouté ici
    // pour cohérence de l'écran de mapping.
    'FULFILLMENT_NOT_REQUIRED' => $langs->trans('FULFILLMENT_NOT_REQUIRED'),
    'SCHEDULED' => $langs->trans('SCHEDULED'),
    'ON_HOLD' => $langs->trans('ON_HOLD'),
    'OPEN' => $langs->trans('OPEN'),
    'IN_PROGRESS' => $langs->trans('IN_PROGRESS'),
    'PENDING' => $langs->trans('PENDING'),
    'SUCCESS' => $langs->trans('SUCCESS'),
    'CANCELLED' => $langs->trans('CANCELLED'),
    'ERROR' => $langs->trans('ERROR')
);

// Dolibarr Order Status options
//
// AC7 (story restaurer-les-correspondances-de-statut-vide-les-tables) : ce tableau référençait
// `Commande::STATUS_DELIVERED`, constante INEXISTANTE dans tout Dolibarr 19+ à 23+ réellement
// ciblé par le module (absente de commande/class/commande.class.php, de stubs/dolibarr.stub et de
// test/bootstrap.php) — y accéder levait une erreur fatale PHP (« Undefined constant ») à chaque
// chargement de cet écran, avant même d'atteindre le bouton de restauration plus bas. Le Dolibarr
// réel ne connaît que 5 statuts de commande ; STATUS_CLOSED (3) porte le libellé natif
// « StatusOrderDelivered » (cf. Commande::LibStatut()) — il n'y a pas de statut « livré » distinct
// de « clôturé ».
$dolibarrStatuses = array(
    Commande::STATUS_CANCELED => $langs->trans('StatusOrderCanceled'),
    Commande::STATUS_DRAFT => $langs->trans('StatusOrderDraft'),
    Commande::STATUS_VALIDATED => $langs->trans('StatusOrderValidated'),
    Commande::STATUS_SHIPMENTONPROCESS => $langs->trans('StatusOrderSentPartially'),
    Commande::STATUS_CLOSED => $langs->trans('StatusOrderDelivered')
);

// Display intro
print '<div class="opacitymedium">' . $langs->trans("OrderStatusMappingDesc") . '</div><br>';

// Show Shopify to Dolibarr mappings
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="7">' . $langs->trans("ShopifyToDolibarrStatusMapping") . '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td>' . $langs->trans("ShopifyFinancialStatus") . '</td>';
print '<td>' . $langs->trans("ShopifyFulfillmentStatus") . '</td>';
print '<td>' . $langs->trans("DolibarrOrderStatus") . '</td>';
print '<td>' . $langs->trans("DefaultMapping") . '</td>';
print '<td>' . $langs->trans("MappingPriority") . '</td>';
print '<td>' . $langs->trans("MappingDescription") . '</td>';
print '<td>' . $langs->trans("Actions") . '</td>';
print '</tr>';

// Retrieve existing mappings
$sql = "SELECT rowid, shopify_financial_status, shopify_fulfillment_status, dolibarr_status, default_mapping, priority, description, active
        FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol
        WHERE entity = " . $conf->entity . "
        ORDER BY priority DESC, rowid ASC";

$resql = $db->query($sql);
$shopifyToDolibarrMappings = array();

if ($resql) {
    $num = $db->num_rows($resql);
    
    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';
            print '<td>' . dol_escape_htmltag($obj->shopify_financial_status) . '</td>';
            print '<td>' . dol_escape_htmltag($obj->shopify_fulfillment_status) . '</td>';
            print '<td>' . $dolibarrStatuses[$obj->dolibarr_status] . '</td>';
            print '<td>' . ($obj->default_mapping ? $langs->trans('Yes') : $langs->trans('No')) . '</td>';
            print '<td>' . $obj->priority . '</td>';
            print '<td>' . dol_escape_htmltag($obj->description) . '</td>';
            print '<td>';
            print '<a class="reposition" href="' . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1) . '?action=delete&id=' . $obj->rowid . '&direction=shopify_to_dolibarr&token=' . newToken() . '">';
            print img_delete();
            print '</a>';
            print '</td>';
            print '</tr>';
            
            $shopifyToDolibarrMappings[] = $obj;
        }
    } else {
        print '<tr><td colspan="7"><span class="opacitymedium">' . $langs->trans("NoOrderStatusMappingDefined") . '</span></td></tr>';
    }
} else {
    dol_syslog("orderstatusmapping.php: SQL error fetching Shopify to Dolibarr mappings: " . $db->lasterror(), LOG_ERR);
    print '<tr><td colspan="7" class="error">' . $langs->trans("ErrorDatabaseQuery") . '</td></tr>';
}

// Form to add a new mapping
print '<tr class="liste_titre">';
print '<td colspan="7">' . $langs->trans("AddOrderStatusMapping") . '</td>';
print '</tr>';

print '<form action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" method="post">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="add_shopify_to_dolibarr">';

print '<tr>';
// Shopify Financial Status
print '<td>';
print '<select name="shopify_financial_status" class="flat" required>';
print '<option value="">&nbsp;</option>';
foreach ($shopifyFinancialStatuses as $key => $label) {
    print '<option value="' . $key . '">' . $key . '</option>';
}
print '</select>';
print '</td>';

// Shopify Fulfillment Status
print '<td>';
print '<select name="shopify_fulfillment_status" class="flat" required>';
print '<option value="">&nbsp;</option>';
foreach ($shopifyFulfillmentStatuses as $key => $label) {
    print '<option value="' . $key . '">' . $key . '</option>';
}
print '</select>';
print '</td>';

// Dolibarr Status
print '<td>';
print '<select name="dolibarr_status" class="flat" required>';
print '<option value="">&nbsp;</option>';
foreach ($dolibarrStatuses as $key => $label) {
    print '<option value="' . $key . '">' . $label . '</option>';
}
print '</select>';
print '</td>';

// Default mapping
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="default_mapping" value="1">';
print '<span class="toggle-slider"></span>';
print '</label>';
print '</td>';

// Priority
print '<td>';
print '<input type="number" name="priority" value="10" class="width50 flat">';
print '</td>';

// Description
print '<td>';
print '<input type="text" name="description" class="flat" size="30">';
print '</td>';

// Submit button
print '<td>';
print '<input type="submit" class="button" value="' . $langs->trans("Add") . '">';
print '</td>';
print '</tr>';

print '</form>';
print '</table>';
print '</div>';
print '<br>';

// Show Dolibarr to Shopify mappings
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="7">' . $langs->trans("DolibarrToShopifyStatusMapping") . '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<td>' . $langs->trans("DolibarrOrderStatus") . '</td>';
print '<td>' . $langs->trans("ShopifyFinancialStatus") . '</td>';
print '<td>' . $langs->trans("ShopifyFulfillmentStatus") . '</td>';
print '<td>' . $langs->trans("DefaultMapping") . '</td>';
print '<td>' . $langs->trans("MappingPriority") . '</td>';
print '<td>' . $langs->trans("MappingDescription") . '</td>';
print '<td>' . $langs->trans("Actions") . '</td>';
print '</tr>';

// Retrieve existing mappings
$sql = "SELECT rowid, dolibarr_status, shopify_financial_status, shopify_fulfillment_status, default_mapping, priority, description, active
        FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop
        WHERE entity = " . $conf->entity . "
        ORDER BY priority DESC, rowid ASC";

$resql = $db->query($sql);
$dolibarrToShopifyMappings = array();

if ($resql) {
    $num = $db->num_rows($resql);
    
    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';
            print '<td>' . $dolibarrStatuses[$obj->dolibarr_status] . '</td>';
            print '<td>' . dol_escape_htmltag($obj->shopify_financial_status) . '</td>';
            print '<td>' . dol_escape_htmltag($obj->shopify_fulfillment_status) . '</td>';
            print '<td>' . ($obj->default_mapping ? $langs->trans('Yes') : $langs->trans('No')) . '</td>';
            print '<td>' . $obj->priority . '</td>';
            print '<td>' . dol_escape_htmltag($obj->description) . '</td>';
            print '<td>';
            print '<a class="reposition" href="' . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1) . '?action=delete&id=' . $obj->rowid . '&direction=dolibarr_to_shopify&token=' . newToken() . '">';
            print img_delete();
            print '</a>';
            print '</td>';
            print '</tr>';
            
            $dolibarrToShopifyMappings[] = $obj;
        }
    } else {
        print '<tr><td colspan="7"><span class="opacitymedium">' . $langs->trans("NoOrderStatusMappingDefined") . '</span></td></tr>';
    }
} else {
    dol_syslog("orderstatusmapping.php: SQL error fetching Dolibarr to Shopify mappings: " . $db->lasterror(), LOG_ERR);
    print '<tr><td colspan="7" class="error">' . $langs->trans("ErrorDatabaseQuery") . '</td></tr>';
}

// Form to add a new mapping
print '<tr class="liste_titre">';
print '<td colspan="7">' . $langs->trans("AddOrderStatusMapping") . '</td>';
print '</tr>';

print '<form action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" method="post">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="add_dolibarr_to_shopify">';

print '<tr>';
// Dolibarr Status
print '<td>';
print '<select name="dolibarr_status" class="flat" required>';
print '<option value="">&nbsp;</option>';
foreach ($dolibarrStatuses as $key => $label) {
    print '<option value="' . $key . '">' . $label . '</option>';
}
print '</select>';
print '</td>';

// Shopify Financial Status
print '<td>';
print '<select name="shopify_financial_status" class="flat" required>';
print '<option value="">&nbsp;</option>';
foreach ($shopifyFinancialStatuses as $key => $label) {
    print '<option value="' . $key . '">' . $key . '</option>';
}
print '</select>';
print '</td>';

// Shopify Fulfillment Status
print '<td>';
print '<select name="shopify_fulfillment_status" class="flat" required>';
print '<option value="">&nbsp;</option>';
foreach ($shopifyFulfillmentStatuses as $key => $label) {
    print '<option value="' . $key . '">' . $key . '</option>';
}
print '</select>';
print '</td>';

// Default mapping
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="default_mapping" value="1">';
print '<span class="toggle-slider"></span>';
print '</label>';
print '</td>';

// Priority
print '<td>';
print '<input type="number" name="priority" value="10" class="width50 flat">';
print '</td>';

// Description
print '<td>';
print '<input type="text" name="description" class="flat" size="30">';
print '</td>';

// Submit button
print '<td>';
print '<input type="submit" class="button" value="' . $langs->trans("Add") . '">';
print '</td>';
print '</tr>';

print '</form>';
print '</table>';
print '</div>';
print '<br>';

// Additional explanation
print '<div class="opacitymedium">';
print $langs->trans("StatusMappingExplanation") . '<br><br>';
print '</div>';

// Button to restore default mappings
print '<div class="tabsAction">';
print '<a class="butAction" href="' . dol_buildpath('/doli2shop/admin/orderstatusmapping.php', 1) . '?action=restore_defaults&token=' . newToken() . '">' . $langs->trans("RestoreDefaultMappings") . '</a>';
print '</div>';

// Confirmation dialog for restoring defaults
if ($action == 'restore_defaults') {
    print $form->formconfirm(dol_escape_htmltag($_SERVER["PHP_SELF"]), $langs->trans("RestoreDefaultMappings"), $langs->trans("ConfirmRestoreDefaultMappings"), "restore_defaults", '', 0, 1);
}

print dol_get_fiche_end();

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

llxFooter();
$db->close();