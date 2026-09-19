#!/usr/bin/env bash
# HTTP E2E for BOARD REAL-TIME SYNC (RT-04/05/06, SPECIFICATION §5.19) —
# runs against live Apache (shuffle.ea.org).
#
# Session: uses MYA (user 4, role admin — Hermes' own account). A PHP helper
# (tests/_rt_session.php) mints a fresh DB session row for user 4 and drives
# the GET calls (writes the body to a temp file, prints only the status code).
# Daniel's (user 1) admin session is never read — this test creates its own
# session + fixture board and deletes both on exit.
#
# Exercises:
#   [1] GET /v1/boards/{id}/region unauth                  -> 401 (auth gate)
#   [2] GET /v1/boards/{id}/region (mya authed)            -> 200 text/html + lane/card/ghost
#   [3] GET /v1/boards/{id}/region If-None-Match=<version> -> 304 (RT-05 ETag contract)
#   [4] GET /v1/boards/0/region (no such board)            -> 404
#   [5] board page render includes the shared region       -> same renderer as [2]
#   [6] client at version N<server -> If-None-Match: N     -> 200 + fresh fragment (RT-07)
#
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
cd "$SH"

BODYF=$(mktemp)
SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "$SID" ] || { rm -f "$BODYF"; echo "failed to mint mya session"; exit 1; }

PASS=0; FAIL=0; CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

# get <url> [sid] [extra-header]
#   sid default = the mya session; pass "" for an unauthenticated probe.
# Writes body to $BODYF, sets $CODE (int status).
get() {
  local url="$1"; local sid="${2-USE_GLOBAL}"; local hdr="${3:-}"
  local effsid
  [ "$sid" = "USE_GLOBAL" ] && effsid="$SID" || effsid="$sid"
  if [ -n "$hdr" ]; then CODE=$(php "$P" http "$url" "$effsid" "$BODYF" "$hdr" 2>/dev/null)
  else                   CODE=$(php "$P" http "$url" "$effsid" "$BODYF" 2>/dev/null); fi
}

# ---------------------------------------------------------------- fixture (user 4 = mya)
FIX=$(php -r '
  require "include/bootstrap.php";
  $uid = 4;
  $bm = new \Shuffle\Model\Board($db); $lm = new \Shuffle\Model\Lane($db); $cm = new \Shuffle\Model\Card($db);
  $b = $bm->create(["title" => "RT Sync Probe", "visibility" => "private", "created_by" => $uid]);
  $l = $lm->create(["board_id" => $b, "title" => "Inbox", "position" => 1000]);
  $cm->create(["lane_id" => $l, "title" => "RT SYNC CARD", "created_by" => $uid]);
  echo $b;' 2>&1) || { echo "fixture creation failed: $FIX"; rm -f "$BODYF"; php "$P" cleanup "$SID" >/dev/null 2>&1; exit 1; }
BID="$FIX"
echo "fixture board: $BID (owner mya/user4)"

cleanup() {
  php "$P" cleanup "$SID" >/dev/null 2>&1 || true
  php -r '
    require "include/bootstrap.php";
    $b = (int) $argv[1];
    $li = array_map("intval", $db->fetchAll("SELECT id FROM lanes WHERE board_id = ?", [$b]));
    if ($li) { $in = implode(",", $li);
      $ci = array_map("intval", $db->fetchAll("SELECT id FROM cards WHERE lane_id IN ($in)"));
      if ($ci) { $in2 = implode(",", $ci);
        $sqls = ["DELETE FROM comments WHERE card_id IN ($in2)",
                 "DELETE FROM checklist_items WHERE checklist_id IN (SELECT id FROM checklists WHERE card_id IN ($in2))",
                 "DELETE FROM checklists WHERE card_id IN ($in2)",
                 "DELETE FROM attachments WHERE card_id IN ($in2)",
                 "DELETE FROM card_assignments WHERE card_id IN ($in2)",
                 "DELETE FROM card_activity WHERE card_id IN ($in2)",
                 "DELETE FROM cards WHERE id IN ($in2)"];
        foreach ($sqls AS $sql) $db->execute($sql);
      }
    }
    $db->execute("DELETE FROM lanes WHERE board_id = ?", [$b]);
    $db->execute("DELETE FROM board_organizations WHERE board_id = ?", [$b]);
    $db->execute("DELETE FROM boards WHERE id = ?", [$b]);' "$BID" 2>&1 || true
  rm -f "$BODYF"
}
trap cleanup EXIT

# ---------------------------------------------------------------- [1] unauth -> 401
# Helper: sid="" => sends a dummy "no real session" cookie, not the mya one.
get "$B/v1/boards/$BID/region" ""
[ "$CODE" = "401" ]; CK "[1] unauth -> 401 (got $CODE)" $?

# ---------------------------------------------------------------- [2] mya region -> 200 + markup
get "$B/v1/boards/$BID/region"
A=1; grep -q 'class="lane"'     "$BODYF" && A=0
B2=1; grep -q 'RT SYNC CARD'    "$BODYF" && B2=0
C2=1; grep -qE 'class="card'    "$BODYF" && C2=0
D2=1; grep -q 'id="btn-add-lane"' "$BODYF" && D2=0
OK=1; { [ "$CODE" = "200" ] && [ "$A" -eq 0 ] && [ "$B2" -eq 0 ] && [ "$C2" -eq 0 ] && [ "$D2" -eq 0 ]; } && OK=0
CK "[2] mya region = 200 + lane/card/ghost (status=$CODE)" "$OK"

# ---------------------------------------------------------------- [3] If-None-Match -> 304 (RT-05 ETag contract)
# (authenticated; uses mya's session)
get "$B/v1/boards/$BID/version"
VER=$(php -r '$j = json_decode($argv[1], true); echo $j["version"] ?? "";' "$(cat "$BODYF")" 2>/dev/null)
get "$B/v1/boards/$BID/region" "$SID" "If-None-Match: \"$VER\""
[ "$CODE" = "304" ]; CK "[3] If-None-Match=$VER -> 304 (got $CODE)" $?

# ---------------------------------------------------------------- [4] no such board -> 404
get "$B/v1/boards/0/region"
[ "$CODE" = "404" ]; CK "[4] board id 0 -> 404 (got $CODE)" $?

# ---------------------------------------------------------------- [5] board page render uses shared region
get "$B/board.php?id=$BID"
A=1; grep -q 'board-lanes-container' "$BODYF" && A=0
B2=1; grep -q 'RT SYNC CARD'         "$BODYF" && B2=0
OK=1; { [ "$CODE" = "200" ] && [ "$A" -eq 0 ] && [ "$B2" -eq 0 ]; } && OK=0
CK "[5] board page renders shared region (status=$CODE)" "$OK"

# ---------------------------------------------------------------- [6] client BEHIND server -> 200 (RT-07)
# Pins the inverted-ETag contract. The RT-06 bug in the v1.16 client sent
# the TARGET (new) version as If-None-Match, so every sync after an edit
# 304'd "already current" and the tile never refreshed. The honest server-side
# invariant:
#   client at version N, server at N+1
#   → GET /region with If-None-Match: "$N" MUST return 200 + the fresh HTML
#   (NOT the 304 that the client-side inversion would have "predicted").
#
# Steps:
#   a. read the current board version          (call it N)
#   b. bump the version by one card mutation   (server becomes N+1)
#   c. fetch the region with If-None-Match: N
#   d. assert 200 + fresh body (the lanes/card markup is present)
BOARD_VER=$(php -r 'require "include/bootstrap.php"; $b=(int)$argv[1]; echo (new \Shuffle\Model\Board($db))->getVersion($b);' "$BID" 2>&1)
# Bump: rename the fixture card (visible in the fragment, so we can also
# assert we got the FRESH body, not a stale cached one). Board's version IS
# the region's ETag — bump only the board, the card row has no ETag.
php -r '
  require "include/bootstrap.php";
  $b = (int) $argv[1];
  $laneId = (int) $db->fetch("SELECT id FROM lanes WHERE board_id = ?", [$b])["id"];
  $cardId = (int) $db->fetch("SELECT id FROM cards WHERE lane_id = ?", [$laneId])["id"];
  $db->execute("UPDATE cards SET title = \"RT SYNC CARD (bumped)\" WHERE id = ?", [$cardId]);
  (new \Shuffle\Model\Board($db))->incrementVersion($b);
' "$BID" 2>&1 || true
echo "client-etag=$BOARD_VER (server is now $((BOARD_VER + 1)))"
get "$B/v1/boards/$BID/region" "$SID" "If-None-Match: \"$BOARD_VER\""
A=1;  grep -q 'class="lane"'      "$BODYF" && A=0
B2=1; grep -q 'RT SYNC CARD (bumped)' "$BODYF" && B2=0   # proves FRESH body, not 304 / cached
OK=1; { [ "$CODE" = "200" ] && [ "$A" -eq 0 ] && [ "$B2" -eq 0 ]; } && OK=0
CK "[6] client at old version (If-None-Match=\"$BOARD_VER\") with server bumped -> 200 + fresh HTML (got $CODE) — inverted-ETag contract" "$OK"

echo "----------------------------------------"
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
