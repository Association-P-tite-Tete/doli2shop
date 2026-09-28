<?php
/**
 * @file        admin/setup_guide.php
 * @brief       Guide de configuration Doli2Shop — écran de renvoi vers la documentation officielle
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.16
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
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Libraries
dol_include_once('/core/lib/admin.lib.php');
require_once '../lib/doli2shop.lib.php';

// Translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

/*
 * View
 */
$page_name = "SetupGuide";
llxHeader('', $langs->trans($page_name));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'guide', $langs->trans($page_name), -1, 'doli2shop@doli2shop');
// Pas de barre de contexte : page de renvoi documentation (le contexte boutique n'a aucun effet ici).

// === INLINE STYLES ===
print '<style>
.guide-container {
    max-width: 900px;
    margin: 0 auto;
    padding-bottom: 30px;
}

/* CTA card — documentation officielle */
.guide-doc-cta {
    background: #029e9c;
    border-radius: 10px;
    padding: 28px 32px;
    margin: 24px 0;
    text-align: center;
}
.guide-doc-cta a {
    display: inline-block;
    padding: 14px 32px;
    background: #ffffff;
    color: #026B6A !important;
    border-radius: 8px;
    text-decoration: none !important;
    font-weight: 700;
    font-size: 16px;
    transition: background 0.2s;
}
.guide-doc-cta a:hover {
    background: #e6fafa !important;
    text-decoration: none !important;
}
.guide-doc-cta p {
    color: rgba(255,255,255,0.9);
    margin: 0 0 18px 0;
    font-size: 15px;
}

/* Étapes résumées */
.guide-steps-new {
    background: #f8f9fa;
    border-left: 4px solid #029e9c;
    border-radius: 8px;
    padding: 22px 28px;
    margin: 24px 0;
}
.guide-steps-new h3 {
    margin: 0 0 16px 0;
    color: #026B6A;
    font-size: 16px;
}
.guide-steps-new ol {
    margin: 0;
    padding-left: 24px;
}
.guide-steps-new ol li {
    padding: 6px 0;
    color: #343a40;
    font-size: 14px;
}

/* Liens rapides */
.guide-quick-links {
    background: #ffffff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px 28px;
    margin: 24px 0;
}
.guide-quick-links h3 {
    margin: 0 0 14px 0;
    color: #495057;
    font-size: 15px;
}
.guide-quick-links ul {
    margin: 0;
    padding-left: 20px;
}
.guide-quick-links ul li {
    padding: 4px 0;
    font-size: 14px;
}
</style>';

// === MAIN CONTENT ===
print '<div class="guide-container">';

// Intro
print '<p>' . $langs->transnoentities('GuideNewIntro') . '</p>';

// CTA documentation officielle
print '<div class="guide-doc-cta">';
print '<p>' . $langs->transnoentities('GuideNewTitle') . '</p>';
print '<a href="https://doli2shop.ptitetete.org" target="_blank" rel="noopener noreferrer">';
print $langs->transnoentities('GuideDocButton');
print '</a>';
print '</div>';

// 4 étapes résumées
print '<div class="guide-steps-new">';
print '<h3>' . $langs->transnoentities('GuideNewStepsTitle') . '</h3>';
print '<ol>';
print '<li>' . $langs->transnoentities('GuideNewStep1') . '</li>';
print '<li>' . $langs->transnoentities('GuideNewStep2') . '</li>';
print '<li>' . $langs->transnoentities('GuideNewStep3') . '</li>';
print '<li>' . $langs->transnoentities('GuideNewStep4') . '</li>';
print '</ol>';
print '</div>';

// Liens internes rapides
print '<div class="guide-quick-links">';
print '<h3>' . $langs->transnoentities('GuideNewQuickLinks') . '</h3>';
print '<ul>';
print '<li><a href="' . dol_buildpath('/doli2shop/admin/stores.php', 1) . '">';
print $langs->transnoentities('GuideNewLinkStores');
print '</a></li>';
print '<li><a href="' . dol_buildpath('/doli2shop/admin/health.php', 1) . '">';
print $langs->transnoentities('GuideNewLinkHealth');
print '</a></li>';
print '</ul>';
print '</div>';

print '</div>'; // End guide-container

print dol_get_fiche_end();
print '</div>'; // End doli2shop-page

llxFooter();
$db->close();
