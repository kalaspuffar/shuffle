<?php
declare(strict_types=1);

namespace Shuffle\Service;

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

    /**
     * @param Notification $notificationModel Notification data access instance
     * @param Card         $cardModel         Card data access instance (for assignment lookups)
     */
    public function __construct(Notification $notificationModel, Card $cardModel)
    {
        $this->notificationModel = $notificationModel;
        $this->cardModel = $cardModel;
    }

    /**
     * Returns notifications for a user.
     *
     * @param int  $userId     User ID
     * @param bool $unreadOnly Filter to unread only
     * @param int  $limit      Maximum results
     * @return array Array of notifications
     */
    public function getNotificationsForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        return $this->notificationModel->findByUser($userId, $unreadOnly, $limit);
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

            $message = "You were assigned to '" . mb_substr($cardTitle, 0, 100, 'UTF-8') . "'";

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
     * @param int    $cardId       Card ID
     * @param int    $authorUserId Comment author's user ID
     * @param string $cardTitle    Card title for the notification message
     * @param string $authorName   Comment author's display name
     */
    public function notifyComment(int $cardId, int $authorUserId, string $cardTitle, string $authorName): void
    {
        $assignedUsers = $this->cardModel->getAssignedUsers($cardId);

        foreach ($assignedUsers as $user) {
            $userId = (int) $user['id'];

            // Don't notify the comment author
            if ($userId === $authorUserId) {
                continue;
            }

            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $message = $authorName . " commented on '" . $truncatedTitle . "'";

            $this->notificationModel->create([
                'user_id'      => $userId,
                'type'         => 'comment',
                'reference_id' => $cardId,
                'message'      => $message,
            ]);
        }
    }
}
