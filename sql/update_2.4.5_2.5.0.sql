-- =====================================================
-- MIGRATION v2.4.5 -> v2.5.0 (DOLI2SHOP)
-- Date: 2026-08-09
-- Version: 2.5.0
-- Description: Ajout colonne offsale_action_status sur llx_doli2shop_products — Story 58-5
--              (option "ne pousser vers Shopify que les produits En Vente" + outil de
--              traitement de l'existant OffSaleProductsService). Marqueur de traçabilité
--              ('left_draft' / 'archived') qui empêche le cycle de resynchronisation normal
--              de désarchiver silencieusement un produit archivé explicitement par l'outil
--              tant qu'il reste hors vente (AC5) — sans cette colonne, le prochain cycle de
--              contenu (24h ou clic "Synchroniser") écraserait ARCHIVED par DRAFT.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_products — ADD COLUMN offsale_action_status
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'offsale_action_status');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_products ADD COLUMN offsale_action_status varchar(20) DEFAULT NULL COMMENT ''left_draft / archived (OffSaleProductsService, Story 58-5), NULL = non traité''',
    'SELECT "Column offsale_action_status already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
