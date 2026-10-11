<?php
/**
 * @file        class/stockpushqueueservice.class.php
 * @brief       File d'attente pour le push stock Dolibarr -> Shopify ASYNCHRONE (Story 52-4)
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.1
 * @since       2.4.5
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/actionlogger.class.php';
require_once dirname(__FILE__) . '/shopifystocktrigger.class.php';

/**
 * Class StockPushQueueService
 *
 * Découple le push stock Dolibarr → Shopify de la requête de validation (Story 52-4, follow-up
 * de la Story 52-1 dont le push était branché en SYNCHRONE sur le trigger STOCK_MOVEMENT).
 *
 * Deux responsabilités strictement séparées :
 *   - enqueue() : appelée par ShopifyStockTrigger::runTrigger() DANS la requête de validation.
 *     AUCUN appel réseau Shopify, AUCUNE instanciation ShopifyApi/StoreService — un simple
 *     UPSERT SQL léger (marqueur + timestamp, JAMAIS la valeur de stock : le worker relit
 *     toujours la valeur courante en base au moment du drainage, cf. Résultat Validate point 4).
 *   - drainQueue() : appelée par StockPushQueueCron (CRON dédié, 5 min), HORS de toute
 *     transaction métier Dolibarr. Claim atomique (pending → processing, pattern
 *     WebhookManager::processEvent()), retry borné + dead-letter (pattern
 *     DOLI2SHOP_WEBHOOK_MAX_TRIES), puis délègue le push réel à
 *     ShopifyStockTrigger::updateShopifyStockBatch() (Story 66-1, 2.6.1 — le cas n=1 est
 *     updateShopifyStock()), seule primitive qui gère le multi-boutiques (Epic 47), le plancher 0,
 *     la résolution d'inventaire et la LECTURE de la quantité Shopify à la localisation visée.
 *
 * Design : une ligne de file = un (fk_product, entity), JAMAIS une (fk_product, fk_store) — le
 * drainage appelle updateShopifyStockBatch() qui fait lui-même le fan-out vers TOUTES les
 * boutiques mappées de l'entité. Ceci évite toute duplication de la logique multi-boutiques dans
 * cette classe.
 */
class StockPushQueueService
{
    /**
     * Items réclamés puis poussés ensemble (Story 66-1, review HIGH-1). Doit rester petit devant le
     * délai de reprise des items bloqués (DOLI2SHOP_STOCK_PUSH_QUEUE_STUCK_TIMEOUT_MIN, 5 min).
     */
    const DRAIN_SUBLOT_SIZE = 25;

    /** Budget de temps d'un drainage, strictement sous le délai de reprise des items bloqués (300 s). */
    const DRAIN_TIME_BUDGET_SECONDS = 180;

    /**
     * Âge maximal (secondes) pendant lequel un item peut être réessayé SANS consommer d'essai
     * (incident transitoire, erreur de configuration de boutique). Au-delà, chaque tentative consomme un
     * essai et l'item finit en dead-letter. Re-review 66-1 (HIGH-A, MEDIUM-1) : sans cette borne, un
     * échec que l'on croit passager mais qui est permanent boucle sans fin, un appel API par cycle.
     * Aucune colonne n'existe pour dater le début de la période d'échec (`date_creation` = tout premier
     * enfilage du produit, `tms` est réécrit à chaque claim) : la date de début est portée par un marqueur
     * en tête de `last_error` (« [depuis AAAA-MM-JJ hh:mm:ss] »), sans nouvelle colonne ni migration.
     */
    const FREE_RETRY_MAX_AGE_SECONDS = 86400;

    use LoggerTrait;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var int Entité Dolibarr courante */
    private $entity;

    /**
     * @param DoliDB   $db     Gestionnaire base de données
     * @param int|null $entity Entité Dolibarr (null = entité courante $conf->entity)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
    }

    /**
     * Enfile un marqueur "stock à pousser" pour un produit (appelée DANS la requête de
     * validation, DOIT rester strictement non-bloquante : aucun appel réseau, aucune écriture
     * autre que ce simple UPSERT).
     *
     * Coalescence N→1 : plusieurs mouvements de stock du même produit avant le passage du worker
     * sont collabsés en une seule ligne (contrainte UNIQUE fk_product+entity), via
     * INSERT ... ON DUPLICATE KEY UPDATE. Réactive aussi un item terminé/dead-letter (status
     * remis à 0, compteur de tentatives remis à 0) : un NOUVEAU mouvement métier mérite un
     * nouveau cycle de tentatives, indépendant de l'historique d'une file précédente.
     *
     * @param int $dolibarrProductId ID du produit Dolibarr concerné par le mouvement de stock
     * @return bool true si enfilé avec succès, false sinon (jamais d'exception propagée)
     */
    public function enqueue($dolibarrProductId)
    {
        $dolibarrProductId = (int) $dolibarrProductId;
        if ($dolibarrProductId <= 0) {
            $this->log('enqueue() - ID produit invalide, no-op', LOG_WARNING);
            return false;
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " (fk_product, entity, status, tries, date_creation, tms)";
        $sql .= " VALUES (" . $dolibarrProductId . ", " . (int) $this->entity . ", 0, 0, NOW(), NOW())";
        $sql .= " ON DUPLICATE KEY UPDATE";
        $sql .= " status = 0,";
        $sql .= " tries = 0,";
        // Re-review 66-1 (MEDIUM-1) : un réarmement ouvre une NOUVELLE période d'échec. Sans vider
        // last_error, le marqueur « [depuis …] » d'une ancienne panne rendrait l'item « trop vieux » et il
        // repartirait en dead-letter en 5 cycles sur une simple coupure.
        $sql .= " last_error = NULL,";
        $sql .= " tms = NOW()";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('enqueue() - Erreur enfilage produit ID ' . $dolibarrProductId . ': ' . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $this->log('enqueue() - Produit ID ' . $dolibarrProductId . ' enfilé (entity=' . $this->entity . ')', LOG_DEBUG);
        return true;
    }

    /**
     * Draine la file d'attente (appelée par le CRON, JAMAIS depuis une requête web) : récupère
     * un lot de marqueurs pending, les claim atomiquement (pattern WebhookManager::processEvent(),
     * status 0→3), puis pousse le stock COURANT de chaque produit via
     * ShopifyStockTrigger::updateShopifyStockBatch() (relit la valeur en base à cet instant précis,
     * jamais un snapshot pris à l'enqueue).
     *
     * @param int $limit Nombre max de marqueurs traités par passage
     * @return array{claimed:int,pushed:int,errors:int,deadLetter:int,raced:int,recoalesced:int,skipped:bool,timeBudgetReached:bool,deferred:int}
     */
    public function drainQueue($limit = 100)
    {
        $limit = max(1, (int) $limit);

        $result = array(
            'claimed' => 0,
            'pushed' => 0,
            'errors' => 0,
            'deadLetter' => 0,
            'raced' => 0,
            'recoalesced' => 0,
            'skipped' => false,
            'timeBudgetReached' => false,
            'deferred' => 0,
        );

        // Re-vérification de la direction de sync AU DRAINAGE (pas seulement à l'enqueue) : un
        // admin qui désactive dolibarr_to_shopify pendant que la file contient des items ne doit
        // pas voir ces items poussés au cycle CRON suivant.
        try {
            $directionEnabled = $this->createSyncUtils()->isStockSyncToShopifyEnabled();
        } catch (\Throwable $e) {
            // Fail-open : une configuration illisible ne doit pas geler la file (le push reste
            // idempotent et garde ses propres protections en aval).
            $this->log('drainQueue() - Direction de sync non vérifiable (' . $e->getMessage() . '), drainage poursuivi', LOG_WARNING);
            $directionEnabled = true;
        }
        if (!$directionEnabled) {
            $this->log('drainQueue() - Synchronisation stock Dolibarr → Shopify désactivée, drainage ignoré', LOG_INFO);
            $result['skipped'] = true;
            return $result;
        }

        $this->recoverStuckItems();

        $maxTries = getDolGlobalInt('DOLI2SHOP_STOCK_PUSH_QUEUE_MAX_TRIES', 5);
        if ($maxTries < 1) {
            $maxTries = 5;
        }

        $sql = "SELECT rowid, fk_product, tries, last_error FROM " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " WHERE status = 0"; // pending
        $sql .= " AND tries < " . (int) $maxTries;
        $sql .= " AND entity = " . (int) $this->entity;
        $sql .= " ORDER BY tms ASC";
        $sql .= " " . $this->db->plimit((int) $limit, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('drainQueue() - Erreur requête sélection: ' . $this->db->lasterror(), LOG_ERR);
            return $result;
        }

        $items = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $items[] = array(
                'rowid' => (int) $obj->rowid,
                'fk_product' => (int) $obj->fk_product,
                'tries' => (int) $obj->tries,
                'last_error' => isset($obj->last_error) ? (string) $obj->last_error : '',
            );
        }
        $this->db->free($resql);

        // Story 66-1 (2.6.1) : claim atomique de CHAQUE item (inchangé, un par un), puis UN appel de push
        // par SOUS-LOT réclamé — le trigger regroupe par (boutique, emplacement) : une lecture et une
        // écriture par boutique et par lot au lieu de deux appels par produit. Statut terminal par rowid
        // (markDone / markRetry / markDeadLetter, gardés `status = 3`) INCHANGÉ.
        //
        // Review 66-1 (HIGH-1) : on ne réclame JAMAIS plus d'items qu'on ne peut traiter avant que
        // recoverStuckItems() (5 min par défaut) ne les juge abandonnés. Réclamer les 100 d'un coup puis
        // pousser en série (throttle Shopify, rejeux) laissait les derniers items en status 3 assez
        // longtemps pour être remis en pending par un second drainage → double push, et markDone du
        // second worker sur le claim du premier. Donc : sous-lots de DRAIN_SUBLOT_SIZE (claim → push →
        // statut terminal, puis sous-lot suivant) ET budget de temps : au-delà de
        // DRAIN_TIME_BUDGET_SECONDS le drainage s'arrête, les items non réclamés restent pending et
        // repartent au cycle suivant.
        $startedAt = $this->monotonicNow();
        foreach (array_chunk($items, self::DRAIN_SUBLOT_SIZE) as $sublot) {
            if ($this->monotonicNow() - $startedAt > self::DRAIN_TIME_BUDGET_SECONDS) {
                $result['timeBudgetReached'] = true;
                $this->log('drainQueue() - Budget de temps (' . self::DRAIN_TIME_BUDGET_SECONDS . ' s) atteint : les items restants repartent au prochain cycle', LOG_WARNING);
                break;
            }

            $claimed = array();
            foreach ($sublot as $item) {
                if ($this->claimItem($item, $result)) {
                    $claimed[] = $item;
                }
            }

            if (empty($claimed)) {
                continue;
            }

            $remaining = max(0.0, self::DRAIN_TIME_BUDGET_SECONDS - ($this->monotonicNow() - $startedAt));
            $outcomes = $this->pushClaimedItems($claimed, $remaining);
            foreach ($claimed as $item) {
                $this->finalizeItem($item, $maxTries, $outcomes[$item['fk_product']], $result);
            }
        }

        return $result;
    }

    /**
     * Horloge monotone du drainage (mockable en test pour simuler un drainage lent).
     *
     * @return float Secondes
     */
    protected function monotonicNow()
    {
        return microtime(true);
    }

    /**
     * Horodatage Unix courant (mockable en test : âge des échecs réessayés sans consommer d'essai).
     *
     * @return int
     */
    protected function nowTimestamp()
    {
        return time();
    }

    /**
     * Claim atomique d'un item (pattern WebhookManager::processEvent(), status 0→3) : empêche le
     * traitement concurrent par un autre worker (course CRON qui se chevauche).
     *
     * @param array $item   ['rowid'=>int,'fk_product'=>int,'tries'=>int]
     * @param array $result [IN/OUT] compteurs cumulés
     * @return bool true si CE worker a réclamé l'item
     */
    private function claimItem(array $item, array &$result)
    {
        $rowid = $item['rowid'];

        $sqlClaim = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        // tms explicitement repositionné (et pas seulement via ON UPDATE CURRENT_TIMESTAMP) :
        // recoverStuckItems() mesure l'ancienneté du claim sur cette colonne, cette détection ne
        // doit dépendre d'aucun comportement implicite du moteur SQL.
        $sqlClaim .= " SET status = 3, tms = NOW()"; // processing
        $sqlClaim .= " WHERE rowid = " . (int) $rowid;
        $sqlClaim .= " AND status = 0";
        $resClaim = $this->db->query($sqlClaim);
        if (!$resClaim || $this->db->affected_rows($resClaim) == 0) {
            $this->log('drainOneItem() - Item rowid=' . $rowid . ' déjà claim par un autre worker, skip', LOG_DEBUG);
            $result['raced']++;
            return false;
        }

        $result['claimed']++;
        return true;
    }

    /**
     * Pousse le stock COURANT des produits réclamés via ShopifyStockTrigger::updateShopifyStockBatch()
     * (un seul chemin de code avec le recalage et l'API). Jamais d'exception : en cas d'exception
     * imprévue du lot entier, chaque item est marqué en échec avec le message.
     *
     * @param array $claimed Items réclamés
     * @param float|null $remainingSeconds Budget de temps restant du drainage : les lots non traités à l'expiration
     *                          reviennent en `deferred` et repassent en pending SANS consommer d'essai
     * @return array<int,array{code:int,stock:int|null,errors:string[]}> Résultat par fk_product
     */
    private function pushClaimedItems(array $claimed, $remainingSeconds = null)
    {
        $productIds = array();
        foreach ($claimed as $item) {
            $productIds[] = $item['fk_product'];
        }

        try {
            $trigger = $this->createStockTrigger();
            // bypassApiResolutionCap=true : un item de file mérite une résolution complète (même
            // rationale que StockRecalageService::processBatch(), pas un skip silencieux sur
            // plafond partagé par requête HTTP — le worker CRON est un contexte dédié borné par
            // $limit, pas une requête web à N lignes concurrentes).
            $outcomes = $trigger->updateShopifyStockBatch($productIds, true, $remainingSeconds);
        } catch (\Throwable $e) {
            $this->log('drainQueue() - Exception lors du push du lot (' . count($productIds) . ' produit(s)): ' . $e->getMessage(), LOG_ERR);
            $outcomes = array();
            foreach ($productIds as $productId) {
                $outcomes[$productId] = array('code' => -1, 'stock' => null, 'errors' => array('Exception: ' . $e->getMessage()));
            }
        }

        foreach ($productIds as $productId) {
            if (!isset($outcomes[$productId])) {
                $outcomes[$productId] = array('code' => -1, 'stock' => null, 'errors' => array('résultat de push absent pour ce produit'));
            }
        }

        return $outcomes;
    }

    /**
     * Mise à jour du statut d'un item après son push : done / pending pour retry / dead_letter.
     *
     * @param array $item     ['rowid'=>int,'fk_product'=>int,'tries'=>int]
     * @param int   $maxTries Borne de tentatives avant dead-letter
     * @param array $outcome  Résultat du produit (code, stock, errors)
     * @param array $result   [IN/OUT] compteurs cumulés
     * @return void
     */
    private function finalizeItem(array $item, $maxTries, array $outcome, array &$result)
    {
        $rowid = $item['rowid'];
        $dolibarrProductId = $item['fk_product'];

        // Re-review 66-1 (MEDIUM-2) : lot non traité faute de temps -> retour en pending, AUCUN essai consommé,
        // last_error inchangé. Le prochain cycle le reprend.
        if (!empty($outcome['deferred'])) {
            $marked = $this->markTerminalStatus($rowid, "status = 0", 'StockPushQueueService::markDeferred');
            $result['deferred']++;
            $result['timeBudgetReached'] = true;
            if (!$marked) {
                $result['recoalesced']++;
            }
            return;
        }

        $success = ($outcome['code'] === 0 && empty($outcome['errors']));
        $errorMessage = null;
        if (!$success) {
            $errorMessage = !empty($outcome['errors']) ? implode('; ', $outcome['errors']) : 'erreur non détaillée';
        }

        if ($success) {
            $marked = $this->markDone($rowid);
            $result['pushed']++;
            if (!$marked) {
                $result['recoalesced']++;
            }
            $this->logAction(ActionLogger::RESULT_SUCCESS, $dolibarrProductId, 'Push stock asynchrone OK (produit ID ' . $dolibarrProductId . ')');
            if (!empty($outcome['warnings'])) {
                // Une boutique a réussi, une autre est mal configurée : jamais silencieux.
                $this->logAction(ActionLogger::RESULT_ERROR, $dolibarrProductId, 'Push stock OK sur au moins une boutique, configuration à corriger sur une autre : ' . implode('; ', $outcome['warnings']));
            }
            return;
        }

        // Review 66-1 (MEDIUM-b/c) : un incident TRANSITOIRE (Shopify throttlé, 5xx, réseau) ou une erreur
        // de CONFIGURATION de boutique (emplacement absent/désactivé) ne consomme PAS les essais : une
        // panne Shopify de 25 minutes ne doit pas envoyer toute la file en dead-letter (backoff complet :
        // hors périmètre). L'item reste pending avec son last_error explicite.
        //
        // BORNE D'ÂGE (re-review 66-1, HIGH-A / MEDIUM-1) : ce répit est limité à FREE_RETRY_MAX_AGE_SECONDS
        // depuis le début de la période d'échec (marqueur en tête de last_error). Passé ce délai, chaque
        // tentative consomme un essai -> dead-letter avec le dernier message explicite. Le marqueur
        // d'origine est CONSERVÉ pour que la bascule soit définitive.
        $since = $this->parseFailureEpisodeStart(isset($item['last_error']) ? $item['last_error'] : '');
        $freeRetry = isset($outcome['consumeTry']) && $outcome['consumeTry'] === false;
        if ($freeRetry) {
            $now = $this->nowTimestamp();
            if ($since === null) {
                $since = $now;
            }
            $errorMessage = '[depuis ' . date('Y-m-d H:i:s', $since) . '] ' . $errorMessage;
            if (($now - $since) <= self::FREE_RETRY_MAX_AGE_SECONDS) {
                $marked = $this->markRetry($rowid, $item['tries'], $errorMessage);
                $result['errors']++;
                if (!$marked) {
                    $result['recoalesced']++;
                }
                $this->logAction(ActionLogger::RESULT_ERROR, $dolibarrProductId, 'Push stock asynchrone échoué sans consommer d\'essai (incident transitoire ou configuration de boutique) : ' . $errorMessage);
                return;
            }
            $errorMessage .= ' (échec persistant depuis plus de ' . (int) (self::FREE_RETRY_MAX_AGE_SECONDS / 3600) . ' h : les essais sont désormais consommés)';
        }

        $newTries = $item['tries'] + 1;
        if ($newTries >= $maxTries) {
            $marked = $this->markDeadLetter($rowid, $newTries, $errorMessage);
            $result['deadLetter']++;
            if (!$marked) {
                $result['recoalesced']++;
            }
            $this->logAction(ActionLogger::RESULT_ERROR, $dolibarrProductId, 'Push stock asynchrone en dead-letter après ' . $newTries . ' tentative(s) : ' . $errorMessage);
        } else {
            $marked = $this->markRetry($rowid, $newTries, $errorMessage);
            $result['errors']++;
            if (!$marked) {
                $result['recoalesced']++;
            }
            $this->logAction(ActionLogger::RESULT_ERROR, $dolibarrProductId, 'Push stock asynchrone échoué (tentative ' . $newTries . '/' . $maxTries . ') : ' . $errorMessage);
        }
    }

    /**
     * Date de début de la période d'échec portée par le marqueur « [depuis AAAA-MM-JJ hh:mm:ss] » en tête
     * de last_error.
     *
     * @param string $lastError last_error de l'item
     * @return int|null Horodatage Unix, ou null s'il n'y a pas de marqueur
     */
    private function parseFailureEpisodeStart($lastError)
    {
        if (preg_match('/^\[depuis (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', (string) $lastError, $matches)) {
            $timestamp = strtotime($matches[1]);
            return $timestamp === false ? null : $timestamp;
        }
        return null;
    }

    /**
     * Écrit le statut terminal d'un item SANS JAMAIS écraser un ré-enfilage survenu pendant le
     * traitement.
     *
     * Garde `AND status = 3` indispensable : `enqueue()` (UPSERT sur la clé UNIQUE
     * (fk_product, entity)) repasse la ligne à `status = 0` dès qu'un NOUVEAU mouvement de stock
     * touche le produit, même en plein drainage. Sans cette garde, le `markDone()` final écrasait
     * ce marqueur frais avec `status = 1` : le mouvement concurrent n'était jamais poussé (une
     * seule ligne par produit) et Shopify restait durablement désynchronisé, en silence.
     * `affected_rows == 0` signifie donc « ré-enfilé pendant le traitement » : on laisse la ligne
     * en pending, le prochain passage repoussera la valeur courante (le push est idempotent,
     * valeur absolue).
     *
     * @param int    $rowid   Rowid de l'item
     * @param string $setters Fragment SET de la requête (hors garde de statut)
     * @param string $context Contexte de log
     * @return bool true si l'item a bien été marqué, false s'il a été ré-enfilé entre-temps
     */
    private function markTerminalStatus($rowid, $setters, $context)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " SET " . $setters;
        $sql .= " WHERE rowid = " . (int) $rowid;
        $sql .= " AND status = 3"; // uniquement l'item que CE worker a claim

        $resql = $this->db->query($sql);
        if (!$resql) {
            // Un échec de mise à jour de statut ne doit jamais interrompre le drainage des items
            // suivants : l'item restera en processing et sera récupéré par recoverStuckItems().
            $this->log($context . ' - Erreur mise à jour statut item rowid=' . $rowid . ': ' . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        if ($this->db->affected_rows($resql) == 0) {
            $this->log($context . ' - Item rowid=' . $rowid . ' ré-enfilé pendant le traitement (nouveau mouvement de stock), laissé en pending pour un push de la valeur courante', LOG_INFO);
            return false;
        }

        return true;
    }

    /**
     * Récupère les items "stuck" en status=3 (processing) depuis plus de N minutes — traitement
     * inline/CRON précédent qui a crashé ou timeout (pattern WebhookManager::processPendingEvents()).
     *
     * @return void
     */
    private function recoverStuckItems()
    {
        $stuckTimeout = getDolGlobalInt('DOLI2SHOP_STOCK_PUSH_QUEUE_STUCK_TIMEOUT_MIN', 5);
        if ($stuckTimeout < 1) {
            $stuckTimeout = 5;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " SET status = 0"; // remettre en pending pour retry
        $sql .= " WHERE status = 3";
        $sql .= " AND TIMESTAMPDIFF(MINUTE, tms, NOW()) > " . (int) $stuckTimeout;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            $resetCount = $this->db->affected_rows($resql);
            if ($resetCount > 0) {
                $this->log('recoverStuckItems() - ' . $resetCount . ' item(s) stuck remis en pending (entity=' . $this->entity . ')', LOG_WARNING);
            }
        }
    }

    /**
     * Marque un item comme traité avec succès (status=1).
     *
     * @param int $rowid Rowid de l'item
     * @return bool false si l'item a été ré-enfilé pendant le traitement (laissé en pending)
     */
    private function markDone($rowid)
    {
        // last_error = NULL : un succès clôt la période d'échec (le marqueur « depuis » d'un épisode
        // précédent ne doit pas faire croire à un échec ancien lors du prochain).
        return $this->markTerminalStatus(
            $rowid,
            "status = 1, date_traitement = NOW(), tries = tries + 1, last_error = NULL",
            'StockPushQueueService::markDone'
        );
    }

    /**
     * Remet un item en pending pour une prochaine tentative (borne non atteinte).
     *
     * @param int         $rowid Rowid de l'item
     * @param int         $tries Nouveau compteur de tentatives
     * @param string|null $error Message d'erreur
     * @return bool false si l'item a été ré-enfilé pendant le traitement (compteur laissé à zéro)
     */
    private function markRetry($rowid, $tries, $error)
    {
        $setters = "status = 0, tries = " . (int) $tries . ", date_traitement = NOW()";
        if ($error !== null) {
            $setters .= ", last_error = '" . $this->db->escape($error) . "'";
        }
        return $this->markTerminalStatus($rowid, $setters, 'StockPushQueueService::markRetry');
    }

    /**
     * Marque un item en dead-letter (status=2, borne de tentatives atteinte) : n'est plus
     * repris par le drainage (filtre tries < maxTries), jamais bloquant.
     *
     * @param int         $rowid Rowid de l'item
     * @param int         $tries Nouveau compteur de tentatives
     * @param string|null $error Message d'erreur
     * @return bool false si l'item a été ré-enfilé pendant le traitement (nouveau cycle accordé)
     */
    private function markDeadLetter($rowid, $tries, $error)
    {
        $setters = "status = 2, tries = " . (int) $tries . ", date_traitement = NOW()";
        if ($error !== null) {
            $setters .= ", last_error = '" . $this->db->escape($error) . "'";
        }
        return $this->markTerminalStatus($rowid, $setters, 'StockPushQueueService::markDeadLetter');
    }

    /**
     * Journalise le résultat d'un drainage (ActionLogger — non-bloquant par construction).
     *
     * @param string $result
     * @param int    $dolibarrProductId
     * @param string $message
     * @return void
     */
    private function logAction($result, $dolibarrProductId, $message)
    {
        ActionLogger::log($this->db, $this->entity, ActionLogger::TYPE_STOCK_PUSH_QUEUE, 'product', $dolibarrProductId, $result, $message);
    }

    // =========================================================================
    // Story 58-1 : observabilité de la file — compteurs + relance dead-letter
    //
    // Appelées EXCLUSIVEMENT depuis l'admin (admin/sync_products.php) : aucune de ces
    // méthodes n'est invoquée depuis ShopifyStockTrigger::runTrigger() ni enqueue()/
    // drainQueue() (Résultat Validate point 9, invariant AC4 : zéro travail
    // supplémentaire dans la transaction métier de validation de facture).
    //
    // Piège cross-entity (régression classe 54-2, Résultat Validate point 7) : ces
    // méthodes ne renvoient JAMAIS de libellé produit — uniquement rowid/fk_product/
    // tries/last_error/date_traitement. La résolution du libellé (jointure llx_product
    // filtrée par entity) se fait dans la page admin, jamais ici.
    // =========================================================================

    /**
     * Compte les items de la file par statut pour l'entité courante (AC1).
     *
     * Simple COUNT(*) groupé par statut — invariant AC4 : jamais appelée depuis le
     * chemin critique (trigger/enqueue), uniquement depuis l'admin.
     *
     * @return array{pending:int,processing:int,dead_letter:int}
     * @since 2.5.0
     */
    public function getQueueCounts()
    {
        $counts = array('pending' => 0, 'processing' => 0, 'dead_letter' => 0);
        // Mapping colonne status -> clé exposée (cf. commentaire llx_doli2shop_stock_queue.sql :
        // 0=pending, 1=done, 2=dead_letter, 3=processing). Le statut "done" (1) n'est
        // volontairement pas exposé : hors périmètre observabilité (AC1).
        $statusMap = array(0 => 'pending', 3 => 'processing', 2 => 'dead_letter');

        $sql = "SELECT status, COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " WHERE entity = " . (int) $this->entity;
        $sql .= " GROUP BY status";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('getQueueCounts() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return $counts;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $statusKey = (int) $obj->status;
            if (isset($statusMap[$statusKey])) {
                $counts[$statusMap[$statusKey]] = (int) $obj->nb;
            }
        }
        $this->db->free($resql);

        return $counts;
    }

    /**
     * Compte les items pending dont le dernier enfilage (tms) date de plus de
     * $thresholdMinutes — signal d'une file qui grossit plus vite qu'elle n'est
     * drainée (AC2). Mesuré sur `tms` (pilote déjà le FIFO de drainQueue() et reste
     * correctement daté après recoverStuckItems(), Résultat Validate point 8).
     *
     * @param int $thresholdMinutes Seuil en minutes (garde < 1 -> défaut 30, cohérent
     *                              avec DOLI2SHOP_STOCK_PUSH_QUEUE_ALERT_THRESHOLD_MIN)
     * @return int Nombre d'items pending en retard
     * @since 2.5.0
     */
    public function countStalePending($thresholdMinutes = 30)
    {
        $thresholdMinutes = (int) $thresholdMinutes;
        if ($thresholdMinutes < 1) {
            $thresholdMinutes = 30;
        }

        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " WHERE status = 0"; // pending
        $sql .= " AND TIMESTAMPDIFF(MINUTE, tms, NOW()) > " . $thresholdMinutes;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('countStalePending() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->nb : 0;
    }

    /**
     * Liste les items en dead-letter (status=2) pour l'entité courante, les plus
     * récemment traités en premier (AC3). Ne renvoie AUCUN libellé produit — la
     * résolution se fait dans la page admin (cf. commentaire de section, piège
     * cross-entity 54-2).
     *
     * @param int $limit Nombre max d'items retournés
     * @return array<int, array{rowid:int,fk_product:int,tries:int,last_error:?string,date_traitement:?string}>
     * @since 2.5.0
     */
    public function getDeadLetterItems($limit = 50)
    {
        $limit = max(1, (int) $limit);
        $items = array();

        $sql = "SELECT rowid, fk_product, tries, last_error, date_traitement";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        $sql .= " WHERE status = 2"; // dead_letter
        $sql .= " AND entity = " . (int) $this->entity;
        $sql .= " ORDER BY date_traitement DESC";
        $sql .= " " . $this->db->plimit($limit, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('getDeadLetterItems() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return $items;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $items[] = array(
                'rowid' => (int) $obj->rowid,
                'fk_product' => (int) $obj->fk_product,
                'tries' => (int) $obj->tries,
                'last_error' => $obj->last_error,
                'date_traitement' => $obj->date_traitement,
            );
        }
        $this->db->free($resql);

        return $items;
    }

    /**
     * Remet en attente un lot d'items dead-letter (status 2 -> 0, tries remis à
     * zéro) — action admin explicite (AC3).
     *
     * ⚠️ CRITICAL évité (Résultat Validate point 4) : contrairement à
     * WebhookManager::replayBatch() (webhookmanager.class.php:863-894), la garde
     * `AND status = 2` est OBLIGATOIRE en plus de `entity`. Sans elle, un item déjà
     * repris entre-temps par un enqueue()/drainage concurrent (ex. remis en pending
     * ou reclaim processing) serait écrasé par cette relance — rouvrant la classe de
     * bug corrigée en CRITICAL sur la story 52-4 (markTerminalStatus()). Les items
     * sortis du dead-letter entre l'affichage de la liste et le clic sont donc
     * ignorés silencieusement (affected_rows reflète uniquement ceux réellement
     * relancés).
     *
     * `last_error` est VIDÉ à la relance, comme à chaque réarmement par enqueue() (re-review 66-1,
     * MEDIUM-1) : il porte le marqueur de début de période d'échec, qui ne doit pas survivre à un
     * réarmement. (Auparavant conservé comme « dernière erreur connue », Résultat Validate point 6.)
     *
     * @param array $rowids Rowids à relancer (assainis : entiers positifs uniquement)
     * @return int Nombre d'items réellement relancés, -1 en cas d'erreur SQL
     * @since 2.5.0
     */
    public function requeueDeadLetter(array $rowids)
    {
        $safeIds = array_filter(array_map('intval', $rowids), function ($id) {
            return $id > 0;
        });
        if (empty($safeIds)) {
            return 0;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_stock_queue";
        // last_error = NULL : relance manuelle = nouvelle période d'échec (re-review 66-1, MEDIUM-1).
        $sql .= " SET status = 0, tries = 0, last_error = NULL, date_traitement = NOW()";
        $sql .= " WHERE rowid IN (" . implode(',', $safeIds) . ")";
        $sql .= " AND entity = " . (int) $this->entity;
        $sql .= " AND status = 2"; // uniquement les items encore en dead-letter (garde CRITICAL 52-4)

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('requeueDeadLetter() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($resql);
        $this->log('requeueDeadLetter() - ' . $affected . ' item(s) relancé(s) manuellement (entity=' . $this->entity . ')', LOG_INFO);

        if ($affected > 0) {
            // Résultat Validate point 5 : un seul log agrégé pour le lot, JAMAIS un log par
            // produit (object_type/object_id volontairement NULL — action non rattachée à un
            // produit unique).
            ActionLogger::log(
                $this->db,
                $this->entity,
                ActionLogger::TYPE_STOCK_PUSH_QUEUE,
                null,
                null,
                ActionLogger::RESULT_SUCCESS,
                'Relance manuelle dead-letter : ' . $affected . ' item(s)'
            );
        }

        return $affected;
    }

    // =========================================================================
    // Collaborateurs mockables (onlyMethods() — isolation des tests unitaires)
    // =========================================================================

    /**
     * @return ShopifyStockTrigger
     */
    protected function createStockTrigger()
    {
        return new ShopifyStockTrigger($this->db, $this->entity);
    }

    /**
     * @return SyncUtils
     */
    protected function createSyncUtils()
    {
        require_once dirname(__FILE__) . '/syncutils.class.php';
        return new SyncUtils($this->db, $this->entity);
    }
}
