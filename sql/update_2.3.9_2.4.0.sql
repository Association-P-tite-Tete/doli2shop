-- =====================================================
-- MIGRATION v2.3.9 -> v2.4.0 (DOLI2SHOP)
-- Date: 2026-07-04
-- Version: 2.4.0
-- Description: Ajout colonne serial_number VARCHAR(50) DEFAULT NULL
--              sur llx_doli2shop_stores pour la liaison de licence DoliStore
--              par boutique (Story 49-12).
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Script IDEMPOTENT. Pattern information_schema obligatoire (INTERDIT IF [NOT] EXISTS).
-- =====================================================


-- ============================================================================
-- PARTIE 1 : ADD COLUMN serial_number VARCHAR(50) DEFAULT NULL
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'serial_number');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN serial_number VARCHAR(50) DEFAULT NULL COMMENT \'Numéro de série DoliStore lié à cette boutique (Story 49-12)\' AFTER license_checked',
    'SELECT "Column serial_number already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : Marqueur de migration (traçabilité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.9_2.4.0', 'update_2.3.9_2.4.0.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN — colonne serial_number ajoutée après license_checked.
-- Valeur NULL par défaut : les boutiques existantes n'ont pas de serial lié.
-- La liaison se fait via admin/stores.php action=activate_store_serial.
-- ============================================================================
