<?php
/**
 * @file        class/storeservice.class.php
 * @brief       Service CRUD pour la table llx_doli2shop_stores (configuration boutiques Shopify)
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.3.1
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/storecategoryhelper.class.php';

/**
 * Service CRUD pour la gestion des boutiques Shopify dans Doli2Shop.
 *
 * Chaque opération filtre par entity pour garantir l'isolation multi-entité.
 * Les credentials (access_token, api_key, api_secret) ne sont JAMAIS loggés.
 */
class StoreService
{
    use LoggerTrait;

    /** @var DoliDB Gestionnaire de base de données */
    private $db;

    /** @var bool Vrai si la dernière requête de fetch() a échoué (≠ « aucune ligne trouvée ») */
    private $lastFetchFailed = false;

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
        $this->db = $db;
        $this->entity = isset($entity) ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);
        $this->log('Initialisation entity=' . $this->entity, LOG_DEBUG);
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * Crée une boutique.
     *
     * @param  array $data Données de la boutique (label, shop_domain obligatoires)
     * @return int         rowid créé (>0), ou -1 en cas d'erreur
     */
    public function create(array $data): int
    {
        if (empty($data['label']) || empty($data['shop_domain'])) {
            $this->log('create() - label et shop_domain obligatoires', LOG_WARNING);
            return -1;
        }

        // Hotfix 2.4.5 (AC4 — Validate correction #4) : construction DYNAMIQUE des colonnes,
        // en miroir exact du pattern array_key_exists/isset déjà utilisé par update(). Avant ce
        // correctif, create() référençait INCONDITIONNELLEMENT les 13 colonnes (dont
        // token_expires_at/refresh_token, ajoutées par la migration sql/update_2.4.0_2.4.1.sql) :
        // sur une base où cette migration n'a pas été rejouée (mise à jour par simple copie de
        // fichiers, sans désactivation/réactivation du module), la création d'une NOUVELLE
        // boutique échouait avec "Unknown column" alors que update() (colonnes non référencées
        // si absentes des $data) fonctionnait — asymétrie exploitée par le symptôme client
        // (2ᵉ boutique impossible à connecter). entity/label/shop_domain/datec restent toujours
        // présents (colonnes obligatoires, jamais optionnelles).
        $columns = array('entity', 'label', 'shop_domain');
        $values  = array((int) $this->entity, "'" . $this->db->escape($data['label']) . "'", "'" . $this->db->escape($data['shop_domain']) . "'");

        // Review 3 couches (MEDIUM) : miroir LITTÉRAL d'update() → array_key_exists(), pas
        // isset(). Une clé présente avec la valeur null doit écrire NULL explicitement, comme le
        // fait update() ; avec isset() les deux méthodes divergeraient silencieusement le jour où
        // une colonne optionnelle aurait un défaut SQL non-NULL.
        foreach (array('access_token', 'api_key', 'api_secret', 'location_id') as $textColumn) {
            if (array_key_exists($textColumn, $data)) {
                $columns[] = $textColumn;
                $values[]  = $data[$textColumn] !== null
                    ? "'" . $this->db->escape((string) $data[$textColumn]) . "'"
                    : 'NULL';
            }
        }
        if (array_key_exists('fk_categorie', $data)) {
            $columns[] = 'fk_categorie';
            $values[]  = $data['fk_categorie'] !== null ? (int) $data['fk_categorie'] : 'NULL';
        }
        // is_default / active : TOUJOURS écrits (défaut explicite 0/1 si absents des données) —
        // comportement historique de create(), volontairement conservé.
        $columns[] = 'is_default';
        $values[]  = isset($data['is_default']) ? (int) (bool) $data['is_default'] : 0;
        $columns[] = 'active';
        $values[]  = isset($data['active']) ? (int) (bool) $data['active'] : 1;
        if (array_key_exists('token_expires_at', $data)) {
            $columns[] = 'token_expires_at';
            $values[]  = $data['token_expires_at'] !== null
                ? "'" . $this->db->escape((string) $data['token_expires_at']) . "'"
                : 'NULL';
        }
        if (array_key_exists('refresh_token', $data)) {
            if ($data['refresh_token'] !== null) {
                // Story 51-1 : usage unique, jamais loggué en clair — alerte précoce de troncature varchar(512)
                $refreshTokenRaw = (string) $data['refresh_token'];
                if (strlen($refreshTokenRaw) > 500) {
                    $this->log('create() - refresh_token > 500 caractères, risque de troncature silencieuse par la colonne varchar(512)', LOG_WARNING);
                }
                $columns[] = 'refresh_token';
                $values[]  = "'" . $this->db->escape($refreshTokenRaw) . "'";
            } else {
                $columns[] = 'refresh_token';
                $values[]  = 'NULL';
            }
        }

        $columns[] = 'datec';
        $values[]  = "'" . $this->db->idate(dol_now()) . "'";

        $label = $data['label']; // pour les messages de log ci-dessous (non vide, déjà validé plus haut)

        $this->db->begin();

        $sql  = 'INSERT INTO ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' (' . implode(', ', $columns) . ')';
        $sql .= ' VALUES (' . implode(', ', $values) . ')';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('create() - Erreur INSERT boutique "' . $label . '": ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $dolibarrStoreId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'doli2shop_stores');
        $this->db->commit();

        $this->log('create() - Boutique créée rowid=' . $dolibarrStoreId . ' label="' . $label . '"', LOG_INFO);

        // Epic 47, Story 47-5 : créer les catégories Dolibarr typées pour la boutique (non bloquant)
        try {
            $storeForCategories = $this->fetch((int) $dolibarrStoreId);
            if ($storeForCategories !== null) {
                $categoryHelper = $this->buildStoreCategoryHelper();
                $categoryHelper->ensureStoreCategories($storeForCategories);
            }
        } catch (\Throwable $e) {
            // \Throwable (pas seulement Exception) : une constante de classe Categorie::TYPE_*
            // absente déclenche une Error fatale PHP, jamais rattrapée par catch (Exception) —
            // hotfix 2.4.5 (Nicolas Graillon 2026-07-28, HTTP 500 sur oauth_receive.php +
            // transaction laissée ouverte). Ne PAS ajouter de rollback() ici : l'INSERT de la
            // boutique est déjà commité juste au-dessus, un rollback casserait une transaction validée.
            $this->log('create() - Erreur création catégories boutique (non bloquant): ' . $e->getMessage(), LOG_WARNING);
        }

        return (int) $dolibarrStoreId;
    }

    /**
     * Instancie le helper de catégories boutique.
     *
     * Extrait en méthode protégée (hotfix 2.4.5) pour permettre son remplacement par
     * un double de test simulant une \Error fatale (ex. constante Categorie::TYPE_*
     * absente) sans dépendre du comportement réel de Categorie — sert à vérifier que
     * create() n'importe jamais une telle erreur au-delà de son bloc catégories.
     *
     * @return StoreCategoryHelper
     * @since  2.4.5
     */
    protected function buildStoreCategoryHelper(): StoreCategoryHelper
    {
        return new StoreCategoryHelper($this->db, $this->entity);
    }

    /**
     * Récupère une boutique par son rowid (filtrée par entity).
     *
     * @param  int         $dolibarrStoreId Rowid de la boutique
     * @return object|null                  Objet boutique ou null si non trouvée
     */
    public function fetch(int $dolibarrStoreId): ?object
    {
        $sql  = 'SELECT rowid, entity, label, shop_domain, access_token, api_key, api_secret,';
        $sql .= '       location_id, fk_categorie, fk_categorie_order, fk_categorie_invoice, fk_categorie_proposal,';
        $sql .= '       is_default, active, license_status, license_checked, serial_number,';
        $sql .= '       token_expires_at, refresh_token, token_reconnect_required, datec, tms';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE rowid = ' . $dolibarrStoreId;
        $sql .= ' AND entity = ' . (int) $this->entity;

        // Review 3 couches (MEDIUM) : `fetch()` renvoie `null` aussi bien pour « ligne absente »
        // que pour « la requête a échoué ». update() doit distinguer les deux, sous peine de
        // logger « boutique inexistante » sur une simple panne SQL — précisément le diagnostic
        // trompeur que la story 58-3 corrige ailleurs. D'où ce drapeau interne.
        $this->lastFetchFailed = false;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->lastFetchFailed = true;
            $this->log('fetch() - Erreur requête rowid=' . $dolibarrStoreId . ': ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $row = $this->db->fetch_object($result);
        return $row ?: null;
    }

    /**
     * Met à jour une boutique existante.
     *
     * Contrat de retour (Story 58-3, AC2) :
     *  -  1 : ligne modifiée.
     *  -  0 : ligne EXISTANTE mais valeurs déjà identiques (no-op légitime). Le driver mysqli de
     *         Dolibarr se connecte SANS `CLIENT_FOUND_ROWS` (cf. `core/db/mysqli.class.php`) :
     *         `affected_rows()` compte les lignes CHANGÉES, pas MATCHÉES. Reconnecter une boutique
     *         au même shop avec un token identique (scénario réel : Shopify renvoie souvent le même
     *         `access_token` tant que l'app n'est pas désinstallée) donne légitimement 0 — NE JAMAIS
     *         traiter ce cas comme une erreur, ce serait un faux positif bloquant des reconnexions
     *         valides.
     *  - -1 : erreur SQL sur l'UPDATE.
     *  - -2 : boutique inexistante ou hors entité — détecté via `fetch()` AVANT l'UPDATE (qui
     *         n'est alors PAS exécuté). Distinct de 0 : ici la ligne n'existe pas du tout.
     *
     * Tout appelant testant `< 0` traite déjà `-2` comme une erreur sans modification.
     *
     * @param  int   $dolibarrStoreId Rowid de la boutique à mettre à jour
     * @param  array $data            Données à modifier (seules les clés présentes sont mises à jour)
     * @return int                    1 = modifié, 0 = no-op légitime, -1 = erreur SQL, -2 = inexistante/hors entité
     * @since  2.5.0 Sentinelle -2 ajoutée (Story 58-3) — contrat étendu, voir ci-dessus
     */
    public function update(int $dolibarrStoreId, array $data): int
    {
        if (empty($data)) {
            return -1;
        }

        // AC2 (Story 58-3) : vérifier l'existence AVANT l'UPDATE plutôt que de réinterpréter
        // affected_rows()==0 (qui, sans CLIENT_FOUND_ROWS, ne distingue pas "rien à changer" de
        // "ligne absente" — cf. PHPDoc ci-dessus). fetch() filtre déjà par entity : id inexistant
        // et id hors entité produisent le même null, comme avant cette story.
        if ($this->fetch($dolibarrStoreId) === null) {
            if ($this->lastFetchFailed) {
                // La vérification elle-même a échoué : erreur SQL (-1), pas boutique absente (-2).
                $this->log('update() - Vérification d\'existence impossible pour rowid=' . $dolibarrStoreId . ' (erreur SQL), UPDATE non exécuté', LOG_ERR);
                return -1;
            }
            $this->log('update() - Boutique rowid=' . $dolibarrStoreId . ' inexistante ou hors entité (entity=' . (int) $this->entity . '), UPDATE non exécuté', LOG_WARNING);
            return -2;
        }

        $setClauses = array();

        if (isset($data['label'])) {
            $setClauses[] = "label = '" . $this->db->escape($data['label']) . "'";
        }
        if (isset($data['shop_domain'])) {
            $setClauses[] = "shop_domain = '" . $this->db->escape($data['shop_domain']) . "'";
        }
        if (array_key_exists('access_token', $data)) {
            $setClauses[] = 'access_token = ' . ($data['access_token'] !== null ? "'" . $this->db->escape($data['access_token']) . "'" : 'NULL');
        }
        if (array_key_exists('api_key', $data)) {
            $setClauses[] = 'api_key = ' . ($data['api_key'] !== null ? "'" . $this->db->escape($data['api_key']) . "'" : 'NULL');
        }
        if (array_key_exists('api_secret', $data)) {
            $setClauses[] = 'api_secret = ' . ($data['api_secret'] !== null ? "'" . $this->db->escape($data['api_secret']) . "'" : 'NULL');
        }
        if (array_key_exists('location_id', $data)) {
            $setClauses[] = 'location_id = ' . ($data['location_id'] !== null ? "'" . $this->db->escape($data['location_id']) . "'" : 'NULL');
        }
        if (array_key_exists('fk_categorie', $data)) {
            $setClauses[] = 'fk_categorie = ' . ($data['fk_categorie'] !== null ? (int) $data['fk_categorie'] : 'NULL');
        }
        if (array_key_exists('fk_categorie_order', $data)) {
            $setClauses[] = 'fk_categorie_order = ' . ($data['fk_categorie_order'] !== null ? (int) $data['fk_categorie_order'] : 'NULL');
        }
        if (array_key_exists('fk_categorie_invoice', $data)) {
            $setClauses[] = 'fk_categorie_invoice = ' . ($data['fk_categorie_invoice'] !== null ? (int) $data['fk_categorie_invoice'] : 'NULL');
        }
        if (array_key_exists('fk_categorie_proposal', $data)) {
            $setClauses[] = 'fk_categorie_proposal = ' . ($data['fk_categorie_proposal'] !== null ? (int) $data['fk_categorie_proposal'] : 'NULL');
        }
        if (isset($data['is_default'])) {
            $setClauses[] = 'is_default = ' . (int) (bool) $data['is_default'];
        }
        if (isset($data['active'])) {
            $setClauses[] = 'active = ' . (int) (bool) $data['active'];
        }
        if (isset($data['license_status'])) {
            $allowed = ['valid', 'invalid', 'unknown'];
            $ls = in_array($data['license_status'], $allowed, true) ? $data['license_status'] : 'unknown';
            $setClauses[] = "license_status = '" . $this->db->escape($ls) . "'";
        }
        if (array_key_exists('license_checked', $data)) {
            $setClauses[] = 'license_checked = ' . ($data['license_checked'] !== null ? "'" . $this->db->escape($data['license_checked']) . "'" : 'NULL');
        }
        if (array_key_exists('token_reconnect_required', $data)) {
            $setClauses[] = 'token_reconnect_required = ' . ((int) (bool) $data['token_reconnect_required']);
        }
        if (array_key_exists('token_expires_at', $data)) {
            $setClauses[] = 'token_expires_at = ' . ($data['token_expires_at'] !== null ? "'" . $this->db->escape((string) $data['token_expires_at']) . "'" : 'NULL');
        }
        if (array_key_exists('refresh_token', $data)) {
            // Story 51-1 : usage unique, jamais loggué en clair — alerte précoce de troncature varchar(512)
            if ($data['refresh_token'] !== null && strlen((string) $data['refresh_token']) > 500) {
                $this->log('update() - refresh_token > 500 caractères, risque de troncature silencieuse par la colonne varchar(512)', LOG_WARNING);
            }
            $setClauses[] = 'refresh_token = ' . ($data['refresh_token'] !== null ? "'" . $this->db->escape((string) $data['refresh_token']) . "'" : 'NULL');
        }
        if (array_key_exists('serial_number', $data)) {
            // Contrainte longueur 50 + escape ; NULL autorisé pour effacement
            $snVal = $data['serial_number'];
            if ($snVal !== null) {
                $snVal = substr((string) $snVal, 0, 50);
                $setClauses[] = "serial_number = '" . $this->db->escape($snVal) . "'";
            } else {
                $setClauses[] = 'serial_number = NULL';
            }
        }

        if (empty($setClauses)) {
            return -1;
        }

        $this->db->begin();

        $sql  = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' SET ' . implode(', ', $setClauses);
        $sql .= ' WHERE rowid = ' . $dolibarrStoreId;
        $sql .= ' AND entity = ' . (int) $this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('update() - Erreur UPDATE rowid=' . $dolibarrStoreId . ': ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($result);
        $this->db->commit();
        if ($affected <= 0) {
            // Story 58-3 : l'existence est désormais garantie (vérifiée ci-dessus AVANT l'UPDATE) —
            // ce cas est un no-op légitime (valeurs déjà identiques), PAS une anomalie.
            $this->log('update() - Aucune ligne modifiée pour rowid=' . $dolibarrStoreId . ' (entity=' . (int) $this->entity . ' : valeurs déjà identiques, no-op légitime)', LOG_DEBUG);
            return 0;
        }

        // MEDIUM-4 : si la boutique devient boutique par défaut (is_default=1), purger ses overrides
        // per-store — la nouvelle défaut lit désormais les constantes globales (cohérent P1).
        // Non bloquant : un échec de purge n'annule pas la mise à jour principale.
        if (isset($data['is_default']) && (int) (bool) $data['is_default'] === 1) {
            require_once dirname(__FILE__) . '/storesettings.class.php';
            $sqlPurge  = 'DELETE FROM ' . MAIN_DB_PREFIX . 'doli2shop_store_settings';
            $sqlPurge .= ' WHERE entity = ' . (int) $this->entity;
            $sqlPurge .= ' AND fk_store = ' . (int) $dolibarrStoreId;
            $resPurge = $this->db->query($sqlPurge);
            if (!$resPurge) {
                $this->log(
                    'update() - WARNING : purge overrides per-store échouée pour rowid=' . $dolibarrStoreId . ' : ' . $this->db->lasterror(),
                    LOG_WARNING
                );
            } else {
                $purgedCount = $this->db->affected_rows($resPurge);
                StoreSettings::clearCache($dolibarrStoreId);
                $this->log(
                    'update() - Boutique rowid=' . $dolibarrStoreId . ' promue défaut : ' . $purgedCount . ' override(s) per-store purgé(s)',
                    LOG_INFO
                );
            }
        }

        $this->log('update() - Boutique mise à jour rowid=' . $dolibarrStoreId, LOG_INFO);
        return 1;
    }

    /**
     * Supprime une boutique.
     *
     * @param  int $dolibarrStoreId Rowid de la boutique à supprimer
     * @return int                  1 si OK, -1 en cas d'erreur
     */
    public function delete(int $dolibarrStoreId): int
    {
        $this->db->begin();

        $sql  = 'DELETE FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE rowid = ' . $dolibarrStoreId;
        $sql .= ' AND entity = ' . (int) $this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('delete() - Erreur DELETE rowid=' . $dolibarrStoreId . ': ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($result);
        $this->db->commit();
        if ($affected <= 0) {
            $this->log('delete() - Aucune boutique supprimée pour rowid=' . $dolibarrStoreId . ' (entity=' . (int) $this->entity . ' : id inexistant ou hors entité)', LOG_WARNING);
            return 0;
        }
        $this->log('delete() - Boutique supprimée rowid=' . $dolibarrStoreId, LOG_INFO);
        return 1;
    }

    /**
     * Retourne toutes les boutiques de l'entité.
     *
     * @param  bool  $onlyActive Si true, filtre uniquement les boutiques actives
     * @return array             Tableau d'objets boutique (peut être vide)
     */
    public function getAll(bool $onlyActive = false): array
    {
        $sql  = 'SELECT rowid, entity, label, shop_domain, access_token, api_key, api_secret,';
        $sql .= '       location_id, fk_categorie, fk_categorie_order, fk_categorie_invoice, fk_categorie_proposal,';
        $sql .= '       is_default, active, license_status, license_checked, serial_number,';
        $sql .= '       token_expires_at, refresh_token, token_reconnect_required, datec, tms';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE entity = ' . (int) $this->entity;
        if ($onlyActive) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY is_default DESC, label ASC';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('getAll() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return array();
        }

        $stores = array();
        while ($row = $this->db->fetch_object($result)) {
            $stores[] = $row;
        }
        return $stores;
    }

    /**
     * Retourne la boutique par défaut de l'entité (is_default=1).
     *
     * @return object|null Objet boutique par défaut, ou null si aucune
     */
    public function getDefault(): ?object
    {
        $sql  = 'SELECT rowid, entity, label, shop_domain, access_token, api_key, api_secret,';
        $sql .= '       location_id, fk_categorie, fk_categorie_order, fk_categorie_invoice, fk_categorie_proposal,';
        $sql .= '       is_default, active, license_status, license_checked, serial_number,';
        $sql .= '       token_expires_at, refresh_token, token_reconnect_required, datec, tms';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE entity = ' . (int) $this->entity;
        $sql .= ' AND is_default = 1';
        $sql .= ' ORDER BY rowid ASC LIMIT 1';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('getDefault() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $row = $this->db->fetch_object($result);
        return $row ?: null;
    }

    /**
     * Retourne la boutique correspondant au shop_domain donné (filtrée par entity).
     *
     * Utilisée par le routage webhooks : recherche sur TOUS les statuts active/inactive
     * (un webhook légitime d'une boutique connue doit être traité même si inactive).
     *
     * @param  string      $domain Shop domain (ex: "mystore.myshopify.com")
     * @return object|null         Objet boutique ou null si aucune boutique ne correspond
     */
    public function getByShopDomain(string $domain): ?object
    {
        if (empty($domain)) {
            $this->log('getByShopDomain() - domain vide, retour null', LOG_DEBUG);
            return null;
        }

        // Les domaines Shopify sont en minuscules ; normaliser pour un match robuste
        $domain = strtolower(trim($domain));

        $sql  = 'SELECT rowid, entity, label, shop_domain, access_token, api_key, api_secret,';
        $sql .= '       location_id, fk_categorie, fk_categorie_order, fk_categorie_invoice, fk_categorie_proposal,';
        $sql .= '       is_default, active, license_status, license_checked, serial_number,';
        $sql .= '       token_expires_at, refresh_token, token_reconnect_required, datec, tms';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE entity = ' . (int) $this->entity;
        $sql .= " AND LOWER(shop_domain) = '" . $this->db->escape($domain) . "'";
        $sql .= ' LIMIT 1';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('getByShopDomain() - Erreur requête domain="' . $domain . '": ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $row = $this->db->fetch_object($result);
        $this->db->free($result);
        return $row ?: null;
    }

    /**
     * Résout la boutique à utiliser pour router un webhook entrant.
     *
     * Triple fallback (invariant rétrocompat absolu) :
     * 1. shop_domain connu → boutique correspondante (tout statut active)
     * 2. shop_domain inconnu ou null → boutique par défaut de l'entité
     * 3. Pas de boutique par défaut → null (chemin entité/constantes = comportement historique)
     *
     * Loggue le niveau de fallback pour traçabilité.
     *
     * @param  string|null $shopDomain Shop domain reçu dans X-Shopify-Shop-Domain
     * @return object|null             Objet boutique (null = chemin historique entité/constantes)
     */
    public function resolveStoreForRouting(?string $shopDomain): ?object
    {
        // Niveau 1 : shop_domain connu → sa boutique
        if (!empty($shopDomain)) {
            $store = $this->getByShopDomain($shopDomain);
            if ($store !== null) {
                $this->log(
                    'resolveStoreForRouting() - shop_domain="' . $shopDomain . '" résolu vers boutique rowid=' . (int) ($store->rowid ?? 0) . ' label="' . ($store->label ?? '') . '"',
                    LOG_DEBUG
                );
                return $store;
            }
            // shop_domain non trouvé en table → fallback boutique défaut (niveau 2)
            $this->log(
                'resolveStoreForRouting() - shop_domain="' . $shopDomain . '" non trouvé en table, fallback boutique défaut (warning)',
                LOG_WARNING
            );
        } else {
            $this->log(
                'resolveStoreForRouting() - shop_domain null/vide, fallback boutique défaut',
                LOG_DEBUG
            );
        }

        // Niveau 2 : boutique par défaut de l'entité
        $defaultStore = $this->getDefault();
        if ($defaultStore !== null) {
            $this->log(
                'resolveStoreForRouting() - Fallback boutique défaut rowid=' . (int) ($defaultStore->rowid ?? 0) . ' label="' . ($defaultStore->label ?? '') . '"',
                LOG_INFO
            );
            return $defaultStore;
        }

        // Niveau 3 : pas de boutique → chemin historique entité/constantes
        $this->log(
            'resolveStoreForRouting() - Aucune boutique disponible (pas de boutique défaut), chemin entité/constantes (warning)',
            LOG_WARNING
        );
        return null;
    }

    /**
     * Compte le nombre de boutiques de l'entité.
     *
     * @return int Nombre de boutiques (0 si aucune ou erreur)
     */
    public function countStores(): int
    {
        $sql  = 'SELECT COUNT(*) as nb';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE entity = ' . (int) $this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('countStores() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return 0;
        }

        $row = $this->db->fetch_object($result);
        return (int) ($row->nb ?? 0);
    }

    /**
     * Compte le nombre TOTAL de boutiques déclarées (actives ou non) de l'entité, en distinguant
     * EXPLICITEMENT « aucune boutique » (retour 0) d'une erreur SQL (retour null) — contrairement
     * à getAll()/countStores() qui renvoient tous les deux un résultat "vide" (tableau vide / 0)
     * dans les deux cas. Utilisée par ShopifyStockTrigger pour ne JAMAIS retomber sur le chemin
     * legacy global sur une erreur SQL indéterminée (Story 52-1 review passe 2, MEDIUM-1).
     *
     * @return int|null Nombre de boutiques (>= 0) sur succès, null si erreur SQL. L'appelant ne
     *                   doit JAMAIS interpréter null comme "0 boutique" (pas de fallback legacy).
     */
    public function countAllOrNull(): ?int
    {
        $sql  = 'SELECT COUNT(*) as nb';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= ' WHERE entity = ' . (int) $this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('countAllOrNull() - Erreur requête: ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $row = $this->db->fetch_object($result);
        return (int) ($row->nb ?? 0);
    }

    // =========================================================================
    // LICENCE PAR BOUTIQUE (Story 47-6)
    // =========================================================================

    /**
     * Met à jour le statut de licence d'une boutique et horodate la vérification.
     *
     * Valeurs autorisées pour $status : 'valid', 'invalid', 'unknown'.
     * Toute autre valeur est normalisée à 'unknown'.
     *
     * @param  int    $storeId Rowid de la boutique
     * @param  string $status  Nouveau statut licence ('valid'|'invalid'|'unknown')
     * @return int             1 si OK, 0 si boutique non trouvée/hors entité, -1 en cas d'erreur
     */
    public function setLicenseStatus(int $storeId, string $status): int
    {
        $allowed = ['valid', 'invalid', 'unknown'];
        $safeStatus = in_array($status, $allowed, true) ? $status : 'unknown';

        $this->db->begin();

        $sql  = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= " SET license_status = '" . $this->db->escape($safeStatus) . "'";
        $sql .= ', license_checked = NOW()';
        $sql .= ' WHERE rowid = ' . (int) $storeId;
        $sql .= ' AND entity = ' . (int) $this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('setLicenseStatus() - Erreur UPDATE rowid=' . $storeId . ': ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($result);
        $this->db->commit();

        if ($affected <= 0) {
            $this->log('setLicenseStatus() - Boutique rowid=' . $storeId . ' non trouvée ou hors entité', LOG_DEBUG);
            return 0;
        }

        $this->log('setLicenseStatus() - Statut licence rowid=' . $storeId . ' mis à jour → ' . $safeStatus, LOG_DEBUG);
        return 1;
    }

    /**
     * Positionne/lève le drapeau "reconnexion Shopify requise" d'une boutique (Story 51-1).
     *
     * Champ DÉDIÉ explicite (distinct de license_status, sémantique différente) : positionné
     * à 1 quand le refresh automatique du token OAuth échoue DÉFINITIVEMENT (401 persistant
     * après retry en T5, ou échec de persistance après consommation du refresh token
     * usage-unique en T4). Remis à 0 automatiquement dès qu'un refresh réussit.
     *
     * N'A JAMAIS pour effet de désactiver la boutique (invariant Epic 47 : la boutique par
     * défaut n'est jamais bloquée automatiquement) — signal purement informatif pour l'admin.
     *
     * @param  int  $storeId  Rowid de la boutique
     * @param  bool $required true = reconnexion requise, false = résolu (reset après refresh OK)
     * @return int             Forward direct de update() (Story 58-3) : 1 = OK, 0 = no-op
     *                         légitime, -1 = erreur SQL, -2 = boutique non trouvée/hors entité.
     *                         Best-effort : la valeur de retour n'est volontairement pas
     *                         exploitée par l'appelant (cf. shopifyapi.class.php).
     * @since  2.4.1
     */
    public function setReconnectRequired(int $storeId, bool $required): int
    {
        return $this->update($storeId, ['token_reconnect_required' => $required]);
    }

    /**
     * Enregistre le numéro de série DoliStore pour une boutique.
     *
     * Valide le format SI-YYYY-XXXX-XXXX avant toute écriture.
     * Le serial N'EST JAMAIS loggué (donnée sensible).
     *
     * @param  int    $storeId Rowid de la boutique
     * @param  string $serial  Numéro de série (format SI-YYYY-[A-Z0-9]{4}-[A-Z0-9]{4})
     * @return int             1 si OK, 0 si boutique non trouvée/hors entité, -1 en cas d'erreur
     * @since  2.4.0
     */
    public function setSerialNumber(int $storeId, string $serial): int
    {
        // Validation stricte du format SI-YYYY-XXXX-XXXX
        if (!preg_match('/^SI-\d{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $serial)) {
            $this->log('setSerialNumber() - Format invalide pour rowid=' . $storeId, LOG_WARNING);
            return -1;
        }

        // Contrainte longueur + escape (serial jamais dans les logs)
        $escapedSerial = $this->db->escape(substr($serial, 0, 50));

        $this->db->begin();

        $sql  = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_stores';
        $sql .= " SET serial_number = '" . $escapedSerial . "'";
        $sql .= ' WHERE rowid = ' . (int) $storeId;
        $sql .= ' AND entity = ' . (int) $this->entity;

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('setSerialNumber() - Erreur UPDATE rowid=' . $storeId . ': ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $affected = $this->db->affected_rows($result);
        $this->db->commit();

        if ($affected <= 0) {
            $this->log('setSerialNumber() - Boutique rowid=' . $storeId . ' non trouvée ou hors entité', LOG_DEBUG);
            return 0;
        }

        $this->log('setSerialNumber() - Numéro de série enregistré pour rowid=' . $storeId, LOG_DEBUG);
        return 1;
    }

    // =========================================================================
    // SEEDING boutique par défaut
    // =========================================================================

    /**
     * Crée la boutique par défaut depuis les constantes DOLI2SHOP_* si aucune boutique n'existe.
     *
     * Idempotent : si au moins une boutique existe pour l'entité, ne fait rien (return 0).
     * Si les constantes DOLI2SHOP_STORE_HOSTNAME et DOLI2SHOP_ACCESS_TOKEN sont vides,
     * ne crée pas de boutique (install fraîche sans configuration — return 0 sans erreur).
     *
     * @return int 1 si une boutique par défaut a été créée, 0 si no-op, -1 si erreur
     */
    public function ensureDefaultStore(): int
    {
        // Idempotence : ne rien faire si au moins une boutique existe déjà
        if ($this->countStores() > 0) {
            $this->log('ensureDefaultStore() - Boutiques déjà présentes (entity=' . $this->entity . '), pas de seeding', LOG_DEBUG);
            return 0;
        }

        // Lecture des constantes de connexion (jamais loggées)
        $shopHostname = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME');
        $accessToken  = getDolGlobalString('DOLI2SHOP_ACCESS_TOKEN');

        // Sans hostname ET access_token, pas de seeding (install fraîche non configurée)
        if (empty($shopHostname) || empty($accessToken)) {
            $this->log('ensureDefaultStore() - Constantes DOLI2SHOP_STORE_HOSTNAME/DOLI2SHOP_ACCESS_TOKEN absentes, pas de seeding', LOG_DEBUG);
            return 0;
        }

        $apiKey      = getDolGlobalString('DOLI2SHOP_API_KEY');
        $apiSecret   = getDolGlobalString('DOLI2SHOP_API_SECRET_KEY');
        $locationId  = getDolGlobalString('DOLI2SHOP_LOCATION_ID');
        $fkCategorie = getDolGlobalString('DOLI2SHOP_DOLIBARR_PROCATE');

        $data = array(
            'label'        => 'Boutique par défaut',
            'shop_domain'  => $shopHostname,
            'access_token' => $accessToken,
            'api_key'      => $apiKey,
            'api_secret'   => $apiSecret,
            'location_id'  => $locationId ?: null,
            'fk_categorie' => $fkCategorie ? (int) $fkCategorie : null,
            'is_default'   => 1,
            'active'       => 1,
        );

        $dolibarrStoreId = $this->create($data);
        if ($dolibarrStoreId <= 0) {
            $this->log('ensureDefaultStore() - Échec création boutique par défaut (entity=' . $this->entity . ')', LOG_ERR);
            return -1;
        }

        $this->log('ensureDefaultStore() - Boutique par défaut créée rowid=' . $dolibarrStoreId . ' (entity=' . $this->entity . ')', LOG_INFO);

        // Epic 47, Story 47-5 : les catégories sont déjà gérées par create() → StoreService::create()
        // appelle ensureStoreCategories() en interne (non bloquant).
        // Note : pour la boutique par défaut dont fk_categorie = PROCATE, le tag produit est
        // intentionnellement skippé (rétrocompat mono-boutique — AC #5/#6).

        return 1;
    }

    // =========================================================================
    // BACKFILL fk_store (Epic 47-2)
    // =========================================================================

    /**
     * Backfille la colonne fk_store sur la table llx_doli2shop_webhooks.
     *
     * Assigne tous les webhooks de l'entité courante ayant fk_store = 0
     * à la boutique par défaut de l'entité.
     *
     * Idempotent : ne touche que les lignes où fk_store = 0.
     * No-op si aucune boutique par défaut n'existe pour l'entité.
     *
     * NOTE : Cette méthode doit être appelée APRÈS ensureDefaultStore() dans
     * modDoli2Shop::init(), car la migration SQL tourne avant le seeding.
     *
     * @return int Nombre de lignes mises à jour (0 si no-op), -1 en cas d'erreur
     * @since 2.3.5
     */
    public function backfillWebhooksStore(): int
    {
        $defaultStore = $this->getDefault();
        if ($defaultStore === null) {
            $this->log('backfillWebhooksStore() - Pas de boutique par défaut pour entity=' . $this->entity . ', backfill ignoré', LOG_DEBUG);
            return 0;
        }

        $defaultRowid = (int) ($defaultStore->rowid ?? 0);
        if ($defaultRowid <= 0) {
            $this->log('backfillWebhooksStore() - Boutique par défaut sans rowid valide (entity=' . $this->entity . '), backfill ignoré', LOG_WARNING);
            return 0;
        }

        $entity = (int) $this->entity;

        $sql  = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_webhooks';
        $sql .= ' SET fk_store = ' . $defaultRowid;
        $sql .= ' WHERE entity = ' . $entity;
        $sql .= ' AND fk_store = 0';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->log('backfillWebhooksStore() - Erreur UPDATE webhooks: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        $updated = (int) $this->db->affected_rows($result);

        $this->log(
            'backfillWebhooksStore() - Backfill webhooks terminé entity=' . $entity
            . ' fk_store=' . $defaultRowid
            . ' lignes mises à jour=' . $updated,
            $updated > 0 ? LOG_INFO : LOG_DEBUG
        );

        return $updated;
    }

    /**
     * Complète la config de la boutique par DÉFAUT existante depuis les constantes globales.
     *
     * Les installations qui ont seedé la boutique par défaut AVANT que la liaison catégorie /
     * entrepôt soit gérée (ou avec la constante encore vide au moment du seed) gardent une
     * boutique par défaut dont fk_categorie / location_id sont NULL — jamais re-remplis car
     * ensureDefaultStore() est idempotent (no-op dès qu'une boutique existe). C'est le symptôme
     * « catégorie produit non liée » signalé après migration (Story 48-3).
     *
     * Rattrape ces champs SANS JAMAIS écraser une valeur déjà renseignée :
     * - fk_categorie <- DOLI2SHOP_DOLIBARR_PROCATE (si store.fk_categorie vide et constante définie)
     * - location_id  <- DOLI2SHOP_LOCATION_ID      (si store.location_id vide et constante définie)
     *
     * Idempotent : ne met à jour que les champs vides → no-op aux init() suivants.
     * No-op si aucune boutique par défaut.
     *
     * @return int 1 si la boutique par défaut a été complétée, 0 si rien à faire, -1 en cas d'erreur
     * @since 2.3.6
     */
    public function backfillDefaultStoreConfig(): int
    {
        $defaultStore = $this->getDefault();
        if ($defaultStore === null) {
            $this->log('backfillDefaultStoreConfig() - Pas de boutique par défaut (entity=' . $this->entity . '), no-op', LOG_DEBUG);
            return 0;
        }

        $defaultRowid = (int) ($defaultStore->rowid ?? 0);
        if ($defaultRowid <= 0) {
            $this->log('backfillDefaultStoreConfig() - Boutique par défaut sans rowid valide (entity=' . $this->entity . '), no-op', LOG_WARNING);
            return 0;
        }

        $patch = array();

        // fk_categorie (catégorie produit) <- PROCATE, uniquement si non déjà renseigné
        if (empty($defaultStore->fk_categorie)) {
            $procate = getDolGlobalString('DOLI2SHOP_DOLIBARR_PROCATE');
            if (!empty($procate)) {
                $patch['fk_categorie'] = (int) $procate;
            }
        }

        // location_id (entrepôt Shopify) <- LOCATION_ID, uniquement si non déjà renseigné
        if (empty($defaultStore->location_id)) {
            $locationId = getDolGlobalString('DOLI2SHOP_LOCATION_ID');
            if (!empty($locationId)) {
                $patch['location_id'] = $locationId;
            }
        }

        if (empty($patch)) {
            $this->log('backfillDefaultStoreConfig() - Boutique par défaut déjà complète ou constantes absentes (entity=' . $this->entity . '), no-op', LOG_DEBUG);
            return 0;
        }

        if ($this->update($defaultRowid, $patch) < 0) {
            $this->log('backfillDefaultStoreConfig() - Échec mise à jour boutique par défaut #' . $defaultRowid, LOG_ERR);
            return -1;
        }

        $this->log('backfillDefaultStoreConfig() - Boutique par défaut #' . $defaultRowid . ' complétée (' . implode(', ', array_keys($patch)) . ')', LOG_INFO);
        return 1;
    }

    /**
     * Backfille la colonne fk_store sur les tables techniques de mapping.
     *
     * Met à jour toutes les lignes de l'entité courante ayant fk_store = 0
     * en les assignant à la boutique par défaut de l'entité.
     *
     * Idempotent : ne touche que les lignes où fk_store = 0 (filtre explicit).
     * No-op si aucune boutique par défaut n'existe pour l'entité.
     *
     * Tables concernées : llx_doli2shop_products, llx_doli2shop_orders,
     * llx_doli2shop_inventory, llx_doli2shop_shipping_rules, llx_doli2shop_payments (Story 48-4).
     *
     * @return int Nombre total de lignes mises à jour (0 si no-op), -1 en cas d'erreur
     */
    public function backfillTechnicalTables(): int
    {
        $defaultStore = $this->getDefault();
        if ($defaultStore === null) {
            $this->log('backfillTechnicalTables() - Pas de boutique par défaut pour entity=' . $this->entity . ', backfill ignoré', LOG_DEBUG);
            return 0;
        }

        $defaultRowid = (int) ($defaultStore->rowid ?? 0);
        if ($defaultRowid <= 0) {
            // Garde défensive : une boutique par défaut sans rowid valide ne doit JAMAIS
            // servir de cible de backfill (sinon fk_store resterait à 0 → idempotence rompue,
            // backfill rejoué en boucle à chaque init()).
            $this->log('backfillTechnicalTables() - Boutique par défaut sans rowid valide (entity=' . $this->entity . '), backfill ignoré', LOG_WARNING);
            return 0;
        }

        $entity = (int) $this->entity;

        $this->db->begin();

        $totalUpdated = 0;

        // --- products ---
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_products';
        $sql .= ' SET fk_store = ' . $defaultRowid;
        $sql .= ' WHERE entity = ' . $entity;
        $sql .= ' AND fk_store = 0';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('backfillTechnicalTables() - Erreur UPDATE products: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $totalUpdated += (int) $this->db->affected_rows($result);

        // --- orders ---
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_orders';
        $sql .= ' SET fk_store = ' . $defaultRowid;
        $sql .= ' WHERE entity = ' . $entity;
        $sql .= ' AND fk_store = 0';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('backfillTechnicalTables() - Erreur UPDATE orders: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $totalUpdated += (int) $this->db->affected_rows($result);

        // --- inventory ---
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_inventory';
        $sql .= ' SET fk_store = ' . $defaultRowid;
        $sql .= ' WHERE entity = ' . $entity;
        $sql .= ' AND fk_store = 0';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('backfillTechnicalTables() - Erreur UPDATE inventory: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $totalUpdated += (int) $this->db->affected_rows($result);

        // --- shipping_rules (Story 48-4 : mappings expédition par boutique) ---
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_shipping_rules';
        $sql .= ' SET fk_store = ' . $defaultRowid;
        $sql .= ' WHERE entity = ' . $entity;
        $sql .= ' AND fk_store = 0';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('backfillTechnicalTables() - Erreur UPDATE shipping_rules: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $totalUpdated += (int) $this->db->affected_rows($result);

        // --- payments (Story 48-4 : mappings paiement par boutique) ---
        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'doli2shop_payments';
        $sql .= ' SET fk_store = ' . $defaultRowid;
        $sql .= ' WHERE entity = ' . $entity;
        $sql .= ' AND fk_store = 0';

        $result = $this->db->query($sql);
        if (!$result) {
            $this->db->rollback();
            $this->log('backfillTechnicalTables() - Erreur UPDATE payments: ' . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
        $totalUpdated += (int) $this->db->affected_rows($result);

        $this->db->commit();

        $this->log(
            'backfillTechnicalTables() - Backfill terminé entity=' . $entity
            . ' fk_store=' . $defaultRowid
            . ' lignes mises à jour=' . $totalUpdated,
            $totalUpdated > 0 ? LOG_INFO : LOG_DEBUG
        );

        return $totalUpdated;
    }
}
