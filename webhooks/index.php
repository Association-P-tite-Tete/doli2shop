<?php
/**
 * @file        webhooks/index.php
 * @brief       Entry point for Shopify webhook HTTP POST requests
 *
 * This file receives webhook notifications from Shopify, validates HMAC signature,
 * and dispatches events to the WebhookManager for processing.
 *
 * Story webhooks-multicompany-sans-resolution-d-entite (v2.5.3) : sous Multicompany, ce
 * script résout maintenant l'entité qui porte la configuration de la boutique EMETTRICE
 * (X-Shopify-Shop-Domain) avant toute lecture de configuration, avant le chargement de $user
 * et avant l'instanciation de WebhookManager — voir doli2shopResolveEntityForShopDomain()
 * (lib/doli2shop.lib.php). AC6(e) — condition de sécurité documentée, non vérifiable par le
 * code : CHAQUE entité doit avoir sa PROPRE app Shopify (donc son propre secret HMAC). Deux
 * entités partageant le même secret rendraient un payload signé valide pour l'une rejouable
 * vers l'autre (le sélecteur d'entité, lui, n'est pas signé). La clé de déduplication des
 * webhooks porte sur (webhook_id, entity) — llx_doli2shop_webhook_events, index
 * uk_doli2shop_webhook_events_dedup — donc un tel rejeu ne serait PAS filtré comme doublon.
 *
 * @package     ShopifyIntegration
 * @subpackage  Webhooks
 * @category    webhooks
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.8
 * @link        https://doli2shop.ptitetete.org
 */

// Webhook endpoint - no authentication required (HMAC validation is done instead)
define('NOLOGIN', 1);
define('NOCSRFCHECK', 1);
define('NOIPCHECK', 1);
define('NOBROWSERNOTIF', 1);
// Story 59-3 : un webhook Shopify n'est jamais rejoué par un navigateur avec cookie de session
// et s'authentifie par HMAC, pas par session. NOSESSION empêche main.inc.php d'appeler
// session_start() (main.inc.php ~L.127) : plus de fichier de session ouvert par appel entrant
// (jusqu'à des dizaines/minute). NOTOKENRENEWAL empêche la rotation du jeton CSRF
// (main.inc.php ~L.333) — sans session, $_SESSION['token']/['newtoken'] n'existeraient de toute
// façon pas, mais le déclarer documente l'intention et évite toute dépendance implicite.
// Sans impact sur $user : avec NOLOGIN déjà actif, master.inc.php ne peuple jamais $user depuis
// la session — il est reconstruit plus bas indépendamment, comme aujourd'hui.
define('NOSESSION', 1);
define('NOTOKENRENEWAL', 1);

// Load Dolibarr environment - cascade 3 levels
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
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
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    http_response_code(500);
    echo json_encode(['error' => 'Dolibarr environment not found']);
    exit;
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Story webhooks-multicompany-sans-resolution-d-entite : fonctions de résolution d'entité
// multi-tenant (doli2shopResolveEntityForShopDomain(), doli2shopValidateShopDomainFormat()).
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Load WebhookManager (which loads handlers internally). La CLASSE est chargée ici, mais
// n'est INSTANCIÉE (new WebhookManager) que plus bas, APRÈS la résolution d'entité (AC1/AC7) :
// tant que l'entité n'est pas résolue, tout objet qui lirait $conf->entity au constructeur
// (WebhookManager::log() via LoggerTrait, notamment) le ferait sur la mauvaise entité.
require_once dirname(__FILE__) . '/../class/webhookmanager.class.php';

// ========================================
// Extract Shopify headers
// ========================================
// Déplacé EN TÊTE du script (Story webhooks-multicompany-sans-resolution-d-entite, AC1/AC7) :
// $shopDomain doit être connu AVANT toute lecture de configuration (getDolGlobalString/Int),
// avant le chargement de $user et avant l'instanciation de WebhookManager, pour permettre de
// résoudre l'entité qui porte la configuration de cette boutique. Sous Multicompany, ce script
// s'exécute toujours en entité 1 par défaut (NOLOGIN saute la résolution d'entité du cœur,
// main.inc.php ~L.500) : sans ce réordonnancement, la configuration (secret HMAC, hostname,
// utilisateur webhook) d'une boutique déclarée sur une autre entité n'est jamais vue.
$hmacHeader = isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256']) ? $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] : '';
$topic = isset($_SERVER['HTTP_X_SHOPIFY_TOPIC']) ? $_SERVER['HTTP_X_SHOPIFY_TOPIC'] : '';
$shopDomain = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';
$apiVersion = isset($_SERVER['HTTP_X_SHOPIFY_API_VERSION']) ? $_SERVER['HTTP_X_SHOPIFY_API_VERSION'] : '';
$webhookId = isset($_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID']) ? $_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] : '';

dol_syslog("Doli2Shop Webhook: Received - topic=" . $topic . " shop=" . $shopDomain . " api_version=" . $apiVersion . " webhook_id=" . $webhookId, LOG_INFO);

// ========================================
// Contrôles préalables — méthode, payload, en-têtes obligatoires
// ========================================
// ⚠️ Revue 3 couches (HIGH, Blind Hunter) : ces contrôles doivent précéder la résolution
// d'entité, et non la suivre. Placés après, ils créaient un oracle d'énumération : une simple
// requête GET (sans HMAC, sans corps) portant un domaine déclaré sur DEUX entités sortait en
// 401 par la branche « ambiguïté », alors que le même GET portant n'importe quel autre domaine
// sortait en 405 — un attaquant anonyme distinguait donc les domaines configurés en double,
// exactement l'oracle que l'AC6(b) veut fermer. Aucun de ces contrôles ne lit la configuration
// (ni getDolGlobalString(), ni $user, ni WebhookManager) : les remonter ne rompt pas l'AC1, qui
// exige seulement que la résolution d'entité précède toute LECTURE DE CONFIGURATION.
// Effet de bord bienvenu : plus aucune requête SQL de résolution n'est dépensée pour une
// requête qui sera de toute façon rejetée (MEDIUM de la même revue).

// ========================================
// Only accept POST requests
// ========================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    dol_syslog("Doli2Shop Webhook: Rejected non-POST request: " . $_SERVER['REQUEST_METHOD'], LOG_WARNING);
    exit;
}

// ========================================
// Read raw payload
// ========================================
$payload = file_get_contents('php://input');

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    dol_syslog("Doli2Shop Webhook: Rejected empty payload", LOG_WARNING);
    exit;
}

// ========================================
// Validate required headers
// ========================================
if (empty($hmacHeader)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing HMAC header']);
    dol_syslog("Doli2Shop Webhook: Missing X-Shopify-Hmac-Sha256 header", LOG_WARNING);
    exit;
}

if (empty($topic)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing topic header']);
    dol_syslog("Doli2Shop Webhook: Missing X-Shopify-Topic header", LOG_WARNING);
    exit;
}

if (empty($shopDomain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing shop domain header']);
    dol_syslog("Doli2Shop Webhook: Missing X-Shopify-Shop-Domain header", LOG_WARNING);
    exit;
}

// ========================================
// Résolution d'entité multi-tenant (AC1 à AC6bis)
// ========================================
// AC6 : X-Shopify-Shop-Domain n'est PAS signé et est lu avant toute vérification HMAC. Ce
// sélecteur reste sain à condition que RIEN ne soit écrit en base avant la validation de la
// signature (WebhookManager::receive() fait HMAC -> dédup lecture seule -> INSERT, cf. story)
// et que les cinq conditions de sécurité listées dans la story soient respectées ci-dessous.
global $mysoc;

$entityResolution = doli2shopResolveEntityForShopDomain($db, $shopDomain);

switch ($entityResolution['status']) {
    case 'ambiguous':
        // AC3 : ambiguïté = refus explicite, jamais de choix implicite (écrire dans la mauvaise
        // société est pire qu'un refus : Shopify retentera le webhook).
        // AC6(b) : réponse externe INDISTINGUABLE d'un HMAC invalide (401 uniforme) — sinon
        // l'endpoint deviendrait un oracle d'énumération des boutiques configurées. Le détail
        // (quelles entités) reste en journal uniquement.
        dol_syslog(
            "Doli2Shop Webhook: Ambiguous shop_domain=" . $shopDomain
                . " found on entities=[" . implode(',', $entityResolution['entities']) . "]"
                . " (source=" . $entityResolution['source'] . ") — webhook refused, entity resolution aborted",
            LOG_ERR
        );
        http_response_code(401);
        echo json_encode(['error' => 'Invalid HMAC signature']);
        exit;

    case 'resolved':
        $resolvedEntity = (int) $entityResolution['entity'];
        // AC5 : rétrocompatibilité stricte — si l'entité résolue est déjà l'entité courante,
        // aucun rechargement, aucun coût supplémentaire (setEntityValues() elle-même no-op sur
        // entité inchangée, mais on évite même l'appel pour ne pas rebuilder $mysoc pour rien).
        if ($resolvedEntity !== (int) $conf->entity) {
            $conf->setEntityValues($db, $resolvedEntity);

            // AC5bis : $mysoc est construit une seule fois dans master.inc.php, AVANT que
            // l'entité ne soit connue (chemin NOLOGIN) — donc depuis les constantes
            // MAIN_INFO_SOCIETE_* de l'entité 1. setEntityValues() recharge $conf mais PAS
            // $mysoc. Sans ce rechargement, une commande de l'entité résolue serait calculée
            // (Commande::addline()/calcul_price_total()) avec la société de l'entité 1 : pays
            // du vendeur et régime de TVA faux, erreur comptable silencieuse.
            if (is_object($mysoc) && method_exists($mysoc, 'setMysoc')) {
                $mysoc->setMysoc($conf);
            }

            dol_syslog(
                "Doli2Shop Webhook: entity resolved to " . $resolvedEntity . " for shop=" . $shopDomain
                    . " (source=" . $entityResolution['source'] . "), config + \$mysoc reloaded",
                LOG_INFO
            );
        }
        break;

    case 'invalid_format':
        // Format d'en-tête inattendu (motif xxx.myshopify.com non respecté, ou en-tête vide) :
        // pas d'écriture, pas de requête déclenchée avec cette valeur — traité comme AC4
        // (boutique inconnue), entité courante conservée.
        dol_syslog(
            "Doli2Shop Webhook: shop_domain header format invalide, résolution d'entité ignorée: " . $shopDomain,
            LOG_WARNING
        );
        break;

    case 'unknown':
        // AC4 : aucune correspondance (ni llx_doli2shop_stores, ni llx_const) — comportement
        // actuel conservé (entité courante), simple avertissement journalisé. Une installation
        // mono-entité sans ligne stores ni constante doit continuer de fonctionner à l'identique.
        dol_syslog(
            "Doli2Shop Webhook: shop_domain inconnu, entité courante conservée (AC4): " . $shopDomain,
            LOG_WARNING
        );
        break;

    case 'disabled':
    default:
        // AC6(c)/AC5 : Multicompany inactif — une seule entité possible, rien à résoudre, aucun
        // coût supplémentaire pour l'installation mono-entité (chemin très majoritaire).
        break;
}

// ========================================
// Setup user context for webhook processing
// ========================================
// Avec NOLOGIN, $user est vide (ID=0, pas de droits).
// Les handlers webhook ont besoin d'un utilisateur avec droits (création commandes, produits, etc.)
// On charge l'utilisateur configuré pour les CRONs, ou à défaut l'admin (ID=1).
// Lu APRES la résolution d'entité ci-dessus : DOLI2SHOP_WEBHOOK_USER_ID est une constante par
// entité (llx_const), donc lue depuis la MAUVAISE entité si elle était consultée plus tôt.
$webhookUserId = getDolGlobalInt('DOLI2SHOP_WEBHOOK_USER_ID', 0);
if (empty($webhookUserId)) {
    // Fallback : utiliser l'utilisateur CRON configuré dans Dolibarr
    $webhookUserId = getDolGlobalInt('MAIN_CRON_USERID', 0);
}
if (empty($webhookUserId)) {
    // Dernier recours : admin (ID=1)
    $webhookUserId = 1;
}

// Charger l'utilisateur avec ses droits
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
$user = new User($db);
$fetchResult = $user->fetch($webhookUserId);
if ($fetchResult <= 0) {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook user configuration error']);
    dol_syslog("Doli2Shop Webhook: Cannot load webhook user ID=" . $webhookUserId . " (check DOLI2SHOP_WEBHOOK_USER_ID or MAIN_CRON_USERID settings)", LOG_ERR);
    exit;
}
$user->getRights();

dol_syslog("Doli2Shop Webhook: Using user ID=" . $user->id . " (" . $user->login . ") admin=" . ($user->admin ? "yes" : "no"), LOG_DEBUG);

// ========================================
// Phase 1 : Stockage du webhook (< 500ms)
// ========================================
try {
    $webhookManager = new WebhookManager($db);

    // receive() fait stockage SEUL (HMAC + dedup + INSERT)
    // Retour : >0 = eventId, 0 = doublon, <0 = erreur
    $eventId = $webhookManager->receive(
        $payload,
        $hmacHeader,
        $topic,
        $shopDomain,
        $apiVersion,
        $webhookId
    );

    // ========================================
    // Phase 2 : Réponse HTTP immédiate
    // ========================================
    if ($eventId > 0) {
        http_response_code(200);
        echo json_encode(['status' => 'accepted']);
        dol_syslog("Doli2Shop Webhook: Stored event " . $eventId . " for topic=" . $topic, LOG_INFO);
    } elseif ($eventId == 0) {
        // Doublon détecté (pré-check ou ON DUPLICATE KEY)
        http_response_code(200);
        echo json_encode(['status' => 'duplicate']);
        dol_syslog("Doli2Shop Webhook: Duplicate webhook for topic=" . $topic . " webhook_id=" . $webhookId, LOG_INFO);
    } elseif (in_array('Invalid HMAC signature', $webhookManager->errors)) {
        // Signature invalide — ne pas accepter
        http_response_code(401);
        echo json_encode(['error' => 'Invalid HMAC signature']);
        dol_syslog("Doli2Shop Webhook: HMAC validation failed for topic=" . $topic . " from " . $shopDomain, LOG_ERR);
    } else {
        // Erreur de stockage — l'event n'a PAS été stocké, Shopify doit retenter
        // NE PAS retourner 200 : l'event serait perdu (pas en base, CRON ne peut pas retraiter)
        http_response_code(500);
        echo json_encode(['error' => 'Storage error']);
        dol_syslog("Doli2Shop Webhook: Storage error for topic=" . $topic . ": " . implode(', ', $webhookManager->errors), LOG_ERR);
    }

    // ========================================
    // Phase 3 : Flush — envoyer la réponse au client
    // ========================================
    if (function_exists('fastcgi_finish_request')) {
        // PHP-FPM : méthode optimale, libère le process FPM
        fastcgi_finish_request();
        dol_syslog("Doli2Shop Webhook: Response flushed via fastcgi_finish_request", LOG_DEBUG);
    } else {
        // Apache mod_php ou autre : flush manuel avec headers de fermeture connexion
        // Note : le pattern async peut ne pas fonctionner sur certains hébergeurs Apache
        header("Connection: close");
        header("Content-Length: " . ob_get_length());
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        dol_syslog("Doli2Shop Webhook: Response flushed via ob_end_flush (mod_php fallback)", LOG_DEBUG);
    }

    // ========================================
    // Phase 4 : Traitement inline (après flush)
    // ========================================
    // Le client a déjà reçu la réponse HTTP.
    // Si SHOPIFY_WEBHOOK_IMMEDIATE_PROCESS est actif : traitement inline maintenant.
    // Sinon : l'event reste en status=0 (pending) et sera traité par le CRON.
    // Dans les deux cas, le CRON sert de filet de sécurité pour les events non traités.
    if ($eventId > 0 && getDolGlobalString('SHOPIFY_WEBHOOK_IMMEDIATE_PROCESS')) {
        // Protéger le script contre l'interruption et limiter le temps d'exécution
        // Seulement si un event doit être traité en inline
        ignore_user_abort(true);
        set_time_limit(30);
        try {
            // Epic 47-4 : passer $shopDomain pour le routage par boutique (lu ci-dessus)
            $result = $webhookManager->processEvent($eventId, $topic, $payload, $shopDomain);
            if ($result > 0) {
                dol_syslog("Doli2Shop Webhook: Processed inline event " . $eventId . " for topic=" . $topic, LOG_INFO);
            } else {
                dol_syslog("Doli2Shop Webhook: Inline processing failed for event " . $eventId . ", CRON will retry", LOG_WARNING);
            }
        } catch (\Throwable $e) {
            dol_syslog("Doli2Shop Webhook: Inline processing exception for event " . $eventId . ": " . $e->getMessage(), LOG_ERR);
            // Event reste en status=0 ou 3, sera retraité par le CRON
        }
    }
} catch (\Throwable $e) {
    // Si le flush a déjà été envoyé, http_response_code() et echo sont sans effet (connexion fermée)
    // On ne tente l'écriture HTTP que si headers pas encore envoyés
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
    dol_syslog("Doli2Shop Webhook: " . get_class($e) . ": " . $e->getMessage(), LOG_ERR);
}

// Fermer proprement
if (isset($db)) {
    $db->close();
}
