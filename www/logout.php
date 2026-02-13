<?php
/**
 * Logout Handler
 *
 * Validates CSRF token, destroys the user session, and redirects to the login page.
 * Only accepts POST requests to prevent CSRF-based forced logout attacks.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// Validate CSRF token
$submittedToken = $_POST['_csrf'] ?? '';
if (!$csrf->validate($submittedToken)) {
    http_response_code(403);
    exit;
}

$auth->logout();

header('Location: /login.php');
exit;
