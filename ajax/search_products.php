<?php
/**
 * @file        ajax/search_products.php
 * @brief       AJAX endpoint for product search with category filtering
 *              Compatible Dolibarr 18.0-23+ using official methods: get_filles(), get_all_ways(), SQL fallback
 *
 * @package     ShopifyIntegration
 * @subpackage  Ajax
 * @category    ajax
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.0.20
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Story 59-7 : ne pas faire tourner le jeton CSRF sur ce endpoint AJAX. Cet endpoint est appelé
// en RAFALE par l'autocomplétion (une requête par frappe clavier, toutes avec le même jeton
// statique embarqué au rendu de la page) — un tel enchaînement périmait les liens d'action
// affichés par d'autres pages du module ouvertes dans la même session dès que
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

// Security check — admin only, BEFORE any class loading (Story 61-8, AC4). Auparavant ce
// contrôle passait après les deux dol_include_once() ci-dessous — incohérent avec les fichiers
// jumeaux du module (recover_shopify_ids.php, debug_numeric_sku.php), qui vérifient
// $user->admin avant tout chargement de classe. Lecture complète du fichier faite avant de
// déplacer ce bloc : rien entre l'ancien et le nouvel emplacement ne dépend de
// Categorie/security.lib.php/LoggerTrait (le contrôle ne référence que $user->admin).
if (!$user->admin) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Access forbidden'));
    exit;
}

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Libraries
dol_include_once('/categories/class/categorie.class.php');
dol_include_once('/core/lib/security.lib.php');  // Pour check_token()
dol_include_once('/doli2shop/class/productscopehelper.class.php');
dol_include_once('/doli2shop/lib/doli2shop.lib.php'); // Story 63-16 : contexte boutique consultée (doli2shopGetCurrentAdminStore)
require_once dirname(__DIR__) . '/class/LoggerTrait.php';

// Define log constants if not already defined
if (!defined('LOG_DEBUG')) define('LOG_DEBUG', 7);
if (!defined('LOG_INFO')) define('LOG_INFO', 6);
if (!defined('LOG_WARNING')) define('LOG_WARNING', 4);
if (!defined('LOG_ERR')) define('LOG_ERR', 3);

/**
 * Classe pour la recherche de produits via AJAX
 */
class ProductSearch
{
    use LoggerTrait;

    /** @var DoliDB Database handler */
    private $db;
    
    /** @var Translate Object for translations */
    private $langs;

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
    }
    
    /**
     * Process the AJAX request for product search
     * 
     * @return array Results of the search
     */
    public function processRequest()
    {
        global $user, $conf;

        // Security check
        if (!$user->admin) {
            accessforbidden('accessforbidden');
        }
        
        // CSRF Protection - Use Dolibarr's secure token verification
        if (!verifToken()) {
            accessforbidden('Invalid token');
        }
        
        // Parameters - Utiliser 'aZ09' pour préserver les zéros de tête des SKU numériques
        $search_term = GETPOST('term', 'aZ09');
        $category_id = GETPOST('category_id', 'int');
        // FIX #146 v2.1.2: Support filtre par type de produit (all/0/1)
        $product_type_filter = GETPOST('product_type', 'alpha');

        // Log des paramètres reçus pour faciliter le débogage
        $this->log("Requête reçue - term: '{$search_term}', category_id: '{$category_id}', product_type: '{$product_type_filter}'", LOG_INFO);
        
        // Vérifications des paramètres
        if (empty($search_term)) {
            $this->log("AVERTISSEMENT: Terme de recherche vide", LOG_WARNING);
        }
        
        if (empty($category_id)) {
            $this->log("AVERTISSEMENT: Catégorie non définie ou invalide (ID: " . (empty($category_id) ? 'non défini' : $category_id) . ")", LOG_WARNING);
            // Permettre la recherche sans filtre de catégorie au lieu de bloquer
            $this->log("Recherche sans filtre de catégorie - tous les produits", LOG_INFO);
        } else {
            $this->log("Recherche avec catégorie ID: {$category_id}", LOG_DEBUG);
        }

        $results = array();
        
        if ($search_term) {
            // FIX #146 v2.1.2: Initialiser la requête de base avec type de produit
            $this->log("Construction de la requête SQL pour la recherche de produits", LOG_DEBUG);

            // Story 63-16 (AC2, ajout Validate) : la sous-requête corrélée is_synced n'avait ni
            // filtre entity ni filtre fk_store — un produit de l'entité courante mappé UNIQUEMENT
            // sur une AUTRE boutique (ou, en théorie, sur une autre entité) s'affichait "synchronisé"
            // dans cette recherche, alors qu'il ne l'est pas dans la boutique consultée. Même
            // motif que admin/sync_products.php (Story 63-16 AC1) : boutique par défaut =
            // fk_store IN (0, id) pour couvrir les lignes legacy d'avant le backfill Epic 47,
            // boutique secondaire = égalité stricte.
            //
            // Code review 63-16 (24/08) : le calcul de $searchProductsStoreId/$searchProductsIsDefaultStore
            // dupliquait littéralement le même motif que admin/sync_products.php (MEDIUM) — et
            // traitait TOUJOURS "$searchProductsAdminStore === null" comme "install jamais migrée"
            // (invariant Epic 47 n°1), alors que ce retour couvre aussi l'anomalie "des boutiques
            // existent mais aucune n'est résolue" (HIGH) — qui, sans distinction, remettrait "aucun
            // filtre fk_store" et ferait afficher "synchronisé" un produit qui ne l'est que sur une
            // AUTRE boutique. Les deux corrigés en consolidant sur les helpers partagés de
            // lib/doli2shop.lib.php (mêmes que admin/sync_products.php).
            $searchProductsAdminStore = doli2shopGetCurrentAdminStore($this->db);
            $searchProductsScope = doli2shopResolveCounterStoreScope($this->db, $searchProductsAdminStore, 'ajax/search_products.php');
            $searchProductsStoreId = $searchProductsScope['storeId'];
            $searchProductsIsDefaultStore = $searchProductsScope['isDefault'];
            $searchProductsSyncFilter = " AND dsp.entity = " . (int) $conf->entity;
            $searchProductsSyncFilter .= doli2shopBuildStoreScopedSqlFilter($searchProductsStoreId, !$searchProductsIsDefaultStore, 'dsp.fk_store');

            $sql = "SELECT DISTINCT p.rowid, p.ref, p.label, p.fk_product_type,";
            $sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "product as v WHERE v.fk_parent = p.rowid) as nb_direct_variants,";
            $sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "product_attribute_combination as pac WHERE pac.fk_product_parent = p.rowid) as nb_combo_variants,";
            $sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "doli2shop_products as dsp WHERE dsp.fk_product = p.rowid" . $searchProductsSyncFilter . ") as is_synced";
            $sql .= " FROM " . MAIN_DB_PREFIX . "product as p";
            
            // Jointure conditionnelle sur les catégories si une catégorie est spécifiée
            //
            // Story 63-19 (AC1 + AC2) : arbre de catégories calculé par ProductScopeHelper,
            // helper PARTAGÉ avec le CRON (class/importproducts.class.php), admin/sync_products.php
            // et ajax/sync_products_batch.php — remplace les 3 tentatives ad hoc précédentes
            // (get_filles(), get_all_ways(), repli SQL direct qui ne descendait qu'UN seul niveau,
            // `:214-217` avant cette story). Ce endpoint est appelé par la barre de recherche de
            // admin/sync_products.php (même $defaultCategoryId que le bloc de stats de la même page) :
            // avant ce correctif, la recherche pouvait retrouver un produit qu'une sous-catégorie
            // plus profonde que le premier niveau laissait échapper au bloc de stats — la même
            // classe de symptôme que le dossier Europe Loisirs (confirmé sur données de production
            // le 23/08/2026), à l'intérieur d'une seule et même page.
            //
            // Story 63-19 (AC2) : entité tranchée en faveur de `$conf->entity` — aligné sur les
            // TROIS autres sites (CRON, écran stats, sync manuelle), qui utilisent tous la même
            // sémantique "entité courante stricte". `getEntity('product')` (helper Multicompany
            // officiel) pouvait renvoyer une liste d'entités TRANSVERSES et donc un périmètre plus
            // large que les autres sites sur la MÊME page — exactement la divergence que cette
            // story corrige.
            //
            // Correctif post-code-review (HIGH 6) : la justification précédente invoquait
            // "l'invariant n°1 Epic 47" (multi-boutique Doli2Shop = une entité) — mais cet invariant
            // porte sur l'ARCHITECTURE du module, pas sur la configuration Multicompany du client,
            // qui est un réglage Dolibarr ORTHOGONAL. Un client peut être conforme à l'invariant
            // Epic 47 ET avoir activé le partage de produits Multicompany (`$mc->getEntity()`
            // renverrait alors une liste "entité1,entité2..." même pour ce module). `$conf->entity`
            // reste le choix retenu — pour la cohérence avec les 3 autres sites, qui étaient DÉJÀ
            // restreints à `$conf->entity` avant cette story (cet alignement ÉTEND le risque au 4ᵉ
            // site, il ne l'introduit pas) — mais c'est une HYPOTHÈSE NON MESURÉE sur les
            // configurations du parc, pas une conséquence démontrée de l'invariant Epic 47.
            // Vérification ouverte en story dédiée (docs/implementation-artifacts, sévérité MEDIUM,
            // milestone v2.6.0).
            if ($category_id > 0) {
                $scopeHelper = new ProductScopeHelper($this->db);
                $all_categories = $scopeHelper->getCategoryTreeIds($category_id, $conf->entity);
                // HIGH 3 (code review) : diagnostic partagé, distingue catégorie inexistante /
                // trouvée dans une autre entité / sans descendance.
                $scopeHelper->logEmptyScopeDiagnostics($category_id, $conf->entity, $all_categories);

                // HIGH 2 (code review) : avant ce correctif, un périmètre vide réinjectait la
                // catégorie BRUTE, NON VALIDÉE pour l'entité courante (`array($category_id)`) —
                // recréant la fuite inter-entités que l'AC2 devait supprimer (un produit de
                // l'entité courante taggué avec une catégorie d'une AUTRE entité redevenait
                // visible). `buildCategoryInClause()` rend "(0)" sur un tableau vide, exactement
                // comme les 3 autres sites — jamais "tout le catalogue" ni la valeur brute par
                // accident.
                $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "categorie_product as cp ON p.rowid = cp.fk_product";
                $sql .= " WHERE p.entity = " . (int) $conf->entity;
                $sql .= " AND cp.fk_categorie IN " . ProductScopeHelper::buildCategoryInClause($all_categories);
            } else {
                // Pas de filtre par catégorie
                $sql .= " WHERE p.entity = " . (int) $conf->entity;
            }
            
            // Escape search term and use it for LIKE queries
            // Gestion spéciale pour les SKU numériques pour éviter les problèmes d'échappement
            $escaped_term = $this->db->escape($search_term);
            
            // Log du terme de recherche avant et après échappement
            $this->log("Terme original: '{$search_term}', terme échappé: '{$escaped_term}'", LOG_DEBUG);
            
            // Construire la condition de recherche avec gestion des SKU numériques
            if (is_numeric($search_term)) {
                // Pour les SKU purement numériques, essayer plusieurs approches
                $sql .= " AND (";
                $sql .= "p.ref = '" . $escaped_term . "'";
                $sql .= " OR p.ref LIKE '%" . $escaped_term . "%'";
                $sql .= " OR CAST(p.ref AS UNSIGNED) = CAST('" . $escaped_term . "' AS UNSIGNED)";
                $sql .= " OR p.label LIKE '%" . $escaped_term . "%'";
                $sql .= ")";
            } else {
                // Pour les SKU alphanumériques, utilisation standard
                $sql .= " AND (p.ref LIKE '%" . $escaped_term . "%' OR p.label LIKE '%" . $escaped_term . "%')";
            }

            // FIX #146 v2.1.2: Filtrer par type de produit si demandé
            if ($product_type_filter === '0' || $product_type_filter === '1') {
                $sql .= " AND p.fk_product_type = " . (int)$product_type_filter;
                $this->log("Filtre par type de produit appliqué: " . $product_type_filter, LOG_DEBUG);
            } else {
                $this->log("Pas de filtre par type de produit (afficher tout)", LOG_DEBUG);
            }

            // Exclure les produits non en vente
            $sql .= " AND p.tosell = 1";
            // Exclude products that are children (either direct or combo)
            $sql .= " AND (p.fk_parent IS NULL OR p.fk_parent = 0)";  // Not a direct child
            $sql .= " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "product_attribute_combination pac WHERE pac.fk_product_child = p.rowid)";  // Not a combo child
            // Include only parent products and standalone products
            $sql .= " ORDER BY p.ref ASC";
            $sql .= " LIMIT 20";  // Limit results for performance
            
            // Log de la requête SQL complète pour le débogage
            $this->log("Requête SQL finale: " . $sql, LOG_DEBUG);
            
            $resql = $this->db->query($sql);
            
            if ($resql) {
                $num_rows = $this->db->num_rows($resql);
                $this->log("Requête exécutée avec succès, {$num_rows} résultats trouvés", LOG_INFO);
                
                while ($obj = $this->db->fetch_object($resql)) {
                    $label = $obj->ref;
                    if ($obj->label) {
                        $label .= ' - ' . $obj->label;
                    }
                    
                    // Show variant count if any
                    $total_variants = $obj->nb_direct_variants + $obj->nb_combo_variants;
                    if ($total_variants > 0) {
                        $label .= ' (' . $total_variants . ' ' . $this->langs->trans("Variants") . ')';
                    }

                    // FIX #146 v2.1.2: Ajouter badge type de produit
                    $product_type_label = ($obj->fk_product_type == 1) ? '🔧 Service' : '📦 Produit';
                    $label .= ' [' . $product_type_label . ']';

                    $results[] = array(
                        'id' => $obj->rowid,
                        'label' => $label,
                        'value' => $obj->ref,
                        'sync_status' => ($obj->is_synced > 0),
                        'product_type' => $obj->fk_product_type  // FIX #146 v2.1.2
                    );
                    
                    $this->log("Produit trouvé: ID={$obj->rowid}, REF={$obj->ref}, Variants={$total_variants}", LOG_DEBUG);
                }
            } else {
                $this->log("ERREUR: Échec de l'exécution de la requête SQL", LOG_ERR);
                $this->log("Erreur SQL: " . $this->db->lasterror(), LOG_ERR);
            }
        }
        
        // Log final sur les résultats
        $result_count = count($results);
        $this->log("Fin de traitement, {$result_count} résultats retournés", LOG_INFO);
        
        return $results;
    }
}

// Instancier et exécuter la recherche
$results = array();
try {
    $productSearch = new ProductSearch($db, $langs);
    $results = $productSearch->processRequest();
} catch (Exception $e) {
    // En cas d'erreur, utiliser le logging standard de Dolibarr
    dol_syslog("EXCEPTION dans search_products.php: " . $e->getMessage(), LOG_ERR);
    $results = array(); // En cas d'erreur, retourner un tableau vide
} catch (Error $e) {
    // Capturer aussi les erreurs fatales PHP (ex: undefined method)
    dol_syslog("PHP ERROR dans search_products.php: " . $e->getMessage(), LOG_ERR);
    $results = array();
}

// Return JSON response - toujours envoyer une réponse JSON valide
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// S'assurer que $results est toujours un tableau
if (!is_array($results)) {
    $results = array();
}

echo json_encode($results);