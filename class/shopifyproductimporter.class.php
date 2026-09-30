<?php
/**
 * @file        class/shopifyproductimporter.class.php
 * @brief       Class for importing products from Shopify to Dolibarr
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.1.6
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 *
 * Story import-images-duplique-les-photos-a-chaque-synchronisation (v2.5.3) : importProductImages()
 * téléchargeait inconditionnellement toutes les images du produit à chaque appel — le webhook
 * `products/update` (onlyNew=false) en recopiait donc une copie complète à chaque notification
 * Shopify, quel que soit le champ réellement modifié. Correctif : table de correspondance dédiée
 * `llx_doli2shop_product_images` (fk_product, entity, shopify_image_id, filename), alimentée
 * depuis le `id` d'image Shopify déjà présent dans le payload GraphQL mais jusqu'ici jeté après
 * lecture de `->url`. Nom de fichier déterministe (préfixe `doli2shop-shopify-` + shopify_image_id,
 * plus de `uniqid()`) : un second passage retrouve son propre travail sans retélécharger, et le
 * fichier reste distinguable d'une photo déposée par le client (AC4).
 *
 * Correctif complémentaire (revue coordinateur) : `addImageToProduct()` retirait un no-op
 * `$product->photo = ...; $product->update(...)` — `Product` n'a pas de propriété `$photo` ni
 * `llx_product` de colonne `photo`, donc rien n'était persisté, mais l'`update()` déclenchait
 * quand même le trigger `PRODUCT_MODIFY`, qui exporte le produit vers Shopify et pouvait ainsi
 * ré-émettre le webhook `products/update` à l'origine de l'import — une boucle d'écho
 * auto-entretenue, seulement atténuée par la garde anti-boucle 30 s/5 min de
 * `ProductWebhookHandler.php:249-282`. Ces écritures sont supprimées.
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/core/class/commonobject.class.php');
dol_include_once('/product/class/product.class.php');
dol_include_once('/categories/class/categorie.class.php');
dol_include_once('/ecm/class/ecmfiles.class.php');
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/collectionsutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
require_once dirname(__FILE__) . '/storeservice.class.php';         // Epic 47, Story 47-5: tag catégorie produit boutique
require_once dirname(__FILE__) . '/storecategoryhelper.class.php';  // Epic 47, Story 47-5: tag catégorie produit boutique

/**
 * Class for importing products from Shopify to Dolibarr
 */
class ShopifyProductImporter
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDB Database handler */
    private $db;

    /** @var ShopifyApi Shopify API client */
    private $shopifyApi;

    /** @var SyncFlowPolicy|null Politique de synchronisation directionnelle (Story 53-5, lazy) */
    private $syncFlowPolicy;

    /** @var CollectionsUtils Collections utilities for category mapping */
    private $collectionsUtils;

    /** @var object Configuration settings */
    private $config;

    /** @var int Current entity */
    private $entity;

    /** @var User Current user */
    private $user;

    /** @var array Import statistics */
    private $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'images_imported' => 0
    ];

    /**
     * Chemins de fichiers (image + vignettes thumbs/) en attente de suppression PHYSIQUE,
     * décidés par removeStaleProductImageMappings() pendant l'import courant, alors que la
     * transaction SQL de processProduct() est encore ouverte.
     *
     * Revue coordinateur (findings additionnels, story import-images-duplique) : un unlink()
     * immédiat est irréversible, alors qu'un rollback() SQL déclenché par une étape ultérieure de
     * processProduct() (ex. saveProductExtrafields()) restaure la ligne de mapping supprimée mais
     * JAMAIS le fichier physique. La suppression du fichier n'a donc plus lieu qu'après le commit()
     * réel de la transaction — flushPendingFileDeletions(), appelée par processProduct() juste
     * après db->commit() — jamais avant, et jamais du tout en cas de rollback (clearPendingFileDeletions()).
     *
     * @var string[]
     */
    private $pendingFileDeletions = [];

    /** @var array Errors encountered during import */
    public $errors = [];

    /** @var string Last error message */
    public $error = '';

    /**
     * @var array<string,string[]> Cache mémoire des résolutions DNS par hôte, pour la durée du
     * run (process PHP courant, pas de TTL).
     *
     * Story 61-6 (review, HIGH-2) : avant ce cache, `resolveHostIps()` refaisait jusqu'à 3
     * résolutions DNS bloquantes (A, puis AAAA, puis repli `gethostbyname`) À CHAQUE saut de
     * `downloadImage()` (URL initiale + jusqu'à 3 redirections), et sans aucune mutualisation
     * entre deux images du même hôte lors d'un import de catalogue entier. Sur un hébergeur au
     * DNS lent — précisément la population visée par cette story — le temps cumulé pouvait
     * déborder `max_execution_time` sur un CRON. Ce cache mémoïse le résultat par hôte pour le
     * reste du run : pas de TTL nécessaire, la durée de vie du cache est celle du process PHP
     * (une exécution de script/CRON).
     *
     * ⚠️ Non fait, signalé explicitement plutôt que présenté comme résolu : la durée d'UNE
     * résolution DNS individuelle n'est pas bornée (`dns_get_record()`/`gethostbyname()` n'ont
     * pas de timeout configurable en PHP sans extension supplémentaire, ex. pcntl/pthreads).
     * Seule la mutualisation entre appels est traitée ici.
     */
    private static $hostIpsCache = array();

    /**
     * @var bool Indique si le DERNIER appel à `linkDolibarrVariant()` ayant renvoyé `true` l'a
     * fait SANS rien écrire en base (no-op) — module Variants désactivé, ou aucune option
     * Shopify distinctive exploitable. `false` pour l'idempotence (combinaison déjà existante)
     * et le garde-fou double-parent : ces cas ont bien une `ProductCombination` en base.
     * Correction post-review 3-couches (hotfix 2.4.4 2/2, convergence `VariantRepairService`) —
     * sans distinction, un no-op comptait comme "réparé" à tort et, comme aucune combinaison
     * n'existait, le candidat repassait le filtre à chaque passe (non-convergence).
     * @since 2.4.4
     */
    public $lastLinkWasNoop = false;

    /**
     * @var bool Indique si le DERNIER appel à `linkDolibarrVariant()` ayant renvoyé `true` n'a
     * ATTACHÉ AUCUNE combinaison nouvelle — soit parce qu'elle existait déjà pour le BON parent
     * (garde HIGH-B, ci-dessous), soit par idempotence exacte (paires attribut/valeur déjà
     * identiques), soit parce que l'enfant est rattaché à un AUTRE parent (`$lastLinkWasWrongParent`,
     * ci-dessous — cas également "déjà lié", pas une nouvelle attache). Distinct de
     * `$lastLinkWasNoop` (module désactivé / aucune option distinctive — ici il n'existe JAMAIS
     * de `ProductCombination`, alors que `$lastLinkWasAlreadyLinked` couvre les cas où elle EXISTE
     * déjà). Story 63-17 (code review, MEDIUM-E) : `VariantRepairService::processOneCandidate()`
     * ne doit compter `repaired` que sur une VRAIE nouvelle attache — sans cette distinction, le
     * pool "libellés" (candidats DÉJÀ rattachés, cf `countRepairCandidates($includeLabelOnly)`)
     * gonflait `repaired` à chaque correction de libellé alors qu'aucun rattachement n'avait eu
     * lieu.
     * @since 2.5.2
     */
    public $lastLinkWasAlreadyLinked = false;

    /**
     * @var bool Indique si le DERNIER appel à `linkDolibarrVariant()` a détecté que l'enfant est
     * déjà rattaché à un parent DIFFÉRENT de celui attendu (incohérence de données préexistante,
     * cf. garde double-parent) — implique toujours `$lastLinkWasAlreadyLinked = true`. Story 63-17
     * (code review, LOW-H) : sur ce cas, le libellé ne doit PAS être recalculé (il serait recalculé
     * avec le titre du MAUVAIS parent, aggravant l'incohérence) — `VariantRepairService` utilise ce
     * drapeau pour sauter l'étape `fixLabels` spécifiquement ici.
     * @since 2.5.2
     */
    public $lastLinkWasWrongParent = false;

    /**
     * Constructor
     *
     * @param DoliDB      $db     Database handler
     * @param User        $user   Current user
     * @param int|null    $entity Entity ID (optional)
     * @param object|null $store  Objet boutique (StoreService). Null = chemin historique entité/constantes.
     */
    public function __construct($db, $user, $entity = null, $store = null)
    {
        global $conf;

        $this->db = $db;
        $this->user = $user;

        // Determine entity to use
        if ($entity !== null) {
            $this->entity = (int)$entity;
        } else {
            $this->entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;
        }

        $this->log("Initialisation ShopifyProductImporter pour l'entité " . $this->entity, LOG_INFO);

        // Create ShopifyApi with explicit entity (+ store si fourni)
        $this->shopifyApi = new ShopifyApi($db, $this->entity, $store);

        // Create CollectionsUtils for category mapping
        $this->collectionsUtils = new CollectionsUtils($db, $this->entity);

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
     * Instance SyncFlowPolicy paresseuse (une seule par instance de l'importeur — Story 53-5,
     * JAMAIS d'instanciation dans une boucle produits).
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
     * Vérifie qu'un sous-flux d'import (prix, images, collections) est autorisé pour la boutique
     * courante via SyncFlowPolicy — direction shopify_to_dolibarr fixe (ce fichier n'importe que
     * dans ce sens). Helper factorisé pour les sites 2c/2d/2e (Story 53-5).
     *
     * @param  string $flowId Identifiant SyncFlowPolicy::FLOW_*
     * @return bool
     */
    private function isImportFlowAllowed($flowId)
    {
        return $this->getSyncFlowPolicy()->isAllowed($flowId, 'shopify_to_dolibarr', $this->shopifyApi->getStoreId());
    }

    /**
     * Check if product import from Shopify is enabled
     *
     * Story 53-5 (site 2a) : gate d'entrée grossier — le lot mêle créations et mises à jour
     * (onlyNew peut être false), donc autorisé dès que product_create OU product_update est
     * permis pour la boutique courante ; la granularité fine (bloquer uniquement les créations,
     * ou uniquement les mises à jour) est posée au point de décision fin (site 2b, processProduct).
     * Sans override SYNC_FLOW_*, create/update dérivent tous deux de SYNC_PRODUCTS_DIRECTION →
     * équivalent à l'ancienne garde globale unique (SyncUtils, retirée par cette story).
     *
     * @return boolean True if enabled
     */
    public function isImportEnabled()
    {
        $storeId = $this->shopifyApi->getStoreId();
        $policy = $this->getSyncFlowPolicy();
        return $policy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_CREATE, 'shopify_to_dolibarr', $storeId)
            || $policy->isAllowed(SyncFlowPolicy::FLOW_PRODUCT_UPDATE, 'shopify_to_dolibarr', $storeId);
    }

    /**
     * Get synchronization options from Dolibarr configuration
     * Ensures consistency with export (ImportProducts) settings
     *
     * Correctif code review (MEDIUM-3, Story 53-5) : les clés 'sync_prices'/'sync_images' ont été
     * retirées — le prix et les images à l'import passent désormais exclusivement par
     * SyncFlowPolicy::FLOW_PRICE/FLOW_IMAGES via isImportFlowAllowed() (sites 2c/2d), plus jamais
     * lues depuis ce tableau (vérifié par grep sur l'ensemble du dépôt avant suppression).
     *
     * @return array Synchronization options
     */
    private function getSyncOptions()
    {
        return [
            'sync_descriptions' => getDolGlobalInt('DOLI2SHOP_SYNC_DESCRIPTIONS', 1),
            'sync_stock' => getDolGlobalInt('DOLI2SHOP_SYNC_STOCK', 1),
            'sync_attributes' => getDolGlobalInt('DOLI2SHOP_SYNC_ATTRIBUTES', 0),
            'use_virtual_stock' => getDolGlobalInt('DOLI2SHOP_USE_VIRTUAL_STOCK', 0)
        ];
    }

    /**
     * Get product stock based on virtual/real stock configuration
     * Ensures consistency with export (ImportProducts) logic
     *
     * @param Product $product Dolibarr product
     * @return float Stock value
     */
    private function getProductStock($product)
    {
        if (getDolGlobalInt('DOLI2SHOP_USE_VIRTUAL_STOCK')) {
            return $product->stock_theorique ?? 0;
        }
        return $product->stock_reel ?? 0;
    }

    /**
     * Save Shopify tags and vendor to product extrafields
     *
     * @param Product $product Dolibarr product object
     * @param object $shopifyProduct Shopify product data
     * @return void
     */
    private function saveProductExtrafields($product, $shopifyProduct)
    {
        $hasExtrafields = false;

        // Tags Shopify
        if (!empty($shopifyProduct->tags)) {
            $tags = is_array($shopifyProduct->tags) ? implode(', ', $shopifyProduct->tags) : $shopifyProduct->tags;
            $product->array_options['options_shopify_tags'] = substr($tags, 0, 1024);
            $hasExtrafields = true;
            $this->log("Tags Shopify stockés pour produit " . $product->ref . ": " . substr($tags, 0, 100), LOG_DEBUG);
        }

        // Vendor Shopify
        if (!empty($shopifyProduct->vendor)) {
            $product->array_options['options_shopify_vendor'] = substr($shopifyProduct->vendor, 0, 255);
            $hasExtrafields = true;
            $this->log("Vendor Shopify stocké pour produit " . $product->ref . ": " . $shopifyProduct->vendor, LOG_DEBUG);
        }

        // Save extrafields if any
        if ($hasExtrafields) {
            $result = $product->insertExtraFields();
            if ($result < 0) {
                $this->log("Erreur sauvegarde extrafields produit " . $product->ref . ": " . implode(', ', $product->errors), LOG_WARNING);
            }
        }
    }

    /**
     * Assign product to Dolibarr categories based on Shopify collections
     *
     * @param Product $product Dolibarr product object
     * @param object $shopifyProduct Shopify product with collections
     * @return int Number of categories assigned
     */
    private function assignProductToCategories($product, $shopifyProduct)
    {
        // Story 53-5 (site 2e) : gate unique via SyncFlowPolicy (encapsule déjà le gate
        // DOLI2SHOP_SYNC_PRODUCT_COLLECTIONS + le mapping DOLI2SHOP_SYNC_COLLECTIONS_DIRECTION
        // shop_to_dol/bidirectional → shopify_to_dolibarr/both — remplace le couple de conditions).
        if (!$this->isImportFlowAllowed(SyncFlowPolicy::FLOW_COLLECTIONS)) {
            return 0;
        }

        // Extract collections from product
        if (empty($shopifyProduct->collections->edges)) {
            $this->log("Aucune collection pour produit " . $product->ref, LOG_DEBUG);
            return 0;
        }

        $assignedCount = 0;

        foreach ($shopifyProduct->collections->edges as $edge) {
            $collection = $edge->node;

            // Get collection ID (use legacyResourceId if available, otherwise extract from GID)
            $collectionId = $collection->legacyResourceId ?? $this->extractNumericId($collection->id, 'Collection');

            if (empty($collectionId)) {
                continue;
            }

            // Get mapping for this collection
            $mapping = $this->collectionsUtils->getMappingByCollectionId($collectionId);

            if ($mapping && !empty($mapping['dolibarr_category_id'])) {
                $categoryId = (int)$mapping['dolibarr_category_id'];

                // Check if product is already in this category
                $cat = new Categorie($this->db);
                if ($cat->fetch($categoryId) > 0) {
                    // add_type returns >0 on success, 0 if already exists, <0 on error
                    $result = $cat->add_type($product, Categorie::TYPE_PRODUCT);
                    if ($result > 0) {
                        $assignedCount++;
                        $this->log("Catégorie $categoryId assignée au produit " . $product->ref . " depuis collection " . $collection->title, LOG_DEBUG);
                    } elseif ($result == 0) {
                        // Already assigned, not an error
                        $this->log("Produit " . $product->ref . " déjà dans catégorie $categoryId", LOG_DEBUG);
                    }
                }
            } else {
                $this->log("Collection non mappée: " . $collection->title . " (ID: $collectionId)", LOG_DEBUG);
            }
        }

        if ($assignedCount > 0) {
            $this->log("Total catégories assignées au produit " . $product->ref . ": $assignedCount", LOG_INFO);
        }

        return $assignedCount;
    }

    /**
     * Import products from Shopify to Dolibarr
     *
     * @param int $limit Maximum number of products to import (default 50)
     * @param string|null $cursor Pagination cursor for GraphQL
     * @param bool $onlyNew Import only new products (not already mapped)
     * @return array Result with stats and pagination info
     */
    public function importProducts($limit = 50, $cursor = null, $onlyNew = true)
    {
        $this->log("Début import produits Shopify → Dolibarr (limit=$limit, onlyNew=$onlyNew)", LOG_INFO);

        // Check if sync direction is enabled
        if (!$this->isImportEnabled()) {
            $this->log("Import Shopify → Dolibarr désactivé dans la configuration", LOG_WARNING);
            return [
                'success' => false,
                'message' => 'Import Shopify → Dolibarr is disabled in configuration',
                'stats' => $this->stats,
                'hasNextPage' => false,
                'endCursor' => null
            ];
        }

        try {
            // Fetch products from Shopify
            $shopifyProducts = $this->getProductsFromShopify($cursor, $limit);

            if (empty($shopifyProducts['products'])) {
                $this->log("Aucun produit Shopify à importer", LOG_INFO);
                return [
                    'success' => true,
                    'message' => 'No products to import',
                    'stats' => $this->stats,
                    'hasNextPage' => false,
                    'endCursor' => null
                ];
            }

            // Process each product
            foreach ($shopifyProducts['products'] as $shopifyProduct) {
                try {
                    $this->processProduct($shopifyProduct, $onlyNew);
                } catch (Exception $e) {
                    $this->stats['errors']++;
                    $this->errors[] = "Product " . $shopifyProduct->id . ": " . $e->getMessage();
                    $this->log("Erreur import produit " . $shopifyProduct->id . ": " . $e->getMessage(), LOG_ERR);
                }
            }

            $this->log("Import terminé - Créés: " . $this->stats['created'] .
                       ", Mis à jour: " . $this->stats['updated'] .
                       ", Ignorés: " . $this->stats['skipped'] .
                       ", Erreurs: " . $this->stats['errors'], LOG_INFO);

            return [
                'success' => true,
                'message' => 'Import completed',
                'stats' => $this->stats,
                'hasNextPage' => $shopifyProducts['hasNextPage'],
                'endCursor' => $shopifyProducts['endCursor']
            ];

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            $this->log("Erreur critique import: " . $e->getMessage(), LOG_ERR);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'stats' => $this->stats,
                'hasNextPage' => false,
                'endCursor' => null
            ];
        }
    }

    /**
     * Import a single product from Shopify
     *
     * @param string $shopifyProductId Shopify product ID (numeric or GID)
     * @return array Result with success status and product info
     */
    public function importSingleProduct($shopifyProductId)
    {
        $this->log("Import produit unique: $shopifyProductId", LOG_INFO);

        // Check if sync direction is enabled
        if (!$this->isImportEnabled()) {
            return [
                'success' => false,
                'message' => 'Import Shopify → Dolibarr is disabled in configuration'
            ];
        }

        try {
            // Normalize product ID (extract numeric ID if GID format)
            $numericId = $this->extractNumericId($shopifyProductId, 'Product');

            // Fetch single product from Shopify
            $shopifyProduct = $this->getProductFromShopify($numericId);

            if (empty($shopifyProduct)) {
                return [
                    'success' => false,
                    'message' => 'Product not found in Shopify'
                ];
            }

            // Process the product
            $result = $this->processProduct($shopifyProduct, false);

            return [
                'success' => true,
                'message' => $result['action'] . ': ' . ($shopifyProduct->title ?? ''),
                'dolibarrProductId' => $result['dolibarrProductId'],
                'action' => $result['action']
            ];

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            $this->log("Erreur import produit unique: " . $e->getMessage(), LOG_ERR);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get products from Shopify with pagination
     *
     * @param string|null $cursor Pagination cursor
     * @param int $limit Number of products to fetch
     * @return array Products and pagination info
     */
    private function getProductsFromShopify($cursor = null, $limit = 50)
    {
        $this->log("Récupération produits Shopify (cursor=" . ($cursor ?? 'null') . ", limit=$limit)", LOG_DEBUG);

        // ⚠️ Le littéral `images(first: 20)` ci-dessous DOIT rester synchronisé avec
        // self::IMAGES_FETCH_LIMIT (utilisée par importProductImages() pour décider si le
        // payload peut être tronqué) — grep IMAGES_FETCH_LIMIT avant toute modification de cette
        // requête. Pas de pagination réelle du sous-champ images ici : cf. doc de la constante.
        $query = 'query GetProducts($cursor: String, $first: Int!) {
            products(first: $first, after: $cursor) {
                edges {
                    node {
                        id
                        title
                        handle
                        status
                        bodyHtml
                        descriptionHtml
                        productType
                        vendor
                        tags
                        createdAt
                        updatedAt
                        variants(first: 100) {
                            edges {
                                node {
                                    id
                                    sku
                                    title
                                    price
                                    compareAtPrice
                                    barcode
                                    inventoryQuantity
                                    inventoryItem {
                                        id
                                        tracked
                                        measurement {
                                            weight {
                                                unit
                                                value
                                            }
                                        }
                                    }
                                    selectedOptions {
                                        name
                                        value
                                    }
                                }
                            }
                        }
                        images(first: 20) {
                            edges {
                                node {
                                    id
                                    url
                                    altText
                                }
                            }
                        }
                        options {
                            id
                            name
                            values
                        }
                        collections(first: 10) {
                            edges {
                                node {
                                    id
                                    title
                                    handle
                                    legacyResourceId
                                }
                            }
                        }
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }';

        $variables = [
            'first' => $limit,
            'cursor' => $cursor
        ];

        $response = $this->shopifyApi->executeGraphQL(['query' => $query, 'variables' => $variables]);

        if (!empty($response->errors)) {
            $errorMsg = is_array($response->errors) ? json_encode($response->errors) : $response->errors;
            throw new Exception("GraphQL error: " . $errorMsg);
        }

        // Story 62-6 (finding 7, review 3 couches 2026-09-23) : `data` présent mais
        // `data->products` absent (schéma Shopify cassé/changé, ex. renommage du champ racine)
        // ne doit JAMAIS être confondu avec un catalogue légitimement épuisé. Un catalogue vide
        // légitime renvoie toujours `products` (avec `edges: []`) — c'est `products` lui-même qui
        // doit être présent, pas `edges`. Sans cette vérification, le `!empty(...->edges)`
        // ci-dessous rendait les deux cas indiscernables : `$products` restait `[]` et
        // `importProducts()` annonçait `success:true, "No products to import"` — la synchro page
        // suivante affichait « terminé » alors que la réponse Shopify était structurellement
        // inexploitable. Chemin volontairement limité à `['products']` (pas
        // `['products', 'edges']`) : voir ShopifyProductImporterGetProductsFromShopifySchemaGuardTest.
        $productsPathSignal = \ShopifyApi::extractGraphQLDataPath($response, ['products']);
        if (!$productsPathSignal['success']) {
            throw new Exception("GraphQL error: unexpected response schema — 'data.products' missing while fetching Shopify products page");
        }

        $products = [];
        if (!empty($response->data->products->edges)) {
            foreach ($response->data->products->edges as $edge) {
                $products[] = $edge->node;
            }
        }

        return [
            'products' => $products,
            'hasNextPage' => $response->data->products->pageInfo->hasNextPage ?? false,
            'endCursor' => $response->data->products->pageInfo->endCursor ?? null
        ];
    }

    /**
     * Get a single product from Shopify by ID
     *
     * @param string $shopifyProductId Numeric product ID
     * @return object|null Product object or null
     */
    private function getProductFromShopify($shopifyProductId)
    {
        $this->log("Récupération produit Shopify ID: $shopifyProductId", LOG_DEBUG);

        // ⚠️ Le littéral `images(first: 20)` ci-dessous DOIT rester synchronisé avec
        // self::IMAGES_FETCH_LIMIT — grep IMAGES_FETCH_LIMIT avant toute modification.
        $query = 'query GetProduct($id: ID!) {
            product(id: $id) {
                id
                title
                handle
                status
                bodyHtml
                descriptionHtml
                productType
                vendor
                tags
                createdAt
                updatedAt
                variants(first: 100) {
                    edges {
                        node {
                            id
                            sku
                            title
                            price
                            compareAtPrice
                            barcode
                            inventoryQuantity
                            inventoryItem {
                                id
                                tracked
                                measurement {
                                    weight {
                                        unit
                                        value
                                    }
                                }
                            }
                            selectedOptions {
                                name
                                value
                            }
                        }
                    }
                }
                images(first: 20) {
                    edges {
                        node {
                            id
                            url
                            altText
                        }
                    }
                }
                options {
                    id
                    name
                    values
                }
                collections(first: 10) {
                    edges {
                        node {
                            id
                            title
                            handle
                            legacyResourceId
                        }
                    }
                }
            }
        }';

        $variables = [
            'id' => 'gid://shopify/Product/' . $shopifyProductId
        ];

        $response = $this->shopifyApi->executeGraphQL(['query' => $query, 'variables' => $variables]);

        if (!empty($response->errors)) {
            $errorMsg = is_array($response->errors) ? json_encode($response->errors) : $response->errors;
            throw new Exception("GraphQL error: " . $errorMsg);
        }

        return $response->data->product ?? null;
    }

    /**
     * Wrapper public RICHE, ajouté par le hotfix 2.4.4 (2/2, réparation batch des imports
     * existants) — réutilise STRICTEMENT `getProductFromShopify()` (aucune duplication de
     * requête GraphQL) et matche la variante recherchée par id (`extractNumericId`).
     *
     * Accepte optionnellement un produit Shopify déjà récupéré (`$cachedProduct`) pour éviter
     * un re-fetch réseau : `VariantRepairService::repairBatch()` déduplique ainsi les appels
     * API par `shopifyProductId` le temps d'un lot (plusieurs variantes/enfants à réparer
     * peuvent partager le même produit Shopify parent).
     *
     * @param string      $shopifyProductId Id Shopify du produit (numérique, sans préfixe gid://)
     * @param string      $shopifyVariantId Id Shopify de la variante recherchée
     * @param object|null $cachedProduct    Produit Shopify déjà récupéré (évite le re-fetch réseau)
     * @return array{product:object,variant:object,selectedOptions:array}|null
     *         null si le produit OU la variante est introuvable (produit supprimé/injoignable,
     *         variante supprimée côté Shopify) — l'appelant doit alors skip+log, jamais bloquer.
     * @since 2.4.4
     */
    public function fetchShopifyVariantContext($shopifyProductId, $shopifyVariantId, $cachedProduct = null)
    {
        $product = $cachedProduct;

        if (empty($product)) {
            try {
                $product = $this->getProductFromShopify($shopifyProductId);
            } catch (\Throwable $e) {
                $this->log("fetchShopifyVariantContext: erreur fetch produit Shopify #$shopifyProductId: " . $e->getMessage(), LOG_WARNING);
                return null;
            }
        }

        if (empty($product)) {
            $this->log("fetchShopifyVariantContext: produit Shopify introuvable (#$shopifyProductId)", LOG_WARNING);
            return null;
        }

        $variant = null;
        if (!empty($product->variants->edges)) {
            foreach ($product->variants->edges as $edge) {
                $node = $edge->node ?? null;
                if ($node === null) {
                    continue;
                }
                if ((string) $this->extractNumericId($node->id, 'ProductVariant') === (string) $shopifyVariantId) {
                    $variant = $node;
                    break;
                }
            }
        }

        if ($variant === null) {
            $this->log("fetchShopifyVariantContext: variante Shopify introuvable (#$shopifyVariantId) sur produit #$shopifyProductId", LOG_WARNING);
            return null;
        }

        return [
            'product' => $product,
            'variant' => $variant,
            'selectedOptions' => $variant->selectedOptions ?? [],
        ];
    }

    /**
     * Process a single Shopify product for import
     *
     * @param object $shopifyProduct Shopify product object
     * @param bool $onlyNew Only import if not already mapped
     * @return array Result with action taken and product ID
     */
    private function processProduct($shopifyProduct, $onlyNew = true)
    {
        $shopifyProductId = $this->extractNumericId($shopifyProduct->id, 'Product');
        $this->log("Traitement produit Shopify: " . $shopifyProduct->title . " (ID: $shopifyProductId)", LOG_DEBUG);

        // Get variants
        $variants = [];
        if (!empty($shopifyProduct->variants->edges)) {
            foreach ($shopifyProduct->variants->edges as $edge) {
                $variants[] = $edge->node;
            }
        }

        // Check if already mapped
        $existingMapping = $this->findExistingMapping($shopifyProductId);

        if ($existingMapping && $onlyNew) {
            $this->stats['skipped']++;
            $this->log("Produit déjà mappé, ignoré: $shopifyProductId", LOG_DEBUG);
            return [
                'action' => 'skipped',
                'dolibarrProductId' => $existingMapping->fk_product
            ];
        }

        // Story 53-5 (site 2b) : gate fin par action, indépendant du gate d'entrée grossier (2a).
        // Un produit déjà mappé se met à jour (flux product_update) ; un produit non mappé est créé
        // (flux product_create) — chacun peut être bloqué indépendamment (ex. SYNC_FLOW_PRODUCT_
        // CREATE=none bloque uniquement les créations, les mises à jour continuent).
        $productActionFlow = $existingMapping ? SyncFlowPolicy::FLOW_PRODUCT_UPDATE : SyncFlowPolicy::FLOW_PRODUCT_CREATE;
        if (!$this->isImportFlowAllowed($productActionFlow)) {
            $this->stats['skipped']++;
            $this->log("processProduct - Flux " . $productActionFlow . " désactivé (storeId="
                . $this->shopifyApi->getStoreId() . "), produit Shopify ignoré: " . $shopifyProductId, LOG_DEBUG);
            return [
                'action' => 'skipped',
                'dolibarrProductId' => $existingMapping->fk_product ?? null
            ];
        }

        // Determine if product has variants
        $hasVariants = count($variants) > 1 || $this->hasRealOptions($shopifyProduct);

        $this->db->begin();

        try {
            if ($existingMapping) {
                // Update existing product
                $dolibarrProductId = $this->updateDolibarrProduct($existingMapping->fk_product, $shopifyProduct, $variants);
                // v2.1.9: Mettre à jour le mapping parent lors du re-import (Finding #4)
                $this->saveProductMapping($dolibarrProductId, $shopifyProductId, null, null);
                $this->stats['updated']++;
                $action = 'updated';
            } else {
                // Create new product
                if ($hasVariants) {
                    $dolibarrProductId = $this->createDolibarrProductWithVariants($shopifyProduct, $variants);
                } else {
                    $dolibarrProductId = $this->createDolibarrSimpleProduct($shopifyProduct, $variants[0] ?? null);
                }
                $this->stats['created']++;
                $action = 'created';
            }

            // Import images if configured (Story 53-5, site 2c)
            if ($this->isImportFlowAllowed(SyncFlowPolicy::FLOW_IMAGES)) {
                $this->importProductImages($dolibarrProductId, $shopifyProduct);
            }

            // Save extrafields (tags and vendor) - v2.1.6
            $productObj = new Product($this->db);
            if ($productObj->fetch($dolibarrProductId) > 0) {
                // Save tags and vendor to extrafields
                $this->saveProductExtrafields($productObj, $shopifyProduct);

                // Assign categories from collections if configured
                $this->assignProductToCategories($productObj, $shopifyProduct);
            }

            $this->db->commit();

            // "À traiter aussi" (revue coordinateur, story import-images-duplique) : la
            // suppression PHYSIQUE des fichiers image remplacés (AC3) n'a lieu qu'ICI, une fois
            // la transaction réellement commit() — jamais avant. Un rollback() plus haut (ex.
            // saveProductExtrafields() qui lève) restaure la ligne de mapping mais ne peut jamais
            // restaurer un fichier déjà supprimé du disque ; différer l'unlink() élimine ce risque.
            $this->flushPendingFileDeletions();

            return [
                'action' => $action,
                'dolibarrProductId' => $dolibarrProductId
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            // Abandonne les suppressions de fichiers mises en attente SANS toucher au disque —
            // les fichiers "remplacés" restent présents (orphelins mais intacts), cohérent avec
            // le rollback() de la ligne de mapping ci-dessus.
            $this->clearPendingFileDeletions();
            throw $e;
        }
    }

    /**
     * Check if product has real options (not just "Default Title")
     *
     * @param object $shopifyProduct Shopify product
     * @return bool True if has real options
     */
    private function hasRealOptions($shopifyProduct)
    {
        if (empty($shopifyProduct->options)) {
            return false;
        }

        foreach ($shopifyProduct->options as $option) {
            // v2.1.9: Une option nommée différemment de "Title" avec au moins 1 valeur = variant réel
            // (même avec 1 seule valeur : ex. "Couleur: Rouge" = produit avec option)
            if ($option->name !== 'Title' && !empty($option->values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find existing mapping for a Shopify product
     *
     * @param string $shopifyProductId Shopify product ID
     * @return object|null Mapping object or null
     */
    private function findExistingMapping($shopifyProductId)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE shopifyProductId = '" . $this->db->escape($shopifyProductId) . "'";
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " AND (fk_product_parent IS NULL OR fk_product_parent = 0)";
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            return $this->db->fetch_object($result);
        }

        return null;
    }

    /**
     * Find Dolibarr product by SKU
     *
     * @param string $sku Product SKU
     * @return int|null Product ID or null
     */
    private function findDolibarrProductBySku($sku)
    {
        if (empty($sku)) {
            return null;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
        $sql .= " WHERE ref = '" . $this->db->escape($sku) . "'";
        $sql .= " AND entity IN (" . getEntity('product') . ")";
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            return (int)$obj->rowid;
        }

        return null;
    }

    /**
     * Find existing mapping by Dolibarr product ID
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @return object|null Mapping object or null
     * @since 2.1.9
     */
    private function findMappingByDolibarrProductId($dolibarrProductId)
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE fk_product = " . (int)$dolibarrProductId;
        $sql .= " AND entity = " . (int)$this->entity;
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            return $this->db->fetch_object($result);
        }

        return null;
    }

    /**
     * Create a simple Dolibarr product (no variants)
     *
     * @param object $shopifyProduct Shopify product
     * @param object|null $variant Primary variant
     * @return int Dolibarr product ID
     */
    private function createDolibarrSimpleProduct($shopifyProduct, $variant = null)
    {
        global $conf;

        $shopifyProductId = $this->extractNumericId($shopifyProduct->id, 'Product');
        $sku = $variant->sku ?? $shopifyProduct->handle ?? 'SHOP-' . $shopifyProductId;

        $this->log("Création produit simple: $sku", LOG_INFO);

        // Check if SKU already exists
        $existingProductId = $this->findDolibarrProductBySku($sku);
        if ($existingProductId) {
            $this->log("SKU déjà existant, mise à jour: $sku (ID: $existingProductId)", LOG_INFO);
            return $this->updateDolibarrProductData($existingProductId, $shopifyProduct, $variant);
        }

        // Get sync options for conditional synchronization (consistency with export)
        $syncOptions = $this->getSyncOptions();

        $product = new Product($this->db);
        $product->ref = $sku;
        $product->label = $shopifyProduct->title ?? $sku;
        $product->type = 0; // Product (not service)
        $product->status = ($shopifyProduct->status === 'ACTIVE') ? 1 : 0;
        $product->status_buy = 1;
        $product->entity = $this->entity;

        // Description conditionnelle (comme l'export)
        if ($syncOptions['sync_descriptions']) {
            $product->description = $this->sanitizeHtml($shopifyProduct->bodyHtml ?? $shopifyProduct->descriptionHtml ?? '');
        }

        // Prix conditionnel (comme l'export) — Story 53-5 (site 2d, 1/4) via SyncFlowPolicy
        if ($this->isImportFlowAllowed(SyncFlowPolicy::FLOW_PRICE) && $variant && !empty($variant->price)) {
            $product->price = floatval($variant->price);
        }

        // Attributs conditionnels : poids et barcode (comme l'export)
        if ($syncOptions['sync_attributes'] && $variant) {
            // Weight is now in inventoryItem.measurement.weight (API 2024-01+)
            if (!empty($variant->inventoryItem->measurement->weight->value)) {
                $product->weight = $this->convertWeight(
                    $variant->inventoryItem->measurement->weight->value,
                    $variant->inventoryItem->measurement->weight->unit ?? 'KILOGRAMS'
                );
                $product->weight_units = 0; // kg
            }
            if (!empty($variant->barcode)) {
                $product->barcode = $variant->barcode;
            }
        }

        // Create product
        $dolibarrProductId = $product->create($this->user);

        if ($dolibarrProductId < 0) {
            throw new Exception("Failed to create product: " . implode(', ', $product->errors));
        }

        // Save mapping
        $shopifyVariantId = $variant ? $this->extractNumericId($variant->id, 'ProductVariant') : null;
        $this->saveProductMapping($dolibarrProductId, $shopifyProductId, $shopifyVariantId);

        // Handle stock if tracked (vérifie SYNC_STOCK)
        if ($syncOptions['sync_stock'] && $variant && !empty($variant->inventoryQuantity)) {
            $this->updateProductStock($dolibarrProductId, $variant->inventoryQuantity);
        }

        $this->log("Produit créé avec succès: ID $dolibarrProductId", LOG_INFO);

        return $dolibarrProductId;
    }

    /**
     * Create a Dolibarr product with variants
     *
     * @param object $shopifyProduct Shopify product
     * @param array $variants Shopify variants
     * @return int Dolibarr parent product ID
     */
    private function createDolibarrProductWithVariants($shopifyProduct, $variants)
    {
        $shopifyProductId = $this->extractNumericId($shopifyProduct->id, 'Product');

        $this->log("Création produit avec variants: " . $shopifyProduct->title . " (" . count($variants) . " variants)", LOG_INFO);

        // Create parent product using product handle as SKU (unique per product in Shopify)
        $firstVariant = $variants[0] ?? null;
        $parentSku = $shopifyProduct->handle ?? $firstVariant->sku ?? 'SHOP-' . $shopifyProductId;

        // Check if parent already exists (try current SKU, then legacy -PARENT suffix)
        $existingParentId = $this->findDolibarrProductBySku($parentSku);
        if (!$existingParentId) {
            // v2.1.9: Rétrocompatibilité — chercher l'ancien format -PARENT (v2.1.6-v2.1.8)
            $legacyParentSku = $parentSku . '-PARENT';
            $existingParentId = $this->findDolibarrProductBySku($legacyParentSku);
            if ($existingParentId) {
                // Vérifier qu'aucun produit n'existe déjà avec le nouveau SKU avant de renommer
                $conflictId = $this->findDolibarrProductBySku($parentSku);
                if ($conflictId) {
                    $this->log("Parent legacy trouvé ($legacyParentSku) mais SKU cible ($parentSku) déjà pris par produit #$conflictId — renommage annulé", LOG_WARNING);
                } else {
                    $this->log("Parent trouvé avec ancien SKU legacy: $legacyParentSku — renommage vers $parentSku", LOG_INFO);
                    $parentObj = new Product($this->db);
                    if ($parentObj->fetch($existingParentId) > 0) {
                        $parentObj->ref = $parentSku;
                        $renameResult = $parentObj->update($parentObj->id, $this->user);
                        if ($renameResult < 0) {
                            $this->log("Échec renommage parent $legacyParentSku → $parentSku: " . implode(', ', $parentObj->errors), LOG_ERR);
                        } else {
                            $this->log("Parent renommé: $legacyParentSku → $parentSku", LOG_INFO);
                        }
                    }
                }
            }
        }

        if ($existingParentId) {
            $this->log("Parent SKU déjà existant: $parentSku (ID: $existingParentId)", LOG_INFO);
            $parentProductId = $existingParentId;
        } else {
            $parentProduct = new Product($this->db);
            $parentProduct->ref = $parentSku;
            $parentProduct->label = $shopifyProduct->title ?? $parentSku;
            $parentProduct->description = $this->sanitizeHtml($shopifyProduct->bodyHtml ?? $shopifyProduct->descriptionHtml ?? '');
            $parentProduct->type = 0;
            $parentProduct->status = ($shopifyProduct->status === 'ACTIVE') ? 1 : 0;
            $parentProduct->status_buy = 1;
            $parentProduct->entity = $this->entity;

            // Set price from first variant
            if ($firstVariant && !empty($firstVariant->price)) {
                $parentProduct->price = floatval($firstVariant->price);
            }

            $parentProductId = $parentProduct->create($this->user);

            if ($parentProductId < 0) {
                throw new Exception("Failed to create parent product: " . implode(', ', $parentProduct->errors));
            }
        }

        // Save mapping for parent (without variant ID)
        $this->saveProductMapping($parentProductId, $shopifyProductId, null, null);

        // Create variant products
        foreach ($variants as $variant) {
            $this->createVariantProduct($parentProductId, $shopifyProductId, $shopifyProduct, $variant);
        }

        $this->log("Produit avec variants créé: ID parent $parentProductId", LOG_INFO);

        return $parentProductId;
    }

    /**
     * Persist a new variant Product in Dolibarr (thin wrapper isolated for testability).
     *
     * @param Product $product Product object with ref/label/etc already populated
     * @return int Dolibarr product ID on success, <= 0 on failure ($product->error / $product->errors populated by Dolibarr)
     * @since 2.4.3
     */
    protected function persistNewDolibarrProduct($product)
    {
        return $product->create($this->user);
    }

    /**
     * Persist an updated Product in Dolibarr (thin wrapper isolated for testability — même
     * pattern que persistNewDolibarrProduct()). Story 63-17 : seam nécessaire pour vérifier par
     * un test réel (pas par réflexion sur une propriété) le libellé effectivement écrit par
     * updateDolibarrProductData() juste avant persistance, sans base de données.
     *
     * @param Product $product Product object with label/status/etc already populated
     * @return int Dolibarr update() return code (< 0 = échec)
     * @since 2.5.2
     */
    protected function persistUpdatedDolibarrProduct($product)
    {
        return $product->update($product->id, $this->user);
    }

    /**
     * Indique si le module Variants Dolibarr est actif (wrapper protégé pour testabilité :
     * override via onlyMethods(['isVariantsModuleEnabled']) dans les tests, sans dépendre
     * de l'état global $conf).
     *
     * @return bool
     * @since 2.4.4
     */
    protected function isVariantsModuleEnabled()
    {
        return (bool) isModEnabled('variants');
    }

    /**
     * Relie un produit enfant Dolibarr DÉJÀ CRÉÉ à son parent via une vraie déclinaison
     * Dolibarr (ProductCombination + attributs/valeurs), à partir des options Shopify
     * sélectionnées de la variante.
     *
     * Filtre d'abord les options dont la valeur vaut 'Default Title'. Si la liste filtrée
     * est vide (aucune option distinctive), log WARNING et retourne true SANS rien écrire
     * dans product_attribute* (jamais de combinaison/attribut factice — cohérent avec le
     * repli de l'export ImportProducts::getVariantOptions()).
     *
     * Ne crée AUCUN nouveau Product (l'enfant existe déjà, cf createVariantProduct()).
     * Idempotent : réutilise attribut/valeur/combinaison déjà existants (ré-import).
     *
     * Visibilité `public` depuis le hotfix 2.4.4 (2/2, réparation batch des imports
     * existants) — était `protected` en 1/2. Aucun changement de comportement, seul
     * l'accès externe (VariantRepairService) change.
     *
     * @param int   $parentProductId Id Dolibarr du produit parent (existant)
     * @param int   $childProductId  Id Dolibarr du produit enfant/variant (déjà créé)
     * @param array $selectedOptions Options Shopify sélectionnées (objets {name, value})
     * @return bool true si succès (ou rien à faire), false si échec ($this->error rempli)
     * @since 2.4.4
     */
    public function linkDolibarrVariant($parentProductId, $childProductId, array $selectedOptions)
    {
        // Correction post-review 3-couches (2.4.4 2/2, convergence) : réinitialisé à CHAQUE appel,
        // positionné à true UNIQUEMENT dans les branches ci-dessous qui retournent true SANS
        // écrire de ProductCombination (module désactivé / aucune option distinctive). Les
        // branches d'idempotence (combinaison déjà existante) et de garde double-parent NE
        // positionnent PAS ce flag : une ProductCombination existe déjà en base pour ces cas.
        $this->lastLinkWasNoop = false;
        $this->lastLinkWasAlreadyLinked = false;
        $this->lastLinkWasWrongParent = false;

        if (!$this->isVariantsModuleEnabled()) {
            $this->log("Module variants désactivé — repli mapping seul, pas de déclinaison Dolibarr pour le produit #$childProductId", LOG_WARNING);
            $this->lastLinkWasNoop = true;
            return true;
        }

        // Filtrer les options 'Default Title' (repli export cohérent, cf getVariantOptions l.3244-3249)
        // Code review 3-couches (Edge Case Hunter) : une valeur vide ('') n'est pas non plus
        // une option distinctive exploitable — traitée comme 'Default Title'/null, pas de
        // combinaison/attribut factice pour une option sans valeur réelle.
        $realOptions = [];
        foreach ($selectedOptions as $option) {
            $value = $option->value ?? null;
            if ($value === null || $value === '' || $value === 'Default Title') {
                continue;
            }
            $realOptions[] = ['name' => $option->name ?? 'OPTION', 'value' => $value];
        }

        if (empty($realOptions)) {
            $this->log("Options toutes 'Default Title' — pas de déclinaison créée pour le produit #$childProductId (attendu, cf export)", LOG_WARNING);
            $this->lastLinkWasNoop = true;
            return true;
        }

        // Chargement des classes variants (dol_include_once, JAMAIS require_once DOL_DOCUMENT_ROOT
        // direct — en test DOL_DOCUMENT_ROOT pointe sur test/stubs/dolibarr qui n'a pas 'variants/',
        // dol_include_once est un no-op en test et s'appuie sur les stubs class_exists de bootstrap.php)
        dol_include_once('/variants/class/ProductAttribute.class.php');
        dol_include_once('/variants/class/ProductAttributeValue.class.php');
        dol_include_once('/variants/class/ProductCombination.class.php');
        dol_include_once('/variants/class/ProductCombination2ValuePair.class.php');

        // Code review 3-couches (Edge Case Hunter) : durcissement du helper pour la
        // réparation des imports déjà faits (story 2.4.4 2/2). Nul en 1/2 (l'enfant vient
        // d'être créé par createVariantProduct() et n'est encore rattaché à aucun parent),
        // mais un enfant DÉJÀ rattaché à un parent DIFFÉRENT ne doit JAMAIS recevoir une 2e
        // combinaison (un produit ne peut avoir qu'un seul parent de déclinaison).
        $existingParentCombination = new ProductCombination($this->db);
        $existingParentId = (int) $existingParentCombination->fetchByFkProductChild($childProductId);
        if ($existingParentId > 0 && $existingParentId !== (int) $parentProductId) {
            $this->log("Produit enfant #$childProductId déjà rattaché à un autre parent Dolibarr (#$existingParentId, attendu #$parentProductId) — skip, pas de 2e combinaison créée", LOG_WARNING);
            $this->lastLinkWasAlreadyLinked = true;
            $this->lastLinkWasWrongParent = true;
            return true;
        }

        // Story 63-17 (code review, HIGH-B) : l'enfant a déjà une VRAIE combinaison rattachée AU
        // BON parent — no-op COMPLET, AVANT même de calculer les features. Avant ce correctif,
        // seul un parent DIFFÉRENT court-circuitait ci-dessus ; un parent identique continuait
        // vers fetchByProductCombination2ValuePairs() (ligne plus bas), qui exige une égalité
        // EXACTE des paires attribut/valeur. Si le marchand renomme une valeur d'option Shopify
        // après le premier rattachement (ex. "Rouge" -> "Rouge Foncé"), cette recherche échoue et
        // le code tombait dans la branche de CRÉATION — 2 ProductCombination pour le MÊME enfant,
        // `llx_product_attribute_combination.fk_product_child` n'ayant AUCUNE contrainte UNIQUE
        // (cf install/mysql/tables/llx_product_attribute_combination-variants.key.sql, un simple
        // ADD INDEX). Ce no-op ignore délibérément les options courantes (compromis choisi par la
        // review pour éliminer le doublon — un renommage d'option ne met plus à jour les paires
        // déjà enregistrées, mais ne corrompt plus non plus les données).
        if ($existingParentId > 0) {
            $this->log("Produit enfant #$childProductId déjà rattaché au bon parent Dolibarr (#$parentProductId, combinaison #" . $existingParentCombination->id . ") — no-op complet, options Shopify courantes ignorées", LOG_DEBUG);
            $this->lastLinkWasAlreadyLinked = true;
            return true;
        }

        // Get-or-create attributs + valeurs, construit la map [fk_attr => fk_attr_val]
        $combinationFeatures = [];
        foreach ($realOptions as $option) {
            $attributeId = $this->findOrCreateProductAttribute($option['name']);
            if ($attributeId <= 0) {
                $this->error = "linkDolibarrVariant: impossible de créer/trouver l'attribut '" . $option['name'] . "'";
                $this->log($this->error, LOG_ERR);
                return false;
            }

            $attributeValueId = $this->findOrCreateProductAttributeValue($attributeId, $option['value']);
            if ($attributeValueId <= 0) {
                $this->error = "linkDolibarrVariant: impossible de créer/trouver la valeur '" . $option['value'] . "' (attribut #$attributeId)";
                $this->log($this->error, LOG_ERR);
                return false;
            }

            $combinationFeatures[$attributeId] = $attributeValueId;
        }

        // Idempotence : ne pas dupliquer une combinaison déjà existante pour ce parent
        $productCombination = new ProductCombination($this->db);
        $existingCombination = $productCombination->fetchByProductCombination2ValuePairs($parentProductId, $combinationFeatures);
        if ($existingCombination !== false && !empty($existingCombination->id)) {
            $this->log("Déclinaison déjà existante pour le produit #$childProductId (combinaison #" . $existingCombination->id . "), idempotence respectée", LOG_DEBUG);
            $this->lastLinkWasAlreadyLinked = true;
            return true;
        }

        // Combinaison bas niveau (PAS createProductCombination() — clone/update un Product,
        // cf Dev Notes ; l'enfant est déjà créé par createVariantProduct())
        // Code review 3-couches (Blind Hunter) : transaction explicite sur le multi-write
        // ProductCombination + boucle ProductCombination2ValuePair (transactions sur
        // multi-write, comme les autres écritures multi-tables du module).
        // Sur échec d'une paire, rollback : jamais de combinaison orpheline ni de paires
        // partielles (sinon fetchByProductCombination2ValuePairs exige un match exact et
        // accumule les doublons à chaque retry).
        $this->db->begin();

        $combination = new ProductCombination($this->db);
        $combination->fk_product_parent = (int) $parentProductId;
        $combination->fk_product_child = (int) $childProductId;
        $combination->entity = $this->entity; // AC 4bis: écraser explicitement l'entité

        $createResult = $combination->create($this->user);
        if ($createResult <= 0) {
            $this->db->rollback();
            $this->error = "linkDolibarrVariant: échec création ProductCombination: " . trim($combination->error . ' ' . implode(', ', (array) $combination->errors));
            $this->log($this->error, LOG_ERR);
            return false;
        }

        foreach ($combinationFeatures as $attributeId => $attributeValueId) {
            $pair = new ProductCombination2ValuePair($this->db);
            $pair->fk_prod_combination = $combination->id;
            $pair->fk_prod_attr = $attributeId;
            $pair->fk_prod_attr_val = $attributeValueId;

            $pairResult = $pair->create($this->user);
            if ($pairResult <= 0) {
                $this->db->rollback();
                $this->error = "linkDolibarrVariant: échec création ProductCombination2ValuePair (attr=$attributeId, val=$attributeValueId)";
                $this->log($this->error, LOG_ERR);
                return false;
            }
        }

        $this->db->commit();

        $this->log("Déclinaison créée: produit #$childProductId lié au parent #$parentProductId (combinaison #" . $combination->id . ")", LOG_INFO);

        return true;
    }

    /**
     * Get-or-create un ProductAttribute Dolibarr par nom (ref normalisée), filtré par entité.
     * Pas de fetchByRef natif côté core — recherche SQL directe.
     *
     * @param string $name Nom de l'option Shopify (ex. "Couleur")
     * @return int Id du ProductAttribute (>0) ou <=0 en cas d'échec
     * @since 2.4.4
     */
    private function findOrCreateProductAttribute($name)
    {
        $ref = strtoupper(dol_sanitizeFileName(dol_string_nospecial(trim($name))));
        if ($ref === '') {
            $ref = 'OPTION';
        }

        // Code review 3-couches (Blind Hunter) : recherche alignée sur l'entité d'ÉCRITURE
        // ($this->entity), pas sur getEntity('product') (= $conf->entity global). En
        // multi-entité, l'importer peut être construit avec $entity ≠ $conf->entity ; chercher
        // dans le mauvais scope créait des doublons attribut/valeur au ré-import.
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product_attribute";
        $sql .= " WHERE ref = '" . $this->db->escape($ref) . "'";
        $sql .= " AND entity = " . (int) $this->entity;
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            return (int) $obj->rowid;
        }

        // Code review 3-couches (Blind Hunter) : ->ref DOIT utiliser la ref normalisée+repli
        // ($ref, déjà calculée ci-dessus pour le SELECT), PAS le nom brut. Sinon, si $name
        // normalise en interne (par le create() du core) vers une chaîne vide (nom vide/tout-
        // spécial), la création échoue silencieusement (ErrorFieldRequired) avec une ref
        // différente de celle recherchée. ->label reste la valeur humaine brute.
        $attribute = new ProductAttribute($this->db);
        $attribute->ref = $ref;
        $attribute->label = $name;
        $attribute->entity = $this->entity; // AC 4bis: écraser explicitement l'entité

        $attributeId = $attribute->create($this->user);
        if ($attributeId <= 0) {
            $this->log("Échec création ProductAttribute '$name': " . trim($attribute->error . ' ' . implode(', ', (array) $attribute->errors)), LOG_ERR);
        }

        return $attributeId;
    }

    /**
     * Get-or-create un ProductAttributeValue Dolibarr par valeur (ref normalisée), pour un
     * attribut donné, filtré par entité. Pas de fetchByRef natif côté core — recherche SQL
     * directe.
     *
     * @param int    $attributeId Id du ProductAttribute parent
     * @param string $value       Valeur de l'option Shopify (ex. "Rouge")
     * @return int Id du ProductAttributeValue (>0) ou <=0 en cas d'échec
     * @since 2.4.4
     */
    private function findOrCreateProductAttributeValue($attributeId, $value)
    {
        $ref = strtoupper(dol_sanitizeFileName(dol_string_nospecial(trim($value))));
        if ($ref === '') {
            $ref = 'VALUE';
        }

        // Code review 3-couches (Blind Hunter) : recherche alignée sur l'entité d'écriture,
        // cf commentaire équivalent dans findOrCreateProductAttribute().
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product_attribute_value";
        $sql .= " WHERE fk_product_attribute = " . (int) $attributeId;
        $sql .= " AND ref = '" . $this->db->escape($ref) . "'";
        $sql .= " AND entity = " . (int) $this->entity;
        $sql .= " LIMIT 1";

        $result = $this->db->query($sql);
        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            return (int) $obj->rowid;
        }

        // Code review 3-couches (Blind Hunter) : ->ref = ref normalisée+repli ($ref), pas la
        // valeur brute — cf commentaire équivalent dans findOrCreateProductAttribute().
        // ->value reste la valeur humaine brute.
        $attributeValue = new ProductAttributeValue($this->db);
        $attributeValue->fk_product_attribute = (int) $attributeId;
        $attributeValue->ref = $ref;
        $attributeValue->value = $value;
        $attributeValue->entity = $this->entity; // AC 4bis: écraser explicitement l'entité

        $attributeValueId = $attributeValue->create($this->user);
        if ($attributeValueId <= 0) {
            $this->log("Échec création ProductAttributeValue '$value': " . trim($attributeValue->error . ' ' . implode(', ', (array) $attributeValue->errors)), LOG_ERR);
        }

        return $attributeValueId;
    }

    /**
     * Construit le libellé distinctif d'une variante (Bug A.2, hotfix 2.4.4).
     *
     * Cascade : options sélectionnées non-'Default Title' → variant->title (si distinctif)
     * → SKU (repli final, toujours défini et distinctif — inclut tout suffixe
     * anti-collision déjà appliqué par l'appelant). L'ancien code utilisait un `elseif`
     * qui devenait une branche morte quand `selectedOptions` était présent mais que
     * TOUTES les valeurs valaient 'Default Title' : le repli `variant->title` n'était
     * alors jamais tenté et le libellé variante restait identique au libellé parent.
     *
     * Visibilité `public` depuis le hotfix 2.4.4 (2/2, réparation batch) — était `private`
     * en 1/2. Aucun changement de comportement, requis par `VariantRepairService` pour
     * l'option `fixLabels` (recalcul du libellé distinctif sur les imports déjà faits).
     *
     * @param object $shopifyProduct Produit Shopify (pour ->title)
     * @param object $variant        Variante Shopify (->selectedOptions, ->title)
     * @param string $sku            SKU final de la variante (déjà résolu par l'appelant)
     * @return string Libellé complet "Titre parent - <suffixe distinctif>"
     * @since 2.4.4
     */
    public function buildVariantLabel($shopifyProduct, $variant, $sku)
    {
        $variantLabel = $shopifyProduct->title ?? '';

        $optionValues = [];
        if (!empty($variant->selectedOptions)) {
            foreach ($variant->selectedOptions as $option) {
                if ($option->value !== 'Default Title') {
                    $optionValues[] = $option->value;
                }
            }
        }

        if (!empty($optionValues)) {
            $distinctiveSuffix = implode(' / ', $optionValues);
        } elseif (!empty($variant->title) && $variant->title !== 'Default Title') {
            $distinctiveSuffix = $variant->title;
        } else {
            $distinctiveSuffix = $sku;
        }

        return $variantLabel . ' - ' . $distinctiveSuffix;
    }

    /**
     * Create a variant product in Dolibarr
     *
     * @param int $parentProductId Dolibarr parent product ID
     * @param string $shopifyProductId Shopify product ID
     * @param object $shopifyProduct Shopify product data
     * @param object $variant Shopify variant data
     * @return int Dolibarr variant product ID
     */
    private function createVariantProduct($parentProductId, $shopifyProductId, $shopifyProduct, $variant)
    {
        $shopifyVariantId = $this->extractNumericId($variant->id, 'ProductVariant');
        $sku = $variant->sku ?? 'SHOP-VAR-' . $shopifyVariantId;

        // Check if variant already exists (mais pas si c'est le parent lui-même)
        $existingProductId = $this->findDolibarrProductBySku($sku);
        if ($existingProductId && $existingProductId !== $parentProductId) {
            // v2.1.9: Vérifier que ce produit n'appartient pas déjà à un autre parent Shopify
            $existingVariantMapping = $this->findMappingByDolibarrProductId($existingProductId);
            if ($existingVariantMapping && $existingVariantMapping->shopifyProductId != $shopifyProductId) {
                $this->log("SKU $sku déjà utilisé par un autre produit Shopify (#" . $existingVariantMapping->shopifyProductId . ") — création avec SKU alternatif", LOG_WARNING);
                $sku = $sku . '-V' . $shopifyVariantId;
            } else {
                $this->log("Variant SKU déjà existant: $sku (ID: $existingProductId)", LOG_DEBUG);
                $this->saveProductMapping($existingProductId, $shopifyProductId, $shopifyVariantId, $parentProductId);
                return $existingProductId;
            }
        } elseif ($existingProductId === $parentProductId) {
            // SKU du variant identique au parent — suffixer pour éviter la collision
            $sku = $sku . '-V' . $shopifyVariantId;
            $this->log("Collision SKU variant/parent détectée, SKU variant ajusté: $sku", LOG_WARNING);
            // Re-check avec le nouveau SKU
            $existingProductId = $this->findDolibarrProductBySku($sku);
            if ($existingProductId) {
                $this->saveProductMapping($existingProductId, $shopifyProductId, $shopifyVariantId, $parentProductId);
                return $existingProductId;
            }
        }

        // Build variant label from options — cascade A.2 (fix hotfix 2.4.4), cf buildVariantLabel()
        $variantLabel = $this->buildVariantLabel($shopifyProduct, $variant, $sku);

        // Get sync options for conditional synchronization (consistency with export)
        $syncOptions = $this->getSyncOptions();

        $product = new Product($this->db);
        $product->ref = $sku;
        $product->label = $variantLabel;
        $product->type = 0;
        $product->status = ($shopifyProduct->status === 'ACTIVE') ? 1 : 0;
        $product->status_buy = 1;
        $product->entity = $this->entity;
        // NOTE hotfix 2.4.4 (Bug A.1) : l'ancienne ligne `$product->fk_product_parent = $parentProductId;`
        // était une propriété fantôme (n'existe pas sur llx_product) — supprimée. La vraie
        // déclinaison Dolibarr (product_attribute_combination) est créée par linkDolibarrVariant()
        // après la création réussie du produit enfant, cf plus bas.

        // Description conditionnelle (comme l'export)
        if ($syncOptions['sync_descriptions']) {
            $product->description = $this->sanitizeHtml($shopifyProduct->bodyHtml ?? $shopifyProduct->descriptionHtml ?? '');
        }

        // Prix conditionnel (comme l'export) — Story 53-5 (site 2d, 2/4) via SyncFlowPolicy
        if ($this->isImportFlowAllowed(SyncFlowPolicy::FLOW_PRICE) && !empty($variant->price)) {
            $product->price = floatval($variant->price);
        }

        // Attributs conditionnels : poids et barcode (comme l'export)
        if ($syncOptions['sync_attributes']) {
            // Weight is now in inventoryItem.measurement.weight (API 2024-01+)
            if (!empty($variant->inventoryItem->measurement->weight->value)) {
                $product->weight = $this->convertWeight(
                    $variant->inventoryItem->measurement->weight->value,
                    $variant->inventoryItem->measurement->weight->unit ?? 'KILOGRAMS'
                );
                $product->weight_units = 0; // kg
            }
            if (!empty($variant->barcode)) {
                $product->barcode = $variant->barcode;
            }
        }

        $dolibarrProductId = $this->persistNewDolibarrProduct($product);

        if ($dolibarrProductId <= 0) {
            // v2.4.3: 0 est un échec ambigu Dolibarr (create() peut renvoyer 0 sans erreur explicite)
            // v2.4.3 code review CRITICAL: la réutilisation n'est tentée QUE si l'échec est un vrai
            // doublon de ref (ErrorProductAlreadyExists). Product::create() peut échouer pour
            // d'autres raisons (barcode déjà utilisé, trigger PRODUCT_CREATE, ref invalide...) —
            // ces échecs ne doivent JAMAIS être masqués par une réutilisation silencieuse.
            $isDuplicateRef = ($product->error === 'ErrorProductAlreadyExists')
                || in_array('ErrorProductAlreadyExists', (array) $product->errors, true);

            if ($isDuplicateRef) {
                $existingBySku = $this->findDolibarrProductBySku($product->ref);
                if ($existingBySku) {
                    // v2.4.3 code review HIGH: garde anti-collision croisée, même logique que le
                    // pré-check (l.1026-1036) — un SKU déjà mappé à un AUTRE produit Shopify ne doit
                    // jamais être réutilisé silencieusement (corruption croisée entre produits).
                    $existingMapping = $this->findMappingByDolibarrProductId($existingBySku);
                    if ($existingMapping && $existingMapping->shopifyProductId != $shopifyProductId) {
                        throw new Exception(
                            "Failed to create variant product (ref=" . $product->ref . "): "
                            . "SKU déjà mappé au produit Shopify #" . $existingMapping->shopifyProductId
                            . ", réutilisation refusée pour éviter une corruption croisée"
                        );
                    }

                    // Résilience idempotente : re-livraison webhook ou import partiel ont pu
                    // empêcher le pré-check (l.1025) de détecter le SKU avant la création
                    // (même entité — le décalage d'entité est structurellement impossible ici,
                    // create() et findDolibarrProductBySku utilisent le même getEntity('product')).
                    $this->log("createVariantProduct - SKU déjà présent après échec de création, réutilisation: " . $product->ref . " (ID: $existingBySku)", LOG_WARNING);
                    $this->saveProductMapping($existingBySku, $shopifyProductId, $shopifyVariantId, $parentProductId);
                    return $existingBySku;
                }
            }

            $errDetail = trim($product->error . ' ' . implode(', ', (array) $product->errors));
            throw new Exception("Failed to create variant product (ref=" . $product->ref . "): " . ($errDetail !== '' ? $errDetail : 'raison inconnue (create=' . $dolibarrProductId . ')'));
        }

        // Save mapping
        $this->saveProductMapping($dolibarrProductId, $shopifyProductId, $shopifyVariantId, $parentProductId);

        // Hotfix 2.4.4 (Bug A.1) : créer la vraie déclinaison Dolibarr (attributs/valeurs +
        // ProductCombination) reliant l'enfant à son parent. Uniquement au point de succès
        // de la création (PAS dans les branches de retour anticipé ci-dessus — SKU déjà
        // existant ou réutilisation après échec : ces produits déjà présents relèvent de
        // la réparation, story 2.4.4 2/2).
        if (!$this->linkDolibarrVariant($parentProductId, $dolibarrProductId, (array) ($variant->selectedOptions ?? []))) {
            $this->log("Échec liaison déclinaison Dolibarr pour le produit #$dolibarrProductId: " . $this->error, LOG_ERR);
        }

        // Handle stock
        if (!empty($variant->inventoryQuantity) && getDolGlobalInt('DOLI2SHOP_SYNC_STOCK')) {
            $this->updateProductStock($dolibarrProductId, $variant->inventoryQuantity);
        }

        $this->log("Variant créé: $sku (ID: $dolibarrProductId)", LOG_DEBUG);

        return $dolibarrProductId;
    }

    /**
     * Update an existing Dolibarr product
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param object $shopifyProduct Shopify product
     * @param array $variants Shopify variants
     * @return int Dolibarr product ID
     */
    private function updateDolibarrProduct($dolibarrProductId, $shopifyProduct, $variants)
    {
        $this->log("Mise à jour produit Dolibarr: ID $dolibarrProductId", LOG_INFO);

        // Get sync options for conditional synchronization (consistency with export)
        $syncOptions = $this->getSyncOptions();

        $product = new Product($this->db);
        if ($product->fetch($dolibarrProductId) <= 0) {
            throw new Exception("Product not found in Dolibarr: $dolibarrProductId");
        }

        // Label toujours synchronisé (identifiant)
        $product->label = $shopifyProduct->title ?? $product->label;
        $product->status = ($shopifyProduct->status === 'ACTIVE') ? 1 : 0;

        // Description conditionnelle (comme l'export)
        if ($syncOptions['sync_descriptions']) {
            $product->description = $this->sanitizeHtml($shopifyProduct->bodyHtml ?? $shopifyProduct->descriptionHtml ?? $product->description);
        }

        // Update from first variant
        $firstVariant = $variants[0] ?? null;
        if ($firstVariant) {
            // Prix conditionnel (comme l'export) — Story 53-5 (site 2d, 3/4) via SyncFlowPolicy
            if ($this->isImportFlowAllowed(SyncFlowPolicy::FLOW_PRICE) && !empty($firstVariant->price)) {
                $product->price = floatval($firstVariant->price);
            }
            // Attributs conditionnels : poids et barcode (comme l'export)
            if ($syncOptions['sync_attributes']) {
                // Weight is now in inventoryItem.measurement.weight (API 2024-01+)
                if (!empty($firstVariant->inventoryItem->measurement->weight->value)) {
                    $product->weight = $this->convertWeight(
                        $firstVariant->inventoryItem->measurement->weight->value,
                        $firstVariant->inventoryItem->measurement->weight->unit ?? 'KILOGRAMS'
                    );
                }
                if (!empty($firstVariant->barcode)) {
                    $product->barcode = $firstVariant->barcode;
                }
            }
        }

        $result = $product->update($dolibarrProductId, $this->user);

        if ($result < 0) {
            throw new Exception("Failed to update product: " . implode(', ', $product->errors));
        }

        // Update variants if the product has real variant structure — count($variants) > 1 OU
        // une SEULE variante mais avec au moins une option Shopify nommée distinctive
        // (`hasRealOptions()`, ex. "Couleur: Rouge") — Story 63-17, code review HIGH-D.
        //
        // AVANT ce correctif, seul `count($variants) > 1` déclenchait la boucle : un produit
        // réduit à 1 seule variante mais avec une vraie option (créé ainsi côté marchand, ou
        // réduit de 2 à 1 variante après suppression) a une structure parent+enfant existante en
        // base (`ProductCombination`), mais n'atteignait JAMAIS `updateOrCreateVariant()` ni
        // `updateDolibarrProductData()` — le défaut Cause 1 (libellé écrasé par le titre du
        // parent) restait donc actif INDÉFINIMENT pour toute cette classe de produits à chaque
        // `products/update`, seul le batch de réparation admin pouvant les rattraper (jamais le
        // flux webhook, exactement le rapport client DataImpuls).
        //
        // AC1 (produit simple réel, non cassé) : `hasRealOptions()` retourne `false` dès que la
        // seule option est 'Title'/vide (structure "Default Title" d'un vrai produit simple) —
        // le comportement d'un produit simple reste donc strictement inchangé.
        //
        // ⚠️ EFFET DE BORD ASSUMÉ (arbitré par le mainteneur le 22/08/2026, documenté au ChangeLog
        // 2.5.2 à sa demande). Cette condition est désormais **identique** à celle qui pilote la
        // CRÉATION (`$hasVariants`, voir `:823`) : le chemin de mise à jour et le chemin d'import
        // cessent de diverger. Conséquence pour un cas qui n'existait pas avant ce correctif : un
        // produit importé à l'origine SANS option (donc créé comme produit simple, sans structure
        // parent/enfant) auquel le marchand AJOUTE ensuite une option nommée côté Shopify entre
        // maintenant dans cette boucle ; `updateOrCreateVariant()` ne trouvera aucun mapping pour la
        // variante et tombera donc sur `createVariantProduct()` — Dolibarr CRÉERA la déclinaison
        // manquante au lieu de laisser le produit simple indéfiniment.
        //
        // C'est le comportement voulu (l'import l'aurait créée ainsi si l'option avait existé au
        // premier passage), mais c'est plus que ce que la story 63-17 promettait — d'où cette note :
        // si un défaut est signalé un jour sur « un produit simple devenu produit à déclinaison
        // tout seul », c'est ICI qu'il faut regarder, et le correctif serait de borner ce cas
        // (n'entrer dans la boucle que si une structure parent/enfant existe déjà), PAS de revenir
        // à `count($variants) > 1` — ce qui réarmerait le défaut HIGH-D corrigé ci-dessus.
        if (count($variants) > 1 || $this->hasRealOptions($shopifyProduct)) {
            foreach ($variants as $variant) {
                $this->updateOrCreateVariant($dolibarrProductId, $shopifyProduct, $variant);
            }
        }

        return $dolibarrProductId;
    }

    /**
     * Update or create a variant for an existing parent product
     *
     * @param int $parentProductId Dolibarr parent product ID
     * @param object $shopifyProduct Shopify product
     * @param object $variant Shopify variant
     * @return int Dolibarr variant product ID
     */
    private function updateOrCreateVariant($parentProductId, $shopifyProduct, $variant)
    {
        $shopifyProductId = $this->extractNumericId($shopifyProduct->id, 'Product');
        $shopifyVariantId = $this->extractNumericId($variant->id, 'ProductVariant');

        // Check if variant is already mapped
        $sql = "SELECT fk_product FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE shopifyVariantId = '" . $this->db->escape($shopifyVariantId) . "'";
        $sql .= " AND entity = " . (int)$this->entity;

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            // Story 63-17 (Cause 1) : $parentProductId est connu ICI (paramètre de la méthode) —
            // c'est le fait qui distingue une variante d'un produit simple, transmis à
            // updateDolibarrProductData() pour qu'elle recalcule le libellé via buildVariantLabel()
            // au lieu d'écraser le libellé enfant avec le titre du parent (cf docblock plus bas).
            return $this->updateDolibarrProductData($obj->fk_product, $shopifyProduct, $variant, $parentProductId);
        }

        // Create new variant
        return $this->createVariantProduct($parentProductId, $shopifyProductId, $shopifyProduct, $variant);
    }

    /**
     * Update Dolibarr product data from Shopify
     *
     * Story 63-17 (Cause 1 — régression) : le hotfix 2.4.4 n'avait corrigé que le chemin de
     * CRÉATION (createVariantProduct(), via buildVariantLabel()). Ce chemin de MISE À JOUR
     * réécrivait le libellé de la variante avec `$shopifyProduct->title` (= titre du PARENT)
     * à chaque `products/update`, annulant indéfiniment le travail de la 2.4.4.
     *
     * Critère « est-ce une variante » (AC2, réécrit après Validate du 21/08) : **PAS** la
     * présence de `$variant`. ⚠️ Correction post-review (LOW-F) : le docblock affirmait ici que
     * les deux appelants passent TOUJOURS un `$variant` non nul — c'est FAUX pour
     * `createDolibarrSimpleProduct($shopifyProduct, $variants[0] ?? null)` (`:812`), qui transmet
     * bien `null` quand `$variants` est vide. Seul `updateOrCreateVariant()` garantit un `$variant`
     * non nul. Cette inexactitude ne change rien au critère retenu, indépendant de `$variant` :
     * `$parentProductId`, connu de l'appelant. `updateOrCreateVariant()` le transmet (c'est le
     * parent Dolibarr qu'il traite), `createDolibarrSimpleProduct()` ne le transmet jamais
     * (produit simple, pas de parent) — aucun risque de code mort ni de régression croisée sur le
     * produit simple (AC1), que `$variant` soit nul ou non chez cet appelant.
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param object $shopifyProduct Shopify product
     * @param object|null $variant Shopify variant
     * @param int|null $parentProductId Id Dolibarr du produit PARENT si $dolibarrProductId est
     *                                   une variante déjà mappée (connu de updateOrCreateVariant()) ;
     *                                   null/absent pour un produit simple — comportement
     *                                   strictement inchangé (AC1)
     * @return int Dolibarr product ID
     * @since 2.5.2 paramètre $parentProductId (Story 63-17)
     */
    private function updateDolibarrProductData($dolibarrProductId, $shopifyProduct, $variant = null, $parentProductId = null)
    {
        // Get sync options for conditional synchronization (consistency with export)
        $syncOptions = $this->getSyncOptions();

        $product = new Product($this->db);
        if ($product->fetch($dolibarrProductId) <= 0) {
            throw new Exception("Product not found: $dolibarrProductId");
        }

        if ((int) $parentProductId > 0 && $variant) {
            // Variante déjà mappée : recalculer le libellé distinctif comme au moment de la
            // création (buildVariantLabel = titre parent + suffixe options/titre/SKU), JAMAIS
            // le titre du parent seul — c'est exactement la régression Cause 1.
            $sku = $variant->sku ?? $product->ref;
            $product->label = $this->buildVariantLabel($shopifyProduct, $variant, $sku);
        } else {
            // Produit simple ou parent : label toujours synchronisé (identifiant) — comportement
            // strictement inchangé (AC1).
            $product->label = $shopifyProduct->title ?? $product->label;
        }
        $product->status = ($shopifyProduct->status === 'ACTIVE') ? 1 : 0;

        // Description conditionnelle (comme l'export)
        if ($syncOptions['sync_descriptions']) {
            $product->description = $this->sanitizeHtml($shopifyProduct->bodyHtml ?? $shopifyProduct->descriptionHtml ?? $product->description);
        }

        if ($variant) {
            // Prix conditionnel (comme l'export) — Story 53-5 (site 2d, 4/4) via SyncFlowPolicy
            if ($this->isImportFlowAllowed(SyncFlowPolicy::FLOW_PRICE) && !empty($variant->price)) {
                $product->price = floatval($variant->price);
            }
            // Attributs conditionnels : poids et barcode (comme l'export)
            if ($syncOptions['sync_attributes']) {
                // Weight is now in inventoryItem.measurement.weight (API 2024-01+)
                if (!empty($variant->inventoryItem->measurement->weight->value)) {
                    $product->weight = $this->convertWeight(
                        $variant->inventoryItem->measurement->weight->value,
                        $variant->inventoryItem->measurement->weight->unit ?? 'KILOGRAMS'
                    );
                }
                if (!empty($variant->barcode)) {
                    $product->barcode = $variant->barcode;
                }
            }
        }

        $result = $this->persistUpdatedDolibarrProduct($product);

        if ($result < 0) {
            throw new Exception("Failed to update product: " . implode(', ', $product->errors));
        }

        // Update mapping
        $shopifyProductId = $this->extractNumericId($shopifyProduct->id, 'Product');
        $shopifyVariantId = $variant ? $this->extractNumericId($variant->id, 'ProductVariant') : null;
        $this->saveProductMapping($dolibarrProductId, $shopifyProductId, $shopifyVariantId);

        return $dolibarrProductId;
    }

    /**
     * Save product mapping to database
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param string $shopifyProductId Shopify product ID
     * @param string|null $shopifyVariantId Shopify variant ID
     * @param int|null $parentProductId Dolibarr parent product ID for variants
     * @return void
     */
    private function saveProductMapping($dolibarrProductId, $shopifyProductId, $shopifyVariantId = null, $parentProductId = null)
    {
        $this->log("Sauvegarde mapping: Dolibarr=$dolibarrProductId, Shopify=$shopifyProductId, Variant=$shopifyVariantId, Parent=$parentProductId", LOG_DEBUG);

        $fkStore = (int)$this->shopifyApi->getStoreId();

        // Verrou advisory MySQL (Story cle-unique-doli2shop-products-null-fk-product-parent, AC1) :
        // la course porte sur la séquence DELETE puis INSERT ci-dessous (v2.1.9) — deux appels
        // concurrents peuvent chacun supprimer la ligne de l'autre avant que l'un des deux INSERT
        // n'ait eu lieu. Même mécanisme et même scope que
        // ImportProducts::manageProductMapping() (fk_product + fk_store, entité ajoutée
        // automatiquement par CronHelperTrait::buildCronLockName()) : les deux méthodes écrivent la
        // même table et doivent se sérialiser l'une contre l'autre, pas seulement contre
        // elles-mêmes.
        $lockName = 'product_map_' . (int)$dolibarrProductId . '_' . $fkStore;
        // Timeout 3s : cf. justification dans ImportProducts::manageProductMapping() (même
        // constante, même raisonnement — ni blocage indéfini, ni échec quasi systématique).
        $lockTimeout = 3;

        if (!$this->acquireCronLock($lockName, $lockTimeout)) {
            // AC1 : JAMAIS de skip silencieux — un mapping jamais créé serait pire que le doublon
            // que ce correctif ferme.
            $error = "Verrou non acquis pour product_map fk_product=$dolibarrProductId fk_store=$fkStore"
                . " (entity=" . $this->entity . ") : abandon (course avec un autre appelant, verrou"
                . " déjà détenu au-delà du timeout de {$lockTimeout}s)";
            $this->log($error, LOG_ERR);
            throw new Exception($error);
        }

        try {
            // v2.1.9: Stratégie en 2 temps pour contourner la clé unique (fk_product, entity, fk_product_parent)
            // Si fk_product_parent change, la clé composite change → INSERT au lieu d'UPDATE → doublon.
            // On supprime d'abord l'ancien mapping du même fk_product pour cette entity.
            // Story 47-3: filtrer aussi par fk_store pour ne pas effacer les mappings des autres boutiques.
            // Rétrocompat : en chemin historique/fallback (fkStore=0), NE PAS filtrer sur fk_store —
            // sinon le DELETE ne matcherait pas les lignes backfillées (fk_store>0) et l'INSERT créerait
            // un doublon. fkStore=0 ⇒ comportement pré-47-3 (DELETE par fk_product+entity).
            $sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products";
            $sqlDelete .= " WHERE fk_product = " . (int)$dolibarrProductId;
            $sqlDelete .= " AND entity = " . (int)$this->entity;
            if ($fkStore > 0) {
                $sqlDelete .= " AND fk_store = " . $fkStore;
            }
            $this->db->query($sqlDelete);

            // INSERT le mapping à jour (avec fk_store pour traçabilité par boutique)
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_products";
            $sql .= " (fk_product, shopifyProductId, shopifyVariantId, fk_product_parent, entity, fk_store, last_sync_status, tms)";
            $sql .= " VALUES (";
            $sql .= (int)$dolibarrProductId . ", ";
            $sql .= "'" . $this->db->escape($shopifyProductId) . "', ";
            $sql .= ($shopifyVariantId ? "'" . $this->db->escape($shopifyVariantId) . "'" : "NULL") . ", ";
            $sql .= ($parentProductId ? (int)$parentProductId : "NULL") . ", ";
            $sql .= (int)$this->entity . ", ";
            $sql .= $fkStore . ", ";
            $sql .= "'success', ";
            $sql .= "'" . $this->db->idate(dol_now()) . "'";
            $sql .= ")";

            $result = $this->db->query($sql);

            if (!$result) {
                // AC3 : lire lasterrno() IMMÉDIATEMENT — rien d'autre n'a requêté $this->db entre
                // l'échec du query() et cette lecture. DoliDB normalise l'errno MySQL/MariaDB 1062
                // en la chaîne 'DB_ERROR_RECORD_ALREADY_EXISTS' (core/db/mysqli.class.php, vérifié
                // sur Dolibarr 18/23/24) — jamais l'entier 1062 brut.
                if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                    // Absorption : un appelant concurrent a gagné la course entre notre DELETE et
                    // notre INSERT (fenêtre résiduelle, ex. un tiers non couvert par ce verrou).
                    // MEDIUM (review 3 couches, 27/09/2026) : la ligne survivante porte les données
                    // du GAGNANT, pas forcément les NÔTRES (le nôtre — le perdant — peut avoir reçu
                    // un shopifyProductId/shopifyVariantId/parentProductId différent, ex. deux
                    // webhooks quasi simultanés portant des payloads distincts) : réappliquer notre
                    // propre charge par un UPDATE de secours, symétrique à celui de
                    // manageProductMapping() sur ce même cas. Filtre fk_store conditionnel comme
                    // partout ailleurs dans cette méthode (invariant CLAUDE.dolibarr.md §14).
                    // Best-effort : un échec de CETTE UPDATE ne doit pas faire régresser
                    // l'absorption en échec de synchro (le mapping existe déjà, c'est l'essentiel).
                    $sqlUpdateAfterAbsorb = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_products";
                    $sqlUpdateAfterAbsorb .= " SET shopifyProductId = '" . $this->db->escape($shopifyProductId) . "'";
                    $sqlUpdateAfterAbsorb .= ", shopifyVariantId = " . ($shopifyVariantId ? "'" . $this->db->escape($shopifyVariantId) . "'" : "NULL");
                    $sqlUpdateAfterAbsorb .= ", fk_product_parent = " . ($parentProductId ? (int)$parentProductId : "NULL");
                    $sqlUpdateAfterAbsorb .= ", last_sync_status = 'success'";
                    $sqlUpdateAfterAbsorb .= ", tms = '" . $this->db->idate(dol_now()) . "'";
                    $sqlUpdateAfterAbsorb .= " WHERE fk_product = " . (int)$dolibarrProductId;
                    $sqlUpdateAfterAbsorb .= " AND entity = " . (int)$this->entity;
                    if ($fkStore > 0) {
                        $sqlUpdateAfterAbsorb .= " AND fk_store = " . $fkStore;
                    }
                    $updateAfterAbsorbResult = $this->db->query($sqlUpdateAfterAbsorb);
                    if (!$updateAfterAbsorbResult) {
                        $this->log("saveProductMapping - Échec de la ré-application (best-effort) après absorption"
                            . " pour fk_product=$dolibarrProductId fk_store=$fkStore : " . $this->db->lasterror(), LOG_WARNING);
                    }

                    $this->log("saveProductMapping - Collision absorbée (DB_ERROR_RECORD_ALREADY_EXISTS)"
                        . " pour fk_product=$dolibarrProductId fk_store=$fkStore : mapping déjà recréé"
                        . " par un appel concurrent, notre charge ré-appliquée sur la ligne survivante", LOG_DEBUG);
                } else {
                    // Toute autre erreur remonte comme avant (pas de masquage).
                    $error = "Erreur sauvegarde mapping produit $dolibarrProductId: " . $this->db->lasterror();
                    $this->log($error, LOG_ERR);
                    throw new Exception($error);
                }
            }
        } finally {
            // Libération garantie sur TOUS les chemins de sortie (succès, absorption, exception).
            $this->releaseCronLock($lockName);
        }

        // ====================================================================
        // Epic 47, Story 47-5 : Tag catégorie produit par boutique (non bloquant)
        //
        // Rétrocompat ABSOLUE : skip si store->fk_categorie == DOLI2SHOP_DOLIBARR_PROCATE
        // (boutique par défaut mono-boutique → ne PAS re-catégoriser les produits existants).
        // Si un client a manuellement assigné fk_categorie à une valeur ≠ PROCATE sur la
        // boutique par défaut, le tag sera appliqué (comportement assumé, cas marginal).
        // ====================================================================
        try {
            $fkStoreForProd = (int) $fkStore; // $fkStore est défini plus haut dans cette méthode
            if ($fkStoreForProd > 0) {
                $storeServiceForProd = new StoreService($this->db, $this->entity);
                $storeForProd        = $storeServiceForProd->fetch($fkStoreForProd);
                if ($storeForProd !== null) {
                    $storeFkCategorie = isset($storeForProd->fk_categorie) ? (int) $storeForProd->fk_categorie : 0;
                    $procate          = getDolGlobalInt('DOLI2SHOP_DOLIBARR_PROCATE', 0);

                    // Skip si fk_categorie non défini OU si c'est la catégorie PROCATE (rétrocompat)
                    if ($storeFkCategorie > 0 && $storeFkCategorie !== $procate) {
                        dol_include_once('/product/class/product.class.php');
                        $productObj = new Product($this->db);
                        if ($productObj->fetch((int) $dolibarrProductId) > 0) {
                            $catHelperProd = new StoreCategoryHelper($this->db, $this->entity);
                            $catHelperProd->tagObjectWithStoreCategory($productObj, $storeForProd, 'product');
                        }
                    } else {
                        $this->log(
                            'saveProductMapping() - Skip tag catégorie produit boutique rowid=' . $fkStoreForProd
                            . ' : fk_categorie=' . $storeFkCategorie . ' (PROCATE=' . $procate . ', rétrocompat mono-boutique)',
                            LOG_DEBUG
                        );
                    }
                }
            }
        } catch (\Throwable $eCatProd) {
            // Non bloquant : le mapping est sauvé même si le tag échoue (capture aussi Error/TypeError)
            $this->log('saveProductMapping() - Erreur tag catégorie produit (non bloquant): ' . $eCatProd->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Préfixe déterministe des fichiers image déposés par le module (AC2/AC4) : distingue sans
     * ambiguïté un fichier déposé par importProductImages() d'une photo déposée par le client
     * (condition nécessaire pour que l'outil de nettoyage AC5 ne touche jamais aux fichiers du
     * client) et remplace l'ancien `uniqid()` qui interdisait structurellement toute
     * reconnaissance d'un import précédent.
     */
    private const IMPORTED_IMAGE_PREFIX = 'doli2shop-shopify-';

    /**
     * Nombre d'images demandées par le sous-champ GraphQL `images(first: N)` (requêtes de
     * getProductsFromShopify()/getProductFromShopify() — le littéral `images(first: 20)` dans ces
     * deux requêtes DOIT rester synchronisé avec cette constante).
     *
     * Revue coordinateur (CRITICAL-1) : cette limite, sans pagination, était un angle mort SANS
     * conséquence avant cette story — un produit à plus de 20 photos en perdait simplement
     * quelques-unes à l'affichage/import. Le correctif AC3 (suppression des images "disparues" du
     * payload) transforme cet angle mort en SUPPRESSION active : si le marchand réordonne ses
     * photos sur un produit à plus de IMAGES_FETCH_LIMIT images, une photo intacte peut sortir de
     * la fenêtre reçue et être effacée du disque à tort.
     *
     * Décision retenue : PAS de pagination réelle du sous-champ `images` ici — paginer changerait
     * aussi le NOMBRE d'images importées par appel (un changement de comportement plus large que
     * ce que corrige cette story) et toucherait une requête GraphQL partagée avec d'autres usages
     * (listing produits). À la place : la réconciliation des suppressions (AC3) est intégralement
     * INTERDITE dès que le nombre d'arêtes reçues atteint cette limite (payload potentiellement
     * tronqué, exhaustivité non prouvée) — cf. importProductImages(). Coût assumé : un produit à
     * plus de 20 photos n'est jamais nettoyé automatiquement (aucune image jamais supprimée), y
     * compris pour un remplacement réel — seul l'outil de nettoyage manuel (AC5) reste disponible,
     * avec aperçu. Un fichier gardé à tort coûte de l'espace disque ; un fichier supprimé à tort
     * est une perte irréversible chez le client — l'asymétrie de coût justifie ce choix.
     */
    private const IMAGES_FETCH_LIMIT = 20;

    /**
     * Import product images from Shopify
     *
     * Story import-images-duplique-les-photos (AC1/AC2/AC3) : chaque image Shopify est identifiée
     * par son `shopify_image_id` (id GraphQL, stable), mémorisé dans `llx_doli2shop_product_images`
     * dès le premier import. Une image déjà mémorisée n'est JAMAIS retéléchargée (AC1) ; une image
     * mémorisée mais disparue du payload actuel a été réellement remplacée côté Shopify (nouvel id)
     * — son fichier et sa vignette sont supprimés au lieu de s'accumuler (AC3).
     *
     * Revue coordinateur (CRITICAL-1/CRITICAL-2) — principe directeur du correctif : "absent du
     * payload courant" n'est traité comme "supprimé chez Shopify" QUE si la liste reçue est
     * exhaustive ET porte sur la bonne boutique. La réconciliation des suppressions (appel à
     * removeStaleProductImageMappings()) est désactivée pour CET appel dès que l'exhaustivité de
     * la liste ne peut pas être prouvée (payload potentiellement tronqué, ou au moins une image
     * ignorée) — jamais appliquée partiellement. Voir IMAGES_FETCH_LIMIT pour le détail du
     * raisonnement sur la troncature.
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param object $shopifyProduct Shopify product
     * @return int Number of images imported
     */
    private function importProductImages($dolibarrProductId, $shopifyProduct)
    {
        if (empty($shopifyProduct->images->edges)) {
            // Revue coordinateur : un payload SANS AUCUNE arête est le cas le MOINS exhaustif
            // possible (pourrait aussi bien être un produit réellement sans photo qu'un payload
            // partiel/erroné) — décision EXPLICITE, pas subie : on n'efface jamais de mapping ni
            // de fichier existant sur la seule foi d'un tableau vide. Conséquence assumée : un
            // produit dont TOUTES les photos sont réellement retirées de Shopify garde ses
            // fichiers indéfiniment (coût : espace disque, jamais perte de données) — seul
            // l'outil de nettoyage manuel (AC5) peut alors intervenir.
            return 0;
        }

        $importedCount = 0;
        $product = new Product($this->db);

        if ($product->fetch($dolibarrProductId) <= 0) {
            $this->log("Produit non trouvé pour import images: $dolibarrProductId", LOG_WARNING);
            return 0;
        }

        // CRITICAL-3 (revue coordinateur) : un même produit Dolibarr peut être rattaché à
        // PLUSIEURS boutiques Shopify (Epic 47) — chaque boutique a son propre espace de
        // shopify_image_id, non partagé avec les autres (les identifiants Shopify sont scopés par
        // boutique). Filtre conditionnel identique à l'invariant partagé du projet
        // (saveProductMapping() ci-dessus, ShopifyWebhooks::createWebhook(), CLAUDE.dolibarr.md
        // §14) : fkStore > 0 => filtrer strictement sur CETTE boutique ; fkStore == 0 (chemin
        // historique/constantes) => pas de filtre (comportement mono-boutique inchangé, cohérent
        // avec le reste du module).
        $fkStore = (int) $this->shopifyApi->getStoreId();

        $existingMappings = $this->getProductImageMappings($dolibarrProductId, $fkStore);
        $currentShopifyImageIds = [];
        $dir = $this->getProductImageDir($product);

        $totalEdgesCount = count($shopifyProduct->images->edges);
        $skippedImagesCount = 0;

        foreach ($shopifyProduct->images->edges as $edge) {
            $image = $edge->node;

            if (empty($image->url)) {
                // CRITICAL-2 (revue coordinateur) : un média Shopify pas encore prêt (upload en
                // cours) a une URL vide. SANS ce compteur, cette image est simplement absente de
                // $currentShopifyImageIds — une liste alors incomplète face à des mappings non
                // vides, qui aurait fait supprimer TOUTES les images du produit plus bas.
                $skippedImagesCount++;
                continue;
            }

            $rawShopifyImageId = !empty($image->id) ? $this->extractNumericId($image->id, 'ProductImage') : '';

            // Le repli générique de extractNumericId() renvoie la chaîne BRUTE (ex. un GID
            // malformé) si aucun nombre n'a pu en être extrait — une telle valeur ne doit JAMAIS
            // entrer telle quelle dans un nom de fichier ni dans la colonne VARCHAR(64) : on ne
            // retient qu'un identifiant strictement numérique (ctype_digit), même logique de
            // validation de format que l'extension de fichier juste après (preg_replace).
            $shopifyImageId = ($rawShopifyImageId !== '' && ctype_digit((string) $rawShopifyImageId))
                ? (string) $rawShopifyImageId
                : '';

            if ($shopifyImageId === '') {
                $skippedImagesCount++;
                $this->log("importProductImages: image sans identifiant Shopify numérique exploitable pour produit $dolibarrProductId, import ignoré", LOG_WARNING);
                continue;
            }

            $currentShopifyImageIds[] = $shopifyImageId;

            if (isset($existingMappings[$shopifyImageId])) {
                // AC1 : image déjà mémorisée — ne jamais retélécharger ni recopier.
                $existingFileName = $existingMappings[$shopifyImageId];
                $existingPath = rtrim($dir, '/') . '/' . $existingFileName;

                // "À traiter aussi" (revue coordinateur) : un fichier présent mais de taille
                // NULLE (téléchargement précédent interrompu) reste cassé pour toujours si l'on
                // se contente de is_file() — filesize() > 0 force un nouveau téléchargement.
                if (is_file($existingPath) && filesize($existingPath) > 0) {
                    continue;
                }

                // Fichier disparu ou vide sur disque : re-téléchargement normal ci-dessous, avec
                // le même shopify_image_id (ON DUPLICATE KEY UPDATE).
                $this->log("importProductImages: fichier attendu absent ou vide sur disque pour shopify_image_id=$shopifyImageId (produit $dolibarrProductId), retéléchargement", LOG_WARNING);
            }

            try {
                $imageUrl = $image->url;

                // Download image
                $tempFile = $this->downloadImage($imageUrl);

                if ($tempFile) {
                    // Add to product
                    $result = $this->addImageToProduct($product, $tempFile, $shopifyImageId, $fkStore);

                    // Clean up temp file
                    @unlink($tempFile);

                    if ($result > 0) {
                        $importedCount++;
                        $this->stats['images_imported']++;
                    }
                }
            } catch (Exception $e) {
                $this->log("Erreur import image: " . $e->getMessage(), LOG_WARNING);
            }
        }

        // CRITICAL-1/CRITICAL-2 (revue coordinateur) : ne réconcilier les suppressions QUE si
        // l'exhaustivité de la liste reçue est prouvée — jamais partiellement.
        $canReconcileDeletions = true;

        if ($totalEdgesCount >= self::IMAGES_FETCH_LIMIT) {
            $canReconcileDeletions = false;
            $this->log(
                "importProductImages: produit $dolibarrProductId — $totalEdgesCount image(s) reçue(s) "
                . "(>= limite " . self::IMAGES_FETCH_LIMIT . "), payload potentiellement tronqué : "
                . "suppression désactivée pour cet appel",
                LOG_WARNING
            );
        }

        if ($skippedImagesCount > 0) {
            $canReconcileDeletions = false;
            $this->log(
                "importProductImages: produit $dolibarrProductId — $skippedImagesCount image(s) ignorée(s) "
                . "(URL vide ou id non exploitable) : suppression désactivée pour cet appel",
                LOG_WARNING
            );
        }

        if ($canReconcileDeletions) {
            // AC3 : les shopify_image_id mémorisés mais absents du payload actuel ont été
            // réellement remplacés côté Shopify (le module reçoit un NOUVEL id, l'ancien a
            // disparu) — nettoyage du fichier + de sa vignette au lieu de laisser l'ancienne
            // version traîner. La suppression PHYSIQUE réelle est différée (cf.
            // $pendingFileDeletions / flushPendingFileDeletions()) jusqu'au commit() réel de la
            // transaction ouverte par processProduct() — seule la ligne SQL est retirée ici, ce
            // qui participe correctement à un éventuel rollback().
            $this->removeStaleProductImageMappings($dolibarrProductId, $dir, $existingMappings, $currentShopifyImageIds, $fkStore);
        }

        $this->log("Images importées pour produit $dolibarrProductId: $importedCount", LOG_DEBUG);

        return $importedCount;
    }

    /**
     * Répertoire disque des images d'un produit (helper partagé import/nettoyage), factorisant
     * le calcul déjà présent dans addImageToProduct().
     *
     * @param Product $product Dolibarr product object
     * @return string Chemin absolu du répertoire (sans slash final)
     */
    private function getProductImageDir($product)
    {
        global $conf;

        return rtrim($conf->product->multidir_output[$product->entity], '/') . '/' . get_exdir(0, 0, 0, 1, $product, 'product');
    }

    /**
     * Correspondances shopify_image_id => filename déjà mémorisées pour un produit (AC1/AC2).
     *
     * CRITICAL-3 (revue coordinateur) : filtre conditionnel sur fk_store — cf. invariant
     * CLAUDE.dolibarr.md §14, même logique que saveProductMapping()/findExistingMapping() plus
     * haut dans cette classe. $fkStore == 0 (chemin historique/constantes) ne filtre PAS sur
     * fk_store (comportement mono-boutique inchangé, ne casse pas les installs jamais migrées
     * vers Epic 47) ; $fkStore > 0 filtre strictement sur cette boutique, pour qu'un même produit
     * Dolibarr rattaché à plusieurs boutiques n'expose jamais les mappings d'une autre boutique.
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param int $fkStore rowid boutique courante (0 = chemin historique/constantes, pas de filtre)
     * @return array<string,string> shopify_image_id => filename
     */
    private function getProductImageMappings($dolibarrProductId, $fkStore)
    {
        $mappings = [];

        $sql = "SELECT shopify_image_id, filename FROM " . MAIN_DB_PREFIX . "doli2shop_product_images";
        $sql .= " WHERE fk_product = " . (int) $dolibarrProductId;
        $sql .= " AND entity = " . (int) $this->entity;
        if ((int) $fkStore > 0) {
            $sql .= " AND fk_store = " . (int) $fkStore;
        }

        $result = $this->db->query($sql);

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $mappings[$obj->shopify_image_id] = $obj->filename;
            }
        }

        return $mappings;
    }

    /**
     * Mémorise (fk_product, entity, shopify_image_id, fk_store) => filename après un dépôt
     * réussi sur disque (AC1/AC2). ON DUPLICATE KEY UPDATE couvre le cas d'un re-téléchargement
     * suite à une suppression manuelle ou une taille nulle (même shopify_image_id, même boutique,
     * même clé unique).
     *
     * CRITICAL-3 (revue coordinateur) : fk_store fait partie de la clé unique
     * `(fk_product, entity, shopify_image_id, fk_store)` — contrairement à
     * ShopifyWebhooks::createWebhook() (clé `(webhook_id, entity)`, fk_store hors clé, d'où le
     * pattern `IF(VALUES(fk_store) > 0, ...)` pour ne jamais dégrader un fk_store déjà connu),
     * ici deux boutiques distinctes qui partagent PAR COÏNCIDENCE le même shopify_image_id
     * numérique (des espaces d'id Shopify indépendants par boutique) doivent produire DEUX lignes
     * distinctes, jamais une collision de clé — c'est précisément l'objet du CRITICAL-3. fk_store
     * étant partie intégrante de la clé, un `ON DUPLICATE KEY` ne peut se déclencher qu'avec un
     * fk_store IDENTIQUE à la ligne existante : le motif `IF(VALUES(fk_store) > 0, ...)` n'a donc
     * pas sa place ici (il protégerait contre un cas qui ne peut structurellement pas se produire
     * avec cette clé) — seul `filename` a besoin d'être rafraîchi.
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param string $shopifyImageId Identifiant Shopify de l'image
     * @param string $filename Nom du fichier déposé
     * @param int $fkStore rowid boutique courante (0 = chemin historique/constantes)
     * @return void
     */
    private function saveProductImageMapping($dolibarrProductId, $shopifyImageId, $filename, $fkStore)
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_product_images";
        $sql .= " (fk_product, entity, shopify_image_id, filename, fk_store, date_creation)";
        $sql .= " VALUES (";
        $sql .= (int) $dolibarrProductId . ", ";
        $sql .= (int) $this->entity . ", ";
        $sql .= "'" . $this->db->escape($shopifyImageId) . "', ";
        $sql .= "'" . $this->db->escape($filename) . "', ";
        $sql .= (int) $fkStore . ", ";
        $sql .= "'" . $this->db->idate(dol_now()) . "'";
        $sql .= ")";
        $sql .= " ON DUPLICATE KEY UPDATE filename = VALUES(filename)";

        $result = $this->db->query($sql);

        if (!$result) {
            $this->log("saveProductImageMapping: échec enregistrement mapping image produit=$dolibarrProductId shopify_image_id=$shopifyImageId fk_store=$fkStore: " . $this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Supprime les mappings dont le shopify_image_id n'apparaît plus dans le payload Shopify
     * actuel — remplacement réel de l'image (AC3), pas une simple omission temporaire (l'appelant
     * ne déclenche cette méthode que lorsque l'exhaustivité de la liste reçue est prouvée, cf.
     * importProductImages()).
     *
     * Revue coordinateur : la suppression PHYSIQUE (fichier + vignettes) n'est PAS effectuée ici
     * — seule la ligne SQL est retirée (participe correctement à un éventuel rollback() de la
     * transaction ouverte par processProduct()). Les chemins à supprimer sont accumulés dans
     * $this->pendingFileDeletions ; flushPendingFileDeletions() (appelée après un commit() réel)
     * fait l'unlink() réel.
     *
     * CRITICAL-3 : DELETE filtré conditionnellement par fk_store, même invariant que
     * getProductImageMappings()/saveProductImageMapping() ci-dessus.
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param string $dir Répertoire disque des images du produit
     * @param array<string,string> $existingMappings shopify_image_id => filename (avant import)
     * @param string[] $currentShopifyImageIds shopify_image_id présents dans le payload actuel
     * @param int $fkStore rowid boutique courante (0 = chemin historique/constantes)
     * @return void
     */
    private function removeStaleProductImageMappings($dolibarrProductId, $dir, array $existingMappings, array $currentShopifyImageIds, $fkStore)
    {
        foreach ($existingMappings as $shopifyImageId => $filename) {
            // PHP transtype silencieusement en entier toute clé de tableau qui ressemble à un
            // nombre ('111' devient la clé int(111)) — sans ce cast, la comparaison stricte
            // ci-dessous contre $currentShopifyImageIds (des chaînes, jamais utilisées comme clé)
            // échouait TOUJOURS, et une image pourtant toujours présente côté Shopify était
            // supprimée puis retéléchargée à chaque passage.
            $shopifyImageId = (string) $shopifyImageId;

            if (in_array($shopifyImageId, $currentShopifyImageIds, true)) {
                continue;
            }

            $filePath = rtrim($dir, '/') . '/' . $filename;

            if (is_file($filePath)) {
                $this->pendingFileDeletions[] = $filePath;
            }

            // AC3 : Dolibarr se fie à l'EXISTENCE du fichier de vignette, pas à son contenu —
            // sans cette suppression, l'ancienne vignette en cache continuerait de s'afficher.
            foreach ($this->collectProductImageThumbPaths($dir, $filename) as $thumbPath) {
                $this->pendingFileDeletions[] = $thumbPath;
            }

            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_product_images";
            $sql .= " WHERE fk_product = " . (int) $dolibarrProductId;
            $sql .= " AND entity = " . (int) $this->entity;
            $sql .= " AND shopify_image_id = '" . $this->db->escape($shopifyImageId) . "'";
            if ((int) $fkStore > 0) {
                $sql .= " AND fk_store = " . (int) $fkStore;
            }
            $this->db->query($sql);

            $this->log("removeStaleProductImageMappings: image Shopify #$shopifyImageId remplacée pour produit $dolibarrProductId (suppression physique différée jusqu'au commit: $filename)", LOG_INFO);
        }
    }

    /**
     * Supprime PHYSIQUEMENT les fichiers (images + vignettes) mis en attente par
     * removeStaleProductImageMappings() pendant l'import courant, puis vide la file.
     *
     * À appeler UNIQUEMENT après un commit() réel de la transaction SQL de processProduct() —
     * jamais avant (cf. doc de $pendingFileDeletions).
     *
     * @return void
     */
    private function flushPendingFileDeletions()
    {
        foreach ($this->pendingFileDeletions as $filePath) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        $this->pendingFileDeletions = [];
    }

    /**
     * Abandonne les suppressions de fichiers mises en attente (rollback SQL de processProduct())
     * SANS toucher au disque : les fichiers "remplacés" restent présents, orphelins mais
     * intacts — coût en espace disque, jamais en perte de données.
     *
     * @return void
     */
    private function clearPendingFileDeletions()
    {
        $this->pendingFileDeletions = [];
    }

    /**
     * Liste (sans supprimer) les vignettes `thumbs/<basename>_*` d'un fichier image existantes
     * sur disque. Balaie le contenu du répertoire plutôt qu'un `glob()` sur un motif construit
     * avec le nom de fichier : un `ref` produit contenant un métacaractère glob (`[`, `*`, `?`)
     * casserait sinon silencieusement le motif de recherche.
     *
     * @param string $dir Répertoire disque des images du produit (parent de thumbs/)
     * @param string $filename Nom du fichier dont on liste les vignettes
     * @return string[] Chemins absolus des fichiers de vignette trouvés
     */
    private function collectProductImageThumbPaths($dir, $filename)
    {
        $thumbsDir = rtrim($dir, '/') . '/thumbs';

        if (!is_dir($thumbsDir)) {
            return [];
        }

        $prefix = pathinfo($filename, PATHINFO_FILENAME) . '_';
        $entries = @scandir($thumbsDir) ?: [];
        $paths = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (strpos($entry, $prefix) === 0) {
                $thumbPath = $thumbsDir . '/' . $entry;
                if (is_file($thumbPath)) {
                    $paths[] = $thumbPath;
                }
            }
        }

        return $paths;
    }

    /**
     * Nombre maximal de sauts de redirection HTTP suivis manuellement par downloadImage().
     *
     * Story 61-6 (AC2) : CURLOPT_FOLLOWLOCATION est désactivé pour que chaque redirection
     * repasse par isImageUrlSafe() avant d'être suivie — sinon une URL initiale validée
     * pourrait rediriger vers une adresse interne et contourner entièrement la protection
     * SSRF. Bornage à 3 sauts : suffisant pour les CDN usuels (Shopify n'en émet aucun en
     * pratique sur cdn.shopify.com, vérifié manuellement — cf. Completion Notes), fail-closed
     * au-delà.
     */
    private const MAX_IMAGE_REDIRECTS = 3;

    /**
     * Download image from URL to temp file
     *
     * Story 61-6 (AC1/AC2) : chaque URL (initiale et chaque redirection suivie manuellement)
     * est validée par isImageUrlSafe() avant tout appel curl — fail-closed (retourne false)
     * si l'hôte résout vers une adresse privée/réservée, si le schéma n'est pas https, ou si
     * le nombre de redirections dépasse MAX_IMAGE_REDIRECTS.
     *
     * @param string $url Image URL
     * @return string|false Temp file path or false on failure
     */
    private function downloadImage($url)
    {
        $tempDir = sys_get_temp_dir();
        // v2.1.9: Extraire l'extension réelle depuis l'URL (png, webp, jpg...)
        $urlPath = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($urlPath, PATHINFO_EXTENSION) ?: 'jpg';
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext); // Sécurité: caractères alphanum seulement
        $tempFile = $tempDir . '/shopify_img_' . uniqid() . '.' . $ext;

        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_IMAGE_REDIRECTS; $hop++) {
            if (!$this->isImageUrlSafe($currentUrl)) {
                $this->log("downloadImage: URL rejetée par la validation SSRF (schéma non-https ou hôte résolvant vers une plage privée/réservée): " . $currentUrl, LOG_WARNING);
                return false;
            }

            $result = $this->fetchImageOnce($currentUrl);

            // Story 61-6 (review, HIGH-1) : isImageUrlSafe() ci-dessus valide l'hôte à un
            // instant T, via SA PROPRE résolution DNS. curl se connecte à un instant T+1, via
            // SA résolution DNS — potentiellement différente (DNS rebinding : une réponse
            // publique à la validation, une réponse interne à la connexion réelle). Sans ce
            // contrôle, la validation ci-dessus serait décorative face à ce vecteur précis.
            // CURLINFO_PRIMARY_IP donne l'adresse RÉELLEMENT contactée : on la revalide, et on
            // rejette le corps téléchargé (fail-closed) si elle n'est pas sûre — même si
            // l'hôte validé quelques lignes plus haut l'était.
            if (!empty($result['primaryIp']) && !self::isIpAddressSafe($result['primaryIp'])) {
                $this->log("downloadImage: adresse IP réellement contactée (" . $result['primaryIp'] . ") rejetée après connexion (DNS rebinding) pour " . $currentUrl, LOG_WARNING);
                return false;
            }

            $httpCode = $result['httpCode'];

            if ($httpCode >= 300 && $httpCode < 400) {
                $location = $result['redirectUrl'];

                if (empty($location)) {
                    $this->log("downloadImage: redirection HTTP $httpCode sans Location exploitable pour $currentUrl", LOG_WARNING);
                    return false;
                }

                $currentUrl = $location;
                continue;
            }

            $imageData = $result['body'];

            if ($httpCode === 200 && !empty($imageData)) {
                file_put_contents($tempFile, $imageData);
                return $tempFile;
            }

            return false;
        }

        // Story 61-6 (review, LOW) : journaliser $currentUrl (le dernier saut connu — la
        // cible qui aurait dépassé la limite, jamais contactée) plutôt que $url (l'URL
        // initiale), pour que le diagnostic pointe vers le bon maillon de la chaîne de
        // redirection plutôt que systématiquement vers le point de départ.
        $this->log("downloadImage: nombre maximal de redirections (" . self::MAX_IMAGE_REDIRECTS . ") dépassé pour " . $url . " (dernier saut: " . $currentUrl . ")", LOG_WARNING);
        return false;
    }

    /**
     * Exécute UN appel curl vers $url (pas de suivi de redirection curl natif : la
     * revalidation SSRF à chaque saut se fait dans downloadImage(), pas ici).
     *
     * Story 61-6 (AC2) : extrait de downloadImage() en méthode `protected` distincte pour
     * être surchargeable par un test (pattern déjà utilisé dans ce module, cf.
     * TestableImportProducts dans test/unit/ImportProductsImagesTest.php) — un test peut ainsi
     * scénariser une chaîne de redirections (y compris vers une adresse interne) sans réseau
     * réel, tout en exerçant le VRAI code de downloadImage()/isImageUrlSafe() qui décide de
     * suivre ou non chaque saut.
     *
     * @param string $url URL à contacter (déjà validée par isImageUrlSafe() par l'appelant)
     * @return array{httpCode:int,body:?string,redirectUrl:?string,primaryIp:?string}
     */
    protected function fetchImageOnce(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Story 61-6 (AC2) : FOLLOWLOCATION désactivé volontairement — les redirections sont
        // suivies manuellement par downloadImage(), avec revalidation SSRF à chaque saut.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // CURLINFO_REDIRECT_URL renvoie l'URL absolue résolue par curl (relative Location
        // comprise), sans qu'il soit nécessaire d'activer FOLLOWLOCATION.
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        // Story 61-6 (review, HIGH-1) : CURLINFO_PRIMARY_IP est l'adresse RÉELLEMENT contactée
        // par curl pour CETTE requête. curl refait sa propre résolution DNS, indépendamment de
        // isImageUrlSafe() qui a validé le nom d'hôte un instant plus tôt — c'est cette IP,
        // pas celle validée, qui doit repasser par isIpAddressSafe() dans downloadImage() pour
        // fermer le DNS rebinding (TOCTOU).
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        return array(
            'httpCode' => (int) $httpCode,
            'body' => is_string($body) ? $body : null,
            'redirectUrl' => !empty($redirectUrl) ? $redirectUrl : null,
            'primaryIp' => !empty($primaryIp) ? $primaryIp : null,
        );
    }

    /**
     * Valide qu'une URL d'image peut être passée à curl sans risque de SSRF.
     *
     * Story 61-6 (AC1) : schéma https obligatoire, et AUCUNE IP résolue pour l'hôte ne doit
     * appartenir à une plage privée/réservée (RFC 1918, loopback, lien-local — dont
     * 169.254.169.254, l'endpoint de métadonnées cloud AWS/GCP). Décision fail-closed : toute
     * ambiguïté (parse_url() échoue, hôte non résolvable) est un refus.
     *
     * Approche "refus des plages privées après résolution DNS" plutôt qu'allowlist de domaines
     * Shopify — décision du mainteneur (cf. story 61-6) : robuste face à un futur changement de
     * domaine CDN côté Shopify, contrairement à une allowlist qui casserait l'import en silence.
     *
     * @param string $url URL à valider
     * @return bool true si l'URL peut être contactée en sécurité
     */
    private function isImageUrlSafe(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = $parts['host'];

        // Hôte déjà littéral (IPv4/IPv6) : pas de résolution DNS nécessaire.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isIpAddressSafe($host);
        }

        $ips = self::resolveHostIps($host);
        if (empty($ips)) {
            // Fail-closed : hôte non résolvable ici, on ne prend pas le risque de laisser curl
            // tenter sa propre résolution sans aucune garantie.
            //
            // Story 61-6 (review, HIGH-1 — commentaire corrigé, l'ancienne formulation
            // « TOCTOU atténué mais pas éliminé » était trop optimiste) : cette validation
            // résout l'hôte à un instant T ; curl se connecte à un instant T+1, via SA PROPRE
            // résolution. Sur le vecteur DNS rebinding (réponse publique ici, réponse interne
            // à la connexion), cette validation seule est décorative. Ce qui ferme réellement
            // ce vecteur est la revalidation de CURLINFO_PRIMARY_IP (l'adresse RÉELLEMENT
            // contactée) faite dans downloadImage() après chaque fetchImageOnce() — pas ce
            // fail-closed-ci, qui ne couvre que le cas où aucune IP n'a pu être résolue du
            // tout. Le vecteur des redirections HTTP, lui, est bien fermé par la boucle
            // manuelle de downloadImage() (AC2, prouvé par test).
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isIpAddressSafe($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Résout un nom d'hôte en la liste de ses IPv4/IPv6 (aucune décision de sécurité ici,
     * purement la résolution — la décision est dans isIpAddressSafe()).
     *
     * Story 61-6 (review, HIGH-2) : mémoïsée par hôte via self::$hostIpsCache pour la durée du
     * run — voir le commentaire de la propriété pour le raisonnement complet.
     *
     * @param string $host Nom d'hôte (pas une IP littérale)
     * @return string[] IPs résolues (vide si non résolvable)
     */
    private static function resolveHostIps(string $host): array
    {
        if (array_key_exists($host, self::$hostIpsCache)) {
            return self::$hostIpsCache[$host];
        }

        $ips = array();

        $recordsA = @dns_get_record($host, DNS_A);
        if (is_array($recordsA)) {
            foreach ($recordsA as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $recordsAaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($recordsAaaa)) {
            foreach ($recordsAaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            // Repli sur le résolveur système (utilisé par ex. quand dns_get_record() échoue
            // faute de serveur DNS accessible pour des requêtes typées, mais que la résolution
            // classique fonctionne).
            $resolved = @gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }

        self::$hostIpsCache[$host] = $ips;

        return $ips;
    }

    /**
     * Décision pure : cette IP est-elle acceptable pour un téléchargement sortant ?
     *
     * Story 61-6 (AC3) : extraite séparément de la résolution DNS pour être testable avec des
     * IP construites à la main (127.0.0.1, 169.254.169.254, 10.x.x.x, adresses IPv6
     * équivalentes...), sans dépendre du réseau ni d'un mock DNS.
     *
     * FILTER_FLAG_NO_PRIV_RANGE rejette 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7.
     * FILTER_FLAG_NO_RES_RANGE rejette les plages réservées, dont 127.0.0.0/8 (loopback),
     * 169.254.0.0/16 (lien-local — donc 169.254.169.254, l'endpoint de métadonnées cloud
     * AWS/GCP cité par l'audit) et ::1.
     *
     * @param string $ip Adresse IP (IPv4 ou IPv6)
     * @return bool true si l'IP est autorisée
     */
    private static function isIpAddressSafe(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Add image to Dolibarr product
     *
     * Story import-images-duplique-les-photos (AC2) : nom de fichier DÉTERMINISTE dérivé du
     * `shopify_image_id` (plus de `uniqid()`), préfixé `IMPORTED_IMAGE_PREFIX` pour rester
     * distinguable d'une photo déposée par le client (AC4). Le mapping est mémorisé après un
     * dépôt réussi, seule condition qui permette à un second passage de reconnaître son propre
     * travail (AC1).
     *
     * Correctif (revue coordinateur) : cette méthode appelait auparavant `$product->photo = ...`
     * suivi de `$product->update()` pour "l'image principale". `Product` n'a AUCUNE propriété
     * `$photo` (vérifié sur le core Dolibarr 23 : 0 occurrence de `public $photo`) et
     * `llx_product` n'a AUCUNE colonne `photo` — l'affectation ne persistait donc RIEN, mais
     * l'`update()` déclenchait quand même un `UPDATE` complet + le trigger `PRODUCT_MODIFY`.
     * `ShopifyTriggerManager::handleProductEvent()` (class/shopifytriggermanager.class.php:110)
     * réagit à ce trigger en exportant le produit vers Shopify, qui renvoie alors un nouveau
     * webhook `products/update` — une boucle d'écho auto-entretenue, seulement atténuée par la
     * garde anti-boucle de 30 s/5 min de `ProductWebhookHandler.php:249-282`. Ces deux lignes
     * sont donc retirées : aucun usage réel constaté, uniquement un coût (écriture + trigger)
     * sans effet.
     *
     * "À traiter aussi" (revue coordinateur) : `copy()` direct vers le chemin final laisse une
     * fenêtre où un lecteur (affichage fiche produit, génération de vignette) peut lire un
     * fichier à moitié écrit — le nom étant désormais DÉTERMINISTE (AC2), deux livraisons quasi
     * simultanées (deux webhooks rapprochés sur le même produit) écrivent le MÊME chemin final.
     * `copy()` vers un fichier temporaire du même répertoire PUIS `rename()` (atomique sur un
     * même système de fichiers POSIX, garanti par `rename(2)`) élimine cette fenêtre : le chemin
     * final n'existe jamais dans un état partiellement écrit.
     *
     * Story photos-importees-de-shopify-jamais-indexees-dans-ecm (v2.5.3, HIGH — crash en
     * production chez un client identifié) : cette méthode ne déposait le fichier que sur disque
     * et dans `llx_doli2shop_product_images`, jamais dans `llx_ecm_files`. C'est le cœur de
     * Dolibarr (`completeFileArrayWithDatabaseInfo()`, `core/lib/files.lib.php:429-524`) qui
     * auto-indexait alors le fichier au premier affichage de l'onglet Documents — sans aucune
     * protection concurrente, d'où un `DB_ERROR_RECORD_ALREADY_EXISTS` sur
     * `uk_ecm_files (filepath, filename, entity)` quand deux affichages quasi simultanés
     * découvraient tous deux le même fichier "hors index". `indexProductImageInEcm()` ferme cette
     * fenêtre en indexant immédiatement après le dépôt, dans la même opération que
     * `saveProductImageMapping()`.
     *
     * @param Product $product Dolibarr product object
     * @param string $imagePath Path to image file
     * @param string $shopifyImageId Identifiant Shopify de l'image (déjà extrait, stable, validé
     *                                ctype_digit par l'appelant)
     * @param int $fkStore rowid boutique courante (0 = chemin historique/constantes)
     * @return int Result (>0 success, <0 error)
     */
    private function addImageToProduct($product, $imagePath, $shopifyImageId, $fkStore)
    {
        $dir = $this->getProductImageDir($product);

        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }

        // Get file extension
        $ext = pathinfo($imagePath, PATHINFO_EXTENSION) ?: 'jpg';
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'jpg';
        $newFileName = $product->ref . '_' . self::IMPORTED_IMAGE_PREFIX . $shopifyImageId . '.' . $ext;
        $destPath = $dir . '/' . $newFileName;
        $tmpDestPath = $dir . '/.tmp-' . self::IMPORTED_IMAGE_PREFIX . $shopifyImageId . '-' . uniqid() . '.' . $ext;

        if (copy($imagePath, $tmpDestPath) && rename($tmpDestPath, $destPath)) {
            $this->saveProductImageMapping($product->id, $shopifyImageId, $newFileName, $fkStore);
            $this->indexProductImageInEcm($product, $dir, $newFileName);

            return 1;
        }

        @unlink($tmpDestPath);

        return -1;
    }

    /**
     * Inscrit une image de produit déposée sur disque dans l'index de fichiers de Dolibarr
     * (`llx_ecm_files`), pour fermer la fenêtre exploitée par le mécanisme d'auto-indexation du
     * cœur (`completeFileArrayWithDatabaseInfo()`).
     *
     * Story photos-importees-de-shopify-jamais-indexees-dans-ecm — AC1 : appelée immédiatement
     * après le `copy()`/`rename()`, dans la même opération que `saveProductImageMapping()` (jamais
     * en rattrapage différé, sinon la fenêtre de course reste ouverte).
     *
     * 🔑 AC4 — chemin DÉRIVÉ, jamais reconstruit par concaténation supposée : deux clients
     * Multicompany entité 2 observés donnent des `filepath` DIFFÉRENTS
     * (`2/produit/X840085071K` chez Europe Loisirs, `produit/P46` chez Essences Naturelles
     * Corses, cf. constat du 10/09/2026 dans la story) selon que Multicompany partage ou non les
     * répertoires de documents entre entités. `$dir` (= `getProductImageDir($product)`, déjà
     * basé sur `$conf->product->multidir_output[$product->entity]`) porte déjà cette différence :
     * on se contente de lui retirer le préfixe `DOL_DATA_ROOT`, EXACTEMENT comme le fait le cœur
     * dans `addFileIntoDatabaseIndex()` (core/lib/files.lib.php:2297-2302). Ni "2/" ni "entity/"
     * ne sont jamais ajoutés ou supposés ici.
     *
     * AC2 — entité utilisée : celle du PRODUIT (`$product->entity`), jamais `$conf->entity`
     * contextuel (invariant §14 CLAUDE.dolibarr.md).
     *
     * AC3 — idempotence : un `fetch()` préalable couvre le rejeu normal ET la reprise des photos
     * déjà déposées hors index par les versions précédentes du module (rien à faire si déjà
     * indexé). Si malgré tout deux appels concurrents perdent la course sur la contrainte unique
     * `uk_ecm_files (filepath, filename, entity)`, le message d'erreur porté par
     * `EcmFiles::create()` (`Error DB_ERROR_RECORD_ALREADY_EXISTS : ...`) est reconnu et traité
     * comme un no-op, jamais comme un échec.
     *
     * Choix `EcmFiles` direct plutôt que le wrapper `addFileIntoDatabaseIndex()` du cœur : ce
     * wrapper lit l'utilisateur via `global $user`, jamais via un paramètre — une dépendance à un
     * état global peu fiable dans un contexte webhook/cron. `$this->user` (l'acteur explicite de
     * cette instance, déjà utilisé pour tous les `create()`/`update()` de cette classe) est plus
     * sûr. La construction de l'objet `EcmFiles` reproduit fidèlement les champs posés par
     * `addFileIntoDatabaseIndex()`.
     *
     * @param Product $product Dolibarr product object (entité et id du produit)
     * @param string $dir Répertoire disque où le fichier vient d'être déposé (retour de
     *                     `getProductImageDir()`)
     * @param string $filename Nom du fichier déposé (sans chemin)
     * @return void
     */
    private function indexProductImageInEcm($product, $dir, $filename)
    {
        if (!class_exists('EcmFiles')) {
            $this->log("EcmFiles indisponible, indexation ECM ignorée pour $filename", LOG_WARNING);
            return;
        }

        // Même dérivation que le cœur (core/lib/files.lib.php:addFileIntoDatabaseIndex,
        // :2297-2302) : ne JAMAIS reconstruire le chemin par concaténation supposée de l'entité.
        $relDir = preg_replace('/^' . preg_quote(DOL_DATA_ROOT, '/') . '/', '', $dir);
        $relDir = preg_replace('/[\\/]+$/', '', $relDir);
        $relDir = preg_replace('/^[\\/]+/', '', $relDir);

        $productEntity = (int) $product->entity;

        // AC3 — reprise de l'existant : déjà indexé (rejeu normal, ou photo déposée hors index
        // par une version antérieure du module) -> rien à faire.
        $lookup = new EcmFiles($this->db);
        $existing = $lookup->fetch(0, '', $relDir . '/' . $filename, '', '', '', 0, $productEntity);
        if ($existing > 0) {
            $this->log("Image déjà indexée dans ecm_files: $filename", LOG_DEBUG);
            return;
        }

        $fullPath = rtrim($dir, '/') . '/' . $filename;

        $ecmfile = new EcmFiles($this->db);
        $ecmfile->filepath = $relDir;
        $ecmfile->filename = $filename;
        $ecmfile->label = is_readable($fullPath) ? (string) md5_file($fullPath) : '';
        // LOW (revue du 12/09/2026) : le cœur renseigne fullpath_orig avec le chemin ABSOLU du
        // fichier sur disque (core/lib/files.lib.php:2311, addFileIntoDatabaseIndex()) — jamais
        // vide. On s'aligne : $fullPath est déjà ce même chemin absolu (calculé juste au-dessus).
        $ecmfile->fullpath_orig = $fullPath;
        $ecmfile->gen_or_uploaded = 'uploaded';
        $ecmfile->description = '';
        $ecmfile->keywords = '';
        $ecmfile->src_object_id = $product->id;
        $ecmfile->src_object_type = !empty($product->table_element) ? $product->table_element : 'product';
        // AC2 — entité du produit, jamais $conf->entity contextuel.
        $ecmfile->entity = $productEntity;

        $result = $ecmfile->create($this->user);

        if ($result <= 0) {
            $errorText = implode(' ', (array) $ecmfile->errors);
            if (strpos($errorText, 'DB_ERROR_RECORD_ALREADY_EXISTS') !== false) {
                // AC3/AC5 — course perdue contre une indexation concurrente : idempotent par
                // construction, pas un échec.
                $this->log("Image déjà indexée dans ecm_files (course concurrente): $filename", LOG_DEBUG);
            } else {
                $this->log("Échec indexation ecm_files pour $filename: $errorText", LOG_WARNING);
            }
        }
    }

    /**
     * Update product stock in Dolibarr
     *
     * @param int $dolibarrProductId Dolibarr product ID
     * @param int $quantity Stock quantity
     * @return void
     */
    private function updateProductStock($dolibarrProductId, $quantity)
    {
        // Get default warehouse
        $warehouseId = getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE');

        if (empty($warehouseId)) {
            $this->log("Entrepôt par défaut non configuré, stock non mis à jour", LOG_WARNING);
            return;
        }

        $product = new Product($this->db);
        if ($product->fetch($dolibarrProductId) <= 0) {
            return;
        }

        // Get current stock - Respecter configuration stock virtuel/réel (comme l'export)
        $currentStock = $this->getProductStock($product);

        // Calculate difference
        $diff = $quantity - $currentStock;

        if ($diff == 0) {
            return;
        }

        // Create stock movement
        $label = 'Shopify Import - Stock sync';
        $inventorycode = 'SHOP-IMPORT-' . date('YmdHis');

        if ($diff > 0) {
            $result = $product->correct_stock(
                $this->user,
                $warehouseId,
                abs($diff),
                0, // 0 = addition
                $label,
                0,
                $inventorycode
            );
        } else {
            $result = $product->correct_stock(
                $this->user,
                $warehouseId,
                abs($diff),
                1, // 1 = removal
                $label,
                0,
                $inventorycode
            );
        }

        if ($result > 0) {
            $this->log("Stock mis à jour pour produit $dolibarrProductId: $currentStock → $quantity", LOG_DEBUG);
        }
    }

    /**
     * Convert weight to kilograms
     *
     * @param float $weight Weight value
     * @param string $unit Weight unit (GRAMS, KILOGRAMS, OUNCES, POUNDS)
     * @return float Weight in kilograms
     */
    private function convertWeight($weight, $unit)
    {
        switch (strtoupper($unit)) {
            case 'GRAMS':
                return $weight / 1000;
            case 'OUNCES':
                return $weight * 0.0283495;
            case 'POUNDS':
                return $weight * 0.453592;
            case 'KILOGRAMS':
            default:
                return $weight;
        }
    }

    /**
     * Extract numeric ID from Shopify GID
     *
     * @param string $gid Shopify GID (e.g., gid://shopify/Product/123)
     * @param string $type Expected type (Product, ProductVariant, etc.)
     * @return string Numeric ID
     */
    private function extractNumericId($gid, $type = 'Product')
    {
        if (is_numeric($gid)) {
            return $gid;
        }

        if (preg_match('/gid:\/\/shopify\/' . $type . '\/(\d+)/', $gid, $matches)) {
            return $matches[1];
        }

        // Fallback: extract any number from the string
        if (preg_match('/(\d+)$/', $gid, $matches)) {
            return $matches[1];
        }

        return $gid;
    }

    /**
     * Sanitize HTML content from Shopify
     *
     * @param string $html HTML content
     * @return string Sanitized content
     */
    private function sanitizeHtml($html)
    {
        if (empty($html)) {
            return '';
        }

        // Remove script tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);

        // Remove style tags
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($html);
    }

    /**
     * Get import statistics
     *
     * @return array Statistics
     */
    public function getStats()
    {
        return $this->stats;
    }

    /**
     * Get list of Shopify products not yet imported
     *
     * @param int $limit Maximum number to return
     * @return array List of products
     */
    public function getUnimportedProducts($limit = 100)
    {
        $this->log("Récupération produits Shopify non importés", LOG_DEBUG);

        $allProducts = [];
        $cursor = null;
        $maxApiCalls = 20; // v2.1.9: Limite anti-timeout (20 × 50 = 1000 produits max parcourus)
        $apiCallCount = 0;

        do {
            $apiCallCount++;
            if ($apiCallCount > $maxApiCalls) {
                $this->log("getUnimportedProducts: limite d'appels API atteinte ($maxApiCalls), arrêt", LOG_WARNING);
                break;
            }

            $result = $this->getProductsFromShopify($cursor, min($limit, 50));

            foreach ($result['products'] as $product) {
                $shopifyProductId = $this->extractNumericId($product->id, 'Product');
                $mapping = $this->findExistingMapping($shopifyProductId);

                if (!$mapping) {
                    $allProducts[] = [
                        'id' => $shopifyProductId,
                        'title' => $product->title,
                        'status' => $product->status,
                        'variants_count' => count($product->variants->edges ?? []),
                        'sku' => $product->variants->edges[0]->node->sku ?? null
                    ];
                }

                if (count($allProducts) >= $limit) {
                    break 2;
                }
            }

            $cursor = $result['endCursor'];

        } while ($result['hasNextPage'] && count($allProducts) < $limit);

        return $allProducts;
    }
}
