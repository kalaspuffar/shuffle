<?php
/**
 * One-off migration: add `email_notifications` to users (NOTIF-06, spec v1.18 §5.26).
 *
 *   email_notifications TINYINT(1) NOT NULL DEFAULT 0
 *
 * Per-user email-notification opt-in. OFF by default (2026-09-20, Daniel) —
 * a user who has not enabled it on the profile page never receives
 * notification email, even when SMTP is fully configured. Display/preference
 * data, not identity: no FKs, no unique key, NOT NULL with an explicit
 * default so `SELECT *` consumers always see the column.
 *
 * The column is added AFTER `email` and BEFORE `role` so the SELECT column
 * order stays predictable (same placement convention as v1.14 phone/location/bio).
 *
 * Usage:
 *   php bin/add-user-email-notifications.php            # apply (idempotent)
 *   php bin/add-user-email-notifications.php --dry-run  # report only
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
    ['users', 'email_notifications']
);

if ($existing !== null) {
    echo "SKIP   users.email_notifications (already present)\n";
} else {
    $sql = "ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Per-user email notification opt-in (NOTIF-06)' AFTER email";
    if ($dryRun) {
        echo "DRYRUN $sql\n";
    } else {
        $db->execute($sql);
        echo "ADD    users.email_notifications\n";
    }
}

echo $dryRun ? "Done (dry-run).\n" : "Done.\n";
