-- Date: 2026-06-19
-- Version: 2.3.0
-- Description: Journal des actions module (hors webhook_events) — Story 38.3
--              Capture les actions à forte valeur de diagnostic : clôtures auto,
--              skips idempotence, erreurs CRON non liées à un webhook.
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License

CREATE TABLE llx_doli2shop_action_log
(
  rowid         integer AUTO_INCREMENT PRIMARY KEY,
  date_action   datetime NOT NULL COMMENT 'Horodatage de l action',
  action_type   varchar(50) NOT NULL COMMENT 'Type d action (order_auto_closed, expedition_skipped_duplicate, ...)',
  object_type   varchar(50) DEFAULT NULL COMMENT 'Type d objet Dolibarr (commande, facture, shipping, product)',
  object_id     integer DEFAULT NULL COMMENT 'Rowid de l objet Dolibarr',
  result        varchar(20) DEFAULT NULL COMMENT 'Résultat: success, error, skipped',
  message       text DEFAULT NULL COMMENT 'Message court (< 500 chars)',
  entity        integer NOT NULL DEFAULT 1,
  fk_store      integer DEFAULT NULL COMMENT 'Boutique source (NULL = global/inconnu)'
) ENGINE=innodb;

-- Index liste/filtre par date (par entité)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_date');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_action_log_date ON llx_doli2shop_action_log (entity, date_action)',
                   'SELECT "Index idx_doli2shop_action_log_date already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index filtre par type d action (par entité)
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'llx_doli2shop_action_log'
               AND INDEX_NAME = 'idx_doli2shop_action_log_type');
SET @sqlstmt := IF(@exist = 0,
                   'CREATE INDEX idx_doli2shop_action_log_type ON llx_doli2shop_action_log (entity, action_type)',
                   'SELECT "Index idx_doli2shop_action_log_type already exists"');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
