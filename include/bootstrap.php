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

// 6. Initialize i18n (INTL-02/03, v1.22 §5.29)
//
// Locale resolution order:
//   (1) the logged-in user's preference  — users.language (NULL = "app default")
//   (2) the app default                  — app.locale (setup wizard / config.php)
//   (3) en                                — ships on every install, authoritative
//
// Every hop checks the file exists (INTL-03): a preference whose language file
// was removed (e.g. a community file) falls back SILENTLY — the user is never
// told their preference is broken. A language may still be *selected* (its
// row persists) even if its file is missing; only the render falls back.
// The English base layer is loaded by Lang itself for non-'en' locales so a
// KEY MISSING FROM THE LANGUAGE FILE renders in English, never as a raw key
// (INTL-04). Unauthenticated pages (no $_SESSION['user_id']) skip step (1)
// and render the app default — the preference travels with the user, not the
// browser (THEME-03 model).
$appLocale = $config['app']['locale'] ?? 'en';
$locale = $appLocale;
try {
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== null) {
        $prefRow = $db->fetch(
            'SELECT `language` FROM `users` WHERE `id` = ?',
            [(int) $_SESSION['user_id']]
        );
        // Guard against a missing `language` column before the migration has
        // run on a fresh install: the SELECT would throw, and we must boot.
        if (is_array($prefRow) && array_key_exists('language', $prefRow)) {
            $userLang = $prefRow['language'];
            if (is_string($userLang) && $userLang !== ''
                && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $userLang) === 1
            ) {
                $locale = $userLang;
            }
        }
    }
} catch (\Throwable $e) {
    // DB not reachable / column absent → fall through to the app default.
    $locale = $appLocale;
}

$langDir = ROOT_DIR . '/include/lang';
$exists = static function (string $code) use ($langDir): bool {
    return file_exists(rtrim($langDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $code . '.json');
};

// (1) → (2) → (3) file-existence chain (INTL-03 silent fallback).
if (!$exists($locale)) {
    $locale = $appLocale;
}
if (!$exists($locale)) {
    $locale = ($exists('en')) ? 'en' : $appLocale; // en is the normal path
}

$lang = new Shuffle\Core\Lang(
    $locale,
    ROOT_DIR . '/include/lang',
    ($locale === 'en') ? null : 'en'   // INTL-04: English base layer backfill
);

// 7. Initialize CSRF token manager
$csrf = new Shuffle\Core\Csrf();

// 8. Initialize Auth instance
$auth = new Shuffle\Core\Auth($db, $session);
