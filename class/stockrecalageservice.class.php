<?php
/**
 * @file        class/stockrecalageservice.class.php
 * @brief       Recalage initial en masse du stock Dolibarr → Shopify (Story 52-2)
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.4.2
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/syncflowpolicy.class.php';
require_once dirname(__FILE__) . '/shopifystocktrigger.class.php';
require_once dirname(__FILE__) . '/actionlogger.class.php';

// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}


/**
 * Class StockRecalageService
 *
 * Recalage initial en masse (action admin EXPLICITE, JAMAIS automatique à l'install/upgrade) :
 * pousse le stock Dolibarr absolu de TOUS les produits mappés vers Shopify, une fois, par lots
 * paginés (taille bornée à ShopifyStockTrigger::MAX_API_RESOLUTIONS_PER_REQUEST, cf. décision
 * story 52-2). Réutilise STRICTEMENT ShopifyStockTrigger::updateShopifyStock() par produit —
 * AUCUNE duplication de la logique de push (multi-boutiques, plancher 0, gate licence,
 * write-through, plafond API : tout est déjà dedans, cf. Story 52-1).
 *
 * Pose SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS en garde de cohérence (try/finally + nesting guard,
 * pattern shopifyordermanager.class.php:5204-5207/5315-5319) pendant le traitement du lot — ce
 * flag NE GATE PAS le push : updateShopifyStock() ne lit AUCUN flag anti-boucle (seul
 * runTrigger() le fait), il neutralise seulement d'éventuels triggers Dolibarr parasites
 * déclenchés en cascade pendant le batch (le recalage DOIT pousser, c'est son unique but).
 */
class StockRecalageService
{
    use LoggerTrait;

    /**
     * Taille de lot alignée sur ShopifyStockTrigger::MAX_API_RESOLUTIONS_PER_REQUEST (20) :
     * chaque lot AJAX est une requête HTTP neuve (compteur statique déjà à 0 en début de lot),
     * on ne bute donc jamais sur le plafond de résolutions API DANS un lot, et on garde le cache
     * gate-licence process-scoped chaud entre produits d'un même lot (pas de resetPushRegistry()
     * intra-lot, qui viderait aussi ce cache).
     */
    const BATCH_SIZE = 20;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var int Entité Dolibarr courante */
    private $entity;

    /**
     * @param DoliDB   $db     Gestionnaire base de données
     * @param int|null $entity Entité Dolibarr (null = entité courante $conf->entity)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
    }

    /**
     * Indique si le recalage peut s'exécuter (AC #4) : direction de synchronisation des stocks
     * configurée ∈ {dolibarr_to_shopify, both}.
     *
     * Story 53-3 (site 6) : garde SyncFlowPolicy (flux stock, d2s), désormais per-store — signature
     * rétrocompatible ($storeId=0 = portée globale, comportement identique à avant cette story).
     *
     * @param  int $storeId Rowid boutique (0 = portée globale, comportement historique)
     * @return bool
     */
    public function isEnabled(int $storeId = 0)
    {
        return $this->createSyncFlowPolicy()->isAllowed(SyncFlowPolicy::FLOW_STOCK, 'dolibarr_to_shopify', $storeId);
    }

    /**
     * Compte le nombre de produits mappés concernés par le recalage (DISTINCT fk_product),
     * filtré entity + fk_store conditionnel (invariant CLAUDE.dolibarr.md §14 : JAMAIS de filtre
     * fk_store si storeId <= 0).
     *
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @return int
     */
    public function countMappedProducts($storeId = 0)
    {
        $sql = "SELECT COUNT(DISTINCT fk_product) as total FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE entity = " . (int) $this->entity;
        if ((int) $storeId > 0) {
            $sql .= " AND fk_store = " . (int) $storeId;
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('countMappedProducts() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return (int) (isset($obj->total) ? $obj->total : 0);
    }

    /**
     * Traite un lot de produits mappés (curseur = offset paginé) : pousse le stock absolu de
     * chaque produit vers Shopify via ShopifyStockTrigger::updateShopifyStock(), journalise
     * chaque résultat (ActionLogger, AC #5), sous garde SYNC_IN_PROGRESS (try/finally + nesting
     * guard, AC #7). JAMAIS d'exception non catchée n'interrompt le lot : un produit en échec ne
     * bloque jamais les suivants.
     *
     * Sémantique des compteurs (AC #5) : `updateShopifyStock()` n'expose pas de distinction
     * externe entre « poussé avec succès » et « skip interne légitime » (non mappé, boutique non
     * licenciée, aucune boutique éligible...) — l'exposer nécessiterait de dupliquer sa logique
     * interne, explicitement interdit (Dev Notes story 52-2). Un résultat 0 SANS erreur est donc
     * compté dans `pushed` (couvre le push réel ET le skip interne bénin, les deux étant des
     * issues "sans erreur"). Le bucket `skipped` couvre le no-op MACRO (direction désactivée :
     * lot entier sauté avant toute tentative).
     *
     * @param int $offset  Décalage de pagination (curseur)
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @return array{processed:int,pushed:int,errors:int,skipped:int,hasMore:bool,nextOffset:int,errorDetails:array}
     */
    public function processBatch($offset, $storeId = 0)
    {
        $offset = max(0, (int) $offset);
        $storeId = (int) $storeId;

        $result = array(
            'processed' => 0,
            'pushed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'hasMore' => false,
            'nextOffset' => $offset,
            'errorDetails' => array(),
        );

        // AC #4 : ne pousse que si la direction configurée l'autorise. Batch entier = no-op
        // (comptabilisé "skipped", jamais d'appel API, jamais bloquant).
        // Story 53-3 : transmet SON $storeId (auparavant perdu — isEnabled() ignorait le scoping
        // per-store réel du lot).
        if (!$this->isEnabled($storeId)) {
            $this->log('processBatch() - Synchronisation stocks Dolibarr → Shopify désactivée (entity=' . $this->entity . '), no-op', LOG_INFO);
            $totalProducts = $this->countMappedProducts($storeId);
            $result['skipped'] = $totalProducts;
            // LOW-2 (review 52-2) : persister un état cohérent même désactivé (offset 0), pour que
            // l'UI « dernier recalage » ne reste pas trompeusement vide.
            $this->persistBatchProgress($offset, 0, 0);
            return $result;
        }

        $productIds = $this->fetchProductIdsBatch($offset, $storeId, self::BATCH_SIZE);

        if (empty($productIds)) {
            // LOW-2 : 0 produit à cet offset (catalogue vide à offset 0, ou fin de passe) → état cohérent.
            $this->persistBatchProgress($offset, 0, 0);
            return $result;
        }

        // Garde de cohérence (nesting guard, try/finally) — cf. shopifyordermanager.class.php
        // :5204-5207/:5315-5319. Ce flag NE GATE PAS updateShopifyStock() (qui ne lit aucun
        // flag) : il neutralise seulement d'éventuels triggers Dolibarr parasites déclenchés en
        // cascade pendant le lot (ex. recalcul de stock virtuel déclenchant un STOCK_MOVEMENT).
        global $conf;
        $flagAlreadySet = (bool) getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS');
        if (!$flagAlreadySet) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;
        }

        try {
            foreach ($productIds as $dolibarrProductId) {
                $result['processed']++;
                try {
                    $trigger = $this->createStockTrigger();
                    $pushedStock = null;
                    // HIGH-1 (review 52-2) : bypass du plafond de résolutions API. Le recalage est un
                    // batch admin délibéré (borné BATCH_SIZE + set_time_limit(0) + rate-limit client API
                    // + write-through) qui DOIT résoudre tous les produits × toutes les boutiques ; sans
                    // bypass, le plafond partagé par requête (20) provoquerait des skips silencieux
                    // comptés "poussés" dès ~10 produits en multi-boutiques → recalage incomplet.
                    $pushResult = $trigger->updateShopifyStock($dolibarrProductId, $pushedStock, true);

                    if ($pushResult === 0 && empty($trigger->errors)) {
                        $result['pushed']++;
                        $message = 'Recalage stock produit ID ' . $dolibarrProductId . ' : valeur poussée='
                            . ($pushedStock !== null ? $pushedStock : 'n/a');
                        $this->logAction(ActionLogger::RESULT_SUCCESS, $dolibarrProductId, $message);
                    } else {
                        $result['errors']++;
                        $errMsg = 'Recalage stock produit ID ' . $dolibarrProductId . ' échoué : '
                            . (!empty($trigger->errors) ? implode('; ', $trigger->errors) : 'erreur non détaillée');
                        $result['errorDetails'][] = $errMsg;
                        $this->logAction(ActionLogger::RESULT_ERROR, $dolibarrProductId, $errMsg);
                    }
                } catch (\Throwable $e) {
                    // AC #7 : non-blocage — un produit en échec (exception imprévue, ex. panne
                    // réseau Shopify) ne doit JAMAIS interrompre le traitement des produits
                    // suivants du même lot.
                    $result['errors']++;
                    $errMsg = 'Exception recalage produit ID ' . $dolibarrProductId . ': ' . $e->getMessage();
                    $result['errorDetails'][] = $errMsg;
                    $this->logAction(ActionLogger::RESULT_ERROR, $dolibarrProductId, $errMsg);
                    $this->log('processBatch() - ' . $errMsg, LOG_ERR);
                }
            }
        } finally {
            // Toujours retirer le flag anti-boucle si posé par CET appel (nesting guard) : ne
            // JAMAIS retirer un flag posé par un appelant englobant.
            if (!$flagAlreadySet) {
                unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);
            }
        }

        $totalProducts = $this->countMappedProducts($storeId);
        $nextOffset = $offset + count($productIds);
        $result['hasMore'] = ($nextOffset < $totalProducts);
        $result['nextOffset'] = $nextOffset;

        // MEDIUM-1 (review 52-2) : persister l'état « dernier recalage » côté SERVEUR à la fin de
        // CHAQUE lot (cumul), plutôt que de dépendre d'un unique appel `finish` alimenté par des
        // totaux du navigateur (perdus si l'onglet se ferme en cours de passe). Un onglet fermé
        // laisse donc l'état du dernier lot réellement traité, pas un état obsolète.
        $this->persistBatchProgress($offset, $result['pushed'], $result['errors']);

        return $result;
    }

    /**
     * Persiste l'état « dernier recalage » (Task 2, AC #6) : horodatage + compteurs cumulés,
     * agrégés par l'appelant (AJAX) sur TOUS les lots de la passe complète. Idempotent : chaque
     * appel ÉCRASE l'état précédent (jamais d'incrément cumulatif inter-passes, AC #8).
     *
     * @param int $pushedTotal Nombre total de produits poussés avec succès (toute la passe)
     * @param int $errorsTotal Nombre total d'erreurs (toute la passe)
     * @return void
     */
    public function persistLastRecalageState($pushedTotal, $errorsTotal)
    {
        $now = function_exists('dol_now') ? dol_now() : time();
        $dateSql = method_exists($this->db, 'idate') ? $this->db->idate($now) : date('Y-m-d H:i:s', $now);

        $this->setConst('DOLI2SHOP_LAST_STOCK_RECALAGE', $dateSql);
        $this->setConst('DOLI2SHOP_LAST_STOCK_RECALAGE_COUNT', (int) $pushedTotal);
        $this->setConst('DOLI2SHOP_LAST_STOCK_RECALAGE_ERRORS', (int) $errorsTotal);

        $this->log(
            'persistLastRecalageState() - Recalage terminé (entity=' . $this->entity . '): '
            . (int) $pushedTotal . ' poussés, ' . (int) $errorsTotal . ' erreurs',
            LOG_INFO
        );
    }

    /**
     * MEDIUM-1 (review 52-2) : persiste l'état « dernier recalage » côté SERVEUR à la fin de chaque
     * lot, en cumulant sur la passe en cours — au lot offset 0 on (ré)initialise, aux lots suivants
     * on ajoute les compteurs du lot courant à ceux déjà persistés. Ainsi l'observabilité (AC #6) ne
     * dépend plus d'un unique appel `finish` alimenté par le navigateur (perdu si l'onglet se ferme).
     * L'horodatage est rafraîchi à chaque lot (= dernière activité de recalage).
     *
     * @param int $offset       Offset du lot courant (0 = début de passe → réinitialise le cumul)
     * @param int $batchPushed  Produits poussés dans CE lot
     * @param int $batchErrors  Erreurs dans CE lot
     * @return void
     */
    private function persistBatchProgress($offset, $batchPushed, $batchErrors)
    {
        $now = function_exists('dol_now') ? dol_now() : time();
        $dateSql = method_exists($this->db, 'idate') ? $this->db->idate($now) : date('Y-m-d H:i:s', $now);

        if ((int) $offset === 0) {
            $count = (int) $batchPushed;
            $errors = (int) $batchErrors;
        } else {
            $count = (function_exists('getDolGlobalInt') ? getDolGlobalInt('DOLI2SHOP_LAST_STOCK_RECALAGE_COUNT') : 0) + (int) $batchPushed;
            $errors = (function_exists('getDolGlobalInt') ? getDolGlobalInt('DOLI2SHOP_LAST_STOCK_RECALAGE_ERRORS') : 0) + (int) $batchErrors;
        }

        $this->setConst('DOLI2SHOP_LAST_STOCK_RECALAGE', $dateSql);
        $this->setConst('DOLI2SHOP_LAST_STOCK_RECALAGE_COUNT', $count);
        $this->setConst('DOLI2SHOP_LAST_STOCK_RECALAGE_ERRORS', $errors);
    }

    // =========================================================================
    // Collaborateurs mockables (onlyMethods() — isolation des tests unitaires)
    // =========================================================================

    /**
     * Factory protégée SyncFlowPolicy (Story 53-3, site 6 — remplace buildSyncUtils(), pattern
     * createSyncUtils() de api_doli2shop.class.php:335).
     *
     * @return SyncFlowPolicy
     */
    protected function createSyncFlowPolicy()
    {
        return new SyncFlowPolicy($this->db, $this->entity);
    }

    /**
     * @return ShopifyStockTrigger
     */
    protected function createStockTrigger()
    {
        return new ShopifyStockTrigger($this->db, $this->entity);
    }

    /**
     * Récupère un lot paginé de fk_product mappés (DISTINCT), filtré entity + fk_store
     * conditionnel (invariant §14).
     *
     * @param int $offset
     * @param int $storeId
     * @param int $limit
     * @return int[] Liste de fk_product (DISTINCT, paginée, triée)
     */
    protected function fetchProductIdsBatch($offset, $storeId, $limit)
    {
        $sql = "SELECT DISTINCT fk_product FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE entity = " . (int) $this->entity;
        if ((int) $storeId > 0) {
            $sql .= " AND fk_store = " . (int) $storeId;
        }
        $sql .= " ORDER BY fk_product";
        $sql .= $this->db->plimit((int) $limit, (int) $offset);

        $productIds = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $productIds[] = (int) $obj->fk_product;
            }
            $this->db->free($resql);
        } else {
            $this->log('fetchProductIdsBatch() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
        }
        return $productIds;
    }

    /**
     * Wrapper mockable autour de dolibarr_set_const() (fonction globale non mockable directement
     * en test unitaire sans espace de noms).
     *
     * @param string     $name  Nom de la constante
     * @param string|int $value Valeur à persister
     * @return int Résultat de dolibarr_set_const() (>0 = OK)
     */
    protected function setConst($name, $value)
    {
        return dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $this->entity);
    }

    /**
     * Journalise le résultat d'un produit (ActionLogger — non-bloquant par construction).
     *
     * @param string $result
     * @param int    $dolibarrProductId
     * @param string $message
     * @return void
     */
    private function logAction($result, $dolibarrProductId, $message)
    {
        ActionLogger::log($this->db, $this->entity, ActionLogger::TYPE_STOCK_RECALAGE, 'product', $dolibarrProductId, $result, $message);
    }
}
