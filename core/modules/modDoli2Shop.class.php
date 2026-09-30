<?php
/**
 * @file        core/modules/modDoli2Shop.class.php
 * @brief       Doli2Shop - Dolibarr-Shopify Integration Module descriptor
 *
 * @package     Doli2Shop
 * @category    core
 * @author      P'tite Tête
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU Public License
 * @version     2.6.0
 * @since       1.0.0
 * @link        https://doli2shop.ptitetete.org
 */

dol_include_once('/core/modules/DolibarrModules.class.php');
dol_include_once('/core/lib/files.lib.php');

// Compatibility functions loaded dynamically when needed (not during module scan)

/**
 *  Description and activation class for module Doli2Shop
 */
// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}

class modDoli2Shop extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        
        // Load module translations
        $langs->loadLangs(["doli2shop@doli2shop"]);

        // Module configuration
        $this->numero = 351003;
        $this->rights_class = 'doli2shop';
        $this->family = "interface";
        $this->module_position = '50';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Doli2ShopDescription";
        $this->descriptionlong = "Doli2ShopDescriptionLong";
        $this->editor_name = 'P\'tite Tête';
        $this->editor_url = 'https://doli2shop.ptitetete.org';
        // Version centralisée — source unique dans lib/version.lib.php
        dol_include_once('/doli2shop/lib/version.lib.php');

        $this->version = DOLI2SHOP_MODULE_VERSION;
        // URL vers le fichier contenant la dernière version du module
        // version.php retourne du texte brut par défaut (compatible Dolibarr)
        $this->url_last_version = 'https://doli2shop.ptitetete.org/version.php';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'shopify_color.png@doli2shop';

        // URL de vérification automatique configurée - Dolibarr gère la vérification

        // Module parts
        $this->module_parts = array(
            'triggers' => 1,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'theme' => 0,
            'css' => array('/doli2shop/css/doli2shop.css.php'),
            'js' => array('/doli2shop/js/doli2shop.js'),
            'hooks' => array('ordercard'),
            'moduleforexternal' => 0
        );

        // Data directories to create when module is enabled
        $this->dirs = array("/doli2shop/temp");

        // Config pages
        $this->config_page_url = array("setup.php@doli2shop");

        // Dependencies - Modules requis pour Doli2Shop
        $this->depends = array('modProduct', 'modSociete', 'modCommande', 'modStock', 'modCategorie');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("doli2shop@doli2shop");

        // Tables créées par le module (préfixe llx_ automatique)
        $this->tables = array(
            'doli2shop_products',              // Mapping produits Dolibarr ↔ Shopify
            'doli2shop_orders',                // Mapping commandes Dolibarr ↔ Shopify
            'doli2shop_order_status_dol2shop', // Mapping statuts Dolibarr → Shopify
            'doli2shop_order_status_shop2dol', // Mapping statuts Shopify → Dolibarr
            'doli2shop_collections',           // Mapping collections Shopify ↔ catégories Dolibarr
            'doli2shop_inventory',             // Mapping inventaire/emplacements
            'doli2shop_shipping',              // Mapping méthodes expédition (legacy, migrée vers shipping_rules)
            'doli2shop_shipping_rules',        // Mapping méthodes expédition v2.2.0 (rule_type title/tracking)
            'doli2shop_payments',              // Mapping méthodes paiement
            'doli2shop_webhooks',              // Webhooks enregistrés
            'doli2shop_webhook_events',        // Événements webhooks
            'doli2shop_image_hashes',          // Cache hash images produits (Story 8.2)
            'doli2shop_product_images',        // Mapping shopify_image_id <-> fichier importé (dédup import photos)
            'doli2shop_migrations',            // Historique migrations SQL
            'doli2shop_fulfillment_events',    // Story 40.1 — Historique tracking FulfillmentEvents
            'doli2shop_action_log',            // Story 38.3 — Journal des actions module
            'doli2shop_stores',                // Epic 47, Story 47-1 — Configuration multi-boutiques Shopify
            'doli2shop_store_settings'         // Story 49-3 — Réglages key-value par boutique (fallback global)
            // Note: doli2shop_storedetails retiré - table dépréciée depuis v2.1.1, migrée vers llx_const
        );
        
        // Prerequisites
        // v2.2.2 FIX: format array(major, minor, patch) — 3 elements requis pour eviter
        // les PHP Warnings "Undefined array key 2" dans DolibarrModules.class.php
        $this->phpmin = array(7, 4, 0);
        // Plancher de version : Dolibarr n'a PAS de LTS officielle (cf. politique upstream).
        // Les correctifs de securite ne sont soumis que pour les branches recentes, et les
        // branches 19, 20 et 21 ne recoivent plus de correctif depuis plus d'un an. On ne
        // declare donc que les branches reellement maintenues. Decision du 2026-09-10.
        $this->need_dolibarr_version = array(18, 0);
        $this->max_dolibarr_version = array(24, 0);

        // Array to add new pages in new tabs (pages accessibles via config_page_url)
		$this->tabs = array();

        // Dictionaries
		$this->dictionaries = array();

        // Boxes/Widgets
        $this->boxes = array();
        
        // Constants
        $this->const = array(
            // v2.2.0: Traitement immédiat des webhooks activé par défaut (temps réel)
            // Le CRON sert de filet de sécurité pour les events non traités
            array('SHOPIFY_WEBHOOK_IMMEDIATE_PROCESS', 'chaine', '1', 'Process webhooks immediately upon reception', 0, 'current', 1),
            // v2.3.0: Politique de retry webhook configurable (Story 36.2 / 36.3)
            array('DOLI2SHOP_WEBHOOK_MAX_TRIES', 'chaine', '5', 'Max webhook retry attempts before dead_letter', 0, 'current', 1),
            array('DOLI2SHOP_WEBHOOK_STUCK_TIMEOUT_MIN', 'chaine', '5', 'Minutes before stuck processing events are reset to pending', 0, 'current', 1),
            // v2.2.1: Système d'alertes proactif (Story 30.1)
            array('DOLI2SHOP_ALERT_ENABLED', 'chaine', '0', 'Enable proactive alert emails on critical errors', 0, 'current', 1),
            array('DOLI2SHOP_ALERT_EMAIL', 'chaine', '', 'Email address for alert notifications', 0, 'current', 1),
            array('DOLI2SHOP_ALERT_THRESHOLD', 'chaine', '3', 'Number of consecutive errors before sending alert', 0, 'current', 1),
            array('DOLI2SHOP_ALERT_TYPES', 'chaine', 'webhook,health', 'Comma-separated list of active alert types', 0, 'current', 1),
            array('DOLI2SHOP_ALERT_COOLDOWN', 'chaine', '60', 'Minimum minutes between alert emails (throttling)', 0, 'current', 1),
            // v2.2.1: Timestamps dernière alerte par type — créés dynamiquement par sendAlert(), déclarés ici pour cleanup uninstall
            array('DOLI2SHOP_ALERT_LAST_SENT_WEBHOOK', 'chaine', '0', 'Last webhook alert sent timestamp', 0, 'current', 1),
            array('DOLI2SHOP_ALERT_LAST_SENT_HEALTH', 'chaine', '0', 'Last health alert sent timestamp', 0, 'current', 1),
            array('DOLI2SHOP_ALERT_LAST_SENT_SYNC', 'chaine', '0', 'Last sync alert sent timestamp', 0, 'current', 1),
            // v2.3.0 Story 40.1: Flag activation persistance FulfillmentEvents (défaut=0, opt-in)
            array('DOLI2SHOP_FETCH_FULFILLMENT_EVENTS', 'chaine', '0', 'Persist Shopify FulfillmentEvents tracking history (Story 40.1)', 0, 'current', 1),
        );

        // Cronjobs - Standard Dolibarr CRON definition
        // v2.1.6: Noms cohérents avec préfixe module et direction claire
        $this->cronjobs = array(
            // Export produits Dolibarr → Shopify
            0 => array(
                'label' => 'Doli2ShopProductsExport',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/importproductscron.class.php',
                'objectname' => 'ImportProductsCron',
                'method' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopProductsExportDesc',
                'frequency' => 60,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 50
            ),
            // Import historique commandes Shopify → Dolibarr
            1 => array(
                'label' => 'Doli2ShopOrdersHistoricalImport',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifyhistoricalimportcron.class.php',
                'objectname' => 'ShopifyHistoricalImportCron',
                'method' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopOrdersHistoricalImportDesc',
                'frequency' => 30,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 50
            ),
            // Nettoyage automatique après import historique
            2 => array(
                'label' => 'Doli2ShopHistoricalCleanup',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifyhistoricalimportcleanupcron.class.php',
                'objectname' => 'ShopifyHistoricalImportCleanupCron',
                'method' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopHistoricalCleanupDesc',
                'frequency' => 60,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 90
            ),
            // Import produits Shopify → Dolibarr
            3 => array(
                'label' => 'Doli2ShopProductsImport',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifyproductimportcron.class.php',
                'objectname' => 'ShopifyProductImportCron',
                'method' => 'run',
                'parameters' => '50',
                'comment' => 'Doli2ShopProductsImportDesc',
                'frequency' => 30,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 50
            ),
            // Vérification santé webhooks et auto re-registration (Story 1.3)
            // v2.2.0: status=1 par défaut — monitoring webhooks toujours actif
            4 => array(
                'label' => 'Doli2ShopWebhookHealthCheck',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifywebhooks.class.php',
                'objectname' => 'ShopifyWebhooks',
                'method' => 'cronCheckWebhookHealth',
                'parameters' => '',
                'comment' => 'Doli2ShopWebhookHealthCheckDesc',
                'frequency' => 60,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 60
            ),
            // Rattrapage commandes manquées par polling (Story 1.4)
            5 => array(
                'label' => 'Doli2ShopOrdersCatchup',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifyordercatchupcron.class.php',
                'objectname' => 'ShopifyOrderCatchupCron',
                'method' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopOrdersCatchupDesc',
                'frequency' => 60,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 70
            ),
            // Rattrapage expéditions manquantes par polling fulfillments Shopify
            6 => array(
                'label' => 'Doli2ShopFulfillmentCatchup',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifyfulfillmentcatchupcron.class.php',
                'objectname' => 'ShopifyFulfillmentCatchupCron',
                'method' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopFulfillmentCatchupDesc',
                'frequency' => 30,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 75
            ),
            // Traitement asynchrone des webhooks en file d'attente
            // v2.2.0: status=1 par défaut — filet de sécurité toujours actif
            7 => array(
                'label' => 'Doli2ShopWebhookProcess',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/webhookprocesscron.class.php',
                'objectname' => 'WebhookProcessCron',
                'method' => 'run',
                'parameters' => '',
                'comment' => 'Doli2ShopWebhookProcessDesc',
                'frequency' => 5,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 50
            ),
            // v2.2.1: Vérification alertes proactives (Story 30.1)
            8 => array(
                'label' => 'Doli2ShopAlertCheck',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifyalertcron.class.php',
                'objectname' => 'ShopifyAlertCron',
                'method' => 'run_alerts',
                'parameters' => '',
                'comment' => 'Doli2ShopAlertCheckDesc',
                'frequency' => 15,
                'unitfrequency' => 60,
                'status' => 0,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 50
            ),
            // v2.3.0: Heartbeat — remontée quotidienne des métriques (Story 46.2)
            // status=1 par défaut : télémétrie active tant qu'une licence est configurée
            9 => array(
                'label' => 'Doli2ShopHeartbeat',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/doli2shopheartbeatcron.class.php',
                'objectname' => 'Doli2ShopHeartbeatCron',
                'method' => 'run_heartbeat',
                'parameters' => '',
                'comment' => 'Doli2ShopHeartbeatDesc',
                'frequency' => 1,
                'unitfrequency' => 86400,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 90
            ),
            // Story 51-1 (T6, AC10) : filet de sécurité — refresh préventif quotidien des jetons
            // OAuth Shopify expirables (rattrape les boutiques inactives, échéance 90j refresh_token).
            // status=1 par défaut : sans effet tant qu'aucune boutique n'a de token expirable (AC3).
            10 => array(
                'label' => 'Doli2ShopTokenRefresh',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/shopifytokenrefreshcron.class.php',
                'objectname' => 'ShopifyTokenRefreshCron',
                'method' => 'executeCron',
                'parameters' => '',
                'comment' => 'Doli2ShopTokenRefreshDesc',
                'frequency' => 1,
                'unitfrequency' => 86400,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 90
            ),
            // Story 52-4 : drainage de la file de push stock asynchrone (Dolibarr → Shopify).
            // Le trigger STOCK_MOVEMENT enfile un marqueur (jamais de push synchrone/appel API
            // dans la transaction de validation) ; ce CRON dédié pousse le stock courant vers
            // Shopify HORS de toute transaction métier — élimine le risque de lock-wait sur
            // llx_doli2shop_stores en cas de refresh de jeton OAuth (cf. Contexte du bug, story 52-4).
            // status=1 par défaut : filet de sécurité toujours actif, comme Doli2ShopWebhookProcess.
            11 => array(
                'label' => 'Doli2ShopStockPushQueueProcess',
                'jobtype' => 'method',
                'class' => '/doli2shop/class/stockpushqueuecron.class.php',
                'objectname' => 'StockPushQueueCron',
                'method' => 'run',
                'parameters' => '',
                'comment' => 'Doli2ShopStockPushQueueProcessDesc',
                'frequency' => 5,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => 'isModEnabled("doli2shop")',
                'priority' => 55
            )
        );

        // Permissions
        $this->rights = $this->getModuleRights();

        // Main menu entries to add
        $this->menu = array();
		$r = 0;
    }

    /**
     * Function called when module is enabled
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		$result = $this->_load_tables('/doli2shop/sql/');
		if ($result < 0) {
			return -1;
		}

		// Permissions
		$this->remove($options);

		$sql = array();

		// Exécuter l'installation standard avec les scripts SQL
		$result = $this->_init($sql, $options);

		// Si l'installation a réussi, exécuter la migration de configuration
		if ($result > 0) {
			global $conf;

			// v2.2.1: Ne migrer que si l'ancienne table storedetails existe encore
			// Évite d'instancier ConfigurationMigrator inutilement sur les installations >= v2.1.0
			$tableCheck = $this->db->query("SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "doli2shop_storedetails'");
			if ($tableCheck && $this->db->num_rows($tableCheck) > 0) {
				dol_include_once('/doli2shop/class/configurationMigrator.class.php');

				try {
					$migrator = new ConfigurationMigrator($this->db);
					$migrationResult = $migrator->ensureCompleteMigration($conf->entity);

					if ($migrationResult) {
						dol_syslog("Doli2Shop: Migration configuration completed successfully during module activation", LOG_INFO);
					} else {
						dol_syslog("Doli2Shop: Migration configuration failed during module activation", LOG_WARNING);
					}
				} catch (Exception $e) {
					dol_syslog("Doli2Shop: Migration error during activation: " . $e->getMessage(), LOG_ERR);
				}
			} else {
				dol_syslog("Doli2Shop: Table storedetails absente — migration configuration non nécessaire", LOG_DEBUG);
			}

			// FIX #144 v2.1.2: Exécuter les migrations SQL idempotentes via MigrationManager
			// DETTE 50-6 (décision d'architecture, multi-entité) : le suivi par entité des
			// migrations (table llx_doli2shop_migrations) est déjà correct et dynamique ici
			// — MigrationManager est instancié avec $conf->entity (l'entité réellement en
			// cours d'activation), et recordMigration() y écrit le marqueur (DELETE+INSERT)
			// pour CETTE entité après exécution. Le DDL (ALTER TABLE/CREATE) reste par
			// SCHÉMA (pas par entité) et déjà idempotent via information_schema, donc une
			// entité 2+ qui active le module ne "recasse" jamais rien même si le DDL est
			// techniquement déjà appliqué (guard information_schema = no-op silencieux).
			// Le seul défaut réel corrigé (cf. class/MigrationManager.class.php::parseSQLContent)
			// : les fichiers update_*.sql historiques embarquent un marqueur "INSERT IGNORE
			// ... entity=1" en dur, qui créerait un FAUX marqueur pour l'entité 1 si rejoué
			// depuis une entité 2+ jamais activée sur l'entité 1. Ce marqueur embarqué est
			// désormais neutralisé au parsing (MigrationManager gère seul le bookkeeping par
			// entité) — les fichiers .sql historiques ne sont volontairement PAS modifiés
			// (contenu figé chez les clients).
			dol_include_once('/doli2shop/class/MigrationManager.class.php');

			try {
				$migrationManager = new MigrationManager($this->db, $conf->entity);

				// Liste des migrations à exécuter
				$migrations = [
					[
						'version' => '2.0.5_2.0.6',
						'file' => dol_buildpath('/doli2shop/sql/update_2.0.5_2.0.6.sql', 0)
					],
					[
						'version' => '2.0.22_2.0.23',
						'file' => dol_buildpath('/doli2shop/sql/update_2.0.22_2.0.23.sql', 0)
					],
					[
						'version' => '2.1.5_2.1.6',
						'file' => dol_buildpath('/doli2shop/sql/update_2.1.5_2.1.6.sql', 0)
					],
					[
						'version' => '2.1.8_2.2.0',
						'file' => dol_buildpath('/doli2shop/sql/update_2.1.8_2.2.0.sql', 0)
					],
					[
						'version' => '2.2.3_2.2.4',
						'file' => dol_buildpath('/doli2shop/sql/update_2.2.3_2.2.4.sql', 0)
					],
					[
						'version' => '2.2.9_2.3.0',
						'file' => dol_buildpath('/doli2shop/sql/update_2.2.9_2.3.0.sql', 0)
					],
					[
						'version' => '2.3.0_2.3.1',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.0_2.3.1.sql', 0)
					],
					[
						'version' => '2.3.1_2.3.2',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.1_2.3.2.sql', 0)
					],
					[
						'version' => '2.3.2_2.3.3',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.2_2.3.3.sql', 0)
					],
					[
						'version' => '2.3.3_2.3.4',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.3_2.3.4.sql', 0)
					],
					[
						'version' => '2.3.4_2.3.5',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.4_2.3.5.sql', 0)
					],
					[
						'version' => '2.3.5_2.3.6',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.5_2.3.6.sql', 0)
					],
					[
						'version' => '2.3.6_2.3.7',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.6_2.3.7.sql', 0)
					],
					[
						'version' => '2.3.7_2.3.8',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.7_2.3.8.sql', 0)
					],
					[
						'version' => '2.3.8_2.3.9',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.8_2.3.9.sql', 0)
					],
					[
						'version' => '2.3.9_2.4.0',
						'file' => dol_buildpath('/doli2shop/sql/update_2.3.9_2.4.0.sql', 0)
					],
					[
						'version' => '2.4.0_2.4.1',
						'file' => dol_buildpath('/doli2shop/sql/update_2.4.0_2.4.1.sql', 0)
					],
					[
						'version' => '2.4.1_2.4.4',
						'file' => dol_buildpath('/doli2shop/sql/update_2.4.1_2.4.4.sql', 0)
					],
					[
						'version' => '2.4.4_2.4.5',
						'file' => dol_buildpath('/doli2shop/sql/update_2.4.4_2.4.5.sql', 0)
					],
					[
						'version' => '2.4.5_2.5.0',
						'file' => dol_buildpath('/doli2shop/sql/update_2.4.5_2.5.0.sql', 0)
					],
					[
						'version' => '2.5.0_2.5.3',
						'file' => dol_buildpath('/doli2shop/sql/update_2.5.0_2.5.3.sql', 0)
					],
					// 2.5.3b : colonnes d'ecart de totaux sur llx_doli2shop_orders.
					// Cle de version DISTINCTE, et non une « partie 2 » de 2.5.0_2.5.3 :
					// isMigrationApplied() ne compare que la chaine de version, sans hash de
					// contenu. Une installation ayant deja joue 2.5.0_2.5.3 (c'etait le cas de
					// l'install de developpement des le 08/09) aurait saute ces colonnes pour
					// toujours. Un fichier de migration deja livre est FIGE.
					[
						'version' => '2.5.3_2.5.3b',
						'file' => dol_buildpath('/doli2shop/sql/update_2.5.3_2.5.3b.sql', 0)
					],
					// 2.5.4 : colonnes d'audit des commandes importees en prix hors taxes avant le
					// correctif e825f456 (story reprise-des-commandes-importees-en-prix-hors-taxes).
					// Cle de version DISTINCTE et fichier NEUF (meme regle qu'au-dessus) : jamais de
					// "partie 2" d'un fichier de migration deja livre.
					[
						'version' => '2.5.3b_2.5.4',
						'file' => dol_buildpath('/doli2shop/sql/update_2.5.3b_2.5.4.sql', 0)
					],
					// 2.5.5 : colonnes de suivi des variantes/declinaisons non appariees par SKU sur
					// llx_doli2shop_products (story variante-sans-correspondance-sku-ignoree-en-silence).
					// Cle de version DISTINCTE et fichier NEUF (meme regle qu'au-dessus).
					[
						'version' => '2.5.4_2.5.5',
						'file' => dol_buildpath('/doli2shop/sql/update_2.5.4_2.5.5.sql', 0)
					],
					// 2.6.0 : journal de RUN sur llx_doli2shop_action_log (run_id, origin,
					// duration_ms) + index de lecture. Story
					// journal-de-run-identifiant-et-provenance. Cle de version DISTINCTE et
					// fichier NEUF (meme regle qu'au-dessus).
					[
						'version' => '2.5.5_2.6.0',
						'file' => dol_buildpath('/doli2shop/sql/update_2.5.5_2.6.0.sql', 0)
					],
					// 2.6.0b : dedoublonnage + colonne generee default_key + index unique sur
					// llx_doli2shop_stores, empechant deux boutiques is_default=1 pour la meme
					// entity. Story doublon-is-default-boutiques-non-empeche. Cle de version
					// DISTINCTE et fichier NEUF (meme regle qu'au-dessus) : 2.5.5_2.6.0 est deja
					// cablee et livree, suffixe "b" comme 2.5.3_2.5.3b (meme cycle 2.6.0 pas
					// encore publie).
					[
						'version' => '2.6.0_2.6.0b',
						'file' => dol_buildpath('/doli2shop/sql/update_2.6.0_2.6.0b.sql', 0)
					],
					// 2.6.0c : colonne de persistance du compteur de cycles consecutifs "reference
					// de stock non resolue a l'emplacement Shopify configure" sur
					// llx_doli2shop_products. Story
					// stock-article-non-active-emplacement-reselection-perpetuelle. Cle de version
					// DISTINCTE et fichier NEUF (meme regle qu'au-dessus) : 2.6.0_2.6.0b est deja
					// cablee et livree (meme cycle 2.6.0 pas encore publie).
					[
						'version' => '2.6.0b_2.6.0c',
						'file' => dol_buildpath('/doli2shop/sql/update_2.6.0b_2.6.0c.sql', 0)
					],
					// 2.6.0c_2.6.0d : defense en profondeur de la cle unique de
					// llx_doli2shop_products (colonne generee fk_product_parent_key + index unique),
					// story cle-unique-doli2shop-products-null-fk-product-parent. Cle de version
					// DISTINCTE et fichier NEUF (meme regle qu'au-dessus).
					[
						'version' => '2.6.0c_2.6.0d',
						'file' => dol_buildpath('/doli2shop/sql/update_2.6.0c_2.6.0d.sql', 0)
					],
					// 2.6.0d_2.6.0e : colonne shopify_media_ids sur llx_doli2shop_products —
					// provenance EXPLICITE des medias Shopify crees par le module, condition (a)
					// de ImportProducts::isExistingMediaCreatedByModule(). Review 3 couches
					// (27/09/2026, HIGH) de la story
					// apparier-les-images-une-a-une-au-lieu-de-tout-detruire. ORDRE : DOIT
					// s'executer APRES 2.6.0c_2.6.0d ci-dessus (suite chronologique du cycle 2.6.0),
					// et comme toutes les migrations DDL de ce fichier, AVANT ensureDefaultStore()
					// plus bas (invariant migration/seeding, CLAUDE.dolibarr.md paragraphe 14 : le
					// DDL (ADD COLUMN) reste ici, un eventuel BACKFILL dependant de la boutique par
					// defaut irait en PHP apres ensureDefaultStore() — sans objet ici, cette colonne
					// ne depend pas de fk_store). Cle de version DISTINCTE et fichier NEUF : "d_e",
					// suite de "c_d" ci-dessus (branches fusionnees le 28/09/2026).
					// fk_store CONDITIONNEL : lecture/ecriture de cette colonne restent, comme le
					// reste du chemin images, non filtrees par fk_store — motif PREEXISTANT,
					// documente en story separee (fk-store-absent-du-mapping-produit-shopify-images).
					[
						'version' => '2.6.0d_2.6.0e',
						'file' => dol_buildpath('/doli2shop/sql/update_2.6.0d_2.6.0e.sql', 0)
					],
					// 2.6.0e_2.6.0f : colonne images_priority_requeue sur llx_doli2shop_products —
					// marque un produit DIFFERE (budget de polling images epuise, ou lot precedent
					// encore en cours) pour un traitement PRIORITAIRE au cycle de contenu/stock
					// suivant (ORDER BY des requetes de selection de importProducts(), ~488-521 et
					// ~582-610). Re-review 3 couches (28/09/2026, HIGH) de la story
					// apparier-les-images-une-a-une-au-lieu-de-tout-detruire. Cle de version
					// DISTINCTE et fichier NEUF (meme regle qu'au-dessus) : "e_f", suite de "d_e".
					// fk_store CONDITIONNEL : meme motif preexistant que shopify_media_ids
					// ci-dessus, non introduit ni corrige ici.
					[
						'version' => '2.6.0e_2.6.0f',
						'file' => dol_buildpath('/doli2shop/sql/update_2.6.0e_2.6.0f.sql', 0)
					]
				];

				$allMigrationsSuccess = true;
				foreach ($migrations as $migration) {
					$success = $migrationManager->executeMigration($migration['version'], $migration['file']);
					if (!$success) {
						$allMigrationsSuccess = false;
						dol_syslog("Doli2Shop: Migration {$migration['version']} failed", LOG_WARNING);
					}
				}

				if ($allMigrationsSuccess) {
					dol_syslog("Doli2Shop: All SQL migrations completed successfully", LOG_INFO);
				} else {
					dol_syslog("Doli2Shop: Some SQL migrations failed - check migration history", LOG_WARNING);
				}
			} catch (Exception $e) {
				dol_syslog("Doli2Shop: MigrationManager error during activation: " . $e->getMessage(), LOG_ERR);
				// Ne pas faire échouer l'installation pour une erreur de migration
			}

			// Story migration-ne-comble-jamais-une-table-incomplete (v2.5.3) : réparation
			// AUTOMATIQUE du schéma de llx_doli2shop_stores, AVANT le seeding de la boutique
			// par défaut ci-dessous (décision du mainteneur, Validate 09/09/2026). C'est le
			// point qui casse en premier sur une install affectée : StoreService::create()
			// construit son SQL depuis les clés de $data, pas depuis le schéma réel — une
			// colonne manquante fait échouer silencieusement le seeding ("Unknown column").
			// DDL uniquement (ADD COLUMN idempotent, jamais de DROP/CREATE) : le backfill de
			// données dépendant de la boutique par défaut reste APRÈS ensureDefaultStore()
			// plus bas (invariant du module, cf. CLAUDE.dolibarr.md §14).
			dol_include_once('/doli2shop/lib/doli2shop.lib.php');
			try {
				$storesSchemaRepair = doli2shopRepairStoresTableSchema($this->db);
				if (!empty($storesSchemaRepair['added'])) {
					dol_syslog("Doli2Shop: Schéma llx_doli2shop_stores réparé automatiquement — colonne(s) ajoutée(s) : " . implode(', ', $storesSchemaRepair['added']), LOG_WARNING);
				}
				if (!empty($storesSchemaRepair['failed'])) {
					dol_syslog("Doli2Shop: Réparation schéma llx_doli2shop_stores incomplète — colonne(s) non ajoutée(s) : " . implode(', ', $storesSchemaRepair['failed']), LOG_ERR);
				}
			} catch (\Throwable $e) {
				// Échec réparation ne doit pas bloquer l'activation du module (même garde que
				// le seeding et les backfills suivants)
				dol_syslog("Doli2Shop: Erreur réparation schéma llx_doli2shop_stores (non bloquant): " . $e->getMessage(), LOG_ERR);
			}

			// Finding 4 (review 3 couches 2026-09-26, story doublon-is-default-boutiques-non-empeche) :
			// sur un parc très ancien où is_default/default_key viennent d'être ajoutées par la
			// réparation ci-dessus (parce que update_2.6.0_2.6.0b.sql avait échoué faute de colonne
			// is_default), l'index unique uk_doli2shop_stores_default_key restait sinon absent jusqu'à
			// une SECONDE réactivation du module (seul moment où MigrationManager rejoue une migration
			// précédemment en échec) — fenêtre sans AUCUNE protection contre le doublon, potentiellement
			// permanente. Posé ICI, dès CETTE activation, dès que les deux colonnes existent ; no-op si
			// l'index est déjà là (cas normal) ou si des doublons existent encore (dédoublonnage complet
			// laissé à la migration rejouée).
			try {
				$defaultKeyIndexResult = doli2shopEnsureDefaultKeyUniqueIndex($this->db);
				if (!$defaultKeyIndexResult['ensured'] && $defaultKeyIndexResult['reason'] === 'duplicates_present') {
					dol_syslog("Doli2Shop: Index unique uk_doli2shop_stores_default_key non posé (doublons is_default=1 existants) — sera posé à la prochaine réactivation du module", LOG_WARNING);
				} elseif (!$defaultKeyIndexResult['ensured'] && $defaultKeyIndexResult['reason'] === 'alter_failed') {
					dol_syslog("Doli2Shop: Échec pose index unique uk_doli2shop_stores_default_key (voir log ci-dessus)", LOG_ERR);
				}
			} catch (\Throwable $e) {
				// Non bloquant, même garde que la réparation de schéma ci-dessus.
				dol_syslog("Doli2Shop: Erreur pose index uk_doli2shop_stores_default_key (non bloquant): " . $e->getMessage(), LOG_ERR);
			}

			// Epic 47, Story 47-1: Seeding boutique par défaut depuis les constantes DOLI2SHOP_*
				// Exécuté après les migrations SQL (la table llx_doli2shop_stores doit exister)
				dol_include_once('/doli2shop/class/storeservice.class.php');
				try {
					$storeService = new StoreService($this->db, $conf->entity);
					$seedResult = $storeService->ensureDefaultStore();
					if ($seedResult === 1) {
						dol_syslog("Doli2Shop: Boutique par défaut créée depuis les constantes DOLI2SHOP_*", LOG_INFO);
					} elseif ($seedResult === 0) {
						dol_syslog("Doli2Shop: Seeding boutique par défaut — no-op (boutiques déjà présentes ou constantes absentes)", LOG_DEBUG);
					} else {
						dol_syslog("Doli2Shop: Seeding boutique par défaut échoué (non bloquant)", LOG_WARNING);
					}
				} catch (\Throwable $e) {
					// Échec seeding ne doit pas bloquer l'activation du module
					dol_syslog("Doli2Shop: Erreur seeding boutique par défaut (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// Story multicompany-seed-paiements-jamais-pose-en-entite-secondaire (v2.5.3):
				// Seeding PHP des correspondances de paiement par défaut, PAR ENTITÉ. Remplace
				// l'ancien INSERT SQL de sql/llx_doli2shop_payments.sql figé sur entity=1 sous
				// une garde globale (dès qu'une première entité était semée, aucune autre ne
				// l'était jamais). Idempotent sur (entité, méthode) : ne recrée ni n'écrase
				// jamais une correspondance existante, y compris modifiée par le client.
				dol_include_once('/doli2shop/class/paymentmethodsmapping.class.php');
				try {
					$paymentMethodsMapping = new PaymentMethodsMapping($this->db);
					$insertedMappings = $paymentMethodsMapping->ensureDefaultMappings((int) $conf->entity);
					if ($insertedMappings > 0) {
						dol_syslog("Doli2Shop: Correspondances de paiement par défaut semées — " . $insertedMappings . " ligne(s) créée(s) (entity=" . $conf->entity . ")", LOG_INFO);
					} elseif ($insertedMappings === 0) {
						dol_syslog("Doli2Shop: Correspondances de paiement par défaut — no-op (déjà présentes ou aucun mode de paiement valide, entity=" . $conf->entity . ")", LOG_DEBUG);
					} else {
						dol_syslog("Doli2Shop: Seeding correspondances de paiement échoué (non bloquant, entity=" . $conf->entity . ")", LOG_WARNING);
					}
				} catch (\Throwable $e) {
					// Échec seeding ne doit pas bloquer l'activation du module
					dol_syslog("Doli2Shop: Erreur seeding correspondances de paiement (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// Story restaurer-les-correspondances-de-statut-vide-les-tables (v2.5.3) : seeding
				// PHP des correspondances de statut de commande par défaut (deux tables), PAR
				// ENTITÉ. Remplace la lecture, jamais fonctionnelle, de
				// sql/update_2.1.0_add_order_status_mapping.sql (fichier absent du dépôt — les
				// deux tables restaient vides sur toute installation neuve). Définition PHP unique
				// partagée avec l'action `restore_defaults` de admin/orderstatusmapping.php.
				// Idempotent sur (entité, clé unique de chaque table) : ne recrée ni n'écrase
				// jamais une correspondance existante, y compris modifiée par le client.
				dol_include_once('/doli2shop/class/orderstatusmapper.class.php');
				try {
					$orderStatusMapper = new OrderStatusMapper($this->db, (int) $conf->entity);
					$orderStatusSeedResult = $orderStatusMapper->ensureDefaultMappings((int) $conf->entity);
					$insertedShop2dol = $orderStatusSeedResult['shop2dol'];
					$insertedDol2shop = $orderStatusSeedResult['dol2shop'];
					if ($insertedShop2dol > 0 || $insertedDol2shop > 0) {
						dol_syslog("Doli2Shop: Correspondances de statut de commande par défaut semées — " . $insertedShop2dol . " (shop2dol) + " . $insertedDol2shop . " (dol2shop) ligne(s) créée(s) (entity=" . $conf->entity . ")", LOG_INFO);
					} elseif ($insertedShop2dol === 0 && $insertedDol2shop === 0) {
						dol_syslog("Doli2Shop: Correspondances de statut de commande par défaut — no-op (déjà présentes, entity=" . $conf->entity . ")", LOG_DEBUG);
					} else {
						dol_syslog("Doli2Shop: Seeding correspondances de statut de commande échoué (non bloquant, entity=" . $conf->entity . ")", LOG_WARNING);
					}
				} catch (\Throwable $e) {
					// Échec seeding ne doit pas bloquer l'activation du module
					dol_syslog("Doli2Shop: Erreur seeding correspondances de statut de commande (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// Epic 47, Story 47-2: Backfill fk_store sur les tables techniques de mapping
				// Exécuté après ensureDefaultStore() — la boutique par défaut doit exister
				try {
					$storeServiceForBackfill = new StoreService($this->db, $conf->entity);
					// backfillTechnicalTables() est auto-gardé (no-op si pas de boutique par défaut),
					// inutile de re-vérifier getDefault() ici (évite un SELECT redondant).
					$backfillCount = $storeServiceForBackfill->backfillTechnicalTables();
					if ($backfillCount > 0) {
						dol_syslog("Doli2Shop: Backfill fk_store terminé — " . $backfillCount . " ligne(s) mise(s) à jour (entity=" . $conf->entity . ")", LOG_INFO);
					} elseif ($backfillCount === 0) {
						dol_syslog("Doli2Shop: Backfill fk_store — no-op (aucune ligne à backfiller)", LOG_DEBUG);
					} else {
						dol_syslog("Doli2Shop: Backfill fk_store échoué (non bloquant)", LOG_WARNING);
					}
				} catch (\Throwable $e) {
					// Échec backfill ne doit pas bloquer l'activation du module
					dol_syslog("Doli2Shop: Erreur backfill fk_store (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// Epic 47, Story 47-7: Backfill fk_store sur llx_doli2shop_webhooks
				// Exécuté après ensureDefaultStore() — la boutique par défaut doit exister
				// La migration DDL tourne avant le seeding, le backfill doit être en PHP.
				try {
					$storeServiceForWebhooks = new StoreService($this->db, $conf->entity);
					$backfillWebhooks = $storeServiceForWebhooks->backfillWebhooksStore();
					if ($backfillWebhooks > 0) {
						dol_syslog("Doli2Shop: Backfill fk_store webhooks terminé — " . $backfillWebhooks . " webhook(s) mis à jour (entity=" . $conf->entity . ")", LOG_INFO);
					} elseif ($backfillWebhooks === 0) {
						dol_syslog("Doli2Shop: Backfill fk_store webhooks — no-op (aucun webhook à backfiller)", LOG_DEBUG);
					} else {
						dol_syslog("Doli2Shop: Backfill fk_store webhooks échoué (non bloquant)", LOG_WARNING);
					}
				} catch (\Throwable $e) {
					// Échec backfill ne doit pas bloquer l'activation du module
					dol_syslog("Doli2Shop: Erreur backfill fk_store webhooks (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// Story 48-3 : complétude de la boutique par défaut existante (fk_categorie / location_id)
				// Exécuté après ensureDefaultStore() — rattrape les installs seedées avant la liaison
				// catégorie/entrepôt (symptôme « catégorie produit non liée »). Idempotent, non écrasant.
				try {
					$storeServiceForConfig = new StoreService($this->db, $conf->entity);
					$backfillConfig = $storeServiceForConfig->backfillDefaultStoreConfig();
					if ($backfillConfig === 1) {
						dol_syslog("Doli2Shop: Boutique par défaut complétée (catégorie/entrepôt) depuis les constantes globales", LOG_INFO);
					} elseif ($backfillConfig === 0) {
						dol_syslog("Doli2Shop: Complétude boutique par défaut — no-op (déjà complète ou constantes absentes)", LOG_DEBUG);
					} else {
						dol_syslog("Doli2Shop: Complétude boutique par défaut échouée (non bloquant)", LOG_WARNING);
					}
				} catch (\Throwable $e) {
					dol_syslog("Doli2Shop: Erreur complétude boutique par défaut (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// Story 48-2 : backfill des catégories typées par boutique (dont le NOUVEAU tag
				// devis TYPE_PROPOSAL) sur TOUTES les boutiques existantes. ensureStoreCategories()
				// n'est appelé qu'à la création ; les boutiques créées avant l'ajout d'un type ne
				// l'auraient jamais. Idempotent (ne crée que les catégories manquantes). Requiert
				// $user (présent en activation web ; différé proprement sinon).
				try {
					$storeServiceForCats = new StoreService($this->db, $conf->entity);
					dol_include_once('/doli2shop/class/storecategoryhelper.class.php');
					$catHelperBackfill = new StoreCategoryHelper($this->db, $conf->entity);
					$allStoresForCats = $storeServiceForCats->getAll();
					foreach ($allStoresForCats as $storeForCats) {
						$catHelperBackfill->ensureStoreCategories($storeForCats);
					}
					dol_syslog("Doli2Shop: Backfill catégories typées par boutique — " . count($allStoresForCats) . " boutique(s) traitée(s) (idempotent)", LOG_DEBUG);
				} catch (\Throwable $e) {
					dol_syslog("Doli2Shop: Erreur backfill catégories typées boutiques (non bloquant): " . $e->getMessage(), LOG_ERR);
				}

				// v2.2.0 Story 19.1: Migration JSON carrier mapping → table doli2shop_shipping
			$this->migrateCarrierMappingJsonToTable($conf->entity);

			// v2.2.0 Story 22.1: Migration doli2shop_shipping → doli2shop_shipping_rules (rule_type)
			$this->migrateShippingToRules($conf->entity);

			// v2.1.6: Nettoyage de l'ancien dossier shopifyintegration (migration de nom)
			$this->cleanupOldModuleFolder($langs);

			// v2.1.6: Restaurer le status des CRONs selon les constantes mémorisées
			$this->restoreCronStatuses($conf->entity);

			// v2.2.1: Nettoyage CRONs orphelins et création CRONs manquants
			// Fix bug Astrid/Cheer-Moda: l'upgrade v2.1.x→v2.2.0 laisse des CRONs
			// pointant vers des classes supprimées (shopifyordersynccron.class.php)
			$this->cleanupOrphanCrons($conf->entity);
		}

		return $result;
	}

	/**
	 * Nettoie l'ancien dossier shopifyintegration après migration vers doli2shop
	 * Stratégie en cascade : suppression auto → avertissement → documentation
	 *
	 * @param Translate $langs Language object
	 * @return void
	 */
	private function cleanupOldModuleFolder($langs)
	{
		$oldModulePath = DOL_DOCUMENT_ROOT . '/custom/shopifyintegration';

		// Vérifier si l'ancien dossier existe
		if (!is_dir($oldModulePath)) {
			dol_syslog("Doli2Shop: Ancien dossier shopifyintegration non détecté - installation propre", LOG_DEBUG);
			return;
		}

		dol_syslog("Doli2Shop: Ancien dossier shopifyintegration détecté à " . $oldModulePath, LOG_INFO);

		// Option 1: Tenter la suppression automatique
		if (is_writable($oldModulePath)) {
			try {
				// Utiliser la fonction Dolibarr pour suppression récursive
				if (function_exists('dol_delete_dir_recursive')) {
					$deleteResult = dol_delete_dir_recursive($oldModulePath);
				} else {
					// Fallback manuel si fonction non disponible
					$deleteResult = $this->deleteDirectoryRecursive($oldModulePath);
				}

				if ($deleteResult >= 0 || $deleteResult === true) {
					dol_syslog("Doli2Shop: Ancien dossier shopifyintegration supprimé automatiquement avec succès", LOG_INFO);
					setEventMessages($langs->trans("OldModuleFolderDeleted", "shopifyintegration"), null, 'mesgs');
					return;
				}
			} catch (Exception $e) {
				dol_syslog("Doli2Shop: Erreur lors de la suppression automatique: " . $e->getMessage(), LOG_WARNING);
			}
		}

		// Option 2: Suppression automatique échouée → Afficher avertissement
		dol_syslog("Doli2Shop: Impossible de supprimer automatiquement l'ancien dossier (permissions insuffisantes)", LOG_WARNING);

		$warningMsg = $langs->trans("OldModuleFolderDetected", "shopifyintegration");
		$instructionMsg = $langs->trans("OldModuleFolderInstructions", $oldModulePath);

		// Message d'avertissement avec instructions
		setEventMessages($warningMsg . '<br><br><strong>' . $langs->trans("ManualAction") . ':</strong><br>' .
			'<code>rm -rf ' . $oldModulePath . '</code><br><br>' .
			$instructionMsg, null, 'warnings');

		// Stocker un flag pour rappeler dans l'interface admin
		dolibarr_set_const($this->db, 'DOLI2SHOP_OLD_FOLDER_EXISTS', '1', 'chaine', 0, 'Old shopifyintegration folder needs manual cleanup', $GLOBALS['conf']->entity);
	}

	/**
	 * Suppression récursive d'un répertoire (fallback)
	 *
	 * @param string $dir Chemin du répertoire à supprimer
	 * @return bool True si succès
	 */
	private function deleteDirectoryRecursive($dir)
	{
		if (!is_dir($dir)) {
			return true;
		}

		$files = array_diff(scandir($dir), array('.', '..'));
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			if (is_dir($path)) {
				$this->deleteDirectoryRecursive($path);
			} else {
				@unlink($path);
			}
		}

		return @rmdir($dir);
	}

	/**
	 * Restaure le status des CRONs selon les constantes mémorisées
	 * v2.1.6 - Permet de conserver l'état des CRONs lors réactivation module
	 *
	 * @param int $entity Entité Dolibarr
	 * @return void
	 */
	/**
	 * v2.2.1: Nettoyage CRONs orphelins lors de l'upgrade
	 *
	 * Supprime les CRONs pointant vers des classes supprimees (ex: ShopifyOrderSyncCron)
	 * et s'assure que les nouveaux CRONs (ex: WebhookProcessCron, AlertCron) existent.
	 *
	 * @param int $entity Entity ID
	 * @return void
	 */
	private function cleanupOrphanCrons($entity)
	{
		// Liste des classes supprimees dans les versions precedentes
		$deletedClasses = [
			'/doli2shop/class/shopifyordersynccron.class.php',  // Supprime en v2.2.0 (Story 14.1)
		];

		foreach ($deletedClasses as $classPath) {
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob";
			$sql .= " WHERE classesname = '" . $this->db->escape($classPath) . "'";
			$sql .= " AND entity = " . ((int) $entity);
			$result = $this->db->query($sql);
			if ($result && $this->db->affected_rows($result) > 0) {
				dol_syslog("Doli2Shop: Deleted orphan CRON referencing " . $classPath, LOG_WARNING);
			}
		}

		dol_syslog("Doli2Shop: Orphan CRON cleanup completed for entity " . $entity, LOG_INFO);
	}

	private function restoreCronStatuses($entity)
	{
		try {
			// v2.2.0: CRON OrdersImport supprimé — plus de restauration nécessaire

			// Restaurer le status du CRON produits selon la direction
			$syncDirection = getDolGlobalString('DOLI2SHOP_SYNC_PRODUCTS_DIRECTION', 'both');

			// CRON Export (Dolibarr → Shopify)
			$exportActive = in_array($syncDirection, ['dolibarr_to_shopify', 'both']) ? 1 : 0;
			$sqlExport = "UPDATE " . MAIN_DB_PREFIX . "cronjob
						  SET status = " . $exportActive . "
						  WHERE label = 'Doli2ShopProductsExport'
						  AND entity = " . (int)$entity;
			$this->db->query($sqlExport);

			// CRON Import (Shopify → Dolibarr)
			$importActive = in_array($syncDirection, ['shopify_to_dolibarr', 'both']) ? 1 : 0;
			$sqlImport = "UPDATE " . MAIN_DB_PREFIX . "cronjob
						  SET status = " . $importActive . "
						  WHERE label = 'Doli2ShopProductsImport'
						  AND entity = " . (int)$entity;
			$this->db->query($sqlImport);

			dol_syslog("Doli2Shop: CRONs produits restaurés (direction: $syncDirection, export: $exportActive, import: $importActive)", LOG_INFO);

		} catch (Exception $e) {
			dol_syslog("Doli2Shop: Erreur restauration CRONs: " . $e->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Migrate JSON carrier shipping mapping to doli2shop_shipping table
	 * Story 19.1: Unification of the 2 shipping mapping systems
	 *
	 * @param int $entity Entity ID
	 * @return void
	 */
	private function migrateCarrierMappingJsonToTable($entity)
	{
		try {
			// Check if JSON carrier mapping constant exists
			$sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const"
				. " WHERE name = 'DOLI2SHOP_CARRIER_SHIPPING_MAPPING'"
				. " AND entity = " . (int)$entity;

			$resql = $this->db->query($sql);
			if (!$resql) {
				return;
			}

			$obj = $this->db->fetch_object($resql);
			if (!$obj || empty($obj->value)) {
				dol_syslog("Doli2Shop: No JSON carrier mapping to migrate (Story 19.1)", LOG_DEBUG);
				return;
			}

			$mapping = json_decode($obj->value, true);
			if (!is_array($mapping) || empty($mapping)) {
				dol_syslog("Doli2Shop: JSON carrier mapping is empty or invalid, skipping migration", LOG_DEBUG);
				return;
			}

			// Get default delivery days
			$defaultDeliveryDays = getDolGlobalInt('DOLI2SHOP_DEFAULT_DELIVERY_DAYS', 3);

			$migratedCount = 0;
			$duplicateCount = 0;

			$this->db->begin();

			foreach ($mapping as $carrierName => $dolibarrMethodId) {
				if (empty($carrierName) || (int)$dolibarrMethodId <= 0) {
					continue;
				}

				// INSERT ... ON DUPLICATE KEY UPDATE match_tracking = 1
				$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_shipping"
					. " (shopify_pattern, dol_shipping_method_id, delivery_days, match_tracking, entity)"
					. " VALUES ('" . $this->db->escape($carrierName) . "', " . (int)$dolibarrMethodId
					. ", " . (int)$defaultDeliveryDays . ", 1, " . (int)$entity . ")"
					. " ON DUPLICATE KEY UPDATE match_tracking = 1, dol_shipping_method_id = " . (int)$dolibarrMethodId;

				$resInsert = $this->db->query($sqlInsert);
				if ($resInsert) {
					$affectedRows = $this->db->affected_rows($resInsert);
					if ($affectedRows == 1) {
						$migratedCount++;
					} elseif ($affectedRows == 2) {
						// ON DUPLICATE KEY UPDATE counts as 2 affected rows
						$duplicateCount++;
					}
				}
			}

			// Delete the JSON constant after successful migration
			$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "const"
				. " WHERE name = 'DOLI2SHOP_CARRIER_SHIPPING_MAPPING'"
				. " AND entity = " . (int)$entity;

			$this->db->query($sqlDelete);

			$this->db->commit();

			dol_syslog(
				"Doli2Shop: Carrier mapping migration completed (Story 19.1)"
				. " — migrated: $migratedCount, merged duplicates: $duplicateCount"
				. ", JSON constant deleted for entity $entity",
				LOG_INFO
			);
		} catch (Exception $e) {
			$this->db->rollback();
			dol_syslog("Doli2Shop: Carrier mapping migration error: " . $e->getMessage(), LOG_ERR);
		}
	}

	/**
	 * Migration doli2shop_shipping → doli2shop_shipping_rules (Story 22.1)
	 *
	 * Convertit le modèle match_tracking booléen en rule_type ENUM('title', 'tracking') :
	 * - match_tracking = 0 → 1 entrée rule_type = 'title'
	 * - match_tracking = 1 → 2 entrées : rule_type = 'title' ET rule_type = 'tracking'
	 *
	 * Renomme l'ancienne table en _old après migration réussie.
	 * Idempotent : ne fait rien si la table source n'existe plus ou si les données sont déjà migrées.
	 *
	 * @param int $entity Entity ID
	 * @return void
	 */
	private function migrateShippingToRules($entity)
	{
		try {
			// Vérifier que la table source existe encore (pas déjà renommée)
			$sqlCheck = "SELECT COUNT(*) as cnt FROM information_schema.TABLES"
				. " WHERE TABLE_SCHEMA = DATABASE()"
				. " AND TABLE_NAME = '" . MAIN_DB_PREFIX . "doli2shop_shipping'";
			$resCheck = $this->db->query($sqlCheck);
			if (!$resCheck) {
				return;
			}
			$objCheck = $this->db->fetch_object($resCheck);
			if (!$objCheck || (int)$objCheck->cnt == 0) {
				dol_syslog("Doli2Shop: Table doli2shop_shipping not found — migration already done or first install (Story 22.1)", LOG_DEBUG);
				return;
			}

			// Vérifier que la table cible existe
			$sqlCheckTarget = "SELECT COUNT(*) as cnt FROM information_schema.TABLES"
				. " WHERE TABLE_SCHEMA = DATABASE()"
				. " AND TABLE_NAME = '" . MAIN_DB_PREFIX . "doli2shop_shipping_rules'";
			$resCheckTarget = $this->db->query($sqlCheckTarget);
			if (!$resCheckTarget) {
				return;
			}
			$objCheckTarget = $this->db->fetch_object($resCheckTarget);
			if (!$objCheckTarget || (int)$objCheckTarget->cnt == 0) {
				dol_syslog("Doli2Shop: Table doli2shop_shipping_rules not found — SQL migration not yet executed (Story 22.1)", LOG_WARNING);
				return;
			}

			// Multi-entité : migrer TOUTES les entités de la table source (pas seulement $entity)
			// pour pouvoir renommer la table en toute sécurité après
			$sqlSource = "SELECT entity, shopify_pattern, dol_shipping_method_id, delivery_days, match_tracking"
				. " FROM " . MAIN_DB_PREFIX . "doli2shop_shipping";
			$resSource = $this->db->query($sqlSource);
			if (!$resSource) {
				return;
			}

			$sourceCount = $this->db->num_rows($resSource);
			if ($sourceCount == 0) {
				dol_syslog("Doli2Shop: No shipping entries to migrate (Story 22.1)", LOG_DEBUG);
				// Table vide → renommer directement
			} else {
				$this->db->begin();

				$titleCount = 0;
				$trackingCount = 0;

				while ($obj = $this->db->fetch_object($resSource)) {
					$escapedPattern = $this->db->escape($obj->shopify_pattern);
					$methodId = (int)$obj->dol_shipping_method_id;
					$days = (int)$obj->delivery_days;
					$rowEntity = (int)$obj->entity;

					// Toujours créer une règle title
					$sqlTitle = "INSERT IGNORE INTO " . MAIN_DB_PREFIX . "doli2shop_shipping_rules"
						. " (entity, rule_type, shopify_pattern, dol_shipping_method_id, delivery_days, active)"
						. " VALUES (" . $rowEntity . ", 'title', '" . $escapedPattern . "'"
						. ", " . $methodId . ", " . $days . ", 1)";
					$resTitle = $this->db->query($sqlTitle);
					if ($resTitle) {
						if ($this->db->affected_rows($resTitle) > 0) {
							$titleCount++;
						}
					}

					// Si match_tracking = 1, créer aussi une règle tracking
					if ((int)$obj->match_tracking == 1) {
						$sqlTracking = "INSERT IGNORE INTO " . MAIN_DB_PREFIX . "doli2shop_shipping_rules"
							. " (entity, rule_type, shopify_pattern, dol_shipping_method_id, delivery_days, active)"
							. " VALUES (" . $rowEntity . ", 'tracking', '" . $escapedPattern . "'"
							. ", " . $methodId . ", " . $days . ", 1)";
						$resTracking = $this->db->query($sqlTracking);
						if ($resTracking) {
							if ($this->db->affected_rows($resTracking) > 0) {
								$trackingCount++;
							}
						}
					}
				}

				$this->db->commit();

				dol_syslog(
					"Doli2Shop: Shipping rules migration completed (Story 22.1)"
					. " — source: $sourceCount, title rules: $titleCount, tracking rules: $trackingCount",
					LOG_INFO
				);
			}

			// Renommer l'ancienne table (filet de sécurité) — après migration de TOUTES les entités
			$oldTableName = MAIN_DB_PREFIX . "doli2shop_shipping_old";
			$sqlCheckOld = "SELECT COUNT(*) as cnt FROM information_schema.TABLES"
				. " WHERE TABLE_SCHEMA = DATABASE()"
				. " AND TABLE_NAME = '" . $this->db->escape($oldTableName) . "'";
			$resCheckOld = $this->db->query($sqlCheckOld);
			$oldExists = false;
			if ($resCheckOld) {
				$objOld = $this->db->fetch_object($resCheckOld);
				$oldExists = ($objOld && (int)$objOld->cnt > 0);
			}

			if (!$oldExists) {
				$sqlRename = "RENAME TABLE " . MAIN_DB_PREFIX . "doli2shop_shipping"
					. " TO " . $oldTableName;
				$this->db->query($sqlRename);
				dol_syslog("Doli2Shop: Old table doli2shop_shipping renamed to _old (Story 22.1)", LOG_INFO);
			}
		} catch (Exception $e) {
			$this->db->rollback();
			dol_syslog("Doli2Shop: Shipping rules migration error (Story 22.1): " . $e->getMessage(), LOG_ERR);
		}
	}

    public function remove($options = '')
    {
        $sql = array();
        
        // Dolibarr framework automatically removes CRONs defined in $this->cronjobs
        // No manual SQL queries needed - the framework handles cleanup
        
        // // SQL file for table removal - uncomment if needed for database cleanup
        // $sqlfile = DOL_DOCUMENT_ROOT.'/custom/doli2shop/sql/llx_uninstall.sql';
        // if (file_exists($sqlfile)) {
        //     $sql = array_merge($sql, array_filter(explode(';', file_get_contents($sqlfile))));
        // }
        
        return $this->_remove($sql, $options);
    }


    /**
     * Get module rights
     *
     * @return array Array of rights
     */
    private function getModuleRights()
    {
        // NOTE (Story 7.3): These permissions are defined for Dolibarr's permission system
        // but are intentionally NOT enforced in admin pages. All admin pages check $user->admin
        // exclusively because Doli2Shop is a system administration tool (OAuth connection,
        // sync config, monitoring) that should only be accessible to Dolibarr admins.
        // These rights are kept for potential future fine-grained access in a later version.
        $r = 0;

        // v2.2.2 FIX: ajout des indices [2] et [3] manquants
        // Dolibarr 21+ (PHP 8+) émet des warnings "Undefined array key" si non fournis
        // Format standard Dolibarr : [0]=id, [1]=label, [2]=type, [3]=defaut, [4]=code, [5]=object
        // Story 54-2 (extension de périmètre) : les libellés sont des CLÉS de traduction
        // (fichier doli2shop@doli2shop déjà déclaré via $this->langfiles) — $langs->trans($obj->label)
        // est appelé par le core (htdocs/user/perms.php) pour afficher le libellé sur l'écran des
        // permissions Dolibarr. Avant ce fix, ces libellés étaient du texte anglais en dur, jamais
        // traduit (trans() renvoie la chaîne telle quelle si la clé n'existe pas).
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
        $this->rights[$r][1] = 'Doli2ShopRightRead';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'read';
        $this->rights[$r][5] = '';
        $r++;

        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
        $this->rights[$r][1] = 'Doli2ShopRightWrite';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $this->rights[$r][5] = '';
        $r++;

        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
        $this->rights[$r][1] = 'Doli2ShopRightDelete';
        $this->rights[$r][2] = 'd';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'delete';
        $this->rights[$r][5] = '';
        $r++;

        // Story 54-1 : droits dédiés API REST native, DISTINCTS des droits UI ci-dessus
        // (read/write/delete). Désactivés par défaut ([3]=0) — opt-in explicite.
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
        $this->rights[$r][1] = 'Doli2ShopRightApiRead';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'api_read';
        $this->rights[$r][5] = '';
        $r++;

        // Créé dès maintenant pour la story 54-2 (actions push/recalage/catchup via API) —
        // NON utilisé par les endpoints de lecture de cette story 54-1.
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
        $this->rights[$r][1] = 'Doli2ShopRightApiOperate';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'api_operate';
        $this->rights[$r][5] = '';
        $r++;

        return $this->rights;
    }
    
}
