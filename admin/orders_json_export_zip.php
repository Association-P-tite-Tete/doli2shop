<?php
/**
 * @file        admin/orders_json_export_zip.php
 * @brief       Outil support : export multi-commandes Shopify en archive ZIP (1 JSON / commande)
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

// Seuil au-delà duquel une confirmation explicite est demandée (limite le risque de rate-limit Shopify)
const DOLI2SHOP_ZIP_CONFIRM_THRESHOLD = 50;

$action      = GETPOST('action', 'aZ09');
$rawList     = GETPOST('identifiers', 'nohtml');
$confirmBulk = GETPOST('confirm_bulk', 'alpha');

// Vérifier la configuration Shopify
$migrator    = new ConfigurationMigrator($db);
$configArray = $migrator->getConfiguration($conf->entity);
$configReady = !empty($configArray)
    && !empty($configArray['shopify_store_hostname'])
    && !empty($configArray['shopify_access_token'])
    && !empty($configArray['shopify_api_key']);

/**
 * Parse la saisie (1 identifiant par ligne ou séparés par virgule/point-virgule), dédoublonne.
 *
 * @param string $raw Saisie brute
 * @return string[] Liste d'identifiants nettoyés et uniques
 */
function doli2shopParseIdentifiers($raw)
{
    $parts = preg_split('/[\r\n,;]+/', (string) $raw);
    $clean = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !in_array($p, $clean, true)) {
            $clean[] = $p;
        }
    }
    return $clean;
}

$identifiers   = doli2shopParseIdentifiers($rawList);
$errorMessage  = '';
$reportLines   = array();

// Story 59-2 (AC1/AC2) : cette action faisait sauter son bloc EN SILENCE sur un jeton refusé
// (aucun message, aucune trace, aucun effet). Message écran + trace journal (LOG_WARNING).
if ($action === 'export' && $configReady && !verifToken()) {
    dol_syslog("orders_json_export_zip.php: action 'export' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action === 'export' && $configReady && verifToken()) {
    if (empty($identifiers)) {
        $errorMessage = $langs->trans("OrdersZipExportNoIdentifiers");
    } elseif (count($identifiers) > DOLI2SHOP_ZIP_CONFIRM_THRESHOLD && $confirmBulk !== '1') {
        // Demander confirmation explicite au-delà du seuil (re-affichée dans la vue)
        $errorMessage = $langs->trans("OrdersZipExportConfirmNeeded", count($identifiers), DOLI2SHOP_ZIP_CONFIRM_THRESHOLD);
    } elseif (!class_exists('ZipArchive')) {
        $errorMessage = $langs->trans("OrdersZipExportNoZipExt");
    } else {
        $shopifyApi = new ShopifyApi($db);

        $tmpZipPath = tempnam(sys_get_temp_dir(), 'd2s_orders_zip_');
        $zip = new ZipArchive();
        if ($tmpZipPath === false) {
            $errorMessage = $langs->trans("OrdersZipExportTmpError");
        } elseif ($zip->open($tmpZipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZipPath);
            $errorMessage = $langs->trans("OrdersZipExportZipError");
        } else {
            $okCount   = 0;
            $failCount = 0;
            $usedNames = array();

            foreach ($identifiers as $idx => $identifier) {
                $orderData = $shopifyApi->getOrderRawJson($identifier);

                if (empty($orderData)) {
                    $failCount++;
                    $reportLines[] = '✗ ' . $identifier;
                } else {
                    $orderName = isset($orderData->name) ? $orderData->name : $identifier;
                    $safeName  = preg_replace('/[^A-Za-z0-9_\-]/', '_', ltrim($orderName, '#'));
                    if ($safeName === '') {
                        $safeName = 'order_' . $idx;
                    }
                    // Éviter les collisions de noms dans le zip
                    $entryName = 'commande_' . $safeName . '.json';
                    $suffix = 1;
                    while (isset($usedNames[$entryName])) {
                        $entryName = 'commande_' . $safeName . '_' . $suffix . '.json';
                        $suffix++;
                    }
                    $usedNames[$entryName] = true;

                    $jsonString = json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $zip->addFromString($entryName, $jsonString);
                    $okCount++;
                    $reportLines[] = '✓ ' . $orderName;
                }

                // Throttling défensif entre appels (le backoff 429 reste géré dans executeGraphQL)
                if ($idx < count($identifiers) - 1) {
                    usleep(300000); // 300 ms
                }
            }

            // Joindre un rapport texte (succès/échecs) DANS le zip — sinon perdu au streaming
            $reportHeader = $langs->trans("OrdersZipExportReport") . ' (' . $okCount . ' OK / ' . $failCount . ' KO)';
            $zip->addFromString('_rapport.txt', $reportHeader . "\n\n" . implode("\n", $reportLines) . "\n");

            $zip->close();

            // Traçabilité : qui, quand, combien
            dol_syslog("Doli2Shop OrdersZipExport - user #" . $user->id . " (" . $user->login . ") a exporté $okCount commande(s) en ZIP ($failCount échec(s))", LOG_INFO);

            if ($okCount === 0) {
                @unlink($tmpZipPath);
                $errorMessage = $langs->trans("OrdersZipExportAllFailed");
            } else {
                $zipFilename = 'export_orders_' . dol_print_date(dol_now(), '%Y%m%d_%H%M%S') . '.zip';
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
                header('Content-Length: ' . filesize($tmpZipPath));
                $sent = readfile($tmpZipPath);
                if ($sent === false) {
                    // Headers déjà envoyés : on ne peut que tracer l'échec côté serveur
                    dol_syslog("Doli2Shop OrdersZipExport - échec readfile pour $zipFilename (user #" . $user->id . ")", LOG_ERR);
                }
                @unlink($tmpZipPath);
                exit;
            }
            @unlink($tmpZipPath);
        }
    }
}

/*
 * View
 */
$form = new Form($db);
$pageTitle = $langs->trans("OrdersZipExportTitle");

llxHeader('', $pageTitle);

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'health', $langs->trans("Doli2ShopSetup"), -1, "doli2shop@doli2shop");
// Pas de barre de contexte : export global (le sélecteur n'aurait aucun effet).

print load_fiche_titre($pageTitle, '', 'object_doli2shop@doli2shop');

print '<div class="opacitymedium">' . $langs->trans("OrdersZipExportHelp") . '</div><br>';

if (!$configReady) {
    print info_admin($langs->trans("OrderJsonExportNeedConfig"), 0, 0, 'warning');
} else {
    if (!empty($errorMessage)) {
        print info_admin($errorMessage, 0, 0, 'warning');
    }

    $needConfirm = (count($identifiers) > DOLI2SHOP_ZIP_CONFIRM_THRESHOLD);

    print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="export">';

    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>' . $langs->trans("OrdersZipExportFormTitle") . '</td></tr>';
    print '<tr class="oddeven"><td>';
    print '<div class="opacitymedium">' . $langs->trans("OrdersZipExportInputHint") . '</div>';
    print '<textarea name="identifiers" rows="10" class="quatrevingtpercent" style="width:100%;font-family:monospace;" placeholder="#1234&#10;#1235&#10;1236">' . dol_escape_htmltag($rawList) . '</textarea>';
    print '</td></tr>';
    print '</table>';
    print '</div>';

    if ($needConfirm) {
        print '<div class="center" style="margin-top:8px;">';
        print '<label><input type="checkbox" name="confirm_bulk" value="1"> '
            . $langs->trans("OrdersZipExportConfirmCheckbox", count($identifiers)) . '</label>';
        print '</div>';
    }

    print '<div class="center" style="margin-top:10px;">';
    print '<input type="submit" class="button button-primary" value="' . $langs->trans("OrdersZipExportSubmit") . '">';
    print '</div>';
    print '</form>';

    if (!empty($reportLines)) {
        print '<br>';
        print load_fiche_titre($langs->trans("OrdersZipExportReport"), '', '');
        print '<textarea readonly="readonly" rows="12" class="quatrevingtpercent" style="width:100%;font-family:monospace;">'
            . dol_escape_htmltag(implode("\n", $reportLines)) . '</textarea>';
    }
}

print dol_get_fiche_end();
print '</div>'; // .doli2shop-page

llxFooter();
$db->close();
