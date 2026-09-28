-- =====================================================
-- MIGRATION v2.3.1 -> v2.3.2 (DOLI2SHOP)
-- Date: 2026-06-23
-- Version: 2.3.2
-- Description: Ajout colonne fk_store sur les tables techniques de mapping
--              (products, orders, inventory) + adaptation des contraintes d'unicité
--              pour autoriser un même objet Dolibarr sur N boutiques.
--              Epic 47, Story 47-2 — Référence boutique sur les tables techniques.
--              Le backfill des lignes existantes (fk_store 0 → rowid boutique défaut)
--              est effectué en PHP via StoreService::backfillTechnicalTables(),
--              appelé depuis modDoli2Shop::init() après ensureDefaultStore().
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS et information_schema.STATISTICS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_products
-- ============================================================================

-- 1.1 ADD COLUMN fk_store (idempotent)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_products ADD COLUMN fk_store int(11) NOT NULL DEFAULT 0 COMMENT ''Référence boutique Shopify (0 = non assigné legacy, rowid llx_doli2shop_stores après backfill)''',
    'SELECT "Column fk_store already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1.2 DROP ancienne UK uk_doli2shop_products_unique si elle n'inclut pas encore fk_store
--     Condition : l'UK existe ET ne contient pas encore la colonne fk_store
--     On détecte cela en cherchant l'UK avec fk_store dans ses colonnes
SET @exist_old := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_products'
                   AND INDEX_NAME = 'uk_doli2shop_products_unique'
                   AND COLUMN_NAME = 'fk_store');
-- Si l'UK existe mais sans fk_store → l'ancienne UK doit être droppée
SET @uk_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_products'
                   AND INDEX_NAME = 'uk_doli2shop_products_unique');
SET @sqlstmt := IF(@uk_exists > 0 AND @exist_old = 0,
    'ALTER TABLE llx_doli2shop_products DROP INDEX uk_doli2shop_products_unique',
    'SELECT "UK uk_doli2shop_products_unique already up-to-date or absent"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1.3 ADD nouvelle UK incluant fk_store (idempotent)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'uk_doli2shop_products_unique');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_products ADD UNIQUE INDEX uk_doli2shop_products_unique (fk_product, entity, fk_product_parent, fk_store)',
    'SELECT "Index uk_doli2shop_products_unique already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1.4 ADD INDEX fk_store (idempotent)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'idx_doli2shop_products_fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_products ADD INDEX idx_doli2shop_products_fk_store (fk_store)',
    'SELECT "Index idx_doli2shop_products_fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : llx_doli2shop_orders
-- ============================================================================

-- 2.1 ADD COLUMN fk_store (idempotent)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_orders ADD COLUMN fk_store int(11) NOT NULL DEFAULT 0 COMMENT ''Référence boutique Shopify (0 = non assigné legacy, rowid llx_doli2shop_stores après backfill)''',
    'SELECT "Column fk_store already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2.2 DROP ancienne UK uk_doli2shop_orders_save_fk_commande si sans fk_store
SET @exist_old := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_orders'
                   AND INDEX_NAME = 'uk_doli2shop_orders_save_fk_commande'
                   AND COLUMN_NAME = 'fk_store');
SET @uk_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_orders'
                   AND INDEX_NAME = 'uk_doli2shop_orders_save_fk_commande');
SET @sqlstmt := IF(@uk_exists > 0 AND @exist_old = 0,
    'ALTER TABLE llx_doli2shop_orders DROP INDEX uk_doli2shop_orders_save_fk_commande',
    'SELECT "UK uk_doli2shop_orders_save_fk_commande already up-to-date or absent"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2.3 ADD nouvelle UK fk_commande + fk_store (idempotent)
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

-- 2.4 DROP ancienne UK uk_doli2shop_orders_save_shopify_id si sans fk_store
SET @exist_old := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_orders'
                   AND INDEX_NAME = 'uk_doli2shop_orders_save_shopify_id'
                   AND COLUMN_NAME = 'fk_store');
SET @uk_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_orders'
                   AND INDEX_NAME = 'uk_doli2shop_orders_save_shopify_id');
SET @sqlstmt := IF(@uk_exists > 0 AND @exist_old = 0,
    'ALTER TABLE llx_doli2shop_orders DROP INDEX uk_doli2shop_orders_save_shopify_id',
    'SELECT "UK uk_doli2shop_orders_save_shopify_id already up-to-date or absent"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2.5 ADD nouvelle UK shopifyOrderId + fk_store (idempotent)
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

-- 2.6 ADD INDEX fk_store (idempotent)
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


-- ============================================================================
-- PARTIE 3 : llx_doli2shop_inventory
-- ============================================================================

-- 3.1 ADD COLUMN fk_store (idempotent)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_inventory'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_inventory ADD COLUMN fk_store int(11) NOT NULL DEFAULT 0 COMMENT ''Référence boutique Shopify (0 = non assigné legacy, rowid llx_doli2shop_stores après backfill)''',
    'SELECT "Column fk_store already exists in llx_doli2shop_inventory"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3.2 DROP ancienne UK uk_doli2shop_inventory si sans fk_store
--     Note : UK inline dans le CREATE TABLE initial → même logique de migration
SET @exist_old := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_inventory'
                   AND INDEX_NAME = 'uk_doli2shop_inventory'
                   AND COLUMN_NAME = 'fk_store');
SET @uk_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_inventory'
                   AND INDEX_NAME = 'uk_doli2shop_inventory');
SET @sqlstmt := IF(@uk_exists > 0 AND @exist_old = 0,
    'ALTER TABLE llx_doli2shop_inventory DROP INDEX uk_doli2shop_inventory',
    'SELECT "UK uk_doli2shop_inventory already up-to-date or absent"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3.3 ADD nouvelle UK incluant fk_store (idempotent)
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

-- 3.4 ADD INDEX fk_store (idempotent)
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


-- ============================================================================
-- PARTIE 4 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.1_2.3.2', 'update_2.3.1_2.3.2.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Note : le backfill fk_store (0 → rowid boutique par défaut) est effectué
-- en PHP via StoreService::backfillTechnicalTables(), appelé depuis
-- modDoli2Shop::init() après ensureDefaultStore().
-- Raison : la boutique par défaut est créée par ensureDefaultStore() APRÈS
-- l'exécution des migrations SQL — un backfill SQL ici ne trouverait rien.
-- ============================================================================
