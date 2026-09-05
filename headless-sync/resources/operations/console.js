/**
 * HSP Operations Console — minimal vanilla JS (OPSC-S3; DECISION V (a)).
 *
 * No framework, no bundler, no build step (DECISION V (a)). Three behaviours:
 *   1. Dashboard poll — periodically fetch the re-rendered widget markup from the nonce-protected
 *      admin-ajax endpoint and swap it in, so the displayed figures actually advance.
 *   2. API Playground — POST the selected endpoint to the nonce-protected execute endpoint and
 *      render the JSON response in the Response Viewer.
 *   3. Operational actions — POST Replay/Reconcile to the nonce-protected action endpoint
 *      (DECISION V (d)) and render the returned ActionResult.
 *
 * Every request carries the console nonce (verified server-side via check_ajax_referer) and is
 * capability-checked server-side. Behaviours 1 and 2 change no state; behaviour 3 invokes the two
 * ratified actions ONLY — each a thin delegator to ReplayService / ReconciliationService — with the
 * action key, parameters, capability and confirmation all validated server-side. The Playground
 * endpoint is selected by its stable route key (the <option> value); the server re-resolves it from
 * registered metadata by identity (read-only, GET only), so a registration-order shift can't
 * retarget a route.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function form(root, data) {
        var params = new URLSearchParams();
        Object.keys(data).forEach(function (k) { params.append(k, data[k]); });
        return params;
    }

    // --- Dashboard polling ---------------------------------------------------
    function initDashboard() {
        var dash = document.querySelector('.hsp-ops-dashboard');
        if (!dash) { return; }

        var cfg = window.HSP_OPS || null;
        if (!cfg || !cfg.ajaxUrl || !cfg.nonce || !cfg.pollAction) { return; }

        var intervalMs = (cfg.pollIntervalSeconds || 15) * 1000;

        var widgets = dash.querySelector('.hsp-ops-widgets');

        function poll() {
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: form(dash, { action: cfg.pollAction, nonce: cfg.nonce }).toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (!payload || !payload.success || !payload.data) { return; }

                    // Swap in the server-rendered widgets. This is the whole point of the poll:
                    // it used to fetch a full provider snapshot set and only stamp the timestamp
                    // below, so every open tab ran the PostgreSQL reads every 15s and the numbers
                    // on screen never changed. The markup is produced by DashboardView (the same
                    // renderer as the initial page load) and is already escaped server-side.
                    if (widgets && typeof payload.data.widgets === 'string') {
                        widgets.innerHTML = payload.data.widgets;
                    }

                    dash.setAttribute('data-last-poll', new Date().toISOString());
                })
                .catch(function () { /* transient; next tick retries */ });
        }

        window.setInterval(poll, intervalMs);
    }

    // --- API Playground execution -------------------------------------------
    function initPlayground() {
        var pg = document.querySelector('.hsp-ops-playground');
        if (!pg) { return; }

        var ajaxUrl = pg.getAttribute('data-ajax-url');
        var nonce   = pg.getAttribute('data-nonce');
        var action  = pg.getAttribute('data-action');
        if (!ajaxUrl || !nonce || !action) { return; }

        var button = pg.querySelector('.hsp-ops-execute');
        var meta    = pg.querySelector('.hsp-ops-response-meta');
        var body    = pg.querySelector('.hsp-ops-response-body');
        if (!button) { return; }

        button.addEventListener('click', function () {
            var endpoint = pg.querySelector('.hsp-ops-endpoint');
            var slug     = pg.querySelector('.hsp-ops-slug');
            var query    = pg.querySelector('.hsp-ops-query');

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: form(pg, {
                    action: action,
                    nonce: nonce,
                    endpoint: endpoint ? endpoint.value : '',
                    slug: slug ? slug.value : '',
                    query: query ? query.value : ''
                }).toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (payload && payload.success && payload.data) {
                        if (meta) { meta.textContent = 'HTTP ' + payload.data.status + '  ' + payload.data.path; }
                        if (body) { body.textContent = JSON.stringify(payload.data.body, null, 2); }
                    } else {
                        var msg = payload && payload.data && payload.data.message ? payload.data.message : 'Request failed.';
                        if (meta) { meta.textContent = 'Error'; }
                        if (body) { body.textContent = msg; }
                    }
                })
                .catch(function () {
                    if (body) { body.textContent = 'Request failed.'; }
                });
        });
    }

    // --- Operational actions (Replay / Reconcile) ----------------------------
    // The nonce-protected hsp_ops_action endpoint existed and worked, but nothing here called it,
    // so the two ratified actions had no way in from the console. Every guard that matters (POST
    // only, nonce, capability, confirmation, action-key whitelist, parameter whitelist) is enforced
    // server-side in ConsoleActionController — the confirm() below is a courtesy, not the control.
    function initActions() {
        var root = document.querySelector('.hsp-ops-actions');
        if (!root) { return; }

        var ajaxUrl = root.getAttribute('data-ajax-url');
        var nonce   = root.getAttribute('data-nonce');
        var action  = root.getAttribute('data-action');
        if (!ajaxUrl || !nonce || !action) { return; }

        var summary = root.querySelector('.hsp-ops-action-summary');
        var detail  = root.querySelector('.hsp-ops-action-detail');

        function report(text, json) {
            if (summary) { summary.textContent = text; }
            if (detail) { detail.textContent = json ? JSON.stringify(json, null, 2) : ''; }
        }

        Array.prototype.forEach.call(root.querySelectorAll('.hsp-ops-action'), function (formEl) {
            formEl.addEventListener('submit', function (event) {
                event.preventDefault();

                var opAction = formEl.getAttribute('data-op-action');

                if (formEl.getAttribute('data-confirm') === '1' &&
                    !window.confirm('Run "' + opAction + '" now?')) {
                    return;
                }

                var params = new URLSearchParams();
                params.append('action', action);
                params.append('nonce', nonce);
                params.append('op_action', opAction);
                params.append('confirm', '1');

                // Only fields the operator actually filled in are sent; an empty text field must
                // not be posted as an empty parameter, or a range replay would receive from=''.
                Array.prototype.forEach.call(formEl.elements, function (el) {
                    if (!el.name) { return; }
                    if (el.type === 'checkbox') {
                        if (el.checked) { params.append(el.name, '1'); }
                        return;
                    }
                    if (el.value !== '') { params.append(el.name, el.value); }
                });

                var button = formEl.querySelector('.hsp-ops-action-run');
                if (button) { button.disabled = true; }
                report('Running ' + opAction + '…', null);

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: params.toString()
                })
                    .then(function (r) { return r.json(); })
                    .then(function (payload) {
                        if (payload && payload.success && payload.data) {
                            report(payload.data.summary || 'Done.', payload.data.detail || null);
                        } else {
                            var msg = payload && payload.data && payload.data.message
                                ? payload.data.message
                                : 'Action failed.';
                            report(msg, null);
                        }
                    })
                    .catch(function () { report('Action failed.', null); })
                    .then(function () { if (button) { button.disabled = false; } });
            });
        });
    }

    ready(function () {
        initDashboard();
        initPlayground();
        initActions();
    });
})();
