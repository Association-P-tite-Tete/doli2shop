<?php
/**
 * @file        admin/rights_check.php
 * @brief       Redirection PURE vers health.php — rétrocompatibilité des liens externes
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.0.27
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Story dix-ecrans-atteignables-par-aucun-lien (AC5, Validate 09/09/2026) : ce fichier redirigeait
// vers admin/diagnostic.php, qui redirige LUI-MÊME vers admin/health.php pour toute requête
// non-POST — un double saut. Ce fichier est conservé (rétrocompatibilité d'un éventuel lien
// externe déjà distribué) mais redirige désormais directement vers health.php, comme
// admin/support_config.php. Volontairement minimal : pas de chargement de l'environnement
// Dolibarr, cette page ne fait jamais rien d'autre que rediriger.
header('Location: health.php');
exit();