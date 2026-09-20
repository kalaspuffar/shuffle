#!/usr/bin/env bash
# HTTP E2E for NOTIF-06 email notifications (spec v1.18 §5.26).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1).
#
# Test-safety invariant (Daniel 2026-09-03): NEVER touch the Daniel account
# (user id 1) or mutate mya's (id 4) stored data. All WRITE-path tests use a
# dedicated fixture user (random username, created + deleted here, session
# minted via tests/_rt_session.php). Render checks are GET-only.
#
# The POST /v1/me/test-email call goes through the REAL Mailer + SMTP
# configured on this host. The recipient is a throwaway <fx>@example.test
# address (a reserved, non-deliverable domain), so worst case the relay
# returns 502 — both 202 and 502 are valid, honest outcomes; we assert the
# envelope matches the code, not the code matches the relay.
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

BODYF=$(mktemp)
# mya (4) — used for UNAUTH probes + read-only render checks only.
SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "${SID:-}" ] || { rm -f "$BODYF"; echo "failed to mint mya session"; exit 1; }
COMYA="shuffle_session=$SID"

FX=""
SIDFX=""
cleanup() {
  rm -f "$BODYF"
  php "$P" cleanup "$SID"  >/dev/null 2>&1
  [ -n "${SIDFX:-}" ] && php "$P" cleanup "$SIDFX" >/dev/null 2>&1
  # Clean up any notifications created during the test.
  [ -n "${FX:-}" ] && php -r 'require "include/bootstrap.php"; $db->execute("DELETE FROM notifications WHERE user_id = ?", [$argv[1]]);' "$FX" >/dev/null 2>&1
  [ -n "${FX:-}" ] && php -r 'require "include/bootstrap.php"; (new \Shuffle\Model\User($db))->delete('"$FX"');' >/dev/null 2>&1
}
trap cleanup EXIT

read_csrf() {
  php -r '
    require "include/bootstrap.php";
    $row = $db->fetch("SELECT data FROM sessions WHERE id = ?", [$argv[1] ?? ""]);
    if ($row && preg_match("/csrf_token\|s:64:\"([a-f0-9]{64})\"/", $row["data"] ?? "", $m)) { echo $m[1]; }
  ' "$1" 2>/dev/null
}
CSRF=$(read_csrf "$SID")
CH="X-CSRF-Token: $CSRF"

# ============================================================================
# [1] Unauthenticated POST /v1/me/test-email → 403 (CSRF gate before auth)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X POST -H "$H" \
  -H 'Content-Type: application/json' \
  -b 'shuffle_session=__unauth_probe__' -d '{}' \
  "$B/v1/me/test-email")
[ "$CODE" = "403" ]
CK "[1] unauth POST /v1/me/test-email -> 403 CSRF gate (got $CODE)" "$?"

# ============================================================================
# [2] Fixture user — the ACTOR for all write-path tests (never user 1 / 4)
# ============================================================================
FX=$(php -r '
require "include/bootstrap.php";
$um = new \Shuffle\Model\User($db);
$name = "httpe-".substr(bin2hex(random_bytes(4)),0,8);
$u = $um->create([
  "username"=>$name,"password_hash"=>password_hash("fx-pass-1",PASSWORD_ARGON2ID),
  "name"=>"HTTP Notif Fixture","email"=>$name."@example.test",
  "role"=>"member","status"=>"active",
]);
$db->execute("UPDATE users SET organization_id = 1 WHERE id = ?", [$u]);
echo $u;' 2>/dev/null)
[ -n "${FX:-}" ] || { echo "failed to create fixture user"; exit 1; }

SIDFX=$(php "$P" mint "$FX" 2>/dev/null) || true
[ -n "${SIDFX:-}" ] || { echo "failed to mint fixture session"; exit 1; }
COOKIEX="shuffle_session=$SIDFX"
CSRFX=$(read_csrf "$SIDFX")
CHFX="X-CSRF-Token: $CSRFX"

# ============================================================================
# [3] PUT /v1/me — email_notifications flag round-trip (the SELF path)
# ============================================================================
# Baseline: column exists and defaults 0 (new user).
BASE=$(php -r 'require "include/bootstrap.php"; echo (int)($db->fetch("SELECT email_notifications FROM users WHERE id = ?", ['"$FX"'])["email_notifications"] ?? -1);')
[ "$BASE" = "0" ]
CK "[3] new fixture flag defaults OFF (got $BASE)" "$?"

CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CHFX" \
  -H 'Content-Type: application/json' -b "$COOKIEX" \
  -d '{"email_notifications":true,"bio":"opted in via http e2e"}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[3] PUT /v1/me {email_notifications:true} -> 200 (got $CODE)" "$?"
grep -q '"email_notifications":1' "$BODYF"
CK "[3] flag now 1 in response" "$?"
grep -q '"bio":"opted in via http e2e"' "$BODYF"
CK "[3] bio field-only preserved alongside the flag" "$?"

NOW=$(php -r 'require "include/bootstrap.php"; echo (int)($db->fetch("SELECT email_notifications FROM users WHERE id = ?", ['"$FX"'])["email_notifications"] ?? -1);')
[ "$NOW" = "1" ]
CK "[3] flag persisted to DB = 1 (got $NOW)" "$?"

# Flip back OFF (restores the fixture to default).
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CHFX" \
  -H 'Content-Type: application/json' -b "$COOKIEX" \
  -d '{"email_notifications":false}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[3] PUT /v1/me {email_notifications:false} -> 200 (got $CODE)" "$?"
GREPNOW=$(php -r 'require "include/bootstrap.php"; echo (int)($db->fetch("SELECT email_notifications FROM users WHERE id = ?", ['"$FX"'])["email_notifications"] ?? -1);')
[ "$GREPNOW" = "0" ]
CK "[3] flag flipped back to 0 (got $GREPNOW)" "$?"

# Non-boolean flag → 400 (service contract).
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CHFX" \
  -H 'Content-Type: application/json' -b "$COOKIEX" \
  -d '{"email_notifications":"yes"}' \
  "$B/v1/me")
[ "$CODE" = "400" ]
CK "[3] non-boolean email_notifications -> 400 (got $CODE)" "$?"

# ============================================================================
# [4] POST /v1/me/test-email — real Mailer + SMTP path
#     The recipient is a fixture <random>@example.test (reserved, non-routable
#     domain), so the relay either accepts it into its outbound queue (202) or
#     rejects it (502). BOTH are honest outcomes; we assert the ENVELOPE.
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X POST -H "$H" -H "$CHFX" \
  -H 'Content-Type: application/json' -b "$COOKIEX" -d '{}' \
  "$B/v1/me/test-email")
if [ "$CODE" = "202" ]; then
  grep -q '"status":"queued"' "$BODYF"
  CK "[4] test-email 202 -> \"status\":\"queued\" (envelope correct)" "$?"
elif [ "$CODE" = "502" ]; then
  grep -q '"error":"smtp_unavailable"' "$BODYF"
  CK "[4] test-email 502 -> \"error\":\"smtp_unavailable\" (envelope correct; relay refused $FX)" "$?"
else
  CK "[4] test-email envelope — expected 202 or 502 (got $CODE; body: $(head -c 200 "$BODYF"))" 1
fi

# ============================================================================
# [5] Profile page render contract (GET — safe, read-only)
# ============================================================================
HTML=$(curl -s -H "$H" -b "$COOKIEX" "$B/profile.php")
echo "$HTML" | grep -q 'id="email-notif-section"'
CK "[5] render: #email-notif-section present" "$?"
echo "$HTML" | grep -q 'id="email-notif-check"'
CK "[5] render: #email-notif-check present" "$?"
echo "$HTML" | grep -q 'id="email-notif-save"'
CK "[5] render: #email-notif-save present" "$?"
echo "$HTML" | grep -q 'id="test-email-btn"'
CK "[5] render: #test-email-btn present" "$?"
echo "$HTML" | grep -q 'Email notifications'
CK "[5] render: section heading 'Email notifications'" "$?"
echo "$HTML" | grep -q 'Send test email'
CK "[5] render: test email button label" "$?"
echo "$HTML" | grep -q 'data-lang='
CK "[5] render: i18n payload (data-lang) present" "$?"
# The new CSS classes are in app.css.
curl -s -H "$H" -b "$COOKIEX" "$B/css/app.css" | grep -q '\.profile-email-notif'
CK "[5] css: .profile-email-notif rules present in app.css" "$?"
# JS syntax sanity.
node --check www/js/profile.js 2>/dev/null
CK "[5] profile.js syntax ok" "$?"

# ============================================================================
# [6] GET /v1/users/{other} — the flag's privacy class (same as email).
#     a) NON-ADMIN same-org viewer (fixture, member, org 1) → mya (admin, org 1):
#          same-org non-admin branch — email + email_notifications both nulled.
#     b) ADMIN viewer (mya) → fixture: admin pass-through — flag is 0/1.
#     Both are non-self, neither touches Daniel (id 1).
# ============================================================================
if [ -n "${SIDFX:-}" ]; then
  CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COOKIEX" \
    "$B/v1/users/4")
  [ "$CODE" = "200" ]
  CK "[6a] non-admin same-org GET /v1/users/4 -> 200 (got $CODE)" "$?"
  grep -q '"email":null' "$BODYF"
  CK "[6a] email scrubbed to null for non-admin same-org (baseline, pre-existing)" "$?"
  if grep -q '"email_notifications":' "$BODYF"; then
    grep -q '"email_notifications":null' "$BODYF"
    CK "[6a] email_notifications scrubbed to null for non-admin same-org" "$?"
  else
    CK "[6a] email_notifications absent (also non-leaking) for non-admin viewer" 0
  fi
fi

CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -b "$COMYA" \
  "$B/v1/users/$FX")
[ "$CODE" = "200" ]
CK "[6b] admin GET /v1/users/{fx} still 200 after column added (got $CODE)" "$?"
grep -q '"email_notifications":[01]' "$BODYF"
CK "[6b] admin payload carries email_notifications as 0/1 (shape intact)" "$?"

# ============================================================================
echo ""
echo "HTTP_NOTIF_EMAIL  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
