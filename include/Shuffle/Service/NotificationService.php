<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Lang;
use Shuffle\Model\Card;
use Shuffle\Model\Notification;

/**
 * Notification business logic service.
 *
 * Handles notification CRUD and triggers for assignment and comment events.
 * Notifications are created for users when they are assigned to a card
 * or when someone comments on a card they are assigned to.
 */
class NotificationService
{
    private Notification $notificationModel;
    private Card $cardModel;
    private Lang $lang;

    /**
     * @param Notification $notificationModel Notification data access instance
     * @param Card         $cardModel         Card data access instance (for assignment lookups)
     * @param Lang         $lang              Internationalization service
     */
    public function __construct(Notification $notificationModel, Card $cardModel, Lang $lang)
    {
        $this->notificationModel = $notificationModel;
        $this->cardModel = $cardModel;
        $this->lang = $lang;
    }

    /**
     * Returns notifications for a user, enriched with the card's board_id
     * (for the NOTIF-09 deep link /board.php?id={boardId}&card={cardId}).
     *
     * @param int  $userId     User ID
     * @param bool $unreadOnly Filter to unread only
     * @param int  $limit      Maximum results
     * @return array Array of notifications, each row with an added `board_id`
     *               key (int or null if the card / lane / board was removed).
     */
    public function getNotificationsForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $rows = $this->notificationModel->findByUser($userId, $unreadOnly, $limit);
        if ($rows === []) {
            return $rows;
        }

        $cardIds = [];
        foreach ($rows as $row) {
            $cardIds[(int) $row['reference_id']] = true;
        }

        $boardByCard = $this->cardModel->boardsForCards(array_keys($cardIds));

        foreach ($rows as &$row) {
            $cardId = (int) $row['reference_id'];
            $row['board_id'] = isset($boardByCard[$cardId]) ? (int) $boardByCard[$cardId] : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns the unread notification count for a user.
     *
     * @param int $userId User ID
     * @return int Unread count
     */
    public function getUnreadCount(int $userId): int
    {
        return $this->notificationModel->countUnread($userId);
    }

    /**
     * Marks a single notification as read.
     *
     * Verifies ownership before marking.
     *
     * @param int $notificationId Notification ID
     * @param int $userId         Current user ID (ownership check)
     * @throws \RuntimeException If notification not found or not owned by user
     */
    public function markAsRead(int $notificationId, int $userId): void
    {
        $notification = $this->notificationModel->findById($notificationId);
        if ($notification === null || (int) $notification['user_id'] !== $userId) {
            throw new \RuntimeException('Notification not found');
        }

        $this->notificationModel->markRead($notificationId);
    }

    /**
     * Marks all notifications as read for a user.
     *
     * @param int $userId User ID
     */
    public function markAllAsRead(int $userId): void
    {
        $this->notificationModel->markAllRead($userId);
    }

    /**
     * Deletes a notification.
     *
     * Verifies ownership before deleting.
     *
     * @param int $notificationId Notification ID
     * @param int $userId         Current user ID (ownership check)
     * @throws \RuntimeException If notification not found or not owned by user
     */
    public function deleteNotification(int $notificationId, int $userId): void
    {
        $notification = $this->notificationModel->findById($notificationId);
        if ($notification === null || (int) $notification['user_id'] !== $userId) {
            throw new \RuntimeException('Notification not found');
        }

        $this->notificationModel->delete($notificationId);
    }

    /**
     * Creates assignment notifications for newly assigned users.
     *
     * Called when users are assigned to a card. Notifies each assigned user
     * except the user who performed the action (the assigner).
     *
     * @param int    $cardId        Card ID
     * @param array  $assignedUserIds Array of newly assigned user IDs
     * @param int    $assignerUserId  User ID of the person making the assignment
     * @param string $cardTitle      Card title for the notification message
     */
    public function notifyAssignment(int $cardId, array $assignedUserIds, int $assignerUserId, string $cardTitle): void
    {
        foreach ($assignedUserIds as $userId) {
            $userId = (int) $userId;

            // Don't notify the user who performed the assignment
            if ($userId === $assignerUserId) {
                continue;
            }

            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $message = $this->lang->get('notification.assigned_to', [$truncatedTitle]);

            $this->notificationModel->create([
                'user_id'      => $userId,
                'type'         => 'assignment',
                'reference_id' => $cardId,
                'message'      => $message,
            ]);
        }
    }

    /**
     * Creates comment notifications for all users assigned to a card.
     *
     * Called when a comment is posted. Notifies all assigned users
     * except the comment author.
     *
     * v1.8 (NOTIF-07): also creates a creator-scope notification for the
     * card's author — unless the author is the creator, or the creator
     * is already in the assignee set (in which case they get ONE
     * 'comment'-type row, not a duplicate). All rows produced by this
     * call share the new comment's id (`comment_id`) so the bell panel
     * can deep-link to it (NOTIF-09).
     *
     * @param int     $cardId        Card ID
     * @param int     $authorUserId  Comment author's user ID
     * @param string  $cardTitle     Card title for the notification message
     * @param string  $authorName    Comment author's display name
     * @param int|null $commentId    New comment's id (NOTIF-09 anchor)
     * @param array|null $card       Full card row (provides created_by for NOTIF-07);
     *                               read by reference only — no extra query
     */
    public function notifyComment(int $cardId, int $authorUserId, string $cardTitle, string $authorName, ?int $commentId = null, ?array $card = null): void
    {
        $assignedUsers = $this->cardModel->getAssignedUsers($cardId);
        $assignedIds = [];

        foreach ($assignedUsers as $user) {
            $userId = (int) $user['id'];
            $assignedIds[] = $userId;

            // Don't notify the comment author
            if ($userId === $authorUserId) {
                continue;
            }

            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $message = $this->lang->get('notification.commented_on', [$authorName, $truncatedTitle]);

            $this->notificationModel->create([
                'user_id'      => $userId,
                'type'         => 'comment',
                'reference_id' => $cardId,
                'comment_id'   => $commentId,
                'message'      => $message,
            ]);
        }

        // NOTIF-07: the card's creator also gets notified — but only if they
        // aren't the commenter AND aren't already on the assignee list (to
        // avoid double-notifying).
        $creatorId = ($card !== null && isset($card['created_by'])) ? (int) $card['created_by'] : null;
        if ($creatorId !== null
            && $creatorId !== $authorUserId
            && in_array($creatorId, $assignedIds, true) === false) {
            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $this->notificationModel->create([
                'user_id'      => $creatorId,
                'type'         => 'creator',
                'reference_id' => $cardId,
                'comment_id'   => $commentId,
                'message'      => $this->lang->get('notification.creator_commented_on', [$authorName, $truncatedTitle]),
            ]);
        }
    }

    /**
     * Creates a creator-scope notification that a card moved into a Done
     * lane (NOTIF-08 — "your card shipped").
     *
     * No-op when the actor IS the creator or the creator is unknown.
     * Uses the same `\bdone\b` (case-insensitive, word-bounded) matcher
     * as the priority digest (PRIO-13) — "Done-ness" never matches,
     * "Done — v2" does.
     *
     * @param int    $cardId        Card ID
     * @param int    $actorUserId   Acting user's ID (mover)
     * @param string $actorName     Acting user's display name
     * @param string $toLaneTitle   Lane the card landed in (Done-lane)
     * @param array|null $card      Full card row (for created_by + title)
     */
    public function notifyCreatorDoneMove(int $cardId, int $actorUserId, string $actorName, string $toLaneTitle, ?array $card = null): void
    {
        if ($card === null) {
            return;
        }

        $creatorId = isset($card['created_by']) ? (int) $card['created_by'] : null;
        if ($creatorId === null || $creatorId === $actorUserId) {
            return;
        }

        $truncatedTitle = mb_substr((string) ($card['title'] ?? ''), 0, 100, 'UTF-8');
        $this->notificationModel->create([
            'user_id'      => $creatorId,
            'type'         => 'creator',
            'reference_id' => $cardId,
            'comment_id'   => null,
            'message'      => $this->lang->get('notification.creator_done', [$actorName, $truncatedTitle, $toLaneTitle]),
        ]);
    }
}
