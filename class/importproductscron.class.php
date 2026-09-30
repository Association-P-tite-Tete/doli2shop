<?php

/**
 * @file        class/importproductscron.class.php
 * @brief This file contains the ImportProductsCron class
 * @note Class for handling product import cron jobs
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category cron
 * @version     2.6.0
 * @since 2.0.1
 * @author      P'tite Tête <doli2shop@ptitetete.com>
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @link        https://doli2shop.ptitetete.org
 */

// FIX #152 v2.1.2: Protection contre accès direct (compatible CRON et modules)
// CRITIQUE: Double-check nécessaire pour supporter init(), admin/, et CRON
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/core/class/commonobject.class.php');
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/runjournal.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';
dol_include_once('/doli2shop/class/importproducts.class.php');

/**
 * Class for handling product import cron jobs
 */
class ImportProductsCron extends CommonObject
{
    use LoggerTrait;
    use CronHelperTrait;

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'doli2shop_importproductscron';

    /**
     * @var string ID to identify managed object
     */
    public $element = 'importproductscron';

    /**
     * @var string Name of icon for importproductscron . Must be a 'fa-xxx' fontawesome code
     */
    public $picto = 'fa-sync';

    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var int Error code (or message)
     */
    public $error;

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * @var array Array of label of error
     */
    public $errorMessages = array();

    /**
     * @var string Description
     */
    public $description;

    /**
     * @var int Importance (0=low, 1=medium, 2=high)
     */
    public $priority = 0;

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
     * Create object into database
     *
     * @param  User $user      User that creates
     * @param  bool $notrigger false=launch triggers after, true=disable triggers
     * @return int             <0 if KO, Id of created object if OK
     */
    public function create($user, $notrigger = false)
    {
        return $this->createCommon($user, $notrigger);
    }

    /**
     * Load object in memory from the database
     *
     * @param int    $id   Id object
     * @param string $ref  Ref
     * @return int         <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }

    /**
     * Update object into database
     *
     * @param  User $user      User that modifies
     * @param  bool $notrigger false=launch triggers after, true=disable triggers
     * @return int             <0 if KO, >0 if OK
     */
    public function update($user, $notrigger = false)
    {
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete object in database
     *
     * @param  User $user      User that deletes
     * @param  bool $notrigger false=launch triggers after, true=disable triggers
     * @return int             <0 if KO, >0 if OK
     */
    public function delete($user, $notrigger = false)
    {
        return $this->deleteCommon($user, $notrigger);
    }

    /**
     * Instancie StoreService pour l'entité donnée.
     * Méthode protégée pour permettre le mock en test.
     *
     * @param  int $entity Entité Dolibarr
     * @return StoreService
     */
    protected function createStoreService(int $entity): StoreService
    {
        return new StoreService($this->db, $entity);
    }

    /**
     * Execute cron job — boucle sur les boutiques actives (AC #5, #7, #10).
     * Rétrocompat : 0 boutique → run unique entité (constantes).
     *
     * @param  object $conf     Conf object
     * @param  object $langs    Langs object
     * @return int              0 if OK, >0 if error
     */
    public function executeCron($conf = null, $langs = null)
    {
        global $conf, $langs;

        // FIX #152 v2.1.2: TRY/CATCH GLOBAL pour capturer TOUTE erreur silencieuse
        try {
            dol_syslog("ImportProductsCron::executeCron - ENTRY POINT - Starting execution", LOG_INFO);

            $this->output = '';
            $this->error = 0;

            // FIX #140 v2.1.1: Déterminer entity AVANT tout logging
            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;

            // FIX #152 v2.1.2: Récupération version/build via méthode générique CronHelperTrait
            $versionInfo = $this->getModuleVersionInfo();

            // FIX #140 v2.1.1: LOG OBLIGATOIRE au démarrage pour traçabilité
            $this->log("[START] PRODUCTS IMPORT CRON START - Entity: {$entity} | v{$versionInfo['version']} | Build: {$versionInfo['build']} | PHP timeout: " . ini_get('max_execution_time') . "s", LOG_INFO);
            $this->log("Auto-cleanup enabled: Invalid Shopify product IDs will be automatically reset", LOG_INFO);
            $this->output .= "Start import products (v{$versionInfo['version']} build:{$versionInfo['build']})";

        // FIX #140 v2.1.1: Protection timeout - Augmenter limite temps pour CRON (max 5 minutes)
        $oldTimeLimit = ini_get('max_execution_time');
        if ($oldTimeLimit < 300) {
            @set_time_limit(300); // 5 minutes
            $this->log("⏱️ Execution time limit extended: " . $oldTimeLimit . "s → 300s", LOG_DEBUG);
        }

        $startTime = microtime(true);

        try {
            // Define cron mode for context identification (Issue #39)
            // Detect if executed via web interface (manual cron execution) vs real automatic cron
            $isWebExecution = !empty($_SERVER['HTTP_HOST']) ||
                             !empty($_SERVER['REQUEST_URI']) ||
                             isset($_SESSION) ||
                             !empty($_SERVER['REMOTE_ADDR']);

            if (!defined('CRON_MODE')) {
                define('CRON_MODE', !$isWebExecution); // true only if really automatic
            }

            // FIX v2.1.2: Libérer locks SQL + auto-déblocage CRON gelé
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/importproductscron.class.php');

            // Story 36.4 : verrou anti double-exécution (non bloquant) — au niveau CRON, pas par boutique
            if (!$this->acquireCronLock('products_import')) {
                $this->log("ImportProductsCron::executeCron - Another instance is already running, skipping", LOG_INFO);
                $this->output .= "\n" . 'Product import skipped: another instance is already running';
                @set_time_limit($oldTimeLimit);
                return 0;
            }

            // Log execution context for debugging Issue #39
            $this->log("=== CRON EXECUTION CONTEXT ===", LOG_INFO);
            $executionType = $isWebExecution ? "MANUAL (cron executed via web interface)" : "AUTOMATIC (cron)";
            $this->log("Execution mode: " . $executionType, LOG_INFO);
            $this->log("Web execution detected: " . ($isWebExecution ? "YES" : "NO"), LOG_INFO);
            $this->log("User context: " . (getDolGlobalString('USER_CURRENT') !== '' ? "Set" : "None"), LOG_INFO);
            $this->log("Multi-price config: " . (getDolGlobalInt('PRODUIT_MULTIPRICES') ? "Enabled" : "Disabled"), LOG_INFO);
            if (getDolGlobalInt('PRODUIT_MULTIPRICES')) {
                $this->log("Multi-price limit: " . getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT'), LOG_INFO);
            }
            $this->log("==============================", LOG_INFO);

            // v2.2.2: Anti-loop flag — prevent webhook handlers from re-processing
            // products that we are currently exporting to Shopify
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;
            $this->log("Anti-loop flag SYNC_IN_PROGRESS set", LOG_DEBUG);

            // --- Boucle boutiques (AC #5, #7) ---
            // Récupérer les boutiques actives ; si table vide → fallback run unique (rétrocompat)
            $storeService = $this->createStoreService($entity);
            $stores = $storeService->getAll(true);

            $globalResult = 0;

            if (empty($stores)) {
                // Rétrocompat mono-boutique : 0 boutique en table → run unique sur entité/constantes
                $this->log("[INFO] No active stores in table — falling back to single entity run (retrocompat)", LOG_INFO);
                $globalResult = $this->runSyncForStore($entity, null, $startTime);
            } else {
                $this->log("[INFO] Found " . count($stores) . " active store(s), iterating per store", LOG_INFO);
                foreach ($stores as $store) {
                    $storeLabel = $store->shop_domain ?? ('store#' . $store->rowid);
                    try {
                        // AC#10 : boutique sans credentials → warning + continue
                        if (empty($store->access_token) || empty($store->shop_domain)) {
                            $this->log("[WARN] Store without credentials (shop_domain=" . $storeLabel . "), skipping", LOG_WARNING);
                            $this->output .= "\n[WARN] Store " . $storeLabel . " skipped (missing credentials)";
                            continue;
                        }

                        // Story 47-6, gate étendue par licence-gate-des-ecritures-sortantes (AC1) : la
                        // boutique par défaut passe désormais aussi par un gate réel (plus une
                        // exemption inconditionnelle) — cf. doli2shopEvaluateDefaultStoreSyncGate().
                        $licenceCheck = doli2shopStoreSyncAllowed($store);
                        if (!$licenceCheck['allowed']) {
                            // Blind Hunter (licence-gate-des-ecritures-sortantes) : la boutique par
                            // défaut ne doit JAMAIS être écrite en DB par ce CRON — invariant 49-8 —
                            // y compris ici. Avant cette story cette branche était morte pour
                            // is_default (toujours allowed=true) ; elle est désormais atteignable
                            // (sync_gate_active / failopen_expired), et écrivait 'invalid' sans
                            // garde, y compris quand le blocage vient d'une panne de NOTRE serveur
                            // (failopen_expired) et non d'une licence réellement invalide.
                            if (empty($store->is_default)) {
                                $storeService->setLicenseStatus((int) $store->rowid, 'invalid');
                            }
                            $this->log("[WARN] Store " . $storeLabel . " skipped (license " . $licenceCheck['reason'] . ")", LOG_WARNING);
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

                        $storeResult = $this->runSyncForStore($entity, $store, $startTime);
                        if ($storeResult < 0) {
                            $globalResult = $storeResult;
                            // On continue sur les autres boutiques même en cas d'erreur partielle
                        }
                    } catch (Exception $e) {
                        // AC#10 : boutique en erreur → log + continue
                        $this->log("[WARN] Store " . $storeLabel . " failed: " . $e->getMessage() . " — continuing", LOG_WARNING);
                        $this->output .= "\n[WARN] Store " . $storeLabel . " error: " . $e->getMessage();
                    }
                }
            }

            // FIX #140 v2.1.1: Logging temps d'exécution total
            $executionTime = round(microtime(true) - $startTime, 2);

            if ($globalResult < 0) {
                $this->log("[ERROR] CRON execution FAILED after " . $executionTime . "s", LOG_ERR);
                @set_time_limit($oldTimeLimit);
                return -1;
            }

            $this->log("[OK] CRON execution completed successfully in " . $executionTime . "s", LOG_INFO);
            $this->output .= "\n" . 'Import completed successfully (' . $executionTime . 's)';

            // Restaurer limite temps
            @set_time_limit($oldTimeLimit);

            return 0;
        } catch (Exception $e) {
            // v2.2.2: Ensure anti-loop flag is released on outer exception too
            unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);

            $this->error++;
            $this->errors[] = $e->getMessage();
            $this->output .= "\n" . 'Exception during import: ' . $e->getMessage();

            // FIX #140 v2.1.1: Logging temps d'exécution même en exception
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->log("[ERROR] CRON execution EXCEPTION after " . $executionTime . "s: " . $e->getMessage(), LOG_ERR);

            // Restaurer limite temps
            @set_time_limit($oldTimeLimit);

            return -1;
        } finally {
            // v2.2.2: toujours relâcher le flag anti-loop
            unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);
            $this->log("Anti-loop flag SYNC_IN_PROGRESS released", LOG_DEBUG);
            // Story 36.4 : toujours libérer le verrou (no-op si non détenu par cette session)
            $this->releaseCronLock('products_import');
        }

    // FIX #152 v2.1.2: CATCH GLOBAL pour capturer erreurs fatales AVANT le try interne
    } catch (Exception $e) {
        // v2.2.2: Ensure anti-loop flag is released on fatal exception
        unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);

        $errorMsg = "[ERROR] FATAL EXCEPTION in executeCron(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString();
        dol_syslog($errorMsg, LOG_ERR);

        $this->output = $errorMsg;
        $this->error = 1;
        return -1;
    } catch (Error $e) {
        // Capturer aussi les Error PHP 7+ (TypeError, ParseError, etc.)
        // v2.2.2: Ensure anti-loop flag is released on fatal PHP error
        unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);

        $errorMsg = "[ERROR] FATAL PHP ERROR in executeCron(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString();
        dol_syslog($errorMsg, LOG_ERR);

        $this->output = $errorMsg;
        $this->error = 1;
        return -1;
    }
}

    /**
     * Exécute la synchronisation produits pour UNE boutique (ou le chemin entité si $store=null).
     * Méthode extraite pour permettre la boucle multi-boutique et les tests unitaires.
     *
     * @param  int         $entity    Entité Dolibarr
     * @param  object|null $store     Objet boutique (null = chemin historique entité/constantes)
     * @param  float       $startTime microtime de début (pour logging durée)
     * @return int                    0=OK, -1=erreur
     */
    /**
     * Rowid de la boutique, ou null.
     *
     * @param  object|null $store Boutique
     * @return int|null
     */
    protected static function storeIdOf($store)
    {
        return (!empty($store) && !empty($store->id)) ? (int) $store->id : null;
    }

    /**
     * Compteurs de bilan d'un cycle d'import.
     *
     * Ce sont exactement les compteurs qui vivaient jusqu'ici en LOG_WARNING, noyés dans un
     * fichier partagé : ils deviennent le bilan lisible du run.
     *
     * @param  ImportProducts $importer Importeur au terme du cycle
     * @return array<string,int>
     */
    protected static function countersOf($importer)
    {
        return [
            'stock_echecs' => (int) ($importer->stockSyncFailedCount ?? 0),
            'images_sautees' => (int) ($importer->imagesSyncSkippedCount ?? 0),
            'images_renvoyees' => (int) ($importer->imagesResyncForcedCount ?? 0),
            'images_sans_source' => (int) ($importer->imagesNoSourceFoundCount ?? 0),
            // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : échecs de
            // CRÉATION de médias (anciennes photos préservées, nouvelle tentative au prochain
            // cycle) — même convention que les compteurs d'images ci-dessus.
            'images_creation_echouee' => (int) ($importer->imagesCreationFailedCount ?? 0),
            // MEDIUM (review 3 couches 27/09/2026) : échecs de SUPPRESSION après une création
            // réussie (doublon possible, jamais de perte de photo) — même convention.
            'images_suppression_echouee' => (int) ($importer->imagesDeleteFailedCount ?? 0),
            // HIGH (re-review 27/09/2026, point 1) : produits DIFFÉRÉS (budget de polling déjà
            // épuisé, ou lot précédent encore en cours) — ni échec ni réussite.
            'images_differees' => (int) ($importer->imagesDeferredForBudgetCount ?? 0),
            'stock_emplacement_plafonne' => (int) ($importer->stockLocationCappedCount ?? 0),
        ];
    }

    protected function runSyncForStore(int $entity, $store, float $startTime): int
    {
        $storeLabel = ($store !== null) ? ($store->shop_domain ?? ('store#' . $store->rowid)) : 'entity-fallback';
        $this->log("[STORE] Starting sync for: " . $storeLabel, LOG_INFO);

        // Vérifier la configuration POUR CETTE BOUTIQUE
        $shopifyApi = new ShopifyApi($this->db, $entity, $store);
        if (!$shopifyApi->isConfigurationComplete('products')) {
            $this->log("[ERROR] Configuration incomplete for store: " . $storeLabel . " — aborting store sync", LOG_ERR);
            $this->output .= "\n" . '[ERROR] Configuration incomplete for store ' . $storeLabel . ' - sync aborted';
            return -1;
        }

        $this->log("[OK] Configuration verified for store: " . $storeLabel, LOG_INFO);

        // Journal de RUN — la ligne de DÉMARRAGE est posée AVANT le travail, et c'est tout son
        // intérêt : sans elle, un ordonnanceur à l'arrêt et un ordonnanceur qui tourne sans rien
        // avoir à faire laissent la même absence de trace. Story
        // journal-de-run-identifiant-et-provenance.
        RunJournal::start($entity, RunJournal::ORIGIN_CRON, 'Synchronisation produits - ' . $storeLabel, self::storeIdOf($store));

        $importer = new ImportProducts($this->db, $entity, $store);
        $result = $importer->importProducts();

        if ($result < 0) {
            $this->error++;
            $this->output .= "\n" . 'Error during import for store ' . $storeLabel . ': ' . $importer->error;
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->log("[ERROR] Store sync FAILED for " . $storeLabel . " after " . $executionTime . "s", LOG_ERR);
            RunJournal::finish($entity, self::countersOf($importer), ActionLogger::RESULT_ERROR, self::storeIdOf($store));
            return -1;
        }

        // FIX v2.4.1 (hotfix) : résumé visible des échecs de sync stock (compteur remonté
        // par ImportProducts::updateInventoryQuantities() — auparavant les échecs de stock
        // batch (compare-and-swap / userErrors) étaient totalement silencieux)
        if (!empty($importer->stockSyncFailedCount)) {
            $this->output .= "\n" . '[WARN] Store ' . $storeLabel . ': ' . $importer->stockSyncFailedCount . ' item(s) en échec de sync stock (voir logs LOG_ERR)';
            $this->log("[STOCK] " . $importer->stockSyncFailedCount . " item(s) en échec de sync stock pour store " . $storeLabel, LOG_ERR);
        }

        // Hotfix 2.5.7 (review 3 couches 26/09/2026) : échecs STRUCTURELS d'emplacement — distinct
        // du compteur ci-dessus, signale un problème de CONFIGURATION (shopify_location_id
        // invalide/introuvable) qu'aucun retry ne résoudra, pas un simple incident réseau.
        if (!empty($importer->stockSyncLocationErrorCount)) {
            $this->output .= "\n" . '[ERROR] Store ' . $storeLabel . ': ' . $importer->stockSyncLocationErrorCount . ' erreur(s) STRUCTURELLE(S) d\'emplacement Shopify (verifier shopify_location_id, voir logs LOG_ERR)';
            $this->log("[STOCK-LOCATION] " . $importer->stockSyncLocationErrorCount . " erreur(s) structurelle(s) d'emplacement pour store " . $storeLabel, LOG_ERR);
        }

        // Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3) : articles
        // ACTUELLEMENT plafonnés (référence de stock non résolue depuis N cycles consécutifs) —
        // distinct du compteur ci-dessus (une erreur GraphQL GLOBALE), ce cas ne produit AUCUNE
        // erreur GraphQL, juste une absence de niveau de stock pour l'article à l'emplacement.
        if (!empty($importer->stockLocationCappedCount)) {
            $this->output .= "\n" . '[WARN] Store ' . $storeLabel . ': ' . $importer->stockLocationCappedCount . ' article(s) plafonne(s) (jamais active(s) a l\'emplacement Shopify, action manuelle requise, voir logs LOG_WARNING)';
            $this->log("[STOCK-LOCATION-CAPPED] " . $importer->stockLocationCappedCount . " article(s) plafonne(s) pour store " . $storeLabel, LOG_WARNING);
        }

        // Story variante-sans-correspondance-sku-ignoree-en-silence (AC1/AC2/AC3) : même
        // convention que $stockSyncFailedCount ci-dessus — compteur remonté par
        // ImportProducts::reportUnmatchedVariants(), auparavant totalement silencieux
        // (le "if" sans "else" d'updateVariantInventory()).
        if (!empty($importer->unmatchedVariantsCount)) {
            $this->output .= "\n" . '[WARN] Store ' . $storeLabel . ': ' . $importer->unmatchedVariantsCount . ' variante(s)/déclinaison(s) sans correspondance de SKU (voir logs LOG_WARNING, et l\'écran de diagnostic)';
            $this->log("[VARIANTS] " . $importer->unmatchedVariantsCount . " variante(s)/déclinaison(s) sans correspondance de SKU pour store " . $storeLabel, LOG_WARNING);
        }

        // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC2/AC3) : même convention —
        // compteur remonté par ImportProducts::syncProductAllImages(), auparavant totalement
        // silencieux (LOG_INFO noyé). C'est LE signal du symptôme Xavier Hubier (Europe Loisirs,
        // 13/09/2026) : hash Dolibarr identique mais Shopify ne détenait plus les médias attendus.
        if (!empty($importer->imagesResyncForcedCount)) {
            $this->output .= "\n" . '[WARN] Store ' . $storeLabel . ': ' . $importer->imagesResyncForcedCount . ' produit(s) avec images renvoyées après divergence Shopify (hash identique mais médias absents/différents, voir logs LOG_WARNING)';
            $this->log("[IMAGES] " . $importer->imagesResyncForcedCount . " produit(s) avec renvoi d'images forcé après divergence Shopify pour store " . $storeLabel, LOG_WARNING);
        }

        // Même convention, pour le cas où le module ne trouve AUCUNE image à envoyer : l'abandon
        // est légitime mais il laissait le produit sans photo côté Shopify en silence.
        if (!empty($importer->imagesNoSourceFoundCount)) {
            $refs = implode(', ', $importer->imagesNoSourceFoundRefs);
            if ($importer->imagesNoSourceFoundCount > count($importer->imagesNoSourceFoundRefs)) {
                $refs .= ' (+' . ($importer->imagesNoSourceFoundCount - count($importer->imagesNoSourceFoundRefs)) . ')';
            }
            $this->output .= "\n" . '[WARN] Store ' . $storeLabel . ': ' . $importer->imagesNoSourceFoundCount
                . ' produit(s) laisses SANS PHOTO cote Shopify, faute d\'image trouvee cote Dolibarr (ni index llx_ecm_files, ni disque) : ' . $refs;
            $this->log("[IMAGES] " . $importer->imagesNoSourceFoundCount . " produit(s) sans source d'image cote Dolibarr pour store " . $storeLabel . " : " . $refs, LOG_WARNING);
        }

        // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : échecs de
        // CRÉATION de médias — anciennes photos préservées (aucune suppression), nouvelle
        // tentative au prochain cycle. Même convention que les compteurs d'images ci-dessus.
        if (!empty($importer->imagesCreationFailedCount)) {
            $refs = implode(', ', $importer->imagesCreationFailedRefs);
            if ($importer->imagesCreationFailedCount > count($importer->imagesCreationFailedRefs)) {
                $refs .= ' (+' . ($importer->imagesCreationFailedCount - count($importer->imagesCreationFailedRefs)) . ')';
            }
            $this->output .= "\n" . '[ERROR] Store ' . $storeLabel . ': ' . $importer->imagesCreationFailedCount
                . ' produit(s) en echec de creation de medias Shopify (anciennes photos preservees, nouvelle tentative au prochain cycle) : ' . $refs;
            $this->log("[IMAGES] " . $importer->imagesCreationFailedCount . " produit(s) en echec de creation de medias pour store " . $storeLabel . " : " . $refs, LOG_ERR);
        }

        // MEDIUM (review 3 couches 27/09/2026) : échecs de SUPPRESSION après une création
        // réussie — doublon possible (ancien + nouveau média), jamais de perte de photo.
        if (!empty($importer->imagesDeleteFailedCount)) {
            $refs = implode(', ', $importer->imagesDeleteFailedRefs);
            if ($importer->imagesDeleteFailedCount > count($importer->imagesDeleteFailedRefs)) {
                $refs .= ' (+' . ($importer->imagesDeleteFailedCount - count($importer->imagesDeleteFailedRefs)) . ')';
            }
            $this->output .= "\n" . '[ERROR] Store ' . $storeLabel . ': ' . $importer->imagesDeleteFailedCount
                . ' produit(s) en echec de suppression des anciens medias apres creation reussie (doublon possible) : ' . $refs;
            $this->log("[IMAGES] " . $importer->imagesDeleteFailedCount . " produit(s) en echec de suppression pour store " . $storeLabel . " : " . $refs, LOG_ERR);
        }

        // HIGH (re-review 27/09/2026, point 1) : produits DIFFEREES (budget de polling deja
        // epuise, ou lot precedent encore en cours de traitement) - ni echec ni reussite, mais
        // traites en PRIORITE au prochain cycle.
        if (!empty($importer->imagesDeferredForBudgetCount)) {
            $refs = implode(', ', $importer->imagesDeferredForBudgetRefs);
            if ($importer->imagesDeferredForBudgetCount > count($importer->imagesDeferredForBudgetRefs)) {
                $refs .= ' (+' . ($importer->imagesDeferredForBudgetCount - count($importer->imagesDeferredForBudgetRefs)) . ')';
            }
            $this->output .= "\n" . '[INFO] Store ' . $storeLabel . ': ' . $importer->imagesDeferredForBudgetCount
                . ' produit(s) differe(s) (budget de polling epuise ou lot precedent en cours), priorite au prochain cycle : ' . $refs;
            $this->log("[IMAGES] " . $importer->imagesDeferredForBudgetCount . " produit(s) differe(s) pour store " . $storeLabel . " : " . $refs, LOG_INFO);
        }

        RunJournal::finish($entity, self::countersOf($importer), ActionLogger::RESULT_SUCCESS, self::storeIdOf($store));

        $this->log("[OK] Sync completed for store: " . $storeLabel, LOG_INFO);
        return 0;
    }

}
