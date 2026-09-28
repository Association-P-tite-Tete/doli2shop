-- Date: 2025-02-10
-- Version: 2.0.6
-- Description: Mise à jour de la table llx_dolibarr_shopify_orders_save
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.5 to 2.0.6
-- Dans la table llx_dolibarr_shopify_orders_save
-- Renommage de la colonne fk_order en fk_order_id
-- Ajout de la contrainte de clé étrangère sur la colonne fk_order_id

-- FIX #144 v2.1.2: SUPPRESSION TRUNCATE - Migration idempotente sans perte de données
-- ❌ ANCIEN CODE (DÉTRUIT LES DONNÉES):
-- TRUNCATE TABLE llx_dolibarr_shopify_products_save;
-- ALTER TABLE llx_dolibarr_shopify_products_save AUTO_INCREMENT = 1;

-- ============================================================================
-- FIX v2.5.0 (story 62-1) : GARDE D'EXISTENCE DE TABLE
--
-- Les deux tables visées par ce script (`llx_dolibarr_shopify_products_save` et
-- `llx_dolibarr_shopify_orders_save`) ont été renommées depuis : elles ne figurent
-- dans AUCUN fichier `sql/llx_*.sql` du schéma actuel. Une installation récente ne
-- les possède donc pas.
--
-- Or ce script EST câblé dans `modDoli2Shop::init()` : il s'exécute à chaque
-- activation du module. Les gardes ci-dessous vérifiaient l'existence de l'INDEX ou
-- de la CONTRAINTE, jamais celle de la TABLE — et `information_schema` renvoie
-- logiquement 0 pour l'index d'une table absente. Le `IF(... = 0, 'ALTER TABLE ...')`
-- partait donc contre une table inexistante et produisait une erreur SQL.
--
-- Les renommages de colonnes (`@exist_old_col`) étaient, eux, déjà protégés : ils
-- dégradent naturellement en no-op quand la table manque.
--
-- ⚠️ Ne jamais retirer les tests `@table_exists_* > 0` ajoutés ci-dessous.
-- ============================================================================
SET @table_products_save := (SELECT COUNT(*) FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'llx_dolibarr_shopify_products_save');
SET @table_orders_save := (SELECT COUNT(*) FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save');

-- ✅ NOUVEAU CODE: Renommage conditionnel colonne dolProId → fk_product
SET @exist_old_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                       AND COLUMN_NAME = 'dolProId');
SET @exist_new_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                       AND COLUMN_NAME = 'fk_product');

-- Renommer SEULEMENT si ancienne colonne existe ET nouvelle n'existe pas
SET @sqlstmt := IF(@exist_old_col > 0 AND @exist_new_col = 0,
                   'ALTER TABLE llx_dolibarr_shopify_products_save CHANGE dolProId fk_product int(11)',
                   'SELECT "Column already renamed or does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FIX #144 v2.1.2: Ajout conditionnel contraintes (idempotence)
-- Vérifier si l'index unique existe déjà
SET @exist_uk := (SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                  AND INDEX_NAME = 'uk_dolibarr_shopify_products_save_fk_product');

SET @sqlstmt := IF(@table_products_save > 0 AND @exist_uk = 0,
                   'ALTER TABLE llx_dolibarr_shopify_products_save ADD UNIQUE INDEX uk_dolibarr_shopify_products_save_fk_product (fk_product)',
                   'SELECT "Unique index already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Vérifier si la contrainte FK existe déjà
SET @exist_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                  WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                  AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_products_product');

SET @sqlstmt := IF(@table_products_save > 0 AND @exist_fk = 0,
                   'ALTER TABLE llx_dolibarr_shopify_products_save ADD CONSTRAINT fk_dolibarr_shopify_products_product FOREIGN KEY (fk_product) REFERENCES llx_product (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key constraint already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FIX #144 v2.1.2: Renommage conditionnel colonne dolOrderId → fk_commande
SET @exist_old_col_order := (SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
                              AND COLUMN_NAME = 'dolOrderId');
SET @exist_new_col_order := (SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
                              AND COLUMN_NAME = 'fk_commande');

SET @sqlstmt := IF(@exist_old_col_order > 0 AND @exist_new_col_order = 0,
                   'ALTER TABLE llx_dolibarr_shopify_orders_save CHANGE dolOrderId fk_commande int(11)',
                   'SELECT "Column already renamed or does not exist"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FIX #144 v2.1.2: Ajout conditionnel index unique fk_commande
SET @exist_uk_order := (SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
                        AND INDEX_NAME = 'uk_dolibarr_shopify_orders_save_fk_commande');

SET @sqlstmt := IF(@table_orders_save > 0 AND @exist_uk_order = 0,
                   'ALTER TABLE llx_dolibarr_shopify_orders_save ADD UNIQUE INDEX uk_dolibarr_shopify_orders_save_fk_commande (fk_commande)',
                   'SELECT "Unique index fk_commande already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FIX #144 v2.1.2: Ajout conditionnel contrainte FK fk_commande
SET @exist_fk_order := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
                        AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_orders_commande');

SET @sqlstmt := IF(@table_orders_save > 0 AND @exist_fk_order = 0,
                   'ALTER TABLE llx_dolibarr_shopify_orders_save ADD CONSTRAINT fk_dolibarr_shopify_orders_commande FOREIGN KEY (fk_commande) REFERENCES llx_commande (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key constraint already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FIX #144 v2.1.2: Ajout conditionnel index unique shopifyOrderId
SET @exist_uk_shopify_order_id := (SELECT COUNT(*) FROM information_schema.STATISTICS
                                    WHERE TABLE_SCHEMA = DATABASE()
                                    AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
                                    AND INDEX_NAME = 'uk_dolibarr_shopify_orders_save_shopify_id');

SET @sqlstmt := IF(@table_orders_save > 0 AND @exist_uk_shopify_order_id = 0,
                   'ALTER TABLE llx_dolibarr_shopify_orders_save ADD UNIQUE INDEX uk_dolibarr_shopify_orders_save_shopify_id (shopifyOrderId)',
                   'SELECT "Unique index shopifyOrderId already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;