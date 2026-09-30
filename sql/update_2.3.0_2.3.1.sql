-- =====================================================
-- MIGRATION v2.3.0 -> v2.3.1 (DOLI2SHOP)
-- Date: 2026-06-23
-- Version: 2.6.0
-- Description: Création de la table llx_doli2shop_stores (Epic 47, Story 47-1).
--              Table de configuration multi-boutiques Shopify par entité.
--              Seeding de la boutique par défaut effectué en PHP (StoreService::ensureDefaultStore).
--              MàJ story migration-ne-comble-jamais-une-table-incomplete (v2.5.3, AC3) : le
--              CREATE TABLE ci-dessous portait à l'origine seulement 13 des 22 colonnes de
--              référence (sql/llx_doli2shop_stores.sql), sans aucun ALTER ADD COLUMN pour les
--              7 manquantes — sur une install où ce CREATE avait déjà tourné (table existante,
--              branche IF no-op), ces 7 colonnes ne pouvaient plus jamais être ajoutées. Aligné
--              ici sur la définition de référence complète (source unique par table, AC3) ; la
--              réparation d'une table déjà incomplète est couverte séparément et automatiquement
--              par doli2shopRepairStoresTableSchema() (lib/doli2shop.lib.php), appelée depuis
--              modDoli2Shop::init() avant le seeding (AC1).
--              MàJ story doublon-is-default-boutiques-non-empeche (v2.6.0) : colonne générée
--              default_key + index unique associé, alignés ici sur sql/llx_doli2shop_stores.sql
--              (23 colonnes de référence désormais) — même motif de single-source-of-truth que
--              ci-dessus. Cette branche CREATE ne joue de toute façon plus pour une install déjà
--              migrée (MigrationManager ne rejoue jamais une version déjà marquée appliquée) : la
--              protection réelle pour le parc existant est la migration dédiée
--              update_2.6.0_2.6.0b.sql, câblée séparément avec sa propre clé de version.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.TABLES — pattern obligatoire (règle projet absolue)
-- =====================================================

-- ============================================================================
-- PARTIE 1 : Création de la table llx_doli2shop_stores (DDL idempotent)
-- ============================================================================

SET @exist_table := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_doli2shop_stores');
SET @sqlstmt := IF(@exist_table = 0,
    'CREATE TABLE llx_doli2shop_stores (
        rowid                integer      AUTO_INCREMENT PRIMARY KEY,
        entity               int(11)      NOT NULL DEFAULT 1,
        label                varchar(255) NOT NULL,
        shop_domain          varchar(255) NOT NULL COMMENT ''hostname myshopify.com (ex: xxx.myshopify.com)'',
        access_token         varchar(255) DEFAULT NULL,
        api_key              varchar(255) DEFAULT NULL,
        api_secret           varchar(255) DEFAULT NULL,
        location_id          varchar(255) DEFAULT NULL,
        fk_categorie         int(11)      DEFAULT NULL COMMENT ''Catégorie Dolibarr produits (TYPE_PRODUCT) boutique (Story 47-5)'',
        fk_categorie_order   int(11)      DEFAULT NULL COMMENT ''Catégorie Dolibarr commandes (TYPE_ORDER) boutique (Story 47-5)'',
        fk_categorie_invoice int(11)      DEFAULT NULL COMMENT ''Catégorie Dolibarr factures (TYPE_INVOICE) boutique (Story 47-5)'',
        fk_categorie_proposal int(11)     DEFAULT NULL COMMENT ''Catégorie Dolibarr devis (TYPE_PROPOSAL) boutique (Story 48-2)'',
        is_default           tinyint(1)   NOT NULL DEFAULT 0,
        default_key          int(11)      GENERATED ALWAYS AS (IF(is_default = 1, entity, NULL)) VIRTUAL COMMENT ''Colonne generee (story doublon-is-default) : NULL si is_default=0, sinon entity'',
        active               tinyint(1)   NOT NULL DEFAULT 1,
        license_status       varchar(20)  NOT NULL DEFAULT ''unknown'' COMMENT ''Statut licence boutique : valid|invalid|unknown (Story 47-6)'',
        license_checked      datetime     DEFAULT NULL COMMENT ''Dernière vérification licence (Story 47-6)'',
        serial_number        varchar(50)  DEFAULT NULL COMMENT ''Numéro de série DoliStore lié à cette boutique (Story 49-12)'',
        token_expires_at     datetime     DEFAULT NULL COMMENT ''Échéance access_token OAuth Shopify, marge de sécurité déjà déduite (Story 51-1)'',
        refresh_token        varchar(512) DEFAULT NULL COMMENT ''Refresh token OAuth Shopify (usage unique, 90j) — jamais loggué (Story 51-1)'',
        token_reconnect_required tinyint(1) NOT NULL DEFAULT 0 COMMENT ''Reconnexion Shopify requise : refresh token échoué définitivement (Story 51-1)'',
        datec                datetime     DEFAULT NULL,
        tms                  timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "Table llx_doli2shop_stores already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PARTIE 2 : Index idempotents sur llx_doli2shop_stores
-- ============================================================================

-- Index sur entity (recherches par entité)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND INDEX_NAME = 'idx_doli2shop_stores_entity');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD INDEX idx_doli2shop_stores_entity (entity)',
    'SELECT "Index idx_doli2shop_stores_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unicité (shop_domain, entity) — une même boutique ne peut être configurée qu'une fois par entité
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND INDEX_NAME = 'uk_doli2shop_stores_domain');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD UNIQUE INDEX uk_doli2shop_stores_domain (shop_domain, entity)',
    'SELECT "Index uk_doli2shop_stores_domain already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ⚠️ PAS de bloc gardé ici pour uk_doli2shop_stores_default_key (contrairement à
-- sql/llx_doli2shop_stores.sql) : cette migration est câblée AVANT update_2.6.0_2.6.0b.sql dans
-- modDoli2Shop::init() — un rejeu qui l'exécuterait réellement (install jamais migrée) trouverait
-- la colonne default_key pas encore ajoutée (ADD COLUMN vit dans update_2.6.0_2.6.0b.sql, câblée
-- après). L'index est donc du ressort EXCLUSIF de cette migration dédiée, jamais dupliqué ici
-- (SqlMigrationsIntegrationTest rejoue les migrations câblées dans l'ordre de $migrations et
-- aurait échoué sur "Unknown column 'default_key'" si ce bloc avait été dupliqué ici).


-- ============================================================================
-- PARTIE 3 : Marqueur de migration (traçabilité)
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- entity = 1 : convention DDL (opère sur structure, pas sur données par entité)
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.3.0_2.3.1', 'update_2.3.0_2.3.1.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Note : le seeding de la boutique par défaut (lecture des constantes DOLI2SHOP_*
-- et INSERT dans llx_doli2shop_stores) est effectué en PHP via
-- StoreService::ensureDefaultStore(), appelé depuis modDoli2Shop::init()
-- après la boucle de migrations.
-- ============================================================================
