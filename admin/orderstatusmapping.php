<?php
/**
 * @file        admin/orderstatusmapping.php
 * @brief       Redirection PURE vers setup.php?tab=orders — écran « Mappage des statuts » retiré
 *
 * @package     ShopifyIntegration
 * @subpackage  Admin
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.2
 * @since       2.0.0
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Story 68-1 (2.6.1) : l'écran « Mappage des statuts de commande » est retiré. Il pilotait la
// synchronisation des statuts de Dolibarr vers Shopify, qui n'a jamais fonctionné
// (ShopifyApi n'a ni createOrder, ni updateOrder, ni markOrderAsPaid, ni createFulfillment), et
// ses tables ne pilotent aucun comportement (seul OrderSyncToShopify les lit, en no-op journalisé). Les tables et le mapper sont conservés
// (suppression éventuelle dans une story ultérieure, avec migration idempotente).
//
// Ce fichier est REMPLACÉ par une redirection au lieu d'être supprimé : Dolibarr n'efface jamais
// un fichier à la mise à jour, donc une suppression laisserait l'ancien écran — et ses actions
// POST d'écriture — installé sur toutes les installations existantes. Volontairement minimal :
// pas de chargement de l'environnement Dolibarr, cette page ne fait jamais rien d'autre que
// rediriger (même patron que admin/rights_check.php).
// Le contexte de boutique (multi-boutiques) est conservé : entier strictement positif seulement.
$redirectStoreId = (isset($_GET['store_id']) && is_string($_GET['store_id']) && ctype_digit($_GET['store_id'])) ? (int) $_GET['store_id'] : 0;
header('Location: setup.php?tab=orders' . ($redirectStoreId > 0 ? '&store_id=' . $redirectStoreId : ''));
exit();
