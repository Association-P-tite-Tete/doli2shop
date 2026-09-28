<?php

/**
 * @file        class/paymentmethodsmapping.class.php
 * @brief This file contains the PaymentMethodsMapping class
 * @note Handles mapping between Shopify payment methods and Dolibarr payment methods
 *      This class manages the mapping between Shopify payment methods and
 *      Dolibarr payment methods .
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @version     2.5.7
 * @since       2.0.14
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
dol_include_once('/core/class/commonobject.class.php');
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';

class PaymentMethodsMapping extends CommonObject
{
    use LoggerTrait;

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'doli2shop_payments';
    /** @var string Module name */
    public $element = 'paymentmethodsmapping';
    /** @var DoliDB Database handler */
    public $db;

    /**
     * @var array  Array with all fields and their property
     */
    public $fields = array(
        'rowid'              =>  array('type' => 'integer', 'label' => 'ID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'index' => 1),
        'entity'             =>  array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 0, 'default' => 1),
        'shopify_payment_method' =>  array('type' => 'varchar(255)', 'label' => 'ShopifyPaymentMethod', 'enabled' => 1, 'position' => 30, 'notnull' => 1),
        'dolibarr_payment_id' =>  array('type' => 'integer', 'label' => 'DolibarrPaymentId', 'enabled' => 1, 'position' => 40, 'notnull' => 1),
        'active'             =>  array('type' => 'integer', 'label' => 'Active', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'default' => 1)
    );

    public $rowid;
    public $entity;
    public $shopify_payment_method;
    public $dolibarr_payment_id;
    public $active;

    /**
     * Correspondances de paiement par défaut (Story multicompany-seed-paiements-jamais-pose-en-entite-secondaire).
     *
     * Source de vérité unique pour le seeding : remplace l'ancien INSERT SQL de
     * sql/llx_doli2shop_payments.sql qui figeait `entity = 1` (voir ensureDefaultMappings()).
     *
     * @var array<int, array{shopify_payment_method: string, dolibarr_payment_id: int}>
     */
    private const DEFAULT_PAYMENT_MAPPINGS = [
        ['shopify_payment_method' => 'card',      'dolibarr_payment_id' => 6],
        ['shopify_payment_method' => 'bank',      'dolibarr_payment_id' => 2],
        ['shopify_payment_method' => 'check',     'dolibarr_payment_id' => 7],
        ['shopify_payment_method' => 'paypal',    'dolibarr_payment_id' => 6],
        ['shopify_payment_method' => 'applepay',  'dolibarr_payment_id' => 6],
        ['shopify_payment_method' => 'googlepay', 'dolibarr_payment_id' => 6],
        ['shopify_payment_method' => 'stripe',    'dolibarr_payment_id' => 6],
        ['shopify_payment_method' => 'cash',      'dolibarr_payment_id' => 4],
    ];

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
     * Create object into database
     *
     * @param  User $user      User that creates
     * @param  bool $notrigger false=launch triggers after, true=disable triggers
     * @return int             <0 if KO, Id of created object if OK
     */
    public function create(User $user, $notrigger = false)
    {
        return $this->createCommon($user, $notrigger);
    }

    /**
     * Load object in memory from the database
     *
     * @param int    $id   Id object
     * @param string $ref  Ref
     * @return int         <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }

    /**
     * Update object into database
     *
     * @param  User $user      User that modifies
     * @param  bool $notrigger false=launch triggers after, true=disable triggers
     * @return int             <0 if KO, >0 if OK
     */
    public function update(User $user, $notrigger = false)
    {
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete object in database
     *
     * @param  User $user      User that deletes
     * @param  bool $notrigger false=launch triggers after, true=disable triggers
     * @return int             <0 if KO, >0 if OK
     */
    public function delete(User $user, $notrigger = false)
    {
        return $this->deleteCommon($user, $notrigger);
    }

    /**
    * Load list of objects in memory from the database .
    *
    * @param  string      $sortorder    Sort Order
    * @param  string      $sortfield    Sort field
    * @param  int         $limit        Limit the number of lines returned
    * @param  int         $offset       Offset
    * @param  string      $filter       Filter as an Universal Search string
    * @param  string      $filtermode   No more used
    * @return array|int                 int <0 if KO, array of pages if OK
    */
    public function fetchAll($sortorder = '', $sortfield = '', $limit = 1000, $offset = 0, string $filter = '', $filtermode = 'AND')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element;
        if ($filter) {
            // Sécurisation: parser le filtre universal search pour prévenir les injections SQL
            $sqlWhere = $this->convertFilterToSafeSqlWhere($filter);
            if (!empty($sqlWhere)) {
                $sql .= " WHERE " . $sqlWhere;
            }
        }
        if ($limit) {
            $sql .= $this->db->plimit($limit, $offset);
        }

        $resql = SqlUtils::executeQuery($this->db, $sql, "fetching payment methods mappings", false);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            $records = array();

            while ($i < $num) {
                $obj = $this->db->fetch_object($resql);

                $record = new self($this->db);
                $record->setVarsFromFetchObj($obj);
                $records[] = $record;

                $i++;
            }

            $this->db->free($resql);
            return $records;
        }
        return -1;
    }

    /**
    * Get a Dolibarr payment ID from a Shopify payment method name
    *
    * @param string $shopifyPaymentMethod Shopify payment method name
    * @param int    $storeId              FK boutique (Story 48-4). >0 = mapping par boutique ;
    *                                     0 = chemin historique/fallback (pas de filtre fk_store)
    * @return int|false Dolibarr payment ID or false if not found
    */
    public function getDolibarrPaymentId($shopifyPaymentMethod, $storeId = 0)
    {
        global $conf;

        $sql = "SELECT dolibarr_payment_id
                FROM " . MAIN_DB_PREFIX . $this->table_element . "
                WHERE entity = ?
                AND shopify_payment_method = ?
                AND active = 1";

        $params = [(int)$conf->entity, $shopifyPaymentMethod];
        // Story 48-4 : filtre fk_store conditionnel (storeId>0 = multi-boutique ; 0 = legacy/fallback)
        if ((int) $storeId > 0) {
            $sql .= " AND fk_store = ?";
            $params[] = (int) $storeId;
        }

        $resql = SqlUtils::executeQuery($this->db, $sql, "getting Dolibarr payment ID", false, $params);

        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int)$obj->dolibarr_payment_id;
        }

        if ($resql) {
            $this->db->free($resql);
        }
        return false;
    }

    /**
     * Retourne la définition des correspondances de paiement par défaut (lecture seule).
     *
     * Exposée pour les tests — évite de dupliquer la liste ou de passer par la réflexion.
     *
     * @return array<int, array{shopify_payment_method: string, dolibarr_payment_id: int}>
     * @since 2.5.3
     */
    public static function getDefaultMappingsDefinition(): array
    {
        return self::DEFAULT_PAYMENT_MAPPINGS;
    }

    /**
     * Sème les correspondances de paiement par défaut pour une entité donnée.
     *
     * Story multicompany-seed-paiements-jamais-pose-en-entite-secondaire : remplace l'ancien
     * seeding SQL (sql/llx_doli2shop_payments.sql) qui figeait `entity = 1` sous une garde
     * `WHERE NOT EXISTS (SELECT 1 FROM llx_doli2shop_payments LIMIT 1)` portant sur TOUTE la
     * table — dès qu'une première entité était semée, aucune autre ne l'était jamais.
     *
     * - AC1 : sème pour l'entité `$entity` passée en paramètre, que d'autres entités aient
     *   déjà été semées ou non (garde par ligne, jamais globale).
     * - AC2 : appelée en PHP depuis modDoli2Shop::init(), après ensureDefaultStore().
     * - AC3 : idempotent sur le couple (entité, méthode Shopify) — une correspondance déjà
     *   présente (semée précédemment OU modifiée par le client) n'est JAMAIS ni recréée ni
     *   écrasée. Le chemin historique/fallback est ciblé via fk_store = 0 (cf. Story 48-4).
     * - AC4 : le mode de paiement Dolibarr par défaut doit exister dans l'entité cible
     *   (dictionnaire multi-entité `llx_c_paiement`, entity IN (0, cible) — 0 = partagé toutes
     *   entités). S'il n'existe pas, la ligne est ignorée avec un avertissement (LOG_WARNING)
     *   plutôt que de créer une correspondance vers un mode de paiement inexistant.
     * - AC5 : sur une entité déjà entièrement semée, aucune ligne n'est insérée (no-op strict).
     *
     * Erreur SQL sur une ligne : loggée et la ligne est ignorée, sans bloquer le reste du lot
     * (cohérent avec les autres seedings non-bloquants de modDoli2Shop::init()).
     *
     * @param int $entity Entité Dolibarr cible
     * @return int Nombre de lignes insérées (0 = no-op), -1 si l'entité est invalide
     * @since 2.5.3
     */
    public function ensureDefaultMappings(int $entity): int
    {
        $entity = (int) $entity;
        if ($entity <= 0) {
            $this->log('ensureDefaultMappings() - Entité invalide (' . $entity . '), seeding ignoré', LOG_WARNING);
            return -1;
        }

        $inserted = 0;

        foreach (self::DEFAULT_PAYMENT_MAPPINGS as $mapping) {
            $shopifyMethod = $mapping['shopify_payment_method'];
            $dolibarrPaymentId = (int) $mapping['dolibarr_payment_id'];

            // AC3 : garde par (entité, méthode, fk_store=0) — jamais sur toute la table.
            // Une ligne déjà présente n'est jamais retouchée, même si le client l'a modifiée.
            $sqlExists = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element
                . " WHERE entity = ? AND shopify_payment_method = ? AND fk_store = 0";
            $resqlExists = SqlUtils::executeQuery(
                $this->db,
                $sqlExists,
                "checking existing default payment mapping",
                false,
                [$entity, $shopifyMethod]
            );

            if ($resqlExists === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur vérification existence (méthode=' . $shopifyMethod
                    . ', entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $alreadyExists = $this->db->num_rows($resqlExists) > 0;
            $this->db->free($resqlExists);

            if ($alreadyExists) {
                continue;
            }

            // AC4 : le mode de paiement Dolibarr doit exister dans l'entité cible (ou être
            // partagé via entity = 0, convention Multicompany des tables dictionnaire).
            $sqlPayment = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = ? AND entity IN (0, ?)";
            $resqlPayment = SqlUtils::executeQuery(
                $this->db,
                $sqlPayment,
                "checking Dolibarr payment method exists for entity",
                false,
                [$dolibarrPaymentId, $entity]
            );

            if ($resqlPayment === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur vérification mode de paiement #' . $dolibarrPaymentId
                    . ' (entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $paymentMethodExists = $this->db->num_rows($resqlPayment) > 0;
            $this->db->free($resqlPayment);

            if (!$paymentMethodExists) {
                $this->log(
                    'ensureDefaultMappings() - Mode de paiement Dolibarr #' . $dolibarrPaymentId
                    . ' absent de l\'entité ' . $entity . ', correspondance "' . $shopifyMethod
                    . '" ignorée (avertissement, pas de blocage)',
                    LOG_WARNING
                );
                continue;
            }

            $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element
                . " (shopify_payment_method, dolibarr_payment_id, entity, fk_store, active)"
                . " VALUES (?, ?, ?, 0, 1)";
            $resqlInsert = SqlUtils::executeQuery(
                $this->db,
                $sqlInsert,
                "seeding default payment mapping",
                false,
                [$shopifyMethod, $dolibarrPaymentId, $entity]
            );

            if ($resqlInsert === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur insertion correspondance "' . $shopifyMethod
                    . '" (entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $inserted++;
        }

        $this->log(
            'ensureDefaultMappings() - Seeding terminé (entity=' . $entity . ') — '
            . $inserted . ' ligne(s) insérée(s)',
            $inserted > 0 ? LOG_INFO : LOG_DEBUG
        );

        return $inserted;
    }

    /**
     * Convert Universal Search filter to safe SQL WHERE clause
     *
     * @param string $filter Filter string in Universal Search format
     * @return string Safe SQL WHERE clause
     */
    private function convertFilterToSafeSqlWhere($filter)
    {
        if (empty($filter)) {
            return "";
        }

        // Gérer le cas spécial pour entity qui est couramment utilisé
        $filter = str_replace('entity:=:', 'entity = ', $filter);

        // Tableau des opérateurs sécurisés
        $safeOperators = [
            '=' =>  '=',
            '<' =>  '<',
            '>' =>  '>',
            '<=' =>  '<=',
            '>=' =>  '>='
        ];

        // Tableau des champs autorisés
        $allowedFields = ['entity', 'rowid', 'shopify_payment_method', 'dolibarr_payment_id', 'active'];

        // Parser les recherches dans le format "field operator value"
        $result = '';
        $subFilters = [];

        // Si le filtre contient AND ou OR, le diviser en plusieurs conditions
        if (strpos($filter, ' AND ') !== false) {
            $subFilters = explode(' AND ', $filter);
            $operator = ' AND ';
        } elseif (strpos($filter, ' OR ') !== false) {
            $subFilters = explode(' OR ', $filter);
            $operator = ' OR ';
        } else {
            $subFilters[] = $filter;
            $operator = '';
        }

        $conditions = [];

        foreach ($subFilters as $subFilter) {
            // Vérifier si la condition est un simple 'field = value'
            if (preg_match('/^([a-zA-Z0-9_]+)\s*=\s*(.+)$/', $subFilter, $matches)) {
                $field = trim($matches[1]);
                $value = trim($matches[2]);

                // Vérifier que le champ est autorisé
                if (in_array($field, $allowedFields)) {
                    // Traiter différemment selon le type de champ
                    if (in_array($field, ['rowid', 'entity', 'dolibarr_payment_id', 'active'])) {
                        // Convertir en entier pour les champs numériques
                        $conditions[] = $field . " = " . (int)$value;
                    } else {
                        // Échapper pour les champs texte
                        $conditions[] = $field . " = '" . $this->db->escape($value) . "'";
                    }
                }
            }
            // Cas spécial pour shopify_payment_method:=:card
            elseif (preg_match('/^([a-zA-Z0-9_]+):=:(.+)$/', $subFilter, $matches)) {
                $field = trim($matches[1]);
                $value = trim($matches[2]);

                // Vérifier que le champ est autorisé
                if (in_array($field, $allowedFields)) {
                    // Traiter différemment selon le type de champ
                    if (in_array($field, ['rowid', 'entity', 'dolibarr_payment_id', 'active'])) {
                        // Convertir en entier pour les champs numériques
                        $conditions[] = $field . " = " . (int)$value;
                    } else {
                        // Échapper pour les champs texte
                        $conditions[] = $field . " = '" . $this->db->escape($value) . "'";
                    }
                }
            }
        }

        // Combiner les conditions avec l'opérateur approprié
        if (!empty($conditions)) {
            if (empty($operator)) {
                return $conditions[0];
            } else {
                return implode($operator, $conditions);
            }
        }

        return "";
    }
}
