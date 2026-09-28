-- Date: 2025-11-21
-- Version: 2.1.5
-- Description: Système historique adresses + Points relais + Traçabilité metafields
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.1.4 to 2.1.5

-- =============================================================================
-- PARTIE 1 : EXTRAFIELD POUR HASH ADRESSE (détection doublons contacts)
-- =============================================================================

-- Vérifier si la table llx_socpeople_extrafields existe
SET @exist_table := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = CONCAT((SELECT value FROM information_schema.TABLES t
                             JOIN information_schema.COLUMNS c ON t.TABLE_NAME = c.TABLE_NAME
                             WHERE c.COLUMN_NAME = 'MAIN_DB_PREFIX' LIMIT 1), 'socpeople_extrafields')
);

-- Créer la table si elle n'existe pas
SET @sqlstmt_create_table := IF(
    @exist_table = 0,
    'CREATE TABLE llx_socpeople_extrafields (
        rowid int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tms timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        fk_object int(11) NOT NULL,
        import_key varchar(14) DEFAULT NULL,
        KEY idx_socpeople_extrafields (fk_object)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
    'SELECT "Table llx_socpeople_extrafields already exists"'
);

PREPARE stmt_create FROM @sqlstmt_create_table;
EXECUTE stmt_create;
DEALLOCATE PREPARE stmt_create;

-- Vérifier si la colonne address_hash existe
SET @exist_column_hash := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'llx_socpeople_extrafields'
    AND COLUMN_NAME = 'address_hash'
);

-- Ajouter la colonne address_hash si elle n'existe pas
SET @sqlstmt_add_hash := IF(
    @exist_column_hash = 0,
    'ALTER TABLE llx_socpeople_extrafields ADD COLUMN address_hash VARCHAR(32) DEFAULT NULL',
    'SELECT "Column address_hash already exists"'
);

PREPARE stmt_hash FROM @sqlstmt_add_hash;
EXECUTE stmt_hash;
DEALLOCATE PREPARE stmt_hash;

-- Créer un index sur address_hash pour performances
SET @exist_index_hash := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'llx_socpeople_extrafields'
    AND INDEX_NAME = 'idx_socpeople_extrafields_address_hash'
);

SET @sqlstmt_index_hash := IF(
    @exist_index_hash = 0,
    'CREATE INDEX idx_socpeople_extrafields_address_hash ON llx_socpeople_extrafields (address_hash)',
    'SELECT "Index idx_socpeople_extrafields_address_hash already exists"'
);

PREPARE stmt_index_hash FROM @sqlstmt_index_hash;
EXECUTE stmt_index_hash;
DEALLOCATE PREPARE stmt_index_hash;

-- Enregistrer l'extrafield dans llx_extrafields
INSERT INTO llx_extrafields (name, label, type, elementtype, size, entity, enabled, pos)
SELECT * FROM (
    SELECT
        'address_hash' as name,
        'Hash adresse (détection doublons Shopify)' as label,
        'varchar' as type,
        'socpeople' as elementtype,
        '32' as size,
        '1' as entity,
        '1' as enabled,
        '100' as pos
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields
    WHERE name = 'address_hash'
    AND elementtype = 'socpeople'
)
LIMIT 1;

-- =============================================================================
-- PARTIE 2 : EXTRAFIELD POUR INFORMATIONS POINT RELAIS
-- =============================================================================

-- Vérifier si la table llx_commande_extrafields existe
SET @exist_table_commande := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'llx_commande_extrafields'
);

-- Créer la table si elle n'existe pas
SET @sqlstmt_create_commande := IF(
    @exist_table_commande = 0,
    'CREATE TABLE llx_commande_extrafields (
        rowid int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tms timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        fk_object int(11) NOT NULL,
        import_key varchar(14) DEFAULT NULL,
        KEY idx_commande_extrafields (fk_object)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
    'SELECT "Table llx_commande_extrafields already exists"'
);

PREPARE stmt_create_commande FROM @sqlstmt_create_commande;
EXECUTE stmt_create_commande;
DEALLOCATE PREPARE stmt_create_commande;

-- Vérifier si la colonne pickup_point_info existe
SET @exist_column_pickup := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'llx_commande_extrafields'
    AND COLUMN_NAME = 'pickup_point_info'
);

-- Ajouter la colonne pickup_point_info si elle n'existe pas
SET @sqlstmt_add_pickup := IF(
    @exist_column_pickup = 0,
    'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_point_info TEXT DEFAULT NULL',
    'SELECT "Column pickup_point_info already exists"'
);

PREPARE stmt_pickup FROM @sqlstmt_add_pickup;
EXECUTE stmt_pickup;
DEALLOCATE PREPARE stmt_pickup;

-- Enregistrer l'extrafield dans llx_extrafields
INSERT INTO llx_extrafields (name, label, type, elementtype, size, entity, enabled, pos)
SELECT * FROM (
    SELECT
        'pickup_point_info' as name,
        'Informations point relais (JSON)' as label,
        'text' as type,
        'commande' as elementtype,
        NULL as size,
        '1' as entity,
        '1' as enabled,
        '200' as pos
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields
    WHERE name = 'pickup_point_info'
    AND elementtype = 'commande'
)
LIMIT 1;

-- =============================================================================
-- PARTIE 3 : EXTRAFIELD POUR TRAÇABILITÉ METAFIELDS BRUTS
-- =============================================================================

-- Vérifier si la colonne shopify_metafields_raw existe
SET @exist_column_meta := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'llx_commande_extrafields'
    AND COLUMN_NAME = 'shopify_metafields_raw'
);

-- Ajouter la colonne shopify_metafields_raw si elle n'existe pas
SET @sqlstmt_add_meta := IF(
    @exist_column_meta = 0,
    'ALTER TABLE llx_commande_extrafields ADD COLUMN shopify_metafields_raw TEXT DEFAULT NULL',
    'SELECT "Column shopify_metafields_raw already exists"'
);

PREPARE stmt_meta FROM @sqlstmt_add_meta;
EXECUTE stmt_meta;
DEALLOCATE PREPARE stmt_meta;

-- Enregistrer l'extrafield dans llx_extrafields
INSERT INTO llx_extrafields (name, label, type, elementtype, size, entity, enabled, pos)
SELECT * FROM (
    SELECT
        'shopify_metafields_raw' as name,
        'Metafields Shopify bruts (JSON)' as label,
        'text' as type,
        'commande' as elementtype,
        NULL as size,
        '1' as entity,
        '1' as enabled,
        '300' as pos
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields
    WHERE name = 'shopify_metafields_raw'
    AND elementtype = 'commande'
)
LIMIT 1;

-- =============================================================================
-- PARTIE 4 : CONFIGURATION MODULE
-- =============================================================================

-- Activer la gestion des contacts d'adresses par défaut
INSERT INTO llx_const (name, value, type, note, visible, entity)
SELECT * FROM (
    SELECT
        'SHOPIFYINTEGRATION_ENABLE_ADDRESS_CONTACTS' as name,
        '1' as value,
        'chaine' as type,
        'Activer la création automatique de contacts pour les adresses de livraison' as note,
        '0' as visible,
        '1' as entity
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_const
    WHERE name = 'SHOPIFYINTEGRATION_ENABLE_ADDRESS_CONTACTS'
)
LIMIT 1;

-- Activer le support des points relais par défaut
INSERT INTO llx_const (name, value, type, note, visible, entity)
SELECT * FROM (
    SELECT
        'SHOPIFYINTEGRATION_ENABLE_PICKUP_POINTS' as name,
        '1' as value,
        'chaine' as type,
        'Activer la synchronisation des points relais Shopify' as note,
        '0' as visible,
        '1' as entity
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_const
    WHERE name = 'SHOPIFYINTEGRATION_ENABLE_PICKUP_POINTS'
)
LIMIT 1;

-- Activer la sauvegarde des metafields bruts par défaut
INSERT INTO llx_const (name, value, type, note, visible, entity)
SELECT * FROM (
    SELECT
        'SHOPIFYINTEGRATION_SAVE_RAW_METAFIELDS' as name,
        '1' as value,
        'chaine' as type,
        'Sauvegarder tous les metafields Shopify pour traçabilité' as note,
        '0' as visible,
        '1' as entity
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM llx_const
    WHERE name = 'SHOPIFYINTEGRATION_SAVE_RAW_METAFIELDS'
)
LIMIT 1;

-- =============================================================================
-- SECTION 4 : COLONNES DÉDIÉES PICKUP POINTS (v2.1.5 - Amélioration)
-- Migration JSON monolithique → Colonnes SQL natives pour requêtes performantes
-- =============================================================================

-- 4.1 pickup_provider (Nom du provider : Mondial Relay, Boxtal, Atlas, etc.)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_provider');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_provider VARCHAR(50) DEFAULT NULL COMMENT "Provider point relais (Mondial Relay, Boxtal, Atlas)"',
                   'SELECT "Column pickup_provider already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.2 pickup_point_id (ID unique du point relais)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_point_id');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_point_id VARCHAR(100) DEFAULT NULL COMMENT "ID unique du point relais"',
                   'SELECT "Column pickup_point_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index pour recherches rapides par pickup_point_id
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND INDEX_NAME = 'idx_pickup_point_id');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_pickup_point_id ON llx_commande_extrafields(pickup_point_id)',
                   'SELECT "Index idx_pickup_point_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.3 pickup_point_name (Nom du point relais)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_point_name');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_point_name VARCHAR(255) DEFAULT NULL COMMENT "Nom du point relais"',
                   'SELECT "Column pickup_point_name already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.4 pickup_address_line1 (Adresse ligne 1)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_address_line1');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_address_line1 VARCHAR(255) DEFAULT NULL COMMENT "Adresse point relais ligne 1"',
                   'SELECT "Column pickup_address_line1 already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.5 pickup_address_line2 (Adresse ligne 2)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_address_line2');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_address_line2 VARCHAR(255) DEFAULT NULL COMMENT "Adresse point relais ligne 2"',
                   'SELECT "Column pickup_address_line2 already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.6 pickup_city (Ville)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_city');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_city VARCHAR(100) DEFAULT NULL COMMENT "Ville du point relais"',
                   'SELECT "Column pickup_city already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.7 pickup_zip (Code postal)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_zip');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_zip VARCHAR(20) DEFAULT NULL COMMENT "Code postal du point relais"',
                   'SELECT "Column pickup_zip already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.8 pickup_country (Code pays ISO)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_country');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_country VARCHAR(10) DEFAULT NULL COMMENT "Code pays ISO (FR, BE, etc.)"',
                   'SELECT "Column pickup_country already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.9 pickup_phone (Téléphone)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_phone');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_phone VARCHAR(20) DEFAULT NULL COMMENT "Téléphone du point relais"',
                   'SELECT "Column pickup_phone already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4.10 pickup_extra_data (JSON données provider-spécifiques : horaires, etc.)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_commande_extrafields'
               AND COLUMN_NAME = 'pickup_extra_data');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_commande_extrafields ADD COLUMN pickup_extra_data TEXT DEFAULT NULL COMMENT "JSON données provider-spécifiques (horaires, etc.)"',
                   'SELECT "Column pickup_extra_data already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Enregistrement des nouveaux extrafields dans Dolibarr (llx_extrafields)
INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_provider', 'Provider point relais', 'varchar', 'commande', 1, 10, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_provider' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_point_id', 'ID point relais', 'varchar', 'commande', 1, 11, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_point_id' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_point_name', 'Nom point relais', 'varchar', 'commande', 1, 12, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_point_name' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_address_line1', 'Adresse ligne 1 point relais', 'varchar', 'commande', 1, 13, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_address_line1' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_address_line2', 'Adresse ligne 2 point relais', 'varchar', 'commande', 1, 14, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_address_line2' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_city', 'Ville point relais', 'varchar', 'commande', 1, 15, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_city' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_zip', 'Code postal point relais', 'varchar', 'commande', 1, 16, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_zip' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_country', 'Pays point relais', 'varchar', 'commande', 1, 17, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_country' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_phone', 'Téléphone point relais', 'varchar', 'commande', 1, 18, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_phone' AND elementtype = 'commande'
)
LIMIT 1;

INSERT INTO llx_extrafields (name, label, type, elementtype, entity, pos, enabled, alwayseditable)
SELECT 'pickup_extra_data', 'Données supplémentaires point relais (JSON)', 'text', 'commande', 1, 19, '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM llx_extrafields WHERE name = 'pickup_extra_data' AND elementtype = 'commande'
)
LIMIT 1;

-- =============================================================================
-- FIN DU SCRIPT
-- =============================================================================
