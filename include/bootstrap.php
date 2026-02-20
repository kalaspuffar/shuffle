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

// 4a. Merge settings table values into $config, overriding any config.php defaults.
//     Dotted keys are expanded into nested arrays (e.g. 'smtp.host' → $config['smtp']['host']).
//     Type coercions are applied for fields that must not remain strings.
try {
    $dbSettings = $db->fetchAll("SELECT `key`, `value` FROM `settings`");
    foreach ($dbSettings as $row) {
        $parts = explode('.', $row['key'], 2);
        if (count($parts) === 2) {
            [$section, $name] = $parts;
            $value = $row['value'];

            // Apply type coercions for known numeric/boolean fields
            if ($row['key'] === 'smtp.port' || $row['key'] === 'polling.interval' || $row['key'] === 'upload.chunk_size') {
                $value = (int)$value;
            } elseif ($row['key'] === 's3.path_style') {
                // Stored as '1' (true) or '0' (false) in the database
                $value = ($value === '1');
            }

            $config[$section][$name] = $value;
        }
    }

    // Re-apply timezone if app.timezone was loaded from the DB, overriding the
    // config.php value that was set earlier in bootstrap before DB was available.
    if (isset($config['app']['timezone'])) {
        date_default_timezone_set($config['app']['timezone']);
    }
} catch (\Exception $e) {
    // Settings table may not exist on a fresh install before setup runs.
    // Bootstrap continues with config.php values only.
}

// 5. Initialize and start custom session handler
$session = new Shuffle\Core\Session($db, $config['session']);
$session->start();

// 6. Initialize i18n
$lang = new Shuffle\Core\Lang(
    $config['app']['locale'] ?? 'en',
    ROOT_DIR . '/include/lang'
);

// 7. Initialize CSRF token manager
$csrf = new Shuffle\Core\Csrf();

// 8. Initialize Auth instance
$auth = new Shuffle\Core\Auth($db, $session);
