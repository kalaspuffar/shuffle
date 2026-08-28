<?php
/**
 * One-off migration: add `lanes.icon` (VARCHAR 16 NULL) and backfill icons
 * from lane names (case-insensitive match, only where icon IS NULL).
 *
 * Usage:
 *   php bin/add-lane-icon.php            # apply DDL + backfill (idempotent)
 *   php bin/add-lane-icon.php --dry-run  # report only, write nothing
 *
 * Re-running is a no-op. Database credentials come from etc/config.php.
 */

require dirname(__DIR__) . '/include/Shuffle/Core/Database.php';

$dryRun = in_array('--dry-run', $argv, true);

$configFile = dirname(__DIR__) . '/etc/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "FATAL: " . $configFile . " not found\n");
    exit(1);
}

$config = (array) require $configFile;
$db = new Shuffle\Core\Database($config['db']);

// 1. Add the column if it is not present (idempotent DDL).
$exists = $db->fetch(
    "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lanes' AND COLUMN_NAME = 'icon'"
);

if ($exists === null) {
    if ($dryRun) {
        echo "DDL: would add lanes.icon VARCHAR(16) NULL\n";
    } else {
        $db->execute('ALTER TABLE lanes ADD COLUMN icon VARCHAR(16) NULL AFTER title');
        echo "DDL: added lanes.icon VARCHAR(16) NULL\n";
    }
} else {
    echo "DDL: lanes.icon already present — skipping\n";
}

// 2. Backfill icons from lane titles (case-insensitive), icon NULL rows only.
$map = [
    'inbox'               => '📥',
    'resources'           => '🔖',
    'backlog'             => '⏳',
    'up next'             => '🚦',
    'in progress'         => '🔨',
    'blocked'             => '⛔',
    'in review'           => '👀',
    'waiting for release' => '📦',
    'qa'                  => '🧪',
    'quality assurance'   => '🧪',
    'done'                => '✅',
    "won't fix"           => '🚫',
    'wont fix'            => '🚫',
];

if ($dryRun && $exists === null) {
    // Column does not exist yet and we are not applying it, so the backfill
    // SQL (which references `icon`) cannot run. Report that and stop.
    echo "\nDRY RUN finished (column not yet added — backfill skipped).\n";
    exit(0);
}

$lanes = $db->fetchAll('SELECT id, title FROM lanes WHERE icon IS NULL ORDER BY id');

$matched = 0;
foreach ($lanes as $lane) {
    $key = mb_strtolower(trim((string) $lane['title']), 'UTF-8');
    if (!isset($map[$key])) {
        continue;
    }
    $icon = $map[$key];
    $matched++;
    if ($dryRun) {
        printf("  [would update] lane #%d (%s) → %s\n", $lane['id'], $lane['title'], $icon);
    } else {
        $db->execute('UPDATE lanes SET icon = ? WHERE id = ?', [$icon, $lane['id']]);
        printf("  updated lane #%d (%s) → %s\n", $lane['id'], $lane['title'], $icon);
    }
}

printf("\nScanned %d lanes without icon; %d matched a default name.\n", count($lanes), $matched);
echo $dryRun ? "DRY RUN finished — no writes were made.\n" : "Migration complete.\n";
