<?php
/**
 * @file        class/actions_doli2shop.class.php
 * @brief       Hook Doli2Shop — point d'entrée des hooks UI du module
 *
 * Classe de hooks Dolibarr résolue automatiquement par le hookmanager via la
 * convention de nommage /{module}/class/actions_{module}.class.php avec la
 * classe Actions{Ucfirst(module)}. Pour ce module : ActionsDoli2shop.
 *
 * Hook actuellement implémenté :
 *  - formObjectOptions (contexte 'ordercard') : injecte un bloc « Suivi
 *    expédition Shopify » dans la fiche commande Dolibarr, alimenté depuis
 *    la table llx_doli2shop_fulfillment_events (Stories 40.1 / 40.2).
 *
 * Les événements affichés sont persistés par le catchup CRON
 * (ShopifyFulfillmentCatchupCron) via GraphQL enrichi ; le webhook temps réel
 * ne contient pas les FulfillmentEvents (GPS, timeline) et ne les persiste pas.
 *
 * Dépendance : Story 40.1 doit avoir créé la table
 * llx_doli2shop_fulfillment_events. Si la table est absente ou qu'aucun
 * événement n'existe pour la commande, le bloc n'est pas rendu.
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

// Protection contre accès direct
if (!defined('DOLIBARR_INC_FOR_MODULES') && !defined('DOL_DOCUMENT_ROOT')) {
    print "Erreur, accès interdit.\n";
    exit();
}

dol_include_once('/doli2shop/class/shopifyfulfillmentmanager.class.php');

/**
 * ActionsDoli2shop — Classe de hooks UI du module Doli2Shop
 *
 * Le nom de classe (Actions + Ucfirst('doli2shop')) et le chemin du fichier
 * (class/actions_doli2shop.class.php) sont imposés par le hookmanager Dolibarr.
 *
 * @since 2.3.0
 */
class ActionsDoli2shop
{
    /** @var string Réponse HTML à injecter (convention Dolibarr hookmanager) */
    public $resprints = '';

    /** @var array Erreurs éventuelles */
    public $errors = array();

    /**
     * Hook formObjectOptions — injecte le bloc timeline dans la fiche commande
     *
     * Appelé par Dolibarr sur toutes les pages enregistrées dans
     * $module_parts['hooks']. On vérifie le contexte 'ordercard' et l'objet
     * 'commande' avant d'afficher quoi que ce soit.
     *
     * @param  array  $parameters  Hook parameters (currentcontext, object, etc.)
     * @param  mixed  $object      Dolibarr object (Commande)
     * @param  string $action      Current action
     * @param  mixed  $hookmanager HookManager instance
     * @return int    0 = OK (no error)
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs, $user, $db;

        // Vérifier que le module Doli2Shop est actif
        if (!isModEnabled('doli2shop')) {
            return 0;
        }

        // Vérifier le contexte : on ne s'exécute que sur la fiche commande
        $contexts = $parameters['currentcontext'] ?? '';
        if (!in_array('ordercard', is_array($contexts) ? $contexts : array($contexts), true)) {
            return 0;
        }

        // Vérifier que l'objet est bien une commande
        if (!is_object($object) || empty($object->element) || $object->element !== 'commande') {
            return 0;
        }

        // Vérifier les droits lecture commande
        if (!$user->hasRight('commande', 'lire')) {
            return 0;
        }

        $dolibarrOrderId = (int) ($object->id ?? 0);
        if ($dolibarrOrderId <= 0) {
            return 0;
        }

        // Charger les traductions
        $langs->loadLangs(array('doli2shop@doli2shop'));

        // Bloc « Adresses » (livraison / facturation / point relais) — indépendant
        // du fulfillment : s'affiche dès qu'une adresse existe (#200, Story 39.5).
        $addressesHtml = $this->renderAddressesBlock($langs, $object, $db);

        // Bloc « Suivi expédition Shopify » — uniquement si des événements existent (Stories 40.1/40.2)
        $fulfillmentHtml = '';
        $fulfillmentManager = new ShopifyFulfillmentManager($db);
        $events = $fulfillmentManager->getEventsForOrder($dolibarrOrderId, 20);
        if (!empty($events)) {
            $summary = $fulfillmentManager->getLatestFulfillmentSummary($dolibarrOrderId);
            $fulfillmentHtml = $this->renderFulfillmentBlock($langs, $summary, $events);
        }

        $this->resprints = $addressesHtml . $fulfillmentHtml;

        return 0;
    }

    /**
     * Render le bloc HTML « Adresses » (livraison / facturation / point relais) de la
     * fiche commande — évite d'ouvrir l'onglet Contacts/Adresses (#200, Story 39.5).
     *
     * Sources : contacts SHIPPING/BILLING liés à la commande + extrafields point relais
     * (pickup_*) de llx_commande_extrafields. N'affiche rien si aucune donnée. Non intrusif.
     *
     * @param  Translate $langs  Objet traductions Dolibarr
     * @param  Commande   $object Commande Dolibarr
     * @param  DoliDB     $db     Connexion base
     * @return string HTML du bloc (vide si rien à afficher)
     */
    private function renderAddressesBlock($langs, $object, $db)
    {
        // Charger les extrafields (point relais) seulement si le core ne les a pas déjà
        // chargés (mode VIEW les charge) — évite une requête SQL redondante à chaque rendu.
        if (empty($object->array_options) && method_exists($object, 'fetch_optionals')) {
            $object->fetch_optionals();
        }
        $opt = (isset($object->array_options) && is_array($object->array_options)) ? $object->array_options : array();

        $pickupName = isset($opt['options_pickup_point_name']) ? trim((string) $opt['options_pickup_point_name']) : '';

        $shipping = $this->getOrderAddressContact($object, 'SHIPPING', $db);
        $billing  = $this->getOrderAddressContact($object, 'BILLING', $db);

        // Rien à afficher
        if ($pickupName === '' && $shipping === null && $billing === null) {
            return '';
        }

        $esc = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        $html  = '<style type="text/css">' . "\n";
        $html .= '.doli2shop-addresses-block { margin: 8px 0; }' . "\n";
        $html .= '.doli2shop-addresses-grid { display: flex; flex-wrap: wrap; gap: 12px; }' . "\n";
        $html .= '.doli2shop-address-card { flex: 1 1 220px; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px 12px; background: #fafafa; }' . "\n";
        $html .= '.doli2shop-address-card h4 { margin: 0 0 6px 0; font-size: 13px; color: #029e9c; }' . "\n";
        $html .= '.doli2shop-address-card .doli2shop-addr-line { font-size: 12px; line-height: 1.45; }' . "\n";
        $html .= '.doli2shop-address-card.pickup { border-color: #029e9c; }' . "\n";
        $html .= '</style>' . "\n";

        $html .= '<div class="doli2shop-addresses-block">' . "\n";
        $html .= '<div class="doli2shop-addresses-grid">' . "\n";

        // Point relais (prioritaire visuellement : c'est là qu'on expédie)
        if ($pickupName !== '') {
            $provider = isset($opt['options_pickup_provider']) ? trim((string) $opt['options_pickup_provider']) : '';
            $line1 = isset($opt['options_pickup_address_line1']) ? trim((string) $opt['options_pickup_address_line1']) : '';
            $line2 = isset($opt['options_pickup_address_line2']) ? trim((string) $opt['options_pickup_address_line2']) : '';
            $zip   = isset($opt['options_pickup_zip']) ? trim((string) $opt['options_pickup_zip']) : '';
            $city  = isset($opt['options_pickup_city']) ? trim((string) $opt['options_pickup_city']) : '';
            $country = isset($opt['options_pickup_country']) ? trim((string) $opt['options_pickup_country']) : '';

            $html .= '<div class="doli2shop-address-card pickup">';
            $html .= '<h4>&#128230; ' . $esc($langs->trans('ShopifyPickupPoint'));
            if ($provider !== '') {
                $html .= ' <span class="opacitymedium">(' . $esc($provider) . ')</span>';
            }
            $html .= '</h4>';
            $html .= '<div class="doli2shop-addr-line"><strong>' . $esc($pickupName) . '</strong></div>';
            if ($line1 !== '') {
                $html .= '<div class="doli2shop-addr-line">' . $esc($line1) . '</div>';
            }
            if ($line2 !== '') {
                $html .= '<div class="doli2shop-addr-line">' . $esc($line2) . '</div>';
            }
            if ($zip !== '' || $city !== '') {
                $html .= '<div class="doli2shop-addr-line">' . $esc(trim($zip . ' ' . $city)) . '</div>';
            }
            if ($country !== '') {
                $html .= '<div class="doli2shop-addr-line">' . $esc($country) . '</div>';
            }
            $html .= '</div>';
        }

        // Adresse de livraison
        if ($shipping !== null) {
            $html .= $this->renderContactAddressCard($langs->trans('ShopifyShippingAddress'), '&#128666;', $shipping, $esc);
        }

        // Adresse de facturation
        if ($billing !== null) {
            $html .= $this->renderContactAddressCard($langs->trans('ShopifyBillingAddress'), '&#129534;', $billing, $esc);
        }

        $html .= '</div>'; // grid
        $html .= '</div>'; // block

        return $html;
    }

    /**
     * Récupère le 1er contact externe d'un type donné lié à la commande.
     *
     * @param  Commande $object Commande
     * @param  string   $code   Code type contact ('SHIPPING' / 'BILLING')
     * @param  DoliDB   $db     Connexion base
     * @return Contact|null Contact chargé ou null si absent/erreur
     */
    private function getOrderAddressContact($object, $code, $db)
    {
        if (!method_exists($object, 'liste_contact')) {
            return null;
        }
        $contacts = $object->liste_contact(-1, 'external', 0, $code);
        if (!is_array($contacts) || empty($contacts) || empty($contacts[0]['id'])) {
            return null;
        }

        dol_include_once('/contact/class/contact.class.php');
        $contact = new Contact($db);
        if ($contact->fetch((int) $contacts[0]['id']) <= 0) {
            return null;
        }
        return $contact;
    }

    /**
     * Render une carte d'adresse à partir d'un objet Contact Dolibarr.
     *
     * @param  string   $title Libellé traduit du bloc
     * @param  string   $icon  Entité HTML d'icône
     * @param  Contact  $c     Contact chargé
     * @param  callable $esc   Fonction d'échappement HTML
     * @return string HTML de la carte
     */
    private function renderContactAddressCard($title, $icon, $c, $esc)
    {
        $html  = '<div class="doli2shop-address-card">';
        $html .= '<h4>' . $icon . ' ' . $esc($title) . '</h4>';

        $name = trim((string) ($c->firstname ?? '') . ' ' . (string) ($c->lastname ?? ''));
        // Fallback B2B : si pas de nom de personne, utiliser la raison sociale du contact
        if ($name === '' && isset($c->company) && $c->company !== '') {
            $name = (string) $c->company;
        }
        if ($name !== '') {
            $html .= '<div class="doli2shop-addr-line"><strong>' . $esc($name) . '</strong></div>';
        }
        if (!empty($c->address)) {
            $html .= '<div class="doli2shop-addr-line">' . nl2br($esc($c->address)) . '</div>';
        }
        $zipTown = trim((string) ($c->zip ?? '') . ' ' . (string) ($c->town ?? ''));
        if ($zipTown !== '') {
            $html .= '<div class="doli2shop-addr-line">' . $esc($zipTown) . '</div>';
        }
        $country = !empty($c->country) ? $c->country : (!empty($c->country_code) ? $c->country_code : '');
        if ($country !== '') {
            $html .= '<div class="doli2shop-addr-line">' . $esc($country) . '</div>';
        }
        if (!empty($c->phone_pro) || !empty($c->phone_mobile)) {
            $phone = !empty($c->phone_pro) ? $c->phone_pro : $c->phone_mobile;
            $html .= '<div class="doli2shop-addr-line opacitymedium">' . $esc($phone) . '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render le bloc HTML « Suivi expédition Shopify »
     *
     * @param  Translate  $langs   Objet traductions Dolibarr
     * @param  array|null $summary Résumé du dernier fulfillment
     * @param  array      $events  Liste des événements
     * @return string HTML du bloc
     */
    private function renderFulfillmentBlock($langs, $summary, array $events)
    {
        // CSS inline spécifique au bloc timeline (cohérent avec Teal #029e9c)
        $html = '<style>
.doli2shop-tracking-block {
    margin-top: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    background: #fff;
}
.doli2shop-tracking-header {
    background-color: #f5f5f5;
    border-bottom: 1px solid #e0e0e0;
    padding: 10px 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.doli2shop-tracking-header h4 {
    margin: 0;
    color: #333;
    font-size: 14px;
}
.doli2shop-tracking-header .doli2shop-badge {
    background-color: #029e9c;
    color: #fff;
    border-radius: 3px;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}
.doli2shop-tracking-summary {
    padding: 12px 15px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 13px;
}
.doli2shop-tracking-summary .doli2shop-field {
    display: flex;
    flex-direction: column;
}
.doli2shop-tracking-summary .doli2shop-field label {
    font-weight: bold;
    color: #666;
    font-size: 11px;
    text-transform: uppercase;
    margin-bottom: 2px;
}
.doli2shop-tracking-summary .doli2shop-field span {
    color: #333;
}
.doli2shop-tracking-summary a {
    color: #029e9c;
}
.doli2shop-tracking-timeline {
    padding: 10px 15px;
}
.doli2shop-tracking-timeline table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.doli2shop-tracking-timeline th {
    text-align: left;
    padding: 6px 8px;
    background: #f9f9f9;
    border-bottom: 1px solid #e0e0e0;
    color: #555;
    font-size: 11px;
    text-transform: uppercase;
}
.doli2shop-tracking-timeline td {
    padding: 6px 8px;
    border-bottom: 1px solid #f5f5f5;
    vertical-align: top;
}
.doli2shop-tracking-timeline tr:last-child td {
    border-bottom: none;
}
.doli2shop-tracking-timeline .status-delivered { color: #2e7d32; font-weight: bold; }
.doli2shop-tracking-timeline .status-in_transit { color: #1565c0; }
.doli2shop-tracking-timeline .status-out_for_delivery { color: #e65100; }
.doli2shop-tracking-timeline .status-failure { color: #c62828; }
.doli2shop-tracking-timeline .status-attempted_delivery { color: #f57f17; }
.doli2shop-tracking-timeline .doli2shop-gps { color: #888; font-style: italic; font-size: 11px; }
</style>' . "\n";

        $html .= '<div class="doli2shop-tracking-block">' . "\n";

        // En-tête du bloc
        $statusLabel = htmlspecialchars($this->formatStatus($langs, $summary['event_status'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html .= '<div class="doli2shop-tracking-header">';
        $html .= '<h4>' . htmlspecialchars($langs->transnoentities('FulfillmentHistoryTitle'), ENT_QUOTES, 'UTF-8') . '</h4>';
        if (!empty($statusLabel)) {
            $html .= '<span class="doli2shop-badge">' . $statusLabel . '</span>';
        }
        $html .= '</div>' . "\n";

        // Résumé du dernier fulfillment (tracking + dates clés)
        if ($summary) {
            $html .= '<div class="doli2shop-tracking-summary">' . "\n";

            // Transporteur
            if (!empty($summary['tracking_company'])) {
                $html .= '<div class="doli2shop-field"><label>'
                    . htmlspecialchars($langs->transnoentities('TrackingCarrier'), ENT_QUOTES, 'UTF-8') . '</label><span>'
                    . htmlspecialchars($summary['tracking_company'], ENT_QUOTES, 'UTF-8')
                    . '</span></div>' . "\n";
            }

            // Numéro de tracking (cliquable si URL disponible)
            if (!empty($summary['tracking_number'])) {
                $trackingDisplay = htmlspecialchars($summary['tracking_number'], ENT_QUOTES, 'UTF-8');
                if (!empty($summary['tracking_url'])) {
                    $trackingDisplay = '<a href="' . dol_escape_htmltag($summary['tracking_url'])
                        . '" target="_blank" rel="noopener noreferrer">'
                        . $trackingDisplay . ' &#x1f517;</a>';
                }
                $html .= '<div class="doli2shop-field"><label>'
                    . htmlspecialchars($langs->transnoentities('TrackingNumber'), ENT_QUOTES, 'UTF-8') . '</label><span>'
                    . $trackingDisplay . '</span></div>' . "\n";
            }

            // Date dernier événement
            if (!empty($summary['happened_at'])) {
                $html .= '<div class="doli2shop-field"><label>'
                    . htmlspecialchars($langs->transnoentities('FulfillmentLastEvent'), ENT_QUOTES, 'UTF-8') . '</label><span>'
                    . dol_print_date($this->toTimestamp($summary['happened_at']), 'dayhour')
                    . '</span></div>' . "\n";
            }

            // Livraison estimée
            if (!empty($summary['estimated_delivery_at'])) {
                $html .= '<div class="doli2shop-field"><label>'
                    . htmlspecialchars($langs->transnoentities('EstimatedDelivery'), ENT_QUOTES, 'UTF-8') . '</label><span>'
                    . dol_print_date($this->toTimestamp($summary['estimated_delivery_at']), 'day')
                    . '</span></div>' . "\n";
            }

            $html .= '</div>' . "\n";
        }

        // Timeline des événements
        $html .= '<div class="doli2shop-tracking-timeline">' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<thead><tr>'
            . '<th>' . htmlspecialchars($langs->transnoentities('FulfillmentEventDate'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars($langs->transnoentities('FulfillmentEventStatus'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars($langs->transnoentities('FulfillmentEventMessage'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '<th>' . htmlspecialchars($langs->transnoentities('FulfillmentEventLocation'), ENT_QUOTES, 'UTF-8') . '</th>'
            . '</tr></thead>' . "\n";
        $html .= '<tbody>' . "\n";

        foreach ($events as $event) {
            $statusSlug = strtolower($event['event_status'] ?? '');
            $statusClass = 'status-' . preg_replace('/[^a-z0-9_]/', '_', $statusSlug);

            $locationParts = array();
            if (!empty($event['city'])) {
                $locationParts[] = htmlspecialchars($event['city'], ENT_QUOTES, 'UTF-8');
            }
            if (!empty($event['country'])) {
                $locationParts[] = htmlspecialchars($event['country'], ENT_QUOTES, 'UTF-8');
            }
            $locationStr = implode(', ', $locationParts);

            // Coordonnées GPS en texte
            if ($event['latitude'] !== null && $event['longitude'] !== null) {
                $lat = htmlspecialchars(number_format((float) $event['latitude'], 5), ENT_QUOTES, 'UTF-8');
                $lon = htmlspecialchars(number_format((float) $event['longitude'], 5), ENT_QUOTES, 'UTF-8');
                $locationStr .= '<br><span class="doli2shop-gps">' . $lat . ', ' . $lon . '</span>';
            }

            $html .= '<tr>'
                . '<td>' . dol_print_date($this->toTimestamp($event['happened_at']), 'dayhour') . '</td>'
                . '<td class="' . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($this->formatStatus($langs, $event['event_status'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '</td>'
                . '<td>' . htmlspecialchars($event['message'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . $locationStr . '</td>'
                . '</tr>' . "\n";
        }

        $html .= '</tbody></table>' . "\n";
        $html .= '</div>' . "\n"; // .doli2shop-tracking-timeline
        $html .= '</div>' . "\n"; // .doli2shop-tracking-block

        return $html;
    }

    /**
     * Formate un statut FulfillmentEvent machine en libellé traduit
     *
     * Les libellés sont tirés des clés de traduction FulfillmentStatus<CamelCase>
     * (présentes dans langs/*). Fallback générique si la clé n'existe pas.
     *
     * @param  Translate $langs  Objet traductions Dolibarr
     * @param  string    $status Statut machine (IN_TRANSIT, DELIVERED, etc.)
     * @return string Libellé formaté
     */
    private function formatStatus($langs, $status)
    {
        if (empty($status)) {
            return '';
        }

        // IN_TRANSIT -> FulfillmentStatusInTransit
        $camel = str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $status))));
        $key = 'FulfillmentStatus' . $camel;
        $label = $langs->trans($key);

        // Si la clé n'existe pas, $langs->trans() retourne la clé telle quelle
        if ($label !== $key) {
            return $label;
        }

        // Fallback : libellé lisible à partir du statut machine
        return ucwords(strtolower(str_replace('_', ' ', $status)));
    }

    /**
     * Convertit une date MySQL DATETIME en timestamp Unix pour dol_print_date()
     *
     * @param  string|null $mysqlDate Date YYYY-MM-DD HH:II:SS ou null
     * @return int Timestamp Unix (0 si null/invalide)
     */
    private function toTimestamp($mysqlDate)
    {
        if (empty($mysqlDate)) {
            return 0;
        }
        $ts = strtotime($mysqlDate);
        return ($ts !== false) ? $ts : 0;
    }
}
