<?php

/**
 * @file        class/shopifyordermanager.class.php
 * @brief This file contains the ShopifyOrderManager class
 * @note Shopify Order Manager class for handling order synchronization
 *
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @version     2.6.0
 * @since       2.0.0
 * @author      P'tite Tête <doli2shop@ptitetete.com>
 * @copyright   2022-2025 Robert Steinbacher<robert .steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/core/lib/admin.lib.php'); // FIX #152 v2.1.2: Nécessaire pour dolibarr_set_const/get_const
dol_include_once('/core/lib/company.lib.php');
dol_include_once('/societe/class/societe.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/contact/class/contact.class.php'); // v2.1.5: Pour système contacts/adresses
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/configurationMigrator.class.php';
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
require_once dirname(__FILE__) . '/actionlogger.class.php'; // Story 38.3 : journal d'actions module
dol_include_once('/doli2shop/class/shopifyapi.class.php');
dol_include_once('/doli2shop/class/shopifyordersync.class.php');
dol_include_once('/compta/facture/class/facture.class.php'); // v2.2.0: Story 2.2 — Auto-création facture brouillon
dol_include_once('/expedition/class/expedition.class.php'); // v2.2.0: Story 2.3 — Auto-création bon d'expédition
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php'; // v2.2.0: Story 7.3 — PII masking helpers
require_once dirname(__FILE__) . '/storeservice.class.php';         // Epic 47, Story 47-5: tag catégories boutique
require_once dirname(__FILE__) . '/storecategoryhelper.class.php';  // Epic 47, Story 47-5: tag catégories boutique

class ShopifyOrderManager
{
    use LoggerTrait;

    /**
     * Préfixe stable du message d'exception levé par checkUserPermissions() quand l'utilisateur
     * manque de droits Dolibarr standard. Exposé en constante pour que les appelants (ex.
     * API REST Doli2Shop::instantiateOrderManagerOrFail) mappent proprement en 403 sans se
     * coupler à un libellé exact (review 54-2, MEDIUM-3).
     */
    const ERR_INSUFFICIENT_RIGHTS_PREFIX = 'Droits insuffisants';

    /** @var DoliDB */
    private $db;

    /** @var ShopifyApi */
    private $shopifyApi;

    /** @var array */
    private $config;

    /** @var array */
    private $conf;

    /** @var int */
    private $entity;

    /** @var string */
    public $error;

    /** @var array */
    public $errors = array();

    /**
     * Raison du dernier refus SILENCIEUX (return 0) de createOrder(), lue par
     * processSingleOrderFromWebhook() puis par les appelants (OrderWebhookHandler notamment) pour
     * distinguer un refus métier explicite d'un simple doublon/course concurrente — AC3, story
     * reglage-commandes-non-payees-ignore-par-les-webhooks. Remis à null en tête de chaque appel à
     * processSingleOrderFromWebhook() (l'instance est réutilisée en boucle par le rattrapage et
     * l'import historique — ne jamais laisser une valeur d'un tour précédent fuiter au suivant).
     * Valeurs connues : 'unpaid' (réglage SYNC_NON_PAID_ORDERS désactivé + commande non payée,
     * displayFinancialStatus reconnu du vocabulaire Shopify) ; 'unpaid_status_unknown' (MEDIUM-1,
     * Code Review du 09/09/2026 — même refus fail-closed, mais displayFinancialStatus absent, vide,
     * ou d'une valeur Shopify jamais documentée : signale une anomalie API à diagnostiquer, pas un
     * simple statut « non payé »).
     *
     * @var string|null
     */
    public $lastSkipReason = null;

    /**
     * Résultat du dernier contrôle de cohérence totaux Shopify/Dolibarr effectué par createOrder()
     * pour la commande en cours — story
     * ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (AC1/AC3/AC5). À la différence
     * de $lastSkipReason, un écart de totaux NE bloque PAS l'import (AC5 : la commande est importée
     * quand même, avec sa marque persistée) — il ne s'agit donc jamais d'un « skip ».
     *
     * Remis à null en tête de chaque appel à processSingleOrderFromWebhook() (même raison que
     * $lastSkipReason : l'instance est réutilisée en boucle par le rattrapage et l'import
     * historique). Lu par processSingleOrderFromWebhook() juste après createOrder() pour reporter
     * les valeurs sur le ShopifyOrderSync persisté (llx_doli2shop_orders).
     *
     * null si aucun écart détecté (delta <= 0,01 €, seuil de détection historique INCHANGÉ) ou si
     * `totalPriceSet` était absent de la réponse Shopify. Sinon, tableau :
     *   - shopifyTotal   float  Total TTC annoncé par Shopify (totalPriceSet.shopMoney.amount)
     *   - dolibarrTotal  float  Total TTC calculé par Dolibarr (Commande::total_ttc)
     *   - diff           float  shopifyTotal - dolibarrTotal (signé)
     *   - relativeDiff   float  abs(diff) / référence (>= 0)
     *   - severity       string 'rounding' (quelques centimes, tolérable) ou 'proportional' (un
     *                            facteur constant, signature d'une erreur de calcul systématique —
     *                            AC3) — cf. classifyTotalsMismatch()
     *
     * @var array{shopifyTotal: float, dolibarrTotal: float, diff: float, relativeDiff: float, severity: string}|null
     */
    public $lastTotalsMismatch = null;

    /** @var StoreSettings|null Accesseur réglages per-store (Story 49-4) */
    private $storeSettings = null;

    public function __construct($db, $entity = null, $store = null)
    {
        global $conf;
        $this->db = $db;
        $this->conf = $GLOBALS['conf'];

        // Determine entity to use
        if ($entity !== null) {
            $useEntity = (int)$entity;
        } else {
            $useEntity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;
        }

        $this->entity = $useEntity;
        $storeLabel = ($store !== null) ? ($store->shop_domain ?? 'store#' . ($store->rowid ?? '?')) : 'null';
        $this->log("Using entity: " . $useEntity . " store=" . $storeLabel, LOG_INFO);

        // Create ShopifyApi with explicit entity and optional store (Epic 47-4)
        $this->shopifyApi = new ShopifyApi($db, $useEntity, $store);

        // Check if Shopify API is properly initialized
        if (!empty($this->shopifyApi->error)) {
            $this->error = "Failed to initialize Shopify API: " . $this->shopifyApi->error;
            $this->errors[] = $this->error;
            $this->log("Error: " . $this->error, LOG_ERR);
            throw new Exception($this->error);
        }

        // Get configuration from ShopifyApi
        $this->config = $this->shopifyApi->getConfig();

        if (empty($this->config)) {
            $this->error = "Failed to load configuration from ShopifyApi";
            $this->errors[] = $this->error;
            $this->log("Error: " . $this->error, LOG_ERR);
            throw new Exception($this->error);
        }
        
        // Préchargement cache per-store (Story 49-4 — évite N+1 SQL par commande)
        require_once dirname(__FILE__) . '/storesettings.class.php';
        $this->storeSettings = new StoreSettings($this->db);
        $this->storeSettings->getAllForStore($this->getStoreId()); // précharge cache boutique

        // NOUVELLE FONCTION v2.0.30: Vérification des droits utilisateur
        $this->checkUserPermissions();
    }

    /**
     * Retourne le rowid de la boutique courante (0 si chemin historique entité/constantes).
     *
     * Délègue à ShopifyApi::getStoreId() — source unique de vérité du contexte boutique.
     *
     * @return int rowid boutique (0 = mono-boutique/constantes, > 0 = boutique identifiée)
     */
    public function getStoreId(): int
    {
        return ($this->shopifyApi !== null) ? $this->shopifyApi->getStoreId() : 0;
    }

    /**
     * NOUVELLE FONCTION v2.0.30: Vérification des droits utilisateur CRON
     *
     * Vérifie que l'utilisateur actuel possède tous les droits nécessaires
     * pour effectuer les opérations de synchronisation des commandes.
     *
     * @throws Exception Si des droits manquent
     */
    private function checkUserPermissions()
    {
        global $user;

        // Si l'utilisateur n'est pas chargé (contexte webhook), charger l'utilisateur CRON
        if (empty($user->id)) {
            $cronUserId = getDolGlobalInt('DOLI2SHOP_WEBHOOK_USER_ID', getDolGlobalInt('MAIN_CRON_USERID', 0));
            if ($cronUserId > 0) {
                $user->fetch($cronUserId);
                $user->getRights();
                $this->log("checkUserPermissions - Loaded CRON user ID=" . $cronUserId . " (" . $user->login . ")", LOG_INFO);
            }
        }

        // v2.2.0: Forcer les droits facture en mémoire pour le contexte CRON/webhook
        // Le flux complet order→invoice→payment nécessite ces droits. On les force en mémoire
        // (pas en BDD) pour éviter que l'admin doive les configurer manuellement.
        if (!empty($user->id) && isModEnabled('facture')) {
            if (!isset($user->rights->facture)) {
                $user->rights->facture = new \stdClass();
            }
            if (empty($user->rights->facture->creer)) {
                $user->rights->facture->creer = 1;
                $this->log("checkUserPermissions - Forced facture->creer in memory for CRON user", LOG_DEBUG);
            }
            if (empty($user->rights->facture->valider)) {
                $user->rights->facture->valider = 1;
                $this->log("checkUserPermissions - Forced facture->valider in memory for CRON user", LOG_DEBUG);
            }
            if (empty($user->rights->facture->lire)) {
                $user->rights->facture->lire = 1;
            }
        }

        $this->log("=== VÉRIFICATION DROITS UTILISATEUR CRON ===", LOG_INFO);
        $this->log("User ID: " . $user->id . " (" . $user->login . ")", LOG_INFO);
        $this->log("Entity active: " . $this->entity, LOG_INFO);
        $this->log("Admin: " . ($user->admin ? "OUI" : "NON"), LOG_INFO);

        // Liste des droits requis
        $requiredRights = [
            'societe->creer' => 'Création société',
            'societe->lire' => 'Lecture société',
            'commande->creer' => 'Création commande',
            'commande->lire' => 'Lecture commande',
            'produit->lire' => 'Lecture produit',
            'facture->creer' => 'Création facture',
            'facture->valider' => 'Validation facture',
        ];
        
        $missingRights = [];
        foreach ($requiredRights as $rightPath => $label) {
            list($module, $action) = explode('->', $rightPath);
            $hasRight = false;
            
            // Vérification via hasRight() (API recommandée Dolibarr 18+, chemin nominal)
            if (method_exists($user, 'hasRight')) {
                $hasRight = $user->hasRight($module, $action);
            } elseif (isset($user->rights->$module->$action)) {
                // Fallback défensif documenté : Dolibarr < 17 (pré-hasRight()). Hors cible 19+,
                // conservé pour robustesse. Accès direct $user->rights-> volontaire ici.
                $hasRight = (bool)$user->rights->$module->$action;
            }
            
            $this->log("Droit " . $label . ": " . ($hasRight ? "✓ OUI" : "✗ NON"), LOG_INFO);
            
            if (!$hasRight) {
                $missingRights[] = $label;
            }
        }
        
        // Si droits manquants, lever une exception
        if (!empty($missingRights)) {
            $errorMsg = self::ERR_INSUFFICIENT_RIGHTS_PREFIX . " pour l'utilisateur CRON (ID: " . $user->id . "): " . implode(', ', $missingRights);
            $this->log($errorMsg, LOG_ERR);
            throw new Exception($errorMsg);
        }
        
        $this->log("✓ Tous les droits requis sont présents pour l'utilisateur " . $user->login, LOG_INFO);
        $this->log("=== FIN VÉRIFICATION DROITS ===", LOG_INFO);
    }

    // v2.2.0 Epic 14: Ancien flux import (syncOrders, executeSyncLogic, etc.) supprime
    // Nouveau flux: processSingleOrderFromWebhook() via webhooks + CRONs

    /**
     * Vérifie si une commande existe déjà dans la table de synchronisation OU dans llx_commande
     *
     * v2.1.7-fix: Double vérification pour éviter les doublons si table sync est vide/purgée
     * 1. Vérifie dans llx_doli2shop_orders par shopifyOrderId
     * 2. Si non trouvé, vérifie dans llx_commande par ref_client (PREFIXE_#NUMERO)
     *
     * @param string $shopifyOrderId ID Shopify de la commande
     * @param string $shopifyOrderName Numéro de commande Shopify (#1234) pour vérification ref_client
     * @param string $context Contexte pour le log (optionnel)
     * @return bool
     */
    private function orderExists($shopifyOrderId, $shopifyOrderName = '', $context = 'sync')
    {
        // 1. Vérification dans la table de synchronisation (existant)
        // Epic 47-4 : filtre fk_store conditionnel (getStoreId()>0 = multi-boutique)
        // getStoreId()==0 → pas de filtre (legacy/fallback : lignes backfillées, pas de doublon)
        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "doli2shop_orders";
        $sql .= " WHERE shopifyOrderId = '" . $this->db->escape($shopifyOrderId) . "'";
        $sql .= " AND entity = " . (int)$this->entity;
        if ($this->shopifyApi !== null && $this->shopifyApi->getStoreId() > 0) {
            $sql .= " AND fk_store = " . (int) $this->shopifyApi->getStoreId();
        }

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log("Erreur SQL vérification existence commande (sync table): " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($result);
        $existsInSync = ($obj && $obj->nb > 0);

        if ($existsInSync) {
            $contextLabel = $context === 'historical' ? 'import historique' : 'sync normale';
            $this->log("Commande Shopify $shopifyOrderId déjà présente dans table sync, ignorée ($contextLabel)", LOG_DEBUG);
            return true;
        }

        // 2. v2.1.7-fix: Vérification dans llx_commande par ref_client si shopifyOrderName fourni
        if (!empty($shopifyOrderName)) {
            $dolibarrOrderId = $this->orderExistsInDolibarrByRefClient($shopifyOrderName);
            if ($dolibarrOrderId !== false) {
                $contextLabel = $context === 'historical' ? 'import historique' : 'sync normale';
                $this->log("Commande Shopify $shopifyOrderName déjà présente dans llx_commande (ID: $dolibarrOrderId), ignorée ($contextLabel)", LOG_INFO);

                // v2.1.7-fix: Reconstruire l'entrée dans la table sync pour maintenir la cohérence
                $this->rebuildSyncEntry($dolibarrOrderId, $shopifyOrderId);

                return true;
            }
        }

        return false;
    }

    /**
     * v2.1.7-fix: Vérifie si une commande existe déjà dans llx_commande par ref_client
     *
     * Recherche par pattern: ref_client contient le numéro Shopify (#1234)
     * Compatible avec préfixe et suffixe configurés
     *
     * @param string $shopifyOrderName Numéro de commande Shopify (ex: "#1234")
     * @return int|false ID de la commande Dolibarr si trouvée, false sinon
     */
    private function orderExistsInDolibarrByRefClient($shopifyOrderName)
    {
        if (empty($shopifyOrderName)) {
            return false;
        }

        // Recherche par LIKE pour être compatible avec tout préfixe/suffixe
        // Le numéro Shopify (#1234) est unique et suffit pour identifier la commande
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "commande";
        $sql .= " WHERE ref_client LIKE '%" . $this->db->escape($shopifyOrderName) . "%'";
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log("Erreur SQL vérification existence commande (llx_commande): " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($result);
        if ($obj && $obj->rowid > 0) {
            $this->log("Commande trouvée dans llx_commande (ID: {$obj->rowid}) avec ref_client contenant '$shopifyOrderName'", LOG_DEBUG);
            return (int)$obj->rowid;
        }

        return false;
    }

    // v2.2.0 Epic 14: getLastShopifyOrderDateFromDolibarr() supprimee (ancien flux)

    /**
     * v2.1.7-fix: Reconstruit l'entrée dans la table de sync si commande existe dans llx_commande
     *
     * Appelé quand une commande est trouvée dans llx_commande mais pas dans llx_doli2shop_orders
     * Permet de maintenir la cohérence de la table de synchronisation
     *
     * @param int $dolibarrOrderId ID de la commande Dolibarr
     * @param string $shopifyOrderId ID Shopify de la commande
     * @param string $fulfillmentStatus Statut fulfillment (optionnel)
     * @return bool Succès ou échec
     */
    private function rebuildSyncEntry($dolibarrOrderId, $shopifyOrderId, $fulfillmentStatus = 'UNFULFILLED')
    {
        global $user;

        try {
            $orderSync = new ShopifyOrderSync($this->db);
            $orderSync->fk_commande = $dolibarrOrderId;
            $orderSync->shopifyOrderId = $shopifyOrderId;
            $orderSync->dolOrderFulfillment = $fulfillmentStatus;
            $orderSync->entity = $this->entity;
            // Epic 47-4 : fk_store conditionnel (getStoreId()=0 → chemin historique, pas de filtre)
            $orderSync->fk_store = ($this->shopifyApi !== null) ? (int) $this->shopifyApi->getStoreId() : 0;

            if ($orderSync->create($user) > 0) {
                $this->log("Entrée sync reconstruite pour commande Dolibarr ID: $dolibarrOrderId, Shopify ID: $shopifyOrderId", LOG_INFO);
                return true;
            } else {
                $this->log("Échec reconstruction entrée sync: " . $orderSync->error, LOG_WARNING);
                return false;
            }
        } catch (Exception $e) {
            $this->log("Exception reconstruction entrée sync: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }
    
    // v2.2.0 Epic 14: orderExistsInSync, markHistoricalImportCompleted,
    // saveHistoricalImportResumePoint supprimees (ancien flux)

    /**
     * Correspondance des noms de pays vers les codes ISO
     *
     *
     * @param string $countryName Nom du pays
     * @return string Code ISO ou entrée originale si non trouvé
     */
    private function mapCountryNameToCode($countryName)
    {
        $countryMap = [
            // UE / EEE + principaux marchés (#284 : couverture élargie pour Colissimo & co.)
            'FRANCE' =>  'FR',
            'BELGIUM' =>  'BE',
            'GERMANY' =>  'DE',
            'ITALY' =>  'IT',
            'SPAIN' =>  'ES',
            'NETHERLANDS' =>  'NL',
            'LUXEMBOURG' =>  'LU',
            'PORTUGAL' =>  'PT',
            'AUSTRIA' =>  'AT',
            'SWITZERLAND' =>  'CH',
            'IRELAND' =>  'IE',
            'DENMARK' =>  'DK',
            'SWEDEN' =>  'SE',
            'FINLAND' =>  'FI',
            'NORWAY' =>  'NO',
            'POLAND' =>  'PL',
            'CZECH REPUBLIC' =>  'CZ',
            'CZECHIA' =>  'CZ',
            'GREECE' =>  'GR',
            'HUNGARY' =>  'HU',
            'ROMANIA' =>  'RO',
            'BULGARIA' =>  'BG',
            'CROATIA' =>  'HR',
            'SLOVAKIA' =>  'SK',
            'SLOVENIA' =>  'SI',
            'ESTONIA' =>  'EE',
            'LATVIA' =>  'LV',
            'LITHUANIA' =>  'LT',
            'CYPRUS' =>  'CY',
            'MALTA' =>  'MT',
            'UNITED KINGDOM' =>  'GB',
            'UNITED STATES' =>  'US',
            'CANADA' =>  'CA',
            // Ajouter d'autres mappages selon besoin
        ];

        $upperName = strtoupper(trim($countryName));
        return $countryMap[$upperName] ?? $countryName;
    }

    private function createOrUpdateCustomer($shopifyCustomer)
    {
        global $user;
        $this->log("Creating or updating customer: " . (isset($shopifyCustomer->email) ? doli2shop_mask_email($shopifyCustomer->email) : 'unknown'), LOG_DEBUG);

        // Advisory lock pour empêcher la création concurrente du même client
        $customerEmail = isset($shopifyCustomer->email) ? $shopifyCustomer->email : '';
        $lockName = 'doli2shop_customer_' . $this->entity . '_' . md5($customerEmail);
        $lockResult = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lockName) . "', 10) as locked");
        $lockObj = $this->db->fetch_object($lockResult);
        if (!$lockObj || $lockObj->locked != 1) {
            $this->log("createOrUpdateCustomer - Cannot acquire lock for customer " . doli2shop_mask_email($customerEmail) . ", another process is creating it", LOG_WARNING);
            return -1;
        }

        try {
            $soc = new Societe($this->db);

            // Recherche par email vérifié avec filtre entité
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE email = ? AND entity = ?";
            $resql = SqlUtils::executeQuery($this->db, $sql, "checking customer email", false, [$shopifyCustomer->email, $this->entity]);

            if ($resql && $this->db->num_rows($resql) > 0) {
                $this->log("Client existant trouvé par email dans entity " . $this->entity, LOG_DEBUG);
                $obj = $this->db->fetch_object($resql);
                $fetchResult = $soc->fetch($obj->rowid);
                if ($fetchResult <= 0) {
                    $this->log("Erreur fetch société ID " . $obj->rowid . ": " . $soc->error, LOG_ERR);
                    throw new Exception("Impossible de charger la société ID " . $obj->rowid);
                }
                $this->log("Client chargé avec succès: ID=" . $soc->id . ", Email=" . doli2shop_mask_email($soc->email) . ", Entity=" . $this->entity, LOG_DEBUG);
                $this->db->free($resql);
            } elseif ($resql) {
                $this->log("Aucun client existant trouvé pour email " . doli2shop_mask_email($shopifyCustomer->email) . " dans entity " . $this->entity, LOG_DEBUG);
                $this->db->free($resql);
            } else {
                $this->log("Erreur SQL lors recherche client: " . $this->db->lasterror(), LOG_ERR);
            }

            // Mise à jour des données
            // CORRECTION v2.1.5: Priorité au champ "company" si fourni par le client
            // v2.1.6-fix: Ignorer company si c'est juste une civilité
            $civilityTerms = array('madame', 'mme', 'mme.', 'monsieur', 'mr', 'mr.', 'm.', 'mademoiselle', 'mlle', 'mlle.', 'ms', 'ms.', 'mrs', 'mrs.');
            $companyValue = !empty($shopifyCustomer->defaultAddress->company) ? trim($shopifyCustomer->defaultAddress->company) : '';
            $companyIsJustCivility = in_array(strtolower($companyValue), $civilityTerms);

            if (!empty($companyValue) && !$companyIsJustCivility) {
                // Priorité 1 : Company si fourni ET pas juste une civilité
                $soc->name = $companyValue;
                $this->log("Client créé/màj avec company: " . doli2shop_mask_name($soc->name) . " (ID Shopify: {$shopifyCustomer->id})", LOG_INFO);
            } else {
                if ($companyIsJustCivility) {
                    $this->log("Company ignoré car civilité détectée: '$companyValue'", LOG_INFO);
                }
                // Fallback : firstName + lastName (en nettoyant les civilités)
                $firstName = trim($shopifyCustomer->firstName);
                $lastName = trim($shopifyCustomer->lastName);

                // Nettoyer civilités du prénom/nom
                if (in_array(strtolower($firstName), $civilityTerms)) {
                    $this->log("Civilité détectée dans firstName: '$firstName' - ignorée", LOG_INFO);
                    $firstName = '';
                }
                if (in_array(strtolower($lastName), $civilityTerms)) {
                    $this->log("Civilité détectée dans lastName: '$lastName' - ignorée", LOG_INFO);
                    $lastName = '';
                }

                $soc->name = trim($firstName . ' ' . $lastName);

                // Si le nom est vide après nettoyage, utiliser l'email
                if (empty($soc->name)) {
                    $soc->name = $shopifyCustomer->email;
                    $this->log("Nom vide après nettoyage civilités, utilisation email: " . doli2shop_mask_email($soc->name), LOG_WARNING);
                } else {
                    $this->log("Client créé/màj avec firstName+lastName: " . doli2shop_mask_name($soc->name) . " (ID Shopify: {$shopifyCustomer->id})", LOG_DEBUG);
                }
            }

            // v2.2.0: Auto-positionnement type de tiers B2B/B2C
            $hasValidCompany = !empty($companyValue) && !$companyIsJustCivility;
            $typentWithCompany = $this->storeSettings->getInt($this->getStoreId(), 'TYPENT_WITH_COMPANY', 0);
            $typentWithoutCompany = $this->storeSettings->getInt($this->getStoreId(), 'TYPENT_WITHOUT_COMPANY', 0);
            $configTypentId = $hasValidCompany ? $typentWithCompany : $typentWithoutCompany;

            if ($configTypentId > 0 && $soc->id <= 0) {
                $soc->typent_id = $configTypentId;
                $this->log("createOrUpdateCustomer - Customer type set to typent_id=" . $configTypentId
                    . " (" . ($hasValidCompany ? "company detected: " . doli2shop_mask_name($companyValue) : "no company") . ")", LOG_INFO);
            } elseif ($configTypentId > 0 && $soc->id > 0) {
                $this->log("createOrUpdateCustomer - Existing customer, skipping typent_id assignment (current typent_id=" . $soc->typent_id . ")", LOG_DEBUG);
            }

            $soc->email = $shopifyCustomer->email;
            $soc->phone = $shopifyCustomer->phone;
            $soc->client = 1;

            // CORRECTION v2.0.31: Génération adaptative code client selon modèle Dolibarr
            $this->setClientCode($soc, $shopifyCustomer);

            if ($shopifyCustomer->defaultAddress) {
                $soc->address = implode("\n", array_filter([
                    $shopifyCustomer->defaultAddress->address1,
                    $shopifyCustomer->defaultAddress->address2
                ]));
                $soc->zip = $shopifyCustomer->defaultAddress->zip;
                $soc->town = $shopifyCustomer->defaultAddress->city;
                $soc->state = $shopifyCustomer->defaultAddress->province;
                $soc->phone = $shopifyCustomer->defaultAddress->phone;

                // Gestion du pays avec getCountry()
                $countryCode = isset($shopifyCustomer->defaultAddress->countryCodeV2) ?
                    $shopifyCustomer->defaultAddress->countryCodeV2 :
                    $countryCode = isset($shopifyCustomer->defaultAddress->countryCodeV2) ?
                    $shopifyCustomer->defaultAddress->countryCodeV2 :
                    $this->mapCountryNameToCode($shopifyCustomer->defaultAddress->country);
                $countryInfo = getCountry($countryCode, 'all', $this->db);

                if (is_array($countryInfo)) {
                    $soc->country_id = $countryInfo['id'];
                    $soc->country_code = $countryInfo['code'];
                } else {
                    $this->log("Pays non trouvé pour le code: " . $countryCode, LOG_WARNING);
                    $soc->country_id = 0;
                    $soc->country_code = $countryCode;
                }
            }

            // FIX v2.0.36: Mapping consentement marketing Shopify → Dolibarr natif "Refuser les e-mails de masse"
            // Shopify marketingState: SUBSCRIBED, UNSUBSCRIBED, NOT_SUBSCRIBED, PENDING, etc.
            if (isset($shopifyCustomer->emailMarketingConsent) && isset($shopifyCustomer->emailMarketingConsent->marketingState)) {
                $marketingState = $shopifyCustomer->emailMarketingConsent->marketingState;
                // Si client SUBSCRIBED = accepte emails (no_email = 0), sinon refuse emails (no_email = 1)
                $noEmail = ($marketingState === 'SUBSCRIBED') ? 0 : 1;
                $soc->setNoEmail($noEmail);
                $this->log("Consentement marketing Shopify: " . $marketingState . " → no_email=" . $noEmail, LOG_DEBUG);
            }
            // LOGS v2.0.30: Informations avant création/mise à jour
            global $user;
            $operation = ($soc->id > 0) ? "mise à jour" : "création";
            $this->log("Tentative " . $operation . " société: " . doli2shop_mask_name($soc->name) . " (email: " . doli2shop_mask_email($soc->email) . ")", LOG_INFO);
            $this->log("Contexte utilisateur: ID=" . $user->id . " (" . $user->login . "), Entity=" . $this->entity, LOG_DEBUG);
            
            // Création ou mise à jour
            if ($soc->id > 0) {
                // FIX v2.0.36: Contourner bug Dolibarr avec extrafields vides
                // L'erreur peut être une exception directe au lieu d'un code retour
                try {
                    $result = $soc->update(0, $user);

                    // Vérifier également si erreur dans $soc->error sans exception
                    if ($result < 0 && strpos($soc->error, 'llx_societe_extrafields SET') !== false && strpos($soc->error, 'WHERE fk_object') !== false) {
                        $this->log("Bug Dolibarr extrafields vides (code retour) ignoré pour société ID=" . $soc->id, LOG_WARNING);
                        $result = 1; // Considérer comme succès
                        $soc->error = ''; // Effacer l'erreur
                    }
                } catch (Exception $e) {
                    // Vérifier si c'est l'erreur SQL extrafields vides
                    $errorMsg = $e->getMessage();
                    if (strpos($errorMsg, 'llx_societe_extrafields SET') !== false && strpos($errorMsg, 'WHERE fk_object') !== false) {
                        $this->log("Bug Dolibarr extrafields vides (exception) ignoré pour société ID=" . $soc->id . " - Exception: " . $errorMsg, LOG_WARNING);
                        $result = 1; // Considérer comme succès car la société existe déjà
                    } else {
                        // Autre exception, la relancer
                        throw $e;
                    }
                }
            } else {
                $result = $soc->create($user);
            }

            if ($result < 0) {
                // LOGS v2.0.30: Messages d'erreur détaillés
                $errorMsg = $soc->error ?: 'Erreur inconnue';
                if (!empty($soc->errors)) {
                    $errorMsg .= ' - ' . implode(', ', $soc->errors);
                }
                $this->log("Échec " . $operation . " société: " . $errorMsg, LOG_ERR);
                $this->log("Données client: " . json_encode(array(
                    'nom' => doli2shop_mask_name($soc->name),
                    'email' => doli2shop_mask_email($soc->email),
                    'entity' => $this->entity,
                    'user_id' => $user->id,
                    'operation' => $operation
                )), LOG_DEBUG);
                
                throw new Exception("Erreur " . $operation . " société [Email: " . doli2shop_mask_email($shopifyCustomer->email) . ", Entity: " . $this->entity . "]: " . $errorMsg);
            } else {
                // LOGS v2.0.30: Succès avec détails
                $this->log("Succès " . $operation . " société: ID=" . $soc->id . ", Nom=" . doli2shop_mask_name($soc->name), LOG_INFO);

                // Attribution automatique de la catégorie client Shopify (si configurée)
                $customerCategoryId = isset($this->config->dolibarr_customer_category) ? $this->config->dolibarr_customer_category : '0';
                if (!empty($customerCategoryId) && $customerCategoryId !== '0') {
                    $this->log("Attribution catégorie client Shopify: ID=" . $customerCategoryId . " pour société ID=" . $soc->id, LOG_INFO);
                    $this->assignCustomerToCategory($soc->id, (int)$customerCategoryId);
                } else {
                    $this->log("Aucune catégorie client configurée (dolibarr_customer_category=" . $customerCategoryId . ")", LOG_DEBUG);
                }
            }

            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return $soc;
        } catch (Exception $e) {
            $this->log("Erreur: " . $e->getMessage(), LOG_ERR);
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return -1;
        }
    }

    /**
     * Assign customer to a specific category
     *
     * @param int $customerId Customer ID
     * @param int $categoryId Category ID
     * @return bool Success
     */
    private function assignCustomerToCategory($customerId, $categoryId)
    {
        try {
            // Vérifier si l'association existe déjà (table liaison sans id/rowid)
            $sql = "SELECT 1 FROM " . MAIN_DB_PREFIX . "categorie_societe
                    WHERE fk_categorie = ? AND fk_soc = ?";
            $result = SqlUtils::executeQuery($this->db, $sql, "checking category assignment", false, [$categoryId, $customerId]);

            if ($result && $this->db->num_rows($result) == 0) {
                // Créer l'association
                $sql_insert = "INSERT INTO " . MAIN_DB_PREFIX . "categorie_societe
                              (fk_categorie, fk_soc) VALUES (?, ?)";
                $insert_result = SqlUtils::executeQuery($this->db, $sql_insert, "assigning customer category", false, [$categoryId, $customerId]);

                if ($insert_result) {
                    $this->log("Client ID {$customerId} assigné à la catégorie {$categoryId}", LOG_INFO);
                    if ($result) $this->db->free($result);
                    return true;
                }
            } else {
                $this->log("Client ID {$customerId} déjà dans la catégorie {$categoryId}", LOG_DEBUG);
                if ($result) $this->db->free($result);
                return true;
            }

            if ($result) $this->db->free($result);
            $this->log("Échec assignation catégorie - condition non satisfaite pour client {$customerId}", LOG_WARNING);
            return false;

        } catch (Exception $e) {
            $this->log("Erreur assignation catégorie: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Détermine si une commande Shopify est importable en Dolibarr, compte tenu de son statut
     * financier et du réglage DOLI2SHOP_SYNC_NON_PAID_ORDERS.
     *
     * Fonction PURE (aucun I/O), extraite de createOrder() pour être testable unitairement sans
     * dépendance DB/réseau — story reglage-commandes-non-payees-ignore-par-les-webhooks, AC1/AC6,
     * réserve 4 du Validate (asymétrie de vocabulaire).
     *
     * Vocabulaire : $financialStatus DOIT être le displayFinancialStatus renvoyé par l'API GraphQL
     * (PENDING, AUTHORIZED, PARTIALLY_PAID, PAID, PARTIALLY_REFUNDED, REFUNDED, VOIDED, EXPIRED —
     * en MAJUSCULES), PAS le champ REST `financial_status` minuscule du payload webhook brut :
     * createOrder() ne reçoit JAMAIS ce dernier, ses données viennent toujours d'un fetch GraphQL
     * (buildSingleOrderQuery(), ou le node pré-fetché de l'import historique/rattrapage — même
     * requête). Le filtre `financial_status:'paid'` utilisé par les requêtes de LISTE
     * (ShopifyApi::getOrdersWithPagination(), fetchHistoricalOrdersBatch()) est un vocabulaire
     * DIFFÉRENT et plus étroit (recherche Shopify, n'inclut pas partially_refunded/refunded) —
     * sans conséquence ici : ce test revérifie indépendamment CHAQUE commande avec le vocabulaire
     * GraphQL réel, quel que soit le filtre de recherche qui l'a amenée jusqu'à ce choke point.
     * Test IDENTIQUE à celui qui déclenche le paiement plus bas dans createOrder() : "importable" et
     * "payée" restent le MÊME test partout dans ce fichier.
     *
     * @param  string $financialStatus   displayFinancialStatus Shopify (ex: 'PAID', 'PENDING')
     * @param  bool   $syncNonPaidOrders Valeur résolue du réglage DOLI2SHOP_SYNC_NON_PAID_ORDERS
     * @return bool                      true si la commande peut être importée
     */
    public static function isOrderImportAllowed($financialStatus, $syncNonPaidOrders)
    {
        if ($syncNonPaidOrders) {
            return true;
        }
        return in_array($financialStatus, ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true);
    }

    /**
     * Détermine si $financialStatus est une valeur displayFinancialStatus RECONNUE du
     * vocabulaire Shopify documenté, par opposition à une valeur absente, vide, ou jamais
     * documentée (anomalie API).
     *
     * Fonction PURE (aucun I/O) — extraite pour la même raison qu'isOrderImportAllowed() :
     * distinguer, dans la trace persistée (lastSkipReason) et à l'écran (admin/webhook_events.php),
     * le refus franc (« commande PENDING, réglage désactivé ») du cas où le statut financier est
     * indéterminé — MEDIUM-1, Code Review du 09/09/2026. Les deux cas restent fail-closed
     * (isOrderImportAllowed() refuse l'import dans les deux cas si le réglage est désactivé) :
     * seule la RAISON exposée change, jamais le comportement de refus lui-même.
     *
     * @param  string $financialStatus displayFinancialStatus Shopify (peut être '' ou absent)
     * @return bool                    true si valeur du vocabulaire Shopify documenté
     */
    public static function isFinancialStatusRecognized($financialStatus)
    {
        return in_array($financialStatus, [
            'PENDING', 'AUTHORIZED', 'PARTIALLY_PAID', 'PAID',
            'PARTIALLY_REFUNDED', 'REFUNDED', 'VOIDED', 'EXPIRED',
        ], true);
    }

    /**
     * Détermine si `taxesIncluded` est exploitable (booléen strict) dans la réponse GraphQL
     * Shopify d'une commande, par opposition à absent (champ non demandé sur un fragment oublié,
     * ancienne version d'API) ou d'un type inattendu (anomalie de réponse).
     *
     * Fonction PURE (aucun I/O) — story
     * prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes, AC3 : fail-closed sur
     * l'inconnu. `taxesIncluded` conditionne si les montants LineItem/ShippingLine fournis par
     * Shopify (originalUnitPriceSet, discountedUnitPriceSet, originalPriceSet, discountedPriceSet)
     * sont TTC ou HT — un champ absent ne doit JAMAIS être traité par défaut comme "TTC"
     * (c'est exactement l'hypothèse fausse que ce défaut avait figée dans le code) : il doit
     * REFUSER l'import plutôt que deviner.
     *
     * @param  \stdClass $shopifyOrder Commande Shopify brute (peut manquer de `taxesIncluded`)
     * @return bool                   true si `taxesIncluded` est un booléen exploitable
     */
    public static function isTaxesIncludedKnown($shopifyOrder)
    {
        return isset($shopifyOrder->taxesIncluded) && is_bool($shopifyOrder->taxesIncluded);
    }

    /**
     * Convertit un montant brut Shopify (LineItem/ShippingLine) en montant HT, selon le réglage
     * `taxes_included` réel de la boutique.
     *
     * Fonction PURE (aucun I/O) — story
     * prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes, AC1/AC2. AVANT ce correctif,
     * la division par (1 + taux) était INCONDITIONNELLE (le montant était toujours traité comme
     * TTC) — faux pour toute boutique réglée en prix hors taxes, qui voyait ses commandes
     * enregistrées à ~83% de leur valeur réelle (division par 1,20 à taux 20%).
     *
     * Precondition (garantie par l'appelant unique, createOrder()) : $taxesIncluded est un
     * booléen strict — le cas "inconnu" est refusé fail-closed en amont par
     * isTaxesIncludedKnown() (AC3), jamais deviné ici.
     *
     * @param  float $rawAmountFromShopify Montant Shopify brut (TTC si $taxesIncluded, HT sinon)
     * @param  float $taxRatePercent       Taux de TVA en pourcentage (ex. 20 pour 20%)
     * @param  bool  $taxesIncluded        true = $rawAmountFromShopify est TTC ; false = déjà HT
     * @return float                       Montant HT
     */
    public static function computeHtFromShopifyAmount($rawAmountFromShopify, $taxRatePercent, $taxesIncluded)
    {
        if ($taxesIncluded === true) {
            return $rawAmountFromShopify / (1 + ($taxRatePercent / 100));
        }
        // AC2 : taxesIncluded === false — le montant Shopify est déjà HT, utilisé tel quel.
        // Jamais l'inverse (ne JAMAIS multiplier ici pour "recomposer" un HT qui serait en
        // réalité déjà HT) — le TTC est recomposé par Dolibarr lui-même (OrderLine::insert() /
        // Commande::update_price()) à partir de ce HT et de $taxRatePercent.
        return (float) $rawAmountFromShopify;
    }

    /** Seuil de détection d'écart totaux Shopify/Dolibarr (v2.3.0, AC 39-1 #5) — INCHANGÉ par la
     * story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (consigne explicite : ne
     * pas modifier ce seuil, seulement lui donner un destinataire). */
    const TOTALS_MISMATCH_DETECTION_THRESHOLD = 0.01;

    /**
     * Seuil de déviation RELATIVE au-delà duquel un écart de totaux cesse d'être un simple écart
     * d'arrondi (quelques centimes, dont le poids RELATIF diminue avec la taille de la commande)
     * pour devenir un écart PROPORTIONNEL — un facteur constant appliqué au total entier, signature
     * d'une erreur de calcul systématique plutôt que du bruit d'arrondi. Story de référence :
     * prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (division par 1+taux appliquée
     * à un montant déjà HT → écart d'environ 17% sur CHAQUE commande de la boutique concernée).
     *
     * 5% est choisi nettement au-dessus du bruit d'arrondi habituel (quelques centimes sur un total
     * de plusieurs dizaines/centaines d'euros représentent une fraction de pourcent) et nettement en
     * dessous du plancher observé du défaut réel (~17%).
     */
    const TOTALS_MISMATCH_PROPORTIONAL_RATIO_THRESHOLD = 0.05;

    /**
     * Compare le total TTC annoncé par Shopify au total TTC calculé par Dolibarr et qualifie
     * l'écart, le cas échéant — story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal.
     *
     * Fonction PURE (aucun I/O), extraite du bloc existant depuis la v2.3.0 (AC 39-1 #5,
     * createOrder()) qui ne faisait QUE journaliser un WARNING — sans distinguer un écart d'arrondi
     * tolérable (AC3) d'un écart proportionnel (facteur constant sur le total, signature d'une
     * erreur de calcul systématique) et sans jamais persister ni remonter à l'écran (AC1/AC2).
     *
     * Classification (AC3) : un écart d'arrondi a un poids RELATIF qui diminue quand la commande
     * grossit (quelques centimes fixes) ; un écart proportionnel garde le MÊME poids relatif quelle
     * que soit la taille de la commande (un facteur constant, ex. diviser par 1,20 un montant déjà
     * HT). D'où la classification par déviation RELATIVE (relativeDiff), pas seulement par le delta
     * absolu — un écart de 0,50€ sur une commande de 8€ (6%) est proportionnel, le même 0,50€ sur
     * une commande de 800€ (0,06%) est un arrondi.
     *
     * @param  float $shopifyTotal  Total TTC annoncé par Shopify (totalPriceSet.shopMoney.amount)
     * @param  float $dolibarrTotal Total TTC calculé par Dolibarr (Commande::total_ttc)
     * @return array{shopifyTotal: float, dolibarrTotal: float, diff: float, relativeDiff: float, severity: string}|null
     *               null si aucun écart détecté (seuil de détection 0,01€ INCHANGÉ, AC 39-1 #5)
     */
    public static function classifyTotalsMismatch($shopifyTotal, $dolibarrTotal)
    {
        $shopifyTotal = (float) $shopifyTotal;
        $dolibarrTotal = (float) $dolibarrTotal;
        $diff = $shopifyTotal - $dolibarrTotal;

        if (abs($diff) <= self::TOTALS_MISMATCH_DETECTION_THRESHOLD) {
            return null;
        }

        // Référence pour le calcul relatif : le total Shopify (montant réellement encaissé,
        // le "meilleur juge disponible" cf. la story) — repli sur le total Dolibarr si le total
        // Shopify est nul (évite une division par zéro sans jamais deviner un total absent comme
        // "rounding" par défaut : un total Shopify nul avec un total Dolibarr non nul EST un écart
        // proportionnel par construction, relativeDiff = 1.0 dans ce cas).
        $reference = abs($shopifyTotal) > 0.0001 ? abs($shopifyTotal) : abs($dolibarrTotal);
        $relativeDiff = $reference > 0.0001 ? (abs($diff) / $reference) : 1.0;

        $severity = ($relativeDiff > self::TOTALS_MISMATCH_PROPORTIONAL_RATIO_THRESHOLD)
            ? 'proportional'
            : 'rounding';

        return array(
            'shopifyTotal' => $shopifyTotal,
            'dolibarrTotal' => $dolibarrTotal,
            'diff' => $diff,
            'relativeDiff' => $relativeDiff,
            'severity' => $severity,
        );
    }

    /**
     * Construit les 3 champs à reporter sur ShopifyOrderSync (llx_doli2shop_orders) à partir de
     * $lastTotalsMismatch — story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal,
     * AC1 (persistance). Fonction PURE, extraite pour être testable sans instancier
     * ShopifyOrderSync/DoliDB : ne fait qu'un mapping null-safe.
     *
     * @param  array{shopifyTotal: float, dolibarrTotal: float, diff: float, relativeDiff: float, severity: string}|null $lastTotalsMismatch
     * @return array{totals_mismatch_severity: string|null, shopify_total_ttc: float|null, dolibarr_total_ttc: float|null}
     */
    public static function buildOrderSyncTotalsMismatchFields($lastTotalsMismatch)
    {
        if (empty($lastTotalsMismatch)) {
            return array(
                'totals_mismatch_severity' => null,
                'shopify_total_ttc' => null,
                'dolibarr_total_ttc' => null,
            );
        }

        return array(
            'totals_mismatch_severity' => $lastTotalsMismatch['severity'],
            'shopify_total_ttc' => $lastTotalsMismatch['shopifyTotal'],
            'dolibarr_total_ttc' => $lastTotalsMismatch['dolibarrTotal'],
        );
    }

    /**
     * Détermine si un sourceName Shopify est un identifiant technique (purement numérique)
     * plutôt qu'un nom de canal lisible ('web', 'pos', 'mobile_app', 'shopify_draft_order'...).
     *
     * Fonction PURE (aucun I/O), extraite de createOrder() pour être testable unitairement —
     * story source-name-numerique-pollue-la-reference-client, AC1/AC5.
     *
     * Shopify documente sourceName comme le nom de canal lisible d'une commande ('web',
     * 'mobile_app', 'pos'...). Non documenté mais confirmé par la communauté : une commande
     * créée par une application tierce via l'API reçoit, à défaut de valeur explicite,
     * l'identifiant numérique de l'application — les valeurs lisibles étant réservées aux
     * canaux propres à Shopify (cf. Validate du 09/09/2026). Détection par la FORME
     * (ctype_digit), jamais par égalité à une valeur observée en test.
     *
     * @param  string $sourceName Valeur brute de shopifyOrder->sourceName (déjà non vide)
     * @return bool               true si purement numérique (identifiant technique)
     */
    public static function isSourceNameTechnical($sourceName)
    {
        return $sourceName !== '' && ctype_digit((string) $sourceName);
    }

    /**
     * Construit l'enrichissement de ref_client (référence marketplace externe ou source) et
     * l'éventuel ajout à note_private pour une commande Shopify.
     *
     * Fonction PURE (aucun I/O), extraite de createOrder() pour être testable unitairement sans
     * instancier Commande/DoliDB — story source-name-numerique-pollue-la-reference-client, AC5,
     * réserve du Validate du 09/09/2026 (les tests existants recopiaient cette logique au lieu
     * de l'appeler, et mordaient donc sur une copie, jamais sur le correctif réel).
     *
     * Priorité (AC2/AC3, inchangée) :
     *   1. externalOrderRef (customAttributes) si présent ;
     *   2. sinon sourceName, SAUF 'web'/'shopify_draft_order' (ignorés comme aujourd'hui) et
     *      SAUF forme technique (AC1 : va dans note_private, pas dans ref_client).
     *
     * @param  \stdClass $shopifyOrder Commande Shopify brute (sourceName, customAttributes)
     * @return array{refClientSuffix: string, notePrivateAddition: string, externalOrderRef: string, sourceName: string, sourceNameIsTechnical: bool}
     *               refClientSuffix      : à concaténer tel quel à ref_client (peut être vide)
     *               notePrivateAddition  : à concaténer tel quel à note_private (déjà préfixé
     *                                      par "\n" si non vide)
     */
    public static function buildRefClientSourceEnrichment($shopifyOrder)
    {
        // Les commandes marketplace (ex: Nature & Découvertes) ont des customAttributes avec la
        // référence externe du canal d'origine.
        $externalOrderRef = '';
        if (!empty($shopifyOrder->customAttributes)) {
            foreach ($shopifyOrder->customAttributes as $attr) {
                // Chercher les attributs courants pour les refs marketplace
                $key = is_object($attr) ? ($attr->key ?? '') : ($attr['key'] ?? '');
                $val = is_object($attr) ? ($attr->value ?? '') : ($attr['value'] ?? '');
                if (in_array(strtolower($key), ['order_id', 'external_id', 'marketplace_order_id', 'reference', 'po_number']) && !empty($val)) {
                    $externalOrderRef = $val;
                    break;
                }
            }
        }

        $sourceName = !empty($shopifyOrder->sourceName) ? $shopifyOrder->sourceName : '';
        $refClientSuffix = '';
        $notePrivateAddition = '';
        $sourceNameIsTechnical = false;

        if (!empty($externalOrderRef)) {
            $refClientSuffix = ' [' . $externalOrderRef . ']';
        } elseif (!empty($sourceName) && $sourceName !== 'web' && $sourceName !== 'shopify_draft_order') {
            if (self::isSourceNameTechnical($sourceName)) {
                $sourceNameIsTechnical = true;
                $notePrivateAddition = "\n[Shopify] Source: " . $sourceName;
            } else {
                $refClientSuffix = ' [' . $sourceName . ']';
            }
        }

        return array(
            'refClientSuffix' => $refClientSuffix,
            'notePrivateAddition' => $notePrivateAddition,
            'externalOrderRef' => $externalOrderRef,
            'sourceName' => $sourceName,
            'sourceNameIsTechnical' => $sourceNameIsTechnical,
        );
    }

    private function createOrder($shopifyOrder, $customerId)
    {
        global $user;
        $this->log("Creating order for Shopify order: " . $shopifyOrder->name, LOG_DEBUG);

        // Anti-doublon: advisory lock pour empêcher les créations concurrentes
        $shopifyOrderId = str_replace('gid://shopify/Order/', '', $shopifyOrder->id);
        $lockName = 'doli2shop_order_' . $this->entity . '_' . $shopifyOrderId;
        $lockResult = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($lockName) . "', 5) as locked");
        $lockObj = $this->db->fetch_object($lockResult);
        if (!$lockObj || $lockObj->locked != 1) {
            $this->log("createOrder - Cannot acquire lock for order " . $shopifyOrder->name . ", another process is creating it", LOG_WARNING);
            return -1;
        }

        // Double-check après lock: la commande a-t-elle été créée entre-temps ?
        if ($this->orderExists($shopifyOrderId, $shopifyOrder->name, 'webhook')) {
            $this->log("createOrder - Order " . $shopifyOrder->name . " already exists after lock (concurrent creation), skipping", LOG_INFO);
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return 0;
        }

        // v2.3.0: Guard commande annulée (AC 39-2 #1)
        // LOG_INFO (et non WARNING) : ignorer une commande déjà annulée est un comportement
        // normal. En polling, la commande peut repasser à chaque cycle tant qu'elle reste dans
        // la fenêtre temporelle — ne pas polluer les logs d'avertissements pour un cas nominal.
        if (!empty($shopifyOrder->cancelledAt)) {
            $this->log(
                'createOrder - Commande Shopify ' . $shopifyOrder->name . ' déjà annulée (cancelledAt: ' . $shopifyOrder->cancelledAt . ') — création Dolibarr ignorée',
                LOG_INFO
            );
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return 0;
        }

        // Story reglage-commandes-non-payees-ignore-par-les-webhooks (AC1/AC2/AC4) :
        // choke point unique — createOrder() n'est appelé QUE depuis processSingleOrderFromWebhook()
        // (:4653), lui-même le point d'entrée commun aux webhooks orders/create-updated-paid, au
        // rattrapage (catchupMissingOrders) et à l'import historique. Un refus ici borne donc les
        // TROIS chemins d'un coup, sans dupliquer le test ailleurs.
        // Ré-évalué à CHAQUE appel (jamais de cache de décision) : une commande refusée aujourd'hui
        // repasse ce même test demain (webhook orders/paid, rattrapage ou import) avec le
        // displayFinancialStatus À JOUR — AC4, aucun état "définitivement dehors" possible.
        $financialStatusForImportGate = $shopifyOrder->displayFinancialStatus ?? '';
        $syncNonPaidOrdersForImportGate = (bool) $this->storeSettings->getInt($this->getStoreId(), 'SYNC_NON_PAID_ORDERS', 0);
        if (!self::isOrderImportAllowed($financialStatusForImportGate, $syncNonPaidOrdersForImportGate)) {
            // MEDIUM-1 (Code Review du 09/09/2026) : le comportement de refus (fail-closed) est
            // IDENTIQUE dans les deux branches ci-dessous — seule la RAISON exposée (lastSkipReason,
            // puis lastActionResult/admin/webhook_events.php) change. Sans cette distinction,
            // displayFinancialStatus absent/vide/inconnu (anomalie API Shopify) et PENDING/AUTHORIZED
            // (statut normal mais non payé) remontaient identiquement « Non payée — ignorée »,
            // égarant le diagnostic support en cas d'anomalie réelle.
            if (self::isFinancialStatusRecognized($financialStatusForImportGate)) {
                $this->log(
                    'createOrder - Commande Shopify ' . $shopifyOrder->name . ' non payée '
                    . '(displayFinancialStatus=' . $financialStatusForImportGate . ') et réglage '
                    . 'DOLI2SHOP_SYNC_NON_PAID_ORDERS désactivé — import refusé (AC1)',
                    LOG_INFO
                );
                $this->lastSkipReason = 'unpaid';
            } else {
                $this->log(
                    'createOrder - Commande Shopify ' . $shopifyOrder->name . ' : displayFinancialStatus '
                    . 'indéterminé (absent, vide, ou valeur Shopify non documentée : "'
                    . $financialStatusForImportGate . '") et réglage DOLI2SHOP_SYNC_NON_PAID_ORDERS '
                    . 'désactivé — import refusé par prudence (fail-closed), à diagnostiquer '
                    . '(anomalie API possible)',
                    LOG_WARNING
                );
                $this->lastSkipReason = 'unpaid_status_unknown';
            }
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return 0;
        }

        // Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (AC3) : deuxième
        // choke point fail-closed, même emplacement/mêmes trois chemins que le gate financier
        // ci-dessus. `taxesIncluded` conditionne si les montants Shopify (lignes, frais de port,
        // pourboire, remise) sont TTC ou HT — un champ absent (ancienne version d'API, réponse
        // tronquée, fragment de requête oublié) ne doit JAMAIS être deviné comme "TTC par défaut"
        // (c'est exactement le défaut CRITICAL comptable que cette story corrige) : l'import est
        // refusé plutôt que de produire un montant silencieusement faux.
        if (!self::isTaxesIncludedKnown($shopifyOrder)) {
            $this->log(
                'createOrder - Commande Shopify ' . $shopifyOrder->name . ' : `taxesIncluded` '
                . 'absent ou de type invalide dans la réponse GraphQL — impossible de déterminer '
                . 'si les prix de ligne sont TTC ou HT — import refusé par prudence (fail-closed)',
                LOG_ERR
            );
            $this->lastSkipReason = 'taxes_included_unknown';
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return 0;
        }
        $taxesIncluded = (bool) $shopifyOrder->taxesIncluded;

        try {
            $order = new Commande($this->db);
            $order->socid = $customerId;

            // Référence avec préfixe
            $order->ref_client = $this->config->order_prefix . '_' . $shopifyOrder->name;

            // v2.2.0: Enrichir ref_client avec la référence marketplace externe si disponible
            // Les commandes marketplace (ex: Nature & Découvertes) ont des customAttributes
            // avec la référence externe du canal d'origine.
            // Story source-name-numerique-pollue-la-reference-client : la classification
            // (externalOrderRef prioritaire / sourceName technique / sourceName lisible) est
            // extraite dans buildRefClientSourceEnrichment() — fonction PURE testable sans
            // instancier Commande/DoliDB (réserve du Validate du 09/09/2026 : les tests
            // existants recopiaient cette logique au lieu de l'appeler).
            $sourceEnrichment = self::buildRefClientSourceEnrichment($shopifyOrder);
            $order->ref_client .= $sourceEnrichment['refClientSuffix'];
            if (!empty($sourceEnrichment['notePrivateAddition'])) {
                // AC1 : un sourceName purement numérique (identifiant technique d'une app
                // tierce) ne va pas dans ref_client (il serait identique pour toutes les
                // commandes de la même app) mais dans note_private, comme les autres infos de
                // provenance de cette fonction (devise étrangère, récap financier plus bas).
                if (empty($order->note_private)) {
                    $order->note_private = '';
                }
                $order->note_private .= $sourceEnrichment['notePrivateAddition'];
            }

            if (!empty($sourceEnrichment['externalOrderRef'])) {
                $this->log("createOrder - Ref marketplace externe détectée: " . $sourceEnrichment['externalOrderRef'] . " (source: " . $sourceEnrichment['sourceName'] . ")", LOG_INFO);
            } elseif ($sourceEnrichment['sourceNameIsTechnical']) {
                $this->log("createOrder - Commande source non-web (identifiant technique, hors ref_client): " . $sourceEnrichment['sourceName'], LOG_INFO);
            } elseif (!empty($sourceEnrichment['refClientSuffix'])) {
                // Ajouter le nom de la source pour traçabilité
                $this->log("createOrder - Commande source non-web: " . $sourceEnrichment['sourceName'], LOG_INFO);
            }

            // Dates
            $orderDate = strtotime($shopifyOrder->createdAt);
            $order->date = $orderDate;
            $order->date_creation = $orderDate;

            // Adresses
            $this->setAddresses($order, $shopifyOrder);

            // Configuration
            $order->cond_reglement_id = $this->config->payment_terms;
            $order->mode_reglement_id = $this->getPaymentMode($shopifyOrder);
            $order->fk_account = $this->config->order_bank_account;
            $order->demand_reason_id = $this->config->order_origin;

            // v2.3.0: Persistance devise étrangère (AC 39-2 #2)
            if (!empty($shopifyOrder->currencyCode) && $shopifyOrder->currencyCode !== 'EUR') {
                $this->log('createOrder - Devise étrangère détectée: ' . $shopifyOrder->currencyCode . ' pour ' . $shopifyOrder->name, LOG_INFO);
                if (empty($order->note_private)) {
                    $order->note_private = '';
                }
                $order->note_private .= "\n[Shopify] Devise: " . $shopifyOrder->currencyCode;
            }

            // Statut
            // $order->statut = $this->mapOrderStatus($shopifyOrder->displayFinancialStatus);
            $order->statut = Commande::STATUS_DRAFT;

            // Gestion des méthodes d'expédition
            foreach ($shopifyOrder->shippingLines->edges as $edge) {
                $shippingLine = $edge->node;
                $parsedShipping = $this->parseShippingTitle($shippingLine->title);
                $shippingMethod = $this->getDolibarrShippingMethod($parsedShipping);

                $order->shipping_method_id = $shippingMethod['id'];
                $order->warehouse_id = $this->config->default_warehouse_id;
                $this->setDeliveryDate($order, $orderDate, $shippingMethod['days']);

                break; // On prend la première méthode d'expédition
            }

            // Lignes de commande
            // Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (AC1/AC2) :
            // $taxesIncluded propagé depuis le gate ci-dessus, garanti booléen strict ici.
            $this->addOrderLines($order, $shopifyOrder->lineItems->edges, $taxesIncluded);
            $this->addShippingLines($order, $shopifyOrder->shippingLines->edges, $taxesIncluded);

            // Ajouter les remises globales si nécessaire
            $this->processDiscounts($order, $shopifyOrder, $taxesIncluded);

            // ====================================================================
            // v2.1.5 : DÉTECTION LIMITES GRAPHQL (Surveillance troncatures potentielles)
            // ====================================================================
            if (isset($shopifyOrder->lineItems->edges) && count($shopifyOrder->lineItems->edges) >= 40) {
                $this->log("[WARNING]  ATTENTION: Commande {$shopifyOrder->name} a " . count($shopifyOrder->lineItems->edges) .
                           " articles - Limite GraphQL atteinte (max 40). Certains articles peuvent être manquants.", LOG_WARNING);
            }

            if (isset($shopifyOrder->shippingLines->edges) && count($shopifyOrder->shippingLines->edges) >= 5) {
                $this->log("[WARNING]  ATTENTION: Commande {$shopifyOrder->name} a " . count($shopifyOrder->shippingLines->edges) .
                           " méthodes d'expédition - Limite GraphQL atteinte (max 5). Certaines méthodes peuvent être manquantes.", LOG_WARNING);
            }

            if (isset($shopifyOrder->discountApplications->edges) && count($shopifyOrder->discountApplications->edges) >= 10) {
                $this->log("[WARNING]  ATTENTION: Commande {$shopifyOrder->name} a " . count($shopifyOrder->discountApplications->edges) .
                           " remises - Limite GraphQL atteinte (max 10). Certaines remises peuvent être manquantes.", LOG_WARNING);
            }

            if (isset($shopifyOrder->transactions) && count($shopifyOrder->transactions) >= 5) {
                $this->log("[WARNING]  ATTENTION: Commande {$shopifyOrder->name} a " . count($shopifyOrder->transactions) .
                           " transactions - Limite GraphQL atteinte (max 5). Certaines transactions peuvent être manquantes.", LOG_WARNING);
            }

            // LOGS v2.0.30: Validation avant création
            if (!$customerId || $customerId <= 0) {
                throw new Exception(sprintf(
                    "ID tiers invalide (%s) pour création commande Shopify %s",
                    $customerId,
                    $shopifyOrder->name
                ));
            }

            // v2.3.0: Récap financier Shopify dans note_private (AC 39-1 #3)
            $noteFinancier = '';
            if (isset($shopifyOrder->subtotalPriceSet->shopMoney->amount)) {
                $noteFinancier .= '[Shopify] Sous-total: ' . number_format((float)$shopifyOrder->subtotalPriceSet->shopMoney->amount, 2) . $shopifyOrder->subtotalPriceSet->shopMoney->currencyCode;
            }
            if (isset($shopifyOrder->totalTaxSet->shopMoney->amount)) {
                $noteFinancier .= ' | Taxes: ' . number_format((float)$shopifyOrder->totalTaxSet->shopMoney->amount, 2) . $shopifyOrder->totalTaxSet->shopMoney->currencyCode;
            }
            if (isset($shopifyOrder->totalDiscountsSet->shopMoney->amount)) {
                $noteFinancier .= ' | Remises: ' . number_format((float)$shopifyOrder->totalDiscountsSet->shopMoney->amount, 2) . $shopifyOrder->totalDiscountsSet->shopMoney->currencyCode;
            }
            if (isset($shopifyOrder->totalPriceSet->shopMoney->amount)) {
                $noteFinancier .= ' | Total: ' . number_format((float)$shopifyOrder->totalPriceSet->shopMoney->amount, 2) . $shopifyOrder->totalPriceSet->shopMoney->currencyCode;
            }
            if (!empty($noteFinancier)) {
                if (empty($order->note_private)) {
                    $order->note_private = '';
                }
                $order->note_private .= "\n" . $noteFinancier;
            }

            $this->log(
                'Création commande Dolibarr pour Shopify #' . $shopifyOrder->name
                . (isset($shopifyOrder->orderNumber) ? ' (orderNumber: ' . (int)$shopifyOrder->orderNumber . ')' : '')
                . ' (tiers: ' . $customerId . ')',
                LOG_INFO
            );
            
            if ($order->create($user) < 0) {
                // MESSAGES v2.0.30: Messages d'erreur contextuels détaillés
                $errorDetails = [
                    'shopify_order' => $shopifyOrder->name,
                    'socid' => $order->socid,
                    'ref_client' => $order->ref_client,
                    'entity' => $this->entity,
                    'user_id' => $user->id,
                    'user_login' => $user->login,
                    'error' => $order->error ?: 'Erreur inconnue'
                ];
                
                if ($order->socid <= 0) {
                    throw new Exception("Impossible de créer la commande Shopify " . $shopifyOrder->name . " : ID tiers invalide (" . $order->socid . ")");
                } else {
                    throw new Exception("Échec création commande Shopify " . $shopifyOrder->name . " : " . $order->error . " [Tiers: " . $order->socid . ", Entity: " . $this->entity . "]");
                }
            }
            
            $this->log("Commande Dolibarr créée avec succès: ID=" . $order->id . " pour Shopify #" . $shopifyOrder->name, LOG_INFO);

            // v2.3.0: Vérification cohérence totaux Shopify vs Dolibarr (AC 39-1 #5)
            // Comparer TTC à TTC : totalPriceSet Shopify est TTC (boutiques B2C),
            // et $order->total_ttc est calculé par Dolibarr à la création. Comparer au total HT
            // des lignes générerait un faux écart systématique sur toute commande avec TVA.
            //
            // Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (HIGH) : ce
            // contrôle existe depuis la v2.3.0 et confronte notre calcul au montant que Shopify
            // déclare avoir encaissé — le meilleur juge disponible. Il n'aboutissait qu'au journal
            // (dolibarr.log, jamais lu en exploitation courante) : sur toute boutique en prix hors
            // taxes, l'écart existait sur CHAQUE commande pendant plus d'un an sans que personne ne
            // l'entende (story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes,
            // corrigée au commit e825f456). AC1/AC5 : $lastTotalsMismatch est lu juste après par
            // processSingleOrderFromWebhook() pour persister la marque sur ShopifyOrderSync — la
            // commande reste IMPORTÉE (AC5, décision explicite ci-dessous), jamais refusée ni mise
            // en attente pour ce seul motif.
            //
            // AC5 — décision (à confirmer/infirmer en review) : IMPORTER quand même, marque
            // persistée. Refuser ferait perdre une commande réelle (paiement déjà encaissé côté
            // Shopify) pour un écart parfois d'un centime ; la mettre "en attente" exigerait une
            // file de résolution manuelle hors périmètre de cette story et retarderait une commande
            // légitime. La seule différence de traitement entre 'rounding' et 'proportional' est le
            // NIVEAU D'ALERTE (log LOG_WARNING vs LOG_ERR, statut vert/orange vs rouge à l'écran,
            // AC3) — jamais un blocage : un écart proportionnel signale une erreur SYSTÉMATIQUE
            // probable (donc potentiellement déjà présente sur des dizaines d'autres commandes),
            // ce qui appelle une investigation prioritaire, pas un refus au cas par cas.
            $this->lastTotalsMismatch = null;
            if (isset($shopifyOrder->totalPriceSet->shopMoney->amount)) {
                $shopifyTotal = (float) $shopifyOrder->totalPriceSet->shopMoney->amount;
                $dolibarrTotal = (float) $order->total_ttc;
                $this->lastTotalsMismatch = self::classifyTotalsMismatch($shopifyTotal, $dolibarrTotal);
                if ($this->lastTotalsMismatch !== null) {
                    $isProportional = ($this->lastTotalsMismatch['severity'] === 'proportional');
                    $this->log(sprintf(
                        'createOrder - Écart %s totaux TTC Shopify/Dolibarr: Shopify=%.2f, Dolibarr=%.2f (diff=%.4f, relatif=%.2f%%) pour %s'
                        . ($isProportional ? ' — ALERTE: facteur constant probable, signature d\'une erreur de calcul systématique, à investiguer en priorité' : ' — écart d\'arrondi, tolérable'),
                        $this->lastTotalsMismatch['severity'],
                        $shopifyTotal,
                        $dolibarrTotal,
                        $this->lastTotalsMismatch['diff'],
                        $this->lastTotalsMismatch['relativeDiff'] * 100,
                        $shopifyOrder->name
                    ), $isProportional ? LOG_ERR : LOG_WARNING);
                }
            }

            if ($order->statut == Commande::STATUS_DRAFT) {
                // v2.2.8: fallback chain entrepôt — avec STOCK_CALCULATE_ON_VALIDATE_ORDER,
                // valid() sans idwarehouse > 0 ne décrémente jamais le stock (skip silencieux Dolibarr)
                $stockWarehouseId = $this->resolveWarehouseIdForStock($order);
                $this->warnIfStockNotDecremented($stockWarehouseId, "createOrder", "order #" . $order->id, 'STOCK_CALCULATE_ON_VALIDATE_ORDER');
                $validResult = $order->valid($user, $stockWarehouseId);
                if ($validResult < 0) {
                    // Retry sans triggers (contournement conflit trigger tiers ex: ProductionInterne)
                    $this->log("createOrder - valid() failed for order #" . $order->id
                        . ": " . ($order->error ?: 'Unknown error') . " — retrying with notrigger=1", LOG_WARNING);
                    $order->statut = Commande::STATUS_DRAFT; // Reset statut après rollback interne
                    $validResult = $order->valid($user, $stockWarehouseId, 1); // notrigger=1
                    if ($validResult < 0) {
                        $this->log("createOrder - WARNING: valid(notrigger=1) also failed for order #" . $order->id
                            . ": " . ($order->error ?: 'Unknown error') . " — order stays as draft", LOG_WARNING);
                    } else {
                        $this->log("createOrder - Order #" . $order->id . " validated with notrigger=1 (trigger tiers en conflit)", LOG_WARNING);
                    }
                } else {
                    $this->log("createOrder - Order #" . $order->id . " validated (STATUS_DRAFT → STATUS_VALIDATED)", LOG_DEBUG);
                }
            }

            // ====================================================================
            // v2.2.0 : CRÉATION AUTOMATIQUE FACTURE (Story 2.2 + 12.1)
            // ====================================================================
            if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_CREATE_INVOICE', 1)) {
                $invoiceErrors = array(); // Isolate invoice errors from order errors
                $invoiceResult = $this->createInvoiceFromOrder($order, $user);
                if ($invoiceResult < 0) {
                    // Extract only invoice-related errors (added after this point)
                    $invoiceErrors = array_slice($this->errors, -1); // Last error is the invoice one
                    $this->log("createOrder - Invoice creation failed (non-blocking) for order #" . $order->id . ": " . implode(', ', $invoiceErrors), LOG_WARNING);
                    // Non-bloquant : la commande est valide, on continue
                } elseif ($invoiceResult > 0) {
                    $this->log("createOrder - Invoice #" . $invoiceResult . " created for order #" . $order->id, LOG_INFO);
                }
            }

            // ====================================================================
            // v2.1.5 : SYSTÈME CONTACTS ADRESSES HISTORIQUE
            // ====================================================================
            if (getDolGlobalInt('DOLI2SHOP_ENABLE_ADDRESS_CONTACTS')) {
                $this->log("v2.1.5 : Activation système contacts historique pour commande #" . $order->id, LOG_DEBUG);

                // Créer ou trouver contact livraison
                if (isset($shopifyOrder->shippingAddress) && !empty($shopifyOrder->shippingAddress)) {
                    $shippingContactId = $this->createOrFindShippingContact(
                        $customerId,
                        $shopifyOrder->shippingAddress
                    );

                    if ($shippingContactId) {
                        $this->associateContactToOrder($order->id, $shippingContactId, 'SHIPPING');
                    }
                }

                // Créer ou trouver contact facturation
                if (isset($shopifyOrder->billingAddress) && !empty($shopifyOrder->billingAddress)) {
                    $billingContactId = $this->createOrFindBillingContact(
                        $customerId,
                        $shopifyOrder->billingAddress
                    );

                    if ($billingContactId) {
                        $this->associateContactToOrder($order->id, $billingContactId, 'BILLING');
                    }
                }
            }

            // ====================================================================
            // v2.1.5 : PAGINATION METAFIELDS (Correction perte données >250 metafields)
            // ====================================================================
            $metafields = isset($shopifyOrder->metafields) ? $shopifyOrder->metafields : array();

            // HOTFIX v2.1.5: Extraire count selon type (objet GraphQL {pageInfo, edges} vs array)
            if (is_object($metafields) && isset($metafields->edges)) {
                $metafieldCount = count($metafields->edges);
            } elseif (is_array($metafields)) {
                $metafieldCount = count($metafields);
            } else {
                $metafieldCount = 0;
            }

            // Détection besoin pagination (si >= 250 metafields, possibilité de troncature)
            if ($metafieldCount >= 250) {
                $this->log("[WARNING]  ATTENTION: " . $metafieldCount . " metafields détectés - Activation pagination automatique", LOG_WARNING);

                try {
                    // Récupération complète via pagination
                    $allMetafields = $this->shopifyApi->getAllOrderMetafields($shopifyOrder->id);

                    $allMetafieldCount = is_array($allMetafields) ? count($allMetafields) : 0;

                    if ($allMetafieldCount > $metafieldCount) {
                        $this->log("[OK] Pagination metafields: " . $metafieldCount . " → " . $allMetafieldCount .
                                   " (" . ($allMetafieldCount - $metafieldCount) . " metafields supplémentaires récupérés)", LOG_INFO);
                        $metafields = $allMetafields;
                    }
                } catch (Exception $e) {
                    $this->log("[ERROR] Erreur pagination metafields: " . $e->getMessage() . " - Utilisation metafields partiels", LOG_ERR);
                    // Fallback: utiliser les metafields partiels disponibles
                }
            }

            // ====================================================================
            // v2.1.5 : SUPPORT POINTS RELAIS (MONDIAL RELAY, BOXTAL, ATLAS, ETC.)
            // ====================================================================
            if (getDolGlobalInt('DOLI2SHOP_ENABLE_PICKUP_POINTS')) {
                $this->log("v2.1.5 : Activation support points relais pour commande #" . $order->id, LOG_DEBUG);

                $customAttributes = isset($shopifyOrder->customAttributes) ? $shopifyOrder->customAttributes : array();
                $note = isset($shopifyOrder->note) ? $shopifyOrder->note : '';

                $pickupInfo = $this->extractPickupPointInfo($metafields, $customAttributes, $note);
                $this->savePickupPointInfo($order->id, $pickupInfo);
            }

            // ====================================================================
            // v2.1.5 : TRAÇABILITÉ METAFIELDS BRUTS (TOUS LES METAFIELDS SHOPIFY)
            // ====================================================================
            if (getDolGlobalInt('DOLI2SHOP_SAVE_RAW_METAFIELDS')) {
                $this->log("v2.1.5 : Activation sauvegarde metafields bruts pour commande #" . $order->id, LOG_DEBUG);

                // Utilisation de $metafields déjà paginé (ligne 1044)
                $customAttributes = isset($shopifyOrder->customAttributes) ? $shopifyOrder->customAttributes : array();
                $note = isset($shopifyOrder->note) ? $shopifyOrder->note : '';
                $tags = isset($shopifyOrder->tags) ? $shopifyOrder->tags : array();

                $this->saveRawMetafields($order->id, $metafields, $customAttributes, $note, $tags);
            }

            // ====================================================================
            // Epic 47, Story 47-5 : Tag catégorie commande par boutique (non bloquant)
            // ====================================================================
            try {
                $fkStore = ($this->shopifyApi !== null) ? (int) $this->shopifyApi->getStoreId() : 0;
                if ($fkStore > 0) {
                    $storeServiceForTag = new StoreService($this->db, (int) ($this->entity ?? 1));
                    $storeForTag        = $storeServiceForTag->fetch($fkStore);
                    if ($storeForTag !== null) {
                        $catHelper = new StoreCategoryHelper($this->db, (int) ($this->entity ?? 1));
                        $catHelper->tagObjectWithStoreCategory($order, $storeForTag, 'order');
                    }
                }
            } catch (\Throwable $eCat) {
                // Non bloquant : la commande reste créée même si le tag échoue (capture aussi Error/TypeError)
                $this->log('createOrder - Erreur tag catégorie commande (non bloquant): ' . $eCat->getMessage(), LOG_WARNING);
            }

            // Release advisory lock
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return $order->id;
        } catch (Exception $e) {
            $this->log("Erreur: " . $e->getMessage(), LOG_ERR);
            // Release advisory lock even on error
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return -1;
        }
    }

    /**
     * Résout l'entrepôt à passer à Facture::validate() pour le mouvement de stock.
     *
     * Dolibarr ne décrémente le stock sur validation de facture (STOCK_CALCULATE_ON_BILL)
     * que si $idwarehouse > 0 — sinon le bloc stock est silencieusement ignoré.
     * Chaîne de fallback : config module → entrepôt de la commande → entrepôt global Dolibarr.
     *
     * @param  Commande|null $order Commande source (optionnelle, pour son warehouse_id)
     * @return int                  ID entrepôt, ou 0 si aucun trouvé
     * @since  2.2.8
     */
    private function resolveWarehouseIdForStock($order = null)
    {
        $stockWarehouseId = $this->storeSettings->getInt($this->getStoreId(), 'DEFAULT_WAREHOUSE_ID', 0);

        if ($stockWarehouseId <= 0 && isset($this->config) && !empty($this->config->default_warehouse_id)) {
            $stockWarehouseId = (int) $this->config->default_warehouse_id;
        }

        if ($stockWarehouseId <= 0 && $order !== null && !empty($order->warehouse_id)) {
            $stockWarehouseId = (int) $order->warehouse_id;
        }

        if ($stockWarehouseId <= 0) {
            $stockWarehouseId = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE', 0);
        }

        return $stockWarehouseId;
    }

    /**
     * Logge un LOG_WARNING si aucun entrepôt n'est résolu alors qu'une règle de
     * décrémentation automatique du stock est active — Dolibarr skippe alors le
     * mouvement de stock silencieusement (aucune erreur, aucun log natif).
     *
     * Les 4 règles Dolibarr possibles :
     *   STOCK_CALCULATE_ON_BILL            (validation facture client)
     *   STOCK_CALCULATE_ON_VALIDATE_ORDER  (validation commande client)
     *   STOCK_CALCULATE_ON_SHIPMENT        (validation expédition)
     *   STOCK_CALCULATE_ON_SHIPMENT_CLOSE  (classement clôturé de l'expédition)
     *
     * @param  int          $stockWarehouseId Entrepôt résolu (0 = aucun)
     * @param  string       $context          Nom de la méthode appelante (pour le log)
     * @param  string       $objectInfo       Identification de l'objet (ex: "order #123")
     * @param  string|array $stockRules       Constante(s) Dolibarr concernée(s) par ce chemin
     * @return void
     * @since  2.2.8
     */
    private function warnIfStockNotDecremented($stockWarehouseId, $context, $objectInfo, $stockRules)
    {
        if ($stockWarehouseId > 0 || !isModEnabled('stock')) {
            return;
        }

        foreach ((array) $stockRules as $stockRuleConstant) {
            if (getDolGlobalString($stockRuleConstant)) {
                $this->log($context . " - WARNING: no warehouse resolved for " . $objectInfo
                    . " — stock will NOT be decremented (" . $stockRuleConstant . " requires idwarehouse > 0)."
                    . " Set DOLI2SHOP_DEFAULT_WAREHOUSE_ID or MAIN_DEFAULT_WAREHOUSE.", LOG_WARNING);
                return;
            }
        }
    }

    /**
     * Create an invoice from a validated order and optionally validate it (Story 2.2 + 12.1)
     *
     * Creates an invoice linked to the given order. If DOLI2SHOP_AUTO_VALIDATE_INVOICE
     * is enabled (default=1), the invoice is automatically validated (status=1) and receives
     * an official number. If disabled or validation fails, the invoice stays as draft (status=0).
     * This is non-blocking: if invoice creation/validation fails, the order remains valid.
     *
     * IMPORTANT: Default is ON (1). Existing installations upgrading to v2.2.0 will
     * have invoices auto-validated unless they explicitly disable this setting.
     *
     * Return codes:
     *   > 0 : ID of the created invoice (success — validated or draft)
     *   0   : Invoice not created (module disabled, already exists, insufficient rights — non-blocking)
     *   < 0 : Technical error (DB failure, exception)
     *
     * @param  Commande $order  The validated Dolibarr order
     * @param  User     $user   The user performing the action
     * @return int              Invoice ID (>0), 0 (skipped), or negative on error
     * @since  2.2.0
     */
    private function createInvoiceFromOrder($order, $user)
    {
        global $conf;

        // Pre-creation checks (non-blocking: return 0 to skip without error)
        if (!isModEnabled('facture')) {
            $this->log("createInvoiceFromOrder - Module Facture not enabled, skipping invoice creation", LOG_INFO);
            return 0;
        }

        if (!$user->hasRight('facture', 'creer')) {
            $this->log("createInvoiceFromOrder - User " . $user->login . " has no facture->creer right, skipping invoice creation", LOG_WARNING);
            return 0;
        }

        if (empty($order->lines) || count($order->lines) == 0) {
            // Reload lines from DB if not populated after create()+valid()
            $order->fetch_lines();
            if (empty($order->lines) || count($order->lines) == 0) {
                $this->log("createInvoiceFromOrder - Order #" . $order->id . " has no lines, skipping invoice creation", LOG_WARNING);
                return 0;
            }
        }

        // Idempotence: check if an invoice is already linked to this order via element_element
        $sql = "SELECT fk_target FROM " . MAIN_DB_PREFIX . "element_element";
        $sql .= " WHERE sourcetype = 'commande' AND fk_source = " . ((int) $order->id);
        $sql .= " AND targettype = 'facture'";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $existingInvoiceId = (int) $obj->fk_target;
                $this->log("createInvoiceFromOrder - Invoice already exists (ID=" . $existingInvoiceId . ") for order #" . $order->id . ", skipping", LOG_INFO);
                $this->db->free($resql);
                return 0;
            }
            $this->db->free($resql);
        }

        // Create draft invoice linked to the order
        try {
            $invoice = new Facture($this->db);

            // Copy order header properties to invoice
            $invoice->socid = $order->socid;
            $invoice->type = Facture::TYPE_STANDARD;
            $invoice->date = dol_now();
            $invoice->ref_client = $order->ref_client;
            $invoice->cond_reglement_id = $order->cond_reglement_id;
            $invoice->mode_reglement_id = $order->mode_reglement_id;
            $invoice->fk_account = $order->fk_account;
            $invoice->note_private = '[Doli2Shop] Auto-created from order ' . $order->ref;

            // Origin link: Dolibarr auto-creates commande→facture in llx_element_element
            $invoice->origin = 'commande';
            $invoice->origin_id = $order->id;

            // Multi-entity support
            $invoice->entity = $conf->entity;

            // Create the invoice (header only — lines added below)
            $invoiceId = $invoice->create($user);

            if ($invoiceId < 0) {
                $this->errors[] = "Invoice create() failed: " . ($invoice->error ? $invoice->error : 'Unknown error');
                $this->log("createInvoiceFromOrder - Failed to create invoice for order #" . $order->id . ": " . $invoice->error, LOG_ERR);
                return -1;
            }

            // Lien explicite commande→facture dans element_element
            // Dolibarr 22.x ne crée pas automatiquement le lien via origin/origin_id dans Facture::create()
            $invoice->add_object_linked($invoice->origin, $invoice->origin_id);
            $this->log("createInvoiceFromOrder - Linked invoice #" . $invoiceId . " to order #" . $order->id . " in element_element", LOG_DEBUG);

            // v2.2.0 FIX: Dolibarr 22.x Facture::create() ne copie PAS les lignes depuis origin
            // On doit les ajouter manuellement depuis la commande après création
            $invoice->fetch_lines();
            if (empty($invoice->lines) || count($invoice->lines) == 0) {
                // Recharger les lignes de la commande depuis la BDD
                $order->fetch_lines();
                if (!empty($order->lines)) {
                    $this->log("createInvoiceFromOrder - Adding " . count($order->lines) . " lines from order #" . $order->id . " to invoice #" . $invoiceId, LOG_DEBUG);
                    foreach ($order->lines as $orderLine) {
                        $result = $invoice->addline(
                            $orderLine->desc,                           // desc
                            $orderLine->subprice,                       // pu_ht
                            $orderLine->qty,                            // qty
                            $orderLine->tva_tx,                         // txtva
                            $orderLine->localtax1_tx,                   // txlocaltax1
                            $orderLine->localtax2_tx,                   // txlocaltax2
                            $orderLine->fk_product,                     // fk_product
                            $orderLine->remise_percent,                 // remise_percent
                            '',                                         // date_start
                            '',                                         // date_end
                            0,                                          // ventil (code comptable)
                            $orderLine->info_bits,                      // info_bits
                            $orderLine->fk_remise_except,               // fk_remise_except
                            'HT',                                       // price_base_type
                            0,                                          // pu_ttc
                            $orderLine->product_type,                   // type
                            -1,                                         // rang
                            $orderLine->special_code,                   // special_code
                            '',                                         // origin
                            0,                                          // origin_id
                            0,                                          // fk_parent_line
                            $orderLine->fk_fournprice ?? null,          // fk_fournprice
                            $orderLine->pa_ht ?? 0,                     // pa_ht
                            $orderLine->label ?? '',                    // label
                            $orderLine->array_options ?? array(),       // array_options
                            '',                                         // situation_percent
                            0,                                          // fk_prev_id
                            $orderLine->fk_unit ?? null                 // fk_unit
                        );
                        if ($result < 0) {
                            $this->log("createInvoiceFromOrder - Failed to add line to invoice #" . $invoiceId . ": " . $invoice->error, LOG_WARNING);
                        }
                    }
                    // Recharger les lignes pour la validation
                    $invoice->fetch_lines();
                    $this->log("createInvoiceFromOrder - Invoice #" . $invoiceId . " now has " . count($invoice->lines) . " lines", LOG_DEBUG);
                }
            } else {
                $this->log("createInvoiceFromOrder - Invoice #" . $invoiceId . " already has " . count($invoice->lines) . " lines (auto-copied by Dolibarr)", LOG_DEBUG);
            }

            // Story 12.1: Auto-validate invoice if configured
            if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_VALIDATE_INVOICE', 1)) {
                // Check user has validation rights before attempting
                if ($user->hasRight('facture', 'valider')) {
                    // v2.2.8: passer l'entrepôt à validate() — sans idwarehouse > 0,
                    // STOCK_CALCULATE_ON_BILL ne décrémente jamais le stock (skip silencieux Dolibarr)
                    $stockWarehouseId = $this->resolveWarehouseIdForStock($order);
                    $this->warnIfStockNotDecremented($stockWarehouseId, "createInvoiceFromOrder", "invoice #" . $invoiceId, 'STOCK_CALCULATE_ON_BILL');
                    $validateResult = $invoice->validate($user, '', $stockWarehouseId);
                    if ($validateResult > 0) {
                        $this->log("createInvoiceFromOrder - Invoice #" . $invoiceId
                            . " created and validated (ref=" . $invoice->ref . ") for order #" . $order->id, LOG_INFO);
                    } else {
                        // Non-bloquant: validation failed, invoice stays as draft.
                        // Note: no automatic retry — admin must validate manually if this fails.
                        $validateError = $invoice->error ? $invoice->error : 'Unknown validation error';
                        if (!empty($invoice->errors)) {
                            $validateError = implode('; ', $invoice->errors);
                        }
                        $this->log("createInvoiceFromOrder - Invoice #" . $invoiceId
                            . " created as draft (validation failed: " . $validateError
                            . ") for order #" . $order->id, LOG_WARNING);
                    }
                } else {
                    $this->log("createInvoiceFromOrder - Invoice #" . $invoiceId
                        . " created as draft (user has no facture->valider right) for order #" . $order->id, LOG_WARNING);
                }
            } else {
                $this->log("createInvoiceFromOrder - Invoice #" . $invoiceId
                    . " created as draft (auto-validate disabled) for order #" . $order->id, LOG_INFO);
            }

            // ====================================================================
            // Epic 47, Story 47-5 : Tag catégorie facture par boutique (non bloquant)
            // ====================================================================
            try {
                $fkStoreForInv = ($this->shopifyApi !== null) ? (int) $this->shopifyApi->getStoreId() : 0;
                if ($fkStoreForInv > 0) {
                    $storeServiceForInv = new StoreService($this->db, (int) ($this->entity ?? 1));
                    $storeForInv        = $storeServiceForInv->fetch($fkStoreForInv);
                    if ($storeForInv !== null) {
                        $catHelperInv = new StoreCategoryHelper($this->db, (int) ($this->entity ?? 1));
                        $catHelperInv->tagObjectWithStoreCategory($invoice, $storeForInv, 'invoice');
                    }
                }
            } catch (\Throwable $eCatInv) {
                // Non bloquant : la facture reste créée même si le tag échoue (capture aussi Error/TypeError)
                $this->log('createInvoiceFromOrder - Erreur tag catégorie facture (non bloquant): ' . $eCatInv->getMessage(), LOG_WARNING);
            }

            return $invoiceId;

        } catch (Exception $e) {
            $this->errors[] = "Exception creating invoice: " . $e->getMessage();
            $this->log("createInvoiceFromOrder - Exception for order #" . $order->id . ": " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Process payment for an existing Dolibarr order (Story 12.2)
     *
     * Finds the linked invoice via element_element, checks idempotence
     * (no duplicate payment via paiement_facture), creates payment if needed,
     * and classifies order as "Billed".
     *
     * Return codes:
     *   > 0 : Payment created successfully (payment ID)
     *   0   : Skipped (no invoice found, payment already exists, toggle disabled, or invoice not validated)
     *   < 0 : Error
     *
     * @param  int    $dolibarrOrderId  Dolibarr order rowid
     * @param  mixed  $shopifyData      Shopify order data (object or array)
     * @param  User   $user             User performing the action
     * @return int                      Payment ID (>0), 0 (skipped), or negative on error
     * @since  2.2.0
     */
    public function processPaymentForOrder($dolibarrOrderId, $shopifyData, $user)
    {
        $this->log("processPaymentForOrder - Processing payment for order #" . $dolibarrOrderId, LOG_INFO);

        // 1. Load the Dolibarr order
        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        $order = new Commande($this->db);
        $fetchResult = $order->fetch($dolibarrOrderId);
        if ($fetchResult <= 0) {
            $this->errors[] = "Order #" . $dolibarrOrderId . " not found";
            $this->log("processPaymentForOrder - Order #" . $dolibarrOrderId . " not found or fetch error", LOG_WARNING);
            return -1;
        }

        // 2. Find the linked invoice via element_element
        $sql = "SELECT fk_target FROM " . MAIN_DB_PREFIX . "element_element";
        $sql .= " WHERE fk_source = " . ((int) $dolibarrOrderId);
        $sql .= " AND sourcetype = 'commande' AND targettype = 'facture'";
        $sql .= " ORDER BY rowid DESC LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = "SQL error finding invoice: " . $this->db->lasterror();
            $this->log("processPaymentForOrder - SQL error finding invoice for order #"
                . $dolibarrOrderId . ": " . $this->db->lasterror(), LOG_ERR);
            return -2;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj || empty($obj->fk_target)) {
            $this->log("processPaymentForOrder - No invoice linked to order #"
                . $dolibarrOrderId . ", skipping payment", LOG_INFO);
            return 0;
        }

        $invoiceId = (int) $obj->fk_target;

        // 3. Check idempotence: does the invoice already have a payment? (AC5)
        $sqlPay = "SELECT rowid FROM " . MAIN_DB_PREFIX . "paiement_facture";
        $sqlPay .= " WHERE fk_facture = " . $invoiceId;
        $sqlPay .= " LIMIT 1";

        $resqlPay = $this->db->query($sqlPay);
        $hasPayment = ($resqlPay && $this->db->fetch_object($resqlPay));
        if ($resqlPay) {
            $this->db->free($resqlPay);
        }
        if ($hasPayment) {
            $this->log("processPaymentForOrder - Payment already exists for invoice #"
                . $invoiceId . " (order #" . $dolibarrOrderId . "), skipping (idempotent)", LOG_INFO);
            return 0;
        }

        // 4. Load the invoice for payment creation
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($this->db);
        $fetchInvoice = $invoice->fetch($invoiceId);
        if ($fetchInvoice <= 0) {
            $this->errors[] = "Invoice #" . $invoiceId . " not found";
            $this->log("processPaymentForOrder - Invoice #" . $invoiceId
                . " not found or fetch error", LOG_WARNING);
            return -3;
        }

        // 5. Create the payment (Story 12.3)
        $paymentResult = $this->createPaymentFromShopify($order, $invoice, $shopifyData, $user);

        // 6. If payment created successfully, classify order as "Billed" (Story 12.4)
        if ($paymentResult > 0) {
            if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_CLASSIFY_BILLED', 1)) {
                $this->classifyOrderBilledIfInvoiced($order, $user);
                if (!empty($order->billed)) {
                    $this->log("processPaymentForOrder - Order #" . $dolibarrOrderId
                        . " classified Billed after payment #" . $paymentResult, LOG_INFO);
                }
            }

        }

        // 7. Flux paiement tardif (Story 38.1) : si la commande est entièrement
        // expédiée mais pas encore clôturée (paiement reçu après l'expédition),
        // tenter la clôture. Appelé aussi si paymentResult=0 (paiement déjà existant)
        // pour rattraper le cas doublon webhook orders/paid. Idempotent via attemptAutoCloseOrder().
        if ($paymentResult >= 0) {
            $this->attemptAutoCloseOrder($order, $user, 'orders/paid');
        }

        return $paymentResult;
    }

    /**
     * Create a Dolibarr Payment from Shopify payment data (Story 12.3)
     *
     * Creates a Paiement object linked to the invoice, optionally adds a bank entry
     * if module Banque is active and DOLI2SHOP_ORDER_BANK_ACCOUNT is configured,
     * then marks the invoice as paid. Controlled by DOLI2SHOP_AUTO_CREATE_PAYMENT toggle.
     *
     * @param  Commande $order        Dolibarr order
     * @param  Facture  $invoice      Dolibarr invoice (must be validated, status >= 1)
     * @param  mixed    $shopifyData  Shopify order data (object or array)
     * @param  User     $user         User performing the action
     * @return int                    Payment ID (>0), 0 (skipped), or negative on error
     * @since  2.2.0
     */
    private function createPaymentFromShopify($order, $invoice, $shopifyData, $user)
    {
        // Story 12.3: Check toggle — if disabled, skip payment creation (AC6)
        if (!$this->storeSettings->getInt($this->getStoreId(), 'AUTO_CREATE_PAYMENT', 1)) {
            $this->log("createPaymentFromShopify - Auto-create payment disabled, skipping"
                . " for invoice #" . $invoice->id, LOG_INFO);
            return 0;
        }

        // Check invoice is validated (status >= 1) — can't pay a draft invoice
        if ($invoice->statut < 1) {
            $this->log("createPaymentFromShopify - Invoice #" . $invoice->id
                . " is in draft (status=" . $invoice->statut . "), cannot create payment."
                . " Enable DOLI2SHOP_AUTO_VALIDATE_INVOICE or validate manually.", LOG_WARNING);
            return 0;
        }

        // v2.2.0 FIX: Facture à 0€ (produit gratuit) — classer payée sans créer de paiement
        if ($invoice->total_ttc == 0) {
            $setPaidResult = $invoice->setPaid($user);
            if ($setPaidResult < 0) {
                $this->log("createPaymentFromShopify - setPaid() failed for 0€ invoice #"
                    . $invoice->id . ": " . ($invoice->error ?: 'Unknown'), LOG_WARNING);
            } else {
                $this->log("createPaymentFromShopify - 0€ invoice #" . $invoice->id
                    . " classified as paid (no payment needed)", LOG_INFO);
            }
            return 0; // Pas de paiement créé, mais facture classée payée
        }

        // 1. Extract payment mode from webhook payload (AC4)
        $paymentModeId = $this->getPaymentModeFromWebhook($shopifyData);

        // 2. Extract payment date and order reference
        $paymentDate = dol_now();
        $shopifyOrderName = '';
        if (is_array($shopifyData)) {
            $paymentDate = !empty($shopifyData['created_at'])
                ? strtotime($shopifyData['created_at']) : dol_now();
            $shopifyOrderName = $shopifyData['name'] ?? '';
        } elseif (is_object($shopifyData)) {
            $paymentDate = !empty($shopifyData->created_at)
                ? strtotime($shopifyData->created_at) : dol_now();
            $shopifyOrderName = $shopifyData->name ?? '';
        }

        // 3. Extract gateway name for note
        $gatewayName = $this->extractGatewayName($shopifyData);

        // 4. Create Paiement object (AC1)
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        $paiement = new Paiement($this->db);
        $paiement->datepaye     = $paymentDate;
        $paiement->amounts      = array($invoice->id => $invoice->total_ttc);
        $paiement->multicurrency_amounts = array($invoice->id => $invoice->multicurrency_total_ttc);
        $paiement->paiementid   = $paymentModeId;
        $paiement->num_payment  = $shopifyOrderName;
        $paiement->note_public  = 'Paiement Shopify' . ($gatewayName ? ' via ' . $gatewayName : '');

        $this->db->begin();

        $paymentId = $paiement->create($user, 1); // 1 = don't auto-close invoice if partial
        if ($paymentId < 0) {
            $this->db->rollback();
            $this->errors[] = "Payment create() failed: " . ($paiement->error ?: 'Unknown error');
            $this->log("createPaymentFromShopify - Failed for invoice #" . $invoice->id
                . " (order #" . $order->id . "): " . ($paiement->error ?: 'Unknown'), LOG_ERR);
            return -1;
        }

        // 5. Add bank entry if module Banque active (AC2/AC3)
        $bankAccountId = $this->storeSettings->getInt($this->getStoreId(), 'ORDER_BANK_ACCOUNT', 0);
        if (isModEnabled('banque') && $bankAccountId > 0) {
            $addBankResult = $paiement->addPaymentToBank(
                $user, 'payment', '(CustomerInvoicePayment)', $bankAccountId, '', ''
            );
            if ($addBankResult < 0) {
                // Non-bloquant : paiement créé mais écriture bancaire échouée
                $this->log("createPaymentFromShopify - Bank entry failed (non-blocking) for payment #"
                    . $paymentId . ": " . ($paiement->error ?: 'Unknown'), LOG_WARNING);
            }
        } else {
            $this->log("createPaymentFromShopify - Bank module not active or no account configured"
                . " — payment #" . $paymentId . " created without bank entry", LOG_INFO);
        }

        // 6. Mark invoice as paid (AC1)
        $setPaidResult = $invoice->setPaid($user);
        if ($setPaidResult < 0) {
            $this->log("createPaymentFromShopify - setPaid() failed (non-blocking) for invoice #"
                . $invoice->id . ": " . ($invoice->error ?: 'Unknown'), LOG_WARNING);
        }

        $this->db->commit();

        $this->log("createPaymentFromShopify - Payment #" . $paymentId . " created"
            . " for invoice #" . $invoice->id . " (order #" . $order->id
            . ", amount=" . $invoice->total_ttc . ", mode=" . $paymentModeId . ")", LOG_INFO);

        return $paymentId;
    }

    /**
     * Get Dolibarr payment mode ID from webhook REST payload (Story 12.3)
     *
     * Unlike getPaymentMode() which expects GraphQL response with transactions->edges,
     * this method works with the REST webhook payload containing payment_gateway_names.
     *
     * @param  mixed $shopifyData  Shopify webhook payload (array or object)
     * @return int                 Dolibarr payment mode ID (default: 6 = CB)
     * @since  2.2.0
     */
    private function getPaymentModeFromWebhook($shopifyData)
    {
        $gatewayName = $this->extractGatewayName($shopifyData);
        if (empty($gatewayName)) {
            $this->log("getPaymentModeFromWebhook - No gateway found, using default CB (6)", LOG_DEBUG);
            return 6;
        }

        // Try database mapping first (same as getPaymentMode)
        require_once dirname(__FILE__) . '/paymentmethodsmapping.class.php';
        $paymentMappingObj = new PaymentMethodsMapping($this->db);
        $dolibarrPaymentId = $paymentMappingObj->getDolibarrPaymentId($gatewayName, $this->getStoreId());

        if ($dolibarrPaymentId !== false) {
            $this->log("getPaymentModeFromWebhook - Mapped '" . $gatewayName
                . "' to payment mode " . $dolibarrPaymentId, LOG_DEBUG);
            return $dolibarrPaymentId;
        }

        // Emergency fallback (same logic as getPaymentMode + common gateways)
        $emergencyFallback = array(
            'card' => 6,               // CB
            'bank' => 2,               // Virement
            'check' => 7,              // Chèque
            'paypal' => 6,             // CB fallback
            'stripe' => 6,             // CB fallback
            'shopify_payments' => 6,   // CB fallback
            'cash' => 4,               // Espèces
        );
        if (isset($emergencyFallback[$gatewayName])) {
            $this->log("getPaymentModeFromWebhook - Emergency fallback '" . $gatewayName
                . "' to mode " . $emergencyFallback[$gatewayName], LOG_DEBUG);
            return $emergencyFallback[$gatewayName];
        }

        $this->log("getPaymentModeFromWebhook - No mapping for '" . $gatewayName
            . "', using default CB (6)", LOG_WARNING);
        return 6;
    }

    /**
     * Extract the primary payment gateway name from Shopify webhook data (Story 12.3)
     *
     * Handles both array and object payloads (webhook REST format).
     *
     * @param  mixed $shopifyData  Shopify webhook payload (array or object)
     * @return string              Gateway name (e.g. "shopify_payments") or empty string
     * @since  2.2.0
     */
    private function extractGatewayName($shopifyData)
    {
        if (is_array($shopifyData)) {
            if (!empty($shopifyData['payment_gateway_names']) && is_array($shopifyData['payment_gateway_names'])) {
                return (string) $shopifyData['payment_gateway_names'][0];
            }
            return isset($shopifyData['gateway']) ? (string) $shopifyData['gateway'] : '';
        } elseif (is_object($shopifyData)) {
            if (!empty($shopifyData->payment_gateway_names) && is_array($shopifyData->payment_gateway_names)) {
                return (string) $shopifyData->payment_gateway_names[0];
            }
            return isset($shopifyData->gateway) ? (string) $shopifyData->gateway : '';
        }
        return '';
    }

    /**
     * Create a shipment (Expedition) from a Shopify fulfillment linked to a Dolibarr order.
     *
     * Called from OrderWebhookHandler::handleOrderFulfilled() when a Shopify order
     * is fulfilled or partially fulfilled. The expedition is created as a validated
     * shipment with tracking info.
     *
     * Return codes:
     *   > 0 : ID of the created expedition (success)
     *   0   : Expedition not created (module disabled, already exists, insufficient rights — non-blocking)
     *   < 0 : Technical error (DB failure, exception)
     *
     * @param  Commande $order           The Dolibarr order
     * @param  array    $fulfillmentData Shopify fulfillment data (tracking_number, tracking_url, line_items, etc.)
     * @param  User     $user            The user performing the action
     * @param  bool     $isPartial       True if orders/partially_fulfilled
     * @return int                       Expedition ID (>0), 0 (skipped), or negative on error
     * @since  2.2.0
     */
    public function createExpeditionFromFulfillment($order, $fulfillmentData, $user, $isPartial = false)
    {
        global $conf;

        // Pre-creation checks (non-blocking: return 0 to skip without error)
        if (!isModEnabled('expedition')) {
            $this->log("createExpeditionFromFulfillment - Module Expedition not enabled, skipping shipment creation", LOG_INFO);
            return 0;
        }

        if (!$user->hasRight('expedition', 'creer')) {
            $this->log("createExpeditionFromFulfillment - User " . $user->login . " has no expedition->creer right, skipping", LOG_WARNING);
            return 0;
        }

        // Ensure order lines are loaded
        if (empty($order->lines) || count($order->lines) == 0) {
            $order->fetch_lines();
            if (empty($order->lines) || count($order->lines) == 0) {
                $this->log("createExpeditionFromFulfillment - Order #" . $order->id . " has no lines, skipping", LOG_WARNING);
                return 0;
            }
        }

        // Check order status: reopen if closed, skip if canceled or draft
        if ($order->statut == Commande::STATUS_CANCELED) {
            $this->log("createExpeditionFromFulfillment - Order #" . $order->id
                . " is canceled, skipping expedition creation", LOG_WARNING);
            return 0;
        }
        if ($order->statut == Commande::STATUS_DRAFT) {
            $this->log("createExpeditionFromFulfillment - Order #" . $order->id
                . " is draft, attempting auto-validation before expedition", LOG_WARNING);
            // v2.2.8: fallback chain entrepôt (cf. createOrder)
            $warehouseId = $this->resolveWarehouseIdForStock($order);
            $this->warnIfStockNotDecremented($warehouseId, "createExpeditionFromFulfillment", "order #" . $order->id, 'STOCK_CALCULATE_ON_VALIDATE_ORDER');
            $validResult = $order->valid($user, $warehouseId);
            if ($validResult < 0) {
                // Retry sans triggers (contournement conflit trigger tiers)
                $this->log("createExpeditionFromFulfillment - valid() failed, retrying with notrigger=1", LOG_WARNING);
                $order->statut = Commande::STATUS_DRAFT;
                $validResult = $order->valid($user, $warehouseId, 1);
                if ($validResult < 0) {
                    $this->log("createExpeditionFromFulfillment - Cannot validate draft order #" . $order->id
                        . ": " . ($order->error ?: 'Unknown error') . ", skipping expedition", LOG_ERR);
                    return 0;
                }
                $this->log("createExpeditionFromFulfillment - Order #" . $order->id
                    . " auto-validated with notrigger=1 (trigger tiers en conflit)", LOG_WARNING);
            } else {
                $this->log("createExpeditionFromFulfillment - Order #" . $order->id
                    . " auto-validated (STATUS_DRAFT → STATUS_VALIDATED)", LOG_INFO);
            }
        }
        // v2.2.2 — Tracker si on a rouvert la commande pour pouvoir la refermer
        // si le webhook concurrent (orders/fulfilled juste après orders/updated) découvre
        // que l'expédition existe déjà. Sans ce flag, la commande reste "rouverte" indéfiniment.
        $orderWasReopened = false;
        if ($order->statut == Commande::STATUS_CLOSED) {
            $this->log("createExpeditionFromFulfillment - Order #" . $order->id
                . " is closed, attempting reopen for late fulfillment", LOG_INFO);
            $reopenResult = $order->set_reopen($user);
            if ($reopenResult > 0) {
                $orderWasReopened = true;
                $this->log("createExpeditionFromFulfillment - Order #" . $order->id
                    . " reopened successfully (STATUS_CLOSED → STATUS_VALIDATED)", LOG_INFO);
            } else {
                $this->log("createExpeditionFromFulfillment - Failed to reopen order #" . $order->id
                    . ": " . ($order->error ? $order->error : 'Unknown error') . ", skipping", LOG_WARNING);
                return 0;
            }
        }

        // Extract tracking info from fulfillment data
        $trackingNumber = isset($fulfillmentData['tracking_number']) ? $fulfillmentData['tracking_number'] : '';
        $trackingUrl = isset($fulfillmentData['tracking_url']) ? $fulfillmentData['tracking_url'] : '';
        $trackingCompany = isset($fulfillmentData['tracking_company']) ? $fulfillmentData['tracking_company'] : '';
        $fulfillmentId = isset($fulfillmentData['id']) ? (string) $fulfillmentData['id'] : '';

        // Idempotence: check if an expedition for this fulfillment is already linked to this order.
        // Primary key: tracking_number (most common). Fallback: fulfillment_id stored in note_private.
        // This supports multiple partial fulfillments per order (each with different tracking/fulfillment_id).
        $sql = "SELECT e.rowid FROM " . MAIN_DB_PREFIX . "expedition e";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "element_element ee";
        $sql .= "   ON ee.fk_target = e.rowid AND ee.targettype = 'shipping'";
        $sql .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source = " . ((int) $order->id);
        if (!empty($trackingNumber)) {
            $sql .= " AND e.tracking_number = '" . $this->db->escape($trackingNumber) . "'";
        } elseif (!empty($fulfillmentId)) {
            // No tracking number: use fulfillment_id stored in note_private for idempotence
            $sql .= " AND e.note_private LIKE '%" . $this->db->escape('fulfillment_id=' . $fulfillmentId) . "%'";
        }
        $sql .= " AND e.entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $existingExpeditionId = (int) $obj->rowid;
                $this->log("createExpeditionFromFulfillment - Expedition already exists (ID=" . $existingExpeditionId . ") for order #" . $order->id
                    . ((!empty($trackingNumber)) ? " tracking=" . $trackingNumber : "") . ", skipping", LOG_INFO);
                ActionLogger::log(
                    $this->db,
                    $this->entity,
                    ActionLogger::TYPE_EXPEDITION_SKIPPED_DUPLICATE,
                    'shipping',
                    $existingExpeditionId,
                    ActionLogger::RESULT_SKIPPED,
                    'Expédition déjà existante pour commande ' . $order->ref
                        . (!empty($trackingNumber) ? ' (tracking ' . $trackingNumber . ')' : '')
                );
                $this->db->free($resql);
                // v2.2.2 — Si on vient de rouvrir la commande pour ce webhook (late fulfillment)
                // mais que l'expédition existait déjà (créée par orders/updated juste avant),
                // refermer la commande pour ne pas la laisser en "validated" indéfiniment.
                if ($orderWasReopened) {
                    $this->reCloseReopenedOrder($order, $user);
                }
                return 0;
            }
            $this->db->free($resql);
        }

        // Advisory lock to prevent race condition when multiple webhooks arrive simultaneously
        // (e.g. orders/fulfilled + orders/updated at the same second)
        $lockName = 'doli2shop_expedition_order_' . ((int) $order->id);
        $lockSql = "SELECT GET_LOCK('" . $this->db->escape($lockName) . "', 5)";
        $lockResult = $this->db->query($lockSql);
        $lockAcquired = false;
        if ($lockResult) {
            $lockObj = $this->db->fetch_object($lockResult);
            // GET_LOCK returns 1 if acquired, 0 if timeout, NULL if error
            if (isset($lockObj) && reset($lockObj) == 1) {
                $lockAcquired = true;
            }
        }
        if (!$lockAcquired) {
            $this->log("createExpeditionFromFulfillment - Could not acquire advisory lock for order #" . $order->id
                . ", another process is likely creating the expedition — skipping", LOG_INFO);
            return 0;
        }

        // Re-check idempotence after acquiring lock (double-check pattern)
        $resql2 = $this->db->query($sql);
        if ($resql2 && $this->db->num_rows($resql2) > 0) {
            $obj2 = $this->db->fetch_object($resql2);
            $this->log("createExpeditionFromFulfillment - Expedition created by concurrent process (ID=" . $obj2->rowid
                . ") for order #" . $order->id . ", releasing lock and skipping", LOG_INFO);
            $this->db->free($resql2);
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            // v2.2.2 — Refermer la commande si on l'avait rouverte (late fulfillment race condition)
            if ($orderWasReopened) {
                $this->reCloseReopenedOrder($order, $user);
            }
            return 0;
        }
        if ($resql2) {
            $this->db->free($resql2);
        }

        // Create expedition linked to the order
        try {
            $expedition = new Expedition($this->db);

            // Header properties
            $expedition->socid = $order->socid;
            $expedition->date_delivery = dol_now();
            $expedition->origin = 'commande';
            $expedition->origin_id = $order->id;
            $expedition->ref_customer = isset($order->ref_client) ? $order->ref_client : '';
            $expedition->tracking_number = $trackingNumber;
            $expedition->tracking_url = $trackingUrl;
            $expedition->note_private = '[Doli2Shop] Auto-created from Shopify fulfillment'
                . (!empty($fulfillmentId) ? ' fulfillment_id=' . $fulfillmentId : '')
                . (!empty($trackingCompany) ? ' (' . $trackingCompany . ')' : '');
            $expedition->entity = $conf->entity;

            // Shipping method: lookup from tracking_company, fallback to config default
            $shippingMethodId = 0;
            if (!empty($trackingCompany)) {
                $shippingMethodId = $this->getShippingMethodIdFromTrackingCompany($trackingCompany);
            }
            if ($shippingMethodId <= 0) {
                $shippingMethodId = $this->storeSettings->getInt($this->getStoreId(), 'DEFAULT_SHIPPING_METHOD_ID', 0);
            }
            if ($shippingMethodId > 0) {
                $expedition->shipping_method_id = $shippingMethodId;
            }

            // Warehouse from module config (v2.2.8: fallback chain + warning —
            // avec STOCK_CALCULATE_ON_SHIPMENT[_CLOSE], des lignes d'expédition sans
            // fk_entrepot > 0 ne décrémentent jamais le stock, sans erreur Dolibarr)
            $warehouseId = $this->resolveWarehouseIdForStock($order);
            $this->warnIfStockNotDecremented($warehouseId, "createExpeditionFromFulfillment", "expedition of order #" . $order->id,
                array('STOCK_CALCULATE_ON_SHIPMENT', 'STOCK_CALCULATE_ON_SHIPMENT_CLOSE'));

            // Build expedition lines
            if ($isPartial && isset($fulfillmentData['line_items']) && is_array($fulfillmentData['line_items'])) {
                // Partial fulfillment: only include fulfilled line_items
                $this->buildPartialExpeditionLines($expedition, $order, $fulfillmentData['line_items'], $warehouseId);
            } else {
                // Full fulfillment: include all order lines
                $this->buildFullExpeditionLines($expedition, $order, $warehouseId);
            }

            // Calculate total weight
            // Priority 1: fulfillment line_items grams (Shopify source of truth, si disponible)
            // Priority 2: Dolibarr product weight (fallback depuis les produits importés)
            $totalWeightKg = 0;
            $weightSource = '';

            // Essayer d'abord les grams du fulfillment
            if (isset($fulfillmentData['line_items']) && is_array($fulfillmentData['line_items'])) {
                foreach ($fulfillmentData['line_items'] as $fItem) {
                    $grams = isset($fItem['grams']) ? (int) $fItem['grams'] : 0;
                    $qty = isset($fItem['quantity']) ? (int) $fItem['quantity'] : 1;
                    if ($grams > 0) {
                        $totalWeightKg += ($grams / 1000) * $qty;
                    }
                }
                if ($totalWeightKg > 0) {
                    $weightSource = 'fulfillment grams';
                }
            }

            // Fallback: calculer depuis le poids des produits Dolibarr (déjà importé depuis Shopify)
            if ($totalWeightKg == 0 && !empty($expedition->lines)) {
                foreach ($expedition->lines as $expLine) {
                    if (!empty($expLine->fk_product) && $expLine->qty > 0) {
                        $sqlWeight = "SELECT weight, weight_units FROM " . MAIN_DB_PREFIX . "product"
                            . " WHERE rowid = " . (int) $expLine->fk_product;
                        $resWeight = $this->db->query($sqlWeight);
                        if ($resWeight) {
                            $objWeight = $this->db->fetch_object($resWeight);
                            if ($objWeight && $objWeight->weight > 0) {
                                // Convertir en kg selon weight_units Dolibarr
                                // weight_units: -6=mg, -3=g, 0=kg, 3=tonnes, 98=oz, 99=lb
                                // Formule métrique: kg = weight * 10^(weight_units)
                                $productWeightKg = (float) $objWeight->weight;
                                $weightUnit = (int) ($objWeight->weight_units ?? 0);
                                if ($weightUnit == 98) {
                                    $productWeightKg *= 0.0283495; // oz → kg
                                } elseif ($weightUnit == 99) {
                                    $productWeightKg *= 0.453592; // lb → kg
                                } elseif ($weightUnit != 0) {
                                    // Unités métriques: pow(10, weight_units)
                                    // -6=mg (×10⁻⁶), -3=g (×10⁻³), 3=tonnes (×10³)
                                    $productWeightKg *= pow(10, $weightUnit);
                                }
                                // weight_units == 0 → déjà en kg
                                $totalWeightKg += $productWeightKg * $expLine->qty;
                            }
                            $this->db->free($resWeight);
                        }
                    }
                }
                if ($totalWeightKg > 0) {
                    $weightSource = 'Dolibarr products';
                }
            }

            if ($totalWeightKg > 0) {
                $expedition->weight = round($totalWeightKg, 4);
                $expedition->weight_units = 0; // 0 = kg in Dolibarr
                $this->log("createExpeditionFromFulfillment - Weight: "
                    . $expedition->weight . " kg (source: " . $weightSource . ") for order #" . $order->id, LOG_DEBUG);
            }

            if (empty($expedition->lines) || count($expedition->lines) == 0) {
                $this->log("createExpeditionFromFulfillment - No expedition lines could be built for order #" . $order->id . ", skipping", LOG_WARNING);
                $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
                return 0;
            }

            // Log line summary
            $totalQty = 0;
            foreach ($expedition->lines as $expLine) {
                $totalQty += $expLine->qty;
            }
            $lineCount = count($expedition->lines);
            $this->log("createExpeditionFromFulfillment - Building expedition with " . $lineCount . " lines, total qty=" . $totalQty
                . ($isPartial ? " (partial)" : " (full)") . " for order #" . $order->id, LOG_DEBUG);

            // Create the expedition
            $expeditionId = $expedition->create($user);

            if ($expeditionId < 0) {
                // Dolibarr stocke parfois l'erreur dans errors[] (array) mais pas dans error (string)
                $errorDetail = $expedition->error;
                if (empty($errorDetail) && !empty($expedition->errors)) {
                    $errorDetail = implode('; ', $expedition->errors);
                }
                if (empty($errorDetail)) {
                    $errorDetail = 'Unknown error (check expedition lines origin_line_id and fk_product)';
                }
                $this->errors[] = "Expedition create() failed: " . $errorDetail;
                $this->log("createExpeditionFromFulfillment - Failed to create expedition for order #" . $order->id . ": " . $errorDetail, LOG_ERR);
                // Release advisory lock on failure
                $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
                return -1;
            }

            // Validate the expedition (this decrements stock if stock management is active)
            $validResult = $expedition->valid($user);
            if ($validResult < 0) {
                // FIX B: après un valid() en échec, re-fetch depuis la base pour connaître l'état réel.
                // valid() peut avoir partiellement abouti (statut en base >= 1) sans retourner >= 0
                // (ex. conflit trigger tiers). Ne pas re-valider dans ce cas → continuer vers setClosed().
                $this->log("createExpeditionFromFulfillment - valid() failed for expedition #" . $expeditionId
                    . ": " . ($expedition->error ?: 'Unknown') . " — re-fetching from DB to check actual status", LOG_WARNING);
                $expeditionCheck = new Expedition($this->db);
                $expeditionCheck->fetch($expeditionId);
                if ($expeditionCheck->statut >= 1) {
                    // La validation a en réalité abouti (statut en base >= 1) — continuer vers setClosed()
                    $this->log("createExpeditionFromFulfillment - Expedition #" . $expeditionId
                        . " is actually statut=" . $expeditionCheck->statut . " in DB — valid() race condition, continuing to setClosed()", LOG_WARNING);
                    $expedition = $expeditionCheck;
                    $validResult = 1;  // Considérer comme validée
                } else {
                    // Statut 0 en base : retry sur l'objet re-fetché avec notrigger=1
                    $this->log("createExpeditionFromFulfillment - Expedition #" . $expeditionId
                        . " still statut=0 in DB — retrying valid(notrigger=1)", LOG_WARNING);
                    $validResult = $expeditionCheck->valid($user, 1);  // notrigger=1 (Expedition::valid signature: $user, $notrigger)
                    if ($validResult < 0) {
                        $this->log("createExpeditionFromFulfillment - Expedition #" . $expeditionId
                            . " validation failed even with notrigger=1: "
                            . ($expeditionCheck->error ?: 'Unknown') . " — left as draft, will be retried by catchup cron (Phase 2)", LOG_WARNING);
                        // Non-blocking : l'expédition brouillon sera rattrapée par closeUnclosedExpeditions (Phase 2)
                    } else {
                        $expedition = $expeditionCheck;
                    }
                }
            }
            if ($validResult >= 0) {
                $this->log("createExpeditionFromFulfillment - Expedition #" . $expeditionId . " validated for order #" . $order->id, LOG_DEBUG);

                // v2.2.0 FIX: Clôturer l'expédition (statut 1 → 2)
                // setClosed() gère aussi la clôture automatique de la commande si toutes les lignes sont expédiées
                $closeResult = $expedition->setClosed();
                if ($closeResult < 0) {
                    $this->log("createExpeditionFromFulfillment - Expedition #" . $expeditionId
                        . " validated but close failed: " . ($expedition->error ?: 'Unknown')
                        . " — expedition left as validated", LOG_WARNING);
                } else {
                    $this->log("createExpeditionFromFulfillment - Expedition #" . $expeditionId
                        . " closed (status=2) for order #" . $order->id, LOG_INFO);
                }
            }

            $this->log("createExpeditionFromFulfillment - Expedition created: ID=" . $expeditionId
                . " for order #" . $order->id
                . (!empty($trackingNumber) ? " tracking=" . $trackingNumber : ""), LOG_INFO);

            // ================================================================
            // POST-ACTIONS : Facturation + Clôture commande
            // Non-bloquant : l'expédition est créée, les post-actions sont best-effort
            // ================================================================
            $this->postExpeditionActions($order, $user, $isPartial);

            // Release advisory lock
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return $expeditionId;

        } catch (Exception $e) {
            $this->errors[] = "Exception creating expedition: " . $e->getMessage();
            $this->log("createExpeditionFromFulfillment - Exception for order #" . $order->id . ": " . $e->getMessage(), LOG_ERR);
            // Release advisory lock on exception
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($lockName) . "')");
            return -1;
        }
    }

    /**
     * Post-expedition actions: create invoice + close order if fully shipped.
     *
     * Non-blocking: failures are logged but do not affect the expedition result.
     * Only executes if the corresponding module settings are enabled.
     *
     * @param  Commande $order     The Dolibarr order
     * @param  User     $user      Dolibarr user
     * @param  bool     $isPartial Whether this was a partial fulfillment
     * @return void
     * @since  2.2.0
     */
    private function postExpeditionActions($order, $user, $isPartial)
    {
        global $conf;

        try {
            // 1) Auto-create invoice if not already exists and setting enabled (Story 2.2 + 12.1)
            if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_CREATE_INVOICE', 1)) {
                $invoiceResult = $this->createInvoiceFromOrder($order, $user);
                if ($invoiceResult > 0) {
                    $this->log("postExpeditionActions - Invoice #" . $invoiceResult
                        . " created for order #" . $order->id, LOG_INFO);
                } elseif ($invoiceResult == 0) {
                    $this->log("postExpeditionActions - Invoice skipped (already exists or conditions not met)"
                        . " for order #" . $order->id, LOG_DEBUG);
                } else {
                    $this->log("postExpeditionActions - Invoice creation failed (non-blocking)"
                        . " for order #" . $order->id, LOG_WARNING);
                }
            }

            // 2) Classify order as billed if invoice exists
            if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_CLASSIFY_BILLED', 1)) {
                $this->classifyOrderBilledIfInvoiced($order, $user);
            }

            // 3) Close order if ALL lines are fully shipped (not for partial fulfillments)
            // Note: Expedition::setClosed() peut déjà fermer la commande en interne,
            // la tentative est idempotente (skip si déjà clôturée). Logique centralisée
            // dans attemptAutoCloseOrder() — partagée avec le flux orders/paid (Story 38.1).
            if (!$isPartial) {
                $this->attemptAutoCloseOrder($order, $user, 'orders/fulfilled');
            }
        } catch (\Throwable $e) {
            $this->log("postExpeditionActions - Exception for order #" . $order->id
                . ": " . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Tentative de clôture automatique d'une commande entièrement expédiée.
     *
     * Logique centralisée appelée depuis le flux d'expédition (orders/fulfilled)
     * et le flux de paiement tardif (orders/paid — Story 38.1). Respecte :
     *  - le toggle DOLI2SHOP_AUTO_CLOSE_ORDER (défaut activé) ;
     *  - l'idempotence : skip si la commande n'est plus à VALIDATED/SHIPMENTONPROCESS ;
     *  - l'invariant stock virtuel : clôture seulement si toutes les lignes physiques
     *    sont expédiées (areAllPhysicalLinesShipped()).
     * Non-bloquant : un échec de cloture() n'interrompt pas le traitement appelant.
     *
     * @param  Commande $order          Commande Dolibarr (re-fetch pour un état frais)
     * @param  User     $user           Utilisateur Dolibarr
     * @param  string   $triggerContext Contexte déclencheur pour les logs (ex. topic webhook)
     * @return int  >0 = clôturée, 0 = non tentée/skip, <0 = échec cloture()
     * @since  2.3.0
     */
    private function attemptAutoCloseOrder($order, $user, $triggerContext = '')
    {
        $ctx = ($triggerContext !== '') ? ' (triggered by ' . $triggerContext . ')' : '';

        if (!$this->storeSettings->getInt($this->getStoreId(), 'AUTO_CLOSE_ORDER', 1)) {
            $this->log("attemptAutoCloseOrder - Auto-close disabled, order #" . $order->id
                . " left as-is" . $ctx, LOG_DEBUG);
            return 0;
        }

        // Re-fetch : l'expédition (setClosed) ou le paiement a pu modifier le statut
        $order->fetch($order->id);

        // Idempotence : ne clôturer que depuis un statut éligible
        if ($order->statut != Commande::STATUS_VALIDATED && $order->statut != Commande::STATUS_SHIPMENTONPROCESS) {
            $this->log("attemptAutoCloseOrder - Order #" . $order->id
                . " at status " . $order->statut . ", skipping close" . $ctx, LOG_DEBUG);
            return 0;
        }

        if (!$this->areAllPhysicalLinesShipped($order)) {
            $this->log("attemptAutoCloseOrder - Order #" . $order->id
                . " not fully shipped yet, skipping close" . $ctx, LOG_DEBUG);
            return 0;
        }

        $closeResult = $order->cloture($user);
        if ($closeResult > 0) {
            $this->log("attemptAutoCloseOrder - Order #" . $order->id
                . " closed (all lines shipped)" . $ctx, LOG_INFO);
            ActionLogger::log(
                $this->db,
                $this->entity,
                ActionLogger::TYPE_ORDER_AUTO_CLOSED,
                'commande',
                $order->id,
                ActionLogger::RESULT_SUCCESS,
                'Commande ' . $order->ref . ' clôturée automatiquement' . $ctx
            );
        } elseif ($closeResult === 0) {
            // cloture() retourne 0 si déjà STATUS_CLOSED ou droits insuffisants — non-bloquant
            $this->log("attemptAutoCloseOrder - Order #" . $order->id
                . " not closed (already closed or insufficient rights)" . $ctx, LOG_DEBUG);
        } else {
            $this->log("attemptAutoCloseOrder - Failed to close order #" . $order->id
                . ": " . ($order->error ? $order->error : 'Unknown error') . $ctx, LOG_WARNING);
            ActionLogger::log(
                $this->db,
                $this->entity,
                ActionLogger::TYPE_ORDER_AUTO_CLOSED,
                'commande',
                $order->id,
                ActionLogger::RESULT_ERROR,
                'Échec clôture auto commande ' . $order->ref . ' : ' . ($order->error ? $order->error : 'Unknown error') . $ctx
            );
        }

        return $closeResult;
    }

    /**
     * Classify order as "billed" if an invoice is linked to it.
     *
     * @param  Commande $order The Dolibarr order
     * @param  User     $user  Dolibarr user
     * @return void
     * @since  2.2.0
     */
    private function classifyOrderBilledIfInvoiced($order, $user)
    {
        global $conf;

        // Check if order is already classified as billed (idempotent — Story 12.4)
        if (!empty($order->billed)) {
            $this->log("classifyOrderBilledIfInvoiced - Order #" . $order->id
                . " already classified as billed, skipping (idempotent)", LOG_DEBUG);
            return;
        }

        // Check if an invoice exists for this order
        $sql = "SELECT ee.fk_target FROM " . MAIN_DB_PREFIX . "element_element ee"
            . " WHERE ee.sourcetype = 'commande' AND ee.fk_source = " . ((int) $order->id)
            . " AND ee.targettype = 'facture'"
            . " LIMIT 1";
        $resql = $this->db->query($sql);

        if ($resql && $this->db->num_rows($resql) > 0) {
            $result = $order->classifyBilled($user);
            if ($result > 0) {
                $this->log("classifyOrderBilledIfInvoiced - Order #" . $order->id . " classified as billed", LOG_INFO);
            } else {
                $this->log("classifyOrderBilledIfInvoiced - Failed to classify order #" . $order->id
                    . " as billed: " . ($order->error ? $order->error : 'Unknown error'), LOG_WARNING);
            }
        }
        if ($resql) {
            $this->db->free($resql);
        }
    }

    /**
     * Check if all physical (non-service) lines of an order are fully shipped.
     *
     * Uses $order->loadExpeditions() which fills $order->expeditions[line_id] = cumulated qty.
     * This is the correct way — Commande::fetch_lines() does NOT populate qty_shipped on OrderLine.
     *
     * @param  Commande $order Dolibarr order (must be fetched, lines will be loaded if needed)
     * @return bool True if all physical lines have qty_shipped >= qty ordered
     * @since  2.2.0
     */
    private function areAllPhysicalLinesShipped($order)
    {
        if (empty($order->lines)) {
            $order->fetch_lines();
        }
        // loadExpeditions remplit $order->expeditions[line_id] = qty cumulée
        $order->loadExpeditions(Expedition::STATUS_CLOSED);

        foreach ($order->lines as $line) {
            // Skip service lines (product_type=1) — not shippable
            if (isset($line->product_type) && $line->product_type == 1) {
                continue;
            }
            $lineId = $line->id;
            $qtyShipped = isset($order->expeditions[$lineId]) ? (float) $order->expeditions[$lineId] : 0;
            if ($qtyShipped < $line->qty) {
                $this->log("areAllPhysicalLinesShipped - Order #" . $order->id
                    . " line #" . $lineId . " not fully shipped: " . $qtyShipped . "/" . $line->qty, LOG_DEBUG);
                return false;
            }
        }
        return true;
    }

    /**
     * Build expedition lines for a full fulfillment (all order lines).
     *
     * @param  Expedition $expedition  The expedition object to populate
     * @param  Commande   $order       The source order with lines loaded
     * @param  int        $warehouseId Default warehouse ID
     * @return void
     * @since  2.2.0
     */
    private function buildFullExpeditionLines($expedition, $order, $warehouseId)
    {
        foreach ($order->lines as $orderLine) {
            // Skip service-only lines (product_type=1) if they have no product
            if (empty($orderLine->fk_product) && isset($orderLine->product_type) && $orderLine->product_type == 1) {
                continue;
            }

            $expedition->lines[] = $this->createExpeditionLine($orderLine, $orderLine->qty, $warehouseId);
        }
    }

    /**
     * Build expedition lines for a partial fulfillment (only fulfilled line_items).
     *
     * Matches Shopify fulfillment line_items to Dolibarr order lines via the
     * product mapping table (llx_doli2shop_products: shopifyVariantId → fk_product).
     *
     * @param  Expedition $expedition     The expedition object to populate
     * @param  Commande   $order          The source order with lines loaded
     * @param  array      $fulfillmentLineItems Shopify fulfillment line_items array
     * @param  int        $warehouseId    Default warehouse ID
     * @return void
     * @since  2.2.0
     */
    private function buildPartialExpeditionLines($expedition, $order, $fulfillmentLineItems, $warehouseId)
    {
        global $conf;

        foreach ($fulfillmentLineItems as $shopifyLine) {
            $shopifyLineQty = isset($shopifyLine['quantity']) ? (int) $shopifyLine['quantity'] : 0;
            if ($shopifyLineQty <= 0) {
                continue;
            }

            $shopifyVariantId = isset($shopifyLine['variant_id']) ? (string) $shopifyLine['variant_id'] : '';
            $shopifyProductId = isset($shopifyLine['product_id']) ? (string) $shopifyLine['product_id'] : '';
            $shopifySku = isset($shopifyLine['sku']) ? $shopifyLine['sku'] : '';

            // Find the Dolibarr product ID from the mapping table
            $dolibarrProductId = $this->findDolibarrProductFromShopifyIds($shopifyVariantId, $shopifyProductId, $shopifySku);

            // Match to order line
            $matchedOrderLine = null;
            foreach ($order->lines as $orderLine) {
                if ($dolibarrProductId > 0 && $orderLine->fk_product == $dolibarrProductId) {
                    $matchedOrderLine = $orderLine;
                    break;
                }
            }

            if ($matchedOrderLine) {
                $expedition->lines[] = $this->createExpeditionLine($matchedOrderLine, $shopifyLineQty, $warehouseId);
                $this->log("createExpeditionFromFulfillment - Matched fulfillment line: Shopify variant " . $shopifyVariantId
                    . " → Dolibarr product #" . $dolibarrProductId . " qty=" . $shopifyLineQty, LOG_DEBUG);
            } else {
                $this->log("createExpeditionFromFulfillment - Could not match fulfillment line: variant_id=" . $shopifyVariantId
                    . " product_id=" . $shopifyProductId . " sku=" . $shopifySku . " — skipping line", LOG_WARNING);
            }
        }

        // Log partial fulfillment summary
        $expeditionLineCount = count($expedition->lines);
        $orderLineCount = count($order->lines);
        $this->log("createExpeditionFromFulfillment - Partial expedition: " . $expeditionLineCount . "/" . $orderLineCount
            . " order lines matched from " . count($fulfillmentLineItems) . " fulfillment items", LOG_INFO);
    }

    /**
     * Create a single expedition line from an order line.
     *
     * @param  object $orderLine   The source order line
     * @param  float  $qty         Quantity to ship
     * @param  int    $warehouseId Warehouse ID for stock movement
     * @return object              ExpeditionLigne object (or stdClass for compatibility)
     * @since  2.2.0
     */
    private function createExpeditionLine($orderLine, $qty, $warehouseId)
    {
        $line = new ExpeditionLigne($this->db);
        $line->fk_product = isset($orderLine->fk_product) ? $orderLine->fk_product : 0;
        $line->qty = $qty;
        $line->entrepot_id = $warehouseId;
        $line->origin_line_id = isset($orderLine->id) ? $orderLine->id : 0;
        $line->rang = isset($orderLine->rang) ? $orderLine->rang : 0;
        return $line;
    }

    /**
     * Find a Dolibarr product ID from Shopify variant/product IDs using the mapping table.
     *
     * Tries in order: 1) variant_id match, 2) product_id match (simple products), 3) SKU match.
     *
     * @param  string $shopifyVariantId Shopify variant ID
     * @param  string $shopifyProductId Shopify product ID
     * @param  string $shopifySku       Shopify SKU
     * @return int                      Dolibarr product ID, or 0 if not found
     * @since  2.2.0
     */
    /**
     * Cache de résolution des codes de TVA, par « taux|pays ».
     *
     * @var array<string,string|null>
     */
    private $vatSourceCodeCache = [];

    /**
     * Accumulateur des remises globales ACROSS/ALL ou ENTITLED, groupé PAR TAUX DE TVA
     * (clé = taux formaté en chaîne à 4 décimales, cf. `formatTaxRateAccumulatorKey()` — un
     * FLOAT utilisé directement comme clé de tableau PHP est tronqué en entier, ce qui
     * fusionnerait silencieusement un taux 5,5% avec un taux 5% : la clé doit être une chaîne
     * contenant un point décimal pour ne jamais être recastée par PHP).
     *
     * Alimenté ligne par ligne par `addOrderLines()` (Task correctif review 3 couches
     * 2026-09-23 : les allocations Shopify redevenues la source de vérité) et consommé par
     * `processDiscounts()`, qui n'y touche plus jamais le contenu — il émet une ligne de
     * remise PAR taux accumulé. Réinitialisé au tout début de CHAQUE commande (première
     * instruction d'`addOrderLines()`) pour ne jamais fuir d'une commande à l'autre quand la
     * même instance de `ShopifyOrderManager` traite plusieurs commandes (import historique,
     * rattrapage CRON).
     *
     * @var array<string,float>
     */
    private $globalAcrossDiscountByTaxRate = [];

    /**
     * true si au moins une `discountAllocation` de la commande EN COURS n'a pas pu fournir son
     * `discountApplication` (requête non alignée, cache, anomalie API) — décision TOUT-OU-RIEN
     * au niveau de la COMMANDE ENTIÈRE (jamais allocation par allocation, cf. finding MEDIUM
     * review 3 couches 2026-09-23) : dans ce cas, `addOrderLines()` retombe intégralement sur
     * le comportement pré-2.5.6 (détection primaire + fallback non filtré) et
     * `processDiscounts()` n'ajoute AUCUNE ligne de remise globale.
     *
     * Réinitialisé au début de chaque commande, comme `$globalAcrossDiscountByTaxRate`.
     *
     * @var bool
     */
    private $globalAcrossDiscountApplicationMissing = false;

    /**
     * Résout le code de TVA du dictionnaire Dolibarr pour un taux et un pays donnés.
     *
     * ## Pourquoi cette méthode existe
     *
     * Le module écrivait le **code pays** (`FR`, `DE`) dans `vat_src_code`. Or ce champ porte la
     * colonne `code` du dictionnaire `llx_c_tva`, dont le cœur dit lui-même : *« a key to describe
     * vat entry, for example FR20 »*. Et c'est sur cette ligne du dictionnaire que vivent les
     * comptes comptables (`accountancy_code_sell`, `accountancy_code_buy`).
     *
     * `getTaxesFromId()` (`core/lib/functions.lib.php`) ajoute `AND t.code = '...'` dès qu'un code
     * est présent. Un code pays ne correspondant à aucune ligne, **aucun compte n'était résolu** —
     * d'où le signalement d'un client le 22/09/2026 : « parfois les comptes de TVA ne sont pas
     * renseignés ».
     *
     * ## Un code VIDE est une réponse correcte, pas un échec
     *
     * Beaucoup d'entrées du dictionnaire n'ont pas de code — les taux allemands, par exemple, sont
     * insérés sans colonne `code`, donc à `''`. Dans ce cas, laisser le champ vide est **exactement
     * ce qu'il faut** : le cœur résout alors par le seul taux. Un code faux est pire qu'un code
     * absent, puisqu'il **restreint** la recherche à une ligne inexistante.
     *
     * ⚠️ Le code n'est jamais FABRIQUÉ par concaténation (`FR` + `20`) : le dictionnaire d'un
     * client peut contenir `FR20NPR`, des entrées DOM-TOM, ou des codes propres à son pays. On le
     * **lit**.
     *
     * @param  float       $taxRate     Taux de TVA (ex. 20, 19, 5.5)
     * @param  string|null $countryCode Code ISO du pays (ex. 'FR', 'DE') ; vide = pays du vendeur
     * @return string      Code du dictionnaire, ou chaîne vide si aucun code applicable
     * @since  2.6.0
     */
    private function resolveVatSourceCode($taxRate, $countryCode)
    {
        global $mysoc;

        $countryCode = strtoupper(trim((string) $countryCode));
        if ($countryCode === '' && !empty($mysoc->country_code)) {
            $countryCode = strtoupper((string) $mysoc->country_code);
        }
        if ($countryCode === '') {
            return '';
        }

        // ⚠️ La resolution ne doit JAMAIS empecher l'import d'une commande. Sans base
        // exploitable, on rend une chaine vide : le coeur resout alors par le seul taux, ce qui
        // est exactement le comportement correct en l'absence de code.
        if (empty($this->db) || !is_object($this->db) || !method_exists($this->db, 'query')) {
            return '';
        }

        $cacheKey = (string) $taxRate . '|' . $countryCode;
        if (array_key_exists($cacheKey, $this->vatSourceCodeCache)) {
            return $this->vatSourceCodeCache[$cacheKey];
        }

        $this->vatSourceCodeCache[$cacheKey] = '';

        $sql = "SELECT t.code FROM " . MAIN_DB_PREFIX . "c_tva as t";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "c_country as c ON c.rowid = t.fk_pays";
        $sql .= " WHERE t.active = 1";
        $sql .= " AND c.code = '" . $this->db->escape($countryCode) . "'";
        $sql .= " AND t.taux = " . ((float) $taxRate);
        // Une entrée sans particularité d'abord : les codes suffixés (NPR, DOM-TOM…) désignent des
        // régimes spécifiques qu'on ne doit pas choisir à la place du régime courant.
        $sql .= " ORDER BY CASE WHEN t.code = '' THEN 0 ELSE 1 END, t.code";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('resolveVatSourceCode - lecture du dictionnaire de TVA impossible : '
                . $this->db->lasterror() . ' — code laisse vide.', LOG_WARNING);
            return '';
        }

        if ($obj = $this->db->fetch_object($resql)) {
            $this->vatSourceCodeCache[$cacheKey] = (string) $obj->code;
        } else {
            // Cas réel et légitime : une vente au taux d'un pays que le dictionnaire du client ne
            // connaît pas. On le dit, parce que c'est ce qui explique un compte de TVA vide.
            $this->log('resolveVatSourceCode - aucun taux ' . $taxRate . '% pour le pays '
                . $countryCode . ' dans le dictionnaire : le compte comptable de TVA ne pourra pas '
                . 'etre resolu pour cette ligne.', LOG_WARNING);
        }
        $this->db->free($resql);

        return $this->vatSourceCodeCache[$cacheKey];
    }

    private function findDolibarrProductFromShopifyIds($shopifyVariantId, $shopifyProductId, $shopifySku)
    {
        global $conf;

        // Try 1: Match by Shopify variant_id
        if (!empty($shopifyVariantId)) {
            $sql = "SELECT fk_product FROM " . MAIN_DB_PREFIX . "doli2shop_products";
            $sql .= " WHERE shopifyVariantId = '" . $this->db->escape($shopifyVariantId) . "'";
            $sql .= " AND entity = " . ((int) $conf->entity);

            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return (int) $obj->fk_product;
            }
            if ($resql) {
                $this->db->free($resql);
            }
        }

        // Try 2: Match by Shopify product_id (simple products without variants)
        if (!empty($shopifyProductId)) {
            $sql = "SELECT fk_product FROM " . MAIN_DB_PREFIX . "doli2shop_products";
            $sql .= " WHERE shopifyProductId = '" . $this->db->escape($shopifyProductId) . "'";
            $sql .= " AND (shopifyVariantId IS NULL OR shopifyVariantId = '')";
            $sql .= " AND entity = " . ((int) $conf->entity);

            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return (int) $obj->fk_product;
            }
            if ($resql) {
                $this->db->free($resql);
            }
        }

        // Try 3: Match by SKU on Dolibarr product
        if (!empty($shopifySku)) {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
            $sql .= " WHERE ref = '" . $this->db->escape($shopifySku) . "'";
            $sql .= " AND entity IN (" . getEntity('product') . ")";

            $resql = $this->db->query($sql);
            if ($resql && $this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return (int) $obj->rowid;
            }
            if ($resql) {
                $this->db->free($resql);
            }
        }

        return 0;
    }

    private function parseShippingTitle($title)
    {
        // Regex pour extraire transporteur et jours
        $patterns = [
            '/^(.*?)\s*-\s*(\d+)\s*(JOUR|DAY|jours|days)/i' =>  'full',
            '/^(.*?)\s*(\d+)\s*(J|D)/i' =>  'short'
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $title, $matches)) {
                $carrier = trim($matches[1]);
                $days = (int)$matches[2];

                // Nettoyage supplémentaire
                $carrier = preg_replace('/\s*(delivery|pick up|livraison|point relais)$/i', '', $carrier);

                return [
                    'carrier' =>  $carrier,
                    'days' =>  $days,
                    'original' =>  $title
                ];
            }
        }

        // Fallback si aucun pattern ne correspond
        return [
            'carrier' =>  $title,
            'days' =>  $this->config->default_delivery_days ?? 3,
            'original' =>  $title
        ];
    }

    /**
     * Obtenir l'ID du mode de paiement Dolibarr correspondant à la méthode de paiement Shopify
     *
     *
     * @param object $shopifyOrder Commande Shopify
     * @return int ID du mode de paiement Dolibarr
     */
    private function getPaymentMode($shopifyOrder)
    {
        global $conf;

        // Récupérer le mode de paiement configuré pour les transactions Shopify
        if (!empty($shopifyOrder->transactions->edges)) {
            $transaction = $shopifyOrder->transactions->edges[0]->node;
            if (!empty($transaction->paymentDetails) && !empty($transaction->paymentDetails->paymentMethodName)) {
                $paymentMethod = $transaction->paymentDetails->paymentMethodName;

                // Essayer d'obtenir le mappage personnalisé de la base de données
                require_once dirname(__FILE__) . '/paymentmethodsmapping.class.php';
                $paymentMappingObj = new PaymentMethodsMapping($this->db);
                $dolibarrPaymentId = $paymentMappingObj->getDolibarrPaymentId($paymentMethod, $this->getStoreId());

                if ($dolibarrPaymentId !== false) {
                    $this->log("Found custom payment mapping for method: " . $paymentMethod . " = " . $dolibarrPaymentId, LOG_DEBUG);
                    return $dolibarrPaymentId;
                }

                // Pour la rétrocompatibilité, code dur minimal en cas d'urgence

                // Pour la rétrocompatibilité, code dur minimal en cas d'urgence
                // (si la table n'est pas encore créée ou si la méthode n'existe pas)
                $emergencyFallback = [
                    'card' =>  6,    // CB
                    'bank' =>  2,    // Virement
                    'check' =>  7,   // Chèque
                ];

                if (isset($emergencyFallback[$paymentMethod])) {
                    $this->log("Using emergency fallback payment mapping for method: " . $paymentMethod . " = " . $emergencyFallback[$paymentMethod], LOG_DEBUG);
                    return $emergencyFallback[$paymentMethod];
                }
            }
        }

        // Mode de paiement par défaut - CB
        $this->log("Using default payment method - CB", LOG_DEBUG);
        return 6;
    }

    /**
     * Lookup shipping method ID from Shopify tracking_company name.
     * Priority: 1) Custom carrier mapping (JSON constant), 2) llx_c_shipment_mode by libelle/code.
     * Used for EXPEDITIONS (tracking_company from fulfillment data).
     *
     * @param string $trackingCompany Shopify tracking company name (e.g. "Mondial Relay")
     * @return int rowid from llx_c_shipment_mode, or 0 if not found
     * @since 2.2.0
     */
    private function getShippingMethodIdFromTrackingCompany($trackingCompany)
    {
        if (empty($trackingCompany)) {
            return 0;
        }

        // 0) Check doli2shop_shipping_rules with rule_type = 'tracking' (Story 22.1 — 2-level resolution)
        $sqlTracking = "SELECT dol_shipping_method_id FROM " . MAIN_DB_PREFIX . "doli2shop_shipping_rules"
            . " WHERE rule_type = 'tracking'"
            . " AND active = 1"
            . " AND LOWER(shopify_pattern) = LOWER('" . $this->db->escape(trim($trackingCompany)) . "')"
            . " AND entity = " . (int)$this->conf->entity;
        // Story 48-4 : filtre fk_store conditionnel (getStoreId()>0 = multi-boutique ; 0 = legacy/fallback, pas de filtre)
        if ($this->getStoreId() > 0) {
            $sqlTracking .= " AND fk_store = " . (int) $this->getStoreId();
        }
        $sqlTracking .= " LIMIT 1";
        $resqlTracking = $this->db->query($sqlTracking);
        if ($resqlTracking && ($objTracking = $this->db->fetch_object($resqlTracking))) {
            $this->db->free($resqlTracking);
            $this->log("getShippingMethodIdFromTrackingCompany - Tracking rule match: '"
                . $trackingCompany . "' → shipping method #" . $objTracking->dol_shipping_method_id, LOG_INFO);
            return (int) $objTracking->dol_shipping_method_id;
        }
        if ($resqlTracking) {
            $this->db->free($resqlTracking);
        }

        // 1) Exact match case-insensitive on libelle or code (active modes)
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_shipment_mode"
            . " WHERE active = 1"
            . " AND (LOWER(libelle) = LOWER('" . $this->db->escape($trackingCompany) . "')"
            . " OR LOWER(code) = LOWER('" . $this->db->escape($trackingCompany) . "'))"
            . " LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("getShippingMethodIdFromTrackingCompany - SQL error on exact match: "
                . $this->db->lasterror(), LOG_ERR);
        } elseif (($obj = $this->db->fetch_object($resql))) {
            $this->db->free($resql);
            $this->log("getShippingMethodIdFromTrackingCompany - Exact match: '"
                . $trackingCompany . "' → shipping method #" . $obj->rowid, LOG_INFO);
            return (int) $obj->rowid;
        } else {
            $this->db->free($resql);
        }

        // 2) Partial match: libelle contains tracking_company (active modes)
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_shipment_mode"
            . " WHERE active = 1"
            . " AND LOWER(libelle) LIKE LOWER('%" . $this->db->escape($trackingCompany) . "%')"
            . " LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("getShippingMethodIdFromTrackingCompany - SQL error on partial match: "
                . $this->db->lasterror(), LOG_ERR);
        } elseif (($obj = $this->db->fetch_object($resql))) {
            $this->db->free($resql);
            $this->log("getShippingMethodIdFromTrackingCompany - Partial match: '"
                . $trackingCompany . "' → shipping method #" . $obj->rowid, LOG_INFO);
            return (int) $obj->rowid;
        } else {
            $this->db->free($resql);
        }

        // 3) Search INACTIVE modes — log WARNING but do NOT auto-activate (admin may have intentionally disabled)
        $sql = "SELECT rowid, code, libelle FROM " . MAIN_DB_PREFIX . "c_shipment_mode"
            . " WHERE active = 0"
            . " AND (LOWER(libelle) = LOWER('" . $this->db->escape($trackingCompany) . "')"
            . " OR LOWER(code) = LOWER('" . $this->db->escape($trackingCompany) . "')"
            . " OR LOWER(libelle) LIKE LOWER('%" . $this->db->escape($trackingCompany) . "%'))"
            . " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && ($obj = $this->db->fetch_object($resql))) {
            $this->db->free($resql);
            $this->log("getShippingMethodIdFromTrackingCompany - Inactive mode found but NOT auto-activated: '"
                . $obj->code . "' (" . $obj->libelle . ") #" . $obj->rowid . " for carrier '"
                . $trackingCompany . "'. Activate it manually in Dolibarr if needed.", LOG_WARNING);
            // Do not return — fall through to Step 4 (auto-create)
        } elseif ($resql) {
            $this->db->free($resql);
        }

        // 4) Not found at all → auto-create the shipping mode with D2S_ prefix for identification
        $code = 'D2S_' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $trackingCompany));
        $code = substr($code, 0, 30); // Max length for code field
        $insertSql = "INSERT INTO " . MAIN_DB_PREFIX . "c_shipment_mode (code, libelle, active, entity)"
            . " VALUES ('" . $this->db->escape($code) . "', '" . $this->db->escape($trackingCompany) . "', 1, " . (int)$this->conf->entity . ")";
        $resInsert = $this->db->query($insertSql);
        if ($resInsert) {
            $newId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "c_shipment_mode");
            $this->log("getShippingMethodIdFromTrackingCompany - Auto-created shipping mode: '"
                . $code . "' (" . $trackingCompany . ") → #" . $newId, LOG_INFO);
            return $newId;
        }

        // Insert failed (maybe duplicate code) — log and fallback
        $this->log("getShippingMethodIdFromTrackingCompany - No match and auto-create failed for '"
            . $trackingCompany . "': " . $this->db->lasterror(), LOG_WARNING);
        return 0;
    }

    /**
     * Obtenir la méthode d'expédition Dolibarr correspondante
     *
     * @param array $parsedShipping
     * @return array
     */
    private function getDolibarrShippingMethod($parsedShipping)
    {
        $sql = "SELECT dol_shipping_method_id, delivery_days
                FROM " . MAIN_DB_PREFIX . "doli2shop_shipping_rules
                WHERE rule_type = 'title'
                AND active = 1
                AND entity = ?
                AND (LOWER(shopify_pattern) = LOWER(?) OR LOWER(shopify_pattern) = LOWER(?))";

        $sqlParams = [
            (int)$this->conf->entity,
            $parsedShipping['original'],
            $parsedShipping['carrier']
        ];
        // Story 48-4 : filtre fk_store conditionnel (getStoreId()>0 = multi-boutique ; 0 = legacy/fallback)
        if ($this->getStoreId() > 0) {
            $sql .= " AND fk_store = ?";
            $sqlParams[] = (int) $this->getStoreId();
        }

        $result = SqlUtils::executeQuery($this->db, $sql, "getting shipping method", false, $sqlParams);

        if ($result && ($obj = $this->db->fetch_object($result))) {
            $shippingMethod = [
                'id' =>  $obj->dol_shipping_method_id,
                'days' =>  $obj->delivery_days
            ];
            $this->db->free($result);
            return $shippingMethod;
        } elseif ($result) {
            $this->db->free($result);
        }

        // Fallback sur la méthode par défaut
        return [
            'id' =>  $this->config->default_shipping_method_id,
            'days' =>  $this->config->default_delivery_days
        ];
    }

    /**
     * Définit la date de livraison de la commande
     *
     * @param Commande $order
     * @param int $createdAt Timestamp de création de la commande
     * @param int $shippingDaysDelivery
     */
    private function setDeliveryDate(&$order, $createdAt, $shippingDaysDelivery = 0)
    {
        $delay = (int)$this->config->delivery_delay + (int)$shippingDaysDelivery;
        $type = $this->config->delivery_delay_type;

        if ($type === 'working') {
            $weekends = floor($delay / 5) * 2;
            $delay += $weekends;
        }

        // Repli local si date.lib.php du coeur est indisponible (le module ne declare plus la fonction).
        $order->delivery_date = function_exists('dol_time_plus_duree')
            ? dol_time_plus_duree($createdAt, $delay, 'd')
            : $createdAt + 86400 * (int) $delay;
    }

    /**
     * Get buying price (pa_ht) from product based on configuration
     * Uses PMP or cost_price depending on DOLI2SHOP_BUYING_PRICE_SOURCE setting
     *
     * @param Product $product Dolibarr product object (already fetched)
     * @return float Buying price
     * @since 2.2.0
     */
    private function getProductBuyingPrice($product)
    {
        $source = $this->storeSettings->get($this->getStoreId(), 'BUYING_PRICE_SOURCE', 'pmp');

        if ($source === 'cost_price') {
            if (!empty($product->cost_price)) {
                return (float) $product->cost_price;
            }
            // Fallback to PMP if cost_price is 0 or empty
            $this->log("getProductBuyingPrice - cost_price is empty for product #" . $product->id
                . ", fallback to PMP (" . ($product->pmp ?: 0) . ")", LOG_WARNING);
            return !empty($product->pmp) ? (float) $product->pmp : 0;
        }

        // Default: PMP with cost_price fallback
        return !empty($product->pmp) ? (float) $product->pmp : (!empty($product->cost_price) ? (float) $product->cost_price : 0);
    }

    private function setAddresses(&$order, $shopifyOrder)
    {
        // Adresse de livraison
        if ($shopifyOrder->shippingAddress) {
            // CORRECTION v2.1.5: Ajout support champ "company"
            $order->shipping_address = implode("\n", array_filter([
                !empty($shopifyOrder->shippingAddress->company) ? $shopifyOrder->shippingAddress->company : null,
                $shopifyOrder->shippingAddress->address1,
                $shopifyOrder->shippingAddress->address2
            ]));
            $order->shipping_zip = $shopifyOrder->shippingAddress->zip;
            $order->shipping_town = $shopifyOrder->shippingAddress->city;
            $order->shipping_state = $shopifyOrder->shippingAddress->province;
            $order->shipping_phone = $shopifyOrder->shippingAddress->phone;

            // Utiliser countryCodeV2 en priorité, sinon mapper le nom du pays
            $shippingCountryCode = isset($shopifyOrder->shippingAddress->countryCodeV2) ?
                $shopifyOrder->shippingAddress->countryCodeV2 :
                $shippingCountryCode = isset($shopifyOrder->shippingAddress->countryCodeV2) ?
                $shopifyOrder->shippingAddress->countryCodeV2 :
                $this->mapCountryNameToCode($shopifyOrder->shippingAddress->country);

            $shippingCountryInfo = getCountry($shippingCountryCode, 'all', $this->db);
            $order->shipping_country_id = $shippingCountryInfo['id'] ?? 0;
            $order->shipping_country_code = $shippingCountryInfo['code'] ?? $shippingCountryCode;
        }

        // Adresse de facturation
        if ($shopifyOrder->billingAddress) {
            // CORRECTION v2.1.5: Ajout support champ "company"
            $order->billing_address = implode("\n", array_filter([
                !empty($shopifyOrder->billingAddress->company) ? $shopifyOrder->billingAddress->company : null,
                $shopifyOrder->billingAddress->address1,
                $shopifyOrder->billingAddress->address2
            ]));
            $order->billing_zip = $shopifyOrder->billingAddress->zip;
            $order->billing_town = $shopifyOrder->billingAddress->city;
            $order->billing_state = $shopifyOrder->billingAddress->province;
            $order->billing_phone = $shopifyOrder->billingAddress->phone;

            // Utiliser countryCodeV2 en priorité, sinon mapper le nom du pays
            $billingCountryCode = isset($shopifyOrder->billingAddress->countryCodeV2) ?
                $shopifyOrder->billingAddress->countryCodeV2 :
                $billingCountryCode = isset($shopifyOrder->billingAddress->countryCodeV2) ?
                $shopifyOrder->billingAddress->countryCodeV2 :
                $this->mapCountryNameToCode($shopifyOrder->billingAddress->country);

            $billingCountryInfo = getCountry($billingCountryCode, 'all', $this->db);
            $order->billing_country_id = $billingCountryInfo['id'] ?? 0;
            $order->billing_country_code = $billingCountryInfo['code'] ?? $billingCountryCode;
        }
    }

    /**
    * Ajoute une ou plusieurs lignes de remise globale à la commande si nécessaire.
    *
    * Hotfix 2.5.6, corrigé par la review 3 couches du 2026-09-23 : le montant HT n'est ni
    * recalculé ici à partir d'un sous-total sur `$order->lines` (bug double-comptage 2.5.5 +
    * bug préexistant HIGH ENTITLED-sur-toutes-les-lignes), ni resommé ici depuis les
    * `discountAllocations` Shopify (ce qui portait encore le CRITICAL : double lecture avec
    * `addOrderLines()`, cf. commentaire plus bas). Cette méthode ne fait plus que LIRE
    * l'accumulateur `$this->globalAcrossDiscountByTaxRate`, rempli exclusivement par
    * `addOrderLines()` — SEUL site qui lit encore les `discountAllocations` — et émet UNE ligne
    * de remise PAR TAUX DE TVA accumulé (plus la ligne unique "taux le plus haut" d'avant ce
    * correctif).
    *
    * @param object $order Commande Dolibarr
    * @param array $shopifyOrder Commande Shopify complète
    * @param bool $taxesIncluded Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes
    *             (AC1/AC2) : true si les montants Shopify de cette boutique sont TTC, false s'ils
    *             sont déjà HT. Garanti booléen strict par le gate fail-closed de createOrder() (AC3).
    *             Non utilisé directement ici (conversion HT déjà faite par addOrderLines()) —
    *             conservé pour compatibilité de signature avec l'appelant createOrder().
    * @return void
    */
    private function processDiscounts(&$order, $shopifyOrder, $taxesIncluded)
    {
        // Si pas de remises, ne rien faire
        if (
            empty($shopifyOrder->discountApplications) ||
            empty($shopifyOrder->discountApplications->edges)
        ) {
            return;
        }

        $discountApplications = $shopifyOrder->discountApplications->edges;
        $globalDiscountNeeded = false;
        $discountDescriptions = [];

        // Pour chaque application de remise. Hotfix 2.5.6 (Task 2, Validate 2026-09-23 #2) :
        // ne s'arrête PLUS à la première remise globale trouvée (l'ancien `break` faisait
        // disparaître silencieusement toute seconde remise ACROSS/ALL ou ENTITLED simultanée —
        // le module anticipe pourtant jusqu'à 10 discountApplications par commande).
        foreach ($discountApplications as $discountEdge) {
            $discount = $discountEdge->node;

            // Les trois champs de décision sont lus DÉFENSIVEMENT : une requête GraphQL qui
            // omettrait l'un d'eux ne doit pas faire basculer l'arithmétique en silence. C'est
            // exactement ce qui s'est produit jusqu'en 2.5.5 avec `targetSelection`, absent de
            // buildOrderFieldsFragment() : la condition de remise globale comparait null à 'ALL',
            // donc elle était toujours fausse, et la remise disparaissait sans un mot.
            $allocationMethod = $discount->allocationMethod ?? null;
            $targetSelection = $discount->targetSelection ?? null;
            $targetType = $discount->targetType ?? null;

            // Un champ manquant est une ANOMALIE DE REQUÊTE, pas un cas métier : il se signale.
            // Sans ce garde-fou, la seule trace serait un « Undefined property » dans le journal
            // PHP du serveur, que personne ne relie à une remise perdue.
            foreach (['allocationMethod' => $allocationMethod, 'targetSelection' => $targetSelection, 'targetType' => $targetType] as $fieldName => $fieldValue) {
                if ($fieldValue === null) {
                    $this->log(
                        "processDiscounts - CHAMP MANQUANT dans la reponse Shopify : '" . $fieldName
                        . "' est absent de discountApplications. La remise de la commande "
                        . ($shopifyOrder->name ?? '?') . " risque de ne PAS etre appliquee. "
                        . "Verifier que la requete GraphQL demande bien ce champ.",
                        LOG_ERR
                    );
                }
            }

            // Log pour le debugging
            $this->log("Traitement remise: allocationMethod=" . $allocationMethod .
                    ", targetSelection=" . $targetSelection .
                    ", targetType=" . $targetType, LOG_DEBUG);

            // Afficher la structure de la valeur de remise pour le debug
            $this->log("Structure de la remise: " . print_r($discount->value, true), LOG_DEBUG);

            // v2.3.0: Log détaillé pour allocationMethod EACH (AC 39-1 #2)
            if ($allocationMethod === 'EACH') {
                $discountValueStr = isset($discount->value->percentage)
                    ? $discount->value->percentage . '%'
                    : (isset($discount->value->amount) ? $discount->value->amount . ' ' . ($discount->value->currencyCode ?? '') : 'N/A');
                $this->log(
                    'processDiscounts - Remise EACH détectée: allocationMethod=EACH, targetSelection=' . $targetSelection
                    . ', valeur=' . $discountValueStr,
                    LOG_DEBUG
                );
            }

            // v2.3.0: Log remise sur frais de port (AC 39-1 #2)
            if ($targetType === 'SHIPPING_LINE') {
                $discountValueStr = isset($discount->value->percentage)
                    ? $discount->value->percentage . '%'
                    : (isset($discount->value->amount) ? $discount->value->amount . ' ' . ($discount->value->currencyCode ?? '') : 'N/A');
                $this->log(
                    'processDiscounts - Remise sur frais de port (SHIPPING_LINE): valeur=' . $discountValueStr,
                    LOG_INFO
                );
            }

            // Vérifier si c'est une remise globale avec méthode ACROSS (prédicat partagé avec
            // le filtre du fallback discountAllocations d'addOrderLines() — Task 2)
            if ($this->isGlobalAcrossDiscount($allocationMethod, $targetSelection, $targetType)) {
                $globalDiscountNeeded = true;

                // Le type de remise (pourcentage / montant fixe) ne sert plus qu'à construire la
                // DESCRIPTION affichée : le montant réel vient de l'accumulateur
                // $this->globalAcrossDiscountByTaxRate, rempli par addOrderLines() (Hotfix 2.5.6,
                // correctif review 3 couches 2026-09-23) — plus du sous-total recalculé ici.
                if (isset($discount->value->percentage)) {
                    $discountPercentage = floatval($discount->value->percentage);
                    $discountDescriptions[] = "Remise de " . abs($discountPercentage) . "%";
                    $this->log("Remise en pourcentage détectée: " . $discountPercentage . "%", LOG_DEBUG);
                } elseif (isset($discount->value->amount)) {
                    $discountAmount = floatval($discount->value->amount);
                    $currencyCode = isset($discount->value->currencyCode) ? $discount->value->currencyCode : 'EUR';
                    $discountDescriptions[] = "Remise de " . abs($discountAmount) . " " . $currencyCode;
                    $this->log("Remise en montant fixe détectée: " . $discountAmount . " " . $currencyCode, LOG_DEBUG);
                } else {
                    // Type de remise inconnu — n'empêche pas de compter cette application : le
                    // montant réel vient des allocations, indépendamment de la forme de $value.
                    $this->log("Type de remise inconnu - structure: " . print_r($discount->value, true), LOG_WARNING);
                    $discountDescriptions[] = "Remise globale";
                }
            }
        }

        if (!$globalDiscountNeeded) {
            return;
        }

        // Correctif review 3 couches 2026-09-23 (CRITICAL) : le montant n'est PLUS RECALCULÉ ici.
        // Il a déjà été accumulé, ligne par ligne et PAR TAUX DE TVA, par addOrderLines() dans
        // $this->globalAcrossDiscountByTaxRate — désormais la SEULE lecture des discountAllocations
        // pour ce calcul. Avant ce correctif, addOrderLines() ET processDiscounts() lisaient
        // chacun les allocations indépendamment : quand la détection PRIMAIRE d'addOrderLines()
        // (discountedUnitPriceSet) reflétait déjà la remise ACROSS (app tierce, Shopify Functions),
        // son garde `$remisePercent == 0` ne se déclenchait jamais, donc son fallback filtré
        // n'excluait rien — mais processDiscounts() la resommait quand même ici : double-comptage
        // (252,00 au lieu de 126,00). Un seul site de lecture supprime la possibilité même du
        // défaut, au lieu de la neutraliser après coup.
        if ($this->globalAcrossDiscountApplicationMissing) {
            // Repli TOUT-OU-RIEN au niveau de la COMMANDE ENTIÈRE (finding MEDIUM review 3 couches
            // 2026-09-23 : un repli par ALLOCATION individuelle pouvait produire une somme
            // partielle, ni l'ancien ni le nouveau comportement). Déjà détecté et journalisé en
            // détail par addOrderLines() : ici, on se contente d'honorer la même décision — AUCUNE
            // ligne de remise globale, seul le remise_percent posé par le fallback non filtré
            // d'addOrderLines() représente les remises (comportement pré-2.5.6, jamais de sur-remise).
            $this->log(
                "processDiscounts - Repli tout-ou-rien actif pour la commande " . ($shopifyOrder->name ?? '?')
                . " ('discountApplication' manquant sur au moins une discountAllocation, deja "
                . "journalise en detail par addOrderLines) : aucune ligne de remise globale ajoutee ici.",
                LOG_ERR
            );
            return;
        }

        $discountDescription = implode(' + ', $discountDescriptions);

        // Ajouter les codes de réduction s'ils existent
        if (!empty($shopifyOrder->discountCodes) && is_array($shopifyOrder->discountCodes)) {
            $codeString = implode(', ', $shopifyOrder->discountCodes);
            if (!empty($codeString)) {
                $discountDescription .= " (Code: " . $codeString . ")";
            }
        }

        // Ne garder que les taux dont le cumul est réellement positif (bruit d'arrondi exclu) —
        // les clés de l'accumulateur sont des CHAÎNES formatées (formatTaxRateAccumulatorKey()),
        // jamais des floats utilisés directement comme clé de tableau (qui seraient tronqués en
        // entier par PHP — 5,5 % fusionnerait silencieusement avec 5 %).
        $amountsByTaxRate = [];
        foreach ($this->globalAcrossDiscountByTaxRate as $taxRateKey => $amountHT) {
            if ($amountHT > 0.005) {
                $amountsByTaxRate[] = ['taxRate' => (float) $taxRateKey, 'amountHT' => $amountHT];
            }
        }

        if (empty($amountsByTaxRate)) {
            // Finding HIGH (review 3 couches 2026-09-23) : une remise globale est ANNONCÉE
            // (discountApplications) mais AUCUNE discountAllocation Shopify ne la représente sur
            // les lignes — avant ce correctif, ce cas se terminait en silence
            // (`$totalDiscountHT <= 0 → return`, sans le moindre message). La remise est réellement
            // perdue : on le dit, pour qu'un support puisse le rapprocher d'une commande sous-remisée.
            $this->log(
                "processDiscounts - Remise globale ANNONCEE (" . $discountDescription . ") pour la "
                . "commande " . ($shopifyOrder->name ?? '?') . " mais AUCUNE discountAllocation Shopify "
                . "ne la represente sur les lignes : remise perdue, aucune ligne ajoutee. Payload "
                . "Shopify a investiguer (incoherence discountApplications/discountAllocations ?).",
                LOG_ERR
            );
            return;
        }

        if (empty($order->note_private)) {
            $order->note_private = '';
        }

        // Une ligne de remise globale PAR TAUX DE TVA accumulé (corrige l'approximation
        // "taux le plus haut" préexistante — finding LOW/MEDIUM de la même review : une remise
        // ACROSS/ALL sur une commande à TVA mixte, ex. 5,5 % + 20 %, ventilait jusqu'ici TOUTE la
        // remise au taux le plus élevé, faussant la comptabilité TVA de la ligne au taux réduit).
        foreach ($amountsByTaxRate as $entry) {
            $taxRate = $entry['taxRate'];
            $amountHT = $entry['amountHT'];

            $orderline = new OrderLine($this->db);
            $orderline->desc = $discountDescription;
            $orderline->subprice = -$amountHT; // Montant négatif pour une remise, déjà HT
            $orderline->qty = 1;
            $orderline->tva_tx = $taxRate;
            $orderline->product_type = 0;
            $orderline->fk_product = 0; // Pas de produit associé
            $orderline->remise_percent = 0;
            $orderline->info_bits = 0;
            $orderline->rang = 999; // Positionner à la fin
            $orderline->special_code = 3; // Code spécial pour les remises

            // 🔴 JAMAIS le code pays ici : ce champ porte le code du dictionnaire de TVA, et
            // c'est lui qui conditionne la resolution du compte comptable.
            $orderline->vat_src_code = $this->resolveVatSourceCode(
                $taxRate,
                isset($order->billing_country_code) ? $order->billing_country_code : ''
            );

            $order->lines[] = $orderline;

            $this->log("Remise globale ajoutee (taux {$taxRate}%): " . $discountDescription . " ( -" . $amountHT . "€ HT / -" . ($amountHT * (1 + ($taxRate / 100))) . "€ TTC)", LOG_DEBUG);
        }

        $order->note_private .= "\nRemise globale appliquée: " . $discountDescription;
    }

    /**
     * Prédicat partagé identifiant une remise globale ACROSS (ALL ou ENTITLED, LINE_ITEM).
     *
     * Utilisé À LA FOIS par `processDiscounts()` (détection + description) ET par le fallback
     * `discountAllocations` d'`addOrderLines()` (exclusion des allocations déjà représentées par
     * la ligne de remise globale) — Hotfix 2.5.6, Task 2. Une seule méthode partagée : dupliquer
     * la condition inline aux deux endroits est exactement le type de mutation de FORME qui
     * réintroduit le double-comptage en silence si les deux copies divergent un jour.
     *
     * @param string|null $allocationMethod Discount->allocationMethod (lu défensivement, `?? null`)
     * @param string|null $targetSelection  Discount->targetSelection (lu défensivement, `?? null`)
     * @param string|null $targetType       Discount->targetType (lu défensivement, `?? null`)
     * @return bool
     */
    private function isGlobalAcrossDiscount($allocationMethod, $targetSelection, $targetType)
    {
        return $allocationMethod === 'ACROSS'
            && ($targetSelection === 'ALL' || $targetSelection === 'ENTITLED')
            && $targetType === 'LINE_ITEM';
    }

    /**
     * Formate un taux de TVA en clé de tableau PHP STABLE, jamais tronquée.
     *
     * Un FLOAT utilisé directement comme clé de tableau PHP est irrémédiablement tronqué à
     * l'entier par le moteur (`$arr[5.5]` stocke en réalité sous la clé entière `5`) — cela
     * fusionnerait silencieusement une TVA à 5,5 % avec une TVA à 5 %, un défaut qu'aucun test
     * de montant seul ne révèle si le jeu de données ne mélange pas ces deux taux précis. Une
     * chaîne à décimales FIXES (ex. `'5.5000'`) n'est en revanche jamais recastée par PHP (seules
     * les chaînes qui ressemblent à un entier décimal valide le sont), et le nombre fixe de
     * décimales regroupe de façon stable des taux identiques malgré le bruit de calcul flottant
     * (`array_reduce()` sur plusieurs `taxLines`).
     *
     * @param float $taxRate Taux de TVA en pourcentage (ex. 20, 5.5)
     * @return string Clé stable, ex. '20.0000', '5.5000'
     */
    private function formatTaxRateAccumulatorKey($taxRate)
    {
        return number_format(round((float) $taxRate, 4), 4, '.', '');
    }

    /**
     * Détecte si au moins une `discountAllocation` de la commande (TOUTES lignes brutes
     * Shopify confondues, avant tout filtrage pourboire/bundle) n'a pas pu fournir son
     * `discountApplication` — requête non alignée, cache, anomalie API.
     *
     * Décision TOUT-OU-RIEN au niveau de la COMMANDE, jamais allocation par allocation (finding
     * MEDIUM, review 3 couches 2026-09-23) : un repli par allocation individuelle pouvait
     * produire une somme PARTIELLE (certaines allocations filtrées par le prédicat partagé,
     * d'autres non, selon que LEUR PROPRE `discountApplication` était présent) — un état que ni
     * le comportement pré-2.5.6 ni le nouveau ne représentent correctement. Ici, la moindre
     * anomalie sur N'IMPORTE QUELLE ligne de la commande fait retomber TOUTE la commande sur le
     * comportement pré-2.5.6 (jamais de sur-remise, seule sous-évaluation possible — repli connu
     * et déjà toléré avant ce hotfix).
     *
     * @param array $lineItems Edges bruts (`$shopifyOrder->lineItems->edges`), AVANT filtrage
     *                          pourboire/bundle : une allocation orpheline reste une anomalie de
     *                          requête même sur une ligne qui sera ensuite ignorée/transformée.
     * @return bool
     */
    private function anyDiscountAllocationMissingApplicationField($lineItems)
    {
        foreach ($lineItems as $edge) {
            $line = $edge->node;
            if (empty($line->discountAllocations)) {
                continue;
            }
            foreach ($line->discountAllocations as $allocation) {
                $allocationApplication = $allocation->discountApplication ?? null;
                $allocAllocationMethod = $allocationApplication->allocationMethod ?? null;
                $allocTargetSelection = $allocationApplication->targetSelection ?? null;
                $allocTargetType = $allocationApplication->targetType ?? null;

                if ($allocAllocationMethod === null || $allocTargetSelection === null || $allocTargetType === null) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Referme une commande qui avait été rouverte pour un late fulfillment alors
     * qu'une expédition existait déjà (race condition orders/updated + orders/fulfilled).
     *
     * Sans ce mécanisme, la commande reste en STATUS_VALIDATED indéfiniment et seul
     * le CRON FulfillmentCatchup la referme (~30 min de retard).
     *
     * Non-bloquant : tout échec est loggué en WARNING.
     *
     * @param  Commande $order The Dolibarr order (avec statut déjà rouvert)
     * @param  User     $user  Dolibarr user
     * @return void
     * @since  2.2.2
     */
    private function reCloseReopenedOrder($order, $user)
    {
        if (!$this->storeSettings->getInt($this->getStoreId(), 'AUTO_CLOSE_ORDER', 1)) {
            $this->log("reCloseReopenedOrder - Order #" . $order->id
                . " left as reopened (DOLI2SHOP_AUTO_CLOSE_ORDER disabled)", LOG_DEBUG);
            return;
        }

        // Re-fetch pour avoir l'état le plus à jour (l'autre webhook peut avoir touché la commande)
        $order->fetch($order->id);

        if ($order->statut == Commande::STATUS_CLOSED) {
            $this->log("reCloseReopenedOrder - Order #" . $order->id
                . " already closed, skipping", LOG_DEBUG);
            return;
        }

        if (!$this->areAllPhysicalLinesShipped($order)) {
            $this->log("reCloseReopenedOrder - Order #" . $order->id
                . " not fully shipped, leaving open", LOG_DEBUG);
            return;
        }

        $closeResult = $order->cloture($user);
        if ($closeResult > 0) {
            $this->log("reCloseReopenedOrder - Order #" . $order->id
                . " re-closed after late fulfillment webhook race condition", LOG_INFO);
        } else {
            $this->log("reCloseReopenedOrder - Failed to re-close order #" . $order->id
                . ": " . ($order->error ?: 'Unknown error'), LOG_WARNING);
        }
    }

    /**
     * Détecte si une ligne Shopify est une sous-ligne technique injectée par une app de bundles/cadeaux.
     *
     * Les apps tierces (USH Bundles, Frequently Bought Together, EasyGift, etc.) ajoutent
     * des sous-lignes dont les `customAttributes` portent une clé préfixée par `_` (convention
     * Shopify pour les propriétés privées/système). Un composant à écarter est une ligne portant
     * une telle clé `_`-préfixée ET réellement facturée à 0/0 (`discountedUnitPriceSet` et
     * `originalUnitPriceSet` tous deux absents ou à 0). D'autres apps (ex. « mix & match ») utilisent
     * la même convention de clé `_`-préfixée sur des lignes de vrais produits facturables : le prix
     * (> 0) prime toujours sur la présence de l'attribut et fait considérer la ligne comme normale.
     *
     * @param object $line Node lineItem GraphQL
     * @return bool true si la ligne est identifiée comme bundle/cadeau (composant à 0/0 à écarter)
     * @since 2.2.2
     */
    private function isBundleOrGiftLine($line)
    {
        if (empty($line->customAttributes) || !is_array($line->customAttributes)) {
            return false;
        }

        // Fix 2.4.3 (2/2) — garde prix : une ligne facturable (prix remisé OU d'origine > 0)
        // n'est jamais un composant de bundle/cadeau à écarter, même avec une clé _-préfixée
        // (cas commande #51675 : app "mix & match" facturable à 11,50 €).
        $discounted = isset($line->discountedUnitPriceSet) ? (float) $line->discountedUnitPriceSet->shopMoney->amount : 0;
        $original   = isset($line->originalUnitPriceSet) ? (float) $line->originalUnitPriceSet->shopMoney->amount : 0;
        if ($discounted > 0 || $original > 0) {
            return false; // ligne facturable (vrai produit, ex. bundle "mix & match") → jamais un composant à écarter
        }

        foreach ($line->customAttributes as $attr) {
            $key = isset($attr->key) ? (string) $attr->key : '';
            if ($key !== '' && strpos($key, '_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ajoute les lignes de commande à la commande Dolibarr.
     *
     * Correctif review 3 couches 2026-09-23 : SEUL site qui lit encore les `discountAllocations`
     * Shopify pour la remise globale ACROSS/ALL ou ENTITLED. Pour chaque ligne PRODUIT réellement
     * créée (pas le pourboire, pas une ligne bundle/cadeau ignorée), dès que TOUTES les
     * `discountAllocations` de la COMMANDE portent `discountApplication` :
     *  - le `remise_percent` de la ligne est reconstruit UNIQUEMENT depuis les allocations NON
     *    globales de CETTE ligne (même formule que l'ancien fallback FIX #233 v2.1.7), en IGNORANT
     *    la détection primaire `discountedUnitPriceSet` dès que la ligne porte des allocations —
     *    elle peut représenter la MÊME remise globale que la ligne ACROSS (c'est le CRITICAL de
     *    cette review : additionner les deux, c'est le double-comptage) ;
     *  - la part globale (allocations dont `discountApplication` satisfait `isGlobalAcrossDiscount()`)
     *    est convertie en HT avec le taux de TVA DÉJÀ RÉSOLU de la ligne ($taxRate, repli produit
     *    Dolibarr compris — ce qui corrige le finding HIGH : l'ancien calcul lisait `taxLines` brut
     *    sans ce repli, surestimant de 20 % la remise HT des commandes marketplace) et accumulée
     *    dans `$this->globalAcrossDiscountByTaxRate`, groupée PAR TAUX DE TVA.
     *
     * @param Commande $order
     * @param array $lineItems
     * @param bool $taxesIncluded Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes
     *             (AC1/AC2) : true si les montants Shopify de cette boutique sont TTC, false s'ils
     *             sont déjà HT. Garanti booléen strict par le gate fail-closed de createOrder() (AC3).
     */
    private function addOrderLines(&$order, $lineItems, $taxesIncluded)
    {
        // v2.2.2 — Stratégie de gestion des lignes bundle/cadeau injectées par les apps Shopify tierces
        // 0 = ignore (défaut), 1 = import comme commentaire, 2 = import comme ligne normale
        $bundleBehavior = $this->storeSettings->getInt($this->getStoreId(), 'BUNDLE_LINES_BEHAVIOR', 0);

        // Réinitialisation obligatoire au début de CHAQUE commande (correctif review 3 couches
        // 2026-09-23) : la même instance de ShopifyOrderManager traite plusieurs commandes de
        // suite (import historique, rattrapage CRON) — sans ce reset, l'accumulateur d'une
        // commande fuiterait dans la ligne de remise globale de la commande suivante.
        $this->globalAcrossDiscountByTaxRate = [];
        $this->globalAcrossDiscountApplicationMissing = $this->anyDiscountAllocationMissingApplicationField($lineItems);

        if ($this->globalAcrossDiscountApplicationMissing) {
            $this->log(
                "addOrderLines - CHAMP MANQUANT dans la reponse Shopify : 'discountApplication' "
                . "absent d'au moins une discountAllocation de la commande "
                . (isset($order->ref_client) ? $order->ref_client : '?') . ". Repli TOUT-OU-RIEN sur "
                . "le comportement pre-2.5.6 pour TOUTE la commande : aucune ligne de remise globale "
                . "ne sera ajoutee par processDiscounts(), seul remise_percent (fallback historique, "
                . "non filtre) represente les remises sur les lignes concernees. Verifier que la "
                . "requete GraphQL demande bien discountApplication { allocationMethod "
                . "targetSelection targetType } sur CHAQUE bloc discountAllocations des lignes.",
                LOG_ERR
            );
        }

        foreach ($lineItems as $index => $edge) {
            $line = $edge->node;
            // Vérifier si c'est une ligne de pourboire (Tip)
            if ($line->title === 'Tip') {
                $this->log("Traitement d'une ligne de pourboire: " . $line->discountedUnitPriceSet->shopMoney->amount . " " . $line->discountedUnitPriceSet->shopMoney->currencyCode, LOG_DEBUG);

                // Récupérer le prix brut Shopify (TTC ou HT selon $taxesIncluded — AC4, le
                // nommage ne présume plus de la nature du montant)
                $priceFromShopify = isset($line->discountedUnitPriceSet) ?
                    $line->discountedUnitPriceSet->shopMoney->amount : 0;

                // Déterminer le taux de TVA (généralement 0% pour les pourboires)
                $taxRate = 0;

                // Convertir en prix HT (AC1/AC2 : uniquement si $taxesIncluded === true)
                $priceHT = self::computeHtFromShopifyAmount($priceFromShopify, $taxRate, $taxesIncluded);

                $orderline = new OrderLine($this->db);
                $orderline->desc = $line->title;
                $orderline->subprice = $priceHT;
                $orderline->qty = $line->quantity;
                $orderline->tva_tx = $taxRate;
                $orderline->product_type = 1;  // Type 1 pour service
                $orderline->fk_product = $this->config->tip_product_id;

                // 🔴 JAMAIS le code pays ici (cf. resolveVatSourceCode()). Le pourboire porte un
                // taux de 0 : on resout donc le code du taux 0 du pays, qui existe dans certains
                // dictionnaires et pas dans d'autres — et une absence rend simplement une chaine
                // vide, ce qui est le comportement correct.
                $orderline->vat_src_code = $this->resolveVatSourceCode(
                    $taxRate,
                    isset($order->billing_country_code) ? $order->billing_country_code : ''
                );

                $orderline->localtax1_tx = 0;
                $orderline->localtax2_tx = 0;
                $orderline->remise_percent = 0;
                $orderline->info_bits = 0;
                $orderline->fk_remise_except = 0;
                $orderline->special_code = 0;
                $orderline->fk_fournprice = null;

                // FIX #151 v2.1.3: Récupérer infos produit tip si configuré
                if (!empty($this->config->tip_product_id)) {
                    require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                    $product = new Product($this->db);

                    if ($product->fetch($this->config->tip_product_id) > 0) {
                        $orderline->pa_ht = $this->getProductBuyingPrice($product);
                        $orderline->accountancy_code_sell = $product->accountancy_code_sell ?? '';
                        $orderline->accountancy_code_buy = $product->accountancy_code_buy ?? '';
                        $orderline->product_type = $product->type ?? 1;
                        $orderline->fk_unit = $product->fk_unit ?? null;
                        $this->log("Infos produit Tip récupérées - ID: {$this->config->tip_product_id}", LOG_DEBUG);
                    } else {
                        $orderline->pa_ht = 0;
                        $orderline->fk_unit = null;
                    }
                } else {
                    $orderline->pa_ht = 0;
                    $orderline->fk_unit = null;
                }

                $orderline->product_label = $line->title;
                $orderline->array_options = array();
                $orderline->rang = 995; // Pour positionner après les produits mais avant les frais de port

                $order->lines[] = $orderline;

                // Passer à la prochaine ligne
                continue;
            }

            // v2.2.2 — Filtrage configurable des lignes bundle/cadeau (apps tierces Shopify)
            if ($this->isBundleOrGiftLine($line)) {
                if ($bundleBehavior === 0) {
                    $this->log("Ligne bundle/cadeau ignorée: '" . $line->title . "' (customAttributes avec clé préfixée _)", LOG_INFO);
                    continue;
                }

                if ($bundleBehavior === 1) {
                    $this->log("Ligne bundle/cadeau importée comme commentaire: '" . $line->title . "'", LOG_INFO);

                    $attrSummary = array();
                    foreach ($line->customAttributes as $attr) {
                        if (!empty($attr->key)) {
                            $attrSummary[] = $attr->key . '=' . (isset($attr->value) ? $attr->value : '');
                        }
                    }
                    $commentDesc = '[Bundle/Cadeau Shopify] ' . $line->title;
                    if (!empty($attrSummary)) {
                        $commentDesc .= ' (' . implode(', ', $attrSummary) . ')';
                    }

                    $orderline = new OrderLine($this->db);
                    $orderline->desc = $commentDesc;
                    $orderline->subprice = 0;
                    $orderline->qty = $line->quantity;
                    $orderline->tva_tx = 0;
                    $orderline->localtax1_tx = 0;
                    $orderline->localtax2_tx = 0;
                    $orderline->remise_percent = 0;
                    $orderline->info_bits = 0;
                    $orderline->fk_remise_except = 0;
                    $orderline->special_code = 3; // Code commentaire dans Dolibarr
                    $orderline->fk_fournprice = null;
                    $orderline->fk_product = 0;
                    $orderline->product_type = 1;
                    $orderline->pa_ht = 0;
                    $orderline->fk_unit = null;
                    $orderline->product_label = $line->title;
                    $orderline->array_options = array();
                    $orderline->rang = 990;

                    $order->lines[] = $orderline;
                    continue;
                }

                // bundleBehavior === 2 : import comme ligne normale (pas de filtrage, on retombe dans le traitement standard)
                $this->log("Ligne bundle/cadeau importée comme ligne normale: '" . $line->title . "'", LOG_INFO);
            }

            // v2.2.0: Trouver le produit Dolibarr associé (SKU → mapping table → fallback)
            $fk_product = 0;

            // Extraire SKU (peut être à $line->sku ou $line->variant->sku selon la source GraphQL)
            $lineSku = '';
            if (!empty($line->sku)) {
                $lineSku = $line->sku;
            } elseif (!empty($line->variant->sku)) {
                $lineSku = $line->variant->sku;
            }

            // Extraire IDs Shopify pour fallback sur table de mapping doli2shop_products
            $shopifyVariantId = '';
            $shopifyProductId = '';
            if (!empty($line->variant->id)) {
                $shopifyVariantId = str_replace('gid://shopify/ProductVariant/', '', $line->variant->id);
            }
            if (!empty($line->product->id)) {
                $shopifyProductId = str_replace('gid://shopify/Product/', '', $line->product->id);
            }

            // Stratégie 1: Match par SKU (ref produit Dolibarr)
            if (!empty($lineSku)) {
                $fk_product = $this->getProductId($lineSku);
                if ($fk_product > 0) {
                    $this->log("Produit trouvé par SKU '" . $lineSku . "': ID=" . $fk_product, LOG_DEBUG);
                }
            }

            // Stratégie 2: Fallback sur table de mapping doli2shop_products (variant_id → product_id → SKU)
            if ($fk_product == 0 && (!empty($shopifyVariantId) || !empty($shopifyProductId) || !empty($lineSku))) {
                $fk_product = $this->findDolibarrProductFromShopifyIds($shopifyVariantId, $shopifyProductId, $lineSku);
                if ($fk_product > 0) {
                    $this->log("Produit trouvé par mapping Shopify (variant=" . $shopifyVariantId . ", product=" . $shopifyProductId . "): ID=" . $fk_product, LOG_DEBUG);
                }
            }

            // Log si aucun produit trouvé
            if ($fk_product == 0) {
                $this->log("Aucun produit Dolibarr trouvé pour ligne '" . $line->title . "' (SKU=" . ($lineSku ?: 'vide') . ", variantId=" . ($shopifyVariantId ?: 'vide') . ", productId=" . ($shopifyProductId ?: 'vide') . ")", LOG_WARNING);
            }

            // Calcul du taux TVA depuis Shopify taxLines
            $taxRate = 0;
            $taxFromShopify = false;
            if (!empty($line->taxLines) && (is_array($line->taxLines) || is_object($line->taxLines))) {
                $taxRate = array_reduce((array) $line->taxLines, function ($c, $t) {
                    return $c + ($t->rate * 100);
                }, 0);
                if ($taxRate > 0) {
                    $taxFromShopify = true;
                }
            }

            // FIX v2.2.0: Fallback TVA si taxLines vide (commandes marketplace ex: Nature & Découvertes)
            // Utiliser le taux TVA du produit Dolibarr comme référence
            if (!$taxFromShopify && $fk_product > 0) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $tmpProduct = new Product($this->db);
                if ($tmpProduct->fetch($fk_product) > 0 && $tmpProduct->tva_tx > 0) {
                    $taxRate = $tmpProduct->tva_tx;
                    $this->log("TVA fallback depuis produit Dolibarr #" . $fk_product . ": " . $taxRate . "%"
                        . " (taxLines Shopify vide — commande marketplace ?)", LOG_WARNING);
                }
            }

            // MEDIUM (re-review 2026-09-23) : taxLines Shopify vide ET aucun produit Dolibarr
            // trouvé (fk_product=0) → aucun repli possible, taxRate reste à 0% en silence sans ce
            // log. Les montants HT (ligne ET, le cas échéant, part globale accumulée) sont alors
            // potentiellement faux — signalé explicitement, en nommant la commande.
            if (!$taxFromShopify && $fk_product == 0) {
                $this->log("addOrderLines - taux de TVA non resolu, montants HT potentiellement faux (ligne '"
                    . $line->title . "', commande " . (isset($order->ref_client) ? $order->ref_client : '?')
                    . ") : taxLines Shopify vide ET aucun produit Dolibarr trouve (fk_product=0)", LOG_WARNING);
            }

            // Récupérer les prix bruts Shopify (TTC ou HT selon $taxesIncluded — AC4, le nommage
            // ne présume plus de la nature du montant)
            $originalPriceFromShopify = isset($line->originalUnitPriceSet) ?
                $line->originalUnitPriceSet->shopMoney->amount : 0;
            $discountedPriceFromShopify = isset($line->discountedUnitPriceSet) ?
                $line->discountedUnitPriceSet->shopMoney->amount : $originalPriceFromShopify;

            // Calcul des prix HT (AC1/AC2 : uniquement si $taxesIncluded === true — avant ce
            // correctif la division était inconditionnelle, ce qui sous-évaluait ~17% chaque
            // commande d'une boutique réglée en prix hors taxes)
            $originalPriceHT = self::computeHtFromShopifyAmount($originalPriceFromShopify, $taxRate, $taxesIncluded);
            $discountedPriceHT = self::computeHtFromShopifyAmount($discountedPriceFromShopify, $taxRate, $taxesIncluded);

            // Logs de débogage
            $this->log("Prix pour {$line->title}: Original(brut Shopify)={$originalPriceFromShopify}, Remisé(brut Shopify)={$discountedPriceFromShopify}, TVA={$taxRate}%, taxesIncluded=" . ($taxesIncluded ? 'true' : 'false') . ($taxFromShopify ? '' : ' (fallback Dolibarr)'), LOG_DEBUG);
            $this->log("Prix HT optimisés: Original={$originalPriceHT}, Remisé={$discountedPriceHT}", LOG_DEBUG);

            // Calcul de la remise — détection PRIMAIRE (discountedUnitPriceSet vs originalUnitPriceSet)
            $remisePercent = 0;
            if ($originalPriceHT > 0) {
                $diff = $originalPriceHT - $discountedPriceHT;
                if ($diff > 0.005) { // Seuil de 0.5 centime
                    $remisePercent = round(100 * $diff / $originalPriceHT, 2);
                    $this->log("Remise calculée: {$remisePercent}%", LOG_DEBUG);
                }
            }

            if ($this->globalAcrossDiscountApplicationMissing) {
                // Repli TOUT-OU-RIEN pour TOUTE la commande (déjà journalisé une fois en LOG_ERR
                // au début d'addOrderLines()) : comportement pré-2.5.6 intégral, IDENTIQUE à
                // l'ancien FIX #233 v2.1.7 — fallback NON FILTRÉ, qui somme TOUTES les allocations
                // sans distinguer leur discountApplication (jamais de sur-remise, cf. story).
                if ($remisePercent == 0 && !empty($line->discountAllocations)) {
                    $totalAllocated = 0;
                    foreach ($line->discountAllocations as $allocation) {
                        if (!isset($allocation->allocatedAmountSet->shopMoney->amount)) {
                            continue;
                        }
                        $totalAllocated += floatval($allocation->allocatedAmountSet->shopMoney->amount);
                    }
                    if ($totalAllocated > 0 && $originalPriceFromShopify > 0) {
                        // Garde quantité (LOW, re-review 2026-09-23) : une ligne à quantité <= 0
                        // porterait un dénominateur nul (division par zéro, PHP renvoie INF sans
                        // exception) — remise_percent resterait à 0, jamais une valeur aberrante.
                        if ($line->quantity > 0) {
                            $totalLineFromShopify = $originalPriceFromShopify * $line->quantity;
                            $remisePercent = round(100 * $totalAllocated / $totalLineFromShopify, 2);
                            $this->log("Remise via discountAllocations (repli tout-ou-rien): {$remisePercent}% ({$totalAllocated} EUR sur {$totalLineFromShopify} EUR)", LOG_DEBUG);
                        } else {
                            $this->log("addOrderLines - quantite <= 0 sur une ligne portant une allocation de remise (repli tout-ou-rien), commande "
                                . (isset($order->ref_client) ? $order->ref_client : '?')
                                . " : remise_percent force a 0 (division evitee)", LOG_WARNING);
                        }
                    }
                }
            } elseif (!empty($line->discountAllocations)) {
                // Correctif review 3 couches 2026-09-23 (CRITICAL) : dès que la commande entière a
                // toutes ses discountApplication présentes, CETTE ligne devient la source de
                // vérité pour sa propre remise — on IGNORE volontairement le $remisePercent de la
                // détection PRIMAIRE calculé ci-dessus, qu'il soit nul ou non. Avant ce correctif,
                // une app tierce/Shopify Function qui reflétait déjà la remise ACROSS dans
                // `discountedUnitPriceSet` faisait porter `remise_percent` par la détection
                // PRIMAIRE ET la ligne globale de processDiscounts() resommait la même allocation :
                // double-comptage (252,00 au lieu de 126,00 sur le cas de régression #1025).
                $totalAllocatedNonGlobal = 0.0;
                $totalAllocatedGlobal = 0.0;

                foreach ($line->discountAllocations as $allocation) {
                    if (!isset($allocation->allocatedAmountSet->shopMoney->amount)) {
                        continue;
                    }

                    $allocationApplication = $allocation->discountApplication ?? null;
                    $allocAllocationMethod = $allocationApplication->allocationMethod ?? null;
                    $allocTargetSelection = $allocationApplication->targetSelection ?? null;
                    $allocTargetType = $allocationApplication->targetType ?? null;
                    $amount = floatval($allocation->allocatedAmountSet->shopMoney->amount);

                    if ($this->isGlobalAcrossDiscount($allocAllocationMethod, $allocTargetSelection, $allocTargetType)) {
                        // Remise globale ACROSS/ALL ou ENTITLED : représentée par la ligne globale
                        // de processDiscounts(), JAMAIS ici — sinon double-comptage.
                        $totalAllocatedGlobal += $amount;
                    } else {
                        // Toute autre nature (EACH, EXPLICIT, remise ciblée...) : SEULE
                        // représentation, comme l'ancien fallback FIX #233 v2.1.7.
                        $totalAllocatedNonGlobal += $amount;
                    }
                }

                $remisePercent = 0;
                if ($totalAllocatedNonGlobal > 0 && $originalPriceFromShopify > 0) {
                    // Garde quantité (LOW, re-review 2026-09-23) : même raison que le repli
                    // tout-ou-rien ci-dessus — quantité <= 0 → dénominateur nul évité.
                    if ($line->quantity > 0) {
                        // Même formule que l'ancien fallback (ratio insensible à $taxesIncluded tant
                        // que numérateur et dénominateur partagent la même base brute Shopify).
                        $totalLineFromShopify = $originalPriceFromShopify * $line->quantity;
                        $remisePercent = round(100 * $totalAllocatedNonGlobal / $totalLineFromShopify, 2);
                        $this->log("Remise via discountAllocations (non globale): {$remisePercent}% ({$totalAllocatedNonGlobal} EUR sur {$totalLineFromShopify} EUR)", LOG_DEBUG);
                    } else {
                        $this->log("addOrderLines - quantite <= 0 sur une ligne portant une allocation de remise non globale, commande "
                            . (isset($order->ref_client) ? $order->ref_client : '?')
                            . " : remise_percent force a 0 (division evitee)", LOG_WARNING);
                    }
                }

                if ($totalAllocatedGlobal > 0.005) {
                    // Conversion HT avec le taux de TVA DÉJÀ RÉSOLU de CETTE ligne ($taxRate,
                    // repli produit Dolibarr compris ci-dessus) — corrige le finding HIGH : l'ancien
                    // calcul (computeGlobalAcrossDiscountAmountHT(), supprimée) lisait `taxLines`
                    // brut SANS ce repli, surestimant de 20% la remise HT des commandes
                    // marketplace à `taxLines` vide sur une boutique TTC.
                    $globalAmountHT = self::computeHtFromShopifyAmount($totalAllocatedGlobal, $taxRate, $taxesIncluded);
                    $rateKey = $this->formatTaxRateAccumulatorKey($taxRate);
                    if (!isset($this->globalAcrossDiscountByTaxRate[$rateKey])) {
                        $this->globalAcrossDiscountByTaxRate[$rateKey] = 0.0;
                    }
                    $this->globalAcrossDiscountByTaxRate[$rateKey] += $globalAmountHT;
                    $this->log("Remise globale accumulee (taux {$taxRate}%): +{$globalAmountHT} EUR HT", LOG_DEBUG);
                }
            }
            // Sinon (ligne sans discountAllocations du tout) : $remisePercent reste la valeur de
            // la détection PRIMAIRE calculée ci-dessus — comportement inchangé.

            // Création de la ligne de commande
            $orderline = new OrderLine($this->db);
            $orderline->desc = $line->title;
            $orderline->subprice = $originalPriceHT;
            $orderline->qty = $line->quantity;
            $orderline->tva_tx = $taxRate;
            $orderline->product_type = 0;
            $orderline->fk_product = $fk_product;

            // Code pays pour TVA
            // 🔴 JAMAIS le code pays ici : ce champ porte le code du dictionnaire de TVA, et
            // c'est lui qui conditionne la resolution du compte comptable.
            $orderline->vat_src_code = $this->resolveVatSourceCode(
                $taxRate,
                isset($order->billing_country_code) ? $order->billing_country_code : ''
            );
            $orderline->localtax1_tx = 0;
            $orderline->localtax2_tx = 0;
            $orderline->remise_percent = $remisePercent;
            $orderline->info_bits = 0;
            $orderline->fk_remise_except = 0;
            $orderline->rang = -1;
            $orderline->special_code = 0;
            $orderline->fk_fournprice = null;

            // FIX #151 v2.1.3: Récupérer toutes les informations produit Dolibarr
            if ($fk_product > 0) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $product = new Product($this->db);

                if ($product->fetch($fk_product) > 0) {
                    // === ESSENTIELS (comptabilité + gestion) ===
                    $orderline->pa_ht = $this->getProductBuyingPrice($product);
                    $orderline->accountancy_code_sell = $product->accountancy_code_sell ?? '';
                    $orderline->accountancy_code_buy = $product->accountancy_code_buy ?? '';
                    $orderline->product_type = $product->type ?? 0;
                    $orderline->fk_unit = $product->fk_unit ?? null;

                    // === TRAÇABILITÉ ===
                    if (!empty($product->barcode)) {
                        $orderline->barcode = $product->barcode;
                        $orderline->fk_barcode_type = $product->fk_barcode_type ?? 0;
                    }

                    // === INTÉGRATIONS TIERCES ===
                    if (!empty($product->ref_ext)) {
                        $orderline->ref_ext = $product->ref_ext;
                    }

                    // === LOGISTIQUE (poids/dimensions) ===
                    if (!empty($product->weight)) {
                        $orderline->weight = $product->weight;
                        $orderline->weight_units = $product->weight_units ?? 0;
                    }
                    if (!empty($product->length)) {
                        $orderline->length = $product->length;
                        $orderline->length_units = $product->length_units ?? 0;
                    }
                    if (!empty($product->surface)) {
                        $orderline->surface = $product->surface;
                        $orderline->surface_units = $product->surface_units ?? 0;
                    }
                    if (!empty($product->volume)) {
                        $orderline->volume = $product->volume;
                        $orderline->volume_units = $product->volume_units ?? 0;
                    }

                    $this->log("Infos produit récupérées - ID: {$fk_product}, pa_ht: {$orderline->pa_ht}, type: {$orderline->product_type}", LOG_DEBUG);
                } else {
                    // Fallback si produit non trouvé
                    $orderline->pa_ht = 0;
                    $this->log("Produit ID {$fk_product} introuvable lors du fetch, infos par défaut", LOG_WARNING);
                }
            } else {
                // Pas de produit associé (ligne manuelle Shopify)
                $orderline->pa_ht = 0;
                $this->log("Ligne sans produit Dolibarr associé (SKU: " . ($lineSku ?: 'vide') . ", title: " . $line->title . ")", LOG_INFO);
            }

            $orderline->product_label = $line->title;
            $orderline->array_options = array();
            $orderline->rang = $index + 1; // Positionner selon l'ordre d'arrivée

            $order->lines[] = $orderline;
        }
    }

    /**
     * Ajoute les lignes de frais de port à la commande Dolibarr
     *
     * @param Commande $order
     * @param array $shippingLines
     * @param bool $taxesIncluded Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes
     *             (AC1/AC2) : true si les montants Shopify de cette boutique sont TTC, false s'ils
     *             sont déjà HT. Garanti booléen strict par le gate fail-closed de createOrder() (AC3).
     */
    private function addShippingLines(&$order, $shippingLines, $taxesIncluded)
    {
        foreach ($shippingLines as $edge) {
            $line = $edge->node;
            $taxRate = array_reduce($line->taxLines, function ($c, $t) {
                return $c + ($t->rate * 100);
            }, 0);

            // Récupérer les prix bruts Shopify (TTC ou HT selon $taxesIncluded — AC4, le nommage
            // ne présume plus de la nature du montant ; même division inconditionnelle que le
            // site 1 avant ce correctif)
            $originalPriceFromShopify = isset($line->originalPriceSet) ?
                $line->originalPriceSet->shopMoney->amount : 0;
            $discountedPriceFromShopify = isset($line->discountedPriceSet) ?
                $line->discountedPriceSet->shopMoney->amount : $originalPriceFromShopify;

            // Calcul des prix HT (AC1/AC2 : uniquement si $taxesIncluded === true)
            $originalPriceHT = self::computeHtFromShopifyAmount($originalPriceFromShopify, $taxRate, $taxesIncluded);
            $discountedPriceHT = self::computeHtFromShopifyAmount($discountedPriceFromShopify, $taxRate, $taxesIncluded);

            // Logs de débogage
            $this->log("Prix pour {$line->title}: Original(brut Shopify)={$originalPriceFromShopify}, Remisé(brut Shopify)={$discountedPriceFromShopify}, taxesIncluded=" . ($taxesIncluded ? 'true' : 'false'), LOG_DEBUG);
            $this->log("Prix HT optimisés: Original={$originalPriceHT}, Remisé={$discountedPriceHT}", LOG_DEBUG);

            // Calculer remise avec sécurité contre erreurs d'arrondi
            $remisePercent = 0;
            if ($originalPriceHT > 0) {
                $diff = $originalPriceHT - $discountedPriceHT;
                // Si différence > 0.005€ (0.5 centime), considérer comme remise
                if ($diff > 0.005) {
                    $remisePercent = round(100 * $diff / $originalPriceHT, 2);
                    $this->log("Remise shipping calculée: {$remisePercent}% (diff:{$diff})", LOG_DEBUG);
                }
            }

            $orderline = new OrderLine($this->db);
            $orderline->desc = $line->title;
            $orderline->subprice = $originalPriceHT;
            $orderline->qty = 1;
            $orderline->tva_tx = $taxRate;
            $orderline->product_type = 1;  // Type 1 pour service
            $orderline->fk_product = $this->config->shipping_product_id;

            // Définir code source TVA pour pays européens
            // 🔴 JAMAIS le code pays ici : ce champ porte le code du dictionnaire de TVA, et
            // c'est lui qui conditionne la resolution du compte comptable.
            $orderline->vat_src_code = $this->resolveVatSourceCode(
                $taxRate,
                isset($order->billing_country_code) ? $order->billing_country_code : ''
            );

            $orderline->localtax1_tx = 0;
            $orderline->localtax2_tx = 0;
            $orderline->remise_percent = $remisePercent;
            $orderline->info_bits = 0;
            $orderline->fk_remise_except = 0;
            $orderline->rang = -1;
            $orderline->special_code = 0;
            $orderline->fk_fournprice = null;

            // FIX #151 v2.1.3: Récupérer infos produit shipping si configuré
            if (!empty($this->config->shipping_product_id)) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $product = new Product($this->db);

                if ($product->fetch($this->config->shipping_product_id) > 0) {
                    $orderline->pa_ht = $this->getProductBuyingPrice($product);
                    $orderline->accountancy_code_sell = $product->accountancy_code_sell ?? '';
                    $orderline->accountancy_code_buy = $product->accountancy_code_buy ?? '';
                    $orderline->product_type = $product->type ?? 1;
                    $orderline->fk_unit = $product->fk_unit ?? null;
                    $this->log("Infos produit Shipping récupérées - ID: {$this->config->shipping_product_id}", LOG_DEBUG);
                } else {
                    $orderline->pa_ht = 0;
                    $orderline->fk_unit = null;
                }
            } else {
                $orderline->pa_ht = 0;
                $orderline->fk_unit = null;
            }

            $orderline->product_label = $line->title;
            $orderline->array_options = array();
            $orderline->rang = 990; // vers la fin de la commande

            $order->lines[] = $orderline;
        }
    }

    /**
    * Récupère l'ID du produit Dolibarr à partir de la référence
    *
    * @param string $sku
    * @return int
    */
    private function getProductId($sku)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE ref = ?";
        $this->log("Product ID SQL Query for SKU: " . $sku, LOG_DEBUG);
        $resql = SqlUtils::executeQuery($this->db, $sql, "getting product ID", false, [$sku]);
        $productId = 0;

        if ($resql && ($obj = $this->db->fetch_object($resql))) {
            $productId = $obj->rowid;
            $this->log("Product found: ID = " . $productId . " for SKU: " . $sku, LOG_DEBUG);
            $this->db->free($resql);
        } elseif ($resql) {
            $this->log("No product found for SKU: " . $sku, LOG_WARNING);
            $this->db->free($resql);
        } else {
            $this->log("SQL error when searching for SKU: " . $sku . " - " . $this->db->lasterror(), LOG_ERR);
        }

        return $productId;
    }

    /**
     * Mappe le statut financier Shopify vers le statut Dolibarr.
     *
     * NOTE INTENTIONNELLE (AC 39-2 #4) : mapOrderStatus() n'est PAS utilisé dans createOrder()
     * (ligne ~704 : STATUS_DRAFT hardcodé). Ce choix est délibéré :
     * - La création initialise toujours en DRAFT pour garantir l'exécution propre des hooks Dolibarr
     * - valid() est appelé immédiatement après pour déclencher les triggers de stock et les hooks
     * - Passer directement à STATUS_VALIDATED sans valid() supprimerait les mouvements de stock
     *   (STOCK_CALCULATE_ON_VALIDATE_ORDER) et les triggers inter-modules (ProductionInterne)
     * Cette méthode reste utilisée pour les mises à jour de statut ultérieures (webhooks orders/paid).
     *
     * @param string $shopifyStatus Statut financier Shopify (PAID, PENDING, VOIDED, etc.)
     * @return int Statut Dolibarr correspondant
     * @since 2.0.0
     */
    private function mapOrderStatus($shopifyStatus)
    {
        $statusMap = [
            'PAID' =>  Commande::STATUS_VALIDATED,
            'PARTIALLY_PAID' =>  Commande::STATUS_VALIDATED,
            'PENDING' =>  Commande::STATUS_DRAFT,
            'VOIDED' =>  Commande::STATUS_CANCELED
        ];
        return $statusMap[$shopifyStatus] ?? Commande::STATUS_DRAFT;
    }

    /**
     * Mappe le statut fulfillment Shopify (enum OrderDisplayFulfillmentStatus) vers la
     * valeur locale `dolOrderFulfillment`.
     *
     * Story 51-2 (T3.4, CORRECTION VALIDATE P-MEDIUM) : le changelog Shopify (~03/07/2026)
     * ajoute la valeur `FULFILLMENT_NOT_REQUIRED` (commande 100% service/digital, aucun
     * fulfillment attendu). Sans mapping explicite, cette valeur tombait silencieusement
     * dans le fallback 'pending' du dictionnaire fermé — mappée désormais vers 'not_required',
     * une valeur locale dédiée qui NE FIGURE PAS dans les filtres
     * IN ('fulfilled','partial','complete','partially_fulfilled') du CRON de rattrapage
     * (class/shopifyfulfillmentcatchupcron.class.php:326,484,672) : une commande 'not_required'
     * ne déclenche donc jamais de création d'expédition ni de clôture forcée.
     *
     * @param string $shopifyStatus Statut fulfillment Shopify (FULFILLED, PARTIALLY_FULFILLED, UNFULFILLED, FULFILLMENT_NOT_REQUIRED...)
     * @return string Valeur locale dolOrderFulfillment
     * @since 2.0.0
     * @version     2.6.0 Story 51-2 : ajout mapping FULFILLMENT_NOT_REQUIRED
     */
    private function mapFulfillmentStatus($shopifyStatus)
    {
        $statusMap = [
            'FULFILLED' =>  'complete',
            'PARTIALLY_FULFILLED' =>  'partial',
            'UNFULFILLED' =>  'pending',
            'FULFILLMENT_NOT_REQUIRED' => 'not_required'
        ];
        return $statusMap[$shopifyStatus] ?? 'pending';
    }
    
    /**
     * Configuration adaptative du code client selon le modèle Dolibarr
     * CORRECTION v2.0.31: Gestion intelligente ErrorCustomerCodeRequired
     *
     * @param Societe $soc                 Objet société Dolibarr
     * @param object  $shopifyCustomer      Client Shopify
     * @return void
     * @since 2.0.31
     */
    private function setClientCode($soc, $shopifyCustomer)
    {
        global $conf;
        
        $this->log("=== DÉBUT setClientCode() ===", LOG_INFO);
        
        // Détecter le modèle de génération configuré
        $codeClientModel = getDolGlobalString('SOCIETE_CODECLIENT_ADDON');
        
        $this->log("Modèle de génération détecté: '" . $codeClientModel . "'", LOG_INFO);
        
        // Extraire l'ID Shopify propre (sans préfixe GraphQL)
        $shopifyCustomerId = '';
        if (isset($shopifyCustomer->id)) {
            $shopifyCustomerId = str_replace('gid://shopify/Customer/', '', $shopifyCustomer->id);
            $this->log("ID Shopify extrait: '" . $shopifyCustomerId . "'", LOG_INFO);
        } else {
            $this->log("ATTENTION: Aucun ID Shopify trouvé dans l'objet customer", LOG_WARNING);
        }
        
        // Stratégie adaptative selon le modèle
        if (strpos($codeClientModel, 'leopard') !== false || empty($codeClientModel)) {
            $this->log("BRANCHE: Modèle leopard ou vide détecté", LOG_INFO);
            // Modèle leopard (SANS génération auto) ou pas de modèle : utiliser l'ID Shopify
            if (!empty($shopifyCustomerId)) {
                $soc->code_client = 'SH' . $shopifyCustomerId;
                $this->log("[OK] Code client fixé (leopard): " . $soc->code_client, LOG_INFO);
            } else {
                // Fallback : génération basée sur timestamp
                $soc->code_client = 'SH' . date('YmdHis') . rand(100, 999);
                $this->log("[WARNING] Code client fallback timestamp: " . $soc->code_client, LOG_WARNING);
            }
        } else {
            $this->log("BRANCHE: Modèle avec génération automatique: " . $codeClientModel, LOG_INFO);
            // Modèles monkey, elephant ou personnalisés : tentative génération automatique

            // v2.1.6-fix: Vérifier si code existant valide AVANT de le modifier
            $existingCodeInvalid = empty($soc->code_client) || $soc->code_client == -1 || $soc->code_client == '-1';
            $this->log("Code existant: '" . $soc->code_client . "', invalide: " . ($existingCodeInvalid ? 'OUI' : 'NON'), LOG_INFO);

            // Si création nouvelle société OU code existant invalide, forcer la régénération
            if (!$soc->id || $existingCodeInvalid) {
                if ($soc->id && $existingCodeInvalid) {
                    $this->log("Société existante (ID: " . $soc->id . ") avec code INVALIDE (" . $soc->code_client . "), régénération nécessaire", LOG_WARNING);
                } else {
                    $this->log("Nouvelle société, appel get_codeclient()", LOG_INFO);
                }

                $soc->code_client = -1;
                $soc->get_codeclient($soc, 0);

                // Vérifier si la génération a réussi
                if (empty($soc->code_client) || $soc->code_client == -1) {
                    // Fallback vers ID Shopify si génération échouée
                    if (!empty($shopifyCustomerId)) {
                        $soc->code_client = 'SH' . $shopifyCustomerId;
                        $this->log("[WARNING] Génération automatique échouée, fallback ID Shopify: " . $soc->code_client, LOG_WARNING);
                    } else {
                        // Dernier recours : timestamp unique
                        $soc->code_client = 'SH' . date('YmdHis') . rand(100, 999);
                        $this->log("[WARNING] Génération automatique échouée, fallback timestamp: " . $soc->code_client, LOG_WARNING);
                    }
                } else {
                    $this->log("[OK] Génération automatique réussie: " . $soc->code_client, LOG_INFO);
                }
            } else {
                $this->log("Société existante (ID: " . $soc->id . "), code VALIDE conservé: " . $soc->code_client, LOG_INFO);
            }
        }
        
        $this->log("=== Code client FINAL: '" . $soc->code_client . "' pour " . doli2shop_mask_email($soc->email) . " ===", LOG_INFO);
    }

    // =============================================================================
    // SYSTÈME CONTACTS/ADRESSES HISTORIQUE (v2.1.5)
    // =============================================================================

    /**
     * Normalise une adresse pour comparaison (détection doublons)
     *
     * @param array|object $address Adresse Shopify (shippingAddress ou billingAddress, array ou stdClass)
     * @return string Adresse normalisée (minuscules, espaces unifiés)
     */
    private function normalizeAddress($address)
    {
        if (empty($address)) {
            return '';
        }

        // Cast défensif : accepter stdClass (json_decode) ou array
        if (is_object($address)) {
            $address = (array) $address;
        }

        $parts = [
            $address['address1'] ?? '',
            $address['address2'] ?? '',
            $address['zip'] ?? '',
            $address['city'] ?? '',
            $address['countryCodeV2'] ?? ''
        ];

        // Convertir en minuscules + supprimer espaces multiples + trim
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', implode('|', $parts))));

        return $normalized;
    }

    /**
     * Calcule le hash MD5 d'une adresse (pour détection doublons rapide)
     *
     * @param array|object $address Adresse Shopify (array ou stdClass)
     * @return string Hash MD5
     */
    private function getAddressHash($address)
    {
        return md5($this->normalizeAddress($address));
    }

    /**
     * Récupère l'ID pays Dolibarr depuis le code ISO
     *
     * @param string $countryCode Code pays ISO (ex: "FR", "US")
     * @return int ID pays Dolibarr (0 si non trouvé)
     */
    private function getCountryIdFromCode($countryCode)
    {
        if (empty($countryCode)) {
            return 0;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country";
        $sql .= " WHERE code = '" . $this->db->escape($countryCode) . "'";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return (int)$obj->rowid;
        }

        return 0;
    }

    /**
     * Résout le code pays ISO-2 d'une adresse Shopify (#284)
     *
     * Accepte les variantes de schéma : `countryCodeV2` (GraphQL), `country_code` (REST),
     * `countryCode`, puis retombe sur le nom de pays `country` mappé en ISO-2.
     *
     * @param array $address Adresse Shopify (déjà castée en array)
     * @return string Code pays ISO-2 ('' si indéterminable)
     * @since 2.3.0
     */
    private function resolveAddressCountryCode($address)
    {
        foreach (array('countryCodeV2', 'country_code', 'countryCode') as $countryField) {
            if (!empty($address[$countryField])) {
                return $address[$countryField];
            }
        }
        if (!empty($address['country'])) {
            return $this->mapCountryNameToCode($address['country']);
        }
        return '';
    }

    /**
     * Récupère l'email d'un Tiers Dolibarr
     *
     * Utilisé pour recopier l'email sur les contacts/adresses créés depuis une commande
     * Shopify (les adresses Shopify ne portent pas d'email — #284).
     *
     * @param int $societeId ID du Tiers Dolibarr
     * @return string Email du Tiers ('' si absent ou Tiers introuvable)
     * @since 2.3.0
     */
    private function getSocieteEmail($societeId)
    {
        if (empty($societeId)) {
            return '';
        }

        $sql = "SELECT email FROM " . MAIN_DB_PREFIX . "societe";
        $sql .= " WHERE rowid = " . ((int) $societeId);
        $sql .= " AND entity IN (" . getEntity('societe') . ")";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return !empty($obj->email) ? $obj->email : '';
        }
        if ($resql) {
            $this->db->free($resql);
        }

        return '';
    }

    /**
     * Récupère l'ID du type de contact pour un élément donné
     *
     * @param string $element Type d'élément ('commande', 'facture', etc.)
     * @param string $code Code du type de contact ('SHIPPING', 'BILLING', etc.)
     * @param string $source 'external' ou 'internal'
     * @return int|false ID du type de contact ou false si non trouvé
     */
    private function getContactTypeId($element, $code, $source = 'external')
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_type_contact";
        $sql .= " WHERE element = '" . $this->db->escape($element) . "'";
        $sql .= " AND code = '" . $this->db->escape($code) . "'";
        $sql .= " AND source = '" . $this->db->escape($source) . "'";
        $sql .= " AND active = 1";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return (int)$obj->rowid;
        }

        $this->log("Type de contact non trouvé : element=$element, code=$code, source=$source", LOG_WARNING);
        return false;
    }

    /**
     * Sauvegarde le hash d'une adresse dans les extrafields du contact
     *
     * @param int $contactId ID du contact Dolibarr
     * @param string $addressHash Hash MD5 de l'adresse
     * @return bool True si succès, false sinon
     */
    private function saveAddressHash($contactId, $addressHash)
    {
        if (empty($contactId) || empty($addressHash)) {
            $this->log("saveAddressHash: paramètres invalides (contactId=$contactId, addressHash=$addressHash)", LOG_DEBUG);
            return false;
        }

        // Vérifier si l'entrée extrafields existe
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "socpeople_extrafields";
        $sql .= " WHERE fk_object = " . ((int) $contactId);

        $resql = $this->db->query($sql);

        if ($resql && $this->db->num_rows($resql) > 0) {
            // UPDATE
            $sql_update = "UPDATE " . MAIN_DB_PREFIX . "socpeople_extrafields";
            $sql_update .= " SET address_hash = '" . $this->db->escape($addressHash) . "'";
            $sql_update .= " WHERE fk_object = " . ((int) $contactId);

            $result = $this->db->query($sql_update);
        } else {
            // INSERT
            $sql_insert = "INSERT INTO " . MAIN_DB_PREFIX . "socpeople_extrafields";
            $sql_insert .= " (fk_object, address_hash)";
            $sql_insert .= " VALUES (" . ((int) $contactId) . ", '" . $this->db->escape($addressHash) . "')";

            $result = $this->db->query($sql_insert);
        }

        if ($result) {
            $this->log("Hash adresse sauvegardé pour contact #$contactId : $addressHash", LOG_DEBUG);
            return true;
        } else {
            $this->log("Erreur sauvegarde hash adresse pour contact #$contactId : " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Recherche un contact existant par hash d'adresse
     *
     * @param int $societeId ID de la société Dolibarr
     * @param string $addressHash Hash MD5 de l'adresse normalisée
     * @return int|false ID du contact si trouvé, false sinon
     * @since 2.1.5
     */
    private function findExistingContactByAddressHash($societeId, $addressHash)
    {
        if (empty($addressHash)) {
            return false;
        }

        $sql = "SELECT sp.rowid";
        $sql .= " FROM " . MAIN_DB_PREFIX . "socpeople sp";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "socpeople_extrafields spe ON sp.rowid = spe.fk_object";
        $sql .= " WHERE sp.fk_soc = " . ((int) $societeId);
        $sql .= " AND spe.address_hash = '" . $this->db->escape($addressHash) . "'";
        $sql .= " AND sp.entity IN (" . getEntity('socpeople') . ")";
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);
        if ($result) {
            if ($this->db->num_rows($result) > 0) {
                $obj = $this->db->fetch_object($result);
                $this->log("Contact existant trouvé via hash #" . $obj->rowid . " pour société #$societeId", LOG_DEBUG);
                return (int) $obj->rowid;
            }
        } else {
            $this->log("Erreur recherche contact par hash : " . $this->db->lasterror(), LOG_ERR);
        }

        return false;
    }

    /**
     * Crée un nouveau contact pour une adresse
     *
     * @param int $societeId ID de la société Dolibarr
     * @param array $address Tableau d'adresse Shopify (address1, address2, city, zip, province, country, etc.)
     * @param string $type Type de contact ('SHIPPING' ou 'BILLING')
     * @return int|false ID du contact créé, false en cas d'erreur
     * @since 2.1.5
     */
    private function createContact($societeId, $address, $type)
    {
        global $user;

        if (empty($societeId) || empty($address)) {
            $this->log("Impossible de créer contact : société ou adresse vide", LOG_WARNING);
            return false;
        }

        // Cast défensif : accepter stdClass (json_decode) ou array
        if (is_object($address)) {
            $address = (array) $address;
        }

        require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

        $contact = new Contact($this->db);
        $contact->socid = $societeId;

        // Informations nominatives (support snake_case REST + camelCase GraphQL)
        $contact->lastname = !empty($address['last_name']) ? $address['last_name'] : (!empty($address['lastName']) ? $address['lastName'] : '');
        $contact->firstname = !empty($address['first_name']) ? $address['first_name'] : (!empty($address['firstName']) ? $address['firstName'] : '');

        // v2.1.6-fix: Détection et extraction des civilités
        $civilityMapping = array(
            'madame' => 'MME',
            'mme' => 'MME',
            'mme.' => 'MME',
            'monsieur' => 'MR',
            'mr' => 'MR',
            'mr.' => 'MR',
            'm.' => 'MR',
            'mademoiselle' => 'MLE',
            'mlle' => 'MLE',
            'mlle.' => 'MLE',
            'ms' => 'MLE',
            'ms.' => 'MLE',
            'mrs' => 'MME',
            'mrs.' => 'MME',
        );

        // Vérifier si lastname ou firstname contient une civilité
        $detectedCivility = '';
        foreach (array('lastname', 'firstname') as $field) {
            $value = strtolower(trim($contact->$field));
            if (isset($civilityMapping[$value])) {
                $detectedCivility = $civilityMapping[$value];
                $contact->$field = ''; // Vider le champ car c'était une civilité
                $this->log("Civilité détectée dans $field: '$value' → code '$detectedCivility'", LOG_INFO);
                break;
            }
        }

        // Assigner la civilité si détectée
        if (!empty($detectedCivility)) {
            $contact->civility_code = $detectedCivility;
        }

        // CORRECTION v2.3.0 (#285): le `company` Shopify reste sur le Tiers, jamais sur le contact.
        // Le champ `poste` ne doit contenir qu'un poste/fonction explicite (non fourni par Shopify),
        // sinon il reste vide. L'ancien comportement (v2.1.5) polluait `poste` avec le nom de société.

        // Si pas de nom/prénom, utiliser le name complet ou la company
        if (empty($contact->lastname) && empty($contact->firstname)) {
            if (!empty($address['name'])) {
                // v2.1.6-fix: Extraire civilité du name aussi
                $nameParts = explode(' ', trim($address['name']));
                $firstPart = strtolower($nameParts[0]);

                if (isset($civilityMapping[$firstPart])) {
                    // Premier mot est une civilité
                    if (empty($contact->civility_code)) {
                        $contact->civility_code = $civilityMapping[$firstPart];
                        $this->log("Civilité détectée dans name: '$firstPart' → code '{$contact->civility_code}'", LOG_INFO);
                    }
                    array_shift($nameParts); // Retirer la civilité
                }

                // Répartir le reste entre prénom et nom
                if (count($nameParts) >= 2) {
                    $contact->firstname = $nameParts[0];
                    $contact->lastname = implode(' ', array_slice($nameParts, 1));
                } elseif (count($nameParts) == 1) {
                    $contact->lastname = $nameParts[0];
                }
            } elseif (!empty($address['company'])) {
                $contact->lastname = $address['company'];
            }
        }

        // Adresse complète (support snake_case REST + camelCase GraphQL)
        $addr1 = !empty($address['address1']) ? $address['address1'] : '';
        $addr2 = !empty($address['address2']) ? $address['address2'] : '';
        $contact->address = $addr1;
        if (!empty($addr2)) {
            $contact->address .= ($contact->address ? "\n" : '') . $addr2;
        }

        $contact->zip = !empty($address['zip']) ? $address['zip'] : '';
        $contact->town = !empty($address['city']) ? $address['city'] : '';
        $contact->state_id = 0;

        // Pays (#284) : GraphQL fournit `countryCodeV2` (ISO-2), REST `country_code`.
        $countryCode = $this->resolveAddressCountryCode($address);
        if (!empty($countryCode)) {
            $countryId = $this->getCountryIdFromCode($countryCode);
            if ($countryId > 0) {
                $contact->country_id = $countryId;
            } else {
                $this->log("createContact - Pays Shopify '$countryCode' introuvable dans c_country, country_id laissé vide", LOG_WARNING);
            }
        }

        // Contact
        $contact->phone_pro = !empty($address['phone']) ? $address['phone'] : '';

        // Email (#284) : les adresses Shopify ne portent pas d'email → on le reprend du Tiers,
        // qui a été créé/mis à jour depuis customer.email. Nécessaire pour Colissimo & co.
        $contact->email = $this->getSocieteEmail($societeId);

        // Statut
        $contact->statut = 1; // Actif

        // Création
        $contactId = $contact->create($user);
        if ($contactId > 0) {
            $this->log("Contact $type créé #$contactId pour société #$societeId : " . doli2shop_mask_name($contact->firstname) . " " . doli2shop_mask_name($contact->lastname), LOG_INFO);

            // Sauvegarder le hash pour détection future
            $addressHash = $this->getAddressHash($address);
            if ($addressHash) {
                $this->saveAddressHash($contactId, $addressHash);
            }

            return $contactId;
        } else {
            $this->log("Erreur création contact $type pour société #$societeId : " . $contact->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Crée ou retrouve un contact de livraison
     *
     * @param int $societeId ID de la société Dolibarr
     * @param array|object $shippingAddress Adresse de livraison Shopify (array ou stdClass)
     * @return int|false ID du contact, false en cas d'erreur
     * @since 2.1.5
     */
    private function createOrFindShippingContact($societeId, $shippingAddress)
    {
        if (empty($shippingAddress)) {
            $this->log("Adresse de livraison vide, aucun contact créé", LOG_DEBUG);
            return false;
        }

        // Vérifier si un contact avec cette adresse existe déjà
        $addressHash = $this->getAddressHash($shippingAddress);
        if ($addressHash) {
            $existingContactId = $this->findExistingContactByAddressHash($societeId, $addressHash);
            if ($existingContactId) {
                $this->log("Contact de livraison existant trouvé #$existingContactId pour société #$societeId", LOG_INFO);
                return $existingContactId;
            }
        }

        // Créer nouveau contact
        $this->log("Création nouveau contact de livraison pour société #$societeId", LOG_INFO);
        return $this->createContact($societeId, $shippingAddress, 'SHIPPING');
    }

    /**
     * Crée ou retrouve un contact de facturation
     *
     * @param int $societeId ID de la société Dolibarr
     * @param array|object $billingAddress Adresse de facturation Shopify (array ou stdClass)
     * @return int|false ID du contact, false en cas d'erreur
     * @since 2.1.5
     */
    private function createOrFindBillingContact($societeId, $billingAddress)
    {
        if (empty($billingAddress)) {
            $this->log("Adresse de facturation vide, aucun contact créé", LOG_DEBUG);
            return false;
        }

        // Vérifier si un contact avec cette adresse existe déjà
        $addressHash = $this->getAddressHash($billingAddress);
        if ($addressHash) {
            $existingContactId = $this->findExistingContactByAddressHash($societeId, $addressHash);
            if ($existingContactId) {
                $this->log("Contact de facturation existant trouvé #$existingContactId pour société #$societeId", LOG_INFO);
                return $existingContactId;
            }
        }

        // Créer nouveau contact
        $this->log("Création nouveau contact de facturation pour société #$societeId", LOG_INFO);
        return $this->createContact($societeId, $billingAddress, 'BILLING');
    }

    /**
     * Associe un contact à une commande
     *
     * @param int $orderId ID de la commande Dolibarr
     * @param int $contactId ID du contact
     * @param string $type Type de contact ('SHIPPING' ou 'BILLING')
     * @return bool True si succès, false sinon
     * @since 2.1.5
     */
    private function associateContactToOrder($orderId, $contactId, $type)
    {
        if (empty($orderId) || empty($contactId)) {
            return false;
        }

        // Récupérer l'ID du type de contact
        $contactTypeId = $this->getContactTypeId('commande', $type, 'external');
        if (!$contactTypeId) {
            $this->log("Type de contact $type introuvable pour commande", LOG_ERR);
            return false;
        }

        // Vérifier si l'association existe déjà
        $sql = "SELECT COUNT(*) as nb";
        $sql .= " FROM " . MAIN_DB_PREFIX . "element_contact";
        $sql .= " WHERE element_id = " . ((int) $orderId);
        $sql .= " AND fk_socpeople = " . ((int) $contactId);
        $sql .= " AND fk_c_type_contact = " . ((int) $contactTypeId);

        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            if ($obj->nb > 0) {
                $this->log("Contact #$contactId déjà associé comme $type à commande #$orderId", LOG_DEBUG);
                return true;
            }
        }

        // Créer l'association
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "element_contact";
        $sql .= " (element_id, fk_socpeople, datecreate, statut, fk_c_type_contact)";
        $sql .= " VALUES (";
        $sql .= ((int) $orderId) . ",";
        $sql .= ((int) $contactId) . ",";
        $sql .= "'" . $this->db->idate(dol_now()) . "',";
        $sql .= "4,"; // Statut actif
        $sql .= ((int) $contactTypeId);
        $sql .= ")";

        $result = $this->db->query($sql);
        if ($result) {
            $this->log("Contact #$contactId associé comme $type à commande #$orderId", LOG_INFO);
            return true;
        } else {
            $this->log("Erreur association contact #$contactId à commande #$orderId : " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Extrait les informations de point relais depuis les metafields et customAttributes Shopify
     *
     * Supporte les apps suivantes :
     * - Mondial Relay Official
     * - Boxtal Connect
     * - Atlas Pickup Points
     * - Chronopost
     * - PointPicker
     * - Colissimo Points Relais
     *
     * @param array $metafields Metafields Shopify (tableau ou objet edges/nodes GraphQL)
     * @param array $customAttributes Custom attributes Shopify
     * @param string $note Note de la commande Shopify
     * @return array|null Informations structurées du point relais, null si aucun point relais détecté
     * @since 2.1.5
     */
    private function extractPickupPointInfo($metafields, $customAttributes = array(), $note = '')
    {
        $pickupInfo = null;

        // Normaliser metafields (GraphQL edges/nodes → tableau simple)
        $metafieldsArray = array();
        if (!empty($metafields) && is_array($metafields)) {
            if (isset($metafields['edges'])) {
                // Format GraphQL
                foreach ($metafields['edges'] as $edge) {
                    if (isset($edge['node'])) {
                        $node = $edge['node'];
                        $key = $node['namespace'] . '.' . $node['key'];
                        $metafieldsArray[$key] = $node['value'];
                    }
                }
            } else {
                // Format direct
                $metafieldsArray = $metafields;
            }
        }

        // Pattern 1 : Mondial Relay (namespace "mondial_relay")
        if (isset($metafieldsArray['mondial_relay.relay_id'])) {
            $pickupInfo = array(
                'provider' => 'Mondial Relay',
                'id' => $metafieldsArray['mondial_relay.relay_id'],
                'name' => isset($metafieldsArray['mondial_relay.relay_name']) ? $metafieldsArray['mondial_relay.relay_name'] : '',
                'address' => isset($metafieldsArray['mondial_relay.relay_address']) ? $metafieldsArray['mondial_relay.relay_address'] : '',
                'city' => isset($metafieldsArray['mondial_relay.relay_city']) ? $metafieldsArray['mondial_relay.relay_city'] : '',
                'zip' => isset($metafieldsArray['mondial_relay.relay_zip']) ? $metafieldsArray['mondial_relay.relay_zip'] : '',
                'country' => isset($metafieldsArray['mondial_relay.relay_country']) ? $metafieldsArray['mondial_relay.relay_country'] : '',
            );
        }
        // Pattern 2 : Boxtal Connect (namespace "boxtal")
        elseif (isset($metafieldsArray['boxtal.pickup_point_id'])) {
            $pickupInfo = array(
                'provider' => 'Boxtal',
                'id' => $metafieldsArray['boxtal.pickup_point_id'],
                'name' => isset($metafieldsArray['boxtal.pickup_point_name']) ? $metafieldsArray['boxtal.pickup_point_name'] : '',
                'address' => isset($metafieldsArray['boxtal.pickup_point_address']) ? $metafieldsArray['boxtal.pickup_point_address'] : '',
                'city' => isset($metafieldsArray['boxtal.pickup_point_city']) ? $metafieldsArray['boxtal.pickup_point_city'] : '',
                'zip' => isset($metafieldsArray['boxtal.pickup_point_zip']) ? $metafieldsArray['boxtal.pickup_point_zip'] : '',
                'country' => isset($metafieldsArray['boxtal.pickup_point_country']) ? $metafieldsArray['boxtal.pickup_point_country'] : '',
            );
        }
        // Pattern 3 : Atlas Pickup Points (namespace "atlas")
        elseif (isset($metafieldsArray['atlas.pickup_location_id'])) {
            $pickupInfo = array(
                'provider' => 'Atlas Pickup Points',
                'id' => $metafieldsArray['atlas.pickup_location_id'],
                'name' => isset($metafieldsArray['atlas.pickup_location_name']) ? $metafieldsArray['atlas.pickup_location_name'] : '',
                'address' => isset($metafieldsArray['atlas.pickup_location_address']) ? $metafieldsArray['atlas.pickup_location_address'] : '',
                'city' => isset($metafieldsArray['atlas.pickup_location_city']) ? $metafieldsArray['atlas.pickup_location_city'] : '',
                'zip' => isset($metafieldsArray['atlas.pickup_location_zip']) ? $metafieldsArray['atlas.pickup_location_zip'] : '',
                'country' => isset($metafieldsArray['atlas.pickup_location_country']) ? $metafieldsArray['atlas.pickup_location_country'] : '',
            );
        }
        // Pattern 4 : Custom Attributes (format clé-valeur)
        elseif (!empty($customAttributes) && is_array($customAttributes)) {
            foreach ($customAttributes as $attr) {
                // FIX v2.2.0: GraphQL json_decode retourne stdClass, pas array
                $attr = (array) $attr;
                if (isset($attr['key']) && isset($attr['value'])) {
                    $key = strtolower($attr['key']);

                    // Détection patterns communs
                    if (strpos($key, 'pickup') !== false || strpos($key, 'relay') !== false || strpos($key, 'point_relais') !== false) {
                        if (!$pickupInfo) {
                            $pickupInfo = array('provider' => 'Custom Attribute');
                        }

                        // ID
                        if (strpos($key, 'id') !== false) {
                            $pickupInfo['id'] = $attr['value'];
                        }
                        // Nom
                        elseif (strpos($key, 'name') !== false || strpos($key, 'nom') !== false) {
                            $pickupInfo['name'] = $attr['value'];
                        }
                        // Adresse
                        elseif (strpos($key, 'address') !== false || strpos($key, 'adresse') !== false) {
                            $pickupInfo['address'] = $attr['value'];
                        }
                        // Ville
                        elseif (strpos($key, 'city') !== false || strpos($key, 'ville') !== false) {
                            $pickupInfo['city'] = $attr['value'];
                        }
                        // Code postal
                        elseif (strpos($key, 'zip') !== false || strpos($key, 'postal') !== false || strpos($key, 'cp') !== false) {
                            $pickupInfo['zip'] = $attr['value'];
                        }
                        // Pays
                        elseif (strpos($key, 'country') !== false || strpos($key, 'pays') !== false) {
                            $pickupInfo['country'] = $attr['value'];
                        }
                    }
                }
            }
        }
        // Pattern 5 : Détection dans la note (fallback)
        elseif (!empty($note) && (strpos($note, 'Point Relais') !== false || strpos($note, 'Pickup Point') !== false)) {
            // Extraction basique depuis note
            $pickupInfo = array(
                'provider' => 'Extracted from note',
                'raw_note' => $note,
            );
        }

        if ($pickupInfo) {
            $this->log("Point relais détecté : " . $pickupInfo['provider'] . " - ID: " . (isset($pickupInfo['id']) ? $pickupInfo['id'] : 'N/A'), LOG_INFO);
        }

        return $pickupInfo;
    }

    /**
     * Sauvegarde les informations de point relais dans les extrafields de la commande
     *
     * CORRECTION v2.1.5: Migration JSON monolithique → Colonnes SQL dédiées pour requêtes natives
     *
     * @param int $orderId ID de la commande Dolibarr
     * @param array|null $pickupInfo Informations structurées du point relais (ou null)
     * @return bool True si succès, false sinon
     * @since 2.1.5
     */
    private function savePickupPointInfo($orderId, $pickupInfo)
    {
        if (empty($orderId)) {
            return false;
        }

        // Si pas de point relais, nettoyer les champs
        if (empty($pickupInfo)) {
            $this->log("Aucun point relais pour commande #$orderId, nettoyage des champs", LOG_DEBUG);
        }

        // Vérifier si extrafield existe déjà
        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "commande_extrafields WHERE fk_object = " . ((int) $orderId);
        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("Erreur vérification extrafields pour commande #$orderId : " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($result);
        $exists = ($obj->nb > 0);

        // Préparation des données pour colonnes dédiées
        $pickupProvider = !empty($pickupInfo['provider']) ? $this->db->escape($pickupInfo['provider']) : null;
        $pickupPointId = !empty($pickupInfo['id']) ? $this->db->escape($pickupInfo['id']) : null;
        $pickupPointName = !empty($pickupInfo['name']) ? $this->db->escape($pickupInfo['name']) : null;
        $pickupAddressLine1 = !empty($pickupInfo['address']) ? $this->db->escape($pickupInfo['address']) : null;
        $pickupAddressLine2 = !empty($pickupInfo['address2']) ? $this->db->escape($pickupInfo['address2']) : null;
        $pickupCity = !empty($pickupInfo['city']) ? $this->db->escape($pickupInfo['city']) : null;
        $pickupZip = !empty($pickupInfo['zip']) ? $this->db->escape($pickupInfo['zip']) : null;
        $pickupCountry = !empty($pickupInfo['country']) ? $this->db->escape($pickupInfo['country']) : null;
        $pickupPhone = !empty($pickupInfo['phone']) ? $this->db->escape($pickupInfo['phone']) : null;

        // JSON backup complet pour données provider-spécifiques (horaires, etc.)
        $pickupExtraData = !empty($pickupInfo) ? $this->db->escape(json_encode($pickupInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) : null;

        // JSON legacy pour compatibilité avec code existant
        $pickupPointInfoJson = !empty($pickupInfo) ? $this->db->escape(json_encode($pickupInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null;

        if ($exists) {
            // UPDATE avec colonnes dédiées
            $sql = "UPDATE " . MAIN_DB_PREFIX . "commande_extrafields SET ";
            $sql .= " pickup_provider = " . ($pickupProvider ? "'" . $pickupProvider . "'" : "NULL") . ",";
            $sql .= " pickup_point_id = " . ($pickupPointId ? "'" . $pickupPointId . "'" : "NULL") . ",";
            $sql .= " pickup_point_name = " . ($pickupPointName ? "'" . $pickupPointName . "'" : "NULL") . ",";
            $sql .= " pickup_address_line1 = " . ($pickupAddressLine1 ? "'" . $pickupAddressLine1 . "'" : "NULL") . ",";
            $sql .= " pickup_address_line2 = " . ($pickupAddressLine2 ? "'" . $pickupAddressLine2 . "'" : "NULL") . ",";
            $sql .= " pickup_city = " . ($pickupCity ? "'" . $pickupCity . "'" : "NULL") . ",";
            $sql .= " pickup_zip = " . ($pickupZip ? "'" . $pickupZip . "'" : "NULL") . ",";
            $sql .= " pickup_country = " . ($pickupCountry ? "'" . $pickupCountry . "'" : "NULL") . ",";
            $sql .= " pickup_phone = " . ($pickupPhone ? "'" . $pickupPhone . "'" : "NULL") . ",";
            $sql .= " pickup_extra_data = " . ($pickupExtraData ? "'" . $pickupExtraData . "'" : "NULL") . ",";
            $sql .= " pickup_point_info = " . ($pickupPointInfoJson ? "'" . $pickupPointInfoJson . "'" : "NULL");
            $sql .= " WHERE fk_object = " . ((int) $orderId);
        } else {
            // INSERT avec colonnes dédiées
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "commande_extrafields";
            $sql .= " (fk_object, pickup_provider, pickup_point_id, pickup_point_name,";
            $sql .= " pickup_address_line1, pickup_address_line2, pickup_city, pickup_zip,";
            $sql .= " pickup_country, pickup_phone, pickup_extra_data, pickup_point_info)";
            $sql .= " VALUES (";
            $sql .= ((int) $orderId) . ",";
            $sql .= ($pickupProvider ? "'" . $pickupProvider . "'" : "NULL") . ",";
            $sql .= ($pickupPointId ? "'" . $pickupPointId . "'" : "NULL") . ",";
            $sql .= ($pickupPointName ? "'" . $pickupPointName . "'" : "NULL") . ",";
            $sql .= ($pickupAddressLine1 ? "'" . $pickupAddressLine1 . "'" : "NULL") . ",";
            $sql .= ($pickupAddressLine2 ? "'" . $pickupAddressLine2 . "'" : "NULL") . ",";
            $sql .= ($pickupCity ? "'" . $pickupCity . "'" : "NULL") . ",";
            $sql .= ($pickupZip ? "'" . $pickupZip . "'" : "NULL") . ",";
            $sql .= ($pickupCountry ? "'" . $pickupCountry . "'" : "NULL") . ",";
            $sql .= ($pickupPhone ? "'" . $pickupPhone . "'" : "NULL") . ",";
            $sql .= ($pickupExtraData ? "'" . $pickupExtraData . "'" : "NULL") . ",";
            $sql .= ($pickupPointInfoJson ? "'" . $pickupPointInfoJson . "'" : "NULL");
            $sql .= ")";
        }

        $result = $this->db->query($sql);
        if ($result) {
            if (!empty($pickupInfo)) {
                $this->log("[OK] Point relais sauvegardé (colonnes dédiées) : {$pickupInfo['provider']} - {$pickupInfo['name']} (ID: {$pickupInfo['id']}) pour commande #$orderId", LOG_INFO);
            } else {
                $this->log("Point relais nettoyé pour commande #$orderId", LOG_DEBUG);
            }
            return true;
        } else {
            $this->log("[ERROR] Erreur sauvegarde point relais pour commande #$orderId : " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Sauvegarde TOUS les metafields bruts de la commande Shopify pour traçabilité complète
     *
     * Permet de conserver toutes les données Shopify même si elles ne sont pas traitées
     * actuellement par le module. Utile pour :
     * - Déboguer les problèmes de synchronisation
     * - Récupérer des données d'apps tierces futures
     * - Traçabilité complète des informations Shopify
     *
     * @param int $orderId ID de la commande Dolibarr
     * @param array $metafields Metafields Shopify (format GraphQL edges/nodes)
     * @param array $customAttributes Custom attributes Shopify
     * @param string $note Note de la commande
     * @param array $tags Tags de la commande
     * @return bool True si succès, false sinon
     * @since 2.1.5
     */
    private function saveRawMetafields($orderId, $metafields = array(), $customAttributes = array(), $note = '', $tags = array())
    {
        if (empty($orderId)) {
            return false;
        }

        // Construire structure complète pour traçabilité
        $rawData = array(
            'metafields' => array(),
            'customAttributes' => $customAttributes,
            'note' => $note,
            'tags' => $tags,
            'saved_at' => date('Y-m-d H:i:s'),
        );

        // Normaliser metafields (GraphQL → tableau simple)
        if (!empty($metafields) && is_array($metafields)) {
            if (isset($metafields['edges'])) {
                // Format GraphQL
                foreach ($metafields['edges'] as $edge) {
                    if (isset($edge['node'])) {
                        $node = $edge['node'];
                        $rawData['metafields'][] = array(
                            'namespace' => isset($node['namespace']) ? $node['namespace'] : '',
                            'key' => isset($node['key']) ? $node['key'] : '',
                            'value' => isset($node['value']) ? $node['value'] : '',
                            'type' => isset($node['type']) ? $node['type'] : '',
                        );
                    }
                }
            } else {
                // Format direct
                $rawData['metafields'] = $metafields;
            }
        }

        // Encoder en JSON
        $rawJson = json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // Vérifier si extrafield existe déjà
        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "commande_extrafields WHERE fk_object = " . ((int) $orderId);
        $result = $this->db->query($sql);

        if ($result) {
            $obj = $this->db->fetch_object($result);
            $exists = ($obj->nb > 0);

            if ($exists) {
                // UPDATE
                $sql = "UPDATE " . MAIN_DB_PREFIX . "commande_extrafields";
                $sql .= " SET shopify_metafields_raw = '" . $this->db->escape($rawJson) . "'";
                $sql .= " WHERE fk_object = " . ((int) $orderId);
            } else {
                // INSERT
                $sql = "INSERT INTO " . MAIN_DB_PREFIX . "commande_extrafields";
                $sql .= " (fk_object, shopify_metafields_raw)";
                $sql .= " VALUES (" . ((int) $orderId) . ", '" . $this->db->escape($rawJson) . "')";
            }

            $result = $this->db->query($sql);
            if ($result) {
                $metaCount = count($rawData['metafields']);
                $attrCount = count($customAttributes);
                $this->log("Metafields bruts sauvegardés pour commande #$orderId : $metaCount metafields, $attrCount custom attributes", LOG_INFO);
                return true;
            } else {
                $this->log("Erreur sauvegarde metafields bruts pour commande #$orderId : " . $this->db->lasterror(), LOG_ERR);
                return false;
            }
        }

        return false;
    }

    /**
     * Catch-up: complete invoice creation/validation and payment for an already-imported order.
     *
     * Called by processSingleOrderFromWebhook() when the order already exists in Dolibarr.
     * Performs a quick SQL check to see if invoice+payment are complete. If not, fetches the
     * Shopify order and completes the missing steps (create invoice, validate, create payment).
     *
     * @param  string $shopifyOrderId Shopify order ID (numeric)
     * @param  User   $user           User performing the action
     * @return int    1 if already complete or catch-up done, <0 on error
     * @since  2.2.0
     */
    private function catchUpInvoiceAndPayment($shopifyOrderId, $user)
    {
        // 1. Find the Dolibarr order ID from sync table
        $sql = "SELECT fk_commande FROM " . MAIN_DB_PREFIX . "doli2shop_orders";
        $sql .= " WHERE shopifyOrderId = '" . $this->db->escape($shopifyOrderId) . "'";
        $sql .= " AND entity = " . (int) $this->entity . " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("catchUpInvoiceAndPayment - SQL error: " . $this->db->lasterror(), LOG_ERR);
            return 1; // Non-bloquant
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj || empty($obj->fk_commande)) {
            $this->log("catchUpInvoiceAndPayment - No Dolibarr order found for Shopify " . $shopifyOrderId . ", skipping", LOG_INFO);
            return 1;
        }

        $dolibarrOrderId = (int) $obj->fk_commande;

        // 2. Quick check: is invoice validated + payment exists?
        $sqlCheck = "SELECT f.rowid as invoice_id, f.fk_statut as invoice_status, pf.rowid as payment_id";
        $sqlCheck .= " FROM " . MAIN_DB_PREFIX . "element_element ee";
        $sqlCheck .= " JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = ee.fk_target";
        $sqlCheck .= " LEFT JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_facture = f.rowid";
        $sqlCheck .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source = " . $dolibarrOrderId;
        $sqlCheck .= " AND ee.targettype = 'facture' LIMIT 1";

        $resqlCheck = $this->db->query($sqlCheck);
        $check = $resqlCheck ? $this->db->fetch_object($resqlCheck) : null;
        if ($resqlCheck) {
            $this->db->free($resqlCheck);
        }

        // v2.2.0 FIX: Vérifier si la facture est à 0€ (pas besoin de paiement)
        if ($check && $check->invoice_status >= 1 && empty($check->payment_id)) {
            $invoiceTmp = new Facture($this->db);
            if ($invoiceTmp->fetch((int) $check->invoice_id) > 0 && $invoiceTmp->total_ttc == 0 && empty($invoiceTmp->paye)) {
                $setPaidRes = $invoiceTmp->setPaid($user);
                if ($setPaidRes < 0) {
                    $this->log("catchUpInvoiceAndPayment - setPaid() failed for 0€ invoice #" . $check->invoice_id
                        . ": " . ($invoiceTmp->error ?: 'Unknown'), LOG_WARNING);
                } else {
                    $this->log("catchUpInvoiceAndPayment - 0€ invoice #" . $check->invoice_id . " classified as paid (catch-up)", LOG_INFO);
                }
            }
        }

        // Invoice validated (status >= 1) AND (payment exists OR 0€ invoice marked paid) → check billed status then skip
        // Re-check payment status for 0€ invoices that were just marked as paid
        if ($check && $check->invoice_status >= 1) {
            // Re-query payment status (may have been a 0€ invoice just classified above)
            $sqlPayCheck = "SELECT pf.rowid as payment_id, f.total_ttc, f.paye FROM " . MAIN_DB_PREFIX . "facture f";
            $sqlPayCheck .= " LEFT JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_facture = f.rowid";
            $sqlPayCheck .= " WHERE f.rowid = " . (int) $check->invoice_id . " LIMIT 1";
            $resPayCheck = $this->db->query($sqlPayCheck);
            $payCheck = $resPayCheck ? $this->db->fetch_object($resPayCheck) : null;
            if ($resPayCheck) {
                $this->db->free($resPayCheck);
            }

            $isComplete = (!empty($payCheck->payment_id) || ($payCheck && $payCheck->total_ttc == 0 && !empty($payCheck->paye)));
        }

        if ($check && $check->invoice_status >= 1 && isset($isComplete) && $isComplete) {
            // v2.2.0 FIX: Vérifier aussi que la commande est bien classée "Facturé"
            // Certaines commandes ont facture + paiement mais ne sont pas classées "Facturé"
            // Note: colonne SQL = 'facture', propriété PHP = 'billed'
            $sqlBilled = "SELECT facture FROM " . MAIN_DB_PREFIX . "commande WHERE rowid = " . $dolibarrOrderId;
            $resBilled = $this->db->query($sqlBilled);
            $objBilled = $resBilled ? $this->db->fetch_object($resBilled) : null;
            if ($objBilled && empty($objBilled->facture)) {
                require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
                $orderTmp = new Commande($this->db);
                if ($orderTmp->fetch($dolibarrOrderId) > 0) {
                    if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_CLASSIFY_BILLED', 1)) {
                        $orderTmp->classifyBilled($user);
                        $this->log("catchUpInvoiceAndPayment - Order #" . $dolibarrOrderId . " classified as Billed (catch-up)", LOG_INFO);
                    }
                }
            }
            $this->log("catchUpInvoiceAndPayment - Order #" . $dolibarrOrderId . " (Shopify " . $shopifyOrderId . ") fully complete, skipping", LOG_DEBUG);
            return 1;
        }

        // 3. Something is missing — load the Dolibarr order
        $this->log("catchUpInvoiceAndPayment - Order #" . $dolibarrOrderId . " (Shopify " . $shopifyOrderId . ") needs catch-up"
            . " (invoice=" . ($check ? "ID " . $check->invoice_id . " status=" . $check->invoice_status : "NONE")
            . ", payment=" . ($check && !empty($check->payment_id) ? "YES" : "NONE") . ")", LOG_INFO);

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $order = new Commande($this->db);
        if ($order->fetch($dolibarrOrderId) <= 0) {
            $this->log("catchUpInvoiceAndPayment - Failed to load order #" . $dolibarrOrderId, LOG_ERR);
            return 1;
        }
        $order->fetch_lines();

        // 3bis. Auto-validate draft orders (fix: commandes restées en brouillon après valid() échoué)
        if ($order->statut == Commande::STATUS_DRAFT) {
            // v2.2.8: fallback chain entrepôt (cf. createOrder)
            $warehouseId = $this->resolveWarehouseIdForStock($order);
            $this->warnIfStockNotDecremented($warehouseId, "catchUpInvoiceAndPayment", "order #" . $dolibarrOrderId, 'STOCK_CALCULATE_ON_VALIDATE_ORDER');
            $validResult = $order->valid($user, $warehouseId);
            if ($validResult < 0) {
                // Retry sans triggers (contournement conflit trigger tiers)
                $this->log("catchUpInvoiceAndPayment - valid() failed for order #" . $dolibarrOrderId
                    . ", retrying with notrigger=1", LOG_WARNING);
                $order->statut = Commande::STATUS_DRAFT;
                $validResult = $order->valid($user, $warehouseId, 1);
                if ($validResult < 0) {
                    $this->log("catchUpInvoiceAndPayment - Cannot validate draft order #" . $dolibarrOrderId
                        . ": " . ($order->error ?: 'Unknown error'), LOG_WARNING);
                } else {
                    $this->log("catchUpInvoiceAndPayment - Order #" . $dolibarrOrderId
                        . " auto-validated with notrigger=1 during catch-up", LOG_WARNING);
                }
            } else {
                $this->log("catchUpInvoiceAndPayment - Order #" . $dolibarrOrderId
                    . " auto-validated (draft → validated) during catch-up", LOG_INFO);
            }
        }

        // 4a. No invoice → create one (idempotent via createInvoiceFromOrder)
        if (!$check || empty($check->invoice_id)) {
            if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_CREATE_INVOICE', 1)) {
                $invoiceResult = $this->createInvoiceFromOrder($order, $user);
                if ($invoiceResult > 0) {
                    $this->log("catchUpInvoiceAndPayment - Invoice #" . $invoiceResult . " created for order #" . $dolibarrOrderId, LOG_INFO);
                } elseif ($invoiceResult < 0) {
                    $this->log("catchUpInvoiceAndPayment - Invoice creation failed for order #" . $dolibarrOrderId . ": " . implode(', ', $this->errors), LOG_WARNING);
                    return 1; // Non-bloquant
                }
            }
        }

        // 4b. Invoice exists as draft → add lines if empty, then try to validate
        if ($check && !empty($check->invoice_id) && $check->invoice_status == 0) {
            $invoice = new Facture($this->db);
            if ($invoice->fetch((int) $check->invoice_id) > 0) {
                $invoice->fetch_lines();

                // v2.2.0 FIX: Si la facture est vide (Dolibarr 22.x ne copie pas les lignes automatiquement)
                // on les ajoute manuellement depuis la commande
                if (empty($invoice->lines) || count($invoice->lines) == 0) {
                    if (!empty($order->lines)) {
                        $this->log("catchUpInvoiceAndPayment - Invoice #" . $check->invoice_id . " is empty, adding " . count($order->lines) . " lines from order #" . $dolibarrOrderId, LOG_INFO);
                        foreach ($order->lines as $orderLine) {
                            $invoice->addline(
                                $orderLine->desc,
                                $orderLine->subprice,
                                $orderLine->qty,
                                $orderLine->tva_tx,
                                $orderLine->localtax1_tx,
                                $orderLine->localtax2_tx,
                                $orderLine->fk_product,
                                $orderLine->remise_percent,
                                '', '', 0,
                                $orderLine->info_bits,
                                $orderLine->fk_remise_except,
                                'HT', 0,
                                $orderLine->product_type,
                                -1,
                                $orderLine->special_code,
                                '', 0, 0,
                                $orderLine->fk_fournprice ?? null,
                                $orderLine->pa_ht ?? 0,
                                $orderLine->label ?? '',
                                $orderLine->array_options ?? array(),
                                '', 0,
                                $orderLine->fk_unit ?? null
                            );
                        }
                        $invoice->fetch_lines();
                        $this->log("catchUpInvoiceAndPayment - Invoice #" . $check->invoice_id . " now has " . count($invoice->lines) . " lines", LOG_DEBUG);
                    }
                }

                if ($this->storeSettings->getInt($this->getStoreId(), 'AUTO_VALIDATE_INVOICE', 1) && $user->hasRight('facture', 'valider')) {
                    // v2.2.8: passer l'entrepôt à validate() — sans idwarehouse > 0,
                    // STOCK_CALCULATE_ON_BILL ne décrémente jamais le stock (skip silencieux Dolibarr)
                    $stockWarehouseId = $this->resolveWarehouseIdForStock($order);
                    $this->warnIfStockNotDecremented($stockWarehouseId, "catchUpInvoiceAndPayment", "invoice #" . $check->invoice_id, 'STOCK_CALCULATE_ON_BILL');
                    $validateResult = $invoice->validate($user, '', $stockWarehouseId);
                    if ($validateResult > 0) {
                        $this->log("catchUpInvoiceAndPayment - Invoice #" . $check->invoice_id . " validated (catch-up) for order #" . $dolibarrOrderId, LOG_INFO);
                    } else {
                        $this->log("catchUpInvoiceAndPayment - Invoice #" . $check->invoice_id . " validation failed: " . ($invoice->error ?: 'Unknown'), LOG_WARNING);
                    }
                } else {
                    $this->log("catchUpInvoiceAndPayment - Invoice #" . $check->invoice_id . " is draft but auto-validate disabled or user lacks facture->valider right", LOG_WARNING);
                }
            }
        }

        // 5. Payment catch-up — fetch Shopify order for financial status + payment data
        // Only call Shopify API if we don't already have a payment
        if (!$check || empty($check->payment_id)) {
            try {
                $result = $this->shopifyApi->executeGraphQL([
                    'query' => $this->buildSingleOrderQuery(),
                    'variables' => [
                        'query' => "id:" . $shopifyOrderId,
                    ]
                ]);

                if (!empty($result->data->orders->edges)) {
                    $shopifyOrder = json_decode(json_encode($result->data->orders->edges[0]->node));
                    $financialStatus = $shopifyOrder->displayFinancialStatus ?? '';

                    if (in_array($financialStatus, ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'])) {
                        $paymentResult = $this->processPaymentForOrder($dolibarrOrderId, $shopifyOrder, $user);
                        if ($paymentResult > 0) {
                            $this->log("catchUpInvoiceAndPayment - Payment #" . $paymentResult . " created (catch-up) for order #" . $dolibarrOrderId, LOG_INFO);
                        } elseif ($paymentResult == 0) {
                            $this->log("catchUpInvoiceAndPayment - Payment skipped for order #" . $dolibarrOrderId . " (invoice draft, already exists, or disabled)", LOG_INFO);
                        } else {
                            $this->log("catchUpInvoiceAndPayment - Payment failed for order #" . $dolibarrOrderId . ": " . implode(', ', $this->errors), LOG_WARNING);
                        }
                    } else {
                        $this->log("catchUpInvoiceAndPayment - Order #" . $dolibarrOrderId . " not paid on Shopify (status=" . $financialStatus . "), skipping payment", LOG_INFO);
                    }
                }
            } catch (\Exception $e) {
                $this->log("catchUpInvoiceAndPayment - Exception fetching Shopify order " . $shopifyOrderId . ": " . $e->getMessage(), LOG_WARNING);
                // Non-bloquant
            }
        }

        return 1;
    }

    /**
     * Process a single order from a Shopify webhook or historical import
     *
     * Public facade for the private createOrUpdateCustomer() and createOrder() methods.
     * Used by OrderWebhookHandler, OrdersCatchup, and HistoricalImport CRONs.
     *
     * @param string      $shopifyOrderId   Shopify order ID (numeric)
     * @param stdClass|null $shopifyOrderData Pre-fetched order node (skip API call if provided)
     * @return int >0 (Dolibarr order ID) if new order created, 0 if order already exists (catch-up), <0 if error
     * @since 2.1.8
     */
    public function processSingleOrderFromWebhook($shopifyOrderId, $shopifyOrderData = null)
    {
        global $user;

        $this->log("processSingleOrderFromWebhook - shopifyOrderId=" . $shopifyOrderId, LOG_INFO);

        // Remise à zéro AC3 : l'instance est réutilisée en boucle par le rattrapage et l'import
        // historique — ne jamais laisser un refus du tour précédent fuiter sur celui-ci.
        $this->lastSkipReason = null;
        // Même raison — story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal :
        // ne jamais laisser un écart de totaux détecté au tour précédent fuiter sur celui-ci.
        $this->lastTotalsMismatch = null;

        // Vérifier si la commande existe déjà
        if ($this->orderExists($shopifyOrderId)) {
            // v2.2.0: Rattrapage — vérifier si facture+paiement sont complets
            // Retourne 0 pour signaler "commande existante" (CRON compteur skipped, AC#3)
            $this->catchUpInvoiceAndPayment($shopifyOrderId, $user);
            return 0;
        }

        try {
            // v2.2.0 Story 14.2: Si les données sont fournies (import historique), skip le fetch API
            if (!empty($shopifyOrderData)) {
                $order = $shopifyOrderData;
                // Normaliser en objet stdClass si nécessaire
                if (is_array($order)) {
                    $order = json_decode(json_encode($order));
                }
                $this->log("processSingleOrderFromWebhook - Using pre-fetched order data (skip API call)", LOG_DEBUG);
            } else {
                // Fetch la commande depuis Shopify via GraphQL
                $queryFilter = "id:" . $shopifyOrderId;

                $result = $this->shopifyApi->executeGraphQL([
                    'query' => $this->buildSingleOrderQuery(),
                    'variables' => [
                        'query' => $queryFilter,
                    ]
                ]);

                // Surfacer une éventuelle erreur GraphQL (accès refusé, throttling, etc.)
                // au lieu de la masquer derrière un trompeur « not found ». Cas typique :
                // l'app n'a pas l'accès aux Protected Customer Data → la requête `orders`
                // renvoie une erreur / un résultat vide alors que produits & stock passent.
                if (!empty($result->errors)) {
                    $this->error = "Erreur API Shopify (fetch commande " . $shopifyOrderId . ") : "
                        . json_encode($result->errors);
                    $this->errors[] = $this->error;
                    $this->log("processSingleOrderFromWebhook - " . $this->error, LOG_ERR);
                    return -1;
                }

                if (empty($result->data->orders->edges)) {
                    $this->error = "Order " . $shopifyOrderId . " not found in Shopify "
                        . "(réponse `orders` vide — vérifier le scope read_orders ET l'accès "
                        . "« Protected customer data » de l'app Shopify ; produits/stock non concernés)";
                    $this->errors[] = $this->error;
                    $this->log("processSingleOrderFromWebhook - " . $this->error, LOG_ERR);
                    return -1;
                }

                $order = $result->data->orders->edges[0]->node;
                // Convertir en objet pour compatibilité avec les méthodes existantes
                $order = json_decode(json_encode($order));
            }

            $this->log("processSingleOrderFromWebhook - Fetched order: name=" . $order->name
                . " financialStatus=" . ($order->displayFinancialStatus ?? '')
                . " fulfillmentStatus=" . ($order->displayFulfillmentStatus ?? ''), LOG_INFO);

            // Créer ou mettre à jour le client
            // v2.3.0: Fallback email guest checkout (AC 39-3 #3)
            $shopifyCustomerForLookup = !empty($order->customer) ? $order->customer : null;
            if ((empty($shopifyCustomerForLookup) || empty($shopifyCustomerForLookup->email))
                && !empty($order->email)) {
                $this->log(
                    'processSingleOrderFromWebhook - Guest checkout détecté - email commande utilisé: ' . doli2shop_mask_email($order->email),
                    LOG_INFO
                );
                if (empty($shopifyCustomerForLookup)) {
                    $shopifyCustomerForLookup = new \stdClass();
                }
                $shopifyCustomerForLookup->email = $order->email;
                // Initialiser les champs nécessaires à createOrUpdateCustomer si absents
                if (!isset($shopifyCustomerForLookup->firstName)) {
                    $shopifyCustomerForLookup->firstName = '';
                }
                if (!isset($shopifyCustomerForLookup->lastName)) {
                    $shopifyCustomerForLookup->lastName = '';
                }
                if (!isset($shopifyCustomerForLookup->phone)) {
                    $shopifyCustomerForLookup->phone = '';
                }
                if (!isset($shopifyCustomerForLookup->defaultAddress)) {
                    $shopifyCustomerForLookup->defaultAddress = null;
                }
                if (!isset($shopifyCustomerForLookup->emailMarketingConsent)) {
                    $shopifyCustomerForLookup->emailMarketingConsent = null;
                }
                if (!isset($shopifyCustomerForLookup->id)) {
                    $shopifyCustomerForLookup->id = '';
                }
            }

            if (empty($shopifyCustomerForLookup) || empty($shopifyCustomerForLookup->email)) {
                $this->error = "Order " . $shopifyOrderId . " has no customer data";
                $this->errors[] = $this->error;
                $this->log("processSingleOrderFromWebhook - " . $this->error, LOG_ERR);
                return -1;
            }

            $customer = $this->createOrUpdateCustomer($shopifyCustomerForLookup);

            if (!is_object($customer) || $customer->id <= 0) {
                $this->error = "Failed to create/update customer for order " . $shopifyOrderId;
                $this->errors[] = $this->error;
                $this->log("processSingleOrderFromWebhook - " . $this->error, LOG_ERR);
                return -1;
            }

            // Créer la commande
            $dolibarrOrderId = $this->createOrder($order, $customer->id);

            if ($dolibarrOrderId <= 0) {
                if ($dolibarrOrderId == 0) {
                    if ($this->lastSkipReason === 'unpaid') {
                        // AC1/AC3 : refus métier explicite (réglage désactivé + commande non payée),
                        // PAS un doublon — ne pas écraser le message par le libellé générique ci-dessous.
                        $this->log("processSingleOrderFromWebhook - Order " . $shopifyOrderId . " NOT imported: unpaid and DOLI2SHOP_SYNC_NON_PAID_ORDERS disabled", LOG_INFO);
                    } elseif ($this->lastSkipReason === 'unpaid_status_unknown') {
                        // MEDIUM-1 : même refus fail-closed, mais displayFinancialStatus indéterminé —
                        // à ne pas confondre avec un doublon NI avec un refus "non payée" ordinaire.
                        $this->log("processSingleOrderFromWebhook - Order " . $shopifyOrderId . " NOT imported: displayFinancialStatus indéterminé et DOLI2SHOP_SYNC_NON_PAID_ORDERS désactivé (anomalie API possible)", LOG_WARNING);
                    } elseif ($this->lastSkipReason === 'taxes_included_unknown') {
                        // Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (AC3) :
                        // refus fail-closed distinct — `taxesIncluded` absent/de type invalide dans la
                        // réponse GraphQL, impossible de déterminer TTC/HT — PAS un doublon.
                        $this->log("processSingleOrderFromWebhook - Order " . $shopifyOrderId . " NOT imported: taxesIncluded absent ou invalide dans la réponse GraphQL (impossible de déterminer TTC/HT)", LOG_ERR);
                    } else {
                        // Order already created by concurrent process — not an error
                        $this->log("processSingleOrderFromWebhook - Order already created concurrently for Shopify " . $shopifyOrderId . ", skipping", LOG_INFO);
                    }
                    return 0;
                }
                $this->error = "Failed to create Dolibarr order for Shopify order " . $shopifyOrderId;
                $this->errors[] = $this->error;
                $this->log("processSingleOrderFromWebhook - " . $this->error, LOG_ERR);
                return -1;
            }

            // Sauvegarder le mapping dans la table de synchronisation
            $orderSync = new ShopifyOrderSync($this->db);
            $orderSync->fk_commande = $dolibarrOrderId;
            $orderSync->shopifyOrderId = $shopifyOrderId;
            $orderSync->dolOrderFulfillment = $this->mapFulfillmentStatus($order->displayFulfillmentStatus);
            $orderSync->entity = $this->entity;
            // Epic 47-4 : fk_store conditionnel (getStoreId()=0 → chemin historique)
            $orderSync->fk_store = ($this->shopifyApi !== null) ? (int) $this->shopifyApi->getStoreId() : 0;

            // Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (AC1) : persiste
            // la marque d'écart de totaux détectée par createOrder() (null-safe — buildOrderSyncTotalsMismatchFields()
            // renvoie 3 nulls si $lastTotalsMismatch est null, aucun écart détecté).
            $totalsMismatchFields = self::buildOrderSyncTotalsMismatchFields($this->lastTotalsMismatch);
            $orderSync->totals_mismatch_severity = $totalsMismatchFields['totals_mismatch_severity'];
            $orderSync->shopify_total_ttc = $totalsMismatchFields['shopify_total_ttc'];
            $orderSync->dolibarr_total_ttc = $totalsMismatchFields['dolibarr_total_ttc'];

            if ($orderSync->create($user) < 0) {
                $this->log("processSingleOrderFromWebhook - Warning: Failed to save sync mapping: " . $orderSync->error, LOG_WARNING);
                // Non bloquant : la commande a été créée
            }

            $this->log("processSingleOrderFromWebhook - Order created successfully: dolibarrOrderId=" . $dolibarrOrderId, LOG_INFO);

            // v2.2.0: Si la commande Shopify est payée, créer le paiement Dolibarr
            // Le CRON de rattrapage doit reproduire le même flux que le webhook handler
            $financialStatus = $order->displayFinancialStatus ?? '';
            if (in_array($financialStatus, ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'])) {
                $this->log("processSingleOrderFromWebhook - Order is paid (status=" . $financialStatus . "), processing payment", LOG_INFO);
                $paymentResult = $this->processPaymentForOrder($dolibarrOrderId, $order, $user);
                if ($paymentResult > 0) {
                    $this->log("processSingleOrderFromWebhook - Payment #" . $paymentResult . " created for order #" . $dolibarrOrderId, LOG_INFO);
                } elseif ($paymentResult == 0) {
                    $this->log("processSingleOrderFromWebhook - Payment skipped for order #" . $dolibarrOrderId . " (already exists, invoice draft, or disabled)", LOG_INFO);
                } else {
                    $this->log("processSingleOrderFromWebhook - Payment creation failed for order #" . $dolibarrOrderId . ": " . implode(', ', $this->errors), LOG_WARNING);
                    // Non-bloquant : la commande et la facture sont créées
                }
            }

            return $dolibarrOrderId;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $this->error;
            $this->log("processSingleOrderFromWebhook - Exception: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Fetch a batch of historical orders with FULL field set (same as buildSingleOrderQuery)
     *
     * v2.2.0 Story 14.2: Batch query paginée pour import historique.
     * Utilise le même set de champs que buildSingleOrderQuery() pour garantir la compatibilité
     * avec processSingleOrderFromWebhook($id, $data).
     *
     * Amendement — review 3 couches du 24/08 (CRITICAL, Story 63-18) : `ShopifyApi::executeGraphQL()`
     * ne lève AUCUNE exception sur un 401 persistant (après refresh+retry), ni sur une erreur GraphQL
     * autre que "Throttled", ni sur le throttle épuisé après ses relances — elle renvoie la réponse
     * brute (`$decoded`, en erreur : `errors` présent et/ou `data` absent). Avant cet amendement,
     * cette méthode redescendait une telle réponse avec EXACTEMENT la même signature qu'une plage
     * réellement vide (`orders => []`, `hasNextPage => false`), rendant les deux cas indiscernables
     * pour l'appelant — qui concluait alors à tort à la complétion de l'import
     * (`doli2shopIsHistoricalImportPassComplete()`). Le tableau retourné porte désormais une clé
     * `'error'` explicite : `true` pour une réponse inexploitable (ou pour la garde "aucun filtre"
     * ci-dessous, qui est un refus, pas une plage vide), `false` sinon — y compris pour une plage
     * légitimement épuisée. `ShopifyHistoricalImportCron::executeCron()` s'appuie sur cette clé pour
     * ne jamais conclure à une complétion sur une passe en erreur.
     *
     * @param string|null $createdAtMin Date de création minimum (ISO 8601 ou YYYY-MM-DD)
     * @param string|null $createdAtMax Date de création maximum (ISO 8601 ou YYYY-MM-DD)
     * @param string|null $cursor Cursor de pagination GraphQL
     * @param int $batchSize Nombre de commandes par page (défaut: 20)
     * @return array ['orders' => stdClass[], 'hasNextPage' => bool, 'endCursor' => string|null, 'error' => bool]
     * @since 2.2.0
     */
    public function fetchHistoricalOrdersBatch($createdAtMin = null, $createdAtMax = null, $cursor = null, $batchSize = 20)
    {
        // Construire le filtre query Shopify
        $queryFilters = [];

        if (!empty($createdAtMin)) {
            $dateValue = $createdAtMin;
            if (strpos($dateValue, 'T') === false && strpos($dateValue, ' ') === false) {
                $dateValue .= ' 00:00:00';
            }
            $startDateTime = new \DateTime($dateValue, new \DateTimeZone('UTC'));
            $queryFilters[] = "created_at:>='" . $startDateTime->format('Y-m-d\TH:i:s\Z') . "'";
        }

        if (!empty($createdAtMax)) {
            $dateValue = $createdAtMax;
            if (strpos($dateValue, 'T') === false && strpos($dateValue, ' ') === false) {
                $dateValue .= ' 23:59:59';
            }
            $endDateTime = new \DateTime($dateValue, new \DateTimeZone('UTC'));
            $queryFilters[] = "created_at:<='" . $endDateTime->format('Y-m-d\TH:i:s\Z') . "'";
        }

        // Filtrer par financial_status sauf si DOLI2SHOP_SYNC_NON_PAID_ORDERS
        if (!(bool) $this->storeSettings->getInt($this->getStoreId(), 'SYNC_NON_PAID_ORDERS', 0)) {
            $queryFilters[] = "financial_status:'paid'";
        }

        $queryString = implode(' AND ', $queryFilters);

        // Review fix L2: Guard — refuser de fetcher TOUTES les commandes si aucun filtre
        // Story 63-18 (review 24/08, CRITICAL) : ce refus n'est PAS une plage vide légitime —
        // 'error' => true pour que l'appelant ne le confonde jamais avec une plage épuisée.
        if (empty($queryFilters)) {
            $this->log("fetchHistoricalOrdersBatch - ABORT: no filters set (would fetch ALL orders), at least one date filter is required", LOG_ERR);
            return [
                'orders' => [],
                'hasNextPage' => false,
                'endCursor' => null,
                'error' => true,
            ];
        }

        $this->log("fetchHistoricalOrdersBatch - query: " . $queryString . ", cursor: " . ($cursor ?: 'null') . ", batch: " . $batchSize, LOG_DEBUG);

        // Requête GraphQL paginée avec le MÊME field set que buildSingleOrderQuery()
        $orderFields = $this->buildOrderFieldsFragment();
        $gqlQuery = '
            query($first: Int!, $cursor: String, $query: String) {
                orders(first: $first, after: $cursor, query: $query, sortKey: CREATED_AT) {
                    edges {
                        node {
                            ' . $orderFields . '
                        }
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
        ';

        $variables = [
            'first' => (int)$batchSize,
            'query' => $queryString,
        ];
        if (!empty($cursor)) {
            $variables['cursor'] = $cursor;
        }

        $result = $this->shopifyApi->executeGraphQL([
            'query' => $gqlQuery,
            'variables' => $variables,
        ]);

        // Story 63-18 (review 24/08, CRITICAL) : distinguer une réponse INEXPLOITABLE (401
        // persistant, throttle épuisé, erreur GraphQL non-Throttled — cf. ShopifyApi::executeGraphQL(),
        // aucune de ces conditions ne lève d'exception) d'une plage RÉELLEMENT vide. Les deux
        // produisaient jusqu'ici exactement le même retour ('orders' => [], 'hasNextPage' => false).
        // Signal retenu : absence de la clé 'data' (réponse non-GraphQL, ex. 401) OU présence de
        // 'errors' (erreur GraphQL, avec ou sans 'data' partiel) => réponse en erreur, quel que soit
        // le contenu de 'data' par ailleurs. Une plage légitimement épuisée a 'data' ET jamais 'errors'.
        if (!isset($result->data) || isset($result->errors)) {
            $this->log("fetchHistoricalOrdersBatch - réponse Shopify inexploitable (pas de 'data' et/ou 'errors' présent) — passe marquée EN ERREUR, à distinguer d'une plage vide", LOG_ERR);
            return [
                'orders' => [],
                'hasNextPage' => false,
                'endCursor' => null,
                'error' => true,
            ];
        }

        if (empty($result->data->orders->edges)) {
            return [
                'orders' => [],
                'hasNextPage' => false,
                'endCursor' => null,
                'error' => false,
            ];
        }

        $orders = [];
        foreach ($result->data->orders->edges as $edge) {
            // Normaliser en stdClass (json_decode round-trip)
            $orders[] = json_decode(json_encode($edge->node));
        }

        $pageInfo = $result->data->orders->pageInfo ?? null;

        return [
            'orders' => $orders,
            'hasNextPage' => isset($pageInfo->hasNextPage) ? $pageInfo->hasNextPage : false,
            'endCursor' => isset($pageInfo->endCursor) ? $pageInfo->endCursor : null,
            'error' => false,
        ];
    }

    /**
     * Build the common order fields fragment for GraphQL queries
     *
     * v2.2.0 Story 14.2: Champs partagés entre buildSingleOrderQuery() et fetchHistoricalOrdersBatch()
     * pour garantir que les données sont identiques quel que soit le chemin d'appel.
     *
     * @return string GraphQL fields fragment
     * @since 2.2.0
     */
    private function buildOrderFieldsFragment()
    {
        // ⚠️ `targetSelection` est EXIGÉ par la condition de remise globale de processDiscounts()
        // (`$discount->targetSelection === 'ALL' || === 'ENTITLED'`). Absent de ce fragment
        // jusqu'en 2.5.5, il rendait cette condition TOUJOURS fausse sur les deux chemins qui
        // l'utilisent — import historique et récupération d'une commande à l'unité : la remise
        // globale ACROSS était silencieusement perdue, et PHP émettait
        // « Undefined property: stdClass::$targetSelection » (constaté en production).
        // Toute modification de ce fragment doit rester alignée sur les champs réellement lus par
        // processDiscounts() ; le test OrderFieldsFragmentDiscountParityTest le vérifie.
        return '
            id
            name
            createdAt
            processedAt
            cancelledAt
            currencyCode
            updatedAt
            displayFinancialStatus
            displayFulfillmentStatus
            taxesIncluded
            totalPriceSet {
                shopMoney {
                    amount
                    currencyCode
                }
            }
            totalTaxSet {
                shopMoney {
                    amount
                    currencyCode
                }
            }
            subtotalPriceSet {
                shopMoney {
                    amount
                    currencyCode
                }
            }
            totalDiscountsSet {
                shopMoney {
                    amount
                    currencyCode
                }
            }
            totalShippingPriceSet {
                shopMoney {
                    amount
                    currencyCode
                }
            }
            note
            tags
            sourceName
            email
            customAttributes {
                key
                value
            }
            customer {
                id
                email
                firstName
                lastName
                phone
                defaultAddress {
                    company
                    address1
                    address2
                    city
                    zip
                    province
                    country
                    countryCodeV2
                    phone
                }
            }
            shippingAddress {
                company
                firstName
                lastName
                address1
                address2
                city
                zip
                province
                country
                countryCodeV2
                phone
            }
            billingAddress {
                company
                firstName
                lastName
                address1
                address2
                city
                zip
                province
                country
                countryCodeV2
                phone
            }
            lineItems(first: 40) {
                edges {
                    node {
                        id
                        title
                        quantity
                        sku
                        customAttributes {
                            key
                            value
                        }
                        product {
                            id
                        }
                        variant {
                            id
                            sku
                            title
                            price
                        }
                        originalUnitPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                        discountedUnitPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                        discountAllocations {
                            allocatedAmountSet {
                                shopMoney {
                                    amount
                                    currencyCode
                                }
                            }
                            discountApplication {
                                allocationMethod
                                targetSelection
                                targetType
                            }
                        }
                        taxLines {
                            rate
                            title
                            priceSet {
                                shopMoney {
                                    amount
                                    currencyCode
                                }
                            }
                        }
                    }
                }
            }
            shippingLines(first: 5) {
                edges {
                    node {
                        title
                        code
                        originalPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                        discountedPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                        taxLines {
                            rate
                            title
                            priceSet {
                                shopMoney {
                                    amount
                                    currencyCode
                                }
                            }
                        }
                    }
                }
            }
            discountApplications(first: 10) {
                edges {
                    node {
                        ... on DiscountCodeApplication {
                            code
                        }
                        targetType
                        allocationMethod
                        targetSelection
                        value {
                            ... on MoneyV2 {
                                amount
                                currencyCode
                            }
                            ... on PricingPercentageValue {
                                percentage
                            }
                        }
                    }
                }
            }
            transactions(first: 5) {
                id
                gateway
                status
                kind
                amountSet {
                    shopMoney {
                        amount
                        currencyCode
                    }
                }
                paymentDetails {
                    ... on CardPaymentDetails {
                        paymentMethodName
                    }
                }
            }
            metafields(first: 50) {
                edges {
                    node {
                        namespace
                        key
                        value
                        type
                    }
                }
            }
        ';
    }

    /**
     * Build GraphQL query for fetching a single order with all fields needed by createOrder()
     *
     * @return string GraphQL query
     * @since 2.1.8
     */
    private function buildSingleOrderQuery()
    {
        // v2.2.0 Story 14.2: Utilise le fragment partagé pour garantir la cohérence des champs
        $orderFields = $this->buildOrderFieldsFragment();
        return '
            query($query: String!) {
                orders(first: 1, query: $query) {
                    edges {
                        node {
                            ' . $orderFields . '
                        }
                    }
                }
            }
        ';
    }

    /**
     * Rattrapage commandes manquees par comparaison Shopify vs Dolibarr
     *
     * Compare les commandes recentes Shopify (payees) avec les commandes importees
     * dans Dolibarr. Les commandes presentes sur Shopify mais absentes sont importees
     * individuellement via processSingleOrderFromWebhook().
     *
     * Story 1.4 - Epic 1: Fiabilite des webhooks temps reel
     *
     * @param int $lookbackHours Fenetre de recherche en heures (defaut: 168 = 7 jours)
     * @return array ['checked'=>int, 'missing'=>int, 'imported'=>int, 'failed'=>int, 'skipped_pending'=>int]
     * @since 2.2.0
     */
    public function catchupMissingOrders($lookbackHours = 168)
    {
        global $conf;

        $this->errors = array();
        $startTime = microtime(true);
        $counters = array(
            'checked' => 0,
            'missing' => 0,
            'imported' => 0,
            'failed' => 0,
            'skipped_pending' => 0
        );

        $this->log("ShopifyOrderManager::catchupMissingOrders - Starting catchup (lookback: {$lookbackHours}h, entity: {$this->entity})", LOG_INFO);

        // Anti-boucle : empêcher les triggers Dolibarr de renvoyer vers Shopify
        // pendant le rattrapage de commandes FROM Shopify
        $flagAlreadySet = (bool) getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS');
        if (!$flagAlreadySet) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;
        }

        try {
            // 1. Pre-fetch des commandes en attente de traitement webhook (AC #2)
            $pendingWebhookOrderIds = $this->getPendingWebhookOrderIds();

            // 2. Calculer la date de debut de la fenetre de recherche
            $lookbackDate = new \DateTime();
            $lookbackDate->sub(new \DateInterval('PT' . (int)$lookbackHours . 'H'));
            $updatedAtMin = $lookbackDate->format('Y-m-d\TH:i:s\Z');

            $this->log("ShopifyOrderManager::catchupMissingOrders - Fetching paid orders since " . $updatedAtMin, LOG_DEBUG);

            // 3. Recuperer les commandes Shopify payees dans la fenetre
            $params = array(
                'financial_status' => 'paid',
                'updated_at_min' => $updatedAtMin
            );
            $cursor = null;
            $maxImports = getDolGlobalInt('DOLI2SHOP_CATCHUP_MAX_ORDERS', 50);
            $batchSize = 50;
            $maxChecked = 500; // Limite globale d'ordres verifies par execution

            do {
                $response = $this->shopifyApi->getOrdersWithPagination($params, $cursor, $batchSize);

                if (empty($response) || !isset($response->orders)) {
                    $this->log("ShopifyOrderManager::catchupMissingOrders - API error: empty response or no orders field", LOG_ERR);
                    $counters['failed']++;
                    $this->errors[] = 'API returned empty or malformed response during pagination';
                    break;
                }

                $orders = $response->orders;
                $pageInfo = $response->pageInfo;

                $this->log("ShopifyOrderManager::catchupMissingOrders - Batch retrieved: " . count($orders) . " orders", LOG_DEBUG);

                foreach ($orders as $order) {
                    $counters['checked']++;

                    // Extraire l'ID numerique Shopify
                    $shopifyOrderId = str_replace('gid://shopify/Order/', '', $order->id);
                    $shopifyOrderName = isset($order->name) ? $order->name : '';

                    // Verification 1 : La commande existe-t-elle deja dans Dolibarr ?
                    if ($this->orderExists($shopifyOrderId, $shopifyOrderName, 'catchup')) {
                        // v2.2.0: Rattrapage facture/paiement pour commandes existantes incomplètes
                        global $user;
                        $this->catchUpInvoiceAndPayment($shopifyOrderId, $user);
                        continue;
                    }

                    // Verification 2 (AC #2) : Est-elle en attente de traitement webhook ?
                    if (isset($pendingWebhookOrderIds[$shopifyOrderId])) {
                        $counters['skipped_pending']++;
                        $this->log("ShopifyOrderManager::catchupMissingOrders - Commande #" . $shopifyOrderId . " deja en cours de traitement", LOG_DEBUG);
                        continue;
                    }

                    // La commande est manquante — l'importer
                    $counters['missing']++;

                    // Verifier la limite d'imports par execution
                    if ($counters['imported'] >= $maxImports) {
                        $this->log("ShopifyOrderManager::catchupMissingOrders - Max imports reached (" . $maxImports . "), stopping", LOG_INFO);
                        break 2;
                    }

                    $this->log("ShopifyOrderManager::catchupMissingOrders - Rattrapage: commande Shopify #" . $shopifyOrderId . " manquante, importation...", LOG_INFO);

                    $errorsBefore = $this->errors; // Sauvegarder erreurs existantes
                    $this->errors = array();
                    $result = $this->processSingleOrderFromWebhook($shopifyOrderId);

                    if ($result > 0) {
                        $counters['imported']++;
                        $this->log("ShopifyOrderManager::catchupMissingOrders - Rattrapage: commande Shopify #" . $shopifyOrderId . " importee (dolibarrOrderId=" . $result . ")", LOG_INFO);
                    } else {
                        $counters['failed']++;
                        $importErrors = implode(', ', $this->errors);
                        $this->log("ShopifyOrderManager::catchupMissingOrders - Echec import commande Shopify #" . $shopifyOrderId . ": " . $importErrors, LOG_ERR);
                    }
                    // Restaurer les erreurs accumulees + ajouter les nouvelles
                    $this->errors = array_merge($errorsBefore, $this->errors);

                    // Rate limiting : 500ms entre chaque import pour eviter le rate limit API
                    usleep(500000);
                }

                // Verifier limite globale
                if ($counters['checked'] >= $maxChecked) {
                    $this->log("ShopifyOrderManager::catchupMissingOrders - Max checked limit reached (" . $maxChecked . "), stopping", LOG_INFO);
                    break;
                }

                // Pagination
                if (isset($pageInfo->hasNextPage) && $pageInfo->hasNextPage && !empty($pageInfo->endCursor)) {
                    $cursor = $pageInfo->endCursor;
                } else {
                    break;
                }
            } while (true);

        } catch (\Throwable $e) {
            $this->log("ShopifyOrderManager::catchupMissingOrders - Exception: " . $e->getMessage(), LOG_ERR);
            $this->errors[] = $e->getMessage();
            $counters['failed']++; // Assurer que le CRON detecte l'echec
        } finally {
            // Toujours retirer le flag anti-boucle si posé par cet appel (nesting guard)
            if (!$flagAlreadySet) {
                unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($counters['missing'] == 0) {
            $this->log("ShopifyOrderManager::catchupMissingOrders - No missing orders found (" . $counters['checked'] . " checked) in " . $elapsedMs . "ms", LOG_DEBUG);
        } else {
            $this->log("ShopifyOrderManager::catchupMissingOrders - Completed in " . $elapsedMs . "ms: "
                . $counters['checked'] . " checked, "
                . $counters['missing'] . " missing, "
                . $counters['imported'] . " imported, "
                . $counters['failed'] . " failed, "
                . $counters['skipped_pending'] . " skipped_pending", LOG_INFO);
        }

        return $counters;
    }

    /**
     * Pre-fetch des Shopify order IDs en attente de traitement dans la table webhook_events
     *
     * Recupere les IDs des commandes Shopify dont les webhooks sont en cours de traitement
     * (status pending ou processing). Utilise pour eviter que le catchup n'importe une commande
     * deja en cours de traitement par un webhook (AC #2 Story 1.4).
     *
     * @return array Associative array [shopifyOrderId => true]
     * @throws \RuntimeException Si la requete SQL echoue (securite : ne pas continuer sans deduplication)
     * @since 2.2.0
     */
    private function getPendingWebhookOrderIds()
    {
        $pendingIds = array();

        $sql = "SELECT payload FROM " . MAIN_DB_PREFIX . "doli2shop_webhook_events";
        $sql .= " WHERE status IN (0, 3)"; // 0=pending, 3=processing
        $sql .= " AND topic IN ('orders/create', 'orders/paid', 'orders/updated')";
        $sql .= " AND entity = " . (int)$this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $errorMsg = "ShopifyOrderManager::getPendingWebhookOrderIds - SQL error: " . $this->db->lasterror();
            $this->log($errorMsg, LOG_ERR);
            // Securite : ne pas continuer le catchup sans les donnees de deduplication webhook
            throw new \RuntimeException($errorMsg);
        }

        while ($obj = $this->db->fetch_object($result)) {
            if (!empty($obj->payload)) {
                $data = json_decode($obj->payload, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->log("ShopifyOrderManager::getPendingWebhookOrderIds - Failed to decode payload: " . json_last_error_msg(), LOG_WARNING);
                    continue;
                }
                if (is_array($data) && !empty($data['id'])) {
                    $pendingIds[(string)$data['id']] = true;
                }
            }
        }
        $this->db->free($result);

        $this->log("ShopifyOrderManager::getPendingWebhookOrderIds - Found " . count($pendingIds) . " pending order events", LOG_DEBUG);
        return $pendingIds;
    }
}
