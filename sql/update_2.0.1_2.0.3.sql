-- Date: 2025-02-03
-- Version: 2.0.3
-- Description: Mise à jour de la table llx_shopify_dolibarr_storedetails
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.1 to 2.0.3
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN shopify_vendor VARCHAR(255) DEFAULT 'Dolibarr Shopify' AFTER shopify_location_id;

