<?php
/**
 * One-off migration: create the `user_prio` table (personal priority list,
 * PRIO-01..11).
 *
 * Usage:
 *   php bin/add-user-prio.php            # apply DDL (idempotent)
 *   php bin/add-user-prio.php --dry-run  # report only, write nothing
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

$exists = $db->fetch(
    "SELECT TABLE_NAME AS name FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_prio'"
);

if ($exists !== null) {
    echo "DDL: table user_prio already present — nothing to do\n";
    exit(0);
}

if ($dryRun) {
    echo "DDL: would create table user_prio\n";
    echo "     columns: id, user_id, card_id, position, added_at\n";
    echo "     UNIQUE (user_id, card_id), KEY (user_id, position)\n";
    echo "     FK user_id -> users.id ON DELETE CASCADE\n";
    echo "     FK card_id -> cards.id ON DELETE CASCADE\n";
    exit(0);
}

$db->execute(
    "CREATE TABLE IF NOT EXISTS `user_prio` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `card_id`    INT UNSIGNED NOT NULL,
        `position`   INT UNSIGNED NOT NULL,
        `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_prio_user_card` (`user_id`, `card_id`),
        KEY `idx_user_prio_user_pos` (`user_id`, `position`),
        CONSTRAINT `fk_user_prio_user` FOREIGN KEY (`user_id`)
            REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_user_prio_card` FOREIGN KEY (`card_id`)
            REFERENCES `cards` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "DDL: created table user_prio\n";
