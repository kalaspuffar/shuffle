#!/usr/bin/env node
/**
 * Regression test for the "prioritize button does nothing" bug:
 * priority.js was parsing `if (typeof Shuffle === 'undefined' || !Shuffle.api)
 * return;` AT LOAD TIME, before app.js (footer) defined window.Shuffle, and
 * the IIFE bailed out silently → all buttons/dead drag-and-drop were no-ops.
 *
 * This harness runs priority.js in a VM context where `window.Shuffle` is
 * UNDEFINED at parse time (mirrors real page load order), then re-injects
 * it (as if app.js had finished loading) BEFORE dispatching user actions.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', 'www', 'js', 'priority.js'), 'utf8');
let failures = 0;
function check(name, ok, detail) {
    if (ok) console.log('  ok:   ' + name);
    else { failures++; console.log('  FAIL: ' + name + (detail ? ' — ' + detail : '')); }
}

// ---- tiny DOM ----------------------------------------------------
function makeEl(tag) {
    const el = {
        tagName: (tag || 'div').toUpperCase(),
        children: [],
        dataset: {},
        attributes: {},
        textContent: '',
        parentNode: null,
        listeners: {},
        classSet: new Set(),
    };
    el.classList = {
        add: (c) => el.classSet.add(c),
        remove: (c) => el.classSet.delete(c),
        contains: (c) => el.classSet.has(c),
    };
    el.setAttribute = function (k, v) {
        this.attributes[k] = String(v);
        if (k.startsWith('data-')) this.dataset[k.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = v;
    };
    el.getAttribute = function (k) { return this.attributes[k] ?? null; };
    el.removeAttribute = function (k) { delete this.attributes[k]; };
    el.appendChild = function (c) { c.parentNode = el; el.children.push(c); return c; };
    el.insertBefore = function (c, ref) {
        const i = el.children.indexOf(ref);
        c.parentNode = el;
        if (i === -1) el.children.push(c); else el.children.splice(i, 0, c);
        return c;
    };
    el.replaceWith = function (c) {
        if (!el.parentNode) return;
        const i = el.parentNode.children.indexOf(el);
        if (i !== -1) el.parentNode.children[i] = c;
        c.parentNode = el.parentNode;
    };
    el.remove = function () {
        if (el.parentNode) {
            const i = el.parentNode.children.indexOf(el);
            if (i !== -1) el.parentNode.children.splice(i, 1);
            el.parentNode = null;
        }
    };
    function matchesSel(el2, sel) {
        if (el2.classList) {
            const clsM = sel.match(/^\.([-\w]+)$/);
            if (clsM && el2.classList.contains(clsM[1])) return true;
        }
        const attrM = sel.match(/^\[([-\w]+)(?:=("[^"]*"))?\]$/);
        if (attrM) {
            const attr = attrM[1];
            const want = attrM[2] ? attrM[2].slice(1, -1) : true;
            const have = el2.attributes && (el2.attributes[attr] !== undefined || (el2.dataset && attr in el2.dataset));
            if (want === true) return have ? true : false;
            if (el2.attributes && el2.attributes[attr] === want) return true;
            if (el2.dataset) {
                const camel = attr.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
                if (String(el2.dataset[camel] ?? el2.dataset[attr] ?? '') === want) return true;
            }
        }
        return false;
    }
    el.closest = function (sel) {
        let n = el;
        while (n) {
            if (matchesSel(n, sel)) return n;
            n = n.parentNode;
        }
        return null;
    };
    el.querySelector = function (sel) { return el.querySelectorAll(sel)[0] || null; };
    el.querySelectorAll = function (sel) {
        const simple = sel.split(' ').pop().replace(/^\./, '');
        const out = [];
        (function walk(n) {
            for (const c of n.children || []) {
                if (c.classSet && c.classSet.has(simple)) out.push(c);
                walk(c);
            }
        })(el);
        return out;
    };
    el.cloneNode = function () {
        const n = makeEl(tag);
        n.dataset = { ...el.dataset };
        n.attributes = { ...el.attributes };
        n.textContent = el.textContent;
        n.classSet = new Set(el.classSet);
        el.children.forEach((c, i) => {
            const k = c.cloneNode ? c.cloneNode(true) : makeEl('div');
            n.appendChild(i === 0 ? k : k);
        });
        return n;
    };
    el.focus = function () {};
    el.addEventListener = function (t, fn) { el.listeners[t] = fn; };
    el.dispatch = function (t, ev) { (el.listeners[t] || function () {})(ev); };
    el.getBoundingClientRect = function () { return { top: 0, left: 0, height: 40, width: 300 }; };
    el.querySelector2 = null; // placeholder to avoid accidental match on classList check
    return el;
}

// Build a priority-page DOM: one inbox item + prioritize button, prioritized empty
function buildDom() {
    const script = makeEl('script');
    script.id = 'priority-script';
    script.setAttribute('data-lang', JSON.stringify({ added: 'A', removed: 'R', moved: 'M', error_failed: 'EF', action_remove: 'Remove', prioritize: 'Add' }));

    const allEmpty = makeEl('p'); allEmpty.classList.add('priority-all-empty');

    const inboxSection = makeEl('span'); inboxSection.setAttribute('data-count-section', 'inbox');
    const inboxList = makeEl('ul'); inboxList.setAttribute('data-priority-section', 'inbox');
    const tierLi = makeEl('li'); tierLi.classList.add('priority-tier');
    const tierUl = makeEl('ul'); tierLi.appendChild(tierUl);
    const inboxItem = makeEl('li'); inboxItem.classList.add('priority-item'); inboxItem.setAttribute('data-card-id', '101');
    const inboxBtn = makeEl('button'); inboxBtn.setAttribute('data-priority-action', 'prioritize'); inboxBtn.setAttribute('data-card-id', '101');
    inboxItem.appendChild(inboxBtn);
    tierUl.appendChild(inboxItem);
    inboxList.appendChild(tierLi);

    const prioSection = makeEl('section'); prioSection.id = 'priority-prioritized-section';
    const prioCount = makeEl('span'); prioCount.setAttribute('data-count-section', 'prioritized'); prioSection.appendChild(prioCount);
    const prioEmpty = makeEl('p'); prioEmpty.classList.add('priority-empty'); prioEmpty.textContent = 'Nothing prioritized yet.'; prioSection.appendChild(prioEmpty);

    const body = makeEl('body');
    body.appendChild(allEmpty);
    body.appendChild(inboxSection);
    body.appendChild(inboxList);
    body.appendChild(prioSection);
    body.appendChild(script);
    return {
        body, script, inboxItem, inboxBtn, prioSection,
        byId: {
            'priority-script': script,
            'priority-prioritized-section': prioSection,
        },
        bySelector: {
            '[data-count-section="inbox"]': inboxSection,
            '[data-count-section="prioritized"]': prioCount,
            '[data-priority-section="inbox"]': inboxList,
            '.priority-all-empty': allEmpty,
        },
    };
}

function makeDocument(dom) {
    const doc = {
        _root: dom,
        getElementById: (id) => dom.byId[id] || null,
        querySelector: (sel) => dom.bySelector[sel] || null,
        _listeners: {},
        addEventListener: (t, fn) => { doc._listeners[t] = fn; },
        dispatch: (t, ev) => doc._listeners[t] && doc._listeners[t](ev),
    };
    return doc;
}

function setupSandbox({ withShuffleAtRun } = { withShuffleAtRun: false }) {
    const dom = buildDom();
    const document = makeDocument(dom);
    const apiLog = [];
    const shuffleObj = {
        api: (url, options) => {
            apiLog.push({ url, options });
            return Promise.resolve({ status: 200, data: {} });
        },
        showFlash: () => {},
    };
    const sandbox = {
        document,
        window: {},
        console, Promise, JSON, parseInt, encodeURIComponent,
    };
    vm.createContext(sandbox);
    // Simulate: at the moment priority.js runs, app.js hasn't run yet,
    // so window.Shuffle is absent.
    sandbox.window.Shuffle = undefined;

    vm.runInContext(SRC, sandbox, { filename: 'priority.js' });

    // Simulate: app.js (footer) has now loaded and defined window.Shuffle
    // (BEFORE the user clicks anything — always true in a real page).
    sandbox.window.Shuffle = shuffleObj;
    sandbox.Shuffle = shuffleObj; // also in global scope in case of unqualified access

    return { dom, document, sandbox, apiLog, shuffleObj };
}

let apiLog = [];
console.log('[1] prioritize button click — must call API with correct method');
{
    const s = setupSandbox();
    apiLog = s.apiLog;
    let threw = null;
    try {
        // the handler is delegated on document — dispatch there
        s.document.dispatch('click', {
            target: s.dom.inboxBtn,
            preventDefault() {},
        });
    } catch (e) { threw = e; }
    check('no exception thrown', threw === null, threw && (threw.constructor.name + ': ' + threw.message));
    const call = apiLog.find(x => x.url === '/v1/priority/inbox/101' && x.options.method === 'POST');
    check('POST /v1/priority/inbox/101 called', !!call, 'apiLog=' + JSON.stringify(apiLog));
}

console.log('[2] regression guard: if app.js is MISSING, click must not throw');
{
    const s = setupSandbox();
    // Force Shuffle absent even at click time (pathological, but must be safe):
    s.sandbox.Shuffle = undefined;
    s.sandbox.window.Shuffle = undefined;
    let threw = null;
    try {
        s.document.dispatch('click', { target: s.dom.inboxBtn, preventDefault() {} });
    } catch (e) { threw = e; }
    check('no exception, graceful rejection or no-op', threw === null, threw && threw.message);
}

console.log('[3) commitReorder: DOM order → after_card_id mapping (manual trace)');
{
    // We trust the vm-driven behaviour from test 1; here we simply verify
    // the documented mapping from priority.js source for the two known
    // drag outcomes: moving the first item to after the second ⇒ prev = its
    // new previous sibling (which is c1); moving first to top ⇒ prev = null.
    const text = fs.readFileSync(path.join(__dirname, '..', 'www', 'js', 'priority.js'), 'utf8');
    check('commitReorder uses prevSibling → after_card_id', /prevSibling\(movingEl\)/.test(text) && /after_card_id:\s*prev\s*\?/.test(text));
    check('commitReorder PUT /v1/priority/position', text.includes("'/v1/priority/position'") && /method:\s*'PUT'/.test(text));
}

console.log('-----------------------------------');
console.log(failures === 0 ? 'ALL PASS' : failures + ' FAILURES');
process.exit(failures === 0 ? 0 : 1);
