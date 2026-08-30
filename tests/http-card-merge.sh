#!/usr/bin/env bash
# HTTP E2E for CARD-10..13 (card merge) — runs against live Apache (shuffle.ea.org).
#
# Exercises:
#   [1] POST /v1/cards/{id}/merge unauth            → 401
#   [2] POST /v1/cards/{id}/merge same-card dest    → 400
#   [3] POST /v1/cards/{id}/merge cross-board dest  → 400
#   [4] POST /v1/cards/{id}/merge happy path        → 200 (source gone,
#                                                      survivor has folded content)
#   [5] Card-page render with merge button (only when >1 card on board)
#
set -u
H='-H Host:shuffle.ea.org'
B=http://127.0.0.1

# Resolve admin session + CSRF (user 1) from the DB at runtime.
SESS=$(cd ~/shuffle && php -r '
  require "include/bootstrap.php";
  $row = $db->fetch("SELECT id, `data` FROM sessions WHERE user_id = 1 ORDER BY last_activity DESC LIMIT 1");
  if (!$row || !preg_match("/csrf_token\\|s:64:\\\"([0-9a-f]{64})\\\"/", $row["data"], $m)) exit(3);
  echo $row["id"] . "\n" . $m[1];') || { echo "no live admin session — run login first"; exit 1; }
SID=$(printf '%s' "$SESS" | head -1)
CSRF=$(printf '%s' "$SESS" | tail -1)
COOKIE="shuffle_session=$SID"
PASS=0; FAIL=0
ck() { # ck <name> <cond:0=fail>
  if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1";
  else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi
}

cd ~/shuffle

# ---------------------------------------------------------------------------
# Fixtures: main board with 2 cards + 1 other user's board + cross-board card
# (PHP file to escape shell substitution cleanly)
# ---------------------------------------------------------------------------
FIXTURE=$(php tests/_fixture-merge.php)
echo "fixture: $FIXTURE"
MAIN=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["main"])' "$FIXTURE")
C1=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["c1"])' "$FIXTURE")
C2=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["c2"])' "$FIXTURE")
CX=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["cx"])' "$FIXTURE")
OTHER=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["other"])' "$FIXTURE")

cleanup() {
  php -r '
    require "include/bootstrap.php";
    $boards = ['"$MAIN"','"$OTHER"'];
    foreach ($boards as $b) { (new \Shuffle\Service\BoardService(new \Shuffle\Model\Board($db), new \Shuffle\Model\Lane($db), new \Shuffle\Model\Card($db)))->deleteBoard($b); }
  ' >/dev/null 2>&1
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# [1] Unauthenticated merge → 401
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/merge-r1 -w '%{http_code}' $H \
  -H "Content-Type: application/json" \
  -X POST $B/v1/cards/$C1/merge \
  -d '{"destination_card_id": '"$C2"'}')
[ "$CODE" = "403" ]; ck "unauth merge → 403 (CSRF gate before auth) (got $CODE)" $?

# ---------------------------------------------------------------------------
# [2] Same-card merge → 400
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/merge-r2 -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$C1/merge \
  -d '{"destination_card_id": '"$C1"'}')
[ "$CODE" = "400" ]; ck "same-card merge → 400 (got $CODE)" $?

# ---------------------------------------------------------------------------
# [3] Cross-board destination → 400
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/merge-r3 -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$C1/merge \
  -d '{"destination_card_id": '"$CX"'}')
[ "$CODE" = "400" ]; ck "cross-board → 400 (got $CODE)" $?

# ---------------------------------------------------------------------------
# [5] Card-page render: the 2-card board shows the merge button BEFORE the
#     happy-path merge consumes the source card (render order matters —
#     after the merge the board has one card and the button correctly
#     disappears, which is its own assertion below)
# ---------------------------------------------------------------------------
HTML=$(curl -s $H -b "$COOKIE" "$B/card.php?id=$C2")
echo "$HTML" | grep -q 'btn-merge-card'; ck "card.php (2-card board) renders merge button" $?
echo "$HTML" | grep -q 'card-merge-overlay'; ck "card.php renders merge modal overlay" $?
echo "$HTML" | grep -q 'name="merge-destination"'; ck "card.php modal lists a destination radio" $?

# ---------------------------------------------------------------------------
# [4] Happy path (source = C1, destination = C2)
# ---------------------------------------------------------------------------
MERGED=$(curl -s -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$C1/merge \
  -d '{"destination_card_id": '"$C2"'}')
CODE="${MERGED: -3}"
BODY="${MERGED%$CODE}"
[ "$CODE" = "200" ]; ck "merge happy path → 200 (got $CODE)" $?
echo "$BODY" | python3 -c "
import json,sys
d=json.load(sys.stdin)
card=d['card']
assert card['id']==$C2, card
assert any(c['body']=='http src comment' for c in card['comments']), 'merged comment missing'
" ; ck "response: survivor id + folded comment present" $?

# Verify source card is gone (404 on GET)
GETSRC=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE" $B/v1/cards/$C1)
[ "$GETSRC" = "404" ]; ck "source card GET → 404 (got $GETSRC)" $?

# Verify card_merged activity row on survivor
MERGEDFEED=$(curl -s $H -b "$COOKIE" "$B/v1/cards/$C2/activity?limit=5")
echo "$MERGEDFEED" | python3 -c "
import json,sys
d=json.load(sys.stdin)
item=[i for i in d['items'] if i['event']=='card_merged']
assert item, 'card_merged event missing'
assert item[0]['detail']['source_card']['id']==$C1, item[0]
" ; ck "history feed: card_merged event present on survivor with source id" $?

# ---------------------------------------------------------------------------
# [5b] Post-merge: the survivor's board is down to 1 card, so the merge
#      surface correctly disappears from the survivor's page
# ---------------------------------------------------------------------------
HTML=$(curl -s $H -b "$COOKIE" "$B/card.php?id=$C2")
echo "$HTML" | grep -q 'btn-merge-card'; [ "$?" -eq 1 ]; ck "card.php: merge button gone once board is down to 1 card" $?

# A single-card board (fresh fixture) should NOT render the merge button
read -r SINGLE SINGLEBOARD < <(php tests/_fixture-single-card.php)
HTML=$(curl -s $H -b "$COOKIE" "$B/card.php?id=$SINGLE")
echo "$HTML" | grep -q 'btn-merge-card'; [ "$?" -eq 1 ]; ck "card.php (1-card board) does NOT render merge button" $?

# cleanup single-card fixture board
php -r '
require "include/bootstrap.php";
(new \Shuffle\Service\BoardService(new \Shuffle\Model\Board($db), new \Shuffle\Model\Lane($db), new \Shuffle\Model\Card($db)))->deleteBoard('"$SINGLEBOARD"');
' >/dev/null 2>&1
echo ""
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
