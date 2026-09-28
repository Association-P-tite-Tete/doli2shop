-- Description: Save the link between a Dolibarr order and a Shopify order
-- Date: 2026-09-11
-- Version: 2.5.3
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Create table llx_doli2shop_orders
CREATE TABLE llx_doli2shop_orders
(
  id                   integer AUTO_INCREMENT PRIMARY KEY,
  entity               integer DEFAULT 1 NOT NULL,
  fk_commande          int(11) NOT NULL,
  shopifyOrderId       varchar(255) NOT NULL,
  dolOrderFulfillment  varchar(255) NOT NULL,
  -- Store reference (Epic 47 — multi-boutiques)
  fk_store             int(11) NOT NULL DEFAULT 0 COMMENT 'Référence boutique Shopify (0 = non assigné legacy, rowid llx_doli2shop_stores après backfill)',
  -- Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal : marque persistée de
  -- l'écart de totaux Shopify/Dolibarr détecté à la création (null = aucun écart détecté)
  totals_mismatch_severity varchar(20) DEFAULT NULL COMMENT 'null|rounding|proportional',
  shopify_total_ttc    double(20,4) DEFAULT NULL COMMENT 'Total TTC Shopify au moment de la création (si écart détecté)',
  dolibarr_total_ttc   double(20,4) DEFAULT NULL COMMENT 'Total TTC Dolibarr au moment de la création (si écart détecté)',
  tms                  timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

-- Add orders constraints (idempotent)
-- UK étendue à fk_store (Epic 47-2) : un même rowid fk_commande peut exister par boutique
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'uk_doli2shop_orders_save_fk_commande');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD UNIQUE INDEX uk_doli2shop_orders_save_fk_commande (fk_commande, fk_store)',
                   'SELECT "Index uk_doli2shop_orders_save_fk_commande already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND CONSTRAINT_NAME = 'fk_doli2shop_orders_commande');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD CONSTRAINT fk_doli2shop_orders_commande FOREIGN KEY (fk_commande) REFERENCES llx_commande (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key fk_doli2shop_orders_commande already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- UK shopifyOrderId étendue à fk_store (Epic 47-2) : un shopifyOrderId n'est unique que par boutique
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'uk_doli2shop_orders_save_shopify_id');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD UNIQUE INDEX uk_doli2shop_orders_save_shopify_id (shopifyOrderId, fk_store)',
                   'SELECT "Index uk_doli2shop_orders_save_shopify_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur fk_store (Epic 47-2 — performance recherches par boutique)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'idx_doli2shop_orders_fk_store');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD INDEX idx_doli2shop_orders_fk_store (fk_store)',
                   'SELECT "Index idx_doli2shop_orders_fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (AC1) : colonnes ajoutées
-- si la table existait déjà (installation antérieure à cette story) sans elles — no-op si la
-- table vient d'être créée ci-dessus (colonnes déjà présentes).
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'totals_mismatch_severity');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN totals_mismatch_severity varchar(20) DEFAULT NULL COMMENT ''null|rounding|proportional''',
                   'SELECT "Column totals_mismatch_severity already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'shopify_total_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN shopify_total_ttc double(20,4) DEFAULT NULL COMMENT ''Total TTC Shopify au moment de la creation (si ecart detecte)''',
                   'SELECT "Column shopify_total_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'dolibarr_total_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN dolibarr_total_ttc double(20,4) DEFAULT NULL COMMENT ''Total TTC Dolibarr au moment de la creation (si ecart detecte)''',
                   'SELECT "Column dolibarr_total_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur totals_mismatch_severity (recherche/comptage écran diagnostic — story ecart-de-totaux)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'idx_doli2shop_orders_totals_mismatch');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD INDEX idx_doli2shop_orders_totals_mismatch (totals_mismatch_severity, entity)',
                   'SELECT "Index idx_doli2shop_orders_totals_mismatch already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;