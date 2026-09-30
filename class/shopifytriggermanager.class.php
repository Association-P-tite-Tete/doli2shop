<?php
/**
 * Class for managing Dolibarr triggers for Shopify integration
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
dol_include_once('/core/triggers/dolibarrtriggers.class.php');
require_once dirname(__FILE__) . '/syncutils.class.php';
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/ordersynctoshopify.class.php';
require_once dirname(__FILE__) . '/importproducts.class.php';
require_once dirname(__FILE__) . '/shopifystocktrigger.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Class ShopifyTriggerManager
 * Gère les triggers Dolibarr pour la synchronisation avec Shopify
 *
 * Anti-boucle flags (Story 1.5) :
 * ---------------------------------------------------------------
 * | Flag                                          | Posé par                    | Vérifié par           |
 * |-----------------------------------------------|-----------------------------|-----------------------|
 * | SHOPIFY_INTEGRATION_PROCESSING_WEBHOOK        | OrderWebhookHandler         | manageTriggers()      |
 * | SHOPIFY_INTEGRATION_PROCESSING_PRODUCT_WEBHOOK| ProductWebhookHandler       | handleProductEvent()  |
 * | SHOPIFY_INTEGRATION_PROCESSING_INVENTORY_WEBHOOK| InventoryWebhookHandler   | ShopifyStockTrigger   |
 * | SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS          | OrderSyncToShopify,         | manageTriggers(),     |
 * |                                               | CRONs (webhooks, catchup,   | tous les handlers     |
 * |                                               | historical import)          |                       |
 * ---------------------------------------------------------------
 * Tous les flags sont des variables $conf->global->* (in-memory, même processus PHP uniquement).
 * Tous les flags DOIVENT être dans un bloc try/finally pour éviter les flags orphelins.
 */
class ShopifyTriggerManager
{
    /**
     * Logger statique pour l'utilisation dans la méthode statique
     *
     * @param string $message Message à logger
     * @param int $level Niveau de log
     * @return void
     */
    private static function logMessage($message, $level = LOG_INFO)
    {
        $prefix = 'ShopifyTriggerManager';
        dol_syslog($prefix . '::' . $message, $level);
    }

    /**
     * Factory statique protégée SyncFlowPolicy (Story 53-3, AC2 — équivalent testable de la
     * factory protégée d'instance des autres classes touchées ; ShopifyTriggerManager est
     * intégralement statique, pas d'instance à mocker).
     *
     * @param  DoliDB $db Gestionnaire de base de données
     * @return SyncFlowPolicy
     */
    protected static function createSyncFlowPolicy($db)
    {
        return new SyncFlowPolicy($db);
    }

    /**
     * Gestionnaire d'événements pour les triggers Dolibarr
     * 
     * @param string $action Action du trigger
     * @param object $object Objet concerné
     * @param User $user Utilisateur déclencheur
     * @param Translate $langs Traductions
     * @param Conf $conf Configuration
     * @return int 0 pour continuer, 1 pour remplacer, -1 pour annuler
     */
    public static function manageTriggers($action, $object, $user, $langs, $conf)
    {
        // Ne pas traiter si déjà en cours de traitement webhook ou synchronisation
        if (getDolGlobalInt('SHOPIFY_INTEGRATION_PROCESSING_WEBHOOK') ||
            getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS')) {
            self::logMessage("manageTriggers - Anti-boucle: skip trigger " . $action . " (webhook ou sync en cours)", LOG_DEBUG);
            return 0;
        }
        
        self::logMessage("Traitement du trigger: " . $action, LOG_DEBUG);
        
        // Traiter selon le type d'événement
        switch ($action) {
            // Commandes
            case 'ORDER_CREATE':
            case 'ORDER_VALIDATE':
            case 'ORDER_MODIFY':
            case 'ORDER_CLASSIFY_BILLED':
            case 'ORDER_CLOSE':
            case 'ORDER_CANCEL':
                return self::handleOrderEvent($action, $object, $user, $langs, $conf);
                
            // Produits
            case 'PRODUCT_CREATE':
            case 'PRODUCT_MODIFY':
            case 'PRODUCT_DELETE':
            case 'PRODUCT_VALIDATE':
            case 'PRODUCT_UNVALIDATE':
                return self::handleProductEvent($action, $object, $user, $langs, $conf);
                
            // Expédition
            case 'SHIPPING_VALIDATE':
                return self::handleShippingEvent($action, $object, $user, $langs, $conf);
                
            // Paiement
            case 'PAYMENT_CUSTOMER_CREATE':
                return self::handlePaymentEvent($action, $object, $user, $langs, $conf);

            // Stock (Story 52-1) : push Dolibarr → Shopify sur tout mouvement de stock
            case 'STOCK_MOVEMENT':
                return self::handleStockEvent($action, $object, $user, $langs, $conf);

            default:
                return 0;
        }
    }
    
    /**
     * Gère les événements liés aux commandes
     * 
     * @param string $action Action du trigger
     * @param Commande $object Objet commande
     * @param User $user Utilisateur déclencheur
     * @param Translate $langs Traductions
     * @param Conf $conf Configuration
     * @return int 0 pour continuer
     */
    private static function handleOrderEvent($action, $object, $user, $langs, $conf)
    {
        global $db;
        
        // Vérifier que l'objet est bien une commande
        if (!is_object($object) || !isset($object->table_element) || $object->table_element !== 'commande') {
            self::logMessage("L'objet n'est pas une commande: " . get_class($object), LOG_WARNING);
            return 0;
        }
        
        // Story 53-3 (site 3a) : garde SyncFlowPolicy (flux order_status, d2s ; statique donc
        // storeId=0 — pas de boutique connue à ce point du trigger Dolibarr).
        $policy = static::createSyncFlowPolicy($db);
        if (!$policy->isAllowed(SyncFlowPolicy::FLOW_ORDER_STATUS, 'dolibarr_to_shopify', 0)) {
            self::logMessage("Synchronisation Dolibarr → Shopify désactivée pour les commandes", LOG_INFO);
            return 0;
        }

        self::logMessage("Synchronisation de la commande ID " . $object->id . " suite à l'action " . $action, LOG_INFO);
        
        try {
            // Créer l'objet de synchronisation
            $orderSync = new OrderSyncToShopify($db);
            
            // Pour certaines actions, synchroniser uniquement le statut
            $statusOnlyActions = ['ORDER_VALIDATE', 'ORDER_CLASSIFY_BILLED', 'ORDER_CLOSE', 'ORDER_CANCEL'];
            
            if (in_array($action, $statusOnlyActions)) {
                $result = $orderSync->updateOrderStatus($object->id, $object->statut);
            } else {
                // Pour les autres actions, synchroniser toute la commande
                $result = $orderSync->syncOrderToShopify($object->id);
            }
            
            if ($result < 0) {
                self::logMessage("Erreur lors de la synchronisation: " . implode(', ', $orderSync->errors), LOG_ERR);
            } else {
                self::logMessage("Synchronisation réussie de la commande ID " . $object->id, LOG_INFO);
            }
        } catch (Exception $e) {
            self::logMessage("Exception lors de la synchronisation: " . $e->getMessage(), LOG_ERR);
        }
        
        // Ne jamais interrompre le processus Dolibarr
        return 0;
    }
    
    /**
     * Gère les événements liés aux produits
     * 
     * @param string $action Action du trigger
     * @param Product $object Objet produit
     * @param User $user Utilisateur déclencheur
     * @param Translate $langs Traductions
     * @param Conf $conf Configuration
     * @return int 0 pour continuer
     */
    private static function handleProductEvent($action, $object, $user, $langs, $conf)
    {
        global $db;
        
        // Vérifier que l'objet est bien un produit
        if (!is_object($object) || !isset($object->table_element) || $object->table_element !== 'product') {
            self::logMessage("L'objet n'est pas un produit: " . get_class($object), LOG_WARNING);
            return 0;
        }
        
        // Ne pas traiter si en cours de traitement webhook
        if (getDolGlobalInt('SHOPIFY_INTEGRATION_PROCESSING_PRODUCT_WEBHOOK')) {
            self::logMessage("Traitement webhook en cours, ignorant trigger produit", LOG_DEBUG);
            return 0;
        }
        
        // Vérifier direction de synchronisation
        $syncUtils = new SyncUtils($db);
        if (!$syncUtils->isProductSyncToShopifyEnabled()) {
            self::logMessage("Synchronisation Dolibarr → Shopify désactivée pour les produits", LOG_INFO);
            return 0;
        }
        
        // v2.2.0: La synchronisation produit temps réel via trigger est désactivée.
        // Les produits sont synchronisés via le CRON export (ImportProductsCron).
        // Le trigger PRODUCT_CREATE/MODIFY dans le contexte web causerait un timeout
        // car ImportProducts fait des appels API Shopify bloquants.
        // TODO v3.0.0: Implémenter une file d'attente async pour la sync produit temps réel.
        self::logMessage("Trigger produit " . $action . " pour ID " . $object->id
            . " — sync déléguée au CRON export (pas de sync temps réel pour les produits)", LOG_DEBUG);
        
        // Ne jamais interrompre le processus Dolibarr
        return 0;
    }
    
    /**
     * Gère les événements liés aux expéditions
     * 
     * @param string $action Action du trigger
     * @param Expedition $object Objet expédition
     * @param User $user Utilisateur déclencheur
     * @param Translate $langs Traductions
     * @param Conf $conf Configuration
     * @return int 0 pour continuer
     */
    private static function handleShippingEvent($action, $object, $user, $langs, $conf)
    {
        global $db;
        
        // Vérifier que l'objet est bien une expédition
        if (!is_object($object) || !isset($object->table_element) || $object->table_element !== 'expedition') {
            return 0;
        }
        
        // Story 53-3 (site 3b) : garde SyncFlowPolicy (flux shipping, d2s ; storeId=0, statique).
        $policy = static::createSyncFlowPolicy($db);
        if (!$policy->isAllowed(SyncFlowPolicy::FLOW_SHIPPING, 'dolibarr_to_shopify', 0)) {
            self::logMessage("Synchronisation Dolibarr → Shopify désactivée pour les expéditions", LOG_INFO);
            return 0;
        }
        
        // Récupérer l'ID de la commande associée
        $orderId = $object->origin_id;
        
        if (empty($orderId)) {
            self::logMessage("Expédition sans commande associée: ID " . $object->id, LOG_WARNING);
            return 0;
        }
        
        self::logMessage("Synchronisation de l'expédition pour la commande ID " . $orderId, LOG_INFO);
        
        try {
            // Récupérer la commande associée
            $order = new Commande($db);
            $result = $order->fetch($orderId);
            
            if ($result <= 0) {
                self::logMessage("Commande introuvable pour l'expédition: ID " . $orderId, LOG_WARNING);
                return 0;
            }
            
            // Déterminer le nouveau statut
            // NOTE: Dans Dolibarr, une expédition validée peut correspondre à
            // un statut "expédié" ou "partiellement expédié" pour la commande
            
            // Vérifier si toutes les lignes sont expédiées
            $allLinesShipped = self::checkAllLinesShipped($order);
            
            // Mettre à jour le statut en conséquence
            $orderSync = new OrderSyncToShopify($db);
            
            // Statut expédié ou partiellement expédié selon les lignes
            $newStatus = $allLinesShipped ? Commande::STATUS_CLOSED : Commande::STATUS_VALIDATED;
            
            $result = $orderSync->updateOrderStatus($orderId, $newStatus);
            
            if ($result < 0) {
                self::logMessage("Erreur lors de la synchronisation de l'expédition: " . implode(', ', $orderSync->errors), LOG_ERR);
            } else {
                self::logMessage("Synchronisation réussie de l'expédition pour la commande ID " . $orderId, LOG_INFO);
            }
        } catch (Exception $e) {
            self::logMessage("Exception lors de la synchronisation de l'expédition: " . $e->getMessage(), LOG_ERR);
        }
        
        return 0;
    }
    
    /**
     * Gère les événements liés aux paiements
     * 
     * @param string $action Action du trigger
     * @param Paiement $object Objet paiement
     * @param User $user Utilisateur déclencheur
     * @param Translate $langs Traductions
     * @param Conf $conf Configuration
     * @return int 0 pour continuer
     */
    private static function handlePaymentEvent($action, $object, $user, $langs, $conf)
    {
        global $db;
        
        // Vérifier que l'objet est bien un paiement
        if (!is_object($object) || !isset($object->table_element) || $object->table_element !== 'paiement') {
            return 0;
        }
        
        // Story 53-3 (site 3c) : garde SyncFlowPolicy (flux order_payment, d2s ; storeId=0, statique).
        $policy = static::createSyncFlowPolicy($db);
        if (!$policy->isAllowed(SyncFlowPolicy::FLOW_ORDER_PAYMENT, 'dolibarr_to_shopify', 0)) {
            self::logMessage("Synchronisation Dolibarr → Shopify désactivée pour les paiements", LOG_INFO);
            return 0;
        }
        
        // Récupérer les commandes liées à ce paiement
        $object->fetchAmounts();
        
        if (empty($object->amounts) || !is_array($object->amounts)) {
            self::logMessage("Paiement sans montants associés: ID " . $object->id, LOG_WARNING);
            return 0;
        }
        
        // Pour chaque facture payée, vérifier les commandes associées
        foreach (array_keys($object->amounts) as $invoiceId) {
            $sql = "SELECT fk_commande FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int)$invoiceId;
            $resql = $db->query($sql);
            
            if ($resql && $db->num_rows($resql) > 0) {
                $obj = $db->fetch_object($resql);
                $orderId = $obj->fk_commande;
                
                if (!empty($orderId)) {
                    self::logMessage("Synchronisation du paiement pour la commande ID " . $orderId, LOG_INFO);
                    
                    try {
                        // Mettre à jour le statut "payé" dans Shopify
                        $orderSync = new OrderSyncToShopify($db);
                        $result = $orderSync->updateOrderStatus($orderId, Commande::STATUS_VALIDATED);
                        
                        if ($result < 0) {
                            self::logMessage("Erreur lors de la synchronisation du paiement: " . implode(', ', $orderSync->errors), LOG_ERR);
                        } else {
                            self::logMessage("Synchronisation réussie du paiement pour la commande ID " . $orderId, LOG_INFO);
                        }
                    } catch (Exception $e) {
                        self::logMessage("Exception lors de la synchronisation du paiement: " . $e->getMessage(), LOG_ERR);
                    }
                }
            }
            
            if ($resql) {
                $db->free($resql);
            }
        }
        
        return 0;
    }
    
    /**
     * Gère les événements liés aux mouvements de stock (push Dolibarr → Shopify)
     *
     * Story 52-1 : deuxième point de branchement (le premier est le filtre de préfixe de
     * interface_99_modDoli2Shop_Doli2ShopTriggers::runTrigger()). $object est ici toujours
     * l'instance MouvementStock elle-même (seul appelant de call_trigger('STOCK_MOVEMENT', ...)
     * dans Dolibarr core), avec product_id fiable car assigné avant l'appel du trigger dans
     * MouvementStock::_create().
     *
     * @param string         $action Action du trigger (STOCK_MOVEMENT)
     * @param MouvementStock $object Objet mouvement de stock
     * @param User           $user   Utilisateur déclencheur
     * @param Translate      $langs  Traductions
     * @param Conf           $conf   Configuration
     * @return int 0 pour continuer (ne jamais interrompre le processus Dolibarr)
     */
    private static function handleStockEvent($action, $object, $user, $langs, $conf)
    {
        global $db;

        // Vérifier que l'objet est bien exploitable comme mouvement de stock
        if (!is_object($object) || (!isset($object->product_id) && !isset($object->fk_product))) {
            self::logMessage(
                "L'objet n'est pas un mouvement de stock exploitable: " . (is_object($object) ? get_class($object) : gettype($object)),
                LOG_WARNING
            );
            return 0;
        }

        // Vérifier direction de synchronisation (garde de niveau routeur, cohérente avec les
        // autres handlers ; ShopifyStockTrigger::runTrigger() revérifie la direction (désormais via
        // SyncFlowPolicy également, Story 53-3 site 5) ainsi que les gardes anti-boucle fines :
        // origin_type=shopify_sync, flags PROCESSING_INVENTORY_WEBHOOK/SYNC_IN_PROGRESS)
        $policy = static::createSyncFlowPolicy($db);
        if (!$policy->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'dolibarr_to_shopify', 0)) {
            self::logMessage("Synchronisation Dolibarr → Shopify désactivée pour les stocks", LOG_DEBUG);
            return 0;
        }

        try {
            $stockTrigger = new ShopifyStockTrigger($db);

            // Piège ordre des paramètres (Dev Notes story 52-1) : ShopifyStockTrigger::runTrigger()
            // attend ($action, $object, $user, $conf, $langs) — $conf/$langs inversés par rapport
            // aux handlers de manageTriggers() qui reçoivent ($action, $object, $user, $langs, $conf).
            // Transmission explicite dans le bon ordre pour ne pas propager l'inversion.
            $result = $stockTrigger->runTrigger($action, $object, $user, $conf, $langs);

            if ($result < 0) {
                self::logMessage("Erreur lors de la synchronisation du stock: " . implode(', ', $stockTrigger->errors), LOG_ERR);
            }
        } catch (Exception $e) {
            self::logMessage("Exception lors de la synchronisation du stock: " . $e->getMessage(), LOG_ERR);
        } catch (Error $e) {
            self::logMessage("Fatal error lors de la synchronisation du stock: " . $e->getMessage(), LOG_ERR);
        }

        // Ne jamais interrompre le processus Dolibarr
        return 0;
    }

    /**
     * Vérifie si toutes les lignes d'une commande sont expédiées
     *
     * @param Commande $order Commande à vérifier
     * @return bool True si toutes les lignes sont expédiées
     */
    private static function checkAllLinesShipped($order)
    {
        // Récupérer les lignes de la commande
        $order->fetch_lines();
        
        if (empty($order->lines)) {
            return false;
        }
        
        // Vérifier chaque ligne de la commande
        foreach ($order->lines as $line) {
            // Ignorer les lignes de type service (non expédiables)
            if ($line->product_type == 1) {
                continue;
            }
            
            // Récupérer la quantité expédiée
            $qtyShipped = $line->qty_shipped;
            
            // Si au moins une ligne n'est pas complètement expédiée, retourner false
            if ($qtyShipped < $line->qty) {
                return false;
            }
        }
        
        // Toutes les lignes sont expédiées
        return true;
    }
}