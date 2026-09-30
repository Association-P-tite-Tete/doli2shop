-- ============================================================================
-- Migration Doli2Shop : 2.6.0c -> 2.6.0d
--
-- Date        : 2026-09-27 (dedoublonnage corrige le 27/09/2026, review 3 couches, CRITICAL —
--               fk_store manquant du GROUP BY, perte de mappings multi-boutiques legitimes)
-- Version     : 2.6.0
-- Description : Durcissement en DEFENSE EN PROFONDEUR de la clé unique de
--               llx_doli2shop_products (story
--               cle-unique-doli2shop-products-null-fk-product-parent, AC2). La clé actuelle
--               (fk_product, entity, fk_product_parent, fk_store) traite fk_product_parent NULL
--               comme jamais égal à lui-même : deux lignes strictement identiques pour un produit
--               SIMPLE (sans parent) ne violent pas la contrainte. Le correctif PRINCIPAL de cette
--               story est un verrou applicatif (GET_LOCK, cf.
--               ImportProducts::manageProductMapping()/ShopifyProductImporter::saveProductMapping())
--               qui ferme le TOCTOU réel ; cette migration apporte la PARITÉ de comportement avec
--               les produits AVEC parent (déjà protégés par la clé actuelle) : une collision
--               résiduelle échoue désormais bruyamment (violation de contrainte, absorbée
--               explicitement côté application, cf. AC3) au lieu de dupliquer silencieusement.
--               PARTIE 1 (correctif CRITICAL) : le dédoublonnage ne supprime QUE ce que l'index de
--               la PARTIE 3 interdira réellement — fk_store fait partie du GROUP BY, jamais de
--               suppression d'un mapping multi-boutiques légitime (Epic 47).
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que les migrations precedentes) : jamais une
-- "partie 2" d'un fichier de migration deja livre. sql/update_2.6.0_2.6.0b.sql (story
-- doublon-is-default-boutiques-non-empeche) est deja cable et livre.
--
-- ATTENTION (cablage core/modules/modDoli2Shop.class.php) : la cle de version
-- '2.6.0b_2.6.0c' est RESERVEE par la story parallele
-- stock-article-non-active-emplacement-reselection-perpetuelle (autre branche, non fusionnee a la
-- date d'ecriture de ce fichier). Cette migration-ci se cable donc APRES elle
-- ('2.6.0c_2.6.0d') : un conflit de fusion TRIVIAL est attendu sur le tableau $migrations de
-- modDoli2Shop.class.php le jour ou les deux branches se rejoignent (l'entree 2.6.0b_2.6.0c
-- s'insere avant celle-ci) — resolution attendue = garder les deux entrees, dans cet ordre.
--
-- Ordre a l'interieur de ce fichier : dedoublonnage AVANT ajout de la colonne generee, colonne
-- generee AVANT l'index unique qui s'appuie dessus (meme ordre que update_2.6.0_2.6.0b.sql).
-- Aucun ordre inverse n'est idempotent-safe : ajouter l'index avant dedoublonnage echouerait
-- (doublons existants violent l'unicite).
-- ============================================================================

-- ============================================================================
-- PARTIE 1 : Dedoublonnage deterministe des lignes strictement dupliquees AU REGARD DE LA
-- CONTRAINTE POSEE PAR LA PARTIE 3 CI-DESSOUS, AVANT toute contrainte.
--
-- ⚠️ CRITICAL corrige (review 3 couches, 27/09/2026) : la 1ere version de cette migration
-- groupait par (fk_product, entity, fk_product_parent normalise) SANS fk_store. Deux mappings
-- LEGITIMES d'un meme produit sur DEUX BOUTIQUES REELLES (Epic 47, multi-boutiques — ex.
-- fk_store=5 et fk_store=8) etaient alors vus comme des doublons, et l'un des deux etait SUPPRIME
-- a l'activation du module : une PERTE DE DONNEES reelle, reproduite en base. Le dedoublonnage ne
-- doit supprimer QUE ce que l'index unique de la PARTIE 3 interdira reellement, soit
-- (fk_product, entity, fk_product_parent_key, fk_store) — fk_store fait donc partie du GROUP BY
-- et de la jointure ci-dessous. Un couple fk_store=0 / fk_store>0 pour le meme produit (l'un
-- legacy, l'autre backfille sur une boutique reelle) NE VIOLE PAS cet index et n'est plus touche
-- par cette migration.
--
-- Suivi (hors perimetre de cette story, PAS code ici) : si un nettoyage de ces couples
-- fk_store=0 / fk_store>0 est un jour souhaite (ex. purge du legacy une fois le backfill boutique
-- confirme complet), ce serait un backfill PHP APRES ensureDefaultStore() — invariant
-- CLAUDE.dolibarr.md §14, jamais du SQL de migration qui n'a pas acces a la boutique par defaut au
-- moment ou les migrations s'executent (modDoli2Shop::init()).
--
-- Regle de preference, desormais A L'INTERIEUR D'UN GROUPE A fk_store IDENTIQUE (vrai doublon,
-- meme produit, meme entity, meme parent normalise, MEME boutique) : preferer DANS CET ORDRE :
--   1. La plus recente (MAX(tms)).
--   2. A egalite de tms, celle porteuse d'un shopifyProductId non vide plutot qu'une ligne
--      'pending' incomplete.
--   3. En dernier repli, MAX(id).
--
-- Pure DML idempotente : PAS de garde information_schema necessaire (reservee aux DDL,
-- CLAUDE.dolibarr.md §4) — rejouer ce DELETE sur un etat deja deduplique ne trouve plus aucune
-- ligne a supprimer.
--
-- Jointure sur une table derivee (sous-requete aliasee dans le FROM) plutot qu'une sous-requete
-- correlee directe sur la meme table : evite l'erreur MySQL/MariaDB 1093 ("You can't specify
-- target table for update in FROM clause"), pattern standard cross-SGBD (deja utilise par
-- update_2.6.0_2.6.0b.sql).
--
-- Pas de fonction fenetree (ROW_NUMBER() indisponible sous MySQL 5.7 — cible du depot,
-- CLAUDE.dolibarr.md §11) : les 3 criteres de preference sont encodes en une CLE COMPOSITE
-- triable lexicographiquement (chaine de largeur fixe, caracteres numeriques uniquement, donc
-- l'ordre chaine == l'ordre numerique) :
--   1. UNIX_TIMESTAMP(tms)     -> 20 caracteres, cale a gauche par des zeros (MAX(tms))
--   2. shopifyProductId <> ''  -> 1 caractere ('1' ou '0')
--   3. id                      -> 20 caracteres, cale a gauche par des zeros (MAX(id), et rend la
--                                  cle unique par ligne : jamais d'egalite residuelle)
-- MAX() de cette cle par groupe (fk_product, entity, parent_key, fk_store) designe la ligne a
-- CONSERVER ; toute ligne du groupe dont la cle ne matche pas ce maximum est supprimee.
-- ============================================================================
DELETE p FROM llx_doli2shop_products p
INNER JOIN (
    SELECT fk_product, entity, parent_key, fk_store, MAX(rank_key) AS best_key
    FROM (
        SELECT fk_product, entity, COALESCE(fk_product_parent, 0) AS parent_key, fk_store,
               CONCAT(
                   LPAD(UNIX_TIMESTAMP(tms), 20, '0'),
                   IF(shopifyProductId <> '', '1', '0'),
                   LPAD(id, 20, '0')
               ) AS rank_key
        FROM llx_doli2shop_products
    ) ranked
    GROUP BY fk_product, entity, parent_key, fk_store
    HAVING COUNT(*) > 1
) grp
        ON grp.fk_product = p.fk_product
       AND grp.entity = p.entity
       AND grp.parent_key = COALESCE(p.fk_product_parent, 0)
       AND grp.fk_store = p.fk_store
WHERE CONCAT(
          LPAD(UNIX_TIMESTAMP(p.tms), 20, '0'),
          IF(p.shopifyProductId <> '', '1', '0'),
          LPAD(p.id, 20, '0')
      ) <> grp.best_key;

-- ============================================================================
-- PARTIE 2 : Colonne generee fk_product_parent_key = COALESCE(fk_product_parent, 0).
--
-- JAMAIS ecrire 0 dans fk_product_parent lui-meme : violerait la FK
-- fk_doli2shop_products_parent -> llx_product(rowid) (aucun produit rowid=0). Une colonne
-- generee SEPAREE est la seule option viable, sur le meme modele que default_key de
-- update_2.6.0_2.6.0b.sql (VIRTUAL, indexable depuis MySQL 5.7.8+/MariaDB 10.2+ — dans la plage
-- cible du depot, CLAUDE.dolibarr.md §11).
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'fk_product_parent_key');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_products ADD COLUMN fk_product_parent_key int(11) GENERATED ALWAYS AS (COALESCE(fk_product_parent, 0)) VIRTUAL',
    'SELECT "Colonne fk_product_parent_key already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PARTIE 3 : Index unique sur (fk_product, entity, fk_product_parent_key, fk_store) — le
-- correctif de defense en profondeur (AC2). Empeche desormais, au niveau base, deux lignes
-- strictement identiques pour un produit SANS parent (cas NULL != NULL de la clé actuelle).
-- L'ancien index uk_doli2shop_products_unique reste en place (inoffensif, ne coute rien a
-- conserver).
-- ============================================================================
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
