-- ============================================================================
-- Migration Doli2Shop : 2.6.0 -> 2.6.0b
--
-- Date        : 2026-09-23 (dedoublonnage revise le 2026-09-26, review 3 couches, finding 3 ;
--               re-revise le 2026-09-26, re-review, findings 1 et 2)
-- Version     : 2.6.0
-- Description : Empeche deux boutiques is_default=1 pour la meme entity (story
--               doublon-is-default-boutiques-non-empeche). Correctif principal = contrainte DB
--               (colonne generee + index unique), pas seulement un garde applicatif : un
--               SELECT ... FOR UPDATE ne peut pas verrouiller une ligne qui n'existe pas encore,
--               exactement le cas des deux races TOCTOU reelles identifiees par le Validate
--               (StoreService::ensureDefaultStore(), admin/oauth_receive.php). PARTIE 1 (finding 3
--               review 3 couches 2026-09-26) : le dedoublonnage prefere desormais la ligne dont
--               shop_domain correspond a la constante DOLI2SHOP_STORE_HOSTNAME de l'entity (llx_const),
--               MIN(rowid) restant le repli si aucune ne correspond. PARTIE 1 (re-review 2026-09-26,
--               findings 1 et 2) : la preference hostname est desormais restreinte aux boutiques
--               ACTIVES (finding 1 — une boutique inactive ne doit jamais redevenir par defaut en
--               demotant une active) et priorise explicitement la constante entity-specifique sur
--               la constante globale entity=0 (finding 2 — au lieu d'un simple `IN (st.entity, 0)`
--               sans ordre) ; le repli MIN(rowid) prefere lui aussi une boutique active parmi les
--               doublons quand il en existe une, avant de retomber sur le MIN(rowid) absolu.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que les migrations precedentes) : jamais une
-- "partie 2" d'un fichier de migration deja livre. 2.5.5_2.6.0 (journal de run) est deja cablee
-- et livree ; celle-ci cible donc 2.6.0_2.6.0b (meme convention de suffixe que 2.5.3_2.5.3b :
-- plusieurs stories dans le meme cycle de version pas encore publie).
--
-- Ordre a l'interieur de ce fichier : dedoublonnage AVANT ajout de la colonne generee, colonne
-- generee AVANT l'index unique qui s'appuie dessus. Aucun ordre inverse n'est idempotent-safe :
-- ajouter l'index avant dedoublonnage echouerait (doublons existants violent l'unicite).
-- ============================================================================

-- ============================================================================
-- PARTIE 1 : Dedoublonnage deterministe des lignes is_default=1 existantes, AVANT toute
-- contrainte.
--
-- Regle de departage (finding 3, review 3 couches 2026-09-26 ; restreinte finding 1 et
-- reordonnee finding 2, re-review 2026-09-26) : parmi les doublons d'une entity, on prefere,
-- DANS CET ORDRE :
--   1. La ligne ACTIVE dont shop_domain correspond a la constante DOLI2SHOP_STORE_HOSTNAME
--      *entity-specifique* (llx_const, c.entity = st.entity) — la constante propre a l'entity
--      doit toujours l'emporter sur une constante globale (finding 2).
--   2. A defaut, la ligne ACTIVE dont shop_domain correspond a la constante GLOBALE
--      (c.entity = 0) — dolibarr_set_const() ecrit toujours avec l'entity EXPLICITE de
--      l'appelant, jamais 0/global (cf. admin/oauth_receive.php), mais ce repli couvre une
--      constante posee manuellement en global.
--   3. A defaut de toute correspondance active, la ligne ACTIVE la plus ancienne (MIN(rowid))
--      parmi les doublons — une boutique INACTIVE ne doit jamais redevenir par defaut en
--      demotant une boutique active, meme si une constante perimee la designe encore
--      (finding 1 : le filtre `active = 1` s'applique aux etapes 1 et 2 ci-dessus, jamais une
--      ligne inactive n'est choisie tant qu'une ligne active existe parmi les doublons).
--   4. En tout dernier repli (cas degenere : TOUTES les lignes en doublon sont inactives),
--      MIN(rowid) absolu — exactement la meme regle de desambiguisation que
--      StoreService::getDefault() (ORDER BY rowid ASC) : aucun changement de comportement
--      observable pour les lecteurs actuels dans ce cas limite.
--
-- Pure DML idempotente : PAS de garde information_schema necessaire (reserve aux DDL,
-- CLAUDE.dolibarr.md §4) — rejouer ces UPDATE sur un etat deja deduplique ne trouve plus aucune
-- ligne a corriger (WHERE s.rowid <> keep.keep_rowid devient vide).
--
-- Jointures sur des tables derivees (sous-requetes aliasees dans le FROM) plutot que des
-- sous-requetes correlees directes sur la meme table : evite l'erreur MySQL/MariaDB 1093 ("You
-- can't specify target table for update in FROM clause"), pattern standard cross-SGBD.
-- ============================================================================
UPDATE llx_doli2shop_stores s
INNER JOIN (
    SELECT dup.entity,
           COALESCE(pref_entity.keep_rowid, pref_global.keep_rowid, active_fallback.keep_rowid, dup.min_rowid) AS keep_rowid
    FROM (
        SELECT entity, MIN(rowid) AS min_rowid
        FROM llx_doli2shop_stores
        WHERE is_default = 1
        GROUP BY entity
    ) dup
    LEFT JOIN (
        -- Priorite 1 : constante ENTITY-SPECIFIQUE (c.entity = st.entity), boutique ACTIVE
        -- uniquement. MIN(st.rowid) ne sert ici que de garde deterministe si, cas degenere,
        -- plusieurs lignes matchaient encore (jamais observe en pratique — une seule ligne porte
        -- normalement chaque shop_domain par entity, cf. uk_doli2shop_stores_domain).
        SELECT st.entity, MIN(st.rowid) AS keep_rowid
        FROM llx_doli2shop_stores st
        INNER JOIN llx_const c
                ON c.name = 'DOLI2SHOP_STORE_HOSTNAME'
               AND c.entity = st.entity
               AND c.value = st.shop_domain
        WHERE st.is_default = 1
          AND st.active = 1
        GROUP BY st.entity
    ) pref_entity ON pref_entity.entity = dup.entity
    LEFT JOIN (
        -- Priorite 2 : constante GLOBALE (c.entity = 0), boutique ACTIVE. N'est retenue par le
        -- COALESCE que si pref_entity n'a rien trouve pour cette entity : une jointure separee
        -- (au lieu de l'ancien `c.entity IN (st.entity, 0)` sans ordre) garantit que la
        -- constante entity-specifique l'emporte toujours quand les deux existent.
        SELECT st.entity, MIN(st.rowid) AS keep_rowid
        FROM llx_doli2shop_stores st
        INNER JOIN llx_const c
                ON c.name = 'DOLI2SHOP_STORE_HOSTNAME'
               AND c.entity = 0
               AND c.value = st.shop_domain
        WHERE st.is_default = 1
          AND st.active = 1
        GROUP BY st.entity
    ) pref_global ON pref_global.entity = dup.entity
    LEFT JOIN (
        -- Priorite 3 : aucune constante (entity-specifique ou globale) ne designe une boutique
        -- ACTIVE parmi les doublons — repli sur la boutique ACTIVE la plus ancienne (MIN(rowid)
        -- restreint a active = 1), jamais sur une boutique inactive tant qu'une active existe.
        SELECT entity, MIN(rowid) AS keep_rowid
        FROM llx_doli2shop_stores
        WHERE is_default = 1
          AND active = 1
        GROUP BY entity
    ) active_fallback ON active_fallback.entity = dup.entity
) keep ON keep.entity = s.entity
SET s.is_default = 0
WHERE s.is_default = 1
  AND s.rowid <> keep.keep_rowid;

-- ============================================================================
-- PARTIE 2 : Colonne generee default_key = IF(is_default = 1, entity, NULL).
--
-- NULL pour toute ligne is_default=0 : un index unique MySQL/MariaDB autorise plusieurs lignes
-- a NULL, donc seules les boutiques par defaut (is_default=1) participent a la contrainte
-- d'unicite de la PARTIE 3 — une par entity, jamais deux entites differentes entre elles.
--
-- Supporte nativement sur la cible officielle du depot (MySQL 5.7+ / MariaDB 10.3+,
-- CLAUDE.dolibarr.md §4) : index sur colonne generee virtuelle disponible depuis MySQL 5.7.8 et
-- MariaDB 10.2.
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND COLUMN_NAME = 'default_key');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD COLUMN default_key int(11) GENERATED ALWAYS AS (IF(is_default = 1, entity, NULL)) VIRTUAL',
    'SELECT "Colonne default_key already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PARTIE 3 : Index unique sur default_key — le correctif principal (AC1). Empeche desormais,
-- au niveau base, deux lignes is_default=1 pour la MEME entity (violation immediate de
-- l'INSERT/UPDATE concurrent, cf. StoreService::create()/update() qui gerent ce cas).
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stores'
               AND INDEX_NAME = 'uk_doli2shop_stores_default_key');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stores ADD UNIQUE INDEX uk_doli2shop_stores_default_key (default_key)',
    'SELECT "Index uk_doli2shop_stores_default_key already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
