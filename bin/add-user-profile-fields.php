<?php
/**
 * One-off migration: add `phone`, `location`, `bio` to users (USER-01, spec v1.14 §5.22).
 *
 * All three columns are NULL-able display data (not identity):
 *   phone    VARCHAR(32)  NULL   — contact line shown on the user's profile / contact tooltip
 *   location VARCHAR(120) NULL   — free-text location
 *   bio      TEXT         NULL   — short free-text profile bio
 *
 * The columns are added AFTER `email` and BEFORE `role` so the SELECT
 * column order stays predictable and the model's select list stays readable.
 *
 * Usage:
 *   php bin/add-user-profile-fields.php            # apply (idempotent)
 *   php bin/add-user-profile-fields.php --dry-run  # report only
 *
 * Re-running is a no-op: each column is first checked against
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

$columns = [
    'phone'    => "VARCHAR(32)  NULL COMMENT 'Contact phone line (USER-01)'",
    'location' => "VARCHAR(120) NULL COMMENT 'Free-text location (USER-01)'",
    'bio'      => "TEXT         NULL COMMENT 'Short profile bio (USER-01)'",
];

foreach ($columns as $col => $ddl) {
    $existing = $db->fetch(
        'SELECT column_name FROM information_schema.COLUMNS
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        ['users', $col]
    );

    if ($existing !== null) {
        echo "SKIP   users.$col (already present)\n";
        continue;
    }

    $sql = "ALTER TABLE users ADD COLUMN $col $ddl AFTER email";
    if ($dryRun) {
        echo "DRYRUN $sql\n";
    } else {
        $db->execute($sql);
        echo "ADD    users.$col\n";
    }
}

echo $dryRun ? "Done (dry-run).\n" : "Done.\n";
