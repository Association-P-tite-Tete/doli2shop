-- =====================================================
-- MIGRATION v2.2.3 -> v2.2.4 (DOLI2SHOP)
-- Date: 2026-05-14
-- Version: 2.2.4
-- Description: Migration ID module Dolibarr 436950 -> 351003
--              (ID officiel attribué dans la plage réservée à
--               l'Association P'tite Tête par Dolibarr Foundation)
-- Author: P'tite Tête
-- Copyright 2024-2026 P'tite Tête <doli2shop@ptitetete.com>
-- License: http://www.gnu.org/licenses/gpl.html GNU General Public License
-- =====================================================
-- IMPORTANT: Ce script est IDEMPOTENT (peut être exécuté plusieurs fois)
-- =====================================================
-- CONTEXTE :
-- Le module Doli2Shop déclarait initialement $this->numero = 436950 qui
-- n'était pas dans la plage des IDs autorisés par Dolibarr Foundation.
-- L'ID officiellement attribué à l'association P'tite Tête est 351003.
--
-- Les rights Dolibarr sont construits dynamiquement comme :
--     $this->numero . sprintf("%02d", $r + 1)
-- → Anciens IDs : 43695001 (read), 43695002 (write), 43695003 (delete)
-- → Nouveaux IDs : 35100301 (read), 35100302 (write), 35100303 (delete)
--
-- Sans cette migration, les utilisateurs Doli2Shop existants perdraient
-- toutes leurs permissions au prochain re-enregistrement des rights par
-- Dolibarr (à la réactivation du module). Cette migration préserve les
-- assignations existantes en renommant les IDs en base.
-- =====================================================


-- ============================================================================
-- PARTIE 1 : Migration des rights dans llx_rights_def (table des droits)
-- ============================================================================
-- Si Dolibarr a déjà ré-enregistré les nouveaux rights (35100301/2/3)
-- AVANT cette migration, les anciennes lignes (43695001/2/3) deviennent
-- orphelines. On ne peut pas avoir deux lignes avec le même PK → on
-- supprime d'abord les éventuelles nouvelles lignes orphelines, puis on
-- renomme les anciennes vers les nouveaux IDs.
-- ============================================================================

-- 1.a — Supprimer les nouvelles lignes orphelines (au cas où Dolibarr les a déjà créées)
DELETE FROM llx_rights_def WHERE id IN (35100301, 35100302, 35100303) AND module = 'doli2shop';

-- 1.b — Renommer les anciennes lignes vers les nouveaux IDs
UPDATE llx_rights_def SET id = 35100301 WHERE id = 43695001 AND module = 'doli2shop';
UPDATE llx_rights_def SET id = 35100302 WHERE id = 43695002 AND module = 'doli2shop';
UPDATE llx_rights_def SET id = 35100303 WHERE id = 43695003 AND module = 'doli2shop';


-- ============================================================================
-- PARTIE 2 : Migration des assignations utilisateurs (llx_user_rights)
-- ============================================================================
-- Préserve les rights attribués individuellement aux utilisateurs.
-- Idempotent : un second run ne trouvera plus les anciens fk_id à migrer.
-- ============================================================================

UPDATE llx_user_rights SET fk_id = 35100301 WHERE fk_id = 43695001;
UPDATE llx_user_rights SET fk_id = 35100302 WHERE fk_id = 43695002;
UPDATE llx_user_rights SET fk_id = 35100303 WHERE fk_id = 43695003;


-- ============================================================================
-- PARTIE 3 : Migration des assignations groupes (llx_usergroup_rights)
-- ============================================================================
-- Préserve les rights attribués aux groupes d'utilisateurs.
-- ============================================================================

UPDATE llx_usergroup_rights SET fk_id = 35100301 WHERE fk_id = 43695001;
UPDATE llx_usergroup_rights SET fk_id = 35100302 WHERE fk_id = 43695002;
UPDATE llx_usergroup_rights SET fk_id = 35100303 WHERE fk_id = 43695003;


-- ============================================================================
-- PARTIE 4 : Marqueur de migration (traçabilité)
-- ============================================================================
-- Insère une trace dans la table de migrations Doli2Shop (créée v2.1.2).
-- Idempotent via UNIQUE KEY uk_migration_entity (migration_version, entity).
-- INSERT IGNORE → second run = silent no-op.
-- ============================================================================

INSERT IGNORE INTO llx_doli2shop_migrations
    (migration_version, migration_file, applied_date, success, entity)
VALUES
    ('2.2.3_2.2.4', 'update_2.2.3_2.2.4.sql', NOW(), 1, 1);


-- ============================================================================
-- FIN DE LA MIGRATION
-- ============================================================================
-- Pour vérifier l'état post-migration :
--   SELECT id, module, perms FROM llx_rights_def WHERE module = 'doli2shop';
-- Attendu : 3 lignes avec id 35100301, 35100302, 35100303
-- ============================================================================
