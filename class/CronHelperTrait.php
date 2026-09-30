<?php

/**
 * @file        class/CronHelperTrait.php
 * @brief       Helper trait for CRON jobs - auto-unfreeze and lock management
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.1.2
 * @since       2.1.2
 * @link        https://doli2shop.ptitetete.org
 */

/**
 * Trait CronHelperTrait
 *
 * Provides common helper methods for CRON jobs
 *
 * FIX v2.1.2: Libération locks SQL + auto-déblocage CRONs gelés
 * - Résout problème CRONs restant en jaune (processing=1)
 * - Libère locks transactionnels via SELECT avant new ShopifyApi()
 * - Auto-déblocage CRONs bloqués depuis > 1h
 *
 * Usage: use CronHelperTrait; dans les classes CRON
 * Requirements: $this->db, $this->log(), $this->output doivent exister
 *
 * @property DoliDB $db
 * @property string $output
 */
trait CronHelperTrait
{
    /**
     * Check if CRON is frozen and auto-unfreeze if necessary
     *
     * Cette méthode fait 2 choses critiques:
     * 1. SELECT sur llx_cronjob → Libère les locks transactionnels MySQL
     * 2. Si CRON bloqué > 1h → Auto-déblocage (processing=0)
     *
     * FIX v2.1.2: Résout deadlock quand Dolibarr fait UPDATE processing=1
     * avec transaction active, puis CRON appelle new ShopifyApi() qui essaie
     * de lire llx_const → timeout 60s → processus tué silencieusement
     *
     * @param int $entity Entity ID
     * @param string $classesname CRON class path (ex: '/doli2shop/class/shopifyordersynccron.class.php')
     * @return void
     */
    protected function checkAndUnfreezeCron(int $entity, string $classesname): void
    {
        try {
            // SELECT simple pour libérer locks transactionnels potentiels
            // Cette requête DOIT être exécutée AVANT new ShopifyApi() / new Cronjob()
            $sql = "SELECT processing, datelastrun, pid FROM " . MAIN_DB_PREFIX . "cronjob
                    WHERE module_name = 'doli2shop'
                    AND classesname = '" . $this->db->escape($classesname) . "'
                    AND entity = " . (int)$entity;

            $resql = $this->db->query($sql);
            if ($resql && $obj = $this->db->fetch_object($resql)) {
                if ($obj->processing == 1) {
                    // Utiliser datelastrun au lieu de datec (datec = date création, pas date run actuel)
                    $blockedSince = $this->db->jdate($obj->datelastrun);
                    $blockedDuration = time() - $blockedSince;

                    // Auto-déblocage si CRON gelé depuis > 5 min
                    // Ne PAS débloquer si c'est le run actuel (PID correspond ou durée < 5 min)
                    if ($blockedDuration > 300 && $obj->pid != getmypid()) {
                        $this->log("[WARNING] CRON frozen since " . dol_print_date($blockedSince, 'dayhour') .
                                  " (" . round($blockedDuration / 60) . " minutes). Auto-unlocking...", LOG_WARNING);

                        $sqlUnlock = "UPDATE " . MAIN_DB_PREFIX . "cronjob SET processing = 0
                                     WHERE module_name = 'doli2shop'
                                     AND classesname = '" . $this->db->escape($classesname) . "'
                                     AND entity = " . (int)$entity;
                        $this->db->query($sqlUnlock);

                        $this->output .= "\n[WARNING] CRON was frozen - auto-unlocked";
                        $this->log("[OK] CRON successfully unlocked", LOG_INFO);
                    }
                }
            }
        } catch (Exception $e) {
            // Ne pas faire échouer le CRON pour un problème de check
            // Le SELECT a quand même été exécuté → locks libérés
            $this->log("[WARNING] Failed to check CRON freeze status: " . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Acquiert un verrou advisory MySQL pour empêcher la double-exécution d'un CRON (Story 36.4).
     *
     * Utilise GET_LOCK (non bloquant par défaut, timeout 0). Le double-traitement d'un
     * event individuel reste protégé par l'atomic claim de WebhookManager ; ce verrou
     * complète au niveau du run complet (évite gaspillage + faux duplicates concurrents).
     *
     * ⚠️ NON RÉENTRANT (LOW, review 3 couches 27/09/2026) : `GET_LOCK` MySQL/MariaDB n'a **pas**
     * de compteur de références. Un second `acquireCronLock()` sur le **même nom**, obtenu sur la
     * même connexion pendant que le premier est encore détenu, réussit silencieusement (MySQL
     * autorise une connexion à ré-acquérir son propre verrou) — mais un `releaseCronLock()`
     * imbriqué libère alors le niveau **englobant**, pas seulement le niveau interne : le verrou
     * extérieur se retrouve libéré alors que son appelant croit toujours le détenir. Ne **jamais**
     * imbriquer deux `acquireCronLock()`/`releaseCronLock()` portant le **même nom** de verrou sur
     * un même parcours d'exécution (ex. une méthode qui en appelle une autre protégée par le même
     * verrou) — scoper des noms distincts si un tel appel imbriqué est un jour nécessaire.
     *
     * @param  string $lockName Nom logique du job (préfixé/borné en interne)
     * @param  int    $timeout  Secondes d'attente (0 = non bloquant, retour immédiat)
     * @return bool  true si le verrou est acquis, false sinon (déjà détenu ou erreur)
     */
    protected function acquireCronLock(string $lockName, int $timeout = 0): bool
    {
        try {
            $safeName = $this->buildCronLockName($lockName);
            $sql = "SELECT GET_LOCK('" . $this->db->escape($safeName) . "', " . ((int) $timeout) . ") as locked";
            $resql = $this->db->query($sql);
            if (!$resql) {
                return false;
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            // GET_LOCK retourne 1 si acquis, 0 si timeout, NULL si erreur
            return ($obj && isset($obj->locked) && (int) $obj->locked === 1);
        } catch (\Throwable $e) {
            $this->log("[WARNING] acquireCronLock failed for " . $lockName . ": " . $e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Libère un verrou advisory MySQL acquis par acquireCronLock() (Story 36.4).
     *
     * @param  string $lockName Même nom logique que celui passé à acquireCronLock()
     * @return void
     */
    protected function releaseCronLock(string $lockName): void
    {
        try {
            $safeName = $this->buildCronLockName($lockName);
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($safeName) . "')");
        } catch (\Throwable $e) {
            // Non bloquant : le lock est de toute façon auto-libéré en fin de session MySQL
            $this->log("[WARNING] releaseCronLock failed for " . $lockName . ": " . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Construit un nom de lock borné (préfixe module + entité), <= 64 chars (limite MySQL).
     *
     * @param  string $lockName Nom logique du job
     * @return string Nom de lock final
     */
    private function buildCronLockName(string $lockName): string
    {
        global $conf;
        $entity = (int) ($conf->entity ?? 1);
        $name = 'doli2shop_cron_' . $lockName . '_' . $entity;
        if (strlen($name) > 64) {
            // GET_LOCK limite à 64 chars (MySQL 8+) — tronquer + alerter (risque collision)
            $this->log("[WARNING] CRON lock name truncated to 64 chars: " . $name, LOG_WARNING);
            $name = substr($name, 0, 64);
        }
        return $name;
    }

    /**
     * Get module version and build ID for logging purposes
     *
     * Cette méthode permet d'identifier précisément quelle version du module
     * et quel build (MD5 du fichier CRON) est en cours d'exécution.
     *
     * Utilité production:
     * - Traçabilité des versions déployées
     * - Debug facilité (savoir quel code exact est exécuté)
     * - Détection problèmes après mises à jour
     *
     * FIX #152 v2.1.2: Factorisation code dupliqué dans CRONs historique/cleanup
     *
     * @return array ['version' => 'X.Y.Z', 'build' => 'abc12345']
     */
    protected function getModuleVersionInfo(): array
    {
        $version = 'unknown';
        $build = 'unknown';

        try {
            dol_include_once('/doli2shop/core/modules/modDoli2Shop.class.php');
            $tempModule = new modDoli2Shop($this->db);
            $version = $tempModule->version;

            // Build ID = 8 premiers caractères MD5 du fichier CRON appelant
            // Utilise ReflectionClass pour obtenir le fichier de la classe réelle (pas du trait)
            $classFile = (new ReflectionClass($this))->getFileName();
            if (file_exists($classFile)) {
                $build = substr(md5_file($classFile), 0, 8);
            }
        } catch (Exception $e) {
            // Ignorer erreur récupération version - ne pas faire échouer le CRON
        }

        return ['version' => $version, 'build' => $build];
    }
}
