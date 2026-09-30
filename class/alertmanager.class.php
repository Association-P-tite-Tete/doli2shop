<?php
/**
 * @file        class/alertmanager.class.php
 * @brief       Proactive alert system for critical Shopify integration errors
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.2.1
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/LoggerTrait.php';
require_once dirname(__FILE__) . '/healthchecker.class.php';
require_once dirname(__FILE__) . '/sqlutils.class.php';
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Required for dolibarr_set_const() — used to persist DOLI2SHOP_ALERT_LAST_SENT (Story 30.2)
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}

/**
 * Class AlertManager
 * Gère la détection d'erreurs critiques et l'envoi d'alertes email proactives
 *
 * Story 30.1 — Système d'alertes proactif v2.2.1
 */
class AlertManager
{
    use LoggerTrait;

    /** @var DoliDB Database handler */
    private $db;

    /** @var bool Alertes activées */
    private $enabled;

    /** @var string Email destinataire */
    private $alertEmail;

    /** @var int Seuil d'erreurs consécutives */
    private $threshold;

    /** @var array Types d'alertes activés */
    private $alertTypes;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->loadConfig();
    }

    /**
     * Charge la configuration des alertes depuis les constantes Dolibarr
     *
     * @return void
     */
    private function loadConfig()
    {
        $this->enabled = (bool) getDolGlobalInt('DOLI2SHOP_ALERT_ENABLED', 0);
        $this->alertEmail = getDolGlobalString('DOLI2SHOP_ALERT_EMAIL', '');
        $this->threshold = getDolGlobalInt('DOLI2SHOP_ALERT_THRESHOLD', 3);

        // Story 63-11 (AC5) : 'license' ajouté aux types par défaut — réutilise le pipeline
        // d'alerte existant (throttle par type, cron déjà planifié) pour rendre visibles des
        // échecs répétés de validation licence, sans nouveau canal ni nouvelle configuration
        // pour un admin ayant déjà activé les alertes proactives.
        $typesStr = getDolGlobalString('DOLI2SHOP_ALERT_TYPES', 'webhook,health,license');
        $this->alertTypes = array_map('trim', explode(',', $typesStr));
    }

    /**
     * Vérifie si le système d'alertes est correctement configuré
     *
     * @return bool True si les alertes sont activées et configurées
     */
    public function isConfigured()
    {
        return $this->enabled && !empty($this->alertEmail) && filter_var($this->alertEmail, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Vérifie si un type d'alerte est activé
     *
     * @param string $type Type d'alerte (webhook, health, sync)
     * @return bool True si le type est actif
     */
    public function isAlertEnabled($type)
    {
        return $this->enabled && in_array($type, $this->alertTypes);
    }

    /**
     * Vérifie si une alerte peut être envoyée (throttling/cooldown par type)
     *
     * Story 30.2 — Patch code review : throttle par type (webhook/health/sync)
     * Permet à une alerte critique d'un type de passer même si un autre type a été envoyé récemment.
     *
     * @param string $type Type d'alerte (webhook, health, sync)
     * @return bool True si assez de temps s'est écoulé depuis la dernière alerte de ce type
     */
    public function canSendAlert($type = 'global')
    {
        $constName = 'DOLI2SHOP_ALERT_LAST_SENT_' . strtoupper($type);
        $lastSent = getDolGlobalInt($constName, 0);
        $cooldownMinutes = getDolGlobalInt('DOLI2SHOP_ALERT_COOLDOWN', 60);
        $cooldownSeconds = $cooldownMinutes * 60;

        if ($lastSent === 0) {
            return true; // Jamais envoyé pour ce type
        }

        // Patch code review : si l'horloge a reculé (NTP), considérer comme jamais envoyé
        if ($lastSent > dol_now()) {
            return true;
        }

        return (dol_now() - $lastSent) >= $cooldownSeconds;
    }

    /**
     * Méthode principale : vérifie toutes les alertes et envoie les emails si nécessaire
     *
     * @return array Résultat avec 'checked' (types vérifiés), 'alerts_sent' (alertes envoyées), 'throttled' (supprimées)
     */
    public function checkAndAlert()
    {
        $result = [
            'checked' => 0,
            'alerts_sent' => 0,
            'throttled' => false,
            'errors' => [],
        ];

        if (!$this->isConfigured()) {
            $this->log("AlertManager::checkAndAlert - Alertes non configurées ou désactivées", LOG_DEBUG);
            return $result;
        }

        // Vérification webhooks en erreur (throttle par type — patch code review 30.2)
        if ($this->isAlertEnabled('webhook')) {
            $result['checked']++;
            if (!$this->canSendAlert('webhook')) {
                $this->log("AlertManager::checkAndAlert - Cooldown webhook actif, alerte supprimée", LOG_DEBUG);
                $result['throttled'] = true;
            } else {
                $webhookErrors = $this->checkWebhookErrors();
                if (!empty($webhookErrors)) {
                    $sent = $this->sendAlert('webhook', $webhookErrors);
                    if ($sent) {
                        $result['alerts_sent']++;
                    }
                }
            }
        }

        // Vérification santé globale (throttle par type — patch code review 30.2)
        if ($this->isAlertEnabled('health')) {
            $result['checked']++;
            if (!$this->canSendAlert('health')) {
                $this->log("AlertManager::checkAndAlert - Cooldown health actif, alerte supprimée", LOG_DEBUG);
                $result['throttled'] = true;
            } else {
                $healthData = $this->checkHealthStatus();
                if (!empty($healthData)) {
                    $sent = $this->sendAlert('health', $healthData);
                    if ($sent) {
                        $result['alerts_sent']++;
                    }
                }
            }
        }

        // Vérification échecs répétés de validation licence (Story 63-11, AC5)
        if ($this->isAlertEnabled('license')) {
            $result['checked']++;
            if (!$this->canSendAlert('license')) {
                $this->log("AlertManager::checkAndAlert - Cooldown license actif, alerte supprimée", LOG_DEBUG);
                $result['throttled'] = true;
            } else {
                $licenseFailure = $this->checkLicenseValidationFailures();
                if (!empty($licenseFailure)) {
                    $sent = $this->sendAlert('license', $licenseFailure);
                    if ($sent) {
                        $result['alerts_sent']++;
                    }
                }
            }
        }

        $this->log("AlertManager::checkAndAlert - Vérifié: " . $result['checked'] . " types, Alertes envoyées: " . $result['alerts_sent'], LOG_INFO);

        return $result;
    }

    /**
     * Détecte des échecs consécutifs de validation licence (Story 63-11, AC5).
     *
     * Réutilise le compteur déjà tenu par SupportManager::validateDualChannel() pour son repli
     * progressif (AC3) — aucune nouvelle donnée, aucun nouveau canal : seulement une lecture
     * de plus dans le pipeline d'alerte existant (même seuil DOLI2SHOP_ALERT_THRESHOLD que les
     * vérifications webhook/health).
     *
     * @return array|null Données d'alerte, ou null si sous le seuil configuré
     */
    public function checkLicenseValidationFailures()
    {
        require_once dirname(__FILE__) . '/supportmanager.class.php';

        try {
            $supportManager = new SupportManager($this->db);
            $summary = $supportManager->getDualChannelFailureSummary();
        } catch (\Throwable $e) {
            $this->log("AlertManager::checkLicenseValidationFailures - Exception: " . $e->getMessage(), LOG_ERR);
            return null;
        }

        $failureCount = isset($summary['failure_count']) ? (int) $summary['failure_count'] : 0;

        // ⚠️ HIGH de la revue 3 couches du 29/08 : le test `>=` seul suffisait quand le seuil
        // vaut 0 — `0 >= 0` est vrai, donc une alerte « 0 échec consécutif » partait à CHAQUE
        // exécution du CRON, sur une installation parfaitement saine. Un seuil à 0 est une
        // saisie plausible (un administrateur qui croit désactiver le seuil). La garde
        // `$failureCount > 0` rend l'alerte impossible sans échec réel, quel que soit le seuil.
        if ($failureCount > 0 && $failureCount >= $this->threshold) {
            $this->log(
                "AlertManager::checkLicenseValidationFailures - {$failureCount} échecs consécutifs détectés (seuil: {$this->threshold})",
                LOG_WARNING
            );
            return [
                'failure_count' => $failureCount,
                'last_result' => $summary['last_result'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Détecte les webhooks en échec répété (>= seuil consécutif)
     *
     * @return array|null Données d'erreur ou null si pas d'alerte nécessaire
     */
    public function checkWebhookErrors()
    {
        global $conf;

        $sql = "SELECT COUNT(*) as error_count,";
        $sql .= " MAX(last_error) as latest_error,";
        $sql .= " MAX(date_reception) as latest_date";
        $sql .= " FROM " . MAIN_DB_PREFIX . "doli2shop_webhook_events";
        $sql .= " WHERE status = 2";  // status = error
        $sql .= " AND tries >= " . ((int) $this->threshold);
        $sql .= " AND date_reception > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->log("AlertManager::checkWebhookErrors - Erreur SQL: " . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($obj && (int) $obj->error_count >= $this->threshold) {
            $this->log("AlertManager::checkWebhookErrors - " . $obj->error_count . " erreurs webhook détectées (seuil: " . $this->threshold . ")", LOG_WARNING);
            return [
                'error_count' => (int) $obj->error_count,
                'latest_error' => $obj->latest_error,
                'latest_date' => $obj->latest_date,
            ];
        }

        return null;
    }

    /**
     * Vérifie le statut de santé via HealthChecker
     *
     * @return array|null Données de santé ou null si OK
     */
    public function checkHealthStatus()
    {
        try {
            $healthChecker = new HealthChecker($this->db);
            $health = $healthChecker->getHealthStatus();

            if (isset($health['status']) && $health['status'] === 'red') {
                $this->log("AlertManager::checkHealthStatus - Statut RED détecté, errors_24h=" . ($health['errors_24h'] ?? 0), LOG_WARNING);
                return $health;
            }
        } catch (\Exception $e) {
            $this->log("AlertManager::checkHealthStatus - Exception: " . $e->getMessage(), LOG_ERR);
        }

        return null;
    }

    /**
     * Envoie un email d'alerte
     *
     * @param string $type Type d'alerte (webhook, health, sync)
     * @param array  $data Données de l'alerte
     * @return bool True si l'email a été envoyé
     */
    public function sendAlert($type, $data)
    {
        global $conf, $langs;

        if (empty($this->alertEmail)) {
            return false;
        }

        $langs->load('shopifyintegration@doli2shop');

        $storeHost = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', '');
        $defaultFrom = !empty($storeHost) ? 'noreply@' . $storeHost : 'noreply@doli2shop.local';
        $from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', $defaultFrom);
        $subject = $this->buildSubject($type, $data);
        $message = $this->buildMessage($type, $data);

        // Utiliser CMailFile Dolibarr
        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';

        $mail = new \CMailFile(
            $subject,
            $this->alertEmail,
            $from,
            $message,
            [],    // Fichiers joints
            [],    // MIME types
            [],    // Noms fichiers
            '',    // CC
            '',    // BCC
            0,     // Priorité
            1,     // Type HTML
            '',    // Errors to
            '',    // CSS
            '',    // Track ID
            '',    // More headers
            'standard',
            ''     // Replyto
        );

        $result = $mail->sendfile();

        if ($result) {
            $this->log("AlertManager::sendAlert - Email envoyé type=" . $type . " to=" . $this->alertEmail, LOG_INFO);
            // Story 30.2 (patch code review) : Persister le timestamp PAR TYPE pour le throttling
            if (function_exists('dolibarr_set_const')) {
                $constName = 'DOLI2SHOP_ALERT_LAST_SENT_' . strtoupper($type);
                dolibarr_set_const($this->db, $constName, (string) dol_now(), 'chaine', 0, '', $conf->entity);
            }
        } else {
            $this->log("AlertManager::sendAlert - Échec envoi email type=" . $type . " error=" . $mail->error, LOG_ERR);
        }

        return (bool) $result;
    }

    /**
     * Construit le sujet de l'email d'alerte
     *
     * @param string $type Type d'alerte
     * @param array  $data Données
     * @return string Sujet formaté
     */
    private function buildSubject($type, $data)
    {
        global $langs;

        $store = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', 'Shopify');

        switch ($type) {
            case 'webhook':
                return '[Doli2Shop] ' . $langs->trans('AlertEmailSubjectWebhook', $store, $data['error_count'] ?? 0);
            case 'health':
                return '[Doli2Shop] ' . $langs->trans('AlertEmailSubjectHealth', $store);
            case 'license':
                return '[Doli2Shop] ' . $langs->trans('AlertEmailSubjectLicense', $store, $data['failure_count'] ?? 0);
            default:
                return '[Doli2Shop] ' . $langs->trans('AlertEmailSubjectGeneric', $store);
        }
    }

    /**
     * Construit le corps HTML de l'email d'alerte
     *
     * @param string $type Type d'alerte
     * @param array  $data Données
     * @return string Corps HTML
     */
    private function buildMessage($type, $data)
    {
        global $conf, $langs;

        $adminUrl = dol_buildpath('/doli2shop/admin/setup.php', 2);
        $store = getDolGlobalString('DOLI2SHOP_STORE_HOSTNAME', 'Shopify');
        $timestamp = dol_print_date(dol_now(), 'dayhour');

        $html = '<html><body style="font-family: Arial, sans-serif; color: #333;">';
        $html .= '<h2 style="color: #e74c3c;">&#9888; ' . $langs->trans('AlertEmailTitle') . '</h2>';

        $html .= '<table style="border-collapse: collapse; width: 100%; max-width: 600px;">';
        $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertStore') . '</strong></td>';
        $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . htmlspecialchars($store, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertTimestamp') . '</strong></td>';
        $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . $timestamp . '</td></tr>';

        if ($type === 'webhook') {
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertType') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . $langs->trans('AlertTypeWebhook') . '</td></tr>';
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertErrorCount') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c; font-weight: bold;">' . ((int) ($data['error_count'] ?? 0)) . '</td></tr>';
            if (!empty($data['latest_error'])) {
                $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertLatestError') . '</strong></td>';
                $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;"><code>' . htmlspecialchars($data['latest_error'], ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
            }
        } elseif ($type === 'health') {
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertType') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . $langs->trans('AlertTypeHealth') . '</td></tr>';
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertErrorCount') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c; font-weight: bold;">' . ((int) ($data['errors_24h'] ?? 0)) . ' ' . $langs->trans('AlertErrors24h') . '</td></tr>';
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertWebhooksActive') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . ((int) ($data['webhooks_active'] ?? 0)) . '/' . ((int) ($data['webhooks_total'] ?? 0)) . '</td></tr>';
        } elseif ($type === 'license') {
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertType') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . $langs->trans('AlertTypeLicense') . '</td></tr>';
            $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertErrorCount') . '</strong></td>';
            $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee; color: #e74c3c; font-weight: bold;">' . ((int) ($data['failure_count'] ?? 0)) . '</td></tr>';
            if (!empty($data['last_result']['status'])) {
                $html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>' . $langs->trans('AlertLatestError') . '</strong></td>';
                $html .= '<td style="padding: 8px; border-bottom: 1px solid #eee;"><code>' . htmlspecialchars($data['last_result']['status'], ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
            }
        }

        $html .= '</table>';

        $html .= '<p style="margin-top: 20px;">';
        $html .= '<a href="' . htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') . '" style="background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">';
        $html .= $langs->trans('AlertViewAdmin');
        $html .= '</a></p>';

        $html .= '<p style="color: #999; font-size: 12px; margin-top: 30px;">';
        $html .= $langs->trans('AlertFooter');
        $html .= '</p>';

        $html .= '</body></html>';

        return $html;
    }
}
