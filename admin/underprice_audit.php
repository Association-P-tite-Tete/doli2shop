<?php
/**
 * @file        admin/underprice_audit.php
 * @brief       Audit (jamais de correction) des commandes Shopify importées sous-évaluées avant le
 *              correctif e825f456, story reprise-des-commandes-importees-en-prix-hors-taxes
 *
 * Outil admin EXPLICITE et JAMAIS automatique. ⚠️ CE N'EST PAS UN CORRECTEUR — c'est un outil
 * D'AUDIT (Validate du 12/09/2026) : la quasi-totalité des commandes concernées est déjà
 * auto-validée, auto-facturée et auto-payée par défaut, et une facture validée ne se corrige
 * jamais par UPDATE (comptablement, cela passe par un avoir — décision et acte du client, hors de
 * cet outil). Cet écran ne comporte donc AUCUN bouton « corriger » : seuls un aperçu (dry-run) et
 * un enregistrement du verdict d'audit (piste d'audit, idempotent) sont proposés.
 *
 * Deux temps obligatoires : aperçu (dry-run, aucune écriture) puis enregistrement explicite du
 * verdict — pattern admin/discount_repair.php (Hotfix 2.4.5, AC4), page DÉDIÉE.
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.5.4
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
dol_include_once('/doli2shop/class/underpricedorderauditservice.class.php');

// Traductions
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Accès (pattern admin/discount_repair.php)
if (!$user->admin) {
    accessforbidden();
}

// Contexte boutique (Story 49-9) — scope cohérent avec les autres pages admin
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
$currentAdminStoreIsSecondary = ($currentAdminStore !== null && empty($currentAdminStore->is_default));
$auditStoreId = $currentAdminStoreIsSecondary ? (int) $currentAdminStore->rowid : 0;

$underpriceAuditService = new UnderpricedOrderAuditService($db, $conf->entity, (int) $user->id);
$underpriceAuditCandidateCount = $underpriceAuditService->countCandidateOrders($auditStoreId);
$underpriceAuditConfirmMessage = $langs->trans("UnderpriceAuditConfirm", (string) $underpriceAuditCandidateCount);

// Page setup
llxHeader('', $langs->trans("UnderpriceAuditTitle"), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'underpriceaudit', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');

print load_fiche_titre($langs->trans("UnderpriceAuditTitle"), '', 'title_setup');

print '<div class="d2s-card" style="padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-search-dollar"></i> ' . $langs->trans("UnderpriceAuditTitle") . '</h3>';
print '<p class="opacitymedium">' . dol_escape_htmltag($langs->trans("UnderpriceAuditIntro")) . '</p>';
print '<div class="warning" style="margin: 10px 0;">';
print '<strong>' . $langs->trans("Warning") . ':</strong> ' . dol_escape_htmltag($langs->trans("UnderpriceAuditWarning"));
print '</div>';
print '<p><strong id="d2s-underprice-audit-count">' . dol_escape_htmltag($langs->trans("UnderpriceAuditCountLabel", (string) $underpriceAuditCandidateCount)) . '</strong></p>';

print '<div style="margin-top: 12px;">';
print '<button type="button" class="butAction" id="d2s-underprice-audit-scan-button">';
print '<i class="fa fa-search"></i> ' . $langs->trans("UnderpriceAuditScanButton");
print '</button> ';
print '<button type="button" class="butAction" id="d2s-underprice-audit-apply-button" disabled>';
print '<i class="fa fa-clipboard-check"></i> ' . $langs->trans("UnderpriceAuditApplyButton");
print '</button>';
print '</div>';
print '</div>';

// Zone de progression (cachée initialement)
print '<div id="d2s-underprice-audit-progress" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-sync fa-spin" id="d2s-underprice-audit-spinner"></i> ' . $langs->trans("UnderpriceAuditProgressTitle") . '</h3>';
print '<div class="d2s-progress-bar-container">';
print '<div class="d2s-progress-text" id="d2s-underprice-audit-progress-text">0 / 0</div>';
print '<div class="d2s-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="d2s-underprice-audit-progressbar">';
print '<div class="d2s-progress-fill" id="d2s-underprice-audit-progress-fill" style="width: 0%;"></div>';
print '</div>';
print '</div>';
print '<div id="d2s-underprice-audit-status" style="margin-top: 8px; font-size: 0.9em; color: #666;"></div>';
print '</div>';

// Zone aperçu (dry-run) — tableau des candidats
print '<div id="d2s-underprice-audit-preview" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-eye"></i> ' . $langs->trans("UnderpriceAuditPreviewTitle") . '</h3>';
print '<div style="overflow-x: auto;">';
print '<table class="noborder" style="width: 100%;" id="d2s-underprice-audit-preview-table">';
print '<thead><tr class="liste_titre">';
print '<th>' . $langs->trans("UnderpriceAuditColOrder") . '</th>';
print '<th>' . $langs->trans("UnderpriceAuditColStatus") . '</th>';
print '<th>' . $langs->trans("UnderpriceAuditColVerdict") . '</th>';
print '<th>' . $langs->trans("UnderpriceAuditColRecordedTTC") . '</th>';
print '<th>' . $langs->trans("UnderpriceAuditColRealTTC") . '</th>';
print '<th>' . $langs->trans("UnderpriceAuditColDeltaTTC") . '</th>';
print '<th>' . $langs->trans("UnderpriceAuditColAction") . '</th>';
print '</tr></thead>';
print '<tbody id="d2s-underprice-audit-preview-body"></tbody>';
print '</table>';
print '</div>';
print '</div>';

// Zone de rapport (cachée initialement)
print '<div id="d2s-underprice-audit-report" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-clipboard-list"></i> ' . $langs->trans("UnderpriceAuditComplete") . '</h3>';
print '<div id="d2s-underprice-audit-report-kpis" style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;"></div>';
print '<div id="d2s-underprice-audit-report-total-delta" style="margin-top: 8px; font-weight: bold;"></div>';
print '<div id="d2s-underprice-audit-report-errors" style="display: none; margin-top: 12px;"></div>';
print '</div>';

print '</div>'; // .doli2shop-page

?>
<script>
/**
 * Story reprise-des-commandes-importees-en-prix-hors-taxes — audit (jamais de correction) des
 * commandes importées sous-évaluées avant e825f456. Deux passes AJAX distinctes via
 * ajax/underprice_audit_batch.php : scan (dry-run, jamais d'écriture) puis audit (confirm=1
 * explicite, persiste UNIQUEMENT le verdict — aucune commande/facture/paiement modifié). Pattern
 * JS aligné sur admin/discount_repair.php.
 */
(function() {
    'use strict';

    <?php
    $underpriceAuditJsTransKeys = array(
        'scanButton' => 'UnderpriceAuditScanButton',
        'scanInProgress' => 'UnderpriceAuditScanInProgress',
        'applyButton' => 'UnderpriceAuditApplyButton',
        'applyInProgress' => 'UnderpriceAuditApplyInProgress',
        'complete' => 'UnderpriceAuditComplete',
        'alreadyCorrect' => 'UnderpriceAuditAlreadyCorrect',
        'manualRequired' => 'UnderpriceAuditManualRequired',
        'manualReview' => 'UnderpriceAuditManualReview',
        'errors' => 'UnderpriceAuditErrors',
        'noErrors' => 'UnderpriceAuditNoErrors',
        'errorSync' => 'ErrorSync',
        'noCandidates' => 'UnderpriceAuditNoCandidates',
        'totalDelta' => 'UnderpriceAuditTotalDelta',
        'mixedCurrencies' => 'UnderpriceAuditMixedCurrencies',
        'reasonNoHistoricalNote' => 'UnderpriceAuditReasonNoHistoricalNote',
        'reasonNoteUnparseable' => 'UnderpriceAuditReasonNoteUnparseable',
        'reasonMismatchUnexplained' => 'UnderpriceAuditReasonMismatchUnexplained',
        'reasonMatchesNote' => 'UnderpriceAuditReasonMatchesNote',
        'reasonUnderpricedDraft' => 'UnderpriceAuditReasonUnderpricedDraft',
        'reasonUnderpricedCanceled' => 'UnderpriceAuditReasonUnderpricedCanceled',
        'reasonUnderpricedCommitted' => 'UnderpriceAuditReasonUnderpricedCommitted',
    );
    $underpriceAuditJsTransValues = array();
    foreach ($underpriceAuditJsTransKeys as $jsKey => $langKey) {
        $underpriceAuditJsTransValues[$jsKey] = html_entity_decode($langs->trans($langKey), ENT_QUOTES, 'UTF-8');
    }
    ?>
    var UTRANS = <?php echo json_encode($underpriceAuditJsTransValues, JSON_UNESCAPED_UNICODE); ?>;
    var UCONFIRM = <?php echo json_encode(html_entity_decode($underpriceAuditConfirmMessage, ENT_QUOTES, 'UTF-8')); ?>;

    var REASON_LABELS = {
        'no_historical_note': UTRANS.reasonNoHistoricalNote,
        'note_unparseable': UTRANS.reasonNoteUnparseable,
        'mismatch_unexplained': UTRANS.reasonMismatchUnexplained,
        'matches_note': UTRANS.reasonMatchesNote,
        'underpriced_draft': UTRANS.reasonUnderpricedDraft,
        'underpriced_canceled': UTRANS.reasonUnderpricedCanceled,
        'underpriced_committed': UTRANS.reasonUnderpricedCommitted
    };

    var ajaxUrl = <?php echo json_encode(dol_buildpath('/doli2shop/ajax/underprice_audit_batch.php', 1)); ?>;
    var csrfToken = <?php echo json_encode(currentToken()); ?>;
    var storeId = <?php echo json_encode($auditStoreId); ?>;

    var scanButton = document.getElementById('d2s-underprice-audit-scan-button');
    var applyButton = document.getElementById('d2s-underprice-audit-apply-button');
    if (!scanButton || !applyButton || typeof jQuery === 'undefined') {
        return;
    }

    var progressSection = document.getElementById('d2s-underprice-audit-progress');
    var progressText = document.getElementById('d2s-underprice-audit-progress-text');
    var progressFill = document.getElementById('d2s-underprice-audit-progress-fill');
    var progressBar = document.getElementById('d2s-underprice-audit-progressbar');
    var statusDiv = document.getElementById('d2s-underprice-audit-status');
    var spinner = document.getElementById('d2s-underprice-audit-spinner');
    var previewSection = document.getElementById('d2s-underprice-audit-preview');
    var previewBody = document.getElementById('d2s-underprice-audit-preview-body');
    var reportSection = document.getElementById('d2s-underprice-audit-report');
    var reportKpis = document.getElementById('d2s-underprice-audit-report-kpis');
    var reportTotalDelta = document.getElementById('d2s-underprice-audit-report-total-delta');
    var reportErrors = document.getElementById('d2s-underprice-audit-report-errors');

    var isRunning = false;
    var hasScanResults = false;
    var scanTotalDelta = 0;
    var scanTotalDeltaCurrencies = [];

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

    // Edge Case Hunter (12/09) : le montant n'est PAS forcément en EUR — une boutique Shopify
    // peut être réglée dans une autre devise (shopMoney = devise DE LA BOUTIQUE ; cf. test unitaire
    // CHF côté service). Un "€" figé mentirait sur la devise réelle dans un écran d'audit
    // comptable. `currency` vient de row.currency (aperçu par commande, toujours renseigné quand
    // un montant réel/écart existe) ou d'un code déjà connu pour un total agrégé mono-devise.
    function formatAmount(value, currency) {
        if (value === null || typeof value === 'undefined') {
            return '—';
        }
        var suffix = currency ? (' ' + currency) : ' €';
        return Number(value).toFixed(2).replace('.', ',') + suffix;
    }

    // AC3 : jamais un bouton "corriger" — un texte qui dit la marche à suivre HORS de l'outil.
    function actionLabelFor(reason) {
        return REASON_LABELS[reason] || reason;
    }

    function appendPreviewRow(row) {
        var tr = document.createElement('tr');

        var tdOrder = document.createElement('td');
        tdOrder.textContent = (row.ref || ('#' + row.fkCommande));
        tr.appendChild(tdOrder);

        var tdStatus = document.createElement('td');
        tdStatus.textContent = row.isDraft ? 'Brouillon' : ('Statut ' + row.statut);
        tr.appendChild(tdStatus);

        var tdVerdict = document.createElement('td');
        tdVerdict.textContent = row.status;
        tr.appendChild(tdVerdict);

        var tdRecorded = document.createElement('td');
        tdRecorded.textContent = formatAmount(row.recordedTotalTtc, row.currency);
        tr.appendChild(tdRecorded);

        var tdReal = document.createElement('td');
        tdReal.textContent = formatAmount(row.realTotalTtc, row.currency);
        tr.appendChild(tdReal);

        var tdDelta = document.createElement('td');
        tdDelta.textContent = formatAmount(row.deltaTotalTtc, row.currency);
        tr.appendChild(tdDelta);

        var tdAction = document.createElement('td');
        tdAction.textContent = actionLabelFor(row.reason);
        tr.appendChild(tdAction);

        previewBody.appendChild(tr);

        // Une commande annulée n'a jamais été facturée/payée : exclue du total "somme en jeu"
        // (même règle que UnderpricedOrderAuditService::processOneCandidate() côté audit réel).
        if (row.status === 'manual_required' && row.reason !== 'underpriced_canceled' && typeof row.deltaTotalTtc === 'number') {
            scanTotalDelta += row.deltaTotalTtc;
            // Edge Case Hunter (12/09) : un aperçu storeId=0 peut balayer plusieurs boutiques aux
            // devises différentes (shopMoney = devise DE LA BOUTIQUE, constante par boutique mais
            // pas entre boutiques) — sommer 20 EUR + 15 USD en un seul total étiqueté d'une seule
            // devise serait un montant sans signification. On trace les devises distinctes vues
            // pour que finishScan() refuse d'afficher un total unique si elles divergent.
            var rowCurrency = row.currency || 'EUR';
            if (scanTotalDeltaCurrencies.indexOf(rowCurrency) === -1) {
                scanTotalDeltaCurrencies.push(rowCurrency);
            }
        }
    }

    scanButton.addEventListener('click', function() {
        if (isRunning) {
            return;
        }
        isRunning = true;
        hasScanResults = false;
        scanTotalDelta = 0;
        scanTotalDeltaCurrencies = [];
        applyButton.disabled = true;

        scanButton.disabled = true;
        scanButton.textContent = UTRANS.scanInProgress;

        previewBody.innerHTML = '';
        previewSection.style.display = 'none';
        reportSection.style.display = 'none';
        progressSection.style.display = 'block';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 / 0';
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
                    finishScan(UTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }
                var total = data.total || 0;
                progressText.textContent = '0 / ' + total;
                if (total === 0) {
                    finishScan(UTRANS.noCandidates);
                    return;
                }
                runScanBatch(0, total);
            },
            error: function(xhr, status, err) {
                finishScan(UTRANS.errorSync + ': ' + err);
            }
        });
    });

    var scanCount = 0;

    function runScanBatch(cursor, total) {
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'scan_batch', cursor: cursor, store_id: storeId, token: csrfToken },
            success: function(data) {
                if (!data || !data.success) {
                    finishScan(UTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }

                var rows = data.rows || [];
                for (var i = 0; i < rows.length; i++) {
                    appendPreviewRow(rows[i]);
                    scanCount++;
                }
                hasScanResults = hasScanResults || rows.length > 0;

                var pct = total > 0 ? Math.min(Math.round((scanCount / total) * 100), 100) : 100;
                progressFill.style.width = pct + '%';
                progressBar.setAttribute('aria-valuenow', String(pct));
                progressText.textContent = scanCount + ' / ' + total;

                if (data.hasMore) {
                    runScanBatch(parseInt(data.cursor || '0', 10), total);
                } else {
                    finishScan();
                }
            },
            error: function(xhr, status, err) {
                finishScan(UTRANS.errorSync + ': ' + err);
            }
        });
    }

    function finishScan(errorMessage) {
        isRunning = false;
        scanCount = 0;
        scanButton.disabled = false;
        scanButton.textContent = UTRANS.scanButton;
        spinner.className = 'fa fa-check-circle';
        spinner.style.color = errorMessage ? '#dc3545' : '#28a745';

        if (errorMessage) {
            statusDiv.textContent = errorMessage;
            return;
        }

        previewSection.style.display = 'block';
        applyButton.disabled = !hasScanResults;

        if (hasScanResults) {
            // Edge Case Hunter (12/09) : ne jamais additionner des écarts de devises différentes
            // sous une étiquette unique — cf. scanTotalDeltaCurrencies plus haut.
            if (scanTotalDeltaCurrencies.length > 1) {
                statusDiv.textContent = UTRANS.mixedCurrencies.replace('%s', scanTotalDeltaCurrencies.join(', '));
            } else {
                statusDiv.textContent = UTRANS.totalDelta.replace('%s', formatAmount(scanTotalDelta, scanTotalDeltaCurrencies[0]));
            }
        }
    }

    applyButton.addEventListener('click', function() {
        if (isRunning) {
            return;
        }
        if (!window.confirm(UCONFIRM)) {
            return;
        }

        isRunning = true;
        applyButton.disabled = true;
        applyButton.textContent = UTRANS.applyInProgress;
        scanButton.disabled = true;

        var totals = { processed: 0, alreadyCorrect: 0, manualRequired: 0, manualReview: 0, errors: 0, totalDeltaTtc: 0, totalDeltaCurrencies: [] };
        var allErrorDetails = [];

        progressSection.style.display = 'block';
        reportSection.style.display = 'none';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 traitées';
        statusDiv.textContent = '';
        spinner.className = 'fa fa-sync fa-spin';
        spinner.style.color = '';

        function runAuditBatch(cursor) {
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: { action: 'audit_batch', cursor: cursor, store_id: storeId, confirm: 1, token: csrfToken },
                success: function(data) {
                    if (!data || !data.success) {
                        finishApply(totals, allErrorDetails, UTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                        return;
                    }

                    totals.processed += (data.processed || 0);
                    totals.alreadyCorrect += (data.alreadyCorrect || 0);
                    totals.manualRequired += (data.manualRequired || 0);
                    totals.manualReview += (data.manualReview || 0);
                    totals.errors += (data.errors || 0);
                    totals.totalDeltaTtc += (data.totalDeltaTtc || 0);

                    // Edge Case Hunter (12/09) : union des devises réellement comptées sur tous les
                    // lots de la passe — cf. UnderpricedOrderAuditService::auditBatch() (totalDeltaCurrencies).
                    var batchCurrencies = data.totalDeltaCurrencies || [];
                    for (var c = 0; c < batchCurrencies.length; c++) {
                        if (totals.totalDeltaCurrencies.indexOf(batchCurrencies[c]) === -1) {
                            totals.totalDeltaCurrencies.push(batchCurrencies[c]);
                        }
                    }

                    if (data.errorDetails && data.errorDetails.length > 0) {
                        for (var i = 0; i < data.errorDetails.length; i++) {
                            allErrorDetails.push(data.errorDetails[i]);
                        }
                    }

                    progressText.textContent = totals.processed + ' traitées';
                    statusDiv.textContent = UTRANS.alreadyCorrect + ': ' + totals.alreadyCorrect +
                        ' | ' + UTRANS.manualRequired + ': ' + totals.manualRequired +
                        ' | ' + UTRANS.manualReview + ': ' + totals.manualReview +
                        ' | ' + UTRANS.errors + ': ' + totals.errors;

                    if (data.hasMore) {
                        runAuditBatch(parseInt(data.cursor || '0', 10));
                    } else {
                        finishApply(totals, allErrorDetails);
                    }
                },
                error: function(xhr, status, err) {
                    finishApply(totals, allErrorDetails, UTRANS.errorSync + ': ' + err);
                }
            });
        }

        runAuditBatch(0);
    });

    function finishApply(totals, allErrorDetails, errorMessage) {
        isRunning = false;
        applyButton.disabled = true; // relancer un scan avant un nouvel enregistrement d'audit
        applyButton.textContent = UTRANS.applyButton;
        scanButton.disabled = false;

        spinner.className = errorMessage ? 'fa fa-exclamation-triangle' : 'fa fa-check-circle';
        spinner.style.color = errorMessage ? '#dc3545' : '#28a745';

        reportSection.style.display = 'block';
        reportKpis.innerHTML = '';
        reportKpis.appendChild(createKpiCard(totals.alreadyCorrect, UTRANS.alreadyCorrect, '#026B6A'));
        reportKpis.appendChild(createKpiCard(totals.manualRequired, UTRANS.manualRequired, '#f0ad4e'));
        reportKpis.appendChild(createKpiCard(totals.manualReview, UTRANS.manualReview, '#6c757d'));
        reportKpis.appendChild(createKpiCard(totals.errors, UTRANS.errors, totals.errors > 0 ? '#dc3545' : '#28a745'));

        // Edge Case Hunter (12/09) : jamais un total unique quand plusieurs devises se sont
        // mélangées sur la passe (storeId=0, boutiques en devises différentes).
        if (totals.totalDeltaCurrencies.length > 1) {
            reportTotalDelta.textContent = UTRANS.mixedCurrencies.replace('%s', totals.totalDeltaCurrencies.join(', '));
        } else {
            reportTotalDelta.textContent = UTRANS.totalDelta.replace('%s', formatAmount(totals.totalDeltaTtc, totals.totalDeltaCurrencies[0]));
        }

        reportErrors.innerHTML = '';
        if (allErrorDetails.length > 0) {
            reportErrors.style.display = 'block';
            var ul = document.createElement('ul');
            for (var i = 0; i < allErrorDetails.length; i++) {
                var li = document.createElement('li');
                li.textContent = allErrorDetails[i];
                ul.appendChild(li);
            }
            reportErrors.appendChild(ul);
        } else {
            reportErrors.style.display = 'none';
        }

        if (errorMessage) {
            statusDiv.textContent = errorMessage;
        }

        // Rafraîchit le compteur affiché en haut de page (nouveau scan requis pour ré-auditer)
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_count', store_id: storeId, token: csrfToken },
            success: function(data) {
                if (data && data.success) {
                    var countLabel = document.getElementById('d2s-underprice-audit-count');
                    if (countLabel) {
                        countLabel.textContent = countLabel.textContent.replace(/[0-9]+/, String(data.total || 0));
                    }
                }
            }
        });
    }
})();
</script>
<?php

llxFooter();
