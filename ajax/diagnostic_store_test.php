<?php
/**
 * @file        ajax/diagnostic_store_test.php
 * @brief       AJAX endpoint — test API Shopify (connexion + scopes + canaux) PAR BOUTIQUE,
 *              différé depuis admin/health.php et admin/diagnostic.php (Story 50-3).
 *
 * Appelé après le rendu de la page pour éviter N appels API synchrones (timeout Guzzle)
 * au chargement. Ne fait JAMAIS aucun appel réseau tant qu'il n'est pas invoqué explicitement
 * par le JS (js/diagnostic_store_test.js). AUCUN secret (token, api_secret) n'est jamais
 * renvoyé dans la réponse.
 *
 * @package     ShopifyIntegration
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.4.1
 * @link        https://doli2shop.ptitetete.org
 */

// Story 59-7 : ne pas faire tourner le jeton CSRF sur ce endpoint AJAX. C'est très exactement le
// chemin identifié par l'investigation 59-1 comme cause du blocage client : admin/health.php
// déclenche un appel AJAX AUTOMATIQUE par boutique connectée (js/diagnostic_store_test.js), et
// chacun de ces appels faisait tourner le jeton — chez un client à 3 boutiques, ouvrir Santé
// suffisait à périmer les liens d'action de Webhooks sans même cliquer. NOTOKENRENEWAL
// désactive la ROTATION (main.inc.php:333-349) ; la VÉRIFICATION (verifToken(), plus bas) reste
// intégralement active — cet endpoint continue de rejeter tout appel sans jeton valide.
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'Include of main fails'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Security check — admin only (avant tout chargement de classe métier)
if (!$user->admin) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('success' => false, 'message' => 'Access forbidden'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// CSRF token verification
// Hotfix 2.4.6 (2/2) : réimplémentait en dur la comparaison au mauvais jeton (`newToken()` =
// jeton de la PROCHAINE requête, jamais celui réellement soumis) — cassé en silence dès que
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégué au helper partagé
// verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
if (!verifToken()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('success' => false, 'message' => 'Invalid CSRF token'), JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

$langs->load('admin');
$langs->load('doli2shop@doli2shop');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__FILE__) . '/../class/storeservice.class.php';
require_once dirname(__FILE__) . '/../class/configurationMigrator.class.php';
require_once dirname(__FILE__) . '/../class/shopifyapi.class.php';

/**
 * Construit un résumé sûr (sans secret) des canaux de vente (publications) Shopify.
 * Copie condensée de testChannels() (admin/health.php / admin/diagnostic.php) — n'expose
 * jamais le JSON brut des publications (réservé à l'export ?format=json synchrone).
 *
 * @param  ShopifyApi $shopifyApi Instance de l'API Shopify (globale ou ::forStore())
 * @param  Translate  $langs      Objet de traduction
 * @return array{status: string, count: int, names: string[], message: string}
 */
function diagnosticAjaxChannelsSummary($shopifyApi, $langs)
{
    try {
        $publications = $shopifyApi->getPublications();
    } catch (\Throwable $e) {
        return array(
            'status'  => 'error',
            'count'   => 0,
            'names'   => array(),
            // Message déjà formaté (traduit) : le JS l'affiche tel quel, sans i18n côté client.
            'message' => $langs->transnoentities('DiagnosticChannelsErrorPrefix', $e->getMessage()),
        );
    }

    if (empty($publications)) {
        return array(
            'status'  => 'warning',
            'count'   => 0,
            'names'   => array(),
            'message' => $langs->transnoentities('DiagnosticChannelsNone'),
        );
    }

    $channelNames = array();
    foreach ($publications as $pub) {
        $name = html_entity_decode((string) $pub['name'], ENT_QUOTES, 'UTF-8');
        if (!empty($pub['app_title'])) {
            $name .= ' (' . html_entity_decode((string) $pub['app_title'], ENT_QUOTES, 'UTF-8') . ')';
        }
        $channelNames[] = $name;
    }

    $displayNames = array_slice($channelNames, 0, 5);
    $namesJoined = implode(', ', $displayNames);
    if (count($channelNames) > 5) {
        $namesJoined .= '... (+' . (count($channelNames) - 5) . ')';
    }

    return array(
        'status'  => 'success',
        'count'   => count($channelNames),
        'names'   => $displayNames,
        // Message déjà formaté (traduit + noms inclus) : le JS l'affiche tel quel.
        'message' => $langs->transnoentities('DiagnosticChannelsAvailable', (string) count($channelNames), $namesJoined),
    );
}

/**
 * Traduit un résultat brut de ShopifyApi::testShopifyConnection() vers une structure JSON
 * sûre (whitelist de champs, jamais de token/secret, descriptions et catégories déjà traduites).
 *
 * @param  array     $connResult Résultat brut de testShopifyConnection()
 * @param  Translate $langs      Objet de traduction
 * @return array Structure JSON-safe
 */
function diagnosticAjaxMapConnectionResult(array $connResult, $langs)
{
    $mapped = array(
        'connection'       => !empty($connResult['connection']),
        'shop_info'        => null,
        'scopes_analysis'  => array(),
        'required_missing' => 0,
        'optional_missing' => 0,
        'total_scopes'     => 0,
        'errors'           => array(),
    );

    if (!empty($connResult['shop_info'])) {
        $mapped['shop_info'] = array(
            'name'          => isset($connResult['shop_info']->name) ? (string) $connResult['shop_info']->name : '',
            'currency_code' => isset($connResult['shop_info']->currencyCode) ? (string) $connResult['shop_info']->currencyCode : '',
        );
    }

    if (!empty($connResult['scopes_analysis'])) {
        foreach ($connResult['scopes_analysis'] as $scope) {
            $required = !empty($scope['required']);
            $present  = !empty($scope['present']);
            $mapped['total_scopes']++;
            if (!$present) {
                if ($required) {
                    $mapped['required_missing']++;
                } else {
                    $mapped['optional_missing']++;
                }
            }
            // Story 50-3 — labels et classes CSS déjà résolus côté serveur (mêmes règles que
            // renderShopifyScopesTable() dans admin/health.php / admin/diagnostic.php) : le JS
            // n'a besoin d'aucune table d'i18n, il affiche ces chaînes telles quelles.
            if ($present) {
                $statusClass = 'success';
                $statusLabel = '✓ ' . $langs->transnoentities('ScopeGranted');
            } elseif ($required) {
                $statusClass = 'error';
                $statusLabel = '✗ ' . $langs->transnoentities('ScopeMissing');
            } else {
                $statusClass = 'warning';
                $statusLabel = '! ' . $langs->transnoentities('ScopeNotGrantedOptional');
            }
            $requiredClass = $present ? 'success' : ($required ? 'error' : 'warning');
            $requiredLabel = $required ? $langs->transnoentities('RequiredScope') : $langs->transnoentities('OptionalScope');

            $mapped['scopes_analysis'][] = array(
                'category'       => isset($scope['category']) ? (string) $scope['category'] : '',
                'category_label' => $langs->transnoentities('Scope' . ucfirst((string) ($scope['category'] ?? ''))),
                'description'    => $langs->transnoentities((string) ($scope['description'] ?? '')),
                'required'       => $required,
                'present'        => $present,
                'status_class'   => $statusClass,
                'status_label'   => $statusLabel,
                'required_class' => $requiredClass,
                'required_label' => $requiredLabel,
            );
        }
    }

    if (!empty($connResult['errors'])) {
        foreach ($connResult['errors'] as $err) {
            $mapped['errors'][] = (string) $err;
        }
    }

    return $mapped;
}

// ── Résolution + validation de la boutique (entity-scopée) ──────────────────────────────────
// FIX Story 50-17 : GETPOST(..., 'int') retourne une numeric-string, jamais un int réel —
// la comparaison stricte === 0 échouait TOUJOURS ("0" !== 0), y compris pour la boutique
// légacy (store_id=0, chemin mono-boutique) qui tombait alors à tort dans le fetch() par
// rowid (rowid=0 inexistant → 403 "Invalid store_id"). GETPOSTINT() cast en int réel.
$storeId = GETPOSTINT('store_id');
$store = null;
$isLegacyDefault = ($storeId === 0);

if (!$isLegacyDefault) {
    if ($storeId < 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Missing or invalid store_id'), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    $storeService = new StoreService($db);
    $store = $storeService->fetch($storeId);
    if ($store === null || (int) $store->entity !== (int) $conf->entity) {
        http_response_code(403);
        echo json_encode(array('success' => false, 'message' => 'Invalid store_id'), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
    // Défense en profondeur : une boutique désactivée entre le rendu de la page et l'appel
    // AJAX ne doit plus être testable via cet endpoint (getAll(true) au rendu ne l'aurait
    // de toute façon pas exposée).
    if (empty($store->active)) {
        http_response_code(403);
        echo json_encode(array('success' => false, 'message' => 'Store is not active'), JSON_HEX_TAG | JSON_HEX_AMP);
        exit;
    }
}

// ── Test (connexion + scopes + canaux), jamais d'exception non catchée ───────────────────────
// Story 50-3 — labels statiques inclus dans CHAQUE réponse : le JS reste sans aucune table
// d'i18n propre, il affiche ces chaînes (déjà traduites) telles quelles.
$staticLabels = array(
    'connectionSuccess' => $langs->transnoentities('ShopifyConnectionSuccess'),
    'connectionFailed'  => $langs->transnoentities('ShopifyConnectionFailed'),
    'shopInfo'          => $langs->transnoentities('ShopInfo'),
    'scopeStatus'       => $langs->transnoentities('ShopifyScopeStatus'),
    'permission'        => $langs->transnoentities('Permission'),
    'requiredHeader'    => $langs->transnoentities('RequiredScope'),
    'statusHeader'      => $langs->transnoentities('Status'),
    'pendingMessage'    => $langs->transnoentities('StoreNotConnectedYet'),
);

$result = array(
    'success'          => true,
    'store_id'         => $storeId,
    'connection'       => false,
    'pending'          => false,
    'shop_info'        => null,
    'scopes_analysis'  => array(),
    'required_missing' => 0,
    'optional_missing' => 0,
    'total_scopes'     => 0,
    'channels'         => array('status' => 'skipped', 'count' => 0, 'names' => array(), 'message' => ''),
    'errors'           => array(),
    'labels'           => $staticLabels,
);

try {
    if ($isLegacyDefault) {
        $migrator = new ConfigurationMigrator($db);
        $config = $migrator->getConfiguration($conf->entity);
        if (empty($config['shopify_access_token']) || empty($config['shopify_store_hostname'])) {
            $result['pending'] = true;
            $result['errors'][] = $langs->transnoentities('ShopifyConfigIncomplete');
        } else {
            $shopifyApiInstance = new ShopifyApi($db);
            if (!empty($shopifyApiInstance->error)) {
                $result['errors'][] = (string) $shopifyApiInstance->error;
            } else {
                $connResult = $shopifyApiInstance->testShopifyConnection();
                $result = array_merge($result, diagnosticAjaxMapConnectionResult($connResult, $langs));
                if (!empty($connResult['connection'])) {
                    $result['channels'] = diagnosticAjaxChannelsSummary($shopifyApiInstance, $langs);
                }
            }
        }
    } else {
        if (empty($store->access_token) || empty($store->shop_domain)) {
            $result['pending'] = true;
            $result['errors'][] = $langs->transnoentities('StoreNotConnectedYet');
        } else {
            $shopifyApiInstance = ShopifyApi::forStore($db, $store);
            if (!empty($shopifyApiInstance->error)) {
                $result['errors'][] = (string) $shopifyApiInstance->error;
            } else {
                $connResult = $shopifyApiInstance->testShopifyConnection();
                $result = array_merge($result, diagnosticAjaxMapConnectionResult($connResult, $langs));
                if (!empty($connResult['connection'])) {
                    $result['channels'] = diagnosticAjaxChannelsSummary($shopifyApiInstance, $langs);
                }
            }
        }
    }
} catch (\Throwable $e) {
    $result['success'] = false;
    $result['errors'][] = $e->getMessage();
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
