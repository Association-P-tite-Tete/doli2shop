<?php
/**
 * @file        class/supportmanager.community.php
 * @brief       Stub community edition de SupportManager
 *
 * @package     Doli2Shop
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.3.0
 *
 * Substitué à class/supportmanager.class.php au moment de la sync vers le
 * miroir public. Cette version community ne contient AUCUNE logique de
 * validation de licence — elle déclare explicitement `valid=false,
 * is_community=true` pour que le code consommateur puisse distinguer
 * « pas de licence » de « licence valide » et afficher l'encart approprié.
 *
 * Méthodes consommées par le code public (audit 2026-05-04) :
 *   - __construct($db)
 *   - getSupportConfig()
 *   - validateSupportByEmail($email)
 *   - getSupportStatus()
 *   - validateSupport($serial)
 *
 * Contracts retournés alignés exactement sur le SupportManager privé pour
 * ne casser aucun consommateur (clés requises : success, support_data,
 * validation_enabled, cache_enabled, status, cached, etc.).
 *
 * Origine : Epic 41 / Story 41.2 (P2+P3+P4+P5 review 41.2, D1=valid_false).
 */

require_once dirname(__FILE__) . '/LoggerTrait.php';

class SupportManager
{
    use LoggerTrait;

    /**
     * @var DoliDB
     */
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->log('SupportManager community edition — pas de validation licence', LOG_INFO);
    }

    /**
     * Renvoie la config support en mode community. Toutes les clés consommées
     * par admin/diagnostic.php sont présentes (5 clés du privé + flag community).
     */
    public function getSupportConfig(): array
    {
        return [
            'support_email' => '',
            'serial_number' => '',
            'validation_enabled' => false,
            'cache_enabled' => false,
            'api_endpoint' => '',
            'api_timeout' => 0,
            'cache_ttl' => 0,
            'is_community' => true,
            'last_validation' => null,
            'last_validation_status' => 'community',
        ];
    }

    /**
     * Validation explicitement INVALIDE en community.
     * Le code consommateur doit traiter `is_community === true` séparément
     * de `valid === true` (cas premium). Cf. admin/diagnostic.php.
     *
     * Décision D1 review 41.2 : `valid=false, is_community=true` empêche
     * un drop-replace malveillant du stub sur une install premium pour
     * bypasser la licence.
     */
    public function validateSupport($serialNumber, $additionalData = []): array
    {
        return [
            'valid' => false,
            'is_community' => true,
            'support_active' => false,
            'status' => 'community',
            'cached' => false,
            'message' => 'Version Community Edition — support technique disponible via adhésion P\'tite Tête (https://www.ptitetete.org/).',
            'expiration_date' => null,
            'license_type' => 'community',
            'serial_number' => '',
        ];
    }

    /**
     * Validation par email — retourne le contrat attendu par le consommateur
     * privé : `{success, support_data: {...}}`. Le wrapper success/support_data
     * est préservé pour ne pas casser admin/diagnostic.php:847.
     */
    public function validateSupportByEmail($email, $additionalData = []): array
    {
        return [
            'success' => true,
            'is_community' => true,
            'support_data' => $this->validateSupport('', $additionalData),
        ];
    }

    /**
     * Statut support local. Renvoie un état « community » avec toutes les
     * clés que le code consommateur lit (is_valid, is_community, etc.).
     */
    public function getSupportStatus(): array
    {
        return [
            'is_valid' => false,
            'is_community' => true,
            'support_active' => false,
            'license_type' => 'community',
            'status' => 'community',
            'cached' => false,
            'expiration_date' => null,
            'days_remaining' => null,
            'last_check' => null,
            'message' => 'Community Edition — pas de licence requise.',
        ];
    }

    /**
     * Sync toujours autorisée en community : la version libre n'a pas
     * de gating de licence — c'est l'usage attendu.
     */
    public function isSyncAllowed(): bool
    {
        return true;
    }

    /**
     * URL de renouvellement → page produit P'tite Tête (adhésion + premium).
     */
    public function getRenewalUrl(): string
    {
        return 'https://www.ptitetete.org/products/shopify-integration-pour-dolibarr-v2-x';
    }

    /**
     * Pas de message d'expiration en community.
     */
    public function getExpirationMessage(): string
    {
        return '';
    }

    /**
     * Catch-all : méthodes non implémentées renvoient un array community
     * avec `success=false` (truthy mais explicite). Évite les fatal errors
     * si une méthode privée est ajoutée plus tard sans mise à jour du stub.
     *
     * Note : si une nouvelle méthode privée doit retourner bool/string,
     * il faut l'implémenter explicitement dans ce stub plutôt que de
     * laisser tomber dans __call (cf. defer review 41.2).
     */
    public function __call($name, $arguments)
    {
        $this->log("SupportManager community : méthode '$name' non implémentée — réponse par défaut", LOG_DEBUG);
        return [
            'is_community' => true,
            'success' => false,
            'message' => 'Fonctionnalité support non disponible en Community Edition.',
        ];
    }
}
