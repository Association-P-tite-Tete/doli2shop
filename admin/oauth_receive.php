<?php
/**
 * @file        admin/oauth_receive.php
 * @brief       OAuth receiver - stores credentials from proxy
 *
 * This page receives the OAuth credentials from the central proxy
 * and stores them in Dolibarr configuration.
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
dol_include_once('/doli2shop/class/shopifyapi.class.php');

// Security check
if (!$user->admin) {
    accessforbidden();
}

// Load translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop"));

// ===========================================
// CONFIGURATION
// ===========================================

// Secret HMAC legacy (modèle historique à secret partagé). PLUS de valeur par défaut
// prévisible (HIGH review 34.6) : la vérification legacy n'est possible que si la constante
// est explicitement définie. Ce chemin n'est emprunté que si le proxy n'émet PAS de
// signature asymétrique (proxy non migré) ; sinon vérification asymétrique exclusive.
$oauth_proxy_secret = getDolGlobalString('DOLI2SHOP_OAUTH_PROXY_SECRET', '');

// URL de l'endpoint clé publique du proxy (Story 34.6 — vérification asymétrique)
$oauth_pubkey_url = 'https://doli2shop.ptitetete.org/oauth/pubkey.php';

// Story 51-1 — marge de sécurité (secondes) soustraite à l'échéance persistée du token :
// absorbe la latence réseau entre la réception du token et son premier usage (évite un
// 401 immédiat sur un token "juste" expiré).
if (!defined('DOLI2SHOP_TOKEN_EXPIRY_MARGIN_SECONDS')) {
    define('DOLI2SHOP_TOKEN_EXPIRY_MARGIN_SECONDS', 120);
}

/**
 * Récupère la clé publique de signature OAuth du proxy, avec cache local par key_id.
 *
 * Le cache (constantes Dolibarr) évite un appel réseau à chaque retour. En cas de rotation
 * côté proxy (key_id différent), la clé est re-récupérée automatiquement.
 *
 * @param string  $keyId Identifiant de clé annoncé dans le retour OAuth
 * @param string  $url   Endpoint pubkey du proxy
 * @param DoliDB  $db    Connexion base
 * @param Conf    $conf  Conf Dolibarr (entity)
 * @return string|null PEM de la clé publique correspondant à $keyId, ou null
 */
function doli2shopFetchOAuthPublicKey($keyId, $url, $db, $conf)
{
    // Format strict (fingerprint hex) — bloque tout key_id forgé et évite des fetchs parasites
    if (!preg_match('/^[a-f0-9]{8,64}$/', (string) $keyId)) {
        return null;
    }

    // 1) Cache : si on a déjà la clé pour ce key_id, l'utiliser
    $cachedId  = getDolGlobalString('DOLI2SHOP_OAUTH_PUBKEY_ID', '');
    $cachedPem = getDolGlobalString('DOLI2SHOP_OAUTH_PUBKEY_PEM', '');
    if ($cachedId === $keyId && $cachedPem !== '') {
        return $cachedPem;
    }

    // 2) Récupération HTTPS via le helper Dolibarr (timeout court, schéma https only)
    dol_include_once('/core/lib/geturl.lib.php');
    $result = getURLContent($url, 'GET', '', 1, array(), array('https'), 0);
    if (!is_array($result) || (int) ($result['http_code'] ?? 0) !== 200 || empty($result['content'])) {
        dol_syslog("Doli2Shop OAuth: échec récupération clé publique ($url) code=" . ($result['http_code'] ?? 'n/a'), LOG_WARNING);
        return null;
    }

    $payload = json_decode($result['content'], true);
    if (!is_array($payload) || empty($payload['key_id']) || empty($payload['public_key'])) {
        dol_syslog("Doli2Shop OAuth: réponse pubkey invalide", LOG_WARNING);
        return null;
    }

    // 3) Le key_id servi doit correspondre à celui annoncé dans le retour (intégrité)
    if (!hash_equals((string) $payload['key_id'], (string) $keyId)) {
        dol_syslog("Doli2Shop OAuth: key_id pubkey (" . $payload['key_id'] . ") != attendu ($keyId)", LOG_WARNING);
        return null;
    }

    // 4) Sanity : PEM de clé publique valide
    if (openssl_pkey_get_public($payload['public_key']) === false) {
        dol_syslog("Doli2Shop OAuth: clé publique reçue invalide (openssl)", LOG_WARNING);
        return null;
    }

    // 5) Mise en cache (par entity)
    $entity = isset($conf->entity) ? (int) $conf->entity : 1;
    dolibarr_set_const($db, 'DOLI2SHOP_OAUTH_PUBKEY_ID', (string) $payload['key_id'], 'chaine', 0, '', $entity);
    dolibarr_set_const($db, 'DOLI2SHOP_OAUTH_PUBKEY_PEM', (string) $payload['public_key'], 'chaine', 0, '', $entity);

    return (string) $payload['public_key'];
}

// ===========================================
// HANDLE OAUTH RESPONSE
// ===========================================

/**
 * Redirect to wizard or connect page based on OAuth origin (Story 6.4 — L1 DRY refactor)
 *
 * @param string $errorType Error type code for the wizard structured error display
 * @return void Exits after redirect
 */
function doli2shopOauthErrorRedirect($errorType)
{
    $oauthRedirectErr = isset($_SESSION['oauth_redirect']) ? $_SESSION['oauth_redirect'] : '';
    unset($_SESSION['oauth_redirect']);
    if ($oauthRedirectErr === 'wizard') {
        header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=1&oauth_error_type=' . $errorType);
    } else {
        header('Location: ' . dol_buildpath('/doli2shop/admin/connect_shopify.php', 1));
    }
    exit;
}

$error = GETPOST('error', 'alpha');
$error_description = GETPOST('error_description', 'alphanohtml');

// Check for errors from proxy
if (!empty($error)) {
    dol_syslog("Doli2Shop OAuth: Error received - $error: $error_description", LOG_ERR);
    setEventMessages($langs->trans("OAuthError") . ": " . $error_description, null, 'errors');
    doli2shopOauthErrorRedirect('credentials');
}

// Get credentials from proxy
$shop = GETPOST('shop', 'alphanohtml');
$access_token = GETPOST('access_token', 'alphanohtml');
$scope = GETPOST('scope', 'alphanohtml');
$api_key = GETPOST('api_key', 'alphanohtml');
$api_secret = GETPOST('api_secret', 'alphanohtml');
$signature = GETPOST('signature', 'alphanohtml');
$signature_asym = GETPOST('signature_asym', 'alphanohtml'); // hex RSA (Story 34.6)
$key_id = GETPOST('key_id', 'alphanohtml');
$timestamp = GETPOST('timestamp', 'int');

// Story 51-1 — Jetons expirables : présents UNIQUEMENT si Shopify a basculé ce marchand
// en régime expirable (expiring=1 côté proxy). Absents = comportement rétrocompatible (AC5).
$expires_in = GETPOST('expires_in', 'int');
$refresh_token = GETPOST('refresh_token', 'alphanohtml');
$signature_ext = GETPOST('signature_ext', 'alphanohtml');
$signature_asym_ext = GETPOST('signature_asym_ext', 'alphanohtml');

// Validate required parameters (au moins une signature requise)
if (empty($shop) || empty($access_token) || (empty($signature) && empty($signature_asym))) {
    dol_syslog("Doli2Shop OAuth: Missing required parameters", LOG_ERR);
    setEventMessages($langs->trans("OAuthMissingParameters"), null, 'errors');
    doli2shopOauthErrorRedirect('credentials');
}

// Verify signature
$data_to_verify = $shop . '|' . $access_token . '|' . $scope;

// Story 34.6 — Stratégie de vérification :
// - Si le retour porte une signature ASYMÉTRIQUE (`signature_asym`), le proxy est migré :
//   on vérifie EXCLUSIVEMENT en asymétrique, FAIL-CLOSED. Tout échec (OpenSSL absent, clé
//   publique injoignable, hex invalide, openssl_verify != 1) = REJET. AUCUNE retombée sur
//   le HMAC legacy → impossible de downgrader en omettant/cassant l'asymétrique (HIGH review).
// - Sinon (pas de `signature_asym` = proxy non migré) : HMAC legacy, et UNIQUEMENT si la
//   constante DOLI2SHOP_OAUTH_PROXY_SECRET est explicitement définie (plus de défaut prévisible).
if (!empty($signature_asym)) {
    if (!function_exists('openssl_verify')) {
        dol_syslog("Doli2Shop OAuth: signature asymétrique présente mais OpenSSL absent — rejet", LOG_ERR);
        setEventMessages($langs->trans("OAuthInvalidSignature"), null, 'errors');
        doli2shopOauthErrorRedirect('signature');
    }
    $publicPem = doli2shopFetchOAuthPublicKey($key_id, $oauth_pubkey_url, $db, $conf);
    if ($publicPem === null) {
        // Clé publique indisponible : REJET (pas de downgrade legacy). Réessayer la connexion.
        dol_syslog("Doli2Shop OAuth: clé publique indisponible (key_id=$key_id) — rejet sans downgrade", LOG_ERR);
        setEventMessages($langs->trans("OAuthInvalidSignature"), null, 'errors');
        doli2shopOauthErrorRedirect('signature');
    }
    $rawSig = @hex2bin($signature_asym);
    $verify = ($rawSig !== false)
        ? openssl_verify($data_to_verify, $rawSig, $publicPem, OPENSSL_ALGO_SHA256)
        : -1;
    if ($verify !== 1) { // 1 = OK ; 0 = invalide ; -1 = erreur
        dol_syslog("Doli2Shop OAuth: signature asymétrique invalide (verify=$verify)", LOG_ERR);
        setEventMessages($langs->trans("OAuthInvalidSignature"), null, 'errors');
        doli2shopOauthErrorRedirect('signature');
    }
} else {
    // Proxy non migré → HMAC legacy, sans défaut prévisible
    if ($oauth_proxy_secret === '' || empty($signature)) {
        dol_syslog("Doli2Shop OAuth: aucune signature vérifiable (asym absente, legacy non configuré)", LOG_ERR);
        setEventMessages($langs->trans("OAuthInvalidSignature"), null, 'errors');
        doli2shopOauthErrorRedirect('signature');
    }
    $expected_signature = hash_hmac('sha256', $data_to_verify, $oauth_proxy_secret);
    if (!hash_equals($expected_signature, $signature)) {
        dol_syslog("Doli2Shop OAuth: Invalid signature", LOG_ERR);
        setEventMessages($langs->trans("OAuthInvalidSignature"), null, 'errors');
        doli2shopOauthErrorRedirect('signature');
    }
}

// ===========================================
// Story 51-1 — Vérification de la signature ÉTENDUE (jetons expirables)
// ===========================================
// Stratégie de rétrocompatibilité DOUBLE ASYMÉTRIE (module/proxy déployés séparément) :
// - La signature de BASE ci-dessus (shop|access_token|scope) N'A PAS changé et reste
//   OBLIGATOIRE — c'est elle qui protège shop/access_token/scope, comme avant cette story.
// - expires_in/refresh_token sont authentifiés par une signature SÉPARÉE et INDÉPENDANTE
//   (`signature_ext`/`signature_asym_ext`), couvrant shop|access_token|scope|expires_in|
//   refresh_token. Un échec de vérification sur CETTE signature additionnelle ne fait PAS
//   échouer tout le callback OAuth (le shop/access_token/scope restent authentifiés par la
//   signature de base) : on se contente d'ignorer expires_in/refresh_token (comme si Shopify
//   ne les avait pas renvoyés — AC5), et de logger un WARNING.
//   - proxy à jour + module PAS à jour : le module ignore silencieusement expires_in/
//     refresh_token/signature_ext (pas de GETPOST dessus) → aucune régression.
//   - proxy PAS à jour + module à jour : ces champs sont absents → bloc ci-dessous no-op,
//     token_expires_at/refresh_token restent NULL (comportement historique, AC5).
$tokenExpiresAt = null;
if ($expires_in > 0 && $refresh_token !== '') {
    // FIX HIGH (review 51-1) — anti-replay : timestamp inclus dans la chaîne signée ÉTENDUE
    // (miroir exact de shopify_return.php). La signature de BASE (data_to_verify plus haut,
    // shop|access_token|scope) N'INCLUT PAS le timestamp et reste STRICTEMENT inchangée — des
    // modules déployés avant cette story la vérifient déjà telle quelle (dette tracée :
    // le canal de base reste rejouable comme avant la story, à durcir au retrait du HMAC legacy).
    $data_to_verify_ext = $shop . '|' . $access_token . '|' . $scope . '|' . $expires_in . '|' . $refresh_token . '|' . $timestamp;
    $extSignatureValid = false;
    $extHasSignature = (!empty($signature_asym_ext) || !empty($signature_ext));

    // Fail-closed sur le canal ÉTENDU uniquement : timestamp OBLIGATOIRE dès qu'une signature
    // étendue est fournie, et fraîcheur stricte (>300s = rejet). Un échec ici n'invalide QUE
    // expires_in/refresh_token (branche else ci-dessous) — le reste du callback (shop/
    // access_token/scope, déjà authentifié par la signature de base) n'est jamais impacté.
    if ($extHasSignature && empty($timestamp)) {
        dol_syslog("Doli2Shop OAuth: signature étendue (51-1) présente mais timestamp absent — expires_in/refresh_token ignorés (anti-replay)", LOG_WARNING);
    } elseif ($extHasSignature && (time() - (int) $timestamp) > 300) {
        dol_syslog("Doli2Shop OAuth: signature étendue (51-1) trop ancienne (anti-replay, >300s) — expires_in/refresh_token ignorés", LOG_WARNING);
    } elseif (!empty($signature_asym_ext)) {
        if (function_exists('openssl_verify')) {
            $publicPemExt = doli2shopFetchOAuthPublicKey($key_id, $oauth_pubkey_url, $db, $conf);
            if ($publicPemExt !== null) {
                $rawSigExt = @hex2bin($signature_asym_ext);
                $verifyExt = ($rawSigExt !== false)
                    ? openssl_verify($data_to_verify_ext, $rawSigExt, $publicPemExt, OPENSSL_ALGO_SHA256)
                    : -1;
                $extSignatureValid = ($verifyExt === 1);
            }
        }
    } elseif (!empty($signature_ext) && $oauth_proxy_secret !== '') {
        $expectedSignatureExt = hash_hmac('sha256', $data_to_verify_ext, $oauth_proxy_secret);
        $extSignatureValid = hash_equals($expectedSignatureExt, $signature_ext);
    }

    if ($extSignatureValid) {
        // Marge de sécurité (latence réseau entre réception et 1er usage) — évite un 401
        // immédiat sur un token "juste" expiré.
        $tokenExpiresAt = date('Y-m-d H:i:s', time() + $expires_in - DOLI2SHOP_TOKEN_EXPIRY_MARGIN_SECONDS);
    } else {
        dol_syslog("Doli2Shop OAuth: signature étendue (51-1) absente/invalide — expires_in/refresh_token ignorés (fallback rétrocompatible)", LOG_WARNING);
        $refresh_token = ''; // ne jamais persister un refresh_token non authentifié
    }
}

// Verify timestamp (not older than 5 minutes)
if (!empty($timestamp) && (time() - $timestamp) > 300) {
    dol_syslog("Doli2Shop OAuth: Request expired (timestamp too old)", LOG_ERR);
    setEventMessages($langs->trans("OAuthExpired"), null, 'errors');
    doli2shopOauthErrorRedirect('expired');
}

// Validate shop format
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop)) {
    dol_syslog("Doli2Shop OAuth: Invalid shop format: $shop", LOG_ERR);
    setEventMessages($langs->trans("OAuthInvalidShop"), null, 'errors');
    doli2shopOauthErrorRedirect('shop_format');
}

// ===========================================
// RÉSOLUTION DU CONTEXTE BOUTIQUE (Story 48-6 + hardening review 48-6)
// ===========================================

// Story 48-6 : credentials issus de l'OAuth UNIQUEMENT (aucune saisie manuelle). Toute boutique
// = une ligne `stores`. La boutique PAR DÉFAUT conserve EN PLUS les constantes DOLI2SHOP_* (legacy).
// Contexte posé par connect_shopify : '' = bouton principal (défaut) ; 'new' = nouvelle boutique ;
// 'reconnect:<id>' = reconnexion d'une boutique.
dol_include_once('/doli2shop/class/storeservice.class.php');
$storeService = new StoreService($db);

// H3 : distinguer "clé absente" (session perdue pendant le round-trip OAuth) de "bouton principal"
// (clé présente = '') pour ne JAMAIS écraser la boutique par défaut par accident.
$sessionLost  = !array_key_exists('oauth_store_context', $_SESSION);
$storeContext = $sessionLost ? '' : (string) $_SESSION['oauth_store_context'];
$reconnectId  = (strpos($storeContext, 'reconnect:') === 0) ? (int) substr($storeContext, strlen('reconnect:')) : 0;

$defaultStore     = $storeService->getDefault();
$existingByDomain = $storeService->getByShopDomain($shop);

// Résolution de la cible : $targetStore (objet à mettre à jour, ou null = création),
// $targetsDefaultStore (pilote purge + constantes globales), $createAsDefault (is_default à la création).
$targetStore         = null;
$targetsDefaultStore = false;
$createAsDefault     = false;
$resolveError        = '';

if ($reconnectId > 0) {
    // H1bis : valider l'EXISTENCE de la boutique AVANT (update() renvoie 0, pas <0, sur id inexistant
    // ou hors entité → l'échec serait silencieux). fetch() filtre par entity → pas de cross-entité.
    $targetStore = $storeService->fetch($reconnectId);
    if ($targetStore === null) {
        $resolveError = 'store_failed';
        dol_syslog("Doli2Shop OAuth: reconnexion demandée pour boutique #$reconnectId inexistante/hors entité — abandon", LOG_ERR);
    } else {
        $targetsDefaultStore = ((int) $targetStore->is_default === 1);
    }
} elseif ($storeContext === 'new') {
    // Ajout d'une boutique. Si le domaine est déjà connu → mise à jour (pas de doublon, M1/M2).
    if ($existingByDomain !== null) {
        $targetStore         = $existingByDomain;
        $targetsDefaultStore = ((int) $existingByDomain->is_default === 1);
    } else {
        // 1ère boutique de l'entité = défaut ; sinon secondaire.
        $createAsDefault     = ($defaultStore === null);
        $targetsDefaultStore = $createAsDefault;
    }
} elseif ($sessionLost) {
    // H3 : session perdue → NE JAMAIS écraser la boutique par défaut à l'aveugle.
    if ($existingByDomain !== null) {
        // Le shop revenu correspond à une boutique connue → mettre à jour celle-là (sûr).
        $targetStore         = $existingByDomain;
        $targetsDefaultStore = ((int) $existingByDomain->is_default === 1);
        dol_syslog("Doli2Shop OAuth: contexte session perdu — résolution par domaine sur boutique #" . $existingByDomain->rowid, LOG_WARNING);
    } elseif ($defaultStore === null) {
        // Aucune boutique : 1ère install → créer la défaut.
        $createAsDefault     = true;
        $targetsDefaultStore = true;
    } else {
        // Shop inconnu + défaut existante : ambigu → créer une SECONDAIRE pour préserver la défaut.
        $createAsDefault     = false;
        $targetsDefaultStore = false;
        dol_syslog("Doli2Shop OAuth: contexte session perdu + shop inconnu ($shop) — création boutique secondaire pour préserver la boutique par défaut", LOG_WARNING);
    }
} else {
    // Bouton principal explicite = boutique par défaut (peut changer de shop → purge plus bas).
    $targetStore         = $defaultStore; // null si pas encore créée → création
    $createAsDefault     = ($defaultStore === null);
    $targetsDefaultStore = true;
}

if ($resolveError !== '') {
    unset($_SESSION['oauth_store_context']);
    // Hotfix 2.4.5 (review 3 couches, HIGH) : ce chemin d'échec restait muet alors que sa cause
    // est connue avec certitude (boutique à reconnecter inexistante ou hors entité). Le laisser
    // afficher un « Échec de l'enregistrement des credentials » nu reproduisait exactement le
    // défaut que ce hotfix corrige sur l'autre chemin.
    setEventMessages(
        $langs->trans("OAuthStoreFailed") . ' ' . $langs->trans("OAuthStoreReconnectNotFound", (int) $reconnectId),
        null,
        'errors'
    );
    doli2shopOauthErrorRedirect($resolveError);
}

// ===========================================
// GARDE-FOU : un domaine Shopify = une seule ligne `stores` (hotfix 2.5.0)
// ===========================================
// Nicolas Graillon (3 boutiques, bloqué 10 jours) : le contexte de reconnexion se perdait entre le
// lien GET « Reconnecter via OAuth » (admin/setup.php) et le formulaire POST de connect_shopify.php
// (correctif ci-dessus). La cible ci-dessus ($targetStore) peut donc avoir été mal résolue AVANT ce
// point — ce garde-fou vérifie, juste avant toute écriture, que le domaine réellement reçu de
// Shopify n'appartient pas déjà à UNE AUTRE ligne que $targetStore. Voir le PHPDoc de
// doli2shopResolveOAuthDomainConflict() (lib/doli2shop.lib.php) pour le détail des deux issues.
$domainConflictBeforeRowid = ($targetStore !== null) ? (int) $targetStore->rowid : 0;
$domainConflict = doli2shopResolveOAuthDomainConflict($targetStore, $targetsDefaultStore, $existingByDomain, $reconnectId > 0);

if ($domainConflict['reject']) {
    unset($_SESSION['oauth_store_context']);
    $conflictStore = $domainConflict['conflictStore'];
    $conflictReason = $domainConflict['conflictReason'] !== '' ? $domainConflict['conflictReason'] : 'explicit_target';
    dol_syslog(
        "Doli2Shop OAuth: reconnexion #$reconnectId refusée ($conflictReason) — domaine $shop déjà rattaché à la boutique #"
        . (int) ($conflictStore->rowid ?? 0) . " (\"" . doli2shopStoreDisplayLabel($conflictStore) . "\")",
        LOG_WARNING
    );
    setEventMessages(
        doli2shopBuildOAuthDomainConflictMessage($langs, $shop, $conflictStore, $conflictReason),
        null,
        'errors'
    );
    // HIGH-2 (review) : type d'erreur distinct pour le refus "boutique désactivée" — utile au
    // diagnostic (wizard) sans changer le comportement de doli2shopOauthErrorRedirect() lui-même.
    doli2shopOauthErrorRedirect($conflictReason === 'inactive_owner' ? 'domain_conflict_inactive' : 'domain_conflict');
}

$targetStore         = $domainConflict['targetStore'];
$targetsDefaultStore = $domainConflict['targetsDefaultStore'];

$domainConflictAfterRowid = ($targetStore !== null) ? (int) $targetStore->rowid : 0;
// HIGH-1 (review post-hotfix) : le reciblage implicite doit être VISIBLE à l'écran, pas seulement
// dans dolibarr.log — sinon un succès générique masquerait qu'une AUTRE boutique que celle visée a
// été mise à jour (motif identifié par la rétrospective epic 59 comme le plus coûteux du projet).
// Message émis APRÈS le commit (avec $purgedShopChange plus bas) : avant le commit, l'écriture
// réelle n'a pas encore eu lieu — l'afficher plus tôt annoncerait un reciblage qui pourrait au
// final échouer et être rollback.
$domainRetargeted = ($domainConflictAfterRowid !== $domainConflictBeforeRowid);
if ($domainRetargeted) {
    dol_syslog(
        "Doli2Shop OAuth: contexte de reconnexion résolu implicitement vers une cible incorrecte pour"
        . " le domaine $shop — reciblage automatique sur la boutique #$domainConflictAfterRowid"
        . " (propriétaire légitime du domaine, targetsDefault=" . ($targetsDefaultStore ? '1' : '0') . ")",
        LOG_WARNING
    );
}

$previous_shop = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '');

// ===========================================
// TRANSACTION UNIQUE : purge (si défaut + shop changé) + upsert + constantes (C2)
// Les begin/commit internes de StoreService s'imbriquent (compteur de profondeur DoliDB) ;
// un rollback externe annule l'ensemble → atomicité purge+credentials garantie.
// ===========================================

dol_syslog("Doli2Shop OAuth: Storing credentials for $shop (targetsDefault=" . ($targetsDefaultStore ? '1' : '0') . ")", LOG_INFO);

$db->begin();
$errors = 0;
$purgedShopChange = false;

// Hotfix 2.4.5 (AC1 — Validate correction #1) : cause technique SQL de l'échec, capturée
// IMMÉDIATEMENT à chaque point d'incrément de $errors (ligne juste après le dol_syslog(...,
// LOG_ERR) existant), AVANT toute autre requête ($db->query()/rollback()) susceptible d'écraser
// mysqli->error. Relire lasterror() APRÈS le rollback() (l.499 plus bas) serait faux : le
// rollback lui-même est une requête. Volontairement PAS un getter sur StoreService (create()/
// update() peuvent être appelés hors de ce contexte transactionnel) : simple variable locale.
$storeFailureDetail = '';

// Purge SEULEMENT si on (re)connecte la BOUTIQUE PAR DÉFAUT vers un autre shop.
// En multi-boutiques, ajouter/reconnecter une boutique secondaire ne purge JAMAIS la sync globale.
if ($targetsDefaultStore && !empty($previous_shop) && $previous_shop !== $shop) {
    dol_syslog("Doli2Shop OAuth: Shop changed from $previous_shop to $shop - Purging sync tables", LOG_WARNING);

    // Story deconnexion-oauth-ignore-le-multi-boutiques (AC3, réserve n°2 + n°4 du Validate) :
    // `doli2shop_products_mapping` et `doli2shop_collections_mapping` n'existent dans AUCUN
    // fichier sql/ du dépôt — code mort retiré, sans y substituer de nouvelle purge
    // (llx_doli2shop_collections n'a d'ailleurs pas de colonne fk_store). Seule
    // llx_doli2shop_orders existe réellement et est purgée, bornée à la boutique par défaut
    // (fk_store), pas à toute l'entité — même classe de défaut que disconnect_oauth.php.
    $tables_to_purge = array(
        MAIN_DB_PREFIX . 'doli2shop_orders',
    );
    $purgeStoreId = ($targetStore !== null) ? (int) $targetStore->rowid : 0;

    // Review 3 couches du 09/09/2026 (HIGH, bord n°1) — MÊME classe de défaut que
    // disconnect_oauth.php, corrigée ici aussi plutôt que laissée en dette : le filtre `fk_store`
    // strict rate les lignes restées à `fk_store = 0` quand le backfill Epic 47-2
    // (StoreService::backfillTechnicalTables(), chaque UPDATE ayant sa propre gestion d'erreur
    // SANS transaction globale) a partiellement échoué. Ces orphelines ne sont incluses QUE si la
    // cible est la boutique par défaut ET qu'aucune autre boutique n'existe — mono-boutique
    // garanti par construction, donc aucune ambiguïté d'appartenance. En multi-boutiques, une
    // orpheline peut appartenir à une secondaire : le filtre strict reste, un DELETE est
    // irréversible.
    $includeOrphanedStoreRows = ($purgeStoreId > 0) && doli2shopShouldPurgeOrphanedStoreRows(
        $storeService->getAll(),
        $targetsDefaultStore
    );

    foreach ($tables_to_purge as $table) {
        $sql_check = "SHOW TABLES LIKE '" . $db->escape($table) . "'";
        $resql_check = $db->query($sql_check);
        if ($resql_check && $db->num_rows($resql_check) > 0) {
            $sql = doli2shopBuildOAuthPurgeSql($table, (int) $conf->entity, $purgeStoreId, $includeOrphanedStoreRows);
            $resql = $db->query($sql);
            if ($resql) {
                $deleted = $db->affected_rows($resql);
                dol_syslog("Doli2Shop OAuth: Purged $deleted rows from $table (store #$purgeStoreId)", LOG_INFO);
            } else {
                $errors++;
                // Review 3 couches (MEDIUM) : plusieurs tables peuvent échouer dans cette boucle.
                // Une simple affectation ne garderait que la DERNIÈRE cause ; on les accumule pour
                // que l'écran porte l'information complète, pas seulement dolibarr.log.
                $purgeFailure = $db->lasterror();
                $storeFailureDetail = ($storeFailureDetail === '')
                    ? $purgeFailure
                    : $storeFailureDetail . ' | ' . $purgeFailure;
                dol_syslog("Doli2Shop OAuth: Failed to purge $table: " . $purgeFailure, LOG_ERR);
            }
        }
    }

    if ($errors === 0) {
        $purgedShopChange = true; // message différé après commit
    }
} elseif (!empty($previous_shop) && $previous_shop === $shop) {
    dol_syslog("Doli2Shop OAuth: Reconnecting to same shop $shop - keeping sync data", LOG_INFO);
}

// Upsert de la ligne `stores`.
$storeData = array(
    'shop_domain'  => $shop,
    'access_token' => $access_token,
    'api_key'      => $api_key,
    'api_secret'   => $api_secret,
    'active'       => 1,
);

// Story 51-1 : uniquement si présents et authentifiés (signature étendue valide) — ne JAMAIS
// écraser un couple existant par NULL si Shopify ne renvoie rien sur une reconnexion (le champ
// est simplement absent du tableau, StoreService::update() ne le touche pas — array_key_exists).
if ($tokenExpiresAt !== null && $refresh_token !== '') {
    $storeData['token_expires_at'] = $tokenExpiresAt;
    $storeData['refresh_token'] = $refresh_token;
}

if ($errors === 0) {
    if ($targetStore !== null) {
        if ($storeService->update((int) $targetStore->rowid, $storeData) < 0) {
            $errors++;
            // AC1 : lasterror() lu ICI, immédiatement après l'échec constaté par update() lui-même
            // (qui a déjà rollback sa PROPRE sous-transaction interne, cf. begin/commit imbriqués
            // l.390-393) — avant toute autre requête côté oauth_receive.php.
            $storeFailureDetail = $db->lasterror();
            dol_syslog("Doli2Shop OAuth: échec mise à jour boutique #" . $targetStore->rowid . ": " . $storeFailureDetail, LOG_ERR);
        }
    } else {
        $storeData['label']      = $shop;
        $storeData['is_default'] = $createAsDefault ? 1 : 0;
        if ($storeService->create($storeData) <= 0) {
            $errors++;
            $storeFailureDetail = $db->lasterror();
            dol_syslog("Doli2Shop OAuth: échec création boutique $shop (is_default=" . $storeData['is_default'] . "): " . $storeFailureDetail, LOG_ERR);
        }
    }
}

// Constantes DOLI2SHOP_* : UNIQUEMENT pour la boutique par défaut (une boutique secondaire
// ne doit jamais écraser la config globale historique).
if ($targetsDefaultStore && $errors === 0) {
    // Hotfix 2.4.5 (AC1) : capture immédiate de $storeFailureDetail, avant toute autre requête.
    // Story 58-3 (AC1) : API_KEY/API_SECRET_KEY/OAUTH_SCOPES ci-dessous INCRÉMENTENT désormais
    // aussi $errors sur échec réel d'écriture (le "silencieux connu et préexistant" du hotfix
    // 2.4.5 est comblé — cf. bloc plus bas, même pattern via doli2shopIsOAuthConstantWriteFailure()).
    if (dolibarr_set_const($db, 'DOLI2SHOP_STORE_HOSTNAME', $shop, 'chaine', 0, '', $conf->entity) <= 0) {
        $errors++;
        $storeFailureDetail = $db->lasterror();
        dol_syslog("Doli2Shop OAuth: échec dolibarr_set_const DOLI2SHOP_STORE_HOSTNAME: " . $storeFailureDetail, LOG_ERR);
    }
    if (dolibarr_set_const($db, 'DOLI2SHOP_ACCESS_TOKEN', $access_token, 'chaine', 0, '', $conf->entity) <= 0) {
        $errors++;
        $storeFailureDetail = $db->lasterror();
        dol_syslog("Doli2Shop OAuth: échec dolibarr_set_const DOLI2SHOP_ACCESS_TOKEN: " . $storeFailureDetail, LOG_ERR);
    }
    // Story 58-3 (AC1 — Validate correction #1) : ces 3 écritures étaient jusqu'ici NUES — un
    // échec de dolibarr_set_const() y passait inaperçu (connexion annoncée réussie, config
    // partielle, symptômes visibles seulement à la 1ère synchronisation). Même pattern que
    // STORE_HOSTNAME/ACCESS_TOKEN ci-dessus : $errors++ + capture $storeFailureDetail +
    // dol_syslog(LOG_ERR). !empty($x) reste la garde d'ENTRÉE (valeur absente = simplement omise,
    // comportement historique inchangé) ; c'est le RETOUR de dolibarr_set_const() après cette
    // garde qui distingue l'échec d'écriture — décision extraite en fonction PURE
    // doli2shopIsOAuthConstantWriteFailure() (lib/doli2shop.lib.php) car test/bootstrap.php stub
    // dolibarr_set_const() en retournant TOUJOURS 1 (l'échec n'est pas simulable autrement).
    if (!empty($api_key)) {
        $apiKeyWriteResult = dolibarr_set_const($db, 'DOLI2SHOP_API_KEY', $api_key, 'chaine', 0, '', $conf->entity);
        if (doli2shopIsOAuthConstantWriteFailure((string) $api_key, (int) $apiKeyWriteResult)) {
            $errors++;
            $storeFailureDetail = $db->lasterror();
            dol_syslog("Doli2Shop OAuth: échec dolibarr_set_const DOLI2SHOP_API_KEY: " . $storeFailureDetail, LOG_ERR);
        }
    }
    if (!empty($api_secret)) {
        $apiSecretWriteResult = dolibarr_set_const($db, 'DOLI2SHOP_API_SECRET_KEY', $api_secret, 'chaine', 0, '', $conf->entity);
        if (doli2shopIsOAuthConstantWriteFailure((string) $api_secret, (int) $apiSecretWriteResult)) {
            $errors++;
            $storeFailureDetail = $db->lasterror();
            dol_syslog("Doli2Shop OAuth: échec dolibarr_set_const DOLI2SHOP_API_SECRET_KEY: " . $storeFailureDetail, LOG_ERR);
        }
    }
    if (!empty($scope)) {
        $scopesWriteResult = dolibarr_set_const($db, 'DOLI2SHOP_OAUTH_SCOPES', $scope, 'chaine', 0, '', $conf->entity);
        if (doli2shopIsOAuthConstantWriteFailure((string) $scope, (int) $scopesWriteResult)) {
            $errors++;
            $storeFailureDetail = $db->lasterror();
            dol_syslog("Doli2Shop OAuth: échec dolibarr_set_const DOLI2SHOP_OAUTH_SCOPES: " . $storeFailureDetail, LOG_ERR);
        }
    }
    dolibarr_set_const($db, 'DOLI2SHOP_OAUTH_CONNECTED_AT', date('Y-m-d H:i:s'), 'chaine', 0, '', $conf->entity);

    // Story 51-1 — Jetons expirables (AC2) : équivalent constantes pour la boutique par défaut
    if ($tokenExpiresAt !== null && $refresh_token !== '') {
        // (CORRECTION VALIDATE P2) alerte précoce de troncature silencieuse (colonne refresh_token varchar(512))
        if (strlen($refresh_token) > 500) {
            dol_syslog("Doli2Shop OAuth: refresh_token > 500 caractères, risque de troncature silencieuse (constante DOLI2SHOP_REFRESH_TOKEN)", LOG_WARNING);
        }
        dolibarr_set_const($db, 'DOLI2SHOP_TOKEN_EXPIRES_AT', $tokenExpiresAt, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'DOLI2SHOP_REFRESH_TOKEN', $refresh_token, 'chaine', 0, '', $conf->entity);
    }
}

// Story 49-5 : pour les boutiques SECONDAIRES, écrire DOLI2SHOP_OAUTH_CONNECTED_AT_<rowid>
// La boutique par défaut a déjà DOLI2SHOP_OAUTH_CONNECTED_AT (bloc ci-dessus).
if (!$targetsDefaultStore && $errors === 0 && $targetStore !== null && (int) $targetStore->rowid > 0) {
    dolibarr_set_const($db, 'DOLI2SHOP_OAUTH_CONNECTED_AT_'.(int) $targetStore->rowid, date('Y-m-d H:i:s'), 'chaine', 0, '', $conf->entity);
}

// Contexte consommé
unset($_SESSION['oauth_store_context']);

if ($errors > 0) {
    $db->rollback();
    dol_syslog("Doli2Shop OAuth: Failed to store credentials (purge+upsert rolled back)", LOG_ERR);

    // Hotfix 2.4.5 (AC1 + AC3) : message auto-explicatif — cause SQL réelle ($storeFailureDetail,
    // capturée AVANT ce rollback, cf. les 4 points d'incrément plus haut) + état du schéma de la
    // table des boutiques (détection d'une migration non appliquée).
    //
    // Échappement : les valeurs dynamiques sont passées en PARAMÈTRES de $langs->trans(), qui
    // applique htmlentities() au résultat d'une clé de traduction connue. Ce n'est vrai QUE par
    // ce chemin : concaténer ici une donnée brute hors d'un trans() ouvrirait une faille XSS
    // (review 3 couches — le commentaire précédent « trans() échappe tout » était trop vague).
    //
    // AC2 — Zéro fuite de secret : $db->lasterror() (driver mysqli, cf. core/db/mysqli.class.php)
    // retourne UNIQUEMENT le texte d'erreur MySQL (ex. "Unknown column 'x' in 'field list'"),
    // JAMAIS la requête exécutée. C'est $db->lastqueryerror() qui contiendrait le SQL complet
    // (donc les credentials) — INTERDIT sur ce chemin, volontairement jamais appelé ici.
    $storesSchemaCheck = doli2shopCheckStoresTableSchema($db);
    $errorMessage = doli2shopBuildOAuthStoreFailureMessage($langs, $storeFailureDetail, $storesSchemaCheck);

    setEventMessages($errorMessage, null, 'errors');
    doli2shopOauthErrorRedirect('store_failed');
}

$db->commit();

if ($purgedShopChange) {
    setEventMessages($langs->trans("OAuthShopChangedPurged", $previous_shop, $shop), null, 'warnings');
}

// HIGH-1 (review) : $targetStore porte ici la boutique RÉELLEMENT écrite (après reciblage, avant
// tout autre réassignement possible dans ce fichier — aucun ne survient plus bas).
if ($domainRetargeted && $targetStore !== null) {
    setEventMessages(doli2shopBuildOAuthDomainRetargetedMessage($langs, $shop, $targetStore), null, 'warnings');
}

dol_syslog("Doli2Shop OAuth: Credentials stored successfully for $shop", LOG_INFO);

// ===========================================
// FETCH ADDITIONAL INFO (Location ID, etc.)
// ===========================================

try {
    // Reload conf to get new values
    $conf->global->DOLI2SHOP_STORE_HOSTNAME = $shop;
    $conf->global->DOLI2SHOP_ACCESS_TOKEN = $access_token;
    if (!empty($api_key)) {
        $conf->global->DOLI2SHOP_API_KEY = $api_key;
    }
    if (!empty($api_secret)) {
        $conf->global->DOLI2SHOP_API_SECRET_KEY = $api_secret;
    }

    // Try to fetch shop info and location
    $shopifyApi = new ShopifyApi($db);

    // Get locations (check if method exists first)
    if (method_exists($shopifyApi, 'getLocations')) {
        $locations = $shopifyApi->getLocations();
        if (!empty($locations) && is_array($locations)) {
            // Use first location as default
            $first_location = reset($locations);
            if (!empty($first_location['id'])) {
                $location_id = $first_location['id'];
                // Extract numeric ID if it's a GID
                if (strpos($location_id, 'gid://') !== false) {
                    $location_id = preg_replace('/.*\//', '', $location_id);
                }
                dolibarr_set_const($db, 'DOLI2SHOP_LOCATION_ID', $location_id, 'chaine', 0, '', $conf->entity);
                dol_syslog("Doli2Shop OAuth: Auto-configured location ID: $location_id", LOG_INFO);
            }
        }
    } else {
        dol_syslog("Doli2Shop OAuth: getLocations method not available, skipping auto-configuration", LOG_INFO);
    }

    // Get shop info (check if method exists first)
    if (method_exists($shopifyApi, 'getShopInfo')) {
        $shop_info = $shopifyApi->getShopInfo();
        if (!empty($shop_info)) {
            // Store shop name as vendor if not set
            if (empty(getDolGlobalString('DOLI2SHOP_VENDOR')) && !empty($shop_info['name'])) {
                dolibarr_set_const($db, 'DOLI2SHOP_VENDOR', $shop_info['name'], 'chaine', 0, '', $conf->entity);
            }
        }
    }
} catch (Exception $e) {
    // Non-critical, just log
    dol_syslog("Doli2Shop OAuth: Failed to fetch additional info: " . $e->getMessage(), LOG_WARNING);
} catch (Error $e) {
    // Catch PHP errors too (like undefined methods)
    dol_syslog("Doli2Shop OAuth: Error fetching additional info: " . $e->getMessage(), LOG_WARNING);
}

// ===========================================
// AUTO-REGISTER WEBHOOKS (v2.1.8)
// ===========================================

try {
    require_once dirname(__FILE__) . '/../class/shopifywebhooks.class.php';

    $shopifyWebhooksAuto = new ShopifyWebhooks($db);

    // Topics par défaut à enregistrer automatiquement
    $defaultTopics = array(
        'products/create',
        'products/update',
        'products/delete',
        'orders/create',
        'orders/updated',
        'orders/cancelled',
        'orders/fulfilled',
        'orders/paid',
        'inventory_levels/update',
        'app/uninstalled'
    );

    // Vérifier si c'est une reconnexion (shop déjà connu)
    $existingWebhooksCount = 0;
    $sqlCount = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks WHERE status = 1 AND entity = " . ((int) $conf->entity);
    $resqlCount = $db->query($sqlCount);
    if ($resqlCount) {
        $objCount = $db->fetch_object($resqlCount);
        $existingWebhooksCount = (int) $objCount->nb;
        $db->free($resqlCount);
    }

    if ($existingWebhooksCount > 0) {
        // Reconnexion : synchroniser pour vérifier/recréer les webhooks manquants
        dol_syslog("Doli2Shop OAuth: Reconnection detected, syncing webhooks (" . $existingWebhooksCount . " existing)", LOG_INFO);
        // Story 59-9 (correctif review) : reconnexion OAuth = action humaine explicite (l'utilisateur
        // vient de réautoriser l'app, potentiellement pour corriger la cause d'un refus durable) —
        // `true` garantit que le disjoncteur d'échecs consécutifs ne l'ignore jamais, contrairement
        // au CRON périodique (cronCheckWebhookHealth() passe false).
        $shopifyWebhooksAuto->syncWebhooks($defaultTopics, true);
    } else {
        // Nouvelle installation : créer tous les webhooks
        dol_syslog("Doli2Shop OAuth: New installation, auto-registering webhooks", LOG_INFO);
        $webhookSuccessCount = 0;
        $webhookFailCount = 0;

        foreach ($defaultTopics as $webhookTopic) {
            $webhookResult = $shopifyWebhooksAuto->createWebhookWithDatabase($webhookTopic);
            if ($webhookResult > 0) {
                $webhookSuccessCount++;
                dol_syslog("Doli2Shop OAuth: Webhook registered: " . $webhookTopic, LOG_INFO);
            } else {
                $webhookFailCount++;
                dol_syslog("Doli2Shop OAuth: Failed to register webhook: " . $webhookTopic . " - " . implode(', ', $shopifyWebhooksAuto->errors), LOG_WARNING);
                $shopifyWebhooksAuto->errors = array(); // Reset pour le prochain topic
            }
        }

        dol_syslog("Doli2Shop OAuth: Auto-registered webhooks: " . $webhookSuccessCount . " success, " . $webhookFailCount . " failed", LOG_INFO);
    }
} catch (Exception $e) {
    // Non-bloquant : l'utilisateur pourra les configurer manuellement dans admin/webhooks.php
    dol_syslog("Doli2Shop OAuth: Failed to auto-register webhooks: " . $e->getMessage(), LOG_WARNING);
} catch (Error $e) {
    dol_syslog("Doli2Shop OAuth: Error auto-registering webhooks: " . $e->getMessage(), LOG_WARNING);
}

// ===========================================
// REDIRECT TO SUCCESS PAGE
// ===========================================

setEventMessages($langs->trans("OAuthSuccess", $shop), null, 'mesgs');

// Check if OAuth was initiated from wizard (Story 6.1 — Task 3.2)
$oauthRedirect = isset($_SESSION['oauth_redirect']) ? $_SESSION['oauth_redirect'] : '';
unset($_SESSION['oauth_redirect']);

if ($oauthRedirect === 'wizard') {
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=1&oauth_success=1');
} else {
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup.php', 1) . '?oauth_success=1');
}
exit;
