<?php

/**
 * @file        class/shopifyordercatchupcron.class.php
 * @brief       CRON pour rattrapage des commandes Shopify manquees par polling
 *
 * Story 1.4 - Epic 1: Fiabilite des webhooks temps reel
 * Compare les commandes recentes Shopify avec Dolibarr et importe les manquantes.
 * Filet de securite complementaire au CRON normal (index 0) et au health check (index 5).
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.2.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre acces direct (compatible CRON et modules)
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
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';
dol_include_once('/doli2shop/class/shopifyordermanager.class.php');

/**
 * Class ShopifyOrderCatchupCron
 *
 * CRON de rattrapage des commandes manquees.
 * Execute toutes les 60 minutes pour comparer les commandes Shopify recentes
 * avec celles importees dans Dolibarr et importer les manquantes.
 *
 * @since 2.2.0
 */
class ShopifyOrderCatchupCron
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
     * Execute cron job for order catchup
     *
     * Boucle sur les boutiques actives (AC #6) :
     * - 0 boutique → fallback run unique entité (rétrocompat)
     * - Skip + warning si boutique sans credentials
     * - Agrégation des compteurs
     * - Verrou anti double-exécution au niveau CRON (AC #8)
     *
     * @param object $conf Config object (unused, global $conf used instead)
     * @param object $langs Lang object (unused)
     * @return int 0 if OK, <0 if error
     */
    public function executeCron($conf = null, $langs = null)
    {
        global $conf;

        try {
            $this->output = '';
            $this->error = 0;
            $this->errors = array();

            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;
            $startTime = microtime(true);

            // Recuperer version pour log
            $versionInfo = $this->getModuleVersionInfo();

            $this->log("ShopifyOrderCatchupCron::executeCron - START - Entity: {$entity} | v{$versionInfo['version']}", LOG_INFO);

            // Protection timeout : 5 minutes max
            $oldTimeLimit = (int)ini_get('max_execution_time');
            if ($oldTimeLimit < 300) {
                @set_time_limit(300);
            }

            // Auto-detection et deblocage CRON bloque
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/shopifyordercatchupcron.class.php');

            // Story 36.4 : verrou anti double-exécution au niveau CRON (AC #8, non par boutique)
            if (!$this->acquireCronLock('orders_catchup')) {
                $this->log("ShopifyOrderCatchupCron::executeCron - Another instance is already running, skipping", LOG_INFO);
                $this->output = 'Order catchup skipped: another instance is already running';
                @set_time_limit($oldTimeLimit);
                return 0;
            }

            // Recuperer le lookback configurable (defaut: 168h = 7 jours)
            $lookbackHours = getDolGlobalInt('DOLI2SHOP_CATCHUP_LOOKBACK_HOURS', 168);

            // Epic 47-4 : boucle sur boutiques actives (AC #6)
            $storeService = $this->createStoreService($entity);
            $stores = $storeService->getAll(true);

            // Compteurs agrégés
            $totalChecked     = 0;
            $totalMissing     = 0;
            $totalImported    = 0;
            $totalFailed      = 0;
            $totalSkipped     = 0;
            $globalResult     = 0;

            if (empty($stores)) {
                // Rétrocompat : 0 boutique en table → run unique entité/constantes
                $this->log("ShopifyOrderCatchupCron::executeCron - No active stores — falling back to single entity run (retrocompat)", LOG_INFO);
                $runResult = $this->runCatchupForStore($entity, null, $lookbackHours);
                $totalChecked  += $runResult['checked'];
                $totalMissing  += $runResult['missing'];
                $totalImported += $runResult['imported'];
                $totalFailed   += $runResult['failed'];
                $totalSkipped  += $runResult['skipped_pending'];
                if ($runResult['failed'] > 0) {
                    $globalResult = -1;
                }
            } else {
                $this->log("ShopifyOrderCatchupCron::executeCron - Found " . count($stores) . " active store(s), iterating", LOG_INFO);
                foreach ($stores as $store) {
                    $storeLabel = $store->shop_domain ?? ('store#' . ($store->rowid ?? '?'));
                    // AC #6 : skip + warning si boutique sans credentials
                    if (empty($store->access_token) || empty($store->shop_domain)) {
                        $this->log("ShopifyOrderCatchupCron::executeCron - Store without credentials (domain=" . $storeLabel . "), skipping", LOG_WARNING);
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
                        $this->log("ShopifyOrderCatchupCron::executeCron - Store " . $storeLabel . " skipped (license " . $licenceCheck['reason'] . ")", LOG_WARNING);
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
                    try {
                        $runResult = $this->runCatchupForStore($entity, $store, $lookbackHours);
                        $totalChecked  += $runResult['checked'];
                        $totalMissing  += $runResult['missing'];
                        $totalImported += $runResult['imported'];
                        $totalFailed   += $runResult['failed'];
                        $totalSkipped  += $runResult['skipped_pending'];
                        if ($runResult['failed'] > 0) {
                            $globalResult = -1;
                        }
                    } catch (\Throwable $e) {
                        $this->log("ShopifyOrderCatchupCron::executeCron - Store " . $storeLabel . " failed: " . $e->getMessage() . " — continuing", LOG_WARNING);
                        $this->output .= "\n[WARN] Store " . $storeLabel . " error: " . $e->getMessage();
                    }
                }
            }

            $elapsedSec = round(microtime(true) - $startTime, 2);

            $this->output = "Order catchup: "
                . $totalChecked . " checked, "
                . $totalMissing . " missing, "
                . $totalImported . " imported, "
                . $totalFailed . " failed, "
                . $totalSkipped . " skipped_pending"
                . " (" . $elapsedSec . "s)"
                . $this->output; // Ajouter les warnings boutiques

            $this->log("ShopifyOrderCatchupCron::executeCron - COMPLETED in {$elapsedSec}s", LOG_INFO);

            // Restaurer limite temps
            @set_time_limit($oldTimeLimit);

            // Retourner erreur si des imports ont echoue
            if ($totalFailed > 0) {
                $this->error = $totalFailed;
                return -1;
            }

            return 0;

        } catch (\Throwable $e) {
            $errorMsg = "ShopifyOrderCatchupCron::executeCron - FATAL: " . $e->getMessage()
                . " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            $this->log($errorMsg, LOG_ERR);

            $this->output = "Order catchup failed: " . $e->getMessage();
            $this->error = 1;
            $this->errors[] = $e->getMessage();

            // Restaurer limite temps meme en cas d'exception
            if (isset($oldTimeLimit)) {
                @set_time_limit($oldTimeLimit);
            }

            return -1;
        } finally {
            // Story 36.4 : toujours libérer le verrou (no-op si non détenu par cette session)
            $this->releaseCronLock('orders_catchup');
        }
    }

    /**
     * Exécute le rattrapage des commandes manquantes pour une boutique (ou entité).
     *
     * Pattern extrait de executeCron pour permettre l'itération multi-boutique.
     * Vérifie la configuration API avant de lancer catchupMissingOrders().
     *
     * @param  int         $entity       Entité Dolibarr
     * @param  object|null $store        Objet boutique (null = chemin historique entité/constantes)
     * @param  int         $lookbackHours Fenêtre de rattrapage en heures
     * @return array       Compteurs ['checked','missing','imported','failed','skipped_pending']
     */
    protected function runCatchupForStore(int $entity, $store, int $lookbackHours): array
    {
        $emptyCounters = array(
            'checked'         => 0,
            'missing'         => 0,
            'imported'        => 0,
            'failed'          => 0,
            'skipped_pending' => 0,
        );

        $storeLabel = ($store !== null) ? ($store->shop_domain ?? 'store#' . ($store->rowid ?? '?')) : 'entity-fallback';

        // Verifier que la configuration est complete pour cette boutique/entité
        $shopifyApi = new ShopifyApi($this->db, $entity, $store);
        if (!$shopifyApi->isConfigurationComplete('orders')) {
            $this->log("ShopifyOrderCatchupCron::runCatchupForStore - Configuration incomplete for store=" . $storeLabel . ", skipping", LOG_DEBUG);
            return $emptyCounters;
        }

        $this->log("ShopifyOrderCatchupCron::runCatchupForStore - Running catchup for store=" . $storeLabel . " lookback=" . $lookbackHours . "h", LOG_INFO);

        $manager = new ShopifyOrderManager($this->db, $entity, $store);
        $counters = $manager->catchupMissingOrders($lookbackHours);

        $this->log(
            "ShopifyOrderCatchupCron::runCatchupForStore - store=" . $storeLabel
            . " checked=" . $counters['checked']
            . " imported=" . $counters['imported']
            . " failed=" . $counters['failed'],
            LOG_INFO
        );

        return $counters;
    }
}
