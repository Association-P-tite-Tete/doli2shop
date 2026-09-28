<?php
/**
 * @file        class/storecategoryhelper.class.php
 * @brief       Helper centralisé pour la création et le tag des catégories Dolibarr par boutique
 *
 * Chaque boutique Shopify peut avoir jusqu'à 3 catégories Dolibarr typées :
 *   - TYPE_PRODUCT  → produits synchronisés (fk_categorie)
 *   - TYPE_ORDER    → commandes créées depuis Shopify (fk_categorie_order)
 *   - TYPE_INVOICE  → factures créées depuis Shopify (fk_categorie_invoice)
 *
 * La méthode ensureStoreCategories() est idempotente : elle ne crée une catégorie
 * que si la colonne correspondante est NULL. Les tags sont non bloquants (try/catch).
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.3.3
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Helper centralisé pour la gestion des catégories Dolibarr par boutique.
 *
 * Usage :
 *   $helper = new StoreCategoryHelper($db, $entity);
 *   $helper->ensureStoreCategories($store);           // crée les catégories manquantes
 *   $helper->tagObjectWithStoreCategory($obj, $store, Categorie::TYPE_ORDER); // tag
 */
class StoreCategoryHelper
{
    use LoggerTrait;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var int Identifiant entité Dolibarr */
    private $entity;

    /**
     * Constructeur
     *
     * @param DoliDB   $db     Gestionnaire base de données
     * @param int|null $entity Entité Dolibarr (null = entité courante $conf->entity)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db     = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Crée les catégories Dolibarr manquantes pour une boutique et persiste leurs IDs.
     *
     * Idempotent : ne crée une catégorie que si la colonne correspondante est NULL.
     * Cas particulier produit : ne crée/écrase PAS fk_categorie si déjà défini
     * (la boutique par défaut pointe sur PROCATE — rétrocompat absolue).
     *
     * Requiert un $user Dolibarr valide pour Categorie::create(). Si $user est vide
     * (contexte CLI/cron sans utilisateur connecté), on loggue et on différie
     * sans crasher.
     *
     * @param  object       $store  Objet boutique (issu de StoreService::fetch())
     * @return array                Tableau associatif des IDs créés/existants :
     *                              ['product'=>int|null, 'order'=>int|null, 'invoice'=>int|null]
     */
    public function ensureStoreCategories($store): array
    {
        global $user;

        $result = ['product' => null, 'order' => null, 'invoice' => null, 'proposal' => null];

        if ($store === null || empty($store->rowid)) {
            $this->log('ensureStoreCategories() - store null ou sans rowid, skip', LOG_WARNING);
            return $result;
        }

        // Guard : Categorie::create() requiert un utilisateur Dolibarr valide
        if (empty($user) || empty($user->id)) {
            $this->log(
                'ensureStoreCategories() - $user vide (contexte CLI/cron), '
                . 'création catégories différée pour boutique rowid=' . (int) $store->rowid,
                LOG_DEBUG
            );
            return $result;
        }

        $dolibarrStoreId = (int) $store->rowid;
        $storeLabel      = (string) ($store->label ?? '');
        $updateData      = [];

        // Charger la classe Categorie AVANT d'utiliser ses constantes TYPE_* (sinon
        // « Class Categorie not found » à l'activation : la constante est évaluée comme
        // argument avant l'include interne de createCategory()).
        dol_include_once('/categories/class/categorie.class.php');

        // -------- Catégorie PRODUIT (TYPE_PRODUCT) --------
        // Ne crée pas si fk_categorie déjà défini (boutique défaut = PROCATE, rétrocompat).
        $existingProductCat = isset($store->fk_categorie) ? (int) $store->fk_categorie : 0;
        if ($existingProductCat > 0) {
            $result['product'] = $existingProductCat;
            $this->log(
                'ensureStoreCategories() - fk_categorie déjà défini (' . $existingProductCat . ') pour boutique rowid=' . $dolibarrStoreId . ', skip création produit',
                LOG_DEBUG
            );
        } else {
            // TYPE_PRODUCT est une constante historique (présente depuis les toutes
            // premières versions de Categorie::class) : garde étendue ici par cohérence
            // uniquement (coût nul), aucun risque d'absence réellement connu sur Dolibarr 19+.
            $typeProduct = $this->resolveCategorieTypeConstant('Categorie::TYPE_PRODUCT');
            if ($typeProduct === null) {
                $this->log(
                    'ensureStoreCategories() - Categorie::TYPE_PRODUCT introuvable (inattendu, constante historique), skip création produit pour boutique rowid=' . $dolibarrStoreId,
                    LOG_WARNING
                );
            } else {
                $catLabel = $this->buildCategoryLabel($storeLabel, 'product');
                $catId    = $this->createCategory($catLabel, $typeProduct, $user);
                if ($catId > 0) {
                    $result['product']          = $catId;
                    $updateData['fk_categorie'] = $catId;
                }
            }
        }

        // -------- Catégorie COMMANDE (TYPE_ORDER) --------
        $existingOrderCat = isset($store->fk_categorie_order) ? (int) $store->fk_categorie_order : 0;
        if ($existingOrderCat > 0) {
            $result['order'] = $existingOrderCat;
            $this->log(
                'ensureStoreCategories() - fk_categorie_order déjà défini (' . $existingOrderCat . ') pour boutique rowid=' . $dolibarrStoreId . ', skip création commande',
                LOG_DEBUG
            );
        } else {
            // TYPE_ORDER : constante historique, même remarque que TYPE_PRODUCT ci-dessus.
            $typeOrder = $this->resolveCategorieTypeConstant('Categorie::TYPE_ORDER');
            if ($typeOrder === null) {
                $this->log(
                    'ensureStoreCategories() - Categorie::TYPE_ORDER introuvable (inattendu, constante historique), skip création commande pour boutique rowid=' . $dolibarrStoreId,
                    LOG_WARNING
                );
            } else {
                $catLabel = $this->buildCategoryLabel($storeLabel, 'order');
                $catId    = $this->createCategory($catLabel, $typeOrder, $user);
                if ($catId > 0) {
                    $result['order']                    = $catId;
                    $updateData['fk_categorie_order']   = $catId;
                }
            }
        }

        // -------- Catégorie FACTURE (TYPE_INVOICE) --------
        $existingInvoiceCat = isset($store->fk_categorie_invoice) ? (int) $store->fk_categorie_invoice : 0;
        if ($existingInvoiceCat > 0) {
            $result['invoice'] = $existingInvoiceCat;
            $this->log(
                'ensureStoreCategories() - fk_categorie_invoice déjà défini (' . $existingInvoiceCat . ') pour boutique rowid=' . $dolibarrStoreId . ', skip création facture',
                LOG_DEBUG
            );
        } else {
            // TYPE_INVOICE : constante historique, même remarque que TYPE_PRODUCT ci-dessus.
            $typeInvoice = $this->resolveCategorieTypeConstant('Categorie::TYPE_INVOICE');
            if ($typeInvoice === null) {
                $this->log(
                    'ensureStoreCategories() - Categorie::TYPE_INVOICE introuvable (inattendu, constante historique), skip création facture pour boutique rowid=' . $dolibarrStoreId,
                    LOG_WARNING
                );
            } else {
                $catLabel = $this->buildCategoryLabel($storeLabel, 'invoice');
                $catId    = $this->createCategory($catLabel, $typeInvoice, $user);
                if ($catId > 0) {
                    $result['invoice']                   = $catId;
                    $updateData['fk_categorie_invoice']  = $catId;
                }
            }
        }

        // -------- Catégorie DEVIS (TYPE_PROPOSAL) — Story 48-2 --------
        // TYPE_PROPOSAL n'existe que depuis octobre 2025 (core Dolibarr, commit fc1364db56) :
        // absente sur Dolibarr 18 à 22, alors que le module se déclare compatible dès Dolibarr 18
        // (need_dolibarr_version). Résolution défensive obligatoire (hotfix 2.4.5, Nicolas Graillon
        // 2026-07-28 : Error fatale « Undefined class constant » sur ce chemin non rattrapée).
        $existingProposalCat = isset($store->fk_categorie_proposal) ? (int) $store->fk_categorie_proposal : 0;
        if ($existingProposalCat > 0) {
            $result['proposal'] = $existingProposalCat;
            $this->log(
                'ensureStoreCategories() - fk_categorie_proposal déjà défini (' . $existingProposalCat . ') pour boutique rowid=' . $dolibarrStoreId . ', skip création devis',
                LOG_DEBUG
            );
        } else {
            $typeProposal = $this->resolveCategorieTypeConstant('Categorie::TYPE_PROPOSAL');
            if ($typeProposal === null) {
                $this->log(
                    'ensureStoreCategories() - Categorie::TYPE_PROPOSAL introuvable sur cette version de Dolibarr (< version core ayant introduit la constante), catégorie devis non créée pour boutique rowid=' . $dolibarrStoreId,
                    LOG_WARNING
                );
            } else {
                $catLabel = $this->buildCategoryLabel($storeLabel, 'propal');
                $catId    = $this->createCategory($catLabel, $typeProposal, $user);
                if ($catId > 0) {
                    $result['proposal']                  = $catId;
                    $updateData['fk_categorie_proposal'] = $catId;
                }
            }
        }

        // Persister les nouvelles colonnes en base si au moins une catégorie créée
        if (!empty($updateData)) {
            require_once dirname(__FILE__) . '/storeservice.class.php';
            $storeService = new StoreService($this->db, $this->entity);
            $updateResult = $storeService->update($dolibarrStoreId, $updateData);
            // Story 58-3 : < 0 (pas <= 0) — 0 est désormais un no-op légitime (valeurs déjà
            // identiques), pas une erreur ; ne pas le journaliser en WARNING.
            if ($updateResult < 0) {
                $this->log(
                    'ensureStoreCategories() - Échec UPDATE boutique rowid=' . $dolibarrStoreId . ' (updateResult=' . $updateResult . ')',
                    LOG_WARNING
                );
            } else {
                $this->log(
                    'ensureStoreCategories() - Boutique rowid=' . $dolibarrStoreId . ' mise à jour : ' . json_encode($updateData),
                    LOG_INFO
                );
            }
        }

        return $result;
    }

    /**
     * Tague un objet Dolibarr avec la catégorie boutique du type donné.
     *
     * Non bloquant : tout échec est loggué (WARNING) sans exception propagée.
     * No-op si la catégorie du type est NULL pour cette boutique.
     *
     * Idempotent : Categorie::add_type() retourne 0 si l'objet est déjà membre.
     *
     * @param  object $object  Objet Dolibarr (Commande, Facture, Product…)
     * @param  object $store   Objet boutique (issu de StoreService::fetch())
     * @param  string $type    Constante Categorie::TYPE_* ('order', 'invoice', 'product')
     * @return int             1 = tagué, 0 = déjà membre (no-op), -1 = erreur/skip
     */
    public function tagObjectWithStoreCategory($object, $store, $type): int
    {
        if ($store === null || $object === null) {
            $this->log('tagObjectWithStoreCategory() - store ou object null, skip', LOG_DEBUG);
            return -1;
        }

        // Résoudre la colonne fk_categorie_* selon le type
        $categoryId = $this->resolveCategoryIdForType($store, $type);
        if ($categoryId <= 0) {
            $this->log(
                'tagObjectWithStoreCategory() - catégorie type=' . $type . ' NULL pour boutique rowid=' . (int) ($store->rowid ?? 0) . ', skip silencieux',
                LOG_DEBUG
            );
            return -1;
        }

        try {
            if (!class_exists('Categorie')) {
                dol_include_once('/categories/class/categorie.class.php');
            }

            $cat = new Categorie($this->db);
            if ($cat->fetch($categoryId) <= 0) {
                $this->log(
                    'tagObjectWithStoreCategory() - Catégorie id=' . $categoryId . ' introuvable (type=' . $type . ')',
                    LOG_WARNING
                );
                return -1;
            }

            $result = $cat->add_type($object, $type);

            if ($result > 0) {
                $this->log(
                    'tagObjectWithStoreCategory() - Objet id=' . (int) ($object->id ?? 0) . ' tagué catégorie id=' . $categoryId . ' (type=' . $type . ')',
                    LOG_DEBUG
                );
                return 1;
            } elseif ($result == 0) {
                // Déjà membre — idempotent, pas d'erreur
                $this->log(
                    'tagObjectWithStoreCategory() - Objet id=' . (int) ($object->id ?? 0) . ' déjà dans catégorie id=' . $categoryId . ' (type=' . $type . '), skip',
                    LOG_DEBUG
                );
                return 0;
            } else {
                $this->log(
                    'tagObjectWithStoreCategory() - Erreur add_type (result=' . $result . ') objet id=' . (int) ($object->id ?? 0) . ' catégorie id=' . $categoryId . ' (type=' . $type . ')',
                    LOG_WARNING
                );
                return -1;
            }
        } catch (\Throwable $e) {
            // \Throwable (pas seulement Exception) : une Error fatale (ex. constante de
            // classe absente) ne doit jamais faire échouer le tag d'un objet — hotfix 2.4.5.
            $this->log(
                'tagObjectWithStoreCategory() - Exception type=' . $type . ' catégorie id=' . $categoryId . ' : ' . $e->getMessage(),
                LOG_WARNING
            );
            return -1;
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Résout de façon défensive une constante de classe `Categorie::TYPE_*`.
     *
     * `defined()` ne lève jamais d'erreur — contrairement à un accès direct à une
     * constante de classe absente, qui provoque une `Error` fatale PHP
     * (« Undefined class constant ») non rattrapable par un `catch (Exception)`.
     * Utilisé notamment pour `Categorie::TYPE_PROPOSAL`, introduite dans le core
     * Dolibarr en octobre 2025 (commit fc1364db56) et donc absente sur Dolibarr
     * 18 à 22, alors que le module se déclare compatible dès Dolibarr 18
     * (`need_dolibarr_version`). Hotfix 2.4.5 (Nicolas Graillon, 2026-07-28).
     *
     * @param  string      $constantName Nom complet de la constante (ex. 'Categorie::TYPE_PROPOSAL')
     * @return string|null               Valeur de la constante, ou null si elle n'existe pas
     * @since  2.4.5
     */
    protected function resolveCategorieTypeConstant(string $constantName): ?string
    {
        return defined($constantName) ? constant($constantName) : null;
    }

    /**
     * Résout l'ID de catégorie Dolibarr pour un type donné depuis l'objet boutique.
     *
     * @param  object $store Objet boutique
     * @param  string $type  Constante Categorie::TYPE_* ('product', 'order', 'invoice')
     * @return int           ID catégorie (>0) ou 0 si non défini
     */
    private function resolveCategoryIdForType($store, $type): int
    {
        switch ($type) {
            case 'product':
                return isset($store->fk_categorie) ? (int) $store->fk_categorie : 0;
            case 'order':
                return isset($store->fk_categorie_order) ? (int) $store->fk_categorie_order : 0;
            case 'invoice':
                return isset($store->fk_categorie_invoice) ? (int) $store->fk_categorie_invoice : 0;
            case 'propal':
                return isset($store->fk_categorie_proposal) ? (int) $store->fk_categorie_proposal : 0;
            default:
                $this->log('resolveCategoryIdForType() - type inconnu: ' . $type, LOG_WARNING);
                return 0;
        }
    }

    /**
     * Construit le label d'une catégorie boutique selon le type.
     *
     * Format : "Doli2Shop - {label boutique} - {suffixe i18n}"
     *
     * @param  string $storeLabel Label de la boutique
     * @param  string $type       Type ('product', 'order', 'invoice')
     * @return string             Label de la catégorie
     */
    public static function buildCategoryLabel(string $storeLabel, string $type): string
    {
        global $langs;

        // Charger la langue si disponible (contexte web) — fichier langs/<lang>/doli2shop.lang
        if (!empty($langs)) {
            $langs->load('doli2shop@doli2shop');
        }

        // Nettoyer le label boutique (évite tout HTML résiduel dans le nom de catégorie)
        $storeLabel = strip_tags($storeLabel);

        switch ($type) {
            case 'product':
                $suffix = (!empty($langs) && $langs->trans('StoreProductCategory') !== 'StoreProductCategory')
                    ? $langs->trans('StoreProductCategory')
                    : 'Produits';
                break;
            case 'order':
                $suffix = (!empty($langs) && $langs->trans('StoreOrderCategory') !== 'StoreOrderCategory')
                    ? $langs->trans('StoreOrderCategory')
                    : 'Commandes';
                break;
            case 'invoice':
                $suffix = (!empty($langs) && $langs->trans('StoreInvoiceCategory') !== 'StoreInvoiceCategory')
                    ? $langs->trans('StoreInvoiceCategory')
                    : 'Factures';
                break;
            case 'propal':
                $suffix = (!empty($langs) && $langs->trans('StoreProposalCategory') !== 'StoreProposalCategory')
                    ? $langs->trans('StoreProposalCategory')
                    : 'Devis';
                break;
            default:
                $suffix = $type;
        }

        return 'Doli2Shop - ' . $storeLabel . ' - ' . $suffix;
    }

    /**
     * Crée une Categorie Dolibarr du type donné et retourne son rowid.
     *
     * @param  string $label Libellé de la catégorie
     * @param  string $type  Constante Categorie::TYPE_* (string, jamais int)
     * @param  User   $user  Utilisateur Dolibarr (requis par Categorie::create())
     * @return int           rowid créé (>0) ou 0 en cas d'échec
     */
    private function createCategory(string $label, string $type, $user): int
    {
        try {
            if (!class_exists('Categorie')) {
                dol_include_once('/categories/class/categorie.class.php');
            }

            $category          = new Categorie($this->db);
            $category->label   = $label;
            $category->type    = $type;   // STRING ('product'/'order'/'invoice') — jamais int
            $category->entity  = $this->entity;

            $catId = $category->create($user);

            if ($catId > 0) {
                $this->log(
                    'createCategory() - Catégorie créée : "' . $label . '" type=' . $type . ' id=' . $catId,
                    LOG_INFO
                );
                return $catId;
            } else {
                $this->log(
                    'createCategory() - Échec création catégorie "' . $label . '" type=' . $type . ' (result=' . $catId . ')',
                    LOG_WARNING
                );
                return 0;
            }
        } catch (\Throwable $e) {
            // \Throwable (pas seulement Exception) : défense en profondeur, hotfix 2.4.5.
            $this->log(
                'createCategory() - Exception création "' . $label . '" type=' . $type . ' : ' . $e->getMessage(),
                LOG_WARNING
            );
            return 0;
        }
    }
}
