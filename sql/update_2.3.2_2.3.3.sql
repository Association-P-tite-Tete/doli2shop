-- =====================================================
-- MIGRATION v2.3.2 -> v2.3.3 (DOLI2SHOP)
-- Date: 2026-06-24
-- Version: 2.3.3
-- Description: Ajout colonnes fk_categorie_order + fk_categorie_invoice sur
--              llx_doli2shop_stores pour le tag automatique commandes/factures
--              par boutique (catégories typées Dolibarr par boutique).
--              Epic 47, Story 47-5 — Tags / catégories par boutique.
--              La création des catégories (ensureStoreCategories) est effectuée
--              en PHP via StoreCategoryHelper, appelé depuis StoreService::create()
--              et ensureDefaultStore().
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_stores — ADD COLUMN fk_categorie_order
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'fk_categorie_order');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN fk_categorie_order int(11) DEFAULT NULL COMMENT ''Catégorie Dolibarr commandes (TYPE_ORDER) boutique (Story 47-5)''',
    'SELECT "Column fk_categorie_order already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : llx_doli2shop_stores — ADD COLUMN fk_categorie_invoice
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'fk_categorie_invoice');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN fk_categorie_invoice int(11) DEFAULT NULL COMMENT ''Catégorie Dolibarr factures (TYPE_INVOICE) boutique (Story 47-5)''',
    'SELECT "Column fk_categorie_invoice already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.2_2.3.3', 'update_2.3.2_2.3.3.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Note : la création des catégories boutique (TYPE_PRODUCT/ORDER/INVOICE) est
-- effectuée en PHP via StoreCategoryHelper::ensureStoreCategories(), appelé
-- depuis StoreService::create() et ensureDefaultStore().
-- La colonne fk_categorie (produit) existait déjà depuis la Story 47-1.
-- ============================================================================
