/**
 * Shuffle — Personal Priority List (PRIO-01..11)
 *
 * progressive enhancement on top of the server-rendered /priority.php:
 *   - "Prioritize" (inbox → prioritized)
 *   - "Remove"    (prioritized → inbox)
 *   - drag & drop reordering within the prioritized section
 *   - keyboard reordering (Alt+↑ / Alt+↓ / Home / End on a list item)
 *
 * Without JavaScript the page still renders fully; each card links to its
 * card page. All state-changing calls go to the v1 API with CSRF + session
 * (Shuffle.api from app.js).
 */

(function () {
    'use strict';

    var script = document.getElementById('priority-script');
    if (!script) {
        return; // not a priority page
    }

    var L;
    try { L = JSON.parse(script.dataset.lang || '{}'); } catch (e) { L = {}; }
    // app.js (loaded in the page footer, AFTER this script tag) defines
    // Shuffle.api. It only exists once a user action fires, so resolve it
    // lazily at call time — never at parse time (same pattern as
    // board.js / card.js calling Shuffle.api() inside handlers).
    function api(url, options) {
        if (typeof Shuffle === 'undefined' || !Shuffle.api) {
            return Promise.reject({ message: MSG.errorFailed });
        }
        return Shuffle.api(url, options);
    }

    var MSG = {
        added:        L.added        || 'Moved to prioritized.',
        removed:      L.removed      || 'Moved back to inbox.',
        moved:        L.moved        || 'Reordered.',
        errorFailed:  L.error_failed || 'Couldn\'t update your priority list. Please try again.',
        errorConflict:L.error_conflict || 'That card is on a Done lane — move it out of Done first.',
        actionRemove: L.remove       || 'Remove from list',
        actionAdd:    L.prioritize   || 'Prioritize'
    };

    // ---------------------------------------------------------------
    // DOM anchors
    // ---------------------------------------------------------------
    var inboxSection     = document.querySelector('[data-count-section="inbox"]');
    var prioritizedCount = document.querySelector('[data-count-section="prioritized"]');
    var inboxList        = document.querySelector('[data-priority-section="inbox"]');
    var prioList         = document.getElementById('priority-reorder-list');
    var anyPrioritized   = !!prioList;
    var anyInbox         = !!inboxList;
    var allEmptyEl       = document.querySelector('.priority-all-empty');

    function itemCount(listEl) {
        if (!listEl) return 0;
        // Prioritized section: items are direct children.
        // Inbox section: items live inside tier <li> wrappers.
        return listEl.querySelectorAll(':scope > .priority-item').length
             + listEl.querySelectorAll(':scope > .priority-tier > .priority-item').length;
    }

    function refreshCounts() {
        if (inboxSection && inboxList) inboxSection.textContent = String(itemCount(inboxList));
        if (prioritizedCount && prioList) prioritizedCount.textContent = String(itemCount(prioList));
    }

    function flash(message, type) {
        if (typeof Shuffle.showFlash === 'function') Shuffle.showFlash(message, type);
    }

    function busy(btn, busyState) {
        if (busyState) {
            btn.dataset.label = btn.textContent;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        } else {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        }
    }

    // ---------------------------------------------------------------
    // Prioritize / Remove
    // ---------------------------------------------------------------
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-priority-action]');
        if (!btn) return;

        var action  = btn.dataset.priorityAction;
        var cardId  = btn.dataset.cardId;
        var itemEl  = btn.closest('.priority-item');
        if (!itemEl || !cardId) return;

        busy(btn, true);

        if (action === 'prioritize') {
            api(`/v1/priority/inbox/${encodeURIComponent(cardId)}`, { method: 'POST' })
                .then(function (res) {
                    if (res.status !== 200 && res.status !== 204) {
                        throw { message: res.data && res.data.error || MSG.errorFailed, conflict: false };
                    }
                    onPrioritized(itemEl);
                    flash(MSG.added, 'success');
                })
                .catch(function (err) {
                    flash((err && err.message) || MSG.errorFailed, 'error');
                })
                .finally(function () { busy(btn, false); });
        } else if (action === 'remove') {
            api(`/v1/priority/inbox/${encodeURIComponent(cardId)}`, { method: 'DELETE' })
                .then(function (res) {
                    onRemoved(itemEl);
                    flash(MSG.removed, 'success');
                })
                .catch(function () {
                    flash(MSG.errorFailed, 'error');
                })
                .finally(function () { busy(btn, false); });
        }
    });

    function onPrioritized(itemEl) {
        // Move the card from inbox to the bottom of prioritized.
        if (!prioList) {
            // No prioritized <ul> rendered (was empty). Build one by swapping
            // the empty-state <p> for the list element.
            var section = document.getElementById('priority-prioritized-section');
            if (section) {
                var empty = section.querySelector('.priority-empty');
                if (empty) {
                    var ul = document.createElement('ul');
                    ul.className = 'priority-list';
                    ul.setAttribute('role', 'list');
                    ul.dataset.prioritySection = 'prioritized';
                    ul.id = 'priority-reorder-list';
                    empty.replaceWith(ul);
                    prioList = ul;
                }
            }
            // Re-acquire anchor
            prioList = document.getElementById('priority-reorder-list');
        }

        var clone = itemEl.cloneNode(true);
        clone.classList.add('priority-item--reorderable');
        clone.setAttribute('draggable', 'true');
        // Switch the action button: add → remove
        var actionBtn = clone.querySelector('[data-priority-action]');
        if (actionBtn) {
            actionBtn.dataset.priorityAction = 'remove';
            actionBtn.setAttribute('aria-label', MSG.actionRemove + ' — ' + (clone.querySelector('.priority-item-link') || {}).textContent);
        }

        if (prioList) prioList.appendChild(clone);
        itemEl.remove();

        refreshCounts();
        hideEmptyStates();
    }

    function onRemoved(itemEl) {
        itemEl.remove();
        if (anyPrioritized && prioList && prioList.querySelectorAll(':scope > .priority-item').length === 0) {
            // Show the empty state again, drop the list
            var section = document.getElementById('priority-prioritized-section');
            if (section && prioList) {
                var p = document.createElement('p');
                p.className = 'priority-empty';
                p.setAttribute('role', 'status');
                var emptyText = (document.getElementById('priority-inbox-section') &&
                                 (document.getElementById('priority-inbox-section').querySelector('.priority-empty'))) ||
                                document.createElement('span');
                p.textContent = emptyText.textContent || 'Nothing prioritized yet.';
                prioList.replaceWith(p);
            }
        }
        refreshCounts();
    }

    function hideEmptyStates() {
        if (allEmptyEl) allEmptyEl.remove();
    }

    // ---------------------------------------------------------------
    // Drag & drop reordering (prioritized section)
    // ---------------------------------------------------------------
    var dragSrc = null;
    var prevDragOverTarget = null;

    if (anyPrioritized && prioList) {
        prioList.addEventListener('dragstart', function (event) {
            var item = event.target.closest('.priority-item');
            if (!item) return;
            dragSrc = item;
            item.classList.add('dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                try { event.dataTransfer.setData('text/plain', item.dataset.cardId || ''); } catch (e) {}
            }
        });

        prioList.addEventListener('dragover', function (event) {
            event.preventDefault(); // allow drop
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
            var target = event.target.closest('.priority-item--reorderable');
            if (!target || !dragSrc || target === dragSrc) return; // self-hittest: no-op
            var rect = target.getBoundingClientRect();
            var before = (event.clientY - rect.top) < rect.height / 2;
            movePlaceholder(dragSrc, target, before);
        });

        prioList.addEventListener('drop', function (event) {
            event.preventDefault();
        });

        prioList.addEventListener('dragend', function () {
            if (dragSrc) {
                dragSrc.classList.remove('dragging');
                commitReorder(dragSrc);
                dragSrc = null;
                prevDragOverTarget = null;
            }
        });
    }

    function movePlaceholder(placeholder, target, before) {
        if (target.parentNode !== placeholder.parentNode) return;
        if (before) {
            if (target !== placeholder && (target.previousElementSibling !== placeholder)) {
                target.parentNode.insertBefore(placeholder, target);
            }
        } else {
            var next = target.nextElementSibling;
            if (next !== placeholder && next) {
                target.parentNode.insertBefore(placeholder, next);
            } else if (target.parentNode.lastElementChild !== placeholder) {
                target.parentNode.appendChild(placeholder);
            }
        }
    }

    function prevSibling(el) {
        var n = el.previousElementSibling;
        while (n && !n.classList.contains('priority-item--reorderable')) {
            n = n.previousElementSibling;
        }
        return n;
    }

    function nextSibling(el) {
        var n = el.nextElementSibling;
        while (n && !n.classList.contains('priority-item--reorderable')) {
            n = n.nextElementSibling;
        }
        return n;
    }

    function firstReorderable(list) {
        return list.querySelector(':scope > .priority-item--reorderable');
    }

    function lastReorderable(list) {
        var all = list.querySelectorAll(':scope > .priority-item--reorderable');
        return all.length ? all[all.length - 1] : null;
    }

    function commitReorder(movingEl) {
        // The DOM order after the drop is the desired state: find the item
        // immediately BEFORE the moving item in the list (null if it is first)
        // and tell the API "put it after that card" (null = move to top).
        var prev = prevSibling(movingEl);
        api('/v1/priority/position', {
            method: 'PUT',
            body: {
                card_id:       parseInt(movingEl.dataset.cardId, 10) || 0,
                after_card_id: prev ? (parseInt(prev.dataset.cardId, 10) || null) : null
            }
        })
            .then(function (res) {
                if (res.status !== 200) {
                    throw { message: (res.data && res.data.error) || MSG.errorFailed };
                }
                flash(MSG.moved, 'success');
            })
            .catch(function (err) {
                flash((err && err.message) || MSG.errorFailed, 'error');
            });
    }

    // ---------------------------------------------------------------
    // Keyboard reordering (Alt+↑ / Alt+↓ / Home / End)
    // ---------------------------------------------------------------
    if (anyPrioritized && prioList) {
        prioList.addEventListener('keydown', function (event) {
            var item = event.target.closest('.priority-item--reorderable');
            if (!item) return;

            var key = event.key;
            if (!event.altKey && !event.ctrlKey) return; // let bare keys do nothing

            if (!event.altKey) return;

            if (key === 'ArrowUp' || key === 'ArrowLeft') {
                var prev = prevSibling(item);
                if (prev) {
                    event.preventDefault();
                    if (item.move) item.move({ before: prev });
                    else prev.parentNode.insertBefore(item, prev);
                    commitReorder(item);
                    item.focus();
                }
            } else if (key === 'ArrowDown' || key === 'ArrowRight') {
                var next = nextSibling(item);
                if (next) {
                    event.preventDefault();
                    if (item.move) item.move({ after: next });
                    else {
                        var afterNext = next.nextElementSibling;
                        next.parentNode.insertBefore(item, afterNext);
                    }
                    commitReorder(item);
                    item.focus();
                }
            } else if (key === 'Home') {
                event.preventDefault();
                var first = firstReorderable(prioList);
                if (first && first !== item) {
                    if (item.move) item.move({ before: first });
                    else first.parentNode.insertBefore(item, first);
                    commitReorder(item);
                    item.focus();
                }
            } else if (key === 'End') {
                event.preventDefault();
                var last = lastReorderable(prioList);
                if (last && last !== item) {
                    if (item.move) prioList.appendChild(item);
                    else prioList.appendChild(item);
                    commitReorder(item);
                    item.focus();
                }
            }
        });
    }

    // ---------------------------------------------------------------
    // Initial count refresh
    // ---------------------------------------------------------------
    refreshCounts();
})();
