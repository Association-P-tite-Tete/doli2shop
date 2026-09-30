<?php
/**
 * @file        admin/offsale_products.php
 * @brief       Traitement des produits hors vente déjà poussés sur Shopify (Story 58-5, AC4)
 *
 * Outil admin EXPLICITE (jamais automatique) : identifie les produits Dolibarr passés hors
 * vente (tosell=0) mais déjà mappés/poussés sur Shopify, et propose un traitement au choix —
 * laisser en brouillon, archiver, ou supprimer — cf. remontée Xavier Hubier (Europe Loisirs),
 * 2026-07-27. Deux temps obligatoires : aperçu (dry-run, aucune écriture) puis application
 * explicite — pattern admin/discount_repair.php (Hotfix 2.4.5, AC4).
 *
 * La suppression est IRRÉVERSIBLE (handle produit, images, référencement, liens depuis
 * d'anciennes commandes) : elle exige une confirmation DISTINCTE du bouton "Appliquer" général
 * (case à cocher dédiée + saisie d'un mot de confirmation) — aucun autre outil du module n'a
 * cette opération, ce mécanisme est inventé pour cette story.
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.5.0
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
dol_include_once('/doli2shop/class/offsaleproductsservice.class.php');

// Traductions
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// Accès (pattern admin/discount_repair.php, admin/sync_products.php)
if (!$user->admin) {
    accessforbidden();
}

// Contexte boutique (Story 49-9) — scope cohérent avec les autres pages admin
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
$currentAdminStoreIsSecondary = ($currentAdminStore !== null && empty($currentAdminStore->is_default));
$offsaleStoreId = $currentAdminStoreIsSecondary ? (int) $currentAdminStore->rowid : 0;

$offsaleService = new OffSaleProductsService($db, $conf->entity);
$offsaleCandidateCount = $offsaleService->countCandidates($offsaleStoreId);

// Page setup
llxHeader('', $langs->trans("OffSaleProductsTitle"), '', '', 0, 0, '', '', '', 'mod-doli2shop page-admin');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'offsaleproducts', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');

print load_fiche_titre($langs->trans("OffSaleProductsTitle"), '', 'title_setup');

print '<div class="d2s-card" style="padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-eye-slash"></i> ' . $langs->trans("OffSaleProductsTitle") . '</h3>';
print '<p class="opacitymedium">' . dol_escape_htmltag($langs->trans("OffSaleProductsIntro")) . '</p>';
print '<p><strong id="d2s-offsale-count">' . dol_escape_htmltag($langs->trans("OffSaleProductsCandidateCount", (string) $offsaleCandidateCount)) . '</strong></p>';

print '<div style="margin: 12px 0;">';
print '<label style="display: block; margin-bottom: 6px;"><input type="radio" name="d2s-offsale-action" value="leave_draft" checked> ' . $langs->trans("OffSaleActionLeaveDraft") . '</label>';
print '<label style="display: block; margin-bottom: 6px;"><input type="radio" name="d2s-offsale-action" value="archive"> ' . $langs->trans("OffSaleActionArchive") . '</label>';
print '<label style="display: block; margin-bottom: 6px;"><input type="radio" name="d2s-offsale-action" value="delete"> ' . $langs->trans("OffSaleActionDelete") . '</label>';
print '</div>';

// Bloc de double confirmation — visible UNIQUEMENT quand "delete" est sélectionné (AC4).
print '<div id="d2s-offsale-delete-confirm-block" class="warning" style="display: none; margin: 10px 0; padding: 10px;">';
print '<strong>' . $langs->trans("Warning") . ':</strong> ' . dol_escape_htmltag($langs->trans("OffSaleDeleteWarning"));
print '<div style="margin-top: 8px;">';
print '<label><input type="checkbox" id="d2s-offsale-delete-checkbox"> ' . dol_escape_htmltag($langs->trans("OffSaleDeleteConfirm")) . '</label>';
print '</div>';
print '<div style="margin-top: 8px;">';
print '<input type="text" id="d2s-offsale-delete-word" placeholder="' . dol_escape_htmltag($langs->trans("OffSaleDeleteConfirm")) . '" size="20" autocomplete="off">';
print '</div>';
print '</div>';

print '<div style="margin-top: 12px;">';
print '<button type="button" class="butAction" id="d2s-offsale-scan-button">';
print '<i class="fa fa-search"></i> ' . $langs->trans("DiscountRepairScanButton");
print '</button> ';
print '<button type="button" class="butActionDelete" id="d2s-offsale-apply-button" disabled>';
print '<i class="fa fa-wrench"></i> ' . $langs->trans("DiscountRepairApplyButton");
print '</button>';
print '</div>';
print '</div>';

// Zone de progression (cachée initialement)
print '<div id="d2s-offsale-progress" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-sync fa-spin" id="d2s-offsale-spinner"></i> ' . $langs->trans("DiscountRepairProgressTitle") . '</h3>';
print '<div class="d2s-progress-bar-container">';
print '<div class="d2s-progress-text" id="d2s-offsale-progress-text">0 / 0</div>';
print '<div class="d2s-progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="d2s-offsale-progressbar">';
print '<div class="d2s-progress-fill" id="d2s-offsale-progress-fill" style="width: 0%;"></div>';
print '</div>';
print '</div>';
print '<div id="d2s-offsale-status" style="margin-top: 8px; font-size: 0.9em; color: #666;"></div>';
print '</div>';

// Zone aperçu (dry-run) — tableau des candidats
print '<div id="d2s-offsale-preview" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-eye"></i> ' . $langs->trans("DiscountRepairPreviewTitle") . '</h3>';
print '<div style="overflow-x: auto;">';
print '<table class="noborder" style="width: 100%;" id="d2s-offsale-preview-table">';
print '<thead><tr class="liste_titre">';
print '<th>' . $langs->trans("Ref") . '</th>';
print '<th>' . $langs->trans("Label") . '</th>';
print '<th>' . $langs->trans("DiscountRepairColStatus") . '</th>';
print '</tr></thead>';
print '<tbody id="d2s-offsale-preview-body"></tbody>';
print '</table>';
print '</div>';
print '</div>';

// Zone de rapport (cachée initialement)
print '<div id="d2s-offsale-report" class="d2s-card" style="display: none; padding: 16px; margin-bottom: 16px;">';
print '<h3><i class="fa fa-clipboard-list"></i> ' . $langs->trans("DiscountRepairComplete") . '</h3>';
print '<div id="d2s-offsale-report-kpis" style="display: -webkit-box; display: -ms-flexbox; display: flex; -ms-flex-wrap: wrap; flex-wrap: wrap; margin: 12px 0;"></div>';
print '<div id="d2s-offsale-report-errors" style="display: none; margin-top: 12px;"></div>';
print '</div>';

print '</div>'; // .doli2shop-page

?>
<script>
/**
 * Story 58-5 (AC4) — Traitement des produits hors vente déjà poussés sur Shopify. Deux passes
 * AJAX distinctes via ajax/offsale_products_batch.php : scan (dry-run, jamais d'écriture) puis
 * repair (confirm=1 explicite ; delete exige EN PLUS confirm_delete=1). Pattern JS aligné sur
 * admin/discount_repair.php.
 */
(function() {
    'use strict';

    <?php
    $offsaleJsTransKeys = array(
        'scanButton' => 'DiscountRepairScanButton',
        'scanInProgress' => 'DiscountRepairScanInProgress',
        'applyButton' => 'DiscountRepairApplyButton',
        'applyInProgress' => 'DiscountRepairApplyInProgress',
        'complete' => 'DiscountRepairComplete',
        'leftDraft' => 'OffSaleActionLeaveDraft',
        'archived' => 'OffSaleActionArchive',
        'deleted' => 'OffSaleActionDelete',
        'errors' => 'DiscountRepairErrors',
        'noErrors' => 'DiscountRepairNoErrors',
        'errorSync' => 'ErrorSync',
        'noCandidates' => 'DiscountRepairNoCandidates',
        'deleteConfirmWord' => 'OffSaleDeleteConfirm',
    );
    $offsaleJsTransValues = array();
    foreach ($offsaleJsTransKeys as $jsKey => $langKey) {
        $offsaleJsTransValues[$jsKey] = html_entity_decode($langs->trans($langKey), ENT_QUOTES, 'UTF-8');
    }
    ?>
    var OTRANS = <?php echo json_encode($offsaleJsTransValues, JSON_UNESCAPED_UNICODE); ?>;

    var ajaxUrl = <?php echo json_encode(dol_buildpath('/doli2shop/ajax/offsale_products_batch.php', 1)); ?>;
    var csrfToken = <?php echo json_encode(currentToken()); ?>;
    var storeId = <?php echo json_encode($offsaleStoreId); ?>;

    var scanButton = document.getElementById('d2s-offsale-scan-button');
    var applyButton = document.getElementById('d2s-offsale-apply-button');
    if (!scanButton || !applyButton || typeof jQuery === 'undefined') {
        return;
    }

    var deleteBlock = document.getElementById('d2s-offsale-delete-confirm-block');
    var deleteCheckbox = document.getElementById('d2s-offsale-delete-checkbox');
    var deleteWord = document.getElementById('d2s-offsale-delete-word');
    var actionRadios = document.getElementsByName('d2s-offsale-action');

    var progressSection = document.getElementById('d2s-offsale-progress');
    var progressText = document.getElementById('d2s-offsale-progress-text');
    var progressFill = document.getElementById('d2s-offsale-progress-fill');
    var progressBar = document.getElementById('d2s-offsale-progressbar');
    var statusDiv = document.getElementById('d2s-offsale-status');
    var spinner = document.getElementById('d2s-offsale-spinner');
    var previewSection = document.getElementById('d2s-offsale-preview');
    var previewBody = document.getElementById('d2s-offsale-preview-body');
    var reportSection = document.getElementById('d2s-offsale-report');
    var reportKpis = document.getElementById('d2s-offsale-report-kpis');
    var reportErrors = document.getElementById('d2s-offsale-report-errors');

    var isRunning = false;
    var hasScanResults = false;

    function getSelectedAction() {
        for (var k = 0; k < actionRadios.length; k++) {
            if (actionRadios[k].checked) {
                return actionRadios[k].value;
            }
        }
        return 'leave_draft';
    }

    function updateDeleteBlockVisibility() {
        deleteBlock.style.display = (getSelectedAction() === 'delete') ? 'block' : 'none';
        updateApplyButtonState();
    }

    // AC4 : double confirmation — la suppression n'est JAMAIS activable par défaut. Bouton
    // "Appliquer" désactivé tant que (aperçu fait) ET (case cochée) ET (mot exact saisi), pour
    // l'action "delete" uniquement.
    function updateApplyButtonState() {
        if (!hasScanResults) {
            applyButton.disabled = true;
            return;
        }
        if (getSelectedAction() !== 'delete') {
            applyButton.disabled = false;
            return;
        }
        var wordOk = (deleteWord.value === OTRANS.deleteConfirmWord);
        applyButton.disabled = !(deleteCheckbox.checked && wordOk);
    }

    for (var r = 0; r < actionRadios.length; r++) {
        actionRadios[r].addEventListener('change', function() {
            hasScanResults = false; // changer d'action invalide l'aperçu précédent (relancer un scan)
            previewSection.style.display = 'none';
            updateDeleteBlockVisibility();
        });
    }
    deleteCheckbox.addEventListener('change', updateApplyButtonState);
    deleteWord.addEventListener('input', updateApplyButtonState);

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

    function appendPreviewRow(row) {
        var tr = document.createElement('tr');

        var tdRef = document.createElement('td');
        tdRef.textContent = row.ref || ('#' + row.fkProduct);
        tr.appendChild(tdRef);

        var tdLabel = document.createElement('td');
        tdLabel.textContent = row.label || '';
        tr.appendChild(tdLabel);

        var tdStatus = document.createElement('td');
        tdStatus.textContent = row.shopifyStatus || '?';
        tr.appendChild(tdStatus);

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
        scanButton.textContent = OTRANS.scanInProgress;

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
                    finishScan(OTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                    return;
                }
                var total = data.total || 0;
                progressText.textContent = '0 / ' + total;
                if (total === 0) {
                    finishScan(OTRANS.noCandidates);
                    return;
                }
                runScanBatch(0, total);
            },
            error: function(xhr, status, err) {
                finishScan(OTRANS.errorSync + ': ' + err);
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
                    finishScan(OTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
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
                finishScan(OTRANS.errorSync + ': ' + err);
            }
        });
    }

    function finishScan(errorMessage) {
        isRunning = false;
        scanCount = 0;
        scanButton.disabled = false;
        scanButton.textContent = OTRANS.scanButton;
        spinner.className = 'fa fa-check-circle';
        spinner.style.color = errorMessage ? '#dc3545' : '#28a745';

        if (errorMessage) {
            statusDiv.textContent = errorMessage;
            return;
        }

        previewSection.style.display = 'block';
        updateApplyButtonState();
    }

    applyButton.addEventListener('click', function() {
        if (isRunning || applyButton.disabled) {
            return;
        }

        var action = getSelectedAction();
        var confirmDelete = (action === 'delete') ? 1 : 0;

        if (!window.confirm(OTRANS.applyButton + ' (' + action + ') ?')) {
            return;
        }

        isRunning = true;
        applyButton.disabled = true;
        applyButton.textContent = OTRANS.applyInProgress;
        scanButton.disabled = true;

        var totals = { processed: 0, leftDraft: 0, archived: 0, deleted: 0, errors: 0 };
        var allErrorDetails = [];

        progressSection.style.display = 'block';
        reportSection.style.display = 'none';
        progressFill.style.width = '0%';
        progressBar.setAttribute('aria-valuenow', '0');
        progressText.textContent = '0 traités';
        statusDiv.textContent = '';
        spinner.className = 'fa fa-sync fa-spin';
        spinner.style.color = '';

        function runApplyBatch(cursor) {
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'repair_batch',
                    cursor: cursor,
                    store_id: storeId,
                    offsale_action: action,
                    confirm: 1,
                    confirm_delete: confirmDelete,
                    token: csrfToken
                },
                success: function(data) {
                    if (!data || !data.success) {
                        finishApply(totals, allErrorDetails, OTRANS.errorSync + ': ' + (data ? data.message : 'Unknown error'));
                        return;
                    }

                    totals.processed += (data.processed || 0);
                    totals.leftDraft += (data.leftDraft || 0);
                    totals.archived += (data.archived || 0);
                    totals.deleted += (data.deleted || 0);
                    totals.errors += (data.errors || 0);

                    if (data.errorDetails && data.errorDetails.length > 0) {
                        for (var i = 0; i < data.errorDetails.length; i++) {
                            allErrorDetails.push(data.errorDetails[i]);
                        }
                    }

                    progressText.textContent = totals.processed + ' traités';
                    statusDiv.textContent = OTRANS.leftDraft + ': ' + totals.leftDraft +
                        ' | ' + OTRANS.archived + ': ' + totals.archived +
                        ' | ' + OTRANS.deleted + ': ' + totals.deleted +
                        ' | ' + OTRANS.errors + ': ' + totals.errors;

                    if (data.hasMore) {
                        runApplyBatch(parseInt(data.cursor || '0', 10));
                    } else {
                        finishApply(totals, allErrorDetails);
                    }
                },
                error: function(xhr, status, err) {
                    finishApply(totals, allErrorDetails, OTRANS.errorSync + ': ' + err);
                }
            });
        }

        runApplyBatch(0);
    });

    function finishApply(totals, allErrorDetails, errorMessage) {
        isRunning = false;
        applyButton.disabled = true; // relancer un scan avant une nouvelle application
        applyButton.textContent = OTRANS.applyButton;
        scanButton.disabled = false;
        hasScanResults = false;

        spinner.className = errorMessage ? 'fa fa-exclamation-triangle' : 'fa fa-check-circle';
        spinner.style.color = errorMessage ? '#dc3545' : '#28a745';

        reportSection.style.display = 'block';
        reportKpis.innerHTML = '';
        reportKpis.appendChild(createKpiCard(totals.leftDraft, OTRANS.leftDraft, '#026B6A'));
        reportKpis.appendChild(createKpiCard(totals.archived, OTRANS.archived, '#f0ad4e'));
        reportKpis.appendChild(createKpiCard(totals.deleted, OTRANS.deleted, '#dc3545'));
        reportKpis.appendChild(createKpiCard(totals.errors, OTRANS.errors, totals.errors > 0 ? '#dc3545' : '#28a745'));

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
                    var countLabel = document.getElementById('d2s-offsale-count');
                    if (countLabel) {
                        countLabel.textContent = countLabel.textContent.replace(/[0-9]+/, String(data.total || 0));
                    }
                }
            }
        });
    }

    updateDeleteBlockVisibility();
})();
</script>
<?php

llxFooter();
