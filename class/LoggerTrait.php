<?php
/**
 * @file        class/LoggerTrait.php
 * @brief       Trait for logging with class prefix in ShopifyIntegration module
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.0.19
 * @since       2.0.19
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible avec chargement dans init() et par modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';
require_once dirname(__FILE__) . '/modulelogfile.class.php';

trait LoggerTrait
{
    /**
     * Log message with class prefix and production optimization
     *
     * @param string $message Message to log
     * @param int $level Log level (LOG_DEBUG, LOG_INFO, LOG_WARNING, LOG_ERR)
     * @return void
     * @version     2.0.33 Added production logging optimization
     */
    protected function log(string $message, int $level = LOG_INFO): void
    {
        global $conf;
        
        // Optimize logging in production: skip DEBUG logs based on Dolibarr's SYSLOG_LEVEL
        //
        // ⚠️ Depuis l'ajout du journal fichier du module, ce raccourci ne peut plus décider seul.
        // Le but d'un journal à nous est justement de pouvoir monter en verbosité POUR NOUS, le
        // temps d'un diagnostic, SANS passer tout Dolibarr en DEBUG — ce qui gonflerait encore un
        // fichier partagé qui atteignait déjà 323 Mo chez un client. On ne sort donc que si les
        // DEUX destinations refusent le DEBUG.
        if ($level == LOG_DEBUG) {
            $globalSyslogLevel = getDolGlobalInt('SYSLOG_LEVEL', LOG_INFO);
            $fileWantsDebug = ModuleLogFile::isEnabled() && ModuleLogFile::threshold() >= LOG_DEBUG;

            if ($globalSyslogLevel > LOG_DEBUG && !$fileWantsDebug) {
                return; // Ni Dolibarr ni notre fichier ne veulent du DEBUG
            }

            // Notre fichier veut du DEBUG mais pas Dolibarr : on n'écrit QUE dans le nôtre.
            if ($globalSyslogLevel > LOG_DEBUG) {
                $backtraceDebug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                $callerDebug = $backtraceDebug[1]['function'] ?? 'unknown';
                ModuleLogFile::write(get_class($this) . '::' . $callerDebug . ' - ' . $message, $level);
                return;
            }
        }
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[1]['function'] ?? 'unknown';
        $prefixed = get_class($this) . '::' . $caller . ' - ' . $message;

        dol_syslog($prefixed, $level);

        // Et dans NOTRE fichier. Ce point de passage unique est la raison pour laquelle le
        // journal dédié ne demande aucune modification des quelque 1 700 appels à log() du
        // module : tout y transite déjà.
        //
        // Le doublon avec dol_syslog() est VOULU. Le fichier de Dolibarr reste la référence pour
        // qui sait s'en servir ; le nôtre est celui que le client peut nous envoyer — chez un
        // client de septembre 2026, le fichier partagé pesait 323 Mo, donc ni transmissible ni
        // lisible. Écrire deux fois coûte un appel disque ; ne pas le faire coûte des jours
        // d'allers-retours.
        ModuleLogFile::write($prefixed, $level);
    }
}
