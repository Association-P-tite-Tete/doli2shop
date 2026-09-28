<?php
/**
 * @file        class/healthchecker.class.php
 * @brief       Health metrics calculator for Doli2Shop system monitoring
 *
 * Provides a single source of truth for system health metrics:
 * error rates, sync timestamps, order/product counts, webhook status.
 * Used by the admin dashboard (Story 4.2) and future alerting systems.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.2.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre acces direct
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, acces interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Class HealthChecker
 *
 * Calculates system health metrics from existing Doli2Shop tables.
 * No new SQL tables required — reads from webhook_events, webhooks, orders, products.
 */
class HealthChecker
{
    use LoggerTrait;

    /** @var DoliDB Database handler */
    private $db;

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
        $this->errors = array();
    }

    /**
     * Get complete health status of the Doli2Shop system
     *
     * Returns an associative array with 7 metrics:
     * - status: string (green/orange/red)
     * - last_sync_timestamp: string|null (datetime of last successful sync)
     * - errors_24h: int (error count in last 24 hours)
     * - orders_30d: int (orders synced in last 30 days)
     * - products_synced: int (distinct products currently synced)
     * - webhooks_active: int (active webhook subscriptions)
     * - webhooks_total: int (total webhook subscriptions)
     *
     * @return array Health metrics array
     */
    public function getHealthStatus()
    {
        global $conf;

        $this->log("HealthChecker::getHealthStatus - Calculating health metrics for entity " . $conf->entity, LOG_DEBUG);

        $errors24h = $this->countErrors24h();
        $lastSyncTimestamp = $this->getLastSyncTimestamp();
        $orders30d = $this->countOrders30d();
        $productsSynced = $this->countProductsSynced();
        $webhooksData = $this->getWebhooksStatus();
        $webhooksActive = $webhooksData['active'];
        $webhooksTotal = $webhooksData['total'];

        $status = $this->calculateHealthColor($errors24h, $webhooksActive, $webhooksTotal);

        $this->log("HealthChecker::getHealthStatus - Status=" . $status . " errors_24h=" . $errors24h . " webhooks=" . $webhooksActive . "/" . $webhooksTotal, LOG_INFO);

        return array(
            'status' => $status,
            'last_sync_timestamp' => $lastSyncTimestamp,
            'errors_24h' => $errors24h,
            'orders_30d' => $orders30d,
            'products_synced' => $productsSynced,
            'webhooks_active' => $webhooksActive,
            'webhooks_total' => $webhooksTotal,
        );
    }

    /**
     * Count webhook errors in the last 24 hours
     *
     * @return int Number of errors
     */
    private function countErrors24h()
    {
        global $conf;

        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "doli2shop_webhook_events";
        $sql .= " WHERE action_result = 'error'";
        $sql .= " AND date_reception >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $count = (int) $obj->cnt;
            $this->db->free($result);
            return $count;
        }

        $this->errors[] = "HealthChecker::countErrors24h - SQL error: " . $this->db->lasterror();
        $this->log("HealthChecker::countErrors24h - SQL error: " . $this->db->lasterror(), LOG_ERR);
        return 0;
    }

    /**
     * Get timestamp of last successful sync operation
     *
     * @return string|null Datetime string or null if no sync yet
     */
    private function getLastSyncTimestamp()
    {
        global $conf;

        $sql = "SELECT MAX(date_traitement) as last_sync FROM " . MAIN_DB_PREFIX . "doli2shop_webhook_events";
        $sql .= " WHERE action_result = 'success'";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $lastSync = $obj->last_sync;
            $this->db->free($result);
            return $lastSync;
        }

        $this->errors[] = "HealthChecker::getLastSyncTimestamp - SQL error: " . $this->db->lasterror();
        $this->log("HealthChecker::getLastSyncTimestamp - SQL error: " . $this->db->lasterror(), LOG_ERR);
        return null;
    }

    /**
     * Count orders synchronized in the last 30 days
     *
     * @return int Number of orders
     */
    private function countOrders30d()
    {
        global $conf;

        // Note: llx_doli2shop_orders uses `tms` (timestamp), NOT `date_creation`
        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "doli2shop_orders";
        $sql .= " WHERE tms >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $count = (int) $obj->cnt;
            $this->db->free($result);
            return $count;
        }

        $this->errors[] = "HealthChecker::countOrders30d - SQL error: " . $this->db->lasterror();
        $this->log("HealthChecker::countOrders30d - SQL error: " . $this->db->lasterror(), LOG_ERR);
        return 0;
    }

    /**
     * Count distinct products currently synchronized
     *
     * Counts parent/simple products only (fk_product_parent IS NULL)
     * to avoid counting each variant separately.
     *
     * @return int Number of synced products
     */
    private function countProductsSynced()
    {
        global $conf;

        $sql = "SELECT COUNT(DISTINCT fk_product) as cnt FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE fk_product_parent IS NULL";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $count = (int) $obj->cnt;
            $this->db->free($result);
            return $count;
        }

        $this->errors[] = "HealthChecker::countProductsSynced - SQL error: " . $this->db->lasterror();
        $this->log("HealthChecker::countProductsSynced - SQL error: " . $this->db->lasterror(), LOG_ERR);
        return 0;
    }

    /**
     * Get webhook subscriptions status (active vs total)
     *
     * @return array Array with 'active' and 'total' keys
     */
    private function getWebhooksStatus()
    {
        global $conf;

        $sql = "SELECT";
        $sql .= " COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active_count,";
        $sql .= " COUNT(*) as total_count";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks";
        $sql .= " WHERE entity = " . ((int) $conf->entity);

        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $data = array(
                'active' => (int) $obj->active_count,
                'total' => (int) $obj->total_count,
            );
            $this->db->free($result);
            return $data;
        }

        $this->errors[] = "HealthChecker::getWebhooksStatus - SQL error: " . $this->db->lasterror();
        $this->log("HealthChecker::getWebhooksStatus - SQL error: " . $this->db->lasterror(), LOG_ERR);
        return array('active' => 0, 'total' => 0);
    }

    /**
     * Calculate health color based on error count and webhook status
     *
     * Logic:
     * - GREEN: 0 errors AND (webhooks active OR no webhooks registered yet)
     * - ORANGE: errors > 0 AND errors <= warnThreshold
     * - RED: errors > errorThreshold OR (webhooks registered but none active)
     *
     * Special cases:
     * - Fresh install (0 webhooks registered): GREEN (not yet configured)
     * - Empty database (0 events): GREEN (no errors = healthy)
     *
     * @param int $errors24h Number of errors in last 24h
     * @param int $webhooksActive Number of active webhooks
     * @param int $webhooksTotal Total number of registered webhooks
     * @return string Health status color: green, orange, or red
     */
    private function calculateHealthColor($errors24h, $webhooksActive, $webhooksTotal)
    {
        $warnThreshold = getDolGlobalInt('DOLI2SHOP_HEALTH_WARN_THRESHOLD', 5);
        $errorThreshold = getDolGlobalInt('DOLI2SHOP_HEALTH_ERROR_THRESHOLD', 5);

        // RED: Webhooks registered but ALL inactive (critical system failure)
        if ($webhooksTotal > 0 && $webhooksActive == 0) {
            $this->log("HealthChecker::calculateHealthColor - RED: All webhooks inactive (" . $webhooksTotal . " registered, 0 active)", LOG_WARNING);
            return 'red';
        }

        // RED: Too many errors (above error threshold)
        if ($errors24h > $errorThreshold) {
            $this->log("HealthChecker::calculateHealthColor - RED: " . $errors24h . " errors > threshold " . $errorThreshold, LOG_WARNING);
            return 'red';
        }

        // ORANGE: Some errors (between 1 and warn threshold inclusive)
        if ($errors24h > 0 && $errors24h <= $warnThreshold) {
            return 'orange';
        }

        // RED: Errors between warn and error threshold (when thresholds differ)
        if ($errors24h > $warnThreshold) {
            return 'red';
        }

        // GREEN: No errors, webhooks OK (or fresh install with no webhooks)
        return 'green';
    }
}
