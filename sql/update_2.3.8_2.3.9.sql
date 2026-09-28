-- =====================================================
-- MIGRATION v2.3.8 -> v2.3.9 (DOLI2SHOP)
-- Date: 2026-07-01
-- Version: 2.3.9
-- Description: Ajout colonne fk_store + index sur llx_doli2shop_action_log
--              pour le filtre par boutique dans admin/action_log.php (Story 49-5).
--              Les lignes existantes conservent fk_store NULL (pas de backfill).
--              fk_store NULL = visible dans "Toutes les boutiques" seulement.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Script IDEMPOTENT. Pattern information_schema obligatoire (INTERDIT IF [NOT] EXISTS).
-- =====================================================


-- ============================================================================
-- PARTIE 1 : ADD COLUMN fk_store INTEGER DEFAULT NULL
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_action_log ADD COLUMN fk_store INTEGER DEFAULT NULL COMMENT \'Boutique source (NULL = global/inconnu)\'',
    'SELECT "Column fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : ADD INDEX idx_doli2shop_action_log_store (entity, fk_store)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_store');
SET @sqlstmt := IF(@exist = 0,
    'CREATE INDEX idx_doli2shop_action_log_store ON llx_doli2shop_action_log (entity, fk_store)',
    'SELECT "Index idx_doli2shop_action_log_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3 : Marqueur de migration (traçabilité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.8_2.3.9', 'update_2.3.8_2.3.9.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN — pas de backfill : fk_store NULL sur toutes les lignes existantes.
-- Les nouvelles lignes seront renseignées par les writers (story ultérieure).
-- Filtre par boutique dans action_log.php retourne 0 ligne tant que NULL.
-- ============================================================================
