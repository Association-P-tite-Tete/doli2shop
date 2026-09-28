<?php
/**
 * @file        admin/image_duplicates_cleanup.php
 * @brief       Nettoyage des doublons de photos produit créés par l'ancien uniqid() (AC5)
 *
 * Story import-images-duplique-les-photos-a-chaque-synchronisation. Avant le correctif de
 * `ShopifyProductImporter::importProductImages()`, chaque synchronisation (le webhook
 * `products/update` en particulier) recopiait toutes les images du produit sous un nom
 * `<ref>_<uniqid()>.<ext>` — aucun appel ne pouvait reconnaître le fichier déposé par l'appel
 * précédent, d'où une accumulation de doublons parfois vieille de plusieurs semaines. Corriger
 * la source ne nettoie pas l'existant : cette page identifie ces doublons legacy pour les
 * produits mappés Shopify de l'entité courante, et ne supprime QUE sur confirmation explicite
 * après aperçu — jamais un fichier qui ne correspond pas exactement au motif déposé par le
 * module (AC4 : les photos du client ne sont jamais concernées), pattern
 * admin/discount_repair.php / admin/offsale_products.php (aperçu puis application distincte).
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.5.3
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
dol_include_once('/doli2shop/class/imageduplicatescleanupservice.class.php');

// Traductions
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Accès (pattern admin/discount_repair.php, admin/offsale_products.php)
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Sécurité CSRF — pattern partagé du module (verifToken(), JAMAIS de comparaison à newToken(),
// cf. lib/compatibility.lib.php et Hotfix 2.4.6).
if ($action && !GETPOST('token', 'alphanohtml')) {
    accessforbidden('CSRF token missing');
}
if ($action && !verifToken()) {
    accessforbidden('Invalid CSRF token');
}

$imageDuplicatesCleanupService = new ImageDuplicatesCleanupService($db, $conf->entity);

$applyResult = null;

if ($action === 'apply') {
    $selectionRaw = GETPOST('delete', 'array');
    $selection = array();

    if (is_array($selectionRaw)) {
        foreach ($selectionRaw as $entry) {
            // Format "fk_product|filename", cf. valeur des cases à cocher ci-dessous.
            $parts = explode('|', (string) $entry, 2);
            if (count($parts) === 2) {
                $selection[] = array(
                    'fk_product' => (int) $parts[0],
                    'filename' => $parts[1],
                );
            }
        }
    }

    if (!empty($selection)) {
        $applyResult = $imageDuplicatesCleanupService->apply($selection);
    } else {
        $applyResult = array('deleted' => 0, 'errors' => array());
    }
}

// Aperçu recalculé à chaque affichage (avant ET après une application) — jamais mis en cache :
// une sélection appliquée ne doit plus apparaître comme candidate au tour suivant.
$scanReport = $imageDuplicatesCleanupService->scan();

// Page setup
llxHeader('', $langs->trans("ImageDuplicatesCleanupTitle"), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'imageduplicatescleanup', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');

print load_fiche_titre($langs->trans("ImageDuplicatesCleanupTitle"), '', 'title_setup');

print '<div class="d2s-card" style="padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-copy"></i> ' . $langs->trans("ImageDuplicatesCleanupTitle") . '</h3>';
print '<p class="opacitymedium">' . dol_escape_htmltag($langs->trans("ImageDuplicatesCleanupIntro")) . '</p>';
print '<div class="warning" style="margin: 10px 0;">';
print '<strong>' . $langs->trans("Warning") . ':</strong> ' . dol_escape_htmltag($langs->trans("ImageDuplicatesCleanupWarning"));
print '</div>';
print '</div>';

if ($applyResult !== null) {
    if ($applyResult['deleted'] > 0) {
        print '<div class="ok" style="margin: 10px 0;">' . dol_escape_htmltag($langs->trans("ImageDuplicatesCleanupApplied", (string) $applyResult['deleted'])) . '</div>';
    }
    if (!empty($applyResult['errors'])) {
        print '<div class="error" style="margin: 10px 0;">';
        print '<strong>' . $langs->trans("ImageDuplicatesCleanupErrors") . '</strong><ul>';
        foreach ($applyResult['errors'] as $errorMessage) {
            print '<li>' . dol_escape_htmltag($errorMessage) . '</li>';
        }
        print '</ul></div>';
    }
}

if (empty($scanReport)) {
    print '<div class="opacitymedium">' . dol_escape_htmltag($langs->trans("ImageDuplicatesCleanupNoCandidates")) . '</div>';
} else {
    print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="apply">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th></th>';
    print '<th>' . $langs->trans("ImageDuplicatesCleanupColProduct") . '</th>';
    print '<th>' . $langs->trans("ImageDuplicatesCleanupColFile") . '</th>';
    print '<th>' . $langs->trans("ImageDuplicatesCleanupColSize") . '</th>';
    print '<th>' . $langs->trans("ImageDuplicatesCleanupColDate") . '</th>';
    print '<th>' . $langs->trans("ImageDuplicatesCleanupColAction") . '</th>';
    print '</tr>';

    $totalCandidates = 0;

    foreach ($scanReport as $productReport) {
        $fkProduct = $productReport['fk_product'];
        $ref = $productReport['ref'];

        print '<tr class="oddeven">';
        print '<td rowspan="' . (1 + count($productReport['toDelete'])) . '"></td>';
        print '<td rowspan="' . (1 + count($productReport['toDelete'])) . '">' . dol_escape_htmltag($ref) . ' (#' . $fkProduct . ')</td>';
        print '<td>' . dol_escape_htmltag($productReport['keep']['filename']) . '</td>';
        print '<td>' . dol_print_size($productReport['keep']['size']) . '</td>';
        print '<td>' . dol_print_date($productReport['keep']['mtime'], 'dayhour') . '</td>';
        print '<td><span class="badge badge-status4">' . $langs->trans("ImageDuplicatesCleanupKept") . '</span></td>';
        print '</tr>';

        foreach ($productReport['toDelete'] as $fileInfo) {
            $totalCandidates++;
            $checkboxValue = $fkProduct . '|' . $fileInfo['filename'];

            print '<tr class="oddeven">';
            print '<td>' . dol_escape_htmltag($fileInfo['filename']) . '</td>';
            print '<td>' . dol_print_size($fileInfo['size']) . '</td>';
            print '<td>' . dol_print_date($fileInfo['mtime'], 'dayhour') . '</td>';
            print '<td><input type="checkbox" name="delete[]" value="' . dol_escape_htmltag($checkboxValue) . '" checked="checked"></td>';
            print '</tr>';
        }
    }

    print '</table>';

    print '<div style="margin-top: 12px;">';
    print '<button type="submit" class="butAction" onclick="return confirm(' . json_encode($langs->trans("ImageDuplicatesCleanupApplyConfirm", (string) $totalCandidates)) . ');">';
    print '<i class="fa fa-trash"></i> ' . $langs->trans("ImageDuplicatesCleanupApplyButton");
    print '</button>';
    print '</div>';

    print '</form>';
}

print '</div>'; // fin .doli2shop-page

llxFooter();
