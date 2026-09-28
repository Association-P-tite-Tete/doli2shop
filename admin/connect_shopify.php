<?php
/**
 * @file        admin/connect_shopify.php
 * @brief       OAuth connection page - initiates Shopify OAuth flow
 *
 * This page displays a "Connect to Shopify" button that initiates the OAuth
 * flow via the central proxy at doli2shop.ptitetete.org.
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.org>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.6
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists(__DIR__."/../main.inc.php")) {
    $res = @include __DIR__."/../main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../main.inc.php")) {
    $res = @include __DIR__."/../../main.inc.php";
}
if (!$res && file_exists(__DIR__."/../../../main.inc.php")) {
    $res = @include __DIR__."/../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Load required libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/doli2shop/lib/doli2shop.lib.php');
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Security check
if (!$user->admin) {
    accessforbidden();
}

// Load translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// ===========================================
// CONFIGURATION
// ===========================================

// OAuth Proxy URL
$oauth_proxy_url = 'https://doli2shop.ptitetete.org/oauth/init.php';

// Story 34.6 — Le HMAC d'init (jamais vérifié côté proxy) et son secret partagé prévisible
// sont supprimés. La sécurité du flux repose sur la signature ASYMÉTRIQUE du RETOUR
// (clé publique du proxy, cf. oauth_receive.php).

// ===========================================
// ACTION: INITIATE OAUTH
// ===========================================

$action = GETPOST('action', 'aZ09');

if ($action == 'connect') {
    // Verify CSRF token — verifToken() standard Dolibarr (Story 7.3 AC1)
    if (!verifToken()) {
        setEventMessages($langs->trans("InvalidToken"), null, 'errors');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Check HTTPS requirement (Shopify requires HTTPS for OAuth callback)
    $parsed_root = parse_url($dolibarr_main_url_root);
    $is_localhost = in_array($parsed_root['host'] ?? '', ['localhost', '127.0.0.1', '::1']);
    if (!$is_localhost && ($parsed_root['scheme'] ?? '') !== 'https') {
        dol_syslog("Doli2Shop OAuth: BLOCKED - Dolibarr URL uses HTTP instead of HTTPS: $dolibarr_main_url_root", LOG_ERR);
        setEventMessages($langs->trans("OAuthHttpsRequired"), null, 'errors');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Generate secure state parameter
    $state = bin2hex(random_bytes(32));

    // Store state in session for verification on return
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_initiated'] = time();

    // Story 48-6 : contexte boutique pour router le callback OAuth (credentials = OAuth uniquement).
    // '' = boutique par défaut (bouton principal) ; 'new' = nouvelle boutique ; 'reconnect:<id>' = reconnexion.
    $storeContextParam = GETPOST('store_context', 'aZ09');
    $ctxStoreId = (int) GETPOST('store_id', 'int');
    if ($storeContextParam === 'reconnect' && $ctxStoreId > 0) {
        $_SESSION['oauth_store_context'] = 'reconnect:' . $ctxStoreId;
    } elseif ($storeContextParam === 'new') {
        $_SESSION['oauth_store_context'] = 'new';
    } else {
        $_SESSION['oauth_store_context'] = '';
    }

    // Store redirect origin for post-OAuth routing (Story 6.1 — Task 3.1)
    $redirect = GETPOST('redirect', 'aZ09');
    if (!empty($redirect)) {
        $_SESSION['oauth_redirect'] = $redirect;
    }

    // Build the return URL (where the proxy will redirect after OAuth)
    $return_url = $dolibarr_main_url_root . '/custom/doli2shop/admin/oauth_receive.php';

    // Build proxy URL (plus de hmac d'init — sécurité portée par la signature du retour)
    // FIX CRITIQUE (review 51-1) — signal de capacité : ce module (>= 2.4.1) sait rafraîchir
    // un token expirable (ShopifyApi::ensureFreshToken()). Le proxy ne doit demander
    // `expiring=1` à Shopify QUE si le module qui reçoit le retour est capable de gérer le
    // refresh — sinon un module ancien (< 2.4.1) recevrait un token expirable après 1h sans
    // aucun mécanisme de refresh, tuant la synchronisation de la boutique. Couvre les DEUX
    // chemins (connexion initiale ET reconnexion 'reconnect:<id>'/'new', cf. $_SESSION
    // ci-dessus) puisqu'ils partagent ce même unique point de construction de l'URL proxy.
    $proxy_params = [
        'state' => $state,
        'returnurl' => $return_url,
        'client_capability' => 'token_refresh_v1'
    ];

    $redirect_url = $oauth_proxy_url . '?' . http_build_query($proxy_params);

    // Log the initiation
    dol_syslog("Doli2Shop OAuth: Initiating connection to Shopify via proxy", LOG_INFO);
    dol_syslog("Doli2Shop OAuth: Return URL = $return_url", LOG_DEBUG);

    // Redirect to proxy
    header('Location: ' . $redirect_url);
    exit;
}

// ===========================================
// VIEW
// ===========================================

$page_name = $langs->trans("ConnectToShopify");
llxHeader('', $page_name);

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Title
print load_fiche_titre($page_name, '', 'title_setup');

// Check if already connected
$is_connected = !empty(getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME')) && !empty(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN'));

if ($is_connected) {
    $shop = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME');

    print '<div class="info">';
    print '✅ ' . $langs->trans("AlreadyConnectedToShopify", '<strong>' . dol_escape_htmltag($shop) . '</strong>');
    print '</div>';
    print '<br>';

    // Option to reconnect
    print '<div class="warning">';
    print '⚠️ ' . $langs->trans("ReconnectWarning");
    print '</div>';
    print '<br>';
}

// Connection box
print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("ShopifyOAuthConnection") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td colspan="2">';

// Info text
print '<div style="padding: 20px; text-align: center;">';

print '<div style="margin-bottom: 20px;">';
print '<span style="font-size: 48px;">🔌</span>';
print '</div>';

print '<h3>' . $langs->trans("ConnectYourShopifyStore") . '</h3>';

print '<p style="color: #666; max-width: 500px; margin: 0 auto 20px;">';
print $langs->trans("OAuthConnectionDescription");
print '</p>';

// Benefits list
print '<ul style="text-align: left; max-width: 400px; margin: 0 auto 20px; color: #555;">';
print '<li>✅ ' . $langs->trans("OAuthBenefit1") . '</li>';
print '<li>✅ ' . $langs->trans("OAuthBenefit2") . '</li>';
print '<li>✅ ' . $langs->trans("OAuthBenefit3") . '</li>';
print '</ul>';

// Connect button
print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="connect">';

// Hotfix 2.5.0 (Nicolas Graillon, 3 boutiques) : cette page peut être atteinte par un lien GET
// portant store_context=reconnect&store_id=N (bouton "Reconnecter via OAuth" d'admin/setup.php
// sur une boutique SECONDAIRE, cf. setup.php ~l.1211-1213). Sans repropagation, ce formulaire
// POST perdait le contexte au clic suivant : oauth_receive.php retombait alors sur la résolution
// "bouton principal" et écrivait les credentials de la boutique reconnectée sur la boutique PAR
// DÉFAUT (cause du "Duplicate entry ... uk_doli2shop_stores_domain" — la contrainte unique a
// bloqué l'écriture, mais l'aurait sinon faite silencieusement). On relit le contexte reçu en GET
// et on le reporte en champs cachés — n'émettre les champs que s'ils sont effectivement renseignés
// pour ne rien changer au comportement historique (bouton principal, sans paramètres).
$connectStoreContext = GETPOST('store_context', 'aZ09');
$connectStoreId = GETPOSTINT('store_id');

// MEDIUM-1 (review post-hotfix) : store_context=reconnect SANS store_id valide (lien obsolète,
// favori, URL retapée) ne doit JAMAIS être reproposé seul — connect_shopify.php (l.95-103, action=
// connect) exige les DEUX pour router en reconnexion ; un store_context='reconnect' orphelin y
// retomberait silencieusement sur '' (bouton principal), exactement la résolution que ce hotfix
// cherche à éviter. On n'émet ni l'un ni l'autre : le formulaire redevient EXPLICITEMENT le bouton
// principal plutôt que de laisser un contexte orphelin le faire IMPLICITEMENT au POST suivant.
if ($connectStoreContext === 'reconnect' && $connectStoreId <= 0) {
    dol_syslog(
        "Doli2Shop OAuth: contexte de reconnexion perdu à l'affichage de connect_shopify.php"
        . " (store_context=reconnect sans store_id valide) — repli explicite sur le bouton principal",
        LOG_WARNING
    );
    $connectStoreContext = '';
    $connectStoreId = 0;
}

if ($connectStoreContext !== '') {
    print '<input type="hidden" name="store_context" value="' . dol_escape_htmltag($connectStoreContext) . '">';
}
if ($connectStoreId > 0) {
    print '<input type="hidden" name="store_id" value="' . $connectStoreId . '">';
}

$button_text = $is_connected ? $langs->trans("ReconnectToShopify") : $langs->trans("ConnectToShopify");

print '<input type="submit" class="butAction" value="' . dol_escape_htmltag($button_text) . '" />';

print '</form>';

// Multi-store warning
print '<div class="warning" style="margin-top: 15px; text-align: left;">';
print $langs->trans("OAuthMultiStoreWarning");
print '</div>';

print '</div>';

print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '</div>'; // fichethirdleft

// Right column - How it works
print '<div class="fichetwothirdright">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("HowItWorks") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>';

print '<div style="padding: 15px;">';

print '<ol style="line-height: 2;">';
print '<li>' . $langs->trans("OAuthStep1") . '</li>';
print '<li>' . $langs->trans("OAuthStep2") . '</li>';
print '<li>' . $langs->trans("OAuthStep3") . '</li>';
print '<li>' . $langs->trans("OAuthStep4") . '</li>';
print '</ol>';

print '<div class="info" style="margin-top: 15px;">';
print '🔒 ' . $langs->trans("OAuthSecurityNote");
print '</div>';

print '</div>';

print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '</div>'; // fichetwothirdright

print '</div>'; // fichecenter


llxFooter();
$db->close();

/**
 * Verify CSRF token using Dolibarr standard method
 *
 * @return bool True if token is valid
 */
// Custom verifyCsrfToken() removed in Story 7.3 — use Dolibarr's native verifToken() instead
