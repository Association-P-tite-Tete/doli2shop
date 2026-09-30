<?php
/**
 * @file        class/skuvariantmatcher.class.php
 * @brief       This file contains the SkuVariantMatcher class
 * @note        Appariement PUR (sans DB, sans API) des variantes Shopify aux déclinaisons
 *              Dolibarr, uniquement par SKU.
 *
 * Story variante-sans-correspondance-sku-ignoree-en-silence : `ImportProducts::updateVariantInventory()`
 * appariait les déclinaisons par SKU sans jamais signaler une absence de correspondance (silence
 * total, 0 log). Le même dépôt testait par ailleurs la présence d'un SKU de QUATRE façons
 * concurrentes et incohérentes :
 *   - `empty($variant->sku)`                              (syncInventoryOnly, ~ex-l.604)
 *   - `!empty($sv->sku)`                                   (syncVariantStocksOnly, ~ex-l.762)
 *   - `!empty($shopifyVariant->sku) && isset(...)`         (mapVariantsToShopify, ~ex-l.1427)
 *   - `isset($variant->sku)`                               (updateVariantInventory, ~ex-l.2635)
 *     ⚠️ Cette dernière écriture est la SEULE des quatre à accepter une chaîne VIDE ('') comme
 *     clé d'appariement valide (isset('') === true) — source d'un appariement fantôme sur SKU ''.
 *
 * AC4 : les quatre convergent désormais sur normalizeSku() ci-dessous — null et '' (après cast
 * string) sont traités IDENTIQUEMENT ("pas de SKU"), jamais une clé d'appariement.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.5.5
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

/**
 * Class SkuVariantMatcher - appariement pur variantes Shopify <-> déclinaisons Dolibarr par SKU
 *
 * Aucune dépendance DB/API/Dolibarr : entièrement testable en isolation (AC5).
 */
class SkuVariantMatcher
{
    /**
     * Normalise un SKU pour en faire (ou non) une clé d'appariement.
     *
     * `null` et `''` (chaîne vide, y compris après cast d'un SKU non-string) sont TOUJOURS
     * traités de façon identique : "pas de SKU", donc jamais de clé d'appariement (AC4). Une
     * valeur telle que "0" reste en revanche un SKU valide (contrairement à `empty()`, qui
     * l'aurait écartée à tort — écart volontaire par rapport aux 3 anciennes écritures `empty()`,
     * plus correct qu'elles).
     *
     * Espaces en début/fin retirés (`trim()`) avant comparaison : Shopify n'effectue AUCUN
     * trim serveur sur le champ SKU d'une variante créée/modifiée via l'API GraphQL — une valeur
     * copiée-collée avec un espace de tête ("` 42285`") resterait donc, sans ce trim, un SKU
     * distinct de la référence Dolibarr "42285" et produirait un AVERTISSEMENT sur une déclinaison
     * en réalité saine à CHAQUE cycle de synchronisation — exactement le risque de faux positif à
     * éviter en priorité (cf. story, section risques). Un SKU composé uniquement d'espaces devient
     * ainsi correctement "pas de SKU" après trim, plutôt qu'une clé d'appariement fantôme.
     *
     * @param  mixed $sku Valeur brute (peut être null, un stdClass::$sku absent, une chaîne, etc.)
     * @return string|null SKU normalisé, ou null si "pas de SKU"
     */
    public static function normalizeSku($sku)
    {
        if ($sku === null) {
            return null;
        }

        $sku = trim((string) $sku);

        if ($sku === '') {
            return null;
        }

        return $sku;
    }

    /**
     * Apparie une liste de variantes Shopify à une liste de produits/déclinaisons Dolibarr,
     * uniquement par SKU (jamais par position ni par titre — cf. story, section "à se garder de
     * faire").
     *
     * Fonction PURE : aucune I/O, aucun log, aucun effet de bord. Retourne les paires appariées
     * ET les deux listes de non-appariés (AC1/AC2 sont satisfaits par l'appelant, qui journalise
     * et persiste à partir de ce résultat).
     *
     * @param  array $shopifyVariants Liste d'objets variante Shopify (chacun avec ->sku, ->id, ...)
     * @param  array $dolProducts     Liste d'objets produit/déclinaison Dolibarr (chacun avec ->ref, ...)
     * @return array{
     *     pairs: array<int, array{dolProduct: object, shopifyVariant: object}>,
     *     unmatchedDolProducts: array<int, object>,
     *     unmatchedShopifyVariants: array<int, object>
     * }
     */
    public static function matchVariants(array $shopifyVariants, array $dolProducts)
    {
        // Index Shopify par SKU normalisé. En cas de doublon de SKU côté Shopify (ne devrait pas
        // arriver en pratique), la DERNIÈRE variante du flux l'emporte — comportement déjà celui
        // du code d'origine (écriture successive dans le même tableau associatif).
        $shopifyBySku = [];
        foreach ($shopifyVariants as $variant) {
            $sku = self::normalizeSku(isset($variant->sku) ? $variant->sku : null);
            if ($sku !== null) {
                $shopifyBySku[$sku] = $variant;
            }
        }

        $pairs = [];
        $unmatchedDolProducts = [];
        $usedShopifySkus = [];

        foreach ($dolProducts as $dolProduct) {
            $dolSku = self::normalizeSku(isset($dolProduct->ref) ? $dolProduct->ref : null);

            if ($dolSku !== null && isset($shopifyBySku[$dolSku])) {
                $pairs[] = [
                    'dolProduct' => $dolProduct,
                    'shopifyVariant' => $shopifyBySku[$dolSku],
                ];
                $usedShopifySkus[$dolSku] = true;
            } else {
                $unmatchedDolProducts[] = $dolProduct;
            }
        }

        // Variantes Shopify jamais consommées par un appariement (SKU sans déclinaison Dolibarr
        // correspondante — AC2). Les variantes sans SKU du tout (case B, "Default Title") ne sont
        // jamais entrées dans $shopifyBySku : il faut les retrouver séparément pour ne pas les
        // perdre du côté "non apparié".
        $unmatchedShopifyVariants = [];
        foreach ($shopifyVariants as $variant) {
            $sku = self::normalizeSku(isset($variant->sku) ? $variant->sku : null);
            if ($sku === null) {
                $unmatchedShopifyVariants[] = $variant;
            } elseif (!isset($usedShopifySkus[$sku])) {
                $unmatchedShopifyVariants[] = $variant;
            }
        }

        return [
            'pairs' => $pairs,
            'unmatchedDolProducts' => $unmatchedDolProducts,
            'unmatchedShopifyVariants' => $unmatchedShopifyVariants,
        ];
    }
}
