<?php
/**
 * @file        class/configurationMigrator.class.php
 * @brief       Utility class for migrating configuration from table to Dolibarr constants
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.27
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible avec chargement dans init() et par modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

/**
 * Classe utilitaire pour gérer la migration des paramètres de configuration
 * De la table llx_doli2shop_storedetails vers les constantes Dolibarr standard
 */
class ConfigurationMigrator
{
    use LoggerTrait;
    
    /**
     * @var DoliDB Database handler
     */
    private $db;
    
    /**
     * @var array Mapping des champs table vers constantes (ordre logique par fonctionnalité)
     */
    private $fieldsMapping = [
        // ====== 1. SHOPIFY - Connexion API (paramètres critiques) ======
        'shopify_store_hostname' => ['name' => 'DOLI2SHOP_STORE_HOSTNAME', 'type' => 'chaine'],
        'shopify_access_token' => ['name' => 'DOLI2SHOP_ACCESS_TOKEN', 'type' => 'chaine'],
        'shopify_api_key' => ['name' => 'DOLI2SHOP_API_KEY', 'type' => 'chaine'],
        'shopify_api_secret_key' => ['name' => 'DOLI2SHOP_API_SECRET_KEY', 'type' => 'chaine'],

        // ====== 1bis. SHOPIFY - Jetons expirables + refresh (Story 51-1) ======
        'shopify_token_expires_at' => ['name' => 'DOLI2SHOP_TOKEN_EXPIRES_AT', 'type' => 'chaine'],
        'shopify_refresh_token' => ['name' => 'DOLI2SHOP_REFRESH_TOKEN', 'type' => 'chaine'],

        // ====== 2. SHOPIFY - Configuration boutique ======
        'shopify_location_id' => ['name' => 'DOLI2SHOP_LOCATION_ID', 'type' => 'chaine'],

        // ====== 3. DOLIBARR - Connexion API ======
        // Story 57-5 : mapping 'dolibarr_hosturl'/'dolibarr_api_key' retiré — plus aucun consommateur
        // dans le module depuis 57-7 (accès direct base+disque pour les images), et leur maintien
        // exposait `dolibarr_hosturl` en clair dans l'export JSON de support (absent de
        // `$sensitiveKeys` dans admin/diagnostic.php et admin/health.php). Les constantes
        // `DOLI2SHOP_DOLIBARR_HOSTURL`/`DOLI2SHOP_DOLIBARR_API_KEY` en base ne sont ni supprimées ni
        // migrées — ce retrait ne change que la configuration renvoyée par getConfiguration(),
        // jamais les données existantes.

        // ====== 4. DOLIBARR - Configuration ======
        'dolibarr_procate' => ['name' => 'DOLI2SHOP_DOLIBARR_PROCATE', 'type' => 'chaine'],
        'dolibarr_customer_category' => ['name' => 'DOLI2SHOP_DOLIBARR_CUSTOMER_CATEGORY', 'type' => 'chaine'],

        // ====== 4bis. DOLIBARR - Type de tiers v2.2.0 (Story 13.1) ======
        'typent_with_company' => ['name' => 'DOLI2SHOP_TYPENT_WITH_COMPANY', 'type' => 'entier'],
        'typent_without_company' => ['name' => 'DOLI2SHOP_TYPENT_WITHOUT_COMPANY', 'type' => 'entier'],

        // ====== 5. SYNCHRONISATION - Produits ======
        'products_per_cron_update' => ['name' => 'DOLI2SHOP_PRODUCTS_PER_CRON_UPDATE', 'type' => 'entier'],
        'use_virtual_stock' => ['name' => 'DOLI2SHOP_USE_VIRTUAL_STOCK', 'type' => 'yesno'],
        'sync_product_descriptions' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_DESCRIPTIONS', 'type' => 'yesno'],
        'sync_product_images' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_IMAGES', 'type' => 'yesno'],
        // ====== ANCIENS CHAMPS - Mappings legacy pour éviter "constants missing" ======
        'sync_products_descriptions' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_DESCRIPTIONS', 'type' => 'yesno'], // Alias
        'sync_products_images' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_IMAGES', 'type' => 'yesno'], // Alias
        'sync_products_prices' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_PRICES', 'type' => 'yesno'], // Alias
        'sync_products_status' => ['name' => 'DOLI2SHOP_SYNC_PRODUCTS_STATUS', 'type' => 'yesno', 'default' => '0'],
        'which_price_to_sync' => ['name' => 'DOLI2SHOP_WHICH_PRICE_TO_SYNC', 'type' => 'entier', 'default' => '1'],

        // ====== 6. SYNCHRONISATION - Commandes ======
        'max_orders_per_sync' => ['name' => 'DOLI2SHOP_MAX_ORDERS_PER_SYNC', 'type' => 'entier'],
        'order_prefix' => ['name' => 'DOLI2SHOP_ORDER_PREFIX', 'type' => 'chaine'],
        'order_origin' => ['name' => 'DOLI2SHOP_ORDER_ORIGIN', 'type' => 'entier'],
        'payment_terms' => ['name' => 'DOLI2SHOP_PAYMENT_TERMS', 'type' => 'entier'],
        'order_bank_account' => ['name' => 'DOLI2SHOP_ORDER_BANK_ACCOUNT', 'type' => 'entier'],

        // ====== 7. LIVRAISON ======
        'delivery_delay' => ['name' => 'DOLI2SHOP_DELIVERY_DELAY', 'type' => 'entier'],
        'delivery_delay_type' => ['name' => 'DOLI2SHOP_DELIVERY_DELAY_TYPE', 'type' => 'chaine'],
        'default_delivery_days' => ['name' => 'DOLI2SHOP_DEFAULT_DELIVERY_DAYS', 'type' => 'entier'],
        'default_shipping_method_id' => ['name' => 'DOLI2SHOP_DEFAULT_SHIPPING_METHOD_ID', 'type' => 'entier'],
        'default_warehouse_id' => ['name' => 'DOLI2SHOP_DEFAULT_WAREHOUSE_ID', 'type' => 'entier'],

        // ====== (suite section 5) SYNCHRONISATION - Produits avancées ======
        'sync_product_prices' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_PRICES', 'type' => 'yesno'],
        'sync_price_level' => ['name' => 'DOLI2SHOP_SYNC_PRICE_LEVEL', 'type' => 'entier'],
        'price_priority_ttc' => ['name' => 'DOLI2SHOP_PRICE_PRIORITY_TTC', 'type' => 'yesno'],
        'sync_product_stocks' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_STOCKS', 'type' => 'yesno'],
        'sync_product_attributes' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_ATTRIBUTES', 'type' => 'yesno'],
        // Story 58-5 (AC1) : ne pousser vers Shopify QUE les produits "En Vente" (tosell=1) à la
        // CRÉATION. OFF par défaut (rétrocompatibilité stricte) : volontairement PAS d'entrée
        // dans $defaults ci-dessous (getConfiguration()) — même schéma que sync_product_attributes,
        // pas celui de use_virtual_stock. Ne PAS réutiliser sync_products_status/
        // DOLI2SHOP_SYNC_PRODUCTS_STATUS (clé orpheline jamais câblée depuis 09/2025, cf. story 58-5).
        'sync_products_only_active' => ['name' => 'DOLI2SHOP_SYNC_PRODUCTS_ONLY_ACTIVE', 'type' => 'yesno'],
        'inventory_policy_continue_selling' => ['name' => 'DOLI2SHOP_INVENTORY_POLICY_CONTINUE_SELLING', 'type' => 'yesno'],

        // ====== (suite section 5) SYNCHRONISATION - Produits direction v2.1.6 ======
        'sync_products_direction' => ['name' => 'DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', 'type' => 'chaine'],
        'sync_products_conflict_resolution' => ['name' => 'DOLI2SHOP_SYNC_PRODUCTS_CONFLICT_RESOLUTION', 'type' => 'chaine'],
        'shopify_vendor' => ['name' => 'DOLI2SHOP_VENDOR', 'type' => 'chaine'],
        'vendor' => ['name' => 'DOLI2SHOP_VENDOR', 'type' => 'chaine'], // Alias normalisé (Story 49-4 code review)

        // ====== 8. PRODUITS SPÉCIAUX ======
        'shipping_product_id' => ['name' => 'DOLI2SHOP_SHIPPING_PRODUCT_ID', 'type' => 'entier'],
        'tip_product_id' => ['name' => 'DOLI2SHOP_TIP_PRODUCT_ID', 'type' => 'entier'],

        // ====== 9. IMPORT HISTORIQUE ======
        'historical_import_enabled' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_ENABLED', 'type' => 'yesno'],
        'historical_import_start_date' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_START_DATE', 'type' => 'chaine'], // Date originale configurée par utilisateur
        'historical_import_resume_date' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE', 'type' => 'chaine'], // Point de reprise automatique
        'historical_import_end_date' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_END_DATE', 'type' => 'chaine'],
        'historical_import_completed' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED', 'type' => 'yesno'],
        'historical_import_completed_date' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED_DATE', 'type' => 'entier'],
        'historical_import_total_count' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_TOTAL_COUNT', 'type' => 'entier'],
        'historical_import_processed_count' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_PROCESSED_COUNT', 'type' => 'entier'],
        'historical_import_skipped_count' => ['name' => 'DOLI2SHOP_HISTORICAL_IMPORT_SKIPPED_COUNT', 'type' => 'entier'],

        // v2.2.0: orders_cron_enabled / DOLI2SHOP_ORDERS_CRON_ENABLED supprimé (CRON OrdersImport supprimé)

        // ====== 9ter. AUTO-CRÉATION FACTURE v2.2.0 (Story 2.2) ======
        'auto_create_invoice' => ['name' => 'DOLI2SHOP_AUTO_CREATE_INVOICE', 'type' => 'yesno'],

        // ====== 9quater. AUTO-VALIDATION FACTURE v2.2.0 (Story 12.1) ======
        'auto_validate_invoice' => ['name' => 'DOLI2SHOP_AUTO_VALIDATE_INVOICE', 'type' => 'yesno'],

        // ====== 9quinquies. AUTO-CRÉATION PAIEMENT v2.2.0 (Story 12.3) ======
        'auto_create_payment' => ['name' => 'DOLI2SHOP_AUTO_CREATE_PAYMENT', 'type' => 'yesno'],

        // ====== 9sexies. AUTO-CRÉATION EXPÉDITION v2.2.0 (Story 2.3) ======
        'auto_create_expedition' => ['name' => 'DOLI2SHOP_AUTO_CREATE_EXPEDITION', 'type' => 'yesno'],

        // ====== 9septies. SOURCE PRIX D'ACHAT v2.2.0 (Story 20.1) ======
        'buying_price_source' => ['name' => 'DOLI2SHOP_BUYING_PRICE_SOURCE', 'type' => 'chaine'],

        // ====== 9octies. LIGNES BUNDLES/CADEAUX v2.2.2 (Story 24.2) ======
        // 0 = ignorer (défaut), 1 = importer comme commentaire, 2 = importer comme ligne normale
        'bundle_lines_behavior' => ['name' => 'DOLI2SHOP_BUNDLE_LINES_BEHAVIOR', 'type' => 'entier'],

        // ====== 9nonies. AUTO-CLÔTURE COMMANDE v2.2.2 (option oubliée du setup standard) ======
        'auto_close_order' => ['name' => 'DOLI2SHOP_AUTO_CLOSE_ORDER', 'type' => 'yesno'],

        // ====== 9decies. SYNC COMMANDES NON PAYÉES v2.3.x (Story 49-4) ======
        'sync_non_paid_orders' => ['name' => 'DOLI2SHOP_SYNC_NON_PAID_ORDERS', 'type' => 'yesno'],

        // ====== 9undecies. AUTO-CLASSEMENT FACTURÉ v2.3.x (Story 49-4) ======
        'auto_classify_billed' => ['name' => 'DOLI2SHOP_AUTO_CLASSIFY_BILLED', 'type' => 'yesno'],

        // ====== 10. SYNCHRONISATION - Collections ======
        'sync_product_collections' => ['name' => 'DOLI2SHOP_SYNC_PRODUCT_COLLECTIONS', 'type' => 'yesno'],
        'sync_collections_direction' => ['name' => 'DOLI2SHOP_SYNC_COLLECTIONS_DIRECTION', 'type' => 'chaine'],
        'sync_collections_conflict_resolution' => ['name' => 'DOLI2SHOP_SYNC_COLLECTIONS_CONFLICT_RESOLUTION', 'type' => 'chaine'],
        'collections_sales_channels' => ['name' => 'DOLI2SHOP_COLLECTIONS_SALES_CHANNELS', 'type' => 'chaine'],
        'include_parent_categories' => ['name' => 'DOLI2SHOP_INCLUDE_PARENT_CATEGORIES', 'type' => 'yesno']
    ];
    
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
     * Vérifie si la migration est nécessaire
     *
     * @param int $entity Entity ID
     * @return bool True if migration is needed
     */
    public function isMigrationNeeded($entity = null)
    {
        global $conf;

        if ($entity === null) {
            $entity = $conf->entity;
        }

        // v2.2.1: Si la table obsolète n'existe pas, pas de migration nécessaire
        // Évite les erreurs SQL répétées dans les logs pour les installations >= v2.1.0
        if (!$this->tableExists()) {
            return false;
        }

        // Vérifier s'il y a des données dans l'ancienne table
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = " . (int)$entity;
        $result = $this->db->query($sql);
        
        if (!$result) {
            $this->log("Error checking migration need: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
        
        $obj = $this->db->fetch_object($result);
        $hasOldData = ($obj->count > 0);
        
        // Vérifier s'il y a déjà des constantes migrées (seulement celles du mapping)
        $constantNames = array_map(function($mapping) { return $mapping['name']; }, $this->fieldsMapping);
        $constantNamesStr = "'" . implode("', '", $constantNames) . "'";
        
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "const 
                WHERE name IN ($constantNamesStr) AND entity = " . (int)$entity;
        $result = $this->db->query($sql);
        
        if (!$result) {
            $this->log("Error checking existing constants: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
        
        $obj = $this->db->fetch_object($result);
        $hasNewData = ($obj->count > 0);
        
        $this->log("Migration check - Entity: $entity, Old data: " . ($hasOldData ? 'YES' : 'NO') . ", New data: " . ($hasNewData ? 'YES' : 'NO'), LOG_INFO);
        
        return $hasOldData && !$hasNewData;
    }
    
    /**
     * Exécute la migration automatique
     *
     * @param int $entity Entity ID
     * @return bool Success status
     */
    public function runMigration($entity = null)
    {
        global $conf;

        if ($entity === null) {
            $entity = $conf->entity;
        }

        // v2.2.1: Si la table obsolète n'existe pas, rien à migrer
        if (!$this->tableExists()) {
            $this->log("runMigration - Table storedetails absente, migration non nécessaire", LOG_INFO);
            return true;
        }

        $this->log("Starting automatic migration for entity: $entity", LOG_INFO);

        $this->db->begin();

        try {
            $migratedCount = 0;

            // Récupérer la configuration de l'ancienne table
            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = " . (int)$entity;
            $result = $this->db->query($sql);
            
            if (!$result || $this->db->num_rows($result) == 0) {
                $this->log("No configuration found to migrate for entity: $entity", LOG_WARNING);
                $this->db->rollback();
                return false;
            }
            
            $config = $this->db->fetch_object($result);
            
            // Migrer chaque champ
            foreach ($this->fieldsMapping as $oldField => $constInfo) {
                if (property_exists($config, $oldField) && $config->$oldField !== null) {
                    // Accepter les valeurs 0, "0", chaînes vides - seul NULL est exclu
                    $success = $this->migrateField($oldField, $config->$oldField, $constInfo, $entity);
                    if ($success) {
                        $migratedCount++;
                    }
                }
            }
            
            $this->log("Migration completed successfully: $migratedCount parameters migrated", LOG_INFO);
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->log("Migration failed: " . $e->getMessage(), LOG_ERR);
            $this->db->rollback();
            return false;
        }
    }
    
    /**
     * Force la migration complète même si des constantes existent déjà
     * Utile pour corriger des migrations incomplètes
     *
     * @param int $entity Entity ID
     * @return bool Success status
     */
    public function forceMigration($entity = null)
    {
        global $conf;
        
        if ($entity === null) {
            $entity = $conf->entity;
        }
        
        $this->log("Starting FORCED migration for entity: $entity", LOG_INFO);
        
        $this->db->begin();
        
        try {
            $migratedCount = 0;
            
            // Récupérer la configuration de l'ancienne table
            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = " . (int)$entity;
            $result = $this->db->query($sql);
            
            if (!$result || $this->db->num_rows($result) == 0) {
                $this->log("No configuration found to migrate for entity: $entity", LOG_WARNING);
                $this->db->rollback();
                return false;
            }
            
            $config = $this->db->fetch_object($result);
            
            // FORCER la migration de TOUS les champs, même ceux déjà migrés
            foreach ($this->fieldsMapping as $oldField => $constInfo) {
                if (property_exists($config, $oldField) && $config->$oldField !== null) {
                    // Supprimer d'abord la constante existante si elle existe
                    $deleteSql = "DELETE FROM " . MAIN_DB_PREFIX . "const 
                                  WHERE name = '" . $this->db->escape($constInfo['name']) . "' 
                                  AND entity = " . (int)$entity;
                    $this->db->query($deleteSql);
                    
                    // Migrer le champ
                    $success = $this->migrateField($oldField, $config->$oldField, $constInfo, $entity);
                    if ($success) {
                        $migratedCount++;
                        // FIX MEDIUM (review 51-1) : loguer la VALEUR en clair pour un champ
                        // sensible (token/clé/secret) exposait le secret complet en LOG_INFO.
                        $this->log("FORCED migration: $oldField -> {$constInfo['name']} = " . $this->redactValueForLog($oldField, $config->$oldField), LOG_INFO);
                    }
                }
            }
            
            $this->log("FORCED migration completed successfully: $migratedCount parameters migrated", LOG_INFO);
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->log("FORCED migration failed: " . $e->getMessage(), LOG_ERR);
            $this->db->rollback();
            return false;
        }
    }
    
    /**
     * Migre un champ spécifique vers une constante
     *
     * @param string $fieldName Nom du champ
     * @param mixed $value Valeur
     * @param array $constInfo Informations sur la constante
     * @param int $entity Entity ID
     * @return bool Success status
     */
    private function migrateField($fieldName, $value, $constInfo, $entity)
    {
        dol_include_once('/core/lib/admin.lib.php');
        
        // Convertir la valeur selon le type
        $convertedValue = $this->convertValue($value, $constInfo['type']);
        
        $result = dolibarr_set_const($this->db, $constInfo['name'], $convertedValue, $constInfo['type'], 0, 
                                    "Migrated from storedetails table - Field: $fieldName", $entity);
        
        if ($result > 0) {
            // FIX MEDIUM (review 51-1) : idem — ne jamais loguer la valeur en clair d'un champ sensible.
            $this->log("Migrated $fieldName -> {$constInfo['name']} = " . $this->redactValueForLog($fieldName, $convertedValue), LOG_DEBUG);
            return true;
        } else {
            $this->log("Failed to migrate $fieldName -> {$constInfo['name']}", LOG_ERR);
            return false;
        }
    }

    /**
     * Redacte la valeur d'un champ sensible avant de le loguer (FIX MEDIUM, review 51-1).
     * Les deux logs génériques de migration (`FORCED migration`/`Migrated`) affichaient la
     * valeur en clair des champs, y compris tokens/clés/secrets — jamais acceptable même en
     * LOG_INFO/LOG_DEBUG (mémoire projet : ne jamais logger credentials ou données sensibles).
     *
     * @param string $fieldName Nom du champ (clé de $fieldsMapping, ex. 'shopify_access_token')
     * @param mixed  $value     Valeur à (éventuellement) redacter
     * @return string '***' si le champ est sensible, sinon la valeur castée en string
     */
    private function redactValueForLog($fieldName, $value)
    {
        // Story 57-5 : 'dolibarr_api_key' retiré — n'est plus une clé de $fieldsMapping, donc
        // jamais passé en $fieldName ici (defense-in-depth devenue inatteignable, retirée avec le
        // mapping plutôt que laissée inerte).
        $sensitiveFields = [
            'shopify_refresh_token',
            'shopify_access_token',
            'shopify_api_secret_key',
        ];

        if (in_array($fieldName, $sensitiveFields, true)) {
            return '***';
        }

        return (string) $value;
    }

    /**
     * Convertit une valeur selon le type de constante Dolibarr
     *
     * @param mixed $value Original value
     * @param string $type Dolibarr constant type
     * @return string Converted value
     */
    private function convertValue($value, $type)
    {
        switch ($type) {
            case 'yesno':
                return ($value == 1 || $value === true || $value === '1') ? '1' : '0';
            case 'entier':
                return (string)(int)$value;
            case 'chaine':
            default:
                return (string)$value;
        }
    }
    
    /**
     * Récupère la configuration en utilisant les constantes avec fallback vers la table
     *
     * @param int $entity Entity ID
     * @return array Configuration array
     */
    public function getConfiguration($entity = null)
    {
        global $conf;

        if ($entity === null) {
            $entity = $conf->entity;
        }

        $config = [];
        $missingConstants = [];
        $criticalConstants = ['shopify_store_hostname', 'shopify_access_token', 'shopify_api_key'];
        $criticalMissing = 0;

        // Essayer d'abord de lire depuis les constantes
        foreach ($this->fieldsMapping as $oldField => $constInfo) {
            $value = $this->getConstantValue($constInfo['name'], $entity);

            if ($value !== null) {
                $config[$oldField] = $value;
            } else {
                $missingConstants[] = $oldField;
                if (in_array($oldField, $criticalConstants)) {
                    $criticalMissing++;
                }
            }
        }

        // Utiliser valeurs par défaut pour les constantes manquantes (plus de fallback table)
        if (!empty($missingConstants)) {
            $this->log("Constants missing (" . implode(', ', $missingConstants) . "), using default values", LOG_INFO);

            $defaults = [
                'max_orders_per_sync' => '10',
                'products_per_cron_update' => '10',
                'use_virtual_stock' => '1',
                'delivery_delay' => '2',
                'delivery_delay_type' => 'working',
                'default_delivery_days' => '3',
                'dolibarr_customer_category' => '0',
                'historical_import_enabled' => '0',
                'historical_import_completed' => '0',
                'historical_import_total_count' => '0',
                'historical_import_processed_count' => '0',
                'historical_import_skipped_count' => '0'
            ];

            foreach ($missingConstants as $field) {
                if (isset($defaults[$field])) {
                    $config[$field] = $defaults[$field];
                }
            }
        }

        // Toujours ajouter les valeurs par défaut pour les nouvelles configurations v2.0.35
        $newConfigDefaults = [
            'dolibarr_customer_category' => '0',
            'historical_import_total_count' => '0',
            'historical_import_processed_count' => '0',
            'historical_import_skipped_count' => '0'
        ];

        foreach ($newConfigDefaults as $field => $defaultValue) {
            if (!isset($config[$field])) {
                $config[$field] = $defaultValue;
            }
        }

        // Réorganiser le tableau dans l'ordre logique défini par fieldsMapping
        $orderedConfig = [];
        foreach ($this->fieldsMapping as $field => $constInfo) {
            if (isset($config[$field])) {
                $orderedConfig[$field] = $config[$field];
            }
        }

        // Ajouter les champs supplémentaires qui ne sont pas dans fieldsMapping (au cas où)
        foreach ($config as $field => $value) {
            if (!isset($orderedConfig[$field])) {
                $orderedConfig[$field] = $value;
            }
        }

        // Story 57-5 : fallback URL runtime (Story 56.1) retiré — dolibarr_hosturl n'est plus dans
        // $fieldsMapping (plus aucun consommateur depuis 57-7, accès direct base+disque pour les
        // images), ce fallback n'avait donc plus rien à alimenter.

        // FIX #152 v2.1.2: Garder le retour en ARRAY (13 endroits du code attendent un array)
        // ShopifyApi fait la conversion en objet lui-même (ligne 137)
        return $orderedConfig;
    }
    
    /**
     * Récupère une constante avec gestion de l'entité
     *
     * FIX #152 v2.1.2: VRAIE CORRECTION - Lecture DIRECTE depuis base de données
     *
     * PROBLÈME INITIAL: getDolGlobalString() + empty() ne distingue pas "constante absente" vs "constante = 0"
     * PROBLÈME RÉEL: getDolGlobal*() lit depuis $conf->global (cache) qui est chargé UNE FOIS au démarrage
     *                Changer $conf->entity ne recharge PAS les constantes depuis la base !
     *
     * SOLUTION DÉFINITIVE: Requête SQL directe pour lire la valeur réelle depuis llx_const
     *
     * @param string $name Constant name
     * @param int $entity Entity ID
     * @return mixed Constant value or null
     */
    private function getConstantValue($name, $entity)
    {
        // FIX #154 v2.1.3: Liste des constantes cryptées sensibles (clés API)
        // Ces constantes sont stockées cryptées avec dolcrypt: et nécessitent getDolGlobalString()
        // Story 57-5 : 'DOLI2SHOP_DOLIBARR_API_KEY' retirée — n'est plus le nom d'aucune entrée de
        // $fieldsMapping, donc plus jamais passée en $name ici (defense-in-depth devenue
        // inatteignable, retirée avec le mapping plutôt que laissée inerte).
        $encryptedConstants = [
            'DOLI2SHOP_ACCESS_TOKEN',
            'DOLI2SHOP_API_KEY',
            'DOLI2SHOP_API_SECRET_KEY',
        ];

        // Si constante sensible → utiliser getDolGlobalString (décryptage automatique Dolibarr)
        if (in_array($name, $encryptedConstants)) {
            return getDolGlobalString($name);
        }

        // Sinon, lecture SQL directe (constantes non cryptées)
        // FIX #152 v2.1.2: Lecture DIRECTE depuis base pour supporter entity différente
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = '" . $this->db->escape($name) . "'";
        $sql .= " AND entity IN (" . (int)$entity . ", 0)"; // 0 = global config
        $sql .= " ORDER BY entity DESC LIMIT 1"; // Priorité entity spécifique > global

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            $this->db->free($result);

            // Retourner même si valeur = "0" ou chaîne vide (constante existe)
            return $obj->value;
        }

        // Constante absente
        return null;
    }
    
    /**
     * Récupère la configuration depuis l'ancienne table (fallback)
     *
     * @param int $entity Entity ID
     * @return array Configuration array
     */
    private function getConfigurationFromTable($entity)
    {
        // v2.2.1: Si la table obsolète n'existe pas, retourner vide
        if (!$this->tableExists()) {
            return [];
        }

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = " . (int)$entity;
        $result = $this->db->query($sql);
        
        if ($result && ($row = $this->db->fetch_object($result))) {
            return (array)$row;
        }
        
        // Fallback vers entity 1 si pas de config pour l'entité courante
        if ($entity != 1) {
            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = 1";
            $result = $this->db->query($sql);
            
            if ($result && ($row = $this->db->fetch_object($result))) {
                $this->log("Using fallback configuration from entity 1", LOG_INFO);
                return (array)$row;
            }
        }
        
        return [];
    }
    
    /**
     * Sauvegarde une valeur de configuration
     *
     * @param string $fieldName Field name (old format)
     * @param mixed $value Value to save
     * @param int $entity Entity ID
     * @return bool Success status
     */
    public function saveConfigurationValue($fieldName, $value, $entity = null)
    {
        global $conf;
        
        if ($entity === null) {
            $entity = $conf->entity;
        }
        
        if (!isset($this->fieldsMapping[$fieldName])) {
            $this->log("Unknown configuration field: $fieldName", LOG_ERR);
            return false;
        }
        
        $constInfo = $this->fieldsMapping[$fieldName];
        $convertedValue = $this->convertValue($value, $constInfo['type']);

        // Log spécial pour les champs import historique
        $historicalFields = ['historical_import_completed', 'historical_import_completed_date', 'historical_import_total_count', 'historical_import_processed_count', 'historical_import_skipped_count'];
        if (in_array($fieldName, $historicalFields)) {
            $this->log("HISTORICAL FIELD SAVE: $fieldName -> {$constInfo['name']} = '$convertedValue' (type: {$constInfo['type']})", LOG_INFO);
        }
        
        dol_include_once('/core/lib/admin.lib.php');

        // Force saving even for '0' or empty values by ensuring constant exists
        if ($convertedValue === '0' || $convertedValue === '') {
            // First delete if exists to force reinsertion
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($constInfo['name']) . "' AND entity = " . (int)$entity;
            $this->db->query($sql);
        }

        $result = dolibarr_set_const($this->db, $constInfo['name'], $convertedValue, $constInfo['type'], 0,
                                    "Updated via ConfigurationMigrator", $entity);

        if ($result > 0) {
            $this->log("Saved $fieldName -> {$constInfo['name']} = '$convertedValue' (forced save for empty/zero values)", LOG_DEBUG);
            return true;
        } else {
            $this->log("Failed to save $fieldName -> {$constInfo['name']}", LOG_ERR);
            return false;
        }
    }
    
    /**
     * Génère un rapport de migration
     *
     * @param int $entity Entity ID
     * @return array Migration report
     */
    public function getMigrationReport($entity = null)
    {
        global $conf;
        
        if ($entity === null) {
            $entity = $conf->entity;
        }
        
        $report = [
            'entity' => $entity,
            'migration_needed' => $this->isMigrationNeeded($entity),
            'constants' => [],
            'table_data' => [],
            'missing' => [],
            'total_constants' => 0,
            'total_table_fields' => 0
        ];
        
        // Vérifier les constantes existantes
        foreach ($this->fieldsMapping as $oldField => $constInfo) {
            $value = $this->getConstantValue($constInfo['name'], $entity);
            $report['constants'][$oldField] = [
                'constant_name' => $constInfo['name'],
                'value' => $value,
                'exists' => ($value !== null)
            ];
            if ($value !== null) {
                $report['total_constants']++;
            }
        }
        
        // Vérifier les données de la table
        $tableData = $this->getConfigurationFromTable($entity);
        foreach ($this->fieldsMapping as $oldField => $constInfo) {
            if (isset($tableData[$oldField]) && !empty($tableData[$oldField])) {
                $report['table_data'][$oldField] = $tableData[$oldField];
                $report['total_table_fields']++;
                
                // Si existe dans table mais pas en constante
                if (!$report['constants'][$oldField]['exists']) {
                    $report['missing'][] = $oldField;
                }
            }
        }
        
        return $report;
    }
    
    /**
     * Obtient les statistiques détaillées de la migration
     *
     * @param int $entity Entity ID
     * @return array Configuration statistics
     */
    public function getConfigurationStats($entity = null)
    {
        global $conf;
        
        if ($entity === null) {
            $entity = $conf->entity;
        }
        
        $stats = [
            'entity' => $entity,
            'total_expected' => count($this->fieldsMapping),
            'constants_migrated' => 0,
            'old_table_records' => 0,
            'old_table_exists' => false,
            'missing_parameters' => [],
            'migration_status' => 'unknown',
            'fallback_used' => false
        ];
        
        // Compter uniquement les constantes correspondant au mapping de migration
        $constantNames = array_map(function($mapping) { return $mapping['name']; }, $this->fieldsMapping);
        $constantNamesStr = "'" . implode("', '", $constantNames) . "'";
        
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "const 
                WHERE name IN ($constantNamesStr) AND entity = " . (int)$entity;
        $result = $this->db->query($sql);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $stats['constants_migrated'] = (int)$obj->count;
        }
        
        // Vérifier si l'ancienne table existe et compter les enregistrements
        $stats['old_table_exists'] = $this->tableExists();
        if ($stats['old_table_exists']) {
            $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails
                    WHERE entity = " . (int)$entity;
            $result = $this->db->query($sql);
            if ($result) {
                $obj = $this->db->fetch_object($result);
                $stats['old_table_records'] = (int)$obj->count;
            }
        }
        
        // Vérifier quels paramètres sont manquants
        foreach ($this->fieldsMapping as $oldField => $constInfo) {
            $value = $this->getConstantValue($constInfo['name'], $entity);
            if ($value === null) {
                $stats['missing_parameters'][] = $oldField;
                $stats['fallback_used'] = true;
            }
        }
        
        // Déterminer le statut de migration
        if (!$stats['old_table_exists']) {
            // Si l'ancienne table n'existe pas, la migration est complète
            $stats['migration_status'] = 'complete';
        } elseif ($stats['constants_migrated'] >= $stats['total_expected']) {
            $stats['migration_status'] = 'complete';
        } elseif ($stats['constants_migrated'] > 0) {
            $stats['migration_status'] = 'partial';
        } else {
            $stats['migration_status'] = 'not_migrated';
        }
        
        return $stats;
    }

    /**
     * Assure la migration complète et supprime l'ancienne table
     * Cette méthode fait la migration définitive et nettoie l'ancienne table
     *
     * @param int $entity Entity ID
     * @return bool Success status
     */
    public function ensureCompleteMigration($entity = null)
    {
        global $conf;

        if ($entity === null) {
            $entity = $conf->entity;
        }

        $this->log("Starting complete migration check for entity: $entity", LOG_INFO);

        // 1. Vérifier si l'ancienne table existe
        if (!$this->tableExists()) {
            $this->log("Old table llx_doli2shop_storedetails doesn't exist - migration already complete", LOG_INFO);
            return true;
        }

        // 2. Vérifier s'il y a des données dans l'ancienne table pour cette entité
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = " . (int)$entity;
        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("Error checking old table data: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($result);
        if ($obj->count == 0) {
            $this->log("No data in old table for entity $entity", LOG_INFO);
            // Pas de données pour cette entité, vérifier s'il faut supprimer la table
            return $this->cleanupTableIfEmpty();
        }

        // 3. Faire la migration complète
        $this->log("Data found in old table, performing complete migration", LOG_INFO);
        $migrationResult = $this->runMigration($entity);

        if (!$migrationResult) {
            $this->log("Migration failed, keeping old table for safety", LOG_ERR);
            return false;
        }

        // 4. Vérifier que les constantes critiques sont bien créées
        $constantsCreated = 0;
        $criticalConstants = ['shopify_store_hostname', 'shopify_access_token', 'shopify_api_key'];
        $criticalCreated = 0;

        foreach ($this->fieldsMapping as $oldField => $constInfo) {
            $value = $this->getConstantValue($constInfo['name'], $entity);
            if ($value !== null) {
                $constantsCreated++;
                if (in_array($oldField, $criticalConstants)) {
                    $criticalCreated++;
                }
            }
        }

        $this->log("Migration verification: $constantsCreated/" . count($this->fieldsMapping) . " constants created, $criticalCreated/" . count($criticalConstants) . " critical constants", LOG_INFO);

        // 5. Si les constantes critiques sont créées, nettoyer les données de l'entité migrée
        // Accepter que les nouvelles constantes v2.0.35 ne soient pas dans l'ancienne table
        if ($criticalCreated >= count($criticalConstants)) {
            $this->log("Critical constants migrated successfully, proceeding with entity data cleanup", LOG_INFO);
            return $this->cleanupEntityData($entity);
        } else {
            $this->log("Migration incomplete: only $criticalCreated/" . count($criticalConstants) . " critical constants migrated, keeping old table", LOG_WARNING);
            return false;
        }
    }

    /**
     * Vérifie si la table doli2shop_storedetails existe
     *
     * @return bool True if table exists
     */
    private function tableExists()
    {
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "doli2shop_storedetails'";
        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("Error checking table existence: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        return ($this->db->num_rows($result) > 0);
    }

    /**
     * Nettoie les données de l'entité migrée et supprime la table si elle devient vide
     *
     * @param int $entity Entity ID
     * @return bool Success status
     */
    private function cleanupEntityData($entity)
    {
        // Supprimer les données de l'entité migrée
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = " . (int)$entity;
        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("Error deleting entity data: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $this->log("Entity $entity data successfully removed from old table", LOG_INFO);

        // Vérifier s'il reste des données pour d'autres entités
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails";
        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("Error checking remaining table data: " . $this->db->lasterror(), LOG_ERR);
            return true; // Entity data deleted, that's already a success
        }

        $obj = $this->db->fetch_object($result);

        if ($obj->count == 0) {
            // Table vide, on peut la supprimer complètement
            $this->log("Old table is now empty, removing it completely", LOG_INFO);
            $sql = "DROP TABLE " . MAIN_DB_PREFIX . "doli2shop_storedetails";
            $result = $this->db->query($sql);

            if ($result) {
                $this->log("Old table successfully removed", LOG_INFO);
                return true;
            } else {
                $this->log("Failed to remove old table: " . $this->db->lasterror(), LOG_ERR);
                return true; // Entity data still deleted, partial success
            }
        } else {
            $this->log("Old table still contains data for other entities ($obj->count records), keeping table structure", LOG_INFO);
            return true;
        }
    }

    /**
     * Nettoie l'ancienne table si elle est vide ou si toutes les entités sont migrées
     *
     * @return bool Success status
     */
    private function cleanupTableIfEmpty()
    {
        // Vérifier si la table contient encore des données
        $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails";
        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("Error checking table data for cleanup: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($result);

        if ($obj->count == 0) {
            // Table vide, on peut la supprimer
            $this->log("Old table is empty, removing it", LOG_INFO);
            $sql = "DROP TABLE " . MAIN_DB_PREFIX . "doli2shop_storedetails";
            $result = $this->db->query($sql);

            if ($result) {
                $this->log("Old table successfully removed", LOG_INFO);
                return true;
            } else {
                $this->log("Failed to remove old table: " . $this->db->lasterror(), LOG_ERR);
                return false;
            }
        } else {
            $this->log("Old table still contains data for other entities, keeping it", LOG_INFO);
            return true;
        }
    }
}