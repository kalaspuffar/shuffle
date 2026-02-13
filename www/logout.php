<?php
/**
 * Logout Handler
 *
 * Destroys the user session and redirects to the login page.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$auth->logout();

header('Location: /login.php');
exit;
