<?php
/* Copyright (C) 2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
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

/**
 * @file       class/modulelogfile.class.php
 * @brief      Journal du module dans son propre fichier, par le mécanisme NATIF de Dolibarr.
 * @package    ShopifyIntegration
 * @subpackage Core
 * @category   Logging
 * @author     P'tite Tête
 * @copyright  2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license    GPL v3+
 * @version     2.6.0
 * @since      2.5.5
 * @link       https://doli2shop.ptitetete.org
 */

/**
 * Journal du module, écrit dans SON fichier — sans une ligne de code d'écriture.
 *
 * ## Tout est natif, et c'est le point
 *
 * `dol_syslog()` accepte un 4ᵉ paramètre, `$suffixinfilename`
 * (`core/lib/functions.lib.php:2707`), que le gestionnaire de fichiers insère avant l'extension
 * (`core/modules/syslog/mod_syslog_file.php:154`). Un appel avec le suffixe `_doli2shop` écrit
 * donc dans **`DOL_DATA_ROOT/dolibarr_doli2shop.log`**, à la racine des documents, à côté de
 * `dolibarr.log`.
 *
 * Trois mécanismes du cœur s'appliquent alors **automatiquement**, sans que nous écrivions quoi
 * que ce soit :
 *
 * | Besoin | Natif | Où |
 * |---|---|---|
 * | Écriture, format, verrouillage | gestionnaire `mod_syslog_file` | `getFilename()`, 23.0 `:154` / 24.0.1 `:157` |
 * | **Rotation** + compression, `SYSLOG_FILE_SAVES` archives (10 par défaut) | `Utils::compressSyslogs()` | 23.0 `:1003` / 24.0.1 `:1049`, motif `^(dolibarr_.+\|odt2pdf)\.log$` |
 * | **Purge** des anciens journaux | `Utils::purgeFiles('logfiles')` | `:148`, motif `.*\.log[\.0-9]*(\.gz)?$` |
 *
 * La rotation est déclenchée par le job natif du module Syslog
 * (`core/modules/modSyslog.class.php:97`).
 *
 * 🔴 **Le nom doit commencer par `dolibarr_`, et cela ne sert qu'à la ROTATION.** La purge, elle,
 * ramasse tout `*.log`. Perdre le préfixe est donc pire que « le fichier grossit » : il grossit
 * **sans jamais être coupé**, puis le job de purge l'efface **en entier** — on perd le bornage ET
 * les archives, sans la moindre erreur.
 *
 * 🔴 **Aucun écran natif ne liste ces fichiers.** `admin/syslog.php` ne traite que le journal
 * principal ; le motif ci-dessus vit dans `compressSyslogs()`, pas dans une page d'administration.
 * Le lien de téléchargement que le module pose sur son onglet Support est donc le **seul** chemin
 * par lequel un client peut récupérer ce fichier.
 * *(Les deux précisions viennent de la session ProductionInterne, 19/09, vérifiées sur 23.0 et
 * 24.0.1 : ma première rédaction attribuait au préfixe les trois mécanismes et supposait un écran
 * natif de téléchargement.)*
 *
 * ## Ce qu'on a retiré, et pourquoi
 *
 * Une première version écrivait le fichier elle-même, avec sa propre rotation par taille et un
 * répertoire par entité. Tout cela **existait déjà dans le cœur**, en mieux testé : réimplémenter
 * revenait à maintenir un doublon qui diverge, et à sortir du chemin où l'administrateur sait
 * déjà chercher ses journaux.
 *
 * ## Un seul fichier, l'entité dans la ligne
 *
 * Pas de fichier par entité. Un client multi-sociétés ne doit pas avoir à deviner lequel nous
 * envoyer, et couper la chronologie en deux rend le diagnostic plus difficile, pas plus simple.
 * L'entité est donc **un champ de la ligne**, préfixé au message.
 */
class ModuleLogFile
{
    /**
     * Suffixe inséré dans le nom du fichier par le gestionnaire natif.
     *
     * ⚠️ Le fichier résultant s'appelle `dolibarr_doli2shop.log`. Le préfixe `dolibarr_` n'est pas
     * cosmétique : c'est lui qui fait entrer le fichier dans le motif de rotation
     * (`^(dolibarr_.+|odt2pdf)\.log$`) et dans l'écran natif des journaux. Le changer, c'est
     * perdre la rotation sans aucun message d'erreur.
     */
    const FILENAME_SUFFIX = '_doli2shop';

    /**
     * Résultat mémorisé du test d'accessibilité en écriture, pour la durée de la requête.
     *
     * @var bool|null
     */
    private static $writable = null;

    /**
     * Le journal dédié est-il actif ?
     *
     * Activé par défaut : un journal qu'il faut penser à allumer AVANT la panne ne sert jamais,
     * puisqu'on ne sait qu'après coup qu'on en aurait eu besoin.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return getDolGlobalInt('DOLI2SHOP_LOG_FILE_ENABLED', 1) === 1;
    }

    /**
     * Niveau minimal écrit dans le fichier dédié.
     *
     * Indépendant de `SYSLOG_LEVEL` : c'est tout l'intérêt d'avoir notre fichier. On peut y monter
     * en DEBUG le temps d'un diagnostic sans passer tout Dolibarr en verbeux — ce qui gonflerait
     * encore le `dolibarr.log` partagé, à 323 Mo chez un client de septembre 2026.
     *
     * @return int Constante LOG_*
     */
    public static function threshold()
    {
        $configured = getDolGlobalInt('DOLI2SHOP_LOG_FILE_LEVEL', LOG_INFO);

        // Une valeur hors échelle syslog rendrait le journal soit muet, soit incontrôlable.
        if ($configured < LOG_ERR || $configured > LOG_DEBUG) {
            return LOG_INFO;
        }

        return $configured;
    }

    /**
     * Chemin du fichier de journal du module.
     *
     * Reconstruit comme le fait le gestionnaire natif : `SYSLOG_FILE` si défini, sinon
     * `DOL_DATA_ROOT/dolibarr.log`, puis insertion du suffixe avant `.log`.
     *
     * @return string|null
     */
    public static function path()
    {
        global $conf;

        if (!defined('DOL_DATA_ROOT')) {
            return null;
        }

        $base = getDolGlobalString('SYSLOG_FILE');
        $base = ($base !== '')
            ? str_replace('DOL_DATA_ROOT', DOL_DATA_ROOT, $base)
            : DOL_DATA_ROOT . '/dolibarr.log';

        return preg_replace('/\.log$/i', self::FILENAME_SUFFIX . '.log', $base);
    }

    /**
     * Écrit une ligne dans le fichier dédié, via le mécanisme natif.
     *
     * @param  string $message Message déjà préfixé par l'appelant
     * @param  int    $level   Niveau syslog
     * @return bool   true si la ligne a été transmise au gestionnaire natif
     */
    public static function write($message, $level = LOG_INFO)
    {
        global $conf;

        try {
            if (!self::isEnabled() || (int) $level > self::threshold()) {
                return false;
            }

            // L'entité voyage DANS la ligne, puisqu'il n'y a qu'un fichier pour toutes.
            $entity = (!empty($conf->entity)) ? (int) $conf->entity : 1;
            $line = '[entity ' . $entity . '] ' . (string) $message;

            // ⚠️ Le fichier doit être inscriptible AVANT l'appel — voir writableProbe().
            if (!self::writableProbe()) {
                return false;
            }

            // 4ᵉ paramètre = suffixe de nom de fichier ; 5ᵉ = restriction au gestionnaire fichier.
            //
            // 🔴 La valeur attendue est `'file'`, PAS `'mod_syslog_file'`. Le cœur compare le
            // CODE du gestionnaire, pas son nom de classe :
            //   functions.lib.php (18 `:1878`, 23 `:2854`, 24 `:2887`) :
            //     if ($restricttologhandler && $loghandlerinstance->code != $restricttologhandler)
            //   mod_syslog_file.php : public $code = 'file';
            // Passer le nom de classe fait donc `continue` sur TOUS les gestionnaires : la ligne
            // n'atteint jamais export(), le fichier n'est jamais créé, et rien ne le signale.
            // (Défaut livré en 2.5.5, trouvé par la session Hierdocs en implémentant la règle
            // commune ; corrigé ici.)
            dol_syslog($line, (int) $level, 0, self::FILENAME_SUFFIX, 'file');

            return true;
        } catch (Exception $e) {
            // Un journal ne doit JAMAIS faire échouer ce qu'il observe.
            return false;
        }
    }

    /**
     * Le fichier de journal est-il inscriptible ?
     *
     * 🔴 Indispensable sur Dolibarr 18 et 23, pas sur 24. Quand `fopen()` échoue,
     * `mod_syslog_file::export()` de la 23 (`:193-205`) appelle `fopen()` **sans `@`** puis
     * imprime un bloc d'erreur — soit ~400 octets crachés dans la sortie. La 24 a ajouté
     * `|| !empty($suffixinfilename)` aux deux gardes (`:195`, `:206`), les 18 et 23 non.
     *
     * Chez nous, ces octets n'atterriraient pas dans un PDF mais dans la **réponse JSON** des
     * écrans AJAX de synchronisation : le JSON devient illisible et l'écran casse. Un journal qui
     * fait tomber la fonctionnalité qu'il observe est précisément ce qu'on veut éviter.
     *
     * Le test est mémorisé pour la durée de la requête : un `is_writable()` par ligne de journal
     * coûterait un appel disque à chaque trace.
     *
     * *(Divergence 18-23 / 24 mesurée par la session Hierdocs, recoupée ici sur les trois
     * installations.)*
     *
     * @return bool
     */
    private static function writableProbe()
    {
        if (self::$writable !== null) {
            return self::$writable;
        }

        self::$writable = false;

        $path = self::path();
        if ($path === null) {
            return false;
        }

        // Fichier existant : c'est lui qui doit être inscriptible. Sinon, c'est le répertoire.
        self::$writable = is_file($path) ? is_writable($path) : is_writable(dirname($path));

        return self::$writable;
    }

    /**
     * Taille du fichier de journal du module, archives comprises.
     *
     * Les archives sont produites par la rotation native sous la forme `<fichier>.<n>.gz`.
     *
     * @return int Octets
     */
    public static function totalSize()
    {
        $path = self::path();
        if ($path === null) {
            return 0;
        }

        $total = is_file($path) ? (int) @filesize($path) : 0;
        foreach ((array) @glob($path . '.*') as $archive) {
            if (is_file($archive)) {
                $total += (int) @filesize($archive);
            }
        }

        return $total;
    }
}
