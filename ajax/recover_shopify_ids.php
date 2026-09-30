<?php
/**
 * @file        ajax/recover_shopify_ids.php
 * @brief       AJAX endpoint for recovering missing Shopify IDs
 *
 * @package     ShopifyIntegration
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.0.23
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Story 59-7 : ne pas faire tourner le jeton CSRF sur ce endpoint AJAX. Un appel déclenché
// automatiquement ou de façon répétée/chaînée périmait les liens d'action affichés par
// d'autres pages du module ouvertes dans la même session dès que
// MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif (investigation 59-1). NOTOKENRENEWAL
// désactive la ROTATION (main.inc.php:333-349) ; la VÉRIFICATION (verifToken(), plus bas) reste
// intégralement active — cet endpoint continue de rejeter tout appel sans jeton valide.
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

// Security check — admin only, before any class loading (Story 7.3 AC1, FR28)
if (!$user->admin) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Access forbidden'));
    exit;
}

// Include compatibility functions for older Dolibarr versions (verifToken(), Hotfix 2.4.6)
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Libraries
dol_include_once('/core/lib/security.lib.php');
require_once dirname(__DIR__) . '/class/LoggerTrait.php';
dol_include_once('/doli2shop/class/importproducts.class.php');
dol_include_once('/doli2shop/class/shopifyapi.class.php');

// Define log constants if not already defined
if (!defined('LOG_DEBUG')) define('LOG_DEBUG', 7);
if (!defined('LOG_INFO')) define('LOG_INFO', 6);
if (!defined('LOG_WARNING')) define('LOG_WARNING', 4);
if (!defined('LOG_ERR')) define('LOG_ERR', 3);

/**
 * Classe pour la récupération des IDs Shopify
 */
class ShopifyIdRecovery
{
    use LoggerTrait;

    /** @var DoliDB Database handler */
    private $db;
    
    /** @var Translate Object for translations */
    private $langs;
    
    /** @var ImportProducts Import products handler */
    private $importProducts;
    
    /** @var ShopifyApi Shopify API handler */
    private $shopifyApi;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     * @param Translate $langs Language handler
     */
    public function __construct($db, $langs)
    {
        $this->db = $db;
        $this->langs = $langs;
        $this->importProducts = new ImportProducts($db);
        $this->shopifyApi = new ShopifyApi($db);
    }
    
    /**
     * Process the AJAX request for ID recovery
     * 
     * @return array Results of the recovery
     */
    public function processRequest()
    {
        global $user, $conf;
        
        // Security check
        if (!$user->admin) {
            return ['error' => 'Access forbidden'];
        }
        
        // CSRF Protection
        // Hotfix 2.4.6 (2/2) : ce endpoint réimplémentait en dur la comparaison au mauvais
        // jeton (`$_SESSION['newtoken']` = jeton de la PROCHAINE requête, jamais celui
        // réellement soumis) — cassé en silence dès que
        // MAIN_SECURITY_CSRF_TOKEN_RENEWAL_ON_EACH_CALL est actif. Délégué au helper partagé
        // verifToken() (lib/compatibility.lib.php), seule source de vérité pour la comparaison.
        if (!verifToken()) {
            // Review 3 couches (HIGH) : aligné sur le contrat des 5 autres endpoints batch
            // (403 + success:false). Cet endpoint renvoyait un 200 avec une simple clé `error`,
            // qu'un appelant testant le code HTTP ou `success` n'aurait pas vu comme un échec.
            http_response_code(403);
            return ['success' => false, 'error' => 'Invalid token'];
        }
        
        $dryRun = GETPOST('dry_run', 'int') == 1;
        $limit = GETPOST('limit', 'int') ?: 10; // Default limit for safety
        
        try {
            $this->log("Starting Shopify ID recovery (dry_run: " . ($dryRun ? 'true' : 'false') . ", limit: $limit)", LOG_INFO);
            
            // Get mappings with empty Shopify IDs
            $emptyMappings = $this->getEmptyShopifyMappings($limit);
            
            if (empty($emptyMappings)) {
                return [
                    'success' => true,
                    'message' => 'No mappings with empty Shopify IDs found',
                    'recovered' => 0,
                    'failed' => 0,
                    'details' => []
                ];
            }
            
            $recovered = 0;
            $failed = 0;
            $details = [];
            
            foreach ($emptyMappings as $mapping) {
                $result = $this->recoverShopifyId($mapping, $dryRun);
                
                if ($result['success']) {
                    $recovered++;
                } else {
                    $failed++;
                }
                
                $details[] = $result;
            }
            
            $this->log("Recovery completed: $recovered recovered, $failed failed", LOG_INFO);
            
            // Story 61-8 (AC2) : l'enveloppe annonçait `success: true` même quand TOUTES les lignes
            // avaient échoué — un appelant qui ne teste que ce drapeau croyait l'opération réussie.
            // Aligné sur le contrat des cinq autres endpoints batch du module. Le mode simulation
            // reste `true` : aucune écriture n'y est tentée, un « échec » y est un simple constat.
            $envelopeSuccess = !($failed > 0 && $recovered === 0 && !$dryRun);

            return [
                'success' => $envelopeSuccess,
                'dry_run' => $dryRun,
                'recovered' => $recovered,
                'failed' => $failed,
                'total_processed' => count($emptyMappings),
                'details' => $details
            ];
            
        } catch (Exception $e) {
            $this->log("Exception in ID recovery: " . $e->getMessage(), LOG_ERR);
            return [
                'error' => 'Internal error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get mappings with empty Shopify IDs
     *
     * @param int $limit Maximum number of mappings to process
     * @return array List of mappings to recover
     */
    private function getEmptyShopifyMappings($limit)
    {
        global $conf;
        
        $sql = "SELECT dsp.rowid, dsp.fk_product, p.ref, p.label
                FROM " . MAIN_DB_PREFIX . "doli2shop_products dsp
                INNER JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = dsp.fk_product
                WHERE dsp.entity = " . (int)$conf->entity . "
                AND (dsp.shopifyProductId IS NULL OR dsp.shopifyProductId = '' OR dsp.shopifyProductId = '0')
                AND p.tosell = 1
                ORDER BY dsp.tms DESC
                LIMIT " . (int)$limit;
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception("Error querying empty mappings: " . $this->db->lasterror());
        }
        
        $mappings = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $mappings[] = [
                'mapping_id' => $obj->rowid,
                'dolibarr_id' => $obj->fk_product,
                'dolibarr_ref' => $obj->ref,
                'dolibarr_label' => $obj->label
            ];
        }
        
        $this->db->free($resql);
        return $mappings;
    }
    
    /**
     * Attempt to recover Shopify ID for a specific mapping
     *
     * @param array $mapping Mapping data
     * @param bool $dryRun Whether to actually update the database
     * @return array Recovery result
     */
    private function recoverShopifyId($mapping, $dryRun)
    {
        $this->log("Attempting to recover Shopify ID for product " . $mapping['dolibarr_ref'], LOG_DEBUG);
        
        try {
            // Search for the product on Shopify by SKU
            $shopifyProduct = $this->shopifyApi->findProductBySku($mapping['dolibarr_ref']);
            
            if (!$shopifyProduct) {
                return [
                    'success' => false,
                    'mapping_id' => $mapping['mapping_id'],
                    'dolibarr_ref' => $mapping['dolibarr_ref'],
                    'error' => 'Product not found on Shopify',
                    'action' => 'none'
                ];
            }
            
            // Extract Shopify IDs
            $shopifyProductId = str_replace('gid://shopify/Product/', '', $shopifyProduct->id);
            $shopifyVariantId = null;
            
            if (!empty($shopifyProduct->variants->nodes[0])) {
                $shopifyVariantId = str_replace('gid://shopify/ProductVariant/', '', $shopifyProduct->variants->nodes[0]->id);
            }
            
            if (!$dryRun) {
                // Story 61-8 (AC1) : cet UPDATE utilisait des placeholders `?` avec un tableau passé
                // en 2e argument de `DoliDB::query()`. Or ce 2e argument est `$usesavepoint` (un
                // booléen) : les paramètres n'étaient JAMAIS liés, et MySQL rejetait la requête.
                // Hors simulation, 100 % des écritures échouaient — sous une enveloppe
                // `success: true`. Le mode simulation, lui, « fonctionnait », ce qui masquait le
                // défaut en test. Valeurs désormais échappées explicitement.
                $sql = "UPDATE " . MAIN_DB_PREFIX . "doli2shop_products
                        SET shopifyProductId = '" . $this->db->escape($shopifyProductId) . "',
                            shopifyVariantId = " . ($shopifyVariantId === null
                                ? "NULL"
                                : "'" . $this->db->escape($shopifyVariantId) . "'") . ",
                            tms = NOW()
                        WHERE rowid = " . (int) $mapping['mapping_id'];

                $resql = $this->db->query($sql);

                if (!$resql) {
                    return [
                        'success' => false,
                        'mapping_id' => $mapping['mapping_id'],
                        'dolibarr_ref' => $mapping['dolibarr_ref'],
                        'error' => 'Database update failed: ' . $this->db->lasterror(),
                        'action' => 'database_error'
                    ];
                }
            }
            
            return [
                'success' => true,
                'mapping_id' => $mapping['mapping_id'],
                'dolibarr_ref' => $mapping['dolibarr_ref'],
                'shopify_product_id' => $shopifyProductId,
                'shopify_variant_id' => $shopifyVariantId,
                'action' => $dryRun ? 'would_update' : 'updated'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'mapping_id' => $mapping['mapping_id'],
                'dolibarr_ref' => $mapping['dolibarr_ref'],
                'error' => $e->getMessage(),
                'action' => 'exception'
            ];
        }
    }
}

// Instancier et exécuter la récupération
$recovery = new ShopifyIdRecovery($db, $langs);
$results = $recovery->processRequest();

// Return JSON response
header('Content-Type: application/json');
echo json_encode($results);