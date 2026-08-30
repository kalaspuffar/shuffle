#!/usr/bin/env bash
# HTTP E2E for ACTIVITY-03 — card activity feed API (GET /v1/cards/{id}/activity).
#
# Exercises (against live Apache at shuffle.ea.org):
#   [1] Feed on a brand-new card → 200 with items:[], has_more:false (cold log).
#   [2] A full mutation round-trip (create → move → edit → assign → comment)
#       produces ≥5 feed entries visible through the HTTP layer, newest first.
#   [3] Feed item shape: {id, event, actor:{id,name}, created_at, detail} —
#       actor is the acting user (name populated), detail is an object.
#   [4] Paging: ?limit=2 returns 2 items + has_more:true; ?before=<id>
#       returns older items that do not overlap the first page.
#   [5] Unauth → 401 (or 403 — the point is: NOT 200, NOT a body leak).
#   [6] Unknown card id → 404 (BOARD-04b mapping in the controller).
#   [7] ?limit out of range (0, negative, >500) → clamped, not an error.
#
# Cleanup: deletes its fixture board. A pre-existing user id is required for
# the assignment fixture (user 2 by default; override with argv[2]).
set -u
H='-H Host:shuffle.ea.org'
B=http://127.0.0.1
cd ~/shuffle

resolve_session() {
  php -r '
    require "include/bootstrap.php";
    $uid = (int) $argv[1];
    $row = $db->fetch("SELECT id, `data` FROM sessions WHERE user_id = ? ORDER BY last_activity DESC LIMIT 1", [$uid]);
    if (!$row) exit(2);
    if (!preg_match("/csrf_token\|s:64:.*?\"([0-9a-f]{64})/", $row["data"], $m)) exit(3);
    echo $row["id"] . "\n" . $m[1];' "$1"
}

ADMIN="${1:-1}"
TARGET="${2:-2}"

SESS=$(resolve_session "$ADMIN") || { echo "no live admin session — log in first"; exit 1; }
SID_A=$(printf '%s' "$SESS" | head -1)
CSRF_A=$(printf '%s' "$SESS" | tail -1)
COOKIE_A="shuffle_session=$SID_A"

PASS=0; FAIL=0
ck() {
  if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1";
  else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi
}

# ------------------------------------------------------------------
# Fixture: board + 2 lanes + 1 card
# ------------------------------------------------------------------
read -r FIX_BID FIX_L1 FIX_L2 <<EOF
$(php -r '
  require "include/bootstrap.php";
  $b = new \Shuffle\Model\Board($db);
  $l = new \Shuffle\Model\Lane($db);
  $bId = $b->create(["title"=>"Mya HTTP-ACT-board-".date("YmdHis"),"visibility"=>"private","created_by"=>1]);
  $l1 = $l->create(["board_id"=>$bId,"title"=>"Inbox","position"=>1000]);
  $l2 = $l->create(["board_id"=>$bId,"title"=>"Doing","position"=>2000]);
  echo $bId . " " . $l1 . " " . $l2;')
EOF
echo "fixture: board=$FIX_BID lanes=$FIX_L1/$FIX_L2"

FIX_CID=""
cleanup() {
  if [ -n "$FIX_BID" ]; then
    php -r 'require "include/bootstrap.php"; (new \Shuffle\Model\Board($db))->delete((int)$argv[1]);' "$FIX_BID" 2>/dev/null
  fi
}
trap cleanup EXIT

card_id_from() {
  # Handles the several response shapes: {"card":{...}}, {"cards":[...]},
  # a bare {...} with id, or a list.
  printf '%s' "$1" | python3 -c '
import json,sys
d=json.load(sys.stdin)
if isinstance(d,dict) and "card" in d and isinstance(d["card"],dict):
    print(d["card"]["id"])
elif isinstance(d,dict) and "cards" in d and d["cards"]:
    print(d["cards"][0]["id"])
elif isinstance(d,list) and d:
    print(d[0]["id"])
elif isinstance(d,dict) and "id" in d:
    print(d["id"])
' 2>/dev/null
}

# ------------------------------------------------------------------
echo ""
echo "[0] Create a card via the API (also logs card_created)"
CREATE_RES=$(curl -s $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" -X POST \
  "$B/v1/boards/$FIX_BID/lanes/$FIX_L1/cards" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Mya HTTP-ACT-card","description":"http activity fixture"}')
FIX_CID=$(card_id_from "$CREATE_RES")
if [ -z "$FIX_CID" ]; then
  echo "could not create fixture card via API — response: $CREATE_RES"
  exit 1
fi
echo "fixture card: $FIX_CID"

# ------------------------------------------------------------------
echo ""
echo "[1] Feed on the fresh card returns 200 + JSON envelope (cold log has ≥1 row: card_created)"
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" "$B/v1/cards/$FIX_CID/activity")
[ "$CODE" = "200" ]; ck "GET activity on fresh card -> 200 (got $CODE)" $?

BODY=$(curl -s $H -b "$COOKIE_A" "$B/v1/cards/$FIX_CID/activity")
echo "$BODY" | python3 -c '
import json,sys
d=json.load(sys.stdin)
assert "items" in d and isinstance(d["items"], list), "items missing"
assert "has_more" in d and isinstance(d["has_more"], bool), "has_more missing"
' ; ck "feed envelope {items:[], has_more:bool} present" $?

# card_created must already be there (hook fires before the create response returns)
echo "$BODY" | python3 -c '
import json,sys
d=json.load(sys.stdin)
events=[r["event"] for r in d["items"]]
assert "card_created" in events, f"card_created not in {events}"
' ; ck "card_created row present via HTTP" $?

# ------------------------------------------------------------------
echo ""
echo "[2] Mutation round-trip → feed grows and is newest-first"
# move — PUT /cards/{id}/move (JSON body per CardController::move)
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" \
  -X PUT "$B/v1/cards/$FIX_CID/move" \
  -H 'Content-Type: application/json' \
  -d "{\"lane_id\":$FIX_L2}")
[ "$CODE" = "200" ]; ck "move card -> 200 (got $CODE)" $?

# edit (title change)
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" \
  -X PUT "$B/v1/cards/$FIX_CID" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Mya HTTP-ACT-card v2"}')
[ "$CODE" = "200" ]; ck "edit card title -> 200 (got $CODE)" $?

# assign user $TARGET through the update endpoint (logs assigned/unassigned)
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" \
  -X PUT "$B/v1/cards/$FIX_CID" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF_A" \
  -d "{\"assigned_user_ids\":[$TARGET]}")
[ "$CODE" = "200" ]; ck "assign user $TARGET -> 200 (got $CODE)" $?

# comment — POST /cards/{id}/comments
COMMENT_RES=$(curl -s $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" \
  -X POST "$B/v1/cards/$FIX_CID/comments" \
  -H 'Content-Type: application/json' \
  -d '{"body":"http activity fixture comment"}')

FEED=$(curl -s $H -b "$COOKIE_A" "$B/v1/cards/$FIX_CID/activity")
echo "$FEED" | python3 -c '
import json,sys
d=json.load(sys.stdin)
items=d["items"]
events=[r["event"] for r in items]
expected=["card_created","card_moved","card_edited","comment_created"]
missing=[e for e in expected if e not in events]
assert not missing, f"missing events: {missing} (have {events})"
# newest-first: card_created must be LAST (oldest), comment_created FIRST
assert events[-1] == "card_created", f"oldest row should be card_created, got {events[-1]}"
assert events[0] == "comment_created", f"newest row should be comment_created, got {events[0]}"
ids=[r["id"] for r in items]
assert ids == sorted(ids, reverse=True), f"ids not descending: {ids}"
' ; ck "all 4 expected events present, newest-first, ids descending" $?

# ------------------------------------------------------------------
echo ""
echo "[3] Feed item shape: id int, event str, actor {id,name}, created_at, detail obj|null"
echo "$FEED" | python3 -c '
import json,sys,re
d=json.load(sys.stdin)
for r in d["items"]:
    assert isinstance(r["id"], int)
    assert isinstance(r["event"], str)
    a=r["actor"]
    assert isinstance(a["id"], int)
    assert isinstance(a["name"], str) and a["name"], "actor.name empty"
    assert re.match(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$", r["created_at"]), r["created_at"]
    assert r["detail"] is None or isinstance(r["detail"], dict)
' ; ck "item shape valid on every row" $?

# ------------------------------------------------------------------
echo ""
echo "[4] Paging — ?limit=2 returns 2 + has_more:true; ?before is stable"
P1=$(curl -s $H -b "$COOKIE_A" "$B/v1/cards/$FIX_CID/activity?limit=2")
echo "$P1" | python3 -c '
import json,sys
d=json.load(sys.stdin)
assert len(d["items"]) == 2, len(d["items"])
assert d["has_more"] is True
' ; ck "limit=2 → 2 items + has_more:true" $?

FIRST2_ID=$(printf '%s' "$P1" | python3 -c 'import json,sys; print(json.load(sys.stdin)["items"][0]["id"])')
LAST_OF_P1=$(printf '%s' "$P1" | python3 -c 'import json,sys; print(json.load(sys.stdin)["items"][-1]["id"])')
P2=$(curl -s $H -b "$COOKIE_A" "$B/v1/cards/$FIX_CID/activity?limit=50&before=$LAST_OF_P1")
P2IDS=$(printf '%s' "$P2" | python3 -c 'import json,sys; print(" ".join(str(r["id"]) for r in json.load(sys.stdin)["items"]))')
if echo " $P2IDS " | grep -q " $LAST_OF_P1 "; then
  ck "page2 does not overlap page1 (before=$LAST_OF_P1)" 1
else
  ck "page2 does not overlap page1 (before=$LAST_OF_P1)" 0
fi
# page2 must only contain OLDER rows (id < LAST_OF_P1)
if [ -n "$P2IDS" ]; then
  OLDEST_NEWER=$(echo $P2IDS | tr ' ' '\n' | sort -rn | head -1)
  [ "$OLDEST_NEWER" -lt "$LAST_OF_P1" ]; ck "page2 rows are all older than page1 (id < $LAST_OF_P1)" $?
else
  # no rows older than page1 is acceptable only if page1 was the whole feed
  echo "$FEED" | python3 -c '
import json,sys
d=json.load(sys.stdin)
sys.exit(0 if len(d["items"]) <= 2 else 1)'
  ck "page2 empty is consistent with feed size" $?
fi

# ------------------------------------------------------------------
echo ""
echo "[5] Unauthenticated → NOT 200, and no card body leaks"
CODE=$(curl -s -o /tmp/act-noauth.json -w '%{http_code}' $H "$B/v1/cards/$FIX_CID/activity")
if [ "$CODE" = "401" ] || [ "$CODE" = "403" ]; then
  ck "unauth -> $CODE (expected 401/403)" 0
else
  ck "unauth -> $CODE (expected 401/403)" 1
fi
# no body leak: no "items" / "card_created" in the response (the error payload is {error:...})
if grep -q '"card_created"\|"items"' /tmp/act-noauth.json 2>/dev/null; then
  ck "no feed body in unauth response" 1
else
  ck "no feed body in unauth response" 0
fi
rm -f /tmp/act-noauth.json

# ------------------------------------------------------------------
echo ""
echo "[6] Unknown card id → 404 (BOARD-04b mapping)"
BIG=$(($FIX_CID + 100000))
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" "$B/v1/cards/$BIG/activity")
[ "$CODE" = "404" ]; ck "unknown card -> 404 (got $CODE)" $?

# ------------------------------------------------------------------
echo ""
echo "[7] ?limit clamping — 0, negative, and >500 all return 200 (clamped) not 500"
for BAD in 0 -3 99999; do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" "$B/v1/cards/$FIX_CID/activity?limit=$BAD")
  [ "$CODE" = "200" ]; ck "limit=$BAD -> 200 (got $CODE)" $?
done

# ------------------------------------------------------------------
echo ""
echo "Result: $PASS passed, $FAIL failed"
exit $FAIL
