<?php

/**
 * @file        class/productscopehelper.class.php
 * @brief       Helper unique définissant "quels produits Dolibarr sont dans le périmètre de
 *              synchronisation vers Shopify" — Story 63-19
 *
 * Avant cette story, CINQ sites recalculaient chacun leur propre définition du périmètre
 * catégorie/entité (CRON, écran stats, sync manuelle, recherche produit, preview mort) — avec des
 * divergences qui rendaient le symptôme illisible pour l'utilisateur : un écran pouvait afficher
 * "aucun produit à synchroniser" pendant que le CRON en poussait des centaines (dossier support
 * Europe Loisirs, confirmé sur données de production le 23/08/2026).
 *
 * Ce helper centralise :
 *   - le calcul de l'ARBRE de catégories (racine + tous ses descendants), tranché comme LA
 *     définition qui fait foi (AC1) — c'est le comportement du CRON, en production depuis des
 *     années, préféré à la "catégorie exacte" des écrans (plus restrictive, source du symptôme
 *     Europe Loisirs : son produit témoin est dans une sous-catégorie, jamais dans la racine) ;
 *   - le filtre `entity` sur `llx_categorie` PENDANT la traversée de l'arbre (AC2) — absent avant
 *     cette story sur le chemin CRON, ce qui faisait fuiter les catégories de toutes les entités
 *     sur une installation Multi Company.
 *
 * Le filtre `tosell` (AC3) N'EST PAS centralisé ici : il reste une décision PAR SITE, documentée
 * dans la story de dev (63-19, section AC3) — le CRON doit continuer à voir les produits hors
 * vente déjà synchronisés pour pouvoir les repasser en DRAFT (garde-fou anti-désarchivage
 * silencieux, Story 58-5, `ImportProducts::shouldSkipInactiveProductCreation()` /
 * `syncProduct()`), alors que les écrans humains (stats, recherche, sync manuelle) filtrent
 * `tosell = 1` pour ne présenter que des produits qu'un humain peut raisonnablement vouloir
 * synchroniser. Ce n'est PAS un oubli : centraliser tosell ici aurait cassé silencieusement le
 * garde-fou 58-5 (le CRON ne verrait plus jamais les produits déjà mappés repassés hors vente).
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.5.2
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';

/**
 * Helper centralisé pour le périmètre "catégorie + entité" des produits synchronisés Dolibarr →
 * Shopify.
 *
 * Usage :
 *   $scopeHelper = new ProductScopeHelper($db);
 *   $categoryIds = $scopeHelper->getCategoryTreeIds($rootCategoryId, $entity);
 *   $sql .= " AND cp.fk_categorie IN " . ProductScopeHelper::buildCategoryInClause($categoryIds);
 *   $sql .= " AND p.entity = " . (int) $entity;
 */
class ProductScopeHelper
{
    use LoggerTrait;

    /**
     * Garde de profondeur pour la traversée de l'arbre de catégories — PARTAGÉE par les deux
     * implémentations (CTE récursive et table temporaire itérative).
     *
     * Corrige deux constats de la code review du 23/08/2026 :
     *  - CRITICAL 1 : avant ce correctif, seul le chemin table temporaire bornait sa traversée à 10
     *    niveaux ; le chemin CTE, lui, dépendait entièrement de `cte_max_recursion_depth` (1000 par
     *    défaut chez MySQL) pour se terminer — un cycle dans `fk_parent` (catégorie qui est sa
     *    propre ancêtre, corruption/migration/saisie manuelle) déclenchait `ER_CTE_RECURSION_LIMIT`,
     *    que `SqlUtils::executeQuery()` transforme en `Exception` (fatal sur `admin/sync_products.php`,
     *    appelé avant `llxHeader()` et sans aucun `try/catch` ; DÉNI DE SERVICE silencieux sur le
     *    CRON, qui catchait déjà l'exception mais ne synchronisait alors plus rien).
     *  - MEDIUM 8 : les deux chemins avaient des profondeurs asymétriques et non documentées (CTE
     *    ~1000, table temporaire 10) — deux moteurs pouvaient donc voir des périmètres différents
     *    sur le même catalogue au-delà de 10 niveaux. Harmonisé sur UNE seule constante.
     *
     * Le chemin CTE borne désormais sa récursion à cette même profondeur (`depth_level < N` dans la
     * clause récursive) — ce qui la rend robuste à un cycle QUELLE QUE SOIT la valeur de
     * `cte_max_recursion_depth` côté serveur : la requête se termine d'elle-même après N niveaux,
     * jamais par une exception. Les deux chemins journalisent en `LOG_WARNING` quand ce plafond est
     * effectivement atteint (le périmètre calculé peut alors être TRONQUÉ — descendants au-delà de
     * cette profondeur non inclus), pour qu'un plafond silencieux ne soit jamais confondu avec un
     * arbre complet.
     */
    const MAX_CATEGORY_TREE_DEPTH = 10;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /**
     * @param DoliDB $db Gestionnaire base de données
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // DÉTECTION DE MOTEUR — AC8
    // =========================================================================

    /**
     * Détermine si le moteur SQL connecté supporte `WITH RECURSIVE` (CTE récursive), à partir de
     * la chaîne renvoyée par `SELECT VERSION()`.
     *
     * ⚠️ Piège vérifié empiriquement (AC8) plutôt que supposé : `VERSION()` renvoie par exemple
     * "9.7.1" sur une install MySQL locale et "12.0.2-MariaDB" sur l'install MariaDB de test —
     * un simple `preg_match('/^(\d+\.\d+)/')` puis `(float) >= 8.0` fonctionne aujourd'hui pour
     * les DEUX moteurs, mais PAR COÏNCIDENCE : les numéros de version MariaDB (10.x, 11.x, 12.x)
     * se comparent tous ≥ 8.0 en float, alors qu'ils ne signifient absolument pas "MySQL 8+" — ce
     * sont des lignées de version indépendantes. Le test aurait donné un résultat FAUX pour du
     * MariaDB 10.0/10.1 (WITH RECURSIVE introduit en 10.2.2 seulement, cf. CLAUDE.dolibarr.md §11
     * qui fixe le plancher supporté à MariaDB 10.3+, donc hors du périmètre réellement déployé —
     * mais la coïncidence numérique reste fragile et ne doit pas être ce qui protège le module).
     *
     * Détection explicite : MariaDB est identifié par la sous-chaîne "MariaDB" dans `VERSION()`
     * (présente sur toutes les builds MariaDB modernes, y compris les deux vérifiées ici) et est
     * TOUJOURS considéré compatible CTE récursive, puisque le plancher supporté du module
     * (MariaDB 10.3+) est postérieur à l'introduction de la fonctionnalité (10.2.2). MySQL reste
     * sur le test numérique `>= 8.0` (recursive CTE introduites en MySQL 8.0).
     *
     * @param  string $versionString Chaîne renvoyée par `SELECT VERSION()`
     * @return bool   true si `WITH RECURSIVE` est supporté
     */
    public static function supportsRecursiveCte($versionString)
    {
        $versionString = (string) $versionString;

        if (stripos($versionString, 'MariaDB') !== false) {
            return true;
        }

        preg_match('/^(\d+\.\d+)/', $versionString, $matches);
        $versionNumber = isset($matches[1]) ? (float) $matches[1] : 0.0;

        return $versionNumber >= 8.0;
    }

    /**
     * Lit `SELECT VERSION()` sur la connexion courante.
     *
     * @return string Chaîne de version brute (chaîne vide si la requête échoue)
     */
    protected function detectDbVersion()
    {
        $result = SqlUtils::executeQuery($this->db, "SELECT VERSION() as db_version", "detecting DB engine version for category tree", false);
        if (!$result) {
            return '';
        }
        $row = $this->db->fetch_object($result);
        $this->db->free($result);

        return $row && isset($row->db_version) ? (string) $row->db_version : '';
    }

    // =========================================================================
    // ARBRE DE CATÉGORIES — AC1 (arbre, tranché) + AC2 (filtre entity)
    // =========================================================================

    /**
     * Calcule l'arbre complet d'une catégorie (racine + tous ses descendants), filtré par entité
     * sur `llx_categorie` à chaque niveau (AC2).
     *
     * @param  int $rootCategoryId ID de la catégorie racine (ex: DOLI2SHOP_DOLIBARR_PROCATE)
     * @param  int $entity         Entité Dolibarr
     * @return int[] Liste des rowid de catégories dans le périmètre (racine incluse), vide si
     *               $rootCategoryId <= 0
     */
    public function getCategoryTreeIds($rootCategoryId, $entity)
    {
        $rootCategoryId = (int) $rootCategoryId;
        $entity = (int) $entity;

        if ($rootCategoryId <= 0) {
            return array();
        }

        $dbVersion = $this->detectDbVersion();

        if (self::supportsRecursiveCte($dbVersion)) {
            $this->log("Using recursive CTE method (VERSION(): " . $dbVersion . ")", LOG_DEBUG);
            return $this->getCategoryTreeIdsViaCte($rootCategoryId, $entity);
        }

        $this->log("Using compatible temporary-table method (VERSION(): " . $dbVersion . ")", LOG_DEBUG);
        return $this->getCategoryTreeIdsViaTempTable($rootCategoryId, $entity);
    }

    /**
     * Chemin MySQL 8.0+ / MariaDB (toute version supportée par le module) : CTE récursive.
     *
     * Bornée à `self::MAX_CATEGORY_TREE_DEPTH` niveaux (CRITICAL 1 / MEDIUM 8, code review 63-19) :
     * la clause récursive n'avance plus tant que `cte_max_recursion_depth` (MySQL) le permet, elle
     * s'arrête d'elle-même — un cycle dans `fk_parent` termine donc TOUJOURS la requête, jamais par
     * `ER_CTE_RECURSION_LIMIT`.
     *
     * @param  int $rootCategoryId ID de la catégorie racine
     * @param  int $entity         Entité Dolibarr
     * @return int[] Liste des rowid dans le périmètre
     */
    protected function getCategoryTreeIdsViaCte($rootCategoryId, $entity)
    {
        $maxLevel = self::MAX_CATEGORY_TREE_DEPTH;

        $sql = "WITH RECURSIVE category_tree AS (
                SELECT rowid, fk_parent, 0 AS depth_level
                FROM " . MAIN_DB_PREFIX . "categorie
                WHERE rowid = " . (int) $rootCategoryId . " AND entity = " . (int) $entity . "
                UNION ALL
                SELECT c.rowid, c.fk_parent, ct.depth_level + 1
                FROM " . MAIN_DB_PREFIX . "categorie c
                INNER JOIN category_tree ct ON ct.rowid = c.fk_parent
                WHERE c.entity = " . (int) $entity . "
                    AND ct.depth_level < " . (int) $maxLevel . "
            )
            SELECT rowid, depth_level FROM category_tree";

        $result = SqlUtils::executeQuery($this->db, $sql, "getting category tree (recursive CTE)");

        $ids = array();
        $deepestLevelSeen = -1;
        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $ids[] = (int) $obj->rowid;
                if (isset($obj->depth_level)) {
                    $deepestLevelSeen = max($deepestLevelSeen, (int) $obj->depth_level);
                }
            }
            $this->db->free($result);
        }

        // MEDIUM 8 : un plafond silencieux est indistinguable d'un arbre complet — le signaler.
        // La profondeur max possible en sortie est $maxLevel (base=0 ... dernière génération
        // ajoutée = $maxLevel) ; l'atteindre signifie que d'éventuels descendants au-delà n'ont
        // jamais été explorés (soit une hiérarchie inhabituellement profonde, soit un cycle).
        if ($deepestLevelSeen >= $maxLevel) {
            $this->log("Category tree traversal (recursive CTE) for root " . (int) $rootCategoryId . " (entity " . (int) $entity . ") reached the depth cap (" . $maxLevel . " levels) — descendants beyond this depth are NOT included in the scope. Expected for an unusually deep hierarchy; also the safety bound protecting against a cyclic fk_parent (a category that is its own ancestor).", LOG_WARNING);
        }

        return $ids;
    }

    /**
     * Chemin de compatibilité (moteur sans CTE récursive) : table temporaire itérative, garde de
     * profondeur à 10 niveaux — copie du comportement historique de `ImportProducts::importProducts()`
     * avant extraction (Story 63-19), avec le filtre `entity` ajouté (AC2).
     *
     * @param  int $rootCategoryId ID de la catégorie racine
     * @param  int $entity         Entité Dolibarr
     * @return int[] Liste des rowid dans le périmètre
     */
    protected function getCategoryTreeIdsViaTempTable($rootCategoryId, $entity)
    {
        $rootCategoryId = (int) $rootCategoryId;
        $entity = (int) $entity;

        $sqlCreateTemp = "CREATE TEMPORARY TABLE IF NOT EXISTS tmp_category_tree_63_19 (
            id INT NOT NULL,
            level INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        )";
        SqlUtils::executeQuery($this->db, $sqlCreateTemp, "creating temporary category tree table");

        // Table temporaire par connexion : vidée avant usage pour ne jamais hériter d'un arbre
        // d'un appel précédent sur la même connexion (CREATE TEMPORARY TABLE IF NOT EXISTS ne
        // recrée pas la table si elle existe déjà).
        SqlUtils::executeQuery($this->db, "TRUNCATE TABLE tmp_category_tree_63_19", "truncating temporary category tree table", false);

        // N'insère la racine que si elle appartient bien à l'entité demandée (AC2) — sinon
        // l'arbre est vide, comme le rendrait la CTE récursive dans le même cas.
        $sqlCheckRoot = "SELECT rowid FROM " . MAIN_DB_PREFIX . "categorie WHERE rowid = " . $rootCategoryId . " AND entity = " . $entity;
        $resultCheckRoot = SqlUtils::executeQuery($this->db, $sqlCheckRoot, "checking root category entity");
        $rootBelongsToEntity = $resultCheckRoot && $this->db->num_rows($resultCheckRoot) > 0;
        if ($resultCheckRoot) {
            $this->db->free($resultCheckRoot);
        }

        if (!$rootBelongsToEntity) {
            return array();
        }

        $sqlInsertRoot = "INSERT INTO tmp_category_tree_63_19 (id, level) VALUES (" . $rootCategoryId . ", 0)";
        SqlUtils::executeQuery($this->db, $sqlInsertRoot, "inserting root category");

        $foundNew = true;
        $level = 0;
        $maxLevel = self::MAX_CATEGORY_TREE_DEPTH; // Garde de profondeur — harmonisée avec le chemin CTE (MEDIUM 8)

        while ($foundNew && $level < $maxLevel) {
            $sqlFindChildren = "INSERT IGNORE INTO tmp_category_tree_63_19 (id, level)
                SELECT c.rowid, " . ($level + 1) . "
                FROM " . MAIN_DB_PREFIX . "categorie c
                INNER JOIN tmp_category_tree_63_19 t ON c.fk_parent = t.id
                WHERE t.level = " . $level . " AND c.entity = " . $entity;

            $result = SqlUtils::executeQuery($this->db, $sqlFindChildren, "finding child categories at level " . $level);
            $affectedRows = $result ? $this->db->affected_rows($result) : 0;

            $this->log("Level " . $level . ": Added " . $affectedRows . " child categories", LOG_DEBUG);

            $foundNew = ($affectedRows > 0);
            $level++;
        }

        // MEDIUM 8 : distingue "plus aucun enfant trouvé" (fin normale) de "plafond de profondeur
        // atteint alors que des enfants existaient encore au dernier niveau exploré" (troncature
        // possible — même logique que le chemin CTE ci-dessus). Un plafond silencieux est
        // indistinguable d'un arbre complet.
        if ($level >= $maxLevel && $foundNew) {
            $this->log("Category tree traversal (temporary table) for root " . $rootCategoryId . " (entity " . $entity . ") reached the depth cap (" . $maxLevel . " levels) — descendants beyond this depth are NOT included in the scope. Expected for an unusually deep hierarchy; also the safety bound protecting against a cyclic fk_parent (a category that is its own ancestor).", LOG_WARNING);
        }

        $sqlSelect = "SELECT id as rowid FROM tmp_category_tree_63_19";
        $result = SqlUtils::executeQuery($this->db, $sqlSelect, "reading category tree from temporary table");

        $ids = array();
        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $ids[] = (int) $obj->rowid;
            }
            $this->db->free($result);
        }

        return $ids;
    }

    // =========================================================================
    // DIAGNOSTIC — HIGH 3 / HIGH 5 (code review 63-19)
    // =========================================================================

    /**
     * Journalise un diagnostic quand le périmètre catégorie calculé nécessite une explication —
     * PARTAGÉ par les 4 sites (CRON, écran stats, sync manuelle, recherche produit) pour que le
     * prochain dossier support identique à Europe Loisirs laisse une trace exploitable au premier
     * coup d'œil dans les journaux, plutôt qu'un LOG_INFO invisible à toute supervision qui ne
     * regarde que WARNING/ERR.
     *
     * Distingue 3 causes :
     *  1. Racine non configurée (<= 0) — périmètre vide PAR CONCEPTION, identique et explicite sur
     *     les 4 sites (HIGH 5) : jamais "tout le catalogue" par défaut sur un site pendant que
     *     d'autres renvoient zéro.
     *  2. Racine configurée mais périmètre vide — catégorie introuvable dans AUCUNE entité, ou
     *     trouvée mais sous une AUTRE entité que celle utilisée (le cas confirmé chez Europe
     *     Loisirs pour le volet AC2 : `llx_cronjob.entity` figée à l'activation du module peut
     *     diverger de l'entité où vit la configuration/les catégories — HIGH 3). Le message nomme
     *     les deux entités (celle utilisée ET celle où la catégorie a été trouvée, si trouvée) pour
     *     que ce soit CE message qui évite le prochain dossier support identique.
     *  3. Racine configurée, trouvée dans la bonne entité, mais sans aucun descendant — périmètre
     *     limité à la racine seule. Cas normal (catégorie sans sous-catégorie), journalisé en
     *     LOG_INFO (pas WARNING : ce n'est pas une anomalie).
     *
     * @param  int   $rootCategoryId Catégorie racine configurée (ex: DOLI2SHOP_DOLIBARR_PROCATE)
     * @param  int   $entity         Entité utilisée pour le calcul
     * @param  int[] $categoryIds    Résultat de getCategoryTreeIds() pour ce couple racine/entité
     * @return void
     */
    public function logEmptyScopeDiagnostics($rootCategoryId, $entity, array $categoryIds)
    {
        $rootCategoryId = (int) $rootCategoryId;
        $entity = (int) $entity;

        if ($rootCategoryId <= 0) {
            // HIGH 5 : "catégorie non configurée" est désormais un périmètre VIDE explicite et
            // IDENTIQUE sur les 4 sites — jamais "tout le catalogue" par défaut sur l'un d'eux.
            // Journalisé en WARNING (pas INFO) : c'est une configuration incomplète, pas une
            // erreur transitoire, et elle doit rester visible en supervision.
            $this->log("No root category configured (entity " . $entity . ") — product scope is EMPTY by design on all call sites until DOLI2SHOP_DOLIBARR_PROCATE is set.", LOG_WARNING);
            return;
        }

        if (!empty($categoryIds)) {
            if (count($categoryIds) === 1) {
                $this->log("Category scope for root " . $rootCategoryId . " (entity " . $entity . ") has no descendant category — scope limited to the root category itself.", LOG_INFO);
            }
            return;
        }

        // Périmètre vide alors qu'une racine est configurée (> 0) : distinguer "catégorie
        // inexistante" de "catégorie existante mais dans une autre entité" (HIGH 3).
        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "categorie WHERE rowid = " . $rootCategoryId;
        $result = SqlUtils::executeQuery($this->db, $sql, "diagnosing empty category scope", false);

        $foundEntities = array();
        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $foundEntities[] = (int) $obj->entity;
            }
            $this->db->free($result);
        }

        if (empty($foundEntities)) {
            $this->log("Category scope EMPTY for root " . $rootCategoryId . " (used entity " . $entity . "): this category does NOT EXIST in any entity. Check the configured category ID (DOLI2SHOP_DOLIBARR_PROCATE).", LOG_WARNING);
            return;
        }

        $this->log("Category scope EMPTY for root " . $rootCategoryId . ": used entity " . $entity . ", but this category was found under entity " . implode(',', $foundEntities) . " instead — likely an entity mismatch (e.g. llx_cronjob.entity fixed at module activation vs. the entity the module is actually configured in). See Story 63-19.", LOG_WARNING);
    }

    // =========================================================================
    // SQL — helper d'assemblage
    // =========================================================================

    /**
     * Construit une clause `IN (...)` à partir d'une liste d'IDs de catégories, sûre même si la
     * liste est vide (retourne `(0)`, qui ne matche jamais aucune vraie catégorie — jamais "tout
     * le catalogue" par accident si la configuration est absente/invalide).
     *
     * @param  int[] $categoryIds
     * @return string Fragment SQL "(id1,id2,...)" ou "(0)" si vide
     */
    public static function buildCategoryInClause(array $categoryIds)
    {
        if (empty($categoryIds)) {
            return "(0)";
        }

        $sanitized = array_map('intval', $categoryIds);

        return "(" . implode(',', $sanitized) . ")";
    }
}
