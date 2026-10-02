<?php
/**
 * @file        class/api_doli2shop.class.php
 * @brief       Socle API REST Dolibarr NATIVE du module Doli2Shop (lecture seule, Story 54-1)
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.5.0
 * @link        https://doli2shop.ptitetete.org
 */

use Luracast\Restler\RestException;

// Protection contre accès direct
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Pré-charger le core date.lib.php avant lib/compatibility.lib.php (tiré par LoggerTrait via
// StoreService). Depuis 2.6.0 compatibility.lib.php charge lui-même date.lib.php du cœur au lieu de
// polyfiller dol_time_plus_duree() ; ce pré-chargement est conservé comme ceinture, sans effet.
if (defined('DOL_DOCUMENT_ROOT') && is_file(DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php')) {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
}

require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/../lib/version.lib.php';

// Story 54-2 : endpoints d'action — dépendances des services réutilisés STRICTEMENT
// (aucune logique métier dupliquée, cf. Dev Notes story 54-2).
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/shopifystocktrigger.class.php';
require_once dirname(__FILE__) . '/stockrecalageservice.class.php';
require_once dirname(__FILE__) . '/shopifyordermanager.class.php';
require_once dirname(__FILE__) . '/importproducts.class.php';
require_once dirname(__FILE__) . '/actionlogger.class.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php'; // doli2shopStoreSyncAllowed() (gate licence)

/**
 * File for API class Doli2Shop
 *
 * Expose des endpoints de LECTURE SEULE pour diagnostiquer/piloter une
 * installation Doli2Shop sans navigateur ni session admin (agent externe
 * autorisé : IA type Claude + skill, script de monitoring, outil support).
 * Auth standard Dolibarr (DOLAPIKEY) + droit dédié `doli2shop->api_read`.
 *
 * Préfixe URL : /api/index.php/doli2shop/...
 *
 * Endpoints exposés (Story 54-1, lecture) :
 *   GET /doli2shop/status             → version, boutiques, directions de sync, dernier recalage, compteurs
 *   GET /doli2shop/stores             → liste des boutiques (projection whitelist, AUCUN secret)
 *   GET /doli2shop/mappings/products  → mappings produits Dolibarr <-> Shopify, paginés/filtrés
 *   GET /doli2shop/actionlog          → journal d'actions module, paginé/filtré
 *
 * Endpoints exposés (Story 54-2, actions — droit `doli2shop->api_operate` requis, INDÉPENDANT
 * de `api_read` : un token operate-only peut agir sans lire) :
 *   POST /doli2shop/stock/push/{productId}    → push stock ciblé d'UN produit vers Shopify
 *   POST /doli2shop/stock/recalage            → traite UN lot de recalage stock (confirm=1 requis)
 *   POST /doli2shop/orders/catchup            → rattrapage commandes Shopify manquantes
 *   POST /doli2shop/products/sync/{productId} → resync ciblée Dolibarr → Shopify d'UN produit
 *
 * ⚠ Prérequis droits `POST /orders/catchup` : `ShopifyOrderManager` (réutilisé tel quel, aucune
 * logique dupliquée) exige EN PLUS, pour l'utilisateur propriétaire du token API, les droits
 * Dolibarr STANDARD société (lire/créer), commande (lire/créer), produit (lire), facture
 * (créer/valider) — sans quoi l'appel échoue en 403 explicite (jamais un 500 opaque).
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Doli2Shop extends DolibarrApi
{
    /**
     * Constructeur
     */
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    // =========================================================================
    // HELPERS PRIVÉS (protected pour rester mockables en test — onlyMethods())
    // =========================================================================

    /**
     * Vérifie le droit dédié `doli2shop->api_read`, distinct des droits UI
     * (`read`/`write`/`delete`). Lève une RestException 403 si absent.
     *
     * Le contrôle DOLAPIKEY (401 si absent/invalide) est géré en amont par
     * le framework Restler/Dolibarr (annotation de classe ci-dessus) — cette
     * méthode ne gère QUE l'autorisation applicative (droit métier).
     *
     * @return void
     * @throws RestException 403 Droit doli2shop->api_read manquant
     */
    protected function checkApiReadRight()
    {
        if (!DolibarrApiAccess::$user->hasRight('doli2shop', 'api_read')) {
            throw new RestException(403, "Droit doli2shop->api_read requis");
        }
    }

    /**
     * Résout l'entity Dolibarr à utiliser pour filtrer les requêtes (garde
     * multi-entity systématique — invariant §14 CLAUDE.dolibarr.md).
     *
     * @return int Entity courante de l'utilisateur authentifié (fallback $conf->entity)
     */
    protected function getRequestEntity()
    {
        global $conf;
        if (isset(DolibarrApiAccess::$user) && DolibarrApiAccess::$user !== null && isset(DolibarrApiAccess::$user->entity) && (int) DolibarrApiAccess::$user->entity > 0) {
            return (int) DolibarrApiAccess::$user->entity;
        }
        return isset($conf->entity) ? (int) $conf->entity : 1;
    }

    /**
     * Clamp la pagination : défaut 100 si absent/0/négatif, max 500.
     *
     * @param  int $limit Limite demandée
     * @return int         Limite clampée dans [1, 500]
     */
    protected function clampLimit($limit)
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return 100;
        }
        if ($limit > 500) {
            return 500;
        }
        return $limit;
    }

    /**
     * Valide un filtre de date au format AAAA-MM-JJ (chaîne vide = pas de filtre, autorisé).
     * Rejette tout autre format (ex: epoch, DD/MM/YYYY) avec une 400 explicite.
     *
     * @param  mixed  $value     Valeur reçue
     * @param  string $fieldName Nom du champ (pour le message d'erreur)
     * @return void
     * @throws RestException 400 Format de date invalide
     */
    protected function assertValidDateOrEmpty($value, $fieldName)
    {
        if ($value === '' || $value === null) {
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            throw new RestException(400, $fieldName . ' : format invalide, attendu AAAA-MM-JJ (reçu "' . $value . '")');
        }
        list($y, $m, $d) = array_map('intval', explode('-', (string) $value));
        if (!checkdate($m, $d, $y)) {
            throw new RestException(400, $fieldName . ' : date invalide (' . $value . ')');
        }
    }

    /**
     * Vérifie le droit dédié `doli2shop->api_operate`, INDÉPENDANT de `api_read` (un token
     * operate-only peut agir sans lire — droits Dolibarr indépendants, documenté Dev Notes
     * story 54-2). Lève une RestException 403 si absent.
     *
     * @return void
     * @throws RestException 403 Droit doli2shop->api_operate manquant
     */
    protected function checkApiOperateRight()
    {
        if (!DolibarrApiAccess::$user->hasRight('doli2shop', 'api_operate')) {
            throw new RestException(403, "Droit doli2shop->api_operate requis");
        }
    }

    /**
     * Résout un `store_id` optionnel (0 = pas de scoping boutique) en objet boutique validé
     * entity, pour les 3 endpoints d'action qui acceptent ce paramètre (recalage, catchup,
     * products/sync). Invariant §14 CLAUDE.dolibarr.md : jamais de filtre si storeId <= 0.
     *
     * @param  int $storeId Rowid boutique demandé (0 = pas de scoping)
     * @param  int $entity  Entity courante de l'utilisateur API
     * @return object|null  Boutique validée (entity OK), ou null si $storeId <= 0
     * @throws RestException 404 store_id inconnu ou hors entity
     */
    protected function resolveOptionalStore($storeId, $entity)
    {
        $storeId = (int) $storeId;
        if ($storeId <= 0) {
            return null;
        }

        $store = $this->createStoreService($entity)->fetch($storeId);
        if ($store === null || (int) $store->entity !== (int) $entity) {
            throw new RestException(404, 'store_id inconnu ou hors entité (store_id=' . $storeId . ')');
        }

        // HIGH-2 (review 54-2) : un store_id valide mais DÉSACTIVÉ ou NON LICENCIÉ ne doit jamais
        // déclencher de vrais appels Shopify pour les endpoints qui n'ont pas la gate interne du
        // ShopifyStockTrigger (ordersCatchup, productsSync). On applique ici la MÊME gate que
        // pushStockForStore : boutique par défaut jamais bloquée (le helper le garantit), secondaire
        // désactivée/non licenciée = 409 explicite (invariant §14).
        if (empty($store->active)) {
            throw new RestException(409, 'Boutique désactivée — synchronisation refusée (store_id=' . $storeId . ')');
        }
        $licence = doli2shopStoreSyncAllowed($store);
        if (empty($licence['allowed'])) {
            throw new RestException(
                409,
                'Boutique non autorisée à synchroniser (licence: ' . ($licence['reason'] ?? 'inconnue') . ', store_id=' . $storeId . ')'
            );
        }
        return $store;
    }

    /**
     * Charge un produit Dolibarr en garantissant qu'il appartient à l'entity de l'utilisateur API
     * (CRITICAL review 54-2) : `Product::fetch($id)` du core NE filtre PAS par entity sur un id
     * numérique — sans ce contrôle, un token entity=2 pourrait pousser/resynchroniser un produit
     * d'entity=1. On renvoie le MÊME 404 que « introuvable » pour ne pas révéler l'existence du
     * produit dans une autre société.
     *
     * @param  int $productId
     * @param  int $entity
     * @return Product
     * @throws RestException 404 Produit introuvable OU hors entity
     */
    protected function fetchProductInEntityOrFail($productId, $entity)
    {
        $product = $this->createProduct($entity);
        if ($product->fetch($productId) <= 0 || (int) $product->entity !== (int) $entity) {
            throw new RestException(404, 'Produit Dolibarr introuvable (id=' . $productId . ')');
        }
        return $product;
    }

    /**
     * Sanitise un tableau d'erreurs remontées par un service avant de le renvoyer dans une réponse
     * API (HIGH-1 review 54-2) : les `$service->errors`/`errorDetails` concatènent des messages
     * bruts (SQL lasterror, exceptions Guzzle réseau, getMessage arbitraires) qui ne doivent JAMAIS
     * fuiter vers un tiers. Le détail complet part en `dol_syslog` ; la réponse ne conserve qu'un
     * message générique par erreur (l'agent connaît ainsi le NOMBRE d'erreurs, pas leur contenu infra).
     *
     * @param  mixed  $errors  Tableau d'erreurs du service (ou vide)
     * @param  string $context Nom de l'action (pour le log serveur)
     * @return array  Messages génériques (même cardinalité que $errors)
     */
    protected function sanitizeErrors($errors, $context)
    {
        if (empty($errors) || !is_array($errors)) {
            return array();
        }
        $out = array();
        foreach ($errors as $i => $err) {
            if (function_exists('dol_syslog')) {
                dol_syslog(
                    'Doli2Shop::' . $context . ' - erreur service #' . $i . ': '
                        . (is_string($err) ? $err : json_encode($err)),
                    LOG_ERR
                );
            }
            $out[] = "Erreur lors de l'action (détail en logs serveur)";
        }
        return $out;
    }

    /**
     * Gabarit commun d'exécution d'une action (Task 1, AC #7/#8) : exécute la closure et
     * normalise TOUTE exception imprévue en `RestException(500, message générique)` — le détail
     * complet part en `dol_syslog`, JAMAIS dans la réponse API (aucune stack trace, aucun détail
     * d'infra/credentials). Les `RestException` intentionnelles levées par l'action elle-même
     * (400/403/404/409, déjà normalisées) traversent SANS transformation.
     *
     * @param  string   $actionName Nom court de l'action (pour le message de log serveur)
     * @param  callable $action     Closure exécutant l'action, retourne le tableau de réponse
     * @return array Résultat de l'action
     * @throws RestException Propagée telle quelle si levée par l'action, sinon 500 générique
     */
    protected function runAction($actionName, callable $action)
    {
        try {
            return $action();
        } catch (RestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (function_exists('dol_syslog')) {
                dol_syslog('Doli2Shop::' . $actionName . ' - Exception non gérée: ' . $e->getMessage(), LOG_ERR);
            }
            throw new RestException(500, "Erreur interne lors de l'exécution de l'action « " . $actionName . " » — voir les logs serveur");
        }
    }

    /**
     * Journalise une action API (AC #6) — non-bloquant par construction (ActionLogger::log()
     * avale toute exception). Story 54-2 : transmet le 8ᵉ paramètre `fk_store` (comble la dette
     * v2.3.9 — la colonne existait mais aucun writer ne la renseignait).
     *
     * @param  string   $actionType Type d'action (constante ActionLogger::TYPE_*)
     * @param  string|null $objectType Type d'objet Dolibarr concerné (ou null)
     * @param  int|null $objectId   Rowid de l'objet concerné (ou null)
     * @param  string   $result     Résultat (constante ActionLogger::RESULT_*)
     * @param  string   $message    Message court
     * @param  int|null $fkStore    Rowid boutique concernée (null = non applicable/toutes)
     * @return void
     */
    protected function logApiAction($actionType, $objectType, $objectId, $result, $message, $fkStore = null)
    {
        ActionLogger::log($this->db, $this->getRequestEntity(), $actionType, $objectType, $objectId, $result, $message, $fkStore);
    }

    // =========================================================================
    // COLLABORATEURS MOCKABLES (protected — onlyMethods() en test, pattern
    // createStockTrigger() de StockRecalageService)
    // =========================================================================

    /**
     * @param  int $entity
     * @return Product
     */
    protected function createProduct($entity)
    {
        return new Product($this->db);
    }

    /**
     * Factory protégée SyncFlowPolicy (Story 53-3, site 7 — remplace createSyncUtils()).
     *
     * @param  int $entity
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy($entity)
    {
        return new SyncFlowPolicy($this->db, $entity);
    }

    /**
     * @param  int $entity
     * @return ShopifyStockTrigger
     */
    protected function createStockTrigger($entity)
    {
        return new ShopifyStockTrigger($this->db, $entity);
    }

    /**
     * @param  int $entity
     * @return StockRecalageService
     */
    protected function createStockRecalageService($entity)
    {
        return new StockRecalageService($this->db, $entity);
    }

    /**
     * @param  int $entity
     * @return StoreService
     */
    protected function createStoreService($entity)
    {
        return new StoreService($this->db, $entity);
    }

    /**
     * @param  int         $entity
     * @param  object|null $store  Boutique validée (null = chemin historique entité/constantes)
     * @return importProducts
     */
    protected function createImportProducts($entity, $store)
    {
        return new importProducts($this->db, (int) $entity, $store);
    }

    /**
     * Factory brute (mockable) de `ShopifyOrderManager` — peut lever la `\Exception` native du
     * constructeur (`checkUserPermissions()`, cf. `instantiateOrderManagerOrFail()` qui la
     * mappe en 403 explicite).
     *
     * @param  int         $entity
     * @param  object|null $store  Boutique validée (null = chemin historique entité/constantes)
     * @return ShopifyOrderManager
     */
    protected function createOrderManager($entity, $store)
    {
        return new ShopifyOrderManager($this->db, $entity, $store);
    }

    /**
     * Instancie `ShopifyOrderManager` en mappant explicitement l'`Exception` de
     * `checkUserPermissions()` (droits Dolibarr standard manquants pour l'utilisateur du token
     * API) en `RestException(403)` — JAMAIS un 500 opaque (AC #4, P1 validation 2026-07-18).
     * Toute AUTRE exception du constructeur (ex. échec d'init `ShopifyApi`) est laissée
     * remonter : `runAction()` la normalisera en 500 générique.
     *
     * @param  int         $entity
     * @param  object|null $store
     * @return ShopifyOrderManager
     * @throws RestException 403 Droits Dolibarr standard manquants pour l'utilisateur du token API
     */
    protected function instantiateOrderManagerOrFail($entity, $store)
    {
        try {
            return $this->createOrderManager($entity, $store);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), ShopifyOrderManager::ERR_INSUFFICIENT_RIGHTS_PREFIX) !== false) {
                throw new RestException(
                    403,
                    "L'utilisateur API n'a pas les droits Dolibarr requis pour le rattrapage de commandes "
                        . "(société lire/créer, commande lire/créer, produit lire, facture créer/valider)"
                );
            }
            throw $e;
        }
    }

    // =========================================================================
    // ENDPOINTS D'ACTION (Story 54-2)
    // =========================================================================

    /**
     * Push stock ciblé d'UN produit Dolibarr vers Shopify (toutes boutiques mappées où le
     * produit est éligible — pas de scoping `store_id`, cf. `ShopifyStockTrigger::updateShopifyStock()`
     * qui boucle déjà sur les boutiques actives). Bypass du plafond de résolutions API : contexte
     * admin/agent délibéré, même statut que le recalage (Dev Notes story 54-2).
     *
     * @url POST /stock/push/{productId}
     *
     * @param int $productId ID du produit Dolibarr à pousser
     *
     * @return array {success, action, product_id, pushed_stock, errors[]}
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_operate manquant
     * @throws RestException 400 productId invalide
     * @throws RestException 404 Produit Dolibarr introuvable
     * @throws RestException 409 Synchronisation stock Dolibarr→Shopify désactivée en configuration
     * @throws RestException 500 Erreur interne (détail en logs serveur)
     */
    public function stockPush($productId)
    {
        $this->checkApiOperateRight();

        $productId = (int) $productId;
        if ($productId <= 0) {
            throw new RestException(400, 'productId invalide (reçu ' . $productId . ')');
        }

        $entity = $this->getRequestEntity();

        return $this->runAction('stock_push', function () use ($productId, $entity) {
            $this->fetchProductInEntityOrFail($productId, $entity); // CRITICAL : garde entity

            // Story 53-3 (site 7) : garde SyncFlowPolicy (flux stock, d2s, storeId=0 par conception —
            // push toutes boutiques mappées, cf. ShopifyStockTrigger::updateShopifyStock() ci-dessous).
            if (!$this->createSyncFlowPolicy($entity)->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'dolibarr_to_shopify', 0)) {
                throw new RestException(
                    409,
                    'Synchronisation stock Dolibarr→Shopify désactivée en configuration — action refusée '
                        . '(jamais de faux succès)'
                );
            }

            // Contexte API = requête fraîche : reset des registres statiques process-scoped
            // (dédup, cache gate-licence, compteur plafond API) avant le push, pattern batch.
            ShopifyStockTrigger::resetPushRegistry();

            $trigger = $this->createStockTrigger($entity);
            $pushedStockOut = null;
            $result = $trigger->updateShopifyStock($productId, $pushedStockOut, true);

            $success = ($result === 0 && empty($trigger->errors));
            $this->logApiAction(
                ActionLogger::TYPE_STOCK_PUSH,
                'product',
                $productId,
                $success ? ActionLogger::RESULT_SUCCESS : ActionLogger::RESULT_ERROR,
                'API stock/push produit ID ' . $productId . ' : valeur poussée=' . ($pushedStockOut !== null ? $pushedStockOut : 'n/a')
            );

            return array(
                'success' => $success,
                'action' => 'stock_push',
                'product_id' => $productId,
                'pushed_stock' => $pushedStockOut,
                'errors' => $this->sanitizeErrors($trigger->errors, 'stock_push'),
            );
        });
    }

    /**
     * Traite UN lot de recalage initial en masse du stock Dolibarr → Shopify. L'agent appelant
     * boucle en ré-appelant avec `offset = nextOffset` tant que `hasMore` est vrai (même contrat
     * que l'UI admin JS). **`confirm=1` obligatoire à CHAQUE appel** (400 pédagogique sinon) :
     * action lourde (écrase le stock Shopify par les valeurs Dolibarr), exiger la confirmation à
     * chaque lot est le choix le plus protecteur (documenté Dev Notes story 54-2, décision dev).
     *
     * @url POST /stock/recalage
     *
     * @param int $confirm  Confirmation explicite de l'action lourde (1 = confirmé, sinon 400)
     * @param int $offset   Décalage de pagination du lot (défaut 0)
     * @param int $store_id Rowid boutique optionnel (0 = toutes les boutiques de l'entité)
     *
     * @return array {success, action, processed, pushed, errors, skipped, hasMore, nextOffset, total, errorDetails[]}
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_operate manquant
     * @throws RestException 400 confirm != 1
     * @throws RestException 404 store_id inconnu ou hors entity
     * @throws RestException 500 Erreur interne (détail en logs serveur)
     */
    public function stockRecalage($confirm = 0, $offset = 0, $store_id = 0)
    {
        $this->checkApiOperateRight();

        if (!is_numeric($confirm) || (int) $confirm !== 1) {
            throw new RestException(
                400,
                "confirm=1 requis : action lourde — écrase le stock Shopify par les valeurs Dolibarr. "
                    . "Relancer l'appel avec le paramètre confirm=1 pour exécuter ce lot."
            );
        }

        $entity = $this->getRequestEntity();
        $offset = max(0, (int) $offset);

        return $this->runAction('stock_recalage', function () use ($offset, $store_id, $entity) {
            $store = $this->resolveOptionalStore($store_id, $entity);
            $storeId = $store !== null ? (int) $store->rowid : 0;

            $service = $this->createStockRecalageService($entity);
            $result = $service->processBatch($offset, $storeId);
            $total = $service->countMappedProducts($storeId);

            $success = ((int) $result['errors'] === 0);
            $this->logApiAction(
                ActionLogger::TYPE_STOCK_RECALAGE,
                null,
                null,
                $success ? ActionLogger::RESULT_SUCCESS : ActionLogger::RESULT_ERROR,
                'API stock/recalage lot offset=' . $offset . ' : ' . $result['pushed'] . ' poussés, '
                    . $result['errors'] . ' erreurs, ' . $result['skipped'] . ' ignorés',
                $storeId > 0 ? $storeId : null
            );

            return array(
                'success' => $success,
                'action' => 'stock_recalage',
                'processed' => $result['processed'],
                'pushed' => $result['pushed'],
                'errors' => $result['errors'],
                'skipped' => $result['skipped'],
                'hasMore' => $result['hasMore'],
                'nextOffset' => $result['nextOffset'],
                'total' => $total,
                'errorDetails' => $this->sanitizeErrors($result['errorDetails'], 'stock_recalage'),
            );
        });
    }

    /**
     * Rattrapage des commandes Shopify manquantes (comparaison Shopify vs Dolibarr sur une
     * fenêtre de recherche bornée).
     *
     * ⚠ Prérequis droits Dolibarr STANDARD (non-`doli2shop`) pour l'utilisateur propriétaire
     * du token API : société (lire/créer), commande (lire/créer), produit (lire), facture
     * (créer/valider) — le constructeur de `ShopifyOrderManager` les vérifie et lève une 403
     * explicite si absents (jamais un 500 opaque, AC #4).
     *
     * @url POST /orders/catchup
     *
     * @param int $lookback_hours Fenêtre de recherche en heures (défaut 168, borné 1..720)
     * @param int $store_id       Rowid boutique optionnel (0 = boutique par défaut/legacy)
     *
     * @return array {success, action, checked, missing, imported, failed, skipped_pending, errors[]}
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_operate manquant, OU droits Dolibarr standard
     *                           manquants pour l'utilisateur du token API (cf. prérequis ci-dessus)
     * @throws RestException 400 lookback_hours hors bornes [1, 720]
     * @throws RestException 404 store_id inconnu ou hors entity
     * @throws RestException 500 Erreur interne (détail en logs serveur)
     */
    public function ordersCatchup($lookback_hours = 168, $store_id = 0)
    {
        $this->checkApiOperateRight();

        $lookbackHours = (int) $lookback_hours;
        if ($lookbackHours < 1 || $lookbackHours > 720) {
            throw new RestException(400, 'lookback_hours doit être compris entre 1 et 720 (reçu ' . $lookbackHours . ')');
        }

        $entity = $this->getRequestEntity();

        return $this->runAction('orders_catchup', function () use ($lookbackHours, $store_id, $entity) {
            $store = $this->resolveOptionalStore($store_id, $entity);
            $storeId = $store !== null ? (int) $store->rowid : 0;

            $orderManager = $this->instantiateOrderManagerOrFail($entity, $store);
            $counters = $orderManager->catchupMissingOrders($lookbackHours);

            $success = ((int) $counters['failed'] === 0);
            $this->logApiAction(
                ActionLogger::TYPE_ORDERS_CATCHUP,
                null,
                null,
                $success ? ActionLogger::RESULT_SUCCESS : ActionLogger::RESULT_ERROR,
                'API orders/catchup lookback=' . $lookbackHours . 'h : ' . $counters['checked'] . ' vérifiées, '
                    . $counters['imported'] . ' importées, ' . $counters['failed'] . ' échecs',
                $storeId > 0 ? $storeId : null
            );

            return array(
                'success' => $success,
                'action' => 'orders_catchup',
                'checked' => $counters['checked'],
                'missing' => $counters['missing'],
                'imported' => $counters['imported'],
                'failed' => $counters['failed'],
                'skipped_pending' => $counters['skipped_pending'],
                'errors' => $this->sanitizeErrors($orderManager->errors, 'orders_catchup'),
            );
        });
    }

    /**
     * Resync ciblée d'UN produit Dolibarr → Shopify (jamais de tableau exposé côté API : un seul
     * produit par appel, protection durée d'exécution — Dev Notes story 54-2). Multi-boutiques :
     * `store_id` optionnel, cohérent avec le scoping du batch AJAX existant.
     *
     * @url POST /products/sync/{productId}
     *
     * @param int $productId ID du produit Dolibarr à resynchroniser
     * @param int $store_id  Rowid boutique optionnel (0 = toutes/legacy)
     *
     * @return array {success, action, product_id, result, errors[]}
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_operate manquant
     * @throws RestException 400 productId invalide
     * @throws RestException 404 Produit Dolibarr introuvable, OU store_id inconnu/hors entity
     * @throws RestException 500 Erreur interne (détail en logs serveur)
     */
    public function productsSync($productId, $store_id = 0)
    {
        $this->checkApiOperateRight();

        $productId = (int) $productId;
        if ($productId <= 0) {
            throw new RestException(400, 'productId invalide (reçu ' . $productId . ')');
        }

        $entity = $this->getRequestEntity();

        return $this->runAction('products_sync', function () use ($productId, $store_id, $entity) {
            $this->fetchProductInEntityOrFail($productId, $entity); // CRITICAL : garde entity

            $store = $this->resolveOptionalStore($store_id, $entity);
            $storeId = $store !== null ? (int) $store->rowid : 0;

            $importProducts = $this->createImportProducts($entity, $store);
            // importProductsManual() retourne un INT (pas un tableau) : >0 = nb synchronisé,
            // 0 = no-op (skip), <0 = erreur (Dev Notes story 54-2, précisions validation).
            $result = $importProducts->importProductsManual(array($productId));

            $success = ($result >= 0);
            $status = $result > 0 ? ActionLogger::RESULT_SUCCESS : ($result === 0 ? ActionLogger::RESULT_SKIPPED : ActionLogger::RESULT_ERROR);

            $errors = array();
            if ($result < 0 && !empty($importProducts->error)) {
                $errors = $this->sanitizeErrors(array($importProducts->error), 'products_sync');
            }

            $this->logApiAction(
                ActionLogger::TYPE_PRODUCTS_SYNC,
                'product',
                $productId,
                $status,
                'API products/sync produit ID ' . $productId . ' : résultat=' . $result,
                $storeId > 0 ? $storeId : null
            );

            return array(
                'success' => $success,
                'action' => 'products_sync',
                'product_id' => $productId,
                'result' => $result,
                'errors' => $errors,
            );
        });
    }

    // =========================================================================
    // ENDPOINTS DE LECTURE (Story 54-1)
    // =========================================================================

    /**
     * Statut synthétique du module : version, boutiques déclarées/actives,
     * directions de synchronisation, dernier recalage stock, compteurs clés.
     *
     * AUCUN secret n'est jamais retourné par cet endpoint.
     *
     * @url GET /status
     *
     * @return array Statut du module (module_version, stores, sync, last_stock_recalage, mapped_products_count, pending_webhook_events)
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_read manquant
     */
    public function status()
    {
        $this->checkApiReadRight();

        $entity = $this->getRequestEntity();

        $storeService = new StoreService($this->db, $entity);
        $storesDeclared = $storeService->countAllOrNull();
        // M1 (review 54-1) : countAllOrNull() distingue erreur SQL (null) de "0 boutique" —
        // un agent de monitoring ne doit JAMAIS recevoir un 200 avec des compteurs à zéro
        // sur un simple hoquet DB (getAll() retourne [] dans les deux cas, ambigu).
        if ($storesDeclared === null) {
            throw new RestException(503, 'Erreur base de données en comptant les boutiques');
        }
        $activeStores = $storeService->getAll(true);

        $mappedProductsCount = 0;
        $sql = 'SELECT COUNT(DISTINCT fk_product) as nb FROM ' . MAIN_DB_PREFIX . 'doli2shop_products';
        $sql .= ' WHERE entity = ' . (int) $entity;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $mappedProductsCount = (int) ($obj->nb ?? 0);
        }

        // Compteur webhooks en attente : disponible à coût raisonnable (index
        // (status, tries, entity, date_reception) sur llx_doli2shop_webhook_events).
        // null si la requête échoue (jamais interprété comme "0 en attente").
        $pendingWebhookEvents = null;
        $sqlPending = 'SELECT COUNT(*) as nb FROM ' . MAIN_DB_PREFIX . 'doli2shop_webhook_events';
        $sqlPending .= ' WHERE status = 0 AND entity = ' . (int) $entity;
        $resqlPending = $this->db->query($sqlPending);
        if ($resqlPending) {
            $objPending = $this->db->fetch_object($resqlPending);
            $pendingWebhookEvents = (int) ($objPending->nb ?? 0);
        }

        return array(
            'module_version' => DOLI2SHOP_MODULE_VERSION,
            'stores' => array(
                'declared' => $storesDeclared,
                'active' => count($activeStores),
            ),
            'sync' => array(
                'orders_direction' => getDolGlobalString('DOLI2SHOP_SYNC_ORDERS_DIRECTION', 'shopify_to_dolibarr'),
                'stocks_direction' => getDolGlobalString('DOLI2SHOP_SYNC_STOCKS_DIRECTION', SyncFlowPolicy::DEFAULT_STOCKS_DIRECTION),
                'products_direction' => getDolGlobalString('DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', 'both'),
                'use_virtual_stock' => (bool) getDolGlobalInt('DOLI2SHOP_USE_VIRTUAL_STOCK', 0),
                'sync_product_stocks' => (bool) getDolGlobalInt('DOLI2SHOP_SYNC_PRODUCT_STOCKS', 0),
            ),
            'last_stock_recalage' => array(
                'date' => getDolGlobalString('DOLI2SHOP_LAST_STOCK_RECALAGE', ''),
                'count' => getDolGlobalInt('DOLI2SHOP_LAST_STOCK_RECALAGE_COUNT', 0),
                'errors' => getDolGlobalInt('DOLI2SHOP_LAST_STOCK_RECALAGE_ERRORS', 0),
            ),
            'mapped_products_count' => $mappedProductsCount,
            'pending_webhook_events' => $pendingWebhookEvents,
        );
    }

    /**
     * Liste des boutiques Shopify configurées.
     *
     * Projection WHITELIST stricte, champ par champ : rowid, label, shop_domain,
     * active, is_default, license_status, token_reconnect_required, dates.
     * JAMAIS l'objet brut (qui porte access_token/refresh_token/api_secret/serial_number).
     *
     * @url GET /stores
     *
     * @return array Liste des boutiques (champs whitelistés uniquement)
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_read manquant
     */
    public function stores()
    {
        $this->checkApiReadRight();

        $entity = $this->getRequestEntity();
        $storeService = new StoreService($this->db, $entity);
        // M1 (review 54-1) : getAll() retourne [] aussi bien sur "0 boutique" que sur erreur
        // SQL — lever un 503 explicite sur erreur plutôt que renvoyer une liste vide trompeuse.
        if ($storeService->countAllOrNull() === null) {
            throw new RestException(503, 'Erreur base de données en listant les boutiques');
        }
        $stores = $storeService->getAll(false);

        $result = array();
        foreach ($stores as $store) {
            // Projection whitelist STRICTE — n'ajouter un champ ici qu'après
            // revue explicite (aucun credential ne doit jamais transiter).
            $result[] = array(
                'rowid' => (int) ($store->rowid ?? 0),
                'label' => (string) ($store->label ?? ''),
                'shop_domain' => (string) ($store->shop_domain ?? ''),
                'active' => (bool) ($store->active ?? 0),
                'is_default' => (bool) ($store->is_default ?? 0),
                'license_status' => (string) ($store->license_status ?? 'unknown'),
                'token_reconnect_required' => (bool) ($store->token_reconnect_required ?? 0),
                'date_creation' => $store->datec ?? null,
                'date_modification' => $store->tms ?? null,
            );
        }
        return $result;
    }

    /**
     * Mappings produits Dolibarr <-> Shopify (llx_doli2shop_products), paginés et filtrés.
     *
     * @url GET /mappings/products
     *
     * @param int    $fk_product Filtre exact sur l'id produit Dolibarr (0 = pas de filtre)
     * @param int    $fk_store   Filtre exact sur l'id boutique (0 = pas de filtre — la table n'a pas de fk_store=0 significatif hors legacy)
     * @param string $sku        Filtre exact sur la référence produit Dolibarr (llx_product.ref via JOIN — la table de mapping n'a pas de colonne sku)
     * @param int    $limit      Pagination : nombre de lignes (défaut 100, max 500)
     * @param int    $page       Pagination : numéro de page (0-indexé)
     *
     * @return array Liste des mappings (fk_product, product_ref, shopify_product_id, shopify_variant_id, fk_store, tms, last_stock_sync)
     *
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_read manquant
     * @throws RestException 503 Erreur SQL
     */
    public function mappingsProducts($fk_product = 0, $fk_store = 0, $sku = '', $limit = 100, $page = 0)
    {
        $this->checkApiReadRight();

        $entity = $this->getRequestEntity();
        $limit = $this->clampLimit($limit);
        $page = max(0, (int) $page);
        $offset = $limit * $page;

        $sql = 'SELECT dp.id, dp.fk_product, p.ref as product_ref, dp.shopifyProductId, dp.shopifyVariantId,';
        $sql .= ' dp.fk_store, dp.tms, dp.last_stock_sync';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_products as dp';
        $sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'product as p ON p.rowid = dp.fk_product';
        $sql .= ' WHERE dp.entity = ' . (int) $entity;

        if ((int) $fk_product > 0) {
            $sql .= ' AND dp.fk_product = ' . (int) $fk_product;
        }
        if ((int) $fk_store > 0) {
            $sql .= ' AND dp.fk_store = ' . (int) $fk_store;
        }
        if ($sku !== '' && $sku !== null) {
            $sql .= " AND p.ref = '" . $this->db->escape($sku) . "'";
        }

        $sql .= ' ORDER BY dp.tms DESC';
        $sql .= $this->db->plimit($limit, $offset);

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(503, 'Erreur SQL mappings produits: ' . $this->db->lasterror());
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'id' => (int) $obj->id,
                'fk_product' => (int) $obj->fk_product,
                'product_ref' => $obj->product_ref,
                'shopify_product_id' => $obj->shopifyProductId,
                'shopify_variant_id' => $obj->shopifyVariantId,
                'fk_store' => (int) $obj->fk_store,
                'tms' => $obj->tms,
                'last_stock_sync' => $obj->last_stock_sync,
            );
        }
        return $rows;
    }

    /**
     * Journal d'actions du module (llx_doli2shop_action_log), paginé et filtré,
     * trié par date décroissante.
     *
     * @url GET /actionlog
     *
     * @param string $action_type Filtre exact sur le type d'action (ex: stock_recalage, order_auto_closed)
     * @param string $result      Filtre exact sur le résultat (success, error, skipped)
     * @param string $date_min    Filtre borne basse sur date_action, format AAAA-MM-JJ (400 si invalide)
     * @param string $date_max    Filtre borne haute sur date_action, format AAAA-MM-JJ (400 si invalide)
     * @param int    $fk_store    Filtre exact sur l'id boutique (0 = pas de filtre)
     * @param int    $limit       Pagination : nombre de lignes (défaut 100, max 500)
     * @param int    $page        Pagination : numéro de page (0-indexé)
     *
     * @return array Liste des entrées du journal (rowid, date_action, action_type, object_type, object_id, result, message, fk_store)
     *
     * @throws RestException 400 Format de date_min/date_max invalide
     * @throws RestException 401 Authentification DOLAPIKEY manquante ou invalide
     * @throws RestException 403 Droit doli2shop->api_read manquant
     * @throws RestException 503 Erreur SQL
     */
    public function actionlog($action_type = '', $result = '', $date_min = '', $date_max = '', $fk_store = 0, $limit = 100, $page = 0)
    {
        $this->checkApiReadRight();

        $this->assertValidDateOrEmpty($date_min, 'date_min');
        $this->assertValidDateOrEmpty($date_max, 'date_max');

        $entity = $this->getRequestEntity();
        $limit = $this->clampLimit($limit);
        $page = max(0, (int) $page);
        $offset = $limit * $page;

        $sql = 'SELECT rowid, date_action, action_type, object_type, object_id, result, message, fk_store';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_action_log';
        $sql .= ' WHERE entity = ' . (int) $entity;

        if ($action_type !== '' && $action_type !== null) {
            $sql .= " AND action_type = '" . $this->db->escape($action_type) . "'";
        }
        if ($result !== '' && $result !== null) {
            $sql .= " AND result = '" . $this->db->escape($result) . "'";
        }
        if ($date_min !== '' && $date_min !== null) {
            $sql .= " AND date_action >= '" . $this->db->escape($date_min) . " 00:00:00'";
        }
        if ($date_max !== '' && $date_max !== null) {
            $sql .= " AND date_action <= '" . $this->db->escape($date_max) . " 23:59:59'";
        }
        if ((int) $fk_store > 0) {
            $sql .= ' AND fk_store = ' . (int) $fk_store;
        }

        $sql .= ' ORDER BY date_action DESC';
        $sql .= $this->db->plimit($limit, $offset);

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(503, 'Erreur SQL actionlog: ' . $this->db->lasterror());
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'rowid' => (int) $obj->rowid,
                'date_action' => $obj->date_action,
                'action_type' => $obj->action_type,
                'object_type' => $obj->object_type,
                'object_id' => $obj->object_id !== null ? (int) $obj->object_id : null,
                'result' => $obj->result,
                'message' => $obj->message,
                'fk_store' => $obj->fk_store !== null ? (int) $obj->fk_store : null,
            );
        }
        return $rows;
    }
}
