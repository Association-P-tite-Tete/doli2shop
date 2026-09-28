-- =====================================================
-- MIGRATION v2.4.1 -> v2.4.4 (DOLI2SHOP)
-- Date: 2026-07-26
-- Version: 2.4.4
-- Description: Ajout colonne variant_repair_status sur llx_doli2shop_products —
--              marqueur de convergence pour VariantRepairService (hotfix 2.4.4,
--              2/2 — correctifs post-review 3-couches, réparation batch des imports
--              existants en vraies déclinaisons Dolibarr). Exclut définitivement du
--              pool de réparation les candidats DÉTERMINISTES ET PERMANENTS (mapping
--              invalide, variante Shopify sans option distinctive exploitable, parent
--              Dolibarr supprimé) — sans cette colonne, le compteur de candidats ne
--              convergeait jamais vers 0 (ces candidats revenaient indéfiniment).
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- Utilise information_schema.COLUMNS
-- Pattern obligatoire (règle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================


-- ============================================================================
-- PARTIE 1 : llx_doli2shop_products — ADD COLUMN variant_repair_status
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_products'
               AND COLUMN_NAME = 'variant_repair_status');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_products ADD COLUMN variant_repair_status tinyint(1) NOT NULL DEFAULT 0 COMMENT ''0 = candidat réparation variante, 1 = irréparable permanent (VariantRepairService, hotfix 2.4.4 2/2)''',
    'SELECT "Column variant_repair_status already exists in llx_doli2shop_products"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
