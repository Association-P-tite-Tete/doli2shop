-- ============================================================================
-- Migration Doli2Shop : 2.5.3b -> 2.5.4
--
-- Date        : 2026-09-12
-- Version     : 2.5.4
-- Description : Colonnes d'audit des commandes importees en prix hors taxes avant
--               le correctif e825f456 (sous-evaluation ~17% par division par 1+taux
--               appliquee a un montant deja HT). Story
--               reprise-des-commandes-importees-en-prix-hors-taxes.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, PAS UNE PARTIE 2 DE update_2.5.3_2.5.3b.sql : ce dernier est
-- DEJA LIVRE (cle de version '2.5.3_2.5.3b' deja enregistree chez au moins une
-- installation) — MigrationManager::isMigrationApplied() ne compare que la chaine
-- de version, sans hash de contenu (lecon du 12/09/2026, meme defaut que celui qui
-- a produit ce fichier lui-meme). Toute colonne supplementaire va dans un fichier
-- neuf avec une cle de version neuve.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_orders — colonnes d'audit sous-evaluation (UnderpricedOrderAuditService)
--
-- L'oracle est la note historique ecrite en note_private a l'import (depuis la
-- v2.3.0, "[Shopify] Sous-total: X | Taxes: Y | Remises: Z | Total: W", construite a
-- partir des champs GraphQL bruts, jamais passes par la conversion buggee) — AUCUN
-- appel reseau Shopify n'est necessaire ni effectue par ce service.
--
-- Ces colonnes persistent le VERDICT de l'audit (jamais une correction : la story
-- decide explicitement de n'ecrire AUCUNE donnee commande/facture/paiement) pour
-- l'idempotence (AC6 : une commande deja auditee n'est plus jamais recomptee) et la
-- piste d'audit (AC5 : qui, quand, quels montants).
-- ============================================================================

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_status');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_status varchar(20) DEFAULT NULL COMMENT ''already_correct|manual_required|manual_review''',
                   'SELECT "Column underprice_audit_status already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_reason');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_reason varchar(64) DEFAULT NULL COMMENT ''motif detaille du verdict (cf. UnderpricedOrderAuditService::REASON_*)''',
                   'SELECT "Column underprice_audit_reason already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_date');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_date datetime DEFAULT NULL COMMENT ''Date audit (tracabilite AC5)''',
                   'SELECT "Column underprice_audit_date already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_fk_user');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_fk_user integer DEFAULT NULL COMMENT ''Utilisateur ayant lance audit (tracabilite AC5)''',
                   'SELECT "Column underprice_audit_fk_user already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_recorded_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_recorded_ttc double(20,4) DEFAULT NULL COMMENT ''Total TTC enregistre en base au moment audit''',
                   'SELECT "Column underprice_audit_recorded_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_real_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_real_ttc double(20,4) DEFAULT NULL COMMENT ''Total TTC reel lu dans la note historique Shopify (NULL si aucune note)''',
                   'SELECT "Column underprice_audit_real_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND COLUMN_NAME = 'underprice_audit_delta_ttc');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD COLUMN underprice_audit_delta_ttc double(20,4) DEFAULT NULL COMMENT ''Ecart TTC reel - enregistre (NULL si non calculable)''',
                   'SELECT "Column underprice_audit_delta_ttc already exists in llx_doli2shop_orders"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_orders'
               AND INDEX_NAME = 'idx_doli2shop_orders_underprice_audit');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_orders ADD INDEX idx_doli2shop_orders_underprice_audit (underprice_audit_status, entity)',
                   'SELECT "Index idx_doli2shop_orders_underprice_audit already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
