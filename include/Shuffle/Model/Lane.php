<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Lane data access layer.
 *
 * Provides CRUD operations for the lanes table with gap-based position
 * management. Lanes belong to a board and are ordered by position.
 */
class Lane
{
    private Database $db;

    /** Gap between positions for new/reordered lanes */
    private const POSITION_GAP = 1000;

    private const SELECT_COLUMNS = 'id, board_id, title, position, created_at, updated_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds a lane by ID.
     *
     * @param int $id Lane ID
     * @return array|null Lane row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM lanes WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns all lanes for a board, ordered by position.
     *
     * @param int $boardId Board ID
     * @return array Array of lane rows
     */
    public function findByBoard(int $boardId): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM lanes WHERE board_id = ? ORDER BY position ASC',
            [$boardId]
        );
    }

    /**
     * Creates a new lane at the end of the board.
     *
     * Position is calculated as the maximum existing position + POSITION_GAP,
     * or POSITION_GAP if the board has no lanes.
     *
     * @param array $data Lane data: board_id, title
     * @return int The new lane's ID
     */
    public function create(array $data): int
    {
        $maxPos = $this->db->fetch(
            'SELECT MAX(position) AS max_pos FROM lanes WHERE board_id = ?',
            [$data['board_id']]
        );

        $position = ($maxPos !== null && $maxPos['max_pos'] !== null)
            ? (int) $maxPos['max_pos'] + self::POSITION_GAP
            : self::POSITION_GAP;

        $this->db->execute(
            'INSERT INTO lanes (board_id, title, position) VALUES (?, ?, ?)',
            [$data['board_id'], $data['title'], $position]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates a lane's title.
     *
     * @param int    $id    Lane ID
     * @param string $title New title
     */
    public function updateTitle(int $id, string $title): void
    {
        $this->db->execute(
            'UPDATE lanes SET title = ?, updated_at = NOW() WHERE id = ?',
            [$title, $id]
        );
    }

    /**
     * Repositions a lane after a specified lane, or to the first position.
     *
     * Uses gap-based positioning: inserts between the target and its neighbor.
     * Triggers a full renumber if the gap becomes too small (< 1).
     *
     * @param int      $id          Lane ID to move
     * @param int      $boardId     Board ID
     * @param int|null $afterLaneId Place after this lane (null = move to first)
     */
    public function reposition(int $id, int $boardId, ?int $afterLaneId): void
    {
        $lanes = $this->findByBoard($boardId);

        if ($afterLaneId === null) {
            // Move to first position
            $firstPos = !empty($lanes) ? (int) $lanes[0]['position'] : self::POSITION_GAP;
            $newPos = (int) floor($firstPos / 2);

            if ($newPos < 1) {
                $this->renumberPositions($boardId);
                $lanes = $this->findByBoard($boardId);
                $firstPos = (int) $lanes[0]['position'];
                $newPos = (int) floor($firstPos / 2);
            }

            $this->db->execute(
                'UPDATE lanes SET position = ?, updated_at = NOW() WHERE id = ?',
                [$newPos, $id]
            );
            return;
        }

        // Find the target lane and the one after it
        $afterPos = null;
        $nextPos = null;

        for ($i = 0; $i < count($lanes); $i++) {
            if ((int) $lanes[$i]['id'] === $afterLaneId) {
                $afterPos = (int) $lanes[$i]['position'];
                // Find the next lane that isn't the one being moved
                for ($j = $i + 1; $j < count($lanes); $j++) {
                    if ((int) $lanes[$j]['id'] !== $id) {
                        $nextPos = (int) $lanes[$j]['position'];
                        break;
                    }
                }
                break;
            }
        }

        if ($afterPos === null) {
            return;
        }

        if ($nextPos === null) {
            // Place at the end
            $newPos = $afterPos + self::POSITION_GAP;
        } else {
            $newPos = (int) floor(($afterPos + $nextPos) / 2);

            if ($newPos <= $afterPos) {
                $this->renumberPositions($boardId);
                // Recalculate after renumbering
                $this->reposition($id, $boardId, $afterLaneId);
                return;
            }
        }

        $this->db->execute(
            'UPDATE lanes SET position = ?, updated_at = NOW() WHERE id = ?',
            [$newPos, $id]
        );
    }

    /**
     * Deletes a lane by ID.
     *
     * @param int $id Lane ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM lanes WHERE id = ?', [$id]);
    }

    /**
     * Counts cards in a lane (non-archived).
     *
     * @param int $id Lane ID
     * @return int Card count
     */
    public function countCards(int $id): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM cards WHERE lane_id = ?',
            [$id]
        );

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Renumbers all lane positions in a board using POSITION_GAP increments.
     *
     * Used when gap-based insertion runs out of space.
     *
     * @param int $boardId Board ID
     */
    private function renumberPositions(int $boardId): void
    {
        $this->db->beginTransaction();
        try {
            $lanes = $this->db->fetchAll(
                'SELECT id FROM lanes WHERE board_id = ? ORDER BY position ASC',
                [$boardId]
            );

            $position = self::POSITION_GAP;
            foreach ($lanes as $lane) {
                $this->db->execute(
                    'UPDATE lanes SET position = ? WHERE id = ?',
                    [$position, $lane['id']]
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
