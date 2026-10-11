<?php
/**
 * @file        class/inventoryreferenceresolver.class.php
 * @brief       Lecture des quantités de référence Shopify (available / on_hand) à la localisation visée
 *
 * @package     ShopifyIntegration
 * @subpackage  Classes
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.2
 * @since       2.6.1
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}
require_once dirname(__FILE__) . '/shopifyapi.class.php';

/**
 * Class InventoryReferenceResolver
 *
 * Story 66-1 (2.6.1) : primitive COMMUNE extraite de
 * `ImportProducts::fillInventoryReferenceQuantities()` (hotfix 2.5.7, trois re-reviews) pour que le
 * push stock du trigger (`ShopifyStockTrigger`) lise lui aussi la quantité Shopify à la
 * localisation visée AVANT d'écrire, au lieu d'envoyer un `changeFromQuantity = 0` inventé que
 * Shopify refuse dès que le stock en ligne n'est pas nul.
 *
 * Le corps est celui de la méthode d'origine, déplacé à l'identique (mêmes regroupements par
 * localisation, même ordre des `catch`, mêmes messages de log, aucun zéro inventé). La seule
 * addition est le paramètre OUT optionnel `$failures`, qui permet à un appelant de distinguer
 * « lecture impossible » (réseau, emplacement) de « article absent à cet emplacement ».
 *
 * Elle ne rappelle QUE `ShopifyApi::getInventoryQuantitiesAtLocation()` : un faussaire n'exposant
 * que cette méthode suffit en test.
 */
class InventoryReferenceResolver
{
    /**
     * Résout `current_shopify_quantity` (available) et `on_hand_shopify_quantity` (on_hand) EN LES
     * LISANT À LA LOCALISATION VISÉE, pour chaque article du lot dont l'inventory_item_id et le
     * location_id sont renseignés.
     *
     * ⚠️ ÉCRASE INCONDITIONNELLEMENT `current_shopify_quantity` / `on_hand_shopify_quantity` dès que
     * la lecture réseau résout une valeur pour l'article visé. N'inscrit RIEN pour un article dont
     * la quantité ne peut pas être résolue (jamais de zéro « par défaut »).
     *
     * ⚠️ CLÉ DISTINCTE pour chaque grandeur : `available` → `current_shopify_quantity` (chemin
     * delta), `on_hand` → `on_hand_shopify_quantity` (chemin de repli). Jamais l'une pour l'autre.
     *
     * @param  array<int,array<string,mixed>> $items               Lot d'articles, modifié en place
     * @param  object                         $api                 Instance exposant getInventoryQuantitiesAtLocation()
     * @param  int                            $locationErrorCount  [IN/OUT] compteur d'erreurs STRUCTURELLES d'emplacement
     * @param  callable|null                  $logger              function(string $message, int $level): void
     * @param  array|null                     $failures            [OUT] index d'article => ['kind' => 'location'|'transient'|'persistent',
     *                                                             'reason' => string, 'message' => string, 'location' => string]
     *                                                             pour chaque article dont la lecture a ÉCHOUÉ. Un article
     *                                                             absent de la réponse SANS échec n'y figure pas.
     * @return int Nombre de quantités résolues
     */
    public function resolve(array &$items, $api, &$locationErrorCount, $logger = null, &$failures = null)
    {
        $failures = array();

        $byLocation = [];
        foreach ($items as $index => $iq) {
            $locationGid = (string) ($iq['location_id'] ?? '');
            $itemGid = (string) ($iq['inventory_item_id'] ?? '');
            if ($locationGid === '' || $itemGid === '') {
                continue;
            }
            $byLocation[$locationGid][$itemGid][] = $index;
        }

        if (empty($byLocation)) {
            return 0;
        }

        $resolved = 0;
        foreach ($byLocation as $locationGid => $itemsToIndexes) {
            // 🔴 Cet appel réseau NE DOIT JAMAIS faire échouer la synchronisation qui l'englobe
            // (cf. ImportProducts : l'exception remonterait jusqu'au rollback de syncProduct()).
            // Deux échecs distincts, traités différemment :
            // - STRUCTUREL (ShopifyLocationErrorException) : défaut de CONFIGURATION, jamais résolu
            //   par un simple retry. LOG_ERR explicite + compteur dédié, une fois par appel.
            // - TRANSITOIRE (tout autre Throwable) : LOG_WARNING, retenté au prochain cycle.
            //
            // Hotfix 2.5.7 (re-review 27/09/2026, CRITICAL) : `catch (ShopifyLocationErrorException)`
            // AVANT `catch (\Throwable)` — cette exception porte les quantités déjà résolues par les
            // chunks PRÉCÉDENTS du même appel (`$e->partialResults`) : on les applique quand même
            // ci-dessous au lieu de les perdre (ne PAS faire `continue` ici).
            $levels = [];
            $unresolvedTransient = [];
            $unresolvedPersistent = [];
            try {
                $levels = $api->getInventoryQuantitiesAtLocation(array_keys($itemsToIndexes), $locationGid, $unresolvedTransient, $unresolvedPersistent);
            } catch (ShopifyLocationErrorException $e) {
                $locationErrorCount++;
                // Message ACTIONNABLE distinct selon la raison précise portée par l'exception : un
                // emplacement DÉSACTIVÉ n'appelle pas la même remédiation qu'un emplacement
                // introuvable/invalide.
                if ($e->reason === 'disabled') {
                    $this->emit($logger, 'fillInventoryReferenceQuantities - ERREUR STRUCTURELLE emplacement '
                        . $locationGid . ' DESACTIVE cote Shopify : ' . $e->getMessage()
                        . ' - choisir un emplacement ACTIF dans la configuration de la boutique '
                        . '(retenter ne suffira pas tant qu\'aucun emplacement actif n\'est configure).', LOG_ERR);
                } else {
                    $this->emit($logger, 'fillInventoryReferenceQuantities - ERREUR STRUCTURELLE emplacement '
                        . $locationGid . ' introuvable ou invalide cote Shopify : ' . $e->getMessage()
                        . ' - verifier shopify_location_id dans la configuration de la boutique '
                        . '(retenter ne suffira pas tant que la configuration est incorrecte).', LOG_ERR);
                }
                $levels = $e->partialResults;
                foreach ($itemsToIndexes as $itemGid => $indexes) {
                    if (array_key_exists($itemGid, $levels)) {
                        continue;
                    }
                    foreach ($indexes as $index) {
                        $failures[$index] = [
                            'kind' => 'location',
                            'reason' => (string) $e->reason,
                            'message' => $e->getMessage(),
                            'location' => $locationGid,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->emit($logger, 'fillInventoryReferenceQuantities - lecture temporairement impossible pour '
                    . 'la localisation ' . $locationGid . ' (incident reseau transitoire) : ' . $e->getMessage()
                    . ' - les articles concernes seront ecartes ce cycle-ci et retentes au prochain.', LOG_WARNING);
                // Re-review 66-1 (HIGH-A) : transitoire = réseau/délai SEULEMENT ; une exception d'une autre
                // nature (configuration absente, 401…) est persistante.
                $exceptionKind = ShopifyApi::isTransientFailureMessage($e->getMessage()) ? 'transient' : 'persistent';
                foreach ($itemsToIndexes as $itemGid => $indexes) {
                    foreach ($indexes as $index) {
                        $failures[$index] = [
                            'kind' => $exceptionKind,
                            'reason' => $exceptionKind,
                            'message' => $e->getMessage(),
                            'location' => $locationGid,
                        ];
                    }
                }
                continue;
            }
            // Re-review 66-1 (HIGH-A) : chunk en erreur NON transitoire (401, 403, ACCESS_DENIED, schéma) :
            // PERSISTANT, jamais confondu avec un incident passager ni avec « non activé ».
            foreach ($itemsToIndexes as $itemGid => $indexes) {
                if (!array_key_exists($itemGid, $levels) && is_array($unresolvedPersistent) && isset($unresolvedPersistent[$itemGid])) {
                    foreach ($indexes as $index) {
                        $failures[$index] = [
                            'kind' => 'persistent',
                            'reason' => 'persistent',
                            'message' => (string) $unresolvedPersistent[$itemGid],
                            'location' => $locationGid,
                        ];
                    }
                }
            }
            // Review 66-1 (MEDIUM-d) : un article non résolu parce que la requête de son chunk est revenue
            // en erreur (throttle, erreur partielle) est un INCIDENT PASSAGER, pas « non activé ».
            foreach ($itemsToIndexes as $itemGid => $indexes) {
                if (!array_key_exists($itemGid, $levels) && is_array($unresolvedTransient) && isset($unresolvedTransient[$itemGid])) {
                    foreach ($indexes as $index) {
                        $failures[$index] = [
                            'kind' => 'transient',
                            'reason' => 'transient',
                            'message' => (string) $unresolvedTransient[$itemGid],
                            'location' => $locationGid,
                        ];
                    }
                }
            }
            foreach ($itemsToIndexes as $itemGid => $indexes) {
                if (!array_key_exists($itemGid, $levels)) {
                    continue;
                }
                foreach ($indexes as $index) {
                    // ⚠️ CLÉ DISTINCTE, et ce n'est pas un détail. Écrire ici
                    // `current_shopify_quantity` ferait basculer l'article vers le chemin DELTA
                    // (`inventoryAdjustQuantities`), qui ajuste la grandeur **available**. Or on
                    // vient de lire **on_hand**. Le delta serait calculé sur une grandeur et
                    // appliqué à une autre : un stock faux, écrit en silence — pire que le refus
                    // qu'on corrige.
                    if (array_key_exists('on_hand', $levels[$itemGid])) {
                        $items[$index]['on_hand_shopify_quantity'] = (int) $levels[$itemGid]['on_hand'];
                    }
                    if (array_key_exists('available', $levels[$itemGid])) {
                        // ⚠️ On ÉCRASE volontairement toute valeur venue de
                        // `ProductVariant.inventoryQuantity` (TOTAL toutes localisations) : le chemin
                        // delta compare `changeFromQuantity` à la quantité `available` DE LA
                        // LOCALISATION visée. (CRITICAL relevé par la revue 3 couches du 21/09.)
                        $items[$index]['current_shopify_quantity'] = (int) $levels[$itemGid]['available'];
                    }
                    $resolved++;
                }
            }
        }

        $this->emit($logger, 'fillInventoryReferenceQuantities - ' . $resolved
            . ' article(s) dont les quantites de reference ont ete lues a la localisation visee',
            LOG_INFO);

        return $resolved;
    }

    /**
     * Transmet un message au logger de l'appelant (jamais d'exception si absent).
     *
     * @param callable|null $logger  Logger de l'appelant
     * @param string        $message Message
     * @param int           $level   Niveau syslog
     * @return void
     */
    private function emit($logger, $message, $level)
    {
        if (is_callable($logger)) {
            call_user_func($logger, $message, $level);
        }
    }
}
