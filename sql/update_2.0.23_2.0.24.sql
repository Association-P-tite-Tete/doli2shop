-- Date: 2025-01-08
-- Version: 2.0.24
-- Description: Add historical order import fields to storedetails table
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.23 to 2.0.24

-- Add historical_import_enabled column
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'historical_import_enabled');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN historical_import_enabled TINYINT(1) DEFAULT 0 COMMENT ''Enable historical order import on next CRON run''', 
                   'SELECT ''Column historical_import_enabled already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add historical_import_start_date column
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'historical_import_start_date');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN historical_import_start_date DATE DEFAULT NULL COMMENT ''Start date for historical order import''', 
                   'SELECT ''Column historical_import_start_date already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add historical_import_end_date column
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'historical_import_end_date');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN historical_import_end_date DATE DEFAULT NULL COMMENT ''End date for historical order import''', 
                   'SELECT ''Column historical_import_end_date already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add historical_import_completed column
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'historical_import_completed');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN historical_import_completed TINYINT(1) DEFAULT 0 COMMENT ''Whether historical import has been completed''', 
                   'SELECT ''Column historical_import_completed already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add historical_import_completed_date column
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
               AND COLUMN_NAME = 'historical_import_completed_date');
SET @sqlstmt := IF(@exist = 0, 
                   'ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN historical_import_completed_date DATETIME DEFAULT NULL COMMENT ''Date when historical import was completed''', 
                   'SELECT ''Column historical_import_completed_date already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update cron job labels to use translation keys instead of hardcoded English text
-- This enables multilingual support for cron job names
UPDATE llx_cronjob 
SET label = 'CronJobOrdersSync',
    note = 'CronJobOrdersSyncDesc'
WHERE objectname = 'ShopifyOrderSyncCron' 
AND module_name = 'shopifyintegration';

UPDATE llx_cronjob 
SET label = 'CronJobProductsSync',
    note = 'CronJobProductsSyncDesc'
WHERE objectname = 'ImportProductsCron'
AND module_name = 'shopifyintegration';