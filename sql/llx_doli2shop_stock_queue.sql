-- Date: 2026-07-27
-- Version: 2.4.5
-- Description: Table de file d'attente pour le push stock Dolibarr -> Shopify ASYNCHRONE (Story 52-4).
--              Marqueur "stock a pousser" + timestamp UNIQUEMENT (JAMAIS la valeur de stock : le
--              worker relit toujours la valeur courante en base au moment du drainage). Coalescence
--              N mouvements -> 1 ligne via la contrainte UNIQUE (fk_product, entity) + INSERT ...
--              ON DUPLICATE KEY UPDATE. Le drainage pousse via
--              ShopifyStockTrigger::updateShopifyStock() (inchangee), qui gere elle-meme le
--              multi-boutiques (Epic 47) : une ligne de file = un produit, jamais une boutique.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_stock_queue
(
  -- Primary key
  rowid           integer AUTO_INCREMENT PRIMARY KEY,

  -- Marqueur produit a pousser (JAMAIS de valeur de stock stockee ici)
  fk_product      integer NOT NULL COMMENT 'Produit Dolibarr dont le stock doit etre pousse vers Shopify',

  -- Processing (memes conventions que llx_doli2shop_webhook_events)
  status          tinyint NOT NULL DEFAULT 0 COMMENT '0=pending, 1=done, 2=dead_letter, 3=processing',
  tries           integer NOT NULL DEFAULT 0 COMMENT 'Nombre de tentatives de drainage',
  last_error      text DEFAULT NULL COMMENT 'Dernier message d''erreur du worker',

  -- Timestamps
  date_creation   datetime DEFAULT NULL COMMENT 'Premier enqueue (produit jamais encore en file)',
  date_traitement datetime DEFAULT NULL COMMENT 'Dernier drainage tente (succes ou echec)',
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Dernier enqueue (coalescence) - pilote l''ordre de drainage FIFO',

  -- Dolibarr standard fields
  entity          integer NOT NULL DEFAULT 1
) ENGINE=innodb;

-- Index on entity
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stock_queue'
               AND INDEX_NAME = 'idx_doli2shop_stock_queue_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_stock_queue_entity ON llx_doli2shop_stock_queue (entity)',
                   'SELECT "Index idx_doli2shop_stock_queue_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for CRON processing (pending items, ordre FIFO via tms)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stock_queue'
               AND INDEX_NAME = 'idx_doli2shop_stock_queue_processing');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_stock_queue_processing ON llx_doli2shop_stock_queue (status, tries, entity, tms)',
                   'SELECT "Index idx_doli2shop_stock_queue_processing already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- UNIQUE index (fk_product, entity) : clef de coalescence — un produit n'a JAMAIS plus d'une
-- ligne active par entite (INSERT ... ON DUPLICATE KEY UPDATE s'appuie dessus).
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stock_queue'
               AND INDEX_NAME = 'uk_doli2shop_stock_queue_product');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_doli2shop_stock_queue_product ON llx_doli2shop_stock_queue (fk_product, entity)',
                   'SELECT "Index uk_doli2shop_stock_queue_product already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
