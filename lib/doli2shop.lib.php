<?php
/**
 * @file        lib/doli2shop.lib.php
 * @brief       Library files with common functions for Doli2Shop
 *
 * @package     Doli2Shop
 * @subpackage  Lib
 * @category    lib
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       1.0.0
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Version centralisée — source unique
require_once dirname(__FILE__) . '/version.lib.php';


/**
 * Lit le cache de statut de licence d'un domaine, et refuse ce qu'il ne peut pas avoir ecrit.
 *
 * Retourne le tableau mis en cache, ou `null` si rien d'exploitable — l'appelant reinterroge
 * alors le serveur. Ne bloque JAMAIS de lui-meme : le pire cas est un appel reseau de plus.
 *
 * ⚠️ La borne haute sur l'expiration est le coeur de cette fonction. Ce code n'ecrit jamais une
 * expiration au-dela de `time() + DOLI2SHOP_LICENSE_CACHE_TTL` ; en rencontrer une signifie que
 * la constante vient d'ailleurs — typiquement d'un administrateur Dolibarr, qui edite les
 * constantes du module depuis l'ecran natif `const.php?visible=all`, sans SQL.
 *
 * C'est le moyen le moins cher de neutraliser le gate de licence, et il ne casse AUCUNE
 * signature : il suffit de mettre en cache une reponse credible dont le champ signe
 * `sync_stops_at` est simplement ABSENT, et de repousser l'expiration de dix ans. Le module ne
 * recoit alors jamais de date d'arret, le gate reste inactif, et le serveur n'est plus jamais
 * consulte. Signer une valeur ne protege pas de son omission ; borner sa fraicheur, si.
 * (Trouve en review le 13/09/2026, vecteur 1 du cadrage.)
 *
 * @param  string     $shopDomain Domaine de la boutique
 * @return array|null Statut mis en cache, ou null s'il faut reinterroger le serveur
 */
function doli2shopReadCachedLicenseStatus(string $shopDomain): ?array
{
    if ($shopDomain === '') {
        return null;
    }

    $domainHash = md5($shopDomain);
    $cached     = getDolGlobalString('DOLI2SHOP_LICENSE_STATUS_' . $domainHash);
    $expiry     = getDolGlobalInt('DOLI2SHOP_LICENSE_STATUS_EXPIRY_' . $domainHash, 0);

    if ($cached === '' || $expiry <= time()) {
        return null;
    }

    if ($expiry > time() + DOLI2SHOP_LICENSE_CACHE_TTL) {
        return null;
    }

    $decoded = json_decode($cached, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Duree de vie du cache de statut de licence, en secondes.
 *
 * Source UNIQUE : elle borne l'ecriture ET la lecture. C'est la lecture qui compte le plus —
 * une expiration trouvee au-dela de `time() + cette valeur` ne peut pas venir de nous, et
 * trahit une constante forgee (cf. le commentaire detaille au point de lecture).
 */
if (!defined('DOLI2SHOP_LICENSE_CACHE_TTL')) {
    define('DOLI2SHOP_LICENSE_CACHE_TTL', 300);
}

/**
 * Format a datetime as relative time ("il y a X min/h/j")
 *
 * @param string|null $datetime SQL datetime string or null
 * @return string Formatted relative time string
 * @since 2.2.0 Story 4.2 — Health dashboard widget
 */
// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}

function doli2shopFormatRelativeTime($datetime)
{
    global $langs;
    if (empty($datetime)) {
        return $langs->trans("HealthNeverSynced");
    }
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $langs->trans("HealthNeverSynced");
    }
    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return $langs->trans("HealthSyncJustNow");
    }
    if ($diff < 3600) {
        return $langs->trans("HealthSyncMinutesAgo", floor($diff / 60));
    }
    if ($diff < 86400) {
        return $langs->trans("HealthSyncHoursAgo", floor($diff / 3600));
    }
    return $langs->trans("HealthSyncDaysAgo", floor($diff / 86400));
}

/**
 * Résout et retourne la boutique active pour les pages d'administration Doli2Shop (Story 49-1).
 *
 * Priorité de résolution :
 *  1. GETPOST('store_id', 'int') — paramètre GET explicite
 *  2. $_SESSION['doli2shop_admin_store'] — contexte session persisté
 *  3. StoreService::getDefault() — boutique par défaut de l'entité
 *
 * Dans tous les chemins valides, le rowid résolu est persisté en session.
 * Retourne null uniquement si aucune boutique n'existe (install vierge).
 *
 * @param  DoliDB $db Connexion base de données
 * @return object|null Objet boutique active, ou null si aucune boutique configurée
 * @since  2.3.5
 */
function doli2shopGetCurrentAdminStore($db)
{
    // Cache par requête (review 49-1 H2) : la fonction est appelée plusieurs fois par page
    // (head + barre de contexte + pages). GETPOST/session sont stables sur la requête →
    // on évite de relancer getAll()/getDefault() à chaque appel.
    static $resolved = false;
    static $cachedStore = null;
    if ($resolved) {
        return $cachedStore;
    }

    require_once dirname(__FILE__) . '/../class/storeservice.class.php';

    $storeService = new StoreService($db);
    $allStores = $storeService->getAll();

    // Construire un map rowid → objet pour validation rapide
    $storeMap = array();
    foreach ($allStores as $s) {
        $storeMap[(int) $s->rowid] = $s;
    }

    $result = null;

    // Priorité 1 : GETPOST store_id (paramètre GET explicite, ex. lien « Configurer → »)
    $requestedId = GETPOST('store_id', 'int');
    if ($requestedId > 0 && isset($storeMap[$requestedId])) {
        $_SESSION['doli2shop_admin_store'] = $requestedId;
        $result = $storeMap[$requestedId];
    } else {
        if ($requestedId > 0) {
            // store_id demandé mais hors entité / inexistant → fallback (review 49-1 MEDIUM)
            dol_syslog("doli2shopGetCurrentAdminStore - store_id=" . $requestedId . " invalide ou hors entité, fallback", LOG_WARNING);
        }

        // Priorité 2 : session persistée lors d'une navigation précédente
        $sessionId = isset($_SESSION['doli2shop_admin_store']) ? (int) $_SESSION['doli2shop_admin_store'] : 0;
        if ($sessionId > 0 && isset($storeMap[$sessionId])) {
            $result = $storeMap[$sessionId];
        } else {
            // Priorité 3 : boutique par défaut de l'entité
            $default = $storeService->getDefault();
            if ($default !== null) {
                $_SESSION['doli2shop_admin_store'] = (int) $default->rowid;
                $result = $default;
            }
            // sinon : aucune boutique configurée (install vierge) → $result reste null
        }
    }

    $resolved = true;
    $cachedStore = $result;
    return $result;
}

/**
 * Retourne les clés per-store d'un onglet donné de l'interface d'administration (Story 49-10).
 *
 * Source unique des listes de clés — les badges héritage ET le handler reset_tab_overrides
 * utilisent cette fonction pour éviter toute divergence de listes.
 *
 * @param  string $tab Identifiant d'onglet : 'settings', 'orders' ou 'products'
 * @return array       Liste des noms de clés (casse originale — le handler normalise en strtoupper)
 * @since  2.3.10
 * @version 2.3.10
 */
function doli2shopGetPerStoreKeysByTab(string $tab): array
{
    switch ($tab) {
        case 'settings':
            return [
                'dolibarr_procate',
                'dolibarr_customer_category',
                'typent_with_company',
                'typent_without_company',
                'max_orders_per_sync',
                'products_per_cron_update',
            ];
        case 'orders':
            return [
                'order_prefix',
                'delivery_delay',
                'delivery_delay_type',
                'order_origin',
                'payment_terms',
                'default_delivery_days',
                'default_shipping_method_id',
                'shipping_product_id',
                'tip_product_id',
                'order_bank_account',
                'default_warehouse_id',
                'auto_create_invoice',
                'auto_validate_invoice',
                'auto_create_payment',
                'auto_create_expedition',
                'auto_close_order',
                'buying_price_source',
                'bundle_lines_behavior',
                'sync_non_paid_orders',
                'auto_classify_billed',
            ];
        case 'products':
            return [
                'sync_products_direction',
                'sync_products_conflict_resolution',
                'sync_product_prices',
                'sync_price_level',
                'price_priority_ttc',
                'sync_product_descriptions',
                'sync_product_images',
                'sync_product_stocks',
                'sync_product_attributes',
                'sync_products_only_active',
                'sync_product_collections',
                'include_parent_categories',
                'sync_collections_direction',
                'sync_collections_conflict_resolution',
                'collections_sales_channels',
                'vendor',
                'use_virtual_stock',
                'inventory_policy_continue_selling',
            ];
        default:
            return [];
    }
}

/**
 * Liste des paramètres de query string retirés de l'URL au moment où le sélecteur de boutique
 * (doli2shopRenderAdminTopBar()) fixe `store_id`.
 *
 * Deux familles, toutes deux devenues caduques dès que la boutique active change :
 * - **action ponctuelle déjà exécutée** (AC3 du hotfix 2.5.2-webhooks-formulaire-imbrique) :
 *   `action`, `token`, `save` — décrivent une action passée, pas un état de vue ;
 * - **contexte de boutique / position de liste**, trouvé en code review 3-couches du même
 *   hotfix (HIGH — régression fonctionnelle) :
 *   - `filter_shop_domain` / `filter_shop_domain_submitted` (`admin/webhook_events.php:76-79`)
 *     et `filter_store` (`admin/action_log.php:71-77`) : ces deux écrans n'auto-sélectionnent
 *     la boutique active QUE si le paramètre est absent. Sans purge, le badge de la barre
 *     change de boutique mais la LISTE reste filtrée sur l'ancienne, silencieusement — c'est
 *     précisément le mécanisme qui vient d'être cassé par la préservation intégrale de la query
 *     string (AC3) : l'ancien `<form method="get">` remplaçait toute la query string par ses
 *     seuls champs et purgeait ces paramètres à chaque changement de boutique.
 *   - `page` (`admin/webhook_events.php`, `admin/action_log.php`) / `events_page`
 *     (`admin/webhooks.php`) : un offset de pagination devenu hors bornes sur la nouvelle
 *     boutique affiche une table vide (pas d'erreur SQL, juste une liste qui semble vide).
 *   - `wizard_store_id` (`admin/setup_wizard.php:124-126`) : **le cas le plus grave des trois**,
 *     et celui que le balayage initial de la review avait manqué. Ce paramètre ne se contente pas
 *     de survivre : il **prime explicitement** sur la barre de contexte (« wizard_store_id
 *     explicite (GET ou POST) prend la priorité sur la session »), et `:138-140` **réécrit**
 *     `$currentAdminStore` **ET** `$_SESSION['doli2shop_admin_store']` sur la boutique qu'il
 *     désigne. Sans purge, changer de boutique via le sélecteur sur cet écran serait donc non
 *     seulement **sans effet**, mais repositionnerait en plus la session sur l'ancienne boutique.
 *     Or c'est l'écran où se saisissent les identifiants de connexion Shopify : configurer la
 *     mauvaise boutique n'y est pas un désagrément d'affichage.
 *
 * Les filtres qui NE dépendent PAS de la boutique (topic, statut, dates, `tab`, `step`...) ne
 * figurent PAS ici : les conserver est le progrès qu'apporte ce hotfix par rapport à l'ancien
 * `<form>` (qui purgeait tout), il ne faut pas le sacrifier.
 *
 * Cette liste alimente À LA FOIS le JS généré par doli2shopRenderAdminTopBar() (onchange du
 * sélecteur) ET test/unit/StoreSwitchDroppedParamsTest.php, qui vérifie le CONTRAT (quels
 * paramètres disparaissent, lesquels survivent) — PHP pur, testable sans navigateur.
 * ⚠️ Ce qui reste non couvert par ce dépôt (aucun runner JS) : l'exécution réelle de
 * `URLSearchParams` dans un navigateur. Le contrat PHP est vérifié ; son exécution côté client
 * ne l'est pas.
 *
 * @return array<int, string>
 * @since 2.5.2
 */
function doli2shopStoreSwitchDroppedParams()
{
    return array(
        // Action ponctuelle déjà exécutée — AC3
        'action',
        'token',
        'save',
        // Contexte boutique caduc — auto-sélection basée sur l'absence du paramètre
        'filter_shop_domain',
        'filter_shop_domain_submitted',
        'filter_store',
        // Position de liste caduque après changement de boutique
        'page',
        'events_page',
        // Contexte boutique PRIORITAIRE sur la barre (réécrit $currentAdminStore ET la session)
        'wizard_store_id',
    );
}

/**
 * Affiche (print) la barre de contexte boutique pour les pages d'administration (Story 49-10).
 *
 * Design amélioré : fond #f0fafa, bordure gauche teal, flexbox. Mono-boutique = display:none.
 * Utilise doli2shopGetCurrentAdminStore() en interne (cache statique — aucun SQL supplémentaire).
 *
 * Convention : PRINT directement (ne pas retourner) pour garantir un usage unique par page.
 * Ne rien faire si aucune boutique n'existe (install vierge).
 *
 * @param  DoliDB $db Connexion base de données
 * @return void
 * @since  2.3.10
 * @version     2.5.7
 */
function doli2shopRenderAdminTopBar($db)
{
    global $langs;

    $currentStore = doli2shopGetCurrentAdminStore($db);

    // Aucune boutique configurée : barre inutile
    if ($currentStore === null) {
        return;
    }

    require_once dirname(__FILE__) . '/../class/storeservice.class.php';
    $storeService = new StoreService($db);
    $storeCount = $storeService->countStores();

    $langs->load("doli2shop@doli2shop");

    // Mono-boutique : barre rendue mais masquée CSS
    $displayStyle = ($storeCount <= 1) ? 'display:none;' : '';

    // Toutes les boutiques pour le sélecteur admin
    $allStores = $storeService->getAll(false);

    // Badge selon type de boutique
    if (!empty($currentStore->is_default)) {
        $badgeHtml = ' <span style="background:#e0f7f6;color:#026B6A;border:1px solid #b2ebeb;border-radius:3px;padding:1px 7px;font-size:0.8em;font-weight:600;">'
            . dol_escape_htmltag($langs->trans('StoreContextBarDefault'))
            . '</span>';
    } else {
        $badgeHtml = ' <span style="background:#f0f0f0;color:#666;border:1px solid #ddd;border-radius:3px;padding:1px 7px;font-size:0.8em;">'
            . dol_escape_htmltag($langs->trans('StoreContextBarSecondary'))
            . '</span>';
    }

    // Domaine affiché uniquement si différent du label (évite duplication)
    $domainHtml = '';
    if (trim((string)$currentStore->label) !== trim((string)$currentStore->shop_domain)) {
        $domainHtml = ' <span class="opacitymedium" style="font-size:0.9em;">'
            . dol_escape_htmltag($currentStore->shop_domain)
            . '</span>';
    }

    // Sélecteur de boutiques — navigation directe par onchange, SANS <form> (Hotfix 2.5.2).
    //
    // Avant : un <form method="get"> entourait ce <select>. Sur les 8 appelants qui invoquent
    // doli2shopRenderAdminTopBar() hors de tout formulaire, c'était sans conséquence. Mais sur
    // admin/webhooks.php, l'appel a lieu À L'INTÉRIEUR du <form method="POST" action="...
    // ?action=update"> qui enregistre les topics (:428-642) : face à un <form> imbriqué, le
    // parseur HTML5 ignore la balise OUVRANTE (form element pointer déjà positionné) mais honore
    // la FERMANTE — le </form> de la barre boutique fermait donc le formulaire d'enregistrement,
    // laissant la table des topics et le bouton "Enregistrer" hors de tout formulaire (submit sans
    // form owner = inerte, POST jamais envoyé). Retirer le <form> interne supprime la CLASSE du
    // défaut pour les 9 appelants, présents et futurs — même motif que le sélecteur déjà en place
    // sur admin/setup_wizard.php:1390/:1439 (navigation par onchange, sans <form>).
    //
    // La query string de la VUE courante (tab, filter_status, filter_topic, step, subtab, selon
    // l'écran) est préservée nativement côté client via URLSearchParams sur
    // window.location.search : on ne reconstruit rien en PHP (PHP_SELF ne porte de toute façon
    // jamais la query string). Les paramètres retirés avant de fixer store_id — action ponctuelle
    // déjà exécutée ET contexte de boutique/position de liste devenu caduc — viennent d'une seule
    // source de vérité, doli2shopStoreSwitchDroppedParams(), testée en PHP (voir son docblock :
    // sans cette purge, deux écrans désynchronisent silencieusement le badge affiché et les
    // données filtrées — HIGH trouvé en code review du hotfix 2.5.2). .set() évite nativement
    // toute duplication de store_id.
    //
    // PHP_SELF reste réinjecté dans ce JS inline (pour l'URL de destination) : échappement JS
    // D'ABORD (apostrophes), HTML ensuite. dol_escape_htmltag() seul ne suffit pas — il s'appuie
    // sur htmlentities(ENT_COMPAT), qui n'encode PAS l'apostrophe, et le navigateur décode
    // l'attribut HTML AVANT de compiler son contenu comme JavaScript. Même motif que
    // admin/webhooks.php:693 et :701 pour cette raison précise.
    $currentPageJsSafe = dol_escape_htmltag(dol_escape_js($_SERVER['PHP_SELF'], 1));
    $storeSelectOnchange = 'var params=new URLSearchParams(window.location.search);';
    foreach (doli2shopStoreSwitchDroppedParams() as $droppedParam) {
        $storeSelectOnchange .= 'params.delete(\'' . $droppedParam . '\');';
    }
    $storeSelectOnchange .= 'params.set(\'store_id\',this.value);'
        . 'window.location.href=\'' . $currentPageJsSafe . '?\'+params.toString();';

    $selectHtml  = '<select name="store_id" onchange="' . $storeSelectOnchange . '" style="border:2px solid #029e9c;border-radius:5px;padding:7px 12px;background:#fff;cursor:pointer;font-size:1.05em;font-weight:600;color:#075e5d;min-width:220px;">';
    foreach ($allStores as $store) {
        $selected = ((int) $store->rowid === (int) $currentStore->rowid) ? ' selected' : '';
        $storeLabel = dol_escape_htmltag($store->label);
        if (empty($store->active)) {
            $storeLabel .= ' (' . dol_escape_htmltag($langs->trans('Inactive')) . ')';
        }
        $selectHtml .= '<option value="' . (int) $store->rowid . '"' . $selected . '>' . $storeLabel . '</option>';
    }
    $selectHtml .= '</select>';

    // Lien Gérer les boutiques
    $storesUrl = htmlspecialchars(dol_buildpath('/doli2shop/admin/stores.php', 1), ENT_QUOTES, 'UTF-8');

    print '<div class="doli2shop-store-context-bar" style="'
        . 'background:#f0fafa;'
        . 'border-left:4px solid #029e9c;'
        . 'border-bottom:1px solid #c8e8e8;'
        . 'padding:10px 16px;'
        . 'margin-bottom:12px;'
        . 'font-size:1.05em;'
        . 'display:flex;'
        . 'align-items:center;'
        . 'gap:10px;'
        . 'flex-wrap:wrap;'
        . $displayStyle
        . '">';
    print '&#x1F3EC; ';
    print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans('StoreContextActive')) . '</span>';
    print ' <strong>' . dol_escape_htmltag($currentStore->label) . '</strong>';
    print $domainHtml;
    print $badgeHtml;
    print $selectHtml;
    print ' <a href="' . $storesUrl . '">' . dol_escape_htmltag($langs->trans('ManageStores')) . '</a>';
    print '</div>';
}

/**
 * @deprecated 2.3.10 — Utiliser doli2shopRenderAdminTopBar($db) à la place.
 *
 * Alias déprécié de la barre de contexte boutique. Conservé pour éviter les crashes sur les pages
 * non encore migrées. Appelle doli2shopRenderAdminTopBar() si $db est disponible, sinon no-op.
 *
 * @param  DoliDB      $db           Connexion base de données
 * @param  object|null $currentStore Ignoré (la nouvelle fonction résout en interne)
 * @return string                    Chaîne vide (la nouvelle fonction print directement)
 * @since  2.3.5
 * @version 2.3.10
 */
function doli2shopAdminStoreContextBar($db, $currentStore)
{
    doli2shopRenderAdminTopBar($db);
    return '';
}

/**
 * Statut santé HealthChecker, mis en cache statique pour la durée de la requête (Story 37.2).
 *
 * Non bloquant : toute erreur (DB lente/inaccessible) retourne array() sans propager
 * d'exception — la navigation admin ne doit jamais casser à cause de ce check.
 *
 * @param  DoliDB $db
 * @return array  Statut santé (clés status/errors_24h/...) ou array() en cas d'échec
 * @since  2.3.0
 */
function doli2shopGetCachedHealthStatus($db)
{
    static $cached = null;
    if ($cached === null) {
        try {
            require_once dirname(__FILE__) . '/../class/healthchecker.class.php';
            $hc = new HealthChecker($db);
            $cached = $hc->getHealthStatus();
        } catch (\Throwable $e) {
            $cached = array();
        }
    }
    return $cached;
}

function doli2shopAdminPrepareHead()
{
    global $langs, $conf, $db;

    $langs->load("doli2shop@doli2shop");

    // Inclure ConfigurationMigrator pour la migration (v2.0.27)
    dol_include_once('/doli2shop/class/configurationMigrator.class.php');

    // Résoudre la boutique active et construire le suffixe store_id pour les onglets
    // par-boutique (Story 49-1). Si aucune boutique (install vierge), $storeParam = '' :
    // les URLs restent valides (comportement gracieux).
    $currentStore = doli2shopGetCurrentAdminStore($db);
    $currentStoreId = ($currentStore !== null && (int) $currentStore->rowid > 0)
        ? (int) $currentStore->rowid
        : 0;
    $storeParam = ($currentStoreId > 0) ? '&store_id=' . $currentStoreId : '';

    $h = 0;
    $head = array();

    // ── GROUPE COMMUN — toujours visibles, SANS store_id ──────────────────────

    // Wizard Tab — toujours visible, premier onglet (Story 6.1)
    $head[$h][0] = dol_buildpath("/doli2shop/admin/setup_wizard.php", 1);
    $head[$h][1] = $langs->trans("WizardTab");
    $head[$h][2] = 'wizard';
    $h++;

    // Stores Tab — point d'entrée multi-boutiques, placé JUSTE APRÈS le wizard (Story 48-1).
    // Toujours visible, sans store_id (la gestion des boutiques est globale).
    $head[$h][0] = dol_buildpath("/doli2shop/admin/stores.php", 1);
    $head[$h][1] = $langs->trans("StoresTab");
    $head[$h][2] = 'stores';
    $h++;

    // Général Tab — réglages communs (Clé API Dolibarr + URL hôte), sans store_id (Story 49-2)
    $head[$h][0] = dol_buildpath("/doli2shop/admin/general.php", 1);
    $head[$h][1] = $langs->trans("GeneralTab");
    $head[$h][2] = 'general';
    $h++;

    // ── GROUPE PAR-BOUTIQUE — conditionnels $configComplete + portent $storeParam ──

    // Paramètres — premier onglet par-boutique (toujours tenté, même avant $configComplete)
    $head[$h][0] = dol_buildpath("/doli2shop/admin/setup.php", 1) . '?tab=settings' . $storeParam;
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;

    // Vérifier si les paramètres de base sont configurés pour l'entité courante (v2.0.27)
    $migrator = new ConfigurationMigrator($db);
    $configArray = $migrator->getConfiguration($conf->entity);

    $configComplete = !empty($configArray) &&
                     !empty($configArray['shopify_store_hostname']) &&
                     !empty($configArray['shopify_access_token']) &&
                     !empty($configArray['shopify_api_key']) &&
                     !empty($configArray['shopify_api_secret_key']) &&
                     !empty($configArray['shopify_location_id']);

    // N'afficher les onglets supplémentaires que si la configuration est complète
    if ($configComplete) {
        // Configuration des commandes
        $head[$h][0] = dol_buildpath("/doli2shop/admin/setup.php", 1) . '?tab=orders' . $storeParam;
        $head[$h][1] = $langs->trans("OrderConfiguration");
        $head[$h][2] = 'orders';
        $h++;

        // Mappage des statuts de commande — écran existant, fonctionnalité VIVANTE (consommée en
        // production par le mappeur de statuts) mais jusqu'ici atteignable par aucun lien (story
        // dix-ecrans-atteignables-par-aucun-lien, AC2). Placé juste après "Configuration des
        // commandes" : même famille fonctionnelle. Séquencement imposé par le Validate du
        // 09/09/2026 : ce rebranchement n'a pu avancer qu'une fois le bouton "Restaurer les
        // valeurs par défaut" réparé (story restaurer-les-correspondances-de-statut-vide-les-tables).
        $head[$h][0] = dol_buildpath("/doli2shop/admin/orderstatusmapping.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("OrderStatusMapping");
        $head[$h][2] = 'orderstatusmapping';
        $h++;

        // Story 22.4: Shipping and Payment tabs removed — content merged into Orders tab

        // Directions de synchronisation (produits/commandes/paiements/expéditions/stocks) — écran
        // existant mais jusqu'ici atteignable par aucun lien (story
        // ecran-directions-de-synchronisation-mort-et-casse, AC2). Placé juste après
        // "Configuration des commandes" : porte notamment le sens du stock, dont le défaut
        // ('both') laisse Shopify écrire dans Dolibarr tant que personne ne l'a réglé ici.
        $head[$h][0] = dol_buildpath("/doli2shop/admin/syncoptions.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("SyncOptionsTab");
        $head[$h][2] = 'syncoptions';
        $h++;

        // Synchronisation des produits
        $head[$h][0] = dol_buildpath("/doli2shop/admin/setup.php", 1) . '?tab=products' . $storeParam;
        $head[$h][1] = $langs->trans("ProductSynchronization");
        $head[$h][2] = 'products';
        $h++;

        // Synchronisation manuelle produits bidirectionnelle
        $head[$h][0] = dol_buildpath("/doli2shop/admin/sync_products.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("ManualSync");
        $head[$h][2] = 'sync';
        $h++;

        // Mapping collections Shopify ↔ catégories Dolibarr — écran existant, jusqu'ici
        // atteignable par aucun lien (story dix-ecrans-atteignables-par-aucun-lien, AC1/AC3).
        // Placé avec les écrans produits : les collections sont le pendant Shopify des catégories
        // produit Dolibarr.
        $head[$h][0] = dol_buildpath("/doli2shop/admin/collections_mapping.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("CollectionsMappingManagement");
        $head[$h][2] = 'collectionsmapping';
        $h++;

        // Webhooks Tab (v2.1.8)
        $head[$h][0] = dol_buildpath("/doli2shop/admin/webhooks.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("Webhooks");
        $head[$h][2] = 'webhooks';
        $h++;

        // Événements webhooks (v2.2.0 - Story 3.3)
        $head[$h][0] = dol_buildpath("/doli2shop/admin/webhook_events.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("WebhookEventsTab");
        $head[$h][2] = 'events';
        $h++;

        // Journal d'actions (v2.3.0 - Story 38.3)
        $head[$h][0] = dol_buildpath("/doli2shop/admin/action_log.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("ActionLogTab");
        $head[$h][2] = 'actionlog';
        $h++;

        // Recorrection des remises importées (Hotfix 2.4.5, AC4)
        $head[$h][0] = dol_buildpath("/doli2shop/admin/discount_repair.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("DiscountRepairTab");
        $head[$h][2] = 'discountrepair';
        $h++;

        // Audit des commandes importées en prix hors taxes avant e825f456 (story
        // reprise-des-commandes-importees-en-prix-hors-taxes) — outil d'AUDIT, jamais de
        // correction (Validate du 12/09/2026). Placé juste après l'onglet de recorrection des
        // remises : même famille fonctionnelle (passif d'import à traiter/signaler).
        $head[$h][0] = dol_buildpath("/doli2shop/admin/underprice_audit.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("UnderpriceAuditTab");
        $head[$h][2] = 'underpriceaudit';
        $h++;

        // Nettoyage des photos produit dupliquées par l'import (story
        // import-images-duplique-les-photos-a-chaque-synchronisation, AC5). Sans cette entrée,
        // l'écran existe mais n'est atteignable par aucun lien : le dépôt connaît déjà cette
        // classe de défaut (sections de diagnostic vivantes sur une page, mortes sur l'autre).
        $head[$h][0] = dol_buildpath("/doli2shop/admin/image_duplicates_cleanup.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("ImageDuplicatesCleanupTab");
        $head[$h][2] = 'imageduplicatescleanup';
        $h++;

        // Traitement des produits hors vente déjà poussés (Story 58-5, AC4)
        $head[$h][0] = dol_buildpath("/doli2shop/admin/offsale_products.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("OffSaleProductsTab");
        $head[$h][2] = 'offsaleproducts';
        $h++;

        // Outils de maintenance (diagnostic mapping) — écran existant, jusqu'ici atteignable par
        // aucun lien (story dix-ecrans-atteignables-par-aucun-lien, AC1/AC4). La section "Outils
        // de récupération" morte (boutons sans JavaScript) a été retirée du fichier avant ce
        // rebranchement — inutile de brancher un onglet vers des boutons qui ne font rien.
        $head[$h][0] = dol_buildpath("/doli2shop/admin/maintenance.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
        $head[$h][1] = $langs->trans("MaintenanceTools");
        $head[$h][2] = 'maintenance';
        $h++;
    }

    // Licence — toujours visible (v2.1.7 - Dual-Channel Billing), HORS $configComplete.
    // CORRECTION VALIDATE (49-1) : porte $storeParam mais reste hors du bloc conditionnel.
    $head[$h][0] = dol_buildpath("/doli2shop/admin/shopify_license.php", 1) . ($storeParam ? '?' . ltrim($storeParam, '&') : '');
    $head[$h][1] = $langs->trans("LicenseTab");
    $head[$h][2] = 'license';
    $h++;

    // ── GROUPE COMMUN (suite) — toujours visibles, SANS store_id ──────────────

    // Tickets de support — écran existant, fonctionnel (crée/suit des tickets via l'API du site),
    // jusqu'ici atteignable par aucun lien (story dix-ecrans-atteignables-par-aucun-lien, AC3).
    // Toujours visible, hors $configComplete : utile même sur une install pas encore configurée.
    $head[$h][0] = dol_buildpath("/doli2shop/admin/support_tickets.php", 1);
    $head[$h][1] = $langs->trans("SupportTickets");
    $head[$h][2] = 'supporttickets';
    $h++;

    // Health & Diagnostic Tab — onglet unifié (Story 49-6)
    // Badge coloré si la santé est dégradée (orange/red), via HealthChecker mis en cache.
    $healthLabel = $langs->trans("HealthTab");
    $healthStatus = doli2shopGetCachedHealthStatus($db);
    if (!empty($healthStatus['status']) && $healthStatus['status'] !== 'green') {
        $badgeColor = ($healthStatus['status'] === 'red') ? '#dc3545' : '#f0ad4e';
        $errCount = isset($healthStatus['errors_24h']) ? (int) $healthStatus['errors_24h'] : 0;
        $healthLabel .= ' <span class="badge" title="' . dol_escape_htmltag($langs->trans("VerificationBadgeTooltip")) . '" style="background:' . $badgeColor . ';color:#fff;border-radius:10px;padding:1px 6px;font-size:0.8em;">' . $errCount . '</span>';
    }
    $head[$h][0] = dol_buildpath("/doli2shop/admin/health.php", 1);
    $head[$h][1] = $healthLabel;
    $head[$h][2] = 'health';
    $h++;

    // Guide Tab - toujours visible, commun (sans store_id)
    $head[$h][0] = dol_buildpath("/doli2shop/admin/setup_guide.php", 1);
    $head[$h][1] = $langs->trans("SetupGuide");
    $head[$h][2] = 'guide';
    $h++;

    // About Tab - toujours visible, commun (sans store_id)
    $head[$h][0] = dol_buildpath("/doli2shop/admin/about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'doli2shop@doli2shop');
    complete_head_from_modules($conf, $langs, null, $head, $h, 'doli2shop@doli2shop', 'remove');

    return $head;
}

/**
 * Construit le segment de télémétrie commun (domaine Dolibarr, version Dolibarr,
 * version du module, version PHP) envoyé dans les payloads de validation/licence/support.
 *
 * Contrat strict (Story 50-9) : ne renvoie JAMAIS la chaîne littérale 'unknown' ni une
 * valeur null explicite — quand une donnée n'est pas disponible, la clé correspondante
 * est simplement absente du tableau retourné (le tableau est construit incrémentalement,
 * pas via `?? 'unknown'` ni `array_filter()` qui risquerait de retirer des valeurs
 * légitimes comme '0').
 *
 * Résolution de `dolibarr_domain` :
 * - `$_SERVER['HTTP_HOST']` si non vide (contexte HTTP normal, comportement inchangé) ;
 * - sinon host extrait de `global $dolibarr_main_url_root` (défini dans conf/conf.php,
 *   ex. 'http://127.0.0.1:8987') via `parse_url(..., PHP_URL_HOST)` — permet de résoudre
 *   un vrai domaine même en contexte CLI/CRON (pas de $_SERVER['HTTP_HOST']) ;
 * - sinon la clé `dolibarr_domain` est absente du tableau retourné.
 *
 * Résolution de `dolibarr_version` : `DOL_VERSION` si `defined('DOL_VERSION')` et non
 * vide, sinon la clé est absente.
 *
 * `module_version` (DOLI2SHOP_MODULE_VERSION) et `php_version` (PHP_VERSION) sont
 * toujours présents.
 *
 * Pas de dépendance `$db`/`$conf` : uniquement des sources globales PHP et des constantes,
 * ce qui permet d'appeler cette fonction depuis n'importe quel contexte (classe, script
 * admin, CRON) sans setup supplémentaire.
 *
 * @return array Tableau associatif ne contenant que les clés effectivement résolues
 * @since 2.4.1 Story 50-9 — télémétrie domaine/version, jamais de sentinelle générique
 */
function doli2shopBuildTelemetry()
{
    $telemetry = array();

    $dolibarrDomain = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $dolibarrDomain = $_SERVER['HTTP_HOST'];
    } else {
        global $dolibarr_main_url_root;
        if (!empty($dolibarr_main_url_root)) {
            $parsedHost = parse_url($dolibarr_main_url_root, PHP_URL_HOST);
            if (!empty($parsedHost)) {
                $dolibarrDomain = $parsedHost;
            }
        }
    }
    if ($dolibarrDomain !== '') {
        $telemetry['dolibarr_domain'] = $dolibarrDomain;
    }

    if (defined('DOL_VERSION') && DOL_VERSION !== '') {
        $telemetry['dolibarr_version'] = DOL_VERSION;
    }

    $telemetry['module_version'] = DOLI2SHOP_MODULE_VERSION;
    $telemetry['php_version'] = PHP_VERSION;

    return $telemetry;
}

/**
 * Récupère le statut de la licence depuis l'API ou le cache.
 *
 * Comportement selon le paramètre $shopDomain :
 * - Sans paramètre (null) : comportement global actuel **strictement préservé** (cache par entity).
 *   Utilisé par les gates download/support existants, validateDualChannel, etc.
 * - Avec $shopDomain non vide : valide la licence de CETTE boutique et la cache par domaine
 *   (clé = 'DOLI2SHOP_LICENSE_STATUS_' + md5($shopDomain)). Les deux espaces de clés
 *   sont distincts (md5 hex ≠ entier numérique entity).
 *
 * Dans les deux cas, le TTL du cache est de 5 minutes.
 *
 * v2.1.8 - Mode dégradé (flux global)
 * v2.3.4 - Cache par domaine pour multi-boutiques (Story 47-6)
 *
 * @param  string|null $shopDomain Domaine Shopify de la boutique à vérifier (null = flux global)
 * @return array Statut de la licence avec mode dégradé
 */
function doli2shopGetLicenseStatus($shopDomain = null)
{
    global $conf, $db;

    // Story 47-6 : si un domaine est fourni, utiliser un cache par domaine (clé md5)
    // pour éviter que la licence d'une boutique en écrase une autre.
    if (!empty($shopDomain)) {
        $domainHash  = md5($shopDomain);
        $cacheKey    = 'DOLI2SHOP_LICENSE_STATUS_' . $domainHash;
        $cacheExpiry = 'DOLI2SHOP_LICENSE_STATUS_EXPIRY_' . $domainHash;

        $fromCache = doli2shopReadCachedLicenseStatus($shopDomain);
        if ($fromCache !== null) {
            return $fromCache;
        }

        // POST billing avec CE domaine (pas la constante globale)
        // Story 50-9 : télémétrie (domaine/version Dolibarr/version module/version PHP)
        // construite via doli2shopBuildTelemetry() — jamais 'unknown', clé omise si non résolue.
        $apiUrl   = 'https://doli2shop.ptitetete.org/api/billing.php?action=validate-dolibarr';
        $postData = json_encode(array_merge(
            [
                'action'      => 'validate-dolibarr',
                'shop_domain' => $shopDomain,
            ],
            doli2shopBuildTelemetry()
        ));

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            // API injoignable — boutique secondaire sera exclue (pas de dégradé pour les secondaires)
            // On NE met PAS en cache cet état d'erreur (pas de $cacheExpiry) : la prochaine tentative
            // réessaiera l'API et pourra récupérer un cache récent si entre-temps il était stocké.
            return [
                'valid'        => false,
                'status'       => 'api_error',
                'mode'         => 'normal',
                'can_sync'     => false,
                'can_download' => false,
                'can_support'  => false,
            ];
        }

        $result = json_decode($response, true);
        if (!$result) {
            return [
                'valid'        => false,
                'status'       => 'parse_error',
                'mode'         => 'normal',
                'can_sync'     => false,
                'can_download' => false,
                'can_support'  => false,
            ];
        }

        // Mettre en cache pour 5 minutes (amortit les erreurs transitoires)
        dolibarr_set_const($db, $cacheKey, json_encode($result), 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, $cacheExpiry, time() + DOLI2SHOP_LICENSE_CACHE_TTL, 'int', 0, '', $conf->entity);

        return $result;
    }

    // ── Flux global (sans paramètre) — strictement préservé, aucune modification ──
    // Vérifier le cache (valide 5 minutes)
    $cacheKey = 'DOLI2SHOP_LICENSE_STATUS_' . $conf->entity;
    $cacheExpiry = 'DOLI2SHOP_LICENSE_STATUS_EXPIRY_' . $conf->entity;

    $cachedStatus = getDolGlobalString($cacheKey);
    $cachedExpiry = getDolGlobalInt($cacheExpiry, 0);

    if ($cachedStatus && $cachedExpiry > time()) {
        return json_decode($cachedStatus, true);
    }

    // Récupérer les infos de connexion
    dol_include_once('/doli2shop/class/configurationMigrator.class.php');
    $migrator = new ConfigurationMigrator($db);
    $config = $migrator->getConfiguration($conf->entity);

    $shopDomain = $config['shopify_store_hostname'] ?? '';
    if (empty($shopDomain)) {
        return [
            'valid' => false,
            'status' => 'not_configured',
            'mode' => 'normal',
            'can_sync' => false,
            'can_download' => false,
            'can_support' => false,
        ];
    }

    // Appeler l'API pour valider (action en query param ET dans le body JSON
    // car PHP ne remplit pas $_POST pour Content-Type: application/json)
    // Story 50-9 : télémétrie construite via doli2shopBuildTelemetry() — jamais 'unknown',
    // clé omise si non résolue.
    $apiUrl = 'https://doli2shop.ptitetete.org/api/billing.php?action=validate-dolibarr';
    $postData = json_encode(array_merge(
        [
            'action' => 'validate-dolibarr',
            'shop_domain' => $shopDomain,
        ],
        doli2shopBuildTelemetry()
    ));

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        // En cas d'erreur, permettre la sync mais bloquer le reste
        return [
            'valid' => false,
            'status' => 'api_error',
            'mode' => 'degraded',
            'can_sync' => true,
            'can_download' => false,
            'can_support' => false,
        ];
    }

    $result = json_decode($response, true);
    if (!$result) {
        return [
            'valid' => false,
            'status' => 'parse_error',
            'mode' => 'degraded',
            'can_sync' => true,
            'can_download' => false,
            'can_support' => false,
        ];
    }

    // Mettre en cache pour 5 minutes
    dolibarr_set_const($db, $cacheKey, json_encode($result), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, $cacheExpiry, time() + DOLI2SHOP_LICENSE_CACHE_TTL, 'int', 0, '', $conf->entity);

    return $result;
}

// Story licence-gate-des-ecritures-sortantes (AC3) — fenêtre de fail-open borné du gate RÉEL
// (Mécanisme B). ⚠️ Ne JAMAIS confondre avec DOLI2SHOP_LICENSE_GRACE_PERIOD_DAYS (story 61-9,
// Mécanisme A, affichage/télémétrie SEULEMENT, n'affecte jamais can_sync) ni avec les 60 jours
// post-expiration décidés le 12/09/2026 (ceux-là sont calculés côté serveur dans sync_stops_at).
// Celle-ci mesure une chose différente des deux autres : combien de temps on continue à
// synchroniser quand c'est NOTRE serveur de licences qui ne répond plus.
if (!defined('DOLI2SHOP_SYNC_GATE_FAILOPEN_WINDOW_DAYS_DEFAULT')) {
    define('DOLI2SHOP_SYNC_GATE_FAILOPEN_WINDOW_DAYS_DEFAULT', 14);
}

/**
 * Vérifie la signature RSA-SHA256 d'une date d'arrêt de synchronisation (AC2-ter).
 *
 * Fonction PURE (aucun accès réseau ni base) — volontairement séparée de la récupération de clé
 * pour rester testable avec une paire de clés générée à la volée dans les tests. Fail-closed
 * strict : toute anomalie renvoie false, jamais un doute favorable au client.
 *
 * @param  string $shopDomain     Domaine de la boutique concernée (lie la signature à CE client
 *                                — empêche de rejouer la réponse encore valide d'un autre client)
 * @param  string $rawSyncStopsAt Valeur BRUTE du champ sync_stops_at telle que reçue (chaîne)
 * @param  string $signatureHex   Signature hexadécimale reçue
 * @param  string $publicKeyPem   Clé publique PEM à utiliser pour la vérification
 * @return bool True seulement si openssl_verify() confirme la signature (retour === 1)
 * @since 2.6.0 Story licence-gate-des-ecritures-sortantes — AC2-ter
 */
function doli2shopVerifySyncGateSignature($shopDomain, $rawSyncStopsAt, $signatureHex, $publicKeyPem)
{
    if (!function_exists('openssl_verify') || empty($publicKeyPem) || empty($signatureHex)) {
        return false;
    }

    $rawSig = @hex2bin((string) $signatureHex);
    if ($rawSig === false) {
        return false;
    }

    $dataToVerify = (string) $shopDomain . '|' . (string) $rawSyncStopsAt;
    $verify = openssl_verify($dataToVerify, $rawSig, $publicKeyPem, OPENSSL_ALGO_SHA256);

    return $verify === 1;
}

/**
 * Récupère la clé publique de signature du gate de synchronisation, avec cache local par
 * key_id — MÊME patron que doli2shopFetchOAuthPublicKey() (admin/oauth_receive.php:83-129,
 * Story 34.6), répliqué ici plutôt que réutilisé tel quel pour deux raisons : ce gate doit
 * pouvoir être évalué depuis un CRON/webhook (pas seulement une page admin avec $user/$langs
 * chargés), et ses clés protègent une surface distincte de l'OAuth — jamais de partage des
 * constantes de cache entre les deux.
 *
 * ⚠️ Ne construit AUCUNE infrastructure neuve côté serveur : le patron (génération RSA-2048,
 * endpoint public {alg, key_id, public_key}, cache par key_id, fail-closed) existe déjà et
 * tourne en production pour l'OAuth — seul le endpoint et les constantes de cache sont dédiés.
 *
 * @param  string $keyId Identifiant de clé annoncé dans la réponse licence
 * @param  DoliDB $db    Connexion base
 * @param  object $conf  Conf Dolibarr (entity)
 * @return string|null PEM de la clé publique correspondant à $keyId, ou null si indisponible/invalide
 * @since 2.6.0 Story licence-gate-des-ecritures-sortantes — AC2-ter
 */
function doli2shopFetchSyncGatePublicKey($keyId, $db, $conf)
{
    // Format strict (fingerprint hex) — bloque tout key_id forgé et évite des fetchs parasites.
    if (!preg_match('/^[a-f0-9]{8,64}$/', (string) $keyId)) {
        return null;
    }

    // 1) Cache : si on a déjà la clé pour ce key_id, l'utiliser (TOFU — aucune re-vérification
    // réseau tant que le key_id annoncé ne change pas).
    $cachedId  = getDolGlobalString('DOLI2SHOP_SYNCGATE_PUBKEY_ID', '');
    $cachedPem = getDolGlobalString('DOLI2SHOP_SYNCGATE_PUBKEY_PEM', '');
    if ($cachedId === $keyId && $cachedPem !== '') {
        return $cachedPem;
    }

    if (!function_exists('getURLContent')) {
        dol_include_once('/core/lib/geturl.lib.php');
    }
    if (!function_exists('getURLContent')) {
        return null;
    }

    // Endpoint attendu côté site (lot séparé) : GET, réponse {"alg":"RSA-SHA256","key_id":"...",
    // "public_key":"...PEM..."} — même forme que oauth/pubkey.php.
    $url = 'https://doli2shop.ptitetete.org/api/license-pubkey.php';
    $result = getURLContent($url, 'GET', '', 1, array(), array('https'), 0);
    if (!is_array($result) || (int) ($result['http_code'] ?? 0) !== 200 || empty($result['content'])) {
        dol_syslog("Doli2Shop SyncGate: échec récupération clé publique ($url) code=" . ($result['http_code'] ?? 'n/a'), LOG_WARNING);
        return null;
    }

    $payload = json_decode($result['content'], true);
    if (!is_array($payload) || empty($payload['key_id']) || empty($payload['public_key'])) {
        dol_syslog("Doli2Shop SyncGate: réponse pubkey invalide", LOG_WARNING);
        return null;
    }

    // Le key_id servi doit correspondre à celui annoncé dans la réponse licence (intégrité).
    if (!hash_equals((string) $payload['key_id'], (string) $keyId)) {
        dol_syslog("Doli2Shop SyncGate: key_id pubkey (" . $payload['key_id'] . ") != attendu ($keyId)", LOG_WARNING);
        return null;
    }

    // Sanity : PEM de clé publique valide.
    if (openssl_pkey_get_public($payload['public_key']) === false) {
        dol_syslog("Doli2Shop SyncGate: clé publique reçue invalide (openssl)", LOG_WARNING);
        return null;
    }

    $entity = isset($conf->entity) ? (int) $conf->entity : 1;
    dolibarr_set_const($db, 'DOLI2SHOP_SYNCGATE_PUBKEY_ID', (string) $payload['key_id'], 'chaine', 0, '', $entity);
    dolibarr_set_const($db, 'DOLI2SHOP_SYNCGATE_PUBKEY_PEM', (string) $payload['public_key'], 'chaine', 0, '', $entity);

    return (string) $payload['public_key'];
}

/**
 * Extrait et VÉRIFIE la date d'arrêt de synchronisation d'une réponse licence, pour un usage de
 * GATE (par opposition à `doli2shopGetSyncStopDeadline()`, réutilisée ci-dessous pour le
 * parsing/sanity, mais qui ne vérifie AUCUNE signature — elle n'alimente que l'affichage AC3-bis,
 * jamais une décision de blocage).
 *
 * Fail-closed strict (AC2-ter) : `sync_stops_at` absent, `sync_stops_at_key_id`/
 * `sync_stops_at_signature` absents, clé publique injoignable/invalide, hex invalide, ou
 * `openssl_verify()` != 1 — tous ces cas sont traités EXACTEMENT comme "aucune date reçue"
 * (retour null), JAMAIS comme une date lointaine.
 *
 * Revérifiée INTÉGRALEMENT à chaque appel, y compris quand `$licenseStatus` provient du cache 5
 * minutes de `doli2shopGetLicenseStatus()` : ce cache contient les champs signés BRUTS (jamais un
 * verdict pré-vérifié), donc chaque lecture — fraîche ou en cache — repasse par
 * `openssl_verify()`. C'est ce qui satisfait « vérifiée à chaque lecture, y compris depuis le
 * cache ».
 *
 * @param  array  $licenseStatus Réponse de doli2shopGetLicenseStatus() (fraîche ou en cache)
 * @param  string $shopDomain    Domaine de la boutique concernée
 * @return int|null Timestamp Unix vérifié, ou null si aucune date fiable n'a été reçue
 * @since 2.6.0 Story licence-gate-des-ecritures-sortantes — AC2-bis / AC2-ter
 */
function doli2shopGetVerifiedSyncStopDeadline(array $licenseStatus, $shopDomain)
{
    global $db, $conf;

    $rawValue = $licenseStatus['sync_stops_at'] ?? null;
    if (empty($rawValue) || !is_string($rawValue)) {
        return null;
    }

    $keyId        = (string) ($licenseStatus['sync_stops_at_key_id'] ?? '');
    $signatureHex = (string) ($licenseStatus['sync_stops_at_signature'] ?? '');
    if ($keyId === '' || $signatureHex === '') {
        // Date présente mais non signée (ou signature incomplète) : traitée comme absente.
        return null;
    }

    $publicKeyPem = doli2shopFetchSyncGatePublicKey($keyId, $db, $conf);
    if ($publicKeyPem === null) {
        return null;
    }

    if (!doli2shopVerifySyncGateSignature((string) $shopDomain, $rawValue, $signatureHex, $publicKeyPem)) {
        return null;
    }

    // Signature valide : réutiliser le parsing/sanity déjà éprouvé (fenêtre ±2 ans, strtotime).
    return doli2shopGetSyncStopDeadline($licenseStatus);
}

/**
 * Évalue le gate de synchronisation pour la boutique PAR DÉFAUT (AC1/AC2/AC2-bis/AC2-ter/AC3).
 *
 * Avant cette story, la boutique par défaut était TOUJOURS autorisée, sans même interroger le
 * serveur (invariant Epic 47-6). Cette story y met fin, mais de façon strictement bornée :
 * - Aucune date d'arrêt signée reçue → gate INACTIF, comportement historique préservé (AC2).
 * - Date reçue et signée, mais future → autorisé (période de grâce, AC2-bis).
 * - Date reçue, signée, et dépassée → BLOQUÉ (AC1).
 * - Serveur de licences injoignable → fail-open BORNÉ (AC3) : autorisé tant que la dernière
 *   validation réussie date de moins de DOLI2SHOP_SYNC_GATE_FAILOPEN_WINDOW_DAYS jours, puis
 *   bloqué. Distinct de doli2shopGetLicenseStatus() (flux global, comportement de repli
 *   INCHANGÉ, utilisé par le bandeau/diagnostic) : ici on interroge TOUJOURS la branche par
 *   domaine, qui ne met pas en cache les erreurs réseau (donc jamais de faux "succès" mémorisé).
 *
 * @param  string $shopDomain Domaine Shopify de la boutique par défaut
 * @return array ['allowed'=>bool, 'status'=>'unknown'|'invalid', 'reason'=>string]
 * @since 2.6.0 Story licence-gate-des-ecritures-sortantes
 */
function doli2shopEvaluateDefaultStoreSyncGate($shopDomain)
{
    global $conf, $db;

    if (empty($shopDomain)) {
        // Cas défensif : jamais vu en production (Invariant n°1, la boutique par défaut est
        // toujours seedée avec un domaine) — gate inactif plutôt qu'un blocage sur une absence
        // de donnée qui n'est pas de la responsabilité du client.
        return ['allowed' => true, 'status' => 'unknown', 'reason' => 'no_shop_domain_default'];
    }

    $lastValidConst = 'DOLI2SHOP_SYNC_GATE_LAST_VALID_AT_' . md5((string) $shopDomain);

    try {
        $result = doli2shopGetLicenseStatus($shopDomain);
    } catch (\Throwable $e) {
        $result = ['status' => 'api_exception'];
    }

    // Blind Hunter (licence-gate-des-ecritures-sortantes) : doli2shopGetLicenseStatus() peut
    // renvoyer autre chose qu'un tableau quand le cache llx_const est corrompu ou forgé — ce
    // cache est éditable par tout administrateur Dolibarr depuis admin/const.php?visible=all
    // (cf. story, "Où le client peut forger ce cache"). Un JSON non-tableau y survit à
    // json_decode() (ex. `true`, `5`, ou une chaîne qui échoue et json_decode() renvoie null) et
    // provoquait un TypeError NON INTERCEPTÉ sur doli2shopGetVerifiedSyncStopDeadline() plus bas
    // (type-hinté `array`) — qui plantait tout le CRON en cascade (aucun try/catch aux sites
    // d'appel), pour TOUTES les boutiques du run, pas seulement celle au cache corrompu. Traité
    // exactement comme une erreur réseau : jamais un blocage direct, jamais un crash.
    if (!is_array($result)) {
        $result = ['status' => 'api_exception'];
    }

    $apiStatus = $result['status'] ?? 'unknown';
    $apiUnreachable = in_array($apiStatus, ['api_error', 'parse_error', 'api_exception'], true);

    if ($apiUnreachable) {
        $failopenDays = max(0, getDolGlobalInt(
            'DOLI2SHOP_SYNC_GATE_FAILOPEN_WINDOW_DAYS',
            DOLI2SHOP_SYNC_GATE_FAILOPEN_WINDOW_DAYS_DEFAULT
        ));
        $lastValid = getDolGlobalInt($lastValidConst, 0);

        if ($lastValid === 0) {
            // Jamais eu de succès mesuré (installation neuve, ou jamais interrogé depuis) :
            // comportement historique préservé, jamais bloqué sur cette seule base.
            return ['allowed' => true, 'status' => 'unknown', 'reason' => 'failopen_never_validated'];
        }

        $daysSinceLastValid = (time() - $lastValid) / 86400;
        if ($daysSinceLastValid <= $failopenDays) {
            return ['allowed' => true, 'status' => 'unknown', 'reason' => 'failopen_window'];
        }

        return ['allowed' => false, 'status' => 'invalid', 'reason' => 'failopen_expired'];
    }

    // Serveur joignable : mémoriser CE succès réseau (indépendant de la validité de la licence,
    // c'est un signal de disponibilité, pas un signal métier) pour le fail-open borné futur.
    dolibarr_set_const($db, $lastValidConst, (string) time(), 'entier', 0, '', $conf->entity);

    $syncStopsAt = doli2shopGetVerifiedSyncStopDeadline($result, $shopDomain);

    if ($syncStopsAt === null) {
        return ['allowed' => true, 'status' => 'unknown', 'reason' => 'no_sync_gate_date'];
    }

    if (time() < $syncStopsAt) {
        return ['allowed' => true, 'status' => 'unknown', 'reason' => 'within_grace_period'];
    }

    return ['allowed' => false, 'status' => 'invalid', 'reason' => 'sync_gate_active'];
}

/**
 * Détermine si une boutique est autorisée à synchroniser (Story 47-6 — gate licence par boutique).
 *
 * Règle PO :
 * - Boutique par défaut (is_default = 1) : plus une exemption inconditionnelle depuis la story
 *   licence-gate-des-ecritures-sortantes (AC1) — déléguée à
 *   `doli2shopEvaluateDefaultStoreSyncGate()`, qui reste lenient tant qu'aucune date d'arrêt
 *   SIGNÉE n'a été reçue du serveur (AC2), et fail-open BORNÉ si le serveur est injoignable
 *   (AC3). Ce n'est qu'au terme de la période de grâce pilotée par le serveur que cette boutique
 *   peut désormais être bloquée — invariant historique « toujours autorisée » remplacé par
 *   « autorisée tant que le serveur ne dit pas explicitement et de façon vérifiable le contraire ».
 * - Boutique secondaire (is_default = 0) : gate réel sur `valid === true`, INCHANGÉ par cette
 *   story (déjà fail-closed sur licence invalide ou API injoignable, plus strict que ce que la
 *   story ajoute pour la boutique par défaut). `can_sync` est intentionnellement ignoré (hardcodé
 *   `true` côté backend — inutilisable).
 * - Fallback (store null) : aucun gate (chemin entité/constantes = install historique) — hors
 *   périmètre de cette story, qui ne gate que les boutiques déclarées en table.
 *
 * En cas d'exception API (non-bloquant) : boutique secondaire → exclue (unknown) ; défaut →
 * fail-open borné (cf. doli2shopEvaluateDefaultStoreSyncGate()).
 *
 * @param  object|null $store Objet boutique (issu de StoreService::fetch/getAll) ou null
 * @return array ['allowed'=>bool, 'status'=>'valid'|'invalid'|'unknown', 'reason'=>string]
 */
function doli2shopStoreSyncAllowed($store): array
{
    // Pas de boutique (chemin rétrocompat entité/constantes) : jamais bloqué
    if ($store === null) {
        return ['allowed' => true, 'status' => 'unknown', 'reason' => 'no_store_context'];
    }

    $isDefault  = !empty($store->is_default);
    $shopDomain = $store->shop_domain ?? '';

    // Boutique par défaut : gate réel désormais (AC1), voir doli2shopEvaluateDefaultStoreSyncGate()
    if ($isDefault) {
        return doli2shopEvaluateDefaultStoreSyncGate($shopDomain);
    }

    // Boutique secondaire sans shop_domain : ne peut pas être licenciée (et ne doit PAS
    // retomber sur le flux global = licence de la boutique par défaut). Exclue. (review M1)
    if (empty($shopDomain)) {
        return ['allowed' => false, 'status' => 'invalid', 'reason' => 'no_shop_domain'];
    }

    // Boutique secondaire : gate sur `valid` (jamais sur `can_sync`)
    try {
        $result = doli2shopGetLicenseStatus($shopDomain);
        $valid  = isset($result['valid']) && $result['valid'] === true;
        $apiStatus = $result['status'] ?? 'unknown';

        if ($valid) {
            return ['allowed' => true, 'status' => 'valid', 'reason' => 'license_valid'];
        }

        // Distinguer licence expirée / non trouvée vs API injoignable pour les logs
        $reason = ($apiStatus === 'api_error' || $apiStatus === 'parse_error')
            ? 'api_unreachable'
            : 'license_invalid_or_not_found';

        return ['allowed' => false, 'status' => 'invalid', 'reason' => $reason];
    } catch (\Throwable $e) {
        // Exception non bloquante (capture aussi Error/TypeError) : boutique secondaire
        // exclue par défaut de sécurité (jamais de crash du CRON appelant)
        return ['allowed' => false, 'status' => 'unknown', 'reason' => 'api_exception'];
    }
}

/**
 * Détermine si une boutique doit afficher le badge « migration jeton requise » (Story 51-1/51-4).
 *
 * Un jeton OAuth est considéré non expirable — donc à migrer via re-connexion OAuth avant
 * l'échéance Shopify du 01/01/2027 — quand access_token est renseigné ET token_expires_at
 * est absent. « Absent » normalise TOUTES les représentations possibles de « pas de date » :
 * NULL (retour natif mysqli sur colonne datetime NULL), chaîne vide '', ou la sentinelle
 * MySQL '0000-00-00 00:00:00' (colonne datetime historiquement insérée hors NULL par un
 * dump/import ancien — comparaison stricte à '' uniquement raterait ce cas — Story 51-4).
 *
 * Fonction UNIQUE réutilisée par la fiche boutique et la liste (admin/stores.php) — toute
 * évolution de la règle se fait ici, jamais en dupliquant la condition à chaque site d'affichage.
 *
 * @param  object|null $store Objet boutique (issu de StoreService::fetch/getAll/getDefault) ou null
 * @return bool True si le badge « migration jeton requise » doit être affiché
 * @since  2.4.2
 */
function doli2shopStoreTokenMigrationRequired($store): bool
{
    if ($store === null || empty($store->access_token)) {
        return false;
    }

    $tokenExpiresAt = $store->token_expires_at ?? null;
    if ($tokenExpiresAt === null) {
        return true;
    }

    $normalized = trim((string) $tokenExpiresAt);
    if ($normalized === '' || strpos($normalized, '0000-00-00') === 0) {
        return true;
    }

    return false;
}

/**
 * Récupère la dernière version publiée du module depuis le website (#275 / Story 46.1)
 *
 * GET https://doli2shop.ptitetete.org/api/version.php (format json), avec cache court
 * (1h) et timeout court. Dégradation gracieuse : retourne null en cas d'erreur réseau /
 * réponse invalide (la page appelante ne doit jamais bloquer ni fataliser).
 *
 * @return string|null Numéro de version distante (ex. "2.4.0") ou null si indéterminable
 * @since 2.3.0
 */
function doli2shopGetLatestVersion()
{
    global $conf, $db;

    $cacheKey = 'DOLI2SHOP_LATEST_VERSION_' . $conf->entity;
    $cacheExpiry = 'DOLI2SHOP_LATEST_VERSION_EXPIRY_' . $conf->entity;

    $cached = getDolGlobalString($cacheKey);
    $cachedExpiry = getDolGlobalInt($cacheExpiry, 0);
    if ($cached && $cachedExpiry > time()) {
        return $cached;
    }

    $ch = curl_init('https://doli2shop.ptitetete.org/api/version.php?format=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['User-Agent: ShopifyIntegration/' . DOLI2SHOP_MODULE_VERSION],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        dol_syslog('doli2shopGetLatestVersion - curl error: ' . curl_error($ch), LOG_WARNING);
    }
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['version'])) {
        return null;
    }

    $latest = (string) $data['version'];

    // Validation stricte du format avant mise en cache (évite d'empoisonner le cache)
    if (!preg_match('/^\d+\.\d+(\.\d+)?(-[\w.]+)?$/', $latest)) {
        dol_syslog('doli2shopGetLatestVersion - format de version inattendu, ignoré: ' . $latest, LOG_WARNING);
        return null;
    }

    // Cache 1h
    dolibarr_set_const($db, $cacheKey, $latest, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, $cacheExpiry, time() + 3600, 'int', 0, '', $conf->entity);

    return $latest;
}

/**
 * Indique si une mise à jour du module est disponible (Story 46.1)
 *
 * @return string|false Version distante si supérieure à la version installée, false sinon
 *                      (false aussi si version distante indéterminable)
 * @since 2.3.0
 */
function doli2shopIsUpdateAvailable()
{
    $latest = doli2shopGetLatestVersion();
    if (empty($latest)) {
        return false;
    }
    return version_compare($latest, DOLI2SHOP_MODULE_VERSION, '>') ? $latest : false;
}

/**
 * Demande au website un lien de téléchargement tokenisé du module (Story 46.1)
 *
 * POST generate-download-token avec le shop_domain. Le website vérifie la licence
 * active avant de générer le token (1h, 3 téléchargements). Dégradation gracieuse.
 *
 * @return string|null URL de téléchargement tokenisée, ou null si licence inactive / erreur
 * @since 2.3.0
 */
function doli2shopRequestDownloadUrl()
{
    global $conf, $db;

    dol_include_once('/doli2shop/class/configurationMigrator.class.php');
    $migrator = new ConfigurationMigrator($db);
    $config = $migrator->getConfiguration($conf->entity);
    $shopDomain = $config['shopify_store_hostname'] ?? '';
    if (empty($shopDomain)) {
        return null;
    }

    $postData = json_encode([
        'action' => 'generate-download-token',
        'shop_domain' => $shopDomain,
    ]);

    $ch = curl_init('https://doli2shop.ptitetete.org/api/billing.php?action=generate-download-token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ShopifyIntegration/' . DOLI2SHOP_MODULE_VERSION],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        dol_syslog('doli2shopRequestDownloadUrl - curl error: ' . curl_error($ch), LOG_WARNING);
    }
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['download_url'])) {
        return null;
    }

    $downloadUrl = (string) $data['download_url'];

    // Garde-fou anti open-redirect : n'accepter qu'une URL du domaine officiel Doli2Shop.
    if (strpos($downloadUrl, 'https://doli2shop.ptitetete.org/') !== 0) {
        dol_syslog('doli2shopRequestDownloadUrl - URL de téléchargement rejetée (domaine inattendu): ' . $downloadUrl, LOG_WARNING);
        return null;
    }

    return $downloadUrl;
}

/**
 * Résout la date d'arrêt de synchronisation (fin de période de grâce, AC3-bis de la story
 * `licence-prevenir-et-permettre-de-renouveler`) à partir du statut de licence.
 *
 * ⚠️ Contrat attendu avec `website/api/billing.php` (lot site, développé EN PARALLÈLE — cf.
 * `docs/planning-artifacts/decision-blocage-licence-expiree.md`) : ce module lit un champ
 * `sync_stops_at` dans la réponse de `validate-dolibarr` — une date/heure (idéalement ISO 8601,
 * mais tout format accepté par `strtotime()` convient) désignant le moment EXACT où toute la
 * synchronisation (imports compris) s'arrêtera pour cette licence, à l'issue des 60 jours de
 * grâce décidés le 12/09/2026. Ce champ est DISTINCT de `expires_at` (date d'expiration de la
 * licence elle-même, déjà retournée aujourd'hui) : ne jamais les confondre, `sync_stops_at`
 * vaut en principe expiration + 60 jours.
 *
 * Tant que le serveur n'envoie pas ce champ (contrat pas encore livré côté site, ou volontairement
 * omis pour une licence non concernée par le gate), cette fonction retourne null et AUCUNE alerte
 * n'est affichée — c'est précisément ce qui permet de livrer ce lot avant celui du site. Une
 * valeur absente, vide, non parseable ou manifestement incohérente (hors d'une fenêtre de ±2 ans
 * autour d'aujourd'hui) est TOUJOURS traitée comme absente — jamais interprétée comme "une date
 * lointaine", ni comme "aujourd'hui".
 *
 * @param  array $licenseStatus Statut retourné par doli2shopGetLicenseStatus()
 * @return int|null Timestamp Unix de la date d'arrêt, ou null si non exploitable
 */
function doli2shopGetSyncStopDeadline($licenseStatus)
{
    if (empty($licenseStatus['sync_stops_at']) || !is_string($licenseStatus['sync_stops_at'])) {
        return null;
    }

    $raw = trim($licenseStatus['sync_stops_at']);
    if ($raw === '') {
        return null;
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return null;
    }

    // Garde-fou de cohérence : rejeter toute date hors d'une fenêtre raisonnable (protège
    // contre un champ mal formé côté API qui parserait "par accident" vers une valeur absurde,
    // par exemple une chaîne numérique interprétée comme un timestamp Unix ou une date epoch).
    $twoYears = 2 * 365 * 86400;
    if ($timestamp < (time() - $twoYears) || $timestamp > (time() + $twoYears)) {
        return null;
    }

    return $timestamp;
}

/**
 * Statuts qui signifient réellement une expiration de licence (par opposition à une simple
 * impossibilité de la vérifier). Contrat serveur (`website/api/billing.php:1077-1079`) :
 * `status === 'not_found'` → aucune licence trouvée (traité séparément, cf. $wantsSerialEntry) ;
 * `status === 'expired' | 'canceled'` → licence trouvée mais non valide, donc réellement expirée.
 * Tout le reste (`api_error`, `parse_error`, ou tout statut futur non listé ici) N'EST PAS une
 * expiration : cf. doli2shopShowLicenseWarningBanner() AC2 — la liste blanche, pas la liste noire.
 *
 * @return string[]
 */
function doli2shopGetGenuinelyExpiredStatuses(): array
{
    return ['expired', 'canceled'];
}

/**
 * Affiche le bandeau d'avertissement si licence expirée / jamais liée / non vérifiable (mode
 * dégradé), et l'alerte de période de grâce quand le serveur transmet une date d'arrêt de
 * synchronisation.
 * v2.1.8 - Mode dégradé
 * v2.6.0 (Story licence-prevenir-et-permettre-de-renouveler) :
 *   - AC3 : appelée sur les 27 écrans d'administration du module (au lieu de 2) ;
 *   - AC3-bis : ajout de l'alerte de période de grâce (cf. doli2shopGetSyncStopDeadline()) ;
 *   - AC4 : distingue "licence jamais liée" (`status === 'not_found'`) de "licence expirée" —
 *     avant ce correctif, les deux cas affichaient le même titre "Votre licence a expiré !".
 * v2.5.4 (Story bandeau-annonce-expiration-quand-le-serveur-est-injoignable) :
 *   - AC1/AC2 : la branche par défaut n'affirme plus "expirée". Seuls les statuts de
 *     doli2shopGetGenuinelyExpiredStatuses() (liste blanche) produisent ce titre ; `api_error`,
 *     `parse_error`, et tout statut inconnu produisent désormais un message neutre — "nous
 *     n'avons pas pu vérifier votre licence, rien n'est bloqué". Corrige la CLASSE de défaut, pas
 *     seulement les deux valeurs connues à ce jour.
 * v2.6.0 (Story licence-gate-des-ecritures-sortantes, AC9) : le message par défaut de la branche
 *   "licence expirée" bascule de `LicenseExpiredDegradedMode` ("la synchronisation continue") à
 *   `SyncStoppedByLicenseDescription` une fois `sync_stops_at` dépassée — pour ne plus promettre
 *   au client une continuité qui n'est plus vraie le jour où le gate réel s'active. Ce n'est
 *   qu'un texte par défaut : le serveur (`warning_message`) reste prioritaire, comme avant.
 *
 * @return void
 */
function doli2shopShowLicenseWarningBanner()
{
    global $langs;

    $licenseStatus = doli2shopGetLicenseStatus();

    // Ne rien afficher si licence valide
    if (!empty($licenseStatus['valid'])) {
        return;
    }

    // Ne rien afficher si pas configuré (Shopify non connecté : rien à évaluer)
    if (($licenseStatus['status'] ?? '') === 'not_configured') {
        return;
    }

    $langs->load("doli2shop@doli2shop");

    $status = $licenseStatus['status'] ?? '';

    // AC4 : "jamais liée" (aucune licence trouvée côté serveur pour ce shop/serial/email) et
    // "expirée" sont deux réalités distinctes, avec deux actions distinctes. Un client DoliStore
    // qui n'a jamais saisi son numéro de série ne doit jamais lire "Votre licence a expiré !".
    //
    // Le serveur envoie `action` pour dire ce qu'il faut proposer ('enter_serial_number' ou
    // 'renew') : c'est LUI qui fait foi, parce que lui seul sait pourquoi la licence est
    // invalide. On ne re-déduit depuis `status` que si le champ est absent — le cas d'un site
    // pas encore à jour. Sans cette lecture, un futur statut qui devrait mener à la saisie du
    // numéro de série (sans s'appeler 'not_found') afficherait "expirée" en silence.
    $serverAction = $licenseStatus['action'] ?? null;
    $hasServerAction = is_string($serverAction) && $serverAction !== '';
    $wantsSerialEntry = $hasServerAction
        ? $serverAction === 'enter_serial_number'
        : $status === 'not_found';

    if ($wantsSerialEntry) {
        $bannerTitle = $langs->trans("LicenseNeverLinkedWarning");
        $bannerMessage = $langs->trans("LicenseNeverLinkedDescription");
        $actionUrl = dol_buildpath('/doli2shop/admin/shopify_license.php', 1);
        $actionLabel = $langs->trans("LinkDoliStoreLicense");
    } elseif ($hasServerAction || in_array($status, doli2shopGetGenuinelyExpiredStatuses(), true)) {
        // Deux façons distinctes d'arriver ici, à ne pas fusionner par erreur :
        // - `$hasServerAction` (donc `action === 'renew'`, seule autre valeur connue en dehors de
        //   'enter_serial_number' déjà traité ci-dessus) : le serveur fait foi explicitement, on
        //   ne re-discute pas sa décision avec `status` — cf. le commentaire au-dessus.
        // - à défaut d'action (site pas à jour) : AC2, liste BLANCHE — seuls les statuts qui
        //   signifient réellement une expiration retombent ici. Un statut absent de cette liste
        //   (connu ou futur) ne peut jamais produire ce titre, quel que soit son nom.
        $bannerTitle = $langs->trans("LicenseExpiredWarning");
        // Story licence-gate-des-ecritures-sortantes (AC9) : ce message par défaut n'est utilisé
        // QUE si le serveur n'envoie pas `warning_message` (site pas encore à jour) — sinon, c'est
        // le serveur qui fait foi, comme pour `action` ci-dessus. Tant que sync_stops_at n'est pas
        // dépassée, "la synchronisation continue" reste vrai (AC2) : ne PAS basculer avant. Utilise
        // le parsing non signé de doli2shopGetSyncStopDeadline() — suffisant ici (affichage
        // seulement, jamais une décision de blocage, qui elle passe par
        // doli2shopGetVerifiedSyncStopDeadline() dans le gate réel).
        $syncStopDeadlineForWording = doli2shopGetSyncStopDeadline($licenseStatus);
        $defaultExpiredMessage = ($syncStopDeadlineForWording !== null && time() >= $syncStopDeadlineForWording)
            ? $langs->trans("SyncStoppedByLicenseDescription")
            : $langs->trans("LicenseExpiredDegradedMode");
        $bannerMessage = $licenseStatus['warning_message'] ?? $defaultExpiredMessage;
        $actionUrl = $licenseStatus['renewal_url'] ?? 'https://doli2shop.ptitetete.org/shopify-app/';
        $actionLabel = $langs->trans("RenewNow");
    } else {
        // AC1 : statut inconnu de la liste blanche — `api_error`, `parse_error` (notre serveur
        // n'a pas répondu, ou a répondu de façon inexploitable), ou tout statut qu'un futur
        // déploiement du site introduirait sans qu'on l'ait prévu ici. On ne sait pas ce que ce
        // statut signifie : le bandeau ne doit donc RIEN affirmer sur l'état de la licence — ni
        // "expirée", ni "valide". Message neutre, qui rassure sur le fond (AC4 : le mode dégradé,
        // lui, n'est pas touché ici).
        $bannerTitle = $langs->trans("LicenseCheckUnavailableWarning");
        $bannerMessage = $langs->trans("LicenseCheckUnavailableDescription");
        $actionUrl = dol_buildpath('/doli2shop/admin/shopify_license.php', 1);
        $actionLabel = $langs->trans("CheckNow");
    }

    print '<div class="warning" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 15px;">';
    print '<span style="font-size: 24px;">⚠️</span>';
    print '<div style="flex: 1;">';
    print '<strong style="color: #856404;">' . dol_escape_htmltag($bannerTitle) . '</strong><br>';
    print '<span style="color: #856404;">' . dol_escape_htmltag($bannerMessage) . '</span>';
    print '</div>';
    print '<a href="' . htmlspecialchars($actionUrl) . '" target="_blank" class="button" style="background: #ffc107; color: #212529; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: bold;">';
    print dol_escape_htmltag($actionLabel);
    print '</a>';
    print '</div>';

    // AC3-bis : alerte de période de grâce, affichée UNIQUEMENT tant que le serveur transmet
    // une date d'arrêt exploitable (cf. doli2shopGetSyncStopDeadline() pour le contrat exact).
    $syncStopDeadline = doli2shopGetSyncStopDeadline($licenseStatus);
    if ($syncStopDeadline !== null) {
        $formattedDeadline = dol_print_date($syncStopDeadline, 'day');
        $renewalUrl = $licenseStatus['renewal_url'] ?? 'https://doli2shop.ptitetete.org/shopify-app/';

        print '<div class="warning" style="background: #f8d7da; border: 1px solid #dc3545; border-radius: 4px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 15px;">';
        print '<span style="font-size: 24px;">⏳</span>';
        print '<div style="flex: 1;">';
        print '<strong style="color: #721c24;">' . dol_escape_htmltag($langs->trans("LicenseGracePeriodWarningTitle")) . '</strong><br>';
        print '<span style="color: #721c24;">' . dol_escape_htmltag($langs->trans("LicenseGracePeriodWarningDescription", $formattedDeadline)) . '</span>';
        print '</div>';
        print '<a href="' . htmlspecialchars($renewalUrl) . '" target="_blank" class="button" style="background: #dc3545; color: #fff; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: bold;">';
        print dol_escape_htmltag($langs->trans("RenewNow"));
        print '</a>';
        print '</div>';
    }
}

/**
 * Vérifie si le support est disponible (licence active)
 * v2.1.8 - Mode dégradé
 *
 * @return bool True si le support est disponible
 */
function doli2shopCanAccessSupport()
{
    $licenseStatus = doli2shopGetLicenseStatus();
    return !empty($licenseStatus['can_support']);
}

/**
 * Mask an email address for GDPR-compliant logging (Story 7.3 AC3, FR32, NFR-S7)
 *
 * Returns "***@domain.com" to preserve domain for debugging while hiding PII.
 *
 * @param  string $email Email address to mask
 * @return string Masked email (e.g., "***@domain.com") or "***@***" if invalid
 */
function doli2shop_mask_email($email)
{
    if (empty($email) || strpos($email, '@') === false) {
        return '***@***';
    }
    // Use strrpos to handle emails with multiple @ (e.g., "user@internal@domain.com")
    $atPos = strrpos($email, '@');
    $domain = substr($email, $atPos + 1);
    if (empty($domain)) {
        return '***@***';
    }
    return '***@' . $domain;
}

/**
 * Mask a person name for GDPR-compliant logging (Story 7.3 AC3, FR32, NFR-S7)
 *
 * Returns first character + "***" to preserve initial for debugging while hiding PII.
 *
 * @param  string $name Name to mask
 * @return string Masked name (e.g., "J***") or "***" if empty
 */
function doli2shop_mask_name($name)
{
    if (empty($name) || mb_strlen($name) <= 1) {
        return '***';
    }
    return mb_substr($name, 0, 1) . '***';
}

/**
 * Récupère le statut courant d'un objet Dolibarr lié à un event webhook (Story 38.2).
 *
 * Requête légère sur la PK de la table métier (filtrée par entité), pour afficher
 * dans la liste webhook_events si l'objet créé est toujours présent et dans quel état.
 * N+1 acceptable (25-100 lignes, lookup sur PK indexée). Produits exclus (pas de
 * statut pertinent) → retourne null.
 *
 * @param  DoliDB $db         Connexion base
 * @param  string $objectType Type d'objet : commande|facture|shipping
 * @param  int    $objectId   Rowid de l'objet
 * @return array|null  ['label' => string, 'color' => string] ou null si non pertinent ;
 *                     ['label' => trans('WebhookObjectStatusDeleted'), ...] si introuvable
 */
function doli2shop_get_object_status_label($db, $objectType, $objectId)
{
    global $langs, $conf;

    $objectId = (int) $objectId;
    if ($objectId <= 0) {
        return null;
    }

    $deleted = array('label' => $langs->trans('WebhookObjectStatusDeleted'), 'color' => '#999999');

    if ($objectType === 'commande') {
        $sql = "SELECT fk_statut FROM ".MAIN_DB_PREFIX."commande"
            ." WHERE rowid = ".$objectId." AND entity = ".((int) $conf->entity);
        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $db->fetch_object($res);
        $db->free($res);
        if (!$obj) {
            return $deleted;
        }
        switch ((int) $obj->fk_statut) {
            case -1: return array('label' => $langs->trans('WebhookObjStatusCanceled'), 'color' => '#dc3545');
            case 0:  return array('label' => $langs->trans('WebhookObjStatusDraft'), 'color' => '#999999');
            // Aplat assombri (#02807E) et non le turquoise de charte #029E9C : ce badge pose du
            // texte BLANC (admin/webhook_events.php), et blanc sur #029E9C ne vaut que 3,29:1 —
            // sous le seuil de 4,5:1 exige pour un libelle de 11px. #02807E conserve la teinte et
            // remonte a 4,78:1. Regle de charte du 17/09 : les couleurs de charte sont des
            // couleurs d'APLAT, et un aplat qui porte du texte doit etre choisi pour ce texte.
            case 1:  return array('label' => $langs->trans('WebhookObjStatusValidated'), 'color' => '#02807E');
            case 2:  return array('label' => $langs->trans('WebhookObjStatusShipping'), 'color' => '#f0ad4e');
            case 3:  return array('label' => $langs->trans('WebhookObjStatusClosed'), 'color' => '#28a745');
            default: return array('label' => '#'.(int) $obj->fk_statut, 'color' => '#999999');
        }
    }

    if ($objectType === 'facture') {
        $sql = "SELECT fk_statut, paye FROM ".MAIN_DB_PREFIX."facture"
            ." WHERE rowid = ".$objectId." AND entity = ".((int) $conf->entity);
        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $db->fetch_object($res);
        $db->free($res);
        if (!$obj) {
            return $deleted;
        }
        $statut = (int) $obj->fk_statut;
        if ($statut == 0) {
            return array('label' => $langs->trans('WebhookObjStatusDraft'), 'color' => '#999999');
        }
        if ($statut == 1) {
            return (!empty($obj->paye))
                ? array('label' => $langs->trans('WebhookObjStatusPaid'), 'color' => '#28a745')
                : array('label' => $langs->trans('WebhookObjStatusUnpaid'), 'color' => '#f0ad4e');
        }
        if ($statut == 2 || $statut == 3) {
            return array('label' => $langs->trans('WebhookObjStatusCanceled'), 'color' => '#dc3545');
        }
        return array('label' => '#'.$statut, 'color' => '#999999');
    }

    if ($objectType === 'shipping') {
        $sql = "SELECT fk_statut FROM ".MAIN_DB_PREFIX."expedition"
            ." WHERE rowid = ".$objectId." AND entity = ".((int) $conf->entity);
        $res = $db->query($sql);
        if (!$res) {
            return null;
        }
        $obj = $db->fetch_object($res);
        $db->free($res);
        if (!$obj) {
            return $deleted;
        }
        switch ((int) $obj->fk_statut) {
            case 0:  return array('label' => $langs->trans('WebhookObjStatusDraft'), 'color' => '#999999');
            case 1:  return array('label' => $langs->trans('WebhookObjStatusValidated'), 'color' => '#02807E');
            case 2:  return array('label' => $langs->trans('WebhookObjStatusClosed'), 'color' => '#28a745');
            case -1: return array('label' => $langs->trans('WebhookObjStatusCanceled'), 'color' => '#dc3545');
            default: return array('label' => '#'.(int) $obj->fk_statut, 'color' => '#999999');
        }
    }

    // product ou type inconnu : pas de statut pertinent
    return null;
}

/**
 * Renomme les catégories Dolibarr liées à une boutique suite à un changement de libellé (Story 49-5).
 *
 * Pour chaque type commande/facture/devis, si la catégorie existe ET commence par 'Doli2Shop - ',
 * le label est reconstruit via StoreCategoryHelper::buildCategoryLabel() et mis à jour.
 * Non bloquant : les erreurs sont loguées en WARNING mais n'interrompent pas le traitement.
 *
 * La catégorie produit (fk_categorie) n'est PAS renommée automatiquement (risque collision).
 *
 * @param  DoliDB $db       Connexion base de données
 * @param  object $store    Objet boutique (avec fk_categorie_order/invoice/proposal)
 * @param  string $newLabel Nouveau libellé de la boutique
 * @return int              Nombre de catégories effectivement renommées
 * @since  2.3.9
 */
function doli2shopRenameStoreCategories($db, $store, $newLabel)
{
    global $user, $conf;

    // Inclure les classes nécessaires
    if (!class_exists('StoreCategoryHelper')) {
        require_once dirname(__FILE__) . '/../class/storecategoryhelper.class.php';
    }
    if (!class_exists('Categorie')) {
        dol_include_once('/categories/class/categorie.class.php');
    }

    $typesMap = array(
        'order'   => 'fk_categorie_order',
        'invoice' => 'fk_categorie_invoice',
        'propal'  => 'fk_categorie_proposal',
    );

    $renamed = 0;

    foreach ($typesMap as $type => $col) {
        $catId = isset($store->{$col}) ? (int) $store->{$col} : 0;
        if ($catId <= 0) {
            continue;
        }

        $cat = new Categorie($db);
        if ($cat->fetch($catId) <= 0) {
            dol_syslog("doli2shopRenameStoreCategories - catégorie id=" . $catId . " type=" . $type . " introuvable, skip", LOG_WARNING);
            continue;
        }

        // Garde anti-renommage : ne renommer QUE les catégories créées par Doli2Shop
        if (strpos($cat->label, 'Doli2Shop - ') !== 0) {
            dol_syslog("doli2shopRenameStoreCategories - catégorie id=" . $catId . " '" . $cat->label . "' ne commence pas par 'Doli2Shop - ', skip (catégorie réassignée manuellement)", LOG_WARNING);
            continue;
        }

        $newCatLabel = StoreCategoryHelper::buildCategoryLabel($newLabel, $type);
        $cat->label  = $newCatLabel;

        if ($cat->update($user) >= 0) {
            $renamed++;
            dol_syslog("doli2shopRenameStoreCategories - catégorie id=" . $catId . " renommée en '" . $newCatLabel . "'", LOG_INFO);
        } else {
            dol_syslog("doli2shopRenameStoreCategories - échec update catégorie id=" . $catId . " : " . $db->lasterror(), LOG_WARNING);
        }
    }

    return $renamed;
}

/**
 * Liste des CRONs attendus du module avec leurs paramètres (Story 46.2 / 37.1).
 *
 * Source unique partagée entre le diagnostic (admin/diagnostic.php) et le wizard
 * de vérification (admin/setup_verification.php). Déplacée ici en v2.3.0 pour
 * réutilisation sans duplication.
 *
 * @return array<string,array> Clé = nom court de la classe CRON, valeur = paramètres
 * @since 2.3.0
 */
if (!function_exists('getAllExpectedCrons')) {
    function getAllExpectedCrons()
    {
        return array(
            'ImportProductsCron' => array(
                'label' => 'Doli2ShopProductsExport',
                'classesname' => '/doli2shop/class/importproductscron.class.php',
                'methodename' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopProductsExportDesc',
                'frequency' => 60,
                'unitfrequency' => 60,
                'priority' => 50
            ),
            'ShopifyHistoricalImportCron' => array(
                'label' => 'Doli2ShopOrdersHistoricalImport',
                'classesname' => '/doli2shop/class/shopifyhistoricalimportcron.class.php',
                'methodename' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopOrdersHistoricalImportDesc',
                'frequency' => 30,
                'unitfrequency' => 60,
                'priority' => 50
            ),
            'ShopifyHistoricalImportCleanupCron' => array(
                'label' => 'Doli2ShopHistoricalCleanup',
                'classesname' => '/doli2shop/class/shopifyhistoricalimportcleanupcron.class.php',
                'methodename' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopHistoricalCleanupDesc',
                'frequency' => 60,
                'unitfrequency' => 60,
                'priority' => 90
            ),
            'ShopifyProductImportCron' => array(
                'label' => 'Doli2ShopProductsImport',
                'classesname' => '/doli2shop/class/shopifyproductimportcron.class.php',
                'methodename' => 'run',
                'parameters' => '50',
                'comment' => 'Doli2ShopProductsImportDesc',
                'frequency' => 30,
                'unitfrequency' => 60,
                'priority' => 50
            ),
            'WebhookProcessCron' => array(
                'label' => 'Doli2ShopWebhookProcess',
                'classesname' => '/doli2shop/class/webhookprocesscron.class.php',
                'methodename' => 'run',
                'parameters' => '',
                'comment' => 'Doli2ShopWebhookProcessDesc',
                'frequency' => 5,
                'unitfrequency' => 60,
                'priority' => 50
            ),
            'Doli2ShopHeartbeatCron' => array(
                'label' => 'Doli2ShopHeartbeat',
                'classesname' => '/doli2shop/class/doli2shopheartbeatcron.class.php',
                'methodename' => 'run_heartbeat',
                'parameters' => '',
                'comment' => 'Doli2ShopHeartbeatDesc',
                'frequency' => 1,
                'unitfrequency' => 86400,
                'priority' => 90
            )
        );
    }
}

/**
 * Vérifie que TOUTES les colonnes attendues de `llx_doli2shop_stores` existent réellement
 * en base (hotfix 2.4.5 — « Échec de l'enregistrement des credentials »).
 *
 * Détecte une migration SQL non rejouée (mise à jour du module par simple COPIE de fichiers,
 * sans désactivation/réactivation — cas courant en clientèle) AVANT qu'elle ne produise un
 * échec incompréhensible (ex. `Unknown column 'token_expires_at'...` lors d'un INSERT/UPDATE
 * sur `StoreService::create()`/`update()`).
 *
 * ⚠️ NE PAS confondre avec `VerificationWizard::checkMigrations()` /
 * `ConfigurationMigrator::isMigrationNeeded()` : ceux-ci détectent une migration de
 * CONFIGURATION (table `llx_doli2shop_storedetails` → constantes Dolibarr), sans rapport
 * avec des colonnes SQL manquantes sur `llx_doli2shop_stores`. Faux amis, à ne pas réutiliser
 * pour ce contrôle.
 *
 * Appelée depuis 3 emplacements (Validate 2026-07-27, correction #3) :
 * - `admin/oauth_receive.php` sur échec de l'enregistrement des credentials ;
 * - `admin/health.php::checkTables()` ;
 * - `admin/diagnostic.php::checkTables()` (parité volontaire des deux copies, Story 49-6).
 * `admin/setup_verification.php` n'est volontairement PAS ciblé : ce n'est plus qu'un
 * `header('Location: health.php')` depuis la Story 49-6 (cibler ce fichier serait un no-op).
 *
 * @param  DoliDB $db Connexion base de données
 * @return array{status:string,missing:string[]} `status` vaut `complete` (rien à signaler),
 *                    `incomplete` (migration partielle : `missing` liste les colonnes absentes),
 *                    `absent` (la table n'existe pas du tout) ou `unknown` (le contrôle lui-même
 *                    n'a pas pu s'exécuter — surtout ne PAS l'assimiler à `complete`).
 * @since  2.4.5
 */
function doli2shopCheckStoresTableSchema($db): array
{
    // Colonnes attendues = miroir exact de sql/llx_doli2shop_stores.sql (création + migrations
    // ADD COLUMN successives : token_expires_at/refresh_token/token_reconnect_required en 2.4.0/2.4.1,
    // fk_categorie_proposal en 48-2, serial_number en 49-12, etc.)
    $expectedColumns = array(
        'rowid', 'entity', 'label', 'shop_domain', 'access_token', 'api_key', 'api_secret',
        'location_id', 'fk_categorie', 'fk_categorie_order', 'fk_categorie_invoice',
        'fk_categorie_proposal', 'is_default', 'active', 'license_status', 'license_checked',
        'serial_number', 'token_expires_at', 'refresh_token', 'token_reconnect_required',
        'datec', 'tms',
    );

    $sql  = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS';
    $sql .= " WHERE TABLE_SCHEMA = DATABASE()";
    $sql .= " AND TABLE_NAME = '" . $db->escape(MAIN_DB_PREFIX . 'doli2shop_stores') . "'";

    $result = $db->query($sql);
    if (!$result) {
        // Erreur SQL sur le contrôle LUI-MÊME (pas sur le schéma) : état INDÉTERMINÉ. Review
        // 3 couches (MEDIUM) : retourner « complet » ici afficherait un faux vert « Complet » sur
        // les pages de diagnostic alors que rien n'a pu être vérifié — précisément le travers
        // (échec muet) que ce hotfix corrige. On distingue donc explicitement cet état.
        dol_syslog('doli2shopCheckStoresTableSchema() - Erreur requête information_schema: ' . $db->lasterror(), LOG_WARNING);
        return array('status' => 'unknown', 'missing' => array());
    }

    // Comparaison insensible à la casse : MySQL/MariaDB traitent les noms de colonnes sans égard
    // à la casse, une colonne recréée à la main en `Token_Expires_At` ne doit pas être signalée
    // manquante (review 3 couches, LOW).
    $existingColumns = array();
    while ($row = $db->fetch_object($result)) {
        $existingColumns[] = strtolower($row->COLUMN_NAME);
    }

    // Aucune colonne remontée = la table n'existe pas du tout (une table réelle a toujours au
    // moins une colonne). Review 3 couches (MEDIUM) : ne pas annoncer « 22 colonnes manquantes »
    // sur une install où la table n'a simplement jamais été créée — le diagnostic serait exact
    // mais illisible, et la cause (table absente) est différente d'une migration partielle.
    if (empty($existingColumns)) {
        return array('status' => 'absent', 'missing' => $expectedColumns);
    }

    $missing = array_values(array_diff($expectedColumns, $existingColumns));

    return array(
        'status'  => empty($missing) ? 'complete' : 'incomplete',
        'missing' => $missing,
    );
}

/**
 * Définitions DDL des colonnes de `llx_doli2shop_stores` qu'une réparation automatique sait
 * ajouter par `ALTER TABLE … ADD COLUMN` (story « migration-ne-comble-jamais-une-table-incomplete »).
 *
 * Miroir des colonnes de `sql/llx_doli2shop_stores.sql`, à l'EXCEPTION volontaire des 6 colonnes
 * d'identité (`rowid`, `entity`, `label`, `shop_domain`, `datec`, `tms`) : celles-ci sont présentes
 * dans TOUTE version historique du `CREATE TABLE` (y compris la plus ancienne, 13 colonnes de
 * `sql/update_2.3.0_2.3.1.sql`), et certaines (`label`, `shop_domain`) sont `NOT NULL` SANS
 * défaut — un `ADD COLUMN` à l'aveugle sur une table déjà peuplée serait risqué (AC5, aucune perte
 * de données). Une table où l'une de ces 6 colonnes manquerait réellement est structurellement
 * hors du périmètre de cette réparation (cf. `doli2shopRepairStoresTableSchema()`, branche
 * "non couverte").
 *
 * ⚠️ Test de non-divergence : `test/unit/StoresTableSchemaSingleSourceOfTruthTest.php` compare
 * cette liste (+ les 6 colonnes d'identité) à `sql/llx_doli2shop_stores.sql` ET à
 * `doli2shopCheckStoresTableSchema()` — toute colonne ajoutée à l'un sans l'autre fait échouer ce
 * test (AC3).
 *
 * @return array<string,string> Colonne => fragment DDL complet (sans `ALTER TABLE … ADD COLUMN`)
 * @since  2.5.3
 */
function doli2shopStoresTableColumnDefinitions(): array
{
    return array(
        'access_token' => "access_token VARCHAR(255) DEFAULT NULL",
        'api_key' => "api_key VARCHAR(255) DEFAULT NULL",
        'api_secret' => "api_secret VARCHAR(255) DEFAULT NULL",
        'location_id' => "location_id VARCHAR(255) DEFAULT NULL",
        'fk_categorie' => "fk_categorie INT(11) DEFAULT NULL COMMENT 'Catégorie Dolibarr produits (TYPE_PRODUCT) boutique (Story 47-5)'",
        'fk_categorie_order' => "fk_categorie_order INT(11) DEFAULT NULL COMMENT 'Catégorie Dolibarr commandes (TYPE_ORDER) boutique (Story 47-5)'",
        'fk_categorie_invoice' => "fk_categorie_invoice INT(11) DEFAULT NULL COMMENT 'Catégorie Dolibarr factures (TYPE_INVOICE) boutique (Story 47-5)'",
        'fk_categorie_proposal' => "fk_categorie_proposal INT(11) DEFAULT NULL COMMENT 'Catégorie Dolibarr devis (TYPE_PROPOSAL) boutique (Story 48-2)'",
        'is_default' => "is_default TINYINT(1) NOT NULL DEFAULT 0",
        'active' => "active TINYINT(1) NOT NULL DEFAULT 1",
        'license_status' => "license_status VARCHAR(20) NOT NULL DEFAULT 'unknown' COMMENT 'Statut licence boutique : valid|invalid|unknown (Story 47-6)'",
        'license_checked' => "license_checked DATETIME DEFAULT NULL COMMENT 'Dernière vérification licence (Story 47-6)'",
        'serial_number' => "serial_number VARCHAR(50) DEFAULT NULL COMMENT 'Numéro de série DoliStore lié à cette boutique (Story 49-12)'",
        'token_expires_at' => "token_expires_at DATETIME DEFAULT NULL COMMENT 'Échéance access_token OAuth Shopify, marge de sécurité déjà déduite (Story 51-1)'",
        'refresh_token' => "refresh_token VARCHAR(512) DEFAULT NULL COMMENT 'Refresh token OAuth Shopify (usage unique, 90j) — jamais loggué (Story 51-1)'",
        'token_reconnect_required' => "token_reconnect_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Reconnexion Shopify requise : refresh token échoué définitivement (Story 51-1)'",
    );
}

/**
 * Répare AUTOMATIQUEMENT le schéma de `llx_doli2shop_stores` : ajoute par `ALTER TABLE … ADD
 * COLUMN` les colonnes détectées manquantes par `doli2shopCheckStoresTableSchema()`, quel que soit
 * l'historique de migrations de l'installation (AC1). Appelée depuis `modDoli2Shop::init()`, AVANT
 * `StoreService::ensureDefaultStore()` (décision du mainteneur, Validate 09/09/2026) : c'est le
 * point qui casse en premier sur une install affectée — `StoreService::create()` construit son SQL
 * d'après les clés de `$data`, pas d'après le schéma réel, et échoue avec `Unknown column` dès le
 * seeding de la boutique par défaut.
 *
 * DDL uniquement (AC5 — aucune perte de données) : n'exécute jamais de `DROP`/`CREATE` sur la
 * table. N'ajoute QUE des colonnes couvertes par `doli2shopStoresTableColumnDefinitions()`, toutes
 * `DEFAULT NULL` ou `NOT NULL DEFAULT <valeur>` — un `ADD COLUMN` sûr même sur une table déjà
 * peuplée. Idempotent (AC4) : sur un schéma `complete`, ne fait rien et ne journalise rien
 * d'alarmant (`LOG_DEBUG` seulement).
 *
 * Ne tente JAMAIS d'`ALTER` si le statut est `absent` (la table n'existe pas — hors périmètre,
 * l'installation elle-même doit la créer) ou `unknown` (le contrôle de schéma lui-même a échoué :
 * agir sur une liste `missing` qu'on ne peut pas faire confiance serait risqué).
 *
 * @param  DoliDB $db Connexion base de données
 * @return array{repaired:bool, added:string[], failed:string[], status:string} `status` = statut
 *         du schéma AVANT réparation ; `added` = colonnes effectivement ajoutées ; `failed` =
 *         colonnes manquantes mais non ajoutées (non couvertes par cette réparation, ou `ALTER`
 *         en échec — cause dans le log via `$db->lasterror()`) ; `repaired` = vrai si au moins une
 *         colonne a été ajoutée.
 * @since  2.5.3
 */
function doli2shopRepairStoresTableSchema($db): array
{
    $schemaCheck = doli2shopCheckStoresTableSchema($db);
    $status = $schemaCheck['status'];
    $missing = $schemaCheck['missing'];

    $result = array('repaired' => false, 'added' => array(), 'failed' => array(), 'status' => $status);

    if ($status === 'complete') {
        // AC4 : rien à faire, rien à journaliser d'alarmant sur une installation saine.
        dol_syslog('doli2shopRepairStoresTableSchema() - Schéma ' . MAIN_DB_PREFIX . 'doli2shop_stores complet, aucune réparation nécessaire', LOG_DEBUG);
        return $result;
    }

    if ($status === 'absent') {
        // Table absente : hors périmètre de cette réparation (ADD COLUMN sur une table qui
        // n'existe pas échouerait). L'installation/réinstallation du module doit la créer.
        dol_syslog('doli2shopRepairStoresTableSchema() - Table ' . MAIN_DB_PREFIX . 'doli2shop_stores absente, réparation ignorée (nécessite une (ré)installation complète)', LOG_WARNING);
        return $result;
    }

    if ($status === 'unknown') {
        // Le contrôle de schéma lui-même a échoué : ne JAMAIS agir à l'aveugle sur une liste
        // "missing" dont on ne peut pas garantir l'exactitude (AC5).
        dol_syslog('doli2shopRepairStoresTableSchema() - Contrôle de schéma indéterminé, réparation ignorée par prudence', LOG_WARNING);
        return $result;
    }

    // $status === 'incomplete'
    $columnDefinitions = doli2shopStoresTableColumnDefinitions();

    foreach ($missing as $column) {
        if (!isset($columnDefinitions[$column])) {
            // Colonne manquante non couverte (ex. une des 6 colonnes d'identité — jamais
            // observées manquantes en pratique, cf. doli2shopStoresTableColumnDefinitions()) :
            // pas d'ALTER à l'aveugle, intervention manuelle nécessaire.
            $result['failed'][] = $column;
            dol_syslog(
                "doli2shopRepairStoresTableSchema() - Colonne '$column' manquante mais non couverte par la réparation automatique (structure trop divergente), intervention manuelle nécessaire",
                LOG_ERR
            );
            continue;
        }

        $sql = 'ALTER TABLE ' . MAIN_DB_PREFIX . 'doli2shop_stores ADD COLUMN ' . $columnDefinitions[$column];
        $queryResult = $db->query($sql);
        if ($queryResult) {
            $result['added'][] = $column;
            dol_syslog("doli2shopRepairStoresTableSchema() - Colonne '$column' ajoutée sur " . MAIN_DB_PREFIX . 'doli2shop_stores (réparation automatique)', LOG_WARNING);
        } else {
            $result['failed'][] = $column;
            dol_syslog("doli2shopRepairStoresTableSchema() - Échec ajout colonne '$column': " . $db->lasterror(), LOG_ERR);
        }
    }

    $result['repaired'] = !empty($result['added']);

    return $result;
}

/**
 * Assemble le message affiché à l'utilisateur quand l'enregistrement des credentials OAuth
 * échoue (hotfix 2.4.5) : libellé traduit + cause SQL réelle + état du schéma.
 *
 * Fonction PURE, extraite de `admin/oauth_receive.php` (review 3 couches, LOW) : ce script est
 * un point d'entrée top-level (session, `header()`, `exit`) donc non exécutable en test unitaire —
 * l'assemblage du message, lui, doit rester vérifiable.
 *
 * ⚠️ Sécurité : `$storeFailureDetail` DOIT provenir de `$db->lasterror()` (texte d'erreur MySQL
 * seul), JAMAIS de `$db->lastqueryerror()` qui contiendrait la requête — donc les credentials.
 * Les valeurs dynamiques sont injectées comme PARAMÈTRES de `$langs->trans()`, qui applique
 * `htmlentities()` sur le résultat d'une clé connue : ne jamais concaténer ici une donnée brute
 * hors d'un `trans()`, ce serait une faille XSS.
 *
 * @param  Translate $langs              Gestionnaire de traductions
 * @param  string    $storeFailureDetail Texte d'erreur SQL capturé au moment de l'échec ('' si aucun)
 * @param  array     $schemaCheck        Retour de `doli2shopCheckStoresTableSchema()`
 * @return string                        Message prêt pour `setEventMessages()`
 * @since  2.4.5
 */
function doli2shopBuildOAuthStoreFailureMessage($langs, $storeFailureDetail, array $schemaCheck): string
{
    $message = $langs->trans('OAuthStoreFailed');

    if ((string) $storeFailureDetail !== '') {
        $message .= ' ' . $langs->trans('OAuthStoreFailedCause', (string) $storeFailureDetail);
    }

    $status = isset($schemaCheck['status']) ? $schemaCheck['status'] : 'unknown';
    $missing = isset($schemaCheck['missing']) && is_array($schemaCheck['missing']) ? $schemaCheck['missing'] : array();

    if ($status === 'incomplete' && !empty($missing)) {
        $message .= ' ' . $langs->trans('OAuthStoreSchemaIncomplete', implode(', ', $missing));
    } elseif ($status === 'absent') {
        $message .= ' ' . $langs->trans('OAuthStoreSchemaTableMissing', MAIN_DB_PREFIX . 'doli2shop_stores');
    }
    // status 'unknown' : le contrôle de schéma n'a pas pu s'exécuter — ne rien ajouter plutôt que
    // d'affirmer à tort une migration manquante ; la cause SQL ci-dessus porte déjà l'information.

    return $message;
}

/**
 * Décide si l'écriture d'une constante OAuth (`DOLI2SHOP_API_KEY`, `DOLI2SHOP_API_SECRET_KEY`,
 * `DOLI2SHOP_OAUTH_SCOPES`) doit être considérée comme un ÉCHEC (Story 58-3, AC1).
 *
 * Fonction PURE, séparée de l'appel `dolibarr_set_const()` lui-même : `test/bootstrap.php` stub
 * `dolibarr_set_const()` en retournant TOUJOURS 1, ce qui rend l'échec impossible à simuler si la
 * décision reste inline dans `admin/oauth_receive.php`. En l'extrayant ici, elle devient testable
 * unitairement sans dépendre du stub Dolibarr.
 *
 * Deux gardes DISTINCTES, à ne jamais fusionner :
 *  - `!empty($value)` / `$value !== ''` reste la garde d'ENTRÉE (comportement historique) : une
 *    valeur absente (le proxy n'a simplement pas renvoyé ce champ) n'est PAS une erreur, on omet
 *    l'écriture.
 *  - Le RETOUR de `dolibarr_set_const()` *après* cette garde distingue l'échec d'écriture : une
 *    valeur présente que `dolibarr_set_const()` n'a pas réussi à persister (retour <= 0) EST une
 *    erreur, au même titre que `DOLI2SHOP_STORE_HOSTNAME`/`DOLI2SHOP_ACCESS_TOKEN` juste au-dessus
 *    dans `oauth_receive.php`.
 *
 * @param  string $value      Valeur à écrire (avant l'appel à `dolibarr_set_const()`)
 * @param  int    $writeResult Retour de `dolibarr_set_const()` pour cette même valeur
 * @return bool                true si l'écriture doit être comptée comme un échec
 * @since  2.5.0
 */
function doli2shopIsOAuthConstantWriteFailure(string $value, int $writeResult): bool
{
    return $value !== '' && $writeResult <= 0;
}

/**
 * Applique l'invariant « un domaine Shopify ne peut appartenir qu'à UNE seule ligne
 * `llx_doli2shop_stores` par entité » au point UNIQUE d'écriture du callback OAuth (hotfix 2.5.0,
 * Nicolas Graillon — 3 boutiques, reconnexion de la boutique secondaire "Echelle Pro" bloquée
 * 10 jours par `Duplicate entry '...' for key 'uk_doli2shop_stores_domain'`).
 *
 * Cause racine du symptôme : le contexte de reconnexion (`store_context`/`store_id`) se perdait
 * entre le lien GET « Reconnecter via OAuth » (admin/setup.php) et le formulaire POST de
 * admin/connect_shopify.php (corrigé séparément — repropagation en champs cachés). Sans second
 * garde-fou AU POINT D'ÉCRITURE, une résolution de contexte incorrecte ferait écrire les
 * credentials d'UNE boutique sur la ligne d'une AUTRE : la contrainte UNIQUE `(shop_domain,
 * entity)` en base n'a fait que rendre l'échec VISIBLE — sans elle (ex. domaine réellement libre
 * mais mauvaise cible), l'écrasement serait SILENCIEUX. Ce garde-fou rend le cas impossible avant
 * toute écriture, avec un traitement différent selon l'origine de la cible :
 *
 * - Cible résolue IMPLICITEMENT par le code (bouton principal, session perdue) et le domaine
 *   appartient déjà à une AUTRE ligne ACTIVE : reciblage SILENCIEUX sur cette ligne — c'est elle
 *   qui possède légitimement le domaine. `targetsDefaultStore` est recalculé depuis SON
 *   `is_default`, jamais depuis la cible initiale (sinon les constantes globales `DOLI2SHOP_*`
 *   seraient écrites avec les credentials d'une boutique secondaire — régression grave, invariant
 *   Epic 47).
 * - Cible résolue IMPLICITEMENT et le domaine appartient à une AUTRE ligne DÉSACTIVÉE (review
 *   HIGH-2, post-hotfix) : REFUS, sans aucune écriture — jamais de réactivation silencieuse d'une
 *   boutique volontairement désactivée (cela relancerait des synchronisations stock/commandes
 *   coupées exprès). L'admin doit réactiver cette boutique lui-même puis utiliser SON propre
 *   bouton « Reconnecter ».
 * - Cible désignée EXPLICITEMENT par l'utilisateur (`reconnect:<id>`) et le domaine appartient à
 *   une AUTRE ligne (active ou non — non réévalué ici, comportement préexistant au hotfix) :
 *   REFUS, sans aucune écriture. L'utilisateur a désigné une cible précise ; le reciblage
 *   silencieux serait une action dans son dos.
 *
 * Fonction PURE, extraite pour rester testable unitairement — `admin/oauth_receive.php` est un
 * point d'entrée top-level (session, `header()`, `exit`) non exécutable en test, même contrainte
 * que `doli2shopBuildOAuthStoreFailureMessage()` ci-dessus.
 *
 * @param  object|null $targetStore         Boutique résolue par la chaîne if/elseif de contexte
 *                                           d'oauth_receive.php (avant ce garde), ou null (chemin création)
 * @param  bool        $targetsDefaultStore Calculé avant ce garde, en miroir de $targetStore
 * @param  object|null $existingByDomain    Boutique qui possède déjà le domaine reçu de Shopify
 *                                           (`StoreService::getByShopDomain()`), ou null si aucune.
 *                                           Doit porter la propriété `active` (0/1) quand elle est
 *                                           connue ; absente = considérée active (comportement
 *                                           historique, ne casse pas les appelants qui ne la
 *                                           renseignent pas).
 * @param  bool        $explicitTarget      true si la cible a été désignée explicitement par
 *                                           l'utilisateur (`reconnect:<id>`), false si résolue
 *                                           implicitement (bouton principal / session perdue)
 * @return array{targetStore:object|null,targetsDefaultStore:bool,reject:bool,conflictStore:object|null,conflictReason:string}
 *                    `reject` = true signifie qu'AUCUNE écriture ne doit avoir lieu ; `conflictStore`
 *                    porte alors la boutique propriétaire à nommer à l'écran (label + rowid).
 *                    `conflictReason` vaut '' (pas de refus), 'explicit_target' (cible désignée
 *                    par l'utilisateur) ou 'inactive_owner' (reciblage implicite refusé car la
 *                    boutique propriétaire est désactivée) — pilote le message affiché.
 * @since  2.5.0
 */
function doli2shopResolveOAuthDomainConflict($targetStore, bool $targetsDefaultStore, $existingByDomain, bool $explicitTarget): array
{
    $noConflict = array(
        'targetStore'         => $targetStore,
        'targetsDefaultStore' => $targetsDefaultStore,
        'reject'              => false,
        'conflictStore'       => null,
        'conflictReason'      => '',
    );

    if ($existingByDomain === null) {
        return $noConflict;
    }

    $existingRowid = (int) ($existingByDomain->rowid ?? 0);
    $targetRowid   = ($targetStore !== null) ? (int) ($targetStore->rowid ?? 0) : 0;

    // Pas de conflit : la cible EST déjà la boutique propriétaire du domaine — cas légitime déjà
    // résolu par les branches 'new' / session-perdue plus haut dans oauth_receive.php. Comportement
    // PRÉEXISTANT au hotfix, non réévalué ici : même une boutique désactivée reste reconnectable
    // via SA PROPRE ligne (reconnect:<id> ciblant elle-même) — cf. PHPDoc ci-dessus.
    if ($targetStore !== null && $existingRowid === $targetRowid) {
        return $noConflict;
    }

    if ($explicitTarget) {
        return array(
            'targetStore'         => $targetStore,
            'targetsDefaultStore' => $targetsDefaultStore,
            'reject'              => true,
            'conflictStore'       => $existingByDomain,
            'conflictReason'      => 'explicit_target',
        );
    }

    // HIGH-2 (review post-hotfix) : la boutique propriétaire du domaine est DÉSACTIVÉE — ne PAS la
    // réactiver silencieusement en lui écrivant de nouveaux credentials (cela relancerait stock/
    // commandes). `?? 1` : une valeur absente (appelant qui ne renseigne pas `active`) reste
    // considérée active, comportement historique inchangé pour ces appelants.
    if ((int) ($existingByDomain->active ?? 1) === 0) {
        return array(
            'targetStore'         => $targetStore,
            'targetsDefaultStore' => $targetsDefaultStore,
            'reject'              => true,
            'conflictStore'       => $existingByDomain,
            'conflictReason'      => 'inactive_owner',
        );
    }

    // Reciblage silencieux : la ligne qui possède déjà le domaine est la bonne cible.
    return array(
        'targetStore'         => $existingByDomain,
        'targetsDefaultStore' => ((int) ($existingByDomain->is_default ?? 0) === 1),
        'reject'              => false,
        'conflictStore'       => null,
        'conflictReason'      => '',
    );
}

/**
 * Construit le libellé d'une boutique pour affichage à l'écran, avec repli sur `#<rowid>` quand le
 * label est vide (LOW, review post-hotfix 2.5.0) — évite un message du type « la boutique « »
 * (identifiant N) » quand `label` n'a jamais été renseigné.
 *
 * @param  object $store Boutique (doit porter ->label et ->rowid)
 * @return string
 * @since  2.5.0
 */
function doli2shopStoreDisplayLabel($store): string
{
    $label = (string) ($store->label ?? '');
    if ($label !== '') {
        return $label;
    }

    return '#' . (int) ($store->rowid ?? 0);
}

/**
 * Message de refus affiché quand `doli2shopResolveOAuthDomainConflict()` renvoie `reject = true`
 * (hotfix 2.5.0 + HIGH-2 review). Deux variantes selon `conflictReason` :
 *  - `'explicit_target'` : cible désignée par l'utilisateur (`reconnect:<id>`), domaine détenu par
 *    une autre boutique — clé `OAuthStoreDomainConflict` (comportement d'origine du hotfix).
 *  - `'inactive_owner'` : reciblage implicite refusé parce que la boutique propriétaire du domaine
 *    est désactivée — clé `OAuthStoreDomainConflictInactiveOwner` (HIGH-2), invite à réactiver
 *    cette boutique puis utiliser SON propre bouton « Reconnecter ».
 *
 * Fonction PURE, testable unitairement sans dépendre de `admin/oauth_receive.php` (top-level, non
 * exécutable en test — même contrainte que les fonctions voisines de ce fichier).
 *
 * @param  object $langs          Objet Translate Dolibarr (ou stub de test exposant trans())
 * @param  string $shop            Domaine Shopify reçu de Shopify (ex. "xxx.myshopify.com")
 * @param  object $conflictStore   Boutique propriétaire du domaine (`conflictStore` du résultat)
 * @param  string $conflictReason  'explicit_target' ou 'inactive_owner'
 * @return string
 * @since  2.5.0
 */
function doli2shopBuildOAuthDomainConflictMessage($langs, string $shop, $conflictStore, string $conflictReason): string
{
    $label = doli2shopStoreDisplayLabel($conflictStore);
    $rowid = (int) ($conflictStore->rowid ?? 0);

    $key = ($conflictReason === 'inactive_owner') ? 'OAuthStoreDomainConflictInactiveOwner' : 'OAuthStoreDomainConflict';

    return $langs->trans($key, $shop, $label, $rowid);
}

/**
 * Message de reciblage SILENCIEUX affiché après succès (HIGH-1, review post-hotfix 2.5.0) —
 * symétrique de `doli2shopBuildOAuthDomainConflictMessage()` ci-dessus. Nomme la boutique
 * RÉELLEMENT mise à jour quand `doli2shopResolveOAuthDomainConflict()` a reciblé la cible : sans
 * ce message, l'écran affiche un succès générique alors qu'une AUTRE boutique que celle visée par
 * l'admin a été modifiée — exactement le motif identifié par la rétrospective de l'epic 59 comme
 * le plus coûteux du projet (action réussie + message rassurant + effet ailleurs que là où
 * l'utilisateur le croit).
 *
 * Fonction PURE, testable unitairement — même contrainte top-level que les fonctions voisines.
 *
 * @param  object $langs Objet Translate Dolibarr (ou stub de test exposant trans())
 * @param  string $shop  Domaine Shopify reçu de Shopify
 * @param  object $store Boutique réellement mise à jour (`targetStore` APRÈS reciblage)
 * @return string
 * @since  2.5.0
 */
function doli2shopBuildOAuthDomainRetargetedMessage($langs, string $shop, $store): string
{
    $label = doli2shopStoreDisplayLabel($store);
    $rowid = (int) ($store->rowid ?? 0);

    return $langs->trans('OAuthStoreDomainRetargeted', $shop, $label, $rowid);
}

/**
 * Résout la boutique cible d'une action de déconnexion OAuth (`admin/disconnect_oauth.php`),
 * à partir d'un `store_id` déjà extrait (GETPOST) et de la liste complète des boutiques de
 * l'entité (`StoreService::getAll()`).
 *
 * Fonction PURE — aucun accès `$db`/`$_SESSION`/GETPOST ici (motif `doli2shopResolveOAuthDomainConflict()`),
 * testable par simple tableau d'objets en mémoire.
 *
 * Story `deconnexion-oauth-ignore-le-multi-boutiques.md`, réserve n°5 du Validate du 09/09/2026 :
 * cet écran résout la boutique par un `store_id` porté EXPLICITEMENT par l'URL (lien « Déconnecter »
 * de `admin/setup.php` et lien de confirmation de `admin/disconnect_oauth.php` lui-même), jamais
 * par `$_SESSION['doli2shop_admin_store']` — non fiable entre la page de confirmation et l'exécution
 * (l'admin peut changer de boutique dans un autre onglet entre les deux requêtes). `$requestedStoreId`
 * doit donc être revalidé ICI, à chaque appel, contre la liste réelle des boutiques de l'entité —
 * jamais supposé valide simplement parce qu'il a traversé l'URL.
 *
 * @param  object[] $allStores        Boutiques de l'entité (StoreService::getAll()), avec ->rowid/->is_default
 * @param  int      $requestedStoreId store_id demandé (GETPOST('store_id', 'int')) ; <= 0 = aucune demande explicite
 * @return object|null                Boutique résolue (par id si valide, sinon boutique par défaut),
 *                                    ou null si aucune boutique n'existe encore (install jamais migrée)
 * @since  2.5.3
 */
function doli2shopResolveOAuthDisconnectStore(array $allStores, int $requestedStoreId)
{
    if ($requestedStoreId > 0) {
        foreach ($allStores as $store) {
            if ((int) ($store->rowid ?? 0) === $requestedStoreId) {
                return $store;
            }
        }
        // store_id demandé mais hors entité / inexistant → repli sur la boutique par défaut
        // (même politique que doli2shopGetCurrentAdminStore() — jamais un rowid non vérifié).
    }

    foreach ($allStores as $store) {
        if (!empty($store->is_default)) {
            return $store;
        }
    }

    // Aucune boutique par défaut trouvée (aucune boutique du tout : install jamais migrée, ou
    // toutes secondaires sans défaut — anomalie distincte, cf. doli2shopResolveCounterStoreScope()).
    return null;
}

/**
 * Construit le SQL de purge d'une table de synchronisation OAuth (déconnexion ou changement de
 * domaine de la boutique par défaut), en la bornant à la boutique concernée.
 *
 * Fonction PURE — aucun accès `$db` (le SHOW TABLES d'existence et l'exécution restent dans
 * l'appelant, comme avant ce refactor).
 *
 * Story `deconnexion-oauth-ignore-le-multi-boutiques.md`, réserve n°1 du Validate du 09/09/2026 —
 * PIÈGE À NE PAS REPRODUIRE : l'invariant générique du module (« pas de filtre `fk_store` quand
 * `getStoreId() == 0` ») décrit le code AVEUGLE (cron, webhooks non migrés) qui ne sait PAS quelle
 * boutique est concernée. Les deux appelants de cette fonction (`admin/disconnect_oauth.php`,
 * `admin/oauth_receive.php`) résolvent TOUJOURS une boutique explicite avant d'appeler cette
 * fonction — par défaut ou secondaire, avec un rowid réel. `$storeId > 0` doit donc TOUJOURS
 * produire le filtre `fk_store`, boutique par défaut comprise : appliquer l'invariant générique
 * littéralement (« pas de filtre si c'est la boutique par défaut ») laisserait vivre le défaut
 * (purge `entity`-wide au lieu de `fk_store`) sur le cas mono-boutique, le plus fréquent.
 * `$storeId <= 0` ne doit survenir que dans le cas légitime "aucune boutique n'existe encore"
 * (install jamais migrée) — seul cas où l'absence de filtre reproduit le comportement historique.
 *
 * Review 3 couches du 09/09/2026 (HIGH) — bord n°1 découvert sur le cas mono-boutique : la
 * correctitude de `fk_store = <rowid>` seul repose entièrement sur l'hypothèse que
 * `StoreService::backfillTechnicalTables()` a bien migré TOUTES les lignes historiques
 * `fk_store = 0` vers le rowid de la boutique par défaut — or ce backfill n'est PAS transactionnel
 * (chaque `UPDATE` gère son échec indépendamment, `class/storeservice.class.php:940-1036`) : un
 * backfill partiellement échoué laisse des orphelines à `fk_store = 0` qui ne sont alors JAMAIS
 * purgées en déconnectant la boutique par défaut, alors que l'écran promet leur suppression.
 * `$includeOrphanedRows` couvre ce bord — voir l'appelant pour la condition qui le rend sûr
 * (mono-boutique garanti par construction, seul cas où `fk_store = 0` ne peut appartenir qu'à la
 * boutique par défaut).
 *
 * @param  string $table               Nom de table complet (MAIN_DB_PREFIX inclus) — toujours une
 *                                      constante interne, jamais une entrée utilisateur.
 * @param  int    $entity              Entité Dolibarr (`$conf->entity`)
 * @param  int    $storeId             Rowid de la boutique concernée ; <= 0 = aucun filtre (chemin historique)
 * @param  bool   $includeOrphanedRows Si true ET `$storeId > 0`, inclut aussi les lignes
 *                                      résiduelles `fk_store = 0` dans le filtre (`IN (0, storeId)`).
 *                                      À ne passer à true QUE quand l'appelant a vérifié qu'aucune
 *                                      ambiguïté d'appartenance n'existe (mono-boutique garanti) —
 *                                      voir `doli2shopShouldPurgeOrphanedStoreRows()`. Par défaut
 *                                      false : préserve le comportement strict pré-existant.
 * @return string                      SQL complet, prêt pour `$db->query()`
 * @since  2.5.3
 */
function doli2shopBuildOAuthPurgeSql(string $table, int $entity, int $storeId, bool $includeOrphanedRows = false): string
{
    $sql = 'DELETE FROM ' . $table . ' WHERE entity = ' . $entity;
    if ($storeId > 0) {
        $sql .= $includeOrphanedRows
            ? ' AND fk_store IN (0, ' . $storeId . ')'
            : ' AND fk_store = ' . $storeId;
    }
    return $sql;
}

/**
 * Détermine si la purge OAuth doit aussi inclure les lignes orphelines `fk_store = 0`, en plus du
 * rowid de la boutique cible (review 3 couches du 09/09/2026, HIGH, bord n°1).
 *
 * Sûr UNIQUEMENT quand la boutique cible est la boutique PAR DÉFAUT et qu'aucune AUTRE boutique
 * n'existe pour l'entité : mono-boutique garanti par construction (invariant Epic 47 n°1), donc
 * toute ligne `fk_store = 0` ne peut appartenir qu'à cette unique boutique — aucune ambiguïté.
 * Dès qu'une boutique SECONDAIRE existe, une ligne `fk_store = 0` peut appartenir à N'IMPORTE
 * laquelle des boutiques de l'entité (le backfill ne garantit pas l'attribution par boutique en
 * cas d'échec partiel) : ne jamais l'inclure dans ce cas — la purge resterait alors strictement
 * bornée à `fk_store = <rowid résolu>`.
 *
 * Ne réutilise PAS `doli2shopBuildStoreScopedSqlFilter()` : ce helper applique `IN (0, storeId)`
 * pour TOUTE boutique par défaut, sans condition de mono-boutique — correct pour un COMPTEUR
 * d'affichage (webhooks), mais trop large pour une purge `DELETE` irréversible : en multi-boutiques,
 * il purgerait des lignes orphelines qui pourraient appartenir à une boutique secondaire.
 *
 * @param  object[] $allStores            Boutiques de l'entité (`StoreService::getAll()`)
 * @param  bool     $isTargetDefaultStore True si la boutique cible de la purge est la boutique par défaut
 * @return bool
 * @since  2.5.3
 */
function doli2shopShouldPurgeOrphanedStoreRows(array $allStores, bool $isTargetDefaultStore): bool
{
    return $isTargetDefaultStore && count($allStores) <= 1;
}

/**
 * Message de refus affiché quand AUCUNE boutique ne peut être résolue comme cible de déconnexion
 * OAuth (`doli2shopResolveOAuthDisconnectStore()` renvoie `null`) — review 3 couches du 09/09/2026
 * (HIGH, bord n°2). Sans ce refus explicite, `$targetStoreId` retombe à `0` et
 * `doli2shopBuildOAuthPurgeSql()` ne pose alors AUCUN filtre `fk_store` : la purge s'exécute sur
 * TOUTES les commandes de l'entité, toutes boutiques confondues — exactement le défaut n°2 que la
 * story corrigeait, réapparu dans ce cas d'anomalie (aucune boutique `is_default = 1`, ou install
 * jamais migrée).
 *
 * Fonction PURE, testable unitairement — même contrainte top-level que les fonctions voisines.
 *
 * @param  object $langs Objet Translate Dolibarr (ou stub de test exposant trans())
 * @return string
 * @since  2.5.3
 */
function doli2shopBuildOAuthDisconnectNoStoreMessage($langs): string
{
    return $langs->trans('DisconnectShopifyNoDefaultStore');
}

/**
 * Construit la clause SQL de filtrage par boutique (`fk_store`), partagée entre TOUTES les
 * requêtes de la page webhooks (hotfix 2.4.6).
 *
 * Reproduit EXACTEMENT l'invariant Epic 47 (filtre `fk_store` conditionnel) :
 *  - `$storeId <= 0` (aucune boutique résolue) → chaîne vide, aucun filtre (chemin historique)
 *  - Boutique secondaire → filtre strict `AND <column> = $storeId`
 *  - Boutique par défaut → `AND <column> IN (0, $storeId)` (couvre les lignes historiques
 *    `fk_store = 0` pré-backfill, toujours rattachées à la boutique par défaut)
 *
 * Cause racine du bug hotfix 2.4.6 : cette logique était dupliquée en dur dans
 * `admin/webhooks.php` (requête d'affichage ET boucle de sauvegarde) ; la Story 49-9 n'avait mis
 * à jour qu'une des deux copies, provoquant un décalage entre la boutique affichée et la boutique
 * réellement modifiée en installation multi-boutiques. Centraliser cette logique dans une unique
 * fonction, consommée par les deux requêtes, empêche la récidive.
 *
 * @param  int    $storeId     Rowid de la boutique courante (0 ou négatif = aucun filtre)
 * @param  bool   $isSecondary True si la boutique courante est une boutique secondaire (non défaut)
 * @param  string $column      Nom de la colonne à filtrer (défaut 'fk_store')
 * @return string              Clause SQL prête à concaténer (chaîne vide, ou débutant par ' AND ')
 * @since  2.4.6
 */
function doli2shopBuildStoreScopedSqlFilter(int $storeId, bool $isSecondary, string $column = 'fk_store'): string
{
    if ($storeId <= 0) {
        return '';
    }

    // Défense en profondeur : $column est toujours un littéral côté appelants internes (ex.
    // 'fk_store' ou 'w.fk_store' qualifié par alias de table), mais on n'interpole jamais un nom
    // de colonne non validé dans une requête SQL sans le contraindre à une forme sûre.
    if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)?$/', $column)) {
        $column = 'fk_store';
    }

    if ($isSecondary) {
        return ' AND ' . $column . ' = ' . $storeId;
    }

    return ' AND ' . $column . ' IN (0, ' . $storeId . ')';
}

/**
 * Résout le contexte boutique à utiliser pour un COMPTEUR d'écran admin (jamais pour le routage
 * webhook ni pour la sélection des produits à synchroniser), en distinguant deux causes bien
 * différentes derrière un retour `null` de `doli2shopGetCurrentAdminStore()` (Story 63-16, HIGH,
 * code review du 24/08) :
 *
 *  - **Aucune boutique n'existe en base** (`StoreService::countStores() === 0`) : install JAMAIS
 *    migrée — invariant Epic 47 n°1. `storeId=0` (aucun filtre) est le comportement historique
 *    correct, à ne jamais changer.
 *  - **Des boutiques existent mais aucune n'a pu être résolue** (ni `is_default = 1`, ni
 *    `store_id` en GET/session) : ce n'est PAS un chemin legacy, c'est une **anomalie de
 *    données** — cf. la story `doublon-is-default-boutiques-non-empeche.md`, qui documente le cas
 *    de cardinalité symétrique (deux lignes `is_default = 1`) pour la même table, sans contrainte
 *    d'unicité en base dans les deux sens. Reproduire "aucun filtre" ici ferait REVIVRE
 *    intégralement le défaut CRITICAL de la Story 63-16 (comptage en double d'un produit sur
 *    plusieurs boutiques réelles) dès que ≥ 2 boutiques actives existent. On se replie donc sur la
 *    1ʳᵉ boutique ACTIVE dans l'ordre déterministe de `StoreService::getAll()`, **toujours traitée
 *    comme boutique NON défaut** (jamais le motif `fk_store IN (0, id)` réservé à un défaut
 *    confirmé — on ne sait pas, dans cet état, à qui les lignes `fk_store = 0` legacy
 *    appartiennent), et on journalise l'anomalie en `LOG_WARNING` pour qu'elle soit corrigée à la
 *    source (aucune ligne `is_default = 1`).
 *
 * @param  DoliDB      $db                Connexion base de données
 * @param  object|null $currentAdminStore Retour de `doli2shopGetCurrentAdminStore($db)`
 * @param  string      $context           Préfixe de log identifiant le fichier appelant
 * @return array{storeId:int, isDefault:bool} `storeId <= 0` = aucun filtre à appliquer
 * @since  2.5.2
 */
function doli2shopResolveCounterStoreScope($db, $currentAdminStore, string $context): array
{
    if ($currentAdminStore !== null) {
        return array(
            'storeId' => (int) $currentAdminStore->rowid,
            'isDefault' => !empty($currentAdminStore->is_default),
        );
    }

    require_once dirname(__FILE__) . '/../class/storeservice.class.php';
    $storeService = new StoreService($db);

    if ($storeService->countStores() === 0) {
        // Install jamais migrée — invariant Epic 47 n°1, comportement historique inchangé.
        return array('storeId' => 0, 'isDefault' => false);
    }

    // Anomalie : des boutiques existent, aucune n'a pu être résolue.
    $activeStores = $storeService->getAll(true);
    if (empty($activeStores)) {
        // Toutes inactives : rien de cohérent à scoper, même issue que "aucune boutique" — mais
        // journalisé, car ce n'est PAS le même état que l'install jamais migrée.
        dol_syslog(
            $context . ': doli2shopResolveCounterStoreScope - des boutiques existent mais aucune '
            . 'active et aucune resolue par defaut/session — comptage sans filtre fk_store (etat a corriger)',
            LOG_WARNING
        );
        return array('storeId' => 0, 'isDefault' => false);
    }

    $fallbackStore = $activeStores[0];
    dol_syslog(
        $context . ': doli2shopResolveCounterStoreScope - aucune boutique par defaut resolue '
        . '(ni is_default=1, ni store_id GET/session) alors que ' . count($activeStores)
        . ' boutique(s) active(s) existent — comptage replie sur la boutique rowid='
        . (int) $fallbackStore->rowid . ' label="' . ($fallbackStore->label ?? '') . '", traitee '
        . 'comme boutique NON par defaut. Corriger l\'absence de ligne is_default=1 en base '
        . '(cf. doublon-is-default-boutiques-non-empeche.md).',
        LOG_WARNING
    );

    return array('storeId' => (int) $fallbackStore->rowid, 'isDefault' => false);
}

/**
 * Écrit le statut local d'un lot de lignes de webhooks, en contrôlant le retour SQL (Hotfix 2.4.7).
 *
 * Revue 3 couches (HIGH) : les écritures `SET status` étaient faites en `$db->query(...)` nu.
 * `$db->query()` renvoie `false` sur échec **sans lever d'exception** — le `try/catch (\Throwable)`
 * de la page ne pouvait donc pas les voir. Un deadlock ou une coupure laissait `$error` à 0, la
 * transaction committait et l'utilisateur lisait « Configuration sauvegardée » alors que rien
 * n'avait changé : exactement le défaut que ce hotfix corrige, réintroduit ailleurs.
 *
 * @param  DoliDB $db      Handler base
 * @param  int[]  $rowIds  Identifiants de lignes à mettre à jour (liste vide = succès, rien à faire)
 * @param  int    $status  0 (inactif) ou 1 (actif)
 * @return bool            true si l'écriture a abouti (ou n'avait pas lieu d'être)
 * @since  2.4.7
 */
function doli2shopSetWebhookRowsStatus($db, array $rowIds, int $status): bool
{
    $ids = array();
    foreach ($rowIds as $rowId) {
        $rowId = (int) $rowId;
        if ($rowId > 0) {
            $ids[] = $rowId;
        }
    }

    if (empty($ids)) {
        return true;
    }

    $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_webhooks";
    $sql .= " SET status = " . ($status === 1 ? 1 : 0);
    $sql .= " WHERE rowid IN (" . implode(',', $ids) . ")";

    $resql = $db->query($sql);
    if (!$resql) {
        dol_syslog("doli2shopSetWebhookRowsStatus - échec UPDATE status : " . $db->lasterror(), LOG_ERR);
        return false;
    }

    return true;
}

/**
 * Un `webhook_id` désigne-t-il un abonnement Shopify réellement enregistré ? (Hotfix 2.4.7)
 *
 * Revue 3 couches (Edge Case Hunter, LOW/MEDIUM « géré par accident ») : les trois points d'appel
 * utilisaient `empty($webhookId)`. Or `empty('0')` vaut `true` en PHP : une ligne portant
 * littéralement `webhook_id = '0'` (la colonne est un `varchar(255)` sans contrainte de format)
 * était classée « pas de webhook » — jamais supprimée, jamais reconnue comme vivante. Le code ne
 * fonctionnait que parce qu'un GID Shopify ne vaut jamais `'0'` : une garantie externe, pas une
 * propriété du code. La comparaison est désormais explicite.
 *
 * @param  mixed $webhookId Valeur brute lue en base (chaîne, null, absente)
 * @return bool             true si la valeur désigne un abonnement (chaîne non vide)
 * @since  2.4.7
 */
function doli2shopWebhookIdIsLive($webhookId): bool
{
    if ($webhookId === null || is_array($webhookId) || is_object($webhookId)) {
        return false;
    }

    return trim((string) $webhookId) !== '';
}

/**
 * Story 59-4 (AC1/AC2) : repère, parmi des lignes locales portant un `webhook_id` non vide, celles
 * dont l'identifiant ne correspond à AUCUN abonnement réellement présent chez Shopify au moment de
 * l'appel — des « abonnements fantômes » (révocation, réinstallation d'app, changement de version
 * d'API supprime l'abonnement côté Shopify sans que la base locale en soit informée).
 *
 * Fonction pure (aucun accès DB ni API) : les deux ensembles sont déjà chargés par l'appelant.
 * Consommée par `ShopifyWebhooks::logPhantomWebhookDivergence()` (AC2, détection/signalement pur,
 * toutes lignes actives d'un coup, appariement par IDENTIFIANT). La décision de RECRÉATION dans
 * `createWebhookWithDatabase()` (AC1) n'utilise volontairement PAS cette fonction : elle vérifie la
 * présence du TOPIC (pas de l'identifiant précis) dans l'instantané Shopify frais, pour ne jamais
 * confondre un abonnement recréé entre-temps par un autre appelant (identifiant forcément différent
 * du nôtre) avec un fantôme — cf. commentaire de cette méthode.
 *
 * @param  object[] $localActiveRows     Lignes locales (au moins `webhook_id`, `topic`, `rowid`)
 * @param  object[] $liveShopifyWebhooks Résultat BRUT de `listWebhooks()` (objets avec `->id`)
 * @return object[]                      Sous-ensemble de $localActiveRows : lignes fantômes
 * @since  2.4.8
 */
function doli2shopFindPhantomWebhookRows(array $localActiveRows, array $liveShopifyWebhooks): array
{
    $liveIds = array();
    foreach ($liveShopifyWebhooks as $liveWebhook) {
        if (isset($liveWebhook->id)) {
            $liveIds[(string) $liveWebhook->id] = true;
        }
    }

    $phantoms = array();
    foreach ($localActiveRows as $row) {
        $webhookId = isset($row->webhook_id) ? $row->webhook_id : null;
        if (!doli2shopWebhookIdIsLive($webhookId)) {
            continue;
        }
        if (!isset($liveIds[(string) $webhookId])) {
            $phantoms[] = $row;
        }
    }

    return $phantoms;
}

/**
 * Story 59-4 (AC1) : nettoie une ligne dont l'abonnement Shopify vient d'être PROUVÉ fantôme —
 * `status = 0` (elle ne décrit plus un abonnement vivant) et `webhook_id = NULL` (l'identifiant
 * fantôme ne doit plus jamais être ré-affiché ni ré-évalué comme « vivant » par
 * `doli2shopWebhookIdIsLive()`). `webhook_id` est nullable (voir `sql/llx_doli2shop_webhooks.sql`)
 * et l'index unique porte sur `(webhook_id, entity)` : plusieurs lignes à `NULL` coexistent sans
 * conflit (MySQL/MariaDB traitent NULL comme distinct dans un index unique).
 *
 * Best-effort volontaire : appelée à l'intérieur de la transaction de création de
 * `createWebhookWithDatabase()` (rollback groupé si la recréation échoue ensuite, AC5) — mais un
 * échec de CETTE UPDATE ne doit jamais, à lui seul, empêcher la tentative de création réelle qui
 * suit (la ligne fantôme resterait alors non nettoyée, ce qui est un défaut d'hygiène mineur, pas
 * une incohérence bloquante : `doli2shopSelectPreferredWebhookRow()` départage déjà par `rowid`
 * décroissant, la ligne fraîchement créée prime).
 *
 * @param  DoliDB $db     Handler base
 * @param  int[]  $rowIds Identifiants de lignes à nettoyer (liste vide = succès, rien à faire)
 * @return bool           true si l'écriture a abouti (ou n'avait pas lieu d'être)
 * @since  2.4.8
 */
function doli2shopMarkWebhookRowsAsPhantom($db, array $rowIds): bool
{
    $ids = array();
    foreach ($rowIds as $rowId) {
        $rowId = (int) $rowId;
        if ($rowId > 0) {
            $ids[] = $rowId;
        }
    }

    if (empty($ids)) {
        return true;
    }

    $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_webhooks";
    $sql .= " SET status = 0, webhook_id = NULL";
    $sql .= " WHERE rowid IN (" . implode(',', $ids) . ")";

    $resql = $db->query($sql);
    if (!$resql) {
        dol_syslog("doli2shopMarkWebhookRowsAsPhantom - échec UPDATE : " . $db->lasterror(), LOG_ERR);
        return false;
    }

    return true;
}

/**
 * Story 59-4 (AC4) : un refus Shopify (`userErrors[].message` sur `webhookSubscriptionCreate`) est-il
 * DURABLE (portée OAuth manquante, topic non éligible à la version d'app configurée dans le Shopify
 * Partner Dashboard) ou TRANSITOIRE (aléa réseau, rate limit, panne momentanée) ?
 *
 * La distinction conditionne le comportement de `cronCheckWebhookHealth()` : après la story 59-4,
 * `createWebhookWithDatabase()` ne court-circuite plus la création sur la seule présence d'un
 * `webhook_id` local (AC1) — un topic durablement refusé par Shopify serait donc retenté à CHAQUE
 * vérification périodique (chaque heure) si rien ne le distinguait d'un échec ordinaire, gonflant le
 * compteur d'échecs et affichant une santé webhooks perpétuellement en panne pour une situation que
 * seule une action humaine (accorder la portée, activer l'option côté Partner Dashboard) peut
 * résoudre — jamais une nouvelle tentative automatique.
 *
 * Classification heuristique par mots-clés, volontairement PRUDENTE : en l'absence de preuve
 * positive de refus durable, le résultat est `false` (transitoire) — un cas ambigu continue donc
 * d'être retenté plutôt que d'être étouffé à tort, ce qui serait pire que le bruit qu'il évite.
 *
 * ⚠️ Liste de mots-clés non exhaustive, établie sans essai réel contre l'API Shopify (la story
 * demandait cette confirmation en amont du code — non réalisable dans cet environnement). À affiner
 * dès qu'un message Shopify réel est observé en log pour un refus durable confirmé.
 *
 * @param  string[] $userErrorMessages Messages bruts de `$data->userErrors[]->message`
 * @return bool                        true si au moins un message signale un refus DURABLE
 * @since  2.4.8
 */
function doli2shopClassifyShopifyUserErrorAsDurable(array $userErrorMessages): bool
{
    $durableKeywords = array(
        'not configured', 'non configur',
        'not approved', 'non approuv',
        'not eligible', 'non éligible', 'non eligible',
        'access scope', 'required access', 'access denied',
        'requires approval', 'protected customer data',
        'not available for this app', "l'application n'est pas",
        'not supported for this topic',
    );

    foreach ($userErrorMessages as $message) {
        $normalized = mb_strtolower((string) $message);
        foreach ($durableKeywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Code Review 3 couches (MEDIUM 5, Epic 59) : `doli2shopClassifyShopifyUserErrorAsDurable()` est une
 * heuristique par mots-clés, explicitement établie SANS essai réel contre l'API Shopify. Si le
 * message réel d'un refus durable ne matche AUCUN mot-clé (formulation imprévue), le refus est classé
 * TRANSITOIRE à tort : `cronCheckWebhookHealth()` le retente à CHAQUE passage (chaque heure) et bascule
 * la santé en KO indéfiniment — le symptôme même que le compteur `durableRefusalCount` (Story 59-4)
 * devait éliminer.
 *
 * Cette fonction est un disjoncteur DE SECOURS, orthogonal à la classification par mots-clés : un
 * topic qui échoue sur `$threshold` exécutions CONSÉCUTIVES est traité comme un refus durable DE FACTO,
 * quelle que soit sa classification heuristique — la classification par mots-clés reste la source
 * primaire (elle coupe plus tôt, dès le 1er échec, quand elle est concluante) ; ce disjoncteur est le
 * filet qui rattrape le cas où elle se serait trompée.
 *
 * Fonction pure (aucun accès DB) : l'état (compteur d'échecs consécutifs) est chargé/persisté par
 * l'appelant (cf. `ShopifyWebhooks::loadWebhookFailStreaks()`/`saveWebhookFailStreaks()`), typiquement
 * via une constante Dolibarr (`llx_const`) — aucune migration SQL nécessaire pour ce hotfix.
 *
 * @param  int  $previousConsecutiveFailures Compteur d'échecs consécutifs déjà enregistré (0 si absent/sain)
 * @param  bool $succeededThisRun            true si CETTE tentative a réussi (création réelle OU confirmation vivante)
 * @param  bool $classifiedDurableThisRun    Résultat de `doli2shopClassifyShopifyUserErrorAsDurable()` pour CETTE tentative
 * @param  int  $threshold                   Nombre d'échecs consécutifs au-delà duquel le topic est traité
 *                                           comme durable de facto (par défaut 3 : trois heures consécutives
 *                                           pour un CRON horaire, une marge jugée suffisante pour absorber un
 *                                           aléa réseau ponctuel sans pour autant marteler indéfiniment Shopify)
 * @return array{consecutiveFailures:int, treatAsDurable:bool}
 * @since  2.4.8
 */
function doli2shopComputeWebhookFailStreakState(
    int $previousConsecutiveFailures,
    bool $succeededThisRun,
    bool $classifiedDurableThisRun,
    int $threshold = 3
): array {
    if ($succeededThisRun) {
        // Un succès referme le disjoncteur : le topic est de nouveau sain, on repart de zéro. Sans
        // cette remise à zéro, un aléa transitoire ancien resterait éternellement compté et finirait
        // par déclencher le disjoncteur sur un topic qui fonctionne pourtant de nouveau.
        return array('consecutiveFailures' => 0, 'treatAsDurable' => false);
    }

    $newCount = $previousConsecutiveFailures + 1;

    return array(
        'consecutiveFailures' => $newCount,
        'treatAsDurable' => $classifiedDurableThisRun || ($newCount >= max(1, $threshold)),
    );
}

/**
 * Correctif de review (Epic 59, story 59-9) : `doli2shopComputeWebhookFailStreakState()` ci-dessus
 * est consultée aussi bien par le CRON périodique (`cronCheckWebhookHealth()`) que par une action
 * MANUELLE (bouton « Activer tous les webhooks », reconnexion OAuth) — les deux passent par
 * `ShopifyWebhooks::attemptCreateMissingWebhook()`. Sans distinction, un topic déjà reconnu durable
 * DE FACTO (disjoncteur ouvert après `$threshold` échecs consécutifs) resterait ignoré même après
 * une action humaine qui vient précisément de corriger la cause du refus (ex. portée OAuth accordée
 * dans le Partner Dashboard, app réinstallée) — reproduisant EXACTEMENT le symptôme qui a ouvert ce
 * dossier : « je clique, il ne se passe rien, et pas non plus de message » (cf. section 1.3 de
 * l'epic). Un scénario réel et coûteux : le client corrige la cause, reclique, et le disjoncteur
 * absorbe silencieusement son clic sans jamais retenter la création chez Shopify.
 *
 * Règle : seul le CRON périodique (appel automatique, sans intention humaine explicite) doit
 * s'arrêter sur un disjoncteur déjà ouvert — c'est sa raison d'être (ne pas marteler Shopify pour un
 * topic qui ne peut structurellement pas aboutir sans action humaine). Une action MANUELLE ne saute
 * JAMAIS : c'est précisément son intérêt, elle exprime un contexte que le module n'a pas eu la
 * possibilité d'observer. Si la tentative manuelle échoue de nouveau, le compteur repart normalement
 * depuis son état courant — `doli2shopComputeWebhookFailStreakState()` l'incrémente comme n'importe
 * quel échec, sans traitement spécial — pour qu'aucun contournement permanent ne vide le disjoncteur
 * de son sens : le CRON suivant relira l'état PERSISTÉ et recommencera à ignorer si le seuil est
 * toujours atteint.
 *
 * Fonction pure (aucun accès DB/réseau, aucun effet de bord) : ne décide QUE si la tentative doit
 * être sautée — jamais l'appel Shopify lui-même, laissé tel quel à l'appelant.
 *
 * @param  int  $previousConsecutiveFailures Compteur d'échecs consécutifs déjà enregistré, AVANT cette tentative
 * @param  bool $isManualTrigger             true si l'appel provient d'une action humaine explicite
 *                                           (bouton admin « Activer tous les webhooks », reconnexion
 *                                           OAuth), false si CRON périodique (`cronCheckWebhookHealth()`)
 * @param  int  $threshold                   Même seuil que `doli2shopComputeWebhookFailStreakState()`
 *                                           (par défaut 3) — DOIT rester identique aux deux endroits.
 * @return bool true si la tentative doit être SAUTÉE (aucun appel Shopify), false si elle doit avoir lieu
 * @since  2.4.8
 */
function doli2shopShouldSkipDurableRetry(
    int $previousConsecutiveFailures,
    bool $isManualTrigger,
    int $threshold = 3
): bool {
    if ($isManualTrigger) {
        // Une action manuelle ne saute JAMAIS le disjoncteur — c'est précisément ce qu'elle doit
        // garantir : la tentative a RÉELLEMENT lieu, quel que soit l'état persisté du compteur.
        return false;
    }

    return $previousConsecutiveFailures >= max(1, $threshold);
}

/**
 * Story 59-9 (correctif review, point 4) : détermine le message à afficher après l'action « Activer
 * tous les webhooks » (`admin/webhooks.php`, action `activate_all_webhooks`), à partir de la
 * télémétrie exposée par `ShopifyWebhooks::$lastSyncDurableRefusalCount`/`$lastSyncFailedCount`.
 *
 * Avant ce correctif, l'appelant affichait TOUJOURS le même message de succès dès que
 * `syncWebhooks()` retournait `true` — or ce booléen ne reflète QUE l'échec DUR de `listWebhooks()`
 * (API totalement inaccessible), jamais un refus durable ou un échec ordinaire de recréation d'un
 * topic précis (cf. le corps de `syncWebhooks()`, qui `return true;` inconditionnellement en dehors
 * de ce cas). Un client pouvait donc croire à une activation complète alors qu'un topic restait
 * refusé durablement par Shopify (portée manquante, fonctionnalité non activée), sans jamais savoir
 * qu'une action de sa part était requise avant qu'une nouvelle tentative ait un sens.
 *
 * Priorité : un refus DURABLE prime sur un échec ORDINAIRE si les deux coexistent (un refus durable
 * ne se résoudra JAMAIS tout seul, contrairement à un échec transitoire qu'un simple nouveau clic
 * peut suffire à corriger) — l'utilisateur doit voir en priorité l'information qui requiert une
 * action de sa part.
 *
 * Fonction pure : ne fait QUE choisir la clé de langue/le type de message/le paramètre numérique —
 * jamais l'affichage lui-même, laissé à l'appelant (seul à connaître `$langs`/`setEventMessages()`).
 *
 * @param  int $durableRefusalCount Nombre de topics refusés DURABLEMENT (`ShopifyWebhooks::$lastSyncDurableRefusalCount`)
 * @param  int $failedCount         Nombre de topics en échec ORDINAIRE (`ShopifyWebhooks::$lastSyncFailedCount`)
 * @return array{langKey:string, messageType:string, countParam:int|null} `countParam` est `null` quand
 *                                  la clé de langue n'attend qu'un seul paramètre (le nom de la boutique)
 * @since  2.4.8
 */
function doli2shopBuildActivateAllWebhooksMessage(int $durableRefusalCount, int $failedCount): array
{
    if ($durableRefusalCount > 0) {
        return array(
            'langKey' => 'SomeWebhooksDurablyRefusedForStore',
            'messageType' => 'warnings',
            'countParam' => $durableRefusalCount,
        );
    }

    if ($failedCount > 0) {
        return array(
            'langKey' => 'SomeWebhooksFailedForStore',
            'messageType' => 'warnings',
            'countParam' => $failedCount,
        );
    }

    return array(
        'langKey' => 'AllWebhooksActivatedForStore',
        'messageType' => 'mesgs',
        'countParam' => null,
    );
}

/**
 * Choisit le message final affiché par `admin/webhooks.php` après l'enregistrement du formulaire
 * webhooks (bouton "Enregistrer"), à partir des trois compteurs indépendants renvoyés par
 * `WebhookSettingsSaver::saveTopics()` (Story 60-1, points 2/3).
 *
 * **Pourquoi cette fonction existe** — avant son extraction, cette décision vivait en logique
 * inline dans `admin/webhooks.php` (chaîne `if`/`elseif`), sans aucun test dédié : la mutation
 * consistant à retirer `&& empty($topicsUnverifiable)` de la première branche — c'est-à-dire
 * RÉINTRODUIRE EXACTEMENT le défaut d'origine de cette story, « Configuration sauvegardée » alors
 * que des topics n'ont pas pu être vérifiés — laissait les 1354 tests existants au vert. C'est
 * précisément l'endroit qui a trompé le client pendant dix jours, sur le modèle déjà établi par
 * `doli2shopBuildActivateAllWebhooksMessage()` ci-dessus pour un autre bouton.
 *
 * **Point 3 (MEDIUM)** — la branche « non vérifiable » ne doit JAMAIS être tue par la présence d'un
 * échec d'écriture concurrent : avant ce correctif, `!$error` gardait cette branche dans
 * `admin/webhooks.php`, si bien qu'un échec d'écriture ET un topic non vérifiable dans le MÊME
 * enregistrement retombaient sur le message "X mis à jour, Y en échec" — qui tait le non-vérifiable,
 * alors que l'information existe déjà par topic (le message par topic, lui, l'affiche). La phrase de
 * synthèse — celle qu'on lit en premier — mentait par omission. Cette fonction combine désormais les
 * TROIS compteurs dans TOUTES les combinaisons, jamais seulement deux à la fois.
 *
 * Priorité des branches (aucun succès plein n'est jamais annoncé tant qu'un repli prudent ou un
 * échec d'écriture subsiste) :
 *  1. Aucun échec, aucun non-vérifiable -> succès plein.
 *  2. Aucun échec, au moins un non-vérifiable -> succès partiel explicite (jamais "sauvegardé" plein).
 *  3. Échec + au moins un topic appliqué + aucun non-vérifiable -> partiel (comportement historique).
 *  4. Échec + au moins un topic appliqué + au moins un non-vérifiable -> partiel, mentionnant les
 *     TROIS compteurs (c'est le cas que le point 3 corrige : ne plus jamais taire le non-vérifiable).
 *  5. Échec + aucun topic appliqué + aucun non-vérifiable -> échec total (comportement historique).
 *  6. Échec + aucun topic appliqué + au moins un non-vérifiable -> échec total, mentionnant aussi le
 *     non-vérifiable (même principe que 4, sur la branche échec total).
 *
 * Fonction pure : ne fait QUE choisir la clé de langue/le type de message/les paramètres numériques
 * — jamais l'affichage lui-même, laissé à l'appelant (seul à connaître `$langs`/`setEventMessages()`).
 *
 * @param  bool $hadError           `$topicsReport['hadError'] || $generalSettingsReport['hadError']`
 * @param  int  $topicsApplied      `$topicsReport['topicsApplied']`
 * @param  int  $topicsFailed       `$topicsReport['topicsFailed']`
 * @param  int  $topicsUnverifiable `$topicsReport['topicsUnverifiable']` (Story 60-1, AC2)
 * @return array{langKey:string, messageType:string, params:array<int,int>}
 * @since  2.4.8
 */
function doli2shopBuildWebhookSaveMessage(bool $hadError, int $topicsApplied, int $topicsFailed, int $topicsUnverifiable): array
{
    if (!$hadError && $topicsUnverifiable <= 0) {
        return array('langKey' => 'SetupSaved', 'messageType' => 'mesgs', 'params' => array());
    }

    if (!$hadError) {
        // Story 60-1 (AC1) : aucun échec d'écriture, mais au moins un topic non vérifiable — ne
        // jamais annoncer "Configuration sauvegardée" (succès plein) dans ce cas : c'est exactement
        // le symptôme vécu par le client (bouton "Enregistrer", message de succès, rien ne change).
        return array(
            'langKey' => 'SetupSavedWithUnverifiableTopics',
            'messageType' => 'warnings',
            'params' => array($topicsApplied, $topicsUnverifiable),
        );
    }

    if ($topicsApplied > 0 && $topicsUnverifiable <= 0) {
        return array(
            'langKey' => 'SetupSavedPartially',
            'messageType' => 'warnings',
            'params' => array($topicsApplied, $topicsFailed),
        );
    }

    if ($topicsApplied > 0) {
        // Point 3 (MEDIUM) : les TROIS compteurs coexistent — ne plus jamais taire le non-vérifiable
        // derrière un échec d'écriture concurrent.
        return array(
            'langKey' => 'SetupSavedPartiallyWithUnverifiableTopics',
            'messageType' => 'warnings',
            'params' => array($topicsApplied, $topicsFailed, $topicsUnverifiable),
        );
    }

    if ($topicsUnverifiable > 0) {
        // Round 3 (Edge Case Hunter, MEDIUM, story 2.4.7) : quand AUCUN topic n'a pu être appliqué,
        // annoncer "une partie a été enregistrée" laisserait croire à tort qu'une majorité des cases
        // avaient été prises en compte. Point 3 : ce cas doit aussi dire le non-vérifiable, pas
        // seulement l'échec total.
        return array(
            'langKey' => 'SetupNoWebhookChangeAppliedWithUnverifiableTopics',
            'messageType' => 'errors',
            'params' => array($topicsUnverifiable),
        );
    }

    // Round 3 (Edge Case Hunter, MEDIUM, story 2.4.7) : quand AUCUN topic n'a pu être appliqué,
    // annoncer "une partie a été enregistrée" laissait croire à tort qu'une majorité des cases
    // avaient été prises en compte, alors que seuls des réglages sans rapport l'avaient été.
    return array('langKey' => 'SetupNoWebhookChangeApplied', 'messageType' => 'errors', 'params' => array());
}

/**
 * Détermine l'état d'un topic à partir de TOUTES les lignes de son scope (Hotfix 2.4.7, round 2).
 *
 * **Pourquoi cette fonction existe** — la revue adversariale 3 couches a montré, sur les trois
 * couches indépendamment, que le round 1 corrigeait la désactivation mais laissait l'activation
 * reproduire le symptôme d'origine. Deux causes :
 *  1. l'activation cherchait une « jumelle vivante » par un `foreach` premier-trouvé sur des lignes
 *     NON TRIÉES — soit une troisième copie de logique de sélection, non déterministe, exactement
 *     ce que l'AC6 interdit ;
 *  2. surtout, elle écrivait sur cette jumelle, alors que l'affichage montre la ligne *préférée* :
 *     l'action portait donc sur une ligne, l'affichage sur une autre. Case restée décochée sous un
 *     « Configuration sauvegardée » — le symptôme de Nicolas Graillon, une 3ᵉ fois.
 *
 * **La correction de fond** : l'état d'un topic n'est pas une propriété d'UNE ligne, c'est une
 * propriété du SCOPE. Un topic est actif dès qu'une ligne du scope porte un abonnement vivant. La
 * ligne « préférée » ne sert plus qu'à afficher les détails (identifiant, URL, date). Affichage et
 * enregistrement lisent désormais le même état agrégé, calculé ici et nulle part ailleurs.
 *
 * La ligne vivante est elle-même choisie par `doli2shopSelectPreferredWebhookRow()` appliquée au
 * sous-ensemble des lignes vivantes : aucune nouvelle règle de tri n'est introduite (AC6).
 *
 * @param  object[] $rowsForTopic Lignes du scope (même topic/entity/filtre boutique)
 * @return array{isActive: bool, liveRow: ?object, preferredRow: ?object, displayRow: ?object}
 * @since  2.4.7
 */
function doli2shopComputeWebhookTopicState(array $rowsForTopic): array
{
    $liveRows = array();
    $hasStatusActive = false;

    foreach ($rowsForTopic as $row) {
        if (doli2shopWebhookIdIsLive(isset($row->webhook_id) ? $row->webhook_id : null)) {
            $liveRows[] = $row;
        }
        if (isset($row->status) && (int) $row->status === 1) {
            $hasStatusActive = true;
        }
    }

    $preferredRow = doli2shopSelectPreferredWebhookRow($rowsForTopic);
    $liveRow = doli2shopSelectPreferredWebhookRow($liveRows);

    return array(
        // Un abonnement vivant prime sur tout : c'est la réalité côté Shopify. À défaut, on retient
        // un `status = 1` local (ligne orpheline d'un INSERT partiel) pour ne pas afficher
        // « inactif » sur une ligne que la désactivation devra pourtant nettoyer.
        'isActive' => ($liveRow !== null) || $hasStatusActive,
        'liveRow' => $liveRow,
        'preferredRow' => $preferredRow,
        // Les colonnes de détail (identifiant, URL, version d'API) doivent décrire l'abonnement
        // réellement en place quand il y en a un.
        'displayRow' => $liveRow !== null ? $liveRow : $preferredRow,
    );
}

/**
 * Sélectionne, parmi plusieurs lignes `llx_doli2shop_webhooks` partageant le même topic, LA ligne
 * à retenir — pour l'affichage ET pour la boucle de sauvegarde (hotfix 2.4.7).
 *
 * Cause racine du symptôme résiduel de Nicolas Graillon après le hotfix 2.4.6 : la table
 * `llx_doli2shop_webhooks` n'a aucune contrainte d'unicité sur `(topic, entity, fk_store)`. Une
 * installation antérieure au multi-boutiques (Epic 47) conserve des lignes `fk_store = 0` à côté
 * des lignes par boutique, et le filtre `IN (0, X)` de la boutique par défaut couvre les deux.
 * La requête d'affichage (`admin/webhooks.php`, l. ~494) n'avait AUCUN tri explicite entre ces
 * doublons : `fetch_object()` retournait une ligne arbitraire, dépendante de l'ordre de lecture du
 * moteur SQL (potentiellement différent entre MySQL et MariaDB) — d'où un état affiché qui ne
 * correspondait ni à ce que la sauvegarde venait d'écrire, ni à un ordre stable dans le temps.
 *
 * Règle de sélection (pure, sans I/O, testable sans base) : à topic égal, la ligne rattachée à la
 * boutique courante (fk_store le plus élevé) prime sur une ligne historique `fk_store = 0` ; à
 * `fk_store` égal, la ligne la plus récente (`rowid` le plus élevé) prime. Le résultat ne dépend
 * JAMAIS de l'ordre dans lequel les lignes sont passées en entrée (réduction par maximum) — c'est
 * précisément la propriété qui manquait à l'ancien tri implicite du moteur SQL.
 *
 * Consommée par les DEUX endroits qui doivent choisir « la » ligne représentative d'un topic
 * (`admin/webhooks.php` : requête d'affichage et boucle de sauvegarde) — une seule fonction, pour
 * ne plus jamais laisser deux copies de ce tri diverger (c'est la duplication qui a produit le
 * hotfix 2.4.6, puis ce hotfix 2.4.7, sur le même symptôme).
 *
 * @param  object[] $candidateRows Lignes `stdClass` (ou compatibles) portant au moins `fk_store`
 *                                 et `rowid`, toutes déjà filtrées sur le même topic/entity/scope
 *                                 boutique en amont (cette fonction ne fait AUCUN filtrage).
 * @return object|null             La ligne retenue, ou null si `$candidateRows` est vide.
 * @since  2.4.7
 */
function doli2shopSelectPreferredWebhookRow(array $candidateRows)
{
    $preferred = null;

    foreach ($candidateRows as $row) {
        if ($preferred === null) {
            $preferred = $row;
            continue;
        }

        $rowFkStore = isset($row->fk_store) ? (int) $row->fk_store : 0;
        $preferredFkStore = isset($preferred->fk_store) ? (int) $preferred->fk_store : 0;

        if ($rowFkStore !== $preferredFkStore) {
            if ($rowFkStore > $preferredFkStore) {
                $preferred = $row;
            }
            continue;
        }

        // fk_store identique : départager par la ligne la plus récente. `rowid` AUTO_INCREMENT
        // croissant = ordre d'insertion, cohérent avec l'ancien `ORDER BY rowid DESC` de la
        // sauvegarde (hotfix 2.4.6) que cette fonction remplace.
        $rowRowid = isset($row->rowid) ? (int) $row->rowid : 0;
        $preferredRowid = isset($preferred->rowid) ? (int) $preferred->rowid : 0;
        if ($rowRowid > $preferredRowid) {
            $preferred = $row;
        }
    }

    return $preferred;
}

/**
 * Calcule le plan de désactivation d'un topic à partir de TOUTES les lignes déjà chargées de son
 * scope (Hotfix 2.4.7, périmètre point 2 — AC4).
 *
 * Fonction pure (aucun accès DB ni API) : classe les lignes en deux listes actionnables par
 * l'appelant (`admin/webhooks.php`) :
 *  - `webhookIdsToDelete` : CHAQUE `webhook_id` non vide trouvé dans le scope, pas seulement celui
 *    de la ligne la mieux classée. Avant ce hotfix, la sauvegarde n'agissait que sur UNE ligne
 *    (`ORDER BY ... LIMIT 1`) : une ligne jumelle portant un `webhook_id` distinct et vivant
 *    survivait à une désactivation, et redevenait « la » ligne retenue par
 *    `doli2shopSelectPreferredWebhookRow()` au rechargement — le symptôme résiduel remonté par
 *    Nicolas Graillon après le hotfix 2.4.6 (case toujours cochée malgré « Configuration
 *    sauvegardée »).
 *  - `rowIdsToForceInactiveStatus` : rowid des lignes SANS `webhook_id` mais encore à `status = 1`
 *    (incohérence historique, ex. INSERT partiel). `ShopifyWebhooks::deleteWebhook()` boucle par
 *    `webhook_id` : il ne peut structurellement jamais toucher ces lignes. Sans ce correctif
 *    explicite, elles resteraient affichées « Actif » indéfiniment (périmètre point 4 : l'état
 *    local doit refléter l'action réalisée, pas seulement dépendre des effets de bord du DELETE).
 *
 * @param  object[] $rowsForTopic Lignes du scope (même topic/entity/filtre boutique), déjà
 *                                 chargées par l'appelant (cette fonction ne fait AUCUN filtrage).
 * @return array{webhookIdsToDelete: string[], rowIdsToForceInactiveStatus: int[]}
 * @since  2.4.7
 */
function doli2shopComputeWebhookDeactivationPlan(array $rowsForTopic): array
{
    $webhookIdsToDelete = array();
    $rowIdsToForceInactiveStatus = array();

    foreach ($rowsForTopic as $row) {
        $webhookId = isset($row->webhook_id) ? $row->webhook_id : null;

        if (doli2shopWebhookIdIsLive($webhookId)) {
            $webhookIdsToDelete[] = $webhookId;
            continue;
        }

        // Pas de webhook_id : deleteWebhook() ne pourra jamais atteindre cette ligne (il boucle
        // par webhook_id). Si elle porte encore status = 1, c'est une incohérence à corriger
        // explicitement plutôt qu'à laisser survivre indéfiniment.
        if (isset($row->status) && (int) $row->status === 1 && isset($row->rowid)) {
            $rowIdsToForceInactiveStatus[] = (int) $row->rowid;
        }
    }

    return array(
        'webhookIdsToDelete' => $webhookIdsToDelete,
        'rowIdsToForceInactiveStatus' => $rowIdsToForceInactiveStatus,
    );
}

/**
 * Indique si un chemin issu du manifeste sort du dossier du module (Story 2.4.7-4, AC4).
 *
 * Un manifeste légitime (produit par build/generate_manifest.php) ne contient jamais un tel
 * chemin — cette fonction couvre le cas d'un manifeste corrompu ou modifié à la main, pour que
 * `doli2shopVerifyPackageIntegrity()` puisse rejeter la ligne SANS jamais la résoudre sur le
 * disque (donc sans jamais risquer de sortir du dossier du module) ni lever d'exception.
 *
 * @param  string $relativePath Chemin relatif tel que lu dans le manifeste (non normalisé)
 * @return bool True si le chemin doit être rejeté (absolu, ou contenant un segment `..`)
 * @since  2.4.7
 */
function doli2shopManifestPathEscapesRoot($relativePath)
{
    $normalized = str_replace('\\', '/', (string) $relativePath);

    if ($normalized === '') {
        return true;
    }

    // Chemin absolu Unix (commence par '/') ou Windows (ex. "C:/...")
    if ($normalized[0] === '/' || preg_match('/^[A-Za-z]:\//', $normalized)) {
        return true;
    }

    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '..') {
            return true;
        }
    }

    return false;
}

/**
 * Compare le manifeste d'empreintes livré (build/manifest.md5) à l'état réel du disque
 * (Story 2.4.7-4 — dossier Nicolas Graillon : deux allers-retours passés à chercher un défaut de
 * code qui n'était en réalité pas déployé chez le client).
 *
 * Fonction PURE (décision Validate n°4) : reçoit le contenu brut du manifeste et un résolveur de
 * fichier INJECTABLE — jamais de `DOL_DOCUMENT_ROOT` codé en dur ici, sinon la fonction ne serait
 * pas testable sans un vrai paquet installé. `admin/diagnostic.php` construit le résolveur réel
 * (voir `doli2shopBuildManifestFileStateResolver()`) et se contente d'appeler cette fonction.
 *
 * Aucune exception ne remonte jamais d'ici : chaque cas de faute (manifeste absent/vide/malformé,
 * chemin sortant du dossier du module, fichier illisible) est absorbé et se traduit par un statut
 * lisible dans le rapport retourné — jamais par un throw.
 *
 * Vocabulaire délibéré (AC9) : un fichier dont l'empreinte diffère est répertorié comme
 * « modifié », jamais « corrompu » — un client qui a patché un fichier à la main, ou dont le
 * transfert FTP a converti les fins de ligne (mode ASCII), mérite un constat neutre, pas une
 * accusation. Le libellé et l'orientation vers la bonne cause restent la responsabilité de la
 * couche d'affichage (`admin/diagnostic.php`) ; ce rapport ne fournit que les faits.
 *
 * @param  string|null $manifestContent   Contenu brut de build/manifest.md5, ou null/'' si le
 *                                        fichier n'existe pas (installation antérieure à cette
 *                                        version, ou installation manuelle) — cas MAJORITAIRE
 *                                        pendant des mois (AC3).
 * @param  callable    $fileStateResolver function(string $relativePath): array{exists: bool,
 *                                        readable: bool, md5: string|null} — état réel, sur
 *                                        disque, du fichier désigné par un chemin relatif au
 *                                        dossier du module. Doit retourner exists=false pour tout
 *                                        ce qui n'est pas un fichier régulier (répertoire, etc.).
 * @return array{
 *     status: string,               'unverifiable'|'conform'|'altered'
 *     total: int,                   Nombre de lignes exploitables du manifeste
 *     conform: int,                 Fichiers dont l'empreinte correspond
 *     modified: string[],           Chemins relatifs dont l'empreinte diffère
 *     missing: string[],            Chemins relatifs absents du disque
 *     unreadable: string[],         Chemins relatifs présents mais illisibles (permission refusée)
 *     invalid_lines: int,           Lignes du manifeste ne respectant pas le format attendu
 *     rejected_paths: string[]      Chemins rejetés car sortant du dossier du module (AC4)
 * }
 * @since  2.4.7
 */
function doli2shopVerifyPackageIntegrity($manifestContent, callable $fileStateResolver)
{
    $report = array(
        'status' => 'unverifiable',
        'total' => 0,
        'conform' => 0,
        'modified' => array(),
        'missing' => array(),
        'unreadable' => array(),
        'invalid_lines' => 0,
        'rejected_paths' => array(),
    );

    // AC3 : manifeste absent (ou vide, ce qui revient au même en pratique — aucune ligne à
    // vérifier) => « non vérifiable », JAMAIS présenté comme une corruption.
    if ($manifestContent === null || trim((string) $manifestContent) === '') {
        return $report;
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) $manifestContent);
    $entries = array();
    $seenPaths = array();

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }

        // Format attendu (généré par build/generate_manifest.php) : "<md5 32 hex>  <chemin>"
        if (!preg_match('/^([a-fA-F0-9]{32})\s+(.+)$/', $line, $matches)) {
            $report['invalid_lines']++;
            continue;
        }

        $expectedMd5 = strtolower($matches[1]);
        $relativePath = trim($matches[2]);

        if ($relativePath === '') {
            $report['invalid_lines']++;
            continue;
        }

        // AC4 : chemin sortant du dossier du module — rejeté SANS être résolu sur le disque.
        if (doli2shopManifestPathEscapesRoot($relativePath)) {
            $report['rejected_paths'][] = $relativePath;
            continue;
        }

        // Revue 3 couches (MEDIUM) : un chemin présent DEUX FOIS dans le manifeste (édition à la
        // main, corruption) était compté deux fois — le même fichier pouvait alors apparaître à la
        // fois dans « conformes » et dans « modifiés », rendant le rapport incohérent. Un manifeste
        // sain n'a jamais de doublon (le générateur indexe par chemin) : une répétition est donc
        // une ligne à écarter, pas une entrée à vérifier deux fois.
        if (isset($seenPaths[$relativePath])) {
            $report['invalid_lines']++;
            continue;
        }
        $seenPaths[$relativePath] = true;

        $entries[] = array('path' => $relativePath, 'md5' => $expectedMd5);
    }

    // Manifeste présent mais sans aucune ligne exploitable (uniquement du vide, du malformé ou du
    // rejeté) : on n'a la preuve d'aucune altération, seulement l'absence de données utilisables —
    // reste « non vérifiable », jamais « corrompu ».
    if (empty($entries)) {
        return $report;
    }

    $report['total'] = count($entries);

    foreach ($entries as $entry) {
        try {
            $state = $fileStateResolver($entry['path']);
        } catch (\Throwable $e) {
            // Le résolveur ne doit jamais lever d'exception, mais si un cas imprévu (ex. erreur
            // de filesystem exotique) en produit une malgré tout, on la traite comme un fichier
            // illisible plutôt que de laisser l'exception remonter et casser tout le diagnostic.
            $report['unreadable'][] = $entry['path'];
            continue;
        }

        $exists = !empty($state['exists']);
        $readable = !empty($state['readable']);
        $actualMd5 = isset($state['md5']) ? $state['md5'] : null;

        if (!$exists) {
            $report['missing'][] = $entry['path'];
            continue;
        }

        if (!$readable || $actualMd5 === null || $actualMd5 === false) {
            $report['unreadable'][] = $entry['path'];
            continue;
        }

        if (strtolower((string) $actualMd5) === $entry['md5']) {
            $report['conform']++;
        } else {
            $report['modified'][] = $entry['path'];
        }
    }

    // Revue 3 couches (CRITICAL, trouvé indépendamment par le Blind Hunter ET l'Edge Case Hunter) :
    // le statut ne regardait QUE modified/missing/unreadable. Un manifeste lui-même abîmé — tronqué
    // en transit, BOM ajouté par un éditeur, ligne corrompue, chemin de traversée injecté — rendait
    // donc « conforme » dès lors que les fichiers des lignes ENCORE LISIBLES étaient présents. Or
    // les entrées perdues ne sont jamais vérifiées : l'outil censé détecter un déploiement partiel
    // rassurait à tort, sur son propre fichier de référence. Un manifeste partiellement inutilisable
    // n'est pas une preuve de conformité : c'est une couverture incomplète, et cela doit se voir.
    if (!empty($report['modified']) || !empty($report['missing']) || !empty($report['unreadable'])) {
        $report['status'] = 'altered';
    } elseif (!empty($report['invalid_lines']) || !empty($report['rejected_paths'])) {
        // Rien ne prouve une altération des fichiers : c'est le manifeste qui est inexploitable en
        // partie. Statut distinct, pour ne dire ni « tout va bien » ni « votre installation est
        // altérée » — les deux seraient faux.
        $report['status'] = 'partial';
    } else {
        $report['status'] = 'conform';
    }

    return $report;
}

/**
 * Construit le résolveur de fichier réel (disque) utilisé par `admin/diagnostic.php` pour
 * `doli2shopVerifyPackageIntegrity()` (Story 2.4.7-4).
 *
 * Isolé de toute constante globale (`DOL_DOCUMENT_ROOT`) : la racine est un PARAMÈTRE, jamais lue
 * en dur ici — c'est ce qui rend `doli2shopVerifyPackageIntegrity()` testable sans dépendre d'une
 * installation Dolibarr réelle. Cette fonction-ci, en revanche, n'est pas pure (accès disque) :
 * elle n'est délibérément pas couverte par les tests unitaires de la fonction de comparaison,
 * seulement exercée par la page de diagnostic elle-même.
 *
 * @param  string $moduleRootDir Chemin absolu du dossier du module installé (ex.
 *                               DOL_DOCUMENT_ROOT . '/custom/doli2shop')
 * @return callable function(string $relativePath): array{exists: bool, readable: bool, md5: string|null}
 * @since  2.4.7
 */
function doli2shopBuildManifestFileStateResolver($moduleRootDir)
{
    $root = rtrim((string) $moduleRootDir, '/');

    return function ($relativePath) use ($root) {
        $absolutePath = $root . '/' . $relativePath;

        if (!is_file($absolutePath)) {
            return array('exists' => false, 'readable' => false, 'md5' => null);
        }

        if (!is_readable($absolutePath)) {
            return array('exists' => true, 'readable' => false, 'md5' => null);
        }

        $md5 = @md5_file($absolutePath);
        if ($md5 === false) {
            return array('exists' => true, 'readable' => false, 'md5' => null);
        }

        return array('exists' => true, 'readable' => true, 'md5' => $md5);
    };
}

/**
 * Détecte une seconde copie du module sur le disque (Story 2.4.7-5 — dossier Nicolas Graillon).
 *
 * Un client bloqué a fait porter trois échanges sur une question à laquelle le module aurait dû
 * répondre seul : « quel dossier sert la page que je regarde ? ». `build_id` (empreinte du fichier
 * réellement exécuté) et `cron_builds` (jusque-là calculé sur un chemin codé en dur) mesuraient deux
 * dossiers DIFFÉRENTS sans jamais le dire — voir docs/troubleshooting/DEPLOIEMENT_PARTIEL_MODULE.md.
 *
 * Fonction PURE (même décision Validate que `doli2shopVerifyPackageIntegrity()`, Story 2.4.7-4) :
 * aucun accès disque direct, aucune constante Dolibarr — `admin/diagnostic.php` et
 * `admin/health.php` construisent le résolveur réel (voir `doli2shopBuildPathRealpathResolver()`)
 * et la liste des candidats, et se contentent d'appeler cette fonction.
 *
 * AC2 : la liste des candidats n'est PAS dédoublonnée par l'appelant — la clé `'main'` de
 * `$conf->file->dol_document_root` et le candidat explicite `DOL_DOCUMENT_ROOT/custom/doli2shop`
 * désignent le même chemin, et se retrouvent donc tous les deux dans `$candidatePaths`. Le
 * dédoublonnage se fait ICI, sur le chemin RÉSOLU par `$pathRealpathResolver` — jamais sur le
 * chemin brut, qui peut différer textuellement (barre oblique finale, casse du lecteur, etc.) pour
 * un seul et même dossier.
 *
 * AC3 : un candidat identique au dossier exécuté (même chemin, ou lien symbolique pointant vers le
 * même dossier réel une fois résolu) n'est jamais une copie secondaire — aucune alerte ne doit
 * naître d'une installation à copie unique, symlink ou non. `$executedDir` est supposé DÉJÀ résolu
 * (`realpath()`) par l'appelant : cette fonction ne fait que comparer des chaînes, elle ne résout
 * jamais elle-même le dossier exécuté.
 *
 * Revue 3 couches (CRITICAL) : le fallback historique, côté appelant, retombait sur le chemin BRUT
 * (non résolu) de `$executedDir` quand son propre `realpath()` échouait — alors que les candidats,
 * eux, sont TOUJOURS résolus. Sur une installation par lien symbolique (le cas exact que l'AC3
 * protège), la comparaison `===` échouait systématiquement et le module signalait sa PROPRE
 * installation comme copie fantôme. Un client suivant le conseil de suppression aurait détruit son
 * installation réelle. Cette fonction refuse maintenant de conclure quoi que ce soit quand
 * `$executedDir` est vide : elle renvoie le statut `undetermined`, jamais une alerte de copie
 * secondaire construite sur une comparaison qu'on sait invalide.
 *
 * MEDIUM (candidat illisible ≠ candidat absent) : `$pathRealpathResolver` peut aussi renvoyer
 * `false` — distinct de `null` — pour signaler « existe mais n'a pas pu être vérifié »
 * (permissions, `open_basedir` en hébergement mutualisé). Une seconde copie réellement présente
 * mais hors du jail PHP ne doit jamais disparaître en silence : elle est répertoriée à part, dans
 * `unverifiable_candidates`, plutôt que d'être confondue avec un emplacement simplement inutilisé.
 *
 * @param  string   $executedDir           Chemin RÉSOLU (realpath) du dossier du module réellement
 *                                         exécuté (déjà canonique — cette fonction ne le résout pas),
 *                                         ou chaîne vide si l'appelant n'a pas pu le résoudre : dans
 *                                         ce cas, aucune comparaison n'est tentée (statut
 *                                         `undetermined`).
 * @param  string[] $candidatePaths        Chemins bruts (non résolus, potentiellement en double) où
 *                                         Dolibarr peut héberger le module.
 * @param  callable $pathRealpathResolver  function(string $path): string|null|false — résout un
 *                                         chemin candidat en chemin canonique (encapsule
 *                                         `realpath()` + `is_dir()`) ; renvoie `null` si le chemin
 *                                         n'existe pas, ou `false` s'il existe mais n'a pas pu être
 *                                         vérifié (permissions, `open_basedir`). Jamais appelée sur
 *                                         `$executedDir`.
 * @return array{
 *     status: string,                    'undetermined'|'single_copy'|'secondary_copy_detected'
 *     secondary_copies: string[],        Chemins résolus des copies secondaires détectées (dédoublonnés)
 *     unverifiable_candidates: string[]  Chemins bruts des candidats existants mais non vérifiables
 * }
 * @since  2.4.7
 */
function doli2shopDetectSecondaryModuleCopy($executedDir, array $candidatePaths, callable $pathRealpathResolver)
{
    // CRITICAL : $executedDir non résolu => aucune conclusion possible. Comparer un chemin brut à
    // des candidats toujours résolus produirait de faux signalements (cas symlink de l'AC3).
    if ($executedDir === null || $executedDir === '') {
        return array(
            'status' => 'undetermined',
            'secondary_copies' => array(),
            'unverifiable_candidates' => array(),
        );
    }

    $secondaryCopies = array();
    $unverifiableCandidates = array();
    $seenResolvedPaths = array();

    foreach ($candidatePaths as $candidatePath) {
        $resolved = $pathRealpathResolver($candidatePath);

        // Candidat inexistant (emplacement alternatif non utilisé, etc.) : rien à signaler.
        if ($resolved === null || $resolved === '') {
            continue;
        }

        // MEDIUM : candidat présent mais non vérifiable (permissions, open_basedir) — distinct
        // d'un candidat inexistant. Jamais traité comme une copie secondaire (on ne sait pas s'il
        // en est une), jamais non plus passé sous silence.
        if ($resolved === false) {
            $unverifiableCandidates[] = $candidatePath;
            continue;
        }

        // AC2 : dédoublonnage sur le chemin résolu (ex. 'main' == DOL_DOCUMENT_ROOT/custom/doli2shop).
        if (isset($seenResolvedPaths[$resolved])) {
            continue;
        }
        $seenResolvedPaths[$resolved] = true;

        // AC3 : identique (ou symlink vers) le dossier exécuté => pas une copie secondaire.
        if ($resolved === $executedDir) {
            continue;
        }

        $secondaryCopies[] = $resolved;
    }

    return array(
        'status' => empty($secondaryCopies) ? 'single_copy' : 'secondary_copy_detected',
        'secondary_copies' => $secondaryCopies,
        'unverifiable_candidates' => $unverifiableCandidates,
    );
}

/**
 * Construit le résolveur réel (disque) utilisé par `admin/diagnostic.php` et `admin/health.php`
 * pour `doli2shopDetectSecondaryModuleCopy()` (Story 2.4.7-5).
 *
 * Isolé de tout accès disque direct dans la fonction pure : celle-ci reçoit ce résolveur en
 * paramètre, jamais `realpath()`/`is_dir()` en dur — c'est ce qui la rend testable sans dossier
 * réel sur disque. Cette fonction-ci, en revanche, n'est pas pure : elle n'est délibérément pas
 * couverte par les tests unitaires de la fonction de détection, seulement exercée par les pages de
 * diagnostic elles-mêmes.
 *
 * MEDIUM (candidat illisible ≠ candidat absent) : `realpath()`/`is_dir()` échouent de la même façon
 * que le chemin n'existe pas DU TOUT ou qu'il existe mais reste hors de portée du process PHP
 * (permissions refusées, `open_basedir` en hébergement mutualisé). Une seconde copie réellement
 * présente mais hors du jail PHP ne serait alors jamais signalée. On distingue donc les deux : si
 * `is_dir()` sur le chemin BRUT (non résolu) échoue aussi, le chemin n'existe vraiment pas (`null`) ;
 * s'il réussit alors que la résolution canonique a échoué, le chemin existe mais n'a pas pu être
 * vérifié (`false`).
 *
 * @return callable function(string $path): string|null|false — chemin canonique résolu ; `null` si
 *                   le chemin n'existe pas ; `false` s'il existe mais n'a pas pu être vérifié
 *                   (permissions, `open_basedir`).
 * @since  2.4.7
 */
function doli2shopBuildPathRealpathResolver()
{
    return function ($path) {
        $path = (string) $path;
        $resolved = @realpath($path);

        if ($resolved !== false && is_dir($resolved)) {
            return $resolved;
        }

        // La résolution canonique a échoué : reste à savoir si le chemin n'existe simplement pas,
        // ou s'il existe mais que PHP ne peut pas le vérifier complètement.
        if (!@is_dir($path)) {
            return null;
        }

        return false;
    };
}

/**
 * Construit la liste (dédoublonnée, filtrée) des emplacements où Dolibarr peut héberger le module,
 * pour `doli2shopDetectSecondaryModuleCopy()` (Story 2.4.7-5).
 *
 * Fonction PURE (aucun accès disque, aucune constante Dolibarr) : `admin/diagnostic.php` et
 * `admin/health.php` construisaient chacune, en copié-collé, la même liste — `DOL_DOCUMENT_ROOT` +
 * boucle sur `$conf->file->dol_document_root` — sans qu'aucun test ne la couvre. Extraite ici, elle
 * devient testable indépendamment des deux pages.
 *
 * Tolérance délibérée : `$documentRootConfig` peut être `null`, un tableau vide ou même une valeur
 * non-tableau — toutes les installations Dolibarr ne déclarent pas de racine alternative
 * (`dolibarr_main_document_root_alt` absent de `conf.php`). Toute entrée vide ou non-chaîne (y
 * compris le segment vide qu'un `dolibarr_main_document_root_alt` mal formé du type `';;'` produit
 * une fois éclaté) est ignorée plutôt que transformée en candidat `/custom/doli2shop` à la racine
 * du filesystem.
 *
 * @param  string      $documentRoot        Racine principale (DOL_DOCUMENT_ROOT).
 * @param  mixed       $documentRootConfig  `$conf->file->dol_document_root` (tableau clé 'main' +
 *                                          clés 'altN'), ou absent/vide/non-tableau.
 * @return string[] Chemins candidats bruts (non résolus), dédoublonnés.
 * @since  2.4.7
 */
function doli2shopBuildModuleLocationCandidates($documentRoot, $documentRootConfig)
{
    $candidates = array();

    $appendCandidate = function ($root) use (&$candidates) {
        if (!is_string($root)) {
            return;
        }

        $trimmedRoot = rtrim($root, '/');
        if ($trimmedRoot === '') {
            // Segment vide (racine manquante, ou séparateur `;;` mal formé) : ne produit PAS un
            // candidat '/custom/doli2shop' à la racine du filesystem.
            return;
        }

        $candidates[] = $trimmedRoot . '/custom/doli2shop';
    };

    $appendCandidate($documentRoot);

    if (is_array($documentRootConfig)) {
        foreach ($documentRootConfig as $altRoot) {
            $appendCandidate($altRoot);
        }
    }

    return array_values(array_unique($candidates));
}

/**
 * Résume l'inventaire des lignes de webhooks (Story 2.4.7-6 — extraction depuis
 * admin/diagnostic.php::checkWebhookRows(), section aussi portée sur admin/health.php).
 *
 * Fonction PURE (AC3) : reçoit les lignes déjà lues et ne fait que compter, détecter les topics
 * réellement ambigus et borner l'affichage — aucun accès disque ni base ici. La requête SQL reste
 * dans chaque page appelante, exactement comme `doli2shopBuildManifestFileStateResolver()` pour
 * `checkFileIntegrity()` : partager le RÉSULTAT, pas la lecture.
 *
 * Contrainte de nommage (AC3) : ne pas confondre avec `doli2shopComputeWebhookTopicState()` ni
 * `doli2shopSelectPreferredWebhookRow()`, qui outillent la DÉSACTIVATION des webhooks — cette
 * fonction sert l'INVENTAIRE affiché dans l'export de diagnostic, un usage différent.
 *
 * Revue 3 couches (HIGH, Story 2.4.7-6) : le regroupement se faisait sur `topic` SEUL, sans
 * `fk_store` — or une ligne par boutique et par topic est le fonctionnement NORMAL du
 * multi-boutiques (`class/shopifywebhooks.class.php` insère volontairement une ligne par
 * boutique). Toute installation à 2+ boutiques voyait donc chacun de ses topics signalé à tort.
 * Seules deux configurations sont réellement ambiguës :
 *  (a) plusieurs lignes portant le MÊME `fk_store` pour un topic (vrai doublon) ; ou
 *  (b) une ligne `fk_store = 0` (héritée d'avant le multi-boutiques, qui s'applique à TOUTE
 *      boutique) cohabitant avec au moins une ligne `fk_store > 0` pour ce même topic — c'est
 *      cette cohabitation qui a produit les défauts 2.4.6 et 2.4.7, pas la pluralité de boutiques.
 * Une ligne par boutique DISTINCTE, sans ligne `fk_store = 0`, n'est PAS un doublon.
 *
 * @param  object[] $rows          Lignes lues depuis llx_doli2shop_webhooks (stdClass avec au
 *                                 moins rowid, topic, fk_store, webhook_id, status), dans l'ordre
 *                                 de la requête (ORDER BY topic, fk_store, rowid).
 * @param  int      $maxRowsListed Plafond d'affichage — les deux pages utilisent 40, même
 *                                 convention que checkModuleLocation()/checkFileIntegrity(). Une
 *                                 valeur négative est ramenée à 0 (revue 3 couches, MEDIUM) : sans
 *                                 cette garde, `array_slice($rows, 0, -5)` renverrait « tout sauf
 *                                 les 5 derniers » au lieu de rien.
 * @return array{
 *     total: int,                                Nombre total de lignes
 *     duplicated_topics: array<string, int>,      Topic => nombre de lignes, UNIQUEMENT les topics
 *                                                  réellement ambigus (voir ci-dessus), borné à
 *                                                  $maxRowsListed comme le reste de l'affichage
 *     duplicated_topics_omitted: int,             Nombre de topics ambigus au-delà du plafond
 *     rows_listed: object[],                      Sous-ensemble borné des lignes reçues, dans
 *                                                  l'ordre reçu
 *     rows_omitted: int                           Nombre de lignes au-delà du plafond
 * }
 * @since  2.4.7
 */
function doli2shopSummarizeWebhookInventoryRows(array $rows, $maxRowsListed = 40)
{
    $maxRowsListed = max(0, (int) $maxRowsListed);

    // Regroupe par topic PUIS par fk_store au sein du topic (voir docblock ci-dessus pour le
    // détail des deux configurations réellement ambiguës).
    $topicFkStoreCounts = array(); // topic => [fk_store => nombre de lignes]
    $topicTotals = array(); // topic => nombre total de lignes (affiché quelle que soit l'ambiguïté)
    foreach ($rows as $row) {
        $topicKey = (string) $row->topic;
        $fkStore = (int) $row->fk_store;

        $topicTotals[$topicKey] = isset($topicTotals[$topicKey]) ? $topicTotals[$topicKey] + 1 : 1;

        if (!isset($topicFkStoreCounts[$topicKey])) {
            $topicFkStoreCounts[$topicKey] = array();
        }
        $topicFkStoreCounts[$topicKey][$fkStore] = isset($topicFkStoreCounts[$topicKey][$fkStore])
            ? $topicFkStoreCounts[$topicKey][$fkStore] + 1
            : 1;
    }

    $duplicatedTopics = array();
    foreach ($topicFkStoreCounts as $topicKey => $fkStoreCounts) {
        $hasRealDuplicate = false;
        foreach ($fkStoreCounts as $countForStore) {
            if ($countForStore > 1) {
                $hasRealDuplicate = true;
                break;
            }
        }

        // fk_store=0 présent ET au moins une autre valeur de fk_store présente pour ce topic.
        $hasLegacyCohabitation = isset($fkStoreCounts[0]) && count($fkStoreCounts) > 1;

        if ($hasRealDuplicate || $hasLegacyCohabitation) {
            $duplicatedTopics[$topicKey] = $topicTotals[$topicKey];
        }
    }

    // Finition (revue 3 couches, LOW) : même convention de troncature que le reste de la page.
    $duplicatedTopicsListed = array_slice($duplicatedTopics, 0, $maxRowsListed, true);
    $duplicatedTopicsOmitted = count($duplicatedTopics) - count($duplicatedTopicsListed);

    $rowsListed = array_slice($rows, 0, $maxRowsListed);
    $rowsOmitted = count($rows) - count($rowsListed);

    return array(
        'total' => count($rows),
        'duplicated_topics' => $duplicatedTopicsListed,
        'duplicated_topics_omitted' => $duplicatedTopicsOmitted,
        'rows_listed' => $rowsListed,
        'rows_omitted' => $rowsOmitted,
    );
}

/**
 * Construit le rapport « écart de totaux Shopify/Dolibarr » affiché par
 * admin/health.php::checkRecentSyncs() et son pendant admin/diagnostic.php (même section,
 * `DiagSectionSyncTitle` — Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal,
 * AC1/AC2/AC3).
 *
 * Fonction PURE (même pattern que doli2shopSummarizeWebhookInventoryRows() /
 * doli2shopResolveOAuthDomainConflict()) : aucun `$langs`, aucun `addCheck()`, aucun accès `$db` —
 * les deux pages admin exécutent la requête SQL (COUNT ... GROUP BY totals_mismatch_severity,
 * puis SELECT ref/severity des N plus récentes) et passent les faits bruts ici. C'est le SEUL
 * moyen d'avoir une preuve par test unitaire de ce diagnostic : le corps des deux pages admin
 * n'est pas testable unitairement (pas de bootstrap Dolibarr complet en test).
 *
 * AC3 : le statut remonté distingue explicitement l'écart d'arrondi (tolérable, quelques
 * centimes) de l'écart PROPORTIONNEL (un facteur constant sur le total, signature d'une erreur de
 * calcul systématique — cf. ShopifyOrderManager::classifyTotalsMismatch()) : la présence de
 * ne serait-ce qu'un seul écart proportionnel fait remonter le statut à 'error' (rouge), même si le
 * nombre d'écarts d'arrondi est nul par ailleurs — un écart proportionnel mérite une
 * investigation prioritaire, pas d'être noyé dans une moyenne.
 *
 * @param  int      $roundingCount      Nombre de commandes en écart 'rounding' (severity)
 * @param  int      $proportionalCount  Nombre de commandes en écart 'proportional' (severity)
 * @param  string[] $recentOrderRefs    Références des commandes concernées, PLUS RÉCENTES
 *                                      D'ABORD (ordre déjà trié par l'appelant, ex. `ORDER BY
 *                                      o.tms DESC`) — sert l'AC1 (« identification des commandes
 *                                      concernées »), pas seulement un compteur.
 * @param  int      $maxOrdersListed    Plafond d'affichage de la liste (même convention que
 *                                      doli2shopSummarizeWebhookInventoryRows(), négatif ramené à 0)
 * @return array{
 *     total: int,
 *     rounding: int,
 *     proportional: int,
 *     status: string,               'success' (aucun écart) | 'warning' (arrondi seul) |
 *                                    'error' (au moins un écart proportionnel)
 *     sample_refs: string[],        Sous-ensemble borné de $recentOrderRefs, dans l'ordre reçu
 *     sample_omitted: int           Nombre de références au-delà du plafond
 * }
 * @since  2.5.3
 */
function doli2shopBuildOrdersTotalsMismatchReport($roundingCount, $proportionalCount, array $recentOrderRefs = array(), $maxOrdersListed = 10)
{
    $roundingCount = max(0, (int) $roundingCount);
    $proportionalCount = max(0, (int) $proportionalCount);
    $maxOrdersListed = max(0, (int) $maxOrdersListed);

    if ($proportionalCount > 0) {
        $status = 'error';
    } elseif ($roundingCount > 0) {
        $status = 'warning';
    } else {
        $status = 'success';
    }

    $sampleRefs = array_slice($recentOrderRefs, 0, $maxOrdersListed);
    $sampleOmitted = count($recentOrderRefs) - count($sampleRefs);

    return array(
        'total' => $roundingCount + $proportionalCount,
        'rounding' => $roundingCount,
        'proportional' => $proportionalCount,
        'status' => $status,
        'sample_refs' => $sampleRefs,
        'sample_omitted' => $sampleOmitted,
    );
}

/**
 * Construit le rapport de non-appariés SKU (variantes/déclinaisons) pour l'écran de diagnostic —
 * fonction PURE (aucun $db, aucun $langs, aucun addCheck()), appelée TELLE QUELLE par
 * admin/health.php ET admin/diagnostic.php (checkRecentSyncs()) : c'est la SEULE partie testable
 * unitairement de ces deux pages (pas de bootstrap Dolibarr complet en test) — même motif que
 * doli2shopBuildOrdersTotalsMismatchReport() ci-dessus.
 *
 * Story variante-sans-correspondance-sku-ignoree-en-silence (AC1/AC2/AC3) :
 * `ImportProducts::updateVariantInventory()` appariait les déclinaisons Dolibarr aux variantes
 * Shopify uniquement par SKU, mais un `if` sans `else` avalait en silence toute
 * déclinaison/variante sans correspondance (0 log, 0 remontée écran) — signalé en production par
 * une cliente dont le stock d'une déclinaison restait figé à 0 malgré un diagnostic à "0 erreur".
 * Le compte de non-appariés (les deux sens) doit désormais remonter à l'écran, pas seulement au
 * journal.
 *
 * @param  int      $dolMismatchCount      Somme des `variant_mismatch_dol_count` en base
 *                                         (déclinaisons Dolibarr sans variante Shopify)
 * @param  int      $shopifyMismatchCount  Somme des `variant_mismatch_shopify_count` en base
 *                                         (variantes Shopify sans déclinaison Dolibarr)
 * @param  string[] $recentProductRefs     Références des produits concernés, PLUS RÉCEMMENT
 *                                         calculés d'abord (déjà triés par l'appelant, ex.
 *                                         `ORDER BY variant_mismatch_tms DESC`) — sert
 *                                         l'identification des produits concernés, pas seulement
 *                                         un compteur.
 * @param  int      $maxProductsListed     Plafond d'affichage de la liste (même convention que
 *                                         doli2shopBuildOrdersTotalsMismatchReport(), négatif
 *                                         ramené à 0)
 * @return array{
 *     total: int,
 *     dol: int,
 *     shopify: int,
 *     status: string,               'success' (aucun non-apparié) | 'error' (au moins un, des
 *                                    deux côtés — un stock figé à tort n'est jamais un simple
 *                                    avertissement, cf. story)
 *     sample_refs: string[],        Sous-ensemble borné de $recentProductRefs, dans l'ordre reçu
 *     sample_omitted: int           Nombre de références au-delà du plafond
 * }
 * @since  2.5.5
 */
function doli2shopBuildVariantMismatchReport($dolMismatchCount, $shopifyMismatchCount, array $recentProductRefs = array(), $maxProductsListed = 10)
{
    $dolMismatchCount = max(0, (int) $dolMismatchCount);
    $shopifyMismatchCount = max(0, (int) $shopifyMismatchCount);
    $maxProductsListed = max(0, (int) $maxProductsListed);

    $total = $dolMismatchCount + $shopifyMismatchCount;
    $status = ($total > 0) ? 'error' : 'success';

    $sampleRefs = array_slice($recentProductRefs, 0, $maxProductsListed);
    $sampleOmitted = count($recentProductRefs) - count($sampleRefs);

    return array(
        'total' => $total,
        'dol' => $dolMismatchCount,
        'shopify' => $shopifyMismatchCount,
        'status' => $status,
        'sample_refs' => $sampleRefs,
        'sample_omitted' => $sampleOmitted,
    );
}

/**
 * Détermine la CAUSE du diagnostic photos produit à partir des faits mesurés — jamais un
 * verdict « échec » générique (Story 57-4, AC1/AC1bis/AC2).
 *
 * Fonction PURE (pattern `doli2shopResolveOAuthDomainConflict()`) : aucun `$langs`, aucun
 * `addCheck()` — `admin/diagnostic.php::checkProductPhotoSync()` et son pendant
 * `admin/health.php` appellent cette fonction avec les faits bruts (sortie de
 * `DolibarrDirectFileResolver::describeProductPhotosDir()` + comptages), puis traduisent
 * `cause` via i18n. C'est le SEUL moyen d'avoir une preuve par test unitaire de ce diagnostic :
 * le corps des deux pages admin, lui, n'est pas testable unitairement (pas de bootstrap Dolibarr
 * complet en test).
 *
 * Ordre de priorité des causes (la première condition vraie l'emporte) :
 * 0. FILET DE SÉCURITÉ (FIX-CRITICAL, code review 57-4) — `listedImagesCount > 0` l'emporte
 *    TOUJOURS sur `dir_not_found`/`dir_not_readable`, quel que soit l'état rapporté par
 *    `$dirDescribe`. Une lecture réelle réussie (au moins une image effectivement listée par
 *    `listProductImages()`) est la preuve la plus forte possible que le chemin fonctionne ;
 *    l'état du répertoire n'est alors qu'une information de contexte, jamais un verdict
 *    contraire. Défense en profondeur : `describeProductPhotosDir()` itère désormais sur tous
 *    les candidats et ne devrait plus produire cette contradiction en pratique, mais cette
 *    fonction reste la SEULE testable unitairement pour le prouver — ne jamais retirer ce garde.
 * 1. `dir_unresolved`     — `describeProductPhotosDir()['path']` est null (multidir_output non
 *                           résolu : vide, non-string, ou préfixé `error-`).
 * 2. `dir_not_found`      — chemin résolu mais répertoire absent du disque.
 * 3. `dir_not_readable`   — répertoire existant mais non lisible (permissions, mutualisé).
 * 4. `file_missing_on_disk` — au moins une ligne `llx_ecm_files` indexée pour ce produit, mais
 *                           AUCUNE image effectivement listée (désynchronisation base/disque).
 * 5. `ok`                 — au moins une image listée : le chemin réellement emprunté par le
 *                           module fonctionne de bout en bout.
 * 6. `no_indexed_images`  — répertoire accessible, mais aucune ligne `llx_ecm_files` pour ce
 *                           produit (cas normalement inatteignable en pratique : l'appelant
 *                           sélectionne un produit connu pour avoir au moins une ligne indexée
 *                           — conservé par sûreté, jamais un crash sur un compte à 0).
 *
 * `entityMismatch` est calculé INDÉPENDAMMENT de la cause ci-dessus (il ne la remplace ni ne la
 * bloque) : `describeProductPhotosDir()`/`listProductImages()` résolvent déjà correctement via
 * `$product->entity` (invariant FIX-HIGH-2 de 57-1) — un produit d'une autre entité que
 * `$conf->entity` (multi-company, entités partagées) n'est donc pas une erreur en soi, mais reste
 * un fait à signaler explicitement à l'écran (transparence sur le produit-témoin sélectionné).
 *
 * @param  array{path:?string,exists:bool,readable:bool,reason:?string} $dirDescribe Sortie de
 *                              `DolibarrDirectFileResolver::describeProductPhotosDir()`
 * @param  int      $ecmFilesRowCount  Sortie de `countIndexedProductImages()` — lignes indexées,
 *                              indépendamment de leur présence disque
 * @param  int      $listedImagesCount Sortie de `count(listProductImages())` — images réellement
 *                              lisibles sur disque
 * @param  int|null $productEntity  `$product->entity`, ou null si non renseignée
 * @param  int      $currentEntity  `$conf->entity` au moment du diagnostic
 * @return array{status:string,cause:string,entityMismatch:bool} `status` ∈ success|warning|error
 *                              (vocabulaire `addCheck()`, cf. admin/diagnostic.php::addCheck()) ;
 *                              `cause` = code stable pour traduction i18n (jamais affiché brut) ;
 *                              `entityMismatch` = true si `$productEntity` diffère de
 *                              `$currentEntity` (null = non renseignée, jamais considéré différent)
 * @since  2.5.0
 */
function doli2shopResolveProductPhotoDiagnosticCause(
    array $dirDescribe,
    int $ecmFilesRowCount,
    int $listedImagesCount,
    ?int $productEntity,
    int $currentEntity
): array {
    $entityMismatch = ($productEntity !== null && $productEntity !== $currentEntity);

    if ($listedImagesCount > 0) {
        // FIX-CRITICAL (code review 57-4) : filet de sécurité — une lecture réelle réussie ne
        // peut jamais être un défaut de répertoire. Ne JAMAIS retirer ce garde, cf. docblock.
        $status = 'success';
        $cause = 'ok';
    } elseif ($dirDescribe['path'] === null) {
        $status = 'error';
        $cause = 'dir_unresolved';
    } elseif (!$dirDescribe['exists']) {
        $status = 'error';
        $cause = 'dir_not_found';
    } elseif (!$dirDescribe['readable']) {
        $status = 'error';
        $cause = 'dir_not_readable';
    } elseif ($ecmFilesRowCount > 0 && $listedImagesCount === 0) {
        $status = 'error';
        $cause = 'file_missing_on_disk';
    } else {
        // $listedImagesCount === 0 ici (déjà traité par le filet de sécurité ci-dessus sinon).
        $status = 'warning';
        $cause = 'no_indexed_images';
    }

    return array('status' => $status, 'cause' => $cause, 'entityMismatch' => $entityMismatch);
}

/**
 * Pendant catégorie de {@see doli2shopResolveProductPhotoDiagnosticCause()} — Story 57-4 (AC3).
 *
 * Différences ASSUMÉES avec le volet produit (asymétrie déjà documentée dans
 * `DolibarrDirectFileResolver` — 57-7) : les photos de catégorie ne sont JAMAIS indexées dans
 * `llx_ecm_files` (`Categorie::add_photo()` n'appelle jamais `addFileIntoDatabaseIndex()`), donc
 * il n'existe PAS de cause `file_missing_on_disk` symétrique — le disque EST la seule source de
 * vérité, il ne peut pas diverger d'un index qui n'existe pas.
 *
 * Ordre de priorité des causes :
 * 0. FILET DE SÉCURITÉ (FIX-CRITICAL, code review 57-4) — `listedImagesCount > 0` l'emporte
 *    TOUJOURS sur `dir_not_found`/`dir_not_readable`, symétrique au filet du volet produit
 *    (@see doli2shopResolveProductPhotoDiagnosticCause()). Ne jamais retirer ce garde.
 * 1. `dir_unresolved`   — `describeCategoryPhotosDir()['path']` est null (catégorie sans id, ou
 *                         multidir_output non résolu).
 * 2. `dir_not_found`    — chemin résolu mais répertoire absent du disque.
 * 3. `dir_not_readable` — répertoire existant mais non lisible.
 * 4. `ok`               — au moins une image listée.
 * 5. `no_images`        — répertoire accessible, aucune image trouvée (catégorie sans photo —
 *                         cas normal, jamais un défaut du module).
 *
 * @param  array{path:?string,exists:bool,readable:bool,reason:?string} $dirDescribe Sortie de
 *                              `DolibarrDirectFileResolver::describeCategoryPhotosDir()`
 * @param  int      $listedImagesCount Sortie de `count(listCategoryImages())`
 * @param  int|null $categoryEntity `$category->entity`, ou null si non renseignée
 * @param  int      $currentEntity  `$conf->entity` au moment du diagnostic
 * @return array{status:string,cause:string,entityMismatch:bool}
 * @since  2.5.0
 */
function doli2shopResolveCategoryPhotoDiagnosticCause(
    array $dirDescribe,
    int $listedImagesCount,
    ?int $categoryEntity,
    int $currentEntity
): array {
    $entityMismatch = ($categoryEntity !== null && $categoryEntity !== $currentEntity);

    if ($listedImagesCount > 0) {
        // FIX-CRITICAL (code review 57-4) : filet de sécurité — une lecture réelle réussie ne
        // peut jamais être un défaut de répertoire. Ne JAMAIS retirer ce garde, cf. docblock.
        $status = 'success';
        $cause = 'ok';
    } elseif ($dirDescribe['path'] === null) {
        $status = 'error';
        $cause = 'dir_unresolved';
    } elseif (!$dirDescribe['exists']) {
        $status = 'error';
        $cause = 'dir_not_found';
    } elseif (!$dirDescribe['readable']) {
        $status = 'error';
        $cause = 'dir_not_readable';
    } else {
        $status = 'warning';
        $cause = 'no_images';
    }

    return array('status' => $status, 'cause' => $cause, 'entityMismatch' => $entityMismatch);
}

/**
 * Calcule le réalignement de DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE quand la date de début
 * (`historical_import_start_date`) est modifiée alors que l'import historique est DÉJÀ activé
 * (Story 63-18, AC1/AC2).
 *
 * Avant cette story, la copie START_DATE -> RESUME_DATE n'existait QUE sur la transition
 * désactivé -> activé (`admin/setup.php`) : un utilisateur qui corrigeait la date de début pendant
 * que l'import était déjà en cours ne voyait donc aucun effet, alors que l'aide du champ affirmait
 * l'inverse. Cette fonction est PURE (aucune lecture/écriture de constante, aucune dépendance
 * Dolibarr) pour rester testable directement : `admin/setup.php` se contente d'appliquer la
 * décision qu'elle retourne.
 *
 * @param  string $newStartDate      Nouvelle valeur postée pour historical_import_start_date (peut être vide)
 * @param  string $oldStartDate      Valeur actuellement stockée dans DOLI2SHOP_HISTORICAL_IMPORT_START_DATE
 * @param  string $currentResumeDate Valeur actuelle de DOLI2SHOP_HISTORICAL_IMPORT_RESUME_DATE (peut être vide)
 * @return array{shouldRealign:bool,clearResumeDate:bool,newResumeDate:?string,warnAdvanced:bool}
 *              shouldRealign   true si START_DATE a changé et qu'un réalignement est nécessaire
 *              clearResumeDate true si RESUME_DATE doit être effacée (nouvelle date de début vide)
 *              newResumeDate   nouvelle valeur de RESUME_DATE à écrire (null si clearResumeDate)
 *              warnAdvanced    true si le point de reprise ACTUEL est plus ancien que la nouvelle
 *                              date de début : le réalignement va donc AVANCER le point de reprise
 *                              et sauter des commandes intermédiaires — à signaler explicitement à
 *                              l'utilisateur (AC1), jamais en silence
 * @since  2.5.2
 */
function doli2shopComputeHistoricalResumeRealignment(string $newStartDate, string $oldStartDate, string $currentResumeDate): array
{
    $result = array(
        'shouldRealign' => false,
        'clearResumeDate' => false,
        'newResumeDate' => null,
        'warnAdvanced' => false,
    );

    if ($newStartDate === $oldStartDate) {
        // Aucun changement de START_DATE : rien à réaligner (évite de ré-écrire RESUME_DATE à
        // chaque submit de l'onglet orders alors que la date de début n'a pas bougé).
        return $result;
    }

    $result['shouldRealign'] = true;

    if (empty($newStartDate)) {
        $result['clearResumeDate'] = true;
        return $result;
    }

    $result['newResumeDate'] = $newStartDate;

    // Comparaison lexicographique volontaire : les deux valeurs sont des dates ISO (YYYY-MM-DD
    // ou YYYY-MM-DDTHH:MM:SSZ), pour lesquelles l'ordre lexicographique == l'ordre chronologique.
    // Review 3 couches du 24/08 (LOW) : cette hypothèse n'était pas vérifiée — une valeur non ISO
    // (saisie corrompue, ancien format) produirait une comparaison lexicographique incohérente
    // sans jamais planter, mais avec un `warnAdvanced` potentiellement faux. On valide donc le
    // format des DEUX valeurs avant de comparer ; une valeur qui ne ressemble pas à une date ISO
    // désactive l'avertissement plutôt que de le fonder sur une comparaison qui n'a pas de sens
    // (mieux vaut ne pas avertir que d'avertir à tort sur une donnée illisible).
    $looksLikeIsoDate = static function (string $value): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}:\d{2})?/', $value);
    };

    if (
        !empty($currentResumeDate)
        && $looksLikeIsoDate($currentResumeDate)
        && $looksLikeIsoDate($newStartDate)
        && $currentResumeDate < $newStartDate
    ) {
        $result['warnAdvanced'] = true;
    }

    return $result;
}

/**
 * Détermine si une passe du CRON d'import historique a épuisé la plage de commandes configurée
 * (Story 63-18, AC4/AC5) : complet si le plafond par exécution (`$maxImportsPerRun`) n'a PAS été
 * atteint ET qu'il n'y a plus de page suivante côté Shopify (`pageInfo.hasNextPage`). Fonction
 * PURE (aucune lecture/écriture de constante, aucun appel réseau) pour rester testable sans
 * dépendances Dolibarr/Shopify — `ShopifyHistoricalImportCron::executeCron()` se contente
 * d'appliquer la décision qu'elle retourne (écrit COMPLETED + COMPLETED_DATE si true).
 *
 * Décision retenue (arbitrage Dev, AC4) : `hasNextPage == false` sur une passe qui n'a PAS été
 * interrompue par le plafond. Une passe interrompue par le plafond (`imported >= maxImportsPerRun`)
 * n'est jamais complète, même si `hasNextPage` devient false par ailleurs — le point de reprise a
 * seulement avancé, pas fini. Cas limite assumé : si le plafond est atteint EXACTEMENT sur la
 * dernière commande disponible (plus aucune ensuite), cette passe rapporte "non complet" ; la passe
 * SUIVANTE, qui ne trouvera plus aucune commande, le détectera. Complétion retardée d'une passe,
 * jamais fausse — préférable à un TOTAL_COUNT ou une complétion inventés.
 *
 * Amendement — review 3 couches du 24/08 (HIGH) : `$failedThisPass` entre désormais dans la
 * décision. Avant cet amendement, une passe où 100% des commandes traitées échouaient
 * (`processSingleOrderFromWebhook()` en erreur pour chacune) et dont la pagination s'épuisait
 * (`hasNextPage=false`) était rapportée "complète" — `imported=0 < maxImportsPerRun` et
 * `!hasNextPage` suffisaient, sans regarder si ce zéro venait d'une plage vide ou d'échecs. Une
 * passe qui n'a QUE des échecs n'est pas une passe réussie : `$failedThisPass > 0` bloque
 * désormais la complétion, quel que soit le reste.
 *
 * @param  int  $importedThisPass Nombre de commandes réellement importées durant cette passe
 * @param  int  $maxImportsPerRun Plafond configuré par passe (DOLI2SHOP_HISTORICAL_MAX_PER_RUN)
 * @param  bool $hasNextPage      Dernier pageInfo.hasNextPage retourné par Shopify pour cette passe
 * @param  int  $failedThisPass   Nombre de commandes dont le traitement a échoué durant cette passe
 *                                 (défaut 0, rétrocompatible avec les appels antérieurs à l'amendement)
 * @return bool
 * @since  2.5.2
 */
function doli2shopIsHistoricalImportPassComplete(int $importedThisPass, int $maxImportsPerRun, bool $hasNextPage, int $failedThisPass = 0): bool
{
    return ($importedThisPass < $maxImportsPerRun) && !$hasNextPage && ($failedThisPass === 0);
}

/**
 * Décide de l'action à appliquer aux constantes de complétion de l'import historique
 * (DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED / _COMPLETED_DATE) à la fin d'une passe du CRON.
 *
 * Ajoutée par la review 3 couches du 24/08 (CRITICAL). Chaîne du défaut corrigé ici : sur un 401
 * persistant après refresh+retry, ou sur toute erreur GraphQL autre que "Throttled", ou sur le
 * throttle épuisé après ses relances, `ShopifyApi::executeGraphQL()` ne lève AUCUNE exception —
 * elle renvoie la réponse brute (`$decoded`), en erreur. `ShopifyOrderManager::fetchHistoricalOrdersBatch()`
 * redescendait cette réponse en erreur avec EXACTEMENT la même signature qu'une plage réellement
 * épuisée (`orders => [], hasNextPage => false`) — `doli2shopIsHistoricalImportPassComplete()`
 * concluait donc à tort à la complétion (`imported=0 < plafond`, `!hasNextPage`), et
 * `ShopifyHistoricalImportCleanupCron` désactivait ensuite le CRON d'import sur un import qui
 * n'avait RIEN importé. Le correctif : `fetchHistoricalOrdersBatch()` distingue maintenant les deux
 * cas (clé `'error' => true` du tableau retourné, cf. Completion Notes) — cette distinction devient
 * le premier paramètre ici, `$batchHadError`, qui prime sur tout le reste.
 *
 * Fonction PURE : ne lit ni n'écrit aucune constante, ne fait aucun appel réseau/DB. Le CRON se
 * contente d'appliquer l'action retournée. Trois issues possibles :
 *  - 'complete'   : écrire COMPLETED=1 (COMPLETED_DATE seulement si pas déjà complet avant)
 *  - 'reset'      : écrire COMPLETED=0 + COMPLETED_DATE=0 — du travail est réapparu après une
 *                    complétion antérieure (comportement Task 3 préexistant, conservé à l'identique)
 *  - 'no_change'  : NE RIEN ÉCRIRE — soit la réponse de cette passe est INEXPLOITABLE
 *                    (`$batchHadError === true` : on ne sait ni si la plage est épuisée ni si elle
 *                    contient encore des commandes — écrire 1 serait aussi arbitraire qu'écrire 0),
 *                    soit la passe n'est simplement pas complète et ne l'était pas non plus avant.
 *                    Volontaire : sur erreur, on ne réinitialise PAS non plus un état "complet"
 *                    antérieur — cette passe n'apporte aucune preuve qu'il ait changé, la prochaine
 *                    passe qui réussit tranchera.
 *
 * @param  bool $batchHadError      true si fetchHistoricalOrdersBatch() a signalé une réponse API
 *                                   inexploitable pour cette passe (401 persistant, throttle épuisé,
 *                                   erreur GraphQL, garde "aucun filtre") — jamais une plage vide légitime.
 * @param  int  $importedThisPass   Commandes réellement importées durant cette passe.
 * @param  int  $failedThisPass     Commandes dont le traitement a échoué durant cette passe.
 * @param  int  $maxImportsPerRun   Plafond configuré par passe.
 * @param  bool $hasNextPage        Dernier pageInfo.hasNextPage retourné par Shopify pour cette passe.
 * @param  bool $wasAlreadyCompleted État actuel de DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED avant cette passe.
 * @return string 'complete' | 'reset' | 'no_change'
 * @since  2.5.2
 */
function doli2shopDecideHistoricalImportCompletionAction(
    bool $batchHadError,
    int $importedThisPass,
    int $failedThisPass,
    int $maxImportsPerRun,
    bool $hasNextPage,
    bool $wasAlreadyCompleted
): string {
    if ($batchHadError) {
        return 'no_change';
    }

    if (doli2shopIsHistoricalImportPassComplete($importedThisPass, $maxImportsPerRun, $hasNextPage, $failedThisPass)) {
        return 'complete';
    }

    if ($wasAlreadyCompleted) {
        return 'reset';
    }

    return 'no_change';
}

/**
 * Valide le FORMAT d'un domaine Shopify (`X-Shopify-Shop-Domain`) avant qu'il n'entre dans
 * toute requête SQL (AC6a de la story webhooks-multicompany-sans-resolution-d-entite).
 *
 * Normalise en minuscules et vérifie le motif `xxx.myshopify.com` (hostname nu, jamais de
 * schéma ni de chemin — même forme que celle écrite par `admin/oauth_receive.php:164` dans
 * `DOLI2SHOP_STORE_HOSTNAME`, cf. Validate 05/09 : pas de piège de normalisation entre les
 * deux sources).
 *
 * @param  string $shopDomain Valeur brute de l'en-tête HTTP (non fiable, non signée)
 * @return string|false Domaine normalisé (minuscules) si le format est valide, false sinon
 * @since  2.5.3
 */
function doli2shopValidateShopDomainFormat($shopDomain)
{
    $normalized = strtolower(trim((string) $shopDomain));
    if (!preg_match('/^[a-z0-9][a-z0-9.-]*\.myshopify\.com$/', $normalized)) {
        return false;
    }
    return $normalized;
}

/**
 * Résout l'ENTITÉ Dolibarr qui porte la configuration d'une boutique Shopify donnée, à partir
 * de son `shop_domain` — sans filtrer par l'entité courante (c'est tout son objet).
 *
 * Story webhooks-multicompany-sans-resolution-d-entite (AC1/AC2/AC3/AC4/AC6). Contexte : sous
 * Multicompany, `webhooks/index.php` s'exécute toujours en entité 1 (`NOLOGIN` saute la
 * résolution d'entité du cœur, `main.inc.php:500`) — un webhook pour une boutique configurée
 * sur une autre entité ne trouve donc jamais son secret HMAC ni ses identifiants Shopify.
 *
 * Ordre de résolution (AC2) :
 * 1. `llx_doli2shop_stores` (`shop_domain` → `entity`, toutes les boutiques depuis la 2.3.0) ;
 * 2. repli `llx_const` (`DOLI2SHOP_STORE_HOSTNAME`) pour les installations mono-boutique
 *    antérieures à la 2.3.0, qui n'ont jamais de ligne dans `llx_doli2shop_stores`.
 *
 * Isolation multi-tenant : cette requête est DÉLIBÉRÉMENT la seule du module à ne pas filtrer
 * par `entity` — c'est son objet (retrouver l'entité elle-même). Elle ne casse pas
 * l'isolation : elle sélectionne UNIQUEMENT la colonne `entity` (AC6d), jamais les jetons
 * d'accès, jamais aucune autre donnée de configuration ou métier — elle indique quelle
 * configuration existe pour ce domaine, elle n'en expose aucun contenu.
 *
 * Ambiguïté (AC3) : si le même domaine est déclaré sur plusieurs entités, retour explicite
 * 'ambiguous' — jamais de choix implicite (écrire dans la mauvaise société est pire qu'un
 * refus, Shopify retentera le webhook).
 *
 * Coût (AC5/AC6c) : n'exécute AUCUNE requête si Multicompany n'est pas actif — une install
 * mono-entité n'a par construction qu'une seule entité candidate, la question ne se pose pas.
 *
 * @param  DoliDB $db         Connexion base de données
 * @param  string $shopDomain Valeur brute de l'en-tête X-Shopify-Shop-Domain (non validée)
 * @return array{status: string, entity: int|null, entities: int[], source: string|null}
 *              status = 'disabled' (Multicompany inactif, pas de résolution nécessaire)
 *                     | 'invalid_format' (en-tête ne respecte pas le motif attendu)
 *                     | 'unknown' (aucune correspondance dans stores ni const)
 *                     | 'resolved' (une seule entité trouvée — `entity` renseigné)
 *                     | 'ambiguous' (plusieurs entités trouvées — `entities` renseigné)
 * @since  2.5.3
 */
function doli2shopResolveEntityForShopDomain($db, $shopDomain)
{
    // AC6(c) : la résolution n'est active que sous Multicompany. Hors Multicompany, il n'existe
    // qu'une seule entité possible : aucune requête supplémentaire, aucun coût (AC5).
    if (!isModEnabled('multicompany')) {
        return array('status' => 'disabled', 'entity' => null, 'entities' => array(), 'source' => null);
    }

    // AC6(a) : l'en-tête attaquant-contrôlé est validé de FORME avant d'entrer dans une requête.
    $normalizedDomain = doli2shopValidateShopDomainFormat($shopDomain);
    if ($normalizedDomain === false) {
        return array('status' => 'invalid_format', 'entity' => null, 'entities' => array(), 'source' => null);
    }

    $escapedDomain = $db->escape($normalizedDomain);

    // 1. Source prioritaire : llx_doli2shop_stores (multi-boutiques depuis 2.3.0).
    // AC6(d) : SEULE la colonne entity est sélectionnée — jamais access_token/api_secret/etc.
    $sql = 'SELECT DISTINCT entity FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
    $sql .= " WHERE LOWER(shop_domain) = '" . $escapedDomain . "'";
    $sql .= ' AND entity >= 1';
    $sql .= ' ORDER BY entity ASC';

    $storeEntities = array();
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $storeEntities[] = (int) $obj->entity;
        }
        $db->free($resql);
    } else {
        dol_syslog('doli2shopResolveEntityForShopDomain - erreur requête llx_doli2shop_stores: ' . $db->lasterror(), LOG_ERR);
    }

    if (count($storeEntities) > 1) {
        return array('status' => 'ambiguous', 'entity' => null, 'entities' => $storeEntities, 'source' => 'stores');
    }
    if (count($storeEntities) === 1) {
        return array('status' => 'resolved', 'entity' => $storeEntities[0], 'entities' => $storeEntities, 'source' => 'stores');
    }

    // 2. Repli : llx_const DOLI2SHOP_STORE_HOSTNAME (installations mono-boutique < 2.3.0, jamais
    // migrées vers llx_doli2shop_stores).
    $sqlConst = 'SELECT DISTINCT entity FROM ' . MAIN_DB_PREFIX . 'const';
    $sqlConst .= " WHERE name = 'DOLI2SHOP_STORE_HOSTNAME'";
    $sqlConst .= " AND LOWER(value) = '" . $escapedDomain . "'";
    $sqlConst .= ' AND entity >= 1';
    $sqlConst .= ' ORDER BY entity ASC';

    $constEntities = array();
    $resqlConst = $db->query($sqlConst);
    if ($resqlConst) {
        while ($obj = $db->fetch_object($resqlConst)) {
            $constEntities[] = (int) $obj->entity;
        }
        $db->free($resqlConst);
    } else {
        dol_syslog('doli2shopResolveEntityForShopDomain - erreur requête llx_const: ' . $db->lasterror(), LOG_ERR);
    }

    if (count($constEntities) > 1) {
        return array('status' => 'ambiguous', 'entity' => null, 'entities' => $constEntities, 'source' => 'const');
    }
    if (count($constEntities) === 1) {
        return array('status' => 'resolved', 'entity' => $constEntities[0], 'entities' => $constEntities, 'source' => 'const');
    }

    // AC4 : aucune correspondance — comportement historique conservé par l'appelant (entité
    // courante), simplement signalé ici pour être journalisé.
    return array('status' => 'unknown', 'entity' => null, 'entities' => array(), 'source' => null);
}

/**
 * Recense, pour l'écran de diagnostic (à brancher séparément — hors périmètre de cette
 * fonction), tous les `shop_domain` déclarés sur PLUSIEURS entités (ambiguïté au sens de
 * doli2shopResolveEntityForShopDomain(), AC3).
 *
 * Un refus d'ambiguïté qui ne vit qu'en journal applicatif finit par faire supprimer
 * l'abonnement webhook par Shopify après échecs répétés, sans que personne le voie : cette
 * fonction donne à un écran d'administration de quoi afficher l'alerte de façon persistante.
 *
 * Balaie les deux sources (`llx_doli2shop_stores` ET le repli `llx_const`) indépendamment :
 * une ambiguïté dans `llx_doli2shop_stores` pour un domaine donné n'empêche pas d'en détecter
 * une autre, distincte, dans `llx_const` pour un domaine différent. Si `llx_doli2shop_stores`
 * porte déjà (au moins) une ligne pour un domaine, ce domaine prime dans le résultat (même
 * logique de priorité que la résolution elle-même) — la clé `llx_const` correspondante n'est
 * ajoutée que si aucune ligne `stores` n'existe pour ce domaine.
 *
 * @param  DoliDB $db Connexion base de données
 * @return array<string, int[]> Domaine (minuscules) => liste triée des entités concernées
 * @since  2.5.3
 */
function doli2shopFindAmbiguousShopDomains($db)
{
    if (!isModEnabled('multicompany')) {
        return array();
    }

    $ambiguous = array();

    $sqlStores = 'SELECT LOWER(shop_domain) AS shop_domain, GROUP_CONCAT(DISTINCT entity ORDER BY entity ASC) AS entities';
    $sqlStores .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
    $sqlStores .= ' WHERE entity >= 1';
    $sqlStores .= ' GROUP BY LOWER(shop_domain)';
    $sqlStores .= ' HAVING COUNT(DISTINCT entity) > 1';

    $resqlStores = $db->query($sqlStores);
    if ($resqlStores) {
        while ($obj = $db->fetch_object($resqlStores)) {
            $ambiguous[$obj->shop_domain] = array_map('intval', explode(',', (string) $obj->entities));
        }
        $db->free($resqlStores);
    } else {
        dol_syslog('doli2shopFindAmbiguousShopDomains - erreur requête llx_doli2shop_stores: ' . $db->lasterror(), LOG_ERR);
    }

    $sqlConst = "SELECT LOWER(value) AS shop_domain, GROUP_CONCAT(DISTINCT entity ORDER BY entity ASC) AS entities";
    $sqlConst .= ' FROM ' . MAIN_DB_PREFIX . 'const';
    $sqlConst .= " WHERE name = 'DOLI2SHOP_STORE_HOSTNAME'";
    $sqlConst .= ' AND entity >= 1';
    $sqlConst .= ' GROUP BY LOWER(value)';
    $sqlConst .= ' HAVING COUNT(DISTINCT entity) > 1';

    $resqlConst = $db->query($sqlConst);
    if ($resqlConst) {
        while ($obj = $db->fetch_object($resqlConst)) {
            if (!isset($ambiguous[$obj->shop_domain])) {
                $ambiguous[$obj->shop_domain] = array_map('intval', explode(',', (string) $obj->entities));
            }
        }
        $db->free($resqlConst);
    } else {
        dol_syslog('doli2shopFindAmbiguousShopDomains - erreur requête llx_const: ' . $db->lasterror(), LOG_ERR);
    }

    return $ambiguous;
}
