-- Date: 2025-04-08 15:00:00
-- Version: 2.1.8
-- Description: Création de la table llx_doli2shop_payments
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Create table llx_doli2shop_payments
CREATE TABLE llx_doli2shop_payments (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    shopify_payment_method VARCHAR(255) NOT NULL,
    dolibarr_payment_id INTEGER NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    active INTEGER DEFAULT 1 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

-- v2.5.3 (story multicompany-seed-paiements-jamais-pose-en-entite-secondaire) :
-- L'ancien seeding de données ci-dessous a été RETIRÉ. Il posait les 8 correspondances par
-- défaut avec `entity` figée à 1, sous une garde globale `WHERE NOT EXISTS (SELECT 1 FROM
-- llx_doli2shop_payments LIMIT 1)` qui regardait TOUTE la table sans filtre d'entité : dès
-- qu'une première entité était semée, aucune autre ne l'était jamais (Multicompany).
-- Invariant Epic 47 : le DDL reste en SQL, le SEEDING DE DONNÉES va en PHP, exécuté après
-- ensureDefaultStore() — voir PaymentMethodsMapping::ensureDefaultMappings() et son appel
-- dans modDoli2Shop::init(). Cette méthode sème par (entité, méthode), jamais sur toute la
-- table, et ne recrée/n'écrase jamais une correspondance existante (idempotence + valeurs
-- clients préservées).
-- Codes typiques : CB=6, VIR=2, CHQ=7, LIQ=4

-- v2.1.8: Add index on entity for multi-entity performance (compatible MySQL 5.7+ / MariaDB 10.x)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_payments'
               AND INDEX_NAME = 'idx_shopify_payment_mapping_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_shopify_payment_mapping_entity ON llx_doli2shop_payments (entity)',
                   'SELECT "Index idx_shopify_payment_mapping_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- v2.1.8: Add unique constraint on shopify_payment_method + entity to prevent duplicates
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_payments'
               AND INDEX_NAME = 'uk_shopify_payment_method_entity');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_shopify_payment_method_entity ON llx_doli2shop_payments (shopify_payment_method, entity)',
                   'SELECT "Index uk_shopify_payment_method_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;