<?php
/**
 * Shopify Stock Trigger Handler
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.1
 * @since       2.1.8
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
dol_include_once('/core/db/Database.interface.php');
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/inventoryreferenceresolver.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';
dol_include_once('/product/class/product.class.php');
// Story 52-4 : la file d'attente est chargée en LAZY (require dans createStockPushQueueService())
// et non ici — ShopifyStockTrigger étant instancié dans le chemin trigger synchrone
// (Facture::validate() → STOCK_MOVEMENT), tout chargement au niveau fichier reste anodin (aucun
// appel réseau à l'inclusion), mais on garde le require au plus près de l'usage pour la lisibilité
// du flux "enqueue only, no API call in request" (AC1/AC2).

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Class ShopifyStockTrigger
 * Gère la synchronisation des stocks de Dolibarr vers Shopify (Story 52-1, puis ASYNCHRONE
 * depuis la Story 52-4).
 *
 * Flux ACTUEL (52-4) : STOCK_MOVEMENT Dolibarr → runTrigger() → enqueueStockPush() — enfile un
 * marqueur (StockPushQueueService::enqueue()) et retourne IMMÉDIATEMENT, SANS aucun appel réseau
 * Shopify ni instanciation ShopifyApi/StoreService dans la requête de validation (AC1/AC2, cf.
 * story 52-4 : élimine le risque de lock-wait InnoDB sur llx_doli2shop_stores en cas de refresh
 * de jeton OAuth pendant Facture::validate()).
 *
 * Le push réel vit dans updateShopifyStock() (méthode PUBLIQUE, signature inchangée) = cas n=1 de
 * updateShopifyStockBatch() (Story 66-1, 2.6.1) : pour chaque boutique mappée, LECTURE de la quantité
 * Shopify à la localisation visée (InventoryReferenceResolver) puis écriture du delta
 * `inventoryAdjustQuantities` via ShopifyApi::inventorySetQuantities() (plancher 0). Elle est appelée
 * DEUX façons :
 *   - HORS transaction, par StockPushQueueCron (5 min) via StockPushQueueService::drainQueue() —
 *     chemin ASYNCHRONE normal (STOCK_MOVEMENT).
 *   - Par StockRecalageService::processBatch() — action admin explicite de recalage en masse
 *     (Story 52-2), toujours synchrone et volontaire (hors flux trigger).
 */
class ShopifyStockTrigger
{
    use LoggerTrait;

    /** @var DoliDb Database handler */
    private $db;

    /** @var ShopifyApi API handler (chemin legacy entité/constantes, boutique nulle) */
    private $api;

    /** @var int Entité Dolibarr courante */
    private $entity;

    /** @var array Error messages */
    public $errors = array();

    /**
     * Compteur d'erreurs STRUCTURELLES d'emplacement rencontrées par la lecture de référence
     * (ShopifyLocationErrorException) — exigé par InventoryReferenceResolver::resolve(), même rôle
     * que ImportProducts::$stockSyncLocationErrorCount.
     *
     * @var int
     */
    private $locationErrorCount = 0;

    /** @var array<int,array<int,array{kind:string,message:string}>> Erreurs en attente de consolidation, par produit (lot en cours) */
    private $batchPending = array();

    /** @var array<int,int> Nombre de boutiques confirmées par produit (lot en cours) */
    private $batchConfirmed = array();

    /** @var array<int,bool> Produits dont au moins un lot n'a pas été traité faute de temps (lot en cours) */
    private $batchDeferred = array();

    /** @var float Horloge monotone au début du lot en cours */
    private $batchStartedAt = 0.0;

    /** @var float|null Budget de temps du lot en cours (null = illimité) */
    private $batchMaxSeconds = null;

    /**
     * Taille max des registres statiques process-scoped (Story 52-1 review — MEDIUM).
     * Borne défensive pour les contextes CLI/CRON long-vivants (worker persistant traitant
     * un très grand nombre de produits/boutiques distincts) : au-delà, le registre concerné
     * est purgé (perte de l'optimisation de dédup/cache uniquement, jamais de correction
     * fonctionnelle perdue — le prochain push re-résout/re-pousse normalement).
     */
    const MAX_REGISTRY_SIZE = 5000;

    /**
     * Plafond de résolutions API Shopify (fallback `resolveInventoryItemIdViaApi()`) par requête
     * HTTP/process (Story 52-1 review passe 2, HIGH-1). `llx_doli2shop_inventory` étant vide en
     * prod (aucun writer avant cette correction), CHAQUE ligne de mouvement de stock retombait
     * systématiquement sur le fallback API (1 à 6 appels GraphQL synchrones bloquants) DANS la
     * transaction de validation d'expédition Dolibarr : une expédition à N lignes = risque de
     * timeout proportionnel à N. Au-delà de ce plafond, le produit est skippé (jamais d'erreur
     * bloquante) — rattrapage attendu par le CRON de filet (Story 52-2). Le write-through
     * (`persistResolvedInventoryItemId()`) réduit ce risque à terme : un produit résolu une fois
     * via l'API est ensuite lu directement en base (0 appel API) pour tous les mouvements suivants.
     */
    const MAX_API_RESOLUTIONS_PER_REQUEST = 20;

    /**
     * Plafond d'articles par appel d'écriture `inventorySetQuantities` (Story 66-1, décision
     * mainteneur du 05/10/2026 : 100 éléments par mutation, = DOLI2SHOP_STOCK_PUSH_QUEUE_LIMIT par
     * défaut). La limite exacte de `changes` par appel pour l'API 2026-04 est à vérifier dans la
     * documentation Admin API ; 100 est retenu par prudence. Le plafond de 250 de `nodes(ids:)`
     * (lecture) est, lui, déjà géré par ShopifyApi::getInventoryQuantitiesAtLocation() (chunks de 200).
     */
    const MAX_ITEMS_PER_MUTATION = 100;

    /**
     * Compteur statique process-scoped du nombre de résolutions API RÉELLEMENT tentées
     * (`resolveInventoryItemIdViaApi()` invoquée) durant la requête HTTP/process courante
     * (Story 52-1 review passe 2, HIGH-1). Incrémenté uniquement quand la source DB
     * (`getShopifyInventoryId()`) n'a rien retourné ET que le plafond n'est pas encore atteint.
     * Remis à zéro par `resetPushRegistry()`.
     *
     * @var int
     */
    private static $apiResolutionsThisRequest = 0;

    /**
     * Registre statique de consolidation par requête (Story 52-1, Task 4).
     *
     * Clé "fk_product:fk_store" → dernière valeur de stock RÉELLEMENT poussée avec succès.
     * On pousse à chaque mouvement (inventorySetQuantities est absolu/idempotent → la dernière
     * valeur écrase les précédentes, convergence garantie). Un push n'est supprimé QUE s'il est
     * strictement redondant (même couple + même valeur qu'un push précédent réussi). JAMAIS de
     * dédup « premier arrivé » : une valeur différente est toujours poussée (AC #5). Vit le temps
     * du process PHP (une requête HTTP) — pas de faux négatif inter-requêtes. Bornée à
     * MAX_REGISTRY_SIZE (voir confirmEntry()).
     *
     * @var array<string,int>
     */
    private static $lastPushed = array();

    /**
     * Cache statique process-scoped du résultat de la gate licence par boutique (Story 52-1
     * review — HIGH-1). doli2shopStoreSyncAllowed() peut déclencher un curl bloquant (timeout
     * 10s) pour les boutiques secondaires quand le cache DB 5 min a expiré ; ce cache évite de
     * ré-invoquer la gate (et donc un éventuel curl redondant) pour CHAQUE ligne de mouvement
     * de stock d'une même requête (ex. expédition à N lignes). Clé = storeId (0 = legacy/null).
     * Bornée à MAX_REGISTRY_SIZE (voir isStoreSyncAllowed()).
     *
     * @var array<int,array>
     */
    private static $licenseGateCache = array();

    /**
     * Constructor
     *
     * @param DoliDb   $db     Database handler
     * @param int|null $entity Entité Dolibarr (null = $conf->entity)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
        $this->api = new ShopifyApi($db, $this->entity);
        // LoggerTrait::log() utilise get_class($this) automatiquement
    }

    /**
     * Factory protégée SyncFlowPolicy (Story 53-3, site 5 — remplace la propriété $syncUtils,
     * pattern createSyncUtils() de api_doli2shop.class.php:335).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db, $this->entity);
    }

    /**
     * Réinitialise les registres statiques process-scoped (usage tests, et CLI/CRON long-vivants
     * si un appelant veut forcer un reset explicite entre deux lots).
     *
     * @return void
     */
    public static function resetPushRegistry()
    {
        self::$lastPushed = array();
        self::$licenseGateCache = array();
        self::$apiResolutionsThisRequest = 0;
    }

    /**
     * Gestionnaire de trigger pour les mouvements de stock Dolibarr
     *
     * Story 52-4 : n'effectue plus AUCUN push Shopify en synchrone. Enfile un marqueur
     * (enqueueStockPush()) et retourne immédiatement — le push réel est délégué au CRON
     * StockPushQueueCron (asynchrone, hors transaction métier).
     *
     * @param string    $action Type d'action (STOCK_MOVEMENT)
     * @param object    $object Objet concerné par l'action (instance MouvementStock)
     * @param User      $user   Utilisateur déclenchant l'action
     * @param Conf      $conf   Configuration Dolibarr (⚠ ordre $conf,$langs — cf. handleStockEvent)
     * @param Translate $langs  Traductions
     * @return int 0=OK (ne jamais bloquer Dolibarr), -1=KO logique interne
     */
    public function runTrigger($action, $object, $user, $conf, $langs)
    {
        // Vérifier si c'est un mouvement de stock
        if ($action != 'STOCK_MOVEMENT') {
            return 0; // Action non concernée
        }

        // Story 53-3 (site 5, gate routeur) : garde SyncFlowPolicy (flux stock, d2s, storeId=0).
        // prepareStockPush() revérifie la direction PAR BOUTIQUE plus bas (nouveau gate per-store).
        if (!$this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'dolibarr_to_shopify', 0)) {
            $this->log("Synchronisation des stocks Dolibarr → Shopify désactivée", LOG_INFO);
            return 0;
        }

        // Éviter les boucles infinies en vérifiant l'origine du mouvement
        if (isset($object->origin_type) && $object->origin_type == 'shopify_sync') {
            $this->log("Mouvement provenant de Shopify, pas de synchronisation pour éviter une boucle", LOG_INFO);
            return 0;
        }

        // Vérifier si un webhook ou une synchronisation est en cours de traitement
        // Flag PROCESSING_INVENTORY_WEBHOOK : posé par InventoryWebhookHandler (inbound → trigger → outbound)
        // Flag SYNC_IN_PROGRESS : pose par OrderSyncToShopify / CRONs import (webhooks, catchup, historical)
        if (getDolGlobalInt('SHOPIFY_INTEGRATION_PROCESSING_INVENTORY_WEBHOOK')
            || getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS')) {
            $this->log("Webhook ou synchronisation en cours, pas de sync stock pour éviter une boucle", LOG_INFO);
            return 0;
        }

        // Récupérer l'ID du produit concerné
        // Sur un STOCK_MOVEMENT, $object est l'instance MouvementStock : product_id est assigné
        // AVANT l'appel au trigger dans MouvementStock::_create() ; fk_product en secours.
        $dolibarrProductId = 0;
        if (!empty($object->product_id)) {
            $dolibarrProductId = (int) $object->product_id;
        } elseif (!empty($object->fk_product)) {
            $dolibarrProductId = (int) $object->fk_product;
        }
        if (empty($dolibarrProductId)) {
            $this->log("ID produit non trouvé dans le mouvement de stock", LOG_WARNING);
            return 0;
        }

        // Story 52-4 : enfiler un marqueur "stock à pousser" (AUCUN appel réseau Shopify ici).
        // Le push réel (updateShopifyStock, inchangée) est délégué au CRON asynchrone
        // StockPushQueueCron, hors de toute transaction métier Dolibarr.
        return $this->enqueueStockPush($dolibarrProductId);
    }

    /**
     * Enfile un marqueur de push stock pour le CRON asynchrone (Story 52-4, AC1/AC2).
     *
     * AUCUN appel réseau Shopify, AUCUNE instanciation ShopifyApi/StoreService : délègue à
     * StockPushQueueService::enqueue() (simple UPSERT SQL, marqueur + timestamp uniquement,
     * JAMAIS la valeur de stock — le worker relit toujours la valeur courante en base au moment
     * du drainage).
     *
     * @param int $dolibarrProductId ID du produit Dolibarr concerné par le mouvement de stock
     * @return int 0=OK (enfilé ou déjà en file), -1=échec d'enfilage (jamais bloquant pour Dolibarr)
     */
    protected function enqueueStockPush($dolibarrProductId)
    {
        $queued = $this->createStockPushQueueService()->enqueue($dolibarrProductId);
        if (!$queued) {
            $this->errors[] = 'Échec enfilage push stock asynchrone pour le produit ID ' . $dolibarrProductId;
            return -1;
        }
        return 0;
    }

    /**
     * Factory mockable (isolation des tests unitaires, pattern createStockTrigger() de
     * StockRecalageService) — charge la classe en lazy pour ne jamais peser sur le chemin
     * trigger si elle n'est pas utilisée (ex. direction désactivée, garde anti-boucle en amont).
     *
     * @return StockPushQueueService
     */
    protected function createStockPushQueueService()
    {
        require_once dirname(__FILE__) . '/stockpushqueueservice.class.php';
        return new StockPushQueueService($this->db, $this->entity);
    }

    /**
     * Mettre à jour le stock d'un produit dans Shopify pour toutes les boutiques mappées.
     *
     * Story 66-1 (2.6.1) : cette méthode (signature publique INCHANGÉE, y compris le paramètre OUT
     * `$pushedStockOut`) est désormais le cas n=1 de updateShopifyStockBatch() — UN SEUL chemin de
     * code pour le trigger, la file asynchrone, le recalage et `POST /stock/push`.
     *
     * Résout le stock Dolibarr (réel/virtuel, plancher 0), LIT la quantité Shopify à la localisation
     * visée de chaque boutique, puis écrit le delta `inventoryAdjustQuantities` (compare-and-swap
     * sur `available`). Jamais de `changeFromQuantity` inventé : si la lecture est impossible,
     * l'article n'est pas envoyé et le résultat est -1 (rejouable).
     *
     * @param int      $dolibarrProductId ID du produit Dolibarr
     * @param int|null $pushedStockOut    [OUT] Story 52-2 : reçoit la valeur de stock résolue et
     *                                    planchée (0 si non résolvable) — permet à un appelant
     *                                    batch (recalage) de journaliser la valeur poussée SANS
     *                                    dupliquer la logique de résolution. Inchangé si le produit
     *                                    n'a pas pu être chargé.
     * @param bool     $bypassApiResolutionCap Contourne le plafond de résolutions API par requête
     * @return int 0=OK (au moins pas d'erreur bloquante), -1=au moins une boutique en erreur
     */
    public function updateShopifyStock($dolibarrProductId, &$pushedStockOut = null, $bypassApiResolutionCap = false)
    {
        $dolibarrProductId = (int) $dolibarrProductId;
        $outcomes = $this->updateShopifyStockBatch(array($dolibarrProductId), $bypassApiResolutionCap);
        $outcome = isset($outcomes[$dolibarrProductId])
            ? $outcomes[$dolibarrProductId]
            : array('code' => -1, 'stock' => null, 'errors' => array());

        // Story 52-2 : expose la valeur planchée résolue pour observabilité côté appelant (batch
        // de recalage). Ne participe à AUCUNE décision de push (purement informatif).
        if ($outcome['stock'] !== null) {
            $pushedStockOut = $outcome['stock'];
        }

        return $outcome['code'];
    }

    /**
     * Pousse le stock Dolibarr de PLUSIEURS produits vers Shopify, regroupé par (boutique,
     * emplacement) : UN appel de lecture et UN appel d'écriture (par lots de
     * MAX_ITEMS_PER_MUTATION) par boutique, au lieu de deux appels par produit (Story 66-1, D4).
     *
     * Algorithme : (1) résolution du stock Dolibarr de chaque produit ; (2) par produit et par
     * boutique, les gates (licence, SyncFlowPolicy, API, article, emplacement, dédup) — inchangés ;
     * (3) par groupe (boutique, emplacement) : lecture de la quantité Shopify à l'emplacement visé
     * (InventoryReferenceResolver, la même primitive que l'import), puis écriture ; (4) si une
     * écriture groupée est refusée (compare-and-swap : un seul stock périmé fait refuser le lot
     * ENTIER), les articles du lot sont rejoués UN PAR UN après relecture — un article fautif
     * n'envoie jamais ses voisins en échec (D5).
     *
     * @param int[] $dolibarrProductIds        IDs de produits Dolibarr
     * @param bool  $bypassApiResolutionCap    Contourne le plafond de résolutions API par requête
     *                                         (contexte CRON / admin délibéré, cf. resolveInventoryItemId())
     * @param float|null $maxSeconds           Budget de temps du lot (re-review 66-1, MEDIUM-2) : au-delà, les
     *                                         lots restants ne sont PAS traités (résultat `deferred`, sans
     *                                         erreur ni push) pour que l'appelant les remette en attente
     * @return array<int,array{code:int,stock:int|null,errors:string[],consumeTry:bool,warnings:string[],deferred:bool}> Par produit : code 0 (OK ou skip
     *               non bloquant) / -1 (au moins une boutique en erreur), stock planché poussé (null si
     *               produit non chargeable), messages d'erreur FINAUX de ce produit
     * @since 2.6.1
     */
    public function updateShopifyStockBatch(array $dolibarrProductIds, $bypassApiResolutionCap = true, $maxSeconds = null)
    {
        $outcomes = array();
        $targets = array();
        $this->batchPending = array();
        $this->batchConfirmed = array();
        $this->batchDeferred = array();
        $this->batchStartedAt = $this->monotonicNow();
        $this->batchMaxSeconds = ($maxSeconds === null) ? null : (float) $maxSeconds;

        foreach ($dolibarrProductIds as $rawProductId) {
            $dolibarrProductId = (int) $rawProductId;
            if (isset($outcomes[$dolibarrProductId])) {
                continue;
            }
            $this->log("Mise à jour du stock Shopify pour le produit Dolibarr ID: " . $dolibarrProductId, LOG_INFO);
            $outcomes[$dolibarrProductId] = array('code' => 0, 'stock' => null, 'errors' => array(), 'consumeTry' => true, 'warnings' => array(), 'deferred' => false);

            // Résoudre le stock Dolibarr (réel/virtuel selon config) une seule fois par produit
            $errorsBefore = count($this->errors);
            $stock = $this->resolveStockValue($dolibarrProductId);
            if ($stock === null) {
                // Chargement produit impossible : erreur déjà loggée
                $outcomes[$dolibarrProductId]['code'] = -1;
                $outcomes[$dolibarrProductId]['errors'] = array_slice($this->errors, $errorsBefore);
                continue;
            }

            // Shopify n'accepte pas les stocks négatifs
            $safeStock = max(0, (int) $stock);
            if ($safeStock != $stock) {
                $this->log("Stock négatif (" . $stock . ") ajusté à 0 pour Shopify", LOG_WARNING);
            }

            $outcomes[$dolibarrProductId]['stock'] = $safeStock;
            $targets[$dolibarrProductId] = $safeStock;
        }

        if (empty($targets)) {
            return $outcomes;
        }

        // Liste des boutiques actives (multi-boutiques Epic 47). getActiveStores() n'existe pas
        // côté StoreService → getAll(true).
        $stores = $this->getActiveStores();

        if (empty($stores)) {
            // CRITICAL-2 (code review) : getActiveStores() vide peut signifier PLUSIEURS choses
            // très différentes qu'il faut distinguer avant de décider du chemin legacy :
            //   1) Aucune boutique n'a JAMAIS été déclarée (install mono-boutique pré-Epic-47) →
            //      chemin legacy entité/constantes légitime (store_id=0, pas de filtre fk_store,
            //      pas de gate licence — invariant rétrocompat).
            //   2) Des boutiques EXISTENT mais sont toutes désactivées → jamais de fallback : ce
            //      serait un bypass de la gate licence + un push non déterministe (credentials
            //      potentiellement d'une autre boutique).
            //   3) MEDIUM-1 (review passe 2) : le COMPTAGE lui-même peut échouer sur une erreur SQL
            //      indéterminée. `countDeclaredStores()` (→ StoreService::countAllOrNull()) retourne
            //      alors `null`, JAMAIS `0` — contrairement à l'ancienne implémentation basée sur
            //      `getAllStores()` (tableau vide = "0 boutique" OU "erreur SQL", ambigu) qui pouvait
            //      à tort emprunter le chemin legacy global sur une simple erreur de requête.
            // On tranche donc explicitement sur les 3 valeurs possibles : null (erreur) → skip,
            // >0 (déclarées mais inactives) → skip, ===0 (jamais déclarées) → legacy.
            $totalDeclaredStores = $this->countDeclaredStores();
            if ($totalDeclaredStores === null) {
                $this->log("Erreur SQL indéterminée en comptant les boutiques déclarées (entity=" . $this->entity
                    . "), skip stock produits ID: " . implode(',', array_keys($targets)) . " — JAMAIS de fallback legacy sur erreur SQL (MEDIUM-1)", LOG_ERR);
                return $outcomes;
            }
            if ($totalDeclaredStores > 0) {
                $this->log("Boutiques déclarées (" . $totalDeclaredStores . ") mais aucune active/éligible, "
                    . "skip stock produits ID: " . implode(',', array_keys($targets)) . " — jamais de fallback legacy global", LOG_INFO);
                return $outcomes;
            }
            $this->log("Aucune boutique jamais déclarée (legacy pré-Epic-47), push legacy entité/constantes", LOG_DEBUG);
            $stores = array(null);
        }

        // Phase 1 : gates + préparation, par produit et par boutique, regroupement (boutique, emplacement).
        $groups = array();
        $apiByStore = array();
        foreach ($targets as $dolibarrProductId => $safeStock) {
            foreach ($stores as $store) {
                $prepared = $this->prepareStockPush($dolibarrProductId, $store, $safeStock, $bypassApiResolutionCap, $apiByStore);
                if ($prepared === null) {
                    continue; // skip non bloquant (déjà loggé)
                }
                if (isset($prepared['error'])) {
                    $this->recordProductError($dolibarrProductId, $prepared['error'], 'persistent');
                    continue;
                }
                // Clé de regroupement : (boutique, emplacement). La boutique en fait PARTIE — deux
                // boutiques aux credentials distincts ne partagent jamais un appel, même si leur
                // identifiant d'emplacement est identique.
                $groupKey = $prepared['storeId'] . '|' . $prepared['locationId'];
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = array('api' => $prepared['api'], 'entries' => array());
                }
                $groups[$groupKey]['entries'][] = $prepared;
            }
        }

        // Phase 2 : lecture + écriture par groupe.
        foreach ($groups as $group) {
            $this->pushGroup($group['api'], $group['entries'], $outcomes);
        }

        $this->finalizeOutcomes($outcomes);

        return $outcomes;
    }

    /**
     * Gates et préparation d'un push (produit, boutique) : tout ce que faisait pushStockForStore()
     * AVANT la construction du payload (Story 66-1 : scindé en préparation / envoi).
     *
     * @param int         $dolibarrProductId       ID produit Dolibarr
     * @param object|null $store                   Boutique (null = chemin legacy entité/constantes)
     * @param int         $safeStock               Stock cible (déjà planché à 0)
     * @param bool        $bypassApiResolutionCap  Contourne le plafond de résolutions API
     * @param array       $apiByStore              [IN/OUT] cache des API par boutique, le temps du lot
     * @return array|null null = skip non bloquant ; ['error' => string] = erreur ; sinon l'entrée prête
     */
    private function prepareStockPush($dolibarrProductId, $store, $safeStock, $bypassApiResolutionCap, array &$apiByStore)
    {
        $storeId = ($store !== null && !empty($store->rowid)) ? (int) $store->rowid : 0;
        $storeLabel = ($store !== null && isset($store->label)) ? $store->label : 'legacy';

        // Gate licence par boutique (helper canonique, résultat mis en cache process-scoped —
        // HIGH-1) : boutique par défaut jamais bloquée, secondaire non licenciée = skip loggé,
        // jamais d'erreur bloquante. $store null = pas de gate (doli2shopStoreSyncAllowed gère ce cas).
        $licence = $this->isStoreSyncAllowed($store);
        if (empty($licence['allowed'])) {
            $this->log("Boutique '" . $storeLabel . "' (rowid=" . $storeId . ") non autorisée à synchroniser (reason="
                . ($licence['reason'] ?? 'unknown') . "), skip stock produit ID: " . $dolibarrProductId, LOG_INFO);
            return null;
        }

        // Story 53-3 : gate per-store SyncFlowPolicy (flux stock, d2s), AJOUTÉ APRÈS le gate
        // licence ci-dessus et AVANT tout appel API — un override per-store désactivant le push
        // pour CETTE boutique est désormais honoré (les autres boutiques mappées continuent de
        // pousser normalement).
        if (!$this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'dolibarr_to_shopify', $storeId)) {
            $this->log("Synchronisation stock Dolibarr → Shopify désactivée pour la boutique '" . $storeLabel
                . "' (rowid=" . $storeId . "), skip produit ID: " . $dolibarrProductId, LOG_DEBUG);
            return null;
        }

        // Instancier l'API de la boutique AVANT résolution inventaire : CRITICAL-1 (code review) —
        // le fallback API de résolution de l'inventory item id a besoin de l'API de CETTE boutique
        // (credentials propres, jamais celles d'une autre boutique).
        if (!array_key_exists($storeId, $apiByStore)) {
            $apiByStore[$storeId] = $this->buildApiForStore($store);
        }
        $api = $apiByStore[$storeId];
        if ($api === null || !empty($api->error)) {
            $this->log("API Shopify indisponible pour la boutique '" . $storeLabel . "' (rowid=" . $storeId
                . "): " . ($api !== null ? $api->error : 'null'), LOG_ERR);
            if ($api !== null && !empty($api->error)) {
                return array('error' => (string) $api->error);
            }
            return array('error' => "API Shopify indisponible pour la boutique '" . $storeLabel . "' (rowid=" . $storeId . ")");
        }

        // Résoudre l'ID d'inventaire Shopify filtré par boutique. CRITICAL-1 (code review) :
        // llx_doli2shop_inventory n'a AUCUN writer dans le dépôt → source DB prioritaire mais
        // optionnelle ; fallback OBLIGATOIRE via l'API Shopify (mêmes appels que le flux CRON
        // fonctionnel ImportProducts::syncInventoryOnly()/updateSingleVariantStock()).
        // Absent des deux sources = produit non mappé sur cette boutique → skip (jamais d'erreur).
        $shopifyInventoryItemId = $this->resolveInventoryItemId($dolibarrProductId, $storeId, $api, $bypassApiResolutionCap);
        if (empty($shopifyInventoryItemId)) {
            $this->log("Pas d'ID d'inventaire Shopify (DB ni API) pour le produit ID " . $dolibarrProductId
                . " sur la boutique '" . $storeLabel . "' (rowid=" . $storeId . "), skip", LOG_DEBUG);
            return null;
        }

        // Résoudre la location Shopify de la boutique
        $locationId = $this->resolveLocationId($store);
        if (empty($locationId)) {
            $this->log("Aucune location Shopify configurée pour la boutique '" . $storeLabel
                . "' (rowid=" . $storeId . "), stock non synchronisé pour le produit ID: " . $dolibarrProductId, LOG_WARNING);
            return null;
        }

        // Consolidation « dernière valeur gagne » : ne supprimer QUE les push strictement redondants
        $registryKey = $dolibarrProductId . ':' . $storeId;
        if (isset(self::$lastPushed[$registryKey]) && self::$lastPushed[$registryKey] === $safeStock) {
            $this->log("Push redondant supprimé (produit ID " . $dolibarrProductId . ", boutique rowid " . $storeId
                . ", valeur " . $safeStock . " déjà poussée)", LOG_DEBUG);
            return null;
        }

        return array(
            'productId' => $dolibarrProductId,
            'storeId' => $storeId,
            'storeLabel' => $storeLabel,
            'locationId' => $locationId,
            'inventoryItemId' => $shopifyInventoryItemId,
            'safeStock' => $safeStock,
            'registryKey' => $registryKey,
            'api' => $api,
        );
    }

    /**
     * Horloge monotone (mockable en test).
     *
     * @return float Secondes
     */
    protected function monotonicNow()
    {
        return microtime(true);
    }

    /**
     * Le budget de temps du lot est-il épuisé ? (re-review 66-1, MEDIUM-2)
     *
     * @return bool
     */
    private function isOverBudget()
    {
        return $this->batchMaxSeconds !== null && ($this->monotonicNow() - $this->batchStartedAt) > $this->batchMaxSeconds;
    }

    /**
     * Marque un article comme NON traité faute de temps : ni erreur ni push, l'appelant le remet en attente.
     *
     * @param array $entry Entrée préparée
     * @return void
     */
    private function deferEntry(array $entry)
    {
        $this->batchDeferred[$entry['productId']] = true;
        $this->log("Budget de temps du lot épuisé : produit ID " . $entry['productId'] . ", boutique rowid "
            . $entry['storeId'] . " reporté au prochain cycle", LOG_WARNING);
    }

    /**
     * Pousse un groupe (boutique, emplacement) : dédoublonne, puis traite par LOTS de
     * MAX_ITEMS_PER_MUTATION, chacun avec SA propre lecture de référence juste avant SON écriture
     * (review 66-1, MEDIUM-a : une référence lue pour 120 articles serait périmée pour le second lot).
     *
     * @param ShopifyApi $api      API de LA boutique du groupe
     * @param array      $entries  Entrées préparées par prepareStockPush()
     * @param array      $outcomes [IN/OUT] résultats par produit
     * @return void
     */
    private function pushGroup($api, array $entries, array &$outcomes)
    {
        // Défensif (review 66-1, LOW) : deux produits visant le MÊME (article, emplacement) dans un
        // lot enverraient deux deltas calculés sur la même référence — le second serait refusé. La
        // DERNIÈRE valeur gagne (même principe que le registre de dédup) ; l'entrée écartée n'est pas
        // enregistrée comme poussée.
        $byTarget = array();
        foreach ($entries as $position => $entry) {
            $targetKey = $entry['inventoryItemId'] . '|' . $entry['locationId'];
            if (isset($byTarget[$targetKey])) {
                $this->log("Deux produits visent le même article/emplacement (" . $targetKey . ") : la valeur du produit ID "
                    . $entry['productId'] . " remplace celle du produit ID " . $entries[$byTarget[$targetKey]]['productId'], LOG_WARNING);
                // L'entrée écartée n'est PAS un succès silencieux (re-review 66-1, LOW-1) : erreur de nature
                // `config` (valeur non poussée), avertissement si le produit a réussi ailleurs.
                $discarded = $entries[$byTarget[$targetKey]];
                $this->recordProductError($discarded['productId'], "Produit ID " . $discarded['productId'] . ", boutique '"
                    . $discarded['storeLabel'] . "' (rowid=" . $discarded['storeId'] . ") : même variante que le produit "
                    . $entry['productId'] . " (dernier dans l'ordre du lot, article " . $entry['inventoryItemId'] . ") — valeur non poussée, la dernière du lot l'emporte", 'config');
                unset($entries[$byTarget[$targetKey]]);
            }
            $byTarget[$targetKey] = $position;
        }

        foreach (array_chunk(array_values($entries), self::MAX_ITEMS_PER_MUTATION) as $chunkEntries) {
            if ($this->isOverBudget()) {
                foreach ($chunkEntries as $deferredEntry) {
                    $this->deferEntry($deferredEntry);
                }
                continue;
            }
            $this->pushChunk($api, $chunkEntries, $outcomes);
        }
    }

    /**
     * Lit la référence Shopify d'un lot (≤ MAX_ITEMS_PER_MUTATION articles), écrit le delta, isole les
     * échecs (Story 66-1, D1/D3/D5).
     *
     * @param ShopifyApi $api      API de LA boutique du lot
     * @param array      $entries  Entrées préparées (≤ MAX_ITEMS_PER_MUTATION)
     * @param array      $outcomes [IN/OUT] résultats par produit
     * @return void
     */
    private function pushChunk($api, array $entries, array &$outcomes)
    {
        // Payload en snake_case (cf. ImportProducts::updateInventoryQuantities()). AUCUNE référence
        // n'est posée ici : elle est LUE à la localisation visée par le résolveur ci-dessous.
        $rows = array();
        foreach ($entries as $position => $entry) {
            $rows[$position] = $this->buildQuantityRow($entry);
        }

        $failures = array();
        $this->resolveReferences($rows, $api, $failures);

        $readyPositions = array();
        foreach ($entries as $position => $entry) {
            $unreadable = $this->describeUnreadableReference($entry, $rows[$position], isset($failures[$position]) ? $failures[$position] : null);
            if ($unreadable !== null) {
                $this->failEntry($entry, array($unreadable['message']), $unreadable['kind']);
                continue;
            }
            $readyPositions[] = $position;
        }

        if (empty($readyPositions)) {
            return;
        }

        $chunkRows = array();
        foreach ($readyPositions as $position) {
            $chunkRows[] = $rows[$position];
            $entry = $entries[$position];
            $this->log("Push stock Shopify: boutique '" . $entry['storeLabel'] . "' (rowid=" . $entry['storeId']
                . "), inventory_item_id=" . $entry['inventoryItemId'] . ", location=" . $entry['locationId']
                . ", quantité=" . $entry['safeStock'], LOG_INFO);
        }

        // MEDIUM-2 (review passe 2, cosmétique — NON bloquant) : ce push sortant ne pose PAS le
        // flag SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS. Ce n'est PAS un oubli : l'écho webhook
        // Shopify (inventory_levels/update déclenché par notre propre mutation) arrive dans une
        // requête HTTP SÉPARÉE et ULTÉRIEURE, donc un flag in-memory posé ICI serait de toute
        // façon retombé avant l'arrivée de cet écho. L'anti-boucle réellement efficace est déjà
        // en place ailleurs : InventoryWebhookHandler pose PROCESSING_INVENTORY_WEBHOOK avant de
        // créer le mouvement de stock inbound, et runTrigger() rejette tout mouvement dont
        // origin_type == 'shopify_sync'. Aucune boucle infinie possible ; seul effet résiduel
        // accepté : un mouvement de stock Dolibarr redondant (mais non-bouclant).
        $sent = $this->sendQuantities($api, $chunkRows);
        if ($sent === null) {
            foreach ($readyPositions as $position) {
                $this->confirmEntry($entries[$position]);
            }
            return;
        }

        $kind = $sent['transient'] ? 'transient' : 'persistent';

        // D5 : une mutation Shopify est ATOMIQUE — un seul changeFromQuantity périmé (mouvement
        // concurrent entre la lecture et l'écriture) fait refuser le lot ENTIER, et l'index de
        // l'article fautif n'est pas fiable. On rejoue donc chaque article SEUL, après relecture —
        // MAIS UNIQUEMENT sur une userError (refus portant sur les articles — compare-and-swap OU autre :
        // article non stocké à l'emplacement, quantité hors bornes… sinon un seul article fautif ferait
        // échouer les 99 autres, re-review 66-1 HIGH-B). Un throttle, un 5xx, une exception réseau, un
        // 401/403 ou toute erreur de premier niveau n'a rien à voir avec un article : rejouer n articles un
        // par un multiplierait les appels sur une API déjà saturée ou refusée. Échec du lot entier.
        // Un article déjà appliqué relit delta = 0 et est sauté (jamais de double application).
        if (!$sent['replay'] || count($readyPositions) === 1) {
            foreach ($readyPositions as $position) {
                $this->failEntry($entries[$position], $sent['errors'], $kind);
            }
            return;
        }

        $this->log("Écriture groupée refusée (userErrors" . ($sent['cas'] ? ', compare-and-swap' : '') . ") pour " . count($readyPositions) . " article(s) ("
            . implode(', ', $sent['errors']) . ") — rejeu article par article après relecture", LOG_WARNING);
        foreach ($readyPositions as $position) {
            if ($this->isOverBudget()) {
                $this->deferEntry($entries[$position]);
                continue;
            }
            $this->replayEntry($api, $entries[$position]);
        }
    }

    /**
     * Rejoue UN article après relecture de sa référence (isolation D5).
     *
     * @param ShopifyApi $api   API de la boutique
     * @param array      $entry Entrée préparée
     * @return void
     */
    private function replayEntry($api, array $entry)
    {
        // Ligne NEUVE : aucune référence du premier passage ne survit (elle est périmée par hypothèse).
        $rows = array($this->buildQuantityRow($entry));
        $failures = array();
        $this->resolveReferences($rows, $api, $failures);

        $unreadable = $this->describeUnreadableReference($entry, $rows[0], isset($failures[0]) ? $failures[0] : null);
        if ($unreadable !== null) {
            $this->failEntry($entry, array($unreadable['message']), $unreadable['kind']);
            return;
        }

        $sent = $this->sendQuantities($api, $rows);
        if ($sent === null) {
            $this->confirmEntry($entry);
            return;
        }
        $this->failEntry($entry, $sent['errors'], $sent['transient'] ? 'transient' : 'persistent');
    }

    /**
     * Ligne de quantité sans aucune référence (format snake_case de ShopifyApi::inventorySetQuantities()).
     *
     * @param array $entry Entrée préparée
     * @return array
     */
    private function buildQuantityRow(array $entry)
    {
        return array(
            'inventory_item_id'    => $entry['inventoryItemId'],
            'location_id'          => $entry['locationId'],
            'available_adjustment' => $entry['safeStock'],
        );
    }

    /**
     * Lit la référence Shopify (available/on_hand) à l'emplacement visé via la primitive commune
     * InventoryReferenceResolver (la même que l'import, hotfix 2.5.7) — jamais une seconde implémentation.
     *
     * @param array      $rows     [IN/OUT] lignes de quantité
     * @param ShopifyApi $api      API de la boutique
     * @param array      $failures [OUT] échecs de lecture par index de ligne
     * @return void
     */
    private function resolveReferences(array &$rows, $api, array &$failures)
    {
        $resolver = new InventoryReferenceResolver();
        $logger = function ($message, $level) {
            $this->log($message, $level);
        };
        $resolver->resolve($rows, $api, $this->locationErrorCount, $logger, $failures);
    }

    /**
     * D3 : si la référence n'a pas pu être lue, l'article n'est PAS envoyé. Retourne le message
     * d'erreur explicite et sa nature (jamais de valeur inventée), ou null si la référence est lue.
     *
     * Natures : 'config' (emplacement absent/désactivé : défaut de configuration de CETTE boutique,
     * retenter ne sert à rien tant qu'il n'est pas corrigé), 'transient' (réseau, throttle, erreur
     * partielle : incident passager), 'persistent' (article non activé à l'emplacement).
     *
     * @param array      $entry   Entrée préparée
     * @param array      $row     Ligne après lecture
     * @param array|null $failure Échec de lecture signalé par le résolveur (null = aucun)
     * @return array{message:string,kind:string}|null
     */
    private function describeUnreadableReference(array $entry, array $row, $failure)
    {
        $prefix = "Produit ID " . $entry['productId'] . ", boutique '" . $entry['storeLabel'] . "' (rowid=" . $entry['storeId'] . ") : ";

        if ($failure !== null) {
            if ($failure['kind'] === 'location') {
                return array('kind' => 'config', 'message' => $prefix . "lecture du stock Shopify impossible, emplacement " . $entry['locationId']
                    . " invalide ou désactivé (" . $failure['reason'] . ") — configuration de la boutique à corriger : "
                    . $failure['message']);
            }
            if ($failure['kind'] === 'persistent') {
                return array('kind' => 'persistent', 'message' => $prefix . "lecture du stock Shopify refusée, erreur non transitoire ("
                    . $failure['message'] . ") — vérifier la connexion Shopify (jeton, droits de l'application)");
            }
            return array('kind' => 'transient', 'message' => $prefix . "lecture du stock Shopify impossible (" . $failure['message'] . ") — sera retenté");
        }

        if (!array_key_exists('current_shopify_quantity', $row) && !array_key_exists('on_hand_shopify_quantity', $row)) {
            return array('kind' => 'persistent', 'message' => $prefix . "article Shopify " . $entry['inventoryItemId'] . " absent de l'emplacement "
                . $entry['locationId'] . " (non activé ou non suivi à cet emplacement) — stock non poussé. Activer l'article à cet "
                . "emplacement dans Shopify ; la tentative sera renouvelée.");
        }

        return null;
    }

    /**
     * Envoie un lot de lignes déjà munies de leur référence.
     *
     * @param ShopifyApi $api  API de la boutique
     * @param array      $rows Lignes de quantité
     * @return array{errors:string[],cas:bool,replay:bool,transient:bool}|null null = succès confirmé. `replay` =
     *         au moins une userError (refus portant sur les articles) : seul cas qui justifie un rejeu
     *         article par article ; `cas` = compare-and-swap (journal) ; `transient` = throttle/5xx/réseau
     *         UNIQUEMENT (tout le reste est persistant).
     */
    private function sendQuantities($api, array $rows)
    {
        try {
            $response = $api->inventorySetQuantities($rows);
        } catch (\Throwable $e) {
            $this->log("Exception lors du push stock Shopify (" . count($rows) . " article(s)): " . $e->getMessage(), LOG_ERR);
            // Re-review 66-1 (HIGH-A) : transitoire = réseau/délai SEULEMENT ; toute autre exception
            // (configuration absente, 401…) est persistante.
            return array('errors' => array($e->getMessage()), 'cas' => false, 'replay' => false,
                'transient' => ShopifyApi::isTransientFailureMessage($e->getMessage()));
        }

        // Détecter les erreurs de la réponse agrégée normalisée (userErrors / failedCount / errors)
        if (!$this->responseHasError($response)) {
            return null;
        }

        $userErrors = (is_object($response) && !empty($response->userErrors)) ? (array) $response->userErrors : array();
        // Détection du compare-and-swap par le TEXTE : la mutation ne demande pas `userErrors.code`
        // (champ à vérifier dans la doc Admin API 2026-04 avant de l'ajouter ; non inventé ici). Le
        // drapeau `cas` ne sert plus qu'au journal : le rejeu vaut pour TOUTE userError.
        $cas = false;
        foreach ($userErrors as $userError) {
            $text = (is_object($userError) && isset($userError->message))
                ? (string) $userError->message
                : (is_string($userError) ? $userError : (string) json_encode($userError));
            if (preg_match('/changeFromQuantity|persisted quantity/i', $text)) {
                $cas = true;
            }
        }

        // Re-review 66-1 (HIGH-A) : sans userError, l'erreur est de premier niveau. Transitoire
        // UNIQUEMENT si c'est un throttle ou une erreur serveur déclarée ; 401, 403, ACCESS_DENIED,
        // schéma, inconnue = persistant. Réponse non-objet : corps illisible (passerelle 5xx).
        if (!is_object($response)) {
            $transient = true;
        } elseif (!empty($userErrors)) {
            $transient = false;
        } else {
            $transient = ShopifyApi::isTransientGraphQLErrors($response->errors ?? null);
        }

        return array('errors' => $this->extractResponseErrors($response), 'cas' => $cas, 'replay' => !empty($userErrors), 'transient' => $transient);
    }

    /**
     * Enregistre un succès CONFIRMÉ dans le registre de dédup (jamais avant : un push refusé ne
     * doit pas figer la valeur, Story 66-1 AC6) et le journalise.
     *
     * @param array $entry Entrée préparée
     * @return void
     */
    private function confirmEntry(array $entry)
    {
        // Registre borné (MEDIUM, review) : purge défensive avant écriture pour les contextes
        // CLI/CRON long-vivants (worker persistant traitant un très grand nombre de produits
        // distincts). Perte de l'optimisation de dédup uniquement, jamais de valeur périmée.
        if (count(self::$lastPushed) >= self::MAX_REGISTRY_SIZE) {
            self::$lastPushed = array();
            $this->log("Registre de consolidation stock réinitialisé (taille max " . self::MAX_REGISTRY_SIZE . " atteinte)", LOG_DEBUG);
        }

        // Succès : enregistrer la dernière valeur poussée pour ce couple
        self::$lastPushed[$entry['registryKey']] = $entry['safeStock'];
        $this->batchConfirmed[$entry['productId']] = (isset($this->batchConfirmed[$entry['productId']]) ? $this->batchConfirmed[$entry['productId']] : 0) + 1;
        $this->log("Stock Shopify mis à jour avec succès (produit ID " . $entry['productId'] . ", boutique rowid "
            . $entry['storeId'] . ", quantité " . $entry['safeStock'] . ")", LOG_INFO);
    }

    /**
     * Échec FINAL d'un article : journalise, mémorise l'erreur du produit (consolidée en fin de lot
     * par finalizeOutcomes()). Ne PAS enregistrer dans le registre : la valeur n'est pas confirmée
     * poussée → un prochain mouvement re-tentera.
     *
     * @param array    $entry  Entrée préparée
     * @param string[] $errors Messages d'erreur
     * @param string   $kind   'transient' | 'config' | 'persistent'
     * @return void
     */
    private function failEntry(array $entry, array $errors, $kind)
    {
        $this->log("Push stock Shopify en échec (" . $kind . ", produit ID " . $entry['productId'] . ", boutique rowid "
            . $entry['storeId'] . "): " . implode(', ', $errors), LOG_ERR);
        foreach ($errors as $message) {
            $this->recordProductError($entry['productId'], $message, $kind);
        }
    }

    /**
     * Mémorise une erreur d'un produit pour la consolidation de fin de lot.
     *
     * @param int    $dolibarrProductId ID produit
     * @param string $message           Message
     * @param string $kind              'transient' | 'config' | 'persistent'
     * @return void
     */
    private function recordProductError($dolibarrProductId, $message, $kind)
    {
        $this->batchPending[$dolibarrProductId][] = array('kind' => $kind, 'message' => (string) $message);
    }

    /**
     * Consolide les erreurs de chaque produit en fin de lot (review 66-1, MEDIUM-b/c) :
     *  - une erreur 'persistent' ou 'transient' fait échouer le produit (code -1) ;
     *  - `consumeTry` n'est vrai que si au moins une erreur est 'persistent' : un incident transitoire
     *    ou une erreur de configuration ne consomme PAS les essais de la file (une panne Shopify ne
     *    doit pas envoyer tout le lot en dead-letter ; le backoff complet reste hors périmètre) ;
     *  - une erreur 'config' (emplacement absent/désactivé d'UNE boutique) ne fait pas échouer le
     *    produit si au moins une autre boutique a été confirmée : elle devient un avertissement
     *    (`warnings`), jamais silencieuse (LOG_ERR). Sans aucune boutique réussie, le produit échoue
     *    (code -1) SANS consommer d'essai, avec le message explicite.
     *
     * @param array $outcomes [IN/OUT] résultats par produit
     * @return void
     */
    private function finalizeOutcomes(array &$outcomes)
    {
        foreach ($outcomes as $dolibarrProductId => &$outcome) {
            if (!empty($this->batchDeferred[$dolibarrProductId])) {
                $outcome['deferred'] = true;
            }
            if (empty($this->batchPending[$dolibarrProductId])) {
                continue;
            }
            $all = array();
            $blocking = array();
            $config = array();
            foreach ($this->batchPending[$dolibarrProductId] as $pending) {
                $all[] = $pending['message'];
                if ($pending['kind'] === 'config') {
                    $config[] = $pending['message'];
                } else {
                    $blocking[] = $pending;
                }
            }

            if (!empty($blocking)) {
                $outcome['code'] = -1;
                $outcome['errors'] = array_merge($outcome['errors'], $all);
                $consume = false;
                foreach ($blocking as $pending) {
                    if ($pending['kind'] === 'persistent') {
                        $consume = true;
                    }
                }
                $outcome['consumeTry'] = $consume;
            } elseif (!empty($this->batchConfirmed[$dolibarrProductId])) {
                $outcome['warnings'] = array_merge($outcome['warnings'], $config);
            } else {
                $outcome['code'] = -1;
                $outcome['errors'] = array_merge($outcome['errors'], $config);
                $outcome['consumeTry'] = false;
            }

            if ($outcome['code'] === -1) {
                $this->errors = array_merge($this->errors, $all);
            }
        }
        unset($outcome);
    }

    /**
     * Charge le produit et résout la valeur de stock (réel/virtuel) à pousser.
     *
     * @param int $dolibarrProductId ID produit Dolibarr
     * @return int|null Valeur de stock (peut être négative avant plancher), ou null si erreur de chargement
     */
    protected function resolveStockValue($dolibarrProductId)
    {
        $product = new Product($this->db);
        $result = $product->fetch($dolibarrProductId);
        if ($result <= 0) {
            $this->log("Erreur lors du chargement du produit ID: " . $dolibarrProductId, LOG_ERR);
            $this->errors[] = isset($product->error) ? $product->error : ('fetch failed for product ' . $dolibarrProductId);
            return null;
        }

        // Charger les stocks du produit
        $product->load_stock('warehouseopen,warehouseinternal');

        return $this->getStockValue($product);
    }

    /**
     * Retourne la liste des boutiques actives de l'entité.
     *
     * @return array Tableau d'objets boutique (vide = chemin legacy)
     */
    protected function getActiveStores()
    {
        $storeService = new StoreService($this->db, $this->entity);
        return $storeService->getAll(true);
    }

    /**
     * Compte le nombre TOTAL de boutiques déclarées de l'entité, actives ou non (CRITICAL-2, code
     * review ; MEDIUM-1, review passe 2).
     *
     * Utilisé UNIQUEMENT pour distinguer, quand `getActiveStores()` est vide :
     *   - « aucune boutique jamais déclarée » (retour 0 → legacy pré-Epic-47, chemin global légitime)
     *   - « des boutiques existent mais sont toutes inactives » (retour > 0 → jamais de legacy)
     *   - « erreur SQL indéterminée lors du comptage » (retour null → jamais de legacy, MEDIUM-1 :
     *     contrairement à l'ancien `getAllStores()` basé sur `StoreService::getAll()`, dont le
     *     tableau vide était ambigu entre "aucune boutique" et "erreur SQL").
     *
     * @return int|null Nombre de boutiques déclarées (>= 0), ou null si erreur SQL indéterminée.
     */
    protected function countDeclaredStores()
    {
        $storeService = new StoreService($this->db, $this->entity);
        return $storeService->countAllOrNull();
    }

    /**
     * Gate licence par boutique, mise en cache process-scoped (HIGH-1, code review).
     *
     * doli2shopStoreSyncAllowed() peut déclencher un curl bloquant (timeout 10s) pour une
     * boutique secondaire dont le cache DB 5 minutes a expiré. Un mouvement de stock étant
     * désormais poussé PAR LIGNE (une expédition de N lignes = N mouvements), sans ce cache
     * process-scoped une même boutique pourrait re-déclencher inutilement la gate N fois dans
     * la même requête. Bornée à MAX_REGISTRY_SIZE.
     *
     * @param object|null $store Boutique (null = chemin legacy, jamais de gate)
     * @return array ['allowed'=>bool, 'status'=>string, 'reason'=>string]
     */
    protected function isStoreSyncAllowed($store)
    {
        $storeId = ($store !== null && !empty($store->rowid)) ? (int) $store->rowid : 0;

        if (isset(self::$licenseGateCache[$storeId])) {
            return self::$licenseGateCache[$storeId];
        }

        $result = doli2shopStoreSyncAllowed($store);

        if (count(self::$licenseGateCache) >= self::MAX_REGISTRY_SIZE) {
            self::$licenseGateCache = array();
            $this->log("Cache gate licence réinitialisé (taille max " . self::MAX_REGISTRY_SIZE . " atteinte)", LOG_DEBUG);
        }
        self::$licenseGateCache[$storeId] = $result;

        return $result;
    }

    /**
     * Instancie l'API Shopify pour une boutique donnée (ou l'API legacy si $store === null).
     *
     * @param object|null $store Boutique (null = legacy entité/constantes)
     * @return ShopifyApi|null
     */
    protected function buildApiForStore($store)
    {
        if ($store === null) {
            return $this->api;
        }
        return ShopifyApi::forStore($this->db, $store, $this->entity);
    }

    /**
     * Résout la location Shopify (GID) à cibler pour une boutique.
     *
     * @param object|null $store Boutique (null = legacy → constante globale)
     * @return string GID location (gid://shopify/Location/N) ou '' si non résolue
     */
    protected function resolveLocationId($store)
    {
        if ($store !== null && !empty($store->location_id)) {
            return $this->toLocationGid($store->location_id);
        }
        $locationId = getDolGlobalString('DOLI2SHOP_LOCATION_ID', '');
        if (!empty($locationId)) {
            return $this->toLocationGid($locationId);
        }
        return '';
    }

    /**
     * Normalise un identifiant de location en GID Shopify.
     *
     * @param string $raw Identifiant brut (numérique ou déjà GID)
     * @return string GID (gid://shopify/Location/N)
     */
    private function toLocationGid($raw)
    {
        $raw = (string) $raw;
        if (strpos($raw, 'gid://') === 0) {
            return $raw;
        }
        return 'gid://shopify/Location/' . $raw;
    }

    /**
     * Normalise un identifiant d'inventory item en GID Shopify.
     *
     * @param string $raw Identifiant brut (numérique ou déjà GID)
     * @return string GID (gid://shopify/InventoryItem/N)
     */
    private function toInventoryItemGid($raw)
    {
        $raw = (string) $raw;
        if (strpos($raw, 'gid://') === 0) {
            return $raw;
        }
        return 'gid://shopify/InventoryItem/' . $raw;
    }

    /**
     * Détecte si une réponse inventorySetQuantities agrégée contient une erreur.
     *
     * @param object|null $response Réponse agrégée normalisée (cf. ShopifyApi::inventorySetQuantities())
     * @return bool True si erreur détectée
     */
    private function responseHasError($response)
    {
        if (!is_object($response)) {
            return true;
        }
        if (!empty($response->errors)) {
            return true;
        }
        if (!empty($response->userErrors)) {
            return true;
        }
        if (isset($response->failedCount) && (int) $response->failedCount > 0) {
            return true;
        }
        return false;
    }

    /**
     * Extrait les messages d'erreur d'une réponse inventorySetQuantities agrégée.
     *
     * @param object|null $response Réponse agrégée normalisée
     * @return array Messages d'erreur (jamais vide si responseHasError() est vrai)
     */
    private function extractResponseErrors($response)
    {
        $messages = array();
        if (!is_object($response)) {
            return array('Réponse API invalide (non-objet)');
        }
        if (!empty($response->errors)) {
            foreach ((array) $response->errors as $err) {
                if (is_object($err) && isset($err->message)) {
                    $messages[] = (string) $err->message;
                } elseif (is_string($err)) {
                    $messages[] = $err;
                } else {
                    $messages[] = json_encode($err);
                }
            }
        }
        if (!empty($response->userErrors)) {
            foreach ((array) $response->userErrors as $err) {
                $field = (is_object($err) && isset($err->field))
                    ? (is_array($err->field) ? implode('.', $err->field) : $err->field)
                    : '';
                $msg = (is_object($err) && isset($err->message))
                    ? $err->message
                    : (is_string($err) ? $err : json_encode($err));
                $messages[] = trim($field . ': ' . $msg, ': ');
            }
        }
        if (empty($messages) && isset($response->failedCount) && (int) $response->failedCount > 0) {
            $messages[] = 'failedCount=' . (int) $response->failedCount;
        }
        return $messages;
    }

    /**
     * Obtenir l'ID d'inventaire Shopify pour un produit Dolibarr, filtré par boutique.
     *
     * Source DB PRIORITAIRE mais OPTIONNELLE (CRITICAL-1, code review) : llx_doli2shop_inventory
     * n'a aucun writer dans le dépôt à ce jour (table vide en prod) — cette méthode retourne donc
     * systématiquement null en prod actuellement. Elle reste appelée en premier (source la plus
     * fiable et la moins coûteuse SI un jour peuplée), mais son échec n'est PAS bloquant :
     * resolveInventoryItemId() bascule alors sur le fallback API obligatoire
     * (resolveInventoryItemIdViaApi()).
     *
     * Invariant CLAUDE.dolibarr.md §14 : filtre fk_store conditionné à store_id > 0 ;
     * store_id == 0 (legacy) = pas de filtre boutique.
     *
     * @param int $dolibarrProductId ID du produit Dolibarr
     * @param int $storeId           Rowid boutique (0 = legacy, pas de filtre fk_store)
     * @return string|null GID inventory item, ou null si non trouvé
     */
    protected function getShopifyInventoryId($dolibarrProductId, $storeId = 0)
    {
        $sql = "SELECT shopify_inventory_item_id FROM " . MAIN_DB_PREFIX . "doli2shop_inventory";
        $sql .= " WHERE fk_product = " . ((int) $dolibarrProductId);
        $sql .= " AND entity = " . ((int) $this->entity);
        // Filtre fk_store conditionnel (invariant §14) : jamais de filtre si storeId == 0
        if ((int) $storeId > 0) {
            $sql .= " AND fk_store = " . ((int) $storeId);
        }
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if (!empty($obj->shopify_inventory_item_id)) {
                return $this->toInventoryItemGid($obj->shopify_inventory_item_id);
            }
            return null;
        } elseif ($resql) {
            $this->db->free($resql);
        }

        return null;
    }

    /**
     * Résout l'inventory item id Shopify d'un produit pour une boutique donnée (CRITICAL-1, code review).
     *
     * Orchestration à 2 sources : llx_doli2shop_inventory en priorité (rapide, source directe si
     * un jour peuplée), puis fallback OBLIGATOIRE via l'API Shopify (resolveInventoryItemIdViaApi())
     * — sans ce fallback, la table étant vide en prod, AUCUN produit ne serait jamais résolu et le
     * push resterait un no-op silencieux malgré tout le reste de la story branché correctement.
     *
     * @param int         $dolibarrProductId ID du produit Dolibarr
     * @param int         $storeId           Rowid boutique (0 = legacy)
     * @param ShopifyApi  $api               Instance API de LA boutique courante (jamais une autre)
     * @return string|null GID inventory item, ou null si non résolu par aucune des 2 sources
     */
    protected function resolveInventoryItemId($dolibarrProductId, $storeId, $api, $bypassApiResolutionCap = false)
    {
        $viaDb = $this->getShopifyInventoryId($dolibarrProductId, $storeId);
        if (!empty($viaDb)) {
            return $viaDb;
        }

        // HIGH-1 (review passe 2) : plafond de résolutions API par requête. La table
        // llx_doli2shop_inventory étant vide en prod (avant le write-through ci-dessous), CHAQUE
        // ligne de mouvement de stock retombait sur ce fallback API bloquant (1-6 appels GraphQL
        // synchrones) DANS la transaction de validation d'expédition Dolibarr. Au-delà du plafond,
        // on skip explicitement (jamais d'appel API) plutôt que de risquer un timeout proportionnel
        // au nombre de lignes — rattrapage attendu par le CRON de filet (Story 52-2).
        //
        // HIGH-1 (review 52-2) : le plafond protège le chemin SYNCHRONE (trigger → Expedition::valid()).
        // Le recalage batch (StockRecalageService) est un contexte admin DÉLIBÉRÉ, borné par
        // BATCH_SIZE + set_time_limit(0) + rate-limit du client API + write-through (passes suivantes
        // gratuites). Il DOIT résoudre TOUS les produits × TOUTES les boutiques (sinon skips silencieux
        // comptés "poussés" → recalage incomplet en multi-boutiques). Il passe donc $bypassApiResolutionCap=true.
        if (!$bypassApiResolutionCap && self::$apiResolutionsThisRequest >= self::MAX_API_RESOLUTIONS_PER_REQUEST) {
            $this->log("Plafond de résolutions API atteint (" . self::MAX_API_RESOLUTIONS_PER_REQUEST
                . ") pour cette requête, skip résolution API produit ID " . $dolibarrProductId
                . " sur la boutique rowid " . $storeId . " — rattrapage par CRON 52-2", LOG_WARNING);
            return null;
        }
        self::$apiResolutionsThisRequest++;

        return $this->resolveInventoryItemIdViaApi($dolibarrProductId, $storeId, $api);
    }

    /**
     * Résout l'inventory item id Shopify DEPUIS L'API, en répliquant le flux CRON fonctionnel
     * (ImportProducts::syncInventoryOnly() l.566 + updateSingleVariantStock() l.643) — CRITICAL-1.
     *
     * 1) Récupère le shopifyProductId mappé localement (llx_doli2shop_products, fk_store
     *    conditionnel). Fallback : recherche par SKU via ShopifyApi::findProductBySku($ref).
     * 2) $api->getProductVariants($shopifyProductId) (legacyResourceId numérique, PAS un GID —
     *    la méthode construit elle-même le GID produit en interne).
     * 3) Matche la variante par SKU strict (= $product->ref) ; si aucune correspondance stricte
     *    et une seule variante Shopify existe (produit simple), la retient par défaut.
     * 4) Lit $variant->inventoryItemId (déjà un GID Shopify, exposé à plat par getProductVariants()).
     *
     * Scope STRICTEMENT borné à 1 produit — jamais d'instanciation d'ImportProducts complet ici
     * (risque de timeout catalogue, cf. Dev Notes story 52-1) : seuls les 2 appels API ciblés sont
     * réutilisés.
     *
     * @param int        $dolibarrProductId ID du produit Dolibarr
     * @param int        $storeId           Rowid boutique (0 = legacy, pas de filtre fk_store)
     * @param ShopifyApi $api               Instance API de la boutique courante
     * @return string|null GID inventory item, ou null si non résolu
     */
    protected function resolveInventoryItemIdViaApi($dolibarrProductId, $storeId, $api)
    {
        if ($api === null) {
            return null;
        }

        $productRef = $this->getProductRef($dolibarrProductId);
        if ($productRef === null) {
            $this->log("Résolution API impossible : produit Dolibarr ID " . $dolibarrProductId . " introuvable", LOG_WARNING);
            return null;
        }

        $shopifyProductId = $this->getShopifyProductId($dolibarrProductId, $storeId);

        if (empty($shopifyProductId)) {
            try {
                $shopifyProduct = $api->findProductBySku($productRef);
            } catch (\Throwable $e) {
                $this->log("Exception findProductBySku (produit ID " . $dolibarrProductId . ", ref " . $productRef
                    . "): " . $e->getMessage(), LOG_ERR);
                return null;
            }
            if (empty($shopifyProduct) || empty($shopifyProduct->legacyResourceId)) {
                $this->log("Produit Shopify introuvable par SKU '" . $productRef . "' (produit ID " . $dolibarrProductId . ")", LOG_DEBUG);
                return null;
            }
            $shopifyProductId = $shopifyProduct->legacyResourceId;
        }

        try {
            $variants = $api->getProductVariants($shopifyProductId);
        } catch (\Throwable $e) {
            $this->log("Exception getProductVariants (Shopify product ID " . $shopifyProductId . ", produit Dolibarr ID "
                . $dolibarrProductId . "): " . $e->getMessage(), LOG_ERR);
            return null;
        }

        if (empty($variants)) {
            $this->log("Aucune variante Shopify pour le produit Shopify ID " . $shopifyProductId
                . " (produit Dolibarr ID " . $dolibarrProductId . ")", LOG_DEBUG);
            return null;
        }

        $matchedVariant = null;
        foreach ($variants as $variant) {
            if (!empty($variant->sku) && $variant->sku === $productRef) {
                $matchedVariant = $variant;
                break;
            }
        }
        // Produit simple sans correspondance SKU stricte : une seule variante Shopify → la retenir
        if ($matchedVariant === null && count($variants) === 1) {
            $matchedVariant = $variants[0];
        }

        if ($matchedVariant === null || empty($matchedVariant->inventoryItemId)) {
            $this->log("Aucune variante Shopify correspondante avec inventoryItemId pour le produit ID "
                . $dolibarrProductId . " (ref " . $productRef . ")", LOG_DEBUG);
            return null;
        }

        // inventoryItemId déjà un GID (exposé tel quel par getProductVariants()) ; toInventoryItemGid()
        // est idempotent — défense en profondeur si jamais un id numérique brut était renvoyé.
        $resolvedGid = $this->toInventoryItemGid($matchedVariant->inventoryItemId);

        // HIGH-1 (review passe 2) — write-through : persiste le résultat dans llx_doli2shop_inventory
        // pour que les mouvements SUIVANTS de ce même produit/boutique lisent la table (0 appel API)
        // au lieu de re-tenter systématiquement le fallback API. Best-effort, jamais bloquant.
        $this->persistResolvedInventoryItemId($dolibarrProductId, $storeId, $resolvedGid);

        return $resolvedGid;
    }

    /**
     * Persiste (write-through) l'inventory item id résolu via l'API dans llx_doli2shop_inventory
     * (HIGH-1, review passe 2) : chaque produit n'est ainsi résolu via l'API Shopify qu'UNE seule
     * fois — les mouvements suivants de ce couple (produit, boutique) sont lus directement en base
     * par `getShopifyInventoryId()` (0 appel API, rapide), ce qui réduit fortement la pression sur
     * le plafond `MAX_API_RESOLUTIONS_PER_REQUEST`.
     *
     * La clé métier de LOOKUP (utilisée par `getShopifyInventoryId()`) est (fk_product, fk_store,
     * entity), mais la contrainte UNIQUE réelle de la table porte sur
     * (shopify_inventory_item_id, entity, fk_store) (cf. sql/llx_doli2shop_inventory.sql, Epic 47-2).
     * Ces deux clés ne coïncident pas forcément : si l'inventory_item_id Shopify d'un produit change
     * (variante recréée côté Shopify), un simple `INSERT ... ON DUPLICATE KEY UPDATE` sur la seule UK
     * réelle laisserait une ligne OBSOLÈTE en base (ancien item_id, même produit/boutique) en plus de
     * la nouvelle — la lecture suivante de `getShopifyInventoryId()` (LIMIT 1, sans ORDER BY
     * déterministe) pourrait alors renvoyer indifféremment l'ancienne ou la nouvelle valeur. On purge
     * donc explicitement toute ligne obsolète du même (fk_product, fk_store, entity) AVANT l'upsert
     * par UK réelle.
     *
     * `$db->escape()` sur toute valeur dynamique, filtre entity systématique, fk_store toujours
     * renseigné (0 = legacy, colonne NOT NULL DEFAULT 0 — pas de filtre conditionnel nécessaire ici
     * car on écrit la valeur telle quelle, invariant §14 ne concerne que les clauses WHERE de lecture
     * globale). Transaction explicite. Best-effort strict : toute erreur SQL est loggée en
     * LOG_WARNING mais JAMAIS bloquante (non-blocage Dolibarr) — un échec de persistance n'empêche
     * pas le push courant de réussir, seul l'optimisme du cache est perdu pour ce produit.
     *
     * @param int    $dolibarrProductId ID produit Dolibarr
     * @param int    $storeId           Rowid boutique (0 = legacy)
     * @param string $inventoryItemGid  GID inventory item résolu via l'API (gid://shopify/InventoryItem/N)
     * @return void
     */
    protected function persistResolvedInventoryItemId($dolibarrProductId, $storeId, $inventoryItemGid)
    {
        if (empty($inventoryItemGid)) {
            return;
        }

        $dolibarrProductId = (int) $dolibarrProductId;
        $storeId = (int) $storeId;
        $entity = (int) $this->entity;
        $rawItemId = $this->db->escape($this->fromInventoryItemGid($inventoryItemGid));

        $this->db->begin();

        // Purge des lignes obsolètes du même (fk_product, fk_store, entity) portant un item_id
        // DIFFÉRENT (cf. docblock : clé de lookup métier ≠ UK réelle de la table).
        $sqlPurge  = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_inventory";
        $sqlPurge .= " WHERE fk_product = " . $dolibarrProductId;
        $sqlPurge .= " AND entity = " . $entity;
        $sqlPurge .= " AND fk_store = " . $storeId;
        $sqlPurge .= " AND shopify_inventory_item_id != '" . $rawItemId . "'";

        $resPurge = $this->db->query($sqlPurge);
        if (!$resPurge) {
            $this->db->rollback();
            $this->log("persistResolvedInventoryItemId() - Erreur purge lignes obsolètes (produit ID " . $dolibarrProductId
                . ", boutique rowid " . $storeId . "): " . $this->db->lasterror(), LOG_WARNING);
            return;
        }

        $now = $this->db->idate(dol_now());
        $sqlUpsert  = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_inventory";
        $sqlUpsert .= " (fk_product, shopify_inventory_item_id, entity, fk_store, date_creation)";
        $sqlUpsert .= " VALUES (" . $dolibarrProductId . ", '" . $rawItemId . "', " . $entity . ", " . $storeId . ", '" . $now . "')";
        $sqlUpsert .= " ON DUPLICATE KEY UPDATE";
        $sqlUpsert .= " fk_product = VALUES(fk_product),";
        $sqlUpsert .= " tms = CURRENT_TIMESTAMP";

        $resUpsert = $this->db->query($sqlUpsert);
        if (!$resUpsert) {
            $this->db->rollback();
            $this->log("persistResolvedInventoryItemId() - Erreur upsert (produit ID " . $dolibarrProductId
                . ", boutique rowid " . $storeId . "): " . $this->db->lasterror(), LOG_WARNING);
            return;
        }

        $this->db->commit();
        $this->log("persistResolvedInventoryItemId() - Write-through OK (produit ID " . $dolibarrProductId
            . ", boutique rowid " . $storeId . ", item_id=" . $rawItemId . ")", LOG_DEBUG);
    }

    /**
     * Extrait l'identifiant numérique brut d'un GID inventory item Shopify (inverse de
     * `toInventoryItemGid()`), pour stockage en base sous la forme historiquement utilisée par
     * `getShopifyInventoryId()` (colonne `shopify_inventory_item_id`, valeur brute non-GID).
     *
     * @param string $gid GID Shopify (gid://shopify/InventoryItem/N) ou déjà un id brut
     * @return string Identifiant brut
     */
    private function fromInventoryItemGid($gid)
    {
        $gid = (string) $gid;
        if (strpos($gid, 'gid://shopify/InventoryItem/') === 0) {
            return str_replace('gid://shopify/InventoryItem/', '', $gid);
        }
        return $gid;
    }

    /**
     * Charge la référence (SKU) d'un produit Dolibarr, nécessaire au matching de variante Shopify.
     *
     * @param int $dolibarrProductId ID du produit Dolibarr
     * @return string|null Référence produit, ou null si le produit est introuvable/sans ref
     */
    protected function getProductRef($dolibarrProductId)
    {
        $product = new Product($this->db);
        $result = $product->fetch((int) $dolibarrProductId);
        if ($result <= 0 || empty($product->ref)) {
            return null;
        }
        return $product->ref;
    }

    /**
     * Lit le shopifyProductId (legacyResourceId numérique, PAS un GID) mappé localement pour un
     * produit Dolibarr, filtré par boutique.
     *
     * Invariant CLAUDE.dolibarr.md §14 : filtre fk_store conditionné à store_id > 0.
     *
     * @param int $dolibarrProductId ID du produit Dolibarr
     * @param int $storeId           Rowid boutique (0 = legacy, pas de filtre fk_store)
     * @return string|null Shopify legacyResourceId numérique, ou null si non mappé
     */
    protected function getShopifyProductId($dolibarrProductId, $storeId = 0)
    {
        $sql = "SELECT shopifyProductId FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE fk_product = " . ((int) $dolibarrProductId);
        $sql .= " AND entity = " . ((int) $this->entity);
        if ((int) $storeId > 0) {
            $sql .= " AND fk_store = " . ((int) $storeId);
        }
        $sql .= " AND shopifyProductId IS NOT NULL AND shopifyProductId != ''";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if (!empty($obj->shopifyProductId)) {
                $raw = (string) $obj->shopifyProductId;
                // Stocké historiquement en legacyResourceId numérique ; normalise si un GID y était stocké
                if (strpos($raw, 'gid://') === 0) {
                    return str_replace('gid://shopify/Product/', '', $raw);
                }
                return $raw;
            }
            return null;
        } elseif ($resql) {
            $this->db->free($resql);
        }

        return null;
    }

    /**
     * Obtenir la valeur de stock à utiliser (réel ou virtuel selon la configuration)
     *
     * @param Product $product Objet produit Dolibarr
     * @return int Valeur du stock à utiliser
     */
    private function getStockValue($product)
    {
        global $conf;

        // Déterminer si on utilise le stock virtuel ou réel.
        // FIX (52-1) : lire la constante canonique DOLI2SHOP_USE_VIRTUAL_STOCK (celle réglée par
        // l'admin et lue par le CRON via SyncUtils/ImportProducts). L'ancien nom SHOPIFY_USE_VIRTUAL_STOCK
        // était périmé (avant le renommage shopify→doli2shop v2.1.6) : le trigger et le CRON auraient
        // poussé des valeurs divergentes (réel vs virtuel). Défaut 0 (réel), cohérent avec le CRON
        // (!empty(config->use_virtual_stock) → unset = réel).
        $useVirtualStock = getDolGlobalInt('DOLI2SHOP_USE_VIRTUAL_STOCK', 0);

        if ($useVirtualStock) {
            $this->log("Utilisation du stock virtuel configurée", LOG_DEBUG);

            // Cascade de détection du stock virtuel
            if (isset($product->stock_theorique)) {
                $stock = $product->stock_theorique;
                $this->log("Stock virtuel trouvé via stock_theorique: " . $stock, LOG_DEBUG);
            } elseif (method_exists($product, 'getStockTheoretic')) {
                $stock = $product->getStockTheoretic();
                $this->log("Stock virtuel trouvé via getStockTheoretic(): " . $stock, LOG_DEBUG);
            } elseif (method_exists($product, 'getStockTheorique')) {
                $stock = $product->getStockTheorique();
                $this->log("Stock virtuel trouvé via getStockTheorique(): " . $stock, LOG_DEBUG);
            } else {
                // Fallback au stock réel
                $stock = $product->stock_reel;
                $this->log("Stock virtuel non trouvé, utilisation du stock réel: " . $stock, LOG_DEBUG);
            }
        } else {
            // Utiliser le stock réel directement
            $stock = $product->stock_reel;
            $this->log("Utilisation du stock réel: " . $stock, LOG_DEBUG);
        }

        return $stock;
    }
}
