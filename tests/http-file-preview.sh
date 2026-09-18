#!/usr/bin/env bash
# HTTP E2E for FILE-06/07 (v1.15, spec §5.23) — GET /v1/attachments/{id}/preview
# Runs against live Apache (shuffle.ea.org). Uses mya (user 4) via a minted
# DB session + the raw-binary put driver — never touches Daniel's session.
#
#   [1]  GET  preview unauth                        -> 401 (auth gate, API)
#   [2]  GET  preview image (no Range)              -> 200, inline disposition,
#                                                     Content-Type image/png,
#                                                     Content-Length == size,
#                                                     Accept-Ranges: bytes
#   [3]  GET  preview image bytes 0-9               -> 206, Content-Range
#                                                     0-9/total, 10 bytes body
#   [4]  GET  preview image malformed Range         -> 416 + Accept-Ranges + star Content-Range
#   [5]  GET  preview image overshoot Range         -> 416 (object too small)
#   [6]  GET  preview zip                           -> 415 (type gate; /download keeps working)
#   [7]  GET  download zip (regression)             -> 200, Content-Disposition STILL attachment
#   [8]  GET  board region fragment                 -> contains card-thumb for the
#                                                     image card, NOT for the zip card
#   [9]  GET  preview unknown id                    -> 404
#
set -u
SH=~/shuffle
P="$SH/tests/_rt_session.php"
B=http://127.0.0.1
cd "$SH"

BODYF=$(mktemp)
HDRF=$(mktemp)
cleanup() {
  php "$P" cleanup "$SID" >/dev/null 2>&1 || true
  if [ -n "${FX:-}" ]; then php tests/_cleanup-file-preview.php "$FX" >/dev/null 2>&1 || true; fi
  rm -f "$BODYF" "$HDRF"
}
trap cleanup EXIT

SID=$(php "$P" mint 4 2>/dev/null) || true
[ -n "$SID" ] || { rm -f "$BODYF" "$HDRF"; echo "failed to mint mya session"; exit 1; }

PASS=0; FAIL=0
CK() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }
hdr() { local h="$1" v; v=$(grep -i "^$h:" "$HDRF" | head -1 | cut -d: -f2- | sed 's/^[[:space:]]*//'); printf '%s' "$v"; }
jget() { python3 -c "import json,sys; d=json.load(open('$1')); print($2)" 2>/dev/null; }

# ---------------------------------------------------------------- fixture
FX=$(php tests/_fixture-file-preview.php 2>/dev/null) || { echo "fixture failed"; exit 1; }
BOARD=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["board"])' "$FX")
CARD_A=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["cardA"])' "$FX")
ATT_IMG=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["attImg"])' "$FX")
ATT_ZIP=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["attZip"])' "$FX")
PNG_SIZE=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["pngSize"])' "$FX")
echo "fixture: board=$BOARD attImg=$ATT_IMG attZip=$ATT_ZIP pngSize=$PNG_SIZE"

# ---------------------------------------------------------------- [1] unauth
CODE=$(php "$P" http "$B/v1/attachments/$ATT_IMG/preview" "" "$BODYF" 2>/dev/null)
CK "[1] unauth GET -> 401 (got $CODE)" $([ "$CODE" = "401" ]; echo $?)

# ---------------------------------------------------------------- [2] image full
CODE=$(php "$P" hdrs "$B/v1/attachments/$ATT_IMG/preview" "$SID" "$HDRF" 2>/dev/null)
# body via http driver (writes to BODYF)
HTTPCODE=$(php "$P" http "$B/v1/attachments/$ATT_IMG/preview" "$SID" "$BODYF" 2>/dev/null)
SIZE_BODY=$(wc -c < "$BODYF" | tr -d ' ')
DISP=$(hdr "Content-Disposition")
CT=$(hdr "Content-Type")
CL=$(hdr "Content-Length")
AR=$(hdr "Accept-Ranges")
CC=$(hdr "Cache-Control")
ok=0
[ "$CODE" = "200" ]      || { ok=1; echo "  hdr status=$CODE"; }
[ "$HTTPCODE" = "200" ]  || { ok=1; echo "  http status=$HTTPCODE"; }
case "$DISP" in "inline;"*) ;; *) ok=1; echo "  disposition='$DISP'";; esac
[ "$CT" = "image/png" ]  || { ok=1; echo "  content-type='$CT'"; }
[ "$CL" = "$PNG_SIZE" ]  || { ok=1; echo "  content-length='$CL' want $PNG_SIZE"; }
[ "$AR" = "bytes" ]      || { ok=1; echo "  accept-ranges='$AR'"; }
case "$CC" in "private, no-store") ;; *) ok=1; echo "  cache-control='$CC'";; esac
[ "$SIZE_BODY" = "$PNG_SIZE" ] || { ok=1; echo "  body size=$SIZE_BODY want $PNG_SIZE"; }
CK "[2] image 200 + inline + image/png + CL=$PNG_SIZE + Accept-Ranges: bytes + private,no-store (ok=$ok)" $ok

# ---------------------------------------------------------------- [3] image range 0-9
CODE=$(php "$P" hdrs "$B/v1/attachments/$ATT_IMG/preview" "$SID" "$HDRF" "Range: bytes=0-9" 2>/dev/null)
CR=$(hdr "Content-Range")
CL=$(hdr "Content-Length")
ok=0
[ "$CODE" = "206" ] || ok=1
[ "$CR" = "bytes 0-9/$PNG_SIZE" ] || ok=1
[ "$CL" = "10" ] || ok=1
CK "[3] range 0-9 -> 206 + Content-Range: bytes 0-9/$PNG_SIZE + CL=10 (got $CR, $CL)" $ok

# ---------------------------------------------------------------- [4] malformed range
CODE=$(php "$P" hdrs "$B/v1/attachments/$ATT_IMG/preview" "$SID" "$HDRF" "Range: bytes=abc" 2>/dev/null)
AR=$(hdr "Accept-Ranges")
CR=$(hdr "Content-Range")
ok=0
[ "$CODE" = "416" ] || ok=1
[ "$AR" = "bytes" ] || ok=1
case "$CR" in "bytes */"*|*"bytes */"*) ;; *) ok=1;; esac
CK "[4] malformed range -> 416 + Accept-Ranges + star Content-Range (got: $CR)" $ok

# ---------------------------------------------------------------- [5] overshoot
BIG=$((PNG_SIZE + 100))
CODE=$(php "$P" hdrs "$B/v1/attachments/$ATT_IMG/preview" "$SID" "$HDRF" "Range: bytes=0-$BIG" 2>/dev/null)
CK "[5] overshoot range (0-$BIG on $PNG_SIZE B) -> 416 (got $CODE)" $([ "$CODE" = "416" ]; echo $?)

# ---------------------------------------------------------------- [6] zip -> 415
CODE=$(php "$P" hdrs "$B/v1/attachments/$ATT_ZIP/preview" "$SID" "$HDRF" 2>/dev/null)
CK "[6] zip preview -> 415 (got $CODE)" $([ "$CODE" = "415" ]; echo $?)

# ---------------------------------------------------------------- [7] download zip still attachment
CODE=$(php "$P" hdrs "$B/v1/attachments/$ATT_ZIP/download" "$SID" "$HDRF" 2>/dev/null)
DISP=$(hdr "Content-Disposition")
ok=0
[ "$CODE" = "200" ] || ok=1
case "$DISP" in "attachment; filename=\"bundle.zip\""*) ;; *) ok=1;; esac
CK "[7] zip download -> 200 + disposition still attachment; filename=bundle.zip (got: $DISP)" $ok

# ---------------------------------------------------------------- [8] board region fragment
CODE=$(php "$P" http "$B/v1/boards/$BOARD/region" "$SID" "$BODYF" 2>/dev/null)
FRAG=$(cat "$BODYF")
ok=0
[ "$CODE" = "200" ] || ok=1
# The test board has exactly 2 cards: card A (image → thumbnail) and card B
# (zip → no thumbnail). So the fragment must contain EXACTLY ONE card-thumb:
# presence proves §5.23 rendering for the image card, absence on the zip card
# proves the firstPreviewableByCards type gate at the template level.
THUMBS=$(printf '%s' "$FRAG" | grep -c 'class="card-thumb"' || true)
[ "$THUMBS" = "1" ] || ok=1
CK "[8] board region: exactly 1 card-thumb (image card yes, zip card no; got $THUMBS)" $ok

# ---------------------------------------------------------------- [9] unknown id
CODE=$(php "$P" hdrs "$B/v1/attachments/999999999/preview" "$SID" "$HDRF" 2>/dev/null)
CK "[9] preview unknown id -> 404 (got $CODE)" $([ "$CODE" = "404" ]; echo $?)

# ---------------------------------------------------------------- summary
echo "------------------------------------------------------"
echo "http-file-preview: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" = "0" ]
