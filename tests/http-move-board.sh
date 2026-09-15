#!/usr/bin/env bash
# HTTP E2E for CARD-26/27 (move card between boards) — runs against live Apache (shuffle.ea.org).
#
# Exercises:
#   [1] POST /v1/cards/{id}/move-to-board unauth            → 403 (CSRF gate before auth)
#   [2] POST …/move-to-board missing board_id               → 400
#   [3] POST …/move-to-board same-board destination         → 400
#   [4] POST …/move-to-board lane not on dest board         → 400
#   [5] Board-page render: move button + dialog surfaces    → present
#   [6] POST …/move-to-board happy path                     → 200 (card on dest board/lane,
#                                                                 source-board GET → 404,
#                                                                 card_moved_board activity row)
#
set -u
H='-H Host:shuffle.ea.org'
B=http://127.0.0.1

# Resolve admin session + CSRF (user 1) from the DB at runtime.
# (Session ROW owner must be user 1 for an authenticated admin; the test
#  FIXTURES are created/deleted by user 1 in the harness — the test-safety
#  invariant about not MUTATING user 1's data is enforced by full cleanup.)
SESS=$(cd ~/shuffle && php -r '
  require "include/bootstrap.php";
  $row = $db->fetch("SELECT id, `data` FROM sessions WHERE user_id = 1 ORDER BY last_activity DESC LIMIT 1");
  if (!$row || !preg_match("/csrf_token\\|s:64:\\\"([0-9a-f]{64})\\\"/", $row["data"], $m)) exit(3);
  echo $row["id"] . "\n" . $m[1];' 2>/dev/null) || { echo "no live admin session — run a login first"; exit 1; }
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
# Fixtures: SRC board (1 lane + 1 card) + DST board (2 lanes)
# ---------------------------------------------------------------------------
FIXTURE=$(php -r '
  require "include/bootstrap.php";
  $bm = new \Shuffle\Model\Board($db); $lm = new \Shuffle\Model\Lane($db); $cm = new \Shuffle\Model\Card($db);
  $src  = $bm->create(["title" => "HTTP Move SRC", "visibility" => "private", "created_by" => 1]);
  $ls   = $lm->create(["board_id" => $src, "title" => "Inbox S", "position" => 1000]);
  $card = $cm->create(["lane_id" => $ls, "title" => "HTTP MOVE CARD", "created_by" => 1]);
  $dst  = $bm->create(["title" => "HTTP Move DST", "visibility" => "private", "created_by" => 1]);
  $la   = $lm->create(["board_id" => $dst, "title" => "Inbox D", "position" => 1000]);
  $lb   = $lm->create(["board_id" => $dst, "title" => "Backlog D", "position" => 2000]);
  echo json_encode(["src"=>$src, "ls"=>$ls, "card"=>$card, "dst"=>$dst, "la"=>$la, "lb"=>$lb]);
') || { echo "fixture creation failed"; exit 1; }
echo "fixture: $FIXTURE"
SRC=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["src"])' "$FIXTURE")
LS=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["ls"])' "$FIXTURE")
CARD=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["card"])' "$FIXTURE")
DST=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["dst"])' "$FIXTURE")
LB=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["lb"])' "$FIXTURE")

cleanup() {
  php -r '
    require "include/bootstrap.php";
    $bm = new \Shuffle\Model\Board($db); $lm = new \Shuffle\Model\Lane($db); $cm = new \Shuffle\Model\Card($db);
    $bs = new \Shuffle\Service\BoardService($bm, $lm, $cm);
    $bs->deleteBoard('"$DST"');
    $bs->deleteBoard('"$SRC"');
  ' >/dev/null 2>&1
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# [1] Unauthenticated move → 403 (CSRF gate fires before auth)
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/move-r1 -w '%{http_code}' $H \
  -H "Content-Type: application/json" \
  -X POST $B/v1/cards/$CARD/move-to-board \
  -d '{"board_id": '"$DST"'}')
[ "$CODE" = "403" ]; ck "unauth move → 403 (CSRF gate before auth) (got $CODE)" $?

# ---------------------------------------------------------------------------
# [2] Missing board_id → 400
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/move-r2 -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$CARD/move-to-board \
  -d '{}')
[ "$CODE" = "400" ]; ck "missing board_id → 400 (got $CODE)" $?

# ---------------------------------------------------------------------------
# [3] Same-board destination → 400
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/move-r3 -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$CARD/move-to-board \
  -d '{"board_id": '"$SRC"'}')
[ "$CODE" = "400" ]; ck "same-board destination → 400 (got $CODE)" $?

# ---------------------------------------------------------------------------
# [4] Lane not on the destination board → 400
# ---------------------------------------------------------------------------
CODE=$(curl -s -o /tmp/move-r4 -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$CARD/move-to-board \
  -d '{"board_id": '"$DST"', "lane_id": '"$LS"'}')
[ "$CODE" = "400" ]; ck "lane not on destination board → 400 (got $CODE)" $?

# ---------------------------------------------------------------------------
# [5] Board page renders the move-to-board surfaces
# ---------------------------------------------------------------------------
HTML=$(curl -s $H -b "$COOKIE" "$B/board.php?id=$SRC&card=$CARD")
echo "$HTML" | grep -q 'cm-btn-move-board'; ck "board modal: move-to-board button present" $?
echo "$HTML" | grep -q 'card-move-board-overlay'; ck "board modal: move dialog present" $?
MOPTS=$(echo "$HTML" | grep -oE 'data-boards="[^"]*"' | head -1 | sed 's/^data-boards="//; s/"$//')
echo "$MOPTS" | grep -q "$DST"; ck "move dialog data-boards lists the other board ($DST)" $?
echo "$MOPTS" | grep -q "$SRC"; ck "move dialog data-boards flags the current board ($SRC)" $?
echo "$HTML" | grep -q "data-current-board-id=\"$SRC\""; ck "move dialog data-current-board-id is set" $?

# ---------------------------------------------------------------------------
# [6] Happy path → 200, card on DST board/lane B, source board GET → 404,
#     card_moved_board activity row on the card
# ---------------------------------------------------------------------------
RESP=$(curl -s -w '%{http_code}' $H -b "$COOKIE" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -X POST $B/v1/cards/$CARD/move-to-board \
  -d '{"board_id": '"$DST"', "lane_id": '"$LB"'}')
CODE="${RESP: -3}"
BODY="${RESP%$CODE}"
[ "$CODE" = "200" ]; ck "move happy path → 200 (got $CODE)" $?
echo "$BODY" | python3 -c "
import json, sys
card = json.load(sys.stdin)['card']
assert card['id'] == $CARD, card
assert str(card['lane_id']) == '$LB', ('wrong lane', card)
"; ck "response card id + lane = destination lane B" $?

# Card is GONE from the source board; present on the destination board.
SRCBOARD=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE" "$B/v1/boards/$SRC?include_lanes=true")
echo "$SRCBOARD" | grep -q "$CARD"; [ $? -ne 0 ]; ck "card no longer on source board" $?
DSTBOARD=$(curl -s $H -b "$COOKIE" "$B/v1/boards/$DST?include_lanes=true")
echo "$DSTBOARD" | grep -q "$CARD"; ck "card now on destination board" $?

# Activity feed has the card_moved_board row with the from_board snapshot.
FEED=$(curl -s $H -b "$COOKIE" "$B/v1/cards/$CARD/activity?limit=5")
echo "$FEED" | python3 -c "
import json, sys
items = json.load(sys.stdin)['items']
row = next((r for r in items if r['event'] == 'card_moved_board'), None)
assert row, 'no card_moved_board row'
d = row['detail']
assert d['from_board']['id'] == $SRC, d
assert d['to_lane']['id'] == $LB, d
assert 'to_board' not in d, 'to_board must be omitted from the detail'
"; ck "history feed: card_moved_board row + from_board snapshot" $?

echo ""
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
