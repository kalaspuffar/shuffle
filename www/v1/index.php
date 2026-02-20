<?php
/**
 * Shuffle REST API v1 Front Controller
 *
 * All API requests are routed through this file via Apache rewrite rules.
 * Initializes the application, registers routes, applies CSRF validation,
 * sets security headers, and dispatches the request.
 */

// Bootstrap the application
require_once dirname(__DIR__, 2) . '/include/bootstrap.php';

// Security headers for API responses
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

// Initialize request/response
$request  = new Shuffle\Core\Request();
$response = new Shuffle\Core\Response();

// CSRF validation for state-changing methods (except login and activate)
$method = $request->getMethod();
$path = $request->getPath();

$csrfExemptPaths = ['/auth/login', '/users/activate', '/setup/test-smtp', '/setup/test-s3'];
$requiresCsrf = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);

if ($requiresCsrf) {
    $isExempt = in_array($path, $csrfExemptPaths, true);

    if (!$isExempt) {
        $csrfToken = $request->getHeader('X-CSRF-Token', '');
        if (!$csrf->validate($csrfToken)) {
            $response->error('CSRF token invalid', 403);
            exit;
        }
    }
}

// Instantiate controllers
$authController = new Shuffle\Controller\AuthController($auth, $csrf);

$userModel   = new Shuffle\Model\User($db);
$userService = new Shuffle\Service\UserService($userModel);

// Resolve SMTP config: database settings take precedence over config.php
$smtpRows = $db->fetchAll(
    "SELECT `key`, `value` FROM `settings` WHERE `key` LIKE 'smtp.%'"
);
if (!empty($smtpRows)) {
    $smtpConfig = [];
    foreach ($smtpRows as $row) {
        // Strip the 'smtp.' prefix to produce array keys like 'host', 'port', etc.
        $smtpConfig[substr($row['key'], 5)] = $row['value'];
    }
} else {
    $smtpConfig = $config['smtp'] ?? [];
}

$mailer = new Shuffle\Core\Mailer($smtpConfig);
$appUrl = $config['app']['url'] ?? 'http://localhost';
$userController = new Shuffle\Controller\UserController($auth, $userService, $mailer, $appUrl);

$orgModel      = new Shuffle\Model\Organization($db);
$orgService    = new Shuffle\Service\OrganizationService($orgModel);
$orgController = new Shuffle\Controller\OrganizationController($auth, $orgService);

$boardModel      = new Shuffle\Model\Board($db);
$laneModel       = new Shuffle\Model\Lane($db);
$cardModel       = new Shuffle\Model\Card($db);

$boardService    = new Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);
$boardController = new Shuffle\Controller\BoardController($auth, $boardService);

$laneService    = new Shuffle\Service\LaneService($laneModel, $boardModel);
$laneController = new Shuffle\Controller\LaneController($auth, $laneService);

$cardService    = new Shuffle\Service\CardService($cardModel, $boardModel);
$cardController = new Shuffle\Controller\CardController($auth, $cardService);

$commentModel      = new Shuffle\Model\Comment($db);
$commentService    = new Shuffle\Service\CommentService($commentModel, $cardModel, $boardModel);
$commentController = new Shuffle\Controller\CommentController($auth, $commentService, $cardService);

$checklistModel      = new Shuffle\Model\Checklist($db);
$checklistItemModel  = new Shuffle\Model\ChecklistItem($db);
$checklistService    = new Shuffle\Service\ChecklistService($checklistModel, $checklistItemModel, $cardModel, $boardModel);
$checklistController = new Shuffle\Controller\ChecklistController($auth, $checklistService, $cardService);

// Notification system
$notificationModel      = new Shuffle\Model\Notification($db);
$notificationService    = new Shuffle\Service\NotificationService($notificationModel, $cardModel, $lang);
$notificationController = new Shuffle\Controller\NotificationController($auth, $notificationService);

// Wire NotificationService into CommentService for comment notifications
$commentService->setNotificationService($notificationService);

// Wire NotificationService into CardService for assignment notifications
$cardService->setNotificationService($notificationService);

// Attachment system
$attachmentModel   = new Shuffle\Model\Attachment($db);
$s3Client          = new Shuffle\Core\S3Client($config['s3'] ?? []);
$attachmentService = new Shuffle\Service\AttachmentService(
    $attachmentModel,
    $cardModel,
    $boardModel,
    $s3Client,
    $config['upload']['chunk_size'] ?? 5242880
);
$attachmentController = new Shuffle\Controller\AttachmentController($auth, $attachmentService, $cardService);

// Search system
$searchService    = new Shuffle\Service\SearchService($db);
$searchController = new Shuffle\Controller\SearchController($auth, $searchService);

// Setup wizard (unauthenticated; guarded by admin-existence check inside controller)
$setupController = new Shuffle\Controller\SetupController($db);

// Wire services into CardService for full card loading
$cardService->setCommentService($commentService);
$cardService->setChecklistService($checklistService);
$cardService->setAttachmentService($attachmentService);

// Register routes
$router = new Shuffle\Core\Router();

// Auth routes
$router->post('/auth/login', [$authController, 'login']);
$router->post('/auth/logout', [$authController, 'logout']);
$router->get('/auth/session', [$authController, 'session']);

// User routes
$router->get('/users', [$userController, 'index']);
$router->get('/users/{id}', [$userController, 'show']);
$router->post('/users/invite', [$userController, 'invite']);
$router->post('/users/activate', [$userController, 'activate']);
$router->put('/users/{id}', [$userController, 'update']);
$router->delete('/users/{id}', [$userController, 'delete']);

// Organization routes
$router->get('/organizations', [$orgController, 'index']);
$router->get('/organizations/{id}', [$orgController, 'show']);
$router->post('/organizations', [$orgController, 'create']);
$router->put('/organizations/{id}', [$orgController, 'update']);
$router->delete('/organizations/{id}', [$orgController, 'delete']);
$router->get('/organizations/{id}/members', [$orgController, 'members']);
$router->post('/organizations/{id}/members', [$orgController, 'addMember']);
$router->delete('/organizations/{id}/members/{userId}', [$orgController, 'removeMember']);

// Board routes
$router->get('/boards', [$boardController, 'index']);
$router->get('/boards/{id}', [$boardController, 'show']);
$router->post('/boards', [$boardController, 'create']);
$router->put('/boards/{id}', [$boardController, 'update']);
$router->delete('/boards/{id}', [$boardController, 'delete']);
$router->post('/boards/{id}/archive', [$boardController, 'archive']);
$router->post('/boards/{id}/restore', [$boardController, 'restore']);
$router->get('/boards/{id}/version', [$boardController, 'version']);

// Lane routes
$router->get('/boards/{boardId}/lanes', [$laneController, 'index']);
$router->post('/boards/{boardId}/lanes', [$laneController, 'create']);
$router->put('/lanes/{id}', [$laneController, 'update']);
$router->put('/lanes/{id}/position', [$laneController, 'reposition']);
$router->delete('/lanes/{id}', [$laneController, 'delete']);

// Card routes
$router->get('/cards/{id}', [$cardController, 'show']);
$router->post('/boards/{boardId}/lanes/{laneId}/cards', [$cardController, 'create']);
$router->put('/cards/{id}', [$cardController, 'update']);
$router->put('/cards/{id}/move', [$cardController, 'move']);
$router->post('/cards/{id}/archive', [$cardController, 'archive']);
$router->post('/cards/{id}/restore', [$cardController, 'restore']);
$router->delete('/cards/{id}', [$cardController, 'delete']);

// Comment routes
$router->post('/cards/{cardId}/comments', [$commentController, 'create']);
$router->put('/comments/{id}', [$commentController, 'update']);
$router->delete('/comments/{id}', [$commentController, 'delete']);

// Checklist routes
$router->post('/cards/{cardId}/checklists', [$checklistController, 'create']);
$router->put('/checklists/{id}', [$checklistController, 'update']);
$router->delete('/checklists/{id}', [$checklistController, 'delete']);
$router->post('/checklists/{checklistId}/items', [$checklistController, 'createItem']);
$router->put('/checklist-items/{id}', [$checklistController, 'updateItem']);
$router->put('/checklist-items/{id}/position', [$checklistController, 'repositionItem']);
$router->delete('/checklist-items/{id}', [$checklistController, 'deleteItem']);

// Notification routes
$router->get('/notifications', [$notificationController, 'index']);
$router->get('/notifications/count', [$notificationController, 'count']);
$router->put('/notifications/{id}/read', [$notificationController, 'markRead']);
$router->post('/notifications/read-all', [$notificationController, 'markAllRead']);
$router->delete('/notifications/{id}', [$notificationController, 'delete']);

// Search routes
$router->get('/search', [$searchController, 'search']);

// Setup routes (unauthenticated, guarded internally)
$router->post('/setup/test-smtp', [$setupController, 'testSmtp']);
$router->post('/setup/test-s3',   [$setupController, 'testS3']);

// Attachment routes
$router->post('/cards/{cardId}/attachments', [$attachmentController, 'create']);
$router->get('/cards/{cardId}/attachments', [$attachmentController, 'index']);
$router->get('/attachments/{id}/download', [$attachmentController, 'download']);
$router->delete('/attachments/{id}', [$attachmentController, 'delete']);

// Dispatch
$router->dispatch($request, $response);
