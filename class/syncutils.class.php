<?php
/**
 * @file        class/syncutils.class.php
 * @brief       Class for synchronization direction utilities
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.1.6
 * @since       2.0.0
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
// SyncFlowPolicy::DEFAULT_STOCKS_DIRECTION : source unique du défaut du sens du stock
// (story sens-du-stock-par-defaut-dolibarr-vers-shopify).
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';

/**
 * SyncUtils class for synchronization direction utilities
 */
class SyncUtils
{
    use LoggerTrait;
    
    /**
     * @var DoliDB Database handler
     */
    private $db;
    
    /**
     * @var Conf Configuration object
     */
    private $conf;
    
    /**
     * @var ShopifyApi API object
     */
    private $api;
    
    /**
     * @var int Entity ID
     */
    private $entity;
    
    /**
     * @var array Current configuration
     */
    private $config;
    
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     * @param int $entity Entity ID (optional)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        
        $this->db = $db;
        $this->conf = $conf;
        $this->entity = $entity ?: $conf->entity;
        $this->api = new ShopifyApi($db, $this->entity);
        
        $this->loadConfig();
    }
    
    /**
     * Load configuration from Dolibarr constants
     *
     * @return void
     */
    private function loadConfig()
    {
        $this->log("Chargement de la configuration de synchronisation pour l'entité " . $this->entity, LOG_DEBUG);

        $this->config = new stdClass();

        // Lecture depuis les constantes Dolibarr (table llx_const)
        // La table llx_doli2shop_storedetails a été supprimée en v2.1.1
        $this->config->sync_products_direction = getDolGlobalString('DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', 'both');
        $this->config->sync_orders_direction = getDolGlobalString('DOLI2SHOP_SYNC_ORDERS_DIRECTION', 'shopify_to_dolibarr');
        $this->config->sync_payments_direction = getDolGlobalString('DOLI2SHOP_SYNC_PAYMENTS_DIRECTION', 'shopify_to_dolibarr');
        $this->config->sync_shipping_direction = getDolGlobalString('DOLI2SHOP_SYNC_SHIPPING_DIRECTION', 'both');
        $this->config->sync_stocks_direction = getDolGlobalString('DOLI2SHOP_SYNC_STOCKS_DIRECTION', SyncFlowPolicy::DEFAULT_STOCKS_DIRECTION);
        $this->config->conflict_resolution_strategy = getDolGlobalString('DOLI2SHOP_CONFLICT_RESOLUTION_STRATEGY', 'newest_wins');

        $this->log("Configuration chargée - Produits: " . $this->config->sync_products_direction .
                   ", Commandes: " . $this->config->sync_orders_direction .
                   ", Stocks: " . $this->config->sync_stocks_direction, LOG_DEBUG);
    }
    
    /**
     * Check if synchronization is enabled for the given type and direction
     *
     * @param string $type Type of data (products, orders, payments, shipping, stocks)
     * @param string $direction Direction of synchronization (shopify_to_dolibarr, dolibarr_to_shopify)
     * @return boolean True if synchronization is enabled for the given type and direction
     */
    public function isSyncEnabled($type, $direction)
    {
        $this->log("Vérification si la synchronisation est activée pour $type dans la direction $direction", LOG_DEBUG);
        
        // Vérifier que le type est valide
        $validTypes = array('products', 'orders', 'payments', 'shipping', 'stocks');
        if (!in_array($type, $validTypes)) {
            $this->log("Type de synchronisation invalide: $type", LOG_WARNING);
            return false;
        }
        
        // Vérifier que la direction est valide
        $validDirections = array('shopify_to_dolibarr', 'dolibarr_to_shopify');
        if (!in_array($direction, $validDirections)) {
            $this->log("Direction de synchronisation invalide: $direction", LOG_WARNING);
            return false;
        }
        
        // Lire la direction configurée pour ce type
        $configKey = 'sync_' . $type . '_direction';
        $configuredDirection = isset($this->config->$configKey) ? $this->config->$configKey : 'both';

        // Si "none", aucune synchronisation n'est activée
        if ($configuredDirection == 'none') {
            $this->log("Synchronisation désactivée pour $type (direction = none)", LOG_DEBUG);
            return false;
        }

        // Vérifier si la synchronisation est activée dans cette direction
        $enabled = ($configuredDirection == 'both' || $configuredDirection == $direction);
        
        $this->log("Synchronisation " . ($enabled ? "activée" : "désactivée") . " pour $type dans la direction $direction", LOG_DEBUG);
        
        return $enabled;
    }
    
    /**
     * Get conflict resolution strategy
     *
     * @return string Strategy (shopify_wins, dolibarr_wins, newest_wins, oldest_wins, manual_resolution)
     */
    public function getConflictStrategy()
    {
        return $this->config->conflict_resolution_strategy ?: 'newest_wins';
    }
    
    /**
     * Check if products synchronization is enabled from Shopify to Dolibarr
     *
     * @return boolean True if enabled
     */
    public function isProductSyncFromShopifyEnabled()
    {
        return $this->isSyncEnabled('products', 'shopify_to_dolibarr');
    }
    
    /**
     * Check if products synchronization is enabled from Dolibarr to Shopify
     *
     * @return boolean True if enabled
     */
    public function isProductSyncToShopifyEnabled()
    {
        return $this->isSyncEnabled('products', 'dolibarr_to_shopify');
    }
    
    /**
     * Check if orders synchronization is enabled from Shopify to Dolibarr
     *
     * @return boolean True if enabled
     */
    public function isOrderSyncFromShopifyEnabled()
    {
        return $this->isSyncEnabled('orders', 'shopify_to_dolibarr');
    }
    
    /**
     * Check if orders synchronization is enabled from Dolibarr to Shopify
     *
     * @return boolean True if enabled
     */
    public function isOrderSyncToShopifyEnabled()
    {
        return $this->isSyncEnabled('orders', 'dolibarr_to_shopify');
    }
    
    /**
     * Check if payments synchronization is enabled from Shopify to Dolibarr
     *
     * @return boolean True if enabled
     */
    public function isPaymentSyncFromShopifyEnabled()
    {
        return $this->isSyncEnabled('payments', 'shopify_to_dolibarr');
    }
    
    /**
     * Check if payments synchronization is enabled from Dolibarr to Shopify
     *
     * @return boolean True if enabled
     */
    public function isPaymentSyncToShopifyEnabled()
    {
        return $this->isSyncEnabled('payments', 'dolibarr_to_shopify');
    }
    
    /**
     * Check if shipping synchronization is enabled from Shopify to Dolibarr
     *
     * @return boolean True if enabled
     */
    public function isShippingSyncFromShopifyEnabled()
    {
        return $this->isSyncEnabled('shipping', 'shopify_to_dolibarr');
    }
    
    /**
     * Check if shipping synchronization is enabled from Dolibarr to Shopify
     *
     * @return boolean True if enabled
     */
    public function isShippingSyncToShopifyEnabled()
    {
        return $this->isSyncEnabled('shipping', 'dolibarr_to_shopify');
    }
    
    /**
     * Check if stocks synchronization is enabled from Shopify to Dolibarr
     *
     * @return boolean True if enabled
     */
    public function isStockSyncFromShopifyEnabled()
    {
        return $this->isSyncEnabled('stocks', 'shopify_to_dolibarr');
    }
    
    /**
     * Check if stocks synchronization is enabled from Dolibarr to Shopify
     *
     * @return boolean True if enabled
     */
    public function isStockSyncToShopifyEnabled()
    {
        return $this->isSyncEnabled('stocks', 'dolibarr_to_shopify');
    }
    
    /**
     * Resolve conflict based on the configured strategy
     *
     * @param mixed $shopifyData Data from Shopify
     * @param mixed $dolibarrData Data from Dolibarr
     * @param int $shopifyTimestamp Timestamp of Shopify data (optional)
     * @param int $dolibarrTimestamp Timestamp of Dolibarr data (optional)
     * @return mixed The data to use
     */
    public function resolveConflict($shopifyData, $dolibarrData, $shopifyTimestamp = null, $dolibarrTimestamp = null)
    {
        $strategy = $this->getConflictStrategy();
        $this->log("Résolution de conflit avec stratégie: $strategy", LOG_DEBUG);
        
        switch ($strategy) {
            case 'shopify_wins':
                return $shopifyData;
                
            case 'dolibarr_wins':
                return $dolibarrData;
                
            case 'newest_wins':
                if ($shopifyTimestamp && $dolibarrTimestamp) {
                    return ($shopifyTimestamp > $dolibarrTimestamp) ? $shopifyData : $dolibarrData;
                }
                // Par défaut, si les timestamps ne sont pas disponibles, Shopify gagne
                return $shopifyData;
                
            case 'oldest_wins':
                if ($shopifyTimestamp && $dolibarrTimestamp) {
                    return ($shopifyTimestamp < $dolibarrTimestamp) ? $shopifyData : $dolibarrData;
                }
                // Par défaut, si les timestamps ne sont pas disponibles, Dolibarr gagne
                return $dolibarrData;
                
            case 'manual_resolution':
                // Pour la résolution manuelle, nous ne pouvons pas résoudre automatiquement
                // Nous retournons null et la classe appelante devra gérer ce cas spécial
                return null;
                
            default:
                // Si la stratégie est inconnue, utiliser la plus récente par défaut
                $this->log("Stratégie de résolution inconnue: $strategy, utilisation de 'newest_wins' par défaut", LOG_WARNING);
                if ($shopifyTimestamp && $dolibarrTimestamp) {
                    return ($shopifyTimestamp > $dolibarrTimestamp) ? $shopifyData : $dolibarrData;
                }
                return $shopifyData;
        }
    }
}