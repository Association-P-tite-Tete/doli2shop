<?php
/**
 * @file        class/stockpushqueuecron.class.php
 * @brief       CRON de drainage de la file de push stock asynchrone (Story 52-4)
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.4.5
 * @since       2.4.5
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Class StockPushQueueCron
 *
 * Traite la file d'attente de push stock Dolibarr → Shopify (Story 52-4) : draine les marqueurs
 * enfilés par ShopifyStockTrigger::runTrigger() (STOCK_MOVEMENT), HORS de toute transaction
 * métier Dolibarr — c'est ICI, et seulement ici, que le push réel Shopify (et un éventuel refresh
 * de jeton OAuth) peut avoir lieu, ce qui élimine le risque de lock-wait sur
 * `llx_doli2shop_stores` pendant `Facture::validate()` (cf. Contexte du bug, story 52-4).
 */
class StockPushQueueCron
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
        global $conf;

        try {
            $this->checkAndUnfreezeCron(
                (int) ($conf->entity ?? 1),
                '/doli2shop/class/stockpushqueuecron.class.php'
            );

            dol_syslog("StockPushQueueCron::run - ENTRY POINT - Starting execution", LOG_INFO);

            $error = 0;
            $this->output = '';

            // Verrou de réentrance (non bloquant) — Résultat Validate point 6.
            if (!$this->acquireCronLock('stock_push_queue')) {
                $this->log("StockPushQueueCron::run - Another instance is already running, skipping", LOG_INFO);
                $this->output = "Another instance is already running, skipping\n";
                return 0;
            }

            $limit = getDolGlobalInt('DOLI2SHOP_STOCK_PUSH_QUEUE_LIMIT', 100);

            try {
                require_once dirname(__FILE__) . '/stockpushqueueservice.class.php';

                $service = new StockPushQueueService($this->db, (int) ($conf->entity ?? 1));
                $result = $service->drainQueue($limit);

                $this->output .= "Claimed " . $result['claimed'] . ", pushed " . $result['pushed']
                    . ", errors " . $result['errors'] . ", dead-letter " . $result['deadLetter']
                    . ", raced " . $result['raced'] . "\n";

                if ($result['errors'] > 0 || $result['deadLetter'] > 0) {
                    // Non bloquant pour le CRON global (les items en échec sont retentés ou
                    // signalés en dead-letter, jamais bloquants) : on ne compte PAS $error++ ici,
                    // cohérent avec la sémantique "0 = OK global" même si des items individuels
                    // ont échoué (déjà journalisés via ActionLogger).
                    $this->log(
                        "StockPushQueueCron::run - " . $result['errors'] . " erreur(s), "
                        . $result['deadLetter'] . " dead-letter (voir journal ActionLogger)",
                        LOG_WARNING
                    );
                }
            } catch (\Throwable $e) {
                $error++;
                $this->errors[] = $e->getMessage();
                $this->output .= "Exception: " . $e->getMessage() . "\n";
                error_log("[Doli2Shop] StockPushQueueCron - Exception: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
            } finally {
                $this->releaseCronLock('stock_push_queue');
            }

            dol_syslog("StockPushQueueCron::run - DONE - output: " . trim($this->output), LOG_INFO);

            return $error;
        } catch (\Throwable $e) {
            $errorMsg = "[ERROR] FATAL " . get_class($e) . " in run(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            dol_syslog($errorMsg, LOG_ERR);
            error_log("[Doli2Shop] " . $errorMsg);

            $this->output = $errorMsg;
            $this->errors[] = $errorMsg;
            return 1;
        }
    }
}
// CRON registration handled by modDoli2Shop::$cronjobs (index 11)
