<?php
/**
 * @file        admin/health.php
 * @brief       Onglet Santé & diagnostic unifié — synthèse par boutique + diagnostic complet + support (Story 49-6)
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.3.2
 * @link        https://doli2shop.ptitetete.org
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
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
require_once '../lib/doli2shop.lib.php';
// Story 50-17 (fix prod) : autoloader Composer du module chargé EXPLICITEMENT — avant la 50-3
// l'autoload était chargé en effet de bord par l'instanciation synchrone de ShopifyApi/Guzzle,
// supprimée par le report des tests API en AJAX (fatal en prod). checkDolibarrApi() n'instancie
// plus Guzzle depuis la Story 57-4 (accès direct base+disque) ; ce require explicite reste
// nécessaire tant qu'une classe Composer peut être utilisée en dehors du chemin AJAX différé.
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once '../class/configurationMigrator.class.php';
require_once '../class/supportmanager.class.php';
require_once '../class/storeservice.class.php';
require_once '../class/shopifyrightsChecker.class.php';
// Story 57-4 : diagnostic photos par accès direct base+disque, plus par API REST self.
require_once '../class/dolibarrdirectfileresolver.class.php';
dol_include_once('/product/class/product.class.php');
dol_include_once('/categories/class/categorie.class.php');

// Contrôle d'accès
if (!$user->admin) {
    accessforbidden();
}

$langs->load('admin');
$langs->load('doli2shop@doli2shop');

// ── HANDLERS POST ────────────────────────────────────────────────────────────
$action = GETPOST('action', 'aZ09');
$format = GETPOST('format', 'alpha');

// Story 59-2 (AC1/AC2) : ces 3 actions faisaient sauter leur bloc EN SILENCE sur un jeton refusé
// (aucun message, aucune trace, aucun effet). Même garde-fou que admin/diagnostic.php : message
// écran + trace journal (LOG_WARNING) avant le bloc d'action. Parité stricte maintenue entre les
// deux pages (mêmes 3 actions, mêmes messages).
if ($action === 'cleanup_cron_duplicates' && !verifToken()) {
    dol_syslog("health.php: action 'cleanup_cron_duplicates' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action === 'activate_cron' && !verifToken()) {
    dol_syslog("health.php: action 'activate_cron' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action === 'recreate_cron' && !verifToken()) {
    dol_syslog("health.php: action 'recreate_cron' refused for user " . $user->login . " (invalid/expired CSRF token)", LOG_WARNING);
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

// Handler cleanup_cron_duplicates
if ($action === 'cleanup_cron_duplicates' && verifToken()) {
    $result = cleanupCronDuplicatesHealth($db, $conf);
    if ($result > 0) {
        setEventMessages($langs->trans("CronDuplicatesCleanedSuccess", $result), null, 'mesgs');
    } elseif ($result === 0) {
        setEventMessages($langs->trans("NoCronDuplicatesToClean"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("ErrorCleaningCronDuplicates"), null, 'errors');
    }
    header('Location: ' . dol_buildpath('/doli2shop/admin/health.php', 1));
    exit;
}

// Handler activate_cron
if ($action === 'activate_cron' && verifToken()) {
    $cronId = GETPOST('cron_id', 'int');
    if ($cronId > 0) {
        dol_include_once('/cron/class/cronjob.class.php');
        $cronjob = new Cronjob($db);
        $result = $cronjob->fetch($cronId);
        if ($result > 0 && $cronjob->entity == $conf->entity) {
            $cronjob->status = 1;
            $updateResult = $cronjob->update($user);
            if ($updateResult > 0) {
                setEventMessages($langs->trans("CronActivatedSuccess"), null, 'mesgs');
            } else {
                setEventMessages($langs->trans("ErrorActivatingCron") . ": " . implode(', ', $cronjob->errors), null, 'errors');
            }
        } else {
            setEventMessages($langs->trans("CronNotFoundOrNotAccessible"), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans("InvalidCronId"), null, 'errors');
    }
    header('Location: ' . dol_buildpath('/doli2shop/admin/health.php', 1));
    exit;
}

// Handler recreate_cron
if ($action === 'recreate_cron' && verifToken()) {
    $cronType = GETPOST('cron_type', 'aZ09');
    $validCronTypes = getAllExpectedCrons();
    if (!isset($validCronTypes[$cronType])) {
        setEventMessages($langs->trans("CronTypeInvalid"), null, 'errors');
    } else {
        $checkSql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "cronjob"
            . " WHERE objectname = '" . $db->escape($cronType) . "'"
            . " AND entity = " . (int)$conf->entity;
        $checkResult = $db->query($checkSql);
        if (!$checkResult) {
            setEventMessages($langs->trans("CronRecreateError", $db->lasterror()), null, 'errors');
            header('Location: ' . dol_buildpath('/doli2shop/admin/health.php', 1));
            exit;
        }
        $checkObj = $db->fetch_object($checkResult);
        if ($checkObj && $checkObj->cnt > 0) {
            setEventMessages($langs->trans("CronAlreadyExists"), null, 'warnings');
        } else {
            dol_include_once('/cron/class/cronjob.class.php');
            $cronDef = $validCronTypes[$cronType];
            $cronjob = new Cronjob($db);
            $cronjob->label = $cronDef['label'];
            $cronjob->jobtype = 'method';
            $cronjob->module_name = 'doli2shop';
            $cronjob->classesname = $cronDef['classesname'];
            $cronjob->objectname = $cronType;
            $cronjob->methodename = $cronDef['methodename'];
            $cronjob->parameters = $cronDef['parameters'];
            $cronjob->frequency = $cronDef['frequency'];
            $cronjob->unitfrequency = $cronDef['unitfrequency'];
            $cronjob->status = 0;
            $cronjob->entity = $conf->entity;
            $cronjob->priority = $cronDef['priority'];
            $cronjob->note_private = isset($cronDef['comment']) ? $cronDef['comment'] : '';
            $cronjob->test = 'isModEnabled("doli2shop")';
            // Cronjob::create() REFUSE la création si datenextrun est vide : c'est le premier champ
            // contrôlé (`CronFieldMandatory` / `CronDtNextLaunch`, cron/class/cronjob.class.php:386).
            // Il n'était pas renseigné ici : l'action « Recréer » échouait donc SYSTÉMATIQUEMENT,
            // sur toute installation — pas seulement en mode SQL strict comme le laissait penser la
            // remontée client (Reminiscence, 2026-08-07). La création par le descripteur du module
            // (modDoli2Shop::init()) n'était pas concernée, elle renseigne ce champ.
            $cronjob->datenextrun = dol_now();
            $cronid = $cronjob->create($user);
            if ($cronid > 0) {
                setEventMessages($langs->trans("CronRecreatedSuccess"), null, 'mesgs');
            } else {
                $errorMsg = implode(', ', (array)$cronjob->errors);
                setEventMessages($langs->trans("CronRecreateError", $errorMsg), null, 'errors');
            }
        }
    }
    header('Location: ' . dol_buildpath('/doli2shop/admin/health.php', 1));
    exit;
}


// ── FONCTIONS LOCALES ────────────────────────────────────────────────────────

/**
 * Nettoie les CRONs dupliqués du module Shopify (copie locale pour health.php)
 *
 * @param DoliDB $db   Database handler
 * @param Conf   $conf Configuration object
 * @return int Nombre de CRONs supprimés, -1 en cas d'erreur
 */
function cleanupCronDuplicatesHealth($db, $conf)
{
    try {
        $db->begin();
        $removedCount = 0;

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob
                WHERE objectname IN ('ImportProductsCron', 'ShopifyHistoricalImportCron', 'ShopifyHistoricalImportCleanupCron', 'ShopifyProductImportCron')
                AND status = 0
                AND entity = " . (int)$conf->entity;
        $result = $db->query($sql);
        if ($result) {
            $removedCount += $db->affected_rows($result);
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob
                WHERE label IN ('CronJobOrdersSync', 'CronJobProductsSync')
                AND entity = " . (int)$conf->entity;
        $result = $db->query($sql);
        if ($result) {
            $removedCount += $db->affected_rows($result);
        }

        $cronTypes = ['ImportProductsCron', 'ShopifyHistoricalImportCron', 'ShopifyHistoricalImportCleanupCron', 'ShopifyProductImportCron'];
        foreach ($cronTypes as $type) {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cronjob
                    WHERE objectname = '" . $db->escape($type) . "'
                    AND status = 1
                    AND entity = " . (int)$conf->entity . "
                    ORDER BY rowid ASC";
            $result = $db->query($sql);
            $cronIds = [];
            while ($obj = $db->fetch_object($result)) {
                $cronIds[] = $obj->rowid;
            }
            if (count($cronIds) > 1) {
                array_shift($cronIds);
                foreach ($cronIds as $cronId) {
                    $sql = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob WHERE rowid = " . (int)$cronId;
                    $result = $db->query($sql);
                    if ($result) {
                        $removedCount++;
                    }
                }
            }
        }

        $db->commit();
        return $removedCount;
    } catch (Exception $e) {
        $db->rollback();
        return -1;
    }
}

// Helper function pour vérifier si le module est activé (compatible toutes versions)
function isShopifyModuleEnabledHealth($conf)
{
    return getDolGlobalBool('MAIN_MODULE_SHOPIFYINTEGRATION') ||
           (function_exists('isModEnabled') && isModEnabled('doli2shop')) ||
           (!empty($conf->doli2shop->enabled));
}

// CORRECTION v2.0.31: Nettoyage des entités HTML pour export JSON
function cleanHtmlEntitiesForJsonHealth($data)
{
    if (is_array($data)) {
        return array_map('cleanHtmlEntitiesForJsonHealth', $data);
    } elseif (is_string($data)) {
        return html_entity_decode($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}

// Story fix-export-diagnostic-secrets (CRITICAL) : l'ancienne maskSensitiveValueHealth() ci-dessus
// (substr($value, 0, 4) . '****' . substr($value, -4)) exposait 8 des caractères du secret, et
// n'était appelée que sur 4 clés figées — `shopify_refresh_token` en sortait EN CLAIR. Remplacée
// par doli2shopRedactSensitiveConfig() (lib/doli2shop.lib.php), masquage intégral par MOTIF de nom
// de clé, appliquée à TOUT le rapport juste avant l'export (voir plus bas).

// ── CLASSE DIAGNOSTIC (copie locale de diagnostic.php — Story 49-6 Option B) ──

/**
 * Classe de diagnostic du module Shopify (copie de diagnostic.php pour health.php)
 */
class ShopifyDiagnosticHealth
{
    private $db;
    private $conf;
    private $langs;
    private $results = [];
    private $errors = [];
    private $warnings = [];
    /** @var array Mode de licence détecté */
    private $licenceMode = array('mode' => 'unknown', 'source' => null, 'plan_name' => null, 'license_type' => null);
    /** @var array|null Cache local (durée de la requête) des boutiques actives — Story 50-3, évite les StoreService::getAll() redondants */
    private $activeStoresCache = null;
    /** @var bool Si vrai, checkShopifyApi() ne fait AUCUN appel réseau Shopify (reporté en AJAX) — Story 50-3 */
    private $deferApiTests = false;
    /** @var string Dossier RÉSOLU (realpath) du module réellement exécuté — Story 2.4.7-5 */
    private $executedModuleDir = '';
    /** @var string[] Copies secondaires du module détectées (chemins résolus) — Story 2.4.7-5 */
    private $secondaryModuleCopies = array();

    public function __construct($db, $conf, $langs)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->langs = $langs;
    }

    /**
     * Retourne la liste des boutiques actives, mise en cache pour la durée de la requête
     * (Story 50-3 — plusieurs méthodes de check appelaient chacune leur propre
     * StoreService::getAll(true), une requête SQL par appel).
     *
     * @return array Boutiques actives (objets StoreService)
     */
    private function getActiveStores()
    {
        if ($this->activeStoresCache === null) {
            $storeService = new StoreService($this->db);
            $this->activeStoresCache = $storeService->getAll(true);
        }
        return $this->activeStoresCache;
    }

    /**
     * Active/désactive le report des tests API Shopify (connexion, scopes, canaux)
     * vers le chargement AJAX par boutique (Story 50-3).
     *
     * @param  bool $defer
     * @return void
     */
    public function setDeferApiTests($defer)
    {
        $this->deferApiTests = (bool) $defer;
    }

    /**
     * Lance tous les diagnostics
     */
    public function runAllDiagnostics()
    {
        $this->checkSystem();
        $this->checkModule();
        $this->checkSupport();
        $this->checkCronJobs();
        $this->checkShopifyApi();
        $this->checkDolibarrApi();
        $this->checkRecentSyncs();
        // AC7 (Story 2.4.7-6) : même ordre que admin/diagnostic.php — les clients comparent des
        // exports dans le temps, un ordre différent entre les deux pages rendrait la comparaison
        // pénible.
        $this->checkWebhookRows();
        $this->checkAmbiguousShopDomains();
        $this->checkModuleLocation();
        $this->checkFileIntegrity();
        $this->checkLogFiles();
        return $this->generateReport();
    }

    /**
     * Retourne le mode de licence détecté
     *
     * @return array Mode licence avec clés: mode, source, plan_name, license_type
     */
    public function getLicenceMode()
    {
        return $this->licenceMode;
    }

    /**
     * Retourne le dossier RÉSOLU (realpath) du module réellement exécuté, calculé par
     * checkModuleLocation() (Story 2.4.7-5, AC4bis) — utilisé par le script pour recalculer
     * `cron_builds` depuis le dossier réellement servi plutôt que depuis un chemin codé en dur.
     *
     * @return string
     */
    public function getExecutedModuleDir()
    {
        return $this->executedModuleDir;
    }

    /**
     * Retourne les copies secondaires du module détectées par checkModuleLocation() (Story 2.4.7-5,
     * AC4bis) — utilisé par le script pour enrichir `export_info`.
     *
     * @return string[]
     */
    public function getSecondaryModuleCopies()
    {
        return $this->secondaryModuleCopies;
    }

    private function checkSystem()
    {
        $this->results['system'] = ['title' => $this->langs->transnoentities('DiagSectionSystemTitle'), 'checks' => []];

        $dolVersion = DOL_VERSION;
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemDolibarrVersion'), $dolVersion, 'info');

        $phpVersion = phpversion();
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemPhpVersion'), $phpVersion,
            version_compare($phpVersion, '7.4', '>=') ? 'success' : 'error',
            $this->langs->trans('DiagSystemPhpVersionHelp'));

        $extensions = ['curl', 'json', 'mbstring'];
        foreach ($extensions as $ext) {
            $loaded = extension_loaded($ext);
            $this->addCheck('system', $this->langs->transnoentities('DiagSystemExtensionLabel', $ext),
                $loaded ? $this->langs->transnoentities('DiagValuePresent') : $this->langs->transnoentities('DiagValueMissing'),
                $loaded ? 'success' : 'error');
        }

        $dbType = $this->db->type;
        $dbVersion = $this->db->getVersion();
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemDbType'), $dbType, 'info');
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemDbVersion'), $dbVersion, 'info');

        $hasDatabase = extension_loaded('mysqli') || extension_loaded('pdo_mysql');
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemDbExtension'),
            $hasDatabase ? $this->langs->transnoentities('DiagSystemDbExtensionPresentValue') : $this->langs->transnoentities('DiagValueMissing'),
            $hasDatabase ? 'success' : 'error', $this->langs->trans('DiagSystemDbExtensionHelp'));

        $timezone = date_default_timezone_get();
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemTimezone'), $timezone, 'info');

        $memoryLimit = ini_get('memory_limit');
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemMemoryLimit'), $memoryLimit, 'info');

        $maxExecTime = ini_get('max_execution_time');
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemExecTimeout'), $maxExecTime . 's', 'info');

        $customPath = dol_buildpath('/doli2shop', 0);
        $writable = is_writable($customPath);
        $this->addCheck('system', $this->langs->transnoentities('DiagSystemModuleDirPerms'),
            $writable ? $this->langs->transnoentities('DiagValueWriteOk') : $this->langs->transnoentities('DiagValueNoWrite'),
            $writable ? 'success' : 'warning');

        $useOldPathForPhoto = getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO', 0);
        if ($useOldPathForPhoto == 1) {
            $this->addCheck('system', $this->langs->transnoentities('DiagSystemPhotoPathLabel'),
                $this->langs->transnoentities('DiagSystemPhotoPathOldValue'), 'warning',
                $this->langs->trans('DiagSystemPhotoPathOldHelp'));
        } else {
            $this->addCheck('system', $this->langs->transnoentities('DiagSystemPhotoPathLabel'), $this->langs->transnoentities('DiagSystemPhotoPathModernValue'), 'success');
        }
    }

    private function checkModule()
    {
        $this->results['module'] = ['title' => $this->langs->transnoentities('DiagSectionModuleTitle'), 'checks' => []];

        // Flags globaux — indépendants des boutiques
        $enabled = getDolGlobalBool('MAIN_MODULE_SHOPIFYINTEGRATION') ||
                   (function_exists('isModEnabled') && isModEnabled('doli2shop')) ||
                   (!empty($this->conf->doli2shop->enabled));
        $this->addCheck('module', $this->langs->transnoentities('ModuleEnabled'), $enabled ? $this->langs->trans('Yes') : $this->langs->trans('No'), $enabled ? 'success' : 'error');

        if ($enabled) {
            // Drapeaux de configuration globaux (non liés à une boutique)
            $migrator = new ConfigurationMigrator($this->db);
            $configArray = $migrator->getConfiguration($this->conf->entity);
            $config = !empty($configArray) ? (object)$configArray : null;

            if ($config) {
                $syncCollections = (isset($config->sync_product_collections) && $config->sync_product_collections == 1) ? $this->langs->trans('Activated') : $this->langs->trans('Disabled');
                $this->addCheck('module', $this->langs->transnoentities('ModuleOptionSyncCollections'), $syncCollections, 'info');

                $useVirtualStock = (isset($config->use_virtual_stock) && $config->use_virtual_stock == 1) ? $this->langs->trans('ModuleOptionVirtualStock') : $this->langs->trans('ModuleOptionRealStock');
                $this->addCheck('module', $this->langs->transnoentities('ModuleOptionStockType'), $useVirtualStock, 'info');

                $productsPerCron = $config->products_per_cron_update ?? 10;
                $this->addCheck('module', $this->langs->transnoentities('ModuleOptionProductsPerCron'), (string) $productsPerCron, 'info');

                $maxOrdersSync = $config->max_orders_per_sync ?? 20;
                $this->addCheck('module', $this->langs->transnoentities('ModuleOptionMaxOrdersSync'), (string) $maxOrdersSync, 'info');
            }

            // Credentials par boutique
            $activeStores = $this->getActiveStores();

            if (!empty($activeStores)) {
                // Mode multi-boutiques : credentials par boutique (jamais en clair)
                foreach ($activeStores as $store) {
                    $storeLabel = !empty($store->label) ? $store->label : (string) $store->rowid; // brut : échappé au rendu (évite le double-encodage)
                    $hasDomain  = !empty($store->shop_domain);
                    $hasToken   = !empty($store->access_token);
                    $credOk     = $hasDomain && $hasToken;
                    $credStatus = $credOk ? 'success' : ($hasDomain || $hasToken ? 'warning' : 'error');
                    $credValue  = $credOk
                        ? $this->langs->trans('ModuleStoreCredentialsOk')
                        : $this->langs->trans('HealthStoreCredentialsMissing');
                    $this->addCheck(
                        'module',
                        $this->langs->transnoentities('ModuleStoreCredentialsLabel', $storeLabel),
                        $credValue,
                        $credStatus
                    );

                    // Story 51-4 : badge « migration jeton requise » (token non expirable —
                    // échéance Shopify 01/01/2027), condition normalisée via le helper partagé
                    // (couvre NULL, '', '0000-00-00 00:00:00'). Symétrie avec diagnostic.php.
                    if ($hasToken && doli2shopStoreTokenMigrationRequired($store)) {
                        $this->addCheck(
                            'module',
                            $this->langs->transnoentities('ModuleStoreCredentialsLabel', $storeLabel) . ' — ' . $this->langs->trans('StoreTokenMigrationRequiredBadge'),
                            $this->langs->trans('StoreTokenMigrationRequiredHint'),
                            'warning'
                        );
                    }

                    // Story 51-4 : flag reconnexion requise (échec définitif de refresh, 51-1 AC9)
                    if (!empty($store->token_reconnect_required)) {
                        $this->addCheck(
                            'module',
                            $this->langs->transnoentities('ModuleStoreCredentialsLabel', $storeLabel),
                            $this->langs->trans('OAuthTokenReconnectRequired', $storeLabel),
                            'error'
                        );
                    }
                }
            } else {
                // Chemin legacy (0 boutique seedée) : comportement original
                $this->addCheck('module', $this->langs->transnoentities('DiagModuleStoreUrlLabel'),
                    $config && !empty($config->shopify_store_hostname) ? dol_escape_htmltag($config->shopify_store_hostname) : $this->langs->transnoentities('DiagValueNotConfiguredF'),
                    $config && !empty($config->shopify_store_hostname) ? 'success' : 'error');

                $this->addCheck('module', $this->langs->transnoentities('DiagModuleApiKeyLabel'),
                    $config && !empty($config->shopify_api_key) ? $this->langs->transnoentities('DiagValueConfiguredF') : $this->langs->transnoentities('DiagValueMissing'),
                    $config && !empty($config->shopify_api_key) ? 'success' : 'error');

                $this->addCheck('module', $this->langs->transnoentities('DiagModuleAccessTokenLabel'),
                    $config && !empty($config->shopify_access_token) ? $this->langs->transnoentities('DiagValueConfiguredM') : $this->langs->transnoentities('DiagValueMissingM'),
                    $config && !empty($config->shopify_access_token) ? 'success' : 'error');

                // Story 51-4 : badge « migration jeton requise » (chemin constantes, boutique par défaut)
                $legacyTokenExpiresAt = trim(getDolGlobalString('DOLI2SHOP_TOKEN_EXPIRES_AT', ''));
                if ($config && !empty($config->shopify_access_token)
                    && ($legacyTokenExpiresAt === '' || strpos($legacyTokenExpiresAt, '0000-00-00') === 0)) {
                    $this->addCheck(
                        'module',
                        $this->langs->trans('StoreTokenMigrationRequiredBadge'),
                        $this->langs->trans('StoreTokenMigrationRequiredHint'),
                        'warning'
                    );
                }

                // Story 51-4 : flag reconnexion requise (chemin constantes)
                if ($config && !empty($config->shopify_access_token) && getDolGlobalInt('DOLI2SHOP_TOKEN_RECONNECT_REQUIRED', 0) == 1) {
                    $this->addCheck(
                        'module',
                        $this->langs->trans('StoreTokenMigrationRequiredBadge'),
                        $this->langs->trans('OAuthTokenReconnectRequired', ''),
                        'error'
                    );
                }

                $this->addCheck('module', $this->langs->transnoentities('DiagModuleApiSecretKeyLabel'),
                    $config && !empty($config->shopify_api_secret_key) ? $this->langs->transnoentities('DiagValueConfiguredF') : $this->langs->transnoentities('DiagValueMissing'),
                    $config && !empty($config->shopify_api_secret_key) ? 'success' : 'error');

                $this->addCheck('module', $this->langs->transnoentities('DiagModuleLocationIdLabel'),
                    $config && !empty($config->shopify_location_id) ? dol_escape_htmltag((string) $config->shopify_location_id) : $this->langs->transnoentities('DiagValueNotConfiguredM'),
                    $config && !empty($config->shopify_location_id) ? 'success' : 'error');
            }
        }

        $this->checkTables();
    }

    private function checkSupport()
    {
        $this->results['support'] = ['title' => $this->langs->transnoentities('SupportStatus'), 'checks' => []];

        try {
            $supportManager = new SupportManager($this->db);
            $config = $supportManager->getSupportConfig();

            if (!empty($config['is_community'])) {
                $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
                    $this->langs->trans('CommunityEditionDiagnosticEncart'), 'info');
                return;
            }

            // Statut licence par boutique (basé sur license_status persisté — pas d'appel API ici)
            $activeStores = $this->getActiveStores();

            if (!empty($activeStores)) {
                // Mode multi-boutiques : afficher le statut persisté de chaque boutique
                foreach ($activeStores as $store) {
                    $storeLabel    = !empty($store->label) ? $store->label : (string) $store->rowid; // brut : échappé au rendu
                    $licenseStatus = isset($store->license_status) ? $store->license_status : '';
                    $isDefaultStore = ((int) $store->is_default === 1);

                    if ($licenseStatus === 'valid') {
                        $statusValue  = $this->langs->trans('StoreLicenseStatusValid');
                        $statusLevel  = 'success';
                        $statusNote   = '';
                    } elseif ($licenseStatus === 'invalid') {
                        $statusValue  = $this->langs->trans('StoreLicenseStatusInvalid');
                        $statusLevel  = 'error';
                        $statusNote   = $this->langs->trans('DiagStoreLicenseCheckNow');
                    } elseif ($isDefaultStore) {
                        // Boutique par défaut avec statut inconnu : exemptée (rétrocompat)
                        $statusValue  = $this->langs->trans('StoreLicenseStatusDefaultExempt');
                        $statusLevel  = 'info';
                        $statusNote   = '';
                    } else {
                        // Boutique secondaire statut inconnu
                        $statusValue  = $this->langs->trans('StoreLicenseStatusUnknown');
                        $statusLevel  = 'warning';
                        $statusNote   = $this->langs->trans('DiagStoreLicenseCheckNow');
                    }

                    $this->addCheck(
                        'support',
                        $this->langs->transnoentities('DiagStoreLicenseLabel', $storeLabel),
                        $statusValue,
                        $statusLevel,
                        $statusNote
                    );
                }

                // Note renvoyant vers la fiche boutique
                $this->addCheck('support', $this->langs->transnoentities('DiagStoreLicenseHint'), '', 'info');
            } else {
                // Chemin legacy (0 boutique seedée) : comportement original
                $shopHostname = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '');
                $shopDomainResolved = false;

                if (!empty($shopHostname)) {
                    $shopDomainResolved = $this->checkSupportByShopDomain($shopHostname);
                }

                if (!$shopDomainResolved) {
                    $purchaseEmail = $config['support_email'] ?? '';
                    if (!empty($purchaseEmail)) {
                        $this->checkSupportByEmail($supportManager, $purchaseEmail);
                    } else {
                        $serialNumber = $config['serial_number'] ?? '';
                        if (!empty($serialNumber)) {
                            $this->checkSupportBySerial($supportManager, $serialNumber);
                        } else {
                            $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
                                $this->langs->trans('LicenseModeNone'), 'warning',
                                $this->langs->trans('LicenseModeNoneHelp'));
                            $this->licenceMode = array('mode' => 'none', 'source' => null, 'plan_name' => null, 'license_type' => null);
                            $this->addCheck('support', $this->langs->transnoentities('SupportPurchaseEmail'),
                                $this->langs->trans('NoPurchaseEmailConfigured'), 'warning',
                                $this->langs->trans('DiagSupportNoPurchaseEmailHelp'));
                            $this->addCheck('support', $this->langs->transnoentities('SupportValidity'),
                                $this->langs->trans('NotConfigured'), 'warning',
                                $this->langs->trans('DiagSupportNoLicenseValidationHelp'));
                        }
                    }
                }
            }

            $this->addCheck('support', $this->langs->transnoentities('SupportValidationEnabled'),
                $config['validation_enabled'] ? $this->langs->trans('Yes') : $this->langs->trans('No'),
                $config['validation_enabled'] ? 'success' : 'info');

            $this->addCheck('support', $this->langs->transnoentities('SupportCacheEnabled'),
                $config['cache_enabled'] ? $this->langs->trans('Yes') : $this->langs->trans('No'),
                $config['cache_enabled'] ? 'success' : 'info');
        } catch (Exception $e) {
            $this->addCheck('support', $this->langs->transnoentities('DiagSupportErrorLabel'), $this->langs->transnoentities('DiagErrorWithMessage', $e->getMessage()), 'error',
                $this->langs->trans('DiagSupportErrorHelp'));
            dol_syslog("ShopifyDiagnosticHealth::checkSupport - Exception: " . $e->getMessage(), LOG_ERR);
        }
    }

    private function checkSupportByShopDomain($shopHostname)
    {
        $shopDomain = $shopHostname;
        if (!preg_match('/\.myshopify\.com$/', $shopDomain)) {
            $shopDomain .= '.myshopify.com';
        }

        // Review 3 couches 27/09 (finding HIGH n°1) : URL de production rendue surchargeable —
        // point de résolution unique, cf. doli2shopGetBillingApiEndpoint() (lib/doli2shop.lib.php).
        $apiUrl = doli2shopGetBillingApiEndpoint() . '?action=get-license-by-shop&shop_domain=' . urlencode($shopDomain);

        // Story 50-9 : header X-Dolibarr-Domain omis si non résolu (jamais le littéral générique).
        $checkSupportHeaders = array(
            'User-Agent: Doli2Shop/' . DOLI2SHOP_MODULE_VERSION,
        );
        $checkSupportDomain = doli2shopBuildTelemetry()['dolibarr_domain'] ?? null;
        if ($checkSupportDomain !== null) {
            $checkSupportHeaders[] = 'X-Dolibarr-Domain: ' . $checkSupportDomain;
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => $checkSupportHeaders,
            CURLOPT_SSL_VERIFYPEER => true
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return false;
        }

        $payload = isset($data['data']) ? $data['data'] : $data;

        // Story 65-2 : licence déliée (champ additif du site) — pas de mode « Shopify » ni de plan.
        $licenseDisplayState = doli2shopResolveLicenseDisplayState($payload);
        if (!empty($payload['found']) && $licenseDisplayState['state'] === 'unlinked') {
            $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
                $this->langs->trans('LicenseModeNone'), 'warning');
            $this->licenceMode = array('mode' => 'none', 'source' => null, 'plan_name' => null, 'license_type' => null);
            $this->addCheck('support', $this->langs->transnoentities('SupportValidity'),
                $this->langs->trans('SupportInvalid'), $licenseDisplayState['cardClass'], $this->langs->trans($licenseDisplayState['noteKey']));
            return true;
        }

        if (!empty($payload['found']) && $payload['source'] === 'shopify') {
            $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
                $this->langs->trans('LicenseModeShopify'), 'success');
            $planName = isset($payload['subscription']['plan_name']) ? $payload['subscription']['plan_name'] : null;
            $licenseType = isset($payload['license']['license_type']) ? $payload['license']['license_type'] : null;
            $this->licenceMode = array('mode' => 'shopify', 'source' => 'shopify', 'plan_name' => $planName, 'license_type' => $licenseType);
            if (!empty($planName)) {
                $this->addCheck('support', $this->langs->transnoentities('SubscriptionPlan'),
                    htmlspecialchars($planName, ENT_QUOTES, 'UTF-8'), 'info');
            }
            if (!empty($payload['license']['serial_number'])) {
                $this->addCheck('support', $this->langs->transnoentities('SupportSerialNumber'),
                    $payload['license']['serial_number'], 'info');
            }
            $isValid = !empty($payload['valid']);
            $isCanceled = ($licenseDisplayState['state'] === 'canceled');
            $validityStatus = $isValid ? $this->langs->trans('SupportValid') : $this->langs->trans('SupportInvalid');
            $validityClass = $isValid ? 'success' : 'error';
            if (isset($payload['license']['days_remaining']) && $payload['license']['days_remaining'] !== null) {
                $validityStatus .= ' (' . $payload['license']['days_remaining'] . ' ' . $this->langs->trans('DaysRemaining') . ')';
            }
            $validityNote = $isCanceled ? $this->langs->trans('SubscriptionCanceled') : '';
            if ($isCanceled) {
                $validityClass = 'warning';
            }
            $this->addCheck('support', $this->langs->transnoentities('SupportValidity'), $validityStatus, $validityClass, $validityNote);
            if (!empty($payload['license']['expires_at']) && $payload['license']['expires_at'] !== '9999-12-31') {
                $this->addCheck('support', $this->langs->transnoentities('SupportExpiryDate'), $payload['license']['expires_at'], 'info');
            }
            return true;
        }

        if (!empty($payload['found']) && $payload['source'] === 'dolistore') {
            $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
                $this->langs->trans('LicenseModeDolistore'), 'info');
            $licenseType = isset($payload['license']['license_type']) ? $payload['license']['license_type'] : null;
            $this->licenceMode = array('mode' => 'dolistore', 'source' => 'dolistore', 'plan_name' => null, 'license_type' => $licenseType);
            if (!empty($payload['license']['serial_number'])) {
                $this->addCheck('support', $this->langs->transnoentities('SupportSerialNumber'), $payload['license']['serial_number'], 'info');
            }
            $isValid = !empty($payload['valid']);
            $validityStatus = $isValid ? $this->langs->trans('SupportValid') : $this->langs->trans('SupportInvalid');
            $validityClass = $isValid ? 'success' : 'error';
            if (isset($payload['license']['days_remaining']) && $payload['license']['days_remaining'] !== null) {
                $validityStatus .= ' (' . $payload['license']['days_remaining'] . ' ' . $this->langs->trans('DaysRemaining') . ')';
            }
            $this->addCheck('support', $this->langs->transnoentities('SupportValidity'), $validityStatus, $validityClass);
            if (!empty($payload['license']['expires_at']) && $payload['license']['expires_at'] !== '9999-12-31') {
                $this->addCheck('support', $this->langs->transnoentities('SupportExpiryDate'), $payload['license']['expires_at'], 'info');
            }
            return true;
        }

        $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
            $this->langs->trans('LicenseModeNone'), 'warning',
            isset($payload['message']) ? $payload['message'] : $this->langs->trans('LicenseModeNoneHelp'));
        $this->licenceMode = array('mode' => 'none', 'source' => null, 'plan_name' => null, 'license_type' => null);
        $this->addCheck('support', $this->langs->transnoentities('SupportValidity'),
            $this->langs->trans('NotConfigured'), 'warning',
            $this->langs->trans('DiagSupportNoLicenseFoundForDomain', $shopDomain));
        return true;
    }

    private function checkSupportByEmail($supportManager, $purchaseEmail)
    {
        $this->addCheck('support', $this->langs->transnoentities('SupportPurchaseEmail'),
            doli2shop_mask_email($purchaseEmail), 'success', $this->langs->trans('DiagSupportEmailDetectionLegacyNote'));
        $emailValidation = $supportManager->validateSupportByEmail($purchaseEmail);
        if ($emailValidation['success'] && isset($emailValidation['support_data'])) {
            $supportData = $emailValidation['support_data'];
            $licenseSource = isset($supportData['source']) ? $supportData['source'] : 'unknown';
            if ($licenseSource === 'shopify') {
                $this->addCheck('support', $this->langs->transnoentities('LicenseMode'), $this->langs->transnoentities('LicenseModeShopify'), 'success');
                $this->licenceMode = array('mode' => 'shopify', 'source' => $licenseSource,
                    'plan_name' => isset($supportData['plan_name']) ? $supportData['plan_name'] : null,
                    'license_type' => isset($supportData['license_type']) ? $supportData['license_type'] : null);
                if (!empty($supportData['plan_name'])) {
                    $this->addCheck('support', $this->langs->transnoentities('SubscriptionPlan'),
                        htmlspecialchars($supportData['plan_name'], ENT_QUOTES, 'UTF-8'), 'info');
                }
            } elseif ($licenseSource === 'fallback') {
                $this->addCheck('support', $this->langs->transnoentities('LicenseMode'),
                    $this->langs->trans('LicenseModeNone'), 'warning', $this->langs->trans('DiagSupportApiBillingUnavailableNote'));
                $this->licenceMode = array('mode' => 'unknown', 'source' => 'fallback', 'plan_name' => null, 'license_type' => null);
            } else {
                $this->addCheck('support', $this->langs->transnoentities('LicenseMode'), $this->langs->transnoentities('LicenseModeDolistore'), 'info');
                $this->licenceMode = array('mode' => 'dolistore', 'source' => $licenseSource,
                    'plan_name' => null,
                    'license_type' => isset($supportData['license_type']) ? $supportData['license_type'] : null);
            }
            // Story 61-9 (Option B) : au-delà de la fenêtre de grâce, refléter honnêtement
            // l'absence de vérification récente plutôt qu'un « invalide » trompeur — cette
            // synchronisation-ci (Mécanisme A, purement informatif) n'est jamais gatée.
            if (isset($supportData['status']) && $supportData['status'] === 'unverified_stale') {
                $validityStatus = $this->langs->trans('SupportUnverifiedStale');
                $validityClass = 'warning';
            } else {
                $validityStatus = $supportData['valid'] ? $this->langs->trans('SupportValid') : $this->langs->trans('SupportInvalid');
                $validityClass = $supportData['valid'] ? 'success' : 'error';
            }
            if (!empty($supportData['primary_serial'])) {
                $this->addCheck('support', $this->langs->transnoentities('SupportSerialNumber'),
                    $supportData['primary_serial'], 'info', $this->langs->trans('DiagSupportSerialAutoFoundNote'));
            }
            if (isset($supportData['days_remaining']) && $supportData['days_remaining'] !== null) {
                $validityStatus .= ' (' . $supportData['days_remaining'] . ' ' . $this->langs->trans('DaysRemaining') . ')';
            }
            $this->addCheck('support', $this->langs->transnoentities('SupportValidity'),
                $validityStatus, $validityClass, isset($supportData['message']) ? $supportData['message'] : '');
            if (!empty($supportData['expiry_date']) && $supportData['expiry_date'] !== '9999-12-31') {
                $this->addCheck('support', $this->langs->transnoentities('SupportExpiryDate'), $supportData['expiry_date'], 'info');
            }
        } else {
            $this->addCheck('support', $this->langs->transnoentities('SupportValidity'),
                $this->langs->trans('SupportValidationFailed'), 'error',
                $emailValidation['support_data']['message'] ?? $this->langs->trans('DiagSupportUnknownErrorNote'));
        }
    }

    private function checkSupportBySerial($supportManager, $serialNumber)
    {
        $this->addCheck('support', $this->langs->transnoentities('LicenseMode'), $this->langs->transnoentities('LicenseModeDolistore'), 'info');
        $this->licenceMode = array('mode' => 'dolistore', 'source' => 'legacy_serial', 'plan_name' => null, 'license_type' => null);
        $this->addCheck('support', $this->langs->transnoentities('SupportSerialNumber'), $serialNumber, 'info',
            $this->langs->trans('DiagSupportSerialLegacyModeNote'));
        $validation = $supportManager->validateSupport($serialNumber);
        $validityStatus = $validation['valid'] ? $this->langs->trans('SupportValid') : $this->langs->trans('SupportInvalid');
        $validityClass = $validation['valid'] ? 'success' : 'error';
        if (isset($validation['days_remaining']) && $validation['days_remaining'] !== null) {
            $validityStatus .= ' (' . $validation['days_remaining'] . ' ' . $this->langs->trans('DaysRemaining') . ')';
        }
        $this->addCheck('support', $this->langs->transnoentities('SupportValidity'), $validityStatus, $validityClass, $validation['message'] ?? '');
    }

    private function checkTables()
    {
        $requiredTables = [
            MAIN_DB_PREFIX . 'doli2shop_products',
            MAIN_DB_PREFIX . 'doli2shop_orders',
            MAIN_DB_PREFIX . 'doli2shop_collections',
            MAIN_DB_PREFIX . 'doli2shop_payments'
        ];
        foreach ($requiredTables as $table) {
            $sql = "SHOW TABLES LIKE '" . $this->db->escape($table) . "'";
            $result = $this->db->query($sql);
            $exists = $this->db->num_rows($result) > 0;
            $this->addCheck('module', $this->langs->transnoentities('DiagTableLabel', $table),
                $exists ? $this->langs->transnoentities('DiagValuePresent') : $this->langs->transnoentities('DiagValueMissing'),
                $exists ? 'success' : 'error');
        }

        // Hotfix 2.4.5 (AC3 — Validate correction #3) : ce contrôle d'EXISTENCE de table (ci-dessus)
        // n'inclut volontairement pas llx_doli2shop_stores. On ajoute ici un contrôle de SCHÉMA
        // (colonnes) dédié, qui détecte une migration SQL non rejouée (mise à jour du module par
        // simple copie de fichiers, sans désactivation/réactivation) — même cause que l'échec muet
        // « Échec de l'enregistrement des credentials » du retour OAuth (admin/oauth_receive.php).
        $storesSchemaCheck = doli2shopCheckStoresTableSchema($this->db);
        $storesTable = MAIN_DB_PREFIX . 'doli2shop_stores';
        switch ($storesSchemaCheck['status']) {
            case 'complete':
                $this->addCheck('module', $this->langs->transnoentities('DiagTableSchemaLabel', $storesTable), $this->langs->transnoentities('DiagTableSchemaCompleteValue'), 'success');
                break;
            case 'incomplete':
                $this->addCheck(
                    'module',
                    $this->langs->transnoentities('DiagTableSchemaLabel', $storesTable),
                    $this->langs->transnoentities('DiagTableSchemaIncompleteValue'),
                    'error',
                    $this->langs->trans('OAuthStoreSchemaIncomplete', implode(', ', $storesSchemaCheck['missing']))
                );
                break;
            case 'absent':
                $this->addCheck(
                    'module',
                    $this->langs->transnoentities('DiagTableSchemaLabel', $storesTable),
                    $this->langs->transnoentities('DiagTableSchemaAbsentValue'),
                    'error',
                    $this->langs->trans('OAuthStoreSchemaTableMissing', $storesTable)
                );
                break;
            default:
                // 'unknown' : le contrôle lui-même a échoué — surtout pas un vert « Complet »
                // (review 3 couches, MEDIUM : un état indéterminé déguisé en état sain est
                // exactement l'échec muet que ce hotfix corrige).
                $this->addCheck(
                    'module',
                    $this->langs->transnoentities('DiagTableSchemaLabel', $storesTable),
                    $this->langs->transnoentities('DiagTableSchemaUnknownValue'),
                    'warning',
                    $this->langs->trans('OAuthStoreSchemaUnknown')
                );
                break;
        }
    }

    private function checkCronJobs()
    {
        $this->results['cron'] = ['title' => $this->langs->transnoentities('DiagSectionCronTitle'), 'checks' => []];

        $syncProductsDirection = getDolGlobalString('DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', 'both');
        $expectedCronStates = [
            'ImportProductsCron' => in_array($syncProductsDirection, ['dolibarr_to_shopify', 'both']),
            'ShopifyProductImportCron' => in_array($syncProductsDirection, ['shopify_to_dolibarr', 'both']),
            'ShopifyHistoricalImportCron' => null,
            'ShopifyHistoricalImportCleanupCron' => null,
            'WebhookProcessCron' => null,
        ];

        $allExpectedCrons = getAllExpectedCrons();
        $cronObjects = ['ImportProductsCron', 'ShopifyHistoricalImportCron', 'ShopifyHistoricalImportCleanupCron', 'ShopifyProductImportCron', 'WebhookProcessCron'];
        $sql = "SELECT rowid, label, objectname, status, processing, datelastrun, datenextrun, lastresult, lastoutput "
            . "FROM " . MAIN_DB_PREFIX . "cronjob "
            . "WHERE (module_name = 'doli2shop' "
            . "OR objectname IN ('" . implode("','", $cronObjects) . "')) "
            . "AND entity = " . (int)$this->conf->entity . " "
            . "ORDER BY objectname, status DESC";

        $result = $this->db->query($sql);
        $cronCount = 0;
        $cronsByType = [];

        while ($obj = $this->db->fetch_object($result)) {
            $cronCount++;
            $type = $obj->objectname;
            if (!isset($cronsByType[$type])) {
                $cronsByType[$type] = [];
            }
            $cronsByType[$type][] = $obj;
        }

        $duplicatesDetected = false;
        foreach ($cronsByType as $type => $crons) {
            if (count($crons) > 1) {
                $duplicatesDetected = true;
                $this->addCheck('cron', $this->langs->transnoentities('DiagCronDuplicatesDetectedLabel', $type),
                    $this->langs->transnoentities('DiagCronDuplicatesCountValue', count($crons)), 'error');
                foreach ($crons as $i => $cron) {
                    $prefix = ($i === count($crons) - 1) ? "└─" : "├─";
                    $status = $cron->status ? $this->langs->transnoentities('Active') : $this->langs->transnoentities('Inactive');
                    $statusClass = $cron->status ? 'success' : 'error';
                    $displayLabel = $cron->label;
                    if ($this->langs->trans($cron->label) !== $cron->label) {
                        $displayLabel = $this->langs->trans($cron->label);
                    }
                    $this->addCheck('cron', "$prefix {$displayLabel} (ID: {$cron->rowid})", $status, $statusClass);
                }
                $this->addCheck('cron', $this->langs->transnoentities('DiagCronSolutionsLabel'),
                    $this->langs->transnoentities('DiagCronSolutionsValue'), 'info');
                $baseUrl = dol_buildpath('/doli2shop/admin/health.php', 1);
                $cleanupUrl = $baseUrl . '?action=cleanup_cron_duplicates&token=' . newToken();
                $this->addCheck('cron', $this->langs->transnoentities('DiagCronCleanupLabel'),
                    "<a href=\"$cleanupUrl\" class=\"button button-delete\" "
                    . "onclick=\"return confirm('" . dol_escape_js($this->langs->trans('DiagCronCleanupConfirm')) . "')\">"
                    . $this->langs->trans('DiagCronCleanupLinkText') . "</a>", 'info', '', true);
            } elseif (count($crons) === 1) {
                $obj = $crons[0];
                $cronType = $obj->objectname;
                $isActive = ($obj->status == 1);
                $expectedState = isset($expectedCronStates[$cronType]) ? $expectedCronStates[$cronType] : null;
                $isConform = ($expectedState === null) ? true : ($isActive === $expectedState);

                if ($isConform) {
                    $statusClass = 'success';
                    $status = $isActive ? $this->langs->trans("Active") : $this->langs->trans("Inactive") . ' (' . $this->langs->trans("ConformToConfig") . ')';
                } else {
                    $statusClass = 'warning';
                    $status = $isActive ? $this->langs->trans("Active") : $this->langs->trans("Inactive");
                }

                $displayLabel = $obj->label;
                if ($this->langs->trans($obj->label) !== $obj->label) {
                    $displayLabel = $this->langs->trans($obj->label);
                }
                $this->addCheck('cron', $displayLabel, $status, $statusClass);

                if (!$isActive && !$isConform) {
                    $baseUrl = dol_buildpath('/doli2shop/admin/health.php', 1);
                    $activateUrl = $baseUrl . "?action=activate_cron&cron_id=" . $obj->rowid . "&token=" . newToken();
                    $this->addCheck('cron', $this->langs->transnoentities('DiagCronActivateLabel'),
                        "<a href=\"$activateUrl\" class=\"button button-primary\" "
                        . "onclick=\"return confirm('" . dol_escape_js($this->langs->trans('DiagCronActivateConfirm')) . "')\">"
                        . $this->langs->trans('DiagCronActivateLinkText') . "</a>", 'info', '', true);
                }

                if ($obj->status && $obj->datelastrun) {
                    $lastRun = date('d/m/Y H:i:s', strtotime($obj->datelastrun));
                    $hoursAgo = (time() - strtotime($obj->datelastrun)) / 3600;
                    $this->addCheck('cron', $this->langs->transnoentities('DiagCronLastRunLabel'), $lastRun, $hoursAgo < 24 ? 'success' : 'warning');
                    if ($obj->lastresult === null || $obj->lastresult === '') {
                        $resultDisplay = $this->langs->transnoentities('DiagCronResultRunning');
                        $resultStatus = 'info';
                    } else {
                        $resultDisplay = $obj->lastresult;
                        $resultStatus = ($obj->lastresult == '0') ? 'success' : 'error';
                    }
                    $this->addCheck('cron', $this->langs->transnoentities('DiagCronResultLabel'), $resultDisplay, $resultStatus);
                    if ($obj->lastoutput) {
                        $this->addCheck('cron', $this->langs->transnoentities('DiagCronOutputLabel'), substr($obj->lastoutput, 0, 100) . '...', 'info');
                    }
                }
            }
        }

        $missingCronCount = 0;
        foreach ($allExpectedCrons as $cronType => $cronDef) {
            if (!isset($cronsByType[$cronType])) {
                $missingCronCount++;
                $displayLabel = $cronDef['label'];
                if ($this->langs->trans($cronDef['label']) !== $cronDef['label']) {
                    $displayLabel = $this->langs->trans($cronDef['label']);
                }
                $this->addCheck('cron', $displayLabel . ' (' . $cronType . ')',
                    $this->langs->transnoentities("CronMissing"), 'error', $this->langs->trans("CronMissingHelp"));
                $baseUrl = dol_buildpath('/doli2shop/admin/health.php', 1);
                $recreateUrl = $baseUrl . '?action=recreate_cron&cron_type=' . urlencode($cronType) . '&token=' . newToken();
                $this->addCheck('cron', "└─ " . $this->langs->transnoentities("RecreateCron"),
                    "<a href=\"" . htmlspecialchars($recreateUrl, ENT_QUOTES, 'UTF-8') . "\" class=\"button button-primary\" "
                    . "onclick=\"return confirm('" . dol_escape_js($this->langs->trans("ConfirmRecreateCron")) . "')\">"
                    . $this->langs->trans("RecreateCron") . "</a>", 'info', '', true);
            }
        }

        if ($duplicatesDetected) {
            $this->addCheck('cron', $this->langs->transnoentities('DiagCronSummaryLabel'), $this->langs->transnoentities('DiagCronSummaryDuplicatesValue'), 'error');
        } elseif ($cronCount === 0) {
            $this->addCheck('cron', $this->langs->transnoentities('DiagCronSummaryLabel'),
                $this->langs->trans("CronSummaryAllMissing", count($allExpectedCrons)), 'error');
        } elseif ($missingCronCount > 0) {
            $this->addCheck('cron', $this->langs->transnoentities('DiagCronSummaryLabel'),
                $this->langs->trans("CronSummaryPartialMissing", $cronCount, $missingCronCount), 'warning');
        } else {
            switch ($syncProductsDirection) {
                case 'none':
                    $resumeMessage = $this->langs->trans("CronConfigNone");
                    break;
                case 'shopify_to_dolibarr':
                    $resumeMessage = $this->langs->trans("CronConfigImportOnly");
                    break;
                case 'dolibarr_to_shopify':
                    $resumeMessage = $this->langs->trans("CronConfigExportOnly");
                    break;
                default:
                    $resumeMessage = $this->langs->trans("CronConfigBoth");
                    break;
            }
            $this->addCheck('cron', $this->langs->transnoentities('DiagCronSummaryLabel'), $resumeMessage, 'success');
        }
    }

    private function checkShopifyApi()
    {
        $this->results['api'] = ['title' => $this->langs->transnoentities('DiagSectionApiTitle'), 'checks' => []];

        $moduleEnabled = getDolGlobalBool('MAIN_MODULE_SHOPIFYINTEGRATION') ||
                        (function_exists('isModEnabled') && isModEnabled('doli2shop')) ||
                        (!empty($this->conf->doli2shop->enabled));
        if (!$moduleEnabled) {
            $this->addCheck('api', $this->langs->transnoentities('DiagApiTestLabel'), $this->langs->transnoentities('DiagApiModuleDisabledValue'), 'warning');
            return;
        }

        // Story 50-3 — les tests réseau Shopify (connexion, scopes, canaux) sont différés vers
        // le chargement AJAX par boutique (ajax/diagnostic_store_test.php) après le rendu de la
        // page, pour éviter N appels API synchrones (timeout Guzzle) au chargement. Réactivé en
        // synchrone pour l'export JSON et le fallback ?sync_tests=1 (JS désactivé).
        if ($this->deferApiTests) {
            $this->addCheck('api', $this->langs->transnoentities('DiagApiTestLabel'), $this->langs->transnoentities('DiagnosticApiTestsDeferred'), 'info');
            return;
        }

        require_once dirname(__FILE__) . '/../class/shopifyapi.class.php';

        // FIX 10 — check per-store quand des boutiques existent
        $activeStores = $this->getActiveStores();

        if (!empty($activeStores)) {
            // Mode multi-boutiques : tester chaque boutique active
            foreach ($activeStores as $store) {
                $storeLabel = !empty($store->label) ? $store->label : (string) $store->rowid;
                if (empty($store->access_token) || empty($store->shop_domain)) {
                    // Boutique jamais configurée : warning, pas error
                    $this->addCheck('api', $this->langs->transnoentities('DiagApiStoreLabel', $storeLabel), $this->langs->transnoentities('DiagApiStoreNotConnectedValue'), 'warning');
                    continue;
                }
                try {
                    $shopifyApiStore = ShopifyApi::forStore($this->db, $store);
                    $connectionResult = $shopifyApiStore->testShopifyConnection();
                    if ($connectionResult['connection']) {
                        $this->addCheck('api', $this->langs->transnoentities('DiagApiConnectionForStoreLabel', $storeLabel), $this->langs->transnoentities('DiagValueSuccess'), 'success');
                        if (!empty($connectionResult['shop_info']->name)) {
                            $this->addCheck('api', $this->langs->transnoentities('DiagApiStoreLabel', $storeLabel), $connectionResult['shop_info']->name, 'info');
                        }
                        // Story 50-2 — canaux/publications testés PAR BOUTIQUE (auparavant legacy uniquement)
                        $this->testChannels($shopifyApiStore, $storeLabel);
                    } else {
                        $this->addCheck('api', $this->langs->transnoentities('DiagApiConnectionForStoreLabel', $storeLabel), $this->langs->transnoentities('DiagValueFailure'), 'error');
                        if (!empty($connectionResult['errors'])) {
                            foreach ($connectionResult['errors'] as $error) {
                                $this->addCheck('api', $this->langs->transnoentities('DiagApiErrorForStoreLabel', $storeLabel), $error, 'error');
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->addCheck('api', $this->langs->transnoentities('DiagApiConnectionForStoreLabel', $storeLabel), $this->langs->transnoentities('DiagErrorWithMessage', $e->getMessage()), 'error');
                }
            }
            return;
        }

        // Mode legacy (0 boutique seedée) : comportement original mono-boutique
        $migrator = new ConfigurationMigrator($this->db);
        $configArray = $migrator->getConfiguration($this->conf->entity);

        if (empty($configArray) || empty($configArray['shopify_store_hostname']) || empty($configArray['shopify_access_token'])) {
            $this->addCheck('api', $this->langs->transnoentities('DiagApiTestLabel'), $this->langs->transnoentities('DiagApiConfigMissingValue'), 'error');
            return;
        }

        $config = (object)$configArray;
        if (empty($config->shopify_store_hostname) || empty($config->shopify_access_token)) {
            $this->addCheck('api', $this->langs->transnoentities('DiagApiTestLabel'), $this->langs->transnoentities('DiagApiConfigIncompleteValue'), 'error');
            return;
        }

        try {
            $shopifyApi = new ShopifyApi($this->db);
            $connectionResult = $shopifyApi->testShopifyConnection();

            if ($connectionResult['connection']) {
                $this->addCheck('api', $this->langs->transnoentities('DiagApiConnectionLabel'), $this->langs->transnoentities('DiagValueSuccess'), 'success');
                if (!empty($connectionResult['shop_info']->name)) {
                    $this->addCheck('api', $this->langs->transnoentities('DiagApiStoreNameLabel'), $connectionResult['shop_info']->name, 'info');
                }
                $this->testChannels($shopifyApi);
            } else {
                $this->addCheck('api', $this->langs->transnoentities('DiagApiConnectionLabel'), $this->langs->transnoentities('DiagValueFailure'), 'error');
                if (!empty($connectionResult['errors'])) {
                    foreach ($connectionResult['errors'] as $error) {
                        $this->addCheck('api', $this->langs->transnoentities('DiagApiErrorLabel'), $error, 'error');
                    }
                } else {
                    $this->addCheck('api', $this->langs->transnoentities('DiagApiErrorLabel'), $this->langs->transnoentities('DiagApiConnectionImpossibleValue'), 'error');
                }
            }
        } catch (\Throwable $e) {
            $this->addCheck('api', $this->langs->transnoentities('DiagApiTestLabel'), $this->langs->transnoentities('DiagErrorWithMessage', $e->getMessage()), 'error');
        }
    }

    /**
     * Tests de l'API Dolibarr.
     *
     * Story 57-4 (AC1bis) : le diagnostic photos (produit + catégorie) s'exécute désormais
     * INCONDITIONNELLEMENT, en tête de méthode, AVANT toute sortie anticipée (module désactivé,
     * API exposée désactivée...) — 57-7 a fait de l'accès direct base+disque le SEUL chemin de
     * lecture réel du module, qui ne dépend d'AUCUNE de ces conditions. Réécrire
     * checkProductPhotoSync() sans déplacer son appel n'aurait servi à rien pour les clients que
     * 57-5 va justement libérer de l'URL Dolibarr self / la clé API self (validation NO-GO du
     * 2026-08-08, cf. Change Log de la story).
     *
     * Le check "module API Dolibarr" qui suit est REQUALIFIÉ (AC4), pas supprimé : le module
     * Doli2Shop EXPOSE sa propre API (`class/api_doli2shop.class.php`, préfixe
     * `/doli2shop/...` de l'API REST Dolibarr), qui a besoin du module API/Web Services Dolibarr pour
     * fonctionner — c'est ce qu'il vérifie désormais EXPLICITEMENT, sans aucun rapport avec les
     * images. Il ne fait plus de `return` bloquant : rien en aval n'en dépend plus.
     * L'URL Dolibarr self et la clé API self sont RETIRÉES entièrement (pas seulement déclassées) :
     * vérifié qu'ils ne servent plus à rien nulle part dans le module — ni pour les images (accès
     * direct), ni pour l'API exposée (dont l'authentification est la clé Dolibarr de l'APPELANT
     * externe, jamais l'une de ces deux constantes). Les constantes elles-mêmes ne sont plus lues
     * par `admin/general.php`/`admin/setup.php` depuis 57-5 (retrait de l'interface + du mapping
     * `ConfigurationMigrator`) ; elles restent seulement présentes en base pour les installations
     * existantes, sans plus aucun lecteur ni écrivain dans le module.
     */
    private function checkDolibarrApi()
    {
        $this->results['dolibarr_api'] = ['title' => $this->langs->transnoentities('DolibarrApiDiagnostic'), 'checks' => []];

        $this->checkProductPhotoSync();
        $this->checkCategoryPhotoSync();

        $moduleEnabled = getDolGlobalBool('MAIN_MODULE_SHOPIFYINTEGRATION') ||
                        (function_exists('isModEnabled') && isModEnabled('doli2shop')) ||
                        (!empty($this->conf->doli2shop->enabled));
        if (!$moduleEnabled) {
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DolibarrApiDiagnostic'), $this->langs->transnoentities('ModuleDisabled'), 'warning');
            return;
        }

        // AC4 : requalifié — concerne UNIQUEMENT l'API EXPOSÉE par le module (class/api_doli2shop.class.php).
        if (empty($this->conf->api->enabled)) {
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('ApiModuleStatus'),
                $this->langs->trans('ApiModuleDisabled'), 'warning', $this->langs->trans('ApiModuleDisabledHelp'));
        } else {
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('ApiModuleStatus'), $this->langs->transnoentities('ApiModuleEnabled'), 'success');
        }
    }

    /**
     * Story 57-4 : diagnostic photos PRODUIT par ACCÈS DIRECT (`DolibarrDirectFileResolver`),
     * remplace l'ancien test HTTP self (Story 56.1) devenu trompeur depuis que 57-7 a fait de
     * l'accès direct base+disque le SEUL chemin de lecture réel du module — rejouer l'API
     * pouvait afficher une erreur alors que la synchronisation d'images fonctionnait, et
     * inversement rester tout vert alors que `multidir_output` n'était pas lisible.
     *
     * Ce check :
     * 1. Signale explicitement `PRODUCT_USE_OLD_PATH_FOR_PHOTO=1` (cause connue, cf. checkSystem()).
     * 2. Sélectionne UN produit réel possédant au moins une photo indexée (`llx_ecm_files`,
     *    entity-aware) — pattern SQL inchangé depuis 56.1.
     * 3. Délègue au resolver (AC0) : répertoire résolu (`describeProductPhotosDir()`), nombre de
     *    lignes indexées (`countIndexedProductImages()`) vs listées (`listProductImages()`) —
     *    jamais de reconstruction locale du chemin.
     * 4. Rapporte une cause DISTINCTE par échec (AC2, `doli2shopResolveProductPhotoDiagnosticCause()`
     *    dans lib/doli2shop.lib.php — fonction PURE, seul moyen d'avoir une preuve par test),
     *    jamais un « échec » générique.
     * 5. En cas de succès, lit réellement le contenu binaire d'une image (`getImageBinary()`) —
     *    preuve de bout en bout, comme l'ancien test faisait un download réel.
     *
     * SKIPPED explicite (statut 'info', pas une erreur) si aucun produit avec photo n'est trouvé.
     * Non bloquant : toute exception est interceptée, jamais de fatal. Aucun appel HTTP, aucune
     * URL, aucune clé d'API sur ce chemin (AC1).
     *
     * @return void
     * @since 2.5.0
     */
    private function checkProductPhotoSync()
    {
        try {
            // (1) Ancien chemin photos — cause connue, déjà signalée dans checkSystem() ; on le
            // rappelle ici dans le contexte du test photo réel.
            $useOldPathForPhoto = getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO', 0);
            if ($useOldPathForPhoto == 1) {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoOldPathLabel'),
                    $this->langs->trans('DiagProductPhotoOldPathValue'), 'warning',
                    $this->langs->trans('DiagProductPhotoOldPathHelp'));
            }

            // (2) Sélection d'UN produit réel avec photo, entity-aware (pattern importproducts.class.php:4141).
            // Review 56.1 (Blind Hunter + Edge Case Hunter + Acceptance Auditor) : la 1re version faisait un
            // JOIN SQL avec `ef.filepath LIKE CONCAT('produit/', p.ref, '/%')` — p.ref n'étant PAS échappé pour
            // les wildcards LIKE (% et _), une référence produit contenant ces caractères pouvait faire matcher
            // le mauvais produit. Fix : fichiers candidats via ecm_files (LIKE sur littéral fixe uniquement),
            // extraction du segment ref en PHP, puis égalité stricte `ref = '...'` (échappée par $db->escape(),
            // jamais de LIKE sur une valeur dynamique) — élimine la classe de bug plutôt que d'échapper le LIKE.
            // AC4 (story multicompany-ecm-files-prefixe-entite-jamais-reconnu) : en Multicompany,
            // avec entity > 1, `ef.filepath` est écrit sous la forme `{entity}/produit/{ref}`
            // (conf.class.php:737-742 + files.lib.php:2292), jamais `produit/{ref}` — le simple
            // LIKE 'produit/%' ne matchait donc plus JAMAIS aucune ligne pour ces entités (0
            // ligne, toujours), envoyant ce diagnostic sur « Ignoré » alors que l'index contient
            // des milliers de photos (constat client : 4570 lignes, entity=2). Le second membre
            // du OR (`REGEXP '^[0-9]+/produit/'`, compatible MySQL ET MariaDB, cf. audit Fable)
            // couvre la forme préfixée SANS jamais remplacer le LIKE existant (les deux formes
            // restent acceptées, aucune régression mono-entité). Duplication EXACTE de
            // `admin/diagnostic.php::checkProductPhotoSync()` — même correctif, même commentaire :
            // corriger l'un sans l'autre laisse l'écran Santé menteur (AC4).
            $sqlPhotoCandidates = "SELECT ef.filepath"
                . " FROM " . MAIN_DB_PREFIX . "ecm_files ef"
                . " WHERE ef.entity IN (" . getEntity('product') . ")"
                . " AND (ef.filepath LIKE 'produit/%' OR ef.filepath REGEXP '^[0-9]+/produit/')"
                . " AND ef.filepath NOT LIKE '%/thumbs/%'"
                . " AND (ef.filename LIKE '%.png' OR ef.filename LIKE '%.jpg' OR ef.filename LIKE '%.jpeg'"
                . " OR ef.filename LIKE '%.gif' OR ef.filename LIKE '%.webp')"
                . " ORDER BY ef.rowid ASC";
            $sqlPhotoCandidates .= $this->db->plimit(20);

            $dolibarrProductId = 0;
            $dolibarrProductRef = '';

            $resPhotoCandidates = $this->db->query($sqlPhotoCandidates);
            if ($resPhotoCandidates) {
                while ($objPhotoCandidate = $this->db->fetch_object($resPhotoCandidates)) {
                    // AC4 : extraction du ref par le segment SUIVANT 'produit', jamais un indice
                    // fixe (`explode('/')[1]` renvoyait 'produit' lui-même — pas le ref — dès
                    // qu'un préfixe d'entité précède, ex. filepath "2/produit/{ref}").
                    $filepathParts = array_values(array_filter(explode('/', trim($objPhotoCandidate->filepath, '/'))));
                    $produitSegmentIndex = array_search('produit', $filepathParts, true);
                    if ($produitSegmentIndex === false || empty($filepathParts[$produitSegmentIndex + 1])) {
                        continue;
                    }
                    $candidateRef = $filepathParts[$produitSegmentIndex + 1];

                    $sqlProductMatch = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product"
                        . " WHERE ref = '" . $this->db->escape($candidateRef) . "'"
                        . " AND entity IN (" . getEntity('product') . ")";
                    $sqlProductMatch .= $this->db->plimit(1);

                    $resProductMatch = $this->db->query($sqlProductMatch);
                    if ($resProductMatch && $this->db->num_rows($resProductMatch) > 0) {
                        $objProductMatch = $this->db->fetch_object($resProductMatch);
                        $dolibarrProductId = (int) $objProductMatch->rowid;
                        $dolibarrProductRef = $candidateRef;
                        $this->db->free($resProductMatch);
                        break;
                    }
                    if ($resProductMatch) {
                        $this->db->free($resProductMatch);
                    }
                }
                $this->db->free($resPhotoCandidates);
            }

            if ($dolibarrProductId <= 0) {
                // AC4 (story images-produit-non-indexees-jamais-synchronisees) : la sélection
                // ci-dessus ne regarde QUE l'index `llx_ecm_files` — un client dont TOUTES les
                // photos produit sont sur disque sans index (dossier support Xavier Hubier /
                // Europe Loisirs, 01/09/2026) ressortait ici en « Ignoré », message qui
                // l'envoyait sur une fausse piste (« vous n'avez aucune photo produit ») alors
                // que son ERP lui en montrait une. Avant de conclure « aucune photo nulle
                // part », on cherche — sur un échantillon borné, même pattern que
                // checkCategoryPhotoSync() — un produit dont le disque révèle des photos alors
                // que l'index n'en connaît aucune.
                $diskOnlyProduct = $this->findProductWithDiskOnlyPhoto(new DolibarrDirectFileResolver($this->db, $this->conf->entity));

                if ($diskOnlyProduct !== null) {
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoTestLabel'),
                        $this->langs->transnoentities('DiagProductPhotoDiskOnlyValue', $diskOnlyProduct['ref']), 'warning',
                        $this->langs->trans('DiagProductPhotoDiskOnlyHelp'));

                    // ⚠️ Relevé par la revue 3 couches (MEDIUM) : ce chemin retournait ici
                    // immédiatement, sans jamais exécuter la preuve par LECTURE BINAIRE que le
                    // chemin normal exécute toujours. On annonçait donc « photos trouvées sur le
                    // disque » sans avoir vérifié qu'on pouvait réellement les lire — or des
                    // droits Unix corrects sur le répertoire mais faux sur le fichier suffisent
                    // à casser l'envoi. Tout l'esprit de ce contrôle est la preuve par lecture
                    // réelle : on l'applique donc aussi ici.
                    $this->proveProductPhotoBinaryReadable($diskOnlyProduct['id'], $diskOnlyProduct['ref']);

                    return;
                }

                // SKIPPED explicite : réellement aucune photo (ni indexée, ni sur disque) dans
                // l'échantillon testé — pas un échec.
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoTestLabel'),
                    $this->langs->trans('DiagProductPhotoTestSkipped'), 'info',
                    $this->langs->trans('DiagProductPhotoTestSkippedHelp'));
                return;
            }

            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoTestProductLabel'),
                $dolibarrProductRef . ' (id=' . $dolibarrProductId . ')', 'info');

            // (3) Accès direct : délégation intégrale au resolver (AC0) — jamais de reconstruction
            // locale du chemin (piège identifié par la validation NO-GO du 2026-08-08).
            $product = new Product($this->db);
            $product->fetch($dolibarrProductId);

            $resolver = new DolibarrDirectFileResolver($this->db, $this->conf->entity);
            $dirDescribe = $resolver->describeProductPhotosDir($product);
            $ecmFilesRowCount = $resolver->countIndexedProductImages($product);
            $listedImages = $resolver->listProductImages($product);
            $listedImagesCount = count($listedImages);

            $productEntity = !empty($product->entity) ? (int) $product->entity : null;
            $verdict = doli2shopResolveProductPhotoDiagnosticCause(
                $dirDescribe,
                $ecmFilesRowCount,
                $listedImagesCount,
                $productEntity,
                (int) $this->conf->entity
            );

            // AC2 — entité du produit ≠ entité courante : signalé à l'écran INDÉPENDAMMENT du
            // verdict de répertoire (multi-company, entités partagées — pas nécessairement un défaut).
            if ($verdict['entityMismatch']) {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoEntityMismatchLabel'),
                    $this->langs->transnoentities('DiagProductPhotoEntityMismatchValue', $productEntity, (int) $this->conf->entity),
                    'info');
            }

            $dirIsProblem = in_array($verdict['cause'], ['dir_unresolved', 'dir_not_found', 'dir_not_readable'], true);
            $dirValue = $dirDescribe['path'] ?? $this->langs->transnoentities('DiagProductPhotoDirUnresolvedValue');
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoDirLabel'), $dirValue,
                $dirIsProblem ? 'error' : 'info');

            // (4) Cause DISTINCTE par échec (AC2) — jamais un « échec » générique.
            switch ($verdict['cause']) {
                case 'dir_unresolved':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoResultLabel'),
                        $this->langs->trans('DiagProductPhotoDirUnresolvedValue'), 'error',
                        $this->langs->trans('DiagProductPhotoDirUnresolvedHelp'));
                    return;
                case 'dir_not_found':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoResultLabel'),
                        $this->langs->trans('DiagProductPhotoDirNotFoundValue'), 'error',
                        $this->langs->trans('DiagProductPhotoDirNotFoundHelp'));
                    return;
                case 'dir_not_readable':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoResultLabel'),
                        $this->langs->trans('DiagProductPhotoDirNotReadableValue'), 'error',
                        $this->langs->trans('DiagProductPhotoDirNotReadableHelp'));
                    return;
                case 'file_missing_on_disk':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoResultLabel'),
                        $this->langs->transnoentities('DiagProductPhotoFileMissingOnDiskValue', $ecmFilesRowCount, $listedImagesCount), 'error',
                        $this->langs->trans('DiagProductPhotoFileMissingOnDiskHelp'));
                    return;
                case 'no_indexed_images':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoResultLabel'),
                        $this->langs->trans('DiagProductPhotoNoIndexedImagesValue'), 'warning',
                        $this->langs->trans('DiagProductPhotoNoIndexedImagesHelp'));
                    return;
            }

            // (5) 'ok' : au moins une image listée -> preuve par lecture binaire réelle, bout en bout.
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoResultLabel'),
                $this->langs->transnoentities('DiagProductPhotoOkValue', $listedImagesCount), 'success',
                $this->langs->trans('DiagProductPhotoOkHelp'));

            $firstImage = $listedImages[0];
            $filename = basename($firstImage['relativename']);
            $binary = $resolver->getImageBinary($product, $filename);

            if ($binary !== null && !empty($binary['content'])) {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoReadLabel'),
                    $this->langs->transnoentities('DiagProductPhotoReadOkValue', strlen($binary['content'])), 'success',
                    $this->langs->trans('DiagProductPhotoReadHelp'));
            } else {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoReadLabel'),
                    $this->langs->trans('DiagProductPhotoReadFailedValue'), 'warning',
                    $this->langs->trans('DiagProductPhotoReadFailedHelp'));
            }
        } catch (\Throwable $e) {
            // Non bloquant : ce check ne doit jamais faire échouer le diagnostic global.
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoTestLabel'),
                $this->langs->trans('DiagProductPhotoUnexpectedError') . ' : ' . $e->getMessage(), 'error');
        }
    }


    /**
     * Preuve par LECTURE BINAIRE réelle d'une photo produit trouvée par le repli disque.
     *
     * ⚠️ Ajoutée après la revue 3 couches (MEDIUM) : le chemin « photos sur disque non
     * indexées » annonçait sa découverte puis retournait immédiatement, sans exécuter la preuve
     * de lecture que le chemin normal exécute toujours. On affirmait donc « photos trouvées »
     * sans avoir vérifié qu'on savait les LIRE — or des droits Unix corrects sur le répertoire
     * mais faux sur le fichier suffisent à casser l'envoi vers Shopify, et le client se
     * retrouverait avec un diagnostic rassurant et toujours aucune image.
     *
     * Non bloquant par construction : ce contrôle ne doit jamais faire échouer le diagnostic.
     *
     * @param int    $productId  Identifiant du produit témoin
     * @param string $productRef Référence du produit témoin
     * @return void
     */
    private function proveProductPhotoBinaryReadable(int $productId, string $productRef): void
    {
        try {
            $resolver = new DolibarrDirectFileResolver($this->db, $this->conf->entity);

            $product = new Product($this->db);
            if ($product->fetch($productId) <= 0) {
                return;
            }

            $images = $resolver->listProductImages($product);
            if (empty($images)) {
                return;
            }

            $filename = basename($images[0]['relativename']);
            $binary = $resolver->getImageBinary($product, $filename);

            if ($binary !== null && !empty($binary['content'])) {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoReadLabel'),
                    $this->langs->transnoentities('DiagProductPhotoReadOkValue', strlen($binary['content'])), 'success',
                    $this->langs->trans('DiagProductPhotoReadHelp'));
            } else {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagProductPhotoReadLabel'),
                    $this->langs->trans('DiagProductPhotoReadFailedValue'), 'warning',
                    $this->langs->trans('DiagProductPhotoReadFailedHelp'));
            }
        } catch (\Throwable $e) {
            // Silencieux : la découverte disque-only a déjà été annoncée, cette preuve est un
            // complément. Ne jamais dégrader le diagnostic global pour un échec ici.
            return;
        }
    }

    /**
     * AC4 (story images-produit-non-indexees-jamais-synchronisees) : cherche, sur un échantillon
     * BORNÉ de produits de l'entité courante (même pattern que checkCategoryPhotoSync() ci-dessous
     * — `rowid ASC`, limite 50), un produit dont le DISQUE révèle des photos alors qu'AUCUNE ligne
     * `llx_ecm_files` ne l'indexe. Distingue ainsi « aucune photo nulle part » (état légitime,
     * SKIPPED) de « photos désynchronisées » (le cas Xavier Hubier / Europe Loisirs, dossier
     * support du 01/09/2026) — la sélection SQL de `checkProductPhotoSync()` ne regarde QUE
     * l'index et ne peut structurellement pas voir ce cas.
     *
     * Ne scanne JAMAIS le disque de TOUS les produits de l'installation : s'arrête au premier
     * produit trouvé, comme le volet catégorie. `countIndexedProductImages() > 0` élimine tout
     * produit déjà couvert par la sélection indexée ci-dessus (défense en profondeur — ne devrait
     * plus se produire puisque cette méthode n'est appelée QUE si cette sélection a échoué).
     *
     * @param DolibarrDirectFileResolver $resolver Resolver déjà instancié par l'appelant
     * @return array{id:int, ref:string}|null
     * @since 2.5.3
     */
    private function findProductWithDiskOnlyPhoto(DolibarrDirectFileResolver $resolver): ?array
    {
        $sqlProductSample = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product"
            . " WHERE entity IN (" . getEntity('product') . ")"
            . " ORDER BY rowid ASC";
        $sqlProductSample .= $this->db->plimit(50);

        $resProductSample = $this->db->query($sqlProductSample);
        if (!$resProductSample) {
            return null;
        }

        $found = null;
        while ($objProductSample = $this->db->fetch_object($resProductSample)) {
            $candidateProduct = new Product($this->db);
            if ($candidateProduct->fetch((int) $objProductSample->rowid) <= 0) {
                continue;
            }

            // countIndexedProductImages()==0 ET listProductImages() non vide = preuve directe
            // que la seule source qui a trouvé quelque chose est le repli disque (AC1) —
            // symétrique de la boucle checkCategoryPhotoSync() ci-dessous.
            if ($resolver->countIndexedProductImages($candidateProduct) > 0) {
                continue;
            }

            if (!empty($resolver->listProductImages($candidateProduct))) {
                $found = ['id' => (int) $candidateProduct->id, 'ref' => (string) $candidateProduct->ref];
                break;
            }
        }
        $this->db->free($resProductSample);

        return $found;
    }

    /**
     * Story 57-4 (AC3) : pendant CATÉGORIE de {@see checkProductPhotoSync()} — 57-7 a livré la
     * lecture directe des images de catégorie, restée invisible du diagnostic jusqu'ici.
     *
     * Les photos de catégorie ne sont JAMAIS indexées dans `llx_ecm_files` (asymétrie assumée du
     * resolver, cf. `DolibarrDirectFileResolver`, Story 57-7) : le raccourci SQL du volet produit
     * est inapplicable. Itère donc sur un échantillon BORNÉ de catégories de l'entité courante
     * (`rowid ASC`, limite 50) et appelle `listCategoryImages()` jusqu'au premier résultat non
     * vide — ne scanne JAMAIS le disque de TOUTES les catégories depuis cette page.
     *
     * Même structure de verdict que le volet produit
     * (`doli2shopResolveCategoryPhotoDiagnosticCause()`), sans la cause `file_missing_on_disk`
     * (pas d'index à comparer côté catégorie).
     *
     * @return void
     * @since 2.5.0
     */
    private function checkCategoryPhotoSync()
    {
        try {
            $sqlCategoryCandidates = "SELECT rowid FROM " . MAIN_DB_PREFIX . "categorie"
                . " WHERE entity = " . (int) $this->conf->entity
                . " ORDER BY rowid ASC";
            $sqlCategoryCandidates .= $this->db->plimit(50);

            $resolver = new DolibarrDirectFileResolver($this->db, $this->conf->entity);

            $dolibarrCategoryId = 0;
            $categoryImages = [];

            $resCategoryCandidates = $this->db->query($sqlCategoryCandidates);
            if ($resCategoryCandidates) {
                while ($objCategoryCandidate = $this->db->fetch_object($resCategoryCandidates)) {
                    $candidateCategory = new Categorie($this->db);
                    $candidateCategory->fetch((int) $objCategoryCandidate->rowid);
                    $images = $resolver->listCategoryImages($candidateCategory);
                    if (!empty($images)) {
                        $dolibarrCategoryId = (int) $objCategoryCandidate->rowid;
                        $categoryImages = $images;
                        break;
                    }
                }
                $this->db->free($resCategoryCandidates);
            }

            if ($dolibarrCategoryId <= 0) {
                // SKIPPED explicite : aucune catégorie avec photo dans l'échantillon borné, pas un échec.
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoTestLabel'),
                    $this->langs->trans('DiagCategoryPhotoTestSkipped'), 'info',
                    $this->langs->trans('DiagCategoryPhotoTestSkippedHelp'));
                return;
            }

            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoTestCategoryLabel'),
                'id=' . $dolibarrCategoryId, 'info');

            $category = new Categorie($this->db);
            $category->fetch($dolibarrCategoryId);

            $dirDescribe = $resolver->describeCategoryPhotosDir($category);
            $listedImagesCount = count($categoryImages);
            $categoryEntity = !empty($category->entity) ? (int) $category->entity : null;

            $verdict = doli2shopResolveCategoryPhotoDiagnosticCause(
                $dirDescribe,
                $listedImagesCount,
                $categoryEntity,
                (int) $this->conf->entity
            );

            if ($verdict['entityMismatch']) {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoEntityMismatchLabel'),
                    $this->langs->transnoentities('DiagCategoryPhotoEntityMismatchValue', $categoryEntity, (int) $this->conf->entity),
                    'info');
            }

            $dirIsProblem = in_array($verdict['cause'], ['dir_unresolved', 'dir_not_found', 'dir_not_readable'], true);
            $dirValue = $dirDescribe['path'] ?? $this->langs->transnoentities('DiagCategoryPhotoDirUnresolvedValue');
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoDirLabel'), $dirValue,
                $dirIsProblem ? 'error' : 'info');

            switch ($verdict['cause']) {
                case 'dir_unresolved':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoResultLabel'),
                        $this->langs->trans('DiagCategoryPhotoDirUnresolvedValue'), 'error',
                        $this->langs->trans('DiagCategoryPhotoDirUnresolvedHelp'));
                    return;
                case 'dir_not_found':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoResultLabel'),
                        $this->langs->trans('DiagCategoryPhotoDirNotFoundValue'), 'error',
                        $this->langs->trans('DiagCategoryPhotoDirNotFoundHelp'));
                    return;
                case 'dir_not_readable':
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoResultLabel'),
                        $this->langs->trans('DiagCategoryPhotoDirNotReadableValue'), 'error',
                        $this->langs->trans('DiagCategoryPhotoDirNotReadableHelp'));
                    return;
                case 'no_images':
                    // Ne devrait pas arriver : la sélection ne retient qu'une catégorie avec AU
                    // MOINS une image (cf. boucle ci-dessus). Conservé par sûreté défensive.
                    $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoResultLabel'),
                        $this->langs->trans('DiagCategoryPhotoNoImagesValue'), 'warning');
                    return;
            }

            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoResultLabel'),
                $this->langs->transnoentities('DiagCategoryPhotoOkValue', $listedImagesCount), 'success',
                $this->langs->trans('DiagCategoryPhotoOkHelp'));

            $firstImage = $categoryImages[0];
            $binary = $resolver->getCategoryImageBinary($category, $firstImage['filename']);

            if ($binary !== null && !empty($binary['content'])) {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoReadLabel'),
                    $this->langs->transnoentities('DiagCategoryPhotoReadOkValue', strlen($binary['content'])), 'success',
                    $this->langs->trans('DiagCategoryPhotoReadHelp'));
            } else {
                $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoReadLabel'),
                    $this->langs->trans('DiagCategoryPhotoReadFailedValue'), 'warning',
                    $this->langs->trans('DiagCategoryPhotoReadFailedHelp'));
            }
        } catch (\Throwable $e) {
            $this->addCheck('dolibarr_api', $this->langs->transnoentities('DiagCategoryPhotoTestLabel'),
                $this->langs->trans('DiagCategoryPhotoUnexpectedError') . ' : ' . $e->getMessage(), 'error');
        }
    }

    private function checkRecentSyncs()
    {
        $this->results['sync'] = ['title' => $this->langs->transnoentities('DiagSectionSyncTitle'), 'checks' => []];

        $entityId = (int) $this->conf->entity;

        $activeStores = $this->getActiveStores();

        if (!empty($activeStores)) {
            // --- Totaux globaux (toutes boutiques confondues) ---
            $sqlProdTotal = "SELECT COUNT(*) as total, MAX(tms) as last_sync"
                . " FROM " . MAIN_DB_PREFIX . "doli2shop_products"
                . " WHERE entity = " . $entityId;
            $resProdTotal = $this->db->query($sqlProdTotal);
            if ($resProdTotal) {
                $objProdTotal = $this->db->fetch_object($resProdTotal);
                $this->addCheck('sync', $this->langs->transnoentities('SyncTotalProducts'), (string) (int) $objProdTotal->total, 'info');
            }

            $sqlOrdTotal = "SELECT COUNT(*) as total, MAX(tms) as last_sync"
                . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders"
                . " WHERE entity = " . $entityId;
            $resOrdTotal = $this->db->query($sqlOrdTotal);
            if ($resOrdTotal) {
                $objOrdTotal = $this->db->fetch_object($resOrdTotal);
                $this->addCheck('sync', $this->langs->transnoentities('SyncTotalOrders'), (string) (int) $objOrdTotal->total, 'info');
            }

            // --- Détail par boutique : produits (fk_store) ---
            foreach ($activeStores as $store) {
                $storeLabel = !empty($store->label) ? $store->label : (string) $store->rowid; // brut : échappé au rendu (évite le double-encodage)
                $storeId    = (int) $store->rowid;
                $isDefault  = ((int) $store->is_default === 1);

                // Boutique par défaut = fk_store IN (0, <id>) pour couvrir les lignes historiques
                if ($isDefault) {
                    $fkStoreCond = "(fk_store = 0 OR fk_store = " . $storeId . ")";
                } else {
                    $fkStoreCond = "fk_store = " . $storeId;
                }

                // Produits par boutique
                $sqlProd = "SELECT COUNT(*) as total, MAX(tms) as last_sync"
                    . " FROM " . MAIN_DB_PREFIX . "doli2shop_products"
                    . " WHERE entity = " . $entityId
                    . " AND " . $fkStoreCond;
                $resProd = $this->db->query($sqlProd);
                if ($resProd) {
                    $objProd = $this->db->fetch_object($resProd);
                    $this->addCheck('sync', $this->langs->transnoentities('SyncProductsForStore', $storeLabel), (string) (int) $objProd->total, 'info');
                    if (!empty($objProd->last_sync)) {
                        $lastSync = date('d/m/Y H:i:s', strtotime($objProd->last_sync));
                        $daysAgo  = (time() - strtotime($objProd->last_sync)) / 86400;
                        $this->addCheck('sync', $this->langs->transnoentities('SyncLastProductsForStore', $storeLabel), $lastSync, $daysAgo < 7 ? 'success' : 'warning');
                    }
                }

                // Commandes par boutique
                $sqlOrd = "SELECT COUNT(*) as total, MAX(tms) as last_sync"
                    . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders"
                    . " WHERE entity = " . $entityId
                    . " AND " . $fkStoreCond;
                $resOrd = $this->db->query($sqlOrd);
                if ($resOrd) {
                    $objOrd = $this->db->fetch_object($resOrd);
                    $this->addCheck('sync', $this->langs->transnoentities('SyncOrdersForStore', $storeLabel), (string) (int) $objOrd->total, 'info');
                    if (!empty($objOrd->last_sync)) {
                        $lastSync = date('d/m/Y H:i:s', strtotime($objOrd->last_sync));
                        $daysAgo  = (time() - strtotime($objOrd->last_sync)) / 86400;
                        $this->addCheck('sync', $this->langs->transnoentities('SyncLastOrdersForStore', $storeLabel), $lastSync, $daysAgo < 7 ? 'success' : 'warning');
                    }
                }
            }

            // --- Collections : pas de fk_store — compteur global unique ---
            $sqlColl = "SELECT COUNT(*) as total, MAX(tms) as last_sync"
                . " FROM " . MAIN_DB_PREFIX . "doli2shop_collections"
                . " WHERE entity = " . $entityId;
            $resColl = $this->db->query($sqlColl);
            if ($resColl) {
                $objColl = $this->db->fetch_object($resColl);
                $this->addCheck('sync', $this->langs->transnoentities('SyncTotalCollections'), (string) (int) $objColl->total, 'info',
                    $this->langs->trans('SyncCollectionsGlobalNote'));
                if (!empty($objColl->last_sync)) {
                    $lastSync = date('d/m/Y H:i:s', strtotime($objColl->last_sync));
                    $this->addCheck('sync', $this->langs->transnoentities('DiagSyncLastCollectionsLabel'), $lastSync, 'info');
                }
            }
        } else {
            // Chemin legacy (0 boutique seedée) : comportement original
            $sql = "SELECT COUNT(*) as total, MAX(tms) as last_sync FROM " . MAIN_DB_PREFIX . "doli2shop_products WHERE entity = " . $entityId;
            $result = $this->db->query($sql);
            if ($result) {
                $obj = $this->db->fetch_object($result);
                $this->addCheck('sync', $this->langs->transnoentities('DiagSyncProductsCountLabel'), (string) (int) $obj->total, 'info');
                if (!empty($obj->last_sync)) {
                    $lastSync = date('d/m/Y H:i:s', strtotime($obj->last_sync));
                    $daysAgo = (time() - strtotime($obj->last_sync)) / 86400;
                    $this->addCheck('sync', $this->langs->transnoentities('DiagSyncLastProductsLabel'), $lastSync, $daysAgo < 7 ? 'success' : 'warning');
                }
            }

            $sql = "SELECT COUNT(*) as total, MAX(tms) as last_sync FROM " . MAIN_DB_PREFIX . "doli2shop_orders WHERE entity = " . $entityId;
            $result = $this->db->query($sql);
            if ($result) {
                $obj = $this->db->fetch_object($result);
                $this->addCheck('sync', $this->langs->transnoentities('DiagSyncOrdersCountLabel'), (string) (int) $obj->total, 'info');
                if (!empty($obj->last_sync)) {
                    $lastSync = date('d/m/Y H:i:s', strtotime($obj->last_sync));
                    $daysAgo = (time() - strtotime($obj->last_sync)) / 86400;
                    $this->addCheck('sync', $this->langs->transnoentities('DiagSyncLastOrdersLabel'), $lastSync, $daysAgo < 7 ? 'success' : 'warning');
                }
            }

            $sql = "SELECT COUNT(*) as total, MAX(tms) as last_sync FROM " . MAIN_DB_PREFIX . "doli2shop_collections WHERE entity = " . $entityId;
            $result = $this->db->query($sql);
            if ($result) {
                $obj = $this->db->fetch_object($result);
                $this->addCheck('sync', $this->langs->transnoentities('DiagSyncCollectionsMappedLabel'), (string) (int) $obj->total, 'info');
                if (!empty($obj->last_sync)) {
                    $lastSync = date('d/m/Y H:i:s', strtotime($obj->last_sync));
                    $this->addCheck('sync', $this->langs->transnoentities('DiagSyncLastCollectionsLabel'), $lastSync, 'info');
                }
            }
        }

        // Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (AC1/AC2/AC3) : le
        // contrôle totalPriceSet/total_ttc existe depuis la v2.3.0
        // (ShopifyOrderManager::createOrder()) mais n'aboutissait qu'au log — jamais à l'écran,
        // jamais compté. Persisté désormais sur llx_doli2shop_orders (totals_mismatch_severity /
        // shopify_total_ttc / dolibarr_total_ttc), remonté ici via la fonction PURE
        // doli2shopBuildOrdersTotalsMismatchReport() de lib/doli2shop.lib.php — seule partie
        // testable unitairement de ce diagnostic (cf. son docblock).
        $sqlMismatchCounts = "SELECT totals_mismatch_severity, COUNT(*) as total"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders"
            . " WHERE entity = " . $entityId
            . " AND totals_mismatch_severity IS NOT NULL"
            . " GROUP BY totals_mismatch_severity";
        $resMismatchCounts = $this->db->query($sqlMismatchCounts);
        $roundingCount = 0;
        $proportionalCount = 0;
        if ($resMismatchCounts) {
            while ($objMismatchCount = $this->db->fetch_object($resMismatchCounts)) {
                if ($objMismatchCount->totals_mismatch_severity === 'proportional') {
                    $proportionalCount = (int) $objMismatchCount->total;
                } elseif ($objMismatchCount->totals_mismatch_severity === 'rounding') {
                    $roundingCount = (int) $objMismatchCount->total;
                }
            }
        }

        // AC1 : identification des commandes concernées (pas seulement un compteur) — les plus
        // récentes d'abord, pré-bornées à 50 côté SQL avant troncature finale (10) par la
        // fonction pure ci-dessous.
        $recentOrderRefs = array();
        $sqlMismatchRefs = "SELECT c.ref as order_ref"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_orders o"
            . " INNER JOIN " . MAIN_DB_PREFIX . "commande c ON c.rowid = o.fk_commande"
            . " WHERE o.entity = " . $entityId
            . " AND o.totals_mismatch_severity IS NOT NULL"
            . " ORDER BY o.tms DESC"
            . " LIMIT 50";
        $resMismatchRefs = $this->db->query($sqlMismatchRefs);
        if ($resMismatchRefs) {
            while ($objMismatchRef = $this->db->fetch_object($resMismatchRefs)) {
                $recentOrderRefs[] = (string) $objMismatchRef->order_ref;
            }
        }

        $mismatchReport = doli2shopBuildOrdersTotalsMismatchReport($roundingCount, $proportionalCount, $recentOrderRefs, 10);

        $mismatchNote = '';
        if ($mismatchReport['total'] > 0) {
            $mismatchNote = $this->langs->trans(
                'DiagOrdersTotalsMismatchBreakdown',
                (string) $mismatchReport['rounding'],
                (string) $mismatchReport['proportional']
            );
            if (!empty($mismatchReport['sample_refs'])) {
                $mismatchNote .= ' ' . $this->langs->trans(
                    'DiagOrdersTotalsMismatchOrdersList',
                    implode(', ', $mismatchReport['sample_refs'])
                );
                if ($mismatchReport['sample_omitted'] > 0) {
                    $mismatchNote .= ' ' . $this->langs->trans('DiagOrdersTotalsMismatchMoreOmitted', (string) $mismatchReport['sample_omitted']);
                }
            }
        }

        $this->addCheck(
            'sync',
            $this->langs->transnoentities('DiagOrdersTotalsMismatchLabel'),
            (string) $mismatchReport['total'],
            $mismatchReport['status'],
            $mismatchNote
        );

        // Story variante-sans-correspondance-sku-ignoree-en-silence (AC1/AC2/AC3) : le contrôle
        // d'appariement SKU existe depuis toujours (ImportProducts::updateVariantInventory())
        // mais n'aboutissait qu'au silence total — jamais un log, jamais un compteur (signalé en
        // production par une cliente dont le stock d'une déclinaison restait figé à 0 malgré un
        // diagnostic à "0 erreur"). Persisté désormais sur llx_doli2shop_products
        // (variant_mismatch_dol_count / variant_mismatch_shopify_count / variant_mismatch_note),
        // remonté ici via la fonction PURE doli2shopBuildVariantMismatchReport() de
        // lib/doli2shop.lib.php — même patron que le contrôle d'écart de totaux ci-dessus. Même
        // section que admin/diagnostic.php::checkRecentSyncs() (parité —
        // test/unit/DiagnosticHealthParityGuardTest.php).
        $sqlVariantMismatchTotals = "SELECT SUM(variant_mismatch_dol_count) as dol_total,"
            . " SUM(variant_mismatch_shopify_count) as shopify_total"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_products"
            . " WHERE entity = " . $entityId
            . " AND fk_product_parent IS NULL"
            . " AND (variant_mismatch_dol_count > 0 OR variant_mismatch_shopify_count > 0)";
        $resVariantMismatchTotals = $this->db->query($sqlVariantMismatchTotals);
        $variantDolMismatchTotal = 0;
        $variantShopifyMismatchTotal = 0;
        if ($resVariantMismatchTotals) {
            $objVariantMismatchTotals = $this->db->fetch_object($resVariantMismatchTotals);
            $variantDolMismatchTotal = (int) $objVariantMismatchTotals->dol_total;
            $variantShopifyMismatchTotal = (int) $objVariantMismatchTotals->shopify_total;
        }

        $recentVariantMismatchRefs = array();
        $sqlVariantMismatchRefs = "SELECT p.ref as product_ref"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_products dp"
            . " INNER JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = dp.fk_product"
            . " WHERE dp.entity = " . $entityId
            . " AND dp.fk_product_parent IS NULL"
            . " AND (dp.variant_mismatch_dol_count > 0 OR dp.variant_mismatch_shopify_count > 0)"
            . " ORDER BY dp.variant_mismatch_tms DESC"
            . " LIMIT 50";
        $resVariantMismatchRefs = $this->db->query($sqlVariantMismatchRefs);
        if ($resVariantMismatchRefs) {
            while ($objVariantMismatchRef = $this->db->fetch_object($resVariantMismatchRefs)) {
                $recentVariantMismatchRefs[] = (string) $objVariantMismatchRef->product_ref;
            }
        }

        $variantMismatchReport = doli2shopBuildVariantMismatchReport(
            $variantDolMismatchTotal,
            $variantShopifyMismatchTotal,
            $recentVariantMismatchRefs,
            10
        );

        $variantMismatchNote = '';
        if ($variantMismatchReport['total'] > 0) {
            $variantMismatchNote = $this->langs->trans(
                'DiagVariantMismatchBreakdown',
                (string) $variantMismatchReport['dol'],
                (string) $variantMismatchReport['shopify']
            );
            if (!empty($variantMismatchReport['sample_refs'])) {
                $variantMismatchNote .= ' ' . $this->langs->trans(
                    'DiagVariantMismatchProductsList',
                    implode(', ', $variantMismatchReport['sample_refs'])
                );
                if ($variantMismatchReport['sample_omitted'] > 0) {
                    $variantMismatchNote .= ' ' . $this->langs->trans('DiagVariantMismatchMoreOmitted', (string) $variantMismatchReport['sample_omitted']);
                }
            }
        }

        $this->addCheck(
            'sync',
            $this->langs->transnoentities('DiagVariantMismatchLabel'),
            (string) $variantMismatchReport['total'],
            $variantMismatchReport['status'],
            $variantMismatchNote
        );
    }

    /**
     * Inventaire des lignes de webhooks (Story 2.4.7-6, AC1 — dossier Nicolas Graillon). Portage
     * de `admin/diagnostic.php::checkWebhookRows()` (Story 2.4.7-2), section construite pour ce
     * même dossier support mais livrée uniquement sur la page qui redirige vers *Santé* — donc
     * jamais atteinte par le client à qui on demandait de l'ouvrir.
     *
     * Même requête SQL, mêmes clés de traduction, même signalement des topics portés par
     * plusieurs lignes, même troncature bornée. Le comptage / la détection des doublons / la
     * troncature sont délégués à la fonction pure `doli2shopSummarizeWebhookInventoryRows()` de
     * lib/doli2shop.lib.php (AC3) — cette méthode ne fait que lire les lignes et rendre le rapport.
     *
     * @since 2.4.7
     */
    private function checkWebhookRows()
    {
        // Comme toutes les autres sections : le titre doit exister, l'affichage le lit directement
        // (`$section['title']`) sans valeur de repli.
        $this->results['webhooks'] = [
            'title' => $this->langs->transnoentities('DiagWebhookRowsTitle'),
            'checks' => []
        ];

        $sql = "SELECT rowid, topic, fk_store, webhook_id, status, entity";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks";
        $sql .= " WHERE entity = " . ((int) $this->conf->entity);
        $sql .= " ORDER BY topic, fk_store, rowid";

        $resql = $this->db->query($sql);
        if (!$resql) {
            // Même distinction table-absente / vraie panne que admin/diagnostic.php.
            $tableMissing = (stripos((string) $this->db->lasterror(), "doesn't exist") !== false)
                || (stripos((string) $this->db->lasterror(), 'no such table') !== false);

            $this->addCheck(
                'webhooks',
                $this->langs->transnoentities('DiagWebhookRowsTitle'),
                $tableMissing
                    ? $this->langs->transnoentities('DiagWebhookRowsTableMissing')
                    : $this->langs->transnoentities('DiagWebhookRowsQueryFailed'),
                'warning',
                $tableMissing
                    ? $this->langs->transnoentities('DiagWebhookRowsTableMissingNote')
                    : $this->db->lasterror()
            );
            return;
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }

        if (empty($rows)) {
            $this->addCheck('webhooks', $this->langs->transnoentities('DiagWebhookRowsTitle'),
                $this->langs->transnoentities('DiagWebhookRowsNone'), 'info');
            return;
        }

        $summary = doli2shopSummarizeWebhookInventoryRows($rows);

        $this->addCheck('webhooks', $this->langs->transnoentities('DiagWebhookRowsTitle'),
            (string) $summary['total'], 'info');

        // Un topic porté par plusieurs lignes est le motif exact que les hotfix 2.4.6/2.4.7
        // rendent inoffensif mais que la migration de déduplication devra traiter : on le signale
        // explicitement plutôt que de laisser le lecteur compter les lignes à la main.
        $duplicated = array();
        foreach ($summary['duplicated_topics'] as $topicKey => $count) {
            // Revue 3 couches (LOW) : un topic vide/null ne doit jamais s'afficher comme un
            // libellé blanc — même traitement que webhook_id manquant ci-dessous.
            $topicLabel = ($topicKey === '') ? $this->langs->transnoentities('DiagValueMissing') : $topicKey;
            $duplicated[] = $topicLabel . ' (' . $count . ')';
        }
        $this->addCheck(
            'webhooks',
            $this->langs->transnoentities('DiagWebhookRowsDuplicatedTopics'),
            empty($duplicated) ? $this->langs->transnoentities('DiagValueNone') : implode(', ', $duplicated),
            empty($duplicated) ? 'success' : 'warning',
            empty($duplicated) ? '' : $this->langs->transnoentities('DiagWebhookRowsDuplicatedNote')
        );

        if ($summary['duplicated_topics_omitted'] > 0) {
            // Revue 3 couches (LOW) : même convention de troncature que le reste de la page.
            $this->addCheck(
                'webhooks',
                $this->langs->transnoentities('DiagWebhookRowsTruncated'),
                (string) $summary['duplicated_topics_omitted'],
                'info'
            );
        }

        // Revue 3 couches (MEDIUM, admin/diagnostic.php) : liste bornée, avec annonce du reste,
        // même motif que checkFileIntegrity() ci-dessous.
        $rowsOmitted = $summary['rows_omitted'];

        foreach ($summary['rows_listed'] as $obj) {
            $webhookId = (string) ($obj->webhook_id ?? '');
            if ($webhookId === '') {
                $idLabel = $this->langs->transnoentities('DiagValueMissing');
            } else {
                // Tronqué : identifiant non secret, mais l'export circule par email.
                $idLabel = '…' . substr($webhookId, -8);
            }

            $this->addCheck(
                'webhooks',
                '#' . (int) $obj->rowid . ' ' . dol_escape_htmltag((string) $obj->topic),
                'fk_store=' . (int) $obj->fk_store
                    . ' · status=' . (int) $obj->status
                    . ' · webhook_id=' . $idLabel,
                'info'
            );
        }

        if ($rowsOmitted > 0) {
            $this->addCheck(
                'webhooks',
                $this->langs->transnoentities('DiagWebhookRowsTruncated'),
                (string) $rowsOmitted,
                'info'
            );
        }
    }

    private function checkLogFiles()
    {
        $this->results['logs'] = ['title' => $this->langs->transnoentities('DiagSectionLogsTitle'), 'checks' => []];

        $logPaths = [
            $this->conf->syslog->file ?? '',
            DOL_DATA_ROOT . '/dolibarr.log',
            DOL_DOCUMENT_ROOT . '/../documents/dolibarr.log',
            '/var/log/dolibarr.log'
        ];

        $logFound = false;
        foreach ($logPaths as $logFile) {
            if (!empty($logFile) && file_exists($logFile) && is_readable($logFile)) {
                $logFound = true;
                $size = filesize($logFile);
                $sizeMB = round($size / 1024 / 1024, 2);
                $this->addCheck('logs', $this->langs->transnoentities('DiagLogsFileLabel'), $logFile, 'info');
                $this->addCheck('logs', $this->langs->transnoentities('DiagLogsSizeLabel'), $sizeMB . ' MB', $sizeMB > 100 ? 'warning' : 'success');
                if ($sizeMB > 100) {
                    $this->addCheck('logs', $this->langs->transnoentities('DiagLogsRecommendationLabel'), $this->langs->transnoentities('DiagLogsArchiveRecommendationValue'), 'warning');
                }
                break;
            }
        }

        if (!$logFound) {
            $this->addCheck('logs', $this->langs->transnoentities('DiagLogsFileLabel'), $this->langs->transnoentities('DiagLogsNotFoundValue'), 'warning');
        }
    }

    /**
     * Emplacement d'exécution du module et détection d'une seconde copie (Story 2.4.7-5, AC4bis —
     * dossier Nicolas Graillon). Traitement identique à `admin/diagnostic.php::checkModuleLocation()`
     * (même fonction pure `doli2shopDetectSecondaryModuleCopy()` de lib/doli2shop.lib.php) : un
     * client qui exporte depuis *Santé* plutôt que depuis *Diagnostic* retombait exactement dans le
     * même piège (le bloc `build_id`/`cron_builds` de cette page est identique — mêmes quatre
     * chemins codés en dur, aucune section d'emplacement).
     *
     * AC1 : chemin d'exécution résolu via `realpath()`, toujours affiché en `info`.
     * AC2 : comparé à `DOL_DOCUMENT_ROOT/custom/doli2shop` + chaque racine alternative déclarée.
     * AC3 : copie unique (symlink compris, candidat absent compris) => aucune alerte.
     *
     * Revue 3 couches (CRITICAL) : même correctif que `admin/diagnostic.php::checkModuleLocation()`
     * — le chemin non résolu n'est plus jamais transmis à la détection (elle recevrait un chemin
     * BRUT face à des candidats TOUJOURS résolus, faussant la comparaison sur une installation par
     * lien symbolique). Statut `undetermined` => simple avertissement, jamais d'alerte de copie
     * secondaire.
     *
     * @since 2.4.7
     */
    /**
     * Signale les domaines de boutique déclarés sur PLUSIEURS entités (Multicompany).
     *
     * Story webhooks-multicompany-sans-resolution-d-entite, AC3 — volet écran. Le point d'entrée
     * des webhooks refuse tout appel portant un domaine ambigu : il ne peut pas choisir l'entité
     * sans risquer d'écrire les commandes dans la mauvaise société. Ce refus est correct, mais il
     * ne vivait qu'en journal applicatif — or Shopify finit par supprimer un abonnement dont les
     * appels échouent de façon répétée. Le client perdait alors ses webhooks sans jamais voir
     * pourquoi. La revue 3 couches a relevé cet AC comme non satisfait tant que la détection
     * n'était pas remontée ici.
     *
     * Silencieux hors Multicompany et lorsque aucune ambiguïté n'existe : la fonction sous-jacente
     * renvoie alors un tableau vide (@see doli2shopFindAmbiguousShopDomains()).
     *
     * @return void
     * @since 2.5.3
     */
    private function checkAmbiguousShopDomains()
    {
        if (!isModEnabled('multicompany')) {
            return;
        }

        $ambiguous = doli2shopFindAmbiguousShopDomains($this->db);
        if (empty($ambiguous)) {
            return;
        }

        if (!isset($this->results['webhooks'])) {
            $this->results['webhooks'] = [
                'title' => $this->langs->transnoentities('DiagWebhookRowsTitle'),
                'checks' => []
            ];
        }

        foreach ($ambiguous as $shopDomain => $entities) {
            $this->addCheck(
                'webhooks',
                $this->langs->transnoentities('DiagAmbiguousShopDomainTitle'),
                $this->langs->transnoentities(
                    'DiagAmbiguousShopDomainValue',
                    $shopDomain,
                    implode(', ', $entities)
                ),
                'error',
                $this->langs->transnoentities('DiagAmbiguousShopDomainNote')
            );
        }
    }

    private function checkModuleLocation()
    {
        $this->results['location'] = [
            'title' => $this->langs->transnoentities('DiagLocationTitle'),
            'checks' => []
        ];

        // Dossier du module = parent de admin/ — résolu via realpath() (AC1).
        $moduleRootDir = dirname(__DIR__);
        $resolvedExecutedDir = @realpath($moduleRootDir);
        // CRITICAL : chaîne vide (jamais le chemin brut) transmise à la détection quand realpath()
        // échoue — voir admin/diagnostic.php::checkModuleLocation() pour le détail du bug corrigé.
        $executedDir = ($resolvedExecutedDir === false || $resolvedExecutedDir === '') ? '' : $resolvedExecutedDir;
        $this->executedModuleDir = ($executedDir !== '') ? $executedDir : $moduleRootDir;

        // AC2 : DOL_DOCUMENT_ROOT/custom/doli2shop explicite + chaque racine déclarée dans
        // $conf->file->dol_document_root (clé 'main' == DOL_DOCUMENT_ROOT, clés altN). Construction
        // et dédoublonnage délégués à la fonction pure (MEDIUM — copié-collé non testé auparavant).
        $documentRootConfig = isset($this->conf->file->dol_document_root) ? $this->conf->file->dol_document_root : null;
        $candidatePaths = doli2shopBuildModuleLocationCandidates(DOL_DOCUMENT_ROOT, $documentRootConfig);

        $resolver = doli2shopBuildPathRealpathResolver();
        $detection = doli2shopDetectSecondaryModuleCopy($executedDir, $candidatePaths, $resolver);
        $this->secondaryModuleCopies = $detection['secondary_copies'];

        // CRITICAL : dossier exécuté non résolu => aucune conclusion possible.
        if ($detection['status'] === 'undetermined') {
            $this->addCheck(
                'location',
                $this->langs->transnoentities('DiagLocationExecutedPath'),
                $this->executedModuleDir,
                'warning',
                $this->langs->transnoentities('DiagLocationUnresolvedNote')
            );
            return;
        }

        $this->addCheck(
            'location',
            $this->langs->transnoentities('DiagLocationExecutedPath'),
            $executedDir,
            'info'
        );

        // AC3 : copie unique (avec ou sans symlink, candidat absent ou non) => aucune alerte.
        if ($detection['status'] === 'single_copy') {
            $this->addCheck(
                'location',
                $this->langs->transnoentities('DiagLocationTitle'),
                $this->langs->transnoentities('DiagLocationNoSecondaryCopy'),
                'success'
            );
        } else {
            // MEDIUM : liste bornée, même convention que checkFileIntegrity() (symétrie diagnostic.php).
            $maxListed = 40;
            $listedCopies = array_slice($detection['secondary_copies'], 0, $maxListed);
            $omittedCopies = count($detection['secondary_copies']) - count($listedCopies);

            foreach ($listedCopies as $secondaryPath) {
                $this->addCheck(
                    'location',
                    $this->langs->transnoentities('DiagLocationSecondaryCopyDetected'),
                    $secondaryPath,
                    'error',
                    $this->langs->transnoentities('DiagLocationSecondaryCopyNote')
                );
            }

            if ($omittedCopies > 0) {
                $this->addCheck('location', $this->langs->transnoentities('DiagLocationTruncated'), (string) $omittedCopies, 'info');
            }
        }

        // MEDIUM : candidat existant mais non vérifiable (permissions, open_basedir).
        if (!empty($detection['unverifiable_candidates'])) {
            $maxListed = 40;
            $listedUnverifiable = array_slice($detection['unverifiable_candidates'], 0, $maxListed);
            $omittedUnverifiable = count($detection['unverifiable_candidates']) - count($listedUnverifiable);

            foreach ($listedUnverifiable as $unverifiablePath) {
                $this->addCheck(
                    'location',
                    $this->langs->transnoentities('DiagLocationUnverifiableCandidate'),
                    $unverifiablePath,
                    'warning',
                    $this->langs->transnoentities('DiagLocationUnverifiableCandidateNote')
                );
            }

            if ($omittedUnverifiable > 0) {
                $this->addCheck('location', $this->langs->transnoentities('DiagLocationTruncated'), (string) $omittedUnverifiable, 'info');
            }
        }
    }

    /**
     * Vérification d'intégrité du paquet livré (Story 2.4.7-6, AC2 — dossier Nicolas Graillon).
     * Portage de `admin/diagnostic.php::checkFileIntegrity()` (Story 2.4.7-4), section construite
     * pour ce même dossier support mais livrée uniquement sur la page qui redirige vers *Santé*.
     *
     * Délègue déjà tout à `doli2shopVerifyPackageIntegrity()` + `doli2shopBuildManifestFileStateResolver()`
     * (lib/doli2shop.lib.php) : portage direct, aucune extraction supplémentaire nécessaire (AC3).
     *
     * @since 2.4.7
     */
    private function checkFileIntegrity()
    {
        $this->results['integrity'] = [
            'title' => $this->langs->transnoentities('DiagIntegrityTitle'),
            'checks' => []
        ];

        // Dossier du module = parent de admin/ (même convention que checkModuleLocation() ci-dessus).
        $moduleRootDir = dirname(__DIR__);
        $manifestPath = $moduleRootDir . '/build/manifest.md5';

        $manifestContent = null;
        if (is_file($manifestPath) && is_readable($manifestPath)) {
            $read = @file_get_contents($manifestPath);
            $manifestContent = ($read === false) ? null : $read;
        }

        $resolver = doli2shopBuildManifestFileStateResolver($moduleRootDir);
        $report = doli2shopVerifyPackageIntegrity($manifestContent, $resolver);

        if ($report['status'] === 'unverifiable') {
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityTitle'),
                $this->langs->transnoentities('DiagIntegrityUnverifiable'),
                'info',
                $this->langs->transnoentities('DiagIntegrityUnverifiableNote')
            );
            return;
        }

        $this->addCheck(
            'integrity',
            $this->langs->transnoentities('DiagIntegrityFilesChecked'),
            (string) $report['total'],
            'info'
        );
        $this->addCheck(
            'integrity',
            $this->langs->transnoentities('DiagIntegrityFilesConform'),
            (string) $report['conform'],
            'success'
        );

        // Même correctif que admin/diagnostic.php (revue 3 couches CRITICAL) : le verdict ne sort
        // pas tant que les lignes de manifeste invalides et les chemins rejetés n'ont pas été rendus.
        if ($report['status'] === 'conform') {
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityTitle'),
                $this->langs->transnoentities('DiagIntegrityConform'),
                'success'
            );
            return;
        }

        if ($report['status'] === 'partial') {
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityTitle'),
                $this->langs->transnoentities('DiagIntegrityPartial'),
                'warning',
                $this->langs->transnoentities('DiagIntegrityPartialNote')
            );
        }

        $maxListed = 40;

        if (!empty($report['missing'])) {
            $listed = array_slice($report['missing'], 0, $maxListed);
            $omitted = count($report['missing']) - count($listed);
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityMissingFiles'),
                implode(', ', $listed),
                'error',
                $this->langs->transnoentities('DiagIntegrityMissingNote')
            );
            if ($omitted > 0) {
                $this->addCheck('integrity', $this->langs->transnoentities('DiagIntegrityTruncated'), (string) $omitted, 'info');
            }
        }

        if (!empty($report['modified'])) {
            $listed = array_slice($report['modified'], 0, $maxListed);
            $omitted = count($report['modified']) - count($listed);
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityModifiedFiles'),
                implode(', ', $listed),
                'warning',
                $this->langs->transnoentities('DiagIntegrityModifiedNote')
            );
            if ($omitted > 0) {
                $this->addCheck('integrity', $this->langs->transnoentities('DiagIntegrityTruncated'), (string) $omitted, 'info');
            }
        }

        if (!empty($report['unreadable'])) {
            $listed = array_slice($report['unreadable'], 0, $maxListed);
            $omitted = count($report['unreadable']) - count($listed);
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityUnreadableFiles'),
                implode(', ', $listed),
                'warning',
                $this->langs->transnoentities('DiagIntegrityUnreadableNote')
            );
            if ($omitted > 0) {
                $this->addCheck('integrity', $this->langs->transnoentities('DiagIntegrityTruncated'), (string) $omitted, 'info');
            }
        }

        // AC4 (admin/diagnostic.php) : chemins hors du dossier du module / lignes malformées —
        // comptés seulement, jamais affichés en clair.
        if (!empty($report['rejected_paths'])) {
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityRejectedPaths'),
                (string) count($report['rejected_paths']),
                'warning',
                $this->langs->transnoentities('DiagIntegrityRejectedPathsNote')
            );
        }

        if (!empty($report['invalid_lines'])) {
            $this->addCheck(
                'integrity',
                $this->langs->transnoentities('DiagIntegrityInvalidLines'),
                (string) $report['invalid_lines'],
                'warning',
                $this->langs->transnoentities('DiagIntegrityInvalidLinesNote')
            );
        }
    }

    private function addCheck($category, $name, $value, $status, $note = '', $isHtml = false)
    {
        $this->results[$category]['checks'][] = [
            'name' => $name,
            'value' => $value,
            'status' => $status,
            'note' => $note,
            'isHtml' => $isHtml,
        ];

        if ($status === 'error') {
            $cleanName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $cleanValue = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            $this->errors[] = "$cleanName: $cleanValue";
        } elseif ($status === 'warning') {
            $cleanName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $cleanValue = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            $this->warnings[] = "$cleanName: $cleanValue";
        }
    }

    private function generateReport()
    {
        $totalChecks = array_sum(array_map(function ($section) {
            return isset($section['checks']) ? count($section['checks']) : 0;
        }, $this->results));

        return [
            'timestamp' => date('d/m/Y H:i:s'),
            'summary' => [
                'total_errors' => count($this->errors),
                'total_warnings' => count($this->warnings),
                'total_checks' => $totalChecks,
                'success_rate' => $totalChecks > 0 ? round(($totalChecks - count($this->errors) - count($this->warnings)) / $totalChecks * 100, 1) : 100,
                'errors' => $this->errors,
                'warnings' => $this->warnings
            ],
            'results' => $this->results
        ];
    }

    /**
     * Test des canaux de ventes Shopify (publications)
     *
     * @param  ShopifyApi   $shopifyApi Instance de l'API Shopify (globale ou ::forStore())
     * @param  string|null  $storeLabel Libellé de la boutique (multi-boutiques — Story 50-2). Null = legacy mono-boutique.
     * @return void
     */
    private function testChannels($shopifyApi, $storeLabel = null)
    {
        $suffix = ($storeLabel !== null && $storeLabel !== '') ? ' — ' . $storeLabel : '';
        try {
            $publications = $shopifyApi->getPublications();
            if (empty($publications)) {
                $this->addCheck('api', $this->langs->transnoentities('DiagApiPublicationsLabel') . $suffix, $this->langs->transnoentities('DiagApiNoPublicationsFoundValue'), 'warning',
                    $this->langs->trans('DiagApiPublicationsScopeHelp'));
                $this->addCheck('api', $this->langs->transnoentities('DiagApiTestDiagnosticLabel') . $suffix, $this->langs->transnoentities('DiagApiNoChannelFoundValue'), 'info');
            } else {
                $this->addCheck('api', $this->langs->transnoentities('DiagApiPublicationsAvailableLabel') . $suffix, $this->langs->transnoentities('DiagApiChannelsFoundCountValue', count($publications)), 'success');
                $channelNames = [];
                foreach ($publications as $pub) {
                    $name = html_entity_decode($pub['name'], ENT_QUOTES, 'UTF-8');
                    if (!empty($pub['app_title'])) {
                        $name .= ' (' . html_entity_decode($pub['app_title'], ENT_QUOTES, 'UTF-8') . ')';
                    }
                    $channelNames[] = $name;
                }
                if (count($channelNames) <= 5) {
                    $this->addCheck('api', $this->langs->transnoentities('DiagApiChannelsDetectedLabel') . $suffix, implode(', ', $channelNames), 'info');
                } else {
                    $this->addCheck('api', $this->langs->transnoentities('DiagApiChannelsDetectedLabel') . $suffix, implode(', ', array_slice($channelNames, 0, 5)) . $this->langs->transnoentities('DiagApiChannelsMoreSuffix', count($channelNames) - 5), 'info');
                }
                $this->addCheck('api', $this->langs->transnoentities('DiagApiPublicationsJsonLabel') . $suffix,
                    '<pre style="max-height:200px;overflow:auto;background:#f5f5f5;padding:10px;border:1px solid #ddd;">'
                    . htmlspecialchars(json_encode($publications, JSON_PRETTY_PRINT)) . '</pre>', 'info', '', true);
            }
        } catch (Exception $e) {
            $this->addCheck('api', $this->langs->transnoentities('DiagApiTestPublicationsLabel') . $suffix, $this->langs->transnoentities('DiagErrorWithMessage', $e->getMessage()), 'error');
        }
    }
}

// ── CONSTRUCTION DU RAPPORT ──────────────────────────────────────────────────

// Story 50-3 — les tests réseau Shopify (connexion, scopes, canaux) sont différés vers un
// chargement AJAX par boutique après le rendu de la page, pour éviter N appels API synchrones
// (timeout Guzzle 30s non réglable par appel) au chargement de la page. Deux cas restent
// pleinement synchrones (rétrocompat) : l'export JSON (?format=json, outil support) et le
// fallback JS désactivé (?sync_tests=1, lien affiché en <noscript>).
// FIX Story 50-17 : GETPOST(..., 'int') retourne une numeric-string (contrat documenté
// Dolibarr, cf. core/lib/functions.lib.php), jamais un int réel — la comparaison stricte
// === 1 échouait donc TOUJOURS ("1" !== 1), rendant le fallback ?sync_tests=1 (lien
// <noscript> pour JS désactivé) inopérant : la page restait indéfiniment en mode différé
// avec les squelettes "Testing..." jamais résolus. GETPOSTINT() effectue le cast (int) réel.
$syncTestsRequested = (GETPOSTINT('sync_tests') === 1);
$deferStoreTests = ($format !== 'json') && !$syncTestsRequested;

$diagnostic = new ShopifyDiagnosticHealth($db, $conf, $langs);
if ($deferStoreTests) {
    $diagnostic->setDeferApiTests(true);
}
$report = $diagnostic->runAllDiagnostics();

require_once dirname(__FILE__) . '/../core/modules/modDoli2Shop.class.php';
$modShopify = new modDoli2Shop($db);

$diagnosticBuildId = 'unknown';
if (file_exists(__FILE__)) {
    $diagnosticBuildId = substr(md5_file(__FILE__), 0, 8);
}

// Story 2.4.7-5 (AC4bis) : mêmes empreintes de CRONs calculées depuis le répertoire RÉELLEMENT
// EXÉCUTÉ (checkModuleLocation(), realpath()) — plus jamais depuis un chemin DOL_DOCUMENT_ROOT
// codé en dur. Symétrie avec admin/diagnostic.php.
$executedModuleDir = $diagnostic->getExecutedModuleDir();

$cronBuilds = [];
$cronFiles = [
    'importproductscron' => $executedModuleDir . '/class/importproductscron.class.php',
    'shopifyhistoricalimportcron' => $executedModuleDir . '/class/shopifyhistoricalimportcron.class.php',
    'shopifyhistoricalimportcleanupcron' => $executedModuleDir . '/class/shopifyhistoricalimportcleanupcron.class.php',
    'webhookprocesscron' => $executedModuleDir . '/class/webhookprocesscron.class.php'
];
foreach ($cronFiles as $cronName => $cronFile) {
    $cronBuilds[$cronName] = file_exists($cronFile) ? substr(md5_file($cronFile), 0, 8) : 'not_found';
}

$report = array_merge([
    'export_info' => [
        'version' => $modShopify->version,
        'build_id' => $diagnosticBuildId,
        'module_name' => 'Doli2Shop',
        'generated_by' => $user->login . ' (' . $user->email . ')',
        'dolibarr_version' => DOL_VERSION,
        'timestamp' => date('d/m/Y H:i:s'),
        'format_version' => '1.2',  // Story 2.4.7-5 : ajout executed_path et secondary_copies
        'executed_path' => $executedModuleDir,
        'secondary_copies' => $diagnostic->getSecondaryModuleCopies(),
        'cron_builds' => $cronBuilds
    ]
], $report);

// Initialisations des variables utilisées au rendu, hors bloc conditionnel (PHP 8 strict)
$shopifyConnectionTestsPerStore = [];

// Story 50-3 — instance StoreService partagée pour toute la page (réduit les instanciations
// StoreService::getAll() redondantes) : réutilisée ci-dessous ET par la section synthèse plus bas.
$storeServiceHealth = new StoreService($db);
$allStores = $storeServiceHealth->getAll(true); // boutiques actives seulement

if (isShopifyModuleEnabledHealth($conf)) {
    require_once dirname(__FILE__) . '/../class/shopifyapi.class.php';

    // ── Test connexion Shopify par boutique ──────────────────────────────────
    if (!$deferStoreTests) {
        // Mode synchrone : export JSON (?format=json) ou fallback ?sync_tests=1 (JS désactivé)
        if (empty($allStores)) {
            // Comportement legacy : install vierge ou ancienne version sans boutique seedée
            $migrator = new ConfigurationMigrator($db);
            $config = $migrator->getConfiguration($conf->entity);
            if (!empty($config['shopify_access_token']) && !empty($config['shopify_store_hostname'])) {
                try {
                    $shopifyApi = new ShopifyApi($db);
                    $legacyResult = $shopifyApi->testShopifyConnection();
                } catch (\Throwable $e) {
                    $legacyResult = ['connection' => false, 'errors' => [$e->getMessage()]];
                }
            } else {
                $legacyResult = ['connection' => false, 'errors' => [$langs->transnoentities('ShopifyConfigIncomplete')]];
            }
            $report['shopify_connection_test'] = $legacyResult;
            $shopifyConnectionTestsPerStore[] = [
                'store_label'   => $langs->transnoentities('DefaultStore'),
                'store_id'      => 0,
                'is_default'    => true,
                'result'        => $legacyResult,
            ];
        } else {
            // Multi-boutiques : tester chaque boutique active
            // FIX 6 — mitigation timeouts cumulés (N boutiques × 30 s) avant la boucle API
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            $defaultStoreResult = null;
            foreach ($allStores as $store) {
                if (empty($store->access_token) || empty($store->shop_domain)) {
                    // FIX 5 — boutique jamais configurée : pending (warning), pas error
                    $storeResult = [
                        'connection'      => false,
                        'pending'         => true,
                        'errors'          => [$langs->transnoentities('StoreNotConnectedYet')],
                        'scopes_analysis' => [],
                        'shop_info'       => null,
                    ];
                } else {
                    try {
                        $shopifyApiStore = ShopifyApi::forStore($db, $store);
                        $storeResult = $shopifyApiStore->testShopifyConnection();
                    } catch (\Throwable $e) {
                        $storeResult = ['connection' => false, 'errors' => [$e->getMessage()], 'scopes_analysis' => [], 'shop_info' => null];
                    }
                }
                $entry = [
                    'store_label' => $store->label,
                    'store_id'    => (int) $store->rowid,
                    'is_default'  => !empty($store->is_default),
                    'result'      => $storeResult,
                ];
                $shopifyConnectionTestsPerStore[] = $entry;
                if (!empty($store->is_default)) {
                    $defaultStoreResult = $storeResult;
                }
            }
            // FIX 4 — Rétrocompat export JSON : boutique par défaut dans la clé historique.
            // Si la boutique par défaut était inactive (absente de getAll(true)), la résoudre
            // via getDefault() sans filtre actif, et signaler l'inactivité plutôt que de
            // retomber sur la première boutique active (sémantique cassée).
            if ($defaultStoreResult === null) {
                $defaultStoreObj = $storeServiceHealth->getDefault();
                if ($defaultStoreObj !== null) {
                    // Boutique par défaut existe mais est inactive
                    $defaultStoreResult = [
                        'connection' => false,
                        'errors'     => [$langs->transnoentities('DefaultStoreDisabled')],
                    ];
                } else {
                    $defaultStoreResult = ['connection' => false];
                }
            }
            $report['shopify_connection_test'] = $defaultStoreResult;
        }

        $report['shopify_connection_tests_per_store'] = $shopifyConnectionTestsPerStore;
    } else {
        // Story 50-3 — Mode différé : AUCUN appel réseau Shopify ici. Le résultat réel
        // (connexion + scopes + canaux) est chargé après le rendu via une requête AJAX par
        // boutique (ajax/diagnostic_store_test.php, cf. js/diagnostic_store_test.js).
        // Le statut "pending" (identifiants manquants) est connu sans appel API, donc affiché
        // immédiatement — seul le test réseau réel est différé.
        if (empty($allStores)) {
            $legacyHostnameDefer = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '');
            $legacyHasCredentialsDefer = !empty($legacyHostnameDefer)
                && !empty(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN', ''))
                && !empty(getDolGlobalString('DOLI2SHOP_API_KEY', ''));
            $shopifyConnectionTestsPerStore[] = [
                'store_label' => $langs->transnoentities('DefaultStore'),
                'store_id'    => 0,
                'is_default'  => true,
                'result'      => [
                    'connection'      => null,
                    'deferred'        => true,
                    'pending'         => !$legacyHasCredentialsDefer,
                    'errors'          => [],
                    'scopes_analysis' => [],
                    'shop_info'       => null,
                ],
            ];
        } else {
            foreach ($allStores as $store) {
                $isPendingDefer = empty($store->access_token) || empty($store->shop_domain);
                $shopifyConnectionTestsPerStore[] = [
                    'store_label' => $store->label,
                    'store_id'    => (int) $store->rowid,
                    'is_default'  => !empty($store->is_default),
                    'result'      => [
                        'connection'      => null,
                        'deferred'        => true,
                        'pending'         => $isPendingDefer,
                        'errors'          => [],
                        'scopes_analysis' => [],
                        'shop_info'       => null,
                    ],
                ];
            }
        }
        // Pas d'écriture de $report['shopify_connection_test*'] en mode différé : l'export
        // JSON complet reste exclusivement porté par le mode synchrone (format=json l'impose).
    }
}

$rightsChecker = new ShopifyRightsChecker($db, $user);
$rightsStatus = $rightsChecker->checkAllRights();

$rightsSummary = ['total_rights' => 0, 'active_rights' => 0, 'missing_rights' => 0, 'critical_missing' => 0];
foreach ($rightsStatus as $module => $actions) {
    foreach ($actions as $actionKey => $status) {
        $rightsSummary['total_rights']++;
        if ($status['current']) {
            $rightsSummary['active_rights']++;
        } else {
            $rightsSummary['missing_rights']++;
            if ($status['critical']) {
                $rightsSummary['critical_missing']++;
            }
        }
    }
}

$report['user_rights_check'] = [
    'user_info' => ['login' => $user->login, 'email' => $user->email, 'lastname' => $user->lastname, 'firstname' => $user->firstname],
    'rights_summary' => $rightsSummary,
    'rights_details' => $rightsStatus
];

if (isShopifyModuleEnabledHealth($conf)) {
    $migrator = new ConfigurationMigrator($db);
    $fullConfig = $migrator->getConfiguration($conf->entity);
    if (isset($fullConfig['historical_import_completed_date']) &&
        is_numeric($fullConfig['historical_import_completed_date']) &&
        $fullConfig['historical_import_completed_date'] > 0) {
        $timestamp = $fullConfig['historical_import_completed_date'];
        $fullConfig['historical_import_completed_date'] = dol_print_date($timestamp, 'dayhour') . ' (timestamp: ' . $timestamp . ')';
    }
    // Story fix-export-diagnostic-secrets (CRITICAL) : plus de masquage ici, sur une liste figée de
    // clés — doli2shopRedactSensitiveConfig() masque TOUT le rapport (donc aussi cette section) par
    // motif de nom de clé, juste avant l'export JSON ci-dessous.
    $report['configuration'] = $fullConfig;
}

$report['licence_mode'] = $diagnostic->getLicenceMode();

// ── EXPORT JSON ──────────────────────────────────────────────────────────────
if ($format === 'json') {
    // Re-review 3 couches (MEDIUM) : le masquage et l'export sont désormais entourés d'un
    // try/catch(\Throwable) — si le masquage échoue pour une raison quelconque, l'export renvoie
    // une erreur JSON générique, SANS jamais sortir le rapport non masqué, et journalise l'incident.
    try {
        // Story fix-export-diagnostic-secrets (CRITICAL) : masquage de TOUT le rapport (pas
        // seulement $report['configuration']) juste avant l'export — couvre aussi le numéro de
        // série de licence porté par les checks 'support' (addCheck()), où qu'il apparaisse dans
        // l'arborescence.
        // Story export-diagnostic-detection-hex-elargie (constat 3, LOW) : masquage + encodage
        // factorisés dans doli2shopBuildRedactedJsonExport() (JSON_THROW_ON_ERROR inclus, lève
        // désormais une \JsonException interceptée ci-dessous plutôt que de renvoyer `false` en
        // silence sur une chaîne UTF-8 invalide).
        $jsonExport = doli2shopBuildRedactedJsonExport(cleanHtmlEntitiesForJsonHealth($report));
        $filename = 'diagnostic_shopify_' . date('Y-m-d_H-i-s') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        echo $jsonExport;
    } catch (\Throwable $e) {
        dol_syslog(
            'health.php: échec du masquage/export du diagnostic JSON — export refusé pour ne'
            . ' jamais risquer de sortir le rapport non masqué : ' . $e->getMessage(),
            LOG_ERR
        );
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        // Story export-diagnostic-detection-hex-elargie (constat 3, LOW) : http_response_code()
        // n'émet aucun avertissement après envoi des en-têtes (contrairement à header()), impact
        // réel négligeable — gardé sous headers_sent() par cohérence avec le header() ci-dessus,
        // défensif plutôt que correctif d'un défaut observable.
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode(array('error' => 'export_failed'));
    }
    exit;
}

// ── RENDU HTML ───────────────────────────────────────────────────────────────
$page_name = "HealthTab";
llxHeader('', $langs->trans($page_name));

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

print '<div class="doli2shop-page">';
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'health', $langs->trans("ShopifyIntegration"), -1, 'doli2shop@doli2shop');
// Pas de barre de contexte : la synthèse itère TOUTES les boutiques (le sélecteur n'aurait aucun effet).

// ── SECTION SYNTHÈSE PAR BOUTIQUE ───────────────────────────────────────────
print '<h3 style="margin:16px 0 8px;">' . dol_escape_htmltag($langs->trans("HealthSectionStores")) . '</h3>';

// Story 50-3 — réutilise $allStores (déjà chargé plus haut via $storeServiceHealth) au lieu
// d'une nouvelle instanciation StoreService::getAll() redondante.
$stores = $allStores;

if (empty($stores)) {
    // Mono-boutique legacy : créer un bandeau synthétique depuis la config globale
    $legacyHostname = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '');
    $legacyHasCredentials = !empty($legacyHostname)
        && !empty(getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN', ''))
        && !empty(getDolGlobalString('DOLI2SHOP_API_KEY', ''));

    // Vérifier webhooks pour la boutique legacy via la table (fk_store IS NULL ou 0)
    $sqlWLegacy = "SELECT COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS active_count,"
        . " COUNT(*) AS total_count"
        . " FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks"
        . " WHERE (fk_store IS NULL OR fk_store = 0)"
        . " AND entity = " . (int)$conf->entity;
    $resultWLegacy = $db->query($sqlWLegacy);
    $whLegacy = ['active_count' => 0, 'total_count' => 0];
    if ($resultWLegacy) {
        $objWLegacy = $db->fetch_object($resultWLegacy);
        $whLegacy['active_count'] = (int)$objWLegacy->active_count;
        $whLegacy['total_count'] = (int)$objWLegacy->total_count;
    }

    // Couleur locale mono-boutique
    if (!$legacyHasCredentials) {
        $storeColor = '#dc3545';
        $storeEmoji = '&#x26D4;';
    } elseif ($whLegacy['total_count'] > 0 && $whLegacy['active_count'] === 0) {
        $storeColor = '#dc3545';
        $storeEmoji = '&#x26D4;';
    } else {
        $storeColor = '#28a745';
        $storeEmoji = '&#x2705;';
    }

    print '<div style="border:1px solid ' . $storeColor . ';border-radius:6px;padding:12px 18px;margin-bottom:10px;background:' . ($storeColor === '#dc3545' ? '#fff5f5' : '#f0fff4') . ';display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
    print '<span style="font-size:20px;">' . $storeEmoji . '</span>';
    print '<strong>' . dol_escape_htmltag($legacyHostname ?: $langs->trans("HealthStoreCredentialsMissing")) . '</strong>';
    if ($whLegacy['total_count'] > 0) {
        print ' &mdash; ' . dol_escape_htmltag($langs->trans("HealthStoreWebhooks")) . ' : ' . (int)$whLegacy['active_count'] . '/' . (int)$whLegacy['total_count'];
    }
    print '</div>';
} else {
    foreach ($stores as $store) {
        $hasCredentials = !empty($store->access_token) && !empty($store->api_key);

        // Requête webhooks par boutique
        $sqlW = "SELECT COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) AS active_count,"
            . " COUNT(*) AS total_count"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks"
            . " WHERE fk_store = " . (int)$store->rowid
            . " AND entity = " . (int)$conf->entity;
        $resultW = $db->query($sqlW);
        $whCounts = ['active_count' => 0, 'total_count' => 0];
        if ($resultW) {
            $objW = $db->fetch_object($resultW);
            $whCounts['active_count'] = (int)$objW->active_count;
            $whCounts['total_count'] = (int)$objW->total_count;
        }

        $licenseStatus = isset($store->license_status) ? $store->license_status : '';

        // Story 49-12 T5.1 : exemption boutique par défaut avec statut unknown/''.
        // La boutique par défaut (is_default=1) est exemptée de licence (invariant Epic 47) :
        // si son license_status est 'unknown' ou '', elle est saine — affichée en vert.
        // Si son statut est 'valid' ou 'invalid', afficher le statut réel.
        // (CORRECTION VALIDATE P4 : NE PAS toucher $isRed — une boutique par défaut 'invalid' reste rouge)
        $isDefaultStore = ((int) $store->is_default === 1);
        $licenseIsExempt = ($isDefaultStore && in_array($licenseStatus, ['unknown', ''], true));

        // Logique couleur locale
        $isRed = !$hasCredentials
            || ($whCounts['total_count'] > 0 && $whCounts['active_count'] === 0)
            || $licenseStatus === 'invalid';
        // Story 49-12 T5.2 : exclure les boutiques exemptées de isOrange
        $isOrange = !$isRed && !$licenseIsExempt && (
            in_array($licenseStatus, ['unknown', 'community', ''])
            || ($whCounts['total_count'] > 0 && $whCounts['active_count'] < $whCounts['total_count'])
        );

        // Story 49-12 T5.4 : boutique exemptée → vert, SAUF si $isRed (credentials manquants
        // ou webhooks tous down ou licence invalid) — FIX 2 (CR 49-12 HIGH) :
        // $isRed reprend la main sur l'exemption pour éviter qu'une boutique par défaut
        // sans credentials s'affiche en vert.
        if ($licenseIsExempt && !$isRed) {
            $storeColor = '#28a745';
            $storeBg = '#f0fff4';
            $storeEmoji = '&#x2705;';
        } elseif ($isRed) {
            $storeColor = '#dc3545';
            $storeBg = '#fff5f5';
            $storeEmoji = '&#x26D4;';
        } elseif ($isOrange) {
            $storeColor = '#f0ad4e';
            $storeBg = '#fffdf0';
            $storeEmoji = '&#x26A0;&#xFE0F;';
        } else {
            $storeColor = '#28a745';
            $storeBg = '#f0fff4';
            $storeEmoji = '&#x2705;';
        }

        // Story 49-12 T5.3 : traduction statut licence — cas exemption avant le switch
        // Traduction statut licence (clés existantes Story 49-8 + StoreLicenseStatusCommunity 49-6)
        if ($licenseIsExempt) {
            // Boutique par défaut + unknown → exemptée, affichée en vert info
            $licLabel = '&#x2139;&#xFE0F; ' . dol_escape_htmltag($langs->transnoentities("StoreLicenseStatusDefaultExempt"));
        } else {
            switch ($licenseStatus) {
                case 'valid':
                case 'active':
                    $licLabel = '&#x2705; ' . dol_escape_htmltag($langs->transnoentities("StoreLicenseStatusValid"));
                    break;
                case 'invalid':
                    $licLabel = '&#x26D4; ' . dol_escape_htmltag($langs->transnoentities("StoreLicenseStatusInvalid"));
                    break;
                case 'community':
                    $licLabel = '&#x2139;&#xFE0F; ' . dol_escape_htmltag($langs->transnoentities("StoreLicenseStatusCommunity"));
                    break;
                default:
                    $licLabel = '&#x26A0;&#xFE0F; ' . dol_escape_htmltag($langs->transnoentities("StoreLicenseStatusUnknown"));
                    break;
            }
        }

        print '<div style="border:1px solid ' . $storeColor . ';border-radius:6px;padding:12px 18px;margin-bottom:10px;background:' . $storeBg . ';display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
        print '<span style="font-size:20px;">' . $storeEmoji . '</span>';
        print '<strong>' . dol_escape_htmltag($store->label) . '</strong>';
        if (!empty($store->shop_domain)) {
            print ' <span class="opacitymedium">(' . dol_escape_htmltag($store->shop_domain) . ')</span>';
        }
        print ' &mdash; ' . dol_escape_htmltag($langs->trans("HealthStoreLicenseStatus")) . ' : ' . $licLabel;
        if ($whCounts['total_count'] > 0) {
            print ' &mdash; ' . dol_escape_htmltag($langs->trans("HealthStoreWebhooks")) . ' : ' . $whCounts['active_count'] . '/' . $whCounts['total_count'];
        }
        if (!$hasCredentials) {
            print ' <span style="color:#dc3545;font-weight:600;"> &mdash; &#x26A0; ' . dol_escape_htmltag($langs->trans("HealthStoreCredentialsMissing")) . '</span>';
        }
        print '</div>';
    }
}

// ── OUTILS SUPPORT : export JSON commande/produit ───────────────────────────
print '<div class="center" style="margin-bottom:10px;">';
print '<a class="button" href="' . dol_buildpath('/doli2shop/admin/order_json_export.php', 1) . '">'
    . '&#128227; ' . $langs->trans("OrderJsonExportTitle") . '</a>';
print ' <a class="button" href="' . dol_buildpath('/doli2shop/admin/product_json_export.php', 1) . '">'
    . '&#128230; ' . $langs->trans("ProductJsonExportTitle") . '</a>';
print ' <a class="button" href="' . dol_buildpath('/doli2shop/admin/orders_json_export_zip.php', 1) . '">'
    . '&#128230; ' . $langs->trans("OrdersZipExportTitle") . '</a>';
print '</div>';

// ── SECTION DÉTAIL DIAGNOSTIC ────────────────────────────────────────────────

?>

<div class="fichecenter">
    <!-- En-tête diagnostic -->
    <div class="info">
        <h3><i class="fa fa-stethoscope"></i> <?php echo $langs->trans("Diagnostic"); ?></h3>
        <p><?php echo $langs->trans("DiagnosticDescription"); ?></p>

        <!-- Export JSON disponible en haut -->
        <div style="text-align: right; margin: 10px 0;">
            <a class="button" href="<?php echo dol_buildpath('/doli2shop/admin/health.php', 1); ?>?format=json">
                <i class="fa fa-download"></i> <?php echo $langs->trans("ExportDiagnosticJSON"); ?>
            </a>
        </div>
    </div>

    <!-- Test connexion Shopify par boutique -->
    <?php
    // ── Fonction locale : rendu tableau des scopes ───────────────────────────
    // FIX 7 — guard de redéclaration (inclusions multiples PHP, tests)
    if (!function_exists('renderShopifyScopesTable')) {
    function renderShopifyScopesTable($scopesAnalysis, $langs)
    {
        $categories = [];
        foreach ($scopesAnalysis as $scope) {
            $categories[$scope['category']][] = $scope;
        }
        echo '<br><strong>' . $langs->transnoentities('ShopifyScopeStatus') . ':</strong>';
        echo '<table class="noborder centpercent" style="margin-top:10px;">';
        echo '<tr class="liste_titre">';
        echo '<td>' . $langs->transnoentities('Permission') . '</td>';
        echo '<td class="center">' . $langs->transnoentities('RequiredScope') . '</td>';
        echo '<td class="center">' . $langs->transnoentities('Status') . '</td>';
        echo '</tr>';
        foreach ($categories as $categoryName => $scopes) {
            echo '<tr class="liste_titre"><td colspan="3" style="font-weight:bold;background:#f0f0f0;">';
            echo $langs->transnoentities('Scope' . ucfirst($categoryName));
            echo '</td></tr>';
            foreach ($scopes as $scope) {
                echo '<tr><td>' . $langs->transnoentities($scope['description']) . '</td>';
                echo '<td class="center">';
                if ($scope['present']) {
                    echo '<span class="success">&#x2713; ' . $langs->transnoentities('ScopeGranted') . '</span>';
                } else {
                    $cls = $scope['required'] ? 'error' : 'warning';
                    echo '<span class="' . $cls . '">';
                    echo $scope['required'] ? '&#x2717; ' . $langs->transnoentities('ScopeMissing') : '! ' . $langs->transnoentities('ScopeNotGrantedOptional');
                    echo '</span>';
                }
                echo '</td><td class="center">';
                $cls2 = $scope['present'] ? 'success' : ($scope['required'] ? 'error' : 'warning');
                echo '<span class="' . $cls2 . '">';
                echo $scope['required'] ? $langs->transnoentities('RequiredScope') : $langs->transnoentities('OptionalScope');
                echo '</span></td></tr>';
            }
        }
        echo '</table>';
    }
    } // end if (!function_exists('renderShopifyScopesTable'))

    // ── Calcul de la classe globale (agrège toutes les boutiques) ────────────
    // FIX 5 — boutique pending (sans token) = warning, pas error globale
    // Story 50-3 — les entrées "deferred" (résultat pas encore connu, chargé en AJAX) sont
    // ignorées du calcul agrégé initial (sauf pending, connu sans appel API) : le JS met à
    // jour les data-* du bloc et relance la classification une fois les tests AJAX terminés.
    $globalRequiredMissing  = 0;
    $globalOptionalMissing  = 0;
    $globalConnectionError  = false;
    $globalPendingStores    = 0;
    $globalTotalScopes      = 0;
    $anyDeferredPending     = false;

    foreach ($shopifyConnectionTestsPerStore as $storeEntry) {
        $storeResult = $storeEntry['result'];
        if (!empty($storeResult['deferred'])) {
            if (!empty($storeResult['pending'])) {
                $globalPendingStores++;
            } else {
                $anyDeferredPending = true;
            }
            continue;
        }
        if (empty($storeResult['connection'])) {
            if (!empty($storeResult['pending'])) {
                // Jamais configurée : compte comme warning, pas erreur
                $globalPendingStores++;
            } else {
                $globalConnectionError = true;
            }
        }
        if (!empty($storeResult['scopes_analysis'])) {
            foreach ($storeResult['scopes_analysis'] as $scope) {
                $globalTotalScopes++;
                if (!$scope['present']) {
                    if ($scope['required']) {
                        $globalRequiredMissing++;
                    } else {
                        $globalOptionalMissing++;
                    }
                }
            }
        }
    }

    if ($globalConnectionError || $globalRequiredMissing > 0) {
        $shopifyConnectionClass = 'has-error';
    } elseif ($globalOptionalMissing > 0 || $globalPendingStores > 0) {
        $shopifyConnectionClass = 'has-warning';
    } else {
        $shopifyConnectionClass = 'has-success';
    }
    ?>
    <?php if (!empty($shopifyConnectionTestsPerStore)): ?>
    <!-- Epic 59 (HIGH) : sur la toute première requête d'une session PHP fraîche (favori,
         reconnexion par cookie), $_SESSION['newtoken'] n'existe pas encore côté core au moment du
         rendu, donc $_SESSION['token'] non plus (main.inc.php:333-349 : la rotation
         "$_SESSION['token'] = $_SESSION['newtoken']" ne s'exécute que si 'newtoken' est déjà
         défini) : currentToken() renvoie '' pour data-doli2shop-token ci-dessous. Un F5 répare
         (la requête suivante peuple $_SESSION['token']), mais SANS lui l'appel AJAX serait de
         toute façon rejeté (verifToken() refuse systématiquement un jeton vide) : on ne peut pas
         "réparer" $_SESSION côté serveur sans le bidouiller à la main (hors périmètre, cf. note
         Validate). js/diagnostic_store_test.js détecte ce cas via data-doli2shop-token-missing-label
         et affiche ce message au lieu de laisser les squelettes "Testing..." figés en silence. -->
    <div class="diagnostic-section <?php echo $shopifyConnectionClass; ?>" id="shopify-connection"
         data-connection-success="<?php echo ($globalConnectionError ? 'false' : 'true'); ?>"
         data-total-scopes="<?php echo (int) $globalTotalScopes; ?>"
         data-required-scopes-missing="<?php echo (int) $globalRequiredMissing; ?>"
         data-optional-scopes-missing="<?php echo (int) $globalOptionalMissing; ?>"
         data-has-connection-error="<?php echo ($globalConnectionError ? 'true' : 'false'); ?>"
         data-doli2shop-deferred="<?php echo $anyDeferredPending ? 'true' : 'false'; ?>"
         data-doli2shop-ajax-url="<?php echo dol_escape_htmltag(dol_buildpath('/doli2shop/ajax/diagnostic_store_test.php', 1)); ?>"
         data-doli2shop-token="<?php echo dol_escape_htmltag(currentToken()); ?>"
         data-doli2shop-token-missing-label="<?php echo dol_escape_htmltag($langs->transnoentities('ErrorInvalidCSRFTokenRetry')); ?>">
        <h4 onclick="toggleSection('shopify-connection')" style="cursor: pointer;">
            <i class="fa fa-plug"></i> <?php echo $langs->transnoentities('ShopifyConnectionTest'); ?>
            <span class="section-toggle" id="toggle-shopify-connection">▼</span>
        </h4>
        <div class="section-content" id="content-shopify-connection">
        <?php if ($anyDeferredPending): ?>
            <noscript>
                <div class="warning">
                    <?php echo $langs->transnoentities('DiagnosticJsDisabledMessage'); ?>
                    <a class="button" href="?sync_tests=1"><?php echo $langs->transnoentities('DiagnosticRunSyncTest'); ?></a>
                </div>
            </noscript>
        <?php endif; ?>
        <?php $storeCount = count($shopifyConnectionTestsPerStore); $storeIdx = 0; ?>
        <?php foreach ($shopifyConnectionTestsPerStore as $storeEntry): ?>
            <?php
            $storeResult    = $storeEntry['result'];
            $storeLabel     = dol_escape_htmltag($storeEntry['store_label']);
            $isDefault      = !empty($storeEntry['is_default']);
            $storeIdRaw     = (int) $storeEntry['store_id'];
            $isDeferredItem = !empty($storeResult['deferred']) && empty($storeResult['pending']);
            $storeIdx++;
            ?>
            <div style="margin-bottom:18px;">
                <h5 style="margin:0 0 6px;">
                    <i class="fa fa-store"></i>
                    <?php echo $langs->transnoentities('DiagnosticStoreConnectionFor', $storeLabel); ?>
                    <?php if ($isDefault): ?>
                        <span style="background:#029e9c;color:#fff;border-radius:3px;padding:1px 7px;font-size:0.8em;vertical-align:middle;">
                            <?php echo $langs->transnoentities('StoreDefault'); ?>
                        </span>
                    <?php endif; ?>
                </h5>
                <?php if ($isDeferredItem): ?>
                    <!-- Story 50-3 — squelette rempli en AJAX par js/diagnostic_store_test.js -->
                    <div class="doli2shop-store-test-result" id="doli2shop-store-test-<?php echo $storeIdRaw; ?>"
                         data-doli2shop-store-test="1" data-store-id="<?php echo $storeIdRaw; ?>"
                         data-error-label="<?php echo dol_escape_htmltag($langs->transnoentities('DiagnosticTestAjaxError')); ?>">
                        <span aria-hidden="true">&#9203;</span> <?php echo $langs->transnoentities('DiagnosticTestLoading'); ?>
                    </div>
                <?php elseif (!empty($storeResult['connection'])): ?>
                    <div class="ok">
                        <strong><?php echo $langs->transnoentities('ShopifyConnectionSuccess'); ?></strong><br>
                        <?php if (!empty($storeResult['shop_info'])): ?>
                            <strong><?php echo $langs->transnoentities('ShopInfo'); ?>:</strong>
                            <?php echo htmlspecialchars((string) $storeResult['shop_info']->name, ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo htmlspecialchars((string) $storeResult['shop_info']->currencyCode, ENT_QUOTES, 'UTF-8'); ?>)<br>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($storeResult['scopes_analysis'])): ?>
                        <?php renderShopifyScopesTable($storeResult['scopes_analysis'], $langs); ?>
                    <?php endif; ?>
                <?php elseif (!empty($storeResult['pending'])): ?>
                    <!-- FIX 5 — boutique jamais configurée : warning, pas error -->
                    <div class="warning">
                        <?php echo $langs->transnoentities('StoreNotConnectedYet'); ?>
                    </div>
                <?php else: ?>
                    <div class="error">
                        <strong><?php echo $langs->transnoentities('ShopifyConnectionFailed'); ?></strong><br>
                        <?php if (!empty($storeResult['errors'])): ?>
                            <?php echo implode('<br>', array_map(static function ($e) { return htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8'); }, $storeResult['errors'])); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($storeIdx < $storeCount): ?>
                <hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Vérification des droits utilisateur -->
    <?php
    $totalRights = $report['user_rights_check']['rights_summary']['total_rights'];
    $activeRights = $report['user_rights_check']['rights_summary']['active_rights'];
    $missingRights = $report['user_rights_check']['rights_summary']['missing_rights'];
    $criticalMissing = $report['user_rights_check']['rights_summary']['critical_missing'];
    ?>
    <div class="diagnostic-section" id="user-rights"
         data-total-rights="<?php echo $totalRights; ?>"
         data-active-rights="<?php echo $activeRights; ?>"
         data-missing-rights="<?php echo $missingRights; ?>"
         data-critical-missing="<?php echo $criticalMissing; ?>">
        <h4 onclick="toggleSection('user-rights')" style="cursor: pointer;">
            <i class="fa fa-user-shield"></i> <?php echo $langs->trans("UserRightsCheck"); ?>
            <span class="section-toggle" id="toggle-user-rights">▼</span>
        </h4>
        <div class="section-content" id="content-user-rights">
            <?php echo $rightsChecker->renderRightsReport($rightsStatus); ?>
        </div>
    </div>

    <!-- Détails par catégorie -->
    <?php foreach ($report['results'] as $category => $section): ?>
        <div class="diagnostic-section" id="diagnostic-<?php echo htmlspecialchars($category); ?>">
            <h4 onclick="toggleSection('diagnostic-<?php echo htmlspecialchars($category); ?>')" style="cursor: pointer;">
                <i class="fa fa-list"></i> <?php echo htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8'); ?>
                <span class="section-toggle" id="toggle-diagnostic-<?php echo htmlspecialchars($category); ?>">▼</span>
            </h4>
            <div class="section-content" id="content-diagnostic-<?php echo htmlspecialchars($category); ?>">
                <div class="div-table-responsive-no-min">
                    <table class="noborder centpercent">
                        <tr class="liste_titre">
                            <td colspan="3"><?php echo htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr class="liste_titre">
                            <td><?php echo $langs->trans('DiagColumnCheck'); ?></td>
                            <td><?php echo $langs->trans('DiagColumnValue'); ?></td>
                            <td class="center"><?php echo $langs->trans('DiagColumnStatus'); ?></td>
                        </tr>
                        <?php foreach ($section['checks'] as $check): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $check['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php
                                    if (!empty($check['isHtml'])) {
                                        echo $check['value'];
                                    } else {
                                        echo htmlspecialchars((string) $check['value'], ENT_QUOTES, 'UTF-8');
                                    }
                                    // Hotfix 2.4.5 (review 3 couches, HIGH) : la note porte le message
                                    // ACTIONNABLE du check (ex. colonnes manquantes + « désactivez/réactivez
                                    // le module »). Elle était stockée par addCheck() mais jamais rendue en
                                    // HTML — seul l'export JSON la contenait, donc l'admin restait devant un
                                    // « Colonnes manquantes » sans savoir quoi faire. Les notes venant de
                                    // $langs->trans() sont déjà encodées en entités : on décode avant de
                                    // ré-échapper pour ne pas afficher « &#039; » à l'écran (même traitement
                                    // que addCheck() applique déjà à $this->errors[]).
                                    if (!empty($check['note'])) {
                                        $noteText = html_entity_decode((string) $check['note'], ENT_QUOTES, 'UTF-8');
                                        echo '<br><span class="opacitymedium">'
                                            . htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8')
                                            . '</span>';
                                    }
                                ?></td>
                                <td class="center">
                                    <?php
                                    $icon = '';
                                    switch ($check['status']) {
                                        case 'success':
                                            $icon = '<span class="success">&#x2713;</span>';
                                            break;
                                        case 'error':
                                            $icon = '<span class="error">&#x2717;</span>';
                                            break;
                                        case 'warning':
                                            $icon = '<span class="warning">!</span>';
                                            break;
                                        case 'info':
                                            $icon = '<span class="info">i</span>';
                                            break;
                                    }
                                    echo $icon;
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<?php
// ── ENCART SUPPORT ───────────────────────────────────────────────────────────
$supportEmail = 'doli2shop@ptitetete.com';
$mailtoSubject = rawurlencode('Support Doli2Shop - Demande d\'assistance');
$mailtoBody = rawurlencode(
    "Bonjour,\n\n"
    . "J'ai besoin d'aide concernant le module Doli2Shop.\n\n"
    . "=== DESCRIPTION DU PROBLÈME ===\n"
    . "[Décrivez votre problème ici...]\n\n"
    . "=== INFORMATIONS IMPORTANTES ===\n"
    . "Merci de joindre le fichier de diagnostic JSON téléchargé depuis Dolibarr.\n"
    . "Ce fichier nous permet d'analyser rapidement votre configuration.\n\n"
    . "Cordialement"
);
?>

<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:24px;max-width:800px;margin:24px auto 0;text-align:center;">
    <div style="font-size:48px;margin-bottom:12px;">&#128172;</div>
    <h3 style="margin:0 0 8px;"><?php echo $langs->trans("NeedHelp"); ?></h3>
    <p style="color:#666;margin:0 0 20px;"><?php echo $langs->trans("SupportPageDesc"); ?></p>

    <div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-bottom:20px;">
        <a href="<?php echo dol_buildpath('/doli2shop/admin/health.php', 1); ?>?format=json"
           style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;background:#029e9c;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">
            <span style="font-size:18px;">&#128229;</span> <?php echo $langs->trans("DownloadDiagnostic"); ?>
        </a>
        <a href="mailto:<?php echo $supportEmail; ?>?subject=<?php echo $mailtoSubject; ?>&body=<?php echo $mailtoBody; ?>"
           style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;background:#667eea;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">
            <span style="font-size:18px;">&#9993;</span> <?php echo $langs->trans("ContactSupport"); ?>
        </a>
    </div>

    <div style="background:#fff;border-radius:6px;padding:16px;text-align:left;">
        <h4 style="margin:0 0 10px;font-size:14px;"><?php echo $langs->trans("HowToGetSupport"); ?></h4>
        <ul style="margin:0;padding-left:20px;color:#555;">
            <li style="margin-bottom:6px;"><?php echo $langs->trans("SupportStep1"); ?></li>
            <li style="margin-bottom:6px;"><?php echo $langs->trans("SupportStep2"); ?></li>
            <li style="margin-bottom:6px;"><?php echo $langs->trans("SupportStep3"); ?></li>
        </ul>
        <p style="margin:14px 0 0;font-size:13px;">
            <?php echo $langs->trans("SupportEmail"); ?> :
            <span style="font-family:monospace;background:#e9ecef;padding:2px 6px;border-radius:4px;"><?php echo $supportEmail; ?></span>
        </p>
    </div>
</div>

<style>
.success { color: #28a745; font-weight: bold; }
.error { color: #dc3545; font-weight: bold; }
.warning { color: #ffc107; font-weight: bold; }

span.success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    padding: 2px 8px;
    border-radius: 3px;
    color: #155724;
    font-weight: bold;
}
span.error {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    padding: 2px 8px;
    border-radius: 3px;
    color: #721c24;
    font-weight: bold;
}
span.warning {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    padding: 2px 8px;
    border-radius: 3px;
    color: #856404;
    font-weight: bold;
}
.info { color: #17a2b8; font-weight: bold; }
.error, .warning { padding: 10px; border-radius: 4px; margin: 10px 0; }

.diagnostic-section {
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 10px 0;
    background: #fff;
}
.diagnostic-section h4 {
    background: #f8f9fa;
    margin: 0;
    padding: 12px 15px;
    border-bottom: 1px solid #ddd;
    position: relative;
    user-select: none;
}
.diagnostic-section h4:hover { background: #e9ecef; }
.section-toggle { float: right; font-size: 14px; transition: transform 0.3s ease; }
.section-content { padding: 15px; display: block; }
.section-content.collapsed { display: none; }
.section-toggle.collapsed { transform: rotate(-90deg); }
.diagnostic-section.has-error { background: #f8d7da; border-color: #f5c6cb; }
.diagnostic-section.has-error h4 { background: #f5c6cb; color: #721c24; border-bottom-color: #f1b0b7; }
.diagnostic-section.has-warning { background: #fff3cd; border-color: #ffeaa7; }
.diagnostic-section.has-warning h4 { background: #ffeaa7; color: #856404; border-bottom-color: #ffdf7e; }
.diagnostic-section.has-success { background: #d4edda; border-color: #c3e6cb; }
.diagnostic-section.has-success h4 { background: #c3e6cb; color: #155724; border-bottom-color: #b8dacc; }
.diagnostic-section.has-success .section-content { background: #f8fff9; }
.diagnostic-section.has-warning .section-content { background: #fefdf6; }
.diagnostic-section.has-error .section-content { background: #fefcfc; }
.error { background-color: #f8d7da; border: 1px solid #f5c6cb; }
.warning { background-color: #fff3cd; border: 1px solid #ffeaa7; }
</style>

<script>
function toggleSection(sectionId) {
    var content = document.getElementById('content-' + sectionId);
    var toggle = document.getElementById('toggle-' + sectionId);
    if (content.style.display === 'none' || content.classList.contains('collapsed')) {
        content.style.display = 'block';
        content.classList.remove('collapsed');
        toggle.classList.remove('collapsed');
        toggle.innerHTML = '▼';
    } else {
        content.style.display = 'none';
        content.classList.add('collapsed');
        toggle.classList.add('collapsed');
        toggle.innerHTML = '▶';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { autoCollapseSuccessSections(); }, 500);
});

function autoCollapseSuccessSections() {
    var sections = document.querySelectorAll('.diagnostic-section');
    sections.forEach(function(section) {
        var sectionId = section.id;
        var content = document.getElementById('content-' + sectionId);
        var toggle = document.getElementById('toggle-' + sectionId);
        if (!content || !toggle) return;

        section.classList.remove('has-error', 'has-warning', 'has-success');

        if (sectionId === 'shopify-connection') {
            // Story 50-3 — tant que les tests AJAX par boutique ne sont pas terminés, on laisse
            // la section neutre/dépliée (spinners visibles) plutôt que de la classer en
            // "succès" prématurément. js/diagnostic_store_test.js relance ce classement une
            // fois les résultats réels connus (data-doli2shop-deferred repassé à 'false').
            if (section.dataset.doli2shopDeferred === 'true') {
                expandSection(sectionId, content, toggle);
                return;
            }
            var connectionSuccess = section.dataset.connectionSuccess === 'true';
            var requiredScopesMissing = parseInt(section.dataset.requiredScopesMissing) || 0;
            var optionalScopesMissing = parseInt(section.dataset.optionalScopesMissing) || 0;
            var hasConnectionError = section.dataset.hasConnectionError === 'true';
            if (hasConnectionError || !connectionSuccess || requiredScopesMissing > 0) {
                section.classList.add('has-error');
                expandSection(sectionId, content, toggle);
            } else if (optionalScopesMissing > 0) {
                section.classList.add('has-warning');
                expandSection(sectionId, content, toggle);
            } else {
                section.classList.add('has-success');
                collapseSection(sectionId, content, toggle);
            }
        } else if (sectionId === 'user-rights') {
            var totalRightsD = parseInt(section.dataset.totalRights) || 0;
            var criticalMissingD = parseInt(section.dataset.criticalMissing) || 0;
            var missingRightsD = parseInt(section.dataset.missingRights) || 0;
            if (criticalMissingD > 0) {
                section.classList.add('has-error');
                expandSection(sectionId, content, toggle);
            } else if (missingRightsD > 0) {
                section.classList.add('has-warning');
                expandSection(sectionId, content, toggle);
            } else {
                section.classList.add('has-success');
                collapseSection(sectionId, content, toggle);
            }
        } else {
            var hasErrorDiv = section.querySelector('div.error, span.error');
            var hasSuccessElements = section.querySelector('.success, .ok');
            var hasWarningDiv = section.querySelector('div.warning, span.warning');
            if (hasErrorDiv) {
                section.classList.add('has-error');
                expandSection(sectionId, content, toggle);
            } else if (hasWarningDiv) {
                section.classList.add('has-warning');
                expandSection(sectionId, content, toggle);
            } else if (hasSuccessElements || content.innerHTML.trim() !== '') {
                section.classList.add('has-success');
                collapseSection(sectionId, content, toggle);
            } else {
                expandSection(sectionId, content, toggle);
            }
        }
    });
}

function collapseSection(sectionId, content, toggle) {
    content.style.display = 'none';
    content.classList.add('collapsed');
    toggle.classList.add('collapsed');
    toggle.innerHTML = '▶';
}

function expandSection(sectionId, content, toggle) {
    content.style.display = 'block';
    content.classList.remove('collapsed');
    toggle.classList.remove('collapsed');
    toggle.innerHTML = '▼';
}
</script>

<?php
// Story 50-3 — script AJAX de remplissage progressif des tests par boutique (no-op si la
// section est en mode synchrone : data-doli2shop-deferred="false" ou section absente).
print '<script src="' . dol_buildpath('/doli2shop/js/diagnostic_store_test.js', 1) . '"></script>';

print dol_get_fiche_end();
print '</div>'; // .doli2shop-page

llxFooter();
$db->close();
