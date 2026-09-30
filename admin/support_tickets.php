<?php
/**
 * @file        admin/support_tickets.php
 * @brief       Interface de gestion des tickets support depuis Dolibarr
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.1.8
 * @link        http://www.dolibarr.org
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

// Libraries
dol_include_once('/core/lib/admin.lib.php');
require_once '../lib/doli2shop.lib.php';
require_once '../lib/version.lib.php';
require_once '../lib/compatibility.lib.php';
require_once '../class/modulelogfile.class.php';

// Load translation files
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

// Get action
$action = GETPOST('action', 'aZ09');

// v2.1.8 - Vérifier le statut de licence pour le mode dégradé
$licenseStatus = doli2shopGetLicenseStatus();
$canAccessSupport = !empty($licenseStatus['can_support']);

// Récupérer les infos de licence pour identifier le client
$licenseEmail = getDolGlobalString('DOLI2SHOP_LICENSE_EMAIL', $licenseStatus['customer_email'] ?? '');
$serialNumber = getDolGlobalString('DOLI2SHOP_SERIAL_NUMBER', $licenseStatus['serial_number'] ?? '');
$websiteUrl = getDolGlobalString('DOLI2SHOP_WEBSITE_URL', 'https://doli2shop.ptitetete.org');

// Messages
$error = '';
$success = '';

/*
 * Actions
 */

// Bloquer la création de ticket si licence expirée (v2.1.8)
if ($action === 'create' && !$canAccessSupport) {
    $error = $langs->trans("LicenseExpiredNoSupport");
    $action = '';
}

// CSRF validation on all write actions (Story 7.3 AC1)
if (in_array($action, array('create', 'reply')) && !verifToken()) {
    setEventMessages($langs->trans("ErrorTokenCSRF"), null, 'errors');
    $action = '';
}

if ($action === 'create' && !empty($licenseEmail) && $canAccessSupport) {
    $subject = GETPOST('subject', 'alphanohtml');
    $category = GETPOST('category', 'alpha');
    $priority = GETPOST('priority', 'alpha');
    $message = GETPOST('message', 'restricthtml');

    if (empty($subject)) {
        $error = $langs->trans("ErrorFieldRequired", $langs->trans("Subject"));
    } elseif (empty($message)) {
        $error = $langs->trans("ErrorFieldRequired", $langs->trans("Message"));
    } else {
        // Story 50-9 : télémétrie résolue une seule fois avant construction du payload.
        $ticketTelemetry = doli2shopBuildTelemetry();

        // Préparer les données
        $ticketData = array(
            'customer_email' => $licenseEmail,
            'serial_number' => $serialNumber,
            'subject' => $subject,
            'category' => $category ?: 'technical',
            'priority' => $priority ?: 'medium',
            'message' => $message,
            'source' => 'dolibarr_module',
            // Story 50-9 : guard defined(DOL_VERSION) (absent avant) ; 'server' ne contient plus
            // jamais de sentinelle générique — valeur résolue via doli2shopBuildTelemetry()
            // ou clé omise. Noms de clés ('source_metadata', 'server', 'dolibarr_version'...)
            // inchangés : contrat de /api/support/tickets côté website non renommé (cf. Dev
            // Notes story 50-9).
            'source_metadata' => array_merge(
                array(
                    'module_version' => DOLI2SHOP_MODULE_VERSION,
                    'php_version' => PHP_VERSION,
                ),
                defined('DOL_VERSION') && DOL_VERSION !== '' ? array('dolibarr_version' => DOL_VERSION) : array(),
                isset($ticketTelemetry['dolibarr_domain']) ? array('server' => $ticketTelemetry['dolibarr_domain']) : array()
            )
        );

        // Appeler l'API
        $result = callSupportApi('POST', '/api/support/tickets', $ticketData);

        if ($result && isset($result['success']) && $result['success']) {
            $ticketNum = isset($result['data']['ticket_number']) ? $result['data']['ticket_number'] : '';
            $success = $langs->trans("TicketCreatedSuccess", $ticketNum);
        } else {
            $errorMsg = isset($result['error']) ? $result['error'] : 'Unknown error';
            $error = $langs->trans("TicketCreationFailed") . ': ' . $errorMsg;
        }
    }
}

// Ajouter une réponse
if ($action === 'reply' && !empty($licenseEmail)) {
    $ticketId = GETPOST('ticket_id', 'int');
    $message = GETPOST('reply_message', 'restricthtml');

    if (empty($message)) {
        $error = $langs->trans("ErrorFieldRequired", $langs->trans("Message"));
    } else {
        $replyData = array(
            'message' => $message,
            'author_email' => $licenseEmail,
        );

        $result = callSupportApi('POST', '/api/support/tickets/' . $ticketId . '/reply', $replyData);

        if ($result && isset($result['success']) && $result['success']) {
            $success = $langs->trans("ReplyAddedSuccess");
        } else {
            $errorMsg = isset($result['error']) ? $result['error'] : 'Unknown error';
            $error = $langs->trans("ReplyFailed") . ': ' . $errorMsg;
        }
    }
}

/*
 * View
 */

$page_name = "SupportTickets";
llxHeader('', $langs->trans($page_name));

// Header
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("SupportTickets"), $linkback, 'title_setup');

// Onglets
// Story dix-ecrans-atteignables-par-aucun-lien (Validate 09/09/2026) : cette page usurpait
// jusqu'ici la clé d'onglet 'health' faute d'onglet propre — elle possède désormais la sienne
// ('supporttickets', enregistrée dans doli2shopAdminPrepareHead()).
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'supporttickets', $langs->trans("Doli2Shop"), -1, 'doli2shop@doli2shop');
// Pas de barre de contexte : tickets support non filtrés par boutique (le sélecteur n'aurait aucun effet).

// v2.1.8 - Afficher le bandeau d'avertissement si licence expirée (mode dégradé)
doli2shopShowLicenseWarningBanner();

// Journal du module — placé EN TÊTE de l'écran support, avant la liste des tickets : c'est ici
// que le client arrive quand quelque chose ne va pas, et le premier geste utile est de joindre
// son journal. Sans ce lien, l'échange commence par « pouvez-vous m'envoyer vos logs ? », suivi
// d'explications sur où les trouver — et souvent d'un fichier trop volumineux pour la messagerie.
if (ModuleLogFile::isEnabled()) {
    print '<div class="d2s-logfile-hint" style="margin-bottom:16px; padding:12px 16px; border:1px solid var(--colortopbordertitle, #ddd); border-radius:6px;">';
    print '<strong>' . dol_escape_htmltag($langs->trans("ModuleLogFileSupportTitle")) . '</strong><br>';
    print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans("ModuleLogFileSupportHelp", dol_print_size(ModuleLogFile::totalSize(), 1, 1))) . '</span><br>';
    print '<form method="POST" action="' . dol_escape_htmltag(dol_buildpath('/doli2shop/admin/action_log.php', 1)) . '" style="margin-top:8px;">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="download_logfile">';
    print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("ModuleLogFileDownload")) . '">';
    print '</form>';
    print '</div>';
}

// CSS personnalisé
print '<style>
.tickets-container { max-width: 1000px; }
.ticket-card { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 16px; transition: box-shadow 0.2s; }
.ticket-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.ticket-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
.ticket-number { font-family: monospace; font-size: 14px; color: #026B6A; font-weight: 600; }
.ticket-subject { font-size: 16px; font-weight: 600; color: #333; margin: 8px 0; }
.ticket-meta { display: flex; gap: 16px; font-size: 13px; color: #666; }
.badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; }
.badge-status-open { background: #d4edda; color: #155724; }
.badge-status-in_progress { background: #fff3cd; color: #856404; }
.badge-status-waiting_customer { background: #cce5ff; color: #004085; }
.badge-status-resolved { background: #d4edda; color: #155724; }
.badge-status-closed { background: #e2e3e5; color: #383d41; }
.badge-priority-low { background: #e2e3e5; color: #383d41; }
.badge-priority-medium { background: #fff3cd; color: #856404; }
.badge-priority-high { background: #f8d7da; color: #721c24; }
.badge-priority-urgent { background: #721c24; color: #fff; }
.new-ticket-form { background: #f8f9fa; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
.new-ticket-form h3 { margin: 0 0 20px 0; font-size: 18px; color: #333; }
.form-row { display: flex; gap: 16px; margin-bottom: 16px; }
.form-row .form-group { flex: 1; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-group textarea { min-height: 120px; resize: vertical; }
.btn-primary { background: #029e9c; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
.btn-primary:hover { background: #028584; }
.btn-secondary { background: #6c757d; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; }
.btn-secondary:hover { background: #5a6268; }
.no-license-warning { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 24px; }
.empty-state { text-align: center; padding: 40px; color: #666; }
.empty-state .icon { font-size: 48px; margin-bottom: 16px; }
.message-list { margin-top: 16px; border-top: 1px solid #dee2e6; padding-top: 16px; }
</style>';

// Afficher les messages
if ($error) {
    print '<div class="error">' . $error . '</div>';
}
if ($success) {
    print '<div class="ok">' . $success . '</div>';
}

// Vérifier si une licence est configurée
if (empty($licenseEmail)) {
    print '<div class="no-license-warning">';
    print '<p><strong>' . $langs->trans("NoLicenseConfigured") . '</strong></p>';
    print '<p>' . $langs->trans("ConfigureLicenseFirst") . '</p>';
    print '<a href="shopify_license.php" class="btn-primary">' . $langs->trans("ConfigureLicense") . '</a>';
    print '</div>';
} else {
    print '<div class="tickets-container">';

    // Formulaire de création de ticket (v2.1.8 - désactivé si licence expirée)
    print '<div class="new-ticket-form">';
    print '<h3>&#127915; ' . $langs->trans("CreateNewTicket") . '</h3>';

    // v2.1.8 - Vérifier si le support est accessible
    if (!$canAccessSupport) {
        print '<div class="warning" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-bottom: 15px;">';
        print '<strong>⚠️ ' . $langs->trans("LicenseExpiredWarning") . '</strong><br>';
        // AC9 : ne promettre la poursuite de la synchronisation que si elle a reellement lieu.
        // LicenseExpiredNoSupportDescription dit « la synchronisation continue de fonctionner
        // (mode degrade) » — vrai tant qu'aucun gate n'est actif, faux ensuite.
        // ⚠️ Deux pieges evites ici, tous deux rencontres le 13/09 :
        //  - doli2shopStoreSyncAllowed(null) renvoie TOUJOURS allowed=true (chemin sans contexte
        //    de boutique) : le message ne basculerait jamais ;
        //  - $licenseStatus['can_sync'] est code en dur a `true` cote serveur : idem.
        // On lit donc la date d'arret annoncee, comme le bandeau partage.
        $syncGateReached = doli2shopGetSyncStopDeadline($licenseStatus);
        $syncHasStopped  = ($syncGateReached !== null && time() >= $syncGateReached);
        print $langs->trans(
            $syncHasStopped ? "SyncStoppedByLicenseDescription" : "LicenseExpiredNoSupportDescription"
        );
        print '</div>';
    }

    print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="create">';

    $disabledAttr = $canAccessSupport ? '' : ' disabled';

    print '<div class="form-group">';
    print '<label for="subject">' . $langs->trans("Subject") . ' *</label>';
    print '<input type="text" id="subject" name="subject" required placeholder="' . $langs->trans("DescribeProblemBriefly") . '"' . $disabledAttr . '>';
    print '</div>';

    print '<div class="form-row">';
    print '<div class="form-group">';
    print '<label for="category">' . $langs->trans("Category") . '</label>';
    print '<select id="category" name="category"' . $disabledAttr . '>';
    print '<option value="technical">' . $langs->trans("CategoryTechnical") . '</option>';
    print '<option value="billing">' . $langs->trans("CategoryBilling") . '</option>';
    print '<option value="feature_request">' . $langs->trans("CategoryFeatureRequest") . '</option>';
    print '<option value="bug">' . $langs->trans("CategoryBug") . '</option>';
    print '<option value="other">' . $langs->trans("CategoryOther") . '</option>';
    print '</select>';
    print '</div>';

    print '<div class="form-group">';
    print '<label for="priority">' . $langs->trans("Priority") . '</label>';
    print '<select id="priority" name="priority"' . $disabledAttr . '>';
    print '<option value="low">' . $langs->trans("PriorityLow") . '</option>';
    print '<option value="medium" selected>' . $langs->trans("PriorityMedium") . '</option>';
    print '<option value="high">' . $langs->trans("PriorityHigh") . '</option>';
    print '<option value="urgent">' . $langs->trans("PriorityUrgent") . '</option>';
    print '</select>';
    print '</div>';
    print '</div>';

    print '<div class="form-group">';
    print '<label for="message">' . $langs->trans("Message") . ' *</label>';
    print '<textarea id="message" name="message" required placeholder="' . $langs->trans("DescribeProblemDetail") . '"' . $disabledAttr . '></textarea>';
    print '</div>';

    print '<button type="submit" class="btn-primary"' . $disabledAttr . '>' . $langs->trans("CreateTicket") . '</button>';
    print '</form>';
    print '</div>';

    // Récupérer et afficher les tickets existants
    print '<h3 style="margin-bottom:16px;">' . $langs->trans("MyTickets") . '</h3>';

    $tickets = callSupportApi('GET', '/api/support/tickets?email=' . urlencode($licenseEmail));

    if ($tickets && isset($tickets['data']) && !empty($tickets['data'])) {
        foreach ($tickets['data'] as $ticket) {
            print '<div class="ticket-card">';
            print '<div class="ticket-header">';
            print '<div>';
            print '<span class="ticket-number">' . htmlspecialchars($ticket['ticket_number'], ENT_QUOTES, 'UTF-8') . '</span>';
            print '<div class="ticket-subject">' . htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8') . '</div>';
            print '</div>';
            print '<div>';
            print '<span class="badge badge-status-' . htmlspecialchars($ticket['status'], ENT_QUOTES, 'UTF-8') . '">' . $langs->trans('TicketStatus' . ucfirst($ticket['status'])) . '</span> ';
            print '<span class="badge badge-priority-' . htmlspecialchars($ticket['priority'], ENT_QUOTES, 'UTF-8') . '">' . $langs->trans('Priority' . ucfirst($ticket['priority'])) . '</span>';
            print '</div>';
            print '</div>';

            print '<div class="ticket-meta">';
            print '<span>' . $langs->trans("Created") . ': ' . dol_print_date(strtotime($ticket['created_at']), 'dayhour') . '</span>';
            print '<span>' . $langs->trans("Updated") . ': ' . dol_print_date(strtotime($ticket['updated_at']), 'dayhour') . '</span>';
            print '</div>';

            // Formulaire de réponse si le ticket n'est pas fermé
            if (!in_array($ticket['status'], array('closed', 'resolved'))) {
                print '<div class="message-list">';
                print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
                print '<input type="hidden" name="token" value="' . newToken() . '">';
                print '<input type="hidden" name="action" value="reply">';
                print '<input type="hidden" name="ticket_id" value="' . (int)$ticket['id'] . '">';
                print '<div class="form-group">';
                print '<textarea name="reply_message" rows="3" placeholder="' . $langs->trans("WriteYourReply") . '"></textarea>';
                print '</div>';
                print '<button type="submit" class="btn-secondary">' . $langs->trans("Reply") . '</button>';
                print '</form>';
                print '</div>';
            }

            print '</div>'; // .ticket-card
        }
    } else {
        print '<div class="empty-state">';
        print '<div class="icon">&#128172;</div>';
        print '<p>' . $langs->trans("NoTicketsYet") . '</p>';
        print '<p>' . $langs->trans("CreateFirstTicket") . '</p>';
        print '</div>';
    }

    print '</div>'; // .tickets-container
}

print dol_get_fiche_end();

llxFooter();
$db->close();

/**
 * Appeler l'API support
 *
 * @param string $method HTTP method
 * @param string $endpoint API endpoint
 * @param array $data Data to send
 * @return array|null Response data
 */
function callSupportApi($method, $endpoint, $data = array())
{
    $websiteUrl = getDolGlobalString('DOLI2SHOP_WEBSITE_URL', 'https://doli2shop.ptitetete.org');
    $apiToken = getDolGlobalString('DOLI2SHOP_API_TOKEN', '');

    $url = rtrim($websiteUrl, '/') . $endpoint;

    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
    );

    if ($apiToken) {
        $headers[] = 'X-API-Token: ' . $apiToken;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return array('success' => false, 'error' => 'Connection failed');
    }

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return array('success' => true, 'data' => $result);
    }

    $errorMsg = isset($result['error']) ? $result['error'] : 'API error';
    return array('success' => false, 'error' => $errorMsg, 'data' => $result);
}
