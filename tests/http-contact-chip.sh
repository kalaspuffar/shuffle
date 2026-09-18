#!/usr/bin/env bash
# HTTP E2E for USER-04 / USER-01 contact-surfacing (spec v1.16 §5.24).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1).
#
# Contract under test:
#   - GET /v1/users/{id}  — same-org viewer → 200 with "email":null;
#                           cross-org viewer → 404 (never 403);
#                           admin & self → 200 with email present (unchanged)
#   - GET /v1/cards/{id}  — assigned_users rows carry phone/location,
#                           and the assignee contact is visible to a
#                           same-org board member (org-visible board)
#
# Test-safety invariant (Daniel 2026-09-18, permanent): NOTHING here reads,
# writes, or mints-a-session-for user 1 (Daniel) or user 71 (danielp).
# Actors: mya (4, admin, read-only here) + two fixture users (created and
# deleted below), one a same-org member, one in a separate org.
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$1" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $2"; else FAIL=$((FAIL+1)); echo "FAIL  $2"; fi; }

BODYF=$(mktemp)
SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "${SID:-}" ] || { rm -f "$BODYF"; echo "failed to mint mya session (admin actor)"; exit 1; }
COOKIE="shuffle_session=$SID"

ALICE=""; BOB=""; CX=""; SIDBOB=""; SIDCX=""; BRDBRD=""; LANE=""; CARD=""
cleanup() {
  rm -f "$BODYF"
  php "$P" cleanup "$SID"     >/dev/null 2>&1
  [ -n "$SIDBOB" ] && php "$P" cleanup "$SIDBOB" >/dev/null 2>&1
  [ -n "$SIDCX"  ] && php "$P" cleanup "$SIDCX"  >/dev/null 2>&1
  # Delete fixtures in FK order (boards reference users; users FK orgs ON DELETE SET NULL — safe).
  php -r '
    require "include/bootstrap.php";
    $a = (int)($argv[1] ?? 0); $b = (int)($argv[2] ?? 0);
    if ($a) { $db->execute("DELETE FROM users WHERE id = ?", [$a]); }
    if ($b) { $db->execute("DELETE FROM users WHERE id = ?", [$b]); }
  ' "$ALICE" "$BOB" >/dev/null 2>&1
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Fixtures, created as mya (4) via PHP bootstrap:
#   ALICE — org 1, member, phone+location set
#   BOB   — org 1, member (same-org viewer)
#   CX    — org 2, member (cross-org viewer) — org 2 created for isolation,
#            deleted last (ON DELETE SET NULL unlinks users, fine)
#   BRDBRD — org-visible board (org 1) with one card assigned to ALICE + BOB
# ---------------------------------------------------------------------------
FIXT=$(php -r '
  require "include/bootstrap.php";
  $um = new \Shuffle\Model\User($db);
  $bm = new \Shuffle\Model\Board($db);
  $lm = new \Shuffle\Model\Lane($db);
  $cm = new \Shuffle\Model\Card($db);
  $base = "cchttp-" . substr(bin2hex(random_bytes(4)), 0, 8);

  // Org 2 (cross-org isolation). organizations(name) only.
  $db->execute("INSERT INTO organizations (name) VALUES (?)", ["CC Fixture Org B"]);
  $org2 = (int)$db->lastInsertId();

  $alice = $um->create([
    "username" => $base . "alice", "password_hash" => password_hash("fx-1", PASSWORD_ARGON2ID),
    "name" => "Alice Chip", "email" => $base . "alice@ex.test",
    "organization_id" => 1, "role" => "member", "status" => "active",
  ]);
  $um->update($alice, ["phone" => "555-0141", "location" => "Stockholm"]);

  $bob = $um->create([
    "username" => $base . "bob", "password_hash" => password_hash("fx-1", PASSWORD_ARGON2ID),
    "name" => "Bob Chip", "email" => $base . "bob@ex.test",
    "organization_id" => 1, "role" => "member", "status" => "active",
  ]);

  $cx = $um->create([
    "username" => $base . "cx", "password_hash" => password_hash("fx-1", PASSWORD_ARGON2ID),
    "name" => "Cross Org", "email" => $base . "cx@ex.test",
    "organization_id" => $org2, "role" => "member", "status" => "active",
  ]);

  // Board visible to org 1 members (+ creator) — the cross-org user CANNOT
  // see it, so the card endpoint is 404 for CX on board-level grounds
  // (expected for the [5] board-payload check — we assert 404 there).
  $board = $bm->create(["title" => "CC Board " . $base, "visibility" => "organization", "created_by" => 4, "organization_ids" => [1]]);
  $lane  = $lm->create(["board_id" => $board, "title" => "Inbox", "position" => 1000]);
  $card  = $cm->create(["lane_id" => $lane, "title" => "CC HTTP card", "created_by" => 4]);
  $db->execute("INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)", [$card, $alice]);
  $db->execute("INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)", [$card, $bob]);

  echo "$alice $bob $cx $org2 $board $lane $card";
')
[ -n "${FIXT:-}" ] || { echo "fixture creation failed"; exit 1; }
read -r ALICE BOB CX ORG2 BRDBRD LANE CARD <<<"$FIXT"

SIDBOB=$(php "$P" mint "$BOB" 2>/dev/null) || SIDBOB=""
SIDCX=$(php  "$P" mint "$CX"  2>/dev/null) || SIDCX=""
COB="shuffle_session=$SIDBOB"
CCX="shuffle_session=$SIDCX"

# ============================================================================
# [1] Same-org (BOB → ALICE): 200, phone/location present, "email":null
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COB" "$B/v1/users/$ALICE")
[ "$CODE" = "200" ]; CK "$?" "[1] same-org GET user -> 200 (got $CODE)"
grep -q '"phone":"555-0141"' "$BODYF"; CK "$?" "[1] phone visible to same-org"
grep -q '"location":"Stockholm"' "$BODYF"; CK "$?" "[1] location visible to same-org"
grep -q '"email":null' "$BODYF"; CK "$?" "[1] email is JSON null for same-org"

# ============================================================================
# [2] Cross-org (CX → ALICE): 404 (never 403 — no enumeration)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$CCX" "$B/v1/users/$ALICE")
[ "$CODE" = "404" ]; CK "$?" "[2] cross-org GET user -> 404 (got $CODE)"

# ============================================================================
# [3] Admin (mya 4 → ALICE): 200, email PRESENT (§5.22 unchanged)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COOKIE" "$B/v1/users/$ALICE")
[ "$CODE" = "200" ]; CK "$?" "[3] admin GET user -> 200 (got $CODE)"
grep -q '"email":"cchttp-[a-z0-9]*alice@ex.test"' "$BODYF"; CK "$?" "[3] email present for admin"
grep -q '"phone":"555-0141"' "$BODYF"; CK "$?" "[3] phone present for admin"

# ============================================================================
# [3b] Self (ALICE → ALICE): 200, email present (self contract unchanged)
# ============================================================================
SIDAL=$(php "$P" mint "$ALICE" 2>/dev/null) || SIDAL=""
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "shuffle_session=$SIDAL" "$B/v1/users/$ALICE")
[ "$CODE" = "200" ]; CK "$?" "[3b] self GET user -> 200 (got $CODE)"
grep -q '"email":"cchttp-[a-z0-9]*alice@ex.test"' "$BODYF"; CK "$?" "[3b] email present for self"
php "$P" cleanup "$SIDAL" >/dev/null 2>&1

# ============================================================================
# [4] Card payload (BOB, same org, member on org-visible board):
#     200 + assigned_users[alice] carries phone+location
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COB" "$B/v1/cards/$CARD")
[ "$CODE" = "200" ]; CK "$?" "[4] same-org GET card -> 200 (got $CODE)"
grep -q "\"phone\":\"555-0141\"" "$BODYF"; CK "$?" "[4] card payload: alice phone visible"
grep -q "\"location\":\"Stockholm\"" "$BODYF"; CK "$?" "[4] card payload: alice location visible"

# ============================================================================
# [5] Cross-org card access: BOARD-04b — CX cannot see an org-1 board → 404
#     (card endpoint never 403-leaks; the org-scope scrub is covered by the
#      CLI suite — a member of org 1 viewing a member of org 1's card on an
#      org-2-shared board is the only real cross-org scrub case, out of v1).
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$CCX" "$B/v1/cards/$CARD")
[ "$CODE" = "404" ]; CK "$?" "[5] cross-org GET card -> 404 board-isolation (got $CODE)"

# ============================================================================
# [6] Rendered chip: board page for BOB carries the contact tooltip;
#     the tooltip string is the template contract (name · phone · location)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COB" "$B/board.php?id=$BRDBRD")
[ "$CODE" = "200" ]; CK "$?" "[6] same-org board renders (got $CODE)"
# The chip span is multi-line HTML — flatten to one line before matching
# class + title on the SAME element (the contact token is unique, the
# class scope rules out a coincidental string elsewhere).
tr -d '\n' < "$BODYF" | grep -q 'class="card-assignee-avatar"[^>]*title="Alice Chip · 555-0141 · Stockholm"'; CK "$?" "[6] chip tooltip: name · phone · location"
tr -d '\n' < "$BODYF" | grep -q 'class="card-assignee-avatar"[^>]*title="Bob Chip"'; CK "$?" "[6] bare assignee: name-only tooltip (no contact = zero change)"

# ============================================================================
# [7] Board page for mya (admin, creator — sees everything): tooltip present
#     (admin pass-through)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COOKIE" "$B/board.php?id=$BRDBRD")
[ "$CODE" = "200" ]; CK "$?" "[7] admin board renders (got $CODE)"
tr -d '\n' < "$BODYF" | grep -q 'class="card-assignee-avatar"[^>]*title="Alice Chip · 555-0141 · Stockholm"'; CK "$?" "[7] admin sees contact in chip"

# ============================================================================
# [8] Cleanup (FK order: assignments → cards → lanes → board orgs → board)
# ============================================================================
php -r '
  require "include/bootstrap.php";
  $card=(int)($argv[1]??0); $lane=(int)($argv[2]??0); $board=(int)($argv[3]??0); $org2=(int)($argv[4]??0);
  $db->execute("DELETE FROM card_assignments WHERE card_id = ?", [$card]);
  $db->execute("DELETE FROM cards WHERE id = ?", [$card]);
  $db->execute("DELETE FROM lanes WHERE board_id = ?", [$board]);
  $db->execute("DELETE FROM board_organizations WHERE board_id = ?", [$board]);
  $db->execute("DELETE FROM boards WHERE id = ?", [$board]);
  $db->execute("DELETE FROM organizations WHERE id = ?", [$org2]);
' "$CARD" "$LANE" "$BRDBRD" "$ORG2" >/dev/null 2>&1

echo ""
echo "$PASS checks, $FAIL failures"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
