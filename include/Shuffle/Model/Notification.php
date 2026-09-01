<?php
declare(strict_types=1);

namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Notification data access layer.
 *
 * Provides CRUD operations for the notifications table.
 * Notifications are created when users are assigned to cards
 * or when comments are posted on cards they are assigned to.
 */
class Notification
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
     * Finds a notification by ID.
     *
     * @param int $id Notification ID
     * @return array|null Notification row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, user_id, type, reference_id, comment_id, message, is_read, created_at
             FROM notifications WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns notifications for a user, newest first.
     *
     * @param int  $userId     User ID
     * @param bool $unreadOnly If true, return only unread notifications
     * @param int  $limit      Maximum number of results
     * @return array Array of notification rows
     */
    public function findByUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = 'SELECT id, user_id, type, reference_id, comment_id, message, is_read, created_at
                FROM notifications
                WHERE user_id = ?';

        $params = [$userId];

        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Returns the count of unread notifications for a user.
     *
     * @param int $userId User ID
     * @return int Unread count
     */
    public function countUnread(int $userId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );

        return $row !== null ? (int) $row['cnt'] : 0;
    }

    /**
     * Creates a new notification.
     *
     * @param array $data Notification data: user_id, type, reference_id, message,
     *                    [comment_id] (v1.8 NOTIF-09 — the comment id to anchor
     *                    the deep link; NULL for assignment / creator-Done events)
     * @return int The new notification's ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO notifications (user_id, type, reference_id, comment_id, message)
             VALUES (?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['type'],
                $data['reference_id'],
                isset($data['comment_id']) ? $data['comment_id'] : null,
                $data['message'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Marks a notification as read.
     *
     * @param int $id Notification ID
     */
    public function markRead(int $id): void
    {
        $this->db->execute(
            'UPDATE notifications SET is_read = 1 WHERE id = ?',
            [$id]
        );
    }

    /**
     * Marks all notifications as read for a user.
     *
     * @param int $userId User ID
     */
    public function markAllRead(int $userId): void
    {
        $this->db->execute(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    /**
     * Deletes a notification by ID.
     *
     * @param int $id Notification ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM notifications WHERE id = ?', [$id]);
    }
}
