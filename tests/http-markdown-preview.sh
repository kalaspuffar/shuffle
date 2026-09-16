#!/usr/bin/env bash
# HTTP E2E for CARD-14 DESCRIPTION MARKDOWN PREVIEW (POST /v1/markdown/render)
# — runs against live Apache (shuffle.ea.org).
#
# Session: mints a fresh MYA (user 4) DB session via tests/_rt_session.php
# and reads its CSRF token back from the row (the helper does this for us)
# — Daniel's (user 1) session is never touched.
#
# Exercises:
#   [1] unauth POST                     -> 403 (CSRF gate precedes auth on POSTs)
#   [2] authed POST rich markdown       -> 200 {html: <strong>/<li> rendered}
#   [3] authed POST <script> payload    -> 200, raw <script> neutralized (safe mode)
#   [4] authed POST empty markdown      -> 200 {"html":""}
#   [5] authed POST non-string markdown -> 422
#   [6] GET (wrong verb)                -> 405 (router verb gate)
#
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
cd "$SH"

BODYF=$(mktemp)
SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "$SID" ] || { rm -f "$BODYF"; echo "failed to mint mya session"; exit 1; }
cleanup() { rm -f "$BODYF"; php "$P" cleanup "$SID" >/dev/null 2>&1; }
trap cleanup EXIT

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

# POST /v1/markdown/render with a JSON body.
#   post <jsonBody> [sid]  -> $CODE = status, body in $BODYF
#   sid="" (explicit empty) => unauth probe (no session). A bare 1-arg call
#   defaults to the mya sid. NOTE: must NOT use ${2:-$SID} — that treats an
#   empty string as unset and would silently authenticate the unauth probe.
post() {
    local body sid
    body="$1"
    if [ "$#" -ge 2 ]; then sid="$2"; else sid="$SID"; fi
    CODE=$(php "$P" post "$B/v1/markdown/render" "$sid" "$BODYF" "$body" 2>/dev/null)
}

# --- [1] unauth (POSTs hit the CSRF gate before the auth gate) -----------
post '{"markdown":"x"}' ""
CK "[1] unauth -> 403 CSRF gate (got $CODE, body: $(head -c 80 "$BODYF"))" "$([ "$CODE" = 403 ] && echo 0 || echo 1)"

# --- [2] rich markdown ----------------------------------------------------
post '{"markdown":"# Heading\n\n- one\n- **two**\n\n`code`"}' "$SID"
grep -q '"html"' "$BODYF" && grep -q '<strong>two</strong>' "$BODYF" && grep -q '<li>' "$BODYF"
CK "[2] 200 + rendered html (got $CODE, body: $(head -c 120 "$BODYF" | tr -d '\n'))" "$([ "$CODE" = 200 ] && echo 0 || echo 1)"

# --- [3] XSS neutralized --------------------------------------------------
post '{"markdown":"hi\n\n<script>alert(1)</script>"}' "$SID"
! grep -q '<script>alert(1)</script>' "$BODYF" && grep -q '&lt;script&gt;' "$BODYF"
CK "[3] <script> neutralized (got $CODE)" "$([ "$CODE" = 200 ] && echo 0 || echo 1)"

# --- [4] empty --------------------------------------------------------------
post '{"markdown":""}' "$SID"
grep -q '"html":""' "$BODYF"
CK "[4] empty -> \"html\":\"\" (got $CODE)" "$([ "$CODE" = 200 ] && echo 0 || echo 1)"

# --- [5] non-string ---------------------------------------------------------
post '{"markdown":42}' "$SID"
CK "[5] non-string -> 422 (got $CODE)" "$([ "$CODE" = 422 ] && echo 0 || echo 1)"

# --- [6] wrong verb ---------------------------------------------------------
CODE=$(php "$P" http "$B/v1/markdown/render" "$SID" "$BODYF" 2>/dev/null)
CK "[6] GET -> 405 (got $CODE)" "$([ "$CODE" = 405 ] && echo 0 || echo 1)"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
