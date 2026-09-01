<?php
/**
 * One-off migration: extend the notifications table for creator scope
 * (NOTIF-07/08) + NOTIF-09 deep-link (`comment_id`) — v1.8.
 *
 *   1. ADD COLUMN IF NOT EXISTS comment_id INT UNSIGNED NULL
 *      (FK to comments.id ON DELETE CASCADE)
 *   2. MODIFY COLUMN type ENUM('assignment','comment','creator') NOT NULL
 *      (adding 'creator'; existing rows retain their current value)
 *
 * Usage:
 *   php bin/add-notification-comment-id.php            # apply (idempotent)
 *   php bin/add-notification-comment-id.php --dry-run  # report only
 *
 * Re-run is a no-op. Credentials come from etc/config.php.
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

// --- check existing state ---
$hasCommentId = $db->fetch(
    "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
       AND COLUMN_NAME = 'comment_id'"
);

$enumTypes = $db->fetch(
    "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
       AND COLUMN_NAME = 'type'"
);
$hasCreator = ($enumTypes && strpos($enumTypes['t'], 'creator') !== false);

if ($hasCommentId !== null && $hasCreator) {
    echo "DDL: notifications.comment_id + enum('creator') already present — nothing to do\n";
    exit(0);
}

if ($dryRun) {
    if ($hasCommentId === null) echo "DDL: would ADD COLUMN comments.comment_id INT UNSIGNED NULL (FK ON DELETE CASCADE)\n";
    if (!$hasCreator)           echo "DDL: would MODIFY type ENUM('assignment','comment','creator') NOT NULL\n";
    exit(0);
}

if ($hasCommentId === null) {
    // MariaDB supports ADD COLUMN IF NOT EXISTS (5.22 / 10.4+). Use an
    // explicit guard for older MySQL by checking first — safest across versions.
    $db->execute(
        "ALTER TABLE `notifications`
         ADD COLUMN `comment_id` INT UNSIGNED NULL AFTER `reference_id`,
         ADD CONSTRAINT `fk_notifications_comment`
             FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE"
    );
    echo "DDL: added notifications.comment_id (FK → comments.id ON DELETE CASCADE)\n";
}

if (!$hasCreator) {
    $db->execute(
        "ALTER TABLE `notifications`
         MODIFY COLUMN `type` ENUM('assignment','comment','creator') NOT NULL"
    );
    echo "DDL: extended notifications.type ENUM to include 'creator'\n";
}
