-- Date: 2026-02-15
-- Version: 2.2.0
-- Description: Table de stockage des événements webhooks Shopify
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_webhook_events
(
  -- Primary key
  rowid               integer AUTO_INCREMENT PRIMARY KEY,

  -- Webhook identification
  webhook_id          varchar(255) DEFAULT NULL COMMENT 'X-Shopify-Webhook-Id header',
  topic               varchar(255) NOT NULL COMMENT 'Webhook topic (e.g., orders/create)',
  shop_domain         varchar(255) DEFAULT NULL COMMENT 'X-Shopify-Shop-Domain header',
  api_version         varchar(20) DEFAULT NULL COMMENT 'X-Shopify-Api-Version header',

  -- Payload
  payload             longtext DEFAULT NULL COMMENT 'Raw JSON payload',
  hmac_header         varchar(255) DEFAULT NULL COMMENT 'X-Shopify-Hmac-Sha256 header',

  -- Processing
  verified            tinyint NOT NULL DEFAULT 0 COMMENT '0=not verified, 1=HMAC valid',
  status              tinyint NOT NULL DEFAULT 0 COMMENT '0=pending, 1=processed, 2=error, 3=processing',
  tries               integer NOT NULL DEFAULT 0 COMMENT 'Number of processing attempts',
  last_error          text DEFAULT NULL COMMENT 'Last error message',

  -- Timestamps
  date_reception      datetime DEFAULT NULL COMMENT 'When the webhook was received',
  date_traitement     datetime DEFAULT NULL COMMENT 'When the webhook was processed',
  processing_time_ms  int DEFAULT NULL COMMENT 'Processing time in milliseconds',

  -- Dolibarr context (Story 3.1)
  dolibarr_object_type varchar(50) DEFAULT NULL COMMENT 'Dolibarr object type (commande, facture, shipping, product)',
  dolibarr_object_id  int DEFAULT NULL COMMENT 'Dolibarr object rowid',
  action_result       varchar(20) DEFAULT NULL COMMENT 'Result: success, error, duplicate, skipped',

  -- Dolibarr standard fields
  entity              integer NOT NULL DEFAULT 1
) ENGINE=innodb;

-- Index on entity
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhook_events_entity ON llx_doli2shop_webhook_events (entity)',
                   'SELECT "Index idx_doli2shop_webhook_events_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for CRON processing (pending events)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_processing');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhook_events_processing ON llx_doli2shop_webhook_events (status, tries, entity, date_reception)',
                   'SELECT "Index idx_doli2shop_webhook_events_processing already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for purge (processed events by date)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_purge');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhook_events_purge ON llx_doli2shop_webhook_events (status, date_traitement, entity)',
                   'SELECT "Index idx_doli2shop_webhook_events_purge already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- UNIQUE index on webhook_id for idempotency / deduplication (Story 1.1)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'uk_doli2shop_webhook_events_dedup');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_doli2shop_webhook_events_dedup ON llx_doli2shop_webhook_events (webhook_id, entity)',
                   'SELECT "Index uk_doli2shop_webhook_events_dedup already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for log viewer filtering by action_result (Story 3.1 / Story 3.3)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_result');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhook_events_result ON llx_doli2shop_webhook_events (action_result, entity)',
                   'SELECT "Index idx_doli2shop_webhook_events_result already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for performance stats aggregation by topic (Story 36.1)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_perf_topic');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_webhook_events_perf_topic ON llx_doli2shop_webhook_events (topic, entity, status, processing_time_ms)',
                   'SELECT "Index idx_doli2shop_webhook_events_perf_topic already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
