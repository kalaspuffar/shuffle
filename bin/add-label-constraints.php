<?php
/**
 * One-off migration: add UNIQUE (board_id, name) index on labels (LABEL-02, §5.15).
 *
 * The schema DDL as shipped does not include this unique key; without it
 * the 409 "duplicate name on board" guard in LabelService is best-effort
 * (two concurrent creates could both race past the check). This index
 * makes the guarantee hold at the storage layer under concurrent load.
 *
 * Usage:
 *   php bin/add-label-constraints.php            # apply (idempotent)
 *   php bin/add-label-constraints.php --dry-run  # report only
 *
 * Re-running is a no-op.
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

$idxName = 'uq_labels_board_name';

// Show CREATE TABLE for the diagnostic
$ddlRow = $db->fetchAll('SHOW CREATE TABLE labels');
$ddl = $ddlRow[0]['Create Table'] ?? '';
echo "Current labels DDL:\n" . str_replace("\n", "\n  ", $ddl) . "\n\n";

// Check for existing duplicates (blocks ADD UNIQUE if present)
$dupes = $db->fetchAll(
    'SELECT board_id, name, COUNT(*) c FROM labels
     GROUP BY board_id, name HAVING c > 1'
);
if ($dupes) {
    echo "BLOCKED: duplicate (board_id,name) rows exist. Dedupe before applying:\n";
    foreach ($dupes as $d) {
        printf("  board=%s name=%s rows=%s\n", $d['board_id'], $d['name'], $d['c']);
    }
    exit(2);
}
echo "No duplicate (board_id,name) rows — safe to add UNIQUE.\n";

if (preg_match('/' . $idxName . '/', $ddl)) {
    echo "Index `$idxName` already present (no-op).\n";
    exit(0);
}

$sql = "ALTER TABLE labels ADD CONSTRAINT `$idxName` UNIQUE (board_id, name)";
echo ($dryRun ? "[dry-run] WOULD RUN: " : "") . $sql . "\n";
if ($dryRun) exit(0);

try {
    $db->execute($sql);
    echo "Applied: `labels` now has UNIQUE (board_id, name).\n";
} catch (Throwable $e) {
    echo "FAILED (possibly already present from a partial run): " . $e->getMessage() . "\n";
    exit(1);
}
