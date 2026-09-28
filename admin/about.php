<?php
/**
 * @file        admin/about.php
 * @brief       About page for Doli2Shop module
 *
 * @package     Doli2Shop
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
dol_include_once('/core/lib/functions2.lib.php');
dol_include_once('/doli2shop/lib/doli2shop.lib.php');

// Translations
$langs->loadLangs(array("errors", "admin", "doli2shop@doli2shop"));

// Access control
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "Doli2ShopAbout";

llxHeader('', $langs->trans($page_name), $help_url);

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Get module info
dol_include_once('/doli2shop/core/modules/modDoli2Shop.class.php');
$tmpmodule = new modDoli2Shop($db);

// === PAGE CONTAINER ===
print '<div class="doli2shop-page">';

// Subheader
$linkback = '<a href="' . dol_escape_htmltag($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header tabs
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), 0, 'doli2shop@doli2shop');
// Pas de barre de contexte : page d'information (le contexte boutique n'a aucun effet ici).

// === INLINE STYLES FOR MODERN DESIGN ===
print '<style>
.about-container {
    max-width: 1000px;
    margin: 0 auto;
}

/* Header Card */
.about-header-card {
    background: linear-gradient(135deg, #029e9c 0%, #026d6b 100%);
    border-radius: 16px;
    padding: 30px;
    color: white;
    display: flex;
    align-items: center;
    gap: 30px;
    margin-bottom: 30px;
    box-shadow: 0 4px 20px rgba(2, 158, 156, 0.3);
}
.about-header-card img {
    width: 100px;
    height: 100px;
    border-radius: 16px;
    background: white;
    padding: 10px;
}
.about-header-info h1 {
    margin: 0 0 10px 0;
    font-size: 28px;
    font-weight: 600;
}
.about-header-info .version-badge {
    display: inline-block;
    background: rgba(255,255,255,0.2);
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 14px;
    margin-right: 10px;
}
.about-header-info .author {
    margin-top: 10px;
    opacity: 0.9;
}

/* Description */
.about-description {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px 25px;
    margin-bottom: 30px;
    border-left: 4px solid #029e9c;
}
.about-description p {
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
    color: #495057;
}

/* Feature Cards Grid */
.feature-cards {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}
@media (max-width: 900px) {
    .feature-cards {
        grid-template-columns: 1fr;
    }
}
.feature-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 25px;
    transition: transform 0.2s, box-shadow 0.2s;
}
.feature-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.feature-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
}
.feature-card-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}
.feature-card-icon.products { background: #e3f2fd; }
.feature-card-icon.orders { background: #fff3e0; }
.feature-card-icon.tech { background: #f3e5f5; }
.feature-card h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: #212529;
}
.feature-card ul {
    margin: 0;
    padding-left: 20px;
    color: #6c757d;
}
.feature-card li {
    margin-bottom: 8px;
    font-size: 14px;
}

/* What\'s New Section */
.whats-new {
    background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
}
.whats-new h2 {
    margin: 0 0 20px 0;
    font-size: 18px;
    color: #212529;
    display: flex;
    align-items: center;
    gap: 10px;
}
.whats-new-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}
@media (max-width: 700px) {
    .whats-new-grid {
        grid-template-columns: 1fr;
    }
}
.whats-new-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 15px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}
.whats-new-item .check {
    color: #026B6A;
    font-weight: bold;
    font-size: 16px;
}
.whats-new-item span {
    font-size: 14px;
    color: #495057;
}

/* Links Section */
.quick-links {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 30px;
}
.quick-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    color: #495057;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s;
}
.quick-link:hover {
    background: #029e9c;
    color: white;
    border-color: #029e9c;
    text-decoration: none;
}
.quick-link.primary {
    background: #029e9c;
    color: white;
    border-color: #029e9c;
}
.quick-link.primary:hover {
    background: #026d6b;
    border-color: #026d6b;
}

/* Legal Footer */
.about-legal {
    text-align: center;
    padding: 20px;
    color: #6c757d;
    font-size: 13px;
    border-top: 1px solid #e9ecef;
}
.about-legal a {
    color: #026B6A;
}
</style>';

// === MAIN CONTENT ===
print '<div class="about-container">';

// --- HEADER CARD ---
print '<div class="about-header-card">';
print '<img src="../img/doli2shop_logo.svg" alt="Doli2Shop" style="width: 80px; height: 80px;">';
print '<div class="about-header-info">';
print '<h1>Doli2Shop</h1>';
print '<span class="version-badge">v' . $tmpmodule->version . '</span>';
// Plancher de version : LU depuis le descripteur du module, jamais code en dur ici.
// Le 10/09/2026, le plancher est passe a 23.0 dans modDoli2Shop.class.php et ce badge
// affichait toujours « Dolibarr 18.0+ » — un client en 18 aurait lu une compatibilite
// que le core aurait refusee a l'activation. Le plancher se code a DEUX endroits :
// le descripteur et l'affichage. Deriver l'affichage du descripteur supprime l'ecart.
$dolMin = is_array($tmpmodule->need_dolibarr_version)
    ? implode('.', array_slice($tmpmodule->need_dolibarr_version, 0, 2))
    : (string) $tmpmodule->need_dolibarr_version;
$dolMax = !empty($tmpmodule->max_dolibarr_version) && is_array($tmpmodule->max_dolibarr_version)
    ? implode('.', array_slice($tmpmodule->max_dolibarr_version, 0, 2))
    : '';
print '<span class="version-badge">Dolibarr ' . dol_escape_htmltag($dolMin)
    . ($dolMax !== '' ? ' - ' . dol_escape_htmltag($dolMax) : '+') . '</span>';
print '<div class="author">' . $langs->trans("Author") . ': Association P\'tite Tete - ' . $langs->trans("License") . ': GPL v3</div>';
print '</div>';
print '</div>';

// --- DESCRIPTION ---
print '<div class="about-description">';
print '<p>' . $langs->trans("ModuleDoli2ShopDesc") . '</p>';
print '</div>';

// --- FEATURE CARDS ---
print '<div class="feature-cards">';

// Products Card
print '<div class="feature-card">';
print '<div class="feature-card-header">';
print '<div class="feature-card-icon products">&#128230;</div>';
print '<h3>' . $langs->trans("ProductSync") . '</h3>';
print '</div>';
print '<ul>';
print '<li>' . $langs->trans("ConfigurableProductSync") . '</li>';
print '<li>' . $langs->trans("VariantSupport") . '</li>';
print '<li>' . $langs->trans("ImageSync") . '</li>';
print '<li>' . $langs->trans("StockSync") . '</li>';
print '<li>' . $langs->trans("MultiLevelPricing") . '</li>';
print '</ul>';
print '</div>';

// Orders Card
print '<div class="feature-card">';
print '<div class="feature-card-header">';
print '<div class="feature-card-icon orders">&#128722;</div>';
print '<h3>' . $langs->trans("OrderSync") . '</h3>';
print '</div>';
print '<ul>';
print '<li>' . $langs->trans("AutoOrderImport") . '</li>';
print '<li>' . $langs->trans("CustomerCreation") . '</li>';
print '<li>' . $langs->trans("InvoiceGeneration") . '</li>';
print '<li>' . $langs->trans("StatusSync") . '</li>';
print '<li>' . $langs->trans("TipHandling") . '</li>';
print '</ul>';
print '</div>';

// Technical Card
print '<div class="feature-card">';
print '<div class="feature-card-header">';
print '<div class="feature-card-icon tech">&#9881;</div>';
print '<h3>' . $langs->trans("TechnicalFeatures") . '</h3>';
print '</div>';
print '<ul>';
print '<li>' . $langs->trans("MultiEntitySupport") . '</li>';
print '<li>' . $langs->trans("CronAutomation") . '</li>';
print '<li>' . $langs->trans("AdvancedLogging") . '</li>';
print '<li>' . $langs->trans("FlexibleMapping") . '</li>';
print '<li>' . $langs->trans("APIIntegration") . '</li>';
print '</ul>';
print '</div>';

// Multi-boutiques Card
print '<div class="feature-card">';
print '<div class="feature-card-header">';
print '<div class="feature-card-icon" style="background: #e8f5e9;">&#127981;</div>';
print '<h3>' . $langs->trans("AboutMultiStoreTitle") . '</h3>';
print '</div>';
print '<ul>';
print '<li>' . $langs->trans("AboutMultiStoreItem1") . '</li>';
print '<li>' . $langs->trans("AboutMultiStoreItem2") . '</li>';
print '<li>' . $langs->trans("AboutMultiStoreItem3") . '</li>';
print '</ul>';
print '<p style="margin-top:12px;font-size:13px;">';
print '<a href="' . dol_buildpath('/doli2shop/admin/stores.php', 1) . '" style="color:#026B6A;">';
print $langs->trans("AboutMultiStoreLink") . ' →';
print '</a>';
print '</p>';
print '</div>';

print '</div>'; // End feature-cards

// --- WHAT'S NEW (version dynamique depuis modDoli2Shop, source unique lib/version.lib.php) ---
print '<div class="whats-new">';
print '<h2>&#127381; ' . $langs->trans("WhatsNew") . ' v' . $tmpmodule->version . '</h2>';
print '<div class="whats-new-grid">';

$newFeatures = array(
    $langs->trans("AboutMigrationDoli2Shop"),
    $langs->trans("AboutSecurityAudit"),
    $langs->trans("AboutCronOrdersToggle"),
    $langs->trans("AboutProductImport"),
    $langs->trans("AboutTablesMigration"),
    $langs->trans("AboutDependenciesUpdate")
);

foreach ($newFeatures as $feature) {
    print '<div class="whats-new-item">';
    print '<span class="check">&#10003;</span>';
    print '<span>' . $feature . '</span>';
    print '</div>';
}

print '</div>'; // End whats-new-grid
print '</div>'; // End whats-new

// --- VIDEO TUTORIALS SECTION ---
print '<div class="whats-new" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a5a 100%); border: none; color: white;">';
print '<h2 style="color: white;">&#127909; ' . $langs->trans("VideoTutorials") . '</h2>';
print '<p style="color: rgba(255,255,255,0.9); margin-bottom: 20px;">' . $langs->trans("VideoTutorialsIntro") . '</p>';
print '<div class="whats-new-grid">';

$videos = array(
    array('num' => '1', 'title' => $langs->trans("Video1Title"), 'duration' => '3-4 min'),
    array('num' => '2', 'title' => $langs->trans("Video2Title"), 'duration' => '4-5 min'),
    array('num' => '3', 'title' => $langs->trans("Video3Title"), 'duration' => '5-6 min'),
    array('num' => '4', 'title' => $langs->trans("Video4Title"), 'duration' => '4-5 min'),
    array('num' => '5', 'title' => $langs->trans("Video5Title"), 'duration' => '3-4 min'),
    array('num' => '6', 'title' => $langs->trans("Video6Title"), 'duration' => '2-3 min')
);

foreach ($videos as $video) {
    print '<a href="https://youtu.be/PLACEHOLDER_VIDEO' . $video['num'] . '" target="_blank" style="text-decoration: none;">';
    print '<div class="whats-new-item" style="background: rgba(255,255,255,0.15); border: none; color: white;">';
    print '<span style="font-size: 20px;">' . $video['num'] . '️⃣</span>';
    print '<span><strong>' . $video['title'] . '</strong><br><small style="opacity: 0.8;">' . $video['duration'] . '</small></span>';
    print '</div>';
    print '</a>';
}

print '</div>'; // End whats-new-grid
print '<div style="text-align: center; margin-top: 15px;">';
print '<a href="https://www.youtube.com/@asso.ptitetete" target="_blank" style="color: white; text-decoration: none; padding: 10px 20px; background: rgba(255,255,255,0.2); border-radius: 8px; display: inline-block;">';
print '<i class="fab fa-youtube"></i> ' . $langs->trans("SubscribeYouTube");
print '</a>';
print '</div>';
print '</div>'; // End video tutorials

// --- QUICK LINKS ---
print '<div class="quick-links">';
print '<a href="https://doli2shop.ptitetete.org" target="_blank" class="quick-link primary">&#127760; ' . $langs->trans("Website") . '</a>';
print '<a href="https://doli2shop.ptitetete.org/documentation" target="_blank" class="quick-link">&#128214; ' . $langs->trans("Documentation") . '</a>';
print '<a href="mailto:doli2shop@ptitetete.org" class="quick-link">&#9993; ' . $langs->trans("Support") . '</a>';
print '<a href="https://www.dolistore.com/product.php?id=2385" target="_blank" class="quick-link">&#128717; DoliStore</a>';
print '</div>';

// --- LEGAL FOOTER ---
print '<div class="about-legal">';
print '<p>Doli2Shop &copy; 2024-2026 <a href="https://www.ptitetete.org" target="_blank">Association P\'tite Tete</a></p>';
print '<p>' . $langs->trans("License") . ': <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank">GNU General Public License v3</a></p>';
print '</div>';

print '</div>'; // End about-container

print dol_get_fiche_end();

print '</div>'; // End doli2shop-page

llxFooter();
$db->close();
