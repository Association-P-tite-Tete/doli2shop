<?php
/**
 * @file        class/underpricedorderauditservice.class.php
 * @brief       Audit (jamais de correction) des commandes Shopify importées sous-évaluées avant
 *              le correctif e825f456 (boutique en prix hors taxes), story
 *              reprise-des-commandes-importees-en-prix-hors-taxes
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.5.4
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
require_once dirname(__FILE__) . '/shopifyordermanager.class.php';

/**
 * Class UnderpricedOrderAuditService
 *
 * Sur une boutique Shopify réglée en prix hors taxes, chaque commande importée AVANT le
 * correctif e825f456 (v2.5.3) a été enregistrée à environ 83% de sa valeur réelle (division
 * par le taux de TVA appliquée à un prix qui était déjà HT). Ce service établit la liste de
 * ces commandes et l'écart en jeu.
 *
 * ⚠️ CE N'EST PAS UN CORRECTEUR. C'EST UN OUTIL D'AUDIT (Validate du 12/09/2026) : la quasi-
 * totalité des commandes concernées est déjà auto-validée, auto-facturée et auto-payée
 * (`ShopifyOrderManager::createOrder()` — AUTO_CREATE_INVOICE/AUTO_VALIDATE_INVOICE/
 * AUTO_CREATE_PAYMENT valent 1 par défaut). Réécrire une facture validée ne se fait pas par
 * UPDATE — comptablement, cela passe par un avoir, décision et acte du client, hors de cet
 * outil. Ce service N'ÉCRIT JAMAIS sur `llx_commande`, `llx_facture` ni les paiements : il ne
 * persiste que son propre verdict, sur `llx_doli2shop_orders` (colonnes `underprice_audit_*`),
 * pour l'idempotence (AC6) et la piste d'audit (AC5).
 *
 * L'oracle est LOCAL, jamais un appel réseau Shopify (AC7) : depuis la v2.3.0,
 * `ShopifyOrderManager::createOrder()` écrit sur CHAQUE commande importée un récapitulatif
 * dans `note_private` — "[Shopify] Sous-total: X | Taxes: Y | Remises: Z | Total: W" — construit
 * à partir des champs GraphQL bruts (jamais passés par la conversion buggée). Comparer
 * `Commande::total_ttc` à ce Total donne le verdict.
 *
 * Détection fondée sur la PRÉSENCE du motif dans `note_private`, jamais sur une date de version
 * en dur (règle 1 de la story) : un client ayant mis à jour tardivement fausserait un critère de
 * date. Conséquences :
 * - note ABSENTE (commande antérieure à la fonctionnalité, ou note supprimée) → `manual_required`
 *   (raison `no_historical_note`) : aucun oracle disponible, JAMAIS réputée correcte faute de
 *   preuve (règle 2).
 * - note PRÉSENTE mais illisible (devise non reconnue, montant tronqué/édité à la main de façon
 *   incohérente) → `manual_review` (raison `note_unparseable`), jamais un verdict de conformité ou
 *   de sous-évaluation (règle 3).
 * - note PRÉSENTE et exploitable → comparaison via `ShopifyOrderManager::classifyTotalsMismatch()`
 *   (même oracle que le contrôle de cohérence livré en 2.5.3) : écart proportionnel (>5%, signature
 *   du facteur constant 1+taux) → sous-évaluée, `manual_required` ; écart mineur ne correspondant
 *   PAS à la signature connue → `manual_review` (conservateur, jamais affirmé comme sous-évaluation
 *   sans preuve du motif).
 *
 * Statuts persistés (`llx_doli2shop_orders.underprice_audit_status`) — volontairement PAS
 * `corrected`/`not_affected` du modèle `DiscountRepairService` : aucune correction n'existe ici.
 *   - `already_correct`  : montant conforme au récap Shopify (écart sous le centime).
 *   - `manual_required`  : nécessite une action humaine HORS de l'outil — soit parce qu'aucun
 *                          oracle n'est disponible (`no_historical_note`), soit parce qu'une
 *                          sous-évaluation confirmée est déjà verrouillée comptablement
 *                          (`underpriced_committed`, avoir) ou encore un simple brouillon
 *                          (`underpriced_draft`, corrigeable directement dans Dolibarr — mais
 *                          jamais par ce service) ou une commande annulée (`underpriced_canceled`,
 *                          jamais facturée, aucune régularisation nécessaire).
 *   - `manual_review`    : donnée ambiguë — la note ne permet PAS d'affirmer un verdict.
 *
 * Idempotent (AC6) : `underprice_audit_status IS NULL` délimite le pool de candidats — une
 * commande déjà auditée (quel que soit le verdict) n'est plus jamais recomptée. Pagination PAR
 * CLÉ sur `do.id` (jamais d'OFFSET), même motif que `DiscountRepairService`.
 *
 * Verrou de réentrance (`auditBatch()`, la seule passe qui écrit) : verrou advisory MySQL par
 * ENTITÉ via `CronHelperTrait`, même pattern que `DiscountRepairService::repairBatch()`.
 * `scanBatch()` (dry-run) n'écrit rien : aucun verrou n'y est prix.
 */
class UnderpricedOrderAuditService
{
    use LoggerTrait;
    use CronHelperTrait;

    /** Taille de lot par défaut si l'appelant (AJAX) n'en précise pas */
    const DEFAULT_BATCH_SIZE = 20;

    /** Borne haute de sécurité sur $limit (paramètre utilisateur via AJAX) */
    const MAX_BATCH_SIZE = 100;

    /** Statuts persistés dans llx_doli2shop_orders.underprice_audit_status */
    const STATUS_ALREADY_CORRECT = 'already_correct';
    const STATUS_MANUAL_REQUIRED = 'manual_required';
    const STATUS_MANUAL_REVIEW = 'manual_review';

    /** Raisons détaillées (llx_doli2shop_orders.underprice_audit_reason) */
    const REASON_MATCHES_NOTE = 'matches_note';
    const REASON_NO_HISTORICAL_NOTE = 'no_historical_note';
    const REASON_NOTE_UNPARSEABLE = 'note_unparseable';
    const REASON_MISMATCH_UNEXPLAINED = 'mismatch_unexplained';
    const REASON_UNDERPRICED_COMMITTED = 'underpriced_committed';
    const REASON_UNDERPRICED_DRAFT = 'underpriced_draft';
    const REASON_UNDERPRICED_CANCELED = 'underpriced_canceled';

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var int Entité Dolibarr courante */
    private $entity;

    /** @var int|null Utilisateur ayant lancé l'audit (piste d'audit AC5), null = inconnu (scan) */
    private $userId;

    /**
     * @param DoliDB   $db     Gestionnaire base de données
     * @param int|null $entity Entité Dolibarr (null = entité courante $conf->entity)
     * @param int|null $userId Utilisateur déclencheur (persisté uniquement par auditBatch())
     */
    public function __construct($db, $entity = null, $userId = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
        $this->userId = isset($userId) ? (int) $userId : null;
    }

    // =========================================================================
    // Fonctions PURES (aucun I/O) — cœur de la détection, testables sans DB
    // =========================================================================

    /**
     * Parse le récapitulatif financier Shopify écrit dans `note_private` depuis la v2.3.0 :
     * "[Shopify] Sous-total: X | Taxes: Y | Remises: Z | Total: W" (`ShopifyOrderManager::
     * createOrder()` — chaque montant est suffixé du code devise SANS espace, ex. "148.14EUR",
     * et formaté via `number_format($v, 2)` — séparateur décimal '.', séparateur de milliers ','
     * par défaut PHP).
     *
     * `note_private` est un champ libre éditable par l'utilisateur (règle 3) : cette fonction
     * distingue explicitement 3 cas, jamais réduits à un simple "trouvé/pas trouvé" :
     * - `found=false`   : aucun motif Shopify/montant reconnu — note absente (commande antérieure
     *                     à la fonctionnalité, 29/06/2026) ou entièrement remplacée par l'auteur.
     * - `found=true, parseable=false` : le marqueur "[Shopify]" ET au moins une étiquette
     *                     (Sous-total/Taxes/Remises/Total) sont présents, mais le montant Total
     *                     n'a pas pu être extrait (note tronquée/éditée de façon incohérente).
     * - `found=true, parseable=true`  : `total` exploitable pour comparaison.
     *
     * @param  string $notePrivate Contenu de Commande::note_private (peut être vide/null)
     * @return array{found:bool,parseable:bool,subtotal:?float,taxes:?float,discounts:?float,total:?float,currency:?string}
     */
    public function parseShopifyFinancialNote($notePrivate)
    {
        $notePrivate = (string) $notePrivate;

        $result = array(
            'found' => false,
            'parseable' => false,
            'subtotal' => null,
            'taxes' => null,
            'discounts' => null,
            'total' => null,
            'currency' => null,
        );

        // Garde conservatrice : si le texte ne porte plus le marqueur "[Shopify]" ET au moins une
        // étiquette de montant connue, on considère qu'il n'y a AUCUNE note exploitable — jamais de
        // tentative de deviner sur un texte qui ne s'identifie plus comme le récap historique.
        $hasMarker = (strpos($notePrivate, '[Shopify]') !== false);
        $hasAnyLabel = (bool) preg_match('/(Sous-total|Taxes|Remises|Total)\s*:/u', $notePrivate);
        if (!$hasMarker || !$hasAnyLabel) {
            return $result;
        }
        $result['found'] = true;

        list($subtotal, ) = $this->extractShopifyAmountField($notePrivate, 'Sous-total');
        list($taxes, ) = $this->extractShopifyAmountField($notePrivate, 'Taxes');
        list($discounts, ) = $this->extractShopifyAmountField($notePrivate, 'Remises');
        list($total, $currency) = $this->extractShopifyAmountField($notePrivate, 'Total');

        $result['subtotal'] = $subtotal;
        $result['taxes'] = $taxes;
        $result['discounts'] = $discounts;
        $result['total'] = $total;
        $result['currency'] = $currency;
        $result['parseable'] = ($total !== null);

        return $result;
    }

    /**
     * Extrait UN champ montant du récap ("Label: 1,234.56EUR") — devise optionnelle, avec ou
     * sans espace avant le code devise (note potentiellement éditée à la main).
     *
     * @param  string $notePrivate
     * @param  string $label Ex. "Total", "Sous-total"
     * @return array{0:?float,1:?string} [montant normalisé ou null, code devise ou null]
     */
    private function extractShopifyAmountField($notePrivate, $label)
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([0-9][0-9.,]*)\s*([A-Za-z]{0,5})/u';
        if (!preg_match($pattern, $notePrivate, $m)) {
            return array(null, null);
        }
        $amount = $this->normalizeShopifyAmount($m[1]);
        $currency = (isset($m[2]) && $m[2] !== '') ? strtoupper($m[2]) : null;
        return array($amount, $currency);
    }

    /**
     * Normalise un montant texte issu de `note_private` en float — robuste au format d'écriture
     * standard (`number_format($v, 2)` : décimal '.', milliers ',') ET à une note éditée à la
     * main en convention française (décimal ',', ex. "1234,56").
     *
     * Règle : les DEUX séparateurs présents → ',' = milliers, '.' = décimal (format d'écriture
     * standard). SEULEMENT ',' présent → décimal SI un seul ',' suivi d'EXACTEMENT 2 chiffres
     * (édition FR plausible), sinon ',' traité comme milliers (aucun décimal). SEULEMENT '.' ou
     * aucun séparateur → inchangé.
     *
     * @param  string $raw
     * @return float|null null si non numérique après normalisation (note illisible)
     */
    private function normalizeShopifyAmount($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $hasComma = (strpos($raw, ',') !== false);
        $hasDot = (strpos($raw, '.') !== false);

        if ($hasComma && $hasDot) {
            $normalized = str_replace(',', '', $raw);
        } elseif ($hasComma && !$hasDot) {
            $parts = explode(',', $raw);
            if (count($parts) === 2 && strlen($parts[1]) === 2) {
                $normalized = $parts[0] . '.' . $parts[1];
            } else {
                $normalized = str_replace(',', '', $raw);
            }
        } else {
            $normalized = $raw;
        }

        if (!is_numeric($normalized)) {
            return null;
        }
        return (float) $normalized;
    }

    /**
     * Classifie UNE commande à partir du total TTC enregistré et du récap Shopify déjà parsé
     * (AUCUN appel Shopify, AUCUNE écriture) — fonction PURE, cœur de `buildPreviewRow()` et
     * `processOneCandidate()`.
     *
     * Réutilise `ShopifyOrderManager::classifyTotalsMismatch()` (même oracle que le contrôle de
     * cohérence livré en 2.5.3, story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-
     * journal) : un écart PROPORTIONNEL (>5% relatif) est la signature du facteur constant
     * 1+taux du défaut e825f456 ; un écart mineur (arrondi) ne correspond PAS à cette signature et
     * n'est JAMAIS affirmé comme une sous-évaluation sans preuve du motif (conservateur, cf.
     * docblock de classe).
     *
     * @param  float  $recordedTotalTtc Commande::total_ttc actuellement en base
     * @param  array  $parsedNote       Retour de parseShopifyFinancialNote()
     * @param  int    $orderStatut      Commande::statut (détermine la raison, jamais le statut)
     * @return array{status:string,reason:string,recordedTotalTtc:float,realTotalTtc:?float,deltaTotalTtc:?float,currency:?string}
     */
    public function classifyOrder($recordedTotalTtc, array $parsedNote, $orderStatut)
    {
        $recordedTotalTtc = (float) $recordedTotalTtc;

        if (empty($parsedNote['found'])) {
            return array(
                'status' => self::STATUS_MANUAL_REQUIRED,
                'reason' => self::REASON_NO_HISTORICAL_NOTE,
                'recordedTotalTtc' => $recordedTotalTtc,
                'realTotalTtc' => null,
                'deltaTotalTtc' => null,
                'currency' => null,
            );
        }

        if (empty($parsedNote['parseable']) || $parsedNote['total'] === null) {
            return array(
                'status' => self::STATUS_MANUAL_REVIEW,
                'reason' => self::REASON_NOTE_UNPARSEABLE,
                'recordedTotalTtc' => $recordedTotalTtc,
                'realTotalTtc' => null,
                'deltaTotalTtc' => null,
                'currency' => isset($parsedNote['currency']) ? $parsedNote['currency'] : null,
            );
        }

        $realTotalTtc = (float) $parsedNote['total'];
        $mismatch = ShopifyOrderManager::classifyTotalsMismatch($realTotalTtc, $recordedTotalTtc);

        if ($mismatch === null) {
            return array(
                'status' => self::STATUS_ALREADY_CORRECT,
                'reason' => self::REASON_MATCHES_NOTE,
                'recordedTotalTtc' => $recordedTotalTtc,
                'realTotalTtc' => $realTotalTtc,
                'deltaTotalTtc' => 0.0,
                'currency' => $parsedNote['currency'],
            );
        }

        $deltaTotalTtc = round($realTotalTtc - $recordedTotalTtc, 2);

        if ($mismatch['severity'] !== 'proportional') {
            return array(
                'status' => self::STATUS_MANUAL_REVIEW,
                'reason' => self::REASON_MISMATCH_UNEXPLAINED,
                'recordedTotalTtc' => $recordedTotalTtc,
                'realTotalTtc' => $realTotalTtc,
                'deltaTotalTtc' => $deltaTotalTtc,
                'currency' => $parsedNote['currency'],
            );
        }

        $orderStatut = (int) $orderStatut;
        if ($orderStatut === \Commande::STATUS_DRAFT) {
            $reason = self::REASON_UNDERPRICED_DRAFT;
        } elseif ($orderStatut === \Commande::STATUS_CANCELED) {
            $reason = self::REASON_UNDERPRICED_CANCELED;
        } else {
            $reason = self::REASON_UNDERPRICED_COMMITTED;
        }

        return array(
            'status' => self::STATUS_MANUAL_REQUIRED,
            'reason' => $reason,
            'recordedTotalTtc' => $recordedTotalTtc,
            'realTotalTtc' => $realTotalTtc,
            'deltaTotalTtc' => $deltaTotalTtc,
            'currency' => $parsedNote['currency'],
        );
    }

    /**
     * Détermine si le `deltaTotalTtc` d'une classification doit compter dans le total agrégé
     * "écart total"/"somme en jeu" (AC2 — `auditBatch()['totalDeltaTtc']`, et son équivalent côté
     * aperçu JS `admin/underprice_audit.php:scanTotalDelta`, qui applique la même règle).
     *
     * Fonction PURE (aucun I/O) : une commande ANNULÉE (`REASON_UNDERPRICED_CANCELED`) n'a jamais
     * été facturée ni payée — aucune régularisation/avoir n'est nécessaire. La sommer surestimerait
     * le montant réellement à régulariser par le client. Toute autre raison sous
     * STATUS_MANUAL_REQUIRED avec un delta connu (committed, draft) compte normalement ;
     * no_historical_note a de toute façon un delta null (aucun oracle disponible).
     *
     * @param  array $classification Retour de classifyOrder()
     * @return bool
     */
    public static function countsTowardTotalDelta(array $classification)
    {
        return $classification['deltaTotalTtc'] !== null
            && $classification['reason'] !== self::REASON_UNDERPRICED_CANCELED;
    }

    // =========================================================================
    // Orchestration DB — scan (dry-run) / audit (persistance du verdict, jamais de correction)
    // =========================================================================

    /**
     * Compte le nombre de commandes candidates (jamais encore auditées), filtré entity + fk_store
     * conditionnel (invariant CLAUDE.dolibarr.md §14).
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
     * Passe DRY-RUN (AC2) : scanne un lot de commandes candidates SANS RIEN ÉCRIRE. Retourne le
     * détail par commande (verdict + montants) pour affichage d'un aperçu.
     *
     * @param int $afterId Curseur (dernier `do.id` vu, 0 au premier lot)
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param int $limit   Taille du lot (bornée [1, MAX_BATCH_SIZE])
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
        foreach ($candidates as $candidate) {
            $maxIdSeen = max($maxIdSeen, (int) $candidate->id);
            $row = $this->buildPreviewRow($candidate);
            $rows[] = $row;

            $rowStatus = isset($row['status']) ? (string) $row['status'] : 'unknown';
            $statusCounts[$rowStatus] = (isset($statusCounts[$rowStatus]) ? $statusCounts[$rowStatus] : 0) + 1;
        }

        // Story-mère (Doli2Shop discount_repair, même arbitrage) : UNE ligne de journal par LOT,
        // pas par commande — un clic « Aperçu » enchaîne toutes les pages de candidats, un journal
        // par commande scannée inonderait la table d'audit sur des catalogues de plusieurs
        // milliers de commandes.
        if (!empty($candidates)) {
            $breakdown = array();
            foreach ($statusCounts as $statusLabel => $statusCount) {
                $breakdown[] = $statusLabel . '=' . $statusCount;
            }
            $this->logAction(
                ActionLogger::RESULT_SKIPPED,
                0,
                'Aperçu (dry-run) : ' . count($candidates) . ' commande(s) analysée(s) ['
                    . implode(', ', $breakdown) . ']'
            );
        }

        return array(
            'rows' => $rows,
            'hasMore' => (count($candidates) === $limit),
            'nextCursor' => $maxIdSeen,
        );
    }

    /**
     * Passe D'AUDIT (confirm=1 côté appelant) : persiste le verdict de chaque commande candidate
     * du lot dans `underprice_audit_*` — JAMAIS de correction de commande/facture/paiement (AC3).
     * Idempotent : une commande déjà auditée n'est plus jamais recomptée (AC6, filtre
     * `underprice_audit_status IS NULL`).
     *
     * Verrou de réentrance PAR ENTITÉ (même pattern que `DiscountRepairService::repairBatch()`) :
     * acquisition non bloquante, libération en `finally`.
     *
     * @param int $afterId Curseur (dernier `do.id` vu, 0 au premier lot)
     * @param int $storeId Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param int $limit   Taille du lot (bornée [1, MAX_BATCH_SIZE])
     * @return array{processed:int,alreadyCorrect:int,manualRequired:int,manualReview:int,errors:int,errorDetails:array,totalDeltaTtc:float,hasMore:bool,nextCursor:int,lockBusy:bool}
     */
    public function auditBatch($afterId, $storeId = 0, $limit = self::DEFAULT_BATCH_SIZE)
    {
        $afterId = max(0, (int) $afterId);
        $limit = max(1, min((int) $limit, self::MAX_BATCH_SIZE));
        $storeId = (int) $storeId;

        $result = array(
            'processed' => 0,
            'alreadyCorrect' => 0,
            'manualRequired' => 0,
            'manualReview' => 0,
            'errors' => 0,
            'errorDetails' => array(),
            'totalDeltaTtc' => 0.0,
            // Edge Case Hunter (12/09) : un lot peut mélanger des commandes de PLUSIEURS boutiques
            // (storeId=0) dont les devises Shopify diffèrent (shopMoney = devise DE LA BOUTIQUE,
            // constante par boutique mais pas entre boutiques). Sommer 20 EUR + 15 USD dans
            // `totalDeltaTtc` produirait un total sans signification affiché comme s'il était dans
            // une seule devise. On trace ici les devises RÉELLEMENT comptées (même filtre que
            // countsTowardTotalDelta()) pour que l'appelant refuse d'afficher un total unique
            // quand elles divergent (cf. admin/underprice_audit.php).
            'totalDeltaCurrencies' => array(),
            'hasMore' => false,
            'nextCursor' => $afterId,
            'lockBusy' => false,
        );

        $lockName = 'underprice_audit_' . $this->entity;
        if (!$this->acquireCronLock($lockName)) {
            $result['lockBusy'] = true;
            $this->log('auditBatch() - verrou déjà détenu pour entity=' . $this->entity . ', lot ignoré', LOG_INFO);
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

    // =========================================================================
    // Implémentation interne
    // =========================================================================

    /**
     * Traite UNE commande candidate : fetch, classification, PERSISTANCE DU VERDICT SEUL (jamais
     * de correction), journalisation. Toute exception est catchée (non-blocage du lot).
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

            $parsedNote = $this->parseShopifyFinancialNote($commande->note_private);
            $classification = $this->classifyOrder((float) $commande->total_ttc, $parsedNote, (int) $commande->statut);

            $this->markAudited($orderRowId, $classification);

            $message = $this->buildAuditLogMessage($fkCommande, $classification);

            switch ($classification['status']) {
                case self::STATUS_ALREADY_CORRECT:
                    $result['alreadyCorrect']++;
                    break;
                case self::STATUS_MANUAL_REVIEW:
                    $result['manualReview']++;
                    break;
                default:
                    $result['manualRequired']++;
                    if (self::countsTowardTotalDelta($classification)) {
                        $result['totalDeltaTtc'] += (float) $classification['deltaTotalTtc'];
                        // Devise garantie non-null ici : countsTowardTotalDelta() n'accepte que les
                        // classifications avec deltaTotalTtc!==null, qui ne survient que pour une
                        // note parseable=true (donc currency toujours renseignée). Filet défensif
                        // 'EUR' conservé au cas où cette invariante serait un jour brisée ailleurs.
                        $deltaCurrency = !empty($classification['currency']) ? (string) $classification['currency'] : 'EUR';
                        if (!in_array($deltaCurrency, $result['totalDeltaCurrencies'], true)) {
                            $result['totalDeltaCurrencies'][] = $deltaCurrency;
                        }
                    }
                    break;
            }

            // RESULT_SKIPPED (jamais SUCCESS) : cet outil ne corrige jamais rien — même le verdict
            // "sous-évaluée, avoir requis" ne déclenche aucune action sur la commande elle-même.
            $this->logAction(ActionLogger::RESULT_SKIPPED, $fkCommande, $message);
        } catch (\Throwable $e) {
            $result['errors']++;
            $message = "Exception audit commande #$fkCommande: " . $e->getMessage();
            $result['errorDetails'][] = $message;
            $this->logAction(ActionLogger::RESULT_ERROR, $fkCommande, $message);
            $this->log('processOneCandidate() - ' . $message, LOG_ERR);
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
            'status' => self::STATUS_MANUAL_REVIEW,
            'reason' => 'order_fetch_failed',
            'recordedTotalTtc' => 0.0,
            'realTotalTtc' => null,
            'deltaTotalTtc' => null,
            'currency' => null,
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

            $parsedNote = $this->parseShopifyFinancialNote($commande->note_private);
            $classification = $this->classifyOrder((float) $commande->total_ttc, $parsedNote, (int) $commande->statut);

            $row['status'] = $classification['status'];
            $row['reason'] = $classification['reason'];
            $row['recordedTotalTtc'] = $classification['recordedTotalTtc'];
            $row['realTotalTtc'] = $classification['realTotalTtc'];
            $row['deltaTotalTtc'] = $classification['deltaTotalTtc'];
            $row['currency'] = $classification['currency'];
        } catch (\Throwable $e) {
            $row['reason'] = 'exception: ' . $e->getMessage();
        }

        return $row;
    }

    /**
     * Persiste le VERDICT (jamais une correction) sur `llx_doli2shop_orders` — idempotence (AC6)
     * + piste d'audit (AC5 : qui via `underprice_audit_fk_user`, quand via `underprice_audit_date`,
     * quels montants via les 3 colonnes `_ttc`).
     *
     * @param int   $orderRowId     Rowid `llx_doli2shop_orders.id`
     * @param array $classification Retour de classifyOrder()
     * @return void
     */
    private function markAudited($orderRowId, array $classification)
    {
        $now = function_exists('dol_now') ? dol_now() : time();
        $dateSql = method_exists($this->db, 'idate') ? $this->db->idate($now) : date('Y-m-d H:i:s', $now);

        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_orders SET";
        $sql .= " underprice_audit_status = '" . $this->db->escape($classification['status']) . "'";
        $sql .= ", underprice_audit_reason = '" . $this->db->escape($classification['reason']) . "'";
        $sql .= ", underprice_audit_date = '" . $this->db->escape($dateSql) . "'";
        $sql .= ", underprice_audit_fk_user = " . ($this->userId !== null && $this->userId > 0 ? (int) $this->userId : "NULL");
        $sql .= ", underprice_audit_recorded_ttc = " . ((float) $classification['recordedTotalTtc']);
        $sql .= ", underprice_audit_real_ttc = " . ($classification['realTotalTtc'] !== null ? (float) $classification['realTotalTtc'] : "NULL");
        $sql .= ", underprice_audit_delta_ttc = " . ($classification['deltaTotalTtc'] !== null ? (float) $classification['deltaTotalTtc'] : "NULL");
        $sql .= " WHERE id = " . (int) $orderRowId;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("markAudited() - Erreur marquage commande (doli2shop_orders.id=#$orderRowId): " . $this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Construit le message journalisé (ActionLogger) — formule EXPLICITEMENT l'action attendue
     * du client selon la raison (AC3) : jamais un bouton, un texte qui dit la marche à suivre.
     *
     * @param int   $fkCommande
     * @param array $classification
     * @return string
     */
    private function buildAuditLogMessage($fkCommande, array $classification)
    {
        // Edge Case Hunter (12/09) : le montant enregistré n'est PAS forcément en EUR — une
        // boutique Shopify peut être réglée dans une autre devise (shopMoney = devise DE LA
        // BOUTIQUE, note historique testée en CHF/USD ailleurs dans ce fichier). Un texte figé
        // "€" mentirait sur la devise réelle dans la piste d'audit (AC5), qui doit rester
        // exploitable pour préparer un avoir. Le code devise (ISO) n'est disponible QUE dans les
        // 3 raisons où un montant est effectivement imprimé (matches_note/committed/draft, cf.
        // classifyOrder() : ces 3 raisons impliquent toutes parseable=true, donc currency non
        // nul) — filet défensif 'EUR' conservé si cette invariante était un jour rompue.
        $currencyCode = !empty($classification['currency']) ? (string) $classification['currency'] : 'EUR';
        $recorded = number_format((float) $classification['recordedTotalTtc'], 2, ',', ' ');

        switch ($classification['reason']) {
            case self::REASON_MATCHES_NOTE:
                return "Commande #$fkCommande : montant conforme au récap Shopify ($recorded $currencyCode TTC) — aucune action";

            case self::REASON_NO_HISTORICAL_NOTE:
                return "Commande #$fkCommande : aucune note historique Shopify (antérieure au récap v2.3.0, ou note modifiée) — aucun oracle disponible, vérification manuelle requise";

            case self::REASON_NOTE_UNPARSEABLE:
                return "Commande #$fkCommande : note Shopify présente mais illisible (format inattendu) — revue manuelle requise";

            case self::REASON_MISMATCH_UNEXPLAINED:
                return "Commande #$fkCommande : écart au récap Shopify ne correspondant pas à la signature connue du défaut — revue manuelle requise";

            case self::REASON_UNDERPRICED_COMMITTED:
                $real = $classification['realTotalTtc'] !== null ? number_format((float) $classification['realTotalTtc'], 2, ',', ' ') : '?';
                $delta = $classification['deltaTotalTtc'] !== null ? number_format((float) $classification['deltaTotalTtc'], 2, ',', ' ') : '?';
                return "Commande #$fkCommande : sous-évaluée (enregistré $recorded $currencyCode TTC, réel $real $currencyCode TTC, écart $delta $currencyCode TTC) — commande verrouillée (validée/facturée/payée), signalée, traitement par avoir HORS DE L'OUTIL";

            case self::REASON_UNDERPRICED_DRAFT:
                $real = $classification['realTotalTtc'] !== null ? number_format((float) $classification['realTotalTtc'], 2, ',', ' ') : '?';
                $delta = $classification['deltaTotalTtc'] !== null ? number_format((float) $classification['deltaTotalTtc'], 2, ',', ' ') : '?';
                return "Commande #$fkCommande : sous-évaluée (enregistré $recorded $currencyCode TTC, réel $real $currencyCode TTC, écart $delta $currencyCode TTC) — brouillon non facturé, correction possible directement dans la commande (aucune action automatique de cet outil)";

            case self::REASON_UNDERPRICED_CANCELED:
                return "Commande #$fkCommande : sous-évaluée mais ANNULÉE — jamais facturée, aucune régularisation nécessaire";

            default:
                return "Commande #$fkCommande : verdict {$classification['status']} ({$classification['reason']})";
        }
    }

    /**
     * Récupère un lot paginé de commandes candidates (pagination PAR CLÉ sur `do.id`), filtré
     * entity + fk_store conditionnel (invariant §14) + `underprice_audit_status` (exclut les
     * commandes déjà auditées).
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
     * Requête de base commune à `countCandidateOrders()` et `fetchCandidateOrdersBatch()`. Aucun
     * pré-filtre heuristique (contrairement à `DiscountRepairService`) : on ne sait PAS à l'avance
     * quelles commandes viennent d'une boutique en prix hors taxes (règle : jamais de supposition,
     * cf. AC1 story-mère) — tout `llx_doli2shop_orders` non encore audité est candidat.
     *
     * @param string $selectClause Ex. "SELECT COUNT(*) as total" ou "SELECT do.id, do.fk_commande"
     * @param int    $storeId
     * @param int    $afterId      0 = pas de filtre curseur (utilisé pour le comptage)
     * @return string
     */
    private function buildCandidateBaseQuery($selectClause, $storeId, $afterId)
    {
        $sql = $selectClause . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders do";
        $sql .= " WHERE do.entity = " . (int) $this->entity;
        if ((int) $storeId > 0) {
            $sql .= " AND do.fk_store = " . (int) $storeId;
        }
        $sql .= " AND do.underprice_audit_status IS NULL";
        if ((int) $afterId > 0) {
            $sql .= " AND do.id > " . (int) $afterId;
        }

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
        ActionLogger::log($this->db, $this->entity, ActionLogger::TYPE_UNDERPRICE_AUDIT, 'commande', $fkCommande, $result, $message);
    }
}
