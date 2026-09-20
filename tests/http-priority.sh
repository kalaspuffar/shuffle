#!/usr/bin/env bash
# HTTP E2E for PRIO-15 — positional inbox→priority drop (live Apache).
# Runs against shuffle.ea.org (http://127.0.0.1).
#
# Test-safety invariant (Daniel 2026-09-03): NEVER user id 1, and mya's
# (id 4) data is never mutated. All round-trips act on a dedicated
# fixture user FX (created + deleted here, session minted via
# tests/_rt_session.php). FX owns a private board with a Work lane,
# a Done lane, and 3 assigned cards.
#
# Exercises (contract from SPECIFICATION v1.19 §5.13):
#   [1]  unauth GET  /v1/priority            → 401
#   [2]  unauth POST /v1/priority/inbox/...  → 403 (CSRF gate precedes auth)
#   [3]  auth GET                                   → 200 {inbox,prioritized}
#   [4]  POST no body                             → 200, appends at bottom
#   [5]  POST {after_card_id: null}               → 200, inserted at TOP
#   [6]  POST {after_card_id: <id>}               → 200, inserted after <id>
#   [7]  POST {after_card_id: self}               → 400 (list unchanged)
#   [8]  POST {after_card_id: unknown}            → 400 (list unchanged)
#   [9]  POST {after_card_id: non-numeric}        → 400
#   [9b] POST {after_card_id: 0}                  → 400
#   [10] POST Done-lane card                      → 409
#   [11] POST already-prioritized + no body       → 200 no-op, position kept
#   [12] POST already-prioritized + anchor        → 200, repositioned
#   [13] DELETE                                   → 204, card back in inbox
#   [14] priority.php renders                     → 200 + inbox/prioritized
#
# Cleanup: the fixture user's rows are cascade-deleted when the user is
# deleted; the board (not FK-linked to the user as a parent) is dropped
# explicitly, which cascades lanes → cards → assignments/prio rows.
#
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

BODYF=$(mktemp)
FX=""
SIDFX=""

cleanup() {
  rm -f "$BODYF"
  [ -n "${SIDFX:-}" ] && php "$P" cleanup "$SIDFX" >/dev/null 2>&1
  # Fixture user first: FKs (card_assignments, user_prio, sessions,
  # card/checklist/attachment refs) cascade off it. The board + lanes +
  # cards remain — drop the board (cascades lanes/cards/assignments).
  if [ -n "${FX:-}" ]; then
    php -r '
      require "include/bootstrap.php";
      foreach ($db->fetchAll("SELECT id FROM boards WHERE created_by = ?", [$argv[1]]) as $row) {
          $db->execute("DELETE FROM boards WHERE id = ?", [(int) $row["id"]]);
      }
      (new \Shuffle\Model\User($db))->delete((int) $argv[1]);
    ' "$FX" >/dev/null 2>&1
  fi
}
trap cleanup EXIT

# ------------------------------------------------------------------
# Fixture user + board + lanes + cards (all created + assigned to FX)
# Prints one canonical line: "<fxId> <c1>,<c2>,<c3> <doneCardId>"
# ------------------------------------------------------------------
FIXOUT=$(php -r '
  require "include/bootstrap.php";
  $um = new \Shuffle\Model\User($db);
  $tag = substr(bin2hex(random_bytes(4)), 0, 8);
  $fx = $um->create([
    "username"        => "prio15fx-$tag",
    "password_hash"   => password_hash("fx", PASSWORD_ARGON2ID),
    "name"            => "Prio15 Fixture",
    "email"           => "prio15fx-$tag@example.test",
    "role"            => "member",
    "organization_id" => 1,
    "status"          => "active",
  ]);
  $b = new \Shuffle\Model\Board($db);
  $board = $b->create(["title" => "PRIO15-FX-" . $tag, "created_by" => (int) $fx]);
  $lm = new \Shuffle\Model\Lane($db);
  $work = $lm->create(["board_id" => (int) $board, "title" => "Work",   "icon" => null, "position" => 1000]);
  $done = $lm->create(["board_id" => (int) $board, "title" => "Done",   "icon" => null, "position" => 2000]);
  $cm = new \Shuffle\Model\Card($db);
  $ids = [];
  for ($i = 1; $i <= 3; $i++) {
      $id = $cm->create(["lane_id" => (int) $work, "title" => "prio15-fx-$i", "description" => "", "due_date" => null, "created_by" => (int) $fx]);
      $db->execute("INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)", [(int) $id, (int) $fx]);
      $ids[] = (int) $id;
  }
  $doneCard = $cm->create(["lane_id" => (int) $done, "title" => "prio15-fx-done", "description" => "", "due_date" => null, "created_by" => (int) $fx]);
  $db->execute("INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)", [(int) $doneCard, (int) $fx]);
  echo $fx . " " . implode(",", $ids) . " " . (int) $doneCard . "\n";
')
# The fixture PHP printed: "<fxId> <c1>,<c2>,<c3> <doneCardId>" — the first
# line is the canonical line (earlier echo output, if any, precedes it —
# use the line that parses cleanly).
FIXLINE="$(echo "$FIXOUT" | awk '/^[0-9]+ [0-9]+,[0-9]+,[0-9]+ [0-9]+$/')"
set -- $FIXLINE
FX=${1:-}; C1=${2%%,*}; C1REST=${2#*,}; C2=${C1REST%%,*}; C2REST=${C1REST#*,}; C3=${C2REST%%,*}; DONECARD=${3:-}
[ -n "${FX:-}" ] && [ -n "${C1:-}" ] && [ -n "${C2:-}" ] && [ -n "${C3:-}" ] && [ -n "${DONECARD:-}" ] \
  || { echo "FAILED: fixture setup output: $FIXOUT"; exit 1; }

SIDFX=$(php "$P" mint "$FX" 2>/dev/null || true)
[ -n "${SIDFX:-}" ] || { echo "FAILED: mint FX session"; exit 1; }

# json <METHOD> <url> <sid> <bodyOut> [jsonBody]; BODY/STATUS come back in
# $BODYF / $CODE. sid "" = unauth probe.
jsonm() {
  local method=$1 url=$2 sid=$3 body=${4:-}
  CODE=$(php "$P" json "$method" "$url" "$sid" "$BODYF" "$body" 2>/dev/null)
}

# ------------------------------------------------------------------
# [1] Unauth GET → 401
# ------------------------------------------------------------------
jsonm GET "$B/v1/priority" "" ""
CK "[1] unauth GET -> 401 (got $CODE)" "$([ "$CODE" = 401 ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [2] Unauth POST → 403 (CSRF gate on mutations)
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C1" "" "{}"
CK "[2] unauth POST -> 403 CSRF (got $CODE)" "$([ "$CODE" = 403 ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [3] Auth GET → 200, 3 inbox cards / 0 prioritized
# ------------------------------------------------------------------
jsonm GET "$B/v1/priority" "$SIDFX" ""
INBOX_N=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo is_array($d) ? count($d["inbox"] ?? []) : -1;' "$BODYF")
PRIO_N=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo is_array($d) ? count($d["prioritized"] ?? []) : -1;' "$BODYF")
CK "[3] GET 200 + 3 inbox / 0 prioritized (got $CODE, inbox=$INBOX_N, prio=$PRIO_N)" "$([ "$CODE" = 200 ] && [ "$INBOX_N" = 3 ] && [ "$PRIO_N" = 0 ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [4] POST no body → 200, appended at bottom (only card in the list)
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C1" "$SIDFX" "{}"
LAST=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); $p=$d["prioritized"] ?? []; echo $p ? (int) end($p)["card_id"] : -1;' "$BODYF" 2>/dev/null)
# the response body here is the POST's own JSON, not a list — re-GET:
jsonm GET "$B/v1/priority" "$SIDFX" ""
LAST=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); $p=$d["prioritized"] ?? []; echo $p ? (int) end($p)["card_id"] : -1;' "$BODYF")
CK "[4] no-body POST -> 200, $C1 in prioritized (got $CODE)" "$([ "$LAST" = "$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [5] POST {after_card_id: null} → 200, C2 at TOP
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C2" "$SIDFX" '{"after_card_id":null}'
jsonm GET "$B/v1/priority" "$SIDFX" ""
ORDER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[5] after:null -> 200, order $C2,$C1 (got: $ORDER)" "$([ "$ORDER" = "$C2,$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [6] POST {after_card_id: C2} → 200, C3 right after C2
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C3" "$SIDFX" "{\"after_card_id\":$C2}"
jsonm GET "$B/v1/priority" "$SIDFX" ""
ORDER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[6] after:$C2 -> 200, order $C2,$C3,$C1 (got: $ORDER)" "$([ "$ORDER" = "$C2,$C3,$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [7] Self-anchor → 400, list unchanged
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C2" "$SIDFX" "{\"after_card_id\":$C2}"
POSTCODE=$CODE
jsonm GET "$B/v1/priority" "$SIDFX" ""
ORDER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[7] self-anchor -> 400 + unchanged (POST $POSTCODE, order $ORDER)" "$([ "$POSTCODE" = 400 ] && [ "$ORDER" = "$C2,$C3,$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [8] Unknown anchor → 400, list unchanged
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C2" "$SIDFX" '{"after_card_id":99999999}'
POSTCODE=$CODE
jsonm GET "$B/v1/priority" "$SIDFX" ""
ORDER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[8] unknown anchor -> 400 + unchanged (POST $POSTCODE, order $ORDER)" "$([ "$POSTCODE" = 400 ] && [ "$ORDER" = "$C2,$C3,$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [9] Non-numeric anchor → 400   /   [9b] zero → 400
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C2" "$SIDFX" '{"after_card_id":"not-a-number"}'
CK "[9] non-numeric anchor -> 400 (got $CODE)" "$([ "$CODE" = 400 ] && echo 0 || echo 1)"
jsonm POST "$B/v1/priority/inbox/$C2" "$SIDFX" '{"after_card_id":0}'
CK "[9b] zero anchor -> 400 (got $CODE)" "$([ "$CODE" = 400 ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [10] Done-lane card → 409
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$DONECARD" "$SIDFX" "{}"
CK "[10] Done-lane card -> 409 (got $CODE)" "$([ "$CODE" = 409 ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [11] POST already-prioritized + NO body → 200 no-op, position kept
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C3" "$SIDFX" "{}"
jsonm GET "$B/v1/priority" "$SIDFX" ""
ORDER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[11] repeat no-body -> 200 no-op, order unchanged (got: $ORDER)" "$([ "$ORDER" = "$C2,$C3,$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [12] POST already-prioritized + explicit anchor → 200 reposition
#      move C3 (currently 2nd) after C1 -> C2, C1, C3
# ------------------------------------------------------------------
jsonm POST "$B/v1/priority/inbox/$C3" "$SIDFX" "{\"after_card_id\":$C1}"
jsonm GET "$B/v1/priority" "$SIDFX" ""
ORDER=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[12] repeat + anchor -> 200 reposition, order $C2,$C1,$C3 (got: $ORDER)" "$([ "$ORDER" = "$C2,$C1,$C3" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [13] DELETE → 204, card back in inbox
#      (state: prioritized = C2, C1, C3 after [12]; C1/C2 stay put)
# ------------------------------------------------------------------
jsonm DELETE "$B/v1/priority/inbox/$C3" "$SIDFX" ""
DEL=$CODE
jsonm GET "$B/v1/priority" "$SIDFX" ""
INBOX_IDS=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["inbox"] ?? []));' "$BODYF")
PRIO_IDS=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", array_map(fn($i)=>(int)$i["card_id"], $d["prioritized"] ?? []));' "$BODYF")
CK "[13] DELETE $DEL + C3 back in inbox (inbox: $INBOX_IDS, prio: $PRIO_IDS)" "$([ "$INBOX_IDS" = "$C3" ] && [ "$PRIO_IDS" = "$C2,$C1" ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
# [14] Render — /priority.php 200 + expected markers (session FX)
# ------------------------------------------------------------------
CODE=$(php "$P" http "$B/priority.php" "$SIDFX" "$BODYF" 2>/dev/null)
grep -q 'priority-inbox-section' "$BODYF" && grep -q 'priority-prioritized-section' "$BODYF" && grep -q 'priority-item--reorderable' "$BODYF"
CK "[14] priority.php renders 200 + both sections (got $CODE)" "$([ "$CODE" = 200 ] && echo 0 || echo 1)"

# ------------------------------------------------------------------
echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
