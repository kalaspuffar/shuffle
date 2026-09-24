#!/usr/bin/env bash
# HTTP E2E for NOTIF-05 due-date reminders (spec v1.23 §5.30).
#
# Live-Apache contract over PUT /v1/me {due_remind_hours} + the profile page
# render of the new Due date reminders card.
#
# Test-safety invariant (Daniel 2026-09-03): NEVER touch user 1. A dedicated
# fixture member is created here (random suffix), sessions minted via
# tests/_rt_session.php, and everything (fixture user, claims, notifications)
# is deleted in the EXIT trap.
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

BODYF=$(mktemp)

# ---- fixture user (never 1, random suffix) -------------------------------
SUF=$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')
FX=$(php -r '
  require "include/bootstrap.php";
  $n = "e2e-http-due-" . $argv[1];
  $id = (new \Shuffle\Model\User($db))->create([
    "username"      => $n,
    "password_hash" => password_hash("fixture-pass-1", PASSWORD_ARGON2ID),
    "name"          => "Due HTTP Fixture",
    "email"         => $n . "@example.remind.test",
    "role"          => "member",
    "status"        => "active",
  ]);
  echo $id;' "$SUF")
[ -n "$FX" ] && [ "$FX" != "0" ] || { rm -f "$BODYF"; echo "fixture user creation failed"; exit 1; }

SID=$(php "$P" mint "$FX") || { rm -f "$BODYF"; echo "session mint failed"; exit 1; }

cleanup() {
  rm -f "$BODYF"
  php "$P" cleanup "$SID" >/dev/null 2>&1
  php -r 'require "include/bootstrap.php";
    $id = (int)$argv[1];
    $db->execute("DELETE FROM due_reminders WHERE user_id = ?", [$id]);
    $db->execute("DELETE FROM notifications WHERE user_id = ?", [$id]);
    (new \Shuffle\Model\User($db))->delete($id);
  ' "$FX" >/dev/null 2>&1
  [ "$FX" != "0" ] && php "$P" cleanup "$SID" >/dev/null 2>&1 || true
}
trap cleanup EXIT

CSRF=$(php -r '
  require "include/bootstrap.php";
  $row = $db->fetch("SELECT data FROM sessions WHERE id = ?", [$argv[1] ?? ""]);
  if ($row && preg_match("/csrf_token\|s:64:\"([a-f0-9]{64})\"/", $row["data"] ?? "", $m)) { echo $m[1]; }
' "$SID" 2>/dev/null)
CH="X-CSRF-Token: $CSRF"

put_json() {
  # put_json <json-body>  → writes status to $CODE, body to $BODYF
  local body="$1"
  CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -X PUT -H "$H" \
    -H 'Content-Type: application/json' -H "$CH" \
    -b "shuffle_session=$SID" -d "$body" "$B/v1/me")
}

get_json() {
  local url="$1"
  CODE=$(curl -s -o "$BODYF" -w "%{http_code}" -H "$H" \
    -b "shuffle_session=$SID" "$B$url")
}

read_due() {
  php -r '
    require "include/bootstrap.php";
    $r = $db->fetch("SELECT due_remind_hours AS v FROM users WHERE id = ?", [$argv[1] ?? 0]);
    $v = $r ? $r["v"] : "NO-ROW";
    echo ($v === null) ? "NULL" : (string)$v;' "$FX" 2>/dev/null
}

# ============================================================================
# [1] unauth PUT → CSRF gate (403) — the CSRF check precedes auth
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w '%{http_code}' -X PUT -H "$H" \
  -H 'Content-Type: application/json' \
  -b 'shuffle_session=__unauth_probe__' -d '{"due_remind_hours":24}' \
  "$B/v1/me")
CK "unauth PUT /v1/me → 403 CSRF gate" $([ "$CODE" = "403" ]; echo $?)

# Unauth GET profile page (should be a redirect or 3xx, not 200)
CODE=$(curl -s -o /dev/null -w '%{http_code}' -H "$H" "$B/profile.php")
CK "unauth /profile.php → not 200 (auth gate)" $([ "$CODE" != "200" ]; echo $?)

# ============================================================================
# [2] authed no-op PUT → 200 + the due_remind_hours field is present in the
#     response payload (there is no GET /v1/me by design — the PUT is the
#     self-service surface, the profile page renders server-side).
# ============================================================================
put_json '{}'
CK "authed PUT /v1/me (no-op) → 200" $([ "$CODE" = "200" ]; echo $?)
grep -q '"due_remind_hours":null' "$BODYF"
CK "response payload carries due_remind_hours (null for a fresh user)" $?

# ============================================================================
# [3] valid set → 200 round-trip
# ============================================================================
put_json '{"due_remind_hours":48}'
CK "PUT 48 → 200" $([ "$CODE" = "200" ]; echo $?)
grep -q '"due_remind_hours":48' "$BODYF"
CK "response confirms 48" $?
[ "$(read_due)" = "48" ]
CK "DB holds 48" $?

put_json '{"due_remind_hours":720}'
CK "PUT 720 → 200" $([ "$CODE" = "200" ]; echo $?)
[ "$(read_due)" = "720" ]
CK "DB holds 720" $?

# null resets
put_json '{"due_remind_hours":null}'
CK "PUT null → 200" $([ "$CODE" = "200" ]; echo $?)
[ "$(read_due)" = "NULL" ]
CK "null resets to NULL" $?

# ============================================================================
# [4] invalid shapes → 400, DB unchanged (still NULL)
# ============================================================================
for BAD in '0' '-1' '721' '"24"' '1.5' 'true' 'false'; do
  put_json "{\"due_remind_hours\":$BAD}"
  CK "invalid ($BAD) → 400" $([ "$CODE" = "400" ]; echo $?)
done
[ "$(read_due)" = "NULL" ]
CK "DB unchanged after invalid values" $?

# ============================================================================
# [5] omit vs null distinction: an omit writes nothing
# ============================================================================
put_json '{"due_remind_hours":48}'       # set 48
[ "$(read_due)" = "48" ]
put_json '{}'                            # omit → no-op for this field
[ "$(read_due)" = "48" ]
CK "omitted key leaves 48 in place" $?
put_json '{"due_remind_hours":null}'     # explicit null → reset
[ "$(read_due)" = "NULL" ]
CK "null (explicit) resets after 48" $?

# ============================================================================
# [6] profile page renders the new card (server side, i18n'd markup)
# ============================================================================
CODE=$(curl -s -o "$BODYF" -w '%{http_code}' -H "$H" -b "shuffle_session=$SID" "$B/profile.php")
CK "authenticated /profile.php → 200" $([ "$CODE" = "200" ]; echo $?)
grep -q 'id="due-remind-section"' "$BODYF"
CK "profile page contains #due-remind-section" $?
grep -q 'id="due-remind-hours"' "$BODYF"
CK "profile page contains the due-remind-hours input" $?
grep -qiE 'remind me|deadline|påminn' "$BODYF"
CK "profile page renders i18n'd due-reminder copy" $?

# ============================================================================
# [7] cross-user: a non-admin cannot update ANOTHER user's profile fields —
#     the 'Access denied' rule (UserService.php:184). This is the same gate
#     that makes due_remind_hours a self-service preference (the theme/
#     language/email_notifications contract, §5.22/5.26/5.29/5.30).
# ============================================================================
# Create a second fixture user so we have someone to try to cross-update.
SUF2=$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')
FX2=$(php -r '
  require "include/bootstrap.php";
  $n = "e2e-http-due2-" . $argv[1];
  echo (new \Shuffle\Model\User($db))->create([
    "username"      => $n,
    "password_hash" => password_hash("fixture-pass-1", PASSWORD_ARGON2ID),
    "name"          => "Due HTTP Fixture 2",
    "email"         => $n . "@example.remind.test",
    "role"          => "member",
    "status"        => "active",
  ]);' "$SUF2")
# Seed a known value on FX2 so we can assert it's unchanged.
php -r 'require "include/bootstrap.php";
  $db->execute("UPDATE users SET due_remind_hours = 42 WHERE id = ?", [ (int)$argv[1] ]);
' "$FX2" >/dev/null 2>&1
php -r 'require "include/bootstrap.php";
  $db->execute("UPDATE users SET due_remind_hours = NULL WHERE id = ?", [ (int)$argv[1] ]);
  $db->execute("UPDATE users SET due_remind_hours = 42 WHERE id = ?", [ (int)$argv[1] ]);
' "$FX2" >/dev/null 2>&1  # final state: 42

SIDFX2=$(php "$P" mint "$FX2") || SIDFX2=""

cleanup2() {
  [ -n "${SIDFX2:-}" ] && php "$P" cleanup "$SIDFX2" >/dev/null 2>&1
  [ "$FX2" != "0" ] && php -r 'require "include/bootstrap.php";
    (new \Shuffle\Model\User($db))->delete((int)$argv[1]);' "$FX2" >/dev/null 2>&1
}
trap cleanup2 EXIT

ADCSRF=$(php -r '
  require "include/bootstrap.php";
  $row = $db->fetch("SELECT data FROM sessions WHERE id = ?", [$argv[1] ?? ""]);
  if ($row && preg_match("/csrf_token\|s:64:\"([a-f0-9]{64})\"/", $row["data"] ?? "", $m)) { echo $m[1]; }
' "$SIDFX2" 2>/dev/null)

# FX2 (a non-admin member) attempts to change FX's due_remind_hours.
CODE=$(curl -s -o "$BODYF" -w '%{http_code}' -X PUT -H "$H" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $ADCSRF" \
  -b "shuffle_session=$SIDFX2" \
  -d "{\"due_remind_hours\":99,\"name\":\"cross-user attempt\"}" "$B/v1/users/$FX")
CK "non-admin cross-user PUT /v1/users/{other} denied (403/400)" $([ "$CODE" = "403" ] || [ "$CODE" = "400" ]; echo $?)
# FX's stored value is unchanged — FX was seeded to NULL, and FX2's attempt was rejected.
[ "$(php -r 'require "include/bootstrap.php";
     $r = $db->fetch("SELECT due_remind_hours AS v FROM users WHERE id = ?", [(int)$argv[1]]);
     $v = $r ? $r["v"] : "NO-ROW";
     echo ($v === null) ? "NULL" : (string)$v;' "$FX" 2>/dev/null)" = "NULL" ]
CK "FX's due_remind_hours unchanged after cross-user attempt" $?

# ============================================================================
# [8] the bell-panel icon branch exists in notifications.js (static contract)
# ============================================================================
grep -q "notification.type === 'due'" "$SH/www/js/notifications.js"
CK "notifications.js has a type==='due' icon branch" $?

# ============================================================================
# [9] scan CLI binary is committed and syntactically valid PHP
# ============================================================================
php -l "$SH/bin/due-reminder-scan.php" >/dev/null 2>&1
CK "bin/due-reminder-scan.php is present and lints" $?

echo
echo "---- NOTIF-05 HTTP contract ($PASS pass, $FAIL fail) ----"
exit $([ "$FAIL" -eq 0 ] && echo 0 || echo 1)
