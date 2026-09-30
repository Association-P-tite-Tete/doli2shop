<?php
/**
 * @file        class/shopifyfulfillmentcatchupcron.class.php
 * @brief       CRON de rattrapage des expéditions manquantes depuis fulfillments Shopify
 *
 * Détecte les commandes Dolibarr marquées "fulfilled" dans Shopify mais sans bon
 * d'expédition Dolibarr. Re-fetch les fulfillments via API et tente la création.
 * Filet de sécurité complémentaire au retry webhook (WebhookProcessCron).
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.2.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/storesettings.class.php';
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
dol_include_once('/doli2shop/class/shopifyordermanager.class.php');
dol_include_once('/doli2shop/class/shopifyfulfillmentmanager.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/expedition/class/expedition.class.php');
dol_include_once('/compta/facture/class/facture.class.php');

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

/**
 * Class ShopifyFulfillmentCatchupCron
 *
 * CRON de rattrapage des expéditions manquantes.
 * Exécuté toutes les 30 minutes pour :
 * 1. Trouver les commandes Dolibarr liées à des commandes Shopify fulfilled
 * 2. Vérifier si elles ont un bon d'expédition Dolibarr
 * 3. Si non, re-fetcher les fulfillments Shopify et tenter la création
 *
 * @since 2.2.0
 */
class ShopifyFulfillmentCatchupCron
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDB */
    private $db;

    /** @var string CRON output message */
    public $output = '';

    /** @var int Error count */
    public $error = 0;

    /** @var array Error messages */
    public $errors = array();

    /**
     * @var int Entité Dolibarr courante (résolue en tête d'executeCron(), consommée par
     *          createSyncFlowPolicy() — Story 53-3, site 8)
     *
     * ⚠️ DOIT RESTER `public` : le planificateur du core ÉCRIT directement dessus avant
     * d'appeler la méthode — `$object->entity = $this->entity;` (cron/class/cronjob.class.php,
     * run_jobs()). Déclarée `private`, PHP lève « Cannot access private property » et le job
     * meurt en Fatal error avant d'avoir rien exécuté. Constaté en production le 2026-08-13.
     * Les autres crons du module n'ont pas de déclaration du tout, ce qui laisse le core créer
     * une propriété dynamique — toléré, mais déprécié depuis PHP 8.2.
     */
    public $entity = 1;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Instancie StoreService pour l'entité donnée.
     * Méthode protégée pour permettre le mock en test (pattern ImportProductsCron).
     *
     * @param  int $entity Entité Dolibarr
     * @return StoreService
     */
    protected function createStoreService(int $entity): StoreService
    {
        return new StoreService($this->db, $entity);
    }

    /**
     * Factory protégée SyncFlowPolicy (Story 53-3, site 8 — remplace createStoreSettings() +
     * isAutoCreateExpeditionEnabledForStore(StoreSettings, storeId) ; la policy encapsule déjà
     * son propre accesseur StoreSettings, le paramètre StoreSettings devient donc inutile).
     * Utilise $this->entity, résolu en tête d'executeCron() avant tout appel à cette factory.
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy(): SyncFlowPolicy
    {
        return new SyncFlowPolicy($this->db, $this->entity);
    }

    /**
     * Indique si la création automatique d'expédition (Phase 1, flux shipping s2d) est activée
     * pour une boutique.
     *
     * Story 53-3 (site 8) : remplace isAutoCreateExpeditionEnabledForStore(StoreSettings, storeId)
     * — appuyé sur SyncFlowPolicy (dérivation legacy AUTO_CREATE_EXPEDITION per-store avec
     * fallback constante globale, équivalence prouvée SyncFlowPolicyTest.php:397-425). storeId=0
     * (chemin historique/fallback) : retombe systématiquement sur la constante globale
     * (invariant mono-boutique inchangé).
     *
     * @param  int $storeId Identifiant boutique (rowid)
     * @return bool
     */
    protected function isShippingInboundAllowedForStore(int $storeId): bool
    {
        return $this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_SHIPPING, 'shopify_to_dolibarr', $storeId);
    }

    /**
     * Execute cron job for fulfillment catchup
     *
     * Epic 47-4 Architecture :
     * - Phase 1 (créer expéditions manquantes via API Shopify) : boucle PAR BOUTIQUE.
     *   Seule la Phase 1 nécessite les credentials Shopify → itération sur boutiques actives.
     * - Phases 2-4 (clôture expéditions, factures 0€, fermeture commandes) : niveau ENTITÉ.
     *   Ces phases agissent sur objets Dolibarr uniquement, pas d'appel Shopify.
     *   Gardes idempotents existants ; boucler par boutique serait redondant.
     *
     * @param object $conf  Config object (unused, global $conf used instead)
     * @param object $langs Lang object (unused)
     * @return int 0 if OK, <0 if error
     */
    public function executeCron($conf = null, $langs = null)
    {
        global $conf, $user;

        try {
            $this->output = '';
            $this->error = 0;
            $this->errors = array();

            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;
            // Story 53-3 : $this->entity consommé par createSyncFlowPolicy() (site 8)
            $this->entity = $entity;
            $startTime = microtime(true);

            $versionInfo = $this->getModuleVersionInfo();
            $this->log("ShopifyFulfillmentCatchupCron::executeCron - START - Entity: {$entity} | v{$versionInfo['version']}", LOG_INFO);

            // Protection timeout : 5 minutes max
            $oldTimeLimit = (int)ini_get('max_execution_time');
            if ($oldTimeLimit < 300) {
                @set_time_limit(300);
            }

            // Auto-detection et deblocage CRON bloqué
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/shopifyfulfillmentcatchupcron.class.php');

            // Story 36.4 : verrou anti double-exécution au niveau CRON (AC #8, non par boutique)
            if (!$this->acquireCronLock('fulfillment_catchup')) {
                $this->log("ShopifyFulfillmentCatchupCron::executeCron - Another instance is already running, skipping", LOG_INFO);
                $this->output = 'Fulfillment catchup skipped: another instance is already running';
                @set_time_limit($oldTimeLimit);
                return 0;
            }

            // Story 50-1 : StoreService récupéré AVANT le gate pour permettre la décision "global
            // OU au moins une boutique avec override actif" ci-dessous, et réutilisé tel quel dans
            // la boucle Phase 1 (pas de double fetch).
            $storeService = $this->createStoreService($entity);
            $stores = $storeService->getAll(true);

            // Vérifier que la feature est activée : globalement OU via un override actif sur au
            // moins une boutique (Story 50-1). Auparavant, une boutique secondaire avec
            // AUTO_CREATE_EXPEDITION activé en override per-store était ignorée dès lors que la
            // constante globale était à 0 — le gate stoppait TOUT le CRON avant la boucle boutiques.
            // Story 53-3 (site 8) : le gate consulte désormais SyncFlowPolicy (flux shipping, s2d)
            // au lieu de lire directement la constante DOLI2SHOP_AUTO_CREATE_EXPEDITION.
            $globalShippingInboundAllowed = $this->isShippingInboundAllowedForStore(0);
            $anyStoreOverrideActive = false;
            if (!$globalShippingInboundAllowed) {
                foreach ($stores as $store) {
                    $storeIdForGate = (int) ($store->rowid ?? 0);
                    if ($storeIdForGate > 0 && $this->isShippingInboundAllowedForStore($storeIdForGate)) {
                        $anyStoreOverrideActive = true;
                        break;
                    }
                }
            }
            if (!$globalShippingInboundAllowed && !$anyStoreOverrideActive) {
                $this->log("ShopifyFulfillmentCatchupCron::executeCron - DOLI2SHOP_AUTO_CREATE_EXPEDITION disabled globally and no store override active, skipping", LOG_DEBUG);
                $this->output = 'Fulfillment catchup skipped: auto-create expedition disabled';
                @set_time_limit($oldTimeLimit);
                return 0;
            }

            // Lookback configurable (défaut: 48h)
            $lookbackHours = getDolGlobalInt('DOLI2SHOP_FULFILLMENT_CATCHUP_HOURS', 48);

            $counters = array(
                'checked'        => 0,
                'created'        => 0,
                'skipped'        => 0,
                'failed'         => 0,
                'closed'         => 0,
                'invoices_fixed' => 0,
                'orders_closed'  => 0
            );

            // ── Phase 1 : Créer les expéditions manquantes — boucle PAR BOUTIQUE (Epic 47-4) ──
            // Seule la Phase 1 nécessite les credentials Shopify (fetch fulfillments via GraphQL).
            // Les Phases 2-4 agissent sur objets Dolibarr → restent au niveau entité.
            // ($storeService / $stores déjà récupérés plus haut pour le gate — Story 50-1)
            if (empty($stores)) {
                // Rétrocompat : 0 boutique → run unique entité/constantes (commandes niveau entité, sans filtre fk_store)
                $missingExpeditions = $this->findOrdersWithoutExpedition($entity, $lookbackHours);
                $counters['checked'] = count($missingExpeditions);
                $this->log("ShopifyFulfillmentCatchupCron::executeCron - Phase 1 (fallback entité): " . $counters['checked']
                    . " orders with fulfillment but no expedition (lookback=" . $lookbackHours . "h)", LOG_INFO);
                $this->log("ShopifyFulfillmentCatchupCron::executeCron - No active stores — falling back to single entity run (retrocompat)", LOG_INFO);
                $shopifyApi = new ShopifyApi($this->db, $entity);
                if ($shopifyApi->isConfigurationComplete('orders')) {
                    $orderManager = new ShopifyOrderManager($this->db, $entity);
                    foreach ($missingExpeditions as $orderInfo) {
                        $result = $this->processOrderFulfillments($orderInfo, $orderManager, $shopifyApi, $user);
                        if ($result > 0) {
                            $counters['created'] += $result;
                        } elseif ($result == 0) {
                            $counters['skipped']++;
                        } else {
                            $counters['failed']++;
                        }
                    }
                }
            } else {
                $this->log("ShopifyFulfillmentCatchupCron::executeCron - Found " . count($stores) . " active store(s), iterating Phase 1 per store", LOG_INFO);
                foreach ($stores as $store) {
                    $storeLabel = $store->shop_domain ?? ('store#' . ($store->rowid ?? '?'));
                    // AC #7 : skip + warning si boutique sans credentials
                    if (empty($store->access_token) || empty($store->shop_domain)) {
                        $this->log("ShopifyFulfillmentCatchupCron::executeCron - Store without credentials (domain=" . $storeLabel . "), skipping", LOG_WARNING);
                        $this->output .= "\n[WARN] Store " . $storeLabel . " skipped (missing credentials)";
                        continue;
                    }
                    // Story 47-6, gate étendue par licence-gate-des-ecritures-sortantes (AC1) : la
                    // boutique par défaut passe désormais aussi par un gate réel (plus une exemption
                    // inconditionnelle) — cf. doli2shopEvaluateDefaultStoreSyncGate().
                    $licenceCheck = doli2shopStoreSyncAllowed($store);
                    if (!$licenceCheck['allowed']) {
                        // Blind Hunter (licence-gate-des-ecritures-sortantes) : la boutique par
                        // défaut ne doit JAMAIS être écrite en DB par ce CRON — invariant 49-8 —
                        // y compris ici. Avant cette story cette branche était morte pour is_default
                        // (toujours allowed=true) ; elle est désormais atteignable
                        // (sync_gate_active / failopen_expired), et écrivait 'invalid' sans garde,
                        // y compris quand le blocage vient d'une panne de NOTRE serveur
                        // (failopen_expired) et non d'une licence réellement invalide.
                        if (empty($store->is_default)) {
                            $storeService->setLicenseStatus((int) $store->rowid, 'invalid');
                        }
                        $this->log("ShopifyFulfillmentCatchupCron::executeCron - Store " . $storeLabel . " skipped (license " . $licenceCheck['reason'] . ")", LOG_WARNING);
                        $this->output .= "\n[WARN] Store " . $storeLabel . " skipped (license invalid: " . $licenceCheck['reason'] . ")";
                        continue;
                    }
                    // Story 49-8 fix A3, adapté par licence-gate-des-ecritures-sortantes : boutique
                    // par défaut → ne PAS écraser le statut licence en DB via ce CRON. ⚠️ Avant
                    // cette story, `reason` valait toujours 'default_store_exempt' pour la boutique
                    // par défaut (exemption inconditionnelle) ; ce n'est plus vrai — le gate réel
                    // renvoie désormais 'no_sync_gate_date'/'within_grace_period'/'sync_gate_active'/
                    // 'failopen_*' selon l'état. Tester `is_default` (plutôt que `reason`) préserve
                    // le comportement voulu par 49-8 quel que soit le vocabulaire de reason retourné :
                    // le statut réel n'est écrit QUE via le bouton « Vérifier maintenant »
                    // (admin/stores.php action=verify_license).
                    if (empty($store->is_default)) {
                        $storeService->setLicenseStatus((int) $store->rowid, $licenceCheck['status']);
                    }
                    // Story 50-1 : décision per-store (fallback global) — AVANT tout appel Shopify.
                    // Une boutique peut désactiver la création auto d'expédition indépendamment de
                    // la constante globale (et inversement, cf. gate ci-dessus). Story 53-3 : via
                    // SyncFlowPolicy (flux shipping, s2d).
                    if (!$this->isShippingInboundAllowedForStore((int) $store->rowid)) {
                        $this->log("ShopifyFulfillmentCatchupCron::executeCron - Store " . $storeLabel . " : AUTO_CREATE_EXPEDITION disabled (per-store or global fallback), skipping Phase 1", LOG_DEBUG);
                        continue;
                    }
                    try {
                        $shopifyApi = new ShopifyApi($this->db, $entity, $store);
                        if (!$shopifyApi->isConfigurationComplete('orders')) {
                            $this->log("ShopifyFulfillmentCatchupCron::executeCron - Config incomplete for store=" . $storeLabel . ", skipping", LOG_DEBUG);
                            continue;
                        }
                        // Epic 47-4 : commandes filtrées PAR BOUTIQUE (fk_store) — évite le N² et les
                        // appels GraphQL sur la mauvaise boutique (review MEDIUM-1)
                        $storeOrders = $this->findOrdersWithoutExpedition($entity, $lookbackHours, (int) $store->rowid);
                        $counters['checked'] += count($storeOrders);
                        $orderManager = new ShopifyOrderManager($this->db, $entity, $store);
                        foreach ($storeOrders as $orderInfo) {
                            $result = $this->processOrderFulfillments($orderInfo, $orderManager, $shopifyApi, $user);
                            if ($result > 0) {
                                $counters['created'] += $result;
                            } elseif ($result == 0) {
                                $counters['skipped']++;
                            } else {
                                $counters['failed']++;
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->log("ShopifyFulfillmentCatchupCron::executeCron - Store " . $storeLabel . " Phase 1 failed: " . $e->getMessage() . " — continuing", LOG_WARNING);
                        $this->output .= "\n[WARN] Store " . $storeLabel . " error: " . $e->getMessage();
                    }
                }
            }

            // ── Phase 2 : Clôturer les expéditions en brouillon ou validées-mais-non-clôturées ──
            // Niveau ENTITÉ — objets Dolibarr uniquement, gardes idempotents existants.
            // Pas de fenêtre temporelle : tout brouillon lié à une commande fulfilled est une anomalie.
            $unclosedCount = $this->closeUnclosedExpeditions($entity);
            $counters['closed'] = $unclosedCount;

            // ── Phase 3 : Rattraper les factures 0€ non payées ──
            // Niveau ENTITÉ — objets Dolibarr uniquement.
            $invoicesFixed = $this->fixUnpaidZeroInvoices($entity, $lookbackHours, $user);
            $counters['invoices_fixed'] = $invoicesFixed;

            // ── Phase 4 : Fermer les commandes avec expédition clôturée mais commande non fermée ──
            // Niveau ENTITÉ — objets Dolibarr uniquement.
            $ordersClosed = $this->closeOrdersWithClosedExpeditions($entity, $lookbackHours, $user);
            $counters['orders_closed'] = $ordersClosed;

            $elapsedSec = round(microtime(true) - $startTime, 2);

            $this->output = "Fulfillment catchup: "
                . $counters['checked'] . " checked, "
                . $counters['created'] . " created, "
                . $counters['skipped'] . " skipped, "
                . $counters['failed'] . " failed, "
                . $counters['closed'] . " exp closed, "
                . $counters['invoices_fixed'] . " inv fixed, "
                . $counters['orders_closed'] . " orders closed"
                . " (" . $elapsedSec . "s)"
                . $this->output; // Ajouter les warnings boutiques

            $this->log("ShopifyFulfillmentCatchupCron::executeCron - COMPLETED in {$elapsedSec}s - " . $this->output, LOG_INFO);

            @set_time_limit($oldTimeLimit);

            if ($counters['failed'] > 0) {
                $this->error = $counters['failed'];
                return -1;
            }

            return 0;

        } catch (\Throwable $e) {
            $errorMsg = "ShopifyFulfillmentCatchupCron::executeCron - FATAL: " . $e->getMessage()
                . " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            $this->log($errorMsg, LOG_ERR);

            $this->output = "Fulfillment catchup failed: " . $e->getMessage();
            $this->error = 1;
            $this->errors[] = $e->getMessage();

            if (isset($oldTimeLimit)) {
                @set_time_limit($oldTimeLimit);
            }

            return -1;
        } finally {
            // Story 36.4 : toujours libérer le verrou (no-op si non détenu par cette session)
            $this->releaseCronLock('fulfillment_catchup');
        }
    }

    /**
     * Find orders that have Shopify fulfillment status but no Dolibarr expedition
     *
     * Queries llx_doli2shop_orders for fulfilled orders, then checks llx_element_element
     * for linked expeditions. Returns orders with no expedition.
     *
     * @param int $entity        Entity ID
     * @param int $lookbackHours Hours to look back
     * @return array Array of [dolibarrOrderId, shopifyOrderId, fulfillmentStatus]
     */
    protected function findOrdersWithoutExpedition($entity, $lookbackHours, $fkStore = 0)
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$lookbackHours} hours"));

        // Commandes Dolibarr liées à Shopify avec fulfillment_status fulfilled ou partial
        // qui n'ont PAS d'expédition liée dans element_element.
        // Epic 47-4 : filtre fk_store CONDITIONNEL (fkStore>0) pour ne traiter que les commandes
        // de la boutique courante — évite le N² (chaque commande tentée pour chaque boutique) et
        // les appels GraphQL gaspillés sur la mauvaise boutique. fkStore=0 (fallback legacy) = pas
        // de filtre (toutes les commandes de l'entité, comportement historique).
        $fkStore = (int) $fkStore;
        $sql = "SELECT d2s.fk_commande, d2s.shopifyOrderId, d2s.dolOrderFulfillment"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders d2s"
            . " INNER JOIN " . MAIN_DB_PREFIX . "commande c ON c.rowid = d2s.fk_commande"
            . " WHERE d2s.entity = " . (int) $entity  // FIX F: cast défense en profondeur
            . " AND d2s.dolOrderFulfillment IN ('fulfilled', 'partial', 'complete', 'partially_fulfilled')"  // FIX A: toutes les valeurs possibles (webhook REST lowercase, mapFulfillmentStatus, ordersynctoshopify uppercase-via-GraphQL)
            . " AND c.fk_statut NOT IN (" . Commande::STATUS_CANCELED . ")"  // Draft orders will be auto-validated by createExpeditionFromFulfillment()
            . " AND d2s.tms >= '" . $this->db->escape($cutoff) . "'"
            . ($fkStore > 0 ? " AND d2s.fk_store = " . $fkStore : "")
            . " AND NOT EXISTS ("
            . "   SELECT 1 FROM " . MAIN_DB_PREFIX . "element_element ee"
            . "   WHERE ee.sourcetype = 'commande' AND ee.fk_source = d2s.fk_commande"
            . "   AND ee.targettype = 'shipping'"
            . " )"
            . " ORDER BY d2s.tms ASC"
            . " LIMIT 20";

        $resql = $this->db->query($sql);

        $results = array();
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $results[] = array(
                    'dolibarrOrderId' => (int) $obj->fk_commande,
                    'shopifyOrderId' => $obj->shopifyOrderId,
                    'fulfillmentStatus' => $obj->dolOrderFulfillment
                );
            }
            $this->db->free($resql);
        } else {
            $this->log("findOrdersWithoutExpedition - SQL error: " . $this->db->lasterror(), LOG_ERR);
        }

        return $results;
    }

    /**
     * Process fulfillments for a single order: fetch from Shopify API and create expedition
     *
     * @param array              $orderInfo    Order info from findOrdersWithoutExpedition
     * @param ShopifyOrderManager $orderManager Order manager instance
     * @param ShopifyApi         $shopifyApi   API instance
     * @param User               $user         Dolibarr user
     * @return int >0 = number of expeditions created, 0 = skipped, -1 = error
     */
    protected function processOrderFulfillments($orderInfo, $orderManager, $shopifyApi, $user)
    {
        $dolibarrOrderId = $orderInfo['dolibarrOrderId'];
        $shopifyOrderId = $orderInfo['shopifyOrderId'];

        $this->log("processOrderFulfillments - Processing order Dolibarr #" . $dolibarrOrderId
            . " (Shopify #" . $shopifyOrderId . ")", LOG_INFO);

        // Fetch fulfillments from Shopify API via GraphQL
        $fulfillments = $this->fetchShopifyFulfillments($shopifyApi, $shopifyOrderId);

        if ($fulfillments === false) {
            $this->log("processOrderFulfillments - Failed to fetch fulfillments from Shopify for order #"
                . $shopifyOrderId, LOG_ERR);
            return -1;
        }

        if (empty($fulfillments)) {
            $this->log("processOrderFulfillments - No fulfillments found for Shopify order #"
                . $shopifyOrderId . " (fulfillment may have been cancelled)", LOG_INFO);
            return 0;
        }

        // Charger la commande Dolibarr
        $order = new Commande($this->db);
        $fetchResult = $order->fetch($dolibarrOrderId);
        if ($fetchResult <= 0) {
            $this->log("processOrderFulfillments - Could not fetch Dolibarr order #" . $dolibarrOrderId, LOG_ERR);
            return -1;
        }

        $isPartial = ($orderInfo['fulfillmentStatus'] === 'partial');
        $createdCount = 0;

        // Story 40.1 : persistance events fulfillment (orthogonal à Epic 11)
        $fulfillmentManager = new ShopifyFulfillmentManager($this->db);

        foreach ($fulfillments as $fulfillment) {
            $orderManager->errors = array();
            $expeditionResult = $orderManager->createExpeditionFromFulfillment($order, $fulfillment, $user, $isPartial);

            if ($expeditionResult > 0) {
                $createdCount++;
                $this->log("processOrderFulfillments - Expedition #" . $expeditionResult
                    . " created for order #" . $dolibarrOrderId
                    . " (tracking=" . ($fulfillment['tracking_number'] ?? 'none') . ")", LOG_INFO);
            } elseif ($expeditionResult == 0) {
                $this->log("processOrderFulfillments - Expedition skipped (already exists or conditions not met)"
                    . " for order #" . $dolibarrOrderId, LOG_DEBUG);
            } else {
                $this->log("processOrderFulfillments - Expedition creation failed for order #" . $dolibarrOrderId
                    . ": " . implode(', ', $orderManager->errors), LOG_ERR);
                return -1;
            }

            // Story 40.1 : persistance des FulfillmentEvents dans
            // llx_doli2shop_fulfillment_events (unique source de la timeline UI).
            // Non critique : ne bloque pas la logique Epic 11 en cas d'erreur.
            if (getDolGlobalInt('DOLI2SHOP_FETCH_FULFILLMENT_EVENTS', 0)) {
                $fulfillmentManager->saveFulfillmentEvents($dolibarrOrderId, $shopifyOrderId, $fulfillment);
            }
        }

        return $createdCount;
    }

    /**
     * Find and close expeditions that are validated (status=1) but not closed (status=2),
     * or draft (status=0) that were never validated.
     *
     * Targets expeditions linked to Shopify fulfilled orders that were created but
     * never validated/closed (e.g. due to a transient trigger failure).
     *
     * Anti-loop: draft expeditions older than DOLI2SHOP_CATCHUP_MAX_AGE_DAYS days
     * (default: 30) are excluded — a brouillon that cannot be validated after N days
     * is beyond the catchup window and requires manual intervention.
     * Validated-but-unclosed expeditions (statut=1) have no age limit: once valid,
     * they should always be closed regardless of age.
     *
     * Draft guard (FIX D): draft expeditions (statut=0) are only retried if they
     * carry a non-empty tracking_number — expeditions created by this module always
     * have tracking set at create() time; manual draft expeditions in preparation
     * typically do not, and should not be touched.
     *
     * Cancelled orders are excluded via INNER JOIN on llx_commande (FIX E).
     *
     * Constant DOLI2SHOP_CATCHUP_MAX_AGE_DAYS (default 30): controls the maximum age
     * in days of draft expeditions eligible for retry. Set to 0 to disable the age
     * filter (not recommended — risks retrying permanently broken expeditions forever).
     *
     * @param int $entity Entity ID
     * @return int Number of expeditions closed
     */
    protected function closeUnclosedExpeditions($entity)
    {
        $maxAgeDays = getDolGlobalInt('DOLI2SHOP_CATCHUP_MAX_AGE_DAYS', 30);

        // Trouver les expéditions en statut 0 (brouillon) ou 1 (validée, non clôturée)
        // liées à des commandes Shopify fulfilled.
        // FIX A: toutes les valeurs possibles de dolOrderFulfillment
        //   - webhook REST Shopify (REST API) → 'fulfilled', 'partial' (minuscules)
        //   - mapFulfillmentStatus (GraphQL sync) → 'complete', 'partial'
        //   - ordersynctoshopify (GraphQL) → 'FULFILLED', 'PARTIALLY_FULFILLED'
        //   MySQL est case-insensitive sur utf8mb4_unicode_ci → les 4 valeurs distinctes
        //   réelles sont : fulfilled, partial, complete, partially_fulfilled.
        // FIX C: borne d'âge sur les brouillons seulement (statut=1 → pas de limite)
        // FIX D: brouillons protégés — traiter statut=0 uniquement si tracking_number posé
        // FIX E: INNER JOIN commande pour exclure les commandes annulées
        $sql = "SELECT e.rowid as expedition_id, e.ref, e.fk_statut as exp_status, d2s.fk_commande, d2s.shopifyOrderId"
            . " FROM " . MAIN_DB_PREFIX . "expedition e"
            . " INNER JOIN " . MAIN_DB_PREFIX . "element_element ee"
            . "   ON ee.targettype = 'shipping' AND ee.fk_target = e.rowid"
            . "   AND ee.sourcetype = 'commande'"
            . " INNER JOIN " . MAIN_DB_PREFIX . "doli2shop_orders d2s"
            . "   ON d2s.fk_commande = ee.fk_source"
            . " INNER JOIN " . MAIN_DB_PREFIX . "commande c"  // FIX E: exclure commandes annulées
            . "   ON c.rowid = d2s.fk_commande AND c.fk_statut <> " . Commande::STATUS_CANCELED
            . " WHERE e.entity = " . (int) $entity
            . " AND d2s.dolOrderFulfillment IN ('fulfilled', 'partial', 'complete', 'partially_fulfilled')"
            . " AND ("
            // statut=1 (validée, non clôturée) → pas de limite d'âge
            . "   e.fk_statut = 1"
            // statut=0 (brouillon) → borne d'âge + guard tracking_number (FIX C + FIX D)
            . "   OR (e.fk_statut = 0"
            . "     AND e.tracking_number IS NOT NULL AND e.tracking_number <> ''"  // FIX D: brouillon manuel sans tracking = skip
            . ($maxAgeDays > 0 ? "     AND e.date_creation >= NOW() - INTERVAL " . (int) $maxAgeDays . " DAY" : "")  // FIX C: anti-boucle infinie
            . "   )"
            . " )"
            . " ORDER BY e.tms ASC"
            . " LIMIT 20";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("closeUnclosedExpeditions - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return 0;
        }

        $closedCount = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $expedition = new Expedition($this->db);
            $fetchResult = $expedition->fetch((int) $obj->expedition_id);
            if ($fetchResult <= 0) {
                $this->log("closeUnclosedExpeditions - Could not fetch expedition #"
                    . $obj->expedition_id, LOG_WARNING);
                continue;
            }

            // If draft (status 0), validate first
            if ($expedition->statut == 0) {
                global $user;
                // Fix review 50-5 : marqueur déjà connu ET déjà throttlé (< 24h depuis le
                // dernier LOG_ERR émis par recordAndThrottleValidationFailure()) → dégrader
                // les 2 WARNING récurrents de ce bloc en DEBUG. Le run ne révèle rien de
                // nouveau ; la trace complète reste disponible en mode debug, seul le bruit
                // WARNING (répété jusqu'à 48×/jour pour une expédition durablement bloquée)
                // disparaît des logs d'exploitation.
                $knownThrottledFailure = $this->isValidationFailureThrottled($expedition->note_private);
                $recurringWarningLevel = $knownThrottledFailure ? LOG_DEBUG : LOG_WARNING;

                $validResult = $expedition->valid($user);
                if ($validResult < 0) {
                    // FIX B: après un valid() en échec, re-fetch depuis la base pour connaître
                    // l'état réel. valid() peut avoir partiellement abouti (statut en base >= 1)
                    // sans retourner >= 0 (ex. conflit trigger tiers). Ne pas re-valider dans ce cas.
                    $this->log("closeUnclosedExpeditions - valid() failed for expedition "
                        . $obj->ref . " (#" . $obj->expedition_id . "): "
                        . ($expedition->error ?: 'Unknown') . " — re-fetching from DB to check actual status", $recurringWarningLevel);
                    $expeditionCheck = new Expedition($this->db);
                    $expeditionCheck->fetch((int) $obj->expedition_id);
                    if ($expeditionCheck->statut >= 1) {
                        // La validation a en réalité abouti (statut en base >= 1) — continuer vers setClosed()
                        $this->log("closeUnclosedExpeditions - Expedition "
                            . $obj->ref . " (#" . $obj->expedition_id . ") is actually statut="
                            . $expeditionCheck->statut . " in DB — valid() race condition, continuing to setClosed()", LOG_WARNING);
                        $expedition = $expeditionCheck;
                        $validResult = 1;  // Considérer comme validée
                        // Fix review 50-5 : validation finalement réussie (race condition) —
                        // retirer le marqueur d'échec s'il était présent.
                        $this->clearValidationFailureMarker($expedition);
                    } else {
                        // Statut 0 en base : retry avec notrigger=1 sur l'objet re-fetché
                        $this->log("closeUnclosedExpeditions - Expedition "
                            . $obj->ref . " (#" . $obj->expedition_id . ") still statut=0 in DB — retrying valid(notrigger=1)", $recurringWarningLevel);
                        $validResult = $expeditionCheck->valid($user, 1);  // notrigger=1
                        if ($validResult < 0) {
                            // Story 50-5 : throttling du log d'échec définitif (marqueur léger dans
                            // note_private, sans migration) — un brouillon in-validable est retenté
                            // 1×/run pendant DOLI2SHOP_CATCHUP_MAX_AGE_DAYS ; sans throttle, chaque
                            // run reproduit un LOG_ERR identique jusqu'à la borne d'âge.
                            $this->recordAndThrottleValidationFailure(
                                (int) $obj->expedition_id,
                                (string) ($expeditionCheck->ref ?? $obj->ref),
                                $expeditionCheck->note_private ?? null,
                                'valid(notrigger=1) also failed: ' . ($expeditionCheck->error ?: 'Unknown'),
                                $entity
                            );
                            continue;
                        }
                        $this->log("closeUnclosedExpeditions - Validated draft expedition "
                            . $obj->ref . " (#" . $obj->expedition_id . ") (0 → 1) with notrigger=1", LOG_WARNING);
                        $expedition = $expeditionCheck;
                        // Fix review 50-5 : validation réussie au retry notrigger=1 — retirer
                        // le marqueur d'échec s'il était présent.
                        $this->clearValidationFailureMarker($expedition);
                    }
                } else {
                    $this->log("closeUnclosedExpeditions - Validated draft expedition "
                        . $obj->ref . " (#" . $obj->expedition_id . ") (0 → 1)", LOG_INFO);
                    // Fix review 50-5 : validation directe réussie — retirer le marqueur
                    // d'échec s'il était présent (brouillon précédemment throttlé qui finit
                    // par se valider normalement sur un run ultérieur).
                    $this->clearValidationFailureMarker($expedition);
                }
            }

            $closeResult = $expedition->setClosed();
            if ($closeResult < 0) {
                $this->log("closeUnclosedExpeditions - Failed to close expedition "
                    . $obj->ref . " (#" . $obj->expedition_id . "): "
                    . ($expedition->error ?: 'Unknown'), LOG_WARNING);
            } else {
                $closedCount++;
                $this->log("closeUnclosedExpeditions - Closed expedition "
                    . $obj->ref . " (#" . $obj->expedition_id . ") for order #"
                    . $obj->fk_commande . " (Shopify #" . $obj->shopifyOrderId . ")", LOG_INFO);
            }
        }
        $this->db->free($resql);

        if ($closedCount > 0) {
            $this->log("closeUnclosedExpeditions - Closed " . $closedCount . " expeditions", LOG_INFO);
        }

        return $closedCount;
    }

    /**
     * Story 50-5 : marqueur léger de suivi des échecs de validation Phase 2, SANS migration
     * (pas de nouvelle colonne/table) — persisté dans note_private de l'expédition.
     *
     * Problème : un brouillon in-validable est retenté 1×/run pendant toute la fenêtre
     * DOLI2SHOP_CATCHUP_MAX_AGE_DAYS (30 jours par défaut) ; sans throttling, l'échec produit
     * un LOG_ERR identique à CHAQUE run (toutes les 30 min), soit ~1440 occurrences/mois pour
     * une seule expédition bloquée.
     *
     * Format du marqueur (ajouté/mis à jour en fin de note_private existante, préservée) :
     *   [D2S_CATCHUP_FAIL count=N last=YYYY-MM-DD HH:MM:SS]
     *
     * Comportement : LOG_ERR émis au premier échec détecté (pas de marqueur existant), puis
     * au maximum 1×/24h ensuite. Entre deux, l'échec est tracé en LOG_DEBUG (silencieux en
     * exploitation normale) mais le compteur continue d'être incrémenté et persisté.
     *
     * @param  int         $expeditionId Rowid expédition
     * @param  string      $expeditionRef Référence expédition (pour le message de log)
     * @param  string|null $currentNote  note_private actuelle en base (peut être vide/null)
     * @param  string      $reason       Raison de l'échec à inclure dans le log
     * @param  int         $entity       Entité Dolibarr (filtre multi-entity de l'UPDATE)
     * @return void
     */
    protected function recordAndThrottleValidationFailure(int $expeditionId, string $expeditionRef, ?string $currentNote, string $reason, int $entity): void
    {
        $markerPattern = '/\[D2S_CATCHUP_FAIL count=(\d+) last=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/';

        $failureCount = 0;
        $lastLoggedTs = null;
        $noteWithoutMarker = (string) $currentNote;

        if ($currentNote !== null && preg_match($markerPattern, $currentNote, $matches)) {
            $failureCount = (int) $matches[1];
            $lastLoggedTs = strtotime($matches[2]);
            $noteWithoutMarker = trim((string) preg_replace($markerPattern, '', $currentNote));
        }

        $failureCount++;
        $now = dol_now();
        // Premier échec (pas de marqueur / timestamp illisible) OU >= 24h depuis le dernier LOG_ERR
        $shouldLogError = ($lastLoggedTs === false || $lastLoggedTs === null || ($now - $lastLoggedTs) >= 86400);

        if ($shouldLogError) {
            $this->log("closeUnclosedExpeditions - Expedition " . $expeditionRef . " (#" . $expeditionId
                . ") failed validation (" . $failureCount . " échec(s) cumulé(s)) — intervention manuelle requise : "
                . $reason, LOG_ERR);
            $lastLoggedTs = $now;
        } else {
            $this->log("closeUnclosedExpeditions - Expedition " . $expeditionRef . " (#" . $expeditionId
                . ") failed validation again (count=" . $failureCount . ", log throttled — prochain LOG_ERR dans <24h): "
                . $reason, LOG_DEBUG);
        }

        $marker = '[D2S_CATCHUP_FAIL count=' . $failureCount . ' last=' . date('Y-m-d H:i:s', $lastLoggedTs) . ']';
        $newNote = trim($noteWithoutMarker . ($noteWithoutMarker !== '' ? "\n" : '') . $marker);

        $sql = "UPDATE " . MAIN_DB_PREFIX . "expedition"
            . " SET note_private = '" . $this->db->escape($newNote) . "'"
            . " WHERE rowid = " . $expeditionId
            . " AND entity = " . (int) $entity;
        $updateResult = $this->db->query($sql);
        if (!$updateResult) {
            // Non bloquant : le throttling redémarrera à count=1 au prochain run (dégradation
            // gracieuse — pire cas = un LOG_ERR de plus que nécessaire, pas de perte de données).
            $this->log("recordAndThrottleValidationFailure - Could not persist failure marker for expedition #"
                . $expeditionId . ": " . $this->db->lasterror(), LOG_WARNING);
        }
    }

    /**
     * Fix review 50-5 : indique si l'échec de validation Phase 2 de cette expédition est
     * déjà connu (marqueur `[D2S_CATCHUP_FAIL ...]` présent en note_private) ET déjà
     * throttlé au sens du LOG_ERR (< 24h depuis le dernier LOG_ERR émis par
     * recordAndThrottleValidationFailure()). Utilisé pour dégrader en LOG_DEBUG les 2
     * WARNING récurrents émis à chaque run tant qu'un brouillon reste bloqué, sans rien
     * changer au throttling du LOG_ERR lui-même (logique indépendante, marqueur en lecture
     * seule ici).
     *
     * @param  string|null $note note_private actuelle (peut contenir ou non le marqueur)
     * @return bool true si un marqueur existe et que son horodatage est encore dans la fenêtre de 24h
     */
    protected function isValidationFailureThrottled(?string $note): bool
    {
        if ($note === null || $note === '') {
            return false;
        }

        $markerPattern = '/\[D2S_CATCHUP_FAIL count=\d+ last=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/';
        if (!preg_match($markerPattern, $note, $matches)) {
            return false;
        }

        $lastLoggedTs = strtotime($matches[1]);
        if ($lastLoggedTs === false) {
            return false;
        }

        return (dol_now() - $lastLoggedTs) < 86400;
    }

    /**
     * Fix review 50-5 : retire le marqueur `[D2S_CATCHUP_FAIL count=N last=...]` de
     * note_private une fois l'expédition validée avec succès. Sans ce nettoyage, le
     * marqueur restait affiché indéfiniment dans note_private même après résolution
     * (manuelle ou par un run ultérieur), laissant croire à tort à un échec persistant.
     *
     * No-op si aucun marqueur n'est présent (pas d'UPDATE inutile).
     *
     * @param  Expedition $expedition Expédition venant d'être validée avec succès
     *                                (note_private reflète l'état en base au moment du fetch)
     * @return void
     */
    protected function clearValidationFailureMarker($expedition): void
    {
        $markerPattern = '/\[D2S_CATCHUP_FAIL count=\d+ last=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/';

        if (empty($expedition->note_private)) {
            return;
        }

        if (!preg_match($markerPattern, $expedition->note_private)) {
            return;
        }

        $newNote = trim((string) preg_replace($markerPattern, '', $expedition->note_private));

        $sql = "UPDATE " . MAIN_DB_PREFIX . "expedition"
            . " SET note_private = '" . $this->db->escape($newNote) . "'"
            . " WHERE rowid = " . (int) $expedition->id
            . " AND entity = " . (int) $expedition->entity;
        $updateResult = $this->db->query($sql);
        if (!$updateResult) {
            $this->log("clearValidationFailureMarker - Could not clear failure marker for expedition #"
                . $expedition->id . ": " . $this->db->lasterror(), LOG_WARNING);
        }
    }

    /**
     * Fix unpaid 0€ invoices linked to Shopify orders
     *
     * Invoices with total_ttc = 0 (free products) should be classified as paid
     * without creating a payment object. This catches up invoices that were created
     * before the 0€ fix was deployed.
     *
     * @param int    $entity        Entity ID
     * @param int    $lookbackHours Hours to look back
     * @param object $user          Dolibarr user
     * @return int Number of invoices fixed
     */
    protected function fixUnpaidZeroInvoices($entity, $lookbackHours, $user)
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$lookbackHours} hours"));

        // Trouver les factures 0€ validées (fk_statut=1) non payées (paye=0)
        // liées à des commandes Shopify
        $sql = "SELECT f.rowid as invoice_id, f.ref, d2s.fk_commande, d2s.shopifyOrderId"
            . " FROM " . MAIN_DB_PREFIX . "facture f"
            . " INNER JOIN " . MAIN_DB_PREFIX . "element_element ee"
            . "   ON ee.targettype = 'facture' AND ee.fk_target = f.rowid"
            . "   AND ee.sourcetype = 'commande'"
            . " INNER JOIN " . MAIN_DB_PREFIX . "doli2shop_orders d2s"
            . "   ON d2s.fk_commande = ee.fk_source"
            . " WHERE f.fk_statut = 1"
            . " AND f.paye = 0"
            . " AND f.total_ttc = 0"
            . " AND f.entity = " . (int) $entity
            . " AND f.tms >= '" . $this->db->escape($cutoff) . "'"
            . " ORDER BY f.tms ASC"
            . " LIMIT 20";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("fixUnpaidZeroInvoices - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return 0;
        }

        $fixedCount = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $invoice = new Facture($this->db);
            $fetchResult = $invoice->fetch((int) $obj->invoice_id);
            if ($fetchResult <= 0) {
                $this->log("fixUnpaidZeroInvoices - Could not fetch invoice #"
                    . $obj->invoice_id, LOG_WARNING);
                continue;
            }

            $setPaidResult = $invoice->setPaid($user);
            if ($setPaidResult < 0) {
                $this->log("fixUnpaidZeroInvoices - setPaid() failed for invoice "
                    . $obj->ref . " (#" . $obj->invoice_id . "): "
                    . ($invoice->error ?: 'Unknown'), LOG_WARNING);
            } else {
                $fixedCount++;
                $this->log("fixUnpaidZeroInvoices - 0€ invoice " . $obj->ref
                    . " (#" . $obj->invoice_id . ") classified as paid for order #"
                    . $obj->fk_commande . " (Shopify #" . $obj->shopifyOrderId . ")", LOG_INFO);
            }
        }
        $this->db->free($resql);

        if ($fixedCount > 0) {
            $this->log("fixUnpaidZeroInvoices - Fixed " . $fixedCount . " zero-amount invoices", LOG_INFO);
        }

        return $fixedCount;
    }

    /**
     * Close orders that have a closed expedition but order status is still validated (1)
     *
     * This catches orders where the expedition was closed (status=2) but the order
     * was never moved to STATUS_CLOSED (3). Root cause: postExpeditionActions used
     * $line->qty_shipped which is not populated by Commande::fetch_lines().
     *
     * Uses the same logic as Dolibarr Expedition::setClosed(): compares qty per line
     * using $order->loadExpeditions() instead of $line->qty_shipped.
     *
     * @param int    $entity        Entity ID
     * @param int    $lookbackHours Hours to look back
     * @param object $user          Dolibarr user
     * @return int Number of orders closed
     */
    protected function closeOrdersWithClosedExpeditions($entity, $lookbackHours, $user)
    {
        // Note: pas de filtre tms ici — cette phase rattrape les vieilles commandes
        // bloquées en statut 1/2 malgré expédition clôturée (bug historique $line->qty_shipped).
        // Le LIMIT 20 suffit à limiter le traitement par exécution CRON.
        $sql = "SELECT DISTINCT c.rowid as order_id, c.ref, c.fk_statut, c.tms, d2s.shopifyOrderId"
            . " FROM " . MAIN_DB_PREFIX . "commande c"
            . " INNER JOIN " . MAIN_DB_PREFIX . "doli2shop_orders d2s ON d2s.fk_commande = c.rowid"
            . " INNER JOIN " . MAIN_DB_PREFIX . "element_element ee"
            . "   ON ee.sourcetype = 'commande' AND ee.fk_source = c.rowid AND ee.targettype = 'shipping'"
            . " INNER JOIN " . MAIN_DB_PREFIX . "expedition e ON e.rowid = ee.fk_target"
            . " WHERE c.entity = " . (int) $entity
            . " AND d2s.dolOrderFulfillment IN ('fulfilled', 'complete')"  // FIX A: 'complete' = mapFulfillmentStatus(FULFILLED) ; PAS les partiels (commande partiellement expédiée ne doit pas être clôturée)
            . " AND e.fk_statut = " . Expedition::STATUS_CLOSED
            . " AND c.fk_statut IN (" . Commande::STATUS_VALIDATED . ", " . Commande::STATUS_SHIPMENTONPROCESS . ")"
            . " ORDER BY c.tms ASC"
            . " LIMIT 20";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("closeOrdersWithClosedExpeditions - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return 0;
        }

        $closedCount = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $order = new Commande($this->db);
            if ($order->fetch((int) $obj->order_id) <= 0) {
                $this->log("closeOrdersWithClosedExpeditions - Could not fetch order #"
                    . $obj->order_id, LOG_WARNING);
                continue;
            }
            $order->fetch_lines();
            $order->loadExpeditions(Expedition::STATUS_CLOSED);

            // Vérifier que toutes les lignes physiques sont expédiées
            // Note: logique identique à ShopifyOrderManager::areAllPhysicalLinesShipped()
            $allShipped = true;
            foreach ($order->lines as $line) {
                // Skip service lines (product_type=1)
                if (isset($line->product_type) && $line->product_type == 1) {
                    continue;
                }
                $lineId = $line->id;
                $qtyShipped = isset($order->expeditions[$lineId]) ? (float) $order->expeditions[$lineId] : 0;
                if ($qtyShipped < $line->qty) {
                    $allShipped = false;
                    $this->log("closeOrdersWithClosedExpeditions - Order " . $obj->ref
                        . " line #" . $lineId . " not fully shipped: " . $qtyShipped . "/" . $line->qty, LOG_DEBUG);
                    break;
                }
            }

            if ($allShipped) {
                // FIX v2.2.2: Si date_cloture est déjà renseignée (état incohérent dû au
                // bug "stale object" du webhook handler), la remettre à null avant d'appeler
                // cloture() pour que Dolibarr ne considère pas la commande comme déjà fermée
                $wasInconsistent = false;
                if (!empty($order->date_cloture) && $order->statut != Commande::STATUS_CLOSED) {
                    $wasInconsistent = true;
                    $this->log("closeOrdersWithClosedExpeditions - Order " . $obj->ref
                        . " (#" . $obj->order_id . ") inconsistent state detected:"
                        . " date_cloture=" . dol_print_date($order->date_cloture, 'dayhour')
                        . " but fk_statut=" . $order->statut . " — clearing date_cloture before cloture()", LOG_WARNING);
                    // Reset date_cloture en BDD pour permettre à cloture() de fonctionner
                    $sqlFix = "UPDATE " . MAIN_DB_PREFIX . "commande"
                        . " SET date_cloture = NULL"
                        . " WHERE rowid = " . ((int) $order->id)
                        . " AND fk_statut IN (" . Commande::STATUS_VALIDATED . ", " . Commande::STATUS_SHIPMENTONPROCESS . ")";
                    $this->db->query($sqlFix);
                    // Re-fetch pour avoir l'objet propre
                    $order->fetch((int) $obj->order_id);
                }

                $closeResult = $order->cloture($user);
                if ($closeResult > 0) {
                    $closedCount++;
                    $this->log("closeOrdersWithClosedExpeditions - Order " . $obj->ref
                        . " (#" . $obj->order_id . ") closed (all lines shipped)"
                        . ($wasInconsistent ? " [fixed inconsistent state]" : "")
                        . " — Shopify #" . $obj->shopifyOrderId, LOG_INFO);
                } else {
                    // Dernier recours: correction directe SQL si cloture() échoue malgré tout
                    $sqlForce = "UPDATE " . MAIN_DB_PREFIX . "commande"
                        . " SET fk_statut = " . Commande::STATUS_CLOSED
                        . ", date_cloture = '" . $this->db->idate(dol_now()) . "'"
                        . " WHERE rowid = " . ((int) $order->id)
                        . " AND fk_statut IN (" . Commande::STATUS_VALIDATED . ", " . Commande::STATUS_SHIPMENTONPROCESS . ")";
                    $forceResult = $this->db->query($sqlForce);
                    if ($forceResult && $this->db->affected_rows($forceResult) > 0) {
                        $closedCount++;
                        $this->log("closeOrdersWithClosedExpeditions - Order " . $obj->ref
                            . " (#" . $obj->order_id . ") force-closed via SQL (cloture() returned "
                            . $closeResult . ": " . ($order->error ?: 'Unknown') . ")"
                            . " — Shopify #" . $obj->shopifyOrderId, LOG_WARNING);
                    } else {
                        $this->log("closeOrdersWithClosedExpeditions - Failed to close order " . $obj->ref
                            . " (#" . $obj->order_id . "): cloture()=" . $closeResult
                            . " error=" . ($order->error ?: 'Unknown')
                            . " — SQL force also failed", LOG_ERR);
                    }
                }
            } else {
                $this->log("closeOrdersWithClosedExpeditions - Order " . $obj->ref
                    . " not fully shipped, skipping close", LOG_DEBUG);
            }
        }
        $this->db->free($resql);

        if ($closedCount > 0) {
            $this->log("closeOrdersWithClosedExpeditions - Closed " . $closedCount . " orders", LOG_INFO);
        }

        return $closedCount;
    }

    /**
     * Fetch fulfillments for a Shopify order via GraphQL API
     *
     * @param ShopifyApi $shopifyApi    API instance
     * @param string     $shopifyOrderId Shopify order GID or numeric ID
     * @return array|false Array of fulfillment data, or false on API error
     */
    private function fetchShopifyFulfillments($shopifyApi, $shopifyOrderId)
    {
        // Construire le GID si c'est un ID numérique
        $orderId = $shopifyOrderId;
        if (is_numeric($orderId)) {
            $orderId = 'gid://shopify/Order/' . $orderId;
        }

        $query = '{
            node(id: "' . $orderId . '") {
                ... on Order {
                    id
                    name
                    fulfillments(first: 10) {
                        id
                        status
                        displayStatus
                        deliveredAt
                        inTransitAt
                        estimatedDeliveryAt
                        trackingInfo {
                            number
                            url
                            company
                        }
                        events(first: 50) {
                            edges {
                                node {
                                    id
                                    status
                                    happenedAt
                                    estimatedDeliveryAt
                                    message
                                    city
                                    province
                                    zip
                                    country
                                    latitude
                                    longitude
                                }
                            }
                        }
                        fulfillmentLineItems(first: 50) {
                            edges {
                                node {
                                    id
                                    quantity
                                    lineItem {
                                        id
                                        variant {
                                            id
                                        }
                                        product {
                                            id
                                        }
                                        sku
                                    }
                                    originalTotalSet {
                                        shopMoney {
                                            amount
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }';

        $response = $shopifyApi->executeGraphQL($query);

        // Convertir stdClass en tableau associatif (executeGraphQL retourne json_decode($body, false))
        if (is_object($response)) {
            $response = json_decode(json_encode($response), true);
        }

        if (!$response || isset($response['errors'])) {
            $errorMsg = isset($response['errors']) ? json_encode($response['errors']) : 'No response';
            $this->log("fetchShopifyFulfillments - GraphQL error for order " . $shopifyOrderId . ": " . $errorMsg, LOG_ERR);
            return false;
        }

        $node = $response['data']['node'] ?? null;
        if (!$node || !isset($node['fulfillments'])) {
            $this->log("fetchShopifyFulfillments - No order data returned for " . $shopifyOrderId, LOG_WARNING);
            return array();
        }

        // Transformer les fulfillments GraphQL en format REST-like compatible avec createExpeditionFromFulfillment
        $fulfillments = array();
        foreach ($node['fulfillments'] as $gqlFulfillment) {
            // Ignorer les fulfillments annulés
            $status = $gqlFulfillment['status'] ?? '';
            if (strtoupper($status) === 'CANCELLED') {
                continue;
            }

            // Extraire le fulfillment ID numérique
            $fulfillmentGid = $gqlFulfillment['id'] ?? '';
            $fulfillmentNumericId = '';
            if (preg_match('/Fulfillment\/(\d+)/', $fulfillmentGid, $matches)) {
                $fulfillmentNumericId = $matches[1];
            }

            $trackingInfo = $gqlFulfillment['trackingInfo'] ?? array();
            $firstTracking = is_array($trackingInfo) && !empty($trackingInfo) ? $trackingInfo[0] : array();

            // Construire les line_items en format REST-like
            $lineItems = array();
            $edges = $gqlFulfillment['fulfillmentLineItems']['edges'] ?? array();
            foreach ($edges as $edge) {
                $lineNode = $edge['node'] ?? array();
                $lineItemData = $lineNode['lineItem'] ?? array();

                // Extraire les IDs numériques des GIDs
                $variantId = '';
                if (isset($lineItemData['variant']['id'])) {
                    if (preg_match('/ProductVariant\/(\d+)/', $lineItemData['variant']['id'], $m)) {
                        $variantId = $m[1];
                    }
                }
                $productId = '';
                if (isset($lineItemData['product']['id'])) {
                    if (preg_match('/Product\/(\d+)/', $lineItemData['product']['id'], $m)) {
                        $productId = $m[1];
                    }
                }

                $lineItems[] = array(
                    'variant_id' => $variantId,
                    'product_id' => $productId,
                    'sku' => $lineItemData['sku'] ?? '',
                    'quantity' => $lineNode['quantity'] ?? 1,
                    'grams' => 0 // Non disponible via GraphQL fulfillmentLineItems
                );
            }

            $fulfillments[] = array(
                'id' => $fulfillmentNumericId,
                'status' => $status,
                // Champs enrichis Story 40.1 (GraphQL 2026-04)
                'shopify_fulfillment_gid'  => $fulfillmentGid,
                'displayStatus'            => $gqlFulfillment['displayStatus'] ?? '',
                'deliveredAt'              => $gqlFulfillment['deliveredAt'] ?? null,
                'inTransitAt'              => $gqlFulfillment['inTransitAt'] ?? null,
                'estimatedDeliveryAt'      => $gqlFulfillment['estimatedDeliveryAt'] ?? null,
                'events'                   => $gqlFulfillment['events'] ?? array(),
                'tracking_number'          => $firstTracking['number'] ?? '',
                'tracking_url'             => $firstTracking['url'] ?? '',
                'tracking_company'         => $firstTracking['company'] ?? '',
                'line_items'               => $lineItems
            );
        }

        $this->log("fetchShopifyFulfillments - Found " . count($fulfillments)
            . " active fulfillments for order " . $shopifyOrderId, LOG_DEBUG);

        return $fulfillments;
    }
}
