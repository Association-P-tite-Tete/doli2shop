<?php
/**
 * @file        admin/_shipping_mapping_table.inc.php
 * @brief       Include partagé — Tableau mapping expédition 3 sections (title, tracking, auto-détectés)
 *
 * Ce fichier est un fragment PHP inclus dans des pages parentes (setup.php, wizard).
 * Il partage le scope de l'appelant et ne contient PAS de logique de sauvegarde.
 *
 * Variables requises dans le scope de l'appelant :
 * @var DoliDB    $db               Instance base de données
 * @var Conf      $conf             Configuration Dolibarr (pour entity)
 * @var Translate $langs            Objet traduction (déjà chargé doli2shop@doli2shop)
 * @var array     $shippingmethods  Méthodes d'expédition Dolibarr [id => label]
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.2.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection — ne pas appeler directement
if (!defined('MAIN_DB_PREFIX')) {
    die('Include only');
}

// Story 48-4 : portée par boutique. L'appelant peut définir $mappingStoreId pour n'afficher
// que les règles d'une boutique (>0). 0 = pas de filtre fk_store (legacy/global).
$mappingStoreId = isset($mappingStoreId) ? (int) $mappingStoreId : 0;
$mappingStoreFilter = ($mappingStoreId > 0) ? (' AND fk_store = ' . $mappingStoreId) : '';

// ============================================================
// SECTION 1 : À LA COMMANDE — Choix d'expédition Shopify (rule_type = 'title')
// ============================================================
print '<div class="titre inline-block" style="margin-bottom:8px;">';
print '<i class="fa fa-shopping-cart opacitymedium"></i> ' . $langs->trans("ShippingRulesAtOrder");
print '</div>';
print '<p class="opacitymedium" style="margin-top:0;">' . $langs->trans("ShippingRulesAtOrderHelp") . '</p>';

print '<div class="table-container">';
print '<table id="title-mappings" class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("ShopifyPattern").'</th>';
print '<th>'.$langs->trans("DolibarrMethod").'</th>';
print '<th>'.$langs->trans("DeliveryDays").'</th>';
print '<th>';
print '<a class="butActionNew" id="addTitleMapping" href="#"'.(!empty($shippingmethods) ? '' : ' style="pointer-events:none;opacity:0.5"').'><i class="fa fa-plus-circle" title="'.$langs->trans("AddShippingMapping").'"></i></a>';
print '</th>';
print '</tr>';

// Lignes existantes title
$sql = "SELECT * FROM ".MAIN_DB_PREFIX."doli2shop_shipping_rules WHERE rule_type = 'title' AND entity = ".(int)$conf->entity.$mappingStoreFilter." ORDER BY shopify_pattern ASC";
$resql = $db->query($sql);
$has_title_rows = false;
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $has_title_rows = true;
        print '<tr>';
        print '<td><input type="text" name="title_shopify_pattern[]" value="'.dol_escape_htmltag($obj->shopify_pattern).'" class="minwidth300" placeholder="'.$langs->trans("Example").': Livraison standard"></td>';
        print '<td><select name="title_dol_shipping_method_id[]" class="flat minwidth200">';
        foreach ($shippingmethods as $id => $label) {
            print '<option value="'.(int)$id.'"'.($obj->dol_shipping_method_id == $id ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
        }
        print '</select></td>';
        print '<td><input type="number" name="title_delivery_days[]" value="'.$obj->delivery_days.'" min="1" max="30"></td>';
        print '<td class="center"><i class="fa fa-trash shipping-delete" title="'.$langs->trans("Delete").'"></i></td>';
        print '</tr>';
    }
    $db->free($resql);
}

if (!$has_title_rows) {
    print '<tr class="oddeven title-empty-msg"><td colspan="4" class="opacitymedium center">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("NoShippingMethodMappingDefined") . '<br>';
    print $langs->trans("ClickAddButtonToCreate");
    print '</td></tr>';
}

print '</table>';
print '</div>';

// ============================================================
// SECTION 2 : AU FULFILLMENT — Transporteur réel (rule_type = 'tracking')
// ============================================================
print '<br>';
print '<div class="titre inline-block" style="margin-bottom:8px;">';
print '<i class="fa fa-truck opacitymedium"></i> ' . $langs->trans("ShippingRulesAtFulfillment");
print '</div>';
print '<p class="opacitymedium" style="margin-top:0;">' . $langs->trans("ShippingRulesAtFulfillmentHelp") . '</p>';

print '<div class="table-container">';
print '<table id="tracking-mappings" class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("ShopifyPattern").'</th>';
print '<th>'.$langs->trans("DolibarrMethod").'</th>';
print '<th>'.$langs->trans("DeliveryDays").'</th>';
print '<th>';
print '<a class="butActionNew" id="addTrackingMapping" href="#"'.(!empty($shippingmethods) ? '' : ' style="pointer-events:none;opacity:0.5"').'><i class="fa fa-plus-circle" title="'.$langs->trans("AddShippingMapping").'"></i></a>';
print ' <a class="butActionNew" id="restoreDefaultCarriers" href="#" title="'.$langs->trans("RestoreDefaultCarriers").'"><i class="fa fa-undo"></i></a>';
print '</th>';
print '</tr>';

// Lignes existantes tracking
$sql = "SELECT * FROM ".MAIN_DB_PREFIX."doli2shop_shipping_rules WHERE rule_type = 'tracking' AND entity = ".(int)$conf->entity.$mappingStoreFilter." ORDER BY shopify_pattern ASC";
$resql = $db->query($sql);
$has_tracking_rows = false;
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $has_tracking_rows = true;
        print '<tr>';
        print '<td><input type="text" name="tracking_shopify_pattern[]" value="'.dol_escape_htmltag($obj->shopify_pattern).'" class="minwidth300" placeholder="'.$langs->trans("Example").': Colissimo"></td>';
        print '<td><select name="tracking_dol_shipping_method_id[]" class="flat minwidth200">';
        foreach ($shippingmethods as $id => $label) {
            print '<option value="'.(int)$id.'"'.($obj->dol_shipping_method_id == $id ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
        }
        print '</select></td>';
        print '<td><input type="number" name="tracking_delivery_days[]" value="'.$obj->delivery_days.'" min="1" max="30"></td>';
        print '<td class="center"><i class="fa fa-trash shipping-delete" title="'.$langs->trans("Delete").'"></i></td>';
        print '</tr>';
    }
    $db->free($resql);
}

if (!$has_tracking_rows) {
    print '<tr class="oddeven tracking-empty-msg"><td colspan="4" class="opacitymedium center">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("NoShippingMethodMappingDefined") . '<br>';
    print $langs->trans("ClickAddButtonToCreate");
    print '</td></tr>';
}

print '</table>';
print '</div>';

// ============================================================
// SECTION 3 : Transporteurs auto-détectés (D2S_*)
// ============================================================
$sqlAutoDetected = "SELECT rowid, code, libelle, active FROM " . MAIN_DB_PREFIX . "c_shipment_mode"
    . " WHERE code LIKE 'D2S_%'"
    . " AND entity IN (0, " . (int)$conf->entity . ")"
    . " ORDER BY libelle ASC";
$resqlAuto = $db->query($sqlAutoDetected);
$autoDetectedModes = [];
if ($resqlAuto) {
    while ($objAuto = $db->fetch_object($resqlAuto)) {
        $autoDetectedModes[] = $objAuto;
    }
    $db->free($resqlAuto);
}

if (!empty($autoDetectedModes)) {
    print '<br>';
    print '<div class="info-box">';
    print '<div class="titre inline-block" style="margin-bottom:8px;">';
    print '<i class="fa fa-bolt opacitymedium"></i> ' . $langs->trans("ShippingRulesAutoDetected");
    print '</div>';
    print '<p class="opacitymedium" style="margin-top:0;">' . $langs->trans("AutoDetectedCarriersHelp") . '</p>';
    print '<div class="table-container">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("Code") . '</th>';
    print '<th>' . $langs->trans("Label") . '</th>';
    print '<th class="center">' . $langs->trans("Status") . '</th>';
    print '<th class="center">' . $langs->trans("Actions") . '</th>';
    print '</tr>';
    foreach ($autoDetectedModes as $mode) {
        print '<tr class="oddeven">';
        print '<td><span class="badge badge-secondary">' . dol_escape_htmltag($mode->code) . '</span></td>';
        print '<td>' . dol_escape_htmltag($mode->libelle) . '</td>';
        print '<td class="center">';
        if ($mode->active) {
            print '<span class="badge badge-status4">' . $langs->trans("Enabled") . '</span>';
        } else {
            print '<span class="badge badge-status8">' . $langs->trans("Disabled") . '</span>';
        }
        print '</td>';
        print '<td class="center">';
        print '<a href="#" class="create-mapping-from-auto butActionNew" data-carrier="' . dol_escape_htmltag($mode->libelle) . '" data-method-id="' . (int)$mode->rowid . '" title="' . $langs->trans("CreateMappingFromAuto") . '">';
        print '<i class="fa fa-plus-circle"></i> ' . $langs->trans("CreateMapping");
        print '</a>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
    print '</div>';
}

// ============================================================
// JavaScript — Gestion des 2 tableaux (title + tracking) + auto-détectés
// ============================================================
print '<script>
document.addEventListener("DOMContentLoaded", function() {';

    // Options HTML partagées pour les selects title et tracking
    print 'var shippingOptionsHtml = `';
    foreach ($shippingmethods as $id => $label) {
        print '<option value="'.dol_escape_htmltag($id).'">'.dol_escape_htmltag($label).'</option>';
    }
    print '`;';
    print 'function makeSelect(namePrefix) { return \'<select name="\' + namePrefix + \'_dol_shipping_method_id[]" class="flat minwidth200">\' + shippingOptionsHtml + \'</select>\'; }';
    print 'var titleSelectHtml = makeSelect("title");';
    print 'var trackingSelectHtml = makeSelect("tracking");';

    print '
    // Suppression de ligne
    function attachDeleteHandlers() {
        document.querySelectorAll(".shipping-delete").forEach(function(btn) {
            btn.onclick = function() {
                if (confirm("'.$langs->trans("ConfirmDeleteMapping").'")) {
                    this.closest("tr").remove();
                }
            };
        });
    }
    attachDeleteHandlers();

    // Ajouter une règle title
    document.getElementById("addTitleMapping").addEventListener("click", function(e) {
        e.preventDefault();
        var emptyRow = document.querySelector(".title-empty-msg");
        if (emptyRow) emptyRow.remove();
        var table = document.getElementById("title-mappings");
        var newRow = table.insertRow(-1);
        newRow.innerHTML = \'<td><input type="text" name="title_shopify_pattern[]" class="minwidth300" placeholder="'.$langs->trans("Example").': Livraison standard"></td>\'
            + \'<td>\' + titleSelectHtml + \'</td>\'
            + \'<td><input type="number" name="title_delivery_days[]" value="3" min="1" max="30"></td>\'
            + \'<td class="center"><i class="fa fa-trash shipping-delete" title="'.$langs->trans("Delete").'"></i></td>\';
        attachDeleteHandlers();
    });

    // Ajouter une règle tracking
    document.getElementById("addTrackingMapping").addEventListener("click", function(e) {
        e.preventDefault();
        var emptyRow = document.querySelector(".tracking-empty-msg");
        if (emptyRow) emptyRow.remove();
        var table = document.getElementById("tracking-mappings");
        var newRow = table.insertRow(-1);
        newRow.innerHTML = \'<td><input type="text" name="tracking_shopify_pattern[]" class="minwidth300" placeholder="'.$langs->trans("Example").': Colissimo"></td>\'
            + \'<td>\' + trackingSelectHtml + \'</td>\'
            + \'<td><input type="number" name="tracking_delivery_days[]" value="2" min="1" max="30"></td>\'
            + \'<td class="center"><i class="fa fa-trash shipping-delete" title="'.$langs->trans("Delete").'"></i></td>\';
        attachDeleteHandlers();
    });

    // Restaurer les défauts (tracking — 10 transporteurs français courants)
    document.getElementById("restoreDefaultCarriers").addEventListener("click", function(e) {
        e.preventDefault();
        if (!confirm("'.$langs->trans("RestoreDefaultCarriersConfirm").'")) return;
        var defaults = [
            {pattern: "Colissimo", days: 2},
            {pattern: "Mondial Relay", days: 4},
            {pattern: "Chronopost", days: 1},
            {pattern: "DPD France", days: 2},
            {pattern: "UPS", days: 2},
            {pattern: "FedEx", days: 2},
            {pattern: "DHL", days: 2},
            {pattern: "La Poste", days: 3},
            {pattern: "GLS", days: 2},
            {pattern: "TNT", days: 2}
        ];
        var table = document.getElementById("tracking-mappings");
        while (table.rows.length > 1) table.deleteRow(1);
        defaults.forEach(function(item) {
            var newRow = table.insertRow(-1);
            newRow.innerHTML = \'<td><input type="text" name="tracking_shopify_pattern[]" value="\' + item.pattern + \'" class="minwidth300"></td>\'
                + \'<td>\' + trackingSelectHtml + \'</td>\'
                + \'<td><input type="number" name="tracking_delivery_days[]" value="\' + item.days + \'" min="1" max="30"></td>\'
                + \'<td class="center"><i class="fa fa-trash shipping-delete" title="'.$langs->trans("Delete").'"></i></td>\';
        });
        attachDeleteHandlers();
    });

    // Créer un mapping depuis la section auto-détectés → insert dans tracking
    document.querySelectorAll(".create-mapping-from-auto").forEach(function(link) {
        link.addEventListener("click", function(e) {
            e.preventDefault();
            var carrier = this.getAttribute("data-carrier");
            var methodId = this.getAttribute("data-method-id");
            var emptyRow = document.querySelector(".tracking-empty-msg");
            if (emptyRow) emptyRow.remove();
            var table = document.getElementById("tracking-mappings");
            var newRow = table.insertRow(-1);
            var optHtml = \'<select name="tracking_dol_shipping_method_id[]" class="flat minwidth200">\';';
            foreach ($shippingmethods as $id => $label) {
                print 'optHtml += \'<option value="'.dol_escape_htmltag($id).'"\'+(methodId == "'.dol_escape_htmltag($id).'" ? " selected" : "")+\'>'.dol_escape_htmltag($label).'</option>\';';
            }
            print 'optHtml += \'</select>\';
            newRow.innerHTML = \'<td><input type="text" name="tracking_shopify_pattern[]" value="\' + carrier + \'" class="minwidth300"></td>\'
                + \'<td>\' + optHtml + \'</td>\'
                + \'<td><input type="number" name="tracking_delivery_days[]" value="2" min="1" max="30"></td>\'
                + \'<td class="center"><i class="fa fa-trash shipping-delete" title="'.$langs->trans("Delete").'"></i></td>\';
            attachDeleteHandlers();
            newRow.scrollIntoView({behavior: "smooth", block: "center"});
        });
    });
});
</script>';
