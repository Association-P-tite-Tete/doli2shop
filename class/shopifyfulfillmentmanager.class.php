<?php
/**
 * @file        class/shopifyfulfillmentmanager.class.php
 * @brief       Persistance des événements de tracking Shopify (FulfillmentEvents)
 *
 * Classe orthogonale à l'Epic 11 : elle ne touche pas à la création de bons
 * d'expédition. Elle persiste uniquement l'historique des FulfillmentEvents
 * dans llx_doli2shop_fulfillment_events, qui est l'UNIQUE source de la timeline
 * de suivi affichée dans la fiche commande (pas d'extrafields commande).
 *
 * Appelée depuis :
 *  - ShopifyFulfillmentCatchupCron::processOrderFulfillments() (rattrapage CRON)
 *    Les FulfillmentEvents (statut, GPS, dates clés) ne sont disponibles que via
 *    GraphQL : c'est le catchup CRON qui les persiste. Le webhook REST temps réel
 *    ne contient pas ces données et ne persiste donc rien ici.
 *
 * @package     Doli2Shop
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.5.7
 * @since       2.3.0
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
 * ShopifyFulfillmentManager — persistance des événements fulfillment Shopify
 *
 * Cette classe est ORTHOGONALE à l'Epic 11. Elle n'affecte pas la création
 * de bons d'expédition Dolibarr. Elle persiste les FulfillmentEvents dans
 * llx_doli2shop_fulfillment_events (timeline), unique source du bloc de suivi
 * affiché dans la fiche commande.
 *
 * Idempotence garantie via INSERT ... ON DUPLICATE KEY UPDATE sur
 * (shopify_event_id, entity).
 *
 * @since 2.3.0
 */
class ShopifyFulfillmentManager
{
    use LoggerTrait;

    /** @var DoliDB */
    private $db;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Persist FulfillmentEvents into llx_doli2shop_fulfillment_events (Story 40.1 AC#2,3,4,5)
     *
     * Boucle sur les événements du fulfillment et insère chaque événement.
     * Idempotence garantie : INSERT ... ON DUPLICATE KEY UPDATE sur (shopify_event_id, entity).
     * Les événements sans coordonnées GPS sont insérés avec latitude/longitude = NULL (AC#3).
     *
     * @param  int    $dolibarrOrderId   Rowid commande Dolibarr
     * @param  string $shopifyOrderId    ID Shopify de la commande
     * @param  array  $fulfillmentData   Données fulfillment enrichies (GraphQL avec events)
     * @return int    Nombre d'événements insérés/mis à jour (0 si aucun événement)
     * @since  2.3.0
     */
    public function saveFulfillmentEvents($dolibarrOrderId, $shopifyOrderId, array $fulfillmentData)
    {
        global $conf;

        $dolibarrOrderId = (int) $dolibarrOrderId;
        if ($dolibarrOrderId <= 0) {
            $this->log("saveFulfillmentEvents - Invalid dolibarrOrderId=" . $dolibarrOrderId, LOG_WARNING);
            return 0;
        }

        $entity = (int) $conf->entity;

        // Extraire les events du fulfillment
        // Support deux formats : events.edges (GraphQL) et events (tableau plat)
        $events = array();
        if (isset($fulfillmentData['events']['edges']) && is_array($fulfillmentData['events']['edges'])) {
            foreach ($fulfillmentData['events']['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $events[] = $edge['node'];
                }
            }
        } elseif (isset($fulfillmentData['events']) && is_array($fulfillmentData['events'])) {
            $events = $fulfillmentData['events'];
        }

        if (empty($events)) {
            $this->log("saveFulfillmentEvents - No fulfillment events for order #" . $dolibarrOrderId, LOG_DEBUG);
            return 0;
        }

        $fulfillmentGid    = $fulfillmentData['id'] ?? ($fulfillmentData['shopify_fulfillment_gid'] ?? '');
        $fulfillmentNumId  = $this->extractNumericId($fulfillmentGid);
        $shopifyOrderStr   = $this->db->escape((string) $shopifyOrderId);
        $trackingNumber    = $this->db->escape($fulfillmentData['tracking_number'] ?? '');
        $trackingCompany   = $this->db->escape($fulfillmentData['tracking_company'] ?? '');
        $trackingUrl       = $this->db->escape($fulfillmentData['tracking_url'] ?? '');

        // Stocker le raw_data du fulfillment (sans les events pour alléger)
        $rawPayload = $fulfillmentData;
        unset($rawPayload['events']); // Éviter la duplication dans raw_data
        $rawData = $this->db->escape(json_encode($rawPayload));

        $insertedCount = 0;

        foreach ($events as $event) {
            // shopify_event_id : GID Shopify (ex. gid://shopify/FulfillmentEvent/12345)
            // Fallback sur un hash si id absent (événements legacy)
            $eventGid = $event['id'] ?? '';
            if (empty($eventGid)) {
                // Générer un ID synthétique idempotent
                $eventGid = 'synthetic:' . md5($fulfillmentGid . ($event['status'] ?? '') . ($event['happenedAt'] ?? ''));
            }
            $shopifyEventId = $this->db->escape($eventGid);

            $eventStatus      = $this->db->escape($event['status'] ?? '');
            $happenedAtRaw    = $this->formatDatetime($event['happenedAt'] ?? null);
            $estDeliveryRaw   = $this->formatDatetime($event['estimatedDeliveryAt'] ?? null);
            $message          = $this->db->escape($event['message'] ?? '');
            $city             = $this->db->escape($event['city'] ?? ($event['address']['city'] ?? ''));
            $province         = $this->db->escape($event['province'] ?? ($event['address']['province'] ?? ''));
            $zip              = $this->db->escape($event['zip'] ?? ($event['address']['zip'] ?? ''));
            $country          = $this->db->escape($event['country'] ?? ($event['address']['country'] ?? ''));

            // Coordonnées GPS : cast explicite en float, NULL si absentes ou invalides
            $latitudeRaw  = $event['latitude'] ?? ($event['address']['latitude'] ?? null);
            $longitudeRaw = $event['longitude'] ?? ($event['address']['longitude'] ?? null);
            $latitude     = ($latitudeRaw !== null && $latitudeRaw !== '') ? (float) $latitudeRaw : null;
            $longitude    = ($longitudeRaw !== null && $longitudeRaw !== '') ? (float) $longitudeRaw : null;

            if (empty($happenedAtRaw)) {
                $happenedAtRaw = date('Y-m-d H:i:s');
            }

            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "doli2shop_fulfillment_events"
                . " (fk_commande, fk_shopify_order_id, shopify_fulfillment_id, shopify_event_id,"
                . "  event_status, happened_at, estimated_delivery_at,"
                . "  message, city, province, zip, country,"
                . "  latitude, longitude,"
                . "  tracking_number, tracking_company, tracking_url,"
                . "  raw_data, entity, date_creation)"
                . " VALUES ("
                . $dolibarrOrderId . ","
                . "'" . $shopifyOrderStr . "',"
                . "'" . $this->db->escape($fulfillmentNumId) . "',"
                . "'" . $shopifyEventId . "',"
                . "'" . $eventStatus . "',"
                . "'" . $this->db->escape($happenedAtRaw) . "',"
                . ($estDeliveryRaw !== null ? "'" . $this->db->escape($estDeliveryRaw) . "'" : "NULL") . ","
                . "'" . $message . "',"
                . "'" . $city . "',"
                . "'" . $province . "',"
                . "'" . $zip . "',"
                . "'" . $country . "',"
                . ($latitude !== null ? number_format($latitude, 8, '.', '') : "NULL") . ","
                . ($longitude !== null ? number_format($longitude, 8, '.', '') : "NULL") . ","
                . "'" . $trackingNumber . "',"
                . "'" . $trackingCompany . "',"
                . "'" . $trackingUrl . "',"
                . "'" . $rawData . "',"
                . $entity . ","
                . "NOW()"
                . ")"
                . " ON DUPLICATE KEY UPDATE"
                . "  event_status = VALUES(event_status),"
                . "  estimated_delivery_at = VALUES(estimated_delivery_at),"
                . "  message = VALUES(message),"
                . "  city = VALUES(city),"
                . "  province = VALUES(province),"
                . "  zip = VALUES(zip),"
                . "  country = VALUES(country),"
                . "  latitude = VALUES(latitude),"
                . "  longitude = VALUES(longitude),"
                . "  tracking_number = VALUES(tracking_number),"
                . "  tracking_company = VALUES(tracking_company),"
                . "  tracking_url = VALUES(tracking_url)";

            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->log("saveFulfillmentEvents - SQL error for event " . $eventGid
                    . " order #" . $dolibarrOrderId . ": " . $this->db->lasterror(), LOG_ERR);
            } else {
                $insertedCount++;
            }
        }

        $this->log("saveFulfillmentEvents - Persisted " . $insertedCount . " events"
            . " for order #" . $dolibarrOrderId
            . " (fulfillment=" . $fulfillmentNumId . ")", LOG_INFO);

        return $insertedCount;
    }

    /**
     * Get fulfillment events for a Dolibarr order (for UI timeline, Story 40.2)
     *
     * Retourne les 20 derniers événements triés par happened_at DESC.
     * Filtre sur entity pour le multi-tenant.
     *
     * @param  int   $dolibarrOrderId  Rowid commande Dolibarr
     * @param  int   $limit            Nombre maximum d'événements (défaut : 20)
     * @return array Tableau d'événements (tableau associatif), vide si aucun
     * @since  2.3.0
     */
    public function getEventsForOrder($dolibarrOrderId, $limit = 20)
    {
        global $conf;

        $dolibarrOrderId = (int) $dolibarrOrderId;
        if ($dolibarrOrderId <= 0) {
            return array();
        }

        $limit  = (int) $limit;
        $entity = (int) $conf->entity;

        $sql = "SELECT rowid, shopify_fulfillment_id, shopify_event_id,"
            . " event_status, happened_at, estimated_delivery_at,"
            . " message, city, province, zip, country,"
            . " latitude, longitude,"
            . " tracking_number, tracking_company, tracking_url"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_fulfillment_events"
            . " WHERE fk_commande = " . $dolibarrOrderId
            . " AND entity = " . $entity
            . " ORDER BY happened_at DESC"
            . " LIMIT " . $limit;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("getEventsForOrder - SQL error for order #" . $dolibarrOrderId
                . ": " . $this->db->lasterror(), LOG_ERR);
            return array();
        }

        $events = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $events[] = array(
                'rowid'                 => (int) $obj->rowid,
                'shopify_fulfillment_id' => $obj->shopify_fulfillment_id,
                'shopify_event_id'      => $obj->shopify_event_id,
                'event_status'          => $obj->event_status,
                'happened_at'           => $obj->happened_at,
                'estimated_delivery_at' => $obj->estimated_delivery_at,
                'message'               => $obj->message,
                'city'                  => $obj->city,
                'province'              => $obj->province,
                'zip'                   => $obj->zip,
                'country'               => $obj->country,
                'latitude'              => $obj->latitude,
                'longitude'             => $obj->longitude,
                'tracking_number'       => $obj->tracking_number,
                'tracking_company'      => $obj->tracking_company,
                'tracking_url'          => $obj->tracking_url,
            );
        }
        $this->db->free($resql);

        return $events;
    }

    /**
     * Get the latest fulfillment summary for a Dolibarr order (used by UI bloc header)
     *
     * Retourne les infos du fulfillment le plus récent (tracking, statut, dates).
     * Tire les données depuis les events persistés (pas les extrafields, qui peuvent
     * être absents si les colonnes extrafields n'ont pas encore été créées).
     *
     * @param  int        $dolibarrOrderId  Rowid commande Dolibarr
     * @return array|null Infos résumé ou null si aucun événement
     * @since  2.3.0
     */
    public function getLatestFulfillmentSummary($dolibarrOrderId)
    {
        global $conf;

        $dolibarrOrderId = (int) $dolibarrOrderId;
        if ($dolibarrOrderId <= 0) {
            return null;
        }

        $entity = (int) $conf->entity;

        $sql = "SELECT event_status, happened_at, estimated_delivery_at,"
            . " tracking_number, tracking_company, tracking_url,"
            . " city, country"
            . " FROM " . MAIN_DB_PREFIX . "doli2shop_fulfillment_events"
            . " WHERE fk_commande = " . $dolibarrOrderId
            . " AND entity = " . $entity
            . " ORDER BY happened_at DESC"
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            return null;
        }

        return array(
            'event_status'          => $obj->event_status,
            'happened_at'           => $obj->happened_at,
            'estimated_delivery_at' => $obj->estimated_delivery_at,
            'tracking_number'       => $obj->tracking_number,
            'tracking_company'      => $obj->tracking_company,
            'tracking_url'          => $obj->tracking_url,
            'city'                  => $obj->city,
            'country'               => $obj->country,
        );
    }

    // =========================================================================
    // Méthodes privées utilitaires
    // =========================================================================

    /**
     * Convertit une date ISO 8601 (Shopify) en format MySQL DATETIME Y-m-d H:i:s
     *
     * @param  string|null $isoDate Date ISO 8601 ou null
     * @return string|null Date MySQL ou null
     */
    private function formatDatetime($isoDate)
    {
        if (empty($isoDate)) {
            return null;
        }
        $ts = strtotime($isoDate);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Extrait l'ID numérique depuis un GID Shopify
     * Ex: "gid://shopify/Fulfillment/123456" → "123456"
     *
     * @param  string $gid GID Shopify
     * @return string ID numérique ou gid complet si extraction impossible
     */
    private function extractNumericId($gid)
    {
        if (empty($gid)) {
            return '';
        }
        if (preg_match('/\/(\d+)$/', $gid, $matches)) {
            return $matches[1];
        }
        return $gid;
    }
}
