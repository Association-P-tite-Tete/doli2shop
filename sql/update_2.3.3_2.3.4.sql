-- =====================================================
-- MIGRATION v2.3.3 -> v2.3.4 (DOLI2SHOP)
-- Date: 2026-06-25
-- Version: 2.3.4
-- Description: Ajout colonnes license_status + license_checked sur
--              llx_doli2shop_stores pour le gate de synchronisation
--              par licence boutique (1 licence = 1 boutique).
--              Epic 47, Story 47-6 — Licence par boutique.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_stores — ADD COLUMN license_status
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'license_status');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN license_status varchar(20) NOT NULL DEFAULT ''unknown'' COMMENT ''Statut licence boutique : valid|invalid|unknown (Story 47-6)''',
    'SELECT "Column license_status already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : llx_doli2shop_stores — ADD COLUMN license_checked
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'license_checked');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN license_checked datetime DEFAULT NULL COMMENT ''Dernière vérification licence (Story 47-6)''',
    'SELECT "Column license_checked already exists in llx_doli2shop_stores"');
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
    ('2.3.3_2.3.4', 'update_2.3.3_2.3.4.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
