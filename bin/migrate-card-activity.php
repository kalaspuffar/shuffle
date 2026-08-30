<?php
/**
 * One-off migration: create the `card_activity` table (card history /
 * audit log, ACTIVITY-01..03).
 *
 * Usage:
 *   php bin/add-card-activity.php            # apply DDL (idempotent)
 *   php bin/add-card-activity.php --dry-run  # report only, write nothing
 *
 * Re-running is a no-op. Database credentials come from etc/config.php.
 *
 * The log starts COLD by design (decision §5.1): no backfill of
 * pre-feature history — cards.updated_at is unreliable and there is no
 * movement record to reconstruct from.
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

$exists = $db->fetch(
    "SELECT TABLE_NAME AS name FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_activity'"
);

if ($exists !== null) {
    echo "DDL: table card_activity already present — nothing to do\n";
    exit(0);
}

if ($dryRun) {
    echo "DDL: would create table card_activity\n";
    echo "     columns: id, card_id, board_id, event, actor_id, payload_json, created_at\n";
    echo "     KEY (card_id, id)  — feed hot path (newest first)\n";
    echo "     KEY (board_id, event, created_at) — digest 'done yesterday' range scan\n";
    echo "     KEY (actor_id, created_at) — v2 'what did X do'\n";
    echo "     FK card_id  -> cards.id  ON DELETE CASCADE\n";
    echo "     FK actor_id -> users.id  ON DELETE CASCADE\n";
    exit(0);
}

$db->execute(
    "CREATE TABLE IF NOT EXISTS `card_activity` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `card_id`        INT UNSIGNED NOT NULL,
        `board_id`       INT UNSIGNED NOT NULL,
        `event`          VARCHAR(32)  NOT NULL,
        `actor_id`       INT UNSIGNED NOT NULL,
        `payload_json`   JSON         NULL,
        `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_card_activity_card_id_id` (`card_id`, `id`),
        KEY `idx_card_activity_board_event_created` (`board_id`, `event`, `created_at`),
        KEY `idx_card_activity_actor_created` (`actor_id`, `created_at`),
        CONSTRAINT `fk_card_activity_card` FOREIGN KEY (`card_id`)
            REFERENCES `cards` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_card_activity_actor` FOREIGN KEY (`actor_id`)
            REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "DDL: created table card_activity\n";
