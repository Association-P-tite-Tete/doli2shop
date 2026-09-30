-- ============================================================================
-- Migration Doli2Shop : 2.5.5 -> 2.6.0
--
-- Date        : 2026-09-19
-- Version     : 2.6.0
-- Description : Journal de RUN sur llx_doli2shop_action_log — identifiant de run
--               et provenance (CRON / MANUEL / WEBHOOK / API). Story
--               journal-de-run-identifiant-et-provenance.
-- Author      : P'tite Tete
-- Copyright   : 2024-2026 P'tite Tete
-- License     : GPL v3+
--
-- NOUVEAU FICHIER, cle de version NEUVE (meme regle que les migrations
-- precedentes) : jamais une "partie 2" d'un fichier de migration deja livre.
--
-- Les deux colonnes sont NULLABLES sans valeur par defaut : les lignes ecrites
-- avant cette migration gardent NULL, ce qui se lit comme "provenance inconnue"
-- et non comme une provenance fausse. Backfill volontairement ABSENT : deviner
-- l'origine d'une ligne historique produirait une donnee inventee.
-- ============================================================================

-- ============================================================================
-- llx_doli2shop_action_log — colonne run_id
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND COLUMN_NAME = 'run_id');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_action_log ADD COLUMN run_id varchar(32) DEFAULT NULL COMMENT ''Identifiant du cycle d execution, genere localement''',
                   'SELECT "Colonne run_id already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- llx_doli2shop_action_log — colonne origin
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND COLUMN_NAME = 'origin');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_action_log ADD COLUMN origin varchar(20) DEFAULT NULL COMMENT ''Provenance: CRON, MANUEL, WEBHOOK, API (NULL = inconnue, lignes anterieures)''',
                   'SELECT "Colonne origin already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- llx_doli2shop_action_log — colonne duration_ms (bilan du run)
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND COLUMN_NAME = 'duration_ms');
SET @sqlstmt := IF(@exist = 0,
                   'ALTER TABLE llx_doli2shop_action_log ADD COLUMN duration_ms integer DEFAULT NULL COMMENT ''Duree du run en millisecondes, renseignee sur la ligne de fin''',
                   'SELECT "Colonne duration_ms already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- Index de lecture : retrouver les lignes d un run, et le dernier run par
-- provenance (c est CETTE requete qui repond a "l ordonnanceur tourne-t-il ?").
-- ============================================================================
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_run');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_action_log_run ON llx_doli2shop_action_log (entity, run_id)',
                   'SELECT "Index idx_doli2shop_action_log_run already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_origin');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_action_log_origin ON llx_doli2shop_action_log (entity, origin, date_action)',
                   'SELECT "Index idx_doli2shop_action_log_origin already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
