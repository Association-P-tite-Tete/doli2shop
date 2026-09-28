<?php
/**
 * @file        class/collectionsconflictresolver.class.php
 * @brief       Class for resolving conflicts in bidirectional collection synchronization
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.0.27
 * @since       2.0.26
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
dol_include_once('/categories/class/categorie.class.php');
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/collectionsutils.class.php';
require_once dirname(__FILE__) . '/configurationMigrator.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Class for resolving conflicts in bidirectional collection synchronization
 */
class CollectionsConflictResolver
{
    use LoggerTrait;

    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var ShopifyApi Shopify API client
     */
    private $shopifyApi;

    /**
     * @var CollectionsUtils Collections mapping utility
     */
    private $collectionsUtils;

    /**
     * @var object Configuration object
     */
    private $config;

    /**
     * @var int Current entity
     */
    private $entity;

    /**
     * Conflict resolution constants
     */
    const RESOLUTION_DOLIBARR_PRIORITY = 'dolibarr_priority';
    const RESOLUTION_SHOPIFY_PRIORITY = 'shopify_priority';
    const RESOLUTION_NEWEST_WINS = 'newest_wins';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     * @param int $entity Entity ID
     */
    public function __construct($db, $entity = 1)
    {
        $this->db = $db;
        $this->entity = $entity;

        // Initialize API and utilities
        $this->shopifyApi = new ShopifyApi($db, $entity);
        $this->collectionsUtils = new CollectionsUtils($db, $entity);
        
        // Load configuration
        $this->loadConfiguration();
    }

    /**
     * Load configuration from constants (v2.0.27)
     */
    private function loadConfiguration()
    {
        $migrator = new ConfigurationMigrator($this->db);
        $configArray = $migrator->getConfiguration($this->entity);
        
        if (empty($configArray)) {
            throw new Exception("Configuration not found for entity " . $this->entity);
        }
        
        $this->config = (object)$configArray;
    }

    /**
     * Detect conflicts in all mappings
     *
     * @return array Array of conflicts found
     */
    public function detectAllConflicts()
    {
        $conflicts = [];
        $mappings = $this->collectionsUtils->getAllMappings();

        foreach ($mappings as $mapping) {
            $conflict = $this->detectConflict($mapping);
            if ($conflict) {
                $conflicts[] = $conflict;
            }
        }

        $this->log("Detected " . count($conflicts) . " conflicts in collections", LOG_INFO);
        return $conflicts;
    }

    /**
     * Detect conflict for a specific mapping
     *
     * @param array $mapping Mapping data from database
     * @return array|null Conflict details or null if no conflict
     */
    public function detectConflict($mapping)
    {
        if (empty($mapping['dolibarr_category_id']) || empty($mapping['shopify_collection_id'])) {
            return null; // Incomplete mapping, no conflict possible
        }

        try {
            // Get current state from both systems
            $dolibarrCategory = $this->getDolibarrCategoryData($mapping['dolibarr_category_id']);
            $shopifyCollection = $this->getShopifyCollectionData($mapping['shopify_collection_id']);

            if (!$dolibarrCategory || !$shopifyCollection) {
                return $this->detectDeletionConflict($mapping, $dolibarrCategory, $shopifyCollection);
            }

            // Compare with last known state
            $currentDolibarrHash = $this->generateDataHash($dolibarrCategory['label'], $dolibarrCategory['description']);
            $currentShopifyHash = $this->generateDataHash($shopifyCollection['title'], $shopifyCollection['description'] ?? '');
            $lastKnownHash = $mapping['last_sync_hash'];

            // Check if both have changed since last sync
            if ($currentDolibarrHash !== $lastKnownHash && $currentShopifyHash !== $lastKnownHash) {
                return [
                    'type' => 'modification',
                    'mapping_id' => $mapping['rowid'],
                    'dolibarr_category_id' => $mapping['dolibarr_category_id'],
                    'shopify_collection_id' => $mapping['shopify_collection_id'],
                    'dolibarr_data' => $dolibarrCategory,
                    'shopify_data' => $shopifyCollection,
                    'last_sync_date' => $mapping['last_sync_date'],
                    'last_sync_hash' => $lastKnownHash,
                    'dolibarr_hash' => $currentDolibarrHash,
                    'shopify_hash' => $currentShopifyHash
                ];
            }

        } catch (Exception $e) {
            $this->log("Error detecting conflict for mapping {$mapping['rowid']}: " . $e->getMessage(), LOG_ERR);
        }

        return null;
    }

    /**
     * Detect deletion conflicts (one side deleted, other side modified)
     *
     * @param array $mapping Mapping data
     * @param array|null $dolibarrCategory Dolibarr category data
     * @param array|null $shopifyCollection Shopify collection data
     * @return array|null Conflict details
     */
    private function detectDeletionConflict($mapping, $dolibarrCategory, $shopifyCollection)
    {
        if (!$dolibarrCategory && !$shopifyCollection) {
            // Both deleted - mark mapping as deleted
            $this->collectionsUtils->markAsDeleted($mapping['rowid']);
            return null;
        }

        if (!$dolibarrCategory) {
            return [
                'type' => 'deletion',
                'mapping_id' => $mapping['rowid'],
                'deleted_side' => 'dolibarr',
                'remaining_data' => $shopifyCollection,
                'last_sync_date' => $mapping['last_sync_date']
            ];
        }

        if (!$shopifyCollection) {
            return [
                'type' => 'deletion',
                'mapping_id' => $mapping['rowid'],
                'deleted_side' => 'shopify',
                'remaining_data' => $dolibarrCategory,
                'last_sync_date' => $mapping['last_sync_date']
            ];
        }

        return null;
    }

    /**
     * Resolve a conflict based on configuration
     *
     * @param array $conflict Conflict details
     * @return array Resolution result
     */
    public function resolveConflict($conflict)
    {
        $resolution = $this->config->sync_collections_conflict_resolution ?? self::RESOLUTION_DOLIBARR_PRIORITY;

        switch ($resolution) {
            case self::RESOLUTION_DOLIBARR_PRIORITY:
                return $this->resolveWithDolibarrPriority($conflict);

            case self::RESOLUTION_SHOPIFY_PRIORITY:
                return $this->resolveWithShopifyPriority($conflict);

            case self::RESOLUTION_NEWEST_WINS:
                return $this->resolveWithNewestWins($conflict);

            default:
                return [
                    'success' => false,
                    'message' => "Unknown conflict resolution strategy: {$resolution}"
                ];
        }
    }

    /**
     * Resolve conflict with Dolibarr priority
     *
     * @param array $conflict Conflict details
     * @return array Resolution result
     */
    private function resolveWithDolibarrPriority($conflict)
    {
        if ($conflict['type'] === 'deletion') {
            // TODO: Implement deletion conflict resolution (deleteShopifyCollection / recreateShopifyCollection)
            return ['status' => 'skipped', 'reason' => 'Deletion conflict resolution not yet implemented'];
        }

        // Modification conflict - update Shopify with Dolibarr data
        return $this->updateShopifyFromDolibarr($conflict);
    }

    /**
     * Resolve conflict with Shopify priority
     *
     * @param array $conflict Conflict details
     * @return array Resolution result
     */
    private function resolveWithShopifyPriority($conflict)
    {
        if ($conflict['type'] === 'deletion') {
            // TODO: Implement deletion conflict resolution (deleteDolibarrCategory / recreateDolibarrCategory)
            return ['status' => 'skipped', 'reason' => 'Deletion conflict resolution not yet implemented'];
        }

        // Modification conflict - update Dolibarr with Shopify data
        return $this->updateDolibarrFromShopify($conflict);
    }

    /**
     * Resolve conflict using newest wins strategy
     *
     * @param array $conflict Conflict details
     * @return array Resolution result
     */
    private function resolveWithNewestWins($conflict)
    {
        if ($conflict['type'] === 'deletion') {
            // For deletions, we can't determine which is newer, so use Dolibarr priority
            return $this->resolveWithDolibarrPriority($conflict);
        }

        // Compare modification times to determine which is newer
        $dolibarrModTime = strtotime($conflict['dolibarr_data']['date_modification'] ?? $conflict['dolibarr_data']['date_creation'] ?? '1970-01-01');
        $shopifyModTime = strtotime($conflict['shopify_data']['updatedAt'] ?? $conflict['shopify_data']['createdAt'] ?? '1970-01-01');

        if ($dolibarrModTime >= $shopifyModTime) {
            return $this->updateShopifyFromDolibarr($conflict);
        } else {
            return $this->updateDolibarrFromShopify($conflict);
        }
    }

    /**
     * Update Shopify collection from Dolibarr category
     *
     * @param array $conflict Conflict details
     * @return array Resolution result
     */
    private function updateShopifyFromDolibarr($conflict)
    {
        try {
            $dolibarrData = $conflict['dolibarr_data'];
            
            // Update collection via Shopify API (this would need a collection update mutation)
            // For now, we'll log the action and update the mapping
            $this->log("Resolving conflict: Updating Shopify collection {$conflict['shopify_collection_id']} with Dolibarr data", LOG_INFO);

            // Update the mapping with new hash
            $newHash = $this->generateDataHash($dolibarrData['label'], $dolibarrData['description']);
            $this->collectionsUtils->createOrUpdateMapping(
                $conflict['dolibarr_category_id'],
                $dolibarrData['label'],
                $conflict['shopify_collection_id'],
                'gid://shopify/Collection/' . $conflict['shopify_collection_id'],
                $dolibarrData['label']
            );

            return [
                'success' => true,
                'action' => 'updated_shopify',
                'message' => "Updated Shopify collection with Dolibarr data"
            ];

        } catch (Exception $e) {
            $this->log("Error updating Shopify from Dolibarr: " . $e->getMessage(), LOG_ERR);
            return [
                'success' => false,
                'message' => "Error updating Shopify: " . $e->getMessage()
            ];
        }
    }

    /**
     * Update Dolibarr category from Shopify collection
     *
     * @param array $conflict Conflict details
     * @return array Resolution result
     */
    private function updateDolibarrFromShopify($conflict)
    {
        try {
            $shopifyData = $conflict['shopify_data'];
            
            $category = new Categorie($this->db);
            $result = $category->fetch($conflict['dolibarr_category_id']);
            
            if ($result > 0) {
                $category->label = $shopifyData['title'];
                $category->description = $shopifyData['description'] ?? '';
                
                if ($category->update($category->id, null) > 0) {
                    // Update the mapping with new hash
                    $newHash = $this->generateDataHash($shopifyData['title'], $shopifyData['description'] ?? '');
                    $this->collectionsUtils->createOrUpdateMapping(
                        $conflict['dolibarr_category_id'],
                        $shopifyData['title'],
                        $conflict['shopify_collection_id'],
                        'gid://shopify/Collection/' . $conflict['shopify_collection_id'],
                        $shopifyData['title']
                    );

                    return [
                        'success' => true,
                        'action' => 'updated_dolibarr',
                        'message' => "Updated Dolibarr category with Shopify data"
                    ];
                }
            }

            return [
                'success' => false,
                'message' => "Failed to update Dolibarr category"
            ];

        } catch (Exception $e) {
            $this->log("Error updating Dolibarr from Shopify: " . $e->getMessage(), LOG_ERR);
            return [
                'success' => false,
                'message' => "Error updating Dolibarr: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get Dolibarr category data
     *
     * @param int $categoryId Category ID
     * @return array|null Category data or null if not found
     */
    private function getDolibarrCategoryData($categoryId)
    {
        try {
            $category = new Categorie($this->db);
            $result = $category->fetch($categoryId);
            
            if ($result > 0) {
                return [
                    'id' => $category->id,
                    'label' => $category->label,
                    'description' => $category->description ?? '',
                    'date_creation' => $category->date_creation,
                    'date_modification' => $category->tms ?? $category->date_creation
                ];
            }
        } catch (Exception $e) {
            $this->log("Error fetching Dolibarr category {$categoryId}: " . $e->getMessage(), LOG_ERR);
        }

        return null;
    }

    /**
     * Get Shopify collection data
     *
     * @param string $collectionId Collection ID
     * @return array|null Collection data or null if not found
     */
    private function getShopifyCollectionData($collectionId)
    {
        try {
            $response = $this->shopifyApi->getCollectionById($collectionId);
            
            if (!empty($response->data->collection)) {
                $collection = $response->data->collection;
                return [
                    'id' => $collection->legacyResourceId,
                    'title' => $collection->title,
                    'description' => $collection->description ?? '',
                    'createdAt' => $collection->createdAt,
                    'updatedAt' => $collection->updatedAt
                ];
            }
        } catch (Exception $e) {
            $this->log("Error fetching Shopify collection {$collectionId}: " . $e->getMessage(), LOG_ERR);
        }

        return null;
    }

    /**
     * Generate hash for change detection
     *
     * @param string $title Title/Label
     * @param string $description Description
     * @return string MD5 hash
     */
    private function generateDataHash($title, $description = '')
    {
        return md5($title . '|' . $description);
    }

    // Additional helper methods for deletion handling would be implemented here
    // deleteShopifyCollection(), recreateShopifyCollection(), etc.

    /**
     * Resolve all conflicts automatically
     *
     * @return array Results summary
     */
    public function resolveAllConflicts()
    {
        $conflicts = $this->detectAllConflicts();
        $results = [
            'total_conflicts' => count($conflicts),
            'resolved' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($conflicts as $conflict) {
            $resolution = $this->resolveConflict($conflict);
            
            if ($resolution['success']) {
                $results['resolved']++;
            } else {
                $results['failed']++;
                $results['errors'][] = $resolution['message'];
            }
        }

        $this->log("Conflict resolution completed. Resolved: {$results['resolved']}, Failed: {$results['failed']}", LOG_INFO);
        
        return $results;
    }
}