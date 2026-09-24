<?php
declare(strict_types=1);
/**
 * E2E service-contract suite for NOTIF-05 due-date reminders (spec v1.23 §5.30).
 *
 * Uses the same live-DB fixture pattern as tests/e2e-notification-email.php:
 * dedicated member accounts (random usernames; never user 1 or mya 4),
 * a private board, lanes (incl. a Done + an In Progress), and fixture-owned
 * cards with day-granular due dates, a FakeMailer recording sends (zero
 * sockets), and full self-cleanup via register_shutdown_function.
 *
 * Window semantics (NOTIF-05 / §5.30):
 *   in-window  iff  NOW() >= due_date − offset  AND  NOW() < due_date + 1 day
 * So a pair fires when we are inside the user's personal "remind me" window
 * — either BEFORE the deadline (the offset has opened) or on / up to a day
 * after (a reminder for a card that is already past due on a deadline we
 * passed is still in-window; only a card MORE than one day past due falls
 * out). The window is day-granular because `cards.due_date` is a DATE.
 *
 * Per-user separation (avoids one shared-offset user confusing the counts):
 *
 *   uA  offset 24h, one card due 3 days ago (in-window)              → FIRES
 *   uB  offset 24h, two cards due 6 days out (out-of-window)         → stays quiet
 *   uC  offset 24h, card due 2 days ago (in-window)                  → FIRES
 *   uW  offset 24h, card due 30 days ago (beyond +1d grace)          → stays quiet
 *   uW2 offset 72h, card due 30 days ago (beyond +1d grace)          → stays quiet
 *   uD  offset 24h, card due 3 days ago BUT on a Done lane           → stays quiet
 *   uAr offset 24h, card due 3 days ago BUT archived                 → stays quiet
 *   uU  offset 24h, one card due 3 days ago, NOT assigned            → stays quiet
 *   uR  offset 24h, pre-change due date 6 days out (out-of-window);
 *       post-change 5 days out (in-window)                           → fires ONCE
 *       after the re-arm
 *   uO  offset 48h, one card due 3 days ago (in-window), email OFF   → bell only
 *   uT  offset 48h, one card due 3 days ago (in-window), email ON +
 *       Mailer configured to throw                                   → bell still fires
 *
 * Usage: php tests/e2e-due-reminders.php
 */
require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0;
$failures = 0;

function check(string $name, bool $cond): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  $name\n";
}

/* ============================ fakes (no sockets) ========================= */
class FakeMailer extends \Shuffle\Core\Mailer
{
    /** @var array<int, array{to:string, subject:string, html:string, text:string}> */
    public array $sends = [];
    public bool  $throwOnSend = false;

    public function __construct()
    {
        parent::__construct([
            'host'       => '127.0.0.1',
            'port'       => 25,
            'encryption' => 'none',
            'username'   => '',
            'password'   => '',
            'from_email' => 'noreply@example.test',
            'from_name'  => 'Shuffle Test',
        ]);
    }

    public function send(string $to, string $subject, string $htmlBody, string $textBody): void
    {
        if ($this->throwOnSend) {
            throw new \RuntimeException('fake SMTP explosion (test-driven)');
        }
        $this->sends[] = compact('to', 'subject', 'htmlBody', 'textBody');
    }
}

/* ============================ fixtures =================================== */
$userModel  = new \Shuffle\Model\User($db);
$cardModel  = new \Shuffle\Model\Card($db);
$notifModel = new \Shuffle\Model\Notification($db);
$userService = new \Shuffle\Service\UserService($userModel);

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

function mk_fixture_user(\Shuffle\Model\User $um, string $tag, string $suffix): int {
    $name = "e2e-due-{$tag}-{$suffix}";
    return $um->create([
        'username'      => $name,
        'password_hash' => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
        'name'          => "Due Reminder Fixture {$tag}",
        'email'         => $name . '@example.remind.test',
        'role'          => 'member',
        'status'        => 'active',
    ]);
}

$uA  = mk_fixture_user($userModel, 'a',  $suffix);
$uB  = mk_fixture_user($userModel, 'b',  $suffix);
$uC  = mk_fixture_user($userModel, 'c',  $suffix);
$uW  = mk_fixture_user($userModel, 'w',  $suffix);
$uW2 = mk_fixture_user($userModel, 'w2', $suffix);
$uD  = mk_fixture_user($userModel, 'd',  $suffix);
$uAr = mk_fixture_user($userModel, 'ar', $suffix);
$uU  = mk_fixture_user($userModel, 'u',  $suffix);
$uR  = mk_fixture_user($userModel, 'r',  $suffix);
$uO  = mk_fixture_user($userModel, 'o',  $suffix);
$uT  = mk_fixture_user($userModel, 't',  $suffix);
$uCr = mk_fixture_user($userModel, 'cr', $suffix);   // card creator / actor

check('fixture users created',
    $uA > 0 && $uB > 0 && $uC > 0 && $uW > 0 && $uW2 > 0 && $uD > 0 && $uAr > 0
    && $uU > 0 && $uR > 0 && $uO > 0 && $uT > 0 && $uCr > 0);

$boardModel = new \Shuffle\Model\Board($db);
$testBoard = $boardModel->create(['title' => "Due E2E {$suffix}", 'visibility' => 'private', 'created_by' => $uCr]);
$laneModel = new \Shuffle\Model\Lane($db);
$laneInbox    = $laneModel->create(['board_id' => $testBoard, 'title' => 'Inbox',       'position' => 1000]);
$laneInProg   = $laneModel->create(['board_id' => $testBoard, 'title' => 'In Progress', 'position' => 1500]);
$laneDone     = $laneModel->create(['board_id' => $testBoard, 'title' => 'Done',        'position' => 2000]);

function mk_card(\Shuffle\Model\Card $cm, string $title, int $laneId, ?string $due, int $creator): int {
    return $cm->create([
        'lane_id'    => $laneId,
        'title'      => $title,
        'due_date'   => $due,
        'created_by' => $creator,
    ]);
}

// Day-granular due dates (cards.due_date is DATE). Verified window rule:
//   in-window iff NOW() >= due − offset  AND  NOW() < due + 1 day
// so (any offset ≤ 720h, any time of day): due TODAY fires; due TOMORROW
// fires (the "remind before" case); due 5+ days out has not opened; any
// PAST-due date is out (a missed deadline never gets a "due soon" ping).
$dueIn    = (string) date('Y-m-d');                        // today   → IN window
$dueIn2b  = (string) date('Y-m-d', time() + 86400);        // tomorrow → IN window (remind-before)
$dueOut   = (string) date('Y-m-d', time() + 6*86400);      // 6 days out → not yet
$dueOut2  = (string) date('Y-m-d', time() + 7*86400);      // 7 days out → not yet
$duePast  = (string) date('Y-m-d', time() - 30*86400);     // past due → out
$duePast2 = (string) date('Y-m-d', time() - 60*86400);     // past due → out
$duePre   = (string) date('Y-m-d', time() + 6*86400);      // re-arm start: not yet
$duePost  = (string) date('Y-m-d', time() + 86400);        // re-arm target: in window

$cardA    = mk_card($cardModel, 'A today reminder',   $laneInbox,  $dueIn,    $uCr);
$cardB1   = mk_card($cardModel, 'B future near',      $laneInbox,  $dueOut,   $uCr);
$cardB2   = mk_card($cardModel, 'B future far',       $laneInbox,  $dueOut2,  $uCr);
$cardC    = mk_card($cardModel, 'C remind tomorrow',  $laneInbox,  $dueIn2b,  $uCr);
$cardW    = mk_card($cardModel, 'W past far',         $laneInbox,  $duePast,  $uCr);
$cardW2   = mk_card($cardModel, 'W2 past further',    $laneInbox,  $duePast2, $uCr);
$cardD    = mk_card($cardModel, 'D done lane',        $laneDone,   $dueIn,    $uCr);
$cardAr   = mk_card($cardModel, 'Ar archived',        $laneInbox,  $dueIn,    $uCr);
$cardU    = mk_card($cardModel, 'U unassigned',       $laneInbox,  $dueIn,    $uCr);
$cardR    = mk_card($cardModel, 'R rearms',           $laneInbox,  $duePre,   $uCr);
$cardO    = mk_card($cardModel, 'O opted out',        $laneInbox,  $dueOut2,  $uCr); // 7d out → quiet at §2, re-armed in §4
$cardT    = mk_card($cardModel, 'T smtp throw',       $laneInbox,  $dueOut,   $uCr); // 6d out → quiet at §2, re-armed in §5

check('fixture cards created',
    $cardA > 0 && $cardB1 > 0 && $cardB2 > 0 && $cardC > 0 && $cardW > 0
    && $cardW2 > 0 && $cardD > 0 && $cardAr > 0 && $cardU > 0
    && $cardR > 0 && $cardO > 0 && $cardT > 0);

/* ============================ cleanup ==================================== */
$cleaned = false;
$allUsers = [$uA,$uB,$uC,$uW,$uW2,$uD,$uAr,$uU,$uR,$uO,$uT,$uCr];
$allCards = [$cardA,$cardB1,$cardB2,$cardC,$cardW,$cardW2,
             $cardD,$cardAr,$cardU,$cardR,$cardO,$cardT];
$allLanes = [$laneInbox, $laneInProg, $laneDone];
$cleanup = function () use ($db, $userModel, $laneModel, $boardModel,
                            $allUsers, $allCards, $allLanes, $testBoard) {
    global $cleaned;
    if ($cleaned) return;
    $cleaned = true;
    try {
        foreach ($allUsers as $uid) {
            $db->execute('DELETE FROM notifications WHERE user_id = ?', [$uid]);
        }
        $phC = implode(',', array_fill(0, count($allCards), '?'));
        $db->execute("DELETE FROM due_reminders WHERE card_id IN ($phC)", $allCards);
        $db->execute("DELETE FROM card_assignments WHERE card_id IN ($phC)", $allCards);
        $db->execute("DELETE FROM cards WHERE id IN ($phC)", $allCards);
        foreach ($allLanes as $lid) { $laneModel->delete($lid); }
        $boardModel->delete($testBoard);
        foreach ($allUsers as $uid) { $userModel->delete($uid); }
    } catch (\Throwable $e) {
        error_log('NOTIF-05 e2e cleanup failed: ' . $e->getMessage());
    }
};
register_shutdown_function($cleanup);

/* ============================ § 1 preference ============================= */
$row = $userModel->findById($uA);
check('1.1 new user due_remind_hours defaults NULL (off)', $row['due_remind_hours'] === null);

$r = $userService->updateUser($uA, ['due_remind_hours' => 24], ['id' => $uA, 'role' => 'member']);
check('1.2 self set stored (24 hours)', (int) $r['due_remind_hours'] === 24);

try {
    $userService->updateUser($uA, ['due_remind_hours' => null], ['id' => $uA, 'role' => 'member']);
    check('1.3 null resets to NULL (off)', $userModel->findById($uA)['due_remind_hours'] === null);
} catch (\InvalidArgumentException $e) {
    check('1.3 null resets to NULL (off)', false);
}

$r = $userService->updateUser($uA, ['due_remind_hours' => 720], ['id' => $uA, 'role' => 'member']);
check('1.4 upper bound 720 stored', (int) $r['due_remind_hours'] === 720);

$allRejected = true;
foreach ([0, -1, 721, '24', 1.5, true, false] as $bad) {
    try {
        $userService->updateUser($uA, ['due_remind_hours' => $bad], ['id' => $uA, 'role' => 'member']);
        $allRejected = false;
    } catch (\InvalidArgumentException $e) {
        // expected
    }
}
check('1.5 non-int / out-of-range / null-equivalents rejected', $allRejected);

/* ============================ § 2 scan: window + bell + email ============ */
$fakeMailer = new FakeMailer();
$svc = new \Shuffle\Service\NotificationService($notifModel, $cardModel, $lang);
$svc->setUserModel($userModel);
$svc->setMailer($fakeMailer, 'http://app.test');
$svc->setDatabase($db);

// Per-user offsets (as their own setting; matches the fixture design above).
$offsets = [
    $uA => 24, $uB => 24, $uC => 24, $uW => 24, $uW2 => 72,
    $uD => 24, $uAr => 24, $uU => 24, $uR => 24, $uO => 48, $uT => 48,
];
foreach ($offsets as $uid => $h) {
    $db->execute('UPDATE users SET due_remind_hours = ? WHERE id = ?', [$h, $uid]);
}

// Default emails OFF; only uA and uR are opted in (uT switches on in § 5).
foreach ([$uA, $uR] as $uid) {
    $db->execute('UPDATE users SET email_notifications = 1 WHERE id = ?', [$uid]);
}
// (uO's offness is the point of § 4; uT switches on only in § 5 — that is
//  the non-fatal throw check. uCr, the card creator, gets no reminder.)

// Assignments: the in-window cards each to their own user.
$cardModel->syncAssignments($cardA,  [$uA]);
$cardModel->syncAssignments($cardB1, [$uB]);
$cardModel->syncAssignments($cardB2, [$uB]);
$cardModel->syncAssignments($cardC,  [$uC]);
$cardModel->syncAssignments($cardW,  [$uW]);
$cardModel->syncAssignments($cardW2, [$uW2]);
$cardModel->syncAssignments($cardD,  [$uD]);
$cardModel->syncAssignments($cardAr, [$uAr]);
// (cardU intentionally gets no assignments: no one to remind.)
$cardModel->syncAssignments($cardR,  [$uR]);
$cardModel->syncAssignments($cardO,  [$uO]);
$cardModel->syncAssignments($cardT,  [$uT]);

// uAr card: archived (excluded from scan SQL).
$db->execute('UPDATE cards SET is_archived = 1 WHERE id = ?', [$cardAr]);

$db->execute('DELETE FROM notifications WHERE user_id IN (' . implode(',', array_map('intval', [$uA,$uB,$uC,$uW,$uW2,$uD,$uAr,$uU,$uR,$uO,$uT])) . ')');  // fresh state, fixture users only
$fakeMailer->sends = [];

$fired = $svc->scanDueReminders();
$bell = function (int $uid) use ($notifModel): array {
    return array_values(array_filter(
        $notifModel->findByUser($uid, false, 20),
        fn($r) => ($r['type'] ?? '') === 'due'
    ));
};

check('2.1 scan fired 2 (uA today, uC tomorrow — R/O/T still out, exclusions hold)',
    $fired === 2, 'fired=' . $fired);
check('2.2 bell row for uA (today-card)', count($bell($uA)) === 1);
check('2.3 no bell for uB (two future-due cards, out-of-window)', count($bell($uB)) === 0);
check('2.4 bell row for uC (tomorrow-card — remind-before)', count($bell($uC)) === 1);
check('2.5 no bell for uW (30 days past — beyond +1d grace)', count($bell($uW)) === 0);
check('2.6 no bell for uW2 (60 days past — beyond +1d grace)', count($bell($uW2)) === 0);
check('2.7 no bell for uD (Done lane card)', count($bell($uD)) === 0);
check('2.8 no bell for uAr (archived card)', count($bell($uAr)) === 0);
check('2.9 no bell for uU (unassigned card)', count($bell($uU)) === 0);
check('2.10 no bell for uR (6 days out — re-arm not yet)', count($bell($uR)) === 0);
check('2.11 no bell for uO (7 days out — re-arm not yet)', count($bell($uO)) === 0);
check('2.12 no bell for uT (6 days out — re-arm not yet)', count($bell($uT)) === 0);

// Email contract from scan #1: exactly ONE successful send (uA, opted in).
// uC is not opted in → no email even though the bell fired.
check('2.14 exactly one email sent (opted-in uA)',
    count($fakeMailer->sends) === 1
    && ($fakeMailer->sends[0]['to'] ?? '') === $userModel->findById($uA)['email']);
$expectLink = 'http://app.test/board.php?id=' . $testBoard . '&card=' . $cardA;
$htmlHas = strpos($fakeMailer->sends[0]['htmlBody'] ?? '', htmlspecialchars($expectLink, ENT_QUOTES, 'UTF-8')) !== false;
$textHas = strpos($fakeMailer->sends[0]['textBody']   ?? '', $expectLink) !== false;
check('2.15 email carries the board/card deep link', $htmlHas || $textHas);

// Claim-table dedupe: an immediate re-scan is a no-op.
$firedAgain = $svc->scanDueReminders();
check('2.16 claim table dedupes: re-scan fires 0', $firedAgain === 0);

/* ============================ § 3 re-arm on changed due date ============= */
// uR's card is 6 days out (out-of-window). Move it to tomorrow → in a FRESH,
// un-claimed window. Only uR is opted in + it's the only re-arming card.
$db->execute("UPDATE cards SET due_date = ? WHERE id = ?", [$duePost, $cardR]);
$fakeMailer->sends = [];
$firedRearm = $svc->scanDueReminders();
check('3.1 re-arm fires the reminder exactly once', $firedRearm === 1);
check('3.2 bell row created for the re-armed uR card', count($bell($uR)) === 1);
$claimsR = $db->fetch('SELECT COUNT(*) AS n FROM due_reminders WHERE card_id = ?', [$cardR]);
check('3.3 exactly one claim row for the re-armed card', (int)($claimsR['n'] ?? 0) === 1);
$uREmailable = array_filter($fakeMailer->sends,
    fn($s) => strpos($s['htmlBody'] ?? '', 'card=' . $cardR) !== false
         || strpos($s['textBody'] ?? '', 'card=' . $cardR) !== false);
check('3.4 re-arm email fired for the opted-in uR', count($uREmailable) === 1
    && $uREmailable[0]['to'] === $userModel->findById($uR)['email']);

// Re-run — the re-armed claim already exists: no second fire.
$firedReplay = $svc->scanDueReminders();
check('3.5 a second scan does not re-fire the re-armed card', $firedReplay === 0);

/* ============================ § 4 opt-out (bell only, no email) ========== */
// uO's card is 7 days out (out-of-window at §2, no claim). uO stays OFF for
// email (email_notifications = 0 — it is a brand-new user, the default). The
// re-arm brings its card into the window; the bell must fire, the email must NOT.
$db->execute("UPDATE cards SET due_date = ? WHERE id = ?", [$duePost, $cardO]);
$fakeMailer->sends = [];
$firedOptOut = $svc->scanDueReminders();
check('4.1 re-armed uO card fires (bell) at § 4', $firedOptOut === 1);
check('4.2 bell row created for the re-armed uO card', count($bell($uO)) === 1);
$uOResidualSends = array_filter($fakeMailer->sends,
    fn($s) => strpos($s['htmlBody'] ?? '', 'card=' . $cardO) !== false
         || strpos($s['textBody'] ?? '', 'card=' . $cardO) !== false);
check('4.3 zero emails for the opted-out uO card', count($uOResidualSends) === 0);

/* ============================ § 5 non-fatal SMTP throw =================== */
// uT opts in (email_notifications = 1), the mailer is armed to throw on send,
// and we re-arm its card into the window. The bell row must still land and
// the exception must NOT escape scanDueReminders (NOTIF-06 non-fatal rule).
$fakeMailer->throwOnSend = true;
$fakeMailer->sends = [];
$db->execute('UPDATE users SET email_notifications = 1 WHERE id = ?', [$uT]);
$db->execute("UPDATE cards SET due_date = ? WHERE id = ?", [$duePost, $cardT]);

$threw = false;
$firedThrow = 0;
try {
    $firedThrow = $svc->scanDueReminders();
} catch (\Throwable $e) {
    $threw = true;
}
$fakeMailer->throwOnSend = false;
$bellT = count($bell($uT));
check('5.1 SMTP throw did NOT escape the scan', !$threw);
check('5.2 bell row for the throw card (uT) still created', $bellT === 1);
check('5.3 the throw card is still counted as fired', $firedThrow === 1);

/* ============================ § 6 i18n parity ============================ */
$enKeys = json_decode(file_get_contents(dirname(__DIR__) . '/include/lang/en.json'), true) ?: [];
$svKeys = json_decode(file_get_contents(dirname(__DIR__) . '/include/lang/sv.json'), true) ?: [];
$need = ['notification.due_reminder', 'profile.section_due_reminders',
        'profile.due_remind_hours', 'profile.due_remind_hours_help',
        'profile.due_remind_range'];
$enAllOk = true; $svAllOk = true;
foreach ($need as $k) {
    if (!isset($enKeys[$k])) $enAllOk = false;
    if (!isset($svKeys[$k])) $svAllOk = false;
}
check('6.1 all five NOTIF-05 keys present in en.json', $enAllOk);
check('6.2 all five NOTIF-05 keys present in sv.json', $svAllOk);
$en = preg_match_all('/\{\d\}/', $enKeys['notification.due_reminder'] ?? '', $m);
$sv = preg_match_all('/\{\d\}/', $svKeys['notification.due_reminder'] ?? '', $m);
check('6.3 due_reminder placeholder parity en==sv', $en === $sv && $en >= 2);

/* ============================ summary ==================================== */
echo "\n";
echo '---- NOTIF-05 due reminders (' . $checks . " checks, {$failures} failures) ----\n";
exit($failures === 0 ? 0 : 1);
