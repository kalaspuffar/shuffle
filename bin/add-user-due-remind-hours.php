<?php
/**
 * One-off migration: add `due_remind_hours` to users (NOTIF-05, spec v1.23 §5.30).
 *
 *   due_remind_hours INT UNSIGNED NULL DEFAULT NULL
 *
 * Per-user "remind me N hours before a card is due". NULL = reminders off
 * for that user (the default — the value is never set automatically, the
 * same "off until deliberately opted in" posture as users.email_notifications).
 * Settable range is 1..720 hours (1 hour .. 30 days) — enforced in
 * UserService::updateMe/updateUser, NOT in the schema (a future widen of the
 * range is a code-only change, not a migration).
 *
 * The column is added AFTER `language` (the last per-user preference column)
 * so the SELECT column order stays predictable:
 *   email_notifications, theme_preference, language, due_remind_hours.
 *
 * Usage:
 *   php bin/add-user-due-remind-hours.php            # apply (idempotent)
 *   php bin/add-user-due-remind-hours.php --dry-run  # report only
 *
 * Re-running is a no-op: the column is first checked against
 * information_schema.COLUMNS before the ALTER is issued.
 */
require dirname(__DIR__) . '/include/Shuffle/Core/Database.php';

$dryRun = in_array('--dry-run', $argv, true);
$configFile = dirname(__DIR__) . '/etc/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "FATAL: $configFile not found\n");
    exit(1);
}
$config = (array) require $configFile;
$db = new Shuffle\Core\Database($config['db']);

$existing = $db->fetch(
    'SELECT column_name FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
    ['users', 'due_remind_hours']
);

if ($existing !== null) {
    echo "SKIP   users.due_remind_hours (already present)\n";
} else {
    $sql = "ALTER TABLE users ADD COLUMN due_remind_hours INT UNSIGNED NULL COMMENT 'NOTIF-05 (v1.23): remind N hours before due, NULL = off' AFTER language";
    if ($dryRun) {
        echo "DRYRUN $sql\n";
    } else {
        $db->execute($sql);
        echo "ADD    users.due_remind_hours\n";
    }
}

echo $dryRun ? "Done (dry-run).\n" : "Done.\n";
