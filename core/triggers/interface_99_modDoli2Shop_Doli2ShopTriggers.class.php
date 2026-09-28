<?php
/**
 * @file        core/triggers/interface_99_modDoli2Shop_Doli2ShopTriggers.class.php
 * @brief       Dolibarr triggers interface for Doli2Shop module (Dolibarr→Shopify sync)
 *
 * This trigger captures Dolibarr events (products, orders, shipping, payments)
 * and delegates to ShopifyTriggerManager for bidirectional synchronization.
 *
 * File naming convention: interface_{priority}_{modName}_{ClassName}.class.php
 * Priority 99 = run late to avoid interfering with core Dolibarr operations
 *
 * @package     ShopifyIntegration
 * @subpackage  Core
 * @category    core
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.8
 * @link        https://doli2shop.ptitetete.org
 */

dol_include_once('/core/triggers/dolibarrtriggers.class.php');

/**
 * Class InterfaceDoli2ShopTriggers
 *
 * Dolibarr trigger interface that delegates to ShopifyTriggerManager
 * for bidirectional sync between Dolibarr and Shopify.
 *
 * Anti-loop mechanism:
 * - Webhook handlers set SHOPIFY_INTEGRATION_PROCESSING_WEBHOOK flag
 * - ShopifyTriggerManager checks this flag and skips sync if set
 * - This prevents infinite loops: Shopify→webhook→trigger→Shopify
 */
class InterfaceDoli2ShopTriggers extends DolibarrTriggers
{
    /**
     * @var string Trigger prefix for Dolibarr 23+ compatibility
     */
    public $TRIGGER_PREFIX = 'DOLI2SHOP';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'doli2shop';
        $this->description = "Doli2Shop triggers for Dolibarr↔Shopify bidirectional sync";
        // Version centralisée — source unique dans lib/version.lib.php
        dol_include_once('/doli2shop/lib/version.lib.php');
        $this->version = DOLI2SHOP_MODULE_VERSION;
        $this->picto = 'shopify_color.png@doli2shop';
    }

    /**
     * Détermine si une action Dolibarr est pertinente pour Doli2Shop (préfixe reconnu).
     *
     * Extrait en méthode statique (HIGH-2, code review Story 52-1) pour être testable
     * indépendamment de runTrigger() (qui a besoin d'un contexte Dolibarr complet).
     * Seuls les préfixes ORDER_, PRODUCT_, SHIPPING_, PAYMENT_, STOCK_ sont traités.
     * STOCK_ (Story 52-1) couvre STOCK_MOVEMENT, seul mouvement de stock émis par Dolibarr
     * (product/stock/class/mouvementstock.class.php::_create()).
     *
     * @param string $action Action du trigger Dolibarr (ex: STOCK_MOVEMENT, ORDER_VALIDATE)
     * @return bool True si l'action doit être traitée par Doli2Shop
     */
    public static function isRelevantAction($action)
    {
        $relevantPrefixes = array('ORDER_', 'PRODUCT_', 'SHIPPING_', 'PAYMENT_', 'STOCK_');
        foreach ($relevantPrefixes as $prefix) {
            if (strpos($action, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Function called when a Dolibar business event is done.
     *
     * @param string    $action Event action code
     * @param Object    $object Object concerned
     * @param User      $user   Object user
     * @param Translate $langs  Object langs
     * @param Conf      $conf   Object conf
     * @return int              0 = OK, <0 = KO (but always return 0 to never block Dolibarr)
     */
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        // Quick check: module must be enabled
        if (!isModEnabled('doli2shop')) {
            return 0;
        }

        // Quick-return for non-relevant actions (cf. isRelevantAction() — HIGH-2 code review :
        // extrait en méthode statique testable indépendamment de tout le reste du trigger)
        if (!self::isRelevantAction($action)) {
            return 0;
        }

        dol_syslog("InterfaceDoli2ShopTriggers::runTrigger - action=" . $action, LOG_DEBUG);

        // v2.2.0: Purge webhook events on ORDER_CLOSE (Story 11.6)
        // Handled BEFORE delegation to ShopifyTriggerManager (local action, not Shopify sync)
        if ($action === 'ORDER_CLOSE' && !empty($object->id)) {
            try {
                require_once dirname(__FILE__) . '/../../class/webhookmanager.class.php';
                $webhookManager = new WebhookManager($this->db);
                $webhookManager->purgeEventsByOrderId((int) $object->id);
            } catch (\Throwable $e) {
                dol_syslog("InterfaceDoli2ShopTriggers::runTrigger - Purge failed for ORDER_CLOSE #"
                    . $object->id . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        // Delegate to ShopifyTriggerManager (which handles all logic: anti-loop, sync direction, dispatch)
        try {
            require_once dirname(__FILE__) . '/../../class/shopifytriggermanager.class.php';

            $result = ShopifyTriggerManager::manageTriggers($action, $object, $user, $langs, $conf);

            return $result;
        } catch (Exception $e) {
            // Never block Dolibarr operations - log error and continue
            dol_syslog("InterfaceDoli2ShopTriggers::runTrigger - Exception for action " . $action . ": " . $e->getMessage(), LOG_ERR);
            return 0;
        } catch (Error $e) {
            // Catch PHP fatal errors too
            dol_syslog("InterfaceDoli2ShopTriggers::runTrigger - Fatal error for action " . $action . ": " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }
}
