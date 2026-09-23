<?php
/**
 * One-off migration: add the `due_reminders` claim table (NOTIF-05, spec v1.23 §5.30).
 *
 *   due_reminders (card_id FK, user_id FK, reminded_at)  PK (card_id, user_id)
 *
 * The claim table that makes "one due-date reminder per card per recipient"
 * true and crash-safe. One row = "this user was already reminded about this
 * card's due date". The scan (NotificationService::scanDueReminders) claims
 * with INSERT IGNORE *before* firing: affectedRows() > 0 means this run won
 * the claim and may fire; affectedRows() == 0 means an earlier run already
 * fired for this (card, user) pair, so it is skipped. A crash between the
 * claim and the fire therefore biases to "at most one reminder, never two" —
 * a missed reminder is tolerable, a double reminder is noise.
 *
 * FKs cascade: a deleted card (which cascade-deletes its assignments) or a
 * deleted user cleans up the claim rows.
 *
 * Usage:
 *   php bin/add-due-reminders.php            # apply (idempotent)
 *   php bin/add-due-reminders.php --dry-run  # report only
 *
 * Re-running is a no-op: the table is first checked against
 * information_schema.TABLES before the CREATE is issued.
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
    'SELECT table_name FROM information_schema.TABLES
     WHERE table_schema = DATABASE() AND table_name = ?',
    ['due_reminders']
);

$hasTable  = ($existing !== null);
$hasDateCol = $hasTable
    && ($db->fetch(
        "SELECT column_name FROM information_schema.COLUMNS
         WHERE table_schema = DATABASE() AND table_name = 'due_reminders' AND column_name = 'due_date'",
        []
    ) !== null);

if ($hasTable && $hasDateCol) {
    echo "SKIP   due_reminders table (already present with due_date)\n";
} elseif ($hasTable) {
    // The table exists from an early v1.23 build (2-col PK, no due_date).
    // The table is brand-new everywhere (v1.23 just shipped) and holds no
    // durable data, so a clean rebuild is the safe idempotent path.
    if ($dryRun) {
        echo "DRYRUN DROP TABLE due_reminders -- then CREATE with (card_id, user_id, due_date) PK\n";
    } else {
        $db->query('DROP TABLE due_reminders');
        $db->execute(
            "CREATE TABLE due_reminders (
                card_id     INT UNSIGNED NOT NULL,
                user_id     INT UNSIGNED NOT NULL,
                due_date    DATE         NOT NULL,
                reminded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (card_id, user_id, due_date),
                CONSTRAINT fk_due_reminders_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE,
                CONSTRAINT fk_due_reminders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "REBUILD due_reminders (2-col PK -> 3-col PK with due_date)\n";
    }
} else {
    if ($dryRun) {
        echo "DRYRUN CREATE TABLE due_reminders (3-col PK with due_date)\n";
    } else {
        $db->execute(
            "CREATE TABLE due_reminders (
                card_id     INT UNSIGNED NOT NULL,
                user_id     INT UNSIGNED NOT NULL,
                due_date    DATE         NOT NULL,
                reminded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (card_id, user_id, due_date),
                CONSTRAINT fk_due_reminders_card FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE,
                CONSTRAINT fk_due_reminders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "CREATE due_reminders\n";
    }
}

echo $dryRun ? "Done (dry-run).\n" : "Done.\n";
