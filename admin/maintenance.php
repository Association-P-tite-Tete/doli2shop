<?php
/**
 * @file        admin/maintenance.php
 * @brief       Maintenance and diagnostic tools for Shopify Integration
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.23
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

// Include compatibility functions for older Dolibarr versions (verifToken(), Hotfix 2.4.6)
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Access control
if (!$user->admin) {
    accessforbidden();
}

// Page setup
$page_name = "MaintenanceTools";
llxHeader('', $langs->trans($page_name), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Libraries

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class=\"doli2shop-page\">';
dol_include_once('/doli2shop/class/importproducts.class.php');
dol_include_once('/doli2shop/lib/doli2shop.lib.php');

// Parameters
$action = GETPOST('action', 'aZ09');

// Security token for CSRF protection
if ($action && !GETPOST('token', 'alphanohtml')) {
    accessforbidden('CSRF token missing');
}
// Hotfix 2.4.6 (2/2) : réimplémentait en dur la comparaison au mauvais jeton (`newToken()` =
// jeton de la PROCHAINE requête, jamais celui réellement soumis) — cassé en silence dès que
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégué au helper partagé
// verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
if ($action && !verifToken()) {
    accessforbidden('Invalid CSRF token');
}

// Page title and navigation
$title = $langs->trans("MaintenanceTools");
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

// Onglets — story dix-ecrans-atteignables-par-aucun-lien (Validate 09/09/2026, réserve 1) : cette
// page n'avait aucune barre d'onglets, ce qui la laissait visuellement détachée du module même
// une fois liée depuis doli2shopAdminPrepareHead().
$maintenanceHead = doli2shopAdminPrepareHead();
print dol_get_fiche_head($maintenanceHead, 'maintenance', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');

// Module configuration check
try {
    $importProducts = new ImportProducts($db);
    $config = $importProducts->getConfig();
    if (empty($config->shopify_store_hostname)) {
        dol_htmloutput_errors("Module not configured. Please configure the module first.");
        llxFooter();
        exit;
    }
} catch (Exception $e) {
    // Log detailed error for administrators, show generic message to users
    dol_syslog("Maintenance: Error loading module configuration: " . $e->getMessage(), LOG_ERR);
    dol_htmloutput_errors($langs->trans("ErrorLoadingConfiguration"));
    llxFooter();
    exit;
}

// Actions
if ($action == 'diagnostic') {
    print '<div id="diagnostic-results" style="margin: 20px 0;">';
    print '<h3><i class="fa fa-search"></i> ' . $langs->trans("DiagnosticResults") . '</h3>';
    
    try {
        // Analyze mapping table
        $mappingAnalysis = analyzeMappingTable($db);
        
        // Display results
        displayDiagnosticResults($mappingAnalysis, $langs);
        
    } catch (Exception $e) {
        // Log detailed error, show generic message to prevent information disclosure
        dol_syslog("Maintenance diagnostic error: " . $e->getMessage(), LOG_ERR);
        print '<div class="error">' . $langs->trans("ErrorDuringDiagnostic") . '</div>';
    }
    
    print '</div>';
}

/**
 * Analyze the mapping table for corruption issues
 *
 * @param DoliDB $db Database connection
 * @return array Analysis results
 */
function analyzeMappingTable($db)
{
    global $conf;
    
    $analysis = [
        'total_mappings' => 0,
        'empty_shopify_ids' => 0,
        'empty_variant_ids' => 0,
        'potential_duplicates' => 0,
        'orphaned_mappings' => 0,
        'valid_mappings' => 0,
        'details' => []
    ];
    
    // Count total mappings.
    // Story 63-16 (AC2, audit) : ce total compte des LIGNES de mapping, pas des produits uniques —
    // depuis l'Epic 47-2 un produit synchronisé sur N boutiques y contribue N fois. C'est
    // volontaire (TotalMappings est un total de mappings, pas de produits) et ne se déduplique
    // pas comme synced_simple/synced_with_variants de admin/sync_products.php. Ne pas confondre
    // ce chiffre avec un compte de produits synchronisés.
    $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "doli2shop_products WHERE entity = " . (int)$conf->entity;
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $analysis['total_mappings'] = (int)$obj->total;
    }
    
    // Count empty Shopify product IDs (Issue #46)
    $sql = "SELECT COUNT(*) as empty_ids FROM " . MAIN_DB_PREFIX . "doli2shop_products 
            WHERE entity = " . (int)$conf->entity . " 
            AND (shopifyProductId IS NULL OR shopifyProductId = '' OR shopifyProductId = '0')";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $analysis['empty_shopify_ids'] = (int)$obj->empty_ids;
    }
    
    // Count empty variant IDs
    $sql = "SELECT COUNT(*) as empty_variants FROM " . MAIN_DB_PREFIX . "doli2shop_products 
            WHERE entity = " . (int)$conf->entity . " 
            AND (shopifyVariantId IS NULL OR shopifyVariantId = '' OR shopifyVariantId = '0')";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $analysis['empty_variant_ids'] = (int)$obj->empty_variants;
    }
    
    // Check for potential duplicates (same Dolibarr product mapped more than once to the SAME
    // Shopify store).
    //
    // Story 63-16 (AC2, ajout Validate) : la clé unique de doli2shop_products est désormais
    // (fk_product, entity, fk_product_parent, fk_store) depuis l'Epic 47-2 — un produit
    // légitimement synchronisé sur N boutiques y a N lignes PAR CONCEPTION, jamais un doublon.
    // L'ancien GROUP BY fk_product (sans fk_store) signalait donc TOUT produit multi-boutiques
    // comme un faux "PotentialDuplicatesIssue". Grouper aussi par fk_store restaure la vraie
    // détection : deux lignes pour le MÊME produit ET la MÊME boutique (fk_store identique) ne
    // peuvent provenir que d'une incohérence de fk_product_parent, jamais d'un multi-boutiques
    // légitime.
    //
    // OBSERVATION (code review 63-16, 24/08, non corrigée ici — hors périmètre de cette story) :
    // "la clé unique empêche les doublons" est FAUX pour un produit SIMPLE (fk_product_parent =
    // NULL, sql/llx_doli2shop_products.sql:19). SQL traite NULL comme jamais égal à lui-même dans
    // une contrainte UNIQUE : deux lignes strictement identiques (même fk_product, entity,
    // fk_store, fk_product_parent=NULL) NE VIOLENT PAS la clé unique, et INSERT ... ON DUPLICATE
    // KEY UPDATE ne les bloque pas davantage (chacune est vue comme une ligne "nouvelle"). Cette
    // requête (GROUP BY fk_product, fk_store) est donc le SEUL filet contre cette classe de
    // doublon pour les produits sans parent — pas une redondance avec la clé unique. Story de
    // suivi ouverte : docs/implementation-artifacts/cle-unique-doli2shop-products-null-fk-product-parent.md
    // (backlog, v2.6.0, MEDIUM).
    // LOW 1 (code review 63-16, 24/08) : fk_store n'était pas remonté — un produit dupliqué sur
    // deux boutiques DISTINCTES (ce que ce GROUP BY fk_product, fk_store détecte désormais comme
    // deux groupes séparés, chacun légitimement à COUNT(*) = 1, donc jamais un faux doublon) ne
    // pouvait pas se distinguer à l'affichage d'un vrai doublon (2 lignes MÊME produit ET MÊME
    // boutique) : les deux se seraient lus comme "Produit #42 : N mappings" identique. fk_store
    // ajouté au SELECT/à la structure pour lever cette ambiguïté à l'écran (AC2 du tableau —
    // "corriger ce qui doit l'être").
    $sql = "SELECT fk_product, fk_store, COUNT(*) as mapping_count
            FROM " . MAIN_DB_PREFIX . "doli2shop_products
            WHERE entity = " . (int)$conf->entity . "
            GROUP BY fk_product, fk_store
            HAVING COUNT(*) > 1";
    $resql = $db->query($sql);
    if ($resql) {
        $duplicates = [];
        while ($obj = $db->fetch_object($resql)) {
            $duplicates[] = [
                'dolibarr_id' => $obj->fk_product,
                'fk_store' => (int) $obj->fk_store,
                'mapping_count' => $obj->mapping_count
            ];
            $analysis['potential_duplicates']++;
        }
        $analysis['details']['duplicates'] = $duplicates;
    }
    
    // Check for orphaned mappings (mappings pointing to non-existent Dolibarr products)
    $sql = "SELECT dsp.*, p.ref 
            FROM " . MAIN_DB_PREFIX . "doli2shop_products dsp
            LEFT JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = dsp.fk_product
            WHERE dsp.entity = " . (int)$conf->entity . "
            AND p.rowid IS NULL";
    $resql = $db->query($sql);
    if ($resql) {
        $orphaned = [];
        while ($obj = $db->fetch_object($resql)) {
            $orphaned[] = [
                'mapping_id' => $obj->rowid,
                'dolibarr_id' => $obj->fk_product,
                'shopify_product_id' => $obj->shopifyProductId,
                'shopify_variant_id' => $obj->shopifyVariantId
            ];
            $analysis['orphaned_mappings']++;
        }
        $analysis['details']['orphaned'] = $orphaned;
    }
    
    // Calculate valid mappings
    $analysis['valid_mappings'] = $analysis['total_mappings'] - $analysis['empty_shopify_ids'] - $analysis['orphaned_mappings'];
    
    return $analysis;
}

/**
 * Display diagnostic results in HTML format
 *
 * @param array $analysis Analysis results
 * @param Translate $langs Language handler
 */
function displayDiagnosticResults($analysis, $langs)
{
    // Summary statistics
    print '<div class="diagnostic-summary" style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px;">';
    print '<h4>' . $langs->trans("DiagnosticSummary") . '</h4>';
    print '<div class="row">';
    print '<div class="col-md-3"><strong>' . $langs->trans("TotalMappings") . ':</strong> ' . $analysis['total_mappings'] . '</div>';
    print '<div class="col-md-3"><strong>' . $langs->trans("ValidMappings") . ':</strong> ' . $analysis['valid_mappings'] . '</div>';
    print '<div class="col-md-3"><strong>' . $langs->trans("EmptyShopifyIDs") . ':</strong> <span style="color: ' . ($analysis['empty_shopify_ids'] > 0 ? 'red' : 'green') . ';">' . $analysis['empty_shopify_ids'] . '</span></div>';
    print '<div class="col-md-3"><strong>' . $langs->trans("OrphanedMappings") . ':</strong> <span style="color: ' . ($analysis['orphaned_mappings'] > 0 ? 'orange' : 'green') . ';">' . $analysis['orphaned_mappings'] . '</span></div>';
    print '</div>';
    print '</div>';
    
    // Issues found
    if ($analysis['empty_shopify_ids'] > 0 || $analysis['orphaned_mappings'] > 0 || $analysis['potential_duplicates'] > 0) {
        print '<div class="diagnostic-issues">';
        print '<h4><i class="fa fa-exclamation-triangle" style="color: orange;"></i> ' . $langs->trans("IssuesFound") . '</h4>';
        
        if ($analysis['empty_shopify_ids'] > 0) {
            print '<div class="alert alert-warning">';
            print '<strong>' . $langs->trans("EmptyShopifyIDsIssue") . '</strong><br>';
            print sprintf($langs->trans("EmptyShopifyIDsDescription"), $analysis['empty_shopify_ids']);
            print '</div>';
        }
        
        if ($analysis['orphaned_mappings'] > 0) {
            print '<div class="alert alert-info">';
            print '<strong>' . $langs->trans("OrphanedMappingsIssue") . '</strong><br>';
            print sprintf($langs->trans("OrphanedMappingsDescription"), $analysis['orphaned_mappings']);
            print '</div>';
        }
        
        if ($analysis['potential_duplicates'] > 0) {
            print '<div class="alert alert-warning">';
            print '<strong>' . $langs->trans("PotentialDuplicatesIssue") . '</strong><br>';
            print sprintf($langs->trans("PotentialDuplicatesDescription"), $analysis['potential_duplicates']);
            
            // Show first few duplicates
            if (!empty($analysis['details']['duplicates'])) {
                print '<ul>';
                $count = 0;
                foreach ($analysis['details']['duplicates'] as $duplicate) {
                    if ($count++ >= 5) break; // Show only first 5
                    // LOW 1 (code review 63-16) : le libellé boutique évite qu'un produit dupliqué
                    // sur 2 boutiques distinctes ne s'affiche deux fois à l'identique.
                    $duplicateStoreLabel = $duplicate['fk_store'] > 0
                        ? '#' . $duplicate['fk_store']
                        : $langs->trans("MaintenanceDuplicateStoreLegacy");
                    print '<li>' . sprintf($langs->trans("MaintenanceDuplicateProductStoreLine"), $duplicate['dolibarr_id'], $duplicateStoreLabel, $duplicate['mapping_count']) . '</li>';
                }
                if (count($analysis['details']['duplicates']) > 5) {
                    print '<li><em>' . sprintf($langs->trans("MaintenanceMoreDuplicates"), (count($analysis['details']['duplicates']) - 5)) . '</em></li>';
                }
                print '</ul>';
            }
            print '</div>';
        }
        
        print '</div>';
    } else {
        print '<div class="alert alert-success">';
        print '<i class="fa fa-check"></i> ' . $langs->trans("NoIssuesFound");
        print '</div>';
    }
}

?>

<div class="tabBar tabBarWithBottom">
    <div class="fichecenter">
        
        <!-- Diagnostic Section -->
        <div class="div-table-responsive-no-min" style="margin-top: 20px;">
            <div class="tagtable liste" style="padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                <div class="tagtr">
                    <div class="tagtd">
                        <h3><i class="fa fa-search"></i> <?php echo $langs->trans("DiagnosticTools"); ?></h3>
                        <p><?php echo $langs->trans("DiagnosticToolsDescription"); ?></p>
                        
                        <form method="POST" action="<?php echo dol_escape_htmltag($_SERVER["PHP_SELF"]); ?>" style="margin-top: 15px;">
                            <input type="hidden" name="token" value="<?php echo newToken(); ?>">
                            <input type="hidden" name="action" value="diagnostic">
                            <button type="submit" class="button" style="background: #4CAF50; color: white;">
                                <i class="fa fa-search"></i> <?php echo $langs->trans("RunDiagnostic"); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Section "Outils de récupération" retirée (story dix-ecrans-atteignables-par-aucun-lien,
             AC4, Validate 09/09/2026) : #recover-ids-btn / #start-recovery / #cancel-recovery
             n'étaient reliés à aucun <script> dans ce fichier ni ailleurs dans le module — un
             mockup mort, jamais un bouton cassé. Candidate à une story dédiée si la fonctionnalité
             (récupération assistée des IDs Shopify) est un jour vraiment implémentée. -->

        <!-- Maintenance History Section -->
        <div class="div-table-responsive-no-min" style="margin-top: 20px;">
            <div class="tagtable liste" style="padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
                <div class="tagtr">
                    <div class="tagtd">
                        <h3><i class="fa fa-history"></i> <?php echo $langs->trans("MaintenanceHistory"); ?></h3>
                        <p><?php echo $langs->trans("MaintenanceHistoryDescription"); ?></p>
                        
                        <div style="margin-top: 15px; color: #666;">
                            <em><?php echo $langs->trans("NoMaintenanceHistoryYet"); ?></em>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<?php
// Footer

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

llxFooter();
$db->close();
?>