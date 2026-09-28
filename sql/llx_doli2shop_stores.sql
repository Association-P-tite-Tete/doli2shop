-- Date: 2026-06-24
-- Version: 2.3.3
-- Description: Création de la table llx_doli2shop_stores qui porte la configuration
--              et les credentials de chaque boutique Shopify (Epic 47, Story 47-1).
--              Supporte le multi-boutiques sur une entité fiscale unique.
--              Story 47-5 : ajout fk_categorie_order + fk_categorie_invoice pour
--              le tag automatique commandes/factures par boutique.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_stores
(
    rowid                integer      AUTO_INCREMENT PRIMARY KEY,
    entity               int(11)      NOT NULL DEFAULT 1,
    label                varchar(255) NOT NULL,
    shop_domain          varchar(255) NOT NULL COMMENT 'hostname myshopify.com (ex: xxx.myshopify.com)',
    access_token         varchar(255) DEFAULT NULL,
    api_key              varchar(255) DEFAULT NULL,
    api_secret           varchar(255) DEFAULT NULL,
    location_id          varchar(255) DEFAULT NULL,
    fk_categorie         int(11)      DEFAULT NULL COMMENT 'Catégorie Dolibarr produits (TYPE_PRODUCT) boutique (Story 47-5)',
    fk_categorie_order   int(11)      DEFAULT NULL COMMENT 'Catégorie Dolibarr commandes (TYPE_ORDER) boutique (Story 47-5)',
    fk_categorie_invoice int(11)      DEFAULT NULL COMMENT 'Catégorie Dolibarr factures (TYPE_INVOICE) boutique (Story 47-5)',
    fk_categorie_proposal int(11)     DEFAULT NULL COMMENT 'Catégorie Dolibarr devis (TYPE_PROPOSAL) boutique (Story 48-2)',
    is_default           tinyint(1)   NOT NULL DEFAULT 0,
    active               tinyint(1)   NOT NULL DEFAULT 1,
    license_status       varchar(20)  NOT NULL DEFAULT 'unknown' COMMENT 'Statut licence boutique : valid|invalid|unknown (Story 47-6)',
    license_checked      datetime     DEFAULT NULL COMMENT 'Dernière vérification licence (Story 47-6)',
    serial_number        varchar(50)  DEFAULT NULL COMMENT 'Numéro de série DoliStore lié à cette boutique (Story 49-12)',
    token_expires_at     datetime     DEFAULT NULL COMMENT 'Échéance access_token OAuth Shopify, marge de sécurité déjà déduite (Story 51-1)',
    refresh_token        varchar(512) DEFAULT NULL COMMENT 'Refresh token OAuth Shopify (usage unique, 90j) — jamais loggué (Story 51-1)',
    token_reconnect_required tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Reconnexion Shopify requise : refresh token échoué définitivement (Story 51-1)',
    datec                datetime     DEFAULT NULL,
    tms                  timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
