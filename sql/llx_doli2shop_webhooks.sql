-- Date: 2026-02-12
-- Version: 2.1.8
-- Description: Table de gestion des webhooks Shopify enregistrés
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_webhooks
(
  -- Primary key
  rowid               integer AUTO_INCREMENT PRIMARY KEY,

  -- Shopify webhook reference
  webhook_id          varchar(255) DEFAULT NULL COMMENT 'Shopify webhook GID',
  topic               varchar(255) NOT NULL COMMENT 'Webhook topic (e.g., orders/create)',
  url                 text DEFAULT NULL COMMENT 'Callback URL',
  api_version         varchar(20) DEFAULT NULL COMMENT 'Shopify API version',

  -- Status
  status              tinyint NOT NULL DEFAULT 0 COMMENT '0=inactive, 1=active',

  -- Shopify timestamps
  created_at_shopify  datetime DEFAULT NULL,
  updated_at_shopify  datetime DEFAULT NULL,

  -- Multi-boutique reference (Story 47-7)
  fk_store            integer NOT NULL DEFAULT 0 COMMENT 'FK store (0 = default store / historic path)',

  -- Dolibarr standard fields
  datec               datetime DEFAULT NULL COMMENT 'Date creation',
  tms                 timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat       integer DEFAULT NULL,
  fk_user_modif       integer DEFAULT NULL,
  entity              integer NOT NULL DEFAULT 1
) ENGINE=innodb;

-- Index on entity
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhooks'
               AND INDEX_NAME = 'idx_doli2shop_webhooks_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhooks_entity ON llx_doli2shop_webhooks (entity)',
                   'SELECT "Index idx_doli2shop_webhooks_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unique index on webhook_id + entity
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhooks'
               AND INDEX_NAME = 'uk_doli2shop_webhooks_id_entity');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_webhooks ADD UNIQUE INDEX uk_doli2shop_webhooks_id_entity (webhook_id, entity)',
                   'SELECT "Index uk_doli2shop_webhooks_id_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on topic + entity for lookup
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhooks'
               AND INDEX_NAME = 'idx_doli2shop_webhooks_topic_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhooks_topic_entity ON llx_doli2shop_webhooks (topic, entity)',
                   'SELECT "Index idx_doli2shop_webhooks_topic_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index on fk_store + entity for per-store app/uninstalled filtering (Story 47-7)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhooks'
               AND INDEX_NAME = 'idx_doli2shop_webhooks_fk_store');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhooks_fk_store ON llx_doli2shop_webhooks (fk_store, entity)',
                   'SELECT "Index idx_doli2shop_webhooks_fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
