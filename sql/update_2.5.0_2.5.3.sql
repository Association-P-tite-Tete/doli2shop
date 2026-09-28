-- =====================================================
-- MIGRATION v2.5.0 -> v2.5.3 (DOLI2SHOP)
-- Date: 2026-09-07
-- Version: 2.5.3
-- Description: Ajout table llx_doli2shop_product_images — correspondance shopify_image_id <->
--              fichier depose sur disque, pour empecher importProductImages() de retelecharger/
--              recopier une image deja presente a chaque synchronisation (webhook
--              products/update notamment, onlyNew=false). fk_store (revue coordinateur,
--              CRITICAL-3) : cle unique etendue a la boutique Shopify — un meme produit
--              Dolibarr peut etre rattache a plusieurs boutiques (Epic 47), chacune avec son
--              propre espace de shopify_image_id ; sans fk_store, un webhook de la boutique B
--              pouvait supprimer les fichiers importes par la boutique A. Story
--              import-images-duplique-les-photos-a-chaque-synchronisation.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut etre execute plusieurs fois, y compris s'il a deja
-- ete execute par une version anterieure de ce meme fichier avant fk_store)
-- Utilise information_schema.TABLES / information_schema.COLUMNS / information_schema.STATISTICS
-- Pattern obligatoire (regle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : TABLE llx_doli2shop_product_images
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_product_images');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE TABLE llx_doli2shop_product_images (
                       rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
                       entity INT NOT NULL DEFAULT 1,
                       fk_product INT NOT NULL COMMENT ''Dolibarr parent product rowid'',
                       shopify_image_id VARCHAR(64) NOT NULL COMMENT ''Identifiant Shopify de l''''image (id GraphQL, numerique extrait)'',
                       filename VARCHAR(255) NOT NULL COMMENT ''Nom du fichier depose sur disque (deterministe, prefixe doli2shop-shopify-)'',
                       content_hash VARCHAR(64) DEFAULT NULL COMMENT ''SHA256 optionnel du contenu telecharge (diagnostic)'',
                       fk_store INT NOT NULL DEFAULT 0 COMMENT ''Reference boutique Shopify (0 = chemin historique/constantes, rowid llx_doli2shop_stores sinon)'',
                       date_creation DATETIME NOT NULL COMMENT ''Date du premier depot de cette image''
                   ) ENGINE=innodb DEFAULT CHARSET=utf8 COMMENT=''Correspondance image Shopify <-> fichier importe, deduplication import''',
                   'SELECT "Table llx_doli2shop_product_images already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ADD COLUMN fk_store si la table a ete creee par une version anterieure de ce fichier (sans
-- fk_store) — no-op si la table vient d'etre creee ci-dessus (colonne deja presente).
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_product_images'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_product_images ADD COLUMN fk_store INT NOT NULL DEFAULT 0 COMMENT ''Reference boutique Shopify (0 = chemin historique/constantes, rowid llx_doli2shop_stores sinon)''',
                   'SELECT "Column fk_store already exists in llx_doli2shop_product_images"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Si un index unique 3 colonnes (fk_product, entity, shopify_image_id) existe deja SANS
-- fk_store (posé par une version anterieure de ce fichier), le supprimer pour le remplacer par
-- la version 4 colonnes ci-dessous — no-op si l'index n'existe pas encore, ou couvre deja fk_store.
SET @legacy_index_without_fk_store := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_product_images'
               AND INDEX_NAME = 'uk_doli2shop_product_images_image');
SET @index_already_has_fk_store := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_product_images'
               AND INDEX_NAME = 'uk_doli2shop_product_images_image'
               AND COLUMN_NAME = 'fk_store');
SET @sqlstmt := IF(@legacy_index_without_fk_store > 0 AND @index_already_has_fk_store = 0,
                   'ALTER TABLE llx_doli2shop_product_images DROP INDEX uk_doli2shop_product_images_image',
                   'SELECT "No legacy 3-column unique index to drop"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unique index (recree si supprime ci-dessus, ou cree directement si absent) : une correspondance
-- par produit/entite/boutique/image Shopify
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_product_images'
               AND INDEX_NAME = 'uk_doli2shop_product_images_image');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE UNIQUE INDEX uk_doli2shop_product_images_image ON llx_doli2shop_product_images (fk_product, entity, shopify_image_id, fk_store)',
                   'SELECT "Index uk_doli2shop_product_images_image already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index sur fk_store (performance recherches par boutique, coherent avec les autres tables)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_product_images'
               AND INDEX_NAME = 'idx_doli2shop_product_images_fk_store');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_product_images_fk_store ON llx_doli2shop_product_images (fk_store, entity)',
                   'SELECT "Index idx_doli2shop_product_images_fk_store already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
