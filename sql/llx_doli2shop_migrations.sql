-- Date: 2025-10-16
-- Version: 2.1.2
-- Description: Table de tracking des migrations pour éviter les ré-exécutions
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- FIX #144 v2.1.2: Système de tracking migrations pour migrations idempotentes

-- Table pour tracer l'historique des migrations appliquées
CREATE TABLE llx_doli2shop_migrations (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    migration_version VARCHAR(20) NOT NULL COMMENT 'Version de la migration (ex: 2.0.5_2.0.6)',
    migration_file VARCHAR(255) NOT NULL COMMENT 'Nom du fichier de migration',
    applied_date DATETIME NOT NULL COMMENT 'Date d''application de la migration',
    execution_time_ms INT DEFAULT 0 COMMENT 'Durée d''exécution en millisecondes',
    success TINYINT(1) DEFAULT 1 COMMENT '1 = succès, 0 = échec',
    error_message TEXT DEFAULT NULL COMMENT 'Message d''erreur si échec',
    entity INT DEFAULT 1 NOT NULL COMMENT 'Multi-entity support',
    INDEX idx_migration_version (migration_version),
    INDEX idx_entity (entity),
    UNIQUE KEY uk_migration_entity (migration_version, entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
