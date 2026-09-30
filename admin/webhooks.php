<?php
/**
 * Webhooks administration page for ShopifyIntegration module
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
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
require_once '../class/webhookmanager.class.php';
require_once '../class/shopifywebhooks.class.php';
require_once '../class/shopifyapi.class.php';
require_once '../class/storeservice.class.php';
require_once '../class/webhooksettingssaver.class.php';

// Load translation files required by the page
$langs->loadLangs(array("admin", "errors", "doli2shop@doli2shop"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

$tab = GETPOST('tab', 'alpha') ? GETPOST('tab', 'alpha') : 'webhooks';
$action = GETPOST('action', 'aZ09');
$value = GETPOST('value', 'alpha');
$webhook_id = GETPOST('webhook_id', 'int');
// Topic peut contenir '/' (ex: orders/create) - alphanohtml permet plus de caractères
$topic = GETPOST('topic', 'alphanohtml');

$error = 0;
// Story 60-1 (AC1/AC2) : défaut sûr si le bloc de sauvegarde n'est pas atteint (même garde que
// $error ci-dessus) — jamais laissé indéfini.
$topicsUnverifiable = 0;

// Contexte boutique (Story 49-9)
$currentAdminStore = doli2shopGetCurrentAdminStore($db);
$currentAdminStoreId = (int) ($currentAdminStore->rowid ?? 0);
// Boutique secondaire = scope API/SQL par boutique ; défaut/null = chemin historique strictement inchangé
$currentAdminStoreIsSecondary = ($currentAdminStore !== null && (int) ($currentAdminStore->is_default ?? 0) === 0);

// Initialize objects
$webhookManager = new WebhookManager($db);
$shopifyWebhooks = $currentAdminStoreIsSecondary ? new ShopifyWebhooks($db, $currentAdminStore) : new ShopifyWebhooks($db);
$shopifyApi = $currentAdminStoreIsSecondary ? ShopifyApi::forStore($db, $currentAdminStore) : new ShopifyApi($db);

/*
 * Actions
 */

// Hotfix 2.4.6 (2/2, AC3) : jusqu'ici, un jeton refusé faisait sauter TOUT le bloc en silence
// (l'utilisateur cliquait « Enregistrer » et rien ne se passait, aucun message, aucune erreur —
// symptôme exact remonté par Nicolas Graillon 2026-08-03). La cause profonde (verifToken()
// comparait au mauvais jeton) est corrigée dans lib/compatibility.lib.php ; ce garde-fou
// affiche désormais un message explicite si l'échec survient malgré tout (session expirée,
// double-soumission, etc.), au lieu de laisser une page muette.
if (($action == 'update') && !empty($_POST) && !verifToken()) {
    // Story 59-2 (AC1) : trace en journal ajoutée en complément du message écran déjà posé par le
    // hotfix 2.4.6 — les deux signaux (écran + journal) sont désormais systématiques sur les 7
    // actions de cette page, pas seulement le message.
    dol_syslog("webhooks.php: action 'update' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if (($action == 'update') && !empty($_POST) && verifToken()) {
    // Story 2.4.7-1b : la boucle d'enregistrement (activation/désactivation par topic +
    // constantes générales, granularité transactionnelle À L'OPÉRATION héritée du hotfix 2.4.7
    // après trois rounds de revue adversariale) est désormais dans class/webhooksettingssaver.class.php
    // — un script procédural avec `exit` sur une branche ne peut structurellement pas être inclus
    // dans PHPUnit, la classe injectable si. Cette page ne garde que la lecture du POST, l'appel à
    // la classe et l'affichage des messages qu'elle restitue (elle n'affiche jamais rien elle-même).
    if ($action == 'update' && GETPOST('save', 'alpha')) {
        $active_topics = GETPOST('active_topics', 'array');

        $webhookSettingsSaver = new WebhookSettingsSaver($db, $shopifyWebhooks);

        $topicsReport = $webhookSettingsSaver->saveTopics(
            $active_topics,
            $currentAdminStoreId,
            $currentAdminStoreIsSecondary,
            $conf->entity,
            $user->login
        );

        // Story 36.3 : politique de retry webhook (bornage fait dans la classe, lecture POST ici)
        $generalSettingsReport = $webhookSettingsSaver->saveGeneralSettings(
            GETPOST("webhook_process_limit", 'int'),
            GETPOST("webhook_keep_days", 'int'),
            GETPOST("webhook_immediate_process", 'int'),
            GETPOSTINT("webhook_max_tries"),
            GETPOSTINT("webhook_stuck_timeout"),
            GETPOST("webhook_user_id", 'int'),
            $conf->entity
        );

        // Rejeu, dans l'ORDRE d'émission d'origine, des messages collectés par la classe — elle ne
        // fait jamais setEventMessages() elle-même, c'est la page qui reste responsable de l'affichage.
        foreach (array_merge($topicsReport['errorMessages'], $generalSettingsReport['errorMessages']) as $eventMessage) {
            setEventMessages($eventMessage['text'], $eventMessage['errors'], $eventMessage['type']);
        }

        $error = ($topicsReport['hadError'] || $generalSettingsReport['hadError']) ? 1 : 0;
        $topicsApplied = $topicsReport['topicsApplied'];
        $topicsFailed = $topicsReport['topicsFailed'];
        // Story 60-1 (AC1/AC2) : topics dont l'état n'a pas pu être vérifié auprès de Shopify —
        // ni un succès plein, ni un échec d'écriture. Leur message par topic est déjà rejoué
        // ci-dessus (errorMessages) ; ce compte pilote uniquement le message GLOBAL ci-dessous,
        // pour ne jamais laisser « Configuration sauvegardée » s'afficher quand une partie du
        // formulaire n'a en réalité pas pu être confirmée.
        $topicsUnverifiable = $topicsReport['topicsUnverifiable'];
    }

    // Les topics ont chacun été committés ou annulés individuellement dans la classe : il ne reste
    // qu'à qualifier le résultat d'ensemble. Un échec partiel n'annule plus les topics réussis,
    // et ne doit donc plus être annoncé comme un succès global.
    // Story 60-1 (points 2/3) : décision extraite dans une fonction PURE testable
    // (doli2shopBuildWebhookSaveMessage(), lib/doli2shop.lib.php) — sur le modèle déjà établi par
    // doli2shopBuildActivateAllWebhooksMessage() pour le bouton "Activer tous les webhooks". Avant
    // cette extraction, cette logique vivait en `if`/`elseif` inline SANS AUCUN test dédié : la
    // mutation consistant à retirer `&& empty($topicsUnverifiable)` — réintroduisant exactement le
    // défaut d'origine de cette story — laissait la suite de tests au vert.
    $saveMessage = doli2shopBuildWebhookSaveMessage($error > 0, $topicsApplied, $topicsFailed, $topicsUnverifiable);
    setEventMessages(
        $langs->trans($saveMessage['langKey'], ...$saveMessage['params']),
        null,
        $saveMessage['messageType']
    );
}

// Story 59-2 (AC1) : jusqu'ici, un jeton refusé sur l'une des 6 actions ci-dessous (toutes sauf
// `update`, déjà corrigée par le hotfix 2.4.6) faisait sauter tout le bloc EN SILENCE — aucun
// message, aucune trace, aucun effet. C'est exactement ce qui est arrivé à Nicolas Graillon sur
// `activate_all_webhooks` (deux clics à 12:39:49 et 12:39:57, aucun des deux n'est entré dans
// l'action). Chaque garde ci-dessous affiche désormais un message explicite ET journalise le
// refus (LOG_WARNING, action nommée + utilisateur) avant que le bloc d'action correspondant ne
// s'exécute plus bas.
if ($action == 'sync_webhooks' && !verifToken()) {
    dol_syslog("webhooks.php: action 'sync_webhooks' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'upgrade_api_version' && !verifToken()) {
    dol_syslog("webhooks.php: action 'upgrade_api_version' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'activate_all_webhooks' && !verifToken()) {
    dol_syslog("webhooks.php: action 'activate_all_webhooks' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'test_webhook' && $webhook_id && !verifToken()) {
    dol_syslog("webhooks.php: action 'test_webhook' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'purge_events' && !verifToken()) {
    dol_syslog("webhooks.php: action 'purge_events' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'export_events_json' && !verifToken()) {
    dol_syslog("webhooks.php: action 'export_events_json' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

// Action synchronisation des webhooks avec Shopify
if ($action == 'sync_webhooks' && verifToken()) {
    dol_syslog("webhooks.php: User " . $user->login . " triggered webhook sync at " . date('Y-m-d H:i:s'), LOG_INFO);
    $result = $shopifyWebhooks->synchronizeWithShopify();
    if ($result > 0) {
        setEventMessages($langs->trans("WebhooksSynchronized", $result), null, 'mesgs');
    } elseif ($result == 0) {
        // Hotfix 2.4.6 (ajout recommandé Validate) : aucun webhook à synchroniser est un cas
        // légitime (installation neuve, boutique sans abonnement Shopify) — sur le modèle de la
        // 3ᵉ branche déjà présente sur upgrade_api_version, pour ne pas la faire tomber en erreur.
        setEventMessages($langs->trans("NoWebhooksToSynchronize"), null, 'warnings');
    } else {
        setEventMessages(null, !empty($shopifyWebhooks->errors) ? $shopifyWebhooks->errors : array($langs->trans("Error")), 'errors');
    }
}

// Action mettre à jour la version API des webhooks
if ($action == 'upgrade_api_version' && verifToken()) {
    dol_syslog("webhooks.php: User " . $user->login . " triggered webhook API version upgrade at " . date('Y-m-d H:i:s'), LOG_INFO);
    $result = $shopifyWebhooks->upgradeWebhooksApiVersion();
    if ($result > 0) {
        setEventMessages($langs->trans("WebhooksApiVersionUpgraded", $result), null, 'mesgs');
    } elseif ($result == 0) {
        setEventMessages($langs->trans("NoWebhooksToUpgrade"), null, 'warnings');
    } else {
        setEventMessages(null, !empty($shopifyWebhooks->errors) ? $shopifyWebhooks->errors : array($langs->trans("Error")), 'errors');
    }
}

// Action activer tous les webhooks (pour installations existantes sans webhooks)
if ($action == 'activate_all_webhooks' && verifToken()) {
    dol_syslog("webhooks.php: User " . $user->login . " triggered activate all webhooks at " . date('Y-m-d H:i:s'), LOG_INFO);
    $supportedTopicsForSync = $shopifyWebhooks->getSupportedTopics();
    // Story 59-9 (correctif review) : action MANUELLE explicite (clic bouton) — `true` garantit que
    // le disjoncteur d'échecs consécutifs (doli2shopShouldSkipDurableRetry()) ne l'ignore JAMAIS,
    // contrairement au CRON périodique (cronCheckWebhookHealth() passe toujours false). Sans cette
    // distinction, un client qui vient de corriger la cause d'un refus durable (portée OAuth
    // accordée, app réinstallée) et reclique verrait sa tentative absorbée sans effet ni message —
    // exactement le symptôme qui a ouvert ce dossier.
    $result = $shopifyWebhooks->syncWebhooks($supportedTopicsForSync, true);
    if ($result) {
        // Story 59-4 (AC3) : nommer la boutique concernée dans le message — le journal du client
        // montre une requête filtrée sur `fk_store IN (0, 1)`, donc CETTE seule boutique (le filtre
        // est intentionnel : l'action agit sur la boutique affichée dans le contexte admin, cf.
        // $currentAdminStore plus haut). Sans ce nommage, deux boutiques entièrement éteintes après
        // un clic sur CE bouton laissaient croire à tort que "tous les webhooks" (toutes boutiques)
        // avaient été activés.
        $storeLabelForActivateAllMessage = ($currentAdminStore !== null && !empty($currentAdminStore->label))
            ? dol_escape_htmltag($currentAdminStore->label)
            : $langs->trans("DefaultStore");
        // Story 59-9 (point 4) : jusqu'ici ce message de succès s'affichait à l'IDENTIQUE, que des
        // topics aient été réellement activés OU refusés durablement/en échec par Shopify — rien ne
        // distinguait "tout est activé" de "un topic reste bloqué, à corriger manuellement". Vérifié
        // sur le code avant correctif : `$result` de syncWebhooks() est TOUJOURS `true` sauf échec dur
        // de listWebhooks(), donc ce cas laissait réellement croire à un succès complet à tort.
        // Décision déléguée à doli2shopBuildActivateAllWebhooksMessage() (fonction pure, testée).
        $activateAllMessage = doli2shopBuildActivateAllWebhooksMessage($shopifyWebhooks->lastSyncDurableRefusalCount, $shopifyWebhooks->lastSyncFailedCount);
        if ($activateAllMessage['countParam'] !== null) {
            setEventMessages(
                $langs->trans($activateAllMessage['langKey'], $activateAllMessage['countParam'], $storeLabelForActivateAllMessage),
                null,
                $activateAllMessage['messageType']
            );
        } else {
            setEventMessages($langs->trans($activateAllMessage['langKey'], $storeLabelForActivateAllMessage), null, $activateAllMessage['messageType']);
        }
    } else {
        setEventMessages(null, !empty($shopifyWebhooks->errors) ? $shopifyWebhooks->errors : array($langs->trans("Error")), 'errors');
    }
}

// Action test webhook
if ($action == 'test_webhook' && $webhook_id && verifToken()) {
    // TODO: Implémenter le test d'un webhook spécifique
    setEventMessages($langs->trans("WebhookTestNotImplemented"), null, 'warnings');
}

// Action vider les événements traités
if ($action == 'purge_events' && verifToken()) {
    $result = $webhookManager->purgeOldEvents();
    if ($result >= 0) {
        setEventMessages($langs->trans("EventsPurged", $result), null, 'mesgs');
    } else {
        setEventMessages(null, !empty($webhookManager->errors) ? $webhookManager->errors : array($langs->trans("Error")), 'errors');
    }
}

// Action exporter les événements en JSON
if ($action == 'export_events_json' && verifToken()) {
    $exportFilterStatus = GETPOST('export_status', 'alpha');
    $exportFilterTopic = GETPOST('export_topic', 'alphanohtml');

    $sqlExport = "SELECT rowid, webhook_id, topic, shop_domain, api_version, verified, status, tries, last_error, payload, date_reception, date_traitement";
    $sqlExport .= " FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
    $sqlExport .= " WHERE entity = ".$conf->entity;
    if ($exportFilterStatus !== '' && $exportFilterStatus !== '-1') {
        $sqlExport .= " AND status = ".(int)$exportFilterStatus;
    }
    if (!empty($exportFilterTopic)) {
        $sqlExport .= " AND topic = '".$db->escape($exportFilterTopic)."'";
    }
    $sqlExport .= " ORDER BY date_reception DESC";
    $sqlExport .= $db->plimit(500, 0); // Max 500 events

    $resExport = $db->query($sqlExport);
    if ($resExport) {
        $events = array();
        while ($obj = $db->fetch_object($resExport)) {
            $eventData = array(
                'id' => (int)$obj->rowid,
                'webhook_id' => $obj->webhook_id,
                'topic' => $obj->topic,
                'shop_domain' => $obj->shop_domain,
                'api_version' => $obj->api_version,
                'verified' => (bool)$obj->verified,
                'status' => (int)$obj->status,
                'status_label' => array(0 => 'pending', 1 => 'processed', 2 => 'error')[(int)$obj->status] ?? 'unknown',
                'tries' => (int)$obj->tries,
                'last_error' => $obj->last_error,
                'payload' => $obj->payload ? json_decode($obj->payload) : null,
                'date_reception' => $obj->date_reception,
                'date_traitement' => $obj->date_traitement
            );
            $events[] = $eventData;
        }

        // Story export-diagnostic-detection-hex-elargie (AC8) : ce point d'export n'était pas
        // couvert par doli2shopRedactSensitiveConfig() — un jeton hexadécimal brut dans `payload`
        // (champ Shopify arbitraire imbriqué) ou dans `last_error` (texte libre du module) partait
        // en clair. Même brique que health.php/diagnostic.php (masquage + JSON_THROW_ON_ERROR),
        // avec le même repli défensif sur échec d'encodage.
        try {
            $jsonExport = doli2shopBuildRedactedJsonExport(array(
                'export_date' => date('Y-m-d H:i:s'),
                'total_events' => count($events),
                'filters' => array(
                    'status' => $exportFilterStatus ?: 'all',
                    'topic' => $exportFilterTopic ?: 'all'
                ),
                'events' => $events
            ));

            // Envoyer le fichier JSON
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="webhook_events_'.date('Y-m-d_His').'.json"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            print $jsonExport;
        } catch (\Throwable $e) {
            dol_syslog(
                'webhooks.php: échec du masquage/export JSON des événements — export refusé pour'
                . ' ne jamais risquer de sortir le rapport non masqué : ' . $e->getMessage(),
                LOG_ERR
            );
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            if (!headers_sent()) {
                http_response_code(500);
            }
            print json_encode(array('error' => 'export_failed'));
        }
        exit;
    }
}

/*
 * View
 */

llxHeader('', $langs->trans("ShopifyWebhooksConfiguration"));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// Subheader

// === DÉBUT ISOLATION CSS v2.1.3 ===
print '<div class=\"doli2shop-page\">';
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans("ShopifyWebhooksConfiguration"), $linkback, 'title_setup');

// Configuration header
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'webhooks', $langs->trans("ShopifyIntegration"), -1, 'shopify_color@doli2shop');

// Description
print '<h3>'.$langs->trans("WebhooksConfigurationIntro").'</h3>';
print '<p>'.$langs->trans("WebhooksConfigurationDesc").'</p>';

// ========================================
// Webhook callback URL (read-only info)
// ========================================
$webhookCallbackUrl = getDolGlobalString('SHOPIFY_WEBHOOK_URL', '');
if (empty($webhookCallbackUrl)) {
    // Construire l'URL par défaut
    global $dolibarr_main_url_root;
    if (!empty($dolibarr_main_url_root)) {
        $webhookCallbackUrl = rtrim($dolibarr_main_url_root, '/') . '/custom/doli2shop/webhooks/index.php';
    } else {
        $webhookCallbackUrl = $langs->trans("NotConfigured");
    }
}

print '<div class="info" style="margin-bottom: 10px;">';
print '<i class="fas fa-link"></i> <strong>' . $langs->trans("WebhookCallbackURL") . ':</strong> ';
print '<code>' . dol_escape_htmltag($webhookCallbackUrl) . '</code>';
print '</div>';

// ========================================
// HMAC Secret status indicator
// ========================================
$hmacSecret = getDolGlobalString('SHOPIFY_WEBHOOK_SECRET', '');
$hmacFallback = getDolGlobalString('DOLI2SHOP_API_SECRET_KEY', '');

print '<div style="margin-bottom: 10px;">';
print '<i class="fas fa-shield-alt"></i> <strong>' . $langs->trans("WebhookHMACStatus") . ':</strong> ';
if (!empty($hmacSecret)) {
    print '<span class="badge badge-status4">' . $langs->trans("Configured") . ' (SHOPIFY_WEBHOOK_SECRET)</span>';
} elseif (!empty($hmacFallback)) {
    print '<span class="badge badge-status4">' . $langs->trans("Configured") . ' (DOLI2SHOP_API_SECRET_KEY - fallback)</span>';
} else {
    print '<span class="badge badge-status8">' . $langs->trans("NotConfigured") . ' - ' . $langs->trans("WebhookHMACNotConfiguredWarning") . '</span>';
}
print '</div>';

// Actions buttons
print '<div class="tabsAction">';
print '<a class="butAction" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=sync_webhooks&token=' . newToken() . '">' . $langs->trans("SyncWebhooks") . '</a>';
// Story 59-4 (AC3) : le libellé nomme la boutique concernée — l'action agit sur la boutique
// affichée dans le contexte admin ($currentAdminStore, filtre fk_store IN (0, N) intentionnel),
// jamais sur "tous les webhooks" au sens de toutes les boutiques de l'entité.
$activateAllWebhooksLabel = ($currentAdminStore !== null && !empty($currentAdminStore->label))
    ? $langs->trans("ActivateAllWebhooksForStore", dol_escape_htmltag($currentAdminStore->label))
    : $langs->trans("ActivateAllWebhooksForStore", $langs->trans("DefaultStore"));
print '<a class="butAction" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=activate_all_webhooks&token=' . newToken() . '">' . $activateAllWebhooksLabel . '</a>';
print '<a class="butAction" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=upgrade_api_version&token=' . newToken() . '">' . $langs->trans("UpgradeWebhooksApiVersion") . '</a>';
print '<a class="butAction" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=purge_events&token=' . newToken() . '">' . $langs->trans("PurgeOldEvents") . '</a>';
print '</div>';

// Configuration form
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=update">';
print '<input type="hidden" name="token" value="'.newToken().'">';

// Paramètres généraux
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td><td>'.$langs->trans("Description").'</td></tr>';

// Nombre d'événements par traitement cron
print '<tr class="oddeven">';
print '<td>'.$langs->trans("WebhookProcessLimit").'</td>';
print '<td>';
print '<input type="number" name="webhook_process_limit" value="'.(getDolGlobalString('SHOPIFY_WEBHOOK_PROCESS_LIMIT') ?: 100).'" min="1" max="1000">';
print '</td>';
print '<td>'.$langs->trans("WebhookProcessLimitDesc").'</td>';
print '</tr>';

// Jours de conservation des événements
print '<tr class="oddeven">';
print '<td>'.$langs->trans("WebhookKeepDays").'</td>';
print '<td>';
print '<input type="number" name="webhook_keep_days" value="'.(getDolGlobalString('SHOPIFY_WEBHOOK_KEEP_DAYS') ?: 30).'" min="1" max="365">';
print '</td>';
print '<td>'.$langs->trans("WebhookKeepDaysDesc").'</td>';
print '</tr>';

// Story 36.3 : tentatives max avant dead_letter
print '<tr class="oddeven">';
print '<td>'.$langs->trans("WebhookMaxTriesLabel").'</td>';
print '<td>';
print '<input type="number" name="webhook_max_tries" value="'.(getDolGlobalInt('DOLI2SHOP_WEBHOOK_MAX_TRIES', 5)).'" min="1" max="20">';
print '</td>';
print '<td>'.$langs->trans("WebhookMaxTriesDesc").'</td>';
print '</tr>';

// Story 36.3 : timeout déblocage des events bloqués (status=3)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("WebhookStuckTimeoutLabel").'</td>';
print '<td>';
print '<input type="number" name="webhook_stuck_timeout" value="'.(getDolGlobalInt('DOLI2SHOP_WEBHOOK_STUCK_TIMEOUT_MIN', 5)).'" min="1" max="60">';
print '</td>';
print '<td>'.$langs->trans("WebhookStuckTimeoutDesc").'</td>';
print '</tr>';

// Traitement immédiat
print '<tr class="oddeven">';
print '<td>'.$langs->trans("WebhookImmediateProcess").'</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="webhook_immediate_process" value="1"'.(getDolGlobalString('SHOPIFY_WEBHOOK_IMMEDIATE_PROCESS') ? ' checked' : '').'>';
print '<span class="toggle-slider"></span>';
print '</label>';
print '</td>';
print '<td>'.$langs->trans("WebhookImmediateProcessDesc").'</td>';
print '</tr>';

// Utilisateur pour le traitement des webhooks
$webhookUserId = getDolGlobalInt('DOLI2SHOP_WEBHOOK_USER_ID', 0);
$cronUserId = getDolGlobalInt('MAIN_CRON_USERID', 0);
print '<tr class="oddeven">';
print '<td>'.$langs->trans("WebhookProcessingUser").'</td>';
print '<td>';
// Utiliser le sélecteur natif Dolibarr
$form = new Form($db);
print $form->select_dolusers($webhookUserId, 'webhook_user_id', 1, null, 0, '', '', $conf->entity, 0, 0, '', 0, '', 'minwidth200');
print '</td>';
print '<td>'.$langs->trans("WebhookProcessingUserDesc");
if ($cronUserId > 0) {
    $cronUser = new User($db);
    $cronUser->fetch($cronUserId);
    print '<br><em>'.$langs->trans("WebhookProcessingUserFallback", $cronUser->login ?: 'ID '.$cronUserId).'</em>';
}
print '</td>';
print '</tr>';

print '</table>';
print '<br>';

// Barre de contexte boutique (Story 49-10)
doli2shopRenderAdminTopBar($db);

// Liste des webhooks
print '<h3>'.$langs->trans("WebhooksConfiguration").'</h3>';
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Active").'</td>';
print '<td>'.$langs->trans("Topic").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '<td>'.$langs->trans("Status").'</td>';
print '<td>'.$langs->trans("WebhookColumnApiVersion").'</td>';
print '<td>'.$langs->trans("WebhookID").'</td>';
print '<td>'.$langs->trans("Actions").'</td>';
print '</tr>';

// Récupérer les webhooks enregistrés
$sql = "SELECT w.*, COUNT(e.rowid) as pending_events";
$sql .= " FROM ".MAIN_DB_PREFIX."doli2shop_webhooks as w";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."doli2shop_webhook_events as e ON e.topic = w.topic AND e.status = 'pending'";
$sql .= " WHERE w.entity = ".$conf->entity;
// Story 49-9 / Hotfix 2.4.6 : filtre fk_store conditionnel — secondaire = strict, défaut = IN (0, defaultId)
// (fk_store = 0 = lignes historiques pré-backfill, toujours rattachées à la boutique par défaut)
// Source de vérité unique (lib/doli2shop.lib.php) — consommée aussi par la boucle de sauvegarde
// ci-dessus, pour ne plus jamais les laisser diverger.
$sql .= doli2shopBuildStoreScopedSqlFilter($currentAdminStoreId, $currentAdminStoreIsSecondary, 'w.fk_store');
$sql .= " GROUP BY w.rowid";
$sql .= " ORDER BY w.topic";

$resql = $db->query($sql);
// Hotfix 2.4.7 (AC1a/AC2/AC3/AC6) : cause racine du symptôme résiduel de Nicolas Graillon — cette
// requête peut renvoyer PLUSIEURS lignes pour un même topic (aucune contrainte d'unicité sur
// (topic, entity, fk_store)) et l'ancien code ne triait pas entre elles : `$webhooksMap[$topic] =
// $obj` gardait la DERNIÈRE ligne lue, dans un ordre laissé au moteur SQL (potentiellement
// différent entre MySQL et MariaDB, et différent de la ligne que la sauvegarde venait d'écrire).
// On regroupe désormais toutes les lignes par topic, puis on délègue le choix de la ligne
// représentative à la même fonction pure que la boucle de sauvegarde ci-dessus (source unique,
// AC6) : à topic égal, la ligne de la boutique courante prime, puis la plus récente — de façon
// garantie indépendante de l'ordre de lecture.
$webhookRowsByTopic = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $webhookRowsByTopic[$obj->topic][] = $obj;
    }
}

// Round 2 de la revue 3 couches : l'état affiché doit être celui du SCOPE, pas d'une ligne isolée.
// Un topic est actif dès qu'une ligne du scope porte un abonnement vivant — c'est la réalité côté
// Shopify. Sinon, une installation dont la ligne « préférée » (boutique courante) est vide et dont
// la jumelle legacy porte l'abonnement s'affichait « inactif » juste après avoir été activée.
// Même fonction que la boucle de sauvegarde, appelée sur les mêmes lignes : les deux ne peuvent
// plus diverger (AC2/AC6).
$webhookStateByTopic = array();
foreach ($webhookRowsByTopic as $topicKey => $rowsForThisTopic) {
    $webhookStateByTopic[$topicKey] = doli2shopComputeWebhookTopicState($rowsForThisTopic);
}

// Afficher tous les topics supportés
$supportedTopics = $shopifyWebhooks->getSupportedTopics();
foreach ($supportedTopics as $topic) {
    $topicStateForDisplay = isset($webhookStateByTopic[$topic]) ? $webhookStateByTopic[$topic] : null;
    // Les colonnes de détail décrivent l'abonnement réellement en place quand il y en a un.
    $webhook = $topicStateForDisplay !== null ? $topicStateForDisplay['displayRow'] : null;
    $topicIsActive = $topicStateForDisplay !== null ? $topicStateForDisplay['isActive'] : false;
    
    print '<tr class="oddeven">';
    
    // Active
    print '<td>';
    print '<label class="toggle-switch">';
    print '<input type="checkbox" name="active_topics['.$topic.']" value="1"';
    if ($topicIsActive) {
        print ' checked';
    }
    print '>';
    print '<span class="toggle-slider"></span>';
    print '</label>';
    print '</td>';
    
    // Topic
    print '<td>'.$topic.'</td>';
    
    // Description
    print '<td>'.$langs->trans("Webhook_".$topic).'</td>';
    
    // Status
    print '<td>';
    if ($webhook) {
        if ($webhook->status == 1) {
            print '<span class="badge badge-status4">'.$langs->trans("Active").'</span>';
        } else {
            print '<span class="badge badge-status5">'.$langs->trans("Inactive").'</span>';
        }
        if ($webhook->pending_events > 0) {
            print ' <span class="badge badge-status1">'.$webhook->pending_events.' '.$langs->trans("Pending").'</span>';
        }
    } else {
        print '<span class="badge badge-status5">'.$langs->trans("NotConfigured").'</span>';
    }
    print '</td>';

    // Version d'API du webhook (repère les abonnements à ré-enregistrer)
    print '<td>';
    if ($webhook && !empty($webhook->api_version)) {
        $isCurrentVer = ($webhook->api_version === $shopifyWebhooks->getApiVersion());
        print '<span style="font-size: 11px;'.($isCurrentVer ? '' : 'color:#cc5500;font-weight:bold;').'" title="'.($isCurrentVer ? '' : dol_escape_htmltag($langs->trans("WebhookApiVersionOutdatedHint"))).'">'.dol_escape_htmltag($webhook->api_version).'</span>';
    } else {
        print '-';
    }
    print '</td>';

    // Webhook ID
    print '<td>';
    if ($webhook && $webhook->webhook_id) {
        print $webhook->webhook_id;
    } else {
        print '-';
    }
    print '</td>';
    
    // Actions
    print '<td>';
    if ($webhook && $webhook->webhook_id) {
        print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=test_webhook&webhook_id='.$webhook->webhook_id.'&token='.newToken().'" class="butActionDelete">'.$langs->trans("Test").'</a>';
    }
    print '</td>';
    
    print '</tr>';
}

print '</table>';

// Submit button
print '<div class="center">';
print '<input type="submit" class="button" name="save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Statistiques des événements
print '<br>';
print '<h3>'.$langs->trans("WebhookEventsStatistics").'</h3>';

$sql = "SELECT status, COUNT(*) as count FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
$sql .= " WHERE entity = ".$conf->entity;
$sql .= " GROUP BY status";

$resql = $db->query($sql);
if ($resql) {
    print '<table class="noborder" width="100%">';
    print '<tr class="liste_titre"><td>'.$langs->trans("Status").'</td><td>'.$langs->trans("Count").'</td></tr>';

    $totalEvents = 0;
    while ($obj = $db->fetch_object($resql)) {
        print '<tr class="oddeven">';
        $statusLabels = array(0 => 'Pending', 1 => 'Processed', 2 => 'Error');
        $statusLabel = isset($statusLabels[(int)$obj->status]) ? $statusLabels[(int)$obj->status] : $langs->trans("EventStatus_".$obj->status);
        print '<td>'.$statusLabel.'</td>';
        print '<td>'.$obj->count.'</td>';
        print '</tr>';
        $totalEvents += $obj->count;
    }

    print '</table>';
}

// ========================================
// Liste détaillée des événements récents
// ========================================
print '<br>';
print '<h3>'.$langs->trans("WebhookRecentEvents").'</h3>';

// Filtres
$filterStatus = GETPOST('filter_status', 'int');
$filterTopic = GETPOST('filter_topic', 'alphanohtml');
$eventsPage = GETPOST('events_page', 'int');
if ($eventsPage < 1) {
    $eventsPage = 1;
}
$eventsPerPage = 25;
$eventsOffset = ($eventsPage - 1) * $eventsPerPage;

// Barre de filtres
print '<div style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">';

// Contexte imbriqué : chaîne JS à apostrophes DANS un attribut HTML. dol_escape_htmltag() seul ne
// suffit pas (ENT_COMPAT laisse passer l'apostrophe, et le navigateur decode l'attribut AVANT de
// compiler le JS) : echappement JS d'abord, echappement HTML ensuite.
print '<select name="filter_status_select" id="filter_status_select" onchange="window.location.href=\''.dol_escape_htmltag(dol_escape_js($_SERVER["PHP_SELF"], 1)).'?filter_status=\'+this.value+\'&filter_topic='.urlencode($filterTopic ?: '').'\'">';
print '<option value="-1">'.$langs->trans("AllStatuses").'</option>';
print '<option value="0"'.($filterStatus === '0' || $filterStatus === 0 ? ' selected' : '').'>'.$langs->trans("Pending").' (0)</option>';
print '<option value="1"'.(GETPOST('filter_status', 'alpha') === '1' ? ' selected' : '').'>'.$langs->trans("Processed").' (1)</option>';
print '<option value="2"'.(GETPOST('filter_status', 'alpha') === '2' ? ' selected' : '').'>'.$langs->trans("Error").' (2)</option>';
print '</select>';

// Meme contexte imbriqué JS-dans-attribut que le filtre de statut ci-dessus.
print '<select name="filter_topic_select" id="filter_topic_select" onchange="window.location.href=\''.dol_escape_htmltag(dol_escape_js($_SERVER["PHP_SELF"], 1)).'?filter_topic=\'+this.value+\'&filter_status='.urlencode(GETPOST('filter_status', 'alpha') ?: '-1').'\'">';
print '<option value="">'.$langs->trans("AllTopics").'</option>';
foreach ($supportedTopics as $topicOption) {
    print '<option value="'.dol_escape_htmltag($topicOption).'"'.($filterTopic === $topicOption ? ' selected' : '').'>'.dol_escape_htmltag($topicOption).'</option>';
}
print '</select>';

if ($filterTopic || (GETPOST('filter_status', 'alpha') !== '' && GETPOST('filter_status', 'alpha') !== '-1')) {
    print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'" class="butAction" style="padding: 4px 10px; font-size: 12px;">'.$langs->trans("ResetFilters").'</a>';
}

// Bouton export JSON (avec les mêmes filtres actifs)
$exportParams = 'action=export_events_json&token='.newToken();
if (GETPOST('filter_status', 'alpha') !== '' && GETPOST('filter_status', 'alpha') !== '-1') {
    $exportParams .= '&export_status='.urlencode(GETPOST('filter_status', 'alpha'));
}
if (!empty($filterTopic)) {
    $exportParams .= '&export_topic='.urlencode($filterTopic);
}
print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?'.$exportParams.'" class="butAction" style="padding: 4px 10px; font-size: 12px;">';
print '<i class="fas fa-download"></i> '.$langs->trans("ExportJSON");
print '</a>';

print '</div>';

// Requête avec filtres
$sqlEvents = "SELECT rowid, webhook_id, topic, shop_domain, api_version, verified, status, tries, last_error, date_reception, date_traitement";
$sqlEvents .= " FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
$sqlEvents .= " WHERE entity = ".$conf->entity;
// Story 49-9 : events récents filtrés par domaine — boutique secondaire uniquement (défaut = chemin actuel)
if ($currentAdminStoreIsSecondary && !empty($currentAdminStore->shop_domain)) {
    $sqlEvents .= " AND shop_domain = '" . $db->escape($currentAdminStore->shop_domain) . "'";
}
if (GETPOST('filter_status', 'alpha') !== '' && GETPOST('filter_status', 'alpha') !== '-1') {
    $sqlEvents .= " AND status = ".(int)$filterStatus;
}
if (!empty($filterTopic)) {
    $sqlEvents .= " AND topic = '".$db->escape($filterTopic)."'";
}
$sqlEvents .= " ORDER BY date_reception DESC";

// Compter le total pour pagination
$sqlCount = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events";
$sqlCount .= " WHERE entity = ".$conf->entity;
if ($currentAdminStoreIsSecondary && !empty($currentAdminStore->shop_domain)) {
    $sqlCount .= " AND shop_domain = '" . $db->escape($currentAdminStore->shop_domain) . "'";
}
if (GETPOST('filter_status', 'alpha') !== '' && GETPOST('filter_status', 'alpha') !== '-1') {
    $sqlCount .= " AND status = ".(int)$filterStatus;
}
if (!empty($filterTopic)) {
    $sqlCount .= " AND topic = '".$db->escape($filterTopic)."'";
}
$resCount = $db->query($sqlCount);
$totalFiltered = 0;
if ($resCount) {
    $objCount = $db->fetch_object($resCount);
    $totalFiltered = $objCount->total;
}
$totalPages = ceil($totalFiltered / $eventsPerPage);

$sqlEvents .= $db->plimit($eventsPerPage, $eventsOffset);

$resqlEvents = $db->query($sqlEvents);
if ($resqlEvents) {
    print '<table class="noborder" width="100%">';
    print '<tr class="liste_titre">';
    print '<td>#</td>';
    print '<td>'.$langs->trans("Date").'</td>';
    print '<td>'.$langs->trans("Topic").'</td>';
    print '<td>'.$langs->trans("ShopDomain").'</td>';
    print '<td>'.$langs->trans("Status").'</td>';
    print '<td>HMAC</td>';
    print '<td>'.$langs->trans("Tries").'</td>';
    print '<td>'.$langs->trans("ProcessedDate").'</td>';
    print '<td>'.$langs->trans("Error").'</td>';
    print '<td>'.$langs->trans("Actions").'</td>';
    print '</tr>';

    $numEvents = $db->num_rows($resqlEvents);
    if ($numEvents == 0) {
        print '<tr class="oddeven"><td colspan="10" class="center">'.$langs->trans("NoWebhookEvents").'</td></tr>';
    }

    while ($event = $db->fetch_object($resqlEvents)) {
        print '<tr class="oddeven">';

        // ID
        print '<td>'.$event->rowid.'</td>';

        // Date réception
        print '<td>'.($event->date_reception ? dol_print_date($db->jdate($event->date_reception), 'dayhour') : '-').'</td>';

        // Topic
        print '<td><code>'.dol_escape_htmltag($event->topic).'</code></td>';

        // Shop domain
        print '<td>'.dol_escape_htmltag($event->shop_domain ?: '-').'</td>';

        // Status badge
        print '<td>';
        switch ((int)$event->status) {
            case 0:
                print '<span class="badge badge-status1">'.$langs->trans("Pending").'</span>';
                break;
            case 1:
                print '<span class="badge badge-status4">'.$langs->trans("Processed").'</span>';
                break;
            case 2:
                print '<span class="badge badge-status8">'.$langs->trans("Error").'</span>';
                break;
            default:
                print '<span class="badge badge-status0">'.dol_escape_htmltag($event->status).'</span>';
        }
        print '</td>';

        // HMAC verified
        print '<td>';
        if ($event->verified) {
            print '<span class="badge badge-status4">OK</span>';
        } else {
            print '<span class="badge badge-status8">Non</span>';
        }
        print '</td>';

        // Tries
        print '<td>'.$event->tries.'</td>';

        // Date traitement
        print '<td>'.($event->date_traitement ? dol_print_date($db->jdate($event->date_traitement), 'dayhour') : '-').'</td>';

        // Last error (tronquée)
        print '<td>';
        if ($event->last_error) {
            $shortError = dol_trunc($event->last_error, 60);
            print '<span title="'.dol_escape_htmltag($event->last_error).'" style="color: #dc3545;">'.dol_escape_htmltag($shortError).'</span>';
        } else {
            print '-';
        }
        print '</td>';

        // Actions (voir payload)
        print '<td>';
        print '<a href="#" onclick="jQuery(\'#payload_'.$event->rowid.'\').toggle(); return false;" class="butAction" style="padding: 2px 8px; font-size: 11px;">'.$langs->trans("ViewPayload").'</a>';
        print '</td>';

        print '</tr>';

        // Ligne cachée pour le payload
        print '<tr id="payload_'.$event->rowid.'" style="display: none;">';
        print '<td colspan="10" style="background: #f8f9fa; padding: 10px;">';
        print '<strong>Webhook ID:</strong> '.dol_escape_htmltag($event->webhook_id ?: 'N/A');
        print ' | <strong>API Version:</strong> '.dol_escape_htmltag($event->api_version ?: 'N/A');
        print '<br><br>';

        // Charger le payload à la demande via AJAX pour ne pas surcharger la page
        // Pour l'instant, affichage direct (limité aux 50 derniers events de toute façon)
        $sqlPayload = "SELECT payload FROM ".MAIN_DB_PREFIX."doli2shop_webhook_events WHERE rowid = ".(int)$event->rowid;
        $resPayload = $db->query($sqlPayload);
        if ($resPayload) {
            $objPayload = $db->fetch_object($resPayload);
            if ($objPayload && $objPayload->payload) {
                // Formatter le JSON pour une meilleure lisibilité
                $decodedPayload = json_decode($objPayload->payload);
                $formattedPayload = $decodedPayload ? json_encode($decodedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $objPayload->payload;
                print '<pre style="max-height: 400px; overflow: auto; background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; word-wrap: break-word;">'.dol_escape_htmltag($formattedPayload).'</pre>';
            } else {
                print '<em>'.$langs->trans("NoPayload").'</em>';
            }
        }

        if ($event->last_error) {
            print '<br><strong style="color: #dc3545;">'.$langs->trans("ErrorDetail").':</strong><br>';
            print '<pre style="background: #fff3cd; padding: 10px; border-radius: 4px; font-size: 12px;">'.dol_escape_htmltag($event->last_error).'</pre>';
        }

        print '</td>';
        print '</tr>';
    }

    print '</table>';

    // Pagination
    if ($totalPages > 1) {
        print '<div class="center" style="margin-top: 10px;">';
        if ($eventsPage > 1) {
            $prevParams = 'events_page='.($eventsPage - 1);
            if (GETPOST('filter_status', 'alpha') !== '' && GETPOST('filter_status', 'alpha') !== '-1') {
                $prevParams .= '&filter_status='.urlencode(GETPOST('filter_status', 'alpha'));
            }
            if (!empty($filterTopic)) {
                $prevParams .= '&filter_topic='.urlencode($filterTopic);
            }
            print '<a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?'.$prevParams.'" class="butAction" style="padding: 4px 12px;">&laquo; '.$langs->trans("Previous").'</a> ';
        }
        print '<span style="padding: 0 10px;">'.$langs->trans("Page").' '.$eventsPage.' / '.$totalPages.' ('.$totalFiltered.' '.$langs->trans("Events").')</span>';
        if ($eventsPage < $totalPages) {
            $nextParams = 'events_page='.($eventsPage + 1);
            if (GETPOST('filter_status', 'alpha') !== '' && GETPOST('filter_status', 'alpha') !== '-1') {
                $nextParams .= '&filter_status='.urlencode(GETPOST('filter_status', 'alpha'));
            }
            if (!empty($filterTopic)) {
                $nextParams .= '&filter_topic='.urlencode($filterTopic);
            }
            print ' <a href="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?'.$nextParams.'" class="butAction" style="padding: 4px 12px;">'.$langs->trans("Next").' &raquo;</a>';
        }
        print '</div>';
    }
} else {
    print '<div class="error">'.$langs->trans("ErrorDatabaseQuery").' : '.dol_escape_htmltag($db->lasterror()).'</div>';
}

print dol_get_fiche_end();

// End of page

// === FIN ISOLATION CSS v2.1.3 ===
print '</div>';

llxFooter();
$db->close();
