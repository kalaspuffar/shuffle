<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Card data access layer.
 *
 * Provides CRUD operations for the cards table with gap-based position
 * management, archive/restore, and card assignments.
 */
class Card
{
    private Database $db;

    /** Gap between positions for new/reordered cards */
    private const POSITION_GAP = 1000;

    private const SELECT_COLUMNS = 'id, lane_id, title, description, due_date, position, is_archived, created_by, created_at, updated_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Batch-loads user assignments for a set of card IDs.
     *
     * @param array $cardIds Array of card IDs
     * @return array Map of card_id => [user rows with id, name, email]
     */
    public function batchLoadAssignments(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT ca.card_id, u.id, u.name, u.email
             FROM card_assignments ca
             JOIN users u ON ca.user_id = u.id
             WHERE ca.card_id IN (' . $placeholders . ')
             ORDER BY u.name ASC',
            $cardIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['card_id']][] = [
                'id'    => (int) $row['id'],
                'name'  => $row['name'],
                'email' => $row['email'],
            ];
        }

        return $map;
    }

    /**
     * Batch-loads comment counts for a set of card IDs.
     *
     * @param array $cardIds Array of card IDs
     * @return array Map of card_id => count
     */
    public function batchLoadCommentCounts(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT card_id, COUNT(*) AS cnt FROM comments
             WHERE card_id IN (' . $placeholders . ')
             GROUP BY card_id',
            $cardIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['card_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * Batch-loads attachment counts for a set of card IDs.
     *
     * @param array $cardIds Array of card IDs
     * @return array Map of card_id => count
     */
    public function batchLoadAttachmentCounts(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT card_id, COUNT(*) AS cnt FROM attachments
             WHERE card_id IN (' . $placeholders . ')
             GROUP BY card_id',
            $cardIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['card_id']] = (int) $row['cnt'];
        }

        return $map;
    }

    /**
     * Batch-loads checklist progress for a set of card IDs.
     *
     * @param array $cardIds Array of card IDs
     * @return array Map of card_id => [total => int, done => int]
     */
    public function batchLoadChecklistProgress(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT cl.card_id, COUNT(*) AS total, SUM(ci.is_checked) AS done
             FROM checklist_items ci
             JOIN checklists cl ON ci.checklist_id = cl.id
             WHERE cl.card_id IN (' . $placeholders . ')
             GROUP BY cl.card_id',
            $cardIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['card_id']] = [
                'total' => (int) $row['total'],
                'done'  => (int) ($row['done'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * Finds a card by ID.
     *
     * @param int $id Card ID
     * @return array|null Card row or null
     */
    public function findById(int $id): ?array
    {
        $card = $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM cards WHERE id = ?',
            [$id]
        );

        if ($card !== null) {
            $card['assigned_users'] = $this->getAssignedUsers($id);
        }

        return $card;
    }

    /**
     * Returns all non-archived cards for a lane, ordered by position.
     *
     * @param int  $laneId         Lane ID
     * @param bool $includeArchived Whether to include archived cards
     * @return array Array of card rows
     */
    public function findByLane(int $laneId, bool $includeArchived = false): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ' FROM cards WHERE lane_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND is_archived = 0';
        }
        $sql .= ' ORDER BY position ASC';

        return $this->db->fetchAll($sql, [$laneId]);
    }

    /**
     * Returns all non-archived cards for a board, grouped by lane.
     *
     * @param int $boardId Board ID
     * @return array Array of card rows with lane_id
     */
    public function findByBoard(int $boardId): array
    {
        return $this->db->fetchAll(
            'SELECT c.id, c.lane_id, c.title, c.description, c.due_date, c.position, c.is_archived, c.created_by, c.created_at, c.updated_at'
            . ' FROM cards c'
            . ' JOIN lanes l ON c.lane_id = l.id'
            . ' WHERE l.board_id = ? AND c.is_archived = 0'
            . ' ORDER BY c.lane_id, c.position ASC',
            [$boardId]
        );
    }

    /**
     * Creates a new card at the bottom of a lane.
     *
     * @param array $data Card data: lane_id, title, description, due_date, created_by
     * @return int The new card's ID
     */
    public function create(array $data): int
    {
        $maxPos = $this->db->fetch(
            'SELECT MAX(position) AS max_pos FROM cards WHERE lane_id = ?',
            [$data['lane_id']]
        );

        $position = ($maxPos !== null && $maxPos['max_pos'] !== null)
            ? (int) $maxPos['max_pos'] + self::POSITION_GAP
            : self::POSITION_GAP;

        $this->db->execute(
            'INSERT INTO cards (lane_id, title, description, due_date, position, created_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['lane_id'],
                $data['title'],
                $data['description'] ?? null,
                $data['due_date'] ?? null,
                $position,
                $data['created_by'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates a card's fields.
     *
     * @param int   $id   Card ID
     * @param array $data Fields to update: title, description, due_date
     */
    public function update(int $id, array $data): void
    {
        $allowedFields = ['title', 'description', 'due_date'];
        $setClauses = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "`{$field}` = ?";
                $params[] = $data[$field];
            }
        }

        if (!empty($setClauses)) {
            $setClauses[] = '`updated_at` = NOW()';
            $params[] = $id;
            $sql = 'UPDATE cards SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $this->db->execute($sql, $params);
        }
    }

    /**
     * Moves a card to a new lane and/or position.
     *
     * @param int      $id          Card ID
     * @param int      $laneId      Target lane ID
     * @param int|null $afterCardId Place after this card (null = move to top)
     */
    public function move(int $id, int $laneId, ?int $afterCardId): void
    {
        if ($afterCardId === null) {
            // Move to top of lane
            $cards = $this->findByLane($laneId);
            $firstPos = !empty($cards) ? (int) $cards[0]['position'] : self::POSITION_GAP;
            $newPos = (int) floor($firstPos / 2);

            if ($newPos < 1) {
                $this->renumberPositions($laneId);
                $cards = $this->findByLane($laneId);
                $firstPos = !empty($cards) ? (int) $cards[0]['position'] : self::POSITION_GAP;
                $newPos = (int) floor($firstPos / 2);
            }

            $this->db->execute(
                'UPDATE cards SET lane_id = ?, position = ?, updated_at = NOW() WHERE id = ?',
                [$laneId, $newPos, $id]
            );
            return;
        }

        // Find position after target card
        $cards = $this->findByLane($laneId);

        $afterPos = null;
        $nextPos = null;

        for ($i = 0; $i < count($cards); $i++) {
            if ((int) $cards[$i]['id'] === $afterCardId) {
                $afterPos = (int) $cards[$i]['position'];
                for ($j = $i + 1; $j < count($cards); $j++) {
                    if ((int) $cards[$j]['id'] !== $id) {
                        $nextPos = (int) $cards[$j]['position'];
                        break;
                    }
                }
                break;
            }
        }

        if ($afterPos === null) {
            // Target card not found in lane; place at end
            $maxPos = !empty($cards) ? (int) $cards[count($cards) - 1]['position'] : 0;
            $newPos = $maxPos + self::POSITION_GAP;
        } elseif ($nextPos === null) {
            $newPos = $afterPos + self::POSITION_GAP;
        } else {
            $newPos = (int) floor(($afterPos + $nextPos) / 2);

            if ($newPos <= $afterPos) {
                $this->renumberPositions($laneId);
                $this->move($id, $laneId, $afterCardId);
                return;
            }
        }

        $this->db->execute(
            'UPDATE cards SET lane_id = ?, position = ?, updated_at = NOW() WHERE id = ?',
            [$laneId, $newPos, $id]
        );
    }

    /**
     * Archives a card.
     *
     * @param int $id Card ID
     */
    public function archive(int $id): void
    {
        $this->db->execute(
            'UPDATE cards SET is_archived = 1, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Restores an archived card.
     *
     * @param int $id Card ID
     */
    public function restore(int $id): void
    {
        $this->db->execute(
            'UPDATE cards SET is_archived = 0, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Deletes a card by ID.
     *
     * @param int $id Card ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cards WHERE id = ?', [$id]);
    }

    /**
     * Returns the board ID that a card belongs to (via its lane).
     *
     * @param int $id Card ID
     * @return int|null Board ID or null
     */
    public function getBoardId(int $id): ?int
    {
        $row = $this->db->fetch(
            'SELECT c.id, b.id AS board_id FROM cards c JOIN lanes l ON c.lane_id = l.id JOIN boards b ON l.board_id = b.id WHERE c.id = ?',
            [$id]
        );

        return $row !== null ? (int) $row['board_id'] : null;
    }

    /**
     * Returns cards assigned to a user for the priority inbox
     * (PRIO-03/04), pre-sorted in in-board position order:
     * board position → lane position → card position.
     *
     * Board-level access filtering is the service's job (it has the
     * acting user's Auth context); this query only guarantees the rows
     * are assigned to the user, non-archived, and on an unarchived board.
     *
     * @param int   $userId          User ID
     * @param int[] $excludedCardIds Cards to skip (the user's prioritized set)
     * @return array Array of rows: card_id, title, due_date, board_id, board_title,
     *               lane_id, lane_title, lane_icon (query order = display order)
     */
    public function findInboxCandidates(int $userId, array $excludedCardIds): array
    {
        $placeholders = '';
        if ($excludedCardIds !== []) {
            $placeholders = 'AND c.id NOT IN (' . implode(',', array_fill(0, count($excludedCardIds), '?')) . ') ';
        }

        return $this->db->fetchAll(
            'SELECT c.id AS card_id, c.title, c.due_date,
                    b.id AS board_id, b.title AS board_title,
                    l.id AS lane_id, l.title AS lane_title, l.icon AS lane_icon
             FROM cards c
             JOIN card_assignments ca ON ca.card_id = c.id
             JOIN lanes l ON c.lane_id = l.id
             JOIN boards b ON l.board_id = b.id
             WHERE ca.user_id = ? AND c.is_archived = 0 AND b.is_archived = 0 '
            . $placeholders
            . 'ORDER BY b.id ASC, l.position ASC, c.position ASC',
            array_merge([$userId], array_map('intval', $excludedCardIds))
        );
    }

    /**
     * Batch-joins cards to their lane (icon + title) and board for the
     * priority list view (PRIO-07). Cards whose lane/board is missing are
     * omitted.
     *
     * @param int[] $cardIds Card IDs (any order; input order is not kept —
     *                       caller sorts by position)
     * @return array Map of card_id => row (card_id, title, due_date,
     *               board_id, board_title, lane_id, lane_title, lane_icon)
     */
    public function findWithBoardForUserList(array $cardIds): array
    {
        if ($cardIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($cardIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT c.id AS card_id, c.title, c.due_date, c.is_archived,
                    b.id AS board_id, b.title AS board_title,
                    l.id AS lane_id, l.title AS lane_title, l.icon AS lane_icon
             FROM cards c
             JOIN lanes l ON c.lane_id = l.id
             JOIN boards b ON l.board_id = b.id
             WHERE c.id IN ($in)
             ORDER BY c.id ASC",
            array_map('intval', $cardIds)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['card_id']] = $row;
        }

        return $map;
    }

    /**
     * Returns assigned users for a card.
     *
     * @param int $cardId Card ID
     * @return array Array of user data (id, name, email)
     */
    public function getAssignedUsers(int $cardId): array
    {
        return $this->db->fetchAll(
            'SELECT u.id, u.name, u.email
             FROM card_assignments ca
             JOIN users u ON ca.user_id = u.id
             WHERE ca.card_id = ?
             ORDER BY u.name ASC',
            [$cardId]
        );
    }

    /**
     * Returns comment count for a card.
     *
     * @param int $cardId Card ID
     * @return int
     */
    public function getCommentCount(int $cardId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM comments WHERE card_id = ?',
            [$cardId]
        );
        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Returns attachment count for a card.
     *
     * @param int $cardId Card ID
     * @return int
     */
    public function getAttachmentCount(int $cardId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM attachments WHERE card_id = ?',
            [$cardId]
        );
        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Returns checklist progress for a card as [total, done].
     *
     * @param int $cardId Card ID
     * @return array [total => int, done => int]
     */
    public function getChecklistProgress(int $cardId): array
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total, SUM(ci.is_checked) AS done
             FROM checklist_items ci
             JOIN checklists cl ON ci.checklist_id = cl.id
             WHERE cl.card_id = ?',
            [$cardId]
        );

        return [
            'total' => $row !== null ? (int) $row['total'] : 0,
            'done'  => $row !== null ? (int) ($row['done'] ?? 0) : 0,
        ];
    }

    /**
     * Syncs card assignments, replacing existing assignments with the given user IDs.
     *
     * Returns the list of newly assigned user IDs (those not previously assigned).
     *
     * @param int   $cardId  Card ID
     * @param array $userIds Array of user IDs to assign
     * @return array Newly assigned user IDs
     */
    public function syncAssignments(int $cardId, array $userIds): array
    {
        $existingUsers = $this->getAssignedUsers($cardId);
        $existingIds = array_map(function (array $user): int {
            return (int) $user['id'];
        }, $existingUsers);

        $newIds = array_map('intval', $userIds);

        // Remove assignments no longer in the list
        $toRemove = array_diff($existingIds, $newIds);
        if (!empty($toRemove)) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $this->db->execute(
                'DELETE FROM card_assignments WHERE card_id = ? AND user_id IN (' . $placeholders . ')',
                array_merge([$cardId], array_values($toRemove))
            );
        }

        // Add new assignments
        $toAdd = array_diff($newIds, $existingIds);
        foreach ($toAdd as $userId) {
            $this->db->execute(
                'INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)',
                [$cardId, $userId]
            );
        }

        return array_values($toAdd);
    }

    /**
     * Renumbers all card positions in a lane using POSITION_GAP increments.
     *
     * @param int $laneId Lane ID
     */
    private function renumberPositions(int $laneId): void
    {
        $this->db->beginTransaction();
        try {
            $cards = $this->db->fetchAll(
                'SELECT id FROM cards WHERE lane_id = ? ORDER BY position ASC',
                [$laneId]
            );

            $position = self::POSITION_GAP;
            foreach ($cards as $card) {
                $this->db->execute(
                    'UPDATE cards SET position = ? WHERE id = ?',
                    [$position, $card['id']]
                );
                $position += self::POSITION_GAP;
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
