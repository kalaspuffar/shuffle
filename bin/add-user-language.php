<?php
/**
 * One-off migration: add `language` to users (INTL-01/02, spec v1.22 §5.29).
 *
 *   language VARCHAR(10) NULL
 *
 * Per-user interface-language preference, persisted server-side so it
 * survives browser restarts and device changes (INTL-02: the preference
 * travels with the USER). NULL is a first-class value meaning "use the app
 * default" (the setup-wizard app.locale) — unlike theme_preference it has no
 * fixed default, because the default IS the install's choice.
 *
 * The column is added AFTER `theme_preference` and BEFORE `phone` so the
 * physical column order stays predictable (consistent with SELECT_COLUMNS in
 * User::SELECT_COLUMNS).
 *
 * Usage:
 *   php bin/add-user-language.php            # apply (idempotent)
 *   php bin/add-user-language.php --dry-run  # report only
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

$column = 'language';
$definition = "VARCHAR(10) NULL";

$existing = $db->fetch(
    'SELECT column_name FROM information_schema.COLUMNS
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
    ['users', $column]
);

if ($existing !== null) {
    echo "-- users.$column already exists; nothing to do.\n";
    exit(0);
}

$sql = "ALTER TABLE users ADD COLUMN $column $definition AFTER theme_preference";

if ($dryRun) {
    echo "DRY-RUN  $sql\n";
    exit(0);
}

$db->execute($sql);
echo "OK  ALTER TABLE users ADD COLUMN $column $definition\n";
