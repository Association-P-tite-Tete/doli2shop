-- Date: 2025-04-08
-- Version: 2.0.0
-- Description: Mise à jour de la table llx_shopify_dolibarr_storedetails et des tâches cron
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 1.0.0 to 2.0.0

-- Rename existing columns (if table exists - table obsolete since v2.1.0)
SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails');

-- Only proceed if table exists
SET @sqlstmt := IF(@table_exists > 0,
                   'ALTER TABLE llx_shopify_dolibarr_storedetails
                    CHANGE shopifystorename shopify_store_hostname varchar(255) NOT NULL,
                    CHANGE shopifystorekey shopify_access_token varchar(255) NOT NULL,
                    CHANGE dolapikey dolibarr_api_key varchar(255) NOT NULL,
                    CHANGE dolhosturl dolibarr_hosturl varchar(255) NOT NULL,
                    CHANGE dolprocate dolibarr_procate varchar(255) NULL',
                   'SELECT "Table llx_shopify_dolibarr_storedetails does not exist - skipping column rename (table obsolete since v2.1.0)"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ajouter les nouvelles colonnes (if table exists - table obsolete since v2.1.0)
SET @sqlstmt := IF(@table_exists > 0,
                   'ALTER TABLE llx_shopify_dolibarr_storedetails
                    ADD COLUMN shopify_api_key varchar(255) NOT NULL AFTER shopify_access_token,
                    ADD COLUMN shopify_api_secret_key varchar(255) NOT NULL AFTER shopify_api_key',
                   'SELECT "Table llx_shopify_dolibarr_storedetails does not exist - skipping add columns (table obsolete since v2.1.0)"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Supprimer les anciennes tâches cron de la version 1.0.0
-- FIX: Utiliser 'classesname' au lieu de 'class' (colonne renommée dans Dolibarr récent)
-- Vérifier quelle colonne existe dans la table llx_cronjob
SET @has_class := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'llx_cronjob'
                   AND COLUMN_NAME = 'class');
SET @has_classesname := (SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'llx_cronjob'
                         AND COLUMN_NAME = 'classesname');

-- Supprimer les tâches cron selon la colonne disponible
SET @sqlstmt := IF(@has_classesname > 0,
                   'DELETE FROM llx_cronjob WHERE
                    (classesname = ''/shopifyintegration/class/orderwebhook.class.php'' AND objectname = ''ProductOrderWebhook'')
                    OR (classesname = ''/shopifyintegration/class/importproducts.class.php'' AND methodename = ''importProducts'')',
                   IF(@has_class > 0,
                      'DELETE FROM llx_cronjob WHERE
                       (class = ''/shopifyintegration/class/orderwebhook.class.php'' AND objectname = ''ProductOrderWebhook'')
                       OR (class = ''/shopifyintegration/class/importproducts.class.php'' AND method = ''importProducts'')',
                      'SELECT "Neither class nor classesname column exists in llx_cronjob - skipping CRON cleanup"'));
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
