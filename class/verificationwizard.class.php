<?php
/**
 * @file        class/verificationwizard.class.php
 * @brief       Wizard de vérification post-installation (Story 37.1)
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.3.0
 * @link        https://doli2shop.ptitetete.org
 */

require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php'; // getAllExpectedCrons()

/**
 * Class VerificationWizard
 *
 * Agrège les vérifications BLOQUANTES post-installation/upgrade en composant les
 * services existants (HealthChecker, ConfigurationMigrator, getAllExpectedCrons),
 * sans dupliquer la logique lourde de admin/diagnostic.php. Chaque check porte une
 * éventuelle action corrective contextuelle (lien/handler existant de diagnostic.php).
 *
 * Catégories : Connexion, Configuration, CRONs, Webhooks, Migrations.
 * Statuts par check : success | warning | error.
 */
class VerificationWizard
{
    /** @var DoliDB */
    private $db;
    /** @var int */
    private $entity;
    /** @var array Résultats de checks (remplis par run()) */
    private $checks = array();
    /** @var array|null Cache du statut santé HealthChecker */
    private $healthStatus = null;

    /** CRONs réellement critiques post-install (les autres alertent en warning) */
    private static $criticalCrons = array('WebhookProcessCron', 'ImportProductsCron');

    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = ($entity !== null) ? (int) $entity : (int) ($conf->entity ?? 1);
    }

    /**
     * Exécute toutes les vérifications et mémorise les résultats.
     *
     * @return void
     */
    public function run()
    {
        $this->checks = array();
        $this->checkConnection();
        $this->checkConfiguration();
        $this->checkCrons();
        $this->checkWebhooks();
        $this->checkMigrations();
    }

    /**
     * Statut santé (HealthChecker), calculé une seule fois, non bloquant.
     *
     * @return array|null
     */
    private function getHealth()
    {
        if ($this->healthStatus === null) {
            try {
                require_once dirname(__FILE__) . '/healthchecker.class.php';
                $hc = new HealthChecker($this->db);
                $this->healthStatus = $hc->getHealthStatus();
            } catch (\Throwable $e) {
                $this->healthStatus = array();
            }
        }
        return $this->healthStatus;
    }

    /**
     * Ajoute un check normalisé.
     */
    private function addCheck($category, $name, $status, $note = '', $action = null)
    {
        $this->checks[] = array(
            'category' => $category,
            'name'     => $name,
            'status'   => $status, // success | warning | error
            'note'     => $note,
            'action'   => $action, // ['label_key'=>, 'type'=>'link|post', 'url'=>, 'cron_type'=>...] ou null
        );
    }

    // ── Connexion Shopify ───────────────────────────────────────────────────
    private function checkConnection()
    {
        $token = getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN');
        $host = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME');
        if (empty($token) || empty($host)) {
            $this->addCheck('connection', 'CheckShopifyConnection', 'error', 'CheckShopifyConnectionMissing', array(
                'label_key' => 'VerificationActionConfigureConnection',
                'type' => 'link',
                'url' => dol_buildpath('/doli2shop/admin/setup_wizard.php', 1) . '?step=1',
            ));
        } else {
            $this->addCheck('connection', 'CheckShopifyConnection', 'success', 'CheckShopifyConnectionOk');
        }
    }

    // ── Configuration requise (entrepôt, catégorie produit) ─────────────────
    private function checkConfiguration()
    {
        $warehouse = getDolGlobalInt('DOLI2SHOP_DEFAULT_WAREHOUSE_ID');
        if ($warehouse <= 0) {
            $this->addCheck('config', 'CheckDefaultWarehouse', 'error', 'CheckDefaultWarehouseMissing', array(
                'label_key' => 'VerificationActionGoToConfig',
                'type' => 'link',
                'url' => dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=orders',
            ));
        } else {
            $this->addCheck('config', 'CheckDefaultWarehouse', 'success', 'CheckDefaultWarehouseOk');
        }

        $procate = getDolGlobalInt('SHOPIFY_DOLIBARR_PROCATE');
        if ($procate <= 0) {
            $this->addCheck('config', 'CheckProductCategory', 'warning', 'CheckProductCategoryMissing', array(
                'label_key' => 'VerificationActionGoToConfig',
                'type' => 'link',
                'url' => dol_buildpath('/doli2shop/admin/setup.php', 1) . '?tab=settings',
            ));
        } else {
            $this->addCheck('config', 'CheckProductCategory', 'success', 'CheckProductCategoryOk');
        }
    }

    // ── CRONs attendus présents et actifs ───────────────────────────────────
    private function checkCrons()
    {
        $expected = getAllExpectedCrons();

        // État réel des CRONs du module en base
        $existing = array();
        $sql = "SELECT classesname, status FROM " . MAIN_DB_PREFIX . "cronjob"
            . " WHERE module_name = 'doli2shop' AND entity = " . ((int) $this->entity);
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $existing[$obj->classesname] = (int) $obj->status;
            }
            $this->db->free($resql);
        }

        foreach ($expected as $cronType => $cronDef) {
            $classPath = $cronDef['classesname'];
            $isCritical = in_array($cronType, self::$criticalCrons, true);
            $present = array_key_exists($classPath, $existing);
            $active = $present && $existing[$classPath] == 1;

            if ($active) {
                $this->addCheck('crons', $cronDef['label'], 'success', 'CheckCronActive');
                continue;
            }

            // Manquant ou inactif : error si critique, warning sinon
            $status = $isCritical ? 'error' : 'warning';
            $note = $present ? 'CheckCronInactive' : 'CheckCronMissing';
            $this->addCheck('crons', $cronDef['label'], $status, $note, array(
                'label_key' => 'VerificationActionRecreateCron',
                'type' => 'post',
                'url' => dol_buildpath('/doli2shop/admin/diagnostic.php', 1),
                'cron_type' => $cronType,
            ));
        }
    }

    // ── Webhooks actifs ─────────────────────────────────────────────────────
    private function checkWebhooks()
    {
        $health = $this->getHealth();
        $active = isset($health['webhooks_active']) ? (int) $health['webhooks_active'] : 0;
        $total = isset($health['webhooks_total']) ? (int) $health['webhooks_total'] : 0;

        if ($total === 0) {
            $this->addCheck('webhooks', 'CheckWebhooks', 'warning', 'CheckWebhooksNone', array(
                'label_key' => 'VerificationActionGoToWebhooks',
                'type' => 'link',
                'url' => dol_buildpath('/doli2shop/admin/webhooks.php', 1),
            ));
        } elseif ($active < $total) {
            $this->addCheck('webhooks', 'CheckWebhooks', 'warning', 'CheckWebhooksPartial', array(
                'label_key' => 'VerificationActionGoToWebhooks',
                'type' => 'link',
                'url' => dol_buildpath('/doli2shop/admin/webhooks.php', 1),
            ));
        } else {
            $this->addCheck('webhooks', 'CheckWebhooks', 'success', 'CheckWebhooksOk');
        }
    }

    // ── Migrations SQL en attente ────────────────────────────────────────────
    private function checkMigrations()
    {
        try {
            require_once dirname(__FILE__) . '/configurationMigrator.class.php';
            $migrator = new ConfigurationMigrator($this->db);
            if ($migrator->isMigrationNeeded($this->entity)) {
                $this->addCheck('migrations', 'CheckMigrations', 'error', 'CheckMigrationsPending', array(
                    'label_key' => 'VerificationActionRunMigration',
                    'type' => 'post_migration',
                    'url' => dol_buildpath('/doli2shop/admin/diagnostic.php', 1),
                ));
            } else {
                $this->addCheck('migrations', 'CheckMigrations', 'success', 'CheckMigrationsOk');
            }
        } catch (\Throwable $e) {
            // Non bloquant : si la détection échoue, on n'affiche pas de faux positif
            $this->addCheck('migrations', 'CheckMigrations', 'warning', 'CheckMigrationsUnknown');
        }
    }

    // ── Accès aux résultats ───────────────────────────────────────────────────

    /** @return array Checks en erreur (bloquants) */
    public function getBlockingChecks()
    {
        return array_values(array_filter($this->checks, function ($c) {
            return $c['status'] === 'error';
        }));
    }

    /** @return array Checks en avertissement */
    public function getWarningChecks()
    {
        return array_values(array_filter($this->checks, function ($c) {
            return $c['status'] === 'warning';
        }));
    }

    /** @return array Checks réussis */
    public function getSuccessChecks()
    {
        return array_values(array_filter($this->checks, function ($c) {
            return $c['status'] === 'success';
        }));
    }

    /** @return array Tous les checks (ordre : erreurs, warnings, succès) */
    public function getAllChecksSorted()
    {
        return array_merge($this->getBlockingChecks(), $this->getWarningChecks(), $this->getSuccessChecks());
    }

    /**
     * Statut global : 'red' si au moins un bloquant, 'orange' si au moins un warning, 'green' sinon.
     *
     * @return string red|orange|green
     */
    public function getOverallStatus()
    {
        if (count($this->getBlockingChecks()) > 0) {
            return 'red';
        }
        if (count($this->getWarningChecks()) > 0) {
            return 'orange';
        }
        return 'green';
    }
}
