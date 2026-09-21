<?php
/**
 * One-off migration: add `theme_preference` to users (THEME-01, spec v1.20 §5.27).
 *
 *   theme_preference ENUM('dark','light') NOT NULL DEFAULT 'dark'
 *
 * Per-user theme choice, persisted server-side so it survives browser
 * restarts and device changes (THEME-02: the preference travels with the
 * USER). Dark is the default — matches the historical look and requires no
 * explicit action.
 *
 * The column is added AFTER `email_notifications` and BEFORE `phone` so the
 * SELECT column order stays predictable (same placement convention as the
 * v1.18 email_notifications migration, and consistent with the SELECT_COLUMNS
 * list in User::SELECT_COLUMNS).
 *
 * Usage:
 *   php bin/add-user-theme-preference.php            # apply (idempotent)
 *   php bin/add-user-theme-preference.php --dry-run  # report only
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

$column = 'theme_preference';
$definition = "ENUM('dark','light') NOT NULL DEFAULT 'dark'";

$existing = $db->fetch(
    'SELECT column_name FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
    ['users', $column]
);

if ($existing !== null) {
    echo "-- users.$column already exists; nothing to do.\n";
    exit(0);
}

$sql = "ALTER TABLE users ADD COLUMN $column $definition AFTER email_notifications";

if ($dryRun) {
    echo "DRY-RUN  $sql\n";
    exit(0);
}

$db->execute($sql);
echo "OK  ALTER TABLE users ADD COLUMN $column $definition\n";
