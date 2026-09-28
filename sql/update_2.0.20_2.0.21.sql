-- Date: 2025-05-22
-- Version: 2.0.21
-- Description: Ajout de la politique d'inventaire pour continuer à vendre en rupture
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.20 to 2.0.21

-- Ajout de la colonne pour la politique d'inventaire (continuer à vendre en cas de rupture)
ALTER TABLE llx_shopify_dolibarr_storedetails ADD COLUMN inventory_policy_continue_selling TINYINT DEFAULT 1 NOT NULL AFTER sync_product_attributes;