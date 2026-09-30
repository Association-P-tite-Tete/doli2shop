<?php
/**
 * @file        class/shopifyrightsChecker.class.php
 * @brief       Verification of Dolibarr rights required for Shopify Integration
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    class
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.6.0
 * @since       2.0.27
 * @link        http://www.dolibarr.org
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct
if (!defined('DOL_DOCUMENT_ROOT')) {
    print "Erreur, accès interdit.\n";
    exit();
}

/**
 * Classe pour vérifier les droits Dolibarr requis par le module Shopify Integration
 */
class ShopifyRightsChecker
{
    /**
     * @var DoliDB Database handler
     */
    private $db;
    
    /**
     * @var User User object
     */
    private $user;
    
    /**
     * @var array Droits requis par le module
     */
    private $requiredRights = [
        'produit' => [
            'lire' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Impossible de lire les produits existants pour synchronisation'
            ],
            'creer' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Synchronisation Shopify → Dolibarr BLOQUÉE - Les nouveaux produits ne seront pas importés'
            ]
        ],
        'categorie' => [
            'lire' => [
                'required' => true,
                'critical' => false,
                'impact' => 'Impossible de lire les catégories pour collections Shopify'
            ],
            'creer' => [
                'required' => true,
                'critical' => false,
                'impact' => 'Collections Shopify ne pourront pas créer de nouvelles catégories'
            ]
        ],
        'commande' => [
            'lire' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Impossible de lire les commandes existantes'
            ],
            'creer' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Import commandes Shopify IMPOSSIBLE'
            ],
            'valider' => [
                'required' => false,
                'critical' => false,
                'impact' => 'Les commandes importées ne pourront pas être automatiquement validées'
            ]
        ],
        'facture' => [
            'lire' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Impossible de lire les factures liées aux commandes'
            ],
            'creer' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Import commandes CRITIQUE - Les commandes Shopify ne créeront pas de factures'
            ]
        ],
        'societe' => [
            'lire' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Impossible de lire les informations des clients'
            ],
            'creer' => [
                'required' => true,
                'critical' => true,
                'impact' => 'Nouveaux clients Shopify ne pourront pas être créés dans Dolibarr'
            ]
        ],
        'stock' => [
            'lire' => [
                'required' => true,
                'critical' => false,
                'impact' => 'Synchronisation des stocks impossible en lecture'
            ],
            'mouvement' => [
                'required' => true,
                'critical' => false,
                'impact' => 'Impossible de modifier les niveaux de stock lors des synchronisations'
            ]
        ],
        'admin' => [
            'config' => [
                'required' => true,
                'critical' => false,
                'impact' => 'Configuration du module impossible'
            ],
            'cron' => [
                'required' => true,
                'critical' => false,
                'impact' => 'Configuration automatique des tâches planifiées impossible'
            ]
        ]
    ];
    
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     * @param User $user Current user
     */
    public function __construct($db, $user)
    {
        $this->db = $db;
        $this->user = $user;
    }
    
    /**
     * Vérifie tous les droits requis
     *
     * @return array Status de tous les droits
     */
    public function checkAllRights()
    {
        $results = [];
        foreach ($this->requiredRights as $module => $actions) {
            $results[$module] = $this->checkModuleRights($module, $actions);
        }
        return $results;
    }
    
    /**
     * Vérifie les droits d'un module spécifique
     *
     * @param string $module Module name
     * @param array $actions Actions to check
     * @return array Rights status
     */
    private function checkModuleRights($module, $actions)
    {
        $status = [];
        foreach ($actions as $action => $config) {
            $hasRight = $this->hasRight($module, $action);
            $status[$action] = [
                'required' => $config['required'],
                'critical' => $config['critical'],
                'current' => $hasRight,
                'impact' => $config['impact'],
                'status' => $this->determineStatus($config['required'], $hasRight, $config['critical'])
            ];
        }
        return $status;
    }
    
    /**
     * Vérifie si l'utilisateur a un droit spécifique
     *
     * @param string $module Module name
     * @param string $action Action name
     * @return bool True if user has the right
     */
    private function hasRight($module, $action)
    {
        // Un administrateur a tous les droits en pratique : le cœur de Dolibarr
        // (loadRights(), user.class.php) ne force en base que rights->user->user
        // et rights->user->self, jamais les droits métier (produit, commande,
        // etc.). Sans ce bypass explicite, un administrateur sans droit
        // explicitement positionné était signalé à tort comme dépourvu de droits
        // critiques. Même modèle que le cas 'admin_config' ci-dessous.
        if (!empty($this->user->admin)) {
            return true;
        }

        switch ($module . '_' . $action) {
            // Produits
            case 'produit_lire':
                return (bool) $this->user->hasRight('produit', 'lire');
            case 'produit_creer':
                return (bool) $this->user->hasRight('produit', 'creer');

            // Catégories
            case 'categorie_lire':
                return (bool) $this->user->hasRight('categorie', 'lire');
            case 'categorie_creer':
                return (bool) $this->user->hasRight('categorie', 'creer');

            // Commandes
            case 'commande_lire':
                return (bool) $this->user->hasRight('commande', 'lire');
            case 'commande_creer':
                return (bool) $this->user->hasRight('commande', 'creer');
            case 'commande_valider':
                return (bool) $this->user->hasRight('commande', 'valider');

            // Factures
            case 'facture_lire':
                return (bool) $this->user->hasRight('facture', 'lire');
            case 'facture_creer':
                return (bool) $this->user->hasRight('facture', 'creer');

            // Société/Tiers
            case 'societe_lire':
                return (bool) $this->user->hasRight('societe', 'lire');
            case 'societe_creer':
                return (bool) $this->user->hasRight('societe', 'creer');

            // Stock
            case 'stock_lire':
                return (bool) $this->user->hasRight('stock', 'lire');
            case 'stock_mouvement':
                return (bool) $this->user->hasRight('stock', 'mouvement');

            // Admin
            case 'admin_config':
                return !empty($this->user->admin);
            case 'admin_cron':
                // Review 3 couches du 09/09/2026 (MEDIUM) : le `!empty($this->user->admin) &&`
                // d'origine est devenu du code mort trompeur depuis l'ajout du bypass admin en
                // tête de hasRight() (:196-198 ci-dessus), qui retourne déjà `true` avant d'entrer
                // dans ce switch — un administrateur n'atteint donc jamais cette ligne. Retiré
                // pour aligner ce cas sur tous les autres (simple délégation à `hasRight()`) ;
                // comportement final inchangé pour tout appelant.
                return (bool) $this->user->hasRight('cron', 'create');

            default:
                return false;
        }
    }
    
    /**
     * Détermine le statut d'un droit
     *
     * @param bool $required Est-ce que le droit est requis
     * @param bool $current L'utilisateur a-t-il ce droit
     * @param bool $critical Est-ce un droit critique
     * @return string Status (success, warning, error, critical)
     */
    private function determineStatus($required, $current, $critical)
    {
        if (!$required) {
            return $current ? 'success' : 'info';
        }
        
        if ($current) {
            return 'success';
        }
        
        return $critical ? 'critical' : 'error';
    }
    
    /**
     * Génère un rapport HTML des droits
     *
     * @param array $rightsStatus Status des droits
     * @return string HTML report
     */
    public function renderRightsReport($rightsStatus)
    {
        global $langs;
        
        $html = '';
        
        // Résumé
        $summary = $this->generateSummary($rightsStatus);
        
        $html .= '<div class="info">';
        $html .= '<p><strong>' . $langs->trans('UserRightsCheck') . '</strong></p>';
        $html .= '<p>Utilisateur actuel : <strong>' . $this->user->login . '</strong> (' . $this->user->email . ')</p>';
        $html .= '</div>';
        
        // Statistiques
        $html .= '<div class="div-table-responsive-no-min">';
        $html .= '<table class="noborder centpercent">';
        $html .= '<tr class="liste_titre"><td colspan="2">' . $langs->trans('RightsSummary') . '</td></tr>';
        
        $html .= '<tr><td>' . $langs->trans('RightsTotal') . '</td>';
        $html .= '<td><strong>' . $summary['total'] . '</strong></td></tr>';
        
        $html .= '<tr><td>' . $langs->trans('RightsActive') . '</td>';
        $html .= '<td><span class="success"><strong>' . $summary['active'] . '</strong></span></td></tr>';
        
        $html .= '<tr><td>' . $langs->trans('RightsMissing') . '</td>';
        $html .= '<td><span class="' . ($summary['missing'] > 0 ? 'warning' : 'success') . '"><strong>' . $summary['missing'] . '</strong></span></td></tr>';
        
        $html .= '<tr><td>' . $langs->trans('RightsCritical') . '</td>';
        $html .= '<td><span class="' . ($summary['critical'] > 0 ? 'critical' : 'success') . '"><strong>' . $summary['critical'] . '</strong></span></td></tr>';
        
        $html .= '</table>';
        $html .= '</div>';
        
        // Droits manquants critiques
        if ($summary['critical'] > 0) {
            $html .= '<br><div class="error">';
            $html .= '<strong>' . $langs->trans('CriticalRightsMissing') . '</strong>';
            $html .= '<ul>';
            foreach ($rightsStatus as $module => $actions) {
                foreach ($actions as $action => $status) {
                    if ($status['status'] === 'critical') {
                        $html .= '<li>' . $this->getModuleName($module) . ' : ' . $this->getActionName($action) . '</li>';
                        $html .= '<li style="margin-left: 20px; color: #666;"><em>' . $status['impact'] . '</em></li>';
                    }
                }
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        // Détail par module
        foreach ($rightsStatus as $module => $actions) {
            $html .= '<br><div class="div-table-responsive-no-min">';
            $html .= '<table class="noborder centpercent">';
            $html .= '<tr class="liste_titre"><td colspan="3">' . $this->getModuleName($module) . '</td></tr>';
            
            foreach ($actions as $action => $status) {
                $statusClass = $this->getStatusClass($status['status']);
                $statusIcon = $this->getStatusIcon($status['status']);
                $statusText = $this->getStatusText($status['status']);
                
                $html .= '<tr>';
                $html .= '<td>' . $this->getActionName($action) . '</td>';
                $html .= '<td class="center"><span class="' . $statusClass . '">' . $statusIcon . ' ' . $statusText . '</span></td>';
                $html .= '<td>' . ($status['current'] ? '' : '<em style="color: #666;">' . $status['impact'] . '</em>') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
            $html .= '</div>';
        }
        
        // Actions correctives
        if ($summary['missing'] > 0) {
            $html .= '<br><div class="tabsAction">';
            $html .= '<a class="butAction" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=generate_rights_sql">';
            $html .= $langs->trans('GenerateRightsSQL');
            $html .= '</a>';
            $html .= '<a class="butAction" href="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?action=show_rights_instructions">';
            $html .= $langs->trans('ShowRightsInstructions');
            $html .= '</a>';
            $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * Génère un résumé des droits
     *
     * @param array $rightsStatus Status des droits
     * @return array Summary
     */
    private function generateSummary($rightsStatus)
    {
        $summary = [
            'total' => 0,
            'active' => 0,
            'missing' => 0,
            'critical' => 0
        ];
        
        foreach ($rightsStatus as $module => $actions) {
            foreach ($actions as $action => $status) {
                $summary['total']++;
                if ($status['current']) {
                    $summary['active']++;
                } else {
                    if ($status['critical']) {
                        $summary['critical']++;
                    } else {
                        // Ne compter dans "missing" que les droits optionnels (non critiques)
                        $summary['missing']++;
                    }
                }
            }
        }
        
        return $summary;
    }
    
    /**
     * Get module display name
     *
     * @param string $module Module name
     * @return string Display name
     */
    private function getModuleName($module)
    {
        global $langs;
        
        $names = [
            'produit' => $langs->trans('Products'),
            'categorie' => $langs->trans('Categories'),
            'commande' => $langs->trans('Orders'),
            'facture' => $langs->trans('Bills'),
            'societe' => $langs->trans('ThirdParties'),
            'stock' => $langs->trans('Stocks'),
            'admin' => $langs->trans('Administration')
        ];
        
        return $names[$module] ?? $module;
    }
    
    /**
     * Get action display name
     *
     * @param string $action Action name
     * @return string Display name
     */
    private function getActionName($action)
    {
        global $langs;
        
        $names = [
            'lire' => $langs->trans('Read'),
            'creer' => $langs->trans('Create'),
            'valider' => $langs->trans('Validate'),
            'mouvement' => $langs->trans('StockMovement'),
            'config' => $langs->trans('Configuration'),
            'cron' => $langs->trans('CronJobs')
        ];
        
        return $names[$action] ?? $action;
    }
    
    /**
     * Get CSS class for status
     */
    private function getStatusClass($status)
    {
        return $status;
    }
    
    /**
     * Get icon for status
     */
    private function getStatusIcon($status)
    {
        $icons = [
            'success' => '[OK]',
            'error' => '[ERROR]',
            'critical' => '🚫',
            'warning' => '[WARNING]',
            'info' => 'ℹ️'
        ];
        
        return $icons[$status] ?? '';
    }
    
    /**
     * Get text for status
     */
    private function getStatusText($status)
    {
        global $langs;
        
        $texts = [
            'success' => $langs->trans('Active'),
            'error' => $langs->trans('Missing'),
            'critical' => $langs->trans('CriticalMissing'),
            'warning' => $langs->trans('Warning'),
            'info' => $langs->trans('Optional')
        ];
        
        return $texts[$status] ?? $status;
    }
    
    /**
     * Génère un script SQL pour corriger les droits manquants
     *
     * @param array $rightsStatus Status des droits
     * @return string SQL script
     */
    public function generateCorrectionSQL($rightsStatus)
    {
        $sql = "-- Script SQL pour corriger les droits manquants\n";
        $sql .= "-- Utilisateur: " . $this->user->login . "\n";
        $sql .= "-- Généré le: " . date('Y-m-d H:i:s') . "\n\n";
        
        $missingRights = [];
        foreach ($rightsStatus as $module => $actions) {
            foreach ($actions as $action => $status) {
                if (!$status['current'] && $status['required']) {
                    $missingRights[] = [
                        'module' => $module,
                        'action' => $action,
                        'critical' => $status['critical']
                    ];
                }
            }
        }
        
        if (empty($missingRights)) {
            $sql .= "-- Aucun droit manquant détecté\n";
            return $sql;
        }
        
        $sql .= "-- ATTENTION: Exécutez ce script avec précaution\n";
        $sql .= "-- Vérifiez les permissions avant d'accorder de nouveaux droits\n\n";
        
        foreach ($missingRights as $right) {
            $sql .= $this->generateRightSQL($right['module'], $right['action'], $right['critical']);
        }
        
        return $sql;
    }
    
    /**
     * Génère le SQL pour un droit spécifique
     */
    private function generateRightSQL($module, $action, $critical = false)
    {
        $comment = $critical ? " -- CRITIQUE" : "";
        
        // Cette implémentation dépend de la structure des droits Dolibarr
        // Normalement il faudrait ajouter dans llx_user_rights
        $sql = "-- Accorder droit $action pour module $module$comment\n";
        $sql .= "-- INSERT INTO " . MAIN_DB_PREFIX . "user_rights (entity, fk_user, fk_id, subperms, perms, attribute) VALUES (...);$comment\n\n";
        
        return $sql;
    }
}