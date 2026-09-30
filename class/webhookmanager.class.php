<?php
/**
 * @file        class/webhookmanager.class.php
 * @brief       Webhook manager - réception, validation HMAC, stockage et traitement des webhooks Shopify
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    webhooks
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.0.0
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
dol_include_once('/core/db/Database.interface.php');
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/shopifywebhooks.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Class WebhookManager
 * Gère la réception, validation et traitement des webhooks Shopify
 */
class WebhookManager
{
    use LoggerTrait;
    /** @var DoliDb Database handler */
    public $db;
    
    /** @var array Errors */
    public $errors = array();
    
    /** @var ShopifyWebhooks Instance de la gestion Shopify */
    private $shopifyWebhooks;
    
    /** @var OrderWebhookHandler Handler pour les webhooks de commandes */
    private $orderHandler;
    
    /** @var InventoryWebhookHandler Handler pour les webhooks d'inventaire */
    private $inventoryHandler;
    
    /** @var ProductWebhookHandler Handler pour les webhooks de produits */
    private $productHandler;
    
    /**
     * Constructor
     *
     * @param DoliDb $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->shopifyWebhooks = new ShopifyWebhooks($db);
        // Handlers are lazy-loaded in loadHandlers() only when processing webhooks
    }

    /**
     * Instancie StoreService pour l'entité donnée.
     * Méthode protégée pour permettre le mock en test (pattern CRONs 47-3/47-4).
     *
     * @param  int $entity Entité Dolibarr
     * @return StoreService
     */
    protected function createStoreServiceForRouting(int $entity): StoreService
    {
        return new StoreService($this->db, $entity);
    }

    /**
     * Load webhook handlers (lazy loading)
     * Only called when actually processing webhooks, not from admin pages
     *
     * @return void
     */
    private function loadHandlers()
    {
        if ($this->orderHandler !== null) {
            return; // Already loaded
        }

        require_once dirname(__FILE__) . '/../webhooks/handlers/OrderWebhookHandler.php';
        require_once dirname(__FILE__) . '/../webhooks/handlers/InventoryWebhookHandler.php';
        require_once dirname(__FILE__) . '/../webhooks/handlers/ProductWebhookHandler.php';

        $this->orderHandler = new OrderWebhookHandler($this->db);
        $this->inventoryHandler = new InventoryWebhookHandler($this->db);
        $this->productHandler = new ProductWebhookHandler($this->db);
    }
    
    /**
     * Receive and store a webhook event (storage only, no processing)
     *
     * Call processEvent() separately after sending HTTP response for async pattern.
     *
     * @param string $payload      Raw payload from webhook
     * @param string $hmacHeader   HMAC header for verification
     * @param string $topic        Webhook topic (e.g., 'orders/create')
     * @param string $domain       Shop domain
     * @param string $apiVersion   API version
     * @param string $webhookId    Webhook ID from Shopify
     * @return int >0 = eventId (stored, needs processing), 0 = duplicate, <0 = error
     */
    public function receive($payload, $hmacHeader, $topic, $domain, $apiVersion, $webhookId)
    {
        global $conf;

        // Générer un ID synthétique si le header X-Shopify-Webhook-Id est absent
        // Evite la collision UNIQUE index pour les webhooks sans ID
        if (empty($webhookId)) {
            $webhookId = 'noid_'.md5($payload.$topic.microtime(true));
            $this->log("WebhookManager::receive - Missing webhook_id, generated synthetic: ".$webhookId, LOG_WARNING);
        }

        $this->log("WebhookManager::receive - topic=".$topic." domain=".$domain." webhookId=".$webhookId, LOG_DEBUG);

        // Vérifier la signature HMAC
        if (!$this->shopifyWebhooks->verifyWebhookHMAC($payload, $hmacHeader)) {
            $this->errors[] = "Invalid HMAC signature";
            return -1;
        }

        // Vérifier si le webhook a déjà été reçu (déduplication)
        if ($this->isWebhookAlreadyProcessed($webhookId, $conf->entity)) {
            return 0; // Doublon détecté, pas de traitement
        }

        // Stocker l'événement webhook avec protection race condition (ON DUPLICATE KEY UPDATE)
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sql .= " (webhook_id, topic, shop_domain, api_version, payload, hmac_header, verified, status, tries, date_reception, entity)";
        $sql .= " VALUES (";
        $sql .= " '".$this->db->escape($webhookId)."',";
        $sql .= " '".$this->db->escape($topic)."',";
        $sql .= " '".$this->db->escape($domain)."',";
        $sql .= " '".$this->db->escape($apiVersion)."',";
        $sql .= " '".$this->db->escape($payload)."',";
        $sql .= " '".$this->db->escape($hmacHeader)."',";
        $sql .= " 1,"; // verified = 1
        $sql .= " 0,"; // status = 0 (pending)
        $sql .= " 0,"; // tries = 0 (incrémenté par updateEventStatus)
        $sql .= " NOW(),";
        $sql .= " ".((int) $conf->entity);
        $sql .= ")";
        $sql .= " ON DUPLICATE KEY UPDATE";
        $sql .= " tries = tries"; // No-op update pour déclencher affected_rows=2 (détection race condition)

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = $this->db->lasterror();
            $this->log("WebhookManager::receive - Error storing webhook: ".$this->db->lasterror(), LOG_ERR);
            return -1;
        }

        // Détecter si ON DUPLICATE KEY UPDATE s'est déclenché (race condition)
        // MySQL affected_rows: 1 = nouvel insert, 2 = update via ON DUPLICATE KEY
        $affectedRows = $this->db->affected_rows($resql);
        if ($affectedRows == 2) {
            $this->log("WebhookManager::receive - Duplicate webhook ".$webhookId." detected via ON DUPLICATE KEY", LOG_INFO);
            return 0; // Doublon détecté via race condition
        }
        if ($affectedRows != 1) {
            $this->log("WebhookManager::receive - Unexpected affected_rows=".$affectedRows." for webhook ".$webhookId, LOG_WARNING);
        }

        $eventId = $this->db->last_insert_id(MAIN_DB_PREFIX."doli2shop_webhook_events");
        if (empty($eventId)) {
            // Fallback : retrouver le rowid via SELECT
            $this->log("WebhookManager::receive - last_insert_id returned 0 for webhook ".$webhookId.", attempting SELECT fallback", LOG_WARNING);
            $sqlFind = "SELECT rowid FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
            $sqlFind .= " WHERE webhook_id = '".$this->db->escape($webhookId)."'";
            $sqlFind .= " AND entity = ".((int) $conf->entity);
            $resFind = $this->db->query($sqlFind);
            if ($resFind && $this->db->num_rows($resFind) > 0) {
                $obj = $this->db->fetch_object($resFind);
                $eventId = $obj->rowid;
            }
            if (empty($eventId)) {
                $this->errors[] = "Cannot determine eventId for webhook ".$webhookId;
                $this->log("WebhookManager::receive - Cannot determine eventId for webhook ".$webhookId.", aborting", LOG_ERR);
                return -1;
            }
        }
        $this->log("WebhookManager::receive - Webhook ".$webhookId." stored with status pending, eventId=".$eventId, LOG_DEBUG);

        return $eventId;
    }

    /**
     * Check if a webhook has already been received and processed (deduplication)
     *
     * @param string $webhookId  X-Shopify-Webhook-Id header value
     * @param int    $entity     Entity ID for multi-entity support
     * @return bool  true if already processed (duplicate), false otherwise
     */
    public function isWebhookAlreadyProcessed($webhookId, $entity)
    {
        if (empty($webhookId)) {
            return false;
        }

        $sql = "SELECT status FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sql .= " WHERE webhook_id = '".$this->db->escape($webhookId)."'";
        $sql .= " AND entity = ".((int) $entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("WebhookManager::isWebhookAlreadyProcessed - DB error checking webhook ".$webhookId.": ".$this->db->lasterror(), LOG_WARNING);
            // Fallback : laisser passer, le INSERT ON DUPLICATE KEY protège en aval
            return false;
        }
        if ($this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            // Tout status existant (0=pending, 1=processed, 2=error, 3=processing) -> doublon
            // Les events en erreur seront purgés par purgeOldEvents() ou investigués par l'admin
            $this->log("WebhookManager::isWebhookAlreadyProcessed - Webhook ".$webhookId." already processed (status=".$obj->status."), skipping", LOG_INFO);
            return true;
        }
        $this->db->free($resql);
        return false;
    }
    
    /**
     * Process a stored webhook event
     * Can be called inline (after flush) or by CRON (processPendingEvents)
     *
     * Uses atomic claim (status 0→3) to prevent race condition between
     * inline processing and CRON processing the same event concurrently.
     *
     * @param int         $eventId    Event ID in database
     * @param string      $topic      Webhook topic
     * @param string      $payload    Raw payload
     * @param string|null $shopDomain Shop domain from X-Shopify-Shop-Domain header (null = rétrocompat)
     * @return int <0 if KO, >0 if OK
     */
    public function processEvent($eventId, $topic, $payload, $shopDomain = null)
    {
        global $conf;

        $startTime = microtime(true);

        $this->log("WebhookManager::processEvent - Processing event ".$eventId.", topic=".$topic, LOG_DEBUG);

        // Atomic claim : transition status 0 (pending) → 3 (processing)
        // Empêche le traitement concurrent par inline ET CRON
        $sqlClaim = "UPDATE ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sqlClaim .= " SET status = 3, date_traitement = NOW()"; // 3 = processing, date_traitement = heure de début
        $sqlClaim .= " WHERE rowid = ".((int) $eventId);
        $sqlClaim .= " AND status = 0"; // Seulement si encore pending
        $resClaim = $this->db->query($sqlClaim);
        if (!$resClaim || $this->db->affected_rows($resClaim) == 0) {
            $this->log("WebhookManager::processEvent - Event ".$eventId." already claimed or processed, skipping", LOG_INFO);
            // Story 3.2: Marquer comme 'duplicate' si action_result pas encore set
            $sqlCheck = "SELECT action_result FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events WHERE rowid = ".((int) $eventId);
            $resCheck = $this->db->query($sqlCheck);
            if ($resCheck) {
                $objCheck = $this->db->fetch_object($resCheck);
                if ($objCheck && empty($objCheck->action_result)) {
                    $sqlDup = "UPDATE ".MAIN_DB_PREFIX."doli2shop_webhook_events";
                    $sqlDup .= " SET action_result = 'duplicate'";
                    $sqlDup .= " WHERE rowid = ".((int) $eventId);
                    $sqlDup .= " AND action_result IS NULL";
                    $this->db->query($sqlDup);
                }
                $this->db->free($resCheck);
            }
            return 1; // Pas une erreur, déjà pris en charge
        }

        // Lazy load handlers only when needed for processing
        $this->loadHandlers();

        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $processingTimeMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->updateEventStatus($eventId, 2, "Invalid JSON payload", $processingTimeMs, null, null, 'error');
            return -1;
        }

        // Epic 47-4 : résolution de la boutique depuis shop_domain (triple fallback)
        // Résoudre une seule fois ici et passer aux handlers via paramètre handleWebhook($topic, $data, $store).
        // Appel INCONDITIONNEL : resolveStoreForRouting() gère shop_domain null/vide (→ boutique
        // par défaut, sinon null = chemin historique). La condition précédente court-circuitait
        // ce fallback pour un shop_domain null (review HIGH-1).
        $storeService = $this->createStoreServiceForRouting((int) $conf->entity);
        $routedStore = $storeService->resolveStoreForRouting($shopDomain);

        // Story 47-6 : gate licence par boutique. $routedStore null → jamais bloqué (chemin
        // rétrocompat entité/constantes, hors périmètre du gate).
        // ⚠️ Story licence-gate-des-ecritures-sortantes (AC1) : avant ce correctif, la boutique
        // PAR DÉFAUT court-circuitait entièrement l'appel (`empty($routedStore->is_default)`),
        // donc `doli2shopStoreSyncAllowed()` n'était même pas invoquée pour elle — soit 100% du
        // parc DoliStore jamais gaté sur ce flux. La boutique par défaut passe désormais par le
        // même appel ; c'est `doli2shopEvaluateDefaultStoreSyncGate()` (interne à la fonction) qui
        // reste lenient tant qu'aucune date d'arrêt signée n'a été reçue du serveur.
        if ($routedStore !== null) {
            $licenceCheck = doli2shopStoreSyncAllowed($routedStore);
            if (!$licenceCheck['allowed']) {
                // Blind Hunter (licence-gate-des-ecritures-sortantes) : invariant 49-8 — la
                // boutique par défaut n'est JAMAIS écrite en DB par un flux automatique, ici le
                // webhook, seulement via le bouton « Vérifier maintenant ». Avant cette story ce
                // chemin ne pouvait pas être atteint pour is_default (court-circuité plus haut) ;
                // il l'est désormais, et écrivait 'invalid' sans garde — y compris quand le
                // blocage vient d'une panne de NOTRE serveur (failopen_expired), pas d'une
                // licence réellement invalide.
                if (empty($routedStore->is_default)) {
                    $storeService->setLicenseStatus((int) $routedStore->rowid, 'invalid');
                }
                $processingTimeMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->log(
                    "WebhookManager::processEvent - Event " . $eventId . " skipped: synchronisation non autorisée"
                    . " shop_domain=" . ($routedStore->shop_domain ?? '?')
                    . " reason=" . $licenceCheck['reason'],
                    LOG_WARNING
                );
                // Marquer traité/skipped (pas retry infini) avec raison licence
                $this->updateEventStatus($eventId, 1, null, $processingTimeMs, null, null, 'skipped_license');
                return 0;
            }
            // Même invariant 49-8 que ci-dessus, pour le cas "autorisé" : ne pas écraser le
            // statut réel de la boutique par défaut en DB depuis un flux automatique.
            if (empty($routedStore->is_default)) {
                $storeService->setLicenseStatus((int) $routedStore->rowid, $licenceCheck['status']);
            }
        }

        $result = 1;
        $error = null;
        $handler = null;

        // Dispatcher selon le topic avec protection contre les exceptions
        // Sans try-catch, une exception empêcherait updateEventStatus() et
        // laisserait l'event en status=3 avec tries jamais incrémenté
        try {
            switch ($topic) {
                case 'products/create':
                case 'products/update':
                case 'products/delete':
                    $handler = $this->productHandler;
                    $result = $handler->handleWebhook($topic, $data, $routedStore);
                    if ($result < 0) {
                        $error = implode(', ', $handler->errors);
                    }
                    break;

                case 'orders/create':
                case 'orders/updated':
                case 'orders/cancelled':
                case 'orders/fulfilled':
                case 'orders/paid':
                case 'orders/partially_fulfilled':
                    $handler = $this->orderHandler;
                    $result = $handler->handleWebhook($topic, $data, $routedStore);
                    if ($result < 0) {
                        $error = implode(', ', $handler->errors);
                    }
                    break;

                case 'inventory_levels/update':
                    $handler = $this->inventoryHandler;
                    $result = $handler->handleWebhook($topic, $data, $routedStore);
                    if ($result < 0) {
                        $error = implode(', ', $handler->errors);
                    }
                    break;

                case 'app/uninstalled':
                    // Story 47-7 : désinstallation ciblée par boutique via fk_store.
                    // Le header $shopDomain est passé comme fallback si myshopify_domain
                    // est absent du payload.
                    $result = $this->handleAppUninstalled($data, $shopDomain);
                    break;

                default:
                    $error = "Unknown webhook topic: ".$topic;
                    $result = -1;
                    break;
            }
        } catch (\Throwable $e) {
            $error = "Exception: ".$e->getMessage();
            $result = -1;
            $this->log("WebhookManager::processEvent - Exception processing event ".$eventId.": ".$e->getMessage(), LOG_ERR);
        }

        // Calculer processing_time_ms
        $processingTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        // Story 3.2: Lire le contexte depuis le handler utilisé
        $dolibarrObjectType = null;
        $dolibarrObjectId = null;
        $actionResult = ($result > 0) ? 'success' : 'error';

        if ($handler !== null) {
            $dolibarrObjectType = $handler->lastObjectType;
            $dolibarrObjectId = $handler->lastObjectId;
            if ($handler->lastActionResult !== null) {
                $actionResult = $handler->lastActionResult;
            }
        }

        // Story 36.2 : en cas d'ÉCHEC RÉEL (result < 0, pas skipped/duplicate qui valent 0),
        // marquer dead_letter si le seuil de tentatives est atteint (updateEventStatus
        // incrémentera tries → seuil = tries actuel + 1). Un event dead_letter (status=2)
        // n'est plus repris par le CRON (filtre status=0).
        if ($result < 0) {
            $maxTries = getDolGlobalInt('DOLI2SHOP_WEBHOOK_MAX_TRIES', 5);
            $currentTries = 0;
            $resTries = $this->db->query("SELECT tries FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events WHERE rowid = ".((int) $eventId));
            if ($resTries) {
                $objTries = $this->db->fetch_object($resTries);
                if ($objTries) {
                    $currentTries = (int) $objTries->tries;
                }
                $this->db->free($resTries);
            }
            if (($currentTries + 1) >= $maxTries) {
                $actionResult = 'dead_letter';
                $this->log("WebhookManager::processEvent - Event ".$eventId." reached max tries (".$maxTries."), marked dead_letter", LOG_WARNING);
            }
        }

        // Mettre à jour le statut de l'événement (status 3 → 1 ou 2)
        $status = ($result > 0) ? 1 : 2; // 1=traité, 2=erreur
        $this->updateEventStatus($eventId, $status, $error, $processingTimeMs, $dolibarrObjectType, $dolibarrObjectId, $actionResult);

        $this->log("WebhookManager::processEvent - Event ".$eventId." processed in ".$processingTimeMs."ms, status=".$status
            ." objectType=".($dolibarrObjectType ? $dolibarrObjectType : 'null')
            ." objectId=".($dolibarrObjectId ? $dolibarrObjectId : 'null')
            ." actionResult=".$actionResult, LOG_INFO);

        return $result;
    }
    
    /**
     * Update event status
     *
     * @param int    $eventId            Event ID
     * @param int    $status             Status (0=pending, 1=processed, 2=error)
     * @param string $error              Error message if any
     * @param int    $processingTimeMs   Processing time in milliseconds (null to skip)
     * @param string $dolibarrObjectType Dolibarr object type: commande, facture, shipping, product (null to skip)
     * @param int    $dolibarrObjectId   Dolibarr object rowid (null to skip)
     * @param string $actionResult       Result: success, error, duplicate, skipped (null to skip)
     * @return int <0 if KO, >0 if OK
     */
    private function updateEventStatus($eventId, $status, $error = null, $processingTimeMs = null, $dolibarrObjectType = null, $dolibarrObjectId = null, $actionResult = null)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."doli2shop_webhook_events SET";
        $sql .= " status = ".((int) $status);
        if ($status == 1) {
            $sql .= ", date_traitement = NOW()";
        }
        if ($error !== null) {
            $sql .= ", last_error = '".$this->db->escape($error)."'";
        }
        if ($processingTimeMs !== null) {
            $sql .= ", processing_time_ms = ".((int) $processingTimeMs);
        }
        if ($dolibarrObjectType !== null) {
            $sql .= ", dolibarr_object_type = '".$this->db->escape($dolibarrObjectType)."'";
        }
        if ($dolibarrObjectId !== null) {
            $sql .= ", dolibarr_object_id = ".((int) $dolibarrObjectId);
        }
        if ($actionResult !== null) {
            $sql .= ", action_result = '".$this->db->escape($actionResult)."'";
        }
        $sql .= ", tries = tries + 1";
        $sql .= " WHERE rowid = ".((int) $eventId);

        $resql = SqlUtils::executeQuery($this->db, $sql, "WebhookManager::updateEventStatus", true, array(), 3);

        return ($resql >= 0) ? 1 : -1;
    }
    
    /**
     * Handle app uninstalled webhook
     *
     * Story 47-7 : filtrage par boutique via fk_store.
     * Résolution de la boutique depuis le myshopify_domain du payload (canonical),
     * avec fallback sur domain si myshopify_domain absent.
     *
     * Sécurité : si le shop_domain ne résout aucune boutique → log warning + no-op
     * (mieux vaut laisser des webhooks actifs que de tout désactiver à tort).
     *
     * Rétrocompat mono-boutique : la boutique par défaut est résolue depuis
     * shop_domain (seeding 47-1 = shop_domain = constante DOLI2SHOP_STORE_HOSTNAME),
     * ses webhooks (backfillés avec son rowid) sont désactivés = comportement actuel.
     *
     * @param array       $data       Webhook data (payload JSON décodé)
     * @param string|null $shopDomain Shop domain reçu dans X-Shopify-Shop-Domain (header routing)
     * @return int <0 if KO, >0 if OK
     */
    private function handleAppUninstalled($data, $shopDomain = null)
    {
        global $conf;

        // Résolution canonique : myshopify_domain (prioritaire) puis domain, puis header routing
        $payloadMyshopify = isset($data['myshopify_domain']) ? (string) $data['myshopify_domain'] : '';
        $payloadDomain    = isset($data['domain'])            ? (string) $data['domain']            : '';

        // Utiliser le myshopify_domain du payload en priorité (c'est le domaine stocké en base),
        // fallback sur domain si absent, puis sur le header routing
        $resolveKey = '';
        if (!empty($payloadMyshopify)) {
            $resolveKey = $payloadMyshopify;
        } elseif (!empty($payloadDomain)) {
            $resolveKey = $payloadDomain;
        } elseif (!empty($shopDomain)) {
            $resolveKey = $shopDomain;
        }

        $this->log(
            "WebhookManager::handleAppUninstalled - App uninstalled"
            . " payloadMyshopify=" . $payloadMyshopify
            . " payloadDomain=" . $payloadDomain
            . " headerShopDomain=" . ($shopDomain ?? 'null')
            . " resolveKey=" . $resolveKey,
            LOG_INFO
        );

        // Résoudre la boutique via StoreService (factory mockable, cohérent avec processEvent)
        $storeService = $this->createStoreServiceForRouting((int) $conf->entity);

        // Tentative directe par myshopify_domain (getByShopDomain) — correspond exactement
        // au champ shop_domain de llx_doli2shop_stores (peuplé par seeding/create)
        $store = null;
        if (!empty($resolveKey)) {
            $store = $storeService->getByShopDomain($resolveKey);
            // Si myshopify_domain n'a pas matché et qu'on a aussi le domain custom, tenter celui-ci
            if ($store === null && !empty($payloadMyshopify) && !empty($payloadDomain) && $payloadDomain !== $payloadMyshopify) {
                $store = $storeService->getByShopDomain($payloadDomain);
            }
        }

        if ($store === null) {
            // Sécurité : boutique non résolue → ne pas désactiver en masse
            $this->log(
                "WebhookManager::handleAppUninstalled - Boutique non résolue pour resolveKey='" . $resolveKey . "'"
                . " — désactivation annulée (sécurité : évite de couper les autres boutiques)",
                LOG_WARNING
            );
            // Retourner 1 (pas d'erreur) : l'event est traité, la sécurité a joué
            return 1;
        }

        $fkStore = (int) $store->rowid;
        $this->log(
            "WebhookManager::handleAppUninstalled - Boutique résolue rowid=" . $fkStore
            . " label=\"" . ($store->label ?? '') . "\""
            . " — désactivation des webhooks fk_store=" . $fkStore,
            LOG_INFO
        );

        // Désactiver uniquement les webhooks de cette boutique.
        // Cas legacy (review MEDIUM) : une install dont la migration 2.3.4_2.3.5 est passée
        // mais le backfill PHP pas encore exécuté a des webhooks à fk_store=0. Ces webhooks
        // « non assignés » appartiennent à la boutique par DÉFAUT → on les inclut UNIQUEMENT
        // quand la boutique résolue est la boutique par défaut (jamais pour une secondaire,
        // sinon on couperait les webhooks non encore assignés d'autres boutiques).
        $isDefault = !empty($store->is_default);
        $sql  = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_webhooks SET";
        $sql .= " status = 0";
        $sql .= " WHERE entity = " . ((int) $conf->entity);
        if ($isDefault) {
            $sql .= " AND fk_store IN (" . $fkStore . ", 0)";
        } else {
            $sql .= " AND fk_store = " . $fkStore;
        }

        $resql = SqlUtils::executeQuery($this->db, $sql, "WebhookManager::handleAppUninstalled", true, array(), 3);

        if ($resql === false) {
            $this->errors[] = $this->db->lasterror();
            return -1;
        }

        return 1;
    }
    
    /**
     * Process pending webhook events
     * This method should be called by a cron task
     *
     * Also recovers stuck events (status=3) that have been in processing
     * state for more than 5 minutes (inline processing crashed or timed out).
     *
     * @param int $limit Max number of events to process
     * @return int Number of events processed, -1 if error
     */
    public function processPendingEvents($limit = 100)
    {
        global $conf;

        $this->log("WebhookManager::processPendingEvents - limit=".$limit, LOG_DEBUG);

        // Récupérer les events stuck en status=3 (processing) depuis plus de 5 minutes
        // Cela signifie que le traitement inline a crashé ou timeout
        $stuckTimeout = getDolGlobalInt('DOLI2SHOP_WEBHOOK_STUCK_TIMEOUT_MIN', 5); // Story 36.3
        if ($stuckTimeout < 1) {
            $stuckTimeout = 5;
        }
        $sqlReset = "UPDATE ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sqlReset .= " SET status = 0"; // Remettre en pending pour retry
        // Story 36.3 : trace de récupération automatique (détectable dans le Log Viewer)
        $sqlReset .= ", last_error = CONCAT(COALESCE(last_error, ''), ' [auto-reset stuck:', NOW(), ']')";
        $sqlReset .= " WHERE status = 3"; // En cours de traitement (stuck)
        $sqlReset .= " AND TIMESTAMPDIFF(MINUTE, date_traitement, NOW()) > ".((int) $stuckTimeout);
        $sqlReset .= " AND entity = ".((int) $conf->entity);
        $resReset = $this->db->query($sqlReset);
        if ($resReset) {
            $resetCount = $this->db->affected_rows($resReset);
            if ($resetCount > 0) {
                $this->log("WebhookManager::processPendingEvents - Reset ".$resetCount." stuck processing events to pending", LOG_WARNING);
            }
        }

        $maxTries = getDolGlobalInt('DOLI2SHOP_WEBHOOK_MAX_TRIES', 5); // Story 36.2
        if ($maxTries < 1) {
            $maxTries = 5;
        }
        // Epic 47-4 : ajouter shop_domain au SELECT pour le routage par boutique
        $sql = "SELECT rowid, topic, payload, shop_domain FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sql .= " WHERE status = 0"; // En attente
        $sql .= " AND tries < ".((int) $maxTries); // Max tentatives configurable
        $sql .= " AND entity = ".((int) $conf->entity);
        $sql .= " ORDER BY date_reception ASC";
        $sql .= " LIMIT ".((int) $limit);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("WebhookManager::processPendingEvents - Error retrieving pending events: ".$this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $processed = 0;
        $failed = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            // Epic 47-4 : shop_domain passé à processEvent pour le routage par boutique
            $shopDomain = isset($obj->shop_domain) ? (string) $obj->shop_domain : null;
            try {
                $result = $this->processEvent($obj->rowid, $obj->topic, $obj->payload, $shopDomain);
                if ($result > 0) {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->log("WebhookManager::processPendingEvents - Uncaught exception for event ".$obj->rowid.": ".$e->getMessage(), LOG_ERR);
                // Continuer avec les events suivants (ne pas bloquer la boucle)
            }
        }

        $this->db->free($resql);

        if ($failed > 0) {
            $this->log("WebhookManager::processPendingEvents - Processed ".$processed.", failed ".$failed, LOG_WARNING);
        } else {
            $this->log("WebhookManager::processPendingEvents - Processed ".$processed." events", LOG_INFO);
        }

        return $processed;
    }
    
    /**
     * Purge old webhook events that have been processed
     *
     * @param int $hoursToKeep Number of hours to keep processed events (default 48)
     * @return int Number of events purged, -1 if error
     */
    public function purgeOldEvents($hoursToKeep = 0)
    {
        global $conf;

        // Use configured value if not specified
        if ($hoursToKeep == 0) {
            // v2.2.8: le paramètre UI "Jours de conservation" (admin/webhooks.php,
            // SHOPIFY_WEBHOOK_KEEP_DAYS) était sauvegardé mais jamais lu — la purge
            // utilisait toujours 48h. Il est désormais prioritaire s'il est défini.
            $keepDays = getDolGlobalInt('SHOPIFY_WEBHOOK_KEEP_DAYS', 0);
            if ($keepDays > 0) {
                $hoursToKeep = $keepDays * 24;
            } else {
                $hoursToKeep = getDolGlobalInt('DOLI2SHOP_WEBHOOK_PURGE_HOURS', 48);
            }
        }

        $this->log("WebhookManager::purgeOldEvents - hoursToKeep=".$hoursToKeep, LOG_INFO);

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sql .= " WHERE (status = 1 OR status = 2)"; // Traité ou en erreur
        $sql .= " AND COALESCE(date_traitement, date_reception) < DATE_SUB(NOW(), INTERVAL ".((int) $hoursToKeep)." HOUR)";
        $sql .= " AND entity = ".((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = $this->db->lasterror();
            $this->log("WebhookManager::purgeOldEvents - Error purging old events: ".$this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($resql);

        $this->log("WebhookManager::purgeOldEvents - Purged ".$affected." old webhook events", LOG_INFO);

        return $affected;
    }

    /**
     * Purge all webhook events beyond maximum retention, regardless of status.
     * Safety net: nothing survives beyond DOLI2SHOP_WEBHOOK_RETENTION_DAYS (default 30).
     *
     * @return int Number of purged events, or -1 on error
     * @since 2.2.0
     */
    public function purgeExpiredEvents()
    {
        global $conf;

        $retentionDays = getDolGlobalInt('DOLI2SHOP_WEBHOOK_RETENTION_DAYS', 30);
        if ($retentionDays <= 0) {
            return 0; // Disabled
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_webhook_events";
        $sql .= " WHERE date_reception < DATE_SUB(NOW(), INTERVAL " . ((int) $retentionDays) . " DAY)";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("WebhookManager::purgeExpiredEvents - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($resql);
        if ($affected > 0) {
            $this->log("WebhookManager::purgeExpiredEvents - Purged " . $affected
                . " expired events (retention=" . $retentionDays . " days)", LOG_INFO);
        }

        return $affected;
    }

    /**
     * Statistiques de temps de traitement par topic (Story 36.1).
     *
     * Calcule count / P50 / P95 / P99 / moyenne du processing_time_ms par topic
     * sur les N derniers jours, pour l'entité courante. Les percentiles sont
     * calculés EN PHP (MySQL ne fournit pas PERCENTILE_CONT — fonction Oracle/PostgreSQL),
     * ce qui garantit un comportement identique sur MySQL 5.7+ / MariaDB / MySQL 8.
     *
     * @param  int $days Fenêtre d'analyse en jours (défaut 7)
     * @return array Liste de ['topic','count','p50','p95','p99','avg'] triée par avg desc ; [] si vide
     * @since  2.3.0
     */
    public function getProcessingTimeStats($days = 7)
    {
        global $conf;

        $days = (int) $days;
        if ($days <= 0) {
            $days = 7;
        }
        $entity = (int) $conf->entity;

        // 1) Agrégat count + moyenne par topic
        $sql = "SELECT topic, COUNT(*) as nb, AVG(processing_time_ms) as avg_ms";
        $sql .= " FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sql .= " WHERE status = 1 AND processing_time_ms IS NOT NULL";
        $sql .= " AND date_reception >= DATE_SUB(NOW(), INTERVAL ".$days." DAY)";
        $sql .= " AND entity = ".$entity;
        $sql .= " GROUP BY topic ORDER BY avg_ms DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("WebhookManager::getProcessingTimeStats - SQL error: ".$this->db->lasterror(), LOG_ERR);
            return array();
        }

        $topics = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $topics[] = array('topic' => $obj->topic, 'count' => (int) $obj->nb, 'avg' => (int) round($obj->avg_ms));
        }
        $this->db->free($resql);

        if (empty($topics)) {
            return array();
        }

        if (count($topics) > 30) {
            $this->log("WebhookManager::getProcessingTimeStats - ".count($topics)." distinct topics (N+1 percentile queries)", LOG_WARNING);
        }

        // 2) Percentiles PHP par topic (jusqu'à 5000 valeurs/topic)
        $stats = array();
        foreach ($topics as $t) {
            $sqlVals = "SELECT processing_time_ms FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
            $sqlVals .= " WHERE status = 1 AND processing_time_ms IS NOT NULL";
            $sqlVals .= " AND topic = '".$this->db->escape($t['topic'])."'";
            $sqlVals .= " AND date_reception >= DATE_SUB(NOW(), INTERVAL ".$days." DAY)";
            $sqlVals .= " AND entity = ".$entity;
            $sqlVals .= " ORDER BY processing_time_ms ASC";
            $sqlVals .= " LIMIT 5000";

            $values = array();
            $resVals = $this->db->query($sqlVals);
            if ($resVals) {
                while ($v = $this->db->fetch_object($resVals)) {
                    $values[] = (int) $v->processing_time_ms;
                }
                $this->db->free($resVals);
            }

            $stats[] = array(
                'topic' => $t['topic'],
                'count' => $t['count'],
                'p50' => $this->percentile($values, 0.50),
                'p95' => $this->percentile($values, 0.95),
                'p99' => $this->percentile($values, 0.99),
                'avg' => $t['avg'],
            );
        }

        return $stats;
    }

    /**
     * Percentile (nearest-rank) sur un tableau de valeurs DÉJÀ trié croissant.
     *
     * @param  array $sortedValues Valeurs entières triées asc
     * @param  float $p            Percentile [0..1]
     * @return int|null  Valeur du percentile, ou null si tableau vide
     * @since  2.3.0
     */
    private function percentile($sortedValues, $p)
    {
        $n = count($sortedValues);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return (int) $sortedValues[0];
        }
        // Nearest-rank : rang = ceil(p * n), index = rang-1, borné à [0, n-1]
        $rank = (int) ceil($p * $n);
        $idx = $rank - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx > $n - 1) {
            $idx = $n - 1;
        }
        return (int) $sortedValues[$idx];
    }

    /**
     * Rejoue un lot d'événements en les remettant en attente (Story 36.2).
     *
     * Réinitialise status=0, tries=0, last_error=NULL, action_result=NULL pour les
     * events sélectionnés (de l'entité courante). Le CRON les reprendra au prochain passage.
     * Couvre aussi le « forçage » des dead_letter (même reset, sans condition de seuil).
     *
     * @param  array $eventIds Liste de rowid (valeurs assainies en interne)
     * @return int  Nombre d'events effectivement réinitialisés, -1 en erreur SQL
     * @since  2.3.0
     */
    public function replayBatch($eventIds)
    {
        global $conf;

        if (!is_array($eventIds) || empty($eventIds)) {
            return 0;
        }

        // Assainir : entiers positifs uniquement
        $safeIds = array_filter(array_map('intval', $eventIds), function ($id) {
            return $id > 0;
        });
        if (empty($safeIds)) {
            return 0;
        }

        $sql = "UPDATE ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sql .= " SET status = 0, tries = 0, last_error = NULL, action_result = NULL";
        $sql .= " WHERE rowid IN (".implode(',', $safeIds).")";
        $sql .= " AND entity = ".((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("WebhookManager::replayBatch - SQL error: ".$this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($resql);
        $this->log("WebhookManager::replayBatch - Reset ".$affected." events for retry", LOG_INFO);

        return $affected;
    }

    /**
     * Purge webhook events linked to a specific Dolibarr order.
     * Called on ORDER_CLOSE trigger to clean up events for completed orders.
     *
     * @param int $dolibarrOrderId Dolibarr order rowid
     * @return int Number of purged events, or -1 on error
     * @since 2.2.0
     */
    public function purgeEventsByOrderId($dolibarrOrderId)
    {
        global $conf;

        if (empty($dolibarrOrderId) || (int) $dolibarrOrderId <= 0) {
            return 0;
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_webhook_events";
        $sql .= " WHERE dolibarr_object_type = 'commande'";
        $sql .= " AND dolibarr_object_id = " . ((int) $dolibarrOrderId);
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("WebhookManager::purgeEventsByOrderId - SQL error for order #"
                . $dolibarrOrderId . ": " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($resql);
        if ($affected > 0) {
            $this->log("WebhookManager::purgeEventsByOrderId - Purged " . $affected
                . " events for order #" . $dolibarrOrderId, LOG_INFO);
        }

        return $affected;
    }
}