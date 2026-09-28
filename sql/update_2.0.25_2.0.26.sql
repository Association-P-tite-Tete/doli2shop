-- Date: 2025-08-20
-- Version: 2.0.26
-- Description: Restore collections synchronization functionality and add bidirectional sync support
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.25 to 2.0.26

-- =============================================================================
-- NOTE: CRON jobs cleanup is now handled automatically by Dolibarr framework
-- No manual SQL cleanup needed - the module system manages CRONs lifecycle
-- =============================================================================

-- =============================================================================
-- COLLECTIONS: Restore collections synchronization functionality  
-- =============================================================================

-- Create collections mapping table
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_collections_mapping');
SET @sqlstmt := IF(@exist = 0, 
                   'CREATE TABLE llx_shopify_collections_mapping (
                        rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                        dolibarr_category_id INTEGER NOT NULL,
                        dolibarr_category_label VARCHAR(255) NOT NULL,
                        shopify_collection_id VARCHAR(255),
                        shopify_collection_gid VARCHAR(255),
                        shopify_collection_title VARCHAR(255),
                        sync_direction VARCHAR(20) DEFAULT ''dol_to_shop'',
                        last_sync_date DATETIME,
                        last_sync_hash VARCHAR(64),
                        sync_status VARCHAR(20) DEFAULT ''active'',
                        entity INTEGER DEFAULT 1 NOT NULL,
                        tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_category (dolibarr_category_id, entity),
                        INDEX idx_collection (shopify_collection_id, entity),
                        INDEX idx_collection_title (shopify_collection_title, entity),
                        UNIQUE KEY uk_category_entity (dolibarr_category_id, entity),
                        INDEX idx_sync_status (sync_status, entity)
                    ) ENGINE=innodb COMMENT=''Table de mapping entre catégories Dolibarr et collections Shopify pour synchronisation bidirectionnelle''', 
                   'SELECT ''Table llx_shopify_collections_mapping already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sync_product_collections configuration option
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'sync_product_collections');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_product_collections TINYINT(1) DEFAULT 1 COMMENT ''Enable product collections synchronization''', 
                   'SELECT ''Column sync_product_collections already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sync_collections_direction configuration option
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'sync_collections_direction');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_collections_direction VARCHAR(20) DEFAULT ''dol_to_shop'' COMMENT ''Collections sync direction: dol_to_shop, shop_to_dol, bidirectional''', 
                   'SELECT ''Column sync_collections_direction already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sync_collections_conflict_resolution configuration option
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'sync_collections_conflict_resolution');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_collections_conflict_resolution VARCHAR(20) DEFAULT ''dolibarr_priority'' COMMENT ''Conflict resolution: dolibarr_priority, shopify_priority, newest_wins''', 
                   'SELECT ''Column sync_collections_conflict_resolution already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;