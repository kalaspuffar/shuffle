#!/usr/bin/env bash
# HTTP E2E for INTL-01..10 — per-user language selection (spec v1.22 §5.29).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1).
#
# Test-safety invariant (Daniel): NEVER touch the Daniel account (user id 1).
# All write-path tests use a dedicated fixture user (random username, created
# + deleted here, session minted via tests/_rt_session.php).
#
# The app default locale here is "en" (settings app.locale), so the
# reset-to-default assertion renders lang="en".
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
FX=""
SIDFX=""

cleanup() {
  rm -f "$BODYF"
  [ -n "${SIDFX:-}" ] && php "$P" cleanup "$SIDFX" >/dev/null 2>&1
  [ -n "${FX:-}" ] && php -r 'require "include/bootstrap.php"; (new \Shuffle\Model\User($db))->delete("'"$FX"'");' >/dev/null 2>&1
  php "$P" cleanup "$SID" >/dev/null 2>&1
}
trap cleanup EXIT

read_csrf() {
  php -r '
    require "include/bootstrap.php";
    $row = $db->fetch("SELECT data FROM sessions WHERE id = ?", [$argv[1] ?? ""]);
    if ($row && preg_match("/csrf_token\|s:64:\"([a-f0-9]{64})\"/", $row["data"] ?? "", $m)) { echo $m[1]; }
  ' "$1" 2>/dev/null
}

# ============================================================================
# [1] unauth PUT /v1/me {language} → 403 (CSRF gate fires before auth)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
  -H 'Content-Type: application/json' \
  -b 'shuffle_session=__unauth_probe__' \
  -d '{"language":"sv"}' \
  "$B/v1/me")
[ "$CODE" = "403" ]
CK "[1] unauth PUT /v1/me language -> 403 CSRF gate (got $CODE)" "$?"

# Fixture user for the write-path tests (never user 1 / user 4).
FX=$(php -r '
require "include/bootstrap.php";
$um = new \Shuffle\Model\User($db);
$name = "httpi18n-" . substr(bin2hex(random_bytes(4)), 0, 8);
$u = $um->create([
  "username"      => $name,
  "password_hash" => password_hash("fx-pass-1", PASSWORD_ARGON2ID),
  "name"          => "HTTP I18n Fixture",
  "email"         => $name . "@example.test",
  "role"          => "member",
  "status"        => "active",
]);
echo $u;')
[ -n "${FX:-}" ] || { echo "failed to create fixture user"; exit 1; }

SIDFX=$(php "$P" mint "$FX" 2>/dev/null) || true
if [ -z "${SIDFX:-}" ]; then echo "could not mint fixture session"; exit 1; fi
CSRFX=$(read_csrf "$SIDFX")
COOKIEX="shuffle_session=$SIDFX"

# ============================================================================
# [2] PUT /v1/me {language:"sv"} → 200, user.language == "sv"
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
  -H "X-CSRF-Token: $CSRFX" -H 'Content-Type: application/json' -b "$COOKIEX" \
  -d '{"language":"sv"}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[2] PUT language:sv -> 200 (got $CODE)" "$?"
grep -q '"language":"sv"' "$BODYF"
CK "[2] user.language sv in response" "$?"

# ============================================================================
# [3] Render as that user: <html lang="sv"> + a known Swedish string visible
# ============================================================================
HTML=$(curl -s -H "$H" -b "$COOKIEX" "$B/profile.php")
echo "$HTML" | grep -q '<html lang="sv"'
CK "[3] <html lang=\"sv\"> rendered for the sv user" "$?"
echo "$HTML" | grep -q 'Spara profil'
CK "[3] Swedish string 'Spara profil' visible" "$?"
echo "$HTML" | grep -q 'id="language-select"'
CK "[3] language-select control present" "$?"
echo "$HTML" | grep -q 'value="sv" selected'
CK "[3] sv option is the selected one" "$?"

# ============================================================================
# [4] PUT language:"zz" → 400 (state unchanged — stays sv)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
  -H "X-CSRF-Token: $CSRFX" -H 'Content-Type: application/json' -b "$COOKIEX" \
  -d '{"language":"zz"}' \
  "$B/v1/me")
[ "$CODE" = "400" ]
CK "[4] PUT language:zz -> 400 (got $CODE)" "$?"
grep -qE '"error"[^}]*[Ii]nvalid language' "$BODYF"
CK "[4] 400 body: Invalid language" "$?"
# after the 400 the stored value must still be sv (re-fetch the user row)
LANGV=$(php -r 'require "include/bootstrap.php"; $r=$db->fetch("SELECT language FROM users WHERE id = ?", ["'"$FX"'"]); echo $r["language"] ?? "";' 2>/dev/null)
[ "$LANGV" = "sv" ]
CK "[4] stored value unchanged (still sv) after 400 (got $LANGV)" "$?"

# ============================================================================
# [5] PUT language:null → 200, user.language null; render falls back to en
#     (app default here is en)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
  -H "X-CSRF-Token: $CSRFX" -H 'Content-Type: application/json' -b "$COOKIEX" \
  -d '{"language":null}' \
  "$B/v1/me")
[ "$CODE" = "200" ]
CK "[5] PUT language:null -> 200 (got $CODE)" "$?"
grep -q '"language":null' "$BODYF"
CK "[5] user.language null in response" "$?"
HTML=$(curl -s -H "$H" -b "$COOKIEX" "$B/profile.php")
echo "$HTML" | grep -q '<html lang="en"'
CK "[5] reset: renders lang=\"en\" (app default)" "$?"
echo "$HTML" | grep -q 'Save profile'
CK "[5] reset: English string visible again" "$?"

# ============================================================================
# [6] login page (unauth) renders lang="en" regardless of stored preference
#     (INTL-02: the preference travels with the USER, not the browser)
# ============================================================================
HTML=$(curl -s -H "$H" -b 'shuffle_session=__unauth_probe__' "$B/login.php")
echo "$HTML" | grep -q '<html lang="en"'
CK "[6] unauth login page -> lang=\"en\" (unauthed rule)" "$?"

# ============================================================================
# [7] lang file parity guard (INTL-07 is machine-checked) + syntax
# ============================================================================
php -r '
$en = json_decode(file_get_contents("include/lang/en.json"), true);
$sv = json_decode(file_get_contents("include/lang/sv.json"), true);
exit( (array_diff(array_keys($en), array_keys($sv)) ?: []) === [] ? 0 : 1 );'
CK "[7] sv.json covers every en.json key" "$?"
node --check www/js/profile.js 2>/dev/null
CK "[7] profile.js syntax ok" "$?"
php -l www/profile.php >/dev/null 2>&1
CK "[7] profile.php lints" "$?"

# ============================================================================
echo ""
echo "HTTP_I18N  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
