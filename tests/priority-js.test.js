/**
 * DOM shim for Node (no external deps). Just enough for priority.js:
 * createElement, addEventListener/Element, closest/querySelector(All),
 * classList, dataset, previousElementSibling/nextElementSibling, insertBefore,
 * appendChild, replaceWith, remove, cloneNode(true), textContent, innerHTML,
 * getBoundingClientRect, focus, disabled, setAttribute/getAttribute,
 * document-level event dispatch (with target).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class ClassList {
    constructor(el) { this.el = el; this.set = new Set(); }
    add(...n) { n.forEach(c => this.set.add(c)); }
    remove(...n) { n.forEach(c => this.set.delete(c)); }
    contains(c) { return this.set.has(c); }
    toString() { return [...this.set].join(' '); }
}

class Element {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.parentNode = null;
        this._attrs = Object.create(null);
        this._listeners = {};
        this.classList = new ClassList(this);
        this._dataset = Object.create(null);
        this._id = null;
        this._textContent = '';
        this.innerHTML = '';
        this.disabled = false;
        this._innerHTML = '';
    }
    get id() { return this._id; }
    set id(v) { this._id = v; }
    get className() { return [...this.classList.set].join(' '); }
    set className(v) { this.classList.set = new Set(String(v).split(/\s+/).filter(Boolean)); }
    get textContent() { return this._textContent; }
    set textContent(v) { this._textContent = String(v); }
    get dataset() { return this._dataset; }
    set dataset(v) { Object.assign(this._dataset, v); }
    setAttribute(k, v) {
        if (k === 'id') { this._id = v; return; }
        if (k === 'class') { this.classList.set = new Set(String(v).split(/\s+/).filter(Boolean)); return; }
        if (k.startsWith('data-')) {
            const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            this._dataset[camel] = String(v);
            return;
        }
        this._attrs[k] = String(v);
    }
    getAttribute(k) {
        if (k === 'id') return this._id || null;
        if (k === 'class') return this.className;
        if (k.startsWith('data-')) {
            const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            const v = this._dataset[camel];
            return (v === undefined || v === null) ? null : v;
        }
        return this._attrs[k] != null ? this._attrs[k] : null;
    }
    removeAttribute(k) {
        if (k === 'id') { this._id = null; return; }
        if (k.startsWith('data-')) {
            const camel = k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            delete this._dataset[camel];
            return;
        }
        delete this._attrs[k];
    }
    appendChild(c) {
        if (c.parentNode) c.parentNode.removeChild(c);
        c.parentNode = this;
        this.children.push(c);
        return c;
    }
    insertBefore(c, ref) {
        const i = this.children.indexOf(ref);
        if (c.parentNode) c.parentNode.removeChild(c);
        c.parentNode = this;
        if (i === -1) this.children.push(c); else this.children.splice(i, 0, c);
        return c;
    }
    removeChild(c) {
        const i = this.children.indexOf(c);
        if (i !== -1) this.children.splice(i, 1);
        c.parentNode = null;
        return c;
    }
    replaceWith(c) {
        if (!this.parentNode) return;
        const i = this.parentNode.children.indexOf(this);
        c.parentNode = this.parentNode;
        if (i === -1) this.parentNode.children.push(c);
        else this.parentNode.children.splice(i, 1, c);
        this.parentNode = null;
    }
    remove() {
        if (this.parentNode) this.parentNode.removeChild(this);
    }
    get previousElementSibling() {
        if (!this.parentNode) return null;
        const i = this.parentNode.children.indexOf(this);
        return i > 0 ? this.parentNode.children[i - 1] : null;
    }
    get nextElementSibling() {
        if (!this.parentNode) return null;
        const i = this.parentNode.children.indexOf(this);
        return i < this.parentNode.children.length - 1 ? this.parentNode.children[i + 1] : null;
    }
    get firstElementChild() { return this.children[0] || null; }
    get lastElementChild() { return this.children[this.children.length - 1] || null; }
    focus() {}
    getBoundingClientRect() { return { top: 0, left: 0, height: 40, width: 300 }; }

    addEventListener(t, fn) { (this._listeners[t] = this._listeners[t] || []).push(fn); }
    removeEventListener(t, fn) {
        const l = this._listeners[t];
        if (!l) return;
        const i = l.indexOf(fn);
        if (i !== -1) l.splice(i, 1);
    }
    dispatch(type, event) {
        (this._listeners[type] || []).slice().forEach(fn => fn.call(this, event));
        return true;
    }

    _matchSimple(sel) {
        // .class | #id | tag | [attr] | [attr="v"] | tag.cls (keep simple)
        sel = sel.trim();
        const clsM = sel.match(/^\.([-a-z_][-\w]*)$/i);
        if (clsM) return this.classList.contains(clsM[1]);
        const idM = sel.match(/^#([\w-]+)$/);
        if (idM) return this._id === idM[1];
        const attrM = sel.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
        if (attrM) {
            const k = attrM[1];
            const want = attrM[2];
            const v = this.getAttribute(k);
            if (want === undefined) return v !== null;
            return v === want;
        }
        const tagM = sel.match(/^([a-z]+)$/i);
        if (tagM) return this.tagName === tagM[1].toUpperCase();
        // compound: e.g. li[data-tier], tag.class#id[attr]
        if (sel.includes('.') || sel.includes('[') || sel.includes('#')) {
            // tag + classes + ids + attribute groups, in any order
            const tag = sel.match(/^[a-zA-Z][\w-]*/);
            const classes = [];
            const ids = [];
            const attrs = [];
            let rest = sel;
            if (tag) rest = rest.slice(tag[0].length);
            rest = rest.replace(/\.([-\w]+)/g, (_, v) => { classes.push(v); return ''; })
                       .replace(/#([\w-]+)/g, (_, v) => { ids.push(v); return ''; })
                       .replace(/\[([\w-]+)(?:="([^"]*)")?\]/g, (_, k, v) => { attrs.push([k, v]); return ''; });
            if (tag && this.tagName !== tag[0].toUpperCase()) return false;
            for (const c of classes) if (!this.classList.contains(c)) return false;
            for (const id of ids) if (this._id !== id) return false;
            for (const [k, v] of attrs) {
                const have = this.getAttribute(k);
                if (v === undefined) { if (have === null) return false; }
                else if (have !== v) return false;
            }
            return true;
        }
        return false;
    }

    closest(sel) {
        // supports "sel1, sel2" and "sel1 sel2" descendant chain.
        if (!sel) return null;
        const sels = String(sel).split(',').map(s => s.trim()).filter(Boolean);
        for (let n = this; n; n = n.parentNode) {
            for (const s of sels) {
                if (this._matchSelChain(n, s)) return n;
            }
        }
        return null;
    }
    _matchSelChain(node, sel) {
        // supports space-separated descendant chain
        const parts = String(sel).trim().split(/\s+/);
        return parts.every((p, i, arr) => {
            if (i === arr.length - 1) return node._matchSimple(p);
            // intermediate part: node must match p (any ancestor check delegated to caller)
            return node._matchSimple(p);
        });
    }
    // Note: closest() above doesn't fully resolve descendant chains. To keep
    // this shim honest with the subset of selectors priority.js uses
    // (simple class/id/attr selectors, plus "li[data-tier]"), special-case
    // that form here.
    matchesSelector(sel) {
        sel = String(sel).trim();
        if (sel === 'li[data-tier]') {
            return this.tagName === 'LI' && (this.getAttribute('data-tier') !== null || (this._dataset && this._dataset.tier !== undefined));
        }
        if (sel === 'li') return this.tagName === 'LI';
        return this._matchSimple(sel);
    }

    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
    querySelectorAll(sel) {
        const out = [];
        (function walk(n) {
            for (const c of n.children || []) {
                if (c._matchSimple(sel)) out.push(c);
                walk(c);
            }
        })(this);
        return out;
    }

    cloneNode(deep) {
        const n = new Element(this.tagName);
        n._id = this._id;
        n._attrs = Object.assign({}, this._attrs);
        n._dataset = Object.assign({}, this._dataset);
        n.classList.set = new Set(this.classList.set);
        n._textContent = this._textContent;
        n.innerHTML = this.innerHTML;
        if (deep) this.children.forEach(c => n.appendChild(c.cloneNode(true)));
        return n;
    }
}

function makeDoc() {
    const body = new Element('body');
    return function document() { return body; };
}

function buildDom() {
    // priority-script (the config element)
    const script = new Element('script');
    script.id = 'priority-script';
    script.setAttribute('data-lang', JSON.stringify({
        added: 'A', removed: 'R', moved: 'M', error_failed: 'EF',
        'action_remove': 'Remove from list', 'action_prioritize': 'Prioritize',
        'prioritized_empty': 'Nothing prioritized yet.',
    }));

    // all-empty notice
    const allEmpty = new Element('p');
    allEmpty.setAttribute('class', 'priority-all-empty');

    // inbox section
    const inboxSection = new Element('section');
    inboxSection.id = 'priority-inbox-section';
    const inboxCount = new Element('span');
    inboxCount.setAttribute('data-count-section', 'inbox');
    inboxCount.textContent = '1';
    inboxSection.appendChild(inboxCount);

    const inboxList = new Element('ul');
    inboxList.setAttribute('data-priority-section', 'inbox');

    const tier1 = new Element('li');
    tier1.setAttribute('class', 'priority-tier');
    tier1.setAttribute('data-tier', '1');
    const tier1H = new Element('h3');
    tier1H.textContent = 'In Progress';
    tier1.appendChild(tier1H);
    const tier1Ul = new Element('ul');
    tier1.appendChild(tier1Ul);

    const inboxItem = new Element('li');
    inboxItem.setAttribute('class', 'priority-item priority-item--inbox');
    inboxItem.setAttribute('data-card-id', '101');
    const inboxLink = new Element('a');
    inboxLink.setAttribute('class', 'priority-item-link');
    inboxLink.textContent = 'Fix the bug';
    inboxItem.appendChild(inboxLink);
    const inboxBtn = new Element('button');
    inboxBtn.setAttribute('data-priority-action', 'prioritize');
    inboxBtn.setAttribute('data-card-id', '101');
    inboxBtn.innerHTML = '<svg></svg>';
    inboxItem.appendChild(inboxBtn);
    tier1Ul.appendChild(inboxItem);
    inboxList.appendChild(tier1);
    inboxSection.appendChild(inboxList);

    // prioritized section (empty at start)
    const prioSection = new Element('section');
    prioSection.id = 'priority-prioritized-section';
    const prioCount = new Element('span');
    prioCount.setAttribute('data-count-section', 'prioritized');
    prioCount.textContent = '0';
    prioSection.appendChild(prioCount);
    const prioEmpty = new Element('p');
    prioEmpty.setAttribute('class', 'priority-empty');
    prioEmpty.textContent = 'Nothing prioritized yet.';
    prioSection.appendChild(prioEmpty);

    const body = new Element('body');
    body.appendChild(allEmpty);
    body.appendChild(inboxSection);
    body.appendChild(prioSection);
    body.appendChild(script);

    return { body, script, inboxItem, inboxBtn, inboxList, inboxSection, inboxCount, prioCount, prioSection, prioEmpty, allEmpty, tier1Ul };
}

// ---- wire up a virtual document -------------------------------------------
function makeSandbox() {
    const dom = buildDom();
    const root = dom.body;
    const listeners = {};
    const documentObj = {
        body: root,
        createElement: (t) => new Element(t),
        getElementById: (id) => {
            const out = [];
            (function walk(n) {
                for (const c of n.children || []) {
                    if (c._id === id) out.push(c);
                    walk(c);
                }
            })(root);
            return out[0] || null;
        },
        querySelector: (sel) => {
            const out = [];
            (function walk(n) {
                for (const c of n.children || []) {
                    if (c._matchSimple(sel)) out.push(c);
                    walk(c);
                }
            })(root);
            return out[0] || null;
        },
        querySelectorAll: (sel) => {
            const out = [];
            (function walk(n) {
                for (const c of n.children || []) {
                    if (c._matchSimple(sel)) out.push(c);
                    walk(c);
                }
            })(root);
            return out;
        },
        addEventListener: (t, fn) => { (listeners[t] = listeners[t] || []).push(fn); },
        dispatch: (t, ev) => { (listeners[t] || []).slice().forEach(fn => fn.call(documentObj, ev)); }
    };

    const apiLog = [];
    const shuffleObj = {
        api: (url, options) => {
            apiLog.push({ url, options });
            return Promise.resolve({ status: 200, data: {} });
        },
        showFlash: (m, t) => {}
    };

    const sandbox = {
        document: documentObj,
        window: {},
        console, Promise, JSON,
        parseInt: (v, r) => parseInt(v, r),
        encodeURIComponent: (v) => encodeURIComponent(v),
    };
    vm.createContext(sandbox);
    // Simulate: at priority.js parse time, app.js / Shuffle has NOT loaded.
    sandbox.window.Shuffle = undefined;
    return { dom, sandbox, documentObj, apiLog, shuffleObj, listeners };
}

const SRC = fs.readFileSync(path.join(__dirname, '..', 'www', 'js', 'priority.js'), 'utf8');

let failures = 0;
function check(name, ok, detail) {
    if (ok) console.log('  ok:   ' + name);
    else { failures++; console.log('  FAIL: ' + name + (detail ? ' — ' + String(detail).slice(0, 200) : '')); }
}
function tick() { return new Promise((r) => setImmediate(r)); }
async function settle(runs = 3) { for (let i = 0; i < runs; i++) await tick(); }

(async () => {

console.log('[1] parse-time + inbox counter (nested under li.tier > ul)');
{
    const s = makeSandbox();
    vm.runInContext(SRC, s.sandbox, { filename: 'priority.js' });
    s.sandbox.window.Shuffle = s.shuffleObj;
    s.sandbox.Shuffle = s.shuffleObj;
    check('no parse-time exception', true);
    const count = s.dom.inboxCount.textContent;
    check('inbox counter = 1 even with nested ul', count === '1', 'got ' + count);
}

console.log('[2] prioritize: POST fired; card moves into the live prioritized list; icon switches to ×; counters update');
{
    const s = makeSandbox();
    vm.runInContext(SRC, s.sandbox, { filename: 'priority.js' });
    s.sandbox.window.Shuffle = s.shuffleObj;
    s.sandbox.Shuffle = s.shuffleObj;

    s.documentObj.dispatch('click', { target: s.dom.inboxBtn, preventDefault() {} });
    await settle();

    const post = s.apiLog.find(x => x.url === '/v1/priority/inbox/101' && x.options.method === 'POST');
    check('POST /v1/priority/inbox/101 fired', !!post, JSON.stringify(s.apiLog.map(x => [x.url, x.options.method])));

    const ul = s.documentObj.getElementById('priority-reorder-list');
    check('prioritized <ul> created on first add', !!ul, 'not found');
    if (ul) {
        const items = ul.querySelectorAll('.priority-item');
        check('moved item exists in prioritized list (no reload)', items.length === 1, 'count=' + items.length);
        if (items.length) {
            const item = items[0];
            check('moved item is draggable', item.getAttribute('draggable') === 'true', item.getAttribute('draggable'));
            const btn = item.querySelector('[data-priority-action]');
            check('moved button action = remove (not prioritize)', btn && btn.getAttribute('data-priority-action') === 'remove', btn && btn.getAttribute('data-priority-action'));
            check('moved button icon = × svg', btn && /l8 8M12 4l-8 8/.test(btn.innerHTML), btn && btn.innerHTML.slice(0, 80));
            check('moved fromTier recorded', item._dataset.fromTier === '1', JSON.stringify(item._dataset));
        }
    }
    check('inbox counter shows 0 now', s.dom.inboxCount.textContent === '0', s.dom.inboxCount.textContent);
    check('prioritized counter shows 1', s.dom.prioCount.textContent === '1', s.dom.prioCount.textContent);
    check('all-empty notice removed', !s.dom.allEmpty.parentNode);

    // ---------- THE BUG: can we reorder IMMEDIATELY after first add? ---
    // Add one more item via the same button-flow so we have 2 items to swap.
    const inboxBtn2 = new Element('button');
    inboxBtn2.setAttribute('data-priority-action', 'prioritize');
    inboxBtn2.setAttribute('data-card-id', '202');
    const inboxItem2 = new Element('li');
    inboxItem2.setAttribute('class', 'priority-item priority-item--inbox');
    inboxItem2.setAttribute('data-card-id', '202');
    inboxItem2.appendChild(inboxBtn2);
    s.dom.tier1Ul.appendChild(inboxItem2);

    s.documentObj.dispatch('click', { target: inboxBtn2, preventDefault() {} });
    await settle();

    const ul2 = s.documentObj.getElementById('priority-reorder-list');
    check('still one prioritized <ul> after second add (no duplicate)', !!ul2 && s.dom.prioSection.querySelectorAll('ul').length === 1, JSON.stringify(s.dom.prioSection.querySelectorAll('ul').map(u => u._id)));
    const items2 = ul2.querySelectorAll('.priority-item');
    check('two items in prioritized', items2.length === 2, 'count=' + items2.length);
    if (items2.length === 2) {
        const [a, b] = [items2[0], items2[1]];
        // drag b before a (clientY near top of a triggers before-half)
        s.documentObj.dispatch('dragstart', { target: b, dataTransfer: { setData() {}, effectAllowed: '' }, preventDefault() {} });
        s.documentObj.dispatch('dragover',  { target: a, clientY: 1, preventDefault() {}, dataTransfer: {} });
        check('dragover re-ordered b before a', ul2.querySelectorAll('.priority-item')[0] === b, JSON.stringify(ul2.querySelectorAll('.priority-item').map(x => x._dataset.cardId)));
        s.documentObj.dispatch('dragend', {});
        await settle();
        const put = s.apiLog.find(x => x.url === '/v1/priority/position' && x.options.method === 'PUT');
        check('PUT /v1/priority/position fired without a reload (delegated handlers)', !!put, 'no PUT');
        check('PUT body: card 202, after null (moved to top)',
            put && put.options.body && put.options.body.card_id === 202 && put.options.body.after_card_id === null,
            put && JSON.stringify(put.options.body));
    }
}

console.log('[3] remove: DELETE fires; card re-appears in the inbox (same tier) with + icon; empty-state restored');
{
    const s = makeSandbox();
    vm.runInContext(SRC, s.sandbox, { filename: 'priority.js' });
    s.sandbox.window.Shuffle = s.shuffleObj;
    s.sandbox.Shuffle = s.shuffleObj;

    // prioritize card 101
    s.documentObj.dispatch('click', { target: s.dom.inboxBtn, preventDefault() {} });
    await settle();

    const ul = s.documentObj.getElementById('priority-reorder-list');
    const item = ul.querySelectorAll('.priority-item')[0];
    const btn = item.querySelector('[data-priority-action]');

    s.documentObj.dispatch('click', { target: btn, preventDefault() {} });
    await settle();

    const del = s.apiLog.find(x => x.url === '/v1/priority/inbox/101' && x.options.method === 'DELETE');
    check('DELETE /v1/priority/inbox/101 fired', !!del, JSON.stringify(s.apiLog.map(x => [x.url, x.options.method])));

    // card should reappear in the inbox tier-1 bucket
    const inboxItems = s.dom.inboxList.querySelectorAll('.priority-item');
    check('card 101 back in the inbox (no reload)', inboxItems.length === 1 && inboxItems[0]._dataset.cardId === '101',
        'count=' + inboxItems.length + ' ' + JSON.stringify(inboxItems.map(x => x._dataset.cardId)));
    if (inboxItems.length) {
        const btn2 = inboxItems[0].querySelector('[data-priority-action]');
        check('reinstated button action = prioritize', btn2 && btn2.getAttribute('data-priority-action') === 'prioritize', btn2 && btn2.getAttribute('data-priority-action'));
        check('reinstated button icon = + svg', btn2 && /M8 2v12M2 8h12/.test(btn2.innerHTML), btn2 && btn2.innerHTML.slice(0, 80));
        // restored into the same tier wrapper
        const tier = inboxItems[0].parentNode;
        check('reinstated item is back under a <ul> tier wrapper',
            tier && tier.parentNode && tier.parentNode.tagName === 'LI' && tier.parentNode.getAttribute('data-tier') === '1',
            'parent=' + (tier && tier.parentNode && tier.parentNode.tagName));
    }
    check('prioritized section back to empty-state <p>',
        !s.documentObj.getElementById('priority-reorder-list') && !!s.dom.prioSection.querySelector('.priority-empty'));
    check('inbox counter back to 1', s.dom.inboxCount.textContent === '1', s.dom.inboxCount.textContent);
}

console.log('[3b] two-tier inbox: each removed card returns to ITS own bucket');
{
    const s = makeSandbox();
    vm.runInContext(SRC, s.sandbox, { filename: 'priority.js' });
    s.sandbox.window.Shuffle = s.shuffleObj;
    s.sandbox.Shuffle = s.shuffleObj;

    // build a second tier (Inbox lane) with card 202
    const tier2 = new Element('li');
    tier2.setAttribute('class', 'priority-tier');
    tier2.setAttribute('data-tier', '2');
    const tier2Ul = new Element('ul');
    tier2.appendChild(tier2Ul);
    const item2 = new Element('li');
    item2.setAttribute('class', 'priority-item priority-item--inbox');
    item2.setAttribute('data-card-id', '202');
    const btn2 = new Element('button');
    btn2.setAttribute('data-priority-action', 'prioritize');
    btn2.setAttribute('data-card-id', '202');
    item2.appendChild(btn2);
    tier2Ul.appendChild(item2);
    s.dom.inboxList.appendChild(tier2);

    // pull both into prioritized
    s.documentObj.dispatch('click', { target: s.dom.inboxBtn, preventDefault() {} });
    await settle();
    s.documentObj.dispatch('click', { target: btn2, preventDefault() {} });
    await settle();
    const ul = s.documentObj.getElementById('priority-reorder-list');
    check('both items prioritized', ul.querySelectorAll('.priority-item').length === 2, 'count=' + ul.querySelectorAll('.priority-item').length);
    const moved101 = ul.querySelectorAll('[data-card-id="101"]')[0];
    const moved202 = ul.querySelectorAll('[data-card-id="202"]')[0];
    check('tier marker per item: 101→1, 202→2',
        moved101._dataset.fromTier === '1' && moved202._dataset.fromTier === '2',
        JSON.stringify([moved101._dataset.fromTier, moved202._dataset.fromTier]));

    // remove each → must land in the matching tier bucket only
    const b1 = moved101.querySelector('[data-priority-action]');
    s.documentObj.dispatch('click', { target: b1, preventDefault() {} });
    await settle();
    const m202 = ul.querySelectorAll('[data-card-id="202"]')[0];
    const bm202 = m202.querySelector('[data-priority-action]');
    s.documentObj.dispatch('click', { target: bm202, preventDefault() {} });
    await settle();

    const inTier1 = s.dom.tier1Ul.querySelectorAll('.priority-item');
    const inTier2 = tier2Ul.querySelectorAll('.priority-item');
    check('card 101 back in tier-1 bucket, absent from tier-2',
        inTier1.length === 1 && inTier1[0]._dataset.cardId === '101' && inTier2.every(x => x._dataset.cardId !== '101'),
        't1=' + JSON.stringify(inTier1.map(x => x._dataset.cardId)) + ' t2=' + JSON.stringify(inTier2.map(x => x._dataset.cardId)));
    check('card 202 back in tier-2 bucket, absent from tier-1',
        inTier2.length === 1 && inTier2[0]._dataset.cardId === '202' && inTier1.every(x => x._dataset.cardId !== '202'),
        't1=' + JSON.stringify(inTier1.map(x => x._dataset.cardId)) + ' t2=' + JSON.stringify(inTier2.map(x => x._dataset.cardId)));
}

console.log('[4] keyboard: Alt+ArrowDown moves item + commits PUT (delegated, no list-bound handler)');
{
    const s = makeSandbox();
    vm.runInContext(SRC, s.sandbox, { filename: 'priority.js' });
    s.sandbox.window.Shuffle = s.shuffleObj;
    s.sandbox.Shuffle = s.shuffleObj;

    s.documentObj.dispatch('click', { target: s.dom.inboxBtn, preventDefault() {} });
    await settle();

    const ul = s.documentObj.getElementById('priority-reorder-list');
    const first = ul.querySelectorAll('.priority-item')[0];
    const second = new Element('li');
    second.setAttribute('class', 'priority-item priority-item--reorderable');
    second.setAttribute('data-card-id', '303');
    ul.appendChild(second);

    s.documentObj.dispatch('keydown', { target: first, altKey: true, key: 'ArrowDown', preventDefault() {} });
    await settle();

    check('Alt+ArrowDown: order swapped', ul.querySelectorAll('.priority-item')[0]._dataset.cardId === '303',
        JSON.stringify(ul.querySelectorAll('.priority-item').map(x => x._dataset.cardId)));
    const put = s.apiLog.filter(x => x.url === '/v1/priority/position').pop();
    check('PUT body: card 101 after 303', put && put.options.body && put.options.body.card_id === 101 && put.options.body.after_card_id === 303,
        put && JSON.stringify(put.options.body));
}

console.log('[5] guard: Shuffle still undefined at action time → clean rejection (no throw)');
{
    const s = makeSandbox();
    vm.runInContext(SRC, s.sandbox, { filename: 'priority.js' });
    // do NOT define window.Shuffle — simulate app.js failing to load.
    s.sandbox.window.Shuffle = undefined;
    s.sandbox.Shuffle = undefined;
    let threw = null;
    try { s.documentObj.dispatch('click', { target: s.dom.inboxBtn, preventDefault() {} }); }
    catch (e) { threw = e; }
    await settle(5);
    check('no exception thrown', threw === null, threw && (threw.constructor.name + ': ' + threw.message));
}

console.log('-----------------------------------');
console.log(failures === 0 ? 'ALL PASS' : failures + ' FAILURES');
process.exit(failures === 0 ? 0 : 1);
})();
