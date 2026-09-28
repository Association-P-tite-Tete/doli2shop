-- Date: 2026-06-16
-- Version: 2.3.0
-- Description: Création table llx_doli2shop_fulfillment_events
--              Historique des événements de tracking Shopify (FulfillmentEvents)
--              Persistés lors de chaque synchronisation fulfillment (webhook + catchup)
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_fulfillment_events
(
    rowid                  INT           AUTO_INCREMENT PRIMARY KEY,
    fk_commande            INT           NOT NULL COMMENT 'Dolibarr order rowid (llx_commande.rowid)',
    fk_shopify_order_id    VARCHAR(50)   NOT NULL COMMENT 'Shopify order GID ou numeric ID',
    shopify_fulfillment_id VARCHAR(50)   NOT NULL COMMENT 'Shopify fulfillment GID ou numeric ID',
    shopify_event_id       VARCHAR(100)  NOT NULL COMMENT 'Shopify FulfillmentEvent GID (unicite)',
    event_status           VARCHAR(100)  NOT NULL COMMENT 'FulfillmentEvent status (IN_TRANSIT, DELIVERED, etc.)',
    happened_at            DATETIME      NOT NULL COMMENT 'Date/heure evenement Shopify',
    estimated_delivery_at  DATETIME      NULL     COMMENT 'Date livraison estimee (nullable)',
    message                TEXT          NULL     COMMENT 'Message evenement transporteur',
    city                   VARCHAR(255)  NULL     COMMENT 'Ville de l evenement',
    province               VARCHAR(255)  NULL     COMMENT 'Province/departement',
    zip                    VARCHAR(50)   NULL     COMMENT 'Code postal',
    country                VARCHAR(100)  NULL     COMMENT 'Pays de l evenement',
    latitude               DECIMAL(10,8) NULL     COMMENT 'Latitude GPS (nullable)',
    longitude              DECIMAL(11,8) NULL     COMMENT 'Longitude GPS (nullable)',
    tracking_number        VARCHAR(255)  NULL     COMMENT 'Numero de tracking du fulfillment',
    tracking_company       VARCHAR(255)  NULL     COMMENT 'Transporteur (carrier)',
    tracking_url           TEXT          NULL     COMMENT 'URL de suivi',
    raw_data               TEXT          NULL     COMMENT 'Payload JSON brut du fulfillment',
    entity                 INT           NOT NULL DEFAULT 1 COMMENT 'Multi-tenant entity',
    date_creation          DATETIME      NOT NULL COMMENT 'Date d insertion en base',
    tms                    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cle unique d idempotence : un evenement Shopify ne peut etre insere qu une fois par entite
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_fulfillment_events'
               AND INDEX_NAME = 'uk_doli2shop_fulfillment_event_entity');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_fulfillment_events ADD UNIQUE KEY uk_doli2shop_fulfillment_event_entity (shopify_event_id, entity)',
    'SELECT "Index uk_doli2shop_fulfillment_event_entity already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index perf : requetes par commande Dolibarr (UI timeline)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_fulfillment_events'
               AND INDEX_NAME = 'idx_doli2shop_fe_fk_commande');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_fulfillment_events ADD INDEX idx_doli2shop_fe_fk_commande (fk_commande)',
    'SELECT "Index idx_doli2shop_fe_fk_commande already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index perf : tri par date d evenement
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_fulfillment_events'
               AND INDEX_NAME = 'idx_doli2shop_fe_happened_at');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_fulfillment_events ADD INDEX idx_doli2shop_fe_happened_at (happened_at)',
    'SELECT "Index idx_doli2shop_fe_happened_at already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;
