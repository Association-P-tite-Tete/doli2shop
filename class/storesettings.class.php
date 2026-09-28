<?php
/**
 * @file        class/storesettings.class.php
 * @brief       Accesseur réglages key-value par boutique (table llx_doli2shop_store_settings)
 *
 * Lit d'abord la table per-store ; si absent ou NULL, retombe sur la constante globale
 * DOLI2SHOP_<KEY> via getDolGlobalString() ; si absente, retourne le défaut appelant.
 * Aucun backfill automatique : la table reste vide tant que l'UI 49-4 n'est pas livrée.
 * Invariant mono-boutique : comportement identique à avant cette story.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.3.10
 * @since       2.3.8
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';

/**
 * Accesseur réglages key-value par boutique avec fallback vers constantes globales DOLI2SHOP_*.
 *
 * Pattern d'utilisation :
 *   $settings = new StoreSettings($db);
 *   $val = $settings->get($storeId, 'AUTO_CREATE_INVOICE', '0');
 *
 * Sécurité : NE PAS faire passer les credentials (access_token, api_key, api_secret) par
 * StoreSettings — ils restent dans llx_doli2shop_stores (StoreService). Ne pas logger setting_value.
 */
class StoreSettings
{
    use LoggerTrait;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var int Identifiant entité Dolibarr */
    private $entity;

    /**
     * Cache statique des réglages per-store (Story 49-4 — évite les N+1 SQL par commande).
     * Structure : self::$cache[$entity][$storeId][NORMALIZED_KEY] = string|null
     * null = ligne absente en DB → fallback global ; string (y compris '') = valeur explicite.
     * Cloisonné par entité (MEDIUM-1 code review) pour éviter les collisions multi-entité.
     *
     * @var array<int,array<int,array<string,string|null>>>
     */
    private static $cache = [];

    /**
     * Flag de bulk-load : indique qu'un getAllForStore() a déjà chargé toutes les clés
     * pour (entity, storeId) — évite les N+1 pour les clés absentes (MEDIUM-2/3).
     * Structure : self::$bulkLoaded[$entity][$storeId] = true
     *
     * @var array<int,array<int,bool>>
     */
    private static $bulkLoaded = [];

    /**
     * Constructeur
     *
     * @param DoliDB   $db     Gestionnaire base de données
     * @param int|null $entity Entité Dolibarr (null = entité courante $conf->entity)
     */
    public function __construct($db, $entity = null)
    {
        global $conf;
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
        $this->log('StoreSettings::__construct() - Initialisation entity=' . $this->entity, LOG_DEBUG);
    }

    // =========================================================================
    // Lecture
    // =========================================================================

    /**
     * Retourne un réglage per-store avec fallback vers la constante globale DOLI2SHOP_<KEY>.
     *
     * Logique :
     *  1. Consulter le cache statique ; si hit → retourner directement (évite N+1 SQL)
     *  2. SELECT setting_value FROM llx_doli2shop_store_settings WHERE entity=? AND fk_store=? AND setting_key=?
     *  3. Si ligne trouvée ET setting_value !== null → stocker en cache et retourner
     *  4. Sinon → stocker null en cache (= absent DB) ; fallback getDolGlobalString('DOLI2SHOP_'.KEY)
     *  5. Sinon → retourner $default
     *
     * CORRECTION VALIDATE : une setting_value NULL ne court-circuite PAS le fallback global.
     * Évite qu'une ligne NULL insérée par erreur masque la constante.
     *
     * @param  int    $storeId Identifiant boutique (fk_store)
     * @param  string $key     Clé de réglage (ex: 'AUTO_CREATE_INVOICE')
     * @param  mixed  $default Valeur par défaut si absent partout
     * @return mixed           Valeur per-store, constante globale, ou défaut
     */
    public function get(int $storeId, string $key, $default = null)
    {
        // Normalisation de casse (review 49-3 MEDIUM) : clé toujours en MAJUSCULES,
        // pour que la lecture DB, l'écriture (set) et le fallback constante soient cohérents.
        $nk = $this->normalizeKey($key);

        $entity = $this->entity;

        // 1. Cache hit — évite le N+1 SQL dans le moteur de commandes
        if (isset(self::$cache[$entity][$storeId]) && array_key_exists($nk, self::$cache[$entity][$storeId])) {
            $cached = self::$cache[$entity][$storeId][$nk];
            if ($cached !== null) {
                return $cached;
            }
            // null en cache = ligne absente en DB → fallback global
            $globalVal = getDolGlobalString('DOLI2SHOP_' . $nk);
            return ($globalVal !== '') ? $globalVal : $default;
        }

        // MEDIUM-2/3 : si le bulk-load a déjà été fait pour (entity, storeId) et que la clé
        // est absente du cache → la clé n'existe pas en DB. Fallback global DIRECT (pas de SQL unitaire).
        if (!empty(self::$bulkLoaded[$entity][$storeId])) {
            $globalVal = getDolGlobalString('DOLI2SHOP_' . $nk);
            return ($globalVal !== '') ? $globalVal : $default;
        }

        // 2. Cache miss → requête SQL
        $sql  = 'SELECT setting_value FROM ' . MAIN_DB_PREFIX . 'doli2shop_store_settings';
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND fk_store = ' . (int) $storeId;
        $sql .= " AND setting_key = '" . $this->db->escape($nk) . "'";
        $sql .= ' LIMIT 1';

        $result = $this->db->query($sql);
        if ($result) {
            $row = $this->db->fetch_object($result);
            if ($row !== null && $row !== false && $row->setting_value !== null) {
                // Valeur per-store présente (même string vide = valeur explicite, pas NULL)
                self::$cache[$entity][$storeId][$nk] = $row->setting_value;
                return $row->setting_value;
            }
        } else {
            $this->log('StoreSettings::get() - Erreur SQL key="' . $nk . '": ' . $this->db->lasterror(), LOG_WARNING);
        }

        // Absent en DB : stocker null dans le cache pour éviter de re-requêter
        self::$cache[$entity][$storeId][$nk] = null;

        // 3. Fallback : constante globale DOLI2SHOP_<KEY>
        // getDolGlobalString retourne '' si absente (jamais null)
        $globalVal = getDolGlobalString('DOLI2SHOP_' . $nk);
        if ($globalVal !== '') {
            return $globalVal;
        }

        // 4. Défaut appelant
        return $default;
    }

    /**
     * Normalise une clé de réglage : trim + MAJUSCULES. Garantit la cohérence entre
     * écriture (set), lecture (get) et fallback constante globale DOLI2SHOP_<KEY>.
     *
     * @param  string $key Clé brute
     * @return string      Clé normalisée
     */
    private function normalizeKey(string $key): string
    {
        return strtoupper(trim($key));
    }

    /**
     * Retourne un réglage per-store casté en entier.
     *
     * @param  int    $storeId Identifiant boutique
     * @param  string $key     Clé de réglage
     * @param  int    $default Valeur par défaut entière
     * @return int
     */
    public function getInt(int $storeId, string $key, int $default = 0): int
    {
        return (int) $this->get($storeId, $key, $default);
    }

    /**
     * Indique si une valeur per-store explicite existe pour cette clé (override actif).
     *
     * Sémantique : '' (string vide) EST un override (valeur explicitement positionnée à vide) ;
     * null = ligne absente en DB = pas d'override (fallback global actif).
     *
     * Auto-bulk-load : si getAllForStore() n'a pas encore été appelé pour (entity, storeId),
     * le charge automatiquement pour éviter les N+1 lors de parcours de liste de clés.
     *
     * @param  int    $storeId Identifiant boutique
     * @param  string $key     Clé de réglage (normalisée en interne)
     * @return bool            true si un override per-store existe, false sinon
     * @since  2.3.10
     */
    public function hasOverride(int $storeId, string $key): bool
    {
        $nk = $this->normalizeKey($key);
        if (empty(self::$bulkLoaded[$this->entity][$storeId])) {
            $this->getAllForStore($storeId); // no-op si déjà chargé
        }
        return array_key_exists($nk, self::$cache[$this->entity][$storeId] ?? [])
            && self::$cache[$this->entity][$storeId][$nk] !== null;
    }

    // =========================================================================
    // Écriture
    // =========================================================================

    /**
     * Insère ou met à jour un réglage per-store (upsert sur la clé unique entity+fk_store+setting_key).
     * Invalide le cache pour forcer une relecture propre après écriture.
     *
     * @param  int    $storeId Identifiant boutique (> 0 obligatoire)
     * @param  string $key     Clé de réglage (non vide)
     * @param  mixed  $value   Valeur à stocker (castée en string)
     * @return int             1 si OK, -1 si erreur
     */
    public function set(int $storeId, string $key, $value): int
    {
        if ($storeId <= 0) {
            $this->log('StoreSettings::set() - storeId invalide (' . $storeId . ')', LOG_WARNING);
            return -1;
        }
        $key = $this->normalizeKey($key);
        if ($key === '') {
            $this->log('StoreSettings::set() - key vide', LOG_WARNING);
            return -1;
        }

        // Sémantique null (review 49-3 MEDIUM) : enregistrer null = « pas d'override par boutique ».
        // On supprime la ligne pour que get() retombe proprement sur le fallback global
        // (au lieu de stocker '' qui court-circuiterait le fallback).
        if ($value === null) {
            return $this->delete($storeId, $key) >= 0 ? 1 : -1;
        }

        $sql  = 'INSERT INTO ' . MAIN_DB_PREFIX . 'doli2shop_store_settings';
        $sql .= ' (entity, fk_store, setting_key, setting_value)';
        $sql .= ' VALUES (';
        $sql .= (int) $this->entity . ', ';
        $sql .= (int) $storeId . ', ';
        $sql .= "'" . $this->db->escape($key) . "', ";
        $sql .= "'" . $this->db->escape((string) $value) . "'";
        $sql .= ')';
        $sql .= ' ON DUPLICATE KEY UPDATE';
        $sql .= ' setting_value = VALUES(setting_value),';
        $sql .= ' tms = CURRENT_TIMESTAMP';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('StoreSettings::set() - Erreur SQL key="' . $key . '": ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        // Mettre à jour le cache directement (évite une purge totale qui force N+1 sur le reste)
        // Le flag bulkLoaded reste valide : on réécrit juste la valeur modifiée.
        self::$cache[$this->entity][$storeId][$key] = (string) $value;

        $this->log('StoreSettings::set() - Réglage key="' . $key . '" enregistré pour storeId=' . $storeId, LOG_INFO);
        return 1;
    }

    /**
     * Vide le cache statique pour une boutique de l'entité courante (ou intégralement si storeId null).
     * Appelé après delete() et lors de la rotation boutique par défaut pour garantir la cohérence.
     *
     * @param  int|null $storeId Boutique ciblée (entité courante), ou null pour vider tout le cache
     * @return void
     */
    public static function clearCache(?int $storeId = null): void
    {
        global $conf;
        $entity = isset($conf->entity) ? (int) $conf->entity : 1;
        if ($storeId !== null) {
            unset(self::$cache[$entity][$storeId]);
            unset(self::$bulkLoaded[$entity][$storeId]);
        } else {
            self::$cache = [];
            self::$bulkLoaded = [];
        }
    }

    // =========================================================================
    // Lecture bulk
    // =========================================================================

    /**
     * Retourne tous les réglages per-store pour une boutique donnée.
     * Peuple également le cache statique en lot (évite les N+1 dans le moteur de commandes).
     *
     * @param  int   $storeId Identifiant boutique
     * @return array          Tableau associatif ['SETTING_KEY' => 'setting_value', ...] ou [] si aucune ligne
     */
    public function getAllForStore(int $storeId): array
    {
        $entity = $this->entity;

        // MEDIUM-2 : early-return si déjà bulk-chargé (évite une 2e requête SELECT complète)
        if (!empty(self::$bulkLoaded[$entity][$storeId])) {
            return self::$cache[$entity][$storeId] ?? [];
        }

        $sql  = 'SELECT setting_key, setting_value FROM ' . MAIN_DB_PREFIX . 'doli2shop_store_settings';
        $sql .= ' WHERE entity = ' . (int) $entity;
        $sql .= ' AND fk_store = ' . (int) $storeId;
        $sql .= ' ORDER BY setting_key';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('StoreSettings::getAllForStore() - Erreur SQL storeId=' . $storeId . ': ' . $this->db->lasterror(), LOG_ERR);
            return [];
        }

        $settings = [];
        while ($row = $this->db->fetch_object($result)) {
            $settings[$row->setting_key] = $row->setting_value;
        }

        // Peupler le cache en lot, cloisonné par entité (MEDIUM-1 + MEDIUM-2/3)
        foreach ($settings as $k => $v) {
            self::$cache[$entity][$storeId][$k] = $v;
        }

        // Marquer comme bulk-chargé : get() ne fera plus de SQL unitaire pour les clés absentes
        self::$bulkLoaded[$entity][$storeId] = true;

        return $settings;
    }

    // =========================================================================
    // Suppression
    // =========================================================================

    /**
     * Supprime un réglage per-store (utile pour les tests et la réinitialisation).
     * Invalide le cache pour cette boutique.
     *
     * @param  int    $storeId Identifiant boutique
     * @param  string $key     Clé de réglage à supprimer
     * @return int             Nombre de lignes supprimées (0 si absent), -1 si erreur
     */
    public function delete(int $storeId, string $key): int
    {
        if ($storeId <= 0) {
            return -1;
        }
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return -1;
        }

        $sql  = 'DELETE FROM ' . MAIN_DB_PREFIX . 'doli2shop_store_settings';
        $sql .= ' WHERE entity = ' . (int) $this->entity;
        $sql .= ' AND fk_store = ' . (int) $storeId;
        $sql .= " AND setting_key = '" . $this->db->escape($key) . "'";

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('StoreSettings::delete() - Erreur SQL key="' . $key . '": ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        // Invalider la clé dans le cache (la ligne est supprimée → null ou absence)
        self::$cache[$this->entity][$storeId][$key] = null;
        // Laisser bulkLoaded intact : le prochain get() sur cette clé verra null → fallback global

        return $this->db->affected_rows($result);
    }
}
