-- Date: 2025-05-09
-- Version: 2.0.16
-- Description: Addition of virtual stock option and entity support for Shopify integration
-- Author: P'tite Tête
-- Copyright 2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright 2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.15 to 2.0.16
-- Add use_virtual_stock and entity columns to llx_shopify_dolibarr_storedetails table

-- Add virtual stock option
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN use_virtual_stock TINYINT(1) NOT NULL DEFAULT 1;
-- Add tip service product field
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN tip_product_id int(11) NULL;

-- Add entity column with default value 1 (main entity)
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN entity int(11) NOT NULL DEFAULT 1;
-- Add index for entity column for better performance
ALTER TABLE llx_shopify_dolibarr_storedetails ADD INDEX idx_shopify_dolibarr_storedetails_entity (entity);

-- Add entity column to llx_dolibarr_shopify_products_save table
ALTER TABLE llx_dolibarr_shopify_products_save ADD COLUMN entity int(11) NOT NULL DEFAULT 1 AFTER fk_product;

-- Add index for entity
ALTER TABLE llx_dolibarr_shopify_products_save ADD INDEX idx_dolibarr_shopify_products_save_entity (entity);

-- Remove unique constraint on fk_product to allow same product ID in different entities
ALTER TABLE llx_dolibarr_shopify_products_save DROP INDEX uk_dolibarr_shopify_products_save_fk_product;

-- Add new unique constraint on fk_product and entity
ALTER TABLE llx_dolibarr_shopify_products_save ADD UNIQUE INDEX uk_dolibarr_shopify_products_save_fk_product_entity (fk_product, entity);