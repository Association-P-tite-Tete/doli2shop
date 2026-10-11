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
 * Story 68-4 (2.6.1) : un fichier n'est proposé QUE s'il a au moins un jumeau de contenu identique
 * (empreinte SHA-256) dans le même produit — deux photos différentes ne sont jamais proposées ;
 * rien n'est coché par défaut ; apply() revérifie le contenu côté serveur et supprime par
 * dol_delete_file() (ligne d'index ecm_files comprise).
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.2
 * @since       2.5.3
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

dol_include_once('/core/lib/functions.lib.php');
dol_include_once('/core/lib/files.lib.php');
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

    /** @var array<string,string|null> Empreintes déjà calculées dans cette requête (clé chemin|taille|mtime) */
    private $fingerprintCache = [];

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
     * pas variante) de cette entité, liste les groupes de fichiers legacy de CONTENU IDENTIQUE
     * (empreinte SHA-256) dans son répertoire d'images. Un fichier legacy dont le contenu est
     * unique n'est jamais proposé. Dans chaque groupe (>= 2 fichiers), le fichier conservé est :
     * (1) celui référencé dans doli2shop_product_images, sinon (2) celui dont la ligne ecm_files a
     * la position la plus basse, sinon (3) le plus récent (mtime), sinon (4) l'ordre alphabétique.
     *
     * Une entrée de rapport par groupe (un produit peut en avoir plusieurs).
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
                // Pas de doublon possible : 0 ou 1 seul fichier legacy pour ce produit.
                continue;
            }

            $mapped = $this->getMappedFilenames((int) $fkProduct);

            foreach ($this->groupByContent($dir, $legacyFiles) as $group) {
                $filenames = array_map(function ($file) {
                    return $file['filename'];
                }, $group);

                $positions = $this->getEcmPositions($product, $filenames);

                usort($group, function ($a, $b) use ($mapped, $positions) {
                    $aMapped = isset($mapped[$a['filename']]) ? 0 : 1;
                    $bMapped = isset($mapped[$b['filename']]) ? 0 : 1;
                    if ($aMapped !== $bMapped) {
                        return $aMapped <=> $bMapped;
                    }

                    $aPos = isset($positions[$a['filename']]) ? $positions[$a['filename']] : PHP_INT_MAX;
                    $bPos = isset($positions[$b['filename']]) ? $positions[$b['filename']] : PHP_INT_MAX;
                    if ($aPos !== $bPos) {
                        return $aPos <=> $bPos;
                    }

                    if ($a['mtime'] !== $b['mtime']) {
                        return $b['mtime'] <=> $a['mtime'];
                    }

                    return strcmp($a['filename'], $b['filename']);
                });

                $keep = array_shift($group);

                $report[] = [
                    'fk_product' => (int) $fkProduct,
                    'ref' => $product->ref,
                    'keep' => $keep,
                    'toDelete' => $group,
                ];
            }
        }

        return $report;
    }

    /**
     * Regroupe des fichiers par contenu identique. Pré-filtre par taille : seuls les fichiers dont
     * la taille coïncide avec celle d'au moins un autre sont hachés (hash_file sha256).
     *
     * @param string $dir Répertoire disque du produit
     * @param array<int,array{filename:string,size:int,mtime:int}> $files
     * @return array<int,array<int,array{filename:string,size:int,mtime:int}>> Groupes de >= 2 fichiers
     */
    private function groupByContent($dir, array $files)
    {
        $bySize = [];
        foreach ($files as $file) {
            $bySize[$file['size']][] = $file;
        }

        $byHash = [];
        foreach ($bySize as $sameSize) {
            if (count($sameSize) < 2) {
                continue;
            }
            foreach ($sameSize as $file) {
                $hash = $this->fingerprint(rtrim($dir, '/') . '/' . $file['filename']);
                if ($hash === null) {
                    continue;
                }
                $byHash[$hash][] = $file;
            }
        }

        $groups = [];
        foreach ($byHash as $group) {
            if (count($group) >= 2) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param string $path Chemin absolu
     * @return string|null Empreinte SHA-256 du contenu, null si illisible
     */
    private function fingerprint($path)
    {
        $cacheKey = $path . '|' . @filesize($path) . '|' . @filemtime($path);
        if (array_key_exists($cacheKey, $this->fingerprintCache)) {
            return $this->fingerprintCache[$cacheKey];
        }

        $hash = @hash_file('sha256', $path);

        return $this->fingerprintCache[$cacheKey] = (is_string($hash) ? $hash : null);
    }

    /**
     * @param int $fkProduct Produit Dolibarr
     * @return array<string,bool> Noms de fichiers référencés dans doli2shop_product_images
     */
    private function getMappedFilenames($fkProduct)
    {
        $names = [];

        $sql = "SELECT filename FROM " . MAIN_DB_PREFIX . "doli2shop_product_images";
        $sql .= " WHERE entity = " . (int) $this->entity;
        $sql .= " AND fk_product = " . (int) $fkProduct;

        $result = $this->db->query($sql);

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $names[(string) $obj->filename] = true;
            }
        }

        return $names;
    }

    /**
     * Positions de l'index Dolibarr (llx_ecm_files) des fichiers donnés. Index absent ou vide :
     * tableau vide (le choix du fichier conservé retombe alors sur mtime), jamais d'échec.
     *
     * @param Product $product Produit Dolibarr
     * @param string[] $filenames Noms de fichiers
     * @return array<string,int> nom de fichier => position
     */
    private function getEcmPositions($product, array $filenames)
    {
        $positions = [];

        if (empty($filenames)) {
            return $positions;
        }

        $entity = !empty($product->entity) ? (int) $product->entity : $this->entity;
        $refDir = 'produit/' . $product->ref;

        $quoted = [];
        foreach ($filenames as $filename) {
            $quoted[] = "'" . $this->db->escape($filename) . "'";
        }

        $sql = "SELECT filename, position FROM " . MAIN_DB_PREFIX . "ecm_files";
        $sql .= " WHERE entity = " . $entity;
        $sql .= " AND (filepath = '" . $this->db->escape($refDir) . "'";
        $sql .= " OR filepath = '" . $this->db->escape($entity . '/' . $refDir) . "')";
        $sql .= " AND filename IN (" . implode(',', $quoted) . ")";

        $result = $this->db->query($sql);

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                if ($obj->position !== null && $obj->position !== '') {
                    $positions[(string) $obj->filename] = (int) $obj->position;
                }
            }
        }

        return $positions;
    }

    /**
     * Applique la suppression d'un sous-ensemble de fichiers explicitement confirmés (jamais
     * "tout" implicitement). Chaque fichier est revérifié CÔTÉ SERVEUR, indépendamment de ce
     * qu'un aperçu ou un formulaire (périmé, forgé) a pu soumettre :
     *  - motif legacy + ref du produit (AC4) et présence sur disque ;
     *  - CONTENU (story 68-4) : un fichier n'est supprimé que s'il existe, sur disque, au moins
     *    un autre fichier de même empreinte SHA-256 qui ne figure PAS dans la sélection du
     *    produit — une photo distincte est donc refusée, et un groupe identique entièrement
     *    coché est refusé en bloc (au plus n-1 membres supprimés) ;
     *  - filet : au moins 1 fichier survit par produit.
     * Suppression par dol_delete_file() (retire aussi la ligne d'index ecm_files).
     *
     * @param array<int,array{fk_product:int,filename:string}> $selection Fichiers à supprimer
     * @return array{deleted:int,errors:string[],nothingSelected:bool,lockBusy:bool}
     */
    public function apply(array $selection)
    {
        $deleted = 0;
        $errors = [];
        $lockBusy = false;

        $filenamesByProduct = [];
        foreach ($selection as $item) {
            $fkProduct = isset($item['fk_product']) ? (int) $item['fk_product'] : 0;
            $filename = isset($item['filename']) ? (string) $item['filename'] : '';

            if ($fkProduct <= 0 || $filename === '') {
                continue;
            }

            $filenamesByProduct[$fkProduct][] = $filename;
        }

        $mappedParentIds = $filenamesByProduct ? $this->getMappedParentProductIds() : [];

        foreach ($filenamesByProduct as $fkProduct => $filenames) {
            if (!in_array((int) $fkProduct, $mappedParentIds, true)) {
                $errors[] = "Produit $fkProduct ignoré (n'est pas un produit parent mappé Shopify de cette entité)";
                continue;
            }

            $product = new Product($this->db);
            if ($product->fetch($fkProduct) <= 0) {
                $errors[] = "Produit $fkProduct introuvable";
                continue;
            }

            $dir = $this->getProductImageDir($product);

            // Verrou exclusif NON bloquant par dossier produit : deux requêtes concurrentes visant
            // chacune un des deux jumeaux ne doivent pas supprimer les deux. Verrou déjà tenu =>
            // refus propre, aucune suppression.
            $lockPath = self::lockPathForDir($dir);
            if (is_link($lockPath)) {
                $errors[] = "Produit $fkProduct : fichier de verrou refusé (lien symbolique), suppression non effectuée";
                continue;
            }
            $previousUmask = umask(0177);
            $lockHandle = @fopen($lockPath, 'c');
            umask($previousUmask);
            if ($lockHandle === false) {
                $errors[] = "Produit $fkProduct : verrou indisponible, suppression non effectuée";
                continue;
            }
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                fclose($lockHandle);
                $lockBusy = true;
                $errors[] = "Produit $fkProduct : un nettoyage est déjà en cours pour ce produit, suppression non effectuée";
                continue;
            }

            try {
                $mappedFilenames = $this->getMappedFilenames((int) $fkProduct);

                // Toutes provenances confondues (hors thumbs/).
                $allFilesOnDisk = $this->listAllImageFiles($dir);

                $eligibleFilenames = [];
                foreach ($filenames as $filename) {
                    if (!preg_match(self::LEGACY_UNIQID_PATTERN, $filename, $matches) || $matches[1] !== $product->ref) {
                        $errors[] = "Fichier $filename ignoré (ne correspond pas au motif déposé par le module pour ce produit $fkProduct)";
                        continue;
                    }

                    if (isset($mappedFilenames[$filename])) {
                        $errors[] = "Fichier $filename refusé (produit $fkProduct) : référencé par la synchronisation Shopify";
                        continue;
                    }

                    if (!in_array($filename, $allFilesOnDisk, true)) {
                        $errors[] = "Fichier $filename déjà absent (produit $fkProduct)";
                        continue;
                    }

                    $eligibleFilenames[] = $filename;
                }

                $eligibleFilenames = array_values(array_unique($eligibleFilenames));

                foreach ($eligibleFilenames as $filename) {
                    $filePath = rtrim($dir, '/') . '/' . $filename;

                    clearstatcache();

                    // Revérification juste avant CHAQUE suppression, sur l'état courant du disque :
                    // un jumeau identique doit exister, hors sélection.
                    $currentFiles = $this->listAllImageFiles($dir);
                    $survivors = array_values(array_diff($currentFiles, $eligibleFilenames));

                    if (!in_array($filename, $currentFiles, true)) {
                        $errors[] = "Fichier $filename déjà absent (produit $fkProduct)";
                        continue;
                    }

                    if (!$this->hasSurvivingTwin($dir, $filename, $survivors)) {
                        $errors[] = "Fichier $filename refusé (produit $fkProduct) : aucune copie identique ne subsisterait — "
                            . "une photo de contenu distinct n'est jamais supprimée";
                        continue;
                    }

                    if (count($currentFiles) - 1 < 1) {
                        $errors[] = "Produit $fkProduct : suppression refusée (laisserait 0 fichier sur "
                            . "disque — au moins 1 photo doit toujours survivre par produit)";
                        continue;
                    }

                    // disableglob=1 : un nom contenant * ou ? ne vise jamais plusieurs fichiers.
                    if (dol_delete_file($filePath, 1) && !file_exists($filePath)) {
                        $deleted++;
                        $this->deleteThumbs($dir, $filename);
                        $this->log("ImageDuplicatesCleanupService::apply - Fichier legacy supprimé: $filePath", LOG_INFO);
                    } else {
                        $errors[] = "Échec de suppression de $filename (produit $fkProduct)";
                    }
                }
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors, 'nothingSelected' => empty($filenamesByProduct), 'lockBusy' => $lockBusy];
    }

    /**
     * Chemin du fichier de verrou d'un dossier produit (répertoire temporaire, jamais le dossier photos).
     *
     * @param string $dir Répertoire disque du produit
     * @return string
     */
    public static function lockPathForDir($dir)
    {
        return sys_get_temp_dir() . '/d2s_imgdup_' . md5($dir) . '.lock';
    }

    /**
     * @param string $dir Répertoire disque du produit
     * @param string $filename Fichier candidat à la suppression
     * @param string[] $survivors Fichiers du produit qui resteraient sur disque
     * @return bool true si l'un des survivants a exactement le même contenu
     */
    private function hasSurvivingTwin($dir, $filename, array $survivors)
    {
        $path = rtrim($dir, '/') . '/' . $filename;
        $size = @filesize($path);
        $hash = $this->fingerprint($path);

        if ($hash === null || $size === false) {
            return false;
        }

        foreach ($survivors as $survivor) {
            $survivorPath = rtrim($dir, '/') . '/' . $survivor;
            if (@filesize($survivorPath) !== $size) {
                continue;
            }
            if ($this->fingerprint($survivorPath) === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rendu HTML du formulaire d'aperçu (extrait de la page pour être testable). Aucune case
     * cochée par défaut ; la confirmation n'est jamais construite dans un onclick : le message
     * (avec __COUNT__) voyage dans data-confirm (dol_escape_htmltag) et un script compte les
     * cases cochées au submit (refus à 0 case).
     *
     * @param array $report Résultat de scan()
     * @param object $langs Translate
     * @param string $token Jeton CSRF
     * @return string HTML ('' si rapport vide)
     */
    public static function renderPreviewForm(array $report, $langs, $token)
    {
        if (empty($report)) {
            return '';
        }

        $html = '<form method="POST" id="d2s-imgdup-form" action="' . dol_escape_htmltag($_SERVER['PHP_SELF'] ?? '') . '"';
        $html .= ' data-confirm="' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupApplyConfirm', '__COUNT__')) . '"';
        $html .= ' data-nothing="' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupNothingSelected')) . '">';
        $html .= '<input type="hidden" name="token" value="' . dol_escape_htmltag($token) . '">';
        $html .= '<input type="hidden" name="action" value="apply">';

        $html .= '<table class="noborder centpercent">';
        $html .= '<tr class="liste_titre">';
        $html .= '<th></th>';
        foreach (array('ImageDuplicatesCleanupColProduct', 'ImageDuplicatesCleanupColFile', 'ImageDuplicatesCleanupColSize', 'ImageDuplicatesCleanupColDate', 'ImageDuplicatesCleanupColAction') as $columnKey) {
            $html .= '<th>' . dol_escape_htmltag($langs->trans($columnKey)) . '</th>';
        }
        $html .= '</tr>';

        foreach ($report as $productReport) {
            $fkProduct = (int) $productReport['fk_product'];
            $rowspan = 1 + count($productReport['toDelete']);

            $html .= '<tr class="oddeven">';
            $html .= '<td rowspan="' . $rowspan . '"></td>';
            $html .= '<td rowspan="' . $rowspan . '">' . dol_escape_htmltag($productReport['ref']) . ' (#' . $fkProduct . ')</td>';
            $html .= '<td>' . dol_escape_htmltag($productReport['keep']['filename']) . '</td>';
            $html .= '<td>' . dol_print_size($productReport['keep']['size']) . '</td>';
            $html .= '<td>' . dol_print_date($productReport['keep']['mtime'], 'dayhour') . '</td>';
            $html .= '<td><span class="badge badge-status4">' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupKept')) . '</span></td>';
            $html .= '</tr>';

            foreach ($productReport['toDelete'] as $fileInfo) {
                $checkboxValue = $fkProduct . '|' . $fileInfo['filename'];

                $html .= '<tr class="oddeven">';
                $html .= '<td>' . dol_escape_htmltag($fileInfo['filename']) . '</td>';
                $html .= '<td>' . dol_print_size($fileInfo['size']) . '</td>';
                $html .= '<td>' . dol_print_date($fileInfo['mtime'], 'dayhour') . '</td>';
                $html .= '<td><input type="checkbox" name="delete[]" value="' . dol_escape_htmltag($checkboxValue) . '"></td>';
                $html .= '</tr>';
            }
        }

        $html .= '</table>';

        $html .= '<div class="tabsAction">';
        $html .= '<button type="submit" class="butAction">';
        $html .= '<i class="fa fa-trash"></i> ' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupApplyButton'));
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</form>';

        $html .= '<script>(function () {'
            . 'var f = document.getElementById("d2s-imgdup-form");'
            . 'if (!f) { return; }'
            . 'f.addEventListener("submit", function (e) {'
            . 'var n = f.querySelectorAll(\'input[name="delete[]"]:checked\').length;'
            . 'if (n === 0) { alert(f.getAttribute("data-nothing")); e.preventDefault(); return; }'
            . 'if (!confirm(f.getAttribute("data-confirm").replace("__COUNT__", n))) { e.preventDefault(); }'
            . '});'
            . '})();</script>';

        return $html;
    }

    /**
     * Rendu HTML du résultat d'une application (succès, erreurs, ou avertissement « rien
     * sélectionné »).
     *
     * @param array{deleted:int,errors:string[],nothingSelected?:bool} $applyResult Résultat de apply()
     * @param object $langs Translate
     * @return string HTML
     */
    public static function renderApplyResult(array $applyResult, $langs)
    {
        $html = '';

        if (!empty($applyResult['nothingSelected'])) {
            $html .= '<div class="warning">' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupNothingSelected')) . '</div>';
        }
        if (!empty($applyResult['lockBusy'])) {
            $html .= '<div class="warning">' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupLockBusy')) . '</div>';
        }
        if (!empty($applyResult['deleted'])) {
            $html .= '<div class="ok">' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupApplied', (string) $applyResult['deleted'])) . '</div>';
        }
        if (!empty($applyResult['errors'])) {
            $html .= '<div class="error"><strong>' . dol_escape_htmltag($langs->trans('ImageDuplicatesCleanupErrors')) . '</strong><ul>';
            foreach ($applyResult['errors'] as $errorMessage) {
                $html .= '<li>' . dol_escape_htmltag($errorMessage) . '</li>';
            }
            $html .= '</ul></div>';
        }

        return $html;
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
