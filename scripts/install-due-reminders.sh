#!/usr/bin/env bash
# install-due-reminders.sh — install the NOTIF-05 due-reminder scan as a
# systemd one-shot service + hourly timer.
#
# Usage (as root, e.g. via sudo):
#     sudo bash scripts/install-due-reminders.sh [shuffle_root]
#   default shuffle_root = /home/<installing-user>/shuffle — pass the path if
#   the app lives elsewhere.
#
# Steps:
#   1. substitute __USER__ / __SHUFFLE_ROOT__ in the service + timer templates
#   2. install both to /etc/systemd/system (backup any live ones)
#   3. verify the one-shot runs clean by hand (bin/due-reminder-scan.php)
#   4. daemon-reload, enable + start the timer (the service is triggered by
#      the timer; we don't enable the service itself)
#   5. report a status block + a one-off run's output
#
# Idempotent: re-running after code updates reinstalls the units and restarts
# the timer. Safe — the claim table makes the scan idempotent, so a double
# install or a double tick never double-fires a reminder.
set -euo pipefail

SHUFFLE_ROOT="${1:-}"
if [ -z "$SHUFFLE_ROOT" ]; then
    SHUFFLE_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
fi
if [ ! -f "$SHUFFLE_ROOT/bin/due-reminder-scan.php" ]; then
    echo "ERROR: $SHUFFLE_ROOT/bin/due-reminder-scan.php not found (pass the repo root)" >&2
    exit 1
fi

SYSTEMD_DIR="$(cd "$(dirname "$0")" && pwd)/systemd"
SVC_DST=/etc/systemd/system/shuffle-due-reminders.service
TMR_DST=/etc/systemd/system/shuffle-due-reminders.timer

if [ -n "${SUDO_USER:-}" ]; then
    RUN_USER="$SUDO_USER"
else
    RUN_USER="$(id -un)"
fi

echo "-- shuffle-due-reminders installer"
echo "   root : $SHUFFLE_ROOT"
echo "   user : $RUN_USER"

# ---- 1) render both units -------------------------------------------------
render() {
    sed -e "s/__USER__/$RUN_USER/g" \
        -e "s#__SHUFFLE_ROOT__#$SHUFFLE_ROOT#g" \
        "$1" > "$2.new"
}
render "$SYSTEMD_DIR/shuffle-due-reminders.service" "$SVC_DST"
render "$SYSTEMD_DIR/shuffle-due-reminders.timer"    "$TMR_DST"

install_pair() {
    local src_suffix="$1" dst="/etc/systemd/system/shuffle-due-reminders$src_suffix"
    if [ -f "$dst" ]; then
        cp -a "$dst" "$dst.bak.$(date +%Y%m%d%H%M%S)"
        echo "   backed up previous $dst"
    fi
    mv "$dst.new" "$dst"
    echo "   installed $dst"
}
install_pair ".service"
install_pair ".timer"
rm -f "$SVC_DST.new" "$TMR_DST.new"

# ---- 2) one-off validation (runs as the app user, not root) ---------------
echo "-- one-off validation (first pass)"
runuser -u "$RUN_USER" -- php "$SHUFFLE_ROOT/bin/due-reminder-scan.php" || {
    echo "WARN: one-off scan reported a problem — the timer will keep retrying hourly." >&2
}

# ---- 3) reload + enable the timer (service is timer-triggered) ------------
systemctl daemon-reload
systemctl enable shuffle-due-reminders.timer
systemctl restart shuffle-due-reminders.timer

echo
echo "-- status"
systemctl --no-pager --lines=4 status shuffle-due-reminders.service || true
echo
systemctl list-timers shuffle-due-reminders.timer --no-pager --all || true
echo
echo "-- how to watch it fire"
echo "   journalctl -u shuffle-due-reminders -f"
echo
echo "-- cron fallback (hosts without systemd timers):"
echo "   0 * * * * php $SHUFFLE_ROOT/bin/due-reminder-scan.php"
echo "   (see doc/setup.md — the claim table keeps it idempotent either way)"
