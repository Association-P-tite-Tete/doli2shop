<?php

/**
 * @file        class/importproducts.class.php
 * @brief       This file contains the ImportProducts class - Handles synchronization between Dolibarr products and Shopify
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête <doli2shop@ptitetete.com>
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       1.0.0
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/categories/class/categorie.class.php');
dol_include_once('/core/class/commonobject.class.php');
dol_include_once('/product/class/product.class.php');
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/collectionsutils.class.php';
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/dolibarrdirectfileresolver.class.php';
require_once dirname(__FILE__) . '/productscopehelper.class.php';
require_once dirname(__FILE__) . '/skuvariantmatcher.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';


/**
 * Class for handling product import and synchronization with Shopify
 */
class ImportProducts extends CommonObject
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var string Module name */
    public $element = 'importproducts';

    /** @var string Table name */
    public $table_element = 'doli2shop_products';

    /** @var DoliDB Database handler */
    public $db;

    /** @var object Configuration settings */
    private $config;

    /** @var array Products already processed in current session */
    private static $sessionProcessedProducts = [];

    /** @var array Collections cache to avoid multiple API calls - Added in v2.0.26 */
    private static $collectionsCache = [];

    /** @var array Pending collection assignments for batch processing - Added in v2.0.26 */
    private $pendingCollectionAssignments = [];

    /** @var ShopifyApi Shopify API client */
    private $shopifyApi;

    /** @var CollectionsUtils Collections mapping utility */
    private $collectionsUtils;

    /** @var SyncFlowPolicy|null Politique de synchronisation directionnelle (Story 53-5, lazy) */
    private $syncFlowPolicy;

    /** @var DolibarrDirectFileResolver|null Accès direct images produit (Story 57-2, lazy — remplace l'API REST self) */
    private $directFileResolver;

    /** @var ProductScopeHelper|null Périmètre catégorie/entité unifié (Story 63-19, lazy) */
    private $productScopeHelper;

    /** @var array Cache des images par productId pour éviter SQL+API répétés (3 appels syncProductAllImages) */
    private $imagesCache = [];

    /** @var int Current entity */
    public $entity;

    /**
     * @var int FIX v2.4.1 (hotfix) : compteur d'items en échec (userErrors Shopify) sur les
     * mises à jour de stock du cycle en cours — incrémenté par updateInventoryQuantities(),
     * lu par le CRON (ImportProductsCron) pour logger un résumé visible en fin de run au lieu
     * d'échouer en silence (cf. inventorySetQuantities() dans ShopifyApi).
     * Hotfix 2.5.7 : également incrémenté par dropUnresolvedInventoryReferences() pour tout
     * article écarté AVANT tout appel Shopify (référence de stock non résolue à la localisation
     * visée) — même convention, même résumé de cycle, cause différente (jamais envoyé, plutôt
     * que refusé par Shopify).
     * Re-review 27/09/2026 (MEDIUM) : également exposé dans la réponse JSON
     * d'ajax/sync_products_batch.php (champ `stockSyncFailed`), même convention que les
     * compteurs d'images (`imagesResyncForced`/`imagesNoSourceFound`).
     * @since 2.4.1
     */
    public $stockSyncFailedCount = 0;

    /**
     * @var int Hotfix 2.5.7 (review 3 couches 26/09/2026, MEDIUM) : compteur d'échecs
     * STRUCTURELS de lecture de quantité de référence par emplacement — distinct de
     * $stockSyncFailedCount (qui compte des ARTICLES non envoyés, quelle qu'en soit la cause).
     * Incrémenté par fillInventoryReferenceQuantities() UNIQUEMENT quand
     * ShopifyApi::isLocationRelatedGraphQLError() confirme qu'une erreur GraphQL globale désigne
     * réellement la localisation interrogée (emplacement inexistant/invalide côté configuration
     * `shopify_location_id`) — JAMAIS pour un THROTTLED ou toute autre erreur globale
     * transitoire (re-review 27/09/2026, CRITICAL : la première version de ce compteur les
     * confondait). Une seule incrémentation par localisation en échec (pas par item concerné).
     * Lu par le CRON (ImportProductsCron, résumé LOG_ERR de fin de cycle) ET exposé dans la
     * réponse JSON d'ajax/sync_products_batch.php (champ `stockSyncLocationErrors`, re-review
     * 27/09/2026 MEDIUM — auparavant invisible sur l'écran de synchronisation manuelle lui-même,
     * seulement dans les logs/la sortie CRON) : contrairement à $stockSyncFailedCount, ce
     * compteur signale un problème de CONFIGURATION qu'aucun retry ne résoudra.
     * @since 2.5.7
     */
    public $stockSyncLocationErrorCount = 0;

    /**
     * @var int Story variante-sans-correspondance-sku-ignoree-en-silence (AC1/AC2/AC3) : compteur
     * de déclinaisons/variantes non appariées par SKU (les deux sens) sur le cycle en cours —
     * incrémenté par reportUnmatchedVariants(), lu par le CRON (ImportProductsCron) pour un
     * résumé visible, même convention que $stockSyncFailedCount ci-dessus.
     * @since 2.5.5
     */
    public $unmatchedVariantsCount = 0;

    /**
     * @var int Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC3) : compteur des
     * sauts d'envoi d'images sur le cycle en cours (hash inchangé côté Dolibarr ET Shopify
     * confirmé détenir toujours le nombre attendu de médias) — décision normale et fréquente,
     * mais jusqu'ici invisible ailleurs qu'en LOG_INFO noyé dans un journal de plusieurs
     * centaines de Mo. Même convention que $stockSyncFailedCount ci-dessus.
     * @since 2.5.5
     */
    public $imagesSyncSkippedCount = 0;

    /**
     * @var int Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC2/AC3) : compteur des
     * renvois d'images FORCÉS malgré un hash composite identique côté Dolibarr — c'est-à-dire
     * les cas où syncProductAllImages() aurait sauté l'envoi sous l'ancienne logique (Story 8.2)
     * mais où la vérification en direct du nombre de médias Shopify a prouvé que la destination
     * avait divergé (produit/médias supprimés côté Shopify, recréation, restauration de
     * sauvegarde…). C'est EXACTEMENT le symptôme signalé par Xavier Hubier (Europe Loisirs,
     * 13/09/2026) : hash resté identique, 0 image côté Shopify, aucune resynchronisation.
     * @since 2.5.5
     */
    public $imagesResyncForcedCount = 0;

    /**
     * Nombre de produits dont la synchronisation d'images a été ABANDONNÉE parce que le module
     * n'a trouvé aucune image côté Dolibarr (ni dans l'index `llx_ecm_files`, ni sur le disque).
     *
     * Le abandon est légitime — il protège contre la suppression des médias Shopify sans
     * remplacement — mais il était jusqu'ici INVISIBLE : une simple ligne LOG_WARNING noyée dans
     * le journal, aucun compteur, rien à l'écran. Un client dont les produits repartent sans
     * photo n'avait donc aucun moyen de distinguer « le module n'a rien trouvé à envoyer » de
     * « le module a envoyé et Shopify a refusé ».
     *
     * @var int
     * @since 2.5.5
     */
    public $imagesNoSourceFoundCount = 0;

    /**
     * Références des produits comptés par $imagesNoSourceFoundCount, pour le rapport.
     *
     * Bornée à 20 entrées : un rapport doit nommer les produits concernés, pas recopier un
     * catalogue.
     *
     * @var string[]
     * @since 2.5.5
     */
    public $imagesNoSourceFoundRefs = [];

    /**
     * Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : compteur des échecs
     * de CRÉATION de médias Shopify sur le cycle en cours — staged upload en échec total,
     * mutation `productCreateMedia` sans média créé ou avec `mediaUserErrors`, ou média créé
     * avec un statut `FAILED`. Dans TOUS ces cas, l'ordre désormais respecté
     * (créer+vérifier PUIS supprimer) fait qu'AUCUNE suppression des anciens médias Shopify n'a
     * lieu : ils restent en place, et l'empreinte composite n'est PAS mise à jour, pour qu'une
     * nouvelle tentative ait lieu au cycle suivant. Même convention que $imagesNoSourceFoundCount
     * ci-dessus.
     *
     * @var int
     * @since 2.6.0
     */
    public $imagesCreationFailedCount = 0;

    /**
     * Références des produits comptés par $imagesCreationFailedCount, pour le rapport (même
     * convention que $imagesNoSourceFoundRefs — bornée à 20 entrées).
     *
     * @var string[]
     * @since 2.6.0
     */
    public $imagesCreationFailedRefs = [];

    /**
     * @var int MEDIUM (review 3 couches 27/09/2026) : compteur des échecs de SUPPRESSION des
     * anciens médias survenus APRÈS une création intégralement réussie ($creationFullySuccessful
     * === true) — distinct de $imagesCreationFailedCount (qui compte les échecs de CRÉATION).
     * Dans ce cas, le produit se retrouve temporairement avec un doublon (ancien + nouveau média)
     * plutôt qu'un remplacement propre — jamais sans photo, mais à surveiller : un échec de
     * suppression répété peut indiquer un problème d'API/permissions. Même convention que
     * $imagesCreationFailedCount (remise à zéro par cycle, résumé LOG_ERR, CRON).
     * @since 2.6.0
     */
    public $imagesDeleteFailedCount = 0;

    /**
     * Références des produits comptés par $imagesDeleteFailedCount (même convention que
     * $imagesCreationFailedRefs — bornée à 20 entrées).
     *
     * @var string[]
     * @since 2.6.0
     */
    public $imagesDeleteFailedRefs = [];

    /**
     * @var int HIGH (re-review 27/09/2026, point 1) : compteur des produits DIFFÉRÉS sur ce
     * cycle — soit parce que le budget de polling du cycle était DÉJÀ épuisé avant même
     * d'attaquer les images de ce produit (point 1a, aucun appel de création n'a eu lieu), soit
     * parce qu'un lot de médias créé lors d'un cycle précédent est encore en cours de traitement
     * chez Shopify (point 1c, ni READY ni FAILED). Dans les deux cas : AUCUNE suppression, AUCUNE
     * création, empreinte non touchée, nouvelle tentative au cycle suivant — ce n'est PAS un échec
     * ($imagesCreationFailedCount reste réservé aux échecs réels), simplement une décharge de
     * charge, d'où un niveau LOG_INFO et un compteur séparé.
     * @since 2.6.0
     */
    public $imagesDeferredForBudgetCount = 0;

    /**
     * Références des produits comptés par $imagesDeferredForBudgetCount (même convention que
     * $imagesCreationFailedRefs — bornée à 20 entrées).
     *
     * @var string[]
     * @since 2.6.0
     */
    public $imagesDeferredForBudgetRefs = [];

    /**
     * Nombre maximal de tentatives de polling du statut FINAL des médias créés
     * (waitForMediaToBeReady()) — extrait en propriété (au lieu d'un argument par défaut figé)
     * uniquement pour permettre à un test unitaire de réduire le temps d'attente réel d'un
     * scénario de TIMEOUT, sans changer le comportement de production (valeur inchangée : 10).
     *
     * @var int
     * @since 2.6.0
     */
    private $mediaReadyMaxAttempts = 10;

    /**
     * Secondes de pause entre deux tentatives de polling — même raison que
     * $mediaReadyMaxAttempts ci-dessus (valeur de production inchangée : 2).
     *
     * @var int
     * @since 2.6.0
     */
    private $mediaReadySleepSeconds = 2;

    /**
     * Budget de temps CUMULÉ (toutes synchronisations d'images confondues sur CE cycle) alloué au
     * polling du statut final des médias (waitForMediaToBeReady()) — re-review 3 couches
     * 27/09/2026 (point 3). Le cycle CRON lui-même est borné par `set_time_limit(300)`
     * (`ImportProductsCron::runSyncForStore()`, ~:196) : sans ce budget, chaque produit dont le
     * polling atteint le timeout (jusqu'à `mediaReadyMaxAttempts * mediaReadySleepSeconds` ≈ 20s)
     * consomme jusqu'à 20s à lui seul, et un lot de plusieurs produits « à problème » dans le même
     * cycle pourrait épuiser à lui seul tout le temps du script, faisant échouer le reste du cycle
     * (stock, autres produits) sans lien apparent avec les images.
     *
     * @var float Secondes, valeur de production par défaut : 120.
     * @since 2.6.0
     */
    private $pollingBudgetSeconds = 120.0;

    /**
     * Temps de polling déjà consommé sur CE cycle (secondes réelles, `microtime(true)`) — remis à
     * zéro par importProducts() comme les autres compteurs de cycle. Une fois
     * $pollingBudgetUsedSeconds >= $pollingBudgetSeconds, tout produit suivant est traité comme
     * NON PRÊT SANS interroger Shopify (aucune suppression, empreinte non écrite, nouvelle
     * tentative au cycle suivant, compté via $imagesCreationFailedCount — compteur existant).
     *
     * @var float
     * @since 2.6.0
     */
    private $pollingBudgetUsedSeconds = 0.0;

    /**
     * @var int Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3) :
     * compteur d'articles ACTUELLEMENT en situation « référence de stock non résolue depuis
     * DOLI2SHOP_LOCATION_UNRESOLVED_STREAK_CAP cycles consécutifs, plafond atteint » sur le
     * cycle en cours — distinct de $stockSyncFailedCount (qui compte n'importe quel échec, y
     * compris transitoire) et de $stockSyncLocationErrorCount (erreurs GraphQL GLOBALES
     * d'emplacement, pas ce cas — un article simplement jamais activé à l'emplacement ne
     * déclenche AUCUNE erreur GraphQL, juste une absence de niveau de stock, cf.
     * ShopifyApi::getInventoryQuantitiesAtLocation()). Incrémenté par
     * updateLocationUnresolvedStreak(), lu par le CRON (ImportProductsCron) et exposé dans la
     * réponse JSON d'ajax/sync_products_batch.php (champ `stockLocationCapped`), même
     * convention que les compteurs voisins.
     * @since 2.6.0
     */
    public $stockLocationCappedCount = 0;

    /**
     * Constructor
     *
     * @param DoliDB      $db     Database handler
     * @param int|null    $entity Entity to use for configuration (optional)
     * @param object|null $store  Objet boutique (StoreService). Null = chemin historique entité/constantes.
     */
    public function __construct($db, $entity = null, $store = null)
    {
        global $conf;
        $this->db = $db;

        // Determine entity to use
        if ($entity !== null) {
            $this->entity = (int)$entity;
        } else {
            $this->entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;
        }

        $this->log("Using entity: " . $this->entity, LOG_INFO);

        // Create ShopifyApi with explicit entity (+ store si fourni)
        $this->shopifyApi = new ShopifyApi($db, $this->entity, $store);

        // Create CollectionsUtils for mapping management
        $this->collectionsUtils = new CollectionsUtils($db, $this->entity);

        // Check if Shopify API configuration is valid
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
    }

    /**
     * Factory protégée SyncFlowPolicy (mockable en test unitaire — Story 53-5, pattern
     * OrderWebhookHandler::createSyncFlowPolicy() de la Story 53-3).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db, $this->entity);
    }

    /**
     * Instance SyncFlowPolicy paresseuse (une seule par instance — Story 53-5, JAMAIS
     * d'instanciation dans une boucle produits, des milliers d'itérations possibles en CRON export).
     *
     * @return SyncFlowPolicy
     */
    private function getSyncFlowPolicy()
    {
        if ($this->syncFlowPolicy === null) {
            $this->syncFlowPolicy = $this->createSyncFlowPolicy();
        }
        return $this->syncFlowPolicy;
    }

    /**
     * Vérifie qu'un sous-flux d'export (prix, images, collections) est autorisé pour la boutique
     * courante via SyncFlowPolicy — direction dolibarr_to_shopify fixe (ce fichier n'exporte que
     * dans ce sens). Helper factorisé pour les sites 3a/3b/3c (Story 53-5).
     *
     * @param  string $flowId Identifiant SyncFlowPolicy::FLOW_*
     * @return bool
     */
    private function isExportFlowAllowed($flowId)
    {
        return $this->getSyncFlowPolicy()->isAllowed($flowId, 'dolibarr_to_shopify', (int) $this->shopifyApi->getStoreId());
    }

    /**
     * Instance DolibarrDirectFileResolver paresseuse (une seule par instance — Story 57-2,
     * pattern getSyncFlowPolicy() : lecture directe base+disque des images produit, sans
     * appel HTTP à l'API REST Dolibarr self.
     *
     * @return DolibarrDirectFileResolver
     */
    private function getDirectFileResolver()
    {
        if ($this->directFileResolver === null) {
            $this->directFileResolver = new DolibarrDirectFileResolver($this->db, $this->entity);
        }
        return $this->directFileResolver;
    }

    /**
     * Factory protégée Categorie (mockable en test unitaire — pattern createSyncFlowPolicy()) :
     * `uploadCategoryImageToCollection()` (Story 57-7) charge la catégorie via cette factory
     * plutôt qu'un `new Categorie()` direct, pour permettre l'injection d'un mock en test
     * sans dépendre du cycle SQL complet de `Categorie::fetch()`.
     *
     * @return Categorie
     */
    protected function createCategorieInstance()
    {
        return new Categorie($this->db);
    }

    /**
     * Factory protégée ProductScopeHelper (mockable en test unitaire — pattern
     * createSyncFlowPolicy() / createCategorieInstance()) : périmètre catégorie/entité unique
     * (Story 63-19, AC1), partagé avec `admin/sync_products.php`, `ajax/sync_products_batch.php`
     * et `ajax/search_products.php`.
     *
     * @return ProductScopeHelper
     */
    protected function createProductScopeHelper()
    {
        return new ProductScopeHelper($this->db);
    }

    /**
     * Instance ProductScopeHelper paresseuse (une seule par instance).
     *
     * @return ProductScopeHelper
     */
    private function getProductScopeHelper()
    {
        if ($this->productScopeHelper === null) {
            $this->productScopeHelper = $this->createProductScopeHelper();
        }
        return $this->productScopeHelper;
    }

    /**
     * Initialize the synchronization process
     *
     * @return int 0 if success, >0 if error
     */
    public function importProducts()
    {
        global $conf;

        // Task 2.5 : vider le cache d'images en début de cycle CRON batch
        $this->clearImagesCache();
        // FIX v2.4.1 (hotfix) : réinitialiser le compteur d'échecs stock au début de CE cycle
        // (l'instance ImportProducts peut être réutilisée par certains appelants)
        $this->stockSyncFailedCount = 0;
        // Hotfix 2.5.7 : même raison, compteur dédié aux échecs STRUCTURELS d'emplacement sur CE
        // cycle.
        $this->stockSyncLocationErrorCount = 0;
        // Story variante-sans-correspondance-sku-ignoree-en-silence : même raison, compteur de
        // variantes/déclinaisons non appariées par SKU sur CE cycle.
        $this->unmatchedVariantsCount = 0;
        // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi : même raison, compteurs de
        // sauts et de renvois forcés d'images sur CE cycle.
        $this->imagesSyncSkippedCount = 0;
        $this->imagesResyncForcedCount = 0;
        $this->imagesNoSourceFoundCount = 0;
        $this->imagesNoSourceFoundRefs = [];
        $this->imagesCreationFailedCount = 0;
        $this->imagesCreationFailedRefs = [];
        $this->imagesDeleteFailedCount = 0;
        $this->imagesDeleteFailedRefs = [];
        $this->imagesDeferredForBudgetCount = 0;
        $this->imagesDeferredForBudgetRefs = [];
        // Re-review 3 couches 27/09/2026 (point 3) : budget de polling remis à zéro par CYCLE.
        $this->pollingBudgetUsedSeconds = 0.0;
        // Story stock-article-non-active-emplacement-reselection-perpetuelle : même raison,
        // compteur d'articles ACTUELLEMENT plafonnés (persistance au-delà de N cycles) sur CE
        // cycle.
        $this->stockLocationCappedCount = 0;

        $this->db->begin();

        try {
            // 1. Get the list of categories (main category + subcategories) — Story 63-19 (AC1) :
            // arbre complet (racine + descendants), filtré par entité (AC2), calculé par le
            // helper PARTAGÉ avec admin/sync_products.php, ajax/sync_products_batch.php et
            // ajax/search_products.php — une seule définition du périmètre catégorie/entité.
            // v2.3.0: Intervalles de sync configurés (double cycle contenu/stock)
            $this->log("ImportProducts::importProducts - Intervalles sync: contenu=24h, stock=15min (double cycle v2.3.0)", LOG_INFO);

            $categoryIds = $this->getProductScopeHelper()->getCategoryTreeIds((int)$this->config->dolibarr_procate, $this->entity);
            $categoryInClause = ProductScopeHelper::buildCategoryInClause($categoryIds);

            $this->log("Category scope (entity " . $this->entity . "): " . count($categoryIds) . " category id(s) in tree", LOG_INFO);
            // HIGH 3 / HIGH 5 (code review 63-19) : diagnostic partagé — journalise en LOG_WARNING
            // (jamais LOG_INFO, invisible à toute supervision) dès que le périmètre catégorie
            // nécessite une explication : racine non configurée, catégorie introuvable, ou trouvée
            // sous une AUTRE entité que celle utilisée ici (le mismatch confirmé chez Europe Loisirs
            // entre l'entité de `llx_cronjob` et celle de la configuration/des catégories).
            $this->getProductScopeHelper()->logEmptyScopeDiagnostics((int)$this->config->dolibarr_procate, $this->entity, $categoryIds);

            // 2. Get all simple products of the category (not variants, not parents)
            // Prioritize products never synchronized or with failed sync status
            // Story 63-19 (AC2) : filtre p.entity ajouté — absent avant cette story, le CRON
            // balayait les produits de TOUTES les entités dès que leur catégorie tombait dans
            // l'arbre. AC3 : PAS de filtre p.tosell ici (volontaire, cf. class/productscopehelper.class.php
            // en-tête et Dev Notes 63-19) — le garde-fou anti-désarchivage silencieux (Story 58-5)
            // a besoin de voir les produits déjà mappés même hors vente pour les repasser en DRAFT.
            //
            // HIGH 4 (code review) : `p.entity = X` EXCLUT désormais aussi `entity = 0` ("partagé
            // entre entités", convention Dolibarr standard) — avant cette story, l'ABSENCE totale de
            // filtre entity incluait de fait ces produits partagés. Décision : garder l'exclusion
            // stricte, PAR SYMÉTRIE avec les 3 sites écran/AJAX qui filtraient déjà `p.entity =
            // $conf->entity` sans jamais inclure `entity = 0` avant cette story — ce n'est donc pas
            // un comportement nouveau introduit ICI, seulement étendu au 4ᵉ site (CRON). Sans
            // Multicompany actif, `entity = 0` n'existe de toute façon jamais sur `llx_product`/
            // `llx_categorie` pour ce module. AVEC Multicompany et le partage de produits activé,
            // ceci reste une HYPOTHÈSE NON MESURÉE sur le parc (aucune installation connue n'utilise
            // ce partage pour Doli2Shop) — fixée par un test dédié (ProductScopeHelperCategoryTreeSqliteTest)
            // et vérification ouverte en story dédiée (cf. HIGH 6 / docs/implementation-artifacts).
            $sql_products = "SELECT p.*, MAX(sync.last_sync_status) as sync_status, MAX(sync.tms) as sync_tms
                FROM " . MAIN_DB_PREFIX . "product p
                INNER JOIN " . MAIN_DB_PREFIX . "categorie_product cp ON p.rowid = cp.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "doli2shop_products sync
                    ON p.rowid = sync.fk_product
                    AND sync.entity = " . (int)$this->entity . "
                WHERE cp.fk_categorie IN " . $categoryInClause . "
                AND p.entity = " . (int)$this->entity . "
                AND NOT EXISTS (
                    SELECT 1
                    FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac2
                    WHERE pac2.fk_product_parent = p.rowid
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac3
                    WHERE pac3.fk_product_child = p.rowid
                )
                -- v2.3.0: Double cycle — contenu (24h) et stock (15min) indépendants
                -- Remplace le hack v2.2.2 fenêtre 1h : sélectionne si contenu à re-sync (tms > 24h)
                -- OU si stock à rattraper (last_stock_sync NULL ou > 15min, ou intervalle élargi
                -- une fois le plafond de persistance atteint — cf. buildStockCatchupEligibilityClause())
                -- Re-review 27/09/2026 (CRITICAL) : `last_sync_status != 'success'` n'est PLUS une
                -- branche indépendante ici — un lot ENTIÈREMENT écarté (produit simple, ou aucune
                -- déclinaison résolue) écrit 'skipped' à CHAQUE cycle, ce qui resélectionnait le
                -- produit indéfiniment sans aucun égard pour le plafond. Cette condition est
                -- désormais gatée par le plafond DANS buildStockCatchupEligibilityClause().
                -- HIGH (re-review 27/09/2026, point 1b) : OU si les images ont été DIFFÉRÉES
                -- (budget de polling épuisé, ou lot précédent encore en cours) — sans cette
                -- clause, un produit dont le contenu ET le stock sont déjà à jour ne serait
                -- JAMAIS re-sélectionné par ce cycle, même prioritaire, avant l'échéance normale
                -- de 24h/15min.
                AND (sync.last_sync_status IS NULL
                    OR sync.tms < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    OR " . $this->buildStockCatchupEligibilityClause() . "
                    OR sync.images_priority_requeue = 1)
                GROUP BY p.rowid
                ORDER BY
                    CASE
                        WHEN MAX(sync.images_priority_requeue) = 1 THEN 0
                        WHEN MAX(sync.last_sync_status) IS NULL THEN 1
                        WHEN MAX(sync.last_sync_status) = 'failed' THEN 2
                        ELSE 3
                    END,
                    p.rowid";

            $result = SqlUtils::executeQuery($this->db, $sql_products, "getting simple products");

            $this->log("Requête produits simples exécutée - recherche produits non synchronisés ou en échec", LOG_INFO);

            $processedProducts = [];
            $simpleProductsCount = 0;
            while ($productData = $this->db->fetch_object($result)) {
                $simpleProductsCount++;
                if (count($processedProducts) >= $this->config->products_per_cron_update) {
                    $this->log("Limite CRON atteinte (" . $this->config->products_per_cron_update . " produits), arrêt traitement produits simples. " . $simpleProductsCount . " candidats trouvés.", LOG_INFO);
                    break;
                }
                $this->log("Processing simple product: " . $productData->ref . " (ID: " . $productData->rowid . ", tosell: " . $productData->tosell . ")", LOG_DEBUG);

                // Toujours utiliser l'objet Product officiel
                $product = new Product($this->db);
                $fetchResult = $product->fetch($productData->rowid);

                if ($fetchResult <= 0) {
                    $this->log("Failed to fetch product ID: " . $productData->rowid, LOG_ERR);
                    continue; // Passer au produit suivant
                }

                $this->log("Product fetched successfully - ID: " . $product->id . ", status: " . $product->status, LOG_DEBUG);

                try {
                    $syncResult = $this->syncProduct($product);
                    if ($syncResult === true || $syncResult === 'stock_synced') {
                        // Produit synchronisé (contenu ou stock seul) → compte dans la limite CRON
                        $processedProducts[] = [
                            'product' =>  $product,
                            'variants' =>  []
                        ];
                    }
                    // syncResult === 'skipped' → aucun appel API, ne compte PAS dans la limite
                } catch (Exception $e) {
                    $this->log("Error syncing simple product " . $product->ref . ": " . $e->getMessage(), LOG_ERR);
                    // Auto-cleanup: si Shopify dit que le produit n'existe pas, supprimer l'entrée sync
                    // pour qu'il soit recréé proprement à la prochaine exécution
                    if (stripos($e->getMessage(), "Product does not exist") !== false
                        || stripos($e->getMessage(), "does not exist") !== false) {
                        $this->log("Auto-cleanup: removing invalid sync entry for product " . $product->ref . " (ID: " . $product->id . ")", LOG_WARNING);
                        $sqlClean = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products WHERE fk_product = " . (int)$product->id . " AND entity = " . (int)$this->entity;
                        $this->db->query($sqlClean);
                    }
                    // Continue with next product instead of failing entire import
                }
            }
            if ($result) {
                $this->db->free($result);
            }

            $this->log("Fin traitement produits simples - " . $simpleProductsCount . " candidats examinés, " . count($processedProducts) . " produits traités", LOG_INFO);

            // 3. Get all parents products of the category
            // Prioritize products never synchronized or with failed sync status
            // Story 63-19 (AC2) : même filtre p.entity que la requête produits simples ; AC3 :
            // pas de filtre p.tosell, mêmes raisons. HIGH 4 : même décision "entity = 0 exclu",
            // voir le commentaire détaillé sur la requête produits simples ci-dessus.
            $sql_products = "SELECT p.*, MAX(sync.last_sync_status) as sync_status, MAX(sync.tms) as sync_tms
                FROM " . MAIN_DB_PREFIX . "product p
                INNER JOIN " . MAIN_DB_PREFIX . "categorie_product cp ON p.rowid = cp.fk_product
                LEFT JOIN " . MAIN_DB_PREFIX . "doli2shop_products sync
                    ON p.rowid = sync.fk_product
                    AND sync.entity = " . (int)$this->entity . "
                WHERE cp.fk_categorie IN " . $categoryInClause . "
                AND p.entity = " . (int)$this->entity . "
                AND EXISTS (
                    SELECT 1
                    FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac2
                    WHERE pac2.fk_product_parent = p.rowid
                )
                -- v2.3.0: Double cycle — contenu (24h) et stock (15min) indépendants
                -- Remplace le hack v2.2.2 fenêtre 1h : sélectionne si contenu à re-sync (tms > 24h)
                -- OU si stock à rattraper (last_stock_sync NULL ou > 15min, ou intervalle élargi
                -- une fois le plafond de persistance atteint — cf. buildStockCatchupEligibilityClause())
                -- Re-review 27/09/2026 (CRITICAL) : `last_sync_status != 'success'` n'est PLUS une
                -- branche indépendante ici — un lot ENTIÈREMENT écarté (produit simple, ou aucune
                -- déclinaison résolue) écrit 'skipped' à CHAQUE cycle, ce qui resélectionnait le
                -- produit indéfiniment sans aucun égard pour le plafond. Cette condition est
                -- désormais gatée par le plafond DANS buildStockCatchupEligibilityClause().
                -- HIGH (re-review 27/09/2026, point 1b) : même clause qu'au-dessus (produits
                -- simples) — voir son commentaire pour la justification.
                AND (sync.last_sync_status IS NULL
                    OR sync.tms < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    OR " . $this->buildStockCatchupEligibilityClause() . "
                    OR sync.images_priority_requeue = 1)
                GROUP BY p.rowid
                ORDER BY
                    CASE
                        WHEN MAX(sync.images_priority_requeue) = 1 THEN 0
                        WHEN MAX(sync.last_sync_status) IS NULL THEN 1
                        WHEN MAX(sync.last_sync_status) = 'failed' THEN 2
                        ELSE 3
                    END,
                    p.rowid";

            $result = SqlUtils::executeQuery($this->db, $sql_products, "getting parent products");

            $this->log("Requête produits parents exécutée - recherche produits non synchronisés ou en échec", LOG_INFO);

            $parentProductsCount = 0;
            while ($productData = $this->db->fetch_object($result)) {
                $parentProductsCount++;
                if (count($processedProducts) >= $this->config->products_per_cron_update) {
                    $this->log("Limite CRON atteinte (" . $this->config->products_per_cron_update . " produits), arrêt traitement produits parents. " . $parentProductsCount . " candidats trouvés.", LOG_INFO);
                    break;
                }
                $this->log("Processing parent product: " . $productData->ref . " (ID: " . $productData->rowid . ", tosell: " . $productData->tosell . ")", LOG_DEBUG);

                // Toujours utiliser l'objet Product officiel
                $product = new Product($this->db);
                $fetchResult = $product->fetch($productData->rowid);

                if ($fetchResult <= 0) {
                    $this->log("Failed to fetch parent product ID: " . $productData->rowid, LOG_ERR);
                    continue; // Passer au produit suivant
                }

                $this->log("Parent product fetched successfully - ID: " . $product->id . ", status: " . $product->status, LOG_DEBUG);

                try {
                    // Use factorized method to get variants
                    $variants = $this->getProductVariants($product->id);

                    $syncResult = $this->syncProduct($product, $variants, '');
                    if ($syncResult === true || $syncResult === 'stock_synced') {
                        // Produit synchronisé (contenu ou stock seul) → compte dans la limite CRON
                        $processedProducts[] = [
                            'product' => $product,
                            'variants' => $variants
                        ];
                    }
                    // syncResult === 'skipped' → aucun appel API, ne compte PAS dans la limite
                } catch (Exception $e) {
                    $this->log("Error syncing parent product " . $product->ref . ": " . $e->getMessage(), LOG_ERR);
                    // Auto-cleanup: si Shopify dit que le produit n'existe pas, supprimer l'entrée sync
                    if (stripos($e->getMessage(), "Product does not exist") !== false
                        || stripos($e->getMessage(), "does not exist") !== false) {
                        $this->log("Auto-cleanup: removing invalid sync entry for parent product " . $product->ref . " (ID: " . $product->id . ")", LOG_WARNING);
                        $sqlClean = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products WHERE fk_product = " . (int)$product->id . " AND entity = " . (int)$this->entity;
                        $this->db->query($sqlClean);
                    }
                    // Continue with next product instead of failing entire import
                }
            }

            if ($result) {
                $this->db->free($result);
            }

            $this->log("Fin traitement produits parents - " . $parentProductsCount . " candidats examinés, " . (count($processedProducts) - $simpleProductsCount) . " produits parents traités", LOG_INFO);
            $this->log("TOTAL SESSION - " . count($processedProducts) . " produits traités (limite CRON: " . $this->config->products_per_cron_update . ")", LOG_INFO);

            // Story 53-5 (site 3b, 1/2) : gate images export via SyncFlowPolicy (remplace la
            // lecture directe de $this->config->sync_product_images) — vérifié UNE fois avant la
            // boucle, pas par produit (policy lazy, jamais d'instanciation en boucle)
            // 4. For each parent product, synchronize its variants then images (if image sync is enabled)
            if ($this->isExportFlowAllowed(SyncFlowPolicy::FLOW_IMAGES)) {
                foreach ($processedProducts as $parentProduct) {
                    try {
                        if (!empty($parentProduct['variants'])) {
                            $this->syncProductAllImages($parentProduct['product'], $parentProduct['variants']);
                        } else {
                            $this->syncProductAllImages($parentProduct['product']);
                        }
                    } catch (Exception $e) {
                        $this->log("Error syncing images for product " . $parentProduct['product']->ref . ": " . $e->getMessage(), LOG_ERR);
                        // Continue with next product instead of failing entire image sync
                    }
                }
            } else {
                $this->log("Image synchronization is disabled. Skipping all product images.", LOG_INFO);
            }

            // Batch update collections with all pending product assignments (v2.0.26)
            if (!empty($this->pendingCollectionAssignments)) {
                $this->log("Updating collections with batched products...", LOG_INFO);
                $this->batchUpdateCollections();
            }

            // FIX v2.4.1 (hotfix) : résumé visible des échecs de sync stock du cycle (au lieu
            // d'échouer en silence) — lu également par ImportProductsCron::runSyncForStore()
            if ($this->stockSyncFailedCount > 0) {
                $this->log("RÉSUMÉ CYCLE STOCK: " . $this->stockSyncFailedCount
                    . " item(s) en échec de synchronisation stock (voir logs LOG_ERR ci-dessus pour le détail SKU/userErrors)", LOG_ERR);
            }

            // Hotfix 2.5.7 (review 3 couches 26/09/2026) : résumé visible des échecs STRUCTURELS
            // de lecture par emplacement (emplacement Shopify invalide/introuvable) — distinct des
            // simples items en échec ci-dessus : cause probable = configuration boutique erronée,
            // pas un incident réseau transitoire. Lu également par ImportProductsCron::runSyncForStore().
            if ($this->stockSyncLocationErrorCount > 0) {
                $this->log("RÉSUMÉ CYCLE EMPLACEMENT STOCK: " . $this->stockSyncLocationErrorCount
                    . " erreur(s) STRUCTURELLE(S) de lecture de stock par emplacement (verifier shopify_location_id"
                    . " dans la configuration de la boutique — voir logs LOG_ERR ci-dessus)", LOG_ERR);
            }

            // Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3) : résumé
            // visible des articles ACTUELLEMENT plafonnés (référence de stock non résolue depuis
            // N cycles consécutifs) — distinct du compteur ci-dessus (une erreur GraphQL GLOBALE
            // d'emplacement), ce cas ne produit AUCUNE erreur GraphQL, juste une absence de
            // niveau de stock pour l'article. Lu également par ImportProductsCron::runSyncForStore().
            if ($this->stockLocationCappedCount > 0) {
                $this->log("RÉSUMÉ CYCLE EMPLACEMENT PLAFONNÉ: " . $this->stockLocationCappedCount
                    . " article(s) jamais active(s) a l'emplacement Shopify configure, plafond de cycles"
                    . " consecutifs atteint - action manuelle requise cote Shopify (voir logs LOG_WARNING ci-dessus)", LOG_WARNING);
            }

            // Story variante-sans-correspondance-sku-ignoree-en-silence (AC1/AC2/AC3) : résumé
            // visible des variantes/déclinaisons non appariées par SKU du cycle (au lieu de rester
            // invisible dans un diagnostic à "0 erreur") — lu également par
            // ImportProductsCron::runSyncForStore() et persisté en base pour l'écran de diagnostic
            // (admin/diagnostic.php et admin/health.php::checkRecentSyncs()).
            if ($this->unmatchedVariantsCount > 0) {
                $this->log("RÉSUMÉ CYCLE VARIANTES NON APPARIÉES: " . $this->unmatchedVariantsCount
                    . " élément(s) sans correspondance de SKU (voir logs LOG_WARNING ci-dessus pour le détail SKU/produit)", LOG_WARNING);
            }

            // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC3) : résumé visible des
            // sauts d'envoi d'images du cycle (décision normale, mais jusqu'ici invisible ailleurs
            // qu'en LOG_INFO) — lu également par ImportProductsCron::runSyncForStore().
            if ($this->imagesSyncSkippedCount > 0) {
                $this->log("RÉSUMÉ CYCLE IMAGES SAUTÉES: " . $this->imagesSyncSkippedCount
                    . " produit(s) avec images inchangées (hash identique ET nombre de médias Shopify confirmé)", LOG_INFO);
            }
            // (AC2/AC3) : résumé visible des renvois forcés malgré un hash identique — c'est LE
            // signal du symptôme Xavier Hubier (produit/médias supprimés côté Shopify, hash resté
            // identique côté Dolibarr) : un compte à 0 ici ne prouve plus rien sur la destination.
            if ($this->imagesResyncForcedCount > 0) {
                $this->log("RÉSUMÉ CYCLE IMAGES RENVOYÉES APRÈS DIVERGENCE SHOPIFY: " . $this->imagesResyncForcedCount
                    . " produit(s) où le hash Dolibarr était identique mais Shopify ne détenait plus le nombre de médias attendu"
                    . " (voir logs LOG_WARNING ci-dessus pour le détail par produit)", LOG_WARNING);
            }

            // Le cas le plus sournois des trois : ni saut volontaire, ni renvoi forcé, mais
            // ABSENCE DE SOURCE. Il se journalise en LOG_WARNING et nomme les produits, parce
            // qu'un compteur sans références oblige à fouiller le journal pour savoir lesquels.
            if ($this->imagesNoSourceFoundCount > 0) {
                $refsList = implode(', ', $this->imagesNoSourceFoundRefs);
                if ($this->imagesNoSourceFoundCount > count($this->imagesNoSourceFoundRefs)) {
                    $refsList .= ' (+' . ($this->imagesNoSourceFoundCount - count($this->imagesNoSourceFoundRefs)) . ' autre(s))';
                }
                $this->log("RÉSUMÉ CYCLE IMAGES SANS SOURCE DOLIBARR: " . $this->imagesNoSourceFoundCount
                    . " produit(s) dont l'envoi d'images a été abandonné faute d'image trouvée côté Dolibarr"
                    . " (ni index llx_ecm_files, ni disque) — ces produits restent SANS PHOTO côté Shopify."
                    . " Produits concernés : " . $refsList, LOG_WARNING);
            }

            // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : résumé visible
            // des échecs de CRÉATION de médias du cycle — le produit garde ses anciennes photos
            // (rien n'a été supprimé), mais la resynchronisation doit être réessayée au prochain
            // cycle. Même convention que les compteurs d'images ci-dessus.
            if ($this->imagesCreationFailedCount > 0) {
                $refsList = implode(', ', $this->imagesCreationFailedRefs);
                if ($this->imagesCreationFailedCount > count($this->imagesCreationFailedRefs)) {
                    $refsList .= ' (+' . ($this->imagesCreationFailedCount - count($this->imagesCreationFailedRefs)) . ' autre(s))';
                }
                $this->log("RÉSUMÉ CYCLE IMAGES EN ÉCHEC DE CRÉATION: " . $this->imagesCreationFailedCount
                    . " produit(s) dont la création de médias Shopify a échoué (totalement ou partiellement)"
                    . " — anciennes photos PRÉSERVÉES (aucune suppression), nouvelle tentative au prochain cycle."
                    . " Produits concernés : " . $refsList, LOG_ERR);
            }

            // MEDIUM (review 3 couches 27/09/2026) : résumé visible des échecs de SUPPRESSION
            // survenus APRÈS une création réussie — le produit se retrouve avec un doublon
            // (ancien + nouveau média), jamais sans photo, mais mérite d'être surveillé.
            if ($this->imagesDeleteFailedCount > 0) {
                $refsList = implode(', ', $this->imagesDeleteFailedRefs);
                if ($this->imagesDeleteFailedCount > count($this->imagesDeleteFailedRefs)) {
                    $refsList .= ' (+' . ($this->imagesDeleteFailedCount - count($this->imagesDeleteFailedRefs)) . ' autre(s))';
                }
                $this->log("RÉSUMÉ CYCLE IMAGES EN ÉCHEC DE SUPPRESSION: " . $this->imagesDeleteFailedCount
                    . " produit(s) où la suppression des anciens médias a échoué après une création réussie"
                    . " (doublon possible : ancien média non retiré + nouveau média créé)."
                    . " Produits concernés : " . $refsList, LOG_ERR);
            }

            // HIGH (re-review 27/09/2026, point 1) : résumé visible des produits DIFFÉRÉS —
            // budget de polling du cycle déjà épuisé, ou lot d'un cycle précédent encore en
            // cours de traitement chez Shopify. Ni un échec ni une réussite : une décharge de
            // charge, nouvelle tentative garantie EN PRIORITÉ au cycle suivant.
            if ($this->imagesDeferredForBudgetCount > 0) {
                $refsList = implode(', ', $this->imagesDeferredForBudgetRefs);
                if ($this->imagesDeferredForBudgetCount > count($this->imagesDeferredForBudgetRefs)) {
                    $refsList .= ' (+' . ($this->imagesDeferredForBudgetCount - count($this->imagesDeferredForBudgetRefs)) . ' autre(s))';
                }
                $this->log("RÉSUMÉ CYCLE IMAGES DIFFÉRÉES: " . $this->imagesDeferredForBudgetCount
                    . " produit(s) reportés (budget de polling du cycle épuisé, ou lot précédent encore en cours) —"
                    . " traités en PRIORITÉ au prochain cycle. Produits concernés : " . $refsList, LOG_INFO);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            $this->log("Import Error: " . $e->getMessage(), LOG_ERR);
            throw $e;
        }
    }

    /**
     * Détermine la stratégie de synchronisation selon les options cochées
     *
     * @param object $dolProduct Dolibarr product object
     * @param string|null $shopifyProductId Shopify product ID if updating
     * @return array Strategy array with method and fields
     * @since 2.0.32
     */
    private function getSyncOptions()
    {
        // Analyse simple des options activées
        $options = [
            'sync_descriptions' => !empty($this->config->sync_product_descriptions) && $this->config->sync_product_descriptions == 1,
            'sync_prices' => !empty($this->config->sync_product_prices) && $this->config->sync_product_prices == 1,
            'sync_stocks' => !empty($this->config->sync_product_stocks) && $this->config->sync_product_stocks == 1,
            'sync_images' => !empty($this->config->sync_product_images) && $this->config->sync_product_images == 1,
            'sync_attributes' => !empty($this->config->sync_product_attributes) && $this->config->sync_product_attributes == 1,
            'sync_collections' => !empty($this->config->sync_product_collections) && $this->config->sync_product_collections == 1
        ];

        $this->log("Options de synchronisation activées:", LOG_DEBUG);
        $this->log("- Descriptions: " . ($options['sync_descriptions'] ? "OUI" : "NON"), LOG_DEBUG);
        $this->log("- Prix: " . ($options['sync_prices'] ? "OUI" : "NON"), LOG_DEBUG);
        $this->log("- Stocks: " . ($options['sync_stocks'] ? "OUI" : "NON"), LOG_DEBUG);
        $this->log("- Images: " . ($options['sync_images'] ? "OUI" : "NON"), LOG_DEBUG);
        $this->log("- Attributs: " . ($options['sync_attributes'] ? "OUI" : "NON"), LOG_DEBUG);
        $this->log("- Collections: " . ($options['sync_collections'] ? "OUI" : "NON"), LOG_DEBUG);

        return $options;
    }

    /**
     * Récupère le stock d'un produit selon la configuration (réel ou virtuel)
     *
     * @param object $dolProduct Le produit Dolibarr
     * @return int Le stock calculé
     * @since 2.0.32
     */
    private function getProductStock($dolProduct)
    {
        // Utiliser le stock virtuel si configuré, sinon le stock réel
        if (!empty($this->config->use_virtual_stock) && $this->config->use_virtual_stock == 1) {
            return isset($dolProduct->stock_theorique) ? (int)$dolProduct->stock_theorique : 0;
        } else {
            return isset($dolProduct->stock_reel) ? (int)$dolProduct->stock_reel : 0;
        }
    }

    /**
     * Synchronisation STOCK UNIQUEMENT - Ne touche à aucune autre donnée Shopify
     *
     * @param object $dolProduct Dolibarr product object
     * @param string|null $shopifyProductId Shopify product ID if available
     * @return bool True if sync was successful
     * @since 2.0.32
     */
    private function syncInventoryOnly($dolProduct, $shopifyProductId = null)
    {
        $this->log("=== MODE STOCK UNIQUEMENT ===", LOG_INFO);
        $this->log("Synchronisation UNIQUEMENT du stock - Préservation de toutes les données Shopify", LOG_INFO);

        try {
            // Si on n'a pas l'ID produit Shopify, on cherche par SKU
            if (!$shopifyProductId) {
                $this->log("Recherche du produit Shopify par SKU: " . $dolProduct->ref, LOG_DEBUG);
                $shopifyProduct = $this->shopifyApi->findProductBySku($dolProduct->ref);
                if (!$shopifyProduct) {
                    $this->log("Produit introuvable sur Shopify avec SKU: " . $dolProduct->ref, LOG_WARNING);
                    return false;
                }
                $shopifyProductId = $shopifyProduct->legacyResourceId;
            }

            // Récupérer les variantes du produit Shopify par SKU
            $this->log("Récupération des variantes pour le produit Shopify ID: " . $shopifyProductId, LOG_DEBUG);
            $shopifyVariants = $this->shopifyApi->getProductVariants($shopifyProductId);

            if (empty($shopifyVariants)) {
                $this->log("Aucune variante trouvée pour le produit Shopify ID: " . $shopifyProductId, LOG_WARNING);
                return false;
            }

            $stockUpdated = false;

            // Parcourir toutes les variantes et mettre à jour le stock si le SKU correspond
            foreach ($shopifyVariants as $variant) {
                // AC4 (story variante-sans-correspondance-sku-ignoree-en-silence) : un seul test de
                // SKU, SkuVariantMatcher::normalizeSku() — null et '' toujours "pas de SKU".
                $variantSku = SkuVariantMatcher::normalizeSku(isset($variant->sku) ? $variant->sku : null);
                if ($variantSku === null) {
                    continue;
                }

                // Vérifier si cette variante correspond au produit Dolibarr
                if ($variantSku === SkuVariantMatcher::normalizeSku($dolProduct->ref)) {
                    $this->log("Variante trouvée avec SKU correspondant: " . $variant->sku, LOG_DEBUG);

                    // Calculer le stock Dolibarr
                    $stock = $this->getProductStock($dolProduct);
                    $this->log("Stock Dolibarr calculé: " . $stock, LOG_DEBUG);

                    // Utiliser inventorySetQuantities pour une mise à jour directe du stock
                    $success = $this->updateSingleVariantStock($variant, $stock);

                    if ($success) {
                        $this->log("Stock mis à jour avec succès pour SKU: " . $variant->sku . " → " . $stock, LOG_INFO);
                        $stockUpdated = true;
                    } else {
                        $this->log("Échec de la mise à jour du stock pour SKU: " . $variant->sku, LOG_ERR);
                    }
                }
            }

            if ($stockUpdated) {
                $this->log("=== SUCCÈS MODE STOCK UNIQUEMENT ===", LOG_INFO);
                $this->log("Stock synchronisé SANS modifier aucune autre donnée Shopify", LOG_INFO);
                return true;
            } else {
                $this->log("Aucune variante avec SKU correspondant trouvée", LOG_WARNING);
                return false;
            }

        } catch (Exception $e) {
            $this->log("Erreur lors de la synchronisation stock uniquement: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Met à jour uniquement le stock d'une variante spécifique
     *
     * @param object $variant Variant object from Shopify (must have inventoryItemId)
     * @param int $stock New stock quantity
     * @return bool True if update was successful
     * @since 2.0.33
     */
    private function updateSingleVariantStock($variant, $stock)
    {
        $this->log("Mise à jour stock variant SKU: " . ($variant->sku ?? 'unknown') . " → " . $stock, LOG_DEBUG);

        try {
            // Vérifier que la variante a un inventoryItemId
            if (empty($variant->inventoryItemId)) {
                $this->log("Pas d'inventoryItemId pour la variante SKU: " . ($variant->sku ?? 'unknown'), LOG_WARNING);
                return false;
            }

            // Préparer les quantités pour l'API Shopify
            // FIX v2.4.1 (hotfix) : clés harmonisées en snake_case avec inventorySetQuantities()
            // (AVANT : 'inventoryItemId'/'quantity' en camelCase — jamais lues par l'API, qui
            // n'accède qu'à 'inventory_item_id'/'location_id'/'available_adjustment' — l'appel
            // envoyait donc un inventoryItemId vide à Shopify)
            $quantities = [
                [
                    'inventory_item_id' => $variant->inventoryItemId,
                    'location_id' => $this->getShopifyLocationId(), // Utiliser la location par défaut
                    'available_adjustment' => max(0, (int)$stock), // Shopify n'accepte pas les stocks négatifs
                    'sku' => $variant->sku ?? null
                ]
            ];

            // Appeler l'API Shopify pour mettre à jour l'inventaire
            $response = $this->shopifyApi->inventorySetQuantities($quantities);

            // Vérifier la réponse
            // FIX v2.4.1 (hotfix) : l'ancienne clé 'inventorySetQuantities' n'a jamais existé dans
            // la réponse GraphQL (le champ retourné porte le nom de la mutation réellement
            // exécutée) — ce check était donc toujours faux et les userErrors jamais détectées.
            // inventorySetQuantities() retourne désormais une réponse agrégée normalisée
            // exposant directement `userErrors` (fusion des 2 chemins delta/fallback).
            if (!empty($response->userErrors)) {
                $errors = [];
                foreach ($response->userErrors as $error) {
                    $errors[] = $error->field . ': ' . $error->message;
                }
                $this->stockSyncFailedCount++;
                $this->log("Erreurs API Shopify pour mise à jour stock (SKU: " . ($variant->sku ?? 'unknown') . "): " . implode(', ', $errors), LOG_ERR);
                return false;
            }

            $this->log("Stock variant mis à jour avec succès: " . ($variant->sku ?? 'unknown') . " → " . $stock, LOG_INFO);
            return true;

        } catch (Exception $e) {
            $this->log("Exception lors de la mise à jour du stock variant: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Récupère l'ID de la location Shopify par défaut
     *
     * @return string Location ID
     * @since 2.0.33
     */
    private function getShopifyLocationId()
    {
        // Utiliser la même config que le reste du module (cohérence avec syncVariantStocksOnly, updateVariantInventory)
        if (!empty($this->config->shopify_location_id)) {
            return 'gid://shopify/Location/' . $this->config->shopify_location_id;
        }

        // Fallback sur la constante globale
        $locationId = getDolGlobalString('DOLI2SHOP_LOCATION_ID', '');
        if (!empty($locationId)) {
            return 'gid://shopify/Location/' . $locationId;
        }

        $this->log("ERREUR: Aucun shopify_location_id configuré — stock sync impossible", LOG_ERR);
        return '';
    }

    /**
     * Story stock-article-non-active-emplacement-reselection-perpetuelle (AC1) : plafond de
     * cycles CONSÉCUTIFS où un article peut rester non résolu (référence de stock illisible à
     * l'emplacement configuré) avant que le produit ne soit considéré comme "plafonné" (retenté
     * à intervalle élargi, plus à chaque cycle). Configurable, défaut raisonnable de l'ordre de
     * quelques cycles (proposition story : 4 à 6, soit ~1h à 1h30 à 15 minutes/cycle).
     *
     * @return int
     * @since 2.6.0
     */
    private function getLocationUnresolvedStreakCap()
    {
        return max(1, (int) getDolGlobalInt('DOLI2SHOP_LOCATION_UNRESOLVED_STREAK_CAP', 5));
    }

    /**
     * Story stock-article-non-active-emplacement-reselection-perpetuelle (AC2) : intervalle
     * élargi (en heures) auquel un produit "plafonné" reste malgré tout retenté — jamais une
     * suppression silencieuse de la synchronisation, juste beaucoup moins souvent tant qu'aucune
     * action manuelle n'a été faite côté Shopify.
     *
     * @return int
     * @since 2.6.0
     */
    private function getLocationUnresolvedRetryHours()
    {
        return max(1, (int) getDolGlobalInt('DOLI2SHOP_LOCATION_UNRESOLVED_RETRY_HOURS', 6));
    }

    /**
     * Story stock-article-non-active-emplacement-reselection-perpetuelle (AC2) : fragment SQL
     * d'éligibilité "stock à rattraper", utilisé PAR LES DEUX requêtes de sélection candidates de
     * importProducts() (produits simples ET produits parents — même fragment, jamais dupliqué à
     * la main, pour ne jamais risquer de diverger entre les deux).
     *
     * Sous le plafond (`location_unresolved_streak < cap`) : comportement STRICTEMENT INCHANGÉ,
     * fenêtre de 15 minutes comme avant cette story (non-régression du cas transitoire, AC4).
     * Au-delà du plafond : fenêtre élargie (`getLocationUnresolvedRetryHours()`) — le produit
     * reste sélectionnable (jamais une suppression silencieuse de la synchronisation) mais
     * beaucoup moins souvent, le temps qu'une action manuelle soit faite côté Shopify.
     *
     * Re-review 27/09/2026 (CRITICAL) : quand TOUT le lot d'un produit est écarté (aucune
     * variante résolue à l'emplacement — le cas même de cette story pour un produit SIMPLE, ou un
     * produit parent dont AUCUNE déclinaison ne matche), `syncProduct()` écrit
     * `last_sync_status = 'skipped'` sur la ligne parent à CHAQUE cycle
     * (importproducts.class.php ~1450). Les deux requêtes de sélection portaient jusqu'ici une
     * branche INDÉPENDANTE `OR sync.last_sync_status != 'success'`, qui resélectionnait ce produit
     * à chaque cycle SANS AUCUN égard pour `location_unresolved_streak` : le plafond n'avait alors
     * strictement aucun effet sur le cas qui motive cette story. Ce fragment intègre désormais
     * cette condition, mais UNIQUEMENT gatée par le plafond (`COALESCE(..., 0) < cap`) : tant que
     * le produit n'est PAS plafonné, un statut différent de 'success' (erreur réseau, produit
     * jamais synchronisé avec succès, etc.) continue de déclencher une resélection IMMÉDIATE,
     * exactement comme avant cette story — seul le cas "plafonné" est ralenti. `COALESCE` protège
     * une ligne sans compteur persisté (NULL par LEFT JOIN) — cas déjà couvert par ailleurs par
     * `sync.last_sync_status IS NULL` (branche indépendante, hors de ce fragment), mais gardé ici
     * par défense en profondeur : "NULL < cap" vaudrait NULL (donc faux) sans ce COALESCE.
     *
     * @return string Fragment SQL déjà entre parenthèses, à combiner par OR avec les autres
     *                conditions de re-sync (statut IS NULL, contenu 24h).
     * @since 2.6.0
     */
    private function buildStockCatchupEligibilityClause()
    {
        $cap = $this->getLocationUnresolvedStreakCap();
        $retryHours = $this->getLocationUnresolvedRetryHours();

        return "("
            . "(sync.last_sync_status != 'success' AND COALESCE(sync.location_unresolved_streak, 0) < " . (int) $cap . ")"
            . " OR (COALESCE(sync.location_unresolved_streak, 0) < " . (int) $cap
            . " AND (sync.last_stock_sync IS NULL OR sync.last_stock_sync < DATE_SUB(NOW(), INTERVAL 15 MINUTE)))"
            . " OR (COALESCE(sync.location_unresolved_streak, 0) >= " . (int) $cap
            . " AND sync.last_stock_sync < DATE_SUB(NOW(), INTERVAL " . (int) $retryHours . " HOUR))"
            . ")";
    }

    /**
     * Story stock-article-non-active-emplacement-reselection-perpetuelle : persiste ou
     * réinitialise le compteur de cycles CONSÉCUTIFS où au moins un article de ce produit a été
     * écarté pour référence de stock non résolue (`dropUnresolvedInventoryReferences()`).
     *
     * ⚠️ Porté EXCLUSIVEMENT par la ligne du produit PARENT/SIMPLE (`fk_product = $parentProductId`,
     * TOUJOURS `$dolProduct->id`, jamais l'id d'une déclinaison individuellement écartée) — c'est
     * cette ligne, et uniquement elle, qui gouverne `sync.last_stock_sync` dans la clause de
     * sélection SQL du cron (cf. buildStockCatchupEligibilityClause(), Validate 27/09/2026,
     * réserve #1). Poser ce compteur sur la ligne d'une déclinaison ne changerait rien à la
     * re-sélection perpétuelle du produit entier.
     *
     * Invariant multi-boutiques (CLAUDE.dolibarr.md §14, même motif que clearOffsaleActionStatus()
     * et les stamps last_stock_sync voisins de syncVariantStocksOnly()) : filtre `fk_store`
     * conditionnel — appliqué seulement si la boutique courante en a un (`> 0`), aucun filtre en
     * mono-boutique/chemin historique (`fk_store == 0`), et jamais d'écrasement d'un `fk_store > 0`
     * par 0.
     *
     * Résolution (Validate 27/09/2026, réserve #3) : dès que ce lot n'a PLUS d'article écarté
     * (incident transitoire réglé, ou activation Shopify faite entre-temps), le compteur est remis
     * à 0 et le produit retrouve la cadence normale — le plafond ne doit JAMAIS devenir un blocage
     * permanent.
     *
     * Re-review 27/09/2026 (LOW) : lecture-modification-écriture (SELECT puis UPDATE), PAS une
     * écriture atomique (`SET location_unresolved_streak = LEAST(location_unresolved_streak + 1,
     * cap)`) — choix délibéré, pas un oubli. Une écriture purement atomique perdrait la valeur
     * `$currentStreak` AVANT incrément, nécessaire pour détecter le franchissement EXACT du
     * plafond (`$currentStreak < $cap` ligne plus bas) qui déclenche l'alerte one-shot : la
     * récupérer nécessiterait soit une variable de session MySQL dans l'UPDATE (fragile, ordre
     * d'évaluation non garanti selon la version du moteur), soit un `SELECT ... FOR UPDATE`
     * verrouillant. Cette lecture-modification-écriture reste SÛRE sans verrou explicite parce que
     * `syncProduct()` (l'appelant, en amont de `syncVariantStocksOnly()`) acquiert déjà un verrou
     * PAR PRODUIT via la colonne `sync_lock` de cette même table AVANT tout appel à cette méthode
     * (`isSyncLocked()` ~ligne 2040, posé par `manageProductMapping(..., 'pending', ...)` juste
     * après, cf. ~ligne 1368-1380) : un second process qui tenterait de synchroniser CE MÊME
     * produit pendant que ce SELECT/UPDATE est en cours serait rejeté par ce verrou AVANT même
     * d'atteindre `syncVariantStocksOnly()`. Aucune autre méthode n'écrit
     * `location_unresolved_streak` (grep vérifié) : pas de concurrence possible sur la même ligne.
     *
     * @param  int  $parentProductId ID Dolibarr du produit PARENT ou SIMPLE (jamais une déclinaison)
     * @param  bool $anyItemDropped  true si au moins un article du lot a été écarté ce cycle
     * @param  int  $fkStore         ShopifyApi::getStoreId() courant (0 = chemin historique mono-boutique)
     * @return void
     * @since  2.6.0
     */
    private function updateLocationUnresolvedStreak($parentProductId, $anyItemDropped, $fkStore)
    {
        $parentProductId = (int) $parentProductId;
        $fkStore = (int) $fkStore;

        $sqlSelect = "SELECT location_unresolved_streak FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE fk_product = ? AND entity = ?";
        $paramsSelect = [$parentProductId, (int) $this->entity];
        if ($fkStore > 0) {
            $sqlSelect .= " AND fk_store = ?";
            $paramsSelect[] = $fkStore;
        }

        $currentStreak = 0;
        $result = SqlUtils::executeQuery($this->db, $sqlSelect, "reading location_unresolved_streak for product " . $parentProductId, false, $paramsSelect);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            if ($obj !== null && isset($obj->location_unresolved_streak)) {
                $currentStreak = (int) $obj->location_unresolved_streak;
            }
            $this->db->free($result);
        }

        if (!$anyItemDropped) {
            // Résolution : retour à la cadence normale, jamais un blocage permanent (réserve #3).
            if ($currentStreak !== 0) {
                $sqlReset = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
                    . " SET location_unresolved_streak = 0 WHERE fk_product = ? AND entity = ?";
                $paramsReset = [$parentProductId, (int) $this->entity];
                if ($fkStore > 0) {
                    $sqlReset .= " AND fk_store = ?";
                    $paramsReset[] = $fkStore;
                }
                $resetResult = SqlUtils::executeQuery($this->db, $sqlReset, "resetting location_unresolved_streak for product " . $parentProductId, false, $paramsReset);
                // Re-review 27/09/2026 (LOW) : la ligne parent peut ne plus exister pour CE
                // fk_store (produit supprimé entre-temps, ou fk_store désaligné) — ce n'est jamais
                // bloquant (comportement inchangé), mais ça restait jusqu'ici totalement
                // silencieux. LOG_DEBUG uniquement : ni WARNING (pas une anomalie en soi), ni
                // bruit en production (niveau DEBUG seulement).
                if ($resetResult && (int) $this->db->affected_rows($resetResult) === 0) {
                    $this->log("updateLocationUnresolvedStreak - reset : 0 ligne affectee pour le produit "
                        . $parentProductId . " (fk_store=" . $fkStore . ") - ligne parent absente pour ce fk_store ?", LOG_DEBUG);
                }
            }
            return;
        }

        $cap = $this->getLocationUnresolvedStreakCap();
        // Fige la valeur affichée au plafond une fois atteint (pas de croissance indéfinie).
        $newStreak = min($currentStreak + 1, $cap);

        $sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
            . " SET location_unresolved_streak = ? WHERE fk_product = ? AND entity = ?";
        $paramsUpdate = [$newStreak, $parentProductId, (int) $this->entity];
        if ($fkStore > 0) {
            $sqlUpdate .= " AND fk_store = ?";
            $paramsUpdate[] = $fkStore;
        }
        $updateResult = SqlUtils::executeQuery($this->db, $sqlUpdate, "updating location_unresolved_streak for product " . $parentProductId, false, $paramsUpdate);
        // Re-review 27/09/2026 (LOW) : même raison que pour le reset ci-dessus — 0 ligne affectée
        // (ligne parent absente pour ce fk_store) ne doit jamais rester totalement silencieux.
        if ($updateResult && (int) $this->db->affected_rows($updateResult) === 0) {
            $this->log("updateLocationUnresolvedStreak - increment : 0 ligne affectee pour le produit "
                . $parentProductId . " (fk_store=" . $fkStore . ") - ligne parent absente pour ce fk_store ?", LOG_DEBUG);
        }

        if ($newStreak >= $cap) {
            $this->stockLocationCappedCount++;
            if ($currentStreak < $cap) {
                // Franchissement du plafond CE cycle : alerte one-shot, jamais répétée les
                // cycles suivants (story : "LOG_WARNING actionnable une seule fois au passage du
                // plafond, pas à chaque cycle").
                $this->log("Produit ID " . $parentProductId . " : plafond de " . $cap
                    . " cycle(s) consecutif(s) atteint pour une reference de stock non resolue "
                    . "(article probablement jamais active a l'emplacement Shopify configure pour "
                    . "cette boutique) - action manuelle requise cote Shopify (Locations de la fiche "
                    . "produit) ; ce produit ne sera plus retente qu'a intervalle elargi ("
                    . $this->getLocationUnresolvedRetryHours() . "h) jusqu'a resolution.", LOG_WARNING);
            }
        }
    }

    /**
     * FIX v2.2.0: Synchronise UNIQUEMENT les stocks des variantes quand le produit est skippé
     *
     * Quand checkProductNeedsUpdate() retourne false (contenu produit inchangé),
     * les stocks doivent quand même être envoyés à Shopify car ils changent indépendamment.
     *
     * @param object $dolProduct Dolibarr parent product
     * @param string $shopifyProductId Shopify product ID
     * @param array $variants Dolibarr product variants
     * @return bool True si au moins un stock a été envoyé
     * @since 2.2.0
     */
    private function syncVariantStocksOnly($dolProduct, $shopifyProductId, $variants = [])
    {
        try {
            // Validation shopify_location_id AVANT tout traitement
            if (empty($this->config->shopify_location_id)) {
                $this->log("syncVariantStocksOnly - shopify_location_id not configured, cannot sync stocks for " . $dolProduct->ref, LOG_ERR);
                return false;
            }

            // Récupérer les variantes Shopify pour avoir les inventoryItemId
            $shopifyVariants = $this->shopifyApi->getProductVariants($shopifyProductId);
            if (empty($shopifyVariants)) {
                $this->log("syncVariantStocksOnly - No Shopify variants found for " . $dolProduct->ref . " (shopifyProductId=" . $shopifyProductId . ")", LOG_WARNING);
                return false;
            }

            $inventoryQuantities = [];
            $locationId = 'gid://shopify/Location/' . $this->config->shopify_location_id;

            // Construire un index SKU → variante Shopify
            // AC4 (story variante-sans-correspondance-sku-ignoree-en-silence) : un seul test de
            // SKU, SkuVariantMatcher::normalizeSku() — null et '' toujours "pas de SKU".
            $shopifyBySku = [];
            foreach ($shopifyVariants as $sv) {
                $svSku = SkuVariantMatcher::normalizeSku(isset($sv->sku) ? $sv->sku : null);
                if ($svSku !== null) {
                    $shopifyBySku[$svSku] = $sv;
                }
            }

            // Produits à vérifier : parent (si produit simple) + variantes enfants
            $productsToCheck = [];
            if (empty($variants)) {
                $productsToCheck[] = $dolProduct;
            } else {
                foreach ($variants as $v) {
                    $varProduct = new Product($this->db);
                    if ($varProduct->fetch($v->id ?? $v->rowid ?? 0) > 0) {
                        $productsToCheck[] = $varProduct;
                    }
                }
            }

            $matchCount = 0;
            foreach ($productsToCheck as $prod) {
                if (!isset($shopifyBySku[$prod->ref])) {
                    $this->log("syncVariantStocksOnly - No Shopify variant match for SKU: " . $prod->ref, LOG_WARNING);
                    continue;
                }
                $sv = $shopifyBySku[$prod->ref];
                // getProductVariants() retourne inventoryItemId (flat), productSet retourne inventoryItem->id (nested)
                $invItemId = !empty($sv->inventoryItemId) ? $sv->inventoryItemId : (!empty($sv->inventoryItem->id) ? $sv->inventoryItem->id : null);
                if (empty($invItemId)) {
                    $this->log("syncVariantStocksOnly - No inventoryItemId for SKU: " . $prod->ref, LOG_WARNING);
                    continue;
                }

                // Charger le stock
                if (method_exists($prod, 'load_stock')) {
                    $prod->load_stock('warehouseopen,warehouseinternal');
                }
                $stock = $this->getProductStock($prod);

                $inventoryItemId = str_replace('gid://shopify/InventoryItem/', '', $invItemId);
                // Hotfix 2.5.7 (RÈGLE UNIQUE, Validate 26/09/2026) : ne JAMAIS poser
                // `current_shopify_quantity` depuis `ProductVariant.inventoryQuantity` — ce champ
                // est un TOTAL toutes localisations Shopify confondues, alors que le chemin delta
                // (`inventoryAdjustQuantities`) compare `changeFromQuantity` à `available` À LA
                // LOCALISATION VISÉE. Sur une boutique à plusieurs emplacements, les deux
                // divergent : Shopify refuse alors la mutation ("no longer matches the persisted
                // quantity"). La SEULE source de cette clé est désormais la lecture par
                // localisation ci-dessous (fillInventoryReferenceQuantities()).
                //
                // 'dol_product_id' (review 3 couches 26/09/2026, CRITICAL) : permet, après le
                // filtrage ci-dessous, de ne stamper `last_stock_sync` QUE pour les produits dont
                // l'article a réellement été envoyé — jamais pour un article écarté.
                $inventoryQuantities[] = [
                    'inventory_item_id' => 'gid://shopify/InventoryItem/' . $inventoryItemId,
                    'location_id' => $locationId,
                    'available_adjustment' => $stock,
                    'sku' => $prod->ref,
                    'dol_product_id' => (int) $prod->id
                ];
                $matchCount++;
            }

            // Hotfix 2.5.7 (review 3 couches 26/09/2026, CRITICAL) : mémorise si le filtrage a
            // ÉCARTÉ au moins un article de ce lot — condition la mise à jour de la ligne de
            // bookkeeping du PRODUIT PARENT lui-même (fk_product = dolProduct->id) plus bas :
            // cette ligne est celle qui gouverne l'éligibilité de TOUT le produit (parent +
            // variantes) au prochain cycle dans les requêtes SQL de sélection de importProducts()
            // (clause `sync.last_stock_sync`). La rafraîchir alors qu'un article a été écarté
            // repousserait le réexamen du produit ENTIER de 15 minutes, alors que l'article écarté
            // doit être retenté dès le cycle suivant.
            $anyItemDropped = false;
            $locationStreakTrackingApplicable = false;
            if (!empty($inventoryQuantities)) {
                $locationStreakTrackingApplicable = true;
                $countBeforeDrop = count($inventoryQuantities);

                // Hotfix 2.5.7 : résout current_shopify_quantity (available) et
                // on_hand_shopify_quantity (on_hand) EN LES LISANT À LA LOCALISATION VISÉE — même
                // méthode privée déjà utilisée et testée par updateVariantInventory() (2.5.5).
                $this->fillInventoryReferenceQuantities($inventoryQuantities);

                // Hotfix 2.5.7 : tout article dont NI current_shopify_quantity NI
                // on_hand_shopify_quantity n'a pu être lu à cette localisation N'EST PAS envoyé ce
                // cycle-ci (ni en delta avec une référence fausse, ni en repli avec une référence
                // non résolue à 0 fabriqué) — journalisé, compté, retenté au cycle suivant.
                $inventoryQuantities = $this->dropUnresolvedInventoryReferences($inventoryQuantities, 'syncVariantStocksOnly');

                $anyItemDropped = (count($inventoryQuantities) < $countBeforeDrop);
            }

            // Story stock-article-non-active-emplacement-reselection-perpetuelle : persiste ou
            // réinitialise le compteur de persistance sur la ligne PARENT/SIMPLE, AVANT de savoir
            // si ce lot a pu être envoyé (couvre aussi bien le cas "mixte" ci-dessous que le cas
            // "tout écarté", qui retourne plus bas SANS jamais entrer dans le bloc d'envoi).
            // `$locationStreakTrackingApplicable` exclut le cas "aucune correspondance SKU du
            // tout" (matchCount == 0 plus bas) — une classe de défaut différente (déjà couverte
            // par $unmatchedVariantsCount), pas une référence de stock non résolue.
            if ($locationStreakTrackingApplicable) {
                $fkStoreForStreak = (int) $this->shopifyApi->getStoreId();
                $this->updateLocationUnresolvedStreak((int) $dolProduct->id, $anyItemDropped, $fkStoreForStreak);
            }

            if (!empty($inventoryQuantities)) {
                $this->log("syncVariantStocksOnly - Sending " . count($inventoryQuantities) . " stock updates for skipped product " . $dolProduct->ref, LOG_INFO);
                $stockResult = $this->updateInventoryQuantities($inventoryQuantities);

                if (!$stockResult) {
                    $this->log("syncVariantStocksOnly - updateInventoryQuantities FAILED for " . $dolProduct->ref, LOG_ERR);
                    return false;
                }

                // v2.3.0 (Epic 32) : ce chemin pousse UNIQUEMENT le stock — il ne doit PAS
                // toucher `tms` (réservé au cycle de sync CONTENU). Sinon chaque rattrapage stock
                // repousserait la fenêtre 24h et la re-sync contenu périodique ne se déclencherait
                // jamais pour les produits à fort mouvement. Seul `last_stock_sync` est mis à jour.

                // Hotfix 2.5.7 (review 3 couches 26/09/2026, CRITICAL) : AVANT, ce UPDATE de masse
                // (`WHERE fk_product_parent = ?`) stampait TOUTES les variantes de ce produit dès
                // qu'au moins UN article avait survécu au filtrage — y compris les variantes dont
                // l'article avait été ÉCARTÉ (référence non résolue). Elles n'auraient alors plus
                // jamais été retentées avant l'expiration normale des 15 minutes. Désormais, seuls
                // les produits Dolibarr dont l'article a réellement été envoyé (via `dol_product_id`,
                // collecté à la construction du lot ci-dessus) sont stampés.
                //
                // Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : invariant multi-boutiques
                // (CLAUDE.dolibarr.md §14, même motif que clearOffsaleActionStatus()) — filtrer par
                // `fk_store` dès que la boutique courante en a un (> 0), sans AUCUN filtre en
                // mono-boutique (fk_store == 0, chemin historique) pour ne pas faire disparaître les
                // lignes backfillées.
                $fkStore = (int) $this->shopifyApi->getStoreId();

                $syncedProductIds = array_values(array_unique(array_filter(array_map(
                    static function ($iq) {
                        return (int) ($iq['dol_product_id'] ?? 0);
                    },
                    $inventoryQuantities
                ))));

                if (!empty($syncedProductIds)) {
                    $placeholders = implode(',', array_fill(0, count($syncedProductIds), '?'));
                    $sqlUpdateStockVariants = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
                        . " SET last_stock_sync = NOW() WHERE fk_product IN (" . $placeholders . ") AND entity = ?";
                    $paramsStockVariants = array_merge($syncedProductIds, [(int) $this->entity]);
                    if ($fkStore > 0) {
                        $sqlUpdateStockVariants .= " AND fk_store = ?";
                        $paramsStockVariants[] = $fkStore;
                    }
                    SqlUtils::executeQuery(
                        $this->db,
                        $sqlUpdateStockVariants,
                        "updating synced products last_stock_sync after stock sync",
                        true,
                        $paramsStockVariants
                    );
                }

                // Hotfix 2.5.7 (review 3 couches 26/09/2026, CRITICAL) : la ligne de bookkeeping du
                // PARENT lui-même (fk_product = dolProduct->id) — qui gouverne l'éligibilité de
                // TOUT le produit au prochain cycle, cf. commentaire plus haut — n'est rafraîchie
                // QUE si aucun article de ce lot n'a été écarté. Sinon, elle reste stale : le
                // produit entier (parent + variante écartée) sera reconsidéré dès le cycle suivant.
                //
                // Hotfix 2.5.7 (re-review 27/09/2026, LOW) : produit SIMPLE (sans variante) — son
                // propre id est déjà dans $syncedProductIds (déjà stampé par l'UPDATE IN(...)
                // ci-dessus) : ne pas le stamper une seconde fois avec un UPDATE identique.
                if (!$anyItemDropped && !in_array((int) $dolProduct->id, $syncedProductIds, true)) {
                    $sqlUpdateStockSelf = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
                        . " SET last_stock_sync = NOW() WHERE fk_product = ? AND entity = ?";
                    $paramsStockSelf = [(int)$dolProduct->id, (int)$this->entity];
                    if ($fkStore > 0) {
                        $sqlUpdateStockSelf .= " AND fk_store = ?";
                        $paramsStockSelf[] = $fkStore;
                    }
                    SqlUtils::executeQuery($this->db, $sqlUpdateStockSelf, "updating product last_stock_sync after stock sync", true, $paramsStockSelf);
                } elseif ($anyItemDropped) {
                    $this->log("syncVariantStocksOnly - La ligne bookkeeping du produit parent ID " . (int)$dolProduct->id
                        . " n'est PAS rafraichie (au moins un article de ce lot a ete ecarte) - le produit entier sera reconsidere au prochain cycle", LOG_INFO);
                }

                $this->log("ImportProducts::syncVariantStocksOnly - last_stock_sync updated for " . count($syncedProductIds) . " synced product(s) (parent ID " . (int)$dolProduct->id . ")", LOG_DEBUG);

                return true;
            }

            // Hotfix 2.5.7 (review 3 couches 26/09/2026, LOW) : distinguer « rien n'a matché du
            // tout » (aucune correspondance SKU/inventoryItemId) de « des articles ont matché mais
            // ont tous été écartés ce cycle-ci » (référence de stock non résolue) — les deux
            // produisaient auparavant le même message, masquant la cause réelle.
            if ($matchCount > 0) {
                $this->log("syncVariantStocksOnly - Aucun envoi pour " . $dolProduct->ref
                    . " : les " . $matchCount . " article(s) apparie(s) ont tous ete ecartes ce cycle-ci"
                    . " (reference de stock non resolue a la localisation visee - voir warnings ci-dessus)"
                    . " (" . count($productsToCheck) . " checked)", LOG_WARNING);
            } else {
                $this->log("syncVariantStocksOnly - No stock updates to send for " . $dolProduct->ref
                    . " (" . count($productsToCheck) . " checked, 0 matched - aucune correspondance SKU/inventoryItemId)", LOG_WARNING);
            }
            return false;

        } catch (Exception $e) {
            $this->log("syncVariantStocksOnly - Error for " . $dolProduct->ref . ": " . $e->getMessage(), LOG_WARNING);
            // Non-bloquant : ne pas faire échouer le CRON pour une erreur de stock
            return false;
        }
    }

    /**
     * Synchronize product between Dolibarr and Shopify
     *
     * @param object $dolProduct Dolibarr product object
     * @param array $variants Dolibarr product variants (optional)
     * @param string $tags Product tags (optional)
     * @param bool $isManualSync True if manual synchronization, false for automatic
     * @return bool True if sync was successful
     */
    private function syncProduct($dolProduct, $variants = [], $tags = '', $isManualSync = false)
    {
        global $conf;

        // On travaille uniquement avec des objets Product
        $productId = $dolProduct->id;

        // Check if product was already processed in current session
        if (isset(self::$sessionProcessedProducts[$productId])) {
            $this->log("Product sync skipped - already processed in current session: " . $dolProduct->ref, LOG_WARNING);
            // Release lock but preserve existing status (don't overwrite successful sync)
            $this->manageProductMapping($productId, null, null, null, null, null, true, false);
            return false;
        }

        // Check if product sync is already locked to prevent duplication
        if ($this->isSyncLocked($productId)) {
            $this->log("Product sync skipped - already locked: " . $dolProduct->ref, LOG_WARNING);
            // No lock to release here as it's held by another process
            return false;
        }

        // Set sync lock to prevent concurrent synchronizations by setting status to pending
        if (!$this->manageProductMapping($productId, null, null, null, 'pending', null, false)) {
            $this->log("Failed to set sync lock for product: " . $dolProduct->ref, LOG_ERR);
            // Mark as failed since we couldn't even start the sync
            $this->manageProductMapping($productId, null, null, null, 'failed', 'Failed to acquire sync lock', false);
            return false;
        }

        // Mark product as processed in current session
        self::$sessionProcessedProducts[$productId] = time();

        $this->db->begin();

        try {

            // Log détaillé pour faciliter le débogage
            $this->log("========== DÉBUT SYNCHRONISATION PRODUIT ==========", LOG_INFO);
            $this->log("Produit: " . $dolProduct->ref . " (ID: " . $productId . ")", LOG_INFO);

            // Log execution context for debugging Issue #39
            if (defined('CRON_MODE') && CRON_MODE === true) {
                $executionMode = 'AUTOMATIC (cron)';
            } elseif (defined('CRON_MODE') && CRON_MODE === false) {
                $executionMode = 'MANUAL (cron executed via web interface)';
            } else {
                $executionMode = 'MANUAL (web interface)';
            }
            $this->log("Mode d'exécution: " . $executionMode, LOG_INFO);

            $this->log("Options de synchronisation:", LOG_INFO);
            $this->log("- Prix: " . ($this->config->sync_product_prices ? "Activé (niveau " . $this->config->sync_price_level . ")" : "Désactivé"), LOG_INFO);
            $this->log("- Descriptions: " . ($this->config->sync_product_descriptions ? "Activé" : "Désactivé"), LOG_INFO);
            $this->log("- Images: " . ($this->config->sync_product_images ? "Activé" : "Désactivé"), LOG_INFO);
            $this->log("- Stocks: " . ($this->config->sync_product_stocks ? "Activé" . ($this->config->use_virtual_stock ? " (Stock virtuel)" : " (Stock réel)") : "Désactivé"), LOG_INFO);
            $this->log("- Attributs: " . ($this->config->sync_product_attributes ? "Activé" : "Désactivé"), LOG_INFO);
            // FIX #149: Correction affichage log - !isset() || == 1 affiche toujours CONTINUE
            $this->log("- Politique d'inventaire: " . ((!empty($this->config->inventory_policy_continue_selling) && $this->config->inventory_policy_continue_selling == 1) ? "CONTINUE (vendre même en rupture)" : "DENY (arrêter si rupture)"), LOG_INFO);
            $this->log("- Shopify Location ID: " . ($this->config->shopify_location_id ?? 'NON CONFIGURÉ'), LOG_INFO);

            // Analyser les options de synchronisation activées
            $syncOptions = $this->getSyncOptions();

            // Check if the product exists in our mapping table
            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element . "
                   WHERE fk_product = ? AND entity = " . (int)$this->entity;
            $result = SqlUtils::executeQuery($this->db, $sql, "checking product mapping", true, [(int)$productId]);
            $shopifyProductId = null;
            $isUpdate = false;

            // Check if product exists in our mapping with valid Shopify ID
            $foundValidMapping = false;
            if ($result && $this->db->num_rows($result) > 0) {
                $savedProduct = $this->db->fetch_object($result);
                $shopifyProductId = $savedProduct->shopifyProductId;
                
                // Check if this is a valid Shopify ID (not a temporary lock)
                if (strpos($shopifyProductId, 'TEMP_LOCK_') !== 0) {
                    $foundValidMapping = true;
                    $isUpdate = true;

                    // AC5 (Story 58-5) : garde-fou anti-désarchivage silencieux. Sans ce garde-fou,
                    // un produit archivé explicitement par OffSaleProductsService (offsale_action_status
                    // = 'archived') serait repoussé en DRAFT par le cycle de contenu automatique (24h,
                    // checkProductNeedsUpdate()) ou un clic "Synchroniser" manuel, dès que
                    // getProductStatus() renvoie toujours false (produit toujours tosell=0) —
                    // prepareProductData() recalculerait $shopifyStatus = 'DRAFT' et productSet()
                    // écraserait silencieusement ARCHIVED par DRAFT. Tant que le produit reste hors
                    // vente, ce chemin sort par 'skipped' SANS jamais appeler prepareProductData()/
                    // productSet(). S'il repasse "En vente", le marqueur est effacé (NULL) pour ne
                    // plus jamais réapparaître "déjà traité" et la resynchronisation normale reprend
                    // (réactivation ACTIVE incluse, comportement déjà existant, inchangé).
                    //
                    // MEDIUM (Code Review 3-couches post-58-5) : la même symétrie doit s'appliquer à
                    // 'left_draft' — SANS ce correctif, le garde ne testait que 'archived' et
                    // 'left_draft' n'était JAMAIS effacé. Un produit marqué 'left_draft', remis En
                    // Vente puis re-retiré, ne réapparaîtrait alors plus jamais comme candidat de
                    // OffSaleProductsService (offsale_action_status IS NULL exclu du pool). Le SKIP
                    // du cycle automatique (chemin ci-dessous) reste réservé à 'archived' uniquement
                    // — 'left_draft' ne pilote aucun statut Shopify particulier, la resynchronisation
                    // normale doit continuer même hors vente (comportement AC2/AC3 inchangé).
                    $offsaleActionStatus = isset($savedProduct->offsale_action_status) ? $savedProduct->offsale_action_status : null;
                    if (!empty($offsaleActionStatus)) {
                        $productBackInSale = $this->getProductStatus($dolProduct);

                        if ($offsaleActionStatus === 'archived' && !$productBackInSale) {
                            $this->log("Skipping resync of archived out-of-sale product (offsale_action_status=archived): " . $dolProduct->ref, LOG_DEBUG);
                            $this->db->rollback();
                            $this->manageProductMapping($productId, null, null, null, 'skipped', null, true, false, false);
                            return 'skipped';
                        }

                        if ($productBackInSale) {
                            $this->clearOffsaleActionStatus($productId);
                        }
                    }

                    // Check if we need to update this product (only for real updates)
                    if (!$this->checkProductNeedsUpdate($dolProduct, $savedProduct, $isManualSync)) {
                        $this->log("Product does not need update: " . $dolProduct->ref, LOG_INFO);
                        $this->db->rollback();

                        // FIX v2.2.0: Assigner les collections même si le produit n'a pas changé
                        if (!empty($shopifyProductId)) {
                            $this->collectProductForCollections($dolProduct, $shopifyProductId);
                        }

                        // FIX v2.2.0: Synchroniser les stocks des variantes même si le contenu produit n'a pas changé
                        // Le stock est indépendant du contenu produit — il doit TOUJOURS être mis à jour
                        $stockSynced = false;
                        if (!empty($this->config->sync_product_stocks) && $this->config->sync_product_stocks == 1 && !empty($shopifyProductId)) {
                            $stockSynced = $this->syncVariantStocksOnly($dolProduct, $shopifyProductId, $variants);
                        }

                        // FIX v2.2.0: Marquer 'success' si stock synchro OK (évite re-traitement à chaque CRON)
                        // Marquer 'skipped' seulement si aucun stock n'a été envoyé
                        // FIX v2.4.1 (hotfix) : $contentSynced=false — ce succès est stock-only, tms
                        // (référence "dernier contenu synchronisé") ne doit PAS être rafraîchi, sinon
                        // le garde-fou 24h et checkProductNeedsUpdate() ne voient plus jamais le contenu vieillir
                        $syncStatus = $stockSynced ? 'success' : 'skipped';
                        $this->manageProductMapping($productId, null, null, null, $syncStatus, null, true, false, false);

                        // Si stock envoyé → compter dans la limite CRON (appel API effectué)
                        // Si rien envoyé → ne pas compter (aucun appel API)
                        return $stockSynced ? 'stock_synced' : 'skipped';
                    }

                    $this->log("Updating existing product: " . $dolProduct->ref . " (Shopify ID: " . $shopifyProductId . ")", LOG_INFO);
                } else {
                    $this->log("Found temporary lock ID, will search by SKU: " . $dolProduct->ref, LOG_INFO);
                }
            }
            
            // If no valid mapping found (either no record or TEMP_LOCK), search by SKU
            if (!$foundValidMapping) {
                $this->log("Searching by SKU: " . $dolProduct->ref, LOG_INFO);

                // FIX: Issue v2.0.23 Session 10 - Improved parent/variant detection logic
                // Check if this product is itself a variant (has a parent) or a true parent product
                $isVariantChild = $this->isProductVariant($dolProduct->id);
                $hasVariants = !empty($variants);
                $isParentProduct = !$isVariantChild && $hasVariants;
                
                $this->log("Product " . $dolProduct->ref . " analysis: isVariantChild=" . ($isVariantChild ? "Yes" : "No") . ", hasVariants=" . ($hasVariants ? "Yes" : "No") . ", isParentProduct=" . ($isParentProduct ? "Yes" : "No"), LOG_DEBUG);

                // v2.2.2: Extract child variant SKUs for Strategy P0 (direct match by known variant SKUs)
                // Prevents duplicates after mapping purge when parent ref differs from variant SKUs
                $variantSkusForSearch = [];
                if ($isParentProduct && !empty($variants)) {
                    foreach ($variants as $v) {
                        $variantRef = $v->ref ?? (is_object($v) && isset($v->rowid) ? null : null);
                        if (!empty($variantRef)) {
                            $variantSkusForSearch[] = $variantRef;
                        } elseif (isset($v->id) || isset($v->rowid)) {
                            // Fallback: load the variant product to get its ref
                            $varProduct = new Product($this->db);
                            if ($varProduct->fetch($v->id ?? $v->rowid) > 0 && !empty($varProduct->ref)) {
                                $variantSkusForSearch[] = $varProduct->ref;
                            }
                        }
                    }
                    $this->log("Prepared " . count($variantSkusForSearch) . " variant SKU(s) for Strategy P0 search", LOG_DEBUG);
                }

                // Search with appropriate flag: only true parent products should use parent search strategy
                $existingProduct = $this->shopifyApi->findProductBySku($dolProduct->ref, $isParentProduct, $variantSkusForSearch);

                if ($existingProduct !== null) {
                    $this->log("Product found by SKU, will update: " . $dolProduct->ref, LOG_INFO);
                    $shopifyProductId = str_replace('gid://shopify/Product/', '', $existingProduct->product->id);

                    // v2.2.0 Story 23.9: TYPE MISMATCH DETECTION
                    $matchType = $existingProduct->matchType ?? null;

                    if ($matchType !== null) {
                        $dolibarrIsSimple = !$isParentProduct && !$isVariantChild;

                        // Cas 1: Dolibarr simple → Shopify variante d'un parent
                        if ($dolibarrIsSimple && $matchType === 'variant') {
                            $this->log("TYPE MISMATCH: Dolibarr simple '" . $dolProduct->ref
                                . "' matched Shopify variant (parent ID: " . $shopifyProductId
                                . ", variantCount: " . ($existingProduct->variantCount ?? '?')
                                . ") — ignoring match, will create separate simple product", LOG_WARNING);
                            $existingProduct = null;
                            $shopifyProductId = null;
                            $isUpdate = false;
                        }

                        // Cas 2: Dolibarr parent → Shopify simple
                        elseif ($isParentProduct && $matchType === 'simple') {
                            $this->log("TYPE MISMATCH: Dolibarr parent '" . $dolProduct->ref
                                . "' matched Shopify simple (ID: " . $shopifyProductId
                                . ") — deleting simple, will create parent", LOG_WARNING);
                            $deleteResult = $this->shopifyApi->deleteProduct($shopifyProductId);
                            if (!$deleteResult) {
                                $this->log("TYPE MISMATCH: Failed to delete Shopify simple product "
                                    . $shopifyProductId . " — skipping to prevent duplicates", LOG_ERR);
                                return 'skipped';
                            }
                            // Nettoyer le mapping orphelin vers le produit supprimé
                            $sqlCleanMapping = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products"
                                . " WHERE shopifyProductId = '" . $this->db->escape($shopifyProductId) . "'"
                                . " AND entity = " . (int)$this->entity;
                            $this->db->query($sqlCleanMapping);
                            $this->log("TYPE MISMATCH: Cleaned orphan mapping for deleted product " . $shopifyProductId, LOG_INFO);
                            $shopifyProductId = null;
                            $isUpdate = false;
                        }
                    }
                    // matchType === null (DIAG strategy) → proceed with normal update (AC6)

                    if ($existingProduct !== null) {
                        $isUpdate = true;
                    }
                } else {
                    $this->log("Product not found by SKU, will create new: " . $dolProduct->ref, LOG_INFO);
                    $shopifyProductId = null;
                    $isUpdate = false;
                }
            }

            // AC2 (Story 58-5) : filtre "ne pousser vers Shopify que les produits En Vente" —
            // CRÉATION uniquement, JAMAIS en mise à jour. $isUpdate est déjà figé ci-dessus (via
            // mapping table OU retrouvaille par SKU) : c'est le seul endroit où "création" est
            // distinguée de "mise à jour" avec certitude (cf. Dev Notes story 58-5, piège des
            // requêtes de sélection qui ne savent pas qu'un produit sans mapping peut déjà
            // exister sur Shopify). Un produit déjà mappé qui passe tosell=0 continue TOUJOURS
            // d'être synchronisé et basculé en DRAFT — invariant AC3, testé en non-régression.
            if (!$isUpdate && $this->shouldSkipInactiveProductCreation($dolProduct)) {
                $this->log("Skipping Shopify creation of out-of-sale product (DOLI2SHOP_SYNC_PRODUCTS_ONLY_ACTIVE active): " . $dolProduct->ref, LOG_DEBUG);
                $this->db->rollback();
                $this->manageProductMapping($productId, null, null, null, 'skipped', null, true, false, false);
                return 'skipped';
            }

            // STEP 1: Create or update the product with basic information
            // ==========================================================

            // Prepare product data for productSet mutation
            $productData = $this->prepareProductData($dolProduct, $variants, $tags, $shopifyProductId, $syncOptions);

            // Execute productSet mutation with improved retry logic
            $maxRetries = 3;
            $retryCount = 0;
            $syncSuccess = false;
            $response = null;
            $permanentError = false;
            $lastErrorMessage = "";
            $extractedShopifyProductId = null; // FIX: Issue #46 - Initialize variable to capture Shopify ID

            // Lists of error patterns that should not be retried (permanent errors)
            $permanentErrorPatterns = [
                // Validation errors
                "is invalid",
                "can't be blank",
                "must be less than",
                "must be greater than",
                "is not included in the list",
                "already exists",
                "must be unique",
                "exceeds maximum allowed",
                // Permission errors
                "access denied",
                "not authorized",
                "permission denied",
                "insufficient permission",
                // Resource errors
                "resource not found",
                "resource does not exist",
                "does not exist",
                "Product does not exist",
                "exceeded plan limit"
            ];

            while ($retryCount < $maxRetries && !$syncSuccess && !$permanentError) {
                if ($retryCount > 0) {
                    $this->log("Retry attempt " . ($retryCount + 1) . " of " . $maxRetries, LOG_INFO);
                    sleep(3 * $retryCount); // Exponential backoff
                }

                try {
                    $response = $this->shopifyApi->productSet($productData, true);

                    // Check for GraphQL-level errors
                    if (isset($response->errors)) {
                        $errorDetails = json_encode($response->errors);
                        $this->log("GraphQL error on attempt " . ($retryCount + 1) . ": " . $errorDetails, LOG_WARNING);
                        $lastErrorMessage = $errorDetails;

                        // Check if this is a permanent error
                        if ($this->isPermanentError($response->errors, $permanentErrorPatterns)) {
                            $this->log("Detected permanent GraphQL error - stopping retry attempts", LOG_WARNING);
                            $permanentError = true;
                        } else {
                            $this->log("Retryable GraphQL error - will attempt again", LOG_INFO);
                            $retryCount++;
                        }
                        continue;
                    }

                    // Check for user errors in the response
                    if (!empty($response->data->productSet->userErrors)) {
                        $userErrors = $response->data->productSet->userErrors;
                        $errorDetails = json_encode($userErrors);
                        $this->log("Product sync error on attempt " . ($retryCount + 1) . ": " . $errorDetails, LOG_WARNING);
                        $lastErrorMessage = $errorDetails;

                        // Check if this is a permanent user error
                        if ($this->isPermanentError($userErrors, $permanentErrorPatterns)) {
                            $this->log("Detected permanent user error - stopping retry attempts", LOG_WARNING);
                            $permanentError = true;
                        } else {
                            $this->log("Retryable user error - will attempt again", LOG_INFO);
                            $retryCount++;
                        }
                    } else {
                        $syncSuccess = true;

                        // FIX: Issue #46 - Extract Shopify product ID correctly
                        if (!empty($response->data->productSet->product->legacyResourceId)) {
                            $extractedShopifyProductId = $response->data->productSet->product->legacyResourceId;
                            $this->processProductMetafields($productData['variants'], $extractedShopifyProductId);
                            $this->log("Processed metafields for product " . $extractedShopifyProductId, LOG_INFO);
                        } elseif (!empty($response->data->productSet->product->id)) {
                            // Fallback to extract from full GID
                            $extractedShopifyProductId = str_replace('gid://shopify/Product/', '', $response->data->productSet->product->id);
                            $this->log("Extracted Shopify product ID from GID: " . $extractedShopifyProductId, LOG_INFO);
                        }

                        $this->log("Product sync successful: " . $dolProduct->ref, LOG_INFO);
                    }
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();
                    $this->log("Exception on attempt " . ($retryCount + 1) . ": " . $errorMessage, LOG_WARNING);
                    $lastErrorMessage = $errorMessage;

                    // Check if the exception indicates a temporary error (network, timeout, rate limit)
                    if (
                        stripos($errorMessage, "timeout") !== false ||
                        stripos($errorMessage, "connection") !== false ||
                        stripos($errorMessage, "network") !== false ||
                        stripos($errorMessage, "rate limit") !== false ||
                        stripos($errorMessage, "too many requests") !== false ||
                        stripos($errorMessage, "503") !== false ||
                        stripos($errorMessage, "502") !== false ||
                        stripos($errorMessage, "504") !== false
                    ) {
                        $this->log("Retryable exception - will attempt again", LOG_INFO);
                        $retryCount++;
                    } else {
                        $this->log("Non-retryable exception - stopping retry attempts", LOG_WARNING);
                        $permanentError = true;
                    }
                }
            }

            if (!$syncSuccess) {
                if ($permanentError) {
                    throw new Exception("Failed to sync product " . $dolProduct->ref . " due to permanent error: " . $lastErrorMessage);
                } else {
                    throw new Exception(
                        "Failed to sync product " . $dolProduct->ref .
                            " after " . $maxRetries . " attempts. Last error: " . $lastErrorMessage
                    );
                }
            }

            // Process response - either immediate product or operation to check later
            if (
                isset($response->data) &&
                isset($response->data->productSet) &&
                (
                    ($response->data->productSet->product) ||
                    ($response->data->productSet->productSetOperation)
                )
            ) {
                if ($response->data->productSet->product) {
                    $shopifyProduct = $response->data->productSet->product;
                    // FIX: Issue #46 - Use the correctly extracted ID
                    $shopifyProductId = $extractedShopifyProductId ?: str_replace('gid://shopify/Product/', '', $shopifyProduct->id);
                    
                    $this->log("Final Shopify product ID for mapping: " . $shopifyProductId, LOG_INFO);
                    
                    // FIX: Issue #46 - Add validation to ensure ID is not empty
                    if (empty($shopifyProductId)) {
                        $this->log("ERROR: Shopify product ID is empty! This will cause mapping corruption.", LOG_ERR);
                        $this->log("Response debug - product: " . json_encode($shopifyProduct), LOG_ERR);
                        throw new Exception("Shopify product ID is empty - cannot save mapping");
                    }

                    // Update product URL in Dolibarr with optimized query
                    if (!empty($shopifyProduct->handle)) {
                        $publicUrl = 'https://' . $this->config->shopify_store_hostname . '/products/' . $shopifyProduct->handle;

                        // Use a more efficient update with less locking
                        $sql = "UPDATE " . MAIN_DB_PREFIX . "product
                                SET url = ?
                                WHERE rowid = ? AND (url IS NULL OR url != ?)";

                        // Only update if URL has changed
                        SqlUtils::executeQuery($this->db, $sql, "updating product URL for product " . $dolProduct->ref, true,
                            [$publicUrl, (int)$productId, $publicUrl], 5); // Increased retries to 5 for better handling
                    }

                    // Save parent product mapping if new
                    if (!$isUpdate) {
                        $this->log("Calling saveProductMapping for new product: " . $dolProduct->ref . " (" . $productId . ") with Shopify ID: " . $shopifyProductId, LOG_INFO);
                        $saveResult = $this->saveProductMapping($productId, $shopifyProductId);
                        $this->log("saveProductMapping result for new product: " . ($saveResult ? "Success" : "Failed"), LOG_INFO);
                    } else {
                        // FIX: Issue #42 - Pass shopifyProductId with original format to maintain consistency
                        $this->log("Calling updateProductTimestamp for existing product: " . $dolProduct->ref . " (" . $productId . ") with Shopify ID: " . $shopifyProductId, LOG_INFO);
                        // $shopifyProductId is already clean (without gid prefix) from line 623
                        $updateResult = $this->updateProductTimestamp($productId, $shopifyProductId);
                        $this->log("updateProductTimestamp result for existing product: " . ($updateResult ? "Success" : "Failed"), LOG_INFO);
                    }

                    // Save variant mappings
                    if (!empty($shopifyProduct->variants->nodes)) {
                        // STEP 2: Update inventory and variant-specific data
                        // ===================================================

                        if (!empty($variants)) {
                            // For products with variants, map each variant
                            $this->mapVariantsToShopify($shopifyProduct->variants->nodes, $variants, $shopifyProductId, $productId);

                            // Update inventory and other variant-specific fields for all variants
                            $this->updateVariantInventory($shopifyProductId, $shopifyProduct->variants->nodes, $variants, $productId);
                        } else {
                            // For simple products, map the single variant
                            $firstVariant = $shopifyProduct->variants->nodes[0];
                            $shopifyVariantId = str_replace('gid://shopify/ProductVariant/', '', $firstVariant->id);

                            // UNIFIED: Use manageProductMapping to update variant ID for simple products
                            $this->log("Updating simple product mapping with variant ID: " . $dolProduct->ref . " (" . $productId . ") with variant ID: " . $shopifyVariantId, LOG_INFO);
                            $updateResult = $this->manageProductMapping($productId, $shopifyProductId, $shopifyVariantId, null, null, null, false);
                            if ($updateResult) {
                                $this->log("Successfully updated variant ID for simple product: " . $dolProduct->ref, LOG_DEBUG);
                            } else {
                                $this->log("Failed to update variant ID for simple product: " . $dolProduct->ref, LOG_ERR);
                            }

                            // Update inventory and other variant-specific fields for the simple product
                            $this->updateVariantInventory($shopifyProductId, $shopifyProduct->variants->nodes, [$dolProduct], $productId);
                        }
                    }

                    // FIX #141 v2.1.1: Publish product to configured sales channels
                    $this->publishProductToConfiguredChannels($shopifyProduct->id, $dolProduct->ref);
                }
                // Handle asynchronous operation
                elseif ($response->data->productSet->productSetOperation) {
                    $operation = $response->data->productSet->productSetOperation;
                    $this->log("Product sync is being processed asynchronously. Operation ID: " . $operation->id, LOG_INFO);

                    // If it's a new product, save a temporary mapping
                    if (!$isUpdate) {
                        // We'll need to update this with the actual product ID later
                        // For now, we just note that a sync operation is in progress
                        $this->log("Asynchronous operation started for product: " . $dolProduct->ref, LOG_INFO);
                        // TODO: Could add a status field to track async operations
                    }
                }
            } else {
                // Détection d'une réponse d'erreur
                $statusCode = 0;
                $responseBody = '';

                // Récupérer le code de statut HTTP et le corps de la réponse si disponibles
                if (property_exists($response, 'status')) {
                    $statusCode = $response->status;
                }

                // Récupérer les détails de l'erreur
                if (property_exists($response, 'errors')) {
                    $responseBody = json_encode($response->errors);
                } else {
                    $responseBody = json_encode($response);
                }

                $errorMessage = "Erreur lors de la synchronisation du produit. ";
                $errorMessage .= "Code de statut HTTP: " . $statusCode . ", ";
                $errorMessage .= "Réponse complète: " . $responseBody;

                $this->log($errorMessage, LOG_ERR);
                throw new Exception($errorMessage);
            }

            $this->db->commit();
            
            // Collect product for collections batch processing (v2.0.26)
            $this->collectProductForCollections($dolProduct, $shopifyProductId);
            
            // Log final pour indiquer la fin de la synchronisation
            $this->log("========== FIN SYNCHRONISATION PRODUIT ==========", LOG_INFO);

            // Release sync lock with cascade update for variants if needed
            $hasVariants = !empty($variants);
            if ($hasVariants) {
                // For parent products with variants, use optimized cascade update
                $this->manageProductMapping($productId, null, null, null, 'success', null, true, true);
            } else {
                // For simple products, use regular release (no cascade needed)
                $this->manageProductMapping($productId, null, null, null, 'success', null, true, false);
            }
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            $this->log("ERREUR de synchronisation: " . $e->getMessage(), LOG_ERR);

            // Release sync lock even on error with cascade update for variants if needed
            $hasVariants = !empty($variants);
            if ($hasVariants) {
                // For parent products with variants, use optimized cascade update
                $this->manageProductMapping($productId, null, null, null, 'failed', $e->getMessage(), true, true);
            } else {
                // For simple products, use regular release (no cascade needed)
                $this->manageProductMapping($productId, null, null, null, 'failed', $e->getMessage(), true, false);
            }
            return false;
        }
    }

    /**
     * Map variant products from Dolibarr to Shopify
     *
     * @param array $shopifyVariants Shopify variant nodes
     * @param array $dolVariants Dolibarr variant products
     * @param string $shopifyProductId Shopify product ID
     * @param int $parentProductId Dolibarr parent product ID
     * @return void
     */
    private function mapVariantsToShopify($shopifyVariants, $dolVariants, $shopifyProductId, $parentProductId)
    {
        // Note: We don't need a transaction here as this is called from syncProduct
        // which already has a transaction wrapper

        $this->log("Mapping " . count($shopifyVariants) . " Shopify variants to " . count($dolVariants) . " Dolibarr variants", LOG_DEBUG);

        // Create a mapping of Dolibarr variants by their SKU
        // AC4 (story variante-sans-correspondance-sku-ignoree-en-silence) : un seul test de SKU,
        // SkuVariantMatcher::normalizeSku() — null et '' toujours "pas de SKU".
        $dolVariantsBySku = [];
        foreach ($dolVariants as $variant) {
            $variantSku = SkuVariantMatcher::normalizeSku(isset($variant->ref) ? $variant->ref : null);
            if ($variantSku !== null) {
                $dolVariantsBySku[$variantSku] = $variant;
            }
        }

        // Map Shopify variants to Dolibarr variants
        foreach ($shopifyVariants as $shopifyVariant) {
            $shopifyVariantSku = SkuVariantMatcher::normalizeSku(isset($shopifyVariant->sku) ? $shopifyVariant->sku : null);
            if ($shopifyVariantSku !== null && isset($dolVariantsBySku[$shopifyVariantSku])) {
                $dolVariant = $dolVariantsBySku[$shopifyVariantSku];
                $shopifyVariantId = str_replace('gid://shopify/ProductVariant/', '', $shopifyVariant->id);
                
                // FIX: Issue #46 - Add validation for variant ID
                if (empty($shopifyVariantId)) {
                    $this->log("ERROR: Shopify variant ID is empty for variant " . $dolVariant->ref . "! This will cause mapping corruption.", LOG_ERR);
                    $this->log("Shopify variant debug: " . json_encode($shopifyVariant), LOG_ERR);
                    continue; // Skip this variant rather than corrupting the mapping
                }

                // Check if mapping already exists
                $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element . "
                       WHERE fk_product = ? AND entity = " . (int)$this->entity;
                $result = SqlUtils::executeQuery($this->db, $sql, "checking variant mapping", true, [(int)$dolVariant->id]);

                $variantMappingSuccess = false;
                
                if ($result && $this->db->num_rows($result) > 0) {
                    // UNIFIED: Use manageProductMapping to update existing variant mapping
                    $this->log("Updating existing variant mapping: " . $dolVariant->ref . " (" . $dolVariant->id . ") with Shopify ID: " . $shopifyProductId . " and variant ID: " . $shopifyVariantId, LOG_INFO);
                    $updateResult = $this->manageProductMapping($dolVariant->id, $shopifyProductId, $shopifyVariantId, $parentProductId, 'success', null, false);
                    if ($updateResult) {
                        $this->log("Updated mapping for variant: " . $dolVariant->ref . " (Shopify ID: " . $shopifyVariantId . ")", LOG_DEBUG);
                        $variantMappingSuccess = true;
                    } else {
                        $this->log("Failed to update mapping for variant: " . $dolVariant->ref, LOG_ERR);
                    }
                } else {
                    // Create new mapping
                    $this->log("Calling saveProductMapping for new variant: " . $dolVariant->ref . " (" . $dolVariant->id . ") with Shopify ID: " . $shopifyProductId . " and variant ID: " . $shopifyVariantId . " and parent ID: " . $parentProductId, LOG_INFO);
                    $saveResult = $this->saveProductMapping($dolVariant->id, $shopifyProductId, $shopifyVariantId, $parentProductId);
                    $this->log("saveProductMapping result for new variant: " . ($saveResult ? "Success" : "Failed"), LOG_INFO);
                    if ($saveResult) {
                        $this->log("Created mapping for variant: " . $dolVariant->ref . " (Shopify ID: " . $shopifyVariantId . ")", LOG_DEBUG);
                        $variantMappingSuccess = true;
                    }
                }
                
                // Release sync lock for variant based on mapping result
                if ($variantMappingSuccess) {
                    $this->manageProductMapping($dolVariant->id, null, null, null, 'success', null, true, false);
                    $this->log("Released sync lock for variant: " . $dolVariant->ref . " (success)", LOG_DEBUG);
                } else {
                    $this->manageProductMapping($dolVariant->id, null, null, null, 'failed', 'Failed to create/update variant mapping', true, false);
                    $this->log("Released sync lock for variant: " . $dolVariant->ref . " (failed)", LOG_DEBUG);
                }
            } else {
                $this->log("Could not match Shopify variant SKU: " . $shopifyVariant->sku, LOG_WARNING);
                // Note: No releaseSyncLock here as we don't have the Dolibarr variant ID
                // These unmatched variants will remain "pending" which is appropriate
            }
        }
    }

    /**
     * Check if a product needs to be updated
     *
     * @param object $dolProduct Dolibarr product
     * @param object $savedProduct Saved product mapping
     * @param bool $isManualSync True if manual synchronization, false for automatic
     * @return bool True if update needed
     */
    private function checkProductNeedsUpdate($dolProduct, $savedProduct, $isManualSync = false)
    {
        // CORRECTION CRITIQUE : Utiliser date_modification au lieu de tms inexistant
        // L'objet Product de Dolibarr utilise date_modification (timestamp Unix)
        $dolTimestamp = !empty($dolProduct->date_modification) ? (int)$dolProduct->date_modification : 0;
        $shopifyTimestamp = strtotime($savedProduct->tms);

        // Get current timestamp
        $currentTimestamp = time();

        // En mode manuel, forcer la synchronisation
        if ($isManualSync) {
            $this->log("Manual sync mode: forcing synchronization. Dolibarr timestamp: $dolTimestamp, Shopify timestamp: $shopifyTimestamp", LOG_DEBUG);
            return true;  // Force la synchronisation en mode manuel
        }

        // FIX v2.4.1 (hotfix prod) : une déclinaison (enfant) modifiée après le dernier tms
        // — désactivation tosell=0, renommage, prix — est un changement de CONTENU du produit
        // parent qui doit déclencher une resync complète. Seule une resync de contenu (productSet)
        // retire les variantes tosell=0 côté Shopify (cf. l.1979-1985). Sans ce check, on ne
        // comparait QUE la date de modification du parent : une déclinaison désactivée seule
        // (parent inchangé) n'était donc jamais retirée de Shopify.
        $sqlMaxChildModif = "SELECT MAX(p.tms) as max_child_tms
                FROM " . MAIN_DB_PREFIX . "product p
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON p.rowid = pac.fk_product_child
                WHERE pac.fk_product_parent = ?";
        $resultMaxChild = SqlUtils::executeQuery($this->db, $sqlMaxChildModif, "checking max child modification date for product " . $dolProduct->id, true, [(int)$dolProduct->id]);
        $maxChildTimestamp = 0;
        if ($resultMaxChild && $this->db->num_rows($resultMaxChild) > 0) {
            $rowMaxChild = $this->db->fetch_object($resultMaxChild);
            if (!empty($rowMaxChild->max_child_tms)) {
                $maxChildTimestamp = strtotime($rowMaxChild->max_child_tms);
            }
        }
        $childNeedsUpdate = $maxChildTimestamp > $shopifyTimestamp;

        // Check if last Shopify update was more than 24 hours ago (automatic mode only)
        $twentyFourHours = 24 * 60 * 60; // 24 hours in seconds
        $isOlderThan24h = ($currentTimestamp - $shopifyTimestamp) > $twentyFourHours;

        $this->log("Automatic sync mode: Dolibarr timestamp: $dolTimestamp, Shopify timestamp: $shopifyTimestamp, Max child timestamp: $maxChildTimestamp, Current timestamp: $currentTimestamp, Older than 24h: $isOlderThan24h, Child needs update: " . ($childNeedsUpdate ? 'Yes' : 'No'), LOG_DEBUG);

        return $dolTimestamp > $shopifyTimestamp || $childNeedsUpdate || $isOlderThan24h;
    }

    /**
     * Check if product sync is locked to prevent concurrent synchronizations
     *
     * @param int $productId Dolibarr product ID
     * @return bool True if product is locked, false otherwise
     */
    private function isSyncLocked($productId)
    {
        $sql = "SELECT sync_lock FROM " . MAIN_DB_PREFIX . $this->table_element . "
                WHERE fk_product = ? AND entity = " . (int)$this->entity;
        $result = SqlUtils::executeQuery($this->db, $sql, "checking sync lock", true, [(int)$productId]);

        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            if ($obj->sync_lock) {
                $lockTime = strtotime($obj->sync_lock);
                $currentTime = time();
                $lockExpireTime = 5 * 60; // 5 minutes lock

                if (($currentTime - $lockTime) < $lockExpireTime) {
                    $this->log("Product " . $productId . " is locked for sync (locked at: " . $obj->sync_lock . ")", LOG_WARNING);
                    return true;
                }
                // Lock expired, will be released automatically
                $this->log("Sync lock expired for product " . $productId . ", proceeding with sync", LOG_DEBUG);
            }
        }
        return false;
    }


    /**
     * Unified function to manage llx_doli2shop_products table
     * Handles creation, updates, status management, and timestamp logic
     * Story 47-3: fk_store inclus dans WHERE UPDATE + INSERT + cascade variantes.
     *
     * @param int $dolibarrId Dolibarr product ID
     * @param string $shopifyId Shopify product ID (can be TEMP_LOCK_* for initial sync)
     * @param string|null $shopifyVariantId Shopify variant ID (null for parent products)
     * @param int|null $dolibarrParentId Parent product ID for variants (null for simple/parent products)
     * @param string|null $status Sync status: 'success', 'failed', 'pending', 'skipped'
     * @param string|null $errorMessage Error message (only for 'failed' status)
     * @param bool $releaseLock Whether to release the sync lock
     * @param bool $isParentWithVariants Whether this is a parent product with variants (triggers cascade update)
     * @param bool $contentSynced FIX v2.4.1 (hotfix) : true = ce succès provient d'une synchro de CONTENU
     *             réelle (productSet exécuté) → tms (référence "dernier contenu synchronisé" utilisée par
     *             checkProductNeedsUpdate() et le garde-fou 24h) est rafraîchi. false = succès stock-only
     *             (cycle stock 15min) → tms N'EST PAS touché (last_stock_sync l'est, séparément).
     *             Sans cette distinction, le cycle stock (toutes les 15min) écrasait tms en continu,
     *             rendant le contenu Shopify jamais réévalué (garde-fou 24h neutralisé de facto).
     * @return bool Success/failure
     */
    private function manageProductMapping($dolibarrId, $shopifyId = null, $shopifyVariantId = null,
                                        $dolibarrParentId = null, $status = null, $errorMessage = null, $releaseLock = false, $isParentWithVariants = false, $contentSynced = true)
    {
        try {
            $updateClauses = [];
            $params = [];

            // Story 47-3 : rowid boutique courante (0 = chemin historique)
            $fkStore = (int)$this->shopifyApi->getStoreId();

            // Handle Shopify IDs if provided
            if ($shopifyId !== null) {
                $cleanShopifyId = str_replace('gid://shopify/Product/', '', $shopifyId);
                if (empty($cleanShopifyId) || $cleanShopifyId === '' || $cleanShopifyId === '0') {
                    $this->log("ERROR: Cannot save mapping with empty or invalid Shopify Product ID for Dolibarr ID: " . $dolibarrId, LOG_ERR);
                    return false;
                }
                $updateClauses[] = "shopifyProductId = ?";
                $params[] = $cleanShopifyId;
            }

            if ($shopifyVariantId !== null) {
                $cleanVariantId = '';
                if (!empty($shopifyVariantId)) {
                    $cleanVariantId = str_replace('gid://shopify/ProductVariant/', '', $shopifyVariantId);
                    if (empty($cleanVariantId) || $cleanVariantId === '0') {
                        $this->log("WARNING: Invalid Shopify Variant ID provided for Dolibarr ID: " . $dolibarrId, LOG_WARNING);
                        $cleanVariantId = '';
                    }
                }
                $updateClauses[] = "shopifyVariantId = ?";
                $params[] = $cleanVariantId;
            }

            // Handle parent ID
            if ($dolibarrParentId !== null) {
                $updateClauses[] = "fk_product_parent = ?";
                $params[] = (int)$dolibarrParentId;
            }

            // Handle status - with special logic for 'success'
            if ($status !== null) {
                $updateClauses[] = "last_sync_status = ?";
                $params[] = $status;

                // CRITICAL: Manage sync lock based on status
                if (in_array($status, ['success', 'failed', 'skipped'])) {
                    // Final statuses: release the lock
                    $updateClauses[] = "sync_lock = NULL";
                } elseif ($status === 'pending') {
                    // Pending status: activate the lock with timestamp
                    $updateClauses[] = "sync_lock = NOW()";
                }

                // CRITICAL: Only update tms on 'success' status AND when it's a real content sync
                // (FIX v2.4.1 hotfix : un succès stock-only ne doit PAS rafraîchir tms)
                if ($status === 'success' && $contentSynced) {
                    $updateClauses[] = "tms = NOW()";
                }

                // Handle error message
                if ($status === 'failed' && $errorMessage !== null) {
                    $updateClauses[] = "last_sync_error = ?";
                    $params[] = $errorMessage;
                } elseif ($status === 'success') {
                    // Clear error on success
                    $updateClauses[] = "last_sync_error = NULL";
                }
            }

            // Handle explicit sync lock release (for cases where we just want to release without status change)
            if ($releaseLock) {
                $updateClauses[] = "sync_lock = NULL";
            }

            if (empty($updateClauses)) {
                $this->log("No updates to perform for product " . $dolibarrId, LOG_DEBUG);
                return true;
            }

            // Determine WHERE clause based on variant presence
            // Story 47-3 : inclure fk_store dans le WHERE pour n'affecter QUE les mappings
            // de cette boutique (isole boutique A de boutique B).
            // IMPORTANT (rétrocompat) : en chemin historique/fallback (fkStore=0), NE PAS filtrer
            // sur fk_store — sinon les lignes déjà backfillées (fk_store>0) ne seraient pas
            // matchées et provoqueraient un INSERT en doublon. fkStore=0 ⇒ comportement pré-47-3.
            $whereClause = "fk_product = ? AND entity = ?";
            $whereParams = [(int)$dolibarrId, (int)$this->entity];
            if ($fkStore > 0) {
                $whereClause .= " AND fk_store = ?";
                $whereParams[] = $fkStore;
            }

            if ($shopifyVariantId !== null && !empty($cleanVariantId ?? '')) {
                // For variants, target specific variant
                $whereClause .= " AND shopifyVariantId = ?";
                $whereParams[] = $cleanVariantId;
            } elseif ($releaseLock && $shopifyId === null) {
                // Special case: releasing lock without Shopify IDs - update any record for this product
                // This handles the case where we just want to release the lock
                // No additional WHERE clause needed - will update the first matching record
            } else {
                // For parent products, target records with empty variant ID
                $whereClause .= " AND (shopifyVariantId = '' OR shopifyVariantId IS NULL)";
            }

            // Try UPDATE first (for existing records)
            $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . "
                    SET " . implode(", ", $updateClauses) . "
                    WHERE " . $whereClause;

            $allParams = array_merge($params, $whereParams);

            $this->log("Executing UPDATE: " . $sql . " with params: " . json_encode($allParams), LOG_DEBUG);

            $result = SqlUtils::executeQuery($this->db, $sql, "updating product mapping", true, $allParams, 5);
            $affectedRows = $this->db->affected_rows($result);

            // If no rows affected and we have Shopify IDs, create new record
            if ($affectedRows == 0 && $shopifyId !== null) {
                // Verrou advisory MySQL (Story cle-unique-doli2shop-products-null-fk-product-parent,
                // AC1) : la course porte sur l'ABSENCE de ligne (check-then-act UPDATE -> SELECT
                // COUNT -> INSERT ci-dessous, sans transaction ni SELECT ... FOR UPDATE) — même
                // mécanisme et même motivation que
                // ShopifyWebhooks::createWebhookWithDatabase() (class/shopifywebhooks.class.php:1550) :
                // rien à verrouiller par lock de ligne (gap locks InnoDB, subtils, source de
                // deadlocks). Nom scopé par produit ET boutique — jamais un verrou global qui
                // sérialiserait tous les produits entre eux ; l'entité est ajoutée automatiquement
                // par CronHelperTrait::buildCronLockName().
                $lockName = 'product_map_' . (int)$dolibarrId . '_' . $fkStore;
                // Timeout 3s : ni bloquant indéfiniment (timeout=0, défaut de la méthode, ferait
                // échouer la quasi-totalité des courses réelles — elles se jouent en quelques
                // dizaines de ms), ni instantané. Suffisant pour laisser le gagnant de la course
                // terminer son propre INSERT (opération unique, rapide) sans bloquer un
                // webhook/CRON au-delà d'un délai perceptible ; cohérent avec le précédent
                // ShopifyWebhooks (borné à 5s max selon le budget restant).
                $lockTimeout = 3;

                if (!$this->acquireCronLock($lockName, $lockTimeout)) {
                    // AC1 : JAMAIS de skip silencieux — un verrou non obtenu doit remonter comme un
                    // échec explicite, sinon un mapping produit ne serait jamais créé, sans aucune
                    // trace (plus grave que le doublon d'origine que ce correctif ferme).
                    $this->log("manageProductMapping - Verrou non acquis pour fk_product=" . $dolibarrId
                        . " fk_store=" . $fkStore . " (entity=" . $this->entity . ") : abandon"
                        . " (course avec un autre appelant, verrou déjà détenu au-delà du timeout de "
                        . $lockTimeout . "s)", LOG_ERR);
                    return false;
                }

                try {
                    // Re-vérification SOUS verrou (le perdant de la course n'est pas un échec) :
                    // CRITICAL: Check if a record already exists for this product to prevent duplicates
                    // Story 47-3 : filtre sur fk_store (isoler les mappings par boutique) — conditionné
                    // à fkStore>0 (fkStore=0 = legacy/fallback → pas de filtre, comportement pré-47-3)
                    $checkSql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table_element .
                               " WHERE fk_product = ? AND entity = ?";
                    $checkParams = [(int)$dolibarrId, (int)$this->entity];
                    if ($fkStore > 0) {
                        $checkSql .= " AND fk_store = ?";
                        $checkParams[] = $fkStore;
                    }

                    // For variants, also check parent ID to allow multiple variants of same parent
                    if ($dolibarrParentId !== null) {
                        $checkSql .= " AND fk_product_parent = ?";
                        $checkParams[] = (int)$dolibarrParentId;
                    } else {
                        // For simple products, ensure no record exists regardless of parent status
                        $checkSql .= " AND (fk_product_parent IS NULL OR fk_product_parent = 0)";
                    }

                    $checkResult = SqlUtils::executeQuery($this->db, $checkSql, "checking product mapping existence", false, $checkParams);
                    if ($checkResult) {
                        $row = $this->db->fetch_object($checkResult);
                        if ($row && $row->count > 0) {
                            $this->log("Product mapping already exists for Dolibarr ID " . $dolibarrId . " (fk_store=" . $fkStore . ") - skipping INSERT to prevent duplicate", LOG_WARNING);
                            return true; // Don't fail, just skip the duplicate creation
                        }
                    }

                    // For INSERT, always use default tms (CURRENT_TIMESTAMP) unless status is 'success'
                    // Story 47-3 : inclure fk_store dans l'INSERT
                    // FIX v2.4.1 : idem UPDATE, tms=NOW() explicite réservé au succès de contenu
                    if ($status === 'success' && $contentSynced) {
                        $insertSql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element .
                                   " (fk_product, entity, fk_store, shopifyProductId, shopifyVariantId, fk_product_parent, last_sync_status, tms) " .
                                   "VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    } else {
                        // Let tms use the default CURRENT_TIMESTAMP from table definition
                        $insertSql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element .
                                   " (fk_product, entity, fk_store, shopifyProductId, shopifyVariantId, fk_product_parent, last_sync_status) " .
                                   "VALUES (?, ?, ?, ?, ?, ?, ?)";
                    }

                    $insertParams = [
                        (int)$dolibarrId,
                        (int)$this->entity,
                        $fkStore,
                        $cleanShopifyId,
                        $cleanVariantId ?? '',
                        $dolibarrParentId ? (int)$dolibarrParentId : null,
                        $status ?? 'pending'
                    ];

                    $this->log("Executing INSERT: " . $insertSql . " with params: " . json_encode($insertParams), LOG_DEBUG);

                    try {
                        $result = SqlUtils::executeQuery($this->db, $insertSql, "inserting product mapping", true, $insertParams, 5);
                        $affectedRows = 1; // INSERT success
                    } catch (Exception $insertException) {
                        // AC3 : lire lasterrno() IMMÉDIATEMENT dans le catch — rien d'autre n'a
                        // requêté $this->db entre l'échec et ici (SqlUtils::executeQuery lève dès que
                        // $db->query() échoue, sans requête intermédiaire). DoliDB normalise l'errno
                        // MySQL/MariaDB 1062 en la chaîne 'DB_ERROR_RECORD_ALREADY_EXISTS'
                        // (core/db/mysqli.class.php, vérifié sur Dolibarr 18/23/24) — jamais l'entier
                        // 1062 brut, que DoliDB ne renvoie jamais.
                        if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                            // Absorption : un appelant concurrent a gagné la course entre notre
                            // re-vérification et notre INSERT (fenêtre résiduelle, ex. un tiers non
                            // couvert par ce verrou). Traiter comme "déjà existant" : appliquer nos
                            // propres données sur la ligne survivante plutôt que perdre l'écriture.
                            // LOG_DEBUG seulement : une fois ce correctif en place, ce n'est plus une
                            // anomalie mais la confirmation que le filet de secours a joué.
                            $this->log("manageProductMapping - Collision absorbée (DB_ERROR_RECORD_ALREADY_EXISTS)"
                                . " pour fk_product=" . $dolibarrId . " fk_store=" . $fkStore
                                . " : mapping déjà créé par un appel concurrent, mise à jour de la ligne existante", LOG_DEBUG);

                            $retryResult = SqlUtils::executeQuery($this->db, $sql, "updating product mapping after absorbed duplicate", false, $allParams, 1);
                            $result = true;
                            $affectedRows = $retryResult ? $this->db->affected_rows($retryResult) : 0;
                        } else {
                            // Toute autre erreur remonte comme avant (pas de masquage).
                            throw $insertException;
                        }
                    }
                } finally {
                    // Libération garantie sur TOUS les chemins de sortie (succès, skip, exception).
                    $this->releaseCronLock($lockName);
                }
            }

            if ($result) {
                $operation = $status ? " with status '$status'" : "";
                $this->log("Product mapping updated successfully for Dolibarr ID " . $dolibarrId . $operation . " (affected rows: " . $affectedRows . ")", LOG_INFO);

                // CRITICAL: Update variants in cascade when parent product status changes
                // Story 47-3 : cascade filtrée par fk_store (ne touche que les variantes de CETTE boutique)
                if ($status !== null && $dolibarrParentId === null && $isParentWithVariants) {
                    // This is a parent product with variants - execute cascade update in the same transaction
                    $this->log("Executing cascade update for variants of parent product " . $dolibarrId . " to status: " . $status . " (fk_store=" . $fkStore . ")", LOG_INFO);

                    // Prepare variant status update clauses
                    $variantUpdateClauses = ["last_sync_status = ?"];
                    $variantParams = [$status];

                    // CRITICAL: Manage sync lock based on status
                    if (in_array($status, ['success', 'failed', 'skipped'])) {
                        // Final statuses: release the lock
                        $variantUpdateClauses[] = "sync_lock = NULL";
                    } elseif ($status === 'pending') {
                        // Pending status: activate the lock with timestamp
                        $variantUpdateClauses[] = "sync_lock = NOW()";
                    }

                    // Only update TMS on 'success' status (FIX v2.4.1 : idem parent, pas en stock-only)
                    if ($status === 'success' && $contentSynced) {
                        $variantUpdateClauses[] = "tms = NOW()";
                    }

                    // Handle error message
                    if ($status === 'failed' && $errorMessage !== null) {
                        $variantUpdateClauses[] = "last_sync_error = ?";
                        $variantParams[] = $errorMessage;
                    } elseif ($status === 'success') {
                        // Clear error on success
                        $variantUpdateClauses[] = "last_sync_error = NULL";
                    }

                    // Update all variants of this parent in the same transaction
                    // Story 47-3 : filtre fk_store pour ne pas toucher les variantes d'une autre boutique
                    // (conditionné fkStore>0 ; fkStore=0 = legacy/fallback → pas de filtre, pré-47-3)
                    $variantSql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . "
                                 SET " . implode(", ", $variantUpdateClauses) . "
                                 WHERE fk_product_parent = ? AND entity = ?";

                    $variantParams[] = (int)$dolibarrId;
                    $variantParams[] = (int)$this->entity;
                    if ($fkStore > 0) {
                        $variantSql .= " AND fk_store = ?";
                        $variantParams[] = $fkStore;
                    }

                    $this->log("Executing variant cascade UPDATE: " . $variantSql . " with params: " . json_encode($variantParams), LOG_DEBUG);

                    $variantResult = SqlUtils::executeQuery($this->db, $variantSql, "updating variants status from parent", true, $variantParams, 5);
                    $variantAffectedRows = $this->db->affected_rows($variantResult);

                    if ($variantResult) {
                        $this->log("Successfully updated " . $variantAffectedRows . " variants to status '" . $status . "' from parent " . $dolibarrId . " (fk_store=" . $fkStore . ")", LOG_INFO);
                    } else {
                        $this->log("Failed to update variants status from parent " . $dolibarrId, LOG_ERR);
                    }
                }

                return true;
            } else {
                $this->log("Failed to update product mapping for Dolibarr ID " . $dolibarrId, LOG_ERR);
                return false;
            }

        } catch (Exception $e) {
            $this->log("Exception in manageProductMapping: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }



    /**
     * Save product mapping between Dolibarr and Shopify
     * @deprecated Use manageProductMapping() instead
     *
     * @param int $dolibarrId Dolibarr product ID
     * @param string $shopifyId Shopify product ID
     * @param string $shopifyVariantId Shopify variant ID (optional)
     * @param int $dolibarrParentId Parent product ID for variants (optional)
     * @return bool Success status
     */
    private function saveProductMapping($dolibarrId, $shopifyId, $shopifyVariantId = null, $dolibarrParentId = null)
    {
        // Use the unified function with 'pending' status by default (will only update tms on success)
        return $this->manageProductMapping($dolibarrId, $shopifyId, $shopifyVariantId, $dolibarrParentId, 'pending', null, false);
    }

    /**
     * Update timestamp for product mapping
     * @deprecated Use manageProductMapping() with 'success' status instead
     *
     * @param int $dolibarrId Dolibarr product ID
     * @param string $shopifyId Shopify product ID
     * @param string $shopifyVariantId Shopify variant ID (optional)
     * @return bool Success status
     */
    private function updateProductTimestamp($dolibarrId, $shopifyId, $shopifyVariantId = null)
    {
        // Use the unified function with 'success' status to update timestamp
        return $this->manageProductMapping($dolibarrId, $shopifyId, $shopifyVariantId, null, 'success', null, false);
    }

    /**
     * Get product sell status (handle both status and tosell properties)
     *
     * @param object $product Product object
     * @return bool True if product is for sale, false otherwise
     */
    private function getProductStatus($product)
    {
        // Debug logging
        $this->log("DEBUG getProductStatus for product: " . ($product->ref ?? 'unknown'), LOG_DEBUG);
        $this->log("  - status property: " . (isset($product->status) ? var_export($product->status, true) : "NOT SET"), LOG_DEBUG);
        $this->log("  - tosell property: " . (isset($product->tosell) ? var_export($product->tosell, true) : "NOT SET"), LOG_DEBUG);

        // Check if we have the status property (from Product::fetch)
        if (isset($product->status) && $product->status !== null && $product->status !== '') {
            $result = ($product->status == 1 || $product->status == '1');
            $this->log("  - Using status property, result: " . ($result ? "ACTIVE" : "DRAFT") . " (value type: " . gettype($product->status) . ")", LOG_DEBUG);
            return $result;
        }

        // Fallback to tosell property (from SQL query)
        if (isset($product->tosell) && $product->tosell !== null && $product->tosell !== '') {
            $result = ($product->tosell == 1 || $product->tosell == '1');
            $this->log("  - Using tosell property, result: " . ($result ? "ACTIVE" : "DRAFT") . " (value type: " . gettype($product->tosell) . ")", LOG_DEBUG);
            return $result;
        }

        // If both are empty/null, default to true (treat as "En vente")
        $this->log("Warning: No sell status found for product " . ($product->ref ?? 'unknown') . ", defaulting to En vente", LOG_WARNING);
        return true;
    }

    /**
     * AC2 (Story 58-5) : décide si la CRÉATION d'un produit sur Shopify doit être ignorée parce
     * que l'option DOLI2SHOP_SYNC_PRODUCTS_ONLY_ACTIVE est active et que le produit est hors
     * vente côté Dolibarr (tosell=0 / status=0). Réutilise getProductStatus() — ne duplique
     * jamais la logique status/tosell.
     *
     * ⚠️ INVARIANT (AC3) : cette méthode ne doit JAMAIS être appelée quand $isUpdate === true.
     * Le filtre porte sur la création uniquement — un produit déjà mappé qui passe hors vente
     * doit continuer d'être synchronisé et basculé en DRAFT sur Shopify, exactement comme
     * aujourd'hui. Le seul appelant légitime est syncProduct(), gardé par `if (!$isUpdate)`.
     *
     * @param object $dolProduct Dolibarr product object (Product ou stub compatible en test)
     * @return bool true = ne pas créer ce produit sur Shopify (skip)
     */
    private function shouldSkipInactiveProductCreation($dolProduct)
    {
        if (empty($this->config->sync_products_only_active) || $this->config->sync_products_only_active != 1) {
            return false;
        }

        return !$this->getProductStatus($dolProduct);
    }

    /**
     * AC5 (Story 58-5) : efface le marqueur offsale_action_status='archived' quand le produit
     * repasse "En vente" côté Dolibarr, afin qu'il ne réapparaisse plus jamais comme "déjà
     * traité" par OffSaleProductsService s'il repasse un jour hors vente.
     *
     * Décision Dev (58-5, AC5) : UPDATE ciblé plutôt qu'un paramètre supplémentaire sur
     * manageProductMapping() — cette méthode a déjà ~7 appelants existants dans ce fichier ;
     * étendre sa signature pour cette seule colonne, utilisée par UN SEUL appelant (ce
     * garde-fou), aurait un risque d'oubli sur un appelant existant hors de proportion avec le
     * bénéfice. Un UPDATE ciblé, filtré entity + fk_store conditionnel (invariant §14
     * CLAUDE.dolibarr.md), est plus sûr et localisé.
     *
     * @param int $productId Dolibarr product rowid
     * @return void
     */
    private function clearOffsaleActionStatus($productId)
    {
        $fkStore = (int) $this->shopifyApi->getStoreId();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element .
               " SET offsale_action_status = NULL" .
               " WHERE fk_product = ? AND entity = ?";
        $params = [(int) $productId, (int) $this->entity];
        if ($fkStore > 0) {
            $sql .= " AND fk_store = ?";
            $params[] = $fkStore;
        }

        SqlUtils::executeQuery($this->db, $sql, "clearing offsale_action_status for product " . $productId, false, $params);
    }

    /**
    * Prepare product data for productSet mutation
    *
    * @param Product $dolProduct Dolibarr product object
    * @param array $variants Dolibarr product variants
    * @param string $tags Product tags
    * @param string $shopifyProductId Shopify product ID (optional for update)
    * @param array $strategy Synchronization strategy (optional)
    * @return array Product data for GraphQL mutation
    */
    private function prepareProductData($dolProduct, $variants = [], $tags = '', $shopifyProductId = null, $syncOptions = null)
    {
        global $conf;

        // On travaille toujours avec des objets Product maintenant
        $productId = $dolProduct->id;

        $this->log("Preparing product data for: " . $dolProduct->ref .
            (($shopifyProductId) ? " (Update - Shopify ID: " . $shopifyProductId . ")" : " (New)"), LOG_DEBUG);

        // Prepare product input with base required properties
        $productStatus = $this->getProductStatus($dolProduct);
        $shopifyStatus = $productStatus ? 'ACTIVE' : 'DRAFT';

        $this->log("Product status determination: " . $dolProduct->ref . " => " . $shopifyStatus . " (getProductStatus returned: " . var_export($productStatus, true) . ")", LOG_INFO);

        // Construire l'input selon la stratégie de synchronisation
        $input = [
            'status' => $shopifyStatus
        ];

        // Gestion intelligente du titre selon le contexte
        if (!$shopifyProductId) {
            // CRÉATION : Le titre est OBLIGATOIRE - toujours utiliser celui de Dolibarr
            $input['title'] = $dolProduct->label;
            $input['tags'] = !empty($tags) ? (is_array($tags) ? $tags : explode(',', $tags)) : [];

            // Ajouter le vendor si configuré
            if (!empty($this->config->shopify_vendor)) {
                $input['vendor'] = $this->config->shopify_vendor;
            }

            // Ajouter la description pour les créations
            $input['descriptionHtml'] = $dolProduct->description;

            $this->log("Création de produit - titre Dolibarr utilisé: " . $dolProduct->label, LOG_INFO);

        } else {
            // MISE À JOUR : Comportement selon sync_descriptions
            $input['tags'] = !empty($tags) ? (is_array($tags) ? $tags : explode(',', $tags)) : [];

            if (!$syncOptions || $syncOptions['sync_descriptions']) {
                // Sync activé : utiliser les données Dolibarr
                $input['title'] = $dolProduct->label;
                $input['descriptionHtml'] = $dolProduct->description;

                if (!empty($this->config->shopify_vendor)) {
                    $input['vendor'] = $this->config->shopify_vendor;
                }

                $this->log("Mise à jour avec sync_descriptions ON - titre Dolibarr: " . $dolProduct->label, LOG_INFO);

            } else {
                // Sync désactivé : récupérer et conserver le titre Shopify
                $this->log("Mise à jour avec sync_descriptions OFF - récupération du titre Shopify", LOG_INFO);

                $shopifyTitle = $this->shopifyApi->getProductTitle($shopifyProductId);
                if ($shopifyTitle !== null) {
                    $input['title'] = $shopifyTitle;
                    $this->log("Titre Shopify conservé: " . $shopifyTitle, LOG_INFO);
                } else {
                    // Fallback : utiliser le titre Dolibarr si erreur de récupération
                    $input['title'] = $dolProduct->label;
                    $this->log("Fallback - titre Dolibarr utilisé car récupération échouée: " . $dolProduct->label, LOG_WARNING);
                }

                // NE PAS inclure descriptionHtml pour préserver celle de Shopify
            }
        }

        // Gestion des collections (v2.0.26) - Les collections seront ajoutées après création du produit
        // Le champ collectionsToJoin n'est pas supporté dans ProductSetInput

        // For an update, include the product ID
        if ($shopifyProductId) {
            $input['id'] = 'gid://shopify/Product/' . $shopifyProductId;
        }

        // Prepare product options - Dolibarr is the single source of truth
        if (!empty($variants)) {
            // For products with variants, retrieve options from Dolibarr
            $productOptions = [];
            $dolibarrOptions = $this->getProductOptions($dolProduct);

            // If it's an update, retrieve existing option IDs
            $optionsIds = [];
            if ($shopifyProductId) {
                try {
                    $optionsIds = $this->shopifyApi->getProductOptionsIds($shopifyProductId);
                } catch (Exception $e) {
                    $this->log("Error retrieving existing option IDs: " . $e->getMessage(), LOG_WARNING);
                }
            }

            if (!empty($dolibarrOptions)) {
                foreach ($dolibarrOptions as $option) {
                    $optionValues = [];
                    if (isset($option['values']) && is_array($option['values'])) {
                        foreach ($option['values'] as $value) {
                            // Ensure values are in the correct format (object with key "name")
                            if (is_array($value) && isset($value['name'])) {
                                $optionValues[] = ['name' =>  $value['name']];
                            } else {
                                $optionValues[] = ['name' =>  $value];
                            }
                        }
                    }

                    $productOption = [
                        'name' =>  $option['name'],
                        'values' =>  $optionValues
                    ];

                    // If this option already exists in Shopify, keep its ID
                    if (isset($optionsIds[$option['name']])) {
                        $productOption['id'] = $optionsIds[$option['name']];
                    }

                    $productOptions[] = $productOption;
                    $this->log("Added option: " . $option['name'] . " with " . count($optionValues) . " values", LOG_DEBUG);
                }

                if (!empty($productOptions)) {
                    $input['productOptions'] = $productOptions;
                    $this->log("Generated " . count($productOptions) . " product options:", LOG_DEBUG);
                    foreach ($productOptions as $productOption) {
                        $this->log("Product Option: " . $productOption['name'] . " with values: " .
                            implode(', ', array_column($productOption['values'], 'name')), LOG_DEBUG);
                    }
                } else {
                    $this->log("WARNING: No valid product options generated for a product with variants!", LOG_WARNING);
                    // Fallback to avoid errors
                    $input['productOptions'] = [
                        [
                            'name' =>  'Variant',
                            'values' =>  [['name' =>  'Default']]
                        ]
                    ];
                }
            } else {
                $this->log("WARNING: No options returned from getProductOptions for a product with variants!", LOG_WARNING);
                // Fallback to avoid errors
                $input['productOptions'] = [
                    [
                        'name' =>  'Variant',
                        'values' =>  [['name' =>  'Default']]
                    ]
                ];
            }
        } else {
            // For a simple product, add the default option
            $input['productOptions'] = [
                [
                    'name' =>  'Title',
                    'values' =>  [['name' =>  'Default Title']]
                ]
            ];
        }

        // Prepare variant data
        $variantsData = [];

        if (!empty($variants)) {
            $this->log("Preparing data for " . count($variants) . " variants", LOG_DEBUG);

            // Create a mapping of options for validation
            $optionNameMap = [];
            foreach ($input['productOptions'] as $option) {
                $optionValues = [];
                foreach ($option['values'] as $valueObj) {
                    $optionValues[] = $valueObj['name'];
                }
                $optionNameMap[$option['name']] = $optionValues;
            }

            foreach ($variants as $variant) {
                // Get the sell status from the appropriate property (status or tosell)
                $sellStatus = isset($variant->status) ? $variant->status : $variant->tosell;

                // Log the variant status for debugging
                $this->log("Processing variant: " . $variant->ref . " - sell status: " . $sellStatus, LOG_DEBUG);

                // Skip variants marked as "Hors Ventes" - they will be removed from Shopify

                // Check if tosell/status is explicitly set to 0 (not just empty)
                if ($sellStatus !== null && $sellStatus !== '' && ($sellStatus == 0 || $sellStatus === '0')) {
                    $this->log("Skipping variant marked as Hors Ventes: " . $variant->ref . " (sell status: '" . var_export($sellStatus, true) . "')", LOG_DEBUG);
                    continue;
                }

                // If tosell/status is empty/null, treat as "En vente" (1) by default
                if ($sellStatus === null || $sellStatus === '') {
                    $this->log("Warning: sell status is empty for variant " . $variant->ref . ", treating as En vente", LOG_WARNING);
                }

                $shopifyVariantId = null;

                // Check if the variant exists in our mapping
                $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element . "
                WHERE fk_product = ? AND entity = " . (int)$this->entity;
                $result = SqlUtils::executeQuery($this->db, $sql, "checking variant mapping", true, [(int)$variant->id]);

                if ($result && $this->db->num_rows($result) > 0) {
                    $savedVariant = $this->db->fetch_object($result);
                    $shopifyVariantId = $savedVariant->shopifyVariantId;
                }

                // Get variant options
                $variantOptions = [];
                $options = $this->getVariantOptions($variant);

                // Log the variant options found
                $this->log("Variant " . $variant->ref . " has " . count($options) . " options", LOG_DEBUG);
                foreach ($options as $option) {
                    $this->log("Option: " . ($option['optionName'] ?? 'N/A') . " = " . ($option['name'] ?? 'N/A'), LOG_DEBUG);
                }

                // Créer un tableau associatif des options de variante par nom d'option
                $variantOptionsByName = [];
                foreach ($options as $option) {
                    if (!empty($option['optionName']) && !empty($option['name'])) {
                        // Check that the option and its value are valid according to the product options
                        if (
                            isset($optionNameMap[$option['optionName']]) &&
                            in_array($option['name'], $optionNameMap[$option['optionName']])
                        ) {
                            $variantOptionsByName[$option['optionName']] = [
                                'optionName' => $option['optionName'],
                                'name' => $option['name']
                            ];
                        } else {
                            $this->log("Warning: Option " . $option['optionName'] . " with value " . $option['name'] .
                                " does not match defined product options for variant " . $variant->ref, LOG_WARNING);
                            $this->log("Available values for " . $option['optionName'] . ": " .
                                implode(', ', $optionNameMap[$option['optionName']] ?? []), LOG_DEBUG);
                        }
                    }
                }

                // Vérifier que la variante a une valeur pour chaque option du produit
                $missingOptions = [];
                foreach ($input['productOptions'] as $productOption) {
                    $optionName = $productOption['name'];
                    if (!isset($variantOptionsByName[$optionName])) {
                        $missingOptions[] = $optionName;
                    }
                }

                // Si des options sont manquantes, journaliser et sauter cette variante
                if (!empty($missingOptions)) {
                    $this->log("Warning: Variant " . $variant->ref . " is missing values for options: " .
                        implode(', ', $missingOptions) . ". This variant will be skipped.", LOG_WARNING);
                    continue;
                }

                // Si toutes les options sont présentes, ajouter les à variantOptions
                foreach ($variantOptionsByName as $option) {
                    $variantOptions[] = $option;
                }

                // Skip to the next if no options found
                if (empty($variantOptions)) {
                    $this->log("Warning: No valid options found for variant " . $variant->ref, LOG_WARNING);
                    continue;
                }

                // Create variant data - Variants with status_tosell = 0 are already skipped
                // Préparer les données de base du variant
                // FIX #149: Correction logique inventoryPolicy - !isset() || == 1 est TOUJOURS TRUE
                $inventoryPolicy = (!empty($this->config->inventory_policy_continue_selling) && $this->config->inventory_policy_continue_selling == 1) ? 'CONTINUE' : 'DENY';
                $this->log("InventoryPolicy pour variant " . $variant->ref . " : " . $inventoryPolicy . " (config: " . ($this->config->inventory_policy_continue_selling ?? 'undefined') . ")", LOG_DEBUG);
                
                // Determine if it's a service based on product type
                $isService = (isset($variant->type) && $variant->type == 1);
                $this->log("Variant " . $variant->ref . " - Product type: " . ($variant->type ?? 'undefined') . ", Is service: " . ($isService ? 'true' : 'false'), LOG_DEBUG);
                
                $variantData = [
                    'sku' =>  $variant->ref,
                    'inventoryPolicy' =>  $inventoryPolicy,
                    'optionValues' =>  $variantOptions,
                    'inventoryItem' => [
                        'requiresShipping' => !$isService,  // Services don't require shipping
                        'tracked' => !$isService  // Services don't need inventory tracking
                    ]
                ];

                // Story 53-5 (site 3a, 1/2) : gate prix export via SyncFlowPolicy (remplace la
                // lecture directe de $this->config->sync_product_prices)
                if ($this->isExportFlowAllowed(SyncFlowPolicy::FLOW_PRICE)) {
                    // Déterminer le niveau de prix à utiliser
                    $price = 0;
                    $priceLevel = isset($this->config->sync_price_level) ? intval($this->config->sync_price_level) : 1;

                    // Récupérer le prix selon le niveau configuré
                    if (!getDolGlobalInt('PRODUIT_MULTIPRICES') || $priceLevel === 0) {
                        // Mode prix unique ou prix de base
                        // Allow zero prices for services (fk_product_type = 1)
                        $allowZeroPrice = $isService;
                        
                        if (!empty($variant->price_ttc) && ($variant->price_ttc > 0 || $allowZeroPrice)) {
                            $price = $variant->price_ttc;
                        } elseif (!empty($variant->price) && ($variant->price > 0 || $allowZeroPrice)) {
                            $price = $variant->price;
                        } elseif (isset($variant->price_ht) && ($variant->price_ht > 0 || $allowZeroPrice)) {
                            $price = $variant->price_ht;
                            // If we only have HT price, we might want to add VAT
                            if (!empty($variant->tva_tx)) {
                                $price = $price * (1 + ($variant->tva_tx / 100));
                            }
                        }
                    } else {
                        // Niveaux de prix (1-9)
                        // Journaliser tous les prix disponibles avec leurs labels
                        $this->log("=== Checking prices for variant " . $variant->ref . " ===", LOG_DEBUG);
                        $this->logMultiplePrices($variant);

                        // Essayer plusieurs formats possibles pour les niveaux de prix
                        // FIX: Option "Prioriser les prix TTC stockés" - Inverser ordre selon configuration
                        $price_found = false;

                        if (!empty($this->config->price_priority_ttc)) {
                            // PRIORITÉ AUX PRIX TTC STOCKÉS (corrige problèmes d'arrondi)
                            $this->log("Price priority mode: TTC stored prices FIRST", LOG_DEBUG);

                            // Format 1: multiprices_ttc[X] (PRIORITAIRE)
                            if (property_exists($variant, 'multiprices_ttc') &&
                               is_array($variant->multiprices_ttc) &&
                               isset($variant->multiprices_ttc[$priceLevel]) &&
                               ($variant->multiprices_ttc[$priceLevel] > 0 || $allowZeroPrice)) {
                                $price = $variant->multiprices_ttc[$priceLevel];
                                $price_found = true;
                                $this->log("Using stored TTC price (PRIORITY) from multiprices_ttc[" . $priceLevel . "] for " . $variant->ref . ": " . $price, LOG_DEBUG);
                            }
                            // Format 2: multiprices[X] (FALLBACK - prix HT à convertir)
                            elseif (property_exists($variant, 'multiprices') &&
                                    is_array($variant->multiprices) &&
                                    isset($variant->multiprices[$priceLevel]) &&
                                    ($variant->multiprices[$priceLevel] > 0 || $allowZeroPrice)) {
                                $price = $variant->multiprices[$priceLevel];
                                $price_found = true;
                                $this->log("Fallback to HT price from multiprices[" . $priceLevel . "] for " . $variant->ref . ", will convert to TTC", LOG_DEBUG);
                            }
                        } else {
                            // PRIORITÉ AU CALCUL HT+TVA (calcul dynamique)
                            $this->log("Price priority mode: HT+VAT calculation FIRST", LOG_DEBUG);

                            // Format 1: multiprices[X] (PRIORITAIRE - prix HT à convertir)
                            if (property_exists($variant, 'multiprices') &&
                               is_array($variant->multiprices) &&
                               isset($variant->multiprices[$priceLevel]) &&
                               ($variant->multiprices[$priceLevel] > 0 || $allowZeroPrice)) {
                                $price = $variant->multiprices[$priceLevel];
                                $price_found = true;
                                $this->log("Using HT price (PRIORITY) from multiprices[" . $priceLevel . "] for " . $variant->ref . ", will convert to TTC", LOG_DEBUG);
                            }
                            // Format 2: multiprices_ttc[X] (FALLBACK - prix TTC stocké)
                            elseif (property_exists($variant, 'multiprices_ttc') &&
                                    is_array($variant->multiprices_ttc) &&
                                    isset($variant->multiprices_ttc[$priceLevel]) &&
                                    ($variant->multiprices_ttc[$priceLevel] > 0 || $allowZeroPrice)) {
                                $price = $variant->multiprices_ttc[$priceLevel];
                                $price_found = true;
                                $this->log("Fallback to stored TTC price from multiprices_ttc[" . $priceLevel . "] for " . $variant->ref . ": " . $price, LOG_DEBUG);
                            }
                        }

                        // Format 3: price_level_X (dernier fallback)
                        if (!$price_found && property_exists($variant, 'price_level_' . $priceLevel)) {
                            $price = $variant->{'price_level_' . $priceLevel};
                            $price_found = true;
                            $this->log("Found price in price_level_" . $priceLevel . " for " . $variant->ref, LOG_DEBUG);
                        }

                        if ($price_found) {
                            // Si c'est un prix HT (de multiprices), convertir vers TTC
                            if (property_exists($variant, 'multiprices') &&
                                is_array($variant->multiprices) &&
                                isset($variant->multiprices[$priceLevel]) &&
                                $price == $variant->multiprices[$priceLevel]) {
                                // Prix HT: convertir vers TTC
                                if (!empty($variant->tva_tx)) {
                                    $price = $price * (1 + ($variant->tva_tx / 100));
                                    $this->log("Converted HT to TTC price for " . $variant->ref . ": " . $price, LOG_DEBUG);
                                } else {
                                    $this->log("Using HT price as-is (no VAT) for " . $variant->ref . ": " . $price, LOG_DEBUG);
                                }
                            }
                            // Si c'est déjà un prix TTC (de multiprices_ttc), pas de conversion
                            elseif (property_exists($variant, 'multiprices_ttc') &&
                                    is_array($variant->multiprices_ttc) &&
                                    isset($variant->multiprices_ttc[$priceLevel]) &&
                                    $price == $variant->multiprices_ttc[$priceLevel]) {
                                $this->log("Using stored TTC price for " . $variant->ref . ": " . $price, LOG_DEBUG);
                            }

                            $this->log("Variant " . $variant->ref . " using price level " . $priceLevel . ": " . $price, LOG_DEBUG);
                        } else {
                            // Fallback au prix standard si le niveau de prix n'est pas défini
                            $this->log("Variant " . $variant->ref . " price level " . $priceLevel . " not found, using standard price", LOG_WARNING);

                            if (!empty($variant->price_ttc) && ($variant->price_ttc > 0 || $allowZeroPrice)) {
                                $price = $variant->price_ttc;
                            } elseif (!empty($variant->price) && ($variant->price > 0 || $allowZeroPrice)) {
                                $price = $variant->price;
                            } elseif (isset($variant->price_ht) && ($variant->price_ht > 0 || $allowZeroPrice)) {
                                $price = $variant->price_ht;
                                // If we only have HT price, we might want to add VAT
                                if (!empty($variant->tva_tx)) {
                                    $price = $price * (1 + ($variant->tva_tx / 100));
                                }
                            }
                        }
                    }

                    $this->log("Variant " . $variant->ref . " final price: " . $price, LOG_DEBUG);
                    $variantData['price'] = number_format($price, 2, '.', '');
                }

                // Ajouter le code-barres uniquement si l'option de synchronisation des attributs est activée (==1)
                if (!empty($this->config->sync_product_attributes) && $this->config->sync_product_attributes == 1) {
                    $variantData['barcode'] = $variant->barcode ?: '';
                }

                // Add the ID if it's an existing variant
                if ($shopifyVariantId) {
                    $variantData['id'] = 'gid://shopify/ProductVariant/' . $shopifyVariantId;
                    $this->log("Added existing variant: " . $variant->ref . " (Shopify ID: " . $shopifyVariantId . ")", LOG_DEBUG);
                } else {
                    $this->log("Added new variant: " . $variant->ref, LOG_DEBUG);
                }

                $variantsData[] = $variantData;
            }
        } else {
            // For a simple product, add a default option value
            $defaultOption = [
                'optionName' =>  'Title',
                'name' =>  'Default Title'
            ];

            // Préparer les données de base du variant pour un produit simple
            // FIX #149: Correction logique inventoryPolicy - !isset() || == 1 est TOUJOURS TRUE
            $inventoryPolicy = (!empty($this->config->inventory_policy_continue_selling) && $this->config->inventory_policy_continue_selling == 1) ? 'CONTINUE' : 'DENY';
            $this->log("InventoryPolicy pour produit simple " . $dolProduct->ref . " : " . $inventoryPolicy . " (config: " . ($this->config->inventory_policy_continue_selling ?? 'undefined') . ")", LOG_DEBUG);
            
            // Determine if it's a service based on product type
            $isService = (isset($dolProduct->type) && $dolProduct->type == 1);
            $this->log("Simple product " . $dolProduct->ref . " - Product type: " . ($dolProduct->type ?? 'undefined') . ", Is service: " . ($isService ? 'true' : 'false'), LOG_DEBUG);
            
            $variantData = [
                'sku' =>  $dolProduct->ref,
                'inventoryPolicy' =>  $inventoryPolicy,
                'optionValues' =>  [$defaultOption],
                'inventoryItem' => [
                    'requiresShipping' => !$isService,  // Services don't require shipping
                    'tracked' => !$isService  // Services don't need inventory tracking
                ]
            ];

            // Story 53-5 (site 3a, 2/2) : gate prix export via SyncFlowPolicy (remplace la
            // lecture directe de $this->config->sync_product_prices)
            if ($this->isExportFlowAllowed(SyncFlowPolicy::FLOW_PRICE)) {
                // Déterminer le niveau de prix à utiliser
                $price = 0;
                $priceLevel = isset($this->config->sync_price_level) ? intval($this->config->sync_price_level) : 1;

                // Journaliser les informations sur le mode de prix
                $this->log("DEBUG: PRODUIT_MULTIPRICES value = " . getDolGlobalString('PRODUIT_MULTIPRICES', 'NOT SET'), LOG_INFO);
                $this->log("DEBUG: PRODUIT_MULTIPRICES_LIMIT value = " . getDolGlobalString('PRODUIT_MULTIPRICES_LIMIT', 'NOT SET'), LOG_INFO);

                if (getDolGlobalInt('PRODUIT_MULTIPRICES') == 1) {
                    $this->log("=== Starting product synchronization with multi-price mode ===", LOG_INFO);
                    $this->log("Multi-price configuration:", LOG_INFO);
                    $this->log("  - Number of price levels: " . getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT'), LOG_INFO);
                    $this->log("  - Sync price level: " . $priceLevel, LOG_INFO);

                    // Journaliser tous les prix disponibles avec leurs labels
                    $this->logMultiplePrices($dolProduct);
                } else {
                    $this->log("=== Starting product synchronization with single price mode ===", LOG_INFO);
                }

                // Récupérer le prix selon le niveau configuré
                if (!getDolGlobalInt('PRODUIT_MULTIPRICES') || $priceLevel === 0) {
                    // Mode prix unique ou prix de base
                    // Allow zero prices for services (fk_product_type = 1)
                    $allowZeroPrice = $isService;
                    
                    if (!empty($dolProduct->price_ttc) && ($dolProduct->price_ttc > 0 || $allowZeroPrice)) {
                        $price = $dolProduct->price_ttc;
                    } elseif (!empty($dolProduct->price) && ($dolProduct->price > 0 || $allowZeroPrice)) {
                        $price = $dolProduct->price;
                    } elseif (isset($dolProduct->price_ht) && ($dolProduct->price_ht > 0 || $allowZeroPrice)) {
                        $price = $dolProduct->price_ht;
                        // If we only have HT price, we might want to add VAT
                        if (!empty($dolProduct->tva_tx)) {
                            $price = $dolProduct->price_ht * (1 + ($dolProduct->tva_tx / 100));
                        }
                    }
                } else {
                    // Niveaux de prix (1-9)
                    // Essayer plusieurs formats possibles pour les niveaux de prix
                    // FIX: Option "Prioriser les prix TTC stockés" - Inverser ordre selon configuration
                    $price_found = false;

                    if (!empty($this->config->price_priority_ttc)) {
                        // PRIORITÉ AUX PRIX TTC STOCKÉS (corrige problèmes d'arrondi)
                        $this->log("Price priority mode: TTC stored prices FIRST", LOG_DEBUG);

                        // Format 1: multiprices_ttc[X] (PRIORITAIRE)
                        if (property_exists($dolProduct, 'multiprices_ttc') &&
                           is_array($dolProduct->multiprices_ttc) &&
                           isset($dolProduct->multiprices_ttc[$priceLevel]) &&
                           ($dolProduct->multiprices_ttc[$priceLevel] > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->multiprices_ttc[$priceLevel];
                            $price_found = true;
                            $this->log("Using stored TTC price (PRIORITY) from multiprices_ttc[" . $priceLevel . "] for " . $dolProduct->ref . ": " . $price, LOG_DEBUG);
                        }
                        // Format 2: multiprices[X] (FALLBACK - prix HT à convertir)
                        elseif (property_exists($dolProduct, 'multiprices') &&
                                is_array($dolProduct->multiprices) &&
                                isset($dolProduct->multiprices[$priceLevel]) &&
                                ($dolProduct->multiprices[$priceLevel] > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->multiprices[$priceLevel];
                            $price_found = true;
                            $this->log("Fallback to HT price from multiprices[" . $priceLevel . "] for " . $dolProduct->ref . ", will convert to TTC", LOG_DEBUG);
                        }
                    } else {
                        // PRIORITÉ AU CALCUL HT+TVA (calcul dynamique)
                        $this->log("Price priority mode: HT+VAT calculation FIRST", LOG_DEBUG);

                        // Format 1: multiprices[X] (PRIORITAIRE - prix HT à convertir)
                        if (property_exists($dolProduct, 'multiprices') &&
                           is_array($dolProduct->multiprices) &&
                           isset($dolProduct->multiprices[$priceLevel]) &&
                           ($dolProduct->multiprices[$priceLevel] > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->multiprices[$priceLevel];
                            $price_found = true;
                            $this->log("Using HT price (PRIORITY) from multiprices[" . $priceLevel . "] for " . $dolProduct->ref . ", will convert to TTC", LOG_DEBUG);
                        }
                        // Format 2: multiprices_ttc[X] (FALLBACK - prix TTC stocké)
                        elseif (property_exists($dolProduct, 'multiprices_ttc') &&
                                is_array($dolProduct->multiprices_ttc) &&
                                isset($dolProduct->multiprices_ttc[$priceLevel]) &&
                                ($dolProduct->multiprices_ttc[$priceLevel] > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->multiprices_ttc[$priceLevel];
                            $price_found = true;
                            $this->log("Fallback to stored TTC price from multiprices_ttc[" . $priceLevel . "] for " . $dolProduct->ref . ": " . $price, LOG_DEBUG);
                        }
                    }

                    // Format 3: price_level_X (dernier fallback)
                    if (!$price_found && property_exists($dolProduct, 'price_level_' . $priceLevel)) {
                        $price = $dolProduct->{'price_level_' . $priceLevel};
                        $price_found = true;
                        $this->log("Found price in price_level_" . $priceLevel . " for " . $dolProduct->ref, LOG_DEBUG);
                    }

                    if ($price_found) {
                        // Si c'est un prix HT (de multiprices), convertir vers TTC
                        if (property_exists($dolProduct, 'multiprices') &&
                            is_array($dolProduct->multiprices) &&
                            isset($dolProduct->multiprices[$priceLevel]) &&
                            $price == $dolProduct->multiprices[$priceLevel]) {
                            // Prix HT: convertir vers TTC
                            if (!empty($dolProduct->tva_tx)) {
                                $price = $price * (1 + ($dolProduct->tva_tx / 100));
                                $this->log("Converted HT to TTC price for " . $dolProduct->ref . ": " . $price, LOG_DEBUG);
                            } else {
                                $this->log("Using HT price as-is (no VAT) for " . $dolProduct->ref . ": " . $price, LOG_DEBUG);
                            }
                        }
                        // Si c'est déjà un prix TTC (de multiprices_ttc), pas de conversion
                        elseif (property_exists($dolProduct, 'multiprices_ttc') &&
                                is_array($dolProduct->multiprices_ttc) &&
                                isset($dolProduct->multiprices_ttc[$priceLevel]) &&
                                $price == $dolProduct->multiprices_ttc[$priceLevel]) {
                            $this->log("Using stored TTC price for " . $dolProduct->ref . ": " . $price, LOG_DEBUG);
                        }

                        $this->log("Product " . $dolProduct->ref . " using price level " . $priceLevel . ": " . $price, LOG_DEBUG);
                    } else {
                        // Fallback au prix standard si le niveau de prix n'est pas défini
                        $this->log("Product " . $dolProduct->ref . " price level " . $priceLevel . " not found, using standard price", LOG_WARNING);

                        if (!empty($dolProduct->price_ttc) && ($dolProduct->price_ttc > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->price_ttc;
                        } elseif (!empty($dolProduct->price) && ($dolProduct->price > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->price;
                        } elseif (isset($dolProduct->price_ht) && ($dolProduct->price_ht > 0 || $allowZeroPrice)) {
                            $price = $dolProduct->price_ht;
                            // If we only have HT price, we might want to add VAT
                            if (!empty($dolProduct->tva_tx)) {
                                $price = $dolProduct->price_ht * (1 + ($dolProduct->tva_tx / 100));
                            }
                        }
                    }
                }

                $this->log("Product " . $dolProduct->ref . " final price: " . $price, LOG_DEBUG);
                $variantData['price'] = number_format($price, 2, '.', '');
            }

            // Ajouter le code-barres uniquement si l'option de synchronisation des attributs est activée (==1)
            if (!empty($this->config->sync_product_attributes) && $this->config->sync_product_attributes == 1) {
                $variantData['barcode'] = $dolProduct->barcode ?: '';
            }

            // If it's an update of an existing simple product, retrieve the variant ID
            if ($shopifyProductId) {
                $sql = "SELECT shopifyVariantId FROM " . MAIN_DB_PREFIX . $this->table_element . "
                        WHERE fk_product = " . (int)$productId;
                $result = SqlUtils::executeQuery($this->db, $sql, "find variantID", false);
                if ($result && ($obj = $this->db->fetch_object($result)) && !empty($obj->shopifyVariantId)) {
                    $variantData['id'] = 'gid://shopify/ProductVariant/' . $obj->shopifyVariantId;
                    $this->log("Added existing simple product variant: " . $dolProduct->ref .
                        " (Shopify Variant ID: " . $obj->shopifyVariantId . ")", LOG_DEBUG);
                }
            }

            // Add variant data to the input
            $variantsData[] = $variantData;
        }

        // Add variants to the input if they are not empty
        if (!empty($variantsData)) {
            // Vérifier une dernière fois la validité des données avant de les envoyer à Shopify
            // Si le produit existe déjà, passer son ID pour vérifier les métachamps
            $shopifyProductId = !empty($shopifyFindResult->product) ? $shopifyFindResult->product->legacyResourceId : null;
            $validatedVariants = $this->validateVariantsBeforeSync($variantsData, $input['productOptions'], $shopifyProductId);
            $input['variants'] = $validatedVariants;

            $this->log("Added " . count($validatedVariants) . " validated variants to product data", LOG_DEBUG);

            // Journaliser les variantes qui ont été filtrées (si applicable)
            $filteredCount = count($variantsData) - count($validatedVariants);
            if ($filteredCount > 0) {
                $this->log("WARNING: " . $filteredCount . " variants were filtered out due to validation errors", LOG_WARNING);
            }
        } else {
            $this->log("WARNING: No variants data generated!", LOG_WARNING);
        }

        return $input;
    }

    /**
     * Journalise (AC1/AC2) et persiste (AC3) les non-appariés SKU issus de
     * SkuVariantMatcher::matchVariants() — appelée depuis updateVariantInventory().
     *
     * Les déclinaisons Dolibarr "Hors Ventes" (tosell=0) sont exclues du signalement : leur
     * absence de traitement est volontaire (cf. boucle principale d'updateVariantInventory()),
     * pas un défaut à faire remonter.
     *
     * @param  string      $shopifyProductId Shopify product ID (pour le message de log)
     * @param  array       $shopifyVariants  Toutes les variantes Shopify du produit (pour le compte total dans le log)
     * @param  array       $matchResult      Résultat de SkuVariantMatcher::matchVariants()
     * @param  int|null    $parentProductId  ID Dolibarr du produit parent/simple visé par la persistance (AC3)
     * @return void
     */
    private function reportUnmatchedVariants($shopifyProductId, array $shopifyVariants, array $matchResult, $parentProductId)
    {
        $totalShopifyVariants = count($shopifyVariants);

        // AC1 : déclinaisons Dolibarr sans variante Shopify correspondante.
        $reportableDolMismatches = [];
        foreach ($matchResult['unmatchedDolProducts'] as $dolProduct) {
            $isHorsVentes = ($dolProduct->tosell !== null && $dolProduct->tosell !== ''
                && ($dolProduct->tosell == 0 || $dolProduct->tosell === '0'));
            if ($isHorsVentes) {
                continue;
            }

            $reportableDolMismatches[] = $dolProduct;
            $this->log(
                "Déclinaison Dolibarr SANS variante Shopify correspondante : ref='" . $dolProduct->ref . "'"
                . " (produit ID " . (isset($dolProduct->id) ? $dolProduct->id : '?') . "), aucun SKU Shopify"
                . " ne correspond parmi les " . $totalShopifyVariants . " variante(s) du produit Shopify "
                . $shopifyProductId . " — le stock de cette déclinaison N'A PAS été synchronisé.",
                LOG_WARNING
            );
        }

        // AC2 : variantes Shopify sans déclinaison Dolibarr correspondante (SKU absent ou
        // inconnu) — c'est elle qui reste bloquée à 0 côté Shopify (symptôme signalé par la
        // cliente Cheer-Moda).
        foreach ($matchResult['unmatchedShopifyVariants'] as $shopifyVariant) {
            $rawSku = isset($shopifyVariant->sku) ? $shopifyVariant->sku : null;
            $normalizedSku = SkuVariantMatcher::normalizeSku($rawSku);
            $skuLabel = ($normalizedSku !== null) ? "'" . $normalizedSku . "'" : '(aucun SKU)';
            $variantId = isset($shopifyVariant->id) ? $shopifyVariant->id : '?';
            $this->log(
                "Variante Shopify SANS déclinaison Dolibarr correspondante : SKU=" . $skuLabel
                . " (variant ID " . $variantId . ") sur le produit Shopify " . $shopifyProductId
                . " — aucune référence Dolibarr ne porte ce SKU.",
                LOG_WARNING
            );
        }

        $dolMismatchCount = count($reportableDolMismatches);
        $shopifyMismatchCount = count($matchResult['unmatchedShopifyVariants']);

        $this->unmatchedVariantsCount += ($dolMismatchCount + $shopifyMismatchCount);

        // AC3 : persistance pour l'écran de diagnostic (admin/diagnostic.php et admin/health.php,
        // checkRecentSyncs()) — sur la ligne du produit PARENT (ou du produit simple), jamais sur
        // celle d'une déclinaison isolée : c'est cette ligne que le diagnostic interroge (colonnes
        // ajoutées par sql/update_2.5.4_2.5.5.sql). Écrit aussi le cas 0/0 pour EFFACER un
        // signalement devenu obsolète (produit réparé depuis le dernier cycle).
        if ($parentProductId !== null && (int) $parentProductId > 0) {
            $note = null;
            if ($dolMismatchCount > 0 || $shopifyMismatchCount > 0) {
                $noteParts = [];
                if ($dolMismatchCount > 0) {
                    $sampleRefs = array_slice(array_map(function ($p) {
                        return $p->ref;
                    }, $reportableDolMismatches), 0, 5);
                    $noteParts[] = $dolMismatchCount . " déclinaison(s) Dolibarr sans variante Shopify ("
                        . implode(', ', $sampleRefs) . ($dolMismatchCount > count($sampleRefs) ? ', ...' : '') . ")";
                }
                if ($shopifyMismatchCount > 0) {
                    $sampleSkus = array_slice(array_map(function ($v) {
                        $sku = SkuVariantMatcher::normalizeSku(isset($v->sku) ? $v->sku : null);
                        return $sku !== null ? $sku : '(sans SKU)';
                    }, $matchResult['unmatchedShopifyVariants']), 0, 5);
                    $noteParts[] = $shopifyMismatchCount . " variante(s) Shopify sans déclinaison Dolibarr ("
                        . implode(', ', $sampleSkus) . ($shopifyMismatchCount > count($sampleSkus) ? ', ...' : '') . ")";
                }
                $note = implode(' | ', $noteParts);
                // Défense en profondeur : variant_mismatch_note est un varchar(500). En mode SQL
                // strict (celui des installations clientes, cf. CLAUDE.dolibarr.md §4), une valeur
                // trop longue est une ERREUR ("Data too long for column"), pas une troncature
                // silencieuse — tronquer nous-mêmes ici évite de déclencher cette erreur pour la
                // seule raison que 5+5 échantillons de références longues dépassent 500 caractères.
                if (strlen($note) > 500) {
                    $note = substr($note, 0, 497) . '...';
                }
            }

            // AC3 : cette persistance est un CONFORT d'écran, jamais une condition de succès de la
            // synchronisation stock elle-même (qui a lieu APRÈS ce point, plus bas dans cette même
            // méthode). $throwException = false : si la migration sql/update_2.5.4_2.5.5.sql n'a
            // pas encore tourné (colonnes absentes) — ou toute autre erreur SQL imprévue — l'échec
            // de CETTE requête ne doit JAMAIS remonter comme Exception. Une Exception ici serait
            // interceptée par le catch(Exception) de syncProduct() (try englobant, plus haut dans
            // la pile), qui fait un ROLLBACK et arrête la synchronisation du produit AVANT que le
            // stock réel ne soit poussé vers Shopify : on retomberait alors dans un défaut BIEN PIRE
            // que celui de la story (silence sur UNE déclinaison) — plus aucun stock synchronisé du
            // tout, sur CHAQUE produit, à CHAQUE cycle, tant que la migration ne serait pas rejouée.
            $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
                . " SET variant_mismatch_dol_count = ?, variant_mismatch_shopify_count = ?,"
                . " variant_mismatch_note = ?, variant_mismatch_tms = NOW()"
                . " WHERE fk_product = ? AND entity = ? AND fk_product_parent IS NULL";
            $persistResult = SqlUtils::executeQuery(
                $this->db,
                $sql,
                "updating variant_mismatch stats after updateVariantInventory",
                false,
                [(int) $dolMismatchCount, (int) $shopifyMismatchCount, $note, (int) $parentProductId, (int) $this->entity]
            );
            if ($persistResult === false) {
                $this->log(
                    "Impossible de persister le compte de non-appariés SKU pour le produit ID "
                    . $parentProductId . " (migration sql/update_2.5.4_2.5.5.sql pas encore jouée ?)"
                    . " — le résumé de l'écran de diagnostic sera incomplet, mais la synchronisation"
                    . " stock du produit continue normalement (voir logs LOG_WARNING ci-dessus pour"
                    . " le détail SKU).",
                    LOG_WARNING
                );
            }
        }
    }

    /**
     * Update inventory and other variant-specific fields using productVariantsBulkUpdate
     *
     * @param string $shopifyProductId Shopify product ID (format: numeric ID without gid:// prefix)
     * @param array $shopifyVariants Shopify variant nodes
     * @param array $dolProducts Dolibarr products
     * @param int|null $parentProductId Story variante-sans-correspondance-sku-ignoree-en-silence
     *        (AC3) : ID du produit Dolibarr parent (ou du produit simple lui-même) sur la ligne
     *        duquel persister le compte de non-appariés — null = pas de persistance (compat
     *        arrière, comportement inchangé si l'appelant ne le fournit pas).
     * @return bool Success status
     */
    private function updateVariantInventory($shopifyProductId, $shopifyVariants, $dolProducts, $parentProductId = null)
    {
        // Story variante-sans-correspondance-sku-ignoree-en-silence : appariement extrait dans
        // SkuVariantMatcher::matchVariants() (fonction PURE, testée en isolation — AC5). Remplace
        // les deux tables associatives ad hoc ci-dessus + le `if` SANS `else` qui avalait
        // silencieusement toute déclinaison/variante non appariée (AC1/AC2 : plus jamais en silence).
        $matchResult = SkuVariantMatcher::matchVariants($shopifyVariants, $dolProducts);

        // AC1/AC2/AC3 : journalise ET persiste les non-appariés (hors "Hors Ventes", volontaire —
        // filtré à l'intérieur), avant de traiter les paires ci-dessous.
        $this->reportUnmatchedVariants($shopifyProductId, $shopifyVariants, $matchResult, $parentProductId);

        // Index des paires par identité d'objet Dolibarr (spl_object_id) : préserve exactement
        // l'ordre et le contenu de la boucle d'origine sur $dolProducts (Hors Ventes, traitement
        // stock, etc.) — seule la SOURCE de la correspondance SKU change.
        $shopifyVariantByDolProductKey = [];
        foreach ($matchResult['pairs'] as $pair) {
            $shopifyVariantByDolProductKey[spl_object_id($pair['dolProduct'])] = $pair['shopifyVariant'];
        }

        // 1. Prepare variants data for bulk update
        $variantsData = [];

        // 2. Prepare inventory quantities for separate update
        $inventoryQuantities = [];

        foreach ($dolProducts as $dolProduct) {
            // Skip images for variants that are "Hors Ventes"
            // Check if tosell is explicitly set to 0 (not just empty)
            if ($dolProduct->tosell !== null && $dolProduct->tosell !== '' && ($dolProduct->tosell == 0 || $dolProduct->tosell === '0')) {
                $this->log("Skipping inventory for Hors Ventes variant: " . $dolProduct->ref . " (tosell value: '" . var_export($dolProduct->tosell, true) . "')", LOG_DEBUG);
                continue;
            }

            if (isset($shopifyVariantByDolProductKey[spl_object_id($dolProduct)])) {
                $shopifyVariant = $shopifyVariantByDolProductKey[spl_object_id($dolProduct)];

                // Prepare base variant data structure
                // FIX #141 v2.1.1: Services ne doivent PAS avoir tracked=true
                $isService = (isset($dolProduct->type) && $dolProduct->type == 1);
                $variantData = [
                    'id' => $shopifyVariant->id,
                    'inventoryItem' => [
                        'requiresShipping' => !$isService,  // Services don't require shipping
                        'tracked' => !$isService  // Services don't need inventory tracking
                    ]
                ];

                // Ajouter les attributs uniquement si l'option de synchronisation est activée (==1)
                if (!empty($this->config->sync_product_attributes) && $this->config->sync_product_attributes == 1) {

                    // Get country code of origin
                    $countryCode = $this->getProductCountryCode($dolProduct->fk_country);

                    // Prepare weight data
                    $weightData = $this->convertWeightUnit($dolProduct);

                    // Complete inventory item data with attributes
                    $variantData['inventoryItem']['harmonizedSystemCode'] = $dolProduct->customcode ?: null;
                    $variantData['inventoryItem']['countryCodeOfOrigin'] = $countryCode ?: 'FR';
                    $variantData['inventoryItem']['measurement'] = [
                        'weight' => [
                            'value' => (float)$weightData['weight'],
                            'unit' => strtoupper($weightData['unit'])
                        ]
                    ];
                }

                $variantsData[] = $variantData;

                // FIX #128 v2.0.36: Correction logique inversée - Synchroniser SEULEMENT si activé
                // [ERROR] AVANT (FAUX): empty() || == 1  →  Condition TOUJOURS vraie
                // [OK] APRÈS (CORRECT): !empty() && == 1  →  Synchronise SI configuré ET activé
                if (!empty($this->config->sync_product_stocks) && $this->config->sync_product_stocks == 1) {
                    // FIX #149: Logs diagnostiques pour debugging problème sync stocks client Philippe
                    $this->log("Synchronisation stocks ACTIVÉE pour produit " . $dolProduct->ref, LOG_DEBUG);

                    // Store inventory quantities for separate update
                    if ($shopifyVariant->inventoryItem && !$isService) {
                        $this->log("Produit " . $dolProduct->ref . " éligible sync stocks (inventoryItem présent, pas un service)", LOG_DEBUG);
                        // FIX #142 v2.1.2: Simplification massive - Remplacement de 150+ lignes complexes
                        // par l'utilisation de la méthode getProductStock() qui gère déjà toute la logique
                        // Cette méthode respecte automatiquement use_virtual_stock et gère tous les cas

                        // Forcer le chargement complet du stock avant de l'utiliser
                        if (method_exists($dolProduct, 'load_stock')) {
                            $this->log("Chargement préventif du stock pour " . $dolProduct->ref, LOG_DEBUG);
                            $dolProduct->load_stock('warehouseopen,warehouseinternal');
                        }

                        // Utiliser getProductStock() qui gère automatiquement stock virtuel vs réel
                        $stockFinal = $this->getProductStock($dolProduct);

                        // Log informatif pour debug
                        $stockType = ($this->config->use_virtual_stock) ? "virtuel (stock_theorique)" : "réel (stock_reel)";
                        $this->log("Stock " . $stockType . " pour " . $dolProduct->ref . ": " . $stockFinal, LOG_INFO);

                        // FIX #149: Validation shopify_location_id AVANT usage (prévention GID invalide)
                        if (empty($this->config->shopify_location_id)) {
                            $this->log("ERREUR CRITIQUE: shopify_location_id NON CONFIGURÉ - impossible synchroniser stocks pour " . $dolProduct->ref, LOG_ERR);
                            $this->log("Veuillez configurer shopify_location_id dans l'administration du module", LOG_ERR);
                        } else {
                            $inventoryItemId = str_replace('gid://shopify/InventoryItem/', '', $shopifyVariant->inventoryItem->id);
                            // FIX: Harmoniser les clés avec updateInventoryQuantities() (snake_case)
                            // FIX v2.4.1 (hotfix) : 'sku' ajouté pour permettre le log LOG_ERR/WARNING
                            // avec SKU concerné plus loin (aggregation userErrors + variant fantôme,
                            // cf. boucle d'enrichissement current_shopify_quantity ci-dessous)
                            // 'dol_product_id' (review 3 couches 26/09/2026, CRITICAL) : permet, après
                            // le filtrage des références non résolues, de ne stamper `last_stock_sync`
                            // QUE pour les produits dont l'article a réellement été envoyé.
                            $inventoryQuantities[] = [
                                'inventory_item_id' =>  'gid://shopify/InventoryItem/' . $inventoryItemId,
                                'location_id' =>  'gid://shopify/Location/' . $this->config->shopify_location_id,
                                'available_adjustment' =>  $stockFinal,
                                'sku' => $dolProduct->ref,
                                'dol_product_id' => (int) $dolProduct->id
                            ];
                            $this->log("Produit " . $dolProduct->ref . " ajouté pour sync stock avec location_id: " . $this->config->shopify_location_id, LOG_DEBUG);
                        }
                    } else {
                        // FIX #149: Log diagnostic quand produit non éligible
                        $reason = $isService ? "produit est un service (type=1)" : "pas d'inventoryItem Shopify";
                        $this->log("Produit " . $dolProduct->ref . " NON éligible sync stocks: " . $reason, LOG_DEBUG);
                    }
                } else {
                    // FIX #149: Log diagnostic quand sync stocks désactivée
                    $this->log("Synchronisation stocks DÉSACTIVÉE (config: " . ($this->config->sync_product_stocks ?? 'undefined') . ")", LOG_DEBUG);
                }
            }
        }

        // First, update variants data
        if (!empty($variantsData)) {
            // Setup retry logic for variant updates
            $maxRetries = 3;
            $retryCount = 0;
            $successStatus = false;
            $permanentError = false;
            $lastErrorMessage = "";

            // Lists of error patterns that should not be retried (permanent errors)
            $permanentErrorPatterns = [
                "is invalid",
                "can't be blank",
                "not found",
                "does not exist",
                "access denied",
                "not authorized"
            ];

            while ($retryCount < $maxRetries && !$successStatus && !$permanentError) {
                if ($retryCount > 0) {
                    $this->log("Variant update retry attempt " . ($retryCount + 1) . " of " . $maxRetries, LOG_INFO);
                    sleep(2 * $retryCount); // Exponential backoff
                }

                try {
                    // Update variants
                    $response = $this->shopifyApi->productVariantsBulkUpdate($shopifyProductId, $variantsData);

                    // Check for GraphQL errors
                    if (isset($response->errors)) {
                        $errorDetails = json_encode($response->errors);
                        $this->log("GraphQL error on variant update attempt " . ($retryCount + 1) . ": " . $errorDetails, LOG_WARNING);
                        $lastErrorMessage = $errorDetails;

                        // Check if this is a permanent error
                        if ($this->isPermanentError($response->errors, $permanentErrorPatterns)) {
                            $this->log("Detected permanent GraphQL error - stopping variant retry attempts", LOG_WARNING);
                            $permanentError = true;
                        } else {
                            $this->log("Retryable GraphQL error - will attempt variant update again", LOG_INFO);
                            $retryCount++;
                        }
                        continue;
                    }

                    // Check for user errors in the response
                    if (
                        isset($response->data) &&
                        isset($response->data->productVariantsBulkUpdate) &&
                        !empty($response->data->productVariantsBulkUpdate->userErrors)
                    ) {

                        $userErrors = $response->data->productVariantsBulkUpdate->userErrors;
                        $errorDetails = json_encode($userErrors);
                        $this->log("Variant update error on attempt " . ($retryCount + 1) . ": " . $errorDetails, LOG_WARNING);
                        $lastErrorMessage = $errorDetails;

                        // Check if this is a permanent user error
                        if ($this->isPermanentError($userErrors, $permanentErrorPatterns)) {
                            $this->log("Detected permanent user error - stopping variant retry attempts", LOG_WARNING);
                            $permanentError = true;
                        } else {
                            $this->log("Retryable user error - will attempt variant update again", LOG_INFO);
                            $retryCount++;
                        }
                    } else {
                        // Success
                        $successStatus = true;
                        $this->log("Successfully updated variant data for product ID: " . $shopifyProductId, LOG_INFO);
                    }
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();
                    $this->log("Exception on variant update attempt " . ($retryCount + 1) . ": " . $errorMessage, LOG_WARNING);
                    $lastErrorMessage = $errorMessage;

                    // Check if the exception indicates a temporary error
                    if (
                        stripos($errorMessage, "timeout") !== false ||
                        stripos($errorMessage, "connection") !== false ||
                        stripos($errorMessage, "network") !== false ||
                        stripos($errorMessage, "rate limit") !== false ||
                        stripos($errorMessage, "too many requests") !== false ||
                        stripos($errorMessage, "503") !== false ||
                        stripos($errorMessage, "502") !== false ||
                        stripos($errorMessage, "504") !== false
                    ) {
                        $this->log("Retryable exception - will attempt variant update again", LOG_INFO);
                        $retryCount++;
                    } else {
                        $this->log("Non-retryable exception - stopping variant retry attempts", LOG_WARNING);
                        $permanentError = true;
                    }
                }
            }

            if (!$successStatus) {
                if ($permanentError) {
                    $this->log("Failed to update variants due to permanent error: " . $lastErrorMessage, LOG_ERR);

                    // Auto-nettoyage : si le produit n'existe pas dans Shopify, réinitialiser l'ID
                    if (stripos($lastErrorMessage, "Product does not exist") !== false) {
                        // Vérifier si l'auto-nettoyage est activé (par défaut: oui)
                        $autoCleanupEnabled = empty($this->config->disable_auto_cleanup) || $this->config->disable_auto_cleanup != 1;

                        if ($autoCleanupEnabled) {
                            $this->log("Auto-cleanup: Product does not exist in Shopify (ID: " . $shopifyProductId . "), resetting product ID", LOG_WARNING);

                            // Extraire l'ID numérique pour vérifier s'il n'y a pas confusion avec l'ID Dolibarr
                            $numericId = null;
                            if (preg_match('/\/Product\/(\d+)$/', $shopifyProductId, $matches)) {
                                $numericId = intval($matches[1]);
                            } elseif (is_numeric($shopifyProductId)) {
                                $numericId = intval($shopifyProductId);
                            }

                            // Si l'ID est très bas (< 100), c'est probablement une erreur
                            if ($numericId !== null && $numericId < 100) {
                                $this->log("WARNING: Low Shopify ID detected ($numericId) - might be a Dolibarr ID error", LOG_WARNING);
                            }

                            $this->resetInvalidShopifyProductId($shopifyProductId);
                        } else {
                            $this->log("Auto-cleanup disabled: Product does not exist error detected but cleanup skipped", LOG_WARNING);
                        }
                    }
                } else {
                    $this->log("Failed to update variants after " . $maxRetries . " attempts . Last error: " . $lastErrorMessage, LOG_ERR);
                }
                // Continue with other updates even if this one failed
            }
        }

        // Then, update inventory quantities
        // FIX #149: Log diagnostic du nombre de variants prêts pour mise à jour stocks
        $nbVariantsStocks = count($inventoryQuantities);
        $this->log("Nombre de variants prêts pour mise à jour stocks: " . $nbVariantsStocks, LOG_INFO);

        if (!empty($inventoryQuantities)) {
            // Hotfix 2.5.7 (RÈGLE UNIQUE, Validate 26/09/2026) : AVANT, `current_shopify_quantity`
            // était d'abord posée ICI (seed) depuis `ProductVariant.inventoryQuantity` — un TOTAL
            // toutes localisations, retourné par un appel getProductVariants() dédié — puis
            // écrasée par fillInventoryReferenceQuantities() ci-dessous QUAND la lecture par
            // localisation réussissait. Le trou : si cette lecture échouait (réseau), le seed
            // fautif n'était JAMAIS effacé et l'article repartait quand même en DELTA avec un
            // changeFromQuantity FAUX — reproduction exacte du défaut corrigé par ce hotfix (trou
            // résiduel signalé par le Validate). La SEULE source de current_shopify_quantity /
            // on_hand_shopify_quantity est désormais cette lecture À LA LOCALISATION VISÉE :
            // aucun seed, aucun appel getProductVariants() supplémentaire (devenu inutile, il ne
            // servait qu'à produire ce seed).
            //
            // Les articles restés sans quantité de référence partiraient sinon en repli
            // `inventorySetOnHandQuantities` avec `changeFromQuantity = 0` — c'est-à-dire en
            // affirmant à Shopify que leur stock enregistré vaut zéro. Cette mutation étant un
            // COMPARE-AND-SWAP et non une écriture absolue, Shopify refuse dès que la quantité
            // réelle diffère : 25 produits sur 76 rejetés d'un coup chez un client le 21/09/2026,
            // leur stock restant à l'ancienne valeur.
            //
            // On lit donc les DEUX quantités de référence À LA LOCALISATION VISÉE : `available`
            // pour le chemin delta, `on_hand` pour le chemin de repli — chacun compare la sienne.
            $this->fillInventoryReferenceQuantities($inventoryQuantities);

            // Hotfix 2.5.7 : tout article dont NI current_shopify_quantity NI
            // on_hand_shopify_quantity n'a pu être lu à cette localisation N'EST PAS envoyé ce
            // cycle-ci (ni en delta avec une référence fausse, ni en repli avec une référence non
            // résolue à 0 fabriqué) — journalisé, compté, retenté au cycle suivant.
            $inventoryQuantities = $this->dropUnresolvedInventoryReferences($inventoryQuantities, 'updateVariantInventory');

            if (!empty($inventoryQuantities)) {
                $inventoryStockResult = $this->updateInventoryQuantities($inventoryQuantities);
            } else {
                // Hotfix 2.5.7 : tous les articles de ce lot ont été écartés (référence non
                // résolue à la localisation visée) — rien n'a été envoyé à Shopify, et ce n'est
                // surtout PAS un succès : `updateInventoryQuantities([])` renverrait `true` de
                // façon triviale (tableau vide), ce qui aurait mis à jour `last_stock_sync`
                // ci-dessous comme si le stock avait été synchronisé. Ces produits ne seraient
                // alors plus jamais retentés au cycle suivant.
                $inventoryStockResult = false;
                $this->log("updateVariantInventory - Aucune quantite envoyee (toutes les references de ce lot etaient non resolues) - last_stock_sync non mis a jour, retente au prochain cycle", LOG_WARNING);
            }

            // v2.3.0: Mettre à jour last_stock_sync pour tous les produits dont les stocks ont été poussés
            //
            // Hotfix 2.5.7 (review 3 couches 26/09/2026, CRITICAL) : AVANT, cette boucle stampait
            // INCONDITIONNELLEMENT tous les $dolProducts passés à la méthode dès que
            // $inventoryStockResult était vrai — y compris les produits dont l'article avait été
            // ÉCARTÉ par dropUnresolvedInventoryReferences() (référence non résolue à la
            // localisation visée). Ces produits n'auraient alors plus jamais été retentés avant
            // l'expiration normale de la fenêtre de 15 minutes. Désormais, seuls les produits dont
            // l'article a réellement survécu au filtrage (présent dans $inventoryQuantities via
            // `dol_product_id`, collecté à la construction du lot plus haut) sont stampés.
            //
            // ⚠️ Effet de bord accepté : un produit "Hors Ventes" ou sans variante Shopify
            // appariée (jamais ajouté à $inventoryQuantities, indépendamment de ce hotfix) ne voit
            // plus non plus sa propre ligne stampée par cette méthode — sans conséquence sur
            // l'éligibilité du produit PARENT (gouvernée par SA PROPRE ligne, non celle de ses
            // variantes, cf. les requêtes SQL de sélection de importProducts()), seulement sur une
            // ligne de bookkeeping qui n'était de toute façon jamais resynchronisée pour lui.
            if ($inventoryStockResult) {
                // Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : invariant multi-boutiques
                // (CLAUDE.dolibarr.md §14, même motif que clearOffsaleActionStatus()) — filtrer par
                // `fk_store` dès que la boutique courante en a un, sans AUCUN filtre en
                // mono-boutique (fk_store == 0).
                $fkStore = (int) $this->shopifyApi->getStoreId();

                $syncedProductIds = array_flip(array_map(
                    static function ($iq) {
                        return (int) ($iq['dol_product_id'] ?? 0);
                    },
                    $inventoryQuantities
                ));

                foreach ($dolProducts as $dolProduct) {
                    if (!isset($dolProduct->id) || !$dolProduct->id) {
                        continue;
                    }

                    if (!isset($syncedProductIds[(int) $dolProduct->id])) {
                        $this->log("updateVariantInventory - last_stock_sync NON mis a jour pour le produit ID "
                            . (int)$dolProduct->id . " (article ecarte ce cycle-ci, cf. warnings ci-dessus) - retente au prochain cycle", LOG_INFO);
                        continue;
                    }

                    // Mettre à jour last_stock_sync du produit lui-même
                    $sqlUpdateStockSelf = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
                        . " SET last_stock_sync = NOW() WHERE fk_product = ? AND entity = ?";
                    $paramsStockSelf = [(int)$dolProduct->id, (int)$this->entity];
                    if ($fkStore > 0) {
                        $sqlUpdateStockSelf .= " AND fk_store = ?";
                        $paramsStockSelf[] = $fkStore;
                    }
                    SqlUtils::executeQuery($this->db, $sqlUpdateStockSelf, "updating product last_stock_sync after updateVariantInventory", true, $paramsStockSelf);

                    // Mettre à jour last_stock_sync des variantes (si produit parent)
                    $sqlUpdateStockVariants = "UPDATE " . MAIN_DB_PREFIX . $this->table_element
                        . " SET last_stock_sync = NOW() WHERE fk_product_parent = ? AND entity = ?";
                    $paramsStockVariants = [(int)$dolProduct->id, (int)$this->entity];
                    if ($fkStore > 0) {
                        $sqlUpdateStockVariants .= " AND fk_store = ?";
                        $paramsStockVariants[] = $fkStore;
                    }
                    SqlUtils::executeQuery($this->db, $sqlUpdateStockVariants, "updating variants last_stock_sync after updateVariantInventory", true, $paramsStockVariants);

                    $this->log("ImportProducts::updateVariantInventory - last_stock_sync updated for product ID " . (int)$dolProduct->id, LOG_DEBUG);
                }
            }
        } else {
            $this->log("Aucun variant à mettre à jour pour les stocks (tableau vide)", LOG_INFO);
        }

        return true;
    }

    /**
     * Update inventory quantities using inventorySetQuantities mutation
     *
     * FIX v2.4.1 (review 50-11, CRITICAL) : le retry de cette boucle ne renvoie plus
     * systématiquement TOUT `$quantities` à ShopifyApi::inventorySetQuantities() — depuis le
     * scindage delta/fallback (cf. ShopifyApi::inventorySetQuantities()), un échec retryable
     * sur un SEUL sous-lot (ex. fallback compare-and-swap) faisait rejouer aussi le sous-lot
     * delta déjà appliqué avec succès (mutation additive `inventoryAdjustQuantities` -> delta
     * compté 2x, stock Shopify faussé). Désormais, quand la réponse agrégée expose
     * `retryableQuantities` (items des SEULS sous-lots en échec, cf.
     * ShopifyApi::aggregateInventoryResults()), `$quantities` est réduit à cette liste avant la
     * tentative suivante — un sous-lot réussi n'est jamais rejoué.
     *
     * @param array $quantities Array of inventory quantities
     * @return bool Success status
     */
    private function updateInventoryQuantities($quantities)
    {
        if (empty($quantities)) {
            return true;
        }

        // v2.0.36: Validate inventory IDs format before processing
        $validatedQuantities = [];
        foreach ($quantities as $qty) {
            $inventoryItemId = $qty['inventory_item_id'] ?? '';
            $locationId = $qty['location_id'] ?? '';
            $availableAdjustment = $qty['available_adjustment'] ?? 0;

            // Validate inventory item ID format (should be a GID like gid://shopify/InventoryItem/12345)
            if (empty($inventoryItemId)) {
                $this->log("Skipping inventory update - empty inventory_item_id", LOG_WARNING);
                continue;
            }

            if (!preg_match('/^gid:\/\/shopify\/InventoryItem\/\d+$/', $inventoryItemId)) {
                $this->log("Invalid inventory_item_id format: {$inventoryItemId} (expected: gid://shopify/InventoryItem/NUMBER)", LOG_WARNING);
                continue;
            }

            // Validate location ID format (should be a GID like gid://shopify/Location/12345)
            if (empty($locationId)) {
                $this->log("Skipping inventory update - empty location_id for item {$inventoryItemId}", LOG_WARNING);
                continue;
            }

            if (!preg_match('/^gid:\/\/shopify\/Location\/\d+$/', $locationId)) {
                $this->log("Invalid location_id format: {$locationId} (expected: gid://shopify/Location/NUMBER)", LOG_WARNING);
                continue;
            }

            // Validate adjustment is numeric
            if (!is_numeric($availableAdjustment)) {
                $this->log("Invalid available_adjustment: {$availableAdjustment} (expected numeric value) for item {$inventoryItemId}", LOG_WARNING);
                continue;
            }

            $validatedQuantities[] = $qty;
            $this->log("Validated inventory data - item: {$inventoryItemId}, location: {$locationId}, adjustment: {$availableAdjustment}", LOG_DEBUG);
        }

        if (empty($validatedQuantities)) {
            $this->log("No valid inventory quantities to update after validation", LOG_WARNING);
            return false;
        }

        $quantities = $validatedQuantities;

        // FIX v2.4.1 (hotfix) : SKU des items du lot, pour orienter le support dans les logs
        // d'erreur (Shopify n'attribue pas toujours ses userErrors à un item précis du lot).
        $skusInBatch = array_values(array_filter(array_map(static function ($qty) {
            return $qty['sku'] ?? null;
        }, $quantities)));

        // Setup retry logic
        $maxRetries = 3;
        $retryCount = 0;
        $successStatus = false;
        $permanentError = false;
        $lastErrorMessage = "";
        // FIX v2.4.1 : compte les items en échec de la DERNIÈRE tentative uniquement (évite de
        // compter plusieurs fois les mêmes items à chaque retry avant abandon)
        $lastFailedCount = 0;

        // Lists of error patterns that should not be retried (permanent errors)
        $permanentErrorPatterns = [
            "is invalid",
            "can't be blank",
            "not found",
            "does not exist",
            "access denied",
            "not authorized",
            "must include",
            "is not defined",
            "Field is not defined",
            "INVALID_FIELD",
            "INVALID_ARGUMENT",
            "provided invalid value"
        ];

        while ($retryCount < $maxRetries && !$successStatus && !$permanentError) {
            if ($retryCount > 0) {
                $this->log("Inventory update retry attempt " . ($retryCount + 1) . " of " . $maxRetries, LOG_INFO);
                sleep(2 * $retryCount); // Exponential backoff
            }

            try {
                // v2.0.36: Log detailed request information for debugging
                $quantitiesCount = count($quantities);
                $this->log("Sending inventorySetQuantities mutation with {$quantitiesCount} items (attempt " . ($retryCount + 1) . ")", LOG_INFO);

                // Log sample of quantities for debugging (first 3 items)
                $sampleQuantities = array_slice($quantities, 0, 3);
                foreach ($sampleQuantities as $qty) {
                    $this->log("Sample quantity data - inventory_item_id: {$qty['inventory_item_id']}, location_id: {$qty['location_id']}, available_adjustment: {$qty['available_adjustment']}", LOG_DEBUG);
                }

                $response = $this->shopifyApi->inventorySetQuantities($quantities);

                // v2.0.36: Log complete response for debugging (critical for Altairis issue)
                $responseJson = json_encode($response);
                $this->log("Full GraphQL response for inventorySetQuantities: " . $responseJson, LOG_DEBUG);

                // Check for top-level GraphQL errors
                // FIX v2.4.1 : !empty() au lieu de isset() — la réponse agrégée expose désormais
                // toujours la clé 'errors' (tableau vide si aucune erreur des 2 chemins delta/fallback)
                if (!empty($response->errors)) {
                    $errorDetails = json_encode($response->errors);
                    $this->log("GraphQL error on inventory update attempt " . ($retryCount + 1) . ": " . $errorDetails, LOG_WARNING);
                    $lastErrorMessage = $errorDetails;
                    // FIX v2.4.1 (review 50-11, MEDIUM) : cette branche (erreur GraphQL top-level)
                    // ne mettait pas à jour $lastFailedCount -> valeur périmée d'une tentative
                    // précédente remontée dans le résumé CRON. Erreur top-level = échec TOTAL du
                    // lot en cours (aucun item n'a pu être traité côté Shopify sur ce chemin).
                    $lastFailedCount = count($quantities);

                    // Check if this is a permanent error
                    if ($this->isPermanentError($response->errors, $permanentErrorPatterns)) {
                        $this->log("Detected permanent GraphQL error - stopping inventory retry attempts", LOG_WARNING);
                        $permanentError = true;
                    } else {
                        $this->log("Retryable GraphQL error - will attempt inventory update again", LOG_INFO);
                        // FIX v2.4.1 (review 50-11, CRITICAL) : ne rejouer QUE les sous-lots en
                        // échec (cf. ShopifyApi::aggregateInventoryResults()) — un sous-lot delta
                        // déjà appliqué avec succès ne doit JAMAIS être rejoué (mutation additive
                        // -> delta compté 2x, stock Shopify faussé).
                        if (!empty($response->retryableQuantities)) {
                            $quantities = $response->retryableQuantities;
                            $lastFailedCount = count($quantities);
                            $skusInBatch = array_values(array_filter(array_map(static function ($qty) {
                                return $qty['sku'] ?? null;
                            }, $quantities)));
                            $this->log("Inventory update retry - lot réduit aux sous-lots en échec ("
                                . implode(', ', $response->failedFields ?? []) . "): " . count($quantities)
                                . " item(s)", LOG_INFO);
                        }
                        $retryCount++;
                    }
                    continue;
                }

                // Check for user errors in the response
                // FIX v2.4.1 (hotfix) : AVANT, cette condition testait la clé morte
                // `$response->data->inventorySetQuantities` (jamais présente — le champ GraphQL
                // retourné porte le nom de la mutation réellement exécutée, inventoryAdjustQuantities
                // OU inventorySetOnHandQuantities) → TOUJOURS fausse → les userErrors Shopify
                // (ex: compare-and-swap mismatch) n'étaient JAMAIS détectées ni loggées, d'où les
                // stocks divergents "corrigés" en silence. `inventorySetQuantities()` retourne
                // désormais `userErrors` déjà fusionné des 2 chemins (delta ET fallback).
                if (!empty($response->userErrors)) {
                    $userErrors = $response->userErrors;
                    $errorDetails = json_encode($userErrors);
                    $lastFailedCount = $response->failedCount ?? count($userErrors);
                    // FIX v2.4.1 : LOG_ERR (pas WARNING) + SKUs du lot pour orienter le support
                    $this->log("Inventory update error on attempt " . ($retryCount + 1)
                        . " (" . $lastFailedCount . " item(s), SKUs concernés: "
                        . (!empty($skusInBatch) ? implode(', ', $skusInBatch) : 'inconnus')
                        . "): " . $errorDetails, LOG_ERR);
                    $lastErrorMessage = $errorDetails;

                    // Check if this is a permanent user error
                    if ($this->isPermanentError($userErrors, $permanentErrorPatterns)) {
                        $this->log("Detected permanent user error - stopping inventory retry attempts", LOG_ERR);
                        $permanentError = true;
                    } else {
                        $this->log("Retryable user error - will attempt inventory update again", LOG_INFO);
                        // FIX v2.4.1 (review 50-11, CRITICAL) : ne rejouer QUE les sous-lots en
                        // échec — cf. commentaire équivalent sur la branche errors[] ci-dessus.
                        // Un sous-lot delta réussi (userErrors vide sur inventoryAdjustQuantities)
                        // ne doit JAMAIS être renvoyé à inventorySetQuantities() sur ce retry.
                        if (!empty($response->retryableQuantities)) {
                            $quantities = $response->retryableQuantities;
                            $lastFailedCount = count($quantities);
                            $skusInBatch = array_values(array_filter(array_map(static function ($qty) {
                                return $qty['sku'] ?? null;
                            }, $quantities)));
                            $this->log("Inventory update retry - lot réduit aux sous-lots en échec ("
                                . implode(', ', $response->failedFields ?? []) . "): " . count($quantities)
                                . " item(s)", LOG_INFO);
                        }
                        $retryCount++;
                    }
                } else {
                    // Success - but let's log what exactly was successful
                    $successStatus = true;
                    $lastFailedCount = 0;

                    // v2.0.36: Log detailed success information
                    // FIX v2.4.1 : la réponse agrégée peut contenir les 2 chemins (delta ET
                    // fallback) si le lot a été scindé — logger les deux si présents
                    $resultsByField = [
                        'inventoryAdjustQuantities' => $response->data->inventoryAdjustQuantities ?? null,
                        'inventorySetOnHandQuantities' => $response->data->inventorySetOnHandQuantities ?? null,
                    ];
                    $loggedAny = false;
                    foreach ($resultsByField as $field => $result) {
                        if ($result === null) {
                            continue;
                        }
                        $loggedAny = true;
                        if (isset($result->inventoryAdjustmentGroup)) {
                            $adjustmentGroup = $result->inventoryAdjustmentGroup;
                            $this->log("Successfully updated inventory via {$field} - adjustmentGroup ID: " . ($adjustmentGroup->id ?? 'N/A'), LOG_INFO);

                            if (isset($adjustmentGroup->changes)) {
                                $changesCount = count($adjustmentGroup->changes);
                                $this->log("Inventory changes applied via {$field}: {$changesCount} items", LOG_INFO);

                                // Log first few changes for debugging
                                foreach (array_slice($adjustmentGroup->changes, 0, 3) as $change) {
                                    $itemId = $change->inventoryItem->id ?? 'N/A';
                                    $locationId = $change->location->id ?? 'N/A';
                                    $delta = $change->delta ?? 'N/A';
                                    $this->log("Change applied - item: {$itemId}, location: {$locationId}, delta: {$delta}", LOG_DEBUG);
                                }
                            }
                        } else {
                            $this->log("Successfully updated inventory via {$field} - no adjustmentGroup details in response", LOG_INFO);
                        }
                    }
                    if (!$loggedAny) {
                        $this->log("Successfully updated inventory quantities - no data details in response", LOG_INFO);
                    }
                }
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $this->log("Exception on inventory update attempt " . ($retryCount + 1) . ": " . $errorMessage, LOG_WARNING);
                $lastErrorMessage = $errorMessage;

                // Check if the exception indicates a temporary error
                if (
                    stripos($errorMessage, "timeout") !== false ||
                    stripos($errorMessage, "connection") !== false ||
                    stripos($errorMessage, "network") !== false ||
                    stripos($errorMessage, "rate limit") !== false ||
                    stripos($errorMessage, "too many requests") !== false ||
                    stripos($errorMessage, "503") !== false ||
                    stripos($errorMessage, "502") !== false ||
                    stripos($errorMessage, "504") !== false
                ) {
                    $this->log("Retryable exception - will attempt inventory update again", LOG_INFO);
                    $retryCount++;
                } else {
                    $this->log("Non-retryable exception - stopping inventory retry attempts", LOG_WARNING);
                    $permanentError = true;
                }
            }
        }

        if (!$successStatus) {
            // FIX v2.4.1 (hotfix) : compteur d'échecs remonté à l'appelant (résumé CRON visible),
            // incrémenté une seule fois (pas à chaque tentative de retry) avec le compte de la
            // dernière tentative connue
            $failedItemsThisBatch = $lastFailedCount > 0 ? $lastFailedCount : count($quantities);
            $this->stockSyncFailedCount += $failedItemsThisBatch;

            if ($permanentError) {
                $this->log("Failed to update inventory due to permanent error (" . $failedItemsThisBatch
                    . " item(s), SKUs: " . (!empty($skusInBatch) ? implode(', ', $skusInBatch) : 'inconnus')
                    . "): " . $lastErrorMessage, LOG_ERR);
            } else {
                $this->log("Failed to update inventory after " . $maxRetries . " attempts (" . $failedItemsThisBatch
                    . " item(s), SKUs: " . (!empty($skusInBatch) ? implode(', ', $skusInBatch) : 'inconnus')
                    . "). Last error: " . $lastErrorMessage, LOG_ERR);
            }
            return false;
        }

        return true;
    }

    /**
     * Get product options from Dolibarr
     *
     * @param object $dolProduct Dolibarr product
     * @return array Array of product options
     */
    private function getProductOptions($dolProduct)
    {
        // On travaille uniquement avec des objets Product
        $productId = $dolProduct->id;

        $this->log("Getting product options for product: " . $dolProduct->ref . " (ID: " . $productId . ")", LOG_DEBUG);

        $sql = "SELECT DISTINCT pa.rowid, pa.ref, pa.label
                FROM " . MAIN_DB_PREFIX . "product_attribute pa
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_value pav ON pav.fk_product_attribute = pa.rowid
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination2val pac2v ON pac2v.fk_prod_attr_val = pav.rowid
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON pac2v.fk_prod_combination = pac.rowid
                WHERE pac.fk_product_parent = ?
                ORDER BY pa.label";

        $result = SqlUtils::executeQuery($this->db, $sql, "getting product options", true, [(int)$productId]);

        $options = [];
        while ($obj = $this->db->fetch_object($result)) {
            // Get all possible values for this attribute
            $sql_values = "SELECT DISTINCT pav.value
                        FROM " . MAIN_DB_PREFIX . "product_attribute_value pav
                        INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination2val pac2v ON pac2v.fk_prod_attr_val = pav.rowid
                        INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON pac2v.fk_prod_combination = pac.rowid
                        WHERE pav.fk_product_attribute = ?
                        AND pac.fk_product_parent = ?";

            $result_values = SqlUtils::executeQuery($this->db,
                $sql_values,
                "getting attribute values",
                true,
                [(int)$obj->rowid, (int)$productId]
            );
            $values = [];
            while ($val = $this->db->fetch_object($result_values)) {
                $values[] = $val->value;
            }

            if ($result_values) {
                $this->db->free($result_values);
            }

            if (!empty($values)) {
                $options[] = [
                    'name' =>  $obj->label,
                    'values' =>  $values
                ];
            }
        }

        if ($result) {
            $this->db->free($result);
        }

        // If no options found but we have variants, create a default option
        if (empty($options)) {
            $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "product_attribute_combination
                    WHERE fk_product_parent = ?";
            $result = SqlUtils::executeQuery($this->db, $sql, "counting product variants", true, [(int)$productId]);
            if ($result && ($obj = $this->db->fetch_object($result)) && $obj->count > 0) {
                $this->log("No options found but product has variants . Creating default option .", LOG_WARNING);
                $options[] = [
                    'name' =>  'Variant',
                    'values' =>  ['Default']
                ];
            }
            if ($result) {
                $this->db->free($result);
            }
        }

        $this->log("Product options: " . print_r($options, true), LOG_DEBUG);
        return $options;
    }

    /**
     * Get variant options from Dolibarr product
     *
     * @param object $dolProductVariant Dolibarr product variant
     * @return array Array of options with name and optionName
     */
    protected function getVariantOptions($dolProductVariant)
    {
        $options = [];

        // Get the variant attributes from the combination table
        $sql = "SELECT pa.ref as attribute_ref, pa.label as attribute_label,
                pav.ref as value_ref, pav.value
                FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac
                LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute_combination2val pac2v ON pac2v.fk_prod_combination = pac.rowid
                LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute_value pav ON pac2v.fk_prod_attr_val = pav.rowid
                LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute pa ON pav.fk_product_attribute = pa.rowid
                WHERE pac.fk_product_child = ?";

        $result = SqlUtils::executeQuery($this->db, $sql, "getting variant options", true, [(int)$dolProductVariant->id]);

        while ($option = $this->db->fetch_object($result)) {
            if (!empty($option->attribute_label) && !empty($option->value)) {
                $options[] = [
                    'name' =>  $option->value,
                    'optionName' =>  $option->attribute_label
                ];
            }
        }

        if ($result) {
            $this->db->free($result);
        }

        if (empty($options)) {
            $this->log("No options found for variant " . $dolProductVariant->ref . " . Using default title .", LOG_WARNING);
            $options[] = [
                'name' =>  'Default Title',
                'optionName' =>  'Title'
            ];
        }

        return $options;
    }

    /**
     * Convert weight unit from Dolibarr to Shopify format
     *
     * @param object $dolproduct Dolibarr product object
     * @return array Array with weight value and unit
     */
    private function convertWeightUnit($dolproduct)
    {
        $weight = $dolproduct->weight;
        $shopifyweightunits = 'POUNDS'; // Default weight unit

        // Convert weight based on Dolibarr weight units
        switch ($dolproduct->weight_units) {
            case 0: // kg
                $shopifyweightunits = 'KILOGRAMS';
                break;
            case 99: // pound
                $shopifyweightunits = 'POUNDS';
                break;
            case -3: // grams
                $shopifyweightunits = 'GRAMS';
                break;
            case 98: // ounce
                $shopifyweightunits = 'OUNCES';
                break;
            case 3: // ton
                $weight = $weight * 1000; // Convert ton to kg
                $shopifyweightunits = 'KILOGRAMS';
                break;
            case -6: // milligram
                $weight = $weight / 1000; // Convert mg to g
                $shopifyweightunits = 'GRAMS';
                break;
        }

        return array(
            'weight' => (float)$weight,
            'unit' => $shopifyweightunits
        );
    }

    /**
     * Get country code for a Dolibarr country ID
     *
     * @param int $countryId Dolibarr country ID
     * @return string ISO country code
     */
    private function getProductCountryCode($countryId)
    {
        try {
            if (empty($countryId) || $countryId <= 0) {
                $this->log("Invalid country ID: " . $countryId . ", using default 'FR'", LOG_WARNING);
                return 'FR';
            }

            $sql = "SELECT code FROM " . MAIN_DB_PREFIX . "c_country WHERE rowid = ?";
            $result = SqlUtils::executeQuery($this->db, $sql, "getting country code", false, [(int)$countryId]);

            if ($result && ($obj = $this->db->fetch_object($result))) {
                $code = $obj->code;
                $this->db->free($result);
                return $code;
            } else {
                $this->log("Country ID not found: " . $countryId . ", using default 'FR'", LOG_WARNING);
                if ($result) {
                    $this->db->free($result);
                }
                return 'FR';
            }
        } catch (Exception $e) {
            $this->log("Error getting country code: " . $e->getMessage() . ", using default 'FR'", LOG_ERR);
            return 'FR';
        }
    }

    /**
     * Determine if an error is permanent (non-retryable) based on error messages
     *
     * @param mixed $errors Error object or array from API response
     * @param array $permanentErrorPatterns List of string patterns that indicate permanent errors
     * @return bool True if the error is permanent and should not be retried
     */
    private function isPermanentError($errors, $permanentErrorPatterns)
    {
        // Handle different error formats
        if (is_array($errors)) {
            // Array of error objects
            foreach ($errors as $error) {
                if (isset($error->message)) {
                    $errorMessage = $error->message;
                } elseif (isset($error->field) && isset($error->message)) {
                    $errorMessage = $error->field . ': ' . $error->message;
                } else {
                    $errorMessage = json_encode($error);
                }

                // Check if this error matches any permanent error pattern
                foreach ($permanentErrorPatterns as $pattern) {
                    if (stripos($errorMessage, $pattern) !== false) {
                        $this->log("Permanent error detected: '" . $pattern . "' in message: " . $errorMessage, LOG_WARNING);
                        return true;
                    }
                }
            }
        } elseif (is_object($errors)) {
            // Single error object or object with nested errors
            if (isset($errors->message)) {
                $errorMessage = $errors->message;

                // Check against patterns
                foreach ($permanentErrorPatterns as $pattern) {
                    if (stripos($errorMessage, $pattern) !== false) {
                        $this->log("Permanent error detected: '" . $pattern . "' in message: " . $errorMessage, LOG_WARNING);
                        return true;
                    }
                }
            } elseif (isset($errors->errors) && is_array($errors->errors)) {
                // Nested errors array
                return $this->isPermanentError($errors->errors, $permanentErrorPatterns);
            } else {
                // Unknown error format, convert to string
                $errorMessage = json_encode($errors);
                foreach ($permanentErrorPatterns as $pattern) {
                    if (stripos($errorMessage, $pattern) !== false) {
                        $this->log("Permanent error detected: '" . $pattern . "' in message: " . $errorMessage, LOG_WARNING);
                        return true;
                    }
                }
            }
        } else {
            // String or other format
            $errorMessage = (string)$errors;
            foreach ($permanentErrorPatterns as $pattern) {
                if (stripos($errorMessage, $pattern) !== false) {
                    $this->log("Permanent error detected: '" . $pattern . "' in message: " . $errorMessage, LOG_WARNING);
                    return true;
                }
            }
        }

        // No permanent error patterns matched
        return false;
    }

    /**
     * Get MIME type from filename
     *
     * @param string $filename Filename
     * @return string MIME type
     */
    /**
     * Valide les variantes avant synchronisation avec Shopify pour éviter les erreurs d'API
     * Vérifie également les métachamps existants pour préserver la compatibilité
     *
     * @param array $variants Tableau des données de variantes à valider
     * @param array $productOptions Options définies au niveau du produit
     * @param string|null $shopifyProductId ID du produit Shopify si existant
     * @return array Tableau des variantes validées
     */
    private function validateVariantsBeforeSync($variants, $productOptions, $shopifyProductId = null)
    {
        $this->log("Validating " . count($variants) . " variants before API sync", LOG_DEBUG);

        if (empty($variants)) {
            return $variants;
        }

        // Extraire les noms des options du produit
        $productOptionNames = array_map(function($option) {
            return $option['name'];
        }, $productOptions);

        $validatedVariants = [];
        $index = 0;

        foreach ($variants as $variant) {
            $valid = true;
            $index++;

            // Vérifier si la variante a des options
            if (empty($variant['optionValues'])) {
                $this->log("Validation failed: Variant at index " . $index . " has no optionValues", LOG_WARNING);
                $valid = false;
                continue;
            }

            // Vérifier que la variante a exactement une valeur pour chaque option du produit
            $optionsInVariant = [];
            foreach ($variant['optionValues'] as $optionValue) {
                if (empty($optionValue['optionName'])) {
                    $this->log("Validation failed: Option value in variant at index " . $index . " has no optionName", LOG_WARNING);
                    $valid = false;
                    continue;
                }

                // Validation spéciale pour tous les types d'options avec prise en compte des métachamps
                if (!empty($optionValue['optionName']) && !empty($optionValue['name'])) {
                    // Récupérer éventuellement l'ID Shopify du produit parent si disponible
                    $shopifyProductId = null;
                    if (!empty($variant['shopifyProductId'])) {
                        $shopifyProductId = $variant['shopifyProductId'];
                    } elseif (!empty($dolProduct->shopify_id)) {
                        $shopifyProductId = $dolProduct->shopify_id;
                    }

                    // Valider et adapter la valeur en fonction des métachamps existants
                    $validationResult = $this->validateOptionValue(
                        $optionValue['optionName'],
                        $optionValue['name'],
                        $shopifyProductId
                    );

                    if (!$validationResult['status']) {
                        $this->log("Validation failed: Variant at index " . $index . " (SKU: " . ($variant['sku'] ?? 'unknown') .
                            ") has invalid value '" . $optionValue['name'] . "' for option '" . $optionValue['optionName'] .
                            "' which might not be compatible with Shopify metafields", LOG_WARNING);
                        $valid = false;
                        continue;
                    }

                    // Si la valeur a été adaptée, mettre à jour la valeur dans le variant
                    if ($validationResult['adaptedValue'] !== $optionValue['name']) {
                        $this->log("Adapted value for option " . $optionValue['optionName'] . ": '" .
                            $optionValue['name'] . "' → '" . $validationResult['adaptedValue'] .
                            "' for variant " . ($variant['sku'] ?? 'unknown'), LOG_INFO);
                        $optionValue['name'] = $validationResult['adaptedValue'];
                    }

                    // Si un métachamp a été trouvé, le stocker pour mise à jour ultérieure
                    if (!empty($validationResult['metafield'])) {
                        if (!isset($variant['metafields'])) {
                            $variant['metafields'] = [];
                        }
                        $variant['metafields'][$optionValue['optionName']] = $validationResult['metafield'];
                    }
                }

                $optionsInVariant[] = $optionValue['optionName'];
            }

            // Vérifier si toutes les options du produit sont présentes dans la variante
            $missingOptions = array_diff($productOptionNames, $optionsInVariant);
            if (!empty($missingOptions)) {
                $this->log("Validation failed: Variant at index " . $index . " (SKU: " . ($variant['sku'] ?? 'unknown') .
                    ") is missing values for options: " . implode(', ', $missingOptions), LOG_WARNING);
                $valid = false;
                continue;
            }

            // Vérifier s'il y a des options en trop dans la variante
            $extraOptions = array_diff($optionsInVariant, $productOptionNames);
            if (!empty($extraOptions)) {
                $this->log("Validation failed: Variant at index " . $index . " (SKU: " . ($variant['sku'] ?? 'unknown') .
                    ") has extra options not defined in product: " . implode(', ', $extraOptions), LOG_WARNING);
                $valid = false;
                continue;
            }

            // Vérifier si la variante a des doublons d'options
            if (count($optionsInVariant) !== count(array_unique($optionsInVariant))) {
                $this->log("Validation failed: Variant at index " . $index . " (SKU: " . ($variant['sku'] ?? 'unknown') .
                    ") has duplicate option values", LOG_WARNING);
                $valid = false;
                continue;
            }

            // Si toutes les validations ont réussi, ajouter la variante au tableau des variantes validées
            if ($valid) {
                $validatedVariants[] = $variant;
            }
        }

        $this->log("Validation completed. " . count($validatedVariants) . " variants passed validation out of " .
            count($variants) . " total variants", LOG_INFO);

        return $validatedVariants;
    }

    /**
     * Valide et adapte les valeurs d'option en fonction du type d'option et des métachamps existants
     * Cette fonction est une passerelle vers des validateurs spécifiques selon le type d'option
     * Si le produit existe déjà sur Shopify, elle tente de conserver les métachamps associés
     *
     * @param string $optionName Nom de l'option (ex: 'Color', 'Size', etc.)
     * @param string $optionValue Valeur de l'option à valider
     * @param string|null $shopifyProductId ID du produit Shopify si existant
     * @return array Tableau associatif avec status (bool) et adaptedValue (string)
     */
    private function validateOptionValue($optionName, $optionValue, $shopifyProductId = null)
    {
        // Normaliser le nom de l'option pour les comparaisons
        $normalizedOptionName = strtolower(trim($optionName));
        $result = [
            'status' => false,
            'adaptedValue' => $optionValue,
            'metafield' => null
        ];

        // Si un produit Shopify existe, vérifier les métachamps associés
        if (!empty($shopifyProductId)) {
            $metafieldInfo = $this->shopifyApi->getProductOptionMetafield($shopifyProductId, $optionName);

            if ($metafieldInfo !== null) {
                $this->log("Found existing metafield for option '{$optionName}' on product {$shopifyProductId}: " .
                    $metafieldInfo['key'], LOG_INFO);
                $result['metafield'] = $metafieldInfo;

                // Vérifier si la valeur actuelle est compatible avec le métachamp
                // Si non, on pourrait ajuster la valeur pour la rendre compatible
                $isCompatible = $this->checkMetafieldCompatibility(
                    $normalizedOptionName,
                    $optionValue,
                    $metafieldInfo['key'],
                    $metafieldInfo['data']['value'] ?? null
                );

                if ($isCompatible) {
                    // La valeur est compatible avec le métachamp existant
                    $this->log("Value '{$optionValue}' is compatible with existing metafield {$metafieldInfo['key']}", LOG_DEBUG);
                    $result['status'] = true;
                    return $result;
                } else {
                    // Tenter d'adapter la valeur pour la rendre compatible
                    $adaptedValue = $this->adaptValueForMetafield(
                        $normalizedOptionName,
                        $optionValue,
                        $metafieldInfo['key']
                    );

                    if ($adaptedValue !== null) {
                        $this->log("Adapted value '{$optionValue}' to '{$adaptedValue}' for metafield compatibility", LOG_INFO);
                        $result['status'] = true;
                        $result['adaptedValue'] = $adaptedValue;
                        return $result;
                    }
                }
            }
        }

        // Si pas de produit Shopify ou pas de métachamp associé, ne pas faire de validation stricte
        // Les options sans metafields peuvent avoir n'importe quelle valeur valide non vide
        if (empty(trim($optionValue))) {
            $this->log("Warning: Empty value for option '{$optionName}'", LOG_WARNING);
            $result['status'] = false;
        } else {
            // Si pas de metafield associé, la valeur est acceptable telle quelle
            $this->log("Option '{$optionName}' has no associated metafield, accepting value '{$optionValue}' as-is", LOG_DEBUG);
            $result['status'] = true;
        }

        return $result;
    }

    /**
     * Vérifie si une valeur d'option est compatible avec un métachamp existant
     *
     * @param string $optionType Type d'option normalisé (ex: 'color')
     * @param string $optionValue Valeur d'option
     * @param string $metafieldKey Clé du métachamp
     * @param string|null $metafieldValue Valeur actuelle du métachamp
     * @return bool True si compatible, false sinon
     */
    private function checkMetafieldCompatibility($optionType, $optionValue, $metafieldKey, $metafieldValue = null)
    {
        // Cas spécial pour le métachamp color-pattern
        if ($metafieldKey === 'shopify.color-pattern') {
            // Vérifier si la valeur actuelle est déjà dans le métachamp (format JSON)
            if ($metafieldValue !== null) {
                $patterns = json_decode($metafieldValue, true);
                if (is_array($patterns)) {
                    foreach ($patterns as $pattern) {
                        if (
                            isset($pattern['name']) &&
                            strcasecmp(trim($pattern['name']), trim($optionValue)) === 0
                        ) {
                            return true;
                        }
                    }
                }
            }

            // Sinon, vérifier avec la validation standard
            return $this->validateColorOptionValue($optionValue);
        }

        // Traitement pour d'autres types de métachamps
        // Ajouter des cas spécifiques pour d'autres métachamps au besoin

        // Par défaut, utiliser la validation standard selon le type d'option
        if (in_array($optionType, ['color', 'colour', 'couleur'])) {
            return $this->validateColorOptionValue($optionValue);
        } elseif (in_array($optionType, ['material', 'matériau', 'matière'])) {
            return $this->validateMaterialOptionValue($optionValue);
        } elseif (in_array($optionType, ['pattern', 'motif'])) {
            return $this->validatePatternOptionValue($optionValue);
        }

        // Par défaut, accepter toute valeur non vide
        return !empty(trim($optionValue));
    }

    /**
     * Adapte une valeur d'option pour la rendre compatible avec un métachamp
     *
     * @param string $optionType Type d'option normalisé (ex: 'color')
     * @param string $optionValue Valeur d'option originale
     * @param string $metafieldKey Clé du métachamp
     * @return string|null Valeur adaptée ou null si impossible à adapter
     */
    private function adaptValueForMetafield($optionType, $optionValue, $metafieldKey)
    {
        // Normaliser la valeur
        $normalizedValue = trim($optionValue);

        // Cas spécial pour le métachamp color-pattern
        if ($metafieldKey === 'shopify.color-pattern' && in_array($optionType, ['color', 'colour', 'couleur'])) {
            // Rechercher une couleur standard équivalente
            $validColors = [
                // Liste simplifiée des couleurs standard
                'Black' => ['noir', 'black', 'schwarz', 'negro'],
                'White' => ['blanc', 'white', 'weiss', 'blanco'],
                'Red' => ['rouge', 'red', 'rot', 'rojo'],
                'Blue' => ['bleu', 'blue', 'blau', 'azul'],
                'Green' => ['vert', 'green', 'grün', 'verde'],
                'Yellow' => ['jaune', 'yellow', 'gelb', 'amarillo'],
                'Gray' => ['gris', 'grey', 'gray', 'grau', 'gris'],
                'Brown' => ['marron', 'brun', 'brown', 'braun', 'marrón']
            ];

            foreach ($validColors as $standard => $variations) {
                foreach ($variations as $variation) {
                    if (strcasecmp($normalizedValue, $variation) === 0) {
                        return $standard;
                    }
                }
            }

            // Si c'est un code hexadécimal sans #, ajouter le #
            if (preg_match('/^[0-9A-F]{6}$/i', $normalizedValue)) {
                return '#' . $normalizedValue;
            }
        }

        // Traitement pour d'autres types de métachamps
        // Logique similaire pour d'autres types de métachamps

        // Si aucune adaptation n'est possible, retourner null
        return null;
    }

    /**
     * Valide les valeurs de couleur pour s'assurer qu'elles sont compatibles avec le métachamp shopify.color-pattern
     *
     * @param string $colorValue Valeur de couleur à valider
     * @return bool True si la valeur de couleur est valide, false sinon
     */
    private function validateColorOptionValue($colorValue)
    {
        // Liste des valeurs de couleur standard acceptées par Shopify
        // Ces valeurs fonctionneront correctement avec les métachamps comme 'shopify.color-pattern'
        $validColors = [
            // Couleurs standards
            'Black', 'White', 'Gray', 'Red', 'Blue', 'Green', 'Yellow', 'Purple', 'Pink', 'Orange', 'Brown',
            'Noir', 'Blanc', 'Gris', 'Rouge', 'Bleu', 'Vert', 'Jaune', 'Violet', 'Rose', 'Orange', 'Marron',

            // Nuances de gris
            'Light Gray', 'Dark Gray', 'Charcoal', 'Silver',
            'Gris clair', 'Gris foncé', 'Anthracite', 'Argenté',

            // Nuances de bleu
            'Navy', 'Light Blue', 'Sky Blue', 'Teal', 'Turquoise', 'Azure',
            'Bleu Marine', 'Bleu Clair', 'Bleu Ciel', 'Canard', 'Turquoise', 'Azur',

            // Nuances de vert
            'Olive', 'Lime', 'Mint', 'Forest Green', 'Emerald',
            'Olive', 'Citron Vert', 'Menthe', 'Vert Forêt', 'Émeraude',

            // Nuances de rouge/rose
            'Burgundy', 'Crimson', 'Magenta', 'Coral', 'Salmon', 'Fuchsia',
            'Bordeaux', 'Cramoisi', 'Magenta', 'Corail', 'Saumon', 'Fuchsia',

            // Nuances de jaune/orange
            'Gold', 'Amber', 'Beige', 'Tan', 'Khaki',
            'Or', 'Ambre', 'Beige', 'Brun clair', 'Kaki',

            // Autres couleurs courantes
            'Ivory', 'Cream', 'Peach', 'Lavender', 'Indigo', 'Mustard',
            'Ivoire', 'Crème', 'Pêche', 'Lavande', 'Indigo', 'Moutarde'
        ];

        // Normaliser la valeur de couleur (Trim et correction de casse)
        $normalizedColor = trim($colorValue);

        // Vérifier si la couleur est dans la liste des couleurs valides (insensible à la casse)
        foreach ($validColors as $validColor) {
            if (strcasecmp($normalizedColor, $validColor) === 0) {
                return true;
            }
        }

        // Si la couleur n'est pas dans la liste, vérifier le format
        // Les couleurs personnalisées doivent suivre un format spécifique pour être reconnues par Shopify

        // Vérifier s'il s'agit d'un code couleur hexadécimal valide (avec ou sans #)
        if (preg_match('/^#?[0-9A-F]{6}$/i', $normalizedColor)) {
            return true;
        }

        // Vérifier s'il s'agit d'un format de couleur RGB
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/i', $normalizedColor)) {
            return true;
        }

        // Si aucune des vérifications ci-dessus n'a réussi, la couleur n'est pas valide
        $this->log("Color validation failed: '{$colorValue}' is not a valid color format for Shopify", LOG_WARNING);
        return false;
    }

    /**
     * Valide les valeurs de matériau pour assurer la compatibilité avec d'éventuels métachamps Shopify
     *
     * @param string $materialValue Valeur de matériau à valider
     * @return bool True si la valeur de matériau est valide, false sinon
     */
    private function validateMaterialOptionValue($materialValue)
    {
        // Liste de matériaux standard
        $validMaterials = [
            // Tissus et textiles
            'Cotton', 'Wool', 'Silk', 'Linen', 'Polyester', 'Nylon', 'Leather', 'Suede', 'Denim',
            'Coton', 'Laine', 'Soie', 'Lin', 'Polyester', 'Nylon', 'Cuir', 'Daim', 'Denim',

            // Métaux
            'Aluminum', 'Stainless Steel', 'Brass', 'Copper', 'Gold', 'Silver', 'Titanium',
            'Aluminium', 'Acier inoxydable', 'Laiton', 'Cuivre', 'Or', 'Argent', 'Titane',

            // Bois
            'Oak', 'Pine', 'Maple', 'Walnut', 'Cherry', 'Mahogany', 'Birch', 'Bamboo',
            'Chêne', 'Pin', 'Érable', 'Noyer', 'Cerisier', 'Acajou', 'Bouleau', 'Bambou',

            // Plastiques et composites
            'Plastic', 'PVC', 'Acrylic', 'Fiberglass', 'Carbon Fiber', 'Composite',
            'Plastique', 'PVC', 'Acrylique', 'Fibre de verre', 'Fibre de carbone', 'Composite',

            // Verre et céramique
            'Glass', 'Crystal', 'Ceramic', 'Porcelain', 'Terracotta',
            'Verre', 'Cristal', 'Céramique', 'Porcelaine', 'Terre cuite',

            // Pierres
            'Marble', 'Granite', 'Quartz', 'Slate', 'Limestone', 'Concrete',
            'Marbre', 'Granit', 'Quartz', 'Ardoise', 'Calcaire', 'Béton'
        ];

        // Normaliser la valeur
        $normalizedMaterial = trim($materialValue);

        // Vérifier dans la liste (insensible à la casse)
        foreach ($validMaterials as $validMaterial) {
            if (strcasecmp($normalizedMaterial, $validMaterial) === 0) {
                return true;
            }
        }

        // Vérifier s'il s'agit d'une valeur non vide
        if (!empty($normalizedMaterial)) {
            // Nous pourrions être plus stricts, mais pour l'instant
            // acceptons tout matériau non vide qui ne figure pas dans notre liste
            $this->log("Material validation: '{$materialValue}' is not in standard list but accepted", LOG_INFO);
            return true;
        }

        $this->log("Material validation failed: '{$materialValue}' is empty or invalid", LOG_WARNING);
        return false;
    }

    /**
     * Journalise tous les prix multi-niveaux disponibles avec leurs labels
     *
     * @param object $product Objet produit ou variant Dolibarr
     * @return void
     */
    private function logMultiplePrices($product)
    {
        global $conf;

        $this->log("=== Logging multiple prices for product/variant " . $product->ref . " ===", LOG_INFO);

        // Vérifier si on est en mode multiprix dans la configuration Dolibarr
        if (getDolGlobalInt('PRODUIT_MULTIPRICES') == 1) {
            $priceLimit = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');
            $this->log("Multi-price mode enabled with " . $priceLimit . " price levels", LOG_INFO);

            // Récupérer les labels de prix depuis la configuration
            $priceLevelLabels = [];
            for ($i = 1; $i <= $priceLimit; $i++) {
                $labelKey = 'PRODUIT_MULTIPRICES_LABEL' . $i;
                $labelValue = getDolGlobalString($labelKey);
                if ($labelValue !== '') {
                    $priceLevelLabels[$i] = $labelValue;
                }
            }

            // Journaliser les prix multi-niveaux TTC
            if (property_exists($product, 'multiprices_ttc') && is_array($product->multiprices_ttc)) {
                $this->log("Available TTC prices:", LOG_INFO);
                foreach ($product->multiprices_ttc as $level => $price) {
                    $label = "";

                    // Récupérer le label si disponible (d'abord depuis le produit, puis depuis la config)
                    if (property_exists($product, 'multiprices_label') &&
                        is_array($product->multiprices_label) &&
                        isset($product->multiprices_label[$level])) {
                        $label = " (label: " . $product->multiprices_label[$level] . ")";
                    } elseif (isset($priceLevelLabels[$level])) {
                        $label = " (config label: " . $priceLevelLabels[$level] . ")";
                    }

                    $this->log("  Level " . $level . $label . ": " . $price . " TTC", LOG_INFO);
                }
            }

            // Journaliser les prix multi-niveaux HT
            if (property_exists($product, 'multiprices') && is_array($product->multiprices)) {
                $this->log("Available HT prices:", LOG_INFO);
                foreach ($product->multiprices as $level => $price) {
                    $label = "";

                    // Récupérer le label si disponible (d'abord depuis le produit, puis depuis la config)
                    if (property_exists($product, 'multiprices_label') &&
                        is_array($product->multiprices_label) &&
                        isset($product->multiprices_label[$level])) {
                        $label = " (label: " . $product->multiprices_label[$level] . ")";
                    } elseif (isset($priceLevelLabels[$level])) {
                        $label = " (config label: " . $priceLevelLabels[$level] . ")";
                    }

                    $this->log("  Level " . $level . $label . ": " . $price . " HT", LOG_INFO);
                }
            }

            // Journaliser le niveau de prix configuré pour la synchronisation
            $priceLevel = isset($this->config->sync_price_level) ? intval($this->config->sync_price_level) : 1;
            $this->log("Configured price level for synchronization: " . $priceLevel, LOG_INFO);

            // Journaliser les propriétés de prix disponibles
            $this->log("Available price properties for " . $product->ref . ":", LOG_DEBUG);
            $priceProperties = ['price', 'price_ttc', 'price_ht', 'multiprices', 'multiprices_ttc'];
            foreach ($priceProperties as $prop) {
                if (property_exists($product, $prop)) {
                    if (is_array($product->$prop)) {
                        $this->log("  - " . $prop . ": array with keys " . implode(', ', array_keys($product->$prop)), LOG_DEBUG);
                    } else {
                        $this->log("  - " . $prop . ": " . $product->$prop, LOG_DEBUG);
                    }
                }
            }

            // Afficher le prix qui sera utilisé pour la synchronisation
            if ($priceLevel > 0) {
                $syncPrice = null;
                if (isset($product->multiprices_ttc[$priceLevel])) {
                    $syncPrice = $product->multiprices_ttc[$priceLevel];
                    $this->log("Price to be synchronized: " . $syncPrice . " TTC from level " . $priceLevel, LOG_INFO);
                } elseif (isset($product->multiprices[$priceLevel])) {
                    $syncPrice = $product->multiprices[$priceLevel];
                    $this->log("Price to be synchronized: " . $syncPrice . " HT from level " . $priceLevel, LOG_INFO);
                } else {
                    $this->log("WARNING: No price found for configured level " . $priceLevel, LOG_WARNING);
                }
            }
        } else {
            $this->log("Multi-price mode disabled - using single price", LOG_INFO);
        }

        $this->log("=== End of multiple prices logging ===", LOG_INFO);
    }

    /**
     * Traite les métachamps collectés pendant la validation des variantes
     * Met à jour ou crée les métachamps nécessaires après la synchronisation du produit
     *
     * @param array $variants Tableau des variantes validées
     * @param string $shopifyProductId ID du produit Shopify
     * @return void
     */
    private function processProductMetafields($variants, $shopifyProductId)
    {
        // Variables pour suivre les métachamps uniques à traiter
        $productMetafields = [];

        // Collecter tous les métachamps trouvés dans les variantes
        foreach ($variants as $variant) {
            if (!empty($variant['metafields'])) {
                foreach ($variant['metafields'] as $optionName => $metafieldInfo) {
                    $metafieldKey = $metafieldInfo['key'];

                    // Stocker les informations de métachamp par clé
                    if (!isset($productMetafields[$metafieldKey])) {
                        $productMetafields[$metafieldKey] = [
                            'metafieldId' => $metafieldInfo['data']['id'] ?? null,
                            'values' => [],
                            'optionName' => $optionName,
                        ];
                    }

                    // Stocker les valeurs d'option (unique)
                    if (!empty($variant['optionValues'])) {
                        foreach ($variant['optionValues'] as $optionValue) {
                            if (
                                !empty($optionValue['optionName']) &&
                                !empty($optionValue['name']) &&
                                strtolower($optionValue['optionName']) === strtolower($optionName)
                            ) {
                                $productMetafields[$metafieldKey]['values'][$optionValue['name']] = $optionValue['name'];
                            }
                        }
                    }
                }
            }
        }

        // Traiter et mettre à jour chaque métachamp unique
        foreach ($productMetafields as $metafieldKey => $metafieldData) {
            $parts = explode('.', $metafieldKey);
            if (count($parts) !== 2) continue;

            $namespace = $parts[0];
            $key = $parts[1];
            $metafieldId = $metafieldData['metafieldId'];
            $values = array_values($metafieldData['values']);

            // Cas spécial pour le métachamp color-pattern qui a un format JSON spécifique
            if ($metafieldKey === 'shopify.color-pattern') {
                // Préparer la valeur au format attendu par Shopify
                $colorPatterns = [];
                foreach ($values as $colorName) {
                    $colorPatterns[] = [
                        'name' => $colorName
                    ];
                }

                if (!empty($colorPatterns)) {
                    $jsonValue = json_encode($colorPatterns);
                    $this->log("Updating shopify.color-pattern metafield with " . count($colorPatterns) . " colors for product " .
                        $shopifyProductId, LOG_INFO);

                    // Mise à jour du métachamp
                    $this->shopifyApi->updateProductMetafield(
                        $shopifyProductId,
                        $namespace,
                        $key,
                        $jsonValue,
                        'json_string',
                        $metafieldId
                    );
                }
            }
            // Ajouter d'autres cas spéciaux ici pour d'autres types de métachamps
            else {
                // Par défaut, pour les métachamps simples, utiliser la première valeur
                if (!empty($values)) {
                    $value = $values[0];
                    $this->log("Updating metafield " . $metafieldKey . " with value " . $value . " for product " .
                        $shopifyProductId, LOG_INFO);

                    $this->shopifyApi->updateProductMetafield(
                        $shopifyProductId,
                        $namespace,
                        $key,
                        $value,
                        'string',
                        $metafieldId
                    );
                }
            }
        }
    }

    /**
     * Valide les valeurs de motif pour assurer la compatibilité avec d'éventuels métachamps Shopify
     *
     * @param string $patternValue Valeur de motif à valider
     * @return bool True si la valeur de motif est valide, false sinon
     */
    private function validatePatternOptionValue($patternValue)
    {
        // Liste de motifs standard
        $validPatterns = [
            // Motifs basiques
            'Solid', 'Striped', 'Checkered', 'Plaid', 'Dotted', 'Floral', 'Geometric',
            'Uni', 'Rayé', 'À carreaux', 'Écossais', 'À pois', 'Fleuri', 'Géométrique',

            // Motifs animaux
            'Animal Print', 'Leopard', 'Zebra', 'Snake', 'Crocodile',
            'Imprimé animal', 'Léopard', 'Zèbre', 'Serpent', 'Crocodile',

            // Motifs textiles
            'Herringbone', 'Houndstooth', 'Pinstripe', 'Argyle', 'Paisley', 'Damask',
            'Chevron', 'Pied-de-poule', 'Fine rayure', 'Écossais', 'Cachemire', 'Damas',

            // Motifs abstraits
            'Abstract', 'Tie-dye', 'Marble', 'Camouflage', 'Ombre', 'Gradient',
            'Abstrait', 'Tie-dye', 'Marbré', 'Camouflage', 'Dégradé', 'Gradient'
        ];

        // Normaliser la valeur
        $normalizedPattern = trim($patternValue);

        // Vérifier dans la liste (insensible à la casse)
        foreach ($validPatterns as $validPattern) {
            if (strcasecmp($normalizedPattern, $validPattern) === 0) {
                return true;
            }
        }

        // Vérifier s'il s'agit d'une valeur non vide
        if (!empty($normalizedPattern)) {
            // Pour les motifs, soyons moins stricts
            $this->log("Pattern validation: '{$patternValue}' is not in standard list but accepted", LOG_INFO);
            return true;
        }

        $this->log("Pattern validation failed: '{$patternValue}' is empty or invalid", LOG_WARNING);
        return false;
    }

    private function getMimeType($filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' =>  'image/jpeg',
            'jpeg' =>  'image/jpeg',
            'png' =>  'image/png',
            'gif' =>  'image/gif',
            'webp' =>  'image/webp'
        ];

        return $mimeTypes[$extension] ?? 'image/jpeg';
    }

    /**
     * Vérifie si une chaîne est au format JSON valide
     *
     * @param string $string La chaîne à vérifier
     * @return bool True si c'est du JSON valide, false sinon
     */
    private function isJson($string)
    {
        // Si ce n'est pas une chaîne, ce n'est pas du JSON
        if (!is_string($string)) {
            return false;
        }

        // Suppression des espaces au début et à la fin
        $string = trim($string);

        // Un JSON valide commence par { ou [
        if (empty($string) || ($string[0] !== '{' && $string[0] !== '[')) {
            return false;
        }

        // Tenter de décoder la chaîne
        json_decode($string);

        // Si json_last_error() retourne JSON_ERROR_NONE, la chaîne est du JSON valide
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get product images from Dolibarr
     *
     * Story 57-2 : délègue à DolibarrDirectFileResolver::listProductImages() — lecture
     * directe base+disque (llx_ecm_files + multidir_output), sans appel HTTP à l'API REST
     * Dolibarr self. Remplace l'ancien flux SQL (bug jumeau : colonne filesize absente de
     * llx_ecm_files, corrigée au niveau resolver 57-1) + HTTP + fallback SQL. Format de
     * retour et cache d'instance INCHANGÉS pour les appelants (syncProductAllImages).
     *
     * @param object $product Dolibarr product
     * @return array Array of image objects
     */
    private function getDolibarrImages($product)
    {
        // Patch 4 — Fix collision cache id=null/0 : skip cache si id pas entier strictement positif.
        // $product->id peut être string en PHP/Dolibarr → cast int avant comparaison.
        $productIdInt = (int)$product->id;
        $useCache = $productIdInt > 0;

        // Task 2.5 — Cache d'instance : évite des appels resolver répétés quand getDolibarrImages
        // est appelé plusieurs fois par produit dans syncProductAllImages
        if ($useCache && isset($this->imagesCache[$productIdInt])) {
            $this->log("getDolibarrImages - Cache hit for product " . $product->ref . " (ID: " . $productIdInt . ")", LOG_DEBUG);
            return $this->imagesCache[$productIdInt];
        }

        $this->log("getDolibarrImages - Getting Dolibarr images for product: " . $product->ref, LOG_DEBUG);
        $images = $this->getDirectFileResolver()->listProductImages($product);

        if ($useCache) {
            $this->imagesCache[$productIdInt] = $images;
        }

        $this->log("getDolibarrImages - Returning " . count($images) . " image(s) for product: " . $product->ref, LOG_DEBUG);

        return $images;
    }

    /**
     * Vide le cache d'images d'instance.
     *
     * À appeler en début de cycle batch (importProductsManual, importProducts)
     * pour éviter que le cache d'un produit précédent contamine le produit suivant.
     *
     * @return void
     */
    public function clearImagesCache(): void
    {
        $this->imagesCache = [];
        $this->log("getDolibarrImages - imagesCache cleared", LOG_DEBUG);
    }

    /**
     * Get unified image content for products
     *
     * Story 57-2 : la branche 'product' délègue à DolibarrDirectFileResolver::getImageBinary()
     * — lecture directe disque, sans appel HTTP à l'API REST Dolibarr self.
     * Story 57-7 : la branche 'category' (HTTP via un client Guzzle self) est SUPPRIMÉE — les
     * images de catégorie sont désormais lues directement par
     * `uploadCategoryImageToCollection()` via `DolibarrDirectFileResolver::listCategoryImages()`/
     * `getCategoryImageBinary()`, SANS transiter par cette méthode. Seul 'product' reste
     * accepté ici ; tout autre `$modulepart` -> log LOG_ERR + null (déjà le comportement pour
     * les modulepart inconnus, conservé tel quel).
     *
     * @param array $image Image data (product format — array with name/filename/relativename/level1name)
     * @param string $modulepart Module part — seul 'product' est accepté depuis la Story 57-7
     * @param string|null $originalFile Non utilisé depuis la suppression de la branche 'category'
     *                                   (Story 57-7) — conservé pour rétro-compatibilité de signature
     * @param object|null $product Dolibarr product — requis pour la branche 'product' (Story 57-2)
     * @return array|null Image data with content, mime_type, size, filename
     * @version     2.6.0
     * @since 2.0.33
     */
    protected function getImageContent($image, $modulepart = 'product', $originalFile = null, $product = null)
    {
        try {
            if ($modulepart !== 'product') {
                $this->log("Error: Unsupported modulepart: " . $modulepart, LOG_ERR);
                return null;
            }

            // Traditional product image format
            // FIX #145: L'API Dolibarr peut retourner 2 formats différents:
            // - Format simple: 'name' rempli, pas de 'filename'
            // - Format enrichi ECM: 'name' null, mais 'filename' et 'relativename' présents
            // On essaie dans l'ordre: name → filename → relativename

            $filename = null;
            $sourceField = null;

            // 1. Essayer 'name' en premier (format simple)
            if (!empty($image['name'])) {
                $filename = trim($image['name']);
                $sourceField = 'name';
            }
            // 2. Sinon essayer 'filename' (format enrichi ECM avec métadonnées complètes)
            elseif (!empty($image['filename'])) {
                $filename = trim($image['filename']);
                $sourceField = 'filename';
            }
            // 3. Sinon fallback sur 'relativename' (toujours présent)
            elseif (!empty($image['relativename'])) {
                $filename = trim($image['relativename']);
                $sourceField = 'relativename';
            }

            // Vérifier qu'on a bien un nom de fichier
            if (empty($filename)) {
                $this->log("Error: Product image missing name, filename and relativename: " . print_r($image, true), LOG_ERR);
                return null;
            }

            // Vérifier level1name
            if (empty($image['level1name'])) {
                $this->log("Error: Product image missing level1name: " . print_r($image, true), LOG_ERR);
                return null;
            }

            $this->log("FIX #145: Using filename from field '" . $sourceField . "': " . $filename, LOG_INFO);

            // Story 57-2 : accès direct base+disque via DolibarrDirectFileResolver, plus
            // aucun appel HTTP sur ce chemin.
            // ⚠️ basename() OBLIGATOIRE : $filename vaut ici level1name/filename CONCATÉNÉ
            // (format producteur du resolver, cf. $relativeKey côté DolibarrDirectFileResolver)
            // alors que getImageBinary() attend le NOM DE FICHIER SEUL (il reconstruit le
            // chemin depuis $product). Sans basename(), le chemin est doublé
            // (.../produit/{ref}/{ref}/{fichier}) et getImageBinary() renvoie null pour
            // TOUTE image.
            if ($product === null) {
                // Fallback de sécurité (limitation connue, cf. Dev Notes 57-2) : ce fallback
                // refait la même cascade name/filename/relativename ; correct uniquement si
                // atteint avec un dict image legacy (cas non nominal — $product doit toujours
                // être propagé par deduplicateImages()).
                $this->log("getImageContent - product parameter is null for 'product' branch, falling back to disk read (known limitation)", LOG_WARNING);
                return $this->getImageContentFromDisk($image, $modulepart, null, null);
            }

            return $this->getDirectFileResolver()->getImageBinary($product, basename($filename));
        } catch (Exception $e) {
            $this->log("Error getting image content: " . $e->getMessage(), LOG_ERR);

            // FIX v2.1.6: Fallback - Try reading directly from disk for intranet installations
            // AC2bis (story multicompany-ecm-files-prefixe-entite-jamais-reconnu) : $product est
            // déjà disponible dans cette portée (4e paramètre de getImageContent()) — le
            // propager pour que getImageContentFromDisk() résolve son répertoire par l'entité DU
            // PRODUIT, pas par l'entité contextuelle.
            $this->log("Exception caught - trying disk fallback for intranet installations", LOG_WARNING);
            return $this->getImageContentFromDisk($image, $modulepart, $originalFile, $product);
        }
    }

    /**
     * Résout `current_shopify_quantity` (available) et `on_hand_shopify_quantity` (on_hand) EN
     * LES LISANT À LA LOCALISATION VISÉE, pour chaque article du lot dont l'inventory_item_id et
     * le location_id sont renseignés.
     *
     * ⚠️ Docblock corrigé (hotfix 2.5.7, Validate 26/09/2026) : une précédente rédaction disait
     * « pour les articles où elle manque encore », ce qui est trompeur — la méthode n'inspecte
     * JAMAIS si la clé est déjà posée avant d'écrire. Elle ÉCRASE INCONDITIONNELLEMENT
     * `current_shopify_quantity`/`on_hand_shopify_quantity` dès que la lecture réseau résout une
     * valeur pour l'article visé, qu'une valeur y était déjà ou non. Ne jamais lui passer un lot
     * en pariant qu'une valeur préexistante serait respectée.
     *
     * ⚠️ N'inscrit RIEN pour un article dont la quantité ne peut pas être résolue. Mettre zéro
     * « par défaut » est précisément le défaut que cette méthode corrige : le repli enverrait
     * alors un compare-and-swap contre une valeur inventée, que Shopify refuse. (Voir
     * dropUnresolvedInventoryReferences() ci-dessous, qui va plus loin depuis le hotfix 2.5.7 : un
     * article resté sans AUCUNE valeur n'est plus envoyé du tout ce cycle-ci.)
     *
     * @param  array<int,array<string,mixed>> $inventoryQuantities Lot d'articles, modifié en place
     * @return int Nombre de quantités résolues
     * @since  2.5.5
     */
    private function fillInventoryReferenceQuantities(array &$inventoryQuantities)
    {
        $byLocation = [];
        foreach ($inventoryQuantities as $index => $iq) {
            $locationGid = (string) ($iq['location_id'] ?? '');
            $itemGid = (string) ($iq['inventory_item_id'] ?? '');
            if ($locationGid === '' || $itemGid === '') {
                continue;
            }
            $byLocation[$locationGid][$itemGid][] = $index;
        }

        if (empty($byLocation)) {
            return 0;
        }

        $resolved = 0;
        foreach ($byLocation as $locationGid => $itemsToIndexes) {
            // 🔴 Cet appel réseau NE DOIT JAMAIS faire échouer la synchronisation qui l'englobe.
            // `executeGraphQL()` lève une exception après épuisement de ses tentatives ; sans ce
            // catch, elle remonte jusqu'au `catch` de syncProduct(), dont le `rollback()`
            // annulerait le mapping produit et les correspondances de variantes DÉJÀ ENREGISTRÉS
            // — alors que la mutation correspondante a, elle, déjà été appliquée chez Shopify et
            // n'est pas annulable. Le produit resterait sans mapping malgré un succès distant, et
            // le cycle se répéterait indéfiniment.
            // (HIGH relevé par la revue 3 couches du 21/09.)
            //
            // Hotfix 2.5.7 (review 3 couches 26/09/2026, MEDIUM) : commentaire corrigé — l'ancien
            // texte disait « le lot repart avec ce qu'il avait, exactement comme avant ce
            // correctif ». C'était vrai tant qu'un SEED (ProductVariant.inventoryQuantity, le
            // TOTAL toutes localisations) était posé avant cet appel : ce seed n'existe plus
            // (retiré par ce même hotfix). Depuis, en cas d'échec de lecture, le lot repart SANS
            // AUCUNE valeur de référence — dropUnresolvedInventoryReferences() écarte alors ces
            // articles au lieu de les envoyer avec une référence fausse ou inventée.
            //
            // Deux échecs distincts, traités différemment :
            // - STRUCTUREL (ShopifyLocationErrorException, levée UNIQUEMENT quand l'erreur GraphQL
            //   globale désigne réellement l'emplacement lui-même — cf.
            //   ShopifyApi::isLocationRelatedGraphQLError()) : défaut de CONFIGURATION, jamais
            //   résolu par un simple retry. LOG_ERR explicite + compteur dédié
            //   ($stockSyncLocationErrorCount), une fois par appel (pas par item).
            // - TRANSITOIRE (tout autre Throwable — réseau/timeout, ou une erreur globale non
            //   liée à l'emplacement comme un THROTTLED épuisé) : incident probablement passager,
            //   LOG_WARNING, retenté au prochain cycle.
            //
            // Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : `catch (ShopifyLocationErrorException)`
            // AVANT `catch (\Throwable)` — cette exception porte les quantités déjà résolues par
            // les chunks PRÉCÉDENTS du même appel (`$e->partialResults`) : on les applique quand
            // même ci-dessous au lieu de les perdre (ne PAS faire `continue` ici).
            $levels = [];
            try {
                $levels = $this->shopifyApi->getInventoryQuantitiesAtLocation(array_keys($itemsToIndexes), $locationGid);
            } catch (ShopifyLocationErrorException $e) {
                $this->stockSyncLocationErrorCount++;
                // Re-review 27/09/2026 (HIGH, second passage) : message ACTIONNABLE distinct selon
                // la raison précise portée par l'exception — un emplacement DÉSACTIVÉ (existe,
                // simplement inactif) n'appelle pas la même remédiation qu'un emplacement
                // introuvable/invalide (corriger `shopify_location_id`) : choisir un AUTRE
                // emplacement, actif celui-là, dans la configuration de la boutique.
                if ($e->reason === 'disabled') {
                    $this->log('fillInventoryReferenceQuantities - ERREUR STRUCTURELLE emplacement '
                        . $locationGid . ' DESACTIVE cote Shopify : ' . $e->getMessage()
                        . ' - choisir un emplacement ACTIF dans la configuration de la boutique '
                        . '(retenter ne suffira pas tant qu\'aucun emplacement actif n\'est configure).', LOG_ERR);
                } else {
                    $this->log('fillInventoryReferenceQuantities - ERREUR STRUCTURELLE emplacement '
                        . $locationGid . ' introuvable ou invalide cote Shopify : ' . $e->getMessage()
                        . ' - verifier shopify_location_id dans la configuration de la boutique '
                        . '(retenter ne suffira pas tant que la configuration est incorrecte).', LOG_ERR);
                }
                $levels = $e->partialResults;
            } catch (\Throwable $e) {
                $this->log('fillInventoryReferenceQuantities - lecture temporairement impossible pour '
                    . 'la localisation ' . $locationGid . ' (incident reseau transitoire) : ' . $e->getMessage()
                    . ' - les articles concernes seront ecartes ce cycle-ci et retentes au prochain.', LOG_WARNING);
                continue;
            }
            foreach ($itemsToIndexes as $itemGid => $indexes) {
                if (!array_key_exists($itemGid, $levels)) {
                    continue;
                }
                foreach ($indexes as $index) {
                    // ⚠️ CLÉ DISTINCTE, et ce n'est pas un détail. Écrire ici
                    // `current_shopify_quantity` ferait basculer l'article vers le chemin DELTA
                    // (`inventoryAdjustQuantities`), qui ajuste la grandeur **available**. Or on
                    // vient de lire **on_hand**. Le delta serait calculé sur une grandeur et
                    // appliqué à une autre : un stock faux, écrit en silence — pire que le refus
                    // qu'on corrige.
                    if (array_key_exists('on_hand', $levels[$itemGid])) {
                        $inventoryQuantities[$index]['on_hand_shopify_quantity'] = (int) $levels[$itemGid]['on_hand'];
                    }
                    if (array_key_exists('available', $levels[$itemGid])) {
                        // ⚠️ On ÉCRASE volontairement la valeur venue de
                        // `ProductVariant.inventoryQuantity`. Celle-ci est un TOTAL toutes
                        // localisations, alors que le chemin delta compare `changeFromQuantity`
                        // à la quantité `available` DE LA LOCALISATION visée. Les deux
                        // coïncident sur une boutique à un seul entrepôt — et divergent sur
                        // toutes les autres, où la mutation est alors refusée.
                        // (CRITICAL relevé par la revue 3 couches du 21/09.)
                        $inventoryQuantities[$index]['current_shopify_quantity'] = (int) $levels[$itemGid]['available'];
                    }
                    $resolved++;
                }
            }
        }

        $this->log('fillInventoryReferenceQuantities - ' . $resolved
            . ' article(s) dont les quantites de reference ont ete lues a la localisation visee',
            LOG_INFO);

        return $resolved;
    }

    /**
     * Hotfix 2.5.7 (RÈGLE UNIQUE, Validate 26/09/2026) : retire du lot tout article dont NI
     * `current_shopify_quantity` (available) NI `on_hand_shopify_quantity` (on_hand) n'a pu être
     * résolu par fillInventoryReferenceQuantities() — c'est-à-dire tout article pour lequel la
     * lecture À LA LOCALISATION VISÉE a échoué (réseau, ou article absent du niveau de stock
     * retourné par Shopify pour cette localisation).
     *
     * ⚠️ Ce n'est PAS un filtre de présence au sens large : un article gardant SEULEMENT
     * `on_hand_shopify_quantity` (chemin de repli `inventorySetOnHandQuantities`) reste envoyé —
     * sa quantité de référence a bien été lue à la localisation visée, elle sert juste une autre
     * grandeur que le chemin delta. Ce qu'on écarte ici est l'absence TOTALE de lecture, pas une
     * valeur jugée insuffisante.
     *
     * L'article écarté n'est PAS envoyé à Shopify ce cycle-ci : ni en delta avec une référence
     * fausse (rendu impossible par ce même hotfix, qui a supprimé tout seed depuis
     * `ProductVariant.inventoryQuantity`), ni en repli avec une référence non résolue (= 0
     * fabriqué, exactement le défaut du 21/09/2026 documenté sur `fillInventoryReferenceQuantities()`
     * ci-dessus). Il est journalisé (LOG_WARNING, SKU + raison), compté dans
     * `$stockSyncFailedCount` — même convention que le reste des échecs de sync stock du cycle,
     * déjà lu par ImportProductsCron::runSyncForStore() pour le résumé visible à l'écran de
     * synchronisation — et retenté au prochain cycle : rien n'est persisté comme "traité" pour cet
     * article (les appelants ne doivent pas marquer `last_stock_sync` si le lot filtré est vide).
     *
     * @param  array<int,array<string,mixed>> $inventoryQuantities Lot déjà passé par
     *         fillInventoryReferenceQuantities()
     * @param  string $context Nom du site d'appel, pour le message de log uniquement
     * @return array<int,array<string,mixed>> Lot filtré (ré-indexé)
     * @since  2.5.7
     */
    private function dropUnresolvedInventoryReferences(array $inventoryQuantities, $context = '')
    {
        $kept = [];
        $droppedCount = 0;

        foreach ($inventoryQuantities as $iq) {
            $hasCurrent = array_key_exists('current_shopify_quantity', $iq) && $iq['current_shopify_quantity'] !== null;
            $hasOnHand = array_key_exists('on_hand_shopify_quantity', $iq) && $iq['on_hand_shopify_quantity'] !== null;

            if (!$hasCurrent && !$hasOnHand) {
                $droppedCount++;
                $this->stockSyncFailedCount++;
                $this->log($context . ' - Article NON synchronise ce cycle : quantite de reference '
                    . 'illisible a la localisation visee (SKU: ' . ($iq['sku'] ?? 'unknown')
                    . ', inventoryItemId: ' . ($iq['inventory_item_id'] ?? '?')
                    . ') - sera retente au prochain cycle.', LOG_WARNING);
                continue;
            }

            $kept[] = $iq;
        }

        if ($droppedCount > 0) {
            $this->log($context . ' - ' . $droppedCount . ' article(s) ecarte(s) ce cycle '
                . '(reference de stock non resolue a la localisation visee, voir warnings ci-dessus)',
                LOG_WARNING);
        }

        return $kept;
    }

    /**
     * Construit le texte alternatif d'une image produit.
     *
     * ## Pourquoi ce n'est pas un détail
     *
     * Le texte alternatif est ce qu'un **lecteur d'écran prononce** à la place de l'image, et ce
     * qu'un moteur de recherche indexe. Jusqu'ici le module y écrivait le nom du fichier tel quel :
     * une personne malvoyante entendait « B000107161008 barre oblique B000107161008 point jpé gé ».
     * C'est inutilisable, et c'est aussi ce qui s'affiche quand l'image ne charge pas.
     *
     * Ce qu'on met à la place : le **libellé du produit**, qui est l'information que l'image porte
     * réellement, suivi du rang quand il y en a plusieurs — sans quoi cinq photos d'un même produit
     * se prononcent toutes à l'identique et le choix devient impossible.
     *
     * ⚠️ **Ne JAMAIS y remettre une valeur technique.** L'idée d'y ranger une empreinte pour
     * apparier les images côté Shopify a été écartée pour cette raison : `alt` appartient au
     * visiteur, pas au module. La clé d'appariement doit venir du nom de fichier porté par l'URL
     * du CDN.
     *
     * @param  object $dolParentProduct Produit Dolibarr
     * @param  int    $index            Rang de l'image (base 0)
     * @param  int    $total            Nombre total d'images du produit
     * @return string Texte alternatif
     * @since  2.5.5
     */
    private function buildImageAltText($dolParentProduct, $index, $total)
    {
        $label = '';
        if (!empty($dolParentProduct->label)) {
            $label = trim((string) $dolParentProduct->label);
        }
        if ($label === '' && !empty($dolParentProduct->ref)) {
            // Un produit sans libellé est rare mais possible : la référence vaut mieux qu'un vide,
            // qui laisserait le lecteur d'écran annoncer « image » sans rien d'autre.
            $label = trim((string) $dolParentProduct->ref);
        }
        if ($label === '') {
            $label = 'Produit';
        }

        // Shopify borne le texte alternatif à 512 caractères ; on coupe bien avant pour laisser
        // la place au suffixe de rang, et parce qu'un alt interminable dessert celui qui l'écoute.
        if (function_exists('mb_substr')) {
            if (mb_strlen($label, 'UTF-8') > 120) {
                $label = mb_substr($label, 0, 117, 'UTF-8') . '...';
            }
        } elseif (strlen($label) > 120) {
            $label = substr($label, 0, 117) . '...';
        }

        $total = (int) $total;
        if ($total <= 1) {
            return $label;
        }

        return $label . ' - photo ' . ((int) $index + 1) . ' sur ' . $total;
    }

    /**
     * Get image content directly from disk (fallback for intranet installations)
     *
     * This method is used as a fallback when the Dolibarr API is not accessible
     * (e.g., when Dolibarr is hosted on an intranet and the API cannot be reached
     * from the module). It reads the image file directly from the local filesystem.
     *
     * @param array       $image        Image metadata from Dolibarr
     * @param string      $modulepart   Module part (product, category, etc.)
     * @param string|null $originalFile Override for original file path
     * @param object|null $product      Produit Dolibarr (entity optionnel) — AC2bis (story
     *                                  multicompany-ecm-files-prefixe-entite-jamais-reconnu) :
     *                                  fournit l'entité à utiliser pour résoudre
     *                                  `multidir_output`, à la place de l'entité contextuelle
     * @return array|null Image data with content, mime_type, size, filename or null on failure
     * @version     2.6.0
     * @since 2.1.6
     */
    private function getImageContentFromDisk($image, $modulepart = 'product', $originalFile = null, $product = null)
    {
        global $conf;

        try {
            $this->log("FALLBACK: Attempting to read image directly from disk", LOG_WARNING);

            // Determine the filename
            $filename = null;
            if ($modulepart === 'product') {
                // Try different fields for filename
                if (!empty($image['name'])) {
                    $filename = trim($image['name']);
                } elseif (!empty($image['filename'])) {
                    $filename = trim($image['filename']);
                } elseif (!empty($image['relativename'])) {
                    $filename = trim($image['relativename']);
                }

                if (empty($filename)) {
                    $this->log("FALLBACK: Cannot determine filename from image metadata", LOG_ERR);
                    return null;
                }

                // Get the product reference (level1name)
                if (empty($image['level1name'])) {
                    $this->log("FALLBACK: Missing level1name (product ref) in image metadata", LOG_ERR);
                    return null;
                }

                $productRef = trim($image['level1name'], '/');

                // FIX-MEDIUM-1 (code review 57-2) : $filename vaut ici level1name/filename
                // CONCATÉNÉ (format producteur du resolver, cf. $relativeKey côté
                // DolibarrDirectFileResolver::fetchOrderedImagesFromDb()) — sans basename(),
                // le chemin est doublé (.../produit/{ref}/{ref}/{fichier}), introuvable sur
                // disque. basename() appliqué UNIQUEMENT sur cette branche produit — c'est la
                // SEULE branche restante de cette méthode depuis la Story 57-7 (la branche
                // 'category' qui existait ici a été supprimée, cf. bloc else ci-dessous).
                $filename = basename($filename);

                // Build the local file path
                // Dolibarr stores product images in: DOL_DATA_ROOT/produit/{product_ref}/{filename}
                // AC2bis (story multicompany-ecm-files-prefixe-entite-jamais-reconnu) : entité
                // DU PRODUIT en priorité, $this->entity (entité du module) en repli — JAMAIS
                // $conf->entity contextuel, comme partout ailleurs dans le module (FIX-MEDIUM-2
                // de 57-2, cf. DolibarrDirectFileResolver::fetchOrderedImagesFromDb()). L'impact
                // réel est faible (l'entité contextuelle est correcte en CRON), mais c'est la
                // même classe de défaut que celle corrigée sur le chemin de LISTING.
                $entityForImageDir = (!empty($product) && !empty($product->entity))
                    ? (int) $product->entity
                    : (int) $this->entity;
                $baseDir = $conf->product->multidir_output[$entityForImageDir] ?? DOL_DATA_ROOT . '/produit';
                $filepath = $baseDir . '/' . $productRef . '/' . $filename;
            } else {
                // Story 57-7 : la branche 'category' (self-served via ecm_files/originalFile,
                // héritée de l'ère HTTP) est SUPPRIMÉE — les images de catégorie passent
                // désormais exclusivement par DolibarrDirectFileResolver (scan disque dédié,
                // cf. uploadCategoryImageToCollection()), jamais par ce fallback générique.
                $this->log("FALLBACK: Unsupported modulepart: " . $modulepart, LOG_ERR);
                return null;
            }

            $this->log("FALLBACK: Attempting to read file: " . $filepath, LOG_INFO);

            // Check if file exists
            if (!file_exists($filepath)) {
                $this->log("FALLBACK: File not found on disk: " . $filepath, LOG_ERR);

                // Try alternative paths (some installations may have different structures)
                $altPaths = [];
                if ($modulepart === 'product') {
                    $altPaths[] = DOL_DATA_ROOT . '/produit/' . $productRef . '/' . $filename;
                    $altPaths[] = DOL_DATA_ROOT . '/product/' . $productRef . '/' . $filename;
                }

                foreach ($altPaths as $altPath) {
                    if (file_exists($altPath)) {
                        $filepath = $altPath;
                        $this->log("FALLBACK: Found file at alternative path: " . $filepath, LOG_INFO);
                        break;
                    }
                }

                if (!file_exists($filepath)) {
                    $this->log("FALLBACK: File not found at any location", LOG_ERR);
                    return null;
                }
            }

            // Check file is readable
            if (!is_readable($filepath)) {
                $this->log("FALLBACK: File exists but is not readable: " . $filepath, LOG_ERR);
                return null;
            }

            // Read the file content
            $content = file_get_contents($filepath);
            if ($content === false) {
                $this->log("FALLBACK: Failed to read file content: " . $filepath, LOG_ERR);
                return null;
            }

            // Get file info
            $filesize = strlen($content);
            $mimeType = $this->getMimeType($filename);

            $this->log("FALLBACK: Successfully read image from disk - File: " . $filename . ", Size: " . $filesize . " bytes, MIME: " . $mimeType, LOG_INFO);

            return [
                'content' => $content,
                'mime_type' => $mimeType,
                'size' => $filesize,
                'filename' => $filename
            ];

        } catch (Exception $e) {
            $this->log("FALLBACK: Exception reading image from disk: " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Detect the "visual" attribute of a product (typically Color/Colour) for image propagation
     *
     * For products with 2+ attributes (e.g., Color + Size), identifies which attribute
     * is "visual" (determines the product appearance) using keyword heuristics.
     * Used by Story 8.1 to propagate images across sibling variants sharing the same color.
     *
     * @param object $dolProductParent Dolibarr parent product
     * @return string|null Label of the visual attribute, or null if none detected
     * @since 2.2.0
     */
    private function detectVisualAttribute($dolProductParent)
    {
        // Get all distinct attributes for this parent product's variants
        $sql = "SELECT DISTINCT pa.rowid, pa.label
                FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination2val pac2v ON pac2v.fk_prod_combination = pac.rowid
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_value pav ON pac2v.fk_prod_attr_val = pav.rowid
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute pa ON pav.fk_product_attribute = pa.rowid
                WHERE pac.fk_product_parent = ?
                ORDER BY pa.rowid";

        $result = SqlUtils::executeQuery($this->db, $sql, "detecting visual attributes for product " . $dolProductParent->ref, false, [(int)$dolProductParent->id]);

        if (!$result) {
            $this->log("ImportProducts::detectVisualAttribute - SQL query failed for product " . $dolProductParent->ref . ", skipping visual detection", LOG_WARNING);
            return null;
        }

        $attributes = [];
        while ($obj = $this->db->fetch_object($result)) {
            $attributes[] = array('id' => $obj->rowid, 'label' => $obj->label);
        }
        $this->db->free($result);

        // Need at least 2 attributes for propagation to be relevant
        if (count($attributes) < 2) {
            $this->log("ImportProducts::detectVisualAttribute - Product " . $dolProductParent->ref . " has " . count($attributes) . " attribute(s), skipping visual detection", LOG_DEBUG);
            return null;
        }

        // Visual keywords (case-insensitive)
        $visualKeywords = array('color', 'colour', 'couleur', 'farbe', 'colore', 'pattern', 'motif', 'muster', 'design');

        // Size keywords to exclude
        $sizeKeywords = array('size', 'taille', 'grosse', 'talla', 'dimension', 'weight', 'poids', 'length', 'longueur');

        // Step 1: Find attribute matching a visual keyword
        foreach ($attributes as $attr) {
            $labelLower = strtolower($attr['label']);
            foreach ($visualKeywords as $keyword) {
                if (strpos($labelLower, $keyword) !== false) {
                    $this->log("ImportProducts::detectVisualAttribute - Found visual attribute '" . $attr['label'] . "' (matched keyword: " . $keyword . ")", LOG_INFO);
                    return $attr['label'];
                }
            }
        }

        // Step 2: Fallback — first attribute that is NOT size-like
        foreach ($attributes as $attr) {
            $labelLower = strtolower($attr['label']);
            $isSize = false;
            foreach ($sizeKeywords as $keyword) {
                if (strpos($labelLower, $keyword) !== false) {
                    $isSize = true;
                    break;
                }
            }
            if (!$isSize) {
                $this->log("ImportProducts::detectVisualAttribute - Fallback: using attribute '" . $attr['label'] . "' as visual (not a size keyword)", LOG_INFO);
                return $attr['label'];
            }
        }

        // All attributes are size-like, no propagation
        $this->log("ImportProducts::detectVisualAttribute - No visual attribute found for product " . $dolProductParent->ref . " (all attributes are size-like)", LOG_DEBUG);
        return null;
    }

    /**
     * Group variant products by their visual attribute value (e.g., group by color)
     *
     * Used by Story 8.1 to identify which variants share the same visual attribute
     * (e.g., all "Red" variants: Red-S, Red-M, Red-L) for image propagation.
     *
     * @param array $dolVariants Array of Dolibarr variant product objects
     * @param string $visualAttributeLabel Label of the visual attribute to group by
     * @return array Associative array: [attributeValue => [variant objects]]
     * @since 2.2.0
     */
    private function groupVariantsByVisualAttribute($dolVariants, $visualAttributeLabel)
    {
        $groups = array();

        // Review fix: Batch-load all variant options in 1 query (instead of N individual getVariantOptions calls)
        $optionsCache = array();
        $variantIds = array();
        foreach ($dolVariants as $dv) {
            $variantIds[] = (int)$dv->id;
        }
        if (!empty($variantIds)) {
            $inClause = implode(',', $variantIds);
            $sql = "SELECT pac.fk_product_child, pa.label as attribute_label, pav.value
                    FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac
                    LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute_combination2val pac2v ON pac2v.fk_prod_combination = pac.rowid
                    LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute_value pav ON pac2v.fk_prod_attr_val = pav.rowid
                    LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute pa ON pav.fk_product_attribute = pa.rowid
                    WHERE pac.fk_product_child IN (" . $inClause . ")";
            $result = SqlUtils::executeQuery($this->db, $sql, "batch loading variant options for grouping", false);
            if ($result) {
                while ($row = $this->db->fetch_object($result)) {
                    $childId = (int)$row->fk_product_child;
                    if (!isset($optionsCache[$childId])) {
                        $optionsCache[$childId] = array();
                    }
                    if (!empty($row->attribute_label) && !empty($row->value)) {
                        $optionsCache[$childId][] = array(
                            'name' => $row->value,
                            'optionName' => $row->attribute_label
                        );
                    }
                }
                $this->db->free($result);
            }
        }

        foreach ($dolVariants as $dolVariant) {
            $variantId = (int)$dolVariant->id;
            // Use batch-loaded cache if available, otherwise fallback to individual query
            $options = isset($optionsCache[$variantId]) ? $optionsCache[$variantId] : $this->getVariantOptions($dolVariant);
            $visualValue = null;

            foreach ($options as $option) {
                if (strcasecmp($option['optionName'], $visualAttributeLabel) === 0) {
                    $visualValue = $option['name'];
                    break;
                }
            }

            if ($visualValue !== null) {
                if (!isset($groups[$visualValue])) {
                    $groups[$visualValue] = array();
                }
                $groups[$visualValue][] = $dolVariant;
            } else {
                $this->log("ImportProducts::groupVariantsByVisualAttribute - Variant " . $dolVariant->ref . " has no value for attribute '" . $visualAttributeLabel . "'", LOG_WARNING);
            }
        }

        $this->log("ImportProducts::groupVariantsByVisualAttribute - Grouped " . count($dolVariants) . " variants into " . count($groups) . " groups by '" . $visualAttributeLabel . "'", LOG_DEBUG);

        return $groups;
    }

    /**
     * Download all image contents and deduplicate by SHA256 hash
     *
     * Downloads binary content for all images, computes SHA256 hash, and identifies
     * duplicates (same binary content regardless of filename). Returns only unique
     * images for upload, with mapping back to all original entries.
     *
     * @param array $allImages Array of image entries [{image, type, product, variantId}]
     * @return array|null Deduplication result or null on complete failure
     * @since 2.2.0
     */
    private function deduplicateImages($allImages)
    {
        $imageContents = array();        // hash => content array {content, mime_type, size, filename}
        $hashList = array();             // ordered list of all hashes (for composite)
        $hashToOriginalIndexes = array(); // hash => [indexes in $allImages]
        $uniqueImages = array();         // deduplicated $allImages entries
        $individualHashes = array();     // unique index => hash
        $seenHashes = array();           // hash => unique index in $uniqueImages
        $failedCount = 0;

        foreach ($allImages as $index => $imageInfo) {
            $image = $imageInfo['image'];
            // Story 57-2 (site 6) : propager le produit — requis par getImageBinary() du resolver
            // pour reconstruire le chemin disque. Posé par syncProductAllImages() dans $allImages[].
            $content = $this->getImageContent($image, 'product', null, $imageInfo['product']);
            if (!$content || empty($content['content'])) {
                $this->log("ImportProducts::deduplicateImages - Failed to get content for " . $image['name'] . ", skipping", LOG_WARNING);
                $failedCount++;
                continue;
            }

            $hash = hash('sha256', $content['content']);
            $hashList[] = $hash;

            if (!isset($hashToOriginalIndexes[$hash])) {
                $hashToOriginalIndexes[$hash] = array();
            }
            $hashToOriginalIndexes[$hash][] = $index;

            if (!isset($seenHashes[$hash])) {
                // First occurrence: add to unique images
                $uniqueIndex = count($uniqueImages);
                $seenHashes[$hash] = $uniqueIndex;
                $uniqueImages[] = $imageInfo;
                $imageContents[$hash] = $content;
                $individualHashes[$uniqueIndex] = $hash;
            } else {
                // Duplicate detected
                $this->log("ImportProducts::deduplicateImages - Image " . $image['name'] . " deduplicated (hash: " . substr($hash, 0, 12) . "), reusing upload", LOG_INFO);
            }
        }

        if (empty($uniqueImages)) {
            $this->log("ImportProducts::deduplicateImages - No valid images after deduplication (failed: " . $failedCount . ")", LOG_WARNING);
            return null;
        }

        // Compute composite hash = SHA256 of sorted individual hashes
        sort($hashList);
        $compositeHash = hash('sha256', implode('', $hashList));

        return array(
            'uniqueImages' => $uniqueImages,
            'imageContents' => $imageContents,
            'hashToOriginalIndexes' => $hashToOriginalIndexes,
            'compositeHash' => $compositeHash,
            'individualHashes' => $individualHashes,
            'originalCount' => count($allImages),
            'uniqueCount' => count($uniqueImages),
            'failedCount' => $failedCount
        );
    }

    /**
     * Get stored composite hash for a product's images
     *
     * @param int $productId Dolibarr parent product ID
     * @param int $entity Entity ID
     * @return string|null Stored composite hash or null if not found
     * @since 2.2.0
     */
    private function getStoredCompositeHash($productId, $entity)
    {
        $sql = "SELECT images_composite_hash FROM " . MAIN_DB_PREFIX . "doli2shop_image_hashes
                WHERE fk_product = ? AND entity = ?";
        $result = SqlUtils::executeQuery($this->db, $sql, "getting stored image hash", false, [(int)$productId, (int)$entity]);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $this->db->free($result);
            if ($obj) {
                return $obj->images_composite_hash;
            }
        }
        return null;
    }

    /**
     * Update stored composite hash for a product's images
     *
     * Uses INSERT ON DUPLICATE KEY UPDATE for atomic upsert.
     *
     * @param int $productId Dolibarr parent product ID
     * @param int $entity Entity ID
     * @param string $compositeHash SHA256 composite hash
     * @param int $imageCount Number of images in the composite
     * @return bool Success
     * @since 2.2.0
     */
    private function updateStoredCompositeHash($productId, $entity, $compositeHash, $imageCount)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_image_hashes
                (entity, fk_product, images_composite_hash, image_count, last_sync)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    images_composite_hash = VALUES(images_composite_hash),
                    image_count = VALUES(image_count),
                    last_sync = NOW()";
        $result = SqlUtils::executeQuery($this->db, $sql, "updating stored image hash", false, [
            (int)$entity,
            (int)$productId,
            $compositeHash,
            (int)$imageCount
        ]);
        return ($result !== false);
    }

    /**
     * Décode la provenance des médias Shopify connus comme CRÉÉS PAR LE MODULE pour un produit
     * (colonne `shopify_media_ids`, JSON — migration 2.6.0d_2.6.0e). Les identifiants servent de
     * preuve de provenance (a) à isExistingMediaCreatedByModule() — HIGH, review 3 couches
     * 27/09/2026 : ne jamais supprimer un média que le module n'a pas lui-même posé.
     *
     * ⚠️ Forme `{"hash": string|null, "ids": string[]}` (re-review 28/09/2026, point 1c) — PAS un
     * simple tableau plat. Le hash composite associé est INDISPENSABLE pour décider si ce lot de
     * médias peut être RÉUTILISÉ sans recréation (syncProductAllImages()) : un lot dont le compte
     * d'identifiants coïncide par hasard avec le nombre d'images Dolibarr courant, mais qui a été
     * créé pour un état ANTÉRIEUR et différent des images (contenu changé, même total), serait
     * servi à tort comme "à jour" sans ce hash pour trancher — la réutilisation n'est tentée QUE si
     * ce hash correspond EXACTEMENT au hash composite courant.
     *
     * @param string|null $rawJson Valeur brute de la colonne (NULL/vide sur une ligne pas encore
     *                             alimentée par cette story, ou avant la migration)
     * @return array{hash: ?string, ids: string[]}
     */
    private function decodeKnownModuleMediaIds($rawJson)
    {
        $empty = ['hash' => null, 'ids' => []];
        if (empty($rawJson)) {
            return $empty;
        }
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || !isset($decoded['ids']) || !is_array($decoded['ids'])) {
            return $empty;
        }
        $ids = array_values(array_filter($decoded['ids'], function ($v) {
            return is_string($v) && $v !== '';
        }));
        $hash = (isset($decoded['hash']) && is_string($decoded['hash']) && $decoded['hash'] !== '') ? $decoded['hash'] : null;
        return ['hash' => $hash, 'ids' => $ids];
    }

    /**
     * Persiste la liste des médias Shopify reconnus comme créés par le module pour ce produit,
     * ainsi que le hash composite (état des images Dolibarr) auquel ce lot correspond.
     *
     * ⚠️ Le hash est ESSENTIEL (re-review 28/09/2026, point 1c) : c'est lui qui permet à un cycle
     * ultérieur de décider si ce lot peut être RÉUTILISÉ tel quel (hash identique au hash composite
     * courant -> même état d'images Dolibarr) ou doit être traité comme potentiellement PÉRIMÉ
     * (hash différent -> les images ont changé depuis, ne jamais servir ce lot comme "à jour" même
     * si son compte d'identifiants coïncide par hasard avec le nouveau compte d'images).
     *
     * ⚠️ Même limite préexistante que la requête de lecture du mapping (SELECT ci-dessus dans
     * syncProductAllImages()) : le WHERE ne filtre pas sur fk_store — motif documenté comme dette
     * séparée (story fk-store-absent-du-mapping-produit-shopify-images), pas introduit ici.
     *
     * @param int         $dolibarrProductId
     * @param int         $entity
     * @param string[]    $mediaIds
     * @param string|null $compositeHash Hash composite courant (état des images Dolibarr)
     * @return void
     */
    private function saveKnownModuleMediaIds($dolibarrProductId, $entity, array $mediaIds, $compositeHash)
    {
        $mediaIds = array_values(array_unique($mediaIds));
        sort($mediaIds);
        $json = json_encode(['hash' => $compositeHash, 'ids' => $mediaIds]);
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . "
                SET shopify_media_ids = ?
                WHERE fk_product = ? AND entity = ?";
        SqlUtils::executeQuery($this->db, $sql, "updating known module media ids", false, [
            $json,
            (int) $dolibarrProductId,
            (int) $entity
        ]);
    }

    /**
     * Marque (ou démarque) un produit comme prioritaire pour le PROCHAIN cycle de synchronisation
     * de contenu/stock (`importProducts()`, requêtes SQL de sélection ~488-521 et ~582-610) — HIGH
     * (re-review 27/09/2026, point 1b). Posée à `true` quand la synchronisation d'images de ce
     * produit vient d'être DIFFÉRÉE (budget de polling du cycle épuisé, ou lot précédent encore en
     * cours de traitement) : sans cette priorité, un produit chroniquement en fin de file (ordre
     * déterministe) ne serait JAMAIS rattrapé, et recréerait un lot complet à chaque cycle sans
     * jamais rien supprimer (accumulation illimitée de doublons).
     *
     * ⚠️ Colonne `images_priority_requeue` (migration 2.6.0e_2.6.0f) — délibérément DISTINCTE de
     * `last_sync_status`/`last_sync_error` : ces deux colonnes portent le statut/l'erreur du
     * contenu produit dans son ensemble (lues par admin/diagnostic.php et d'autres consommateurs
     * existants) ; les réutiliser pour un signal spécifique aux images aurait soit écrasé une
     * vraie erreur de synchronisation de contenu, soit inversement caché ce signal derrière une
     * information sans rapport. Une colonne dédiée coûte une migration triviale et garde les deux
     * préoccupations séparées.
     *
     * @param int  $dolibarrProductId
     * @param int  $entity
     * @param bool $flag
     * @return void
     */
    private function setImagePriorityRequeue($dolibarrProductId, $entity, bool $flag)
    {
        // Re-review 28/09/2026 (Round 5) : filtre fk_store CONDITIONNEL (invariant §14
        // CLAUDE.dolibarr.md, même idiome que clearOffsaleActionStatus() ci-dessus) — jamais de
        // filtre en mono-boutique (getStoreId() == 0, chemin historique), pour ne pas rendre ce
        // garde-fou anti-famine muet sur les installations qui n'ont pas encore de fk_store
        // renseigné.
        //
        // Re-review 28/09/2026 (Round 6, HIGH) : le prédicat `fk_store = ?` STRICT ratait la ligne
        // pas encore backfillée (fk_store = 0) quand la boutique courante en a un — la remise à 0
        // touchait alors 0 ligne et le flag restait à 1 pour toujours sur cette ligne. Assoupli en
        // `(fk_store = ? OR fk_store = 0)`, EXACTEMENT le même prédicat que la lecture
        // (getImagePriorityRequeueFlag() ci-dessous) : lecture et écriture doivent voir la MÊME
        // ligne, sans quoi l'une peut lire un flag que l'autre ne parvient jamais à démarquer.
        $fkStore = (int) $this->shopifyApi->getStoreId();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . "
                SET images_priority_requeue = ?
                WHERE fk_product = ? AND entity = ?";
        $params = [
            $flag ? 1 : 0,
            (int) $dolibarrProductId,
            (int) $entity
        ];
        if ($fkStore > 0) {
            $sql .= " AND (fk_store = ? OR fk_store = 0)";
            $params[] = $fkStore;
        }

        $result = SqlUtils::executeQuery($this->db, $sql, "updating image priority requeue flag", false, $params);
        // Re-review 28/09/2026 (Round 6, HIGH) : même discipline que updateLocationUnresolvedStreak()
        // / clearOffsaleActionStatus() — 0 ligne affectée (ligne absente pour ce fk_product/entity/
        // fk_store) ne doit jamais rester totalement silencieux, sans pour autant être bloquant.
        if ($result && (int) $this->db->affected_rows($result) === 0) {
            $this->log("setImagePriorityRequeue - 0 ligne affectee pour le produit " . $dolibarrProductId
                . " (fk_store=" . $fkStore . ", flag=" . ($flag ? 1 : 0) . ") - ligne absente pour ce fk_store ?", LOG_DEBUG);
        }
    }

    /**
     * Lit la valeur COURANTE de `images_priority_requeue` pour un produit (PARENT/SIMPLE — jamais
     * une déclinaison, cf. l'appelant `syncProductAllImages()`), sur EXACTEMENT le même prédicat
     * `fk_store` que `setImagePriorityRequeue()` ci-dessus — condition nécessaire (Round 6, HIGH)
     * pour que la remise à 0 par l'appelant sache si une écriture est réellement nécessaire, sans
     * jamais lire une ligne que l'écriture ne pourrait pas atteindre ensuite.
     *
     * En présence de DEUX lignes pour ce fk_product/entity (l'une pas encore backfillée à
     * fk_store = 0, l'autre déjà backfillée pour la boutique courante) — fenêtre de migration
     * transitoire, jamais l'état stable — préfère la ligne DÉJÀ backfillée (`ORDER BY fk_store
     * DESC LIMIT 1`), cohérent avec l'invariant §14 CLAUDE.dolibarr.md : ne jamais laisser un
     * fk_store > 0 se faire éclipser par une ligne fk_store = 0 obsolète.
     *
     * @param int $productId ID Dolibarr du produit PARENT ou SIMPLE
     * @param int $entity
     * @return int 0 ou 1 (0 si aucune ligne trouvée)
     */
    private function getImagePriorityRequeueFlag($productId, $entity): int
    {
        $fkStore = (int) $this->shopifyApi->getStoreId();

        $sql = "SELECT images_priority_requeue FROM " . MAIN_DB_PREFIX . $this->table_element . "
                WHERE fk_product = ? AND entity = ?";
        $params = [(int) $productId, (int) $entity];
        if ($fkStore > 0) {
            $sql .= " AND (fk_store = ? OR fk_store = 0) ORDER BY fk_store DESC LIMIT 1";
            $params[] = $fkStore;
        }

        $result = SqlUtils::executeQuery($this->db, $sql, "reading image priority requeue flag for product " . $productId, false, $params);
        if ($result) {
            $obj = $this->db->fetch_object($result);
            $this->db->free($result);
            if ($obj !== null && isset($obj->images_priority_requeue)) {
                return (int) $obj->images_priority_requeue;
            }
        }
        return 0;
    }

    /**
     * Indexe par identifiant la réponse de `ShopifyApi::getMediaStatusByIds()` (`nodes(ids:)`) —
     * factorisé entre `waitForMediaToBeReady()` et la vérification des médias en attente d'un
     * cycle précédent (point 1c, re-review 27/09/2026) : un identifiant demandé mais ABSENT de la
     * réponse (page tronquée, suppression concurrente...) est simplement absent de ce tableau,
     * jamais présent avec un statut inventé.
     *
     * @param object $response Réponse brute de getMediaStatusByIds()
     * @return array<string,string> id (gid complet) => statut
     */
    private function indexMediaStatusResponseById($response): array
    {
        $byId = [];
        if (empty($response) || empty($response->data) || !isset($response->data->nodes) || !is_array($response->data->nodes)) {
            return $byId;
        }
        foreach ($response->data->nodes as $node) {
            if ($node !== null && isset($node->id) && isset($node->status)) {
                $byId[$node->id] = $node->status;
            }
        }
        return $byId;
    }

    /**
     * Détermine si un média Shopify EXISTANT (snapshot pris AVANT le cycle en cours) a été posé
     * par le module, et est donc un candidat légitime à la suppression lors d'un remplacement.
     *
     * HIGH (review 3 couches 27/09/2026) : la sélection ne doit JAMAIS reposer sur la POSITION —
     * un array_slice sur l'ordre de POSITION pouvait supprimer une photo ajoutée à la main et
     * promue en tête par le client. Trois preuves de PROVENANCE, dans l'ordre :
     *
     *  (a) l'identifiant du média est enregistré comme créé par le module (colonne
     *      `shopify_media_ids`, alimentée UNIQUEMENT par les créations réelles du module — voir
     *      syncProductAllImages(), jamais par (b)/(c) ci-dessous) ;
     *  (b) le texte alternatif est EXACTEMENT égal à l'une des chaînes que buildImageAltText()
     *      construirait pour le libellé COURANT du produit et le nombre d'images COURANT (N et M
     *      cohérents — re-review 27/09/2026 : ancien critère bien trop large, un simple suffixe
     *      « - photo N sur M » matchait n'importe quel libellé, y compris celui d'un AUTRE
     *      produit) ;
     *  (c) comportement PRÉ-2.5.5 : le texte alternatif est ÉGAL (pas seulement contenu) au nom de
     *      fichier d'une image Dolibarr COURANTE du produit.
     *
     * Un média à alt VIDE, ou qui ne correspond à AUCUNE de ces preuves, est CONSERVÉ — même s'il
     * en reste plus que d'images Dolibarr après un cycle. Mieux vaut un doublon visible qu'une
     * photo client effacée par erreur (AC5).
     *
     * ⚠️ (b)/(c) ne sont JAMAIS promus en provenance explicite (a) — voir syncProductAllImages(),
     * suppression de l'auto-guérison (re-review 27/09/2026, HIGH) : un média reconnu SEULEMENT par
     * heuristique reste reconnu par heuristique à chaque cycle, il n'est jamais écrit dans
     * `shopify_media_ids`. Seuls les identifiants que le module vient RÉELLEMENT de créer y sont
     * écrits.
     *
     * Limites résiduelles assumées, DANS LES DEUX SENS :
     *  - faux négatif (sûr) : un média (a)/(b)/(c) renommé manuellement par le client avec un alt
     *    ne correspondant plus à aucun motif échappe à la détection et reste conservé comme un
     *    surplus — aucune perte, juste un doublon ;
     *  - faux positif (risque nommé, jugé acceptable) : un client qui écrirait à la main,
     *    EXACTEMENT, l'alt que le module aurait posé (même libellé, même « photo N sur M ») ferait
     *    reconnaître son média comme provenant du module. La reproduction EXACTE d'un texte généré
     *    est jugée improbable en pratique, et le premier passage sans provenance explicite (a)
     *    conserve désormais TOUT média non reconnu — ce faux positif ne peut donc se produire que
     *    sur un média déjà reconnu par (b)/(c) lors d'un cycle où la création réussit, jamais sur
     *    un média totalement inconnu.
     *
     * @param object   $media                    Média Shopify (id, alt, ...) du snapshot AVANT le cycle
     * @param string[] $knownModuleMediaIds      Provenance explicite (a)
     * @param object   $dolParentProduct         Produit parent Dolibarr (pour le libellé courant)
     * @param string[] $currentDolibarrFilenames Noms de fichiers des images Dolibarr actuelles du produit (c)
     * @param int      $currentImageCount        Nombre d'images Dolibarr uniques COURANT (M, pour (b))
     * @return bool
     */
    private function isExistingMediaCreatedByModule($media, array $knownModuleMediaIds, $dolParentProduct, array $currentDolibarrFilenames, int $currentImageCount)
    {
        if (isset($media->id) && in_array($media->id, $knownModuleMediaIds, true)) {
            return true;
        }

        $alt = isset($media->alt) ? trim((string) $media->alt) : '';
        if ($alt === '') {
            return false;
        }

        // (b) égalité EXACTE avec la forme construite par buildImageAltText() pour le libellé
        // COURANT et CHAQUE position possible 1..M du compte d'images COURANT M — jamais un
        // simple test de suffixe. Réutilise buildImageAltText() elle-même (au lieu de
        // recalculer la résolution de libellé/troncature) pour garantir une comparaison
        // BYTE POUR BYTE avec ce que le module aurait réellement posé.
        $positionsToCheck = max(1, $currentImageCount);
        for ($n = 0; $n < $positionsToCheck; $n++) {
            if ($alt === $this->buildImageAltText($dolParentProduct, $n, $currentImageCount)) {
                return true;
            }
        }

        // (c) comportement PRÉ-2.5.5 : alt == nom de fichier d'une image Dolibarr courante.
        if (in_array($alt, $currentDolibarrFilenames, true)) {
            return true;
        }

        return false;
    }

    /**
    * Sync all images for a product with proper ordering
    *
    * @param object $dolParentProduct Dolibarr parent product
    * @param array $dolVariants Dolibarr variants (optional)
    * @return bool Success status
    */
    private function syncProductAllImages($dolParentProduct, $dolVariants = [])
    {
        // FIX #127 v2.0.36: Protection critique contre suppression accidentelle des images
        // Vérifier que la synchronisation des images est activée AVANT toute opération
        // Story 53-5 (site 3b, garde protectrice) : gate via SyncFlowPolicy — sémantique
        // protectrice PRÉSERVÉE À L'IDENTIQUE (refus policy → return true, jamais suppression)
        if (!$this->isExportFlowAllowed(SyncFlowPolicy::FLOW_IMAGES)) {
            $this->log("Image synchronization is disabled in configuration. Aborting to prevent image deletion.", LOG_INFO);
            return true; // Retourner succès pour ne pas bloquer le workflow
        }

        $this->db->begin();

        // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : déclaré AVANT le
        // try pour rester visible du catch — permet de compter une Exception survenant APRÈS le
        // snapshot (ex. staged uploads en échec total) comme un échec de création d'images,
        // jamais une Exception plus précoce (mapping absent, pré-check images, dédoublonnage).
        $reachedImageUploadPhase = false;
        // LOW (review 3 couches 27/09/2026) : évite un double comptage d'imagesCreationFailedCount
        // si $this->db->commit() (ou toute instruction après l'incrémentation dans le bloc
        // "if (!$creationFullySuccessful)") lève une Exception — auquel cas le catch ci-dessous
        // recompterait le MÊME échec pour le MÊME produit sur le MÊME cycle.
        $creationFailureAlreadyCounted = false;

        try {
            $this->log("🖼️ Starting sync all images for parent product: " . $dolParentProduct->ref, LOG_INFO);

            // FIX #139 v2.1.1: Identifier le type de produit pour diagnostic
            $isSimpleProduct = empty($dolVariants);
            $productType = $isSimpleProduct ? "SIMPLE (no variants)" : "WITH VARIANTS (" . count($dolVariants) . " variants)";
            $this->log("📋 Product type: " . $productType, LOG_INFO);

            // Get Shopify product ID - différent selon type de produit
            $shopifyProductId = null;
            $dolibarrProductId = $isSimpleProduct ? (int)$dolParentProduct->id : (int)$dolVariants[0]->id;

            // Re-review 28/09/2026 (Round 6, CRITICAL) : images_priority_requeue est LU et ÉCRIT
            // par les requêtes SQL de sélection du cron (importProducts(), ~566-578 et ~663-675)
            // via `sync.fk_product = p.rowid` — la ligne du PRODUIT PARENT, jamais celle d'une
            // déclinaison. $dolibarrProductId ci-dessus vaut pourtant l'ID de la PREMIÈRE
            // déclinaison pour un produit à variantes (nécessaire pour lire shopifyProductId/
            // shopify_media_ids, qui restent inchangés). Sans cette distinction, la priorité
            // était lue/écrite sur la ligne d'une déclinaison que la sélection du cron ne lit
            // JAMAIS : le mécanisme de rattrapage restait inopérant pour tout produit à
            // variantes — soit l'inverse de la majorité d'un catalogue. Même ID PARENT que celui
            // déjà utilisé pour location_unresolved_streak (updateLocationUnresolvedStreak(),
            // appelé avec (int) $dolProduct->id, le produit PARENT/SIMPLE — jamais une
            // déclinaison).
            $priorityRequeueProductId = $isSimpleProduct ? $dolibarrProductId : (int) $dolParentProduct->id;

            $this->log("🔑 Dolibarr Product ID used for mapping: " . $dolibarrProductId .
                      " (from " . ($isSimpleProduct ? "parent product" : "first variant") . ")", LOG_INFO);

            // FIX #139 v2.1.1: Vérification mapping AVANT toute opération destructive
            // Get Shopify data from the mapping table
            // HIGH (review 3 couches 27/09/2026) : shopify_media_ids (colonne migration
            // 2.6.0d_2.6.0e) lue ici — provenance EXPLICITE des médias créés par le module,
            // condition (a) de isExistingMediaCreatedByModule(). Peut être NULL sur une ligne
            // pas encore alimentée par cette story ; decodeKnownModuleMediaIds() gère ce cas.
            $sql = "SELECT shopifyProductId, shopify_media_ids FROM " . MAIN_DB_PREFIX . $this->table_element . "
                    WHERE fk_product = ? AND entity = " . (int)$this->entity;

            $result = SqlUtils::executeQuery($this->db, $sql, "fetching product mapping for images", true, [$dolibarrProductId]);
            if (!($parentMapping = $this->db->fetch_object($result))) {
                // FIX #139: Ne PAS lever Exception qui pourrait causer rollback après suppression
                // À la place, logger et retourner false proprement
                $this->log("[WARNING] WARNING: No Shopify mapping found for product " . $dolParentProduct->ref .
                          " (ID: $dolibarrProductId, Type: $productType). Images will NOT be synchronized to prevent deletion.",
                          LOG_WARNING);
                $this->db->rollback();
                return false;
            }

            $shopifyProductId = $parentMapping->shopifyProductId;
            $knownModuleMediaProvenance = $this->decodeKnownModuleMediaIds(isset($parentMapping->shopify_media_ids) ? $parentMapping->shopify_media_ids : null);
            $knownModuleMediaIds = $knownModuleMediaProvenance['ids'];
            $knownModuleMediaHash = $knownModuleMediaProvenance['hash'];
            $this->log("[OK] Shopify product ID found: " . $shopifyProductId, LOG_INFO);

            // Re-review 28/09/2026 (Round 5, HIGH) : la priorité est levée dès l'ENTRÉE de la
            // fonction, AVANT tout retour anticipé (pré-check images absentes, échec de
            // dédoublonnage, skip-if-unchanged) — plus seulement après le snapshot des médias
            // (ancien point d'appel, atteint APRÈS ces trois retours). Sans ce déplacement, un
            // produit DIFFÉRÉ un cycle (point 1a, budget épuisé) puis rattrapé au cycle suivant
            // par un chemin de retour anticipé (ex. skip-if-unchanged, hash inchangé) restait
            // prioritaire À CHAQUE cycle, indéfiniment : aucun des quatre retours anticipés
            // (mapping absent, pré-check images, dédoublonnage, skip-if-unchanged) ne repassait
            // par l'ancien appel de remise à 0, situé plus bas dans le flux.
            //
            // Re-review 28/09/2026 (Round 6, CRITICAL + HIGH) : lu désormais sur
            // $priorityRequeueProductId (ligne PARENT pour un produit à variantes, cf. plus haut)
            // via getImagePriorityRequeueFlag() — plus depuis $parentMapping (qui reste keyé sur
            // la première déclinaison, jamais la bonne ligne pour ce flag). Ce garde-fou aligne
            // AUSSI le prédicat fk_store de cette lecture sur celui de l'écriture
            // (setImagePriorityRequeue()) : sans cet alignement, une ligne pas encore backfillée
            // (fk_store = 0) alors que la boutique courante en a un pouvait faire lire un flag à
            // 1 que l'écriture, filtrée strictement sur fk_store = ?, ne parvenait jamais à
            // remettre à 0 (0 ligne affectée, flag bloqué à 1 indéfiniment).
            //
            // N'écrit en base QUE si le flag valait 1 : la très large majorité des produits n'ont
            // jamais été différés, un UPDATE inconditionnel à CHAQUE produit de CHAQUE cycle
            // serait un coût sans aucune valeur.
            if ($this->getImagePriorityRequeueFlag($priorityRequeueProductId, (int) $this->entity) === 1) {
                $this->setImagePriorityRequeue($priorityRequeueProductId, (int) $this->entity, false);
            }

            // FIX #139 v2.1.1: Vérifier qu'il y aura des images à uploader AVANT de supprimer
            // Cela évite de supprimer des images si le processus échoue ensuite
            $this->log("🔍 Pre-checking available images before deletion...", LOG_DEBUG);
            $preCheckImages = $this->getDolibarrImages($dolParentProduct);
            if (empty($preCheckImages) && $isSimpleProduct) {
                $this->log("[WARNING] WARNING: No images found in Dolibarr for simple product " . $dolParentProduct->ref .
                          ". Aborting to prevent deletion without replacement.", LOG_WARNING);

                // L'abandon reste inchangé (il protège les médias Shopify d'une suppression sans
                // remplacement) — ce qui change, c'est qu'il CESSE D'ÊTRE MUET. Sans ce compteur,
                // un catalogue entier peut repartir sans photo en affichant une synchronisation
                // « réussie », et le client n'a aucun moyen de distinguer « rien à envoyer » de
                // « envoi refusé ». Constaté sur un dossier client de septembre 2026.
                $this->imagesNoSourceFoundCount++;
                if (count($this->imagesNoSourceFoundRefs) < 20) {
                    $this->imagesNoSourceFoundRefs[] = (string) $dolParentProduct->ref;
                }

                $this->db->rollback();
                return false;
            }
            $this->log("[OK] Found " . count($preCheckImages) . " parent images, proceeding with synchronization", LOG_DEBUG);

            // 1. Collect all images to upload (deletion moved AFTER skip-if-unchanged check — Story 8.2)
            $allImages = [];
            $mediaOrder = []; // Array to track desired media order

            // 2.1 First collect parent images
            $parentImages = $this->getDolibarrImages($dolParentProduct);
            $this->log("Found " . count($parentImages) . " parent images for product: " . $dolParentProduct->ref, LOG_DEBUG);

            foreach ($parentImages as $image) {
                $allImages[] = [
                    'image' =>  $image,
                    'type' =>  'parent',
                    'product' =>  $dolParentProduct,
                    'variantId' =>  null
                ];
            }

            // 2.2 Then collect variant images, but only for variants that are "En Ventes" (tosell = 1)
            // Story 8.1: Build mapping for ALL active variants (needed for visual attribute image propagation)
            $allVariantShopifyIds = array(); // [dolVariantId => shopifyVariantId]
            $activeVariantIds = array(); // Active variant Dolibarr IDs (tosell != 0)
            if (!empty($dolVariants)) {
                // Filter active variants first
                foreach ($dolVariants as $dolVariant) {
                    if ($dolVariant->tosell !== null && $dolVariant->tosell !== '' && ($dolVariant->tosell == 0 || $dolVariant->tosell === '0')) {
                        $this->log("ImportProducts::syncProductAllImages - Skipping images for Hors Ventes variant: " . $dolVariant->ref . " (tosell value: '" . var_export($dolVariant->tosell, true) . "')", LOG_DEBUG);
                        continue;
                    }
                    $activeVariantIds[] = (int)$dolVariant->id;
                }

                // Story 8.1 review fix: Batch query for ALL active variant mappings (1 query instead of N)
                if (!empty($activeVariantIds)) {
                    $inClause = implode(',', $activeVariantIds);
                    $sql = "SELECT fk_product, shopifyVariantId FROM " . MAIN_DB_PREFIX . $this->table_element . "
                            WHERE fk_product IN (" . $inClause . ") AND entity = " . (int)$this->entity;
                    $result = SqlUtils::executeQuery($this->db, $sql, "fetching variant mappings for images (batch)", true);
                    while ($row = $this->db->fetch_object($result)) {
                        $allVariantShopifyIds[(int)$row->fk_product] = $row->shopifyVariantId;
                    }
                    $this->db->free($result);
                }

                foreach ($dolVariants as $dolVariant) {
                    if (!in_array((int)$dolVariant->id, $activeVariantIds)) {
                        continue;
                    }

                    $shopifyVarId = isset($allVariantShopifyIds[(int)$dolVariant->id]) ? $allVariantShopifyIds[(int)$dolVariant->id] : null;

                    $variantImages = $this->getDolibarrImages($dolVariant);
                    if (!empty($variantImages) && $shopifyVarId) {
                        foreach ($variantImages as $image) {
                            $allImages[] = [
                                'image' =>  $image,
                                'type' =>  'variant',
                                'product' =>  $dolVariant,
                                'variantId' =>  $shopifyVarId
                            ];
                        }
                    }
                }
            }

            if (empty($allImages)) {
                $this->log("ImportProducts::syncProductAllImages - No images to upload for product: " . $dolParentProduct->ref, LOG_INFO);
                $this->db->commit();
                return true;
            }

            $this->log("ImportProducts::syncProductAllImages - Total images collected: " . count($allImages), LOG_INFO);

            // HIGH (review 3 couches 27/09/2026) : noms de fichiers Dolibarr COURANTS — condition
            // (c) de isExistingMediaCreatedByModule() (comportement pré-2.5.5 : alt == nom de
            // fichier). Calculé sur $allImages (parent + variantes), avant dédoublonnage.
            $currentDolibarrFilenames = [];
            foreach ($allImages as $imageEntry) {
                if (!empty($imageEntry['image']['name'])) {
                    $currentDolibarrFilenames[] = basename((string) $imageEntry['image']['name']);
                }
            }

            // 2.3 Story 8.2: Download all image contents and deduplicate by SHA256 hash
            $dedup = $this->deduplicateImages($allImages);
            if ($dedup === null) {
                $this->log("ImportProducts::syncProductAllImages - Story 8.2: Failed to download/deduplicate images for " . $dolParentProduct->ref, LOG_ERR);
                $this->db->rollback();
                return false;
            }

            $uniqueImages = $dedup['uniqueImages'];
            $imageContents = $dedup['imageContents'];
            $hashToOriginalIndexes = $dedup['hashToOriginalIndexes'];
            $compositeHash = $dedup['compositeHash'];
            $individualHashes = $dedup['individualHashes'];
            $dedupFailedCount = $dedup['failedCount'];

            if ($dedup['uniqueCount'] < $dedup['originalCount']) {
                $this->log("ImportProducts::syncProductAllImages - Story 8.2: Deduplicated " . $dedup['originalCount'] . " images to " . $dedup['uniqueCount'] . " unique (saving " . ($dedup['originalCount'] - $dedup['uniqueCount']) . " uploads)", LOG_INFO);
            }

            // Story 8.2: Free dedup structure to release duplicate imageContents references (memory optimization)
            unset($dedup);

            // 2.4 Story 8.2 / hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC2) :
            // skip-if-unchanged — compare composite hash to stored hash, PUIS vérifie en DIRECT
            // que Shopify détient toujours le nombre de médias attendu avant de faire confiance
            // à ce hash pour sauter l'envoi.
            //
            // POURQUOI cette vérification live, et pas simplement lier le hash au
            // shopifyProductId (l'autre piste envisagée par la story) : le hash est calculé et
            // stocké UNIQUEMENT à partir de l'état des images côté DOLIBARR (fk_product, entity)
            // — il ne dit RIEN sur l'état de la DESTINATION. Lier le hash au shopifyProductId
            // aurait seulement couvert le cas "produit Shopify recréé" (nouvel ID) ; il serait
            // resté aveugle au cas "médias supprimés sur le MÊME produit Shopify" (id inchangé,
            // ce que confirme l'export de Xavier Hubier : "media": {"edges": []}) — pourtant
            // explicitement cité par la story comme un des chemins de divergence (suppression des
            // médias, restauration de sauvegarde...). Vérifier le nombre RÉEL de médias côté
            // Shopify couvre TOUS ces cas d'un seul coup, quelle que soit la manière dont la
            // destination a divergé, pour le coût d'un seul appel API léger (mediaCount), sans
            // jamais retélécharger ni renvoyer une image tant que la vérification est concluante.
            //
            // Sur erreur/produit introuvable (getProductMediaCount() retourne null) : on NE
            // SAUTE PAS — on ne peut pas prouver que la destination a ce qu'il faut, et un envoi
            // superflu coûte infiniment moins qu'un silence permanent (c'est exactement l'erreur
            // de raisonnement qui a bloqué Xavier trois fois : une preuve sur la source utilisée
            // comme preuve sur la destination).
            $storedHash = $this->getStoredCompositeHash((int)$dolParentProduct->id, (int)$this->entity);
            $hashMatchesStored = ($storedHash !== null && $storedHash === $compositeHash);
            if ($hashMatchesStored) {
                $expectedMediaCount = count($uniqueImages);
                $actualMediaCount = $this->shopifyApi->getProductMediaCount($shopifyProductId);

                // Code review 3 couches (Blind Hunter/Edge Case Hunter, 2026-09-18) — CRITICAL :
                // Product.mediaCount côté Shopify compte TOUS les types de médias (vidéos,
                // modèles 3D, ET toute image ajoutée à la main par le client dans l'admin
                // Shopify), alors que $expectedMediaCount ne compte que les images Dolibarr
                // dédupliquées. Un client qui ajoute UNE vidéo produit, ou UNE photo directement
                // dans Shopify, fait diverger actualMediaCount > expectedMediaCount de façon
                // PERMANENTE — rien dans ce cycle ni les suivants ne réduit cet écart. Sous une
                // égalité stricte (===), cela aurait forcé un renvoi complet à CHAQUE cycle,
                // indéfiniment (le coût que Story 8.2 existe pour éviter), ET
                // deleteExistingProductImages() aurait supprimé cet ajout manuel à chaque
                // passage puisqu'il ne fait aucune distinction d'origine.
                // On ne force le renvoi que si Shopify détient MOINS de médias que prévu : c'est
                // la seule situation qui prouve une PERTE côté destination (suppression du
                // produit/des médias, recréation, restauration — le symptôme Xavier Hubier). Un
                // excédent (ajout manuel, vidéo, modèle 3D) n'est jamais un signal de divergence
                // à corriger ici et reste intact — cf.
                // ShopifyApiProductMediaCountTest/ImportProductsImageHashCallSiteTest (aucun des
                // deux ne couvre le cas actual > expected ; c'est le trou comblé ici).
                if ($actualMediaCount !== null && $actualMediaCount >= $expectedMediaCount) {
                    $this->imagesSyncSkippedCount++;
                    $this->log("ImportProducts::syncProductAllImages - Story 8.2 / hash-images-survit-a-la-purge-et-bloque-le-renvoi: Images unchanged for product " . $dolParentProduct->ref . " (hash: " . substr($compositeHash, 0, 12) . ") AND Shopify confirms " . $actualMediaCount . " media present, skipping sync", LOG_INFO);
                    $this->db->commit();
                    return true;
                }

                // Hash identique côté Dolibarr, mais Shopify ne détient PAS le nombre de médias
                // attendu : la destination a divergé (produit/médias supprimés, recréation,
                // restauration...). On force un renvoi complet, comme si le hash était absent.
                $this->imagesResyncForcedCount++;
                $this->log("ImportProducts::syncProductAllImages - hash-images-survit-a-la-purge-et-bloque-le-renvoi: hash identique pour le produit " . $dolParentProduct->ref . " (hash: " . substr($compositeHash, 0, 12) . ") MAIS Shopify détient " . var_export($actualMediaCount, true) . " média(s) au lieu des " . $expectedMediaCount . " attendus — la destination a divergé, renvoi complet forcé.", LOG_WARNING);
            }

            $this->log("ImportProducts::syncProductAllImages - Total unique images to upload: " . count($uniqueImages), LOG_INFO);

            // HIGH (re-review 27/09/2026, point 1a) : si le budget de polling du CYCLE est DÉJÀ
            // épuisé AVANT même d'attaquer les images de CE produit, ne rien créer — ni staged
            // upload, ni createProductMedia. Sans ce garde-fou, le budget ne protégeait QUE
            // l'attente (waitForMediaToBeReady()), pas la création elle-même : un produit
            // chroniquement en fin de file (ordre déterministe de la sélection SQL de
            // importProducts()) recréait un lot complet à CHAQUE cycle, sans jamais rien
            // supprimer (creationFullySuccessful restait toujours false faute de confirmation) —
            // accumulation illimitée de médias en double.
            if ($this->pollingBudgetUsedSeconds >= $this->pollingBudgetSeconds) {
                $this->imagesDeferredForBudgetCount++;
                if (count($this->imagesDeferredForBudgetRefs) < 20) {
                    $this->imagesDeferredForBudgetRefs[] = (string) $dolParentProduct->ref;
                }
                // Point 1b : priorité au cycle suivant, pour ne jamais affamer ce produit.
                // Round 6 (CRITICAL) : $priorityRequeueProductId (ligne PARENT), jamais
                // $dolibarrProductId (première déclinaison pour un produit à variantes).
                $this->setImagePriorityRequeue($priorityRequeueProductId, (int) $this->entity, true);
                $this->log("ImportProducts::syncProductAllImages - Budget de polling du cycle déjà épuisé ("
                    . round($this->pollingBudgetUsedSeconds, 1) . "s/" . $this->pollingBudgetSeconds
                    . "s) : produit " . $dolParentProduct->ref . " REPORTÉ sans aucun appel de création"
                    . " (ni staged upload, ni createProductMedia), priorité au prochain cycle.", LOG_INFO);
                $this->db->commit();
                return true;
            }

            // 2. Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : la
            // suppression n'a plus le droit d'intervenir ICI. On se contente de PHOTOGRAPHIER
            // l'état Shopify actuel — cette liste sert plus bas à ne supprimer QUE ce que la
            // création aura effectivement remplacé, jamais avant, jamais plus. Snapshot pris
            // AVANT tout envoi : après création, getProductImages() renverrait aussi les
            // nouveaux médias et rendrait la distinction impossible.
            $this->log("📸 Snapshot des médias existants avant envoi (produit " . $shopifyProductId . ")", LOG_DEBUG);
            $existingMediaBeforeSync = $this->shopifyApi->getProductImages($shopifyProductId);
            $existingMediaBeforeIds = array_map(function ($m) {
                return $m->id;
            }, $existingMediaBeforeSync);
            // Marque le point à partir duquel une Exception (ex. createStagedUploads() en échec
            // total ci-dessous) doit être comptée comme un échec de création — jamais avant
            // (les abandons précoces, ex. mapping/pré-check absents, sont déjà gérés par leurs
            // propres `return false` et ne passent jamais par le catch englobant).
            $reachedImageUploadPhase = true;

            // Re-review 28/09/2026 (Round 5) : l'ancienne remise à 0 vivait ICI — trop tard,
            // après les retours anticipés de pré-check/dédoublonnage/skip-if-unchanged. Déplacée
            // à l'entrée de la fonction (juste après confirmation du mapping, voir plus haut).
            // Ré-posée à true plus bas si ce cycle DIFFÈRE quand même (point 1c, lot précédent
            // encore en cours).

            // HIGH (re-review 27/09/2026, point 1c ; re-review 28/09/2026 : garde-fou par hash) :
            // des médias CRÉÉS PAR LE MODULE lors d'un cycle précédent, mais jamais confirmés
            // (cycle interrompu par le budget), restent dans $knownModuleMediaIds sans qu'on
            // sache s'ils sont utilisables. AVANT d'en recréer un nouveau lot, on vérifie leur
            // statut RÉEL — jamais une seconde création sur un lot déjà en vol.
            //
            // ⚠️ Le hash composite ($knownModuleMediaHash === $compositeHash) est la condition
            // DÉTERMINANTE, pas un simple bonus : sans elle, un lot ANCIEN mais déjà pleinement
            // CONFIRMÉ (persisté après un cycle réussi, donc légitimement recensé comme
            // provenance (a)) serait à tort traité comme "en attente de confirmation" dès que son
            // compte d'identifiants coïncide avec le nombre d'images Dolibarr courant — y compris
            // quand les images ont changé depuis (contenu différent, même total). Le hash composite
            // ne peut être identique que si RIEN n'a changé côté Dolibarr depuis que ce lot précis
            // a été créé : c'est la seule preuve fiable qu'il s'agit bien du MÊME cycle interrompu,
            // pas d'un lot antérieur et désormais périmé.
            $pendingKnownMediaIds = array_values(array_intersect($knownModuleMediaIds, $existingMediaBeforeIds));
            if (
                $knownModuleMediaHash !== null
                && $knownModuleMediaHash === $compositeHash
                && !empty($pendingKnownMediaIds)
                && count($pendingKnownMediaIds) === count($uniqueImages)
            ) {
                $pendingStatusResponse = $this->shopifyApi->getMediaStatusByIds($pendingKnownMediaIds);
                $pendingStatusesById = $this->indexMediaStatusResponseById($pendingStatusResponse);

                $allPendingReady = true;
                $anyPendingFailed = false;
                $failedPendingIds = [];
                foreach ($pendingKnownMediaIds as $pendingId) {
                    $pendingStatus = isset($pendingStatusesById[$pendingId]) ? $pendingStatusesById[$pendingId] : null;
                    if ($pendingStatus === 'FAILED') {
                        $anyPendingFailed = true;
                        $failedPendingIds[] = $pendingId;
                        $allPendingReady = false;
                    } elseif ($pendingStatus !== 'READY') {
                        $allPendingReady = false;
                    }
                }

                if ($allPendingReady) {
                    // Convergence : le lot d'un cycle précédent est maintenant CONFIRMÉ READY —
                    // réutilisé tel quel, AUCUNE recréation. L'association parent/variantes a
                    // déjà eu lieu lors du cycle de création initial (elle ne dépend pas de la
                    // confirmation du statut) ; il ne reste qu'à nettoyer les anciens médias
                    // effectivement remplacés et à figer l'empreinte.
                    $this->log("ImportProducts::syncProductAllImages - " . count($pendingKnownMediaIds)
                        . " média(s) créé(s) lors d'un cycle précédent sont maintenant CONFIRMÉS READY pour le produit "
                        . $dolParentProduct->ref . " : réutilisés comme lot courant, aucune recréation.", LOG_INFO);

                    $mediaIdsToDeleteNow = [];
                    $keptMediaIdsNow = [];
                    foreach ($existingMediaBeforeSync as $existingMedia) {
                        if (in_array($existingMedia->id, $pendingKnownMediaIds, true)) {
                            continue; // fait partie du lot réutilisé — jamais candidat à la suppression
                        }
                        if ($this->isExistingMediaCreatedByModule($existingMedia, $knownModuleMediaIds, $dolParentProduct, $currentDolibarrFilenames, count($uniqueImages))) {
                            $mediaIdsToDeleteNow[] = $existingMedia->id;
                        } else {
                            $keptMediaIdsNow[] = $existingMedia->id;
                        }
                    }

                    $deletedNowIds = [];
                    if (!empty($mediaIdsToDeleteNow)) {
                        $deletionResultNow = $this->deleteSpecificProductMedia($mediaIdsToDeleteNow, $shopifyProductId);
                        $deletedNowIds = $deletionResultNow['deletedIds'];
                        if (!$deletionResultNow['success']) {
                            $this->imagesDeleteFailedCount++;
                            if (count($this->imagesDeleteFailedRefs) < 20) {
                                $this->imagesDeleteFailedRefs[] = (string) $dolParentProduct->ref;
                            }
                            $this->log("[WARNING] Failed to delete some replaced media for product: " . $shopifyProductId
                                . ". Old media may remain alongside the reused batch — no data was lost.", LOG_WARNING);
                        }
                        if (!empty($keptMediaIdsNow)) {
                            $this->log("ImportProducts::syncProductAllImages - " . count($keptMediaIdsNow)
                                . " média(s) Shopify surplus préservé(s) (non appariés, jamais supprimés par défaut) pour le produit "
                                . $dolParentProduct->ref, LOG_INFO);
                        }
                    }

                    $finalKnownIds = array_values(array_diff(array_unique($pendingKnownMediaIds), $deletedNowIds));
                    $this->saveKnownModuleMediaIds($dolibarrProductId, (int) $this->entity, $finalKnownIds, $compositeHash);

                    $hashImageCount = count($allImages) - $dedupFailedCount;
                    $this->updateStoredCompositeHash((int) $dolParentProduct->id, (int) $this->entity, $compositeHash, $hashImageCount);

                    $this->db->commit();
                    $this->log("ImportProducts::syncProductAllImages - Image synchronization completed (lot réutilisé) for product: " . $dolParentProduct->ref, LOG_INFO);
                    return true;
                }

                if ($anyPendingFailed) {
                    // "S'ils sont FAILED, supprime-les (ils sont bien au module) et recrée" :
                    // nettoyage puis poursuite normale du flux (staged upload/création fraîche
                    // ci-dessous), comme si ce lot n'avait jamais existé.
                    $this->log("ImportProducts::syncProductAllImages - " . count($failedPendingIds)
                        . " média(s) d'un cycle précédent ont échoué (FAILED) pour le produit "
                        . $dolParentProduct->ref . " : suppression puis recréation.", LOG_WARNING);
                    $failedDeletionResult = $this->deleteSpecificProductMedia($failedPendingIds, $shopifyProductId);
                    $knownModuleMediaIds = array_values(array_diff($knownModuleMediaIds, $failedDeletionResult['deletedIds']));
                    // Le snapshot pré-cycle contenait ces médias FAILED : les retirer aussi de
                    // $existingMediaBeforeSync/$existingMediaBeforeIds pour que la sélection de
                    // suppression provenance-based, plus bas, ne tente pas de les supprimer une
                    // seconde fois.
                    $existingMediaBeforeSync = array_values(array_filter($existingMediaBeforeSync, function ($m) use ($failedPendingIds) {
                        return !in_array($m->id, $failedPendingIds, true);
                    }));
                    $existingMediaBeforeIds = array_values(array_diff($existingMediaBeforeIds, $failedPendingIds));
                    // Poursuite du flux normal (staged upload + création fraîche) ci-dessous.
                } else {
                    // Ni READY ni FAILED : encore en cours de traitement chez Shopify. Ne PAS
                    // créer un second lot par-dessus — différer proprement, comme le budget.
                    $this->imagesDeferredForBudgetCount++;
                    if (count($this->imagesDeferredForBudgetRefs) < 20) {
                        $this->imagesDeferredForBudgetRefs[] = (string) $dolParentProduct->ref;
                    }
                    // Round 6 (CRITICAL) : ligne PARENT, même raison que le point 1a ci-dessus.
                    $this->setImagePriorityRequeue($priorityRequeueProductId, (int) $this->entity, true);
                    $this->log("ImportProducts::syncProductAllImages - Le lot de médias d'un cycle précédent pour le produit "
                        . $dolParentProduct->ref . " est encore en cours de traitement chez Shopify (ni READY ni FAILED) :"
                        . " REPORTÉ sans créer de second lot, priorité au prochain cycle.", LOG_INFO);
                    $this->db->commit();
                    return true;
                }
            }

            // 3. Prepare staged uploads for unique images only (Story 8.2: deduplicated)
            $stagedUploadInputs = [];
            $imageInfoMap = []; // Map of index -> image info

            foreach ($uniqueImages as $index => $imageInfo) {
                $image = $imageInfo['image'];

                // 🔴 basename() OBLIGATOIRE. `$image['name']` est un CHEMIN RELATIF
                // `{ref}/{fichier}` — c'est la forme produite par DolibarrDirectFileResolver
                // (`:319` et `:896`, `$relativeKey`). Shopify, lui, refuse tout nom de fichier
                // contenant une barre oblique :
                //
                //   userErrors[0] : « B000107161008/B000107161008.jpg: Invalid filename »
                //
                // et renvoie alors une URL de televersement VIDE, dans une reponse 200 OK. Le
                // module televersait vers cette URL vide et obtenait un « cURL error 3: URL
                // rejected » incomprehensible — APRES avoir deja supprime les images existantes
                // (etape 2 ci-dessus). Le produit restait donc SANS AUCUNE IMAGE, et chaque
                // nouvelle tentative repetait la suppression. Constate en production le
                // 19/09/2026 (Europe Loisirs, produit B000107161008).
                //
                // ⚠️ L'inversion a retenir : seules les installations dont les photos sont
                // CORRECTEMENT INDEXEES dans llx_ecm_files sont touchees. Celles dont les photos
                // ne sont trouvees que sur le disque passent par le repli, qui produit un nom nu.
                // L'installation la mieux configuree etait la seule a perdre ses images.
                $uploadFilename = basename((string) $image['name']);

                $input = [
                    'filename' =>  $uploadFilename,
                    'mimeType' =>  $this->getMimeType($uploadFilename),
                    'httpMethod' =>  'PUT',
                    'resource' =>  'IMAGE',
                    'fileSize' =>  (string)$image['size']
                ];

                $stagedUploadInputs[] = $input;
                $imageInfoMap[$index] = $imageInfo;
            }

            // 4. Create staged uploads
            $stagedUploads = $this->shopifyApi->createStagedUploads($stagedUploadInputs);
            if (
                empty($stagedUploads) ||
                empty($stagedUploads->data) ||
                empty($stagedUploads->data->stagedUploadsCreate) ||
                empty($stagedUploads->data->stagedUploadsCreate->stagedTargets)
            ) {
                throw new Exception("Failed to create staged uploads");
            }

            // 5. Upload all images
            $uploadedImages = [];
            $stagedTargets = $stagedUploads->data->stagedUploadsCreate->stagedTargets;

            foreach ($stagedTargets as $index => $target) {
                $imageInfo = $imageInfoMap[$index];
                $image = $imageInfo['image'];

                // Story 8.2: Use pre-downloaded content from deduplication (no re-download)
                $hash = $individualHashes[$index];
                $imageContent = isset($imageContents[$hash]) ? $imageContents[$hash] : null;
                if (!$imageContent) {
                    $this->log("ImportProducts::syncProductAllImages - No pre-downloaded content for image " . $image['name'] . " (hash: " . substr($hash, 0, 12) . ")", LOG_WARNING);
                    continue;
                }

                // Upload the image
                $uploadSuccess = $this->shopifyApi->uploadToStagedTarget($target, $imageContent);
                if ($uploadSuccess) {
                    $uploadedImages[] = [
                        'resourceUrl' =>  $target->resourceUrl,
                        'alt' =>  $this->buildImageAltText($dolParentProduct, $index, count($uniqueImages)),
                        'mediaContentType' =>  'IMAGE',
                        'originalSource' =>  $target->resourceUrl,
                        'imageInfo' =>  $imageInfo,
                        'hash' =>  $hash
                    ];
                    $this->log("ImportProducts::syncProductAllImages - Successfully uploaded image: " . $image['name'], LOG_DEBUG);
                    // Story 8.2: Free binary content after upload to save memory
                    unset($imageContents[$hash]);
                } else {
                    // Story 61-6 (review, MEDIUM-1) : uploadToStagedTarget() expose désormais la
                    // cause réelle de l'échec via $this->shopifyApi->error (ex. "cURL error 60:
                    // SSL certificate problem…") — avant, seul un booléen falsy remontait ici et
                    // ce log générique masquait la cause.
                    $this->log("ImportProducts::syncProductAllImages - Failed to upload image: " . $image['name'] . " - Cause: " . $this->shopifyApi->error, LOG_WARNING);
                }
            }

            $this->log("ImportProducts::syncProductAllImages - Successfully uploaded " . count($uploadedImages) . " of " . count($uniqueImages) . " unique images (from " . count($allImages) . " total)", LOG_INFO);

            // 6. Create media in Shopify
            //
            // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : $creationFullySuccessful
            // devient la SEULE porte pour deux décisions — mettre à jour l'empreinte composite (comme
            // avant, Story 8.2) ET, désormais, supprimer les anciens médias. Tant qu'elle n'est pas
            // levée à true, AUCUNE suppression n'a lieu et les anciens médias restent en place.
            $creationFullySuccessful = false;
            $mediaIdsToDelete = [];
            $keptMediaIds = $existingMediaBeforeIds; // par défaut : rien supprimé, tout conservé
            if (!empty($uploadedImages)) {
                $mediaInputs = [];

                foreach ($uploadedImages as $uploadedImage) {
                    $mediaInputs[] = [
                        'alt' =>  $uploadedImage['alt'],
                        'mediaContentType' =>  $uploadedImage['mediaContentType'],
                        'originalSource' =>  $uploadedImage['originalSource']
                    ];
                }

                // Create media
                $this->log("Creating " . count($mediaInputs) . " media in Shopify", LOG_INFO);
                $response = $this->shopifyApi->createProductMedia($shopifyProductId, $mediaInputs);

                $mediaUserErrors = (!empty($response->data->productCreateMedia->mediaUserErrors))
                    ? $response->data->productCreateMedia->mediaUserErrors
                    : [];

                if (!empty($response->data->productCreateMedia->media)) {
                    $createdMedia = $response->data->productCreateMedia->media;
                    $this->log("Successfully created " . count($createdMedia) . " media", LOG_INFO);

                    // Signal IMMÉDIAT (réponse de création) — utilisé seulement comme filtre
                    // précoce. Il ne suffit PAS à prouver le succès : voir le statut FINAL
                    // (polling) ci-dessous, seul habilité à faire foi (CRITICAL, review 3 couches
                    // 27/09/2026).
                    $immediateFailedStatusCount = 0;
                    foreach ($createdMedia as $media) {
                        if (isset($media->status) && $media->status === 'FAILED') {
                            $immediateFailedStatusCount++;
                        }
                    }

                    // Track media IDs for parent images (to maintain order) and variants (for associations)
                    $parentMediaIds = [];
                    $variantMediaMap = []; // variantId => [mediaIds]

                    // Wait for all media to be ready before proceeding
                    $allMediaIds = [];
                    foreach ($createdMedia as $media) {
                        $allMediaIds[] = $media->id;
                    }

                    // Re-review 3 couches 27/09/2026 (point 3) : budget de polling CUMULÉ sur le
                    // cycle. Épuisé -> ce produit est traité comme NON PRÊT SANS interroger
                    // Shopify (aucune suppression, empreinte non écrite, nouvelle tentative au
                    // cycle suivant) — jamais de suppression par défaut de vérification.
                    if ($this->pollingBudgetUsedSeconds >= $this->pollingBudgetSeconds) {
                        $this->log("ImportProducts::syncProductAllImages - Budget de polling du cycle épuisé ("
                            . round($this->pollingBudgetUsedSeconds, 1) . "s/" . $this->pollingBudgetSeconds
                            . "s) : produit " . $dolParentProduct->ref . " traité comme NON PRÊT sans interroger Shopify,"
                            . " aucune suppression, nouvelle tentative au prochain cycle.", LOG_WARNING);
                        $readyResult = array('allReady' => false, 'anyFailed' => false, 'timedOut' => true, 'statuses' => array());
                        // LOW (re-review 28/09/2026, Round 6, point 3) : un lot vient d'être CRÉÉ
                        // mais jamais interrogé (budget épuisé PENDANT le polling, pas seulement
                        // avant — cf. point 1a ci-dessus) — ce produit doit être repris en
                        // PRIORITÉ au prochain cycle pour confirmer ce lot en attente (point 1c),
                        // exactement comme les deux autres chemins de report.
                        $this->setImagePriorityRequeue($priorityRequeueProductId, (int) $this->entity, true);
                    } else {
                        $this->log("Waiting for " . count($allMediaIds) . " media to be ready...", LOG_INFO);
                        $pollingStartedAt = microtime(true);
                        $readyResult = $this->waitForMediaToBeReady($shopifyProductId, $allMediaIds, $this->mediaReadyMaxAttempts, $this->mediaReadySleepSeconds);
                        $this->pollingBudgetUsedSeconds += (microtime(true) - $pollingStartedAt);
                    }

                    if (!$readyResult['allReady']) {
                        if ($readyResult['anyFailed']) {
                            $this->log("ImportProducts::syncProductAllImages - Au moins un média a basculé FAILED de façon asynchrone après création (statut final, pas immédiat) pour le produit " . $dolParentProduct->ref, LOG_ERR);
                        } else {
                            $this->log("Not all media became ready (timeout), but continuing with available media", LOG_WARNING);
                        }
                    }

                    // CRITICAL (review 3 couches 27/09/2026) : la décision de suppression ET
                    // l'écriture de l'empreinte composite ne peuvent PLUS se fier au statut
                    // IMMÉDIAT de la réponse productCreateMedia (souvent PROCESSING/UPLOADED,
                    // jamais garanti). Seul le statut FINAL observé par waitForMediaToBeReady()
                    // (polling) fait foi : $readyResult['allReady'] exige que TOUS les médias
                    // créés aient atteint READY — un FAILED asynchrone ou un timeout bloquent
                    // désormais la suppression exactement comme un échec immédiat.
                    $creationFullySuccessful = empty($mediaUserErrors)
                        && $immediateFailedStatusCount === 0
                        && count($createdMedia) === count($uploadedImages)
                        && count($uploadedImages) === count($uniqueImages)
                        && $readyResult['allReady'];

                    // Story 8.2: Process created media and map to ALL original entries via hash-based deduplication
                    // Each unique image upload maps to one or more original entries sharing the same hash
                    foreach ($createdMedia as $index => $media) {
                        if (isset($uploadedImages[$index])) {
                            $uploadedImage = $uploadedImages[$index];
                            $hash = isset($uploadedImage['hash']) ? $uploadedImage['hash'] : null;

                            // Map this media ID to ALL original images sharing the same hash
                            if ($hash !== null && isset($hashToOriginalIndexes[$hash])) {
                                foreach ($hashToOriginalIndexes[$hash] as $originalIndex) {
                                    $originalImageInfo = $allImages[$originalIndex];

                                    // For media ordering, add parent media (once)
                                    if ($originalImageInfo['type'] === 'parent') {
                                        if (!in_array($media->id, $parentMediaIds)) {
                                            $parentMediaIds[] = $media->id;
                                        }
                                    }

                                    // For variant associations
                                    if ($originalImageInfo['type'] === 'variant' && !empty($originalImageInfo['variantId'])) {
                                        if (!isset($variantMediaMap[$originalImageInfo['variantId']])) {
                                            $variantMediaMap[$originalImageInfo['variantId']] = [];
                                        }
                                        if (!in_array($media->id, $variantMediaMap[$originalImageInfo['variantId']])) {
                                            $variantMediaMap[$originalImageInfo['variantId']][] = $media->id;
                                        }
                                    }
                                }
                            } else {
                                // Fallback: direct mapping (no hash available, shouldn't happen)
                                $imageInfo = $uploadedImage['imageInfo'];
                                if ($imageInfo['type'] === 'parent') {
                                    $parentMediaIds[] = $media->id;
                                }
                                if ($imageInfo['type'] === 'variant' && !empty($imageInfo['variantId'])) {
                                    if (!isset($variantMediaMap[$imageInfo['variantId']])) {
                                        $variantMediaMap[$imageInfo['variantId']] = [];
                                    }
                                    $variantMediaMap[$imageInfo['variantId']][] = $media->id;
                                }
                            }
                        }
                    }

                    // Story 8.1: Propagate media to sibling variants sharing same visual attribute
                    // For multi-attribute products (e.g., Color x Size), if only one variant per color
                    // has an image, propagate that media association to ALL variants of the same color.
                    // The image is NOT re-uploaded — we reuse the same Shopify media ID.
                    if (!empty($dolVariants) && !empty($allVariantShopifyIds) && !empty($variantMediaMap)) {
                        $visualAttr = $this->detectVisualAttribute($dolParentProduct);
                        if ($visualAttr !== null) {
                            // Build list of active variants (those with a Shopify mapping)
                            $activeVariants = array();
                            foreach ($dolVariants as $dv) {
                                if (isset($allVariantShopifyIds[(int)$dv->id])) {
                                    $activeVariants[] = $dv;
                                }
                            }

                            $groups = $this->groupVariantsByVisualAttribute($activeVariants, $visualAttr);

                            $propagatedCount = 0;
                            foreach ($groups as $attrValue => $groupVariants) {
                                // Find donor: first variant in group that has media
                                $donorMediaIds = array();
                                $donorRef = '';
                                foreach ($groupVariants as $gv) {
                                    $shopifyVId = isset($allVariantShopifyIds[(int)$gv->id]) ? $allVariantShopifyIds[(int)$gv->id] : '';
                                    if (!empty($shopifyVId) && !empty($variantMediaMap[$shopifyVId])) {
                                        $donorMediaIds = $variantMediaMap[$shopifyVId];
                                        $donorRef = $gv->ref;
                                        break;
                                    }
                                }

                                // Propagate to siblings without media
                                if (!empty($donorMediaIds)) {
                                    foreach ($groupVariants as $gv) {
                                        $shopifyVId = isset($allVariantShopifyIds[(int)$gv->id]) ? $allVariantShopifyIds[(int)$gv->id] : '';
                                        if (!empty($shopifyVId) && empty($variantMediaMap[$shopifyVId])) {
                                            $variantMediaMap[$shopifyVId] = $donorMediaIds;
                                            $propagatedCount++;
                                            $this->log("ImportProducts::syncProductAllImages - Variant " . $gv->ref . " inherits image from sibling " . $donorRef . " (shared attribute: " . $visualAttr . "=" . $attrValue . ")", LOG_INFO);
                                        }
                                    }
                                }
                            }

                            if ($propagatedCount > 0) {
                                $this->log("ImportProducts::syncProductAllImages - Story 8.1: Propagated media to " . $propagatedCount . " sibling variants via visual attribute '" . $visualAttr . "'", LOG_INFO);
                            }
                        }
                    }

                    // AC1 (item 2 du périmètre tranché) : les anciens médias ne sont supprimés QUE
                    // si la création a intégralement réussi.
                    //
                    // HIGH (review 3 couches 27/09/2026) : la sélection ne repose PLUS sur la
                    // POSITION/le COMPTE (un array_slice sur l'ordre de POSITION pouvait supprimer
                    // une photo ajoutée à la main et promue en tête) mais sur la PROVENANCE — voir
                    // isExistingMediaCreatedByModule(). Un média Shopify non reconnu comme posé par
                    // le module est un SURPLUS : il n'est JAMAIS supprimé par défaut ici (AC5),
                    // quel qu'en soit le nombre. Seule l'action explicite « forcer le renvoi
                    // complet » (2.5.5) invalide le hash et redéclenche ce même chemin.
                    if ($creationFullySuccessful) {
                        $mediaIdsToDelete = [];
                        $keptMediaIds = [];
                        foreach ($existingMediaBeforeSync as $existingMedia) {
                            if ($this->isExistingMediaCreatedByModule($existingMedia, $knownModuleMediaIds, $dolParentProduct, $currentDolibarrFilenames, count($uniqueImages))) {
                                $mediaIdsToDelete[] = $existingMedia->id;
                            } else {
                                $keptMediaIds[] = $existingMedia->id;
                            }
                        }
                    } else {
                        $mediaIdsToDelete = [];
                        $keptMediaIds = $existingMediaBeforeIds;
                    }

                    // Create full media order: parent images first, then variant images, puis les
                    // médias CONSERVÉS (surplus non supprimé, ou totalité des anciens médias si la
                    // création n'a pas intégralement réussi) — réserve 3 du Validate : le
                    // réordonnancement doit porter sur l'ensemble conservés + nouveaux.
                    $orderedMediaIds = $parentMediaIds;
                    foreach ($variantMediaMap as $shopifyVariantId =>  $mediaIds) {
                        foreach ($mediaIds as $mediaId) {
                            if (!in_array($mediaId, $orderedMediaIds)) {
                                $orderedMediaIds[] = $mediaId;
                            }
                        }
                    }
                    foreach ($keptMediaIds as $keptMediaId) {
                        if (!in_array($keptMediaId, $orderedMediaIds)) {
                            $orderedMediaIds[] = $keptMediaId;
                        }
                    }

                    // 7. Reorder media to ensure parent images come first
                    if (count($orderedMediaIds) > 1) {
                        $this->log("Reordering " . count($orderedMediaIds) . " media for product: " . $shopifyProductId, LOG_INFO);
                        $this->shopifyApi->reorderProductMedia($shopifyProductId, $orderedMediaIds);
                    }

                    // 8. Associate media with variants
                    foreach ($variantMediaMap as $shopifyVariantId => $mediaIds) {
                        $this->log("Associating " . count($mediaIds) . " media with variant: " . $shopifyVariantId, LOG_INFO);
                        foreach ($mediaIds as $mediaId) {
                            $mediaIdClean = str_replace('gid://shopify/MediaImage/', '', $mediaId);
                            $this->shopifyApi->linkImageToVariant(
                                $mediaIdClean,
                                $shopifyProductId,
                                $shopifyVariantId
                            );
                            $this->log("Linked media " . $mediaId . " to variant " . $shopifyVariantId, LOG_DEBUG);
                        }
                    }

                    // 9. Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) :
                    // suppression des anciens médias — MAINTENANT SEULEMENT, après vérification
                    // complète de la création. AUCUNE suppression sur échec (géré par le bloc
                    // $creationFullySuccessful === false ci-dessous, après la sortie de ce bloc).
                    //
                    // HIGH (re-review 27/09/2026) : $newKnownModuleMediaIds ne contient QUE ce que
                    // le module vient RÉELLEMENT de créer ce cycle ($allMediaIds, même en succès
                    // partiel) plus ce qui était déjà connu explicitement — JAMAIS un média reconnu
                    // seulement par heuristique (b)/(c). L'auto-guérison de la première version de
                    // ce correctif est SUPPRIMÉE : elle promouvait en provenance explicite tout
                    // média reconnu par heuristique et survivant à un échec partiel de suppression,
                    // ce qui aurait fini par figer un faux positif d'alt en vérité définitive.
                    $newKnownModuleMediaIds = array_merge($knownModuleMediaIds, $allMediaIds);

                    if ($creationFullySuccessful && !empty($mediaIdsToDelete)) {
                        $this->log("🗑️ Deleting " . count($mediaIdsToDelete) . " module-owned media (of "
                            . count($existingMediaBeforeIds) . " existing before sync, provenance-based selection) for product " . $shopifyProductId, LOG_DEBUG);
                        $deletionResult = $this->deleteSpecificProductMedia($mediaIdsToDelete, $shopifyProductId);
                        if (!$deletionResult['success']) {
                            // MEDIUM (review 3 couches 27/09/2026) : un échec de suppression APRÈS
                            // création réussie n'est plus seulement un WARNING silencieux — il est
                            // compté pour le résumé de cycle. Jamais de perte de photo dans ce cas
                            // (le remplaçant existe déjà), au pire un doublon temporaire.
                            $this->imagesDeleteFailedCount++;
                            if (count($this->imagesDeleteFailedRefs) < 20) {
                                $this->imagesDeleteFailedRefs[] = (string) $dolParentProduct->ref;
                            }
                            $this->log("[WARNING] Failed to delete some replaced media for product: " . $shopifyProductId
                                . ". Old media may remain alongside the new ones — no data was lost.", LOG_WARNING);
                        }

                        // HIGH (re-review 27/09/2026) : retire des identifiants connus tout ce qui
                        // a été EFFECTIVEMENT supprimé — hygiène simple, PAS une promotion. Les
                        // médias reconnus par (b)/(c) qui survivent (échec partiel de suppression)
                        // NE SONT PLUS ajoutés à $newKnownModuleMediaIds : ils resteront reconnus
                        // par heuristique au prochain cycle, jamais par provenance explicite.
                        $newKnownModuleMediaIds = array_diff($newKnownModuleMediaIds, $deletionResult['deletedIds']);

                        if (!empty($keptMediaIds)) {
                            $this->log("ImportProducts::syncProductAllImages - " . count($keptMediaIds)
                                . " média(s) Shopify surplus préservé(s) (non appariés, jamais supprimés par défaut) pour le produit "
                                . $dolParentProduct->ref, LOG_INFO);
                        }
                    }

                    // HIGH : persistance INCONDITIONNELLE (succès complet, partiel, ou échec de
                    // création) — tout média que le module vient de créer doit être reconnu comme
                    // « à nous » dès ce cycle, y compris quand la création n'est que partielle.
                    $this->saveKnownModuleMediaIds($dolibarrProductId, (int) $this->entity, $newKnownModuleMediaIds, $compositeHash);
                } else {
                    $this->log("No media created in Shopify response", LOG_WARNING);
                    if (!empty($mediaUserErrors)) {
                        $this->log("Media creation errors: " . json_encode($mediaUserErrors), LOG_ERR);
                    }
                }
            }

            if (!$creationFullySuccessful) {
                // AC1 : échec partiel ou total de la création — AUCUNE suppression (déjà garanti
                // ci-dessus, les anciens médias n'ont jamais été touchés), échec journalisé de
                // façon ACTIONNABLE et compté pour le résumé de cycle, empreinte NON mise à jour
                // (bloc ci-dessous) pour permettre une nouvelle tentative au cycle suivant.
                $this->imagesCreationFailedCount++;
                $creationFailureAlreadyCounted = true;
                if (count($this->imagesCreationFailedRefs) < 20) {
                    $this->imagesCreationFailedRefs[] = (string) $dolParentProduct->ref;
                }
                $this->log("ImportProducts::syncProductAllImages - ÉCHEC de création des médias pour le produit "
                    . $dolParentProduct->ref . " (" . count($uploadedImages) . "/" . count($uniqueImages)
                    . " téléversées) : anciens médias Shopify PRÉSERVÉS (aucune suppression), nouvelle tentative"
                    . " au prochain cycle. Voir logs ci-dessus pour le détail (userErrors/statut FAILED/échec de téléversement).", LOG_ERR);
            }

            // Story 8.2: Update composite hash only if ALL unique images were successfully uploaded
            // This ensures partial failures trigger a full re-sync on next run
            // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : la condition
            // se réduit désormais à $creationFullySuccessful, qui couvre STRICTEMENT le même cas
            // (count($uploadedImages) === count($uniqueImages)) ET, en plus, l'absence de
            // userErrors/statut FAILED sur les médias créés — condition plus stricte, jamais plus
            // permissive.
            if ($creationFullySuccessful) {
                $hashImageCount = count($allImages) - $dedupFailedCount;
                $this->updateStoredCompositeHash((int)$dolParentProduct->id, (int)$this->entity, $compositeHash, $hashImageCount);
            }
            // else : empreinte volontairement NON mise à jour — voir le LOG_ERR actionnable et
            // l'incrémentation de $imagesCreationFailedCount ci-dessus (échec de création).

            $this->db->commit();
            $this->log("ImportProducts::syncProductAllImages - Image synchronization completed for product: " . $dolParentProduct->ref, LOG_INFO);
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            $this->log("ImportProducts::syncProductAllImages - Error syncing product images: " . $e->getMessage(), LOG_ERR);
            if ($reachedImageUploadPhase) {
                $this->log("ImportProducts::syncProductAllImages - ÉCHEC de création des médias pour le produit "
                    . $dolParentProduct->ref . " (Exception après le snapshot) : anciens médias Shopify PRÉSERVÉS"
                    . " (aucune suppression n'a pu avoir lieu), nouvelle tentative au prochain cycle.", LOG_ERR);
            }
            // Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : une Exception
            // survenue APRÈS le snapshot (ex. createStagedUploads() en échec total, throw ligne
            // plus haut) est un échec de création comme un autre — aucune suppression n'a pu
            // avoir lieu (le snapshot ne fait que lire), les anciens médias restent intacts, et
            // ce cycle doit être compté/retenté comme les échecs partiels ci-dessus. Les
            // abandons PRÉCOCES (mapping absent, pré-check images) ont leurs propres `return
            // false` avant que $reachedImageUploadPhase ne passe à true et ne sont jamais comptés
            // ici, pour ne pas les confondre avec un vrai échec de création.
            if ($reachedImageUploadPhase && !$creationFailureAlreadyCounted) {
                $this->imagesCreationFailedCount++;
                if (count($this->imagesCreationFailedRefs) < 20) {
                    $this->imagesCreationFailedRefs[] = (string) $dolParentProduct->ref;
                }
            }
            return false;
        }
    }

    /**
     * Delete ALL existing images of a Shopify product.
     *
     * ⚠️ CONSERVÉE pour compatibilité mais N'EST PLUS APPELÉE par syncProductAllImages() depuis
     * la Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : elle listait ET
     * supprimait la TOTALITÉ des médias, y compris un surplus non apparié (ajout manuel, vidéo),
     * et le faisait AVANT toute création de remplaçant — exactement la fenêtre destructive visée
     * par cette story. syncProductAllImages() calcule désormais lui-même la liste EXACTE à
     * supprimer (les anciens médias effectivement remplacés, jamais plus) via
     * deleteSpecificProductMedia() ci-dessous, uniquement APRÈS vérification de la création.
     *
     * @param string $shopifyProductId Shopify product ID
     * @return bool Success status
     */
    private function deleteExistingProductImages($shopifyProductId)
    {
        $this->log("Starting deletion of existing images for product: " . $shopifyProductId, LOG_DEBUG);

        // 1. Retrieve all existing images (with pagination)
        $shopifyImages = $this->shopifyApi->getProductImages($shopifyProductId);

        if (empty($shopifyImages)) {
            $this->log("No existing images found for product: " . $shopifyProductId, LOG_INFO);
            return true;
        }

        $this->log("Found " . count($shopifyImages) . " existing images for product: " . $shopifyProductId, LOG_INFO);

        $imagesIds = [];
        foreach ($shopifyImages as $image) {
            $imagesIds[] = $image->id;
        }

        return $this->deleteSpecificProductMedia($imagesIds, $shopifyProductId)['success'];
    }

    /**
     * Delete an EXPLICIT, pre-computed set of media IDs for a Shopify product, in batches.
     *
     * Story apparier-les-images-une-a-une-au-lieu-de-tout-detruire (AC1) : extrait de l'ancien
     * deleteExistingProductImages() qui listait ET supprimait TOUT à chaque appel. Cette méthode
     * ne prend plus AUCUNE décision — l'appelant (syncProductAllImages()) a déjà déterminé quels
     * médias ont été effectivement remplacés et lesquels doivent rester (surplus non apparié,
     * échec de création). Elle se contente d'exécuter la suppression, par lots de 50, avec le
     * même relevé successCount/errorCount que l'implémentation d'origine.
     *
     * @param array  $mediaIds         IDs Shopify (gid://shopify/MediaImage/...) à supprimer
     * @param string $shopifyProductId Shopify product ID
     * @return array{success: bool, deletedIds: string[], errorCount: int} `success` : true si
     *         aucun lot n'a échoué (ou si $mediaIds était vide). `deletedIds` : les IDs
     *         EFFECTIVEMENT confirmés supprimés par Shopify (peut être un sous-ensemble de
     *         $mediaIds en cas d'échec partiel) — utilisé par l'appelant pour mettre à jour la
     *         provenance persistée (HIGH, review 3 couches 27/09/2026).
     */
    private function deleteSpecificProductMedia(array $mediaIds, $shopifyProductId)
    {
        if (empty($mediaIds)) {
            return array('success' => true, 'deletedIds' => [], 'errorCount' => 0);
        }

        $this->db->begin();

        try {
            // Delete images in batches to avoid API limitations
            $batchSize = 50; // Reasonable batch size
            $batches = array_chunk($mediaIds, $batchSize);

            $successCount = 0;
            $errorCount = 0;
            $deletedIds = [];

            foreach ($batches as $index => $batch) {
                $this->log("Processing batch " . ($index + 1) . "/" . count($batches) .
                    " (" . count($batch) . " images)", LOG_DEBUG);

                try {
                    $deleteResponse = $this->shopifyApi->deleteProductImages($batch, $shopifyProductId);

                    // Check the response to ensure the deletion was successful
                    if (
                        isset($deleteResponse->data) &&
                        isset($deleteResponse->data->productDeleteMedia) &&
                        !empty($deleteResponse->data->productDeleteMedia->deletedMediaIds)
                    ) {

                        $batchDeletedIds = $deleteResponse->data->productDeleteMedia->deletedMediaIds;
                        $deletedIds = array_merge($deletedIds, $batchDeletedIds);
                        $deletedCount = count($batchDeletedIds);
                        $successCount += $deletedCount;
                        $this->log("Successfully deleted " . $deletedCount . " images in batch " .
                            ($index + 1), LOG_INFO);
                    } elseif (isset($deleteResponse->errors)) {
                        $this->log("Error deleting batch " . ($index + 1) . ": " .
                            json_encode($deleteResponse->errors), LOG_ERR);
                        $errorCount++;
                    } elseif (
                        isset($deleteResponse->data) &&
                        isset($deleteResponse->data->productDeleteMedia) &&
                        !empty($deleteResponse->data->productDeleteMedia->userErrors)
                    ) {

                        $this->log("User errors during deletion of batch " . ($index + 1) . ": " .
                            json_encode($deleteResponse->data->productDeleteMedia->userErrors), LOG_ERR);
                        $errorCount++;
                    } else {
                        $this->log("Unexpected result during deletion of batch " . ($index + 1) . ": " .
                            json_encode($deleteResponse), LOG_WARNING);
                        $errorCount++;
                    }

                    // Small pause between batches to avoid overloading the API
                    if ($index < count($batches) - 1) {
                        usleep(500000); // 500ms
                    }
                } catch (Exception $e) {
                    $this->log("Exception during deletion of batch " . ($index + 1) . ": " .
                        $e->getMessage(), LOG_ERR);
                    $errorCount++;
                }
            }

            $this->log("Deletion completed: " . $successCount . " images deleted, " .
                $errorCount . " lots avec erreurs", LOG_INFO);

            $this->db->commit();
            // If all deletions succeeded or no images were found
            return array('success' => ($errorCount == 0), 'deletedIds' => $deletedIds, 'errorCount' => $errorCount);
        } catch (Exception $e) {
            $this->db->rollback();
            $this->log("Exception during deletion of images: " . $e->getMessage(), LOG_ERR);
            return array('success' => false, 'deletedIds' => [], 'errorCount' => count($mediaIds));
        }
    }

    /**
     * Wait for media to be ready — attend le statut FINAL (asynchrone) des médias créés.
     *
     * ⚠️ CRITICAL (review 3 couches 27/09/2026, story
     * apparier-les-images-une-a-une-au-lieu-de-tout-detruire) : le statut IMMÉDIAT renvoyé par
     * `productCreateMedia` (souvent `PROCESSING`/`UPLOADED`) ne prouve RIEN sur l'issue réelle du
     * traitement Shopify. Un média peut basculer `FAILED` de façon purement asynchrone, APRÈS la
     * réponse de création — c'est CE polling, et lui seul, qui observe le statut qui fait foi.
     * L'appelant (`syncProductAllImages()`) DOIT désormais conditionner la suppression des
     * anciens médias ET l'écriture de l'empreinte composite sur `allReady === true` ici, jamais
     * sur le statut immédiat de la réponse de création.
     *
     * @param string $shopifyProductId Shopify product ID
     * @param array  $mediaIds         Array of media IDs to check
     * @param int    $maxAttempts      Maximum number of attempts (default: 10)
     * @param int    $sleepSeconds     Seconds to sleep between attempts (default: 2)
     * @return array{allReady: bool, anyFailed: bool, timedOut: bool, statuses: array<string,string>}
     *         `allReady` : true seulement si TOUS les médias demandés ont atteint READY.
     *         `anyFailed` : true si au moins un média a atteint le statut terminal FAILED (dans ce
     *         cas `allReady` est toujours false, et on n'attend pas les tentatives restantes — un
     *         média FAILED ne redeviendra jamais READY).
     *         `timedOut` : true si `maxAttempts` a été épuisé sans qu'aucun média n'ait basculé
     *         FAILED, mais sans que tous n'aient atteint READY non plus (encore PROCESSING/UPLOADED).
     *         `statuses` : dernier statut observé par média demandé (id, gid complet).
     *
     * ⚠️ Re-review 27/09/2026 : interroge désormais `ShopifyApi::getMediaStatusByIds()` (champ
     * racine `nodes(ids:)`, fragment MediaImage) au lieu de `checkMediaStatus()`
     * (`product.media(first:50)`, sans pagination). Deux défauts corrigés d'un coup :
     *  - un produit à plus de 50 médias pouvait laisser un média demandé HORS de la première page,
     *    jamais vu par le polling ;
     *  - PLUS GRAVE : un média demandé mais ABSENT de la réponse (page tronquée, suppression
     *    concurrente...) ne faisait basculer AUCUN indicateur à false — `$allReady` restait à
     *    `true` par défaut puisque la boucle ne trouvait simplement rien à contredire pour cet
     *    identifiant. `nodes(ids:)` cible EXACTEMENT les identifiants demandés ; un identifiant
     *    absent de la réponse (`null`) est maintenant traité comme NON PRÊT, jamais comme prêt par
     *    défaut.
     */
    private function waitForMediaToBeReady($shopifyProductId, $mediaIds, $maxAttempts = 10, $sleepSeconds = 2)
    {
        $this->log("Waiting for media to be ready for product ID: " . $shopifyProductId, LOG_DEBUG);

        $lastKnownStatuses = [];

        // Try up to maxAttempts times
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->shopifyApi->getMediaStatusByIds($mediaIds);

            if (empty($response) || empty($response->data) || !isset($response->data->nodes) || !is_array($response->data->nodes)) {
                $this->log("Media status check failed on attempt " . $attempt, LOG_WARNING);
                sleep($sleepSeconds);
                continue;
            }

            // Index par identifiant retourné — ne suppose PAS que l'ordre de la réponse suit
            // l'ordre des IDs demandés (documenté ainsi par Shopify, mais une correspondance par
            // id est plus robuste et rend le "absent" trivial à détecter : simplement pas de clé).
            // Factorisé (re-review 27/09/2026, point 1c) : réutilisé aussi par la vérification des
            // médias en attente d'un cycle précédent, cf. indexMediaStatusResponseById().
            $byId = $this->indexMediaStatusResponseById($response);

            $allReady = true;
            $anyFailed = false;
            $readyCount = 0;

            foreach ($mediaIds as $mediaId) {
                $status = isset($byId[$mediaId]) ? $byId[$mediaId] : null;

                if ($status === null) {
                    // CRITIQUE (re-review 27/09/2026) : un identifiant demandé mais ABSENT de la
                    // réponse ne doit JAMAIS être traité comme prêt par défaut.
                    $allReady = false;
                    $lastKnownStatuses[$mediaId] = 'MISSING_FROM_RESPONSE';
                    $this->log("Media " . $mediaId . " absent de la réponse nodes() sur la tentative " . $attempt, LOG_WARNING);
                    continue;
                }

                $lastKnownStatuses[$mediaId] = $status;

                if ($status === 'FAILED') {
                    $anyFailed = true;
                    $allReady = false;
                    $this->log("Media " . $mediaId . " status: FAILED (terminal, asynchrone)", LOG_ERR);
                } elseif ($status !== 'READY') {
                    $allReady = false;
                    $this->log("Media " . $mediaId . " status: " . $status . " (not ready)", LOG_DEBUG);
                } else {
                    $readyCount++;
                    $this->log("Media " . $mediaId . " is ready", LOG_DEBUG);
                }
            }

            $this->log("Media ready: " . $readyCount . "/" . count($mediaIds) . " on attempt " . $attempt, LOG_DEBUG);

            if ($anyFailed) {
                // Terminal : un média FAILED ne redeviendra jamais READY, inutile d'attendre les
                // tentatives restantes.
                $this->log("Aborting poll after " . $attempt . " attempt(s): at least one media reached FAILED", LOG_ERR);
                return array('allReady' => false, 'anyFailed' => true, 'timedOut' => false, 'statuses' => $lastKnownStatuses);
            }

            if ($allReady) {
                $this->log("All media are ready after " . $attempt . " attempts", LOG_INFO);
                return array('allReady' => true, 'anyFailed' => false, 'timedOut' => false, 'statuses' => $lastKnownStatuses);
            }

            // Sleep before next attempt
            sleep($sleepSeconds);
        }

        $this->log("Not all media became ready after " . $maxAttempts . " attempts", LOG_WARNING);
        return array('allReady' => false, 'anyFailed' => false, 'timedOut' => true, 'statuses' => $lastKnownStatuses);
    }

    /**
     * Wait for a collection file to be ready and return its image URL
     *
     * @param string $fileId File ID (gid://shopify/MediaImage/XXX)
     * @param int $maxAttempts Maximum number of attempts
     * @param int $sleepSeconds Seconds to wait between attempts
     * @return string|false Image URL if ready, false if timeout
     * @version 2.0.33
     * @since 2.0.33
     */
    private function waitForFileReady($fileId, $maxAttempts = 10, $sleepSeconds = 2)
    {
        $this->log("Waiting for file to be ready: " . $fileId, LOG_DEBUG);

        // Try up to maxAttempts times
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Check file status
            $fileStatus = $this->shopifyApi->getFileStatus($fileId);

            if (empty($fileStatus) || empty($fileStatus->data) || empty($fileStatus->data->node)) {
                $this->log("File status check failed on attempt " . $attempt . " for " . $fileId, LOG_WARNING);
                sleep($sleepSeconds);
                continue;
            }

            $fileData = $fileStatus->data->node;

            // Log current status for debugging
            $currentStatus = $fileData->fileStatus ?? 'UNKNOWN';
            $this->log("File " . $fileId . " status: " . $currentStatus . " on attempt " . $attempt, LOG_DEBUG);

            // Check if file is ready
            if ($currentStatus === 'READY' && !empty($fileData->image) && !empty($fileData->image->url)) {
                $imageUrl = $fileData->image->url;
                $this->log("File became ready after " . $attempt . " attempts: " . $imageUrl, LOG_INFO);
                return $imageUrl;
            }

            // If not ready yet, sleep and retry
            if ($attempt < $maxAttempts) {
                $this->log("File not ready yet, waiting " . $sleepSeconds . " seconds before retry...", LOG_DEBUG);
                sleep($sleepSeconds);
            }
        }

        $this->log("File did not become ready after " . $maxAttempts . " attempts: " . $fileId, LOG_WARNING);
        return false;
    }

    /**
     * Réinitialiser l'ID Shopify d'un produit invalide
     * Auto-nettoyage lorsqu'un produit n'existe plus dans Shopify
     *
     * @param string $shopifyProductId L'ID Shopify du produit (format: gid://shopify/Product/XXX)
     * @return bool True si réussi, false sinon
     */
    private function resetInvalidShopifyProductId($shopifyProductId)
    {
        global $db;

        $this->log("Starting auto-cleanup for invalid Shopify product ID: " . $shopifyProductId, LOG_INFO);

        // Extraire l'ID numérique si nécessaire
        $numericId = $shopifyProductId;
        $fullGid = $shopifyProductId;

        if (strpos($shopifyProductId, 'gid://shopify/Product/') !== false) {
            $numericId = str_replace('gid://shopify/Product/', '', $shopifyProductId);
        } elseif (is_numeric($shopifyProductId)) {
            // Si on a reçu juste l'ID numérique, reconstruire le GID complet
            $fullGid = 'gid://shopify/Product/' . $shopifyProductId;
        }

        // Vérifier si l'ID n'est pas anormalement bas (possible confusion avec ID Dolibarr)
        if (is_numeric($numericId) && intval($numericId) < 100) {
            $this->log("WARNING: Very low Shopify ID detected (" . $numericId . ") - this might be a configuration error", LOG_WARNING);
            $this->log("If these are Dolibarr IDs stored as Shopify IDs, manual correction is needed", LOG_WARNING);
        }

        // Chercher le produit dans la table de mapping
        $sql = "SELECT fk_product, shopifyProductId FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE (shopifyProductId = '" . $db->escape($fullGid) . "'";
        $sql .= " OR shopifyProductId = '" . $db->escape($numericId) . "')";
        $sql .= " AND entity = " . $this->entity;

        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("Error searching for product to cleanup: " . $db->lasterror(), LOG_ERR);
            return false;
        }

        $obj = $db->fetch_object($resql);
        if (!$obj) {
            $this->log("Product not found in mapping table for cleanup", LOG_WARNING);
            return false;
        }

        $dolibarrProductId = $obj->fk_product;

        // Log plus d'informations pour le diagnostic
        $this->log("Found mapping: Dolibarr Product ID " . $dolibarrProductId . " => Shopify ID " . $obj->shopifyProductId, LOG_INFO);

        // Réinitialiser l'ID Shopify dans la table de mapping
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " SET shopifyProductId = NULL, last_sync_status = NULL, last_sync_error = NULL";
        $sql .= " WHERE fk_product = " . intval($dolibarrProductId);
        $sql .= " AND entity = " . $this->entity;

        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("Error resetting Shopify product ID: " . $db->lasterror(), LOG_ERR);
            return false;
        }

        // Optionnel : marquer le produit pour resynchronisation
        $sql = "UPDATE " . MAIN_DB_PREFIX . "product";
        $sql .= " SET tms = NOW()";
        $sql .= " WHERE rowid = " . intval($dolibarrProductId);

        $resql = $db->query($sql);
        if (!$resql) {
            $this->log("Error updating product timestamp: " . $db->lasterror(), LOG_WARNING);
            // Non critique, on continue
        }

        $this->log("Successfully reset Shopify ID for Dolibarr product " . $dolibarrProductId, LOG_INFO);
        $this->log("Product will be recreated in Shopify on next sync", LOG_INFO);

        return true;
    }

    /**
     * Factorized method to get product variants for a given parent product
     * Used by both importProducts() and importProductsManual()
     *
     * @param int $parentProductId Parent product ID
     * @return array Array of Product objects (variants)
     */
    private function getProductVariants($parentProductId)
    {
        $variants = [];
        
        // Use same SQL query as in importProducts() to ensure consistency
        $sql_variants = "SELECT DISTINCT p.*
                FROM " . MAIN_DB_PREFIX . "product p
                INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON p.rowid = pac.fk_product_child
                WHERE pac.fk_product_parent = ?";

        $variantResult = SqlUtils::executeQuery($this->db, $sql_variants, "getting variant products", true, [(int)$parentProductId]);
        
        if ($variantResult) {
            while ($variantData = $this->db->fetch_object($variantResult)) {
                if (!empty($variantData->rowid)) {
                    $this->log("Processing variant: " . $variantData->ref . " (ID: " . $variantData->rowid . ", tosell: " . $variantData->tosell . ")", LOG_DEBUG);

                    // Always use official Product object for variants
                    $variantProduct = new Product($this->db);
                    $fetchResult = $variantProduct->fetch($variantData->rowid);

                    if ($fetchResult > 0) {
                        $this->log("Variant fetched successfully - ID: " . $variantProduct->id . ", status: " . $variantProduct->status . ", ref: " . $variantProduct->ref, LOG_DEBUG);
                        $variants[] = $variantProduct;
                    } else {
                        $this->log("Failed to fetch variant ID: " . $variantData->rowid, LOG_ERR);
                    }
                } else {
                    $this->log("Variant has no rowid set: " . $variantData->ref, LOG_WARNING);
                }
            }
            $this->db->free($variantResult);
        }
        
        return $variants;
    }

    /**
     * Manual product synchronization with transaction handling and factorized logic
     *
     * @param array $productIds Array of product IDs to synchronize
     * @return int Number of products synchronized, -1 on error
     */
    public function importProductsManual($productIds = [])
    {
        $this->log("Starting manual product import using unified logic with transactions", LOG_INFO);
        // Task 2.5 : vider le cache d'images en début de cycle batch
        // (évite contamination inter-produits si la même instance est réutilisée)
        $this->clearImagesCache();

        if (empty($productIds)) {
            $this->log("No product IDs provided for manual synchronization", LOG_WARNING);
            return 0;
        }
        
        $this->log("Manual sync for " . count($productIds) . " product(s): " . implode(', ', $productIds), LOG_INFO);
        
        $numSynced = 0;
        $processedProducts = [];
        
        // Begin database transaction
        $this->db->begin();
        
        try {
            // Step 1: Process each parent product and its variants
            foreach ($productIds as $productId) {
                // Load parent product
                $parentProduct = new Product($this->db);
                $result = $parentProduct->fetch($productId);
                
                if ($result <= 0) {
                    $this->log("Failed to load product ID: " . $productId, LOG_ERR);
                    continue;
                }
                
                $this->log("Processing parent product: " . $parentProduct->ref . " (ID: " . $productId . ")", LOG_INFO);
                $this->log("DEBUG - Parent product type: " . ($parentProduct->type ?? 'undefined') . " (0=product, 1=service)", LOG_DEBUG);
                
                // Get variants using factorized method
                $variants = $this->getProductVariants($productId);
                $this->log("Found " . count($variants) . " variants for product " . $parentProduct->ref, LOG_DEBUG);
                
                // Synchronize the product (parent + variants)
                $syncResult = $this->syncProduct($parentProduct, $variants, '', true);
                if ($syncResult) {
                    $numSynced++;
                    $this->log("Successfully synchronized product: " . $parentProduct->ref, LOG_INFO);
                } else {
                    $this->log("Failed to synchronize product: " . $parentProduct->ref, LOG_WARNING);
                }

                // CRITICAL FIX v2.0.33: Always add product to processedProducts for image synchronization
                // Even if syncProduct returns true without changes (skipped products)
                // This ensures images are synchronized independently of product data changes
                $processedProducts[] = [
                    'product' => $parentProduct,
                    'variants' => $variants
                ];
            }
            
            // Story 53-5 (site 3b, 2/2) : gate images export via SyncFlowPolicy (remplace la
            // lecture directe de $this->config->sync_product_images)
            // Step 2: Synchronize images for all successfully processed products (if enabled)
            if ($this->isExportFlowAllowed(SyncFlowPolicy::FLOW_IMAGES)) {
                $this->log("Starting image synchronization for " . count($processedProducts) . " products", LOG_INFO);
                
                foreach ($processedProducts as $parentProductData) {
                    try {
                        if (!empty($parentProductData['variants'])) {
                            $this->syncProductAllImages($parentProductData['product'], $parentProductData['variants']);
                        } else {
                            $this->syncProductAllImages($parentProductData['product']);
                        }
                        $this->log("Images synchronized for product: " . $parentProductData['product']->ref, LOG_DEBUG);
                    } catch (Exception $e) {
                        $this->log("Error syncing images for product " . $parentProductData['product']->ref . ": " . $e->getMessage(), LOG_ERR);
                        // Continue with next product instead of failing entire image sync
                    }
                }
            } else {
                $this->log("Image synchronization is disabled. Skipping all product images.", LOG_INFO);
            }

            // Batch update collections with all pending product assignments (v2.0.33)
            // This was missing in manual sync but present in CRON sync (line 356-360)
            if (!empty($this->pendingCollectionAssignments)) {
                $this->log("Updating collections with batched products...", LOG_INFO);
                $this->batchUpdateCollections();
            }

            // Commit transaction if we reach here
            $this->db->commit();
            $this->log("Manual import transaction committed successfully", LOG_INFO);
            
        } catch (Exception $e) {
            // Rollback transaction on any error
            $this->db->rollback();
            $this->log("Exception during manual import, transaction rolled back: " . $e->getMessage(), LOG_ERR);
            $this->error = "Exception during manual synchronization: " . $e->getMessage();
            return -1;
        }
        
        $this->log("Manual import completed: " . $numSynced . "/" . count($productIds) . " products synchronized", LOG_INFO);
        return $numSynced;
    }


    /**
     * Get Shopify variant ID from mapping table
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @return string|null Shopify variant ID or null if not found
     */
    private function getShopifyVariantId($dolibarrProductId)
    {
        $sql = "SELECT shopifyVariantId FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE fk_product = " . (int)$dolibarrProductId;
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " AND shopifyVariantId IS NOT NULL AND shopifyVariantId != ''";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("Error querying Shopify variant ID for Dolibarr product " . $dolibarrProductId . ": " . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        if ($this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return $obj->shopifyVariantId;
        }

        $this->db->free($resql);
        return null;
    }

    /**
     * Build variant data for Shopify API
     *
     * @param Product $variantProduct The variant product
     * @param array $fieldsToUpdate Fields to update
     * @param string $shopifyImageUrl Image URL
     * @return array Variant data array
     */
    private function buildVariantData($variantProduct, $fieldsToUpdate = [], $shopifyImageUrl = '')
    {
        // Base variant data
        $variantData = [
            'sku' => $variantProduct->ref,
            'price' => number_format($variantProduct->price_ttc ?: $variantProduct->price, 2, '.', ''),
            'inventoryManagement' => 'shopify',
            'inventoryPolicy' => 'deny',  // Empêcher les ventes avec stock négatif
        ];

        // Gestion du stock
        if ($this->config->stock_method == 'stock_theorique') {
            $stock = $variantProduct->getStockTheoretic();
        } else {
            $stock = $variantProduct->stock_reel ?: 0;
        }
        $variantData['inventoryQuantity'] = max(0, (int)$stock);

        // Titre du variant (peut être différent du produit parent)
        if (!empty($variantProduct->label)) {
            $variantData['title'] = $variantProduct->label;
        }

        // Poids si défini
        if (!empty($variantProduct->weight)) {
            $variantData['weight'] = floatval($variantProduct->weight);
            $variantData['weightUnit'] = 'kg';  // Dolibarr utilise kg par défaut
        }

        $this->log("Built variant data for " . $variantProduct->ref . ": " . json_encode($variantData), LOG_DEBUG);
        return $variantData;
    }

    /**
     * Update variant mapping in database
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param string $shopifyProductId Shopify parent product ID
     * @param string $shopifyVariantId Shopify variant ID
     * @return bool Success
     */
    private function updateVariantMapping($dolibarrProductId, $shopifyProductId, $shopifyVariantId)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " (fk_product, shopifyProductId, shopifyVariantId, entity, datec, tms)";
        $sql .= " VALUES (?, ?, ?, ?, NOW(), NOW())";
        $sql .= " ON DUPLICATE KEY UPDATE";
        $sql .= " shopifyProductId = VALUES(shopifyProductId),";
        $sql .= " shopifyVariantId = VALUES(shopifyVariantId),";
        $sql .= " tms = NOW()";

        $result = SqlUtils::executeQuery(
            $this->db,
            $sql,
            "updating variant mapping",
            [$dolibarrProductId, $shopifyProductId, $shopifyVariantId, $this->entity]
        );

        if (!$result) {
            $this->log("Failed to update variant mapping for product " . $dolibarrProductId, LOG_ERR);
            return false;
        }

        $this->log("Updated variant mapping: Dolibarr " . $dolibarrProductId . " → Shopify variant " . $shopifyVariantId, LOG_INFO);
        return true;
    }

    /**
     * Check if a product is a variant (child product) of another product
     *
     * @param int $productId Dolibarr product ID
     * @return bool True if the product is a variant (has a parent), false if it's a parent or standalone product
     */
    private function isProductVariant($productId)
    {
        // Check if this product is referenced as a child in product_attribute_combination
        $sql = "SELECT COUNT(*) as count 
                FROM " . MAIN_DB_PREFIX . "product_attribute_combination 
                WHERE fk_product_child = ?";
        
        $result = SqlUtils::executeQuery($this->db, $sql, "checking if product is variant", true, [(int)$productId]);
        
        if ($result) {
            $row = $this->db->fetch_object($result);
            $this->db->free($result);
            
            $isVariant = ($row->count > 0);
            $this->log("Product ID " . $productId . " variant check: " . ($isVariant ? "IS a variant" : "NOT a variant"), LOG_DEBUG);
            
            return $isVariant;
        }
        
        $this->log("Failed to check variant status for product ID " . $productId, LOG_WARNING);
        return false;
    }

    // ==========================================================
    // COLLECTIONS MANAGEMENT - Added in v2.0.26
    // ==========================================================

    /**
     * Get product categories from Dolibarr
     *
     * @param Product $dolProduct Dolibarr product object
     * @return array Array of categories with id, label, and parent_id
     * @since 2.0.26
     */
    private function getProductCategories($dolProduct)
    {
        $categories = [];

        // Story 57-7 (Task 3) : LEFT JOIN ecm_files SUPPRIMÉ. Ses colonnes (image_filename/
        // image_filepath) étaient mortes en aval (0 usage, cf. getOrCreateShopifyCollection())
        // — Categorie::add_photo() n'indexe JAMAIS dans ecm_files (vérifié : 0 résultat sur
        // `grep addFileIntoDatabaseIndex categories/`), donc ce JOIN ne ramenait déjà rien en
        // pratique. Le retrait supprime aussi un risque de LIGNES DUPLIQUÉES : ce JOIN n'avait
        // ni LIMIT ni agrégat, donc une catégorie à N images sur ecm_files (backfill manuel via
        // ecm/index_auto.php par exemple) aurait fait sortir la même catégorie N fois.
        $sql = "SELECT c.rowid, c.label, c.description, c.fk_parent
                FROM " . MAIN_DB_PREFIX . "categorie c
                INNER JOIN " . MAIN_DB_PREFIX . "categorie_product cp ON c.rowid = cp.fk_categorie
                WHERE cp.fk_product = " . (int)$dolProduct->id . "
                AND c.entity = " . (int)$this->entity . "
                AND c.type = 0";  // Type 0 = product categories

        // Exclude the parent category (dolibarr_procate) from sync - it's only a container
        if (!empty($this->config->dolibarr_procate)) {
            $sql .= " AND c.rowid != " . (int)$this->config->dolibarr_procate;
        }

        try {
            $result = SqlUtils::executeQuery($this->db, $sql, "getting product categories for " . $dolProduct->ref);

            $allCategories = [];
            while ($row = $this->db->fetch_array($result)) {
                $allCategories[] = [
                    'id' => $row['rowid'],
                    'label' => $row['label'],
                    'description' => $row['description'],
                    'parent_id' => $row['fk_parent']
                ];
            }

            $this->db->free($result);

            // FIX Bug #2 v2.1.1: Filter categories to only include those within dolibarr_procate tree
            // This prevents creating collections for categories outside the configured Shopify category
            if (!empty($this->config->dolibarr_procate)) {
                $filteredCategories = [];
                foreach ($allCategories as $category) {
                    if ($this->isCategoryInTree($category['id'], $this->config->dolibarr_procate)) {
                        $filteredCategories[] = $category;
                    } else {
                        $this->log("Excluding category " . $category['label'] . " (ID: " . $category['id'] . ") - not in dolibarr_procate tree (ID: " . $this->config->dolibarr_procate . ")", LOG_DEBUG);
                    }
                }
                $categories = $filteredCategories;
            } else {
                $categories = $allCategories;
            }

            $logMessage = "Found " . count($categories) . " categories for product " . $dolProduct->ref;
            if (!empty($this->config->dolibarr_procate)) {
                $logMessage .= " (filtered from " . count($allCategories) . " total categories - only those in tree ID " . $this->config->dolibarr_procate . ")";
            }
            $this->log($logMessage, LOG_DEBUG);

            // If parent categories option is enabled, add parent categories
            if (!empty($this->config->include_parent_categories) && $this->config->include_parent_categories == 1) {
                $categories = $this->addParentCategories($categories, $dolProduct->ref);
            }

        } catch (Exception $e) {
            $this->log("Error getting product categories for " . $dolProduct->ref . ": " . $e->getMessage(), LOG_ERR);
        }

        return $categories;
    }

    /**
     * Add parent categories to the categories list recursively
     *
     * @param array $categories Current categories array
     * @param string $productRef Product reference for logging
     * @return array Extended categories array with parents
     * @since 2.0.34
     */
    private function addParentCategories($categories, $productRef)
    {
        $this->log("Adding parent categories for product " . $productRef, LOG_DEBUG);

        $allCategories = $categories;
        $processedIds = [];

        // Create a map of existing categories to avoid duplicates
        foreach ($categories as $category) {
            $processedIds[$category['id']] = true;
        }

        // Collect all parent IDs that need to be processed
        $parentIds = [];
        foreach ($categories as $category) {
            if (!empty($category['parent_id']) && !isset($processedIds[$category['parent_id']])) {
                $parentIds[] = (int)$category['parent_id'];
            }
        }

        // Process parent categories level by level
        while (!empty($parentIds)) {
            $currentParentIds = array_unique($parentIds);
            $parentIds = []; // Reset for next level

            if (!empty($currentParentIds)) {
                // Story 57-7 (Task 3) : LEFT JOIN ecm_files supprimé — même raison que
                // getProductCategories() (colonnes mortes + risque de lignes dupliquées).
                $sql = "SELECT c.rowid, c.label, c.description, c.fk_parent
                        FROM " . MAIN_DB_PREFIX . "categorie c
                        WHERE c.rowid IN (" . implode(',', $currentParentIds) . ")
                        AND c.entity = " . (int)$this->entity . "
                        AND c.type = 0";  // Type 0 = product categories

                // Exclude the parent category (dolibarr_procate) from sync
                if (!empty($this->config->dolibarr_procate)) {
                    $sql .= " AND c.rowid != " . (int)$this->config->dolibarr_procate;
                }

                try {
                    $result = SqlUtils::executeQuery($this->db, $sql, "getting parent categories for " . $productRef);

                    while ($row = $this->db->fetch_array($result)) {
                        if (!isset($processedIds[$row['rowid']])) {
                            $parentCategory = [
                                'id' => $row['rowid'],
                                'label' => $row['label'],
                                'description' => $row['description'],
                                'parent_id' => $row['fk_parent']
                            ];

                            $allCategories[] = $parentCategory;
                            $processedIds[$row['rowid']] = true;

                            $this->log("Added parent category: " . $row['label'] . " (ID: " . $row['rowid'] . ")", LOG_DEBUG);

                            // Add the parent's parent to next level if it exists and hasn't been processed
                            if (!empty($row['fk_parent']) && !isset($processedIds[$row['fk_parent']])) {
                                $parentIds[] = (int)$row['fk_parent'];
                            }
                        }
                    }

                    $this->db->free($result);

                } catch (Exception $e) {
                    $this->log("Error getting parent categories for " . $productRef . ": " . $e->getMessage(), LOG_ERR);
                    break; // Stop processing on error
                }
            }
        }

        $addedCount = count($allCategories) - count($categories);
        if ($addedCount > 0) {
            $this->log("Added " . $addedCount . " parent categories for product " . $productRef . " (total: " . count($allCategories) . ")", LOG_INFO);
        }

        return $allCategories;
    }

    /**
     * Get or create Shopify collection from Dolibarr category with mapping support
     *
     * @param int $categoryId Dolibarr category ID
     * @param string $categoryLabel Category name from Dolibarr
     * @return string|null Shopify collection ID (numeric) or null on error
     * @since 2.0.26
     */
    private function getOrCreateShopifyCollection($categoryData)
    {
        // Extract category information
        // Story 57-7 (Task 3) : $categoryImageFilename/$categoryImageFilepath (image_filename/
        // image_filepath) retirés — 0 usage en aval (l'image de collection est désormais
        // résolue par DolibarrDirectFileResolver dans uploadCategoryImageToCollection(), pas
        // depuis les colonnes ecm_files de ce tableau).
        $categoryId = $categoryData['id'];
        $categoryLabel = $categoryData['label'];
        $categoryDescription = $categoryData['description'] ?? '';

        // Check local cache first
        $cacheKey = $categoryId . '_' . $categoryLabel;
        if (isset(self::$collectionsCache[$cacheKey])) {
            $this->log("Using cached collection ID for: " . $categoryLabel, LOG_DEBUG);
            return self::$collectionsCache[$cacheKey];
        }

        try {
            // Check if mapping already exists
            $mapping = $this->collectionsUtils->getMappingByCategoryId($categoryId);
            
            if ($mapping && !empty($mapping['shopify_collection_id'])) {
                // Mapping exists - verify collection still exists in Shopify
                $existing = $this->shopifyApi->getCollectionById($mapping['shopify_collection_id']);
                
                if ($existing && !empty($existing->title)) {
                    $collectionId = $mapping['shopify_collection_id'];
                    $this->log("Using mapped collection: " . $categoryLabel . " (ID: " . $collectionId . ")", LOG_DEBUG);
                    
                    // Update mapping if label changed
                    if ($mapping['dolibarr_category_label'] !== $categoryLabel || 
                        $mapping['shopify_collection_title'] !== $categoryLabel) {
                        $this->collectionsUtils->createOrUpdateMapping(
                            $categoryId, 
                            $categoryLabel, 
                            $collectionId,
                            'gid://shopify/Collection/' . $collectionId,
                            $categoryLabel,
                            // Review externe 51-2 H1 : préserver la direction persistée (une collection
                            // adoptée 'external_managed' ne doit pas redevenir 'dol_to_shop' au premier
                            // changement de label — createOrUpdateMapping force sinon la valeur par défaut)
                            !empty($mapping['sync_direction']) ? $mapping['sync_direction'] : 'dol_to_shop'
                        );
                        $this->log("Updated mapping for changed category label: " . $categoryLabel, LOG_INFO);
                    }

                    // Review externe 51-2 H1 : la garde "collection adoptée pilotée par règles/sources"
                    // doit tenir au-delà du premier run. Le caractère smart est PERSISTÉ dans le mapping
                    // (sync_direction='external_managed', posé au moment de l'adoption par titre) : on
                    // adopte le mapping (tagging produits gardé par ailleurs via isSmartCollection dans
                    // collectionAddProducts) mais on n'écrase JAMAIS description/image ni ne force la
                    // publication d'une collection gérée par le marchand — à CHAQUE run, sans appel API.
                    if (($mapping['sync_direction'] ?? '') === 'external_managed') {
                        $this->log("Mapped collection " . $categoryLabel . " (ID: " . $collectionId . ") is externally managed (rules/sources) — skipping description/image/publish", LOG_DEBUG);
                        self::$collectionsCache[$cacheKey] = $collectionId;
                        return $collectionId;
                    }

                    // Update existing mapped collection with description and image if they have changed
                    $updateData = [];

                    // Add description if available
                    if (!empty($categoryDescription)) {
                        $updateData['descriptionHtml'] = htmlspecialchars($categoryDescription, ENT_QUOTES, 'UTF-8');
                    }

                    // Update collection only if there's description to update
                    if (!empty($updateData)) {
                        $updateData['title'] = $categoryLabel; // Keep the same title
                        $updateResponse = $this->shopifyApi->updateCollection($collectionId, $updateData);

                        if (!empty($updateResponse->data->collectionUpdate->userErrors)) {
                            $this->log("Failed to update mapped collection " . $categoryLabel . ": " . json_encode($updateResponse->data->collectionUpdate->userErrors), LOG_WARNING);
                        } else {
                            $this->log("Updated mapped collection " . $categoryLabel . " with description", LOG_INFO);
                        }
                    }

                    // Handle image upload separately using staged upload process
                    $this->uploadCategoryImageToCollection($collectionId, $categoryId, $categoryLabel);

                    // Publish mapped collection to configured sales channels
                    $collectionGid = 'gid://shopify/Collection/' . $collectionId;
                    $this->publishCollectionToConfiguredChannels($collectionGid, $categoryLabel);

                    // Cache the result
                    self::$collectionsCache[$cacheKey] = $collectionId;
                    return $collectionId;
                } else {
                    // Collection no longer exists in Shopify - mark mapping as deleted
                    $this->collectionsUtils->markAsDeleted($mapping['rowid']);
                    $this->log("Marked mapping as deleted - collection no longer exists: " . $categoryLabel, LOG_WARNING);
                }
            }

            // Search for existing collection by title (fallback)
            $existing = $this->shopifyApi->findCollectionByTitle($categoryLabel);

            if ($existing && !empty($existing->legacyResourceId)) {
                $collectionId = $existing->legacyResourceId;
                $collectionGid = 'gid://shopify/Collection/' . $collectionId;
                
                $this->log("Found existing collection by title: " . $categoryLabel . " (ID: " . $collectionId . ")", LOG_DEBUG);
                
                // Create or update mapping
                $mappingId = $this->collectionsUtils->createOrUpdateMapping(
                    $categoryId, 
                    $categoryLabel, 
                    $collectionId,
                    $collectionGid,
                    $categoryLabel
                );
                
                if ($mappingId) {
                    $this->log("Created/updated mapping for existing collection: " . $categoryLabel, LOG_INFO);
                }

                // Story 51-2 (Code Review MEDIUM) : une collection ADOPTÉE via findCollectionByTitle()
                // (pas créée par le module) peut être pilotée par des règles (ruleSet) ou le nouveau
                // modèle Collection.sources (SMART). Écraser sa descriptionHtml ou forcer sa
                // republication sur les canaux configurés serait une modification métier non voulue
                // d'une collection dont le module ne fait qu'adopter le mapping. Une collection
                // réellement CRÉÉE par le module (branche else ci-dessous, ID venant de
                // collectionCreate()) reste modifiable normalement — pas de garde sur ce chemin.
                // 1 appel API (isSmartCollection) volontairement limité à ce chemin d'adoption par
                // titre, pas ajouté au chemin "déjà mappée" ci-dessus (perf du cas majoritaire).
                $isAdoptedSmartCollection = $this->shopifyApi->isSmartCollection($collectionId);

                if ($isAdoptedSmartCollection) {
                    // Review post-fix (Edge Case Hunter MEDIUM) : l'upload d'image est AUSSI
                    // gardé — uploadCategoryImageToCollection() exécute une mutation
                    // collectionUpdate qui écraserait l'image de la collection adoptée, en
                    // contradiction avec « adoption sans modification ».
                    // Review externe 51-2 H1 : PERSISTER le caractère smart dans le mapping
                    // (sync_direction='external_managed') pour que la garde tienne aux runs
                    // suivants (chemin "déjà mappée"), sans appel API supplémentaire.
                    $this->collectionsUtils->createOrUpdateMapping(
                        $categoryId,
                        $categoryLabel,
                        $collectionId,
                        $collectionGid,
                        $categoryLabel,
                        'external_managed'
                    );
                    $this->log("Collection " . $categoryLabel . " (ID: " . $collectionId . ") pilotée par règles/sources — adoption sans modification (descriptionHtml, image et publication non forcées)", LOG_INFO);
                } else {
                    // Update existing collection with description and image if they have changed
                    $updateData = [];

                    // Add description if available and different
                    if (!empty($categoryDescription)) {
                        $updateData['descriptionHtml'] = htmlspecialchars($categoryDescription, ENT_QUOTES, 'UTF-8');
                    }

                    // Update collection only if there's description to update
                    if (!empty($updateData)) {
                        $updateData['title'] = $categoryLabel; // Keep the same title
                        $updateResponse = $this->shopifyApi->updateCollection($collectionId, $updateData);

                        if (!empty($updateResponse->data->collectionUpdate->userErrors)) {
                            $this->log("Failed to update existing collection " . $categoryLabel . ": " . json_encode($updateResponse->data->collectionUpdate->userErrors), LOG_WARNING);
                        } else {
                            $this->log("Updated existing collection " . $categoryLabel . " with description", LOG_INFO);
                        }
                    }

                    // Handle image upload separately using staged upload process
                    $this->uploadCategoryImageToCollection($collectionId, $categoryId, $categoryLabel);

                    // Publish existing collection to configured sales channels
                    $this->publishCollectionToConfiguredChannels($collectionGid, $categoryLabel);
                }

            } else {
                // Create new collection with enhanced data
                $collectionData = [
                    'title' => $categoryLabel,
                    'sortOrder' => 'BEST_SELLING'
                ];

                // Add description if available
                if (!empty($categoryDescription)) {
                    $collectionData['descriptionHtml'] = htmlspecialchars($categoryDescription, ENT_QUOTES, 'UTF-8');
                }

                $response = $this->shopifyApi->collectionCreate($collectionData);

                if (!empty($response->data->collectionCreate->userErrors)) {
                    $errors = $response->data->collectionCreate->userErrors;
                    $this->log("Error creating collection " . $categoryLabel . ": " . json_encode($errors), LOG_ERR);
                    return null;
                }

                $collection = $response->data->collectionCreate->collection;
                $collectionId = $collection->legacyResourceId;
                $collectionGid = $collection->id;
                
                $this->log("Created new collection: " . $categoryLabel . " (ID: " . $collectionId . ")", LOG_INFO);

                // Create mapping for new collection
                $mappingId = $this->collectionsUtils->createOrUpdateMapping(
                    $categoryId,
                    $categoryLabel,
                    $collectionId,
                    $collectionGid,
                    $categoryLabel
                );

                if ($mappingId) {
                    $this->log("Created mapping for new collection: " . $categoryLabel, LOG_INFO);
                } else {
                    $this->log("Warning: Failed to create mapping for new collection: " . $categoryLabel, LOG_WARNING);
                }

                // Handle image upload separately using staged upload process
                $this->uploadCategoryImageToCollection($collectionId, $categoryId, $categoryLabel);

                // Publish collection to configured sales channels
                $this->publishCollectionToConfiguredChannels($collectionGid, $categoryLabel);
            }

            // Cache the result
            self::$collectionsCache[$cacheKey] = $collectionId;
            return $collectionId;

        } catch (Exception $e) {
            $this->log("Exception in getOrCreateShopifyCollection for " . $categoryLabel . ": " . $e->getMessage(), LOG_ERR);
            return null;
        }
    }

    /**
     * Prepare collections for product based on Dolibarr categories
     *
     * @param Product $dolProduct Dolibarr product object
     * @return array Array of collection GIDs for productSet mutation
     * @since 2.0.26
     */
    private function prepareProductCollections($dolProduct)
    {
        // Story 53-5 (site 3c, 1/3) : gate collections export via SyncFlowPolicy (la dérivation
        // encapsule DÉJÀ le gate SYNC_PRODUCT_COLLECTIONS + le mapping direction)
        if (!$this->isExportFlowAllowed(SyncFlowPolicy::FLOW_COLLECTIONS)) {
            $this->log("Collections synchronization is disabled", LOG_DEBUG);
            return [];
        }

        $categories = $this->getProductCategories($dolProduct);
        $collectionsToJoin = [];

        foreach ($categories as $category) {
            $collectionId = $this->getOrCreateShopifyCollection($category);

            if ($collectionId) {
                $collectionsToJoin[] = 'gid://shopify/Collection/' . $collectionId;
                $this->log("Will add product " . $dolProduct->ref . " to collection: " . $category['label'] . " (ID: " . $collectionId . ")", LOG_DEBUG);
            }
        }

        if (!empty($collectionsToJoin)) {
            $this->log("Product " . $dolProduct->ref . " will be added to " . count($collectionsToJoin) . " collections", LOG_INFO);
        } else {
            $this->log("No collections found or created for product " . $dolProduct->ref, LOG_DEBUG);
        }

        return $collectionsToJoin;
    }


    /**
     * Collect product for collections batch processing (v2.0.26)
     *
     * @param Product $dolProduct Dolibarr product object
     * @param string $shopifyProductId Shopify product ID
     * @return bool Success status
     * @since 2.0.26
     */
    private function collectProductForCollections($dolProduct, $shopifyProductId)
    {
        // Story 53-5 (site 3c, 2/3) : gate collections export via SyncFlowPolicy (la dérivation
        // encapsule DÉJÀ le gate SYNC_PRODUCT_COLLECTIONS + le mapping direction)
        if (!$this->isExportFlowAllowed(SyncFlowPolicy::FLOW_COLLECTIONS)) {
            $this->log("Collections sync is disabled or direction not allowed for export", LOG_DEBUG);
            return true; // Not an error, just not configured
        }

        // IMPORTANT: Only add parent products to collections, not variants
        // In Shopify, collections work at the product level, and all variants inherit collection membership
        if ($this->isProductVariant($dolProduct->id)) {
            $this->log("Skipping variant " . $dolProduct->ref . " for collections (only parent products are added to collections)", LOG_DEBUG);
            return true; // Skip variants - this is normal behavior
        }

        $this->log("Collecting PARENT product for batch collection assignment: " . $dolProduct->ref . " (Shopify ID: " . $shopifyProductId . ")", LOG_DEBUG);

        $categories = $this->getProductCategories($dolProduct);
        
        if (empty($categories)) {
            $this->log("No categories found for product " . $dolProduct->ref, LOG_DEBUG);
            return true;
        }

        // Collect associations for batch processing
        foreach ($categories as $category) {
            $collectionId = $this->getOrCreateShopifyCollection($category);
            
            if ($collectionId) {
                // Add to pending assignments
                if (!isset($this->pendingCollectionAssignments[$collectionId])) {
                    $this->pendingCollectionAssignments[$collectionId] = [];
                }
                $this->pendingCollectionAssignments[$collectionId][] = $shopifyProductId;
                
                $this->log("Collected product " . $dolProduct->ref . " for collection: " . $category['label'] . " (ID: " . $collectionId . ")", LOG_DEBUG);
            }
        }

        return true;
    }

    /**
     * Add product to collections based on Dolibarr categories
     *
     * @param Product $dolProduct Dolibarr product object
     * @param string $shopifyProductId Shopify product ID
     * @return bool Success status
     * @since 2.0.26
     */
    private function addProductToCollections($dolProduct, $shopifyProductId)
    {
        // Story 53-5 (site 3c, 3/3) : gate collections export via SyncFlowPolicy (la dérivation
        // encapsule DÉJÀ le gate SYNC_PRODUCT_COLLECTIONS + le mapping direction)
        if (!$this->isExportFlowAllowed(SyncFlowPolicy::FLOW_COLLECTIONS)) {
            $this->log("Collections sync is disabled or direction not allowed for export", LOG_DEBUG);
            return true; // Not an error, just not configured
        }

        $this->log("Starting collection assignment for product: " . $dolProduct->ref . " (Shopify ID: " . $shopifyProductId . ")", LOG_INFO);

        $categories = $this->getProductCategories($dolProduct);
        
        if (empty($categories)) {
            $this->log("No categories found for product " . $dolProduct->ref, LOG_DEBUG);
            return true;
        }

        // Group all collections that need this product
        $collectionsToUpdate = [];
        
        foreach ($categories as $category) {
            $collectionId = $this->getOrCreateShopifyCollection($category);
            
            if ($collectionId) {
                $collectionsToUpdate[] = [
                    'id' => $collectionId,
                    'name' => $category['label']
                ];
                $this->log("Will add product to collection: " . $category['label'] . " (ID: " . $collectionId . ")", LOG_DEBUG);
            }
        }

        if (empty($collectionsToUpdate)) {
            $this->log("No collections to update for product " . $dolProduct->ref, LOG_DEBUG);
            return true;
        }

        // Add product to each collection
        $successCount = 0;
        foreach ($collectionsToUpdate as $collection) {
            try {
                $response = $this->shopifyApi->collectionAddProducts($collection['id'], [$shopifyProductId]);
                
                if (!empty($response->data->collectionAddProducts->userErrors)) {
                    $this->log("Error adding product to collection " . $collection['name'] . ": " . json_encode($response->data->collectionAddProducts->userErrors), LOG_WARNING);
                } else {
                    $successCount++;
                    $this->log("Successfully added product " . $dolProduct->ref . " to collection: " . $collection['name'], LOG_INFO);
                }
            } catch (Exception $e) {
                $this->log("Exception adding product to collection " . $collection['name'] . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        $this->log("Collection assignment completed for product " . $dolProduct->ref . ": " . $successCount . "/" . count($collectionsToUpdate) . " collections", LOG_INFO);
        
        // Return true if at least some collections were updated successfully
        return $successCount > 0;
    }

    /**
     * Batch update collections with all pending product assignments (v2.0.26)
     *
     * @return bool Success status
     * @since 2.0.26
     */
    private function batchUpdateCollections()
    {
        if (empty($this->pendingCollectionAssignments)) {
            $this->log("No pending collection assignments to process", LOG_DEBUG);
            return true;
        }

        $this->log("Processing batch collection updates for " . count($this->pendingCollectionAssignments) . " collections", LOG_INFO);

        $successCount = 0;
        $totalAssignments = 0;

        foreach ($this->pendingCollectionAssignments as $collectionId => $productIds) {
            // Remove duplicates if any
            $uniqueProductIds = array_unique($productIds);
            $totalAssignments += count($uniqueProductIds);

            try {
                $this->log("Adding " . count($uniqueProductIds) . " products to collection: " . $collectionId, LOG_INFO);
                
                $response = $this->shopifyApi->collectionAddProducts($collectionId, $uniqueProductIds);
                
                if (!empty($response->data->collectionAddProducts->userErrors)) {
                    $this->log("Error in batch update for collection " . $collectionId . ": " . json_encode($response->data->collectionAddProducts->userErrors), LOG_WARNING);
                } else {
                    $successCount++;
                    $this->log("Successfully added " . count($uniqueProductIds) . " products to collection " . $collectionId . " in one API call", LOG_INFO);
                }
            } catch (Exception $e) {
                $this->log("Exception in batch update for collection " . $collectionId . ": " . $e->getMessage(), LOG_WARNING);
            }
        }

        $this->log("Batch collection updates completed: " . $successCount . "/" . count($this->pendingCollectionAssignments) . " collections, " . $totalAssignments . " total product assignments", LOG_INFO);
        
        // Clear pending assignments for next execution
        $this->pendingCollectionAssignments = [];
        
        return $successCount > 0;
    }

    /**
     * Publish collection to configured sales channels
     *
     * @param string $collectionGid Collection GID (gid://shopify/Collection/xxxxx)
     * @param string $categoryLabel Category label for logging
     * @return bool Success status
     * @since 2.0.33
     */
    private function publishCollectionToConfiguredChannels($collectionGid, $categoryLabel)
    {
        // Get configured sales channels
        $configuredChannels = getDolGlobalString('DOLI2SHOP_COLLECTIONS_SALES_CHANNELS', '');
        if (empty($configuredChannels)) {
            $this->log("No sales channels configured for collections", LOG_DEBUG);
            return true; // Not an error if no channels configured
        }

        $channelIds = array_filter(explode(',', $configuredChannels));
        if (empty($channelIds)) {
            $this->log("No valid sales channels found in configuration", LOG_DEBUG);
            return true;
        }

        $this->log("Publishing collection " . $categoryLabel . " to " . count($channelIds) . " configured sales channels", LOG_INFO);

        try {
            $response = $this->shopifyApi->publishCollectionToChannels($collectionGid, $channelIds);

            // Check for ACCESS_DENIED errors (write_publications scope missing)
            if (!empty($response->errors)) {
                foreach ($response->errors as $error) {
                    if (isset($error->extensions->code) && $error->extensions->code === 'ACCESS_DENIED') {
                        $this->log("Collection " . $categoryLabel . " synchronized successfully but not published (write_publications scope required)", LOG_INFO);
                        return true; // Consider this as success - collection is updated, just not published
                    }
                }
            }

            if ($response && empty($response->data->publishablePublish->userErrors)) {
                $this->log("Successfully published collection " . $categoryLabel . " to sales channels", LOG_INFO);
                return true;
            } else {
                $errors = $response->data->publishablePublish->userErrors ?? [];
                $this->log("Failed to publish collection " . $categoryLabel . " to sales channels: " . json_encode($errors), LOG_WARNING);
                return false;
            }
        } catch (Exception $e) {
            $this->log("Exception publishing collection " . $categoryLabel . " to sales channels: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Publish product to configured sales channels
     *
     * @param string $productGid Product GID (gid://shopify/Product/xxxxx)
     * @param string $productRef Product reference for logging
     * @return bool Success status
     * @since 2.1.1
     */
    private function publishProductToConfiguredChannels($productGid, $productRef)
    {
        // Get configured sales channels (réutilise la config des collections)
        $configuredChannels = getDolGlobalString('DOLI2SHOP_COLLECTIONS_SALES_CHANNELS', '');
        if (empty($configuredChannels)) {
            $this->log("No sales channels configured for products", LOG_DEBUG);
            return true; // Not an error if no channels configured
        }

        $channelIds = array_filter(explode(',', $configuredChannels));
        if (empty($channelIds)) {
            $this->log("No valid sales channels found in configuration", LOG_DEBUG);
            return true;
        }

        $this->log("Publishing product " . $productRef . " to " . count($channelIds) . " configured sales channels", LOG_INFO);

        try {
            // Utilise la même méthode API que pour les collections (publishablePublish est universel)
            $response = $this->shopifyApi->publishCollectionToChannels($productGid, $channelIds);

            // Check for ACCESS_DENIED errors (write_publications scope missing)
            if (!empty($response->errors)) {
                foreach ($response->errors as $error) {
                    if (isset($error->extensions->code) && $error->extensions->code === 'ACCESS_DENIED') {
                        $this->log("Product " . $productRef . " synchronized successfully but not published (write_publications scope required)", LOG_INFO);
                        return true; // Consider this as success - product is updated, just not published
                    }
                }
            }

            if ($response && empty($response->data->publishablePublish->userErrors)) {
                $this->log("Successfully published product " . $productRef . " to sales channels", LOG_INFO);
                return true;
            } else {
                $errors = $response->data->publishablePublish->userErrors ?? [];
                $this->log("Failed to publish product " . $productRef . " to sales channels: " . json_encode($errors), LOG_WARNING);
                return false;
            }
        } catch (Exception $e) {
            $this->log("Exception publishing product " . $productRef . " to sales channels: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Check if a category is within the tree of a parent category
     *
     * @param int $categoryId Category ID to check
     * @param int $parentTreeId Root parent category ID
     * @return bool True if category is in the tree, false otherwise
     * @since 2.1.1
     */
    private function isCategoryInTree($categoryId, $parentTreeId)
    {
        // Category is the parent itself - return false (parent is excluded)
        if ($categoryId == $parentTreeId) {
            return false;
        }

        // Traverse up the parent chain to see if we reach parentTreeId
        $currentId = $categoryId;
        $maxDepth = 20; // Safety limit to prevent infinite loops
        $depth = 0;

        while ($currentId > 0 && $depth < $maxDepth) {
            // Get parent of current category
            $sql = "SELECT fk_parent FROM " . MAIN_DB_PREFIX . "categorie
                    WHERE rowid = " . (int)$currentId . "
                    AND entity = " . (int)$this->entity;

            try {
                $result = SqlUtils::executeQuery($this->db, $sql, "checking category parent chain", false);
                if ($result) {
                    $row = $this->db->fetch_array($result);
                    $this->db->free($result);

                    if ($row) {
                        $parentId = $row['fk_parent'];

                        // Found the parent we're looking for
                        if ($parentId == $parentTreeId) {
                            return true;
                        }

                        // Continue up the chain
                        $currentId = $parentId;
                    } else {
                        // No more parents
                        break;
                    }
                } else {
                    break;
                }
            } catch (Exception $e) {
                $this->log("Error checking category tree for category " . $categoryId . ": " . $e->getMessage(), LOG_WARNING);
                return false;
            }

            $depth++;
        }

        // Didn't find parentTreeId in the parent chain
        return false;
    }



    /**
     * Upload category image to collection using unified image processing
     *
     * Story 57-7 : la résolution de l'image (chemin + binaire) passe désormais exclusivement
     * par DolibarrDirectFileResolver::listCategoryImages()/getCategoryImageBinary() — accès
     * disque direct, plus aucun appel HTTP à l'API REST Dolibarr self (remplace
     * getCategoryImagePath() + getImageContent(..., 'category', ...), supprimées).
     * Comportement fonctionnel préservé (AC2) : première image trouvée ; absence d'image ou
     * catégorie introuvable -> false + log DEBUG/WARNING (pas d'exception bloquante) ; la
     * suite de la chaîne d'upload Shopify (createStagedUploads -> uploadToStagedTarget ->
     * fileCreate -> waitForFileReady -> collectionUpdate) reste STRICTEMENT inchangée.
     *
     * @param string $collectionId Shopify collection ID
     * @param int $categoryId Category ID (rowid)
     * @param string $categoryLabel Category label for logging
     * @return bool Success status
     * @since 2.0.33
     * @version     2.6.0
     */
    private function uploadCategoryImageToCollection($collectionId, $categoryId, $categoryLabel)
    {
        try {
            $this->log("Starting image upload for collection " . $categoryLabel . " (ID: " . $collectionId . ")", LOG_INFO);

            // AC3 : échec de fetch() traité explicitement — jamais de Categorie non chargée
            // passée au resolver (produirait un chemin construit sur id=0).
            $category = $this->createCategorieInstance();
            $fetchResult = $category->fetch((int) $categoryId);
            if ($fetchResult <= 0) {
                $this->log(
                    "uploadCategoryImageToCollection - failed to fetch category id " . (int) $categoryId
                    . " (entity " . (int) $this->entity . ") for collection " . $categoryLabel
                    . " — Categorie::fetch() returned " . $fetchResult,
                    LOG_WARNING
                );
                return false;
            }

            // AC1/AC2 : liste les images via le resolver (accès disque direct), prend la
            // première (ordre alphabétique — cf. DolibarrDirectFileResolver::listCategoryImages()).
            $images = $this->getDirectFileResolver()->listCategoryImages($category);
            if (empty($images)) {
                $this->log("No image path found for category " . $categoryLabel, LOG_DEBUG);
                return false;
            }

            $imageContent = $this->getDirectFileResolver()->getCategoryImageBinary($category, $images[0]['filename']);
            if (!$imageContent) {
                $this->log("No image content found for category " . $categoryLabel, LOG_DEBUG);
                return false;
            }

            // 1. Prepare staged upload input using the same structure as product images
            // Simplify filename to avoid Shopify issues (keep only extension)
            $originalFilename = $imageContent['filename'];
            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $simpleFilename = 'collection_' . $collectionId . '.' . $extension;

            $stagedUploadInput = [
                'filename' => $simpleFilename,
                'mimeType' => $imageContent['mime_type'],
                'httpMethod' => 'PUT',
                'resource' => 'IMAGE',
                'fileSize' => (string)$imageContent['size']
            ];

            $this->log("Simplified filename from '" . $originalFilename . "' to '" . $simpleFilename . "'", LOG_DEBUG);
            $this->log("Using IMAGE resource with PUT method for collection images (same as products)", LOG_DEBUG);

            // 2. Create staged upload (reuse existing method)
            $stagedUploads = $this->shopifyApi->createStagedUploads([$stagedUploadInput]);
            if (
                empty($stagedUploads) ||
                empty($stagedUploads->data) ||
                empty($stagedUploads->data->stagedUploadsCreate) ||
                empty($stagedUploads->data->stagedUploadsCreate->stagedTargets)
            ) {
                $this->log("Failed to create staged upload for collection image", LOG_ERR);
                return false;
            }

            $stagedTarget = $stagedUploads->data->stagedUploadsCreate->stagedTargets[0];

            // 3. Upload image to staged target (reuse existing method)
            $uploadResult = $this->shopifyApi->uploadToStagedTarget($stagedTarget, $imageContent);
            if (!$uploadResult) {
                // Story 61-6 (review, MEDIUM-1) : cause réelle exposée via
                // $this->shopifyApi->error — cf. commentaire équivalent l.~5011.
                $this->log("Failed to upload image to staged target - Cause: " . $this->shopifyApi->error, LOG_ERR);
                return false;
            }

            $this->log("Successfully uploaded collection image to staged target", LOG_INFO);

            // For collections, uploadToStagedTarget returns the final URL, for products it returns resourceUrl or true
            $stagedImageUrl = is_string($uploadResult) ? $uploadResult : $stagedTarget->resourceUrl;
            $this->log("Using staged image URL for fileCreate: " . $stagedImageUrl, LOG_DEBUG);

            // 4. Create file from staged upload (required workflow for 2024-2025)
            $fileCreateResponse = $this->shopifyApi->fileCreate($stagedImageUrl, $simpleFilename, $categoryLabel);

            if (!empty($fileCreateResponse->data->fileCreate->userErrors)) {
                $this->log("Failed to create file from staged upload: " . json_encode($fileCreateResponse->data->fileCreate->userErrors), LOG_ERR);
                return false;
            }

            if (empty($fileCreateResponse->data->fileCreate->files) || empty($fileCreateResponse->data->fileCreate->files[0])) {
                $this->log("No file returned from fileCreate", LOG_ERR);
                return false;
            }

            $createdFile = $fileCreateResponse->data->fileCreate->files[0];
            $fileId = $createdFile->id;

            $this->log("File created with ID: " . $fileId . " (status: " . ($createdFile->fileStatus ?? 'unknown') . ")", LOG_INFO);

            // Wait for file to be ready and get the final image URL
            $finalImageUrl = $this->waitForFileReady($fileId);

            if (!$finalImageUrl) {
                $this->log("File did not become ready or no image URL available for file: " . $fileId, LOG_ERR);
                return false;
            }

            $this->log("File ready with URL: " . $finalImageUrl, LOG_INFO);

            // 5. Update collection with the final image URL
            $collectionUpdateQuery = '
            mutation collectionUpdate($input: CollectionInput!) {
                collectionUpdate(input: $input) {
                    collection {
                        id
                        image {
                            url
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }';

            $collectionUpdateVariables = [
                'input' => [
                    'id' => 'gid://shopify/Collection/' . $collectionId,
                    'image' => [
                        'src' => $finalImageUrl,
                        'altText' => $categoryLabel
                    ]
                ]
            ];

            $collectionUpdateResponse = $this->shopifyApi->executeGraphQL([
                'query' => $collectionUpdateQuery,
                'variables' => $collectionUpdateVariables
            ]);

            if (!empty($collectionUpdateResponse->data->collectionUpdate->userErrors)) {
                $this->log("First attempt failed with altText, trying without altText: " . json_encode($collectionUpdateResponse->data->collectionUpdate->userErrors), LOG_WARNING);

                // Fallback: Try without altText
                $fallbackVariables = [
                    'input' => [
                        'id' => 'gid://shopify/Collection/' . $collectionId,
                        'image' => [
                            'src' => $finalImageUrl
                        ]
                    ]
                ];

                $fallbackResponse = $this->shopifyApi->executeGraphQL([
                    'query' => $collectionUpdateQuery,
                    'variables' => $fallbackVariables
                ]);

                if (!empty($fallbackResponse->data->collectionUpdate->userErrors)) {
                    $this->log("Fallback also failed to update collection with image: " . json_encode($fallbackResponse->data->collectionUpdate->userErrors), LOG_ERR);
                    return false;
                } else {
                    $this->log("Successfully updated collection " . $collectionId . " with image (fallback method)", LOG_INFO);
                    return true;
                }
            }

            $this->log("Successfully updated collection " . $collectionId . " with new image", LOG_INFO);
            return true;

        } catch (Exception $e) {
            $this->log("Exception during collection image upload: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }
}
