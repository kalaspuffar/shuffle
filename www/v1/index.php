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

$csrfExemptPaths = ['/auth/login', '/users/activate'];
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
$mailer      = new Shuffle\Core\Mailer($config['smtp'] ?? []);
$appUrl      = $config['app']['url'] ?? 'http://localhost';
$userController = new Shuffle\Controller\UserController($auth, $userService, $mailer, $appUrl);

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

// Dispatch
$router->dispatch($request, $response);
