<?php
/**
 * @file        class/collectionsutils.class.php
 * @brief       Utility class for managing collections-categories mapping
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.26
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Class for managing collections-categories mapping and synchronization
 */
class CollectionsUtils
{
    use LoggerTrait;

    /**
     * @var DoliDB Database handler
     */
    private $db;

    /**
     * @var int Entity ID for multi-company support
     */
    private $entity;

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
    }

    /**
     * Create or update a collection mapping
     *
     * @param int $dolibarrCategoryId Dolibarr category ID
     * @param string $dolibarrCategoryLabel Dolibarr category label
     * @param string $shopifyCollectionId Shopify collection ID
     * @param string $shopifyCollectionGid Shopify collection GID
     * @param string $shopifyCollectionTitle Shopify collection title
     * @param string $syncDirection Sync direction
     * @return int|false Mapping row ID or false on error
     */
    public function createOrUpdateMapping($dolibarrCategoryId, $dolibarrCategoryLabel, $shopifyCollectionId = null, $shopifyCollectionGid = null, $shopifyCollectionTitle = null, $syncDirection = 'dol_to_shop')
    {
        $this->log("Creating/updating mapping for category $dolibarrCategoryId", LOG_DEBUG);

        // Generate hash for change detection
        $hash = $this->generateSyncHash($dolibarrCategoryLabel, $shopifyCollectionTitle);

        // Check if mapping already exists
        $existingMapping = $this->getMappingByCategoryId($dolibarrCategoryId);
        
        if ($existingMapping) {
            // Update existing mapping
            $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_collections SET";
            $sql .= " dolibarr_category_label = '" . $this->db->escape($dolibarrCategoryLabel) . "'";
            
            if ($shopifyCollectionId) {
                $sql .= ", shopify_collection_id = '" . $this->db->escape($shopifyCollectionId) . "'";
            }
            if ($shopifyCollectionGid) {
                $sql .= ", shopify_collection_gid = '" . $this->db->escape($shopifyCollectionGid) . "'";
            }
            if ($shopifyCollectionTitle) {
                $sql .= ", shopify_collection_title = '" . $this->db->escape($shopifyCollectionTitle) . "'";
            }
            
            $sql .= ", sync_direction = '" . $this->db->escape($syncDirection) . "'";
            $sql .= ", last_sync_date = NOW()";
            $sql .= ", last_sync_hash = '" . $this->db->escape($hash) . "'";
            $sql .= ", sync_status = 'active'";
            $sql .= " WHERE rowid = " . (int)$existingMapping['rowid'];
            
            $result = $this->db->query($sql);
            return $result ? $existingMapping['rowid'] : false;
            
        } else {
            // Create or update mapping using ON DUPLICATE KEY UPDATE
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_collections SET";
            $sql .= " dolibarr_category_id = " . (int)$dolibarrCategoryId;
            $sql .= ", dolibarr_category_label = '" . $this->db->escape($dolibarrCategoryLabel) . "'";
            $sql .= ", sync_direction = '" . $this->db->escape($syncDirection) . "'";
            $sql .= ", last_sync_date = NOW()";
            $sql .= ", last_sync_hash = '" . $this->db->escape($hash) . "'";
            $sql .= ", sync_status = 'active'";
            $sql .= ", entity = " . (int)$this->entity;
            
            if ($shopifyCollectionId) {
                $sql .= ", shopify_collection_id = '" . $this->db->escape($shopifyCollectionId) . "'";
            }
            if ($shopifyCollectionGid) {
                $sql .= ", shopify_collection_gid = '" . $this->db->escape($shopifyCollectionGid) . "'";
            }
            if ($shopifyCollectionTitle) {
                $sql .= ", shopify_collection_title = '" . $this->db->escape($shopifyCollectionTitle) . "'";
            }
            
            // ON DUPLICATE KEY UPDATE pour gérer les doublons automatiquement
            $sql .= " ON DUPLICATE KEY UPDATE";
            $sql .= " dolibarr_category_label = '" . $this->db->escape($dolibarrCategoryLabel) . "'";
            $sql .= ", sync_direction = '" . $this->db->escape($syncDirection) . "'";
            $sql .= ", last_sync_date = NOW()";
            $sql .= ", last_sync_hash = '" . $this->db->escape($hash) . "'";
            $sql .= ", sync_status = 'active'";
            
            if ($shopifyCollectionId) {
                $sql .= ", shopify_collection_id = '" . $this->db->escape($shopifyCollectionId) . "'";
            }
            if ($shopifyCollectionGid) {
                $sql .= ", shopify_collection_gid = '" . $this->db->escape($shopifyCollectionGid) . "'";
            }
            if ($shopifyCollectionTitle) {
                $sql .= ", shopify_collection_title = '" . $this->db->escape($shopifyCollectionTitle) . "'";
            }
            
            $result = $this->db->query($sql);
            if ($result) {
                $insertId = $this->db->last_insert_id(MAIN_DB_PREFIX . "doli2shop_collections");
                return $insertId ? $insertId : $dolibarrCategoryId; // Retourne l'ID inséré ou l'ID de catégorie en cas d'update
            }
            return false;
        }
    }

    /**
     * Get mapping by Dolibarr category ID
     *
     * @param int $categoryId Dolibarr category ID
     * @return array|false Mapping data or false if not found
     */
    public function getMappingByCategoryId($categoryId)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_collections";
        $sql .= " WHERE dolibarr_category_id = " . (int)$categoryId;
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " AND sync_status != 'deleted'";
        
        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result)) {
            return $this->db->fetch_array($result);
        }
        return false;
    }

    /**
     * Get mapping by Shopify collection ID
     *
     * @param string $collectionId Shopify collection ID
     * @return array|false Mapping data or false if not found
     */
    public function getMappingByCollectionId($collectionId)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_collections";
        $sql .= " WHERE shopify_collection_id = '" . $this->db->escape($collectionId) . "'";
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " AND sync_status != 'deleted'";
        
        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result)) {
            return $this->db->fetch_array($result);
        }
        return false;
    }

    /**
     * Get mapping by collection title
     *
     * @param string $title Collection title
     * @return array|false Mapping data or false if not found
     */
    public function getMappingByCollectionTitle($title)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_collections";
        $sql .= " WHERE shopify_collection_title = '" . $this->db->escape($title) . "'";
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " AND sync_status != 'deleted'";
        
        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result)) {
            return $this->db->fetch_array($result);
        }
        return false;
    }

    /**
     * Mark mapping as deleted (soft delete)
     *
     * @param int $mappingId Mapping row ID
     * @return bool Success
     */
    public function markAsDeleted($mappingId)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_collections SET";
        $sql .= " sync_status = 'deleted'";
        $sql .= ", last_sync_date = NOW()";
        $sql .= " WHERE rowid = " . (int)$mappingId;
        // Story 7.3 AC2: entity constraint for multi-tenancy isolation (FR29, FR30)
        $sql .= " AND entity = " . ((int) $this->entity);

        return $this->db->query($sql);
    }

    /**
     * Get all active mappings
     *
     * @param string $syncDirection Optional filter by sync direction
     * @return array Array of mapping data
     */
    public function getAllMappings($syncDirection = null)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_collections";
        $sql .= " WHERE entity = " . (int)$this->entity;
        $sql .= " AND sync_status = 'active'";
        
        if ($syncDirection) {
            $sql .= " AND sync_direction = '" . $this->db->escape($syncDirection) . "'";
        }
        
        $sql .= " ORDER BY dolibarr_category_label ASC";
        
        $result = $this->db->query($sql);
        $mappings = [];
        
        if ($result) {
            while ($row = $this->db->fetch_array($result)) {
                $mappings[] = $row;
            }
        }
        
        return $mappings;
    }

    /**
     * Check if mapping needs update based on hash comparison
     *
     * @param int $categoryId Dolibarr category ID
     * @param string $categoryLabel Current category label
     * @param string $collectionTitle Current collection title
     * @return bool True if update needed
     */
    public function needsUpdate($categoryId, $categoryLabel, $collectionTitle = null)
    {
        $mapping = $this->getMappingByCategoryId($categoryId);
        if (!$mapping) {
            return true; // New mapping needed
        }
        
        $currentHash = $this->generateSyncHash($categoryLabel, $collectionTitle);
        return $mapping['last_sync_hash'] !== $currentHash;
    }

    /**
     * Generate hash for change detection
     *
     * @param string $categoryLabel Dolibarr category label
     * @param string $collectionTitle Shopify collection title
     * @return string MD5 hash
     */
    private function generateSyncHash($categoryLabel, $collectionTitle = null)
    {
        $data = $categoryLabel;
        if ($collectionTitle) {
            $data .= '|' . $collectionTitle;
        }
        return md5($data);
    }

    /**
     * Clean up old mappings for collections that no longer exist
     *
     * @param array $existingCollectionIds Array of existing Shopify collection IDs
     * @return int Number of mappings marked as deleted
     */
    public function cleanupDeletedCollections($existingCollectionIds)
    {
        if (empty($existingCollectionIds)) {
            return 0;
        }
        
        $placeholders = str_repeat('?,', count($existingCollectionIds) - 1) . '?';
        
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_collections SET";
        $sql .= " sync_status = 'deleted'";
        $sql .= ", last_sync_date = NOW()";
        $sql .= " WHERE entity = " . (int)$this->entity;
        $sql .= " AND sync_status = 'active'";
        $sql .= " AND shopify_collection_id IS NOT NULL";
        $sql .= " AND shopify_collection_id NOT IN (" . $placeholders . ")";
        
        // Note: Using direct query since Dolibarr's prepared statements are limited
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_collections SET";
        $sql .= " sync_status = 'deleted'";
        $sql .= ", last_sync_date = NOW()";
        $sql .= " WHERE entity = " . (int)$this->entity;
        $sql .= " AND sync_status = 'active'";
        $sql .= " AND shopify_collection_id IS NOT NULL";
        $sql .= " AND shopify_collection_id NOT IN ('" . implode("','", array_map([$this->db, 'escape'], $existingCollectionIds)) . "')";
        
        $result = $this->db->query($sql);
        return $result ? $this->db->affected_rows($result) : 0;
    }
}