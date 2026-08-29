#!/usr/bin/env bash
# HTTP E2E for BOARD-06a/06b — runs against live Apache (shuffle.ea.org).
# Creates a fixture board, exercises API + render, deletes it, verifies cleanup.
set -u
H='-H Host:shuffle.ea.org'
B=http://127.0.0.1

# Resolve a live admin session + CSRF from the DB at runtime (no hardcoded
# tokens). Requires a reachable local shuffle DB and user_id=1 = admin.
SESS=$(cd ~/shuffle && php -r '
  require "include/bootstrap.php";
  $row = $db->fetch("SELECT id, `data` FROM sessions WHERE user_id = 1 ORDER BY last_activity DESC LIMIT 1");
  if (!$row || !preg_match("/csrf_token\|s:64:\"([0-9a-f]{64})\"/", $row["data"], $m)) exit(3);
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
SID2=$(cd ~/shuffle && php -r '
  require "include/bootstrap.php";
  $b = (new \Shuffle\Model\Board($db))->create(["title"=>"Mya HTTP E2E board","visibility"=>"private","created_by"=>1]);
  $l = (new \Shuffle\Model\Lane($db))->create(["board_id"=>$b,"title"=>"Inbox","position"=>1000]);
  (new \Shuffle\Model\Card($db))->create(["lane_id"=>$l,"title"=>"HTTP card","created_by"=>1]);
  echo $b;')
echo "fixture board id: $SID2"

# 1. API: GET /v1/boards includes card_count = 1 for the fixture
API=$(curl -s $H -b "$COOKIE" $B/v1/boards)
echo "$API" | grep -q "\"card_count\"" ; ck "API GET /v1/boards has card_count" $?
echo "$API" | python3 -c "
import json,sys
d=json.load(sys.stdin)
row=[b for b in d['boards'] if b['id']==$SID2][0]
assert row['card_count']==1, row
print('fixture card_count=1 OK')" ; ck "API fixture board card_count == 1" $?

# 2. Admin render: pencil icon + delete slot in edit modal + data-card-count
HTML=$(curl -s $H -b "$COOKIE" "$B/boards.php")
echo "$HTML" | grep -q 'board-card-pencil' ; ck "render: pencil edit button present" $?
echo "$HTML" | grep -q "id=\"board-modal-delete-slot\"" ; ck "render: modal delete slot present (admin)" $?
echo "$HTML" | grep -q "id=\"board-modal-delete\"" ; ck "render: red Delete button in modal footer" $?
echo "$HTML" | grep -q "data-card-count=\"1\"" ; ck "render: pencil carries data-card-count=1" $?
echo "$HTML" | grep -q 'id="board-delete-overlay"' ; ck "render: delete confirmation dialog present" $?

# 3. DELETE /v1/boards/{id} as admin → 204, then board gone
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE" -H "X-CSRF-Token: $CSRF" -X DELETE $B/v1/boards/$SID2)
[ "$CODE" = "204" ]; ck "DELETE /v1/boards/$SID2 -> 204 (got $CODE)" $?
GONE=$(curl -s $H -b "$COOKIE" $B/v1/boards/$SID2)
echo "$GONE" | grep -q '"id"'; [ "$?" -ne 0 ]; ck "board no longer served after delete" $?

# 4. Cleanup: any fixture lane/card residue
LEFT=$(php -r '
  require "include/bootstrap.php";
  echo count((new \Shuffle\Model\Board($db))->countCardsByBoard(['$SID2']));')
[ "$LEFT" = "0" ]; ck "no card residues for fixture board (got map size $LEFT)" $?

echo ""
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
