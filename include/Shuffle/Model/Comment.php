<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Comment data access layer.
 *
 * Provides CRUD operations for the comments table.
 * Comments belong to a card and are authored by a user.
 */
class Comment
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
     * Finds a comment by ID with the author's name.
     *
     * @param int $id Comment ID
     * @return array|null Comment row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT c.id, c.card_id, c.user_id, c.body, c.created_at, c.updated_at,
                    u.name AS user_name
             FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.id = ?',
            [$id]
        );
    }

    /**
     * Returns all comments for a card, ordered chronologically.
     *
     * @param int $cardId Card ID
     * @return array Array of comment rows with user_name
     */
    public function findByCard(int $cardId): array
    {
        return $this->db->fetchAll(
            'SELECT c.id, c.card_id, c.user_id, c.body, c.created_at, c.updated_at,
                    u.name AS user_name
             FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.card_id = ?
             ORDER BY c.created_at ASC',
            [$cardId]
        );
    }

    /**
     * Creates a new comment.
     *
     * @param array $data Comment data: card_id, user_id, body
     * @return int The new comment's ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO comments (card_id, user_id, body) VALUES (?, ?, ?)',
            [
                $data['card_id'],
                $data['user_id'],
                $data['body'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates a comment's body.
     *
     * @param int    $id   Comment ID
     * @param string $body New body text
     */
    public function update(int $id, string $body): void
    {
        $this->db->execute(
            'UPDATE comments SET body = ?, updated_at = NOW() WHERE id = ?',
            [$body, $id]
        );
    }

    /**
     * Deletes a comment by ID.
     *
     * @param int $id Comment ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM comments WHERE id = ?', [$id]);
    }

    /**
     * Returns the card ID that a comment belongs to.
     *
     * @param int $commentId Comment ID
     * @return int|null Card ID or null
     */
    public function getCardId(int $commentId): ?int
    {
        $row = $this->db->fetch(
            'SELECT card_id FROM comments WHERE id = ?',
            [$commentId]
        );

        return $row !== null ? (int) $row['card_id'] : null;
    }
}
