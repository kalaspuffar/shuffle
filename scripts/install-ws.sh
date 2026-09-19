#!/usr/bin/env bash
# install-ws.sh — install the RT-03 push daemon as a systemd service.
#
# Usage (as root, e.g. sudo):
#     sudo bash scripts/install-ws.sh [shuffle_root]
#   default shuffle_root = /home/<installing-user>/shuffle — pass the path if
#   the app lives elsewhere.
#
# Steps:
#   1. substitute __USER__ / __SHUFFLE_ROOT__ in the unit template
#   2. install to /etc/systemd/system/shuffle-ws.service (backup any live one)
#   3. verify Apache has proxy + proxy_wstunnel enabled (error + hint if not)
#   4. daemon-reload, enable, start, then report a status block
#
# Idempotent: re-running after code updates reinstalls the unit and restarts.
set -euo pipefail

SHUFFLE_ROOT="${1:-}"
if [ -z "$SHUFFLE_ROOT" ]; then
    SHUFFLE_ROOT="${SHUFFLE_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
fi
if [ ! -f "$SHUFFLE_ROOT/bin/ws-daemon.php" ]; then
    echo "ERROR: $SHUFFLE_ROOT/bin/ws-daemon.php not found (pass the repo root)" >&2
    exit 1
fi

UNIT_SRC="$(cd "$(dirname "$0")" && pwd)/systemd/shuffle-ws.service"
UNIT_DST=/etc/systemd/system/shuffle-ws.service
# Run as the invoking user's account owner — the daemon needs the app tree
# (read) and the DB; running it as the account owner keeps file ACLs intact.
if [ -n "${SUDO_USER:-}" ]; then
    RUN_USER="$SUDO_USER"
else
    RUN_USER="$(id -un)"
fi

echo "-- shuffle-ws installer"
echo "   root : $SHUFFLE_ROOT"
echo "   user : $RUN_USER"

# ---- 1) render the unit ---------------------------------------------------
sed -e "s/__USER__/$RUN_USER/g" \
    -e "s#__SHUFFLE_ROOT__#$SHUFFLE_ROOT#g" \
    "$UNIT_SRC" > "$UNIT_DST.new"

# ---- 2) install (backup prior, atomic-ish swap) ---------------------------
if [ -f "$UNIT_DST" ]; then
    cp -a "$UNIT_DST" "$UNIT_DST.bak.$(date +%Y%m%d%H%M%S)"
    echo "   backed up previous unit"
fi
mv "$UNIT_DST.new" "$UNIT_DST"
echo "   installed $UNIT_DST"

# ---- 3) Apache dependency -------------------------------------------------
if command -v a2query >/dev/null 2>&1; then
    for mod in proxy proxy_wstunnel; do
        if ! a2query -m "$mod" >/dev/null 2>&1; then
            echo "ERROR: Apache module '$mod' is not enabled." >&2
            echo "  run:  a2enmod $mod && systemctl reload apache2" >&2
            exit 1
        fi
    done
    echo "   apache: proxy + proxy_wstunnel present"
else
    echo "   WARN: a2query not found — skipped Apache module check"
fi

# ---- 4) reload + enable + start -------------------------------------------
systemctl daemon-reload
systemctl enable shuffle-ws.service
# restart = covers both first-start and upgrade paths
systemctl restart shuffle-ws.service

sleep 1
echo
echo "-- status"
systemctl --no-pager --lines=5 status shuffle-ws.service || true
echo
echo "-- latest journal lines"
journalctl -u shuffle-ws.service --no-pager -n 8
