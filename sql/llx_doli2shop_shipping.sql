-- Date: 2021-06-02 15:00:00
-- Version: 2.1.8
-- Description: Création de la table llx_doli2shop_shipping
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Create table llx_doli2shop_shipping
CREATE TABLE llx_doli2shop_shipping (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    shopify_pattern VARCHAR(255) NOT NULL,
    dol_shipping_method_id INTEGER NOT NULL,
    delivery_days INTEGER DEFAULT 1,
    match_tracking TINYINT DEFAULT 0,
    entity INTEGER DEFAULT 1 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

-- v2.1.8: Add index on entity for multi-entity performance (compatible MySQL 5.7+ / MariaDB 10.x)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping'
               AND INDEX_NAME = 'idx_shopify_shipping_mapping_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_shopify_shipping_mapping_entity ON llx_doli2shop_shipping (entity)',
                   'SELECT "Index idx_shopify_shipping_mapping_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- v2.1.8: Add unique constraint on shopify_pattern + entity to prevent duplicates
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping'
               AND INDEX_NAME = 'uk_shopify_shipping_pattern_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_shopify_shipping_pattern_entity ON llx_doli2shop_shipping (shopify_pattern, entity)',
                   'SELECT "Index uk_shopify_shipping_pattern_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;