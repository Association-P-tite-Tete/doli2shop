-- =====================================================
-- MIGRATION v2.3.4 -> v2.3.5 (DOLI2SHOP)
-- Date: 2026-06-25
-- Version: 2.3.5
-- Description: Ajout colonne fk_store sur llx_doli2shop_webhooks
--              pour isoler les webhooks par boutique (désinstallation
--              ciblée via app/uninstalled, traçabilité multi-boutiques).
--              Backfill PHP post-seeding (modDoli2Shop::init).
--              Epic 47, Story 47-7 — Clôture multi-boutiques.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS / information_schema.STATISTICS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- PAS de backfill SQL ici : la migration tourne AVANT ensureDefaultStore().
-- Le backfill est effectué en PHP par StoreService::backfillWebhooksStore()
-- appelée dans modDoli2Shop::init() après ensureDefaultStore().
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_webhooks — ADD COLUMN fk_store
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_webhooks'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_webhooks ADD COLUMN fk_store int(11) NOT NULL DEFAULT 0 COMMENT ''FK store — 0 = boutique défaut / chemin historique (Story 47-7)''',
    'SELECT "Column fk_store already exists in llx_doli2shop_webhooks"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : index fk_store + entity (filtrage ciblé app/uninstalled)
-- ============================================================================

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


-- ============================================================================
-- PARTIE 3 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.4_2.3.5', 'update_2.3.4_2.3.5.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
