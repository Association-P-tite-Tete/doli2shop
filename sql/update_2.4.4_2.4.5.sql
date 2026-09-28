-- =====================================================
-- MIGRATION v2.4.4 -> v2.4.5 (DOLI2SHOP)
-- Date: 2026-07-27
-- Version: 2.4.5
-- Description: Creation de la table llx_doli2shop_stock_queue (Story 52-4).
--              File d'attente pour le push stock Dolibarr -> Shopify ASYNCHRONE : le trigger
--              STOCK_MOVEMENT enfile desormais un marqueur (fk_product, entity) au lieu de pousser
--              en synchrone dans la transaction de validation de facture/expedition. Un nouveau
--              CRON dedie (Doli2ShopStockPushQueueProcess, 5 min) draine la file et pousse via
--              ShopifyStockTrigger::updateShopifyStock() (primitive inchangee, relit la valeur
--              courante en base au moment du drainage).
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut etre execute plusieurs fois)
-- Utilise information_schema.TABLES / information_schema.STATISTICS — pattern obligatoire
-- (regle projet absolue — INTERDIT IF NOT EXISTS / IF EXISTS)
-- =====================================================

-- ============================================================================
-- PARTIE 1 : Creation de la table llx_doli2shop_stock_queue (DDL idempotent)
-- ============================================================================

SET @exist_table := (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'llx_doli2shop_stock_queue');
SET @sqlstmt := IF(@exist_table = 0,
    'CREATE TABLE llx_doli2shop_stock_queue (
        rowid           INTEGER      AUTO_INCREMENT PRIMARY KEY,
        fk_product      INT(11)      NOT NULL COMMENT ''Produit Dolibarr dont le stock doit etre pousse vers Shopify'',
        status          TINYINT      NOT NULL DEFAULT 0 COMMENT ''0=pending, 1=done, 2=dead_letter, 3=processing'',
        tries           INT(11)      NOT NULL DEFAULT 0 COMMENT ''Nombre de tentatives de drainage'',
        last_error      TEXT         DEFAULT NULL COMMENT ''Dernier message erreur du worker'',
        date_creation   DATETIME     DEFAULT NULL COMMENT ''Premier enqueue, produit jamais encore en file'',
        date_traitement DATETIME     DEFAULT NULL COMMENT ''Dernier drainage tente, succes ou echec'',
        tms             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT ''Dernier enqueue (coalescence), pilote ordre de drainage FIFO'',
        entity          INT(11)      NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT "Table llx_doli2shop_stock_queue already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PARTIE 2 : Index idempotents sur llx_doli2shop_stock_queue
-- ============================================================================

-- Index sur entity
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stock_queue'
               AND INDEX_NAME = 'idx_doli2shop_stock_queue_entity');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stock_queue ADD INDEX idx_doli2shop_stock_queue_entity (entity)',
    'SELECT "Index idx_doli2shop_stock_queue_entity already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index pour le drainage CRON (pending, ordre FIFO via tms)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stock_queue'
               AND INDEX_NAME = 'idx_doli2shop_stock_queue_processing');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stock_queue ADD INDEX idx_doli2shop_stock_queue_processing (status, tries, entity, tms)',
    'SELECT "Index idx_doli2shop_stock_queue_processing already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Unicite (fk_product, entity) — clef de coalescence (INSERT ... ON DUPLICATE KEY UPDATE)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_stock_queue'
               AND INDEX_NAME = 'uk_doli2shop_stock_queue_product');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_stock_queue ADD UNIQUE INDEX uk_doli2shop_stock_queue_product (fk_product, entity)',
    'SELECT "Index uk_doli2shop_stock_queue_product already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PARTIE 3 : llx_doli2shop_orders — ADD COLUMN discount_repair_status (Hotfix 2.4.5, AC4)
-- ============================================================================
-- Marqueur de convergence pour DiscountRepairService : recorrection batch des remises en
-- pourcentage sous-evaluees a l'import (facteur 1+TVA en trop), pour les commandes importees
-- AVANT le fix v2.2.9. Remontee #1025 Nicolas Graillon (Echafaudages Stephanois). Sans cette
-- colonne, une commande deja traitee (corrigee ou classee hors perimetre) reviendrait
-- indefiniment dans le pool de candidats a chaque passe.

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'discount_repair_status');
SET @sqlstmt := IF(@exist = 0,
    'ALTER TABLE llx_doli2shop_orders ADD COLUMN discount_repair_status varchar(30) NULL DEFAULT NULL COMMENT ''NULL = non scanne, corrected/not_affected/manual_required/manual_review (DiscountRepairService, hotfix 2.4.5 AC4)''',
    'SELECT "Column discount_repair_status already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'idx_doli2shop_orders_discount_repair_status');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD INDEX idx_doli2shop_orders_discount_repair_status (discount_repair_status)',
                   'SELECT "Index idx_doli2shop_orders_discount_repair_status already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Note (Partie 1-2, Story 52-4) : aucun backfill de donnees requis (invariant Epic 47) — la
-- file demarre toujours vide, elle ne depend pas de la boutique par defaut (une ligne de file =
-- (fk_product, entity), jamais de fk_store : le drainage rappelle
-- ShopifyStockTrigger::updateShopifyStock() qui gere lui-meme le fan-out multi-boutiques en
-- interne).
-- Note (Partie 3, Hotfix 2.4.5 remise) : aucun backfill non plus — colonne NULL par defaut,
-- chaque commande candidate est classifiee/marquee au premier scan par DiscountRepairService.
-- ============================================================================
