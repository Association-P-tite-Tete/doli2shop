-- Date: 2025-05-22
-- Version: 2.0.22
-- Description: Add sync lock mechanism and fix table structure issues
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.21 to 2.0.22

-- Add entity column if it doesn't exist (for multi-entity support)
SET @exist_entity := (SELECT COUNT(*) FROM information_schema.COLUMNS 
                      WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'llx_dolibarr_shopify_products_save' 
                      AND COLUMN_NAME = 'entity');
SET @sqlstmt_entity := IF(@exist_entity = 0, 
                          'ALTER TABLE llx_dolibarr_shopify_products_save ADD COLUMN entity int(11) NOT NULL DEFAULT 1 AFTER fk_product', 
                          'SELECT "Column entity already exists"');
PREPARE stmt FROM @sqlstmt_entity;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sync_lock column to store lock timestamp
SET @exist_sync_lock := (SELECT COUNT(*) FROM information_schema.COLUMNS 
                         WHERE TABLE_SCHEMA = DATABASE() 
                         AND TABLE_NAME = 'llx_dolibarr_shopify_products_save' 
                         AND COLUMN_NAME = 'sync_lock');
SET @sqlstmt_sync_lock := IF(@exist_sync_lock = 0, 
                             'ALTER TABLE llx_dolibarr_shopify_products_save ADD COLUMN sync_lock DATETIME DEFAULT NULL COMMENT "Timestamp of sync lock to prevent concurrent synchronizations"', 
                             'SELECT "Column sync_lock already exists"');
PREPARE stmt FROM @sqlstmt_sync_lock;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop old unique constraint if it exists (ignore error if doesn't exist)
SET @exist_old_index := (SELECT COUNT(*) FROM information_schema.STATISTICS 
                         WHERE TABLE_SCHEMA = DATABASE() 
                         AND TABLE_NAME = 'llx_dolibarr_shopify_products_save' 
                         AND INDEX_NAME = 'uk_dolibarr_shopify_products_save_fk_product');
SET @sqlstmt_drop_index := IF(@exist_old_index > 0, 
                              'ALTER TABLE llx_dolibarr_shopify_products_save DROP INDEX uk_dolibarr_shopify_products_save_fk_product', 
                              'SELECT "Index uk_dolibarr_shopify_products_save_fk_product does not exist"');
PREPARE stmt FROM @sqlstmt_drop_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add new unique constraint on fk_product and entity (ignore error if exists)
SET @exist_new_index := (SELECT COUNT(*) FROM information_schema.STATISTICS 
                         WHERE TABLE_SCHEMA = DATABASE() 
                         AND TABLE_NAME = 'llx_dolibarr_shopify_products_save' 
                         AND INDEX_NAME = 'uk_dolibarr_shopify_products_save_fk_product_entity');
SET @sqlstmt_add_unique := IF(@exist_new_index = 0, 
                              'ALTER TABLE llx_dolibarr_shopify_products_save ADD UNIQUE INDEX uk_dolibarr_shopify_products_save_fk_product_entity (fk_product, entity)', 
                              'SELECT "Index uk_dolibarr_shopify_products_save_fk_product_entity already exists"');
PREPARE stmt FROM @sqlstmt_add_unique;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for entity (ignore error if exists)
SET @exist_entity_index := (SELECT COUNT(*) FROM information_schema.STATISTICS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'llx_dolibarr_shopify_products_save' 
                            AND INDEX_NAME = 'idx_dolibarr_shopify_products_save_entity');
SET @sqlstmt_add_index := IF(@exist_entity_index = 0, 
                             'ALTER TABLE llx_dolibarr_shopify_products_save ADD INDEX idx_dolibarr_shopify_products_save_entity (entity)', 
                             'SELECT "Index idx_dolibarr_shopify_products_save_entity already exists"');
PREPARE stmt FROM @sqlstmt_add_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing rows to set entity value from products table
UPDATE llx_dolibarr_shopify_products_save ps 
JOIN llx_product p ON ps.fk_product = p.rowid
SET ps.entity = COALESCE(p.entity, 1)
WHERE ps.entity = 1 OR ps.entity IS NULL;

-- Add price_priority_ttc column for multi-price mode configuration
SET @exist_price_priority := (SELECT COUNT(*) FROM information_schema.COLUMNS 
                              WHERE TABLE_SCHEMA = DATABASE() 
                              AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
                              AND COLUMN_NAME = 'price_priority_ttc');
SET @sqlstmt_price_priority := IF(@exist_price_priority = 0, 
                                  'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN price_priority_ttc TINYINT DEFAULT 0 NOT NULL COMMENT "Prioritize stored VAT-inclusive prices over calculated prices" AFTER sync_price_level', 
                                  'SELECT "Column price_priority_ttc already exists"');
PREPARE stmt FROM @sqlstmt_price_priority;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;