<?php
declare(strict_types=1);

namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * User priority-list membership data access layer.
 *
 * Thin DAO for the `user_prio` table (PRIO-01..11). It stores only the
 * (user, card) membership plus the user's custom ordering. All other card
 * data is read live by the service — nothing here is a copy of a card.
 *
 * Uses the same gap-based position scheme as Lane and Card: new entries get
 * max + POSITION_GAP, an insert-after another uses floor((prev+next)/2), and
 * a collapsed gap triggers a full renumber of the user's container.
 */
class UserPrio
{
    private Database $db;

    /** Gap between positions when renumbering a user's list. */
    private const POSITION_GAP = 1000;

    private const SELECT_COLUMNS = 'id, user_id, card_id, position, added_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Returns a user's prioritized entries, ordered by position.
     *
     * @param int $userId User ID
     * @return array Array of user_prio rows (ordered by position ASC)
     */
    public function findByUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . " FROM user_prio WHERE user_id = ? ORDER BY position ASC",
            [$userId]
        );
    }

    /**
     * Finds a single (user, card) membership.
     *
     * @param int $userId User ID
     * @param int $cardId Card ID
     * @return array|null user_prio row or null
     */
    public function findByCardAndUser(int $userId, int $cardId): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . " FROM user_prio WHERE user_id = ? AND card_id = ?",
            [$userId, $cardId]
        );
    }

    /**
     * Returns the maximum position for a user, or 0 if the list is empty.
     *
     * @param int $userId User ID
     * @return int
     */
    public function maxPosition(int $userId): int
    {
        $row = $this->db->fetch(
            'SELECT COALESCE(MAX(position), 0) AS max_pos FROM user_prio WHERE user_id = ?',
            [$userId]
        );

        return (int) ($row['max_pos'] ?? 0);
    }

    /**
     * Inserts a new membership at the given position.
     *
     * @param int $userId   User ID
     * @param int $cardId   Card ID
     * @param int $position Gap-based position
     * @return int The new row's ID
     */
    public function add(int $userId, int $cardId, int $position): int
    {
        $this->db->execute(
            'INSERT INTO user_prio (user_id, card_id, position) VALUES (?, ?, ?)',
            [$userId, $cardId, $position]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Removes a (user, card) membership. No-op if absent.
     *
     * @param int $userId User ID
     * @param int $cardId Card ID
     */
    public function remove(int $userId, int $cardId): void
    {
        $this->db->execute(
            'DELETE FROM user_prio WHERE user_id = ? AND card_id = ?',
            [$userId, $cardId]
        );
    }

    /**
     * Moves a user's entry to after another entry (or to the top when
     * $afterCardId is null), using the shared gap-based scheme
     * (SPECIFICATION.md §4.2). A collapsed gap triggers a full renumber
     * of the user's container.
     *
     * Handles the adjacency case (SPECIFICATION.md §4.2, "collapsed gap"):
     * the moving entry is filtered OUT of the position list before finding
     * the target's prev/next, so we never compute a midpoint that collides
     * with the moving entry itself.
     *
     * @param int      $userId      User ID
     * @param int      $cardId      Card ID to move
     * @param int|null $afterCardId Insert after this card (null = to top)
     * @return int The entry's new position
     * @throws \RuntimeException If the target entry is not in this user's list
     */
    public function reposition(int $userId, int $cardId, ?int $afterCardId): int
    {
        // Load all entries and strip the moving card, so its own current
        // position is never part of the gap computation (adjacency case).
        $entries = $this->findByUser($userId);

        $survivers = array_values(array_filter(
            $entries,
            static fn (array $e) => (int) $e['card_id'] !== $cardId
        ));

        if ($afterCardId === null) {
            // Move to first position: new pos = floor(first-surviving-pos / 2).
            $firstPos = $survivers ? (int) $survivers[0]['position']
                                   : self::POSITION_GAP;
            $newPos = (int) floor($firstPos / 2);

            if ($newPos < 1 || $newPos >= $firstPos) {
                $this->renumberPositions($userId);
                $entries  = $this->findByUser($userId);
                $survivers = array_values(array_filter(
                    $entries,
                    static fn (array $e) => (int) $e['card_id'] !== $cardId
                ));
                $firstPos = $survivers ? (int) $survivers[0]['position'] : self::POSITION_GAP;
                $newPos   = (int) floor($firstPos / 2);
            }

            $this->db->execute(
                'UPDATE user_prio SET position = ? WHERE user_id = ? AND card_id = ?',
                [$newPos, $userId, $cardId]
            );

            return $newPos;
        }

        // Find the target in the survivors (its index defines next-survivor).
        $idx = $this->indexOfCard($survivers, $afterCardId);

        if ($idx === null) {
            throw new \RuntimeException('Target entry not found in this list');
        }

        $afterPos = (int) $survivers[$idx]['position'];

        // NEXT survivor after the target (the one being moved is already
        // filtered out of $survivers). None ⇒ move to end.
        $nextPos = null;
        for ($j = $idx + 1; $j < count($survivers); $j++) {
            $nextPos = (int) $survivers[$j]['position'];
            break;
        }

        if ($nextPos === null) {
            // Place at the end
            $newPos = $afterPos + self::POSITION_GAP;
        } else {
            $newPos = (int) floor(($afterPos + $nextPos) / 2);

            if ($newPos <= $afterPos) {
                $this->renumberPositions($userId);
                return $this->reposition($userId, $cardId, $afterCardId);
            }
        }

        $this->db->execute(
            'UPDATE user_prio SET position = ? WHERE user_id = ? AND card_id = ?',
            [$newPos, $userId, $cardId]
        );

        return $newPos;
    }

    private function indexOfCard(array $entries, int $cardId): ?int
    {
        foreach ($entries as $i => $entry) {
            if ((int) $entry['card_id'] === $cardId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Renumbers all of a user's entries with POSITION_GAP spacing.
     *
     * @param int $userId User ID
     */
    private function renumberPositions(int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $entries = $this->findByUser($userId);
            $position = self::POSITION_GAP;
            foreach ($entries as $entry) {
                $this->db->execute(
                    'UPDATE user_prio SET position = ? WHERE user_id = ? AND id = ?',
                    [$position, $userId, $entry['id']]
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
