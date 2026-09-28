<?php
/**
 * @file        lib/version.lib.php
 * @brief       Source unique de la version du module Doli2Shop
 *
 * Ce fichier est la SEULE source de vérité pour le numéro de version.
 * Tous les autres fichiers (descriptor, triggers, website, API) doivent
 * lire la constante DOLI2SHOP_MODULE_VERSION définie ici.
 *
 * Pour mettre à jour la version : ./update_version.sh X.X.X
 *
 * @package     ShopifyIntegration
 * @subpackage  Lib
 * @category    lib
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.2.1
 * @link        https://doli2shop.ptitetete.org
 */

// =============================================
// VERSION MODULE — SOURCE UNIQUE
// =============================================

if (!defined('DOLI2SHOP_MODULE_VERSION')) {
    /**
     * Version actuelle du module Doli2Shop
     * Format : MAJOR.MINOR.PATCH (semver)
     */
    define('DOLI2SHOP_MODULE_VERSION', '2.5.7');
}

if (!defined('DOLI2SHOP_MIN_DOLIBARR_VERSION')) {
    /**
     * Version minimale de Dolibarr supportée.
     *
     * ⚠️ DOIT rester alignée sur `need_dolibarr_version` du descripteur
     * (`core/modules/modDoli2Shop.class.php`), qui est ce que le cœur applique réellement à
     * l'activation. Le 10/09/2026, le descripteur est passé à 23.0 sans que ces constantes
     * suivent : l'API publique a annoncé pendant trois jours une plage 18.0.0 → 23.99.99 que
     * le module refusait d'honorer — un prospect en 18 lisait « compatible », achetait, et
     * l'activation échouait. Un filet le verrouille désormais (test/unit/).
     */
    define('DOLI2SHOP_MIN_DOLIBARR_VERSION', '18.0.0');
}

if (!defined('DOLI2SHOP_MAX_DOLIBARR_VERSION')) {
    /**
     * Version maximale de Dolibarr supportée. Plafond glissant sur la dernière version publiée
     * (règle des 4 modules, `CLAUDE.dolibarr.md` §11) : à relever à chaque majeure validée.
     */
    define('DOLI2SHOP_MAX_DOLIBARR_VERSION', '24.99.99');
}

/**
 * Retourne la version du module
 *
 * @return string Version au format semver (ex: "2.2.1")
 */
function doli2shop_get_version()
{
    return DOLI2SHOP_MODULE_VERSION;
}
