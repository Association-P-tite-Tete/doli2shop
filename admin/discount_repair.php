<?php
/**
 * @file        admin/discount_repair.php
 * @brief       Recorrection batch des remises % sous-évaluées à l'import (Hotfix 2.4.5, AC4)
 *
 * Outil admin EXPLICITE (jamais automatique) : identifie et corrige les commandes Shopify
 * importées AVANT le fix v2.2.9 dont la ligne de remise globale en pourcentage est sous-évaluée
 * d'un facteur (1 + TVA) — cf. remontée #1025 Nicolas Graillon (Échafaudages Stéphanois).
 * Deux temps obligatoires : aperçu (dry-run, aucune écriture) puis application explicite —
 * pattern admin/sync_products.php (blocs Story 52-2 / Hotfix 2.4.4 2/2), page DÉDIÉE pour ne
 * pas toucher ce fichier volumineux (dev en parallèle sur le bloc stock, Story 52-4).
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.4.5
 * @since       2.4.5
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
dol_include_once('/doli2shop/class/discountrepairservice.class.php');

// Traductions
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Accès (pattern admin/sync_products.php, admin/maintenance.php)
if (!$user->admin) {
    accessforbidden();
}

// Contexte boutique (Story 49-9) — scope cohérent avec les autres pages admin
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
$currentAdminStoreIsSecondary = ($currentAdminStore !== null && empty($currentAdminStore->is_default));
$repairStoreId = $currentAdminStoreIsSecondary ? (int) $currentAdminStore->rowid : 0;

$discountRepairService = new DiscountRepairService($db, $conf->entity);
$discountCandidateCount = $discountRepairService->countCandidateOrders($repairStoreId);
$discountConfirmMessage = $langs->trans("DiscountRepairConfirm", (string) $discountCandidateCount);

// Page setup
llxHeader('', $langs->trans("DiscountRepairTitle"), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'discountrepair', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');

print load_fiche_titre($langs->trans("DiscountRepairTitle"), '', 'title_setup');

print '<div class="d2s-card" style="padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-percent"></i> ' . $langs->trans("DiscountRepairTitle") . '</h3>';
print '<p class="opacitymedium">' . dol_escape_htmltag($langs->trans("DiscountRepairIntro")) . '</p>';
print '<div class="warning" style="margin: 10px 0;">';
print '<strong>' . $langs->trans("Warning") . ':</strong> ' . dol_escape_htmltag($langs->trans("DiscountRepairWarning"));
print '</div>';
print '<p><strong id="d2s-discount-repair-count">' . dol_escape_htmltag($langs->trans("DiscountRepairCountLabel", (string) $discountCandidateCount)) . '</strong></p>';

print '<div style="margin-top: 12px;">';
print '<button type="button" class="butAction" id="d2s-discount-repair-scan-button">';
print '<i class="fa fa-search"></i> ' . $langs->trans("DiscountRepairScanButton");
print '</button> ';
print '<button type="button" class="butAction" id="d2s-discount-repair-apply-button" disabled>';
print '<i class="fa fa-wrench"></i> ' . $langs->trans("DiscountRepairApplyButton");
print '</button>';
print '</div>';
print '</div>';

// Zone de progression (cachée initialement)
print '<div id="d2s-discount-repair-progress" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-sync fa-spin" id="d2s-discount-repair-spinner"></i> ' . $langs->trans("DiscountRepairProgressTitle") . '</h3>';
print '<div class="d2s-progress-bar-container">';
print '<div class="d2s-progress-text" id="d2s-discount-repair-progress-text">0 / 0</div>';
print '<div class="d2s-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="d2s-discount-repair-progressbar">';
print '<div class="d2s-progress-fill" id="d2s-discount-repair-progress-fill" style="width: 0%;"></div>';
print '</div>';
print '</div>';
print '<div id="d2s-discount-repair-status" style="margin-top: 8px; font-size: 0.9em; color: #666;"></div>';
print '</div>';

// Zone aperçu (dry-run) — tableau des candidats
print '<div id="d2s-discount-repair-preview" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-eye"></i> ' . $langs->trans("DiscountRepairPreviewTitle") . '</h3>';
print '<div style="overflow-x: auto;">';
print '<table class="noborder" style="width: 100%;" id="d2s-discount-repair-preview-table">';
print '<thead><tr class="liste_titre">';
print '<th>' . $langs->trans("DiscountRepairColOrder") . '</th>';
print '<th>' . $langs->trans("DiscountRepairColStatus") . '</th>';
print '<th>' . $langs->trans("DiscountRepairColClassification") . '</th>';
print '<th>' . $langs->trans("DiscountRepairColCurrentHT") . '</th>';
print '<th>' . $langs->trans("DiscountRepairColExpectedHT") . '</th>';
print '<th>' . $langs->trans("DiscountRepairColDeltaHT") . '</th>';
print '</tr></thead>';
print '<tbody id="d2s-discount-repair-preview-body"></tbody>';
print '</table>';
print '</div>';
print '</div>';

// Zone de rapport (cachée initialement)
print '<div id="d2s-discount-repair-report" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-clipboard-list"></i> ' . $langs->trans("DiscountRepairComplete") . '</h3>';
print '<div id="d2s-discount-repair-report-kpis" style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;"></div>';
print '<div id="d2s-discount-repair-report-errors" style="display: none; margin-top: 12px;"></div>';
print '</div>';

print '</div>'; // .doli2shop-page

?>
<script>
/**
 * Hotfix 2.4.5 (AC4) — Recorrection batch des remises % sous-évaluées à l'import. Deux passes
 * AJAX distinctes via ajax/discount_repair_batch.php : scan (dry-run, jamais d'écriture) puis
 * repair (confirm=1 explicite). Pattern JS aligné sur admin/sync_products.php (recalage stock).
 */
(function() {
    'use strict';

    <?php
    $discountRepairJsTransKeys = array(
        'scanButton' => 'DiscountRepairScanButton',
        'scanInProgress' => 'DiscountRepairScanInProgress',
        'applyButton' => 'DiscountRepairApplyButton',
        'applyInProgress' => 'DiscountRepairApplyInProgress',
        'complete' => 'DiscountRepairComplete',
        'corrected' => 'DiscountRepairCorrected',
        'manualRequired' => 'DiscountRepairManualRequired',
        'manualReview' => 'DiscountRepairManualReview',
        'notAffected' => 'DiscountRepairNotAffected',
        'errors' => 'DiscountRepairErrors',
        'noErrors' => 'DiscountRepairNoErrors',
        'errorSync' => 'ErrorSync',
        'noCandidates' => 'DiscountRepairNoCandidates',
    );
    $discountRepairJsTransValues = array();
    foreach ($discountRepairJsTransKeys as $jsKey => $langKey) {
        $discountRepairJsTransValues[$jsKey] = html_entity_decode($langs->trans($langKey), ENT_QUOTES, 'UTF-8');
    }
    ?>
    var DTRANS = <?php echo json_encode($discountRepairJsTransValues, JSON_UNESCAPED_UNICODE); ?>;
    var DCONFIRM = <?php echo json_encode(html_entity_decode($discountConfirmMessage, ENT_QUOTES, 'UTF-8')); ?>;

    var ajaxUrl = <?php echo json_encode(dol_buildpath('/doli2shop/ajax/discount_repair_batch.php', 1)); ?>;
    var csrfToken = <?php echo json_encode(currentToken()); ?>;
    var storeId = <?php echo json_encode($repairStoreId); ?>;

    var scanButton = document.getElementById('d2s-discount-repair-scan-button');
    var applyButton = document.getElementById('d2s-discount-repair-apply-button');
    if (!scanButton || !applyButton || typeof jQuery === 'undefined') {
        return;
    }

    var progressSection = document.getElementById('d2s-discount-repair-progress');
    var progressText = document.getElementById('d2s-discount-repair-progress-text');
    var progressFill = document.getElementById('d2s-discount-repair-progress-fill');
    var progressBar = document.getElementById('d2s-discount-repair-progressbar');
    var statusDiv = document.getElementById('d2s-discount-repair-status');
    var spinner = document.getElementById('d2s-discount-repair-spinner');
    var previewSection = document.getElementById('d2s-discount-repair-preview');
    var previewBody = document.getElementById('d2s-discount-repair-preview-body');
    var reportSection = document.getElementById('d2s-discount-repair-report');
    var reportKpis = document.getElementById('d2s-discount-repair-report-kpis');
    var reportErrors = document.getElementById('d2s-discount-repair-report-errors');

    var isRunning = false;
    var hasScanResults = false;

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

    function formatAmount(value) {
        if (value === null || typeof value === 'undefined') {
            return '—';
        }
        return Number(value).toFixed(2).replace('.', ',') + ' €';
    }

    function appendPreviewRow(row) {
        var tr = document.createElement('tr');

        var tdOrder = document.createElement('td');
        tdOrder.textContent = (row.ref || ('#' + row.fkCommande));
        tr.appendChild(tdOrder);

        var tdStatus = document.createElement('td');
        tdStatus.textContent = row.isDraft ? 'Brouillon' : ('Statut ' + row.statut);
        tr.appendChild(tdStatus);

        var tdClass = document.createElement('td');
        tdClass.textContent = row.status;
        tr.appendChild(tdClass);

        var tdCurrent = document.createElement('td');
        tdCurrent.textContent = formatAmount(row.currentHT);
        tr.appendChild(tdCurrent);

        var tdExpected = document.createElement('td');
        tdExpected.textContent = formatAmount(row.expectedHT);
        tr.appendChild(tdExpected);

        var tdDelta = document.createElement('td');
        tdDelta.textContent = formatAmount(row.deltaHT);
        tr.appendChild(tdDelta);

        previewBody.appendChild(tr);
    }

    scanButton.addEventListener('click', function() {
        if (isRunning) {
            return;
        }
        isRunning = true;
        hasScanResults = false;
        applyButton.disabled = true;

        scanButton.disabled = true;
        scanButton.textContent = DTRANS.scanInProgress;

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
                    finishScan(DTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }
                var total = data.total || 0;
                progressText.textContent = '0 / ' + total;
                if (total === 0) {
                    finishScan(DTRANS.noCandidates);
                    return;
                }
                runScanBatch(0, total);
            },
            error: function(xhr, status, err) {
                finishScan(DTRANS.errorSync + ': ' + err);
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
                    finishScan(DTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
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
                finishScan(DTRANS.errorSync + ': ' + err);
            }
        });
    }

    function finishScan(errorMessage) {
        isRunning = false;
        scanCount = 0;
        scanButton.disabled = false;
        scanButton.textContent = DTRANS.scanButton;
        spinner.className = 'fa fa-check-circle';
        spinner.style.color = errorMessage ? '#dc3545' : '#28a745';

        if (errorMessage) {
            statusDiv.textContent = errorMessage;
            return;
        }

        previewSection.style.display = 'block';
        applyButton.disabled = !hasScanResults;
    }

    applyButton.addEventListener('click', function() {
        if (isRunning) {
            return;
        }
        if (!window.confirm(DCONFIRM)) {
            return;
        }

        isRunning = true;
        applyButton.disabled = true;
        applyButton.textContent = DTRANS.applyInProgress;
        scanButton.disabled = true;

        var totals = { processed: 0, corrected: 0, manualRequired: 0, manualReview: 0, notAffected: 0, errors: 0 };
        var allErrorDetails = [];

        progressSection.style.display = 'block';
        reportSection.style.display = 'none';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 traitées';
        statusDiv.textContent = '';
        spinner.className = 'fa fa-sync fa-spin';
        spinner.style.color = '';

        function runApplyBatch(cursor) {
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: { action: 'repair_batch', cursor: cursor, store_id: storeId, confirm: 1, token: csrfToken },
                success: function(data) {
                    if (!data || !data.success) {
                        finishApply(totals, allErrorDetails, DTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                        return;
                    }

                    totals.processed += (data.processed || 0);
                    totals.corrected += (data.corrected || 0);
                    totals.manualRequired += (data.manualRequired || 0);
                    totals.manualReview += (data.manualReview || 0);
                    totals.notAffected += (data.notAffected || 0);
                    totals.errors += (data.errors || 0);

                    if (data.errorDetails && data.errorDetails.length > 0) {
                        for (var i = 0; i < data.errorDetails.length; i++) {
                            allErrorDetails.push(data.errorDetails[i]);
                        }
                    }

                    progressText.textContent = totals.processed + ' traitées';
                    statusDiv.textContent = DTRANS.corrected + ': ' + totals.corrected +
                        ' | ' + DTRANS.manualRequired + ': ' + totals.manualRequired +
                        ' | ' + DTRANS.manualReview + ': ' + totals.manualReview +
                        ' | ' + DTRANS.errors + ': ' + totals.errors;

                    if (data.hasMore) {
                        runApplyBatch(parseInt(data.cursor || '0', 10));
                    } else {
                        finishApply(totals, allErrorDetails);
                    }
                },
                error: function(xhr, status, err) {
                    finishApply(totals, allErrorDetails, DTRANS.errorSync + ': ' + err);
                }
            });
        }

        runApplyBatch(0);
    });

    function finishApply(totals, allErrorDetails, errorMessage) {
        isRunning = false;
        applyButton.disabled = true; // relancer un scan avant une nouvelle application
        applyButton.textContent = DTRANS.applyButton;
        scanButton.disabled = false;

        spinner.className = errorMessage ? 'fa fa-exclamation-triangle' : 'fa fa-check-circle';
        spinner.style.color = errorMessage ? '#dc3545' : '#28a745';

        reportSection.style.display = 'block';
        reportKpis.innerHTML = '';
        reportKpis.appendChild(createKpiCard(totals.corrected, DTRANS.corrected, '#28a745'));
        reportKpis.appendChild(createKpiCard(totals.manualRequired, DTRANS.manualRequired, '#f0ad4e'));
        reportKpis.appendChild(createKpiCard(totals.manualReview, DTRANS.manualReview, '#6c757d'));
        reportKpis.appendChild(createKpiCard(totals.notAffected, DTRANS.notAffected, '#026B6A'));
        reportKpis.appendChild(createKpiCard(totals.errors, DTRANS.errors, totals.errors > 0 ? '#dc3545' : '#28a745'));

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

        // Rafraîchit le compteur affiché en haut de page (nouveau scan requis pour ré-appliquer)
        jQuery.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_count', store_id: storeId, token: csrfToken },
            success: function(data) {
                if (data && data.success) {
                    var countLabel = document.getElementById('d2s-discount-repair-count');
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
