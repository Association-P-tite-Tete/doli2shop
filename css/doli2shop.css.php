<?php
/**
 * @file        css/doli2shop.css.php
 * @brief       CSS stylesheet for Doli2Shop module
 *
 * @package     Doli2Shop
 * @subpackage  CSS
 * @category    css
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.36
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct
if (!defined('NOREQUIREUSER')) {
    define('NOREQUIREUSER', '1');
}
if (!defined('NOREQUIREDB')) {
    define('NOREQUIREDB', '1');
}
if (!defined('NOREQUIRESOC')) {
    define('NOREQUIRESOC', '1');
}
if (!defined('NOREQUIRETRAN')) {
    define('NOREQUIRETRAN', '1');
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
// Story 59-3 : une feuille de style n'a aucune raison d'ouvrir une session Dolibarr —
// NOSESSION évite session_start() (main.inc.php) à chaque chargement CSS, sans rapport avec
// $_SESSION['dol_entity'] lu plus loin dans master.inc.php (ce test est conditionné à
// session_id() non vide, donc ignoré sans session active).
if (!defined('NOSESSION')) {
    define('NOSESSION', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

session_cache_limiter('public');

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include "../../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Define css type
header('Content-type: text/css');

// Output CSS content
?>
/* Style pour les icônes d'aide */
.help-icon {
    display: inline-block;
    width: 16px;
    height: 16px;
    line-height: 16px;
    text-align: center;
    border-radius: 50%;
    background-color: #007bff;
    color: white;
    font-size: 12px;
    font-weight: bold;
    cursor: help;
    margin-left: 5px;
}

/* Style pour le guide de configuration */
.setup-guide {
    max-width: 1000px;
    margin: 0 auto;
}

.setup-section {
    background-color: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    padding: 20px;
    margin-bottom: 20px;
}

.setup-section h2 {
    color: #333;
    margin-top: 0;
}

.setup-explanation {
    margin-bottom: 20px;
}

.setup-fields table {
    width: 100%;
}

.setup-guide .setup-action {
    margin-top: 15px;
    text-align: right;
}

.setup-guide h3 {
    margin-top: 20px;
    margin-bottom: 10px;
    color: #333;
    font-size: 1.1em;
}

.action-buttons {
    text-align: right;
    margin-top: 20px;
}

.features-box {
    background-color: #e3f2fd;
    border: 1px solid #90caf9;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
}

.success-box {
    background-color: #e8f5e8;
    border: 1px solid #81c784;
    border-radius: 5px;
    padding: 15px;
    margin: 15px 0;
}

/* ============================================
   STYLES ISOLÉS AU MODULE SHOPIFYINTEGRATION
   v2.1.3 - Isolation CSS avec .doli2shop-page
   Ces styles ne s'appliquent QUE sur les pages du module
   ============================================ */

/* Lignes de titre - scopé au module */
.doli2shop-page tr.liste_titre th {
    background-color: rgba(47, 173, 180, 0.78);
    font-weight: bold;
    border-bottom: 1px solid #ccc!important;
    text-align: center;
    padding: 5px;
}

/* Lignes alternées - paires - scopé au module */
.doli2shop-page tr.pair td {
    background-color: #ffffff;
    border-bottom: 1px solid #eee;
    padding: 5px;
}

/* Lignes alternées - impaires - scopé au module */
.doli2shop-page tr.impair td {
    background-color: #f8f8f8;
    border-bottom: 1px solid #eee;
    padding: 5px;
}

/* Style alternatif odd - scopé au module */
.doli2shop-page tr.odd td {
    background-color: #f8f8f8;
    border-bottom: 1px solid #eee;
    padding: 5px;
}

/* Style alternatif even - scopé au module */
.doli2shop-page tr.even td {
    background-color: #ffffff;
    border-bottom: 1px solid #eee;
    padding: 5px;
}

/* Cellules de titre de champ - scopé au module */
.doli2shop-page td.titlefieldcreate {
    font-weight: bold;
    width: 30%;
    padding: 5px;
}

/* Styles pour les champs obligatoires - scopés au module */

/* Style pour tous les éléments avec la classe 'required' */
.doli2shop-page .required:after {
    content: " *";
    color: #FF0000;
    font-weight: bold;
}

/* Styles spécifiques pour les cellules de tableaux */
.doli2shop-page tr.pair td.required:after,
.doli2shop-page tr.impair td.required:after,
.doli2shop-page tr.odd td.required:after,
.doli2shop-page tr.even td.required:after {
    content: " *";
    color: #FF0000;
    font-weight: bold;
}

/* Style spécifique pour les cellules de type titlefieldcreate */
.doli2shop-page td.titlefieldcreate.required:after {
    content: " *";
    color: #FF0000;
    font-weight: bold;
}

/* Style pour les champs de formulaire obligatoires */
.doli2shop-page input.required-field,
.doli2shop-page select.required-field,
.doli2shop-page textarea.required-field {
    border-left: 3px solid #FF0000;
}

/* Style pour la légende des champs obligatoires */
.doli2shop-page .required-legend {
    font-size: 0.9em;
    font-style: italic;
    margin-top: 10px;
    color: #555;
}

.doli2shop-page .required-legend:before {
    content: "* ";
    color: #FF0000;
    font-weight: bold;
}

/* Boîtes d'information spécifiques au module Shopify */
.shopify-info-box {
    background-color: #fff3e0;
    border: 1px solid #ffe082;
    border-radius: 5px;
    padding: 15px;
    margin: 15px 0;
    box-sizing: border-box;
}

.shopify-warning-box {
    background-color: #ffebee;
    border: 1px solid #ef9a9a;
    border-radius: 5px;
    padding: 15px;
    margin: 15px 0;
    box-sizing: border-box;
}

/* Styles pour la page About */
.about-module {
    margin: 20px 0;
}

.about-header {
    display: flex;
    align-items: center;
    margin-bottom: 30px;
}

.about-logo {
    margin-right: 20px;
}

.about-logo img {
    max-width: 100px;
    height: auto;
}

.about-info h1 {
    margin: 0 0 10px 0;
}

.version, .author {
    margin: 5px 0;
}

.about-description {
    margin-bottom: 30px;
}

.about-features {
    margin-bottom: 30px;
}

.feature-columns {
    display: flex;
    gap: 30px;
}

.feature-column {
    flex: 1;
}

.about-support {
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 5px;
}

.button {
    display: inline-block;
    padding: 10px 15px;
    background-color: #007bff;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-weight: bold;
}

.button:hover {
    background-color: #0069d9;
}

/* Styles pour la page de synchronisation manuelle */
.sync-container {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 20px;
    margin: 20px 0;
}

.sync-form {
    max-width: 800px;
    margin: 0 auto;
}

.sync-form .form-group {
    margin-bottom: 20px;
}

.sync-form label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
}

.sync-form select,
.sync-form input[type="text"] {
    width: 100%;
    padding: 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.sync-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.sync-stats {
    background: white;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
}

.sync-stats h3 {
    margin-top: 0;
    color: #495057;
}

.stat-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    border-bottom: 1px solid #f0f0f0;
}

.sync-button {
    background-color: #28a745;
    color: white;
    border: none;
    padding: 10px 30px;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    margin-top: 20px;
}

.sync-button:hover {
    background-color: #218838;
}

.sync-button:disabled {
    background-color: #6c757d;
    cursor: not-allowed;
}

/* ===== Toggle Switch Style Dolibarr (v2.0.36 - Issue #130) ===== */

/* Container pour le toggle */
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    vertical-align: middle;
}

/* Cacher la checkbox native */
.toggle-switch input[type="checkbox"] {
    opacity: 0;
    width: 0;
    height: 0;
}

/* Le slider (fond du toggle) */
.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: 0.3s;
    border-radius: 24px;
}

/* Le bouton circulaire */
.toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

/* État activé (checked) */
.toggle-switch input:checked + .toggle-slider {
    background-color: #28a745;
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(20px);
}

/* État désactivé avec curseur not-allowed */
.toggle-switch input:disabled + .toggle-slider {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Effet hover */
.toggle-slider:hover {
    opacity: 0.9;
}

/* Style pour l'alignement avec les help-icons */
.toggle-switch + .help-icon {
    vertical-align: middle;
}

/* ===== Badges (v2.2.0 - Story 3.3 + Story 5.1) ===== */
/* Couleurs WCAG 2.1 AA conformes — contraste >= 4.5:1 */

/* Base badge — shared properties (AC #2) */
.d2s-badge,
.d2s-badge-success,
.d2s-badge-error,
.d2s-badge-skipped,
.d2s-badge-duplicate,
.d2s-badge-pending {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.75em;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
    line-height: 1.5;
}

/* Variants */
.d2s-badge-success {
    background-color: #d4edda;
    background-color: var(--d2s-success-bg, #d4edda);
    color: #155724;
    color: var(--d2s-success-text, #155724);
}

.d2s-badge-error {
    background-color: #f8d7da;
    background-color: var(--d2s-error-bg, #f8d7da);
    color: #721c24;
    color: var(--d2s-error-text, #721c24);
}

.d2s-badge-skipped {
    background-color: #e2e3e5;
    color: #383d41;
}

.d2s-badge-duplicate {
    background-color: #d1ecf1;
    background-color: var(--d2s-info-bg, #d1ecf1);
    color: #0c5460;
    color: var(--d2s-info, #0c5460);
}

.d2s-badge-pending {
    background-color: #fff3cd;
    background-color: var(--d2s-warning-bg, #fff3cd);
    color: #856404;
    color: var(--d2s-warning-text, #856404);
}

/* ===== Empty State (v2.2.0 - Story 5.1, AC #3) ===== */

.d2s-empty-state {
    text-align: center;
    padding: 32px 16px;
    padding: var(--d2s-space-xl, 32px) var(--d2s-space-md, 16px);
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
}

.d2s-empty-state-icon {
    display: block;
    font-size: 2.5em;
    margin-bottom: 12px;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
    opacity: 0.6;
}

.d2s-empty-state-message {
    font-size: 1.1em;
    margin: 0 0 4px;
    color: #333333;
    color: var(--d2s-text, #333333);
}

.d2s-empty-state-hint {
    font-size: 0.9em;
    margin: 0 0 16px;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
}

.d2s-empty-state-action {
    margin-top: 8px;
    margin-top: var(--d2s-space-sm, 8px);
}

.d2s-empty-state-action:focus-visible {
    outline: 2px solid #029e9c;
    outline: 2px solid var(--d2s-brand-primary, #029e9c);
    outline-offset: 2px;
    border-radius: 2px;
}

/* ===== Alert Banner (v2.2.0 - Story 5.1, AC #4) ===== */

.d2s-alert-banner {
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-align: center;
    -ms-flex-align: center;
    align-items: center;
    padding: 12px 16px;
    border-radius: 4px;
    border-radius: var(--d2s-radius, 4px);
    margin-bottom: 16px;
    margin-bottom: var(--d2s-space-md, 16px);
    border: 1px solid transparent;
}

.d2s-alert-icon {
    -ms-flex-negative: 0;
    flex-shrink: 0;
    margin-right: 10px;
    font-size: 1.1em;
}

.d2s-alert-text {
    -webkit-box-flex: 1;
    -ms-flex: 1 1 auto;
    flex: 1 1 auto;
}

.d2s-alert-action {
    -ms-flex-negative: 0;
    flex-shrink: 0;
    margin-left: 16px;
    margin-left: var(--d2s-space-md, 16px);
    font-weight: 600;
    white-space: nowrap;
}

.d2s-alert-close {
    -ms-flex-negative: 0;
    flex-shrink: 0;
    margin-left: 16px;
    margin-left: var(--d2s-space-md, 16px);
    background: none;
    border: none;
    font-size: 1.3em;
    cursor: pointer;
    padding: 0 4px;
    line-height: 1;
    opacity: 0.7;
    color: inherit;
    min-width: 44px;
    min-height: 44px;
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-align: center;
    -ms-flex-align: center;
    align-items: center;
    -webkit-box-pack: center;
    -ms-flex-pack: center;
    justify-content: center;
}

.d2s-alert-close:hover {
    opacity: 1;
}

.d2s-alert-close:focus-visible {
    outline: 2px solid #029e9c;
    outline: 2px solid var(--d2s-brand-primary, #029e9c);
    outline-offset: 2px;
    border-radius: 2px;
}

.d2s-alert-action:focus-visible {
    outline: 2px solid #029e9c;
    outline: 2px solid var(--d2s-brand-primary, #029e9c);
    outline-offset: 2px;
    border-radius: 2px;
}

/* Alert variants */
.d2s-alert--info {
    background-color: #d1ecf1;
    background-color: var(--d2s-info-bg, #d1ecf1);
    color: #0c5460;
    color: var(--d2s-info, #0c5460);
    border-color: #bee5eb;
}

.d2s-alert--info .d2s-alert-action {
    color: #0c5460;
    color: var(--d2s-info, #0c5460);
}

.d2s-alert--warning {
    background-color: #fff3cd;
    background-color: var(--d2s-warning-bg, #fff3cd);
    color: #856404;
    color: var(--d2s-warning-text, #856404);
    border-color: #ffeeba;
}

.d2s-alert--warning .d2s-alert-action {
    color: #856404;
    color: var(--d2s-warning-text, #856404);
}

.d2s-alert--error {
    background-color: #f8d7da;
    background-color: var(--d2s-error-bg, #f8d7da);
    color: #721c24;
    color: var(--d2s-error-text, #721c24);
    border-color: #f5c6cb;
}

.d2s-alert--error .d2s-alert-action {
    color: #721c24;
    color: var(--d2s-error-text, #721c24);
}

/* Responsive alert */
@media (max-width: 480px) {
    .d2s-alert-banner {
        -ms-flex-wrap: wrap;
        flex-wrap: wrap;
    }

    .d2s-alert-action {
        width: 100%;
        margin-left: 0;
        margin-top: 8px;
        margin-top: var(--d2s-space-sm, 8px);
    }
}

/* ===== Doli2Shop Design System (v2.2.0 - Story 5.1) ===== */

/* --- Design Tokens --- */
:root {
    /* Semantic colors */
    --d2s-success: #28a745;
    --d2s-success-bg: #d4edda;
    --d2s-success-text: #155724;
    --d2s-error: #dc3545;
    --d2s-error-bg: #f8d7da;
    --d2s-error-text: #721c24;
    --d2s-warning: #ff9800;
    --d2s-warning-text: #856404;
    --d2s-warning-bg: #fff3cd;
    --d2s-info: #0c5460;
    --d2s-info-bg: #d1ecf1;
    --d2s-muted: #6c757d;

    /* Brand */
    --d2s-brand-primary: #029e9c;
    /* Variante TEXTE du turquoise de charte. --d2s-brand-primary est une couleur d'APLAT
       (fonds, bordures, anneaux de focus) : posee sur du texte elle ne vaut que 3,29:1 sur
       blanc, sous le seuil WCAG de 4,5:1. Cette variante conserve la teinte et atteint
       6,34:1. Regle de charte du 17/09/2026. */
    --d2s-brand-primary-text: #026B6A;
    --d2s-brand-primary-light: #e0f5f5;
    --d2s-brand-primary-dark: #027a78;

    /* Spacing */
    --d2s-space-xs: 4px;
    --d2s-space-sm: 8px;
    --d2s-space-md: 16px;
    --d2s-space-lg: 24px;
    --d2s-space-xl: 32px;

    /* Borders */
    --d2s-radius: 4px;
    --d2s-border: 1px solid var(--colortopbordertitle1, #dee2e6);

    /* Wizard & interaction tokens (Story 5.2) */
    --d2s-step-size: 36px;
    --d2s-step-line-height: 2px;
    --d2s-progress-height: 20px;
    --d2s-transition-speed: 0.3s;

    /* Dolibarr-inherited tokens */
    --d2s-bg-page: var(--colorbackbody, #f4f5f7);
    --d2s-bg-card: var(--colorbacklineimpair1, #ffffff);
    --d2s-text: var(--colortext, #333333);
    --d2s-text-muted: #666666;
}

.d2s-health-widget {
    margin-bottom: 16px;
    margin-bottom: var(--d2s-space-md, 16px);
}

.d2s-health-indicator {
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-align: center;
    -ms-flex-align: center;
    align-items: center;
    padding: 10px 16px;
    border-radius: 6px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    text-decoration: none;
    color: inherit;
    margin-bottom: 12px;
}

a.d2s-health-indicator:hover {
    background: #e9ecef;
    text-decoration: none;
    color: inherit;
}

.d2s-health-pill {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 10px;
    -ms-flex-negative: 0;
    flex-shrink: 0;
}

.d2s-health-indicator--green .d2s-health-pill {
    background-color: #28a745;
    background-color: var(--d2s-success, #28a745);
}

.d2s-health-indicator--orange .d2s-health-pill {
    background-color: #ff9800;
    background-color: var(--d2s-warning, #ff9800);
}

.d2s-health-indicator--red .d2s-health-pill {
    background-color: #dc3545;
    background-color: var(--d2s-error, #dc3545);
}

.d2s-health-text {
    font-weight: 600;
    font-size: 14px;
    margin-right: 12px;
}

.d2s-health-sync {
    font-size: 12px;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
    margin-left: auto;
}

.d2s-kpi-row {
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -ms-flex-wrap: wrap;
    flex-wrap: wrap;
    margin: 0 -6px;
}

.d2s-kpi-card {
    -webkit-box-flex: 1;
    -ms-flex: 1 1 0px;
    flex: 1 1 0;
    min-width: 140px;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px 16px;
    margin: 0 6px 12px;
    text-align: center;
}

.d2s-kpi-icon {
    display: block;
    font-size: 18px;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
    margin-bottom: 6px;
}

.d2s-kpi-value {
    display: block;
    font-size: 2em;
    font-weight: 700;
    line-height: 1.2;
    color: #212529;
}

.d2s-kpi-label {
    display: block;
    font-size: 12px;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
    margin-top: 2px;
}

.d2s-kpi-card--error {
    border-color: #dc3545;
    border-color: var(--d2s-error, #dc3545);
    background: #fff5f5;
}

.d2s-kpi-card--error .d2s-kpi-icon,
.d2s-kpi-card--error .d2s-kpi-value {
    color: #dc3545;
    color: var(--d2s-error, #dc3545);
}

@media (max-width: 768px) {
    .d2s-kpi-card {
        -ms-flex: 1 1 calc(50% - 12px);
        flex: 1 1 calc(50% - 12px);
        min-width: calc(50% - 12px);
    }
}

@media (max-width: 480px) {
    .d2s-kpi-card {
        -ms-flex: 1 1 100%;
        flex: 1 1 100%;
        min-width: 100%;
    }

    .d2s-health-indicator {
        -ms-flex-wrap: wrap;
        flex-wrap: wrap;
    }

    .d2s-health-sync {
        width: 100%;
        margin-left: 22px;
        margin-top: 4px;
    }
}

/* ===== Wizard Stepper (v2.2.0 - Story 5.2, AC #1) ===== */

.d2s-wizard-stepper {
    margin-bottom: 24px;
    margin-bottom: var(--d2s-space-lg, 24px);
}

.d2s-wizard-steps {
    list-style: none;
    padding: 0;
    margin: 0;
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-pack: justify;
    -ms-flex-pack: justify;
    justify-content: space-between;
}

.d2s-step {
    position: relative;
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-orient: vertical;
    -webkit-box-direction: normal;
    -ms-flex-direction: column;
    flex-direction: column;
    -webkit-box-align: center;
    -ms-flex-align: center;
    align-items: center;
    -webkit-box-flex: 1;
    -ms-flex: 1 1 0px;
    flex: 1 1 0;
    text-align: center;
}

/* Connection line between steps */
.d2s-step + .d2s-step::before {
    content: "";
    position: absolute;
    top: 18px;
    top: calc(36px / 2);
    right: 50%;
    left: calc(-50% + 18px);
    height: 2px;
    height: var(--d2s-step-line-height, 2px);
    background: #dee2e6;
    z-index: 0;
}

.d2s-step--done + .d2s-step::before,
.d2s-step--active + .d2s-step::before {
    background: #029e9c;
    background: var(--d2s-brand-primary, #029e9c);
}

/* Step circle */
.d2s-step-circle {
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-align: center;
    -ms-flex-align: center;
    align-items: center;
    -webkit-box-pack: center;
    -ms-flex-pack: center;
    justify-content: center;
    width: 36px;
    width: var(--d2s-step-size, 36px);
    height: 36px;
    height: var(--d2s-step-size, 36px);
    border-radius: 50%;
    border: 2px solid #dee2e6;
    background: #fff;
    font-weight: 700;
    font-size: 14px;
    position: relative;
    z-index: 1;
    -webkit-transition: border-color 0.3s ease, background-color 0.3s ease, color 0.3s ease;
    transition: border-color 0.3s ease, background-color 0.3s ease, color 0.3s ease;
    transition: border-color var(--d2s-transition-speed, 0.3s) ease, background-color var(--d2s-transition-speed, 0.3s) ease, color var(--d2s-transition-speed, 0.3s) ease;
}

.d2s-step-circle:focus-visible {
    outline: 2px solid #029e9c;
    outline: 2px solid var(--d2s-brand-primary, #029e9c);
    outline-offset: 2px;
}

/* Step states */
.d2s-step--pending .d2s-step-circle {
    border-color: #dee2e6;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
}

.d2s-step--active .d2s-step-circle {
    border-color: #029e9c;
    border-color: var(--d2s-brand-primary, #029e9c);
    background: #029e9c;
    background: var(--d2s-brand-primary, #029e9c);
    color: #fff;
}

.d2s-step--done .d2s-step-circle {
    border-color: #28a745;
    border-color: var(--d2s-success, #28a745);
    background: #28a745;
    background: var(--d2s-success, #28a745);
    color: #fff;
}

/* Step title */
.d2s-step-title {
    display: block;
    margin-top: 8px;
    margin-top: var(--d2s-space-sm, 8px);
    font-size: 12px;
    color: #6c757d;
    color: var(--d2s-muted, #6c757d);
}

.d2s-step--active .d2s-step-title {
    color: #026B6A;
    color: var(--d2s-brand-primary-text, #026B6A);
    font-weight: 600;
}

.d2s-step--done .d2s-step-title {
    color: #155724;
    color: var(--d2s-success-text, #155724);
}

/* Responsive: hide titles on small screens */
@media (max-width: 480px) {
    .d2s-step-title {
        display: none;
    }
}

/* ===== Progress Bar (v2.2.0 - Story 5.2, AC #2) ===== */

.d2s-progress-wrapper {
    margin-bottom: 16px;
    margin-bottom: var(--d2s-space-md, 16px);
}

.d2s-progress-text {
    display: block;
    font-size: 13px;
    margin-bottom: 6px;
    color: #333333;
    color: var(--d2s-text, #333333);
}

.d2s-progress {
    width: 100%;
    height: 20px;
    height: var(--d2s-progress-height, 20px);
    background: #e9ecef;
    border-radius: 4px;
    border-radius: var(--d2s-radius, 4px);
    overflow: hidden;
}

.d2s-progress-fill {
    height: 100%;
    background: #029e9c;
    background: var(--d2s-brand-primary, #029e9c);
    border-radius: 4px;
    border-radius: var(--d2s-radius, 4px);
    -webkit-transition: width 0.3s ease;
    transition: width 0.3s ease;
    transition: width var(--d2s-transition-speed, 0.3s) ease;
    min-width: 0;
}

/* ===== Collapsible (v2.2.0 - Story 5.2, AC #3) ===== */

.d2s-collapsible {
    border: 1px solid #dee2e6;
    border-radius: 4px;
    border-radius: var(--d2s-radius, 4px);
    margin-bottom: 8px;
    margin-bottom: var(--d2s-space-sm, 8px);
}

.d2s-collapsible-header {
    display: -webkit-box;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-align: center;
    -ms-flex-align: center;
    align-items: center;
    padding: 10px 16px;
    cursor: pointer;
    font-weight: 600;
    color: #333333;
    color: var(--d2s-text, #333333);
    list-style: none;
}

/* Hide native markers */
.d2s-collapsible-header::-webkit-details-marker {
    display: none;
}

.d2s-collapsible-header::marker {
    display: none;
    content: "";
}

.d2s-collapsible-header:focus-visible {
    outline: 2px solid #029e9c;
    outline: 2px solid var(--d2s-brand-primary, #029e9c);
    outline-offset: -2px;
    border-radius: 4px;
    border-radius: var(--d2s-radius, 4px);
}

/* Chevron icon */
.d2s-collapsible-icon {
    display: inline-block;
    margin-right: 8px;
    -webkit-transition: -webkit-transform 0.3s ease;
    transition: transform 0.3s ease;
    transition: transform var(--d2s-transition-speed, 0.3s) ease;
    font-size: 0.8em;
}

.d2s-collapsible[open] .d2s-collapsible-icon {
    -webkit-transform: rotate(90deg);
    -ms-transform: rotate(90deg);
    transform: rotate(90deg);
}

/* Collapsible body */
.d2s-collapsible-body {
    padding: 0 16px 12px;
    color: #333333;
    color: var(--d2s-text, #333333);
}
