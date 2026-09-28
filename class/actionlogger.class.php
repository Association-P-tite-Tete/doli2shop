<?php
/**
 * @file        class/actionlogger.class.php
 * @brief       Journal des actions module (Story 38.3)
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.4.2
 * @since       2.3.0
 * @link        https://doli2shop.ptitetete.org
 */

/**
 * Class ActionLogger
 *
 * Journal léger des actions à forte valeur de diagnostic du module, déclenchées
 * hors flux webhook_events (clôtures automatiques, skips d'idempotence, erreurs CRON).
 * Complément UI de dol_syslog (qui n'est lisible qu'en accès fichier) — PAS un remplacement.
 *
 * Écrit dans llx_doli2shop_action_log. Toutes les méthodes sont NON-BLOQUANTES :
 * une erreur d'écriture de log ne doit jamais faire échouer le traitement métier.
 */
class ActionLogger
{
    /** Types d'action connus (utilisés pour le filtre de la page admin) */
    const TYPE_ORDER_AUTO_CLOSED = 'order_auto_closed';
    const TYPE_EXPEDITION_SKIPPED_DUPLICATE = 'expedition_skipped_duplicate';
    /** Story 52-2 : recalage initial en masse du stock Dolibarr → Shopify (action admin explicite) */
    const TYPE_STOCK_RECALAGE = 'stock_recalage';
    /** Story 54-2 : push stock ciblé d'UN produit via l'API REST (POST /stock/push/{productId}) */
    const TYPE_STOCK_PUSH = 'stock_push';
    /** Story 54-2 : rattrapage commandes manquantes déclenché via l'API REST (POST /orders/catchup) */
    const TYPE_ORDERS_CATCHUP = 'orders_catchup';
    /** Story 54-2 : resync produit ciblée déclenchée via l'API REST (POST /products/sync/{productId}) */
    const TYPE_PRODUCTS_SYNC = 'products_sync';
    /** Hotfix 2.4.4 (2/2) : réparation batch des imports existants en vraies déclinaisons Dolibarr */
    const TYPE_VARIANT_REPAIR = 'variant_repair';
    /** Story 52-4 : drainage asynchrone de la file de push stock Dolibarr → Shopify (≠ TYPE_STOCK_RECALAGE, action admin ponctuelle) */
    const TYPE_STOCK_PUSH_QUEUE = 'stock_push_queue';
    /** Hotfix 2.4.5 (AC4) : recorrection batch des remises % sous-évaluées à l'import (pré-v2.2.9) */
    const TYPE_DISCOUNT_REPAIR = 'discount_repair';
    /** Story 58-5 (AC4) : traitement des produits hors vente déjà poussés sur Shopify (OffSaleProductsService) */
    const TYPE_OFFSALE_PRODUCTS = 'offsale_products';
    /** Story reprise-des-commandes-importees-en-prix-hors-taxes : audit (jamais de correction) des commandes importées sous-évaluées avant e825f456 (UnderpricedOrderAuditService) */
    const TYPE_UNDERPRICE_AUDIT = 'underprice_audit';

    /** Résultats */
    const RESULT_SUCCESS = 'success';
    const RESULT_ERROR = 'error';
    const RESULT_SKIPPED = 'skipped';

    /**
     * Enregistre une action dans le journal module (non-bloquant).
     *
     * @param  DoliDB      $db         Connexion base
     * @param  int         $entity     Entité courante
     * @param  string      $actionType Type d'action (cf. constantes TYPE_*)
     * @param  string|null $objectType Type d'objet Dolibarr (commande|facture|shipping|product)
     * @param  int|null    $objectId   Rowid de l'objet Dolibarr
     * @param  string      $result     Résultat (cf. constantes RESULT_*)
     * @param  string      $message    Message court (tronqué à 500 caractères)
     * @param  int|null    $fkStore    Story 54-2 : boutique source de l'action (rowid), NULL si
     *                                 non applicable/inconnue (comble la dette v2.3.9 : la colonne
     *                                 existe depuis `sql/update_2.3.8_2.3.9.sql` mais aucun writer
     *                                 ne la renseignait). Purement additif, rétrocompatible : les
     *                                 appelants existants (7 arguments) continuent d'écrire NULL.
     * @return bool  true si inséré, false sinon (jamais d'exception propagée)
     */
    public static function log($db, $entity, $actionType, $objectType, $objectId, $result, $message, $fkStore = null)
    {
        try {
            if (empty($db) || empty($actionType)) {
                return false;
            }

            $entity = (int) $entity;
            if ($entity <= 0) {
                // Contexte CLI/CRON où l'entité n'est pas initialisée : rattacher à l'entité par défaut
                $entity = 1;
            }
            $objectId = ($objectId !== null && $objectId !== '') ? (int) $objectId : null;
            // Invariant §14 CLAUDE.dolibarr.md : fk_store conditionnel — seule une valeur > 0
            // (boutique identifiée) est écrite, sinon NULL (global/inconnu, jamais 0 en dur).
            $fkStore = ($fkStore !== null && (int) $fkStore > 0) ? (int) $fkStore : null;
            $message = (string) $message;
            if (function_exists('dol_trunc')) {
                $message = dol_trunc($message, 500, 'right', 'UTF-8', 1);
            } elseif (mb_strlen($message) > 500) {
                $message = mb_substr($message, 0, 500);
            }

            $dateAction = function_exists('dol_now') ? dol_now() : time();
            $dateSql = method_exists($db, 'idate') ? $db->idate($dateAction) : date('Y-m-d H:i:s', $dateAction);

            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_action_log";
            $sql .= " (date_action, action_type, object_type, object_id, result, message, entity, fk_store)";
            $sql .= " VALUES (";
            $sql .= "'" . $db->escape($dateSql) . "',";
            $sql .= "'" . $db->escape($actionType) . "',";
            $sql .= ($objectType !== null ? "'" . $db->escape($objectType) . "'" : "NULL") . ",";
            $sql .= ($objectId !== null ? ((int) $objectId) : "NULL") . ",";
            $sql .= ($result !== null && $result !== '' ? "'" . $db->escape($result) . "'" : "NULL") . ",";
            $sql .= "'" . $db->escape($message) . "',";
            $sql .= $entity . ",";
            $sql .= ($fkStore !== null ? (int) $fkStore : "NULL");
            $sql .= ")";

            $resql = $db->query($sql);
            return $resql ? true : false;
        } catch (\Throwable $e) {
            // Non-bloquant : on avale toute erreur (le log ne doit jamais casser le métier)
            if (function_exists('dol_syslog')) {
                dol_syslog('ActionLogger::log - non-blocking error: ' . $e->getMessage(), LOG_WARNING);
            }
            return false;
        }
    }
}
