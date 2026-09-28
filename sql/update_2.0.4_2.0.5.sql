-- Date: 2025-02-05
-- Version: 2.0.5
-- Description: Mise à jour de la table llx_dolibarr_shopify_orders_save
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.4 to 2.0.5
-- Dans la table llx_dolibarr_shopify_orders_save
-- Ajout de la colonne entity
-- Dans la table llx_shopify_dolibarr_storedetails
-- Ajout des colonnes order_bank_account

ALTER TABLE llx_dolibarr_shopify_orders_save ADD COLUMN entity integer DEFAULT 1 NOT NULL AFTER id;
-- Ajout de default_delivery_days
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN default_delivery_days INT DEFAULT 3 NOT NULL;
-- Ajout de shipping_product_id
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN shipping_product_id int DEFAULT NULL AFTER default_shipping_method_id;
-- Ajout de default_warehouse_id
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN default_warehouse_id int DEFAULT NULL AFTER shipping_product_id;
-- Ajout de max_orders_per_sync
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN max_orders_per_sync int DEFAULT 10;
-- Ajout de products_per_cron_update
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN products_per_cron_update int DEFAULT 10;



-- Renommage de default_shipping_method en default_shipping_method_id
ALTER TABLE llx_shopify_dolibarr_storedetails 
CHANGE default_shipping_method default_shipping_method_id INT DEFAULT 0 NOT NULL;

ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN order_bank_account integer DEFAULT NULL;