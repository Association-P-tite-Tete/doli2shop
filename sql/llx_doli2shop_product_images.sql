-- =====================================================
-- TABLE llx_doli2shop_product_images
-- Date: 2026-09-07
-- Version: 2.5.3
-- Description: Correspondance shopify_image_id <-> fichier depose sur disque, pour empecher
--              importProductImages() de retelecharger/recopier une image deja presente a
--              chaque synchronisation (webhook products/update notamment, onlyNew=false).
--              fk_store (revue coordinateur, CRITICAL-3) : un meme produit Dolibarr peut etre
--              rattache a plusieurs boutiques Shopify (Epic 47) ; chaque boutique a son propre
--              espace de shopify_image_id, non partage avec les autres. Sans fk_store dans la
--              cle unique, un webhook de la boutique B pouvait supprimer les fichiers importes
--              par la boutique A (meme shopify_image_id numerique, deux images differentes).
--              Story import-images-duplique-les-photos-a-chaque-synchronisation.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================

CREATE TABLE llx_doli2shop_product_images (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INT NOT NULL DEFAULT 1,
    fk_product INT NOT NULL COMMENT 'Dolibarr parent product rowid',
    shopify_image_id VARCHAR(64) NOT NULL COMMENT 'Identifiant Shopify de l''image (id GraphQL, numerique extrait)',
    filename VARCHAR(255) NOT NULL COMMENT 'Nom du fichier depose sur disque (deterministe, prefixe doli2shop-shopify-)',
    content_hash VARCHAR(64) DEFAULT NULL COMMENT 'SHA256 optionnel du contenu telecharge (diagnostic)',
    fk_store INT NOT NULL DEFAULT 0 COMMENT 'Reference boutique Shopify (0 = chemin historique/constantes, rowid llx_doli2shop_stores sinon)',
    date_creation DATETIME NOT NULL COMMENT 'Date du premier depot de cette image'
) ENGINE=innodb DEFAULT CHARSET=utf8 COMMENT='Correspondance image Shopify <-> fichier importe, deduplication import (Story import-images-duplique)';

-- Unique index: une correspondance par produit/entite/boutique/image Shopify
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
