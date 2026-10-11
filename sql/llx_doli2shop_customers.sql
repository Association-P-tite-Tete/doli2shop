-- ============================================================================
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
-- Date: 2026-10-05
-- Version: 2.6.1
-- Since: 2.6.1
-- Description: Story 66-6 — lien « client Shopify <-> tiers Dolibarr ». Une ligne par
--              (entite, boutique, identifiant client Shopify). L'identifiant client Shopify n'est
--              unique QUE par boutique : un meme tiers peut etre lie a plusieurs clients Shopify
--              (une ligne par boutique), jamais l'inverse. Table vide a la creation : AUCUN backfill.
--              Schema final complet (meme definition que le bloc de update_2.6.0f_2.6.1.sql).
-- ============================================================================

CREATE TABLE llx_doli2shop_customers (
    rowid               INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity              INT NOT NULL DEFAULT 1,
    fk_store            INT NOT NULL DEFAULT 0 COMMENT 'Boutique Shopify (rowid llx_doli2shop_stores, 0 = chemin historique sans boutique)',
    shopify_customer_id VARCHAR(64) NOT NULL COMMENT 'Identifiant client Shopify numerique (sans le prefixe gid://shopify/Customer/)',
    fk_soc              INTEGER NOT NULL,
    tms                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers'
               AND INDEX_NAME = 'uk_doli2shop_customers_key');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_customers ADD UNIQUE INDEX uk_doli2shop_customers_key (entity, fk_store, shopify_customer_id)',
                   'SELECT "Index uk_doli2shop_customers_key already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers'
               AND INDEX_NAME = 'idx_doli2shop_customers_fk_soc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_customers ADD INDEX idx_doli2shop_customers_fk_soc (fk_soc)',
                   'SELECT "Index idx_doli2shop_customers_fk_soc already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FK vers llx_societe (suppression du tiers = suppression du lien), posee SEULEMENT si elle peut
-- l'etre : llx_societe doit etre InnoDB (une install ancienne ou migree peut etre en MyISAM, l'ALTER
-- echouerait) et aucune ligne orpheline ne doit exister. Sinon la table reste pleinement utilisable :
-- la lecture du lien est defensive (jointure sur llx_societe, un lien sans tiers est ignore).
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers'
               AND CONSTRAINT_NAME = 'fk_doli2shop_customers_soc');
SET @societe_innodb := (SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'llx_societe'
                        AND ENGINE = 'InnoDB');
SET @orphans := 0;
SET @sqlstmt := IF(@exist = 0 AND @societe_innodb = 1,
                   'SELECT COUNT(*) INTO @orphans FROM llx_doli2shop_customers c LEFT JOIN llx_societe s ON s.rowid = c.fk_soc WHERE s.rowid IS NULL',
                   'SELECT 0');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sqlstmt := IF(@exist = 0 AND @societe_innodb = 1 AND @orphans = 0,
                   'ALTER TABLE llx_doli2shop_customers ADD CONSTRAINT fk_doli2shop_customers_soc FOREIGN KEY (fk_soc) REFERENCES llx_societe (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key fk_doli2shop_customers_soc already exists or not applicable"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
