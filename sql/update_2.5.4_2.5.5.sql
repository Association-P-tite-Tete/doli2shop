-- ============================================================================
-- Migration Doli2Shop : 2.5.4 -> 2.5.5
--
-- Date        : 2026-09-16
-- Version     : 2.5.5
-- Description : Colonnes de suivi des variantes/declinaisons non appariees par SKU
--               sur llx_doli2shop_products. Story
--               variante-sans-correspondance-sku-ignoree-en-silence.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que update_2.5.3_2.5.3b.sql et
-- update_2.5.3b_2.5.4.sql) : jamais une "partie 2" d'un fichier de migration deja
-- livre.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_products — colonnes de suivi des non-apparies SKU
--
-- ImportProducts::updateVariantInventory() appariait les declinaisons Dolibarr aux
-- variantes Shopify uniquement par SKU, mais un "if" sans "else" avalait en silence
-- toute declinaison/variante sans correspondance (0 log, 0 remontee ecran) — signale
-- en production par une cliente (Cheer-Moda, 15/09/2026) dont le stock d'une
-- declinaison restait fige a 0 malgre un diagnostic a "0 erreur".
--
-- Ces colonnes persistent, sur la ligne du produit PARENT (ou du produit simple —
-- jamais sur celle d'une declinaison enfant isolee), le dernier compte de
-- non-apparies dans CHAQUE sens (ImportProducts::reportUnmatchedVariants()) :
--   - variant_mismatch_dol_count      : declinaisons Dolibarr sans variante Shopify
--   - variant_mismatch_shopify_count  : variantes Shopify sans declinaison Dolibarr
--   - variant_mismatch_note           : detail court (SKU/refs concernes, tronque)
--   - variant_mismatch_tms            : horodatage du dernier calcul
--
-- Ecrites a CHAQUE cycle (y compris 0/0, qui efface un signalement redevenu obsolete
-- des que le produit est reappare) — lues par admin/diagnostic.php et
-- admin/health.php (checkRecentSyncs()) via doli2shopBuildVariantMismatchReport()
-- (lib/doli2shop.lib.php), seule partie testable unitairement de cet ecran.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'variant_mismatch_dol_count');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN variant_mismatch_dol_count int(11) DEFAULT NULL COMMENT ''Declinaisons Dolibarr sans variante Shopify (dernier cycle)''',
                   'SELECT "Column variant_mismatch_dol_count already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'variant_mismatch_shopify_count');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN variant_mismatch_shopify_count int(11) DEFAULT NULL COMMENT ''Variantes Shopify sans declinaison Dolibarr (dernier cycle)''',
                   'SELECT "Column variant_mismatch_shopify_count already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'variant_mismatch_note');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN variant_mismatch_note varchar(500) DEFAULT NULL COMMENT ''Detail court des SKU/refs non apparies (dernier cycle)''',
                   'SELECT "Column variant_mismatch_note already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'variant_mismatch_tms');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN variant_mismatch_tms datetime DEFAULT NULL COMMENT ''Horodatage du dernier calcul de non-apparies SKU''',
                   'SELECT "Column variant_mismatch_tms already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
