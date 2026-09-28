-- =====================================================
-- MIGRATION v2.4.0 -> v2.4.1 (DOLI2SHOP)
-- Date: 2026-07-11
-- Version: 2.4.1
-- Description: Ajout colonnes token_expires_at + refresh_token sur
--              llx_doli2shop_stores pour supporter les jetons OAuth
--              Shopify expirables (access token 3600s + refresh token
--              90j usage unique). Échéance Shopify : 1er janvier 2027.
--              Epic 51, Story 51-1 — Jetons expirables + refresh auto.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_stores — ADD COLUMN token_expires_at
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'token_expires_at');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN token_expires_at datetime NULL COMMENT ''Échéance access_token OAuth Shopify, marge de sécurité déjà déduite (Story 51-1)''',
    'SELECT "Column token_expires_at already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 2 : llx_doli2shop_stores — ADD COLUMN refresh_token
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'refresh_token');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN refresh_token varchar(512) NULL COMMENT ''Refresh token OAuth Shopify (usage unique, 90j) — jamais loggué (Story 51-1)''',
    'SELECT "Column refresh_token already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3 : llx_doli2shop_stores — ADD COLUMN token_reconnect_required
-- (CORRECTION VALIDATE T5/AC9 — "à trancher" au Dev) : champ DÉDIÉ explicite plutôt que
-- de détourner license_status (sémantique différente). Positionné à 1 quand le refresh
-- automatique a échoué DÉFINITIVEMENT (401 persistant après retry, ou échec de persistance
-- après consommation du refresh token usage-unique) — piloté un signal admin visible
-- (diagnostic + fiche boutique) sans jamais désactiver la boutique par défaut (invariant
-- Epic 47).
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'token_reconnect_required');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN token_reconnect_required tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Reconnexion Shopify requise : refresh token échoué définitivement (Story 51-1)''',
    'SELECT "Column token_reconnect_required already exists in llx_doli2shop_stores"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 4 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
--
-- NOTE 50-6 (2026-07-12) : cette ligne est désormais REDONDANTE et neutralisée
-- au parsing par MigrationManager::parseSQLContent() (class/MigrationManager.class.php)
-- — jamais envoyée à $db->query(). Raison : en multi-entité, rejouer ce fichier
-- depuis une entité 2+ (première activation, jamais activée sur l'entité 1)
-- créerait un FAUX marqueur "migration appliquée" pour l'entité 1 (l'INSERT
-- IGNORE ne serait pas un no-op, aucune ligne n'existant encore pour entity=1).
-- Le bookkeeping par entité est déjà géré correctement et dynamiquement par
-- MigrationManager::recordMigration() juste après l'exécution de ce fichier.
-- Conséquence pour les FUTURES migrations : NE PLUS AJOUTER ce bloc "INSERT
-- IGNORE INTO llx_doli2shop_migrations" dans les nouveaux update_*.sql — il est
-- inutile (bookkeeping déjà assuré par le PHP) et de toute façon sans effet
-- (filtré). On le laisse ici tel quel (fichier non modifié fonctionnellement,
-- décision 50-6 : ne pas toucher aux migrations déjà écrites) uniquement à
-- titre d'exemple historique du pattern à ne plus reproduire.
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.4.0_2.4.1', 'update_2.4.0_2.4.1.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
