<?php
/**
 * @file        lib/compatibility.lib.php
 * @brief       Shopify Integration compatibility functions for older Dolibarr versions
 *
 * @package     ShopifyIntegration
 * @subpackage  Lib
 * @category    lib
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.34
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible avec scan modules Dolibarr)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

/**
 * Polyfills for Dolibarr compatibility with versions < 16
 * These functions were introduced in Dolibarr 16.0 and are required for our module
 */

// getDolGlobalString - Returns string value from global configuration
if (!function_exists('getDolGlobalString')) {
    /**
     * Get global string constant value with fallback
     * @param string $key     Name of constant
     * @param string $default Default value if constant doesn't exist
     * @return string         Value of the constant or default
     * @since 2.0.34 Compatibility function for Dolibarr < 16
     */
    function getDolGlobalString($key, $default = '')
    {
        global $conf;
        return isset($conf->global->$key) ? (string)$conf->global->$key : $default;
    }
}

// getDolGlobalInt - Returns integer value from global configuration
if (!function_exists('getDolGlobalInt')) {
    /**
     * Get global integer constant value with fallback
     * @param string $key     Name of constant
     * @param int    $default Default value if constant doesn't exist
     * @return int            Value of the constant or default
     * @since 2.0.34 Compatibility function for Dolibarr < 16
     */
    function getDolGlobalInt($key, $default = 0)
    {
        global $conf;
        return isset($conf->global->$key) ? (int)$conf->global->$key : $default;
    }
}

// getDolGlobalFloat - Returns float value from global configuration
if (!function_exists('getDolGlobalFloat')) {
    /**
     * Get global float constant value with fallback
     * @param string $key     Name of constant
     * @param float  $default Default value if constant doesn't exist
     * @return float          Value of the constant or default
     * @since 2.0.34 Compatibility function for Dolibarr < 16
     */
    function getDolGlobalFloat($key, $default = 0.0)
    {
        global $conf;
        return isset($conf->global->$key) ? (float)$conf->global->$key : $default;
    }
}

// getDolGlobalBool - Returns boolean value from global configuration
if (!function_exists('getDolGlobalBool')) {
    /**
     * Get global boolean constant value
     * @param string $key Name of constant
     * @return bool       True if constant exists and is not empty
     * @since 2.0.34 Compatibility function for Dolibarr < 21
     */
    function getDolGlobalBool($key)
    {
        global $conf;
        return !empty($conf->global->$key);
    }
}

// verifToken - Verifies CSRF token for form protection
if (!function_exists('verifToken')) {
    /**
     * Verify CSRF token to protect against cross-site request forgery
     *
     * Hotfix 2.4.6 (2/2) : comparait à tort au jeton `newToken()` (= `$_SESSION['newtoken']`,
     * destiné à la PROCHAINE requête) au lieu du jeton réellement soumis avec le formulaire
     * (`$_SESSION['token']`), celui auquel le core Dolibarr compare (`main.inc.php:417-419`).
     * Sur toute installation avec `MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL` activé, le
     * jeton posté ne matchait alors JAMAIS `newToken()` : toutes les actions admin du module
     * étaient sautées en silence. Décision Validate 2026-08-03 (AC2) : PAS de repli sur
     * `newToken()` — la rotation du core (`$_SESSION['token'] = $_SESSION['newtoken']`)
     * s'exécute avant le code du module, donc `$_SESSION['token']` contient toujours le jeton
     * soumis ; accepter `newToken()` en second recours reviendrait à réintroduire la même
     * classe de bug (jeton valide seulement pour la requête suivante).
     *
     * @return bool True if token is valid, false otherwise
     * @since 2.1.6 Security function for CSRF protection
     * @since 2.4.6 Compare à $_SESSION['token'] (jeton soumis) au lieu de newToken()
     */
    function verifToken()
    {
        $token = GETPOST('token', 'aZ09');
        if (empty($token)) {
            return false;
        }
        return !empty($_SESSION['token']) && $token === $_SESSION['token'];
    }
}

// currentToken - Returns the CURRENT CSRF token ($_SESSION['token']), for AJAX callers
if (!function_exists('currentToken')) {
    /**
     * Return the value of the token currently saved into session under the name 'token'.
     *
     * Story 59-8 (AC3) : vérifié présent dans le core Dolibarr depuis la v10.0.7
     * (`core/lib/functions.lib.php`, docblock `@since Dolibarr v10.0.7`) — largement antérieur
     * au plancher de compatibilité du module (19.0+). La fonction n'a donc jamais dû manquer
     * en pratique sur une installation supportée. Polyfill ajouté malgré tout par défense en
     * profondeur, au même titre que verifToken()/getDolGlobalBool()/dol_time_plus_duree() :
     * son absence provoquerait une erreur fatale (Call to undefined function) sur tout endpoint
     * AJAX construisant un jeton via currentToken() (story 59-8), pas une simple dégradation.
     *
     * Pour un appel AJAX, la page JS appelante doit utiliser CE jeton (courant, sans rotation)
     * ET l'endpoint ajax/*.php appelé doit déclarer la constante NOTOKENRENEWAL (story 59-7) —
     * sans quoi le jeton COURANT ne correspondra jamais à celui attendu par l'endpoint après
     * rotation.
     *
     * @return string Jeton courant, ou chaîne vide si absent de la session
     * @since 2.4.8 Polyfill défensif pour Dolibarr < 10.0.7 (story 59-8)
     */
    function currentToken()
    {
        return isset($_SESSION['token']) ? $_SESSION['token'] : '';
    }
}

// dol_time_plus_duree - Adds a duration to a timestamp
// Defined in core/lib/date.lib.php but not always loaded in webhook/CRON context
if (!function_exists('dol_time_plus_duree')) {
    /**
     * Add a delay to a date
     * @param int    $time     Timestamp
     * @param int    $duration Duration value to add
     * @param string $unit     Unit: 'd' for days, 'm' for months, 'y' for years
     * @return int             New timestamp
     * @since 2.2.0 Compatibility polyfill for webhook context
     */
    function dol_time_plus_duree($time, $duration, $unit = 'd')
    {
        if ($unit == 'd') {
            return $time + (86400 * $duration);
        } elseif ($unit == 'm') {
            $dt = new DateTime();
            $dt->setTimestamp($time);
            $dt->modify('+' . (int)$duration . ' months');
            return $dt->getTimestamp();
        } elseif ($unit == 'y') {
            $dt = new DateTime();
            $dt->setTimestamp($time);
            $dt->modify('+' . (int)$duration . ' years');
            return $dt->getTimestamp();
        }
        return $time + (86400 * $duration);
    }
}

/**
 * Log compatibility info for debugging
 * This helps track which compatibility functions are being used
 */
if (getDolGlobalInt('SHOPIFY_LOG_COMPATIBILITY', 0)) {
    $compatFunctions = [];
    if (!function_exists('getDolGlobalString')) $compatFunctions[] = 'getDolGlobalString';
    if (!function_exists('getDolGlobalInt')) $compatFunctions[] = 'getDolGlobalInt';
    if (!function_exists('getDolGlobalFloat')) $compatFunctions[] = 'getDolGlobalFloat';
    if (!function_exists('getDolGlobalBool')) $compatFunctions[] = 'getDolGlobalBool';

    if (!empty($compatFunctions)) {
        dol_syslog("ShopifyIntegration: Using compatibility functions for Dolibarr < 16: " . implode(', ', $compatFunctions), LOG_INFO);
    }
}