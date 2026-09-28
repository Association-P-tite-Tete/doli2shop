-- =====================================================
-- MIGRATION v2.1.8 -> v2.2.0 (DOLI2SHOP)
-- Date: 2026-02-13
-- Version: 2.2.0
-- Description: Idempotence webhooks - Index UNIQUE pour déduplication
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- =====================================================


-- ============================================================================
-- PARTIE 1: NETTOYAGE DES DOUBLONS EVENTUELS
-- ============================================================================
-- Avant de créer l'index UNIQUE, supprimer les éventuels doublons existants.
-- On conserve l'événement avec le rowid le plus élevé (le plus récent).
-- ============================================================================

DELETE e1 FROM llx_doli2shop_webhook_events e1
INNER JOIN llx_doli2shop_webhook_events e2
ON e1.webhook_id = e2.webhook_id
AND e1.entity = e2.entity
AND e1.rowid < e2.rowid
WHERE e1.webhook_id IS NOT NULL;


-- ============================================================================
-- PARTIE 2: SUPPRESSION DE L'ANCIEN INDEX NON-UNIQUE (si existe)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND INDEX_NAME = 'idx_doli2shop_webhook_events_webhook_id');
SET @sqlstmt := IF(@exist > 0,
                   'DROP INDEX idx_doli2shop_webhook_events_webhook_id ON llx_doli2shop_webhook_events',
                   'SELECT "Index idx_doli2shop_webhook_events_webhook_id does not exist, nothing to drop"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3: CREATION DE L'INDEX UNIQUE POUR DEDUPLICATION
-- ============================================================================

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


-- ============================================================================
-- PARTIE 4: AJOUT COLONNE processing_time_ms (Story 1.2)
-- ============================================================================
-- Temps de traitement en millisecondes pour le monitoring de performance
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND COLUMN_NAME = 'processing_time_ms');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_webhook_events ADD COLUMN processing_time_ms INT DEFAULT NULL AFTER date_traitement',
                   'SELECT "Column processing_time_ms already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 5: AJOUT COLONNES CONTEXTE WEBHOOK (Story 3.1)
-- ============================================================================
-- Colonnes de contexte pour le diagnostic : objet Dolibarr traité et résultat
-- ============================================================================

-- Colonne dolibarr_object_type (VARCHAR 50)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND COLUMN_NAME = 'dolibarr_object_type');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_webhook_events ADD COLUMN dolibarr_object_type VARCHAR(50) DEFAULT NULL COMMENT ''Dolibarr object type (commande, facture, shipping, product)'' AFTER processing_time_ms',
                   'SELECT "Column dolibarr_object_type already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Colonne dolibarr_object_id (INT)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND COLUMN_NAME = 'dolibarr_object_id');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_webhook_events ADD COLUMN dolibarr_object_id INT DEFAULT NULL COMMENT ''Dolibarr object rowid'' AFTER dolibarr_object_type',
                   'SELECT "Column dolibarr_object_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Colonne action_result (VARCHAR 20)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhook_events'
               AND COLUMN_NAME = 'action_result');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_webhook_events ADD COLUMN action_result VARCHAR(20) DEFAULT NULL COMMENT ''Result: success, error, duplicate, skipped'' AFTER dolibarr_object_id',
                   'SELECT "Column action_result already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index pour le filtrage par action_result dans le log viewer admin (Story 3.3)
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


-- ============================================================================
-- PARTIE 6: TABLE IMAGE HASHES - Deduplication images (Story 8.2)
-- ============================================================================
-- Cache SHA256 des images produit pour :
-- 1. Deduplication intra-produit (meme contenu binaire = 1 seul upload)
-- 2. Skip-if-unchanged (meme composite hash = pas de re-sync)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_image_hashes');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE TABLE llx_doli2shop_image_hashes (
                       rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                       entity INT NOT NULL DEFAULT 1,
                       fk_product INT NOT NULL COMMENT ''Dolibarr parent product rowid'',
                       images_composite_hash VARCHAR(64) NOT NULL COMMENT ''SHA256 of sorted concatenated individual image hashes'',
                       image_count INT NOT NULL DEFAULT 0 COMMENT ''Number of images included in composite hash'',
                       last_sync DATETIME NOT NULL COMMENT ''Timestamp of last successful image sync''
                   ) ENGINE=innodb DEFAULT CHARSET=utf8 COMMENT=''Image hash cache for deduplication (Story 8.2)''',
                   'SELECT "Table llx_doli2shop_image_hashes already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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


-- ============================================================================
-- PARTIE 7: SUPPRESSION CRON OBSOLETE OrdersImport (Story 14.1)
-- ============================================================================
-- Le CRON OrdersImport est remplacé par Webhooks + OrdersCatchup (v2.2.0)
-- Nettoyage de l'entrée CRON et de la constante de configuration associée
-- ============================================================================

-- Supprimer le CRON ShopifyOrderSyncCron de la table llx_cronjob
DELETE FROM llx_cronjob WHERE objectname = 'ShopifyOrderSyncCron' AND module_name = 'doli2shop';

-- Supprimer la constante DOLI2SHOP_ORDERS_CRON_ENABLED devenue obsolète
DELETE FROM llx_const WHERE name = 'DOLI2SHOP_ORDERS_CRON_ENABLED';


-- ============================================================================
-- PARTIE 8: AJOUT COLONNE match_tracking (Story 19.1)
-- ============================================================================
-- Unifie les 2 systèmes de mapping expédition : table SQL + JSON carrier mapping
-- match_tracking = 1 : le pattern sert aussi au matching tracking_company (expéditions)
-- match_tracking = 0 : le pattern sert uniquement au matching shippingLine.title (commandes)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping'
               AND COLUMN_NAME = 'match_tracking');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_shipping ADD COLUMN match_tracking TINYINT DEFAULT 0 AFTER delivery_days',
                   'SELECT "Column match_tracking already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 9: CRÉATION TABLE doli2shop_shipping_rules (Story 22.1)
-- ============================================================================
-- Remplace doli2shop_shipping (match_tracking booléen) par un modèle à 2 niveaux :
-- rule_type = 'title'    : matche shippingLine.title (à la commande)
-- rule_type = 'tracking' : matche tracking_company (au fulfillment, override le title)
-- Ajoute le champ 'active' pour désactiver sans supprimer.
-- La migration des données se fait en PHP dans modDoli2Shop::migrateShippingToRules()
-- ============================================================================

-- 9a. Créer la table si elle n'existe pas
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE TABLE llx_doli2shop_shipping_rules (
                       rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
                       entity                  INTEGER NOT NULL DEFAULT 1,
                       rule_type               VARCHAR(10) NOT NULL,
                       shopify_pattern         VARCHAR(255) NOT NULL,
                       dol_shipping_method_id  INTEGER NOT NULL,
                       delivery_days           INTEGER DEFAULT 1,
                       active                  TINYINT NOT NULL DEFAULT 1,
                       tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                   ) ENGINE=innodb',
                   'SELECT "Table llx_doli2shop_shipping_rules already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 9b. Index composite entity + rule_type
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND INDEX_NAME = 'idx_shipping_rules_entity_type');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_shipping_rules_entity_type ON llx_doli2shop_shipping_rules (entity, rule_type)',
                   'SELECT "Index idx_shipping_rules_entity_type already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 9c. Contrainte unique (entity, rule_type, shopify_pattern)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND INDEX_NAME = 'uk_shipping_rules_pattern');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_shipping_rules_pattern ON llx_doli2shop_shipping_rules (entity, rule_type, shopify_pattern)',
                   'SELECT "Index uk_shipping_rules_pattern already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
