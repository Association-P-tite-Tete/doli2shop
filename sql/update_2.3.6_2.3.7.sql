-- =====================================================
-- MIGRATION v2.3.6 -> v2.3.7 (DOLI2SHOP)
-- Date: 2026-06-30
-- Version: 2.3.7
-- Description: Ajout de fk_store sur les tables de mapping shipping_rules + payments
--              pour rendre les correspondances expédition et paiement PAR BOUTIQUE
--              (Epic 48, Story 48-4 — wizard/sync per-store). Reconstruction des index
--              uniques pour inclure fk_store (même pattern autorisé sur des boutiques ≠).
--              DDL UNIQUEMENT : le backfill de fk_store (lignes existantes -> boutique par
--              défaut) se fait en PHP APRÈS ensureDefaultStore() (StoreService), car la
--              boutique par défaut n'existe pas encore au moment des migrations SQL.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Script IDEMPOTENT. Pattern information_schema obligatoire (INTERDIT IF [NOT] EXISTS).
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_shipping_rules — ADD COLUMN fk_store
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_shipping_rules ADD COLUMN fk_store INTEGER NOT NULL DEFAULT 0 COMMENT ''FK boutique (0 = boutique par défaut / chemin historique) - Story 48-4''',
    'SELECT "Column fk_store already exists in llx_doli2shop_shipping_rules"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1b : remplacer l'index unique (entity, rule_type, shopify_pattern) par une version
-- incluant fk_store. Drop de l'ancien (s'il existe), puis création du nouveau (s'il manque).
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND INDEX_NAME = 'uk_shipping_rules_pattern');
SET @sqlstmt := IF(@exist > 0,
    'ALTER TABLE llx_doli2shop_shipping_rules DROP INDEX uk_shipping_rules_pattern',
    'SELECT "Index uk_shipping_rules_pattern already dropped"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND INDEX_NAME = 'uk_shipping_rules_pattern_store');
SET @sqlstmt := IF(@exist = 0,
    'CREATE UNIQUE INDEX uk_shipping_rules_pattern_store ON llx_doli2shop_shipping_rules (entity, fk_store, rule_type, shopify_pattern)',
    'SELECT "Index uk_shipping_rules_pattern_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : llx_doli2shop_payments — ADD COLUMN fk_store
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_payments'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_payments ADD COLUMN fk_store INTEGER NOT NULL DEFAULT 0 COMMENT ''FK boutique (0 = boutique par défaut / chemin historique) - Story 48-4''',
    'SELECT "Column fk_store already exists in llx_doli2shop_payments"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2b : remplacer l'index unique (shopify_payment_method, entity) par une version incluant fk_store.
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_payments'
               AND INDEX_NAME = 'uk_shopify_payment_method_entity');
SET @sqlstmt := IF(@exist > 0,
    'ALTER TABLE llx_doli2shop_payments DROP INDEX uk_shopify_payment_method_entity',
    'SELECT "Index uk_shopify_payment_method_entity already dropped"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_payments'
               AND INDEX_NAME = 'uk_payment_method_entity_store');
SET @sqlstmt := IF(@exist = 0,
    'CREATE UNIQUE INDEX uk_payment_method_entity_store ON llx_doli2shop_payments (shopify_payment_method, entity, fk_store)',
    'SELECT "Index uk_payment_method_entity_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3 : Marqueur de migration (traçabilité)
-- ============================================================================
INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.6_2.3.7', 'update_2.3.6_2.3.7.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN — backfill fk_store (lignes existantes -> boutique par défaut) en PHP :
-- StoreService::backfillTechnicalTables(), appelé depuis modDoli2Shop::init()
-- APRÈS ensureDefaultStore(). Filtre conditionnel getStoreId()>0 côté lecture (étape 2).
-- ============================================================================
