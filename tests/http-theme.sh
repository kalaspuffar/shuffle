#!/usr/bin/env bash
# HTTP E2E for THEME-01..07 — theme & light mode (spec v1.20 §5.27).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1).
#
# Test-safety invariant (Daniel 2026-09-03): NEVER touch the Daniel account
# (user id 71 — also named id 1 in some docs) or mutate mya's (id 4) stored
# data. All write-path tests use a dedicated fixture user (random username,
# created + deleted here, session minted via tests/_rt_session.php).
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

BODYF=$(mktemp)
FX=""
SIDFX=""

cleanup() {
  rm -f "$BODYF"
  [ -n "${SIDFX:-}" ] && php "$P" cleanup "$SIDFX" >/dev/null 2>&1
  [ -n "${FX:-}" ] && php -r 'require "include/bootstrap.php"; (new \Shuffle\Model\User($db))->delete('"$FX"');' >/dev/null 2>&1
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

# [1] Unauth PUT /v1/me theme -> 403 (CSRF gate, per repo convention)
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
  -H 'Content-Type: application/json' \
  -b 'shuffle_session=__unauth_probe__' \
  -d '{"theme":"light"}' \
  "$B/v1/me")
[ "$CODE" = "403" ]
CK "[1] unauth PUT /v1/me {theme} -> 403 CSRF gate (got $CODE)" "$?"

# [1b] Fixture user for write-path tests
FX=$(php -r '
require "include/bootstrap.php";
$um = new \Shuffle\Model\User($db);
$name = "themefx-" . substr(bin2hex(random_bytes(4)), 0, 8);
$u = $um->create([
  "username"      => $name,
  "password_hash" => password_hash("themepw-99", PASSWORD_ARGON2ID),
  "name"          => "Theme FX",
  "email"         => $name . "@example.test",
  "role"          => "member",
  "status"        => "active",
]);
echo $u;')
[ -n "${FX:-}" ] || { echo "failed to create fixture user"; exit 1; }

SIDFX=$(php "$P" mint "$FX" 2>/dev/null) || true
[ -n "${SIDFX:-}" ] || { echo "mint for fixture user failed"; exit 1; }
COOKIE="shuffle_session=$SIDFX"
CSRF=$(read_csrf "$SIDFX")
[ -n "${CSRF:-}" ] || { echo "failed to read CSRF token"; exit 1; }
CH="X-CSRF-Token: $CSRF"

# [2] set theme light
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"theme":"light"}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[2] PUT /v1/me {theme:light} -> 200 (got $CODE)" "$?"
grep -q '"theme_preference":"light"' "$BODYF"
CK "[2] response body theme_preference=light" "$?"

# [3] idempotent same-value write
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"theme":"light"}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[3] idempotent {theme:light} -> 200 (got $CODE)" "$?"

# [4] flip back to dark
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"theme":"dark"}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[4] flip to dark -> 200 (got $CODE)" "$?"
grep -q '"theme_preference":"dark"' "$BODYF"
CK "[4] body reflects dark" "$?"

# [5] canonical `theme_preference` also accepted
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"theme_preference":"light"}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[5] canonical {theme_preference:light} -> 200 (got $CODE)" "$?"
grep -q '"theme_preference":"light"' "$BODYF"
CK "[5] body reflects light" "$?"

# [6] rejected value — the service validates the ENUM
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" -H "$CH" \
  -H 'Content-Type: application/json' -b "$COOKIE" \
  -d '{"theme":"bogus"}' \
  "$B/v1/me")
[ "$CODE" = "400" ]
CK "[6] malformed {theme:bogus} -> 400 (got $CODE)" "$?"

# [7] profile page render — appearance section + radios
HTML=$(curl -s -H "$H" -b "$COOKIE" "$B/profile.php" -o "$BODYF"; echo "$?")
grep -q 'appearance-section' "$BODYF"
CK "[7] profile: appearance-section rendered" "$?"
grep -q 'id="theme-dark"'   "$BODYF"
CK "[7] profile: theme-dark radio rendered" "$?"
grep -q 'id="theme-light"'  "$BODYF"
CK "[7] profile: theme-light radio rendered" "$?"
# current saved theme is light (from step 5) → theme-light marked checked
grep -A1 'id="theme-light"' "$BODYF" | grep -q 'checked'
CK "[7] profile: theme-light marked checked (light is the active state)" "$?"

# [8] CSS surface
CSS=$(curl -s -H "$H" -b "shuffle_session=$SIDFX" "$B/css/app.css" -o "$BODYF"; echo "$?")
grep -q '.theme-choice' "$BODYF"
CK "[8] css: .theme-choice present" "$?"
grep -q -- '--color-on-error' "$BODYF"
CK "[8] css: --color-on-error token present" "$?"
grep -q '[data-theme="light"]' "$BODYF"
CK "[8] css: light theme override present" "$?"

# [9] header data-theme reflects the user's saved theme at paint time
#     (THEME-01: server-persisted preference applied before first paint)
CODE=$(curl -s -H "$H" -b "$COOKIE" -o "$BODYF" -w "%{http_code}" "$B/boards.php")
[ "$CODE" = "200" ]
CK "[9] boards.php render as fx user -> 200 (got $CODE)" "$?"
grep -q 'data-theme="light"' "$BODYF"
CK "[9] <html data-theme=\"light\"> reflects saved theme — no flash of wrong theme" "$?"

echo ""
echo "HTTP_THEME  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
