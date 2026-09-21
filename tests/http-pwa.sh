#!/usr/bin/env bash
# HTTP E2E for PWA-01..08 — installable web app (spec v1.21 §5.28).
# Runs against live Apache (shuffle.ea.org → http://127.0.0.1).
#
# Test-safety invariant (Daniel 2026-09-03): NEVER touch the Daniel account
# (id 71) or mutate mya's (id 4) stored data. Every write-path test in this
# suite uses a dedicated fixture user, minted session included, and both
# are deleted in the EXIT trap.
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
H="Host: shuffle.ea.org"
cd "$SH"

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

BODYF=$(mktemp)
FX=""; SIDFX=""
cleanup() {
  rm -f "$BODYF"
  [ -n "${SIDFX:-}" ] && php "$P" cleanup "$SIDFX" >/dev/null 2>&1
  [ -n "${FX:-}" ] && php -r 'require "include/bootstrap.php"; (new \Shuffle\Model\User($db))->delete($argv[1]);' "$FX" >/dev/null 2>&1
}
trap cleanup EXIT

# Create a dedicated fixture user (role member, org 1) for the authed-page checks.
FX=$(php -r '
  require "include/bootstrap.php";
  $u = new \Shuffle\Model\User($db);
  $name = "pwa_test_" . bin2hex(random_bytes(4));
  $id = $u->create([
    "username"      => $name,
    "password_hash" => password_hash(bin2hex(random_bytes(8)), PASSWORD_ARGON2ID),
    "name"          => "PWA Fixture",
    "email"         => $name . "@test.example",
    "role"          => "member",
    "organization_id" => 1,
    "status"        => "active",
  ]);
  echo $id;
') || { echo "SETUP FAILED: fixture user creation"; exit 1; }
SIDFX=$(php "$P" mint "$FX" 2>/dev/null) || SIDFX=""

# ---------- PWA-01: manifest reachable + served as manifest+json ----------
OUT=$(curl -s -H "$H" -o "$BODYF" -w '%{http_code} %{content_type}' "$B/manifest.webmanifest")
CODE=$(echo "$OUT" | awk '{print $1}')
CT=$(echo "$OUT" | awk '{print $2}')
CK "PWA-01 manifest 200" $([ "$CODE" = "200" ]; echo $?)
CK "PWA-01 manifest content-type manifest+json" $(echo "$CT" | grep -qi 'application/manifest+json'; echo $?)
CK "PWA-01 manifest has name+start_url+display" \
  $(python3 -c "
import json,sys
m=json.load(open('$BODYF'))
ok = (m.get('name') and m.get('short_name') and m.get('start_url')=='/boards.php'
      and m.get('scope')=='/' and m.get('display')=='standalone'
      and m.get('theme_color')=='#6D28D9' and m.get('background_color')=='#0D0D12')
sys.exit(0 if ok else 1)" 2>/dev/null; echo $?)
CK "PWA-01 manifest icon list has 192+512 with maskable purpose on the 512" \
  $(python3 -c "
import json,sys
m=json.load(open('$BODYF'))
icons=m.get('icons',[])
s=str(icons)
any512=[i for i in icons if i.get('sizes')=='512x512' and 'maskable' in i.get('purpose','')]
any192=[i for i in icons if i.get('sizes')=='192x192']
sys.exit(0 if any512 and any192 else 1)" 2>/dev/null; echo $?)

# ---------- PWA-02: icons exist at their declared sizes ----------
for pair in "icon-512.png 512" "icon-192.png 192" "apple-touch-icon.png 180" "favicon.png 48"; do
  set -- $pair
  name=$1; want=$2
  OUT=$(curl -s -H "$H" -o "$BODYF" -w '%{http_code} %{content_type}' "$B/img/$name")
  CODE=$(echo "$OUT" | awk '{print $1}')
  CT=$(echo "$OUT" | awk '{print $2}')
  CK "PWA-02 $name 200" $([ "$CODE" = "200" ]; echo $?)
  CK "PWA-02 $name content-type png" $(echo "$CT" | grep -qi 'image/png'; echo $?)
  DIMS=$(python3 -c "
import struct
f=open('$BODYF','rb').read()
w,h=struct.unpack('>II', f[16:24])
print(w,h)" 2>/dev/null)
  CK "PWA-02 $name dims ${DIMS}" $([ "$DIMS" = "$want $want" ]; echo $?)
done

# ---------- PWA-04: offline fallback page — public, i18n'd, noindex ----------
OUT=$(curl -s -H "$H" -o "$BODYF" -w '%{http_code}' "$B/offline.php")
CODE=$(echo "$OUT")
CK "PWA-04 /offline.php unauth 200" $([ "$CODE" = "200" ]; echo $?)
CK "PWA-04 offline page: i18n'd heading present" \
  $(grep -qE "You(&#039;|')re offline" "$BODYF"; echo $?)
CK "PWA-04 offline page: all three i18n'd body blocks present" \
  $(grep -q 'no cached copy yet' "$BODYF" && grep -q 'last successful load' "$BODYF" && grep -q 'will not sync' "$BODYF"; echo $?)
CK "PWA-04 offline page: has a link back to home" \
  $(grep -Eq 'href="/"' "$BODYF"; echo $?)
CK "PWA-04 offline page: robots noindex" \
  $(grep -qi 'name="robots" content="noindex"' "$BODYF"; echo $?)
CSRFN=$(grep -c 'csrf-token' "$BODYF" || true)
CK "PWA-04 offline page: no session-gated marker" $([ "${CSRFN:-0}" -eq 0 ]; echo $?)
CK "PWA-04 offline.css served 200" \
  $(curl -s -H "$H" -o /dev/null -w '%{http_code}' "$B/offline.css" | grep -q 200; echo $?)

# ---------- PWA-03/05/06: sw.js contract markers ----------
OUT=$(curl -s -H "$H" -o "$BODYF" -w '%{http_code}' "$B/sw.js")
CODE=$(echo "$OUT")
CK "PWA-05/06 /sw.js unauth 200" $([ "$CODE" = "200" ]; echo $?)
CK "PWA-05 sw.js intercepts GET-only (non-GET returns)" \
  $(grep -q 'req.method !== "GET"' "$BODYF"; echo $?)
CK "PWA-05 sw.js excludes /v1/* API paths" \
  $(grep -q '"/v1/"' "$BODYF"; echo $?)
CK "PWA-06 sw.js versioned cache key" \
  $(grep -q 'shuffle-pwa-v' "$BODYF"; echo $?)
CK "PWA-06 sw.js purges older-version caches on activate" \
  $(grep -q 'caches.delete' "$BODYF"; echo $?)
CK "PWA-03 sw.js injects #pwa-offline-banner on cache-hit" \
  $(grep -q 'pwa-offline-banner' "$BODYF"; echo $?)
CK "PWA-04 sw.js pre-caches /offline.php at install" \
  $(grep -q '"/offline.php"' "$BODYF"; echo $?)
CK "PWA-04 sw.js caches only OK responses (no deploy poison)" \
  $(grep -q 'res.ok' "$BODYF"; echo $?)

# ---------- PWA-01/02 wiring: header links on EVERY page ----------
# Unauthenticated: the standalone login/activate/setup pages carry their own
# <head>, so the install tags live there (login.php/activate.php/setup.php)
# and in the shared header.php for the authed pages.
LHTML=$(curl -s -H "$H" "$B/login.php")
CK "PWA-01 login head: manifest link on unauth page" \
  $(printf '%s' "$LHTML" | grep -q 'rel="manifest" href="/manifest.webmanifest"'; echo $?)
CK "PWA-02 login head: apple-touch-icon on unauth page" \
  $(printf '%s' "$LHTML" | grep -q 'rel="apple-touch-icon" href="/img/apple-touch-icon.png"'; echo $?)
CK "PWA-01 login head: theme-color meta on unauth page" \
  $(printf '%s' "$LHTML" | grep -q 'name="theme-color" content="#6D28D9"'; echo $?)
CK "PWA-02 login head: favicon on unauth page" \
  $(printf '%s' "$LHTML" | grep -q 'rel="icon" type="image/png" href="/img/favicon.png"'; echo $?)
ACTHTML=$(curl -s -H "$H" "$B/activate.php")
CK "PWA-01 activate head: manifest link" \
  $(printf '%s' "$ACTHTML" | grep -q 'rel="manifest"'; echo $?)

# ---------- PWA-07: registration script is authed-only ----------
CK "PWA-07 /js/pwa.js served 200" \
  $(curl -s -H "$H" -o /dev/null -w '%{http_code}' "$B/js/pwa.js" | grep -q 200; echo $?)
PWASN=$(printf '%s' "$LHTML" | grep -c 'js/pwa.js' || true)
CK "PWA-07 login page: no /js/pwa.js (unauth does not register)" \
  $([ "${PWASN:-0}" -eq 0 ]; echo $?)
# Authed page as the fixture user
if [ -n "${SIDFX:-}" ]; then
  COOKIE="shuffle_session=$SIDFX"
  HTMLAUTH=$(curl -s -H "$H" -b "$COOKIE" "$B/boards.php" -o "$BODYF"; cat "$BODYF")
  CK "PWA-07 authed boards.php 200" \
    $(curl -s -H "$H" -b "$COOKIE" -o /dev/null -w '%{http_code}' "$B/boards.php" | grep -q 200; echo $?)
  CK "PWA-07 authed page: /js/pwa.js present (registration surface)" \
    $(grep -q 'js/pwa.js' "$BODYF"; echo $?)
  CK "PWA-01 authed page: manifest link still present" \
    $(grep -q 'rel="manifest" href="/manifest.webmanifest"' "$BODYF"; echo $?)
else
  echo "SKIP  PWA-07 authed-page checks (mint failed)"
fi

# ---------- PWA-08: offline surface is static (no script tags, CSP-clean) ----------
OFFLINE=$(curl -s -H "$H" "$B/offline.php")
SCRIPTS=$(printf '%s' "$OFFLINE" | grep -c '<script' || true)
CK "PWA-08 /offline.php: zero <script> tags (static + CSP-clean)" $([ "${SCRIPTS:-0}" -eq 0 ]; echo $?)

echo
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
