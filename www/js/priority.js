/**
 * Shuffle — Personal Priority List (PRIO-01..11)
 *
 * Progressive enhancement on top of the server-rendered /priority.php:
 *   - "Prioritize" (inbox → prioritized)
 *   - "Remove"     (prioritized → inbox)
 *   - drag & drop reordering within the prioritized section
 *   - keyboard reordering (Alt+↑ / Alt+↓ / Alt+Home / Alt+End)
 *
 * LOAD ORDER (important): this script is parsed BEFORE app.js (app.js is
 * loaded by the shared footer, after the page body). window.Shuffle
 * therefore does not exist at parse time and must be resolved lazily at
 * action time — the same pattern board.js / card.js use.
 *
 * DOM SYNC: the prioritized <ul> is created/destroyed together with the
 * first/last item (swapped with the empty-state <p>). List elements are
 * never used as listeners — every handler is delegated on document and
 * resolves #priority-reorder-list at event time, so reordering works from
 * the very first added item and across empty ⇄ filled transitions.
 *
 * Every section button always renders the CORRECT symbol: the + / ×
 * icons are swapped together with data-priority-action on add and on
 * return, and the inbox card re-appears into the tier bucket it came from
 * (remembered on the moved node) when removed — no reload needed.
 */

(function () {
    'use strict';

    var script = document.getElementById('priority-script');
    if (!script) {
        return; // not a priority page
    }

    var L = {};
    try { L = JSON.parse(script.dataset.lang || '{}'); } catch (e) { L = {}; }
    var MSG = {
        added:            L.added             || 'Moved to prioritized.',
        removed:          L.removed           || 'Moved back to inbox.',
        moved:            L.moved             || 'Reordered.',
        errorFailed:      L.error_failed      || "Couldn't update your priority list. Please try again.",
        remove:           L.action_remove     || 'Remove from list',
        prioritize:       L.action_prioritize || 'Prioritize',
        prioritizedEmpty: L.prioritized_empty || 'Nothing prioritized yet.'
    };

    // Same SVGs the PHP template renders (12x12, currentColor).
    var SVG_ADD    = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    var SVG_REMOVE = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

    // ------------------------------------------------------------------
    // Lazy helpers (Shuffle resolves when the user acts, not at parse time)
    // ------------------------------------------------------------------
    function api(url, options) {
        // Reference the global at call time only (no early binding).
        var g = (typeof window !== 'undefined' && window.Shuffle) || (typeof Shuffle !== 'undefined' ? Shuffle : null);
        if (!g || typeof g.api !== 'function') {
            return Promise.reject({ message: MSG.errorFailed });
        }
        return g.api(url, options);
    }

    function flash(message, type) {
        var g = (typeof window !== 'undefined' && window.Shuffle) || (typeof Shuffle !== 'undefined' ? Shuffle : null);
        if (g && typeof g.showFlash === 'function') {
            g.showFlash(message, type);
        }
    }

    // ------------------------------------------------------------------
    // Section anchors — always re-resolved (the prioritized <ul> can be
    // swapped with the empty-state <p> at any time)
    // ------------------------------------------------------------------
    function inboxSection()         { return document.getElementById('priority-inbox-section'); }
    function prioritizedSection()   { return document.getElementById('priority-prioritized-section'); }
    function prioritizedList()      { return document.getElementById('priority-reorder-list'); }
    function inboxList() {
        var s = inboxSection();
        if (!s) return null;
        var all = s.querySelectorAll('[data-priority-section="inbox"]');
        return all.length ? all[0] : null;
    }

    // Direct-child count among the items of a list (used for the header
    // counters). Works with any nesting because it filters by class.
    function countItems(listEl) {
        if (!listEl) return 0;
        return listEl.querySelectorAll('.priority-item').length;
    }

    function refreshCounts() {
        var inbox = inboxSection();
        if (inbox) {
            var all = inbox.querySelectorAll('[data-count-section="inbox"]');
            if (all.length) all[0].textContent = String(countItems(inboxList()));
        }
        var prio = prioritizedSection();
        if (prio) {
            var all2 = prio.querySelectorAll('[data-count-section="prioritized"]');
            if (all2.length) all2[0].textContent = String(countItems(prioritizedList()));
        }
    }

    // ------------------------------------------------------------------
    // Action button (+ / ×) — icon and action data are set together so the
    // visible symbol always matches the behaviour (fix: "cards still show
    // the plus sign after being prioritized").
    // ------------------------------------------------------------------
    function setAction(btn, action, itemEl) {
        if (!btn) return;
        btn.dataset.priorityAction = action;
        btn.innerHTML = (action === 'remove') ? SVG_REMOVE : SVG_ADD;
        var label = (action === 'remove') ? MSG.remove : MSG.prioritize;
        var link  = itemEl ? (itemEl.querySelectorAll ? itemEl.querySelectorAll('.priority-item-link').length ? itemEl.querySelectorAll('.priority-item-link')[0].textContent : null : null) : null;
        btn.setAttribute('aria-label', label + (link ? ' — ' + link : ''));
    }

    function busy(btn, state) {
        if (!btn) return;
        if (state) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        } else {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        }
    }

    // ------------------------------------------------------------------
    // Section switching: prioritized <ul> ⇄ empty-state <p>
    // ------------------------------------------------------------------
    function ensurePrioritizedList() {
        var ul = prioritizedList();
        if (ul) return ul;

        var section = prioritizedSection();
        if (!section) return null;
        var empties = section.querySelectorAll('.priority-empty');
        var empty = empties.length ? empties[0] : null;
        if (!empty) return null;

        ul = document.createElement('ul');
        ul.className = 'priority-list';
        ul.setAttribute('role', 'list');
        ul.setAttribute('data-priority-section', 'prioritized');
        ul.id = 'priority-reorder-list';
        empty.replaceWith ? empty.replaceWith(ul) : empty.parentNode.insertBefore(ul, empty);
        if (ul.parentNode !== empty.parentNode) empty.remove(); // fallback path
        return ul;
    }

    function emptyPrioritizedSection() {
        var section = prioritizedSection();
        var ul = prioritizedList();
        if (!section || !ul) return;
        var p = document.createElement('p');
        p.className = 'priority-empty';
        p.setAttribute('role', 'status');
        p.textContent = MSG.prioritizedEmpty;
        ul.replaceWith ? ul.replaceWith(p) : ul.parentNode.insertBefore(p, ul);
        if (p.parentNode !== ul.parentNode) ul.remove();
    }

    // ------------------------------------------------------------------
    // Prioritize / Remove (delegated on document — survives DOM swaps)
    // ------------------------------------------------------------------
    document.addEventListener('click', function (event) {
        var t = event.target;
        var btn = (t && t.closest) ? t.closest('[data-priority-action]') : null;
        if (!btn) return;

        var action = btn.dataset.priorityAction;
        var cardId = btn.dataset.cardId;
        var itemEl = btn.closest ? btn.closest('.priority-item') : null;
        if (!itemEl || !cardId) return;

        busy(btn, true);

        if (action === 'prioritize') {
            api('/v1/priority/inbox/' + encodeURIComponent(cardId), { method: 'POST' })
                .then(function (res) {
                    if (res.status !== 200 && res.status !== 204) {
                        throw { message: (res.data && res.data.error) || MSG.errorFailed };
                    }
                    onPrioritized(itemEl);
                    flash(MSG.added, 'success');
                })
                .catch(function (err) { flash((err && err.message) || MSG.errorFailed, 'error'); })
                .finally(function () { busy(btn, false); });
        } else if (action === 'remove') {
            api('/v1/priority/inbox/' + encodeURIComponent(cardId), { method: 'DELETE' })
                .then(function (res) {
                    if (res.status !== 200 && res.status !== 204) {
                        throw { message: (res.data && res.data.error) || MSG.errorFailed };
                    }
                    onRemoved(itemEl);
                    flash(MSG.removed, 'success');
                })
                .catch(function (err) { flash((err && err.message) || MSG.errorFailed, 'error'); })
                .finally(function () { busy(btn, false); });
        }
    });

    function onPrioritized(itemEl) {
        var ul = ensurePrioritizedList();
        if (!ul) { flash(MSG.errorFailed, 'error'); return; }

        var clone = itemEl.cloneNode(true);
        clone.classList.add('priority-item--reorderable');
        clone.classList.remove('priority-item--inbox');
        clone.setAttribute('draggable', 'true');

        // Remember the originating inbox tier so a later "return" re-slots
        // the card into the same bucket (In Progress / Inbox / Other).
        var tierWrap = itemEl.closest ? itemEl.closest('li[data-tier]') : null;
        if (tierWrap) {
            clone.dataset.fromTier = String(tierWrap.dataset.tier || tierWrap.getAttribute('data-tier') || '');
        }

        setAction(clone.querySelector ? clone.querySelector('[data-priority-action]') : null, 'remove', clone);

        ul.appendChild(clone);
        itemEl.remove ? itemEl.remove() : itemEl.parentNode.removeChild(itemEl);

        var all = document.querySelectorAll ? document.querySelectorAll('.priority-all-empty') : null;
        if (all && all.length) all[0].remove();

        refreshCounts();
    }

    function onRemoved(itemEl) {
        // Tier bucket to restore into: from the marker on the moving node
        // (preferred) or from the current tier wrapper (if still there).
        var tier = null;
        if (itemEl && itemEl.dataset && itemEl.dataset.fromTier) {
            tier = itemEl.dataset.fromTier;
        } else if (itemEl && itemEl.closest) {
            var tw = itemEl.closest('li[data-tier]');
            if (tw) tier = String(tw.dataset.tier || tw.getAttribute('data-tier') || '');
        }
        if (tier === '') tier = null;

        var restoredNode = itemEl.cloneNode(true); // clone before detaching
        itemEl.remove ? itemEl.remove() : itemEl.parentNode.removeChild(itemEl);

        var ul = prioritizedList();
        if (ul && countItems(ul) === 0) {
            emptyPrioritizedSection();
        }

        var list = inboxList();
        if (list) {
            restoredNode.classList.remove('priority-item--reorderable');
            restoredNode.classList.add('priority-item--inbox');
            restoredNode.removeAttribute('draggable');
            delete restoredNode.dataset.fromTier;
            setAction(restoredNode.querySelector('[data-priority-action]'), 'prioritize', restoredNode);

            var targetUl = null;
            if (tier) {
                var tierItems = list.querySelectorAll('li[data-tier]');
                for (var i = 0; i < tierItems.length; i++) {
                    var t = tierItems[i];
                    if (String(t.dataset.tier || t.getAttribute('data-tier') || '') === tier) {
                        var uls = t.querySelectorAll('ul');
                        if (uls.length) targetUl = uls[0];
                        break;
                    }
                }
            }
            if (targetUl) targetUl.appendChild(restoredNode);
            else list.appendChild(restoredNode); // keep visible; reload re-sorts
        }

        var all = document.querySelectorAll ? document.querySelectorAll('.priority-all-empty') : null;
        if (all && all.length) all[0].remove();

        refreshCounts();
    }

    // ------------------------------------------------------------------
    // Drag & drop reordering.
    //
    // DELEGATED on document (not on the list element) because the live
    // #priority-reorder-list <ul> is swapped in and out of the DOM as the
    // first/last item is added/removed. Handlers bound to an old <ul>
    // would silently stop firing — the exact bug reported ("can't reorder
    // right after adding").
    // ------------------------------------------------------------------
    var dragSrc = null;

    function reorderableItemOf(target) {
        if (!target || !target.closest) return null;
        if (!target.closest('#priority-reorder-list')) return null;
        return target.closest('.priority-item--reorderable');
    }

    function prevSibling(el) {
        var n = el.previousElementSibling;
        while (n && !(n.classList && n.classList.contains('priority-item--reorderable'))) {
            n = n.previousElementSibling;
        }
        return n;
    }

    function nextSibling(el) {
        var n = el.nextElementSibling;
        while (n && !(n.classList && n.classList.contains('priority-item--reorderable'))) {
            n = n.nextElementSibling;
        }
        return n;
    }

    function firstReorderable(list) {
        var all = list.querySelectorAll('.priority-item--reorderable');
        return all.length ? all[0] : null;
    }

    function lastReorderable(list) {
        var all = list.querySelectorAll('.priority-item--reorderable');
        return all.length ? all[all.length - 1] : null;
    }

    document.addEventListener('dragstart', function (event) {
        var item = reorderableItemOf(event.target);
        if (!item) return;
        dragSrc = item;
        if (item.classList) item.classList.add('dragging');
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            try { event.dataTransfer.setData('text/plain', item.dataset.cardId || ''); } catch (e) { /* required on some legacy engines only */ }
        }
    });

    document.addEventListener('dragover', function (event) {
        if (!dragSrc) return;
        var item = reorderableItemOf(event.target);
        if (!item) return; // only reorder inside the prioritized list
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

        var target = reorderableItemOf(event.target);
        if (!target || target === dragSrc) return;
        var rect = target.getBoundingClientRect ? target.getBoundingClientRect() : { top: 0, height: 40 };
        var before = (event.clientY - rect.top) < rect.height / 2;
        movePlaceholder(dragSrc, target, before);
    });

    document.addEventListener('drop', function (event) {
        if (dragSrc && event.target && event.target.closest && event.target.closest('#priority-reorder-list')) {
            event.preventDefault();
        }
    });

    document.addEventListener('dragend', function () {
        if (!dragSrc) return;
        var el = dragSrc;
        dragSrc = null;
        if (el.classList) el.classList.remove('dragging');
        commitReorder(el);
    });

    function movePlaceholder(placeholder, target, before) {
        var parent = placeholder.parentNode;
        if (!parent || parent !== target.parentNode) return;
        if (before) {
            if (target !== placeholder && target.previousElementSibling !== placeholder) {
                parent.insertBefore(placeholder, target);
            }
        } else {
            var next = target.nextElementSibling;
            if (next && next !== placeholder) {
                parent.insertBefore(placeholder, next);
            } else if (parent.lastElementChild !== placeholder) {
                parent.appendChild(placeholder);
            }
        }
    }

    function commitReorder(movingEl) {
        var list = movingEl.parentNode;
        if (!list) return;
        var prev = prevSibling(movingEl);
        api('/v1/priority/position', {
            method: 'PUT',
            body: {
                card_id: parseInt(movingEl.dataset.cardId, 10) || 0,
                after_card_id: prev ? (parseInt(prev.dataset.cardId, 10) || null) : null
            }
        })
            .then(function (res) {
                if (res.status !== 200 && res.status !== 204) {
                    throw { message: (res.data && res.data.error) || MSG.errorFailed };
                }
                flash(MSG.moved, 'success');
            })
            .catch(function (err) { flash((err && err.message) || MSG.errorFailed, 'error'); });
    }

    // ------------------------------------------------------------------
    // Keyboard reordering (Alt+↑ / Alt+↓ / Alt+Home / Alt+End) — also
    // delegated, also event-time list resolution.
    // ------------------------------------------------------------------
    document.addEventListener('keydown', function (event) {
        if (!event.altKey) return;
        var t = event.target;
        var item = (t && t.closest) ? t.closest('.priority-item--reorderable') : null;
        if (!item) return;
        var list = item.parentNode;
        if (!list || list.id !== 'priority-reorder-list') return;

        var key = event.key;

        if (key === 'ArrowUp' || key === 'ArrowLeft') {
            var prev = prevSibling(item);
            if (prev) {
                event.preventDefault ? event.preventDefault() : null;
                list.insertBefore(item, prev);
                commitReorder(item);
                if (item.focus) item.focus();
            }
        } else if (key === 'ArrowDown' || key === 'ArrowRight') {
            var next = nextSibling(item);
            if (next) {
                event.preventDefault ? event.preventDefault() : null;
                var after = next.nextElementSibling;
                if (after) list.insertBefore(item, after);
                else list.appendChild(item);
                commitReorder(item);
                if (item.focus) item.focus();
            }
        } else if (key === 'Home') {
            var first = firstReorderable(list);
            if (first && first !== item) {
                event.preventDefault ? event.preventDefault() : null;
                list.insertBefore(item, first);
                commitReorder(item);
                if (item.focus) item.focus();
            }
        } else if (key === 'End') {
            var last = lastReorderable(list);
            if (last && last !== item) {
                event.preventDefault ? event.preventDefault() : null;
                list.appendChild(item);
                commitReorder(item);
                if (item.focus) item.focus();
            }
        }
    });

    // ------------------------------------------------------------------
    // Priority digest (PRIO-12..14)
    // ------------------------------------------------------------------
    // Server-rendered fallback: the <pre> already holds the digest at the
    // default N, so the feature works with JS disabled (select + copy).
    // With JS, "Copy digest" fetches ?format=markdown with the CURRENT N
    // (recomputed live, PRIO-13), copies it to the clipboard, and shows
    // a status. On clipboard failure the pre is refilled with the fetched
    // markdown so nothing is lost (selectable fallback, PRIO-12).
    // ------------------------------------------------------------------
    (function initDigest() {
        var digest = document.getElementById('priority-digest');
        if (!digest) { return; }

        var dLang = {};
        try { dLang = JSON.parse(digest.dataset.lang || '{}'); } catch (e) { dLang = {}; }
        var D = {
            label:    dLang.label    || 'Top items',
            copied:   dLang.copied   || 'Copied — paste it anywhere.',
            fallback: dLang.fallback || 'Clipboard blocked — the digest is in the box below; select all and copy.',
            error:    dLang.error    || "Couldn't prepare the digest. Please try again."
        };

        var nInput  = document.getElementById('priority-digest-n');
        var copyBtn = document.getElementById('priority-digest-copy');
        var status  = document.getElementById('priority-digest-status');
        var body    = document.getElementById('priority-digest-body');
        if (!nInput || !copyBtn) { return; }

        var busy = false;

        function clampN(value) {
            var n = parseInt(value, 10);
            if (isNaN(n) || n < 1) { return 1; }
            if (n > 50) { return 50; }
            return n;
        }

        function digestN() {
            return clampN(nInput.value);
        }

        function setStatus(text, ok) {
            if (!status) { return; }
            status.textContent = text || '';
            status.classList.toggle('priority-digest-status--error', !ok && !!text);
            status.classList.toggle('priority-digest-status--ok', !!ok && !!text);
        }

        function copyText(text) {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                return navigator.clipboard.writeText(text);
            }
            // Older browsers / non-secure contexts: hidden-clipboard execCommand.
            return new Promise(function (resolve, reject) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                document.body.removeChild(ta);
                if (ok) { resolve(); } else { reject(new Error('copy-failed')); }
            });
        }

        copyBtn.addEventListener('click', function () {
            if (busy) { return; }
            var n = digestN();
            nInput.value = n; // normalize the field to the clamped value we send
            busy = true;
            copyBtn.disabled = true;
            setStatus('', false);

            api('/v1/priority/digest?n=' + encodeURIComponent(n) + '&format=markdown')
                .then(function (res) {
                    var md = (res && typeof res.data === 'string') ? res.data : null;
                    if (res.status !== 200 || md === null) {
                        throw new Error('digest-' + res.status);
                    }
                    if (body) { body.textContent = md; } // keep the visible fallback in sync
                    return copyText(md).then(function () {
                        setStatus(D.copied, true);
                    }, function (err) {
                        // Clipboard blocked → surface the selectable fallback (PRIO-12):
                        // the digest is in the <pre> below; select-all + copy.
                        if (body) {
                            body.classList.add('priority-digest-body--fallback');
                            if (typeof body.focus === 'function') { body.focus(); }
                        }
                        setStatus(D.fallback, false);
                    });
                })
                .catch(function (err) {
                    setStatus(D.error, false);
                })
                .then(function () {
                    busy = false;
                    copyBtn.disabled = false;
                });
        });

        // N changes → refresh the visible fallback (cheap, server recomputes live).
        nInput.addEventListener('change', function () {
            var n = digestN();
            nInput.value = n;
            api('/v1/priority/digest?n=' + encodeURIComponent(n) + '&format=markdown')
                .then(function (res) {
                    if (res && res.status === 200 && typeof res.data === 'string' && body) {
                        body.textContent = res.data;
                        body.classList.remove('priority-digest-body--fallback');
                    }
                })
                .catch(function () { /* keep the prior body; the copy button reports failures */ });
        });
    })();

    // ------------------------------------------------------------------
    // Initial count refresh (counters are server-rendered but cheap to
    // recompute once here for safety after a partial client-side change).
    // ------------------------------------------------------------------
    refreshCounts();
})();
