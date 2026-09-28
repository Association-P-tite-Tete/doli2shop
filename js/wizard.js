/**
 * @file        js/wizard.js
 * @brief       Wizard navigation, validation and AJAX helpers for Doli2Shop
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
var D2S = D2S || {};
(function (D2S) {
    'use strict';

    D2S.Wizard = {
        _st: null, _cfg: null, _cbs: [],
        validators: {},

        /** Init wizard. config: { container, steps[], startStep, prevBtn, nextBtn } */
        init: function (c) {
            if (!c || !c.container || !c.steps) return;
            var el = typeof c.container === 'string' ? document.querySelector(c.container) : c.container;
            if (!el) return;
            var r = function (s) { return !s ? null : (typeof s === 'string' ? document.querySelector(s) : s); };
            D2S.Wizard._cfg = { container: el, steps: c.steps, prevBtn: r(c.prevBtn), nextBtn: r(c.nextBtn) };
            D2S.Wizard._st = { currentStep: c.startStep || 0, totalSteps: c.steps.length,
                isFirst: true, isLast: c.steps.length <= 1 };
            D2S.Wizard._bind();
            D2S.Wizard._updateDOM();
        },

        /** Navigate to step (0-indexed). Validates before forward move. */
        goTo: function (step) {
            var s = D2S.Wizard._st;
            if (!s || step < 0 || step >= s.totalSteps) return;
            if (step > s.currentStep) {
                D2S.Wizard.validate(s.currentStep).then(function (ok) { if (ok) D2S.Wizard._go(step); });
            } else { D2S.Wizard._go(step); }
        },

        next: function () { if (D2S.Wizard._st) D2S.Wizard.goTo(D2S.Wizard._st.currentStep + 1); },
        prev: function () { if (D2S.Wizard._st) D2S.Wizard.goTo(D2S.Wizard._st.currentStep - 1); },

        /** Validate step. Set D2S.Wizard.validators[n] = fn returning bool|Promise. */
        validate: function (step) {
            var v = D2S.Wizard.validators[step];
            if (typeof v === 'function') {
                var r = v();
                return (r && typeof r.then === 'function') ? r : D2S.Wizard._p(!!r);
            }
            return D2S.Wizard._p(true);
        },

        /** Register step-change callback. Receives { from, to, state }. */
        onStepChange: function (cb) { if (typeof cb === 'function') D2S.Wizard._cbs.push(cb); },

        /** Return { currentStep, totalSteps, isFirst, isLast }. */
        getState: function () {
            var s = D2S.Wizard._st;
            return s ? { currentStep: s.currentStep, totalSteps: s.totalSteps, isFirst: s.isFirst, isLast: s.isLast } : null;
        },

        /** AJAX POST to test Shopify connection. Returns Promise<Object>. */
        testConnection: function (url, token) {
            if (typeof jQuery === 'undefined') return D2S.Wizard._p({ success: false, error: 'jQuery unavailable' });
            return jQuery.ajax({ url: url, method: 'POST', data: { action: 'test_connection', token: token },
                dataType: 'json', timeout: 30000 });
        },

        /** Poll progress endpoint. Returns { start(), stop(), onProgress(cb) }. */
        trackProgress: function (url, token, interval) {
            var t = null, cb = null, ms = interval || 2000;
            function poll() {
                if (typeof jQuery === 'undefined') return;
                jQuery.ajax({ url: url, method: 'POST', data: { action: 'get_progress', token: token },
                    dataType: 'json', timeout: 10000, success: function (d) { if (typeof cb === 'function') cb(d); } });
            }
            return {
                start: function () { if (!t) { poll(); t = setInterval(poll, ms); } },
                stop: function () { if (t) { clearInterval(t); t = null; } },
                onProgress: function (fn) { cb = fn; }
            };
        },

        /* --- Private --- */
        _go: function (step) {
            var from = D2S.Wizard._st.currentStep;
            D2S.Wizard._st.currentStep = step;
            D2S.Wizard._st.isFirst = (step === 0);
            D2S.Wizard._st.isLast = (step === D2S.Wizard._st.totalSteps - 1);
            D2S.Wizard._updateDOM();
            var state = D2S.Wizard.getState();
            for (var i = 0; i < D2S.Wizard._cbs.length; i++) D2S.Wizard._cbs[i]({ from: from, to: step, state: state });
        },

        _updateDOM: function () {
            var cfg = D2S.Wizard._cfg, st = D2S.Wizard._st;
            if (!cfg || !st) return;
            var items = cfg.container.querySelectorAll('.d2s-step');
            for (var i = 0; i < items.length; i++) {
                var cls = items[i].className.replace(/d2s-step--(done|active|pending)/g, '').replace(/\s+/g, ' ').trim();
                items[i].className = cls + (i < st.currentStep ? ' d2s-step--done' : i === st.currentStep ? ' d2s-step--active' : ' d2s-step--pending');
            }
            for (var j = 0; j < cfg.steps.length; j++) {
                var p = document.querySelector(cfg.steps[j]);
                if (p) { p.style.display = j === st.currentStep ? '' : 'none'; p.setAttribute('aria-hidden', j === st.currentStep ? 'false' : 'true'); }
            }
            if (cfg.prevBtn) { cfg.prevBtn.style.display = st.isFirst ? 'none' : ''; cfg.prevBtn.disabled = st.isFirst; }
            if (cfg.nextBtn) { cfg.nextBtn.disabled = st.isLast; }
        },

        _bind: function () {
            var cfg = D2S.Wizard._cfg;
            if (cfg.prevBtn) cfg.prevBtn.addEventListener('click', function (e) { e.preventDefault(); D2S.Wizard.prev(); });
            if (cfg.nextBtn) cfg.nextBtn.addEventListener('click', function (e) { e.preventDefault(); D2S.Wizard.next(); });
            var circles = cfg.container.querySelectorAll('.d2s-step-circle');
            for (var i = 0; i < circles.length; i++) {
                (function (idx) {
                    circles[idx].setAttribute('tabindex', '0');
                    circles[idx].setAttribute('role', 'tab');
                    circles[idx].addEventListener('click', function () { D2S.Wizard.goTo(idx); });
                    circles[idx].addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); D2S.Wizard.goTo(idx); }
                    });
                })(i);
            }
        },

        /** Minimal Promise wrapper (IE11 thenable fallback). */
        _p: function (v) {
            if (typeof Promise !== 'undefined') return Promise.resolve(v);
            return { then: function (cb) { cb(v); return D2S.Wizard._p(v); } };
        }
    };
})(D2S);
