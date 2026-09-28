/**
 * @file        js/doli2shop.js
 * @brief       Core JavaScript for Doli2Shop module — collapsible, alerts, cookies
 *
 * @package     Doli2Shop
 * @subpackage  JS
 * @category    core
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <shopifyintegration@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.2.0
 * @since       2.2.0
 * @link        https://doli2shop.ptitetete.org
 */

/**
 * D2S — Doli2Shop global namespace.
 * Progressive enhancement: everything works without JS.
 */
var D2S = D2S || {};

(function (D2S) {
    'use strict';

    /* -------------------------------------------------------
     * D2S.Cookie — simple cookie helpers
     * ------------------------------------------------------- */
    D2S.Cookie = {
        /**
         * Read a cookie value by name.
         * @param {string} name
         * @returns {string|null}
         */
        get: function (name) {
            var match = document.cookie.match(
                new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)')
            );
            return match ? decodeURIComponent(match[1]) : null;
        },

        /**
         * Set a cookie.
         * @param {string} name
         * @param {string} value
         * @param {number} [days=30]
         */
        set: function (name, value, days) {
            var d = new Date();
            d.setTime(d.getTime() + ((days || 30) * 86400000));
            document.cookie = name + '=' + encodeURIComponent(value) +
                ';expires=' + d.toUTCString() +
                ';path=/;SameSite=Lax';
        },

        /**
         * Remove a cookie.
         * @param {string} name
         */
        remove: function (name) {
            D2S.Cookie.set(name, '', -1);
        }
    };

    /* -------------------------------------------------------
     * D2S.Collapsible — sync aria-expanded on <details>
     * ------------------------------------------------------- */
    D2S.Collapsible = {
        /**
         * Initialise collapsible enhancements.
         * Adds aria-expanded to every .d2s-collapsible <details>.
         */
        init: function () {
            var items = document.querySelectorAll('.d2s-collapsible');
            if (!items || !items.length) {
                return;
            }
            for (var i = 0; i < items.length; i++) {
                D2S.Collapsible._bind(items[i]);
            }
        },

        /** @private */
        _bind: function (details) {
            var summary = details.querySelector('.d2s-collapsible-header');
            if (!summary) {
                return;
            }
            // Set initial aria state
            summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');

            details.addEventListener('toggle', function () {
                summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
            });
        }
    };

    /* -------------------------------------------------------
     * D2S.Alert — dismissible alerts with cookie memory
     * ------------------------------------------------------- */
    D2S.Alert = {
        /** Cookie prefix for dismissed alerts */
        _prefix: 'd2s_alert_dismissed_',

        /**
         * Initialise alert dismiss behaviour.
         * Hides already-dismissed alerts and binds close buttons.
         */
        init: function () {
            var alerts = document.querySelectorAll('.d2s-alert-banner[data-alert-id]');
            if (!alerts || !alerts.length) {
                return;
            }
            for (var i = 0; i < alerts.length; i++) {
                D2S.Alert._setup(alerts[i]);
            }
        },

        /** @private */
        _setup: function (banner) {
            var alertId = banner.getAttribute('data-alert-id');
            if (!alertId) {
                return;
            }

            // Already dismissed?
            if (D2S.Cookie.get(D2S.Alert._prefix + alertId) === '1') {
                banner.style.display = 'none';
                return;
            }

            var btn = banner.querySelector('.d2s-alert-close');
            if (!btn) {
                return;
            }

            btn.addEventListener('click', function () {
                D2S.Cookie.set(D2S.Alert._prefix + alertId, '1', 30);
                banner.style.display = 'none';
            });
        }
    };

    /* -------------------------------------------------------
     * D2S.init — bootstrap on DOMContentLoaded
     * ------------------------------------------------------- */

    /**
     * Initialise all D2S enhancements.
     */
    D2S.init = function () {
        D2S.Collapsible.init();
        D2S.Alert.init();
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', D2S.init);
    } else {
        D2S.init();
    }

})(D2S);
