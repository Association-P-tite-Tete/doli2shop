<?php
/**
 * @file        webhooks/handlers/InventoryWebhookHandler.php
 * @brief       Handler for Shopify inventory webhooks (inventory_levels/update)
 *
 * @package     ShopifyIntegration
 * @subpackage  Webhooks
 * @category    webhooks
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.8
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/../../class/LoggerTrait.php';
require_once dirname(__FILE__) . '/../../class/sqlutils.class.php';
require_once dirname(__FILE__) . '/../../class/syncflowpolicy.class.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../../lib/compatibility.lib.php';

/**
 * Class InventoryWebhookHandler
 * Gère les webhooks Shopify liés à l'inventaire (inventory_levels/update)
 */
class InventoryWebhookHandler
{
    use LoggerTrait;

    /** @var DoliDb Database handler */
    public $db;

    /** @var array Errors */
    public $errors = array();

    /** @var string|null Dolibarr object type created/modified (Story 3.2) */
    public $lastObjectType = null;

    /** @var int|null Dolibarr object ID created/modified (Story 3.2) */
    public $lastObjectId = null;

    /** @var string|null Action result override: skipped, duplicate (Story 3.2) */
    public $lastActionResult = null;

    /** @var object|null Boutique courante résolue par processEvent (Epic 47-4) */
    private $currentStore = null;

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
     * Reset context properties before each webhook processing (Story 3.2)
     *
     * @return void
     */
    private function resetContext()
    {
        $this->lastObjectType = null;
        $this->lastObjectId = null;
        $this->lastActionResult = null;
        $this->errors = array();
        $this->currentStore = null;
    }

    /**
     * Factory protégée SyncFlowPolicy (mockable en test unitaire — Story 53-3, site 2, pattern
     * createSyncUtils() de api_doli2shop.class.php:335).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db);
    }

    /**
     * Handle an inventory webhook event
     *
     * @param string      $topic Webhook topic (inventory_levels/update)
     * @param array       $data  Decoded JSON payload
     * @param object|null $store Boutique résolue par WebhookManager::resolveStoreForRouting() (null = rétrocompat)
     * @return int >0 if OK, <0 if KO
     */
    public function handleWebhook($topic, $data, $store = null)
    {
        global $conf;

        $this->resetContext();
        // Epic 47-4 : stocker la boutique résolue (disponible pour usage futur dans l'inventaire)
        $this->currentStore = $store;

        $this->log("handleWebhook - topic=" . $topic, LOG_INFO);

        // Story 53-3 (site 2) : garde SyncFlowPolicy (flux stock, s2d, avec la boutique quand
        // elle est connue) au lieu de la garde globale SyncUtils.
        $storeIdForGate = (int) ($this->currentStore->rowid ?? 0);
        if (!$this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'shopify_to_dolibarr', $storeIdForGate)) {
            $this->log("handleWebhook - Stock sync from Shopify is disabled, skipping", LOG_INFO);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        // Anti-boucle : éviter de re-traiter un événement déclenché par Dolibarr
        // Flag PROCESSING_INVENTORY_WEBHOOK : posé par ce handler (protection inbound → trigger → outbound)
        // Flag SYNC_IN_PROGRESS : posé par outbound sync (protection outbound → webhook echo)
        if (!empty($conf->global->SHOPIFY_INTEGRATION_PROCESSING_INVENTORY_WEBHOOK)
            || !empty($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS)) {
            $this->log("handleWebhook - Already processing or sync in progress, skipping to prevent loop", LOG_DEBUG);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        // Poser le flag anti-boucle
        $conf->global->SHOPIFY_INTEGRATION_PROCESSING_INVENTORY_WEBHOOK = 1;

        try {
            $result = $this->handleInventoryUpdate($data);
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->log("handleWebhook - " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);
            $result = -1;
        } finally {
            // Toujours retirer le flag anti-boucle (jamais orphelin)
            unset($conf->global->SHOPIFY_INTEGRATION_PROCESSING_INVENTORY_WEBHOOK);
        }

        return $result;
    }

    /**
     * Handle inventory_levels/update event
     *
     * @param array $data Webhook payload data
     * @return int >0 if OK, <0 if KO
     */
    private function handleInventoryUpdate($data)
    {
        global $conf, $user;

        $shopifyInventoryItemId = isset($data['inventory_item_id']) ? $data['inventory_item_id'] : '';
        $shopifyLocationId = isset($data['location_id']) ? $data['location_id'] : '';
        $available = isset($data['available']) ? (int) $data['available'] : null;

        $this->log("handleInventoryUpdate - inventory_item_id=" . $shopifyInventoryItemId
            . " location_id=" . $shopifyLocationId
            . " available=" . ($available !== null ? $available : 'null'), LOG_INFO);

        if (empty($shopifyInventoryItemId)) {
            $this->errors[] = "Missing inventory_item_id in webhook data";
            $this->log("handleInventoryUpdate - Missing inventory_item_id", LOG_ERR);
            return -1;
        }

        if ($available === null) {
            $this->errors[] = "Missing available quantity in webhook data";
            $this->log("handleInventoryUpdate - Missing available quantity", LOG_ERR);
            return -1;
        }

        // Chercher le produit Dolibarr via la table de mapping inventaire
        $sql = "SELECT i.fk_product, i.shopify_inventory_item_id,";
        $sql .= " p.label as product_label, p.ref as product_ref";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_inventory as i";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = i.fk_product";
        $sql .= " WHERE i.shopify_inventory_item_id = '" . $this->db->escape($shopifyInventoryItemId) . "'";
        $sql .= " AND i.entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = "SQL error: " . $this->db->lasterror();
            $this->log("handleInventoryUpdate - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            $this->log("handleInventoryUpdate - No Dolibarr product found for inventory_item_id=" . $shopifyInventoryItemId . ", skipping", LOG_WARNING);
            $this->lastActionResult = 'skipped';
            return 1; // Pas une erreur, juste pas de mapping
        }

        $dolibarrProductId = (int) $obj->fk_product;
        $this->lastObjectType = 'product';
        $this->lastObjectId = $dolibarrProductId;
        $warehouseId = getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE_ID', 0);

        $this->log("handleInventoryUpdate - Found Dolibarr product ID=" . $dolibarrProductId
            . " ref=" . $obj->product_ref
            . " label=" . $obj->product_label
            . " warehouse=" . $warehouseId, LOG_INFO);

        // Calculer le stock actuel dans Dolibarr pour l'entrepôt spécifique
        // Le webhook Shopify envoie le stock par location, on doit comparer
        // avec le stock du même entrepôt Dolibarr (pas le stock global stock_reel)
        dol_include_once('/product/class/product.class.php');
        $product = new Product($this->db);
        $product->fetch($dolibarrProductId);

        $currentStock = 0;
        if ($warehouseId > 0) {
            // Récupérer le stock spécifique à l'entrepôt via llx_product_stock
            $sqlStock = "SELECT reel FROM " . MAIN_DB_PREFIX . "product_stock";
            $sqlStock .= " WHERE fk_product = " . ((int) $dolibarrProductId);
            $sqlStock .= " AND fk_entrepot = " . ((int) $warehouseId);
            $resStock = $this->db->query($sqlStock);
            if ($resStock && $this->db->num_rows($resStock) > 0) {
                $stockObj = $this->db->fetch_object($resStock);
                $currentStock = (float) $stockObj->reel;
            }
            $this->log("handleInventoryUpdate - Using warehouse-specific stock for warehouse=" . $warehouseId . " stock=" . $currentStock, LOG_DEBUG);
        } else {
            // Fallback : stock global si pas d'entrepôt spécifique
            $currentStock = $product->stock_reel;
            $this->log("handleInventoryUpdate - No specific warehouse, using global stock_reel=" . $currentStock, LOG_WARNING);
        }

        // Calculer la différence
        $diff = $available - $currentStock;

        if ($diff == 0) {
            $this->log("handleInventoryUpdate - Stock already matches (current=" . $currentStock . " shopify=" . $available . "), no movement needed", LOG_INFO);
            return 1;
        }

        $this->log("handleInventoryUpdate - Stock diff: current=" . $currentStock . " shopify=" . $available . " diff=" . $diff, LOG_INFO);

        // Créer un mouvement de stock
        dol_include_once('/product/stock/class/mouvementstock.class.php');
        $movement = new MouvementStock($this->db);

        // Direction : 0 = entrée, 1 = sortie
        $direction = ($diff > 0) ? 0 : 1;
        $qty = abs($diff);
        $label = 'Shopify webhook inventory_levels/update (item_id=' . $shopifyInventoryItemId . ')';

        $this->db->begin();

        $result = $movement->_create(
            $user,
            $dolibarrProductId,
            $warehouseId,
            $qty,
            $direction,
            0, // price
            $label
        );

        if ($result < 0) {
            $this->db->rollback();
            $this->errors[] = "Failed to create stock movement: " . $movement->error;
            $this->log("handleInventoryUpdate - Failed to create stock movement: " . $movement->error, LOG_ERR);
            return -1;
        }

        $this->db->commit();

        $this->log("handleInventoryUpdate - Stock movement created: product=" . $obj->product_ref
            . " direction=" . ($direction == 0 ? 'IN' : 'OUT')
            . " qty=" . $qty
            . " warehouse=" . $warehouseId, LOG_INFO);

        return 1;
    }
}
