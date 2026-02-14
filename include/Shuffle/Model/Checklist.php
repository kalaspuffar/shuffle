<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Checklist data access layer.
 *
 * Provides CRUD operations for the checklists table with gap-based
 * position management. Checklists belong to a card.
 */
class Checklist
{
    private Database $db;

    /** Gap between positions for new/reordered checklists */
    private const POSITION_GAP = 1000;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds a checklist by ID.
     *
     * @param int $id Checklist ID
     * @return array|null Checklist row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, card_id, title, position, created_at, updated_at
             FROM checklists WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns all checklists for a card, ordered by position.
     *
     * @param int $cardId Card ID
     * @return array Array of checklist rows
     */
    public function findByCard(int $cardId): array
    {
        return $this->db->fetchAll(
            'SELECT id, card_id, title, position, created_at, updated_at
             FROM checklists WHERE card_id = ?
             ORDER BY position ASC',
            [$cardId]
        );
    }

    /**
     * Creates a new checklist at the bottom of a card's checklist list.
     *
     * @param array $data Checklist data: card_id, title
     * @return int The new checklist's ID
     */
    public function create(array $data): int
    {
        $maxPos = $this->db->fetch(
            'SELECT MAX(position) AS max_pos FROM checklists WHERE card_id = ?',
            [$data['card_id']]
        );

        $position = ($maxPos !== null && $maxPos['max_pos'] !== null)
            ? (int) $maxPos['max_pos'] + self::POSITION_GAP
            : self::POSITION_GAP;

        $this->db->execute(
            'INSERT INTO checklists (card_id, title, position) VALUES (?, ?, ?)',
            [
                $data['card_id'],
                $data['title'],
                $position,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates a checklist's title.
     *
     * @param int    $id    Checklist ID
     * @param string $title New title
     */
    public function update(int $id, string $title): void
    {
        $this->db->execute(
            'UPDATE checklists SET title = ?, updated_at = NOW() WHERE id = ?',
            [$title, $id]
        );
    }

    /**
     * Deletes a checklist by ID (cascade deletes items).
     *
     * @param int $id Checklist ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM checklists WHERE id = ?', [$id]);
    }

    /**
     * Returns the card ID that a checklist belongs to.
     *
     * @param int $checklistId Checklist ID
     * @return int|null Card ID or null
     */
    public function getCardId(int $checklistId): ?int
    {
        $row = $this->db->fetch(
            'SELECT card_id FROM checklists WHERE id = ?',
            [$checklistId]
        );

        return $row !== null ? (int) $row['card_id'] : null;
    }
}
