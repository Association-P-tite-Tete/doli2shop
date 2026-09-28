<?php
/**
 * Class for synchronizing orders from Dolibarr to Shopify
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
dol_include_once('/core/db/Database.interface.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/societe/class/societe.class.php');
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/orderstatusmapper.class.php';
require_once dirname(__FILE__) . '/shopifyordersync.class.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Class OrderSyncToShopify
 * Gère la synchronisation des commandes de Dolibarr vers Shopify
 */
class OrderSyncToShopify
{
    use LoggerTrait;

    /** @var DoliDb Database handler */
    private $db;
    
    /** @var ShopifyApi Shopify API handler */
    private $shopifyApi;

    /** @var OrderStatusMapper Status mapper */
    private $orderStatusMapper;
    
    /** @var array Error messages */
    public $errors = array();
    
    /** @var int Entity ID */
    private $entity;

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
        $this->entity = $entity ?: (isset($conf->entity) ? $conf->entity : 1);
        
        $this->log("Initialisation avec l'entité " . $this->entity, LOG_DEBUG);
        
        // Initialiser les classes utilitaires
        $this->shopifyApi = new ShopifyApi($db, $this->entity);
        $this->orderStatusMapper = new OrderStatusMapper($db, $this->entity);
    }

    /**
     * Factory protégée SyncFlowPolicy (Story 53-3, site 4 — remplace la propriété $syncUtils,
     * fini l'injection Reflection dans les tests, pattern createSyncUtils() de
     * api_doli2shop.class.php:335).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db, $this->entity);
    }

    /**
     * Vérifie que les mutations GraphQL sortantes (commandes) sont disponibles
     * dans ShopifyApi. Les méthodes createOrder/updateOrder/markOrderAsPaid/
     * createFulfillment sont planifiées pour la v2.4.x : tant qu'elles ne sont
     * pas implémentées, on sort en no-op plutôt que de déclencher une
     * Fatal Error "Call to undefined method" en production.
     *
     * @return bool True si l'API sortante commandes est disponible
     */
    private function isOutboundOrderApiAvailable()
    {
        return method_exists($this->shopifyApi, 'createOrder')
            && method_exists($this->shopifyApi, 'updateOrder')
            && method_exists($this->shopifyApi, 'markOrderAsPaid')
            && method_exists($this->shopifyApi, 'createFulfillment');
    }

    /**
     * Synchronise une commande Dolibarr vers Shopify
     *
     * @param int $dolibarrOrderId ID de la commande Dolibarr
     * @param bool $forceSync Forcer la synchronisation même si désactivée
     * @return int <0 si erreur, >0 si succès
     */
    public function syncOrderToShopify($dolibarrOrderId, $forceSync = false)
    {
        // Story 53-3 (site 4) : garde SyncFlowPolicy (flux order_create, d2s ; storeId=0 —
        // signature publique historique sans paramètre boutique, documenté ci-dessus).
        if (!$forceSync && !$this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_ORDER_CREATE, 'dolibarr_to_shopify', 0)) {
            $this->log("Synchronisation Dolibarr → Shopify désactivée pour les commandes", LOG_INFO);
            return 1; // Succès mais rien fait
        }

        if (!$this->isOutboundOrderApiAvailable()) {
            $this->log("OrderSyncToShopify::syncOrderToShopify - Sync commandes Dolibarr → Shopify non disponible (prévue v2.4.x), commande " . $dolibarrOrderId . " ignorée", LOG_WARNING);
            return 1; // No-op : ne pas bloquer le workflow Dolibarr
        }

        $this->log("Synchronisation de la commande Dolibarr ID " . $dolibarrOrderId . " vers Shopify", LOG_INFO);
        
        // Anti-boucle : nesting guard pour eviter qu'un finally interne ne retire le flag pose par un appelant externe (CRONs import)
        global $conf;
        $flagAlreadySet = (bool) getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS');
        if (!$flagAlreadySet) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;
        }

        try {
            $this->db->begin();

            // Récupérer la commande Dolibarr
            $order = new Commande($this->db);
            $result = $order->fetch($dolibarrOrderId);

            if ($result <= 0) {
                throw new Exception("Commande Dolibarr ID " . $dolibarrOrderId . " introuvable");
            }

            // Vérifier si une commande Shopify existe déjà
            $shopifyOrderId = $this->getShopifyOrderId($dolibarrOrderId);

            if ($shopifyOrderId) {
                $result = $this->updateShopifyOrder($order, $shopifyOrderId);
            } else {
                $result = $this->createShopifyOrder($order);
            }

            if ($result < 0) {
                throw new Exception(implode(', ', $this->errors));
            }

            $this->db->commit();
            return 1;
        } catch (Exception $e) {
            $this->db->rollback();
            $this->errors[] = $e->getMessage();
            $this->log("Erreur lors de la synchronisation: " . $e->getMessage(), LOG_ERR);
            return -1;
        } finally {
            // Ne retirer le flag que si c'est NOUS qui l'avons posé (pas un appelant externe)
            if (!$flagAlreadySet) {
                unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);
            }
        }
    }

    /**
     * Met à jour uniquement le statut d'une commande Shopify
     * 
     * @param int $dolibarrOrderId ID de la commande Dolibarr
     * @param int $newStatus Nouveau statut Dolibarr
     * @return int <0 si erreur, >0 si succès
     */
    public function updateOrderStatus($dolibarrOrderId, $newStatus)
    {
        // Story 53-3 (site 4) : garde SyncFlowPolicy (flux order_status, d2s ; storeId=0 — idem
        // syncOrderToShopify(), signature publique sans paramètre boutique).
        if (!$this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_ORDER_STATUS, 'dolibarr_to_shopify', 0)) {
            $this->log("Synchronisation Dolibarr → Shopify désactivée pour les commandes", LOG_INFO);
            return 1; // Succès mais rien fait
        }

        if (!$this->isOutboundOrderApiAvailable()) {
            $this->log("OrderSyncToShopify::updateOrderStatus - Sync commandes Dolibarr → Shopify non disponible (prévue v2.4.x), commande " . $dolibarrOrderId . " ignorée", LOG_WARNING);
            return 1; // No-op : ne pas bloquer le workflow Dolibarr
        }

        $this->log("Mise à jour du statut de la commande Dolibarr ID " . $dolibarrOrderId . " vers Shopify", LOG_INFO);
        
        // Anti-boucle : nesting guard pour eviter qu'un finally interne ne retire le flag pose par un appelant externe (CRONs import)
        global $conf;
        $flagAlreadySet = (bool) getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS');
        if (!$flagAlreadySet) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;
        }

        try {
            $this->db->begin();

            // Récupérer l'ID Shopify associé
            $shopifyOrderId = $this->getShopifyOrderId($dolibarrOrderId);

            if (!$shopifyOrderId) {
                throw new Exception("Aucune commande Shopify associée à la commande Dolibarr ID " . $dolibarrOrderId);
            }

            // Convertir le statut Dolibarr vers les statuts Shopify
            $shopifyStatuses = $this->orderStatusMapper->mapDolibarrToShopify($newStatus);

            // Formater l'ID pour l'API GraphQL
            $graphqlOrderId = 'gid://shopify/Order/' . $shopifyOrderId;

            // Mettre à jour selon les statuts spécifiques
            $result = $this->updateShopifyOrderStatuses($graphqlOrderId, $shopifyStatuses);

            if ($result < 0) {
                throw new Exception(implode(', ', $this->errors));
            }

            // Mettre à jour le statut dans la table de synchronisation
            $orderSync = new ShopifyOrderSync($this->db);
            $orderSync->fetchAll('', '', 1, 0, "fk_commande:=:" . $dolibarrOrderId);

            if (isset($orderSync) && count($orderSync) > 0) {
                $orderSync = reset($orderSync);
                $orderSync->dolOrderFulfillment = $shopifyStatuses['fulfillment_status'];
                $orderSync->update($GLOBALS['user']);
            }

            $this->db->commit();
            return 1;
        } catch (Exception $e) {
            $this->db->rollback();
            $this->errors[] = $e->getMessage();
            $this->log("Erreur lors de la mise à jour du statut: " . $e->getMessage(), LOG_ERR);
            return -1;
        } finally {
            // Ne retirer le flag que si c'est NOUS qui l'avons posé (pas un appelant externe)
            if (!$flagAlreadySet) {
                unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);
            }
        }
    }

    /**
     * Récupère l'ID Shopify associé à une commande Dolibarr
     * 
     * @param int $dolibarrOrderId ID de la commande Dolibarr
     * @return string|null ID Shopify ou null si non trouvé
     */
    private function getShopifyOrderId($dolibarrOrderId)
    {
        $sql = "SELECT shopifyOrderId FROM " . MAIN_DB_PREFIX . "doli2shop_orders";
        $sql .= " WHERE fk_commande = " . (int)$dolibarrOrderId . " AND entity = " . (int)$this->entity;
        
        $resql = SqlUtils::executeQuery($this->db, $sql, "getting Shopify Order ID for Dolibarr order", false);
        
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return $obj->shopifyOrderId;
        } elseif ($resql) {
            $this->db->free($resql);
        }
        
        return null;
    }

    /**
     * Crée une commande dans Shopify à partir d'une commande Dolibarr
     * 
     * @param Commande $order Commande Dolibarr
     * @return int <0 si erreur, >0 si succès
     */
    private function createShopifyOrder($order)
    {
        $this->log("Création d'une nouvelle commande Shopify à partir de la commande Dolibarr ID " . $order->id, LOG_INFO);
        
        try {
            // Récupérer les données du client
            $client = new Societe($this->db);
            $client->fetch($order->socid);
            
            if ($client->id <= 0) {
                throw new Exception("Client introuvable pour la commande Dolibarr ID " . $order->id);
            }
            
            // Préparer les données pour l'API
            $orderData = $this->prepareOrderData($order, $client);
            
            // Appel à l'API Shopify
            $response = $this->shopifyApi->createOrder($orderData);
            
            if (empty($response) || isset($response['errors']) || isset($response['userErrors'])) {
                $errorMsg = "Erreur lors de la création de la commande Shopify: ";
                if (isset($response['errors'])) {
                    $errorMsg .= implode(', ', $response['errors']);
                } elseif (isset($response['userErrors'])) {
                    $errorMsg .= implode(', ', array_map(function($e) { 
                        return $e['message']; 
                    }, $response['userErrors']));
                } else {
                    $errorMsg .= "Réponse vide de l'API";
                }
                throw new Exception($errorMsg);
            }
            
            // Extraire l'ID de la commande créée
            $shopifyOrderId = $this->extractShopifyId($response['order']['id']);
            
            // Enregistrer la relation dans la table de synchronisation
            $orderSync = new ShopifyOrderSync($this->db);
            $orderSync->fk_commande = $order->id;
            $orderSync->shopifyOrderId = $shopifyOrderId;
            
            // Récupérer le statut d'exécution
            $dolibarrStatus = $order->statut;
            $shopifyStatuses = $this->orderStatusMapper->mapDolibarrToShopify($dolibarrStatus);
            $orderSync->dolOrderFulfillment = $shopifyStatuses['fulfillment_status'];
            
            // Ajouter l'entité
            $orderSync->entity = $this->entity;
            
            $result = $orderSync->create($GLOBALS['user']);
            
            if ($result <= 0) {
                throw new Exception("Erreur lors de l'enregistrement de la relation commande: " . $orderSync->error);
            }
            
            $this->log("Commande Shopify créée avec succès. ID: " . $shopifyOrderId, LOG_INFO);
            
            return 1;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log("Erreur lors de la création de la commande Shopify: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Met à jour une commande Shopify existante à partir d'une commande Dolibarr
     * 
     * @param Commande $order Commande Dolibarr
     * @param string $shopifyOrderId ID de la commande Shopify
     * @return int <0 si erreur, >0 si succès
     */
    private function updateShopifyOrder($order, $shopifyOrderId)
    {
        $this->log("Mise à jour de la commande Shopify ID " . $shopifyOrderId . " à partir de la commande Dolibarr ID " . $order->id, LOG_INFO);
        
        try {
            // Récupérer les données du client
            $client = new Societe($this->db);
            $client->fetch($order->socid);
            
            if ($client->id <= 0) {
                throw new Exception("Client introuvable pour la commande Dolibarr ID " . $order->id);
            }
            
            // Préparer les données pour l'API
            $orderData = $this->prepareOrderData($order, $client);
            
            // Formater l'ID pour l'API GraphQL
            $graphqlOrderId = 'gid://shopify/Order/' . $shopifyOrderId;
            
            // Appel à l'API Shopify
            $response = $this->shopifyApi->updateOrder($graphqlOrderId, $orderData);
            
            if (empty($response) || isset($response['errors']) || isset($response['userErrors'])) {
                $errorMsg = "Erreur lors de la mise à jour de la commande Shopify: ";
                if (isset($response['errors'])) {
                    $errorMsg .= implode(', ', $response['errors']);
                } elseif (isset($response['userErrors'])) {
                    $errorMsg .= implode(', ', array_map(function($e) { 
                        return $e['message']; 
                    }, $response['userErrors']));
                } else {
                    $errorMsg .= "Réponse vide de l'API";
                }
                throw new Exception($errorMsg);
            }
            
            // Mettre à jour les statuts spécifiques
            $shopifyStatuses = $this->orderStatusMapper->mapDolibarrToShopify($order->statut);
            $this->updateShopifyOrderStatuses($graphqlOrderId, $shopifyStatuses);
            
            // Mettre à jour la table de synchronisation
            $orderSync = new ShopifyOrderSync($this->db);
            $orderSync->fetchAll('', '', 1, 0, "fk_commande:=:" . $order->id . " AND shopifyOrderId:=:" . $shopifyOrderId);
            
            if (isset($orderSync) && count($orderSync) > 0) {
                $orderSync = reset($orderSync);
                $orderSync->dolOrderFulfillment = $shopifyStatuses['fulfillment_status'];
                $orderSync->update($GLOBALS['user']);
            }
            
            $this->log("Commande Shopify mise à jour avec succès. ID: " . $shopifyOrderId, LOG_INFO);
            
            return 1;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log("Erreur lors de la mise à jour de la commande Shopify: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Met à jour les statuts spécifiques d'une commande Shopify
     * 
     * @param string $graphqlOrderId ID GraphQL de la commande Shopify
     * @param array $shopifyStatuses Statuts Shopify (financial_status, fulfillment_status)
     * @return int <0 si erreur, >0 si succès
     */
    private function updateShopifyOrderStatuses($graphqlOrderId, $shopifyStatuses)
    {
        $this->log("Mise à jour des statuts de la commande Shopify ID " . $graphqlOrderId, LOG_DEBUG);
        
        try {
            // Mettre à jour le statut financier si nécessaire
            if (!empty($shopifyStatuses['financial_status']) && $shopifyStatuses['financial_status'] === 'PAID') {
                $response = $this->shopifyApi->markOrderAsPaid($graphqlOrderId);
                
                if (empty($response) || isset($response['errors']) || isset($response['userErrors'])) {
                    $this->log("Avertissement: Échec de la mise à jour du statut financier", LOG_WARNING);
                    // Continuer même si échec
                }
            }
            
            // Mettre à jour le statut d'exécution si nécessaire
            if (!empty($shopifyStatuses['fulfillment_status']) && 
                in_array($shopifyStatuses['fulfillment_status'], ['FULFILLED', 'PARTIALLY_FULFILLED'])) {
                
                // Récupérer les lignes de commande pour la création du fulfillment
                $orderLines = $this->getShopifyOrderLineItems($graphqlOrderId);
                
                if (!empty($orderLines)) {
                    // Préparer les données pour le fulfillment
                    $lineItems = [];
                    foreach ($orderLines as $line) {
                        $lineItems[] = [
                            'id' => $line['id'],
                            'quantity' => $line['quantity']
                        ];
                    }
                    
                    // Créer le fulfillment
                    $response = $this->shopifyApi->createFulfillment($graphqlOrderId, $lineItems);
                    
                    if (empty($response) || isset($response['errors']) || isset($response['userErrors'])) {
                        $this->log("Avertissement: Échec de la création du fulfillment", LOG_WARNING);
                        // Continuer même si échec
                    }
                }
            }
            
            return 1;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log("Erreur lors de la mise à jour des statuts: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Récupère les lignes d'une commande Shopify
     * 
     * @param string $graphqlOrderId ID GraphQL de la commande Shopify
     * @return array Lignes de commande
     */
    private function getShopifyOrderLineItems($graphqlOrderId)
    {
        $query = '
        query getOrderLineItems($id: ID!) {
            order(id: $id) {
                lineItems(first: 50) {
                    edges {
                        node {
                            id
                            quantity
                            fulfillableQuantity
                        }
                    }
                }
            }
        }';
        
        $response = $this->shopifyApi->executeGraphQL($query, ['id' => $graphqlOrderId]);
        
        if (isset($response['order']) && isset($response['order']['lineItems']) && isset($response['order']['lineItems']['edges'])) {
            $lineItems = [];
            foreach ($response['order']['lineItems']['edges'] as $edge) {
                $lineItems[] = $edge['node'];
            }
            return $lineItems;
        }
        
        return [];
    }

    /**
     * Prépare les données d'une commande pour l'API Shopify
     * 
     * @param Commande $order Commande Dolibarr
     * @param Societe $client Client Dolibarr
     * @return array Données formatées pour l'API Shopify
     */
    private function prepareOrderData($order, $client)
    {
        // Données de base
        $orderData = [
            'customerId' => $this->getOrCreateShopifyCustomer($client),
            'tags' => 'Dolibarr, ID:' . $order->id,
            'note' => $order->note_private,
            'email' => $client->email,
            'phone' => $client->phone,
            'test' => false
        ];
        
        // Ajouter les adresses
        $orderData = array_merge($orderData, $this->prepareAddressData($order));
        
        // Ajouter les lignes
        $orderData['lineItems'] = $this->prepareLineItems($order);
        
        // Statuts associés
        $shopifyStatuses = $this->orderStatusMapper->mapDolibarrToShopify($order->statut);
        
        // Ajouter le statut financier
        if (!empty($shopifyStatuses['financial_status'])) {
            $orderData['displayFinancialStatus'] = $shopifyStatuses['financial_status'];
        }
        
        // Le statut d'exécution sera géré séparément
        
        return $orderData;
    }

    /**
     * Prépare les données d'adresse à partir d'une commande
     * 
     * @param Commande $order Commande Dolibarr
     * @return array Données d'adresse formatées
     */
    private function prepareAddressData($order)
    {
        $addressData = [];
        
        // Adresse de livraison
        if (!empty($order->shipping_address) || !empty($order->shipping_zip) || !empty($order->shipping_town)) {
            $addressData['shippingAddress'] = [
                'address1' => $order->shipping_address ?: '',
                'address2' => '',
                'city' => $order->shipping_town ?: '',
                'province' => $order->shipping_state ?: '',
                'zip' => $order->shipping_zip ?: '',
                'phone' => $order->shipping_phone ?: '',
                'countryCode' => $order->shipping_country_code ?: 'FR'
            ];
        }
        
        // Adresse de facturation
        if (!empty($order->billing_address) || !empty($order->billing_zip) || !empty($order->billing_town)) {
            $addressData['billingAddress'] = [
                'address1' => $order->billing_address ?: '',
                'address2' => '',
                'city' => $order->billing_town ?: '',
                'province' => $order->billing_state ?: '',
                'zip' => $order->billing_zip ?: '',
                'phone' => $order->billing_phone ?: '',
                'countryCode' => $order->billing_country_code ?: 'FR'
            ];
        }
        
        return $addressData;
    }

    /**
     * Prépare les lignes de commande pour l'API Shopify
     * 
     * @param Commande $order Commande Dolibarr
     * @return array Lignes formatées
     */
    private function prepareLineItems($order)
    {
        $lineItems = [];
        
        // Récupérer les lignes de la commande
        $order->fetch_lines();
        
        foreach ($order->lines as $line) {
            // Ignorer les lignes spéciales (remises, etc.)
            if ($line->special_code === 3) {
                continue;
            }
            
            // Récupérer le variant Shopify correspondant
            $variantId = $this->getShopifyVariantId($line->fk_product);
            
            // Formater la ligne
            $lineItem = [
                'quantity' => $line->qty,
                'originalUnitPrice' => $line->subprice
            ];
            
            // Si on a un variant, l'utiliser
            if ($variantId) {
                $lineItem['variantId'] = $variantId;
            } else {
                // Sinon, utiliser un produit personnalisé
                $lineItem['title'] = $line->desc ?: $line->product_label ?: 'Produit';
                $lineItem['price'] = $line->subprice;
                $lineItem['requiresShipping'] = true;
                $lineItem['taxable'] = $line->tva_tx > 0;
            }
            
            // Appliquer la remise si nécessaire
            if (!empty($line->remise_percent) && $line->remise_percent > 0) {
                $lineItem['appliedDiscount'] = [
                    'valueType' => 'PERCENTAGE',
                    'value' => $line->remise_percent
                ];
            }
            
            $lineItems[] = $lineItem;
        }
        
        return $lineItems;
    }

    /**
     * Récupère ou crée un client Shopify à partir d'un client Dolibarr
     * 
     * @param Societe $client Client Dolibarr
     * @return string ID GraphQL du client Shopify
     */
    private function getOrCreateShopifyCustomer($client)
    {
        // Rechercher le client par email dans Shopify
        $query = '
        query getCustomerByEmail($email: String!) {
            customers(first: 1, query: $email) {
                edges {
                    node {
                        id
                        email
                    }
                }
            }
        }';
        
        $response = $this->shopifyApi->executeGraphQL($query, ['email' => $client->email]);
        
        // Si le client existe, utiliser son ID
        if (isset($response['customers']) && 
            isset($response['customers']['edges']) && 
            !empty($response['customers']['edges'])) {
            return $response['customers']['edges'][0]['node']['id'];
        }
        
        // Sinon, créer un nouveau client
        $mutation = '
        mutation customerCreate($input: CustomerInput!) {
            customerCreate(input: $input) {
                customer {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }';
        
        $customerData = [
            'email' => $client->email,
            'firstName' => $client->name ?: 'Client',
            'lastName' => '',
            'phone' => $client->phone,
        ];
        
        $response = $this->shopifyApi->executeGraphQL($mutation, ['input' => $customerData]);
        
        if (isset($response['customerCreate']) && 
            isset($response['customerCreate']['customer']) && 
            isset($response['customerCreate']['customer']['id'])) {
            return $response['customerCreate']['customer']['id'];
        }
        
        // En cas d'échec, retourner null (Shopify créera un client anonyme)
        return null;
    }

    /**
     * Récupère l'ID du variant Shopify correspondant à un produit Dolibarr
     * 
     * @param int $dolibarrProductId ID du produit Dolibarr
     * @return string|null ID GraphQL du variant Shopify
     */
    private function getShopifyVariantId($dolibarrProductId)
    {
        if (empty($dolibarrProductId)) {
            return null;
        }
        
        $sql = "SELECT DISTINCT dp.shopify_variant_id
                FROM " . MAIN_DB_PREFIX . "doli2shop_products AS dp
                WHERE dp.fk_product = " . (int)$dolibarrProductId . "
                AND dp.entity = " . (int)$this->entity;
        
        $resql = SqlUtils::executeQuery($this->db, $sql, "getting Shopify variant ID", false);
        
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            
            if (!empty($obj->shopify_variant_id)) {
                // Formater l'ID pour l'API GraphQL
                return 'gid://shopify/ProductVariant/' . $obj->shopify_variant_id;
            }
        } elseif ($resql) {
            $this->db->free($resql);
        }
        
        return null;
    }

    /**
     * Extrait l'ID numérique de Shopify à partir d'un ID GraphQL
     * 
     * @param string $graphqlId ID GraphQL (gid://shopify/Order/12345678)
     * @return string|null ID numérique (12345678)
     */
    private function extractShopifyId($graphqlId)
    {
        if (preg_match('/gid:\/\/shopify\/Order\/(\d+)/', $graphqlId, $matches)) {
            return $matches[1];
        }
        return null;
    }
}