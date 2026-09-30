-- ============================================================================
-- Migration Doli2Shop : 2.6.0b -> 2.6.0c
--
-- Date        : 2026-09-27
-- Version     : 2.6.0
-- Description : Colonne de persistance du compteur de cycles consecutifs "reference de stock
--               non resolue a l'emplacement Shopify configure" sur llx_doli2shop_products.
--               Story stock-article-non-active-emplacement-reselection-perpetuelle.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que les migrations precedentes) : jamais une
-- "partie 2" d'un fichier de migration deja livre. 2.6.0_2.6.0b (dedoublonnage boutiques par
-- defaut) est deja cablee et livree ; celle-ci cible donc 2.6.0b_2.6.0c (meme cycle 2.6.0 pas
-- encore publie, meme convention de suffixe que 2.5.3_2.5.3b).
--
-- Coordination inter-stories (2026-09-27) : une autre story du meme train (cle unique
-- llx_doli2shop_products) prendra la cle suivante 2.6.0c_2.6.0d — ne jamais reutiliser cette
-- cle-ci pour un autre correctif.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_products — colonne de persistance du plafond de re-tentatives
--
-- Sans ce compteur, un article jamais active a l'emplacement Shopify configure (variante creee
-- recemment cote Shopify, jamais rattachee a cet emplacement dans l'admin "Locations" de la fiche
-- produit) fait reselectionner son produit PARENT a CHAQUE cycle CRON, indefiniment : la ligne de
-- bookkeeping du parent n'est volontairement pas rafraichie tant qu'un article du lot est ecarte
-- (comportement voulu pour un incident TRANSITOIRE), mais rien ne distingue "echoue une fois" de
-- "echoue structurellement depuis N cycles" (finding #5 de la re-review 3 couches du hotfix 2.5.7,
-- 27/09/2026).
--
-- Cette colonne persiste, sur la ligne du produit PARENT (ou du produit simple — JAMAIS sur celle
-- d'une declinaison enfant isolee), le nombre de cycles CONSECUTIFS ou au moins un article du lot
-- a ete ecarte pour reference de stock non resolue (ImportProducts::dropUnresolvedInventoryReferences()
-- appele depuis syncVariantStocksOnly()). Remise a 0 des que le lot est de nouveau integralement
-- resolu (incident regle, ou activation Shopify faite entre-temps) : le plafond ne devient jamais
-- un blocage permanent.
--
-- Ecrite a CHAQUE cycle ou au moins un article de ce produit est concerne (incrementee si ecarte,
-- remise a 0 si resolu) — lue par ImportProducts::buildStockCatchupEligibilityClause() (clause de
-- selection SQL du cron) pour elargir l'intervalle de re-tentative une fois le plafond
-- (DOLI2SHOP_LOCATION_UNRESOLVED_STREAK_CAP, defaut 5) atteint.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'location_unresolved_streak');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN location_unresolved_streak int(11) NOT NULL DEFAULT 0 COMMENT ''Cycles consecutifs sans resolution de reference de stock pour la localisation Shopify configuree (0 = resolu ou jamais rencontre)''',
                   'SELECT "Column location_unresolved_streak already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
