<?php

/**
 * @file        class/shopifyapi.class.php
 * @brief This file contains the ShopifyApi class
 * @note Shopify API class for handling API calls to Shopify
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @version     2.6.0
 * @since       2.0.0
 * @author      P'tite Tête <doli2shop@ptitetete.com>
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @link        https://doli2shop.ptitetete.org
 */

// Load dependencies (only when class is actually used, not during scan)
if (defined('DOL_DOCUMENT_ROOT')) {
    dol_include_once('/core/lib/functions.lib.php');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once dirname(__FILE__) . '/sqlutils.class.php';
    require_once dirname(__FILE__) . '/LoggerTrait.php';
    require_once dirname(__FILE__) . '/CronHelperTrait.php';

    // Include compatibility functions for older Dolibarr versions
    require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
}


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : exception dédiée levée par
 * ShopifyApi::getInventoryQuantitiesAtLocation() UNIQUEMENT pour une erreur GraphQL globale qui
 * désigne réellement l'EMPLACEMENT interrogé (invalide, introuvable, accès refusé) — jamais pour
 * un THROTTLED ou toute autre erreur globale transitoire (cf. isLocationRelatedGraphQLError()).
 *
 * Porte les quantités déjà résolues par les chunks PRÉCÉDENTS du même appel
 * (`$partialResults`) : lever cette exception ne doit pas faire perdre un travail déjà fait —
 * seul le chunk en échec est perdu, pas ceux qui ont réussi avant lui.
 *
 * @since 2.5.7
 */
class ShopifyLocationErrorException extends \RuntimeException
{
    /** @var array<string,array<string,int>> */
    public $partialResults = [];

    /**
     * Re-review 27/09/2026 (HIGH) : raison précise de la classification structurelle, pour
     * permettre à l'appelant (ImportProducts::fillInventoryReferenceQuantities()) de journaliser
     * un message ACTIONNABLE distinct selon le cas — un emplacement DÉSACTIVÉ (existe, mais
     * inactif) n'appelle pas la même remédiation qu'un emplacement introuvable/invalide.
     *
     * Valeurs possibles : 'null' (data.location === null), 'disabled' (data.location.isActive
     * === false), 'path' (erreur de champ sur le chemin `location`), 'message' (classifié par
     * isLocationRelatedGraphQLError(), libellé ou extensions.code).
     *
     * @var string
     */
    public $reason = '';
}

class ShopifyApi
{
    use LoggerTrait;
    use CronHelperTrait;

    /**
     * Marge de sécurité (secondes) soustraite à l'échéance persistée après un refresh direct
     * module→Shopify — cohérent avec DOLI2SHOP_TOKEN_EXPIRY_MARGIN_SECONDS côté oauth_receive.php.
     *
     * @var int
     * @since 2.4.1
     */
    const TOKEN_EXPIRY_MARGIN_SECONDS = 120;

    /**
     * Seuil (secondes) avant expiration à partir duquel un refresh proactif est déclenché
     * (AC6 : "moins de 5 minutes de l'échéance").
     *
     * @var int
     * @since 2.4.1
     */
    const TOKEN_REFRESH_THRESHOLD_SECONDS = 300;

    /** @var DoliDB Database handler */
    private $db;

    /** @var int Entité Dolibarr courante (mémorisée pour le refresh token — lock name, persistance) */
    private $entity = 1;

    /** @var object Configuration settings */
    private $config;

    /** @var array Static cache for configurations keyed by "{entity}:{store_rowid}" */
    private static $configCache = [];

    /** @var array Guard migration-lourde par entité (évite N exécutions en boucle multi-boutique) */
    private static $migrationDone = [];

    /**
     * Story 51-2 (Code Review MEDIUM) : mémorise, pour le process PHP courant, que le champ
     * `sources` de Collection s'est déjà révélé indisponible (undefinedField — scope
     * read_products non accordé à l'app installée, ou régression future du schéma). Une fois
     * détecté, getCollectionDetails() ne rejoue plus la requête AVEC `sources` (qui échouerait
     * systématiquement de la même façon) et interroge directement sans, évitant la double
     * requête réseau à chaque appel. Se réinitialise naturellement au prochain process
     * (propriété statique, non persistée en base ni en cache partagé).
     *
     * Review post-fix (HIGH, Blind+Edge Hunter) : tableau CLÉ PAR BOUTIQUE (hostname), pas un
     * bool global — les CRONs multi-boutiques bouclent sur plusieurs instances ShopifyApi dans
     * un même process (Epic 47) ; un scope manquant sur la boutique A ne doit PAS priver la
     * boutique B (scope OK) de la lecture `sources` (faux MANUAL → contournement silencieux de
     * la garde adoption-par-titre de getOrCreateShopifyCollection). Même pattern de scoping que
     * $configCache / $migrationDone ci-dessus.
     *
     * @var array<string,bool> hostname boutique => true si `sources` indisponible
     */
    private static $sourcesFieldUnsupported = [];

    /**
     * Clé de scoping boutique pour self::$sourcesFieldUnsupported.
     *
     * @return string Hostname de la boutique courante ('' si config incomplète)
     * @since 2.4.1
     */
    private function sourcesFieldScopeKey()
    {
        return isset($this->config->shopify_store_hostname) ? (string) $this->config->shopify_store_hostname : '';
    }

    /** @var int rowid de la boutique courante (0 = chemin historique entité/constantes) */
    private $fkStore = 0;

    /**
     * true si la boutique courante EST la boutique par défaut, UNIQUEMENT quand elle a été
     * chargée via un objet boutique portant is_default=1 (ex. `ShopifyApi::forStore()`).
     *
     * Review Hotfix 2.5.2 (correction du commentaire, PAS du code) : reste FALSE sur le chemin
     * historique (`$store === null`, `$fkStore = 0`), même quand ce chemin représente en
     * pratique la boutique par défaut d'une install migrée (écrans admin qui utilisent
     * délibérément `new ShopifyApi($db)`, Story 49-9) ou une install jamais migrée (AC5). Ce
     * chemin est traité séparément, sur le seul test `$this->fkStore === 0`, par
     * fetchFreshTokenState()/persistRefreshedToken()/markReconnectRequired() — ce drapeau ne
     * sert qu'à distinguer, quand `$fkStore > 0`, une boutique par défaut (accédée via
     * forStore()) d'une boutique SECONDAIRE, qui a elle aussi `$fkStore > 0`.
     *
     * Hotfix 2.5.2 : la décision entre « écrire uniquement la ligne stores » et « écrire aussi
     * les constantes DOLI2SHOP_* » se prend sur ce drapeau (ou sur `$fkStore === 0`), JAMAIS sur
     * `$fkStore > 0` seul — une boutique par défaut passée à forStore() (ex.
     * ShopifyTokenRefreshCron) a également `$fkStore > 0`, identique à une boutique secondaire.
     * C'était la source du hotfix : deux copies de credentials pour UNE SEULE boutique, tenues
     * pour indépendantes par persistRefreshedToken()/markReconnectRequired().
     *
     * @var bool
     * @since 2.5.2
     */
    private $isDefaultStore = false;

    /**
     * Review Hotfix 2.5.2 (point 6) : résolution MÉMORISÉE, pour CETTE instance, de la ligne
     * `stores` is_default=1 — évite d'appeler `StoreService::getDefault()` plusieurs fois par
     * cycle de refresh (double-check post-lock, persistance, flag reconnexion). Voir
     * resolveDefaultStoreRow().
     *
     * @var bool true = déjà résolue (que le résultat soit une ligne ou null)
     * @since 2.5.2
     */
    private $defaultStoreRowResolved = false;

    /**
     * @var object|null Ligne `stores` is_default=1 mémorisée par resolveDefaultStoreRow() —
     *                   null = aucune (install jamais migrée) ou pas encore résolue (voir
     *                   $defaultStoreRowResolved).
     * @since 2.5.2
     */
    private $defaultStoreRow = null;

    /** @var Client Shopify API client */
    private $client;

    /** @var string API version for Shopify (bumped 2026-07 — Story 51-2, nouvelle API Collections/Collection.sources) */
    private $apiVersion = '2026-07';

    /**
     * Version d'API cible pour la LIVRAISON DES WEBHOOKS (distincte de $apiVersion, endpoint GraphQL).
     *
     * Story 51-2 (CORRECTION VALIDATE P-HIGH) : la version de livraison des webhooks Shopify
     * est dictée par la version d'app déclarée dans `shopify-app/shopify.app.toml`
     * (cf. Story 49-8, class/shopifywebhooks.class.php:548-565), PAS par la version d'endpoint
     * GraphQL utilisée pour les appels API du module ($apiVersion ci-dessus). Sans cette
     * séparation, bumper $apiVersion à '2026-07' ferait croire à ShopifyWebhooks::syncWebhooks()
     * que tous les webhooks existants (enregistrés en '2026-04', version d'app réelle) sont
     * périmés → delete/recreate de TOUS les webhooks à chaque sync, jamais résorbable tant que
     * shopify-app/shopify.app.toml n'est pas déployé en '2026-07' (procédure CLI documentée,
     * Task T6.1 de cette story, volontairement différée).
     *
     * À bumper à '2026-07' UNIQUEMENT lors du déploiement effectif du TOML (T6.1).
     *
     * @var string
     * @since 2.4.0
     */
    const WEBHOOK_TARGET_API_VERSION = '2026-04';

    /** @var string Error message */
    public $error;

    /** @var array Error messages */
    public $errors = array();

    /**
     * Constructor
     *
     * @param DoliDB      $db     Database handler
     * @param int|null    $entity Entity to use for configuration (optional, defaults to current entity)
     * @param object|null $store  Objet boutique retourné par StoreService (null = chemin historique entité/constantes)
     */
    public function __construct($db, $entity = null, $store = null)
    {
        $this->db = $db;
        $this->loadConfiguration($entity, $store);

        if (empty($this->config)) {
            $this->error = "Failed to load Shopify configuration from database";
            $this->errors[] = $this->error;
            $this->log("Error: " . $this->error, LOG_ERR);
            return;
        }

        if (empty($this->config->shopify_store_hostname)) {
            $this->error = "Shopify store hostname is empty in configuration";
            $this->errors[] = $this->error;
            $this->log("Error: " . $this->error . (!empty($this->config) ? " (hostname=" . (isset($this->config->shopify_store_hostname) ? $this->config->shopify_store_hostname : 'N/A') . ")" : ""), LOG_ERR);
            return;
        }

        // Additional logging for debugging
        $this->log("Configuration loaded successfully: " . $this->getSafeConfigString(), LOG_INFO);

        // Story 51-1 (AC6/AC7) : refresh proactif AVANT tout appel si le token est proche de
        // l'expiration. No-op strict si token_expires_at est NULL (AC3 — comportement historique
        // inchangé pour les installs non migrées). Non bloquant : toute erreur inattendue ne doit
        // JAMAIS empêcher l'instanciation (fallback = comportement historique, l'appel API suivant
        // échouera en 401 et sera rattrapé par T5).
        try {
            $this->ensureFreshToken();
        } catch (\Throwable $e) {
            $this->log("ensureFreshToken() a échoué de façon inattendue (non bloquant): " . $e->getMessage(), LOG_ERR);
        }

        $this->initClient();
    }

    /**
     * Load Shopify configuration from database for current entity, puis écrase optionnellement
     * les 5 champs de connexion depuis un objet boutique (StoreService).
     *
     * @param int|null    $forceEntity Entity to use (optional, defaults to current entity)
     * @param object|null $store       Objet boutique (StoreService). Null = chemin historique.
     * @return void
     */
    public function loadConfiguration($forceEntity = null, $store = null)
    {
        global $conf;

        // Use forced entity if provided, otherwise determine from global conf
        if ($forceEntity !== null) {
            $entity = (int)$forceEntity;
        } else {
            // In cron context, entity might be 0 or not set properly, default to 1
            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;
        }

        // Story loadconfiguration-is-default-sans-verification-entity (AC1, durci en Review 3
        // couches 2026-09-26, finding MEDIUM) : si $store->entity diffère de l'entité de CE
        // chargement, traiter $store EXACTEMENT comme s'il n'avait jamais été fourni — pour TOUT
        // ce qui suit dans cette méthode, pas seulement isDefaultStore. La 1ère version de ce
        // correctif ne gardait la garde que sur isDefaultStore : un $store en désaccord d'entité
        // écrasait quand même hostname/access_token/api_key/api_secret/location_id/refresh_token
        // dans $config (bloc plus bas) et laissait $this->fkStore pointer sur le rowid d'une
        // boutique ÉTRANGÈRE à l'entité chargée — soit exactement le risque que la garde
        // isDefaultStore prétendait écarter, contourné par un autre chemin. En nullant $store ICI,
        // AVANT tout calcul qui en dépend (storeRowid, cacheKey, l'écrasement des 5 champs de
        // connexion, l'overlay P2 conditionné par storeRowid>0), la suite de la méthode retombe
        // intégralement sur le chemin legacy de l'entité de chargement, sans qu'aucune valeur de
        // la boutique étrangère ne soit jamais lue. Un désaccord signale un bug appelant
        // (confusion Multi Company, construction manuelle d'un $store hors StoreService) — jamais
        // observé dans le dépôt à ce jour (tous les appelants passent un $store issu de
        // StoreService, déjà filtré par entité) — journalisé LOG_ERR, jamais silencieux. Si
        // $store->entity est absent (objet minimal ne portant pas cette propriété), on ne peut pas
        // constater de désaccord : comportement inchangé (chemin store normal), pour ne pas casser
        // un appelant qui construirait un $store partiel légitime.
        if ($store !== null && isset($store->entity) && (int) $store->entity !== $entity) {
            $this->log(
                'loadConfiguration() - $store->entity (' . (int) $store->entity . ') diffère de '
                . 'l\'entité de chargement (' . $entity . ') — traité comme si $store n\'avait '
                . 'jamais été fourni (bug appelant probable : boutique hors entité passée à '
                . 'forStore())',
                LOG_ERR
            );
            $store = null;
        }

        // Déterminer le rowid boutique pour la clé de cache composite
        $storeRowid = ($store !== null && !empty($store->rowid)) ? (int)$store->rowid : 0;
        $cacheKey   = $entity . ':' . $storeRowid;

        // Mémorisée pour le refresh token (Story 51-1) : nom de lock, persistance StoreService
        $this->entity = $entity;

        // Hotfix 2.5.2 (AC2) : porter is_default sur l'instance, INDÉPENDAMMENT du cache de
        // config ci-dessous (qui ne mémorise que $this->config, jamais les autres propriétés
        // d'instance) — sinon un hit de cache laisserait $this->isDefaultStore à sa valeur par
        // défaut (false) pour une boutique par défaut passée via forStore(). $store est déjà
        // nullé ci-dessus en cas de désaccord d'entité : cette ligne dérive donc naturellement
        // isDefaultStore=false dans ce cas, sans logique dupliquée.
        $this->isDefaultStore = ($store !== null) && !empty($store->is_default);

        // Check cache first to avoid unnecessary reloads (TRUE OPTIMIZATION)
        // Clé composite {entity}:{store_rowid} — isole les configs entre boutiques
        if (isset(self::$configCache[$cacheKey])) {
            $this->config  = clone self::$configCache[$cacheKey];
            $this->fkStore = $storeRowid;
            $this->log("Configuration loaded from cache for key: " . $cacheKey, LOG_DEBUG);
            return;
        }

        $this->log("Loading configuration for entity: " . $entity . ", store_rowid: " . $storeRowid, LOG_INFO);

        // v2.0.35: Use new constants-based configuration system with complete migration
        require_once dirname(__FILE__) . '/configurationMigrator.class.php';
        $migrator = new ConfigurationMigrator($this->db);

        // Garde migration par ENTITÉ (pas par boutique) : évite de rejouer la migration
        // N fois en boucle multi-boutique (migration = opération lourde par entité, pas par store)
        if (empty(self::$migrationDone[$entity])) {
            $migrationResult = $migrator->ensureCompleteMigration($entity);
            // Ne marquer "fait" que si la migration a RÉUSSI — sinon un échec transitoire
            // (panne DB) figerait définitivement une migration incomplète pour cette entité.
            self::$migrationDone[$entity] = (bool)$migrationResult;
            if ($migrationResult) {
                $this->log("Configuration migration check completed successfully for entity: " . $entity, LOG_INFO);
            } else {
                $this->log("Configuration migration check failed for entity: " . $entity, LOG_WARNING);
            }
        }

        // Load configuration using new system (constants with table fallback)
        $configArray = $migrator->getConfiguration($entity);

        if (!empty($configArray)) {
            // Convert array to object for backward compatibility
            $config = (object)$configArray;

            // Si une boutique est fournie : écraser les 5 champs de connexion
            // Tout le reste (flags sync, config Dolibarr, procate…) reste global par entité
            if ($store !== null) {
                $this->fkStore = $storeRowid;
                if (!empty($store->shop_domain)) {
                    $config->shopify_store_hostname = $store->shop_domain;
                }
                if (isset($store->access_token)) {
                    $config->shopify_access_token = $store->access_token;
                }
                if (isset($store->api_key)) {
                    $config->shopify_api_key = $store->api_key;
                }
                if (isset($store->api_secret)) {
                    $config->shopify_api_secret_key = $store->api_secret;
                }
                if (!empty($store->location_id)) {
                    $config->shopify_location_id = $store->location_id;
                }
                // Story 51-1 : jetons expirables + refresh — property_exists (pas empty/isset)
                // car token_expires_at/refresh_token sont légitimement NULL/vides tant qu'une
                // boutique n'a pas encore de token expirable (AC3, comportement historique).
                if (property_exists($store, 'token_expires_at')) {
                    $config->shopify_token_expires_at = $store->token_expires_at;
                }
                if (property_exists($store, 'refresh_token')) {
                    $config->shopify_refresh_token = $store->refresh_token;
                }
                $this->log(
                    "Store-specific config applied for shop_domain: " . $store->shop_domain . " (store_rowid=" . $storeRowid . ")",
                    LOG_INFO
                );
            }

            // P2 Overlay — appliquer overrides per-store sur champs métier (JAMAIS credentials)
            if ($storeRowid > 0) {
                try {
                    require_once dirname(__FILE__) . '/storesettings.class.php';
                    $overlaySettings = new StoreSettings($this->db);
                    $storeOverrides  = $overlaySettings->getAllForStore($storeRowid);

                    $perStoreConfigOverlayMap = [
                        'ORDER_PREFIX'               => 'order_prefix',
                        'PAYMENT_TERMS'              => 'payment_terms',
                        'ORDER_BANK_ACCOUNT'         => 'order_bank_account',
                        'ORDER_ORIGIN'               => 'order_origin',
                        'DEFAULT_WAREHOUSE_ID'       => 'default_warehouse_id',
                        'DELIVERY_DELAY'             => 'delivery_delay',
                        'DELIVERY_DELAY_TYPE'        => 'delivery_delay_type',
                        'DEFAULT_DELIVERY_DAYS'      => 'default_delivery_days',
                        'DEFAULT_SHIPPING_METHOD_ID' => 'default_shipping_method_id',
                        'AUTO_CREATE_INVOICE'        => 'auto_create_invoice',
                        'AUTO_VALIDATE_INVOICE'      => 'auto_validate_invoice',
                        'AUTO_CREATE_PAYMENT'        => 'auto_create_payment',
                        'AUTO_CREATE_EXPEDITION'     => 'auto_create_expedition',
                        'AUTO_CLOSE_ORDER'           => 'auto_close_order',
                        'BUYING_PRICE_SOURCE'        => 'buying_price_source',
                        'BUNDLE_LINES_BEHAVIOR'      => 'bundle_lines_behavior',
                        'SYNC_NON_PAID_ORDERS'       => 'sync_non_paid_orders',
                        'AUTO_CLASSIFY_BILLED'       => 'auto_classify_billed',
                        'SHIPPING_PRODUCT_ID'        => 'shipping_product_id',
                        'TIP_PRODUCT_ID'             => 'tip_product_id',
                        'TYPENT_WITH_COMPANY'        => 'typent_with_company',
                        'TYPENT_WITHOUT_COMPANY'     => 'typent_without_company',
                        'MAX_ORDERS_PER_SYNC'        => 'max_orders_per_sync',
                        'PRODUCTS_PER_CRON_UPDATE'   => 'products_per_cron_update',
                        'DOLIBARR_PROCATE'           => 'dolibarr_procate',
                        'DOLIBARR_CUSTOMER_CATEGORY' => 'dolibarr_customer_category',
                        'SYNC_PRODUCTS_DIRECTION'    => 'sync_products_direction',
                        'SYNC_PRODUCTS_CONFLICT_RESOLUTION' => 'sync_products_conflict_resolution',
                        'SYNC_PRODUCT_PRICES'        => 'sync_product_prices',
                        'SYNC_PRICE_LEVEL'           => 'sync_price_level',
                        'PRICE_PRIORITY_TTC'         => 'price_priority_ttc',
                        'SYNC_PRODUCT_DESCRIPTIONS'  => 'sync_product_descriptions',
                        'SYNC_PRODUCT_IMAGES'        => 'sync_product_images',
                        'SYNC_PRODUCT_STOCKS'        => 'sync_product_stocks',
                        'SYNC_PRODUCT_ATTRIBUTES'    => 'sync_product_attributes',
                        'SYNC_PRODUCT_COLLECTIONS'   => 'sync_product_collections',
                        'INCLUDE_PARENT_CATEGORIES'  => 'include_parent_categories',
                        'SYNC_COLLECTIONS_DIRECTION' => 'sync_collections_direction',
                        'SYNC_COLLECTIONS_CONFLICT_RESOLUTION' => 'sync_collections_conflict_resolution',
                        'COLLECTIONS_SALES_CHANNELS' => 'collections_sales_channels',
                        'VENDOR'                     => 'shopify_vendor',
                        'USE_VIRTUAL_STOCK'          => 'use_virtual_stock',
                        'INVENTORY_POLICY_CONTINUE_SELLING' => 'inventory_policy_continue_selling',
                    ];

                    // Champs de type entier dans le config-object (LOW — cast DB string → int)
                    // DB retourne toujours une string ; caster pour préserver la sémantique métier.
                    $intConfigFields = [
                        'payment_terms', 'order_bank_account', 'order_origin', 'default_warehouse_id',
                        'delivery_delay', 'default_delivery_days', 'default_shipping_method_id',
                        'auto_create_invoice', 'auto_validate_invoice', 'auto_create_payment',
                        'auto_create_expedition', 'auto_close_order', 'bundle_lines_behavior',
                        'sync_non_paid_orders', 'auto_classify_billed', 'shipping_product_id',
                        'tip_product_id', 'typent_with_company', 'typent_without_company',
                        'max_orders_per_sync', 'products_per_cron_update', 'sync_product_prices',
                        'sync_price_level', 'price_priority_ttc', 'sync_product_descriptions',
                        'sync_product_images', 'sync_product_stocks', 'sync_product_attributes',
                        'sync_product_collections', 'include_parent_categories', 'use_virtual_stock',
                        'inventory_policy_continue_selling',
                    ];

                    foreach ($perStoreConfigOverlayMap as $storeKey => $configField) {
                        if (isset($storeOverrides[$storeKey])) {
                            $rawVal = $storeOverrides[$storeKey];
                            // Cast selon le type du champ config-object
                            $config->{$configField} = in_array($configField, $intConfigFields, true) ? (int) $rawVal : $rawVal;
                        }
                    }
                    $this->log('ShopifyApi::loadConfiguration - P2 overlay applied: ' . count($storeOverrides) . ' overrides for storeRowid=' . $storeRowid, LOG_DEBUG);
                } catch (\Throwable $overlayEx) {
                    // Non bloquant : log warning, continuer avec config globale (LOW — catch Throwable)
                    dol_syslog('ShopifyApi P2 overlay per-store failed for storeRowid=' . $storeRowid . ': ' . $overlayEx->getMessage(), LOG_WARNING);
                }
            }

            $this->config = $config;

            // Cache par clé composite {entity}:{store_rowid}
            // clone : éviter qu'une mutation downstream de $this->config corrompe l'entrée
            // de cache (et donc les instances suivantes partageant la même clé)
            self::$configCache[$cacheKey] = clone $config;

            $this->log("Configuration loaded: stdClass Object", LOG_DEBUG);
        } else {
            $this->log("CRITICAL: No configuration loaded via new system", LOG_ERR);
        }
    }

    /**
     * Retourne le rowid de la boutique courante (0 si chemin historique entité/constantes).
     *
     * @return int rowid boutique (0 = mono-boutique/constantes)
     */
    public function getStoreId(): int
    {
        return $this->fkStore;
    }

    // =========================================================================
    // Story 51-1 — Jetons OAuth Shopify expirables + refresh automatique
    // =========================================================================

    /**
     * Force un refresh si l'échéance du token tombe dans la fenêtre donnée (ou est déjà
     * dépassée) — utilisé par le CRON filet (T6, AC10), avec une fenêtre PLUS LARGE (ex. 1h)
     * que le seuil synchrone (5 min, AC6/T4), pour rattraper les boutiques inactives entre deux
     * exécutions du CRON quotidien.
     *
     * No-op strict si `token_expires_at` est NULL (AC3 — install non migrée / Shopify n'a pas
     * encore basculé ce marchand en régime expirable).
     *
     * Note de conception (cf. Dev Agent Record de la story) : le module ne persiste pas de date
     * d'émission du refresh_token — seul `token_expires_at` (échéance de l'ACCESS token, 1h) est
     * suivi. Une boutique réellement INACTIVE (aucun appel API) a nécessairement un
     * `token_expires_at` qui reste bloqué dans le passé (l'access token dure 60 min). Le check
     * "dans la fenêtre OU déjà dépassé" ci-dessous capture donc AUSSI ce cas sans mécanisme
     * séparé : le CRON quotidien la rafraîchira au plus tard le lendemain, ce qui fait tourner
     * le refresh_token bien avant tout risque d'atteindre les 90 jours.
     *
     * @param  int $windowSeconds Fenêtre d'anticipation (secondes) — ex. 3600 (1h) pour le CRON
     * @return bool true si un refresh a été DÉCLENCHÉ (tenté), false si no-op (rien à faire)
     * @since  2.4.1
     */
    public function refreshTokenIfWithinWindow(int $windowSeconds): bool
    {
        if (empty($this->config) || empty($this->config->shopify_token_expires_at)) {
            return false; // AC3
        }

        $expiresAtTs = strtotime((string) $this->config->shopify_token_expires_at);
        if ($expiresAtTs === false) {
            return false;
        }

        if (($expiresAtTs - time()) > $windowSeconds) {
            return false; // pas encore dans la fenêtre
        }

        // forceRefresh=true : réutilise intégralement la logique T4 (verrou, double-check,
        // persistance avant usage, invalidation configCache) — seul le check de fenêtre diffère.
        $this->ensureFreshToken(true);
        return true;
    }

    /**
     * S'assure que l'access_token courant est frais avant utilisation ; rafraîchit
     * directement auprès de Shopify si nécessaire (AC6/AC7).
     *
     * Invariant AC3 (rétrocompat stricte) : si `token_expires_at` est NULL (install non migrée,
     * ou Shopify ne renvoie pas encore de jeton expirable pour ce marchand), AUCUNE tentative de
     * refresh n'est faite — comportement strictement identique à avant cette story.
     *
     * @param  bool $forceRefresh true = bypass le check "proche de l'expiration" (401 confirmé,
     *                             cf. T5) ; ne bypasse PAS l'absence de refresh_token connu.
     * @return void
     * @since  2.4.1
     */
    private function ensureFreshToken(bool $forceRefresh = false): void
    {
        if (empty($this->config)) {
            return;
        }

        $expiresAtRaw = isset($this->config->shopify_token_expires_at) ? trim((string) $this->config->shopify_token_expires_at) : '';

        if (!$forceRefresh) {
            // AC3 : pas de notion d'expiration -> no-op strict (installs non migrées / Shopify
            // n'a pas encore basculé ce marchand en régime expirable)
            if ($expiresAtRaw === '' || $expiresAtRaw === '0000-00-00 00:00:00') {
                return;
            }
            $expiresAtTs = strtotime($expiresAtRaw);
            if ($expiresAtTs === false || ($expiresAtTs - time()) > self::TOKEN_REFRESH_THRESHOLD_SECONDS) {
                return; // pas encore proche de l'expiration
            }
        }

        if (empty($this->config->shopify_refresh_token)) {
            // Rien à rafraîchir sans refresh_token connu — AC3, pas d'erreur, pas de blocage
            // (ex. 401 sur un token non-expirable : rien à tenter, T5 marquera reconnexion requise).
            if ($forceRefresh) {
                $this->log('ensureFreshToken() - refresh forcé (401) mais aucun refresh_token connu, impossible de rafraîchir', LOG_WARNING);
            }
            return;
        }

        $lockName = $this->buildTokenRefreshLockName();
        if (!$this->acquireCronLock($lockName, 5)) {
            // Un autre process détient déjà le verrou pour cette boutique — ne rien tenter ici
            // (AC7 : éviter de consommer 2x le refresh token usage-unique).
            $this->log('ensureFreshToken() - verrou déjà détenu pour ' . $lockName . ', refresh délégué au détenteur', LOG_INFO);
            return;
        }

        try {
            // (CORRECTION VALIDATE P3) Double-check POST-LOCK : relecture DEPUIS LA DB
            // (StoreService::fetch() ou constantes), JAMAIS via self::$configCache — c'est
            // précisément ce cache potentiellement obsolète que le double-check doit contourner.
            $fresh = $this->fetchFreshTokenState();
            if ($fresh !== null) {
                $freshExpiresAtTs = !empty($fresh['token_expires_at']) ? strtotime($fresh['token_expires_at']) : false;
                $freshIsCloseToExpiry = ($freshExpiresAtTs === false)
                    || (($freshExpiresAtTs - time()) <= self::TOKEN_REFRESH_THRESHOLD_SECONDS);

                // FIX HIGH (review 51-1) : $forceRefresh (appelé après un 401 confirmé) ne doit
                // court-circuiter le double-check que si le token frais lu en DB est le MÊME que
                // celui qui vient d'échouer (personne d'autre n'a encore rafraîchi) ou s'il est
                // lui-même proche de l'expiration/expiré. Si un autre process a DÉJÀ rafraîchi
                // entre-temps (access_token frais différent de celui en mémoire au moment du 401)
                // ET que ce nouveau token n'est pas proche de l'expiration, on adopte ses valeurs
                // SANS appel HTTP — sinon forceRefresh consommait un refresh_token à usage unique
                // déjà consommé par le concurrent, gaspillant un cycle et risquant l'échec Shopify.
                $freshTokenDiffersFromCurrent = isset($this->config->shopify_access_token)
                    && $fresh['access_token'] !== ''
                    && $fresh['access_token'] !== (string) $this->config->shopify_access_token;

                $stillNeedsRefresh = $freshTokenDiffersFromCurrent
                    ? $freshIsCloseToExpiry
                    : ($forceRefresh || $freshIsCloseToExpiry);

                if (!$stillNeedsRefresh) {
                    // Un autre process a déjà rafraîchi entre-temps : adopter ses valeurs fraîches
                    // sans consommer une 2e fois le refresh token (AC7).
                    // FIX HIGH (review 51-1) : reinitClient=true (au lieu de false) — ce point est
                    // désormais atteignable aussi depuis le chemin forceRefresh=true (retry post-401
                    // dans executeGraphQL), qui réutilise ensuite IMMÉDIATEMENT $this->client pour le
                    // retry. Sans reconstruire le client Guzzle avec le nouveau header d'autorisation,
                    // le retry réutiliserait l'ancien token (celui qui vient d'échouer en 401) et
                    // échouerait de nouveau, déclenchant à tort un "reconnexion requise". Sans coût
                    // réseau (juste une nouvelle instance Guzzle) : sûr aussi pour le chemin
                    // constructeur (forceRefresh=false), qui appelle de toute façon initClient() juste après.
                    $this->applyRefreshedToken((string) $fresh['access_token'], (string) $fresh['refresh_token'], (string) $fresh['token_expires_at'], true);
                    $this->log('ensureFreshToken() - double-check post-lock : refresh déjà effectué par un autre process, valeurs adoptées', LOG_INFO);
                    return;
                }
                if (!empty($fresh['refresh_token'])) {
                    $this->config->shopify_refresh_token = $fresh['refresh_token'];
                }
            }

            $result = $this->performTokenRefreshHttpCall();

            if ($result === null) {
                // Erreur réseau (timeout, DNS...) : transitoire, PAS un rejet définitif du
                // refresh_token — ne pas marquer "reconnexion requise" sur un simple incident réseau.
                $this->log('ensureFreshToken() - échec réseau du refresh (transitoire), nouvelle tentative au prochain appel', LOG_ERR);
                return;
            }

            if ($result['ok'] !== true) {
                // Rejet définitif par Shopify (refresh_token invalide/révoqué/déjà consommé hors
                // fenêtre de tolérance) — AC9 : signaler sans jamais désactiver la boutique.
                $this->log('ensureFreshToken() - refresh_token rejeté par Shopify (définitif)', LOG_ERR);
                $this->markReconnectRequired();
                return;
            }

            // (CORRECTION VALIDATE P3) PERSISTER AVANT toute utilisation du nouveau couple : le
            // refresh_token est à usage unique, un échec de persistance après consommation de
            // l'ancien équivaut à une perte du jeton (ni l'ancien ni le nouveau exploitables).
            $persisted = $this->persistRefreshedToken($result['access_token'], $result['refresh_token'], $result['expires_at']);
            if (!$persisted) {
                $this->log('ensureFreshToken() - ÉCHEC persistance du nouveau couple access_token/refresh_token après refresh — jeton potentiellement perdu', LOG_ERR);
                $this->markReconnectRequired();
                return;
            }

            $this->applyRefreshedToken($result['access_token'], $result['refresh_token'], $result['expires_at'], true);
            $this->log('ensureFreshToken() - refresh réussi, nouveau couple persisté et appliqué', LOG_INFO);
        } finally {
            $this->releaseCronLock($lockName);
        }
    }

    /**
     * Construit le nom logique du verrou de refresh (avant préfixage/bornage par
     * CronHelperTrait::buildCronLockName()).
     *
     * Review Hotfix 2.5.2 (point 1, CRITICAL) : la clé doit refléter l'IDENTITÉ réelle de la
     * boutique, jamais `$fkStore` brut — la MÊME boutique par défaut prend `fkStore = N` par le
     * CRON (`forStore()`) et `fkStore = 0` par un écran admin (`new ShopifyApi($db)`), ce qui
     * produisait DEUX verrous `GET_LOCK` distincts pour une seule boutique : aucun blocage
     * mutuel entre les deux chemins, chacun consommait le `refresh_token` Shopify à usage
     * unique de son côté — le défaut d'origine, déplacé de l'écriture vers le verrou.
     *
     * Les deux chemins convergent désormais vers la clé historique « _0 » dès que
     * `$this->isDefaultStore` est vrai. AC5 : une install jamais migrée a `fkStore = 0` ET
     * `isDefaultStore = false` (chemin `$store === null`, cf. loadConfiguration()) —
     * la clé reste EXACTEMENT `token_refresh_{entity}_0`, identique à avant ce correctif.
     *
     * @return string
     * @since 2.5.2
     */
    private function buildTokenRefreshLockName(): string
    {
        $lockStoreKey = $this->isDefaultStore ? 0 : $this->fkStore;
        return 'token_refresh_' . $this->entity . '_' . $lockStoreKey;
    }

    /**
     * Résout, une seule fois par instance (mémorisation), la ligne `stores` is_default=1 de
     * l'entité courante.
     *
     * Review Hotfix 2.5.2 (point 6, MEDIUM) : fetchFreshTokenState(), persistRefreshedToken() et
     * markReconnectRequired() ont chacun besoin de savoir si une ligne « boutique par défaut »
     * existe déjà (chemin constantes) — mutualiser en UN SEUL `SELECT` par instance/par cycle de
     * refresh plutôt que 2 à 3 appels redondants à `StoreService::getDefault()`.
     *
     * @return object|null Ligne boutique par défaut, ou null (aucune — install jamais migrée)
     * @since 2.5.2
     */
    private function resolveDefaultStoreRow(): ?object
    {
        if ($this->defaultStoreRowResolved) {
            return $this->defaultStoreRow;
        }

        require_once dirname(__FILE__) . '/storeservice.class.php';
        $storeService = new StoreService($this->db, $this->entity);
        $defaultStore = $storeService->getDefault();
        $this->defaultStoreRow = ($defaultStore !== null && !empty($defaultStore->rowid)) ? $defaultStore : null;
        $this->defaultStoreRowResolved = true;

        return $this->defaultStoreRow;
    }

    /**
     * Normalise une ligne `stores` en triplet token.
     *
     * @param  object $store
     * @return array{access_token:string,refresh_token:string,token_expires_at:string}
     * @since 2.5.2
     */
    private function tokenStateFromStoreRow(object $store): array
    {
        return [
            'access_token'     => (string) ($store->access_token ?? ''),
            'refresh_token'    => (string) ($store->refresh_token ?? ''),
            'token_expires_at' => (string) ($store->token_expires_at ?? ''),
        ];
    }

    /**
     * Retient, entre deux états token, celui dont l'échéance (`token_expires_at`) est la PLUS
     * RÉCENTE — la copie la plus fraîche est la plus probable d'être issue du DERNIER refresh
     * réussi.
     *
     * @param  array{access_token:string,refresh_token:string,token_expires_at:string} $a
     * @param  array{access_token:string,refresh_token:string,token_expires_at:string} $b
     * @return array{access_token:string,refresh_token:string,token_expires_at:string}
     * @since 2.5.2
     */
    private function freshestTokenState(array $a, array $b): array
    {
        $tsA = !empty($a['token_expires_at']) ? strtotime($a['token_expires_at']) : false;
        $tsB = !empty($b['token_expires_at']) ? strtotime($b['token_expires_at']) : false;

        if ($tsB !== false && ($tsA === false || $tsB > $tsA)) {
            return $b;
        }

        return $a;
    }

    /**
     * Relit l'état courant du token DEPUIS LA BASE (jamais self::$configCache) — utilisé pour le
     * double-check post-lock (CORRECTION VALIDATE P3).
     *
     * Review Hotfix 2.5.2 (point 2, HIGH) : pour une boutique SECONDAIRE, une seule copie
     * (ligne `stores`), comportement inchangé. Pour la boutique PAR DÉFAUT — `isDefaultStore`
     * vrai (accédée via `forStore()`) OU `fkStore === 0` (chemin historique/constantes, qui
     * recouvre aussi bien une install jamais migrée qu'un écran admin sur la boutique par
     * défaut) — DEUX copies sont possibles pour la MÊME boutique : comparer les deux et adopter
     * la plus fraîche (`token_expires_at` le plus récent). Sans cela, le point 1 (même verrou
     * désormais) ne suffisait pas : le process qui arrivait en 2e position relisait sa PROPRE
     * copie (potentiellement périmée) et déclenchait un second appel HTTP au lieu de l'absorber
     * sans appel réseau via le mécanisme d'adoption ci-dessous (:533-547).
     *
     * @return array{access_token:string,refresh_token:string,token_expires_at:string}|null
     * @since 2.4.1
     */
    private function fetchFreshTokenState(): ?array
    {
        if ($this->fkStore > 0 && !$this->isDefaultStore) {
            // Boutique SECONDAIRE (Epic 47) : une seule copie, comportement STRICTEMENT inchangé.
            require_once dirname(__FILE__) . '/storeservice.class.php';
            $storeService = new StoreService($this->db, $this->entity);
            $store = $storeService->fetch($this->fkStore);
            if ($store === null) {
                return null;
            }
            return $this->tokenStateFromStoreRow($store);
        }

        // Boutique PAR DÉFAUT (isDefaultStore===true, quel que soit fkStore) OU chemin
        // fkStore===0 (constantes) : lire les DEUX copies possibles et adopter la plus fraîche.
        $constants = [
            'access_token'     => getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN'),
            'refresh_token'    => getDolGlobalString('DOLI2SHOP_REFRESH_TOKEN'),
            'token_expires_at' => getDolGlobalString('DOLI2SHOP_TOKEN_EXPIRES_AT'),
        ];

        $defaultStoreRow = $this->resolveDefaultStoreRow();
        if ($defaultStoreRow === null) {
            // AC5 : table `stores` vide (install jamais migrée) -> repli strict sur les
            // constantes seules, comportement inchangé.
            return $constants;
        }

        return $this->freshestTokenState($constants, $this->tokenStateFromStoreRow($defaultStoreRow));
    }

    /**
     * Effectue l'appel HTTP direct module→Shopify de refresh du token
     * (POST https://{shop}/admin/oauth/access_token, grant_type=refresh_token).
     *
     * Ne loggue JAMAIS le corps de la réponse ni les tokens (secrets) — uniquement statut HTTP.
     *
     * @return array{ok:bool,access_token?:string,refresh_token?:string,expires_at?:string}|null
     *               null = erreur réseau (transitoire) ; ['ok'=>false] = rejet définitif Shopify ;
     *               ['ok'=>true, ...] = nouveau couple obtenu.
     * @since 2.4.1
     */
    private function performTokenRefreshHttpCall(): ?array
    {
        if (
            empty($this->config->shopify_store_hostname)
            || empty($this->config->shopify_api_key)
            || empty($this->config->shopify_api_secret_key)
            || empty($this->config->shopify_refresh_token)
        ) {
            $this->log('performTokenRefreshHttpCall() - configuration incomplète pour le refresh (hostname/api_key/api_secret/refresh_token)', LOG_ERR);
            return ['ok' => false];
        }

        try {
            $refreshClient = new Client([
                'base_uri' => 'https://' . $this->config->shopify_store_hostname . '/',
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
                'timeout' => 15,
            ]);

            $response = $refreshClient->request('POST', 'admin/oauth/access_token', [
                RequestOptions::JSON => [
                    'client_id' => $this->config->shopify_api_key,
                    'client_secret' => $this->config->shopify_api_secret_key,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->config->shopify_refresh_token,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            // Jamais le corps (contient access_token/refresh_token) — uniquement le statut
            $this->log('performTokenRefreshHttpCall() - statut HTTP ' . $statusCode, LOG_INFO);

            if ($statusCode !== 200) {
                // 400/401 = refresh_token invalide/révoqué/déjà consommé (hors tolérance ~1h) -> définitif
                return ['ok' => false];
            }

            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || empty($decoded['access_token']) || empty($decoded['refresh_token']) || empty($decoded['expires_in'])) {
                $this->log('performTokenRefreshHttpCall() - réponse Shopify incomplète (statut 200 mais champs manquants)', LOG_ERR);
                return ['ok' => false];
            }

            $expiresAt = date('Y-m-d H:i:s', time() + (int) $decoded['expires_in'] - self::TOKEN_EXPIRY_MARGIN_SECONDS);

            return [
                'ok' => true,
                'access_token' => (string) $decoded['access_token'],
                'refresh_token' => (string) $decoded['refresh_token'],
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            // Erreur réseau (timeout, DNS...) : transitoire — pas un rejet définitif du refresh_token
            $this->log('performTokenRefreshHttpCall() - erreur réseau lors du refresh: ' . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Écrit le triplet access_token/refresh_token/token_expires_at dans les constantes
     * DOLI2SHOP_* (copie « boutique par défaut / chemin historique »). Chaque écriture est
     * vérifiée individuellement — même pattern que oauth_receive.php — jamais silencieuse.
     *
     * Story write-default-store-constants-flag-reconnect-hors-contrat (AC1, Task 1 — décision
     * assumée) : la 4ᵉ écriture (remise à zéro de DOLI2SHOP_TOKEN_RECONNECT_REQUIRED) reste
     * volontairement HORS du contrat de retour ($ok ne reflète que le triplet de jeton). Une
     * autre option (faire échouer $ok si cette 4ᵉ écriture échoue seule) romprait la distinction
     * que le hotfix 2.5.2 (point 3, cf. persistRefreshedToken()) a précisément établie entre
     * « le jeton est persisté » et « le flag est à jour » : côté chemin fkStore===0 (plus bas,
     * persistRefreshedToken()), le retour de CETTE méthode EST le retour de persistRefreshedToken()
     * lui-même — un échec isolé du flag ferait alors passer par markReconnectRequired(), qui
     * repose « reconnexion requise » sur les deux copies, y compris celle qui vient de recevoir
     * un jeton pourtant valide. Le flag se corrige de lui-même au prochain refresh réussi (nouvel
     * appel à cette méthode) ou via fetchFreshTokenState() (point 2 : adoption de la copie la
     * plus fraîche) — auto-guérison jugée suffisante pour ne pas complexifier le contrat de
     * retour. Journalisé distinctement (AC2) pour ne jamais confondre cet échec isolé avec un
     * échec de persistance du jeton lui-même.
     *
     * @param  string $accessToken  Nouvel access_token
     * @param  string $refreshToken Nouveau refresh_token
     * @param  string $expiresAt    Échéance calculée
     * @return bool true si les 3 écritures du TRIPLET DE JETON ont réussi — indépendamment du
     *              résultat de la remise à zéro du flag reconnexion (voir docblock ci-dessus)
     * @since 2.5.2
     */
    private function writeDefaultStoreTokenConstants(string $accessToken, string $refreshToken, string $expiresAt): bool
    {
        dol_include_once('/core/lib/admin.lib.php');
        $ok = (dolibarr_set_const($this->db, 'DOLI2SHOP_ACCESS_TOKEN', $accessToken, 'chaine', 0, '', $this->entity) > 0);
        $ok = $ok && (dolibarr_set_const($this->db, 'DOLI2SHOP_REFRESH_TOKEN', $refreshToken, 'chaine', 0, '', $this->entity) > 0);
        $ok = $ok && (dolibarr_set_const($this->db, 'DOLI2SHOP_TOKEN_EXPIRES_AT', $expiresAt, 'chaine', 0, '', $this->entity) > 0);
        if ($ok) {
            $flagReset = (dolibarr_set_const($this->db, 'DOLI2SHOP_TOKEN_RECONNECT_REQUIRED', '0', 'yesno', 0, '', $this->entity) > 0);
            if (!$flagReset) {
                // AC2 : journalisé À PART des 3 écritures ci-dessus — le triplet de jeton, lui,
                // a réussi ($ok est vrai à ce stade). Ne pas confondre avec un échec de
                // persistance du jeton lui-même (message distinct, cf. Dev Notes de la story).
                $this->log(
                    'writeDefaultStoreTokenConstants() - le triplet de jeton a été persisté avec '
                    . 'succès mais la remise à zéro de DOLI2SHOP_TOKEN_RECONNECT_REQUIRED a échoué '
                    . 'seule (entity=' . $this->entity . ') — non bloquant (hors contrat de retour,'
                    . ' cf. docblock), le flag se corrigera au prochain refresh réussi',
                    LOG_ERR
                );
            }
        }
        return $ok;
    }

    /**
     * Persiste le nouveau couple access_token/refresh_token/token_expires_at APRÈS un refresh
     * réussi, AVANT toute utilisation (CORRECTION VALIDATE P3).
     *
     * Hotfix 2.5.2 (AC1/AC2/AC5) : la boutique PAR DÉFAUT a deux copies de ses credentials — la
     * ligne `stores` ET les constantes DOLI2SHOP_*. La décision « écrire aussi les constantes »
     * se prend sur `$this->isDefaultStore`, JAMAIS sur `$this->fkStore > 0` seul (qui vaut aussi
     * vrai pour la boutique par défaut accédée via forStore(), ex. ShopifyTokenRefreshCron —
     * c'était la source du défaut : refresh via forStore() n'écrivait alors QUE la ligne
     * `stores`, jamais les constantes, laissant une copie avec un refresh_token périmé/révoqué
     * par Shopify dès que l'autre copie rafraîchissait). Une boutique SECONDAIRE (Epic 47)
     * continue à n'écrire QUE la ligne `stores`, jamais les constantes — invariant inchangé.
     *
     * Review (point 3, HIGH) — CONTRAT DE RETOUR EXPLICITE en cas d'échec PARTIEL (branche
     * boutique par défaut / fkStore>0) : le retour reflète UNIQUEMENT le succès de la copie
     * PRIMAIRE de CE chemin (la ligne `stores`, écrite par forStore()), jamais un ET avec le
     * résultat de la synchro best-effort vers les constantes. Rationale : si le refresh Shopify
     * a réussi et que la ligne `stores` porte déjà le jeton neuf, mais que SEULE la synchro vers
     * les constantes échoue (panne SQL transitoire), renvoyer false ferait passer
     * ensureFreshToken() par markReconnectRequired() — qui, `isDefaultStore` étant vrai, pose le
     * flag "reconnexion requise" SUR LES DEUX copies, y compris celle qui vient de recevoir le
     * jeton valide. Ce cas (« refresh réussi, synchro seule en échec ») est donc DISTINCT de
     * « Shopify a rejeté le refresh » (qui ne passe jamais par cette méthode : ensureFreshToken()
     * appelle markReconnectRequired() directement sur `$result['ok'] !== true`, avant tout appel
     * à persistRefreshedToken()) et de « la copie primaire elle-même a échoué » (ci-dessous,
     * return false — cas réel, traité comme avant). L'écart entre les deux copies laissé par une
     * synchro en échec s'auto-guérit au prochain double-check post-lock (fetchFreshTokenState(),
     * point 2 : adoption de la copie la plus fraîche) ou au refresh suivant.
     *
     * @param  string $accessToken  Nouvel access_token
     * @param  string $refreshToken Nouveau refresh_token (rotation obligatoire, usage unique)
     * @param  string $expiresAt    Échéance calculée (marge de sécurité déjà déduite)
     * @return bool true si la copie PRIMAIRE de ce chemin a été persistée avec succès —
     *              indépendamment du résultat d'une synchro best-effort vers l'AUTRE copie de
     *              la boutique par défaut ; false si la copie primaire elle-même a échoué.
     * @since 2.4.1
     */
    private function persistRefreshedToken(string $accessToken, string $refreshToken, string $expiresAt): bool
    {
        if (strlen($refreshToken) > 500) {
            $this->log('persistRefreshedToken() - refresh_token > 500 caractères, risque de troncature silencieuse (colonne varchar(512))', LOG_WARNING);
        }

        if ($this->fkStore > 0) {
            require_once dirname(__FILE__) . '/storeservice.class.php';
            $storeService = new StoreService($this->db, $this->entity);
            $result = $storeService->update($this->fkStore, [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $expiresAt,
                'token_reconnect_required' => false,
            ]);
            // Contrat de StoreService::update() (revu en 58-3) : 1 = ligne modifiée ;
            // 0 = no-op légitime (valeurs déjà identiques) ; -1 = erreur SQL ;
            // -2 = boutique introuvable/hors entité. Ici, seul 1 vaut succès : un refresh de
            // jeton qui n'écrit rien est suspect, la strictesse est intentionnelle.
            $storeRowWritten = ($result >= 1);

            if (!$this->isDefaultStore) {
                // Boutique secondaire (AC2, invariant Epic 47) : jamais les constantes.
                return $storeRowWritten;
            }

            // Boutique PAR DÉFAUT accédée via forStore() (fkStore > 0 malgré tout) : synchroniser
            // AUSSI les constantes (AC1) — c'est exactement le chemin qui était cassé.
            $constantsWritten = $this->writeDefaultStoreTokenConstants($accessToken, $refreshToken, $expiresAt);
            if (!$constantsWritten) {
                $this->log('persistRefreshedToken() - ÉCHEC synchronisation des constantes DOLI2SHOP_* pour la boutique par défaut (rowid=' . $this->fkStore . ') après refresh via forStore() — NON BLOQUANT : la ligne stores (copie primaire de ce chemin) porte déjà le jeton neuf, auto-guérison au prochain double-check ou refresh (review point 3)', LOG_ERR);
            }

            // Point 3 : le contrat de retour ne dépend QUE de la copie primaire de ce chemin —
            // voir docblock ci-dessus.
            return $storeRowWritten;
        }

        // fkStore === 0 : chemin historique (constantes). Recouvre deux cas distincts :
        //  - install JAMAIS migrée (table `stores` vide) : AC5, comportement strictement
        //    inchangé, une seule copie ;
        //  - boutique par défaut d'une install MIGRÉE, mais accédée sans objet boutique (ex.
        //    écrans admin qui utilisent délibérément `new ShopifyApi($db)` pour la boutique
        //    par défaut — Story 49-9).
        $constantsWritten = $this->writeDefaultStoreTokenConstants($accessToken, $refreshToken, $expiresAt);

        if ($constantsWritten) {
            // AC1 : si une ligne "boutique par défaut" existe déjà (install migrée), la
            // synchroniser aussi. Sans cela, un refresh déclenché depuis CE chemin (écrans
            // admin, CRON import historique) consommerait le refresh_token à usage unique sans
            // jamais le répercuter sur la ligne `stores` — cassant le PROCHAIN refresh tenté via
            // forStore() (ex. le CRON quotidien), exactement le même défaut vu dans l'autre sens.
            // Best-effort et non bloquant : les constantes (seule source lue par CE chemin) sont
            // déjà persistées avec succès ; un échec de cette synchro secondaire ne doit jamais
            // faire échouer le refresh lui-même. AC5/point 6 (review) : table vide ->
            // resolveDefaultStoreRow() renvoie null (mémorisé, UN SEUL SELECT par instance) ->
            // aucune écriture supplémentaire, comportement strictement inchangé.
            $defaultStoreRow = $this->resolveDefaultStoreRow();
            if ($defaultStoreRow !== null) {
                require_once dirname(__FILE__) . '/storeservice.class.php';
                $storeService = new StoreService($this->db, $this->entity);
                $syncResult = $storeService->update((int) $defaultStoreRow->rowid, [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_expires_at' => $expiresAt,
                    'token_reconnect_required' => false,
                ]);
                if ($syncResult < 0) {
                    $this->log('persistRefreshedToken() - synchro best-effort de la ligne stores (boutique par défaut rowid=' . $defaultStoreRow->rowid . ') échouée après refresh via constantes (non bloquant)', LOG_WARNING);
                }
            }
        }

        return $constantsWritten;
    }

    /**
     * Applique le nouveau couple en mémoire ($this->config) ET invalide/réécrit le cache statique
     * (piège identifié — sinon une 2e instanciation de ShopifyApi dans le même process PHP
     * relirait l'ancien token, potentiellement déjà invalidé côté Shopify après rotation).
     *
     * @param  string $accessToken   Nouvel access_token
     * @param  string $refreshToken  Nouveau refresh_token
     * @param  string $expiresAt     Échéance calculée
     * @param  bool   $reinitClient  true = reconstruire le client Guzzle avec le nouveau header
     * @return void
     * @since 2.4.1
     */
    private function applyRefreshedToken(string $accessToken, string $refreshToken, string $expiresAt, bool $reinitClient): void
    {
        $this->config->shopify_access_token = $accessToken;
        $this->config->shopify_refresh_token = $refreshToken;
        $this->config->shopify_token_expires_at = $expiresAt;

        $cacheKey = $this->entity . ':' . $this->fkStore;
        self::$configCache[$cacheKey] = clone $this->config;

        if ($reinitClient) {
            $this->initClient();
        }
    }

    /**
     * Marque la boutique en statut "reconnexion requise" (AC9) — signal admin non bloquant.
     * N'A JAMAIS pour effet de désactiver la boutique (invariant Epic 47 : la boutique par
     * défaut n'est jamais bloquée automatiquement).
     *
     * Hotfix 2.5.2 (AC3) : même asymétrie que persistRefreshedToken() — posait déjà le flag à
     * deux endroits différents selon `$fkStore`, sans jamais tenir compte de `is_default`. Une
     * boutique par défaut accédée via forStore() ne posait le flag QUE sur la ligne `stores`,
     * jamais sur la constante lue par les écrans admin de la boutique par défaut.
     *
     * @return void
     * @since 2.4.1
     */
    private function markReconnectRequired(): void
    {
        try {
            if ($this->fkStore > 0) {
                require_once dirname(__FILE__) . '/storeservice.class.php';
                $storeService = new StoreService($this->db, $this->entity);
                $storeService->setReconnectRequired($this->fkStore, true);

                if ($this->isDefaultStore) {
                    // AC3 : boutique par défaut accédée via forStore() -> poser aussi le flag
                    // côté constantes (sinon un écran lisant les constantes pour la boutique par
                    // défaut ne voit jamais "reconnexion requise").
                    dol_include_once('/core/lib/admin.lib.php');
                    dolibarr_set_const($this->db, 'DOLI2SHOP_TOKEN_RECONNECT_REQUIRED', '1', 'yesno', 0, '', $this->entity);
                }
            } else {
                dol_include_once('/core/lib/admin.lib.php');
                dolibarr_set_const($this->db, 'DOLI2SHOP_TOKEN_RECONNECT_REQUIRED', '1', 'yesno', 0, '', $this->entity);

                // Symétrique : si une ligne "boutique par défaut" existe (install migrée),
                // poser aussi le flag côté ligne stores (best-effort). AC5/point 6 (review) :
                // table vide -> resolveDefaultStoreRow() renvoie null (mémorisé, UN SEUL SELECT
                // par instance/cycle de refresh — partagé avec fetchFreshTokenState()/
                // persistRefreshedToken() de la MÊME instance), aucune écriture supplémentaire.
                $defaultStoreRow = $this->resolveDefaultStoreRow();
                if ($defaultStoreRow !== null) {
                    require_once dirname(__FILE__) . '/storeservice.class.php';
                    $storeService = new StoreService($this->db, $this->entity);
                    $storeService->setReconnectRequired((int) $defaultStoreRow->rowid, true);
                }
            }
        } catch (\Throwable $e) {
            $this->log('markReconnectRequired() - échec écriture flag (non bloquant): ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Version de l'API Shopify utilisée pour les appels GraphQL (endpoint).
     *
     * Story 51-2 (CORRECTION VALIDATE P-HIGH) : NE PLUS utiliser cet accesseur comme source
     * de la « version cible webhooks » — ShopifyWebhooks utilise désormais la constante
     * séparée self::WEBHOOK_TARGET_API_VERSION (cf. commentaire de la constante).
     *
     * @return string ex. '2026-07'
     * @since 2.3.2
     */
    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Factory : instancie ShopifyApi avec les credentials d'une boutique spécifique.
     * Raccourci utile pour ImportProducts/ShopifyProductImporter.
     *
     * @param DoliDB   $db     Database handler
     * @param object   $store  Objet boutique (StoreService)
     * @param int|null $entity Entité (null = conf->entity)
     * @return self
     */
    public static function forStore($db, $store, $entity = null): self
    {
        return new self($db, $entity, $store);
    }

    /**
     * Get config object
     *
     * @return object Configuration
     */
    public function getConfig()
    {
        return $this->config;
    }
    
    /**
     * Test Shopify API connection and available scopes
     * 
     * @return array Test results with status and details
     */
    public function testShopifyConnection()
    {
        $results = [
            'connection' => false,
            'scopes' => [],
            'scopes_analysis' => [],
            'shop_info' => null,
            'errors' => []
        ];
        
        // Define required scopes with descriptions
        // v2.0.0 - Liste des scopes optimisés (23 scopes)
        // REQUIRED = 14 scopes obligatoires
        // OPTIONAL = 9 scopes utiles selon fonctionnalités
        $requiredScopes = [
            // Products & Inventory (REQUIRED)
            'read_products' => ['required' => true, 'category' => 'products', 'description' => 'read_products'],
            'write_products' => ['required' => true, 'category' => 'products', 'description' => 'write_products'],
            'read_inventory' => ['required' => true, 'category' => 'inventory', 'description' => 'read_inventory'],
            'write_inventory' => ['required' => true, 'category' => 'inventory', 'description' => 'write_inventory'],
            'read_locations' => ['required' => true, 'category' => 'locations', 'description' => 'read_locations'],

            // Customers (REQUIRED - PII Access)
            'read_customers' => ['required' => true, 'category' => 'customers', 'description' => 'read_customers'],
            'write_customers' => ['required' => true, 'category' => 'customers', 'description' => 'write_customers'],

            // Orders (REQUIRED)
            'read_orders' => ['required' => true, 'category' => 'orders', 'description' => 'read_orders'],
            'write_orders' => ['required' => true, 'category' => 'orders', 'description' => 'write_orders'],

            // Fulfillment (REQUIRED - assigned orders)
            'read_assigned_fulfillment_orders' => ['required' => true, 'category' => 'fulfillment', 'description' => 'read_assigned_fulfillment_orders'],
            'write_assigned_fulfillment_orders' => ['required' => true, 'category' => 'fulfillment', 'description' => 'write_assigned_fulfillment_orders'],

            // Files & Themes (REQUIRED - for fileCreate workflow)
            'read_files' => ['required' => true, 'category' => 'files', 'description' => 'read_files'],
            'write_files' => ['required' => true, 'category' => 'files', 'description' => 'write_files'],
            'write_themes' => ['required' => true, 'category' => 'files', 'description' => 'write_themes'],

            // Publications & Channels (OPTIONAL - for collections)
            'read_channels' => ['required' => false, 'category' => 'channels', 'description' => 'read_channels'],
            'write_publications' => ['required' => false, 'category' => 'channels', 'description' => 'write_publications'],

            // Shipping (OPTIONAL - future use)
            'read_shipping' => ['required' => false, 'category' => 'shipping', 'description' => 'read_shipping'],
            'write_shipping' => ['required' => false, 'category' => 'shipping', 'description' => 'write_shipping'],

            // Historical Orders (OPTIONAL - orders > 60 days)
            'read_all_orders' => ['required' => false, 'category' => 'orders', 'description' => 'read_all_orders'],

            // Extended Fulfillment (OPTIONAL)
            'read_third_party_fulfillment_orders' => ['required' => false, 'category' => 'fulfillment', 'description' => 'read_third_party_fulfillment_orders'],
            'write_third_party_fulfillment_orders' => ['required' => false, 'category' => 'fulfillment', 'description' => 'write_third_party_fulfillment_orders'],
            'read_merchant_managed_fulfillment_orders' => ['required' => false, 'category' => 'fulfillment', 'description' => 'read_merchant_managed_fulfillment_orders'],
            'write_merchant_managed_fulfillment_orders' => ['required' => false, 'category' => 'fulfillment', 'description' => 'write_merchant_managed_fulfillment_orders']
        ];

        // Rendre read_channels et write_publications critiques si les collections sont activées
        if (getDolGlobalBool('DOLI2SHOP_SYNC_PRODUCT_COLLECTIONS')) {
            $requiredScopes['read_channels']['required'] = true;
            $requiredScopes['write_publications']['required'] = true;
            // Scopes obligatoires pour fileCreate workflow collections (write_files OU write_themes)
            $requiredScopes['write_files']['required'] = true;
            $requiredScopes['write_themes']['required'] = true;
        }

        // Rendre scopes fichiers obligatoires si synchronisation images produits activée
        if (getDolGlobalBool('DOLI2SHOP_SYNC_PRODUCT_IMAGES')) {
            // Scopes obligatoires pour fileCreate workflow produits (v3.0.0)
            $requiredScopes['write_files']['required'] = true;
            $requiredScopes['write_themes']['required'] = true;
        }
        
        if (empty($this->config) || empty($this->config->shopify_access_token) || empty($this->config->shopify_store_hostname)) {
            $results['errors'][] = 'Configuration API Shopify incomplete';
            return $results;
        }
        
        try {
            // Test basic connection with shop query
            $query = '
            query {
                shop {
                    name
                    email
                    currencyCode
                    plan {
                        displayName
                    }
                    billingAddress {
                        country
                    }
                }
                app {
                    requestedAccessScopes {
                        handle
                    }
                    installation {
                        accessScopes {
                            handle
                        }
                    }
                }
            }';
            
            $response = $this->executeGraphQL(['query' => $query]);
            
            if (!empty($response->data->shop)) {
                $results['connection'] = true;
                $results['shop_info'] = $response->data->shop;
                
                // Extract available scopes
                $availableScopes = [];
                if (!empty($response->data->app->installation->accessScopes)) {
                    foreach ($response->data->app->installation->accessScopes as $scope) {
                        $results['scopes'][] = $scope->handle;
                        $availableScopes[] = $scope->handle;
                    }
                }
                
                // Analyze scopes
                foreach ($requiredScopes as $scopeName => $scopeInfo) {
                    $isPresent = in_array($scopeName, $availableScopes);
                    $results['scopes_analysis'][] = [
                        'name' => $scopeName,
                        'description' => $scopeInfo['description'],
                        'required' => $scopeInfo['required'],
                        'present' => $isPresent,
                        'category' => $scopeInfo['category'],
                        'status' => $isPresent ? 'success' : ($scopeInfo['required'] ? 'error' : 'warning')
                    ];
                }
                
                $this->log("Shopify connection test successful", LOG_INFO);
            } else {
                $results['errors'][] = 'Réponse API Shopify invalide';
            }
            
        } catch (\Throwable $e) {
            // FIX 8 — \Throwable capture aussi TypeError/Error PHP 8 (ex. type mismatch config)
            $results['errors'][] = 'Erreur connexion API: ' . $e->getMessage();
            $this->log("Shopify connection test failed: " . $e->getMessage(), LOG_ERR);
        }

        return $results;
    }

    /**
     * Check if configuration is complete for a specific context
     * 
     * @param string $context The context to check: 'general', 'orders', 'products'
     * @return bool True if configuration is complete for the given context
     */
    public function isConfigurationComplete($context = 'general')
    {
        // Check if configuration is loaded
        if (empty($this->config)) {
            dol_syslog("ShopifyApi::isConfigurationComplete - No configuration found", LOG_WARNING);
            return false;
        }
        
        // Check required Shopify fields (always required)
        $requiredShopifyFields = [
            'shopify_store_hostname',
            'shopify_access_token',
            'shopify_location_id'
        ];
        
        foreach ($requiredShopifyFields as $field) {
            if (empty($this->config->$field)) {
                dol_syslog("ShopifyApi::isConfigurationComplete - Missing required field: " . $field, LOG_WARNING);
                return false;
            }
        }
        
        // Check context-specific fields
        $contextFields = [];
        switch ($context) {
            case 'orders':
                $contextFields = [
                    'order_origin',
                    'payment_terms',
                    'default_shipping_method_id',
                    'default_warehouse_id'
                ];
                break;
                
            case 'products':
                $contextFields = ['dolibarr_procate'];
                break;
                
            case 'general':
            default:
                // No additional fields required for general context
                break;
        }
        
        foreach ($contextFields as $field) {
            if (empty($this->config->$field)) {
                dol_syslog("ShopifyApi::isConfigurationComplete - Missing {$context} field: " . $field, LOG_WARNING);
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get safe config string for logging
     *
     * @return string Configuration details with masked sensitive fields
     */
    private function getSafeConfigString()
    {
        if (empty($this->config)) {
            return "Configuration not loaded";
        }

        // Pour l'affichage dans les logs uniquement
        $logConfig = clone $this->config;
        $logConfig->shopify_access_token = '....' . substr($this->config->shopify_access_token, -4);
        $logConfig->shopify_api_key = '....' . substr($this->config->shopify_api_key, -4);
        $logConfig->shopify_api_secret_key = '....' . substr($this->config->shopify_api_secret_key, -4);
        // Story 57-5 : masquage de dolibarr_api_key retiré — le champ n'existe plus dans la
        // configuration (mapping retiré de ConfigurationMigrator, 57-7 ayant rendu la clé API
        // Dolibarr self sans objet). $this->config->dolibarr_api_key n'est donc plus jamais défini.
        // FIX CRITIQUE (review 51-1) : shopify_refresh_token n'était PAS redacté — fuite du
        // refresh_token complet (secret 90j usage unique) à CHAQUE instanciation loguée en LOG_INFO.
        if (!empty($this->config->shopify_refresh_token)) {
            $logConfig->shopify_refresh_token = '....' . substr((string) $this->config->shopify_refresh_token, -4);
        }
        // Non sensible mais nettoyé par cohérence (pas de valeur à masquer, juste homogénéité du log)
        if (isset($this->config->shopify_token_expires_at)) {
            $logConfig->shopify_token_expires_at = (string) $this->config->shopify_token_expires_at;
        }

        return print_r($logConfig, true);
    }

    /**
     * Initialize GraphQL client with headers
     */
    private function initClient()
    {
        if (empty($this->config)) {
            dol_syslog("ShopifyApi::initClient - No configuration loaded", LOG_WARNING);
            return false;
        }

        // Validate required configuration fields
        if (empty($this->config->shopify_store_hostname)) {
            $this->error = "Shopify store hostname is not configured";
            $this->errors[] = $this->error;
            $this->log("Error: " . $this->error, LOG_ERR);
            return false;
        }

        if (empty($this->config->shopify_access_token)) {
            $this->error = "Shopify access token is not configured";
            $this->errors[] = $this->error;
            $this->log("Error: " . $this->error, LOG_ERR);
            return false;
        }

        $this->client = new Client([
            'base_uri' =>  'https://' . $this->config->shopify_store_hostname . '/admin/api/' . $this->apiVersion . '/',
            'headers' =>  [
                'X-Shopify-Access-Token' =>  $this->config->shopify_access_token,
                'Content-Type' =>  'application/json',
                'Accept' =>  'application/json'
            ],
            'http_errors' =>  false,
            'allow_redirects' =>  true,
            'verify' =>  true,
            'timeout' =>  30
        ]);
    }


    /**
     * Execute a GraphQL query
     *
     * @param array $data Query data with query and variables
     * @return object Response object
     * @throws Exception If there's a network error
     */
    /**
     * Relève les erreurs métier renvoyées par Shopify dans une réponse GraphQL.
     *
     * ## Pourquoi cette méthode existe
     *
     * Shopify signale les refus métier dans `userErrors` (ou `mediaUserErrors`), **pas** dans le
     * code HTTP ni dans `errors` : une mutation refusée répond **200 OK** avec un champ vide et
     * une explication en clair dans `userErrors`. Le module demandait ce champ dans 26 mutations
     * et ne le lisait que dans trois.
     *
     * Conséquence constatée en production le 19/09/2026, chez un client dont les photos ne
     * remontaient plus : `stagedUploadsCreate` refusait le nom de fichier
     * (« B000107161008/B000107161008.jpg: Invalid filename ») et renvoyait une URL VIDE. Le module,
     * qui ne lisait pas `userErrors`, téléversait alors vers cette URL vide et obtenait une erreur
     * cURL incompréhensible — après avoir **déjà supprimé** les images existantes côté Shopify.
     * Le produit restait donc sans aucune image, et le message qui disait exactement pourquoi
     * n'était lu par personne.
     *
     * ⚠️ Cette méthode ne fait pas échouer l'appel : elle **journalise**. Transformer un
     * `userErrors` en exception changerait le comportement de 26 mutations d'un coup, dont
     * certaines où un refus est un cas métier normal. Rendre visible d'abord ; décider ensuite,
     * mutation par mutation.
     *
     * @param  object|null $decoded Réponse Shopify décodée
     * @return array<int,string>    Messages relevés (vide si aucun)
     * @since  2.5.5
     */
    public function collectUserErrors($decoded)
    {
        $messages = [];

        if (!is_object($decoded) || !isset($decoded->data) || !is_object($decoded->data)) {
            return $messages;
        }

        // Shopify emploie les deux noms selon la mutation : `userErrors` partout, et
        // `mediaUserErrors` sur les mutations de médias produit.
        foreach (get_object_vars($decoded->data) as $mutationName => $payload) {
            if (!is_object($payload)) {
                continue;
            }
            foreach (['userErrors', 'mediaUserErrors'] as $errorField) {
                if (empty($payload->$errorField) || !is_array($payload->$errorField)) {
                    continue;
                }
                foreach ($payload->$errorField as $err) {
                    // ⚠️ Casts DÉFENSIFS. Cette méthode tourne désormais sur TOUTE réponse
                    // GraphQL ; un `field` ou un `message` que Shopify renverrait un jour sous
                    // forme d'objet provoquerait une `Error` fatale — que les `catch (Exception)`
                    // du module n'interceptent pas. Un relevé d'erreurs qui fait tomber l'appel
                    // qu'il observe serait pire que pas de relevé.
                    // (MEDIUM relevé par la revue 3 couches du 21/09.)
                    $field = '';
                    if (isset($err->field)) {
                        if (is_array($err->field)) {
                            $field = implode('.', array_map('strval', array_filter($err->field, 'is_scalar')));
                        } elseif (is_scalar($err->field)) {
                            $field = (string) $err->field;
                        }
                    }
                    $message = 'erreur sans message';
                    if (isset($err->message) && is_scalar($err->message)) {
                        $message = (string) $err->message;
                    }
                    $messages[] = $mutationName . ($field !== '' ? ' [' . $field . ']' : '') . ' : ' . $message;
                }
            }
        }

        return $messages;
    }

    /**
     * Journalise les erreurs métier d'une réponse, s'il y en a.
     *
     * @param  object|null $decoded Réponse Shopify décodée
     * @return void
     */
    private function logUserErrors($decoded)
    {
        foreach ($this->collectUserErrors($decoded) as $message) {
            $this->log('Shopify a REFUSE cette operation : ' . $message
                . ' — la reponse est 200 OK, mais le champ attendu est vide.', LOG_ERR);
        }
    }

    public function executeGraphQL($data)
    {
        // Validate configuration before making any API call
        if (empty($this->config) || empty($this->config->shopify_store_hostname)) {
            $error = "Shopify API configuration missing: No store hostname configured";
            $this->log("Error: " . $error, LOG_ERR);
            throw new Exception($error);
        }

        if (empty($this->client)) {
            $error = "Shopify API client is not initialized. Check configuration.";
            $this->log("Error: " . $error, LOG_ERR);
            throw new Exception($error);
        }

        // Story 7.3 AC4 (FR33): Retry with exponential backoff on Shopify GraphQL throttle
        // Note: Shopify GraphQL returns HTTP 200 with errors[].message="Throttled" (NOT HTTP 429)
        // Note: http_errors=false on Guzzle client means GuzzleException only fires on network errors
        $maxRetries = 3;

        // Story 51-1 (T5, AC8) : au plus UNE tentative de refresh+retry sur 401 par appel
        // executeGraphQL(), quel que soit l'état du budget de retry throttle ci-dessus.
        $hasRetriedAfter401 = false;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $options = array();
                $options[RequestOptions::JSON] = $data;

                if ($attempt === 0) {
                    $this->log("GraphQL Query: " . print_r($data, true), LOG_DEBUG);
                }

                $response = $this->client->request(
                    'POST',
                    'graphql.json',
                    $options
                );

                $statusCode = $response->getStatusCode();
                $this->log("Shopify API response status: " . $statusCode, LOG_DEBUG);
                $body = $response->getBody()->getContents();
                $decoded = json_decode($body, false);

                $this->log("Shopify API response body: " . print_r($decoded, true), LOG_DEBUG);

                // Story 51-1 (T5, AC8/AC9) : détection HTTP 401 (token invalide/expiré — race
                // condition, refresh manqué, ou 401 pur car token non expirable révoqué côté Shopify).
                if ($statusCode === 401) {
                    if (!$hasRetriedAfter401) {
                        $hasRetriedAfter401 = true;
                        $this->log("Shopify API 401 détecté — refresh forcé + retry unique", LOG_WARNING);
                        try {
                            // forceRefresh=true : bypass le check "proche de l'expiration", le 401
                            // EST la preuve que le token est mort. No-op silencieux si pas de
                            // refresh_token connu (AC3 — install non migrée).
                            $this->ensureFreshToken(true);
                        } catch (\Throwable $e) {
                            $this->log("ensureFreshToken(force) a échoué pendant la gestion 401: " . $e->getMessage(), LOG_ERR);
                        }
                        // Compenser l'auto-incrément du for : le retry 401 ne doit JAMAIS consommer
                        // le budget de retry throttle (sinon un 401 sur le dernier attempt throttle
                        // sortirait de la boucle sans jamais atteindre la branche "définitif" ci-dessous).
                        $attempt--;
                        continue;
                    }

                    // Retry déjà tenté et échoué de nouveau (401 persistant) : ne PAS boucler
                    // indéfiniment (AC8) — signaler "reconnexion requise" (AC9, jamais de
                    // désactivation automatique de la boutique par défaut) et renvoyer la
                    // réponse telle quelle à l'appelant (comportement historique : pas d'exception).
                    $this->log("Shopify API 401 persistant après refresh+retry — reconnexion requise", LOG_ERR);
                    $this->markReconnectRequired();
                    $this->logUserErrors($decoded);
                    return $decoded;
                }

                // Story 7.3 AC4: Detect Shopify GraphQL throttle (HTTP 200 + errors[].message="Throttled")
                if (isset($decoded->errors) && is_array($decoded->errors)) {
                    foreach ($decoded->errors as $gqlError) {
                        if (isset($gqlError->message) && stripos($gqlError->message, 'Throttled') !== false) {
                            if ($attempt < $maxRetries) {
                                $waitSeconds = (int) pow(2, $attempt); // 1s, 2s, 4s
                                $this->log("Shopify GraphQL throttled, retry " . ($attempt + 1) . "/" . $maxRetries . " after " . $waitSeconds . "s", LOG_WARNING);
                                sleep($waitSeconds);
                                continue 2; // restart the for loop
                            }
                            $this->log("Shopify GraphQL throttled after " . $maxRetries . " retries, returning last response", LOG_ERR);
                        }
                    }
                } elseif (isset($decoded->errors) && is_string($decoded->errors) && $decoded->errors !== '') {
                    // (CORRECTION VALIDATE P4) Shopify renvoie parfois `errors` en STRING top-level
                    // (pas seulement en tableau) — format également observé empiriquement chez
                    // Shopify. Le check is_array() ci-dessus l'ignorerait silencieusement. On ne
                    // logge que le message (pas de secret dedans), sans changer le comportement
                    // de retour (la détection 401 ci-dessus reste basée sur le statut HTTP, pas
                    // sur ce champ).
                    $this->log("Shopify API returned string-form error: " . $decoded->errors, LOG_WARNING);
                }

                // Story 7.3 AC4: Proactive sleep when bucket is running low (non-throttled responses)
                if (isset($decoded->extensions->cost->throttleStatus->currentlyAvailable)) {
                    $available = (int) $decoded->extensions->cost->throttleStatus->currentlyAvailable;
                    if ($available < 100) {
                        $sleepMs = (int) ((100 - $available) * 10);
                        $sleepMs = min($sleepMs, 2000); // Cap at 2 seconds
                        $this->log("GraphQL throttle: " . $available . " points remaining, sleeping " . $sleepMs . "ms", LOG_WARNING);
                        usleep($sleepMs * 1000);
                    }
                }

                $this->logUserErrors($decoded);
                return $decoded;
            } catch (GuzzleException $e) {
                // Network-level errors only (timeout, DNS failure, connection refused)
                // HTTP errors (4xx, 5xx) do NOT trigger this because http_errors=false on the client
                $this->log("Shopify API network error: " . $e->getMessage(), LOG_ERR);

                if ($attempt < $maxRetries) {
                    $waitSeconds = (int) pow(2, $attempt); // 1s, 2s, 4s
                    $this->log("Network error, retry " . ($attempt + 1) . "/" . $maxRetries . " after " . $waitSeconds . "s", LOG_WARNING);
                    sleep($waitSeconds);
                    continue;
                }

                throw new Exception("GraphQL API error: " . $e->getMessage());
            }
        }

        // Should not reach here, but safety fallback
        throw new Exception("GraphQL API error: Max retries exceeded");
    }

    /**
     * Vérifie qu'une réponse GraphQL Shopify (celle renvoyée par executeGraphQL(), TELLE QUELLE)
     * est exploitable ET qu'elle porte bien la valeur attendue au chemin donné sous `data`.
     *
     * Story 62-6 (AC1/AC2/AC3, F1 — jeton Shopify révoqué => faux succès) : extraction du signal
     * déjà répété 3 fois avant cette fonction — `ShopifyOrderManager::fetchHistoricalOrdersBatch()`
     * (Story 63-18), `ShopifyApi::deleteProduct()` (Story 58-5),
     * `ShopifyProductImporter::getProductsBatch()`/`getProductFromShopify()`. `executeGraphQL()`
     * ne lève JAMAIS d'exception sur un 401 persistant (après refresh+retry) ni sur une erreur
     * GraphQL de premier niveau : elle renvoie la réponse Shopify décodée telle quelle (`errors`
     * présent — tableau OU chaîne, format également observé empiriquement chez Shopify, cf.
     * `:1543-1551` — et/ou `data` absent).
     *
     * Le signal générique seul (`!isset($response->data) || isset($response->errors)`, modèle
     * 63-18) traite un `data` présent mais dont le sous-chemin attendu est absent comme un SUCCÈS
     * à valeur vide — c'est le comportement VOULU par 63-18 (`data->orders` absent = plage de
     * commandes épuisée, pas une erreur) mais l'INVERSE de ce qu'exige un comptage
     * (`data->productsCount` absent = schéma Shopify cassé, jamais un total à 0 déguisé en
     * succès — gap trouvé par le Validate du 2026-09-23). D'où le paramètre `$path`, sur le
     * modèle `deleteProduct()`/58-5, qui vérifie `!isset($response->data->productDelete)` — un
     * chemin SPÉCIFIQUE, pas la seule présence générique de `data`.
     *
     * @param mixed $response Réponse décodée de ShopifyApi::executeGraphQL() — stdClass attendu
     *                         (json_decode($body, false)) ; un tableau associatif est également
     *                         accepté (defensive code historique des deux endpoints get_count).
     * @param array $path Chemin des clés à lire sous `data`, ex. ['productsCount', 'count'].
     *                     Un chemin vide se limite au signal générique (présence de `data`, pas
     *                     d'`errors`) — à ne PAS utiliser pour un comptage (cf. ci-dessus).
     * @return array{success: bool, value: mixed} `success` false si la réponse est inexploitable
     *              (pas de `data`, `errors` présent et non vide — tableau ou chaîne — ou chemin
     *              absent/valeur null à un maillon quelconque du chemin).
     * @since 2.6.0
     */
    public static function extractGraphQLDataPath($response, array $path)
    {
        $failure = ['success' => false, 'value' => null];

        if ($response === null) {
            return $failure;
        }

        $isArrayResponse = is_array($response);

        if (!$isArrayResponse && !is_object($response)) {
            return $failure;
        }

        $data = $isArrayResponse ? ($response['data'] ?? null) : ($response->data ?? null);
        $errors = $isArrayResponse ? ($response['errors'] ?? null) : ($response->errors ?? null);

        // (CORRECTION VALIDATE 2026-09-23 / AC1) : `errors` en chaîne non vide compte aussi
        // comme un échec — ne jamais se limiter à is_array($errors) (cf. :1543-1551 ci-dessus).
        if ($data === null || !empty($errors)) {
            return $failure;
        }

        $cursor = $data;
        foreach ($path as $key) {
            if (is_array($cursor)) {
                if (!isset($cursor[$key])) {
                    return $failure;
                }
                $cursor = $cursor[$key];
            } elseif (is_object($cursor)) {
                if (!isset($cursor->$key)) {
                    return $failure;
                }
                $cursor = $cursor->$key;
            } else {
                return $failure;
            }
        }

        return ['success' => true, 'value' => $cursor];
    }

    /**
     * Exécute le comptage produits Shopify (`get_count`) et retourne un tableau JSON-ready +
     * code HTTP, prêt à `http_response_code()`/`echo json_encode()` par l'appelant.
     *
     * Story 62-6, CRITICAL (review 3 couches 2026-09-23) : avant ce correctif, la logique
     * (appel `executeGraphQL()` + lecture de `extractGraphQLDataPath()` + calcul de `$total` +
     * `throw` sur échec) vivait DUPLIQUÉE dans les 2 fichiers ajax, et le seul test de câblage
     * existant (`AjaxGetCountGraphQLFailureGuardTest`) prouvait par lecture de source que les
     * fichiers APPELAIENT `extractGraphQLDataPath()`, jamais qu'ils UTILISAIENT réellement son
     * retour — une mutation locale à un fichier ajax (`$total = isset(...) ? ... : 0` en
     * ignorant `$countSignal`, ou un `throw` remplacé par `$total = 0;`) survivait donc en
     * silence. En centralisant TOUTE la logique ici, dans une fonction pure testée à
     * l'EXÉCUTION avec un double de `$shopifyApi` (n'importe quel objet exposant
     * `executeGraphQL($data)`), ces mutations ne peuvent plus se nicher que dans CE point
     * unique, couvert par `ShopifyApiGraphQLDataPathTest`/`AjaxGetCountGraphQLFailureGuardTest`.
     * Les 2 fichiers ajax n'ont plus qu'à appeler cette fonction et échoer son retour tel quel.
     *
     * @param object $shopifyApi Objet exposant executeGraphQL($data) — ShopifyApi réel ou double
     *                            de test (voir `AjaxGetCountGraphQLFailureGuardTest`).
     * @param string $logPrefix  Préfixe de contexte pour `dol_syslog()` (nom du fichier ajax
     *                            appelant + action), ex. `'wizard_sync.php (get_count)'`.
     * @return array{httpCode:int, body:array<string,mixed>}
     * @since 2.6.0
     */
    public static function buildProductsCountAjaxResponse($shopifyApi, $logPrefix)
    {
        try {
            $countQuery = array('query' => '{ productsCount { count } }');
            $countResult = $shopifyApi->executeGraphQL($countQuery);

            $countSignal = self::extractGraphQLDataPath($countResult, array('productsCount', 'count'));
            if (!$countSignal['success']) {
                throw new Exception('Shopify GraphQL response unusable while counting products: ' . json_encode($countResult));
            }

            return array(
                'httpCode' => 200,
                'body' => array(
                    'success' => true,
                    'total' => (int) $countSignal['value'],
                ),
            );
        } catch (Exception $e) {
            return self::buildProductsCountAjaxFailure($logPrefix, $e);
        }
    }

    /**
     * Formate une réponse ajax d'échec uniforme pour `get_count` : message générique côté client,
     * détail complet (classe, message, fichier:ligne) en `dol_syslog(..., LOG_ERR)` — jamais perdu,
     * jamais exposé au client. Pattern déjà en place dans `ajax/sync_products_batch.php` depuis la
     * Story 63-19/LOW 10 ; centralisé ici (Story 62-6, MEDIUM review 3 couches) pour que
     * `ajax/wizard_sync.php` cesse de renvoyer `$e->getMessage()` brut au client — lequel, depuis
     * ce correctif, contient le `json_encode()` intégral de la réponse Shopify.
     *
     * Public (pas seulement appelée par `buildProductsCountAjaxResponse()` ci-dessus) : les 2
     * fichiers ajax l'utilisent aussi directement pour uniformiser l'échec de construction de
     * `ShopifyApi`/`ShopifyApi::forStore()` (avant même le premier appel GraphQL).
     *
     * @param string    $logPrefix Préfixe de contexte pour `dol_syslog()`.
     * @param Exception $e         Exception capturée.
     * @return array{httpCode:int, body:array<string,mixed>}
     * @since 2.6.0
     */
    public static function buildProductsCountAjaxFailure($logPrefix, Exception $e)
    {
        dol_syslog(
            $logPrefix . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            LOG_ERR
        );

        return array(
            'httpCode' => 500,
            'body' => array(
                'success' => false,
                'message' => 'An internal error occurred while counting products. See server logs for details.',
            ),
        );
    }

    /**
     * Execute productSet mutation to create or update a product
     *
     * @param array $productData Product data for mutation
     * @param bool $synchronous Whether to execute synchronously or asynchronously
     * @return object Response from Shopify
     */
    public function productSet($productData, $synchronous = true)
    {
        $query = 'mutation productSet($input: ProductSetInput!, $synchronous: Boolean!) {
            productSet(input: $input, synchronous: $synchronous) {
                product {
                    id
                    title
                    handle
                    options {
                        name
                        values
                    }
                    variants(first: 50) {
                        nodes {
                            id
                            sku
                            inventoryItem {
                                id
                                tracked
                                requiresShipping
                                harmonizedSystemCode
                                countryCodeOfOrigin
                            }
                            inventoryPolicy
                        }
                    }
                    collections(first: 10) {
                        nodes {
                            id
                            title
                            handle
                            legacyResourceId
                        }
                    }
                }
                productSetOperation {
                    id
                    status
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'input' =>  $productData,
            'synchronous' =>  $synchronous
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Update inventory and other variant-specific fields using productVariantsBulkUpdate
     *
     * @param string $productId Shopify product ID
     * @param array $variants Variant data for update
     * @return object Response from Shopify
     */
    public function productVariantsBulkUpdate($productId, $variants)
    {
        $query = 'mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                product {
                    id
                }
                productVariants {
                    id
                    sku
                    inventoryItem {
                        id
                        tracked
                        requiresShipping
                        harmonizedSystemCode
                        countryCodeOfOrigin
                        measurement {
                            weight {
                                value
                                unit
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'productId' =>  'gid://shopify/Product/' . $productId,
            'variants' =>  $variants
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Update inventory quantities for multiple items
     *
     * Uses Shopify @idempotent directive (available since API 2026-01, mandatory in 2026-04)
     * to ensure stock mutations are safe to retry without creating duplicate movements.
     *
     * FIX v2.4.1 (hotfix prod) : AVANT, un seul item du lot sans `current_shopify_quantity`
     * faisait basculer TOUT le lot vers le fallback compare-and-swap
     * (inventorySetOnHandQuantities avec changeFromQuantity=0 pour les items non enrichis)
     * → échecs silencieux (le code appelant vérifiait de toute façon la mauvaise clé de
     * réponse, cf. `aggregateInventoryResults()`) → stocks Shopify jamais corrigés (négatifs).
     * DÉSORMAIS : le lot est scindé AVANT de choisir la mutation — les items AVEC
     * `current_shopify_quantity` partent en delta (inventoryAdjustQuantities, pas de
     * compare-and-swap requis), les items SANS partent séparément en fallback
     * (inventorySetOnHandQuantities). Un item « fantôme » ne pénalise plus les autres.
     *
     * FIX v2.4.1 (review 50-11, CRITICAL) : AVANT, un échec du seul sous-lot fallback faisait
     * retourner une réponse agrégée qui, une fois transmise au retry de
     * ImportProducts::updateInventoryQuantities(), ne permettait pas de distinguer QUEL sous-lot
     * avait échoué → le retry renvoyait la totalité du lot d'origine (delta + fallback) à
     * inventorySetQuantities(), qui rejouait donc le sous-lot delta déjà appliqué avec succès
     * (mutation additive `inventoryAdjustQuantities` → delta compté 2×, stock Shopify faussé).
     * DÉSORMAIS : la réponse agrégée expose `failedFields` (sous-lots en échec, ex.
     * ['inventorySetOnHandQuantities']) et `retryableQuantities` (UNIQUEMENT les items des
     * sous-lots en échec) — le retry n'a plus qu'à consommer `retryableQuantities` au lieu de
     * rejouer l'intégralité de `$quantities`.
     *
     * @param array $quantities Array of inventory quantities
     * @return object Réponse agrégée normalisée (cf. aggregateInventoryResults()) : expose
     *                `errors` (top-level GraphQL, des 2 chemins), `data->inventoryAdjustQuantities`,
     *                `data->inventorySetOnHandQuantities`, `userErrors` (fusion des 2 chemins),
     *                `failedCount` (nombre d'items en échec, toutes causes confondues),
     *                `failedFields` (noms des sous-lots GraphQL en échec) et `retryableQuantities`
     *                (items des SEULS sous-lots en échec, à retourner tels quels à un retry)
     * @see https://shopify.dev/docs/api/usage/idempotent-requests
     */
    public function inventorySetQuantities($quantities)
    {
        // API 2026-04: inventorySetQuantities et inventorySetOnHandQuantities exigent
        // changeFromQuantity (compare-and-swap). On utilise inventoryAdjustQuantities
        // avec delta = target - current pour éviter cette contrainte quand on connaît
        // la quantité courante. Les items sans quantité courante connue partent en
        // fallback isolé (SET absolu, changeFromQuantity=0 par construction).
        $withCurrent = [];
        $withoutCurrent = [];
        foreach ($quantities as $qty) {
            if (array_key_exists('current_shopify_quantity', $qty) && $qty['current_shopify_quantity'] !== null) {
                $withCurrent[] = $qty;
            } else {
                $withoutCurrent[] = $qty;
            }
        }

        $this->log("ShopifyApi::inventorySetQuantities - lot scindé : " . count($withCurrent)
            . " item(s) en delta (inventoryAdjustQuantities), " . count($withoutCurrent)
            . " item(s) en fallback (inventorySetOnHandQuantities)", LOG_DEBUG);

        $deltaResponse = null;
        $fallbackResponse = null;

        if (!empty($withCurrent)) {
            $deltaResponse = $this->inventoryAdjustQuantitiesBatch($withCurrent);
        }
        if (!empty($withoutCurrent)) {
            $fallbackResponse = $this->inventorySetOnHandQuantitiesBatch($withoutCurrent);
        }

        return $this->aggregateInventoryResults($deltaResponse, $fallbackResponse, $withCurrent, $withoutCurrent);
    }

    /**
     * Exécute la mutation inventoryAdjustQuantities (delta) pour un sous-lot d'items qui ont
     * TOUS une current_shopify_quantity connue. Extrait de inventorySetQuantities() (FIX v2.4.1)
     * pour permettre le scindage delta/fallback par sous-lot au lieu d'un choix global sur
     * l'ensemble du lot.
     *
     * @param array $quantities Sous-lot d'items avec current_shopify_quantity
     * @return object|null Réponse GraphQL brute, ou null si tous les deltas valaient 0
     * @since 2.4.1
     */
    private function inventoryAdjustQuantitiesBatch(array $quantities)
    {
        $idempotencyKey = self::generateUuidV4();
        $this->log("ShopifyApi::inventoryAdjustQuantitiesBatch - idempotencyKey: " . $idempotencyKey, LOG_DEBUG);

        $query = 'mutation inventoryAdjustQuantities($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!) {
            inventoryAdjustQuantities(input: $input) @idempotent(key: $idempotencyKey) {
                inventoryAdjustmentGroup {
                    id
                    changes {
                        name
                        delta
                        quantityAfterChange
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $convertedQuantities = [];
        foreach ($quantities as $qty) {
            $targetQty = (int)($qty['available_adjustment'] ?? 0);
            $currentQty = (int)($qty['current_shopify_quantity'] ?? 0);
            $delta = $targetQty - $currentQty;

            if ($delta == 0) {
                $this->log("inventoryAdjustQuantitiesBatch - Skip delta=0 for " . ($qty['inventory_item_id'] ?? '?'), LOG_DEBUG);
                continue;
            }

            $convertedQuantities[] = [
                'inventoryItemId' => $qty['inventory_item_id'] ?? '',
                'locationId' => $qty['location_id'] ?? '',
                'delta' => $delta,
                // `changeFromQuantity` du chemin delta est compare a la quantite `available`
                // A LA LOCALISATION VISEE (cf. InventoryAdjustQuantitiesInput). `current_shopify_quantity`
                // vaut desormais cette valeur-la, resolue par
                // ImportProducts::fillInventoryReferenceQuantities() — et non plus
                // `ProductVariant.inventoryQuantity`, qui est un TOTAL toutes localisations et
                // faisait donc echouer toute boutique a plus d'un entrepot.
                'changeFromQuantity' => $currentQty
            ];
        }

        if (empty($convertedQuantities)) {
            $this->log("inventoryAdjustQuantitiesBatch - All deltas are 0, nothing to adjust", LOG_INFO);
            return (object)['data' => (object)['inventoryAdjustQuantities' => (object)['userErrors' => []]]];
        }

        $variables = [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'changes' => $convertedQuantities
            ],
            'idempotencyKey' => $idempotencyKey
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Exécute la mutation inventorySetOnHandQuantities (SET absolu, compare-and-swap) pour un
     * sous-lot d'items SANS current_shopify_quantity connue. Extrait de inventorySetQuantities()
     * (FIX v2.4.1) — isolé du sous-lot delta pour qu'un item fantôme ne fasse plus échouer les
     * items qui, eux, avaient une quantité courante fiable.
     *
     * @param array $quantities Sous-lot d'items sans current_shopify_quantity
     * @return object Réponse GraphQL brute
     * @since 2.4.1
     */
    private function inventorySetOnHandQuantitiesBatch(array $quantities)
    {
        $idempotencyKey = self::generateUuidV4();
        $this->log("ShopifyApi::inventorySetOnHandQuantitiesBatch - idempotencyKey: " . $idempotencyKey, LOG_DEBUG);

        $query = 'mutation inventorySetOnHandQuantities($input: InventorySetOnHandQuantitiesInput!, $idempotencyKey: String!) {
            inventorySetOnHandQuantities(input: $input) @idempotent(key: $idempotencyKey) {
                inventoryAdjustmentGroup {
                    id
                    changes {
                        name
                        delta
                        quantityAfterChange
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $convertedQuantities = [];
        foreach ($quantities as $qty) {
            if (!array_key_exists('on_hand_shopify_quantity', $qty)) {
                $this->log('ShopifyApi::inventorySetOnHandQuantitiesBatch - quantite on_hand INCONNUE pour '
                    . ($qty['inventory_item_id'] ?? '?') . ' : le compare-and-swap partira avec 0 et sera '
                    . 'refuse par Shopify si le stock enregistre n\'est pas nul.', LOG_WARNING);
            }
            $convertedQuantities[] = [
                'inventoryItemId' => $qty['inventory_item_id'] ?? '',
                'locationId' => $qty['location_id'] ?? '',
                'quantity' => (int)($qty['available_adjustment'] ?? 0),
                // `changeFromQuantity` est compare a la quantite ON_HAND. On emploie donc la
                // valeur lue sur cette grandeur (ImportProducts::fillMissingOnHandQuantities()),
                // JAMAIS `current_shopify_quantity`, qui porte la quantite `available`.
                //
                // A defaut, l'ancien comportement (0) est conserve — mais il est desormais
                // ANNONCE : il affirme a Shopify que le stock enregistre vaut zero, ce qui fait
                // refuser tout le lot des que c'est faux.
                'changeFromQuantity' => (int)($qty['on_hand_shopify_quantity'] ?? 0)
            ];
        }

        $variables = [
            'input' => [
                'reason' => 'correction',
                'referenceDocumentUri' => 'doli2shop://stock-sync/' . date('Y-m-d'),
                'setQuantities' => $convertedQuantities
            ],
            'idempotencyKey' => $idempotencyKey
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Fusionne les réponses des chemins delta (inventoryAdjustQuantities) et fallback
     * (inventorySetOnHandQuantities) en une réponse normalisée exploitable par l'appelant.
     *
     * FIX v2.4.1 : avant ce fix, le code appelant (ImportProducts::updateInventoryQuantities())
     * vérifiait `$response->data->inventorySetQuantities->userErrors` — une clé qui n'a JAMAIS
     * existé (le nom du champ GraphQL retourné est celui de la mutation réellement exécutée,
     * `inventoryAdjustQuantities` OU `inventorySetOnHandQuantities`). Ce check était donc
     * TOUJOURS faux, et les userErrors de Shopify (ex: compare-and-swap mismatch) n'étaient
     * jamais détectées ni loggées : d'où les stocks divergents « corrigés en silence ».
     *
     * FIX v2.4.1 (review 50-11, CRITICAL) : reçoit désormais aussi les items d'origine de chaque
     * sous-lot (`$withCurrentItems` / `$withoutCurrentItems`) pour pouvoir exposer, en plus de
     * `errors`/`userErrors`/`failedCount`, QUEL(S) sous-lot(s) GraphQL ont échoué
     * (`failedFields`) et la liste des items à re-tenter (`retryableQuantities` — UNIQUEMENT
     * les items des sous-lots en échec, jamais ceux d'un sous-lot réussi).
     *
     * @param object|null $deltaResponse Réponse brute du chemin delta (ou null si non exécuté)
     * @param object|null $fallbackResponse Réponse brute du chemin fallback (ou null si non exécuté)
     * @param array $withCurrentItems Items d'origine envoyés au chemin delta (pour retryableQuantities)
     * @param array $withoutCurrentItems Items d'origine envoyés au chemin fallback (pour retryableQuantities)
     * @return object Réponse agrégée : errors[], data->inventoryAdjustQuantities,
     *                data->inventorySetOnHandQuantities, userErrors[], failedCount,
     *                failedFields[], retryableQuantities[]
     * @since 2.4.1
     */
    private function aggregateInventoryResults($deltaResponse, $fallbackResponse, array $withCurrentItems = [], array $withoutCurrentItems = [])
    {
        $topErrors = [];
        $userErrors = [];
        $failedCount = 0;
        $failedFields = [];
        $retryableQuantities = [];

        $paths = [
            'inventoryAdjustQuantities' => ['response' => $deltaResponse, 'items' => $withCurrentItems],
            'inventorySetOnHandQuantities' => ['response' => $fallbackResponse, 'items' => $withoutCurrentItems],
        ];

        foreach ($paths as $field => $pathData) {
            $resp = $pathData['response'];
            if ($resp === null) {
                continue;
            }
            $pathFailed = false;
            if (!empty($resp->errors)) {
                foreach ((array)$resp->errors as $err) {
                    $topErrors[] = $err;
                }
                $pathFailed = true;
            }
            if (isset($resp->data->$field->userErrors) && !empty($resp->data->$field->userErrors)) {
                foreach ($resp->data->$field->userErrors as $err) {
                    $userErrors[] = $err;
                    $failedCount++;
                }
                $pathFailed = true;
            }
            if ($pathFailed) {
                $failedFields[] = $field;
                $retryableQuantities = array_merge($retryableQuantities, $pathData['items']);
            }
        }

        return (object)[
            'errors' => $topErrors,
            'data' => (object)[
                'inventoryAdjustQuantities' => $deltaResponse->data->inventoryAdjustQuantities ?? null,
                'inventorySetOnHandQuantities' => $fallbackResponse->data->inventorySetOnHandQuantities ?? null,
            ],
            'userErrors' => $userErrors,
            'failedCount' => $failedCount,
            'failedFields' => $failedFields,
            'retryableQuantities' => $retryableQuantities,
        ];
    }

    /**
     * Generate a RFC 4122 compliant UUID v4
     *
     * Compatible PHP 7.2.5+ (uses native random_bytes, no external dependency).
     * Used for Shopify @idempotent directive keys.
     *
     * @return string UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * @since 2.2.0
     */
    private static function generateUuidV4()
    {
        $data = random_bytes(16);
        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to 10xx (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Enrich variant search result with matchType for type mismatch detection (Story 23.9)
     *
     * Determines if the Shopify product is 'simple' (1 variant "Default Title") or 'variant' (parent with real variants).
     *
     * @param object $variantNode Variant node from GraphQL response
     * @return object Enriched result with ->product, ->matchType, ->variantCount
     * @since 2.2.0
     */
    private function enrichVariantResultWithMatchType($variantNode)
    {
        // Conserver toutes les propriétés originales du variant node (id, sku, legacyResourceId)
        $result = clone $variantNode;

        $variantEdges = $variantNode->product->variants->edges ?? [];
        $variantCount = count($variantEdges);
        $isShopifySimple = ($variantCount <= 1 &&
            (!isset($variantEdges[0]) || ($variantEdges[0]->node->title ?? '') === 'Default Title'));

        $result->matchType = $isShopifySimple ? 'simple' : 'variant';
        $result->variantCount = $variantCount;

        $this->log("enrichVariantResultWithMatchType - matchType=" . $result->matchType
            . " variantCount=" . $variantCount . " for product " . ($variantNode->product->title ?? ''), LOG_DEBUG);

        return $result;
    }

    /**
     * Enrich a parent/title/handle search result with matchType = 'parent' (Story 23.9)
     *
     * @param object $productNode Node with ->product property
     * @return object Same node with ->matchType = 'parent' added
     * @since 2.2.0
     */
    private function enrichParentResultWithMatchType($productNode)
    {
        $productNode->matchType = 'parent';
        $productNode->variantCount = null; // Unknown for parent searches
        return $productNode;
    }

    /**
     * Delete a product from Shopify (v2.2.0 Story 23.9)
     *
     * Used for type mismatch resolution: when a Dolibarr parent needs to replace
     * a Shopify simple product, the simple is deleted first.
     *
     * @param string $shopifyProductId Shopify product legacy ID (numeric)
     * @return bool True if deletion succeeded, false on any error
     * @since 2.2.0
     */
    public function deleteProduct($shopifyProductId)
    {
        $this->log("deleteProduct - Deleting Shopify product: " . $shopifyProductId, LOG_WARNING);

        $query = 'mutation productDelete($input: ProductDeleteInput!) {
            productDelete(input: $input) {
                deletedProductId
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'input' => [
                'id' => 'gid://shopify/Product/' . $shopifyProductId
            ]
        ];

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            // CRITICAL (Story 58-5, review) : executeGraphQL() ne lève JAMAIS sur une erreur
            // GraphQL de premier niveau ({"errors":[...]}, ex. scope révoqué, ID malformé, ou
            // throttle après épuisement des retries) — elle retourne le corps décodé tel quel.
            // Sans cette vérification explicite, isset($response->data->productDelete->userErrors)
            // vaut FALSE quand $response->data est absent/null, et le code tombait sur
            // "return true" : succès annoncé alors que RIEN n'a été fait côté Shopify.
            if (isset($response->errors) && !empty($response->errors)) {
                $errorsList = is_array($response->errors) ? $response->errors : [$response->errors];
                if ($this->isProductAlreadyGoneError($errorsList)) {
                    $this->log("deleteProduct - Produit déjà absent côté Shopify (traité comme succès idempotent): " . json_encode($response->errors), LOG_INFO);
                    return true;
                }
                $this->log("deleteProduct - GraphQL top-level error: " . json_encode($response->errors), LOG_ERR);
                return false;
            }

            // Réponse sans erreur top-level MAIS sans preuve de succès non plus (data absent/null) :
            // ne jamais conclure au succès par défaut (c'était le bug CRITICAL corrigé ici).
            if (!isset($response->data->productDelete)) {
                $this->log("deleteProduct - Réponse Shopify inexploitable (pas de data.productDelete): " . json_encode($response), LOG_ERR);
                return false;
            }

            if (isset($response->data->productDelete->userErrors)
                && !empty($response->data->productDelete->userErrors)) {
                $errors = $response->data->productDelete->userErrors;
                // MEDIUM (Story 58-5, review) : un produit déjà supprimé à la main côté Shopify est
                // un succès IDEMPOTENT — l'objectif (produit non vendable) est déjà atteint, il ne
                // doit pas échouer indéfiniment.
                if ($this->isProductAlreadyGoneError($errors)) {
                    $this->log("deleteProduct - Produit déjà absent côté Shopify (traité comme succès idempotent): " . json_encode($errors), LOG_INFO);
                    return true;
                }
                $this->log("deleteProduct - Failed: " . json_encode($errors), LOG_ERR);
                return false;
            }

            $this->log("deleteProduct - Success: deleted " . ($response->data->productDelete->deletedProductId ?? ''), LOG_WARNING);
            return true;
        } catch (Exception $e) {
            $this->log("deleteProduct - Exception: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Détecte si une liste d'erreurs GraphQL (userErrors ou erreurs top-level) signale que le
     * produit Shopify ciblé n'existe déjà plus — traité comme un SUCCÈS IDEMPOTENT par
     * archiveProduct()/deleteProduct() (MEDIUM, Story 58-5 review) : l'objectif (produit non
     * vendable/supprimé) est déjà atteint, un produit retiré à la main côté Shopify entre deux
     * cycles ne doit pas échouer indéfiniment et bloquer le pool de candidats.
     *
     * @param array $errors Liste d'erreurs (objets/tableaux avec ->message ou ['message'], ou chaînes)
     * @return bool
     * @since 2.5.0
     */
    private function isProductAlreadyGoneError($errors)
    {
        foreach ((array) $errors as $error) {
            if (is_object($error) && isset($error->message)) {
                $message = (string) $error->message;
            } elseif (is_array($error) && isset($error['message'])) {
                $message = (string) $error['message'];
            } elseif (is_string($error)) {
                $message = $error;
            } else {
                continue;
            }

            if ($message !== '' && stripos($message, 'does not exist') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Archive a product on Shopify (Story 58-5, AC7) — sets status=ARCHIVED, nothing else.
     *
     * Utilisé par OffSaleProductsService pour traiter les produits déjà poussés qui sont hors
     * vente dans Dolibarr, quand le client choisit explicitement "archiver" plutôt que "laisser
     * en brouillon"/"supprimer".
     *
     * Mutation `productUpdate` MINIMALE (aucune n'existait déjà dans ce fichier, vérifié par
     * grep sur `mutation product`). Volontairement PAS `productSet()`/`prepareProductData()` :
     * ces méthodes recalculent tout le contenu du produit (titre, options, variantes...) et
     * écraseraient potentiellement des données non liées à l'archivage — l'archivage doit être
     * une opération ISOLÉE, statut uniquement.
     *
     * @param string $shopifyProductId Shopify product legacy ID (numeric, sans préfixe gid://)
     * @return bool True si l'archivage a réussi, false sur toute erreur
     * @since 2.5.0
     */
    public function archiveProduct($shopifyProductId)
    {
        $this->log("archiveProduct - Archiving Shopify product: " . $shopifyProductId, LOG_WARNING);

        $query = 'mutation productUpdate($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    status
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'input' => [
                'id' => 'gid://shopify/Product/' . $shopifyProductId,
                'status' => 'ARCHIVED'
            ]
        ];

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            // CRITICAL (Story 58-5, review) : executeGraphQL() ne lève JAMAIS sur une erreur
            // GraphQL de premier niveau ({"errors":[...]}, ex. scope révoqué, ID malformé, ou
            // throttle après épuisement des retries) — elle retourne le corps décodé tel quel.
            // Sans cette vérification explicite, isset($response->data->productUpdate->userErrors)
            // vaut FALSE quand $response->data est absent/null, et le code tombait sur
            // "return true" : succès annoncé alors que le produit restait ACTIF et achetable.
            if (isset($response->errors) && !empty($response->errors)) {
                $errorsList = is_array($response->errors) ? $response->errors : [$response->errors];
                if ($this->isProductAlreadyGoneError($errorsList)) {
                    $this->log("archiveProduct - Produit déjà absent côté Shopify (traité comme succès idempotent): " . json_encode($response->errors), LOG_INFO);
                    return true;
                }
                $this->log("archiveProduct - GraphQL top-level error: " . json_encode($response->errors), LOG_ERR);
                return false;
            }

            // Réponse sans erreur top-level MAIS sans preuve de succès non plus (data absent/null) :
            // ne jamais conclure au succès par défaut (c'était le bug CRITICAL corrigé ici).
            if (!isset($response->data->productUpdate)) {
                $this->log("archiveProduct - Réponse Shopify inexploitable (pas de data.productUpdate): " . json_encode($response), LOG_ERR);
                return false;
            }

            if (isset($response->data->productUpdate->userErrors)
                && !empty($response->data->productUpdate->userErrors)) {
                $errors = $response->data->productUpdate->userErrors;
                // MEDIUM (Story 58-5, review) : un produit déjà supprimé à la main côté Shopify est
                // un succès IDEMPOTENT — l'objectif (produit non vendable) est déjà atteint.
                if ($this->isProductAlreadyGoneError($errors)) {
                    $this->log("archiveProduct - Produit déjà absent côté Shopify (traité comme succès idempotent): " . json_encode($errors), LOG_INFO);
                    return true;
                }
                $this->log("archiveProduct - Failed: " . json_encode($errors), LOG_ERR);
                return false;
            }

            $this->log("archiveProduct - Success: archived " . $shopifyProductId, LOG_WARNING);
            return true;
        } catch (Exception $e) {
            $this->log("archiveProduct - Exception: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Find product by SKU in Shopify
     *
     * @param string $sku Product SKU (parent ref for parent products, variant SKU otherwise)
     * @param bool $isVariantParent Whether this is a parent product with variants
     * @param array $variantSkus (v2.2.2) For parent products, list of exact child variant SKUs (Dolibarr refs)
     *                           to enable Strategy P0: direct search via known variant SKUs.
     *                           Prevents duplicates after mapping purge when parent ref differs from variant SKUs.
     * @return object|null Product data if found
     */
    public function findProductBySku($sku, $isVariantParent = false, $variantSkus = [])
    {
        // FIX: Issue v2.0.23 Session 10 - CRITICAL: Parent products in Shopify don't have SKU, only variants do!
        $this->log("Starting findProductBySku for SKU: " . $sku . ", isVariantParent: " . ($isVariantParent ? 'true' : 'false') . ", variantSkus count: " . count($variantSkus), LOG_INFO);

        if ($isVariantParent) {
            // For parent products: Search via VARIANTS (most reliable) since Shopify parent products don't have SKU
            $this->log("PARENT PRODUCT SEARCH: Shopify parent products don't have SKU, searching via variants (most reliable)", LOG_INFO);

            // v2.2.2 Strategy P0: BEST PRIORITY - Search parent through KNOWN exact variant SKUs (child refs)
            // This strategy uses the actual variant SKUs from Dolibarr (not the parent ref), which is exactly
            // what was sent to Shopify at creation time. Critical after mapping purge where other strategies
            // fail because they try patterns derived from the parent ref.
            if (!empty($variantSkus)) {
                $this->log("Strategy P0: BEST PRIORITY - Searching parent through " . count($variantSkus) . " known exact variant SKU(s)", LOG_DEBUG);
                $result = $this->searchParentByExactVariantSkus($variantSkus);
                if ($result !== null) {
                    $this->log("Strategy P0 SUCCESS: found parent via exact variant SKU match", LOG_INFO);
                    return $result;
                }
                $this->log("Strategy P0: no parent found through " . count($variantSkus) . " known variant SKU(s)", LOG_DEBUG);
            }

            // Strategy P1: PRIORITY - Find parent through its variants (most reliable)
            $this->log("Strategy P1: PRIORITY - Searching parent through variant SKUs (most reliable)", LOG_DEBUG);
            $result = $this->searchParentThroughVariantSku($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found parent product via Strategy P1 (through variant SKU - RELIABLE)", LOG_INFO);
                return $result;
            }

            // Strategy P2: Enhanced variant-based parent search with database mapping
            $this->log("Strategy P2: Enhanced variant-based parent search using known patterns", LOG_DEBUG);
            $result = $this->searchParentViaVariantPatterns($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found parent product via Strategy P2 (variant patterns)", LOG_INFO);
                return $result;
            }

            // Strategy P3: FALLBACK - Search parent product by handle (more stable than title)
            $this->log("Strategy P3: FALLBACK - Searching parent products by handle match", LOG_DEBUG);
            $result = $this->searchProductByHandle($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found parent product via Strategy P3 (handle match)", LOG_INFO);
                return $result;
            }

            // Strategy P4: LAST RESORT - Search parent product by title (least reliable)
            $this->log("Strategy P4: LAST RESORT - Searching parent products by title (WARNING: titles may have changed!)", LOG_WARNING);
            $result = $this->searchProductByTitle($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found parent product via Strategy P4 (title match - verify manually!)", LOG_WARNING);
                return $result;
            }
        } else {
            // For individual variants: Search by SKU (normal behavior)
            $this->log("VARIANT SEARCH: Searching individual variant by SKU", LOG_INFO);
            
            // Strategy V1: Direct SKU search on productVariants
            $this->log("Strategy V1: Searching productVariants by exact SKU match", LOG_DEBUG);
            $result = $this->searchProductVariantBySku($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found variant via Strategy V1 (exact SKU match)", LOG_INFO);
                return $result;
            }

            // Strategy V2: Enhanced variant search with wildcard patterns
            $this->log("Strategy V2: Enhanced variant search with wildcard patterns", LOG_DEBUG);
            $result = $this->searchProductVariantWithWildcard($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found variant via Strategy V2 (wildcard variant search)", LOG_INFO);
                return $result;
            }

            // Strategy V3: Fallback with partial SKU search on variants
            $this->log("Strategy V3: Fallback search with partial SKU matching on variants", LOG_DEBUG);
            $result = $this->searchProductVariantByPartialSku($sku);
            if ($result !== null) {
                $this->log("SUCCESS: Found variant via Strategy V3 (partial SKU match)", LOG_INFO);
                return $result;
            }
        }

        // Strategy DIAG: Diagnostic search for troubleshooting
        $this->log("Strategy DIAG: Diagnostic search for troubleshooting", LOG_DEBUG);
        $result = $this->diagnosticProductSearch($sku);
        if ($result !== null) {
            $this->log("SUCCESS: Found product via Strategy DIAG (diagnostic search)", LOG_INFO);
            return $result;
        }

        $this->log("FAILURE: No product found for SKU " . $sku . " after trying all strategies", LOG_WARNING);
        return null;
    }

    /**
     * Strategy 1: Search productVariants by exact SKU match
     *
     * @param string $sku Product SKU to search for
     * @return object|null Product variant node or null
     */
    private function searchProductVariantBySku($sku)
    {
        // v2.2.0 Story 23.9: Added variants(first:2) to detect simple vs parent product
        $query = '
        query findProductVariantBySku($query: String!) {
            productVariants(first: 5, query: $query) {
                edges {
                    node {
                        id
                        legacyResourceId
                        sku
                        product {
                            id
                            legacyResourceId
                            title
                            handle
                            variants(first: 2) {
                                edges {
                                    node {
                                        title
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }';

        $variables = [
            'query' => 'sku:' . $sku
        ];

        $this->log("Executing exact SKU search with query: " . $variables['query'], LOG_DEBUG);

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (!empty($response->data->productVariants->edges)) {
                $this->log("Found " . count($response->data->productVariants->edges) . " variant(s) matching SKU", LOG_DEBUG);

                $matchedNode = null;

                // Find exact match (in case of partial matches)
                foreach ($response->data->productVariants->edges as $edge) {
                    if ($edge->node->sku === $sku) {
                        $this->log("Exact SKU match found: " . $edge->node->sku, LOG_DEBUG);
                        $matchedNode = $edge->node;
                        break;
                    }
                }

                // If no exact match, return first result
                if ($matchedNode === null) {
                    $this->log("No exact match, returning first result", LOG_DEBUG);
                    $matchedNode = $response->data->productVariants->edges[0]->node;
                }

                // Story 23.9: Enrich with matchType for type mismatch detection
                return $this->enrichVariantResultWithMatchType($matchedNode);
            }
        } catch (Exception $e) {
            $this->log("Error in exact SKU search: " . $e->getMessage(), LOG_WARNING);
        }

        return null;
    }

    /**
     * Strategy 2: Search products by exact title match
     *
     * @param string $title Product title to search for
     * @return object|null Product node or null
     */
    private function searchProductByTitle($title)
    {
        $query = '
        query findProductByTitle($query: String!) {
            products(first: 3, query: $query) {
                edges {
                    node {
                        id
                        legacyResourceId
                        title
                        handle
                    }
                }
            }
        }';

        // FIX: v2.0.23 Session 10 - Return to simple syntax that worked in v2.0.21
        $variables = [
            'query' => $title  // Simple title search without field prefix (like v2.0.21)
        ];

        $this->log("Executing exact title search with query: " . $variables['query'], LOG_DEBUG);

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (!empty($response->data->products->edges)) {
                $this->log("Found " . count($response->data->products->edges) . " product(s) matching title", LOG_DEBUG);

                // Find exact match
                foreach ($response->data->products->edges as $edge) {
                    if ($edge->node->title === $title) {
                        $this->log("Exact title match found: " . $edge->node->title, LOG_DEBUG);

                        // Create a node in the same format as variant search for consistency
                        $productNode = new stdClass();
                        $productNode->product = $edge->node;
                        return $this->enrichParentResultWithMatchType($productNode);
                    }
                }

                // If no exact match, return first result
                $this->log("No exact title match, returning first result", LOG_DEBUG);
                $productNode = new stdClass();
                $productNode->product = $response->data->products->edges[0]->node;
                return $this->enrichParentResultWithMatchType($productNode);
            }
        } catch (Exception $e) {
            $this->log("Error in exact title search: " . $e->getMessage(), LOG_WARNING);
        }

        return null;
    }

    /**
     * Strategy 2: Enhanced variant search with wildcard patterns
     *
     * @param string $sku Product SKU to search for
     * @return object|null Product variant node or null
     */
    private function searchProductVariantWithWildcard($sku)
    {
        // Try different wildcard patterns for variant search
        $searchPatterns = [
            'sku:*' . $sku . '*',  // Contains the SKU
            'sku:' . $sku . '*',   // Starts with SKU
            'sku:*' . $sku,        // Ends with SKU
        ];

        foreach ($searchPatterns as $pattern) {
            $this->log("Trying wildcard pattern: " . $pattern, LOG_DEBUG);
            
            // v2.2.0 Story 23.9: Added variants(first:2) for type mismatch detection
            $query = '
            query findProductVariantWithWildcard($query: String!) {
                productVariants(first: 10, query: $query) {
                    edges {
                        node {
                            id
                            legacyResourceId
                            sku
                            product {
                                id
                                legacyResourceId
                                title
                                handle
                                variants(first: 2) {
                                    edges {
                                        node {
                                            title
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }';

            $variables = ['query' => $pattern];

            try {
                $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                if (!empty($response->data->productVariants->edges)) {
                    $this->log("Found " . count($response->data->productVariants->edges) . " variant(s) with pattern: " . $pattern, LOG_DEBUG);

                    $matchedNode = null;

                    // Prefer exact matches first
                    foreach ($response->data->productVariants->edges as $edge) {
                        if ($edge->node->sku === $sku) {
                            $this->log("Exact SKU match found with wildcard: " . $edge->node->sku, LOG_DEBUG);
                            $matchedNode = $edge->node;
                            break;
                        }
                    }

                    if ($matchedNode === null) {
                        $matchedNode = $response->data->productVariants->edges[0]->node;
                        $this->log("Returning first wildcard match: " . $matchedNode->sku, LOG_DEBUG);
                    }

                    return $this->enrichVariantResultWithMatchType($matchedNode);
                }
            } catch (Exception $e) {
                $this->log("Error in wildcard search with pattern " . $pattern . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        return null;
    }

    /**
     * Strategy 4: Search productVariants by partial SKU match (fallback)
     *
     * @param string $sku Product SKU to search for
     * @return object|null Product variant node or null
     */
    private function searchProductVariantByPartialSku($sku)
    {
        // v2.2.0 Story 23.9: Added variants(first:2) for type mismatch detection
        $query = '
        query findProductVariantByPartialSku($query: String!) {
            productVariants(first: 10, query: $query) {
                edges {
                    node {
                        id
                        legacyResourceId
                        sku
                        product {
                            id
                            legacyResourceId
                            title
                            handle
                            variants(first: 2) {
                                edges {
                                    node {
                                        title
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }';

        $variables = [
            'query' => 'sku:' . $sku
        ];

        $this->log("Executing partial SKU search with query: " . $variables['query'], LOG_DEBUG);

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (!empty($response->data->productVariants->edges)) {
                $this->log("Found " . count($response->data->productVariants->edges) . " variant(s) in partial search", LOG_DEBUG);

                $matchedNode = null;

                // Prefer exact matches first
                foreach ($response->data->productVariants->edges as $edge) {
                    if ($edge->node->sku === $sku) {
                        $this->log("Exact SKU match found in partial search: " . $edge->node->sku, LOG_DEBUG);
                        $matchedNode = $edge->node;
                        break;
                    }
                }

                if ($matchedNode === null) {
                    $matchedNode = $response->data->productVariants->edges[0]->node;
                    $this->log("Returning partial match: " . $matchedNode->sku, LOG_DEBUG);
                }

                return $this->enrichVariantResultWithMatchType($matchedNode);
            }
        } catch (Exception $e) {
            $this->log("Error in partial SKU search: " . $e->getMessage(), LOG_WARNING);
        }

        return null;
    }

    /**
     * Strategy 4: Search products by partial title match (last resort)
     *
     * @param string $title Product title to search for
     * @return object|null Product node or null
     */
    private function searchProductByPartialTitle($title)
    {
        $query = '
        query findProductByPartialTitle($query: String!) {
            products(first: 10, query: $query) {
                edges {
                    node {
                        id
                        legacyResourceId
                        title
                        handle
                    }
                }
            }
        }';

        // Last resort: partial title search without quotes
        $variables = [
            'query' => 'title:' . $title
        ];

        $this->log("Executing partial title search with query: " . $variables['query'], LOG_DEBUG);

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (!empty($response->data->products->edges)) {
                $this->log("Found " . count($response->data->products->edges) . " product(s) in partial title search", LOG_DEBUG);

                // Prefer exact matches first
                foreach ($response->data->products->edges as $edge) {
                    if ($edge->node->title === $title) {
                        $this->log("Exact title match found in partial search: " . $edge->node->title, LOG_DEBUG);

                        $productNode = new stdClass();
                        $productNode->product = $edge->node;
                        return $this->enrichParentResultWithMatchType($productNode);
                    }
                }

                // Return first partial match
                $firstMatch = $response->data->products->edges[0]->node;
                $this->log("Returning partial title match: " . $firstMatch->title, LOG_DEBUG);

                $productNode = new stdClass();
                $productNode->product = $firstMatch;
                return $this->enrichParentResultWithMatchType($productNode);
            }
        } catch (Exception $e) {
            $this->log("Error in partial title search: " . $e->getMessage(), LOG_WARNING);
        }

        return null;
    }

    /**
     * Strategy 5: Search variants through parent product lookup
     * This strategy attempts to find the variant by searching for parent products
     * and then checking their variants
     *
     * @param string $sku Product SKU to search for
     * @return object|null Product variant node or null
     */
    private function searchVariantThroughParentProduct($sku)
    {
        // Try to find parent products that might contain this variant
        $parentSearchPatterns = [
            substr($sku, 0, strrpos($sku, '_')), // Remove suffix after last underscore (DEVIDOIR_6 -> DEVIDOIR)
            preg_replace('/[_-]\d+$/', '', $sku), // Remove trailing underscore/dash + numbers
            preg_replace('/[_-][^_-]+$/', '', $sku), // Remove last segment after underscore/dash
        ];

        $parentSearchPatterns = array_unique(array_filter($parentSearchPatterns));

        foreach ($parentSearchPatterns as $parentPattern) {
            if (empty($parentPattern) || $parentPattern === $sku) continue;
            
            $this->log("Searching for parent product with pattern: " . $parentPattern, LOG_DEBUG);
            
            $query = '
            query findProductWithVariants($query: String!) {
                products(first: 5, query: $query) {
                    edges {
                        node {
                            id
                            legacyResourceId
                            title
                            handle
                            variants(first: 50) {
                                edges {
                                    node {
                                        id
                                        legacyResourceId
                                        sku
                                        product {
                                            id
                                            legacyResourceId
                                            title
                                            handle
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }';

            // Try both title and general search
            $searchQueries = [
                'title:"' . $parentPattern . '"',  // Exact title match
                'title:' . $parentPattern,         // Partial title match
                $parentPattern                      // General search
            ];

            foreach ($searchQueries as $searchQuery) {
                try {
                    $variables = ['query' => $searchQuery];
                    $this->log("Executing parent product search with query: " . $searchQuery, LOG_DEBUG);
                    
                    $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                    if (!empty($response->data->products->edges)) {
                        $this->log("Found " . count($response->data->products->edges) . " parent product(s) with query: " . $searchQuery, LOG_DEBUG);

                        // Check variants in each found product
                        foreach ($response->data->products->edges as $productEdge) {
                            $product = $productEdge->node;
                            
                            if (!empty($product->variants->edges)) {
                                foreach ($product->variants->edges as $variantEdge) {
                                    $variant = $variantEdge->node;
                                    
                                    if ($variant->sku === $sku) {
                                        $this->log("Found exact variant match through parent product: " . $product->title . " -> variant SKU: " . $variant->sku, LOG_INFO);
                                        return $variant;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->log("Error in parent product search with query " . $searchQuery . ": " . $e->getMessage(), LOG_WARNING);
                }
            }
        }

        return null;
    }

    /**
     * Strategy P2: Search products by handle (URL-friendly identifier)
     *
     * @param string $handle Product handle to search for
     * @return object|null Product node or null
     */
    private function searchProductByHandle($handle)
    {
        // Convert SKU to potential handle format (lowercase, replace _ with -)
        $potentialHandles = [
            strtolower(str_replace('_', '-', $handle)),  // DEVIDOIR_6 -> devidoir-6
            strtolower($handle),                         // DEVIDOIR_6 -> devidoir_6
            str_replace('_', '-', $handle),              // DEVIDOIR_6 -> DEVIDOIR-6
        ];

        foreach ($potentialHandles as $handleToTry) {
            $query = '
            query findProductByHandle($query: String!) {
                products(first: 3, query: $query) {
                    edges {
                        node {
                            id
                            legacyResourceId
                            title
                            handle
                        }
                    }
                }
            }';

            $variables = ['query' => 'handle:' . $handleToTry];

            $this->log("Executing handle search with: " . $handleToTry, LOG_DEBUG);

            try {
                $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                if (!empty($response->data->products->edges)) {
                    $this->log("Found " . count($response->data->products->edges) . " product(s) with handle: " . $handleToTry, LOG_DEBUG);

                    // Return first match for handle search
                    $firstMatch = $response->data->products->edges[0]->node;
                    $this->log("Handle match found: " . $firstMatch->title . " (handle: " . $firstMatch->handle . ")", LOG_DEBUG);

                    $productNode = new stdClass();
                    $productNode->product = $firstMatch;
                    return $this->enrichParentResultWithMatchType($productNode);
                }
            } catch (Exception $e) {
                $this->log("Error in handle search with " . $handleToTry . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        return null;
    }

    /**
     * Strategy P0 (v2.2.2): Search parent product through KNOWN exact variant SKUs
     *
     * This is the MOST RELIABLE method for parent products after mapping purge.
     * It uses the actual child variant SKUs from Dolibarr (which were sent to Shopify at creation time),
     * avoiding the pattern-based guessing of Strategy P1/P2 that fails when the parent ref
     * does not share a prefix with the variant SKUs (ex: parent "CABIN" with variants "42285", "43466").
     *
     * @param array $variantSkus List of exact variant SKUs (Dolibarr child refs)
     * @return object|null Product node or null
     * @since 2.2.2
     */
    private function searchParentByExactVariantSkus($variantSkus)
    {
        if (empty($variantSkus)) {
            return null;
        }

        // Limit to first 20 SKUs to avoid GraphQL query size limits
        $variantSkus = array_slice(array_values(array_filter($variantSkus, function ($s) {
            return is_string($s) && trim($s) !== '';
        })), 0, 20);

        if (empty($variantSkus)) {
            return null;
        }

        $query = '
        query findParentByExactVariantSkus($query: String!) {
            productVariants(first: 10, query: $query) {
                edges {
                    node {
                        id
                        legacyResourceId
                        sku
                        product {
                            id
                            legacyResourceId
                            title
                            handle
                        }
                    }
                }
            }
        }';

        // Try each variant SKU individually first (most precise match)
        foreach ($variantSkus as $variantSku) {
            $escapedSku = addslashes($variantSku);
            $this->log("Strategy P0: searching Shopify variant with exact SKU: " . $variantSku, LOG_DEBUG);

            try {
                $variables = ['query' => 'sku:"' . $escapedSku . '"'];
                $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                if (!empty($response->data->productVariants->edges)) {
                    $firstVariant = $response->data->productVariants->edges[0]->node;
                    $parentProduct = $firstVariant->product;

                    $this->log("Strategy P0 SUCCESS: found parent '" . $parentProduct->title
                        . "' via exact variant SKU: " . $firstVariant->sku, LOG_INFO);

                    $productNode = new stdClass();
                    $productNode->product = $parentProduct;
                    return $this->enrichParentResultWithMatchType($productNode);
                }
            } catch (Exception $e) {
                $this->log("Strategy P0: error searching variant SKU '" . $variantSku . "': " . $e->getMessage(), LOG_WARNING);
            }
        }

        return null;
    }

    /**
     * Strategy P1: Search parent product through variant SKU patterns
     * This tries to find the parent by assuming the SKU might be related to a variant
     * This is the MOST RELIABLE method for parent products
     *
     * @param string $sku Parent product SKU/reference
     * @return object|null Product node or null
     */
    private function searchParentThroughVariantSku($sku)
    {
        // Generate potential variant SKU patterns based on parent SKU
        $variantPatterns = [
            $sku . '_*',        // DEVIDOIR_6_* (variants of DEVIDOIR_6)
            $sku . '-*',        // DEVIDOIR_6-* (alternative separator)
            $sku . '*',         // DEVIDOIR_6* (any suffix)
        ];

        foreach ($variantPatterns as $pattern) {
            $this->log("Searching for variants with pattern: " . $pattern, LOG_DEBUG);

            $query = '
            query findParentThroughVariants($query: String!) {
                productVariants(first: 10, query: $query) {
                    edges {
                        node {
                            id
                            legacyResourceId
                            sku
                            product {
                                id
                                legacyResourceId
                                title
                                handle
                            }
                        }
                    }
                }
            }';

            try {
                $variables = ['query' => 'sku:' . $pattern];
                $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                if (!empty($response->data->productVariants->edges)) {
                    $this->log("Found " . count($response->data->productVariants->edges) . " variant(s) with pattern: " . $pattern, LOG_DEBUG);

                    // Return the parent product of the first variant found
                    $firstVariant = $response->data->productVariants->edges[0]->node;
                    $parentProduct = $firstVariant->product;
                    
                    $this->log("Found parent through variant: " . $parentProduct->title . " (via variant SKU: " . $firstVariant->sku . ")", LOG_INFO);

                    $productNode = new stdClass();
                    $productNode->product = $parentProduct;
                    return $this->enrichParentResultWithMatchType($productNode);
                }
            } catch (Exception $e) {
                $this->log("Error in parent search through variants with pattern " . $pattern . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        return null;
    }

    /**
     * Strategy P2: Enhanced variant-based parent search with multiple patterns
     * Uses more sophisticated variant SKU detection patterns
     *
     * @param string $sku Parent product SKU/reference
     * @return object|null Product node or null
     */
    private function searchParentViaVariantPatterns($sku)
    {
        // More sophisticated variant patterns
        $enhancedPatterns = [
            // Common variant naming conventions
            $sku . '_V*',       // DEVIDOIR_6_V1, DEVIDOIR_6_V2
            $sku . '_*M',       // DEVIDOIR_6_6M, DEVIDOIR_6_12M (size variants)
            $sku . '_*CM',      // DEVIDOIR_6_50CM, DEVIDOIR_6_100CM (length variants)
            $sku . '_*MM',      // DEVIDOIR_6_10MM, DEVIDOIR_6_20MM (diameter variants)
            $sku . '_R*',       // DEVIDOIR_6_ROUGE, DEVIDOIR_6_BLEU (color variants)
            $sku . '_*',        // Any suffix with underscore
            $sku . '.*',        // Any suffix with dot
        ];

        foreach ($enhancedPatterns as $pattern) {
            $this->log("Enhanced search for variants with pattern: " . $pattern, LOG_DEBUG);

            $query = '
            query findParentViaEnhancedVariants($query: String!) {
                productVariants(first: 15, query: $query) {
                    edges {
                        node {
                            id
                            legacyResourceId
                            sku
                            displayName
                            product {
                                id
                                legacyResourceId
                                title
                                handle
                                status
                            }
                        }
                    }
                }
            }';

            try {
                $variables = ['query' => 'sku:' . $pattern];
                $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                if (!empty($response->data->productVariants->edges)) {
                    $this->log("Enhanced search found " . count($response->data->productVariants->edges) . " variant(s) with pattern: " . $pattern, LOG_DEBUG);

                    // Check each variant to find one that best matches our parent SKU
                    foreach ($response->data->productVariants->edges as $variantEdge) {
                        $variant = $variantEdge->node;
                        $parentProduct = $variant->product;
                        
                        // Log detailed information for analysis
                        $this->log("Enhanced search - variant SKU: " . $variant->sku . " in product: " . $parentProduct->title, LOG_DEBUG);
                        
                        // Return the parent product of the best match
                        $this->log("Enhanced search found parent: " . $parentProduct->title . " (via variant SKU: " . $variant->sku . ")", LOG_INFO);

                        $productNode = new stdClass();
                        $productNode->product = $parentProduct;
                        return $this->enrichParentResultWithMatchType($productNode);
                    }
                }
            } catch (Exception $e) {
                $this->log("Error in enhanced parent search with pattern " . $pattern . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        return null;
    }

    /**
     * Strategy 7: Diagnostic search to troubleshoot missing products
     * This method performs broader searches to understand why a product isn't found
     *
     * @param string $sku Product SKU to search for
     * @return object|null Product variant node or null
     */
    private function diagnosticProductSearch($sku)
    {
        $this->log("=== DIAGNOSTIC SEARCH FOR: " . $sku . " ===", LOG_INFO);

        // 1. Search for products containing any part of the SKU (very broad)
        $broadSearchQueries = [
            $sku,                           // Plain search without field specification
            'title:' . $sku,               // Title contains
            'sku:' . $sku,                 // SKU contains
            substr($sku, 0, 5) . '*',      // First 5 chars + wildcard
            '*' . substr($sku, -5),        // Wildcard + last 5 chars
        ];

        foreach ($broadSearchQueries as $searchQuery) {
            $this->log("Diagnostic: trying broad search with query: " . $searchQuery, LOG_DEBUG);
            
            $query = '
            query diagnosticProductSearch($query: String!) {
                products(first: 10, query: $query) {
                    edges {
                        node {
                            id
                            legacyResourceId
                            title
                            handle
                            variants(first: 10) {
                                edges {
                                    node {
                                        id
                                        legacyResourceId
                                        sku
                                    }
                                }
                            }
                        }
                    }
                }
            }';

            try {
                $variables = ['query' => $searchQuery];
                $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

                if (!empty($response->data->products->edges)) {
                    $this->log("Diagnostic found " . count($response->data->products->edges) . " product(s) with query: " . $searchQuery, LOG_INFO);

                    foreach ($response->data->products->edges as $productEdge) {
                        $product = $productEdge->node;
                        $this->log("Diagnostic found product: " . $product->title . " (ID: " . $product->id . ")", LOG_INFO);
                        
                        // Check if product title or handle matches our SKU
                        if ($product->title === $sku || $product->handle === $sku) {
                            $this->log("Diagnostic: Found exact match by title/handle!", LOG_INFO);
                            $productNode = new stdClass();
                            $productNode->product = $product;
                            return $this->enrichParentResultWithMatchType($productNode);
                        }

                        // Check variants in found products
                        if (!empty($product->variants->edges)) {
                            foreach ($product->variants->edges as $variantEdge) {
                                $variant = $variantEdge->node;
                                $this->log("Diagnostic found variant: SKU=" . $variant->sku . " (ID: " . $variant->id . ")", LOG_DEBUG);
                                
                                if ($variant->sku === $sku) {
                                    $this->log("Diagnostic: Found exact variant match!", LOG_INFO);
                                    return $variant;
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $this->log("Diagnostic search error with query " . $searchQuery . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        // 2. Also search variants directly with diagnostic approach
        $this->log("Diagnostic: searching variants directly", LOG_DEBUG);
        
        $variantQuery = '
        query diagnosticVariantSearch($query: String!) {
            productVariants(first: 20, query: $query) {
                edges {
                    node {
                        id
                        legacyResourceId
                        sku
                        product {
                            id
                            legacyResourceId
                            title
                            handle
                        }
                    }
                }
            }
        }';

        $variantQueries = [
            $sku,
            '*' . $sku . '*',
            substr($sku, 0, 5) . '*'
        ];

        foreach ($variantQueries as $variantSearch) {
            try {
                $variables = ['query' => $variantSearch];
                $response = $this->executeGraphQL(['query' => $variantQuery, 'variables' => $variables]);

                if (!empty($response->data->productVariants->edges)) {
                    $this->log("Diagnostic found " . count($response->data->productVariants->edges) . " variant(s) with query: " . $variantSearch, LOG_INFO);
                    
                    foreach ($response->data->productVariants->edges as $variantEdge) {
                        $variant = $variantEdge->node;
                        $this->log("Diagnostic variant: SKU=" . $variant->sku . " in product=" . $variant->product->title, LOG_INFO);
                        
                        if ($variant->sku === $sku) {
                            $this->log("Diagnostic: Found exact variant match in variant search!", LOG_INFO);
                            return $variant;
                        }
                    }
                }
            } catch (Exception $e) {
                $this->log("Diagnostic variant search error: " . $e->getMessage(), LOG_WARNING);
            }
        }

        $this->log("=== DIAGNOSTIC SEARCH COMPLETED - NO MATCHES FOUND ===", LOG_WARNING);
        return null;
    }

    /**
     * Get all product images with pagination
     *
     * @param string $productId Shopify product ID
     * @return array Array of all image objects
     */
    public function getProductImages($productId)
    {
        $allImages = [];
        $hasNextPage = true;
        $cursor = null;

        $this->log("Récupération de toutes les images pour le produit: " . $productId, LOG_DEBUG);

        while ($hasNextPage) {
            $query = '
            query getProductMedia($productId: ID!, $first: Int!, $afterCursor: String) {
                product(id: $productId) {
                    media(first: $first, after: $afterCursor, query: "media_type:IMAGE", sortKey: POSITION) {
                        nodes {
                            id
                            mediaContentType
                            status
                            alt
                            ...on MediaImage {
                                image {
                                    url
                                }
                            }
                        }
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                    }
                }
            }';

            $variables = [
                'productId' =>  'gid://shopify/Product/' . $productId,
                'first' =>  50,
                'afterCursor' =>  $cursor
            ];

            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (
                isset($response->data) &&
                isset($response->data->product) &&
                isset($response->data->product->media) &&
                isset($response->data->product->media->nodes)
            ) {

                $images = $response->data->product->media->nodes;
                $allImages = array_merge($allImages, $images);

                // Check if there are more pages
                if (isset($response->data->product->media->pageInfo)) {
                    $pageInfo = $response->data->product->media->pageInfo;
                    $hasNextPage = $pageInfo->hasNextPage;
                    $cursor = $pageInfo->endCursor;
                    $this->log("Fetching image page: " . count($images) . " images retrieved, hasNextPage: " .
                        ($hasNextPage ? 'true' : 'false'), LOG_DEBUG);
                } else {
                    $hasNextPage = false;
                }
            } else {
                // In case of error or unexpected response, stop the loop
                $this->log("Error or unexpected response while retrieving images, stopping pagination", LOG_WARNING);
                if (isset($response->errors)) {
                    $this->log("GraphQL Errors: " . json_encode($response->errors), LOG_ERR);
                }
                $hasNextPage = false;
            }
        }

        $this->log("Total images retrieved for product " . $productId . ": " . count($allImages), LOG_INFO);
        return $allImages;
    }

    /**
     * Delete product images
     *
     * @param array $mediaIds Array of media IDs to delete
     * @param string $productId Shopify product ID
     * @return object Response from Shopify
     */
    public function deleteProductImages($mediaIds, $productId)
    {
        // Display details for debugging
        $this->log("Calling deleteProductImages with " . count($mediaIds) . " media for product: " . $productId, LOG_DEBUG);
        $this->log("List of media IDs to delete: " . implode(', ', array_map(function ($id) {
            return is_string($id) ? $id : (string)$id;
        }, $mediaIds)), LOG_DEBUG);

        $query = '
        mutation productDeleteMedia($mediaIds: [ID!]!, $productId: ID!) {
            productDeleteMedia(mediaIds: $mediaIds, productId: $productId) {
                deletedMediaIds
                deletedProductImageIds
                mediaUserErrors {
                    field
                    message
                }
                product {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'mediaIds' =>  array_values(array_map(function ($id) {
                return is_string($id) ? $id : (string)$id;
            }, $mediaIds)),
            'productId' =>  'gid://shopify/Product/' . $productId
        ];

        $this->log("Executing productDeleteMedia mutation for product: " . $productId, LOG_INFO);
        $this->log("GraphQL Variables for deletion: " . json_encode($variables), LOG_DEBUG);

        try {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            // Log the response for debugging
            $this->log("Response from productDeleteMedia: " . json_encode($response), LOG_DEBUG);

            // Check if the deletion was successful
            if (
                isset($response->data) &&
                isset($response->data->productDeleteMedia) &&
                !empty($response->data->productDeleteMedia->deletedMediaIds)
            ) {

                $deletedCount = count($response->data->productDeleteMedia->deletedMediaIds);
                $this->log("Successfully deleted " . $deletedCount . " media for product: " . $productId, LOG_INFO);
            } elseif (isset($response->errors)) {
                $this->log("GraphQL error during media deletion: " . json_encode($response->errors), LOG_ERR);
            } elseif (
                isset($response->data) &&
                isset($response->data->productDeleteMedia) &&
                !empty($response->data->productDeleteMedia->mediaUserErrors)
            ) {

                $this->log("User errors during media deletion: " .
                    json_encode($response->data->productDeleteMedia->mediaUserErrors), LOG_ERR);
            }

            return $response;
        } catch (Exception $e) {
            $this->log("Exception during execution of productDeleteMedia: " . $e->getMessage(), LOG_ERR);
            throw $e;
        }
    }

    /**
     * Create staged uploads for images
     *
     * @param array $inputs Array of staged upload inputs
     * @return object Response from Shopify
     */
    public function createStagedUploads($inputs)
    {
        $query = '
        mutation stagedUploadsCreate($input: [StagedUploadInput!]!) {
            stagedUploadsCreate(input: $input) {
                stagedTargets {
                    url
                    resourceUrl
                    parameters {
                        name
                        value
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'input' =>  $inputs
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Upload to staged target
     *
     * @param object $target Staged target object
     * @param array $imageContent Image content data
     * @return bool Success status
     */
    public function uploadToStagedTarget($target, $imageContent)
    {
        try {
            // Story 61-6 (AC5) : 'verify' passé à true (alignement sur initClient(), qui le
            // fait correctement depuis l'origine) + timeout explicite ajouté. Aucun incident
            // TLS documenté dans l'historique git ne justifiait 'verify' => false — c'était un
            // réglage par défaut jamais revu depuis la création de cette méthode (mars 2025).
            // 'http_errors' reste à false volontairement (comportement inchangé : pas
            // d'exception sur un code HTTP d'erreur, seulement sur timeout/connexion — ce cas
            // est déjà couvert par le catch (Exception $e) englobant plus bas, qui capture
            // aussi GuzzleHttp\Exception\ConnectException via TransferException/RuntimeException).
            $client = new \GuzzleHttp\Client([
                'verify' =>  true,
                'timeout' =>  30,
                'http_errors' =>  false
            ]);

            // Detect upload type based on URL signature and parameters
            // If URL contains Google auth parameters, use PUT (products)
            // If URL is simple but we have Google auth parameters, construct PUT URL (collections with PUT)
            // Otherwise use POST multipart (collections with POST)
            $hasGoogleParams = strpos($target->url, '?X-Goog-') !== false;
            $hasGoogleAuthInParams = false;

            // Check if we have Google auth parameters in the parameters array
            foreach ($target->parameters as $param) {
                if (strpos($param->name, 'x-goog-') === 0) {
                    $hasGoogleAuthInParams = true;
                    break;
                }
            }

            $usePost = !$hasGoogleParams && !$hasGoogleAuthInParams;

            if ($usePost) {
                // POST multipart upload for collections
                $this->log("Using POST multipart upload for collection image", LOG_DEBUG);

                $multipart = [];

                // Add all parameters as form fields first
                foreach ($target->parameters as $param) {
                    $multipart[] = [
                        'name' => $param->name,
                        'contents' => $param->value
                    ];
                }

                // Add the file content last
                $multipart[] = [
                    'name' => 'file',
                    'contents' => $imageContent['content'],
                    'filename' => $imageContent['filename'],
                    'headers' => [
                        'Content-Type' => $imageContent['mime_type']
                    ]
                ];

                $response = $client->request(
                    'POST',
                    $target->url,
                    [
                        'multipart' => $multipart
                    ]
                );
            } else {
                // PUT binary upload for products and collections
                $uploadType = $hasGoogleParams ? "product" : "collection";
                $this->log("Using PUT binary upload for {$uploadType} image", LOG_DEBUG);

                $putUrl = $target->url;
                $headers = [];

                // If URL doesn't have Google params but parameters do, construct the URL
                if (!$hasGoogleParams && $hasGoogleAuthInParams) {
                    $queryParams = [];
                    foreach ($target->parameters as $param) {
                        $queryParams[$param->name] = $param->value;
                    }
                    $putUrl = $target->url . '?' . http_build_query($queryParams);
                    $this->log("Constructed PUT URL with Google auth parameters: " . $putUrl, LOG_DEBUG);
                } else {
                    // Traditional method: parameters go in headers
                    foreach ($target->parameters as $param) {
                        $headers[$param->name] = $param->value;
                    }
                }

                $response = $client->request(
                    'PUT',
                    $putUrl,
                    [
                        'body' =>  $imageContent['content'],
                        'headers' =>  $headers
                    ]
                );
            }

            $finalUploadUrl = isset($putUrl) ? $putUrl : $target->url;
            $this->log("Uploading to URL: " . $finalUploadUrl, LOG_DEBUG);
            $responseBody = $response->getBody()->getContents();
            $this->log("Upload response: " . $response->getStatusCode() . " - " . $responseBody, LOG_DEBUG);

            // Check if upload was successful
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                // For POST uploads (collections), construct correct URL from resourceUrl + key parameter
                if ($usePost) {
                    // Find the key parameter from staged target parameters
                    $keyParam = null;
                    foreach ($target->parameters as $param) {
                        if ($param->name === 'key') {
                            $keyParam = $param->value;
                            break;
                        }
                    }

                    if ($keyParam && !empty($target->resourceUrl)) {
                        // Construct the final URL by concatenating resourceUrl with key parameter
                        $finalUrl = rtrim($target->resourceUrl, '/') . '/' . ltrim($keyParam, '/');
                        $this->log("Constructed final URL from resourceUrl + key: " . $finalUrl, LOG_DEBUG);
                        $this->log("resourceUrl: " . $target->resourceUrl . " | key: " . $keyParam, LOG_DEBUG);
                        return $finalUrl;
                    } else {
                        // Story 61-6 (review, MEDIUM-1) : $this->error/$this->errors[] positionnés
                        // (convention déjà utilisée par initClient(), cf. l.1057-1060) pour que
                        // l'appelant (importproducts.class.php) puisse journaliser la cause réelle
                        // plutôt qu'un simple retour falsy.
                        $this->error = "Failed to construct URL - Missing resourceUrl or key parameter";
                        $this->errors[] = $this->error;
                        $this->log($this->error, LOG_ERR);
                        return false;
                    }
                }
                // For PUT uploads (products), return the resourceUrl or success indicator
                return $target->resourceUrl ?? true;
            }

            // Story 61-6 (review, MEDIUM-1) : idem ci-dessus — cause exposée via $this->error.
            $this->error = "uploadToStagedTarget: No valid upload target found in response";
            $this->errors[] = $this->error;
            $this->log($this->error, LOG_WARNING);
            return false;
        } catch (Exception $e) {
            // Story 61-6 (review, MEDIUM-1) : la cause réelle d'un échec (ex. "cURL error 60:
            // SSL certificate problem…", désormais POSSIBLE pour la première fois avec
            // 'verify' => true — cf. Story 61-6 AC5) était journalisée ici mais n'atteignait
            // jamais l'appelant (importproducts.class.php), qui ne voyait qu'un retour falsy et
            // journalisait sa propre ligne générique sans cause. Exposée via $this->error, sur
            // le même modèle que initClient().
            $this->error = $e->getMessage();
            $this->errors[] = $this->error;
            $this->log("Error uploading to staged target: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Create product media
     *
     * MEDIUM (re-review 3 couches 27/09/2026, story
     * apparier-les-images-une-a-une-au-lieu-de-tout-detruire) : découpe en lots d'au plus 200 —
     * même prudence et même marge que getMediaStatusByIds() ci-dessous et
     * fillInventoryReferenceQuantities() (~:4071, `nodes(ids:)` borné à 250 par Shopify). Un très
     * gros catalogue d'images pour un seul produit ne doit jamais risquer une mutation rejetée
     * pour dépassement de taille. Chaque lot est exécuté séparément et les résultats (`media`,
     * `mediaUserErrors`) sont fusionnés — transparent pour l'appelant (même forme de réponse)
     * tant que le nombre de médias reste sous la limite d'un lot, ce qui couvre la quasi-totalité
     * des cas réels.
     *
     * @param string $productId Shopify product ID
     * @param array $mediaInputs Media inputs
     * @return object Réponse fusionnée : `$response->data->productCreateMedia->media` et
     *                `->mediaUserErrors` concaténés sur tous les lots
     */
    public function createProductMedia($productId, $mediaInputs)
    {
        $query = '
        mutation productCreateMedia($media: [CreateMediaInput!]!, $productId: ID!) {
            productCreateMedia(media: $media, productId: $productId) {
                media {
                    id
                    alt
                    mediaContentType
                    status
                }
                mediaUserErrors {
                    field
                    message
                }
            }
        }';

        $allMedia = [];
        $allMediaUserErrors = [];

        foreach (array_chunk($mediaInputs, 200) as $chunk) {
            $variables = [
                'media' =>  $chunk,
                'productId' =>  'gid://shopify/Product/' . $productId
            ];

            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (isset($response->data->productCreateMedia->media) && is_array($response->data->productCreateMedia->media)) {
                $allMedia = array_merge($allMedia, $response->data->productCreateMedia->media);
            }
            if (isset($response->data->productCreateMedia->mediaUserErrors) && is_array($response->data->productCreateMedia->mediaUserErrors)) {
                $allMediaUserErrors = array_merge($allMediaUserErrors, $response->data->productCreateMedia->mediaUserErrors);
            }
        }

        $result = new \stdClass();
        $result->data = new \stdClass();
        $result->data->productCreateMedia = new \stdClass();
        $result->data->productCreateMedia->media = $allMedia;
        $result->data->productCreateMedia->mediaUserErrors = $allMediaUserErrors;

        return $result;
    }


    /**
     * Link an image to a variant
     *
     * @param string $imageId Image ID
     * @param string $productId Product ID
     * @param string $variantId Variant ID
     * @return object Response from Shopify
     */
    public function linkImageToVariant($imageId, $productId, $variantId)
    {
        $query = '
        mutation productVariantAppendMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
            productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
                product {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'productId' =>  'gid://shopify/Product/' . $productId,
            'variantMedia' =>  [
                [
                    'mediaIds' =>  ['gid://shopify/MediaImage/' . $imageId],
                    'variantId' =>  'gid://shopify/ProductVariant/' . $variantId
                ]
            ]
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Get existing product options from Shopify
     *
     * @param string $productId Shopify product ID
     * @return array Array of product options in Shopify format
     */
    public function getProductOptions($productId)
    {
        $this->log("Getting product options for Shopify product: " . $productId, LOG_DEBUG);

        $query = [
            'query' =>  'query getProductOptions($productId: ID!) {
                product(id: $productId) {
                    options {
                        id
                        name
                        values
                    }
                }
            }',
            'variables' =>  [
                'productId' =>  'gid://shopify/Product/' . $productId
            ]
        ];

        $response = $this->executeGraphQL($query);

        if (isset($response->data) && isset($response->data->product) && isset($response->data->product->options)) {
            $options = [];
            foreach ($response->data->product->options as $option) {
                $options[] = [
                    'id' =>  $option->id,
                    'name' =>  $option->name,
                    'values' =>  $option->values
                ];
            }
            return $options;
        }

        return [];
    }

    /**
     * Récupère les métachamps d'un produit sur Shopify
     *
     * @param string $productId Shopify product ID
     * @return array Tableau associatif des métachamps (namespace.key => value)
     */
    public function getProductMetafields($productId)
    {
        $this->log("Getting metafields for Shopify product: " . $productId, LOG_DEBUG);

        $query = [
            'query' =>  'query getProductMetafields($productId: ID!) {
                product(id: $productId) {
                    metafields(first: 50) {
                        edges {
                            node {
                                id
                                namespace
                                key
                                value
                                type
                            }
                        }
                    }
                }
            }',
            'variables' =>  [
                'productId' =>  'gid://shopify/Product/' . $productId
            ]
        ];

        $response = $this->executeGraphQL($query);
        $metafields = [];

        if (
            isset($response->data) &&
            isset($response->data->product) &&
            isset($response->data->product->metafields) &&
            isset($response->data->product->metafields->edges)
        ) {
            foreach ($response->data->product->metafields->edges as $edge) {
                $metafield = $edge->node;
                $metafields[$metafield->namespace . '.' . $metafield->key] = [
                    'id' => $metafield->id,
                    'value' => $metafield->value,
                    'type' => $metafield->type
                ];
            }
        }

        $this->log("Retrieved " . count($metafields) . " metafields for product " . $productId, LOG_DEBUG);
        return $metafields;
    }

    /**
     * Récupère les variants d'un produit sur Shopify
     *
     * @param string $productId Shopify product ID (numeric format)
     * @return array Array of variants or empty array if none found
     * @version 2.0.33
     * @since 2.0.33
     */
    /**
     * Lit la quantité ON_HAND réellement enregistrée par Shopify, pour une localisation donnée.
     *
     * ## Pourquoi cette méthode existe
     *
     * `inventorySetOnHandQuantities` **n'est pas une écriture absolue** : c'est un
     * *compare-and-swap*. Son champ `changeFromQuantity` dit « voici la quantité que je crois
     * enregistrée » ; si Shopify en a une autre, il refuse l'opération entière.
     *
     * Le module y envoyait **0** pour tout article dont il ignorait la quantité courante — le
     * commentaire du code parlait d'un « SET absolu, changeFromQuantity=0 par construction »,
     * ce qui décrit une mutation qui n'existe pas. Chez un client, le 21/09/2026, **25 produits
     * sur 76** ont été refusés d'un coup avec, pour chacun :
     *
     *     input.setQuantities.0.changeFromQuantity:
     *     The changeFromQuantity argument no longer matches the persisted quantity.
     *
     * Leur stock restait donc à l'ancienne valeur — souvent zéro — sans que rien d'autre ne le
     * signale.
     *
     * ## Pourquoi ne pas réutiliser `ProductVariant.inventoryQuantity`
     *
     * Ce champ porte la quantité **available**, et **toutes localisations confondues**. Or la
     * comparaison se fait sur **on_hand**, **à une localisation précise**. Les deux valeurs
     * diffèrent dès qu'il existe du stock engagé par une commande non honorée
     * (`available = on_hand − committed`), ou dès qu'une seconde localisation existe. S'en servir
     * comme référence revient à parier que le client n'a ni commande en cours ni second entrepôt.
     *
     * @param  array<int,string> $inventoryItemGids Identifiants GraphQL d'articles d'inventaire
     * @param  string            $locationGid       Identifiant GraphQL de la localisation
     * @return array<string,int> Quantité on_hand par identifiant ; les articles sans niveau de
     *                           stock à cette localisation sont ABSENTS du tableau (et non
     *                           ramenés à zéro, ce qui serait la même erreur qu'avant)
     * @since  2.5.5
     */
    public function getInventoryQuantitiesAtLocation(array $inventoryItemGids, $locationGid)
    {
        $result = [];

        $inventoryItemGids = array_values(array_unique(array_filter($inventoryItemGids)));
        if (empty($inventoryItemGids) || empty($locationGid)) {
            return $result;
        }

        // Re-review 27/09/2026 (MEDIUM) : un $locationGid mal formé n'était classé structurel que
        // PARCE QUE son message d'erreur GraphQL contenait le mot "location" (nom de la variable
        // rejetée par Shopify) — un hasard de formulation, pas une détection déterministe. Le
        // format attendu ici est TOUJOURS `gid://shopify/Location/<entier>` : les appelants
        // (ImportProducts::syncVariantStocksOnly()/updateVariantInventory()) construisent ce GID
        // eux-mêmes via `'gid://shopify/Location/' . $this->config->shopify_location_id` — la
        // config `shopify_location_id` est stockée comme un ID NUMÉRIQUE (vérifié : formulaire
        // admin/setup.php, aide "ID de localisation... visible dans Paramètres > Emplacements",
        // jamais un GID complet ; même regex déjà utilisée pour valider ce même format dans
        // ImportProducts::updateInventoryQuantities(), `class/importproducts.class.php:3446`).
        // Un format invalide est donc TOUJOURS une erreur de configuration/appel, jamais un
        // incident réseau : on le détecte AVANT tout appel GraphQL, sans dépendre du texte que
        // Shopify choisirait de renvoyer.
        if (!preg_match('/^gid:\/\/shopify\/Location\/\d+$/', (string) $locationGid)) {
            $exception = new ShopifyLocationErrorException(
                'Erreur DETERMINISTE (format d\'identifiant d\'emplacement invalide, attendu '
                . 'gid://shopify/Location/<entier>) pour la localisation ' . $locationGid
            );
            $exception->reason = 'format';
            $exception->partialResults = $result;
            throw $exception;
        }

        // Hotfix 2.5.7 (re-review 27/09/2026, HIGH) : champ racine `location(id:)` ajouté à la
        // MÊME requête, DÉLIBÉRÉMENT — c'est ce qui permet une détection DÉTERMINISTE d'une
        // erreur d'emplacement (cf. isLocationFieldError() ci-dessous), plutôt que de deviner
        // depuis le libellé d'un message d'erreur qui peut prendre des formes non prévues
        // (erreur globale, erreur de champ avec `path`, ou simplement `inventoryLevel: null`
        // silencieux — SANS aucune erreur — quand l'emplacement est invalide/étranger à la
        // boutique). Le scope `read_locations` est déjà acquis par le module (getLocations(),
        // requête `locations(first: 50)` existante), donc ce champ n'exige aucun scope
        // supplémentaire.
        // Re-review 27/09/2026 (HIGH) : `isActive` ajouté au champ `location` — un emplacement
        // DÉSACTIVÉ côté Shopify (existe toujours, `location(id:)` le résout donc NON null) fait
        // malgré tout échouer `inventoryLevel(locationId:)` pour chaque article, indéfiniment,
        // sans que `data.location === null` (seul signal vérifié jusqu'ici) ne le détecte. Champ
        // déjà lu ailleurs dans ce fichier par getLocations() (`isActive`, requête
        // `locations(first: 50)` existante) : aucun scope supplémentaire requis.
        $query = '
        query onHandQuantities($ids: [ID!]!, $locationId: ID!) {
            location(id: $locationId) {
                id
                isActive
            }
            nodes(ids: $ids) {
                ... on InventoryItem {
                    id
                    inventoryLevel(locationId: $locationId) {
                        quantities(names: ["on_hand", "available"]) {
                            name
                            quantity
                        }
                    }
                }
            }
        }';

        // Shopify borne `nodes(ids:)` à 250 identifiants par appel.
        foreach (array_chunk($inventoryItemGids, 200) as $chunk) {
            $response = $this->executeGraphQL([
                'query' => $query,
                'variables' => ['ids' => $chunk, 'locationId' => $locationGid],
            ]);

            // Hotfix 2.5.7 (review 3 couches 26/09/2026, MEDIUM) : une erreur GraphQL GLOBALE
            // (ex. localisation inexistante/invalide) ne fait PAS lever d'exception dans
            // executeGraphQL() — elle revient normalement, avec un tableau `errors` top-level et
            // `data` absent ou vide. AVANT ce correctif, ce cas se confondait silencieusement
            // avec « rien à résoudre » : aucun article n'était jamais résolu, sans qu'aucun log
            // distinct ne le signale — et l'appelant le traitait comme un simple incident réseau
            // transitoire à retenter.
            //
            // ⚠️ Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : la première version de ce
            // correctif traitait TOUTE erreur globale comme un défaut de CONFIGURATION
            // (emplacement erroné) — y compris un THROTTLED (rate-limit Shopify), qu'
            // executeGraphQL() renvoie normalement (sans exception) après épuisement de ses
            // propres retries (cf. `errors[].message = "Throttled"`, ~ligne 1530).
            //
            // ⚠️ Hotfix 2.5.7 (re-review 27/09/2026, HIGH) : et la classification par LIBELLÉ de
            // message (isLocationRelatedGraphQLError() ci-dessous) n'est elle-même PAS fiable à
            // 100% : pour un emplacement supprimé/étranger à la boutique, Shopify peut renvoyer
            // (a) une erreur globale, (b) une erreur de CHAMP avec `path` et `data.nodes`
            // partiellement peuplé, ou (c) AUCUNE erreur et `inventoryLevel: null` silencieux —
            // le cas (c) laissait le défaut de configuration se maquiller en « article non
            // activé » répété pour chaque article, sans jamais se signaler comme structurel.
            // D'où le champ racine `location(id:)` ajouté à la requête : `data.location === null`
            // OU `data.location.isActive === false` (re-review 27/09/2026, HIGH, second passage —
            // un emplacement DÉSACTIVÉ existe toujours, donc `location` n'est PAS null, mais fait
            // échouer `inventoryLevel` pour chaque article tout aussi indéfiniment) est un signal
            // DÉTERMINISTE, vérifié par isLocationFieldError() AVANT tout examen de message.
            $locationFieldReason = $this->isLocationFieldError($response);

            $messageText = '';
            if (!empty($response->errors)) {
                if (is_array($response->errors)) {
                    $messages = [];
                    foreach ($response->errors as $err) {
                        $messages[] = (is_object($err) && isset($err->message)) ? $err->message : json_encode($err);
                    }
                    $messageText = implode(' | ', $messages);
                } else {
                    $messageText = (string) $response->errors;
                }
            }

            // LISTE D'AUTORISATION, jamais d'exclusion : une erreur inconnue reste TRANSITOIRE
            // par défaut. Le signal déterministe ($locationFieldReason) prime ; le classifieur
            // par message/extensions.code (isLocationRelatedGraphQLError()) reste un COMPLÉMENT
            // pour les cas où Shopify rejette la requête avant même de l'exécuter (ID mal formé
            // — `data` alors totalement absent, donc `location` n'y figure jamais).
            if ($locationFieldReason !== false || ($messageText !== '' && $this->isLocationRelatedGraphQLError($response->errors ?? null, $messageText))) {
                // Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : ne PAS perdre les
                // quantités déjà résolues par les chunks PRÉCÉDENTS de ce même appel — elles
                // voyagent avec l'exception, l'appelant les applique quand même.
                $reason = $locationFieldReason !== false ? $locationFieldReason : 'message';
                $reasonLabels = [
                    'null' => 'DETERMINISTE (data.location est null)',
                    'disabled' => 'DETERMINISTE (data.location.isActive est false — emplacement desactive)',
                    'path' => 'DETERMINISTE (erreur de champ sur le chemin location)',
                    'message' => 'GraphQL globale',
                ];
                $exception = new ShopifyLocationErrorException(
                    'Erreur ' . $reasonLabels[$reason]
                    . ' pour la localisation ' . $locationGid
                    . ($messageText !== '' ? ' : ' . $messageText : '')
                );
                $exception->reason = $reason;
                $exception->partialResults = $result;
                throw $exception;
            }

            if ($messageText !== '') {
                // Hotfix 2.5.7 (re-review 27/09/2026, MEDIUM) : erreur de CHAMP (pas
                // d'emplacement) — TRANSITOIRE. On ne fait PLUS de `continue` immédiat : si
                // `data.nodes` contient malgré tout des nœuds valides (erreur PARTIELLE sur ce
                // chunk, `errors` et `data.nodes` co-présents), ils sont traités normalement
                // ci-dessous au lieu d'être tous écartés avec le chunk entier. Seuls les nœuds en
                // erreur restent non résolus (donc écartés, retentés au prochain cycle).
                $this->log('ShopifyApi::getInventoryQuantitiesAtLocation - erreur GraphQL '
                    . 'TRANSITOIRE (non liee a l\'emplacement, ex. rate-limit) pour ' . $locationGid
                    . ' : ' . $messageText . ' - les noeuds valides de ce lot sont malgre tout traites.', LOG_WARNING);
            }

            if (empty($response->data->nodes) || !is_array($response->data->nodes)) {
                continue;
            }

            foreach ($response->data->nodes as $node) {
                if (empty($node->id) || empty($node->inventoryLevel->quantities)) {
                    // Pas de niveau de stock à cette localisation : on ne sait pas, et on le dit
                    // en n'inscrivant rien. Inventer un 0 ici reproduirait le défaut corrigé.
                    //
                    // Hotfix 2.5.7 (review 26/09/2026, MEDIUM) : le node EST résolu (l'article
                    // existe bien côté Shopify, l'id a été retourné) mais n'a AUCUNE donnée de
                    // niveau de stock à CETTE localisation précise — ce qui signifie concrètement
                    // que l'article n'est pas activé/stocké à cet emplacement dans Shopify. C'est
                    // un défaut STRUCTUREL (configuration produit côté Shopify), pas un incident
                    // réseau : message actionnable, distinct du cas générique.
                    if (!empty($node->id)) {
                        $this->log("ShopifyApi::getInventoryQuantitiesAtLocation - l'article " . $node->id
                            . " n'est pas active a l'emplacement " . $locationGid . " dans Shopify "
                            . '(aucune donnee de niveau de stock retournee pour cette localisation).', LOG_WARNING);
                    }
                    continue;
                }
                foreach ($node->inventoryLevel->quantities as $q) {
                    if (isset($q->name, $q->quantity) && in_array($q->name, ['on_hand', 'available'], true)) {
                        $result[$node->id][$q->name] = (int) $q->quantity;
                    }
                }
            }
        }

        $this->log('ShopifyApi::getInventoryQuantitiesAtLocation - ' . count($result)
            . ' article(s) resolu(s) sur ' . count($inventoryItemGids) . ' demande(s)', LOG_DEBUG);

        return $result;
    }

    /**
     * Hotfix 2.5.7 (re-review 27/09/2026, HIGH) : détection DÉTERMINISTE d'une erreur
     * d'emplacement — ne dépend d'AUCUN libellé de message, contrairement à
     * isLocationRelatedGraphQLError() ci-dessous. Repose sur le champ racine `location(id:)`
     * ajouté à la requête `onHandQuantities` (cf. getInventoryQuantitiesAtLocation()) : pour un
     * emplacement invalide/introuvable/inaccessible, Shopify renvoie `data.location: null` —
     * QUE ce null soit ou non accompagné d'une erreur de champ formelle sur le chemin
     * `location`. C'est ce signal qui fait foi ; le classifieur par message reste un complément
     * pour le seul cas où `data` est totalement absent (ID mal formé, requête rejetée avant
     * exécution).
     *
     * Re-review 27/09/2026 (HIGH, second passage) : un emplacement DÉSACTIVÉ côté Shopify
     * (existe toujours — `location(id:)` le résout donc NON null) échouait indéfiniment de la
     * même façon qu'un article non activé, sans jamais se signaler comme structurel :
     * `data.location.isActive === false` est désormais vérifié EN PLUS de `data.location === null`.
     * Retourne maintenant la RAISON précise (au lieu d'un simple booléen), pour que l'appelant
     * journalise un message distinct par cas — « désactivé » n'appelle pas la même remédiation
     * (choisir un autre emplacement) que « introuvable/invalide » (corriger la configuration).
     *
     * @param  object|null $response Réponse GraphQL décodée
     * @return string|false 'null'|'disabled'|'path', ou false si aucune erreur d'emplacement détectée
     * @since  2.5.7
     */
    private function isLocationFieldError($response)
    {
        if (isset($response->data) && is_object($response->data) && property_exists($response->data, 'location')) {
            $location = $response->data->location;

            if ($location === null) {
                return 'null';
            }

            if (is_object($location) && isset($location->isActive) && $location->isActive === false) {
                return 'disabled';
            }
        }

        if (!empty($response->errors) && is_array($response->errors)) {
            foreach ($response->errors as $err) {
                if (isset($err->path) && is_array($err->path) && in_array('location', $err->path, true)) {
                    return 'path';
                }
            }
        }

        return false;
    }

    /**
     * Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : détermine si un message d'erreur GraphQL
     * GLOBAL désigne réellement un problème d'EMPLACEMENT (location invalide/introuvable/accès
     * refusé) — par opposition à un THROTTLED (rate-limit Shopify) ou toute autre erreur globale
     * transitoire, qu'aucun retry sur l'emplacement lui-même ne résoudra jamais.
     *
     * ⚠️ Volontairement une LISTE D'AUTORISATION, jamais une liste d'exclusion : une erreur
     * inconnue reste TRANSITOIRE par défaut — c'est le sens sûr. Classer à tort une erreur
     * transitoire (ex. THROTTLED) comme structurelle a produit un LOG_ERR « vérifier
     * shopify_location_id » et un compteur dédié pour un simple rate-limit — l'inverse de ce
     * qu'annonçait le ChangeLog 2.5.7.
     *
     * Re-review 27/09/2026 (HIGH, complément) : simple filet pour le cas où `data` est
     * totalement absent (isLocationFieldError() ci-dessus ne peut alors rien constater) — ex.
     * un ID d'emplacement mal formé, requête rejetée avant exécution. Ajoute la vérification de
     * `extensions.code` (ACCESS_DENIED, NOT_FOUND), même motif que isUndefinedFieldError()
     * (~3907 à date) et publishCollectionToChannels() (~5946 à date) : une erreur structurée est
     * préférée au texte libre quand elle est disponible, mais exige la co-présence du mot
     * « location » dans le message de CETTE erreur — un ACCESS_DENIED/NOT_FOUND peut porter sur
     * un tout autre champ (ex. `write_publications` ailleurs dans ce fichier).
     *
     * @param  array|string|null $errors  `$response->errors` brut (tableau d'objets, chaîne, ou absent)
     * @param  string            $message Message d'erreur GraphQL (déjà concaténé si plusieurs erreurs)
     * @return bool
     */
    private function isLocationRelatedGraphQLError($errors, string $message): bool
    {
        if (is_array($errors)) {
            foreach ($errors as $err) {
                $code = isset($err->extensions->code) ? strtoupper((string) $err->extensions->code) : '';
                if (in_array($code, ['ACCESS_DENIED', 'NOT_FOUND'], true)
                    && stripos((string) ($err->message ?? ''), 'location') !== false
                ) {
                    return true;
                }
            }
        }

        $lower = strtolower($message);
        if (strpos($lower, 'location') === false) {
            return false;
        }

        foreach (['not found', 'does not exist', 'invalid', 'access denied', 'no longer exist', 'cannot be found'] as $needle) {
            if (strpos($lower, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public function getProductVariants($productId)
    {
        $this->log("Getting variants for Shopify product: " . $productId, LOG_DEBUG);

        $query = [
            'query' => 'query getProductVariants($productId: ID!) {
                product(id: $productId) {
                    variants(first: 50) {
                        edges {
                            node {
                                id
                                legacyResourceId
                                sku
                                inventoryItem {
                                    id
                                    tracked
                                }
                                inventoryQuantity
                                inventoryPolicy
                                product {
                                    id
                                    legacyResourceId
                                    title
                                    handle
                                }
                            }
                        }
                    }
                }
            }',
            'variables' => [
                'productId' => 'gid://shopify/Product/' . $productId
            ]
        ];

        try {
            $response = $this->executeGraphQL($query);

            if (isset($response->data) && isset($response->data->product) && isset($response->data->product->variants)) {
                $variants = [];
                foreach ($response->data->product->variants->edges as $edge) {
                    $variant = $edge->node;
                    $variants[] = (object)[
                        'id' => $variant->id,
                        'legacyResourceId' => $variant->legacyResourceId,
                        'sku' => $variant->sku ?? '',
                        'inventoryItemId' => $variant->inventoryItem->id ?? null,
                        'tracked' => $variant->inventoryItem->tracked ?? false,
                        'inventoryQuantity' => $variant->inventoryQuantity ?? 0,
                        'inventoryPolicy' => $variant->inventoryPolicy ?? 'DENY',
                        'product' => (object)[
                            'id' => $variant->product->id ?? null,
                            'legacyResourceId' => $variant->product->legacyResourceId ?? null,
                            'title' => $variant->product->title ?? '',
                            'handle' => $variant->product->handle ?? ''
                        ]
                    ];
                }

                $this->log("Retrieved " . count($variants) . " variants for product " . $productId, LOG_DEBUG);
                return $variants;
            }
        } catch (Exception $e) {
            $this->log("Error retrieving variants for product " . $productId . ": " . $e->getMessage(), LOG_ERR);
        }

        $this->log("No variants found for product " . $productId, LOG_DEBUG);
        return [];
    }

    /**
     * Vérifie si un produit a un métachamp lié à une option spécifique
     *
     * @param string $productId Shopify product ID
     * @param string $optionName Nom de l'option (ex: "Color")
     * @return array|null Données du métachamp si trouvé, null sinon
     */
    public function getProductOptionMetafield($productId, $optionName)
    {
        $metafields = $this->getProductMetafields($productId);
        $normalizedOptionName = strtolower($optionName);

        // Liste des associations potentielles entre options et métachamps
        $optionMetafieldMap = [
            'color' => 'shopify.color-pattern',
            'colour' => 'shopify.color-pattern',
            'couleur' => 'shopify.color-pattern',
            'material' => 'shopify.material',
            'matériau' => 'shopify.material',
            'matière' => 'shopify.material',
            'pattern' => 'shopify.pattern',
            'motif' => 'shopify.pattern'
        ];

        // Vérifier si l'option a un métachamp associé connu
        if (isset($optionMetafieldMap[$normalizedOptionName])) {
            $metafieldKey = $optionMetafieldMap[$normalizedOptionName];

            if (isset($metafields[$metafieldKey])) {
                $this->log("Found metafield " . $metafieldKey . " linked to option " . $optionName, LOG_DEBUG);
                return [
                    'key' => $metafieldKey,
                    'data' => $metafields[$metafieldKey]
                ];
            }
        }

        // Recherche plus générique pour d'autres métachamps qui pourraient être liés à l'option
        foreach ($metafields as $key => $data) {
            if (
                stripos($key, $normalizedOptionName) !== false ||
                (isset($data['value']) && stripos($data['value'], $normalizedOptionName) !== false)
            ) {
                $this->log("Found potential metafield " . $key . " that might be linked to option " . $optionName, LOG_INFO);
                return [
                    'key' => $key,
                    'data' => $data
                ];
            }
        }

        return null;
    }

    /**
     * Met à jour ou crée un métachamp pour un produit
     *
     * @param string $productId Shopify product ID
     * @param string $namespace Namespace du métachamp
     * @param string $key Clé du métachamp
     * @param mixed $value Valeur du métachamp
     * @param string $type Type de valeur du métachamp
     * @param string|null $metafieldId ID du métachamp existant (si mise à jour)
     * @return object Réponse de Shopify
     */
    public function updateProductMetafield($productId, $namespace, $key, $value, $type = 'string', $metafieldId = null)
    {
        // Déterminer si c'est une création ou une mise à jour
        $isUpdate = !empty($metafieldId);
        $operationName = $isUpdate ? 'metafieldUpdate' : 'metafieldCreate';

        // Préparer la mutation
        $query = '
        mutation ' . $operationName . '($input: ' . ($isUpdate ? 'MetafieldUpdateInput!' : 'MetafieldInput!') . ') {
            ' . $operationName . '(input: $input) {
                metafield {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        // Préparer les variables selon le type d'opération
        if ($isUpdate) {
            $variables = [
                'input' => [
                    'id' => $metafieldId,
                    'value' => $value
                ]
            ];
        } else {
            $variables = [
                'input' => [
                    'namespace' => $namespace,
                    'key' => $key,
                    'type' => $type,
                    'value' => $value,
                    'ownerId' => 'gid://shopify/Product/' . $productId
                ]
            ];
        }

        $this->log(($isUpdate ? "Updating" : "Creating") . " metafield " . $namespace . "." . $key . " for product " . $productId, LOG_INFO);

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Retrieve existing option IDs for a Shopify product
     *
     * @param string $productId Shopify product ID
     * @return array Associative array of option name =>  option ID
     */
    public function getProductOptionsIds($productId)
    {
        $this->log("Getting product option IDs for Shopify product: " . $productId, LOG_DEBUG);

        $query = [
            'query' =>  'query getProductOptions($productId: ID!) {
                product(id: $productId) {
                    options {
                        id
                        name
                    }
                }
            }',
            'variables' =>  [
                'productId' =>  'gid://shopify/Product/' . $productId
            ]
        ];

        $response = $this->executeGraphQL($query);

        $optionsIds = [];

        if (isset($response->data) && isset($response->data->product) && isset($response->data->product->options)) {
            foreach ($response->data->product->options as $option) {
                $optionsIds[$option->name] = $option->id;
            }

            $this->log("Retrieved " . count($optionsIds) . " option IDs for product: " . $productId, LOG_DEBUG);
        }

        return $optionsIds;
    }

    /**
     * Get product title from Shopify
     *
     * @param string $productId Shopify product ID (numeric, without gid prefix)
     * @return string|null Product title or null if error
     */
    public function getProductTitle($productId)
    {
        $this->log("Getting product title for Shopify product: " . $productId, LOG_DEBUG);

        $query = [
            'query' => 'query getProductTitle($productId: ID!) {
                product(id: $productId) {
                    title
                }
            }',
            'variables' => [
                'productId' => 'gid://shopify/Product/' . $productId
            ]
        ];

        try {
            $response = $this->executeGraphQL($query);

            if (isset($response->data) && isset($response->data->product) && isset($response->data->product->title)) {
                $title = $response->data->product->title;
                $this->log("Retrieved title from Shopify: " . $title, LOG_INFO);
                return $title;
            } else {
                $this->log("Could not retrieve title for product " . $productId, LOG_WARNING);
                return null;
            }
        } catch (Exception $e) {
            $this->log("Error getting product title: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Récupère le statut Shopify ACTUEL d'un produit (ACTIVE / DRAFT / ARCHIVED), RELU via
     * l'API — jamais supposé à partir d'un cache local. Utilisé par OffSaleProductsService::
     * scanBatch() (Story 58-5, AC4) pour afficher l'aperçu avant confirmation.
     *
     * @param string $shopifyProductId Shopify product legacy ID (numeric, sans préfixe gid://)
     * @return string|null Statut Shopify, ou null si le produit est introuvable/erreur
     * @since 2.5.0
     */
    public function getProductStatus($shopifyProductId)
    {
        $this->log("getProductStatus - Fetching Shopify product status: " . $shopifyProductId, LOG_DEBUG);

        $query = [
            'query' => 'query getProductStatus($productId: ID!) {
                product(id: $productId) {
                    status
                }
            }',
            'variables' => [
                'productId' => 'gid://shopify/Product/' . $shopifyProductId
            ]
        ];

        try {
            $response = $this->executeGraphQL($query);

            if (isset($response->data->product->status)) {
                return $response->data->product->status;
            }

            $this->log("getProductStatus - Could not retrieve status for product " . $shopifyProductId, LOG_WARNING);
            return null;
        } catch (Exception $e) {
            $this->log("getProductStatus - Exception: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Récupère le nombre RÉEL de médias actuellement attachés à un produit Shopify, RELU via
     * l'API — jamais déduit d'un cache local. Utilisé par ImportProducts::syncProductAllImages()
     * (story hash-images-survit-a-la-purge-et-bloque-le-renvoi, AC2) pour vérifier que le hash
     * composite stocké côté Dolibarr décrit encore un état réellement présent côté Shopify avant
     * de sauter l'envoi : le hash ne prouve que l'état de la SOURCE (Dolibarr), jamais celui de
     * la DESTINATION — une suppression/recréation du produit ou de ses médias côté Shopify laisse
     * le hash intact alors que les images ont disparu.
     *
     * @param string $shopifyProductId Shopify product legacy ID (numeric, sans préfixe gid://)
     * @return int|null Nombre de médias, ou null si le produit est introuvable/erreur (dans ce
     *                   cas l'appelant doit se comporter comme s'il ne pouvait PAS vérifier —
     *                   ne jamais sauter sur une vérification qui a échoué)
     * @since 2.5.5
     */
    public function getProductMediaCount($shopifyProductId)
    {
        $this->log("getProductMediaCount - Fetching Shopify product media count: " . $shopifyProductId, LOG_DEBUG);

        $query = [
            'query' => 'query getProductMediaCount($productId: ID!) {
                product(id: $productId) {
                    mediaCount {
                        count
                    }
                }
            }',
            'variables' => [
                'productId' => 'gid://shopify/Product/' . $shopifyProductId
            ]
        ];

        try {
            $response = $this->executeGraphQL($query);

            if (isset($response->data->product->mediaCount->count)) {
                return (int) $response->data->product->mediaCount->count;
            }

            $this->log("getProductMediaCount - Could not retrieve media count for product " . $shopifyProductId . " (product introuvable ou réponse inattendue)", LOG_WARNING);
            return null;
        } catch (Exception $e) {
            $this->log("getProductMediaCount - Exception: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Check the status of media for a product
     *
     * ⚠️ CONSERVÉE pour compatibilité mais N'EST PLUS APPELÉE par
     * `ImportProducts::waitForMediaToBeReady()` depuis la re-review 3 couches du 27/09/2026 (story
     * apparier-les-images-une-a-une-au-lieu-de-tout-detruire) : elle liste les 50 premiers médias
     * du PRODUIT (`media(first: 50)`, sans pagination), ce qui laissait un identifiant simplement
     * absent de la page sans jamais faire échouer le polling. Remplacée par
     * getMediaStatusByIds() ci-dessous, qui cible exactement les identifiants demandés.
     *
     * @param string $productId Shopify product ID
     * @return object Response from Shopify
     */
    public function checkMediaStatus($productId)
    {
        $query = '
        query CheckMediaStatus($productId: ID!) {
            product(id: $productId) {
                media(first: 50) {
                    nodes {
                        id
                        mediaContentType
                        status
                    }
                }
            }
        }';

        $variables = [
            'productId' =>  'gid://shopify/Product/' . $productId
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Récupère le statut FINAL d'un ensemble PRÉCIS d'identifiants de médias, via le champ
     * racine `nodes(ids:)` de l'Admin GraphQL API — remplace checkMediaStatus() dans
     * ImportProducts::waitForMediaToBeReady() (re-review 3 couches 27/09/2026, story
     * apparier-les-images-une-a-une-au-lieu-de-tout-detruire) : checkMediaStatus() listait les 50
     * premiers médias du PRODUIT (`media(first: 50)`, sans pagination) — un produit à plus de 50
     * médias, ou un identifiant simplement absent de cette page (course concurrente, permission),
     * ne remontait AUCUNE erreur, et le polling pouvait croire « tout est prêt » sans jamais avoir
     * vu le statut réel du média concerné.
     *
     * `nodes(ids:)` cible EXACTEMENT les identifiants passés — aucune pagination nécessaire, et un
     * identifiant introuvable revient explicitement à `null` dans le tableau de réponse (jamais
     * silencieusement absent).
     *
     * MEDIUM (re-review 3 couches 27/09/2026) : `nodes(ids:)` est borné à 250 identifiants par
     * appel côté Shopify — découpe en lots d'au plus 200 (même marge que
     * fillInventoryReferenceQuantities(), ~:4071), résultats fusionnés en une seule réponse.
     *
     * @param array $mediaIds IDs Shopify (gid://shopify/MediaImage/...) dont on veut le statut
     * @return object Réponse fusionnée — `$response->data->nodes` (tableau, `null` pour tout ID
     *                introuvable ; chaque entrée non nulle porte `id`, `status`, `mediaContentType`
     *                via le fragment MediaImage)
     * @since 2.6.0
     */
    public function getMediaStatusByIds(array $mediaIds)
    {
        $query = '
        query getMediaStatusByIds($ids: [ID!]!) {
            nodes(ids: $ids) {
                id
                ... on MediaImage {
                    status
                    mediaContentType
                }
            }
        }';

        $allNodes = [];

        foreach (array_chunk(array_values($mediaIds), 200) as $chunk) {
            $variables = [
                'ids' => $chunk,
            ];

            $this->log("getMediaStatusByIds - Fetching status for " . count($chunk) . " media (lot)", LOG_DEBUG);

            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            if (isset($response->data->nodes) && is_array($response->data->nodes)) {
                $allNodes = array_merge($allNodes, $response->data->nodes);
            }
        }

        $result = new \stdClass();
        $result->data = new \stdClass();
        $result->data->nodes = $allNodes;

        return $result;
    }

    /**
     * Reorder product media
     *
     * @param string $productId Shopify product ID
     * @param array $mediaIds Array of media IDs in the desired order
     * @return object Response from Shopify
     */
    public function reorderProductMedia($productId, $mediaIds)
    {
        // Define the GraphQL query according to the documentation
        $query = '
        mutation productReorderMedia($id: ID!, $moves: [MoveInput!]!) {
            productReorderMedia(id: $id, moves: $moves) {
                job {
                    id
                }
                mediaUserErrors {
                    field
                    message
                }
            }
        }';

        // Build the moves array with the new positions
        $moves = [];
        foreach ($mediaIds as $index =>  $mediaId) {
            $moves[] = [
                'id' =>  $mediaId,
                'newPosition' =>  (string)($index)
            ];
        }

        $variables = [
            'id' =>  'gid://shopify/Product/' . $productId,
            'moves' =>  $moves
        ];

        $this->log("Reordering " . count($mediaIds) . " media for product " . $productId, LOG_DEBUG);

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Create file from staged upload
     *
     * @param string $resourceUrl The resource URL from staged upload
     * @param string $filename Original filename
     * @param string $altText Alt text for the file (optional)
     * @return object Response from Shopify
     */
    public function fileCreate($resourceUrl, $filename, $altText = null)
    {
        $query = '
        mutation fileCreate($files: [FileCreateInput!]!) {
            fileCreate(files: $files) {
                files {
                    id
                    createdAt
                    fileStatus
                    alt
                    ... on MediaImage {
                        id
                        image {
                            url
                            width
                            height
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $fileInput = [
            'originalSource' => $resourceUrl,
            'filename' => $filename
        ];

        // Add altText if provided
        if ($altText !== null) {
            $fileInput['alt'] = $altText;
        }

        $variables = [
            'files' => [$fileInput]
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Get file status for collection image processing
     *
     * @param string $fileId File ID (gid://shopify/MediaImage/XXX)
     * @return object Response from Shopify with file status and image URL
     * @version 2.0.33
     * @since 2.0.33
     */
    public function getFileStatus($fileId)
    {
        $query = '
        query getFileStatus($id: ID!) {
            node(id: $id) {
                ... on MediaImage {
                    id
                    fileStatus
                    alt
                    image {
                        url
                        width
                        height
                    }
                }
            }
        }';

        $variables = [
            'id' => $fileId
        ];

        return $this->executeGraphQL(['query' => $query, 'variables' => $variables]);
    }

    /**
     * Get orders from Shopify
     *
     * @param array $params Parameters for filtering orders
     * @return array Orders data
     */
    /**
     * Get orders from Shopify with optional pagination
     * Unified method for both normal sync and historical import
     *
     * @param array $params Query parameters (created_at_min, created_at_max, financial_status, etc.)
     * @param string|null $cursor Pagination cursor for next page (null for first page)
     * @param int|null $batchSize Number of orders per batch (null uses max_orders_per_sync)
     * @return object Response object with orders array and pageInfo for pagination
     */
    public function getOrdersWithPagination($params = array(), $cursor = null, $batchSize = null)
    {
        $this->log(__METHOD__ . " - Params: " . json_encode($params) . ", Cursor: " . ($cursor ?? 'null') . ", BatchSize: " . ($batchSize ?? 'default'), LOG_DEBUG);
        
        // Build query filters with proper ISO 8601 format
        $queryFilters = [];

        // CORRECTION v2.0.28: Support pour updated_at_min (prioritaire) ou created_at_min
        $dateField = 'created_at';
        $dateValue = null;
        
        if (!empty($params['updated_at_min']) && $params['updated_at_min'] !== '0000-00-00') {
            $dateField = 'updated_at';
            $dateValue = $params['updated_at_min'];
            $this->log("Using updated_at filter (prioritaire pour Bug #80)", LOG_INFO);
        } elseif (!empty($params['created_at_min']) && $params['created_at_min'] !== '0000-00-00') {
            $dateField = 'created_at';
            $dateValue = $params['created_at_min'];
            $this->log("Using created_at filter (legacy)", LOG_INFO);
        }
        
        if ($dateValue) {
            // Handle date formats: "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", "YYYY-MM-DDTHH:MM:SS+00:00" (ISO 8601)
            if (strpos($dateValue, 'T') === false && strpos($dateValue, ' ') === false) {
                // Pure date only (YYYY-MM-DD), add time
                $dateValue .= ' 00:00:00';
            }
            $startDateTime = new DateTime($dateValue, new DateTimeZone('UTC'));
            // Enclose the date value in quotes to prevent field parsing issues
            $queryFilters[] = $dateField . ":>='" . $startDateTime->format('Y-m-d\TH:i:s\Z') . "'";
            $this->log("Start date filter: {$dateField}:>='" . $startDateTime->format('Y-m-d\TH:i:s\Z') . "'", LOG_DEBUG);
        }

        if (!empty($params['created_at_max']) && $params['created_at_max'] !== '0000-00-00') {
            // Convert to ISO 8601 format for Shopify GraphQL
            // Handle date formats: "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", "YYYY-MM-DDTHH:MM:SS+00:00" (ISO 8601)
            $dateValue = $params['created_at_max'];
            if (strpos($dateValue, 'T') === false && strpos($dateValue, ' ') === false) {
                // Pure date only (YYYY-MM-DD), add end-of-day time
                $dateValue .= ' 23:59:59';
            }
            $endDateTime = new DateTime($dateValue, new DateTimeZone('UTC'));
            // Enclose the date value in quotes to prevent field parsing issues
            $queryFilters[] = "created_at:<='" . $endDateTime->format('Y-m-d\TH:i:s\Z') . "'";
            $this->log("End date filter: created_at:<='" . $endDateTime->format('Y-m-d\TH:i:s\Z') . "'", LOG_DEBUG);
        }

        // Only filter by financial_status if sync_non_paid_orders is not enabled
        // This allows synchronization of orders from marketplaces like Ankorstore with deferred payment
        //
        // Story reglage-commandes-non-payees-ignore-par-les-webhooks (réserve 1 du Validate) :
        // lecture UNIFIÉE avec ShopifyOrderManager::fetchHistoricalOrdersBatch()
        // (shopifyordermanager.class.php ~4880) — StoreSettings::getInt() boutique-aware, avec repli
        // automatique sur la constante globale DOLI2SHOP_SYNC_NON_PAID_ORDERS quand aucun override
        // per-store n'existe (StoreSettings::get(), doc de tête de classe). Avant ce correctif, cette
        // méthode lisait UNIQUEMENT getDolGlobalBool(), jamais boutique-aware même via forStore() :
        // une boutique secondaire activant ce réglage en per-store était ignorée par le rattrapage
        // (getOrdersWithPagination() est le point d'entrée de catchupMissingOrders()).
        require_once dirname(__FILE__) . '/storesettings.class.php';
        $storeSettingsForNonPaidFilter = new StoreSettings($this->db);
        $syncNonPaidOrdersForFilter = (bool) $storeSettingsForNonPaidFilter->getInt($this->getStoreId(), 'SYNC_NON_PAID_ORDERS', 0);
        if (!empty($params['financial_status']) && !$syncNonPaidOrdersForFilter) {
            $queryFilters[] = "financial_status:'" . $params['financial_status'] . "'";
            $this->log("Filtering orders by financial_status: " . $params['financial_status'], LOG_DEBUG);
        } elseif ($syncNonPaidOrdersForFilter) {
            $this->log("Sync non-paid orders enabled - skipping financial_status filter", LOG_INFO);
        }

        $queryString = implode(' AND ', $queryFilters);
        $this->log("GraphQL query filter: " . ($queryString ?: 'none'), LOG_INFO);

        // Determine batch size
        $batchSize = $batchSize ?? (!empty($this->config->max_orders_per_sync) ? (int)$this->config->max_orders_per_sync : 10);

        // CORRECTION v2.0.28: Adapter le sortKey selon le champ de date utilisé
        $sortKey = ($dateField === 'updated_at') ? 'UPDATED_AT' : 'CREATED_AT';
        $this->log("Using sortKey: $sortKey", LOG_DEBUG);

        // Build unified GraphQL query with all necessary fields
        $gqlQuery = <<<GRAPHQL
        query getOrdersUnified(\$first: Int!, \$cursor: String, \$query: String) {
            orders(first: \$first, after: \$cursor, query: \$query, sortKey: $sortKey) {
                edges {
                    node {
                        id
                        name
                        createdAt
                        processedAt
                        cancelledAt
                        currencyCode
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
                        customer {
                            id
                            firstName
                            lastName
                            email
                            phone
                            defaultAddress {
                                company
                                firstName
                                lastName
                                address1
                                address2
                                city
                                province
                                country
                                countryCodeV2
                                zip
                                phone
                            }
                            emailMarketingConsent {
                                marketingState
                                marketingOptInLevel
                                consentUpdatedAt
                            }
                        }
                        billingAddress {
                            company
                            firstName
                            lastName
                            address1
                            address2
                            city
                            province
                            country
                            countryCodeV2
                            zip
                            phone
                        }
                        shippingAddress {
                            company
                            firstName
                            lastName
                            address1
                            address2
                            city
                            province
                            country
                            countryCodeV2
                            zip
                            phone
                        }
                        discountCodes
                        discountApplications(first: 10) {
                            edges {
                                node {
                                    allocationMethod
                                    targetSelection
                                    targetType
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
                        lineItems(first: 40) {
                            edges {
                                node {
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
                        transactions(first: 5) {
                            gateway
                            paymentDetails {
                                ... on CardPaymentDetails {
                                    paymentMethodName
                                }
                                ... on LocalPaymentMethodsPaymentDetails {
                                    paymentMethodName
                                }
                                ... on ShopPayInstallmentsPaymentDetails {
                                    paymentMethodName
                                }
                            }
                        }
                        metafields(first: 250) {
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                            edges {
                                node {
                                    namespace
                                    key
                                    value
                                    type
                                }
                            }
                        }
                        customAttributes {
                            key
                            value
                        }
                        note
                        tags
                        sourceName
                        email
                    }
                }
                pageInfo {
                    hasNextPage
                    hasPreviousPage
                    startCursor
                    endCursor
                }
            }
        }
        GRAPHQL;

        // Prepare variables for GraphQL execution
        $variables = [
            'first' => $batchSize,
            'query' => $queryString
        ];
        
        if ($cursor) {
            $variables['cursor'] = $cursor;
        }

        $response = $this->executeGraphQL(['query' => $gqlQuery, 'variables' => $variables]);

        // Check for GraphQL errors
        if (isset($response->errors)) {
            $errorMsg = "GraphQL error in getOrdersWithPagination: " . json_encode($response->errors);
            $this->log($errorMsg, LOG_ERR);
            throw new Exception($errorMsg);
        }

        // Return structured response for both simple and paginated usage
        $result = new stdClass();
        
        if (!empty($response->data->orders->edges)) {
            $result->orders = array_map(function ($edge) {
                return $edge->node;
            }, $response->data->orders->edges);
        } else {
            $result->orders = array();
        }

        // Include pagination info for advanced usage with validation
        if (isset($response->data->orders->pageInfo)) {
            $result->pageInfo = $response->data->orders->pageInfo;
            
            // Validate pageInfo has expected boolean values
            if (!isset($result->pageInfo->hasNextPage) || !is_bool($result->pageInfo->hasNextPage)) {
                $this->log("WARNING: pageInfo->hasNextPage is not a valid boolean: " . json_encode($result->pageInfo->hasNextPage), LOG_WARNING);
                $result->pageInfo->hasNextPage = false; // Safe fallback
            }
            
            if (!isset($result->pageInfo->hasPreviousPage) || !is_bool($result->pageInfo->hasPreviousPage)) {
                $this->log("WARNING: pageInfo->hasPreviousPage is not a valid boolean: " . json_encode($result->pageInfo->hasPreviousPage), LOG_WARNING);
                $result->pageInfo->hasPreviousPage = false; // Safe fallback
            }
        } else {
            $this->log("ERROR: Missing pageInfo in GraphQL response", LOG_ERR);
            $result->pageInfo = (object)[
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null
            ];
        }
        
        $this->log("Retrieved " . count($result->orders) . " orders, hasNextPage: " . json_encode($result->pageInfo->hasNextPage) . ", hasPreviousPage: " . json_encode($result->pageInfo->hasPreviousPage), LOG_INFO);

        return $result;
    }

    /**
     * Récupère TOUS les metafields d'une commande avec pagination automatique
     *
     * CORRECTION v2.1.5: Gestion pagination complète pour éviter perte de données >250 metafields
     *
     * @param string $orderId ID GraphQL de la commande (format: gid://shopify/Order/123456789)
     * @return array Tous les metafields (illimités)
     * @throws Exception En cas d'erreur GraphQL
     * @since 2.1.5
     */
    public function getAllOrderMetafields($orderId)
    {
        $allMetafields = array();
        $hasNextPage = true;
        $cursor = null;
        $pageCount = 0;

        $this->log("Récupération metafields avec pagination pour commande: $orderId", LOG_INFO);

        while ($hasNextPage) {
            $pageCount++;

            $query = <<<GRAPHQL
query getOrderMetafields(\$orderId: ID!, \$cursor: String) {
  order(id: \$orderId) {
    metafields(first: 250, after: \$cursor) {
      pageInfo {
        hasNextPage
        endCursor
      }
      edges {
        node {
          namespace
          key
          value
          type
        }
      }
    }
  }
}
GRAPHQL;

            $variables = ['orderId' => $orderId];
            if ($cursor) {
                $variables['cursor'] = $cursor;
            }

            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            // Vérification erreurs GraphQL
            if (isset($response->errors)) {
                $errorMsg = "GraphQL error in getAllOrderMetafields (page $pageCount): " . json_encode($response->errors);
                $this->log($errorMsg, LOG_ERR);
                throw new Exception($errorMsg);
            }

            // Extraction metafields de la page courante
            if (isset($response->data->order->metafields->edges)) {
                $edges = $response->data->order->metafields->edges;
                foreach ($edges as $edge) {
                    $allMetafields[] = $edge->node;
                }

                $this->log("Page $pageCount : " . count($edges) . " metafields récupérés", LOG_DEBUG);

                // Vérification pagination
                $pageInfo = $response->data->order->metafields->pageInfo ?? null;
                if ($pageInfo) {
                    $hasNextPage = $pageInfo->hasNextPage ?? false;
                    $cursor = $pageInfo->endCursor ?? null;
                } else {
                    $this->log("WARNING: pageInfo absent pour getAllOrderMetafields page $pageCount", LOG_WARNING);
                    break;
                }
            } else {
                $this->log("WARNING: Structure GraphQL inattendue pour getAllOrderMetafields page $pageCount", LOG_WARNING);
                break;
            }

            // Sécurité : limite à 100 pages max (25 000 metafields)
            if ($pageCount >= 100) {
                $this->log("ATTENTION: Limite de 100 pages atteinte pour getAllOrderMetafields (orderId: $orderId)", LOG_WARNING);
                break;
            }
        }

        $totalMetafields = count($allMetafields);
        $this->log("Récupération metafields terminée : $totalMetafields metafields sur $pageCount page(s) pour commande $orderId", LOG_INFO);

        return $allMetafields;
    }

    /**
     * Résout l'ID GraphQL d'une commande à partir d'un identifiant libre (#275)
     *
     * Accepte : un gid complet (`gid://shopify/Order/123`), un numéro de commande
     * Shopify (`#1234` ou `1234`) résolu via `orders(query: "name:...")`.
     *
     * @param string $identifier Identifiant saisi par l'admin
     * @return string|null ID GraphQL (gid://shopify/Order/...) ou null si introuvable
     * @since 2.3.0
     */
    public function resolveOrderGid($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        // Déjà un gid → tel quel
        if (strpos($identifier, 'gid://shopify/Order/') === 0) {
            return $identifier;
        }

        // Numéro de commande Shopify (name) : normaliser avec le préfixe '#'
        $orderName = ($identifier[0] === '#') ? $identifier : '#' . $identifier;

        $query = <<<GRAPHQL
query resolveOrder(\$query: String!) {
  orders(first: 1, query: \$query) {
    edges {
      node {
        id
        name
      }
    }
  }
}
GRAPHQL;

        $variables = ['query' => 'name:' . $orderName];
        $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

        if (isset($response->errors)) {
            $this->log("resolveOrderGid - GraphQL error: " . json_encode($response->errors), LOG_ERR);
            return null;
        }

        if (!empty($response->data->orders->edges[0]->node->id)) {
            return $response->data->orders->edges[0]->node->id;
        }

        $this->log("resolveOrderGid - Aucune commande trouvée pour '$orderName'", LOG_WARNING);
        return null;
    }

    /**
     * Récupère le JSON brut complet d'une commande Shopify pour le support (#275)
     *
     * Re-fetch en temps réel via GraphQL (jamais REST) : champs commande, adresses,
     * lignes (avec `customAttributes` des apps tierces : bundles, etc.), metafields, note.
     *
     * @param string $identifier Numéro de commande (#1234 / 1234) ou gid GraphQL
     * @return object|null Nœud `order` GraphQL (stdClass) ou null si introuvable/erreur
     * @since 2.3.0
     */
    public function getOrderRawJson($identifier)
    {
        $orderGid = $this->resolveOrderGid($identifier);
        if (empty($orderGid)) {
            $this->error = "Commande introuvable pour l'identifiant fourni";
            return null;
        }

        $query = <<<GRAPHQL
query getOrderForSupport(\$id: ID!) {
  order(id: \$id) {
    id
    name
    createdAt
    updatedAt
    processedAt
    cancelledAt
    closed
    closedAt
    note
    tags
    email
    phone
    currencyCode
    displayFinancialStatus
    displayFulfillmentStatus
    taxesIncluded
    customAttributes { key value }
    totalPriceSet { shopMoney { amount currencyCode } }
    subtotalPriceSet { shopMoney { amount currencyCode } }
    totalShippingPriceSet { shopMoney { amount currencyCode } }
    totalTaxSet { shopMoney { amount currencyCode } }
    totalDiscountsSet { shopMoney { amount currencyCode } }
    customer { id firstName lastName email phone }
    shippingAddress { firstName lastName company address1 address2 city province country countryCodeV2 zip phone }
    billingAddress { firstName lastName company address1 address2 city province country countryCodeV2 zip phone }
    shippingLines(first: 10) {
      edges { node { title originalPriceSet { shopMoney { amount currencyCode } } } }
    }
    lineItems(first: 250) {
      edges {
        node {
          id
          name
          sku
          quantity
          variantTitle
          vendor
          customAttributes { key value }
          originalUnitPriceSet { shopMoney { amount currencyCode } }
          discountedUnitPriceSet { shopMoney { amount currencyCode } }
          product { id }
          variant { id }
          taxLines { title rate priceSet { shopMoney { amount currencyCode } } }
        }
      }
    }
    metafields(first: 100) {
      edges { node { namespace key value type } }
    }
  }
}
GRAPHQL;

        $response = $this->executeGraphQL(['query' => $query, 'variables' => ['id' => $orderGid]]);

        if (isset($response->errors)) {
            $this->error = "Erreur GraphQL lors de la récupération de la commande";
            $this->log("getOrderRawJson - GraphQL error: " . json_encode($response->errors), LOG_ERR);
            return null;
        }

        if (empty($response->data->order)) {
            $this->error = "Commande $orderGid introuvable côté Shopify";
            $this->log("getOrderRawJson - Réponse vide pour $orderGid", LOG_WARNING);
            return null;
        }

        return $response->data->order;
    }

    /**
     * Résout l'identifiant produit fourni par l'admin vers un gid GraphQL Shopify.
     *
     * Accepte : gid (`gid://shopify/Product/123`), ID numérique, SKU de variante, ou titre produit.
     * Recherche par SKU d'abord (le plus discriminant), puis par titre (cf. conventions Doli2Shop).
     *
     * @param string $identifier gid / ID numérique / SKU variante / titre produit
     * @return string|null gid produit ou null si introuvable/erreur
     * @since 2.3.0
     */
    public function resolveProductGid($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        // Déjà un gid → tel quel
        if (strpos($identifier, 'gid://shopify/Product/') === 0) {
            return $identifier;
        }

        // ID numérique pur → gid produit
        if (ctype_digit($identifier)) {
            return 'gid://shopify/Product/' . $identifier;
        }

        $query = <<<GRAPHQL
query resolveProduct(\$query: String!) {
  products(first: 1, query: \$query) {
    edges { node { id } }
  }
}
GRAPHQL;

        // Échapper pour la SYNTAXE de recherche Shopify (guillemets / backslash) puis encadrer
        // de guillemets : neutralise les opérateurs (AND/OR, parenthèses, ':') dans l'identifiant.
        $safe = str_replace(array('\\', '"'), array('\\\\', '\\"'), $identifier);
        // 1) Recherche par SKU de variante, puis 2) par titre
        foreach (array('sku:"' . $safe . '"', 'title:"' . $safe . '"') as $searchQuery) {
            $response = $this->executeGraphQL(['query' => $query, 'variables' => ['query' => $searchQuery]]);

            if (isset($response->errors)) {
                $this->log("resolveProductGid - GraphQL error: " . json_encode($response->errors), LOG_ERR);
                return null;
            }

            if (!empty($response->data->products->edges[0]->node->id)) {
                return $response->data->products->edges[0]->node->id;
            }
        }

        $this->log("resolveProductGid - Aucun produit trouvé pour '$identifier'", LOG_WARNING);
        return null;
    }

    /**
     * Récupère le JSON brut complet d'un produit Shopify pour le support (Story 33.2, #275).
     *
     * Re-fetch en temps réel via GraphQL (jamais REST) : champs produit, options, variantes
     * (avec inventaire, options sélectionnées, metafields), media, tags, metafields produit.
     *
     * @param string $identifier gid / ID numérique / SKU variante / titre produit
     * @return object|null Nœud `product` GraphQL (stdClass) ou null si introuvable/erreur
     * @since 2.3.0
     */
    public function getProductRawJson($identifier)
    {
        $productGid = $this->resolveProductGid($identifier);
        if (empty($productGid)) {
            $this->error = "Produit introuvable pour l'identifiant fourni";
            return null;
        }

        $query = <<<GRAPHQL
query getProductForSupport(\$id: ID!) {
  product(id: \$id) {
    id
    legacyResourceId
    title
    handle
    status
    productType
    vendor
    tags
    createdAt
    updatedAt
    publishedAt
    descriptionHtml
    options { id name position optionValues { id name } }
    media(first: 50) {
      edges { node {
        mediaContentType
        ... on MediaImage { id image { url altText } }
        ... on Video { id sources { url mimeType } }
        ... on ExternalVideo { id host originUrl }
        ... on Model3d { id sources { url mimeType } }
      } }
    }
    metafields(first: 100) {
      edges { node { namespace key value type } }
    }
    variants(first: 250) {
      edges {
        node {
          id
          legacyResourceId
          title
          sku
          barcode
          price
          compareAtPrice
          inventoryQuantity
          inventoryPolicy
          selectedOptions { name value }
          inventoryItem {
            id
            tracked
            harmonizedSystemCode
            countryCodeOfOrigin
            measurement { weight { value unit } }
          }
          metafields(first: 50) { edges { node { namespace key value type } } }
        }
      }
    }
  }
}
GRAPHQL;

        $response = $this->executeGraphQL(['query' => $query, 'variables' => ['id' => $productGid]]);

        if (isset($response->errors)) {
            $this->error = "Erreur GraphQL lors de la récupération du produit";
            $this->log("getProductRawJson - GraphQL error: " . json_encode($response->errors), LOG_ERR);
            return null;
        }

        if (empty($response->data->product)) {
            $this->error = "Produit $productGid introuvable côté Shopify";
            $this->log("getProductRawJson - Réponse vide pour $productGid", LOG_WARNING);
            return null;
        }

        return $response->data->product;
    }

    /**
     * Legacy method for backward compatibility
     * Wraps getOrdersWithPagination() to maintain existing behavior
     *
     * @param array $params Parameters for filtering orders
     * @return array Orders data (simple array for compatibility)
     */
    public function getOrders($params = array())
    {
        $this->log(__METHOD__ . " - Legacy wrapper called", LOG_DEBUG);
        
        $result = $this->getOrdersWithPagination($params);
        
        // Return simple array for backward compatibility
        return $result->orders;
    }

    // ==========================================================
    // COLLECTIONS MANAGEMENT - Added in v2.0.26
    // ==========================================================

    /**
     * Create a new collection in Shopify
     *
     * @param array $collectionData Collection data for creation
     * @return object Response from Shopify
     * @since 2.0.26
     */
    public function collectionCreate($collectionData)
    {
        $this->log(__METHOD__ . " - Creating collection: " . ($collectionData['title'] ?? 'Unknown'), LOG_INFO);

        $mutation = 'mutation collectionCreate($input: CollectionInput!) {
            collectionCreate(input: $input) {
                collection {
                    id
                    title
                    handle
                    legacyResourceId
                    updatedAt
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = ['input' => $collectionData];

        $response = $this->executeGraphQL(['query' => $mutation, 'variables' => $variables]);

        if (!empty($response->data->collectionCreate->userErrors)) {
            $this->log(__METHOD__ . " - Collection creation errors: " . json_encode($response->data->collectionCreate->userErrors), LOG_ERR);
        } else {
            $this->log(__METHOD__ . " - Collection created successfully: ID " . ($response->data->collectionCreate->collection->legacyResourceId ?? 'Unknown'), LOG_INFO);
        }

        return $response;
    }

    /**
     * Update an existing Shopify collection
     *
     * @param string $collectionId Collection ID to update
     * @param array $collectionData Collection data for update
     * @return object Response from Shopify
     * @since 2.0.33
     */
    public function updateCollection($collectionId, $collectionData)
    {
        $this->log(__METHOD__ . " - Updating collection ID: " . $collectionId . " with title: " . ($collectionData['title'] ?? 'Unknown'), LOG_INFO);

        $mutation = 'mutation collectionUpdate($input: CollectionInput!) {
            collectionUpdate(input: $input) {
                collection {
                    id
                    title
                    handle
                    legacyResourceId
                    description
                    image {
                        id
                        url
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        // Add the collection ID to the input data
        $collectionData['id'] = 'gid://shopify/Collection/' . $collectionId;
        $variables = ['input' => $collectionData];

        $response = $this->executeGraphQL(['query' => $mutation, 'variables' => $variables]);

        if (!empty($response->data->collectionUpdate->userErrors)) {
            $this->log(__METHOD__ . " - Collection update errors: " . json_encode($response->data->collectionUpdate->userErrors), LOG_ERR);
        } else {
            $this->log(__METHOD__ . " - Collection updated successfully: " . ($collectionData['title'] ?? 'Unknown'), LOG_INFO);
        }

        return $response;
    }

    /**
     * Get collection details including type (SMART or MANUAL)
     *
     * Story 51-2 (T2) : lit également `sources { __typename }` (champ standard de l'API
     * Admin GraphQL 2026-07, scope read_products, cf. Dev Notes de la story — TRANCHÉ VALIDATE)
     * en complément de `ruleSet` (déprécié en écriture mais toujours lisible). Nécessaire pour
     * détecter correctement les collections créées nativement avec le nouveau modèle
     * `Collection.sources` (sans `ruleSet`), sous peine de faux négatif dans isSmartCollection().
     *
     * Garde-fou : si Shopify répond avec une erreur de champ inconnu (undefinedField) sur
     * `sources` — scope read_products non accordé à l'app installée, ou régression future du
     * schéma — la requête est rejouée sans `sources`, en dégradant proprement sur la détection
     * `ruleSet` seule (comportement historique). Aucune exception ne doit remonter à l'appelant.
     *
     * @param string $collectionId The collection ID (legacy resource ID)
     * @return object|null Collection details or null if not found
     * @since 2.0.31
     * @version     2.6.0 Story 51-2 : ajout lecture `sources` + fallback undefinedField
     */
    public function getCollectionDetails($collectionId)
    {
        $variables = [
            'id' => 'gid://shopify/Collection/' . $collectionId
        ];

        $queryWithoutSources = 'query getCollectionDetails($id: ID!) {
            collection(id: $id) {
                id
                title
                handle
                legacyResourceId
                ruleSet {
                    appliedDisjunctively
                    rules {
                        column
                        condition
                        relation
                    }
                }
                sortOrder
            }
        }';

        // Story 51-2 (Code Review MEDIUM) : si l'indisponibilité de `sources` a déjà été
        // constatée sur ce process POUR CETTE BOUTIQUE (cf. self::$sourcesFieldUnsupported,
        // clé par hostname), ne pas rejouer une requête AVEC `sources` vouée à échouer de
        // nouveau — interroger directement sans, en une seule requête réseau (au lieu de deux).
        if (!empty(self::$sourcesFieldUnsupported[$this->sourcesFieldScopeKey()])) {
            try {
                $response = $this->executeGraphQL(['query' => $queryWithoutSources, 'variables' => $variables]);
            } catch (Exception $e) {
                $this->log(__METHOD__ . " - Exception GraphQL sans sources: " . $e->getMessage(), LOG_ERR);
                return null;
            }

            return !empty($response->data->collection) ? $response->data->collection : null;
        }

        $queryWithSources = 'query getCollectionDetails($id: ID!) {
            collection(id: $id) {
                id
                title
                handle
                legacyResourceId
                ruleSet {
                    appliedDisjunctively
                    rules {
                        column
                        condition
                        relation
                    }
                }
                sources {
                    __typename
                }
                sortOrder
            }
        }';

        $response = null;
        try {
            $response = $this->executeGraphQL(['query' => $queryWithSources, 'variables' => $variables]);
        } catch (Exception $e) {
            // Garde-fou bon marché : ne jamais laisser une exception remonter jusqu'à l'appelant
            // (collectionAddProducts() via isSmartCollection()). Contrairement au cas
            // undefinedField ci-dessous, une exception réseau/config ici n'est PAS rejouée sans
            // `sources` (Code Review LOW — l'ancien commentaire "retente sans sources" était
            // trompeur : aucun retry n'a lieu dans cette branche) : on abandonne et on retourne
            // null, ce qui fait traiter la collection comme MANUAL par isSmartCollection()
            // (repli documenté dans les Dev Notes de la story 51-2).
            $this->log(__METHOD__ . " - Exception GraphQL avec sources: " . $e->getMessage() . " — abandon, retour null (traité comme collection manuelle)", LOG_WARNING);
            return null;
        }

        if ($this->isUndefinedFieldError($response, 'sources')) {
            // Mémorisation par process ET par boutique (Code Review MEDIUM + post-fix HIGH) :
            // évite de rejouer la requête avec `sources` aux appels suivants de
            // getCollectionDetails() sur ce même process, sans impacter les autres boutiques.
            self::$sourcesFieldUnsupported[$this->sourcesFieldScopeKey()] = true;
            $this->log(__METHOD__ . " - Champ 'sources' indisponible (scope read_products manquant ou régression schéma) — repli sur ruleSet seul pour le reste du process (boutique " . $this->sourcesFieldScopeKey() . ")", LOG_WARNING);

            try {
                $response = $this->executeGraphQL(['query' => $queryWithoutSources, 'variables' => $variables]);
            } catch (Exception $e) {
                $this->log(__METHOD__ . " - Exception GraphQL sans sources: " . $e->getMessage(), LOG_ERR);
                return null;
            }
        }

        if (!empty($response->data->collection)) {
            return $response->data->collection;
        }

        return null;
    }

    /**
     * Détecte une erreur GraphQL de type "champ inconnu" (undefinedField) portant sur
     * le champ donné, dans une réponse GraphQL décodée (objet contenant ->errors[]).
     *
     * Story 51-2 (T2.2) : garde-fou bon marché pour le fallback `sources` de
     * getCollectionDetails() — reste utile en cas de scope non accordé ou de régression
     * future du schéma, sans que le champ soit strictement en "preview" (déjà tranché).
     *
     * @param object|null $response Réponse GraphQL décodée (ou null si exception réseau)
     * @param string      $fieldName Nom du champ GraphQL à surveiller (ex. 'sources')
     * @return bool True si une erreur undefinedField concernant ce champ est présente
     * @since 2.4.0
     */
    private function isUndefinedFieldError($response, $fieldName)
    {
        if (empty($response) || empty($response->errors) || !is_array($response->errors)) {
            return false;
        }

        foreach ($response->errors as $gqlError) {
            $code = $gqlError->extensions->code ?? '';
            $message = $gqlError->message ?? '';

            if (strcasecmp((string) $code, 'undefinedField') === 0) {
                // Code Review LOW : matcher en priorité extensions.fieldName (donnée structurée
                // et précise fournie par Shopify), avant le repli texte sur le message libre.
                $extFieldName = $gqlError->extensions->fieldName ?? null;
                if ($extFieldName !== null) {
                    if ((string) $extFieldName === $fieldName) {
                        return true;
                    }
                    // extensions.fieldName renseigné mais ne correspond pas au champ surveillé :
                    // donnée fiable, pas la peine de retomber sur le message texte pour CE champ.
                    continue;
                }

                // Repli message : certains messages Shopify ne portent pas extensions.fieldName
                if (stripos($message, $fieldName) !== false) {
                    return true;
                }
            }

            // Fallback texte générique : certains messages Shopify ne portent pas extensions.code
            if (stripos($message, $fieldName) !== false && stripos($message, "doesn't exist on type") !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if collection is a Smart Collection (automatic)
     *
     * Story 51-2 (T2.3) : la collection est considérée SMART si `ruleSet.rules` est non vide
     * (comportement historique, branche principale) OU si `sources` contient au moins un
     * élément `CollectionConditionsSource`/`CollectionSubCollectionsSource` (nouveau modèle
     * `Collection.sources`, sans `ruleSet`). Repli MANUAL en cas d'incertitude — cf. Dev Notes
     * de la story 51-2 (« Pourquoi le repli par défaut est MANUAL, pas SMART ») : un faux
     * négatif est rattrapé sans casser le flux (userErrors logués par collectionAddProducts()),
     * alors qu'un faux positif bloquerait silencieusement l'ajout de produits sur la majorité
     * des collections manuelles du parc installé.
     *
     * @param string $collectionId The collection ID (legacy resource ID)
     * @return bool True if Smart Collection, false if Manual Collection
     * @since 2.0.31
     * @version     2.6.0 Story 51-2 : tolérance `sources` (CollectionConditionsSource/CollectionSubCollectionsSource)
     */
    public function isSmartCollection($collectionId)
    {
        $collection = $this->getCollectionDetails($collectionId);

        if (!$collection) {
            $this->log(__METHOD__ . " - Collection not found: " . $collectionId, LOG_WARNING);
            return false; // Default to manual if not found
        }

        // Smart collections have ruleSet defined, Manual collections don't (comportement historique)
        $isSmartCollection = !empty($collection->ruleSet) && !empty($collection->ruleSet->rules);

        // Story 51-2 : complément — nouveau modèle Collection.sources sans ruleSet
        if (!$isSmartCollection && !empty($collection->sources) && is_array($collection->sources)) {
            foreach ($collection->sources as $source) {
                $typename = $source->__typename ?? '';
                if ($typename === 'CollectionConditionsSource' || $typename === 'CollectionSubCollectionsSource') {
                    $isSmartCollection = true;
                    break;
                }
            }
        }

        $this->log(__METHOD__ . " - Collection " . $collectionId . " type: " . ($isSmartCollection ? "SMART (automatic)" : "MANUAL"), LOG_INFO);

        return $isSmartCollection;
    }

    /**
     * Add products to a collection (only works with Manual collections)
     *
     * @param string $collectionId The collection ID (legacy resource ID)
     * @param array $productIds Array of product IDs to add
     * @return object GraphQL response
     * @since 2.0.26
     * @version     2.0.33 Added Smart Collection detection to prevent errors
     */
    public function collectionAddProducts($collectionId, $productIds)
    {
        $this->log(__METHOD__ . " - Adding " . count($productIds) . " products to collection: " . $collectionId, LOG_INFO);

        // Check if this is a Smart Collection first
        if ($this->isSmartCollection($collectionId)) {
            $this->log(__METHOD__ . " - Collection " . $collectionId . " is a Smart Collection - skipping manual product addition", LOG_INFO);
            
            // Return a mock successful response to avoid breaking calling code
            return (object) [
                'data' => (object) [
                    'collectionAddProducts' => (object) [
                        'collection' => (object) [
                            'id' => 'gid://shopify/Collection/' . $collectionId,
                            'title' => 'Smart Collection (managed automatically)',
                            'productsCount' => (object) ['count' => 'auto-managed']
                        ],
                        'userErrors' => []
                    ]
                ]
            ];
        }

        // Get existing products in collection to avoid duplicates
        $existingProductIds = $this->getCollectionProducts($collectionId);
        $this->log(__METHOD__ . " - Collection " . $collectionId . " currently has " . count($existingProductIds) . " products", LOG_DEBUG);

        // Filter out products that are already in the collection
        $newProductIds = array_diff($productIds, $existingProductIds);

        if (empty($newProductIds)) {
            $this->log(__METHOD__ . " - All " . count($productIds) . " products are already in collection " . $collectionId, LOG_INFO);

            // Return a successful response since all products are already where they should be
            return (object) [
                'data' => (object) [
                    'collectionAddProducts' => (object) [
                        'collection' => (object) [
                            'id' => 'gid://shopify/Collection/' . $collectionId,
                            'title' => 'Already in collection',
                            'productsCount' => (object) ['count' => count($existingProductIds)]
                        ],
                        'userErrors' => []
                    ]
                ]
            ];
        }

        $this->log(__METHOD__ . " - Adding " . count($newProductIds) . " new products to collection (skipping " . (count($productIds) - count($newProductIds)) . " existing products)", LOG_INFO);

        // Convert product IDs to GIDs if they aren't already
        $productGids = [];
        foreach ($newProductIds as $productId) {
            if (strpos($productId, 'gid://shopify/Product/') === 0) {
                $productGids[] = $productId;
            } else {
                $productGids[] = 'gid://shopify/Product/' . $productId;
            }
        }

        $mutation = 'mutation collectionAddProducts($id: ID!, $productIds: [ID!]!) {
            collectionAddProducts(id: $id, productIds: $productIds) {
                collection {
                    id
                    title
                    productsCount {
                        count
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'id' => 'gid://shopify/Collection/' . $collectionId,
            'productIds' => $productGids
        ];

        $response = $this->executeGraphQL(['query' => $mutation, 'variables' => $variables]);

        if (!empty($response->data->collectionAddProducts->userErrors)) {
            $this->log(__METHOD__ . " - Error adding products to collection: " . json_encode($response->data->collectionAddProducts->userErrors), LOG_ERR);
        } else {
            $this->log(__METHOD__ . " - Products added successfully to collection. Total products: " . ($response->data->collectionAddProducts->collection->productsCount->count ?? 'Unknown'), LOG_INFO);
        }

        return $response;
    }

    /**
     * Get all products currently in a collection
     *
     * @param string $collectionId Collection ID (numeric)
     * @return array Array of product IDs already in the collection
     * @since 2.0.33
     */
    public function getCollectionProducts($collectionId)
    {
        $this->log(__METHOD__ . " - Getting products for collection: " . $collectionId, LOG_DEBUG);

        $query = '
        query getCollectionProducts($id: ID!, $cursor: String) {
            collection(id: $id) {
                products(first: 250, after: $cursor) {
                    nodes {
                        id
                        legacyResourceId
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
        }';

        $productIds = [];
        $cursor = null;
        $pageCount = 0;
        // Story 51-2 (T4) : garde-fou anti-boucle infinie — une collection Shopify ne dépasse
        // jamais quelques dizaines de milliers de produits en pratique ; 200 pages de 250 = 50000
        // produits est une borne large mais finie, au cas où hasNextPage resterait vrai à tort.
        $maxPages = 200;

        do {
            $variables = [
                'id' => 'gid://shopify/Collection/' . $collectionId,
                'cursor' => $cursor
            ];

            $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

            // Code Review MEDIUM : une erreur GraphQL en cours de pagination (page > 1) ne doit
            // plus interrompre silencieusement la boucle — le résultat partiel est conservé et
            // retourné (comportement inchangé) mais désormais signalé explicitement en WARNING.
            // Review externe 51-2 M2 : GraphQL peut renvoyer errors + data PARTIELLES VALIDES
            // (erreur sur un sous-champ, nodes exploitables) — dans ce cas la page est CONSOMMÉE
            // (log INFO) au lieu d'être jetée ; on ne casse la boucle que si les nodes manquent.
            if (!empty($response->errors)) {
                if (isset($response->data->collection->products->nodes)) {
                    $this->log(
                        __METHOD__ . " - erreurs GraphQL partielles page " . ($pageCount + 1)
                        . " (nodes exploitables, page conservée) pour collection " . $collectionId
                        . " : " . json_encode($response->errors),
                        LOG_INFO
                    );
                } else {
                    $this->log(
                        __METHOD__ . " - pagination interrompue page " . ($pageCount + 1) . " — résultat partiel ("
                        . count($productIds) . " produits) pour collection " . $collectionId . " : " . json_encode($response->errors),
                        LOG_WARNING
                    );
                    break;
                }
            }

            if (!isset($response->data->collection->products->nodes)) {
                if ($pageCount === 0) {
                    $this->log(__METHOD__ . " - No products found in collection " . $collectionId, LOG_DEBUG);
                } else {
                    $this->log(
                        __METHOD__ . " - pagination interrompue page " . ($pageCount + 1) . " — résultat partiel ("
                        . count($productIds) . " produits) pour collection " . $collectionId,
                        LOG_WARNING
                    );
                }
                break;
            }

            foreach ($response->data->collection->products->nodes as $product) {
                // Use legacy resource ID for consistency with our code
                $productIds[] = $product->legacyResourceId;
            }

            $pageCount++;
            $pageInfo = $response->data->collection->products->pageInfo ?? null;
            $hasNextPage = !empty($pageInfo->hasNextPage);
            $cursor = $hasNextPage ? ($pageInfo->endCursor ?? null) : null;

            if ($hasNextPage && empty($cursor)) {
                // endCursor absent malgré hasNextPage=true : anomalie schéma, on arrête proprement
                $this->log(__METHOD__ . " - hasNextPage=true mais endCursor absent pour collection " . $collectionId . " — arrêt pagination", LOG_WARNING);
                break;
            }

            if ($hasNextPage && $pageCount >= $maxPages) {
                $this->log(__METHOD__ . " - Garde-fou anti-boucle infinie atteint (" . $maxPages . " pages) pour collection " . $collectionId . " — pagination arrêtée, résultat potentiellement incomplet", LOG_WARNING);
                break;
            }
        } while ($hasNextPage);

        $this->log(__METHOD__ . " - Found " . count($productIds) . " products in collection " . $collectionId . " (" . $pageCount . " page(s))", LOG_DEBUG);

        return $productIds;
    }

    /**
     * Find collection by title
     *
     * @param string $title Collection title to search for
     * @return object|null Collection object or null if not found
     * @since 2.0.26
     */
    public function findCollectionByTitle($title)
    {
        $this->log(__METHOD__ . " - Searching for collection: " . $title, LOG_DEBUG);

        $query = 'query findCollectionByTitle($query: String!) {
            collections(first: 1, query: $query) {
                edges {
                    node {
                        id
                        title
                        handle
                        legacyResourceId
                        updatedAt
                    }
                }
            }
        }';

        $variables = ['query' => 'title:"' . addslashes($title) . '"'];

        $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

        if (!empty($response->data->collections->edges)) {
            $collection = $response->data->collections->edges[0]->node;
            $this->log(__METHOD__ . " - Found collection: " . $title . " (ID: " . $collection->legacyResourceId . ")", LOG_DEBUG);
            return $collection;
        }

        $this->log(__METHOD__ . " - Collection not found: " . $title, LOG_DEBUG);
        return null;
    }

    /**
     * Get all collections with pagination support
     *
     * @param int $limit Maximum number of collections to retrieve
     * @param string|null $cursor Cursor for pagination
     * @return object Response from Shopify with collections and pagination info
     * @since 2.0.26
     */
    public function getCollections($limit = 50, $cursor = null)
    {
        $this->log(__METHOD__ . " - Getting collections (limit: $limit, cursor: " . ($cursor ?: 'none') . ")", LOG_DEBUG);

        $after = $cursor ? ', after: "' . $cursor . '"' : '';

        $query = 'query getCollections {
            collections(first: ' . $limit . $after . ') {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        title
                        handle
                        legacyResourceId
                        updatedAt
                        productsCount {
                            count
                        }
                    }
                }
            }
        }';

        $response = $this->executeGraphQL(['query' => $query]);

        if (!empty($response->data->collections->edges)) {
            $count = count($response->data->collections->edges);
            $this->log(__METHOD__ . " - Retrieved " . $count . " collections", LOG_DEBUG);
        }

        return $response;
    }

    /**
     * Get collection by ID
     *
     * @param string $collectionId Shopify collection ID (numeric or GID format)
     * @return object|null Collection object or null if not found
     * @since 2.0.26
     */
    public function getCollectionById($collectionId)
    {
        // Normalize ID to GID format if needed
        if (is_numeric($collectionId)) {
            $gid = 'gid://shopify/Collection/' . $collectionId;
        } else {
            $gid = $collectionId;
        }

        $this->log(__METHOD__ . " - Getting collection by ID: " . $gid, LOG_DEBUG);

        $query = 'query getCollectionById($id: ID!) {
            collection(id: $id) {
                id
                title
                handle
                legacyResourceId
                updatedAt
                productsCount {
                    count
                }
            }
        }';

        $variables = ['id' => $gid];

        $response = $this->executeGraphQL(['query' => $query, 'variables' => $variables]);

        if (!empty($response->data->collection)) {
            $this->log(__METHOD__ . " - Found collection: " . $response->data->collection->title, LOG_DEBUG);
            return $response->data->collection;
        }

        $this->log(__METHOD__ . " - Collection not found with ID: " . $gid, LOG_DEBUG);
        return null;
    }

    /**
     * Get all sales channels available in the store
     *
     * @return array List of publications with id, name and app info
     * @since 2.0.33
     */
    public function getPublications()
    {
        $this->log(__METHOD__ . " - Retrieving store publications", LOG_INFO);

        $query = 'query {
            publications(first: 50) {
                edges {
                    node {
                        id
                        name
                        app {
                            id
                            title
                        }
                        supportsFuturePublishing
                    }
                }
            }
        }';

        $response = $this->executeGraphQL(['query' => $query]);

        $publications = [];
        if (!empty($response->data->publications->edges)) {
            foreach ($response->data->publications->edges as $edge) {
                $pub = $edge->node;
                $publications[] = [
                    'id' => $pub->id,
                    'name' => $pub->name,
                    'app_id' => $pub->app->id ?? null,
                    'app_title' => $pub->app->title ?? null,
                    'supports_future_publishing' => $pub->supportsFuturePublishing ?? false
                ];
            }
            $this->log(__METHOD__ . " - Found " . count($publications) . " publications", LOG_INFO);
        } else {
            $this->log(__METHOD__ . " - No publications found", LOG_WARNING);
        }

        return $publications;
    }

    /**
     * Publish a collection to specified publications (sales channels)
     *
     * @param string $collectionGid Collection GID (gid://shopify/Collection/xxxxx)
     * @param array $publicationIds Array of publication IDs to publish to
     * @return object|null Response from Shopify API
     * @since 2.0.33
     */
    public function publishCollectionToChannels($collectionGid, $publicationIds)
    {
        if (empty($publicationIds)) {
            $this->log(__METHOD__ . " - No publication IDs provided", LOG_WARNING);
            return null;
        }

        $this->log(__METHOD__ . " - Publishing collection " . $collectionGid . " to " . count($publicationIds) . " channels", LOG_INFO);

        $publications = [];
        foreach ($publicationIds as $pubId) {
            $publications[] = ['publicationId' => $pubId];
        }

        $mutation = 'mutation publishablePublish($id: ID!, $input: [PublicationInput!]!) {
            publishablePublish(id: $id, input: $input) {
                shop {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'id' => $collectionGid,
            'input' => $publications
        ];

        $response = $this->executeGraphQL(['query' => $mutation, 'variables' => $variables]);

        // Check for ACCESS_DENIED errors first
        if (!empty($response->errors)) {
            foreach ($response->errors as $error) {
                if (isset($error->extensions->code) && $error->extensions->code === 'ACCESS_DENIED') {
                    $this->log(__METHOD__ . " - Access denied for publications: write_publications scope required", LOG_WARNING);
                    $this->log(__METHOD__ . " - Collections will be synchronized without automatic channel publication", LOG_INFO);
                    return $response; // Return gracefully without throwing error
                }
            }
        }

        if (!empty($response->data->publishablePublish->userErrors)) {
            $this->log(__METHOD__ . " - Publication errors: " . json_encode($response->data->publishablePublish->userErrors), LOG_ERR);
        } else {
            $this->log(__METHOD__ . " - Collection published successfully to " . count($publicationIds) . " channels", LOG_INFO);
        }

        return $response;
    }

    /**
     * Get shop information
     *
     * @return array|null Shop data or null on error
     * @since 2.1.6
     */
    public function getShopInfo()
    {
        $this->log(__METHOD__ . " - Fetching shop info", LOG_DEBUG);

        $query = '{
            shop {
                id
                name
                email
                primaryDomain {
                    url
                    host
                }
                currencyCode
                timezoneAbbreviation
                ianaTimezone
                unitSystem
                plan {
                    displayName
                }
            }
        }';

        try {
            $response = $this->executeGraphQL(['query' => $query]);

            if (!empty($response->data->shop)) {
                $shop = $response->data->shop;
                return [
                    'id' => $shop->id ?? '',
                    'name' => $shop->name ?? '',
                    'email' => $shop->email ?? '',
                    'domain' => $shop->primaryDomain->host ?? '',
                    'url' => $shop->primaryDomain->url ?? '',
                    'currency' => $shop->currencyCode ?? '',
                    'timezone' => $shop->ianaTimezone ?? '',
                    'unit_system' => $shop->unitSystem ?? '',
                    'plan' => $shop->plan->displayName ?? ''
                ];
            }

            $this->log(__METHOD__ . " - No shop data in response", LOG_WARNING);
            return null;

        } catch (Exception $e) {
            $this->log(__METHOD__ . " - Error: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Get all locations for the shop
     *
     * @return array List of locations
     * @since 2.1.6
     */
    public function getLocations()
    {
        $this->log(__METHOD__ . " - Fetching locations", LOG_DEBUG);

        $query = '{
            locations(first: 50) {
                edges {
                    node {
                        id
                        name
                        address {
                            address1
                            address2
                            city
                            province
                            zip
                            country
                        }
                        isActive
                        isPrimary
                        fulfillsOnlineOrders
                    }
                }
            }
        }';

        try {
            $response = $this->executeGraphQL(['query' => $query]);

            if (!empty($response->data->locations->edges)) {
                $locations = [];
                foreach ($response->data->locations->edges as $edge) {
                    $node = $edge->node;
                    $locations[] = [
                        'id' => $node->id ?? '',
                        'name' => $node->name ?? '',
                        'address1' => $node->address->address1 ?? '',
                        'address2' => $node->address->address2 ?? '',
                        'city' => $node->address->city ?? '',
                        'province' => $node->address->province ?? '',
                        'zip' => $node->address->zip ?? '',
                        'country' => $node->address->country ?? '',
                        'is_active' => $node->isActive ?? false,
                        'is_primary' => $node->isPrimary ?? false,
                        'fulfills_online_orders' => $node->fulfillsOnlineOrders ?? false
                    ];
                }

                $this->log(__METHOD__ . " - Found " . count($locations) . " locations", LOG_DEBUG);
                return $locations;
            }

            $this->log(__METHOD__ . " - No locations found", LOG_WARNING);
            return [];

        } catch (Exception $e) {
            $this->log(__METHOD__ . " - Error: " . $e->getMessage(), LOG_ERR);
            return [];
        }
    }

    /**
     * Get all unique vendors from Shopify products
     *
     * @param int $limit Maximum number of products to scan (default 250)
     * @return array List of unique vendor names sorted alphabetically
     * @since 2.1.6
     */
    public function getVendors($limit = 250)
    {
        $this->log(__METHOD__ . " - Fetching unique vendors", LOG_DEBUG);

        $vendors = [];
        $cursor = null;
        $hasNextPage = true;
        $batchSize = min($limit, 250);
        $totalFetched = 0;

        while ($hasNextPage && $totalFetched < $limit) {
            $afterClause = $cursor ? ', after: "' . $cursor . '"' : '';

            $query = '{
                products(first: ' . $batchSize . $afterClause . ') {
                    edges {
                        node {
                            vendor
                        }
                        cursor
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }';

            try {
                $response = $this->executeGraphQL(['query' => $query]);

                if (!empty($response->data->products->edges)) {
                    foreach ($response->data->products->edges as $edge) {
                        $vendor = trim($edge->node->vendor ?? '');
                        if (!empty($vendor) && !in_array($vendor, $vendors)) {
                            $vendors[] = $vendor;
                        }
                        $cursor = $edge->cursor;
                        $totalFetched++;
                    }
                    $hasNextPage = $response->data->products->pageInfo->hasNextPage ?? false;
                } else {
                    $hasNextPage = false;
                }

            } catch (Exception $e) {
                $this->log(__METHOD__ . " - Error: " . $e->getMessage(), LOG_ERR);
                break;
            }
        }

        // Sort alphabetically
        sort($vendors, SORT_STRING | SORT_FLAG_CASE);

        $this->log(__METHOD__ . " - Found " . count($vendors) . " unique vendors", LOG_DEBUG);
        return $vendors;
    }
}
