<?php
/**
 * Router for the php built-in test server: `php -S localhost:PORT -t www tests/router.php`
 */
if (PHP_SAPI !== 'cli-server') {
    return false;
}

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docroot = dirname(__DIR__) . '/www';
$file   = $docroot . $uri;

// Serve existing files directly
if ($uri !== '/' && is_file($file)) {
    return false;
}

// /  → board listing equivalent (not needed for this test; serve index)
if ($uri === '/') {
    header('Location: /boards.php');
    return true;
}

// Everything else (including /v1/...) → API front controller
$_SERVER['SCRIPT_NAME'] = '/v1/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docroot . '/v1/index.php';
chdir(dirname(__DIR__) . '/www');
require $docroot . '/v1/index.php';
return true;
