<?php
/**
 * @file        class/MigrationManager.class.php
 * @brief       Gestionnaire intelligent de migrations SQL idempotentes
 *
 * FIX #144 v2.1.2: Empêche la ré-exécution des migrations et la perte de données
 * lors de la réactivation ou mise à jour du module.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    migration
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.1.2
 * @since       2.1.2
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible avec chargement dans init() et par modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Classe MigrationManager
 *
 * Gère l'exécution intelligente et idempotente des migrations SQL.
 * Empêche la perte de données lors des réactivations du module.
 */
class MigrationManager
{
    use LoggerTrait;

    /** @var DoliDB Instance de la base de données */
    private $db;

    /** @var int Entité Dolibarr courante */
    private $entity;

    /** @var string Table de tracking des migrations (sans préfixe) */
    private $historyTable = 'doli2shop_migrations';

    /**
     * Constructeur
     *
     * @param DoliDB $db Instance base de données
     * @param int $entity Entité Dolibarr
     */
    public function __construct($db, $entity = 1)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * Vérifie si la table de tracking existe
     *
     * @return bool True si la table existe
     */
    private function historyTableExists()
    {
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "doli2shop_migrations'";
        $result = $this->db->query($sql);

        if ($result) {
            return $this->db->num_rows($result) > 0;
        }

        return false;
    }

    /**
     * Crée la table de tracking si elle n'existe pas
     *
     * @return bool True si succès
     */
    private function ensureHistoryTableExists()
    {
        if ($this->historyTableExists()) {
            return true;
        }

        $this->log("Table de tracking migrations inexistante, création...", LOG_INFO);

        // Pas de IF NOT EXISTS (convention Dolibarr) : l'existence est déjà
        // vérifiée par historyTableExists() juste au-dessus.
        $sql = "CREATE TABLE " . MAIN_DB_PREFIX . "doli2shop_migrations (
            rowid INT AUTO_INCREMENT PRIMARY KEY,
            migration_version VARCHAR(20) NOT NULL,
            migration_file VARCHAR(255) NOT NULL,
            applied_date DATETIME NOT NULL,
            execution_time_ms INT DEFAULT 0,
            success TINYINT(1) DEFAULT 1,
            error_message TEXT DEFAULT NULL,
            entity INT DEFAULT 1 NOT NULL,
            INDEX idx_migration_version (migration_version),
            INDEX idx_entity (entity),
            UNIQUE KEY uk_migration_entity (migration_version, entity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $result = $this->db->query($sql);

        if ($result) {
            $this->log("Table de tracking créée avec succès", LOG_INFO);
            return true;
        } else {
            $this->log("ERREUR création table tracking: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Vérifie si une migration a déjà été appliquée
     *
     * @param string $version Version de la migration (ex: "2.0.5_2.0.6")
     * @return bool True si déjà appliquée
     */
    public function isMigrationApplied($version)
    {
        // S'assurer que la table existe
        if (!$this->ensureHistoryTableExists()) {
            // Si la table ne peut pas être créée, on considère la migration comme non appliquée
            // pour ne pas bloquer le processus
            return false;
        }

        $sql = "SELECT rowid, success
                FROM " . MAIN_DB_PREFIX . "doli2shop_migrations
                WHERE migration_version = '" . $this->db->escape($version) . "'
                AND entity = " . (int)$this->entity;

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            $alreadyApplied = ($obj->success == 1);

            if ($alreadyApplied) {
                $this->log("Migration $version déjà appliquée - SKIP", LOG_INFO);
            } else {
                $this->log("Migration $version en échec précédemment - RETRY", LOG_WARNING);
            }

            return $alreadyApplied;
        }

        return false;
    }

    /**
     * Enregistre une migration dans l'historique
     *
     * @param string $version Version de la migration
     * @param string $filename Nom du fichier
     * @param int $executionTimeMs Durée d'exécution en ms
     * @param bool $success Succès ou échec
     * @param string|null $errorMessage Message d'erreur éventuel
     * @return bool True si succès
     */
    private function recordMigration($version, $filename, $executionTimeMs, $success, $errorMessage = null)
    {
        // S'assurer que la table existe
        if (!$this->ensureHistoryTableExists()) {
            return false;
        }

        // Supprimer l'entrée existante si présente (pour retry en cas d'échec)
        $sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_migrations
                      WHERE migration_version = '" . $this->db->escape($version) . "'
                      AND entity = " . (int)$this->entity;
        $this->db->query($sqlDelete);

        // Insérer le nouvel enregistrement
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_migrations
                (migration_version, migration_file, applied_date, execution_time_ms, success, error_message, entity)
                VALUES (
                    '" . $this->db->escape($version) . "',
                    '" . $this->db->escape($filename) . "',
                    NOW(),
                    " . (int)$executionTimeMs . ",
                    " . ($success ? 1 : 0) . ",
                    " . ($errorMessage ? "'" . $this->db->escape($errorMessage) . "'" : "NULL") . ",
                    " . (int)$this->entity . "
                )";

        $result = $this->db->query($sql);

        if ($result) {
            $status = $success ? "[OK] SUCCÈS" : "[ERROR] ÉCHEC";
            $this->log("Migration $version enregistrée: $status ({$executionTimeMs}ms)", LOG_INFO);
            return true;
        } else {
            $this->log("ERREUR enregistrement migration: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Exécute une migration de manière sécurisée et idempotente
     *
     * @param string $version Version de la migration (ex: "2.0.5_2.0.6")
     * @param string $sqlFile Chemin complet vers le fichier SQL
     * @return bool True si succès (ou déjà appliquée)
     */
    public function executeMigration($version, $sqlFile)
    {
        // Vérifier si déjà appliquée
        if ($this->isMigrationApplied($version)) {
            return true; // Déjà appliquée = succès
        }

        // Vérifier que le fichier existe
        if (!file_exists($sqlFile)) {
            $this->log("ERREUR: Fichier migration introuvable: $sqlFile", LOG_ERR);
            return false;
        }

        $filename = basename($sqlFile);
        $this->log("Exécution migration $version depuis $filename...", LOG_INFO);

        $startTime = microtime(true);
        $success = true;
        $errorMessage = null;

        // Transaction pour atomicité
        $this->db->begin();

        try {
            // Lire le contenu du fichier SQL
            $sqlContent = file_get_contents($sqlFile);

            // Nettoyer et séparer les requêtes
            $queries = $this->parseSQLContent($sqlContent);

            $queryCount = 0;
            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) continue;

                $queryCount++;
                $result = $this->db->query($query);

                if (!$result) {
                    $success = false;
                    $errorMessage = "Requête #$queryCount échouée: " . $this->db->lasterror();
                    $this->log($errorMessage, LOG_ERR);
                    break;
                }
            }

            if ($success) {
                $this->db->commit();
                $this->log("Migration $version appliquée avec succès ($queryCount requêtes)", LOG_INFO);
            } else {
                $this->db->rollback();
                $this->log("Migration $version échouée - ROLLBACK", LOG_ERR);
            }

        } catch (Exception $e) {
            $this->db->rollback();
            $success = false;
            $errorMessage = "Exception: " . $e->getMessage();
            $this->log("EXCEPTION migration $version: $errorMessage", LOG_ERR);
        }

        $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

        // Enregistrer dans l'historique
        $this->recordMigration($version, $filename, $executionTimeMs, $success, $errorMessage);

        return $success;
    }

    /**
     * Parse le contenu SQL en requêtes individuelles
     *
     * DETTE 50-6 (décision d'architecture) : tous les update_*.sql historiques
     * embarquent un marqueur "INSERT IGNORE INTO llx_doli2shop_migrations (...)
     * VALUES ('X.Y.Z_A.B.C', ..., 1)" avec entity=1 codé en dur. En multi-entité,
     * rejouer ce fichier depuis une entité 2+ (première activation du module sur
     * cette entité) exécuterait cette ligne littéralement : comme aucune ligne
     * n'existe encore pour (version, entity=1), l'INSERT IGNORE ne serait PAS un
     * no-op — il créerait un faux marqueur "migration appliquée" pour l'entité 1,
     * alors que l'entité 1 n'a peut-être jamais activé le module.
     * Le suivi par entité est déjà assuré correctement et dynamiquement par
     * recordMigration() (DELETE+INSERT sur $this->entity = l'entité réellement
     * en cours d'activation, cf. executeMigration()) juste après l'exécution de
     * ce fichier : la ligne SQL embarquée est donc strictement redondante avec
     * ce que fait déjà le PHP, et dangereuse pour les autres entités. On la
     * neutralise ici (jamais envoyée à $db->query()) plutôt que de modifier les
     * fichiers .sql historiques déjà déployés chez les clients (contenu figé).
     * Les FUTURES migrations n'ont plus besoin d'inclure ce marqueur (le
     * bookkeeping est intégralement géré par MigrationManager) — voir la note
     * en commentaire dans sql/update_2.4.0_2.4.1.sql.
     *
     * @param string $sqlContent Contenu SQL brut
     * @return array Tableau de requêtes SQL
     */
    private function parseSQLContent($sqlContent)
    {
        // Supprimer les commentaires SQL
        $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
        $sqlContent = preg_replace('#/\*.*?\*/#s', '', $sqlContent);

        // Séparer par point-virgule
        $queries = explode(';', $sqlContent);

        $historyTable = MAIN_DB_PREFIX . $this->historyTable;

        // Filtrer les requêtes vides ET les marqueurs de migration embarqués
        // (DETTE 50-6, cf. docblock ci-dessus) — MigrationManager::recordMigration()
        // gère déjà ce bookkeeping avec l'entité dynamique correcte.
        $queries = array_filter($queries, function ($q) use ($historyTable) {
            $trimmed = trim($q);
            if (empty($trimmed)) {
                return false;
            }

            // Fix review 50-6 : ancrer la fin du nom de table (\`?\s*[\s(]) pour ne pas
            // matcher accidentellement une table de nom voisin (ex. llx_doli2shop_migrations_archive)
            // — sans ancrage, le préfixe seul suffisait à filtrer (et donc perdre) des requêtes
            // légitimes visant une autre table.
            if (preg_match('/^INSERT\s+(IGNORE\s+)?INTO\s+`?' . preg_quote($historyTable, '/') . '`?\s*[\s(]/i', $trimmed)) {
                return false;
            }

            return true;
        });

        return $queries;
    }

    /**
     * Liste toutes les migrations appliquées pour l'entité courante
     *
     * @return array Tableau des migrations appliquées
     */
    public function getAppliedMigrations()
    {
        if (!$this->historyTableExists()) {
            return [];
        }

        $sql = "SELECT migration_version, migration_file, applied_date, execution_time_ms, success
                FROM " . MAIN_DB_PREFIX . "doli2shop_migrations
                WHERE entity = " . (int)$this->entity . "
                ORDER BY applied_date DESC";

        $result = $this->db->query($sql);
        $migrations = [];

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $migrations[] = [
                    'version' => $obj->migration_version,
                    'file' => $obj->migration_file,
                    'date' => $obj->applied_date,
                    'duration_ms' => $obj->execution_time_ms,
                    'success' => $obj->success == 1
                ];
            }
        }

        return $migrations;
    }
}
