<?php
/**
 * @file        class/imageduplicatescleanupservice.class.php
 * @brief       Nettoyage des doublons de photos produit créés par l'ancien uniqid() (AC5)
 *
 * Story import-images-duplique-les-photos-a-chaque-synchronisation. Avant le correctif de
 * importProductImages()/addImageToProduct(), chaque appel (notamment le webhook `products/update`,
 * onlyNew=false) recopiait TOUTES les images du produit sous un nom `<ref>_<uniqid()>.<ext>` —
 * `uniqid()` garantissant qu'aucun appel ne pouvait jamais reconnaître le fichier déposé par
 * l'appel précédent. Corriger la source ne nettoie pas l'existant : ce service identifie, pour
 * les produits mappés Shopify, les fichiers correspondant EXACTEMENT à ce motif legacy et
 * propose leur suppression — toujours en deux temps (aperçu puis application explicite), et
 * jamais sur un fichier qui ne matche pas le motif du module (AC4 : les photos du client ne sont
 * jamais concernées).
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.5.3
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/product/class/product.class.php');
require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Identifie et supprime (sur confirmation explicite) les doublons legacy `<ref>_<uniqid()>.<ext>`
 * déposés par le module avant le correctif de dédup par shopify_image_id.
 */
class ImageDuplicatesCleanupService
{
    use LoggerTrait;

    /**
     * Motif EXACT des fichiers legacy déposés par addImageToProduct() avant ce correctif :
     * `<ref>_<uniqid() 13 caractères hex>.<ext>` — ex. `P46_6a831e6e5e0bd.jpg`. `uniqid()` produit
     * par défaut 13 caractères hexadécimaux (temps courant en microsecondes) : un nom de fichier
     * client, presque toujours descriptif (tirets, mots), ne matche cette forme que par une
     * coïncidence astronomiquement improbable — condition nécessaire à l'AC4 (ne jamais toucher
     * une photo déposée par le client).
     */
    private const LEGACY_UNIQID_PATTERN = '/^(.*)_([0-9a-f]{13})\.([a-zA-Z0-9]+)$/';

    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /**
     * @param DoliDB $db Database handler
     * @param int $entity Entité Dolibarr courante
     */
    public function __construct($db, $entity)
    {
        $this->db = $db;
        $this->entity = (int) $entity;
    }

    /**
     * Aperçu (dry-run, aucune écriture) : pour chaque produit Dolibarr mappé Shopify (parent,
     * pas variante) de cette entité, liste les fichiers legacy en double dans son répertoire
     * d'images. Le fichier le plus récent (mtime) est proposé comme conservé — c'est celui du
     * dernier passage de synchronisation réussi, les autres sont des copies antérieures.
     *
     * @return array<int,array{fk_product:int,ref:string,keep:array{filename:string,size:int,mtime:int},toDelete:array<int,array{filename:string,size:int,mtime:int}>}>
     */
    public function scan()
    {
        $report = [];

        foreach ($this->getMappedParentProductIds() as $fkProduct) {
            $product = new Product($this->db);
            if ($product->fetch($fkProduct) <= 0) {
                continue;
            }

            $dir = $this->getProductImageDir($product);
            if (!is_dir($dir)) {
                continue;
            }

            $legacyFiles = $this->findLegacyFiles($dir, $product->ref);

            if (count($legacyFiles) < 2) {
                // Pas de doublon : 0 ou 1 seul fichier legacy pour ce produit — rien à nettoyer.
                continue;
            }

            usort($legacyFiles, function ($a, $b) {
                return $b['mtime'] <=> $a['mtime'];
            });

            $keep = array_shift($legacyFiles);

            $report[] = [
                'fk_product' => (int) $fkProduct,
                'ref' => $product->ref,
                'keep' => $keep,
                'toDelete' => $legacyFiles,
            ];
        }

        return $report;
    }

    /**
     * Applique la suppression d'un sous-ensemble de fichiers explicitement confirmés (jamais
     * "tout" implicitement — chaque entrée doit venir d'un aperçu précédent). Chaque fichier est
     * revérifié CONTRE le motif legacy et la ref du produit juste avant suppression, indépendamment
     * de ce que scan() avait renvoyé (défense en profondeur AC4/AC5).
     *
     * HIGH (revue coordinateur, prouvé par exécution du code réel) : la protection "on garde le
     * fichier le plus récent" n'existait auparavant QUE dans l'affichage de scan() (aucune case à
     * cocher générée pour cette ligne) — rien côté serveur n'empêchait une requête POST altérée
     * de soumettre CE fichier aussi, et de faire disparaître irréversiblement la dernière photo
     * réelle d'un produit. Garantie ajoutée, vérifiée par PRODUIT et indépendamment de ce qu'un
     * formulaire a soumis : si supprimer tous les fichiers éligibles d'un produit ne laisserait
     * AUCUN fichier sur son disque, la suppression est refusée EN BLOC pour ce produit (jamais
     * une suppression partielle qui laisserait passer "presque tout").
     *
     * @param array<int,array{fk_product:int,filename:string}> $selection Fichiers à supprimer
     * @return array{deleted:int,errors:string[]}
     */
    public function apply(array $selection)
    {
        $deleted = 0;
        $errors = [];

        // Regroupement par produit : la garantie "au moins un fichier survit" se vérifie PAR
        // PRODUIT, indépendamment des autres produits présents dans la même sélection.
        $filenamesByProduct = [];
        foreach ($selection as $item) {
            $fkProduct = isset($item['fk_product']) ? (int) $item['fk_product'] : 0;
            $filename = isset($item['filename']) ? (string) $item['filename'] : '';

            if ($fkProduct <= 0 || $filename === '') {
                continue;
            }

            $filenamesByProduct[$fkProduct][] = $filename;
        }

        foreach ($filenamesByProduct as $fkProduct => $filenames) {
            $product = new Product($this->db);
            if ($product->fetch($fkProduct) <= 0) {
                $errors[] = "Produit $fkProduct introuvable";
                continue;
            }

            $dir = $this->getProductImageDir($product);

            // Toutes provenances confondues (hors thumbs/) — c'est CE compte, pas seulement les
            // fichiers "legacy", qui détermine combien il en restera réellement après suppression.
            $allFilesOnDisk = $this->listAllImageFiles($dir);

            $eligibleFilenames = [];
            foreach ($filenames as $filename) {
                if (!preg_match(self::LEGACY_UNIQID_PATTERN, $filename, $matches) || $matches[1] !== $product->ref) {
                    // AC4 : ne supprime jamais un fichier qui ne correspond pas EXACTEMENT au
                    // motif du module pour CE produit — même si la sélection venait d'un
                    // formulaire soumis.
                    $errors[] = "Fichier $filename ignoré (ne correspond pas au motif déposé par le module pour ce produit $fkProduct)";
                    continue;
                }

                if (!in_array($filename, $allFilesOnDisk, true)) {
                    $errors[] = "Fichier $filename déjà absent (produit $fkProduct)";
                    continue;
                }

                $eligibleFilenames[] = $filename;
            }

            $eligibleFilenames = array_values(array_unique($eligibleFilenames));

            if (empty($eligibleFilenames)) {
                continue;
            }

            $remainingCount = count($allFilesOnDisk) - count($eligibleFilenames);

            if ($remainingCount < 1) {
                $errors[] = "Produit $fkProduct : suppression refusée (laisserait 0 fichier sur "
                    . "disque — au moins 1 photo doit toujours survivre par produit)";
                continue;
            }

            foreach ($eligibleFilenames as $filename) {
                $filePath = rtrim($dir, '/') . '/' . $filename;

                if (@unlink($filePath)) {
                    $deleted++;
                    $this->deleteThumbs($dir, $filename);
                    $this->log("ImageDuplicatesCleanupService::apply - Fichier legacy supprimé: $filePath", LOG_INFO);
                } else {
                    $errors[] = "Échec de suppression de $filename (produit $fkProduct)";
                }
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * @param string $dir Répertoire disque du produit
     * @return string[] Noms de TOUS les fichiers réellement présents (hors thumbs/, hors . et ..)
     *                   — toutes provenances confondues, pas seulement le motif legacy. Sert à
     *                   déterminer combien de fichiers survivraient réellement après suppression.
     */
    private function listAllImageFiles($dir)
    {
        $files = [];
        $entries = @scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'thumbs') {
                continue;
            }
            if (is_file(rtrim($dir, '/') . '/' . $entry)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * @return int[] fk_product des produits PARENTS (pas variantes) mappés Shopify pour cette entité
     */
    private function getMappedParentProductIds()
    {
        $ids = [];

        $sql = "SELECT DISTINCT fk_product FROM " . MAIN_DB_PREFIX . "doli2shop_products";
        $sql .= " WHERE entity = " . (int) $this->entity;
        $sql .= " AND (fk_product_parent IS NULL OR fk_product_parent = 0)";

        $result = $this->db->query($sql);

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $ids[] = (int) $obj->fk_product;
            }
        }

        return $ids;
    }

    /**
     * @param Product $product Dolibarr product object
     * @return string Chemin absolu du répertoire d'images du produit (sans slash final)
     */
    private function getProductImageDir($product)
    {
        global $conf;

        return rtrim($conf->product->multidir_output[$product->entity], '/') . '/' . get_exdir(0, 0, 0, 1, $product, 'product');
    }

    /**
     * Liste les fichiers du répertoire correspondant exactement au motif legacy ET dont le
     * préfixe correspond EXACTEMENT à la ref du produit (jamais une correspondance approximative
     * qui pourrait viser par coïncidence un fichier client — AC4).
     *
     * @param string $dir Répertoire disque du produit
     * @param string $ref Référence du produit
     * @return array<int,array{filename:string,size:int,mtime:int}>
     */
    private function findLegacyFiles($dir, $ref)
    {
        $files = [];
        $entries = @scandir($dir) ?: [];

        foreach ($entries as $entry) {
            $entryPath = rtrim($dir, '/') . '/' . $entry;

            if ($entry === '.' || $entry === '..' || !is_file($entryPath)) {
                continue;
            }

            if (!preg_match(self::LEGACY_UNIQID_PATTERN, $entry, $matches)) {
                continue;
            }

            if ($matches[1] !== $ref) {
                continue;
            }

            $files[] = [
                'filename' => $entry,
                'size' => (int) (@filesize($entryPath) ?: 0),
                'mtime' => (int) (@filemtime($entryPath) ?: 0),
            ];
        }

        return $files;
    }

    /**
     * Supprime les vignettes `thumbs/<basename>_*` d'un fichier (Dolibarr se fie à l'existence
     * du fichier de vignette, pas à son contenu). Balaie le répertoire plutôt qu'un `glob()`
     * construit avec le nom de fichier, pour ne jamais dépendre d'éventuels métacaractères glob
     * dans une ref produit.
     *
     * @param string $dir Répertoire disque du produit (parent de thumbs/)
     * @param string $filename Nom du fichier dont on supprime les vignettes
     * @return void
     */
    private function deleteThumbs($dir, $filename)
    {
        $thumbsDir = rtrim($dir, '/') . '/thumbs';

        if (!is_dir($thumbsDir)) {
            return;
        }

        $prefix = pathinfo($filename, PATHINFO_FILENAME) . '_';
        $entries = @scandir($thumbsDir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (strpos($entry, $prefix) === 0) {
                $thumbFile = $thumbsDir . '/' . $entry;
                if (is_file($thumbFile)) {
                    @unlink($thumbFile);
                }
            }
        }
    }
}
