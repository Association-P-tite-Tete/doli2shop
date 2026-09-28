<?php
/**
 * @file        admin/order_json_export.php
 * @brief       Outil support : export du JSON brut d'une commande Shopify (re-fetch GraphQL)
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.3.0
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
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

dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/doli2shop/lib/doli2shop.lib.php');
dol_include_once('/doli2shop/class/shopifyapi.class.php');
dol_include_once('/doli2shop/class/configurationMigrator.class.php');

// Traductions
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Accès : réservé aux administrateurs (export potentiellement sensible)
if (!$user->admin) {
    accessforbidden();
}

$action     = GETPOST('action', 'aZ09');
$identifier = GETPOST('identifier', 'alphanohtml');

// Vérifier la configuration Shopify
$migrator    = new ConfigurationMigrator($db);
$configArray = $migrator->getConfiguration($conf->entity);
$configReady = !empty($configArray)
    && !empty($configArray['shopify_store_hostname'])
    && !empty($configArray['shopify_access_token'])
    && !empty($configArray['shopify_api_key']);

/*
 * Actions
 */
$orderData = null;
$jsonString = '';
$errorMessage = '';

// Story 59-2 (AC1/AC2) : cette action faisait sauter son bloc EN SILENCE sur un jeton refusé
// (aucun message, aucune trace, aucun effet). Message écran + trace journal (LOG_WARNING).
if (($action === 'fetch' || $action === 'download') && $configReady && !verifToken()) {
    dol_syslog("order_json_export.php: action '" . $action . "' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if (($action === 'fetch' || $action === 'download') && $configReady && verifToken()) {
    if (empty($identifier)) {
        $errorMessage = $langs->trans("OrderJsonExportNoIdentifier");
    } else {
        $shopifyApi = new ShopifyApi($db);
        $orderData = $shopifyApi->getOrderRawJson($identifier);

        if (empty($orderData)) {
            $errorMessage = $shopifyApi->error ? $shopifyApi->error : $langs->trans("OrderJsonExportNotFound");
        } else {
            $jsonString = json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Traçabilité : qui, quand, quelle commande
            $orderName = isset($orderData->name) ? $orderData->name : $identifier;
            dol_syslog("Doli2Shop OrderJsonExport - user #" . $user->id . " (" . $user->login . ") a exporté la commande Shopify " . $orderName, LOG_INFO);

            // Téléchargement direct
            if ($action === 'download') {
                $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', ltrim($orderName, '#'));
                if ($safeName === '') {
                    $safeName = 'order';
                }
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="commande_' . $safeName . '.json"');
                header('Content-Length: ' . strlen($jsonString));
                echo $jsonString;
                exit;
            }
        }
    }
}

/*
 * View
 */
$form = new Form($db);
$pageTitle = $langs->trans("OrderJsonExportTitle");

llxHeader('', $pageTitle);

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'health', $langs->trans("Doli2ShopSetup"), -1, "doli2shop@doli2shop");
// Pas de barre de contexte : export global (le sélecteur n'aurait aucun effet).

print load_fiche_titre($pageTitle, '', 'object_doli2shop@doli2shop');

print '<div class="opacitymedium">' . $langs->trans("OrderJsonExportHelp") . '</div><br>';

if (!$configReady) {
    print info_admin($langs->trans("OrderJsonExportNeedConfig"), 0, 0, 'warning');
} else {
    // Formulaire de recherche
    print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="fetch">';

    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("OrderJsonExportFormTitle") . '</td></tr>';
    print '<tr class="oddeven">';
    print '<td class="titlefieldcreate">' . $langs->trans("OrderJsonExportIdentifier") . '</td>';
    print '<td><input type="text" name="identifier" class="minwidth300" placeholder="#1234" value="' . dol_escape_htmltag($identifier) . '">';
    print ' <span class="opacitymedium">' . $langs->trans("OrderJsonExportIdentifierHint") . '</span></td>';
    print '</tr>';
    print '</table>';
    print '</div>';

    print '<div class="center" style="margin-top:10px;">';
    print '<input type="submit" class="button button-primary" value="' . $langs->trans("OrderJsonExportFetch") . '">';
    print '</div>';
    print '</form>';

    print '<br>';

    if (!empty($errorMessage)) {
        print info_admin($errorMessage, 0, 0, 'error');
    }

    if (!empty($jsonString)) {
        $orderName = isset($orderData->name) ? $orderData->name : $identifier;

        print load_fiche_titre($langs->trans("OrderJsonExportResultFor", dol_escape_htmltag($orderName)), '', '');

        // Téléchargement en POST (évite le token CSRF dans l'URL/Referer/historique)
        print '<div class="center" style="margin-bottom:8px;">';
        print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '" style="display:inline;">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="download">';
        print '<input type="hidden" name="identifier" value="' . dol_escape_htmltag($identifier) . '">';
        print '<input type="submit" class="button button-primary" value="' . dol_escape_htmltag($langs->trans("OrderJsonExportDownload")) . '">';
        print '</form> ';
        print '<button type="button" class="button" onclick="doli2shopCopyOrderJson();">' . $langs->trans("OrderJsonExportCopy") . '</button>';
        print '</div>';

        print '<textarea id="doli2shop-order-json" readonly="readonly" rows="28" class="quatrevingtpercent centeronline" '
            . 'style="font-family:monospace;white-space:pre;width:100%;">'
            . dol_escape_htmltag($jsonString) . '</textarea>';

        print '<script type="text/javascript">
function doli2shopCopyOrderJson() {
    var ta = document.getElementById("doli2shop-order-json");
    if (!ta) { return; }
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value);
    } else {
        document.execCommand("copy");
    }
}
</script>';
    }
}

print dol_get_fiche_end();
print '</div>'; // .doli2shop-page

llxFooter();
$db->close();
