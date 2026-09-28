-- Date: 2025-04-03
-- Version: 2.0.14
-- Description: Column name corrections and table structure verification
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.13 to 2.0.14

-- Check and correct column names in llx_shopify_dolibarr_storedetails

-- Check if the column shopifystorename still exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'shopifystorename';

-- If it exists, rename it
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE shopifystorename shopify_store_hostname varchar(255) NOT NULL');
SET @skipColumn = 'SELECT 1';

PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the column shopifystorekey still exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'shopifystorekey';

-- If it exists, rename it
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE shopifystorekey shopify_access_token varchar(255) NOT NULL');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the column shopify_acess_token (with typo) exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'shopify_acess_token';

-- If it exists, rename it correctly
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE shopify_acess_token shopify_access_token varchar(255) NOT NULL');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the column dolapikey still exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'dolapikey';

-- If it exists, rename it
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE dolapikey dolibarr_api_key varchar(255) NOT NULL');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the column dolhosturl still exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'dolhosturl';

-- If it exists, rename it
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE dolhosturl dolibarr_hosturl varchar(255) NOT NULL');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the column dolprocate still exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'dolprocate';

-- If it exists, rename it
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE dolprocate dolibarr_procate varchar(255) NULL');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check for the existence of columns added in previous updates
-- If a column doesn't exist, add it

-- Check if the shopify_location_id column exists (added in 2.0.1)
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'shopify_location_id';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN shopify_location_id varchar(255) DEFAULT NULL AFTER shopify_api_secret_key');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the shopify_vendor column exists (added in 2.0.3)
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'shopify_vendor';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN shopify_vendor VARCHAR(255) DEFAULT ''Dolibarr Shopify'' AFTER shopify_location_id');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check for columns added in 2.0.4
-- order_prefix
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'order_prefix';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN order_prefix VARCHAR(10) DEFAULT ''''');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- delivery_delay
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'delivery_delay';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN delivery_delay INT DEFAULT 2');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- delivery_delay_type
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'delivery_delay_type';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN delivery_delay_type VARCHAR(10) DEFAULT ''working''');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- order_origin
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'order_origin';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN order_origin INT');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- payment_terms
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'payment_terms';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN payment_terms INT');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if default_shipping_method still exists (before renaming)
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'default_shipping_method';

-- If it exists, rename it to default_shipping_method_id
SET @renameColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails CHANGE default_shipping_method default_shipping_method_id INT DEFAULT 0 NOT NULL');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if default_shipping_method_id exists
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'default_shipping_method_id';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN default_shipping_method_id INT DEFAULT 0 NOT NULL');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check for columns added in 2.0.5
-- default_delivery_days
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'default_delivery_days';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN default_delivery_days INT DEFAULT 3 NOT NULL');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- shipping_product_id
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'shipping_product_id';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN shipping_product_id int DEFAULT NULL AFTER default_shipping_method_id');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- default_warehouse_id
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'default_warehouse_id';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN default_warehouse_id int DEFAULT NULL AFTER shipping_product_id');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- max_orders_per_sync
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'max_orders_per_sync';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN max_orders_per_sync int DEFAULT 10');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- products_per_cron_update
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'products_per_cron_update';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN products_per_cron_update int DEFAULT 10');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- order_bank_account
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails' 
AND COLUMN_NAME = 'order_bank_account';

SET @addColumn = CONCAT('ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN order_bank_account integer DEFAULT NULL');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the llx_dolibarr_shopify_orders_save table has the entity column
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save' 
AND COLUMN_NAME = 'entity';

SET @addColumn = CONCAT('ALTER TABLE llx_dolibarr_shopify_orders_save ADD COLUMN entity integer DEFAULT 1 NOT NULL AFTER id');
PREPARE stmt FROM IF(@columnExists = 0, @addColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if dolOrderId still exists in llx_dolibarr_shopify_orders_save
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save' 
AND COLUMN_NAME = 'dolOrderId';

-- If it exists, rename it to fk_commande
SET @renameColumn = CONCAT('ALTER TABLE llx_dolibarr_shopify_orders_save CHANGE dolOrderId fk_commande int(11)');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if dolProId still exists in llx_dolibarr_shopify_products_save
SET @columnExists = 0;
SELECT COUNT(*) INTO @columnExists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 'llx_dolibarr_shopify_products_save' 
AND COLUMN_NAME = 'dolProId';

-- If it exists, rename it to fk_product
SET @renameColumn = CONCAT('ALTER TABLE llx_dolibarr_shopify_products_save CHANGE dolProId fk_product int(11)');
PREPARE stmt FROM IF(@columnExists > 0, @renameColumn, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the constraint on fk_commande exists
SET @constraintExists = 0;
SELECT COUNT(*) INTO @constraintExists
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_orders_commande';

-- Add uniqueness constraints and foreign keys if they don't exist
SET @addConstraint = CONCAT('
    ALTER TABLE llx_dolibarr_shopify_orders_save 
    ADD UNIQUE INDEX uk_dolibarr_shopify_orders_save_fk_commande (fk_commande),
    ADD CONSTRAINT fk_dolibarr_shopify_orders_commande FOREIGN KEY (fk_commande) REFERENCES llx_commande (rowid) ON DELETE CASCADE
');

PREPARE stmt FROM IF(@constraintExists = 0, @addConstraint, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if the constraint on shopifyOrderId exists
SET @constraintExists = 0;
SELECT COUNT(*) INTO @constraintExists
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
AND CONSTRAINT_NAME = 'uk_dolibarr_shopify_orders_save_shopify_id';

-- Add the uniqueness constraint on shopifyOrderId if it doesn't exist
SET @addConstraint = CONCAT('
    ALTER TABLE llx_dolibarr_shopify_orders_save
    ADD UNIQUE INDEX uk_dolibarr_shopify_orders_save_shopify_id (shopifyOrderId)
');

PREPARE stmt FROM IF(@constraintExists = 0, @addConstraint, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if constraints on fk_product exist
SET @constraintExists = 0;
SELECT COUNT(*) INTO @constraintExists
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_products_product';

-- Add uniqueness constraints and foreign keys if they don't exist
SET @addConstraint = CONCAT('
    ALTER TABLE llx_dolibarr_shopify_products_save 
    ADD UNIQUE INDEX uk_dolibarr_shopify_products_save_fk_product (fk_product),
    ADD CONSTRAINT fk_dolibarr_shopify_products_product FOREIGN KEY (fk_product) REFERENCES llx_product (rowid) ON DELETE CASCADE
');

PREPARE stmt FROM IF(@constraintExists = 0, @addConstraint, @skipColumn);
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update the cron jobs to the new format
-- First, remove old cron jobs from version 1.0.0 if they still exist
DELETE FROM llx_cronjob WHERE 
  (classesname = '/shopifyintegration/class/orderwebhook.class.php' AND objectname = 'ProductOrderWebhook') 
  OR (classesname = '/shopifyintegration/class/importproducts.class.php' AND methodename = 'classesname');

-- ⚠️ REMOVED: CRON jobs creation - CRONs are now managed by the module class only
-- The module modShopifyIntegration.class.php automatically creates the required CRONs
-- Removing this section prevents duplicate CRON creation

-- Create payment methods mapping table using compatible syntax
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES 
               WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = 'llx_shopify_payment_methods_mapping');
SET @sqlstmt := IF(@exist = 0, 
                   'CREATE TABLE llx_shopify_payment_methods_mapping (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    shopify_payment_method VARCHAR(255) NOT NULL,
    dolibarr_payment_id INTEGER NOT NULL,
    entity INTEGER DEFAULT 1 NOT NULL,
    active INTEGER DEFAULT 1 NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb', 
                   'SELECT ''Table llx_shopify_payment_methods_mapping already exists''');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Insert default values for payment methods mapping only if none exist
SET @count = 0;
SELECT COUNT(*) INTO @count FROM llx_shopify_payment_methods_mapping;

-- CB ID (Priorité: CB > CARD/CREDIT_CARD > 6)
SET @cb_id = (SELECT id FROM llx_c_paiement WHERE code = 'CB' AND active = 1 LIMIT 1);
SET @cb_id = IFNULL(@cb_id, (SELECT id FROM llx_c_paiement WHERE code IN ('CARD', 'CREDIT_CARD') AND active = 1 LIMIT 1));
SET @cb_id = IFNULL(@cb_id, 6);

-- Virement ID (Priorité: VIR > TRA/BANK/TRANSFER > 2)
SET @vir_id = (SELECT id FROM llx_c_paiement WHERE code = 'VIR' AND active = 1 LIMIT 1);
SET @vir_id = IFNULL(@vir_id, (SELECT id FROM llx_c_paiement WHERE code IN ('TRA', 'BANK', 'TRANSFER') AND active = 1 LIMIT 1));
SET @vir_id = IFNULL(@vir_id, 2);

-- Chèque ID (Priorité: CHQ > CHECK/CHEQUE > 7)
SET @chq_id = (SELECT id FROM llx_c_paiement WHERE code = 'CHQ' AND active = 1 LIMIT 1);
SET @chq_id = IFNULL(@chq_id, (SELECT id FROM llx_c_paiement WHERE code IN ('CHECK', 'CHEQUE') AND active = 1 LIMIT 1));
SET @chq_id = IFNULL(@chq_id, 7);

-- Espèces ID (Priorité: LIQ > CASH/MONEY > 4)
SET @liq_id = (SELECT id FROM llx_c_paiement WHERE code = 'LIQ' AND active = 1 LIMIT 1);
SET @liq_id = IFNULL(@liq_id, (SELECT id FROM llx_c_paiement WHERE code IN ('CASH', 'MONEY') AND active = 1 LIMIT 1));
SET @liq_id = IFNULL(@liq_id, 4);

-- Insertion conditionnelle
-- Insertion conditionnelle des méthodes de paiement
INSERT INTO llx_shopify_payment_methods_mapping 
    (shopify_payment_method, dolibarr_payment_id, entity, active)
SELECT shopify_payment_method, dolibarr_payment_id, entity, active
FROM (
    SELECT 'card' AS shopify_payment_method, @cb_id AS dolibarr_payment_id, 1 AS entity, 1 AS active
    UNION ALL
    SELECT 'bank', @vir_id, 1, 1
    UNION ALL
    SELECT 'check', @chq_id, 1, 1
    UNION ALL
    SELECT 'paypal', @cb_id, 1, 1
    UNION ALL
    SELECT 'applepay', @cb_id, 1, 1
    UNION ALL
    SELECT 'googlepay', @cb_id, 1, 1
    UNION ALL
    SELECT 'stripe', @cb_id, 1, 1
    UNION ALL
    SELECT 'cash', @liq_id, 1, 1
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_shopify_payment_methods_mapping
);