-- ============================================================================
-- Copyright (C) 2022-2025 Robert Steinbacher <robert.steinbacher@xivtech.de>
-- Copyright (C) 2022-2025 Thomas Meigen <info@meigensmartsolutions.de>
-- Copyright (C) 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--
-- ============================================================================

CREATE TABLE llx_doli2shop_order_status_shop2dol (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    entity INT NOT NULL DEFAULT 1,
    fk_shopify_store INT NOT NULL DEFAULT 1,               -- Champ conservé pour compatibilité, valeur fixe 1
    shopify_financial_status VARCHAR(50) NOT NULL,          -- PAID, PARTIALLY_PAID, PENDING, VOIDED, etc.
    shopify_fulfillment_status VARCHAR(50) NOT NULL,        -- FULFILLED, PARTIALLY_FULFILLED, UNFULFILLED
    dolibarr_status INT NOT NULL,                           -- 0=draft, 1=validated, 2=processing, 3=delivered, 4=canceled, 5=closed
    default_mapping TINYINT(1) NOT NULL DEFAULT 0,         -- 1 if this is a default mapping
    active TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 10,                      -- Higher priority mappings are applied first
    description VARCHAR(255),
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doli2shop_order_status_shop2dol (entity, shopify_financial_status, shopify_fulfillment_status),
    KEY idx_doli2shop_order_status_shop2dol_entity (entity)
) ENGINE=InnoDB;