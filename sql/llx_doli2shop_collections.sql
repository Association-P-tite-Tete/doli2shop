-- Date: 2025-08-20
-- Version: 2.0.26
-- Description: Table de mapping entre catégories Dolibarr et collections Shopify
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_collections (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    dolibarr_category_id INTEGER NOT NULL,
    dolibarr_category_label VARCHAR(255) NOT NULL,
    shopify_collection_id VARCHAR(255),
    shopify_collection_gid VARCHAR(255),
    shopify_collection_title VARCHAR(255),
    sync_direction VARCHAR(20) DEFAULT 'dol_to_shop',
    last_sync_date DATETIME,
    last_sync_hash VARCHAR(64),
    sync_status VARCHAR(20) DEFAULT 'active',
    entity INTEGER DEFAULT 1 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (dolibarr_category_id, entity),
    INDEX idx_collection (shopify_collection_id, entity),
    UNIQUE KEY uk_category_entity (dolibarr_category_id, entity),
    INDEX idx_sync_status (sync_status, entity)
) ENGINE=innodb;

-- Index pour les recherches par titre de collection (idempotent)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_collections'
               AND INDEX_NAME = 'idx_collection_title');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_collections ADD INDEX idx_collection_title (shopify_collection_title, entity)',
                   'SELECT "Index idx_collection_title already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Commentaires sur les colonnes
ALTER TABLE llx_doli2shop_collections
    COMMENT = 'Table de mapping entre catégories Dolibarr et collections Shopify pour synchronisation bidirectionnelle';