-- ============================================================================
-- Migration Doli2Shop : 2.5.3 -> 2.5.3b
--
-- Date        : 2026-09-12
-- Version     : 2.5.3
-- Description : Colonnes d'ecart de totaux Shopify/Dolibarr sur llx_doli2shop_orders.
--               Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- POURQUOI UN FICHIER SEPARE, ET PAS UNE PARTIE 2 DE update_2.5.0_2.5.3.sql :
--
-- Ces colonnes avaient d'abord ete ajoutees a la fin de update_2.5.0_2.5.3.sql, sous la
-- MEME cle de version. La revue du 12/09/2026 a montre que c'etait un defaut CRITICAL,
-- verifie en base : la cle '2.5.0_2.5.3' etait deja enregistree success=1 depuis le
-- 08/09 (MigrationManager::isMigrationApplied() ne compare QUE la chaine de version,
-- sans aucun hash de contenu). Toute installation ayant deja joue ce fichier aurait donc
-- SAUTE ces colonnes pour toujours, et le code neuf de admin/health.php aurait plante
-- sur « Unknown column ».
--
-- REGLE A RETENIR : un fichier de migration deja livre est FIGE. Toute colonne
-- supplementaire va dans un NOUVEAU fichier avec une NOUVELLE cle de version, meme si
-- elle appartient au meme train. C'est la meme classe de defaut que la story
-- migration-ne-comble-jamais-une-table-incomplete, corrigee dans ce meme train.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_orders — colonnes ecart de totaux Shopify/Dolibarr
-- Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (AC1) : le controle de
-- coherence des totaux existe depuis la v2.3.0 (createOrder()) mais n'aboutissait qu'au log
-- (dolibarr.log, jamais lu en exploitation courante) — sur toute boutique en prix hors taxes,
-- l'ecart existait sur CHAQUE commande pendant plus d'un an sans que personne ne l'entende. Ces
-- colonnes persistent desormais la marque (severite + montants) pour un compteur/listing
-- consultable a l'ecran (admin/health.php, admin/diagnostic.php).
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'totals_mismatch_severity');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN totals_mismatch_severity varchar(20) DEFAULT NULL COMMENT ''null|rounding|proportional''',
                   'SELECT "Column totals_mismatch_severity already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'shopify_total_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN shopify_total_ttc double(20,4) DEFAULT NULL COMMENT ''Total TTC Shopify au moment de la creation (si ecart detecte)''',
                   'SELECT "Column shopify_total_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'dolibarr_total_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN dolibarr_total_ttc double(20,4) DEFAULT NULL COMMENT ''Total TTC Dolibarr au moment de la creation (si ecart detecte)''',
                   'SELECT "Column dolibarr_total_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'idx_doli2shop_orders_totals_mismatch');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD INDEX idx_doli2shop_orders_totals_mismatch (totals_mismatch_severity, entity)',
                   'SELECT "Index idx_doli2shop_orders_totals_mismatch already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
