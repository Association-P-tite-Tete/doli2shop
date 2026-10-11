-- ============================================================================
-- Migration Doli2Shop : 2.6.1 -> 2.6.2
--
-- Date        : 2026-10-11
-- Version     : 2.6.2
-- Description : UNIQUE fichier de migration du cycle 2.6.2 (hotfix). Colonnes last_content_sync (reference STABLE du
--               « dernier contenu synchronise »), last_failed_at (R4) et failed_streak (S5) sur llx_doli2shop_products.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE : jamais une « partie 2 » d'un fichier de migration deja
-- livre (isMigrationApplied() compare la chaine de version).
-- ============================================================================

-- ============================================================================
-- Hotfix 2.6.2 — llx_doli2shop_products.last_content_sync
--
-- Cause : tms est un TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP sur toute base migree
-- (update_2.1.5_2.1.6.sql). syncProduct() pose le verrou 'pending' AVANT de decider si le produit a
-- change : ce simple UPDATE ramene tms a NOW(), donc « modifie depuis la derniere synchro » et la
-- regle des 24 h etaient TOUJOURS faux en mode cron. Aucune modification de contenu ni de prix
-- n'etait propagee. last_content_sync n'a pas d'ON UPDATE : elle n'est ecrite que par une vraie
-- synchro de contenu.
--
-- NULL = jamais synchronise en contenu depuis cette colonne. Elle ne vaut « produit a renvoyer » QUE
-- pour une ligne success, skipped avec un shopifyProductId valide, ou pending a verrou perime
-- (jamais pour failed, sinon boucle).
-- DDL : information_schema + PREPARE, jamais IF NOT EXISTS.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'last_content_sync');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN last_content_sync DATETIME DEFAULT NULL COMMENT ''Derniere synchro de CONTENU reussie (productSet), jamais touchee par le verrou pending ni le stock seul''',
                   'SELECT "Column last_content_sync already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Hotfix 2.6.2 (R4) — llx_doli2shop_products.last_failed_at
--
-- Horodatage du dernier passage en `failed`. Le cron ne retente le CONTENU d'une ligne en echec qu'apres un delai
-- croissant (failed_streak, plafond 6 h ; sinon dix echecs permanents reprendraient chacun une place a chaque
-- cycle : la famine reviendrait par la porte des `failed`). Le stock n'est jamais retarde. NULL = jamais echoue
-- depuis cette colonne = retentable immediatement.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'last_failed_at');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN last_failed_at DATETIME DEFAULT NULL COMMENT ''Dernier passage en failed (le cron ne retente le contenu qu apres un delai croissant de 1 h a 6 h), NULL apres un succes''',
                   'SELECT "Column last_failed_at already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Hotfix 2.6.2 (S5) — llx_doli2shop_products.failed_streak
--
-- Nombre d'echecs CONSECUTIFS du contenu : pilote le delai croissant avant retentative
-- (min(6 h, 1 h x 2^(streak - 1)) depuis last_failed_at). Incremente a chaque passage en failed, remis a 0 par un
-- succes de CONTENU (jamais par un succes « stock seul »). 0 = aucun echec en cours.
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'failed_streak');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_products ADD COLUMN failed_streak INT NOT NULL DEFAULT 0 COMMENT ''Echecs consecutifs du contenu (delai croissant avant retentative), 0 apres un succes de contenu''',
                   'SELECT "Column failed_streak already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Backfill (decision C2 du 2e Validate, precisee par R2 de la review du 11/10/2026) — lignes
-- last_sync_status = 'success', OU 'skipped' avec un shopifyProductId valide (boutique sans synchro
-- de stock), dont last_content_sync est encore NULL :
--   - NULL conserve si une ligne llx_product_price (date_price) du produit OU de l'une de ses
--     declinaisons date des 30 derniers jours : le PRIX est le signal fiable de l'incident du 10/10
--     (94 declinaisons) ; ces produits sont RENVOYES en contenu. (llx_product.tms n'est plus le
--     critere : il bouge aussi a chaque mouvement de stock, donc presque tout le catalogue actif
--     repartirait en masse.) ;
--   - sinon tms du mapping : le reste du catalogue ne repart pas en masse ;
--   - tms du mapping INVALIDE (<= 1970-01-02, valeur epoque/zero) : on le garde tel quel, JAMAIS
--     NULL (ECH-12) — une date nulle ne doit pas declencher un renvoi.
-- « tms = tms » : sans cette affectation explicite, ON UPDATE CURRENT_TIMESTAMP repositionnerait toutes
-- les lignes a l'instant present (meme motif que le bloc 66-1 de update_2.6.0f_2.6.1.sql). Dans un
-- UPDATE, MySQL et MariaDB evaluent les affectations de gauche a droite : tms vaut encore sa valeur
-- d'origine dans le CASE, qui est place AVANT « tms = tms ».
-- Aucune dependance a la boutique par defaut ni a fk_store : l'invariant « backfill dependant de la
-- boutique en PHP apres ensureDefaultStore() » ne s'applique pas. Pas de filtre entity : correctif de
-- donnees, toutes entites. Garde d'existence de llx_product_price et de la table des declinaisons
-- (tables du coeur Dolibarr), sous une forme volontairement differente du motif « TABLE_NAME = x » que
-- le test de migrations utilise pour decider quelles tables cloner.
-- Idempotent : ne touche que les lignes encore NULL.
-- ============================================================================

SET @core_tables := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_NAME IN ('llx_product_price', 'llx_product_attribute_combination')
                     AND TABLE_SCHEMA = DATABASE());
SET @sqlstmt := IF(@core_tables = 2,
                   'UPDATE llx_doli2shop_products sync SET sync.last_content_sync = CASE WHEN sync.tms <= ''1970-01-02'' THEN sync.tms WHEN EXISTS (SELECT 1 FROM llx_product_price pr WHERE pr.fk_product = sync.fk_product AND pr.date_price >= DATE_SUB(NOW(), INTERVAL 30 DAY)) OR EXISTS (SELECT 1 FROM llx_product_attribute_combination pac INNER JOIN llx_product_price cpr ON cpr.fk_product = pac.fk_product_child WHERE pac.fk_product_parent = sync.fk_product AND cpr.date_price >= DATE_SUB(NOW(), INTERVAL 30 DAY)) THEN NULL ELSE sync.tms END, sync.tms = sync.tms WHERE sync.last_content_sync IS NULL AND (sync.last_sync_status = ''success'' OR (sync.last_sync_status = ''skipped'' AND sync.shopifyProductId NOT IN ('''', ''0'') AND LEFT(sync.shopifyProductId, 10) <> ''TEMP_LOCK_''))',
                   'SELECT "Backfill last_content_sync skipped: core tables llx_product_price / llx_product_attribute_combination missing"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
