<?php
/**
 * One-off migration: extend `notifications.type` with 'due' (NOTIF-05, spec v1.23 §5.30).
 *
 *   type ENUM('assignment', 'comment', 'creator')  ->  ENUM('assignment', 'comment', 'creator', 'due')
 *
 * The bell panel gains a fourth event type: the due-date reminder. `reference_id`
 * already carries the card id and `comment_id` stays NULL for this type — no
 * other schema change is needed. The new value appends at the end of the ENUM
 * (MariaDB/MySQL ENUM values are positional — order does not affect storage,
 * and appending is the safe form: existing rows keep their ordinal).
 *
 * Usage:
 *   php bin/add-notification-due-type.php            # apply (idempotent)
 *   php bin/add-notification-due-type.php --dry-run  # report only
 *
 * Re-running is a no-op: information_schema.COLUMNS reports the current ENUM's
 * value list; if 'due' is already present the MODIFY is skipped.
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

$col = $db->fetch(
    'SELECT column_type FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
    ['notifications', 'type']
);

// column_type is e.g. "enum('assignment','comment','creator')" — 'due' present
// as a distinct ENUM value iff the quoted list contains a standalone 'due'.
$already = ($col !== null) && (preg_match("/'due'/", $col['column_type'] ?? '') === 1);

if ($already) {
    echo "SKIP   notifications.type 'due' (already present)\n";
} else {
    $sql = "ALTER TABLE notifications MODIFY COLUMN type ENUM('assignment', 'comment', 'creator', 'due') NOT NULL";
    if ($dryRun) {
        echo "DRYRUN $sql\n";
    } else {
        $db->execute($sql);
        echo "MODIFY notifications.type += 'due'\n";
    }
}

echo $dryRun ? "Done (dry-run).\n" : "Done.\n";
