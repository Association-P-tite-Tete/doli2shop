-- =====================================================
-- MIGRATION v2.2.9 -> v2.3.0 (DOLI2SHOP)
-- Date: 2026-06-16
-- Version: 2.3.0
-- Description: Ajout colonne last_stock_sync dans llx_doli2shop_products
--              pour découpler le cycle de sync STOCK du cycle de sync CONTENU.
--              Permet un filtre double-critère : contenu (24h) vs stock (15min).
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- =====================================================

-- ============================================================================
-- PARTIE 1 : Ajout de la colonne last_stock_sync (DDL idempotent)
-- Utilise information_schema — pattern idempotent obligatoire (règle projet absolue)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'last_stock_sync');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN last_stock_sync DATETIME DEFAULT NULL COMMENT \'Dernière sync stock effective (push Shopify réussi)\'',
                   'SELECT \'Column last_stock_sync already exists\'');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
-- ============================================================================

-- ============================================================================
-- PARTIE 2 : Création table llx_doli2shop_fulfillment_events (Story 40.1)
-- Historique des événements de tracking Shopify (FulfillmentEvents)
-- Pattern information_schema idempotent (règle projet absolue)
-- ============================================================================

SET @exist_table := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_doli2shop_fulfillment_events');
SET @sqlstmt := IF(@exist_table = 0,
    'CREATE TABLE llx_doli2shop_fulfillment_events (
        rowid                  INT           AUTO_INCREMENT PRIMARY KEY,
        fk_commande            INT           NOT NULL,
        fk_shopify_order_id    VARCHAR(50)   NOT NULL,
        shopify_fulfillment_id VARCHAR(50)   NOT NULL,
        shopify_event_id       VARCHAR(100)  NOT NULL,
        event_status           VARCHAR(100)  NOT NULL,
        happened_at            DATETIME      NOT NULL,
        estimated_delivery_at  DATETIME      NULL,
        message                TEXT          NULL,
        city                   VARCHAR(255)  NULL,
        province               VARCHAR(255)  NULL,
        zip                    VARCHAR(50)   NULL,
        country                VARCHAR(100)  NULL,
        latitude               DECIMAL(10,8) NULL,
        longitude              DECIMAL(11,8) NULL,
        tracking_number        VARCHAR(255)  NULL,
        tracking_company       VARCHAR(255)  NULL,
        tracking_url           TEXT          NULL,
        raw_data               TEXT          NULL,
        entity                 INT           NOT NULL DEFAULT 1,
        date_creation          DATETIME      NOT NULL,
        tms                    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT "Table llx_doli2shop_fulfillment_events already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cle unique d idempotence (shopify_event_id, entity)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_fulfillment_events'
               AND INDEX_NAME = 'uk_doli2shop_fulfillment_event_entity');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_fulfillment_events ADD UNIQUE KEY uk_doli2shop_fulfillment_event_entity (shopify_event_id, entity)',
    'SELECT "Index uk_doli2shop_fulfillment_event_entity already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index perf : requetes par commande Dolibarr
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_fulfillment_events'
               AND INDEX_NAME = 'idx_doli2shop_fe_fk_commande');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_fulfillment_events ADD INDEX idx_doli2shop_fe_fk_commande (fk_commande)',
    'SELECT "Index idx_doli2shop_fe_fk_commande already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index perf : tri happened_at
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_fulfillment_events'
               AND INDEX_NAME = 'idx_doli2shop_fe_happened_at');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_fulfillment_events ADD INDEX idx_doli2shop_fe_happened_at (happened_at)',
    'SELECT "Index idx_doli2shop_fe_happened_at already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3 : Création table llx_doli2shop_action_log (Story 38.3)
-- Journal des actions module hors webhook_events (clôtures auto, skips, erreurs CRON)
-- Pattern information_schema idempotent (règle projet absolue)
-- ============================================================================

SET @exist_table := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_doli2shop_action_log');
SET @sqlstmt := IF(@exist_table = 0,
    'CREATE TABLE llx_doli2shop_action_log (
        rowid         INT          AUTO_INCREMENT PRIMARY KEY,
        date_action   DATETIME     NOT NULL,
        action_type   VARCHAR(50)  NOT NULL,
        object_type   VARCHAR(50)  DEFAULT NULL,
        object_id     INT          DEFAULT NULL,
        result        VARCHAR(20)  DEFAULT NULL,
        message       TEXT         DEFAULT NULL,
        entity        INT          NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT "Table llx_doli2shop_action_log already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index liste/filtre par date (par entité)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_date');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_action_log ADD INDEX idx_doli2shop_action_log_date (entity, date_action)',
    'SELECT "Index idx_doli2shop_action_log_date already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index filtre par type d action (par entité)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_type');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_action_log ADD INDEX idx_doli2shop_action_log_type (entity, action_type)',
    'SELECT "Index idx_doli2shop_action_log_type already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 4 : Index perf stats webhook par topic (Story 36.1)
-- Accélère l'agrégation P50/P95/P99 par type d'événement (évite full scan)
-- Pattern information_schema idempotent (règle projet absolue)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_perf_topic');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_webhook_events ADD INDEX idx_doli2shop_webhook_events_perf_topic (topic, entity, status, processing_time_ms)',
    'SELECT "Index idx_doli2shop_webhook_events_perf_topic already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- Marqueur de migration (traçabilité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.2.9_2.3.0', 'update_2.2.9_2.3.0.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Pour vérifier l'état post-migration :
--   DESCRIBE llx_doli2shop_products;
-- Attendu : colonne last_stock_sync DATETIME DEFAULT NULL présente
-- Les lignes existantes auront last_stock_sync = NULL (pas de backfill —
-- comportement conservateur : prochain cycle CRON déclenchera un push stock)
-- ============================================================================
