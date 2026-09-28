-- =====================================================
-- TABLE llx_doli2shop_image_hashes
-- Date: 2026-02-28
-- Version: 2.2.0
-- Description: Cache SHA256 des images produit pour deduplication et skip-if-unchanged (Story 8.2)
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================

CREATE TABLE llx_doli2shop_image_hashes (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INT NOT NULL DEFAULT 1,
    fk_product INT NOT NULL COMMENT 'Dolibarr parent product rowid',
    images_composite_hash VARCHAR(64) NOT NULL COMMENT 'SHA256 of sorted concatenated individual image hashes',
    image_count INT NOT NULL DEFAULT 0 COMMENT 'Number of images included in composite hash',
    last_sync DATETIME NOT NULL COMMENT 'Timestamp of last successful image sync'
) ENGINE=innodb DEFAULT CHARSET=utf8 COMMENT='Image hash cache for deduplication and skip-if-unchanged (Story 8.2)';

-- Unique index: one hash entry per product per entity
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_image_hashes'
               AND INDEX_NAME = 'uk_doli2shop_image_hashes_product');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_doli2shop_image_hashes_product ON llx_doli2shop_image_hashes (fk_product, entity)',
                   'SELECT "Index uk_doli2shop_image_hashes_product already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
