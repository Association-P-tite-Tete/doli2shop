<?php

/**
 * @file        class/dolibarrdirectfileresolver.class.php
 * @brief       Lecture directe des images produit ET catégorie Dolibarr (llx_ecm_files + disque),
 *              sans API REST self
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.5.0
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible avec chargement dans init() et par modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

// Load dependencies
dol_include_once('/core/lib/functions.lib.php');
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Class DolibarrDirectFileResolver
 *
 * Story 57-1 (Epic 57 — suppression de la dépendance à l'API REST Dolibarr self) :
 * liste et lit les images d'un produit directement depuis `llx_ecm_files` et le disque
 * (`multidir_output`), sans passer par un appel HTTP à l'API REST Dolibarr.
 *
 * Le module s'exécute DANS le process Dolibarr (`$conf`/`$db` déjà chargés) : aucune
 * barrière réseau/loopback/droits API n'est nécessaire pour lire ses propres fichiers,
 * contrairement à l'appel HTTP self historique (URL à configurer, résolution loopback,
 * entité de l'utilisateur porteur de la clé API...).
 *
 * Note d'audit (cf. docs/planning-artifacts/epic-57-suppression-api-dolibarr-self.md) :
 * l'API REST self vérifiait `$user->hasRight('produit','lire')` / `$user->hasRight('categorie','lire')`
 * avant de servir un fichier ; cet accès direct s'exécute avec le privilège du process
 * Dolibarr, comme tout code de module PHP appelé depuis Dolibarr — contournement assumé et
 * documenté.
 *
 * Branché depuis 57-2 (produits, `ImportProducts::getImageContent()`) et 57-7 (catégories,
 * `ImportProducts::uploadCategoryImageToCollection()`) : ce backend est désormais le SEUL
 * chemin de lecture d'image du module, pour les deux types d'objets. Plus aucun appelant
 * n'utilise l'API REST Dolibarr self.
 *
 * ⚠️ Asymétrie ASSUMÉE entre les deux volets (documentée en tête de chaque méthode
 * catégorie) : produits = `llx_ecm_files` (source de vérité) + disque ; catégories = disque
 * SEUL (`Categorie::add_photo()` n'indexe jamais dans `ecm_files`, contrairement au produit).
 * Ne pas "corriger" par symétrie, ce serait un retour en arrière.
 */
class DolibarrDirectFileResolver
{
    use LoggerTrait;

    /** @var \DoliDB Handle base Dolibarr */
    private $db;

    /** @var int Entité Dolibarr à utiliser pour les requêtes SQL et la résolution disque */
    private $entity;

    /**
     * Constructeur minimal — PAS de ShopifyApi ni de chargement de configuration.
     * Le resolver est autonome : il n'a besoin que d'un handle DB et d'une entité.
     *
     * @param \DoliDB  $db     Handle base Dolibarr
     * @param int|null $entity Entité à utiliser (par défaut : $conf->entity)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = $entity ?: $conf->entity;
    }

    /**
     * Liste les images d'un produit (hors vignettes `%/thumbs/%`), triées par position.
     *
     * Format IDENTIQUE à ImportProducts::getDolibarrImages()/buildImagesFromOrderedMap()
     * pour un branchement 57-2 sans adaptation des consommateurs.
     *
     * @param object $product Produit Dolibarr (au minimum : id, ref ; entity optionnel)
     * @return array<array{name:string, relativename:string, size:int, level1name:string, position:int}>
     */
    public function listProductImages($product): array
    {
        $orderedImages = $this->fetchOrderedImagesFromDb($product);

        // AC2 : le chemin indexé garde la priorité ABSOLUE — s'il produit ne serait-ce qu'une
        // image, on la renvoie telle quelle (même ordre, même déduplication, mêmes tailles
        // qu'avant cette story). Aucune régression possible.
        if (!empty($orderedImages)) {
            $indexed = $this->buildImagesFromOrderedMap($orderedImages, $product);
            if (!empty($indexed)) {
                // ⚠️ Cas relevé par la revue 3 couches (MEDIUM) : l'index peut être
                // PARTIELLEMENT obsolète — quelques lignes valides, d'autres pointant dans le
                // vide, et des fichiers bien réels sur le disque qui n'y figurent pas du tout.
                // Le produit repart alors avec 1 image sur 3, silencieusement : c'est la même
                // classe de symptôme que celle corrigée ici, avec un seuil plus étroit.
                //
                // ⛔ On ne FUSIONNE PAS pour autant. Ajouter d'office les fichiers du disque
                // reviendrait à publier des images que le client n'a peut-être pas voulues (un
                // ancien visuel resté sur le serveur, par exemple) — un effet de bord bien pire
                // qu'une image manquante, et sur le catalogue de TOUS les clients. Cet
                // arbitrage appartient au mainteneur, pas à ce correctif.
                //
                // On rend donc l'écart VISIBLE, ce qui est précisément ce qui manquait au
                // défaut d'origine, sans rien changer au résultat renvoyé.
                $this->warnIfDiskHasMoreFilesThanIndex($product, count($indexed));

                return $indexed;
            }

            // ⚠️ Cas ajouté après relecture : l'index contenait des lignes, mais AUCUNE n'a
            // survécu au filtre de taille disque de buildImagesFromOrderedMap() — autrement dit
            // l'index pointe des fichiers introuvables ou vides. C'est le cas « indexé mais
            // absent du disque » que le code documentait déjà sans le traiter : la première
            // version du repli ne se déclenchait que sur un index VIDE, donc cette situation
            // restait exactement aussi silencieuse qu'avant le correctif — photo visible dans
            // l'ERP, rien envoyé, aucune erreur.
            //
            // Le repli disque est donc tenté ici aussi : les vraies photos peuvent très bien
            // être présentes sous un autre nom ou un autre chemin que ce que dit l'index.
            $this->log(
                'listProductImages - index ecm_files non vide (' . count($orderedImages)
                . ' ligne(s)) mais AUCUN fichier exploitable sur disque pour le produit '
                . ($product->ref ?? ('id=' . (int) $product->id))
                . ' — repli sur le scan disque. Index probablement obsolète : un réindexage '
                . 'Dolibarr est recommandé.',
                LOG_WARNING
            );
        }

        return $this->scanProductPhotoDirFallback($product);
    }

    /**
     * Journalise un écart entre ce que l'index a produit et ce que le disque contient
     * réellement, SANS modifier le résultat renvoyé.
     *
     * Relevé par la revue 3 couches (MEDIUM) : un index partiellement obsolète laisse le
     * produit avec moins d'images qu'il n'en existe sur le disque, et le faisait jusqu'ici en
     * silence. Ce silence est exactement ce qui a coûté une semaine de diagnostic au client à
     * l'origine de cette story.
     *
     * @param object $product      Produit Dolibarr
     * @param int    $indexedCount Nombre d'images effectivement renvoyées par le chemin indexé
     * @return void
     */
    private function warnIfDiskHasMoreFilesThanIndex($product, int $indexedCount): void
    {
        // AC5 (story multicompany-ecm-files-prefixe-entite-jamais-reconnu) : cet appel sert
        // UNIQUEMENT à COMPTER les fichiers disque pour comparaison — jamais un vrai repli (le
        // chemin indexé a déjà répondu, $indexedCount > 0 dans l'immense majorité des cas). Le
        // WARNING interne de scanProductPhotoDirFallback() (« SANS AUCUNE ligne llx_ecm_files »)
        // serait donc FAUX ici : l'index n'est PAS muet, il a juste moins de lignes que le
        // disque. Avant ce fix, ce message se déclenchait par erreur sur CHAQUE produit avec
        // photo d'une installation par ailleurs parfaitement saine, à chaque synchronisation.
        $onDisk = $this->scanProductPhotoDirFallback($product, false);
        $diskCount = count($onDisk);

        if ($diskCount <= $indexedCount) {
            return;
        }

        $this->log(
            'listProductImages - ecart index/disque pour le produit '
            . ($product->ref ?? ('id=' . (int) $product->id)) . ' : ' . $indexedCount
            . ' image(s) via l\'index ecm_files, mais ' . $diskCount
            . ' fichier(s) exploitable(s) sur le disque. Les images non indexees ne sont PAS '
            . 'envoyees (l\'index reste la source de verite pour l\'ordre d\'affichage). '
            . 'Un reindexage Dolibarr alignerait les deux.',
            LOG_WARNING
        );
    }

    /**
     * AC1 (story images-produit-non-indexees-jamais-synchronisees) : repli disque, appelé
     * UNIQUEMENT quand `llx_ecm_files` ne renvoie aucune ligne pour ce produit — symétrique
     * de ce que font déjà les catégories (`listCategoryImages()`, Story 57-7) : `Categorie::
     * add_photo()` n'indexe JAMAIS dans `ecm_files`, donc le disque y est la SEULE source ;
     * côté produit `ecm_files` reste la source de vérité PRIORITAIRE (elle porte l'ordre
     * d'affichage), ce repli ne sert que le cas où elle est muette (migration, restauration de
     * sauvegarde, dépôt de fichier hors interface Dolibarr — dossier support Xavier Hubier /
     * Europe Loisirs, 01/09/2026).
     *
     * Réutilise `resolveProductPhotoDirCandidates()` — AUCUNE seconde résolution de chemin :
     * mêmes répertoires candidats, dans le même ordre, que `getImageBinary()`/
     * `describeProductPhotosDir()`. S'arrête au PREMIER candidat qui contient au moins un
     * fichier exploitable (même logique de priorité que `describeProductPhotosDir()`).
     *
     * AC2 — ORDRE explicitement choisi : `llx_ecm_files` porte l'ordre d'affichage réel (colonne
     * `position`), qu'un scan disque ne peut PAS deviner — il n'existe aucune notion de position
     * pour un fichier qui n'a jamais été indexé. Le tri retenu est donc **alphabétique par nom de
     * fichier** (`strcmp`), STRICTEMENT le même choix déterministe que
     * `listCategoryImages()` (Story 57-7) : reproductible, testable, indépendant de l'ordre de
     * `readdir()` (dépendant du filesystem/OS, jamais garanti). Un ordre non déterministe ferait
     * changer l'image "principale" d'un produit à chaque synchronisation — pire que le défaut
     * que cette story corrige.
     *
     * AC3 — visibilité : journalise en LOG_WARNING (référence produit + nombre de fichiers)
     * uniquement quand le disque révèle AU MOINS UN fichier alors que l'index n'en connaît
     * AUCUN — c'est précisément le cas silencieux qui a coûté au client de ne pas comprendre
     * seul son incident. Si le disque est également vide, aucune photo n'existe nulle part :
     * état légitime, reste silencieux comme avant cette story.
     *
     * Ne contourne PAS le filtre "fichier indexé mais absent du disque" documenté ailleurs
     * (`buildImagesFromOrderedMap()`) : symétriquement, un fichier listé ici DOIT exister sur
     * le disque puisqu'il est trouvé PAR un scan disque — aucune entrée fantôme possible.
     *
     * AC5 (story multicompany-ecm-files-prefixe-entite-jamais-reconnu) : `$logAsFallback`
     * distingue les DEUX appelants de cette méthode — `listProductImages()` (repli RÉEL, l'index
     * est vide ou inexploitable : le WARNING est vrai et utile) et
     * `warnIfDiskHasMoreFilesThanIndex()` (appel de COMPTAGE seul, sur un produit dont l'index a
     * déjà répondu : le même WARNING y serait FAUX — « SANS AUCUNE ligne llx_ecm_files » alors
     * que l'index en a justement au moins une). Avant ce paramètre, le second appelant
     * déclenchait ce message inconditionnellement sur CHAQUE produit avec photo, y compris sur
     * une installation mono-entité parfaitement saine — un avertissement faux par produit à
     * chaque synchronisation (AC5).
     *
     * @param object $product       Produit Dolibarr (au minimum : id, ref ; entity optionnel)
     * @param bool   $logAsFallback true si cet appel est un vrai repli (journalise le WARNING
     *                              « SANS AUCUNE ligne llx_ecm_files »), false si c'est un simple
     *                              comptage de comparaison (reste silencieux sur ce WARNING précis)
     * @return array<array{name:string, relativename:string, size:int, level1name:string, position:int}>
     * @since 2.5.3
     */
    private function scanProductPhotoDirFallback($product, bool $logAsFallback = true): array
    {
        // ⚠️ Revue 3 couches (MEDIUM) : la référence doit être SANITISÉE ici, exactement comme
        // le fait `resolveProductPhotoDirCandidates()` (`dol_sanitizeFileName()`) pour construire
        // le répertoire réellement scanné. Sans cela, une référence contenant un accent ou un
        // caractère interdit produisait un `level1name` divergent du chemin disque : le
        // répertoire lu était « Ref Speciale » mais la clé renvoyée « Réf Spéciale ». Sur le
        // chemin nominal c'était sans effet (`basename()` jette le préfixe), mais
        // `ImportProducts::getImageContentFromDisk()` concatène ce `level1name` LITTÉRALEMENT :
        // ce second repli aurait cherché le fichier dans un répertoire inexistant. Le docblock
        // de cette méthode revendiquait « aucune divergence possible » — il fallait le tenir.
        $dolibarrProductRef = dol_sanitizeFileName((string) ($product->ref ?? ''));
        if (empty($dolibarrProductRef)) {
            return [];
        }

        $resolved = $this->resolveProductPhotoDirCandidates($product);

        foreach ($resolved['dirs'] as $dirCandidate) {
            if (!is_dir($dirCandidate) || !is_readable($dirCandidate) || !is_executable($dirCandidate)) {
                continue;
            }

            $entries = @scandir($dirCandidate);
            if ($entries === false) {
                continue;
            }

            $filesFound = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $fullPath = $dirCandidate . $entry;
                // is_file() exclut déjà 'thumbs/' (sous-répertoire) — même garde que
                // listCategoryImages(), pas besoin d'exclusion nommée dédiée ici.
                if (!is_file($fullPath)) {
                    continue;
                }

                if (!preg_match('/(\.jpeg|\.jpg|\.gif|\.png|\.webp)$/i', $entry)) {
                    continue;
                }

                $size = @filesize($fullPath);
                if ($size === false || $size <= 0) {
                    // Un upload Shopify avec fileSize=0 serait corrompu — même règle que le
                    // chemin indexé (buildImagesFromOrderedMap()) et que le volet catégorie.
                    continue;
                }

                $filesFound[] = ['filename' => $entry, 'size' => (int) $size];
            }

            if (empty($filesFound)) {
                // Ce candidat existe mais est vide : essayer le candidat suivant plutôt que de
                // conclure trop tôt (même logique de priorité que describeProductPhotosDir()).
                continue;
            }

            // AC2 : ordre déterministe explicite — alphabétique par nom de fichier.
            usort($filesFound, function ($a, $b) {
                return strcmp($a['filename'], $b['filename']);
            });

            // AC3 : le cas qui a coûté au client de ne pas comprendre seul son incident —
            // rendu visible, avec référence produit et nombre de fichiers.
            // AC5 : uniquement quand cet appel est un vrai repli — un appel de COMPTAGE
            // (warnIfDiskHasMoreFilesThanIndex()) ne doit jamais affirmer que l'index est muet.
            if ($logAsFallback) {
                $this->log(
                    "scanProductPhotoDirFallback - " . count($filesFound)
                    . " fichier(s) trouvé(s) sur le disque pour le produit " . $dolibarrProductRef
                    . " SANS AUCUNE ligne llx_ecm_files correspondante (dir: " . $dirCandidate . ")"
                    . " - photos desynchronisees base/disque, jamais synchronisees sans ce repli",
                    LOG_WARNING
                );
            }

            $images = [];
            $position = 0;
            foreach ($filesFound as $fileData) {
                $relativeKey = $dolibarrProductRef . '/' . $fileData['filename'];
                $images[] = [
                    'name'         => $relativeKey,
                    'relativename' => $relativeKey,
                    'size'         => $fileData['size'],
                    'level1name'   => $dolibarrProductRef,
                    'position'     => $position++,
                ];
            }

            return $images;
        }

        return [];
    }

    /**
     * Nombre de lignes indexées dans `llx_ecm_files` pour un produit, INDÉPENDAMMENT de leur
     * présence réelle sur disque.
     *
     * Story 57-4 (AC0/AC2) : le diagnostic photos doit distinguer « aucune image indexée » de
     * « image indexée mais fichier absent du disque » (désynchronisation base/disque, cause
     * distincte à part entière). `listProductImages()` seul ne permet pas cette distinction :
     * `buildImagesFromOrderedMap()` exclut déjà les lignes dont la taille disque résolue est
     * 0 (@see resolveFilesizeFromDisk()), donc son compte ne reflète QUE ce qui est lisible.
     * Réutilise `fetchOrderedImagesFromDb()` (même requête, même filtre entity-aware) — aucune
     * seconde requête, aucune divergence possible avec `listProductImages()`.
     *
     * @param object $product Produit Dolibarr (au minimum : id, ref ; entity optionnel)
     * @return int Nombre de lignes `llx_ecm_files` matchant ce produit (avant tout filtre disque)
     * @since 2.5.0
     */
    public function countIndexedProductImages($product): int
    {
        return count($this->fetchOrderedImagesFromDb($product));
    }

    /**
     * Introspection du répertoire de photos d'un produit — faits bruts pour le diagnostic
     * (Story 57-4, AC0), SANS aucune logique de verdict (cause métier calculée ailleurs, cf.
     * `doli2shopResolveProductPhotoDiagnosticCause()` dans `lib/doli2shop.lib.php`).
     *
     * Réutilise `resolveProductPhotoDirCandidates()` — le MÊME calcul que celui utilisé par
     * `resolveCandidateFilePaths()` (donc par `getImageBinary()`/`resolveFilesizeFromDisk()`) :
     * un diagnostic qui refabriquerait ce chemin de son côté finirait par tester un répertoire
     * différent de celui réellement emprunté par le module (piège identifié par la validation
     * NO-GO du 2026-08-08, cf. Change Log de la story).
     *
     * FIX-CRITICAL (code review 57-4) : la version initiale n'inspectait QUE `dirs[0]` (le
     * premier candidat), alors que la lecture réelle (`resolveCandidateFilePaths()`, donc
     * `getImageBinary()`/`listProductImages()`) essaie successivement TOUS les candidats
     * (`dirs[0]`, `dirs[1]` selon `PRODUCT_USE_OLD_PATH_FOR_PHOTO`, PUIS les fallbacks
     * `DOL_DATA_ROOT` — désormais inclus dans `dirs`, cf. `resolveProductPhotoDirCandidates()`).
     * Un produit dont l'image n'est lisible QUE via un candidat de repli obtenait donc un faux
     * verdict `dir_not_found` en rouge alors que `getImageBinary()` réussissait — exactement le
     * défaut que cette story existe pour supprimer, reproduit à l'envers. Cette méthode itère
     * désormais sur TOUS les candidats et ne rapporte `not_found`/`not_readable` que si AUCUN
     * n'est exploitable ; sinon elle retourne le premier candidat réellement accessible.
     *
     * Priorité quand aucun candidat n'est pleinement accessible (lecture+exécution, cf.
     * FIX-CRITICAL-B ci-dessous) : (1) `resolved['reason']` si le calcul du répertoire de base
     * (`multidir_output`) a lui-même échoué — cause racine actionnable (config), même si des
     * fallbacks ont aussi été essayés en vain ; (2) `not_readable` si au moins un candidat
     * existe mais n'est pas accessible (permissions) ; (3) `not_found` sinon.
     *
     * @param object $product Produit Dolibarr (au minimum : id, ref ; entity optionnel)
     * @return array{path:?string, exists:bool, readable:bool, reason:?string} `reason` non-null
     *         uniquement quand `path` est null (répertoire non résolu) ou quand `exists`/`readable`
     *         est false — sinon null (résolution + accès OK, rien à signaler)
     * @since 2.5.0
     */
    public function describeProductPhotosDir($product): array
    {
        $resolved = $this->resolveProductPhotoDirCandidates($product);
        $candidates = $resolved['dirs'];

        $firstExistingButUnreadable = null;

        foreach ($candidates as $dirCandidate) {
            if (!is_dir($dirCandidate)) {
                continue;
            }

            // FIX-CRITICAL-B (code review 57-4) : is_readable() seul ne détecte PAS le cas
            // mutualisé « répertoire lisible mais non exécutable » (0400) — ouvrir un fichier À
            // L'INTÉRIEUR d'un répertoire exige le bit exécution, pas seulement le bit lecture.
            if (is_readable($dirCandidate) && is_executable($dirCandidate)) {
                return [
                    'path' => $dirCandidate,
                    'exists' => true,
                    'readable' => true,
                    'reason' => null,
                ];
            }

            if ($firstExistingButUnreadable === null) {
                $firstExistingButUnreadable = $dirCandidate;
            }
        }

        if ($resolved['reason'] !== null) {
            return [
                'path' => null,
                'exists' => false,
                'readable' => false,
                'reason' => $resolved['reason'],
            ];
        }

        if ($firstExistingButUnreadable !== null) {
            return [
                'path' => $firstExistingButUnreadable,
                'exists' => true,
                'readable' => false,
                'reason' => 'not_readable',
            ];
        }

        return [
            'path' => $candidates[0] ?? null,
            'exists' => false,
            'readable' => false,
            'reason' => 'not_found',
        ];
    }

    /**
     * Requête `llx_ecm_files` pour le produit donné : construit la map ordonnée
     * [relativename => [position, filepath, filesize, rowid, filename, level1name]].
     *
     * Extrait/généralisé de ImportProducts::getDolibarrImages()
     * (class/importproducts.class.php:4186-4253) — sans dépendance au cache d'instance
     * ni à l'appel API REST. Ajoute l'exclusion des vignettes `%/thumbs/%`, absente de
     * la version historique (aujourd'hui seul le diagnostic l'exclut, cf. admin/diagnostic.php:1549).
     *
     * @param object $product Produit Dolibarr
     * @return array Map [relativename => image data]
     */
    private function fetchOrderedImagesFromDb($product): array
    {
        $dolibarrProductRef = (string) $product->ref;

        // FIX-LOW (code review 57-2) : ref vide -> le pattern 'produit/%' construit plus bas
        // sur-matcherait TOUS les produits (aucun segment de ref pour restreindre le LIKE).
        // Retour anticipé, avant toute requête SQL.
        if (empty($dolibarrProductRef)) {
            $this->log(
                "fetchOrderedImagesFromDb - empty product ref (id "
                . (isset($product->id) ? (int) $product->id : '?')
                . "), refusing to list images (would over-match 'produit/%' on every product)",
                LOG_DEBUG
            );
            return [];
        }

        // FIX-MEDIUM-2 (code review 57-2) : filtre désormais par l'entité DU PRODUIT — résolue
        // exactement comme resolveCandidateFilePaths()/getImageBinary() (produit->entity en
        // priorité, sinon l'entité du resolver) — PAS par l'entité contextuelle $conf->entity
        // via getEntity('product'). Avant ce fix, un produit d'une autre entité que
        // $conf->entity (multi-company transverse) était introuvable via listProductImages()
        // alors que getImageBinary() résolvait bien son fichier sur disque : asymétrie
        // liste/lecture. Entier validé par cast (int), jamais d'injection SQL possible.
        $entityForFilter = !empty($product->entity) ? (int) $product->entity : (int) $this->entity;

        // Task 1 (AC1) : requête entity-aware, exclusion thumbs, extensions image uniquement.
        // FIX-CRITICAL-1 (code review) : `ef.filesize` N'EXISTE PAS dans `llx_ecm_files`
        // (vérifié DESCRIBE sur la base réelle — seules filepath/filename/position existent
        // parmi les colonnes fichier). La sélectionner faisait échouer la requête entière
        // (colonne inconnue) -> [] pour TOUT produit, sans qu'aucun fallback API n'existe ici
        // (contrairement à ImportProducts::getDolibarrImages(), qui a le fallback HTTP->SQL
        // pour se rattraper). La taille est désormais TOUJOURS résolue depuis le disque via
        // resolveFilesizeFromDisk() — c'est la seule source de vérité, pas un simple fallback.
        //
        // FIX (défaut prod 2026-08-13, retour DEVIDOIR_6) : l'ancien pattern
        // 'produit/' . $ref . '%' (SANS séparateur après le ref) sur-matchait tout répertoire
        // dont le NOM commence par le ref, notamment les répertoires de VARIANTES qui
        // préfixent le ref du parent (ex. produit/DEVIDOIR_6_10100_BLANC_JADE pour le parent
        // DEVIDOIR_6). Ces lignes ramenées à tort étaient ensuite systématiquement écartées
        // par buildImagesFromOrderedMap() (filesize=0, le fichier n'existe pas dans le
        // répertoire DU PARENT) — résultat fonctionnel correct par ÉLIMINATION, mais au prix
        // d'un WARNING par fichier de variante ramené à tort (30/sync sur ce cas réel) et
        // d'accès disque inutiles. Le filtre exige désormais un séparateur '/' (ou une égalité
        // stricte) après le ref : seul le répertoire DU PRODUIT ('produit/{ref}') et ses
        // éventuels sous-répertoires ('produit/{ref}/...') matchent — plus jamais un
        // répertoire frère 'produit/{ref}SUFFIXE'. Aucune régression sur le chemin historique
        // PRODUCT_USE_OLD_PATH_FOR_PHOTO ('produit/{split}/{id}/photos', SANS le ref) : ce
        // chemin ne contient déjà PAS le ref et ne matchait donc ni l'ancien ni le nouveau
        // pattern — cette requête SQL n'a jamais été le canal qui l'exposait (@see
        // resolveFilesizeFromDisk()/resolveCandidateFilePaths(), qui restent le SEUL canal
        // capable de le résoudre, via `ef.filepath` désormais utilisé comme source de vérité).
        //
        // AC1(a) (story multicompany-ecm-files-prefixe-entite-jamais-reconnu) : en Multicompany,
        // avec entity > 1, `conf.class.php:737-742` fait pointer `multidir_output` sur
        // `DOL_DATA_ROOT/<entity>` — `addFileIntoDatabaseIndex()` (files.lib.php:2292) indexe
        // alors `ef.filepath` sous la forme `{entity}/produit/{ref}`, jamais `produit/{ref}`. Le
        // filtre ci-dessus ne matchait donc plus JAMAIS aucune ligne pour un produit d'une entité
        // secondaire (0 ligne, toujours), alors que l'index en contenait bel et bien (4570 lignes
        // constatées chez Xavier Hubier / Europe Loisirs, entity=2). On étend le filtre avec la
        // forme préfixée UNIQUEMENT quand l'entité filtrée est > 1 (le cœur n'écrit jamais
        // `1/produit/...`, cf. conf.class.php:740 — inutile de tester ce cas) — SANS jamais
        // cesser d'accepter la forme mono-entité `produit/{ref}` (toujours incluse ci-dessous) :
        // un produit dont l'index a été écrit avant un changement d'entité, ou une installation
        // mono-entité, doivent continuer à être retrouvés par la seule forme non préfixée. Le
        // séparateur '/' (ou l'égalité stricte) après le ref reste exigé dans les DEUX formes —
        // aucune régression possible sur la protection anti-répertoire-frère (DEVIDOIR_6).
        $filepathConditions = '(ef.filepath = ? OR ef.filepath LIKE ?)';

        // Échappement des wildcards LIKE (%, _) sur le ref — \ en premier pour ne pas
        // re-échapper. SqlUtils::executeQuery gère l'échappement SQL classique via
        // $db->escape() (liaison via placeholder ?) ; on ne pré-échappe PAS avec
        // $db->escape() ici pour éviter le double-échappement.
        $likeSafeRef = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $dolibarrProductRef);
        $exactDirPattern = 'produit/' . $likeSafeRef;
        $subDirPattern = 'produit/' . $likeSafeRef . '/%';
        $bindParams = [$exactDirPattern, $subDirPattern];

        if ($entityForFilter > 1) {
            $filepathConditions = '(ef.filepath = ? OR ef.filepath LIKE ? OR ef.filepath = ? OR ef.filepath LIKE ?)';
            $bindParams[] = $entityForFilter . '/produit/' . $likeSafeRef;
            $bindParams[] = $entityForFilter . '/produit/' . $likeSafeRef . '/%';
        }

        $sql = "SELECT ef.rowid, ef.filename, ef.filepath, ef.position"
            . " FROM " . MAIN_DB_PREFIX . "ecm_files ef"
            . " WHERE " . $filepathConditions
            . " AND ef.filepath NOT LIKE '%/thumbs/%'"
            . " AND ef.entity = " . $entityForFilter
            . " AND (ef.filename LIKE '%.png'"
            . " OR ef.filename LIKE '%.jpg'"
            . " OR ef.filename LIKE '%.jpeg'"
            . " OR ef.filename LIKE '%.gif'"
            . " OR ef.filename LIKE '%.webp')"
            . " ORDER BY ef.position, ef.filename";

        $orderedImages = [];

        $result = SqlUtils::executeQuery(
            $this->db,
            $sql,
            "listing product images for " . $dolibarrProductRef,
            false,
            $bindParams
        );

        if (!$result) {
            $this->log(
                "fetchOrderedImagesFromDb - failed to retrieve images for " . $dolibarrProductRef,
                LOG_WARNING
            );
            return [];
        }

        while ($obj = $this->db->fetch_object($result)) {
            // filepath ex. "produit/shopvolant" -> level1name = "shopvolant" (segment SUIVANT
            // 'produit'). AC1(b) (story multicompany-ecm-files-prefixe-entite-jamais-reconnu,
            // retour du Validate) : un indice FIXE (`$pathSegments[1]`) suppose que 'produit' est
            // toujours le premier segment — faux en Multicompany, où le filepath réel est
            // "{entity}/produit/{ref}" (segments ['2','produit','{ref}']) : l'ancien code
            // renvoyait alors 'produit' (le littéral !) comme level1name au lieu du ref. Ce
            // level1name N'EST PAS décoratif : importproducts.class.php:4422 en tire
            // `$productRef = trim($image['level1name'], '/')`, utilisé pour l'appariement
            // produit/image en aval — un level1name corrompu aurait fait passer la requête SQL
            // (AC1a) tout en cassant ce consommateur. On cherche donc le segment 'produit'
            // lui-même, et on prend celui qui le SUIT, quel que soit sa position dans le chemin.
            $pathSegments = array_values(array_filter(explode('/', trim($obj->filepath, '/'))));
            $produitSegmentIndex = array_search('produit', $pathSegments, true);
            $level1name = ($produitSegmentIndex !== false && isset($pathSegments[$produitSegmentIndex + 1]))
                ? $pathSegments[$produitSegmentIndex + 1]
                : '';
            if (empty($level1name)) {
                // filepath malformé ("produit" seul ou vide) -> fallback sur $product->ref
                $level1name = $dolibarrProductRef;
                $this->log(
                    "fetchOrderedImagesFromDb - malformed filepath for " . $dolibarrProductRef
                    . " (filepath: '" . $obj->filepath . "'), falling back to ref as level1name",
                    LOG_WARNING
                );
            }
            $relativeKey = $level1name . '/' . $obj->filename;

            // FIX-MEDIUM (code review) : restaure le log de collision présent dans l'original
            // ImportProducts::getDolibarrImages() (Patch 7, importproducts.class.php:4230-4241),
            // absent de la version extraite/généralisée. La clé n'est PAS modifiée (casserait le
            // tri par relativename) : le log sert uniquement au diagnostic.
            if (isset($orderedImages[$relativeKey])) {
                $this->log(
                    "fetchOrderedImagesFromDb - collision relativeKey '" . $relativeKey
                    . "' pour produit " . $dolibarrProductRef
                    . " (rowid " . $obj->rowid . " écrase rowid " . $orderedImages[$relativeKey]['rowid'] . ")"
                    . " — filepath: '" . $obj->filepath . "'",
                    LOG_WARNING
                );
            }

            // FIX-CRITICAL-1 : `filesize` n'existe pas dans `llx_ecm_files` -> la résolution
            // disque est désormais le SEUL chemin (plus un simple fallback conditionnel).
            // FIX (défaut prod 2026-08-13) : `ef.filepath` (chemin RÉEL indexé au moment de
            // l'upload, @see addFileIntoDatabaseIndex()) est transmis comme source de vérité
            // — @see resolveFilesizeFromDisk().
            $filesize = $this->resolveFilesizeFromDisk($product, $obj->filename, $obj->filepath);

            $orderedImages[$relativeKey] = [
                'position'   => is_null($obj->position) ? PHP_INT_MAX : (int) $obj->position,
                'filepath'   => $obj->filepath,
                'filesize'   => $filesize,
                'rowid'      => (int) $obj->rowid,
                'filename'   => $obj->filename,
                'level1name' => $level1name,
            ];
        }
        $this->db->free($result);

        return $orderedImages;
    }

    /**
     * Taille sur disque d'un fichier produit — SEULE source de vérité pour `filesize`
     * depuis que la colonne `ef.filesize` a été retirée du SELECT (FIX-CRITICAL-1 : la
     * colonne n'existe pas dans `llx_ecm_files`).
     *
     * FIX-HIGH-2 (code review) : utilisait auparavant `$this->entity` (entité du resolver,
     * PAS forcément celle du produit) — cassait le multi-company dès qu'un produit d'une
     * autre entité était résolu. Résout le chemin via la MÊME logique que `getImageBinary()`
     * (mêmes candidats `PRODUCT_USE_OLD_PATH_FOR_PHOTO`, entité du produit) via
     * {@see resolveCandidateFilePaths()} — factorisation qui élimine toute divergence
     * possible entre les deux méthodes. Ces candidats RECALCULENT le chemin à partir du ref
     * et de l'entité COURANTS du produit ; ils restent utilisés en repli.
     *
     * FIX (défaut prod 2026-08-13) : `$filepath`, quand fourni (ligne `llx_ecm_files`,
     * @see fetchOrderedImagesFromDb()), est désormais essayé EN PREMIER — c'est le chemin
     * RELATIF À `DOL_DATA_ROOT` réellement indexé au moment de l'upload par
     * `addFileIntoDatabaseIndex()` (core/lib/files.lib.php), donc la seule source qui reste
     * correcte même si `PRODUCT_USE_OLD_PATH_FOR_PHOTO` a changé depuis l'upload, ou pour le
     * chemin historique `produit/{split}/{id}/photos` (qui ne contient pas le ref et que
     * {@see resolveCandidateFilePaths()} seul ne saurait reconstruire pour cet appelant SQL).
     * `$filepath` vide (appelant qui ne dispose pas d'une ligne `ecm_files`, ex. tests
     * directs) -> comportement inchangé, uniquement les candidats recalculés.
     *
     * @param object $product  Produit Dolibarr (id, ref, entity)
     * @param string $filename Nom du fichier
     * @param string $filepath Chemin `ef.filepath` de la ligne `llx_ecm_files` d'origine
     *                          (relatif à `DOL_DATA_ROOT`, SANS le nom de fichier), vide si
     *                          non disponible
     * @return int Taille en octets, 0 si illisible/absent sur tous les chemins candidats
     */
    private function resolveFilesizeFromDisk($product, string $filename, string $filepath = ''): int
    {
        foreach ($this->resolveAllCandidatePaths($product, $filename, $filepath) as $absolutePath) {
            $size = @filesize($absolutePath);
            if ($size !== false) {
                return (int) $size;
            }
        }

        return 0;
    }

    /**
     * Chemins absolus candidats, `ef.filepath` (source de vérité, @see resolveFilesizeFromDisk())
     * EN PREMIER quand fourni, puis les candidats recalculés de
     * {@see resolveCandidateFilePaths()} en repli — factorisé pour que
     * `resolveFilesizeFromDisk()` reste la SEULE méthode à connaître cet ordre de priorité.
     *
     * @param object $product  Produit Dolibarr (id, ref, entity)
     * @param string $filename Nom du fichier
     * @param string $filepath Chemin `ef.filepath` d'origine (relatif à `DOL_DATA_ROOT`), vide si absent
     * @return string[] Chemins absolus candidats, dans l'ordre à essayer
     */
    private function resolveAllCandidatePaths($product, string $filename, string $filepath): array
    {
        $candidatePaths = [];

        if ($filepath !== '' && defined('DOL_DATA_ROOT')) {
            $candidatePaths[] = rtrim(DOL_DATA_ROOT, '/') . '/' . trim($filepath, '/') . '/' . $filename;
        }

        foreach ($this->resolveCandidateFilePaths($product, $filename) as $absolutePath) {
            $candidatePaths[] = $absolutePath;
        }

        return $candidatePaths;
    }

    /**
     * Répertoires candidats (SANS nom de fichier) pour un produit donné, honorant
     * `PRODUCT_USE_OLD_PATH_FOR_PHOTO` selon le pattern canonique du core
     * (`core/modules/product/doc/pdf_standard.modules.php:244-250`), PLUS les fallbacks
     * `DOL_DATA_ROOT` hérités de `ImportProducts::getImageContentFromDisk()` (l.4958-4972).
     *
     * Story 57-4 (AC0) : extrait de `resolveCandidateFilePaths()` pour être réutilisable par
     * `describeProductPhotosDir()` SANS dupliquer la logique de résolution — un seul endroit
     * calcule les répertoires candidats, que l'appelant veuille ensuite y lire un fichier
     * (`resolveCandidateFilePaths()`/`getImageBinary()`) ou simplement introspecter leur
     * existence/lisibilité (`describeProductPhotosDir()`).
     *
     * FIX-CRITICAL (code review 57-4) : les fallbacks `DOL_DATA_ROOT` étaient auparavant ajoutés
     * UNIQUEMENT dans `resolveCandidateFilePaths()`, en aval de cette méthode — invisibles pour
     * `describeProductPhotosDir()`, qui ne testait donc que 2 candidats sur les 4 réellement
     * essayés par la lecture. Les deux listes divergeaient : un diagnostic qui ne verrait pas ce
     * qu'essaie réellement le module. Ajoutés ici, en fin de liste (essayés en dernier, comme
     * avant), qu'importe que `$baseDir` ait résolu ou non : la lecture réelle les tente aussi
     * dans ce dernier cas (cf. Change Log de la story).
     *
     * Garde `error-` (FIX-HIGH-3 de 57-1, jusqu'ici appliquée UNIQUEMENT au volet catégorie,
     * cf. `resolveCategoryPhotosDir()`) : `getMultidirOutput()` peut renvoyer la chaîne NON VIDE
     * `'error-diroutput-not-defined-for-this-object=...'` quand le module ne peut être résolu —
     * une simple garde `!empty() && is_string()` la laisserait passer et produirait un chemin
     * silencieusement faux. Ajoutée ici pour que `describeProductPhotosDir()` distingue
     * correctement « répertoire non résolu » (AC2) plutôt que de rapporter un faux répertoire.
     *
     * @param object $product Produit Dolibarr (id, ref, entity)
     * @return array{dirs: string[], reason: ?string} `dirs` = répertoires candidats (slash final),
     *         dans l'ordre à essayer (fallbacks `DOL_DATA_ROOT` en dernier) ; `reason` non-null
     *         UNIQUEMENT si le répertoire de base `multidir_output` n'a pas pu être résolu (vide,
     *         non-string, ou préfixé `error-`) — `dirs` peut rester non vide dans ce cas (les
     *         fallbacks restent essayés)
     * @since 2.5.0
     */
    private function resolveProductPhotoDirCandidates($product): array
    {
        $dolibarrProductRef = dol_sanitizeFileName((string) ($product->ref ?? ''));
        $dirCandidates = [];
        $reason = null;

        // Passer 'product' EXPLICITEMENT (2e arg, FIX-HIGH-3) : un produit stdClass minimal
        // (tests, ou tout appelant léger) n'a pas forcément de propriété ->element ; le vrai
        // getMultidirOutput() sans module explicite retomberait dessus et pourrait produire
        // une chaîne d'erreur non vide qui passerait la garde is_string() ci-dessous.
        //
        // FIX (Story 57-6, AC2) : carrier d'entité minimal, sur le modèle exact du volet
        // catégorie (@see resolveCategoryPhotosDirDetailed(), lignes 650-655). Sans lui,
        // getMultidirOutput() reçoit $product directement et, si ->entity est vide, retombe
        // sur $conf->entity global (comportement natif du core, reproduit fidèlement par le
        // stub test/bootstrap.php:354) au lieu de l'entité DU RESOLVER — asymétrie avec
        // fetchOrderedImagesFromDb() (l.244, volet SQL) qui, lui, retombe déjà sur
        // $this->entity. Le comportement quand $product->entity est renseigné (100% des cas
        // réels, Product::fetch() le remplit toujours) est strictement inchangé : seul le
        // repli sur entité vide change de cible.
        $entityForDir = !empty($product->entity) ? (int) $product->entity : (int) $this->entity;
        $entityCarrier = new \stdClass();
        $entityCarrier->entity = $entityForDir;
        $baseDir = getMultidirOutput($entityCarrier, 'product', 0);

        // ⚠️ Revue 3 couches (HIGH, Edge Case Hunter) : `getMultidirOutput()` ne sait résoudre
        // QUE l'entité contextuelle. Le cœur ne peuple qu'une seule clé dans `multidir_output`
        // (`conf.class.php:744` : `array($this->entity => …)`), et `functions.lib.php:187` lit
        // `multidir_output[$object->entity]` — une entité produit différente de `$conf->entity`
        // donne donc une clé absente, donc une chaîne VIDE (pas un `error-…`, contrairement au
        // stub de test). Le code retombait alors sur les chemins de secours `DOL_DATA_ROOT/produit`
        // SANS segment d'entité, qui ne désignent pas le répertoire réel sous Multicompany.
        //
        // Ce défaut est antérieur à la story, mais elle l'expose pour la première fois : avant la
        // correction du filtre, aucune ligne d'entité secondaire n'était jamais sélectionnée, donc
        // ce chemin n'était jamais emprunté. On reconstruit ici le répertoire comme le cœur le
        // ferait (`DOL_DATA_ROOT/{entity}/produit` au-delà de l'entité 1), pour tout appelant qui
        // n'a pas basculé `$conf->entity` — écran de diagnostic, tâche transverse.
        if (
            (empty($baseDir) || !is_string($baseDir) || strpos($baseDir, 'error-') === 0)
            && $entityForDir > 1
            && defined('DOL_DATA_ROOT')
        ) {
            $rebuiltBaseDir = DOL_DATA_ROOT . '/' . $entityForDir . '/produit';
            $this->log(
                'resolveProductPhotoDirCandidates - multidir_output non résolu pour l\'entité '
                . $entityForDir . ' (entité contextuelle differente) : repli sur le chemin '
                . 'reconstruit ' . $rebuiltBaseDir,
                LOG_DEBUG
            );
            $baseDir = $rebuiltBaseDir;
        }

        if (!empty($baseDir) && is_string($baseDir) && strpos($baseDir, 'error-') !== 0) {
            $baseDir = rtrim($baseDir, '/');

            // 2 chemins candidats — pattern canonique pdf_standard.modules.php:244-250.
            // FIX-CRITICAL-2 : le chemin legacy réel N'A PAS de segment `/{ref}/` après
            // `/{id}/photos/` (contrairement à l'implémentation précédente) — vérifié
            // ligne à ligne contre le core (rejoué à la main pour id=837 -> "7/3/837/photos/"
            // et id=42 -> "2/4/42/photos/", sans aucun sous-dossier ref).
            if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
                $middleDirCandidates = [
                    get_exdir($product->id, 2, 0, 0, $product, 'product') . $product->id . '/photos/',
                    get_exdir(0, 0, 0, 0, $product, 'product') . $dolibarrProductRef . '/',
                ];
            } else {
                $middleDirCandidates = [
                    get_exdir(0, 0, 0, 0, $product, 'product'),
                    get_exdir($product->id, 2, 0, 0, $product, 'product') . $product->id . '/photos/',
                ];
            }

            foreach ($middleDirCandidates as $middleDir) {
                $dirCandidates[] = $baseDir . '/' . $middleDir;
            }
        } else {
            $reason = 'unresolved:' . (is_string($baseDir) ? $baseDir : gettype($baseDir));
            $this->log(
                "resolveProductPhotoDirCandidates - unable to resolve multidir_output base dir for product "
                . ($product->ref ?? '?') . " (" . $reason . "), trying DOL_DATA_ROOT fallbacks only",
                LOG_DEBUG
            );
        }

        // FIX-CRITICAL (code review 57-4) : chemins de secours hérités de
        // getImageContentFromDisk() (l.4958-4972), désormais candidats à part entière de CETTE
        // liste (et non plus ajoutés seulement en aval, dans resolveCandidateFilePaths()) — pour
        // qu'une seule construction de chemin serve à la fois la lecture ET l'introspection.
        // Ajoutés inconditionnellement (même si $baseDir a résolu) : c'est déjà le comportement
        // réel de la lecture, qui les essaie toujours en dernier recours.
        if (defined('DOL_DATA_ROOT')) {
            // Revue 3 couches (LOW, Acceptance Auditor) : ces secours ignoraient eux aussi le
            // segment d'entité. Sous Multicompany au-delà de l'entité 1, ils désignaient un
            // répertoire qui n'existe pas — un secours qui ne secourt jamais. Les variantes
            // préfixées passent en PREMIER (chemin réel), les formes historiques restent
            // ensuite, inchangées, pour l'entité 1 et les installations sans Multicompany.
            if ($entityForDir > 1) {
                $dirCandidates[] = DOL_DATA_ROOT . '/' . $entityForDir . '/produit/' . $dolibarrProductRef . '/';
                $dirCandidates[] = DOL_DATA_ROOT . '/' . $entityForDir . '/product/' . $dolibarrProductRef . '/';
            }
            $dirCandidates[] = DOL_DATA_ROOT . '/produit/' . $dolibarrProductRef . '/';
            $dirCandidates[] = DOL_DATA_ROOT . '/product/' . $dolibarrProductRef . '/';
        }

        return ['dirs' => $dirCandidates, 'reason' => $reason];
    }

    /**
     * Chemins absolus candidats pour un fichier produit donné — répertoires candidats
     * (@see resolveProductPhotoDirCandidates()) + nom de fichier. Les fallbacks `DOL_DATA_ROOT`
     * hérités de `ImportProducts::getImageContentFromDisk()` (l.4958-4972) font désormais partie
     * de `resolveProductPhotoDirCandidates()['dirs']` (FIX-CRITICAL, code review 57-4) : une
     * SEULE liste de candidats sert à la fois cette méthode et `describeProductPhotosDir()`,
     * pour qu'aucune divergence ne soit possible entre lecture et introspection (AC0).
     *
     * Méthode PARTAGÉE entre `getImageBinary()` et `resolveFilesizeFromDisk()` (FIX-HIGH-2) :
     * toute correction de résolution de chemin ne se fait qu'à un seul endroit.
     *
     * @param object $product  Produit Dolibarr (id, ref, entity)
     * @param string $filename Nom du fichier
     * @return string[] Chemins absolus candidats, dans l'ordre à essayer
     */
    private function resolveCandidateFilePaths($product, string $filename): array
    {
        $candidatePaths = [];

        foreach ($this->resolveProductPhotoDirCandidates($product)['dirs'] as $dirCandidate) {
            $candidatePaths[] = $dirCandidate . $filename;
        }

        return $candidatePaths;
    }

    /**
     * Construit le tableau final trié depuis la map SQL, en excluant les images de taille 0.
     *
     * Extrait/généralisé de ImportProducts::buildImagesFromOrderedMap()
     * (class/importproducts.class.php:4542-4594) — SANS le paramètre `$reason` ni le bloc
     * de cache d'instance (`$this->imagesCache`) : le resolver est sans état.
     *
     * @param array  $orderedImages Map [relativename => [position, filepath, filesize, rowid, filename, level1name]]
     * @param object $product       Produit Dolibarr (pour log)
     * @return array Tableau d'objets image trié par position puis relativename
     */
    private function buildImagesFromOrderedMap(array $orderedImages, $product): array
    {
        $images = [];
        foreach ($orderedImages as $relativeKey => $imgData) {
            // Skip images avec filesize<=0 : un upload Shopify avec fileSize=0 serait corrompu.
            if (empty($imgData['filesize']) || $imgData['filesize'] <= 0) {
                $this->log(
                    "buildImagesFromOrderedMap - skipping image with size=0 for product " . $product->ref
                    . " (file: " . $imgData['filename']
                    . ", path: " . $imgData['filepath'] . "/" . $imgData['filename'] . ")",
                    LOG_WARNING
                );
                continue;
            }

            $images[] = [
                'name'         => $relativeKey,
                'relativename' => $relativeKey,
                'size'         => $imgData['filesize'],
                'level1name'   => $imgData['level1name'],
                'position'     => $imgData['position'],
            ];
        }

        usort($images, function ($a, $b) {
            if ($a['position'] !== $b['position']) {
                return $a['position'] - $b['position'];
            }
            return strcmp($a['relativename'], $b['relativename']);
        });

        $this->log(
            "buildImagesFromOrderedMap - constructed " . count($images) . " image(s) for product " . $product->ref,
            LOG_DEBUG
        );

        return $images;
    }

    /**
     * Contenu binaire d'une image produit depuis le disque.
     *
     * Suit le pattern canonique du core Dolibarr
     * (core/modules/product/doc/pdf_standard.modules.php:244-250) pour honorer
     * `PRODUCT_USE_OLD_PATH_FOR_PHOTO` : 2 chemins candidats construits via
     * `getMultidirOutput($entityCarrier, 'product', 0)` — module 'product' EXPLICITE, SANS
     * forobject (FIX-HIGH-3), et depuis la story 57-6 un **porteur d'entité minimal** plutôt que
     * `$product` lui-même, pour que le repli se fasse sur l'entité du resolver et non sur
     * `$conf->entity` global quand `$product->entity` est vide (symétrie avec le volet catégorie).
     * Plus `get_exdir()` explicite, qui reçoit lui le `$product` réel — legacy = `.../{split}/{id}/photos/
     * {filename}` (SANS segment `/{ref}/`, FIX-CRITICAL-2), moderne = `.../{ref}/{filename}`.
     * Les deux sont essayés, dans l'ordre déterminé par la constante (le chemin attendu
     * actif est essayé en premier), via {@see resolveCandidateFilePaths()} — partagée avec
     * `resolveFilesizeFromDisk()` (FIX-HIGH-2). Conserve en dernier recours les chemins de
     * secours de ImportProducts::getImageContentFromDisk() (class/importproducts.class.php:4899-5009).
     *
     * Format IDENTIQUE à ImportProducts::getImageContentFromDisk()/getImageContent().
     *
     * @param object $product  Produit Dolibarr (id, ref, entity)
     * @param string $filename Nom du fichier (sans chemin)
     * @return array{content:string, mime_type:string, size:int, filename:string}|null Null si absente/illisible (jamais false)
     */
    public function getImageBinary($product, string $filename): ?array
    {
        if (empty($filename)) {
            $this->log("getImageBinary - empty filename provided", LOG_ERR);
            return null;
        }

        // FIX-HIGH-2/FIX-HIGH-3 (code review) : résolution de chemin factorisée avec
        // resolveFilesizeFromDisk() dans resolveCandidateFilePaths() pour éviter toute
        // divergence entre lecture binaire et calcul de taille.
        $candidatePaths = $this->resolveCandidateFilePaths($product, $filename);

        foreach ($candidatePaths as $filepath) {
            if (!is_file($filepath) || !is_readable($filepath)) {
                continue;
            }

            $content = @file_get_contents($filepath);
            if ($content === false) {
                $this->log("getImageBinary - failed to read file content: " . $filepath, LOG_ERR);
                continue;
            }

            return [
                'content'   => $content,
                'mime_type' => dol_mimetype($filename),
                'size'      => strlen($content),
                'filename'  => $filename,
            ];
        }

        $this->log(
            "getImageBinary - file not found/readable on any candidate path for " . $filename
            . " (product " . ($product->ref ?? '?') . ")",
            LOG_WARNING
        );

        return null;
    }

    /**
     * Répertoire photos d'une catégorie, résolu EXACTEMENT comme le core
     * (`Categorie::add_photo()`/`isAnyPhotoAvailable()`, `categories/class/categorie.class.php:1845,2003`) :
     * `$conf->categorie->multidir_output[$entity] . '/' . get_exdir($id, 2, 0, 0, $category, 'category') . $id . '/photos/'`.
     *
     * Story 57-7 — ASYMÉTRIE ASSUMÉE avec le volet produit : `llx_ecm_files` n'est PAS la
     * source de vérité pour les catégories. `Categorie::add_photo()` n'appelle JAMAIS
     * `addFileIntoDatabaseIndex()` (vérifié : 0 résultat sur `grep addFileIntoDatabaseIndex
     * categories/`), contrairement à Product. Le DISQUE est donc la SEULE source — ne pas
     * "corriger" vers ecm_files par symétrie avec les produits, ce serait casser les
     * catégories (le LEFT JOIN historique sur ecm_files ne ramenait déjà rien en pratique).
     *
     * ⚠️ Piège de nommage (asymétrie vicieuse du core, vérifiée ligne à ligne) :
     * - `getMultidirOutput()` reçoit le module **`categorie`** (nom de `$conf->categorie`) ;
     * - `get_exdir()` reçoit le modulepart **`category`** (clé de `$arrayforoldpath` dans
     *   `functions.lib.php`, qui impose le découpage sur 2 niveaux).
     * Passer `'category'` à `getMultidirOutput()` ne lève PAS d'exception : elle renvoie la
     * chaîne NON VIDE `'error-diroutput-not-defined-for-this-object=category'`
     * (`functions.lib.php:206`), qui passerait une garde `!empty() && is_string()` et
     * produirait un chemin silencieusement faux (même piège que FIX-HIGH-3, 57-1) — d'où la
     * garde explicite sur le préfixe `error-` ci-dessous.
     *
     * Entité : `$category->entity` en PRIORITÉ, `$this->entity` (entité du resolver) en
     * REPLI — JAMAIS l'entité contextuelle globale `$conf->entity`, à laquelle
     * `getMultidirOutput()` se replierait nativement si on lui passait `$category` tel quel
     * avec `->entity` vide. Un "carrier" minimal (seule la propriété `entity` est lue par
     * `getMultidirOutput()` en mode 'output'/`$forobject=0`) force ce choix explicitement.
     *
     * @param object $category Catégorie Dolibarr (au minimum : id ; entity optionnel)
     * @return string|null Répertoire absolu (avec slash final), ou null si non résolvable
     */
    private function resolveCategoryPhotosDir($category): ?string
    {
        return $this->resolveCategoryPhotosDirDetailed($category)['path'];
    }

    /**
     * Variante « détaillée » de {@see resolveCategoryPhotosDir()} qui porte AUSSI la raison
     * d'un échec de résolution — Story 57-4 (AC0). Contient l'INTÉGRALITÉ de la logique
     * (y compris les logs) ; `resolveCategoryPhotosDir()` en est un simple alias `['path']`
     * pour ne rien changer au comportement des appelants existants
     * (`listCategoryImages()`/`getCategoryImageBinary()`). Une seule implémentation, réutilisée
     * aussi par `describeCategoryPhotosDir()` — même exigence de non-divergence que le volet
     * produit (@see resolveProductPhotoDirCandidates()).
     *
     * @param object $category Catégorie Dolibarr (au minimum : id ; entity optionnel)
     * @return array{path: ?string, reason: ?string} `reason` non-null UNIQUEMENT si `path` est null
     * @since 2.5.0
     */
    private function resolveCategoryPhotosDirDetailed($category): array
    {
        if (empty($category->id)) {
            $this->log("resolveCategoryPhotosDir - category has no id, cannot resolve photos directory", LOG_WARNING);
            return ['path' => null, 'reason' => 'no_category_id'];
        }

        $entityForDir = !empty($category->entity) ? (int) $category->entity : (int) $this->entity;
        $entityCarrier = new \stdClass();
        $entityCarrier->entity = $entityForDir;

        // Module EXPLICITE 'categorie' (nom de $conf->categorie) — voir piège documenté ci-dessus.
        $baseDir = getMultidirOutput($entityCarrier, 'categorie', 0);

        if (empty($baseDir) || !is_string($baseDir) || strpos($baseDir, 'error-') === 0) {
            $this->log(
                "resolveCategoryPhotosDir - getMultidirOutput() returned invalid base dir for category id "
                . (int) $category->id . " (entity " . $entityForDir . "): "
                . (is_string($baseDir) ? $baseDir : gettype($baseDir)),
                LOG_WARNING
            );
            return [
                'path' => null,
                'reason' => 'unresolved:' . (is_string($baseDir) ? $baseDir : gettype($baseDir)),
            ];
        }

        $baseDir = rtrim($baseDir, '/');

        // Modulepart EXPLICITE 'category' (clé $arrayforoldpath, découpage sur 2 niveaux) —
        // volontairement DIFFÉRENT du module 'categorie' ci-dessus (piège documenté).
        $exdir = get_exdir((int) $category->id, 2, 0, 0, $category, 'category');

        return ['path' => $baseDir . '/' . $exdir . $category->id . '/photos/', 'reason' => null];
    }

    /**
     * Introspection du répertoire de photos d'une catégorie — pendant catégorie de
     * {@see describeProductPhotosDir()} (Story 57-4, AC0). Réutilise
     * `resolveCategoryPhotosDirDetailed()` — le MÊME calcul que celui utilisé par
     * `listCategoryImages()`/`getCategoryImageBinary()`, aucune divergence possible.
     *
     * Contrairement au volet produit, il n'y a ici qu'UN SEUL candidat (pas de fallback
     * `DOL_DATA_ROOT` côté catégorie — vérifié : `listCategoryImages()`/`getCategoryImageBinary()`
     * n'en essaient aucun), donc pas de boucle sur plusieurs répertoires : CRITICAL-A (code
     * review 57-4) ne s'applique pas à ce volet.
     *
     * FIX-CRITICAL-B (code review 57-4) : `is_readable()` seul ne détecte PAS le cas mutualisé
     * « répertoire lisible mais non exécutable » (0400) — ouvrir un fichier À L'INTÉRIEUR d'un
     * répertoire exige le bit exécution, pas seulement le bit lecture. Sans ce fix, un tel
     * répertoire était déclaré lisible alors que toute lecture échoue, et le diagnostic
     * catégorie rapportait `no_images` en simple avertissement (« pas de photo, cas normal »)
     * à la place d'un vrai problème de droits — la cause réelle devenait invisible.
     *
     * @param object $category Catégorie Dolibarr (au minimum : id ; entity optionnel)
     * @return array{path:?string, exists:bool, readable:bool, reason:?string}
     * @since 2.5.0
     */
    public function describeCategoryPhotosDir($category): array
    {
        $resolved = $this->resolveCategoryPhotosDirDetailed($category);

        if ($resolved['path'] === null) {
            return [
                'path' => null,
                'exists' => false,
                'readable' => false,
                'reason' => $resolved['reason'],
            ];
        }

        $exists = is_dir($resolved['path']);
        $readable = $exists && is_readable($resolved['path']) && is_executable($resolved['path']);
        $reason = null;
        if (!$exists) {
            $reason = 'not_found';
        } elseif (!$readable) {
            $reason = 'not_readable';
        }

        return [
            'path' => $resolved['path'],
            'exists' => $exists,
            'readable' => $readable,
            'reason' => $reason,
        ];
    }

    /**
     * Liste les images d'une catégorie (hors vignettes `thumbs/`), triées ALPHABÉTIQUEMENT.
     *
     * Story 57-7, AC1 — décision de conception ASSUMÉE (option (b), à trancher explicitement
     * par la story, jamais à subir) : le core (`Categorie::liste_photos()`) renvoie l'ordre
     * BRUT de `readdir()` (dépendant du filesystem, non déterministe), jamais trié — cette
     * méthode trie alphabétiquement à la place. CHANGEMENT DE COMPORTEMENT ASSUMÉ : pour une
     * catégorie à 2+ images dont l'ordre disque diverge de l'ordre alphabétique, "la première
     * image" retenue comme image de collection peut changer par rapport au comportement
     * historique (HTTP self, jamais trié non plus côté API `documents`). Choix déterministe
     * délibéré (reproductible, testable) plutôt que dépendant du filesystem de l'hébergeur.
     *
     * Filtre d'extension : jpg/jpeg/png/gif/webp — **volontairement PAS** identique au filtre
     * du core (`Categorie::liste_photos()` : `/(\.jpeg|\.jpg|\.bmp|\.gif|\.png|\.tiff)$/i`,
     * `categories/class/categorie.class.php:2046`). Deux raisons (code review 57-7, HIGH-1) :
     * (1) l'ancien chemin catégorie HTTP (`getCategoryImagePath()`, supprimé par cette story)
     * acceptait déjà `webp` — le reproduire fidèlement aurait été une RÉGRESSION silencieuse
     * pour toute catégorie dont l'image de collection est en `.webp` ; (2) `bmp`/`tiff` ne sont
     * pas des types de ressource `IMAGE` acceptés par Shopify, et
     * `uploadCategoryImageToCollection()` ne tente que `$images[0]` (pas de repli sur l'image
     * suivante) : une catégorie contenant `banner.bmp` + `photo.jpg` échouerait sans jamais
     * essayer le `.jpg`. Ce jeu d'extensions est EXACTEMENT celui du volet produit (cf. le
     * `LIKE` SQL de `fetchOrderedImagesFromDb()` plus haut dans ce fichier) — cohérence
     * délibérée entre les deux volets, malgré l'asymétrie scan-disque/`ecm_files`.
     *
     * @param object $category Catégorie Dolibarr (au minimum : id ; entity optionnel)
     * @return array<array{filename:string, size:int}> Trié par filename ASC
     */
    public function listCategoryImages($category): array
    {
        $dirPath = $this->resolveCategoryPhotosDir($category);
        if ($dirPath === null) {
            return [];
        }

        if (!is_dir($dirPath)) {
            $this->log(
                "listCategoryImages - directory not found for category id " . (int) $category->id . ": " . $dirPath,
                LOG_DEBUG
            );
            return [];
        }

        $entries = @scandir($dirPath);
        if ($entries === false) {
            $this->log("listCategoryImages - unable to scan directory: " . $dirPath, LOG_WARNING);
            return [];
        }

        $images = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dirPath . $entry;
            // is_file() exclut déjà 'thumbs/' (sous-répertoire) et tout autre sous-dossier —
            // pas besoin d'exclusion nommée dédiée, contrairement au volet produit (SQL LIKE).
            if (!is_file($fullPath)) {
                continue;
            }

            if (!preg_match('/(\.jpeg|\.jpg|\.gif|\.png|\.webp)$/i', $entry)) {
                continue;
            }

            $size = @filesize($fullPath);
            if ($size === false || $size <= 0) {
                // Un upload Shopify avec fileSize=0 serait corrompu — même règle que le volet
                // produit (buildImagesFromOrderedMap()).
                $this->log(
                    "listCategoryImages - skipping image with size<=0 for category id "
                    . (int) $category->id . " (file: " . $entry . ")",
                    LOG_WARNING
                );
                continue;
            }

            $images[] = [
                'filename' => $entry,
                'size'     => (int) $size,
            ];
        }

        usort($images, function ($a, $b) {
            return strcmp($a['filename'], $b['filename']);
        });

        $this->log(
            "listCategoryImages - found " . count($images) . " image(s) for category id " . (int) $category->id,
            LOG_DEBUG
        );

        return $images;
    }

    /**
     * Contenu binaire d'une image de catégorie depuis le disque.
     *
     * Format IDENTIQUE à {@see getImageBinary()} (volet produit) : mêmes clés de retour,
     * `null` (jamais `false`) si absente/illisible.
     *
     * @param object $category Catégorie Dolibarr (au minimum : id ; entity optionnel)
     * @param string $filename Nom du fichier (sans chemin)
     * @return array{content:string, mime_type:string, size:int, filename:string}|null
     */
    public function getCategoryImageBinary($category, string $filename): ?array
    {
        if (empty($filename)) {
            $this->log("getCategoryImageBinary - empty filename provided", LOG_ERR);
            return null;
        }

        $dirPath = $this->resolveCategoryPhotosDir($category);
        if ($dirPath === null) {
            return null;
        }

        // basename() : défense en profondeur contre un $filename contenant un chemin
        // (traversal) — le volet produit applique la même prudence en amont (basename() côté
        // appelant, cf. ImportProducts::getImageContent()).
        $filepath = $dirPath . basename($filename);

        if (!is_file($filepath) || !is_readable($filepath)) {
            $this->log("getCategoryImageBinary - file not found/readable: " . $filepath, LOG_WARNING);
            return null;
        }

        $content = @file_get_contents($filepath);
        if ($content === false) {
            $this->log("getCategoryImageBinary - failed to read file content: " . $filepath, LOG_ERR);
            return null;
        }

        return [
            'content'   => $content,
            'mime_type' => dol_mimetype($filename),
            'size'      => strlen($content),
            'filename'  => $filename,
        ];
    }
}
