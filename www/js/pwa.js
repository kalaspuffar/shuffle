/**
 * Shuffle — PWA glue (PWA-01..08, REQUIREMENTS v2.7 §7.18).
 *
 * Runs ONLY on authenticated pages (footer.php gate). Two guarded
 * enhancements, both no-ops when unsupported:
 *
 *   1) Service-worker registration — PWA-07 progressive enhancement.
 *      The SW itself (www/sw.js) implements the offline + cache contract;
 *      this file only opts in. On a browser without `navigator.serviceWorker`
 *      (older engines, or a page loaded without a secure context) the
 *      `in navigator` guard short-circuits and nothing is registered — no
 *      console error, no feature loss (PWA-07).
 *
 *   2) Online-recovery reload — when the user returns to connectivity while
 *      on a page served from the offline fallback, a single `location.reload()`
 *      lets the live server take over. Guarded by a module flag so repeated
 *      `online` events (some browsers fire more than one) do not chain
 *      reloads.
 *
 * No inline handlers, no inline <script> bodies — CSP script-src 'self'
 * already in force (see header.php).
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return; // PWA-07: no secure context / no API — silently skip.
    }

    // Register at page-load time. A registration failure (quota, offline on
    // first visit before the offline bundle ever cached) must never surface
    // as a broken app — log it, don't fail.
    navigator.serviceWorker.register('/sw.js').then(
        function (reg) {
            if (reg && reg.active) {
                window.__pwaSwActive = true;
            }
        },
        function (err) {
            if (typeof console !== 'undefined' && console.debug) {
                console.debug('PWA: service-worker registration skipped —', err && err.name);
            }
        }
    );

    // PWA-03/04: on offline fallback the banner already tells the user. When
    // the network comes back, reload once so the live server takes over.
    var _reloadedOnOffline = false;
    window.addEventListener('online', function () {
        if (_reloadedOnOffline) { return; }
        if (!window.__pwaSwActive) { return; } // not a PWA session — ignore
        // We don't know from here whether we're currently on an offline page
        // or a live one; the banner (injected by the SW) adds the data-pwa-
        // offline attribute to <body> — check for it before reload.
        var banner = document.querySelector('#pwa-offline-banner');
        if (!banner) { return; }
        _reloadedOnOffline = true;
        try { window.location.reload(); } catch (e) { /* noop */ }
    });
})();
