-- Date: 2025-05-22
-- Version: 2.0.17
-- Description: Ajout des options de synchronisation des produits
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.16 to 2.0.17

-- Ajout des colonnes pour les options de synchronisation des produits
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_product_prices TINYINT DEFAULT 1 NOT NULL AFTER use_virtual_stock;
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_product_descriptions TINYINT DEFAULT 1 NOT NULL AFTER sync_product_prices;
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_product_images TINYINT DEFAULT 1 NOT NULL AFTER sync_product_descriptions;
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_product_stocks TINYINT DEFAULT 1 NOT NULL AFTER sync_product_images;
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_product_attributes TINYINT DEFAULT 1 NOT NULL AFTER sync_product_stocks;
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN sync_price_level INT DEFAULT 0 NOT NULL AFTER sync_product_prices;

-- Allow shopifyVariantId to be NULL for parent products without variants
ALTER TABLE llx_dolibarr_shopify_products_save 
MODIFY COLUMN shopifyVariantId varchar(255) DEFAULT NULL;