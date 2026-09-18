<?php
/**
 * One-off migration: create the `board_events` table (RT-03, spec v1.17 §5.25).
 *
 * The table is a change feed for board version bumps: Board::incrementVersion()
 * appends one row per bump, and the WebSocket push daemon (bin/ws-daemon.php)
 * tails it to push `{type:"board_version"}` frames to subscribed sockets.
 *
 * The UNIQUE KEY (board_id, version) makes a concurrent double-INSERT a no-op
 * (`INSERT IGNORE` in the model) — the same bump can never feed the daemon twice.
 *
 * Pruning: the daemon deletes old rows (keeps the tail needed by live sockets,
 * capped at ~5k rows). No index beyond (board_id, version) is required.
 *
 * Usage:
 *   php bin/add-board-events.php            # apply (idempotent)
 *
 * Re-running is a no-op (information_schema TABLES check first).
 */
require dirname(__DIR__) . '/include/Shuffle/Core/Database.php';

$configFile = dirname(__DIR__) . '/etc/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "FATAL: $configFile not found\n");
    exit(1);
}
$config = (array) require $configFile;
$cfg = $config['db'];
$db = new Shuffle\Core\Database($cfg);

$schema = $cfg['name'] ?? 'shuffle';
$has = $db->fetch(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [$schema, 'board_events']
);
if ($has !== null) {
    echo "board_events table already exists — nothing to do.\n";
    exit(0);
}

$db->execute(
    'CREATE TABLE IF NOT EXISTS `board_events` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `board_id`    INT UNSIGNED NOT NULL,
        `version`     BIGINT UNSIGNED NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_board_events_board_version` (`board_id`, `version`),
        KEY `idx_board_events_board_id` (`board_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

echo "Created board_events table.\n";
