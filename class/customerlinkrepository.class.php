<?php
/**
 * @file        class/customerlinkrepository.class.php
 * @brief       Dépôt du lien « client Shopify <-> tiers Dolibarr » (table llx_doli2shop_customers)
 *
 * Story 66-6. L'identifiant client Shopify n'est unique que PAR BOUTIQUE : la clé de liaison est
 * (entity, fk_store, shopify_customer_id). Un tiers peut porter plusieurs liens (un par client
 * Shopify / boutique), jamais l'inverse. La lecture est DÉFENSIVE : elle joint llx_societe, donc un
 * lien dont le tiers n'existe plus (FK absente sur une install dont llx_societe n'est pas InnoDB)
 * est ignoré sans exception.
 *
 * Filtre fk_store CONDITIONNEL (CLAUDE.dolibarr.md §14) : $storeId > 0 -> filtré ; $storeId == 0
 * (chemin historique / mono-boutique par constantes) -> pas de filtre fk_store, et jamais d'écrasement
 * d'un fk_store > 0 par 0 à l'écriture.
 *
 * Aucune méthode ne lève d'exception : l'écriture du lien est NON BLOQUANTE pour l'import de commande.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.1
 * @since       2.6.1
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';

/**
 * Dépôt des liens client Shopify <-> tiers Dolibarr.
 */
class CustomerLinkRepository
{
    use LoggerTrait;

    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var string Dernière erreur SQL (repointThirdparty / upsert) */
    public $error = '';

    /**
     * @param DoliDB   $db     Connexion base
     * @param int|null $entity Entité (null = entité courante)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
    }

    /**
     * Nettoie un identifiant client Shopify : retire le préfixe GraphQL gid://shopify/Customer/.
     *
     * @param  mixed $shopifyCustomerId Identifiant brut (gid ou numérique)
     * @return string Identifiant numérique ('' si vide)
     */
    public static function cleanShopifyCustomerId($shopifyCustomerId)
    {
        return trim(str_replace('gid://shopify/Customer/', '', (string) $shopifyCustomerId));
    }

    /**
     * Retrouve le tiers lié à un client Shopify. Lecture défensive : jointure sur llx_societe (et
     * filtre d'entité sur le TIERS) ; un lien orphelin renvoie 0.
     *
     * @param  int    $storeId           Boutique (0 = pas de filtre fk_store)
     * @param  string $shopifyCustomerId Identifiant client Shopify
     * @return int    rowid du tiers, 0 si aucun lien exploitable
     */
    public function findThirdpartyId($storeId, $shopifyCustomerId)
    {
        $shopifyCustomerId = self::cleanShopifyCustomerId($shopifyCustomerId);
        if ($shopifyCustomerId === '') {
            return 0;
        }
        $sql = "SELECT c.fk_soc FROM " . MAIN_DB_PREFIX . "doli2shop_customers c"
            . " INNER JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = c.fk_soc"
            . " WHERE c.entity = ? AND s.entity = ? AND c.shopify_customer_id = ?";
        $params = array($this->entity, $this->entity, $shopifyCustomerId);
        if ((int) $storeId > 0) {
            $sql .= " AND c.fk_store = ?";
            $params[] = (int) $storeId;
        }
        $sql .= " ORDER BY c.rowid ASC LIMIT 1";

        try {
            $resql = SqlUtils::executeQuery($this->db, $sql, "finding customer link", false, $params);
        } catch (\Throwable $e) {
            $this->log("CustomerLinkRepository::findThirdpartyId - " . $e->getMessage(), LOG_WARNING);
            return 0;
        }
        if (!$resql) {
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return ($obj && !empty($obj->fk_soc)) ? (int) $obj->fk_soc : 0;
    }

    /**
     * Crée ou met à jour le lien (INSERT ... ON DUPLICATE KEY UPDATE fk_soc). NON BLOQUANT : renvoie
     * false (et journalise) sur échec, ne lève jamais.
     *
     * $storeId == 0 : un lien déjà posé pour ce (entity, id) sur une boutique > 0 est mis à jour sur
     * place (fk_store conservé), jamais écrasé par 0 ni dupliqué.
     *
     * @param  int    $storeId           Boutique (0 = chemin historique)
     * @param  string $shopifyCustomerId Identifiant client Shopify
     * @param  int    $dolibarrSocId     Tiers lié
     * @return bool
     */
    public function upsert($storeId, $shopifyCustomerId, $dolibarrSocId)
    {
        $this->error = '';
        $shopifyCustomerId = self::cleanShopifyCustomerId($shopifyCustomerId);
        $dolibarrSocId = (int) $dolibarrSocId;
        if ($shopifyCustomerId === '' || $dolibarrSocId <= 0) {
            return false;
        }
        $storeId = (int) $storeId;

        try {
            if ($storeId === 0) {
                $sqlExisting = "SELECT rowid FROM " . MAIN_DB_PREFIX . "doli2shop_customers"
                    . " WHERE entity = ? AND shopify_customer_id = ? ORDER BY rowid ASC LIMIT 1";
                $resql = SqlUtils::executeQuery($this->db, $sqlExisting, "finding customer link for upsert", false, array($this->entity, $shopifyCustomerId));
                if (!$resql) {
                    $this->error = $this->db->lasterror();
                    return false;
                }
                $existing = $this->db->fetch_object($resql);
                $this->db->free($resql);
                if ($existing && !empty($existing->rowid)) {
                    $sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_customers SET fk_soc = ? WHERE rowid = ?";
                    $ok = SqlUtils::executeQuery($this->db, $sqlUpdate, "updating customer link", false, array($dolibarrSocId, (int) $existing->rowid));
                    if (!$ok) {
                        $this->error = $this->db->lasterror();
                    }
                    return (bool) $ok;
                }
            }

            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_customers (entity, fk_store, shopify_customer_id, fk_soc)"
                . " VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE fk_soc = VALUES(fk_soc)";
            $ok = SqlUtils::executeQuery($this->db, $sql, "upserting customer link", false, array($this->entity, $storeId, $shopifyCustomerId, $dolibarrSocId));
            if (!$ok) {
                $this->error = $this->db->lasterror();
            }
            return (bool) $ok;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->log("CustomerLinkRepository::upsert - " . $e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Liens d'un tiers (lecture de la fiche tiers) — UNE requête, filtrée par entité, avec le libellé
     * de la boutique (LEFT JOIN : une boutique supprimée ou fk_store = 0 donne un libellé vide).
     *
     * @param  int $dolibarrSocId Tiers
     * @return array<int,array{fk_store:int,shopify_customer_id:string,store_label:string}>
     */
    public function findByThirdparty($dolibarrSocId)
    {
        $dolibarrSocId = (int) $dolibarrSocId;
        if ($dolibarrSocId <= 0) {
            return array();
        }
        $sql = "SELECT c.fk_store, c.shopify_customer_id, st.label AS store_label"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_customers c"
            . " LEFT JOIN " . MAIN_DB_PREFIX . "doli2shop_stores st ON st.rowid = c.fk_store AND st.entity = c.entity"
            . " WHERE c.fk_soc = ? AND c.entity = ? ORDER BY c.fk_store ASC, c.rowid ASC";
        try {
            $resql = SqlUtils::executeQuery($this->db, $sql, "listing customer links of a thirdparty", false, array($dolibarrSocId, $this->entity));
        } catch (\Throwable $e) {
            $this->log("CustomerLinkRepository::findByThirdparty - " . $e->getMessage(), LOG_WARNING);
            return array();
        }
        $rows = array();
        if ($resql) {
            $rowCount = (int) $this->db->num_rows($resql);
            for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
                $obj = $this->db->fetch_object($resql);
                if (!$obj) {
                    break;
                }
                $rows[] = array(
                    'fk_store' => (int) $obj->fk_store,
                    'shopify_customer_id' => (string) $obj->shopify_customer_id,
                    'store_label' => isset($obj->store_label) ? (string) $obj->store_label : '',
                );
            }
            $this->db->free($resql);
        }
        return $rows;
    }

    /**
     * Re-pointe les liens d'un tiers fusionné vers le tiers conservé (hook replaceThirdparty).
     * UPDATE simple — jamais UPDATE IGNORE (il masquerait une vraie erreur). Pas de filtre d'entité :
     * fk_soc est un rowid global, un tiers appartient à une seule entité. L'UK ne contient pas fk_soc,
     * le re-pointage ne peut donc pas la violer.
     *
     * @param  int $dolibarrSocOrigin Tiers supprimé par la fusion
     * @param  int $dolibarrSocDest   Tiers conservé
     * @return bool false + $this->error en cas d'erreur SQL
     */
    public function repointThirdparty($dolibarrSocOrigin, $dolibarrSocDest)
    {
        $this->error = '';
        $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_customers SET fk_soc = " . (int) $dolibarrSocDest
            . " WHERE fk_soc = " . (int) $dolibarrSocOrigin;
        $resql = $this->db->query($sql);
        if (!$resql) {
            if (method_exists($this->db, 'lasterrno') && (string) $this->db->lasterrno() === 'DB_ERROR_NOSUCHTABLE') {
                // Module mis à jour sans réactivation : la table n'existe pas encore, donc aucun lien à
                // re-pointer — ne pas faire échouer la fusion de tiers pour un lien qui n'existe pas.
                $this->log("CustomerLinkRepository::repointThirdparty - table absente, rien à re-pointer", LOG_WARNING);
                return true;
            }
            $this->error = $this->db->lasterror();
            $this->log("CustomerLinkRepository::repointThirdparty - " . $this->error, LOG_ERR);
            return false;
        }
        return true;
    }
}
