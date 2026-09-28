<?php

/**
 * @file        class/shopifyhistoricalimportcron.class.php
 * @brief       CRON dédié pour l'import historique des commandes Shopify
 *
 * v2.2.0 Story 14.2: Refactorise pour utiliser processSingleOrderFromWebhook().
 * Chaque commande est maintenant importee avec le flux complet : commande + facture + paiement.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.2
 * @link        http://www.dolibarr.org
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
dol_include_once('/doli2shop/class/shopifyordermanager.class.php');

// Story 63-18 (Task 2/3) : doli2shopIsHistoricalImportPassComplete() — critère de complétion pur,
// testable sans dépendances Dolibarr/Shopify (test/unit/HistoricalImportPassCompleteTest.php).
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}


/**
 * Class ShopifyHistoricalImportCron
 *
 * CRON dédié pour l'import historique des commandes Shopify.
 *
 * v2.2.0 Story 14.2: Utilise désormais processSingleOrderFromWebhook() pour chaque commande,
 * garantissant le flux complet (commande + facture + paiement) identique aux webhooks et au catchup.
 *
 * @since 2.1.2
 */
class ShopifyHistoricalImportCron
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDB */
    private $db;

    /** @var string */
    public $output;

    /** @var int */
    public $error = 0;

    /** @var array */
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
     * Execute historical import cron job
     *
     * v2.2.0 Story 14.2: Refactorisé — boucle sur fetchHistoricalOrdersBatch()
     * + processSingleOrderFromWebhook() pour chaque commande.
     *
     * @param object $conf Config object
     * @param object $langs Lang object
     * @return int 0 if OK, <0 if error
     */
    public function executeCron($conf = null, $langs = null)
    {
        global $conf, $langs;

        // TRY/CATCH GLOBAL pour capturer TOUTE erreur silencieuse
        try {
            dol_syslog("ShopifyHistoricalImportCron::executeCron - ENTRY POINT - Starting execution", LOG_INFO);

            $this->output = '';
            $this->error = 0;

            // Déterminer entity AVANT tout logging
            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;

            // Récupération version/build via méthode générique CronHelperTrait
            $versionInfo = $this->getModuleVersionInfo();

            // LOG OBLIGATOIRE au démarrage pour traçabilité
            $this->log("HISTORICAL IMPORT CRON START - Entity: {$entity} | v{$versionInfo['version']} | Build: {$versionInfo['build']} | PHP timeout: " . ini_get('max_execution_time') . "s", LOG_INFO);

            $this->output .= "Start historical import (v{$versionInfo['version']} build:{$versionInfo['build']})";

            // Protection timeout - Augmenter limite temps pour CRON (max 5 minutes)
            $oldTimeLimit = ini_get('max_execution_time');
            if ($oldTimeLimit < 300) {
                @set_time_limit(300);
                $this->log("Execution time limit extended: " . $oldTimeLimit . "s -> 300s", LOG_DEBUG);
            }

            $startTime = microtime(true);

            try {
                // Libérer locks SQL + auto-déblocage CRON gelé
                $this->checkAndUnfreezeCron($entity, '/doli2shop/class/shopifyhistoricalimportcron.class.php');

                // Story 36.4 : verrou anti double-exécution (non bloquant)
                if (!$this->acquireCronLock('historical_import')) {
                    $this->log("ShopifyHistoricalImportCron::executeCron - Another instance is already running, skipping", LOG_INFO);
                    $this->output .= "\n" . 'Historical import skipped: another instance is already running';
                    @set_time_limit($oldTimeLimit);
                    return 0;
                }

                // Verify configuration is complete before proceeding
                $shopifyApi = new ShopifyApi($this->db, $entity);
                if (!$shopifyApi->isConfigurationComplete('orders')) {
                    $this->log("[ERROR] Configuration incomplete for orders, aborting historical import", LOG_ERR);
                    $this->output .= "\n" . '[ERROR] Configuration incomplete - historical import aborted';
                    @set_time_limit($oldTimeLimit);
                    return -1;
                }

                $this->log("[OK] Configuration verified, proceeding with historical import", LOG_INFO);

                // Hotfix 2.5.2 (AC4) — revu et laissé tel quel : ce CRON reste le SEUL chemin du
                // module sans aucune logique multi-boutique (`ShopifyApi`/`ShopifyOrderManager`
                // instanciés ci-dessus et ci-dessous sans `$store`, fkStore=0, lecture des
                // constantes DOLI2SHOP_*). Une refonte pour boucler par boutique (pattern
                // ShopifyOrderCatchupCron::runCatchupForStore()) est hors périmètre de ce hotfix
                // (feature à part, pas un correctif). Le plancher exigé par l'AC4 — "à défaut de
                // refonte, lire la même copie que le refresh écrit" — est satisfait par le
                // Hotfix 2.5.2 lui-même : ShopifyApi::persistRefreshedToken() synchronise
                // désormais les constantes avec la ligne `stores` de la boutique par défaut,
                // quel que soit le chemin qui a déclenché le refresh (cf. class/shopifyapi.class.php).
                // Nuance apportée par la review 3 couches : "synchronise" n'est pas une garantie
                // absolue et instantanée — si SEULE la synchro best-effort échoue (panne SQL
                // transitoire isolée, cf. persistRefreshedToken(), point 3 de la review), les
                // constantes lues ici peuvent rester périmées jusqu'au refresh SUIVANT, qui se
                // corrige alors tout seul (fetchFreshTokenState() adopte la copie la plus
                // fraîche, quel que soit le chemin qui a réussi le dernier refresh). Il n'existe
                // en revanche AUCUNE fenêtre où les deux copies restent DURABLEMENT
                // désynchronisées — c'était exactement le défaut CRITICAL d'origine.
                // Reste un angle mort assumé : sur une install MULTI-boutique, seule la boutique
                // par défaut est jamais importée par ce CRON — les boutiques secondaires ne le
                // sont pas. Ce gap est distinct du défaut CRITICAL de ce hotfix (deux copies pour
                // UNE boutique) ; à traiter dans une story dédiée si constaté chez un client.
                // Créer le manager
                $manager = new ShopifyOrderManager($this->db, $entity);

                // v2.2.0 Story 14.2: Flag anti-boucle — empêcher triggers Dolibarr de renvoyer vers Shopify
                $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;

                // Récupérer les dates de configuration pour l'import historique
                $createdAtMin = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_START_DATE', '');
                $createdAtMax = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_END_DATE', '');
                $resumeDate = getDolGlobalString('DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE', '');

                // Utiliser la date de reprise si disponible
                if (!empty($resumeDate)) {
                    $createdAtMin = $resumeDate;
                    $this->log("Using resume date: " . $resumeDate, LOG_INFO);
                } else {
                    $this->log("Using start date: " . ($createdAtMin ?: 'none'), LOG_INFO);
                }
                $this->log("End date: " . ($createdAtMax ?: 'none'), LOG_INFO);

                if (empty($createdAtMin)) {
                    $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 0;
                    $this->log("[ERROR] No start date configured for historical import", LOG_ERR);
                    $this->output .= "\n" . '[ERROR] No start date configured';
                    @set_time_limit($oldTimeLimit);
                    return -1;
                }

                // v2.2.0 Story 14.2: Boucle paginée avec processSingleOrderFromWebhook
                $counters = array(
                    'total_checked' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    // Story reglage-commandes-non-payees-ignore-par-les-webhooks (HIGH n°3, Code
                    // Review du 09/09/2026) : sous-ensemble de 'skipped' (n'en est PAS retiré — la
                    // sémantique existante de 'skipped'/handledTotal reste inchangée pour
                    // admin/setup.php), qui isole les commandes refusées par le gate financier
                    // (ShopifyOrderManager::$lastSkipReason non null) d'un doublon ordinaire.
                    'skipped_unpaid' => 0,
                );
                $cursor = null;
                $batchSize = 20;
                $maxImportsPerRun = getDolGlobalInt('DOLI2SHOP_HISTORICAL_MAX_PER_RUN', 50);
                $lastOrderCreatedAt = null;
                // Story 63-18 (review 24/08, CRITICAL) : distinct de "plage vide" — voir
                // fetchHistoricalOrdersBatch() (shopifyordermanager.class.php) et
                // doli2shopDecideHistoricalImportCompletionAction() (lib/doli2shop.lib.php).
                $batchHadError = false;

                do {
                    // Fetch un batch de commandes avec le field set complet
                    $batch = $manager->fetchHistoricalOrdersBatch($createdAtMin, $createdAtMax, $cursor, $batchSize);

                    if (!empty($batch['error'])) {
                        // Réponse Shopify inexploitable (401 persistant, throttle épuisé, erreur
                        // GraphQL, ou garde "aucun filtre") : on arrête cette passe SANS toucher à
                        // l'état de complétion (cf. doli2shopDecideHistoricalImportCompletionAction()
                        // ci-dessous) — le progrès déjà accompli dans cette passe (compteurs,
                        // resume_date) reste persisté normalement.
                        $batchHadError = true;
                        $this->log("[ERROR] fetchHistoricalOrdersBatch returned an error state — pass aborted, completion state left untouched", LOG_ERR);
                        $this->output .= "\n" . '[ERROR] Historical import: réponse Shopify inexploitable, passe interrompue';
                        break;
                    }

                    if (empty($batch['orders'])) {
                        $this->log("No more orders to process in batch", LOG_INFO);
                        break;
                    }

                    $this->log("Batch retrieved: " . count($batch['orders']) . " orders (cursor: " . ($cursor ?: 'start') . ")", LOG_INFO);

                    foreach ($batch['orders'] as $orderNode) {
                        $counters['total_checked']++;

                        // Extraire l'ID numérique Shopify depuis le GID
                        $shopifyOrderId = str_replace('gid://shopify/Order/', '', $orderNode->id);
                        $shopifyOrderName = isset($orderNode->name) ? $orderNode->name : '';

                        // Vérifier limite d'imports par exécution
                        if ($counters['imported'] >= $maxImportsPerRun) {
                            $this->log("Max imports per run reached (" . $maxImportsPerRun . "), saving resume point", LOG_INFO);
                            break 2;
                        }

                        // Traiter la commande via le flux unifié (commande + facture + paiement)
                        // Review fix M2: try/finally pour garantir la restauration des erreurs
                        $errorsBefore = $manager->errors;
                        $manager->errors = array();
                        $result = -99; // Sentinel pour détecter les exceptions non-catchées

                        try {
                            $result = $manager->processSingleOrderFromWebhook($shopifyOrderId, $orderNode);
                        } catch (Exception $orderException) {
                            $manager->errors[] = $orderException->getMessage();
                            $this->log("[ERROR] Exception processing order " . $shopifyOrderName . ": " . $orderException->getMessage(), LOG_ERR);
                        } finally {
                            // Restauration garantie même en cas d'exception
                            $manager->errors = array_merge($errorsBefore, $manager->errors);
                        }

                        // Story reglage-commandes-non-payees-ignore-par-les-webhooks (HIGH n°3,
                        // Code Review du 09/09/2026) : lu IMMÉDIATEMENT après l'appel, avant que
                        // l'itération suivante n'écrase $manager->lastSkipReason (remis à null en
                        // tête de CHAQUE processSingleOrderFromWebhook(), l'instance étant réutilisée
                        // en boucle par cet import historique).
                        $skipReasonForThisOrder = $manager->lastSkipReason;

                        if ($result > 0) {
                            $counters['imported']++;
                            $this->log("Imported order " . $shopifyOrderName . " (Shopify #" . $shopifyOrderId . ") -> Dolibarr #" . $result, LOG_INFO);
                        } elseif ($result == 0) {
                            // Retour 0 = commande existante (catch-up) OU refus explicite du gate
                            // financier (ShopifyOrderManager::$lastSkipReason non null) — AVANT ce
                            // correctif, les deux étaient journalisés et comptés IDENTIQUEMENT comme
                            // "already exists", rendant un refus financier explicite indiscernable
                            // d'un doublon (HIGH n°3). Comptée dans les deux compteurs ('skipped' ET
                            // 'skipped_unpaid') : 'skipped_unpaid' est un SOUS-ENSEMBLE diagnostic de
                            // 'skipped', pas un remplacement — la sémantique existante de 'skipped'
                            // (utilisée par admin/setup.php pour handledTotal/pourcentage) reste
                            // intacte.
                            $counters['skipped']++;
                            if ($skipReasonForThisOrder !== null) {
                                $counters['skipped_unpaid']++;
                                $this->log("Skipped order " . $shopifyOrderName . " (Shopify #" . $shopifyOrderId . "): refus explicite du gate financier (lastSkipReason=" . $skipReasonForThisOrder . "), PAS un doublon", LOG_INFO);
                            } else {
                                $this->log("Skipped order " . $shopifyOrderName . " (already exists, catch-up applied)", LOG_DEBUG);
                            }
                        } else {
                            $counters['failed']++;
                            $importErrors = implode(', ', $manager->errors);
                            $this->log("[ERROR] Failed to import order " . $shopifyOrderName . ": " . $importErrors, LOG_ERR);
                        }

                        // v2.2.0 Review fix H2: resume_date avance SEULEMENT pour les commandes traitées avec succès
                        // (imported ou skipped), PAS pour les failed — pour permettre la re-tentative au prochain run
                        if ($result >= 0 && isset($orderNode->createdAt)) {
                            $lastOrderCreatedAt = $orderNode->createdAt;
                        }

                        // Review fix L1: Rate limiting sur TOUS les traitements (imported + skipped avec catch-up API)
                        if ($counters['total_checked'] > 0 && $counters['total_checked'] % 5 == 0) {
                            usleep(500000);
                        }
                    }

                    // Pagination : passer au batch suivant
                    $cursor = $batch['endCursor'];

                } while ($batch['hasNextPage'] && $counters['imported'] < $maxImportsPerRun);

                // Sauvegarder le resume_date pour la reprise au prochain run.
                // Story 63-18 (review 24/08, MEDIUM/HIGH — TOCTOU) : $resumeDate a été lu en tête de
                // passe (jusqu'à ~300s plus tôt, cf. set_time_limit(300) ci-dessus). Entre-temps,
                // admin/setup.php peut avoir réaligné CETTE MÊME constante suite à une correction de
                // START_DATE par un administrateur (AC1) — dont le message de succès s'affiche
                // immédiatement à l'écran. Écrire ici sans vérifier écraserait cette correction en
                // silence dès que le CRON termine sa passe, ce que l'AC1 interdit explicitement
                // ("jamais en silence"). Relecture FRAÎCHE (SQL direct, PAS getDolGlobalString() —
                // $conf->global est un cache chargé une fois au démarrage du process CRON, il ne
                // verrait jamais une écriture faite par le process web pendant l'exécution) : on
                // n'écrase que si la valeur en base est encore celle lue en tête de passe.
                if (!empty($lastOrderCreatedAt)) {
                    $resumeDateNowInDb = $this->readResumeDateFromDbFresh($entity);
                    if ($resumeDateNowInDb === $resumeDate) {
                        dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE', $lastOrderCreatedAt, 'chaine', 0, '', $entity);
                        $this->log("Resume date saved: " . $lastOrderCreatedAt, LOG_INFO);
                    } else {
                        $this->log("[WARNING] Resume date NOT saved this pass: DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE was modified concurrently (was '" . $resumeDate . "' at pass start, is now '" . $resumeDateNowInDb . "') — admin correction preserved", LOG_WARNING);
                        $this->output .= "\n" . '[WARNING] Resume date not saved this pass (modified concurrently by the admin screen)';
                    }
                }

                // Story 63-18 (Task 2, AC4) : personne n'écrivait PROCESSED_COUNT/SKIPPED_COUNT —
                // le CRON comptait tout en mémoire ($counters) sans jamais le persister, donc l'écran
                // affichait "Aucune donnée d'import disponible" pour toujours. Cumul (pas remplacement) :
                // ces compteurs couvrent TOUTES les passes depuis la dernière activation, pas une seule.
                // TOTAL_COUNT n'est volontairement PAS touché ici : Shopify ne fournit aucun total fiable
                // sans appel supplémentaire (fetchHistoricalOrdersBatch() ne le retourne pas) — l'inventer
                // produirait un pourcentage faux (cf. doli2shopIsHistoricalImportPassComplete() et
                // l'affichage conditionnel dans admin/setup.php).
                // Amendement review 24/08 (HIGH) : FAILED_COUNT est désormais persisté comme les
                // autres — il ne rentrait auparavant dans AUCUNE décision ni dans AUCUN affichage.
                $previousProcessedCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_PROCESSED_COUNT', 0);
                $previousSkippedCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_COUNT', 0);
                $previousFailedCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_FAILED_COUNT', 0);
                // Story reglage-commandes-non-payees-ignore-par-les-webhooks (HIGH n°3) : cumul
                // diagnostique du sous-ensemble "refus gate financier" de SKIPPED_COUNT — jamais lu
                // pour handledTotal/pourcentage (cf. admin/setup.php), uniquement affiché en détail.
                $previousSkippedUnpaidCount = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_UNPAID_COUNT', 0);
                $newProcessedCount = $previousProcessedCount + $counters['imported'];
                $newSkippedCount = $previousSkippedCount + $counters['skipped'];
                $newFailedCount = $previousFailedCount + $counters['failed'];
                $newSkippedUnpaidCount = $previousSkippedUnpaidCount + $counters['skipped_unpaid'];

                dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_PROCESSED_COUNT', (string) $newProcessedCount, 'entier', 0, '', $entity);
                dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_COUNT', (string) $newSkippedCount, 'entier', 0, '', $entity);
                dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_FAILED_COUNT', (string) $newFailedCount, 'entier', 0, '', $entity);
                dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_UNPAID_COUNT', (string) $newSkippedUnpaidCount, 'entier', 0, '', $entity);
                $this->log("Progress persisted: processed=" . $newProcessedCount . " skipped=" . $newSkippedCount . " (dont refus gate financier=" . $newSkippedUnpaidCount . ") failed=" . $newFailedCount, LOG_INFO);

                // Story 63-18 (Task 3, AC4/AC5) : critère de complétion — cette passe a-t-elle
                // épuisé la plage configurée sans être interrompue par le plafond par exécution, sans
                // erreur API (CRITICAL, review 24/08), et sans échec applicatif (HIGH, review 24/08) ?
                // Décision (aucun effet de bord) déléguée à doli2shopDecideHistoricalImportCompletionAction()
                // (lib/doli2shop.lib.php), qui encapsule les trois issues possibles ('complete',
                // 'reset', 'no_change') — le CRON se contente d'appliquer celle qu'elle retourne.
                $passHasNextPage = !empty($batch['hasNextPage']);
                $wasAlreadyCompleted = (getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED', 0) === 1);
                $completionAction = doli2shopDecideHistoricalImportCompletionAction(
                    $batchHadError,
                    $counters['imported'],
                    $counters['failed'],
                    $maxImportsPerRun,
                    $passHasNextPage,
                    $wasAlreadyCompleted
                );

                switch ($completionAction) {
                    case 'complete':
                        dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED', '1', 'yesno', 0, '', $entity);
                        if (!$wasAlreadyCompleted) {
                            dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED_DATE', (string) dol_now(), 'entier', 0, '', $entity);
                            $this->log("[OK] Historical import marked COMPLETED (no more orders to process, cap not reached, no API error, no failure)", LOG_INFO);
                        }
                        break;
                    case 'reset':
                        // Du travail est réapparu après une complétion antérieure (ex. END_DATE
                        // reculée ou nouvelles commandes après réactivation) : ne pas laisser "Import
                        // historique terminé" figé à Oui alors que ce n'est plus vrai (AC4 : un état
                        // qui ne reflète plus la réalité est un défaut, pas une précaution).
                        dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED', '0', 'yesno', 0, '', $entity);
                        dolibarr_set_const($this->db, 'DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED_DATE', '0', 'entier', 0, '', $entity);
                        $this->log("Historical import COMPLETED flag reset to No (new work detected after a prior completion)", LOG_INFO);
                        break;
                    case 'no_change':
                    default:
                        if ($batchHadError) {
                            // CRITICAL (review 24/08) : c'est précisément le cas qui produisait
                            // auparavant une complétion FAUSSE — voir doli2shopDecideHistoricalImportCompletionAction().
                            $this->log("Historical import completion state left untouched (this pass had an unusable API response)", LOG_INFO);
                        }
                        break;
                }

                // Réinitialiser le flag anti-boucle
                $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 0;

                // Logging résultat
                $executionTime = round(microtime(true) - $startTime, 2);
                $summary = "checked=" . $counters['total_checked']
                    . " imported=" . $counters['imported']
                    . " skipped=" . $counters['skipped']
                    . " (skippedUnpaid=" . $counters['skipped_unpaid'] . ")"
                    . " failed=" . $counters['failed'];

                $this->log("[OK] HISTORICAL IMPORT completed in " . $executionTime . "s - " . $summary, LOG_INFO);
                $this->output .= "\n" . 'Historical import completed (' . $executionTime . 's) - ' . $summary;

                @set_time_limit($oldTimeLimit);
                return 0;

            } catch (Exception $e) {
                $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 0;
                $this->error++;
                $this->errors[] = $e->getMessage();
                $this->output .= "\n" . 'Exception during historical import: ' . $e->getMessage();
                $executionTime = round(microtime(true) - $startTime, 2);
                $this->log("[ERROR] HISTORICAL IMPORT EXCEPTION after " . $executionTime . "s: " . $e->getMessage(), LOG_ERR);
                @set_time_limit($oldTimeLimit);
                return -1;
            } finally {
                // Story 36.4 : toujours libérer le verrou (no-op si non détenu par cette session)
                $this->releaseCronLock('historical_import');
            }

        // CATCH GLOBAL pour capturer erreurs fatales
        } catch (Exception $e) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 0;
            $errorMsg = "[ERROR] FATAL EXCEPTION in executeCron(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            dol_syslog($errorMsg, LOG_ERR);
            $this->output = $errorMsg;
            $this->error = 1;
            return -1;
        } catch (Error $e) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 0;
            $errorMsg = "[ERROR] FATAL PHP ERROR in executeCron(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            dol_syslog($errorMsg, LOG_ERR);
            $this->output = $errorMsg;
            $this->error = 1;
            return -1;
        }
    }

    /**
     * Relit DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE DIRECTEMENT en base (jamais via
     * getDolGlobalString()/$conf->global) — Story 63-18, review 24/08 (MEDIUM/HIGH, TOCTOU).
     *
     * $conf->global est un cache chargé UNE FOIS au démarrage du process CRON : il ne verrait
     * jamais une écriture faite, PENDANT l'exécution de cette passe, par le process web
     * d'admin/setup.php (réalignement de RESUME_DATE suite à une correction de START_DATE par un
     * administrateur, AC1). D'où cette relecture SQL directe, utilisée UNIQUEMENT juste avant
     * d'écrire le nouveau point de reprise, pour détecter une modification concurrente.
     *
     * @param  int $entity Entité Dolibarr
     * @return string Valeur actuelle en base ('' si absente)
     */
    private function readResumeDateFromDbFresh($entity)
    {
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = 'DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE'";
        $sql .= " AND entity = " . (int) $entity;

        $resql = $this->db->query($sql);
        $value = '';
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $value = ($obj !== null && $obj->value !== null) ? (string) $obj->value : '';
            }
            $this->db->free($resql);
        }

        return $value;
    }
}
