-- ============================================================================
-- Date: 2025-11-19
-- Version: 2.2.0
-- Description: Table de mapping extensible des champs Dolibarr ↔ Shopify
-- Author: P'tite Tête
-- Copyright: 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- ============================================================================
-- IMPORTANT: Ce script est idempotent et peut être exécuté plusieurs fois
-- IMPORTANT: Mappings basés sur code v2.1.4 (class/importproducts.class.php)
-- ============================================================================

-- ============================================================================
-- CRÉATION DE LA TABLE
-- ============================================================================

CREATE TABLE llx_shopify_field_mapping (
    -- Clé primaire
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,

    -- Multi-entité Dolibarr
    entity INT NOT NULL DEFAULT 1,

    -- Type d'objet (product, variant, order, customer, etc.)
    object_type ENUM('product', 'variant', 'order', 'customer', 'fulfillment') NOT NULL,

    -- Identifiants des champs
    dolibarr_field VARCHAR(100) NOT NULL COMMENT 'Nom du champ Dolibarr (ex: label, description, price)',
    shopify_field VARCHAR(100) NOT NULL COMMENT 'Nom du champ Shopify GraphQL (ex: title, descriptionHtml, price)',

    -- Configuration de synchronisation
    sync_enabled TINYINT NOT NULL DEFAULT 1 COMMENT '0=désactivé, 1=activé',
    sync_direction ENUM('dolibarr_to_shopify', 'shopify_to_dolibarr', 'bidirectional') NOT NULL DEFAULT 'dolibarr_to_shopify',

    -- Métadonnées des champs
    field_type ENUM('string', 'text', 'html', 'int', 'decimal', 'boolean', 'date', 'json', 'object', 'enum') NOT NULL,
    is_required TINYINT NOT NULL DEFAULT 0 COMMENT 'Champ obligatoire pour synchronisation',

    -- Transformation des données
    transformation_class VARCHAR(255) DEFAULT NULL COMMENT 'Classe PHP transformation (ex: PriceFieldTransformer)',
    validation_rules TEXT DEFAULT NULL COMMENT 'Règles de validation JSON',
    depends_on_config VARCHAR(100) DEFAULT NULL COMMENT 'Constante config conditionnelle (ex: sync_product_prices)',

    -- Règles de conflit (mode bidirectionnel - v3.0.0)
    conflict_resolution ENUM('dolibarr_priority', 'shopify_priority', 'newest_wins', 'manual') DEFAULT 'dolibarr_priority',

    -- Ordre et organisation
    field_group VARCHAR(50) DEFAULT NULL COMMENT 'Groupe logique (ex: base, pricing, inventory, media)',
    display_order INT DEFAULT 0 COMMENT 'Ordre affichage interface admin',

    -- Méta-information
    field_label VARCHAR(255) DEFAULT NULL COMMENT 'Libellé interface utilisateur',
    field_description TEXT DEFAULT NULL COMMENT 'Description aide utilisateur',
    code_reference VARCHAR(255) DEFAULT NULL COMMENT 'Référence code source (ex: importproducts.class.php:1746)',

    -- Statut et dates
    active TINYINT NOT NULL DEFAULT 1,
    date_creation DATETIME NOT NULL,
    date_modification DATETIME DEFAULT NULL,

    -- Indexes
    INDEX idx_entity (entity),
    INDEX idx_object_type (object_type),
    INDEX idx_sync_enabled (sync_enabled),
    INDEX idx_field_group (field_group),
    INDEX idx_depends_on_config (depends_on_config),
    UNIQUE KEY uk_field_mapping (entity, object_type, dolibarr_field, shopify_field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONNÉES INITIALES: MAPPING CHAMPS PRODUCT
-- ============================================================================
-- Basé sur analyse code v2.1.4 - class/importproducts.class.php
-- 27 champs réellement synchronisés identifiés
-- ============================================================================

-- Vérifier si données déjà insérées pour éviter duplications
SET @row_count = (SELECT COUNT(*) FROM llx_shopify_field_mapping WHERE object_type = 'product');

-- Insérer seulement si table vide
INSERT INTO llx_shopify_field_mapping
(entity, object_type, dolibarr_field, shopify_field, sync_enabled, sync_direction, field_type, is_required, transformation_class, depends_on_config, field_group, display_order, field_label, field_description, code_reference, active, date_creation)
SELECT * FROM (
    -- ========================================================================
    -- GROUPE: Informations de base
    -- ========================================================================
    -- Status: TOUJOURS synchronisé - Mapping status/tosell → ACTIVE/DRAFT
    SELECT 1 AS entity, 'product' AS object_type, 'status' AS dolibarr_field, 'status' AS shopify_field, 1 AS sync_enabled, 'dolibarr_to_shopify' AS sync_direction, 'enum' AS field_type, 1 AS is_required, 'StatusFieldTransformer' AS transformation_class, NULL AS depends_on_config, 'base' AS field_group, 10 AS display_order,
    'ProductStatus' AS field_label, 'Statut de publication (ACTIVE/DRAFT)' AS field_description, 'importproducts.class.php:1746-1754' AS code_reference, 1 AS active, NOW() AS date_creation

    -- Title: Conditionnel selon sync_product_descriptions
    -- Création: toujours Dolibarr | Mise à jour: conserve Shopify si sync_descriptions=0
    UNION ALL
    SELECT 1, 'product', 'label', 'title', 1, 'dolibarr_to_shopify', 'string', 1, NULL, 'sync_product_descriptions', 'base', 20,
    'ProductTitle', 'Nom du produit (obligatoire à la création)', 'importproducts.class.php:1759,1778,1789-1798', 1, NOW()

    -- Description: Conditionnel selon sync_product_descriptions
    UNION ALL
    SELECT 1, 'product', 'description', 'descriptionHtml', 1, 'dolibarr_to_shopify', 'html', 0, NULL, 'sync_product_descriptions', 'base', 30,
    'ProductDescription', 'Description HTML complète', 'importproducts.class.php:1768,1779', 1, NOW()

    -- ========================================================================
    -- GROUPE: Organisation
    -- ========================================================================
    -- Tags: Toujours synchronisés si fournis
    UNION ALL
    SELECT 1, 'product', 'tags', 'tags', 1, 'dolibarr_to_shopify', 'json', 0, NULL, NULL, 'organization', 100,
    'ProductTags', 'Tags pour recherche et filtres (array)', 'importproducts.class.php:1760,1774', 1, NOW()

    -- Vendor: Conditionnel selon config SHOPIFY_VENDOR
    UNION ALL
    SELECT 1, 'product', 'shopify_vendor', 'vendor', 0, 'dolibarr_to_shopify', 'string', 0, NULL, 'SHOPIFY_VENDOR', 'organization', 110,
    'ProductVendor', 'Fournisseur/marque (depuis config)', 'importproducts.class.php:1763-1765,1781-1783', 1, NOW()

    -- ========================================================================
    -- GROUPE: Collections (v2.0.26)
    -- ========================================================================
    -- Collections: Système bidirectionnel via CollectionsUtils
    UNION ALL
    SELECT 1, 'product', 'categories', 'collections', 1, 'dolibarr_to_shopify', 'json', 0, 'CollectionsFieldTransformer', 'sync_product_collections', 'organization', 120,
    'ProductCollections', 'Collections Shopify (mapping catégories)', 'importproducts.class.php:209-257', 1, NOW()

    -- ========================================================================
    -- GROUPE: Options de produit (Variants)
    -- ========================================================================
    -- ProductOptions: Généré depuis variants Dolibarr
    UNION ALL
    SELECT 1, 'product', 'variants_options', 'productOptions', 1, 'dolibarr_to_shopify', 'json', 0, 'ProductOptionsTransformer', NULL, 'variants', 200,
    'ProductOptions', 'Options produit (Couleur, Taille, etc.)', 'importproducts.class.php:1817-1891', 1, NOW()

) AS tmp
WHERE @row_count = 0;

-- ============================================================================
-- DONNÉES INITIALES: MAPPING CHAMPS PRODUCTVARIANT
-- ============================================================================
-- Basé sur analyse code v2.1.4 - class/importproducts.class.php
-- ============================================================================

SET @row_count_variant = (SELECT COUNT(*) FROM llx_shopify_field_mapping WHERE object_type = 'variant');

INSERT INTO llx_shopify_field_mapping
(entity, object_type, dolibarr_field, shopify_field, sync_enabled, sync_direction, field_type, is_required, transformation_class, depends_on_config, validation_rules, field_group, display_order, field_label, field_description, code_reference, active, date_creation)
SELECT * FROM (
    -- ========================================================================
    -- GROUPE: Identifiants (Variant)
    -- ========================================================================
    -- SKU: TOUJOURS synchronisé - CRITIQUE pour identification
    SELECT 1 AS entity, 'variant' AS object_type, 'ref' AS dolibarr_field, 'sku' AS shopify_field, 1 AS sync_enabled, 'dolibarr_to_shopify' AS sync_direction, 'string' AS field_type, 1 AS is_required, NULL AS transformation_class, NULL AS depends_on_config, NULL AS validation_rules, 'base' AS field_group, 10 AS display_order,
    'VariantSKU' AS field_label, 'Référence unique SKU (OBLIGATOIRE)' AS field_description, 'importproducts.class.php:2012,2187' AS code_reference, 1 AS active, NOW() AS date_creation

    -- Option Values: TOUJOURS synchronisé - Valeurs des options (Couleur=Rouge, etc.)
    UNION ALL
    SELECT 1, 'variant', 'options', 'optionValues', 1, 'dolibarr_to_shopify', 'json', 1, 'OptionValuesTransformer', NULL, NULL, 'base', 20,
    'VariantOptionValues', 'Valeurs options variant (ex: Rouge, M)', 'importproducts.class.php:2014,2189', 1, NOW()

    -- ========================================================================
    -- GROUPE: Tarification (Variant)
    -- ========================================================================
    -- Price: Logique complexe multi-niveaux avec sync_product_prices
    UNION ALL
    SELECT 1, 'variant', 'price', 'price', 1, 'dolibarr_to_shopify', 'decimal', 0, 'PriceFieldTransformer', 'sync_product_prices', '{"min_value":0,"allow_zero_for_services":true}', 'pricing', 100,
    'VariantPrice', 'Prix variant (logique multi-niveaux complexe)', 'importproducts.class.php:2025-2152,2200-2339', 1, NOW()

    -- ========================================================================
    -- GROUPE: Inventaire (Variant)
    -- ========================================================================
    -- Barcode: Conditionnel selon sync_product_attributes
    UNION ALL
    SELECT 1, 'variant', 'barcode', 'barcode', 1, 'dolibarr_to_shopify', 'string', 0, NULL, 'sync_product_attributes', NULL, 'inventory', 200,
    'VariantBarcode', 'Code-barres variant', 'importproducts.class.php:2157,2344', 1, NOW()

    -- InventoryPolicy: CONTINUE (vente sans stock) ou DENY (bloquer)
    UNION ALL
    SELECT 1, 'variant', 'inventory_policy', 'inventoryPolicy', 1, 'dolibarr_to_shopify', 'enum', 0, NULL, 'inventory_policy_continue_selling', NULL, 'inventory', 210,
    'VariantInventoryPolicy', 'Politique inventaire (CONTINUE/DENY)', 'importproducts.class.php:2004,2179', 1, NOW()

    -- RequiresShipping: Services (type=1) ne nécessitent pas expédition
    UNION ALL
    SELECT 1, 'variant', 'type', 'inventoryItem.requiresShipping', 1, 'dolibarr_to_shopify', 'boolean', 0, 'ServiceTypeTransformer', NULL, '{"logic":"!(type==1)"}', 'inventory', 220,
    'VariantRequiresShipping', 'Nécessite expédition (false pour services)', 'importproducts.class.php:2016,2191', 1, NOW()

    -- Tracked: Services (type=1) ne sont pas trackés en inventaire
    UNION ALL
    SELECT 1, 'variant', 'type', 'inventoryItem.tracked', 1, 'dolibarr_to_shopify', 'boolean', 0, 'ServiceTypeTransformer', NULL, '{"logic":"!(type==1)"}', 'inventory', 230,
    'VariantTracked', 'Stock tracké (false pour services)', 'importproducts.class.php:2017,2192', 1, NOW()

    -- Stock: Conditionnel selon sync_product_stocks - Logique virtuel/réel
    UNION ALL
    SELECT 1, 'variant', 'stock', 'inventoryQuantities', 1, 'dolibarr_to_shopify', 'json', 0, 'StockFieldTransformer', 'sync_product_stocks', '{"min_value":0,"exclude_services":true}', 'inventory', 240,
    'VariantStock', 'Quantités inventaire (virtuel/réel)', 'importproducts.class.php:2462-2508,522-530', 1, NOW()

    -- ========================================================================
    -- GROUPE: Attributs physiques (Variant)
    -- ========================================================================
    -- Harmonized System Code: Code douanier (customcode)
    UNION ALL
    SELECT 1, 'variant', 'customcode', 'inventoryItem.harmonizedSystemCode', 1, 'dolibarr_to_shopify', 'string', 0, NULL, 'sync_product_attributes', NULL, 'attributes', 300,
    'VariantHSCode', 'Code système harmonisé (douanes)', 'importproducts.class.php:2447', 1, NOW()

    -- Country Code of Origin: Pays d'origine (conversion ID → Code ISO)
    UNION ALL
    SELECT 1, 'variant', 'fk_country', 'inventoryItem.countryCodeOfOrigin', 1, 'dolibarr_to_shopify', 'string', 0, 'CountryCodeFieldTransformer', 'sync_product_attributes', '{"default":"FR"}', 'attributes', 310,
    'VariantCountryOrigin', 'Pays origine (code ISO)', 'importproducts.class.php:2441,2448,3036-3062', 1, NOW()

    -- Weight: Poids avec conversion unités (objet {value, unit})
    UNION ALL
    SELECT 1, 'variant', 'weight', 'inventoryItem.measurement.weight', 1, 'dolibarr_to_shopify', 'object', 0, 'WeightFieldTransformer', 'sync_product_attributes', '{"units":["KILOGRAMS","GRAMS","POUNDS","OUNCES"]}', 'attributes', 320,
    'VariantWeight', 'Poids variant (avec unité)', 'importproducts.class.php:2444,2449-2454,2995-3028', 1, NOW()

) AS tmp
WHERE @row_count_variant = 0;

-- ============================================================================
-- DONNÉES INITIALES: MAPPING MÉTAFIELDS (METAFIELDS)
-- ============================================================================
-- Basé sur analyse code v2.1.4 - class/importproducts.class.php
-- ============================================================================

SET @row_count_metafields = (SELECT COUNT(*) FROM llx_shopify_field_mapping WHERE object_type = 'product' AND shopify_field LIKE 'metafields.%');

INSERT INTO llx_shopify_field_mapping
(entity, object_type, dolibarr_field, shopify_field, sync_enabled, sync_direction, field_type, is_required, transformation_class, field_group, display_order, field_label, field_description, code_reference, active, date_creation)
SELECT * FROM (
    -- ========================================================================
    -- GROUPE: Métafields produit
    -- ========================================================================
    -- Color Pattern: Métafield automatique pour patterns couleur
    SELECT 1 AS entity, 'product' AS object_type, 'variant_color_options' AS dolibarr_field, 'metafields.shopify.color-pattern' AS shopify_field, 0 AS sync_enabled, 'dolibarr_to_shopify' AS sync_direction, 'json' AS field_type, 0 AS is_required, 'ColorPatternTransformer' AS transformation_class, 'metafields' AS field_group, 400 AS display_order,
    'ColorPatternMetafield' AS field_label, 'Pattern couleur (namespace shopify)' AS field_description, 'importproducts.class.php:3720-3743,3775-3791' AS code_reference, 1 AS active, NOW() AS date_creation

) AS tmp
WHERE @row_count_metafields = 0;

-- ============================================================================
-- DONNÉES INITIALES: MAPPING MÉDIAS (MEDIA)
-- ============================================================================

SET @row_count_media = (SELECT COUNT(*) FROM llx_shopify_field_mapping WHERE object_type = 'product' AND field_group = 'media');

INSERT INTO llx_shopify_field_mapping
(entity, object_type, dolibarr_field, shopify_field, sync_enabled, sync_direction, field_type, is_required, depends_on_config, field_group, display_order, field_label, field_description, code_reference, active, date_creation)
SELECT * FROM (
    -- ========================================================================
    -- GROUPE: Médias
    -- ========================================================================
    -- Images: Conditionnel selon sync_product_images - Protection absolue
    SELECT 1 AS entity, 'product' AS object_type, 'photos' AS dolibarr_field, 'media' AS shopify_field, 1 AS sync_enabled, 'dolibarr_to_shopify' AS sync_direction, 'json' AS field_type, 0 AS is_required, 'sync_product_images' AS depends_on_config, 'media' AS field_group, 500 AS display_order,
    'ProductImages' AS field_label, 'Images produit (protection si sync désactivée)' AS field_description, 'importproducts.class.php:410,918,4378,5063' AS code_reference, 1 AS active, NOW() AS date_creation

) AS tmp
WHERE @row_count_media = 0;

-- ============================================================================
-- VÉRIFICATION ET LOGS
-- ============================================================================

-- Afficher le nombre de mappings créés par type d'objet
SELECT
    object_type,
    COUNT(*) as nb_mappings,
    SUM(CASE WHEN sync_enabled = 1 THEN 1 ELSE 0 END) as nb_enabled,
    SUM(CASE WHEN depends_on_config IS NOT NULL THEN 1 ELSE 0 END) as nb_conditional
FROM llx_shopify_field_mapping
GROUP BY object_type;

-- Afficher les groupes de champs
SELECT
    object_type,
    field_group,
    COUNT(*) as nb_fields
FROM llx_shopify_field_mapping
GROUP BY object_type, field_group
ORDER BY object_type, MIN(display_order);

-- ============================================================================
-- FIN DU SCRIPT
-- ============================================================================
