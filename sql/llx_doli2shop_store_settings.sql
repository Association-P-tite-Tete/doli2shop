-- =====================================================
-- Date: 2026-06-30
-- Version: 2.3.8
-- Description: Création de la table llx_doli2shop_store_settings —
--              réglages key-value par boutique avec fallback global (Story 49-3)
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- Fichier de création fraîche (installation neuve).
-- Pour les installations existantes, utiliser update_2.3.7_2.3.8.sql (migration idempotente).
-- =====================================================

CREATE TABLE llx_doli2shop_store_settings
(
    rowid         integer      AUTO_INCREMENT PRIMARY KEY,
    entity        int          NOT NULL DEFAULT 1,
    fk_store      int          NOT NULL,
    setting_key   varchar(128) NOT NULL,
    setting_value text         DEFAULT NULL,
    tms           timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- UNIQUE (entity, fk_store, setting_key) : couvre aussi les lookups par entity seul
-- (préfixe gauche) — pas d'index (entity) séparé nécessaire (review 49-3).
CREATE UNIQUE INDEX uk_doli2shop_store_settings_key ON llx_doli2shop_store_settings (entity, fk_store, setting_key);
