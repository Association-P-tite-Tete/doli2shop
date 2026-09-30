-- ============================================================================
-- Migration Doli2Shop : 2.6.0e -> 2.6.0f
--
-- Date        : 2026-09-28
-- Version     : 2.6.0
-- Description : Colonne images_priority_requeue sur llx_doli2shop_products — marque un produit
--               DIFFERE par ImportProducts::syncProductAllImages() (budget de polling du cycle
--               epuise, ou lot de medias d'un cycle precedent encore en cours de traitement chez
--               Shopify) pour un traitement PRIORITAIRE au cycle de contenu/stock suivant. Sans
--               ce signal, un produit chroniquement en fin de file (ordre deterministe de la
--               selection SQL de importProducts()) recreerait un lot complet de medias a CHAQUE
--               cycle sans jamais rien supprimer — accumulation illimitee de doublons. Re-review
--               3 couches (28/09/2026, HIGH) de la story
--               apparier-les-images-une-a-une-au-lieu-de-tout-detruire.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que les migrations precedentes) : jamais une
-- "partie 2" d'un fichier de migration deja livre. Suite chronologique de 2.6.0d_2.6.0e sur ce
-- meme cycle 2.6.0 non publie.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_products — images_priority_requeue
--
-- Colonne DISTINCTE de last_sync_status/last_sync_error (deja existantes) : ces deux colonnes
-- portent le statut/l'erreur du contenu produit dans son ENSEMBLE, lues par admin/diagnostic.php
-- et d'autres consommateurs existants. Les reutiliser pour un signal specifique aux images
-- aurait soit ecrase une vraie erreur de synchronisation de contenu, soit cache ce signal
-- derriere une information sans rapport — une colonne dediee garde les deux preoccupations
-- separees pour le cout d'une migration triviale.
--
-- 0 (defaut) = priorite normale. 1 = a traiter EN PRIORITE au prochain cycle de selection de
-- produits (voir l'ORDER BY des deux requetes de importProducts()). Remise a 0 des que le
-- produit est effectivement repris en main (succes, echec normal, ou nouveau lot confirme) ;
-- remise a 1 si ce nouveau cycle differe A NOUVEAU pour la meme raison.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'images_priority_requeue');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN images_priority_requeue TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = produit differe pour budget de polling images, a traiter en priorite au prochain cycle''',
                   'SELECT "Column images_priority_requeue already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
