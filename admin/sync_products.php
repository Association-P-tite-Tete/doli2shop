<?php
/**
 * @file        admin/sync_products.php
 * @brief       Manual product synchronization interface with AJAX batch processing and detailed report
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.0.22
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/doli2shop/lib/doli2shop.lib.php');
dol_include_once('/doli2shop/class/shopifyapi.class.php');
dol_include_once('/doli2shop/class/configurationMigrator.class.php');
dol_include_once('/doli2shop/class/storeservice.class.php');
dol_include_once('/doli2shop/class/stockrecalageservice.class.php');
dol_include_once('/doli2shop/class/variantrepairservice.class.php');
dol_include_once('/doli2shop/class/stockpushqueueservice.class.php');
dol_include_once('/doli2shop/class/productscopehelper.class.php');

// Traductions
// Story 58-1 : "errors" ajouté pour ErrorSQL (relance dead-letter), pattern admin/webhook_events.php
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Accès
if (!$user->admin) {
    accessforbidden();
}

// Classes
// Contexte boutique (Story 49-9)
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
// Boutique secondaire = API scopée boutique ; défaut/null = chemin global strictement inchangé
$currentAdminStoreIsSecondary = ($currentAdminStore !== null && empty($currentAdminStore->is_default));
$shopifyApi = $currentAdminStoreIsSecondary ? ShopifyApi::forStore($db, $currentAdminStore) : new ShopifyApi($db);

// Récupérer la catégorie configurée depuis la base de données
$config = $shopifyApi->getConfig();
$defaultCategoryId = isset($config->dolibarr_procate) ? (int) $config->dolibarr_procate : 0;

// Story 63-19 (AC1) : périmètre catégorie = ARBRE complet (racine + descendants), calculé par le
// helper PARTAGÉ avec le CRON (class/importproducts.class.php), ajax/sync_products_batch.php et
// ajax/search_products.php — avant cette story, cet écran filtrait "catégorie exacte" (cp.fk_categorie
// = $defaultCategoryId), plus restrictif que le CRON qui traite l'arbre : un produit rangé dans une
// sous-catégorie était invisible ici ("aucun produit à synchroniser") tout en étant déjà poussé par
// le CRON — symptôme confirmé sur données de production (dossier Europe Loisirs, 23/08/2026).
// CRITICAL 1 (code review 63-19) : ce calcul tournait auparavant SANS aucun try/catch, AVANT
// llxHeader() — une catégorie cyclique dans fk_parent (corruption/migration/saisie manuelle)
// faisait dépasser `cte_max_recursion_depth` côté MySQL 8+, et l'exception résultante rendait
// cette page ADMIN totalement inaccessible, y compris pour venir corriger la configuration. Le
// helper est désormais robuste au cycle par lui-même (borne de profondeur explicite, cf.
// ProductScopeHelper::MAX_CATEGORY_TREE_DEPTH) ; ce try/catch reste un FILET pour toute autre
// erreur SQL imprévue — jamais une page cassée, un périmètre vide au pire.
$productScopeHelper = new ProductScopeHelper($db);
try {
    $scopeCategoryIds = $productScopeHelper->getCategoryTreeIds($defaultCategoryId, $conf->entity);
} catch (Exception $e) {
    dol_syslog("admin/sync_products.php: category tree computation failed (" . $e->getMessage() . ") — falling back to an EMPTY scope, never the full catalog", LOG_ERR);
    $scopeCategoryIds = array();
}
$productScopeHelper->logEmptyScopeDiagnostics($defaultCategoryId, $conf->entity, $scopeCategoryIds);
$scopeCategoryInClause = ProductScopeHelper::buildCategoryInClause($scopeCategoryIds);

// Vérifier la configuration
$migrator = new ConfigurationMigrator($db);
$configArray = $migrator->getConfiguration($conf->entity);

if (empty($configArray) ||
    empty($configArray['shopify_store_hostname']) ||
    empty($configArray['shopify_access_token']) ||
    empty($configArray['shopify_api_key'])) {
    header("Location: " . DOL_URL_ROOT . "/doli2shop/admin/setup.php");
    exit;
}

// v2.2.0: No more synchronous POST action — all sync is AJAX batch via ajax/sync_products_batch.php

// Story 52-2 : recalage initial en masse du stock Dolibarr -> Shopify (scope boutique cohérent
// avec le reste de la page — Story 49-9)
$recalageStoreId = $currentAdminStoreIsSecondary ? (int) $currentAdminStore->rowid : 0;
$recalageService = new StockRecalageService($db, $conf->entity);
$recalageEnabled = $recalageService->isEnabled();
$recalageMappedCount = $recalageService->countMappedProducts($recalageStoreId);

$lastRecalageDate = getDolGlobalString('DOLI2SHOP_LAST_STOCK_RECALAGE', '');
$lastRecalageCount = getDolGlobalInt('DOLI2SHOP_LAST_STOCK_RECALAGE_COUNT', 0);
$lastRecalageErrorsCount = getDolGlobalInt('DOLI2SHOP_LAST_STOCK_RECALAGE_ERRORS', 0);
if (!empty($lastRecalageDate)) {
    $lastRecalageSummary = $langs->trans(
        "StockRecalageLastRunSummary",
        dol_print_date($db->jdate($lastRecalageDate), 'dayhour'),
        (string) $lastRecalageCount,
        (string) $lastRecalageErrorsCount
    );
} else {
    $lastRecalageSummary = $langs->trans("StockRecalageLastRunNever");
}
$recalageConfirmMessage = $langs->trans("StockRecalageConfirm", (string) $recalageMappedCount);

// Hotfix 2.4.4 (2/2) : réparation batch des imports existants en vraies déclinaisons Dolibarr
// (scope boutique cohérent avec le reste de la page — Story 49-9)
$repairStoreId = $recalageStoreId;
$repairService = new VariantRepairService($db, $user, $conf->entity);
$repairEnabled = $repairService->isEnabled();
// Story 63-17 (AC4) : includeLabelOnly=true — le compteur/bouton initiaux doivent refléter le
// pool "libellés" MÊME quand le pool "rattachement" est à 0 (scénario exact du rapport client :
// des variantes déjà rattachées dont le libellé est resté celui du parent). Sans ce paramètre, le
// bouton resterait grisé alors que des libellés restent à corriger.
//
// Correction post-code-review (HIGH-A) : $repairCandidateCount reste le total COMBINÉ (pilote le
// grisage du bouton — AC4, "0 sur les deux axes" doit rester grisé). Mais AFFICHER ce seul total
// combiné, et bâtir le message de confirmation dessus comme avant ce correctif, c'est exactement
// ce qui produisait le défaut : la case "Corriger aussi les libellés" est DÉCOCHÉE par défaut
// (`admin/sync_products.php`, checkbox ci-dessous), et un clic dans cet état ne traite QUE le pool
// "rattachement" ($repairAttachOnlyCount) — jamais le pool "libellés". Décomposer les deux pools
// ici permet : 1) d'afficher séparément combien restent à rattacher / à corriger (l'utilisateur
// voit POURQUOI le compteur combiné peut ne rien faire si la case reste décochée) ; 2) de bâtir
// un message de confirmation qui ne parle JAMAIS de "rattacher" quand le lot réellement traité
// (case décochée) est 100% libellés.
$repairAttachOnlyCount = $repairService->countRepairCandidates($repairStoreId, false);
$repairCandidateCount = $repairService->countRepairCandidates($repairStoreId, true);
$repairLabelOnlyCount = max(0, $repairCandidateCount - $repairAttachOnlyCount);

$lastRepairDate = getDolGlobalString('DOLI2SHOP_LAST_VARIANT_REPAIR', '');
$lastRepairCount = getDolGlobalInt('DOLI2SHOP_LAST_VARIANT_REPAIR_COUNT', 0);
$lastRepairErrorsCount = getDolGlobalInt('DOLI2SHOP_LAST_VARIANT_REPAIR_ERRORS', 0);
if (!empty($lastRepairDate)) {
    $lastRepairSummary = $langs->trans(
        "VariantRepairLastRunSummary",
        dol_print_date($db->jdate($lastRepairDate), 'dayhour'),
        (string) $lastRepairCount,
        (string) $lastRepairErrorsCount
    );
} else {
    $lastRepairSummary = $langs->trans("VariantRepairLastRunNever");
}
// HIGH-A : trois messages de confirmation, un par composition de lot possible — le JS (plus bas)
// choisit celui qui correspond à l'état RÉEL de la case au moment du clic, jamais un seul message
// figé qui annoncerait "rattacher" un lot 100% libellés (défaut exact relevé par la review).
$repairConfirmMessage = $langs->trans("VariantRepairConfirm", (string) $repairAttachOnlyCount);
$repairConfirmLabelsOnlyMessage = $langs->trans("VariantRepairConfirmLabelsOnly", (string) $repairLabelOnlyCount);
$repairConfirmBothMessage = $langs->trans("VariantRepairConfirmBoth", (string) $repairAttachOnlyCount, (string) $repairLabelOnlyCount);

// Story 58-1 : observabilité de la file de push stock asynchrone (52-4) + relance dead-letter.
// Pattern UI tranché au Validate : checkboxes + action POST (admin/webhook_events.php, Story
// 36.2), PAS le pattern AJAX-progress ci-dessus — disproportionné pour une file dead-letter
// bornée. Toutes les requêtes sont émises DEPUIS cette page admin uniquement (AC4/point 9 :
// aucun impact sur le trigger STOCK_MOVEMENT ni sur StockPushQueueService::enqueue()).
$stockPushQueueService = new StockPushQueueService($db, $conf->entity);

$action = GETPOST('action', 'aZ09');

// Story 59-2 (AC1/AC2), détecté par le garde-fou lors du forward-port sur 2.5.0 : cette action
// sautait son bloc EN SILENCE sur un jeton refusé (aucun message, aucune trace, aucun effet) —
// même classe de défaut que les 30 actions corrigées en 2.4.8. La garde était de surcroît écrite
// sur une variable propre plutôt que sur `$action`, ce qui empêchait le
// garde-fou de résoudre sa portée : la comparaison se fait donc désormais sur `$action`.
if ($action === 'requeue_stock_push_queue' && !verifToken()) {
    dol_syslog("sync_products.php: action 'requeue_stock_push_queue' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action === 'requeue_stock_push_queue' && $user->admin && verifToken()) {
    $queueIds = GETPOST('queue_ids', 'array');
    // Sanitization défensive côté page en plus de celle du service (défense en profondeur) :
    // GETPOST('array') ne garantit que la forme, pas le contenu.
    $nbRequeued = $stockPushQueueService->requeueDeadLetter(is_array($queueIds) ? $queueIds : array());
    if ($nbRequeued > 0) {
        setEventMessages($langs->trans("StockPushQueueRequeueDone", (string) $nbRequeued), null, 'mesgs');
    } elseif ($nbRequeued === 0) {
        setEventMessages($langs->trans("StockPushQueueRequeueNone"), null, 'warnings');
    } else {
        setEventMessages($langs->trans("ErrorSQL"), null, 'errors');
    }
    header('Location: ' . $_SERVER["PHP_SELF"]);
    exit;
}

// Compteurs + alerte de retard (AC1/AC2) — de simples COUNT(*), aucun impact sur le chemin
// critique (invariant 52-4, cf. commentaire de section StockPushQueueService).
$stockPushQueueCounts = $stockPushQueueService->getQueueCounts();
$stockPushQueueAlertThresholdMin = getDolGlobalInt('DOLI2SHOP_STOCK_PUSH_QUEUE_ALERT_THRESHOLD_MIN', 30);
if ($stockPushQueueAlertThresholdMin < 1) {
    $stockPushQueueAlertThresholdMin = 30;
}
$stockPushQueueStaleCount = $stockPushQueueService->countStalePending($stockPushQueueAlertThresholdMin);

// Dead-letter (AC3) : la résolution du libellé produit se fait ICI, jamais dans le service
// (piège cross-entity régression classe 54-2, Résultat Validate point 7) — requête directe
// filtrée par entity, pas de Product::fetch().
$stockPushQueueDeadLetterItems = $stockPushQueueService->getDeadLetterItems(50);
$stockPushQueueProductLabels = array();
if (!empty($stockPushQueueDeadLetterItems)) {
    $deadLetterProductIds = array_unique(array_map(function ($item) {
        return (int) $item['fk_product'];
    }, $stockPushQueueDeadLetterItems));
    $deadLetterProductIds = array_filter($deadLetterProductIds, function ($id) {
        return $id > 0;
    });
    if (!empty($deadLetterProductIds)) {
        $sqlDeadLetterLabels = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product";
        $sqlDeadLetterLabels .= " WHERE rowid IN (" . implode(',', array_map('intval', $deadLetterProductIds)) . ")";
        $sqlDeadLetterLabels .= " AND entity = " . (int) $conf->entity;
        $resDeadLetterLabels = $db->query($sqlDeadLetterLabels);
        if ($resDeadLetterLabels) {
            while ($objDeadLetterLabel = $db->fetch_object($resDeadLetterLabels)) {
                $stockPushQueueProductLabels[(int) $objDeadLetterLabel->rowid] = $objDeadLetterLabel->ref . ' - ' . $objDeadLetterLabel->label;
            }
            $db->free($resDeadLetterLabels);
        }
    }
}

/**
 * View
 */
$form = new Form($db);

llxHeader('', $langs->trans("ManualProductSync"));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class="doli2shop-page">';
$head = doli2shopAdminPrepareHead();

// Titre avec lien de retour
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("Doli2ShopSetup"), $linkback, 'doli2shop@doli2shop');

// Onglets
print dol_get_fiche_head($head, 'sync', '', -1, '');

// Barre de contexte boutique (Story 49-10)
doli2shopRenderAdminTopBar($db);

// Encart boutique ciblée (Story 49-9)
if ($currentAdminStore !== null) {
    print '<div class="d2s-alert-banner d2s-alert--info" style="margin: 8px 0 16px;">';
    print '<i class="fas fa-store"></i> ';
    print dol_escape_htmltag($langs->trans("SyncManualForStore")) . ' ';
    print '<strong>' . dol_escape_htmltag($currentAdminStore->label) . '</strong>';
    if (!empty($currentAdminStore->shop_domain)) {
        print ' <span class="opacitymedium">(' . dol_escape_htmltag($currentAdminStore->shop_domain) . ')</span>';
    }
    print '</div>';
}

// CSS personnalisé
?>
<!-- Inclusion du CSS du module (fichier dynamique PHP — hotfix v2.2.3) -->
<link rel="stylesheet" type="text/css" href="<?php echo dol_buildpath('/doli2shop/css/doli2shop.css.php', 1); ?>">

<div class="sync-container">
    <h2><?php echo $langs->trans("ManualProductSync"); ?></h2>

    <?php
    // Direction configurée
    $configuredDirection = getDolGlobalString('DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', '');
    $showDolibarrStats = ($configuredDirection == 'dolibarr_to_shopify' || $configuredDirection == 'both' || empty($configuredDirection));
    ?>

    <!-- v2.2.0: Statistiques Dolibarr → Shopify -->
    <div id="stats-dolibarr" style="<?php echo $showDolibarrStats ? '' : 'display:none;'; ?>">
    <div class="sync-stats-unified">
        <h3><i class="fa fa-chart-bar"></i> <?php echo $langs->trans("SyncStatistics"); ?></h3>
        <?php
        // Statistiques détaillées unifiées
        $total_products = 0;
        $total_synced = 0;
        $products_with_variants = 0;
        $total_variants = 0;
        $simple_products = 0;

        // HIGH 5 (code review 63-19) : la requête tourne désormais TOUJOURS, catégorie configurée
        // ou pas — avant ce correctif, "catégorie non configurée" (defaultCategoryId <= 0) sautait
        // ce bloc entier et affichait "0 à synchroniser" de façon accidentelle, alors que
        // ajax/sync_products_batch.php (avant son propre correctif HIGH 5) synchronisait
        // silencieusement TOUT LE CATALOGUE dans ce même cas, et le CRON zéro — trois
        // comportements différents pour la même configuration, exactement le symptôme que cette
        // story corrige par ailleurs. `$scopeCategoryInClause` vaut "(0)" (jamais rien) quand la
        // racine n'est pas configurée (ProductScopeHelper::buildCategoryInClause([])) : cette
        // requête retourne alors 0 pour la BONNE raison, la même que les 3 autres sites — voir le
        // bandeau d'avertissement explicite plus bas.

        // Story 63-16 (AC1, AC3, AC4) : la clé unique de llx_doli2shop_products est
        // (fk_product, entity, fk_product_parent, fk_store) depuis l'Epic 47-2 — un produit
        // synchronisé sur N boutiques y a N lignes. Le LEFT JOIN ci-dessous portait ON dsp.fk_product
        // = p.rowid SANS AUCUN FILTRE : les 8 agrégats SUM(CASE WHEN dsp...) comptaient donc une
        // fois PAR LIGNE DE LIAISON, pas une fois par produit (COUNT(DISTINCT p.rowid) ci-dessus
        // était le seul agrégat immunisé). Le filtre va dans le ON, jamais le WHERE : un WHERE
        // transformerait de facto ce LEFT JOIN en INNER JOIN et ferait disparaître les produits NON
        // synchronisés dans la boutique consultée de not_synced_simple / not_synced_with_variants
        // (on corrigerait synced_* en cassant not_synced_*).
        //
        // CRITICAL (code review 63-16, 24/08) : un filtre dans le ON ne suffit pas — il filtre les
        // LIGNES qui matchent, mais ne déduplique rien. Un produit qui a À LA FOIS une ligne
        // fk_store=0 (legacy pré-backfill Epic 47) ET une ligne fk_store=<id boutique par défaut>
        // (post-backfill) satisfait les DEUX branches du motif "(fk_store = 0 OR fk_store = id)" :
        // DEUX lignes dsp jointes pour le MÊME produit, donc compté deux fois par les 8 agrégats
        // SUM(CASE...) (seul COUNT(DISTINCT p.rowid) reste immunisé). Ce n'est pas théorique :
        // ShopifyProductImporter::saveProductMapping() (class/shopifyproductimporter.class.php) ne
        // DELETE que la ligne fk_store=$fkStore quand $fkStore > 0 — jamais la ligne fk_store=0
        // préexistante du même produit, qui survit à côté (la clé unique traite les deux comme des
        // tuples distincts, par conception Epic 47-2 : "permet un même produit sur N boutiques").
        //
        // Correctif : dsp n'est plus un LEFT JOIN direct sur la table, mais sur une sous-requête
        // qui PRÉ-AGRÈGE par fk_product (même motif que le sous-select "variants" quelques lignes
        // plus bas) — une seule ligne jointe par produit, par construction, quel que soit le nombre
        // de lignes dsp qui matchent le périmètre entité/boutique. Règle de PRIORITÉ retenue quand
        // plusieurs lignes dsp coexistent pour le même produit dans ce périmètre (ex. succès sur
        // fk_store=0, échec sur fk_store=id) : has_failed = MAX(CASE WHEN last_sync_status =
        // 'failed' ...) — "failed" l'emporte TOUJOURS, pour ne jamais masquer un échec réel derrière
        // le succès d'une autre ligne du même produit.
        //
        // Motif de filtre boutique (boutique par défaut = fk_store IN (0, id) pour couvrir les
        // lignes legacy ; boutique secondaire = égalité stricte, jamais une ligne fk_store=0)
        // délégué au helper partagé doli2shopBuildStoreScopedSqlFilter() (lib/doli2shop.lib.php,
        // hotfix 2.4.6) plutôt que réimplémenté ici une 3ᵉ fois — MEDIUM (code review 63-16) :
        // cette logique était déjà dupliquée dans admin/diagnostic.php ET ce fichier avant ce
        // correctif ; l'occasion de cette réécriture est prise pour consolider sur le helper.
        //
        // HIGH (code review 63-16, 24/08) : $currentAdminStore === null NE VEUT PAS TOUJOURS DIRE
        // "install jamais migrée" (invariant Epic 47 n°1, seul cas où "aucun filtre" est correct).
        // Si des boutiques EXISTENT mais qu'aucune n'est is_default=1 (ligne par défaut supprimée
        // ou désynchronisée — cf. doublon-is-default-boutiques-non-empeche.md, qui documente le
        // cas de cardinalité symétrique sur la même table sans contrainte d'unicité),
        // doli2shopGetCurrentAdminStore() renvoie null de la même façon, et "aucun filtre" ferait
        // REVIVRE le défaut CRITICAL ci-dessus en intégralité dès que ≥ 2 boutiques réelles
        // existent. doli2shopResolveCounterStoreScope() (lib/doli2shop.lib.php) distingue les deux
        // états : table vide -> aucun filtre (inchangé, invariant Epic 47 n°1) ; boutiques
        // existantes mais aucune résolue -> repli déterministe sur la 1ʳᵉ boutique active, traitée
        // comme NON défaut (jamais le motif OR fk_store=0), + LOG_WARNING pour signaler l'anomalie.
        $syncStatsScope = doli2shopResolveCounterStoreScope($db, $currentAdminStore, 'admin/sync_products.php');
        $syncStatsStoreId = $syncStatsScope['storeId'];
        $syncStatsIsDefaultStore = $syncStatsScope['isDefault'];
        $syncStatsDspStoreFilter = doli2shopBuildStoreScopedSqlFilter($syncStatsStoreId, !$syncStatsIsDefaultStore);

        if (true) {
            $sql = "SELECT
                        COUNT(DISTINCT p.rowid) as total_products,
                        SUM(CASE WHEN dsp.fk_product IS NOT NULL THEN 1 ELSE 0 END) as total_synced,
                        SUM(CASE WHEN dsp.fk_product IS NULL AND (variants.variant_count IS NULL OR variants.variant_count = 0) THEN 1 ELSE 0 END) as not_synced_simple,
                        SUM(CASE WHEN dsp.fk_product IS NULL AND variants.variant_count > 0 THEN 1 ELSE 0 END) as not_synced_with_variants,
                        SUM(CASE WHEN dsp.fk_product IS NULL AND variants.variant_count > 0 THEN variants.variant_count ELSE 0 END) as not_synced_variants_count,
                        SUM(CASE WHEN dsp.fk_product IS NOT NULL AND dsp.has_failed = 0 AND (variants.variant_count IS NULL OR variants.variant_count = 0) THEN 1 ELSE 0 END) as synced_simple,
                        SUM(CASE WHEN dsp.fk_product IS NOT NULL AND dsp.has_failed = 0 AND variants.variant_count > 0 THEN 1 ELSE 0 END) as synced_with_variants,
                        SUM(CASE WHEN dsp.fk_product IS NOT NULL AND dsp.has_failed = 0 AND variants.variant_count > 0 THEN variants.variant_count ELSE 0 END) as synced_variants_count,
                        SUM(CASE WHEN dsp.has_failed = 1 AND (variants.variant_count IS NULL OR variants.variant_count = 0) THEN 1 ELSE 0 END) as failed_simple,
                        SUM(CASE WHEN dsp.has_failed = 1 AND variants.variant_count > 0 THEN 1 ELSE 0 END) as failed_with_variants,
                        SUM(CASE WHEN dsp.has_failed = 1 AND variants.variant_count > 0 THEN variants.variant_count ELSE 0 END) as failed_variants_count,
                        SUM(CASE WHEN variants.variant_count > 0 THEN 1 ELSE 0 END) as products_with_variants,
                        SUM(CASE WHEN variants.variant_count IS NULL OR variants.variant_count = 0 THEN 1 ELSE 0 END) as simple_products,
                        COALESCE(SUM(variants.variant_count), 0) as total_variants
                    FROM " . MAIN_DB_PREFIX . "product p
                    INNER JOIN " . MAIN_DB_PREFIX . "categorie_product cp ON cp.fk_product = p.rowid
                    LEFT JOIN (
                        SELECT
                            fk_product,
                            MAX(CASE WHEN last_sync_status = 'failed' THEN 1 ELSE 0 END) as has_failed
                        FROM " . MAIN_DB_PREFIX . "doli2shop_products
                        WHERE entity = " . (int) $conf->entity . $syncStatsDspStoreFilter . "
                        GROUP BY fk_product
                    ) dsp ON dsp.fk_product = p.rowid
                    LEFT JOIN (
                        SELECT pac.fk_product_parent, COUNT(*) as variant_count
                        FROM " . MAIN_DB_PREFIX . "product pv
                        INNER JOIN " . MAIN_DB_PREFIX . "product_attribute_combination pac ON pv.rowid = pac.fk_product_child
                        WHERE pv.tosell = 1 AND pv.entity = " . (int)$conf->entity . "
                        GROUP BY pac.fk_product_parent
                    ) variants ON variants.fk_product_parent = p.rowid
                    WHERE p.entity = " . (int)$conf->entity . "
                    AND p.tosell = 1
                    AND (p.fk_parent IS NULL OR p.fk_parent = 0)
                    AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac WHERE pac.fk_product_child = p.rowid)
                    AND cp.fk_categorie IN " . $scopeCategoryInClause;

            $resql = $db->query($sql);
            if ($resql) {
                $stats = $db->fetch_object($resql);
                $total_products = (int)$stats->total_products;
                $total_synced = (int)$stats->total_synced;
                $not_synced_simple = (int)$stats->not_synced_simple;
                $not_synced_with_variants = (int)$stats->not_synced_with_variants;
                $not_synced_variants_count = (int)$stats->not_synced_variants_count;
                $synced_simple = (int)$stats->synced_simple;
                $synced_with_variants = (int)$stats->synced_with_variants;
                $synced_variants_count = (int)$stats->synced_variants_count;
                $failed_simple = (int)$stats->failed_simple;
                $failed_with_variants = (int)$stats->failed_with_variants;
                $failed_variants_count = (int)$stats->failed_variants_count;
                $products_with_variants = (int)$stats->products_with_variants;
                $simple_products = (int)$stats->simple_products;
                $total_variants = (int)$stats->total_variants;
                $sync_failures = $failed_simple + $failed_with_variants;
            }
        }

        $sync_percentage = $total_products > 0 ? round(($total_synced / $total_products) * 100) : 0;
        $not_synced_total = (isset($not_synced_simple) ? $not_synced_simple : 0) + (isset($not_synced_with_variants) ? $not_synced_with_variants : 0);
        $synced_total = (isset($synced_simple) ? $synced_simple : 0) + (isset($synced_with_variants) ? $synced_with_variants : 0);
        $failed_total = (isset($failed_simple) ? $failed_simple : 0) + (isset($failed_with_variants) ? $failed_with_variants : 0);
        ?>

        <!-- Résumé principal avec barre de progression d2s-* -->
        <div class="d2s-card" style="margin-bottom: 16px; padding: 16px;">
            <div style="display: -webkit-box; display: -ms-flexbox; display: flex; -webkit-box-pack: justify; -ms-flex-pack: justify; justify-content: space-between; -webkit-box-align: center; -ms-flex-align: center; align-items: center; margin-bottom: 10px;">
                <h4 style="margin: 0;"><?php echo $langs->trans("SyncOverview"); ?></h4>
                <span class="d2s-kpi-value" style="font-size: 2em; color: <?php echo $sync_percentage == 100 ? 'var(--d2s-success, #28a745)' : ($sync_percentage > 50 ? 'var(--d2s-primary, #029e9c)' : 'var(--d2s-danger, #dc3545)'); ?>;">
                    <?php echo $sync_percentage; ?>%
                </span>
            </div>

            <div class="d2s-progress-wrapper">
                <div class="d2s-progress" role="progressbar" aria-valuenow="<?php echo $sync_percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="d2s-progress-fill" style="width: <?php echo $sync_percentage; ?>%;"></div>
                </div>
            </div>

            <!-- KPI cards -->
            <div style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin-top: 16px;">
                <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 150px; flex: 1 1 150px; margin: 4px; text-align: center;">
                    <div class="d2s-kpi-value" style="color: var(--d2s-primary-text, #026B6A);"><?php echo $total_products; ?></div>
                    <div class="d2s-kpi-label"><?php echo $langs->trans("TotalProducts"); ?></div>
                </div>
                <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 150px; flex: 1 1 150px; margin: 4px; text-align: center;">
                    <div class="d2s-kpi-value" style="color: var(--d2s-success, #28a745);"><?php echo $total_synced; ?></div>
                    <div class="d2s-kpi-label"><?php echo $langs->trans("SynchronizedProducts"); ?></div>
                </div>
                <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 150px; flex: 1 1 150px; margin: 4px; text-align: center;">
                    <div class="d2s-kpi-value" style="color: var(--d2s-warning, #ffc107);"><?php echo $products_with_variants; ?></div>
                    <div class="d2s-kpi-label"><?php echo $langs->trans("ProductsWithVariants"); ?></div>
                </div>
                <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 150px; flex: 1 1 150px; margin: 4px; text-align: center;">
                    <div class="d2s-kpi-value" style="color: var(--d2s-danger, #dc3545);"><?php echo isset($sync_failures) ? $sync_failures : 0; ?></div>
                    <div class="d2s-kpi-label"><?php echo $langs->trans("SyncFailures"); ?></div>
                </div>
            </div>

            <?php if ($not_synced_total > 0) : ?>
            <div class="d2s-alert-banner d2s-alert--warning" style="margin-top: 12px;">
                <strong><i class="fa fa-exclamation-triangle"></i> <?php echo $langs->trans("ProductsNotSynced"); ?> (<?php echo $not_synced_total; ?>)</strong>
                <ul style="margin: 8px 0 0 20px; padding: 0; list-style: none;">
                    <?php if (isset($not_synced_simple) && $not_synced_simple > 0) : ?>
                    <li>&#8226; <?php echo $not_synced_simple; ?> <?php echo $langs->trans("SimpleProductsNotSynced"); ?></li>
                    <?php endif; ?>
                    <?php if (isset($not_synced_with_variants) && $not_synced_with_variants > 0) : ?>
                    <li>&#8226; <?php echo $not_synced_with_variants; ?> <?php echo $langs->trans("ProductsWithVariantsNotSynced"); ?> (<?php echo $not_synced_variants_count; ?> <?php echo $langs->trans("VariantsConcerned"); ?>)</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ($synced_total > 0) : ?>
            <div class="d2s-alert-banner d2s-alert--success" style="margin-top: 12px;">
                <strong><i class="fa fa-check-circle"></i> <?php echo $langs->trans("ProductsAlreadySynced"); ?> (<?php echo $synced_total; ?>)</strong>
            </div>
            <?php endif; ?>

            <?php if ($failed_total > 0) : ?>
            <div class="d2s-alert-banner d2s-alert--error" style="margin-top: 12px;">
                <strong><i class="fa fa-exclamation-triangle"></i> <?php echo $langs->trans("SyncFailures"); ?> (<?php echo $failed_total; ?>)</strong>
                <small style="display: block; margin-top: 8px;"><?php echo $langs->trans("CheckLogsOrRetrySync"); ?></small>
            </div>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- Fin stats-dolibarr -->

    <!-- Stats pour Shopify → Dolibarr -->
    <div id="stats-shopify" style="<?php echo !$showDolibarrStats ? '' : 'display:none;'; ?>">
        <div class="sync-stats-unified">
            <h3><i class="fa fa-cloud-download-alt"></i> <?php echo $langs->trans("ShopifyToDolibarr"); ?></h3>
            <div class="d2s-alert-banner d2s-alert--info">
                <p style="margin: 0 0 12px 0;">
                    <i class="fa fa-info-circle"></i> <strong><?php echo $langs->trans("ShopifyImportInfo"); ?></strong>
                </p>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><?php echo $langs->trans("ShopifyImportBatch"); ?></li>
                    <li><?php echo $langs->trans("ShopifyImportNote"); ?></li>
                    <li><?php echo $langs->trans("ShopifyImportCategories"); ?></li>
                </ul>
            </div>
        </div>
    </div><!-- Fin stats-shopify -->

    <!-- Avertissement -->
    <div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 16px;">
        <strong><?php echo $langs->trans("Warning"); ?>:</strong>
        <?php echo $langs->trans("ManualSyncWarning"); ?>
    </div>

    <?php
    // Avertissement si direction non configurée
    if (empty($configuredDirection)) {
        print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 16px;">';
        print '<i class="fa fa-exclamation-triangle"></i> ';
        print '<strong>' . $langs->trans("SyncDirectionNotConfiguredManual") . '</strong><br>';
        print '<a href="' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=products" class="button">' . $langs->trans("ConfigureSyncDirection") . '</a>';
        print '</div>';
    }

    // HIGH 5 (code review 63-19) : bandeau explicite si aucune catégorie racine n'est configurée —
    // évite qu'un compteur à 0 (pour la bonne raison, cf. commentaire du bloc de stats plus haut)
    // ne soit lu comme "rien à synchroniser dans le catalogue" plutôt que "la configuration est
    // incomplète". Même message pour les 4 sites (helper partagé, cf. ProductScopeHelper::logEmptyScopeDiagnostics()).
    if ($defaultCategoryId <= 0) {
        print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 16px;">';
        print '<i class="fa fa-exclamation-triangle"></i> ';
        print '<strong>' . $langs->trans("SyncCategoryNotConfiguredManual") . '</strong><br>';
        print '<a href="' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=products" class="button">' . $langs->trans("ConfigureCategoryScope") . '</a>';
        print '</div>';
    }
    ?>

    <!-- Formulaire de synchronisation AJAX batch (v2.2.0) -->
    <div class="d2s-card" style="padding: 16px; margin-bottom: 16px;">
        <div class="form-group" style="margin-bottom: 12px;">
            <label for="sync_type"><strong><?php echo $langs->trans("SyncDirection"); ?></strong></label>
            <select name="sync_type" id="sync_type" <?php echo empty($configuredDirection) ? 'disabled' : ''; ?> style="margin-left: 8px;">
                <option value=""><?php echo $langs->trans("SelectSyncDirection"); ?></option>
                <?php if ($configuredDirection == 'dolibarr_to_shopify' || $configuredDirection == 'both') : ?>
                <option value="dolibarr_to_shopify"><?php echo $langs->trans("DolibarrToShopify"); ?></option>
                <?php endif; ?>
                <?php if ($configuredDirection == 'shopify_to_dolibarr' || $configuredDirection == 'both') : ?>
                <option value="shopify_to_dolibarr"><?php echo $langs->trans("ShopifyToDolibarr"); ?></option>
                <?php endif; ?>
            </select>
            <?php if (!empty($configuredDirection) && $configuredDirection != 'both') : ?>
            <small class="opacitymedium"><?php echo $langs->trans("DirectionLimitedByConfig"); ?></small>
            <?php endif; ?>
        </div>

        <div class="form-group" style="margin-bottom: 12px;">
            <label for="search_ref"><strong><?php echo $langs->trans("FilterByReference"); ?></strong></label>
            <input type="text" name="search_ref" id="search_ref" value="" placeholder="<?php echo dol_escape_htmltag($langs->trans("ProductRefOrBarcode")); ?>" autocomplete="off" style="margin-left: 8px;">
            <div id="search_results" style="margin-top: 10px; display: none;">
                <div class="shopify-info-box">
                    <strong><?php echo $langs->trans("FoundProducts"); ?>:</strong> <span id="productCount">0</span>
                    <div id="productList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-top: 10px; background: white;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Info box -->
        <div class="shopify-info-box" style="margin-bottom: 12px;">
            <strong><?php echo $langs->trans("Info"); ?>:</strong>
            <ul>
                <li><?php echo $langs->trans("SyncInfoDolibarrToShopify"); ?></li>
                <li><?php echo $langs->trans("SyncInfoShopifyToDolibarr"); ?></li>
                <li><?php echo $langs->trans("SyncInfoFilters"); ?></li>
                <li><?php echo $langs->trans("ManualSyncIgnores24hDelay"); ?></li>
            </ul>
        </div>

        <div class="center">
            <button type="button" class="butAction" id="syncButton" <?php echo empty($configuredDirection) ? 'disabled' : ''; ?>>
                <?php echo $langs->trans("StartSync"); ?>
            </button>
        </div>
    </div>

    <!-- v2.2.0: Zone de progression AJAX (cachée initialement) -->
    <div id="d2s-sync-progress" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-sync fa-spin" id="d2s-sync-spinner"></i> <?php echo $langs->trans("SyncBatchProgress"); ?></h3>
        <div class="d2s-progress-wrapper" style="margin: 12px 0;">
            <div class="d2s-progress-text" id="d2s-sync-progress-text">0 / 0</div>
            <div class="d2s-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="d2s-sync-progressbar">
                <div class="d2s-progress-fill" id="d2s-sync-progress-fill" style="width: 0%;"></div>
            </div>
        </div>
        <div id="d2s-sync-status" style="margin-top: 8px; font-size: 0.9em; color: #666;"></div>
    </div>

    <!-- v2.2.0: Zone de rapport (cachée initialement) -->
    <div id="d2s-sync-report" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-clipboard-list"></i> <?php echo $langs->trans("SyncReportTitle"); ?></h3>
        <!-- KPIs du rapport — populated by JS via safe DOM methods -->
        <div id="d2s-report-kpis" style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;"></div>
        <!-- Erreurs détaillées — populated by JS via safe DOM methods -->
        <div id="d2s-report-errors" style="display: none; margin-top: 12px;"></div>
        <!-- Boutons post-sync -->
        <div class="center" style="margin-top: 16px;">
            <button type="button" class="butAction" id="syncRetryButton" style="display: none;">
                <?php echo $langs->trans("SyncRetry"); ?>
            </button>
        </div>
    </div>

    <!-- Story 52-2 : Recalage initial en masse du stock Dolibarr -> Shopify -->
    <div id="d2s-stock-recalage-card" class="d2s-card" style="padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-redo"></i> <?php echo $langs->trans("StockRecalageTitle"); ?></h3>
        <p class="opacitymedium"><?php echo dol_escape_htmltag($langs->trans("StockRecalageIntro")); ?></p>

        <div class="d2s-alert-banner d2s-alert--warning" style="margin: 12px 0;">
            <strong><?php echo $langs->trans("Warning"); ?>:</strong> <?php echo dol_escape_htmltag($langs->trans("StockRecalageWarning")); ?>
        </div>

        <?php if (!$recalageEnabled) : ?>
        <div class="d2s-alert-banner d2s-alert--error" style="margin-bottom: 12px;">
            <i class="fa fa-exclamation-triangle"></i> <?php echo dol_escape_htmltag($langs->trans("StockRecalageDisabledWarning")); ?>
        </div>
        <?php endif; ?>

        <p><strong id="d2s-recalage-count"><?php echo dol_escape_htmltag($langs->trans("StockRecalageCountLabel", (string) $recalageMappedCount)); ?></strong></p>

        <p class="opacitymedium">
            <?php echo dol_escape_htmltag($langs->trans("StockRecalageLastRunLabel")); ?>
            <span id="d2s-recalage-last-run"><?php echo dol_escape_htmltag($lastRecalageSummary); ?></span>
        </p>

        <div class="center">
            <button type="button" class="butAction" id="d2s-recalage-button" <?php echo (!$recalageEnabled || $recalageMappedCount <= 0) ? 'disabled' : ''; ?>>
                <?php echo $langs->trans("StockRecalageButton"); ?>
            </button>
        </div>
    </div>

    <!-- Zone de progression recalage (cachée initialement) -->
    <div id="d2s-recalage-progress" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-sync fa-spin" id="d2s-recalage-spinner"></i> <?php echo $langs->trans("StockRecalageProgressTitle"); ?></h3>
        <div class="d2s-progress-wrapper" style="margin: 12px 0;">
            <div class="d2s-progress-text" id="d2s-recalage-progress-text">0 / 0</div>
            <div class="d2s-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="d2s-recalage-progressbar">
                <div class="d2s-progress-fill" id="d2s-recalage-progress-fill" style="width: 0%;"></div>
            </div>
        </div>
        <div id="d2s-recalage-status" style="margin-top: 8px; font-size: 0.9em; color: #666;"></div>
    </div>

    <!-- Zone de rapport recalage (cachée initialement) -->
    <div id="d2s-recalage-report" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-clipboard-list"></i> <?php echo $langs->trans("StockRecalageComplete"); ?></h3>
        <div id="d2s-recalage-report-kpis" style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;"></div>
        <div id="d2s-recalage-report-errors" style="display: none; margin-top: 12px;"></div>
    </div>

    <!-- Hotfix 2.4.4 (2/2) : Réparation des imports existants en vraies déclinaisons Dolibarr -->
    <div id="d2s-variant-repair-card" class="d2s-card" style="padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-wrench"></i> <?php echo $langs->trans("VariantRepairTitle"); ?></h3>
        <p class="opacitymedium"><?php echo dol_escape_htmltag($langs->trans("VariantRepairIntro")); ?></p>

        <?php if (!$repairEnabled) : ?>
        <div class="d2s-alert-banner d2s-alert--error" style="margin-bottom: 12px;">
            <i class="fa fa-exclamation-triangle"></i> <?php echo dol_escape_htmltag($langs->trans("VariantRepairDisabledWarning")); ?>
        </div>
        <?php endif; ?>

        <p><strong id="d2s-repair-count"><?php echo dol_escape_htmltag($langs->trans("VariantRepairCountLabel", (string) $repairCandidateCount)); ?></strong></p>

        <?php if ($repairLabelOnlyCount > 0) : ?>
        <!-- HIGH-A (code review 63-17) : distingue les deux pools à l'écran — sans cette ligne,
             le compteur combiné ci-dessus ne dit pas à l'utilisateur qu'une partie du lot exige
             de cocher la case ci-dessous pour être traitée. -->
        <p class="opacitymedium" id="d2s-repair-labelonly-count">
            <?php echo dol_escape_htmltag($langs->trans("VariantRepairLabelOnlyCountLabel", (string) $repairLabelOnlyCount)); ?>
        </p>
        <?php endif; ?>

        <p class="opacitymedium">
            <?php echo dol_escape_htmltag($langs->trans("VariantRepairLastRunLabel")); ?>
            <span id="d2s-repair-last-run"><?php echo dol_escape_htmltag($lastRepairSummary); ?></span>
        </p>

        <div class="form-group" style="margin: 12px 0;">
            <label>
                <input type="checkbox" id="d2s-repair-fix-labels" />
                <?php echo dol_escape_htmltag($langs->trans("VariantRepairFixLabelsCheckbox")); ?>
            </label>
        </div>

        <div class="center">
            <button type="button" class="butAction" id="d2s-repair-button" <?php echo (!$repairEnabled || $repairCandidateCount <= 0) ? 'disabled' : ''; ?>>
                <?php echo $langs->trans("VariantRepairButton"); ?>
            </button>
        </div>
    </div>

    <!-- Zone de progression réparation (cachée initialement) -->
    <div id="d2s-repair-progress" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-sync fa-spin" id="d2s-repair-spinner"></i> <?php echo $langs->trans("VariantRepairProgressTitle"); ?></h3>
        <div class="d2s-progress-wrapper" style="margin: 12px 0;">
            <div class="d2s-progress-text" id="d2s-repair-progress-text">0 / 0</div>
            <div class="d2s-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="d2s-repair-progressbar">
                <div class="d2s-progress-fill" id="d2s-repair-progress-fill" style="width: 0%;"></div>
            </div>
        </div>
        <div id="d2s-repair-status" style="margin-top: 8px; font-size: 0.9em; color: #666;"></div>
    </div>

    <!-- Zone de rapport réparation (cachée initialement) -->
    <div id="d2s-repair-report" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-clipboard-list"></i> <?php echo $langs->trans("VariantRepairComplete"); ?></h3>
        <div id="d2s-repair-report-kpis" style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;"></div>
        <div id="d2s-repair-report-errors" style="display: none; margin-top: 12px;"></div>
    </div>

    <!-- Story 58-1 : Observabilité de la file de push stock asynchrone (52-4) -->
    <div id="d2s-stock-push-queue-card" class="d2s-card" style="padding: 16px; margin-bottom: 16px;">
        <h3><i class="fa fa-stream"></i> <?php echo $langs->trans("StockPushQueueTitle"); ?></h3>
        <p class="opacitymedium"><?php echo dol_escape_htmltag($langs->trans("StockPushQueueIntro")); ?></p>

        <!-- Compteurs (AC1) -->
        <div style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;">
            <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 140px; flex: 1 1 140px; margin: 4px; text-align: center;">
                <div class="d2s-kpi-value" style="color: var(--d2s-primary-text, #026B6A);"><?php echo (int) $stockPushQueueCounts['pending']; ?></div>
                <div class="d2s-kpi-label"><?php echo $langs->trans("StockPushQueueCountPending"); ?></div>
            </div>
            <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 140px; flex: 1 1 140px; margin: 4px; text-align: center;">
                <div class="d2s-kpi-value" style="color: var(--d2s-warning, #ffc107);"><?php echo (int) $stockPushQueueCounts['processing']; ?></div>
                <div class="d2s-kpi-label"><?php echo $langs->trans("StockPushQueueCountProcessing"); ?></div>
            </div>
            <div class="d2s-kpi-card" style="-webkit-box-flex: 1; -ms-flex: 1 1 140px; flex: 1 1 140px; margin: 4px; text-align: center;">
                <div class="d2s-kpi-value" style="color: var(--d2s-danger, #dc3545);"><?php echo (int) $stockPushQueueCounts['dead_letter']; ?></div>
                <div class="d2s-kpi-label"><?php echo $langs->trans("StockPushQueueCountDeadLetter"); ?></div>
            </div>
        </div>

        <!-- Alerte file en retard (AC2) -->
        <?php if ($stockPushQueueStaleCount > 0) : ?>
        <div class="d2s-alert-banner d2s-alert--warning" style="margin: 12px 0;">
            <i class="fa fa-exclamation-triangle"></i>
            <strong><?php echo dol_escape_htmltag($langs->trans("StockPushQueueStaleWarning", (string) $stockPushQueueStaleCount, (string) $stockPushQueueAlertThresholdMin)); ?></strong>
            <p style="margin: 6px 0 0 0;"><?php echo dol_escape_htmltag($langs->trans("StockPushQueueStaleWarningCauses")); ?></p>
        </div>
        <?php endif; ?>

        <!-- Lien historique complémentaire -->
        <p class="opacitymedium" style="margin: 8px 0 12px 0;">
            <a href="<?php echo dol_buildpath('/doli2shop/admin/action_log.php', 1); ?>?filter_action_type=stock_push_queue">
                <i class="fa fa-history"></i> <?php echo $langs->trans("StockPushQueueViewActionLog"); ?>
            </a>
        </p>

        <!-- Dead-letter : liste + relance (AC3) -->
        <?php if (empty($stockPushQueueDeadLetterItems)) : ?>
        <div class="d2s-alert-banner d2s-alert--success">
            <i class="fa fa-check-circle"></i> <?php echo $langs->trans("StockPushQueueNoDeadLetter"); ?>
        </div>
        <?php else : ?>
        <?php
        // Review 3 couches (MEDIUM) : le compteur KPI affiche le TOTAL des items en dead-letter,
        // mais la table est bornée à 50 (les plus récents). Sans ce signal, les items les plus
        // anciens restaient invisibles ET non sélectionnables, donc potentiellement bloqués
        // indéfiniment sans que l'administrateur puisse le savoir.
        $stockPushQueueHiddenDeadLetter = (int) $stockPushQueueCounts['dead_letter'] - count($stockPushQueueDeadLetterItems);
        if ($stockPushQueueHiddenDeadLetter > 0) : ?>
        <div class="d2s-alert-banner d2s-alert--warning">
            <i class="fa fa-exclamation-triangle"></i>
            <?php echo dol_escape_htmltag($langs->trans(
                "StockPushQueueDeadLetterTruncated",
                count($stockPushQueueDeadLetterItems),
                (int) $stockPushQueueCounts['dead_letter'],
                $stockPushQueueHiddenDeadLetter
            )); ?>
        </div>
        <?php endif; ?>
        <form method="POST" action="<?php echo dol_escape_htmltag($_SERVER["PHP_SELF"]); ?>" id="d2s-stock-push-queue-requeue-form">
            <input type="hidden" name="token" value="<?php echo newToken(); ?>">
            <input type="hidden" name="action" value="requeue_stock_push_queue">

            <table class="noborder" width="100%">
                <tr class="liste_titre">
                    <th style="width: 24px;"><input type="checkbox" id="d2s-stock-push-queue-select-all" title="<?php echo dol_escape_htmltag($langs->trans("WebhookSelectAll")); ?>"></th>
                    <th><?php echo $langs->trans("StockPushQueueColumnProduct"); ?></th>
                    <th><?php echo $langs->trans("StockPushQueueColumnTries"); ?></th>
                    <th><?php echo $langs->trans("StockPushQueueColumnLastAttempt"); ?></th>
                    <th><?php echo $langs->trans("StockPushQueueColumnLastError"); ?></th>
                </tr>
                <?php foreach ($stockPushQueueDeadLetterItems as $deadLetterItem) :
                    $deadLetterProductId = (int) $deadLetterItem['fk_product'];
                    $deadLetterProductLabel = isset($stockPushQueueProductLabels[$deadLetterProductId])
                        ? $stockPushQueueProductLabels[$deadLetterProductId]
                        : '#' . $deadLetterProductId . ' (' . $langs->trans("StockPushQueueProductDeleted") . ')';
                    ?>
                <tr class="oddeven">
                    <td style="text-align: center;"><input type="checkbox" name="queue_ids[]" value="<?php echo (int) $deadLetterItem['rowid']; ?>" class="d2s-stock-push-queue-cb"></td>
                    <td>
                        <?php if (isset($stockPushQueueProductLabels[$deadLetterProductId])) : ?>
                        <a href="<?php echo DOL_URL_ROOT; ?>/product/card.php?id=<?php echo $deadLetterProductId; ?>"><?php echo dol_escape_htmltag($deadLetterProductLabel); ?></a>
                        <?php else : ?>
                        <span class="opacitymedium"><?php echo dol_escape_htmltag($deadLetterProductLabel); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int) $deadLetterItem['tries']; ?></td>
                    <td style="white-space: nowrap;"><?php echo !empty($deadLetterItem['date_traitement']) ? dol_print_date($db->jdate($deadLetterItem['date_traitement']), 'dayhour') : '-'; ?></td>
                    <td>
                        <?php if (!empty($deadLetterItem['last_error'])) : ?>
                        <span title="<?php echo dol_escape_htmltag($langs->trans("StockPushQueueLastErrorKnownNotCurrent")); ?>" style="color: #721c24; font-size: 12px;">
                            <?php echo dol_escape_htmltag($deadLetterItem['last_error']); ?>
                        </span>
                        <?php else : ?>
                        <span class="opacitymedium">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <div class="center" style="margin-top: 10px;">
                <button type="submit" class="butAction" onclick="return confirm('<?php echo dol_escape_js($langs->trans("StockPushQueueRequeueConfirm")); ?>');">
                    <i class="fa fa-redo"></i> <?php echo $langs->trans("StockPushQueueRequeueButton"); ?>
                </button>
            </div>
        </form>

        <script>
        (function() {
            var selectAll = document.getElementById('d2s-stock-push-queue-select-all');
            if (!selectAll) {
                return;
            }
            function cbs() { return document.querySelectorAll('.d2s-stock-push-queue-cb'); }
            selectAll.addEventListener('change', function() {
                cbs().forEach(function(cb) { cb.checked = selectAll.checked; });
            });
        })();
        </script>
        <?php endif; ?>
    </div>
</div>

<script>
/**
 * v2.2.0 — AJAX batch synchronization engine for manual product sync
 * Pattern: same as wizard_sync.php (Story 6.3)
 *
 * Security note: All dynamic content is inserted via textContent or
 * escaped through a dedicated helper to prevent XSS. The only use of
 * innerHTML is for static, fully-controlled HTML structures built from
 * server-side translated strings (never from user input).
 */
(function() {
    'use strict';

    // Translation strings from PHP (html_entity_decode pour eviter les entites HTML litterales dans textContent)
    <?php
    $jsTransKeys = array(
        'syncBatchProgress' => 'SyncBatchProgress',
        'syncBatchComplete' => 'SyncBatchComplete',
        'syncReportCreated' => 'SyncReportCreated',
        'syncReportUpdated' => 'SyncReportUpdated',
        'syncReportSkipped' => 'SyncReportSkipped',
        'syncReportErrors' => 'SyncReportErrors',
        'syncReportNoErrors' => 'SyncReportNoErrors',
        'syncReportErrorDetail' => 'SyncReportErrorDetail',
        'startSync' => 'StartSync',
        'syncInProgress' => 'SyncInProgress',
        'syncRetry' => 'SyncRetry',
        'errorSync' => 'ErrorSync',
        'pleaseSelectDirection' => 'PleaseSelectSyncDirection',
        'noProductsToSync' => 'NoProductsToSync',
        'syncReportStocks' => 'SyncReportStocks',
        'syncReportImagesResyncForced' => 'SyncReportImagesResyncForced',
        'syncReportImagesNoSource' => 'SyncReportImagesNoSource',
        // Hotfix 2.5.7 (re-review 27/09/2026, MEDIUM) : même convention que les 2 clés ci-dessus.
        'syncReportStockSyncFailed' => 'SyncReportStockSyncFailed',
        'syncReportStockLocationErrors' => 'SyncReportStockLocationErrors',
        // Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3) : même
        // convention — articles ACTUELLEMENT plafonnés (plafond de cycles consécutifs atteint).
        'syncReportStockLocationCapped' => 'SyncReportStockLocationCapped',
    );
    $jsTransValues = array();
    foreach ($jsTransKeys as $jsKey => $langKey) {
        $jsTransValues[$jsKey] = html_entity_decode($langs->trans($langKey), ENT_QUOTES, 'UTF-8');
    }
    ?>
    var TRANS = <?php echo json_encode($jsTransValues, JSON_UNESCAPED_UNICODE); ?>;

    var ajaxUrl = <?php echo json_encode(dol_buildpath('/doli2shop/ajax/sync_products_batch.php', 1)); ?>;
    var csrfToken = <?php echo json_encode(currentToken()); ?>;
    // Story 49-9 : store_id transmis au worker batch — 0 pour la boutique par défaut (chemin global inchangé)
    var storeId = <?php echo json_encode($currentAdminStoreIsSecondary ? (int) $currentAdminStore->rowid : 0); ?>;

    // Cumulative stats across all batches
    var totalStats = { created: 0, updated: 0, skipped: 0, errors: 0, processed: 0, stockSyncEnabled: false, imagesResyncForced: 0, imagesNoSourceFound: 0, imagesNoSourceFoundRefs: [], runId: null, stockSyncFailed: 0, stockSyncLocationErrors: 0, stockLocationCapped: 0 };
    var allErrorDetails = [];
    var totalToSync = 0;
    var isSyncing = false;

    // DOM elements
    var syncButton = document.getElementById('syncButton');
    var syncTypeSelect = document.getElementById('sync_type');
    var searchRefInput = document.getElementById('search_ref');
    var progressSection = document.getElementById('d2s-sync-progress');
    var progressText = document.getElementById('d2s-sync-progress-text');
    var progressFill = document.getElementById('d2s-sync-progress-fill');
    var progressBar = document.getElementById('d2s-sync-progressbar');
    var statusDiv = document.getElementById('d2s-sync-status');
    var spinner = document.getElementById('d2s-sync-spinner');
    var reportSection = document.getElementById('d2s-sync-report');
    var reportKpis = document.getElementById('d2s-report-kpis');
    var reportErrors = document.getElementById('d2s-report-errors');
    var retryButton = document.getElementById('syncRetryButton');

    /**
     * Escape text for safe display — returns a text node safe string
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    /**
     * Build a KPI card element (safe DOM construction)
     */
    function createKpiCard(value, label, color) {
        var card = document.createElement('div');
        card.className = 'd2s-kpi-card';
        card.setAttribute('style', '-webkit-box-flex: 1; -ms-flex: 1 1 120px; flex: 1 1 120px; margin: 4px; text-align: center;');

        var valDiv = document.createElement('div');
        valDiv.className = 'd2s-kpi-value';
        valDiv.style.color = color;
        valDiv.textContent = String(value);

        var labelDiv = document.createElement('div');
        labelDiv.className = 'd2s-kpi-label';
        labelDiv.textContent = label;

        card.appendChild(valDiv);
        card.appendChild(labelDiv);
        return card;
    }

    /**
     * Start sync — get count then loop batches
     */
    syncButton.addEventListener('click', function() {
        var direction = syncTypeSelect.value;
        if (!direction) {
            alert(TRANS.pleaseSelectDirection);
            return;
        }
        if (isSyncing) return;

        isSyncing = true;
        totalStats = { created: 0, updated: 0, skipped: 0, errors: 0, processed: 0, stockSyncEnabled: false, imagesResyncForced: 0, imagesNoSourceFound: 0, imagesNoSourceFoundRefs: [], runId: null, stockSyncFailed: 0, stockSyncLocationErrors: 0, stockLocationCapped: 0 };
        allErrorDetails = [];
        totalToSync = 0;

        syncButton.disabled = true;
        syncButton.textContent = TRANS.syncInProgress;

        // Reset & show progress
        progressSection.style.display = 'block';
        reportSection.style.display = 'none';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 / ?';
        statusDiv.textContent = '';
        spinner.className = 'fa fa-sync fa-spin';
        spinner.style.color = '';

        // Step 1: get_count
        if (typeof jQuery !== 'undefined') {
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_count',
                    direction: direction,
                    search_ref: searchRefInput.value.trim(),
                    store_id: storeId,
                    token: csrfToken
                },
                success: function(data) {
                    if (data && data.success) {
                        totalToSync = data.total || 0;
                        progressText.textContent = '0 / ' + totalToSync;

                        if (totalToSync === 0) {
                            finishSync(TRANS.noProductsToSync);
                            return;
                        }

                        // Step 2: loop sync_batch
                        syncNextBatch(direction, null, 0);
                    } else {
                        finishSync(TRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    }
                },
                error: function(xhr, status, err) {
                    finishSync(TRANS.errorSync + ': ' + err);
                }
            });
        }
    });

    /**
     * Recursive batch sync
     */
    function syncNextBatch(direction, cursor, offset) {
        var postData = {
            action: 'sync_batch',
            direction: direction,
            search_ref: searchRefInput.value.trim(),
            store_id: storeId,
            token: csrfToken
        };

        if (direction === 'shopify_to_dolibarr' && cursor) {
            postData.cursor = cursor;
        } else if (direction === 'dolibarr_to_shopify') {
            postData.offset = offset || 0;
        }

        // Journal de run : l'identifiant circule d'un lot à l'autre, chaque lot étant une
        // requête HTTP distincte. Vide au premier lot — le serveur ouvre alors le run.
        if (totalStats.runId) {
            postData.run_id = totalStats.runId;
        }

        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: postData,
            success: function(data) {
                if (!data) {
                    finishSync(TRANS.errorSync + ': Empty response');
                    return;
                }

                // Accumulate stats
                totalStats.created += (data.created || 0);
                totalStats.updated += (data.updated || 0);
                totalStats.skipped += (data.skipped || 0);
                totalStats.errors += (data.errors || 0);
                totalStats.processed += (data.processed || 0);
                // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC3)
                totalStats.imagesResyncForced += (data.imagesResyncForced || 0);
            totalStats.imagesNoSourceFound += (data.imagesNoSourceFound || 0);
            if (data.runId && !totalStats.runId) { totalStats.runId = data.runId; }
            if (Array.isArray(data.imagesNoSourceFoundRefs)) {
                data.imagesNoSourceFoundRefs.forEach(function (ref) {
                    if (totalStats.imagesNoSourceFoundRefs.length < 20 && totalStats.imagesNoSourceFoundRefs.indexOf(ref) === -1) {
                        totalStats.imagesNoSourceFoundRefs.push(ref);
                    }
                });
            }
                // Hotfix 2.5.7 (re-review 27/09/2026, MEDIUM) : mêmes compteurs que le résumé
                // CRON/journaux, désormais visibles sur cet écran de synchronisation manuelle.
                totalStats.stockSyncFailed += (data.stockSyncFailed || 0);
                totalStats.stockSyncLocationErrors += (data.stockSyncLocationErrors || 0);
                // Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3)
                totalStats.stockLocationCapped += (data.stockLocationCapped || 0);

                // Track stock sync status (Task 3 — AC4)
                if (data.stockSyncEnabled) {
                    totalStats.stockSyncEnabled = true;
                }

                // Accumulate error details
                if (data.errorDetails && data.errorDetails.length > 0) {
                    for (var i = 0; i < data.errorDetails.length; i++) {
                        allErrorDetails.push(data.errorDetails[i]);
                    }
                }

                // Update progress
                var pct = totalToSync > 0 ? Math.min(Math.round((totalStats.processed / totalToSync) * 100), 100) : 0;
                progressFill.style.width = pct + '%';
                progressBar.setAttribute('aria-valuenow', String(pct));
                progressText.textContent = totalStats.processed + ' / ' + totalToSync;
                statusDiv.textContent = TRANS.syncReportCreated + ': ' + totalStats.created +
                    ' | ' + TRANS.syncReportUpdated + ': ' + totalStats.updated +
                    ' | ' + TRANS.syncReportSkipped + ': ' + totalStats.skipped +
                    ' | ' + TRANS.syncReportErrors + ': ' + totalStats.errors;

                // Continue or finish
                if (data.hasMore) {
                    var nextCursor = data.cursor || null;
                    var nextOffset = direction === 'dolibarr_to_shopify' ? parseInt(data.cursor || '0', 10) : 0;
                    syncNextBatch(direction, nextCursor, nextOffset);
                } else {
                    finishSync();
                }
            },
            error: function(xhr, status, err) {
                finishSync(TRANS.errorSync + ': ' + err);
            }
        });
    }

    /**
     * Finish sync — show report using safe DOM methods
     */
    function finishSync(errorMessage) {
        isSyncing = false;

        // Update progress bar to 100% if no error
        if (!errorMessage) {
            progressFill.style.width = '100%';
            progressBar.setAttribute('aria-valuenow', '100');
            progressText.textContent = totalStats.processed + ' / ' + totalToSync;
        }

        // Stop spinner
        spinner.className = 'fa fa-check-circle';
        spinner.style.color = errorMessage ? 'var(--d2s-danger, #dc3545)' : 'var(--d2s-success, #28a745)';

        // Reset button
        syncButton.disabled = false;
        syncButton.textContent = TRANS.startSync;

        // Build report
        reportSection.style.display = 'block';

        // H2 fix: Remove any previously inserted stock banners (siblings of reportErrors)
        var oldStockBanners = reportSection.querySelectorAll('.d2s-stock-sync-banner');
        for (var sb = 0; sb < oldStockBanners.length; sb++) {
            oldStockBanners[sb].parentNode.removeChild(oldStockBanners[sb]);
        }

        // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC3) : idem pour le bandeau
        // de renvoi d'images forcé, sur un rerun de synchronisation.
        var oldImagesResyncBanners = reportSection.querySelectorAll('.d2s-images-resync-banner');
        for (var ib = 0; ib < oldImagesResyncBanners.length; ib++) {
            oldImagesResyncBanners[ib].parentNode.removeChild(oldImagesResyncBanners[ib]);
        }

        // Hotfix 2.5.7 (re-review 27/09/2026, MEDIUM) : idem pour les 2 bandeaux de stock, sur un
        // rerun de synchronisation.
        var oldStockFailedBanners = reportSection.querySelectorAll('.d2s-stock-sync-failed-banner');
        for (var fb = 0; fb < oldStockFailedBanners.length; fb++) {
            oldStockFailedBanners[fb].parentNode.removeChild(oldStockFailedBanners[fb]);
        }
        var oldStockLocationBanners = reportSection.querySelectorAll('.d2s-stock-location-error-banner');
        for (var lb = 0; lb < oldStockLocationBanners.length; lb++) {
            oldStockLocationBanners[lb].parentNode.removeChild(oldStockLocationBanners[lb]);
        }
        // Story stock-article-non-active-emplacement-reselection-perpetuelle : idem pour le
        // bandeau dédié aux articles plafonnés, sur un rerun de synchronisation.
        var oldStockLocationCappedBanners = reportSection.querySelectorAll('.d2s-stock-location-capped-banner');
        for (var cb = 0; cb < oldStockLocationCappedBanners.length; cb++) {
            oldStockLocationCappedBanners[cb].parentNode.removeChild(oldStockLocationCappedBanners[cb]);
        }

        // M1 fix: Update progress section title with completion message
        if (!errorMessage) {
            var progressTitle = progressSection.querySelector('h3');
            if (progressTitle) {
                while (progressTitle.firstChild) { progressTitle.removeChild(progressTitle.firstChild); }
                var doneIcon = document.createElement('i');
                doneIcon.className = 'fa fa-check-circle';
                doneIcon.style.color = 'var(--d2s-success, #28a745)';
                progressTitle.appendChild(doneIcon);
                progressTitle.appendChild(document.createTextNode(' ' + TRANS.syncBatchComplete));
            }
        }

        // KPIs — safe DOM construction
        while (reportKpis.firstChild) { reportKpis.removeChild(reportKpis.firstChild); }
        reportKpis.appendChild(createKpiCard(totalStats.created, TRANS.syncReportCreated, 'var(--d2s-success, #28a745)'));
        reportKpis.appendChild(createKpiCard(totalStats.updated, TRANS.syncReportUpdated, 'var(--d2s-primary, #029e9c)'));
        reportKpis.appendChild(createKpiCard(totalStats.skipped, TRANS.syncReportSkipped, 'var(--d2s-warning, #ffc107)'));
        reportKpis.appendChild(createKpiCard(totalStats.errors, TRANS.syncReportErrors, 'var(--d2s-danger, #dc3545)'));

        // Stock sync indicator (Task 3 — AC4)
        if (totalStats.stockSyncEnabled && (totalStats.created > 0 || totalStats.updated > 0)) {
            var stockBanner = document.createElement('div');
            stockBanner.className = 'd2s-alert-banner d2s-alert--info d2s-stock-sync-banner';
            stockBanner.style.marginBottom = '12px';
            var stockIcon = document.createElement('i');
            stockIcon.className = 'fa fa-boxes';
            stockBanner.appendChild(stockIcon);
            stockBanner.appendChild(document.createTextNode(' ' + TRANS.syncReportStocks));
            reportErrors.parentNode.insertBefore(stockBanner, reportErrors);
        }

        // Story hash-images-survit-a-la-purge-et-bloque-le-renvoi (AC3) : bandeau visible sur
        // CET écran (celui utilisé par Xavier Hubier trois fois sans succès) dès qu'un renvoi
        // d'images a été forcé malgré un hash Dolibarr identique — signe que la destination
        // Shopify avait divergé (produit/médias supprimés ou recréés).
        if (totalStats.imagesResyncForced > 0) {
            var imagesResyncBanner = document.createElement('div');
            imagesResyncBanner.className = 'd2s-alert-banner d2s-alert--warning d2s-images-resync-banner';
            imagesResyncBanner.style.marginBottom = '12px';
            var imagesResyncIcon = document.createElement('i');
            imagesResyncIcon.className = 'fa fa-exclamation-triangle';
            imagesResyncBanner.appendChild(imagesResyncIcon);
            imagesResyncBanner.appendChild(document.createTextNode(' ' + TRANS.syncReportImagesResyncForced.replace('%s', String(totalStats.imagesResyncForced))));
            reportErrors.parentNode.insertBefore(imagesResyncBanner, reportErrors);
        }

        // Le produit est reparti SANS PHOTO parce que le module n'a trouvé aucune image côté
        // Dolibarr — ni index, ni disque. L'écran le dit maintenant, et nomme les produits :
        // sans cela, une synchronisation « réussie » laissait un catalogue sans visuels.
        if (totalStats.imagesNoSourceFound > 0) {
            var noSourceBanner = document.createElement('div');
            noSourceBanner.className = 'd2s-alert-banner d2s-alert--warning d2s-images-nosource-banner';
            noSourceBanner.style.marginBottom = '12px';
            var noSourceIcon = document.createElement('i');
            noSourceIcon.className = 'fa fa-exclamation-triangle';
            noSourceBanner.appendChild(noSourceIcon);
            var noSourceText = TRANS.syncReportImagesNoSource.replace('%s', String(totalStats.imagesNoSourceFound));
            if (totalStats.imagesNoSourceFoundRefs.length > 0) {
                noSourceText += ' — ' + totalStats.imagesNoSourceFoundRefs.join(', ');
            }
            noSourceBanner.appendChild(document.createTextNode(' ' + noSourceText));
            reportErrors.parentNode.insertBefore(noSourceBanner, reportErrors);
        }

        // Hotfix 2.5.7 (re-review 27/09/2026, MEDIUM) : ces deux compteurs existaient déjà côté
        // résumé LOG_ERR/LOG_WARNING et sortie CRON, mais restaient invisibles sur CET écran de
        // synchronisation manuelle. `stockSyncFailed` : articles non synchronisés ce cycle
        // (userErrors Shopify OU référence de stock non résolue à l'emplacement).
        if (totalStats.stockSyncFailed > 0) {
            var stockFailedBanner = document.createElement('div');
            stockFailedBanner.className = 'd2s-alert-banner d2s-alert--warning d2s-stock-sync-failed-banner';
            stockFailedBanner.style.marginBottom = '12px';
            var stockFailedIcon = document.createElement('i');
            stockFailedIcon.className = 'fa fa-exclamation-triangle';
            stockFailedBanner.appendChild(stockFailedIcon);
            stockFailedBanner.appendChild(document.createTextNode(' ' + TRANS.syncReportStockSyncFailed.replace('%s', String(totalStats.stockSyncFailed))));
            reportErrors.parentNode.insertBefore(stockFailedBanner, reportErrors);
        }

        // `stockSyncLocationErrors` : sous-ensemble STRUCTUREL (emplacement Shopify
        // invalide/introuvable) — un problème de CONFIGURATION, pas un simple aléa réseau ;
        // bandeau distinct, plus grave (danger, pas warning), du précédent.
        if (totalStats.stockSyncLocationErrors > 0) {
            var stockLocationBanner = document.createElement('div');
            stockLocationBanner.className = 'd2s-alert-banner d2s-alert--error d2s-stock-location-error-banner';
            stockLocationBanner.style.marginBottom = '12px';
            var stockLocationIcon = document.createElement('i');
            stockLocationIcon.className = 'fa fa-exclamation-circle';
            stockLocationBanner.appendChild(stockLocationIcon);
            stockLocationBanner.appendChild(document.createTextNode(' ' + TRANS.syncReportStockLocationErrors.replace('%s', String(totalStats.stockSyncLocationErrors))));
            reportErrors.parentNode.insertBefore(stockLocationBanner, reportErrors);
        }

        // Story stock-article-non-active-emplacement-reselection-perpetuelle (AC3) : articles
        // ACTUELLEMENT plafonnés (référence de stock non résolue depuis N cycles consécutifs,
        // action manuelle requise côté Shopify) — distinct du bandeau ci-dessus (erreur GraphQL
        // GLOBALE d'emplacement), ce cas ne produit AUCUNE erreur GraphQL.
        if (totalStats.stockLocationCapped > 0) {
            var stockCappedBanner = document.createElement('div');
            stockCappedBanner.className = 'd2s-alert-banner d2s-alert--warning d2s-stock-location-capped-banner';
            stockCappedBanner.style.marginBottom = '12px';
            var stockCappedIcon = document.createElement('i');
            stockCappedIcon.className = 'fa fa-hourglass-half';
            stockCappedBanner.appendChild(stockCappedIcon);
            stockCappedBanner.appendChild(document.createTextNode(' ' + TRANS.syncReportStockLocationCapped.replace('%s', String(totalStats.stockLocationCapped))));
            reportErrors.parentNode.insertBefore(stockCappedBanner, reportErrors);
        }

        // Error details — safe DOM construction
        while (reportErrors.firstChild) { reportErrors.removeChild(reportErrors.firstChild); }
        reportErrors.style.display = 'block';

        if (errorMessage) {
            var errBanner = document.createElement('div');
            errBanner.className = 'd2s-alert-banner d2s-alert--error';
            var errStrong = document.createElement('strong');
            errStrong.textContent = errorMessage;
            errBanner.appendChild(errStrong);
            reportErrors.appendChild(errBanner);
            retryButton.style.display = 'inline-block';
        } else if (allErrorDetails.length > 0) {
            var details = document.createElement('details');
            details.className = 'd2s-collapsible';
            var summary = document.createElement('summary');
            summary.className = 'd2s-collapsible-header';
            summary.textContent = TRANS.syncReportErrorDetail + ' (' + allErrorDetails.length + ')';
            details.appendChild(summary);

            var ul = document.createElement('ul');
            ul.style.margin = '8px 0 0 0';
            ul.style.paddingLeft = '20px';
            for (var j = 0; j < allErrorDetails.length; j++) {
                var li = document.createElement('li');
                var errItem = allErrorDetails[j];
                if (typeof errItem === 'string') {
                    li.textContent = errItem;
                } else if (typeof errItem === 'object') {
                    li.textContent = errItem.message || errItem.error || JSON.stringify(errItem);
                }
                ul.appendChild(li);
            }
            details.appendChild(ul);
            reportErrors.appendChild(details);
            retryButton.style.display = 'inline-block';
        } else {
            var successBanner = document.createElement('div');
            successBanner.className = 'd2s-alert-banner d2s-alert--success';
            successBanner.textContent = TRANS.syncReportNoErrors;
            reportErrors.appendChild(successBanner);
            retryButton.style.display = 'none';
        }
    }

    /**
     * Retry button
     */
    if (retryButton) {
        retryButton.addEventListener('click', function() {
            reportSection.style.display = 'none';
            syncButton.click();
        });
    }

    /**
     * Direction change — toggle stats
     */
    if (typeof jQuery !== 'undefined') {
        jQuery('#sync_type').on('change', function() {
            var dir = jQuery(this).val();
            if (dir === 'shopify_to_dolibarr') {
                jQuery('#stats-dolibarr').hide();
                jQuery('#stats-shopify').show();
                // M4 fix: Hide search filter — Shopify GraphQL import does not support ref filtering
                jQuery('#search_ref').closest('.form-group').hide();
                jQuery('#search_results').hide();
            } else {
                jQuery('#stats-dolibarr').show();
                jQuery('#stats-shopify').hide();
                jQuery('#search_ref').closest('.form-group').show();
            }
        });

        // Initialize display based on selected direction
        var initialDir = jQuery('#sync_type').val();
        if (initialDir === 'shopify_to_dolibarr') {
            jQuery('#stats-dolibarr').hide();
            jQuery('#stats-shopify').show();
            jQuery('#search_ref').closest('.form-group').hide();
        }

        // AJAX product search (kept from v2.1.6)
        var searchTimer;
        jQuery("#search_ref").on('input', function() {
            clearTimeout(searchTimer);
            var searchTerm = jQuery(this).val().trim();
            if (searchTerm.length < 2) {
                jQuery("#search_results").hide();
                return;
            }
            searchTimer = setTimeout(function() {
                jQuery.ajax({
                    url: <?php echo json_encode(dol_buildpath('/doli2shop/ajax/search_products.php', 1)); ?>,
                    type: "POST",
                    dataType: "json",
                    data: {
                        term: searchTerm,
                        category_id: <?php echo (int)$defaultCategoryId; ?>,
                        token: csrfToken
                    },
                    beforeSend: function() {
                        jQuery("#productList").text(<?php echo json_encode(html_entity_decode($langs->trans("Loading"), ENT_QUOTES, 'UTF-8')); ?> + '...');
                        jQuery("#search_results").show();
                    },
                    success: function(data) {
                        if (!data || data.length === 0) {
                            jQuery("#productCount").text("0");
                            jQuery("#productList").text(<?php echo json_encode(html_entity_decode($langs->trans("NoProductsFound"), ENT_QUOTES, 'UTF-8')); ?>);
                        } else {
                            jQuery("#productCount").text(data.length);
                            displayProductResults(data);
                        }
                    },
                    error: function() {
                        jQuery("#productCount").text("0");
                        jQuery("#productList").text(<?php echo json_encode(html_entity_decode($langs->trans("SearchError"), ENT_QUOTES, 'UTF-8')); ?>);
                    }
                });
            }, 300);
        });
    }

    /**
     * Display product search results using safe DOM methods
     */
    function displayProductResults(products) {
        var container = document.getElementById('productList');
        while (container.firstChild) { container.removeChild(container.firstChild); }

        for (var k = 0; k < products.length; k++) {
            var product = products[k];
            var row = document.createElement('div');
            row.style.cssText = 'padding: 8px; border-bottom: 1px solid #eee; display: -webkit-box; display: -ms-flexbox; display: flex; -webkit-box-pack: justify; -ms-flex-pack: justify; justify-content: space-between; -webkit-box-align: center; -ms-flex-align: center; align-items: center;';

            var infoDiv = document.createElement('div');
            var refEl = document.createElement('strong');
            refEl.style.color = '#0066cc';
            refEl.textContent = product.value || '';
            infoDiv.appendChild(refEl);
            infoDiv.appendChild(document.createElement('br'));
            var labelEl = document.createElement('span');
            labelEl.style.cssText = 'color: #666; font-size: 0.9em;';
            labelEl.textContent = product.label || '';
            infoDiv.appendChild(labelEl);
            row.appendChild(infoDiv);

            var statusEl = document.createElement('span');
            statusEl.style.fontSize = '0.8em';
            if (product.sync_status) {
                statusEl.style.color = '#28a745';
                statusEl.textContent = <?php echo json_encode(html_entity_decode($langs->trans("Synchronized"), ENT_QUOTES, 'UTF-8')); ?>;
            } else {
                statusEl.style.color = '#ffc107';
                statusEl.textContent = <?php echo json_encode(html_entity_decode($langs->trans("NotSynchronized"), ENT_QUOTES, 'UTF-8')); ?>;
            }
            row.appendChild(statusEl);
            container.appendChild(row);
        }
    }
})();
</script>

<script>
/**
 * Story 52-2 — Recalage initial en masse du stock Dolibarr -> Shopify (AJAX batch, indépendant
 * du moteur de synchronisation produits ci-dessus). Réutilise le même pattern (curseur/offset,
 * garde CSRF, progression, rapport) via ajax/stock_recalage_batch.php.
 */
(function() {
    'use strict';

    <?php
    $recalageJsTransKeys = array(
        'stockRecalageButton' => 'StockRecalageButton',
        'stockRecalageInProgress' => 'StockRecalageInProgress',
        'stockRecalageComplete' => 'StockRecalageComplete',
        'stockRecalagePushed' => 'StockRecalagePushed',
        'stockRecalageSkipped' => 'StockRecalageSkipped',
        'stockRecalageErrors' => 'StockRecalageErrors',
        'stockRecalageNoErrors' => 'StockRecalageNoErrors',
        'stockRecalageErrorDetail' => 'StockRecalageErrorDetail',
        'stockRecalageDisabledWarning' => 'StockRecalageDisabledWarning',
        'errorSync' => 'ErrorSync',
    );
    $recalageJsTransValues = array();
    foreach ($recalageJsTransKeys as $jsKey => $langKey) {
        $recalageJsTransValues[$jsKey] = html_entity_decode($langs->trans($langKey), ENT_QUOTES, 'UTF-8');
    }
    ?>
    var RTRANS = <?php echo json_encode($recalageJsTransValues, JSON_UNESCAPED_UNICODE); ?>;
    var RCONFIRM = <?php echo json_encode(html_entity_decode($recalageConfirmMessage, ENT_QUOTES, 'UTF-8')); ?>;

    var ajaxUrl = <?php echo json_encode(dol_buildpath('/doli2shop/ajax/stock_recalage_batch.php', 1)); ?>;
    var csrfToken = <?php echo json_encode(currentToken()); ?>;
    var storeId = <?php echo json_encode($recalageStoreId); ?>;

    var button = document.getElementById('d2s-recalage-button');
    if (!button || typeof jQuery === 'undefined') {
        return;
    }

    var progressSection = document.getElementById('d2s-recalage-progress');
    var progressText = document.getElementById('d2s-recalage-progress-text');
    var progressFill = document.getElementById('d2s-recalage-progress-fill');
    var progressBar = document.getElementById('d2s-recalage-progressbar');
    var statusDiv = document.getElementById('d2s-recalage-status');
    var spinner = document.getElementById('d2s-recalage-spinner');
    var reportSection = document.getElementById('d2s-recalage-report');
    var reportKpis = document.getElementById('d2s-recalage-report-kpis');
    var reportErrors = document.getElementById('d2s-recalage-report-errors');

    var totals = { processed: 0, pushed: 0, skipped: 0, errors: 0 };
    var allErrorDetails = [];
    var totalToProcess = 0;
    var isRunning = false;

    function createKpiCard(value, label, color) {
        var card = document.createElement('div');
        card.className = 'd2s-kpi-card';
        card.setAttribute('style', '-webkit-box-flex: 1; -ms-flex: 1 1 120px; flex: 1 1 120px; margin: 4px; text-align: center;');

        var valDiv = document.createElement('div');
        valDiv.className = 'd2s-kpi-value';
        valDiv.style.color = color;
        valDiv.textContent = String(value);

        var labelDiv = document.createElement('div');
        labelDiv.className = 'd2s-kpi-label';
        labelDiv.textContent = label;

        card.appendChild(valDiv);
        card.appendChild(labelDiv);
        return card;
    }

    button.addEventListener('click', function() {
        if (isRunning) {
            return;
        }
        if (!window.confirm(RCONFIRM)) {
            return;
        }

        isRunning = true;
        totals = { processed: 0, pushed: 0, skipped: 0, errors: 0 };
        allErrorDetails = [];
        totalToProcess = 0;

        button.disabled = true;
        button.textContent = RTRANS.stockRecalageInProgress;

        progressSection.style.display = 'block';
        reportSection.style.display = 'none';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 / ?';
        statusDiv.textContent = '';
        spinner.className = 'fa fa-sync fa-spin';
        spinner.style.color = '';

        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_count', store_id: storeId, token: csrfToken },
            success: function(data) {
                if (!data || !data.success) {
                    finish(RTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }
                if (!data.enabled) {
                    finish(RTRANS.stockRecalageDisabledWarning);
                    return;
                }
                totalToProcess = data.total || 0;
                progressText.textContent = '0 / ' + totalToProcess;
                if (totalToProcess === 0) {
                    finish();
                    return;
                }
                runBatch(0);
            },
            error: function(xhr, status, err) {
                finish(RTRANS.errorSync + ': ' + err);
            }
        });
    });

    function runBatch(offset) {
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'recalage_batch',
                offset: offset,
                store_id: storeId,
                token: csrfToken
            },
            success: function(data) {
                if (!data || !data.success) {
                    finish(RTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }

                totals.processed += (data.processed || 0);
                totals.pushed += (data.pushed || 0);
                totals.skipped += (data.skipped || 0);
                totals.errors += (data.errors || 0);

                if (data.errorDetails && data.errorDetails.length > 0) {
                    for (var i = 0; i < data.errorDetails.length; i++) {
                        allErrorDetails.push(data.errorDetails[i]);
                    }
                }

                var pct = totalToProcess > 0 ? Math.min(Math.round((totals.processed / totalToProcess) * 100), 100) : 100;
                progressFill.style.width = pct + '%';
                progressBar.setAttribute('aria-valuenow', String(pct));
                progressText.textContent = totals.processed + ' / ' + totalToProcess;
                statusDiv.textContent = RTRANS.stockRecalagePushed + ': ' + totals.pushed +
                    ' | ' + RTRANS.stockRecalageSkipped + ': ' + totals.skipped +
                    ' | ' + RTRANS.stockRecalageErrors + ': ' + totals.errors;

                if (data.hasMore) {
                    runBatch(parseInt(data.cursor || '0', 10));
                } else {
                    persistAndFinish();
                }
            },
            error: function(xhr, status, err) {
                finish(RTRANS.errorSync + ': ' + err);
            }
        });
    }

    function persistAndFinish() {
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'finish',
                pushed_total: totals.pushed,
                errors_total: totals.errors,
                store_id: storeId,
                token: csrfToken
            },
            complete: function() {
                finish();
            }
        });
    }

    function finish(errorMessage) {
        isRunning = false;

        spinner.className = errorMessage ? 'fa fa-exclamation-triangle' : 'fa fa-check-circle';
        spinner.style.color = errorMessage ? 'var(--d2s-danger, #dc3545)' : 'var(--d2s-success, #28a745)';

        button.disabled = false;
        button.textContent = RTRANS.stockRecalageButton;

        reportSection.style.display = 'block';

        while (reportKpis.firstChild) { reportKpis.removeChild(reportKpis.firstChild); }
        reportKpis.appendChild(createKpiCard(totals.pushed, RTRANS.stockRecalagePushed, 'var(--d2s-success, #28a745)'));
        reportKpis.appendChild(createKpiCard(totals.skipped, RTRANS.stockRecalageSkipped, 'var(--d2s-warning, #ffc107)'));
        reportKpis.appendChild(createKpiCard(totals.errors, RTRANS.stockRecalageErrors, 'var(--d2s-danger, #dc3545)'));

        while (reportErrors.firstChild) { reportErrors.removeChild(reportErrors.firstChild); }
        reportErrors.style.display = 'block';

        if (errorMessage) {
            var errBanner = document.createElement('div');
            errBanner.className = 'd2s-alert-banner d2s-alert--error';
            var errStrong = document.createElement('strong');
            errStrong.textContent = errorMessage;
            errBanner.appendChild(errStrong);
            reportErrors.appendChild(errBanner);
        } else if (allErrorDetails.length > 0) {
            var details = document.createElement('details');
            details.className = 'd2s-collapsible';
            var summary = document.createElement('summary');
            summary.className = 'd2s-collapsible-header';
            summary.textContent = RTRANS.stockRecalageErrorDetail + ' (' + allErrorDetails.length + ')';
            details.appendChild(summary);

            var ul = document.createElement('ul');
            ul.style.margin = '8px 0 0 0';
            ul.style.paddingLeft = '20px';
            for (var j = 0; j < allErrorDetails.length; j++) {
                var li = document.createElement('li');
                li.textContent = String(allErrorDetails[j]);
                ul.appendChild(li);
            }
            details.appendChild(ul);
            reportErrors.appendChild(details);
        } else {
            var successBanner = document.createElement('div');
            successBanner.className = 'd2s-alert-banner d2s-alert--success';
            successBanner.textContent = RTRANS.stockRecalageNoErrors;
            reportErrors.appendChild(successBanner);
        }
    }
})();
</script>

<script>
/**
 * Hotfix 2.4.4 (2/2) — Réparation batch des imports existants en vraies déclinaisons Dolibarr
 * (AJAX batch, indépendant du moteur de synchronisation produits et du recalage stock).
 * Réutilise le même pattern (curseur/offset, garde CSRF, progression, rapport) via
 * ajax/variant_repair_batch.php.
 */
(function() {
    'use strict';

    <?php
    $repairJsTransKeys = array(
        'variantRepairButton' => 'VariantRepairButton',
        'variantRepairInProgress' => 'VariantRepairInProgress',
        'variantRepairComplete' => 'VariantRepairComplete',
        'variantRepairRepaired' => 'VariantRepairRepaired',
        'variantRepairSkipped' => 'VariantRepairSkipped',
        'variantRepairErrors' => 'VariantRepairErrors',
        'variantRepairLabelsFixed' => 'VariantRepairLabelsFixed',
        'variantRepairNoErrors' => 'VariantRepairNoErrors',
        'variantRepairErrorDetail' => 'VariantRepairErrorDetail',
        'variantRepairDisabledWarning' => 'VariantRepairDisabledWarning',
        'variantRepairUnfixable' => 'VariantRepairUnfixable',
        'variantRepairFinishNote' => 'VariantRepairFinishNote',
        // HIGH-A (code review 63-17) : traduction avec %s littéral, substitué en JS au moment du
        // clic avec le total AUTORITATIF renvoyé par get_count (data.labelOnlyTotal) — jamais le
        // total figé au chargement de la page (pattern déjà utilisé par variantRepairFinishNote
        // ci-dessus).
        'variantRepairNothingToDoCheckLabels' => 'VariantRepairNothingToDoCheckLabels',
        'errorSync' => 'ErrorSync',
    );
    $repairJsTransValues = array();
    foreach ($repairJsTransKeys as $jsKey => $langKey) {
        $repairJsTransValues[$jsKey] = html_entity_decode($langs->trans($langKey), ENT_QUOTES, 'UTF-8');
    }
    ?>
    var VTRANS = <?php echo json_encode($repairJsTransValues, JSON_UNESCAPED_UNICODE); ?>;
    var VCONFIRM = <?php echo json_encode(html_entity_decode($repairConfirmMessage, ENT_QUOTES, 'UTF-8')); ?>;
    // HIGH-A : deux variantes supplémentaires du message de confirmation, choisies en JS selon
    // la composition RÉELLE du lot (état de la case au moment du clic) — jamais un message unique
    // qui annoncerait "rattacher" un lot 100% libellés.
    var VCONFIRM_LABELS_ONLY = <?php echo json_encode(html_entity_decode($repairConfirmLabelsOnlyMessage, ENT_QUOTES, 'UTF-8')); ?>;
    var VCONFIRM_BOTH = <?php echo json_encode(html_entity_decode($repairConfirmBothMessage, ENT_QUOTES, 'UTF-8')); ?>;
    // Compteurs par pool (page load) — utilisés UNIQUEMENT pour choisir le bon message de
    // confirmation ; le traitement réel s'appuie sur le total AUTORITATIF renvoyé par get_count
    // (potentiellement plus à jour), jamais sur ces valeurs figées.
    var repairAttachOnlyCount = <?php echo json_encode($repairAttachOnlyCount); ?>;
    var repairLabelOnlyCount = <?php echo json_encode($repairLabelOnlyCount); ?>;

    var ajaxUrl = <?php echo json_encode(dol_buildpath('/doli2shop/ajax/variant_repair_batch.php', 1)); ?>;
    var csrfToken = <?php echo json_encode(currentToken()); ?>;
    var storeId = <?php echo json_encode($repairStoreId); ?>;

    var button = document.getElementById('d2s-repair-button');
    if (!button || typeof jQuery === 'undefined') {
        return;
    }

    var fixLabelsCheckbox = document.getElementById('d2s-repair-fix-labels');
    var progressSection = document.getElementById('d2s-repair-progress');
    var progressText = document.getElementById('d2s-repair-progress-text');
    var progressFill = document.getElementById('d2s-repair-progress-fill');
    var progressBar = document.getElementById('d2s-repair-progressbar');
    var statusDiv = document.getElementById('d2s-repair-status');
    var spinner = document.getElementById('d2s-repair-spinner');
    var reportSection = document.getElementById('d2s-repair-report');
    var reportKpis = document.getElementById('d2s-repair-report-kpis');
    var reportErrors = document.getElementById('d2s-repair-report-errors');

    var totals = { processed: 0, repaired: 0, skipped: 0, errors: 0, labelsFixed: 0, unfixable: 0 };
    var allErrorDetails = [];
    var totalToProcess = 0;
    var isRunning = false;

    function createKpiCard(value, label, color) {
        var card = document.createElement('div');
        card.className = 'd2s-kpi-card';
        card.setAttribute('style', '-webkit-box-flex: 1; -ms-flex: 1 1 120px; flex: 1 1 120px; margin: 4px; text-align: center;');

        var valDiv = document.createElement('div');
        valDiv.className = 'd2s-kpi-value';
        valDiv.style.color = color;
        valDiv.textContent = String(value);

        var labelDiv = document.createElement('div');
        labelDiv.className = 'd2s-kpi-label';
        labelDiv.textContent = label;

        card.appendChild(valDiv);
        card.appendChild(labelDiv);
        return card;
    }

    button.addEventListener('click', function() {
        if (isRunning) {
            return;
        }

        // HIGH-A (code review 63-17) : l'état de la case est lu ICI, AVANT le message de
        // confirmation — plus le message n'annonce jamais un travail que l'état courant de la
        // case ne va pas effectuer.
        var fixLabels = fixLabelsCheckbox && fixLabelsCheckbox.checked ? 1 : 0;

        var confirmMsg;
        if (fixLabels && repairAttachOnlyCount > 0 && repairLabelOnlyCount > 0) {
            confirmMsg = VCONFIRM_BOTH;
        } else if (fixLabels && repairAttachOnlyCount <= 0) {
            confirmMsg = VCONFIRM_LABELS_ONLY;
        } else {
            // Case décochée (ou rien à corriger côté libellés) : seul le pool "rattachement" est
            // annoncé — c'est aussi tout ce que repair_batch(fix_labels=0) va traiter.
            confirmMsg = VCONFIRM;
        }
        if (!window.confirm(confirmMsg)) {
            return;
        }

        isRunning = true;
        totals = { processed: 0, repaired: 0, skipped: 0, errors: 0, labelsFixed: 0, unfixable: 0 };
        allErrorDetails = [];
        totalToProcess = 0;

        button.disabled = true;
        button.textContent = VTRANS.variantRepairInProgress;

        progressSection.style.display = 'block';
        reportSection.style.display = 'none';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 / ?';
        statusDiv.textContent = '';
        spinner.className = 'fa fa-sync fa-spin';
        spinner.style.color = '';

        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            // HIGH-A : fix_labels transmis à get_count — le total renvoyé DOIT désormais
            // coïncider avec ce que repair_batch(fixLabels) va réellement traiter pour ce même
            // état de la case (cf ajax/variant_repair_batch.php).
            data: { action: 'get_count', store_id: storeId, fix_labels: fixLabels, token: csrfToken },
            success: function(data) {
                if (!data || !data.success) {
                    finish(VTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }
                if (!data.enabled) {
                    finish(VTRANS.variantRepairDisabledWarning);
                    return;
                }
                totalToProcess = data.total || 0;
                progressText.textContent = '0 / ' + totalToProcess;
                if (totalToProcess === 0) {
                    // HIGH-A : ne JAMAIS laisser un total à 0 muet quand la case décochée en est
                    // la cause (pool "libellés" non vide côté serveur, data.labelOnlyTotal — total
                    // AUTORITATIF, pas la valeur figée au chargement de la page) — c'est
                    // exactement le rapport "0/0/0" sans explication relevé par la review.
                    var explanation;
                    if (!fixLabels && (data.labelOnlyTotal || 0) > 0) {
                        explanation = VTRANS.variantRepairNothingToDoCheckLabels.replace('%s', String(data.labelOnlyTotal));
                    }
                    finish(explanation);
                    return;
                }
                runBatch(0, fixLabels);
            },
            error: function(xhr, status, err) {
                finish(VTRANS.errorSync + ': ' + err);
            }
        });
    });

    function runBatch(cursor, fixLabels) {
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'repair_batch',
                cursor: cursor,
                store_id: storeId,
                fix_labels: fixLabels,
                token: csrfToken
            },
            success: function(data) {
                if (!data || !data.success) {
                    finish(VTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }

                totals.processed += (data.processed || 0);
                totals.repaired += (data.repaired || 0);
                totals.skipped += (data.skipped || 0);
                totals.errors += (data.errors || 0);
                totals.labelsFixed += (data.labelsFixed || 0);
                totals.unfixable += (data.unfixable || 0);

                if (data.errorDetails && data.errorDetails.length > 0) {
                    for (var i = 0; i < data.errorDetails.length; i++) {
                        allErrorDetails.push(data.errorDetails[i]);
                    }
                }

                var pct = totalToProcess > 0 ? Math.min(Math.round((totals.processed / totalToProcess) * 100), 100) : 100;
                progressFill.style.width = pct + '%';
                progressBar.setAttribute('aria-valuenow', String(pct));
                progressText.textContent = totals.processed + ' / ' + totalToProcess;
                statusDiv.textContent = VTRANS.variantRepairRepaired + ': ' + totals.repaired +
                    ' | ' + VTRANS.variantRepairSkipped + ': ' + totals.skipped +
                    ' | ' + VTRANS.variantRepairErrors + ': ' + totals.errors;

                if (data.hasMore) {
                    // Curseur par clé (dernier fk_product vu) — PLUS un offset numérique (correction
                    // post-review 3-couches, cf. VariantRepairService::repairBatch()).
                    runBatch(parseInt(data.cursor || '0', 10), fixLabels);
                } else {
                    persistAndFinish();
                }
            },
            error: function(xhr, status, err) {
                finish(VTRANS.errorSync + ': ' + err);
            }
        });
    }

    function persistAndFinish() {
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'finish',
                store_id: storeId,
                token: csrfToken
            },
            complete: function() {
                finish();
            }
        });
    }

    function finish(errorMessage) {
        isRunning = false;

        spinner.className = errorMessage ? 'fa fa-exclamation-triangle' : 'fa fa-check-circle';
        spinner.style.color = errorMessage ? 'var(--d2s-danger, #dc3545)' : 'var(--d2s-success, #28a745)';

        button.disabled = false;
        button.textContent = VTRANS.variantRepairButton;

        reportSection.style.display = 'block';

        while (reportKpis.firstChild) { reportKpis.removeChild(reportKpis.firstChild); }
        reportKpis.appendChild(createKpiCard(totals.repaired, VTRANS.variantRepairRepaired, 'var(--d2s-success, #28a745)'));
        reportKpis.appendChild(createKpiCard(totals.skipped, VTRANS.variantRepairSkipped, 'var(--d2s-warning, #ffc107)'));
        reportKpis.appendChild(createKpiCard(totals.errors, VTRANS.variantRepairErrors, 'var(--d2s-danger, #dc3545)'));
        if (totals.labelsFixed > 0) {
            reportKpis.appendChild(createKpiCard(totals.labelsFixed, VTRANS.variantRepairLabelsFixed, 'var(--d2s-primary, #029e9c)'));
        }
        if (totals.unfixable > 0) {
            reportKpis.appendChild(createKpiCard(totals.unfixable, VTRANS.variantRepairUnfixable, 'var(--d2s-secondary, #6c757d)'));
        }

        while (reportErrors.firstChild) { reportErrors.removeChild(reportErrors.firstChild); }
        reportErrors.style.display = 'block';

        // Barre de progression/rapport honnêtes (correction post-review, LOW) : le total figé
        // côté client (totalToProcess) peut diverger du nombre réel de candidats restants d'une
        // passe à l'autre (pool qui rétrécit). En fin de passe (pas d'erreur bloquante), on
        // rappelle explicitement le nombre d'irréparables permanents et le fait que les candidats
        // ignorés pour cause de licence boutique/erreur transitoire seront retentés automatiquement.
        if (!errorMessage && (totals.unfixable > 0 || totals.skipped > 0)) {
            var finishNote = document.createElement('p');
            finishNote.className = 'opacitymedium';
            finishNote.textContent = VTRANS.variantRepairFinishNote.replace('%s', String(totals.unfixable));
            reportErrors.appendChild(finishNote);
        }

        if (errorMessage) {
            var errBanner = document.createElement('div');
            errBanner.className = 'd2s-alert-banner d2s-alert--error';
            var errStrong = document.createElement('strong');
            errStrong.textContent = errorMessage;
            errBanner.appendChild(errStrong);
            reportErrors.appendChild(errBanner);
        } else if (allErrorDetails.length > 0) {
            var details = document.createElement('details');
            details.className = 'd2s-collapsible';
            var summary = document.createElement('summary');
            summary.className = 'd2s-collapsible-header';
            summary.textContent = VTRANS.variantRepairErrorDetail + ' (' + allErrorDetails.length + ')';
            details.appendChild(summary);

            var ul = document.createElement('ul');
            ul.style.margin = '8px 0 0 0';
            ul.style.paddingLeft = '20px';
            for (var j = 0; j < allErrorDetails.length; j++) {
                var li = document.createElement('li');
                li.textContent = String(allErrorDetails[j]);
                ul.appendChild(li);
            }
            details.appendChild(ul);
            reportErrors.appendChild(details);
        } else {
            var successBanner = document.createElement('div');
            successBanner.className = 'd2s-alert-banner d2s-alert--success';
            successBanner.textContent = VTRANS.variantRepairNoErrors;
            reportErrors.appendChild(successBanner);
        }
    }
})();
</script>

<?php
// Pied de page
print dol_get_fiche_end();

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

llxFooter();
$db->close();
