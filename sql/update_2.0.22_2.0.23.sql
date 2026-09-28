-- Date: 2025-05-31
-- Version: 2.0.23
-- Description: Add fk_product_parent column for better parent-variant relationships
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.0.22 to 2.0.23

-- ============================================================================
-- FIX v2.5.0 (story 62-1) : GARDE D'EXISTENCE DE TABLE
--
-- llx_dolibarr_shopify_products_save a été renommée depuis (cf. update_2.1.5_2.1.6.sql,
-- RENAME TABLE ... TO llx_doli2shop_products) : elle ne figure dans AUCUN fichier
-- sql/llx_*.sql du schéma actuel. Ce script EST câblé dans modDoli2Shop::init() : il
-- s'exécute à CHAQUE activation du module, y compris une installation neuve qui n'a
-- jamais connu cette table.
--
-- Les gardes ci-dessous vérifiaient seulement "la colonne/l'index/la contrainte
-- n'existe pas encore" (@exist = 0) pour décider d'ALTER — or sur une table ABSENTE,
-- la colonne est ELLE AUSSI absente (@exist = 0 tout autant), ce qui déclenchait quand
-- même l'ALTER contre une table inexistante. Contrairement au renommage de colonne de
-- update_2.0.5_2.0.6.sql (double condition ancienne/nouvelle colonne, qui degrade
-- naturellement en no-op), un simple "@exist = 0" ne distingue pas "il faut l'ajouter"
-- de "il n'y a rien à quoi l'ajouter" : c'est le même défaut que celui déjà corrigé,
-- sous une forme différente. Les deux UPDATE (aucune garde du tout) et le bloc de
-- réorganisation (@do_reorganization dérivé uniquement de l'absence d'un index) étaient
-- logés dans le même travers.
--
-- ⚠️ Ne jamais retirer le test @table_exists ajouté ci-dessous ni les "AND @table_exists > 0".
-- ============================================================================
SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'llx_dolibarr_shopify_products_save');

-- Add fk_product_parent column to llx_dolibarr_shopify_products_save
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
               AND COLUMN_NAME = 'fk_product_parent');

SET @sqlstmt := IF(@table_exists > 0 AND @exist = 0,
                   'ALTER TABLE llx_dolibarr_shopify_products_save ADD COLUMN fk_product_parent INT(11) DEFAULT NULL COMMENT "ID of parent product for variants, NULL for simple products and parents"',
                   'SELECT "Table absent or column fk_product_parent already exists"');

PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for performance
SET @index_exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                     AND INDEX_NAME = 'idx_fk_product_parent');

SET @index_sqlstmt := IF(@table_exists > 0 AND @index_exist = 0,
                         'ALTER TABLE llx_dolibarr_shopify_products_save ADD INDEX idx_fk_product_parent (fk_product_parent)',
                         'SELECT "Table absent or index idx_fk_product_parent already exists"');

PREPARE index_stmt FROM @index_sqlstmt;
EXECUTE index_stmt;
DEALLOCATE PREPARE index_stmt;

-- Populate existing data: identify variants by finding products that share the same shopifyProductId
-- but have different fk_product values (indicating they are variants of the same Shopify product)
SET @populate_parents := IF(@table_exists > 0,
    'UPDATE llx_dolibarr_shopify_products_save AS s1
    JOIN (
        SELECT shopifyProductId, MIN(fk_product) as parent_id
        FROM llx_dolibarr_shopify_products_save
        WHERE shopifyProductId != ''''
        AND shopifyVariantId != ''''
        GROUP BY shopifyProductId
        HAVING COUNT(DISTINCT fk_product) > 1
    ) AS parents ON s1.shopifyProductId = parents.shopifyProductId
    SET s1.fk_product_parent = CASE
        WHEN s1.fk_product = parents.parent_id THEN NULL
        ELSE parents.parent_id
    END
    WHERE s1.shopifyProductId != ''''',
    'SELECT "Table llx_dolibarr_shopify_products_save does not exist - SKIP populate parents"');

PREPARE stmt_populate FROM @populate_parents;
EXECUTE stmt_populate;
DEALLOCATE PREPARE stmt_populate;

-- For entries with empty shopifyVariantId, these are parent product entries
SET @reset_simple_products := IF(@table_exists > 0,
    'UPDATE llx_dolibarr_shopify_products_save
    SET fk_product_parent = NULL
    WHERE shopifyVariantId = '''' OR shopifyVariantId IS NULL',
    'SELECT "Table llx_dolibarr_shopify_products_save does not exist - SKIP reset simple products"');

PREPARE stmt_reset FROM @reset_simple_products;
EXECUTE stmt_reset;
DEALLOCATE PREPARE stmt_reset;

-- Add foreign key constraint for fk_product_parent
SET @constraint_exist := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                          WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                          AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_products_parent');

SET @constraint_sqlstmt := IF(@table_exists > 0 AND @constraint_exist = 0,
                              'ALTER TABLE llx_dolibarr_shopify_products_save ADD CONSTRAINT fk_dolibarr_shopify_products_parent FOREIGN KEY (fk_product_parent) REFERENCES llx_product (rowid) ON DELETE CASCADE',
                              'SELECT "Table absent or constraint fk_dolibarr_shopify_products_parent already exists"');

PREPARE constraint_stmt FROM @constraint_sqlstmt;
EXECUTE constraint_stmt;
DEALLOCATE PREPARE constraint_stmt;

-- FIX #144 v2.1.2: Vérifier si la réorganisation a déjà été effectuée
-- On vérifie la présence de l'index unique qui est créé à la fin de la réorganisation
SET @reorganization_done := (SELECT COUNT(*) FROM information_schema.STATISTICS
                              WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                              AND INDEX_NAME = 'uk_dolibarr_shopify_products_unique');

-- Si la réorganisation n'a pas été faite (@reorganization_done = 0), la faire maintenant
-- Sinon, sauter toute cette section — et jamais si la table source n'existe pas (@table_exists,
-- FIX v2.5.0 story 62-1 : sans cette condition, @reorganization_done vaut aussi 0 sur une table
-- absente, ce qui déclenchait CREATE/INSERT/DROP/RENAME contre une table inexistante).
SET @do_reorganization := IF(@table_exists > 0 AND @reorganization_done = 0, 1, 0);

-- Log informatif
SELECT IF(@do_reorganization = 1,
          'Reorganization needed - proceeding with table restructuring',
          'Reorganization already done - SKIPPING') AS reorganization_status;

-- Exécuter la réorganisation SEULEMENT si nécessaire
-- Note: MySQL ne permet pas de conditionner CREATE TABLE avec SET @sqlstmt
-- Solution: Vérifier manuellement avant d'exécuter

-- ✅ SECTION PROTÉGÉE - Ne s'exécute QUE si @do_reorganization = 1
-- Pour vérifier: SELECT @do_reorganization;

-- FIX #144 v2.1.2: Création conditionnelle table temporaire
SET @create_temp_table := IF(@do_reorganization = 1,
    'CREATE TABLE IF NOT EXISTS llx_dolibarr_shopify_products_save_new (
      id                  integer AUTO_INCREMENT PRIMARY KEY,
      entity              int(11) NOT NULL DEFAULT 1,
      fk_product          int(11) NOT NULL,
      fk_product_parent   int(11) DEFAULT NULL COMMENT "ID of parent product for variants, NULL for simple products and parents",
      shopifyProductId    varchar(255) NOT NULL,
      shopifyVariantId    varchar(255) DEFAULT NULL,
      sync_lock           datetime DEFAULT NULL COMMENT "Timestamp of sync lock to prevent concurrent synchronizations",
      last_sync_status    enum("success","failed","pending","skipped") DEFAULT "pending",
      last_sync_error     text DEFAULT NULL,
      tms                 datetime NOT NULL
    ) ENGINE=innodb',
    'SELECT "Table reorganization not needed - SKIP CREATE"');

PREPARE stmt_create FROM @create_temp_table;
EXECUTE stmt_create;
DEALLOCATE PREPARE stmt_create;

-- FIX #144 v2.1.2: Copie conditionnelle des données
SET @copy_data := IF(@do_reorganization = 1,
    'INSERT INTO llx_dolibarr_shopify_products_save_new
    (id, entity, fk_product, fk_product_parent, shopifyProductId, shopifyVariantId, sync_lock, last_sync_status, last_sync_error, tms)
    SELECT
      id, entity, fk_product, fk_product_parent, shopifyProductId, shopifyVariantId, sync_lock, last_sync_status, last_sync_error, tms
    FROM llx_dolibarr_shopify_products_save',
    'SELECT "Table reorganization not needed - SKIP COPY"');

PREPARE stmt_copy FROM @copy_data;
EXECUTE stmt_copy;
DEALLOCATE PREPARE stmt_copy;

-- FIX #144 v2.1.2: DROP conditionnelle ancienne table
SET @drop_old := IF(@do_reorganization = 1,
    'DROP TABLE IF EXISTS llx_dolibarr_shopify_products_save',
    'SELECT "Table reorganization not needed - SKIP DROP"');

PREPARE stmt_drop FROM @drop_old;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

-- FIX #144 v2.1.2: RENAME conditionnelle nouvelle table
SET @rename_new := IF(@do_reorganization = 1,
    'RENAME TABLE llx_dolibarr_shopify_products_save_new TO llx_dolibarr_shopify_products_save',
    'SELECT "Table reorganization not needed - SKIP RENAME"');

PREPARE stmt_rename FROM @rename_new;
EXECUTE stmt_rename;
DEALLOCATE PREPARE stmt_rename;

-- FIX #144 v2.1.2: Nettoyage conditionnel des doublons (SEULEMENT si réorganisation faite)
-- Note: Cette requête DELETE ne peut être préparée dynamiquement
-- On vérifie à nouveau l'état après réorganisation
SET @need_cleanup := (SELECT COUNT(*) FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                      AND INDEX_NAME = 'uk_dolibarr_shopify_products_unique');

-- Si l'index unique n'existe PAS ENCORE (= 0), on doit faire le cleanup avant de le créer
-- Sinon, le cleanup a déjà été fait. FIX v2.5.0 (story 62-1) : @table_exists ajouté, sinon
-- ce DELETE (jamais préparé sous condition avant ce fix) partait contre une table absente.
SET @cleanup_query := IF(@table_exists > 0 AND @need_cleanup = 0,
    'DELETE t1 FROM llx_dolibarr_shopify_products_save t1
    INNER JOIN llx_dolibarr_shopify_products_save t2
    WHERE t1.fk_product = t2.fk_product
      AND t1.entity = t2.entity
      AND t1.fk_product_parent IS NULL
      AND t2.fk_product_parent IS NULL
      AND t1.id < t2.id',
    'SELECT "Table absent or duplicates already cleaned - SKIP DELETE"');

PREPARE stmt_cleanup FROM @cleanup_query;
EXECUTE stmt_cleanup;
DEALLOCATE PREPARE stmt_cleanup;

-- FIX #144 v2.1.2: Ajout conditionnel index unique (protection anti-doublons)
SET @exist_uk_unique := (SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                         AND INDEX_NAME = 'uk_dolibarr_shopify_products_unique');

SET @add_uk_unique := IF(@table_exists > 0 AND @exist_uk_unique = 0,
    'ALTER TABLE llx_dolibarr_shopify_products_save ADD UNIQUE INDEX uk_dolibarr_shopify_products_unique (fk_product, entity, fk_product_parent)',
    'SELECT "Table absent or unique index already exists"');

PREPARE stmt_uk FROM @add_uk_unique;
EXECUTE stmt_uk;
DEALLOCATE PREPARE stmt_uk;

-- FIX #144 v2.1.2: Ajout conditionnel index entity
SET @exist_idx_entity := (SELECT COUNT(*) FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                          AND INDEX_NAME = 'idx_dolibarr_shopify_products_save_entity');

SET @add_idx_entity := IF(@table_exists > 0 AND @exist_idx_entity = 0,
    'ALTER TABLE llx_dolibarr_shopify_products_save ADD INDEX idx_dolibarr_shopify_products_save_entity (entity)',
    'SELECT "Table absent or entity index already exists"');

PREPARE stmt_idx_entity FROM @add_idx_entity;
EXECUTE stmt_idx_entity;
DEALLOCATE PREPARE stmt_idx_entity;

-- FIX #144 v2.1.2: Vérifier si l'index fk_product_parent existe déjà (peut avoir été créé plus haut)
SET @exist_idx_parent_final := (SELECT COUNT(*) FROM information_schema.STATISTICS
                                 WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                                 AND INDEX_NAME = 'idx_fk_product_parent');

SET @add_idx_parent_final := IF(@table_exists > 0 AND @exist_idx_parent_final = 0,
    'ALTER TABLE llx_dolibarr_shopify_products_save ADD INDEX idx_fk_product_parent (fk_product_parent)',
    'SELECT "Table absent or index idx_fk_product_parent already exists (final check)"');

PREPARE stmt_idx_parent_final FROM @add_idx_parent_final;
EXECUTE stmt_idx_parent_final;
DEALLOCATE PREPARE stmt_idx_parent_final;

-- FIX #144 v2.1.2: Ajout conditionnel contrainte FK fk_product
SET @exist_fk_product_final := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                                WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                                AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_products_product');

SET @add_fk_product := IF(@table_exists > 0 AND @exist_fk_product_final = 0,
    'ALTER TABLE llx_dolibarr_shopify_products_save ADD CONSTRAINT fk_dolibarr_shopify_products_product FOREIGN KEY (fk_product) REFERENCES llx_product (rowid) ON DELETE CASCADE',
    'SELECT "Table absent or FK constraint fk_product already exists"');

PREPARE stmt_fk_product FROM @add_fk_product;
EXECUTE stmt_fk_product;
DEALLOCATE PREPARE stmt_fk_product;

-- FIX #144 v2.1.2: Ajout conditionnel contrainte FK fk_product_parent
SET @exist_fk_parent_final := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                               WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                               AND CONSTRAINT_NAME = 'fk_dolibarr_shopify_products_parent');

SET @add_fk_parent := IF(@table_exists > 0 AND @exist_fk_parent_final = 0,
    'ALTER TABLE llx_dolibarr_shopify_products_save ADD CONSTRAINT fk_dolibarr_shopify_products_parent FOREIGN KEY (fk_product_parent) REFERENCES llx_product (rowid) ON DELETE CASCADE',
    'SELECT "Table absent or FK constraint fk_product_parent already exists"');

PREPARE stmt_fk_parent FROM @add_fk_parent;
EXECUTE stmt_fk_parent;
DEALLOCATE PREPARE stmt_fk_parent;

-- FIX #144 v2.1.2: Modification conditionnelle ENUM last_sync_status
-- Vérifier si la colonne a déjà le type correct avec 'skipped'
SET @enum_has_skipped := (SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'llx_dolibarr_shopify_products_save'
                          AND COLUMN_NAME = 'last_sync_status'
                          AND COLUMN_TYPE LIKE '%skipped%');

SET @modify_enum := IF(@table_exists > 0 AND @enum_has_skipped = 0,
    'ALTER TABLE llx_dolibarr_shopify_products_save MODIFY COLUMN last_sync_status enum("success","failed","pending","skipped") DEFAULT "pending"',
    'SELECT "Table absent or ENUM already contains skipped status"');

PREPARE stmt_enum FROM @modify_enum;
EXECUTE stmt_enum;
DEALLOCATE PREPARE stmt_enum;