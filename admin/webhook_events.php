<?php
/**
 * @file        admin/webhook_events.php
 * @brief       Log Viewer for webhook events with filters, pagination, and expandable details
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.4.8
 * @since       2.2.0
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

// Libraries
dol_include_once('/core/lib/admin.lib.php');
require_once '../lib/doli2shop.lib.php';
require_once dirname(__FILE__) . '/../class/storeservice.class.php';

// Load translation files required by the page
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

/*
 * Parameters
 */

$action = GETPOST('action', 'aZ09');
$filterTopic = GETPOST('filter_topic', 'alphanohtml');
$filterResult = GETPOST('filter_result', 'alpha');
$filterDateFrom = GETPOST('filter_date_from', 'alpha');
$filterDateTo = GETPOST('filter_date_to', 'alpha');
// Story 38.2 : filtre type d'objet Dolibarr + filtre boutique
$filterObjectType = GETPOST('filter_object_type', 'alphanohtml');
$filterShopDomain = GETPOST('filter_shop_domain', 'alphanohtml');

// Story 49-5 AC-5 : pré-sélection boutique active si filtre non encore posté
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
if (!GETPOST('filter_shop_domain_submitted', 'int') && $currentAdminStore !== null && (int) $currentAdminStore->rowid > 0) {
    $filterShopDomain = (string) $currentAdminStore->shop_domain;
}
// Story 36.3 : filtre events récupérés automatiquement (stuck)
$filterStuck = GETPOSTINT('filter_stuck') ? 1 : 0;
// Whitelist du type d'objet (sécurité : valeur injectée dans la requête)
$allowedObjectTypes = array('commande', 'facture', 'shipping', 'product');
if (!in_array($filterObjectType, $allowedObjectTypes, true)) {
    $filterObjectType = '';
}
// Domaine boutique : doit correspondre exactement à une valeur stockée (dropdown) ;
// on rejette tout format inattendu (lettres/chiffres/points/tirets uniquement)
if (!empty($filterShopDomain) && !preg_match('/^[a-zA-Z0-9.\-]+$/', $filterShopDomain)) {
    $filterShopDomain = '';
}
// Validate date format YYYY-MM-DD (server-side, HTML5 input type=date only protects client-side)
if (!empty($filterDateFrom) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $filterDateFrom = '';
}
if (!empty($filterDateTo) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $filterDateTo = '';
}
$page = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
// Story 38.2 : taille de page configurable (25/50/100)
$limit = GETPOSTINT('limit');
if (!in_array($limit, array(25, 50, 100), true)) {
    $limit = 25;
}
$offset = $page * $limit;

/*
 * Actions
 */

// Story 59-2 (AC1/AC2) : ces 3 actions faisaient sauter leur bloc EN SILENCE sur un jeton refusé
// (aucun message, aucune trace, aucun effet) — même défaut que celui qui a bloqué Nicolas Graillon
// sur admin/webhooks.php. Message écran + trace journal (LOG_WARNING) avant le bloc d'action.
if ($action == 'export_single_event' && !verifToken()) {
    dol_syslog("webhook_events.php: action 'export_single_event' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'replay_batch' && !verifToken()) {
    dol_syslog("webhook_events.php: action 'replay_batch' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'force_retry' && !verifToken()) {
    dol_syslog("webhook_events.php: action 'force_retry' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

// Export single event as JSON
if ($action == 'export_single_event' && verifToken()) {
    $eventRowId = GETPOSTINT('event_id');
    if ($eventRowId > 0) {
        $sqlExport = "SELECT * FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
        $sqlExport .= " WHERE rowid = ".((int) $eventRowId);
        $sqlExport .= " AND entity = ".((int) $conf->entity);

        $resExport = $db->query($sqlExport);
        if ($resExport) {
            $objExport = $db->fetch_object($resExport);
            if ($objExport) {
                $exportData = array(
                    'export_date' => date('Y-m-d H:i:s'),
                    'event_id' => (int) $objExport->rowid,
                    'webhook_id' => $objExport->webhook_id,
                    'topic' => $objExport->topic,
                    'shop_domain' => $objExport->shop_domain,
                    'api_version' => $objExport->api_version,
                    'verified' => (bool) $objExport->verified,
                    'status' => (int) $objExport->status,
                    'action_result' => $objExport->action_result,
                    'dolibarr_object_type' => $objExport->dolibarr_object_type,
                    'dolibarr_object_id' => $objExport->dolibarr_object_id ? (int) $objExport->dolibarr_object_id : null,
                    'processing_time_ms' => $objExport->processing_time_ms ? (int) $objExport->processing_time_ms : null,
                    'tries' => (int) $objExport->tries,
                    'last_error' => $objExport->last_error,
                    'payload' => $objExport->payload ? json_decode($objExport->payload) : null,
                    'date_reception' => $objExport->date_reception,
                    'date_traitement' => $objExport->date_traitement,
                );

                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="webhook_event_'.$eventRowId.'_'.date('Y-m-d_His').'.json"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                print json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }
    // If event not found, continue to page display
    setEventMessages($langs->trans("EventNotFound"), null, 'errors');
}

// Story 36.2 : rejeu batch des événements sélectionnés
if ($action == 'replay_batch' && verifToken()) {
    $eventIds = GETPOST('event_ids', 'array');
    require_once dirname(__FILE__).'/../class/webhookmanager.class.php';
    $replayManager = new WebhookManager($db);
    $nbReset = $replayManager->replayBatch($eventIds);
    if ($nbReset > 0) {
        setEventMessages($langs->trans("WebhookReplayDone", $nbReset), null, 'mesgs');
    } elseif ($nbReset === 0) {
        setEventMessages($langs->trans("WebhookReplayNone"), null, 'warnings');
    } else {
        setEventMessages($langs->trans("ErrorSQL"), null, 'errors');
    }
    header('Location: '.$_SERVER["PHP_SELF"]);
    exit;
}

// Story 36.2 : forcer le retry d'un event dead_letter
if ($action == 'force_retry' && verifToken()) {
    $eventRowId = GETPOSTINT('event_id');
    if ($eventRowId > 0) {
        require_once dirname(__FILE__).'/../class/webhookmanager.class.php';
        $replayManager = new WebhookManager($db);
        $nbReset = $replayManager->replayBatch(array($eventRowId));
        if ($nbReset > 0) {
            setEventMessages($langs->trans("WebhookReplayDone", $nbReset), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("WebhookReplayNone"), null, 'warnings');
        }
    }
    header('Location: '.$_SERVER["PHP_SELF"]);
    exit;
}


/*
 * View
 */

llxHeader('', $langs->trans("WebhookEventsLogTitle"), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// === CSS Isolation ===
print '<div class="doli2shop-page">';

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("WebhookEventsLogTitle"), $linkback, 'title_setup');

// Navigation tabs
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'events', $langs->trans("ShopifyIntegration"), -1, 'shopify_color@doli2shop');

// Barre de contexte boutique (Story 49-10)
doli2shopRenderAdminTopBar($db);

// Description
print '<p>'.$langs->trans("WebhookEventsLogDesc").'</p>';

// Story 36.3 : bandeau "événements récupérés automatiquement" (24h)
$sqlStuck = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
$sqlStuck .= " WHERE last_error LIKE '%auto-reset stuck%'";
$sqlStuck .= " AND date_reception >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
$sqlStuck .= " AND entity = ".((int) $conf->entity);
$resStuck = $db->query($sqlStuck);
if ($resStuck) {
    $objStuck = $db->fetch_object($resStuck);
    $db->free($resStuck);
    if ($objStuck && (int) $objStuck->nb > 0) {
        print '<div class="warning" style="margin-bottom: 15px;">';
        print dol_escape_htmltag($langs->trans("WebhookStuckRecoveredBanner", (int) $objStuck->nb));
        print ' <a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?filter_stuck=1">'.$langs->trans("WebhookStuckRecoveredLink").'</a>';
        print '</div>';
    }
}

// Helper format ms/s (cohérent avec la colonne temps de traitement)
if (!function_exists('d2s_format_ms')) {
    function d2s_format_ms($ms)
    {
        if ($ms === null) {
            return '-';
        }
        $ms = (int) $ms;
        return ($ms >= 1000) ? round($ms / 1000, 1).'s' : $ms.'ms';
    }
}

// ========================================
// Widget : performances par type d'événement (Story 36.1)
// ========================================
require_once dirname(__FILE__).'/../class/webhookmanager.class.php';
$perfManager = new WebhookManager($db);
$perfStats = $perfManager->getProcessingTimeStats(7);

print '<div style="margin-bottom: 20px;">';
print '<h3 style="margin-bottom: 8px;">'.$langs->trans("WebhookPerfStatsTitle").'</h3>';
if (!empty($perfStats)) {
    print '<table class="noborder" style="width: auto;">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans("WebhookColumnTopic").'</th>';
    print '<th style="text-align: right;">'.$langs->trans("WebhookPerfCount").'</th>';
    print '<th style="text-align: right;">'.$langs->trans("WebhookPerfP50").'</th>';
    print '<th style="text-align: right;">'.$langs->trans("WebhookPerfP95").'</th>';
    print '<th style="text-align: right;">'.$langs->trans("WebhookPerfP99").'</th>';
    print '<th style="text-align: right;">'.$langs->trans("WebhookPerfAvg").'</th>';
    print '</tr>';
    foreach ($perfStats as $stat) {
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($stat['topic']).'</td>';
        print '<td style="text-align: right;">'.((int) $stat['count']).'</td>';
        print '<td style="text-align: right;">'.dol_escape_htmltag(d2s_format_ms($stat['p50'])).'</td>';
        print '<td style="text-align: right;">'.dol_escape_htmltag(d2s_format_ms($stat['p95'])).'</td>';
        print '<td style="text-align: right;">'.dol_escape_htmltag(d2s_format_ms($stat['p99'])).'</td>';
        print '<td style="text-align: right;">'.dol_escape_htmltag(d2s_format_ms($stat['avg'])).'</td>';
        print '</tr>';
    }
    print '</table>';
} else {
    print '<p class="opacitymedium">'.$langs->trans("WebhookPerfNoData").'</p>';
}
print '</div>';

// ========================================
// Filters
// ========================================
$hasFilters = (!empty($filterTopic) || !empty($filterResult) || !empty($filterDateFrom) || !empty($filterDateTo)
    || !empty($filterObjectType) || !empty($filterShopDomain) || !empty($filterStuck) || $limit !== 25);

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
// Story 49-5 : distinguer premier chargement (pré-sélection auto) vs soumission volontaire
print '<input type="hidden" name="filter_shop_domain_submitted" value="1">';
print '<div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">';

// Topic filter
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterTopic").'</label>';
print '<select name="filter_topic" style="min-width: 160px;">';
print '<option value="">'.$langs->trans("All").'</option>';

// Get distinct topics from database
$sqlTopics = "SELECT DISTINCT topic FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
$sqlTopics .= " WHERE entity = ".((int) $conf->entity);
$sqlTopics .= " ORDER BY topic";
$resTopics = $db->query($sqlTopics);
if ($resTopics) {
    while ($objTopic = $db->fetch_object($resTopics)) {
        $selected = ($filterTopic === $objTopic->topic) ? ' selected' : '';
        print '<option value="'.dol_escape_htmltag($objTopic->topic).'"'.$selected.'>'.dol_escape_htmltag($objTopic->topic).'</option>';
    }
    $db->free($resTopics);
}
print '</select>';
print '</div>';

// Result filter (action_result)
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterResult").'</label>';
print '<select name="filter_result" style="min-width: 130px;">';
print '<option value="">'.$langs->trans("All").'</option>';
$resultOptions = array(
    'success' => $langs->trans("WebhookResultSuccess"),
    'error' => $langs->trans("WebhookResultError"),
    'skipped' => $langs->trans("WebhookResultSkipped"),
    'skipped_unpaid' => $langs->trans("WebhookResultSkippedUnpaid"),
    // MEDIUM-1 (Code Review du 09/09/2026) : distinct de 'skipped_unpaid' — displayFinancialStatus
    // indéterminé (absent/vide/valeur Shopify inconnue), signale une anomalie API à diagnostiquer.
    'skipped_fin_unknown' => $langs->trans("WebhookResultSkippedFinUnknown"),
    // Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (AC3) : `taxesIncluded`
    // absent/invalide dans la réponse GraphQL — refus fail-closed distinct, ni doublon ni refus financier.
    'skipped_taxes_unknown' => $langs->trans("WebhookResultSkippedTaxesUnknown"),
    // Story licence-gate-des-ecritures-sortantes (AC5) : l'evenement a ete refuse parce que la
    // licence est expiree et le delai de grace depasse. Sans cette entree, il etait introuvable
    // au filtre — et affiche « Traite », ce qui est l'inverse de ce qui s'est passe.
    'skipped_license' => $langs->trans("WebhookResultSkippedLicense"),
    'duplicate' => $langs->trans("WebhookResultDuplicate"),
);
foreach ($resultOptions as $resultKey => $resultLabel) {
    $selected = ($filterResult === $resultKey) ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($resultKey).'"'.$selected.'>'.dol_escape_htmltag($resultLabel).'</option>';
}
print '</select>';
print '</div>';

// Story 38.2 : Object type filter (Dolibarr object created by the webhook)
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterObjectType").'</label>';
print '<select name="filter_object_type" style="min-width: 130px;">';
print '<option value="">'.$langs->trans("All").'</option>';
$objectTypeOptions = array(
    'commande' => $langs->trans("WebhookObjectTypeOrder"),
    'facture' => $langs->trans("WebhookObjectTypeBill"),
    'shipping' => $langs->trans("WebhookObjectTypeShipment"),
    'product' => $langs->trans("WebhookObjectTypeProduct"),
);
foreach ($objectTypeOptions as $objTypeKey => $objTypeLabel) {
    $selected = ($filterObjectType === $objTypeKey) ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($objTypeKey).'"'.$selected.'>'.dol_escape_htmltag($objTypeLabel).'</option>';
}
print '</select>';
print '</div>';

// Story 38.2 + 49-5 : Shop domain filter (multi-boutique / tests)
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterShopDomain").'</label>';
print '<select name="filter_shop_domain" style="min-width: 160px;">';
// Story 49-5 : option "Toutes les boutiques" en tête (valeur vide)
$selectedAll = ($filterShopDomain === '') ? ' selected' : '';
print '<option value=""'.$selectedAll.'>'.$langs->trans("AllStores").'</option>';
// Story 49-9 : dropdown depuis StoreService::getAll() — value = shop_domain (string), pas rowid
$storeServiceForDropdown = new StoreService($db);
$allStoresForDropdown = $storeServiceForDropdown->getAll(false);
foreach ($allStoresForDropdown as $storeRow) {
    if (empty($storeRow->shop_domain)) {
        continue;
    }
    $selected = ($filterShopDomain === (string) $storeRow->shop_domain) ? ' selected' : '';
    $optionLabel = dol_escape_htmltag($storeRow->label . ' (' . $storeRow->shop_domain . ')');
    print '<option value="'.dol_escape_htmltag($storeRow->shop_domain).'"'.$selected.'>'.$optionLabel.'</option>';
}
print '</select>';
print '</div>';

// Date from
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterDateFrom").'</label>';
print '<input type="date" name="filter_date_from" value="'.dol_escape_htmltag($filterDateFrom).'" style="min-width: 140px;">';
print '</div>';

// Date to
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterDateTo").'</label>';
print '<input type="date" name="filter_date_to" value="'.dol_escape_htmltag($filterDateTo).'" style="min-width: 140px;">';
print '</div>';

// Story 38.2 : Page size selector
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterPageSize").'</label>';
print '<select name="limit" style="min-width: 80px;">';
foreach (array(25, 50, 100) as $limitOption) {
    $selected = ($limit === $limitOption) ? ' selected' : '';
    print '<option value="'.$limitOption.'"'.$selected.'>'.$limitOption.'</option>';
}
print '</select>';
print '</div>';

// Filter button
print '<div>';
print '<input type="submit" class="button" value="'.$langs->trans("WebhookFilterApply").'" style="margin-bottom: 0;">';
print '</div>';

// Reset button (only if filters active)
if ($hasFilters) {
    print '<div>';
    print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" class="butAction" style="padding: 4px 12px; font-size: 12px;">'.$langs->trans("WebhookFilterReset").'</a>';
    print '</div>';
}

print '</div>';
print '</form>';

// ========================================
// Build SQL query with filters
// ========================================
$sqlWhere = " WHERE entity = ".((int) $conf->entity);
if (!empty($filterTopic)) {
    $sqlWhere .= " AND topic = '".$db->escape($filterTopic)."'";
}
if (!empty($filterResult)) {
    $sqlWhere .= " AND action_result = '".$db->escape($filterResult)."'";
}
if (!empty($filterObjectType)) {
    // $filterObjectType déjà validé contre la whitelist en amont
    $sqlWhere .= " AND dolibarr_object_type = '".$db->escape($filterObjectType)."'";
}
if (!empty($filterShopDomain)) {
    $sqlWhere .= " AND shop_domain = '".$db->escape($filterShopDomain)."'";
}
if (!empty($filterStuck)) {
    // Story 36.3 : events récupérés automatiquement depuis un état bloqué
    $sqlWhere .= " AND last_error LIKE '%auto-reset stuck%'";
}
if (!empty($filterDateFrom)) {
    $sqlWhere .= " AND date_reception >= '".$db->escape($filterDateFrom)." 00:00:00'";
}
if (!empty($filterDateTo)) {
    $sqlWhere .= " AND date_reception <= '".$db->escape($filterDateTo)." 23:59:59'";
}

// Count total for pagination
$sqlCount = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events".$sqlWhere;
$resCount = $db->query($sqlCount);
$totalFiltered = 0;
if ($resCount) {
    $objCount = $db->fetch_object($resCount);
    $totalFiltered = (int) $objCount->total;
    $db->free($resCount);
}
$totalPages = ($totalFiltered > 0) ? ceil($totalFiltered / $limit) : 0;

// Fetch events
$sqlEvents = "SELECT rowid, webhook_id, topic, shop_domain, api_version, verified, status, tries, last_error, payload,";
$sqlEvents .= " date_reception, date_traitement, processing_time_ms,";
$sqlEvents .= " dolibarr_object_type, dolibarr_object_id, action_result";
$sqlEvents .= " FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
$sqlEvents .= $sqlWhere;
$sqlEvents .= " ORDER BY date_reception DESC";
$sqlEvents .= $db->plimit($limit, $offset);

$resqlEvents = $db->query($sqlEvents);

// ========================================
// Table display
// ========================================
if ($resqlEvents) {
    $numEvents = $db->num_rows($resqlEvents);

    // Summary line
    if ($totalFiltered > 0) {
        print '<div style="margin-bottom: 8px; font-size: 12px; color: #666;">';
        print $langs->trans("WebhookEventsShowing", ($offset + 1), min($offset + $limit, $totalFiltered), $totalFiltered);
        print '</div>';
    }

    // Story 36.2 : formulaire de rejeu batch englobant le tableau
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" id="d2s_replay_form">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="replay_batch">';

    print '<table class="noborder" width="100%">';
    print '<tr class="liste_titre">';
    print '<th style="width: 24px;"><input type="checkbox" id="d2s_select_all" title="'.$langs->trans("WebhookSelectAll").'"></th>';
    print '<th>'.$langs->trans("WebhookColumnDate").'</th>';
    print '<th>'.$langs->trans("WebhookColumnTopic").'</th>';
    print '<th>'.$langs->trans("WebhookColumnApiVersion").'</th>';
    print '<th>'.$langs->trans("WebhookColumnResult").'</th>';
    print '<th>'.$langs->trans("WebhookColumnObject").'</th>';
    print '<th>'.$langs->trans("WebhookColumnProcessingTime").'</th>';
    print '<th>'.$langs->trans("WebhookColumnActions").'</th>';
    print '</tr>';

    if ($numEvents == 0) {
        // Empty state
        print '<tr class="oddeven">';
        print '<td colspan="8" class="center" style="padding: 40px 20px;">';
        print '<div style="font-size: 14px; color: #666; margin-bottom: 8px;">';
        print '<i class="fas fa-inbox" style="font-size: 32px; color: #ccc; display: block; margin-bottom: 10px;"></i>';
        print $langs->trans("WebhookNoEvents");
        print '</div>';
        print '<div style="font-size: 12px; color: #999;">';
        print $langs->trans("WebhookNoEventsDesc");
        print '</div>';
        print '</td>';
        print '</tr>';
    }

    while ($event = $db->fetch_object($resqlEvents)) {
        print '<tr class="oddeven">';

        // Story 36.2 : checkbox de sélection pour rejeu batch
        print '<td style="text-align: center;"><input type="checkbox" name="event_ids[]" value="'.((int) $event->rowid).'" class="d2s_event_cb"></td>';

        // Date
        print '<td style="white-space: nowrap;">';
        print ($event->date_reception ? dol_print_date($db->jdate($event->date_reception), 'dayhour') : '-');
        print '</td>';

        // Topic
        print '<td><code style="font-size: 11px;">'.dol_escape_htmltag($event->topic).'</code></td>';

        // Version d'API utilisée à la réception du webhook
        print '<td><span style="font-size: 11px; color: #666;">'.dol_escape_htmltag($event->api_version ?: '-').'</span></td>';

        // Result badge (action_result)
        print '<td>';
        $actionResult = $event->action_result;
        if ($actionResult === 'success') {
            print '<span class="d2s-badge-success">'.$langs->trans("WebhookResultSuccess").'</span>';
        } elseif ($actionResult === 'error') {
            print '<span class="d2s-badge-error">'.$langs->trans("WebhookResultError").'</span>';
        } elseif ($actionResult === 'skipped') {
            print '<span class="d2s-badge-skipped">'.$langs->trans("WebhookResultSkipped").'</span>';
        } elseif ($actionResult === 'duplicate') {
            print '<span class="d2s-badge-duplicate">'.$langs->trans("WebhookResultDuplicate").'</span>';
        } elseif ($actionResult === 'skipped_license') {
            // Story licence-gate-des-ecritures-sortantes (AC5), 13/09/2026. C'est le refus muet
            // que le commentaire de 'skipped_unpaid' ci-dessous mettait en garde de ne jamais
            // reproduire — il n'avait tout simplement jamais eu de case ici.
            //
            // Le bucket générique était pire que neutre : updateEventStatus() pose `status = 1`
            // pour ce refus, donc le badge affiché était « Traité ». Un client lisait un succès
            // là où sa synchronisation venait d'être bloquée.
            print '<span class="d2s-badge-skipped" style="background:#856404;color:#fff;">'.$langs->trans("WebhookResultSkippedLicense").'</span>';
        } elseif ($actionResult === 'skipped_unpaid') {
            // Story reglage-commandes-non-payees-ignore-par-les-webhooks (AC3) : refus explicite,
            // badge et libellé dédiés — le refus muet de 'skipped_license' qu'il fallait ne pas
            // reproduire a été corrigé le 13/09 (case dédié juste au-dessus).
            print '<span class="d2s-badge-skipped">'.$langs->trans("WebhookResultSkippedUnpaid").'</span>';
        } elseif ($actionResult === 'skipped_fin_unknown') {
            // MEDIUM-1 (Code Review du 09/09/2026) : badge et libellé DÉDIÉS, distincts de
            // 'skipped_unpaid' — displayFinancialStatus absent/vide/valeur Shopify inconnue, pas un
            // simple statut "non payé" — même refus fail-closed, diagnostic différent.
            print '<span class="d2s-badge-skipped" style="background:#856404;color:#fff;">'.$langs->trans("WebhookResultSkippedFinUnknown").'</span>';
        } elseif ($actionResult === 'skipped_taxes_unknown') {
            // Story prix-de-ligne-toujours-traites-comme-ttc-boutiques-hors-taxes (AC3) : badge et
            // libellé DÉDIÉS — `taxesIncluded` absent/invalide, refus fail-closed comptable, distinct
            // de 'skipped_unpaid' et 'skipped_fin_unknown'.
            print '<span class="d2s-badge-skipped" style="background:#7a1f1f;color:#fff;">'.$langs->trans("WebhookResultSkippedTaxesUnknown").'</span>';
        } elseif ($actionResult === 'dead_letter') {
            // Story 36.2 : event ayant épuisé ses tentatives
            print '<span class="d2s-badge-error" style="background:#7a1f1f;">'.$langs->trans("WebhookDeadLetter").'</span>';
        } else {
            // NULL or unknown — show processing status (pre-Story 3.1 events)
            $statusLabels = array(
                0 => $langs->trans("WebhookStatusPending"),
                1 => $langs->trans("WebhookStatusProcessed"),
                2 => $langs->trans("WebhookStatusError"),
                3 => $langs->trans("WebhookStatusProcessing"),
            );
            $statusLabel = isset($statusLabels[(int) $event->status]) ? $statusLabels[(int) $event->status] : '?';
            print '<span class="badge badge-status0">'.dol_escape_htmltag($statusLabel).'</span>';
        }
        print '</td>';

        // Dolibarr object (type + link)
        print '<td>';
        if (!empty($event->dolibarr_object_type) && !empty($event->dolibarr_object_id)) {
            $objectUrl = '';
            $objectIcon = '';
            $dolibarrObjectId = (int) $event->dolibarr_object_id;
            if ($event->dolibarr_object_type === 'commande') {
                $objectUrl = DOL_URL_ROOT.'/commande/card.php?id='.$dolibarrObjectId;
                $objectIcon = 'fas fa-shopping-cart';
            } elseif ($event->dolibarr_object_type === 'product') {
                $objectUrl = DOL_URL_ROOT.'/product/card.php?id='.$dolibarrObjectId;
                $objectIcon = 'fas fa-cube';
            } elseif ($event->dolibarr_object_type === 'facture') {
                $objectUrl = DOL_URL_ROOT.'/compta/facture/card.php?facid='.$dolibarrObjectId;
                $objectIcon = 'fas fa-file-invoice';
            } elseif ($event->dolibarr_object_type === 'shipping') {
                $objectUrl = DOL_URL_ROOT.'/expedition/card.php?id='.$dolibarrObjectId;
                $objectIcon = 'fas fa-truck';
            }

            if (!empty($objectUrl)) {
                print '<a href="'.$objectUrl.'" title="'.dol_escape_htmltag(ucfirst($event->dolibarr_object_type).' #'.$dolibarrObjectId).'">';
                print '<i class="'.$objectIcon.'"></i> ';
                print dol_escape_htmltag(ucfirst($event->dolibarr_object_type)).' #'.$dolibarrObjectId;
                print '</a>';
            } else {
                print dol_escape_htmltag(ucfirst($event->dolibarr_object_type)).' #'.$dolibarrObjectId;
            }

            // Story 38.2 : statut courant de l'objet Dolibarr (badge)
            $objStatus = doli2shop_get_object_status_label($db, $event->dolibarr_object_type, $dolibarrObjectId);
            if ($objStatus !== null) {
                print ' <span style="display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; color: #fff; background: '.dol_escape_htmltag($objStatus['color']).';">'.dol_escape_htmltag($objStatus['label']).'</span>';
            }
        } elseif (!empty($event->dolibarr_object_type)) {
            print dol_escape_htmltag(ucfirst($event->dolibarr_object_type));
        } else {
            print '<span style="color: #999;">-</span>';
        }
        print '</td>';

        // Processing time
        print '<td style="white-space: nowrap;">';
        if ($event->processing_time_ms !== null && $event->processing_time_ms !== '') {
            $timeMs = (int) $event->processing_time_ms;
            if ($timeMs >= 1000) {
                print '<span title="'.$timeMs.'ms">'.round($timeMs / 1000, 1).'s</span>';
            } else {
                print $timeMs.'ms';
            }
        } else {
            print '<span style="color: #999;">-</span>';
        }
        print '</td>';

        // Actions
        print '<td style="white-space: nowrap;">';
        print '<a href="#" onclick="jQuery(\'#d2s_detail_'.$event->rowid.'\').toggle(); return false;" class="butAction" style="padding: 2px 8px; font-size: 11px;">';
        print '<i class="fas fa-eye"></i> '.$langs->trans("WebhookViewDetails");
        print '</a>';
        print '</td>';

        print '</tr>';

        // ========================================
        // Expandable detail row
        // ========================================
        print '<tr id="d2s_detail_'.$event->rowid.'" style="display: none;">';
        print '<td colspan="7" style="background: #f8f9fa; padding: 15px;">';

        // Header with IDs
        print '<div style="display: flex; gap: 20px; margin-bottom: 10px; flex-wrap: wrap;">';

        // Webhook ID
        print '<div>';
        print '<strong>Webhook ID:</strong> ';
        print dol_escape_htmltag($event->webhook_id ?: 'N/A');
        print '</div>';

        // Shopify ID (extract from payload topic pattern)
        if (!empty($event->dolibarr_object_type)) {
            print '<div>';
            print '<strong>'.$langs->trans("WebhookDolibarrObject").':</strong> ';
            print dol_escape_htmltag(ucfirst($event->dolibarr_object_type));
            if (!empty($event->dolibarr_object_id)) {
                print ' #'.(int) $event->dolibarr_object_id;
            }
            print '</div>';
        }

        // Status
        print '<div>';
        print '<strong>'.$langs->trans("Status").':</strong> ';
        print dol_escape_htmltag($event->action_result ?: 'status='.$event->status);
        print '</div>';

        // HMAC
        print '<div>';
        print '<strong>HMAC:</strong> ';
        if ($event->verified) {
            print '<span class="badge badge-status4">OK</span>';
        } else {
            print '<span class="badge badge-status8">Non</span>';
        }
        print '</div>';

        // Processing time
        if ($event->processing_time_ms !== null && $event->processing_time_ms !== '') {
            print '<div>';
            print '<strong>'.$langs->trans("WebhookColumnProcessingTime").':</strong> ';
            print (int) $event->processing_time_ms.'ms';
            print '</div>';
        }

        // Tries
        print '<div>';
        print '<strong>'.$langs->trans("WebhookTries").':</strong> '.(int) $event->tries;
        print '</div>';

        // Export JSON button
        print '<div>';
        print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=export_single_event&event_id='.$event->rowid.'&token='.newToken().'" class="butAction" style="padding: 2px 8px; font-size: 11px;">';
        print '<i class="fas fa-download"></i> '.$langs->trans("WebhookExportJSON");
        print '</a>';
        print '</div>';

        // Story 36.2 : forcer le retry d'un event dead_letter (malgré le seuil)
        if ($event->action_result === 'dead_letter') {
            print '<div style="margin-top: 6px;">';
            print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=force_retry&event_id='.((int) $event->rowid).'&token='.newToken().'" class="butAction" style="padding: 2px 8px; font-size: 11px;">';
            print '<i class="fas fa-redo"></i> '.$langs->trans("WebhookForceRetry");
            print '</a>';
            print '</div>';
        }

        print '</div>';

        // Error message (if applicable)
        if (!empty($event->last_error)) {
            print '<div style="margin-bottom: 10px;">';
            print '<strong style="color: #721c24;">'.$langs->trans("WebhookErrorMessage").':</strong><br>';
            print '<pre style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; font-size: 12px; margin-top: 4px; white-space: pre-wrap; word-wrap: break-word;">'.dol_escape_htmltag($event->last_error).'</pre>';
            print '</div>';
        }

        // Payload JSON pretty-print
        print '<div>';
        print '<strong>'.$langs->trans("WebhookPayloadTitle").':</strong>';

        if (!empty($event->payload)) {
            $decodedPayload = json_decode($event->payload);
            $formattedPayload = $decodedPayload
                ? json_encode($decodedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $event->payload;
            print '<pre style="max-height: 400px; overflow: auto; background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; word-wrap: break-word; margin-top: 4px;">'.dol_escape_htmltag($formattedPayload).'</pre>';
        } else {
            print '<br><em style="color: #999;">'.$langs->trans("NoPayload").'</em>';
        }

        print '</div>';

        print '</td>';
        print '</tr>';
    }

    $db->free($resqlEvents);

    print '</table>';

    // Story 36.2 : bouton de rejeu batch (masqué tant qu'aucune sélection)
    print '<div id="d2s_replay_bar" style="display:none; margin-top: 10px;">';
    print '<button type="submit" class="button" onclick="return confirm(\''.dol_escape_js($langs->trans("WebhookReplayConfirm")).'\');">';
    print '<i class="fas fa-redo"></i> '.$langs->trans("WebhookReplayBatch");
    print '</button>';
    print ' <span id="d2s_replay_count" class="opacitymedium" style="font-size: 12px;"></span>';
    print '</div>';

    print '</form>'; // d2s_replay_form

    // JS : select-all + affichage conditionnel du bouton de rejeu
    print '<script>
    (function() {
        var selectAll = document.getElementById("d2s_select_all");
        var bar = document.getElementById("d2s_replay_bar");
        var countSpan = document.getElementById("d2s_replay_count");
        function cbs() { return document.querySelectorAll(".d2s_event_cb"); }
        function refresh() {
            var n = 0;
            cbs().forEach(function(cb) { if (cb.checked) n++; });
            if (bar) { bar.style.display = n > 0 ? "block" : "none"; }
            if (countSpan) { countSpan.textContent = n > 0 ? ("("+n+")") : ""; }
        }
        if (selectAll) {
            selectAll.addEventListener("change", function() {
                cbs().forEach(function(cb) { cb.checked = selectAll.checked; });
                refresh();
            });
        }
        cbs().forEach(function(cb) { cb.addEventListener("change", refresh); });
    })();
    </script>';

    // ========================================
    // Pagination
    // ========================================
    if ($totalPages > 1) {
        print '<div class="center" style="margin-top: 10px;">';

        // Build filter params for pagination links
        $paginationParams = '';
        if (!empty($filterTopic)) {
            $paginationParams .= '&filter_topic='.urlencode($filterTopic);
        }
        if (!empty($filterResult)) {
            $paginationParams .= '&filter_result='.urlencode($filterResult);
        }
        if (!empty($filterDateFrom)) {
            $paginationParams .= '&filter_date_from='.urlencode($filterDateFrom);
        }
        if (!empty($filterDateTo)) {
            $paginationParams .= '&filter_date_to='.urlencode($filterDateTo);
        }
        if (!empty($filterObjectType)) {
            $paginationParams .= '&filter_object_type='.urlencode($filterObjectType);
        }
        if (!empty($filterShopDomain)) {
            $paginationParams .= '&filter_shop_domain='.urlencode($filterShopDomain);
        }
        if ($limit !== 25) {
            $paginationParams .= '&limit='.((int) $limit);
        }
        if (!empty($filterStuck)) {
            $paginationParams .= '&filter_stuck=1';
        }

        if ($page > 0) {
            print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?page='.($page - 1).$paginationParams.'" class="butAction" style="padding: 4px 12px;">&laquo; '.$langs->trans("Previous").'</a> ';
        }

        print '<span style="padding: 0 10px;">'.$langs->trans("Page").' '.($page + 1).' / '.$totalPages.' ('.$totalFiltered.' '.$langs->trans("WebhookEventsCount").')</span>';

        if (($page + 1) < $totalPages) {
            print ' <a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?page='.($page + 1).$paginationParams.'" class="butAction" style="padding: 4px 12px;">'.$langs->trans("Next").' &raquo;</a>';
        }
        print '</div>';
    }

} else {
    print '<div class="error">'.$langs->trans("ErrorDatabaseQuery").' : '.dol_escape_htmltag($db->lasterror()).'</div>';
}

print dol_get_fiche_end();

// === End CSS Isolation ===
print '</div>';

llxFooter();
$db->close();
