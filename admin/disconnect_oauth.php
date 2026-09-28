<?php
/**
 * @file        admin/disconnect_oauth.php
 * @brief       Disconnect Shopify OAuth and clear all synchronization data
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.6
 * @link        http://www.dolibarr.org
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

// Include compatibility functions for older Dolibarr versions (verifToken(), Hotfix 2.4.6)
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Libraries
dol_include_once('/core/lib/admin.lib.php');
require_once '../lib/doli2shop.lib.php';
require_once '../class/storeservice.class.php';

// Load translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Access control
if (!$user->admin) {
    accessforbidden();
}

// CSRF protection
// Hotfix 2.4.6 (2/2) : réimplémentait en dur la comparaison au mauvais jeton (`newToken()` =
// jeton de la PROCHAINE requête, jamais celui réellement soumis) — cassé en silence dès que
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégué au helper partagé
// verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
if (!verifToken()) {
    // If no token or invalid token, show confirmation page
    $action = 'confirm';
} else {
    $action = GETPOST('action', 'aZ09');
}

/*
 * Résolution de la boutique cible (Story deconnexion-oauth-ignore-le-multi-boutiques, AC1)
 * ------------------------------------------------------------------------------------------
 * Validate du 09/09/2026, réserve n°1 : cet écran résout TOUJOURS une boutique explicite — par
 * défaut ou secondaire — jamais un contexte "non résolu" façon code aveugle (cron/webhooks). Le
 * `store_id` est porté explicitement par l'URL (lien "Déconnecter" de admin/setup.php ET lien de
 * confirmation ci-dessous), et REVALIDÉ ici à chaque requête (réserve n°5) : $_SESSION n'est
 * jamais utilisée comme source de vérité entre la page de confirmation et l'action réelle.
 */
$requestedStoreId = GETPOST('store_id', 'int');
$storeService = new StoreService($db);
$allStores = $storeService->getAll();
$targetStore = doli2shopResolveOAuthDisconnectStore($allStores, $requestedStoreId);

if ($requestedStoreId > 0 && $targetStore === null) {
    dol_syslog("Doli2Shop disconnect_oauth: store_id=" . $requestedStoreId . " invalide ou hors entite, repli sur la boutique par defaut", LOG_WARNING);
} elseif ($requestedStoreId > 0 && $targetStore !== null && (int) $targetStore->rowid !== $requestedStoreId) {
    // Ne devrait jamais arriver (la recherche est par rowid) — garde défensive.
    dol_syslog("Doli2Shop disconnect_oauth: résolution de boutique incohérente pour store_id=" . $requestedStoreId, LOG_ERR);
}

// Review 3 couches du 09/09/2026 (HIGH, bord n°2) : `doli2shopResolveOAuthDisconnectStore()` ne
// renvoie `null` QUE quand aucune boutique par défaut n'a pu être identifiée (aucune boutique du
// tout, ou anomalie — aucune n'a `is_default = 1`). Continuer avec `$targetStoreId = 0` ferait
// poser AUCUN filtre `fk_store` à la purge plus bas → suppression de TOUTES les commandes de
// l'entité, toutes boutiques confondues (exactement le défaut n°2 que cette story corrigeait).
// On refuse donc explicitement l'action ici, avant tout autre traitement (GET comme POST).
if ($targetStore === null) {
    dol_syslog("Doli2Shop disconnect_oauth: aucune boutique par defaut resolue pour l'entite " . $conf->entity . " - deconnexion refusee (purge non bornee aurait ete requise)", LOG_ERR);
    setEventMessages(doli2shopBuildOAuthDisconnectNoStoreMessage($langs), null, 'errors');
    header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=settings');
    exit;
}

// Cas voisin (anomalie connue, story backlog `doublon-is-default-boutiques-non-empeche`) : si
// PLUSIEURS lignes portent `is_default = 1`, la résolution retient la première de
// `StoreService::getAll()`, qui peut différer de la boutique affichée comme courante par
// `admin/setup.php`. On ne bloque pas (la boutique retenue reste nommée explicitement dans le
// texte de confirmation ci-dessous), mais on journalise l'anomalie pour qu'elle soit corrigée à
// la source.
$defaultStoreCount = 0;
foreach ($allStores as $storeForAnomalyCheck) {
    if (!empty($storeForAnomalyCheck->is_default)) {
        $defaultStoreCount++;
    }
}
if ($defaultStoreCount > 1) {
    dol_syslog("Doli2Shop disconnect_oauth: anomalie " . $defaultStoreCount . " boutiques is_default=1 pour l'entite " . $conf->entity . " - resolution retenue sur la boutique #" . (int) $targetStore->rowid, LOG_WARNING);
}

$targetIsDefault  = !empty($targetStore->is_default);
$targetStoreId    = (int) $targetStore->rowid;
$targetStoreLabel = doli2shopStoreDisplayLabel($targetStore);

// Review 3 couches du 09/09/2026 (HIGH, bord n°1) : sûr uniquement en mono-boutique garanti par
// construction (cf. PHPDoc de `doli2shopShouldPurgeOrphanedStoreRows()`, lib/doli2shop.lib.php).
$includeOrphanedStoreRows = doli2shopShouldPurgeOrphanedStoreRows($allStores, $targetIsDefault);

// Domaine affiché — même règle que admin/setup.php (:1072-1079) : la boutique par défaut lit les
// constantes globales legacy DOLI2SHOP_*, une boutique secondaire lit sa propre ligne `stores`.
$connected_shop = (!$targetStore->is_default)
    ? (string) $targetStore->shop_domain
    : getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME');

/*
 * Actions
 */

if ($action == 'disconnect' && verifToken()) {
    $error = 0;

    $db->begin();

    // 1. Delete OAuth constants — SEULEMENT si la cible est la boutique par défaut (AC1). Les
    //    constantes DOLI2SHOP_* sont globales à l'entité et n'appartiennent qu'à la boutique par
    //    défaut (invariant Epic 47) : les supprimer pour une boutique SECONDAIRE couperait la
    //    connexion de la boutique par défaut au lieu de celle réellement sélectionnée — exactement
    //    le défaut n°1 de cette story. La déconnexion des identifiants propres d'une boutique
    //    secondaire (ligne `llx_doli2shop_stores`) n'est pas couverte ici — cf. réserve n°3 du
    //    Validate, sémantique volontairement non spécifiée par cette story (hors périmètre).
    if ($targetIsDefault) {
        $consts_to_delete = array(
            'DOLI2SHOP_STORE_HOSTNAME',
            'DOLI2SHOP_ACCESS_TOKEN',
            'DOLI2SHOP_API_KEY',
            'DOLI2SHOP_API_SECRET_KEY',
            'DOLI2SHOP_OAUTH_SCOPES',
            'DOLI2SHOP_OAUTH_CONNECTED_AT',
            'DOLI2SHOP_LOCATION_ID',
            'DOLI2SHOP_VENDOR'
        );

        foreach ($consts_to_delete as $const_name) {
            $result = dolibarr_del_const($db, $const_name, $conf->entity);
            if ($result < 0) {
                $error++;
                dol_syslog("Doli2Shop disconnect_oauth: Failed to delete constant " . $const_name, LOG_ERR);
            }
        }
    }

    // 2. Purge des commandes synchronisées, bornée à la boutique déconnectée (AC2). Les tables
    //    `doli2shop_products_mapping` et `doli2shop_collections_mapping` promises par l'ancien
    //    texte n'existent dans AUCUN fichier sql/ du dépôt (AC3, Validate constat n°3) — le code
    //    mort correspondant est retiré, sans y substituer de nouvelle purge (llx_doli2shop_products
    //    et llx_doli2shop_collections ne sont pas touchées ; llx_doli2shop_collections n'a d'ailleurs
    //    pas de colonne fk_store — réserve n°4 du Validate, lecture sûre).
    $table = MAIN_DB_PREFIX . 'doli2shop_orders';
    $sql_check = "SHOW TABLES LIKE '" . $db->escape($table) . "'";
    $resql_check = $db->query($sql_check);
    if ($resql_check && $db->num_rows($resql_check) > 0) {
        $sql = doli2shopBuildOAuthPurgeSql($table, (int) $conf->entity, $targetStoreId, $includeOrphanedStoreRows);
        $resql = $db->query($sql);
        if (!$resql) {
            $error++;
            dol_syslog("Doli2Shop disconnect_oauth: Failed to purge table " . $table . ": " . $db->lasterror(), LOG_ERR);
        } else {
            $affected = $db->affected_rows($resql);
            dol_syslog("Doli2Shop disconnect_oauth: Purged " . $affected . " rows from " . $table . " (store #" . $targetStoreId . ", orphaned fk_store=0 rows included: " . ($includeOrphanedStoreRows ? 'yes' : 'no') . ")", LOG_INFO);
        }
    }

    // 3. Clear ShopifyApi cache (will be rebuilt on next use)
    // This is done automatically when new configuration is loaded

    if (!$error) {
        $db->commit();
        dol_syslog("Doli2Shop disconnect_oauth: Successfully disconnected Shopify OAuth for entity " . $conf->entity . " (store #" . $targetStoreId . ")", LOG_INFO);
        setEventMessages($langs->trans("ShopifyDisconnectSuccess"), null, 'mesgs');
        header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=settings&disconnected=1&store_id=' . $targetStoreId);
        exit;
    } else {
        $db->rollback();
        setEventMessages($langs->trans("ShopifyDisconnectError"), null, 'errors');
    }
}

/*
 * View
 */

$page_name = $langs->trans("DisconnectShopify");
llxHeader('', $page_name);

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print load_fiche_titre($page_name, '', 'title_setup');

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("Doli2ShopSetup"), -1, "doli2shop@doli2shop");
// Pas de barre de contexte : le store_id résolu ci-dessus (URL, jamais la session) pilote déjà
// intégralement la boutique concernée sur cet écran.

// Confirmation page
print '<div class="center" style="padding: 30px;">';

print '<div style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); border-radius: 12px; padding: 30px; color: white; max-width: 600px; margin: 0 auto;">';
print '<h2 style="margin: 0 0 20px 0; color: white;"><i class="fas fa-exclamation-triangle"></i> ' . $langs->trans("DisconnectShopifyConfirmTitle") . '</h2>';

// Current connection info
if (!empty($connected_shop)) {
    print '<p style="background: rgba(255,255,255,0.2); padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
    print '<strong>' . $langs->trans("CurrentlyConnectedTo") . ':</strong><br>';
    print '<span style="font-size: 1.2em;">' . dol_escape_htmltag($connected_shop) . '</span>';
    print '</p>';
}

// AC3 : le texte décrit EXACTEMENT ce qui va se passer, la boutique concernée nommément, et ce
// qui est conservé — clé paramétrée (réserve n°6 du Validate, 5 langues).
print '<p style="margin-bottom: 25px;">' . $langs->trans("DisconnectShopifyWarning", dol_escape_htmltag($targetStoreLabel)) . '</p>';

print '<ul style="text-align: left; background: rgba(255,255,255,0.1); padding: 15px 15px 15px 35px; border-radius: 8px; margin-bottom: 25px;">';
if ($targetIsDefault) {
    print '<li>' . $langs->trans("DisconnectWarningCredentials") . '</li>';
}
print '<li>' . $langs->trans("DisconnectWarningOrders") . '</li>';
print '<li>' . $langs->trans("DisconnectWarningMappingsKept") . '</li>';
if (!$targetIsDefault) {
    print '<li>' . $langs->trans("DisconnectWarningSecondaryCredentialsUnchanged") . '</li>';
}
print '</ul>';

print '<p style="font-weight: bold; margin-bottom: 25px;">' . $langs->trans("DisconnectActionIrreversible") . '</p>';

print '<div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">';

// Cancel button
print '<a href="' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=settings&store_id=' . $targetStoreId . '" class="button" style="background: white; color: #333; border: none; padding: 12px 25px;">';
print '<i class="fas fa-arrow-left"></i> ' . $langs->trans("Cancel");
print '</a>';

// Confirm disconnect button — store_id porté explicitement (réserve n°5), jamais la session.
print '<a href="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?action=disconnect&token=' . newToken() . '&store_id=' . $targetStoreId . '" class="button" style="background: #fff; color: #dc3545; border: 2px solid #fff; padding: 12px 25px; font-weight: bold;">';
print '<i class="fas fa-unlink"></i> ' . $langs->trans("ConfirmDisconnect");
print '</a>';

print '</div>';
print '</div>';

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
