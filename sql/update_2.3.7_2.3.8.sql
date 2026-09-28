-- =====================================================
-- MIGRATION v2.3.7 -> v2.3.8 (DOLI2SHOP)
-- Date: 2026-06-30
-- Version: 2.3.8
-- Description: Ajout table llx_doli2shop_store_settings — réglages key-value par boutique
--              avec fallback global (Story 49-3). Foundation infra réglages per-store.
--              Aucun backfill : la table reste vide à l'init ; StoreSettings::get() retombe
--              sur getDolGlobalString('DOLI2SHOP_*') — invariant mono-boutique préservé.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Script IDEMPOTENT. Pattern information_schema obligatoire (INTERDIT IF [NOT] EXISTS).
-- =====================================================


-- ============================================================================
-- PARTIE 1 : Création de la table llx_doli2shop_store_settings
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_store_settings');
SET @sqlstmt := IF(@exist = 0,
    'CREATE TABLE llx_doli2shop_store_settings (rowid integer AUTO_INCREMENT PRIMARY KEY, entity int NOT NULL DEFAULT 1, fk_store int NOT NULL, setting_key varchar(128) NOT NULL, setting_value text DEFAULT NULL, tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "Table llx_doli2shop_store_settings already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : Index UNIQUE uk_doli2shop_store_settings_key (entity, fk_store, setting_key)
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_store_settings'
               AND INDEX_NAME = 'uk_doli2shop_store_settings_key');
SET @sqlstmt := IF(@exist = 0,
    'CREATE UNIQUE INDEX uk_doli2shop_store_settings_key ON llx_doli2shop_store_settings (entity, fk_store, setting_key)',
    'SELECT "Index uk_doli2shop_store_settings_key already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- Note (review 49-3) : pas d'index (entity) séparé — l'UNIQUE (entity, fk_store, setting_key)
-- couvre déjà les lookups par entity seul (préfixe gauche).


-- ============================================================================
-- PARTIE 3 : Marqueur de migration (traçabilité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.7_2.3.8', 'update_2.3.7_2.3.8.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN — pas de backfill : table vide par design.
-- StoreSettings::get() retourne getDolGlobalString('DOLI2SHOP_*') si aucune ligne per-store.
-- Invariant mono-boutique préservé sans aucune donnée initiale.
-- ============================================================================
