-- ============================================================================
-- Migration Doli2Shop : 2.6.0f -> 2.6.1
--
-- Date        : 2026-10-05
-- Version     : 2.6.1
-- Description : UNIQUE fichier de migration du cycle 2.6.1. Chaque story du cycle y AJOUTE un bloc
--               independant et idempotent (rejouer le fichier complet ne doit jamais changer le
--               resultat d'une seconde execution).
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE : jamais une "partie 2" d'un fichier de migration deja livre
-- (isMigrationApplied() compare la chaine de version).
-- ============================================================================

-- ============================================================================
-- Story 66-1 — remise en file des pushs stock refuses par Shopify (changeFromQuantity)
--
-- Jusqu'a 2.6.0, le push stock du trigger envoyait un changeFromQuantity = 0 INVENTE : Shopify le
-- refusait des que le stock en ligne n'etait pas nul. L'echec etant deterministe (aucune valeur ne
-- change entre deux tentatives), ces produits ont epuise leurs essais en une vingtaine de minutes
-- et dorment en dead-letter (status = 2), ou sont en cours d'echec (status = 0 avec tries > 0).
-- Le correctif de code lit desormais la quantite Shopify a la localisation visee : il faut donner a
-- ces produits une nouvelle chance de partir, sans que le marchand ait quoi que ce soit a lancer.
--
-- Cible UNIQUEMENT la signature de l'erreur (last_error contient changeFromQuantity). Les
-- dead-letters d'une autre cause (delai depasse, produit non mappe...) ne sont PAS touchees : elles
-- se relancent depuis l'administration. Le statut 1 (traite) et le statut 3 (en cours, repris par
-- recoverStuckItems) sont ignores. last_error n'est pas vide (coherent avec requeueDeadLetter()).
--
-- tms = tms : sans cette affectation explicite, ON UPDATE CURRENT_TIMESTAMP repositionnerait toutes les
-- lignes remises en file a l'instant present et ferait perdre l'ordre FIFO du drainage (ORDER BY tms).
--
-- Donnees seules, sans DDL, aucune dependance a la boutique par defaut ni a fk_store : pas de
-- filtre entity volontaire (correctif de donnees, toutes entites). Idempotent : une fois les lignes
-- remises a zero, plus aucune ne correspond tant qu'elles ne re-echouent pas avec la meme
-- signature, et un rejeu complet du fichier reste sans effet.
-- ============================================================================

UPDATE llx_doli2shop_stock_queue
   SET status = 0, tries = 0, tms = tms
 WHERE status IN (0, 2)
   AND last_error LIKE '%changeFromQuantity%';


-- ============================================================================
-- Story 66-4 — memoire de l'image de collection deja envoyee a Shopify
--
-- Jusqu'a 2.6.0, uploadCategoryImageToCollection() refaisait stagedUploadsCreate + fileCreate +
-- collectionUpdate a chaque passage (un fichier de plus dans Contenu > Fichiers par collection et par
-- synchronisation). Trois colonnes dediees (PAS last_sync_hash, reecrit a chaque changement de libelle) :
--   image_sha256         empreinte sha256(sha256(contenu de l'image) | libelle de la categorie)
--   image_collection_id  collection Shopify a laquelle cette empreinte a ete appliquee
--   image_synced_at      date du dernier envoi reussi
-- NULL = jamais envoye : un renvoi unique par collection a la premiere synchronisation apres mise a
-- jour, puis plus rien. AUCUN backfill (on ne sait pas si l'image Shopify correspond a Dolibarr).
-- Blocs independants et idempotents (information_schema + PREPARE, jamais IF NOT EXISTS).
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_collections' AND COLUMN_NAME = 'image_sha256');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_collections ADD COLUMN image_sha256 VARCHAR(64) NULL',
                   'SELECT "image_sha256 exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_collections' AND COLUMN_NAME = 'image_collection_id');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_collections ADD COLUMN image_collection_id VARCHAR(255) NULL',
                   'SELECT "image_collection_id exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_collections' AND COLUMN_NAME = 'image_synced_at');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_collections ADD COLUMN image_synced_at DATETIME NULL',
                   'SELECT "image_synced_at exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ============================================================================
-- Story 66-6 — table de liaison client Shopify <-> tiers Dolibarr (llx_doli2shop_customers)
--
-- Jusqu'a 2.6.0, rien ne reliait un client Shopify a son tiers : la recherche se faisait par e-mail
-- seul, donc une commande sans e-mail etait rejetee. Cette table memorise (entite, boutique, id client
-- Shopify) -> tiers, pour retrouver le meme client d'une commande a l'autre sans e-mail.
-- Meme definition que sql/llx_doli2shop_customers.sql. AUCUN backfill (rien dans la base ne porte
-- l'id client Shopify de maniere fiable) : la table se remplit paresseusement, a chaque commande.
-- Chaque element (table, UK, index, FK) est garde par information_schema + PREPARE, jamais
-- IF NOT EXISTS ; la FK n'est posee que si llx_societe est InnoDB et sans ligne orpheline, et son
-- absence ne rend pas la table inutilisable (lecture defensive cote PHP).
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers');
SET @sqlstmt := IF(@exist = 0,
    'CREATE TABLE llx_doli2shop_customers (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INT NOT NULL DEFAULT 1, fk_store INT NOT NULL DEFAULT 0, shopify_customer_id VARCHAR(64) NOT NULL, fk_soc INTEGER NOT NULL, tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'SELECT "Table llx_doli2shop_customers already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers'
               AND INDEX_NAME = 'uk_doli2shop_customers_key');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_customers ADD UNIQUE INDEX uk_doli2shop_customers_key (entity, fk_store, shopify_customer_id)',
                   'SELECT "Index uk_doli2shop_customers_key already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers'
               AND INDEX_NAME = 'idx_doli2shop_customers_fk_soc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_customers ADD INDEX idx_doli2shop_customers_fk_soc (fk_soc)',
                   'SELECT "Index idx_doli2shop_customers_fk_soc already exists"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_customers'
               AND CONSTRAINT_NAME = 'fk_doli2shop_customers_soc');
SET @societe_innodb := (SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'llx_societe'
                        AND ENGINE = 'InnoDB');
SET @orphans := 0;
SET @sqlstmt := IF(@exist = 0 AND @societe_innodb = 1,
                   'SELECT COUNT(*) INTO @orphans FROM llx_doli2shop_customers c LEFT JOIN llx_societe s ON s.rowid = c.fk_soc WHERE s.rowid IS NULL',
                   'SELECT 0');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sqlstmt := IF(@exist = 0 AND @societe_innodb = 1 AND @orphans = 0,
                   'ALTER TABLE llx_doli2shop_customers ADD CONSTRAINT fk_doli2shop_customers_soc FOREIGN KEY (fk_soc) REFERENCES llx_societe (rowid) ON DELETE CASCADE',
                   'SELECT "Foreign key fk_doli2shop_customers_soc already exists or not applicable"');
PREPARE stmt FROM @sqlstmt; EXECUTE stmt; DEALLOCATE PREPARE stmt;
