<?php
/**
 * @file        class/shopifywebhooks.class.php
 * @brief       Gestion des webhooks Shopify - enregistrement, vérification HMAC, communication API
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    webhooks
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.0.0
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
dol_include_once('/core/db/Database.interface.php');
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Story 2.4.7-2 : doli2shopBuildStoreScopedSqlFilter() / doli2shopComputeWebhookTopicState()
// — même fonctions pures que l'affichage/la sauvegarde admin, réutilisées par la re-vérification
// post-verrou de createWebhookWithDatabase() (une seule source de vérité pour « un abonnement
// est-il déjà vivant pour ce topic/cette boutique »).
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Requis pour dolibarr_set_const() : la fonction vit dans core/lib/admin.lib.php, qui n'est PAS
// auto-chargé hors contexte admin — donc absent en CRON. Constaté en production le 2026-08-13 :
// cronCheckWebhookHealth mourait sur « Call to undefined function dolibarr_set_const() » au
// moment d'écrire DOLI2SHOP_WEBHOOK_HEALTH_FAILSTREAK, c'est-à-dire précisément quand la santé
// des webhooks se dégradait — le diagnostic tombait en panne au moment où il servait.
// Même pattern que alertmanager.class.php:28.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}

/**
 * Class ShopifyWebhooks
 * Gère l'enregistrement et la communication des webhooks avec Shopify
 */
class ShopifyWebhooks
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDb Database handler */
    public $db;
    
    /** @var array Errors */
    public $errors = array();

    /** @var string CRON output message */
    public $output = '';

    /**
     * Story 2.4.7-2 : true si le DERNIER appel à createWebhookWithDatabase() a échoué
     * spécifiquement faute d'avoir pu acquérir le verrou de création (course avec un autre
     * appelant — autre onglet admin, ou CRON cronCheckWebhookHealth() concurrent). Distinct
     * de $errors (qui reste une liste technique) : ce drapeau permet à l'appelant (page admin
     * via WebhookSettingsSaver) de restituer un message TRADUIT et explicite, plutôt que le
     * message générique d'erreur — jamais un échec silencieux (point 5 de la story).
     *
     * @var bool
     */
    public $lastCreateLockTimedOut = false;

    /**
     * Story 59-4 (AC4) : true si le DERNIER échec de `createWebhook()` provient d'un refus DURABLE
     * de Shopify (portée OAuth manquante, topic non éligible à la version d'app configurée dans le
     * Shopify Partner Dashboard) plutôt que d'un aléa transitoire (réseau, rate limit, panne
     * momentanée). Classification via `doli2shopClassifyShopifyUserErrorAsDurable()`.
     *
     * Distinct de `$errors` (liste technique) : permet à l'appelant (`cronCheckWebhookHealth()`) de
     * ne pas traiter un refus stable comme un échec ordinaire à re-signaler en boucle à chaque
     * vérification périodique — un état à observer et à corriger manuellement, jamais à marteler
     * d'appels Shopify supplémentaires. Repositionné à `false` au début de `createWebhook()` ET de
     * `createWebhookWithDatabase()`, pour ne jamais laisser fuiter la classification d'un appel
     * précédent sur un appel qui n'a pas atteint la mutation Shopify.
     *
     * @var bool
     */
    public $lastCreateUserErrorIsDurable = false;

    /** Une mutation Shopify a réellement eu lieu (création initiale OU recréation d'un fantôme). */
    public const CREATE_OUTCOME_CREATED = 'created';

    /** Confirmé vivant CHEZ SHOPIFY par la vérification fraîche : aucune mutation nécessaire. */
    public const CREATE_OUTCOME_ALREADY_LIVE = 'already_live';

    /**
     * La vérification fraîche contre Shopify a elle-même échoué (API down, jeton expiré...) : fail-safe,
     * aucune preuve établie dans un sens ou dans l'autre, aucune mutation tentée (AC5, story 59-4).
     */
    public const CREATE_OUTCOME_UNVERIFIABLE = 'unverifiable';

    /** Échec (budget épuisé, verrou non acquis, HTTP_HOST invalide, ou mutation Shopify refusée). */
    public const CREATE_OUTCOME_ERROR = 'error';

    /**
     * Code Review 3 couches (Epic 59, MEDIUM 2) : classification du DERNIER résultat de
     * `createWebhookWithDatabase()`, une des constantes `self::CREATE_OUTCOME_*` ci-dessus.
     *
     * Avant ce correctif, un retour `1` de `createWebhookWithDatabase()` couvrait TROIS situations
     * bien distinctes (recréation réelle / confirmation "déjà vivant" / fail-safe faute de preuve) que
     * les appelants (`cronCheckWebhookHealth()`, `WebhookSettingsSaver::saveTopics()`) ne pouvaient pas
     * distinguer avec `$result > 0` seul — un état NON RÉSOLU (fail-safe) était donc compté comme un
     * succès de recréation ("recreated successfully" / "PHANTOM RECREATED" dans les logs), exactement
     * la classe de défaut que cet epic corrige (un refus/une absence de preuve maquillée en succès).
     *
     * Repositionné à `null` au début de CHAQUE appel à `createWebhookWithDatabase()`, pour ne jamais
     * laisser fuiter la classification d'un appel précédent (même principe que `$lastCreateLockTimedOut`
     * et `$lastCreateUserErrorIsDurable`).
     *
     * @var string|null
     */
    public $lastCreateOutcome = null;

    /**
     * Code Review 3 couches (Epic 59, HIGH 1) : compteurs de télémétrie du DERNIER appel à
     * `syncWebhooks()` sur CETTE instance — remis à zéro au début de `syncWebhooks()`.
     *
     * Avant ce correctif, `recreatedCount`/`durableRefusalCount`/`failedCount` n'existaient QUE dans
     * le repli mono-boutique historique de `cronCheckWebhookHealth()` (aucune ligne dans
     * `llx_doli2shop_stores`) — un chemin quasi jamais emprunté en production puisque le module seede
     * systématiquement une "boutique par défaut" (`ensureDefaultStore()`). Le chemin RÉELLEMENT
     * emprunté par tous les clients — `syncWebhooksForStore()` -> `syncWebhooks()` — ne remontait
     * qu'un booléen et ignorait les échecs par topic : l'AC2/AC4 de la story 59-4 (télémétrie fine,
     * refus durable non compté en échec) étaient donc invisibles en production. Ces compteurs
     * corrigent cela en portant la même granularité sur le chemin réel.
     *
     * @var int
     */
    public $lastSyncMissingCount = 0;

    /** @var int Nombre de topics manquants réellement (re)créés lors du dernier syncWebhooks(). */
    public $lastSyncRecreatedCount = 0;

    /** @var int Nombre de topics confirmés déjà vivants (gagnant de course concurrent) sans mutation. */
    public $lastSyncAlreadyLiveCount = 0;

    /** @var int Nombre de topics dont la vérification Shopify a échoué (fail-safe, état non résolu). */
    public $lastSyncUnverifiableCount = 0;

    /** @var int Nombre de topics refusés DURABLEMENT (classifiés OU disjoncteur d'échecs répétés). */
    public $lastSyncDurableRefusalCount = 0;

    /** @var int Nombre de topics en échec ORDINAIRE (transitoire) lors du dernier syncWebhooks(). */
    public $lastSyncFailedCount = 0;

    /**
     * Code Review 3 couches (Epic 59, MEDIUM 5) : seuil d'échecs CONSÉCUTIFS (au sens
     * `doli2shopComputeWebhookFailStreakState()`) au-delà duquel un topic est traité comme durable DE
     * FACTO même si la classification par mots-clés ne l'a jamais reconnu comme tel — filet de sécurité
     * contre une classification heuristique qui se serait trompée, cf. doc de cette fonction.
     */
    private const CONSECUTIVE_FAILURE_DURABLE_THRESHOLD = 3;

    /** Résultat de `attemptCreateMissingWebhook()` : le topic a été réellement (re)créé. */
    private const SYNC_ATTEMPT_RECREATED = 'recreated';

    /** Résultat de `attemptCreateMissingWebhook()` : confirmé déjà vivant, aucune mutation. */
    private const SYNC_ATTEMPT_ALREADY_LIVE = 'already_live';

    /** Résultat de `attemptCreateMissingWebhook()` : vérification Shopify indisponible (fail-safe). */
    private const SYNC_ATTEMPT_UNVERIFIABLE = 'unverifiable';

    /** Résultat de `attemptCreateMissingWebhook()` : refus durable (classifié OU disjoncteur). */
    private const SYNC_ATTEMPT_DURABLE = 'durable';

    /** Résultat de `attemptCreateMissingWebhook()` : échec ordinaire (transitoire). */
    private const SYNC_ATTEMPT_FAILED = 'failed';

    /**
     * Story 2.4.7-2 (Code Review round 2, HIGH 3) : budget de temps total (secondes) alloué à une
     * SÉRIE d'appels à createWebhookWithDatabase() sur la MÊME instance. 11 topics x 5s de verrou =
     * jusqu'à ~55s de blocage dans une seule requête HTTP (page admin, callback OAuth), au-delà du
     * max_execution_time (souvent 30s) d'un hébergement infogéré — le timeout PHP produirait un
     * écran blanc, exactement l'échec silencieux que cette story doit éliminer. 20s laisse une
     * marge confortable pour le reste du traitement de la requête (rendu HTML, autres écritures).
     *
     * @var float
     */
    private const CREATE_WEBHOOK_LOOP_TIME_BUDGET_SECONDS = 20.0;

    /**
     * Horodatage (microtime(true)) au-delà duquel une série d'appels à createWebhookWithDatabase()
     * sur cette instance abandonne plutôt que d'attendre — initialisé PARESSEUSEMENT au tout
     * premier appel (jamais en constructeur : une instance qui ne crée aucun webhook ne calcule
     * rien). Partagé par toutes les boucles qui réutilisent une seule instance ShopifyWebhooks à
     * travers leurs itérations (WebhookSettingsSaver::saveTopics(), admin/oauth_receive.php,
     * syncWebhooks(), cronCheckWebhookHealth() fallback mono-boutique).
     *
     * @var float|null
     */
    private $createLoopDeadline = null;

    /**
     * @var ShopifyApi Instance API Shopify
     *
     * Code Review 3 couches (Epic 59, fix pagination `listWebhooks()`) : `protected` (et non
     * `private`), même raison que `$store` ci-dessous — un double de test sans reflection doit
     * pouvoir injecter une fausse API directement pour exercer la VRAIE implémentation de
     * `listWebhooks()` (pagination), plutôt que de devoir la surcharger entièrement et ne plus la
     * tester du tout.
     */
    protected $shopifyApi;

    /**
     * @var object|null Boutique courante (null = chemin global historique)
     *
     * `protected` (et non `private`) depuis la Story 2.4.7-2 : les doubles de test injectables
     * sans reflection (sous-classes qui ne construisent jamais de ShopifyApi réelle, cf.
     * `test/unit/ShopifyWebhooksCreateWebhookLockTest.php`) doivent pouvoir positionner cette
     * boutique directement dans leur propre constructeur pour exercer
     * `createWebhookWithDatabase()` (qui, lui, reste réellement testé — pas court-circuité).
     */
    protected $store;

    /**
     * Constructor
     *
     * @param DoliDb      $db    Database handler
     * @param object|null $store Objet boutique (null = chemin global/historique, API via constantes)
     */
    public function __construct($db, $store = null)
    {
        $this->db    = $db;
        $this->store = $store;
        if ($store !== null) {
            // Chemin multi-boutiques : charger l'API avec les credentials de la boutique
            $this->shopifyApi = ShopifyApi::forStore($db, $store);
        } else {
            // Chemin historique : API via constantes DOLI2SHOP_* de l'entité courante
            $this->shopifyApi = new ShopifyApi($db);
        }
    }
    
    /**
     * Version d'API Shopify CIBLE POUR LES WEBHOOKS (distincte de l'endpoint GraphQL).
     * Permet à l'UI de repérer les webhooks enregistrés sur une version périmée.
     *
     * Story 51-2 (CORRECTION VALIDATE P-HIGH) : retourne désormais
     * ShopifyApi::WEBHOOK_TARGET_API_VERSION (piloté par shopify-app/shopify.app.toml),
     * PAS ShopifyApi::getApiVersion() (endpoint GraphQL, bumpé indépendamment). Sans cette
     * séparation, le bump de l'endpoint GraphQL en 2026-07 aurait fait apparaître tous les
     * webhooks existants (livrés en 2026-04, version d'app réelle) comme périmés → warning
     * permanent dans l'UI + delete/recreate de TOUS les webhooks à chaque sync (cf. T0).
     *
     * @return string ex. '2026-04' (chaîne vide si indisponible)
     * @since 2.3.2
     */
    public function getApiVersion(): string
    {
        return ShopifyApi::WEBHOOK_TARGET_API_VERSION;
    }

    /**
     * Horodatage courant utilisé par le budget de temps global (Story 2.4.7-2, HIGH 3). Extrait en
     * méthode protégée UNIQUEMENT pour permettre à un double de test de contrôler l'écoulement du
     * temps sans dépendre d'un vrai sleep() : un test peut surcharger cette méthode pour simuler un
     * budget déjà épuisé, sans introduire de délai réel dans la suite PHPUnit.
     *
     * @return float
     * @since 2.4.7
     */
    protected function nowForBudget(): float
    {
        return microtime(true);
    }

    /**
     * Instancie StoreService pour l'entité donnée — méthode protégée pour permettre le mock en test
     * (même pattern que `ImportProductsCron::createStoreService()`,
     * `ShopifyOrderCatchupCron::createStoreService()`, etc.) : un double de test peut la surcharger
     * pour retourner un StoreService mocké, sans jamais toucher au vrai `new StoreService(...)` codé
     * en dur qui empêchait jusqu'ici de tester `cronCheckWebhookHealth()` (multi-boutiques) et
     * `resolveCanonicalStoreScope()` autrement qu'en exerçant le repli mono-boutique.
     *
     * @param  int $entity Entité Dolibarr
     * @return StoreService
     * @since  2.4.8
     */
    protected function createStoreService(int $entity): StoreService
    {
        require_once dirname(__FILE__) . '/storeservice.class.php';
        return new StoreService($this->db, $entity);
    }

    /**
     * Instancie un `ShopifyWebhooks` avec les credentials d'une boutique — méthode protégée pour
     * permettre le mock en test (même pattern que `createStoreService()` ci-dessus). Utilisée par
     * `syncWebhooksForStore()` au lieu d'un `new ShopifyWebhooks($this->db, $store)` codé en dur : un
     * double de test peut la surcharger pour retourner une instance entièrement contrôlable par
     * boutique, ce qui permet de tester le chemin MULTI-BOUTIQUES RÉEL
     * (`cronCheckWebhookHealth()` -> `syncWebhooksForStore()` -> `syncWebhooks()`) sans jamais
     * instancier de vraie `ShopifyApi` ni faire d'appel réseau.
     *
     * @param  object $store Objet boutique (credentials, rowid)
     * @return ShopifyWebhooks
     * @since  2.4.8
     */
    protected function createStoreWebhooksInstance(object $store): ShopifyWebhooks
    {
        return new ShopifyWebhooks($this->db, $store);
    }

    /**
     * Résolution CANONIQUE de la boutique courante (fk_store + caractère secondaire), pour construire
     * aussi bien la clé de verrou de création que le filtre SQL scoping boutique.
     *
     * Code Review 3 couches (Epic 59, MEDIUM 3) : extrait de `createWebhookWithDatabase()` (Story
     * 2.4.7-2, Code Review round 2, CRITICAL 2) pour être RÉUTILISÉ par `logPhantomWebhookDivergence()`,
     * qui avait sa propre résolution simplifiée — sans le repli sur la boutique par défaut RÉELLE
     * quand `$this->store === null`. Sur une installation multi-boutiques, cette résolution
     * simplifiée produisait `fkStore=0, isSecondary=false`, et
     * `doli2shopBuildStoreScopedSqlFilter(0, false)` renvoie alors une chaîne VIDE (aucun filtre) :
     * l'instantané Shopify de la boutique par défaut servait à juger les lignes de TOUTES les autres
     * boutiques, qui étaient alors signalées comme fantômes à tort dès qu'elles avaient des webhooks
     * actifs. Une seule source de vérité désormais, comme le reste de cette classe l'exige déjà pour
     * le filtre SQL et l'état agrégé d'un topic.
     *
     * `$this->store` est `null` quand l'appelant instancie `new ShopifyWebhooks($db)` SANS boutique
     * pour la boutique PAR DÉFAUT (`admin/webhooks.php`, `admin/oauth_receive.php`) — alors que
     * `cronCheckWebhookHealth()` boucle sur `StoreService::getAll(true)` (qui INCLUT la boutique par
     * défaut) et instancie avec l'objet réel (`fk_store` = son rowid réel). Sans cette résolution, la
     * MÊME boutique physique produirait deux scopes distincts selon l'appelant.
     *
     * @return array{fkStore:int, isSecondary:bool}
     * @since  2.4.8
     */
    private function resolveCanonicalStoreScope(): array
    {
        global $conf;

        if ($this->store !== null && isset($this->store->rowid) && (int) $this->store->rowid > 0) {
            return array(
                'fkStore' => (int) $this->store->rowid,
                'isSecondary' => !(isset($this->store->is_default) && (int) $this->store->is_default === 1),
            );
        }

        // `$this->store` est null : résoudre la boutique par défaut RÉELLE plutôt que de traiter ce
        // chemin comme "aucune boutique" (ce qui donnait fk_store=0 et un filtre vide, alors qu'une
        // boutique par défaut existe bel et bien).
        $defaultStoreForScope = $this->createStoreService((int) ($conf->entity ?? 1))->getDefault();
        if ($defaultStoreForScope !== null && isset($defaultStoreForScope->rowid) && (int) $defaultStoreForScope->rowid > 0) {
            // C'est PAR DÉFINITION la boutique par défaut : jamais secondaire.
            return array('fkStore' => (int) $defaultStoreForScope->rowid, 'isSecondary' => false);
        }

        // Aucune boutique en base (install vierge / pré-migration Epic 47) : chemin historique
        // strictement inchangé.
        return array('fkStore' => 0, 'isSecondary' => false);
    }

    /**
     * Charge le compteur d'échecs consécutifs par (boutique, topic) — disjoncteur de secours du
     * refus durable (Code Review 3 couches, Epic 59, MEDIUM 5, cf. `doli2shopComputeWebhookFailStreakState()`).
     *
     * Persisté via une constante Dolibarr (`llx_const`, API `dolibarr_set_const()`/`getDolGlobalString()`)
     * plutôt qu'une colonne dédiée : aucune migration SQL nécessaire pour ce hotfix. Méthode protégée
     * (plutôt qu'un simple appel direct dans le corps des méthodes) pour permettre à un double de test
     * de la surcharger par un tableau en mémoire, sans dépendre de la fidélité du stub global
     * `dolibarr_set_const()`/`getDolGlobalString()` de l'environnement de test.
     *
     * @return array<string,int> Clé "fkStore:topic" => nombre d'échecs consécutifs
     * @since  2.4.8
     */
    protected function loadWebhookFailStreaks(): array
    {
        global $conf;
        $raw = getDolGlobalString('DOLI2SHOP_WEBHOOK_HEALTH_FAILSTREAK', '');
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Persiste le compteur d'échecs consécutifs par (boutique, topic) — pendant de
     * `loadWebhookFailStreaks()`, cf. sa documentation.
     *
     * Élague les entrées à 0 (topic redevenu sain) avant sérialisation, pour ne pas faire grossir
     * indéfiniment le blob JSON au fil des mois avec des topics qui ne posent plus problème.
     *
     * @param  array<string,int> $streaks Clé "fkStore:topic" => nombre d'échecs consécutifs
     * @return void
     * @since  2.4.8
     */
    protected function saveWebhookFailStreaks(array $streaks): void
    {
        global $conf;
        $pruned = array_filter($streaks, function ($count) {
            return (int) $count > 0;
        });
        $json = json_encode($pruned);
        if ($json === false) {
            return;
        }
        dolibarr_set_const($this->db, 'DOLI2SHOP_WEBHOOK_HEALTH_FAILSTREAK', $json, 'chaine', 0, '', (int) ($conf->entity ?? 1));
    }

    /**
     * Tentative UNIQUE de création d'un webhook manquant, avec télémétrie fine ET disjoncteur
     * d'échecs consécutifs — Code Review 3 couches (Epic 59, HIGH 1 + MEDIUM 5).
     *
     * Appelée à la fois par `syncWebhooks()` (chemin MULTI-BOUTIQUES RÉEL, `syncWebhooksForStore()`)
     * ET par le repli mono-boutique de `cronCheckWebhookHealth()`, pour que cette logique ne soit
     * JAMAIS dupliquée entre les deux : c'est cette exacte duplication qui avait laissé la télémétrie
     * fine (`recreatedCount`/`durableRefusalCount`) n'exister QUE dans le repli — un chemin quasi
     * jamais emprunté puisqu'une boutique par défaut est systématiquement seedée.
     *
     * Story 59-9 (correctif review) : le paramètre `$isManualTrigger` distingue une action MANUELLE
     * (bouton admin, reconnexion OAuth — ne saute JAMAIS le disjoncteur, cf.
     * `doli2shopShouldSkipDurableRetry()`) du CRON périodique (seul à conserver le disjoncteur — c'est
     * sa raison d'être). Sans cette distinction, un client qui vient de corriger la cause d'un refus
     * durable (portée OAuth accordée, app réinstallée) et reclique sur « Activer tous les webhooks »
     * verrait sa tentative ignorée SANS AUCUN EFFET NI MESSAGE — le symptôme même qui a ouvert cet epic.
     *
     * @param  string             $topic           Topic manquant à créer
     * @param  int                $fkStore         Boutique concernée (résolution canonique), 0 = chemin historique
     * @param  array<string,int>  &$failStreaks    Compteurs d'échecs consécutifs (chargés par l'appelant), MUTÉS ici
     * @param  bool               $isManualTrigger true = action humaine explicite (ne saute jamais) ; false = CRON
     * @return string Une des constantes `self::SYNC_ATTEMPT_*`
     * @since  2.4.8
     */
    private function attemptCreateMissingWebhook(string $topic, int $fkStore, array &$failStreaks, bool $isManualTrigger = true): string
    {
        $streakKey = $fkStore . ':' . $topic;
        $previousStreak = isset($failStreaks[$streakKey]) ? (int) $failStreaks[$streakKey] : 0;

        if (doli2shopShouldSkipDurableRetry($previousStreak, $isManualTrigger, self::CONSECUTIVE_FAILURE_DURABLE_THRESHOLD)) {
            // Disjoncteur déjà ouvert (persisté depuis un passage précédent) ET appel automatique
            // (CRON) : ne pas retenter, ne pas marteler Shopify pour un topic qui ne peut
            // structurellement pas aboutir sans action humaine. Le compteur reste inchangé (aucune
            // tentative n'a eu lieu) — une action MANUELLE ultérieure retentera systématiquement.
            $this->log(
                "ShopifyWebhooks::attemptCreateMissingWebhook - topic=" . $topic . " fk_store=" . $fkStore
                . " disjoncteur déjà ouvert (" . $previousStreak . " échec(s) consécutif(s), seuil "
                . self::CONSECUTIVE_FAILURE_DURABLE_THRESHOLD . ") — tentative CRON ignorée (refus"
                . " durable déjà établi) ; une action manuelle (bouton \"Activer tous les webhooks\","
                . " reconnexion OAuth) retentera systématiquement.",
                LOG_WARNING
            );
            return self::SYNC_ATTEMPT_DURABLE;
        }

        // Reset des erreurs AVANT chaque tentative pour isoler le diagnostic par topic (même
        // précaution que le repli historique et que WebhookSettingsSaver).
        $this->errors = array();
        $result = $this->createWebhookWithDatabase($topic);

        if ($result > 0) {
            // Succès (création réelle OU confirmation vivante) : le disjoncteur se referme.
            $failStreaks[$streakKey] = 0;
            if ($this->lastCreateOutcome === self::CREATE_OUTCOME_CREATED) {
                $this->log("ShopifyWebhooks::attemptCreateMissingWebhook - Webhook " . $topic . " recreated successfully (fk_store=" . $fkStore . ")", LOG_INFO);
                return self::SYNC_ATTEMPT_RECREATED;
            }
            if ($this->lastCreateOutcome === self::CREATE_OUTCOME_UNVERIFIABLE) {
                return self::SYNC_ATTEMPT_UNVERIFIABLE;
            }
            return self::SYNC_ATTEMPT_ALREADY_LIVE;
        }

        $classifiedDurable = !empty($this->lastCreateUserErrorIsDurable);
        $streakState = doli2shopComputeWebhookFailStreakState(
            $previousStreak,
            false,
            $classifiedDurable,
            self::CONSECUTIVE_FAILURE_DURABLE_THRESHOLD
        );
        $failStreaks[$streakKey] = $streakState['consecutiveFailures'];

        if ($streakState['treatAsDurable']) {
            if (!$classifiedDurable) {
                // Disjoncteur de secours déclenché (MEDIUM 5) : la classification par mots-clés n'a
                // JAMAIS reconnu ce refus comme durable, mais il se répète identiquement depuis
                // plusieurs exécutions consécutives — traité comme durable DE FACTO pour ne plus
                // jamais faire basculer la santé en KO indéfiniment sur un topic qui ne peut
                // structurellement pas aboutir.
                $this->log(
                    "ShopifyWebhooks::attemptCreateMissingWebhook - topic=" . $topic . " fk_store=" . $fkStore
                    . " traité comme un refus DURABLE après " . $streakState['consecutiveFailures']
                    . " échec(s) CONSÉCUTIF(S) (classification par mots-clés non concluante, mais le"
                    . " motif se répète à l'identique) — non retenté en boucle, à corriger manuellement : "
                    . implode(', ', $this->errors),
                    LOG_WARNING
                );
            }
            return self::SYNC_ATTEMPT_DURABLE;
        }

        $this->log("ShopifyWebhooks::attemptCreateMissingWebhook - Failed to create webhook for topic " . $topic . ": " . implode(', ', $this->errors), LOG_ERR);
        return self::SYNC_ATTEMPT_FAILED;
    }

    /**
     * Convert REST topic format to GraphQL enum format
     *
     * @param string $topic REST format topic (e.g., 'products/create')
     * @return string GraphQL enum format (e.g., 'PRODUCTS_CREATE')
     */
    public static function topicToGraphQL($topic)
    {
        return strtoupper(str_replace('/', '_', $topic));
    }

    /**
     * Convert GraphQL enum format to REST topic format
     *
     * @param string $topic GraphQL enum format (e.g., 'PRODUCTS_CREATE')
     * @return string REST format topic (e.g., 'products/create')
     */
    public static function topicFromGraphQL($topic)
    {
        // Mapping connu pour les topics avec sous-catégories
        $map = array(
            'PRODUCTS_CREATE' => 'products/create',
            'PRODUCTS_UPDATE' => 'products/update',
            'PRODUCTS_DELETE' => 'products/delete',
            'ORDERS_CREATE' => 'orders/create',
            'ORDERS_UPDATED' => 'orders/updated',
            'ORDERS_CANCELLED' => 'orders/cancelled',
            'ORDERS_FULFILLED' => 'orders/fulfilled',
            'ORDERS_PAID' => 'orders/paid',
            'ORDERS_PARTIALLY_FULFILLED' => 'orders/partially_fulfilled',
            'INVENTORY_LEVELS_UPDATE' => 'inventory_levels/update',
            'APP_UNINSTALLED' => 'app/uninstalled',
        );

        if (isset($map[$topic])) {
            return $map[$topic];
        }

        // Fallback générique : lowercase + premier _ → /
        $lower = strtolower($topic);
        return preg_replace('/_/', '/', $lower, 1);
    }

    /**
     * Convertit un horodatage Shopify (ISO-8601, ex. '2026-07-28T07:18:40Z' ou avec
     * décalage '2026-07-28T07:18:40+02:00') en littéral SQL directement injectable :
     * une chaîne datetime MySQL entre quotes, ou le mot-clé NULL (non quoté) si la
     * valeur est absente ou inanalysable.
     *
     * Hotfix 2.4.5 (Nicolas Graillon 2026-07-28) : les colonnes created_at_shopify /
     * updated_at_shopify sont des `datetime` ; une valeur ISO-8601 brute (avec 'T'/'Z')
     * provoque un DB_ERROR_1292 en MySQL/MariaDB strict et fait échouer silencieusement
     * l'enregistrement des webhooks. `strtotime()` gère nativement 'Z' et les décalages
     * horaires ; `idate()` est le convertisseur canonique du core Dolibarr, déjà utilisé
     * par StoreService::create().
     *
     * Review 3 couches (CRITICAL) : `strtotime()` ne renvoie PAS `false` sur les sentinelles
     * de date « inconnue » du type `0000-00-00` — il renvoie un timestamp négatif valide
     * (-62169984000), qu'`idate()` imprime en `-0001-11-30 00:00:00`, hors plage MySQL
     * `DATETIME` (1000-01-01 → 9999-12-31) : le DB_ERROR_1292 que ce correctif élimine serait
     * donc revenu par une autre porte. D'où le bornage explicite de l'intervalle.
     *
     * Review 3 couches (HIGH) : `idate()` est appelée avec `'gmt'` EXPLICITEMENT. Son défaut
     * est `'tzserver'` (le core lui-même documente ce défaut comme un TODO), ce qui décalerait
     * de plusieurs heures un horodatage Shopify qui est un instant UTC — faussant silencieusement
     * toute comparaison de dérive ou d'audit sur un serveur non configuré en UTC.
     *
     * @param  mixed  $rawValue Valeur brute retournée par l'API Shopify (string|null)
     * @return string           Littéral SQL prêt à insérer : "'YYYY-MM-DD HH:MM:SS'" ou "NULL"
     * @since  2.4.5
     */
    private function formatShopifyTimestampForSql($rawValue): string
    {
        // Une valeur non scalaire (objet sans __toString) ferait échouer le cast en Error fatale.
        if (!is_scalar($rawValue)) {
            return 'NULL';
        }

        $ts = strtotime((string) $rawValue);
        if ($ts === false) {
            return 'NULL';
        }

        // Bornes de la plage MySQL DATETIME, en UTC : 1000-01-01 00:00:00 → 9999-12-31 23:59:59.
        if ($ts < -30610224000 || $ts > 253402300799) {
            return 'NULL';
        }

        return "'".$this->db->idate($ts, 'gmt')."'";
    }

    /**
     * Create a webhook subscription
     *
     * @param string $topic Webhook topic (e.g., 'products/update')
     * @param string $url   Callback URL
     * @return array|false Webhook data if success, false if error
     */
    public function createWebhook($topic, $url)
    {
        global $conf, $user;

        // Story 59-4 (AC4) : repositionné à chaque appel — ne jamais laisser fuiter la
        // classification d'un appel PRÉCÉDENT sur celui-ci (ex. un succès après un refus durable
        // sur une tentative antérieure resterait sinon classé durable à tort).
        $this->lastCreateUserErrorIsDurable = false;

        // Convertir le topic au format GraphQL ENUM (products/create → PRODUCTS_CREATE)
        $graphqlTopic = self::topicToGraphQL($topic);

        $this->log("topic=".$topic." graphqlTopic=".$graphqlTopic." url=".$url, LOG_DEBUG);

        // Mutation GraphQL pour créer un webhook
        $mutation = '
            mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
                webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                    webhookSubscription {
                        id
                        topic
                        callbackUrl
                        apiVersion {
                            handle
                        }
                        createdAt
                        updatedAt
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';

        $variables = array(
            'topic' => $graphqlTopic,
            'webhookSubscription' => array(
                'callbackUrl' => $url,
                'format' => 'JSON'
            )
        );

        $result = $this->shopifyApi->executeGraphQL(array(
            'query' => $mutation,
            'variables' => $variables
        ));

        if (!$result) {
            $this->errors[] = "Failed to create webhook: ".$this->shopifyApi->error;
            return false;
        }

        // Vérifier les erreurs GraphQL top-level (ex: topic invalide)
        if (!empty($result->errors)) {
            foreach ($result->errors as $error) {
                $msg = isset($error->message) ? $error->message : 'Unknown GraphQL error';
                $this->errors[] = "GraphQL error: ".$msg;
                $this->log("GraphQL top-level error: ".$msg, LOG_ERR);
            }
            return false;
        }

        // Vérifier que data existe
        if (!isset($result->data) || !isset($result->data->webhookSubscriptionCreate)) {
            $this->errors[] = "Invalid GraphQL response: missing data.webhookSubscriptionCreate";
            return false;
        }

        $data = $result->data->webhookSubscriptionCreate;

        if (!empty($data->userErrors)) {
            $userErrorMessages = array();
            foreach ($data->userErrors as $error) {
                $this->errors[] = $error->field.": ".$error->message;
                $userErrorMessages[] = isset($error->message) ? $error->message : '';
            }
            // Story 59-4 (AC4) : classifier AVANT de renvoyer false, pour que l'appelant
            // (createWebhookWithDatabase() -> cronCheckWebhookHealth()) distingue un refus durable
            // (topic non éligible à la portée OAuth de l'app, ex. orders/partially_fulfilled chez
            // Nicolas Graillon) d'un aléa transitoire, et n'insiste pas en boucle sur un topic qui
            // ne pourra jamais aboutir sans action humaine (cf. doc de la fonction pour les limites
            // de cette classification heuristique).
            $this->lastCreateUserErrorIsDurable = doli2shopClassifyShopifyUserErrorAsDurable($userErrorMessages);
            return false;
        }

        $webhook = $data->webhookSubscription;

        // Convertir le topic GraphQL vers le format REST pour stockage cohérent
        $restTopic = self::topicFromGraphQL($webhook->topic);

        // Hotfix 2.4.5 (Nicolas Graillon 2026-07-28) : $webhook->createdAt/updatedAt sont au
        // format ISO-8601 Shopify (ex. '2026-07-28T07:18:40Z') — insérés bruts, ils déclenchent
        // DB_ERROR_1292 en MySQL/MariaDB strict sur une colonne datetime. Conversion obligatoire.
        $createdAtSql = $this->formatShopifyTimestampForSql($webhook->createdAt ?? null);
        $updatedAtSql = $this->formatShopifyTimestampForSql($webhook->updatedAt ?? null);

        // fk_store : rowid de la boutique courante (0 = chemin historique / boutique défaut via backfill)
        $fkStore = ($this->store !== null && isset($this->store->rowid) && (int) $this->store->rowid > 0)
            ? (int) $this->store->rowid
            : 0;

        // Stocker dans la base de données
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_webhooks";
        $sql .= " (webhook_id, topic, url, api_version, status, created_at_shopify, updated_at_shopify,";
        $sql .= " datec, fk_user_creat, entity, fk_store)";
        $sql .= " VALUES (";
        $sql .= " '".$this->db->escape($webhook->id)."',";
        $sql .= " '".$this->db->escape($restTopic)."',";

        $sql .= " '".$this->db->escape($webhook->callbackUrl)."',";
        $sql .= " '".$this->db->escape($webhook->apiVersion->handle)."',";
        $sql .= " 1,"; // status = 1 (actif)
        $sql .= " ".$createdAtSql.",";
        $sql .= " ".$updatedAtSql.",";
        $sql .= " NOW(),";
        $sql .= " ".((int) $user->id).",";
        $sql .= " ".((int) $conf->entity).",";
        $sql .= " ".$fkStore;
        $sql .= ")";
        $sql .= " ON DUPLICATE KEY UPDATE";
        $sql .= " topic = VALUES(topic),";
        $sql .= " url = VALUES(url),";
        $sql .= " api_version = VALUES(api_version),";
        $sql .= " status = VALUES(status),";
        $sql .= " updated_at_shopify = VALUES(updated_at_shopify),";
        // Ne PAS écraser un fk_store déjà renseigné (>0) par un 0 du chemin global/historique
        // (sinon une simple resync remettrait fk_store=0 et casserait handleAppUninstalled ciblé — review HIGH)
        $sql .= " fk_store = IF(VALUES(fk_store) > 0, VALUES(fk_store), fk_store),";
        $sql .= " fk_user_modif = ".((int) $user->id);
        
        $resql = SqlUtils::executeQuery($this->db, $sql, "ShopifyWebhooks::createWebhook", true, array(), 3);

        if ($resql < 0) {
            $this->errors[] = $this->db->lasterror();
            return false;
        }

        // Story 49-8 B1.1 : logger la version retournée par Shopify vs la version CIBLE webhooks.
        // Story 51-2 (Code Review MEDIUM x3) : comparer à $this->getApiVersion() (= WEBHOOK_TARGET_API_VERSION,
        // pilotée par shopify-app/shopify.app.toml), PAS à $this->shopifyApi->getApiVersion() (endpoint GraphQL,
        // bumpé indépendamment en 2026-07 par cette même story) — sinon WARNING permanent à chaque création
        // de webhook tant que le TOML n'est pas déployé (les deux versions divergent alors systématiquement).
        // Cf. syncWebhooks() l.~537 qui utilise déjà $this->getApiVersion() pour la même raison.
        $shopifyReturnedVersion = isset($webhook->apiVersion->handle) ? (string) $webhook->apiVersion->handle : 'unknown';
        $targetVersion = (string) $this->getApiVersion();
        if ($shopifyReturnedVersion !== $targetVersion) {
            // Review 49-8 LOW : INFO (pas WARNING) — pas d'action de suppression/recréation ici,
            // ce log est purement informatif ; ne pas polluer les logs d'avertissements à chaque création.
            $this->log(
                "ShopifyWebhooks::createWebhook - topic=" . $restTopic
                . " apiVersion retournée par Shopify=" . $shopifyReturnedVersion
                . " (cible webhooks=" . $targetVersion . ")"
                . " — la version de livraison est dictée par la version d'app du Shopify Partner Dashboard,"
                . " pas par l'endpoint d'appel GraphQL.",
                LOG_INFO
            );
        } else {
            $this->log(
                "ShopifyWebhooks::createWebhook - topic=" . $restTopic
                . " apiVersion retournée par Shopify=" . $shopifyReturnedVersion
                . " (cible=" . $targetVersion . ") — versions alignées.",
                LOG_INFO
            );
        }

        return $webhook;
    }
    
    /**
     * Delete a webhook subscription
     *
     * @param string $webhookId Webhook ID from Shopify
     * @return bool True if success, false if error
     */
    public function deleteWebhook($webhookId)
    {
        global $conf;
        
        $this->log("webhookId=".$webhookId, LOG_DEBUG);
        
        // Mutation GraphQL pour supprimer un webhook
        $mutation = '
            mutation webhookSubscriptionDelete($id: ID!) {
                webhookSubscriptionDelete(id: $id) {
                    deletedWebhookSubscriptionId
                    userErrors {
                        field
                        message
                    }
                }
            }
        ';
        
        $variables = array(
            'id' => $webhookId
        );
        
        $result = $this->shopifyApi->executeGraphQL(array(
            'query' => $mutation,
            'variables' => $variables
        ));
        
        if (!$result) {
            $this->errors[] = "Failed to delete webhook: ".$this->shopifyApi->error;
            return false;
        }
        
        $data = $result->data->webhookSubscriptionDelete;

        if (!empty($data->userErrors)) {
            foreach ($data->userErrors as $error) {
                $this->errors[] = $error->field.": ".$error->message;
            }
            return false;
        }
        
        // Supprimer de la base de données
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."doli2shop_webhooks";
        $sql .= " WHERE webhook_id = '".$this->db->escape($webhookId)."'";
        $sql .= " AND entity = ".((int) $conf->entity);
        
        $resql = SqlUtils::executeQuery($this->db, $sql, "ShopifyWebhooks::deleteWebhook", true, array(), 3);
        
        if ($resql < 0) {
            $this->errors[] = $this->db->lasterror();
            return false;
        }
        
        return true;
    }
    
    /**
     * Upgrade all webhook subscriptions to the current API version
     * Deletes and recreates each webhook so Shopify uses the latest API version
     *
     * @return int Number of upgraded webhooks, or -1 if error
     */
    public function upgradeWebhooksApiVersion()
    {
        global $conf;

        $this->log("Upgrading all webhooks to current API version", LOG_INFO);

        // Story 60-1 (point 4, MEDIUM) : `ShopifyApi::executeGraphQL()` LÈVE une Exception après
        // épuisement de ses 3 tentatives réseau (timeout, DNS, connexion refusée) — ni
        // `listWebhooks()`, ni `deleteWebhook()`, ni `createWebhookWithDatabase()` (qui la laisse
        // volontairement se propager, cf. son propre try/catch) ne la rattrapent. Sans ce filet,
        // cette méthode plantait la page admin (erreur fatale PHP) au lieu d'afficher le message
        // d'échec attendu — alors que l'AC4-b prétendait déjà fermer cette classe d'échec. Même
        // filet que `synchronizeWithShopify()` : $this->errors[] + retour négatif, jamais fuiter.
        try {
            // Lister les webhooks existants sur Shopify.
            // Story 60-1 (AC4-b) : `listWebhooks()` ne renvoie JAMAIS `false` — toujours un tableau
            // vide en cas d'échec, avec `$this->errors` peuplé. Le test `=== false` ci-dessous était
            // donc du code MORT : une panne d'API produisait une boucle vide (0 webhook à parcourir)
            // et le message affiché ("Aucun webhook à mettre à jour") était indiscernable d'un "rien
            // à faire" légitime (Shopify sans webhook réel). Reset préalable de $this->errors — même
            // précaution que synchronizeWithShopify() — pour ne jamais confondre une erreur laissée
            // par un appel précédent avec un échec de CET appel.
            $this->errors = array();
            $existingWebhooks = $this->listWebhooks();
            if (empty($existingWebhooks) && !empty($this->errors)) {
                $this->log(
                    "ShopifyWebhooks::upgradeWebhooksApiVersion - Impossible de lister les webhooks Shopify,"
                    . " abandon : " . implode(', ', $this->errors),
                    LOG_ERR
                );
                return -1;
            }

            $upgraded = 0;
            $errors = 0;

            foreach ($existingWebhooks as $webhook) {
                $webhookGid = $webhook->id;
                $topic = self::topicFromGraphQL($webhook->topic);
                $callbackUrl = $webhook->callbackUrl ?? '';
                $currentVersion = isset($webhook->apiVersion) ? $webhook->apiVersion->handle : 'unknown';

                $this->log("Upgrading webhook: topic=" . $topic . " currentVersion=" . $currentVersion . " id=" . $webhookGid, LOG_INFO);

                // 1. Supprimer l'ancien webhook
                $deleteResult = $this->deleteWebhook($webhookGid);
                if (!$deleteResult) {
                    $this->log("Failed to delete webhook " . $webhookGid . " for topic " . $topic, LOG_ERR);
                    $errors++;
                    continue;
                }

                // 2. Recréer avec la version API courante
                $createResult = $this->createWebhookWithDatabase($topic);
                if ($createResult < 0) {
                    $this->log("Failed to recreate webhook for topic " . $topic, LOG_ERR);
                    $errors++;
                    continue;
                }

                $upgraded++;
                $this->log("Webhook upgraded: topic=" . $topic . " from " . $currentVersion . " to current", LOG_INFO);
            }

            $this->log("Upgrade complete: " . $upgraded . " upgraded, " . $errors . " errors", LOG_INFO);

            if ($errors > 0 && $upgraded == 0) {
                return -1;
            }

            return $upgraded;
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log(
                "ShopifyWebhooks::upgradeWebhooksApiVersion - exception non rattrapée par un appel"
                . " interne (probable panne réseau après retries) : " . $e->getMessage(),
                LOG_ERR
            );
            return -1;
        }
    }

    /**
     * List all webhook subscriptions
     *
     * @return array Array of webhooks
     */
    public function listWebhooks()
    {
        $this->log("", LOG_DEBUG);

        // Code Review 3 couches (Epic 59, MEDIUM 7) : `webhookSubscriptions(first: 250)` ne traitait
        // ni `pageInfo.hasNextPage` ni `cursor` — une liste TRONQUÉE était indiscernable d'une liste
        // complète. Depuis la story 59-4, une absence PRÉTENDUE d'un topic déclenche une écriture
        // DESTRUCTIVE (`webhook_id` mis à NULL puis recréation) : au-delà de 250 abonnements sur une
        // boutique, un topic réellement actif aurait été nettoyé puis dupliqué chez Shopify. La
        // convention du projet impose de paginer réellement plutôt que de se contenter de détecter la
        // troncature — c'est ce que fait cette implémentation.
        $webhooks = array();
        $cursor = null;
        $pageCount = 0;
        // Garde-fou de sécurité : jamais un nombre RÉEL de webhooks Shopify (largement < 100 par
        // boutique en pratique pour ce module, 11 topics supportés au maximum), seulement une
        // protection contre une API qui renverrait indéfiniment hasNextPage=true (réponse corrompue
        // ou bug côté Shopify) — sans cette borne, cette méthode boucherait indéfiniment.
        $maxPages = 40; // 40 x 250 = 10 000 webhooks

        do {
            $query = '
                query($cursor: String) {
                    webhookSubscriptions(first: 250, after: $cursor) {
                        edges {
                            cursor
                            node {
                                id
                                topic
                                callbackUrl
                                apiVersion {
                                    handle
                                }
                                createdAt
                                updatedAt
                            }
                        }
                        pageInfo {
                            hasNextPage
                        }
                    }
                }
            ';

            $result = $this->shopifyApi->executeGraphQL(array(
                'query' => $query,
                'variables' => array('cursor' => $cursor),
            ));

            if (!$result) {
                $this->errors[] = "Failed to list webhooks: ".$this->shopifyApi->error;
                return array();
            }

            // Validation structurelle de la réponse GraphQL
            if (!isset($result->data) || !isset($result->data->webhookSubscriptions) || !isset($result->data->webhookSubscriptions->edges)) {
                $this->errors[] = "Invalid GraphQL response: missing data.webhookSubscriptions.edges";
                $this->log("ShopifyWebhooks::listWebhooks - Invalid GraphQL response structure", LOG_ERR);
                return array();
            }

            $page = $result->data->webhookSubscriptions;
            $lastCursor = null;
            foreach ($page->edges as $edge) {
                $node = $edge->node;
                // Convertir le topic GraphQL ENUM vers le format REST pour cohérence interne
                $node->topic = self::topicFromGraphQL($node->topic);
                $webhooks[] = $node;
                if (isset($edge->cursor)) {
                    $lastCursor = $edge->cursor;
                }
            }

            $hasNextPage = isset($page->pageInfo->hasNextPage) && $page->pageInfo->hasNextPage === true;
            $pageCount++;

            if ($hasNextPage && ($lastCursor === null || $lastCursor === '')) {
                // Réponse incohérente : hasNextPage=true sans curseur exploitable pour continuer.
                // Échouer explicitement (comme un échec réseau) plutôt que de boucler sur le même
                // curseur ou de tronquer silencieusement la liste retournée.
                $this->errors[] = "Invalid GraphQL response: hasNextPage=true without a usable cursor";
                $this->log("ShopifyWebhooks::listWebhooks - hasNextPage=true sans curseur exploitable, abandon", LOG_ERR);
                return array();
            }

            if ($hasNextPage && $pageCount >= $maxPages) {
                $this->errors[] = "Too many webhook pages (".$pageCount."), aborting pagination as a safety guard";
                $this->log("ShopifyWebhooks::listWebhooks - Garde-fou de pagination atteint (".$pageCount." pages), abandon", LOG_ERR);
                return array();
            }

            $cursor = $lastCursor;
        } while ($hasNextPage);

        return $webhooks;
    }
    
    /**
     * Verify webhook HMAC signature
     *
     * @param string $data        Raw webhook data
     * @param string $hmacHeader  HMAC header from Shopify
     * @return bool True if valid, false otherwise
     */
    public function verifyWebhookHMAC($data, $hmacHeader)
    {
        global $conf;

        $webhookSecret = getDolGlobalString('SHOPIFY_WEBHOOK_SECRET', '');
        if (empty($webhookSecret)) {
            // Fallback: pour les apps Shopify OAuth, le secret webhook = client secret
            $webhookSecret = getDolGlobalString('DOLI2SHOP_API_SECRET_KEY', '');
        }

        if (empty($webhookSecret)) {
            $this->log("Webhook secret not configured (neither SHOPIFY_WEBHOOK_SECRET nor DOLI2SHOP_API_SECRET_KEY)", LOG_ERR);
            return false;
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $webhookSecret, true));

        $isValid = hash_equals($calculatedHmac, $hmacHeader);
        if (!$isValid) {
            $this->log("HMAC verification failed - Expected: " . substr($calculatedHmac, 0, 10) . "... Got: " . substr($hmacHeader, 0, 10) . "...", LOG_WARNING);
        }

        return $isValid;
    }
    
    /**
     * Story 59-4 (AC2, ajout) : détecte, SANS RIEN MODIFIER ni recréer, les lignes locales ACTIVES
     * (`status = 1`, `webhook_id` non vide) dont l'identifiant ne correspond à AUCUN abonnement
     * réellement présent chez Shopify au moment de l'appel — y compris pour un topic que Shopify n'a
     * PAS classé « manquant » (il porte une autre souscription, sous un identifiant différent, cas
     * qu'aucune vérification existante ne couvrait avant cette story). Détection + journal
     * uniquement : la décision de RECRÉATION reste entièrement dans `createWebhookWithDatabase()`
     * (AC1), jamais ici — cette méthode ne fait aucune écriture.
     *
     * Appelée par `syncWebhooks()` ET par le repli mono-boutique de `cronCheckWebhookHealth()`, sur
     * l'instantané `listWebhooks()` déjà en main de l'appelant (aucun appel Shopify supplémentaire).
     *
     * Code Review 3 couches (Epic 59, MEDIUM 3) : la résolution de boutique utilise désormais
     * `resolveCanonicalStoreScope()` — la MÊME que `createWebhookWithDatabase()` — au lieu d'une
     * résolution simplifiée qui ne repliait JAMAIS sur la boutique par défaut RÉELLE quand
     * `$this->store === null`. Cette résolution simplifiée produisait `fkStore=0, isSecondary=false`
     * dans ce cas, et `doli2shopBuildStoreScopedSqlFilter(0, false)` renvoie alors une chaîne VIDE
     * (AUCUN filtre) : sur une installation à 3 boutiques, l'instantané Shopify de la boutique 1
     * servait à juger les lignes des boutiques 7 et 8, qui étaient signalées comme fantômes à tort
     * dès qu'elles avaient des webhooks actifs — rupture de l'invariant de cloisonnement `fk_store`.
     *
     * @param  array $liveShopifyWebhooks Résultat de `listWebhooks()` déjà récupéré par l'appelant
     * @return void
     * @since  2.4.8
     */
    private function logPhantomWebhookDivergence(array $liveShopifyWebhooks): void
    {
        global $conf;

        $scope = $this->resolveCanonicalStoreScope();
        $fkStore = $scope['fkStore'];
        $isSecondary = $scope['isSecondary'];

        $sql = "SELECT rowid, webhook_id, topic FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks";
        $sql .= " WHERE entity = " . ((int) $conf->entity);
        $sql .= " AND status = 1";
        $sql .= doli2shopBuildStoreScopedSqlFilter($fkStore, $isSecondary);

        $resql = $this->db->query($sql);
        if (!$resql) {
            // Purement diagnostique : un échec ici ne doit JAMAIS interrompre syncWebhooks() ni le
            // CRON de santé — ce n'est qu'un signalement, pas une opération critique.
            $this->log(
                "logPhantomWebhookDivergence - échec SELECT (diagnostic uniquement, ignoré) : " . $this->db->lasterror(),
                LOG_WARNING
            );
            return;
        }

        $localActiveRows = array();
        while ($rowObj = $this->db->fetch_object($resql)) {
            $localActiveRows[] = $rowObj;
        }

        $phantomRows = doli2shopFindPhantomWebhookRows($localActiveRows, $liveShopifyWebhooks);
        if (!empty($phantomRows)) {
            $topics = array_map(function ($row) {
                return isset($row->topic) ? $row->topic : '?';
            }, $phantomRows);
            $this->log(
                "ShopifyWebhooks - Divergence détectée (AC2, fk_store=" . $fkStore . ") : "
                . count($phantomRows) . " ligne(s) locale(s) active(s) référencent un webhook_id"
                . " absent chez Shopify : " . implode(', ', $topics)
                . " — sera recréé au prochain passage par createWebhookWithDatabase() (AC1) si le"
                . " topic est aussi absent de l'instantané Shopify.",
                LOG_WARNING
            );
        }
    }

    /**
     * Sync webhooks with Shopify
     * Creates missing webhooks and removes obsolete ones
     *
     * Code Review 3 couches (Epic 59, HIGH 1) : porte désormais la MÊME télémétrie fine que le repli
     * mono-boutique de `cronCheckWebhookHealth()` (`$this->lastSyncRecreatedCount`,
     * `$this->lastSyncDurableRefusalCount`, `$this->lastSyncFailedCount`, etc.) — ce chemin est celui
     * RÉELLEMENT emprunté en production par `syncWebhooksForStore()` (le module seede systématiquement
     * une boutique par défaut, `StoreService::getAll(true)` ne renvoie donc quasiment jamais un
     * tableau vide). Avant ce correctif, cette télémétrie n'existait QUE dans le repli, un chemin
     * quasi jamais emprunté.
     *
     * Story 59-9 (correctif review) : `$isManualTrigger` (défaut `true`) est propagé jusqu'à
     * `attemptCreateMissingWebhook()` — seul `cronCheckWebhookHealth()` (via `syncWebhooksForStore()`)
     * doit passer `false`. Tous les appelants existants (`admin/webhooks.php`, reconnexion OAuth)
     * sont des actions humaines explicites : le défaut `true` préserve leur comportement (jamais
     * ignorées par le disjoncteur) sans les obliger à passer le paramètre.
     *
     * @param array $topics          Array of topics to sync
     * @param bool  $isManualTrigger true = action humaine explicite (défaut) ; false = CRON périodique
     * @return bool True if success, false if error
     */
    public function syncWebhooks($topics, bool $isManualTrigger = true)
    {
        global $conf;

        $this->log("Syncing " . count($topics) . " topics", LOG_DEBUG);

        // Remise à zéro de la télémétrie de CET appel (Code Review, HIGH 1).
        $this->lastSyncMissingCount = 0;
        $this->lastSyncRecreatedCount = 0;
        $this->lastSyncAlreadyLiveCount = 0;
        $this->lastSyncUnverifiableCount = 0;
        $this->lastSyncDurableRefusalCount = 0;
        $this->lastSyncFailedCount = 0;

        // Récupérer les webhooks existants sur Shopify
        $existingWebhooks = $this->listWebhooks();

        // Review 48-5 H-1 : si listWebhooks() a échoué (API down / token expiré), il retourne un
        // tableau vide ET popule $this->errors. Sans ce guard, on interpréterait « API inaccessible »
        // comme « tous les webhooks manquent » → 11 createWebhook inutiles vers une API morte, et le
        // CRON santé compterait la boutique « OK ». Échouer proprement (même guard que cronCheckWebhookHealth).
        if (empty($existingWebhooks) && !empty($this->errors)) {
            $this->log("syncWebhooks - Impossible de lister les webhooks Shopify (API/token), abandon : " . implode(', ', $this->errors), LOG_ERR);
            return false;
        }

        $existingTopics = array();
        foreach ($existingWebhooks as $webhook) {
            // Les topics sont déjà en format REST grâce à topicFromGraphQL() dans listWebhooks()
            $existingTopics[$webhook->topic] = $webhook;
        }

        $this->log("Found " . count($existingTopics) . " existing webhooks on Shopify", LOG_DEBUG);

        // Story 59-4 (AC2, ajout) : croiser l'état LOCAL avec la réalité Shopify pour détecter la
        // divergence INVERSE de « topic manquant » — une ligne locale ACTIVE dont le webhook_id ne
        // correspond à aucun abonnement réellement présent chez Shopify, y compris pour un topic que
        // Shopify NE considère PAS comme manquant (il porte une autre souscription, sous un autre
        // identifiant). $existingWebhooks est déjà en main : détection/signalement pur, sans appel
        // Shopify supplémentaire.
        $this->logPhantomWebhookDivergence($existingWebhooks);

        // Version d'API cible pour les WEBHOOKS (Story 51-2 : $this->getApiVersion() retourne
        // ShopifyApi::WEBHOOK_TARGET_API_VERSION, PAS ShopifyApi::getApiVersion() qui est la
        // version d'endpoint GraphQL bumpée indépendamment — cf. commentaire T0 ci-dessus/T0.3).
        // Les webhooks enregistrés sur une version périmée sont ré-enregistrés (delete + recreate)
        // pour aligner la version de livraison du payload sur celle-ci.
        $targetApiVersion = $this->getApiVersion();

        // Code Review 3 couches (Epic 59, MEDIUM 5) : disjoncteur d'échecs consécutifs, chargé UNE
        // FOIS pour tout cet appel et persisté à la fin (cf. `attemptCreateMissingWebhook()`).
        $failStreaks = $this->loadWebhookFailStreaks();
        $scopeForFailStreak = $this->resolveCanonicalStoreScope();
        $fkStoreForFailStreak = $scopeForFailStreak['fkStore'];

        // Créer les webhooks manquants (utilise createWebhookWithDatabase qui a le fallback URL)
        foreach ($topics as $topic) {
            if (!isset($existingTopics[$topic])) {
                $this->lastSyncMissingCount++;
                $this->log("Creating missing webhook for topic " . $topic, LOG_INFO);
                $attemptOutcome = $this->attemptCreateMissingWebhook($topic, $fkStoreForFailStreak, $failStreaks, $isManualTrigger);
                switch ($attemptOutcome) {
                    case self::SYNC_ATTEMPT_RECREATED:
                        $this->lastSyncRecreatedCount++;
                        break;
                    case self::SYNC_ATTEMPT_ALREADY_LIVE:
                        $this->lastSyncAlreadyLiveCount++;
                        break;
                    case self::SYNC_ATTEMPT_UNVERIFIABLE:
                        $this->lastSyncUnverifiableCount++;
                        break;
                    case self::SYNC_ATTEMPT_DURABLE:
                        $this->lastSyncDurableRefusalCount++;
                        break;
                    default: // self::SYNC_ATTEMPT_FAILED
                        $this->lastSyncFailedCount++;
                        break;
                }
            } else {
                // Webhook présent : vérifier sa version d'API et le ré-enregistrer si périmée
                $existing = $existingTopics[$topic];
                $existingVersion = isset($existing->apiVersion->handle) ? $existing->apiVersion->handle : null;
                if ($targetApiVersion !== null && $existingVersion !== null && $existingVersion !== $targetApiVersion) {
                    $this->log("Webhook " . $topic . " en version API " . $existingVersion . " != cible " . $targetApiVersion . " — ré-enregistrement", LOG_INFO);
                    if ($this->deleteWebhook($existing->id)) {
                        $result = $this->createWebhookWithDatabase($topic);
                        if ($result < 0) {
                            $this->log("Échec ré-enregistrement webhook " . $topic . " : " . implode(', ', $this->errors), LOG_ERR);
                        } else {
                            // Story 49-8 B1.2 : après delete+recreate, si la version DB n'a pas changé
                            // (Shopify retourne toujours l'ancienne version), c'est normal : la version de
                            // livraison est dictée par la version d'app configurée dans le Shopify Partner
                            // Dashboard (partners.shopify.com), pas par la version d'endpoint GraphQL.
                            // Le ré-enregistrement a bien eu lieu ; la version restera inchangée côté DB
                            // jusqu'à mise à jour de la version d'app dans le Partner Dashboard.
                            // Review 49-8 LOW : INFO (pas WARNING) — le recreate a réussi, ce log est
                            // explicatif ; ne pas polluer les logs d'avertissements à chaque sync.
                            $this->log(
                                "ShopifyWebhooks::syncWebhooks - Webhook " . $topic . " ré-enregistré."
                                . " Si la version DB affiche toujours " . $existingVersion . " au lieu de " . $targetApiVersion
                                . ", cela est normal : la version de livraison des webhooks Shopify est dictée"
                                . " par la version d'app configurée dans le Shopify Partner Dashboard"
                                . " (partners.shopify.com → App → API access → API version)."
                                . " Le module ne peut pas forcer ce changement par ré-enregistrement.",
                                LOG_INFO
                            );
                        }
                    } else {
                        $this->log("Échec suppression de l'ancien webhook " . $topic . " avant ré-enregistrement", LOG_ERR);
                    }
                } else {
                    $this->log("Webhook already exists for topic " . $topic . " (version " . ($existingVersion ?? 'n/a') . ")", LOG_DEBUG);
                }
            }
        }

        // Supprimer les webhooks obsolètes (topics qui ne sont plus dans la liste supportée)
        foreach ($existingTopics as $topic => $webhook) {
            if (!in_array($topic, $topics)) {
                $this->log("Deleting obsolete webhook for topic " . $topic, LOG_INFO);
                $result = $this->deleteWebhook($webhook->id);
                if (!$result) {
                    $this->log("Failed to delete webhook for topic " . $topic, LOG_ERR);
                }
            }
        }

        // Code Review 3 couches (Epic 59, MEDIUM 5) : persister le disjoncteur pour la PROCHAINE
        // exécution (page admin suivante, ou prochain passage du CRON de santé).
        $this->saveWebhookFailStreaks($failStreaks);

        if ($this->lastSyncMissingCount > 0) {
            $this->log(
                "ShopifyWebhooks::syncWebhooks - " . $this->lastSyncRecreatedCount . "/" . $this->lastSyncMissingCount
                . " manquant(s) recréé(s), " . $this->lastSyncAlreadyLiveCount . " déjà vivant(s),"
                . " " . $this->lastSyncUnverifiableCount . " non vérifiable(s), " . $this->lastSyncDurableRefusalCount
                . " refus durable(s), " . $this->lastSyncFailedCount . " échec(s) ordinaire(s)",
                LOG_INFO
            );
        }

        return true;
    }

    /**
     * Synchronise les webhooks pour une boutique spécifique.
     *
     * Crée les abonnements manquants côté Shopify avec le token de la boutique
     * et les trace en base avec fk_store = $store->rowid.
     *
     * Le chemin sans boutique (global/historique) reste inchangé via syncWebhooks().
     *
     * Code Review 3 couches (Epic 59, HIGH 1) : la télémétrie fine de l'instance interne
     * (`$storeWebhooks->lastSyncRecreatedCount`, etc.) est désormais PORTÉE sur `$this`, pour que
     * `cronCheckWebhookHealth()` (multi-boutiques) puisse l'agréger sur toutes les boutiques — avant
     * ce correctif, cette télémétrie restait enfermée dans l'instance jetable `$storeWebhooks` et ne
     * remontait jamais à l'appelant.
     *
     * Story 59-9 (correctif review) : `$isManualTrigger` (défaut `true`) propagé jusqu'à
     * `syncWebhooks()`/`attemptCreateMissingWebhook()` — `cronCheckWebhookHealth()` (multi-boutiques)
     * est l'UNIQUE appelant qui doit passer `false`.
     *
     * @param object      $store           Objet boutique (credentials, rowid)
     * @param array|null  $topics          Topics à synchroniser (null = getSupportedTopics())
     * @param bool        $isManualTrigger true = action humaine explicite (défaut) ; false = CRON périodique
     * @return bool True si succès, false si erreur
     * @since 2.3.5
     */
    public function syncWebhooksForStore(object $store, ?array $topics = null, bool $isManualTrigger = true): bool
    {
        if (empty($store->rowid) || (int) $store->rowid <= 0) {
            $this->errors[] = 'syncWebhooksForStore() - boutique sans rowid valide';
            return false;
        }

        if ($topics === null) {
            $topics = $this->getSupportedTopics();
        }

        // Instancier un ShopifyWebhooks avec les credentials de cette boutique (méthode protégée
        // pour permettre le mock en test, cf. createStoreWebhooksInstance()).
        $storeWebhooks = $this->createStoreWebhooksInstance($store);
        $result = $storeWebhooks->syncWebhooks($topics, $isManualTrigger);

        // Porter la télémétrie de l'instance interne vers CETTE instance, QUE l'appel ait réussi ou
        // non (même un échec "dur" — API down — peut avoir traité quelques topics avant d'échouer).
        $this->lastSyncMissingCount = $storeWebhooks->lastSyncMissingCount;
        $this->lastSyncRecreatedCount = $storeWebhooks->lastSyncRecreatedCount;
        $this->lastSyncAlreadyLiveCount = $storeWebhooks->lastSyncAlreadyLiveCount;
        $this->lastSyncUnverifiableCount = $storeWebhooks->lastSyncUnverifiableCount;
        $this->lastSyncDurableRefusalCount = $storeWebhooks->lastSyncDurableRefusalCount;
        $this->lastSyncFailedCount = $storeWebhooks->lastSyncFailedCount;

        if (!$result) {
            $this->errors = array_merge($this->errors, $storeWebhooks->errors);
            return false;
        }

        return true;
    }

    /**
     * Get webhook info from database
     *
     * @param int $id Row id
     * @return object|null Webhook object or null if not found
     */
    public function getWebhookInfo($id)
    {
        global $conf;
        
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."doli2shop_webhooks";
        $sql .= " WHERE rowid = ".((int) $id);
        $sql .= " AND entity = ".((int) $conf->entity);
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = $this->db->lasterror();
            return null;
        }
        
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        
        return $obj;
    }
    
    /**
     * Get list of supported webhook topics
     *
     * @return array Array of supported topics
     */
    public function getSupportedTopics()
    {
        return array(
            // Products
            'products/create',
            'products/update',
            'products/delete',
            
            // Orders
            'orders/create',
            'orders/updated',
            'orders/cancelled',
            'orders/fulfilled',
            'orders/paid',
            'orders/partially_fulfilled',
            
            // Stock
            'inventory_levels/update',
            
            // Application
            'app/uninstalled'
        );
    }
    
    /**
     * Synchronize webhooks with Shopify
     * Updates local database with webhook status from Shopify
     *
     * @return int Number of webhooks synchronized, -1 if error
     */
    public function synchronizeWithShopify()
    {
        // Story 59-4 : $user manquait de la déclaration `global` alors que le code plus bas le lit
        // via `isset($user->id) ? $user->id : 1` (deux occurrences, fk_user_creat/fk_user_modif) —
        // bug préexistant repéré en marge de cette story (autre agent), corrigé au passage : sans
        // `global $user`, la variable était toujours indéfinie dans cette portée, `isset()` renvoyait
        // systématiquement false et la ligne de trace tombait toujours sur l'utilisateur 1 (souvent
        // l'admin technique), quel que soit l'utilisateur réellement à l'origine de l'appel (page
        // admin ou CRON exécuté sous un autre compte).
        global $conf, $user;

        $this->log("", LOG_DEBUG);
        
        $this->db->begin();

        try {
            // Récupérer les webhooks depuis Shopify.
            // Story 60-1 (AC4-a) : reset préalable de $this->errors — même précaution que
            // WebhookSettingsSaver::saveTopics() avant son propre appel mutualisé — pour ne
            // jamais confondre une erreur laissée par un appel PRÉCÉDENT sur cette même instance
            // avec un échec de CET appel.
            $this->errors = array();
            $shopifyWebhooks = $this->listWebhooks();

            if (empty($shopifyWebhooks) && !empty($this->errors)) {
                // AC4-a : listWebhooks() a échoué (API down, jeton expiré...) — ne renvoie
                // JAMAIS `false`, toujours un tableau vide, comme une liste RÉELLEMENT vide
                // côté Shopify. Avant ce correctif, cette confusion n'était contrôlée nulle
                // part ici (contrairement à TOUS les autres appelants de listWebhooks() dans
                // cette classe) : le UPDATE de masse plus bas perdait alors sa clause
                // restrictive (`count($webhookIds) > 0`) et désactivait TOUS les webhooks de
                // l'entité en un seul appel — exactement l'état constaté chez le client. Une
                // liste vide issue d'un échec est une condition d'ARRÊT, jamais un filtre
                // optionnel : on abandonne avant toute écriture.
                throw new Exception(
                    'Impossible de lister les webhooks Shopify (API/jeton) : '
                    . implode(', ', $this->errors) . ' — synchronisation abandonnée, aucune écriture effectuée.'
                );
            }

            // Story 60-1 (point 1, CRITICAL) : résolution CANONIQUE de la boutique — la MÊME que
            // createWebhookWithDatabase()/logPhantomWebhookDivergence() — au lieu d'un calcul ad hoc
            // qui ignorait le repli sur la boutique par défaut RÉELLE quand `$this->store === null`
            // (page admin de la boutique par défaut). Cette résolution est indispensable pour cloisonner
            // le UPDATE de masse ci-dessous par boutique (cf. son commentaire) : sans elle, un client à
            // plusieurs boutiques qui clique "Synchroniser" sur UNE boutique (listWebhooks() ne renvoie
            // QUE les webhook_id de CETTE boutique) désactivait silencieusement TOUS les webhooks des
            // AUTRES boutiques dès que l'appel réussissait — aucune panne d'API n'est nécessaire, un
            // clic normal suffit. C'est l'état exact constaté chez le client (deux boutiques secondaires
            // entièrement à status=0, webhook_id intacts) : le correctif AC4-a (échec de listWebhooks())
            // ne couvrait PAS ce cas, seulement celui d'un appel en échec.
            $scope = $this->resolveCanonicalStoreScope();
            $fkStore = $scope['fkStore'];
            $isSecondaryStore = $scope['isSecondary'];

            // Mettre à jour la base de données locale
            $count = 0;
            foreach ($shopifyWebhooks as $webhook) {
                $sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_webhooks";
                $sql .= " (topic, webhook_id, status, url, entity, fk_user_creat, datec, fk_store)";
                $sql .= " VALUES (";
                $sql .= "'".$this->db->escape($webhook->topic)."',";
                $sql .= "'".$this->db->escape($webhook->id)."',";
                $sql .= "1,"; // Status actif
                $sql .= "'".$this->db->escape($webhook->callbackUrl)."',";
                $sql .= $conf->entity.",";
                $sql .= (isset($user->id) ? $user->id : 1).",";
                $sql .= "'".$this->db->idate(dol_now())."',";
                $sql .= $fkStore;
                $sql .= ")";
                $sql .= " ON DUPLICATE KEY UPDATE";
                $sql .= " topic = VALUES(topic),";
                $sql .= " webhook_id = '".$this->db->escape($webhook->id)."',";
                $sql .= " status = 1,";
                $sql .= " url = '".$this->db->escape($webhook->callbackUrl)."',";
                // Ne pas écraser un fk_store >0 existant par un 0 (chemin global) — review HIGH
                $sql .= " fk_store = IF(".((int) $fkStore)." > 0, ".((int) $fkStore).", fk_store),";
                $sql .= " fk_user_modif = ".(isset($user->id) ? (int) $user->id : 1).",";
                $sql .= " tms = '".$this->db->idate(dol_now())."'";
                
                $resql = $this->db->query($sql);
                if (!$resql) {
                    throw new Exception($this->db->lasterror());
                }
                $count++;
            }
            
            // Marquer comme inactifs les webhooks qui n'existent plus dans Shopify.
            // Story 60-1 (AC4-a) : à ce point, l'échec de listWebhooks() a déjà été écarté
            // ci-dessus (throw + rollback) — `$webhookIds` ne peut plus être vide qu'à cause
            // d'un Shopify RÉELLEMENT sans abonnement, jamais d'une panne d'API. Marquer TOUS
            // les webhooks de l'entité comme inactifs est alors le comportement VOULU (rien
            // n'existe plus côté Shopify), pas une clause de filtrage perdue par accident.
            $webhookIds = array_map(function($w) { return "'".$this->db->escape($w->id)."'"; }, $shopifyWebhooks);

            // Story 60-1 (point 1, CRITICAL) : `listWebhooks()` n'interroge QUE la boutique de cette
            // instance ($this->store) — ses identifiants Shopify n'ont donc aucune valeur pour juger
            // des lignes des AUTRES boutiques, qui ont leurs propres webhook_id sur leur propre
            // installation Shopify. Sans ce cloisonnement, ce UPDATE de masse (déjà protégé contre le
            // cas "échec de listWebhooks()" par le throw plus haut, AC4-a) restait néanmoins un
            // UPDATE ENTITÉ ENTIÈRE : un simple clic "Synchroniser" réussi sur la boutique A désactivait
            // silencieusement TOUTES les lignes des boutiques B et C, sous un message de succès plein.
            // Même filtre, même fonction que TOUS les autres appelants scopés de cette classe
            // (doli2shopBuildStoreScopedSqlFilter()) — aucune réimplémentation.
            $sql = "UPDATE ".MAIN_DB_PREFIX."doli2shop_webhooks";
            $sql .= " SET status = 0";
            $sql .= " WHERE entity = ".$conf->entity;
            $sql .= doli2shopBuildStoreScopedSqlFilter($fkStore, $isSecondaryStore);
            if (count($webhookIds) > 0) {
                $sql .= " AND webhook_id NOT IN (".implode(',', $webhookIds).")";
            }

            $resql = $this->db->query($sql);
            if (!$resql) {
                throw new Exception($this->db->lasterror());
            }
            
            $this->db->commit();
            return $count;
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->errors[] = $e->getMessage();
            return -1;
        }
    }
    
    /**
     * Create a webhook in Shopify and save in database
     *
     * Story 2.4.7-2 : point d'entrée UNIQUE (page admin ET CRON cronCheckWebhookHealth()) —
     * le verrou anti-course est donc centralisé ici, une seule fois, plutôt que dupliqué chez
     * les deux appelants (c'est cette duplication qui a produit le défaut d'origine, deux fois).
     *
     * La course : deux appelants peuvent lire « aucun abonnement vivant » au même instant (l'un
     * sur la base locale, l'autre sur un appel Shopify live) et créer chacun un abonnement pour
     * le même topic — deux `webhook_id` distincts, donc double livraison silencieuse des
     * événements Shopify. Un verrou avisor MySQL (`GET_LOCK`, via `CronHelperTrait`) sérialise
     * les créations concurrentes pour le même (topic, entity, fk_store) ; pas de
     * `SELECT ... FOR UPDATE` : la course porte sur l'ABSENCE de ligne, il n'y a donc rien à
     * verrouiller par lock de ligne (ne tiendrait que par les gap locks InnoDB — subtils, source
     * de deadlocks).
     *
     * Pattern acquire → re-vérification → skip, précédent identique à
     * `ShopifyOrderManager::createOrder()` (anti-doublon commande) : après acquisition, on
     * relit l'état LOCAL avant de créer — si un autre appelant a gagné la course pendant qu'on
     * attendait le verrou, on constate l'abonnement déjà vivant et on passe son tour SANS ERREUR
     * (le perdant de la course n'est pas un échec).
     *
     * Le timeout d'acquisition est court et explicite : en cas d'échec (verrou détenu par un
     * autre processus au-delà du timeout), on ne bloque JAMAIS indéfiniment et on ne renvoie
     * jamais un succès silencieux — `$lastCreateLockTimedOut` est positionné pour que l'appelant
     * (WebhookSettingsSaver) puisse le signaler explicitement à l'utilisateur.
     *
     * @param string $topic Webhook topic
     * @param string $url   Optional callback URL (uses default if empty)
     * @return int 1 if success (créé, ou déjà vivant après re-vérification), -1 if error
     * @since 2.4.7
     */
    public function createWebhookWithDatabase($topic, $url = '')
    {
        global $conf, $user;

        $this->lastCreateLockTimedOut = false;
        $this->lastCreateUserErrorIsDurable = false;
        // Code Review 3 couches (Epic 59, MEDIUM 2) : jamais laisser fuiter la classification d'un
        // appel précédent (même principe que les deux drapeaux ci-dessus).
        $this->lastCreateOutcome = null;

        // Story 2.4.7-2 (Code Review round 2, HIGH 3) : budget de temps global — voir le
        // commentaire de $createLoopDeadline. Initialisation paresseuse, au tout premier appel de
        // cette instance.
        if ($this->createLoopDeadline === null) {
            $this->createLoopDeadline = $this->nowForBudget() + self::CREATE_WEBHOOK_LOOP_TIME_BUDGET_SECONDS;
        }

        $remainingBudget = $this->createLoopDeadline - $this->nowForBudget();
        if ($remainingBudget <= 0) {
            // Budget épuisé : abandon EXPLICITE (message + log), jamais un blocage jusqu'au
            // timeout PHP de la requête entière. Les topics restants seront repris au prochain
            // passage (nouvel enregistrement, CRON de santé suivant).
            $this->errors[] = 'Budget de temps global (' . self::CREATE_WEBHOOK_LOOP_TIME_BUDGET_SECONDS
                . 's) dépassé pour la création des webhooks : le topic ' . $topic . ' n\'a pas été traité.'
                . ' Réessayez — les topics restants seront repris au prochain passage (page admin,'
                . ' CRON de santé, ou nouvelle tentative).';
            $this->log(
                "ShopifyWebhooks::createWebhookWithDatabase - Budget de temps global dépassé, topic="
                . $topic . " ignoré (aucune tentative de verrou)",
                LOG_WARNING
            );
            $this->lastCreateOutcome = self::CREATE_OUTCOME_ERROR;
            return -1;
        }

        // Récupérer l'URL de callback depuis la configuration ou utiliser celle passée
        $callbackUrl = !empty($url) ? $url : getDolGlobalString('SHOPIFY_WEBHOOK_URL');
        if (empty($callbackUrl)) {
            // Construire l'URL par défaut si non configurée
            // Utiliser DOL_MAIN_URL_ROOT pour éviter manipulation HTTP_HOST
            global $dolibarr_main_url_root;
            if (!empty($dolibarr_main_url_root)) {
                $callbackUrl = rtrim($dolibarr_main_url_root, '/').'/custom/doli2shop/webhooks/index.php';
            } else {
                // Fallback avec validation HTTP_HOST
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $domain = isset($_SERVER['HTTP_HOST']) ? filter_var($_SERVER['HTTP_HOST'], FILTER_SANITIZE_URL) : '';
                // Validation basique : interdire caractères dangereux
                if (empty($domain) || preg_match('/[<>"\'\s]/', $domain)) {
                    $this->errors[] = 'Invalid HTTP_HOST header (set SHOPIFY_WEBHOOK_URL or $dolibarr_main_url_root)';
                    $this->lastCreateOutcome = self::CREATE_OUTCOME_ERROR;
                    return -1;
                }
                // v2.2.8: aligné sur la branche $dolibarr_main_url_root — le module est
                // installé sous htdocs/custom/, l'ancien chemin sans /custom/ produisait
                // une callback URL en 404 (webhooks jamais reçus, table events vide)
                $callbackUrl = $protocol.'://'.$domain.'/custom/doli2shop/webhooks/index.php';
            }
        }

        // Story 2.4.7-2 (Code Review round 2, CRITICAL 2) : résolution CANONIQUE de la boutique,
        // avant de construire la clé de verrou ET le filtre de re-vérification. `$this->store` est
        // `null` quand l'appelant instancie `new ShopifyWebhooks($db)` SANS boutique pour la
        // boutique PAR DÉFAUT (`admin/webhooks.php:86`, `admin/oauth_receive.php:633`) — alors que
        // `cronCheckWebhookHealth()` boucle sur `StoreService::getAll(true)` (qui INCLUT la
        // boutique par défaut) et instancie avec l'objet réel (`fk_store` = son rowid réel). Sans
        // cette résolution, la MÊME boutique physique produit deux clés de verrou distinctes
        // (`..._s0` vs `..._s1`, aucune sérialisation entre elles) ET un filtre de re-vérification
        // VIDE (`doli2shopBuildStoreScopedSqlFilter(0, false)` ne filtre RIEN, cf. son code) qui
        // voit les lignes des AUTRES boutiques.
        //
        // Code Review 3 couches (Epic 59, MEDIUM 3) : extrait dans `resolveCanonicalStoreScope()`
        // pour être RÉUTILISÉ par `logPhantomWebhookDivergence()` (qui avait sa propre résolution,
        // simplifiée et buggée — cf. la doc de cette méthode).
        $scope = $this->resolveCanonicalStoreScope();
        $fkStore = $scope['fkStore'];
        $isSecondaryStore = $scope['isSecondary'];

        // Nom de verrou borné : "wh_create_<topic sans '/'>_s<fk_store>". L'entité est ajoutée
        // automatiquement par CronHelperTrait::buildCronLockName() (préfixe + suffixe), inutile
        // de la répéter ici.
        $lockName = 'wh_create_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $topic) . '_s' . $fkStore;

        // Timeout d'acquisition borné par le budget restant (HIGH 3) : jamais plus de 5s (précédent
        // identique : ShopifyOrderManager::createOrder()), mais réduit si le budget de la boucle est
        // presque épuisé — une seule attente de verrou ne doit jamais, à elle seule, consommer tout
        // le budget restant. Ni blocage indéfini, ni échec silencieux : un timeout dépassé signale
        // explicitement l'échec à l'appelant plutôt que de laisser croire à un succès.
        $lockTimeout = (int) max(1, min(5, floor($remainingBudget)));

        if (!$this->acquireCronLock($lockName, $lockTimeout)) {
            $this->lastCreateLockTimedOut = true;
            $this->errors[] = 'Impossible d\'acquérir le verrou de création pour le topic ' . $topic
                . ' (fk_store=' . $fkStore . ') : une autre création est déjà en cours (page admin ou CRON).';
            $this->log(
                "ShopifyWebhooks::createWebhookWithDatabase - Verrou non acquis pour topic=" . $topic
                . " fk_store=" . $fkStore . ", abandon (course avec un autre appelant)",
                LOG_WARNING
            );
            $this->lastCreateOutcome = self::CREATE_OUTCOME_ERROR;
            return -1;
        }

        try {
            // Re-vérification après acquisition (point 4) : un autre appelant a pu créer
            // l'abonnement pendant qu'on attendait le verrou. Même filtre boutique que l'affichage
            // admin (doli2shopBuildStoreScopedSqlFilter) et même règle d'agrégation d'état
            // (doli2shopComputeWebhookTopicState) — une seule source de vérité, réutilisée telle
            // quelle plutôt que ré-écrite ici. $fkStore/$isSecondaryStore sont désormais la
            // résolution CANONIQUE calculée ci-dessus (CRITICAL 2), pas `$this->store` brut.
            $sql = "SELECT rowid, webhook_id, fk_store, status FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks";
            $sql .= " WHERE topic = '" . $this->db->escape($topic) . "'";
            $sql .= " AND entity = " . ((int) $conf->entity);
            $sql .= doli2shopBuildStoreScopedSqlFilter($fkStore, $isSecondaryStore);

            $rowsForTopic = array();
            $resql = $this->db->query($sql);
            if ($resql) {
                while ($rowObj = $this->db->fetch_object($resql)) {
                    $rowsForTopic[] = $rowObj;
                }
            } else {
                // Échec du SELECT de re-vérification : on ne peut pas prouver qu'un abonnement
                // existe déjà. On continue sur le fail-open (tentative de création) plutôt que
                // d'échouer sur un simple aléa de lecture — le verrou reste de toute façon détenu
                // par ce processus, aucun autre appelant ne peut créer en parallèle pendant ce temps.
                $this->log(
                    "ShopifyWebhooks::createWebhookWithDatabase - Échec SELECT de re-vérification pour topic=" . $topic
                    . " : " . $this->db->lasterror() . " — poursuite (verrou toujours détenu)",
                    LOG_WARNING
                );
            }

            $topicState = doli2shopComputeWebhookTopicState($rowsForTopic);

            // Story 59-4 (AC1) : rowid de la ligne à nettoyer si elle se révèle FANTÔME ci-dessous
            // (webhook_id local ne correspondant à aucun abonnement réel chez Shopify). Le nettoyage
            // est différé DANS la transaction de création plus bas (AC5) : si la recréation échoue
            // ensuite, tout doit rester annulable en bloc, la ligne fantôme ne doit jamais être
            // marquée nettoyée sans qu'une tentative de remplacement ait réellement eu lieu.
            $phantomRowToClean = null;

            if ($topicState['liveRow'] !== null) {
                // Jusqu'à la story 59-4, la seule présence d'un `webhook_id` local suffisait ici à
                // conclure « déjà vivant » et à `return 1` SANS jamais interroger Shopify — exactement
                // le défaut qui a figé les cinq topics `orders/*` fantômes chez Nicolas Graillon
                // (2274184560980 et consorts) : abonnements révoqués côté Shopify (réinstallation
                // d'app, changement de version d'API) dont la base n'avait jamais été informée.
                //
                // Correction (AC1) : la vérification porte désormais sur l'existence CÔTÉ SHOPIFY,
                // pas sur la présence d'une chaîne en base. Fraîche et À L'INTÉRIEUR du verrou —
                // jamais un instantané pris par l'appelant AVANT l'acquisition (cf. syncWebhooks() qui
                // a pu proclamer le topic absent plusieurs dizaines de ms avant que nous obtenions le
                // verrou) : un autre appelant a pu créer l'abonnement entre-temps, et c'est
                // précisément ce que cette vérification fraîche doit voir pour ne jamais dupliquer un
                // abonnement réellement recréé par un gagnant de course concurrent.
                //
                // Appariement par TOPIC (pas par identifiant) : le gagnant d'une course crée un
                // NOUVEL identifiant, forcément différent de notre `webhook_id` local — un
                // appariement par identifiant confondrait ce cas légitime avec un fantôme et créerait
                // un second abonnement. `doli2shopFindPhantomWebhookRows()` (appariement par
                // identifiant) reste réservé à la détection/signalement large de
                // `logPhantomWebhookDivergence()` (AC2), un usage diagnostique différent.
                $freshShopifyWebhooks = $this->listWebhooks();

                if (empty($freshShopifyWebhooks) && !empty($this->errors)) {
                    // AC5 : l'appel Shopify de VÉRIFICATION échoue (API down, token expiré...). On ne
                    // peut prouver ni que l'abonnement est réel, ni qu'il est fantôme — fail-safe :
                    // ne PAS créer un doublon à l'aveugle, ne PAS non plus nettoyer une ligne dont on
                    // n'a rien prouvé. On conserve l'état local tel quel (comportement historique) et
                    // on signale explicitement que rien n'a été vérifié cette fois-ci, pour que la
                    // base ne reste jamais dans un état décidé sur une preuve absente.
                    $this->log(
                        "ShopifyWebhooks::createWebhookWithDatabase - Échec de la vérification Shopify"
                        . " (abonnement potentiellement fantôme) pour topic=" . $topic . " fk_store=" . $fkStore
                        . " : " . implode(', ', $this->errors) . " — état local conservé tel quel,"
                        . " aucune création, aucune preuve de fantôme établie",
                        LOG_WARNING
                    );
                    // Code Review 3 couches (Epic 59, MEDIUM 2) : cet état N'EST PAS un succès de
                    // recréation — rien n'a été prouvé ni créé. Distinct de CREATED, pour que
                    // l'appelant (attemptCreateMissingWebhook(), WebhookSettingsSaver) ne le
                    // journalise jamais comme "recreated successfully"/"PHANTOM RECREATED".
                    $this->lastCreateOutcome = self::CREATE_OUTCOME_UNVERIFIABLE;
                    return 1;
                }

                $freshTopicFoundAtShopify = false;
                foreach ($freshShopifyWebhooks as $freshWebhook) {
                    if (isset($freshWebhook->topic) && $freshWebhook->topic === $topic) {
                        $freshTopicFoundAtShopify = true;
                        break;
                    }
                }

                if ($freshTopicFoundAtShopify) {
                    // Confirmé RÉEL chez Shopify (soit notre propre abonnement, soit celui d'un
                    // gagnant de course concurrent) : rien à créer, pas de doublon.
                    $this->log(
                        "ShopifyWebhooks::createWebhookWithDatabase - topic=" . $topic . " fk_store=" . $fkStore
                        . " confirmé vivant CHEZ SHOPIFY après vérification fraîche, création évitée",
                        LOG_INFO
                    );
                    $this->lastCreateOutcome = self::CREATE_OUTCOME_ALREADY_LIVE;
                    return 1;
                }

                // Abonnement fantôme CONFIRMÉ (AC1) : la base référence un webhook_id que Shopify ne
                // connaît plus. On ne court-circuite plus la création — le nettoyage de la ligne
                // locale est différé dans la transaction de création ci-dessous (atomique avec elle).
                $phantomRowToClean = $topicState['liveRow'];
                $this->log(
                    "ShopifyWebhooks::createWebhookWithDatabase - topic=" . $topic . " fk_store=" . $fkStore
                    . " : abonnement FANTÔME confirmé (webhook_id=" . $phantomRowToClean->webhook_id
                    . " absent chez Shopify) — recréation au lieu du court-circuit historique",
                    LOG_WARNING
                );
            }

            // Le gagnant de la course (ou la recréation d'un fantôme confirmé ci-dessus) : on crée
            // réellement. createWebhook() effectue la mutation Shopify ET l'écriture locale.
            //
            // Story 2.4.7-2 (Code Review round 2, CRITICAL 1) : transaction PROPRE à cette méthode,
            // committée AVANT la libération du verrou (le `finally` ci-dessous). Le défaut d'origine :
            // l'appelant (`WebhookSettingsSaver::saveTopics()`) ouvrait SA PROPRE transaction autour
            // de cet appel. Les transactions Dolibarr sont imbriquées par COMPTEUR (`DoliDB::begin()`/
            // `commit()`) : un `$db->begin()` de l'appelant aurait fait de ce commit() un simple
            // décrémentement de compteur, sans committer réellement tant que l'appelant n'aurait pas
            // lui-même committé — donc APRÈS la libération du verrou. Fenêtre ouverte : un autre
            // processus acquiert le verrou entre-temps, refait la re-vérification sur SA PROPRE
            // connexion, ne voit pas l'INSERT non committé (isolation InnoDB) et crée un second
            // abonnement — la course que ce hotfix devait justement fermer. `WebhookSettingsSaver`
            // n'ouvre donc PLUS de transaction autour de cet appel (cf. son code) : c'est désormais
            // CETTE méthode, et elle seule, qui possède le cycle begin/commit/rollback de l'écriture.
            $this->db->begin();
            try {
                // Story 59-4 (AC5) : le nettoyage de la ligne fantôme fait partie de LA MÊME
                // transaction que la création — si createWebhook() échoue juste après, le rollback
                // restaure la ligne fantôme exactement comme avant (pas d'état intermédiaire où la
                // base ne référence plus AUCUN abonnement alors qu'aucune tentative de remplacement
                // n'a réellement abouti). Un échec de CETTE UPDATE seule (best-effort, cf. sa
                // documentation) ne bloque volontairement pas la tentative de création qui suit.
                if ($phantomRowToClean !== null) {
                    doli2shopMarkWebhookRowsAsPhantom($this->db, array((int) $phantomRowToClean->rowid));
                }
                $result = $this->createWebhook($topic, $callbackUrl);
            } catch (\Throwable $e) {
                // createWebhook() peut lever (SqlUtils::executeQuery($throwException = true) sur
                // l'INSERT local après épuisement des retries) : annuler NOTRE transaction avant de
                // laisser l'exception se propager (comportement de propagation inchangé, cf. tests).
                $this->db->rollback();
                throw $e;
            }

            if ($result) {
                $this->db->commit();
                // Une mutation Shopify a RÉELLEMENT eu lieu (création initiale, ou recréation d'un
                // fantôme confirmé ci-dessus) — c'est le SEUL cas où "recreated successfully" est vrai.
                $this->lastCreateOutcome = self::CREATE_OUTCOME_CREATED;
                return 1;
            }

            $this->db->rollback();
            $this->lastCreateOutcome = self::CREATE_OUTCOME_ERROR;
            return -1;
        } finally {
            // Libération garantie même si une exception traverse le bloc ci-dessus (point 6).
            $this->releaseCronLock($lockName);
        }
    }

    /**
     * CRON : Webhook health check and auto re-registration
     * Called every hour to verify all Shopify webhook subscriptions are active.
     * Recreates missing webhooks automatically (ADR-6).
     *
     * @return int 0 if OK, <>0 if KO (Dolibarr CRON convention)
     */
    public function cronCheckWebhookHealth()
    {
        global $conf;

        $this->errors = array();
        $startTime = microtime(true);

        try {
            // v2.2.0: Libérer locks transactionnels avant toute lecture llx_const
            $this->checkAndUnfreezeCron(
                (int) ($conf->entity ?? 1),
                '/doli2shop/class/shopifywebhooks.class.php'
            );

            $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Starting webhook health check", LOG_DEBUG);

            // Story 48-5 : santé webhooks MULTI-BOUTIQUES. Boucler sur les boutiques ACTIVES et,
            // pour chacune, créer les abonnements manquants ET ré-enregistrer ceux dont la version
            // d'API est périmée (syncWebhooksForStore -> syncWebhooks). Aligne toutes les boutiques
            // sur la version d'API courante (sans intervention) après une mise à jour du module.
            //
            // Code Review 3 couches (Epic 59, HIGH 1) : c'est le chemin RÉELLEMENT emprunté en
            // production (le module seede systématiquement une boutique par défaut,
            // `getAll(true)` ne renvoie donc quasiment jamais un tableau vide) — la télémétrie fine
            // (recréations/refus durables/échecs ordinaires) est désormais AGRÉGÉE sur toutes les
            // boutiques via les compteurs `lastSync*` que `syncWebhooksForStore()` porte sur `$this`,
            // au lieu de n'exister QUE dans le repli mono-boutique ci-dessous.
            $storeServiceHealth = $this->createStoreService((int) ($conf->entity ?? 1));
            $activeStores = $storeServiceHealth->getAll(true);

            if (!empty($activeStores)) {
                $storeCount  = 0;
                $storeOk     = 0;
                $storeFailed = 0;
                $totalRecreated = 0;
                $totalAlreadyLive = 0;
                $totalUnverifiable = 0;
                $totalDurableRefusal = 0;
                $totalFailed = 0;
                foreach ($activeStores as $healthStore) {
                    $storeCount++;
                    $this->errors = array(); // isoler les erreurs par boutique
                    // Story 59-9 : CRON périodique = jamais une action humaine explicite. Seul
                    // appelant qui doit conserver le disjoncteur (false), cf. doc de syncWebhooksForStore().
                    $res = $this->syncWebhooksForStore($healthStore, null, false);

                    $totalRecreated += $this->lastSyncRecreatedCount;
                    $totalAlreadyLive += $this->lastSyncAlreadyLiveCount;
                    $totalUnverifiable += $this->lastSyncUnverifiableCount;
                    $totalDurableRefusal += $this->lastSyncDurableRefusalCount;
                    $totalFailed += $this->lastSyncFailedCount;

                    // Code Review 3 couches (Epic 59, HIGH 1) : avant ce correctif, `$res` (booléen
                    // toujours `true` sauf échec DUR de listWebhooks()) était le SEUL signal de
                    // santé — un échec ORDINAIRE de recréation sur un topic (ni durable, ni API down)
                    // ne faisait jamais basculer la boutique en échec. Un refus DURABLE seul
                    // (`lastSyncDurableRefusalCount`), lui, ne doit JAMAIS faire basculer la
                    // boutique en échec (AC4/story 59-4 préservé sur le chemin réel).
                    if ($res && $this->lastSyncFailedCount === 0) {
                        $storeOk++;
                        $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Boutique #" . (int) $healthStore->rowid . " (" . $healthStore->shop_domain . ") webhooks synchronisés", LOG_DEBUG);
                    } else {
                        $storeFailed++;
                        $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Échec sync webhooks boutique #" . (int) $healthStore->rowid . " (" . $healthStore->shop_domain . ") : " . implode(', ', $this->errors), LOG_WARNING);
                    }
                    usleep(200000); // 200ms anti rate-limit entre boutiques
                }
                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->log(
                    "ShopifyWebhooks::cronCheckWebhookHealth - Multi-boutiques : " . $storeCount . " boutique(s), " . $storeOk . " ok, " . $storeFailed
                    . " échec, " . $totalRecreated . " recréé(s), " . $totalDurableRefusal . " refus durable(s),"
                    . " " . $totalFailed . " échec(s) ordinaire(s) de topic (" . $elapsedMs . "ms)",
                    LOG_INFO
                );
                $this->output = "Webhook health (multi-boutiques) : " . $storeCount . " boutique(s), " . $storeOk . " ok, " . $storeFailed
                    . " échec, " . $totalRecreated . " recreated, " . $totalDurableRefusal . " durable refusal(s) (" . $elapsedMs . "ms)";
                return ($storeFailed > 0) ? -1 : 0;
            }

            // Fallback legacy MONO-BOUTIQUE (aucune ligne llx_doli2shop_stores) — comportement
            // historique strictement inchangé : santé de la boutique par défaut via les constantes.
            $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Aucune boutique en base, fallback mono-boutique (constantes)", LOG_DEBUG);

            // 1. Lister les webhooks actuels sur Shopify via GraphQL
            $existingWebhooks = $this->listWebhooks();

            // Guard : si listWebhooks() a échoué (API down, token expiré, etc.)
            // il retourne array() vide ET popule $this->errors.
            // Sans ce guard, on interpréterait "API inaccessible" comme "tous les webhooks manquent"
            // et on tenterait de recréer les 11 webhooks inutilement.
            if (empty($existingWebhooks) && !empty($this->errors)) {
                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Cannot list webhooks from Shopify API, aborting (".$elapsedMs."ms): ".implode(', ', $this->errors), LOG_ERR);
                $this->output = "Webhook health check aborted: API error - ".implode(', ', $this->errors);
                return -1;
            }

            $existingTopics = array();
            foreach ($existingWebhooks as $webhook) {
                // Les topics sont déjà en format REST grâce à topicFromGraphQL() dans listWebhooks()
                $existingTopics[$webhook->topic] = $webhook;
            }

            // Story 59-4 (AC2, ajout) : détection/signalement de la divergence inverse, sur
            // l'instantané déjà en main — aucun appel Shopify supplémentaire.
            $this->logPhantomWebhookDivergence($existingWebhooks);

            // 2. Comparer avec les topics supportés
            $expectedTopics = $this->getSupportedTopics();
            $missingCount = 0;
            $recreatedCount = 0;
            $failedCount = 0;
            // Story 59-4 (AC4) : compteur SÉPARÉ des refus DURABLES (portée OAuth manquante, topic
            // non éligible) — ne fait PAS basculer la santé en KO (cf. return plus bas), pour ne pas
            // afficher un CRON perpétuellement en panne sur une situation qu'une nouvelle tentative
            // automatique ne résoudra jamais. Reste visible dans $this->output et les logs.
            $durableRefusalCount = 0;

            // Code Review 3 couches (Epic 59, HIGH 1 + MEDIUM 5) : délégué à
            // `attemptCreateMissingWebhook()` — la MÊME logique (télémétrie + disjoncteur d'échecs
            // consécutifs) que le chemin multi-boutiques réel, pour ne plus jamais laisser diverger
            // ce repli de `syncWebhooks()` sur ce point précis.
            $failStreaks = $this->loadWebhookFailStreaks();
            $scopeForLegacyFallback = $this->resolveCanonicalStoreScope();
            $fkStoreForLegacyFallback = $scopeForLegacyFallback['fkStore'];

            foreach ($expectedTopics as $topic) {
                if (!isset($existingTopics[$topic])) {
                    $missingCount++;
                    $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Missing webhook for topic ".$topic.", recreating...", LOG_INFO);

                    // Story 59-9 : repli mono-boutique, lui aussi emprunté UNIQUEMENT par le CRON
                    // périodique (cf. syncWebhooksForStore() ci-dessus pour le chemin multi-boutiques).
                    $attemptOutcome = $this->attemptCreateMissingWebhook($topic, $fkStoreForLegacyFallback, $failStreaks, false);
                    switch ($attemptOutcome) {
                        case self::SYNC_ATTEMPT_RECREATED:
                            $recreatedCount++;
                            break;
                        case self::SYNC_ATTEMPT_DURABLE:
                            $durableRefusalCount++;
                            break;
                        case self::SYNC_ATTEMPT_ALREADY_LIVE:
                        case self::SYNC_ATTEMPT_UNVERIFIABLE:
                            // Ni un échec, ni une recréation à comptabiliser : gagnant de course
                            // concurrent confirmé vivant, ou vérification Shopify indisponible
                            // (fail-safe). Rien à signaler de plus que les logs déjà émis par
                            // createWebhookWithDatabase() lui-même.
                            break;
                        default: // self::SYNC_ATTEMPT_FAILED
                            $failedCount++;
                            break;
                    }

                    // Pause entre les mutations GraphQL pour éviter le rate limiting
                    usleep(200000); // 200ms
                }
            }

            // Code Review 3 couches (Epic 59, MEDIUM 5) : persister le disjoncteur pour le prochain
            // passage du CRON.
            $this->saveWebhookFailStreaks($failStreaks);

            // 3. Résultat
            $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
            $verifiedCount = count($expectedTopics);

            if ($missingCount == 0) {
                $this->log("ShopifyWebhooks::cronCheckWebhookHealth - All webhooks active (".$verifiedCount." verified) in ".$elapsedMs."ms", LOG_DEBUG);
            } else {
                $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Health check completed: ".$recreatedCount."/".$missingCount." missing webhooks recreated, ".$failedCount." failed, ".$durableRefusalCount." durable refusal(s) in ".$elapsedMs."ms", LOG_INFO);
            }

            $this->output = "Webhook health check: ".$verifiedCount." verified, ".$missingCount." missing, ".$recreatedCount." recreated, ".$durableRefusalCount." durable refusal(s) (".$elapsedMs."ms)";

            return ($failedCount > 0) ? -1 : 0;

        } catch (\Throwable $e) {
            $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->log("ShopifyWebhooks::cronCheckWebhookHealth - Exception: ".$e->getMessage()." (".$elapsedMs."ms)", LOG_ERR);
            $this->output = "Webhook health check failed: ".$e->getMessage();
            return -1;
        }
    }
}