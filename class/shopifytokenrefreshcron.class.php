<?php
/**
 * @file        class/shopifytokenrefreshcron.class.php
 * @brief       CRON filet — refresh préventif quotidien des jetons OAuth Shopify expirables
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.4.1
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';

/**
 * Class ShopifyTokenRefreshCron
 *
 * Story 51-1 (T6, AC10, TRANCHÉ VALIDATE Q3) — CRON DÉDIÉ, fréquence QUOTIDIENNE, dont le SEUL
 * rôle est de servir de FILET DE SÉCURITÉ : rattraper les boutiques INACTIVES (aucun appel API
 * entre deux runs) pour empêcher leur refresh_token d'atteindre l'échéance des 90 jours.
 *
 * Le mécanisme PRIMAIRE reste le refresh synchrone avant tout appel API
 * (`ShopifyApi::ensureFreshToken()`, T4, fenêtre 5 minutes) — ce CRON ne fait que réutiliser
 * cette même logique (`ShopifyApi::refreshTokenIfWithinWindow()`) avec la MÊME fenêtre
 * (`ShopifyApi::TOKEN_REFRESH_THRESHOLD_SECONDS`, 300s — cf. FIX HIGH review 51-1 sur
 * CRON_REFRESH_WINDOW_SECONDS ci-dessous, une fenêtre 1h forçait un refresh quotidien de
 * toutes les boutiques migrées), et ne représente donc PAS un doublon : ce CRON ne fait que
 * rattraper les boutiques que le mécanisme synchrone n'a pas pu couvrir faute d'appel API.
 *
 * Invariant Epic 47 : la boutique par défaut n'est JAMAIS désactivée automatiquement, même en
 * cas d'échec de refresh — ShopifyApi se charge déjà de marquer "reconnexion requise" sans
 * bloquer la synchronisation.
 */
class ShopifyTokenRefreshCron
{
    use CronHelperTrait;
    use LoggerTrait;

    /** @var DoliDB Database handler */
    public $db;

    /** @var string CRON output message */
    public $output = '';

    /** @var int Error count */
    public $error = 0;

    /** @var array Error messages */
    public $errors = [];

    /**
     * Fenêtre d'anticipation (secondes) du CRON filet.
     *
     * FIX HIGH (review 51-1) : ALIGNÉE sur `ShopifyApi::TOKEN_REFRESH_THRESHOLD_SECONDS` (300s),
     * PAS une fenêtre plus large. La durée de vie max persistée d'un access_token est de 3480s
     * (3600s Shopify − 120s de marge `ShopifyApi::TOKEN_EXPIRY_MARGIN_SECONDS`) : une fenêtre de
     * 3600s était donc TOUJOURS ≥ l'échéance persistée, ce qui forçait un refresh quotidien de
     * TOUTES les boutiques migrées (actives comme inactives), y compris celles dont le token
     * venait tout juste d'être rafraîchi par le mécanisme synchrone (T4). En réutilisant EXACTEMENT
     * le même seuil que le check synchrone, ce CRON reste un pur filet de sécurité : les boutiques
     * inactives (échéance déjà dépassée, cf. décision de conception T6 dans la story) restent
     * couvertes (le check "dans la fenêtre OU déjà dépassée" de `refreshTokenIfWithinWindow()`
     * matche toujours une échéance passée), tandis que les boutiques actives dont le token vient
     * d'être rafraîchi (échéance loin dans le futur) sont épargnées d'un refresh inutile.
     *
     * @var int
     */
    const CRON_REFRESH_WINDOW_SECONDS = ShopifyApi::TOKEN_REFRESH_THRESHOLD_SECONDS; // 300s (5 min)

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
     * Méthode CRON principale : rafraîchit préventivement les tokens des boutiques dont
     * l'échéance tombe dans la fenêtre (ou est déjà dépassée — boutiques inactives).
     *
     * Non bloquant : une erreur sur UNE boutique ne doit jamais interrompre le traitement des
     * autres (pattern déjà en place dans les CRONs multi-boutiques du module, ex.
     * ImportProductsCron::executeCron()).
     *
     * @param object|null $conf  Configuration Dolibarr (auto-détectée si null)
     * @param object|null $langs Traductions (auto-détectées si null)
     * @return int 0=OK (y compris skip légitime), -1=erreur inattendue globale
     */
    public function executeCron($conf = null, $langs = null)
    {
        global $conf;

        try {
            $this->output = '';
            $this->error = 0;
            $this->errors = [];

            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int) $conf->entity : 1;

            $this->log("ShopifyTokenRefreshCron::executeCron - START entity=" . $entity, LOG_INFO);

            // Déblocage défensif si le CRON était gelé (pattern commun aux CRONs du module)
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/shopifytokenrefreshcron.class.php');

            // Verrou anti double-exécution AU NIVEAU DU RUN COMPLET (en plus du verrou
            // PAR BOUTIQUE déjà géré par ShopifyApi::ensureFreshToken() pour le refresh lui-même).
            if (!$this->acquireCronLock('token_refresh')) {
                $this->log("ShopifyTokenRefreshCron::executeCron - Une autre instance tourne déjà, skip", LOG_INFO);
                $this->output = 'Token refresh CRON skipped: another instance is already running';
                return 0;
            }

            try {
                $startTime = microtime(true);

                $storeService = new StoreService($this->db, $entity);
                $stores = $storeService->getAll(true);

                $refreshedCount = 0;
                $checkedCount = 0;

                if (empty($stores)) {
                    // Rétrocompat mono-boutique : 0 boutique en table → run unique sur entité/constantes
                    // FIX MEDIUM (review 51-1, T10b) : try/catch local, symétrique au chemin
                    // multi-boutiques ci-dessous — une erreur inattendue sur le chemin legacy ne
                    // doit pas non plus interrompre le CRON (comptage checkedCount/refreshedCount
                    // cohérent, sortie explicite au lieu de remonter à l'exception globale).
                    $checkedCount = 1;
                    try {
                        if ($this->refreshStoreIfNeeded($entity, null)) {
                            $refreshedCount++;
                        }
                    } catch (\Throwable $e) {
                        $this->log("ShopifyTokenRefreshCron - Chemin legacy (boutique par défaut) échec (non bloquant): " . $e->getMessage(), LOG_WARNING);
                        $this->output .= "\n[WARN] Boutique par défaut (legacy) : " . $e->getMessage();
                    }
                } else {
                    foreach ($stores as $store) {
                        $storeLabel = !empty($store->shop_domain) ? $store->shop_domain : ('store#' . $store->rowid);
                        try {
                            if (empty($store->access_token) || empty($store->shop_domain)) {
                                // Boutique sans credentials : rien à rafraîchir, pas une erreur
                                continue;
                            }
                            $checkedCount++;
                            if ($this->refreshStoreIfNeeded($entity, $store)) {
                                $refreshedCount++;
                            }
                        } catch (\Throwable $e) {
                            // Une boutique en erreur ne doit JAMAIS interrompre les autres
                            $this->log("ShopifyTokenRefreshCron - Boutique " . $storeLabel . " échec (non bloquant): " . $e->getMessage(), LOG_WARNING);
                            $this->output .= "\n[WARN] Boutique " . $storeLabel . " : " . $e->getMessage();
                        }
                    }
                }

                $elapsedSec = round(microtime(true) - $startTime, 2);
                $this->output = 'Token refresh CRON: ' . $checkedCount . ' boutique(s) vérifiée(s), '
                    . $refreshedCount . ' refresh déclenché(s) (' . $elapsedSec . 's)' . $this->output;
                $this->log("ShopifyTokenRefreshCron::executeCron - END " . $this->output, LOG_INFO);

                return 0;
            } finally {
                // Toujours libérer le verrou (no-op si non détenu par cette session)
                $this->releaseCronLock('token_refresh');
            }
        } catch (\Throwable $e) {
            // Non bloquant : ce CRON est un filet de sécurité, jamais une source de blocage
            $this->error++;
            $this->errors[] = $e->getMessage();
            $this->output = 'Token refresh CRON échoué (non bloquant): ' . $e->getMessage();
            $this->log("ShopifyTokenRefreshCron::executeCron - EXCEPTION (non bloquant): " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }

    /**
     * Rafraîchit le token d'une boutique (ou de la config entité/constantes si $store est null)
     * si son échéance tombe dans la fenêtre du CRON filet.
     *
     * @param  int         $entity Entité Dolibarr
     * @param  object|null $store  Objet boutique (StoreService), null = chemin historique
     * @return bool true si un refresh a été déclenché, false si no-op (rien à faire)
     */
    private function refreshStoreIfNeeded(int $entity, $store): bool
    {
        $shopifyApi = ($store !== null)
            ? ShopifyApi::forStore($this->db, $store, $entity)
            : new ShopifyApi($this->db, $entity);

        // ShopifyApi::__construct() a déjà tenté un refresh synchrone (fenêtre 5 min, T4) — ici
        // on applique la fenêtre PLUS LARGE du CRON filet (1h) pour rattraper les boutiques
        // inactives dont l'échéance n'était pas encore dans la fenêtre synchrone au moment de
        // l'instanciation ci-dessus (fenêtres complémentaires, cf. Dev Notes de la story).
        return $shopifyApi->refreshTokenIfWithinWindow(self::CRON_REFRESH_WINDOW_SECONDS);
    }
}
