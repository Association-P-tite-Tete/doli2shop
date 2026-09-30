<?php
/**
 * @file        admin/general.php
 * @brief       Onglet « Général » Doli2Shop — anciennement réglages Clé API Dolibarr + URL hôte
 *
 * Story 57-5 : la clé API Dolibarr self et l'URL hôte self ne sont plus utilisées nulle part dans
 * le module depuis 57-7 (accès direct base+disque pour les images) — leurs champs de saisie et leur
 * affichage sont retirés. Les constantes `DOLI2SHOP_DOLIBARR_HOSTURL`/`DOLI2SHOP_DOLIBARR_API_KEY`
 * ne sont ni supprimées ni migrées : une installation existante qui les porte encore continue de
 * fonctionner à l'identique, elles ne sont simplement plus lues ni écrites.
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.3.9
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

// Load translation files
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Security check — réservé aux admins
if (!$user->admin) {
    accessforbidden();
}

// Story 57-5 : plus aucune action POST sur cet onglet (clé API Dolibarr self et URL hôte self
// retirées — l'ancien traitement `save_general` génération/écriture de la clé, validation et
// écriture de l'URL est retiré avec les champs qu'il alimentait). Ni l'une ni l'autre ne servent
// plus à rien dans le module depuis 57-7 (accès direct base+disque pour les images).

/*
 * View
 */

$page_name = "GeneralTab";

llxHeader('', $langs->trans($page_name));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Onglets de navigation
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'general', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');
// Pas de barre de contexte : réglages communs à toutes les boutiques (le contexte boutique n'a aucun effet ici).

// Story 57-5 : plus aucun réglage sur cet onglet.
print '<div class="opacitymedium">'.$langs->trans("GeneralTabNoSettings").'</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
