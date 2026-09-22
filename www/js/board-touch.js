/**
 * MOB-05/06 (spec v2.8 §7.19) — long-press → held-drag to move a card.
 *
 * On coarse-pointer devices, a long press (≥ 450 ms, stationary) "picks
 * up" a card: it lifts visually and the finger becomes the drag cursor.
 * Releasing over a lane commits the move through the SHARED commit path
 * (optimistic DOM move + PUT /v1/cards/{id}/move + revert-on-failure)
 * via ShuffleBoardSync.touchDrag — the same code the mouse path uses,
 * so both paths can't drift apart.
 *
 * Contract (the "swipe will not pick up and move cards" requirement):
 *   - A quick swipe (finger moves > 10 px before the 450 ms hold fires)
 *     aborts the hold — no drag is ever started.
 *   - A stationary long-press is the ONLY path to a drag. No swipe can
 *     produce one, so the accidental-drag failure class is structurally
 *     absent.
 *   - While held, the card's inline `touch-action` is flipped to 'none'
 *     (and restored on release), so after the 450 ms hold the browser
 *     can no longer claim the touch for scrolling — the finger events
 *     route exclusively to this handler, including over other lanes.
 *     Browsers claim a gesture within ~250 ms of touchstart, so the
 *     450 ms hold reliably wins before it can be stolen.
 *   - A quick swipe (before the hold fires) is still claimed by the
 *     browser as a native scroll and fires `pointercancel` — our
 *     pointercancel handler aborts any pending hold and cancels any
 *     in-flight drag, so a swipe never moves a card.
 *   - The mouse path (HTML5 DnD in board.js) is untouched — touch
 *     pointers are filtered out here by pointerType.
 *
 * Progressive enhancement: no-ops unless a touch-capable coarse-pointer
 * device + the board region are present. No dependencies, no globals.
 */
(function () {
    'use strict';

    /* === thresholds — asserted by the contract suite === */
    var HOLD_MS   = 450;   // stationary dwell before the card lifts
    var JITTER_PX = 10;    // movement before HOLD_MS fires → abort (swipe)

    function isCoarseTouch() {
        try {
            if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches)
                return (navigator.maxTouchPoints || 0) > 0;
        } catch (e) { /* fall through */ }
        return (navigator.maxTouchPoints || 0) > 0;
    }
    if (!isCoarseTouch()) return;

    var board = document.querySelector('.board-lanes-container');
    if (!board) return;

    var td = window.ShuffleBoardSync && window.ShuffleBoardSync.touchDrag;
    if (!td) return;

    /* ---- MOB-07: first-use hint, once per session ------------------ */
    (function () {
        var HINT_KEY = 'shuffle_touch_drag_hint';
        function show() {
            try {
                if (window.sessionStorage && sessionStorage.getItem(HINT_KEY)) return;
                sessionStorage.setItem(HINT_KEY, '1');
            } catch (e) {}
            var scriptTag = document.getElementById('board-script');
            var lang = {};
            try { lang = scriptTag ? JSON.parse(scriptTag.dataset.lang || '{}') : {}; } catch (e) {}
            if (window.Shuffle && window.Shuffle.showFlash)
                window.Shuffle.showFlash(
                    lang.card_long_press_hint ||
                    'Tip: press and hold a card to drag it — a swipe won\'t move it.',
                    'info', 6000
                );
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(show, 2000);
            }, { once: true });
        } else {
            setTimeout(show, 2000);
        }
    })();
    /* ---------------------------------------------------------------- */

    /* === held-drag state ============================================= */
    var holdTimer = null;
    var downCard = null;
    var downX = 0, downY = 0;
    var lastX = 0, lastY = 0;
    var held = false;

    function clearHold() {
        if (holdTimer) { clearTimeout(holdTimer); holdTimer = null; }
    }

    /* Resolve the target lane + insertion slot at a viewport point.
       Conventions match board.js's mouse path exactly:
       - `.lane-cards` is the target container
       - `afterCardEl` = the card whose midpoint is the last one ABOVE
         the finger (insert right after it; null = top of the lane). */
    function resolveTarget(x, y) {
        var el = document.elementFromPoint(x, y);
        if (!el || !el.closest) return null;
        var laneCards = el.closest('.lane-cards');
        if (!laneCards) return null;

        var cards = laneCards.querySelectorAll('.card:not([data-dragging="true"])');
        var afterCardEl = null;
        for (var i = 0; i < cards.length; i++) {
            var r = cards[i].getBoundingClientRect();
            if (r.height === 0) continue;
            if (y >= r.top + r.height / 2) afterCardEl = cards[i];
            else break;
        }
        return { laneCards: laneCards, afterCardEl: afterCardEl };
    }

    /* ---- pointerdown: start the hold timer on a card ---------------- */
    board.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse') return;                 // desktop path owns itself
        if (e.pointerType !== 'touch' && e.pointerType !== 'pen') return;
        if (e.button !== 0 && e.pointerType !== 'touch') return;
        if (held) {
            // Multi-touch: a second finger joined — cancel the held drag
            // cleanly (never try to track two pointers).
            held = false;
            if (downCard) downCard.removeAttribute('data-dragging');
            downCard = null;
            td.setHeld(false);
            td.clearIndicators();
            return;
        }

        var card = e.target.closest('.card');
        if (!card) return;
        if (e.target.closest('.card-menu-btn')) return;        // ⋯ button keeps its own tap
        if (card.querySelector('.context-menu')) return;       // menu already open

        downCard = card;
        downX = e.clientX;  downY = e.clientY;
        lastX = e.clientX;  lastY = e.clientY;
        held = false;

        clearHold();
        holdTimer = setTimeout(function () {
            holdTimer = null;
            if (!downCard || held) return;
            held = true;
            downCard.setAttribute('data-dragging', 'true');
            td.setHeld(true);                                  // RT-04 sync guard ON
            try { if (navigator.vibrate) navigator.vibrate(10); } catch (e2) {}
            // The CSS already sets `touch-action: none` on the card at
            // ≤640 px (the whole ≤640 block is gated to phones), so the
            // browser can't claim this touch for scrolling — pointer
            // events route to us exclusively, including as the finger
            // crosses other lanes.
            // Take the first preview over the card.
            var target = resolveTarget(lastX, lastY);
            if (target) td.preview(target.laneCards, target.afterCardEl);
        }, HOLD_MS);
    }, { passive: true });

    /* ---- pointermove: track the finger ------------------------------ */
    board.addEventListener('pointermove', function (e) {
        if (e.pointerType === 'mouse') return;
        if (e.pointerType !== 'touch' && e.pointerType !== 'pen') return;
        lastX = e.clientX;
        lastY = e.clientY;

        if (held && downCard) {
            // HELD: move the ghost preview line under the finger.
            var target = resolveTarget(lastX, lastY);
            if (target) td.preview(target.laneCards, target.afterCardEl);
            else        td.clearIndicators();

        } else if (!held && downCard) {
            // PENDING: movement beyond JITTER_PX before HOLD_MS fires is
            // a swipe → abort the hold; it can no longer become a drag.
            if (Math.abs(lastX - downX) > JITTER_PX ||
                Math.abs(lastY - downY) > JITTER_PX) {
                clearHold();
                downCard = null;
            }
        }
    }, { passive: true });

    /* ---- release: commit the drag, or cancel it ---------------------- */
    function onRelease(cancel) {
        clearHold();

        var wasHeld = held;
        var card    = downCard;
        held = false;
        downCard = null;

        if (card) {
            card.removeAttribute('data-dragging');
            // Suppress the synthetic click a browser may fire after a
            // long-press — board.js reads + clears this flag so the card
            // modal doesn't pop open over the drag result.
            card.dataset.touchLongPress = '1';
        }

        if (wasHeld && card && !cancel) {
            var target = resolveTarget(lastX, lastY);
            if (target) {
                // Shared commit path: optimistic DOM move + PUT + revert.
                td.setHeld(false);
                Promise.resolve(td.commit(card, target.laneCards, target.afterCardEl))
                    .catch(function () {
                        /* commitMoveToLane already flashes the error */
                    });
            } else {
                // Released outside any lane — cancel (card stays put).
                td.setHeld(false);
                td.clearIndicators();
            }
        } else {
            // Swipe/tap release, or a pointercancel mid-drag — no commit;
            // clear any preview, leave the card exactly where it was.
            td.setHeld(false);
            if (wasHeld) td.clearIndicators();
        }
    }
    board.addEventListener('pointerup',     function () { onRelease(false); }, { passive: true });
    board.addEventListener('pointercancel', function () { onRelease(true);  }, { passive: true });

})();
