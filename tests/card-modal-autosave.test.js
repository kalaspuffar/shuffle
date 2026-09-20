#!/usr/bin/env node
/**
 * Shuffle — card-modal autosave client logic (v1.13 Stages C/D, SEC §5.21)
 *
 * Loads the real www/js/card-modal.js in a Node vm context against a minimal
 * DOM shim plus a mocked Shuffle.api. The card is seeded via
 * window.ShuffleCardModal.openById(<cardId>), which triggers loadCard() and
 * applyCard(). After that the module's state.card is the single source of
 * truth for the autosave diff (the dirty-flag contract on input handlers,
 * per-file debounce timers, and the client-side empty-title guard).
 *
 * Covered scenarios (each in an isolated sandbox so no state leaks across
 * tests):
 *
 *   [1] real title change + debounce settle → exactly one additional PUT,
 *       title-only, title input + header title resynced from the response
 *   [2] empty title → client-side guard flashes the i18n error, reverts
 *       the field, and never round-trips (server-side 422 never fires)
 *   [3] rapid edits (three) within the debounce window → exactly one PUT
 *       carrying the FINAL value (debounce contract)
 *   [4] bare blur without change → no PUT (the no-op short-circuit)
 *   [5] real due-date change → exactly one PUT, due_date-only
 *   [6] clearing the due date → PUT with due_date===null (not "absent";
 *       the server reads null as "clear", distinct from omission)
 *   [7] opening a different card (applyCard) with a stale unsaved edit →
 *       the stale PUT is cancelled (clearTimeout is called on the stale
 *       timer; no PUT for the prior card fires)
 *   [8] Escape in the title field → reverts to the stored title, clears
 *       the pending schedule; no PUT after settle
 *
 * Usage: node tests/card-modal-autosave.test.js
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'www', 'js', 'card-modal.js'), 'utf8');

let checks = 0;
let failures = 0;
function check(name, cond, detail) {
    checks++;
    if (cond) { console.log('PASS  ' + name); }
    else {
        failures++;
        console.log('FAIL  ' + name);
        if (detail !== undefined) console.log('     ' + detail);
    }
}

function settle(ms) { return new Promise(r => setTimeout(r, ms)); }

// ---------------------------------------------------------------------------
// Minimal DOM shim — just enough for card-modal.js parse + the input/blur/
// keydown autosave surface + openById(seed) + the header title + the close
// button. The modal's other panels/tabs (assignees, checklists, comments,
// etc.) are NOT present in the DOM, so every applyCard() branch that would
 // touch them early-returns on null refs (which we have verified across the
 // code paths relevant to this suite).
// ---------------------------------------------------------------------------
class E {
    constructor(tag) {
        this.tagName = String(tag).toUpperCase();
        this.children = [];
        this.parentNode = null;
        this._attrs = Object.create(null);
        this._listeners = {};
        this._classes = new Set();
        this._text = '';
        this.value = '';
        this.textContent = '';
        this.disabled = false;
        this.hidden = false;
        this.style = {};
        this._dataset = Object.create(null);
    }
    get id() { return this._attrs['id'] || null; }
    set id(v) { this._attrs['id'] = String(v); }
    get className() { return [...this._classes].join(' '); }
    set className(v) { this._classes = new Set(String(v).split(/\s+/).filter(Boolean)); }
    get textContent() { return this._text; }
    set textContent(v) { this._text = String(v); }
    get dataset() { return this._dataset; }
    set dataset(o) { Object.assign(this._dataset, o); }
    get _selId() { return this._attrs['id']; }
    classList = { _s: this._classes,
        add(...n) { n.forEach(c => this._classes.add(c)); },
        remove(...n) { n.forEach(c => this._classes.delete(c)); },
        contains(c) { return this._classes.has(c); },
        toggle(c, on) { if (on === true) this._classes.add(c); else if (on === false) this._classes.delete(c); else if (this._classes.has(c)) this._classes.delete(c); else this._classes.add(c); },
    };
    setAttribute(k, v) {
        if (k === 'id') { this._attrs['id'] = String(v); return; }
        if (k === 'class') { this._classes = new Set(String(v).split(/\s+/).filter(Boolean)); return; }
        if (k.startsWith('data-')) { const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase()); if (v === null || v === undefined) delete this._dataset[camel]; else this._dataset[camel] = String(v); return; }
        if (v === null) { delete this._attrs[k]; return; }
        this._attrs[k] = String(v);
    }
    getAttribute(k) {
        if (k === 'id') return this._attrs['id'] || null;
        if (k === 'class') { return this.className; }
        if (k.startsWith('data-')) { const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase()); const v = this._dataset[camel]; return (v === undefined || v === null) ? null : String(v); }
        const v = this._attrs[k];
        return v === undefined || v === null ? null : String(v);
    }
    removeAttribute(k) {
        if (k === 'id') { delete this._attrs['id']; return; }
        if (k === 'class') { this._classes = new Set(); return; }
        if (k.startsWith('data-')) { const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase()); delete this._dataset[camel]; return; }
        delete this._attrs[k];
    }
    appendChild(c) { if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.push(c); return c; }
    removeChild(c) { const i = this.children.indexOf(c); if (i !== -1) this.children.splice(i, 1); c.parentNode = null; return c; }
    addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
    _emitLocal(type, ev) { const l = this._listeners[type]; if (l) l.slice().forEach(fn => fn.call(this, ev)); }
    dispatch(type, ev) {
        ev = Object.assign({ type, target: (ev && ev.target) || this, preventDefault() { ev && (ev.defaultPrevented = true); } }, ev || {});
        for (let n = this; n; n = n.parentNode) n._emitLocal(type, ev);
    }
    closest(sel) {
        for (let n = this; n; n = n.parentNode) if (n._match(sel)) return n;
        return null;
    }
    _match(sel) {
        sel = String(sel).trim();
        if (!sel) return false;
        if (sel.startsWith('.')) return this._classes.has(sel.slice(1));
        if (sel.startsWith('#')) return this._attrs['id'] === sel.slice(1);
        if (sel.startsWith('[')) {
            const m = sel.match(/^\[([\w-]+)="?([^"]*)"\]?$/);
            if (m) return this.getAttribute(m[1]) === m[2];
        }
        return this.tagName === sel.toUpperCase();
    }
    querySelectorAll(sel) {
        const out = [];
        (function walk(n) { for (const c of n.children || []) { if (c._match && c._match(sel)) out.push(c); walk(c); } })(this);
        return out;
    }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
    focus() {} blur() { /* no-op: tests dispatch blur explicitly */ }
    scrollIntoView() {}
    insertAdjacentHTML(pos, html) { this._innerHTML = (this._innerHTML || '') + html; this.children.length = 0; }
    cloneNode(deep) { const c = new E(this.tagName); c.children = this.children.slice(); c._attrs = Object.assign({}, this._attrs); c._classes = new Set(this._classes); c._text = this._text; if (deep) this.children.forEach(x => c.appendChild(x.cloneNode(true))); return c; }
}

function buildDom(LANG) {
    const body = new E('body');
    const script = new E('script');
    script.id = 'board-script';
    script.setAttribute('data-lang', JSON.stringify(LANG));
    script.setAttribute('data-can-edit', '1');
    script.setAttribute('data-me', '4');
    script.setAttribute('data-role', 'member');
    body.appendChild(script);

    const boardPage = new E('div');
    boardPage.className = 'board-view-page';
    boardPage.setAttribute('data-board-id', '7');
    body.appendChild(boardPage);

    const overlay = new E('div'); overlay.id = 'card-modal-overlay';
    const modal = new E('div'); modal.id = 'card-modal';

    // A close button — card-modal.js binds every .modal-close to close().
    // It's a no-op in our tests (we never trigger it), but the module
    // expects the list to be non-empty enough to exercise the binding path.
    const closeButton = new E('button');
    closeButton.className = 'modal-close';
    closeButton.textContent = '\u00d7';
    modal.appendChild(closeButton);

    const modalTitle = new E('div'); modalTitle.id = 'card-modal-title';
    const badge = new E('div'); badge.id = 'card-modal-archived-badge';
    const bodyScroll = new E('div'); bodyScroll.id = 'card-modal-body';

    const form = new E('form'); form.id = 'card-modal-form';
    const titleInput = new E('input'); titleInput.id = 'card-modal-title-input'; titleInput.setAttribute('type', 'text');
    const dueInput = new E('input'); dueInput.id = 'card-modal-due-date'; dueInput.setAttribute('type', 'date');
    form.appendChild(titleInput);
    form.appendChild(dueInput);

    bodyScroll.appendChild(form);
    modal.appendChild(modalTitle);
    modal.appendChild(badge);
    modal.appendChild(bodyScroll);
    overlay.appendChild(modal);
    body.appendChild(overlay);

    const cardEl = new E('article');
    cardEl.className = 'card';
    cardEl.setAttribute('data-card-id', '42');
    body.appendChild(cardEl);

    return { body, script, overlay, modal, modalTitle, badge, bodyScroll, form, titleInput, dueInput, boardPage, cardEl };
}

// The i18n bundle card-modal.js reads from <script id="board-script" data-lang=...>.
const LANG = {
    error_bad_request: 'Bad request',
    card_update_success: 'Card saved',
    card_title_required: 'Title required',
    card_assignee_filter_placeholder: 'Filter users',
    card_assignee_no_users: 'No users',
    card_assignee_picker_label: 'Pick user(s)',
    card_description_empty: 'No description yet.',
    card_description_edit: 'Edit',
    card_description_preview: 'Preview',
    action_save: 'Save',
    action_cancel: 'Cancel',
    checklist_empty: 'No checklists.',
    attachment_empty: 'No attachments.',
    comment_empty: 'No comments.',
    "label_card_modal": "Labels",
    "label_card_modal.remove": "Remove",
    "label_card_modal.add": "Add label",
    "label_card_modal.pick": "Pick a label",
    card_save_success: 'Card saved',
    label_manage_empty: 'No labels on this board yet.',
    card_move_to_board: 'Move to board…',
    card_move_to_board_current: 'current board',
    card_merge_into: 'Merge into…',
    card_merge_warning: 'Merge into {0}?',
    action_archive: 'Archive',
    action_delete: 'Delete',
    card_archive_confirm: 'Archive this card?',
    card_archive_success: 'Card archived',
    card_delete_confirm: 'Delete this card?',
    card_delete_success: 'Card deleted',
};

function makeSandbox() {
    const dom = buildDom(LANG);
    const root = dom.body;
    const rootListeners = [];

    const documentObj = {
        body: root,
        createElement: (t) => new E(t),
        getElementById: (id) => root.querySelectorAll('[id="' + id + '"]')[0] || null,
        querySelector: (sel) => root.querySelector(sel),
        querySelectorAll: (sel) => root.querySelectorAll(sel),
        addEventListener: (type, fn) => rootListeners.push({ type, fn }),
        dispatch: (type, ev) => rootListeners.slice().forEach(l => l.fn.call(documentObj, ev)),
        readyState: 'complete',
    };

    const apiLog = [];
    const queue = [];   // ordered responses for consecutive api() calls.
    let queueIndex = 0;
    let defaultStatus = 200;
    let defaultData = { status: 200, data: { card: null } };
    // §12: forced-delay responses keyed by (method+url) → ms — lets a test
    // hold a specific api() call in-flight (the "close before settle" path).
    const delayMap = Object.create(null);
    const shuffleObj = {
        api: function (url, options) {
            options = options || {};
            const urlStr = String(url);
            const method = (options.method || 'GET').toUpperCase();
            apiLog.push({ url: urlStr, method: method, body: options.body || null });
            let result;
            if (queueIndex < queue.length) {
                result = queue[queueIndex++];
            } else {
                result = defaultData;
            }
            const delay = delayMap[method + ' ' + urlStr];
            if (delay != null && delay > 0) {
                return new Promise((resolve) => setTimeout(() => resolve(result), delay));
            }
            return Promise.resolve(result);
        },
        showFlash() {},
        getCsrfToken: () => 'test',
    };
    shuffleObj.__delayMap = delayMap;

    // Track clearTimeout/setTimeout calls so §7 (stale-timer cleanup) can
    // assert that the module actually un-scheduled the pending save.
    const timeouts = [];
    const clearLog = [];
    const syncState = { calls: [], log: [] };
    // Mutable pending sync (RT-07 close() path): the test flips this to true
    // to exercise "a board_version bump was deferred while the modal was open".
    let pendingSync = false;
    const boardSyncStub = {
        hasPendingSync: () => pendingSync,
        clearPendingSync: function () { pendingSync = false; },
        syncNow: function (targetVersion) {
            syncState.calls.push(targetVersion);
            syncState.log.push(targetVersion);
            return Promise.resolve();
        },
        refreshHeader: function () {
            syncState.headerRefreshes = (syncState.headerRefreshes || 0) + 1;
            return Promise.resolve();
        },
    };
    const sandbox = {
        document: documentObj,
        window: {
            location: { search: '' },
            Shuffle: null,   // filled right after the sandbox object is built
            ShuffleBoardSync: boardSyncStub,
        },
        console, Promise, JSON,
        parseInt: (v, r) => parseInt(v, r),
        setTimeout: (fn, ms) => { const id = setTimeout(fn, ms || 0); timeouts.push(id); return id; },
        clearTimeout: (id) => { clearLog.push(id); clearTimeout(id); },
        URL: function (u) { const q = (u || '').indexOf('?') === 0 ? u.slice(1) : (u || '').split('?')[1] || ''; const p = {}; q.split('&').filter(Boolean).forEach(kv => { const [k, v] = kv.split('='); p[decodeURIComponent(k)] = decodeURIComponent(v || ''); }); return { searchParams: { get: (k) => (k in p) ? p[k] : null } }; },
        URLSearchParams: function (search) { const p = {}; ((search || '').replace(/^\?/, '').split('&')).filter(Boolean).forEach(kv => { const [k, v] = kv.split('='); p[decodeURIComponent(k)] = decodeURIComponent(v || ''); }); return { get: (k) => (k in p) ? p[k] : null }; },
        Array, Object, Date, String, Number,
        requestAnimationFrame: (fn) => setTimeout(fn, 0),
        XMLHttpRequest: function () { throw new Error('XHR must not be called in this suite'); },
        confirm: () => false,
    };
    vm.createContext(sandbox);
    sandbox.Shuffle = shuffleObj;   // card-modal.js calls a bare `Shuffle` global
    sandbox.window.Shuffle = shuffleObj;   // flash() reads `window.Shuffle.showFlash` for the guard path (test [2])
    return {
        dom, sandbox, shuffleObj, apiLog, timeouts, clearLog, queue, domObj: documentObj, syncState,
        // §12/§13: RT-07 close-path controls.
        setPendingSync(v) { pendingSync = !!v; },
        setDelay(key, ms) { shuffleObj.__delayMap[key] = ms; },
    };
}

function runModule(s, card) {
    // Seed the card before the module loads (so the first loadCard() picks
    // it up) — openById → loadCard → GET /v1/cards/42.
    s.queue.push({ status: 200, data: { card } });
    vm.runInContext(SRC, s.sandbox, { filename: 'card-modal.js' });
    const api = s.sandbox.window.ShuffleCardModal;
    if (!api || typeof api.openById !== 'function') throw new Error('window.ShuffleCardModal.openById missing');
    api.openById(42);   // triggers loadCard(42) → applyCard(card)
    return api;
}

function countPuts(s, path) {
    return s.apiLog.filter(x =>
        x.url === '/v1/cards/42' &&
        x.method === 'PUT');
}

const BASE = {
    id: 42, title: 'Autosave card', due_date: '2026-12-31',
    description: '', description_html: '<p></p>',
    is_archived: false, assigned_users: [], labels: [],
    checklists: [], attachments: [], comments: [],
};

(async function main() {
    try {
        // ================= [1] real title change ============================
        console.log('\n[1] real title change → one PUT, title-only, header + input resynced');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;

            s.dom.titleInput.value = 'New title';
            s.dom.titleInput.dispatch('input', {});
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'New title' } } });
            await settle(900);

            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('exactly one PUT', puts.length === 1, JSON.stringify(s.apiLog.slice(before)));
            check('PUT body.title = "New title"', puts[0] && puts[0].body && puts[0].body.title === 'New title', puts[0] && JSON.stringify(puts[0].body));
            check('PUT body has no due_date', puts[0] && puts[0].body && !('due_date' in puts[0].body), puts[0] && JSON.stringify(puts[0].body));
            check('header title resynced from response', s.dom.modalTitle.textContent === 'New title', 'got: ' + JSON.stringify(s.dom.modalTitle.textContent));
            check('title input matches saved value', s.dom.titleInput.value === 'New title', 'got: ' + JSON.stringify(s.dom.titleInput.value));
        }

        // ================= [2] empty title guard ============================
        console.log('\n[2] empty title → client-side guard reverts the field, flashes i18n error, no PUT');
        {
            const s = makeSandbox();
            const flashes = [];
            s.shuffleObj.showFlash = (m, t) => { flashes.push({ m, t: t || 'info' }); };
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;

            s.dom.titleInput.value = '';
            s.dom.titleInput.dispatch('input', {});
            s.dom.titleInput.dispatch('blur', {});
            await settle(900);

            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('no PUT was issued', puts.length === 0, JSON.stringify(s.apiLog.slice(before)));
            check('error flash fired with the i18n text', flashes.some(f => f.t === 'error' && /Title/i.test(f.m)), JSON.stringify(flashes));
            check('field reverted to the stored title', s.dom.titleInput.value === 'Autosave card', 'got: ' + JSON.stringify(s.dom.titleInput.value));
        }

        // ================= [3] debounce = 1 round-trip ======================
        console.log('\n[3] rapid edits (3) within the debounce window → exactly one PUT with the FINAL value');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;

            s.dom.titleInput.value = 'First';
            s.dom.titleInput.dispatch('input', {});
            await settle(10);
            s.dom.titleInput.value = 'Second';
            s.dom.titleInput.dispatch('input', {});
            await settle(10);
            s.dom.titleInput.value = 'Final';
            s.dom.titleInput.dispatch('input', {});

            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Final' } } });
            await settle(900);

            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('exactly one PUT after the debounce window', puts.length === 1, 'count=' + puts.length + ' full=' + JSON.stringify(s.apiLog.slice(before)));
            check('PUT body.title = "Final" (final value)', puts[0] && puts[0].body && puts[0].body.title === 'Final', puts[0] && JSON.stringify(puts[0].body));
        }

        // ================= [4] blur without change = no PUT =================
        console.log('\n[4] bare blur without change → no PUT');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;
            s.dom.titleInput.dispatch('blur', {});
            await settle(900);
            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('no PUT was issued', puts.length === 0, JSON.stringify(s.apiLog.slice(before)));
        }

        // ================= [5] due-date change ==============================
        console.log('\n[5] real due-date change → one PUT, due_date-only');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;
            s.dom.dueInput.value = '2027-01-15';
            s.dom.dueInput.dispatch('input', {});
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, due_date: '2027-01-15' } } });
            await settle(900);
            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('exactly one PUT', puts.length === 1, JSON.stringify(s.apiLog.slice(before)));
            check('PUT body.due_date = "2027-01-15"', puts[0] && puts[0].body && puts[0].body.due_date === '2027-01-15', puts[0] && JSON.stringify(puts[0].body));
            check('PUT body has no title', puts[0] && puts[0].body && !('title' in puts[0].body), puts[0] && JSON.stringify(puts[0].body));
        }

        // ================= [6] clear the due date ===========================
        console.log('\n[6] clearing the due date → PUT with due_date===null (server reads null as "clear")');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;
            s.dom.dueInput.value = '';
            s.dom.dueInput.dispatch('input', {});
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, due_date: null } } });
            await settle(900);
            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('exactly one PUT', puts.length === 1, JSON.stringify(s.apiLog.slice(before)));
            check('PUT body.due_date === null', puts[0] && puts[0].body && ('due_date' in puts[0].body) && puts[0].body.due_date === null, puts[0] && JSON.stringify(puts[0].body));
        }

        // ================= [7] stale-timer un-schedule ======================
        console.log('\n[7] opening a different card while a title autosave is pending → the stale PUT is cancelled');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;
            const clearCountBefore = s.clearLog.length;

            // Dirty the title; don't wait for the debounce.
            s.dom.titleInput.value = 'Stale edit';
            s.dom.titleInput.dispatch('input', {});
            // The module has now scheduled a timer. Now open a NEW card,
            // whose applyCard() should clearTimeout(staleTimer).
            // Seed a card on a different id for openById(99).
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 99, title: 'Card ninety-nine' } } });
            s.sandbox.window.ShuffleCardModal.openById(99);
            await settle(10);

            // Now let the stale 800 ms window expire.
            await settle(900);

            const puts42 = s.apiLog.slice(before).filter(x => x.url === '/v1/cards/42' && x.method === 'PUT');
            check('no stale PUT for the prior card (42)', puts42.length === 0, JSON.stringify(s.apiLog.slice(before)));
            check('clearTimeout was called on the stale timer', s.clearLog.length > clearCountBefore,
                'clearLog=' + JSON.stringify(s.clearLog));
        }

        // ================= [8] Escape reverts ===============================
        console.log('\n[8] Escape in the title input → reverts to the stored value and discards the schedule');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const before = s.apiLog.length;
            s.dom.titleInput.value = 'Will be discarded';
            s.dom.titleInput.dispatch('input', {});
            s.dom.titleInput.dispatch('keydown', { key: 'Escape', preventDefault() {} });
            await settle(900);
            const puts = s.apiLog.slice(before).filter(x => x.method === 'PUT');
            check('no PUT after Escape + settle', puts.length === 0, JSON.stringify(s.apiLog.slice(before)));
            check('field reverted to the stored title', s.dom.titleInput.value === 'Autosave card', 'got: ' + JSON.stringify(s.dom.titleInput.value));
        }

        // ================= [9] close after save → immediate region sync ====
        // The bug Daniel hit: autosave bumped the board version but the tile
        // stayed stale (the 15 s poll tick hadn't run → pendingSync unset →
        // no reconcile on close). close() must now call ShuffleBoardSync
        // .syncNow() exactly once.
        console.log('\n[9] close after a successful autosave → one syncNow() call');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);

            s.dom.titleInput.value = 'Fresh title';
            s.dom.titleInput.dispatch('input', {});
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Fresh title' } } });
            await settle(900);

            const before = s.syncState.calls.length;
            s.sandbox.window.ShuffleCardModal.close();
            await settle(20);

            check('exactly one syncNow() call after a save + close', s.syncState.calls.length === before + 1,
                'calls=' + JSON.stringify(s.syncState.calls));
        }

        // ================= [10] close without any mutation → no sync =======
        console.log('\n[10] close with no mutations this open → no syncNow() call');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);

            s.dom.titleInput.dispatch('blur', {});
            await settle(200);
            const before = s.syncState.calls.length;
            s.sandbox.window.ShuffleCardModal.close();
            await settle(100);

            check('no syncNow() when nothing was saved this open', s.syncState.calls.length === before,
                'calls=' + JSON.stringify(s.syncState.calls));
        }

        // ================= [11] syncNow is not doubled ======================
        console.log('\n[11] two closes in a row with one save between → exactly one syncNow() per close-after-save');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            // Open 1: save then close → 1 call
            s.dom.titleInput.value = 'Title one';
            s.dom.titleInput.dispatch('input', {});
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Title one' } } });
            await settle(900);
            const beforeA = s.syncState.calls.length;
            s.sandbox.window.ShuffleCardModal.close();
            await settle(20);
            check('close #1 (after save) fires syncNow once', s.syncState.calls.length === beforeA + 1,
                'calls=' + JSON.stringify(s.syncState.calls));
            // Open 2 (no save) then close → 0 additional
            s.sandbox.window.ShuffleCardModal.openById(42);
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Title one' } } });
            await settle(10);
            const beforeB = s.syncState.calls.length;
            s.sandbox.window.ShuffleCardModal.close();
            await settle(20);
            check('close #2 (no save) fires no syncNow', s.syncState.calls.length === beforeB,
                'calls=' + JSON.stringify(s.syncState.calls));
        }

        // ================= [12] close while autosave in flight ===============
        // RT-07: the classic "close before autosave lands" race. user types
        // → hits Escape in the last 800 ms of the debounce window; the PUT is
        // still on the wire when close() runs. With the old implementation
        // close() would read state.boardMutations = 0 (success not yet run) and
        // skip the reconcile; the save then lands, noteBoardMutation() bumps
        // the counter, but the modal is already gone — the board tile stays
        // stale. RT-07 closes this window with the _boardMutInFlight guard +
        // settle() re-firing once the counter hits 0.
        console.log('\n[12] close fires during an in-flight autosave → exactly one syncNow()');
        {
            const s = makeSandbox();
            // Hold the PUT in-flight (the response resolves after 120 ms).
            s.setDelay('PUT /v1/cards/42', 120);
            runModule(s, BASE);
            await settle(10);

            s.dom.titleInput.value = 'In-flight title';
            s.dom.titleInput.dispatch('input', {});
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'In-flight title' } } });
            await settle(900);   // debounce window; the PUT is now dispatched and delayed

            const before = s.syncState.calls.length;
            s.sandbox.window.ShuffleCardModal.close();
            await settle(20);

            check('no syncNow() fired while the save was still in flight',
                s.syncState.calls.length === before,
                'calls=' + JSON.stringify(s.syncState.calls));

            // Let the in-flight PUT resolve → noteBoardMutation() → _boardMutEnd
            // → settle() → syncNow() exactly once.
            await settle(200);

            check('exactly one syncNow() after the in-flight save lands',
                s.syncState.calls.length === before + 1,
                'calls=' + JSON.stringify(s.syncState.calls));
        }

        // ================= [13] close with deferred bump = in-place refresh ===
        // RT-07: a board_version bump arrived while the modal was open, so
        // board.js deferred it (pendingSync) instead of breaking in. Close()
        // must NOT fire a full location.reload() — it resolves the deferred
        // bump in place via syncNow() AND refreshHeader() (the header
        // surface a region swap does not touch).
        console.log('\n[13] close with a deferred board_version bump → syncNow + refreshHeader, no reload');
        {
            const s = makeSandbox();
            s.setPendingSync(true);          // a version bump was deferred while the modal was open
            runModule(s, BASE);
            await settle(10);

            const beforeSync = s.syncState.calls.length;
            const beforeHeader = s.syncState.headerRefreshes | 0;
            s.sandbox.window.ShuffleCardModal.close();
            await settle(30);

            check('pendingSync is cleared after close()',
                s.sandbox.window.ShuffleBoardSync.hasPendingSync() === false,
                'still pending? ' + JSON.stringify(s.sandbox.window.ShuffleBoardSync.hasPendingSync()));
            check('close fires syncNow() to reconcile the deferred bump in place',
                s.syncState.calls.length === beforeSync + 1,
                'calls=' + JSON.stringify(s.syncState.calls));
            check('close fires refreshHeader() to cover the header surface',
                (s.syncState.headerRefreshes || 0) === beforeHeader + 1,
                'headerRefreshes=' + JSON.stringify(s.syncState.headerRefreshes));
        }

        // ================= [14] RT-08: onRegionSwapped — clean modal =======
        // board.js swaps the region while the modal is open AND clean →
        // the modal re-fetches its card and re-renders the inputs in place.
        console.log('\n[14] modal open + CLEAN + region swap → live re-fetch, inputs updated');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const api = s.sandbox.window.ShuffleCardModal;
            check('isCardModalVisible() is true after open', api.isCardModalVisible() === true);
            check('isDirty() is false for a fresh, unedited modal', api.isDirty() === false);

            const getsBefore = s.apiLog.filter(x => x.method === 'GET' && /\/v1\/cards\/42$/.test(x.url)).length;
            // The server-side title changed elsewhere (another browser): the
            // next GET returns the NEW title.
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Changed elsewhere' } } });
            const result = api.onRegionSwapped();
            await settle(20);

            const getsAfter = s.apiLog.filter(x => x.method === 'GET' && /\/v1\/cards\/42$/.test(x.url)).length;
            check('onRegionSwapped() returns true (refresh applied)', result === true);
            check('exactly one card GET (the live refresh)', getsAfter === getsBefore + 1, JSON.stringify(s.apiLog));
            check('header title re-rendered with the fresh value',
                s.dom.modalTitle.textContent === 'Changed elsewhere', 'got: ' + JSON.stringify(s.dom.modalTitle.textContent));
            check('title input re-rendered with the fresh value',
                s.dom.titleInput.value === 'Changed elsewhere', 'got: ' + JSON.stringify(s.dom.titleInput.value));
            check('no PUT round-tripped', s.apiLog.filter(x => x.method === 'PUT').length === 0, JSON.stringify(s.apiLog));
        }

        // ================= [15] RT-08: onRegionSwapped — dirty modal =======
        // Modal is DIRTY (unsaved title edit) → the refresh is QUEUED, not
        // applied (applying now would clobber the user's in-progress text or
        // race the in-flight save). It must NOT re-fetch, and isDirty() must
        // still report true (board.js then defers the region swap too).
        console.log('\n[15] modal open + DIRTY + region swap → refresh queued, inputs untouched');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const api = s.sandbox.window.ShuffleCardModal;

            // An unsaved edit in the title input (the 'dirty' state).
            s.dom.titleInput.value = 'Unsaved edit';
            s.dom.titleInput.dispatch('input', {});
            check('isDirty() is true while an edit is pending', api.isDirty() === true);

            const getsBefore = s.apiLog.filter(x => x.method === 'GET' && /\/v1\/cards\/42$/.test(x.url)).length;
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Server version' } } });
            const result = api.onRegionSwapped();
            await settle(20);

            const getsAfter = s.apiLog.filter(x => x.method === 'GET' && /\/v1\/cards\/42$/.test(x.url)).length;
            check('onRegionSwapped() returns false (deferred)', result === false);
            check('NO re-fetch while dirty (the user\'s text wins for now)', getsAfter === getsBefore,
                'GETs=' + getsAfter);
            check('the user\'s unsaved text is still in the input',
                s.dom.titleInput.value === 'Unsaved edit', 'got: ' + JSON.stringify(s.dom.titleInput.value));
            // Let the queued autosave land; the deferred refresh settles on
            // the save-success path (it re-fetches, but AFTER the PUT).
            s.queue.push({ status: 200, data: { card: { ...BASE, id: 42, title: 'Unsaved edit' } } });
            await settle(900);
            check('autosave still fired after the queued refresh (no lost save)',
                s.apiLog.filter(x => x.method === 'PUT').length === 1,
                JSON.stringify(s.apiLog.filter(x => x.method === 'PUT')));
        }

        // ================= [16] RT-08: onRegionSwapped — closed modal ======
        // Board fires the hook but the modal is closed → no-op (no fetch).
        console.log('\n[16] region swap with the modal CLOSED → no-op, no fetch');
        {
            const s = makeSandbox();
            runModule(s, BASE);
            await settle(10);
            const api = s.sandbox.window.ShuffleCardModal;
            api.close();
            await settle(5);
            check('modal is closed', api.isCardModalVisible() === false);
            const getsBefore = s.apiLog.filter(x => x.method === 'GET').length;
            const result = api.onRegionSwapped();
            await settle(20);
            const getsAfter = s.apiLog.filter(x => x.method === 'GET').length;
            check('onRegionSwapped() returns false (not visible)', result === false);
            check('no fetch issued while the modal is closed', getsAfter === getsBefore, 'GETs=' + getsAfter);
        }

        console.log('\n-----------------------------------');
        console.log(failures === 0 ? checks + ' PASS, 0 failures' : failures + ' FAILURES');
        process.exit(failures === 0 ? 0 : 1);
    } catch (err) {
        console.error('Test harness error:', err);
        process.exit(2);
    }
})();
