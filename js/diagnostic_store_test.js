/**
 * @file        js/diagnostic_store_test.js
 * @brief       Remplissage progressif (AJAX) des tests API Shopify (connexion + scopes +
 *              canaux) PAR BOUTIQUE sur admin/health.php et admin/diagnostic.php (Story 50-3).
 *
 * No-op automatique si :
 *  - la section #shopify-connection n'existe pas sur la page,
 *  - ou si elle ne contient aucun squelette data-doli2shop-store-test="1" (mode synchrone :
 *    export ?format=json ou fallback ?sync_tests=1, JS désactivé — cf. <noscript> serveur).
 *
 * Chaque appel AJAX cible ajax/diagnostic_store_test.php avec le store_id + le token CSRF
 * déjà rendus côté serveur dans les data-attributes de la section. Aucune donnée sensible
 * (token, secret) n'est jamais présente dans la réponse JSON — uniquement des libellés déjà
 * traduits et des données Shopify non sensibles (nom boutique, devise, scopes, canaux).
 *
 * @package     ShopifyIntegration
 * @subpackage  Js
 * @category    admin
 * @author      P'tite Tête
 * @copyright   2024-2026 P'tite Tête <doli2shop@ptitetete.com>
 * @license     http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @version     2.4.8
 * @since       2.4.1
 * @link        https://doli2shop.ptitetete.org
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        runDoli2ShopStoreTests();
    });

    function runDoli2ShopStoreTests() {
        var section = document.getElementById('shopify-connection');
        if (!section) {
            return; // Page sans bloc de test connexion (ex. module désactivé)
        }

        var ajaxUrl = section.getAttribute('data-doli2shop-ajax-url');
        var token = section.getAttribute('data-doli2shop-token');
        var tokenMissingLabel = section.getAttribute('data-doli2shop-token-missing-label')
            || 'Invalid or expired security token. Please reload the page and try again.';
        var skeletons = section.querySelectorAll('[data-doli2shop-store-test="1"]');

        if (!ajaxUrl || skeletons.length === 0) {
            return; // Mode synchrone, ou rien à charger : silence légitime, rien n'est affiché.
        }

        // Epic 59 (HIGH) : sur la toute première requête d'une session PHP fraîche,
        // $_SESSION['token'] n'est pas encore peuplé côté core (main.inc.php:333-349) —
        // currentToken() rend alors '' côté serveur. Envoyer quand même l'appel échouerait de
        // toute façon (verifToken() rejette systématiquement un jeton vide) : on ne le tente
        // pas, et surtout on ne retourne plus EN SILENCE comme avant (les squelettes
        // "Testing..." restaient alors figés indéfiniment, sans le moindre indice pour
        // l'utilisateur). On affiche un message actionnable et on reclasse la section comme les
        // autres échecs de connexion.
        if (!token) {
            skeletons.forEach(function (skeleton) {
                renderNetworkError(skeleton, tokenMissingLabel);
            });
            section.setAttribute('data-doli2shop-deferred', 'false');
            section.setAttribute('data-connection-success', 'false');
            section.setAttribute('data-has-connection-error', 'true');
            if (typeof window.autoCollapseSuccessSections === 'function') {
                window.autoCollapseSuccessSections();
            }
            return;
        }

        // Compteurs agrégés : on part des valeurs déjà rendues côté serveur (boutiques
        // "pending" déjà connues sans appel API) puis on ajoute la contribution de chaque
        // boutique testée ici en AJAX.
        var aggregate = {
            requiredMissing: parseInt(section.getAttribute('data-required-scopes-missing'), 10) || 0,
            optionalMissing: parseInt(section.getAttribute('data-optional-scopes-missing'), 10) || 0,
            totalScopes: parseInt(section.getAttribute('data-total-scopes'), 10) || 0,
            hasConnectionError: section.getAttribute('data-has-connection-error') === 'true',
        };

        var pendingCalls = skeletons.length;

        function finalizeIfDone() {
            pendingCalls--;
            if (pendingCalls > 0) {
                return;
            }
            // Toutes les boutiques ont répondu (succès ou échec) : on publie les compteurs
            // finaux sur la section et on relance la classification (autoCollapseSuccessSections
            // est définie dans la page hôte — health.php et diagnostic.php en ont chacune leur
            // propre copie, comportement identique).
            section.setAttribute('data-doli2shop-deferred', 'false');
            section.setAttribute('data-connection-success', aggregate.hasConnectionError ? 'false' : 'true');
            section.setAttribute('data-has-connection-error', aggregate.hasConnectionError ? 'true' : 'false');
            section.setAttribute('data-required-scopes-missing', String(aggregate.requiredMissing));
            section.setAttribute('data-optional-scopes-missing', String(aggregate.optionalMissing));
            section.setAttribute('data-total-scopes', String(aggregate.totalScopes));
            if (typeof window.autoCollapseSuccessSections === 'function') {
                window.autoCollapseSuccessSections();
            }
        }

        skeletons.forEach(function (skeleton) {
            var storeId = parseInt(skeleton.getAttribute('data-store-id'), 10) || 0;
            var errorLabel = skeleton.getAttribute('data-error-label') || 'Error';
            var url = buildUrl(ajaxUrl, token, storeId);

            fetchJson(url)
                .then(function (data) {
                    if (!data || data.success !== true) {
                        renderNetworkError(skeleton, errorLabel);
                        aggregate.hasConnectionError = true;
                        return;
                    }
                    renderStoreResult(skeleton, data);
                    if (!data.connection) {
                        aggregate.hasConnectionError = true;
                    }
                    aggregate.requiredMissing += parseInt(data.required_missing, 10) || 0;
                    aggregate.optionalMissing += parseInt(data.optional_missing, 10) || 0;
                    aggregate.totalScopes += parseInt(data.total_scopes, 10) || 0;
                })
                .catch(function () {
                    renderNetworkError(skeleton, errorLabel);
                    aggregate.hasConnectionError = true;
                })
                .then(finalizeIfDone);
        });
    }

    function buildUrl(ajaxUrl, token, storeId) {
        var sep = ajaxUrl.indexOf('?') === -1 ? '?' : '&';
        return ajaxUrl + sep + 'store_id=' + encodeURIComponent(storeId) + '&token=' + encodeURIComponent(token);
    }

    function fetchJson(url) {
        if (typeof window.fetch === 'function') {
            return window.fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                });
        }
        // Repli XMLHttpRequest (anciens navigateurs sans fetch())
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch (e) {
                        reject(e);
                    }
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            };
            xhr.onerror = function () {
                reject(new Error('network error'));
            };
            xhr.send();
        });
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function renderNetworkError(skeleton, errorLabel) {
        clearNode(skeleton);
        skeleton.removeAttribute('data-doli2shop-store-test');
        var box = el('div', 'error');
        box.appendChild(el('strong', null, errorLabel));
        skeleton.appendChild(box);
    }

    function renderStoreResult(skeleton, data) {
        clearNode(skeleton);
        skeleton.removeAttribute('data-doli2shop-store-test');
        var labels = data.labels || {};

        if (data.pending) {
            var pendingBox = el('div', 'warning', labels.pendingMessage || '');
            skeleton.appendChild(pendingBox);
            return;
        }

        if (data.connection) {
            var okBox = el('div', 'ok');
            okBox.appendChild(el('strong', null, labels.connectionSuccess || ''));
            okBox.appendChild(document.createElement('br'));
            if (data.shop_info && (data.shop_info.name || data.shop_info.currency_code)) {
                okBox.appendChild(el('strong', null, (labels.shopInfo || '') + ':'));
                var shopInfoText = document.createTextNode(
                    ' ' + (data.shop_info.name || '') +
                    (data.shop_info.currency_code ? ' (' + data.shop_info.currency_code + ')' : '')
                );
                okBox.appendChild(shopInfoText);
                okBox.appendChild(document.createElement('br'));
            }
            skeleton.appendChild(okBox);

            if (data.scopes_analysis && data.scopes_analysis.length > 0) {
                skeleton.appendChild(renderScopesTable(data.scopes_analysis, labels));
            }

            if (data.channels && data.channels.status && data.channels.status !== 'skipped') {
                var channelsClass = data.channels.status === 'success' ? 'success'
                    : (data.channels.status === 'warning' ? 'warning' : 'error');
                var channelsBox = el('div', channelsClass, data.channels.message || '');
                channelsBox.style.marginTop = '8px';
                skeleton.appendChild(channelsBox);
            }
            return;
        }

        var errorBox = el('div', 'error');
        errorBox.appendChild(el('strong', null, labels.connectionFailed || ''));
        if (data.errors && data.errors.length > 0) {
            errorBox.appendChild(document.createElement('br'));
            data.errors.forEach(function (message, index) {
                if (index > 0) {
                    errorBox.appendChild(document.createElement('br'));
                }
                errorBox.appendChild(document.createTextNode(message));
            });
        }
        skeleton.appendChild(errorBox);
    }

    function renderScopesTable(scopesAnalysis, labels) {
        var fragment = document.createDocumentFragment();
        var heading = document.createElement('br');
        fragment.appendChild(heading);
        fragment.appendChild(el('strong', null, (labels.scopeStatus || '') + ':'));

        var table = document.createElement('table');
        table.className = 'noborder centpercent';
        table.style.marginTop = '10px';

        var headRow = document.createElement('tr');
        headRow.className = 'liste_titre';
        headRow.appendChild(el('td', null, labels.permission || ''));
        var headCenter1 = el('td', 'center', labels.requiredHeader || '');
        var headCenter2 = el('td', 'center', labels.statusHeader || '');
        headRow.appendChild(headCenter1);
        headRow.appendChild(headCenter2);
        table.appendChild(headRow);

        var categories = {};
        var categoryOrder = [];
        scopesAnalysis.forEach(function (scope) {
            var key = scope.category || '';
            if (!categories[key]) {
                categories[key] = { label: scope.category_label || key, scopes: [] };
                categoryOrder.push(key);
            }
            categories[key].scopes.push(scope);
        });

        categoryOrder.forEach(function (key) {
            var catRow = document.createElement('tr');
            catRow.className = 'liste_titre';
            var catCell = document.createElement('td');
            catCell.colSpan = 3;
            catCell.style.fontWeight = 'bold';
            catCell.style.background = '#f0f0f0';
            catCell.textContent = categories[key].label;
            catRow.appendChild(catCell);
            table.appendChild(catRow);

            categories[key].scopes.forEach(function (scope) {
                var row = document.createElement('tr');
                row.appendChild(el('td', null, scope.description || ''));

                var statusCell = document.createElement('td');
                statusCell.className = 'center';
                statusCell.appendChild(el('span', scope.status_class || '', scope.status_label || ''));
                row.appendChild(statusCell);

                var requiredCell = document.createElement('td');
                requiredCell.className = 'center';
                requiredCell.appendChild(el('span', scope.required_class || '', scope.required_label || ''));
                row.appendChild(requiredCell);

                table.appendChild(row);
            });
        });

        fragment.appendChild(table);
        return fragment;
    }

    function clearNode(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }
})();
