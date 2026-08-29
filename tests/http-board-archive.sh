#!/usr/bin/env bash
# HTTP E2E for BOARD-06c/06d — runs against live Apache (shuffle.ea.org).
#
# Exercises:
#   [1] Admin render — edit-modal footer has both the red Delete slot and the
#       new Archive soft slot. The archive slot is hidden by default (JS-only
#       reveals it in edit mode). The pencil button carries
#       data-board-archived="0" (or 1) so the JS can flip Archive ↔ Restore.
#   [2] Admin archive API — POST /v1/boards/{id}/archive → 204; the board
#       leaves the default listing and appears under ?include_archived=1.
#   [3] BOARD-06d side effect — user_prio rows for the board's cards are
#       cleared: the card is gone from BOTH lanes of GET /v1/priority
#       immediately after the archive 204.
#   [4] Admin restore — POST /v1/boards/{id}/restore → 204; board back in
#       the default listing; card re-enters inbox (live recompute) but is
#       NOT auto-returned to the prioritized lane.
#   [5] Static render guard — a non-admin render must not contain the
#       archive slot. Done by inspecting the PHP template (same pattern as
#       the delete-slot admin gate in BOARD-06a — see tests/http-board-delete.sh).
#
# Cleanup: deletes any fixture board it creates.
#
# Requires: a live admin session in the DB (user_id=1).
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

SESS=$(resolve_session 1) || { echo "no live admin session — run login first"; exit 1; }
SID_A=$(printf '%s' "$SESS" | head -1)
CSRF_A=$(printf '%s' "$SESS" | tail -1)
COOKIE_A="shuffle_session=$SID_A"

PASS=0; FAIL=0
ck() {
  if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1";
  else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi
}

# ------------------------------------------------------------------
# Fixture: board + In Progress lane + card + assignment + user_prio row
# ------------------------------------------------------------------
read -r FIX_BID FIX_CID <<EOF
$(php -r '
  require "include/bootstrap.php";
  $b = new \Shuffle\Model\Board($db);
  $l = new \Shuffle\Model\Lane($db);
  $c = new \Shuffle\Model\Card($db);
  $bId = $b->create(["title"=>"Mya HTTP-BA-board-$(date +%s)","visibility"=>"private","created_by"=>1]);
  $lId = $l->create(["board_id"=>$bId,"title"=>"In Progress","position"=>1000]);
  $cId = $c->create(["lane_id"=>$lId,"title"=>"Mya HTTP-BA-card","created_by"=>1]);
  $db->execute("INSERT INTO card_assignments (card_id, user_id) VALUES (?,?)", [$cId, 1]);
  (new \Shuffle\Model\UserPrio($db))->add(1, $cId, 1000);
  echo $bId . " " . $cId;')
EOF
echo "fixture: board=$FIX_BID card=$FIX_CID"

# ------------------------------------------------------------------
echo ""
echo "[1] Admin render — archive slot + data-board-archived on pencil"
HTML=$(curl -s $H -b "$COOKIE_A" "$B/boards.php")
echo "$HTML" | grep -q 'id="board-modal-delete-slot"'      ; ck "delete slot present in admin render" $?
echo "$HTML" | grep -q 'id="board-modal-archive-slot"'     ; ck "archive slot present in admin render" $?
echo "$HTML" | grep -q 'id="board-modal-archive"'          ; ck "archive button present in admin render" $?
echo "$HTML" | grep -q 'data-board-archived="0"'           ; ck "pencil carries data-board-archived attribute" $?
# The archive slot starts hidden (JS reveals it in edit mode) and carries
# aria-hidden="true" server-side, exactly like the delete slot pattern.
echo "$HTML" | grep -Eo 'board-modal-archive-slot[^>]*' | grep -q 'aria-hidden="true"' ; ck "archive slot starts hidden + aria-hidden" $?

# ------------------------------------------------------------------
echo ""
echo "[2] Archive API flow"
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" -X POST "$B/v1/boards/$FIX_BID/archive")
[ "$CODE" = "204" ]; ck "POST /v1/boards/$FIX_BID/archive -> 204 (got $CODE)" $?

DEFAULT=$(curl -s $H -b "$COOKIE_A" "$B/v1/boards")
if echo "$DEFAULT" | python3 -c "
import json,sys
d=json.load(sys.stdin)
ids=[b['id'] for b in d['boards']]
sys.exit(0 if int('$FIX_BID') in ids else 1)"; then
  ck "archived board still visible in default listing" 1
else
  ck "archived board gone from default listing" 0
fi

WITH=$(curl -s $H -b "$COOKIE_A" "$B/v1/boards?include_archived=1")
if echo "$WITH" | python3 -c "
import json,sys
d=json.load(sys.stdin)
ids=[b['id'] for b in d['boards']]
sys.exit(0 if int('$FIX_BID') in ids else 1)"; then
  ck "archived board present under ?include_archived=1" 0
else
  ck "archived board missing under ?include_archived=1" 1
fi

# ------------------------------------------------------------------
echo ""
echo "[3] BOARD-06d: user_prio row cleared, card gone from /v1/priority"
PRIO=$(curl -s $H -b "$COOKIE_A" "$B/v1/priority")
if echo "$PRIO" | python3 -c "
import json,sys
d=json.load(sys.stdin)
cid=int('$FIX_CID')
prio_ids=[x['card_id'] for x in d.get('prioritized',[])]
inbox_ids=[x['card_id'] for x in d.get('inbox',[])]
sys.exit(0 if (cid not in prio_ids) and (cid not in inbox_ids) else 1)"; then
  ck "card absent from BOTH prioritized and inbox after archive" 0
else
  ck "card still visible in /v1/priority after archive (BOARD-06d broken)" 1
fi

# ------------------------------------------------------------------
echo ""
echo "[4] Restore API flow"
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" -X POST "$B/v1/boards/$FIX_BID/restore")
[ "$CODE" = "204" ]; ck "POST /v1/boards/$FIX_BID/restore -> 204 (got $CODE)" $?

DEFAULT=$(curl -s $H -b "$COOKIE_A" "$B/v1/boards")
if echo "$DEFAULT" | python3 -c "
import json,sys
d=json.load(sys.stdin)
ids=[b['id'] for b in d['boards']]
sys.exit(0 if int('$FIX_BID') in ids else 1)"; then
  ck "restored board back in default listing" 0
else
  ck "restored board missing from default listing" 1
fi

PRIO=$(curl -s $H -b "$COOKIE_A" "$B/v1/priority")
if echo "$PRIO" | python3 -c "
import json,sys
d=json.load(sys.stdin)
cid=int('$FIX_CID')
prio_ids=[x['card_id'] for x in d.get('prioritized',[])]
inbox_ids=[x['card_id'] for x in d.get('inbox',[])]
# Spec: card re-enters inbox (live recompute), stays out of prioritized.
sys.exit(0 if (cid not in prio_ids) and (cid in inbox_ids) else 1)"; then
  ck "after restore: card in inbox, NOT in prioritized (BOARD-06d)" 0
else
  ck "after restore: card not in inbox / unexpectedly in prioritized" 1
fi

# ------------------------------------------------------------------
echo ""
echo "[5] Static render guard — archive slot is inside the SAME \$isAdmin block as delete"
# Both slots live in the edit-modal footer, inside one
# `<?php if ($isAdmin): ?> ... <?php endif; ?>` scope. Find the
# if($isAdmin) that immediately precedes the modal footer and verify both
# slot IDs appear between it and its matching `<?php endif; ?>`.
php -r '
  $s = file_get_contents("www/boards.php");
  $footerPos    = strpos($s, "<div class=\"modal-footer\">");
  $deletePos    = strpos($s, "board-modal-delete-slot");
  $archivePos   = strpos($s, "board-modal-archive-slot");
  $actionsPos   = strpos($s, "modal-footer-actions");
  if ($footerPos === false || $deletePos === false || $archivePos === false || $actionsPos === false) exit(1);
  // The admin gate (if $isAdmin) must open BEFORE both slots and close
  // AFTER the action-group (Cancel/Save). Find the nearest gate open
  // before the footer and the first gate close after the action group,
  // and assert the containment order:
  //   gate-open < delete-slot < archive-slot < modal-footer-actions < gate-close
  $prefix  = substr($s, 0, $footerPos);
  $gateOpen = strrpos($prefix, "<?php if (\$isAdmin): ?>");
  $gateClose = strpos($s, "<?php endif; ?>", $actionsPos);
  if ($gateOpen === false || $gateClose === false) exit(1);
  $ok = $gateOpen < $deletePos
     && $deletePos < $archivePos
     && $archivePos < $actionsPos
     && $actionsPos < $gateClose;
  exit($ok ? 0 : 1);' ; ck "archive+delete slots render inside the same \$isAdmin gate" $?

# ------------------------------------------------------------------
# Cleanup: delete fixture board (cascades lanes/cards/user_prio)
# ------------------------------------------------------------------
CODE=$(curl -s -o /dev/null -w '%{http_code}' $H -b "$COOKIE_A" -H "X-CSRF-Token: $CSRF_A" -X DELETE "$B/v1/boards/$FIX_BID")
[ "$CODE" = "204" ]; ck "cleanup: DELETE fixture board -> 204 (got $CODE)" $?

# ------------------------------------------------------------------
echo ""
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
