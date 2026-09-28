<?php
/**
 * @file        class/offsaleproductsservice.class.php
 * @brief       Traitement de l'existant "produits hors vente déjà poussés sur Shopify" (Story 58-5, AC4)
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
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
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/actionlogger.class.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';

// Include compatibility functions for older Dolibarr versions (doli2shopStoreSyncAllowed)
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

/**
 * Class OffSaleProductsService
 *
 * Story 58-5 (AC4) : la remontée client Xavier Hubier (Europe Loisirs) a mis en évidence que
 * TOUS les produits d'une catégorie synchronisée remontent sur Shopify, même ceux non cochés
 * "En Vente" (tosell=0) — corrigé à la CRÉATION par ImportProducts::shouldSkipInactiveProductCreation()
 * (AC2), mais ce filtre ne rattrape PAS les produits déjà poussés AVANT que l'option soit activée.
 * Cette classe traite l'EXISTANT : elle identifie les produits déjà mappés dont le produit
 * Dolibarr est hors vente, et applique le traitement choisi EXPLICITEMENT par l'administrateur
 * (laisser en brouillon / archiver / supprimer) — jamais automatique.
 *
 * Pattern DiscountRepairService (hotfix 2.4.5, AC4) : deux temps obligatoires (scanBatch()
 * dry-run sans écriture, puis repairBatch() confirm=1 explicite), pagination PAR CLÉ sur
 * `dp.id` (JAMAIS d'OFFSET — le pool de candidats rétrécit à chaque lot traité, un OFFSET
 * sauterait des candidats, piège déjà corrigé en 2.4.4/2.4.5), verrou de réentrance MySQL
 * advisory sur repairBatch() UNIQUEMENT (scanBatch() n'écrit rien, aucun verrou).
 *
 * Candidats (AC4) : lignes `llx_doli2shop_products` avec `fk_product_parent IS NULL` (produit
 * parent/simple — l'archivage/suppression Shopify porte sur le produit ENTIER, jamais une
 * déclinaison seule), `shopifyProductId NOT LIKE 'TEMP_LOCK_%'` (mapping valide), jointes au
 * produit Dolibarr `tosell = 0`, filtrées `entity` + `fk_store` CONDITIONNEL (invariant §14
 * CLAUDE.dolibarr.md : storeId=0 = pas de filtre, comportement legacy/toutes boutiques), et pas
 * déjà marquées traitées (`offsale_action_status IS NULL`).
 *
 * Trois actions (AC4) :
 * - `leave_draft` : AUCUN appel réseau — marque `offsale_action_status = 'left_draft'` (sort le
 *   candidat du pool, idempotent).
 * - `archive` : `ShopifyApi::archiveProduct()` (AC7, mutation `productUpdate` isolée, statut
 *   uniquement) ; succès → `offsale_action_status = 'archived'`.
 * - `delete` : `ShopifyApi::deleteProduct()` (déjà existante, `class/shopifyapi.class.php:1671`,
 *   PAS dupliquée) ; succès → SUPPRESSION IMMÉDIATE de la ligne de mapping (mirroring du
 *   nettoyage déjà pratiqué ailleurs dans le module, ex. `importproducts.class.php:1044-1049`) —
 *   pas de marqueur nécessaire, la ligne n'existe plus. Irréversible : exige une confirmation
 *   DISTINCTE (`$confirmDelete`, cf. `repairBatch()`) en plus du `confirm=1` général de l'appelant
 *   AJAX — AUCUN outil du module n'avait jusqu'ici d'opération destructive, ce mécanisme est
 *   inventé pour cette story (pas de pattern existant à recopier).
 *
 * Multi-boutiques (Epic 47, AC8) : `archive`/`delete` gate `doli2shopStoreSyncAllowed($store)`
 * AVANT tout appel réseau — ⚠️ cette fonction retourne un ARRAY (`['allowed' => bool, ...]`),
 * jamais un booléen brut ; toujours tester `$licenceCheck['allowed'] !== true` (piège déjà payé
 * sur l'epic 59 : `if (!doli2shopStoreSyncAllowed($store))` ne se déclencherait jamais, un
 * tableau non vide étant toujours vrai en PHP). `leave_draft` n'a besoin d'aucun appel réseau,
 * donc aucune gate licence.
 *
 * CRITICAL (Code Review 3-couches post-58-5) : `buildCandidateBaseQuery()` ne filtre `fk_store`
 * QUE si `$storeId > 0` (invariant §14) — avec `$storeId = 0` (chemin le PLUS fréquent : boutique
 * PAR DÉFAUT dans `admin/offsale_products.php`), un même lot peut mélanger des candidats de
 * PLUSIEURS boutiques réelles. `scanBatch()`/`repairBatch()` groupent donc désormais les
 * candidats par `dp.fk_store` RÉEL (colonne ajoutée au SELECT) et instancient un `ShopifyApi` +
 * une gate licence PAR GROUPE — jamais un seul client partagé entre boutiques différentes.
 * Pattern repris fidèlement de `VariantRepairService::repairBatch()`/`processStoreGroup()`, qui
 * portait déjà ce correctif CRITICAL (cf. son docblock). `fk_store = 0` (chemin historique) =
 * `$store = null`, jamais bloqué (invariant rétrocompat).
 *
 * HIGH (Code Review 3-couches post-58-5) : `archive`/`delete` respectent désormais le verrou
 * applicatif `sync_lock` (`llx_doli2shop_products.sync_lock`, même mécanisme que
 * `ImportProducts::isSyncLocked()`, TTL 5 minutes) AVANT tout appel réseau — sans ce garde-fou,
 * un CRON en cours de traitement du MÊME produit (verrou actif) pouvait re-basculer Shopify en
 * DRAFT juste après que ce service l'ait archivé/supprimé (course gagnée par le CRON). Un
 * candidat verrouillé est simplement REPORTÉ au lot suivant (ni marqué, ni compté en erreur).
 */
class OffSaleProductsService
{
    use LoggerTrait;
    use CronHelperTrait;

    /** Taille de lot par défaut si l'appelant (AJAX) n'en précise pas */
    const DEFAULT_BATCH_SIZE = 20;

    /** Borne haute de sécurité sur $limit (paramètre utilisateur via AJAX) */
    const MAX_BATCH_SIZE = 100;

    /** Actions applicables par repairBatch() */
    const ACTION_LEAVE_DRAFT = 'leave_draft';
    const ACTION_ARCHIVE = 'archive';
    const ACTION_DELETE = 'delete';

    /** Valeurs persistées dans llx_doli2shop_products.offsale_action_status (AC6) */
    const STATUS_LEFT_DRAFT = 'left_draft';
    const STATUS_ARCHIVED = 'archived';

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
     * Compte le nombre de produits candidats (hors vente, déjà mappés, non traités), filtré
     * entity + fk_store conditionnel (invariant §14).
     *
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @return int
     */
    public function countCandidates($storeId = 0)
    {
        $sql = $this->buildCandidateBaseQuery('SELECT COUNT(*) as total', $storeId, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('countCandidates() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return (int) (isset($obj->total) ? $obj->total : 0);
    }

    /**
     * Passe DRY-RUN : scanne un lot de candidats SANS RIEN ÉCRIRE. Retourne le détail par
     * produit (référence, libellé, statut Shopify ACTUEL — RELU via l'API, jamais supposé à
     * partir d'un cache local — date de dernière synchronisation) pour affichage d'un aperçu
     * avant confirmation.
     *
     * @param int $afterId Curseur (dernier `dp.id` vu, 0 au premier lot)
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param int $limit   Taille du lot (bornée [1, MAX_BATCH_SIZE])
     * @return array{rows:array,hasMore:bool,nextCursor:int}
     */
    public function scanBatch($afterId, $storeId = 0, $limit = self::DEFAULT_BATCH_SIZE)
    {
        $afterId = max(0, (int) $afterId);
        $limit = max(1, min((int) $limit, self::MAX_BATCH_SIZE));
        $storeId = (int) $storeId;

        $candidates = $this->fetchCandidatesBatch($afterId, $storeId, $limit);

        $rows = array();
        $maxIdSeen = $afterId;

        // CRITICAL multi-boutiques : groupe par fk_store RÉEL (jamais un seul client partagé
        // entre boutiques différentes, cf. docblock de classe) + gate licence PAR GROUPE avant
        // toute lecture réseau (LOW, cohérence avec repairBatch() — scanBatch() n'écrit rien mais
        // reste une lecture réseau qui ne doit pas viser une boutique non autorisée).
        $byStore = array();
        foreach ($candidates as $candidate) {
            $byStore[(int) $candidate->fk_store][] = $candidate;
            $maxIdSeen = max($maxIdSeen, (int) $candidate->id);
        }

        foreach ($byStore as $groupStoreId => $groupCandidates) {
            $groupStore = ((int) $groupStoreId > 0) ? $this->resolveStore((int) $groupStoreId) : null;

            $licenceCheck = $this->checkStoreLicence($groupStore);
            if ($licenceCheck['allowed'] !== true) {
                // Boutique non autorisée : PAS de lecture réseau pour ce groupe — statut inconnu
                // dans l'aperçu (jamais un appel Shopify avec les credentials d'une boutique non
                // licenciée, même en dry-run).
                foreach ($groupCandidates as $candidate) {
                    $rows[] = $this->buildPreviewRow($candidate, null);
                }
                continue;
            }

            // Construit l'API SEULEMENT si au moins un candidat existe pour CE groupe (évite un
            // client Shopify inutile).
            $groupShopifyApi = $this->createShopifyApi($groupStore);
            foreach ($groupCandidates as $candidate) {
                $rows[] = $this->buildPreviewRow($candidate, $groupShopifyApi);
            }
        }

        if (!empty($candidates)) {
            $this->logAction(
                ActionLogger::RESULT_SKIPPED,
                0,
                'Aperçu (dry-run) : ' . count($candidates) . ' produit(s) hors vente analysé(s)'
            );
        }

        return array(
            'rows' => $rows,
            'hasMore' => (count($candidates) === $limit),
            'nextCursor' => $maxIdSeen,
        );
    }

    /**
     * Passe d'APPLICATION (confirm=1 côté appelant) : applique l'action CHOISIE EXPLICITEMENT
     * par l'administrateur à tout un lot de candidats.
     *
     * Verrou de réentrance MySQL advisory PAR ENTITÉ, acquis en tout début de méthode, libéré
     * via `finally` — garanti même si une exception s'échappe du lot. Acquisition NON bloquante :
     * si le verrou est déjà détenu, retourne IMMÉDIATEMENT `lockBusy => true` sans même
     * interroger les candidats.
     *
     * `archive`/`delete` : gate licence par boutique (AC8) AVANT tout appel réseau au client de
     * CE groupe — appliquée PAR GROUPE `fk_store` (cf. `processStoreGroup()`), APRÈS acquisition
     * du verrou et récupération des candidats (storeId=0 peut mélanger plusieurs boutiques
     * réelles, cf. docblock de classe — impossible de gater avant de savoir lesquelles). Un
     * groupe bloqué ne fait échouer QUE ce groupe (`licenseBlocked => true` + candidats du groupe
     * comptés en erreur) ; les autres groupes du même lot continuent d'être traités normalement.
     *

     * `delete` exige `$confirmDelete === true` (double confirmation, AC4) — distinct du
     * `confirm=1` général déjà vérifié par l'appelant AJAX. Sans cela, retourne IMMÉDIATEMENT
     * `confirmRequired => true`, aucun candidat traité.
     *
     * @param int    $afterId       Curseur (dernier `dp.id` vu, 0 au premier lot)
     * @param int    $storeId       Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param int    $limit         Taille du lot (bornée [1, MAX_BATCH_SIZE])
     * @param string $action        self::ACTION_LEAVE_DRAFT / ACTION_ARCHIVE / ACTION_DELETE
     * @param bool   $confirmDelete Confirmation DISTINCTE obligatoire pour ACTION_DELETE uniquement
     * @return array{processed:int,leftDraft:int,archived:int,deleted:int,errors:int,errorDetails:array,hasMore:bool,nextCursor:int,lockBusy:bool,licenseBlocked:bool,confirmRequired:bool}
     */
    public function repairBatch($afterId, $storeId = 0, $limit = self::DEFAULT_BATCH_SIZE, $action = self::ACTION_LEAVE_DRAFT, $confirmDelete = false)
    {
        $afterId = max(0, (int) $afterId);
        $limit = max(1, min((int) $limit, self::MAX_BATCH_SIZE));
        $storeId = (int) $storeId;

        $result = array(
            'processed' => 0,
            'leftDraft' => 0,
            'archived' => 0,
            'deleted' => 0,
            'errors' => 0,
            'errorDetails' => array(),
            'hasMore' => false,
            'nextCursor' => $afterId,
            'lockBusy' => false,
            'licenseBlocked' => false,
            'confirmRequired' => false,
        );

        if (!in_array($action, array(self::ACTION_LEAVE_DRAFT, self::ACTION_ARCHIVE, self::ACTION_DELETE), true)) {
            $result['errorDetails'][] = 'Action inconnue: ' . $action;
            $result['errors']++;
            return $result;
        }

        // AC4 : double confirmation obligatoire pour l'action irréversible. Vérifiée AVANT toute
        // gate licence / verrou / requête candidats — aucun effet de bord possible sans elle.
        if ($action === self::ACTION_DELETE && $confirmDelete !== true) {
            $result['confirmRequired'] = true;
            return $result;
        }

        $needsNetwork = in_array($action, array(self::ACTION_ARCHIVE, self::ACTION_DELETE), true);

        // CRITICAL multi-boutiques : la gate licence NE PEUT PLUS se faire ici, avant même de
        // savoir quelles boutiques sont réellement présentes dans le lot — buildCandidateBaseQuery()
        // ne filtre fk_store QUE si $storeId > 0 (invariant §14), donc storeId=0 (boutique par
        // défaut, chemin le PLUS fréquent) peut mélanger plusieurs boutiques réelles. La gate est
        // désormais appliquée PAR GROUPE, après récupération des candidats (cf. processStoreGroup()),
        // pattern VariantRepairService::repairBatch()/processStoreGroup().
        $lockName = 'offsale_products_' . $this->entity;
        if (!$this->acquireCronLock($lockName)) {
            $result['lockBusy'] = true;
            $this->log('repairBatch() - verrou déjà détenu pour entity=' . $this->entity . ', lot ignoré', LOG_INFO);
            return $result;
        }

        try {
            $candidates = $this->fetchCandidatesBatch($afterId, $storeId, $limit);

            if (empty($candidates)) {
                return $result;
            }

            // Groupe par fk_store RÉEL — CRITICAL multi-boutiques : un ShopifyApi + une gate
            // licence PAR GROUPE, jamais un seul client partagé entre boutiques différentes.
            $byStore = array();
            $maxIdSeen = $afterId;
            foreach ($candidates as $candidate) {
                $byStore[(int) $candidate->fk_store][] = $candidate;
                $maxIdSeen = max($maxIdSeen, (int) $candidate->id);
            }

            foreach ($byStore as $groupStoreId => $groupCandidates) {
                $this->processStoreGroup((int) $groupStoreId, $groupCandidates, $action, $needsNetwork, $result);
            }

            $result['hasMore'] = (count($candidates) === $limit);
            $result['nextCursor'] = $maxIdSeen;
        } finally {
            $this->releaseCronLock($lockName);
        }

        return $result;
    }

    /**
     * Traite tous les candidats d'UNE boutique (ou du groupe fallback fk_store=0) : résout la
     * boutique, applique la gate licence (`archive`/`delete` uniquement), instancie le client
     * Shopify DÉDIÉ, puis traite chaque candidat. Toute résolution impossible (boutique non
     * autorisée) fait skip TOUT le groupe (jamais de repli vers une autre boutique) — pattern
     * `VariantRepairService::processStoreGroup()`.
     *
     * @param int    $groupStoreId   Rowid boutique de ce groupe (0 = fallback historique)
     * @param array  $groupCandidates Candidats de ce groupe
     * @param string $action
     * @param bool   $needsNetwork   true pour ACTION_ARCHIVE/ACTION_DELETE
     * @param array  $result         Rapport cumulé (modifié par référence)
     * @return void
     */
    private function processStoreGroup($groupStoreId, array $groupCandidates, $action, $needsNetwork, array &$result)
    {
        $groupStoreId = (int) $groupStoreId;
        $groupStore = ($groupStoreId > 0) ? $this->resolveStore($groupStoreId) : null;

        if (!$needsNetwork) {
            // ACTION_LEAVE_DRAFT : aucun appel réseau, aucune gate licence nécessaire.
            foreach ($groupCandidates as $candidate) {
                $this->processOneCandidate($candidate, $action, null, $result);
            }
            return;
        }

        // AC8 : ⚠️ doli2shopStoreSyncAllowed() retourne un ARRAY — tester explicitement
        // ['allowed'] !== true, jamais le retour brut (un tableau non vide est toujours "vrai"
        // en PHP, le garde-fou ne se déclencherait jamais).
        $licenceCheck = $this->checkStoreLicence($groupStore);
        if ($licenceCheck['allowed'] !== true) {
            $this->skipGroupDueToLicense($groupCandidates, $groupStoreId, $licenceCheck, $result);
            return;
        }

        $groupShopifyApi = $this->createShopifyApi($groupStore);
        foreach ($groupCandidates as $candidate) {
            $this->processOneCandidate($candidate, $action, $groupShopifyApi, $result);
        }
    }

    /**
     * Marque tous les candidats d'un groupe boutique comme ignorés (licence refusée) — AUCUN
     * appel réseau tenté pour ce groupe. Pattern `VariantRepairService::skipGroup()`.
     *
     * `licenseBlocked` reste exposé au niveau du résultat global pour signaler à l'appelant
     * qu'AU MOINS un groupe a été bloqué par la licence (peut coexister avec des candidats
     * traités avec succès dans d'autres groupes du même lot, cf. AJAX qui ne coupe désormais le
     * lot que si RIEN n'a été traité).
     *
     * @param array $groupCandidates
     * @param int   $groupStoreId
     * @param array $licenceCheck
     * @param array $result
     * @return void
     */
    private function skipGroupDueToLicense(array $groupCandidates, $groupStoreId, array $licenceCheck, array &$result)
    {
        $reason = isset($licenceCheck['reason']) ? $licenceCheck['reason'] : 'unknown';
        $result['licenseBlocked'] = true;

        foreach ($groupCandidates as $candidate) {
            $result['processed']++;
            $result['errors']++;
            $fkProduct = (int) $candidate->fk_product;
            $message = "Produit #$fkProduct : traitement ignoré — boutique #$groupStoreId non autorisée (licence: $reason)";
            $result['errorDetails'][] = $message;
            $this->logAction(ActionLogger::RESULT_SKIPPED, $fkProduct, $message);
        }

        $this->log('repairBatch() - boutique #' . $groupStoreId . ' non autorisée (reason=' . $reason . '), ' . count($groupCandidates) . ' candidat(s) ignoré(s)', LOG_WARNING);
    }

    // =========================================================================
    // Implémentation interne
    // =========================================================================

    /**
     * Traite UN candidat selon l'action choisie. Toute exception est catchée (non-blocage du
     * lot) — pattern DiscountRepairService::processOneCandidate().
     *
     * @param object      $candidate  {id, fk_product, shopifyProductId, ref, label, tms}
     * @param string      $action
     * @param ShopifyApi|null $shopifyApi Null si $action === ACTION_LEAVE_DRAFT (aucun appel réseau)
     * @param array       $result     Rapport cumulé (par référence)
     * @return void
     */
    private function processOneCandidate($candidate, $action, $shopifyApi, array &$result)
    {
        $fkProduct = (int) $candidate->fk_product;

        // HIGH (Code Review 3-couches post-58-5) : respecte le verrou applicatif sync_lock posé
        // par ImportProducts::syncProduct() (même TTL 5 minutes que isSyncLocked()) AVANT tout
        // appel réseau — sans ce garde-fou, un CRON en train de traiter CE produit au même
        // instant pourrait re-basculer Shopify en DRAFT juste après que ce service l'ait
        // archivé/supprimé (course gagnée par le CRON, symptôme même que l'AC5 devait empêcher,
        // via une fenêtre de course au lieu du cycle des 24h). Candidat simplement REPORTÉ au lot
        // suivant (ni marqué, ni compté en erreur/traité) — le CRON libère son verrou en <5 min.
        // ACTION_LEAVE_DRAFT n'appelle jamais Shopify : aucun risque de course, pas de garde ici.
        if ($action !== self::ACTION_LEAVE_DRAFT && $this->isCandidateSyncLocked($candidate)) {
            $this->log("processOneCandidate() - Produit #$fkProduct : synchronisation CRON en cours (sync_lock actif), report au lot suivant", LOG_INFO);
            return;
        }

        $result['processed']++;
        $mappingRowId = (int) $candidate->id;
        $shopifyProductId = (string) $candidate->shopifyProductId;

        try {
            switch ($action) {
                case self::ACTION_LEAVE_DRAFT:
                    $this->markOffsaleStatus($mappingRowId, self::STATUS_LEFT_DRAFT);
                    $result['leftDraft']++;
                    $this->logAction(ActionLogger::RESULT_SUCCESS, $fkProduct, "Produit #$fkProduct : laissé en brouillon (aucun appel réseau)");
                    break;

                case self::ACTION_ARCHIVE:
                    if ($shopifyApi->archiveProduct($shopifyProductId)) {
                        $this->markOffsaleStatus($mappingRowId, self::STATUS_ARCHIVED);
                        $result['archived']++;
                        $this->logAction(ActionLogger::RESULT_SUCCESS, $fkProduct, "Produit #$fkProduct : archivé sur Shopify (ID $shopifyProductId)");
                    } else {
                        $result['errors']++;
                        $message = "Produit #$fkProduct : échec archivage Shopify (ID $shopifyProductId)";
                        $result['errorDetails'][] = $message;
                        $this->logAction(ActionLogger::RESULT_ERROR, $fkProduct, $message);
                    }
                    break;

                case self::ACTION_DELETE:
                    if ($shopifyApi->deleteProduct($shopifyProductId)) {
                        $this->deleteMappingRow($mappingRowId);
                        $result['deleted']++;
                        $this->logAction(ActionLogger::RESULT_SUCCESS, $fkProduct, "Produit #$fkProduct : supprimé sur Shopify et mapping retiré (ID $shopifyProductId)");
                    } else {
                        $result['errors']++;
                        $message = "Produit #$fkProduct : échec suppression Shopify (ID $shopifyProductId)";
                        $result['errorDetails'][] = $message;
                        $this->logAction(ActionLogger::RESULT_ERROR, $fkProduct, $message);
                    }
                    break;
            }
        } catch (\Throwable $e) {
            $result['errors']++;
            $message = "Exception traitement produit #$fkProduct: " . $e->getMessage();
            $result['errorDetails'][] = $message;
            $this->logAction(ActionLogger::RESULT_ERROR, $fkProduct, $message);
            $this->log('processOneCandidate() - ' . $message, LOG_ERR);
        }
    }

    /**
     * Construit une ligne d'aperçu (dry-run) pour UN candidat, sans rien écrire. Le statut
     * Shopify est RELU via l'API (jamais supposé) — AC4.
     *
     * @param object          $candidate  {id, fk_product, shopifyProductId, ref, label, tms}
     * @param ShopifyApi|null $shopifyApi Null = boutique non autorisée (licence) pour ce groupe,
     *                                    AUCUNE lecture réseau tentée — shopifyStatus reste null.
     * @return array
     */
    private function buildPreviewRow($candidate, $shopifyApi)
    {
        $shopifyStatus = null;
        if ($shopifyApi !== null) {
            try {
                $shopifyStatus = $shopifyApi->getProductStatus((string) $candidate->shopifyProductId);
            } catch (\Throwable $e) {
                $this->log('buildPreviewRow() - Erreur lecture statut Shopify (ID ' . $candidate->shopifyProductId . '): ' . $e->getMessage(), LOG_WARNING);
            }
        }

        return array(
            'mappingRowId' => (int) $candidate->id,
            'fkProduct' => (int) $candidate->fk_product,
            'ref' => isset($candidate->ref) ? (string) $candidate->ref : '',
            'label' => isset($candidate->label) ? (string) $candidate->label : '',
            'shopifyProductId' => (string) $candidate->shopifyProductId,
            'shopifyStatus' => $shopifyStatus,
            'lastSync' => isset($candidate->tms) ? $candidate->tms : null,
        );
    }

    /**
     * Vérifie le verrou applicatif `sync_lock` (`llx_doli2shop_products.sync_lock`) AVANT toute
     * action réseau sur ce candidat — HIGH (Code Review 3-couches post-58-5). Reproduit le MÊME
     * TTL 5 minutes qu'`ImportProducts::isSyncLocked()` (`class/importproducts.class.php:1529-1551`)
     * — logique dupliquée plutôt que réutilisée : méthode PRIVÉE de l'autre classe, aucune
     * dépendance croisée introduite entre les deux services.
     *
     * @param object $candidate Doit porter ->sync_lock (ajouté au SELECT de fetchCandidatesBatch())
     * @return bool
     */
    private function isCandidateSyncLocked($candidate)
    {
        $syncLock = isset($candidate->sync_lock) ? $candidate->sync_lock : null;
        if (empty($syncLock)) {
            return false;
        }

        $lockTime = strtotime((string) $syncLock);
        if ($lockTime === false) {
            return false;
        }

        $lockExpireSeconds = 5 * 60; // 5 minutes, même TTL qu'ImportProducts::isSyncLocked()
        return (time() - $lockTime) < $lockExpireSeconds;
    }

    /**
     * Marque la ligne de mapping comme traitée (idempotence — exclue des lots suivants).
     *
     * @param int    $mappingRowId Rowid `llx_doli2shop_products.id`
     * @param string $status       self::STATUS_LEFT_DRAFT / STATUS_ARCHIVED
     * @return void
     */
    private function markOffsaleStatus($mappingRowId, $status)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " SET offsale_action_status = '" . $this->db->escape($status) . "'";
        $sql .= " WHERE id = " . (int) $mappingRowId;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("markOffsaleStatus() - Erreur marquage mapping (id=#$mappingRowId): " . $this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Supprime la ligne de mapping après suppression réussie côté Shopify (ACTION_DELETE) —
     * aucun marqueur nécessaire, la ligne n'existe plus (AC6). Mirroring du nettoyage déjà
     * pratiqué ailleurs dans le module pour un produit supprimé côté Shopify
     * (`importproducts.class.php:1044-1049`, `373-378`).
     *
     * @param int $mappingRowId Rowid `llx_doli2shop_products.id`
     * @return void
     */
    private function deleteMappingRow($mappingRowId)
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE id = " . (int) $mappingRowId;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("deleteMappingRow() - Erreur suppression mapping (id=#$mappingRowId): " . $this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Résout l'objet boutique (StoreService::fetch()) à partir d'un rowid, ou null si
     * $storeId <= 0 (chemin fallback/toutes boutiques — jamais bloqué, invariant rétrocompat).
     *
     * @param int $storeId
     * @return object|null
     */
    protected function resolveStore($storeId)
    {
        if ((int) $storeId <= 0) {
            return null;
        }
        return $this->createStoreService()->fetch((int) $storeId);
    }

    /**
     * Factory protégée StoreService — mockable en test unitaire.
     *
     * @return StoreService
     */
    protected function createStoreService()
    {
        return new StoreService($this->db, $this->entity);
    }

    /**
     * Factory protégée ShopifyApi::forStore() — mockable en test unitaire (pattern
     * VariantRepairService::createProductImporter()).
     *
     * @param object|null $store
     * @return ShopifyApi
     */
    protected function createShopifyApi($store)
    {
        return ShopifyApi::forStore($this->db, $store, $this->entity);
    }

    /**
     * Gate licence par boutique — wrapper mockable autour de la fonction globale
     * `doli2shopStoreSyncAllowed()` (pattern `variantrepairservice.class.php:570`).
     *
     * @param object|null $store
     * @return array{allowed:bool,status:string,reason:string}
     */
    protected function checkStoreLicence($store)
    {
        return doli2shopStoreSyncAllowed($store);
    }

    /**
     * Récupère un lot paginé de candidats (pagination PAR CLÉ sur `dp.id`), filtré entity +
     * fk_store conditionnel (invariant §14) + `offsale_action_status IS NULL` (exclut les
     * candidats déjà traités).
     *
     * @param int $afterId Curseur : ne renvoie que les candidats avec `dp.id` STRICTEMENT supérieur
     * @param int $storeId
     * @param int $limit
     * @return object[] Lignes {id, fk_product, shopifyProductId, ref, label, tms}, triées par id ASC
     */
    protected function fetchCandidatesBatch($afterId, $storeId, $limit)
    {
        // dp.fk_store (CRITICAL multi-boutiques : groupage par boutique RÉELLE, cf. docblock de
        // classe) + dp.sync_lock (HIGH : garde-fou anti-course avec le CRON, isCandidateSyncLocked())
        // ajoutés au SELECT — Code Review 3-couches post-58-5.
        $sql = $this->buildCandidateBaseQuery('SELECT dp.id, dp.fk_product, dp.shopifyProductId, dp.fk_store, dp.sync_lock, dp.tms, p.ref, p.label', $storeId, $afterId);
        $sql .= " ORDER BY dp.id ASC";
        $sql .= $this->db->plimit((int) $limit, 0);

        $candidates = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $candidates[] = $obj;
            }
            $this->db->free($resql);
        } else {
            $this->log('fetchCandidatesBatch() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
        }
        return $candidates;
    }

    /**
     * Requête de base commune à countCandidates() et fetchCandidatesBatch().
     *
     * @param string $selectClause Ex. "SELECT COUNT(*) as total" ou "SELECT dp.id, ..."
     * @param int    $storeId
     * @param int    $afterId      0 = pas de filtre curseur (utilisé pour le comptage)
     * @return string
     */
    private function buildCandidateBaseQuery($selectClause, $storeId, $afterId)
    {
        $sql = $selectClause . " FROM " . MAIN_DB_PREFIX . "doli2shop_products dp";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = dp.fk_product";
        $sql .= " WHERE dp.entity = " . (int) $this->entity;
        // Produit parent/simple uniquement — l'archivage/suppression Shopify porte sur le
        // produit ENTIER, jamais sur une déclinaison seule (AC4, hors périmètre 2042-2139).
        $sql .= " AND dp.fk_product_parent IS NULL";
        // Mapping valide uniquement (jamais un TEMP_LOCK_* en cours de pose) — underscores
        // échappés (wildcard LIKE), même si les IDs Shopify sont purement numériques en pratique.
        $sql .= " AND dp.shopifyProductId NOT LIKE 'TEMP\\_LOCK\\_%'";
        $sql .= " AND p.tosell = 0";
        $sql .= " AND dp.offsale_action_status IS NULL";
        // MEDIUM (Code Review 3-couches post-58-5) : un produit PARENT hors vente peut porter des
        // déclinaisons ENCORE en vente (tosell=1) — le candidat n'est sélectionné aujourd'hui que
        // sur le tosell du PARENT. Archiver/supprimer le produit Shopify entier retirerait alors
        // des variantes que Dolibarr considère toujours vendables. Tranché : EXCLURE ces parents
        // des candidats (jamais se contenter de les signaler dans l'aperçu) — l'action de cet
        // outil porte sur le produit ENTIER (AC4), elle ne peut pas être partielle par variante.
        $sql .= " AND NOT EXISTS (";
        $sql .= "     SELECT 1 FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac";
        $sql .= "     INNER JOIN " . MAIN_DB_PREFIX . "product pchild ON pchild.rowid = pac.fk_product_child";
        $sql .= "     WHERE pac.fk_product_parent = p.rowid AND pchild.tosell = 1";
        $sql .= " )";
        if ((int) $storeId > 0) {
            $sql .= " AND dp.fk_store = " . (int) $storeId;
        }
        if ((int) $afterId > 0) {
            $sql .= " AND dp.id > " . (int) $afterId;
        }

        return $sql;
    }

    /**
     * Journalise le résultat d'un produit (ActionLogger — non-bloquant par construction).
     *
     * @param string $result
     * @param int    $fkProduct
     * @param string $message
     * @return void
     */
    private function logAction($result, $fkProduct, $message)
    {
        ActionLogger::log($this->db, $this->entity, ActionLogger::TYPE_OFFSALE_PRODUCTS, 'product', $fkProduct, $result, $message);
    }
}
