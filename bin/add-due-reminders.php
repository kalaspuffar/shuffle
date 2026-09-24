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
// cards.due_date is DATE (deadline = day), so the claim PK carries the
// day: a re-arm fires when the date moves to a DIFFERENT day.
$dateType = ($hasTable
    && $db->fetch(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE table_schema = DATABASE() AND table_name = 'due_reminders' AND column_name = 'due_date'",
        []
    )['COLUMN_TYPE'] ?? null);
$goodShape = $hasTable && str_starts_with((string) $dateType, 'date');

if ($goodShape) {
    echo "SKIP   due_reminders table (already present with a DATE due_date)\n";
} else {
    // Either the table is missing, or it holds an early v1.23 build
    // (2-col PK, or a DATE-typed due_date). It is brand-new everywhere and
    // holds no durable data, so a clean rebuild is the safe idempotent path.
    if ($dryRun) {
        echo "DRYRUN " . ($hasTable ? "DROP+CREATE" : "CREATE")
            . " due_reminders with (card_id, user_id, due_date DATETIME) PK\n";
    } else {
        if ($hasTable) {
            $db->query('DROP TABLE due_reminders');
        }
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
        echo ($hasTable ? "REBUILD" : "CREATE")
            . " due_reminders (3-col PK, due_date DATE matching cards.due_date)\n";
    }
}

echo $dryRun ? "Done (dry-run).\n" : "Done.\n";
