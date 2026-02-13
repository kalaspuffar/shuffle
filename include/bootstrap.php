<?php
/**
 * Shuffle Bootstrap
 *
 * Entry point included by every web page and the API front-controller.
 * Initializes autoloader, configuration, database, session, i18n, and auth.
 */

// 1. Define project root directory
define('ROOT_DIR', dirname(__DIR__));

// 2. Require and register the autoloader
require_once ROOT_DIR . '/include/Shuffle/Core/Autoloader.php';

$autoloader = new Shuffle\Core\Autoloader(ROOT_DIR . '/include/Shuffle');
$autoloader->register();

// 3. Load configuration
$configFile = ROOT_DIR . '/etc/config.php';
if (!file_exists($configFile)) {
    // Hardcoded string is intentional: Lang is not available until config is loaded
    die('Configuration file not found. Copy etc/config.example.php to etc/config.php and update your settings.');
}
$config = require $configFile;

// Set timezone from configuration
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// 4. Initialize Database singleton
$db = new Shuffle\Core\Database($config['db']);

// 5. Initialize and start custom session handler
$session = new Shuffle\Core\Session($db, $config['session']);
$session->start();

// 6. Initialize i18n
$lang = new Shuffle\Core\Lang(
    $config['app']['locale'] ?? 'en',
    ROOT_DIR . '/include/lang'
);

// 7. Initialize Auth instance (stub — Phase 2 delivers full Auth)
// $auth = new Shuffle\Core\Auth($db, $session);
$auth = null;
