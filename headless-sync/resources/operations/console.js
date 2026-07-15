/**
 * HSP Operations Console — minimal vanilla JS (OPSC-S3; DECISION V (a)).
 *
 * Polling only. No framework, no bundler, no build step (DECISION V (a)). Two behaviours:
 *   1. Dashboard poll — periodically GET fresh provider snapshots from the nonce-protected
 *      admin-ajax endpoint and update widget values in place.
 *   2. API Playground — POST the selected endpoint to the nonce-protected execute endpoint and
 *      render the JSON response in the Response Viewer.
 *
 * Every request carries the console nonce (verified server-side via check_ajax_referer) and is
 * capability-checked server-side. This script performs NO state change — it reads snapshots and
 * executes read-only GETs against the delivery API. The endpoint is selected by index; the
 * server re-resolves it from registered metadata (read-only, GET only).
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

        function poll() {
            fetch(cfg.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: form(dash, { action: cfg.pollAction, nonce: cfg.nonce }).toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (payload && payload.success && payload.data && payload.data.snapshots) {
                        dash.setAttribute('data-last-poll', new Date().toISOString());
                    }
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
                    endpoint: endpoint ? endpoint.value : '0',
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

    ready(function () {
        initDashboard();
        initPlayground();
    });
})();
