<?php
/**
 * @file        class/webhooksettingssaver.class.php
 * @brief       Enregistrement des réglages webhooks (activation/désactivation par topic + constantes générales)
 *
 * Story 2.4.7-1b : extraction de la boucle procédurale d'`admin/webhooks.php`
 * (bloc `if (($action == 'update') && !empty($_POST) && verifToken())`, livré la veille dans le
 * hotfix 2.4.7 après TROIS rounds de revue adversariale) dans une classe injectable, testable sans
 * environnement Dolibarr complet — chose que le script procédural (1035 lignes, `exit` sur une
 * branche) interdisait structurellement à PHPUnit.
 *
 * REFACTOR STRICT — comportement inchangé (cf. Validate de la story) : aucune correction de défaut
 * au passage, aucune amélioration opportuniste. Tout le code ci-dessous, y compris les commentaires
 * de justification des rounds 2 et 3 de la revue 2.4.7, est repris À L'IDENTIQUE d'`admin/webhooks.php`.
 * Seule différence : la classe ne fait plus jamais `setEventMessages()` (interdit — cf. exigence de la
 * story) ; chaque message qui était auparavant émis immédiatement est désormais collecté dans un
 * tableau `errorMessages`, restitué à l'appelant (`admin/webhooks.php`) qui reste seul responsable de
 * l'affichage. Le libellé et le TYPE de chaque message ('errors'/'warnings') sont conservés à
 * l'identique et dans le MÊME ordre d'émission.
 *
 * @package     ShopifyIntegration
 * @subpackage  Class
 * @category    webhooks
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.4.7
 * @since       2.4.7
 * @link        https://doli2shop.ptitetete.org
 */

// Protection contre accès direct (compatible CRON et modules)
if (!defined('DOL_DOCUMENT_ROOT') && !defined('DOLIBARR_INC_FOR_MODULES')) {
    print "Erreur, accès interdit.\n";
    exit();
}

require_once dirname(__FILE__) . '/shopifywebhooks.class.php';
require_once dirname(__FILE__) . '/../lib/doli2shop.lib.php';

// Include compatibility functions for older Dolibarr versions
require_once dirname(__FILE__) . '/../lib/compatibility.lib.php';

// Requis pour dolibarr_set_const() : core/lib/admin.lib.php n'est PAS auto-chargé hors
// contexte admin (donc absent en CRON). Sans cette inclusion, l'appel meurt sur « Call to
// undefined function » — et aucune suite de tests ne peut le voir, le stub définissant la
// fonction (dette Story 53-1). Incident réel : 2026-08-13.
if (defined('DOL_DOCUMENT_ROOT')) {
    @include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}


/**
 * Class WebhookSettingsSaver
 *
 * Applique les changements du formulaire d'administration des webhooks (case à cocher par topic +
 * constantes générales), avec la granularité transactionnelle À L'OPÉRATION héritée du hotfix
 * 2.4.7 : chaque unité (réparation de statut à l'activation, création, CHAQUE suppression
 * d'abonnement, remise à zéro des orphelines, constantes générales) est committée dès qu'elle
 * aboutit et annulée seule en cas d'échec — un topic en échec n'annule jamais les topics déjà
 * traités, et au sein d'un même topic, l'échec de la Nème suppression n'annule jamais les
 * suppressions précédentes déjà appliquées côté Shopify (round 3 de la revue 2.4.7).
 */
class WebhookSettingsSaver
{
    /** @var DoliDb Database handler (dépendance injectée, minimum requis) */
    private $db;

    /** @var ShopifyWebhooks Instance de la gestion Shopify (dépendance injectée, minimum requis) */
    private $shopifyWebhooks;

    /**
     * @param DoliDb         $db              Database handler
     * @param ShopifyWebhooks $shopifyWebhooks Instance API Shopify (réelle ou double de test, aucune
     *                                          reflection nécessaire : un double se construit en
     *                                          sous-classant ShopifyWebhooks et en redéfinissant les
     *                                          méthodes utilisées ci-dessous, son constructeur n'étant
     *                                          jamais appelé par CETTE classe).
     */
    public function __construct($db, ShopifyWebhooks $shopifyWebhooks)
    {
        $this->db = $db;
        $this->shopifyWebhooks = $shopifyWebhooks;
    }

    /**
     * Applique l'activation/désactivation demandée pour chaque topic supporté (cœur du hotfix 2.4.7).
     *
     * Ne fait AUCUNE lecture de `$_POST`/`$_GET` : `$activeTopics` est déjà extrait par l'appelant
     * (page admin) via `GETPOST('active_topics', 'array')` — la classe ne lit pas la requête HTTP.
     *
     * @param  array  $activeTopics                 Topics cochés (clé = topic, valeur présente = actif)
     * @param  int    $currentAdminStoreId           Rowid boutique courante (0/négatif = chemin historique)
     * @param  bool   $currentAdminStoreIsSecondary  true si boutique secondaire (scope API/SQL restreint)
     * @param  int    $entity                        Entité Dolibarr courante ($conf->entity)
     * @param  string $userLogin                     Login utilisateur (traçabilité dol_syslog uniquement)
     * @return array{topicsApplied:int, topicsFailed:int, topicsUnverifiable:int, hadError:bool, errorMessages:array}
     *               topicsUnverifiable (Story 60-1, AC2) : topics dont l'état n'a pas pu être
     *               confirmé auprès de Shopify (listWebhooks() en échec) — ni appliqués, ni en
     *               échec d'écriture, jamais comptés ailleurs.
     *               errorMessages[] = array{text: ?string, errors: ?array, type: string} — mêmes
     *               arguments que setEventMessages($text, $errors, $type), à rejouer par l'appelant
     *               DANS L'ORDRE, sans altération.
     */
    public function saveTopics(
        array $activeTopics,
        int $currentAdminStoreId,
        bool $currentAdminStoreIsSecondary,
        int $entity,
        string $userLogin
    ): array {
        global $langs;

        $db = $this->db;
        $shopifyWebhooks = $this->shopifyWebhooks;

        $error = 0;
        $topicsApplied = 0;
        $topicsFailed = 0;
        // Story 60-1 (AC2) : catégorie DISTINCTE des deux ci-dessus — un topic dont l'état n'a pas
        // pu être vérifié auprès de Shopify n'est ni un succès, ni un échec d'écriture.
        $topicsUnverifiable = 0;
        $errorMessages = array();

        // Story 59-9 (AC1/AC2/AC3) : instantané Shopify mutualisé sur TOUT l'enregistrement
        // (jusqu'à onze topics), récupéré au plus UNE FOIS et paresseusement — uniquement si un
        // topic coché "actif" porte déjà un webhook_id local à confirmer. Avant cette story, la
        // branche ci-dessous concluait "déjà actif, rien à faire" sur la seule présence de ce
        // webhook_id, exactement comme le faisait createWebhookWithDatabase() avant le hotfix
        // 59-4 — sauf que ce hotfix ne couvrait que le bouton "Activer tous les webhooks", pas le
        // bouton ENREGISTRER (cette classe), le chemin que le client emprunte réellement.
        // AC2 : la détection réutilise doli2shopFindPhantomWebhookRows() (même fonction que
        // logPhantomWebhookDivergence()/AC2 de la story 59-4), et la recréation est entièrement
        // déléguée à createWebhookWithDatabase() (même mécanisme, même verrou anti-course, même
        // transaction par opération) — aucune logique de décision n'est dupliquée ici.
        $sharedShopifyWebhooksSnapshot = null;
        $sharedShopifyWebhooksSnapshotFetched = false;

        // Récupérer tous les topics supportés
        $supportedTopics = $shopifyWebhooks->getSupportedTopics();

        foreach ($supportedTopics as $topic) {
            // Round 3 de la revue 3 couches (CRITICAL, Edge Case Hunter + Acceptance Auditor) :
            // le round 2 avait fait du TOPIC l'unité transactionnelle. Insuffisant — un topic peut
            // porter PLUSIEURS webhook_id à supprimer (c'est la raison d'être de ce hotfix). Si la
            // 2e suppression échoue après une 1re réussie, le rollback du topic annulait le DELETE
            // local de la 1re, dont l'abonnement avait pourtant été RÉELLEMENT supprimé chez
            // Shopify : la divergence corrigée entre topics était recréée entre jumelles d'un même
            // topic. L'unité indivisible est donc l'OPÉRATION — un appel Shopify et l'écriture
            // locale qui lui correspond — committée dès qu'elle aboutit.
            $topicHadError = false;
            // Story 60-1 (AC1/AC2) : positionné uniquement quand la vérification Shopify est
            // restée indisponible pour CE topic (échec de listWebhooks()) — jamais compté comme
            // appliqué, jamais confondu avec un échec d'écriture.
            $topicIsUnverifiable = false;

            $is_active = isset($activeTopics[$topic]) ? 1 : 0;

            // Récupérer TOUTES les lignes du scope pour ce topic (Hotfix 2.4.7, périmètre point 2).
            // Hotfix 2.4.6 : filtre boutique IDENTIQUE à la requête d'affichage, via la fonction
            // partagée de lib/doli2shop.lib.php (une seule source de vérité). Avant ce fix, cette
            // requête n'avait AUCUN filtre boutique alors que l'affichage en avait un depuis la
            // Story 49-9 : sur une installation multi-boutiques, fetch_object() récupérait une ligne
            // arbitraire parmi les N (une par boutique) partageant le même topic, désynchronisant
            // la sauvegarde de la boutique réellement affichée.
            // Hotfix 2.4.7 : plus de `ORDER BY ... LIMIT 1` ici. La table n'a AUCUNE contrainte
            // d'unicité sur (topic, entity, fk_store) : deux lignes peuvent coexister pour un même
            // topic (ex. une ligne historique fk_store = 0 et une ligne fk_store = X), chacune avec
            // son propre webhook_id. Se limiter à UNE seule ligne laissait une jumelle vivante
            // survivre à une désactivation — exactement le symptôme résiduel remonté par Nicolas
            // Graillon après le hotfix 2.4.6 (AC4). On récupère donc toutes les lignes du scope et
            // on délègue le choix de « la » ligne représentative à la fonction pure partagée
            // doli2shopSelectPreferredWebhookRow() (AC6), la même que celle consommée par
            // l'affichage plus bas.
            $sql = "SELECT rowid, webhook_id, fk_store, status FROM " . MAIN_DB_PREFIX . "doli2shop_webhooks";
            $sql .= " WHERE topic = '" . $db->escape($topic) . "'";
            $sql .= " AND entity = " . $entity;
            $sql .= doli2shopBuildStoreScopedSqlFilter($currentAdminStoreId, $currentAdminStoreIsSecondary);

            $resql = $db->query($sql);
            if ($resql) {
                $rowsForTopic = array();
                while ($rowObj = $db->fetch_object($resql)) {
                    $rowsForTopic[] = $rowObj;
                }

                // AC2/AC6 : même fonction, mêmes lignes de scope que l'affichage -> l'état
                // retenu ici est garanti identique à celui que l'affichage montrera juste après
                // ce POST (la page se ré-affiche dans la MÊME réponse HTTP, sans redirection).
                $topicState = doli2shopComputeWebhookTopicState($rowsForTopic);

                // Review 3 couches (CRITICAL/HIGH) : `createWebhook()` et `deleteWebhook()`
                // appellent toutes deux `SqlUtils::executeQuery(..., $throwException = true, ...)`.
                // Sur échec du INSERT/DELETE local (verrou, deadlock après 3 tentatives), elles
                // LÈVENT une Exception au lieu de renvoyer false — leur `if ($resql < 0)` interne
                // est du code mort. Sans ce try/catch, l'exception traversait la page : fatal PHP,
                // écran blanc, boucle interrompue en cours de route (les topics suivants n'étaient
                // jamais traités) et transaction laissée ouverte — soit exactement le symptôme
                // silencieux que ce hotfix corrige, sur un autre déclencheur.
                // Le contrat des deux méthodes reste inchangé (hors périmètre) : on se contente
                // ici de convertir toute Exception en erreur visible et de poursuivre le lot.
                try {
                    if ($is_active) {
                        // Round 2 de la revue 3 couches (CRITICAL, trouvé indépendamment par les
                        // trois couches) : cette branche cherchait une « jumelle vivante » par un
                        // foreach premier-trouvé sur des lignes NON TRIÉES, puis écrivait sur elle
                        // — alors que l'affichage montre la ligne préférée. L'action portait donc
                        // sur une ligne et l'affichage sur une autre : case restée décochée sous un
                        // « Configuration sauvegardée », soit le symptôme d'origine reproduit.
                        // L'état d'un topic est une propriété du SCOPE, pas d'une ligne : il est
                        // désormais calculé une seule fois, par la même fonction que l'affichage.
                        if ($topicState['liveRow'] !== null) {
                            $liveRow = $topicState['liveRow'];

                            // Story 59-9 (AC1) : la seule présence d'un webhook_id local ne suffit
                            // plus à conclure "déjà actif" — confirmer contre Shopify avant de ne
                            // rien faire, exactement comme createWebhookWithDatabase() le fait
                            // déjà pour "Activer tous les webhooks" (59-4). Sans cette
                            // confirmation, un abonnement fantôme (webhook_id que Shopify ne
                            // connaît plus) reste figé : le client coche, enregistre, rien ne
                            // change — le motif d'ouverture de cette story.
                            if (!$sharedShopifyWebhooksSnapshotFetched) {
                                $sharedShopifyWebhooksSnapshotFetched = true;
                                // AC3 : UN SEUL appel listWebhooks() pour tout l'enregistrement
                                // (jusqu'à onze topics), jamais un par topic — mutualisation
                                // identique à celle déjà faite par syncWebhooks(). Reset des
                                // erreurs avant l'appel (même précaution que
                                // cronCheckWebhookHealth()) : une erreur laissée par une
                                // opération précédente du même lot (delete/create d'un topic
                                // antérieur) fausserait sinon le diagnostic "l'appel a-t-il
                                // échoué ?" ci-dessous.
                                $shopifyWebhooks->errors = array();
                                $freshWebhooksForSnapshot = $shopifyWebhooks->listWebhooks();
                                if (empty($freshWebhooksForSnapshot) && !empty($shopifyWebhooks->errors)) {
                                    // AC5 (fail-safe) : impossible de prouver quoi que ce soit
                                    // côté Shopify (API down, jeton expiré...). On ne crée rien à
                                    // l'aveugle et on ne perd rien : l'état local est conservé tel
                                    // quel pour TOUT le reste du lot (branche "confirmé vivant"
                                    // ci-dessous, comportement historique) — même principe que le
                                    // fail-safe déjà appliqué par createWebhookWithDatabase() sur
                                    // son propre échec de vérification (AC5, story 59-4).
                                    $sharedShopifyWebhooksSnapshot = null;
                                    dol_syslog(
                                        "webhooksettingssaver.class.php: échec de l'instantané Shopify"
                                        . " mutualisé (AC1/AC3) : " . implode(', ', $shopifyWebhooks->errors)
                                        . " — vérification fantôme ignorée pour cet enregistrement,"
                                        . " état local conservé tel quel.",
                                        LOG_WARNING
                                    );
                                } else {
                                    $sharedShopifyWebhooksSnapshot = $freshWebhooksForSnapshot;
                                }
                            }

                            $topicIsPhantom = false;
                            if ($sharedShopifyWebhooksSnapshot !== null) {
                                // AC2 : même fonction pure que logPhantomWebhookDivergence()
                                // (appariement par identifiant), appliquée à la seule ligne de ce
                                // topic contre l'instantané mutualisé — aucune réimplémentation.
                                $phantomRows = doli2shopFindPhantomWebhookRows(
                                    array($liveRow),
                                    $sharedShopifyWebhooksSnapshot
                                );
                                $topicIsPhantom = !empty($phantomRows);
                            }

                            if ($topicIsPhantom) {
                                // Abonnement fantôme CONFIRMÉ (AC1) : ne plus s'arrêter à "rien à
                                // faire". Recréation entièrement déléguée à
                                // createWebhookWithDatabase() — LE MÊME mécanisme que le bouton
                                // "Activer tous les webhooks" (59-4/AC2) : re-vérification
                                // fraîche SOUS VERROU (protection anti-course inchangée),
                                // nettoyage de la ligne fantôme et création dans SA PROPRE
                                // transaction. Absence VOLONTAIRE de $db->begin()/commit() ici,
                                // pour la même raison que la branche de création plus bas (cf.
                                // son commentaire) : ne jamais envelopper cet appel dans une
                                // transaction ouverte par l'appelant (AC4).
                                $result = $shopifyWebhooks->createWebhookWithDatabase($topic);
                                if ($result < 0) {
                                    $error++;
                                    $topicHadError = true;
                                    $errorMessages[] = $this->buildCreateWebhookFailureMessage($shopifyWebhooks, $topic);
                                } elseif ($shopifyWebhooks->lastCreateOutcome === ShopifyWebhooks::CREATE_OUTCOME_CREATED) {
                                    // Code Review 3 couches (Epic 59, MEDIUM 4) : `$result >= 0` couvre
                                    // TROIS situations (recréation réelle / confirmation "déjà vivant" par
                                    // un gagnant de course concurrent / fail-safe faute de preuve) —
                                    // seule la PREMIÈRE justifie ce log. Avant ce correctif, le support
                                    // lisait "PHANTOM RECREATED" alors qu'aucune recréation n'avait eu
                                    // lieu (état non résolu maquillé en succès), ralentissant exactement
                                    // l'investigation que ce dossier cherche à raccourcir.
                                    dol_syslog(
                                        "webhooksettingssaver.class.php: Webhook PHANTOM RECREATED (checkbox) - topic="
                                        . $topic . " by user=" . $userLogin . " at " . date('Y-m-d H:i:s'),
                                        LOG_INFO
                                    );
                                } else {
                                    // Confirmé "déjà vivant" (course gagnée par un autre appelant) ou
                                    // vérification Shopify indisponible (fail-safe, AC5) : ni un échec, ni
                                    // une recréation — l'état local reste tel quel, rien à journaliser
                                    // comme une action réalisée.
                                    dol_syslog(
                                        "webhooksettingssaver.class.php: Webhook PHANTOM CONFIRMED (checkbox, no mutation) - topic="
                                        . $topic . " outcome=" . ((string) $shopifyWebhooks->lastCreateOutcome)
                                        . " by user=" . $userLogin . " at " . date('Y-m-d H:i:s'),
                                        LOG_INFO
                                    );
                                }
                            } elseif ($sharedShopifyWebhooksSnapshot === null) {
                                // Story 60-1 (AC1/AC2/AC3) : la vérification Shopify est restée
                                // indisponible (échec de listWebhooks() ci-dessus) pour ce topic.
                                // Le repli prudent hérité de 59-9 est intégralement CONSERVÉ : sans
                                // preuve, aucune création n'est tentée, et — cas dominant, celui
                                // décrit par la story (webhook_id + status déjà cohérents) — aucune
                                // écriture locale n'a lieu non plus. Ce qui change : ce topic ne
                                // doit plus jamais être compté parmi les topics appliqués (AC2), et
                                // un message EXPLICITE (connexion Shopify indisponible, réglage non
                                // appliqué) remplace le dol_syslog invisible d'avant cette story.
                                $topicIsUnverifiable = true;
                                dol_syslog(
                                    "webhooksettingssaver.class.php: topic=" . $topic
                                    . " non vérifiable (Shopify injoignable) — état local conservé"
                                    . " tel quel, non compté comme appliqué",
                                    LOG_WARNING
                                );
                                $errorMessages[] = array(
                                    'text' => $langs->trans("WebhookTopicVerificationUnavailable", $topic),
                                    'errors' => null,
                                    'type' => 'warnings',
                                );

                                // Comportement historique 59-9 conservé À L'IDENTIQUE (AC3) : une
                                // ligne locale déjà webhook_id-vivante mais restée à status=0 est
                                // réparée à partir du seul signal disponible localement (l'intention
                                // de la case cochée), Shopify étant injoignable pour trancher
                                // davantage. Ce n'est pas l'écriture visée par AC3 (aucune mutation
                                // Shopify, aucune suppression, aucune conclusion sur la réalité de
                                // l'abonnement) : un simple alignement d'un indicateur d'affichage
                                // local sur une intention déjà exprimée par l'utilisateur.
                                if ((int) $liveRow->status !== 1) {
                                    $db->begin();
                                    if (doli2shopSetWebhookRowsStatus($db, array((int) $liveRow->rowid), 1)) {
                                        $db->commit();
                                    } else {
                                        $db->rollback();
                                        $error++;
                                        $topicHadError = true;
                                        $errorMessages[] = array(
                                            'text' => $langs->trans("ErrorDatabaseQuery"),
                                            'errors' => null,
                                            'type' => 'errors',
                                        );
                                    }
                                }
                            } else {
                                // Confirmé vivant CHEZ SHOPIFY (instantané mutualisé disponible et
                                // topic effectivement retrouvé dedans) : rien à créer. Périmètre
                                // point 4 (2.4.6) : l'état local doit refléter cette réalité.
                                if ((int) $liveRow->status !== 1) {
                                    $db->begin();
                                    if (doli2shopSetWebhookRowsStatus($db, array((int) $liveRow->rowid), 1)) {
                                        $db->commit();
                                    } else {
                                        $db->rollback();
                                        $error++;
                                        $topicHadError = true;
                                        $errorMessages[] = array(
                                            'text' => $langs->trans("ErrorDatabaseQuery"),
                                            'errors' => null,
                                            'type' => 'errors',
                                        );
                                    }
                                }
                            }
                        } else {
                            // Aucun abonnement vivant dans le scope : création réelle.
                            //
                            // Story 2.4.7-2 (Code Review round 2, CRITICAL 1) : createWebhookWithDatabase()
                            // gère DÉSORMAIS SA PROPRE transaction en interne (begin -> createWebhook ->
                            // commit, committée AVANT la libération de son verrou anti-course) — cette
                            // méthode ne doit JAMAIS être enveloppée dans une transaction ouverte par
                            // l'appelant. Les transactions Dolibarr sont imbriquées par COMPTEUR : un
                            // $db->begin() ici aurait fait du commit() interne de createWebhookWithDatabase()
                            // un simple décrémentement de compteur, sans committer réellement tant que CE
                            // bloc n'aurait pas lui-même committé — donc APRÈS la libération du verrou.
                            // Fenêtre rouverte : un autre processus aurait pu acquérir le verrou entre-temps,
                            // ne pas voir l'INSERT non committé (isolation InnoDB) et créer un second
                            // abonnement — la course que ce hotfix devait justement fermer. D'où l'absence
                            // VOLONTAIRE de $db->begin()/commit()/rollback() ici — à ne pas réintroduire.
                            $result = $shopifyWebhooks->createWebhookWithDatabase($topic);
                            if ($result < 0) {
                                $error++;
                                $topicHadError = true;
                                // Story 2.4.7-2 (point 5) : échec d'acquisition du verrou de création
                                // (course avec un autre appelant — autre onglet admin ou CRON de santé
                                // concurrent) — cas distinct d'une vraie erreur Shopify/SQL. Message
                                // explicite et traduit, jamais l'échec silencieux que le hotfix CSRF
                                // 2.4.6 a déjà corrigé sur un autre déclencheur.
                                // Story 59-9 : mise en forme factorisée dans buildCreateWebhookFailureMessage()
                                // — la branche "recréation de fantôme" ci-dessus produit EXACTEMENT le
                                // même message pour le même échec, aucune raison de dupliquer ce bloc
                                // une 3e fois dans la classe.
                                $errorMessages[] = $this->buildCreateWebhookFailureMessage($shopifyWebhooks, $topic);
                            } else {
                                dol_syslog(
                                    "webhooksettingssaver.class.php: Webhook ACTIVATED - topic=" . $topic
                                    . " by user=" . $userLogin . " at " . date('Y-m-d H:i:s'),
                                    LOG_INFO
                                );
                            }
                        }
                    } else {
                        // Désactiver le webhook dans Shopify. Périmètre point 2 (AC4) : supprimer
                        // CHAQUE webhook_id non vide trouvé dans le scope, plus seulement celui de
                        // la ligne la mieux classée — sinon une jumelle vivante (autre fk_store,
                        // autre webhook_id) survit et fait réapparaître le topic comme actif au
                        // prochain affichage. C'est exactement l'enchaînement décrit dans la story.
                        // Plan calculé par une fonction pure et unitairement testée (AC4) : la
                        // partition webhook_id-à-supprimer / rowid-orphelin-à-corriger ne dépend
                        // d'aucun accès DB ni API, seulement des lignes déjà chargées ci-dessus.
                        $deactivationPlan = doli2shopComputeWebhookDeactivationPlan($rowsForTopic);

                        foreach ($deactivationPlan['webhookIdsToDelete'] as $webhookIdToDelete) {
                            // Hotfix 2.4.6 : deleteWebhook() renvoie un bool (@return bool). `false < 0`
                            // vaut `false` en PHP — la branche d'erreur n'était jamais prise et le journal
                            // écrivait « DEACTIVATED » même quand la suppression avait échoué. Comparaison
                            // stricte, auto-documentée. Contrat de deleteWebhook() INCHANGÉ (ses 3 autres
                            // appelants internes testent déjà correctement avec `!$result`), c'est
                            // l'appelant (ici) qui boucle désormais sur toutes les lignes du scope.
                            // Une transaction PAR abonnement : la suppression Shopify est
                            // irréversible, l'écriture locale qui la constate ne doit jamais être
                            // annulée à cause d'un autre abonnement du même topic (round 3).
                            $db->begin();
                            $result = $shopifyWebhooks->deleteWebhook($webhookIdToDelete);
                            if ($result === false) {
                                $db->rollback();
                                $error++;
                                $topicHadError = true;
                                $errorMessages[] = array(
                                    'text' => null,
                                    'errors' => !empty($shopifyWebhooks->errors)
                                        ? $shopifyWebhooks->errors
                                        : array($langs->trans("Error") . ' (' . $topic . ')'),
                                    'type' => 'errors',
                                );
                            } else {
                                $db->commit();
                                dol_syslog(
                                    "webhooksettingssaver.class.php: Webhook DEACTIVATED - topic=" . $topic
                                    . " (webhook_id=" . $webhookIdToDelete . ") by user=" . $userLogin
                                    . " at " . date('Y-m-d H:i:s'),
                                    LOG_INFO
                                );
                            }
                        }

                        if (!$topicHadError && !empty($deactivationPlan['rowIdsToForceInactiveStatus'])) {
                            // Périmètre point 4 (AC4) : lignes sans webhook_id mais encore à
                            // status = 1 (incohérence historique) — deleteWebhook() ne les touche
                            // jamais puisqu'il boucle par webhook_id. On force explicitement l'état
                            // local à "inactif" plutôt que de dépendre uniquement des effets de
                            // bord du DELETE.
                            // Round 2 de la revue (HIGH) : `$db->query()` renvoie false sans lever
                            // d'exception — le try/catch ci-dessus ne l'intercepte donc jamais. Sans
                            // contrôle du retour, un échec SQL laissait committer sous un
                            // « Configuration sauvegardée » : la classe de bug même que ce hotfix
                            // corrige, réintroduite sur un chemin neuf.
                            $db->begin();
                            if (doli2shopSetWebhookRowsStatus($db, $deactivationPlan['rowIdsToForceInactiveStatus'], 0)) {
                                $db->commit();
                            } else {
                                $db->rollback();
                                $error++;
                                $topicHadError = true;
                                $errorMessages[] = array(
                                    'text' => $langs->trans("ErrorDatabaseQuery"),
                                    'errors' => null,
                                    'type' => 'errors',
                                );
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Round 3 (Edge Case Hunter + Blind Hunter) : ce bloc n'annulait rien et ne
                    // marquait pas le topic en erreur. Depuis que les transactions sont ouvertes
                    // par opération, une exception peut survenir transaction ouverte : on annule
                    // l'opération EN VOL — et elle seule, les précédentes étant déjà committées en
                    // regard de mutations Shopify réelles.
                    if (!empty($db->transaction_opened)) {
                        $db->rollback();
                    }
                    $error++;
                    $topicHadError = true;
                    dol_syslog(
                        "webhooksettingssaver.class.php: exception sur le topic " . $topic . " : " . $e->getMessage(),
                        LOG_ERR
                    );
                    $errorMessages[] = array(
                        'text' => null,
                        'errors' => array($langs->trans("Error") . ' (' . $topic . ') : ' . $e->getMessage()),
                        'type' => 'errors',
                    );
                }
            } else {
                $error++;
                $topicHadError = true;
                dol_syslog(
                    "webhooksettingssaver.class.php: SQL error fetching existing webhooks: " . $db->lasterror(),
                    LOG_ERR
                );
                $errorMessages[] = array(
                    'text' => $langs->trans("ErrorDatabaseQuery"),
                    'errors' => null,
                    'type' => 'errors',
                );
            }

            // Comptage pour le message final : chaque opération a déjà été committée ou annulée
            // individuellement, il ne reste qu'à qualifier le résultat d'ensemble.
            // Story 60-1 (AC2) : un échec d'écriture prime toujours sur "non vérifiable" — si les
            // deux drapeaux sont positionnés (cas rare : la réparation de statut best-effort a
            // elle-même échoué), c'est bien un échec qu'il faut reporter, jamais une simple
            // incertitude.
            if ($topicHadError) {
                $topicsFailed++;
            } elseif ($topicIsUnverifiable) {
                $topicsUnverifiable++;
            } else {
                $topicsApplied++;
            }
        }

        return array(
            'topicsApplied' => $topicsApplied,
            'topicsFailed' => $topicsFailed,
            // Story 60-1 (AC2) : ni succès, ni échec d'écriture — jamais compté ailleurs.
            'topicsUnverifiable' => $topicsUnverifiable,
            // Seule la nature booléenne de $error est consommée par l'appelant (branche succès total
            // vs partiel vs échec total) : jamais sa valeur numérique brute, cf. admin/webhooks.php.
            'hadError' => $error > 0,
            'errorMessages' => $errorMessages,
        );
    }

    /**
     * Construit le message d'erreur (format `setEventMessages($text, $errors, $type)`, restitué
     * tel quel à l'appelant) pour un échec de `createWebhookWithDatabase()`, quelle que soit la
     * branche de `saveTopics()` qui l'a appelée : création d'un topic absent, ou recréation d'un
     * abonnement fantôme confirmé (Story 59-9, AC1). Factorisation PURE mise en forme — aucune
     * décision nouvelle : les deux appelants produisaient déjà EXACTEMENT le même message pour le
     * même échec (verrou non acquis vs erreur Shopify/SQL générique), dupliquer ce bloc une 3e
     * fois dans la classe aurait recréé le risque de divergence que cet epic corrige depuis le
     * début (deux implémentations d'une même décision qui finissent par diverger).
     *
     * @param  ShopifyWebhooks $shopifyWebhooks Instance ayant tenté createWebhookWithDatabase()
     * @param  string          $topic           Topic concerné (repris dans le message générique)
     * @return array{text: ?string, errors: ?array, type: string}
     * @since  2.4.8
     */
    private function buildCreateWebhookFailureMessage(ShopifyWebhooks $shopifyWebhooks, string $topic): array
    {
        global $langs;

        if (!empty($shopifyWebhooks->lastCreateLockTimedOut)) {
            return array(
                'text' => $langs->trans("WebhookCreateLockTimeout") . ' (' . $topic . ')',
                'errors' => null,
                'type' => 'errors',
            );
        }

        return array(
            'text' => null,
            'errors' => !empty($shopifyWebhooks->errors)
                ? $shopifyWebhooks->errors
                : array($langs->trans("Error") . ' (' . $topic . ')'),
            'type' => 'errors',
        );
    }

    /**
     * Enregistre les constantes générales de configuration webhooks — transaction dédiée : ces
     * constantes ne dépendent d'aucun appel Shopify, elles n'ont pas à être annulées parce qu'un
     * topic a échoué (unité transactionnelle n°5 du hotfix 2.4.7).
     *
     * Ne fait AUCUNE lecture de `$_POST`/`$_GET` : les valeurs sont déjà extraites par l'appelant
     * (page admin) via `GETPOST(...)` — seules la validation/normalisation (bornage retry/timeout) et
     * la persistance restent ici, à l'identique du bloc original.
     *
     * @param  int $webhookProcessLimit    GETPOST("webhook_process_limit", 'int')
     * @param  int $webhookKeepDays        GETPOST("webhook_keep_days", 'int')
     * @param  int $webhookImmediateProcess GETPOST("webhook_immediate_process", 'int')
     * @param  int $webhookMaxTries        GETPOSTINT("webhook_max_tries") — Story 36.3, borné [1,20]
     * @param  int $webhookStuckTimeout    GETPOSTINT("webhook_stuck_timeout") — borné [1,60]
     * @param  int $webhookUserId          GETPOST("webhook_user_id", 'int')
     * @param  int $entity                 Entité Dolibarr courante ($conf->entity)
     * @return array{hadError:bool, errorMessages:array}
     */
    public function saveGeneralSettings(
        int $webhookProcessLimit,
        int $webhookKeepDays,
        int $webhookImmediateProcess,
        int $webhookMaxTries,
        int $webhookStuckTimeout,
        int $webhookUserId,
        int $entity
    ): array {
        global $langs;

        $db = $this->db;

        // Round 3 (Blind Hunter, MEDIUM) : ces six écritures ne contrôlaient AUCUN retour.
        // `dolibarr_set_const()` fait un DELETE puis un INSERT dans une transaction imbriquée ;
        // son rollback interne ne fait que décrémenter le compteur d'imbrication, il ne défait pas
        // le DELETE déjà exécuté. Une constante pouvait donc être supprimée sans être réinsérée,
        // en silence, sous un « Configuration sauvegardée ». Avant ce hotfix, la transaction
        // globale unique rattrapait ce cas par effet de bord ; ce filet accidentel a disparu avec
        // les transactions par opération, il est donc remplacé par un contrôle explicite.
        $constWriteFailed = false;
        $db->begin();
        foreach (array(
            "SHOPIFY_WEBHOOK_PROCESS_LIMIT" => $webhookProcessLimit,
            "SHOPIFY_WEBHOOK_KEEP_DAYS" => $webhookKeepDays,
            "SHOPIFY_WEBHOOK_IMMEDIATE_PROCESS" => $webhookImmediateProcess,
        ) as $constName => $constValue) {
            if (dolibarr_set_const($db, $constName, $constValue, 'chaine', 0, '', $entity) <= 0) {
                $constWriteFailed = true;
                dol_syslog(
                    "webhooksettingssaver.class.php: échec dolibarr_set_const " . $constName . " : " . $db->lasterror(),
                    LOG_ERR
                );
            }
        }
        // Story 36.3 : politique de retry webhook (bornée)
        $maxTriesVal = $webhookMaxTries;
        if ($maxTriesVal < 1) { $maxTriesVal = 5; }
        if ($maxTriesVal > 20) { $maxTriesVal = 20; }
        if (dolibarr_set_const($db, "DOLI2SHOP_WEBHOOK_MAX_TRIES", $maxTriesVal, 'chaine', 0, '', $entity) <= 0) {
            $constWriteFailed = true;
        }
        $stuckVal = $webhookStuckTimeout;
        if ($stuckVal < 1) { $stuckVal = 5; }
        if ($stuckVal > 60) { $stuckVal = 60; }
        if (dolibarr_set_const($db, "DOLI2SHOP_WEBHOOK_STUCK_TIMEOUT_MIN", $stuckVal, 'chaine', 0, '', $entity) <= 0) {
            $constWriteFailed = true;
        }
        if (dolibarr_set_const($db, "DOLI2SHOP_WEBHOOK_USER_ID", $webhookUserId, 'chaine', 0, '', $entity) <= 0) {
            $constWriteFailed = true;
        }

        $errorMessages = array();
        if ($constWriteFailed) {
            $db->rollback();
            $errorMessages[] = array(
                'text' => $langs->trans("ErrorDatabaseQuery"),
                'errors' => null,
                'type' => 'errors',
            );
        } else {
            $db->commit();
        }

        return array(
            'hadError' => $constWriteFailed,
            'errorMessages' => $errorMessages,
        );
    }
}
