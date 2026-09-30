-- Description: This file contains the SQL code to create the table used to store the mapping between Dolibarr products and Shopify products.
-- Date: 2026-06-23
-- Version: 2.3.2
-- Author: P'tite Tête
-- Copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
-- Copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Table structure for table `llx_doli2shop_products`
CREATE TABLE llx_doli2shop_products
(
  -- Primary key
  id                  integer AUTO_INCREMENT PRIMARY KEY,

  -- Dolibarr entity and product references (grouped logically)
  entity              int(11) NOT NULL DEFAULT 1,
  fk_product          int(11) NOT NULL,
  fk_product_parent   int(11) DEFAULT NULL COMMENT 'ID of parent product for variants, NULL for simple products and parents',

  -- Store reference (Epic 47 — multi-boutiques)
  fk_store            int(11) NOT NULL DEFAULT 0 COMMENT 'Référence boutique Shopify (0 = non assigné legacy, rowid llx_doli2shop_stores après backfill)',

  -- Shopify references (grouped logically)
  shopifyProductId    varchar(255) NOT NULL,
  shopifyVariantId    varchar(255) DEFAULT NULL,

  -- Synchronization control fields (grouped logically)
  sync_lock           datetime DEFAULT NULL COMMENT 'Timestamp of sync lock to prevent concurrent synchronizations',
  last_sync_status    enum('success','failed','pending','skipped') DEFAULT 'pending',
  last_sync_error     text DEFAULT NULL,

  -- Timestamps
  tms                 datetime NOT NULL,
  last_stock_sync     datetime DEFAULT NULL COMMENT 'Dernière sync stock effective (push Shopify réussi)',

  -- Réparation batch des déclinaisons (hotfix 2.4.4 2/2, correctifs post-review 3-couches)
  variant_repair_status tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = candidat réparation variante, 1 = irréparable permanent (VariantRepairService)',

  -- Traitement produits "hors vente" déjà poussés (Story 58-5, AC6)
  offsale_action_status varchar(20) DEFAULT NULL COMMENT 'left_draft / archived — traitement OffSaleProductsService, NULL = non traité (pas de valeur pour supprimé, la ligne est retirée)',

  -- Suivi des non-appariés SKU (story variante-sans-correspondance-sku-ignoree-en-silence),
  -- écrit sur la ligne du produit PARENT (ou du produit simple), jamais sur une déclinaison enfant
  variant_mismatch_dol_count     int(11) DEFAULT NULL COMMENT 'Déclinaisons Dolibarr sans variante Shopify (dernier cycle)',
  variant_mismatch_shopify_count int(11) DEFAULT NULL COMMENT 'Variantes Shopify sans déclinaison Dolibarr (dernier cycle)',
  variant_mismatch_note          varchar(500) DEFAULT NULL COMMENT 'Détail court des SKU/refs non appariés (dernier cycle)',
  variant_mismatch_tms           datetime DEFAULT NULL COMMENT 'Horodatage du dernier calcul de non-appariés SKU',

  -- Provenance des médias Shopify créés par le module (story
  -- apparier-les-images-une-a-une-au-lieu-de-tout-detruire, review 3 couches 27/09/2026 HIGH)
  shopify_media_ids   text DEFAULT NULL COMMENT 'JSON : IDs Shopify des médias créés par le module (provenance)',

  -- Priorité de re-traitement images (même story, re-review 28/09/2026 HIGH) : produit différé
  -- (budget de polling épuisé, ou lot précédent encore en cours) à traiter en priorité
  images_priority_requeue tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = produit différé pour budget de polling images, à traiter en priorité au prochain cycle',

  -- Story stock-article-non-active-emplacement-reselection-perpetuelle : compteur de cycles
  -- CONSÉCUTIFS où au moins un article de ce produit a été écarté pour référence de stock non
  -- résolue à l'emplacement Shopify configuré — écrit sur la ligne du produit PARENT (ou du
  -- produit simple), JAMAIS sur celle d'une déclinaison enfant isolée (même convention que
  -- variant_mismatch_* ci-dessus)
  location_unresolved_streak int(11) NOT NULL DEFAULT 0 COMMENT 'Cycles consecutifs sans resolution de reference de stock pour la localisation Shopify configuree (0 = resolu ou jamais rencontre)',

  -- Défense en profondeur de la clé unique (story
  -- cle-unique-doli2shop-products-null-fk-product-parent, AC2) : substitue une sentinelle à NULL
  -- pour que la clé unique ci-dessous couvre aussi les produits SIMPLES (fk_product_parent NULL,
  -- que SQL traite comme jamais égal à lui-même dans une contrainte UNIQUE). JAMAIS écrire 0 dans
  -- fk_product_parent lui-même (violerait la FK fk_doli2shop_products_parent -> llx_product(rowid),
  -- aucun produit rowid=0) : colonne générée SÉPARÉE, cohérente avec default_key de
  -- llx_doli2shop_stores.sql (VIRTUAL, indexable depuis MySQL 5.7.8+/MariaDB 10.2+).
  fk_product_parent_key int(11) GENERATED ALWAYS AS (COALESCE(fk_product_parent, 0)) VIRTUAL
) ENGINE=innodb;

-- Add constraints with improved unique key structure from v2.0.23
-- UK étendue à fk_store (Epic 47-2) : permet un même produit Dolibarr sur N boutiques
-- Check if constraints exist before adding (idempotent script)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'uk_doli2shop_products_unique');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD UNIQUE INDEX uk_doli2shop_products_unique (fk_product, entity, fk_product_parent, fk_store)',
                   'SELECT "Index uk_doli2shop_products_unique already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Défense en profondeur (story cle-unique-doli2shop-products-null-fk-product-parent, AC2) :
-- couvre aussi le cas fk_product_parent NULL, via la colonne générée fk_product_parent_key
-- ci-dessus. L'ancien index uk_doli2shop_products_unique reste en place (inoffensif).
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'uk_doli2shop_products_parent_key');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD UNIQUE INDEX uk_doli2shop_products_parent_key (fk_product, entity, fk_product_parent_key, fk_store)',
                   'SELECT "Index uk_doli2shop_products_parent_key already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'idx_doli2shop_products_save_entity');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD INDEX idx_doli2shop_products_save_entity (entity)',
                   'SELECT "Index idx_doli2shop_products_save_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'idx_fk_product_parent');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD INDEX idx_fk_product_parent (fk_product_parent)',
                   'SELECT "Index idx_fk_product_parent already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND CONSTRAINT_NAME = 'fk_doli2shop_products_product');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD CONSTRAINT fk_doli2shop_products_product FOREIGN KEY (fk_product) REFERENCES llx_product (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key fk_doli2shop_products_product already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND CONSTRAINT_NAME = 'fk_doli2shop_products_parent');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD CONSTRAINT fk_doli2shop_products_parent FOREIGN KEY (fk_product_parent) REFERENCES llx_product (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key fk_doli2shop_products_parent already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur fk_store (Epic 47-2 — performance recherches par boutique)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'idx_doli2shop_products_fk_store');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD INDEX idx_doli2shop_products_fk_store (fk_store)',
                   'SELECT "Index idx_doli2shop_products_fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur les non-appariés SKU (story variante-sans-correspondance-sku-ignoree-en-silence —
-- recherche/comptage écran diagnostic)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND INDEX_NAME = 'idx_doli2shop_products_variant_mismatch');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD INDEX idx_doli2shop_products_variant_mismatch (entity, fk_product_parent, variant_mismatch_dol_count, variant_mismatch_shopify_count)',
                   'SELECT "Index idx_doli2shop_products_variant_mismatch already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
