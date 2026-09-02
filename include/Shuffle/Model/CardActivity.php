<?php
declare(strict_types=1);

namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Card activity data access layer.
 *
 * Thin DAO for the `card_activity` table (ACTIVITY-01..03). Append-only
 * audit log: one row per notable card event, written atomically with the
 * underlying action. Names are snapshotted into `payload_json` at write
 * time so the record survives lane/user/label renames and deletions
 * (decision: Trello/Linear pattern).
 *
 * The table is the single source of truth that prio-digest "Done yesterday"
 * reads from (replaces the separate `card_moves` table from that plan).
 *
 * v1 event types:
 *   card_created | card_moved | card_edited | assigned | unassigned
 *   card_archived | card_restored
 *   attachment_added | attachment_removed
 *   checklist_added | checklist_renamed | checklist_removed
 *   comment_created | comment_edited | comment_deleted
 *   card_merged (CARD-10..13; written on the SURVIVOR card, payload
 *   {source_card: {id, title}})
 *
 * label changes intentionally do NOT write an activity row (LABEL-01, 2026-09-02) — low-signal; the card_merged payload already snapshots the source for the merge case (LABEL-03).
 *
 * Index usage:
 *   (card_id, id DESC)         — feed hot path (newest first)
 *   (board_id, event, created_at) — digest "done yesterday" range scan
 *   (actor_id, created_at)     — v2 "what did X do"
 */
class CardActivity
{
    private Database $db;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Inserts a single activity row.
     *
     * Called by ActivityService::log (the only public write path).
     * The payload array is JSON-encoded before bind.
     *
     * @param int         $cardId    Card ID
     * @param int         $boardId   Board ID (denormalized for board-scoped queries)
     * @param string      $event     Event name (see v1 event types above)
     * @param int         $actorId   User ID of the actor
     * @param array|null  $payload   Event-specific snapshot (nullable)
     * @return int The new row's ID
     */
    public function insert(int $cardId, int $boardId, string $event, int $actorId, array|null $payload): int
    {
        $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

        $this->db->execute(
            'INSERT INTO card_activity (card_id, board_id, event, actor_id, payload_json)
             VALUES (?, ?, ?, ?, ?)',
            [$cardId, $boardId, $event, $actorId, $payloadJson]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the activity feed for a card (newest first).
     *
     * @param int      $cardId   Card ID
     * @param int      $limit    Max rows (capped to 500 by the service)
     * @param int|null $beforeId Only rows with id < $beforeId (scrolling older)
     * @return array Array of card_activity rows + actor name
     */
    public function feedForCard(int $cardId, int $limit, ?int $beforeId = null): array
    {
        $sql = 'SELECT a.id, a.card_id, a.board_id, a.event, a.actor_id, a.payload_json, a.created_at,
                       u.name AS actor_name
                FROM card_activity a
                JOIN users u ON a.actor_id = u.id
                WHERE a.card_id = ?';

        $params = [$cardId];

        if ($beforeId !== null) {
            $sql .= ' AND a.id < ?';
            $params[] = $beforeId;
        }

        $sql .= ' ORDER BY a.id DESC LIMIT ' . (int) $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Returns the count of activity rows for a card.
     */
    public function countForCard(int $cardId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM card_activity WHERE card_id = ?',
            [$cardId]
        );

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Returns activity rows on a board matching an event + optional time
     * window. Used by prio-digest "Done yesterday" (feature/prio-digest)
     * after this branch merges. The digest queries for `card_moved` rows
     * where `payload_json.to_lane.title` is a Done-lane title (matched
     * case-insensitively in PHP, not SQL — lane names vary by board) and
     * `created_at` falls in "yesterday".
     *
     * @param int         $boardId
     * @param string      $event     Event filter (e.g. 'card_moved')
     * @param string|null $since     'Y-m-d H:i:s' or null for unbounded
     * @param string|null $until     'Y-m-d H:i:s' or null for unbounded
     * @return array Array of card_activity rows
     */
    public function feedForBoardEvent(int $boardId, string $event, ?string $since, ?string $until): array
    {
        $sql = 'SELECT a.id, a.card_id, a.board_id, a.event, a.actor_id, a.payload_json, a.created_at,
                       u.name AS actor_name
                FROM card_activity a
                JOIN users u ON a.actor_id = u.id
                WHERE a.board_id = ? AND a.event = ?';

        $params = [$boardId, $event];

        if ($since !== null) {
            $sql .= ' AND a.created_at >= ?';
            $params[] = $since;
        }
        if ($until !== null) {
            $sql .= ' AND a.created_at <= ?';
            $params[] = $until;
        }

        $sql .= ' ORDER BY a.created_at ASC, a.id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Returns activity rows matching an event + optional time window,
     * joined to the live card/board/actor names (no board predicate —
     * for cross-board scans like the priority digest's "Done yesterday").
     *
     * The digest (feature/prio-digest, PRIO-14) queries for `card_moved`
     * rows in a 24h window; the service then filters done-lane targets
     * (case-insensitive in PHP — lane names vary by board) and board
     * access per row. Rows whose card was deleted are already gone
     * (FK ON DELETE CASCADE), so the INNER JOINs are safe.
     *
     * @param string      $event   Event filter (e.g. 'card_moved')
     * @param string|null $since  'Y-m-d H:i:s' or null for unbounded
     * @param string|null $until  'Y-m-d H:i:s' or null for unbounded
     * @param int        $limit   Max rows (defensive cap; 24h moves are few)
     * @return array Array of rows with card/board/actor titles inlined
     */
    public function eventBetween(string $event, ?string $since, ?string $until, int $limit = 500): array
    {
        $sql = 'SELECT a.id, a.card_id, a.board_id, a.actor_id, a.payload_json, a.created_at,
                       c.title AS card_title,
                       b.title AS board_title,
                       u.name  AS actor_name
                FROM card_activity a
                JOIN cards  c ON c.id = a.card_id
                JOIN boards b ON b.id = a.board_id
                JOIN users  u ON u.id = a.actor_id
                WHERE a.event = ?';

        $params = [$event];

        if ($since !== null) {
            $sql .= ' AND a.created_at >= ?';
            $params[] = $since;
        }
        if ($until !== null) {
            $sql .= ' AND a.created_at <= ?';
            $params[] = $until;
        }

        $sql .= ' ORDER BY a.created_at ASC, a.id ASC LIMIT ' . (int) $limit;

        return $this->db->fetchAll($sql, $params);
    }
}
