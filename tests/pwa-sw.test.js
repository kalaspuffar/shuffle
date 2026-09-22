/**
 * tests/pwa-sw.test.js — vm-sandbox contract tests on www/sw.js (PWA-03..06).
 *
 * No service-worker runtime, no browser: the file is loaded as a plain
 * script into a `vm` sandbox with hand-built shims for the globals it
 * touches (`self`, `caches`, `fetch`, `URL`, `Response`, `Headers`,
 * `Promise`). Mirrors the established JS-contract pattern in
 * tests/priority-js.test.js and tests/card-modal-autosave.test.js.
 *
 * What this test proves (the spec contract, not the implementation):
 *   PWA-05  non-GET / cross-origin / /v1/* requests are never intercepted
 *   PWA-03  a GET navigation IS intercepted; on network failure the cached
 *           copy is served with a single #pwa-offline-banner right after <body>
 *   PWA-06  `activate` purges every older shuffle-pwa-v* cache — and only those
 *   PWA-04  `install` pre-caches the offline bundle, writing only res.ok
 *           responses (a broken deploy cannot poison the offline bundle)
 *           and a never-loaded URL falls back to the cached /offline.php
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..');
const SW_SRC = fs.readFileSync(path.join(ROOT, 'www', 'sw.js'), 'utf8');

let PASS = 0, FAIL = 0, FAILURES = [];
function check(name, cond, detail) {
    if (cond) { PASS++; console.log('PASS  ' + name); }
    else {
        FAIL++;
        FAILURES.push(name + (detail ? ' — ' + detail : ''));
        console.log('FAIL  ' + name + (detail ? '\n      ' + detail : ''));
    }
}

function headersShim(m) {
    return {
        _m: m || {},
        get: function (k) { return this._m[String(k).toLowerCase()]; },
        forEach: function () {}
    };
}

function okResponse(body) {
    return {
        ok: true,
        status: 200,
        headers: headersShim({ 'content-type': 'text/html' }),
        clone: function () { const c = okResponse(body); return c; },
        text: function () { return Promise.resolve(body || '<html><head></head><body><main data-x="1">board</main></body></html>'); }
    };
}

function makeFetch(implFn) {
    const calls = [];
    const fn = function (url, opts) {
        calls.push({ url: url, opts: opts || null });
        try {
            const r = implFn(url, opts || {});
            return (r && typeof r.then === 'function') ? r : Promise.resolve(r);
        } catch (e) {
            return Promise.reject(e);
        }
    };
    return { calls: calls, fn: fn };
}

function makeCaches(opts) {
    opts = opts || {};
    const state = {
        keys: opts.keys || [],
        entries: opts.entries || {},
        deleted: [],
        puts: []
    };
    const cacheObj = {
        put: function (url, res) {
            state.puts.push({ url: url, ok: !!(res && res.ok) });
            return Promise.resolve();
        },
        match: function (url) { return Promise.resolve(state.entries[url] || null); }
    };
    const shim = {
        state: state,
        open: function () { return Promise.resolve(cacheObj); },
        keys: function () { return Promise.resolve(state.keys.slice()); },
        delete: function (key) { state.deleted.push(key); return Promise.resolve(true); }
    };
    return shim;
}

function buildSandbox(opts) {
    const handlers = { install: [], activate: [], fetch: [] };
    const waitUntilQueue = [];
    const selfShim = {
        location: { origin: 'http://shuffle.ea.org' },
        addEventListener: function (ev, fn) { handlers[ev] = handlers[ev] || []; handlers[ev].push(fn); }
    };
    function URLShim(u) {
        const s = String(u);
        var m = s.match(/^(https?:\/\/[^/]+)([^?#]*)(?:\?([^#]*))?(?:#.*)?$/);
        if (m) {
            return { origin: m[1], pathname: m[2] || '/', search: m[3] ? '?' + m[3] : '', href: s };
        }
        // Relative URL (SW pre-cache uses "/offline.php" etc.) — resolve
        // against the worker origin, as the real Web Platform API does.
        const path = s;
        const sm = m = path.match(/^([^?#]*)(\?[^#]*)?(#.*)?$/);
        return {
            origin: 'http://shuffle.ea.org',
            pathname: sm[1],
            search: sm[2] || '',
            href: 'http://shuffle.ea.org' + s
        };
    }
    function ResponseShim(body, init) {
        this._body = body;
        this._init = init || {};
        this.headers = (init && init.headers) ? { get: (k) => (init.headers || {})[k], forEach: () => {} } : { get: () => undefined, forEach: () => {} };
    }
    ResponseShim.prototype.text = function () { return Promise.resolve(this._body); };
    function HeadersShim() {
        const m = {};
        this.set = (k, v) => { m[String(k).toLowerCase()] = v; };
        this.get = (k) => m[String(k).toLowerCase()];
    }

    const sandbox = {
        self: selfShim,
        caches: opts.caches,
        fetch: opts.fetchImpl,
        URL: URLShim,
        Response: ResponseShim,
        Headers: HeadersShim,
        Promise: Promise,
        console: console
    };
    vm.createContext(sandbox);
    vm.runInContext(SW_SRC, sandbox, { filename: 'sw.js' });
    return {
        sandbox: sandbox,
        handlers: handlers,
        waitUntil: waitUntilQueue,
        /** Standard event shape where waitUntil captures the Promise the
         *  handler chains onto, then awaitAll() awaits every one (mirrors
         *  the real event.waitUntil contract). */
        makeEvent: function () {
            var ev = {
                waitUntil: function (p) {
                    var pr = Promise.resolve(p);
                    waitUntilQueue.push(pr);
                    return pr;
                }
            };
            return ev;
        },
        awaitAll: function () { return Promise.all(waitUntilQueue.slice()); }
    };
}

/** Drive the fetch handler with a request; resolve to the intercepted
 *  response (Promise or bare) or null when the SW did not intercept. */
function driveFetch(handlers, req) {
    return new Promise(function (resolve) {
        const h = handlers.fetch && handlers.fetch[0];
        if (!h) { resolve(null); return; }
        const ev = { request: req, respondWith: null };
        ev.respondWith = function (r) { ev._responded = r; };
        h(ev);
        resolve(ev._responded === undefined ? null : ev._responded);
    });
}

function textOf(r) {
    if (r === null || r === undefined) { return Promise.resolve(''); }
    if (typeof r.then === 'function') { return r.then(function (x) { return (x && x.text) ? x.text() : String(x); }); }
    return Promise.resolve(r.text ? r.text() : String(r));
}

(async function main() {

    /* ===== PWA-05: intercept-surface contract ============================= */
    {
        const f = makeFetch(() => okResponse());
        const c = makeCaches({});
        const built = buildSandbox({ fetchImpl: f.fn, caches: c });

        let r = await driveFetch(built.handlers, { method: 'POST', url: 'http://shuffle.ea.org/v1/cards/1' });
        check('PWA-05 POST /v1/* is not intercepted', r === null);

        r = await driveFetch(built.handlers, { method: 'POST', url: 'http://shuffle.ea.org/boards.php' });
        check('PWA-05 POST app page is not intercepted', r === null);

        r = await driveFetch(built.handlers, { method: 'GET', url: 'http://shuffle.ea.org/v1/boards/1', mode: 'navigate' });
        check('PWA-05 GET /v1/boards/1 is not intercepted (API surface is hard-excluded)', r === null);

        r = await driveFetch(built.handlers, { method: 'PUT', url: 'http://shuffle.ea.org/v1/me' });
        check('PWA-05 PUT /v1/me is not intercepted', r === null);

        r = await driveFetch(built.handlers, { method: 'GET', url: 'http://evil.example/x.js', mode: 'navigate' });
        check('PWA-05 cross-origin GET is not intercepted', r === null);

        r = await driveFetch(built.handlers, { method: 'GET', url: 'http://shuffle.ea.org/boards.php', mode: 'navigate' });
        check('PWA-03 a GET navigation of an app page IS intercepted', r !== null);
    }

    /* ===== PWA-06: activate hard-purge ==================================== */
    {
        const c = makeCaches({ keys: ['shuffle-pwa-v0', 'shuffle-pwa-v1', 'other-cache', 'shuffle-pwa-v99'] });
        const f = makeFetch(() => okResponse());
        const built = buildSandbox({ fetchImpl: f.fn, caches: c });
        built.handlers.activate[0](built.makeEvent());
        await built.awaitAll();
        const deleted = c.state.deleted.slice().sort();
        // sw.js currently ships CACHE_VERSION "1" → v1 is the current cache,
        // so v0 and v99 are the stale ones. (The contract is: everything
        // other than the current version, hard-purged.)
        const expected = ['shuffle-pwa-v0', 'shuffle-pwa-v99'].sort();
        check('PWA-06 activate purges exactly the older shuffle-pwa-v* caches',
            JSON.stringify(deleted) === JSON.stringify(expected), 'deleted=' + JSON.stringify(deleted));
        check('PWA-06 activate leaves the current-version cache (v1 per CACHE_VERSION)',
            !deleted.includes('shuffle-pwa-v1'));
        check('PWA-06 activate leaves non-shuffle caches untouched', !deleted.includes('other-cache'));
    }

    /* ===== PWA-04: install pre-cache contract ============================= */
    {
        const BUNDLE = ['/manifest.webmanifest', '/offline.php', '/offline.css', '/img/icon-192.png'];

        const c = makeCaches({});
        const f = makeFetch(() => okResponse());
        const built = buildSandbox({ fetchImpl: f.fn, caches: c });
        // The SW reads OFFLINE_BUNDLE from its own source, so the bundle is
        // whatever sw.js defines; assert it contains the offline page.
        built.handlers.install[0](built.makeEvent());
        await built.awaitAll();
        const urls = c.state.puts.map((p) => p.url).sort();
        check('PWA-04 install pre-caches the offline fallback page', urls.indexOf('/offline.php') !== -1, 'puts=' + JSON.stringify(urls));
        check('PWA-04 install pre-caches the offline css', urls.indexOf('/offline.css') !== -1);
        check('PWA-04 install writes only res.ok responses', c.state.puts.every((p) => p.ok === true));

        case2: {
        }

        // (b) a 500 from /offline.php must NOT be persisted (no deploy poison)
        const c2 = makeCaches({});
        const f2 = makeFetch((u) => {
            if (String(u).indexOf('/offline.php') !== -1) { return { ok: false, status: 500 }; }
            return okResponse();
        });
        const built2 = buildSandbox({ fetchImpl: f2.fn, caches: c2 });
        built2.handlers.install[0](built2.makeEvent());
        await built2.awaitAll();
        const bad = c2.state.puts.filter((p) => p.url === '/offline.php');
        check('PWA-04 install skips a bundle entry that did not succeed', bad.length === 0, 'puts=' + JSON.stringify(c2.state.puts));
    }

    /* ===== PWA-03: offline banner injection =============================== */
    {
        const cachedBody = '<html><head><title>Boards</title></head><body><main data-x="1">board</main></body></html>';
        const c = makeCaches({
            entries: {
                'http://shuffle.ea.org/boards.php': {
                    ok: true,
                    status: 200,
                    headers: headersShim({ 'content-type': 'text/html' }),
                    text: () => Promise.resolve(cachedBody),
                    clone: function () { return this; }
                }
            }
        });
        const f = makeFetch(() => { throw new TypeError('offline'); });
        const built = buildSandbox({ fetchImpl: f.fn, caches: c });

        const r = await driveFetch(built.handlers, { method: 'GET', url: 'http://shuffle.ea.org/boards.php', mode: 'navigate' });
        const html = await textOf(r);
        check('PWA-03 offline navigation resolves to the cached copy', html.includes('<main data-x="1">board</main>'));
        check('PWA-03 banner is injected', html.indexOf('id="pwa-offline-banner"') !== -1, 'html=' + html.slice(0, 260));
        check('PWA-03 banner is the first child of <body>',
            /<body[^>]*>\s*<div\s+id="pwa-offline-banner"/.test(html));
        check('PWA-03 banner appears exactly once (idempotent guard)',
            (html.match(/id="pwa-offline-banner"/g) || []).length === 1);

        /* PWA-04 first-visit path: URL never cached → the cached fallback
           page is served instead of a dead screen. */
        const c2 = makeCaches({
            entries: {
                '/offline.php': {
                    ok: true,
                    status: 200,
                    headers: headersShim({ 'content-type': 'text/html' }),
                    text: () => Promise.resolve('<html><body>Offline fallback page</body></html>'),
                    clone: function () { return this; }
                }
            }
        });
        const f2 = makeFetch(() => { throw new TypeError('offline'); });
        const built2 = buildSandbox({ fetchImpl: f2.fn, caches: c2 });
        const r2 = await driveFetch(built2.handlers, { method: 'GET', url: 'http://shuffle.ea.org/never/loaded.php', mode: 'navigate' });
        const html2 = await textOf(r2);
        check('PWA-04 a never-loaded URL serves the cached offline fallback',
            html2.indexOf('Offline fallback page') !== -1, 'html2=' + html2.slice(0, 200));
    }

    /* ==================================================== summary ========= */
    console.log('');
    console.log('='.repeat(52));
    console.log('PWA-SW SANDBOX: PASS=' + PASS + ' FAIL=' + FAIL);
    if (FAIL > 0) {
        console.log('Failures:');
        FAILURES.forEach((s) => console.log('  - ' + s));
        process.exit(1);
    }
    console.log('all green');
})().catch(function (e) { console.error('SANDBOX CRASH', e); process.exit(2); });
