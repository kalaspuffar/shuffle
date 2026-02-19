#!/usr/bin/env php
<?php
/**
 * Trello Board Import Tool
 *
 * Imports a Trello board JSON export into Shuffle.
 * Usage: php bin/trello-import.php --org=<org_id> <path/to/trello-export.json>
 *
 * Options:
 *   --org=<id>    Target organization ID (required)
 *   --user=<id>   Importer user ID; defaults to first admin user
 *   --dry-run     Validate and preview import without writing to database
 *
 * The import is idempotent: re-running with the same JSON file will fail
 * with an error if the board has already been imported (detected via trello_id).
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

// Parse arguments
$opts = getopt('', ['org:', 'user:', 'dry-run'], $rest_index);
$positional = array_slice($argv, $rest_index);

$orgId   = isset($opts['org'])  ? (int) $opts['org']  : 0;
$userId  = isset($opts['user']) ? (int) $opts['user'] : 0;
$dryRun  = isset($opts['dry-run']);
$jsonPath = $positional[0] ?? null;

// Show usage if required arguments are missing
if (!$orgId || !$jsonPath) {
    fwrite(STDERR, "Usage: php bin/trello-import.php --org=<org_id> [--user=<user_id>] [--dry-run] <export.json>\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --org=<id>    Target organization ID (required)\n");
    fwrite(STDERR, "  --user=<id>   Importer user ID (defaults to first admin)\n");
    fwrite(STDERR, "  --dry-run     Validate JSON without importing\n");
    exit(1);
}

if (!file_exists($jsonPath)) {
    fwrite(STDERR, "Error: File not found: {$jsonPath}\n");
    exit(1);
}

// Connect to database
try {
    $db = new Shuffle\Core\Database($config['db']);
} catch (\Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Resolve importer user
if ($userId === 0) {
    $adminUser = $db->fetch("SELECT id FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1");
    if (!$adminUser) {
        fwrite(STDERR, "Error: No active admin user found. Specify --user=<id> explicitly.\n");
        exit(1);
    }
    $userId = (int) $adminUser['id'];
    echo "Using admin user ID {$userId} as importer.\n";
}

// Verify organization exists
$org = $db->fetch("SELECT id, name FROM organizations WHERE id = ?", [$orgId]);
if (!$org) {
    fwrite(STDERR, "Error: Organization ID {$orgId} not found.\n");
    exit(1);
}
echo "Target organization: {$org['name']} (ID {$orgId})\n";

// Dry-run mode: just validate the JSON
if ($dryRun) {
    echo "Dry-run mode — validating JSON only.\n";
    $json = file_get_contents($jsonPath);
    if ($json === false) {
        fwrite(STDERR, "Error: Cannot read file: {$jsonPath}\n");
        exit(1);
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        fwrite(STDERR, "Error: Invalid JSON in {$jsonPath}\n");
        exit(1);
    }
    $requiredKeys = ['id', 'name', 'lists', 'cards', 'members', 'actions'];
    $missing = array_diff($requiredKeys, array_keys($data));
    if (!empty($missing)) {
        fwrite(STDERR, "Error: Missing required keys: " . implode(', ', $missing) . "\n");
        exit(1);
    }
    $listCount    = count($data['lists'] ?? []);
    $cardCount    = count($data['cards'] ?? []);
    $memberCount  = count($data['members'] ?? []);
    $actionCount  = count(array_filter($data['actions'] ?? [], fn($a) => ($a['type'] ?? '') === 'commentCard'));
    echo "Board:   {$data['name']}\n";
    echo "Lists:   {$listCount}\n";
    echo "Cards:   {$cardCount}\n";
    echo "Members: {$memberCount}\n";
    echo "Comments (from actions): {$actionCount}\n";
    echo "Dry-run complete — no data was written.\n";
    exit(0);
}

// Build services for import
$s3Client = null;
if (!empty($config['s3']['endpoint'])) {
    try {
        $s3Client = new Shuffle\Core\S3Client($config['s3']);
    } catch (\Exception $e) {
        echo "Warning: S3 client could not be initialized — attachment import will be skipped.\n";
        echo "  " . $e->getMessage() . "\n";
    }
}

$importService = new Shuffle\Service\ImportService(
    $db,
    $s3Client,
    $config['upload']['chunk_size'] ?? 5242880
);

// Run the import
try {
    echo "Starting import of: {$jsonPath}\n";
    echo str_repeat('-', 60) . "\n";

    $boardId = $importService->importTrelloBoard($jsonPath, $orgId, $userId);

    echo str_repeat('-', 60) . "\n";
    echo "Import complete. New board ID: {$boardId}\n";
    exit(0);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "\nImport failed: " . $e->getMessage() . "\n");
    exit(1);
} catch (\Exception $e) {
    fwrite(STDERR, "\nUnexpected error: " . $e->getMessage() . "\n");
    if (!empty($config['app']['debug'])) {
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }
    exit(1);
}
