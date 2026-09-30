<?php
/**
 * @file        admin/action_log.php
 * @brief       Journal des actions module (hors webhook_events) — Story 38.3
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.3.0
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
require_once '../class/storeservice.class.php';
require_once '../class/modulelogfile.class.php';

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
$filterActionType = GETPOST('filter_action_type', 'alphanohtml');
$filterResult = GETPOST('filter_result', 'alpha');
$filterDateFrom = GETPOST('filter_date_from', 'alpha');
$filterDateTo = GETPOST('filter_date_to', 'alpha');
// Story 49-5 : filtre boutique (0 = toutes)
$filterStoreRaw = GETPOST('filter_store', 'int');
$filterStore = ($filterStoreRaw !== '') ? (int) $filterStoreRaw : -1; // -1 = non posté
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
if ($filterStore < 0) {
    // Premier chargement : pré-sélectionner la boutique active si connue
    $filterStore = ($currentAdminStore !== null && (int) $currentAdminStore->rowid > 0)
        ? (int) $currentAdminStore->rowid
        : 0;
}
if (!empty($filterDateFrom) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $filterDateFrom = '';
}
if (!empty($filterDateTo) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $filterDateTo = '';
}
// Résultat : whitelist
if (!in_array($filterResult, array('success', 'error', 'skipped'), true)) {
    $filterResult = '';
}
$page = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$limit = 50;
$offset = $page * $limit;

$retentionDays = getDolGlobalInt('DOLI2SHOP_ACTION_LOG_RETENTION_DAYS', 90);
if ($retentionDays < 1) {
    $retentionDays = 90;
}
if ($retentionDays > 3650) {
    $retentionDays = 3650;
}

/*
 * Actions
 */

// Story 59-2 (AC1/AC2) : cette action faisait sauter son bloc EN SILENCE sur un jeton refusé
// (aucun message, aucune trace, aucun effet). Message écran + trace journal (LOG_WARNING).
if ($action == 'purge' && !verifToken()) {
    dol_syslog("action_log.php: action 'purge' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

// Purge des entrées anciennes (POST tokenisé)
if ($action == 'purge' && verifToken()) {
    $sqlPurge = "DELETE FROM ".MAIN_DB_PREFIX."doli2shop_action_log";
    $sqlPurge .= " WHERE entity = ".((int) $conf->entity);
    $sqlPurge .= " AND date_action < '".$db->escape($db->idate(dol_now() - ($retentionDays * 24 * 3600)))."'";
    $resPurge = $db->query($sqlPurge);
    if ($resPurge) {
        setEventMessages($langs->trans("ActionLogPurgeDone", $db->affected_rows($resPurge)), null, 'mesgs');
    } else {
        setEventMessages($db->lasterror(), null, 'errors');
    }
    header('Location: '.$_SERVER["PHP_SELF"]);
    exit;
}

// Export CSV du journal — l'exigence qui fait qu'un client peut NOUS l'envoyer sans manipulation.
//
// Format CSV et non JSON : le client l'ouvre dans son tableur, voit ce qu'il nous transmet, et sa
// messagerie ne le refuse pas. Un journal de diagnostic qu'on ne peut pas envoyer ne sert à rien —
// c'est le mur rencontré sur deux dossiers de septembre 2026, où le fichier de log de Dolibarr
// pesait 323 Mo.
//
// ⚠️ Le contenu exporté est le MÊME que celui affiché : ni plus (pas de colonne cachée qui
// sortirait de l'instance sans que le client l'ait vue), ni moins.
if ($action == 'export') {
    if (!verifToken()) {
        dol_syslog("action_log.php: action 'export' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
        setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
    } else {
        $sqlExport = "SELECT date_action, run_id, origin, action_type, object_type, object_id,";
        $sqlExport .= " result, message, duration_ms, fk_store";
        $sqlExport .= " FROM " . MAIN_DB_PREFIX . "doli2shop_action_log";
        $sqlExport .= " WHERE entity = " . ((int) $conf->entity);
        $sqlExport .= " ORDER BY date_action DESC, rowid DESC";
        // Borne dure : un export doit rester envoyable par courriel. Au-delà, c'est la purge ou
        // un filtre qui est la réponse, pas un fichier de plusieurs dizaines de méga-octets.
        $sqlExport .= " LIMIT 20000";

        $resExport = $db->query($sqlExport);
        if (!$resExport) {
            setEventMessages($db->lasterror(), null, 'errors');
        } else {
            $filename = 'doli2shop_journal_' . dol_print_date(dol_now(), '%Y-%m-%d_%H%M%S') . '.csv';

            top_httphead('text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            // BOM UTF-8 : sans lui, Excel affiche les accents en mojibake et le client croit le
            // fichier corrompu.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array('date', 'run_id', 'provenance', 'type', 'objet_type', 'objet_id', 'resultat', 'message', 'duree_ms', 'boutique'), ';');
            while ($objExport = $db->fetch_object($resExport)) {
                // Story export-diagnostic-detection-hex-elargie (AC9) : construction de ligne
                // extraite dans lib/doli2shop.lib.php pour être testable isolément — la colonne
                // 'message' (texte libre écrit par le module : résultats d'appel API, erreurs) peut
                // interpoler un jeton hexadécimal brut, désormais masqué par
                // doli2shopRedactSensitiveValue() comme le reste des exports du module.
                fputcsv($out, doli2shopBuildActionLogExportRow($objExport), ';');
            }
            fclose($out);
            $db->free($resExport);
            exit;
        }
    }
}

// Téléchargement du FICHIER de journal du module.
//
// Les traces du module partaient jusqu'ici dans `documents/dolibarr.log`, PARTAGÉ avec le cœur de
// Dolibarr et tous les autres modules — 323 Mo chez un client de septembre 2026, donc ni
// transmissible par courriel ni lisible. Le module écrit désormais aussi dans son propre fichier,
// borné et tournant, et ce bouton permet au client de nous l'envoyer sans manipulation.
if ($action == 'download_logfile') {
    if (!verifToken()) {
        dol_syslog("action_log.php: action 'download_logfile' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
        setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
    } else {
        $logPath = ModuleLogFile::path();
        if ($logPath === null) {
            // Un journal désactivé n'est pas une panne : le dire évite au client de croire à une
            // erreur. Distinct du cas 'not_found' ci-dessous (chemin configuré mais fichier absent).
            setEventMessages($langs->trans("ModuleLogFileEmpty"), null, 'warnings');
        } else {
            // Re-review 3 couches (H1) : is_file()/is_readable()/fopen() sont désormais vérifiés
            // AVANT tout en-tête HTTP de succès (Content-Type/Content-Disposition). L'ancien code
            // envoyait déjà ces en-têtes avant même de tenter fopen() : un fichier devenu illisible
            // entre les deux (permissions, fichier supprimé par un autre processus, disque hors
            // ligne) produisait un téléchargement de 0 octet annoncé comme un succès HTTP 200, sans
            // aucune trace ni message.
            list($openStatus, $logHandle) = doli2shopOpenLogFileForDownload($logPath);

            if ($openStatus === 'not_found') {
                setEventMessages($langs->trans("ModuleLogFileEmpty"), null, 'warnings');
            } elseif ($openStatus !== 'ok') {
                // 'unreadable' ou 'open_failed' : problème réel d'accès au fichier, pas une simple
                // absence — message d'erreur explicite + trace journal pour investigation.
                dol_syslog("action_log.php: download_logfile impossible (status=$openStatus) for path=$logPath", LOG_ERR);
                setEventMessages($langs->trans("ModuleLogFileUnreadable"), null, 'errors');
            } else {
                $downloadName = 'doli2shop_' . dol_print_date(dol_now(), '%Y-%m-%d_%H%M%S') . '.log';

                top_httphead('text/plain; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                // Story export-diagnostic-detection-hex-elargie (AC10) : le Content-Length
                // précalculé sur la taille du fichier D'ORIGINE est retiré — la taille change après
                // masquage et ne peut plus être connue avant traitement. PHP enverra la réponse
                // sans Content-Length, ce qui est déjà le comportement d'autres exports du module
                // (l'export CSV ci-dessus n'en pose pas non plus).

                // Re-review 3 couches (M1) : lecture par CHUNKS BORNÉS avec fenêtre de
                // recouvrement (jamais fgets() sans longueur, qui chargerait une seule ligne
                // pathologique entière en mémoire) — voir doli2shopStreamMaskedLogFileToOutput().
                $streamStatus = doli2shopStreamMaskedLogFileToOutput(
                    $logHandle,
                    'doli2shopMaskAllKnownSensitiveSubstringsInFreeText',
                    static function (string $chunk): void {
                        echo $chunk;
                    }
                );
                if ($streamStatus === 'read_error') {
                    dol_syslog("action_log.php: download_logfile read error mid-stream for path=$logPath", LOG_ERR);
                }
                exit;
            }
        }
    }
}

/*
 * View
 */

llxHeader('', $langs->trans("ActionLogTitle"), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// === CSS Isolation ===
print '<div class="doli2shop-page">';

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("ActionLogTitle"), $linkback, 'title_setup');

// Navigation tabs
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'actionlog', $langs->trans("ShopifyIntegration"), -1, 'shopify_color@doli2shop');

// Barre de contexte boutique (Story 49-10)
doli2shopRenderAdminTopBar($db);

print '<p class="opacitymedium">'.$langs->trans("ActionLogDesc").'</p>';

// Guard : la table peut être absente si la migration v2.3.0 n'a pas encore été appliquée
$tableExists = false;
$resCheck = $db->query("SELECT 1 FROM information_schema.TABLES"
    ." WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$db->escape(MAIN_DB_PREFIX.'doli2shop_action_log')."'");
if ($resCheck) {
    $tableExists = ($db->num_rows($resCheck) > 0);
    $db->free($resCheck);
}
if (!$tableExists) {
    print '<div class="warning">'.$langs->trans("ActionLogTableMissing").'</div>';
    print dol_get_fiche_end();
    print '</div>';
    llxFooter();
    $db->close();
    exit;
}

// ========================================
// Filters
// ========================================
$hasFilters = (!empty($filterActionType) || !empty($filterResult) || !empty($filterDateFrom) || !empty($filterDateTo) || $filterStore > 0);

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">';

// Action type filter (distinct dynamique)
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("ActionLogFilterType").'</label>';
print '<select name="filter_action_type" style="min-width: 200px;">';
print '<option value="">'.$langs->trans("All").'</option>';
$sqlTypes = "SELECT DISTINCT action_type FROM ".MAIN_DB_PREFIX."doli2shop_action_log";
$sqlTypes .= " WHERE entity = ".((int) $conf->entity)." ORDER BY action_type";
$resTypes = $db->query($sqlTypes);
if ($resTypes) {
    while ($objType = $db->fetch_object($resTypes)) {
        $selected = ($filterActionType === $objType->action_type) ? ' selected' : '';
        // Libellé traduit si clé connue, sinon brut
        $typeLabelKey = 'ActionType_'.$objType->action_type;
        $typeLabel = $langs->trans($typeLabelKey);
        if ($typeLabel === $typeLabelKey) {
            $typeLabel = $objType->action_type;
        }
        print '<option value="'.dol_escape_htmltag($objType->action_type).'"'.$selected.'>'.dol_escape_htmltag($typeLabel).'</option>';
    }
    $db->free($resTypes);
}
print '</select>';
print '</div>';

// Result filter
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterResult").'</label>';
print '<select name="filter_result" style="min-width: 130px;">';
print '<option value="">'.$langs->trans("All").'</option>';
$resultOptions = array(
    'success' => $langs->trans("WebhookResultSuccess"),
    'error' => $langs->trans("WebhookResultError"),
    'skipped' => $langs->trans("WebhookResultSkipped"),
);
foreach ($resultOptions as $resultKey => $resultLabel) {
    $selected = ($filterResult === $resultKey) ? ' selected' : '';
    print '<option value="'.dol_escape_htmltag($resultKey).'"'.$selected.'>'.dol_escape_htmltag($resultLabel).'</option>';
}
print '</select>';
print '</div>';

// Date from / to
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterDateFrom").'</label>';
print '<input type="date" name="filter_date_from" value="'.dol_escape_htmltag($filterDateFrom).'" style="min-width: 140px;">';
print '</div>';
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.$langs->trans("WebhookFilterDateTo").'</label>';
print '<input type="date" name="filter_date_to" value="'.dol_escape_htmltag($filterDateTo).'" style="min-width: 140px;">';
print '</div>';

// Story 49-5 : dropdown boutique
print '<div>';
print '<label style="display: block; font-size: 11px; color: #666; margin-bottom: 2px;">'.dol_escape_htmltag($langs->trans("ActionLogStoreFilter")).'</label>';
print '<select name="filter_store" style="min-width: 160px;">';
$selectedAllStores = ($filterStore == 0) ? ' selected' : '';
print '<option value="0"'.$selectedAllStores.'>'.dol_escape_htmltag($langs->trans("AllStores")).'</option>';
$storeServiceLog = new StoreService($db);
$allStoresLog    = $storeServiceLog->getAll(false);
foreach ($allStoresLog as $s) {
    $selectedStore = ($filterStore == (int) $s->rowid) ? ' selected' : '';
    print '<option value="'.(int) $s->rowid.'"'.$selectedStore.'>'.dol_escape_htmltag($s->label).'</option>';
}
print '</select>';
print '</div>';

print '<div>';
print '<input type="submit" class="button" value="'.$langs->trans("WebhookFilterApply").'" style="margin-bottom: 0;">';
print '</div>';
if ($hasFilters) {
    print '<div>';
    print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" class="butAction" style="padding: 4px 12px; font-size: 12px;">'.$langs->trans("WebhookFilterReset").'</a>';
    print '</div>';
}

print '</div>';
print '</form>';

// ========================================
// Build SQL with filters
// ========================================
$sqlWhere = " WHERE entity = ".((int) $conf->entity);
if (!empty($filterActionType)) {
    $sqlWhere .= " AND action_type = '".$db->escape($filterActionType)."'";
}
if (!empty($filterResult)) {
    $sqlWhere .= " AND result = '".$db->escape($filterResult)."'";
}
if (!empty($filterDateFrom)) {
    $sqlWhere .= " AND date_action >= '".$db->escape($filterDateFrom)." 00:00:00'";
}
if (!empty($filterDateTo)) {
    $sqlWhere .= " AND date_action <= '".$db->escape($filterDateTo)." 23:59:59'";
}
// Story 49-5 : filtre fk_store conditionnel (getStoreId > 0 → filtre ; 0 = global, pas de filtre)
if ($filterStore > 0) {
    $sqlWhere .= " AND fk_store = ".(int) $filterStore;
}

$sqlCount = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."doli2shop_action_log".$sqlWhere;
$resCount = $db->query($sqlCount);
$totalFiltered = 0;
if ($resCount) {
    $objCount = $db->fetch_object($resCount);
    $totalFiltered = (int) $objCount->total;
    $db->free($resCount);
}
$totalPages = ($totalFiltered > 0) ? ceil($totalFiltered / $limit) : 0;

$sqlLog = "SELECT rowid, date_action, action_type, object_type, object_id, result, message";
$sqlLog .= " FROM ".MAIN_DB_PREFIX."doli2shop_action_log";
$sqlLog .= $sqlWhere;
$sqlLog .= " ORDER BY date_action DESC";
$sqlLog .= $db->plimit($limit, $offset);

$resqlLog = $db->query($sqlLog);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("WebhookColumnDate").'</th>';
print '<th>'.$langs->trans("ActionLogColType").'</th>';
print '<th>'.$langs->trans("WebhookColumnObject").'</th>';
print '<th>'.$langs->trans("WebhookColumnResult").'</th>';
print '<th>'.$langs->trans("ActionLogColMessage").'</th>';
print '</tr>';

if ($resqlLog && $db->num_rows($resqlLog) > 0) {
    while ($log = $db->fetch_object($resqlLog)) {
        print '<tr class="oddeven">';

        // Date
        print '<td style="white-space: nowrap;">'.dol_escape_htmltag(dol_print_date($db->jdate($log->date_action), 'dayhour')).'</td>';

        // Action type (libellé traduit si clé connue)
        $typeLabelKey = 'ActionType_'.$log->action_type;
        $typeLabel = $langs->trans($typeLabelKey);
        if ($typeLabel === $typeLabelKey) {
            $typeLabel = $log->action_type;
        }
        print '<td>'.dol_escape_htmltag($typeLabel).'</td>';

        // Object (type + lien Dolibarr)
        print '<td>';
        if (!empty($log->object_type) && !empty($log->object_id)) {
            $objId = (int) $log->object_id;
            $objUrl = '';
            if ($log->object_type === 'commande') {
                $objUrl = DOL_URL_ROOT.'/commande/card.php?id='.$objId;
            } elseif ($log->object_type === 'facture') {
                $objUrl = DOL_URL_ROOT.'/compta/facture/card.php?facid='.$objId;
            } elseif ($log->object_type === 'shipping') {
                $objUrl = DOL_URL_ROOT.'/expedition/card.php?id='.$objId;
            } elseif ($log->object_type === 'product') {
                $objUrl = DOL_URL_ROOT.'/product/card.php?id='.$objId;
            }
            if (!empty($objUrl)) {
                print '<a href="'.$objUrl.'">'.dol_escape_htmltag(ucfirst($log->object_type)).' #'.$objId.'</a>';
            } else {
                print dol_escape_htmltag(ucfirst($log->object_type)).' #'.$objId;
            }
        } else {
            print '<span style="color: #999;">-</span>';
        }
        print '</td>';

        // Result badge
        print '<td>';
        $resultColors = array('success' => '#28a745', 'error' => '#dc3545', 'skipped' => '#f0ad4e');
        $rColor = isset($resultColors[$log->result]) ? $resultColors[$log->result] : '#999999';
        if (!empty($log->result)) {
            print '<span style="display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; color: #fff; background: '.$rColor.';">'.dol_escape_htmltag($log->result).'</span>';
        } else {
            print '<span style="color: #999;">-</span>';
        }
        print '</td>';

        // Message
        print '<td>'.dol_escape_htmltag($log->message).'</td>';

        print '</tr>';
    }
    $db->free($resqlLog);
} else {
    print '<tr><td colspan="5" class="opacitymedium" style="text-align: center; padding: 20px;">'.$langs->trans("ActionLogEmpty").'</td></tr>';
}

print '</table>';

// Pagination
if ($totalPages > 1) {
    print '<div style="margin-top: 15px; text-align: center;">';
    $paginationParams = '';
    if (!empty($filterActionType)) {
        $paginationParams .= '&filter_action_type='.urlencode($filterActionType);
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
    if ($filterStore > 0) {
        $paginationParams .= '&filter_store='.(int) $filterStore;
    }
    if ($page > 0) {
        print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?page='.($page - 1).$paginationParams.'" class="butAction" style="padding: 4px 12px;">&laquo; '.$langs->trans("Previous").'</a> ';
    }
    print '<span style="padding: 0 10px;">'.$langs->trans("Page").' '.($page + 1).' / '.$totalPages.' ('.$totalFiltered.')</span>';
    if (($page + 1) < $totalPages) {
        print ' <a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?page='.($page + 1).$paginationParams.'" class="butAction" style="padding: 4px 12px;">'.$langs->trans("Next").' &raquo;</a>';
    }
    print '</div>';
}

// Export CSV du journal de cycles (base) — ce que le module a fait, cycle par cycle.
print '<br>';
print '<div style="margin-top: 10px;">';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="export">';
print '<small class="opacitymedium">'.$langs->trans("ActionLogExportInfo").'</small> ';
print '<input type="submit" class="button" value="'.$langs->trans("ActionLogExportButton").'">';
print '</form>';
print '</div>';

// Téléchargement du fichier de journal du module — à joindre à une demande d'assistance.
print '<br>';
print '<div style="margin-top: 10px;">';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="download_logfile">';
print '<small class="opacitymedium">'.$langs->trans("ModuleLogFileInfo", dol_print_size(ModuleLogFile::totalSize(), 1, 1)).'</small> ';
print '<input type="submit" class="button" value="'.$langs->trans("ModuleLogFileDownload").'">';
print '</form>';
print '</div>';

// Purge section
print '<br>';
print '<div style="margin-top: 10px;">';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" onsubmit="return confirm(\''.dol_escape_js($langs->trans("ActionLogPurgeConfirm", $retentionDays)).'\');">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="purge">';
print '<small class="opacitymedium">'.$langs->trans("ActionLogRetentionInfo", $retentionDays).'</small> ';
print '<input type="submit" class="butActionDelete" value="'.$langs->trans("ActionLogPurgeButton").'">';
print '</form>';
print '</div>';

print dol_get_fiche_end();

print '</div>'; // doli2shop-page

llxFooter();
$db->close();
