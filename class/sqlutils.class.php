<?php

/**
 * @file        class/sqlutils.class.php
 * @brief This file contains the SqlUtils class
 * @note Utilities for SQL queries
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @version     2.0.16
 * @since       2.0.16
 * @author      P'tite Tête <doli2shop@ptitetete.com>
 * @copyright   2022-2025 Robert Steinbacher<robert.steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible avec chargement dans admin/ et par modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
// Load dependencies
dol_include_once('/core/lib/functions.lib.php');

/**
 * Class SqlUtils - Utilities for SQL queries
 */
class SqlUtils
{
    /**
     * Execute a SQL query with error handling and retry logic for lock timeouts
     *
     * @param DoliDB $db Database handler
     * @param string $sql SQL query to execute
     * @param string $errorContext Context description for error logging
     * @param bool $throwException Whether to throw exception on error (default true)
     * @param array $params Optional parameters for prepared statement
     * @param int $maxRetries Maximum number of retries for lock timeouts (default 3)
     * @return false|DoliDBResult False on error, result object on success
     * @throws Exception If throwException is true and query fails
     */
    public static function executeQuery($db, string $sql, string $errorContext, bool $throwException = true, ?array $params = null, int $maxRetries = 3)
    {
        // Log SQL query for debugging
        dol_syslog("SQL Query in context '$errorContext': " . $sql, LOG_DEBUG);
        if (is_array($params)) {
            dol_syslog("SQL Params: " . json_encode($params), LOG_DEBUG);
        }

        // Si nous avons des paramètres, remplacer les ? par les valeurs réelles
        if (is_array($params) && !empty($params)) {
            // Compter les paramètres dans la requête SQL
            $placeholders = substr_count($sql, '?');

            if ($placeholders > 0 && count($params) >= $placeholders) {
                // Construire manuellement la requête avec les valeurs réelles
                $parts = explode('?', $sql);
                $newSql = $parts[0];

                for ($i = 0; $i < $placeholders; $i++) {
                    $param = $params[$i];

                    if (is_null($param)) {
                        $newSql .= 'NULL';
                    } elseif (is_int($param) || is_float($param)) {
                        $newSql .= $param; // Les nombres peuvent être utilisés directement
                    } elseif (is_bool($param)) {
                        $newSql .= $param ? '1' : '0';
                    } else {
                        $newSql .= "'" . $db->escape($param) . "'"; // Échapper les chaînes
                    }

                    $newSql .= $parts[$i + 1];
                }

                $sql = $newSql;
            }
        }

        // Exécuter la requête avec retry logic pour les lock timeouts
        $attempt = 0;
        $lastError = '';
        $retryDelay = 1;

        while ($attempt < $maxRetries) {
            dol_syslog("Executing SQL (attempt " . ($attempt + 1) . "): " . $sql, LOG_DEBUG);
            $result = $db->query($sql);

            if ($result !== false) {
                dol_syslog("SQL Success in " . $errorContext, LOG_DEBUG);
                return $result;
            }

            $error = $db->lasterror();
            $lastError = $error;

            // Check if it's a lock timeout error
            if (preg_match('/Lock wait timeout exceeded|Deadlock found|database is locked/i', $error)) {
                $attempt++;
                if ($attempt < $maxRetries) {
                    dol_syslog("SQL lock timeout on attempt $attempt for $errorContext, retrying in $retryDelay seconds: $error", LOG_WARNING);
                    sleep($retryDelay);
                    $retryDelay = min($retryDelay * 2, 5); // Exponential backoff up to 5 seconds
                    continue;
                }
            }

            // If not a lock timeout or max retries reached, break and handle error
            break;
        }

        // Handle error after all attempts
        $errorMsg = "SQL Error in " . $errorContext . ": " . $lastError;
        if ($attempt >= $maxRetries) {
            $errorMsg = "SQL Error after $attempt attempts in " . $errorContext . ": " . $lastError;
        }
        dol_syslog($errorMsg, LOG_ERR);

        if ($throwException) {
            throw new Exception($errorMsg);
        }
        return false;
    }

    /**
     * Get module installation date with fallback logic
     * 
     * This method tries to determine when the Shopify Integration module was installed/activated
     * using a hierarchical fallback approach for maximum reliability.
     *
     * @param DoliDB $db Database handler
     * @param int $entity Entity ID (default 1)
     * @return string|null Installation date in 'Y-m-d H:i:s' format or null if cannot determine
     */
    public static function getModuleInstallationDate($db, int $entity = 1): ?string
    {
        dol_syslog("SqlUtils::getModuleInstallationDate - Attempting to get module installation date for entity $entity", LOG_DEBUG);
        
        // Priority 1: Try to get date from Dolibarr constants table (module activation)
        $sql = "SELECT datec FROM " . MAIN_DB_PREFIX . "const WHERE name = 'MAIN_MODULE_SHOPIFYINTEGRATION' AND entity = $entity ORDER BY datec ASC LIMIT 1";
        $result = self::executeQuery($db, $sql, "getting module activation date from constants", false);
        if ($result && $db->num_rows($result) > 0) {
            $obj = $db->fetch_object($result);
            if ($obj && $obj->datec) {
                dol_syslog("SqlUtils::getModuleInstallationDate - Found activation date from constants: " . $obj->datec, LOG_DEBUG);
                $db->free($result);
                return $obj->datec;
            }
            $db->free($result);
        }
        
        // Priority 2: Try to get table creation date from information_schema
        $database = $db->escape($db->database_name);
        $sql = "SELECT CREATE_TIME FROM information_schema.tables
                WHERE TABLE_SCHEMA = '".$database."'
                AND TABLE_NAME = '" . MAIN_DB_PREFIX . "doli2shop_storedetails'
                AND CREATE_TIME IS NOT NULL";
        $result = self::executeQuery($db, $sql, "getting table creation date", false);
        if ($result && $db->num_rows($result) > 0) {
            $obj = $db->fetch_object($result);
            if ($obj && $obj->CREATE_TIME) {
                dol_syslog("SqlUtils::getModuleInstallationDate - Found table creation date: " . $obj->CREATE_TIME, LOG_DEBUG);
                $db->free($result);
                return $obj->CREATE_TIME;
            }
            $db->free($result);
        }
        
        // Priority 3: Try to get first configuration date from store details
        $sql = "SELECT MIN(datec) as first_config FROM " . MAIN_DB_PREFIX . "doli2shop_storedetails WHERE entity = $entity";
        $result = self::executeQuery($db, $sql, "getting first configuration date", false);
        if ($result && $db->num_rows($result) > 0) {
            $obj = $db->fetch_object($result);
            if ($obj && $obj->first_config) {
                dol_syslog("SqlUtils::getModuleInstallationDate - Found first configuration date: " . $obj->first_config, LOG_DEBUG);
                $db->free($result);
                return $obj->first_config;
            }
            $db->free($result);
        }
        
        // Priority 4: Fallback to default date (6 months ago)
        $fallbackDate = date('Y-m-d H:i:s', strtotime('-6 months'));
        dol_syslog("SqlUtils::getModuleInstallationDate - Using fallback date (6 months ago): " . $fallbackDate, LOG_INFO);
        return $fallbackDate;
    }
}
