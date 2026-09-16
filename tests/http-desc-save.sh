#!/usr/bin/env bash
# HTTP E2E for CARD-14 STAGE B — description-local Save (spec §5.21 staging).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1, Host: shuffle.ea.org).
#
# Session: mints a fresh MYA (user 4) DB session via tests/_rt_session.php
# (Daniel's user-1 account is never touched).
#
# Exercises:
#   [1] board.php render: #cm-desc-save button present next to the preview toggle
#   [2] CSS: the save button is hidden in Preview, shown only in Edit mode;
#       pane exclusivity rules intact (the Stage A guard still holds)
#   [3] PUT /v1/cards/{id} description-only round-trip: 200 + description written
#       + title/due_date untouched + title still validated (empty title path)
#   [4] JS: saveDesc issues a description-only payload, re-seeds preview from
#       server description_html, short-circuits an unchanged draft, busy-guard
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

BODYF=$(mktemp)
SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "$SID" ] || { rm -f "$BODYF"; echo "failed to mint mya session"; exit 1; }
COOKIE="shuffle_session=$SID"

# Fixture: board + lane + card (DAO; created_by=4 so cleanup is unambiguous)
FIX=$(php -r '
require "include/bootstrap.php";
$bm = new \Shuffle\Model\Board($db);
$lm = new \Shuffle\Model\Lane($db);
$cm = new \Shuffle\Model\Card($db);
$b = $bm->create(["title" => "Mya HTTP DescSave", "visibility" => "private", "created_by" => 4]);
$l = $lm->create(["board_id" => $b, "title" => "Inbox", "position" => 1000]);
$c = $cm->create(["lane_id" => $l, "title" => "HTTP desc-save fixture", "description" => "# old", "due_date" => "2026-12-31", "created_by" => 4]);
echo "$b $l $c";')
FB=$(echo "$FIX" | awk '{print $1}')
FL=$(echo "$FIX" | awk '{print $2}')
FC=$(echo "$FIX" | awk '{print $3}')

cleanup() {
  [ -n "${FB:-}" ] && php -r '
require "include/bootstrap.php";
$cm=new \Shuffle\Model\Card($db); $lm=new \Shuffle\Model\Lane($db); $bm=new \Shuffle\Model\Board($db);
$cm->delete('"$FC"');
foreach ($lm->findByBoard('"$FB"') as $l) $lm->delete((int)$l["id"]);
$bm->delete('"$FB"');' >/dev/null 2>&1
  rm -f "$BODYF"
  php "$P" cleanup "$SID" >/dev/null 2>&1
}
trap cleanup EXIT

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

# --- [1] render: the description-local Save button is in the modal markup ----
HTML=$(curl -s -H "$H" -b "$COOKIE" "$B/board.php?id=$FB")
echo "$HTML" | grep -q 'id="cm-desc-save"'
CK "[1] render: #cm-desc-save present in board modal" "$?"
echo "$HTML" | grep -q 'class="btn btn-primary btn-sm cm-desc-save-btn" id="cm-desc-save"'
CK "[1] render: save button is primary, small, in cm-desc-save" "$?"
# And the Stage A toggle is still there (regression guard).
echo "$HTML" | grep -q 'id="cm-desc-preview-toggle"'
CK "[1] regression: #cm-desc-preview-toggle still present" "$?"

# --- [2] CSS: save button hidden in Preview, shown in Edit -------------------
awk '/description-edit-actions #cm-desc-save \{/{f=1} f&&/display: none/{n=1} f&&/^}/{exit} END{exit n?0:1}' www/css/app.css
CK "[2] css: #cm-desc-save hidden by default (Preview mode)" "$?"
awk '/is-editing .description-edit-actions #cm-desc-save \{/{f=1} f&&/display: inline-block/{i=1} f&&/^}/{exit} END{exit i?0:1}' www/css/app.css
CK "[2] css: #cm-desc-save visible in Edit mode" "$?"
# Pane exclusivity (Stage A guard must survive — the double-pane root cause).
awk '/^\.description-wrap \.form-textarea \{/{f=1} f&&/display: none/{n=1} f&&/^}/{exit} END{exit n?0:1}' www/css/app.css
CK "[2] css: textarea hidden in Preview mode (Stage A guard intact)" "$?"
awk '/\.description-wrap\.is-editing \.description-preview \{/{f=1} f&&/display: none/{n=1} f&&/^}/{exit} END{exit n?0:1}' www/css/app.css
CK "[2] css: preview hidden in Edit mode (Stage A guard intact)" "$?"

# --- [3] API: description-only PUT round-trip --------------------------------
CSRF=$(php -r '
require "include/bootstrap.php";
$row = $db->fetch("SELECT data FROM sessions WHERE id = ?", ["'"$SID"'"]);
if ($row && preg_match("/csrf_token\|s:64:\"([a-f0-9]+)\"/", $row["data"], $m)) echo $m[1];')

CODE=$(curl -s -o "$BODYF" -w '%{http_code}' -H "$H" -b "$COOKIE" -H "X-CSRF-Token: $CSRF" \
  -X PUT "$B/v1/cards/$FC" -H 'Content-Type: application/json' \
  -d '{"description":"# **new** saved via put"}')
[ "$CODE" = 200 ] && grep -q '"description":"# \*\*new\*\* saved via put"' "$BODYF"
CK "[3] PUT description-only -> 200 + description written (got $CODE, body: $(head -c 80 "$BODYF" | tr -d '\n'))" "$?"
# Title + due untouched by the description-only payload.
grep -q '"title":"HTTP desc-save fixture"' "$BODYF" && grep -q '"due_date":"2026-12-31' "$BODYF"
CK "[3] title + due_date UNCHANGED by the description-only PUT" "$?"
# description_html came back (the preview re-seed contract of Stage B).
grep -q '"description_html"' "$BODYF" && grep -q '<strong>new</strong>' "$BODYF"
CK "[3] response carries description_html (preview seed)" "$?"

# Revert description to baseline (fixture is deleted in cleanup; no drift left).
CODE=$(curl -s -o "$BODYF" -w '%{http_code}' -H "$H" -b "$COOKIE" -H "X-CSRF-Token: $CSRF" \
  -X PUT "$B/v1/cards/$FC" -H 'Content-Type: application/json' \
  -d '{"description":"# old"}')
[ "$CODE" = 200 ]
CK "[3] revert description -> 200 (got $CODE)" "$?"

# Invalid due date still validated even on a description+due payload (guard).
CODE=$(curl -s -o "$BODYF" -w '%{http_code}' -H "$H" -b "$COOKIE" -H "X-CSRF-Token: $CSRF" \
  -X PUT "$B/v1/cards/$FC" -H 'Content-Type: application/json' \
  -d '{"description":"x","due_date":"not-a-date"}')
[ "$CODE" = 400 ]
CK "[3] bad due_date in payload -> 400 (validation intact)" "$?"

# --- [4] JS: saveDesc contract in card-modal.js ------------------------------
J=www/js/card-modal.js
grep -q "body: { description: desc }" $J
CK "[4] js: saveDesc sends a description-only payload" "$?"
grep -q "descInput.addEventListener('input'" $J && grep -q "_descDirty = true" $J
CK "[4] js: dirty-tracking via input (real keystroke only)" "$?"
grep -q "if (!card._descDirty)" $J
CK "[4] js: unchanged draft short-circuits (no round-trip)" "$?"
grep -q "descSaving = true" $J && grep -q "if (descSaving) return;" $J
CK "[4] js: busy-guard prevents double save" "$?"
# On success, re-seed the preview from the server's description_html.
grep -q "result.data.card.description_html" $J && grep -q "setDescPreview(true, seeded)" $J
CK "[4] js: success returns to Preview seeded from server description_html" "$?"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
