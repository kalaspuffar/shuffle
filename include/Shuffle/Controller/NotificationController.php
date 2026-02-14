<?php
declare(strict_types=1);

namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\NotificationService;

/**
 * Notification API controller.
 *
 * Handles listing, counting, marking read, and deleting notifications.
 * All endpoints require authentication; users can only access their own notifications.
 */
class NotificationController
{
    private Auth $auth;
    private NotificationService $notificationService;

    /**
     * @param Auth                $auth                Auth service
     * @param NotificationService $notificationService Notification business logic service
     */
    public function __construct(Auth $auth, NotificationService $notificationService)
    {
        $this->auth = $auth;
        $this->notificationService = $notificationService;
    }

    /**
     * GET /v1/notifications
     *
     * Returns notifications for the current user.
     *
     * Query parameters:
     *   unread_only — "true" to filter to unread only (default: false)
     *   limit       — Number of notifications to return (default: 50)
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $userId = (int) $currentUser['id'];

        $unreadOnly = $request->getQuery('unread_only') === 'true';
        $limit = min(max((int) ($request->getQuery('limit') ?? 50), 1), 100);

        $notifications = $this->notificationService->getNotificationsForUser($userId, $unreadOnly, $limit);
        $unreadCount = $this->notificationService->getUnreadCount($userId);

        $response->json([
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * GET /v1/notifications/count
     *
     * Lightweight endpoint for the notification badge.
     */
    public function count(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $userId = (int) $currentUser['id'];

        $unreadCount = $this->notificationService->getUnreadCount($userId);

        $response->json([
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * PUT /v1/notifications/{id}/read
     *
     * Marks a notification as read.
     */
    public function markRead(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->notificationService->markAsRead($id, (int) $currentUser['id']);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/notifications/read-all
     *
     * Marks all notifications as read for the current user.
     */
    public function markAllRead(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $this->notificationService->markAllAsRead((int) $currentUser['id']);
        $response->noContent();
    }

    /**
     * DELETE /v1/notifications/{id}
     *
     * Dismisses (deletes) a notification.
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->notificationService->deleteNotification($id, (int) $currentUser['id']);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
