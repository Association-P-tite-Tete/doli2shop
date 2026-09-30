<?php
/**
 * @file        admin/shopify_license.community.php
 * @brief       Stub community edition de la page d'admin licence Doli2Shop
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.3.0
 *
 * Substitué à admin/shopify_license.php au moment de la sync vers le miroir
 * public. La gestion de licence n'a pas de sens en community — cette page
 * affiche un encart explicatif et propose un lien vers la page d'adhésion
 * P'tite Tête (encart in-page plutôt que redirection — meilleure UX, on
 * garde le contexte Dolibarr).
 *
 * Origine : Epic 41 / Story 41.2 (P6 review 41.2, D4=encart).
 */

// Protection contre accès direct et chargement Dolibarr
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
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(['admin', 'doli2shop@doli2shop']);

llxHeader('', $langs->trans('CommunityEditionPageTitle'));

print load_fiche_titre($langs->trans('CommunityEditionTitle'), '', 'title_setup');

print '<div class="info" style="border-left: 4px solid #029e9c; padding: 16px 20px; margin: 20px 0; background-color: #f0fdfc; border-radius: 6px;">';
print '<h3 style="color: #026B6A; margin-top: 0;">' . $langs->trans('CommunityEditionEncartHeading') . '</h3>';
print '<p>' . $langs->trans('CommunityEditionEncartIntro') . '</p>';
print '<p><strong>' . $langs->trans('CommunityEditionBenefitsTitle') . '</strong></p>';
print '<ul>';
print '<li>' . $langs->trans('CommunityEditionBenefitPriority') . '</li>';
print '<li>' . $langs->trans('CommunityEditionBenefitBeta') . '</li>';
print '<li>' . $langs->trans('CommunityEditionBenefitApp') . '</li>';
print '</ul>';
print '<p style="margin-top: 16px;">';
print '<a href="https://www.ptitetete.org/products/shopify-integration-pour-dolibarr-v2-x" target="_blank" rel="noopener noreferrer" class="butAction" style="background-color: #029e9c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">' . $langs->trans('CommunityEditionCtaJoin') . '</a>';
print '&nbsp;&nbsp;';
print '<a href="https://doli2shop.ptitetete.org" target="_blank" rel="noopener noreferrer" class="button">' . $langs->trans('CommunityEditionCtaDocs') . '</a>';
print '</p>';
print '</div>';

print '<p style="color: #888; font-size: 13px; margin-top: 24px;">';
print $langs->trans('CommunityEditionFooterNote');
print '</p>';

llxFooter();
$db->close();
