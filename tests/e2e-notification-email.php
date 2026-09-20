<?php
declare(strict_types=1);
/**
 * E2E service-contract suite for NOTIF-06 email notifications (spec v1.18 §5.26).
 *
 * Covers the NotificationService email fan-out against the LIVE DB using:
 *  - a FAKE Mailer (a Mailer subclass that records send() args — zero sockets)
 *  - a User-model proxy counting emailPrefsByIds() calls (batch contract)
 *  - a Card-model proxy counting boardsForCards() calls (no-N+1 contract)
 *
 *   § 1. users.email_notifications column default + PUT /v1/me flag path
 *   § 2. notifyAssignment — opt-in gate, deep link, actor-skip parity
 *   § 3. notifyComment — NOTIF-09 comment anchor, creator dedupe
 *   § 4. notifyCreatorDoneMove — creator email + non-fatal contract
 *   § 5. non-fatal: SMTP throw never escapes notify*, in-app rows still land
 *   § 6. sendTestEmail — flag bypass contract + failure path
 *   § 7. legacy path: service WITHOUT delivery injection = byte-identical behavior
 *
 * Fixtures: three dedicated member accounts (random usernames, never user 1
 * or mya 4) + one private board + lanes + cards. All self-cleaned at exit —
 * register_shutdown_function guards the cleanup.
 *
 * Usage: php tests/e2e-notification-email.php
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
    public bool $throwOnSend = false;

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

class UserProxy extends \Shuffle\Model\User
{
    public int $emailPrefsCalls = 0;

    public function emailPrefsByIds(array $userIds): array
    {
        $this->emailPrefsCalls++;
        return parent::emailPrefsByIds($userIds);
    }
}

class CardProxy extends \Shuffle\Model\Card
{
    public int $boardsForCardsCalls = 0;

    public function boardsForCards(array $cardIds): array
    {
        $this->boardsForCardsCalls++;
        return parent::boardsForCards($cardIds);
    }
}

/* ============================ fixtures =================================== */

$userModel  = new UserProxy($db);
$cardModel  = new CardProxy($db);
$notifModel = new \Shuffle\Model\Notification($db);
$userService = new \Shuffle\Service\UserService($userModel);

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$mkUser = function (string $tag) use ($userModel, $suffix): int {
    $name = "e2e-notif-{$tag}-{$suffix}";
    return $userModel->create([
        'username'      => $name,
        'password_hash' => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
        'name'          => "Notification Fixture {$tag}",
        'email'         => $name . '@example.test',
        'role'          => 'member',
        'status'        => 'active',
    ]);
};

$uAssigner = $mkUser('assigner');   // actor for assignment + comment events
$uIn       = $mkUser('in');         // opted-in recipient
$uOut      = $mkUser('out');        // opted-out recipient (also the test-email actor)

check('fixture users created', $uAssigner > 0 && $uIn > 0 && $uOut > 0);

$boardModel = new \Shuffle\Model\Board($db);
$assignmentBoard = $boardModel->create(['title' => 'Mya E2E Notif', 'visibility' => 'private', 'created_by' => $uAssigner]);
$laneModel  = new \Shuffle\Model\Lane($db);
$laneA = $laneModel->create(['board_id' => $assignmentBoard, 'title' => 'Inbox', 'position' => 1000]);
$laneDone = $laneModel->create(['board_id' => $assignmentBoard, 'title' => 'Done', 'position' => 2000]);

$cardAssign  = $cardModel->create(['lane_id' => $laneA, 'title' => 'Assigned card', 'created_by' => $uAssigner]);
$cardComment = $cardModel->create(['lane_id' => $laneA, 'title' => 'Commented card', 'created_by' => $uAssigner]);
$cardDone    = $cardModel->create(['lane_id' => $laneA, 'title' => 'My ship card', 'created_by' => $uAssigner]);

/* ============================ cleanup ==================================== */

$cleaned = false;
$cleanup = function () use ($db, $userModel, $cardModel, $laneModel, $boardModel,
                            $uAssigner, $uIn, $uOut, $assignmentBoard,
                            $laneA, $laneDone, $cardAssign, $cardComment, $cardDone) {
    global $cleaned;
    if ($cleaned) return;
    $cleaned = true;

    $fixtures = [$uAssigner, $uIn, $uOut];
    $ph = implode(',', array_fill(0, count($fixtures), '?'));
    $db->execute("DELETE FROM notifications WHERE user_id IN ($ph)", $fixtures);
    $db->execute("DELETE FROM card_assignments WHERE card_id IN ($ph)", [$cardAssign, $cardComment, $cardDone]);
    foreach ([$cardAssign, $cardComment, $cardDone] as $cid) {
        $db->execute('DELETE FROM comments WHERE card_id = ?', [$cid]);
        $cardModel->delete($cid);
    }
    foreach ([$laneA, $laneDone] as $lid) {
        $laneModel->delete($lid);
    }
    $boardModel->delete($assignmentBoard);
    foreach ($fixtures as $uid) {
        $userModel->delete($uid);
    }
};
register_shutdown_function($cleanup);

/* ============================ § 1 opt-in flag ============================= */

$row = $userModel->findById($uIn);
check('1.1 new user flag defaults OFF', (int) ($row['email_notifications'] ?? -1) === 0);

$r = $userService->updateUser($uIn, ['email_notifications' => true], ['id' => $uIn, 'role' => 'member']);
check('1.2 self opt-in ON stored', (int) $r['email_notifications'] === 1);
$r = $userService->updateUser($uIn, ['email_notifications' => false], ['id' => $uIn, 'role' => 'member']);
check('1.3 self opt-in OFF stored', (int) $r['email_notifications'] === 0);
try {
    $userService->updateUser($uIn, ['email_notifications' => 'yes'], ['id' => $uIn, 'role' => 'member']);
    check('1.4 non-boolean flag rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.4 non-boolean flag rejected', true);
}
// Cross-user must NOT be settable via the self path (ownership).
$outActor = ['id' => $uOut, 'role' => 'member'];
try {
    $userService->updateUser($uIn, ['email_notifications' => true], $outActor);
    check('1.5 cross-user flag update denied', false);
} catch (\RuntimeException $e) {
    check('1.5 cross-user flag update denied', true);
}
// Leave uIn OUT for the hot-path test; uOut OUT throughout § 2-6.
$prefOff = (int) $userModel->findById($uIn)['email_notifications'] === 0;

/* ============================ § 2 notifyAssignment ======================== */

$fakeMailer = new FakeMailer();
$svc = new \Shuffle\Service\NotificationService($notifModel, $cardModel, $lang);
$svc->setUserModel($userModel);
$svc->setMailer($fakeMailer, 'http://app.test');

$setopt = function (int $uid, bool $on) use ($db): void {
    $db->execute('UPDATE users SET email_notifications = ? WHERE id = ?', [$on ? 1 : 0, $uid]);
};

$cardModel->syncAssignments($cardAssign, [$uIn, $uOut]);

// 2a — nobody opted in: rows land, zero sends, exactly ONE prefs query.
$userModel->emailPrefsCalls = 0;
$cardModel->boardsForCardsCalls = 0;
$fakeMailer->sends = [];
$svc->notifyAssignment($cardAssign, [$uIn, $uOut, $uAssigner], $uAssigner, 'Assigned card');
$rowsIn  = $notifModel->findByUser($uIn, false, 10);
$rowsOut = $notifModel->findByUser($uOut, false, 10);
check('2.1 in-app rows created both recipients', count(array_filter($rowsIn, fn($r) => (int)$r['reference_id'] === $cardAssign)) === 1
                                   && count(array_filter($rowsOut, fn($r) => (int)$r['reference_id'] === $cardAssign)) === 1);
check('2.2 assigner got NO in-app row (actor skip)', count(array_filter($notifModel->findByUser($uAssigner, false, 10), fn($r) => (int)$r['reference_id'] === $cardAssign)) === 0);
check('2.3 zero sends when nobody opted in', count($fakeMailer->sends) === 0);
check('2.4 hot path = ONE batch prefs query', $userModel->emailPrefsCalls === 1);
check('2.5 hot path resolves no board link', $cardModel->boardsForCardsCalls === 0);

// 2b — uIn opted in: exactly one send with the deep link.
$setopt($uIn, true);
$db->execute('DELETE FROM notifications WHERE user_id IN (?, ?)', [$uIn, $uOut]);
$userModel->emailPrefsCalls = 0;
$fakeMailer->sends = [];
$svc->notifyAssignment($cardAssign, [$uIn, $uOut], $uAssigner, 'Assigned card');
check('2.6 exactly one send (opt-in gate)', count($fakeMailer->sends) === 1);
$send = $fakeMailer->sends[0] ?? [];
$expectTo = $userModel->findById($uIn)['email'];
check('2.7 send to opted-in recipient only', ($send['to'] ?? '') === $expectTo);
check('2.8 subject = event message', ($send['subject'] ?? '') !== '' && strpos($send['subject'], 'Assigned card') !== false);
$expectLink = 'http://app.test/board.php?id=' . $assignmentBoard . '&card=' . $cardAssign;
check('2.9 HTML body carries deep link (href, & escaped)',
    strpos($send['htmlBody'] ?? '', htmlspecialchars($expectLink, ENT_QUOTES, 'UTF-8')) !== false);
check('2.10 text body carries deep link', strpos($send['textBody'] ?? '', $expectLink) !== false);
check('2.11 one boardsForCards batch per send', $cardModel->boardsForCardsCalls === 1);

/* ============================ § 3 notifyComment =========================== */

$commentModel = new \Shuffle\Model\Comment($db);
$commentId = $commentModel->create([
    'card_id'    => $cardComment,
    'user_id'    => $uAssigner,
    'body'       => 'a comment for the test',
]);
$cardRow = ['id' => $cardComment, 'title' => 'Commented card', 'created_by' => $uAssigner];

$cardModel->syncAssignments($cardComment, [$uIn]);

$db->execute('DELETE FROM notifications WHERE user_id = ?', [$uIn]);
$fakeMailer->sends = [];
$svc->notifyComment($cardComment, $uAssigner, 'Commented card', 'Notification Fixture assigner', $commentId, $cardRow);
$uInRows = array_filter($notifModel->findByUser($uIn, false, 10), fn($r) => (int)$r['reference_id'] === $cardComment);
check('3.1 assignee got the comment row', count($uInRows) === 1);
check('3.2 row carries the comment anchor',
    isset(array_values($uInRows)[0]['comment_id'])
    && (int) array_values($uInRows)[0]['comment_id'] === (int) $commentId);
check('3.3 creator (commenter) NOT double-notified', count(array_filter($notifModel->findByUser($uAssigner, false, 10), fn($r) => (int)$r['reference_id'] === $cardComment)) === 0);
check('3.4 exactly one send', count($fakeMailer->sends) === 1);
$expectLink3 = 'http://app.test/board.php?id=' . $assignmentBoard . '&card=' . $cardComment . '&tab=comments&comment=' . $commentId;
check('3.5 NOTIF-09 comment anchor in the link (href, & escaped)',
    strpos($fakeMailer->sends[0]['htmlBody'] ?? '', htmlspecialchars($expectLink3, ENT_QUOTES, 'UTF-8')) !== false);
$nonActorEmail = $userModel->findById($uOut)['email'];
check('3.6 body names only the actor, never a recipient email',
    strpos($fakeMailer->sends[0]['htmlBody'] ?? '', 'Notification Fixture assigner') !== false
    && strpos($fakeMailer->sends[0]['htmlBody'] ?? '', $nonActorEmail) === false
    && strpos($fakeMailer->sends[0]['htmlBody'] ?? '', $expectTo) === false);

/* ============================ § 4 notifyCreatorDoneMove ==================== */

// The creator (uAssigner) must be opted in for the done-move email.
$setopt($uAssigner, true);
$db->execute('DELETE FROM notifications WHERE user_id IN (?, ?)', [$uAssigner, $uOut]);
$fakeMailer->sends = [];
$svc->notifyCreatorDoneMove($cardDone, $uOut, 'Notification Fixture out', 'Done',
    ['id' => $cardDone, 'title' => 'My ship card', 'created_by' => $uAssigner]);
$assignerRows = array_filter($notifModel->findByUser($uAssigner, false, 10), fn($r) => (int)$r['reference_id'] === $cardDone);
check('4.1 creator got the done-move row', count($assignerRows) === 1);
check('4.2 mover got no row', count(array_filter($notifModel->findByUser($uOut, false, 10), fn($r) => (int)$r['reference_id'] === $cardDone)) === 0);
check('4.3 exactly one send', count($fakeMailer->sends) === 1);
check('4.4 send to the opted-in creator', ($fakeMailer->sends[0]['to'] ?? '') === $userModel->findById($uAssigner)['email']);
$expectLink4 = 'http://app.test/board.php?id=' . $assignmentBoard . '&card=' . $cardDone;
check('4.5 link to the card (href, & escaped)',
    strpos($fakeMailer->sends[0]['htmlBody'] ?? '', htmlspecialchars($expectLink4, ENT_QUOTES, 'UTF-8')) !== false);

// Actor == creator → no one notified, no sends.
$db->execute('DELETE FROM notifications WHERE user_id = ?', [$uAssigner]);
$fakeMailer->sends = [];
$svc->notifyCreatorDoneMove($cardDone, $uAssigner, 'me', 'Done',
    ['id' => $cardDone, 'title' => 'My ship card', 'created_by' => $uAssigner]);
check('4.6 self-move: no creator row', count(array_filter($notifModel->findByUser($uAssigner, false, 10), fn($r) => (int)$r['reference_id'] === $cardDone)) === 0);
check('4.7 self-move: zero sends', count($fakeMailer->sends) === 0);

/* ============================ § 5 non-fatal contract ====================== */

$setopt($uIn, true);
$db->execute('DELETE FROM notifications WHERE user_id = ?', [$uIn]);
$fakeMailer->sends = [];
$fakeMailer->throwOnSend = true;
$threw = false;
try {
    $svc->notifyAssignment($cardAssign, [$uIn, $uOut], $uAssigner, 'Assigned card');
} catch (\Throwable $e) {
    $threw = true;
}
$threwC = false;
try {
    $svc->notifyComment($cardComment, $uOut, 'Commented card', 'Notification Fixture out', $commentId, $cardRow);
} catch (\Throwable $e) {
    $threwC = true;
}
check('5.1 SMTP failure does NOT escape notifyAssignment', !$threw);
check('5.2 SMTP failure does NOT escape notifyComment', !$threwC);
check('5.3 in-app row STILL created (assignment)', count(array_filter($notifModel->findByUser($uIn, false, 10), fn($r) => (int)$r['reference_id'] === $cardAssign)) >= 1);
check('5.4 in-app row STILL created (comment)', count(array_filter($notifModel->findByUser($uIn, false, 10), fn($r) => (int)$r['reference_id'] === $cardComment)) >= 1);
$fakeMailer->throwOnSend = false;

/* ============================ § 6 sendTestEmail =========================== */

$setopt($uOut, false); // test email must work with the flag OFF
$fakeMailer->sends = [];
$svc->sendTestEmail($uOut);
check('6.1 test email sent with flag OFF (bypass)', count($fakeMailer->sends) === 1);
check('6.2 recipient = the actor own email', ($fakeMailer->sends[0]['to'] ?? '') === $userModel->findById($uOut)['email']);
check('6.3 test email subject', strpos($fakeMailer->sends[0]['subject'] ?? '', 'test email') !== false);
check('6.4 no card link in the test email', strpos($fakeMailer->sends[0]['htmlBody'] ?? '', 'board.php') === false);

$fakeMailer->throwOnSend = true;
$failed = false;
try { $svc->sendTestEmail($uOut); } catch (\Throwable $e) { $failed = true; }
check('6.5 test email failure PROPAGATES (controller maps to 502)', $failed);
$fakeMailer->throwOnSend = false;

/* ============================ § 7 legacy path ============================= */

$legacy = new \Shuffle\Service\NotificationService($notifModel, $cardModel, $lang);
$db->execute('DELETE FROM notifications WHERE user_id IN (?, ?)', [$uIn, $uOut]);
$bail = false;
try {
    $legacy->notifyAssignment($cardAssign, [$uIn, $uOut], $uAssigner, 'Assigned card');
} catch (\Throwable $e) {
    $bail = true;
}
check('7.1 service WITHOUT delivery injection: rows still created, no exception',
    !$bail && count(array_filter($notifModel->findByUser($uIn, false, 10), fn($r) => (int)$r['reference_id'] === $cardAssign)) >= 1);

/* ============================ verdict ===================================== */

echo "\n" . ($failures === 0 ? '✅ ALL ' : '❌ ') . "$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
