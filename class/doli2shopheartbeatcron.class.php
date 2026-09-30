<?php
/**
 * @file        class/doli2shopheartbeatcron.class.php
 * @brief       CRON heartbeat — remontée autonome des métriques d'installation au website
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.3.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/supportmanager.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';

/**
 * Class Doli2ShopHeartbeatCron
 *
 * Story 46.2 — Remontée autonome des métriques (version module, version Dolibarr,
 * version PHP, domaine boutique) vers le website, afin d'alimenter le dashboard de
 * monitoring même quand l'administrateur n'ouvre jamais la page Licence/Diagnostic.
 *
 * Réutilise SupportManager::validateDualChannel() qui POST déjà ces métriques vers
 * l'API billing du website (action validate-dolibarr → trackAccess). Aucun client HTTP
 * dupliqué. Télémétrie active tant qu'une licence est configurée.
 *
 * Fréquence recommandée : quotidienne.
 */
class Doli2ShopHeartbeatCron
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
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Méthode CRON principale : remonte les métriques d'installation au website.
     *
     * Non bloquant : toute erreur réseau est loggée (LOG_WARNING) sans faire échouer
     * le CRON ni boucler. La prochaine occurrence réessaiera.
     *
     * @param object|null $conf  Configuration Dolibarr (auto-détectée si null)
     * @param object|null $langs Traductions (auto-détectées si null)
     * @return int 0=OK (y compris skip légitime), <0=erreur inattendue
     */
    public function run_heartbeat($conf = null, $langs = null)
    {
        global $conf;

        try {
            $this->output = '';
            $this->error = 0;
            $this->errors = [];

            // Remontée pour l'entité du contexte CRON. Comme les autres CRONs du module
            // (ShopifyAlertCron…), la config est lue via getDolGlobalString selon conf->entity :
            // le scheduler Dolibarr exécute le job par entité où il est actif.
            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int) $conf->entity : 1;
            $startTime = microtime(true);

            $this->log("Doli2ShopHeartbeatCron::run_heartbeat - START entity=" . $entity, LOG_INFO);

            // Timeout protection (l'appel billing a déjà ses propres timeouts cURL).
            // Ne JAMAIS réduire une limite illimitée (0) : ne relever que si bornée et trop basse.
            $oldTimeLimit = (int) ini_get('max_execution_time');
            if ($oldTimeLimit > 0 && $oldTimeLimit < 60) {
                @set_time_limit(60);
            }

            // Déblocage défensif si le CRON était gelé
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/doli2shopheartbeatcron.class.php');

            $supportManager = new SupportManager($this->db);

            // Story 51-1 (T7, TRANCHÉ VALIDATE Q2) : suivi de progression de la migration des
            // jetons OAuth expirables — compteur des boutiques dont refresh_token reste NULL
            // (non encore migrées, échéance Shopify 01/01/2027). Remonté au website via le même
            // canal heartbeat existant (non bloquant : un échec de comptage n'empêche pas le
            // heartbeat de continuer).
            $storesPendingTokenMigration = 0;
            $storesTotal = 0;
            try {
                $storeService = new StoreService($this->db, $entity);
                $allStores = $storeService->getAll(true);
                $storesTotal = count($allStores);
                foreach ($allStores as $storeRow) {
                    if (!empty($storeRow->access_token) && empty($storeRow->refresh_token)) {
                        $storesPendingTokenMigration++;
                    }
                }

                // FIX MEDIUM (review 51-1) : install mono-boutique (chemin constantes, invariant
                // Epic 47 — aucune ligne `llx_doli2shop_stores` seedée) était totalement ignorée
                // du comptage : storesTotal restait à 0 alors que la boutique par défaut existe
                // bel et bien via DOLI2SHOP_ACCESS_TOKEN. Miroir de la logique par-boutique
                // ci-dessus, mais sur les constantes globales (getDolGlobalString, pas de requête
                // supplémentaire).
                if (empty($allStores)) {
                    $legacyAccessToken = getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN', '');
                    if ($legacyAccessToken !== '') {
                        $storesTotal = 1;
                        $legacyTokenExpiresAt = getDolGlobalString('DOLI2SHOP_TOKEN_EXPIRES_AT', '');
                        if ($legacyTokenExpiresAt === '') {
                            $storesPendingTokenMigration = 1;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->log("Doli2ShopHeartbeatCron - échec comptage migration jetons (non bloquant): " . $e->getMessage(), LOG_WARNING);
            }

            // validateDualChannel() POST les métriques (module/Dolibarr/PHP + domaine) au website.
            // additionalData : marquer l'origine pour la traçabilité côté logs website.
            $result = $supportManager->validateDualChannel(array(
                'heartbeat' => 1,
                'stores_total' => $storesTotal,
                'stores_pending_token_migration' => $storesPendingTokenMigration,
            ));

            $status = isset($result['status']) ? $result['status'] : 'unknown';

            if ($status === 'no_config') {
                // Aucune licence/boutique configurée : rien à remonter, skip propre.
                $this->output = 'Heartbeat: aucune licence configurée, remontée ignorée';
                $this->log("Doli2ShopHeartbeatCron::run_heartbeat - SKIP (no_config)", LOG_INFO);
                @set_time_limit($oldTimeLimit);
                return 0;
            }

            $elapsedSec = round(microtime(true) - $startTime, 2);
            $validStr = (!empty($result['valid'])) ? 'valid' : 'invalid';
            $this->output = 'Heartbeat: métriques remontées (status=' . $status . ', ' . $validStr . ', ' . $elapsedSec . 's)';
            $this->log("Doli2ShopHeartbeatCron::run_heartbeat - END " . $this->output, LOG_INFO);

            @set_time_limit($oldTimeLimit);
            return 0;

        } catch (\Throwable $e) {
            // Non bloquant : on logge en warning et on renvoie 0 pour ne pas geler le CRON
            // sur une simple indisponibilité réseau du website.
            $this->log("Doli2ShopHeartbeatCron::run_heartbeat - remontée échouée (non bloquant): " . $e->getMessage(), LOG_WARNING);
            $this->output = 'Heartbeat: remontée indisponible (' . $e->getMessage() . ')';
            return 0;
        }
    }
}
