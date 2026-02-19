<?php
/**
 * Application Entry Point
 *
 * Routes the root URL to the appropriate destination:
 *  - Unauthenticated users are sent to the login page.
 *  - Authenticated users are sent to the board listing page.
 *
 * This file exists because login.php redirects to '/' on success,
 * and Apache/Nginx may serve a directory listing without it.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

if ($auth->currentUser() !== null) {
    header('Location: /boards.php');
} else {
    header('Location: /login.php');
}
exit;
