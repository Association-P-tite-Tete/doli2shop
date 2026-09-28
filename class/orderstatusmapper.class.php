<?php
/**
 * Copyright (C) 2022-2025 P'tite Tête <doli2shop@ptitetete.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
/**
 * \file       class/orderstatusmapper.class.php
 * \ingroup    doli2shop
 * \brief      Class for converting order statuses between Shopify and Dolibarr
 */

dol_include_once('/commande/class/commande.class.php');
require_once __DIR__ . '/LoggerTrait.php';
require_once __DIR__ . '/sqlutils.class.php';

/**
 * Class OrderStatusMapper
 */
class OrderStatusMapper
{
    use LoggerTrait;
    
    /**
     * Database handler
     * @var DoliDB
     */
    private $db;
    
    /**
     * Entity ID
     * @var int
     */
    private $entity;
    
    /**
     * Store ID
     * @var int
     */
    private $storeId;
    
    /**
     * Cache for Shopify -> Dolibarr mappings
     * @var array
     */
    private $shopifyToDolibarrCache = null;
    
    /**
     * Cache for Dolibarr -> Shopify mappings
     * @var array
     */
    private $dolibarrToShopifyCache = null;

    /**
     * Correspondances de statut de commande par défaut, Shopify → Dolibarr (Story
     * restaurer-les-correspondances-de-statut-vide-les-tables, AC1/AC2/AC7).
     *
     * Remplace la (fausse) lecture de `sql/update_2.1.0_add_order_status_mapping.sql` : ce
     * fichier n'a jamais existé dans le dépôt, et aucun fichier `llx_*.sql` ne pose de valeur par
     * défaut pour ces tables (uniquement des `CREATE TABLE`) — le Validate du 09/09/2026 l'a
     * confirmé. Source de vérité UNIQUE, partagée entre le seeding d'installation
     * (`ensureDefaultMappings()`, appelé depuis `modDoli2Shop::init()`) et l'action
     * `restore_defaults` de `admin/orderstatusmapping.php`.
     *
     * Codes `dolibarr_status` alignés sur les VRAIES constantes de `commande.class.php`, pour
     * Dolibarr 19+ à 23+ : `Commande::STATUS_CANCELED` (-1), `STATUS_DRAFT` (0),
     * `STATUS_VALIDATED` (1), `STATUS_SHIPMENTONPROCESS` (2), `STATUS_CLOSED` (3 — libellé
     * Dolibarr natif « StatusOrderDelivered », cf. `Commande::LibStatut()`). Il n'existe PAS de
     * `Commande::STATUS_DELIVERED` distinct dans le Dolibarr réellement ciblé par le module : ni
     * `commande/class/commande.class.php` (core), ni `stubs/dolibarr.stub`, ni
     * `test/bootstrap.php` ne déclarent une telle constante — seules les 5 ci-dessus existent.
     * `docs/features/ORDER_STATUS_MAPPING.md` et l'ancienne vue de `admin/orderstatusmapping.php`
     * décrivaient un schéma à 6 statuts (0..5, avec un `STATUS_DELIVERED` et un `STATUS_CLOSED`
     * distincts) qui n'a jamais existé dans un Dolibarr réel : recopier ces valeurs aurait
     * déplacé le défaut plutôt que de le corriger (AC7).
     *
     * Revue finale du train 2.5.3, point 1 — les 8 lignes initiales ne couvraient que 4 des 8
     * valeurs réelles de l'enum Shopify `OrderFinancialStatus` (liste exhaustive confirmée dans
     * `class/shopifyordermanager.class.php` ~ligne 741-742 et `admin/orderstatusmapping.php`
     * ~ligne 357-366) : il manquait `AUTHORIZED`, `EXPIRED`, `PARTIALLY_REFUNDED`, `REFUNDED`.
     * Une commande `REFUNDED` + `FULFILLED` ne trouvait alors aucune correspondance (ni exacte,
     * ni financial-only) et retombait sur le fallback fulfillment-only (ligne PAID/FULFILLED) →
     * classée `STATUS_CLOSED`, comme une commande normalement honorée. Correspondances ajoutées,
     * décision du mainteneur du 09/09/2026 :
     * - `REFUNDED`  → `STATUS_CANCELED` (remboursement intégral : annulée, jamais « clôturée »)
     * - `EXPIRED`   → `STATUS_CANCELED` (autorisation expirée sans capture : la vente n'a pas eu lieu)
     * - `AUTHORIZED` → `STATUS_VALIDATED` (paiement autorisé non capturé : commande ferme, pas
     *   encore réglée) — une seule ligne (`UNFULFILLED`), le statut de livraison ne change rien à
     *   ce statut Dolibarr, cf. le fallback financial-only de `mapShopifyToDolibarr()`.
     * - `PARTIALLY_REFUNDED` → **selon le statut de livraison**, comme `PAID` aujourd'hui : un
     *   remboursement partiel n'annule pas la commande. Trois lignes explicites reproduisant
     *   exactement les couples `PAID` (`UNFULFILLED`/`PARTIALLY_FULFILLED`/`FULFILLED`) — jamais
     *   laissé au fallback, c'est justement le fallback qui produisait le défaut.
     * `PENDING` et `VOIDED` étaient déjà couverts avant ce correctif : aucun changement sur eux.
     *
     * @var array<int, array{shopify_financial_status: string, shopify_fulfillment_status: string, dolibarr_status: int, default_mapping: int, priority: int, description: string}>
     */
    private const DEFAULT_SHOP2DOL_MAPPINGS = [
        // NOTE (revue finale 2.5.3, point 2) : `priority` vaut 10 sur TOUTES les lignes — ce champ
        // ne différencie donc RIEN entre les défauts. La levée d'ambiguïté du fallback
        // "financial status seul" (mapShopifyToDolibarr(), 2e boucle) repose ENTIÈREMENT sur
        // l'ORDRE DE CE TABLEAU (= ordre d'insertion = rowid ASC en base, à priority égale — le
        // tri est `ORDER BY priority DESC, rowid ASC`). Décision assumée : ne pas introduire de
        // priorités différenciées ici, car `ensureDefaultMappings()` est idempotent PAR LIGNE et
        // ne retouche jamais les lignes déjà semées chez un client existant — différencier les
        // priorités ne s'appliquerait qu'aux toutes nouvelles installations, créant un écart de
        // comportement entre parc existant et nouveau parc. Comportement fixé par un test dédié
        // (`OrderStatusMapperTest::testAmbiguousFinancialOnlyFallbackUsesFirstDeclaredRowOrder`) :
        // NE PAS réordonner ce tableau sans mettre à jour ce test.
        ['shopify_financial_status' => 'PENDING',       'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_DRAFT,             'default_mapping' => 1, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PAID',          'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_VALIDATED,         'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PAID',          'shopify_fulfillment_status' => 'PARTIALLY_FULFILLED', 'dolibarr_status' => Commande::STATUS_SHIPMENTONPROCESS, 'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PAID',          'shopify_fulfillment_status' => 'FULFILLED',           'dolibarr_status' => Commande::STATUS_CLOSED,            'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PARTIALLY_PAID', 'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_VALIDATED,         'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PARTIALLY_PAID', 'shopify_fulfillment_status' => 'PARTIALLY_FULFILLED', 'dolibarr_status' => Commande::STATUS_SHIPMENTONPROCESS, 'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PARTIALLY_PAID', 'shopify_fulfillment_status' => 'FULFILLED',           'dolibarr_status' => Commande::STATUS_CLOSED,            'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'VOIDED',        'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_CANCELED,          'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'AUTHORIZED',    'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_VALIDATED,         'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'EXPIRED',       'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_CANCELED,          'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'REFUNDED',      'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_CANCELED,          'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PARTIALLY_REFUNDED', 'shopify_fulfillment_status' => 'UNFULFILLED',         'dolibarr_status' => Commande::STATUS_VALIDATED,         'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PARTIALLY_REFUNDED', 'shopify_fulfillment_status' => 'PARTIALLY_FULFILLED', 'dolibarr_status' => Commande::STATUS_SHIPMENTONPROCESS, 'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['shopify_financial_status' => 'PARTIALLY_REFUNDED', 'shopify_fulfillment_status' => 'FULFILLED',           'dolibarr_status' => Commande::STATUS_CLOSED,            'default_mapping' => 0, 'priority' => 10, 'description' => ''],
    ];

    /**
     * Correspondances de statut de commande par défaut, Dolibarr → Shopify (mêmes garanties que
     * DEFAULT_SHOP2DOL_MAPPINGS ci-dessus). Au plus une ligne par `dolibarr_status` (clé unique
     * `uk_doli2shop_order_status_dol2shop` sur `(entity, dolibarr_status)`) : les 5 statuts réels
     * couvrent exactement les 5 lignes ci-dessous.
     *
     * @var array<int, array{dolibarr_status: int, shopify_financial_status: string, shopify_fulfillment_status: string, default_mapping: int, priority: int, description: string}>
     */
    private const DEFAULT_DOL2SHOP_MAPPINGS = [
        ['dolibarr_status' => Commande::STATUS_DRAFT,             'shopify_financial_status' => 'PENDING', 'shopify_fulfillment_status' => 'UNFULFILLED',         'default_mapping' => 1, 'priority' => 10, 'description' => ''],
        ['dolibarr_status' => Commande::STATUS_VALIDATED,         'shopify_financial_status' => 'PAID',    'shopify_fulfillment_status' => 'UNFULFILLED',         'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['dolibarr_status' => Commande::STATUS_SHIPMENTONPROCESS, 'shopify_financial_status' => 'PAID',    'shopify_fulfillment_status' => 'PARTIALLY_FULFILLED', 'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['dolibarr_status' => Commande::STATUS_CLOSED,            'shopify_financial_status' => 'PAID',    'shopify_fulfillment_status' => 'FULFILLED',           'default_mapping' => 0, 'priority' => 10, 'description' => ''],
        ['dolibarr_status' => Commande::STATUS_CANCELED,          'shopify_financial_status' => 'VOIDED',  'shopify_fulfillment_status' => 'UNFULFILLED',         'default_mapping' => 0, 'priority' => 10, 'description' => ''],
    ];

    /**
     * Constructor
     *
     * @param   DoliDB      $db         Database handler
     * @param   int         $entity     Entity ID
     * @param   int         $storeId    Store ID
     */
    public function __construct($db, $entity = 1, $storeId = 0)
    {
        $this->db = $db;
        $this->entity = $entity;

        // Migration v2.0.31: storeId n'est plus utilisé, remplacé par entity seul
        // Conservé pour compatibilité mais fixé à 1 par défaut
        $this->storeId = !empty($storeId) ? $storeId : 1;

        // initLogger() supprimée — LoggerTrait::log() utilise get_class($this) automatiquement
    }

    /**
     * Retourne la définition des correspondances Shopify → Dolibarr par défaut (lecture seule).
     *
     * Exposée pour les tests et pour l'action `restore_defaults` de
     * `admin/orderstatusmapping.php` — évite de dupliquer la liste.
     *
     * @return array<int, array{shopify_financial_status: string, shopify_fulfillment_status: string, dolibarr_status: int, default_mapping: int, priority: int, description: string}>
     * @since 2.5.3
     */
    public static function getDefaultShopifyToDolibarrMappingsDefinition(): array
    {
        return self::DEFAULT_SHOP2DOL_MAPPINGS;
    }

    /**
     * Retourne la définition des correspondances Dolibarr → Shopify par défaut (lecture seule).
     *
     * @return array<int, array{dolibarr_status: int, shopify_financial_status: string, shopify_fulfillment_status: string, default_mapping: int, priority: int, description: string}>
     * @since 2.5.3
     */
    public static function getDefaultDolibarrToShopifyMappingsDefinition(): array
    {
        return self::DEFAULT_DOL2SHOP_MAPPINGS;
    }

    /**
     * Sème les correspondances de statut de commande par défaut pour une entité donnée, dans les
     * deux tables (Shopify → Dolibarr et Dolibarr → Shopify) — appelé à l'installation/activation
     * du module (`modDoli2Shop::init()`).
     *
     * Suit le modèle de `PaymentMethodsMapping::ensureDefaultMappings()` : idempotent PAR LIGNE
     * (garde sur la clé unique de chaque table), jamais une garde globale — n'écrase ni ne recrée
     * jamais une correspondance déjà présente, y compris modifiée par le client.
     *
     * @param  int $entity Entité Dolibarr cible
     * @return array{shop2dol: int, dol2shop: int} Lignes insérées par table (-1/-1 = entité invalide)
     * @since  2.5.3
     */
    public function ensureDefaultMappings(int $entity): array
    {
        $entity = (int) $entity;
        if ($entity <= 0) {
            $this->log('ensureDefaultMappings() - Entité invalide (' . $entity . '), seeding ignoré', LOG_WARNING);
            return ['shop2dol' => -1, 'dol2shop' => -1];
        }

        return [
            'shop2dol' => $this->ensureDefaultShop2dolMappings($entity),
            'dol2shop' => $this->ensureDefaultDol2shopMappings($entity),
        ];
    }

    /**
     * Seeding idempotent PAR LIGNE de la table shop2dol (garde sur la clé unique
     * (entity, shopify_financial_status, shopify_fulfillment_status)).
     *
     * @param  int $entity Entité déjà validée (> 0) par l'appelant
     * @return int Nombre de lignes insérées
     */
    private function ensureDefaultShop2dolMappings(int $entity): int
    {
        $inserted = 0;

        foreach (self::DEFAULT_SHOP2DOL_MAPPINGS as $mapping) {
            $sqlExists = "SELECT rowid FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol"
                . " WHERE entity = ? AND shopify_financial_status = ? AND shopify_fulfillment_status = ?";
            $resqlExists = SqlUtils::executeQuery(
                $this->db,
                $sqlExists,
                "checking existing default order status mapping (shop2dol)",
                false,
                [$entity, $mapping['shopify_financial_status'], $mapping['shopify_fulfillment_status']]
            );

            if ($resqlExists === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur vérification existence shop2dol ('
                    . $mapping['shopify_financial_status'] . '/' . $mapping['shopify_fulfillment_status']
                    . ', entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $alreadyExists = $this->db->num_rows($resqlExists) > 0;
            $this->db->free($resqlExists);

            if ($alreadyExists) {
                continue;
            }

            $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol"
                . " (entity, fk_shopify_store, shopify_financial_status, shopify_fulfillment_status, dolibarr_status, default_mapping, priority, description, active)"
                . " VALUES (?, 1, ?, ?, ?, ?, ?, ?, 1)";
            $resqlInsert = SqlUtils::executeQuery(
                $this->db,
                $sqlInsert,
                "seeding default order status mapping (shop2dol)",
                false,
                [
                    $entity,
                    $mapping['shopify_financial_status'],
                    $mapping['shopify_fulfillment_status'],
                    (int) $mapping['dolibarr_status'],
                    (int) $mapping['default_mapping'],
                    (int) $mapping['priority'],
                    (string) $mapping['description'],
                ]
            );

            if ($resqlInsert === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur insertion shop2dol ('
                    . $mapping['shopify_financial_status'] . '/' . $mapping['shopify_fulfillment_status']
                    . ', entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $inserted++;
        }

        return $inserted;
    }

    /**
     * Seeding idempotent PAR LIGNE de la table dol2shop (garde sur la clé unique
     * (entity, dolibarr_status)).
     *
     * @param  int $entity Entité déjà validée (> 0) par l'appelant
     * @return int Nombre de lignes insérées
     */
    private function ensureDefaultDol2shopMappings(int $entity): int
    {
        $inserted = 0;

        foreach (self::DEFAULT_DOL2SHOP_MAPPINGS as $mapping) {
            $sqlExists = "SELECT rowid FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop"
                . " WHERE entity = ? AND dolibarr_status = ?";
            $resqlExists = SqlUtils::executeQuery(
                $this->db,
                $sqlExists,
                "checking existing default order status mapping (dol2shop)",
                false,
                [$entity, (int) $mapping['dolibarr_status']]
            );

            if ($resqlExists === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur vérification existence dol2shop (dolibarr_status='
                    . $mapping['dolibarr_status'] . ', entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $alreadyExists = $this->db->num_rows($resqlExists) > 0;
            $this->db->free($resqlExists);

            if ($alreadyExists) {
                continue;
            }

            $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop"
                . " (entity, fk_shopify_store, dolibarr_status, shopify_financial_status, shopify_fulfillment_status, default_mapping, priority, description, active)"
                . " VALUES (?, 1, ?, ?, ?, ?, ?, ?, 1)";
            $resqlInsert = SqlUtils::executeQuery(
                $this->db,
                $sqlInsert,
                "seeding default order status mapping (dol2shop)",
                false,
                [
                    $entity,
                    (int) $mapping['dolibarr_status'],
                    $mapping['shopify_financial_status'],
                    $mapping['shopify_fulfillment_status'],
                    (int) $mapping['default_mapping'],
                    (int) $mapping['priority'],
                    (string) $mapping['description'],
                ]
            );

            if ($resqlInsert === false) {
                $this->log(
                    'ensureDefaultMappings() - Erreur insertion dol2shop (dolibarr_status='
                    . $mapping['dolibarr_status'] . ', entity=' . $entity . '): ' . $this->db->lasterror(),
                    LOG_ERR
                );
                continue;
            }

            $inserted++;
        }

        return $inserted;
    }
    
    /**
     * Map Shopify order statuses to Dolibarr status
     *
     * @param   string  $financialStatus    Financial status from Shopify
     * @param   string  $fulfillmentStatus  Fulfillment status from Shopify
     * @return  int     Dolibarr order status
     */
    public function mapShopifyToDolibarr($financialStatus, $fulfillmentStatus)
    {
        // Load mappings if not already loaded
        if ($this->shopifyToDolibarrCache === null) {
            $this->loadShopifyToDolibarrMappings();
        }
        
        // Default fallback status
        $defaultStatus = Commande::STATUS_DRAFT;
        
        // First try to find an exact match
        $exactMatch = false;
        
        foreach ($this->shopifyToDolibarrCache as $mapping) {
            // Check if mapping is active
            if ($mapping['active'] == 0) {
                continue;
            }
            
            // Look for exact match
            if ($mapping['shopify_financial_status'] == $financialStatus && 
                $mapping['shopify_fulfillment_status'] == $fulfillmentStatus) {
                $this->log("Found exact mapping: {$financialStatus} + {$fulfillmentStatus} -> {$mapping['dolibarr_status']}", LOG_DEBUG);
                return (int)$mapping['dolibarr_status'];
            }
        }
        
        // If no exact match, search for financial status match only
        foreach ($this->shopifyToDolibarrCache as $mapping) {
            // Check if mapping is active
            if ($mapping['active'] == 0) {
                continue;
            }
            
            if ($mapping['shopify_financial_status'] == $financialStatus) {
                $this->log("Found financial status mapping: {$financialStatus} -> {$mapping['dolibarr_status']}", LOG_DEBUG);
                return (int)$mapping['dolibarr_status'];
            }
        }
        
        // If still no match, search for fulfillment status match only
        foreach ($this->shopifyToDolibarrCache as $mapping) {
            // Check if mapping is active
            if ($mapping['active'] == 0) {
                continue;
            }
            
            if ($mapping['shopify_fulfillment_status'] == $fulfillmentStatus) {
                $this->log("Found fulfillment status mapping: {$fulfillmentStatus} -> {$mapping['dolibarr_status']}", LOG_DEBUG);
                return (int)$mapping['dolibarr_status'];
            }
        }
        
        // Use default mapping if exists
        foreach ($this->shopifyToDolibarrCache as $mapping) {
            if ($mapping['default_mapping'] == 1 && $mapping['active'] == 1) {
                $this->log("Using default mapping -> {$mapping['dolibarr_status']}", LOG_DEBUG);
                return (int)$mapping['dolibarr_status'];
            }
        }
        
        $this->log("No mapping found for {$financialStatus} + {$fulfillmentStatus}, using default: {$defaultStatus}", LOG_INFO);
        return $defaultStatus;
    }
    
    /**
     * Map Dolibarr order status to Shopify status
     *
     * @param   int     $dolibarrStatus     Dolibarr order status
     * @return  array   Shopify statuses (associative array with 'financial_status' and 'fulfillment_status')
     */
    public function mapDolibarrToShopify($dolibarrStatus)
    {
        // Load mappings if not already loaded
        if ($this->dolibarrToShopifyCache === null) {
            $this->loadDolibarrToShopifyMappings();
        }
        
        // Default fallback statuses
        $defaultStatuses = [
            'financial_status' => 'PENDING',
            'fulfillment_status' => 'UNFULFILLED'
        ];
        
        foreach ($this->dolibarrToShopifyCache as $mapping) {
            // Check if mapping is active
            if ($mapping['active'] == 0) {
                continue;
            }
            
            if ((int)$mapping['dolibarr_status'] === (int)$dolibarrStatus) {
                $this->log("Found mapping: {$dolibarrStatus} -> {$mapping['shopify_financial_status']} + {$mapping['shopify_fulfillment_status']}", LOG_DEBUG);
                return [
                    'financial_status' => $mapping['shopify_financial_status'],
                    'fulfillment_status' => $mapping['shopify_fulfillment_status']
                ];
            }
        }
        
        // Use default mapping if exists
        foreach ($this->dolibarrToShopifyCache as $mapping) {
            if ($mapping['default_mapping'] == 1 && $mapping['active'] == 1) {
                $this->log("Using default mapping for {$dolibarrStatus} -> {$mapping['shopify_financial_status']} + {$mapping['shopify_fulfillment_status']}", LOG_DEBUG);
                return [
                    'financial_status' => $mapping['shopify_financial_status'],
                    'fulfillment_status' => $mapping['shopify_fulfillment_status']
                ];
            }
        }
        
        $this->log("No mapping found for Dolibarr status {$dolibarrStatus}, using default", LOG_INFO);
        return $defaultStatuses;
    }
    
    /**
     * Check if mappings exist for the Shopify to Dolibarr direction
     *
     * @return bool True if mappings exist
     */
    public function hasShopifyToDolibarrMappings()
    {
        // Load mappings if not already loaded
        if ($this->shopifyToDolibarrCache === null) {
            $this->loadShopifyToDolibarrMappings();
        }
        
        return !empty($this->shopifyToDolibarrCache);
    }
    
    /**
     * Check if mappings exist for the Dolibarr to Shopify direction
     *
     * @return bool True if mappings exist
     */
    public function hasDolibarrToShopifyMappings()
    {
        // Load mappings if not already loaded
        if ($this->dolibarrToShopifyCache === null) {
            $this->loadDolibarrToShopifyMappings();
        }
        
        return !empty($this->dolibarrToShopifyCache);
    }
    
    /**
     * Load Shopify to Dolibarr mappings from database
     */
    private function loadShopifyToDolibarrMappings()
    {
        $this->shopifyToDolibarrCache = [];
        
        $sql = "SELECT rowid, shopify_financial_status, shopify_fulfillment_status, dolibarr_status, 
                default_mapping, priority, description, active 
                FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_shop2dol 
                WHERE entity = " . (int)$this->entity . " 
                AND active = 1 
                ORDER BY priority DESC, rowid ASC";
        
        $resql = SqlUtils::executeQuery($this->db, $sql, "OrderStatusMapper::loadShopifyToDolibarrMappings", false);

        if ($resql) {
            $num = $this->db->num_rows($resql);

            if ($num > 0) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $this->shopifyToDolibarrCache[] = [
                        'shopify_financial_status' => $obj->shopify_financial_status,
                        'shopify_fulfillment_status' => $obj->shopify_fulfillment_status,
                        'dolibarr_status' => $obj->dolibarr_status,
                        'default_mapping' => $obj->default_mapping,
                        'priority' => $obj->priority,
                        'description' => $obj->description,
                        'active' => $obj->active
                    ];
                }
            }
        } else {
            $this->log("Failed to load Shopify to Dolibarr mappings: " . $this->db->lasterror(), LOG_ERR);
        }
    }
    
    /**
     * Load Dolibarr to Shopify mappings from database
     */
    private function loadDolibarrToShopifyMappings()
    {
        $this->dolibarrToShopifyCache = [];
        
        $sql = "SELECT rowid, dolibarr_status, shopify_financial_status, shopify_fulfillment_status, 
                default_mapping, priority, description, active 
                FROM " . MAIN_DB_PREFIX . "doli2shop_order_status_dol2shop 
                WHERE entity = " . (int)$this->entity . " 
                AND active = 1 
                ORDER BY priority DESC, rowid ASC";
        
        $resql = SqlUtils::executeQuery($this->db, $sql, "OrderStatusMapper::loadDolibarrToShopifyMappings", false);

        if ($resql) {
            $num = $this->db->num_rows($resql);

            if ($num > 0) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $this->dolibarrToShopifyCache[] = [
                        'dolibarr_status' => $obj->dolibarr_status,
                        'shopify_financial_status' => $obj->shopify_financial_status,
                        'shopify_fulfillment_status' => $obj->shopify_fulfillment_status,
                        'default_mapping' => $obj->default_mapping,
                        'priority' => $obj->priority,
                        'description' => $obj->description,
                        'active' => $obj->active
                    ];
                }
            }
        } else {
            $this->log("Failed to load Dolibarr to Shopify mappings: " . $this->db->lasterror(), LOG_ERR);
        }
    }
    
    // initLogger() supprimée — LoggerTrait::log() utilise get_class($this) automatiquement
}