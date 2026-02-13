#!/usr/bin/env php
<?php
/**
 * Shuffle Setup Script
 *
 * Creates the initial administrator account and default organization.
 * Run from the command line: php bin/setup.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Load minimal bootstrap (autoloader + config + DB, skip session)
define('ROOT_DIR', dirname(__DIR__));

require_once ROOT_DIR . '/include/Shuffle/Core/Autoloader.php';

$autoloader = new Shuffle\Core\Autoloader(ROOT_DIR . '/include/Shuffle');
$autoloader->register();

$configFile = ROOT_DIR . '/etc/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Error: Configuration file not found.\n");
    fwrite(STDERR, "Copy etc/config.example.php to etc/config.php and update your settings.\n");
    exit(1);
}
$config = require $configFile;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// Load language strings for output messages
$lang = new Shuffle\Core\Lang(
    $config['app']['locale'] ?? 'en',
    ROOT_DIR . '/include/lang'
);

// Connect to database
try {
    $db = new Shuffle\Core\Database($config['db']);
} catch (\Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Check if an admin user already exists
$existingAdmin = $db->fetch(
    "SELECT id FROM users WHERE role = 'admin' LIMIT 1"
);
if ($existingAdmin) {
    fwrite(STDERR, "An admin user already exists. Setup has already been completed.\n");
    exit(1);
}

echo $lang->get('setup.welcome') . "\n";
echo str_repeat('-', 40) . "\n";
echo $lang->get('setup.create_admin') . "\n\n";

// Prompt for admin details
$username = promptInput('Username: ');
$name     = promptInput('Full name: ');
$email    = promptInput('Email: ');
$password = promptPassword('Password: ');

// Validate inputs
$errors = [];
if (strlen($username) < 3 || strlen($username) > 64) {
    $errors[] = 'Username must be between 3 and 64 characters.';
}
if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    $errors[] = 'Username may only contain letters, numbers, dots, hyphens, and underscores.';
}
if (strlen($name) < 1 || strlen($name) > 128) {
    $errors[] = 'Name must be between 1 and 128 characters.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    $errors[] = 'A valid email address is required.';
}
if (strlen($password) < 8) {
    $errors[] = $lang->get('validation.password_min_length', [8]);
}

if (!empty($errors)) {
    fwrite(STDERR, "\nValidation errors:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

// Hash password with Argon2id
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);

// Create default organization and admin user in a transaction
try {
    $db->beginTransaction();

    // Create default organization
    $orgName = $config['app']['name'] ?? 'Shuffle';
    $db->execute(
        'INSERT INTO organizations (name) VALUES (?)',
        [$orgName]
    );
    $orgId = (int) $db->lastInsertId();

    // Create admin user
    $db->execute(
        'INSERT INTO users (username, password_hash, name, email, role, organization_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$username, $passwordHash, $name, $email, 'admin', $orgId, 'active']
    );

    $db->commit();

    echo "\n" . $lang->get('setup.org_created', [$orgName]) . "\n";
    echo $lang->get('setup.admin_created', [$username]) . "\n";
    echo "\n" . $lang->get('setup.success') . "\n";
} catch (\Exception $e) {
    $db->rollBack();
    fwrite(STDERR, "Setup failed: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Prompts for input from the terminal.
 *
 * @param string $prompt The prompt message to display
 * @return string        User input (trimmed)
 */
function promptInput(string $prompt): string
{
    echo $prompt;
    $input = fgets(STDIN);
    if ($input === false) {
        fwrite(STDERR, "\nInput cancelled.\n");
        exit(1);
    }
    return trim($input);
}

/**
 * Prompts for a password without echoing input (when terminal supports it).
 *
 * @param string $prompt The prompt message to display
 * @return string        Password input
 */
function promptPassword(string $prompt): string
{
    // Try to disable terminal echo for password input
    if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false) {
        echo $prompt;
        shell_exec('stty -echo 2>/dev/null');
        $password = fgets(STDIN);
        shell_exec('stty echo 2>/dev/null');
        echo "\n";
    } else {
        $password = promptInput($prompt);
    }

    if ($password === false) {
        fwrite(STDERR, "\nInput cancelled.\n");
        exit(1);
    }
    return trim($password);
}
