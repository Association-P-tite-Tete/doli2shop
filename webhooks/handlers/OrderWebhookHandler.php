<?php
/**
 * @file        webhooks/handlers/OrderWebhookHandler.php
 * @brief       Handler for Shopify order webhooks (orders/create, updated, cancelled, fulfilled, paid, partially_fulfilled)
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
dol_include_once('/doli2shop/class/shopifyordermanager.class.php');
dol_include_once('/doli2shop/class/shopifyfulfillmentmanager.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/compta/facture/class/facture.class.php'); // v2.2.0: Story 2.4 — Détection factures liées lors annulation

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../../lib/compatibility.lib.php';

/**
 * Class OrderWebhookHandler
 * Gère les webhooks Shopify liés aux commandes
 */
class OrderWebhookHandler
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
     * Factory protégée SyncFlowPolicy (mockable en test unitaire — Story 53-3, pattern
     * createSyncUtils() de api_doli2shop.class.php:335).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db);
    }

    /**
     * Factory protégée ShopifyOrderManager (mockable en test unitaire — même motif que
     * createSyncFlowPolicy() ci-dessus, cf. ShopifyTriggerManager::createSyncFlowPolicy() et
     * OrderSyncToShopify::createSyncFlowPolicy()).
     *
     * Story reglage-commandes-non-payees-ignore-par-les-webhooks (HIGH n°1, Code Review) : avant ce
     * seam, `new ShopifyOrderManager(...)` était câblé en dur à chaque site d'appel — un test qui
     * voulait exercer réellement handleOrderCreate() déclenchait un fetch GraphQL réseau. Toutes les
     * instanciations de ce fichier passent désormais par cette factory unique.
     *
     * @return ShopifyOrderManager
     */
    protected function createOrderManager()
    {
        // Epic 47-4 : instancier avec la boutique résolue ($this->currentStore = null en rétrocompat)
        return new ShopifyOrderManager($this->db, null, $this->currentStore);
    }

    /**
     * Résout le flux SyncFlowPolicy correspondant à un topic webhook order (Story 53-3, site 1a).
     * Mapping sur les 6 topics réellement routés par le switch de handleWebhook() : orders/create
     * et orders/paid partagent le flux order_create ; orders/updated, orders/cancelled,
     * orders/fulfilled et orders/partially_fulfilled partagent order_status. Un topic non listé
     * (jamais atteint par le switch en pratique) retombe sur order_status par défaut, avec log —
     * comportement conservé de l'ancienne garde unique.
     *
     * @param  string $topic Topic webhook Shopify reçu
     * @return string        Identifiant de flux SyncFlowPolicy::FLOW_*
     */
    private function resolveOrderFlowForTopic($topic)
    {
        switch ($topic) {
            case 'orders/create':
            case 'orders/paid':
                return SyncFlowPolicy::FLOW_ORDER_CREATE;

            case 'orders/updated':
            case 'orders/cancelled':
            case 'orders/fulfilled':
            case 'orders/partially_fulfilled':
                return SyncFlowPolicy::FLOW_ORDER_STATUS;

            default:
                $this->log("resolveOrderFlowForTopic - Topic order non reconnu: " . $topic
                    . ", repli sur le flux order_status", LOG_WARNING);
                return SyncFlowPolicy::FLOW_ORDER_STATUS;
        }
    }

    /**
     * Handle an order webhook event
     *
     * @param string      $topic Webhook topic
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

        $shopifyOrderId = isset($data['id']) ? $data['id'] : '';
        $orderName = isset($data['name']) ? $data['name'] : '';

        $this->log("handleWebhook - topic=" . $topic
            . " shopifyOrderId=" . $shopifyOrderId
            . " name=" . $orderName, LOG_INFO);

        // Log détaillé de la commande
        $this->logOrderDetails($data);

        // Story 53-3 : la garde consulte désormais SyncFlowPolicy (flux dérivé du topic, avec la
        // boutique quand elle est connue) au lieu de la garde globale SyncUtils. Sans override
        // SYNC_FLOW_* ni per-store, la dérivation legacy reproduit exactement l'ancien comportement.
        $orderFlowId = $this->resolveOrderFlowForTopic($topic);
        $storeIdForGate = (int) ($this->currentStore->rowid ?? 0);
        if (!$this->createSyncFlowPolicy()->isAllowed($orderFlowId, 'shopify_to_dolibarr', $storeIdForGate)) {
            $this->log("handleWebhook - Order sync from Shopify is disabled for flow=" . $orderFlowId
                . " (topic=" . $topic . ", storeId=" . $storeIdForGate . "), skipping", LOG_INFO);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        // Anti-boucle : éviter de re-traiter un événement déclenché par Dolibarr
        // Flag PROCESSING_WEBHOOK : posé par ce handler (protection inbound → trigger → outbound)
        // Flag SYNC_IN_PROGRESS : posé par OrderSyncToShopify (protection outbound → webhook echo)
        if (!empty($conf->global->SHOPIFY_INTEGRATION_PROCESSING_WEBHOOK)
            || !empty($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS)) {
            $this->log("handleWebhook - Already processing or sync in progress, skipping to prevent loop", LOG_DEBUG);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        // Poser le flag anti-boucle
        $conf->global->SHOPIFY_INTEGRATION_PROCESSING_WEBHOOK = 1;

        try {
            switch ($topic) {
                case 'orders/create':
                    $result = $this->handleOrderCreate($data, $topic);
                    break;

                case 'orders/paid':
                    $result = $this->handleOrderPaid($data, $topic);
                    break;

                case 'orders/updated':
                    $result = $this->handleOrderUpdated($data);
                    break;

                case 'orders/cancelled':
                    $result = $this->handleOrderCancelled($data);
                    break;

                case 'orders/fulfilled':
                case 'orders/partially_fulfilled':
                    $result = $this->handleOrderFulfilled($data, $topic);
                    break;

                default:
                    $this->log("handleWebhook - Unknown order topic: " . $topic, LOG_WARNING);
                    $result = -1;
                    break;
            }
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->log("handleWebhook - " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);
            $result = -1;
        } finally {
            // Toujours retirer le flag anti-boucle (jamais orphelin)
            unset($conf->global->SHOPIFY_INTEGRATION_PROCESSING_WEBHOOK);
        }

        return $result;
    }

    /**
     * Log order details for debugging
     *
     * @param array $data Order data
     * @return void
     */
    private function logOrderDetails($data)
    {
        $this->log("  Order name: " . ($data['name'] ?? ''), LOG_INFO);
        $this->log("  Financial status: " . ($data['financial_status'] ?? ''), LOG_INFO);
        $this->log("  Fulfillment status: " . ($data['fulfillment_status'] ?? 'unfulfilled'), LOG_INFO);
        $this->log("  Total price: " . ($data['total_price'] ?? '') . " " . ($data['currency'] ?? ''), LOG_INFO);

        if (isset($data['customer'])) {
            $this->log("  Customer: " . ($data['customer']['email'] ?? 'no email')
                . " (" . ($data['customer']['first_name'] ?? '') . " " . ($data['customer']['last_name'] ?? '') . ")", LOG_INFO);
        }

        $lineItemCount = isset($data['line_items']) ? count($data['line_items']) : 0;
        $this->log("  Line items count: " . $lineItemCount, LOG_INFO);

        if (isset($data['shipping_lines']) && is_array($data['shipping_lines'])) {
            foreach ($data['shipping_lines'] as $shippingLine) {
                $this->log("  Shipping: " . ($shippingLine['title'] ?? '') . " - " . ($shippingLine['price'] ?? ''), LOG_DEBUG);
            }
        }
    }

    /**
     * Handle orders/create event (Story 12.2 — renamed from handleOrderCreateOrPaid)
     *
     * Creates a Dolibarr order + invoice. If financial_status is "paid",
     * also triggers payment creation via processPaymentForOrder().
     *
     * @param array  $data  Webhook payload data
     * @param string $topic Webhook topic for logging
     * @return int >0 if OK, <0 if KO
     */
    private function handleOrderCreate($data, $topic)
    {
        $shopifyOrderId = $data['id'];

        $this->log("handleOrderCreate - Processing " . $topic . " for order " . $shopifyOrderId
            . " (" . ($data['name'] ?? '') . ")", LOG_INFO);

        try {
            // Epic 47-4 : instancier avec la boutique résolue ($this->currentStore = null en rétrocompat)
            $orderManager = $this->createOrderManager();
            $result = $orderManager->processSingleOrderFromWebhook($shopifyOrderId);

            $this->lastObjectType = 'commande';

            if ($result > 0) {
                $this->lastObjectId = $result;
                $this->log("handleOrderCreate - Commande Dolibarr #" . $result
                    . " creee (Shopify #" . $shopifyOrderId . ")", LOG_INFO);

                // Story 12.2: Check financial_status and trigger payment if already paid (AC2)
                $financialStatus = $data['financial_status'] ?? '';
                if ($financialStatus === 'paid') {
                    $paymentResult = $orderManager->processPaymentForOrder($result, $data, $this->getWebhookUser());
                    if ($paymentResult > 0) {
                        $this->log("handleOrderCreate - Payment created for already-paid order #" . $result, LOG_INFO);
                    } elseif ($paymentResult == 0) {
                        $this->log("handleOrderCreate - Payment skipped for order #" . $result
                            . " (already exists or conditions not met)", LOG_INFO);
                    } else {
                        $this->log("handleOrderCreate - Payment creation failed (non-blocking) for order #"
                            . $result, LOG_WARNING);
                    }
                } else {
                    $this->log("handleOrderCreate - Order #" . $result . " created, awaiting payment"
                        . " (financial_status=" . $financialStatus . ")", LOG_INFO);
                }

                return 1;
            } elseif ($result === 0) {
                // Story reglage-commandes-non-payees-ignore-par-les-webhooks (AC1/AC3/AC5) :
                // distinguer le refus métier explicite (commande non payée, réglage désactivé) du
                // doublon générique — sans quoi ce refus retombe dans le bucket 'duplicate' et perd
                // sa raison, exactement le refus muet que cette story dénonce.
                if ($orderManager->lastSkipReason === 'unpaid') {
                    $this->lastActionResult = 'skipped_unpaid';
                    $this->log("handleOrderCreate - Order Shopify #" . $shopifyOrderId
                        . " NOT imported: unpaid and DOLI2SHOP_SYNC_NON_PAID_ORDERS disabled", LOG_INFO);
                } elseif ($orderManager->lastSkipReason === 'unpaid_status_unknown') {
                    // MEDIUM-1 (Code Review) : distinguer, dans la trace ET à l'écran, le refus
                    // franc (commande PENDING/AUTHORIZED/... connue) du cas où displayFinancialStatus
                    // est absent/vide ou d'une valeur Shopify jamais documentée — ce dernier cas
                    // égare le diagnostic support (il ressemble à un refus normal, alors qu'il
                    // signale potentiellement une anomalie côté API Shopify). Le comportement de
                    // refus (fail-closed) est IDENTIQUE dans les deux cas — seule la trace change.
                    $this->lastActionResult = 'skipped_fin_unknown';
                    $this->log("handleOrderCreate - Order Shopify #" . $shopifyOrderId
                        . " NOT imported: displayFinancialStatus indéterminé (absent/vide/valeur inconnue)"
                        . " et DOLI2SHOP_SYNC_NON_PAID_ORDERS désactivé", LOG_WARNING);
                } elseif ($orderManager->lastSkipReason === 'taxes_included_unknown') {
                    // Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (AC3) :
                    // refus fail-closed distinct — `taxesIncluded` absent/de type invalide dans la
                    // réponse GraphQL Shopify, impossible de déterminer si les montants sont TTC ou
                    // HT. PAS un doublon, PAS un refus financier — badge et libellé dédiés.
                    $this->lastActionResult = 'skipped_taxes_unknown';
                    $this->log("handleOrderCreate - Order Shopify #" . $shopifyOrderId
                        . " NOT imported: taxesIncluded absent ou invalide dans la réponse GraphQL"
                        . " (impossible de déterminer si les prix sont TTC ou HT)", LOG_ERR);
                } else {
                    // Order already exists (duplicate webhook or concurrent processing)
                    $this->lastActionResult = 'duplicate';
                    $this->log("handleOrderCreate - Order already exists for Shopify #" . $shopifyOrderId
                        . " (duplicate webhook)", LOG_INFO);
                }
                // AC5 : acquitté (200) dans les deux cas — un refus volontaire n'est pas un échec,
                // ne doit ni être rejoué en boucle par Shopify, ni finir en file d'échec (dead_letter).
                return 1;
            } else {
                $errorMsg = implode(', ', $orderManager->errors);
                $this->errors[] = !empty($errorMsg) ? $errorMsg : 'Unknown error (no details from order manager)';
                $this->log("handleOrderCreate - Failed: " . ($errorMsg ?: 'Unknown error'), LOG_ERR);
                return -1;
            }
        } catch (\Throwable $e) {
            $this->lastObjectType = 'commande';
            $this->errors[] = $e->getMessage();
            $this->log("handleOrderCreate - " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Handle orders/paid webhook — differentiated from orders/create (Story 12.2)
     *
     * If the Dolibarr order already exists, find its linked invoice and trigger
     * payment creation. If the order doesn't exist (race condition), fall back
     * to handleOrderCreate() which will detect financial_status=paid and
     * trigger payment automatically.
     *
     * @param  array  $data   Shopify order data
     * @param  string $topic  Webhook topic
     * @return int            >0 success, <0 error
     */
    private function handleOrderPaid($data, $topic)
    {
        $shopifyOrderId = isset($data['id']) ? $data['id'] : '';

        $this->log("handleOrderPaid - Processing " . $topic . " for order " . $shopifyOrderId
            . " (" . (isset($data['name']) ? $data['name'] : '') . ")", LOG_INFO);

        // 1. Chercher la commande Dolibarr existante
        $dolibarrOrderId = $this->findDolibarrOrderId($shopifyOrderId);

        if (!$dolibarrOrderId) {
            // AC4: Race condition — orders/paid arrived before orders/create
            $this->log("handleOrderPaid - No Dolibarr order found for Shopify #" . $shopifyOrderId
                . ", fallback to handleOrderCreate (race condition)", LOG_INFO);
            // handleOrderCreate will create order + invoice, and detect financial_status=paid
            // to trigger payment automatically (Task 4 / AC2 logic)
            return $this->handleOrderCreate($data, $topic);
        }

        // 2. Order exists — trigger payment processing
        $this->lastObjectType = 'commande';
        $this->lastObjectId = $dolibarrOrderId;

        try {
            // Epic 47-4 : instancier avec la boutique résolue ($this->currentStore = null en rétrocompat)
            $orderManager = $this->createOrderManager();
            $user = $this->getWebhookUser();

            $paymentResult = $orderManager->processPaymentForOrder($dolibarrOrderId, $data, $user);

            if ($paymentResult > 0) {
                $this->lastObjectType = 'paiement';
                $this->lastObjectId = $paymentResult;
                $this->log("handleOrderPaid - Payment #" . $paymentResult . " created for order #"
                    . $dolibarrOrderId . " (Shopify #" . $shopifyOrderId . ")", LOG_INFO);
            } elseif ($paymentResult == 0) {
                // AC5: Payment already exists or skipped (idempotence)
                $this->log("handleOrderPaid - Payment skipped for order #" . $dolibarrOrderId
                    . " (already exists or conditions not met)", LOG_INFO);
            } else {
                $this->log("handleOrderPaid - Payment creation failed for order #"
                    . $dolibarrOrderId . ": " . implode(', ', $orderManager->errors), LOG_WARNING);
            }

            // Return 1 (webhook processed) even if payment failed — payment failure is
            // non-blocking to avoid Shopify retries. Story 12.3 should revisit if needed.
            return 1;
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->log("handleOrderPaid - " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Get the webhook user for payment operations (Story 12.2)
     *
     * @return User The user object for webhook processing
     */
    private function getWebhookUser()
    {
        global $user;
        return $user;
    }

    /**
     * Handle orders/updated event
     *
     * @param array $data Webhook payload data
     * @return int >0 if OK, <0 if KO
     */
    private function handleOrderUpdated($data)
    {
        global $conf, $user;

        $shopifyOrderId = $data['id'];

        $this->log("handleOrderUpdated - Processing update for order " . $shopifyOrderId
            . " (" . ($data['name'] ?? '') . ")", LOG_INFO);

        // Chercher la commande Dolibarr via la table de mapping
        $dolibarrOrderId = $this->findDolibarrOrderId($shopifyOrderId);

        if (!$dolibarrOrderId) {
            $this->log("handleOrderUpdated - No Dolibarr order found for Shopify order " . $shopifyOrderId . ", trying to create", LOG_INFO);
            // Si la commande n'existe pas encore, traiter comme une création
            // handleOrderCreate set les proprietes de contexte
            return $this->handleOrderCreate($data, 'orders/updated');
        }

        $this->lastObjectType = 'commande';
        $this->lastObjectId = $dolibarrOrderId;

        // Mettre à jour la note si elle a changé
        $order = new Commande($this->db);
        $fetchResult = $order->fetch($dolibarrOrderId);

        if ($fetchResult <= 0) {
            $this->log("handleOrderUpdated - Could not fetch Dolibarr order ID=" . $dolibarrOrderId, LOG_WARNING);
            return 1;
        }

        $updated = false;

        // Mettre à jour la note client si différente
        if (isset($data['note']) && $order->note_public !== $data['note']) {
            $this->log("handleOrderUpdated - Updating note_public for order ID=" . $dolibarrOrderId, LOG_INFO);
            $order->note_public = $data['note'];
            $updated = true;
        }

        // Mettre à jour les tags dans note_private
        if (isset($data['tags']) && !empty($data['tags'])) {
            $tags = is_array($data['tags']) ? implode(', ', $data['tags']) : $data['tags'];
            $tagLine = '[Shopify tags: ' . $tags . ']';

            if (strpos($order->note_private, '[Shopify tags:') === false) {
                $order->note_private = (!empty($order->note_private) ? $order->note_private . "\n" : '') . $tagLine;
                $updated = true;
            }
        }

        // =====================================================================
        // v2.2.0 : DÉTECTION CHANGEMENTS FULFILLMENT (Story 11.1)
        // Shopify envoie orders/updated (pas orders/fulfilled) quand un tracking
        // est ajouté/modifié sur un fulfillment existant.
        // =====================================================================
        // Story 53-3 (site 1b) : la garde consulte SyncFlowPolicy (flux shipping, s2d) au lieu de
        // lire directement DOLI2SHOP_AUTO_CREATE_EXPEDITION — un override per-store désormais
        // effectif (avant cette story, ce bloc lisait toujours la constante globale même si la
        // boutique courante avait désactivé la création auto en per-store).
        $storeIdForShipping = (int) ($this->currentStore->rowid ?? 0);
        if ($this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_SHIPPING, 'shopify_to_dolibarr', $storeIdForShipping)
            && isset($data['fulfillments']) && is_array($data['fulfillments'])
            && !empty($data['fulfillments'])) {

            // Epic 47-4 : instancier avec la boutique résolue ($this->currentStore = null en rétrocompat)
            $orderManager = $this->createOrderManager();

            foreach ($data['fulfillments'] as $fulfillment) {
                $trackingNumber = $fulfillment['tracking_number'] ?? '';
                if (empty($trackingNumber)) {
                    continue; // Fulfillment sans tracking → rien à faire
                }

                // 1) Tenter mise à jour tracking sur expédition existante
                $trackingUpdated = $this->updateExpeditionTracking($order, $fulfillment, $user);

                if (!$trackingUpdated) {
                    // 2) Pas d'expédition existante ou tracking identique → tenter création
                    $orderManager->errors = array();
                    $expeditionResult = $orderManager->createExpeditionFromFulfillment(
                        $order, $fulfillment, $user, false
                    );
                    if ($expeditionResult > 0) {
                        $this->log("handleOrderUpdated - Fulfillment detected in orders/updated, expedition #"
                            . $expeditionResult . " created for order ID=" . $dolibarrOrderId, LOG_INFO);
                        $updated = true;
                    } elseif ($expeditionResult == 0) {
                        $this->log("handleOrderUpdated - Fulfillment already processed for order ID="
                            . $dolibarrOrderId . " tracking=" . $trackingNumber, LOG_DEBUG);
                    } else {
                        $this->log("handleOrderUpdated - Expedition creation failed (non-blocking) for order #"
                            . $dolibarrOrderId . ": " . implode(', ', $orderManager->errors), LOG_WARNING);
                    }
                } else {
                    $updated = true;
                }
            }

            // Mettre à jour le statut fulfillment dans la table de sync
            $fulfillmentStatus = $data['fulfillment_status'] ?? '';
            if (!empty($fulfillmentStatus)) {
                $sqlFulfillment = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_orders SET"
                    . " dolOrderFulfillment = '" . $this->db->escape($fulfillmentStatus) . "'"
                    . " WHERE shopifyOrderId = '" . $this->db->escape($shopifyOrderId) . "'"
                    . " AND entity = " . ((int) $conf->entity);
                $resFulfillment = $this->db->query($sqlFulfillment);
                if (!$resFulfillment) {
                    $this->log("handleOrderUpdated - SQL error updating fulfillment status: " . $this->db->lasterror(), LOG_WARNING);
                }
            }

            // Tracking info saved as note (fallback informatif, même pattern que handleOrderFulfilled)
            $noteLines = array();
            foreach ($data['fulfillments'] as $fulfillment) {
                $trackingInfo = array();
                if (!empty($fulfillment['tracking_number'])) {
                    $trackingInfo[] = 'tracking=' . $fulfillment['tracking_number'];
                }
                if (!empty($fulfillment['tracking_url'])) {
                    $trackingInfo[] = 'url=' . $fulfillment['tracking_url'];
                }
                if (!empty($fulfillment['tracking_company'])) {
                    $trackingInfo[] = 'carrier=' . $fulfillment['tracking_company'];
                }
                if (!empty($trackingInfo)) {
                    $noteTag = '[Shopify fulfillment: ' . implode(', ', $trackingInfo) . ' - ' . date('Y-m-d H:i:s') . ']';
                    // Éviter doublons dans note_private (idempotence)
                    if (empty($order->note_private) || strpos($order->note_private, 'tracking=' . ($fulfillment['tracking_number'] ?? '')) === false) {
                        $noteLines[] = $noteTag;
                    }
                }
            }
            if (!empty($noteLines)) {
                $order->note_private = (!empty($order->note_private) ? $order->note_private . "\n" : '')
                    . implode("\n", $noteLines);
                $updated = true;
            }
        }

        if ($updated) {
            $result = $order->update($user);
            if ($result < 0) {
                $this->errors[] = "Failed to update order: " . $order->error;
                $this->log("handleOrderUpdated - Failed to update order ID=" . $dolibarrOrderId . ": " . $order->error, LOG_ERR);
                return -1;
            }
            $this->log("handleOrderUpdated - Order ID=" . $dolibarrOrderId . " updated successfully", LOG_INFO);
        } else {
            $this->log("handleOrderUpdated - No changes detected for order ID=" . $dolibarrOrderId, LOG_INFO);
        }

        return 1;
    }

    /**
     * Handle orders/cancelled event
     *
     * Annule la commande Dolibarr correspondante et détecte les documents liés
     * (factures, expéditions) nécessitant une intervention manuelle.
     *
     * @param array $data Webhook payload data
     * @return int >0 if OK, <0 if KO
     */
    private function handleOrderCancelled($data)
    {
        global $conf, $user;

        $shopifyOrderId = $data['id'];
        $cancelReason = isset($data['cancel_reason']) ? $data['cancel_reason'] : '';

        $this->log("handleOrderCancelled - Processing cancellation for order " . $shopifyOrderId
            . " (" . (isset($data['name']) ? $data['name'] : '') . ") reason=" . $cancelReason, LOG_INFO);

        $dolibarrOrderId = $this->findDolibarrOrderId($shopifyOrderId);

        // AC #3 : commande non importée dans Dolibarr → ignorer (pas une erreur)
        if (!$dolibarrOrderId) {
            $this->log("handleOrderCancelled - Annulation ignoree: commande Shopify #" . $shopifyOrderId . " non importee dans Dolibarr", LOG_INFO);
            $this->lastActionResult = 'skipped';
            return 1;
        }

        $this->lastObjectType = 'commande';
        $this->lastObjectId = $dolibarrOrderId;

        $order = new Commande($this->db);
        $fetchResult = $order->fetch($dolibarrOrderId);

        if ($fetchResult <= 0) {
            $this->log("handleOrderCancelled - Could not fetch Dolibarr order ID=" . $dolibarrOrderId, LOG_WARNING);
            return 1;
        }

        // Idempotence : commande déjà annulée → skip
        if ($order->statut == Commande::STATUS_CANCELED) {
            $this->log("handleOrderCancelled - Commande #" . $dolibarrOrderId . " deja annulee", LOG_INFO);
            return 1;
        }

        // Annuler la commande Dolibarr
        $result = $order->cancel($user);
        if ($result < 0) {
            $this->errors[] = "Failed to cancel order: " . $order->error;
            $this->log("handleOrderCancelled - Failed to cancel order ID=" . $dolibarrOrderId . ": " . $order->error, LOG_ERR);
            return -1;
        }

        // Ajouter une note avec la raison d'annulation
        if (!empty($cancelReason)) {
            $order->note_private = (!empty($order->note_private) ? $order->note_private . "\n" : '')
                . '[Shopify cancel reason: ' . $cancelReason . ' - ' . date('Y-m-d H:i:s') . ']';
            $order->update($user);
        }

        // v2.2.0 : Détection factures liées (Story 2.4 — AC #2)
        $sqlInvoices = "SELECT ee.fk_target, f.ref, f.fk_statut"
            . " FROM " . MAIN_DB_PREFIX . "element_element ee"
            . " INNER JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = ee.fk_target"
            . " WHERE ee.sourcetype = 'commande' AND ee.fk_source = " . ((int) $order->id)
            . " AND ee.targettype = 'facture'"
            . " AND f.entity = " . ((int) $conf->entity);

        $resInvoices = $this->db->query($sqlInvoices);
        if ($resInvoices) {
            while ($objInv = $this->db->fetch_object($resInvoices)) {
                if ($objInv->fk_statut == 0) {
                    // Facture brouillon → supprimer automatiquement
                    $draftInvoice = new Facture($this->db);
                    $fetchRes = $draftInvoice->fetch($objInv->fk_target);
                    if ($fetchRes > 0) {
                        $deleteRes = $draftInvoice->delete($user);
                        if ($deleteRes > 0) {
                            $this->log("handleOrderCancelled - Facture brouillon " . $objInv->ref . " supprimee pour commande annulee #" . $dolibarrOrderId, LOG_INFO);
                        } else {
                            $this->log("handleOrderCancelled - Echec suppression facture brouillon " . $objInv->ref . " pour commande #" . $dolibarrOrderId . ": " . $draftInvoice->error, LOG_WARNING);
                        }
                    } else {
                        $this->log("handleOrderCancelled - Impossible de charger facture brouillon ID=" . $objInv->fk_target . " pour commande #" . $dolibarrOrderId, LOG_WARNING);
                    }
                } else {
                    // Facture validée ou payée → WARNING intervention manuelle
                    $this->log("handleOrderCancelled - Commande annulee mais facture " . $objInv->ref . " deja validee — intervention manuelle requise", LOG_WARNING);
                }
            }
            $this->db->free($resInvoices);
        } else {
            $this->log("handleOrderCancelled - Erreur SQL detection factures liees pour commande #" . $dolibarrOrderId . ": " . $this->db->lasterror(), LOG_WARNING);
        }

        // v2.2.0 : Détection expéditions liées (Story 2.4)
        $sqlExpeditions = "SELECT ee.fk_target, e.ref"
            . " FROM " . MAIN_DB_PREFIX . "element_element ee"
            . " INNER JOIN " . MAIN_DB_PREFIX . "expedition e ON e.rowid = ee.fk_target"
            . " WHERE ee.sourcetype = 'commande' AND ee.fk_source = " . ((int) $order->id)
            . " AND ee.targettype = 'shipping'"
            . " AND e.entity = " . ((int) $conf->entity);

        $resExpeditions = $this->db->query($sqlExpeditions);
        if ($resExpeditions) {
            while ($objExp = $this->db->fetch_object($resExpeditions)) {
                $this->log("handleOrderCancelled - Expedition " . $objExp->ref . " existe pour commande annulee #" . $dolibarrOrderId . " — verification manuelle recommandee", LOG_WARNING);
            }
            $this->db->free($resExpeditions);
        } else {
            $this->log("handleOrderCancelled - Erreur SQL detection expeditions liees pour commande #" . $dolibarrOrderId . ": " . $this->db->lasterror(), LOG_WARNING);
        }

        // AC #1 : log succès avec IDs Dolibarr et Shopify
        $this->log("handleOrderCancelled - Commande Dolibarr #" . $dolibarrOrderId . " annulee suite a annulation Shopify #" . $shopifyOrderId, LOG_INFO);

        return 1;
    }

    /**
     * Handle orders/fulfilled and orders/partially_fulfilled events
     *
     * @param array  $data  Webhook payload data
     * @param string $topic Webhook topic
     * @return int >0 if OK, <0 if KO
     */
    private function handleOrderFulfilled($data, $topic)
    {
        global $conf, $user;

        $shopifyOrderId = $data['id'];
        $fulfillmentStatus = $data['fulfillment_status'] ?? '';

        $this->log("handleOrderFulfilled - Processing " . $topic . " for order " . $shopifyOrderId
            . " fulfillment_status=" . $fulfillmentStatus, LOG_INFO);

        // Log tracking info if available
        if (isset($data['fulfillments']) && is_array($data['fulfillments'])) {
            foreach ($data['fulfillments'] as $fulfillment) {
                $this->log("  Fulfillment: tracking_number=" . ($fulfillment['tracking_number'] ?? 'none')
                    . " tracking_url=" . ($fulfillment['tracking_url'] ?? 'none')
                    . " status=" . ($fulfillment['status'] ?? ''), LOG_INFO);
            }
        }

        $dolibarrOrderId = $this->findDolibarrOrderId($shopifyOrderId);

        if (!$dolibarrOrderId) {
            $this->log("handleOrderFulfilled - No Dolibarr order found for Shopify order " . $shopifyOrderId . ", marked as error (polling may catch later)", LOG_WARNING);
            $this->lastObjectType = 'commande';
            return -1;
        }

        $this->lastObjectType = 'commande';
        $this->lastObjectId = $dolibarrOrderId;

        // Mettre à jour le statut de fulfillment dans la table de sync
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_orders SET";
        $sql .= " dolOrderFulfillment = '" . $this->db->escape($fulfillmentStatus) . "'";
        $sql .= " WHERE shopifyOrderId = '" . $this->db->escape($shopifyOrderId) . "'";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("handleOrderFulfilled - SQL error updating fulfillment status: " . $this->db->lasterror(), LOG_WARNING);
        }

        // Fetch the Dolibarr order object (used for both expedition creation and note)
        $order = new Commande($this->db);
        $fetchResult = $order->fetch($dolibarrOrderId);

        // ====================================================================
        // v2.2.0 : CRÉATION AUTOMATIQUE BON D'EXPÉDITION (Story 2.3)
        // Note: Le changement de statut commande → "expédié" est géré par le
        // trigger Dolibarr SHIPPING_VALIDATE (si module Expedition activé).
        // Ce handler ne modifie PAS directement le statut de la commande.
        // ====================================================================
        // Story 53-3 (site 1c) : idem site 1b — gate SyncFlowPolicy (flux shipping, s2d) per-store.
        $expeditionFailed = false;
        $storeIdForShipping = (int) ($this->currentStore->rowid ?? 0);
        if ($fetchResult > 0 && $this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_SHIPPING, 'shopify_to_dolibarr', $storeIdForShipping)) {
            if (isset($data['fulfillments']) && is_array($data['fulfillments'])) {
                // Epic 47-4 : instancier avec la boutique résolue ($this->currentStore = null en rétrocompat)
                $orderManager = $this->createOrderManager();
                $isPartial = ($topic === 'orders/partially_fulfilled');

                foreach ($data['fulfillments'] as $fulfillment) {
                    $orderManager->errors = array(); // Reset errors before each fulfillment
                    $expeditionResult = $orderManager->createExpeditionFromFulfillment($order, $fulfillment, $user, $isPartial);
                    if ($expeditionResult < 0) {
                        // Expedition creation FAILED — propager l'erreur pour retry via WebhookProcessCron
                        $expeditionError = implode(', ', $orderManager->errors);
                        $this->errors[] = "Expedition creation failed for order #" . $dolibarrOrderId . ": " . $expeditionError;
                        $this->log("handleOrderFulfilled - Expedition creation FAILED for order #" . $dolibarrOrderId
                            . ": " . $expeditionError, LOG_ERR);
                        $expeditionFailed = true;
                    } elseif ($expeditionResult > 0) {
                        $this->lastObjectType = 'shipping';
                        $this->lastObjectId = $expeditionResult;
                        $this->log("handleOrderFulfilled - Expedition #" . $expeditionResult . " created for order #" . $dolibarrOrderId, LOG_INFO);
                    }
                }
            }
        }

        // ====================================================================
        // Story 40.1 : PERSISTANCE ÉVÉNEMENTS FULFILLMENT (orthogonal Epic 11)
        // Activé par flag DOLI2SHOP_FETCH_FULFILLMENT_EVENTS (défaut=0)
        // Note: le webhook REST Shopify ne contient PAS les FulfillmentEvents
        // (displayStatus, events GPS, timeline). Ces données sont disponibles
        // uniquement via GraphQL et sont persistées par le catchup CRON
        // (ShopifyFulfillmentCatchupCron) dans llx_doli2shop_fulfillment_events,
        // qui est l'unique source pour la timeline affichée dans la fiche commande.
        // On ne persiste donc rien ici depuis le payload REST du webhook
        // (pas d'extrafields commande — cf. Story 40, Option A).
        // ====================================================================

        // Tracking info saved as note (preserved as fallback and informational)
        if ($fetchResult > 0 && isset($data['fulfillments']) && is_array($data['fulfillments'])) {
            $noteLines = array();
            foreach ($data['fulfillments'] as $fulfillment) {
                $trackingInfo = array();
                if (!empty($fulfillment['tracking_number'])) {
                    $trackingInfo[] = 'tracking=' . $fulfillment['tracking_number'];
                }
                if (!empty($fulfillment['tracking_url'])) {
                    $trackingInfo[] = 'url=' . $fulfillment['tracking_url'];
                }
                if (!empty($fulfillment['tracking_company'])) {
                    $trackingInfo[] = 'carrier=' . $fulfillment['tracking_company'];
                }
                if (!empty($trackingInfo)) {
                    $noteLines[] = '[Shopify fulfillment: ' . implode(', ', $trackingInfo) . ' - ' . date('Y-m-d H:i:s') . ']';
                }
            }

            if (!empty($noteLines)) {
                // FIX v2.2.2: Re-fetch l'objet commande avant update() pour éviter d'écraser
                // le statut mis à jour par expedition->setClosed() → order->cloture()
                // (Bug "stale object" : l'objet en mémoire avait encore fk_statut=1 alors que
                // la BDD avait déjà fk_statut=3 après clôture via l'expédition)
                $order->fetch($dolibarrOrderId);

                $order->note_private = (!empty($order->note_private) ? $order->note_private . "\n" : '')
                    . implode("\n", $noteLines);
                $order->update($user);
                $this->log("handleOrderFulfilled - Tracking info saved for order ID=" . $dolibarrOrderId, LOG_INFO);
            }
        }

        $this->log("handleOrderFulfilled - Order ID=" . $dolibarrOrderId . " fulfillment updated: " . $fulfillmentStatus, LOG_INFO);

        // Si la création d'expédition a échoué, retourner -1 pour que WebhookProcessCron retente (max 5 tries)
        // La note de tracking et le statut fulfillment sont déjà sauvegardés (non perdus au retry)
        if ($expeditionFailed) {
            return -1;
        }

        return 1;
    }

    /**
     * Update tracking info on an existing expedition if fulfillment is already processed
     * but tracking has changed (Story 11.1)
     *
     * @param  Commande $order       Dolibarr order object
     * @param  array    $fulfillment Shopify fulfillment data
     * @param  User     $user        Dolibarr user object
     * @return bool     true if an expedition was updated, false otherwise
     */
    private function updateExpeditionTracking($order, $fulfillment, $user)
    {
        global $conf;

        $newTrackingNumber = $fulfillment['tracking_number'] ?? '';
        $newTrackingUrl = $fulfillment['tracking_url'] ?? '';
        $fulfillmentId = (string) ($fulfillment['id'] ?? '');

        if (empty($newTrackingNumber) && empty($fulfillmentId)) {
            return false;
        }

        dol_include_once('/expedition/class/expedition.class.php');

        // Chercher les expéditions liées à cette commande
        // v2.2.2 — Retrait de e.tracking_url : la colonne n'existe pas dans llx_expedition
        // (Dolibarr stocke tracking_url comme propriété calculée, pas comme colonne SQL).
        // tracking_url est lu plus tard via Expedition::fetch() si besoin.
        $sql = "SELECT e.rowid, e.tracking_number, e.note_private"
            . " FROM " . MAIN_DB_PREFIX . "expedition e"
            . " INNER JOIN " . MAIN_DB_PREFIX . "element_element ee"
            . "   ON ee.fk_target = e.rowid AND ee.targettype = 'shipping'"
            . " WHERE ee.sourcetype = 'commande' AND ee.fk_source = " . ((int) $order->id)
            . " AND e.entity = " . ((int) $conf->entity);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->log("updateExpeditionTracking - SQL error: " . $this->db->lasterror(), LOG_WARNING);
            return false;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            // Match par fulfillment_id dans note_private
            $matchesFulfillment = (!empty($fulfillmentId)
                && strpos($obj->note_private, 'fulfillment_id=' . $fulfillmentId) !== false);

            if ($matchesFulfillment && $obj->tracking_number !== $newTrackingNumber) {
                // Tracking a changé → mettre à jour
                $expedition = new Expedition($this->db);
                $expedition->fetch($obj->rowid);
                $oldTracking = $expedition->tracking_number;
                $expedition->tracking_number = $newTrackingNumber;
                $expedition->tracking_url = $newTrackingUrl;
                $result = $expedition->update($user);

                if ($result >= 0) {
                    $this->log("updateExpeditionTracking - Tracking updated for expedition #" . $obj->rowid
                        . " (old=" . $oldTracking . " new=" . $newTrackingNumber . ")", LOG_INFO);
                    $this->db->free($resql);
                    return true;
                } else {
                    $this->log("updateExpeditionTracking - Failed to update tracking for expedition #"
                        . $obj->rowid . ": " . $expedition->error, LOG_WARNING);
                    $this->db->free($resql);
                    return false;
                }
            }

            // Match par tracking identique → rien à faire (idempotent)
            if (!empty($obj->tracking_number) && $obj->tracking_number === $newTrackingNumber) {
                $this->db->free($resql);
                return false; // Pas de changement
            }
        }

        $this->db->free($resql);
        return false; // Pas d'expédition trouvée
    }

    /**
     * Find Dolibarr order ID from Shopify order ID
     *
     * @param string $shopifyOrderId Shopify order ID
     * @return int|false Dolibarr order ID or false if not found
     */
    private function findDolibarrOrderId($shopifyOrderId)
    {
        global $conf;

        $sql = "SELECT fk_commande FROM " . MAIN_DB_PREFIX . "doli2shop_orders";
        $sql .= " WHERE shopifyOrderId = '" . $this->db->escape($shopifyOrderId) . "'";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("findDolibarrOrderId - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($obj && $obj->fk_commande > 0) {
            return (int) $obj->fk_commande;
        }

        return false;
    }
}
