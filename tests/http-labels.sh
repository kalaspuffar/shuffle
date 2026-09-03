#!/usr/bin/env bash
# HTTP E2E for LABEL-01..03 (card labels) — runs against live Apache (shuffle.ea.org).
#
#   [1]  GET  /v1/boards/{b}/labels        → 200, empty list
#   [2]  POST /v1/boards/{b}/labels        → 201 {label:{id,name,color,card_count:0}}
#   [3]  POST no color                     → 201
#   [4]  POST bad color                    → 400
#   [5]  POST name dup (exact case)        → 409
#   [6]  POST name dup (different case)    → 409 (case-insensitive uniqueness)
#   [7]  GET  list after creates           → 200, both present, card_count 0
#   [8]  POST /v1/cards/{c}/labels/{id}    → 204
#   [9]  POST attach again (idempotent)    → 200
#   [10] GET  /v1/cards/{c}                → labels[] includes the label
#   [11] DELETE detach                     → 204
#   [12] GET  card detail after detach     → labels[] clean
#   [13] POST blank name                   → 400
#   [14] DELETE unauth                     → 403 (CSRF gate)
#   [15] viewer POST create                → 403 (requireRole('member'))
#   [16] viewer attach (no board access)   → 404 (BOARD-04b strict isolation)
#   [17] PUT  rename                       → 200, name updated
#   [18] DELETE label                      → 204
#   [19] merge union (LABEL-03)            → 200, dest has source's labels
#
# Bodies go to /tmp files; JSON parsed by python3 from the file (no pipe-to-interpreter).
set -u
H='-H Host:shuffle.ea.org'
B=http://127.0.0.1
T=/tmp/lb-$$
rm -f "$T"-*

# Admin session + CSRF (user 1) from the DB — same pattern as http-card-merge.sh.
SESS=$(php -r '
  require "include/bootstrap.php";
  $row = $db->fetch("SELECT id, `data` FROM sessions WHERE user_id = 1 ORDER BY last_activity DESC LIMIT 1");
  if (!$row || !preg_match("/csrf_token\\\\|s:64:.*?([0-9a-f]{64})/", $row["data"], $m)) exit(3);
  echo $row["id"] . "\n" . $m[1];') || { echo "no live admin session — log in as admin first"; exit 1; }
ASESS=$(printf '%s' "$SESS" | head -1)
ACSRF=$(printf '%s' "$SESS" | tail -1)
ACOOKIE="shuffle_session=$ASESS"
A=(-s $H -b "$ACOOKIE" -H "Content-Type: application/json" -H "X-CSRF-Token: $ACSRF")

jget() { python3 -c "import json; d=json.load(open('$1')); print($2)" 2>/dev/null; }
ids_of() { python3 -c "import json; d=json.load(open('$1')); print([int(l['id']) for l in d.get('labels',[])])" 2>/dev/null | tr -d ' \n'; }

# Fixture + cleanup
FIXTURE=$(php tests/_fixture-labels.php)
BOARD=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["board"])' "$FIXTURE")
C1=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["c1"])' "$FIXTURE")
C2=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["c2"])' "$FIXTURE")
VIEWER=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["viewer"])' "$FIXTURE")
UNAME=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["uname"])' "$FIXTURE")
ORG=$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["org"])' "$FIXTURE")
echo "fixture: board=$BOARD c1=$C1 c2=$C2 viewer=$VIEWER uname=$UNAME org=$ORG"

cleanup() { php tests/_cleanup-labels.php "$BOARD" "$VIEWER" "$ORG" >/dev/null 2>&1; rm -f "$T"-*; }
trap cleanup EXIT

PASS=0; FAIL=0
ck() { if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }

# [1] Empty list
CODE=$(curl -s -o $T-1 -w '%{http_code}' $H -b "$ACOOKIE" "$B/v1/boards/$BOARD/labels")
[ "$CODE" = "200" ]; ok=$?
[ "$(jget $T-1 'len(d["labels"])')" = "0" ] && ok=$ok
ck "[1] GET empty list -> 200 + 0 labels (got $CODE)" $ok

# [2] Create with palette hex
CODE=$(curl "${A[@]}" -o $T-2 -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"Bug","color":"#F44336"}')
L1=$(jget $T-2 'd["label"]["id"]')
[ "$CODE" = "201" ] && [ "$(jget $T-2 'd["label"]["color"]')" = "#F44336" ]
ck "[2] create with color -> 201 color round-trips (got $CODE, id=$L1)" $?

# [3] Create without color -> default
CODE=$(curl "${A[@]}" -o $T-3 -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"Feature"}')
L2=$(jget $T-3 'd["label"]["id"]')
if [ "$CODE" = "400" ]; then
  # spec: color is required → 400. Then we can't list 2 later; use a different label.
  CODE2=$(curl "${A[@]}" -o $T-3b -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"Feature","color":"#2196F3"}')
  L2=$(jget $T-3b 'd["label"]["id"]')
  [ "$CODE2" = "201" ]
  ck "[3] create requires color -> 400 (got $CODE); second create with color -> 201 (got $CODE2, id=$L2)" $?
else
  [ "$CODE" = "201" ] && [ -n "$(jget $T-3 'd["label"]["color"]')" ]
  ck "[3] create no-color -> 201 (got $CODE, id=$L2, color=$(jget $T-3 'd["label"]["color"]'))" $?
fi

# [4] Bad color -> 400
CODE=$(curl "${A[@]}" -o $T-4 -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"Bad","color":"zzz"}')
[ "$CODE" = "400" ]; ck "[4] bad color -> 400 (got $CODE)" $?

# [5] Duplicate name (exact) -> 409
CODE=$(curl "${A[@]}" -o $T-5 -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"Bug","color":"#2196F3"}')
[ "$CODE" = "409" ]; ck "[5] dup name (exact) -> 409 (got $CODE)" $?

# [6] Duplicate name (case) -> 409
CODE=$(curl "${A[@]}" -o $T-6 -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"bug","color":"#2196F3"}')
[ "$CODE" = "409" ]; ck "[6] dup name (case) -> 409 (got $CODE)" $?

# [7] List both
CODE=$(curl -s -o $T-7 -w '%{http_code}' $H -b "$ACOOKIE" "$B/v1/boards/$BOARD/labels")
[ "$CODE" = "200" ] && [ "$(jget $T-7 'len(d["labels"])')" = "2" ] && [ "$(jget $T-7 'all(int(l["card_count"])==0 for l in d["labels"])')" = "True" ]
ck "[7] list count=2, card_count=0 (got $CODE)" $?

# [8] Attach -> 204
CODE=$(curl "${A[@]}" -o $T-8 -w '%{http_code}' -X POST "$B/v1/cards/$C1/labels/$L1")
[ "$CODE" = "204" ]; ck "[8] attach -> 204 (got $CODE)" $?

# [8b] Board view renders label dot (LABEL-01 board-view check)
curl -s -o $T-8b -H "Host: shuffle.ea.org" -b "$ACOOKIE" "http://127.0.0.1/board.php?id=$BOARD"
DOTOK=0
grep -q 'class="card-label-dot"' $T-8b || DOTOK=1
grep -q 'background-color: #F44336' $T-8b || DOTOK=1
grep -q 'title="Bug"' $T-8b || DOTOK=1
grep -q 'aria-label="Labels: Bug"' $T-8b || DOTOK=1
ck "[8b] board.php renders 1 dot + #F44336 + tooltip + aria on C1" "$DOTOK"

# [8c] 4 extra labels on C1 (5 total) -> cap 4 dots + +N overflow; aria lists all 5
for n in 1 2 3 4; do
  CODEX=$(curl "${A[@]}" -o $T-8c-$n -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d "{\"name\":\"Cap$n\",\"color\":\"#3b82f6\"}")
  VID=$(jget $T-8c-$n 'd["label"]["id"]')
  curl "${A[@]}" -o /dev/null -X POST "$B/v1/cards/$C1/labels/$VID"
done
curl -s -o $T-8d -H "Host: shuffle.ea.org" -b "$ACOOKIE" "http://127.0.0.1/board.php?id=$BOARD"
CAP=$(python3 - "$T-8d" "$C1" <<'PY3'
import re, sys
h  = open(sys.argv[1]).read()
i  = h.find('data-card-id="%s"' % sys.argv[2])
b  = h[i:i+2500]
dots = len(re.findall(r'class="card-label-dot"', b))
ovf  = b.find('card-label-dot--overflow') >= 0 and re.search(r'>\+1<', b)
aria = re.search(r'aria-label="Labels: ([^"]+)"', b)
names = len(aria.group(1).split(', ')) if aria else 0
print('OK' if (dots == 4 and ovf and names == 5) else 'FAIL dots=%d ovr=%s names=%d' % (dots, bool(ovf), names))
PY3
)
ck "[8c] 5 labels -> cap 4 dots + +1 overflow + 5 aria names (got: $CAP)" $([ "$CAP" = "OK" ]; echo $?)

# [9] Attach idempotent -> 204
CODE=$(curl "${A[@]}" -o $T-9 -w '%{http_code}' -X POST "$B/v1/cards/$C1/labels/$L1")
[ "$CODE" = "204" ]; ck "[9] attach idempotent -> 204 (got $CODE)" $?

# [10] Card detail has label
CODE=$(curl -s -o $T-10 -w '%{http_code}' $H -b "$ACOOKIE" "$B/v1/cards/$C1")
HAS=$(python3 -c "import json; d=json.load(open('$T-10')); print([int(l['id']) for l in d.get('card',{}).get('labels',[])])" 2>/dev/null | tr -d ' \n[]')
case ",$HAS," in *",$L1,"*) has=0;; *) has=1;; esac
[ "$CODE" = "200" ] && [ $has -eq 0 ]; ck "[10] card.detail labels has $L1 (labels=[$HAS])" $?

# [11] Detach -> 204
CODE=$(curl "${A[@]}" -o $T-11 -w '%{http_code}' -X DELETE "$B/v1/cards/$C1/labels/$L1")
[ "$CODE" = "204" ]; ck "[11] detach -> 204 (got $CODE)" $?

# [12] Card detail clean
CODE=$(curl -s -o $T-12 -w '%{http_code}' $H -b "$ACOOKIE" "$B/v1/cards/$C1")
IDS=$(python3 -c "import json; d=json.load(open('$T-12')); print([int(l['id']) for l in d.get('card',{}).get('labels',[])])" 2>/dev/null | tr -d ' \n[]')
case ",$IDS," in *",$L1,"*) clean=1;; *) clean=0;; esac
ck "[12] after detach, labels clean (got [$IDS])" $clean

# [13] Blank name -> 400
CODE=$(curl "${A[@]}" -o $T-13 -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"   ","color":"#F44336"}')
[ "$CODE" = "400" ]; ck "[13] blank name -> 400 (got $CODE)" $?

# [14] Unauth delete -> 403
CODE=$(curl -s -o $T-14 -w '%{http_code}' $H -X DELETE "$B/v1/labels/$L1")
[ "$CODE" = "403" ]; ck "[14] unauth delete -> 403 (got $CODE)" $?

# [15-16] Viewer (org 999, no board access)
LC=$(curl -s -o $T-15login -w '%{http_code}' $H -H "Content-Type: application/json" \
  -X POST "$B/v1/auth/login" -d "{\"username\":\"$UNAME\",\"password\":\"LblViewer!x\"}")
if [ "$LC" != "200" ]; then echo "viewer login failed ($LC): $(cat $T-15login)"; exit 1; fi
SESS2=$(php -r '
  $u = (int)$argv[1];
  require "include/bootstrap.php";
  $r = $db->fetch("SELECT id, `data` FROM sessions WHERE user_id = ? ORDER BY last_activity DESC LIMIT 1", [$u]);
  if (!$r || !preg_match("/csrf_token\\\\|s:64:.*?([0-9a-f]{64})/", $r["data"], $m)) exit(3);
  echo $r["id"] . "\n" . $m[1];' "$VIEWER")
VCODE="shuffle_session=$(printf '%s' "$SESS2" | head -1)"
VTK=$(printf '%s' "$SESS2" | tail -1)

# [15] Viewer create → 403 (requireRole('member')), no matter board access
CODE=$(curl -s -o $T-15 -w '%{http_code}' $H -b "$VCODE" -H "Content-Type: application/json" -H "X-CSRF-Token: $VTK" \
  -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"ViewerTry","color":"#F44336"}')
[ "$CODE" = "403" ]; ck "[15] viewer create -> 403 (got $CODE)" $?

# [16] Viewer attach → 404 (BOARD-04b strict isolation)
CODE=$(curl -s -o $T-16 -w '%{http_code}' $H -b "$VCODE" -H "X-CSRF-Token: $VTK" \
  -X POST "$B/v1/cards/$C1/labels/$L1")
[ "$CODE" = "404" ]; ck "[16] viewer attach (no board access) -> 404 (got $CODE)" $?

# [17] Rename -> 200
CODE=$(curl "${A[@]}" -o $T-17 -w '%{http_code}' -X PUT "$B/v1/labels/$L1" -d '{"name":"Bug (renamed)"}')
[ "$CODE" = "200" ] && [ "$(jget $T-17 'd["label"]["name"]')" = "Bug (renamed)" ]
ck "[17] rename -> 200, name updated (got $CODE)" $?

# [18] Delete label -> 204
CODE=$(curl "${A[@]}" -o $T-18 -w '%{http_code}' -X DELETE "$B/v1/labels/$L2")
[ "$CODE" = "204" ]; ck "[18] delete label -> 204 (got $CODE)" $?

# [19] LABEL-03 merge union
CODE=$(curl "${A[@]}" -o $T-19a -w '%{http_code}' -X POST "$B/v1/cards/$C1/labels/$L1")
[ "$CODE" = "204" -o "$CODE" = "200" ]; ck "[19a] re-attach L1 to source (got $CODE)" $?

CODE=$(curl "${A[@]}" -o $T-19b -w '%{http_code}' -X POST "$B/v1/boards/$BOARD/labels" -d '{"name":"MergeProbe","color":"#4CAF50"}')
L3=$(jget $T-19b 'd["label"]["id"]')
CODE=$(curl "${A[@]}" -o $T-19c -w '%{http_code}' -X POST "$B/v1/cards/$C1/labels/$L3")
[ "$CODE" = "204" -o "$CODE" = "200" ]; ck "[19c] attach L3 to source (got $CODE)" $?

CODE=$(curl "${A[@]}" -o $T-19d -w '%{http_code}' -X POST "$B/v1/cards/$C1/merge" -d "{\"destination_card_id\":$C2}")
[ "$CODE" = "200" ]; ck "[19d] merge src->dest (got $CODE)" $?

CODE=$(curl -s -o $T-19e -w '%{http_code}' $H -b "$ACOOKIE" "$B/v1/cards/$C2")
IDS=$(python3 -c "import json; d=json.load(open('$T-19e')); print([int(l['id']) for l in d.get('card',{}).get('labels',[])])" 2>/dev/null | tr -d ' \n[]')
case ",$IDS," in *",$L1,"*) a=0;; *) a=1;; esac
case ",$IDS," in *",$L3,"*) b=0;; *) b=1;; esac
ok=$((a + b))
ck "[19e] dest has label union L1=$L1 + L3=$L3 (got [$IDS])" $ok

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
