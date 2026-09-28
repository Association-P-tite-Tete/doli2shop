<?php

/**
 * @file        class/shopifyordersync.class.php
 * @brief This file contains the ShopifyOrderSync class
 * @note This class is used to manage the synchronization of orders between Dolibarr and Shopify
 *
 * @package ShopifyIntegration
 * @subpackage Class
 * @category class
 * @version     2.5.7
 * @since 2.0.0
 * @author      P'tite Tête <doli2shop@ptitetete.com>
 * @copyright   2022-2025 Robert Steinbacher<robert .steinbacher@xivtech.de>
 * @copyright   2022-2025 Thomas Meigen<info@meigensmartsolutions.de>
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @link        https://doli2shop.ptitetete.org
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/core/class/commonobject.class.php');
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';


class ShopifyOrderSync extends CommonObject
{
    use LoggerTrait;

    /** @var DoliDB Database handler */
    public $db;

    /** @var string Name of table without prefix where object is stored */
    public $table_element = 'doli2shop_orders';

    /** @var string Name of subtable line */
    public $table_element_line = '';

    /** @var string Element name */
    public $element = 'shopifyordersync';

    /** @var string Name of icon for shopifyordersync . Must be a 'fa-xxx' fontawesome code */
    public $picto = 'fa-shopping-cart';

    /** @var int 1 = Entity managed by getEntity() — Story 7.3 AC2, FR29 */
    public $ismultientitymanaged = 1;

    /** @var array Array of fields */
    public $fields = array(
        'id' =>  array('type' => 'integer', 'label' => 'ID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'index' => 1, 'comment' => "Id"),
        'entity' =>  array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 5, 'notnull' => 1, 'visible' => 0, 'default' => 1, 'index' => 1),
        'fk_commande' =>  array('type' => 'varchar(255)', 'label' => 'Fk_commande', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1),
        'shopifyOrderId' =>  array('type' => 'varchar(255)', 'label' => 'ShopifyOrderId', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1),
        'dolOrderFulfillment' =>  array('type' => 'varchar(255)', 'label' => 'DolOrderFulfillment', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1),
        'fk_store' =>  array('type' => 'integer', 'label' => 'FkStore', 'enabled' => 1, 'position' => 35, 'notnull' => 1, 'visible' => 0, 'default' => 0, 'index' => 1, 'comment' => "Boutique source (Epic 47-4)"),
        // Story ecart-de-totaux-shopify-dolibarr-signale-seulement-en-journal (AC1) : marque
        // persistée de l'écart de totaux détecté par ShopifyOrderManager::createOrder() (null si
        // aucun écart, cf. classifyTotalsMismatch()).
        'totals_mismatch_severity' =>  array('type' => 'varchar(20)', 'label' => 'TotalsMismatchSeverity', 'enabled' => 1, 'position' => 36, 'notnull' => 0, 'visible' => 0, 'default' => null, 'comment' => "null|rounding|proportional (Story ecart-de-totaux)"),
        'shopify_total_ttc' =>  array('type' => 'double(20,4)', 'label' => 'ShopifyTotalTtc', 'enabled' => 1, 'position' => 37, 'notnull' => 0, 'visible' => 0, 'default' => null, 'comment' => "Total TTC Shopify au moment de la création (si écart détecté)"),
        'dolibarr_total_ttc' =>  array('type' => 'double(20,4)', 'label' => 'DolibarrTotalTtc', 'enabled' => 1, 'position' => 38, 'notnull' => 0, 'visible' => 0, 'default' => null, 'comment' => "Total TTC Dolibarr au moment de la création (si écart détecté)"),
        'tms' =>  array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 0)
    );

    /** @var int ID */
    public $id;

    /** @var string Dolibarr Order ID */
    public $fk_commande;

    /** @var string Shopify Order ID */
    public $shopifyOrderId;

    /** @var string Order Fulfillment Status */
    public $dolOrderFulfillment;

    /** @var int Boutique source (rowid llx_doli2shop_stores, 0 = chemin historique) */
    public $fk_store = 0;

    /** @var string|null 'rounding'|'proportional', null si aucun écart détecté (Story ecart-de-totaux) */
    public $totals_mismatch_severity;

    /** @var float|null Total TTC Shopify au moment de la création (si écart détecté) */
    public $shopify_total_ttc;

    /** @var float|null Total TTC Dolibarr au moment de la création (si écart détecté) */
    public $dolibarr_total_ttc;

    /** @var string|null Timestamp */
    public $tms;

    /** @var int Entity */
    public $entity;

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
     * Create object
     *
     * @param User $user User that creates
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int <0 if KO, Id of created object if OK
     */
    public function create(User $user, $notrigger = false)
    {
        return $this->createCommon($user, $notrigger);
    }

    /**
     * Fetch object
     *
     * @param int $id ID
     * @param string $ref Ref
     * @return int <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }

    /**
     * Update object
     *
     * @param User $user User that updates
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int <0 if KO, 0 if not found, >0 if OK
     */
    public function update(User $user, $notrigger = false)
    {
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete object
     *
     * @param User $user User that deletes
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int <0 if KO, 0 if not found, >0 if OK
     */
    public function delete(User $user, $notrigger = false)
    {
        return $this->deleteCommon($user, $notrigger);
    }

    /**
    * Fetch all records
    *
    * @param string $sortorder Sort order
    * @param string $sortfield Sort field
    * @param int $limit Limit
    * @param int $offset Offset
    * @param string $filter Filter
    * @param string $filtermode Filter mode
    * @return array|int Array of records or -1 if error
    */
    public function fetchAll($sortorder = '', $sortfield = '', $limit = 1000, $offset = 0, string $filter = '', $filtermode = 'AND')
    {
        $this->log("", LOG_DEBUG);

        $records = array();

        $sql = "SELECT ";
        $sql .= $this->getFieldList('t');
        $sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";

        if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
            $sql .= " WHERE t.entity IN (" . getEntity($this->element) . ")";
        } else {
            $sql .= " WHERE 1 = 1";
        }

        // Manage filter
        $errormessage = '';
        $sql .= forgeSQLFromUniversalSearchCriteria($filter, $errormessage);
        if ($errormessage) {
            $this->errors[] = $errormessage;
            $this->log(implode(', ', $this->errors), LOG_ERR);
            return -1;
        }

        if (!empty($sortfield)) {
            $sql .= $this->db->order($sortfield, $sortorder);
        }
        if (!empty($limit)) {
            $sql .= $this->db->plimit($limit, $offset);
        }

        $resql = SqlUtils::executeQuery($this->db, $sql, "fetching order sync records", false);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            while ($i < ($limit ? min($limit, $num) : $num)) {
                $obj = $this->db->fetch_object($resql);

                $record = new self($this->db);
                $record->setVarsFromFetchObj($obj);

                $records[$record->id] = $record;

                $i++;
            }
            $this->db->free($resql);

            return $records;
        } else {
            $this->errors[] = 'Error ' . $this->db->lasterror();
            $this->log(implode(', ', $this->errors), LOG_ERR);

            return -1;
        }
    }
}
