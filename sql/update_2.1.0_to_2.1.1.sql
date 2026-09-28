-- Date: 2025-10-08
-- Version: 2.1.1
-- Description: Réparation erreurs migration v2.1.0 + améliorations stabilité CRON
-- Author: P'tite Tête
-- Copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- Update script for version 2.1.0 to 2.1.1

-- =============================================================================
-- FIX #140 v2.1.1: RÉPARATION ERREURS SQL MIGRATION V2.1.0
-- =============================================================================

-- -----------------------------------------------------------------------------
-- FIX #1 : Suppression table obsolète llx_shopify_dolibarr_storedetails
-- Cette table devrait avoir été supprimée lors de la migration v2.1.0
-- mais peut encore exister sur certaines installations
-- -----------------------------------------------------------------------------
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_shopify_dolibarr_storedetails');

SET @sqlstmt := IF(@exist > 0,
                   'DROP TABLE llx_shopify_dolibarr_storedetails',
                   'SELECT "Table llx_shopify_dolibarr_storedetails already dropped"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- FIX #2 : Nettoyage des index dupliqués sur llx_dolibarr_shopify_orders_save
-- Certains index peuvent avoir été créés en double lors de migrations
-- -----------------------------------------------------------------------------

-- Vérifier et supprimer index dupliqué sur shopifyOrderId
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
               AND INDEX_NAME = 'idx_shopify_order_id_duplicate');

SET @sqlstmt := IF(@exist > 0,
                   'ALTER TABLE llx_dolibarr_shopify_orders_save DROP INDEX idx_shopify_order_id_duplicate',
                   'SELECT "No duplicate index to drop on shopifyOrderId"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Vérifier et supprimer index dupliqué sur fk_commande
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
               AND INDEX_NAME = 'idx_fk_commande_duplicate');

SET @sqlstmt := IF(@exist > 0,
                   'ALTER TABLE llx_dolibarr_shopify_orders_save DROP INDEX idx_fk_commande_duplicate',
                   'SELECT "No duplicate index to drop on fk_commande"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- FIX #140 v2.1.1: NOUVELLES COLONNES POUR DÉTECTION BLOCAGE IMPORT HISTORIQUE
-- =============================================================================

-- Note: Les constantes Dolibarr suivantes sont utilisées pour la détection de blocage :
-- - SHOPIFYINTEGRATION_HISTORICAL_IMPORT_LAST_PROCESSED (via dolibarr_set_const)
-- - SHOPIFYINTEGRATION_HISTORICAL_IMPORT_STUCK_COUNTER (via dolibarr_set_const)
-- Ces constantes sont gérées dynamiquement par le code PHP et n'ont pas besoin de table SQL

-- =============================================================================
-- VÉRIFICATION INTÉGRITÉ DES TABLES PRINCIPALES
-- =============================================================================

-- Vérifier que la table principale de sync produits existe
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_shopify_product_sync');

SET @sqlstmt := IF(@exist = 0,
                   'SELECT "WARNING: Table llx_shopify_product_sync does not exist - module installation may be incomplete"',
                   'SELECT "Table llx_shopify_product_sync OK"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Vérifier que la table de sync commandes existe
SET @exist := (SELECT COUNT(*) FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save');

SET @sqlstmt := IF(@exist = 0,
                   'SELECT "WARNING: Table llx_dolibarr_shopify_orders_save does not exist - module installation may be incomplete"',
                   'SELECT "Table llx_dolibarr_shopify_orders_save OK"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VÉRIFICATION COLONNES CRITIQUES
-- =============================================================================

-- Vérifier que les colonnes renommées existent bien (dolOrderId → fk_commande)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
               AND COLUMN_NAME = 'fk_commande');

SET @sqlstmt := IF(@exist = 0,
                   'SELECT "WARNING: Column fk_commande missing in llx_dolibarr_shopify_orders_save - migration incomplete"',
                   'SELECT "Column fk_commande OK"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Vérifier que les anciennes colonnes n'existent plus (dolOrderId)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_dolibarr_shopify_orders_save'
               AND COLUMN_NAME = 'dolOrderId');

SET @sqlstmt := IF(@exist > 0,
                   'SELECT "WARNING: Old column dolOrderId still exists - should have been renamed to fk_commande"',
                   'SELECT "Old column dolOrderId properly removed"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- FIN DU SCRIPT
-- =============================================================================

SELECT "✅ Migration script 2.1.0 → 2.1.1 completed successfully" as Status;
