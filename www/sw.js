/**
 * Shuffle — PWA service worker (PWA-03..06, REQUIREMENTS v2.7 §7.18).
 *
 * Contract
 * --------
 *  - Navigation (top-level GET document) requests: network-first. A
 *    successful response is written through to the cache under its full URL
 *    (query string preserved, so per-board deep links are individually
 *    addressable offline). On network failure: serve the cached copy of that
 *    exact URL with an explicit offline banner (PWA-03); if the URL was never
 *    loaded online, serve the offline fallback page (PWA-04), pre-cached at
 *    install time so this path works even on a cold browser.
 *  - Same-origin static assets (css / js / images / webmanifest): cache-first,
 *    with write-through to the network on a miss (PWA-06).
 *  - Everything else — including ALL state-changing requests (POST/PUT/
 *    DELETE, uploads) and every /v1/* API call — passes through to the
 *    browser's default network fetch. Mutations are NEVER intercepted and
 *    NEVER cached (PWA-05). A write that could not reach the network fails
 *    loudly in the UI; nothing is queued or retried silently.
 *  - One versioned cache: `shuffle-pwa-v<N>`. On activation every other
 *    `shuffle-pwa-v*` cache is deleted — a hard replace, so a bumped
 *    CACHE_VERSION can never continue serving stale entries (PWA-06).
 *
 * Progressive enhancement (PWA-07): this worker only exists on origins where
 * `navigator.serviceWorker` is available (secure context). On plain http the
 * app runs without it and nothing here is load-bearing.
 *
 * CSP: `default-src 'self'` already covers a same-origin worker — no CSP
 * change. The injected offline banner is plain markup + inline styles, both
 * already permitted by the app's CSP (style-src 'unsafe-inline' is in force
 * for the per-row label/geometry paints).
 */
/* global self, caches, fetch */

"use strict";

var CACHE_VERSION = "1";
var CACHE_KEY = "shuffle-pwa-v" + CACHE_VERSION;
var CACHE_PREFIX = "shuffle-pwa-";

// The only pre-cached surface (PWA-04). Everything else enters the cache on
// a real successful response — nothing stale-by-design is held, and a broken
// deploy cannot persist a 500 as the offline bundle (we cache only OK).
var OFFLINE_BUNDLE = [
    "/manifest.webmanifest",
    "/offline.php",
    "/offline.css",
    "/img/icon-192.png"
];

// Offline banner injected before the <body> content of a cached page (PWA-03).
// `id="pwa-offline-banner"` is the stable hook pwa.js uses to detect we are
// on a cached page (so the online-recovery reload guards on it).
var OFFLINE_BANNER_HTML =
    '<div id="pwa-offline-banner" role="status" ' +
    'style="position:sticky;top:0;z-index:9999;padding:10px 16px;' +
    'background:#1A1A2E;color:#F5F3FF;font:14px/1.4 system-ui,sans-serif;' +
    'border-bottom:1px solid #2A2A3D;text-align:center;">' +
    'Offline &mdash; showing the last cached copy of this page. ' +
    'Actions you take will not sync until you are back online.</div>';

var ASSET_RE = /\.(png|jpe?g|svg|webmanifest|css|js|ico|woff2?|ttf)(\?|$)/i;
// A "navigation" for us is a top-level document request. .php and /offline-
// style routes qualify; static asset URLs do not (handled by ASSET_RE above).
var NAVIGATE_ASSET_EXCLUDE = /\.(png|jpe?g|svg|webmanifest|css|js|ico|woff2?|ttf)(\?|$)/i;

self.addEventListener("install", function (event) {
    event.waitUntil(
        caches.open(CACHE_KEY).then(function (cache) {
            return Promise.all(
                OFFLINE_BUNDLE.map(function (url) {
                    return fetch(url, { cache: "no-cache" }).then(
                        function (res) {
                            if (res && res.ok) {
                                return cache.put(url, res.clone());
                            }
                            // Non-OK response is NOT cached (PWA-04 contract).
                            return null;
                        },
                        function () { return null; } // fetch failure: skip
                    );
                })
            );
        })
        // Intentionally no skipWaiting(): active clients keep the current
        // worker until they close; the new version activates when the last
        // old client goes away — avoids a live cache-key swap mid-session.
    );
});

self.addEventListener("activate", function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys
                    .filter(function (k) {
                        return k.indexOf(CACHE_PREFIX) === 0 && k !== CACHE_KEY;
                    })
                    .map(function (k) { return caches.delete(k); })
            );
        })
    );
});

self.addEventListener("fetch", function (event) {
    var req = event.request;

    // PWA-05: only GET requests, same-origin, and never any /v1/* API call.
    if (req.method !== "GET") { return; }
    var url;
    try { url = new URL(req.url); } catch (e) { return; }
    if (url.origin !== self.location.origin) { return; }
    if (url.pathname.indexOf("/v1/") === 0) { return; }

    var pathNoQuery = url.pathname;

    // Navigation: top-level document load. Excludes same-origin static asset
    // URLs (an <img>?src fetch also reports mode navigate in some engines —
    // exclude them explicitly, the ASSET path below owns them).
    if (req.mode === "navigate" && !NAVIGATE_ASSET_EXCLUDE.test(pathNoQuery)) {
        event.respondWith(navigationFetch(req));
        return;
    }

    // Static assets (css/js/images/webmanifest) — cache-first + write-through.
    var assetPath = pathNoQuery + (url.search || "");
    if (ASSET_RE.test(assetPath)) {
        event.respondWith(assetFetch(req));
        return;
    }
    // Anything else: default browser behavior (or a 404).
});

/* ---------------------------- navigation ---------------------------- */

function navigationFetch(req) {
    return new Promise(function (resolve, reject) {
        fetch(req, { credentials: "same-origin" })
            .then(
                function (res) {
                    if (res && res.ok) {
                        // Write-through: remember this exact URL for offline.
                        // Clone because `res` is consumed by respondWith and
                        // the cache takes its own reference. Quota failures
                        // are swallowed — the page still loads.
                        var clone = res.clone();
                        caches.open(CACHE_KEY).then(function (cache) {
                            return cache.put(req.url, clone);
                        }).catch(function () {});
                    }
                    resolve(res);
                },
                function () {
                    resolve(offlineFallback(req));
                }
            );
    });
}

function offlineFallback(req) {
    return caches.open(CACHE_KEY).then(function (cache) {
        return cache.match(req.url).then(function (hit) {
            if (hit) {
                return withOfflineBanner(hit);
            }
            // PWA-04: never-loaded URL → the offline fallback page
            // (pre-cached at install).
            return cache.match("/offline.php").then(function (off) {
                return off || builtinOffline();
            });
        });
    });
}

function withOfflineBanner(response) {
    return response.text().then(function (html) {
        if (typeof html !== "string" || html.indexOf("<body") === -1) {
            return response;
        }
        if (html.indexOf('id="pwa-offline-banner"') !== -1) {
            return response; // already present — don't stack banners
        }
        var injected = html.replace(
            /<body[^>]*>/i,
            function (m) { return m + OFFLINE_BANNER_HTML; }
        );
        var headers = new Headers();
        var ct = response.headers.get("content-type");
        headers.set("Content-Type", ct || "text/html");
        headers.set("X-Offline", "1");
        return new Response(injected, {
            status: 200,
            statusText: "OK (offline)",
            headers: headers
        });
    }).catch(function () {
        return response; // text() failed (streaming?) — fall back to raw
    });
}

function builtinOffline() {
    // Absolute last resort (no /offline.php in the cache AND offline).
    var html =
        "<!DOCTYPE html><html lang='en'><head>" +
        "<meta charset='utf-8'>" +
        "<meta name='viewport' content='width=device-width,initial-scale=1'>" +
        "<title>Shuffle — offline</title>" +
        "<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0D0D12;color:#E2E2EC;font:16px/1.5 system-ui,sans-serif}div{max-width:24rem;text-align:center;padding:1rem}h1{font-size:1.25rem;margin:0 0 .5rem}</style>" +
        "</head><body><div>" +
        "<h1>You're offline</h1>" +
        "<p>No network, and this page has no cached copy yet. <br>" +
        "Try again once you are back online.</p>" +
        "</div></body></html>";
    return new Response(html, {
        status: 200,
        headers: { "Content-Type": "text/html", "X-Offline": "1" }
    });
}

/* ------------------------------ assets ------------------------------ */

function assetFetch(req) {
    return caches.open(CACHE_KEY).then(function (cache) {
        return cache.match(req.url).then(function (hit) {
            if (hit) { return hit; }
            // Cache miss → network; write through on success (PWA-06).
            return new Promise(function (resolve) {
                fetch(req, { cache: "no-cache" }).then(
                    function (res) {
                        if (res && res.ok) {
                            var clone = res.clone();
                            cache.put(req.url, clone).catch(function () {});
                        }
                        resolve(res);
                    },
                    function () { resolve(null); } // no network → null → 504-ish
                );
            }).then(function (res) {
                if (res) { return res; }
                return new Response("Asset unavailable offline.", {
                    status: 503,
                    headers: { "Content-Type": "text/plain" }
                });
            });
        });
    });
}
