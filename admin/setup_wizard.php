<?php
/**
 * @file        admin/setup_wizard.php
 * @brief       Wizard de configuration Doli2Shop en 6 étapes
 *
 * Page unique d'assistant de configuration guidé (ADR-1).
 * Navigation par variable $step (1-6), pas de SPA.
 * Persistance via DOLI2SHOP_WIZARD_STEP dans llx_const.
 *
 * @package     Doli2Shop
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.2.0
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

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
if (file_exists(DOL_DOCUMENT_ROOT . '/core/class/html.formproduct.class.php')) {
	require_once DOL_DOCUMENT_ROOT . '/core/class/html.formproduct.class.php';
}
dol_include_once('/doli2shop/lib/doli2shop.lib.php');

// Security check — admin only (AC5)
if (!$user->admin) {
	accessforbidden();
}

// Load translations
$langs->loadLangs(array("admin", "doli2shop@doli2shop", "orders", "deliveries", "bills", "companies", "dict"));

// ===========================================
// PARAMETERS
// ===========================================

$action = GETPOST('action', 'aZ09');

// Story 22.3: Backward compat — migrate old step 4 to new step 6
$wizardStepRaw = getDolGlobalInt('DOLI2SHOP_WIZARD_STEP', 0);
if ($wizardStepRaw == 4 || $wizardStepRaw == 5) {
	// Old step 4 (activation) = new step 6; old step 5 should not exist but map safely
	dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', 6, 'chaine', 0, '', $conf->entity);
}

// Step from URL, POST, or persisted constant (AC4)
$step = GETPOSTINT('step');
if ($step < 1 || $step > 6) {
	$step = max(1, min(6, getDolGlobalInt('DOLI2SHOP_WIZARD_STEP', 1)));
}

// ===========================================
// DETECT CONNECTION STATE (AC2, AC3)
// ===========================================

$storeHostname = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '');
$accessToken   = getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN', '');
$isConnected   = !empty($storeHostname) && !empty($accessToken);

// Detect installation mode (AC3)
$isVerificationMode = $isConnected;

// If step > 1 but connection is not established, force back to step 1 (Task 4.3)
if ($step > 1 && !$isConnected) {
	$step = 1;
}

// ===========================================
// CONTEXTE BOUTIQUE (Story 48-4 — phase par boutique pour steps 4-5 ; Story 49-11 — barre de contexte)
// ===========================================
// Les steps 4 (expédition) et 5 (paiement) configurent des mappings PAR BOUTIQUE.
// $wizardStoreId = boutique en cours de configuration (défaut = boutique par défaut).
require_once dirname(__FILE__) . '/../class/storeservice.class.php';
$wizardStoreService = new StoreService($db);
$wizardStores       = $wizardStoreService->getAll();
$wizardDefaultStore = $wizardStoreService->getDefault();
$wizardDefaultStoreId = ($wizardDefaultStore !== null) ? (int) $wizardDefaultStore->rowid : 0;

// Story 49-11 — Résoudre la boutique active depuis la barre de contexte
// CORRECTION VALIDATE P5 : doli2shopGetCurrentAdminStore() peut retourner null (install vierge)
$currentAdminStore = doli2shopGetCurrentAdminStore($db);

// FIX 5c (HIGH 49-11) : wizard_store_id explicite (GET ou POST) prend la priorité sur la session
// → resynchroniser aussi $_SESSION et $currentAdminStore pour que l'encart « Configuration de la boutique : X »
//   ne diverge jamais du sélecteur.
$wizardStoreIdExplicit = GETPOSTINT('wizard_store_id');
if ($wizardStoreIdExplicit > 0) {
	$wizardStoreId = $wizardStoreIdExplicit;
} else {
	// Sinon : suivre la barre de contexte
	// CORRECTION VALIDATE P2 : null-check explicite (PAS de ?-> sur possible null, PHP 8+)
	$wizardStoreId = ($currentAdminStore !== null) ? (int) $currentAdminStore->rowid : $wizardDefaultStoreId;
}
// Valider que la boutique demandée existe (sinon retomber sur la boutique par défaut)
$wizardStoreValid = false;
foreach ($wizardStores as $ws) {
	if ((int) $ws->rowid === $wizardStoreId) {
		$wizardStoreValid = true;
		// FIX 5c : resynchroniser $currentAdminStore et la session sur la boutique demandée
		if ($wizardStoreIdExplicit > 0) {
			$currentAdminStore = $ws;
			$_SESSION['doli2shop_admin_store'] = $wizardStoreId;
		}
		break;
	}
}
if (!$wizardStoreValid) {
	$wizardStoreId = $wizardDefaultStoreId;
	// Resynchroniser $currentAdminStore avec la boutique par défaut si la boutique demandée n'existe pas
	$currentAdminStore = $wizardDefaultStore;
}

// ===========================================
// LOAD SHIPPING/PAYMENT METHODS (Story 22.3 — needed for steps 4-6)
// ===========================================

$shippingmethods = array();
$sql = "SELECT rowid, code, libelle FROM ".MAIN_DB_PREFIX."c_shipment_mode WHERE active = 1 ORDER BY libelle ASC";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$shippingmethods[$obj->rowid] = $obj->libelle;
	}
	$db->free($resql);
}

$paymentmethods = array();
$sql = "SELECT id, code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE active = 1";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$paymentmethods[$obj->id] = $obj->libelle . ' (' . $obj->code . ')';
	}
	$db->free($resql);
}

// ===========================================
// STEP 6 — DETECT CONFIGURATION STATUS (Story 15.4, AC1)
// ===========================================

// Shared list of 4 key CRONs for order processing (used by detection and activation)
$cronLabels = array(
	'Doli2ShopWebhookProcess',
	'Doli2ShopOrdersCatchup',
	'Doli2ShopFulfillmentCatchup',
	'Doli2ShopWebhookHealthCheck'
);

$hasWarehouse = getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE_ID', 0) > 0;
$hasCronUser = getDolGlobalInt('DOLI2SHOP_WEBHOOK_USER_ID', 0) > 0;

// SQL counts only needed on step 6 (activation) — avoid overhead on steps 1-5
$webhooksActive = 0;
$webhooksTotal = 0;
$cronsActive = 0;
$cronsTotal = count($cronLabels);
if ($step == 6) {
	// Count active webhooks from DB
	$sqlWebhooks = "SELECT";
	$sqlWebhooks .= " COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active_count,";
	$sqlWebhooks .= " COUNT(*) as total_count";
	$sqlWebhooks .= " FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks";
	$sqlWebhooks .= " WHERE entity = " . ((int) $conf->entity);
	$resqlWebhooks = $db->query($sqlWebhooks);
	if ($resqlWebhooks) {
		$objWebhooks = $db->fetch_object($resqlWebhooks);
		$webhooksActive = (int) $objWebhooks->active_count;
		$webhooksTotal = (int) $objWebhooks->total_count;
		$db->free($resqlWebhooks);
	}

	// Count active CRONs
	$sqlCrons = "SELECT";
	$sqlCrons .= " COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active_count";
	$sqlCrons .= " FROM " . MAIN_DB_PREFIX . "cronjob";
	$sqlCrons .= " WHERE module_name = 'doli2shop'";
	$sqlCrons .= " AND label IN ('" . implode("','", array_map(array($db, 'escape'), $cronLabels)) . "')";
	$sqlCrons .= " AND entity IN (" . getEntity('cronjob') . ")";
	$resqlCrons = $db->query($sqlCrons);
	if ($resqlCrons) {
		$objCrons = $db->fetch_object($resqlCrons);
		$cronsActive = (int) $objCrons->active_count;
		$db->free($resqlCrons);
	}
}

// ===========================================
// ACTIONS
// ===========================================
// Hotfix 2.4.6 (2/2) : les 10 gardes ci-dessous réimplémentaient en dur la comparaison au
// mauvais jeton (`GETPOST('token') == newToken()`, où `newToken()` = jeton de la PROCHAINE
// requête, jamais celui réellement soumis) — cassées en silence dès que
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Déléguées au helper partagé
// verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.

// Restart wizard from step 1
// Review 3 couches (CRITICAL) : les blocs d'action ci-dessous sont gardés par `&& verifToken()`.
// Sans branche `else`, un jeton refusé (session expirée, onglet resté ouvert, rotation CSRF
// consommée par une requête concurrente) les faisait tous sauter EN SILENCE — la page se
// rechargeait à l'identique, sans succès ni erreur. C'est très exactement le symptôme que ce
// hotfix corrige par ailleurs : on le neutralise ici aussi, en signalant le refus une seule fois.
if (!empty($action) && !empty($_POST) && !verifToken()) {
    setEventMessages($langs->trans("ErrorInvalidCSRFTokenRetry"), null, 'errors');
}

if ($action == 'restart_wizard' && verifToken()) {
	dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', 1, 'chaine', 0, '', $conf->entity);
	$step = 1;
	setEventMessages($langs->trans("WizardRestarted"), null, 'mesgs');
}

if ($action == 'goto_step' && verifToken()) {
	$newStep = GETPOSTINT('goto');
	if ($newStep >= 1 && $newStep <= 6) {
		// Validate that we can advance: step 1 requires connection
		if ($newStep > 1 && !$isConnected) {
			setEventMessages($langs->trans("WizardConnectionRequired"), null, 'errors');
			$newStep = 1;
		}
		$step = $newStep;
		// Persist wizard step (AC4)
		dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', $step, 'chaine', 0, '', $conf->entity);
	}
}

// Story 15.2 — Save step 2 configuration (AC2, AC3)
if ($action == 'save_step2_config' && verifToken()) {
	// FIX 8 (MEDIUM 49-11) : guard serveur — une boutique secondaire ne peut pas sauvegarder les réglages communs.
	// Le onsubmit=false côté client ne suffit pas (cURL, curl-less forms, etc.).
	if ($currentAdminStore !== null && !$currentAdminStore->is_default) {
		// Boutique secondaire : réglages communs non modifiables — guard serveur
		setEventMessages($langs->transnoentities("Doli2ShopWizardStep2CommonSettings"), null, 'warnings');
		$step = 2;
	} else {
		$warehouseId = GETPOSTINT('default_warehouse_id');
		$cronUserId = GETPOSTINT('webhook_user_id');
		$customerCategory = GETPOSTINT('dolibarr_customer_category');
		$typentWithCompany = GETPOSTINT('typent_with_company');
		$typentWithoutCompany = GETPOSTINT('typent_without_company');

		// Validation: warehouse and CRON user are required (AC2)
		$step2Errors = array();
		if ($warehouseId <= 0) {
			$step2Errors[] = $langs->trans("WizardStep2DefaultWarehouse");
		}
		if ($cronUserId <= 0) {
			$step2Errors[] = $langs->trans("WizardStep2CronUser");
		}

		if (!empty($step2Errors)) {
			setEventMessages($langs->trans("WizardStep2RequiredFields") . ' : ' . implode(', ', $step2Errors), null, 'errors');
			$step = 2;
		} else {
			// Save all constants (AC3)
			dolibarr_set_const($db, 'DOLI2SHOP_DEFAULT_WAREHOUSE_ID', $warehouseId, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'DOLI2SHOP_WEBHOOK_USER_ID', $cronUserId, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'DOLI2SHOP_DOLIBARR_CUSTOMER_CATEGORY', $customerCategory, 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, 'DOLI2SHOP_TYPENT_WITH_COMPANY', $typentWithCompany, 'entier', 0, '', $conf->entity);
			dolibarr_set_const($db, 'DOLI2SHOP_TYPENT_WITHOUT_COMPANY', $typentWithoutCompany, 'entier', 0, '', $conf->entity);

			// Persist step 3 and redirect (AC3)
			dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', 3, 'chaine', 0, '', $conf->entity);
			setEventMessages($langs->trans("WizardStep2SaveSuccess"), null, 'mesgs');
			header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=3');
			exit;
		}
	}
}

// Story 19.4 — Prefill shipping defaults from wizard
if ($action == 'prefill_shipping_defaults' && verifToken()) {
	$defaults = array(
		array('Colissimo', 2), array('Mondial Relay', 4), array('Chronopost', 1),
		array('La Poste', 3), array('DPD France', 2), array('UPS', 2),
		array('FedEx', 2), array('DHL', 2), array('GLS', 2), array('TNT', 2)
	);
	$insertCount = 0;
	foreach ($defaults as $item) {
		// Story 48-4 : prefill scopé à la boutique en cours de configuration (fk_store)
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_shipping_rules (entity, fk_store, rule_type, shopify_pattern, dol_shipping_method_id, delivery_days, active)"
			. " VALUES (" . (int)$conf->entity . ", " . (int)$wizardStoreId . ", 'tracking', '" . $db->escape($item[0]) . "', 0, " . (int)$item[1] . ", 1)"
			. " ON DUPLICATE KEY UPDATE delivery_days = " . (int)$item[1] . ", active = 1";
		if ($db->query($sql)) {
			$insertCount++;
		}
	}
	setEventMessages($langs->trans("WizardPrefillShippingSuccess", $insertCount), null, 'mesgs');
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=' . $step . '&wizard_store_id=' . (int)$wizardStoreId);
	exit;
}

// Story 22.3 — Save step 4: shipping mappings
if ($action == 'save_step4_shipping' && verifToken()) {
	$db->begin();
	$error = 0;
	// FIX 7 (MEDIUM 49-11) : capturer l'erreur DB au PREMIER échec (une query réussie après peut effacer lasterror)
	$dbError = '';

	// Story 48-4 : scope PAR BOUTIQUE (fk_store). Ne supprime que les règles de la boutique
	// en cours de configuration — les autres boutiques sont préservées.
	$wizardStoreFilter = ($wizardStoreId > 0) ? (" AND fk_store = ".(int)$wizardStoreId) : " AND fk_store = 0";
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."doli2shop_shipping_rules WHERE entity = ".(int)$conf->entity.$wizardStoreFilter)) {
		$error++;
		if ($dbError === '') { $dbError = $db->lasterrno() . ' - ' . $db->lasterror(); }
	}

	// Title rules (à la commande)
	$titlePatterns = GETPOST('title_shopify_pattern', 'array');
	$titleMethods = GETPOST('title_dol_shipping_method_id', 'array');
	$titleDays = GETPOST('title_delivery_days', 'array');
	if (is_array($titlePatterns)) {
		foreach ($titlePatterns as $k => $pattern) {
			if (!empty($pattern) && !empty($titleMethods[$k])) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_shipping_rules SET
					entity = ".(int)$conf->entity.",
					fk_store = ".(int)$wizardStoreId.",
					rule_type = 'title',
					shopify_pattern = '".$db->escape($pattern)."',
					dol_shipping_method_id = ".(int)$titleMethods[$k].",
					delivery_days = ".(int)$titleDays[$k].",
					active = 1";
				if (!$db->query($sql)) {
					$error++;
					if ($dbError === '') { $dbError = $db->lasterrno() . ' - ' . $db->lasterror(); }
				}
			}
		}
	}

	// Tracking rules (au fulfillment)
	$trackingPatterns = GETPOST('tracking_shopify_pattern', 'array');
	$trackingMethods = GETPOST('tracking_dol_shipping_method_id', 'array');
	$trackingDays = GETPOST('tracking_delivery_days', 'array');
	if (is_array($trackingPatterns)) {
		foreach ($trackingPatterns as $k => $pattern) {
			if (!empty($pattern) && !empty($trackingMethods[$k])) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_shipping_rules SET
					entity = ".(int)$conf->entity.",
					fk_store = ".(int)$wizardStoreId.",
					rule_type = 'tracking',
					shopify_pattern = '".$db->escape($pattern)."',
					dol_shipping_method_id = ".(int)$trackingMethods[$k].",
					delivery_days = ".(int)$trackingDays[$k].",
					active = 1";
				if (!$db->query($sql)) {
					$error++;
					if ($dbError === '') { $dbError = $db->lasterrno() . ' - ' . $db->lasterror(); }
				}
			}
		}
	}

	if ($error) {
		// FIX 7 : $dbError déjà capturé au premier échec ci-dessus
		dol_syslog('setup_wizard::save_step4_shipping - SQL error: ' . $dbError, LOG_ERR);
		$db->rollback();
		// FIX 6 (HIGH 49-11) : $dbError peut contenir du SQL/chevrons — échapper pour XSS
		setEventMessages($langs->transnoentities("Doli2ShopWizardSaveError") . ' (' . dol_escape_htmltag($dbError) . ')', null, 'errors');
		$step = 4;
	} else {
		$db->commit();
		dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', 5, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans("WizardStep4SaveSuccess"), null, 'mesgs');
		header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=5&wizard_store_id='.(int)$wizardStoreId);
		exit;
	}
}

// Story 22.3 — Save step 5: payment mappings
if ($action == 'save_step5_payment' && verifToken()) {
	$db->begin();
	$error = 0;
	// FIX 7 (MEDIUM 49-11) : capturer l'erreur DB au PREMIER échec (une query réussie après peut effacer lasterror)
	$dbError = '';

	// Story 48-4 : scope PAR BOUTIQUE (fk_store)
	$wizardStoreFilterPay = ($wizardStoreId > 0) ? (" AND fk_store = ".(int)$wizardStoreId) : " AND fk_store = 0";
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."doli2shop_payments WHERE entity = ".(int)$conf->entity.$wizardStoreFilterPay)) {
		$error++;
		if ($dbError === '') { $dbError = $db->lasterrno() . ' - ' . $db->lasterror(); }
	}

	$shopifyMethods = GETPOST('shopify_payment_method', 'array');
	$dolPaymentIds = GETPOST('dolibarr_payment_id', 'array');
	if (is_array($shopifyMethods)) {
		foreach ($shopifyMethods as $k => $method) {
			if (!empty($method) && !empty($dolPaymentIds[$k])) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."doli2shop_payments SET
					shopify_payment_method = '".$db->escape($method)."',
					dolibarr_payment_id = ".(int)$dolPaymentIds[$k].",
					active = 1,
					fk_store = ".(int)$wizardStoreId.",
					entity = ".(int)$conf->entity;
				if (!$db->query($sql)) {
					$error++;
					if ($dbError === '') { $dbError = $db->lasterrno() . ' - ' . $db->lasterror(); }
				}
			}
		}
	}

	if ($error) {
		// FIX 7 : $dbError déjà capturé au premier échec ci-dessus
		$dbError = $dbError ?: ($db->lasterrno() . ' - ' . $db->lasterror());
		dol_syslog('setup_wizard::save_step5_payment - SQL error: ' . $dbError, LOG_ERR);
		$db->rollback();
		// FIX 6 (HIGH 49-11) : $dbError peut contenir du SQL/chevrons — échapper pour XSS
		setEventMessages($langs->transnoentities("Doli2ShopWizardSaveError") . ' (' . dol_escape_htmltag($dbError) . ')', null, 'errors');
		$step = 5;
	} else {
		$db->commit();
		dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', 6, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans("WizardStep5SaveSuccess"), null, 'mesgs');
		header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=6');
		exit;
	}
}

// Story 15.3 — Save step 3 configuration: order config + processing toggles (AC3)
if ($action == 'save_step3_config' && verifToken()) {
	// FIX 8 (MEDIUM 49-11) : guard serveur — boutique secondaire ne peut pas sauvegarder les réglages communs (step 2)
	// Ce guard s'applique uniquement si la boutique résolue est secondaire ET qu'on tente une action globale.
	// Note : save_step3_config gère déjà la bifurcation défaut/secondaire ci-dessous — le guard est sur save_step2_config.

	// FIX 4 (HIGH 49-11) : résoudre la boutique depuis le POST wizard_store_id (fiable en multi-onglets),
	// et non depuis la session seule (risque de mauvaise boutique si plusieurs onglets ouverts).
	$step3PostedStoreId = GETPOSTINT('wizard_store_id');
	if ($step3PostedStoreId > 0) {
		// Valider que la boutique postée existe bien dans la liste
		$step3StoreValid = false;
		foreach ($wizardStores as $ws) {
			if ((int) $ws->rowid === $step3PostedStoreId) {
				$step3StoreValid = true;
				$currentAdminStore = $ws; // resynchroniser le contexte
				break;
			}
		}
		if ($step3StoreValid) {
			$wizardStoreId = $step3PostedStoreId;
			$_SESSION['doli2shop_admin_store'] = $wizardStoreId;
		}
	}

	// Story 49-11 T5 — Stratégie P1 : boutique par défaut → dolibarr_set_const() ; secondaire → StoreSettings::set()
	// CORRECTION VALIDATE P2 : null-check explicite sur $currentAdminStore
	$perStoreIsDefault = ($currentAdminStore === null) || (bool) $currentAdminStore->is_default;

	if (!$perStoreIsDefault) {
		// Boutique secondaire : écriture per-store via StoreSettings uniquement
		require_once dirname(__FILE__) . '/../class/storesettings.class.php';
		$perStoreSettings = new StoreSettings($db);
		$perStoreStoreId = $wizardStoreId;

		// FIX 1 (CRITICAL 49-11) : clés SANS préfixe DOLI2SHOP_ — le moteur lit 'ORDER_PREFIX', 'AUTO_CREATE_INVOICE', etc.
		// FIX 3 (HIGH 49-11) : compter les échecs set() et bloquer la redirection en cas d'erreur
		$perStoreSetErrors = 0;
		$r = $perStoreSettings->set($perStoreStoreId, 'ORDER_PREFIX', GETPOST('order_prefix', 'alphanohtml'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'DELIVERY_DELAY', GETPOSTINT('delivery_delay'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'DELIVERY_DELAY_TYPE', GETPOST('delivery_delay_type', 'aZ09'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'ORDER_ORIGIN', GETPOSTINT('order_origin'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'PAYMENT_TERMS', GETPOSTINT('payment_terms'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'DEFAULT_SHIPPING_METHOD_ID', GETPOSTINT('default_shipping_method_id'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'BUYING_PRICE_SOURCE', GETPOST('buying_price_source', 'aZ09') ?: 'pmp');
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'SYNC_NON_PAID_ORDERS', GETPOST('sync_non_paid_orders', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'SHIPPING_PRODUCT_ID', GETPOSTINT('shipping_product_id'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'TIP_PRODUCT_ID', GETPOSTINT('tip_product_id'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'DEFAULT_DELIVERY_DAYS', GETPOSTINT('default_delivery_days'));
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'ORDER_BANK_ACCOUNT', GETPOSTINT('order_bank_account'));
		if ($r < 0) { $perStoreSetErrors++; }

		// Checkboxes: absent from POST when unchecked → value 0
		$r = $perStoreSettings->set($perStoreStoreId, 'AUTO_CREATE_EXPEDITION', GETPOST('auto_create_expedition', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'AUTO_CLOSE_ORDER', GETPOST('auto_close_order', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'AUTO_CREATE_INVOICE', GETPOST('auto_create_invoice', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'AUTO_VALIDATE_INVOICE', GETPOST('auto_validate_invoice', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'AUTO_CREATE_PAYMENT', GETPOST('auto_create_payment', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
		$r = $perStoreSettings->set($perStoreStoreId, 'AUTO_CLASSIFY_BILLED', GETPOST('auto_classify_billed', 'aZ09') ? 1 : 0);
		if ($r < 0) { $perStoreSetErrors++; }
	} else {
		// Boutique par défaut : comportement historique — constantes globales uniquement
		dolibarr_set_const($db, 'DOLI2SHOP_ORDER_PREFIX', GETPOST('order_prefix', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_DELIVERY_DELAY', GETPOSTINT('delivery_delay'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_DELIVERY_DELAY_TYPE', GETPOST('delivery_delay_type', 'aZ09'), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_ORDER_ORIGIN', GETPOSTINT('order_origin'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_PAYMENT_TERMS', GETPOSTINT('payment_terms'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_DEFAULT_SHIPPING_METHOD_ID', GETPOSTINT('default_shipping_method_id'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_BUYING_PRICE_SOURCE', GETPOST('buying_price_source', 'aZ09') ?: 'pmp', 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_SYNC_NON_PAID_ORDERS', GETPOST('sync_non_paid_orders', 'aZ09') ? 1 : 0, 'yesno', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_SHIPPING_PRODUCT_ID', GETPOSTINT('shipping_product_id'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_TIP_PRODUCT_ID', GETPOSTINT('tip_product_id'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_DEFAULT_DELIVERY_DAYS', GETPOSTINT('default_delivery_days'), 'entier', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_ORDER_BANK_ACCOUNT', GETPOSTINT('order_bank_account'), 'entier', 0, '', $conf->entity);

		// Checkboxes: absent from POST when unchecked → value 0
		$autoCreateExpedition = GETPOST('auto_create_expedition', 'aZ09') ? 1 : 0;
		$autoCloseOrder = GETPOST('auto_close_order', 'aZ09') ? 1 : 0;
		$autoCreateInvoice = GETPOST('auto_create_invoice', 'aZ09') ? 1 : 0;
		$autoValidateInvoice = GETPOST('auto_validate_invoice', 'aZ09') ? 1 : 0;
		$autoCreatePayment = GETPOST('auto_create_payment', 'aZ09') ? 1 : 0;
		$autoClassifyBilled = GETPOST('auto_classify_billed', 'aZ09') ? 1 : 0;

		// Save all 6 toggles
		dolibarr_set_const($db, 'DOLI2SHOP_AUTO_CREATE_EXPEDITION', $autoCreateExpedition, 'yesno', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_AUTO_CLOSE_ORDER', $autoCloseOrder, 'yesno', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_AUTO_CREATE_INVOICE', $autoCreateInvoice, 'yesno', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_AUTO_VALIDATE_INVOICE', $autoValidateInvoice, 'yesno', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_AUTO_CREATE_PAYMENT', $autoCreatePayment, 'yesno', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOLI2SHOP_AUTO_CLASSIFY_BILLED', $autoClassifyBilled, 'yesno', 0, '', $conf->entity);
	}

	// FIX 3 (HIGH 49-11) : bloquer la redirection si des set() ont échoué (boutique secondaire)
	if (isset($perStoreSetErrors) && $perStoreSetErrors > 0) {
		setEventMessages($langs->transnoentities("Doli2ShopWizardSaveError"), null, 'errors');
		$step = 3;
	} else {
		// FIX 5a (HIGH 49-11) : propager wizard_store_id dans la redirect vers step 4
		dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_STEP', 4, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans("WizardStep3SaveSuccess"), null, 'mesgs');
		header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=4&wizard_store_id=' . (int) $wizardStoreId);
		exit;
	}
}

// Story 15.4 — Activate webhooks (AC2)
if ($action == 'activate_webhooks' && verifToken()) {
	dol_include_once('/doli2shop/class/shopifywebhooks.class.php');
	$webhookManager = new ShopifyWebhooks($db);
	$topics = $webhookManager->getSupportedTopics();
	$result = $webhookManager->syncWebhooks($topics);
	if ($result) {
		setEventMessages($langs->trans("WizardStep4ActivateWebhooksSuccess"), null, 'mesgs');
	} else {
		// Story 49-11 T7 : clé dédiée (les détails d'erreur sont dans $webhookManager->errors)
		setEventMessages($langs->transnoentities("Doli2ShopWizardSaveError"), $webhookManager->errors, 'errors');
	}
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=6');
	exit;
}

// Story 15.4 — Activate CRONs (AC3)
if ($action == 'activate_crons' && verifToken()) {
	// Uses $cronLabels defined in PARAMETERS section
	$sqlActivate = "UPDATE " . MAIN_DB_PREFIX . "cronjob SET status = 1";
	$sqlActivate .= " WHERE module_name = 'doli2shop'";
	$sqlActivate .= " AND label IN ('" . implode("','", array_map(array($db, 'escape'), $cronLabels)) . "')";
	$sqlActivate .= " AND entity IN (" . getEntity('cronjob') . ")";
	$db->query($sqlActivate);
	setEventMessages($langs->trans("WizardStep4ActivateCronsSuccess"), null, 'mesgs');
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=6');
	exit;
}

// Story 15.4 — Complete wizard (AC4, AC5)
if ($action == 'complete_wizard' && verifToken()) {
	dolibarr_set_const($db, 'DOLI2SHOP_WIZARD_COMPLETED', 1, 'chaine', 0, '', $conf->entity);

	// Auto-activate webhooks if not all active
	if ($isConnected && $webhooksActive < $webhooksTotal) {
		dol_include_once('/doli2shop/class/shopifywebhooks.class.php');
		$webhookManager = new ShopifyWebhooks($db);
		$topics = $webhookManager->getSupportedTopics();
		$webhookManager->syncWebhooks($topics);
	}

	// Auto-activate CRONs if not all active
	if ($cronsActive < $cronsTotal) {
		$sqlActivate = "UPDATE " . MAIN_DB_PREFIX . "cronjob SET status = 1";
		$sqlActivate .= " WHERE module_name = 'doli2shop'";
		$sqlActivate .= " AND label IN ('" . implode("','", array_map(array($db, 'escape'), $cronLabels)) . "')";
		$sqlActivate .= " AND entity IN (" . getEntity('cronjob') . ")";
		$db->query($sqlActivate);
	}

	// Check for incomplete items and build warning list (AC5)
	$wizardWarnings = array();
	if (!$isConnected) {
		$wizardWarnings[] = $langs->trans("WizardStep4ConnectionKo");
	}
	if (!$hasWarehouse) {
		$wizardWarnings[] = $langs->trans("WizardStep4WarehouseKo");
	}
	if (!$hasCronUser) {
		$wizardWarnings[] = $langs->trans("WizardStep4CronUserKo");
	}

	if (!empty($wizardWarnings)) {
		setEventMessages($langs->trans("WizardStep4WarningIncomplete"), $wizardWarnings, 'warnings');
	}
	setEventMessages($langs->trans("WizardStep4CompleteSuccess"), null, 'mesgs');
	// Story 37.1 : rediriger vers le wizard de vérification (diagnostic actionnable post-install)
	header('Location: ' . dol_buildpath('/doli2shop/admin/setup_verification.php', 1));
	exit;
}

// Handle OAuth callback success from setup_wizard return
// Note: $conf->global is reloaded from DB on each request, so connection state at lines 88-90 is already up-to-date
if (GETPOST('oauth_success', 'int') == 1 && $isConnected) {
	setEventMessages($langs->trans("WizardConnectionSuccess", $storeHostname), null, 'mesgs');
}

// Story 6.4 — Structured OAuth error display (Task 1.2)
// Define $connectUrl early — needed by error banner action links below (H2 fix)
$connectUrl = dol_buildpath('/doli2shop/admin/connect_shopify.php', 1);

$oauthErrorType = GETPOST('oauth_error_type', 'aZ09_');
$oauthErrorHtml = '';
if (!empty($oauthErrorType)) {
	// Whitelist of known error types (Task 1.7, 1.8)
	$knownErrorTypes = array('credentials', 'ssl', 'timeout', 'signature', 'expired', 'shop_format', 'store_failed');
	if (in_array($oauthErrorType, $knownErrorTypes)) {
		// Determine banner class (Task 1.6 — timeout uses warning)
		$bannerClass = ($oauthErrorType == 'timeout') ? 'd2s-alert--warning' : 'd2s-alert--error';
		$bannerIcon = ($oauthErrorType == 'timeout') ? 'fa-exclamation-triangle' : 'fa-times-circle';

		$oauthErrorHtml .= '<div class="d2s-alert-banner ' . $bannerClass . '" style="margin-bottom: 15px; text-align: left;">';
		$oauthErrorHtml .= '<div style="display: -webkit-box; display: -ms-flexbox; display: flex; -webkit-box-align: start; -ms-flex-align: start; align-items: flex-start;">';
		$oauthErrorHtml .= '<div style="margin-right: 10px; font-size: 1.3em;"><i class="fas ' . $bannerIcon . '"></i></div>';
		$oauthErrorHtml .= '<div>';

		// Quoi (What happened)
		$oauthErrorHtml .= '<strong>' . $langs->trans('WizardErrorWhat_' . $oauthErrorType) . '</strong><br>';

		// Pourquoi (Why it happened)
		$oauthErrorHtml .= '<span class="opacitymedium">' . $langs->trans('WizardErrorWhy_' . $oauthErrorType) . '</span><br>';

		// Action (What to do) — different links per type (Task 1.4, 1.5, 1.6, 1.7)
		$oauthErrorHtml .= '<div style="margin-top: 8px;">';
		if ($oauthErrorType == 'credentials') {
			$oauthErrorHtml .= '<a href="https://apps.shopify.com/doli2shop" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($langs->trans('WizardErrorAction_credentials')) . '</a>';
			$oauthErrorHtml .= ' &middot; ';
			$oauthErrorHtml .= '<a href="https://doli2shop.ptitetete.org/docs/" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($langs->trans('WizardHelpDocLink')) . '</a>';
		} elseif ($oauthErrorType == 'ssl') {
			$oauthErrorHtml .= '<a href="https://doli2shop.ptitetete.org/docs/https-setup" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($langs->trans('WizardErrorAction_ssl')) . '</a>';
		} elseif ($oauthErrorType == 'store_failed') {
			$oauthErrorHtml .= '<a href="mailto:doli2shop@ptitetete.org">' . dol_escape_htmltag($langs->trans('WizardErrorAction_store_failed')) . '</a>';
		} else {
			// timeout, signature, expired, shop_format — retry link
			$oauthErrorHtml .= '<a href="' . $connectUrl . '">' . dol_escape_htmltag($langs->trans('WizardErrorAction_' . $oauthErrorType)) . '</a>';
		}
		$oauthErrorHtml .= '</div>';

		$oauthErrorHtml .= '</div>';
		$oauthErrorHtml .= '</div>';
		$oauthErrorHtml .= '</div>';
	}
	// Fallback (Task 1.8): if unknown type, setEventMessages already displayed by oauth_receive.php
}

// ===========================================
// VIEW
// ===========================================

$pageTitle = $langs->trans("Doli2ShopWizardTitle");
// Escaped URLs for form actions (H2 fix — prevent XSS via PATH_INFO)
$wizardUrl = dol_buildpath('/doli2shop/admin/setup_wizard.php', 1);
// $connectUrl already defined above (Story 6.4 error banner block)

llxHeader('', $pageTitle, '', '', 0, 0, '', '', '', 'mod-doli2shop page-setup-wizard');

// Story licence-prevenir-et-permettre-de-renouveler (AC3) : bandeau de licence sur tous les
// écrans d'administration, pas seulement les deux onglets historiques.
doli2shopShowLicenseWarningBanner();

// CSS module (fichier dynamique PHP — hotfix v2.2.3, le fichier static .css n'existe pas dans le pack)
print '<link rel="stylesheet" type="text/css" href="../css/doli2shop.css.php">';

// Isolation CSS (même pattern que setup.php)
print '<div class="doli2shop-page">';

// Instantiate form helpers (after llxHeader loaded Dolibarr classes)
$form = new Form($db);
$formcompany = new FormCompany($db);
$formproduct = class_exists('FormProduct') ? new FormProduct($db) : null;

// Subheader (même pattern que setup.php)
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("ModuleShopifyIntegrationName"), $linkback, 'title_setup');

// Admin tabs
$head = doli2shopAdminPrepareHead();
print dol_get_fiche_head($head, 'wizard', $langs->trans("ModuleShopifyIntegrationName"), -1, 'doli2shop@doli2shop');
doli2shopRenderAdminTopBar($db);

// Story 49-11 T6 — Encart boutique active : visible seulement si boutique secondaire ET multi-boutiques
// CORRECTION VALIDATE P2 : null-check explicite sur $currentAdminStore
if ($currentAdminStore !== null && !$currentAdminStore->is_default && count($wizardStores) > 1) {
	print '<div class="d2s-alert-banner d2s-alert--info" style="margin-bottom:10px;">';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardForStore")) . ' : <strong>' . dol_escape_htmltag($currentAdminStore->label) . '</strong>';
	print '</div>';
}

// Title with mode indicator (AC3)
$modeLabel = $isVerificationMode ? $langs->trans("WizardVerificationMode") : $langs->trans("WizardNewInstallMode");
print load_fiche_titre($pageTitle, '<span class="opacitymedium">' . dol_escape_htmltag($modeLabel) . '</span>', '');

// ===========================================
// STEPPER (AC1)
// ===========================================

$stepTitles = array(
	1 => $langs->trans("WizardStep1Title"),
	2 => $langs->trans("WizardStep2Title"),
	3 => $langs->trans("WizardStep3Title"),
	4 => $langs->trans("WizardStep4Title"),
	5 => $langs->trans("WizardStep5Title"),
	6 => $langs->trans("WizardStep6Title"),
);

// Resume banner — show if admin has progressed beyond current step
$wizardStepConst = getDolGlobalInt('DOLI2SHOP_WIZARD_STEP', 0);
if ($wizardStepConst >= 2 && $step < $wizardStepConst && empty($oauthErrorType)) {
	$resumeStep = ($wizardStepConst > 6) ? 6 : $wizardStepConst;
	print '<div class="d2s-alert-banner d2s-alert--info" style="margin-bottom: 15px;">';
	print dol_escape_htmltag($langs->trans("WizardResumeInfo", $resumeStep));
	print '</div>';
}

print '<nav class="d2s-wizard-stepper" aria-label="' . dol_escape_htmltag($langs->trans("Doli2ShopWizardTitle")) . '">';
print '<ol class="d2s-wizard-steps">';
for ($s = 1; $s <= 6; $s++) {
	$stepClass = 'd2s-step';
	if ($s < $step) {
		$stepClass .= ' d2s-step--done';
	} elseif ($s == $step) {
		$stepClass .= ' d2s-step--active';
	} else {
		$stepClass .= ' d2s-step--pending';
	}
	print '<li class="' . $stepClass . '">';
	print '<span class="d2s-step-circle">' . $s . '</span>';
	print '<span class="d2s-step-title">' . dol_escape_htmltag($stepTitles[$s]) . '</span>';
	print '</li>';
}
print '</ol>';
print '</nav>';

// Restart wizard button — visible if wizard has progressed beyond step 1
if ($wizardStepConst >= 2 || $step >= 2) {
	print '<div style="text-align: right; margin-bottom: 10px;">';
	print '<a class="button buttonDelete" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=restart_wizard&token=' . newToken() . '"';
	print ' onclick="return confirm(\'' . dol_escape_js($langs->trans("WizardRestartConfirm")) . '\')">';
	print '<span class="fas fa-redo paddingright"></span>' . dol_escape_htmltag($langs->trans("WizardRestart"));
	print '</a>';
	print '</div>';
}

// ===========================================
// PANEL — STEP 1: Connexion OAuth (AC2, AC3)
// ===========================================

print '<div id="d2s-wizard-panel-1" class="d2s-wizard-panel"' . ($step != 1 ? ' style="display:none" aria-hidden="true"' : ' aria-hidden="false"') . '>';

// Story 6.4 — Display structured OAuth error banner if present (Task 1.2)
if (!empty($oauthErrorHtml)) {
	print $oauthErrorHtml;
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("WizardStep1Description") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td colspan="2">';
print '<div style="padding: 20px; text-align: center;">';

// Story 49-11 T3 — Résoudre le domaine et l'état de connexion selon la boutique active
// CORRECTION VALIDATE P2 : null-check explicite sur $currentAdminStore
if ($currentAdminStore !== null && !$currentAdminStore->is_default) {
	// Boutique secondaire : afficher le domaine depuis llx_doli2shop_stores
	$wizardShopDomain = $currentAdminStore->shop_domain;
	$wizardIsConnected = !empty($wizardShopDomain);
	$wizardIsSecondaryStore = true;
} else {
	// Boutique par défaut (ou install vierge) : comportement historique inchangé
	$wizardShopDomain = $storeHostname;
	$wizardIsConnected = $isConnected;
	$wizardIsSecondaryStore = false;
}

if ($wizardIsConnected) {
	// Connected state — green badge (AC2, AC3, AC4)
	print '<div class="d2s-alert-banner d2s-alert--info" style="margin-bottom: 15px; text-align: left;">';
	print '<span style="font-size: 1.5em; vertical-align: middle;">&#x2705;</span> ';
	print '<strong>' . $langs->trans("WizardConnected", dol_escape_htmltag($wizardShopDomain)) . '</strong>';
	print '</div>';

	// Reconnect button — POST vers connect_shopify.php
	// Story 49-11 T3 CORRECTION VALIDATE P1 : boutique secondaire → tandem store_id + store_context=reconnect
	print '<form method="POST" action="' . $connectUrl . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="connect">';
	print '<input type="hidden" name="redirect" value="wizard">';
	if ($wizardIsSecondaryStore) {
		print '<input type="hidden" name="store_id" value="' . (int) $currentAdminStore->rowid . '">';
		print '<input type="hidden" name="store_context" value="reconnect">';
	}
	print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("WizardReconnectButton")) . '" />';
	print '</form>';
} else {
	// Not connected state (AC2, AC4)
	print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 15px; text-align: left;">';
	print '<strong>' . $langs->trans("WizardNotConnected") . '</strong>';
	print '</div>';

	print '<p class="opacitymedium" style="max-width: 500px; margin: 0 auto 20px;">';
	print $langs->trans("WizardStep1Description");
	print '</p>';

	// Connect button — POST vers connect_shopify.php
	// Story 49-11 T3 CORRECTION VALIDATE P1 : boutique secondaire → tandem store_id + store_context=new
	print '<form method="POST" action="' . $connectUrl . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="connect">';
	print '<input type="hidden" name="redirect" value="wizard">';
	if ($wizardIsSecondaryStore) {
		print '<input type="hidden" name="store_id" value="' . (int) $currentAdminStore->rowid . '">';
		print '<input type="hidden" name="store_context" value="new">';
	}
	print '<input type="submit" class="butAction" value="' . dol_escape_htmltag($langs->trans("WizardConnectButton")) . '" />';
	print '</form>';
}

print '</div>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>'; // fichehalfleft

// Right column — connection info
print '<div class="fichehalfright">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("WizardConnectionDetails") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>';
print '<div style="padding: 15px;">';

if ($wizardIsConnected) {
	// Story 49-11 T3 : afficher le domaine de la boutique active (secondaire ou par défaut)
	print '<table class="noborder centpercent">';
	print '<tr><td class="titlefield">' . $langs->trans("ShopifyStoreHostname") . '</td>';
	print '<td><strong>' . dol_escape_htmltag($wizardShopDomain) . '</strong></td></tr>';

	// Informations supplémentaires : uniquement pour la boutique par défaut (constantes globales)
	if (!$wizardIsSecondaryStore) {
		$connectedAt = getDolGlobalString('DOLI2SHOP_OAUTH_CONNECTED_AT', '');
		if (!empty($connectedAt)) {
			print '<tr><td>' . $langs->trans("WizardConnectedAt") . '</td>';
			print '<td>' . dol_escape_htmltag($connectedAt) . '</td></tr>';
		}

		$locationId = getDolGlobalString('DOLI2SHOP_LOCATION_ID', '');
		if (!empty($locationId)) {
			print '<tr><td>' . $langs->trans("WizardLocationId") . '</td>';
			print '<td>' . dol_escape_htmltag($locationId) . '</td></tr>';
		}
	}
	print '</table>';
} else {
	print '<p class="opacitymedium">' . $langs->trans("WizardNoConnectionYet") . '</p>';
}

print '</div>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>'; // fichehalfright

print '</div>'; // fichecenter

print '<div class="clearboth"></div>';

// Story 6.4 — Collapsible FAQ step 1 (Task 2.1)
print '<details class="d2s-collapsible" style="margin-top: 16px;">';
print '<summary class="d2s-collapsible-header">';
print '<span class="d2s-collapsible-icon">&#9654;</span> ';
print dol_escape_htmltag($langs->trans("WizardNeedHelp"));
print '</summary>';
print '<div class="d2s-collapsible-body" style="padding: 10px 15px;">';
print '<p><strong>' . dol_escape_htmltag($langs->trans("WizardHelp1Q_step1")) . '</strong></p>';
print '<p>' . $langs->trans("WizardHelp1A_step1") . '</p>';
print '<hr>';
print '<p><strong>' . dol_escape_htmltag($langs->trans("WizardHelp2Q_step1")) . '</strong></p>';
print '<p>' . $langs->trans("WizardHelp2A_step1") . '</p>';
print '<hr>';
print '<p><strong>' . dol_escape_htmltag($langs->trans("WizardHelp3Q_step1")) . '</strong></p>';
print '<p>' . $langs->trans("WizardHelp3A_step1") . '</p>';
print '</div>';
print '</details>';

print '</div>'; // panel 1

// ===========================================
// PANEL — STEP 2: Configuration Dolibarr de base (Story 15.2)
// ===========================================

print '<div id="d2s-wizard-panel-2" class="d2s-wizard-panel"' . ($step != 2 ? ' style="display:none" aria-hidden="true"' : ' aria-hidden="false"') . '>';

// Story 50-8 — Encart persistant : la boutique active du wizard n'est pas connectée à Shopify.
// Navigation non bloquée (choix UX assumé en review 49-11 F-06) : les réglages restent enregistrables,
// la synchronisation démarrera seulement une fois la connexion établie (step 1).
if (!$wizardIsConnected) {
	print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 15px; text-align: left;">';
	print '<i class="fa fa-exclamation-triangle" style="margin-right:6px;"></i>';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardStoreNotConnectedWarning"));
	print '</div>';
}

// Story 49-11 T4 — Notice réglages communs pour boutique secondaire
// CORRECTION VALIDATE P2 : null-check explicite sur $currentAdminStore
$step2IsSecondary = ($currentAdminStore !== null && !$currentAdminStore->is_default && count($wizardStores) > 1);
if ($step2IsSecondary) {
	print '<div class="d2s-alert-banner d2s-alert--info" style="margin-bottom:12px;">';
	print '<i class="fa fa-info-circle" style="margin-right:6px;"></i>';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardStep2CommonSettings"));
	print '</div>';
}

// Le formulaire step 2 : si boutique secondaire, on bloque la soumission (onsubmit=false)
$step2Onsubmit = $step2IsSecondary ? ' onsubmit="return false;"' : '';
print '<form method="POST" action="' . $wizardUrl . '" id="d2s-form-step2-config"' . $step2Onsubmit . '>';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save_step2_config">';

// FIX 9 (LOW 49-11) : rendre les champs réellement lecture seule pour boutique secondaire via <fieldset disabled>
if ($step2IsSecondary) {
	print '<fieldset disabled style="border:none;padding:0;">';
}
print '<div class="fichecenter">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep2Description")) . '</th>';
print '</tr>';

// Select entrepot (obligatoire) — AC1
print '<tr class="oddeven">';
print '<td class="titlefieldcreate fieldrequired">' . dol_escape_htmltag($langs->trans("WizardStep2DefaultWarehouse")) . '</td>';
print '<td>';
if ($formproduct !== null) {
	print $formproduct->selectWarehouses(getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE_ID'), 'default_warehouse_id', '', 1);
} else {
	// Fallback: build warehouse select manually for older Dolibarr without FormProduct
	$sqlWh = "SELECT e.rowid, e.ref, e.lieu";
	$sqlWh .= " FROM " . MAIN_DB_PREFIX . "entrepot e";
	$sqlWh .= " WHERE e.statut = 1";
	$sqlWh .= " AND e.entity IN (" . getEntity('stock') . ")";
	$sqlWh .= " ORDER BY e.ref";
	$resqlWh = $db->query($sqlWh);
	$warehouses = array();
	if ($resqlWh) {
		while ($objWh = $db->fetch_object($resqlWh)) {
			$label = $objWh->ref;
			if (!empty($objWh->lieu)) {
				$label .= ' (' . $objWh->lieu . ')';
			}
			$warehouses[$objWh->rowid] = $label;
		}
		$db->free($resqlWh);
	}
	print $form->selectarray('default_warehouse_id', $warehouses, getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE_ID'), 1, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
}
print '</td>';
print '</tr>';

// Select utilisateur CRON/Webhook (obligatoire) — AC1
print '<tr class="oddeven">';
print '<td class="titlefieldcreate fieldrequired">' . dol_escape_htmltag($langs->trans("WizardStep2CronUser")) . '</td>';
print '<td>';
// Query admin users only (statut=1, admin=1)
$sqlUsers = "SELECT u.rowid, u.login, u.firstname, u.lastname";
$sqlUsers .= " FROM " . MAIN_DB_PREFIX . "user u";
$sqlUsers .= " WHERE u.statut = 1 AND u.admin = 1";
$sqlUsers .= " AND u.entity IN (0, " . ((int) $conf->entity) . ")";
$sqlUsers .= " ORDER BY u.lastname, u.firstname";
$resqlUsers = $db->query($sqlUsers);
$adminUsers = array();
if ($resqlUsers) {
	while ($objUser = $db->fetch_object($resqlUsers)) {
		$label = $objUser->firstname . ' ' . $objUser->lastname;
		if (empty(trim($label))) {
			$label = $objUser->login;
		}
		$adminUsers[$objUser->rowid] = $label . ' (' . $objUser->login . ')';
	}
	$db->free($resqlUsers);
}
print $form->selectarray('webhook_user_id', $adminUsers, getDolGlobalInt('DOLI2SHOP_WEBHOOK_USER_ID'), 1, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td>';
print '</tr>';

// Select catégorie clients Shopify (optionnel)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("DolibarrCustomerCategory")) . '</td>';
print '<td>';
$customerCategories = $form->select_all_categories(Categorie::TYPE_CUSTOMER, '', 'parent', 64, 0, 1);
$customerCategories = array('' => $langs->trans("None")) + $customerCategories;
print $form->selectarray('dolibarr_customer_category', $customerCategories, getDolGlobalString('DOLI2SHOP_DOLIBARR_CUSTOMER_CATEGORY'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("DolibarrCustomerCategoryHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// Mapping type de tiers B2B/B2C (Story 13.1)
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("ThirdPartyTypeMapping")) . '</th>';
print '</tr>';

$typentArray = $formcompany->typent_array(0);
$typentOptions = array('0' => $langs->trans("DoNotSet")) + $typentArray;

// Type de tiers quand company est renseigné (B2B)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("TypentWithCompany")) . '</td>';
print '<td>';
print $form->selectarray('typent_with_company', $typentOptions, getDolGlobalInt('DOLI2SHOP_TYPENT_WITH_COMPANY', 0), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("TypentWithCompanyHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// Type de tiers quand company est vide (B2C)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("TypentWithoutCompany")) . '</td>';
print '<td>';
print $form->selectarray('typent_without_company', $typentOptions, getDolGlobalInt('DOLI2SHOP_TYPENT_WITHOUT_COMPANY', 0), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("TypentWithoutCompanyHelp")) . '">?</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>'; // fichecenter
if ($step2IsSecondary) {
	print '</fieldset>';
}

print '</form>';

print '</div>'; // panel 2

// ===========================================
// PANEL — STEP 3: Configuration traitement commandes (Story 15.3)
// ===========================================

// FIX 2 (HIGH 49-11) : pré-résoudre les valeurs du step 3 pour éviter de pré-remplir avec les valeurs globales
// pour une boutique secondaire (trompeur). Pour secondaire → StoreSettings::get() avec fallback global.
$step3IsSecondary = ($currentAdminStore !== null && !$currentAdminStore->is_default && count($wizardStores) > 1);
if ($step3IsSecondary && $wizardStoreId > 0) {
	require_once dirname(__FILE__) . '/../class/storesettings.class.php';
	$step3Settings = new StoreSettings($db);
	$v3OrderPrefix        = $step3Settings->get($wizardStoreId, 'ORDER_PREFIX',             getDolGlobalString('DOLI2SHOP_ORDER_PREFIX'));
	$v3DeliveryDelay      = (int) $step3Settings->get($wizardStoreId, 'DELIVERY_DELAY',     getDolGlobalInt('DOLI2SHOP_DELIVERY_DELAY', 7));
	$v3DeliveryDelayType  = $step3Settings->get($wizardStoreId, 'DELIVERY_DELAY_TYPE',      getDolGlobalString('DOLI2SHOP_DELIVERY_DELAY_TYPE', 'working'));
	$v3OrderOrigin        = (int) $step3Settings->get($wizardStoreId, 'ORDER_ORIGIN',       getDolGlobalInt('DOLI2SHOP_ORDER_ORIGIN'));
	$v3PaymentTerms       = (int) $step3Settings->get($wizardStoreId, 'PAYMENT_TERMS',      getDolGlobalInt('DOLI2SHOP_PAYMENT_TERMS'));
	$v3DefaultShippingId  = (int) $step3Settings->get($wizardStoreId, 'DEFAULT_SHIPPING_METHOD_ID', getDolGlobalInt('DOLI2SHOP_DEFAULT_SHIPPING_METHOD_ID'));
	$v3BuyingPriceSource  = $step3Settings->get($wizardStoreId, 'BUYING_PRICE_SOURCE',      getDolGlobalString('DOLI2SHOP_BUYING_PRICE_SOURCE', 'pmp'));
	$v3SyncNonPaid        = (bool) $step3Settings->get($wizardStoreId, 'SYNC_NON_PAID_ORDERS', getDolGlobalBool('DOLI2SHOP_SYNC_NON_PAID_ORDERS'));
	$v3ShippingProductId  = (int) $step3Settings->get($wizardStoreId, 'SHIPPING_PRODUCT_ID', getDolGlobalInt('DOLI2SHOP_SHIPPING_PRODUCT_ID'));
	$v3TipProductId       = (int) $step3Settings->get($wizardStoreId, 'TIP_PRODUCT_ID',     getDolGlobalInt('DOLI2SHOP_TIP_PRODUCT_ID'));
	$v3DefaultDeliveryDays = (int) $step3Settings->get($wizardStoreId, 'DEFAULT_DELIVERY_DAYS', getDolGlobalInt('DOLI2SHOP_DEFAULT_DELIVERY_DAYS', 3));
	$v3OrderBankAccount   = (int) $step3Settings->get($wizardStoreId, 'ORDER_BANK_ACCOUNT', getDolGlobalInt('DOLI2SHOP_ORDER_BANK_ACCOUNT'));
	$v3AutoExpedition     = (bool) $step3Settings->get($wizardStoreId, 'AUTO_CREATE_EXPEDITION', getDolGlobalInt('DOLI2SHOP_AUTO_CREATE_EXPEDITION', 0));
	$v3AutoCloseOrder     = (bool) $step3Settings->get($wizardStoreId, 'AUTO_CLOSE_ORDER',  getDolGlobalInt('DOLI2SHOP_AUTO_CLOSE_ORDER', 1));
	$v3AutoCreateInvoice  = (bool) $step3Settings->get($wizardStoreId, 'AUTO_CREATE_INVOICE', getDolGlobalInt('DOLI2SHOP_AUTO_CREATE_INVOICE', 1));
	$v3AutoValidateInvoice = (bool) $step3Settings->get($wizardStoreId, 'AUTO_VALIDATE_INVOICE', getDolGlobalInt('DOLI2SHOP_AUTO_VALIDATE_INVOICE', 1));
	$v3AutoCreatePayment  = (bool) $step3Settings->get($wizardStoreId, 'AUTO_CREATE_PAYMENT', getDolGlobalInt('DOLI2SHOP_AUTO_CREATE_PAYMENT', 1));
	$v3AutoClassifyBilled = (bool) $step3Settings->get($wizardStoreId, 'AUTO_CLASSIFY_BILLED', getDolGlobalInt('DOLI2SHOP_AUTO_CLASSIFY_BILLED', 1));
} else {
	// Boutique par défaut : valeurs globales
	$v3OrderPrefix        = getDolGlobalString('DOLI2SHOP_ORDER_PREFIX');
	$v3DeliveryDelay      = getDolGlobalInt('DOLI2SHOP_DELIVERY_DELAY', 7);
	$v3DeliveryDelayType  = getDolGlobalString('DOLI2SHOP_DELIVERY_DELAY_TYPE', 'working');
	$v3OrderOrigin        = getDolGlobalInt('DOLI2SHOP_ORDER_ORIGIN');
	$v3PaymentTerms       = getDolGlobalInt('DOLI2SHOP_PAYMENT_TERMS');
	$v3DefaultShippingId  = getDolGlobalInt('DOLI2SHOP_DEFAULT_SHIPPING_METHOD_ID');
	$v3BuyingPriceSource  = getDolGlobalString('DOLI2SHOP_BUYING_PRICE_SOURCE', 'pmp');
	$v3SyncNonPaid        = getDolGlobalBool('DOLI2SHOP_SYNC_NON_PAID_ORDERS');
	$v3ShippingProductId  = getDolGlobalInt('DOLI2SHOP_SHIPPING_PRODUCT_ID');
	$v3TipProductId       = getDolGlobalInt('DOLI2SHOP_TIP_PRODUCT_ID');
	$v3DefaultDeliveryDays = getDolGlobalInt('DOLI2SHOP_DEFAULT_DELIVERY_DAYS', 3);
	$v3OrderBankAccount   = getDolGlobalInt('DOLI2SHOP_ORDER_BANK_ACCOUNT');
	$v3AutoExpedition     = getDolGlobalInt('DOLI2SHOP_AUTO_CREATE_EXPEDITION', 0);
	$v3AutoCloseOrder     = getDolGlobalInt('DOLI2SHOP_AUTO_CLOSE_ORDER', 1);
	$v3AutoCreateInvoice  = getDolGlobalInt('DOLI2SHOP_AUTO_CREATE_INVOICE', 1);
	$v3AutoValidateInvoice = getDolGlobalInt('DOLI2SHOP_AUTO_VALIDATE_INVOICE', 1);
	$v3AutoCreatePayment  = getDolGlobalInt('DOLI2SHOP_AUTO_CREATE_PAYMENT', 1);
	$v3AutoClassifyBilled = getDolGlobalInt('DOLI2SHOP_AUTO_CLASSIFY_BILLED', 1);
}

print '<div id="d2s-wizard-panel-3" class="d2s-wizard-panel"' . ($step != 3 ? ' style="display:none" aria-hidden="true"' : ' aria-hidden="false"') . '>';

// Story 50-8 — Encart persistant : boutique active non connectée (cf. panel 2 pour le détail).
if (!$wizardIsConnected) {
	print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 15px; text-align: left;">';
	print '<i class="fa fa-exclamation-triangle" style="margin-right:6px;"></i>';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardStoreNotConnectedWarning"));
	print '</div>';
}

print '<form method="POST" action="' . $wizardUrl . '" id="d2s-form-step3-config">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save_step3_config">';
// FIX 4 (HIGH 49-11) : transmettre la boutique par POST pour éviter la résolution par session seule (multi-onglets)
print '<input type="hidden" name="wizard_store_id" value="' . (int) $wizardStoreId . '">';

print '<div class="fichecenter">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

// Description row
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep3Description")) . '</th>';
print '</tr>';

// --- Section Configuration commandes ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("OrderConfiguration")) . '</th>';
print '</tr>';

// Préfixe commandes (FIX 2 : $v3OrderPrefix pré-résolu ci-dessus)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("OrderPrefix")) . '</td>';
print '<td><input type="text" name="order_prefix" value="' . dol_escape_htmltag($v3OrderPrefix) . '" size="10">';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("OrderPrefixHelp")) . '">?</span></td>';
print '</tr>';

// Délai de livraison + type
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("DeliveryDelay")) . '</td>';
print '<td>';
print '<input type="number" name="delivery_delay" value="' . (int) $v3DeliveryDelay . '" size="5">';
print ' ' . $form->selectarray('delivery_delay_type', array(
	'working' => $langs->trans("WorkingDays"),
	'calendar' => $langs->trans("CalendarDays")
), $v3DeliveryDelayType, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth200');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("DeliveryDelayHelp")) . '">?</span></td>';
print '</tr>';

// Origine commande
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("OrderOrigin")) . '</td>';
print '<td>';
print $form->selectInputReason($v3OrderOrigin, 'order_origin', -1, -1, 1);
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("OrderOriginHelp")) . '">?</span></td>';
print '</tr>';

// Conditions de règlement
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("PaymentTerms")) . '</td>';
print '<td>';
print $form->getSelectConditionsPaiements($v3PaymentTerms, 'payment_terms', -1, -1, 1);
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("PaymentTermsHelp")) . '">?</span></td>';
print '</tr>';

// Méthode d'expédition par défaut
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("DefaultShippingMethod")) . '</td>';
print '<td>';
print $form->selectShippingMethod($v3DefaultShippingId, 'default_shipping_method_id', '', 2, 1);
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("DefaultShippingMethodHelp")) . '">?</span></td>';
print '</tr>';

// Source prix d'achat
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("BuyingPriceSource")) . '</td>';
print '<td>';
print $form->selectarray('buying_price_source', array(
	'pmp' => $langs->trans("BuyingPriceSourcePMP"),
	'cost_price' => $langs->trans("BuyingPriceSourceCostPrice")
), $v3BuyingPriceSource, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("BuyingPriceSourceHelp")) . '">?</span></td>';
print '</tr>';

// Sync commandes non payées
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("SyncNonPaidOrders")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="sync_non_paid_orders" value="1" ' . ($v3SyncNonPaid ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("SyncNonPaidOrdersHelp")) . '">?</span></td>';
print '</tr>';

// Service expéditions
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("ShippingProduct")) . '</td>';
print '<td>';
$form->select_produits($v3ShippingProductId, 'shipping_product_id', 1, 0, 0, 1, 2, '', 0, array(), 0, '1', 0, 'maxwidth300');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("ShippingProductIdHelp")) . '">?</span></td>';
print '</tr>';

// Service pourboires
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("TipProduct")) . '</td>';
print '<td>';
$form->select_produits($v3TipProductId, 'tip_product_id', 1, 0, 0, 1, 2, '', 0, array(), 0, '1', 0, 'maxwidth300');
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("TipProductIdHelp")) . '">?</span></td>';
print '</tr>';

// Jours de livraison par défaut
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("DefaultDeliveryDays")) . '</td>';
print '<td><input type="number" name="default_delivery_days" value="' . (int) $v3DefaultDeliveryDays . '" min="1" max="30">';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("DefaultDeliveryDaysHelp")) . '">?</span></td>';
print '</tr>';

// Compte bancaire
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("OrderBankAccount")) . '</td>';
print '<td>';
$form->select_comptes($v3OrderBankAccount, 'order_bank_account', 0, '', 1);
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("OrderBankAccountHelp")) . '">?</span></td>';
print '</tr>';

// --- Section Expeditions ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep3SectionExpeditions")) . '</th>';
print '</tr>';

// Toggle: Auto create expedition (default OFF)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep3AutoCreateExpedition")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="auto_create_expedition" value="1" ' . ($v3AutoExpedition ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("WizardStep3AutoCreateExpeditionHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// Toggle: Auto close order after expedition (default ON)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep3AutoCloseOrder")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="auto_close_order" value="1" ' . ($v3AutoCloseOrder ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("WizardStep3AutoCloseOrderHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// --- Section Facturation ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep3SectionBilling")) . '</th>';
print '</tr>';

// Toggle: Auto create invoice (default ON)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep3AutoCreateInvoice")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="auto_create_invoice" value="1" ' . ($v3AutoCreateInvoice ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("WizardStep3AutoCreateInvoiceHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// Toggle: Auto validate invoice (default ON)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep3AutoValidateInvoice")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="auto_validate_invoice" value="1" ' . ($v3AutoValidateInvoice ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("WizardStep3AutoValidateInvoiceHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// --- Section Paiement ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep3SectionPayment")) . '</th>';
print '</tr>';

// Toggle: Auto create payment (default ON)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep3AutoCreatePayment")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="auto_create_payment" value="1" ' . ($v3AutoCreatePayment ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("WizardStep3AutoCreatePaymentHelp")) . '">?</span>';
print '</td>';
print '</tr>';

// Toggle: Auto classify billed (default ON)
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep3AutoClassifyBilled")) . '</td>';
print '<td>';
print '<label class="toggle-switch">';
print '<input type="checkbox" name="auto_classify_billed" value="1" ' . ($v3AutoClassifyBilled ? 'checked' : '') . '>';
print '<span class="toggle-slider"></span>';
print '</label>';
print ' <span class="help-icon" title="' . dol_escape_htmltag($langs->trans("WizardStep3AutoClassifyBilledHelp")) . '">?</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>'; // fichecenter

print '</form>';

print '</div>'; // panel 3

// ===========================================
// PANEL — STEP 4: Correspondances expédition (Story 22.3)
// ===========================================

print '<div id="d2s-wizard-panel-4" class="d2s-wizard-panel"' . ($step != 4 ? ' style="display:none" aria-hidden="true"' : ' aria-hidden="false"') . '>';

// Story 50-8 — Encart persistant : boutique active non connectée (cf. panel 2 pour le détail).
if (!$wizardIsConnected) {
	print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 15px; text-align: left;">';
	print '<i class="fa fa-exclamation-triangle" style="margin-right:6px;"></i>';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardStoreNotConnectedWarning"));
	print '</div>';
}

// Story 48-4 : sélecteur de boutique (phase par boutique). Affiché seulement en multi-boutiques.
if (count($wizardStores) > 1) {
	print '<div class="info" style="margin-bottom:12px;">';
	print '<label><strong>'.$langs->trans("WizardConfigureStore").'</strong> </label> ';
	print '<select class="flat" onchange="window.location.href=\''.dol_escape_js(dol_buildpath('/doli2shop/admin/setup_wizard.php', 1)).'?step=4&wizard_store_id=\'+this.value">';
	foreach ($wizardStores as $ws) {
		print '<option value="'.(int)$ws->rowid.'"'.((int)$ws->rowid === $wizardStoreId ? ' selected' : '').'>'.dol_escape_htmltag($ws->label).'</option>';
	}
	print '</select>';
	print ' <span class="opacitymedium">'.$langs->trans("WizardConfigureStoreHelp").'</span>';
	print '</div>';
}

print '<form method="POST" action="' . $wizardUrl . '" id="d2s-form-step4-shipping">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save_step4_shipping">';
print '<input type="hidden" name="wizard_store_id" value="'.(int)$wizardStoreId.'">';

// Avertissement si aucune méthode d'expédition Dolibarr
if (empty($shippingmethods)) {
	print '<div class="warning">';
	print '<i class="fa fa-exclamation-triangle"></i> ';
	print '<strong>'.$langs->trans("NoDolibarrShippingMethodsAvailable").'</strong><br>';
	print $langs->trans("NoDolibarrShippingMethodsAvailableHelp");
	print '</div><br>';
}

// Include partagé 3 sections (story 22.2) — scope par boutique (Story 48-4)
$mappingStoreId = $wizardStoreId;
include dol_buildpath('/doli2shop/admin/_shipping_mapping_table.inc.php', 0);

print '</form>';

print '</div>'; // panel 4

// ===========================================
// PANEL — STEP 5: Correspondances paiement (Story 22.3)
// ===========================================

print '<div id="d2s-wizard-panel-5" class="d2s-wizard-panel"' . ($step != 5 ? ' style="display:none" aria-hidden="true"' : ' aria-hidden="false"') . '>';

// Story 50-8 — Encart persistant : boutique active non connectée (cf. panel 2 pour le détail).
if (!$wizardIsConnected) {
	print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 15px; text-align: left;">';
	print '<i class="fa fa-exclamation-triangle" style="margin-right:6px;"></i>';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardStoreNotConnectedWarning"));
	print '</div>';
}

// Story 48-4 : sélecteur de boutique (phase par boutique). Multi-boutiques uniquement.
if (count($wizardStores) > 1) {
	print '<div class="info" style="margin-bottom:12px;">';
	print '<label><strong>'.$langs->trans("WizardConfigureStore").'</strong> </label> ';
	print '<select class="flat" onchange="window.location.href=\''.dol_escape_js(dol_buildpath('/doli2shop/admin/setup_wizard.php', 1)).'?step=5&wizard_store_id=\'+this.value">';
	foreach ($wizardStores as $ws) {
		print '<option value="'.(int)$ws->rowid.'"'.((int)$ws->rowid === $wizardStoreId ? ' selected' : '').'>'.dol_escape_htmltag($ws->label).'</option>';
	}
	print '</select>';
	print ' <span class="opacitymedium">'.$langs->trans("WizardConfigureStoreHelp").'</span>';
	print '</div>';
}

print '<form method="POST" action="' . $wizardUrl . '" id="d2s-form-step5-payment">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save_step5_payment">';
print '<input type="hidden" name="wizard_store_id" value="'.(int)$wizardStoreId.'">';

// Avertissement si aucune méthode de paiement Dolibarr
if (empty($paymentmethods)) {
	print '<div class="warning">';
	print '<i class="fa fa-exclamation-triangle"></i> ';
	print '<strong>'.$langs->trans("NoDolibarrPaymentMethodsAvailable").'</strong><br>';
	print $langs->trans("NoDolibarrPaymentMethodsAvailableHelp");
	print '</div><br>';
}

print '<div class="opacitymedium">';
print '<i class="fa fa-info-circle"></i> ' . $langs->trans("PaymentMethodsMappingDesc");
print '</div>';

print '<table id="payment-mappings" class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="titlefieldcreate">'.$langs->trans("ShopifyPaymentMethod").'</th>';
print '<th>'.$langs->trans("DolibarrPaymentMethod").'</th>';
print '<th><a class="butActionNew" id="addPaymentMapping" href="#"'.(!empty($paymentmethods) ? '' : ' style="pointer-events:none;opacity:0.5"').'><i class="fa fa-plus-circle" title="'.$langs->trans("AddPaymentMapping").'"></i></a></th>';
print '</tr>';

// Lignes existantes — scope par boutique (Story 48-4)
$payStoreFilter = ($wizardStoreId > 0) ? (" AND fk_store = ".(int)$wizardStoreId) : " AND fk_store = 0";
$sql = "SELECT * FROM ".MAIN_DB_PREFIX."doli2shop_payments WHERE entity = ".(int)$conf->entity." AND active = 1".$payStoreFilter;
$resql = $db->query($sql);
$hasPaymentRows = false;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$hasPaymentRows = true;
		print '<tr>';
		print '<td><input type="text" name="shopify_payment_method[]" value="'.dol_escape_htmltag($obj->shopify_payment_method).'" class="minwidth300" placeholder="'.$langs->trans("Example").': card, bank, paypal"></td>';
		print '<td><select name="dolibarr_payment_id[]" class="flat minwidth200">';
		foreach ($paymentmethods as $id => $label) {
			print '<option value="'.(int)$id.'"'.($obj->dolibarr_payment_id == $id ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
		}
		print '</select></td>';
		print '<td class="center"><i class="fa fa-trash payment-delete" title="'.$langs->trans("Delete").'"></i></td>';
		print '</tr>';
	}
	$db->free($resql);
}

if (!$hasPaymentRows) {
	print '<tr class="oddeven payment-empty-msg"><td colspan="3" class="opacitymedium center">';
	print '<i class="fa fa-info-circle"></i> ' . $langs->trans("NoPaymentMethodMappingDefined") . '<br>';
	print $langs->trans("ClickAddButtonToCreate");
	print '</td></tr>';
}

print '</table>';

// JavaScript pour le tableau paiement
print '<script>';
print 'document.addEventListener("DOMContentLoaded", function() {';
	print 'var paymentOptionsHtml = \'';
	foreach ($paymentmethods as $id => $label) {
		print '<option value="'.dol_escape_htmltag($id).'">'.dol_escape_htmltag($label).'</option>';
	}
	print '\';';
	print 'function attachPaymentDeleteHandlers() {';
	print '  document.querySelectorAll(".payment-delete").forEach(function(btn) {';
	print '    btn.onclick = function() {';
	print '      if (confirm("'.$langs->trans("ConfirmDeleteMapping").'")) { this.closest("tr").remove(); }';
	print '    };';
	print '  });';
	print '}';
	print 'attachPaymentDeleteHandlers();';
	print 'document.getElementById("addPaymentMapping").addEventListener("click", function(e) {';
	print '  e.preventDefault();';
	print '  var emptyRow = document.querySelector(".payment-empty-msg");';
	print '  if (emptyRow) emptyRow.remove();';
	print '  var table = document.getElementById("payment-mappings");';
	print '  var newRow = table.insertRow(-1);';
	print '  var cellHtml = \'<td><input type="text" name="shopify_payment_method[]" class="minwidth300" placeholder="'.$langs->trans("Example").': card, bank, paypal"></td>\';';
	print '  cellHtml += \'<td><select name="dolibarr_payment_id[]" class="flat minwidth200">\' + paymentOptionsHtml + \'</select></td>\';';
	print '  cellHtml += \'<td class="center"><i class="fa fa-trash payment-delete" title="'.$langs->trans("Delete").'"></i></td>\';';
	print '  newRow.innerHTML = cellHtml;';
	print '  attachPaymentDeleteHandlers();';
	print '});';
print '});';
print '</script>';

print '</form>';

print '</div>'; // panel 5

// ===========================================
// PANEL — STEP 6: Activation et Validation (Story 15.4, renumbered from step 4)
// ===========================================

print '<div id="d2s-wizard-panel-6" class="d2s-wizard-panel"' . ($step != 6 ? ' style="display:none" aria-hidden="true"' : ' aria-hidden="false"') . '>';

// Story 50-8 — Encart persistant : boutique active non connectée (cf. panel 2 pour le détail).
if (!$wizardIsConnected) {
	print '<div class="d2s-alert-banner d2s-alert--warning" style="margin-bottom: 15px; text-align: left;">';
	print '<i class="fa fa-exclamation-triangle" style="margin-right:6px;"></i>';
	print dol_escape_htmltag($langs->transnoentities("Doli2ShopWizardStoreNotConnectedWarning"));
	print '</div>';
}

print '<div class="fichecenter">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

// --- Section: Verification de la configuration ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep4SectionConfig")) . '</th>';
print '</tr>';

// Connexion Shopify
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep4ConnectionLabel")) . '</td>';
print '<td>';
if ($isConnected) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardStep4ConnectionOk", $storeHostname)) . '</span>';
} else {
	print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("WizardStep4ConnectionKo")) . '</span>';
}
print '</td>';
print '</tr>';

// Entrepot
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep2DefaultWarehouse")) . '</td>';
print '<td>';
if ($hasWarehouse) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardStep4WarehouseOk")) . '</span>';
} else {
	print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("WizardStep4WarehouseKo")) . '</span>';
}
print '</td>';
print '</tr>';

// Utilisateur CRON
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep2CronUser")) . '</td>';
print '<td>';
if ($hasCronUser) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardStep4CronUserOk")) . '</span>';
} else {
	print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("WizardStep4CronUserKo")) . '</span>';
}
print '</td>';
print '</tr>';

// --- Section: Correspondances mappings (Story 19.4 → 22.3) ---
// Compute shipping and payment counts for step 6 recap
$shippingCount = 0;
$resCS = $db->query("SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "doli2shop_shipping_rules WHERE active = 1 AND entity = " . (int)$conf->entity);
if ($resCS && ($oCS = $db->fetch_object($resCS))) {
	$shippingCount = (int)$oCS->cnt;
}
$paymentCount = 0;
$resCP = $db->query("SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "doli2shop_payments WHERE entity = " . (int)$conf->entity);
if ($resCP && ($oCP = $db->fetch_object($resCP))) {
	$paymentCount = (int)$oCP->cnt;
}

print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep2Mappings")) . '</th>';
print '</tr>';

// Shipping mappings status
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("ShippingMethodsMapping")) . '</td>';
print '<td>';
if ($shippingCount > 0) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardMappingsCount", $shippingCount)) . '</span>';
} else {
	print '<span class="badge badge-status1">' . dol_escape_htmltag($langs->trans("WizardMappingsNone")) . '</span>';
	print ' <span class="opacitymedium" style="font-size:12px;">' . dol_escape_htmltag($langs->trans("WizardShippingAutoDetectWarning")) . '</span>';
}
print '</td>';
print '</tr>';

// Payment mappings status
print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("PaymentMethodsMapping")) . '</td>';
print '<td>';
if ($paymentCount > 0) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardMappingsCount", $paymentCount)) . '</span>';
} else {
	print '<span class="badge badge-status1">' . dol_escape_htmltag($langs->trans("WizardMappingsNone")) . '</span>';
}
print '</td>';
print '</tr>';

// --- Section: Webhooks Shopify ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep4SectionWebhooks")) . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep4WebhooksLabel")) . '</td>';
print '<td>';
if ($webhooksActive > 0 && $webhooksActive >= $webhooksTotal) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardStep4WebhooksCount", $webhooksActive, $webhooksTotal)) . '</span>';
} elseif ($webhooksActive > 0) {
	print '<span class="badge badge-status1">' . dol_escape_htmltag($langs->trans("WizardStep4WebhooksCount", $webhooksActive, $webhooksTotal)) . '</span>';
} else {
	print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("WizardStep4WebhooksCount", 0, 0)) . '</span>';
}
// Bouton activer seulement si pas tous actifs
if ($webhooksActive < $webhooksTotal) {
	print ' ';
	print '<form method="POST" action="' . $wizardUrl . '" style="display:inline;">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="activate_webhooks">';
	print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("WizardStep4ActivateWebhooks")) . '">';
	print '</form>';
}
print '</td>';
print '</tr>';

// --- Section: CRONs de traitement ---
print '<tr class="liste_titre">';
print '<th colspan="2">' . dol_escape_htmltag($langs->trans("WizardStep4SectionCrons")) . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefieldcreate">' . dol_escape_htmltag($langs->trans("WizardStep4CronsLabel")) . '</td>';
print '<td>';
if ($cronsActive >= $cronsTotal) {
	print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("WizardStep4CronsCount", $cronsActive, $cronsTotal)) . '</span>';
} else {
	print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("WizardStep4CronsCount", $cronsActive, $cronsTotal)) . '</span>';
}
// Bouton activer seulement si pas tous actifs
if ($cronsActive < $cronsTotal) {
	print ' ';
	print '<form method="POST" action="' . $wizardUrl . '" style="display:inline;">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="activate_crons">';
	print '<input type="submit" class="button" value="' . dol_escape_htmltag($langs->trans("WizardStep4ActivateCrons")) . '">';
	print '</form>';
}
print '</td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>'; // fichecenter

// Note: Bouton "Terminer" intégré dans la zone de navigation commune (ci-dessous)
$wizardCompleted = getDolGlobalInt('DOLI2SHOP_WIZARD_COMPLETED', 0);

print '</div>'; // panel 6

// ===========================================
// NAVIGATION BUTTONS (AC1)
// ===========================================

print '<div class="center" style="margin-top: 20px;">';

// Previous button
if ($step > 1) {
	print '<form method="POST" action="' . $wizardUrl . '" style="display:inline-block; margin-right: 10px;">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="goto_step">';
	print '<input type="hidden" name="goto" value="' . ($step - 1) . '">';
	// FIX 5b (HIGH 49-11) : propager wizard_store_id dans les boutons Précédent (steps 4 et 5)
	if ($step >= 4) {
		print '<input type="hidden" name="wizard_store_id" value="' . (int) $wizardStoreId . '">';
	}
	print '<input type="submit" id="d2s-wizard-prev" class="butAction" value="' . dol_escape_htmltag($langs->trans("WizardPrevious")) . '" />';
	print '</form>';
}

// Next button — steps 2-5 submit their own form, step 6 shows "Terminer"
if ($step < 6) {
	$nextDisabled = ($step == 1 && !$isConnected) ? ' disabled="disabled"' : '';
	if ($step == 2) {
		// Step 2: pour boutique secondaire → navigation directe vers step 3 (pas de sauvegarde)
		// Pour boutique par défaut → soumission du formulaire step 2
		if ($step2IsSecondary) {
			print '<form method="POST" action="' . $wizardUrl . '" style="display:inline-block;">';
			print '<input type="hidden" name="token" value="' . newToken() . '">';
			print '<input type="hidden" name="action" value="goto_step">';
			print '<input type="hidden" name="goto" value="3">';
			print '<input type="submit" id="d2s-wizard-next" class="butAction" value="' . dol_escape_htmltag($langs->trans("WizardNext")) . '" />';
			print '</form>';
		} else {
			// Step 2: submit the config form via HTML5 form attribute
			print '<input type="submit" id="d2s-wizard-next" class="butAction" form="d2s-form-step2-config" value="' . dol_escape_htmltag($langs->trans("WizardNext")) . '" />';
		}
	} elseif ($step == 3) {
		// Step 3: submit the order config form via HTML5 form attribute
		print '<input type="submit" id="d2s-wizard-next" class="butAction" form="d2s-form-step3-config" value="' . dol_escape_htmltag($langs->trans("WizardNext")) . '" />';
	} elseif ($step == 4) {
		// Step 4: submit the shipping form via HTML5 form attribute
		print '<input type="submit" id="d2s-wizard-next" class="butAction" form="d2s-form-step4-shipping" value="' . dol_escape_htmltag($langs->trans("WizardNext")) . '" />';
	} elseif ($step == 5) {
		// Step 5: submit the payment form via HTML5 form attribute
		print '<input type="submit" id="d2s-wizard-next" class="butAction" form="d2s-form-step5-payment" value="' . dol_escape_htmltag($langs->trans("WizardNext")) . '" />';
	} else {
		print '<form method="POST" action="' . $wizardUrl . '" style="display:inline-block;">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="goto_step">';
		print '<input type="hidden" name="goto" value="' . ($step + 1) . '">';
		print '<input type="submit" id="d2s-wizard-next" class="butAction"' . $nextDisabled . ' value="' . dol_escape_htmltag($langs->trans("WizardNext")) . '" />';
		print '</form>';
	}
} elseif ($step == 6) {
	// Step 6: un seul bouton contextuel
	$adminUrl = dol_buildpath('/doli2shop/admin/setup.php', 1);
	if ($wizardCompleted) {
		// Wizard déjà terminé → juste aller au tableau de bord
		print '<a href="' . $adminUrl . '" class="butAction">' . dol_escape_htmltag($langs->trans("WizardStep4GoToDashboard")) . '</a>';
	} else {
		// Wizard pas encore terminé → bouton terminer (active webhooks+CRONs automatiquement)
		print '<form method="POST" action="' . $wizardUrl . '" style="display:inline-block;">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="complete_wizard">';
		print '<input type="submit" class="butAction" value="' . dol_escape_htmltag($langs->trans("WizardStep4Complete")) . '">';
		print '</form>';
	}
}

print '</div>';

// ===========================================
// JAVASCRIPT — Progressive Enhancement
// ===========================================

print '<script src="' . dol_buildpath('/doli2shop/js/wizard.js', 1) . '"></script>';
print "\n";
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
	if (typeof D2S !== 'undefined' && D2S.Wizard) {
		// Init JS wizard for stepper visual state and panel management only.
		// Navigation is server-side (POST forms) to ensure step persistence.
		// prevBtn/nextBtn NOT bound to avoid JS/server desync (M3 fix).
		D2S.Wizard.init({
			container: '.d2s-wizard-stepper',
			steps: ['#d2s-wizard-panel-1', '#d2s-wizard-panel-2', '#d2s-wizard-panel-3', '#d2s-wizard-panel-4', '#d2s-wizard-panel-5', '#d2s-wizard-panel-6'],
			startStep: <?php echo ($step - 1); ?>
		});

		// Validator for step 1: connection must be established (used by stepper circle clicks)
		D2S.Wizard.validators[0] = function() {
			return <?php echo $isConnected ? 'true' : 'false'; ?>;
		};

		// Note: Validators for steps 2-6 — no client-side validation needed (server-side POST)
	}
});
</script>
<?php

print dol_get_fiche_end();

print '</div>'; // doli2shop-page

llxFooter();
$db->close();
