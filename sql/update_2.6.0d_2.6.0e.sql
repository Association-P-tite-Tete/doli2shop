-- ============================================================================
-- Migration Doli2Shop : 2.6.0d -> 2.6.0e
--
-- Date        : 2026-09-27
-- Version     : 2.6.0
-- Description : Colonne shopify_media_ids sur llx_doli2shop_products — provenance EXPLICITE des
--               medias Shopify crees par le module. Review 3 couches (27/09/2026, HIGH) de la
--               story apparier-les-images-une-a-une-au-lieu-de-tout-detruire : la selection des
--               anciens medias a supprimer lors d'un remplacement ne doit JAMAIS reposer sur la
--               POSITION (un array_slice sur l'ordre de POSITION pouvait supprimer une photo
--               ajoutee a la main par le client et promue en tete), mais sur la PROVENANCE.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que les migrations precedentes) : jamais une
-- "partie 2" d'un fichier de migration deja livre. Suffixe "d_e" et non "b_c"/"c_d" : ces deux
-- cles sont deja prises par deux autres branches en cours sur ce meme cycle 2.6.0 non publie
-- (2.6.0b_2.6.0c, 2.6.0c_2.6.0d), non presentes dans ce worktree.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_products — shopify_media_ids
--
-- JSON (tableau de chaines) des identifiants Shopify (gid://shopify/MediaImage/...) que le
-- module sait avoir CREES lui-meme pour ce produit. Alimentee par
-- ImportProducts::saveKnownModuleMediaIds(), a chaque cycle qui cree au moins un media (meme en
-- succes PARTIEL) — et enrichie progressivement des medias ANTERIEURS a cette colonne des qu'ils
-- sont reconnus par appariement du texte alternatif (isExistingMediaCreatedByModule(), preuves
-- (b)/(c)) et survivent a une suppression : auto-guerison progressive, la provenance explicite
-- (a) devenant la seule preuve necessaire une fois un media reconnu au moins une fois.
--
-- NULL sur toute ligne pas encore touchee par un cycle images depuis cette story — traite comme
-- une liste VIDE par ImportProducts::decodeKnownModuleMediaIds(), jamais comme une erreur.
--
-- Colonne posee SANS filtre fk_store dans cette migration (DDL par schema, pas par ligne) — la
-- LECTURE/ECRITURE applicative reste actuellement non filtree par fk_store, motif PREEXISTANT
-- documente separement (story fk-store-absent-du-mapping-produit-shopify-images), pas introduit
-- ni corrige ici.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'shopify_media_ids');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN shopify_media_ids TEXT DEFAULT NULL COMMENT ''JSON : IDs Shopify des medias crees par le module (provenance, story apparier-les-images-une-a-une)''',
                   'SELECT "Column shopify_media_ids already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
