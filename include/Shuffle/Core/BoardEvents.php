<?php
/**
 * BoardEvents — shared helpers for the board_events change feed (RT-03,
 * v1.17 §5.25). Used by bin/ws-daemon.php (the consumer) and the e2e test
 * suite (the contract pin). The web app never touches this table.
 *
 * The table: board_events(id BIGINT PK, board_id INT, version BIGINT,
 * created_at, UNIQUE(board_id, version), KEY(board_id)) — appended by
 * Board::incrementVersion(), read by the daemon's tail + prune.
 */
namespace Shuffle\Core;

use PDO;
use PDOException;

class BoardEvents
{
    /**
     * True when the board_events table exists (false on a pre-migration
     * install — callers must degrade silently; the poll path is unaffected).
     */
    public static function isAvailable(PDO $pdo): bool
    {
        try {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM board_events LIMIT 1')->fetchColumn();
            return $n >= 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * The most recent version row per board for ids strictly greater than
     * $afterId (the daemon's cursor). Returns [board_id => version]
     * (the LATEST version per board in the window). Throws when the table
     * is absent — callers catch per their degradation policy.
     */
    public static function latestPerBoardSince(PDO $pdo, int $afterId, int $limit = 2000): array
    {
        $stmt = $pdo->prepare(
            'SELECT board_id, version FROM board_events
             WHERE id > ? ORDER BY id ASC LIMIT ' . (int) $limit
        );
        $stmt->execute([$afterId]);
        $latest = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $latest[(int) $row['board_id']] = (int) $row['version'];
        }
        return $latest;
    }

    /**
     * Advances the caller's cursor to the current MAX(id) (so the next poll
     * only sees new rows). Returns the new cursor (0 when the table is
     * empty/absent).
     */
    public static function advanceCursor(PDO $pdo): int
    {
        $v = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM board_events')->fetchColumn();
        return (int) $v;
    }

    /**
     * Keeps the feed bounded: deletes the oldest rows beyond $keep (and any
     * row older than $maxAgeSeconds). Returns [byCap:int, byAge:int].
     *
     * @throws PDOException when the table is absent (caller degrades)
     */
    public static function prune(PDO $pdo, int $keep = 5000, int $maxAgeSeconds = 3600): array
    {
        $byCap = 0;
        $total = (int) $pdo->query('SELECT COUNT(*) FROM board_events')->fetchColumn();
        if ($total > $keep) {
            $delN = $total - $keep;
            $pdo->prepare(
                'DELETE FROM board_events WHERE `id` IN ('
                . "SELECT `sub_id` FROM (SELECT `id` AS `sub_id` FROM board_events ORDER BY `id` ASC LIMIT {$delN}) AS sub)"
            )->execute();
            $byCap = $delN;
        }
        $stmt = $pdo->prepare('DELETE FROM board_events WHERE created_at < (NOW() - INTERVAL ? SECOND)');
        $stmt->execute([$maxAgeSeconds]);
        return ['byCap' => $byCap, 'byAge' => (int) $stmt->rowCount()];
    }
}
