-- =====================================================
-- MIGRATION v2.3.5 -> v2.3.6 (DOLI2SHOP)
-- Date: 2026-06-30
-- Version: 2.3.6
-- Description: Ajout colonne fk_categorie_proposal sur llx_doli2shop_stores pour
--              le tag automatique des devis (propositions commerciales) par boutique
--              (catégorie typée Dolibarr TYPE_PROPOSAL='propal' par boutique).
--              Epic 48, Story 48-2 — complétude du modèle de tagging par boutique
--              (produit / commande / facture / DEVIS).
--              La création de la catégorie (ensureStoreCategories) est effectuée en
--              PHP via StoreCategoryHelper ; un backfill idempotent rattrape les
--              boutiques existantes depuis modDoli2Shop::init().
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_stores — ADD COLUMN fk_categorie_proposal
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'fk_categorie_proposal');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN fk_categorie_proposal int(11) DEFAULT NULL COMMENT ''Catégorie Dolibarr devis (TYPE_PROPOSAL) boutique (Story 48-2)''',
    'SELECT "Column fk_categorie_proposal already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.5_2.3.6', 'update_2.3.5_2.3.6.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Note : la création de la catégorie devis (TYPE_PROPOSAL) par boutique est
-- effectuée en PHP via StoreCategoryHelper::ensureStoreCategories(), appelé
-- depuis StoreService::create() (nouvelles boutiques) et via le backfill
-- idempotent sur les boutiques existantes (modDoli2Shop::init()).
-- ============================================================================
