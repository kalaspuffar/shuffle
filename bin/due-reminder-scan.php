<?php
/**
 * due-reminder-scan — NOTIF-05 (v1.23 §5.30) due-date reminders, one-shot pass.
 *
 * Scans for cards whose due date is approaching (per the assignee's own
 * "remind N hours before" offset), fires a ONE-TIME bell row per
 * (card, user, due date) claim, and mirrors to opted-in mailboxes
 * (NOTIF-06 contract, non-fatal). Designed to run on a schedule —
 * hourly by default via a systemd timer (see systemd/shuffle-due-reminders.*)
 * or the cron fallback (doc/setup.md).
 *
 * WHY A SEPARATE ONE-SHOT (not in shuffle-ws): the RT-03 push daemon is a
 * long-lived socket server with its own restart domain and no business
 * logic beyond tail+push. A due-date scan is a discrete, rarely-fired job;
 * keeping it out of the daemon means a broken scan cannot take down live
 * board-push, and the two can be restarted/scheduled independently. The
 * single source of dedupe truth is the due_reminders claim table, so the
 * scan is idempotent AND crash-safe whether it runs from the timer, cron,
 * or by hand — twice in one second is fine, it just no-ops the second time.
 *
 * Usage:
 *   php bin/due-reminder-scan.php             # run one pass, report "N fired"
 *   php bin/due-reminder-scan.php --dry-run   # count, but fire nothing
 *
 * Exit code: 0 on success (including "0 fired"), non-zero on a fatal wiring
 * error (config missing / DB down). Per-recipient SMTP failures are logged
 * and do NOT change the exit code (NOTIF-06 non-fatal discipline).
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

$dryRun = in_array('--dry-run', $argv, true);

// ---------- bootstrap (autoloader + config + db, skip session/browser) ----
define('ROOT', dirname(__DIR__));
require ROOT . '/include/Shuffle/Core/Autoloader.php';
(new \Shuffle\Core\Autoloader(ROOT . '/include/Shuffle'))->register();

$cfgFile = ROOT . '/etc/config.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, "FATAL: $cfgFile not found\n");
    exit(1);
}
$cfg = (array) require $cfgFile;
date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');

$dbcfg = $cfg['db'] + ['charset' => 'utf8mb4'];
$pdo   = new \Shuffle\Core\Database($dbcfg);

// ---------- SMTP config: database settings take precedence (index.php) ----
$smtpRows = $pdo->fetchAll("SELECT `key`, `value` FROM `settings` WHERE `key` LIKE 'smtp.%'");
if (!empty($smtpRows)) {
    $smtpConfig = [];
    foreach ($smtpRows as $row) {
        $smtpConfig[substr((string) $row['key'], 5)] = $row['value'];
    }
} else {
    $smtpConfig = $cfg['smtp'] ?? [];
}
$mailer = new \Shuffle\Core\Mailer($smtpConfig);
$appUrl = rtrim((string) ($cfg['app']['url'] ?? 'http://localhost'), '/');

// ---------- Lang: app locale (a system job has no user locale in context)
$appLocale   = (string) ($cfg['app']['locale'] ?? 'en');
$localeChain = [$appLocale, 'en'];
$validLocale = null;
foreach ($localeChain as $c) {
    if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $c) && file_exists(ROOT . '/include/lang/' . $c . '.json')) {
        $validLocale = $c;
        break;
    }
}
$lang = new \Shuffle\Core\Lang($validLocale ?? 'en', ROOT . '/include/lang', 'en');

// ---------- Assemble NotificationService (same setters as www/v1/index.php)
$notifModel = new \Shuffle\Model\Notification($pdo);
$cardModel  = new \Shuffle\Model\Card($pdo);
$userModel  = new \Shuffle\Model\User($pdo);

$service = new \Shuffle\Service\NotificationService($notifModel, $cardModel, $lang);
$service->setUserModel($userModel);
$service->setMailer($mailer, $appUrl);
$service->setDatabase($pdo);      // requires the due_reminders claim table

// ---------- Run the scan --------------------------------------------------
try {
    if ($dryRun) {
        // Count in-window (card, assignee) pairs without claiming or firing.
        $row = $pdo->fetch(
            'SELECT COUNT(*) AS n
             FROM cards c
             JOIN lanes l              ON c.lane_id = l.id
             JOIN card_assignments ca  ON ca.card_id = c.id
             JOIN users u              ON u.id = ca.user_id
             WHERE c.due_date IS NOT NULL AND c.is_archived = 0
               AND u.due_remind_hours IS NOT NULL
               AND NOW() >= DATE_SUB(c.due_date, INTERVAL u.due_remind_hours HOUR)
               AND NOW() <  DATE_ADD(c.due_date, INTERVAL 1 DAY)',
            []
        );
        $n = (int) ($row['n'] ?? 0);
        echo "due-reminders: $n in-window pair(s) — DRY RUN, nothing fired\n";
        exit(0);
    }

    $fired = $service->scanDueReminders();
    echo "due-reminders: $fired fired\n";
    exit(0);
} catch (\Throwable $e) {
    // A fatal wiring/DB error. Do NOT swallow silently (a host admin should
    // see it), but keep the message out of any per-recipient detail.
    fwrite(STDERR, 'due-reminders: FATAL: ' . $e->getMessage() . "\n");
    exit(1);
}
