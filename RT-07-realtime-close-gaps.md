# RT-07 — board real-time sync: close the gaps after RT-03 landed

## Symptoms (Daniel, after the RT-03 WS-merge into main)
1. Editing a card in the modal and closing it → the board tile on the
   board page does NOT reflect the change (until a manual reload).
2. The board page occasionally reloads on its own mid-session.

## Verified against live 127.0.0.1 (mya, board 368, card 829)
The server pipeline is correct end-to-end:
- card PUT persists → `boards.version` bumps → `board_events` row appended.
- daemon pushes `{"type":"board_version","board":N,"version":M}` within ~2 s
  over **both** the direct daemon socket and the Apache `mod_proxy_wstunnel`
  (the browser's path).
- `GET /v1/boards/{id}/region` serves the fresh server-rendered HTML
  (title change visible, ETag/304 contract works for stale and current
  If-None-Match values).
- `GET board.php` renders the fresh state — the "reload" path itself is
  fine when it does run.

## Root causes (all client-side)
**A. `performRegionSync` inverts the ETag (www/js/board.js).**
   It sends `If-None-Match: "<targetVersion>"` — the NEW version — as the
   client's ETag, not the client's CURRENT version. Every region fetch
   therefore always 304s ("already current"), and `swapRegion()` is skipped
   even when the client is actually stale. Result: the board tile stays
   the original after any edit; only a manual reload heals it.

**B. `close()` in card-modal.js does a full `window.location.reload()`**
   whenever a WS frame was deferred by the modal-guard (`pendingSync=true`).
   That is the "board randomly reloads during a session" complaint — every
   save-then-close fires the same path, which is noisy and throws away the
   user's scroll / focus / keyboard selection on the tile.

**C. Close does not wait for an in-flight autosave.**
   `close()` fires the close handler synchronously; the 800 ms debounced
   save() may not have landed yet. `state.boardMutations` is only incremented
   in the save() success handler, so close() can read 0 and skip the sync
   entirely. Then the save lands, the WS echo arrives, but the modal is gone
   so the sync runs — but by then the user is already looking at the stale
   tile.

**D. Checklist mutations in card-modal.js never call `noteBoardMutation()`**
   (create-checklist, add-item, toggle-item, delete-item, delete-checklist).
   They DO bump the board version server-side (verified in
   ChecklistService.php). So a save in the checklist panel leaves the tile's
   "3/5" progress badge stale until a coincidental 15 s poll tick or a
   manual reload.

## Fix (this branch, 4 changes)
1. board.js `performRegionSync`: send `If-None-Match: "<boardVersion>"`
   (the client's known version) and only swap on 200 (keep the 304
   early-return). `boardVersion` advances to `targetVersion` on success
   as before. No test-suite breakage (the pinned contract in
   http-board-sync.sh [3] is "If-None-Match=<current server version> →
   304", which still holds).
2. board.js `ShuffleBoardSync.syncNow(targetVersion)`: accept `undefined`
   to mean "current server version" (existing contract, unchanged).
   Add `refreshHeader()` helper — fetches the board page and refreshes
   only the `data-*` attributes on `.board-view-page` AND `<h1>` /
   label-set in the header — so a full reload is NOT needed when the
   board title/labels changed outside the region (e.g. a label was
   renamed on another client). Called from card-modal close path when
   the `pendingSync` flag was set (preserves that behavior without the
   reload).
3. card-modal.js close(): replace `window.location.reload()` with
   `window.ShuffleBoardSync.syncNow()` + `refreshHeader()`. Add an
   in-flight-saves guard: `close()` queues the board-reconciliation
   behind the in-flight autosave Promises before running, using a
   small counter `state.savingInFlight` that save() bumps on request
   and decrements on settle.
4. card-modal.js: add `noteBoardMutation()` to the success paths of:
   - POST /v1/cards/{id}/checklists
   - POST /v1/checklists/{id}/items
   - PUT  /v1/checklist-items/{id} (toggle)
   - DELETE /v1/checklists/{id}
   - DELETE /v1/checklist-items/{id}

## What we do NOT do (yet)
- We do NOT remove the archive/restore/move-to-board full reloads in
  card-modal.js. Those are structural changes (card disappears from /
  lands in a different board); a reload is the honest render. We keep
  them until a spec item covers "card moves between boards in-place".
- We do NOT re-scope the RT-03 push protocol. The server pipeline is
  verified correct; the bugs are all in the client merge path.

## Files touched
- www/js/board.js
- www/js/card-modal.js
- tests/card-modal-autosave.test.js (add a case: close() fires syncNow
  exactly once even when an autosave is in-flight at close time —
  exercises the new `savingInFlight` guard)
- tests/http-board-sync.sh (add a case: region fetch with the CURRENT
  client ETag returns 200+HTML, not 304)

## Spec
SPECIFICATION §5.19 (RT-04/05/06) already covers the in-place region
sync and the modal close fallback. The ETag-inversion on the client
was never tested; this branch pins it with a contract test. The
"close reloads" behavior is a bug, not a spec requirement; the fix
matches the spec's intent ("sync in place; defer the fallback only
when the modal is open" — and on close the modal is GONE, so the
fallback should be in-place, not a full reload).
