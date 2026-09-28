-- ============================================================================
-- Copyright (C) 2022-2025 Robert Steinbacher <robert.steinbacher@xivtech.de>
-- Copyright (C) 2022-2025 Thomas Meigen <info@meigensmartsolutions.de>
-- Copyright (C) 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--
-- ============================================================================
-- Date: 2026-06-23
-- Version: 2.3.2
-- Description: Ajout colonne fk_store et UK étendue (Epic 47-2 — multi-boutiques)
-- ============================================================================

CREATE TABLE llx_doli2shop_inventory (
    rowid                     INT AUTO_INCREMENT PRIMARY KEY,
    fk_product                INT NOT NULL,
    shopify_inventory_item_id VARCHAR(255) NOT NULL,
    entity                    INT NOT NULL DEFAULT 1,
    -- Store reference (Epic 47 — multi-boutiques)
    fk_store                  INT NOT NULL DEFAULT 0 COMMENT 'Référence boutique Shopify (0 = non assigné legacy, rowid llx_doli2shop_stores après backfill)',
    fk_user_creat             INT NULL,
    fk_user_modif             INT NULL,
    date_creation             DATETIME NOT NULL,
    tms                       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_doli2shop_inventory_product FOREIGN KEY (fk_product) REFERENCES llx_product(rowid) ON DELETE CASCADE
) ENGINE=InnoDB;

-- UK étendue à fk_store (Epic 47-2) : un item inventory n'est unique que par boutique + entité.
-- Déclarée via ALTER idempotent (cohérent avec products/orders) plutôt qu'inline,
-- pour que ce fichier self-heal une table existante chargée par _load_tables.
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_inventory'
               AND INDEX_NAME = 'uk_doli2shop_inventory');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_inventory ADD UNIQUE INDEX uk_doli2shop_inventory (shopify_inventory_item_id, entity, fk_store)',
                   'SELECT "Index uk_doli2shop_inventory already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur fk_store (Epic 47-2 — performance recherches par boutique)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_inventory'
               AND INDEX_NAME = 'idx_doli2shop_inventory_fk_store');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_inventory ADD INDEX idx_doli2shop_inventory_fk_store (fk_store)',
                   'SELECT "Index idx_doli2shop_inventory_fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;