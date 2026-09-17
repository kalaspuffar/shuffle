#!/usr/bin/env bash
# HTTP E2E for USER-01..03 — profile & password API (spec v1.14 §5.22).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1).
#
# Test-safety invariant (Daniel 2026-09-03): NEVER touch the Daniel account
# (user id 1) or mutate mya's (id 4) stored data. All write-path tests use a
# dedicated fixture user (random username, created + deleted here, session
# minted via tests/_rt_session.php, which itself refuses non-active users).
#
# Sessions:
#   SID  — mya (user 4, ROLE ADMIN): exercises the admin surface + /v1/me
#          self-path render + unauth probes
#   SIDFX — the fixture user (created below): exercises PUT /v1/me + login
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

BODYF=$(mktemp)
SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "${SID:-}" ] || { rm -f "$BODYF"; echo "failed to mint mya session"; exit 1; }
COOKIE="shuffle_session=$SID"
FX=""
SIDFX=""

cleanup() {
  rm -f "$BODYF"
  php "$P" cleanup "$SID"     >/dev/null 2>&1
  [ -n "$SIDFX" ] && php "$P" cleanup "$SIDFX" >/dev/null 2>&1
  [ -n "$FX" ] && php -r 'require "include/bootstrap.php"; (new \Shuffle\Model\User($db))->delete('"$FX"');' >/dev/null 2>&1
}
trap cleanup EXIT

# Read the CSRF token written by _rt_session mint, from the session row.
read_csrf() {
  php -r '
    require "include/bootstrap.php";
    $row = $db->fetch("SELECT data FROM sessions WHERE id = ?", [$argv[1] ?? ""]);
    if ($row && preg_match("/csrf_token\|s:64:\"([a-f0-9]{64})\"/", $row["data"] ?? "", $m)) { echo $m[1]; }
  ' "$1" 2>/dev/null
}
CSRF=$(read_csrf "$SID")
[ -n "${CSRF:-}" ] || { echo "failed to read CSRF token"; exit 1; }
CH="X-CSRF-Token: $CSRF"

# ============================================================================
# [1] Unauthenticated PUT /v1/me → 403 (CSRF gate fires BEFORE auth —
#     established repo convention, see http-card-merge.sh [unauth→403])
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
  -H 'Content-Type: application/json' \
  -b 'shuffle_session=__unauth_probe__' \
  -d '{"name":"unauth"}' \
  "$B/v1/me")
[ "$CODE" = "403" ]
CK "[1] unauth PUT /v1/me -> 403 CSRF gate (got $CODE)" "$?"

# ============================================================================
# Fixture user (never user 1 / user 4) for the write-path tests.
# ============================================================================
FX=$(php -r '
require "include/bootstrap.php";
$um = new \Shuffle\Model\User($db);
$name = "httpfx-" . substr(bin2hex(random_bytes(4)), 0, 8);
$u = $um->create([
  "username"      => $name,
  "password_hash" => password_hash("fx-pass-1", PASSWORD_ARGON2ID),
  "name"          => "HTTP Fixture",
  "email"         => $name . "@example.test",
  "role"          => "member",
  "status"        => "active",
]);
echo $u;')
[ -n "${FX:-}" ] || { echo "failed to create fixture user"; exit 1; }

# ============================================================================
# [2] PUT /v1/users/{fx} (admin) — happy round-trip: phone/location/bio
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"phone":"+46 44455667","location":"Testlandia","bio":"http-e2e profile bio"}' \
  "$B/v1/users/$FX")
[ "$CODE" = "200" ]
CK "[2] admin PUT happy -> 200 (got $CODE)" "$?"
grep -qE '"phone": ?"?\+46 ?44455667' "$BODYF"
CK "[2] phone written" "$?"
grep -q '"location":"Testlandia"' "$BODYF"
CK "[2] location written" "$?"
grep -q '"bio":"http-e2e profile bio"' "$BODYF"
CK "[2] bio written" "$?"

# ============================================================================
# [3] PUT field-only — touching only bio must not clear phone
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"bio":"only bio changed"}' \
  "$B/v1/users/$FX")
[ "$CODE" = "200" ]
CK "[3] bio-only PUT -> 200 (got $CODE)" "$?"
grep -q '"bio":"only bio changed"' "$BODYF"
CK "[3] bio updated" "$?"
grep -qE '"phone": ?"?\+46 ?44455667' "$BODYF"
CK "[3] phone retained (field-only contract)" "$?"

# ============================================================================
# [4] Blank-string clear: phone -> null
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"phone":""}' \
  "$B/v1/users/$FX")
[ "$CODE" = "200" ]
CK "[4] blank phone PUT -> 200 (got $CODE)" "$?"
grep -q '"phone":null' "$BODYF"
CK "[4] phone cleared to null" "$?"

# ============================================================================
# [5] Shape errors on the admin path (same service contract as /v1/me)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"phone":"this-is-definitely-too-long-for-a-32-char-phone-line-value"}' \
  "$B/v1/users/$FX")
[ "$CODE" = "400" ]
CK "[5] over-length phone -> 400 (got $CODE)" "$?"

CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"email":"hacker@example.com"}' \
  "$B/v1/users/$FX")
[ "$CODE" = "400" ]
CK "[5] email present -> 400 (immutable) (got $CODE)" "$?"

# ============================================================================
# [6] PUT /v1/me/self — self path on a real self-actor session (the fixture)
# ============================================================================
SIDFX=$(php "$P" mint "$FX" 2>/dev/null) || true
if [ -n "${SIDFX:-}" ]; then
  CSRFX=$(read_csrf "$SIDFX")
  COOKIEX="shuffle_session=$SIDFX"
  CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "X-CSRF-Token: $CSRFX" \
    -H 'Content-Type: application/json' -b "$COOKIEX" \
    -d '{"bio":"self-service bio"}' \
    "$B/v1/me")
  [ "$CODE" = "200" ]
  CK "[6] self PUT /v1/me -> 200 (got $CODE)" "$?"
  grep -q '"bio":"self-service bio"' "$BODYF"
  CK "[6] self bio written" "$?"
  CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "X-CSRF-Token: $CSRFX" \
    -H 'Content-Type: application/json' -b "$COOKIEX" \
    -d '{"email":"x@example.com"}' \
    "$B/v1/me")
  [ "$CODE" = "400" ]
  CK "[6] self email immutable -> 400 (got $CODE)" "$?"
else
  echo "note: could not mint fixture session — skipping [6]"
fi

# ============================================================================
# [7] Password endpoints over HTTP
# ============================================================================
# POST /v1/admin/users/{fx}/reset-password — short → 400
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X POST -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"new_password":"short"}' \
  "$B/v1/admin/users/$FX/reset-password")
[ "$CODE" = "400" ]
CK "[7] reset short -> 400 (got $CODE)" "$?"

# ... unknown target → 404
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X POST -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"new_password":"valid-pass-99"}' \
  "$B/v1/admin/users/999999/reset-password")
[ "$CODE" = "404" ]
CK "[7] reset unknown id -> 404 (got $CODE)" "$?"

# ... non-admin → 403 (uses the fixture's own session — a member — to hit an
# admin endpoint)
if [ -n "${SIDFX:-}" ]; then
  CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X POST -H "$H" -H "X-CSRF-Token: $(read_csrf "$SIDFX")" \
    -H 'Content-Type: application/json' -b "shuffle_session=$SIDFX" \
    -d '{"new_password":"valid-pass-99"}' \
    "$B/v1/admin/users/$FX/reset-password")
  [ "$CODE" = "403" ]
  CK "[7] non-admin reset -> 403 (got $CODE)" "$?"
fi

# ... happy → 204
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X POST -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"new_password":"reset-pw-99"}' \
  "$B/v1/admin/users/$FX/reset-password")
[ "$CODE" = "204" ]
CK "[7] reset happy -> 204 (got $CODE)" "$?"

# ... login with the NEW password succeeds.
FXUSER=$(php -r 'require "include/bootstrap.php"; echo $db->fetch("SELECT username FROM users WHERE id = ?", ["'"$FX"'"])["username"] ?? "";')
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -X POST \
  -H 'Content-Type: application/json' -b 'shuffle_session=__unauth_probe__' \
  -d '{"username":"'"$FXUSER"'","password":"reset-pw-99"}' \
  "$B/v1/auth/login")
[ "$CODE" = "200" ]
CK "[7] login with new password -> 200 (got $CODE)" "$?"

# ... and the OLD password is now rejected.
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" -X POST \
  -H 'Content-Type: application/json' -b 'shuffle_session=__unauth_probe__' \
  -d '{"username":"'"$FXUSER"'","password":"fx-pass-1"}' \
  "$B/v1/auth/login")
[ "$CODE" = "400" ] || [ "$CODE" = "401" ]
CK "[7] old password rejected post-reset (got $CODE)" "$?"

# ============================================================================
# [8] Render contracts
# ============================================================================
HTML=$(curl -s -H "$H" -b "$COOKIE" "$B/profile.php")
echo "$HTML" | grep -q 'id="profile-form"'
CK "[8] render: #profile-form present" "$?"
echo "$HTML" | grep -q 'id="password-form"'
CK "[8] render: #password-form present" "$?"
echo "$HTML" | grep -q 'id="profile-phone"'
CK "[8] render: #profile-phone input" "$?"
echo "$HTML" | grep -q 'id="profile-bio"'
CK "[8] render: #profile-bio textarea" "$?"
echo "$HTML" | grep -q 'profile.email_readonly\|Email is your identity anchor\|profile\\.email'
CK "[8] render: email_readonly i18n present" "$?"
echo "$HTML" | grep -q 'src="/js/profile.js"'
CK "[8] render: /js/profile.js linked" "$?"

NAV=$(curl -s -H "$H" -b "$COOKIE" "$B/boards.php")
echo "$NAV" | grep -q 'href="/profile.php"'
CK "[8] header: /profile.php nav link present" "$?"

# Offline JS sanity (the two JS files we added).
node --check www/js/profile.js 2>/dev/null
CK "[8] profile.js syntax ok" "$?"
node --check www/js/users.js   2>/dev/null
CK "[8] users.js syntax ok"   "$?"

# ============================================================================
# [9] Admin surface — per-row Edit + Reset buttons + modals + i18n payload
# ============================================================================
HTML=$(curl -s -H "$H" -b "$COOKIE" "$B/admin/users.php")
echo "$HTML" | grep -q 'btn-edit-user'
CK "[9] admin: btn-edit-user present" "$?"
echo "$HTML" | grep -q 'btn-reset-password-user'
CK "[9] admin: btn-reset-password-user present" "$?"
echo "$HTML" | grep -q 'id="user-edit-overlay"'
CK "[9] admin: edit modal in DOM" "$?"
echo "$HTML" | grep -q 'id="user-reset-overlay"'
CK "[9] admin: reset modal in DOM" "$?"
echo "$HTML" | grep -q 'id="user-edit-form"'
CK "[9] admin: edit form in DOM" "$?"
echo "$HTML" | grep -q 'id="user-reset-form"'
CK "[9] admin: reset form in DOM" "$?"
echo "$HTML" | grep -q 'id="user-edit-email"[^"]*\|readonly'
CK "[9] admin: email field / readonly present" "$?"
echo "$HTML" | grep -q 'data-lang='
CK "[9] admin: data-lang attr (rows + i18n payload) present" "$?"

# ============================================================================
echo ""
echo "HTTP_USER_PROFILE  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
