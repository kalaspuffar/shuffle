<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Checklist item data access layer.
 *
 * Provides CRUD operations for the checklist_items table with gap-based
 * position management. Items belong to a checklist.
 */
class ChecklistItem
{
    private Database $db;

    /** Gap between positions for new/reordered items */
    private const POSITION_GAP = 1000;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds a checklist item by ID.
     *
     * @param int $id Item ID
     * @return array|null Item row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ci.id, ci.checklist_id, ci.title, ci.is_checked,
                    ci.assigned_user_id, ci.position, ci.created_at, ci.updated_at,
                    u.name AS assigned_user_name
             FROM checklist_items ci
             LEFT JOIN users u ON ci.assigned_user_id = u.id
             WHERE ci.id = ?',
            [$id]
        );
    }

    /**
     * Returns all items for a checklist, ordered by position.
     *
     * @param int $checklistId Checklist ID
     * @return array Array of item rows with assigned_user_name
     */
    public function findByChecklist(int $checklistId): array
    {
        return $this->db->fetchAll(
            'SELECT ci.id, ci.checklist_id, ci.title, ci.is_checked,
                    ci.assigned_user_id, ci.position, ci.created_at, ci.updated_at,
                    u.name AS assigned_user_name
             FROM checklist_items ci
             LEFT JOIN users u ON ci.assigned_user_id = u.id
             WHERE ci.checklist_id = ?
             ORDER BY ci.position ASC',
            [$checklistId]
        );
    }

    /**
     * Creates a new item at the bottom of a checklist.
     *
     * @param array $data Item data: checklist_id, title, assigned_user_id (optional)
     * @return int The new item's ID
     */
    public function create(array $data): int
    {
        $maxPos = $this->db->fetch(
            'SELECT MAX(position) AS max_pos FROM checklist_items WHERE checklist_id = ?',
            [$data['checklist_id']]
        );

        $position = ($maxPos !== null && $maxPos['max_pos'] !== null)
            ? (int) $maxPos['max_pos'] + self::POSITION_GAP
            : self::POSITION_GAP;

        $this->db->execute(
            'INSERT INTO checklist_items (checklist_id, title, assigned_user_id, position)
             VALUES (?, ?, ?, ?)',
            [
                $data['checklist_id'],
                $data['title'],
                $data['assigned_user_id'] ?? null,
                $position,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates an item's fields.
     *
     * @param int   $id   Item ID
     * @param array $data Fields to update: title, is_checked, assigned_user_id
     */
    public function update(int $id, array $data): void
    {
        $allowedFields = ['title', 'is_checked', 'assigned_user_id'];
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
            $sql = 'UPDATE checklist_items SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $this->db->execute($sql, $params);
        }
    }

    /**
     * Repositions an item after the given item (or to top if null).
     *
     * @param int      $id          Item ID
     * @param int|null $afterItemId Place after this item (null = move to top)
     */
    public function reposition(int $id, ?int $afterItemId): void
    {
        $item = $this->findById($id);
        if ($item === null) {
            return;
        }

        $checklistId = (int) $item['checklist_id'];
        $items = $this->findByChecklist($checklistId);

        if ($afterItemId === null) {
            // Move to top
            $firstPos = !empty($items) ? (int) $items[0]['position'] : self::POSITION_GAP;
            $newPos = (int) floor($firstPos / 2);

            if ($newPos < 1) {
                $this->renumberPositions($checklistId);
                $items = $this->findByChecklist($checklistId);
                $firstPos = !empty($items) ? (int) $items[0]['position'] : self::POSITION_GAP;
                $newPos = (int) floor($firstPos / 2);
            }

            $this->db->execute(
                'UPDATE checklist_items SET position = ?, updated_at = NOW() WHERE id = ?',
                [$newPos, $id]
            );
            return;
        }

        // Find position after target item
        $afterPos = null;
        $nextPos = null;

        for ($i = 0; $i < count($items); $i++) {
            if ((int) $items[$i]['id'] === $afterItemId) {
                $afterPos = (int) $items[$i]['position'];
                for ($j = $i + 1; $j < count($items); $j++) {
                    if ((int) $items[$j]['id'] !== $id) {
                        $nextPos = (int) $items[$j]['position'];
                        break;
                    }
                }
                break;
            }
        }

        if ($afterPos === null) {
            // Target not found; place at end
            $maxPos = !empty($items) ? (int) $items[count($items) - 1]['position'] : 0;
            $newPos = $maxPos + self::POSITION_GAP;
        } elseif ($nextPos === null) {
            $newPos = $afterPos + self::POSITION_GAP;
        } else {
            $newPos = (int) floor(($afterPos + $nextPos) / 2);

            if ($newPos <= $afterPos) {
                $this->renumberPositions($checklistId);
                $this->reposition($id, $afterItemId);
                return;
            }
        }

        $this->db->execute(
            'UPDATE checklist_items SET position = ?, updated_at = NOW() WHERE id = ?',
            [$newPos, $id]
        );
    }

    /**
     * Deletes a checklist item by ID.
     *
     * @param int $id Item ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM checklist_items WHERE id = ?', [$id]);
    }

    /**
     * Returns the checklist ID that an item belongs to.
     *
     * @param int $itemId Item ID
     * @return int|null Checklist ID or null
     */
    public function getChecklistId(int $itemId): ?int
    {
        $row = $this->db->fetch(
            'SELECT checklist_id FROM checklist_items WHERE id = ?',
            [$itemId]
        );

        return $row !== null ? (int) $row['checklist_id'] : null;
    }

    /**
     * Renumbers all item positions in a checklist using POSITION_GAP increments.
     *
     * @param int $checklistId Checklist ID
     */
    private function renumberPositions(int $checklistId): void
    {
        $this->db->beginTransaction();
        try {
            $items = $this->db->fetchAll(
                'SELECT id FROM checklist_items WHERE checklist_id = ? ORDER BY position ASC',
                [$checklistId]
            );

            $position = self::POSITION_GAP;
            foreach ($items as $item) {
                $this->db->execute(
                    'UPDATE checklist_items SET position = ? WHERE id = ?',
                    [$position, $item['id']]
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
