<?php
/**
 * @file        class/variantrepairservice.class.php
 * @brief       Réparation batch des imports existants en vraies déclinaisons Dolibarr (Hotfix 2.4.4, 2/2)
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.4.4
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/actionlogger.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/shopifyproductimporter.class.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}


/**
 * Class VariantRepairService
 *
 * Rattache a posteriori les produits déjà importés comme fiches Dolibarr séparées (reliées
 * seulement par `llx_doli2shop_products.fk_product_parent`) en VRAIES déclinaisons Dolibarr
 * (`ProductCombination`), sans réimporter ni dupliquer. Action admin EXPLICITE (JAMAIS
 * automatique à l'install/upgrade, cf. Story 52-2) — écrit dans les tables core produit.
 *
 * Réutilise STRICTEMENT `ShopifyProductImporter::linkDolibarrVariant()` (hotfix 2.4.4, 1/2) pour
 * la création de la déclinaison — aucune duplication de cette logique. Pour chaque candidat, les
 * options Shopify sont re-fetchées (non stockées en base) via le wrapper public
 * `fetchShopifyVariantContext()`.
 *
 * Multi-boutiques (CRITICAL, cf. Validate) : les candidats sont groupés par `fk_store` et un
 * `ShopifyProductImporter` DÉDIÉ est instancié PAR groupe de boutique (`createProductImporter()`,
 * factory mockable), avec gate licence `doli2shopStoreSyncAllowed()` AVANT tout re-fetch réseau —
 * jamais d'interrogation de la mauvaise boutique. `fk_store = 0` (chemin historique/fallback) =
 * `$store = null`, jamais bloqué (invariant rétrocompat, comme le CRON d'import produits).
 *
 * Pose SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS en garde de cohérence (try/finally + nesting guard,
 * pattern StockRecalageService::processBatch()) pendant le traitement du lot.
 */
class VariantRepairService
{
    use LoggerTrait;

    /** Taille de lot par défaut si l'appelant (AJAX) n'en précise pas */
    const DEFAULT_BATCH_SIZE = 20;

    /** Borne haute de sécurité sur $limit (paramètre utilisateur via AJAX) */
    const MAX_BATCH_SIZE = 100;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var User Utilisateur courant (admin déclenchant l'action, requis par linkDolibarrVariant) */
    private $user;

    /** @var int Entité Dolibarr courante */
    private $entity;

    /**
     * @param DoliDB   $db     Gestionnaire base de données
     * @param User     $user   Utilisateur courant (transmis à ShopifyProductImporter/Product::update)
     * @param int|null $entity Entité Dolibarr (null = entité courante $conf->entity)
     */
    public function __construct($db, $user, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->user = $user;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
    }

    /**
     * Indique si la réparation est pertinente (module Variants Dolibarr actif). Un module
     * désactivé ne bloque pas techniquement (linkDolibarrVariant() no-op propre), mais l'action
     * n'a alors aucun effet utile — exposé pour griser le bouton côté UI (pattern
     * StockRecalageService::isEnabled()).
     *
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) isModEnabled('variants');
    }

    /**
     * Compte le nombre de candidats de réparation, filtré entity + fk_store conditionnel
     * (invariant CLAUDE.dolibarr.md §14 : JAMAIS de filtre fk_store si storeId <= 0).
     *
     * Deux pools DÉCOUPLÉS (Story 63-17, Cause 2) :
     *  - Pool RATTACHEMENT (toujours compté) : lignes enfants (`fk_product_parent > 0`) qui n'ont
     *    PAS encore de vraie `ProductCombination` Dolibarr, hors irréparables permanents
     *    (`variant_repair_status = 1`, cf. correction post-review 2.4.4 2/2 — convergence).
     *  - Pool LIBELLÉS (compté seulement si $includeLabelOnly) : lignes DÉJÀ rattachées
     *    (`pac.rowid IS NOT NULL`) dont le libellé Dolibarr de l'enfant est resté identique à
     *    celui du parent — signature EXACTE de la régression Cause 1 (le chemin de mise à jour
     *    écrasait le libellé enfant avec le titre du parent). AC3 : ce pool n'est PLUS conditionné
     *    par `pac.rowid IS NULL` — un candidat déjà rattaché en vraie déclinaison doit pouvoir être
     *    corrigé. AC5 : ce pool n'est PAS non plus filtré par `variant_repair_status` — un
     *    candidat marqué irréparable AU RATTACHEMENT (qui n'a structurellement jamais de vraie
     *    combinaison créée, cf markPermanentlyUnfixable()) reste éligible à la correction de
     *    LIBELLÉ, les deux verrous sont indépendants.
     *
     * Idempotence "gratuite" pour le pool libellés : dès que fixVariantLabelIfNeeded() corrige le
     * libellé, `childp.label` ≠ `parentp.label` et la ligne sort naturellement du pool au prochain
     * comptage — aucun flag additionnel requis (AC6).
     *
     * @param int  $storeId          Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param bool $includeLabelOnly Inclure aussi le pool "libellés" (déjà rattaché, label==parent)
     * @return int
     * @since 2.5.2 paramètre $includeLabelOnly (Story 63-17)
     */
    public function countRepairCandidates($storeId = 0, $includeLabelOnly = false)
    {
        $storeId = (int) $storeId;
        $includeLabelOnly = (bool) $includeLabelOnly;

        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "doli2shop_products p";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON pac.fk_product_child = p.fk_product";
        if ($includeLabelOnly) {
            $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product childp ON childp.rowid = p.fk_product";
            $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product parentp ON parentp.rowid = p.fk_product_parent";
        }
        $sql .= " WHERE p.entity = " . (int) $this->entity;
        $sql .= " AND p.fk_product_parent > 0";
        if ($storeId > 0) {
            $sql .= " AND p.fk_store = " . $storeId;
        }
        $sql .= " AND ((pac.rowid IS NULL AND (p.variant_repair_status IS NULL OR p.variant_repair_status = 0))";
        if ($includeLabelOnly) {
            $sql .= " OR (pac.rowid IS NOT NULL AND childp.label = parentp.label)";
        }
        $sql .= ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log('countRepairCandidates() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return (int) (isset($obj->total) ? $obj->total : 0);
    }

    /**
     * Traite un lot de candidats (curseur = pagination PAR CLÉ sur `fk_product`, PAS un offset
     * numérique — cf. correction CRITICAL review 3-couches ci-dessous) : groupe par `fk_store`,
     * instancie un `ShopifyProductImporter` PAR boutique (gate licence AVANT tout re-fetch),
     * re-fetch les options Shopify de chaque variante et appelle `linkDolibarrVariant()`.
     * Idempotent (le helper skippe si déjà lié/déjà rattaché à un autre parent). JAMAIS
     * d'exception non catchée n'interrompt le lot.
     *
     * CRITICAL (correctifs post-review 3-couches, 2.4.4 2/2) — pagination par clé : le pool de
     * candidats RÉTRÉCIT au fil des réparations réussies (`pac.rowid IS NULL` / marquage
     * `variant_repair_status`). Un offset numérique croissant contre un pool qui rétrécit saute
     * des blocs entiers de candidats jamais traités (et peut faire `hasMore` faussement `false`).
     * Le curseur est donc désormais le plus grand `fk_product` VU dans le lot (peu importe le
     * sort de chaque candidat — réparé/skip/erreur/unfixable, on avance TOUJOURS au-delà) et
     * `hasMore` se déduit uniquement de la taille du lot renvoyé vs `$limit` demandée (jamais
     * d'un recomptage total, qui rétrécit lui aussi et redeviendrait un faux-négatif).
     *
     * Rapport aligné sur le pattern `StockRecalageService::processBatch()` (mêmes clés de base) —
     * `repaired` remplace `pushed`. `labelsFixed`/`unfixable` sont des clés additionnelles.
     *
     * @param int  $cursor    Curseur de pagination (dernier `fk_product` vu, 0 au premier lot)
     * @param int  $limit     Taille du lot (bornée [1, MAX_BATCH_SIZE])
     * @param int  $storeId   Rowid boutique (0 = toutes les boutiques de l'entité)
     * @param bool $fixLabels Recalcule aussi le libellé distinctif de l'enfant (étape séparée) —
     *                        Story 63-17 : pilote AUSSI l'inclusion du pool "libellés" (déjà
     *                        rattaché, label==parent) dans le lot fetché. Inutile de fetcher ces
     *                        lignes si $fixLabels est faux (rien ne serait tenté pour elles).
     * @return array{processed:int,repaired:int,skipped:int,errors:int,errorDetails:array,labelsFixed:int,unfixable:int,hasMore:bool,nextCursor:int}
     */
    public function repairBatch($cursor, $limit, $storeId = 0, $fixLabels = false)
    {
        $cursor = max(0, (int) $cursor);
        $limit = max(1, min((int) $limit, self::MAX_BATCH_SIZE));
        $storeId = (int) $storeId;
        $fixLabels = (bool) $fixLabels;

        $result = array(
            'processed' => 0,
            'repaired' => 0,
            'skipped' => 0,
            'errors' => 0,
            'errorDetails' => array(),
            'labelsFixed' => 0,
            'unfixable' => 0,
            'hasMore' => false,
            'nextCursor' => $cursor,
        );

        // GARDE-FOU SERVEUR (correctif post-review, complément du grisage UI via isEnabled()) :
        // si le module Variants Dolibarr est DÉSACTIVÉ, on ne traite RIEN (aucun fetch, aucun
        // traitement, aucun marquage). Sans cette garde, la branche no-op « module désactivé » de
        // linkDolibarrVariant() serait atteinte pour TOUS les candidats du lot → markPermanentlyUnfixable()
        // les exclurait DÉFINITIVEMENT du pool (mass mis-marking IRRÉVERSIBLE), alors que « module
        // désactivé » est un état CONDITIONNEL/transitoire : réactiver le module doit rendre ces
        // candidats à nouveau réparables. Le bouton UI est grisé quand le module est off, mais
        // l'endpoint AJAX ne re-vérifiait pas côté serveur — c'est la défense qui manquait.
        if (!$this->isEnabled()) {
            $this->log('repairBatch() - Module Variants désactivé, réparation ignorée (aucun marquage) — garde-fou serveur', LOG_WARNING);
            return $result;
        }

        $candidates = $this->fetchRepairCandidatesBatch($cursor, $storeId, $limit, $fixLabels);

        if (empty($candidates)) {
            return $result;
        }

        // Groupe par fk_store — CRITICAL multi-boutiques (Validate) : un ShopifyProductImporter
        // PAR boutique, jamais un seul importer réutilisé entre boutiques différentes.
        // Calcule dans la même boucle le plus grand fk_product vu dans CE lot (curseur suivant).
        $byStore = array();
        $maxFkProductSeen = $cursor;
        foreach ($candidates as $candidate) {
            $byStore[(int) $candidate->fk_store][] = $candidate;
            $maxFkProductSeen = max($maxFkProductSeen, (int) $candidate->fk_product);
        }

        // Garde de cohérence (nesting guard, try/finally) — pattern StockRecalageService::processBatch().
        global $conf;
        $flagAlreadySet = (bool) getDolGlobalInt('SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS');
        if (!$flagAlreadySet) {
            $conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS = 1;
        }

        try {
            foreach ($byStore as $storeRowId => $storeCandidates) {
                $this->processStoreGroup($storeRowId, $storeCandidates, $fixLabels, $result);
            }
        } finally {
            if (!$flagAlreadySet) {
                unset($conf->global->SHOPIFY_INTEGRATION_SYNC_IN_PROGRESS);
            }
        }

        // hasMore : uniquement basé sur la taille du lot renvoyé vs la limite demandée (jamais
        // un recomptage total — cf. docblock, CRITICAL corrigé).
        $result['hasMore'] = (count($candidates) === $limit);
        $result['nextCursor'] = $maxFkProductSeen;

        $this->persistBatchProgress($cursor, $result['repaired'], $result['errors']);

        return $result;
    }

    /**
     * Traite tous les candidats d'UNE boutique (ou du groupe fallback fk_store=0) : résout la
     * boutique, applique la gate licence, instancie l'importer dédié, puis traite chaque
     * candidat. Toute résolution impossible (boutique introuvable/non licenciée) fait skip TOUT
     * le groupe (jamais de repli silencieux vers une autre boutique).
     *
     * @param int    $storeRowId      Rowid boutique (0 = fallback historique)
     * @param array  $storeCandidates Candidats de ce groupe
     * @param bool   $fixLabels       Recalcule aussi le libellé distinctif
     * @param array  $result          Rapport cumulé (modifié par référence)
     * @return void
     */
    private function processStoreGroup($storeRowId, array $storeCandidates, $fixLabels, array &$result)
    {
        $storeRowId = (int) $storeRowId;
        $store = null;

        if ($storeRowId > 0) {
            $store = $this->fetchStoreForRepair($storeRowId);
            if ($store === null) {
                $this->skipGroup($storeCandidates, $result, "boutique #$storeRowId introuvable (supprimée ?)");
                return;
            }
        }

        $licenceCheck = $this->checkStoreLicence($store);
        if (empty($licenceCheck['allowed'])) {
            $reason = $licenceCheck['reason'] ?? 'unknown';
            $this->skipGroup($storeCandidates, $result, "boutique #$storeRowId non autorisée (licence: $reason)");
            return;
        }

        $importer = $this->createProductImporter($store);

        // Cache de dédup fetch par shopifyProductId — scope PAR GROUPE DE BOUTIQUE (jamais
        // partagé entre boutiques, même en cas de collision d'id numérique Shopify entre shops).
        $productCache = array();

        foreach ($storeCandidates as $candidate) {
            $this->processOneCandidate($importer, $candidate, $fixLabels, $productCache, $result);
        }
    }

    /**
     * Marque tous les candidats d'un groupe comme "skipped" avec un message commun (boutique non
     * résolue/non licenciée) — jamais de re-fetch réseau tenté pour ce groupe.
     *
     * @param array  $storeCandidates
     * @param array  $result
     * @param string $reason
     * @return void
     */
    private function skipGroup(array $storeCandidates, array &$result, $reason)
    {
        foreach ($storeCandidates as $candidate) {
            $result['processed']++;
            $result['skipped']++;
            $childId = (int) $candidate->fk_product;
            $message = "Produit #$childId : réparation ignorée — $reason";
            $result['errorDetails'][] = $message;
            $this->logAction(ActionLogger::RESULT_SKIPPED, $childId, $message);
        }
    }

    /**
     * Traite UN candidat : re-fetch (avec dédup cache produit) puis `linkDolibarrVariant()`,
     * puis `fixLabels` optionnel. Toute exception est catchée (non-blocage du lot).
     *
     * Correctifs post-review 3-couches (2.4.4 2/2, convergence) : les cas DÉTERMINISTES ET
     * PERMANENTS (mapping invalide avec id produit valide, `shopifyVariantId` manquant, parent
     * Dolibarr supprimé, aucune option Shopify distinctive exploitable) sont désormais comptés
     * dans le bucket `unfixable` et marqués `variant_repair_status = 1` — exclus définitivement
     * du pool (sans quoi le compteur de candidats ne convergeait jamais vers 0, cf.
     * `markPermanentlyUnfixable()`). À l'inverse, les échecs potentiellement TRANSITOIRES
     * (contexte Shopify introuvable = réseau/rate-limit possible, échec `linkDolibarrVariant()`)
     * restent dans `errors`, JAMAIS marqués permanents (retentables à la prochaine passe).
     *
     * @param ShopifyProductImporter $importer
     * @param object                 $candidate
     * @param bool                   $fixLabels
     * @param array                  $productCache Cache [shopifyProductId => produit Shopify|null] (par référence)
     * @param array                  $result       Rapport cumulé (par référence)
     * @return void
     */
    private function processOneCandidate($importer, $candidate, $fixLabels, array &$productCache, array &$result)
    {
        $result['processed']++;
        $childId = (int) $candidate->fk_product;
        $parentId = (int) $candidate->fk_product_parent;
        $shopifyProductId = (string) $candidate->shopifyProductId;
        $shopifyVariantId = $candidate->shopifyVariantId;

        try {
            if ($childId <= 0 || $parentId <= 0) {
                $message = "Produit #$childId : mapping invalide (parent=$parentId), réparation impossible";
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_SKIPPED, $childId, $message);
                // Marquage permanent UNIQUEMENT si l'identifiant produit est fiable (childId > 0)
                // — un fk_product corrompu à 0 ne peut pas être ciblé par UPDATE sans risque de
                // marquer d'autres lignes (skip simple, non bloquant, ne converge pas mais cas
                // théorique de données déjà corrompues, hors périmètre de ce correctif).
                if ($childId > 0) {
                    $result['unfixable']++;
                    $this->markPermanentlyUnfixable($childId);
                } else {
                    $result['skipped']++;
                }
                return;
            }

            if (empty($shopifyVariantId)) {
                $message = "Produit #$childId : shopifyVariantId manquant en mapping, réparation impossible automatiquement";
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_SKIPPED, $childId, $message);
                $result['unfixable']++;
                $this->markPermanentlyUnfixable($childId);
                return;
            }

            // Garde existence parent (CORRECTION review 3-couches) : llx_product_attribute_combination
            // n'a AUCUNE contrainte FK — ProductCombination::create() ferait un INSERT brut, créant
            // une combinaison ORPHELINE en silence si le parent a été supprimé entre-temps. Vérifié
            // AVANT tout re-fetch réseau (évite un appel Shopify inutile).
            $parentProduct = $this->createProduct();
            if ($parentProduct->fetch($parentId) <= 0) {
                $message = "Produit #$childId : parent #$parentId introuvable (supprimé), réparation impossible";
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_SKIPPED, $childId, $message);
                $result['unfixable']++;
                $this->markPermanentlyUnfixable($childId);
                return;
            }

            $cachedProduct = array_key_exists($shopifyProductId, $productCache) ? $productCache[$shopifyProductId] : null;
            $context = $importer->fetchShopifyVariantContext($shopifyProductId, $shopifyVariantId, $cachedProduct);

            if ($context === null) {
                // Produit/variante Shopify introuvable : NE JAMAIS marquer permanent — on ne peut
                // pas distinguer de façon fiable une suppression réelle côté Shopify d'une simple
                // erreur réseau/rate-limit transitoire. Reste en erreur, retenté à la prochaine passe.
                $result['errors']++;
                $message = "Produit #$childId : produit/variante Shopify introuvable (shopifyProductId=$shopifyProductId, shopifyVariantId=$shopifyVariantId) — non réparable automatiquement";
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_ERROR, $childId, $message);
                return;
            }

            // Dédup fetch : on ne mémorise le produit qu'après un match réussi (cf. docblock
            // fetchShopifyVariantContext() — évite un fetch réseau redondant pour les variantes
            // suivantes du MÊME produit Shopify dans ce lot).
            $productCache[$shopifyProductId] = $context['product'];

            $linked = $importer->linkDolibarrVariant($parentId, $childId, $context['selectedOptions']);
            if (!$linked) {
                // Échec applicatif (ex. erreur SQL création attribut/valeur/combinaison) :
                // potentiellement transitoire, JAMAIS marqué permanent.
                $result['errors']++;
                $message = "Produit #$childId : échec rattachement déclinaison — " . $importer->error;
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_ERROR, $childId, $message);
                return;
            }

            if ($importer->lastLinkWasNoop) {
                // No-op DÉTERMINISTE ET PERMANENT (module variants désactivé OU aucune option
                // Shopify distinctive exploitable) : linkDolibarrVariant() a renvoyé true SANS
                // rien écrire — ce n'est PAS une réparation réelle. Compter dans `repaired`
                // créait un faux « réparé » ET, comme aucune ProductCombination n'existe, le
                // candidat repassait le filtre `pac.rowid IS NULL` à CHAQUE passe (non-convergence,
                // compteur jamais à 0) — corrigé ici : bucket dédié + marqueur permanent.
                $result['unfixable']++;
                $message = "Produit #$childId : aucune option distinctive exploitable côté Shopify — non transformable en déclinaison Dolibarr";
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_SKIPPED, $childId, $message);
                $this->markPermanentlyUnfixable($childId);
                return;
            }

            // Story 63-17, correction post-review (MEDIUM-E) : `linkDolibarrVariant()` peut
            // renvoyer `true` SANS AVOIR RIEN ATTACHÉ DE NOUVEAU — cas devenu réellement
            // atteignable depuis que le pool "libellés" fournit des candidats DÉJÀ rattachés
            // (`pac.rowid IS NOT NULL`, cf `fetchRepairCandidatesBatch($includeLabelOnly)`).
            // AVANT cette distinction, ces candidats gonflaient `repaired` à chaque correction de
            // LIBELLÉ, sans qu'aucun rattachement n'ait eu lieu ("12 réparés" pour 12 corrections
            // de libellé, zéro rattachement réel).
            if ($importer->lastLinkWasAlreadyLinked) {
                $result['skipped']++;
                $message = "Produit #$childId : déjà rattaché au parent #$parentId, aucune nouvelle combinaison créée";
                $result['errorDetails'][] = $message;
                $this->logAction(ActionLogger::RESULT_SKIPPED, $childId, $message);
            } else {
                $result['repaired']++;
                $this->logAction(ActionLogger::RESULT_SUCCESS, $childId, "Produit #$childId rattaché au parent #$parentId (déclinaison Dolibarr créée)");
            }

            // LOW-H (code review) : un enfant rattaché à un AUTRE parent que celui attendu
            // (incohérence de données préexistante, `$importer->lastLinkWasWrongParent`) ne doit
            // JAMAIS voir son libellé recalculé ICI — il serait recalculé avec le titre du
            // MAUVAIS parent (`$context['product']` est le produit Shopify du parent ATTENDU,
            // pas celui réellement rattaché en base), aggravant l'incohérence au lieu de la
            // corriger. Ce cas nécessite une revue manuelle des données, hors périmètre de cette
            // correction automatique.
            if ($fixLabels && !$importer->lastLinkWasWrongParent) {
                if ($this->fixVariantLabelIfNeeded($importer, $childId, $parentProduct->label, $context['product'], $context['variant'], true)) {
                    $result['labelsFixed']++;
                }
            }
        } catch (\Throwable $e) {
            $result['errors']++;
            $message = "Exception réparation produit #$childId: " . $e->getMessage();
            $result['errorDetails'][] = $message;
            $this->logAction(ActionLogger::RESULT_ERROR, $childId, $message);
            $this->log('processOneCandidate() - ' . $message, LOG_ERR);
        }
    }

    /**
     * Étape SÉPARÉE et optionnelle (AC1.3) : recalcule le libellé distinctif de l'enfant
     * (`ShopifyProductImporter::buildVariantLabel()`) et le persiste UNIQUEMENT si le libellé
     * actuel de l'enfant est encore identique au titre du parent (signature du bug A.2) — ne
     * touche jamais un libellé déjà distinctif (ex. déjà corrigé, ou renommé manuellement).
     *
     * Correction post-review (MEDIUM) : n'autorise le recalcul QUE si la variante possède au
     * moins une option Shopify distinctive réelle (`$hasDistinctiveOptions`). Sans cette garde,
     * une variante no-op (toutes options 'Default Title'/vides) verrait `buildVariantLabel()`
     * retomber sur le titre variante/SKU et pourrait écraser un libellé par pure coïncidence.
     * En pratique `processOneCandidate()` ne tente déjà plus `fixLabels` pour ces candidats
     * (retour anticipé sur `lastLinkWasNoop`) — cette garde reste défensive (défense en profondeur,
     * effective même si un futur appelant omettait ce contrôle en amont).
     *
     * @param ShopifyProductImporter $importer
     * @param int                    $childProductId
     * @param string                 $parentDolibarrLabel   Libellé Dolibarr ACTUEL du produit
     *                                                       PARENT (PAS le titre Shopify live,
     *                                                       cf HIGH-C ci-dessous)
     * @param object                 $shopifyProduct        Produit Shopify (->title), utilisé
     *                                                       uniquement pour CONSTRUIRE le nouveau
     *                                                       libellé via buildVariantLabel()
     * @param object                 $variant               Variante Shopify (->selectedOptions, ->title)
     * @param bool                   $hasDistinctiveOptions Au moins une option Shopify distinctive réelle (non 'Default Title'/vide)
     * @return bool true si le libellé a été corrigé, false sinon (déjà correct, pas d'option distinctive, ou échec non bloquant)
     * @since 2.5.2 paramètre $parentDolibarrLabel remplace la comparaison au titre Shopify live (Story 63-17, HIGH-C)
     */
    private function fixVariantLabelIfNeeded($importer, $childProductId, $parentDolibarrLabel, $shopifyProduct, $variant, $hasDistinctiveOptions = true)
    {
        if (!$hasDistinctiveOptions) {
            $this->log("fixVariantLabelIfNeeded() - Produit #$childProductId sans option distinctive réelle, libellé non touché (garde MEDIUM post-review)", LOG_DEBUG);
            return false;
        }

        try {
            $childProduct = $this->createProduct();
            if ($childProduct->fetch($childProductId) <= 0) {
                $this->log("fixVariantLabelIfNeeded() - Produit #$childProductId introuvable, fixLabels ignoré", LOG_WARNING);
                return false;
            }

            // Story 63-17, correction post-review (HIGH-C, convergence) : la garde compare
            // désormais le libellé Dolibarr de l'ENFANT à celui du PARENT DOLIBARR
            // ($parentDolibarrLabel, fourni par l'appelant) — EXACTEMENT le même critère que la
            // sélection SQL du pool "libellés" (`childp.label = parentp.label`, cf
            // countRepairCandidates()/fetchRepairCandidatesBatch()). AVANT ce correctif, la
            // comparaison se faisait contre `$shopifyProduct->title`, le titre Shopify LIVE
            // re-fetché — une source DIFFÉRENTE de celle qui a sélectionné le candidat. Si le
            // libellé Dolibarr du parent était désynchronisé du titre Shopify live, deux issues
            // mauvaises : (a) le candidat entrait dans le pool puis échouait silencieusement ICI
            // à CHAQUE passe (jamais corrigé, jamais sorti du pool) ; (b) dès que le parent
            // Dolibarr se resynchronisait, la ligne sortait du pool SANS avoir jamais été
            // corrigée (libellé buggé figé pour toujours, invisible aux deux pools). Faire
            // converger les deux critères sur la MÊME source (Dolibarr-Dolibarr) élimine ces deux
            // issues : il ne reste qu'une fenêtre de course standard (sélection SQL puis
            // re-vérification ici, quelques instants plus tard, pattern normal de tout traitement
            // par lot) — pas une divergence structurelle entre deux sources différentes.
            if ($childProduct->label !== $parentDolibarrLabel) {
                // Déjà distinctif (pas la signature du bug Cause 1) — ne rien toucher.
                return false;
            }

            $sku = $childProduct->ref;
            $newLabel = $importer->buildVariantLabel($shopifyProduct, $variant, $sku);
            if ($newLabel === $childProduct->label) {
                return false;
            }

            $childProduct->label = $newLabel;
            $updateResult = $childProduct->update($childProduct->id, $this->user);
            if ($updateResult < 0) {
                $this->log("fixVariantLabelIfNeeded() - Échec mise à jour libellé produit #$childProductId: " . implode(', ', (array) $childProduct->errors), LOG_WARNING);
                return false;
            }

            $this->log("fixVariantLabelIfNeeded() - Libellé corrigé produit #$childProductId: \"$newLabel\"", LOG_INFO);
            return true;
        } catch (\Throwable $e) {
            // Non-bloquant : fixLabels est une amélioration best-effort, jamais un motif d'échec
            // du rattachement (déjà réussi à ce stade).
            $this->log("fixVariantLabelIfNeeded() - Exception non bloquante produit #$childProductId: " . $e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Persiste l'état « dernière réparation » côté SERVEUR à la fin de chaque lot, en cumulant
     * sur la passe en cours (pattern StockRecalageService::persistBatchProgress()) — au premier
     * lot de la passe (curseur = 0) on (ré)initialise, aux lots suivants on ajoute au cumul déjà
     * persisté. Le curseur initial d'une passe reste 0 (pagination par clé sur `fk_product`,
     * cf. `repairBatch()`), donc la condition `=== 0` reste sémantiquement identique.
     *
     * @param int $cursor       Curseur du lot courant (0 = début de passe → réinitialise le cumul)
     * @param int $batchRepaired Produits réparés dans CE lot
     * @param int $batchErrors  Erreurs dans CE lot
     * @return void
     */
    private function persistBatchProgress($cursor, $batchRepaired, $batchErrors)
    {
        $now = function_exists('dol_now') ? dol_now() : time();
        $dateSql = method_exists($this->db, 'idate') ? $this->db->idate($now) : date('Y-m-d H:i:s', $now);

        if ((int) $cursor === 0) {
            $count = (int) $batchRepaired;
            $errors = (int) $batchErrors;
        } else {
            $count = (function_exists('getDolGlobalInt') ? getDolGlobalInt('DOLI2SHOP_LAST_VARIANT_REPAIR_COUNT') : 0) + (int) $batchRepaired;
            $errors = (function_exists('getDolGlobalInt') ? getDolGlobalInt('DOLI2SHOP_LAST_VARIANT_REPAIR_ERRORS') : 0) + (int) $batchErrors;
        }

        $this->setConst('DOLI2SHOP_LAST_VARIANT_REPAIR', $dateSql);
        $this->setConst('DOLI2SHOP_LAST_VARIANT_REPAIR_COUNT', $count);
        $this->setConst('DOLI2SHOP_LAST_VARIANT_REPAIR_ERRORS', $errors);
    }

    // =========================================================================
    // Collaborateurs mockables (onlyMethods() — isolation des tests unitaires)
    // =========================================================================

    /**
     * Factory boutique par boutique (CRITICAL multi-boutiques) — un ShopifyProductImporter DÉDIÉ
     * par groupe, jamais réutilisé entre boutiques différentes.
     *
     * @param object|null $store Boutique (StoreService::fetch()) ou null (chemin fallback historique)
     * @return ShopifyProductImporter
     */
    protected function createProductImporter($store)
    {
        return new ShopifyProductImporter($this->db, $this->user, $this->entity, $store);
    }

    /**
     * Factory Product — mockable pour les tests (fixLabels a besoin de contrôler
     * ->label/->ref/->update() sans dépendre d'un registre en mémoire).
     *
     * @return Product
     */
    protected function createProduct()
    {
        dol_include_once('/product/class/product.class.php');
        return new Product($this->db);
    }

    /**
     * @return StoreService
     */
    protected function createStoreService()
    {
        return new StoreService($this->db, $this->entity);
    }

    /**
     * @param int $storeRowId
     * @return object|null
     */
    protected function fetchStoreForRepair($storeRowId)
    {
        return $this->createStoreService()->fetch((int) $storeRowId);
    }

    /**
     * Gate licence par boutique — wrapper mockable autour de la fonction globale
     * `doli2shopStoreSyncAllowed()` (pattern shopifyproductimportcron.class.php:147).
     *
     * @param object|null $store
     * @return array{allowed:bool,status:string,reason:string}
     */
    protected function checkStoreLicence($store)
    {
        return doli2shopStoreSyncAllowed($store);
    }

    /**
     * Récupère un lot paginé de candidats (mapping enfant SANS vraie ProductCombination),
     * filtré entity + fk_store conditionnel (invariant §14) + `variant_repair_status` (exclut
     * les irréparables permanents déjà marqués).
     *
     * CRITICAL (correctif post-review 3-couches, pagination par CLÉ) : `$afterFkProduct` remplace
     * un offset numérique — filtre `p.fk_product > $afterFkProduct` (jamais d'`OFFSET`). Le pool
     * (`pac.rowid IS NULL` / `variant_repair_status = 0`) rétrécit au fil des réparations
     * réussies ; un `OFFSET` croissant contre un pool qui rétrécit sauterait des blocs entiers de
     * candidats jamais traités (bug corrigé ici — cf. docblock `repairBatch()`).
     *
     * Depuis la Story 63-17 : le pool peut être élargi (`$includeLabelOnly`) au pool "libellés"
     * (déjà rattaché, label enfant == label parent — cf `countRepairCandidates()` pour le détail
     * des deux pools et pourquoi ils sont désormais découplés). `processOneCandidate()` n'a besoin
     * d'AUCUNE distinction supplémentaire pour ces lignes : `linkDolibarrVariant()` est déjà
     * idempotent (retourne `true` sans rien écrire si la combinaison existe déjà, cf son
     * docblock), et `fixVariantLabelIfNeeded()` ne touche que les libellés encore bugués — les
     * deux pools peuvent donc être traités par le MÊME code, sans branche supplémentaire.
     *
     * @param int  $afterFkProduct   Curseur : ne renvoie que les candidats avec fk_product STRICTEMENT supérieur (0 = premier lot)
     * @param int  $storeId
     * @param int  $limit
     * @param bool $includeLabelOnly Inclure aussi le pool "libellés" (cf countRepairCandidates())
     * @return object[] Lignes {fk_product, fk_product_parent, fk_store, shopifyProductId, shopifyVariantId}, triées par fk_product ASC
     * @since 2.5.2 paramètre $includeLabelOnly (Story 63-17)
     */
    protected function fetchRepairCandidatesBatch($afterFkProduct, $storeId, $limit, $includeLabelOnly = false)
    {
        $storeId = (int) $storeId;
        $includeLabelOnly = (bool) $includeLabelOnly;

        $sql = "SELECT p.fk_product, p.fk_product_parent, p.fk_store, p.shopifyProductId, p.shopifyVariantId";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_products p";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON pac.fk_product_child = p.fk_product";
        if ($includeLabelOnly) {
            $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product childp ON childp.rowid = p.fk_product";
            $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product parentp ON parentp.rowid = p.fk_product_parent";
        }
        $sql .= " WHERE p.entity = " . (int) $this->entity;
        $sql .= " AND p.fk_product_parent > 0";
        if ($storeId > 0) {
            $sql .= " AND p.fk_store = " . $storeId;
        }
        $sql .= " AND ((pac.rowid IS NULL AND (p.variant_repair_status IS NULL OR p.variant_repair_status = 0))";
        if ($includeLabelOnly) {
            $sql .= " OR (pac.rowid IS NOT NULL AND childp.label = parentp.label)";
        }
        $sql .= ")";
        $sql .= " AND p.fk_product > " . (int) $afterFkProduct;
        $sql .= " ORDER BY p.fk_product ASC";
        $sql .= $this->db->plimit((int) $limit, 0);

        $candidates = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $candidates[] = $obj;
            }
            $this->db->free($resql);
        } else {
            $this->log('fetchRepairCandidatesBatch() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
        }
        return $candidates;
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
     * Marque un candidat comme irréparable de façon PERMANENTE (`variant_repair_status = 1`) —
     * l'exclut définitivement du pool (`countRepairCandidates()`/`fetchRepairCandidatesBatch()`),
     * sans quoi un cas déterministe et permanent (mapping invalide, aucune option distinctive,
     * parent supprimé) reviendrait indéfiniment et le compteur ne convergerait jamais vers 0.
     * JAMAIS appelé pour un échec potentiellement transitoire (réseau, rate-limit, licence
     * boutique) — cf. commentaires `processOneCandidate()`.
     *
     * @param int $childProductId Id Dolibarr du produit enfant (doit être > 0)
     * @return void
     */
    private function markPermanentlyUnfixable($childProductId)
    {
        $childProductId = (int) $childProductId;
        if ($childProductId <= 0) {
            return;
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " SET variant_repair_status = 1";
        $sql .= " WHERE fk_product = " . $childProductId;
        $sql .= " AND entity = " . (int) $this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("markPermanentlyUnfixable() - Erreur requête produit #$childProductId: " . $this->db->lasterror(), LOG_ERR);
        }
    }

    /**
     * Journalise le résultat d'un candidat (ActionLogger — non-bloquant par construction).
     *
     * @param string $result
     * @param int    $dolibarrProductId
     * @param string $message
     * @return void
     */
    private function logAction($result, $dolibarrProductId, $message)
    {
        ActionLogger::log($this->db, $this->entity, ActionLogger::TYPE_VARIANT_REPAIR, 'product', $dolibarrProductId, $result, $message);
    }
}
