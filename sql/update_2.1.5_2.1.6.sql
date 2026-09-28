-- =====================================================
-- MIGRATION v2.1.5 -> v2.1.6 (DOLI2SHOP)
-- Date: 2025-12-13
-- Version: 2.1.6
-- Description: Migration complète vers Doli2Shop
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- =====================================================


-- ============================================================================
-- PARTIE 1: MIGRATION DES CONSTANTES
-- Copier les constantes SHOPIFYINTEGRATION_* vers DOLI2SHOP_*
-- ============================================================================

INSERT INTO llx_const (name, value, type, visible, note, entity)
SELECT
    REPLACE(c.name, 'SHOPIFYINTEGRATION_', 'DOLI2SHOP_'),
    c.value,
    c.type,
    c.visible,
    CONCAT('Migrated from ', c.name, ' on ', NOW()),
    c.entity
FROM llx_const c
WHERE c.name LIKE 'SHOPIFYINTEGRATION_%'
AND NOT EXISTS (
    SELECT 1 FROM llx_const c2
    WHERE c2.name = REPLACE(c.name, 'SHOPIFYINTEGRATION_', 'DOLI2SHOP_')
    AND c2.entity = c.entity
);

-- Migration des constantes SHOPIFY_* (anciens préfixes)
INSERT INTO llx_const (name, value, type, visible, note, entity)
SELECT
    REPLACE(c.name, 'SHOPIFY_', 'DOLI2SHOP_'),
    c.value,
    c.type,
    c.visible,
    CONCAT('Migrated from ', c.name, ' on ', NOW()),
    c.entity
FROM llx_const c
WHERE c.name LIKE 'SHOPIFY_%'
AND c.name NOT LIKE 'SHOPIFYINTEGRATION_%'
AND NOT EXISTS (
    SELECT 1 FROM llx_const c2
    WHERE c2.name = REPLACE(c.name, 'SHOPIFY_', 'DOLI2SHOP_')
    AND c2.entity = c.entity
);

-- Mise à jour URL API Support (migration ancienne → nouvelle)
UPDATE llx_const
SET value = 'https://doli2shop.ptitetete.org/api/validate_support.php',
    note = CONCAT('URL updated from shopifyintegration.ptitetete.com on ', NOW())
WHERE name IN ('SHOPIFY_SUPPORT_API_ENDPOINT', 'DOLI2SHOP_SUPPORT_API_ENDPOINT')
AND value LIKE '%shopifyintegration.ptitetete.com%';


-- ============================================================================
-- PARTIE 2: EXTRAFIELDS PRODUITS - Import Shopify → Dolibarr
-- ============================================================================

-- Extrafield Tags Shopify (stocke les tags du produit Shopify)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_product_extrafields'
               AND COLUMN_NAME = 'shopify_tags');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_product_extrafields ADD COLUMN shopify_tags VARCHAR(1024) DEFAULT NULL',
                   'SELECT "Column shopify_tags already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Extrafield Vendor Shopify (stocke le fournisseur/marque du produit Shopify)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_product_extrafields'
               AND COLUMN_NAME = 'shopify_vendor');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_product_extrafields ADD COLUMN shopify_vendor VARCHAR(255) DEFAULT NULL',
                   'SELECT "Column shopify_vendor already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Enregistrer l'extrafield shopify_tags dans la table des attributs
SET @exist := (SELECT COUNT(*) FROM llx_extrafields
               WHERE elementtype = 'product'
               AND name = 'shopify_tags');
SET @sqlstmt := IF(@exist = 0,
    'INSERT INTO llx_extrafields (name, entity, elementtype, label, type, size, fieldunique, fieldrequired, perms, list, pos, alwayseditable, param, langs, enabled, totalizable, printable, datec) VALUES (''shopify_tags'', 1, ''product'', ''ShopifyTagsExtrafield'', ''varchar'', ''1024'', 0, 0, '''', 1, 100, 0, '''', ''doli2shop@doli2shop'', ''1'', 0, 0, NOW())',
    'SELECT "Extrafield shopify_tags already registered"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Enregistrer l'extrafield shopify_vendor dans la table des attributs
SET @exist := (SELECT COUNT(*) FROM llx_extrafields
               WHERE elementtype = 'product'
               AND name = 'shopify_vendor');
SET @sqlstmt := IF(@exist = 0,
    'INSERT INTO llx_extrafields (name, entity, elementtype, label, type, size, fieldunique, fieldrequired, perms, list, pos, alwayseditable, param, langs, enabled, totalizable, printable, datec) VALUES (''shopify_vendor'', 1, ''product'', ''ShopifyVendorExtrafield'', ''varchar'', ''255'', 0, 0, '''', 1, 101, 0, '''', ''doli2shop@doli2shop'', ''1'', 0, 0, NOW())',
    'SELECT "Extrafield shopify_vendor already registered"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 3: INDEX DE PERFORMANCE SUR TABLES MAPPING
-- ============================================================================

-- Index sur entity pour llx_shopify_payment_methods_mapping (seulement si la table existe)
SET @table_exist := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_shopify_payment_methods_mapping');
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_payment_methods_mapping'
                   AND INDEX_NAME = 'idx_payment_methods_entity');
SET @sqlstmt := IF(@table_exist > 0 AND @idx_exist = 0,
                   'CREATE INDEX idx_payment_methods_entity ON llx_shopify_payment_methods_mapping (entity)',
                   'SELECT "Table missing or index idx_payment_methods_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index composite sur llx_shopify_payment_methods_mapping (seulement si la table existe)
SET @table_exist := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_shopify_payment_methods_mapping');
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_payment_methods_mapping'
                   AND INDEX_NAME = 'idx_payment_methods_search');
SET @sqlstmt := IF(@table_exist > 0 AND @idx_exist = 0,
                   'CREATE INDEX idx_payment_methods_search ON llx_shopify_payment_methods_mapping (shopify_payment_method, entity, active)',
                   'SELECT "Table missing or index idx_payment_methods_search already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur entity pour llx_shopify_shipping_methods_mapping (seulement si la table existe)
SET @table_exist := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_shopify_shipping_methods_mapping');
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_shipping_methods_mapping'
                   AND INDEX_NAME = 'idx_shipping_methods_entity');
SET @sqlstmt := IF(@table_exist > 0 AND @idx_exist = 0,
                   'CREATE INDEX idx_shipping_methods_entity ON llx_shopify_shipping_methods_mapping (entity)',
                   'SELECT "Table missing or index idx_shipping_methods_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index composite sur llx_shopify_shipping_methods_mapping (seulement si la table existe)
SET @table_exist := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_shopify_shipping_methods_mapping');
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_shipping_methods_mapping'
                   AND INDEX_NAME = 'idx_shipping_methods_search');
SET @sqlstmt := IF(@table_exist > 0 AND @idx_exist = 0,
                   'CREATE INDEX idx_shipping_methods_search ON llx_shopify_shipping_methods_mapping (shopify_pattern, entity)',
                   'SELECT "Table missing or index idx_shipping_methods_search already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur entity pour llx_shopify_inventory_mapping (seulement si la table existe)
SET @table_exist := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_shopify_inventory_mapping');
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_inventory_mapping'
                   AND INDEX_NAME = 'idx_inventory_mapping_entity');
SET @sqlstmt := IF(@table_exist > 0 AND @idx_exist = 0,
                   'CREATE INDEX idx_inventory_mapping_entity ON llx_shopify_inventory_mapping (entity)',
                   'SELECT "Table missing or index idx_inventory_mapping_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur fk_product pour llx_shopify_inventory_mapping (seulement si la table existe)
SET @table_exist := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_shopify_inventory_mapping');
SET @idx_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_inventory_mapping'
                   AND INDEX_NAME = 'idx_inventory_mapping_product');
SET @sqlstmt := IF(@table_exist > 0 AND @idx_exist = 0,
                   'CREATE INDEX idx_inventory_mapping_product ON llx_shopify_inventory_mapping (fk_product)',
                   'SELECT "Table missing or index idx_inventory_mapping_product already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 4: RENOMMAGE DES TABLES (avec préservation données)
-- ============================================================================

-- Table 1: llx_dolibarr_shopify_products_save -> llx_doli2shop_products
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_dolibarr_shopify_products_save');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_products');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_dolibarr_shopify_products_save TO llx_doli2shop_products',
                   'SELECT "Table llx_doli2shop_products: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 2: llx_dolibarr_shopify_orders_save -> llx_doli2shop_orders
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_orders');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_dolibarr_shopify_orders_save TO llx_doli2shop_orders',
                   'SELECT "Table llx_doli2shop_orders: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 3: llx_dolibarr_shopify_order_status_mapping -> llx_doli2shop_order_status_dol2shop
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_dolibarr_shopify_order_status_mapping');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_order_status_dol2shop');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_dolibarr_shopify_order_status_mapping TO llx_doli2shop_order_status_dol2shop',
                   'SELECT "Table llx_doli2shop_order_status_dol2shop: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 4: llx_shopify_order_status_mapping -> llx_doli2shop_order_status_shop2dol
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_order_status_mapping');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_order_status_shop2dol');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_order_status_mapping TO llx_doli2shop_order_status_shop2dol',
                   'SELECT "Table llx_doli2shop_order_status_shop2dol: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 5: llx_shopify_collections_mapping -> llx_doli2shop_collections
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_collections_mapping');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_collections');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_collections_mapping TO llx_doli2shop_collections',
                   'SELECT "Table llx_doli2shop_collections: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 6: llx_shopify_inventory_mapping -> llx_doli2shop_inventory
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_inventory_mapping');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_inventory');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_inventory_mapping TO llx_doli2shop_inventory',
                   'SELECT "Table llx_doli2shop_inventory: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 7: llx_shopify_payment_methods_mapping -> llx_doli2shop_payments
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_payment_methods_mapping');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_payments');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_payment_methods_mapping TO llx_doli2shop_payments',
                   'SELECT "Table llx_doli2shop_payments: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 8: llx_shopify_shipping_methods_mapping -> llx_doli2shop_shipping
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_shipping_methods_mapping');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_shipping');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_shipping_methods_mapping TO llx_doli2shop_shipping',
                   'SELECT "Table llx_doli2shop_shipping: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 9: llx_shopify_migration_history -> llx_doli2shop_migrations
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_migration_history');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_migrations');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_migration_history TO llx_doli2shop_migrations',
                   'SELECT "Table llx_doli2shop_migrations: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 10: llx_shopify_webhooks -> llx_doli2shop_webhooks
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_webhooks');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_webhooks');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_webhooks TO llx_doli2shop_webhooks',
                   'SELECT "Table llx_doli2shop_webhooks: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 11: llx_shopify_webhook_events -> llx_doli2shop_webhook_events
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_webhook_events');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_webhook_events');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_webhook_events TO llx_doli2shop_webhook_events',
                   'SELECT "Table llx_doli2shop_webhook_events: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Table 12: llx_shopify_dolibarr_storedetails -> llx_doli2shop_storedetails
SET @exist_old := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails');
SET @exist_new := (SELECT COUNT(*) FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_doli2shop_storedetails');
SET @sqlstmt := IF(@exist_old > 0 AND @exist_new = 0,
                   'RENAME TABLE llx_shopify_dolibarr_storedetails TO llx_doli2shop_storedetails',
                   'SELECT "Table llx_doli2shop_storedetails: already migrated or source does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- PARTIE 5: MIGRATION DES CRONS
-- ============================================================================

-- Option A: Mettre à jour les CRONs existants (si présents)
-- Mettre à jour le module_name des CRONs existants
UPDATE llx_cronjob
SET module_name = 'doli2shop'
WHERE module_name = 'shopifyintegration';

-- Mettre à jour les chemins des classes
UPDATE llx_cronjob
SET classesname = REPLACE(classesname, '/shopifyintegration/', '/doli2shop/')
WHERE classesname LIKE '%/shopifyintegration/%';

-- Mettre à jour le test d'activation du module
UPDATE llx_cronjob
SET test = 'isModEnabled("doli2shop")'
WHERE test = 'isModEnabled("shopifyintegration")';

-- Mettre à jour les anciens tests avec double quotes
UPDATE llx_cronjob
SET test = 'isModEnabled("doli2shop")'
WHERE test LIKE '%shopifyintegration%';

-- Option B: Supprimer les anciens CRONs pour forcer la recréation par Dolibarr
-- Suppression des CRONs orphelins qui référencent l'ancien module
DELETE FROM llx_cronjob WHERE module_name = 'shopifyintegration';
DELETE FROM llx_cronjob WHERE classesname LIKE '%/shopifyintegration/%';

-- Suppression des CRONs doli2shop existants pour permettre la recréation propre
-- (Dolibarr les recréera lors de l'activation du module)
DELETE FROM llx_cronjob WHERE module_name = 'doli2shop';
DELETE FROM llx_cronjob WHERE module_name = 'Doli2Shop';


-- ============================================================================
-- PARTIE 6: ENREGISTREMENT DE LA MIGRATION
-- ============================================================================

-- Créer la table de migrations si elle n'existe pas encore (nouvelle installation)
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_migrations');
SET @sqlstmt := IF(@exist = 0,
    'CREATE TABLE llx_doli2shop_migrations (
        rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
        migration_version VARCHAR(20) NOT NULL,
        migration_file VARCHAR(255) NOT NULL,
        applied_date DATETIME NOT NULL,
        success TINYINT(1) DEFAULT 1,
        entity INT NOT NULL DEFAULT 1,
        UNIQUE KEY uk_migration_version_entity (migration_version, entity)
    ) ENGINE=innodb',
    'SELECT "Table llx_doli2shop_migrations already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Enregistrer cette migration
INSERT INTO llx_doli2shop_migrations (migration_version, migration_file, applied_date, success, entity)
SELECT '2.1.6', 'update_2.1.5_2.1.6.sql', NOW(), 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM llx_doli2shop_migrations
    WHERE migration_version = '2.1.6' AND entity = 1
);


-- ============================================================================
-- PARTIE 7: CONTRAINTES FK (documentées, non activées automatiquement)
-- ============================================================================
-- Note: Ces FK sont documentées mais non activées automatiquement car :
-- 1. Peut échouer si données existantes inconsistantes
-- 2. Les mappings fonctionnent par valeur, pas par référence stricte
-- 3. Permet plus de flexibilité pour mappings personnalisés
--
-- Pour activer manuellement si souhaité :
--
-- ALTER TABLE llx_doli2shop_payments
--     ADD CONSTRAINT fk_payments_dolibarr
--     FOREIGN KEY (dolibarr_payment_id) REFERENCES llx_c_paiement(id) ON DELETE CASCADE;
--
-- ALTER TABLE llx_doli2shop_shipping
--     ADD CONSTRAINT fk_shipping_dolibarr
--     FOREIGN KEY (dol_shipping_method_id) REFERENCES llx_c_shipment_mode(rowid) ON DELETE CASCADE;


-- ============================================================================
-- PARTIE 8: FIX ERREUR "Field 'tms' doesn't have a default value"
-- ============================================================================
-- Problème: Certaines versions MySQL en mode strict requièrent une valeur
-- par défaut pour les champs NOT NULL. Le champ 'tms' de llx_doli2shop_products
-- n'avait pas de DEFAULT CURRENT_TIMESTAMP, causant des erreurs INSERT.
-- Impact: 200+ erreurs INSERT chez les clients en intranet

-- Fix pour llx_doli2shop_products (table principale des mappings)
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products');
SET @sqlstmt := IF(@exist > 0,
                   'ALTER TABLE llx_doli2shop_products MODIFY COLUMN tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                   'SELECT "Table llx_doli2shop_products does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fix également pour l'ancienne table si elle existe encore (migration en cours)
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_products_save');
SET @sqlstmt := IF(@exist > 0,
                   'ALTER TABLE llx_dolibarr_shopify_products_save MODIFY COLUMN tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                   'SELECT "Table llx_dolibarr_shopify_products_save does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- FIN DE LA MIGRATION v2.1.5 -> v2.1.6
-- ============================================================================
