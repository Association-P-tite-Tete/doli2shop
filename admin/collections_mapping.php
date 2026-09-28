<?php
/**
 * @file        admin/collections_mapping.php
 * @brief       Administration page for collections-categories mapping management
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.0.27
 * @since       2.0.26
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

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

dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/categories/class/categorie.class.php');
dol_include_once('/doli2shop/lib/doli2shop.lib.php');
require_once dirname(__FILE__).'/../class/collectionsutils.class.php';
require_once dirname(__FILE__).'/../class/importcollections.class.php';
require_once dirname(__FILE__).'/../class/collectionsconflictresolver.class.php';

// Check user permissions
if (!$user->admin) {
    accessforbidden();
}

// Load language
$langs->loadLangs(array("admin", "categories", "doli2shop@doli2shop"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$mappingId = GETPOST('mapping_id', 'int');

// Initialize utilities
$collectionsUtils = new CollectionsUtils($db, $conf->entity);
$importCollections = new ImportCollections($db, $conf->entity);
$conflictResolver = new CollectionsConflictResolver($db, $conf->entity);

$message = '';
$error = '';

// CSRF Protection for all actions
if ($action && !verifToken()) {
    accessforbidden('Invalid CSRF token');
}

// Handle actions
if ($action == 'delete_mapping' && $mappingId > 0) {
    if ($collectionsUtils->markAsDeleted($mappingId)) {
        $message = $langs->trans("MappingDeleted");
    } else {
        $error = $langs->trans("ErrorDeletingMapping");
    }
}

if ($action == 'import_collections') {
    $limit = GETPOST('import_limit', 'int') ?: 100;
    $results = $importCollections->importAllCollections($limit);
    
    if ($results['success']) {
        $message = $langs->trans("CollectionsImported", $results['imported'], $results['updated'], $results['skipped']);
        if (!empty($results['errors'])) {
            $error = implode('<br>', $results['errors']);
        }
    } else {
        $error = implode('<br>', $results['errors']);
    }
}

if ($action == 'resolve_conflicts') {
    $results = $conflictResolver->resolveAllConflicts();
    $message = $langs->trans("ConflictsResolved", $results['resolved'], $results['total_conflicts']);
    
    if ($results['failed'] > 0) {
        $error = implode('<br>', $results['errors']);
    }
}

if ($action == 'cleanup_orphaned') {
    $cleaned = $importCollections->cleanupOrphanedMappings();
    $message = $langs->trans("OrphanedMappingsCleaned", $cleaned);
}

// Get mappings for display
$mappings = $collectionsUtils->getAllMappings();
$conflicts = $conflictResolver->detectAllConflicts();

/*
 * View
 */
$page_name = "CollectionsMappingManagement";
llxHeader('', $langs->trans($page_name));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class=\"doli2shop-page\">';
print load_fiche_titre($langs->trans($page_name), '', 'shopify@doli2shop');

// Onglets — story dix-ecrans-atteignables-par-aucun-lien (Validate 09/09/2026, réserve 1) : cette
// page n'avait aucune barre d'onglets, ce qui la laissait visuellement détachée du module même
// une fois liée depuis doli2shopAdminPrepareHead().
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'collectionsmapping', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');

// Show messages
if ($message) {
    print '<div class="ok">'.$message.'</div>';
}
if ($error) {
    print '<div class="error">'.$error.'</div>';
}

// Action buttons
print '<div class="tabsAction">';

print '<form method="POST" action="'.dol_buildpath('/doli2shop/admin/collections_mapping.php', 1).'" style="display: inline-block; margin-right: 10px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="import_collections">';
print '<input type="number" name="import_limit" value="100" min="1" max="1000" placeholder="'.$langs->trans("Limit").'" style="width: 60px; margin-right: 5px;">';
print '<input type="submit" class="butAction" value="'.$langs->trans("ImportCollectionsFromShopify").'">';
print '</form>';

print '<a class="butAction" href="'.dol_buildpath('/doli2shop/admin/collections_mapping.php', 1).'?action=resolve_conflicts&token='.newToken().'">'.$langs->trans("ResolveAllConflicts").'</a>';

print '<a class="butAction" href="'.dol_buildpath('/doli2shop/admin/collections_mapping.php', 1).'?action=cleanup_orphaned&token='.newToken().'">'.$langs->trans("CleanupOrphanedMappings").'</a>';

print '</div>';

// Conflicts section
if (!empty($conflicts)) {
    print '<br>';
    print '<div class="warning">';
    print '<strong>'.$langs->trans("ConflictsDetected", count($conflicts)).'</strong><br>';
    foreach ($conflicts as $conflict) {
        $conflictType = $conflict['type'] === 'deletion' ? $langs->trans("DeletionConflict") : $langs->trans("ModificationConflict");
        if ($conflict['type'] === 'deletion') {
            $deletedSide = $conflict['deleted_side'] === 'dolibarr' ? 'Dolibarr' : 'Shopify';
            print "• {$conflictType}: {$deletedSide} " . $langs->trans("ItemDeleted") . "<br>";
        } else {
            print "• " . htmlspecialchars($conflictType, ENT_QUOTES, 'UTF-8') . ": " . htmlspecialchars($conflict['dolibarr_data']['label'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . "<br>";
        }
    }
    print '</div>';
}

// Mappings table
print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("DolibarrCategory").'</th>';
print '<th>'.$langs->trans("ShopifyCollection").'</th>';
print '<th>'.$langs->trans("SyncDirection").'</th>';
print '<th>'.$langs->trans("LastSyncDate").'</th>';
print '<th>'.$langs->trans("SyncStatus").'</th>';
print '<th>'.$langs->trans("Actions").'</th>';
print '</tr>';

if (empty($mappings)) {
    print '<tr class="oddeven">';
    print '<td colspan="6" class="opacitymedium center">'.$langs->trans("NoMappingsFound").'</td>';
    print '</tr>';
} else {
    foreach ($mappings as $mapping) {
        print '<tr class="oddeven">';
        
        // Dolibarr category
        print '<td>';
        if (!empty($mapping['dolibarr_category_id'])) {
            $category = new Categorie($db);
            if ($category->fetch($mapping['dolibarr_category_id']) > 0) {
                print '<a href="'.DOL_URL_ROOT.'/categories/card.php?id='.((int) $category->id).'&type=0" target="_blank">';
                // Audit sécurité 2026-08-08 (HIGH) : le libellé de catégorie est écrit par tout
                // utilisateur ayant le droit `categorie|creer`, y compris NON administrateur. Sans
                // échappement, il s'exécutait dans la session de l'admin qui consulte cette page
                // (XSS stockée franchissant une frontière de privilège : vol de jeton CSRF, altération
                // des credentials Shopify). Ne jamais retirer dol_escape_htmltag() ici.
                print dol_escape_htmltag($category->label).' ('.((int) $category->id).')';
                print '</a>';
            } else {
                print '<span class="error">'.$langs->trans("CategoryNotFound").' ('.((int) $mapping['dolibarr_category_id']).')</span>';
            }
        } else {
            print '<em>'.$langs->trans("NotSet").'</em>';
        }
        print '</td>';
        
        // Shopify collection
        print '<td>';
        if (!empty($mapping['shopify_collection_id'])) {
            // Audit sécurité 2026-08-08 (MEDIUM) : titre venant de Shopify — échappé pour SQL à
            // l'insertion, mais réaffiché brut ici. Les données Shopify sont des entrées NON FIABLES
            // (convention du projet) : un staff de la boutique ou une app tierce autorisée peut y
            // placer du HTML/JS qui s'exécuterait dans le backoffice Dolibarr.
            print dol_escape_htmltag($mapping['shopify_collection_title'])
                .' ('.dol_escape_htmltag($mapping['shopify_collection_id']).')';
        } else {
            print '<em>'.$langs->trans("NotSet").'</em>';
        }
        print '</td>';
        
        // Sync direction
        print '<td>';
        $directionLabels = [
            'dol_to_shop' => 'Dolibarr → Shopify',
            'shop_to_dol' => 'Shopify → Dolibarr',
            'bidirectional' => $langs->trans("Bidirectional")
        ];
        print $directionLabels[$mapping['sync_direction']] ?? $mapping['sync_direction'];
        print '</td>';
        
        // Last sync date
        print '<td>';
        if (!empty($mapping['last_sync_date'])) {
            print dol_print_date($db->jdate($mapping['last_sync_date']), 'dayhour');
        } else {
            print '<em>'.$langs->trans("Never").'</em>';
        }
        print '</td>';
        
        // Status
        print '<td>';
        $statusClass = '';
        $statusLabel = '';
        switch ($mapping['sync_status']) {
            case 'active':
                $statusClass = 'badge badge-status4';
                $statusLabel = $langs->trans("Active");
                break;
            case 'deleted':
                $statusClass = 'badge badge-status8';
                $statusLabel = $langs->trans("Deleted");
                break;
            default:
                $statusClass = 'badge badge-status0';
                $statusLabel = $mapping['sync_status'];
        }
        print '<span class="'.$statusClass.'">'.$statusLabel.'</span>';
        print '</td>';
        
        // Actions
        print '<td>';
        if ($mapping['sync_status'] === 'active') {
            print '<a class="butActionDelete" href="'.dol_buildpath('/doli2shop/admin/collections_mapping.php', 1).'?action=delete_mapping&mapping_id='.$mapping['rowid'].'&token='.newToken().'" ';
            print 'onclick="return confirm(\''.$langs->trans("ConfirmDeleteMapping").'\')">';
            print $langs->trans("Delete");
            print '</a>';
        }
        print '</td>';
        
        print '</tr>';
    }
}

print '</table>';

// Help section
print '<br>';
print '<div class="info">';
print '<h3>'.$langs->trans("Help").'</h3>';
print '<ul>';
print '<li><strong>'.$langs->trans("ImportCollectionsFromShopify").':</strong> '.$langs->trans("ImportCollectionsHelp").'</li>';
print '<li><strong>'.$langs->trans("ResolveAllConflicts").':</strong> '.$langs->trans("ResolveConflictsHelp").'</li>';
print '<li><strong>'.$langs->trans("CleanupOrphanedMappings").':</strong> '.$langs->trans("CleanupOrphanedHelp").'</li>';
print '</ul>';
print '</div>';

// Include bottom

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

llxFooter();
$db->close();
?>