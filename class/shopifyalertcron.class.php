<?php
/**
 * @file        class/shopifyalertcron.class.php
 * @brief       CRON job for proactive alert checking
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.2.1
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/alertmanager.class.php';

/**
 * Class ShopifyAlertCron
 * Tâche planifiée pour la vérification des alertes proactives
 *
 * Story 30.1 — Système d'alertes proactif v2.2.1
 * Fréquence recommandée : toutes les 15 minutes
 */
class ShopifyAlertCron
{
    use CronHelperTrait;
    use LoggerTrait;

    /** @var DoliDB Database handler */
    public $db;

    /** @var string CRON output message */
    public $output = '';

    /** @var int Error count */
    public $error = 0;

    /** @var array Error messages */
    public $errors = [];

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
     * Méthode CRON principale : vérifie les alertes et envoie les notifications
     *
     * @param object|null $conf Configuration Dolibarr (auto-détectée si null)
     * @param object|null $langs Traductions (auto-détectées si null)
     * @return int 0=OK, <0=erreur
     */
    public function run_alerts($conf = null, $langs = null)
    {
        global $conf;

        try {
            $this->output = '';
            $this->error = 0;
            $this->errors = [];

            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int) $conf->entity : 1;
            $startTime = microtime(true);

            // Log version info
            $versionInfo = $this->getModuleVersionInfo();
            $this->log("ShopifyAlertCron::run_alerts - START entity=" . $entity . " " . $versionInfo, LOG_INFO);

            // Timeout protection
            $oldTimeLimit = (int) ini_get('max_execution_time');
            if ($oldTimeLimit < 60) {
                @set_time_limit(60);
            }

            // Check frozen state
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/shopifyalertcron.class.php');

            // Execute alert checks
            $alertManager = new AlertManager($this->db);

            if (!$alertManager->isConfigured()) {
                $this->output = 'Alerts: disabled or not configured';
                $this->log("ShopifyAlertCron::run_alerts - Alertes non configurées, skip", LOG_DEBUG);
                @set_time_limit($oldTimeLimit);
                return 0;
            }

            $result = $alertManager->checkAndAlert();

            $elapsedSec = round(microtime(true) - $startTime, 2);
            $this->output = 'Alerts: checked=' . $result['checked'] . ' sent=' . $result['alerts_sent'] . ' (' . $elapsedSec . 's)';

            $this->log("ShopifyAlertCron::run_alerts - END " . $this->output, LOG_INFO);

            @set_time_limit($oldTimeLimit);
            return 0;

        } catch (\Throwable $e) {
            $this->log("ShopifyAlertCron::run_alerts - EXCEPTION: " . $e->getMessage(), LOG_ERR);
            $this->error = 1;
            $this->errors[] = $e->getMessage();
            $this->output = 'Alert check failed: ' . $e->getMessage();
            return -1;
        }
    }
}
