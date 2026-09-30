<?php
/* Copyright (C) 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * @file       class/runjournal.class.php
 * @brief      Journal de RUN : un identifiant par cycle, sa provenance, et une ligne écrite AU
 *             DÉMARRAGE — pour qu'un ordonnanceur à l'arrêt se distingue d'un ordonnanceur
 *             sans travail.
 * @package    ShopifyIntegration
 * @subpackage Core
 * @category   Logging
 * @author     P'tite Tête
 * @copyright  2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license    GPL v3+
 * @version    2.6.0
 * @since      2.6.0
 * @link       https://doli2shop.ptitetete.org
 */

require_once __DIR__ . '/actionlogger.class.php';

/**
 * Journal de RUN du module.
 *
 * ## Pourquoi cette classe existe
 *
 * Deux dossiers clients de septembre 2026 se sont joués sur de l'archéologie dans un
 * `dolibarr.log` de 323 Mo. Dans l'un des deux, le diagnostic final a été rendu par une capture
 * d'écran de la liste des tâches planifiées de Dolibarr : les douze tâches du module affichaient
 * « 0 lancement », et le module n'avait AUCUN moyen de le dire lui-même — son diagnostic annonce
 * une tâche « Actif », ce qui décrit sa configuration, pas son exécution.
 *
 * ## Les trois décisions de conception, et ce qu'elles écartent
 *
 * 1. **Une ligne au DÉMARRAGE, pas seulement à la fin.** Sans elle, un ordonnanceur qui ne tourne
 *    pas et un ordonnanceur qui tourne sans rien avoir à faire produisent le même vide. C'est LA
 *    raison d'être de cette classe ; tout le reste est du confort.
 *
 * 2. **Écriture sur une connexion INDÉPENDANTE.** La trace doit survivre au `rollback()` de
 *    l'opération qu'elle trace, sans quoi l'échec le plus intéressant est précisément celui qui
 *    n'écrit rien. Le module a ce piège au point d'abandon de la synchro d'images
 *    (`ImportProducts::syncProductAllImages()`, abandon suivi d'un `rollback()`).
 *    Patron repris du module communautaire einvoicing (`AbstractPDPProvider::logCall()`), dont la
 *    session mainteneuse a confirmé l'intention : « the trace must survive a rollback of the
 *    caller's transaction ».
 *
 * 3. **Identifiant GÉNÉRÉ LOCALEMENT, jamais un `SELECT MAX() + 1`.** einvoicing emploie un
 *    compteur lisible incrémental, ce qui l'oblige à un `SELECT ... FOR UPDATE` tenu jusqu'au
 *    commit (et à un verrou consultatif sur PostgreSQL, qui refuse `FOR UPDATE` sur un agrégat) :
 *    sans ce verrou, deux runs concurrents prennent le même numéro et l'un des deux PERD SA TRACE
 *    sur la contrainte d'unicité — l'exact contraire du but recherché. Un identifiant généré
 *    localement supprime d'un coup la contention, l'agrégat et le verrou.
 *
 * ## Ce que cette classe n'enregistre PAS, délibérément
 *
 * Aucun corps de requête, aucune réponse d'API. Des identifiants et des compteurs. Cela SUPPRIME
 * la question des données personnelles au lieu de la masquer : sur einvoicing, noms, adresses et
 * identifiants fiscaux partent en clair dans une table lisible par tout utilisateur ayant le droit
 * de lecture, leur masquage ne couvrant que les secrets d'authentification.
 *
 * ## Destiné à devenir un standard des modules P'tite Tête
 *
 * La forme (identifiant, provenance, ligne de démarrage, bilan, export) est volontairement
 * indépendante du métier Shopify, pour être reprise telle quelle par les autres modules.
 */
class RunJournal
{
    /** Provenance : tâche planifiée Dolibarr. */
    const ORIGIN_CRON = 'CRON';

    /** Provenance : action lancée depuis un écran d'administration du module. */
    const ORIGIN_MANUAL = 'MANUEL';

    /** Provenance : traitement déclenché par un webhook Shopify. */
    const ORIGIN_WEBHOOK = 'WEBHOOK';

    /** Provenance : appel de l'API du module. */
    const ORIGIN_API = 'API';

    /** Type d'action de la ligne de démarrage de run. */
    const TYPE_RUN_START = 'run_start';

    /** Type d'action de la ligne de fin de run. */
    const TYPE_RUN_END = 'run_end';

    /**
     * Connexion indépendante, partagée par tout le processus.
     *
     * @var DoliDB|null
     */
    private static $journalDb = null;

    /**
     * Identifiant du run en cours, ou null hors run.
     *
     * @var string|null
     */
    private static $currentRunId = null;

    /**
     * Provenance du run en cours.
     *
     * @var string|null
     */
    private static $currentOrigin = null;

    /**
     * Horodatage de démarrage (microtime), pour la durée.
     *
     * @var float|null
     */
    private static $startedAt = null;

    /**
     * Toutes les provenances reconnues.
     *
     * @return string[]
     */
    public static function allOrigins()
    {
        return [self::ORIGIN_CRON, self::ORIGIN_MANUAL, self::ORIGIN_WEBHOOK, self::ORIGIN_API];
    }

    /**
     * Génère un identifiant de run.
     *
     * Horodatage compact + 8 caractères aléatoires : lisible (on voit quand le run a eu lieu),
     * triable, et sans collision praticable — donc sans lecture préalable de la table, sans
     * agrégat et sans verrou. Voir la décision 3 dans l'en-tête de classe.
     *
     * @return string Identifiant de 24 caractères au plus
     */
    public static function generateRunId()
    {
        try {
            $random = bin2hex(random_bytes(4));
        } catch (Exception $e) {
            // random_bytes() ne peut échouer que faute de source d'entropie. Un identifiant
            // dégradé vaut mieux qu'un run non tracé : c'est précisément le silence qu'on corrige.
            $random = substr(md5(uniqid('', true)), 0, 8);
        }

        return date('Ymd-His') . '-' . $random;
    }

    /**
     * Ouvre une connexion indépendante de celle du métier.
     *
     * @return DoliDB|null La connexion, ou null si elle ne peut pas être ouverte
     */
    private static function journalDb()
    {
        global $conf, $db, $dolibarr_main_db_pass;

        if (self::$journalDb !== null) {
            return self::$journalDb;
        }

        // Une connexion de plus par processus n'est pas gratuite : on ne l'ouvre que si la
        // configuration est complète. À défaut, on retombe sur la connexion métier — dégradé mais
        // jamais bloquant : un journal est un outil de diagnostic, il ne doit JAMAIS empêcher le
        // travail qu'il observe.
        if (!function_exists('getDoliDBInstance') || empty($conf->db->type) || empty($conf->db->name)) {
            self::$journalDb = $db;
            return self::$journalDb;
        }

        try {
            $independent = getDoliDBInstance(
                $conf->db->type,
                $conf->db->host,
                (string) $conf->db->user,
                (string) $dolibarr_main_db_pass,
                (string) $conf->db->name,
                (int) $conf->db->port
            );
            self::$journalDb = (!empty($independent) && empty($independent->error)) ? $independent : $db;
        } catch (Exception $e) {
            self::$journalDb = $db;
        }

        return self::$journalDb;
    }

    /**
     * Déclare le démarrage d'un run et retourne son identifiant.
     *
     * @param  int         $entity  Entité courante
     * @param  string      $origin  Provenance (cf. constantes ORIGIN_*)
     * @param  string      $label   Libellé court de ce que fait le run
     * @param  int|null    $fkStore Boutique concernée, si applicable
     * @return string      Identifiant du run
     */
    public static function start($entity, $origin, $label = '', $fkStore = null)
    {
        if (!in_array($origin, self::allOrigins(), true)) {
            $origin = self::ORIGIN_MANUAL;
        }

        self::$currentRunId = self::generateRunId();
        self::$currentOrigin = $origin;
        self::$startedAt = microtime(true);

        self::write(
            $entity,
            self::TYPE_RUN_START,
            ActionLogger::RESULT_SUCCESS,
            $label !== '' ? $label : 'Demarrage du cycle',
            $fkStore,
            null
        );

        return self::$currentRunId;
    }

    /**
     * Déclare la fin du run en cours, avec son bilan.
     *
     * @param  int              $entity   Entité courante
     * @param  array<string,int> $counters Compteurs du run (clé => valeur)
     * @param  string           $result   Résultat global (cf. ActionLogger::RESULT_*)
     * @param  int|null         $fkStore  Boutique concernée, si applicable
     * @return bool             true si la ligne a été écrite
     */
    public static function finish($entity, array $counters = [], $result = ActionLogger::RESULT_SUCCESS, $fkStore = null)
    {
        if (self::$currentRunId === null) {
            return false;
        }

        $durationMs = null;
        if (self::$startedAt !== null) {
            $durationMs = (int) round((microtime(true) - self::$startedAt) * 1000);
        }

        $parts = [];
        foreach ($counters as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        $message = empty($parts) ? 'Fin du cycle' : implode(' ', $parts);

        $written = self::write($entity, self::TYPE_RUN_END, $result, $message, $fkStore, $durationMs);

        self::$currentRunId = null;
        self::$currentOrigin = null;
        self::$startedAt = null;

        return $written;
    }

    /**
     * Reprend un run déjà démarré, dans un AUTRE processus.
     *
     * Nécessaire pour les traitements découpés en lots AJAX : chaque lot est une requête HTTP
     * distincte, donc un processus distinct, et l'état statique ne survit pas d'un lot à l'autre.
     * L'identifiant circule alors dans la requête et la réponse.
     *
     * ⚠️ L'identifiant venant du client est traité comme une DONNÉE, jamais comme une valeur de
     * confiance : il est validé sur sa forme avant d'être écrit, sinon il devient un vecteur
     * d'injection dans une colonne qu'on relira ensuite.
     *
     * @param  string $runId  Identifiant reçu
     * @param  string $origin Provenance
     * @return bool   true si l'identifiant a été accepté
     */
    public static function resume($runId, $origin)
    {
        if (!is_string($runId) || !preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/', $runId)) {
            return false;
        }
        if (!in_array($origin, self::allOrigins(), true)) {
            return false;
        }

        self::$currentRunId = $runId;
        self::$currentOrigin = $origin;
        // La durée n'a pas de sens ici : elle est mesurée par le processus qui a démarré le run.
        self::$startedAt = null;

        return true;
    }

    /**
     * Identifiant du run en cours, ou null.
     *
     * @return string|null
     */
    public static function currentRunId()
    {
        return self::$currentRunId;
    }

    /**
     * Provenance du run en cours, ou null.
     *
     * @return string|null
     */
    public static function currentOrigin()
    {
        return self::$currentOrigin;
    }

    /**
     * Réinitialise l'état (tests, et fin de processus long).
     *
     * @return void
     */
    public static function reset()
    {
        self::$currentRunId = null;
        self::$currentOrigin = null;
        self::$startedAt = null;
        self::$journalDb = null;
    }

    /**
     * Écrit une ligne du journal sur la connexion indépendante.
     *
     * ⚠️ Ne propage JAMAIS d'exception et ne renvoie jamais d'erreur bloquante : un journal qui
     * ferait échouer l'opération qu'il observe serait pire que pas de journal du tout.
     *
     * @param  int         $entity     Entité
     * @param  string      $actionType Type d'action
     * @param  string      $result     Résultat
     * @param  string      $message    Message (tronqué)
     * @param  int|null    $fkStore    Boutique
     * @param  int|null    $durationMs Durée en millisecondes
     * @return bool
     */
    private static function write($entity, $actionType, $result, $message, $fkStore = null, $durationMs = null)
    {
        try {
            $journalDb = self::journalDb();
            if (empty($journalDb)) {
                return false;
            }

            $entity = (int) $entity;
            $message = dol_trunc((string) $message, 500, 'right', 'UTF-8', 1);

            $columns = ['date_action', 'action_type', 'result', 'message', 'entity'];
            $values = [
                "'" . $journalDb->idate(dol_now()) . "'",
                "'" . $journalDb->escape($actionType) . "'",
                "'" . $journalDb->escape($result) . "'",
                "'" . $journalDb->escape($message) . "'",
                (string) $entity,
            ];

            if (self::$currentRunId !== null) {
                $columns[] = 'run_id';
                $values[] = "'" . $journalDb->escape(self::$currentRunId) . "'";
            }
            if (self::$currentOrigin !== null) {
                $columns[] = 'origin';
                $values[] = "'" . $journalDb->escape(self::$currentOrigin) . "'";
            }
            if ($fkStore !== null) {
                $columns[] = 'fk_store';
                $values[] = (string) ((int) $fkStore);
            }
            if ($durationMs !== null) {
                $columns[] = 'duration_ms';
                $values[] = (string) ((int) $durationMs);
            }

            $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'doli2shop_action_log ('
                . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

            // begin/commit PROPRES à cette connexion : c'est ce qui rend la trace insensible au
            // rollback de la transaction métier.
            $journalDb->begin();
            $resql = $journalDb->query($sql);
            if ($resql) {
                $journalDb->commit();
                return true;
            }
            $journalDb->rollback();
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}
