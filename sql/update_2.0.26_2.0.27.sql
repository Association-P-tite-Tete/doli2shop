-- Date: 2025-08-28
-- Version: 2.1.8
-- Description: Migration complète des paramètres de configuration de llx_shopify_dolibarr_storedetails vers llx_const
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Migration script from table storedetails to Dolibarr standard constants

-- ============================================================================
-- MIGRATION DES PARAMÈTRES DE CONFIGURATION SHOPIFY INTEGRATION
-- De: llx_shopify_dolibarr_storedetails → llx_const (système standard Dolibarr)
-- v2.1.8: Rendu idempotent — vérifie l'existence de la table source avant migration
-- Si la table n'existe pas (installation directe en v2.0.27+), le script est silencieusement ignoré.
-- Dans ce cas, l'utilisateur doit configurer le module manuellement via l'interface d'administration.
-- ============================================================================

-- Vérifier l'existence de la table source (variable de session réutilisée dans tout le script)
SET @storedetails_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails');

-- 1. Configuration Shopify de base (6 paramètres)
SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_STORE_HOSTNAME", shopify_store_hostname, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shopify_store_hostname IS NOT NULL AND shopify_store_hostname != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: llx_shopify_dolibarr_storedetails does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_ACCESS_TOKEN", shopify_access_token, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shopify_access_token IS NOT NULL AND shopify_access_token != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_API_KEY", shopify_api_key, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shopify_api_key IS NOT NULL AND shopify_api_key != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_API_SECRET_KEY", shopify_api_secret_key, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shopify_api_secret_key IS NOT NULL AND shopify_api_secret_key != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_LOCATION_ID", shopify_location_id, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shopify_location_id IS NOT NULL AND shopify_location_id != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_VENDOR", shopify_vendor, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shopify_vendor IS NOT NULL AND shopify_vendor != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Configuration Dolibarr (3 paramètres)
SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DOLIBARR_HOSTURL", dolibarr_hosturl, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE dolibarr_hosturl IS NOT NULL AND dolibarr_hosturl != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DOLIBARR_API_KEY", dolibarr_api_key, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE dolibarr_api_key IS NOT NULL AND dolibarr_api_key != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DOLIBARR_PROCATE", dolibarr_procate, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE dolibarr_procate IS NOT NULL AND dolibarr_procate != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Configuration des commandes (11 paramètres)
SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_ORDER_PREFIX", order_prefix, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE order_prefix IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DELIVERY_DELAY", CAST(delivery_delay AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE delivery_delay IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DELIVERY_DELAY_TYPE", delivery_delay_type, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE delivery_delay_type IS NOT NULL AND delivery_delay_type != "" ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_ORDER_ORIGIN", CAST(order_origin AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE order_origin IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_PAYMENT_TERMS", CAST(payment_terms AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE payment_terms IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DEFAULT_SHIPPING_METHOD_ID", CAST(default_shipping_method_id AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE default_shipping_method_id IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_SHIPPING_PRODUCT_ID", CAST(shipping_product_id AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE shipping_product_id IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DEFAULT_WAREHOUSE_ID", CAST(default_warehouse_id AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE default_warehouse_id IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_DEFAULT_DELIVERY_DAYS", CAST(default_delivery_days AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE default_delivery_days IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_ORDER_BANK_ACCOUNT", CAST(order_bank_account AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE order_bank_account IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_TIP_PRODUCT_ID", CAST(tip_product_id AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE tip_product_id IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Configuration de la synchronisation (3 paramètres)
SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_MAX_ORDERS_PER_SYNC", CAST(max_orders_per_sync AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE max_orders_per_sync IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_PRODUCTS_PER_CRON_UPDATE", CAST(products_per_cron_update AS CHAR), "entier", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE products_per_cron_update IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_USE_VIRTUAL_STOCK", CAST(use_virtual_stock AS CHAR), "yesno", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE use_virtual_stock IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Import historique des commandes (5 paramètres)
SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_HISTORICAL_IMPORT_ENABLED", CAST(historical_import_enabled AS CHAR), "yesno", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE historical_import_enabled IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_HISTORICAL_IMPORT_START_DATE", historical_import_start_date, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE historical_import_start_date IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_HISTORICAL_IMPORT_END_DATE", historical_import_end_date, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE historical_import_end_date IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_HISTORICAL_IMPORT_COMPLETED", CAST(historical_import_completed AS CHAR), "yesno", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE historical_import_completed IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sqlstmt := IF(@storedetails_exists > 0,
    'INSERT INTO llx_const (name, value, type, visible, note, entity) SELECT "SHOPIFYINTEGRATION_HISTORICAL_IMPORT_COMPLETED_DATE", historical_import_completed_date, "chaine", 0, "Migrated from storedetails", entity FROM llx_shopify_dolibarr_storedetails WHERE historical_import_completed_date IS NOT NULL ON DUPLICATE KEY UPDATE value = VALUES(value)',
    'SELECT "Skipping migration: table does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Synchronisation des produits (8 paramètres)
-- v2.1.8: Vérification table ET colonne pour idempotence complète

-- Vérification colonne sync_product_prices (nécessite aussi que la table existe)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails'
               AND COLUMN_NAME = 'sync_product_prices');

SET @sqlstmt := IF(@exist > 0, 
                   'INSERT INTO llx_const (name, value, type, visible, note, entity)
                    SELECT 
                        "SHOPIFYINTEGRATION_SYNC_PRODUCT_PRICES" as name,
                        CAST(sync_product_prices AS CHAR) as value,
                        "yesno" as type,
                        0 as visible,
                        "Sync product prices - Migrated from storedetails table" as note,
                        entity
                    FROM llx_shopify_dolibarr_storedetails
                    WHERE sync_product_prices IS NOT NULL
                    ON DUPLICATE KEY UPDATE 
                        value = VALUES(value),
                        note = "Sync product prices - Updated from storedetails table"', 
                   'SELECT "Column sync_product_prices does not exist, skipping"');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. Synchronisation des collections (3 paramètres) - v2.0.26
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'sync_product_collections');

SET @sqlstmt := IF(@exist > 0, 
                   'INSERT INTO llx_const (name, value, type, visible, note, entity)
                    SELECT 
                        "SHOPIFYINTEGRATION_SYNC_PRODUCT_COLLECTIONS" as name,
                        CAST(sync_product_collections AS CHAR) as value,
                        "yesno" as type,
                        0 as visible,
                        "Sync product collections - Migrated from storedetails table" as note,
                        entity
                    FROM llx_shopify_dolibarr_storedetails
                    WHERE sync_product_collections IS NOT NULL
                    ON DUPLICATE KEY UPDATE 
                        value = VALUES(value),
                        note = "Sync product collections - Updated from storedetails table"', 
                   'SELECT "Column sync_product_collections does not exist, skipping"');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- VÉRIFICATION POST-MIGRATION
-- ============================================================================

-- Afficher le nombre de constantes migrées par entité
SELECT entity, COUNT(*) as constants_count
FROM llx_const 
WHERE name LIKE 'SHOPIFYINTEGRATION_%' 
  AND note LIKE '%Migrated from storedetails table%'
GROUP BY entity
ORDER BY entity;

-- Afficher un résumé des constantes créées
SELECT 
    SUBSTRING(name, 1, 50) as constant_name,
    type,
    entity,
    CASE 
        WHEN value = '' OR value IS NULL THEN '[EMPTY]'
        WHEN LENGTH(value) > 20 THEN CONCAT(SUBSTRING(value, 1, 17), '...')
        ELSE value 
    END as value_preview
FROM llx_const 
WHERE name LIKE 'SHOPIFYINTEGRATION_%'
  AND note LIKE '%Migrated from storedetails table%'
ORDER BY entity, name;