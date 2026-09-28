<?php
/**
 * @file        class/syncflowpolicy.class.php
 * @brief       Service central de politique de synchronisation directionnelle (Epic 53, Story 53-1)
 *
 * Modélise la direction de synchronisation des 11 flux Doli2Shop (par boutique, avec héritage du
 * défaut global et dérivation automatique depuis les réglages legacy existants). Backend seul :
 * aucun chemin de sync n'est modifié par cette classe (branchement = Story 53-3), aucune UI
 * (écran matrice = Story 53-2).
 *
 * Décisions d'architecture (Dev Notes Story 53-1 — ne pas remettre en cause) :
 * - Pas de nouvelle table ni de migration : overrides explicites = constantes
 *   `DOLI2SHOP_SYNC_FLOW_<ID>` (global, llx_const) + clés `SYNC_FLOW_<ID>` (per-store,
 *   llx_doli2shop_store_settings via StoreSettings).
 * - DEUX namespaces de clés distincts, jamais partagés : `SYNC_FLOW_<ID>` (direction) et
 *   `SYNC_FLOW_<ID>_CONFLICT` (stratégie de conflit).
 * - Vocabulaire direction réutilisé de SyncUtils::isSyncEnabled :
 *   none|both|shopify_to_dolibarr|dolibarr_to_shopify.
 * - Vocabulaire conflit réutilisé de DOLI2SHOP_CONFLICT_RESOLUTION_STRATEGY :
 *   shopify_wins|dolibarr_wins|newest_wins|oldest_wins|manual_resolution.
 * - Absence de TOUTE constante SYNC_FLOW_* → dérivation legacy (voir table de dérivation dans
 *   la story) ; une constante SYNC_FLOW_* posée gagne toujours sur la dérivation.
 * - La matrice reflète les RÉGLAGES, pas l'état d'armement des CRONs
 *   (DOLI2SHOP_ENABLE_PRODUCT_IMPORT_CRON, statuts cronjob…) : ces éléments restent orthogonaux
 *   et ne sont volontairement pas consommés ici.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.5.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/storesettings.class.php';

/**
 * Politique de synchronisation directionnelle : source de vérité unique pour les 11 flux
 * Doli2Shop, consommée par l'écran matrice (53-2) et l'enforcement dans les chemins de sync (53-3).
 */
class SyncFlowPolicy
{
    use LoggerTrait;

    // =========================================================================
    // Identifiants canoniques des 11 flux
    // =========================================================================

    public const FLOW_PRODUCT_CREATE = 'product_create';
    public const FLOW_PRODUCT_UPDATE = 'product_update';
    public const FLOW_PRODUCT_DELETE = 'product_delete';
    public const FLOW_STOCK = 'stock';
    public const FLOW_PRICE = 'price';
    public const FLOW_IMAGES = 'images';
    public const FLOW_COLLECTIONS = 'collections';
    public const FLOW_ORDER_CREATE = 'order_create';
    public const FLOW_ORDER_STATUS = 'order_status';
    public const FLOW_ORDER_PAYMENT = 'order_payment';
    public const FLOW_SHIPPING = 'shipping';

    /**
     * Liste canonique des 11 flux : id => [groupe, libellé-clé i18n réservée pour 53-2].
     * Groupes alignés sur le mockup UX validé (epic-53) : products, stock_price, images,
     * collections, orders, shipping.
     *
     * @var array<string,array{group:string,label:string}>
     */
    public const FLOWS = [
        self::FLOW_PRODUCT_CREATE => ['group' => 'products',    'label' => 'Doli2ShopFlowProductCreate'],
        self::FLOW_PRODUCT_UPDATE => ['group' => 'products',    'label' => 'Doli2ShopFlowProductUpdate'],
        self::FLOW_PRODUCT_DELETE => ['group' => 'products',    'label' => 'Doli2ShopFlowProductDelete'],
        self::FLOW_STOCK          => ['group' => 'stock_price', 'label' => 'Doli2ShopFlowStock'],
        self::FLOW_PRICE          => ['group' => 'stock_price', 'label' => 'Doli2ShopFlowPrice'],
        self::FLOW_IMAGES         => ['group' => 'images',      'label' => 'Doli2ShopFlowImages'],
        self::FLOW_COLLECTIONS    => ['group' => 'collections', 'label' => 'Doli2ShopFlowCollections'],
        self::FLOW_ORDER_CREATE   => ['group' => 'orders',      'label' => 'Doli2ShopFlowOrderCreate'],
        self::FLOW_ORDER_STATUS   => ['group' => 'orders',      'label' => 'Doli2ShopFlowOrderStatus'],
        self::FLOW_ORDER_PAYMENT  => ['group' => 'orders',      'label' => 'Doli2ShopFlowOrderPayment'],
        self::FLOW_SHIPPING       => ['group' => 'shipping',    'label' => 'Doli2ShopFlowShipping'],
    ];

    /**
     * Défaut du sens de synchronisation du STOCK (DOLI2SHOP_SYNC_STOCKS_DIRECTION / legacyString
     * 'SYNC_STOCKS_DIRECTION') quand aucun réglage n'existe en base.
     *
     * Source UNIQUE (story sens-du-stock-par-defaut-dolibarr-vers-shopify, décision du
     * mainteneur du 12/09/2026) : Dolibarr est la source de vérité du stock (réappro, production,
     * inventaire) — Shopify ne doit plus y écrire par défaut sur une installation qui n'a jamais
     * choisi ce comportement. `class/syncutils.class.php`, `class/api_doli2shop.class.php` et
     * `admin/syncoptions.php` dérivent tous de cette constante ; ne JAMAIS réintroduire un
     * littéral 'both'/'dolibarr_to_shopify' local à côté d'eux.
     *
     * ⚠️ Changement de comportement pour le parc installé sans réglage explicite (avant cette
     * story : 'both'). Un réglage explicite en base (dolibarr_set_const) reste respecté tel quel
     * — cette constante n'intervient que si la ligne est absente, cf. getDolGlobalString().
     *
     * @var string
     */
    public const DEFAULT_STOCKS_DIRECTION = 'dolibarr_to_shopify';

    /**
     * Flux considérés comme "famille produits" pour la dérivation de la stratégie de conflit
     * legacy : ils partagent SYNC_PRODUCTS_CONFLICT_RESOLUTION. Les collections ont leur propre
     * clé dédiée (SYNC_COLLECTIONS_CONFLICT_RESOLUTION), les commandes/expéditions utilisent la
     * stratégie globale legacy (CONFLICT_RESOLUTION_STRATEGY).
     *
     * @var string[]
     */
    private const PRODUCT_FAMILY_FLOWS = [
        self::FLOW_PRODUCT_CREATE,
        self::FLOW_PRODUCT_UPDATE,
        self::FLOW_PRODUCT_DELETE,
        self::FLOW_STOCK,
        self::FLOW_PRICE,
        self::FLOW_IMAGES,
    ];

    /** @var string[] Vocabulaire directions valides (identique à SyncUtils::isSyncEnabled) */
    public const DIRECTIONS = ['none', 'both', 'shopify_to_dolibarr', 'dolibarr_to_shopify'];

    /** @var string[] Directions "de contrôle" acceptées par isAllowed() (jamais none/both) */
    private const CHECK_DIRECTIONS = ['shopify_to_dolibarr', 'dolibarr_to_shopify'];

    /** @var string[] Vocabulaire stratégies de conflit valides (identique à CONFLICT_RESOLUTION_STRATEGY) */
    public const CONFLICT_STRATEGIES = ['shopify_wins', 'dolibarr_wins', 'newest_wins', 'oldest_wins', 'manual_resolution'];

    /** @var string Stratégie de conflit par défaut (alignée sur SyncUtils) */
    private const DEFAULT_CONFLICT_STRATEGY = 'newest_wins';

    /** @var string Préfixe des clés de direction : SYNC_FLOW_<ID> */
    private const DIRECTION_KEY_PREFIX = 'SYNC_FLOW_';

    /** @var string Suffixe des clés de stratégie de conflit : SYNC_FLOW_<ID>_CONFLICT */
    private const CONFLICT_KEY_SUFFIX = '_CONFLICT';

    /**
     * @var DoliDB Gestionnaire de base de données
     */
    private $db;

    /**
     * @var int Entité Dolibarr courante
     */
    private $entity;

    /**
     * @var StoreSettings|null Instance StoreSettings paresseuse (factory protégée pour les tests)
     */
    private $storeSettings;

    /**
     * @var string Dernier message d'erreur (rejet setDirection()/setConflictStrategy())
     */
    public $error = '';

    /**
     * Constructeur
     *
     * Limitation connue : $entity ne s'applique QUE aux écritures (writeSetting() →
     * dolibarr_set_const()) et à la portée per-store (StoreSettings, qui reçoit $entity au
     * constructeur). Les LECTURES de constantes globales (legacyString()/legacyBool()/
     * readOverrideValue() en portée globale, storeId<=0) passent par getDolGlobalString()/
     * getDolGlobalInt(), qui lisent $conf->global->* ambiant et restent donc liées à
     * $conf->entity courant — PAS à ce paramètre $entity. Comportement inchangé, documenté
     * uniquement (dette pré-existante, hors périmètre 53-1).
     *
     * @param DoliDB   $db     Gestionnaire base de données
     * @param int|null $entity Entité Dolibarr (null = $conf->entity courante)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
    }

    // =========================================================================
    // Lecture — clés canoniques
    // =========================================================================

    /**
     * Retourne la liste canonique des 11 flux (id, groupe, libellé i18n réservé, clé de réglage).
     *
     * @return array<int,array{id:string,group:string,label:string,key:string}>
     */
    public function getFlows(): array
    {
        $flows = [];
        foreach (self::FLOWS as $flowId => $meta) {
            $flows[] = [
                'id'    => $flowId,
                'group' => $meta['group'],
                'label' => $meta['label'],
                'key'   => self::directionKey($flowId),
            ];
        }
        return $flows;
    }

    /**
     * Clé de réglage direction pour un flux : SYNC_FLOW_<ID> (SANS préfixe DOLI2SHOP_, leçon 49-11).
     *
     * @param  string $flowId Identifiant du flux
     * @return string
     */
    public static function directionKey(string $flowId): string
    {
        return self::DIRECTION_KEY_PREFIX . strtoupper($flowId);
    }

    /**
     * Clé de réglage stratégie de conflit pour un flux : SYNC_FLOW_<ID>_CONFLICT.
     * Namespace DISTINCT de directionKey() — ne jamais réutiliser la clé de direction.
     *
     * @param  string $flowId Identifiant du flux
     * @return string
     */
    public static function conflictKey(string $flowId): string
    {
        return self::directionKey($flowId) . self::CONFLICT_KEY_SUFFIX;
    }

    // =========================================================================
    // Lecture — résolution direction / conflit / matrice
    // =========================================================================

    /**
     * Résout la direction effective d'un flux.
     *
     * Priorité : (1) override explicite SYNC_FLOW_<ID> (per-store via StoreSettings si
     * $storeId>0 — avec fallback natif vers l'override global, sinon constante globale directe)
     * → (2) dérivation legacy (table de dérivation Story 53-1) → (3) défaut du flux.
     *
     * @param  string $flowId  Identifiant du flux (cf. self::FLOWS)
     * @param  int    $storeId Identifiant boutique (0 = portée globale)
     * @return string          none|both|shopify_to_dolibarr|dolibarr_to_shopify
     */
    public function getDirection(string $flowId, int $storeId = 0): string
    {
        if (!array_key_exists($flowId, self::FLOWS)) {
            $this->log('getDirection() - flowId invalide: ' . $flowId, LOG_WARNING);
            return 'none';
        }
        $storeId = (int) $storeId;

        $override = $this->readOverrideValue(self::directionKey($flowId), $storeId);
        if ($override !== null) {
            if (in_array($override, self::DIRECTIONS, true)) {
                return $override;
            }
            $this->log(
                'getDirection() - override rejeté (hors vocabulaire) pour la clé '
                    . self::directionKey($flowId) . ': "' . $override . '" (storeId=' . $storeId
                    . ') — repli sur dérivation',
                LOG_WARNING
            );
        }

        return $this->deriveLegacyDirection($flowId, $storeId);
    }

    /**
     * Indique si la synchronisation est autorisée pour ce flux dans la direction donnée.
     * Même sémantique que SyncUtils::isSyncEnabled() : none → false, both → true, sinon égalité.
     *
     * @param  string $flowId    Identifiant du flux
     * @param  string $direction Direction à vérifier (shopify_to_dolibarr|dolibarr_to_shopify UNIQUEMENT)
     * @param  int    $storeId   Identifiant boutique (0 = portée globale)
     * @return bool
     */
    public function isAllowed(string $flowId, string $direction, int $storeId = 0): bool
    {
        if (!array_key_exists($flowId, self::FLOWS)) {
            $this->log('isAllowed() - flowId invalide: ' . $flowId, LOG_WARNING);
            return false;
        }
        if (!in_array($direction, self::CHECK_DIRECTIONS, true)) {
            $this->log('isAllowed() - direction invalide: ' . $direction, LOG_WARNING);
            return false;
        }

        $configured = $this->getDirection($flowId, $storeId);
        if ($configured === 'none') {
            return false;
        }

        return $configured === 'both' || $configured === $direction;
    }

    /**
     * Résout la stratégie de résolution de conflit effective d'un flux.
     *
     * Priorité : (1) override explicite SYNC_FLOW_<ID>_CONFLICT (même cascade que getDirection())
     * → (2) dérivation legacy (SYNC_PRODUCTS_CONFLICT_RESOLUTION pour la famille produits,
     * SYNC_COLLECTIONS_CONFLICT_RESOLUTION mappé pour collections, CONFLICT_RESOLUTION_STRATEGY
     * global sinon) → (3) défaut newest_wins.
     *
     * @param  string $flowId  Identifiant du flux
     * @param  int    $storeId Identifiant boutique (0 = portée globale)
     * @return string          shopify_wins|dolibarr_wins|newest_wins|oldest_wins|manual_resolution
     */
    public function getConflictStrategy(string $flowId, int $storeId = 0): string
    {
        if (!array_key_exists($flowId, self::FLOWS)) {
            $this->log('getConflictStrategy() - flowId invalide: ' . $flowId, LOG_WARNING);
            return self::DEFAULT_CONFLICT_STRATEGY;
        }
        $storeId = (int) $storeId;

        $override = $this->readOverrideValue(self::conflictKey($flowId), $storeId);
        if ($override !== null) {
            if (in_array($override, self::CONFLICT_STRATEGIES, true)) {
                return $override;
            }
            $this->log(
                'getConflictStrategy() - override rejeté (hors vocabulaire) pour la clé '
                    . self::conflictKey($flowId) . ': "' . $override . '" (storeId=' . $storeId
                    . ') — repli sur dérivation',
                LOG_WARNING
            );
        }

        return $this->deriveLegacyConflictStrategy($flowId, $storeId);
    }

    /**
     * Résout les 11 flux pour la boutique donnée (pour l'écran matrice 53-2).
     * Lecture de configuration pure : AUCUN appel API Shopify.
     *
     * @param  int $storeId Identifiant boutique (0 = portée globale)
     * @return array<int,array{id:string,group:string,label:string,direction:string,conflict_strategy:string}>
     */
    public function getMatrix(int $storeId = 0): array
    {
        $storeId = (int) $storeId;
        $matrix = [];
        foreach (self::FLOWS as $flowId => $meta) {
            $matrix[] = [
                'id'                => $flowId,
                'group'             => $meta['group'],
                'label'             => $meta['label'],
                'direction'         => $this->getDirection($flowId, $storeId),
                'conflict_strategy' => $this->getConflictStrategy($flowId, $storeId),
            ];
        }
        return $matrix;
    }

    // =========================================================================
    // Dérivation legacy (rétrocompat, table de dérivation Story 53-1)
    // =========================================================================

    /**
     * Dérive la direction effective d'un flux depuis les réglages legacy existants
     * (aucune constante SYNC_FLOW_* explicite). Implémente EXACTEMENT la table de dérivation
     * des Dev Notes Story 53-1.
     *
     * @param  string $flowId  Identifiant du flux
     * @param  int    $storeId Identifiant boutique (0 = portée globale)
     * @return string
     */
    private function deriveLegacyDirection(string $flowId, int $storeId): string
    {
        switch ($flowId) {
            case self::FLOW_PRODUCT_CREATE:
            case self::FLOW_PRODUCT_UPDATE:
                return $this->sanitizeLegacyDirection(
                    $this->legacyString('SYNC_PRODUCTS_DIRECTION', $storeId, 'both'),
                    'SYNC_PRODUCTS_DIRECTION',
                    'both'
                );

            case self::FLOW_PRODUCT_DELETE:
                // Le delete Dol→Shop n'existe pas (webhook products/delete = désactivation)
                $productsDirection = $this->legacyString('SYNC_PRODUCTS_DIRECTION', $storeId, 'both');
                return in_array($productsDirection, ['shopify_to_dolibarr', 'both'], true)
                    ? 'shopify_to_dolibarr'
                    : 'none';

            case self::FLOW_STOCK:
                // SYNC_PRODUCT_STOCKS (interrupteur moteur export, dette 50-13) reste ORTHOGONAL,
                // volontairement non consommé ici.
                return $this->sanitizeLegacyDirection(
                    $this->legacyString('SYNC_STOCKS_DIRECTION', $storeId, self::DEFAULT_STOCKS_DIRECTION),
                    'SYNC_STOCKS_DIRECTION',
                    self::DEFAULT_STOCKS_DIRECTION
                );

            case self::FLOW_PRICE:
                $exportEnabled = $this->legacyBool('SYNC_PRODUCT_PRICES', $storeId, false);
                $importEnabled = $this->legacyBool('SYNC_PRICES', $storeId, true);
                return self::combine($exportEnabled, $importEnabled);

            case self::FLOW_IMAGES:
                $exportEnabled = $this->legacyBool('SYNC_PRODUCT_IMAGES', $storeId, false);
                $importEnabled = $this->legacyBool('SYNC_IMAGES', $storeId, true);
                return self::combine($exportEnabled, $importEnabled);

            case self::FLOW_COLLECTIONS:
                $gateEnabled = $this->legacyBool('SYNC_PRODUCT_COLLECTIONS', $storeId, false);
                if (!$gateEnabled) {
                    return 'none';
                }
                $rawDirection = $this->legacyString('SYNC_COLLECTIONS_DIRECTION', $storeId, 'dol_to_shop');
                switch ($rawDirection) {
                    case 'dol_to_shop':
                        return 'dolibarr_to_shopify';
                    case 'shop_to_dol':
                        return 'shopify_to_dolibarr';
                    case 'bidirectional':
                        return 'both';
                    default:
                        return 'none';
                }

            case self::FLOW_ORDER_CREATE:
            case self::FLOW_ORDER_STATUS:
                return $this->sanitizeLegacyDirection(
                    $this->legacyString('SYNC_ORDERS_DIRECTION', $storeId, 'shopify_to_dolibarr'),
                    'SYNC_ORDERS_DIRECTION',
                    'shopify_to_dolibarr'
                );

            case self::FLOW_ORDER_PAYMENT:
                return $this->sanitizeLegacyDirection(
                    $this->legacyString('SYNC_PAYMENTS_DIRECTION', $storeId, 'shopify_to_dolibarr'),
                    'SYNC_PAYMENTS_DIRECTION',
                    'shopify_to_dolibarr'
                );

            case self::FLOW_SHIPPING:
                $shippingDirection = $this->legacyString('SYNC_SHIPPING_DIRECTION', $storeId, 'both');
                $d2sEnabled = in_array($shippingDirection, ['dolibarr_to_shopify', 'both'], true);
                $s2dEnabled = $this->legacyBool('AUTO_CREATE_EXPEDITION', $storeId, false);
                return self::combine($d2sEnabled, $s2dEnabled);

            default:
                // Ne devrait jamais arriver (flowId déjà validé par l'appelant public)
                return 'none';
        }
    }

    /**
     * Valide qu'une valeur de direction legacy lue appartient au vocabulaire cible
     * self::DIRECTIONS. Une valeur VIDE/absente (legacyString() n'a rien trouvé, ni per-store ni
     * global) donne le défaut de la branche appelante — comportement getDolGlobalString()
     * inchangé, identique à l'ancien SyncUtils::isSyncEnabled(). Une valeur NON VIDE hors
     * vocabulaire (résidu antérieur type 'disabled', typo, saisie invalide en base) est en
     * revanche un gate de synchronisation actif mal formé : FAIL-CLOSED — retourne 'none' (flux
     * désactivé) plutôt que le défaut du flux, pour ne jamais activer une sync par accident sur
     * une valeur non reconnue. Reproduit la sémantique de l'ancien SyncUtils::isSyncEnabled(), qui
     * traitait toute valeur non reconnue comme désactivée dans les deux sens. Factorise la
     * validation pour les 4 branches à retour direct de deriveLegacyDirection() (products,
     * stocks, orders, payments) ; NE S'APPLIQUE PAS à deriveLegacyConflictStrategy() (une
     * stratégie de conflit n'est pas un gate de sync — un repli sur le défaut y reste correct).
     *
     * @param  string $value   Valeur legacy lue (via legacyString())
     * @param  string $key     Clé legacy source, pour le message de log (ex: 'SYNC_STOCKS_DIRECTION')
     * @param  string $default Valeur de repli SI $value est vide (absente en base)
     * @return string
     */
    private function sanitizeLegacyDirection(string $value, string $key, string $default): string
    {
        if (in_array($value, self::DIRECTIONS, true)) {
            return $value;
        }

        if ($value === '') {
            return $default;
        }

        $this->log(
            'deriveLegacyDirection() - valeur legacy hors vocabulaire pour ' . $key . ': "'
                . $value . '" — flux désactivé (fail-closed)',
            LOG_WARNING
        );
        return 'none';
    }

    /**
     * Dérive la stratégie de conflit effective d'un flux depuis les réglages legacy existants.
     *
     * @param  string $flowId  Identifiant du flux
     * @param  int    $storeId Identifiant boutique (0 = portée globale)
     * @return string
     */
    private function deriveLegacyConflictStrategy(string $flowId, int $storeId): string
    {
        if ($flowId === self::FLOW_COLLECTIONS) {
            // Vocabulaire propre aux collections (collectionsconflictresolver.class.php:65-66) :
            // mapper UNIQUEMENT dolibarr_priority/shopify_priority ; les autres valeurs légales
            // (ex. newest_wins) appartiennent déjà au vocabulaire cible.
            $raw = $this->legacyString('SYNC_COLLECTIONS_CONFLICT_RESOLUTION', $storeId, 'dolibarr_priority');
            switch ($raw) {
                case 'dolibarr_priority':
                    return 'dolibarr_wins';
                case 'shopify_priority':
                    return 'shopify_wins';
                default:
                    return in_array($raw, self::CONFLICT_STRATEGIES, true) ? $raw : self::DEFAULT_CONFLICT_STRATEGY;
            }
        }

        if (in_array($flowId, self::PRODUCT_FAMILY_FLOWS, true)) {
            // Utilise déjà nativement le vocabulaire cible (ex. dolibarr_wins) — pas de mapping.
            $raw = $this->legacyString('SYNC_PRODUCTS_CONFLICT_RESOLUTION', $storeId, 'dolibarr_wins');
            return in_array($raw, self::CONFLICT_STRATEGIES, true) ? $raw : self::DEFAULT_CONFLICT_STRATEGY;
        }

        // Flux commandes/expéditions : stratégie globale legacy (pas de clé per-store dédiée)
        $raw = $this->legacyString('CONFLICT_RESOLUTION_STRATEGY', $storeId, self::DEFAULT_CONFLICT_STRATEGY);
        return in_array($raw, self::CONFLICT_STRATEGIES, true) ? $raw : self::DEFAULT_CONFLICT_STRATEGY;
    }

    /**
     * Combine deux flags de direction indépendants (export/d2s, import/s2d) en une direction
     * du vocabulaire cible. both si les deux, dolibarr_to_shopify si d2s seul, shopify_to_dolibarr
     * si s2d seul, none sinon.
     *
     * @param  bool $dolibarrToShopify Flag d2s actif
     * @param  bool $shopifyToDolibarr Flag s2d actif
     * @return string
     */
    private static function combine(bool $dolibarrToShopify, bool $shopifyToDolibarr): string
    {
        if ($dolibarrToShopify && $shopifyToDolibarr) {
            return 'both';
        }
        if ($dolibarrToShopify) {
            return 'dolibarr_to_shopify';
        }
        if ($shopifyToDolibarr) {
            return 'shopify_to_dolibarr';
        }
        return 'none';
    }

    /**
     * Lit un réglage legacy de type chaîne. storeId>0 → StoreSettings::get() (fallback global
     * natif, respecte les overlays per-store existants) ; storeId<=0 → getDolGlobalString()
     * direct.
     *
     * @param  string $key     Clé SANS préfixe DOLI2SHOP_ (ex: 'SYNC_PRODUCTS_DIRECTION')
     * @param  int    $storeId Identifiant boutique
     * @param  string $default Valeur par défaut
     * @return string
     */
    private function legacyString(string $key, int $storeId, string $default): string
    {
        if ($storeId > 0) {
            return (string) $this->getStoreSettings()->get($storeId, $key, $default);
        }
        return getDolGlobalString('DOLI2SHOP_' . $key, $default);
    }

    /**
     * Lit un réglage legacy booléen (interrupteur 0/1). Même cascade que legacyString().
     *
     * @param  string $key     Clé SANS préfixe DOLI2SHOP_
     * @param  int    $storeId Identifiant boutique
     * @param  bool   $default Valeur par défaut
     * @return bool
     */
    private function legacyBool(string $key, int $storeId, bool $default): bool
    {
        $defaultInt = $default ? 1 : 0;
        if ($storeId > 0) {
            return (bool) $this->getStoreSettings()->getInt($storeId, $key, $defaultInt);
        }
        return (bool) getDolGlobalInt('DOLI2SHOP_' . $key, $defaultInt);
    }

    /**
     * Lit une valeur d'override explicite (direction ou conflit) pour une clé SYNC_FLOW_* donnée.
     * storeId>0 : StoreSettings::get() — fallback natif vers l'override GLOBAL si aucun override
     * per-store n'existe (mécanique d'héritage AC4). storeId<=0 : constante globale directe.
     *
     * Divergence assumée avec StoreSettings::hasOverride() : une valeur `''` explicitement
     * stockée EST comptée comme override par hasOverride() (reflet brut du stockage), mais est
     * ici considérée comme un override INVALIDE — loggée en LOG_WARNING (clé + storeId) puis
     * ignorée (retour null → l'appelant retombe sur la dérivation legacy). Une chaîne vide ne
     * peut de toute façon jamais appartenir à self::DIRECTIONS / self::CONFLICT_STRATEGIES.
     *
     * @param  string $key     Clé complète (ex: SyncFlowPolicy::directionKey('stock'))
     * @param  int    $storeId Identifiant boutique
     * @return string|null     Valeur explicite si un override valide existe, null sinon
     */
    private function readOverrideValue(string $key, int $storeId): ?string
    {
        if ($storeId > 0) {
            $val = $this->getStoreSettings()->get($storeId, $key, null);
        } else {
            $globalVal = getDolGlobalString('DOLI2SHOP_' . $key, '');
            $val = ($globalVal !== '') ? $globalVal : null;
        }

        if ($val === null) {
            return null;
        }
        if ($val === '') {
            $this->log(
                'readOverrideValue() - override vide ignoré pour la clé ' . $key
                    . ' (storeId=' . $storeId . ') — repli sur dérivation',
                LOG_WARNING
            );
            return null;
        }
        return (string) $val;
    }

    // =========================================================================
    // Écriture (AC3)
    // =========================================================================

    /**
     * Persiste la direction d'un flux. storeId<=0 → constante globale DOLI2SHOP_SYNC_FLOW_<ID> ;
     * storeId>0 → StoreSettings sous la clé SYNC_FLOW_<ID> (sans préfixe, leçon 49-11).
     * Validation stricte : flowId ou direction invalide → rejet ($this->error rempli), rien n'est
     * écrit.
     *
     * @param  string $flowId    Identifiant du flux
     * @param  string $direction Direction à persister (self::DIRECTIONS)
     * @param  int    $storeId   Identifiant boutique (0 = portée globale)
     * @return bool
     */
    public function setDirection(string $flowId, string $direction, int $storeId = 0): bool
    {
        $this->error = '';

        if (!array_key_exists($flowId, self::FLOWS)) {
            $this->error = 'Flux invalide: ' . $flowId;
            $this->log('setDirection() - ' . $this->error, LOG_WARNING);
            return false;
        }
        if (!in_array($direction, self::DIRECTIONS, true)) {
            $this->error = 'Direction invalide: ' . $direction;
            $this->log('setDirection() - ' . $this->error, LOG_WARNING);
            return false;
        }

        return $this->writeSetting(self::directionKey($flowId), $direction, (int) $storeId);
    }

    /**
     * Persiste la stratégie de conflit d'un flux sous une clé DISTINCTE (SYNC_FLOW_<ID>_CONFLICT)
     * — ne jamais réutiliser la clé de direction. Même validation stricte que setDirection().
     *
     * @param  string $flowId   Identifiant du flux
     * @param  string $strategy Stratégie à persister (self::CONFLICT_STRATEGIES)
     * @param  int    $storeId  Identifiant boutique (0 = portée globale)
     * @return bool
     */
    public function setConflictStrategy(string $flowId, string $strategy, int $storeId = 0): bool
    {
        $this->error = '';

        if (!array_key_exists($flowId, self::FLOWS)) {
            $this->error = 'Flux invalide: ' . $flowId;
            $this->log('setConflictStrategy() - ' . $this->error, LOG_WARNING);
            return false;
        }
        if (!in_array($strategy, self::CONFLICT_STRATEGIES, true)) {
            $this->error = 'Stratégie de conflit invalide: ' . $strategy;
            $this->log('setConflictStrategy() - ' . $this->error, LOG_WARNING);
            return false;
        }

        return $this->writeSetting(self::conflictKey($flowId), $strategy, (int) $storeId);
    }

    /**
     * Écrit une clé SYNC_FLOW_* validée (direction ou conflit) — global ou per-store selon storeId.
     *
     * @param  string $key     Clé complète (ex: SYNC_FLOW_STOCK ou SYNC_FLOW_STOCK_CONFLICT)
     * @param  string $value   Valeur validée à écrire
     * @param  int    $storeId Identifiant boutique (0 = portée globale)
     * @return bool
     */
    private function writeSetting(string $key, string $value, int $storeId): bool
    {
        if ($storeId > 0) {
            $result = $this->getStoreSettings()->set($storeId, $key, $value);
            if ($result < 0) {
                $this->error = 'Échec écriture per-store pour la clé ' . $key;
                $this->log('writeSetting() - ' . $this->error . ' storeId=' . $storeId, LOG_ERR);
                return false;
            }
            return true;
        }

        // Piège connu (dette documentée Story 53-1) : dolibarr_set_const() dépend d'une fonction
        // core non auto-chargée hors contexte admin. Le stub de test masque ce bug — l'inclusion
        // reste nécessaire en environnement réel (pattern configurationMigrator.class.php:364).
        dol_include_once('/core/lib/admin.lib.php');

        $result = $this->setConst('DOLI2SHOP_' . $key, $value);
        if ($result <= 0) {
            $this->error = 'Échec écriture constante globale pour la clé ' . $key;
            $this->log('writeSetting() - ' . $this->error, LOG_ERR);
            return false;
        }
        return true;
    }

    /**
     * Wrapper mockable autour de dolibarr_set_const() (fonction globale non mockable directement
     * en test unitaire sans espace de noms — pattern stockrecalageservice.class.php:346).
     *
     * @param  string $name  Nom complet de la constante (avec préfixe DOLI2SHOP_)
     * @param  string $value Valeur à persister
     * @return int           Résultat de dolibarr_set_const() (>0 = OK)
     */
    protected function setConst(string $name, string $value): int
    {
        return dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $this->entity);
    }

    // =========================================================================
    // Héritage par boutique (AC4)
    // =========================================================================

    /**
     * Indique si au moins un override SYNC_FLOW_* (direction OU conflit) est posé pour cette
     * boutique. Couvre les DEUX familles de clés (un override de conflit posé seul doit être
     * détecté).
     *
     * @param  int $storeId Identifiant boutique
     * @return bool
     */
    public function hasStoreOverrides(int $storeId): bool
    {
        if ($storeId <= 0) {
            return false;
        }

        $storeSettings = $this->getStoreSettings();
        foreach (array_keys(self::FLOWS) as $flowId) {
            if ($storeSettings->hasOverride($storeId, self::directionKey($flowId))) {
                return true;
            }
            if ($storeSettings->hasOverride($storeId, self::conflictKey($flowId))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Supprime tous les overrides SYNC_FLOW_* (direction ET conflit) de la boutique — retour à
     * l'héritage du réglage global (mécanique native StoreSettings::get()).
     *
     * @param  int $storeId Identifiant boutique
     * @return int           Nombre de lignes effectivement supprimées
     */
    public function clearStoreOverrides(int $storeId): int
    {
        if ($storeId <= 0) {
            return 0;
        }

        $storeSettings = $this->getStoreSettings();
        $cleared = 0;
        foreach (array_keys(self::FLOWS) as $flowId) {
            $cleared += max(0, $storeSettings->delete($storeId, self::directionKey($flowId)));
            $cleared += max(0, $storeSettings->delete($storeId, self::conflictKey($flowId)));
        }

        // Filet supplémentaire : les delete() unitaires ci-dessus ont déjà invalidé les bonnes
        // clés dans le cache StoreSettings. Ce clearCache() statique résout néanmoins l'entité
        // via $conf->entity ambiant (limitation pré-existante de StoreSettings, indépendante de
        // 53-1) — pas via $this->entity de cette instance.
        \StoreSettings::clearCache($storeId);

        return $cleared;
    }

    // =========================================================================
    // Factories protégées (testabilité — pattern Story 50-1)
    // =========================================================================

    /**
     * Factory StoreSettings (protégée pour permettre le mock en test unitaire, pattern
     * Story501CronPerStoreSettingsTest / ShopifyFulfillmentCatchupCron::createStoreSettings()).
     *
     * @return StoreSettings
     */
    protected function createStoreSettings(): StoreSettings
    {
        return new StoreSettings($this->db, $this->entity);
    }

    /**
     * Instance StoreSettings paresseuse (une seule par instance de policy).
     *
     * @return StoreSettings
     */
    private function getStoreSettings(): StoreSettings
    {
        if ($this->storeSettings === null) {
            $this->storeSettings = $this->createStoreSettings();
        }
        return $this->storeSettings;
    }
}
