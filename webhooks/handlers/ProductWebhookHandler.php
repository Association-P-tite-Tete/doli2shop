<?php
/**
 * @file        webhooks/handlers/ProductWebhookHandler.php
 * @brief       Handler for Shopify product webhooks (products/create, update, delete)
 *
 * @package     ShopifyIntegration
 * @subpackage  Webhooks
 * @category    webhooks
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
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
require_once dirname(__FILE__) . '/../../class/shopifyproductimporter.class.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../../lib/compatibility.lib.php';

/**
 * Class ProductWebhookHandler
 * Gère les webhooks Shopify liés aux produits (products/create, update, delete)
 */
class ProductWebhookHandler
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
     * Factory protégée SyncFlowPolicy (mockable en test unitaire — Story 53-5, pattern
     * OrderWebhookHandler::createSyncFlowPolicy() de la Story 53-3).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db);
    }

    /**
     * Détermine si le gate d'entrée grossier (site 1) doit laisser passer ce topic webhook produit,
     * pour la boutique donnée.
     *
     * Correctif code review (MEDIUM-1) : products/create et products/update partagent désormais la
     * MÊME formule OU que les gates 2a (`ShopifyProductImporter::isImportEnabled()`) et 4a
     * (`ajax/sync_products_batch.php`) — autorisé dès que `product_create` OU `product_update` est
     * permis pour la boutique. Avant ce correctif, ce gate dérivait un flux UNIQUE depuis le seul
     * TOPIC (products/create -> product_create strict), alors que le gate fin (site 2b,
     * `ShopifyProductImporter::processProduct()`) classe create/update selon le MAPPING existant
     * (produit déjà mappé -> product_update, sinon product_create). Shopify livrant les webhooks
     * "at-least-once", une redélivrance de `products/create` pour un produit DÉJÀ mappé (créé lors
     * d'une première tentative, ou mappé via CRON entre-temps) était bloquée ICI si
     * `SYNC_FLOW_PRODUCT_CREATE=none` alors que le site 2b l'aurait autorisée en update — perte
     * silencieuse de sync avec un override différencié CREATE/UPDATE. La granularité fine reste
     * l'unique responsabilité du site 2b : ce gate ne coupe le lot QUE si NI l'une NI l'autre action
     * n'est permise.
     *
     * `products/delete` garde son propre gate dédié `product_delete` (aucun équivalent site 2b :
     * la suppression n'a pas de distinction création/mise à jour).
     *
     * @param  string        $topic   Topic webhook Shopify reçu
     * @param  SyncFlowPolicy $policy  Instance policy à consulter (factory createSyncFlowPolicy())
     * @param  int           $storeId Identifiant boutique (0 = portée globale)
     * @return bool
     */
    private function isProductWebhookEntryAllowed($topic, SyncFlowPolicy $policy, $storeId)
    {
        switch ($topic) {
            case 'products/create':
            case 'products/update':
                return $policy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_CREATE, 'shopify_to_dolibarr', $storeId)
                    || $policy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_UPDATE, 'shopify_to_dolibarr', $storeId);

            case 'products/delete':
                return $policy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_DELETE, 'shopify_to_dolibarr', $storeId);

            default:
                // Jamais atteint par le switch de handleWebhook() en pratique — le switch l.124-138
                // renverra ensuite son WARNING/-1 actuel pour ce topic inconnu. Repli sur
                // product_update seul, comportement identique à l'ancien resolveProductFlowForTopic().
                $this->log("isProductWebhookEntryAllowed - Topic produit non reconnu: " . $topic
                    . ", repli sur le flux product_update", LOG_WARNING);
                return $policy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_UPDATE, 'shopify_to_dolibarr', $storeId);
        }
    }

    /**
     * Handle a product webhook event
     *
     * @param string      $topic Webhook topic (products/create, products/update, products/delete)
     * @param array       $data  Decoded JSON payload
     * @param object|null $store Boutique résolue par WebhookManager::resolveStoreForRouting() (null = rétrocompat)
     * @return int >0 if OK, <0 if KO
     */
    public function handleWebhook($topic, $data, $store = null)
    {
        global $conf;

        $this->resetContext();
        // Epic 47-4 : stocker la boutique résolue pour propagation aux sous-méthodes
        $this->currentStore = $store;

        $shopifyProductId = isset($data['id']) ? $data['id'] : '';
        $title = isset($data['title']) ? $data['title'] : '';

        $this->log("handleWebhook - topic=" . $topic . " shopifyProductId=" . $shopifyProductId . " title=" . $title, LOG_INFO);

        // Story 53-5 : la garde consulte désormais SyncFlowPolicy (avec la boutique quand elle est
        // connue) au lieu de l'ancienne garde globale unique. Sans override SYNC_FLOW_* ni
        // per-store, la dérivation legacy reproduit exactement l'ancien comportement.
        // Correctif MEDIUM-1 : create/update utilisent la formule OU (cf. isProductWebhookEntryAllowed()),
        // symétrique des gates 2a/4a — la granularité fine reste au site 2b (processProduct).
        $storeIdForGate = (int) ($this->currentStore->rowid ?? 0);
        if (!$this->isProductWebhookEntryAllowed($topic, $this->createSyncFlowPolicy(), $storeIdForGate)) {
            $this->log("handleWebhook - Product sync from Shopify is disabled for topic=" . $topic
                . " (storeId=" . $storeIdForGate . "), skipping", LOG_INFO);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        // Anti-boucle : éviter de re-traiter un événement déclenché par Dolibarr
        // Flag PROCESSING_PRODUCT_WEBHOOK : posé par ce handler (protection inbound → trigger → outbound)
        // Flag SYNC_IN_PROGRESS : posé par outbound sync (protection outbound → webhook echo)
        if (!empty($conf->global->SHOPIFY_INTEGRATION_PROCESSING_PRODUCT_WEBHOOK)
            || !empty($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS)) {
            $this->log("handleWebhook - Already processing or sync in progress, skipping to prevent loop", LOG_DEBUG);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        // Poser le flag anti-boucle (vérifié par ShopifyTriggerManager::handleProductEvent ligne 174)
        $conf->global->SHOPIFY_INTEGRATION_PROCESSING_PRODUCT_WEBHOOK = 1;

        try {
            switch ($topic) {
                case 'products/create':
                case 'products/update':
                    $result = $this->handleProductCreateOrUpdate($data, $topic);
                    break;

                case 'products/delete':
                    $result = $this->handleProductDelete($data);
                    break;

                default:
                    $this->log("handleWebhook - Unknown product topic: " . $topic, LOG_WARNING);
                    $result = -1;
                    break;
            }
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->log("handleWebhook - " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);
            $result = -1;
        } finally {
            // Toujours retirer le flag anti-boucle (jamais orphelin)
            unset($conf->global->SHOPIFY_INTEGRATION_PROCESSING_PRODUCT_WEBHOOK);
        }

        return $result;
    }

    /**
     * Handle products/create and products/update events
     *
     * v2.2.2: Anti-loop — check doli2shop_products sync state before delegating
     * to ShopifyProductImporter. Prevents deadlocks and infinite loops when
     * Shopify echoes back a webhook triggered by our own CRON export.
     *
     * @param array  $data  Webhook payload data
     * @param string $topic Webhook topic for logging
     * @return int >0 if OK, <0 if KO
     */
    private function handleProductCreateOrUpdate($data, $topic)
    {
        global $conf, $user;

        $shopifyProductId = $data['id'];

        // Log détaillé du produit
        $this->log("handleProductCreateOrUpdate - Processing product:", LOG_INFO);
        $this->log("  ID: " . $shopifyProductId, LOG_INFO);
        $this->log("  Title: " . ($data['title'] ?? ''), LOG_INFO);
        $this->log("  Vendor: " . ($data['vendor'] ?? ''), LOG_INFO);
        $this->log("  Product Type: " . ($data['product_type'] ?? ''), LOG_INFO);
        $this->log("  Status: " . ($data['status'] ?? ''), LOG_INFO);
        $this->log("  Tags: " . ($data['tags'] ?? ''), LOG_INFO);
        $this->log("  Variants count: " . (isset($data['variants']) ? count($data['variants']) : 0), LOG_INFO);

        if (isset($data['variants']) && is_array($data['variants'])) {
            foreach ($data['variants'] as $variantIndex => $variant) {
                $this->log("  Variant #" . $variantIndex . ": SKU=" . ($variant['sku'] ?? '')
                    . " price=" . ($variant['price'] ?? '')
                    . " inventory_quantity=" . ($variant['inventory_quantity'] ?? ''), LOG_DEBUG);
            }
        }

        // v2.2.2: Anti-loop — cross-process temporal window check on doli2shop_products
        // Strip GID prefix if present (webhook payload sends numeric ID, but be safe)
        $numericShopifyId = preg_replace('/^gid:\/\/shopify\/Product\//', '', (string) $shopifyProductId);

        $sqlCheck = "SELECT sync_lock, last_sync_status, tms";
        $sqlCheck .= " FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sqlCheck .= " WHERE shopifyProductId = '" . $this->db->escape($numericShopifyId) . "'";
        $sqlCheck .= " AND entity = " . ((int) $conf->entity);
        $sqlCheck .= " LIMIT 1";

        $resqlCheck = $this->db->query($sqlCheck);
        if ($resqlCheck) {
            $syncRow = $this->db->fetch_object($resqlCheck);
            $this->db->free($resqlCheck);

            if ($syncRow) {
                // Check 1: sync_lock active and less than 5 minutes old → export in progress, skip to avoid deadlock
                if (!empty($syncRow->sync_lock)) {
                    $lockTime = strtotime($syncRow->sync_lock);
                    $lockAgeSeconds = time() - $lockTime;
                    if ($lockAgeSeconds >= 0 && $lockAgeSeconds < 300) {
                        $this->log("handleProductCreateOrUpdate - SKIP: sync_lock active ("
                            . $lockAgeSeconds . "s ago) for shopifyProductId=" . $numericShopifyId
                            . " — export in progress, avoiding deadlock", LOG_INFO);
                        $this->lastActionResult = 'skipped';
                        return 1;
                    }
                }

                // Check 2: last_sync_status = 'success' and tms < 30 seconds → echo of our own export
                if ($syncRow->last_sync_status === 'success' && !empty($syncRow->tms)) {
                    $tmsTime = strtotime($syncRow->tms);
                    $tmsAgeSeconds = time() - $tmsTime;
                    if ($tmsAgeSeconds >= 0 && $tmsAgeSeconds < 30) {
                        $this->log("handleProductCreateOrUpdate - SKIP: recent successful sync ("
                            . $tmsAgeSeconds . "s ago) for shopifyProductId=" . $numericShopifyId
                            . " — webhook is echo of our own export", LOG_INFO);
                        $this->lastActionResult = 'skipped';
                        return 1;
                    }
                }
            }
        } else {
            // Non-blocking: SQL error on anti-loop check should not prevent processing
            $this->log("handleProductCreateOrUpdate - Warning: anti-loop SQL check failed: "
                . $this->db->lasterror() . " — proceeding with import", LOG_WARNING);
        }

        // Utiliser ShopifyProductImporter pour importer/mettre à jour le produit
        // importSingleProduct() re-fetch via GraphQL puis importe
        // Epic 47-4 : instancier avec la boutique résolue ($this->currentStore = null en rétrocompat)
        $importer = new ShopifyProductImporter($this->db, $user, null, $this->currentStore);
        $importResult = $importer->importSingleProduct($shopifyProductId);

        if (!empty($importResult['success'])) {
            $this->lastObjectType = 'product';
            $this->lastObjectId = isset($importResult['dolibarrProductId'])
                ? (int) $importResult['dolibarrProductId'] : null;
            $this->log("handleProductCreateOrUpdate - " . $topic . " processed successfully: "
                . ($importResult['action'] ?? 'unknown') . " - "
                . ($importResult['message'] ?? ''), LOG_INFO);
            return 1;
        } else {
            $this->lastObjectType = 'product';
            $errorMsg = isset($importResult['message']) ? $importResult['message'] : 'Unknown error';
            $this->errors[] = $errorMsg;
            $this->log("handleProductCreateOrUpdate - Failed to process " . $topic . ": " . $errorMsg, LOG_ERR);
            return -1;
        }
    }

    /**
     * Handle products/delete event
     * Désactive le produit dans Dolibarr (ne supprime pas)
     *
     * @param array $data Webhook payload data
     * @return int >0 if OK, <0 if KO
     */
    private function handleProductDelete($data)
    {
        global $conf, $user;

        $shopifyProductId = $data['id'];

        $this->log("handleProductDelete - Shopify product deleted: ID=" . $shopifyProductId, LOG_INFO);

        // Chercher le produit dans la table de mapping
        $sql = "SELECT p.fk_product, dp.label as product_label, dp.ref as product_ref";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_products as p";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product as dp ON dp.rowid = p.fk_product";
        $sql .= " WHERE p.shopifyProductId = '" . $this->db->escape($shopifyProductId) . "'";
        $sql .= " AND p.entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = "SQL error: " . $this->db->lasterror();
            $this->log("handleProductDelete - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            $this->log("handleProductDelete - No Dolibarr product found for shopify_product_id=" . $shopifyProductId . ", nothing to do", LOG_INFO);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        $dolibarrProductId = (int) $obj->fk_product;
        $this->lastObjectType = 'product';
        $this->lastObjectId = $dolibarrProductId;

        $this->log("handleProductDelete - Found Dolibarr product ID=" . $dolibarrProductId
            . " ref=" . $obj->product_ref . " label=" . $obj->product_label, LOG_INFO);

        // Désactiver le produit Dolibarr (status=0) plutôt que le supprimer
        dol_include_once('/product/class/product.class.php');
        $product = new Product($this->db);
        $fetchResult = $product->fetch($dolibarrProductId);

        if ($fetchResult <= 0) {
            $this->log("handleProductDelete - Could not fetch Dolibarr product ID=" . $dolibarrProductId, LOG_WARNING);
            return 1;
        }

        // Mettre le statut à 0 (inactif/brouillon)
        $product->status = 0;
        $product->status_buy = 0;
        $result = $product->update($dolibarrProductId, $user);

        if ($result < 0) {
            $this->errors[] = "Failed to deactivate product: " . $product->error;
            $this->log("handleProductDelete - Failed to deactivate product ID=" . $dolibarrProductId . ": " . $product->error, LOG_ERR);
            return -1;
        }

        $this->log("handleProductDelete - Product deactivated in Dolibarr: ID=" . $dolibarrProductId . " ref=" . $obj->product_ref, LOG_INFO);

        return 1;
    }
}
