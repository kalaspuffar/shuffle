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
    const shuffleObj = {
        api: function (url, options) {
            options = options || {};
            apiLog.push({ url: String(url), method: options.method || 'GET', body: options.body || null });
            if (queueIndex < queue.length) {
                const r = queue[queueIndex++];
                return Promise.resolve(r);
            }
            // Default: a successful empty-card (status 200, no card).
            return Promise.resolve(defaultData);
        },
        showFlash() {},
        getCsrfToken: () => 'test',
    };

    // Track clearTimeout/setTimeout calls so §7 (stale-timer cleanup) can
    // assert that the module actually un-scheduled the pending save.
    const timeouts = [];
    const clearLog = [];
    const sandbox = {
        document: documentObj,
        window: { location: { search: '' }, setTimeout, clearTimeout },
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
    sandbox.window.ShowFlash = (m, t) => shuffleObj.showFlash && shuffleObj.showFlash(m, t);
    return { dom, sandbox, shuffleObj, apiLog, timeouts, clearLog, queue, domObj: documentObj };
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

        console.log('\n-----------------------------------');
        console.log(failures === 0 ? checks + ' PASS, 0 failures' : failures + ' FAILURES');
        process.exit(failures === 0 ? 0 : 1);
    } catch (err) {
        console.error('Test harness error:', err);
        process.exit(2);
    }
})();
