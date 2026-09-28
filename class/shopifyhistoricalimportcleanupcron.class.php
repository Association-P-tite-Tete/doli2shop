<?php

/**
 * @file        class/shopifyhistoricalimportcleanupcron.class.php
 * @brief       CRON nettoyeur pour désactiver automatiquement le CRON d'import historique
 *
 * FIX #152 v2.1.2: CRON "cleanup" externe qui désactive le CRON historique
 *
 * PROBLÈME RÉSOLU:
 * On ne peut pas modifier le status d'un CRON pendant sa propre exécution,
 * car Dolibarr écrase systématiquement le status après l'exécution avec les
 * valeurs qu'il avait AVANT l'exécution.
 *
 * SOLUTION:
 * Ce CRON s'exécute indépendamment (1x/heure) et désactive le CRON historique
 * depuis l'EXTÉRIEUR quand il détecte que l'import est complété.
 *
 * COMPORTEMENT:
 * 1. Vérifie si DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED = 1
 * 2. Si oui, cherche le CRON historique et vérifie son status
 * 3. Si status = 1, appelle Cronjob::setStatut(0) pour le désactiver
 * 4. Se désactive lui-même après avoir terminé sa mission
 *
 * Story 63-18 (AC5) : ce CRON était un chemin mort depuis sa création — sa garde d'entrée
 * (getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED', 0), ligne ci-dessous) est correcte,
 * mais RIEN n'écrivait jamais cette constante à 1 (cf. class/shopifyhistoricalimportcron.class.php,
 * Task 3). Arbitrage retenu : PAS de suppression de ce CRON — sa logique de désactivation était déjà
 * correcte et couvrait un vrai besoin (désactiver le CRON historique sans se désactiver lui-même
 * PENDANT sa propre exécution, cf. docblock de classe ci-dessous). La Task 3 de la story 63-18 lui
 * fournit désormais la seule chose qui lui manquait : une écriture réelle de COMPLETED. Aucun
 * changement de code dans CE fichier n'était nécessaire — seulement cette note.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.2
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/cron/class/cronjob.class.php');
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/shopifyapi.class.php'; // FIX #152 v2.1.2: Utiliser ShopifyApi comme les autres CRONs

/**
 * Class ShopifyHistoricalImportCleanupCron
 *
 * FIX #152 v2.1.2: CRON "nettoyeur" pour désactiver automatiquement le CRON historique
 *
 * Ce CRON est conçu pour résoudre le problème architectural suivant:
 * - On ne peut pas modifier le status d'un CRON pendant sa propre exécution
 * - Dolibarr appelle Cronjob::update() APRÈS l'exécution et écrase le status
 *
 * Solution: Un CRON externe qui désactive le CRON historique quand nécessaire
 *
 * @since 2.1.2
 */
class ShopifyHistoricalImportCleanupCron
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDB */
    private $db;

    /** @var object Configuration object from ConfigurationMigrator */
    private $config;

    /** @var string */
    public $output;

    /** @var int */
    public $error = 0;

    /** @var array */
    public $errors = array();

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Execute cleanup cron job
     *
     * FIX #152 v2.1.2: Désactivation externe du CRON historique
     *
     * @param object $conf Config object
     * @param object $langs Lang object
     * @return int 0 if OK, <0 if error
     */
    public function executeCron($conf = null, $langs = null)
    {
        global $conf, $langs;

        // FIX #152 v2.1.2: TRY/CATCH GLOBAL pour capturer TOUTE erreur silencieuse
        try {
            dol_syslog("ShopifyHistoricalImportCleanupCron::executeCron - ENTRY POINT - Starting execution", LOG_INFO);

            $this->output = '';
            $this->error = 0;

            // Déterminer entity
            $entity = (isset($conf->entity) && $conf->entity > 0) ? (int)$conf->entity : 1;

            // FIX #152 v2.1.2: Utiliser ShopifyApi comme les autres CRONs (cohérence)
            // ShopifyApi charge la config via ConfigurationMigrator ET la convertit en objet
            $shopifyApi = new ShopifyApi($this->db, $entity);

            // FIX #152 v2.1.2: Récupération version/build via méthode générique CronHelperTrait
            $versionInfo = $this->getModuleVersionInfo();

            $this->log("🧹 CLEANUP CRON START - Entity: {$entity} | v{$versionInfo['version']} | Build: {$versionInfo['build']}", LOG_INFO);
            $this->output .= "Cleanup CRON start (v{$versionInfo['version']} build:{$versionInfo['build']})";

        try {
            // Étape 1: Vérifier si l'import historique est complété via ShopifyApi
            // getDolGlobalInt() lit depuis $conf->global (cache chargé au boot)
            // Pour entity courante, ça fonctionne car $conf->entity = entity CRON
            $historicalImportCompleted = getDolGlobalInt('DOLI2SHOP_HISTORICAL_IMPORT_COMPLETED', 0);

            // FIX #152 v2.1.2: Log debug pour tracer la valeur exacte
            $this->log("🔍 DEBUG: historical_import_completed = " . var_export($historicalImportCompleted, true) . " (type: " . gettype($historicalImportCompleted) . ")", LOG_DEBUG);

            if ($historicalImportCompleted !== 1) {
                $this->log("ℹ️ Historical import not yet completed (value: $historicalImportCompleted) - no cleanup needed", LOG_INFO);
                $this->output .= "\n" . 'Historical import still running - no action taken';
                return 0;
            }

            $this->log("[OK] Historical import detected as COMPLETED - proceeding with cleanup", LOG_INFO);

            // FIX v2.1.2: Auto-déblocage CRON gelé (si nécessaire)
            // NOTE: Lock SQL déjà libéré plus haut, cette fonction ne sert plus qu'au déblocage
            $this->checkAndUnfreezeCron($entity, '/doli2shop/class/shopifyhistoricalimportcleanupcron.class.php');

            // Étape 2: Chercher le CRON d'import historique
            $sql = "SELECT rowid, status FROM " . MAIN_DB_PREFIX . "cronjob
                    WHERE module_name = 'doli2shop'
                    AND classesname = '/doli2shop/class/shopifyhistoricalimportcron.class.php'
                    AND entity = " . (int)$entity;

            $resql = $this->db->query($sql);

            if (!$resql) {
                $this->log("[ERROR] SQL error searching for historical import CRON: " . $this->db->lasterror(), LOG_ERR);
                $this->output .= "\n" . 'SQL error: ' . $this->db->lasterror();
                return -1;
            }

            if ($this->db->num_rows($resql) === 0) {
                $this->log("ℹ️ Historical import CRON not found - already deleted or never created", LOG_INFO);
                $this->output .= "\n" . 'Historical import CRON not found';

                // Se désactiver soi-même car mission accomplie
                $this->disableSelf($entity);
                return 0;
            }

            // Étape 3: Vérifier le statut actuel du CRON historique
            $obj = $this->db->fetch_object($resql);
            $cronRowId = $obj->rowid;
            $cronStatus = $obj->status;
            $this->db->free($resql);

            $this->log("Found historical import CRON: rowid=$cronRowId, status=$cronStatus", LOG_INFO);

            if ($cronStatus == 0) {
                $this->log("[OK] Historical import CRON already disabled - cleanup complete", LOG_INFO);
                $this->output .= "\n" . 'Historical import CRON already disabled';

                // Se désactiver soi-même car mission accomplie
                $this->disableSelf($entity);
                return 0;
            }

            // Étape 4: Désactiver le CRON historique via API native Dolibarr
            $this->log("[FIX] Disabling historical import CRON (rowid=$cronRowId) via Cronjob::setStatut()", LOG_INFO);

            // DEBUG FIX: Log avant création Cronjob
            $this->log("🔹 DEBUG: Creating Cronjob instance...", LOG_DEBUG);
            $cronjob = new Cronjob($this->db);
            $this->log("🔹 DEBUG: Cronjob instance created", LOG_DEBUG);

            // DEBUG FIX: Log avant fetch
            $this->log("🔹 DEBUG: CALLING cronjob->fetch($cronRowId)...", LOG_DEBUG);
            $fetchResult = $cronjob->fetch($cronRowId);
            $this->log("🔹 DEBUG: fetch returned: " . $fetchResult, LOG_DEBUG);

            if ($fetchResult <= 0) {
                $this->log("[ERROR] Failed to fetch historical import CRON (rowid=$cronRowId): " . $cronjob->error, LOG_ERR);
                $this->output .= "\n" . 'Failed to fetch CRON: ' . $cronjob->error;
                return -1;
            }

            // DEBUG FIX: Log avant setStatut
            $this->log("🔹 DEBUG: CALLING cronjob->setStatut(0)...", LOG_DEBUG);
            $result = $cronjob->setStatut(0); // 0 = Cronjob::STATUS_DISABLED
            $this->log("🔹 DEBUG: setStatut returned: " . $result, LOG_DEBUG);

            if ($result <= 0) {
                $this->log("[ERROR] Failed to disable historical import CRON: " . $cronjob->error, LOG_ERR);
                $this->output .= "\n" . 'Failed to disable CRON: ' . $cronjob->error;
                return -1;
            }

            $this->log("[OK] Historical import CRON successfully disabled (rowid=$cronRowId)", LOG_INFO);
            $this->output .= "\n" . 'Historical import CRON successfully disabled';

            // Étape 5: Se désactiver soi-même car mission accomplie
            $this->disableSelf($entity);

            return 0;

        } catch (Exception $e) {
            $this->error++;
            $this->errors[] = $e->getMessage();
            $this->output .= "\n" . 'Exception during cleanup: ' . $e->getMessage();
            $this->log("[ERROR] CLEANUP CRON exception: " . $e->getMessage(), LOG_ERR);
            return -1;
        }

        // FIX #152 v2.1.2: CATCH GLOBAL pour capturer erreurs fatales AVANT le try interne
        } catch (Exception $e) {
            $errorMsg = "[ERROR] FATAL EXCEPTION in executeCron(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString();
            dol_syslog($errorMsg, LOG_ERR);

            $this->output = $errorMsg;
            $this->error = 1;
            return -1;
        } catch (Error $e) {
            // Capturer aussi les Error PHP 7+ (TypeError, ParseError, etc.)
            $errorMsg = "[ERROR] FATAL PHP ERROR in executeCron(): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString();
            dol_syslog($errorMsg, LOG_ERR);

            $this->output = $errorMsg;
            $this->error = 1;
            return -1;
        }
    }

    /**
     * Désactive ce CRON lui-même après avoir terminé sa mission
     *
     * @param int $entity Entity ID
     * @return void
     */
    private function disableSelf($entity)
    {
        try {
            $this->log("[FIX] Self-disabling cleanup CRON (mission accomplished)", LOG_INFO);

            // Chercher ce CRON lui-même
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "cronjob
                    WHERE module_name = 'doli2shop'
                    AND classesname = '/doli2shop/class/shopifyhistoricalimportcleanupcron.class.php'
                    AND entity = " . (int)$entity;

            $resql = $this->db->query($sql);

            if ($resql && $this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $selfRowId = $obj->rowid;
                $this->db->free($resql);

                $cronjob = new Cronjob($this->db);

                if ($cronjob->fetch($selfRowId) > 0) {
                    $result = $cronjob->setStatut(0); // 0 = Cronjob::STATUS_DISABLED

                    if ($result > 0) {
                        $this->log("[OK] Cleanup CRON self-disabled successfully (rowid=$selfRowId)", LOG_INFO);
                        $this->output .= "\n" . 'Cleanup CRON self-disabled (mission complete)';
                    } else {
                        $this->log("[WARNING] Failed to self-disable cleanup CRON: " . $cronjob->error, LOG_WARNING);
                    }
                } else {
                    $this->log("[WARNING] Failed to fetch cleanup CRON for self-disable (rowid=$selfRowId)", LOG_WARNING);
                }
            } else {
                $this->log("[WARNING] Cleanup CRON not found for self-disable", LOG_WARNING);
                if ($resql) $this->db->free($resql);
            }

        } catch (Exception $e) {
            $this->log("[WARNING] Exception during self-disable: " . $e->getMessage(), LOG_WARNING);
        }
    }
}
