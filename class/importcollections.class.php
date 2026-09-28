<?php
/**
 * @file        class/importcollections.class.php
 * @brief       Class for importing Shopify collections as Dolibarr categories
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
 * Class for importing Shopify collections as Dolibarr categories
 */
class ImportCollections
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
     * @var array Processed collections to avoid duplicates
     */
    private $processedCollections = [];

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
     * Import all collections from Shopify as categories in Dolibarr
     *
     * @param int $limit Maximum number of collections to process
     * @return array Results summary
     */
    public function importAllCollections($limit = 100)
    {
        // Check if synchronization is enabled and direction allows import
        if (empty($this->config->sync_product_collections) || 
            !in_array($this->config->sync_collections_direction, ['shop_to_dol', 'bidirectional'])) {
            $this->log("Collections import is disabled or direction not allowed", LOG_DEBUG);
            return [
                'success' => false,
                'message' => 'Collections import is disabled',
                'imported' => 0,
                'errors' => []
            ];
        }

        $results = [
            'success' => true,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        try {
            $this->log("Starting import of Shopify collections", LOG_INFO);

            // Get all collections from Shopify
            $collections = $this->getAllShopifyCollections($limit);
            $this->log("Retrieved " . count($collections) . " collections from Shopify", LOG_INFO);

            foreach ($collections as $collection) {
                $result = $this->importSingleCollection($collection);
                
                if ($result['success']) {
                    if ($result['action'] === 'created') {
                        $results['imported']++;
                    } elseif ($result['action'] === 'updated') {
                        $results['updated']++;
                    } else {
                        $results['skipped']++;
                    }
                } else {
                    $results['errors'][] = $result['message'];
                }
            }

        } catch (Exception $e) {
            $this->log("Error during collections import: " . $e->getMessage(), LOG_ERR);
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }

        $this->log("Collections import completed. Imported: {$results['imported']}, Updated: {$results['updated']}, Skipped: {$results['skipped']}, Errors: " . count($results['errors']), LOG_INFO);
        
        return $results;
    }

    /**
     * Import a single collection as a category
     *
     * @param object $collection Shopify collection data
     * @return array Result details
     */
    private function importSingleCollection($collection)
    {
        $collectionId = $collection->legacyResourceId;
        $collectionTitle = $collection->title;

        // Skip if already processed
        if (in_array($collectionId, $this->processedCollections)) {
            return [
                'success' => true,
                'action' => 'skipped',
                'message' => "Collection already processed: {$collectionTitle}"
            ];
        }

        try {
            // Check if mapping already exists
            $mapping = $this->collectionsUtils->getMappingByCollectionId($collectionId);

            if ($mapping && !empty($mapping['dolibarr_category_id'])) {
                // Category already exists - check for updates
                $category = new Categorie($this->db);
                $result = $category->fetch($mapping['dolibarr_category_id']);

                if ($result > 0) {
                    // Category exists - check if update is needed
                    if ($category->label !== $collectionTitle) {
                        $category->label = $collectionTitle;
                        if ($category->update($category->id, null) > 0) {
                            // Update mapping
                            $this->collectionsUtils->createOrUpdateMapping(
                                $category->id,
                                $collectionTitle,
                                $collectionId,
                                $collection->id,
                                $collectionTitle,
                                'shop_to_dol'
                            );
                            
                            $this->processedCollections[] = $collectionId;
                            return [
                                'success' => true,
                                'action' => 'updated',
                                'message' => "Updated category: {$collectionTitle}"
                            ];
                        } else {
                            return [
                                'success' => false,
                                'action' => 'error',
                                'message' => "Failed to update category: {$collectionTitle}"
                            ];
                        }
                    } else {
                        $this->processedCollections[] = $collectionId;
                        return [
                            'success' => true,
                            'action' => 'skipped',
                            'message' => "Category already up to date: {$collectionTitle}"
                        ];
                    }
                } else {
                    // Mapping exists but category was deleted - mark mapping as deleted
                    $this->collectionsUtils->markAsDeleted($mapping['rowid']);
                    $this->log("Marked mapping as deleted - category no longer exists: {$collectionTitle}", LOG_WARNING);
                }
            }

            // Create new category
            $category = new Categorie($this->db);
            $category->label = $collectionTitle;
            $category->type = Categorie::TYPE_PRODUCT; // Product category
            $category->description = "Imported from Shopify collection";
            $category->entity = $this->entity;
            
            // Set parent to configured root category if exists
            if (!empty($this->config->dolibarr_procate)) {
                $category->fk_parent = (int)$this->config->dolibarr_procate;
            }

            $categoryId = $category->create(null);
            
            if ($categoryId > 0) {
                // Create mapping
                $mappingId = $this->collectionsUtils->createOrUpdateMapping(
                    $categoryId,
                    $collectionTitle,
                    $collectionId,
                    $collection->id,
                    $collectionTitle,
                    'shop_to_dol'
                );

                if ($mappingId) {
                    $this->log("Created category and mapping: {$collectionTitle} (Category ID: {$categoryId})", LOG_INFO);
                    $this->processedCollections[] = $collectionId;
                    return [
                        'success' => true,
                        'action' => 'created',
                        'message' => "Created category: {$collectionTitle}"
                    ];
                } else {
                    $this->log("Warning: Created category but failed to create mapping: {$collectionTitle}", LOG_WARNING);
                    return [
                        'success' => true,
                        'action' => 'created',
                        'message' => "Created category but mapping failed: {$collectionTitle}"
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'action' => 'error',
                    'message' => "Failed to create category: {$collectionTitle}. Error: " . implode(', ', $category->errors)
                ];
            }

        } catch (Exception $e) {
            $this->log("Error importing collection {$collectionTitle}: " . $e->getMessage(), LOG_ERR);
            return [
                'success' => false,
                'action' => 'error',
                'message' => "Exception importing {$collectionTitle}: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get all collections from Shopify with pagination
     *
     * @param int $limit Maximum number of collections to retrieve
     * @return array Array of collection objects
     */
    private function getAllShopifyCollections($limit = 100)
    {
        $collections = [];
        $cursor = null;
        $pageSize = min(50, $limit); // Shopify API limit is typically 250, we use 50 for safety
        $retrieved = 0;

        try {
            do {
                $response = $this->shopifyApi->getCollections($pageSize, $cursor);
                
                if (empty($response->data->collections->edges)) {
                    break;
                }

                foreach ($response->data->collections->edges as $edge) {
                    $collections[] = $edge->node;
                    $retrieved++;
                    
                    if ($retrieved >= $limit) {
                        break 2;
                    }
                }

                // Check if there are more pages
                $pageInfo = $response->data->collections->pageInfo ?? null;
                if ($pageInfo && $pageInfo->hasNextPage) {
                    $cursor = end($response->data->collections->edges)->cursor;
                } else {
                    break;
                }

            } while ($retrieved < $limit);

        } catch (Exception $e) {
            $this->log("Error retrieving collections from Shopify: " . $e->getMessage(), LOG_ERR);
            throw $e;
        }

        return $collections;
    }

    /**
     * Sync bidirectional changes between Shopify and Dolibarr
     *
     * @return array Results summary
     */
    public function syncBidirectional()
    {
        if ($this->config->sync_collections_direction !== 'bidirectional') {
            return [
                'success' => false,
                'message' => 'Bidirectional sync is not enabled'
            ];
        }

        // This is a complex feature that would need conflict resolution
        // For now, we'll implement basic bidirectional by running both directions
        
        $results = [
            'success' => true,
            'shopify_to_dolibarr' => $this->importAllCollections(),
            'dolibarr_to_shopify' => [] // This would need integration with ImportProducts
        ];

        return $results;
    }

    /**
     * Clean up orphaned mappings
     *
     * @return int Number of mappings cleaned up
     */
    public function cleanupOrphanedMappings()
    {
        // Get all existing collections from Shopify
        try {
            $shopifyCollections = $this->getAllShopifyCollections(1000);
            $existingIds = array_map(function($collection) {
                return $collection->legacyResourceId;
            }, $shopifyCollections);

            return $this->collectionsUtils->cleanupDeletedCollections($existingIds);
            
        } catch (Exception $e) {
            $this->log("Error cleaning up orphaned mappings: " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }
}