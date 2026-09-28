<?php
/**
 * @file        class/discountrepairservice.class.php
 * @brief       Recorrection batch des remises en pourcentage sous-évaluées à l'import (Hotfix 2.4.5, AC4)
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.4.5
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

/**
 * Class DiscountRepairService
 *
 * Recorrige les commandes Shopify déjà importées AVANT le fix v2.2.9 (`ShopifyOrderManager::
 * processDiscounts()` l.2850-2855), dont la ligne de remise globale en POURCENTAGE est
 * sous-évaluée d'un facteur (1 + TVA) — cf. remontée #1025 Nicolas Graillon, hotfix 2.4.5.
 *
 * Périmètre STRICTEMENT limité au chemin CONFIRMÉ par le Validate (2026-07-27) : remise
 * ACROSS/pourcentage simple. AUCUN appel réseau Shopify — la seule donnée qui identifie sans
 * ambiguïté le pourcentage d'origine est le texte déjà posé sur la ligne au moment de l'import
 * (`desc` = "Remise de X% (Code: ...)"), cf. `ShopifyOrderManager::processDiscounts()`
 * l.2781/2788. Toute ligne de remise ne correspondant pas à ce motif (montant fixe, EACH,
 * SHIPPING_LINE, remise déjà retouchée manuellement) est classée `manual_review`, JAMAIS
 * corrigée automatiquement (garde-fou conservateur, Validate §5).
 *
 * Garde-fou piste d'audit (Validate §5) : seules les commandes en BROUILLON
 * (`Commande::STATUS_DRAFT`) sont corrigées automatiquement. Une commande validée/facturée est
 * seulement LISTÉE avec le delta HT à régulariser via avoir/note de crédit — jamais éditée.
 *
 * Idempotent : `llx_doli2shop_orders.discount_repair_status` marque chaque commande déjà
 * traitée (`corrected` / `not_affected` / `manual_required` / `manual_review`) — exclue des
 * lots suivants (pattern `VariantRepairService::variant_repair_status`, hotfix 2.4.4).
 * Pagination PAR CLÉ sur `do.id` (jamais d'OFFSET numérique) : le pool rétrécit au fil des
 * corrections, un offset croissant sauterait des blocs entiers de candidats jamais traités
 * (CRITICAL identifié en review 3-couches 2.4.4 2/2, appliqué ici préventivement).
 *
 * Verrou de réentrance (Story 58-2, AC1) : `repairBatch()` (la seule passe qui ÉCRIT) acquiert un
 * verrou advisory MySQL par ENTITÉ via `CronHelperTrait::acquireCronLock()`/`releaseCronLock()`
 * (même mécanisme que `StockPushQueueCron`, pattern `class/stockpushqueuecron.class.php:81-116`),
 * libéré dans un `finally` — garanti même si une exception s'échappe pendant le traitement du
 * lot. `scanBatch()` n'écrit rien : AUCUN verrou n'y est pris. Acquisition non bloquante
 * (timeout=0) : si un autre traitement tourne déjà pour la même entité, `repairBatch()` retourne
 * immédiatement `lockBusy => true` (0 commande traitée), sans même interroger les candidats.
 *
 * Traçabilité de l'hypothèse (Story 58-2, AC2/AC3 — REFORMULÉ par le Validate 2026-07-27) :
 * `classifyDiscountLine()` recalcule le sous-total à partir des mappings produit COURANTS
 * (`getModuleConfig()`) ; aucune table ne conserve d'instantané de la config au moment de
 * l'import, donc AUCUNE détection de dérive de configuration n'est tentée (ce serait deviner,
 * cf. Résultat Validate §"AC2 n'est pas implémentable telle quelle"). À la place : CHAQUE
 * classification effective (y compris `manual_review`, en scan comme en application) journalise
 * le sous-total de référence retenu et les `tip_product_id`/`shipping_product_id` EXCLUS de ce
 * sous-total (cf. `buildClassificationLogSuffix()`), pour qu'une correction contestée puisse être
 * rejouée et expliquée a posteriori si ces mappings ont changé depuis l'import.
 */
class DiscountRepairService
{
    use LoggerTrait;
    use CronHelperTrait;

    /** Taille de lot par défaut si l'appelant (AJAX) n'en précise pas */
    const DEFAULT_BATCH_SIZE = 20;

    /** Borne haute de sécurité sur $limit (paramètre utilisateur via AJAX) */
    const MAX_BATCH_SIZE = 100;

    /** Statuts persistés dans llx_doli2shop_orders.discount_repair_status */
    const STATUS_CORRECTED = 'corrected';
    const STATUS_NOT_AFFECTED = 'not_affected';
    const STATUS_MANUAL_REQUIRED = 'manual_required';
    const STATUS_MANUAL_REVIEW = 'manual_review';

    /** Classifications retournées par classifyDiscountLine() (pure, sans DB) */
    const CLASSIFICATION_ALREADY_CORRECT = 'already_correct';
    const CLASSIFICATION_CORRECTABLE_PRE_FIX = 'correctable_pre_fix';
    const CLASSIFICATION_MANUAL_REVIEW = 'manual_review';

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
     * Compte le nombre de commandes candidates (ligne de remise special_code=3 en montant
     * négatif, jamais encore scannées/corrigées), filtré entity + fk_store conditionnel
     * (invariant CLAUDE.dolibarr.md §14).
     *
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @return int
     */
    public function countCandidateOrders($storeId = 0)
    {
        $sql = $this->buildCandidateBaseQuery('SELECT COUNT(*) as total', $storeId, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('countCandidateOrders() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return (int) (isset($obj->total) ? $obj->total : 0);
    }

    /**
     * Passe DRY-RUN : scanne un lot de commandes candidates SANS RIEN ÉCRIRE (ni ligne, ni
     * `discount_repair_status`). Retourne le détail par commande (classification + montants)
     * pour affichage d'un aperçu avant confirmation.
     *
     * @param int $afterId  Curseur (dernier `do.id` vu, 0 au premier lot)
     * @param int $storeId  Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param int $limit    Taille du lot (bornée [1, MAX_BATCH_SIZE])
     * @return array{rows:array,hasMore:bool,nextCursor:int}
     */
    public function scanBatch($afterId, $storeId = 0, $limit = self::DEFAULT_BATCH_SIZE)
    {
        $afterId = max(0, (int) $afterId);
        $limit = max(1, min((int) $limit, self::MAX_BATCH_SIZE));
        $storeId = (int) $storeId;

        $candidates = $this->fetchCandidateOrdersBatch($afterId, $storeId, $limit);

        $rows = array();
        $maxIdSeen = $afterId;
        $statusCounts = array();
        $lastTrace = '';
        foreach ($candidates as $candidate) {
            $maxIdSeen = max($maxIdSeen, (int) $candidate->id);
            $row = $this->buildPreviewRow($candidate);
            $rows[] = $row;

            $rowStatus = isset($row['status']) ? (string) $row['status'] : 'unknown';
            $statusCounts[$rowStatus] = (isset($statusCounts[$rowStatus]) ? $statusCounts[$rowStatus] : 0) + 1;
            if (!empty($row['trace'])) {
                $lastTrace = $row['trace'];
            }
        }

        // Story 58-2 (AC2) : UNE ligne de journal par LOT, pas par commande (review 3 couches,
        // MEDIUM) — elle porte la répartition des classifications et les mappings produit
        // effectivement utilisés pour le calcul, ce qui suffit à rejouer a posteriori une
        // classification suspecte si ces mappings ont changé depuis l'import.
        if (!empty($candidates)) {
            $breakdown = array();
            foreach ($statusCounts as $statusLabel => $statusCount) {
                $breakdown[] = $statusLabel . '=' . $statusCount;
            }
            $this->logAction(
                ActionLogger::RESULT_SKIPPED,
                0,
                'Aperçu (dry-run) : ' . count($candidates) . ' commande(s) analysée(s) ['
                    . implode(', ', $breakdown) . ']' . $lastTrace
            );
        }

        return array(
            'rows' => $rows,
            'hasMore' => (count($candidates) === $limit),
            'nextCursor' => $maxIdSeen,
        );
    }

    /**
     * Passe d'APPLICATION (confirm=1 côté appelant) : traite un lot de commandes candidates.
     * Pour chaque commande :
     * - `correctable_pre_fix` + brouillon      → corrige la ligne (SQL ciblé, PAS updateline()
     *   — cf. docblock `applyCorrection()`), recalcule les totaux commande, marque `corrected`.
     * - `correctable_pre_fix` + non-brouillon  → NE TOUCHE RIEN, marque `manual_required`
     *   (le delta HT à régulariser via avoir est journalisé/retourné).
     * - `already_correct`                      → marque `not_affected` (non-régression).
     * - autre (`manual_review`)                → marque `manual_review` (montant fixe, EACH,
     *   SHIPPING_LINE ou remise déjà retouchée — hors périmètre auto, Validate §4).
     *
     * JAMAIS d'exception non catchée n'interrompt le lot (chaque candidat est traité dans son
     * propre `try/catch` — cf. `processOneCandidate()`).
     *
     * Verrou de réentrance (Story 58-2, AC1) : verrou advisory MySQL PAR ENTITÉ, acquis en tout
     * début de méthode, libéré via `finally` (garanti même si une exception s'échappe du lot,
     * ex. perte de connexion DB en cours de pagination). Acquisition non bloquante : si le verrou
     * est déjà détenu (une autre exécution de `repairBatch()` en cours pour la même entité), la
     * méthode retourne IMMÉDIATEMENT `lockBusy => true` et 0 commande traitée, sans même
     * interroger les candidats — l'appelant (`ajax/discount_repair_batch.php`) doit distinguer ce
     * cas d'un résultat à zéro légitime (aucun candidat) et afficher un message dédié.
     *
     * @param int $afterId Curseur (dernier `do.id` vu, 0 au premier lot)
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param int $limit   Taille du lot (bornée [1, MAX_BATCH_SIZE])
     * @return array{processed:int,corrected:int,manualRequired:int,manualReview:int,notAffected:int,errors:int,errorDetails:array,hasMore:bool,nextCursor:int,lockBusy:bool}
     */
    public function repairBatch($afterId, $storeId = 0, $limit = self::DEFAULT_BATCH_SIZE)
    {
        $afterId = max(0, (int) $afterId);
        $limit = max(1, min((int) $limit, self::MAX_BATCH_SIZE));
        $storeId = (int) $storeId;

        $result = array(
            'processed' => 0,
            'corrected' => 0,
            'manualRequired' => 0,
            'manualReview' => 0,
            'notAffected' => 0,
            'errors' => 0,
            'errorDetails' => array(),
            'hasMore' => false,
            'nextCursor' => $afterId,
            'lockBusy' => false,
        );

        $lockName = 'discount_repair_' . $this->entity /* entité explicite : CronHelperTrait ajoute en plus un suffixe basé sur $conf->entity GLOBAL, qui divergerait si le service était instancié pour une autre entité — la partition reste correcte grâce à ce préfixe (review 3 couches, LOW) */;
        if (!$this->acquireCronLock($lockName)) {
            $result['lockBusy'] = true;
            $this->log('repairBatch() - verrou déjà détenu pour entity=' . $this->entity . ', lot ignoré', LOG_INFO);
            return $result;
        }

        try {
            $candidates = $this->fetchCandidateOrdersBatch($afterId, $storeId, $limit);

            if (empty($candidates)) {
                return $result;
            }

            $maxIdSeen = $afterId;
            foreach ($candidates as $candidate) {
                $maxIdSeen = max($maxIdSeen, (int) $candidate->id);
                $this->processOneCandidate($candidate, $result);
            }

            $result['hasMore'] = (count($candidates) === $limit);
            $result['nextCursor'] = $maxIdSeen;
        } finally {
            $this->releaseCronLock($lockName);
        }

        return $result;
    }

    /**
     * Classifie UNE ligne de remise à partir des AUTRES lignes de la même commande (déjà en
     * base, non retouchées depuis l'import) — AUCUN appel Shopify. Fonction PURE (testable sans
     * DB), réutilisée par `scanBatch()`/`repairBatch()`.
     *
     * Recalcule le sous-total HT des lignes produit (même formule que
     * `ShopifyOrderManager::processDiscounts()` l.2805-2813 : exclut les remises existantes
     * elles-mêmes, les pourboires et les frais de port), applique le pourcentage extrait du
     * texte de la ligne (`desc`), puis compare au montant actuellement stocké :
     * - à `expectedHT` près (tolérance) → `already_correct`
     * - à `expectedHT / (1+TVA)` près   → `correctable_pre_fix` (signature EXACTE du bug pré-2.2.9)
     * - sinon                            → `manual_review` (montant fixe, EACH, SHIPPING_LINE,
     *   remise retouchée manuellement, ou lignes de commande modifiées depuis l'import)
     *
     * @param object[] $productLines      Lignes NON-remise de la commande (subprice, qty, remise_percent, special_code, fk_product)
     * @param object   $discountLine      Ligne de remise à classifier (subprice<0, tva_tx, desc)
     * @param int      $tipProductId      Id produit pourboire configuré (0 = aucun)
     * @param int      $shippingProductId Id produit frais de port configuré (0 = aucun)
     * @return array{status:string,expectedHT:?float,currentHT:float,deltaHT:?float,reason:string,percentage:?float,subtotalHT:?float}
     */
    public function classifyDiscountLine(array $productLines, $discountLine, $tipProductId = 0, $shippingProductId = 0)
    {
        $currentHT = abs((float) $discountLine->subprice);
        $taxRate = (float) $discountLine->tva_tx;
        $desc = (string) ($discountLine->desc ?? '');

        if (!preg_match('/Remise de\s+([0-9]+(?:[.,][0-9]+)?)\s*%/ui', $desc, $matches)) {
            return array(
                'status' => self::CLASSIFICATION_MANUAL_REVIEW,
                'expectedHT' => null,
                'currentHT' => $currentHT,
                'deltaHT' => null,
                'reason' => 'discount_type_not_percentage_or_unparseable',
                'percentage' => null,
                // Aucun sous-total calculé (le motif de remise n'a même pas pu être identifié) —
                // Story 58-2 AC2/AC3 : null explicite (≠ 0.0) pour distinguer "non calculé" de
                // "sous-total nul" dans le message journalisé.
                'subtotalHT' => null,
            );
        }

        $percentage = (float) str_replace(',', '.', $matches[1]);

        $subtotal = 0.0;
        $tipProductId = (int) $tipProductId;
        $shippingProductId = (int) $shippingProductId;
        foreach ($productLines as $line) {
            if ((int) ($line->special_code ?? 0) == 3) {
                continue; // ne jamais réinclure une AUTRE ligne de remise
            }
            $fkProduct = (int) ($line->fk_product ?? 0);
            if ($tipProductId > 0 && $fkProduct === $tipProductId) {
                continue;
            }
            if ($shippingProductId > 0 && $fkProduct === $shippingProductId) {
                continue;
            }
            $lineRemise = (float) ($line->remise_percent ?? 0);
            $subtotal += ((float) $line->subprice) * ((float) $line->qty) * (1 - ($lineRemise / 100));
        }

        $expectedHT = $subtotal * $percentage / 100;
        // Tolérance : 2 centimes (arrondis multi-lignes) ou 0.5% de la remise attendue, le plus grand.
        $tolerance = max(0.02, 0.005 * abs($expectedHT));

        if (abs($currentHT - $expectedHT) <= $tolerance) {
            return array(
                'status' => self::CLASSIFICATION_ALREADY_CORRECT,
                'expectedHT' => $expectedHT,
                'currentHT' => $currentHT,
                'deltaHT' => 0.0,
                'reason' => 'matches_expected',
                'percentage' => $percentage,
                'subtotalHT' => $subtotal,
            );
        }

        if ($taxRate > 0) {
            $preFixExpected = $expectedHT / (1 + ($taxRate / 100));
            if (abs($currentHT - $preFixExpected) <= $tolerance) {
                return array(
                    'status' => self::CLASSIFICATION_CORRECTABLE_PRE_FIX,
                    'expectedHT' => $expectedHT,
                    'currentHT' => $currentHT,
                    'deltaHT' => round($expectedHT - $currentHT, 2),
                    'reason' => 'pre_v229_double_conversion',
                    'percentage' => $percentage,
                    'subtotalHT' => $subtotal,
                );
            }
        }

        return array(
            'status' => self::CLASSIFICATION_MANUAL_REVIEW,
            'expectedHT' => $expectedHT,
            'currentHT' => $currentHT,
            'deltaHT' => round($expectedHT - $currentHT, 2),
            'reason' => 'amount_mismatch_unexplained',
            'percentage' => $percentage,
            'subtotalHT' => $subtotal,
        );
    }

    // =========================================================================
    // Implémentation interne
    // =========================================================================

    /**
     * Traite UNE commande candidate : fetch, classification, application (ou marquage seul),
     * journalisation. Toute exception est catchée (non-blocage du lot).
     *
     * @param object $candidate {id, fk_commande}
     * @param array  $result    Rapport cumulé (par référence)
     * @return void
     */
    private function processOneCandidate($candidate, array &$result)
    {
        $result['processed']++;
        $orderRowId = (int) $candidate->id;
        $fkCommande = (int) $candidate->fk_commande;

        try {
            dol_include_once('/commande/class/commande.class.php');
            $commande = new \Commande($this->db);
            if ($commande->fetch($fkCommande) <= 0) {
                $message = "Commande Dolibarr #$fkCommande introuvable (supprimée ?)";
                $result['errorDetails'][] = $message;
                $result['errors']++;
                $this->logAction(ActionLogger::RESULT_ERROR, $fkCommande, $message);
                return; // pas de marquage : transitoire, retenté à la prochaine passe
            }

            $discountLines = array();
            $otherLines = array();
            foreach ($commande->lines as $line) {
                if ((int) $line->special_code == 3 && (float) $line->subprice < 0) {
                    $discountLines[] = $line;
                } else {
                    $otherLines[] = $line;
                }
            }

            if (count($discountLines) !== 1) {
                // 0 ligne de remise négative (état inattendu vs. la requête de sélection) ou
                // plusieurs (ambiguïté, ex. remises multiples cumulées) : jamais d'auto-correction.
                $message = "Commande #$fkCommande : " . count($discountLines) . " ligne(s) de remise négative détectée(s), attendu 1 — revue manuelle requise";
                $this->markStatus($orderRowId, self::STATUS_MANUAL_REVIEW);
                $result['manualReview']++;
                $this->logAction(ActionLogger::RESULT_SKIPPED, $fkCommande, $message);
                return;
            }

            $discountLine = $discountLines[0];
            $config = $this->getModuleConfig();
            $tipProductId = isset($config['tip_product_id']) ? (int) $config['tip_product_id'] : 0;
            $shippingProductId = isset($config['shipping_product_id']) ? (int) $config['shipping_product_id'] : 0;

            $classification = $this->classifyDiscountLine($otherLines, $discountLine, $tipProductId, $shippingProductId);
            // Story 58-2 (AC2/AC3) : traçabilité de l'hypothèse — sous-total de référence retenu
            // + produits port/pourboire exclus, accolés à CHAQUE message de cette classification.
            $traceSuffix = $this->buildClassificationLogSuffix($classification, $tipProductId, $shippingProductId);

            switch ($classification['status']) {
                case self::CLASSIFICATION_ALREADY_CORRECT:
                    $this->markStatus($orderRowId, self::STATUS_NOT_AFFECTED);
                    $result['notAffected']++;
                    $this->logAction(ActionLogger::RESULT_SKIPPED, $fkCommande, "Commande #$fkCommande : remise déjà correcte, aucune action" . $traceSuffix);
                    break;

                case self::CLASSIFICATION_CORRECTABLE_PRE_FIX:
                    if ((int) $commande->statut === \Commande::STATUS_DRAFT) {
                        $applied = $this->applyCorrection($commande, $discountLine, $classification['expectedHT']);
                        if ($applied) {
                            $this->markStatus($orderRowId, self::STATUS_CORRECTED);
                            $result['corrected']++;
                            $message = "Commande #$fkCommande : remise corrigée de "
                                . number_format($classification['currentHT'], 2, ',', ' ') . " € HT à "
                                . number_format($classification['expectedHT'], 2, ',', ' ') . " € HT (delta "
                                . number_format($classification['deltaHT'], 2, ',', ' ') . " € HT)"
                                . $traceSuffix;
                            $this->logAction(ActionLogger::RESULT_SUCCESS, $fkCommande, $message);
                        } else {
                            // Échec applicatif potentiellement transitoire : PAS de marquage, retenté.
                            $result['errors']++;
                            $message = "Commande #$fkCommande : échec application correction remise" . $traceSuffix;
                            $result['errorDetails'][] = $message;
                            $this->logAction(ActionLogger::RESULT_ERROR, $fkCommande, $message);
                        }
                    } elseif ((int) $commande->statut === \Commande::STATUS_CANCELED) {
                        // Commande annulée : jamais facturée, donc aucun avoir à émettre — rien à
                        // régulariser, on la sort simplement du pool (idempotence).
                        $this->markStatus($orderRowId, self::STATUS_NOT_AFFECTED);
                        $result['notAffected']++;
                        $message = "Commande #$fkCommande (annulée) : remise sous-évaluée de "
                            . number_format($classification['deltaHT'], 2, ',', ' ') . " € HT — "
                            . "aucune régularisation nécessaire (commande annulée, jamais facturée)"
                            . $traceSuffix;
                        $this->logAction(ActionLogger::RESULT_SKIPPED, $fkCommande, $message);
                    } else {
                        $this->markStatus($orderRowId, self::STATUS_MANUAL_REQUIRED);
                        $result['manualRequired']++;
                        $message = "Commande #$fkCommande (statut verrouillé) : remise sous-évaluée de "
                            . number_format($classification['deltaHT'], 2, ',', ' ') . " € HT — "
                            . "à régulariser via avoir/note de crédit (ne PAS éditer une commande validée/facturée)"
                            . $traceSuffix;
                        $result['errorDetails'][] = $message;
                        $this->logAction(ActionLogger::RESULT_SKIPPED, $fkCommande, $message);
                    }
                    break;

                default:
                    $this->markStatus($orderRowId, self::STATUS_MANUAL_REVIEW);
                    $result['manualReview']++;
                    $message = "Commande #$fkCommande : remise non auto-corrigeable (" . $classification['reason'] . ") — revue manuelle requise" . $traceSuffix;
                    $result['errorDetails'][] = $message;
                    $this->logAction(ActionLogger::RESULT_SKIPPED, $fkCommande, $message);
                    break;
            }
        } catch (\Throwable $e) {
            $result['errors']++;
            $message = "Exception traitement commande #$fkCommande: " . $e->getMessage();
            $result['errorDetails'][] = $message;
            $this->logAction(ActionLogger::RESULT_ERROR, $fkCommande, $message);
            $this->log('processOneCandidate() - ' . $message, LOG_ERR);
        }
    }

    /**
     * Applique la correction : UPDATE ciblé de `llx_commandedet` (subprice + totaux ligne),
     * PUIS recalcul des totaux commande via `update_price()`. Transaction explicite.
     *
     * CHOIX DÉLIBÉRÉ — PAS `Commande::updateline()` : cette méthode core contient un
     * comportement qui écraserait notre marqueur `special_code=3` — `updateline()` force
     * `special_code = 0` dès que `$qty` n'est pas vide ET que `$special_code == 3` est passé en
     * paramètre (bloc "Remove option tag", `commande.class.php` ~l.3161-3163), un comportement
     * pensé pour un tout autre usage Dolibarr (ligne "option" à qty=0) que notre convention
     * interne (marqueur ligne de remise, qty=1). L'utiliser aurait fait disparaître le marqueur
     * `special_code=3` de la ligne corrigée, cassant tous les filtres qui en dépendent
     * (`processDiscounts()`, `classifyDiscountLine()` lui-même). D'où un UPDATE SQL ciblé, qui ne
     * touche QUE les colonnes de montant, et laisse `special_code`/`desc`/`tva_tx`/`qty` intacts.
     *
     * @param \Commande $commande
     * @param object    $discountLine
     * @param float     $expectedHT
     * @return bool true si la correction a été appliquée avec succès
     */
    private function applyCorrection($commande, $discountLine, $expectedHT)
    {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';

        $lineId = (int) $discountLine->id;
        $taxRate = (float) $discountLine->tva_tx;
        $newSubprice = -abs((float) $expectedHT);

        // Multidevise : le taux de change RÉEL de la commande doit être passé à
        // calcul_price_total(). Avec multicurrency_tx=1 en dur, la fonction se contente de
        // recopier les montants en devise société dans les colonnes multicurrency_* — sur une
        // commande en devise étrangère, la ligne corrigée porterait des montants en devise faux,
        // et update_price() (qui SOMME ces colonnes sans les recalculer) propagerait l'erreur aux
        // totaux de la commande entière.
        $multicurrencyTx = (float) (isset($commande->multicurrency_tx) ? $commande->multicurrency_tx : 1);
        if ($multicurrencyTx <= 0) {
            $multicurrencyTx = 1;
        }
        $multicurrencyCode = !empty($commande->multicurrency_code) ? $commande->multicurrency_code : '';

        $tabprice = calcul_price_total(1, $newSubprice, 0, $taxRate, 0, 0, 0, 'HT', 0, 0, null, array(), 100, $multicurrencyTx, 0, $multicurrencyCode);
        $totalHT = $tabprice[0];
        $totalTVA = $tabprice[1];
        $totalTTC = $tabprice[2];
        $multicurrencyTotalHT = isset($tabprice[16]) ? $tabprice[16] : $totalHT;
        $multicurrencyTotalTVA = isset($tabprice[17]) ? $tabprice[17] : $totalTVA;
        $multicurrencyTotalTTC = isset($tabprice[18]) ? $tabprice[18] : $totalTTC;
        // 19 = multicurrency_pu_ht (prix unitaire converti) — jamais le subprice société.
        $multicurrencySubprice = isset($tabprice[19]) ? $tabprice[19] : $newSubprice;

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . "commandedet SET";
        $sql .= " subprice = " . ((float) $newSubprice);
        $sql .= ", total_ht = " . ((float) $totalHT);
        $sql .= ", total_tva = " . ((float) $totalTVA);
        $sql .= ", total_ttc = " . ((float) $totalTTC);
        $sql .= ", multicurrency_subprice = " . ((float) $multicurrencySubprice);
        $sql .= ", multicurrency_total_ht = " . ((float) $multicurrencyTotalHT);
        $sql .= ", multicurrency_total_tva = " . ((float) $multicurrencyTotalTVA);
        $sql .= ", multicurrency_total_ttc = " . ((float) $multicurrencyTotalTTC);
        $sql .= " WHERE rowid = " . $lineId;
        $sql .= " AND fk_commande = " . (int) $commande->id;
        $sql .= " AND special_code = 3"; // garde-fou : ne corrige QUE la ligne marqueur remise

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('applyCorrection() - Erreur UPDATE ligne #' . $lineId . ': ' . $this->db->lasterror(), LOG_ERR);
            $this->db->rollback();
            return false;
        }

        $updateResult = $commande->update_price(1);
        if ($updateResult < 0) {
            $this->log('applyCorrection() - Erreur update_price() commande #' . $commande->id, LOG_ERR);
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        return true;
    }

    /**
     * Marque la commande comme traitée (idempotence — exclue des lots suivants).
     *
     * @param int    $orderRowId Rowid `llx_doli2shop_orders.id`
     * @param string $status
     * @return void
     */
    private function markStatus($orderRowId, $status)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_orders";
        $sql .= " SET discount_repair_status = '" . $this->db->escape($status) . "'";
        $sql .= " WHERE id = " . (int) $orderRowId;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("markStatus() - Erreur marquage commande (doli2shop_orders.id=#$orderRowId): " . $this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Construit une ligne d'aperçu (dry-run) pour UNE commande candidate, sans rien écrire.
     *
     * @param object $candidate {id, fk_commande}
     * @return array
     */
    protected function buildPreviewRow($candidate)
    {
        $fkCommande = (int) $candidate->fk_commande;

        $row = array(
            'orderRowId' => (int) $candidate->id,
            'fkCommande' => $fkCommande,
            'ref' => '',
            'statut' => null,
            'isDraft' => false,
            'status' => self::CLASSIFICATION_MANUAL_REVIEW,
            'reason' => 'order_fetch_failed',
            'currentHT' => 0.0,
            'expectedHT' => null,
            'deltaHT' => null,
        );

        try {
            dol_include_once('/commande/class/commande.class.php');
            $commande = new \Commande($this->db);
            if ($commande->fetch($fkCommande) <= 0) {
                return $row;
            }
            $row['ref'] = $commande->ref;
            $row['statut'] = (int) $commande->statut;
            $row['isDraft'] = ((int) $commande->statut === \Commande::STATUS_DRAFT);

            $discountLines = array();
            $otherLines = array();
            foreach ($commande->lines as $line) {
                if ((int) $line->special_code == 3 && (float) $line->subprice < 0) {
                    $discountLines[] = $line;
                } else {
                    $otherLines[] = $line;
                }
            }

            if (count($discountLines) !== 1) {
                $row['status'] = self::CLASSIFICATION_MANUAL_REVIEW;
                $row['reason'] = 'ambiguous_discount_line_count';
                return $row;
            }

            $config = $this->getModuleConfig();
            $tipProductId = isset($config['tip_product_id']) ? (int) $config['tip_product_id'] : 0;
            $shippingProductId = isset($config['shipping_product_id']) ? (int) $config['shipping_product_id'] : 0;

            $classification = $this->classifyDiscountLine($otherLines, $discountLines[0], $tipProductId, $shippingProductId);
            $row['status'] = $classification['status'];
            $row['reason'] = $classification['reason'];
            $row['currentHT'] = $classification['currentHT'];
            $row['expectedHT'] = $classification['expectedHT'];
            $row['deltaHT'] = $classification['deltaHT'];

            // Story 58-2 (AC2) — observabilité du sous-total de référence et des mappings
            // port/pourboire utilisés. La trace est REMONTÉE au lot (clé 'trace') et non
            // journalisée ici : review 3 couches (MEDIUM, couches 1 et 2) — un clic « Aperçu »
            // enchaîne automatiquement toutes les pages de candidats, une ligne de journal par
            // commande scannée inonderait la table d'audit sur les catalogues de plusieurs
            // milliers de commandes, précisément la cible de cet outil. Le chemin d'APPLICATION
            // (repairBatch), lui, journalise bien commande par commande.
            $row['trace'] = $this->buildClassificationLogSuffix($classification, $tipProductId, $shippingProductId);
        } catch (\Throwable $e) {
            $row['reason'] = 'exception: ' . $e->getMessage();
        }

        return $row;
    }

    /**
     * Récupère la config module (tip_product_id / shipping_product_id) — wrapper mockable.
     *
     * @return array
     */
    protected function getModuleConfig()
    {
        dol_include_once('/doli2shop/class/configurationMigrator.class.php');
        $migrator = new \ConfigurationMigrator($this->db);
        $config = $migrator->getConfiguration($this->entity);
        return is_array($config) ? $config : array();
    }

    /**
     * Récupère un lot paginé de commandes candidates (pagination PAR CLÉ sur `do.id`), filtré
     * entity + fk_store conditionnel (invariant §14) + `discount_repair_status` (exclut les
     * commandes déjà traitées, quel que soit le statut final).
     *
     * @param int $afterId Curseur : ne renvoie que les candidats avec `do.id` STRICTEMENT supérieur
     * @param int $storeId
     * @param int $limit
     * @return object[] Lignes {id, fk_commande}, triées par id ASC
     */
    protected function fetchCandidateOrdersBatch($afterId, $storeId, $limit)
    {
        $sql = $this->buildCandidateBaseQuery('SELECT do.id, do.fk_commande', $storeId, $afterId);
        $sql .= " ORDER BY do.id ASC";
        $sql .= $this->db->plimit((int) $limit, 0);

        $candidates = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $candidates[] = $obj;
            }
            $this->db->free($resql);
        } else {
            $this->log('fetchCandidateOrdersBatch() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
        }
        return $candidates;
    }

    /**
     * Requête de base commune à `countCandidateOrders()` et `fetchCandidateOrdersBatch()`.
     *
     * @param string $selectClause  Ex. "SELECT COUNT(*) as total" ou "SELECT do.id, do.fk_commande"
     * @param int    $storeId
     * @param int    $afterId       0 = pas de filtre curseur (utilisé pour le comptage)
     * @return string
     */
    private function buildCandidateBaseQuery($selectClause, $storeId, $afterId)
    {
        $sql = $selectClause . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders do";
        $sql .= " WHERE do.entity = " . (int) $this->entity;
        if ((int) $storeId > 0) {
            $sql .= " AND do.fk_store = " . (int) $storeId;
        }
        $sql .= " AND do.discount_repair_status IS NULL";
        if ((int) $afterId > 0) {
            $sql .= " AND do.id > " . (int) $afterId;
        }
        $sql .= " AND EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "commandedet cd";
        $sql .= " WHERE cd.fk_commande = do.fk_commande AND cd.special_code = 3 AND cd.subprice < 0)";

        return $sql;
    }

    /**
     * Journalise le résultat d'une commande (ActionLogger — non-bloquant par construction).
     *
     * @param string $result
     * @param int    $fkCommande
     * @param string $message
     * @return void
     */
    protected function logAction($result, $fkCommande, $message)
    {
        ActionLogger::log($this->db, $this->entity, ActionLogger::TYPE_DISCOUNT_REPAIR, 'commande', $fkCommande, $result, $message);
    }

    /**
     * Construit le suffixe de traçabilité accolé à CHAQUE message journalisé pour une
     * classification effective (Story 58-2, AC2/AC3 — reformulé par le Validate 2026-07-27) :
     * sous-total de référence retenu par `classifyDiscountLine()` + identifiants des produits
     * pourboire/frais de port EXCLUS de ce sous-total.
     *
     * Objectif OBSERVABILITÉ, pas détection de dérive de configuration : aucune vérité
     * historique n'existe en base pour comparer le sous-total retenu à l'import (cf. docblock de
     * classe) — ce suffixe permet seulement de rejouer et d'expliquer a posteriori une correction
     * contestée si les mappings produit ont changé depuis l'import. `ActionLogger::log()` ne
     * prenant qu'un message texte libre, ces informations sont injectées directement dans la
     * chaîne (AC3), jamais dans un champ structuré séparé.
     *
     * @param array $classification   Retour de classifyDiscountLine() (clé 'subtotalHT')
     * @param int   $tipProductId     Id produit pourboire configuré (0 = aucun)
     * @param int   $shippingProductId Id produit frais de port configuré (0 = aucun)
     * @return string
     */
    private function buildClassificationLogSuffix(array $classification, $tipProductId, $shippingProductId)
    {
        $subtotalHT = isset($classification['subtotalHT']) ? $classification['subtotalHT'] : null;
        $subtotalLabel = ($subtotalHT !== null)
            ? number_format((float) $subtotalHT, 2, ',', ' ') . ' € HT'
            : 'n/a (motif de remise non reconnu)';

        $tipLabel = ((int) $tipProductId > 0) ? ('#' . (int) $tipProductId) : 'aucun';
        $shippingLabel = ((int) $shippingProductId > 0) ? ('#' . (int) $shippingProductId) : 'aucun';

        return " [sous-total réf.: $subtotalLabel ; produit pourboire: $tipLabel ; produit port: $shippingLabel]";
    }
}
