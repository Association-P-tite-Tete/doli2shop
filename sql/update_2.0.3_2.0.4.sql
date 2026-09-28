-- Date: 2025-02-03
-- Version: 2.0.4
-- Description: Mise à jour de la table llx_shopify_dolibarr_storedetails
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.3 to 2.0.4
-- Dans la table llx_shopify_dolibarr_storedetails
ALTER TABLE llx_shopify_dolibarr_storedetails 
ADD COLUMN order_prefix VARCHAR(10) DEFAULT '',
ADD COLUMN delivery_delay INT DEFAULT 2,
ADD COLUMN delivery_delay_type VARCHAR(10) DEFAULT 'working', -- 'working' ou 'calendar'
ADD COLUMN order_origin INT,
ADD COLUMN payment_terms INT,
ADD COLUMN default_shipping_method INT;