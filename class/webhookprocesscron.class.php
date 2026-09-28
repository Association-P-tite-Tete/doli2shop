<?php
/**
 * Webhook processing cron class for ShopifyIntegration module
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 */

// FIX #152 v2.1.2: Protection contre accès direct
// CRITIQUE: Sans cette protection, Dolibarr 22.0.2+ affiche "Erreur, accès interdit"
// lors du scan des modules pendant l'activation
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// v2.2.0: Seuls les includes légers au niveau fichier (pas de chaîne lourde)
// WebhookManager est chargé en lazy-loading dans run() pour éviter les crashs silencieux
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Class WebhookProcessCron
 * Cron job pour traiter les webhooks en attente
 */
class WebhookProcessCron
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDb Database handler */
    public $db;

    /** @var array Errors */
    public $errors = array();

    /** @var string Cron output */
    public $output;

    /**
     * Constructor
     *
     * @param DoliDb $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Execute the cron job
     *
     * @param string $cronjob Object cronjob
     * @return int 0 if OK, <>0 if KO
     */
    public function run($cronjob = null)
    {
        global $conf, $langs;

        // FIX #152 v2.1.2: TRY/CATCH GLOBAL pour capturer TOUTE erreur silencieuse
        try {
            // v2.2.0: Libérer les locks transactionnels MySQL AVANT toute lecture llx_const
            // Sans ce SELECT, new WebhookManager() → getDolGlobalString() peut deadlock
            // car Dolibarr a fait UPDATE processing=1 dans une transaction
            $this->checkAndUnfreezeCron(
                (int) ($conf->entity ?? 1),
                '/doli2shop/class/webhookprocesscron.class.php'
            );

            dol_syslog("WebhookProcessCron::run - ENTRY POINT - Starting execution", LOG_INFO);
            error_log("[Doli2Shop] WebhookProcessCron::run - ENTRY POINT - Starting execution");

            $error = 0;
            $this->output = '';

            // Story 36.4 : verrou anti double-exécution (non bloquant).
            // Libéré via finally du try ci-dessous ; et de toute façon auto-libéré
            // par MySQL en fin de session CRON (filet de sécurité).
            if (!$this->acquireCronLock('webhook_process')) {
                $this->log("WebhookProcessCron::run - Another instance is already running, skipping", LOG_INFO);
                $this->output = "Another instance is already running, skipping\n";
                return 0;
            }

            // Nombre d'événements à traiter par exécution
            $limit = getDolGlobalInt('SHOPIFY_WEBHOOK_PROCESS_LIMIT', 100);

            try {
                // v2.2.0: Lazy-loading — charger WebhookManager ici (pas au niveau fichier)
                // pour que le try/catch capture toute erreur de chargement
                require_once dirname(__FILE__) . '/webhookmanager.class.php';

                // Créer l'instance du gestionnaire
                $webhookManager = new WebhookManager($this->db);

                // Traiter les événements en attente
                $processed = $webhookManager->processPendingEvents($limit);

                if ($processed < 0) {
                    $error++;
                    $this->errors = array_merge($this->errors, $webhookManager->errors);
                    $this->output .= "Error during processing: ".implode(', ', $webhookManager->errors)."\n";
                    error_log("[Doli2Shop] WebhookProcessCron - Error: ".implode(', ', $webhookManager->errors));
                } else {
                    $this->output .= "Processed ".$processed." webhook events successfully\n";
                }

                // Purger les anciens événements traités et en erreur (niveau 1: 48h, status 1/2)
                $purged = $webhookManager->purgeOldEvents();
                if ($purged >= 0) {
                    $this->output .= "Purged ".$purged." old webhook events\n";
                }

                // Purger les événements expirés au-delà de la rétention max (niveau 2: 30j, tous statuts)
                $expired = $webhookManager->purgeExpiredEvents();
                if ($expired > 0) {
                    $this->output .= "Purged ".$expired." expired webhook events (retention limit)\n";
                }

            } catch (\Throwable $e) {
                $error++;
                $this->errors[] = $e->getMessage();
                $this->output .= "Exception: ".$e->getMessage()."\n";
                error_log("[Doli2Shop] WebhookProcessCron - Exception: ".$e->getMessage()." | ".$e->getFile().":".$e->getLine());
            } finally {
                // Story 36.4 : toujours libérer le verrou (succès, erreur ou exception)
                $this->releaseCronLock('webhook_process');
            }

            dol_syslog("WebhookProcessCron::run - DONE - processed=".($processed ?? 0).", purged=".($purged ?? 0).", errors=".$error, LOG_INFO);
            error_log("[Doli2Shop] WebhookProcessCron::run - DONE - output: ".trim($this->output));

            return $error;

        // FIX #152 v2.1.2: CATCH GLOBAL pour capturer erreurs fatales AVANT le try interne
        } catch (\Throwable $e) {
            $errorMsg = "[ERROR] FATAL " . get_class($e) . " in run(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString();
            dol_syslog($errorMsg, LOG_ERR);
            error_log("[Doli2Shop] " . $errorMsg);

            $this->output = $errorMsg;
            $this->errors[] = $errorMsg;
            return 1;
        }
    }

    // cleanOldEvents() supprimée — remplacée par WebhookManager::purgeOldEvents()
    // qui purge status 1 (traités) ET 2 (erreurs) avec rétention configurable en heures
}
// CRON registration handled by modDoli2Shop::$cronjobs (index 7)
