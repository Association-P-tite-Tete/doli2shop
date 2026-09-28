<?php
/**
 * @file        class/shopifyproductimportcron.class.php
 * @brief       CRON job for importing products from Shopify to Dolibarr
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    cron
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.1.6
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/shopifyproductimporter.class.php';
require_once dirname(__FILE__) . '/storeservice.class.php';
require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/CronHelperTrait.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}


/**
 * CRON job class for importing products from Shopify to Dolibarr
 */
class ShopifyProductImportCron
{
    use LoggerTrait;
    use CronHelperTrait;

    /** @var DoliDB Database handler */
    private $db;

    /** @var User CRON user */
    private $user;

    /** @var string Last error message */
    public $error = '';

    /** @var array Error messages */
    public $errors = [];

    /** @var string Output message for CRON */
    public $output = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     * @param User $user CRON user
     */
    public function __construct($db, $cronUser = null)
    {
        global $user;

        $this->db = $db;
        $this->user = $cronUser ?? $user;
    }

    /**
     * Instancie StoreService pour l'entité donnée.
     * Méthode protégée pour permettre le mock en test.
     *
     * @param  int $entity Entité Dolibarr
     * @return StoreService
     */
    protected function createStoreService(int $entity): StoreService
    {
        return new StoreService($this->db, $entity);
    }

    /**
     * Run the CRON job — boucle sur entités + boutiques actives (AC #6, #7).
     * Rétrocompat : 0 boutique pour une entité → run unique entité (constantes).
     *
     * @param int $limit Maximum number of products to import per run
     * @return int 0 = success, >0 = error code
     */
    public function run($limit = 50)
    {
        global $conf;

        // v2.2.0: Libérer locks transactionnels avant toute lecture llx_const
        $this->checkAndUnfreezeCron(
            (int) ($conf->entity ?? 1),
            '/doli2shop/class/shopifyproductimportcron.class.php'
        );

        $this->log("Démarrage CRON import produits Shopify", LOG_INFO);

        // Check if CRON is enabled
        if (!getDolGlobalInt('DOLI2SHOP_ENABLE_PRODUCT_IMPORT_CRON')) {
            $this->log("CRON import produits désactivé", LOG_INFO);
            $this->output = 'CRON disabled';
            return 0;
        }

        // Get all entities to process
        $entities = $this->getEntitiesToProcess();

        if (empty($entities)) {
            $this->log("Aucune entité à traiter", LOG_INFO);
            $this->output = 'No entities to process';
            return 0;
        }

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($entities as $entity) {
            $this->log("Traitement entité: $entity", LOG_DEBUG);

            // Récupérer les boutiques actives de cette entité
            $storeService = $this->createStoreService($entity);
            $stores = $storeService->getAll(true);

            if (empty($stores)) {
                // Rétrocompat : 0 boutique → run unique sur entité/constantes
                $this->log("Aucune boutique active pour entité $entity — fallback run entité unique (retrocompat)", LOG_INFO);
                $stores = [null]; // Sentinelle : null = chemin historique
            } else {
                $this->log("Entité $entity : " . count($stores) . " boutique(s) active(s) à traiter", LOG_INFO);
            }

            foreach ($stores as $store) {
                $storeLabel = ($store !== null) ? ($store->shop_domain ?? ('store#' . $store->rowid)) : 'entity-fallback';

                // AC#10 : boutique sans credentials → warning + continue
                if ($store !== null && (empty($store->access_token) || empty($store->shop_domain))) {
                    $this->log("[WARN] Boutique sans credentials (shop_domain=" . $storeLabel . "), ignorée", LOG_WARNING);
                    continue;
                }

                // Story 47-6, gate étendue par licence-gate-des-ecritures-sortantes (AC1) : gate
                // licence par boutique, désormais réel aussi pour la boutique par défaut.
                // $store === null = chemin rétrocompat (fallback entité) → jamais bloqué (inchangé).
                // Boutique par défaut (is_default=1) → autorisée tant qu'aucune date d'arrêt
                // signée n'a été reçue du serveur (cf. doli2shopEvaluateDefaultStoreSyncGate()).
                if ($store !== null) {
                    $licenceCheck = doli2shopStoreSyncAllowed($store);
                    if (!$licenceCheck['allowed']) {
                        // Blind Hunter (licence-gate-des-ecritures-sortantes) : la boutique par
                        // défaut ne doit JAMAIS être écrite en DB par ce CRON — invariant 49-8 —
                        // y compris ici. Avant cette story cette branche était morte pour is_default
                        // (toujours allowed=true) ; elle est désormais atteignable
                        // (sync_gate_active / failopen_expired), et écrivait 'invalid' sans garde,
                        // y compris quand le blocage vient d'une panne de NOTRE serveur
                        // (failopen_expired) et non d'une licence réellement invalide.
                        if (empty($store->is_default)) {
                            $storeService->setLicenseStatus((int) $store->rowid, 'invalid');
                        }
                        $this->log("[WARN] Boutique " . $storeLabel . " ignorée (license " . $licenceCheck['reason'] . ")", LOG_WARNING);
                        $this->output .= "\n[WARN] Store " . $storeLabel . " skipped (license " . $licenceCheck['reason'] . ")";
                        continue;
                    }
                    // Story 49-8 fix A3, adapté par licence-gate-des-ecritures-sortantes : boutique
                    // par défaut → ne PAS écraser le statut licence en DB via ce CRON. ⚠️ Avant
                    // cette story, `reason` valait toujours 'default_store_exempt' pour la boutique
                    // par défaut (exemption inconditionnelle) ; ce n'est plus vrai — le gate réel
                    // renvoie désormais 'no_sync_gate_date'/'within_grace_period'/'sync_gate_active'/
                    // 'failopen_*' selon l'état. Tester `is_default` (plutôt que `reason`) préserve
                    // le comportement voulu par 49-8 quel que soit le vocabulaire de reason retourné :
                    // le statut réel n'est écrit QUE via le bouton « Vérifier maintenant »
                    // (admin/stores.php action=verify_license).
                    if (empty($store->is_default)) {
                        $storeService->setLicenseStatus((int) $store->rowid, $licenceCheck['status']);
                    }
                }

                try {
                    // Create importer for this entity + store
                    $importer = new ShopifyProductImporter($this->db, $this->user, $entity, $store);

                    // Check if import is enabled for this entity
                    if (!$importer->isImportEnabled()) {
                        $this->log("Import désactivé pour entité $entity / boutique $storeLabel", LOG_DEBUG);
                        continue;
                    }

                    // Get cursor from last run (stored in constant, indexed by entity ET boutique
                    // pour éviter qu'une boutique écrase le curseur de pagination d'une autre)
                    $cursor = $this->getLastCursor($entity, $store);

                    // Run import
                    $result = $importer->importProducts($limit, $cursor, true);

                    if ($result['success']) {
                        $stats = $result['stats'];
                        $totalCreated += $stats['created'];
                        $totalUpdated += $stats['updated'];
                        $totalSkipped += $stats['skipped'];
                        $totalErrors += $stats['errors'];

                        // Save cursor for next run
                        if ($result['hasNextPage'] && $result['endCursor']) {
                            $this->saveLastCursor($entity, $store, $result['endCursor']);
                        } else {
                            // All products processed, reset cursor
                            $this->saveLastCursor($entity, $store, '');
                        }
                    } else {
                        $this->errors[] = "Entity $entity / store $storeLabel: " . $result['message'];
                        $totalErrors++;
                    }

                } catch (Exception $e) {
                    // AC#10 : boutique en erreur → log + continue
                    $this->log("Erreur entité $entity / boutique $storeLabel: " . $e->getMessage() . " — continuing", LOG_WARNING);
                    $this->errors[] = "Entity $entity / store $storeLabel: " . $e->getMessage();
                    $totalErrors++;
                }
            }
        }

        // Build output message
        $this->output = sprintf(
            "Import terminé - Créés: %d, Mis à jour: %d, Ignorés: %d, Erreurs: %d",
            $totalCreated,
            $totalUpdated,
            $totalSkipped,
            $totalErrors
        );

        $this->log($this->output, LOG_INFO);

        // Return error code if any errors
        if ($totalErrors > 0) {
            $this->error = implode('; ', $this->errors);
            return 1;
        }

        return 0;
    }

    /**
     * Get list of entities to process
     *
     * @return array List of entity IDs
     */
    private function getEntitiesToProcess()
    {
        global $conf;

        // If single entity mode, just return current entity
        if (empty($conf->multicompany->enabled)) {
            return [$conf->entity];
        }

        // Multi-entity: get all entities with module enabled
        $entities = [];

        $sql = "SELECT DISTINCT entity FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = 'MAIN_MODULE_SHOPIFYINTEGRATION'";
        $sql .= " AND value = '1'";

        $result = $this->db->query($sql);

        if ($result) {
            while ($obj = $this->db->fetch_object($result)) {
                $entities[] = (int)$obj->entity;
            }
        }

        // Fallback to current entity
        if (empty($entities)) {
            $entities[] = $conf->entity;
        }

        return $entities;
    }

    /**
     * Nom de la constante curseur, indexée par boutique.
     * Chemin historique (store null) → nom inchangé (rétrocompat : le curseur existant est préservé).
     * Boutique précise → suffixe rowid pour isoler la pagination de chaque boutique.
     *
     * @param  object|null $store Objet boutique (null = fallback entité)
     * @return string             Nom de la constante
     */
    private function cursorConstName($store)
    {
        if ($store !== null && !empty($store->rowid)) {
            return 'DOLI2SHOP_PRODUCT_IMPORT_CURSOR_' . (int) $store->rowid;
        }
        return 'DOLI2SHOP_PRODUCT_IMPORT_CURSOR';
    }

    /**
     * Get last pagination cursor for entity + store
     *
     * @param int         $entity Entity ID
     * @param object|null $store  Boutique (null = fallback entité)
     * @return string|null Cursor or null
     */
    private function getLastCursor($entity, $store = null)
    {
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = '" . $this->db->escape($this->cursorConstName($store)) . "'";
        $sql .= " AND entity = " . (int)$entity;

        $result = $this->db->query($sql);

        if ($result && $this->db->num_rows($result) > 0) {
            $obj = $this->db->fetch_object($result);
            return !empty($obj->value) ? $obj->value : null;
        }

        return null;
    }

    /**
     * Save pagination cursor for next run (per entity + store)
     *
     * @param int         $entity Entity ID
     * @param object|null $store  Boutique (null = fallback entité)
     * @param string      $cursor Cursor value
     * @return void
     */
    private function saveLastCursor($entity, $store, $cursor)
    {
        dolibarr_set_const(
            $this->db,
            $this->cursorConstName($store),
            $cursor,
            'chaine',
            0,
            '',
            $entity
        );
    }
}
