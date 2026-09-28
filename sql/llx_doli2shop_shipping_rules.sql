-- Date: 2026-03-11
-- Version: 2.2.0
-- Description: Création de la table llx_doli2shop_shipping_rules (Story 22.1)
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
--
-- Remplace llx_doli2shop_shipping (match_tracking booléen) par un modèle
-- à 2 niveaux de résolution : rule_type = 'title' (commande) ou 'tracking' (fulfillment).
-- Le tracking_company override toujours le title si une règle correspondante existe.

CREATE TABLE llx_doli2shop_shipping_rules (
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity                  INTEGER NOT NULL DEFAULT 1,
    rule_type               VARCHAR(10) NOT NULL,                   -- 'title' ou 'tracking'
    shopify_pattern         VARCHAR(255) NOT NULL,                  -- Pattern à matcher (title ou tracking_company)
    dol_shipping_method_id  INTEGER NOT NULL,                       -- FK vers llx_c_shipment_mode.rowid
    delivery_days           INTEGER DEFAULT 1,                      -- Délai de livraison en jours
    active                  TINYINT NOT NULL DEFAULT 1,             -- 1 = actif, 0 = désactivé sans suppression
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

-- Index composite pour les lookups par entity + rule_type
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND INDEX_NAME = 'idx_shipping_rules_entity_type');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_shipping_rules_entity_type ON llx_doli2shop_shipping_rules (entity, rule_type)',
                   'SELECT "Index idx_shipping_rules_entity_type already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Contrainte unique : pas de doublon (entity, rule_type, shopify_pattern)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_shipping_rules'
               AND INDEX_NAME = 'uk_shipping_rules_pattern');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_shipping_rules_pattern ON llx_doli2shop_shipping_rules (entity, rule_type, shopify_pattern)',
                   'SELECT "Index uk_shipping_rules_pattern already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
