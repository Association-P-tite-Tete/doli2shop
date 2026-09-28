-- ================================================================================================
-- Description: Update script from version 2.0.17 to 2.0.18
-- Author: P'tite Tête
-- Copyright 2025 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- ================================================================================================

-- Allow shopifyVariantId to be NULL for parent products without variants
ALTER TABLE llx_dolibarr_shopify_products_save 
MODIFY COLUMN shopifyVariantId varchar(255) DEFAULT NULL;