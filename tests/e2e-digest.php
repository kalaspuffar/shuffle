<?php
declare(strict_types=1);
/**
 * E2E check for the Priority Digest (PRIO-12..14).
 *
 * Exercises, against the live DB (headless, as user $argv[1] default 1):
 *   [1] digest shape + live recompute + pre-existing-state safety
 *   [2] top-N truncation + clamping (0→1, 999→50, -5→1)
 *   [3] markdown contract (bold headings, emoji markers, plain-URL deep
 *       links, honest "(none)" sections)
 *   [4] "Done yesterday" from the card_activity log:
 *       - card moved to "Done" lane in-window  → listed;
 *       - card moved to "Done-ness" in-window  → listed (shared \bdone\b
 *         matcher — a hyphen IS a word boundary; same rule as PRIO-04/09);
 *       - card moved to "Completed"            → NOT listed (true negative);
 *       - no-op move Done → "Done — v2"        → NOT listed;
 *       - same card listed once even if moved to Done twice in-window.
 *   [5] BOARD-04b: a user with no board access sees no items, no error.
 *   [6] activity service not wired → RuntimeException (controller: 503).
 *
 * Fixtures live on a dedicated temp board (E2E-DIG-temp-*), so real boards
 * and cards are never touched. The acting user's PRE-EXISTING prioritized
 * list is snapshotted, and every assertion is relative to it — the test is
 * safe to re-run at any time and restores state fully on shutdown.
 *
 * Usage:  php tests/e2e-digest.php [user_id]   (default 1 = admin)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$asUserId = isset($argv[1]) ? (int) $argv[1] : 1;

// ---------------------------------------------------------------------------
// Auth fakes
// ---------------------------------------------------------------------------
class HeadlessAuthAll extends \Shuffle\Core\Auth
{
    private array $fixedUser;
    public function __construct(array $fixedUser) { $this->fixedUser = $fixedUser; }
    public function currentUser(): ?array { return $this->fixedUser; }
    public function requireAuth(): array { return $this->fixedUser; }
    public function requireRole(string $role): array { return $this->fixedUser; }
    public function canAccessBoard(int $boardId): bool { return true; }
}

class HeadlessAuthDeny extends \Shuffle\Core\Auth
{
    private array $fixedUser;
    public function __construct(array $fixedUser) { $this->fixedUser = $fixedUser; }
    public function currentUser(): ?array { return $this->fixedUser; }
    public function requireAuth(): array { return $this->fixedUser; }
    public function requireRole(string $role): array { return $this->fixedUser; }
    public function canAccessBoard(int $boardId): bool { return false; }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------
$checks = 0;
$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if (!$ok) { $failures++; echo "  FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
    else { echo "  ok:   $name\n"; }
}

$restored = false;
$tempBoards = [];
$tempLanes  = [];
$tempCards  = [];
$snapshotUserPrio = $db->fetchAll('SELECT * FROM user_prio');
$actorId = null; // set after user load

function cleanup(): void
{
    global $db, $restored, $tempCards, $tempLanes, $tempBoards, $snapshotUserPrio;
    if ($restored) return;
    $restored = true;
    // Restore user_prio exactly (drop everything we added).
    foreach ($db->fetchAll('SELECT id FROM user_prio') as $row) {
        $keep = false;
        foreach ($snapshotUserPrio as $s) {
            if ((int)$s['id'] === (int)$row['id']) { $keep = true; break; }
        }
        if (!$keep) { $db->execute('DELETE FROM user_prio WHERE id = ?', [(int)$row['id']]); }
    }
    // Explicit log-row cleanup (board cascade would also catch these).
    $db->execute("DELETE FROM card_activity WHERE event = 'card_moved'
                  AND payload_json LIKE '%E2E-DIG%'");
    // Cards first (cascades card_assignments + card_activity FKs), then
    // lanes, then board.
    if ($tempCards) {
        $ph = implode(',', array_fill(0, count($tempCards), '?'));
        $db->execute("DELETE FROM cards WHERE id IN ($ph)", $tempCards);
    }
    if ($tempLanes) {
        $ph = implode(',', array_fill(0, count($tempLanes), '?'));
        $db->execute("DELETE FROM lanes WHERE id IN ($ph)", $tempLanes);
    }
    if ($tempBoards) {
        $ph = implode(',', array_fill(0, count($tempBoards), '?'));
        $db->execute("DELETE FROM boards WHERE id IN ($ph)", $tempBoards);
    }
}
register_shutdown_function('cleanup');

$asUser = $db->fetch('SELECT id, name, role, status, organization_id FROM users WHERE id = ?', [$asUserId]);
if ($asUser === null) { die("No user with id $asUserId\n"); }
$actorId = (int) $asUser['id'];

$card      = new \Shuffle\Model\Card($db);
$lane      = new \Shuffle\Model\Lane($db);
$board     = new \Shuffle\Model\Board($db);
$userPrio  = new \Shuffle\Model\UserPrio($db);
$userMod   = new \Shuffle\Model\User($db);
$activity  = new \Shuffle\Model\CardActivity($db);
$actSvc    = new \Shuffle\Service\CardActivityService($activity, $card, $lane, $userMod);
$lang      = new \Shuffle\Core\Lang('en', dirname(__DIR__) . '/include/lang');

$authAll = new HeadlessAuthAll($asUser);
$svc     = new \Shuffle\Service\PriorityService($userPrio, $card, $lane, $board, $authAll, $actSvc, $lang);

echo "Digest E2E as user #{$asUser['id']} ({$asUser['name']}, {$asUser['role']})\n";

// ---------------------------------------------------------------------------
// Fixture: dedicated temp board with:
//   laneA  "Alpha"      — source lane for all fixture cards
//   laneD  "Done"       — positive done-lane
//   laneDN "Done-ness"  — positive (shared \bdone\b matcher)
//   laneD2 "Done — v2"  — second Done lane (no-op re-lane case)
//   laneC  "Completed"  — negative (NOT a Done lane by the matcher)
// ---------------------------------------------------------------------------
$boardTitle = 'E2E-DIG-temp-' . time();
$boardId = $board->create(['title' => $boardTitle, 'created_by' => $actorId]);
$tempBoards[] = $boardId;

$laneA    = $lane->create(['board_id' => $boardId, 'title' => 'Alpha', 'icon' => null, 'position' => 1000]);
$laneD    = $lane->create(['board_id' => $boardId, 'title' => 'Done', 'icon' => null, 'position' => 2000]);
$laneDN   = $lane->create(['board_id' => $boardId, 'title' => 'Done-ness', 'icon' => null, 'position' => 3000]);
$laneD2   = $lane->create(['board_id' => $boardId, 'title' => 'Done — v2', 'icon' => null, 'position' => 4000]);
$laneC    = $lane->create(['board_id' => $boardId, 'title' => 'Completed', 'icon' => null, 'position' => 5000]);
foreach ([$laneA, $laneD, $laneDN, $laneD2, $laneC] as $l) { $tempLanes[] = $l; }

$mkCard = function (int $laneId, string $title) use ($card, &$tempCards, $actorId): int {
    $id = $card->create([
        'lane_id'     => $laneId,
        'title'       => $title,
        'description' => null,
        'due_date'    => null,
        'created_by'  => $actorId,
    ]);
    $tempCards[] = $id;
    return $id;
};

$topCards = [
    $mkCard($laneA, 'E2E-DIG-top-1'),
    $mkCard($laneA, 'E2E-DIG-top-2'),
    $mkCard($laneA, 'E2E-DIG-top-3'),
];
$doneHitCard   = $mkCard($laneA, 'E2E-DIG-hit-done');
$doneNessCard  = $mkCard($laneA, 'E2E-DIG-hit-doneness');
$completedCard = $mkCard($laneA, 'E2E-DIG-not-completed');
$noopCard      = $mkCard($laneA, 'E2E-DIG-noop-done-done');

// The temp board is 'private' owned by the acting user → accessible (HeadlessAuthAll
// grants all anyway). requireAssignedCard (PRIO-03/05) requires assignment — assign.
foreach (array_merge($topCards, [$doneHitCard, $doneNessCard, $completedCard, $noopCard]) as $cid) {
    $db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cid, $actorId]);
}

// Pre-existing prioritized count (state-relative assertions below).
$prePrioritized = count($svc->getList($asUser)['prioritized']);

// Prioritize the three top cards (service appends in call order).
$svc->prioritize($asUser, $topCards[0]);
$svc->prioritize($asUser, $topCards[1]);
$svc->prioritize($asUser, $topCards[2]);
$expectedTotal = $prePrioritized + 3;

// ---------------------------------------------------------------------------
echo "\n[1] digest shape + live recompute\n";
$d = $svc->digest($asUser, 50);
check('n=50 stays 50', $d['n'] === 50);
check('top has pre+3 items', count($d['top']) === $expectedTotal, 'got=' . count($d['top']) . ' expected=' . $expectedTotal);
check('top ends with our 3 fixture cards, in user order',
    array_map(fn($i) => $i['card_id'], array_slice($d['top'], -3)) === $topCards);
check('top item shape (card_id, card_title, board_title, state_marker, card_html)',
    isset($d['top'][0]['card_id'], $d['top'][0]['card_title'], $d['top'][0]['board_title'],
          $d['top'][0]['state_marker'], $d['top'][0]['card_html']));
check('deep link format /card.php?id=N',
    str_starts_with($d['top'][0]['card_html'] ?? '', '/card.php?id='));
check('state_marker non-empty string', is_string($d['top'][0]['state_marker'] ?? '') && $d['top'][0]['state_marker'] !== '');
check('window is yesterday 00:00:00 → 23:59:59 (server local TZ)',
    ($d['window']['since']  ?? '') === date('Y-m-d 00:00:00', strtotime('-1 day'))
    && ($d['window']['until'] ?? '') === date('Y-m-d 23:59:59', strtotime('-1 day')));

// Live recompute: deprioritize one fixture card → it disappears from top immediately.
$svc->deprioritize($asUser, $topCards[2]);
$dLive = $svc->digest($asUser, 50);
$liveIds = array_map(fn($i) => $i['card_id'], $dLive['top']);
check('deprioritized card immediately leaves the digest top (no cache)',
    !in_array($topCards[2], $liveIds, true) && in_array($topCards[0], $liveIds, true));
$svc->prioritize($asUser, $topCards[2]); // back (recompute live)

// ---------------------------------------------------------------------------
echo "\n[2] truncation + clamping\n";
$d1 = $svc->digest($asUser, 1);
check('n=1 → exactly 1 top item', count($d1['top']) === 1, 'count=' . count($d1['top']));
check('n=1 → first item is the user-order head (pre-existing or first fixture)',
    (int) $d1['top'][0]['card_id'] === (int) $svc->getList($asUser)['prioritized'][0]['card_id']);
check('clamp 0→1', \Shuffle\Service\PriorityService::clampDigestN(0) === 1);
check('clamp 5→5', \Shuffle\Service\PriorityService::clampDigestN(5) === 5);
check('clamp 999→50', \Shuffle\Service\PriorityService::clampDigestN(999) === 50);
check('clamp -5→1', \Shuffle\Service\PriorityService::clampDigestN(-5) === 1);
check('digest(n=0) clamped, does not throw', (new \ReflectionMethod($svc, 'digest'))->getName() === 'digest' && count($svc->digest($asUser, 0)['top']) === 1);

// ---------------------------------------------------------------------------
echo "\n[3] markdown contract\n";
$md = $svc->digestMarkdown($asUser, 50);
check('markdown is a string', is_string($md) && $md !== '');
check('top heading bold + rendered count', str_contains($md, '**My top ' . $expectedTotal . '**'), 'md=' . $md);
check('done heading bold + yesterday date', str_contains($md, '**Done yesterday (' . date('Y-m-d', strtotime('-1 day')) . ')**'), 'md=' . $md);
check('top item line: {marker} {title} — *{board}* — /card.php?id=N (plain URL)',
    preg_match('/^\S+ E2E-DIG-top-1 — \*' . preg_quote($boardTitle, '/') . '\* — \/card\.php\?id=' . $topCards[0] . '/m', $md) === 1, 'md=' . $md);
check('markdown ends with newline', str_ends_with($md, "\n"));

// Honest empty state: deny-all user (no boards) → both sections "(none)".
$svcDeny = new \Shuffle\Service\PriorityService($userPrio, $card, $lane, $board,
    new HeadlessAuthDeny($asUser), $actSvc, $lang);
$mdDeny = $svcDeny->digestMarkdown($asUser, 5);
check('no access: top section renders heading + (none)',
    str_contains($mdDeny, '**My top 0** — (none)'), 'md=' . $mdDeny);
check('no access: done section renders heading + (none)',
    str_contains($mdDeny, '**Done yesterday (' . date('Y-m-d', strtotime('-1 day')) . ')** — (none)'), 'md=' . $mdDeny);
check('no access: no card title leaked from our temp board',
    !str_contains($mdDeny, 'E2E-DIG-top-1'), 'md=' . $mdDeny);

// ---------------------------------------------------------------------------
echo "\n[4] Done yesterday from the card_activity log\n";

// Real moves happen NOW (today), outside the "yesterday" window — so the
// log rows carry a created_at inside the window, exactly as the service
// will read them (same read path; the move-hook write path is already
// covered by the ACTIVITY suites).
$yest = date('Y-m-d', strtotime('-1 day'));

$logAt = function (int $cardId, int $fromLaneId, string $fromTitle, int $toLaneId, string $toTitle, string $at) use ($db, $boardId, $actorId): void {
    $db->execute(
        'INSERT INTO card_activity (card_id, board_id, event, actor_id, payload_json, created_at)
         VALUES (?, ?, "card_moved", ?, ?, ?)',
        [$cardId, $boardId, $actorId,
         json_encode(['from_lane' => ['id' => $fromLaneId, 'title' => $fromTitle, 'icon' => null],
                      'to_lane'   => ['id' => $toLaneId, 'title' => $toTitle, 'icon' => null]], JSON_UNESCAPED_UNICODE),
         $at]
    );
};

// (a) Alpha → "Done", in-window                → LISTED
$logAt($doneHitCard,  $laneA, 'Alpha',     $laneD,  'Done',      $yest . ' 09:15:00');
// (b) Alpha → "Done-ness", in-window          → LISTED (shared \bdone\b matcher: a hyphen IS a word boundary)
$logAt($doneNessCard, $laneA, 'Alpha',     $laneDN, 'Done-ness', $yest . ' 10:30:00');
// (c) Alpha → "Completed", in-window          → NOT listed (true negative for the matcher)
$logAt($completedCard, $laneA, 'Alpha',    $laneC,  'Completed', $yest . ' 11:45:00');
// (d) "Done" → "Done — v2", in-window (no-op) → NOT listed (from-lane already Done)
$logAt($noopCard,     $laneD, 'Done',      $laneD2, 'Done — v2', $yest . ' 13:00:00');
// (e) doneHitCard ALREADY Done → "Done" again in-window → still counted ONCE
$logAt($doneHitCard,  $laneD, 'Done',      $laneD,  'Done',      $yest . ' 15:00:00');

$d = $svc->digest($asUser, 50);
$doneIds = array_map(fn($i) => $i['card_id'], $d['done_yesterday']);
check('Alpha→Done card listed',                in_array($doneHitCard, $doneIds, true), 'ids=' . json_encode($doneIds));
check('Alpha→Done-ness card listed (matcher)', in_array($doneNessCard, $doneIds, true), 'ids=' . json_encode($doneIds));
check('Alpha→Completed card NOT listed',        !in_array($completedCard, $doneIds, true));
check('Done→Done — v2 no-op NOT listed',        !in_array($noopCard, $doneIds, true));
check('doneHitCard counted exactly once',       count(array_keys($doneIds, $doneHitCard, true)) === 1, 'ids=' . json_encode($doneIds));
check('done section has exactly 2 entries',     count($d['done_yesterday']) === 2, 'count=' . count($d['done_yesterday']));

$first = $d['done_yesterday'][0] ?? [];
check('done item: card_title',                ($first['card_title'] ?? '') === 'E2E-DIG-hit-done');
check('done item: to_lane_title = Done',      ($first['to_lane_title'] ?? '') === 'Done');
check('done item: actor.name = acting user',  ($first['actor']['name'] ?? '') === $asUser['name']);
check('done item: created_at in yesterday window', stripos($first['created_at'] ?? '', $yest) === 0);
check('done item: deep link',                 str_starts_with($first['card_html'] ?? '', '/card.php?id=' . $doneHitCard));
$second = $d['done_yesterday'][1] ?? [];
check('second done item is the Done-ness card', (int) ($second['card_id'] ?? 0) === (int) $doneNessCard);

// Ordering: oldest first within the window.
check('done items oldest-first', ($first['created_at'] ?? '') <= ($second['created_at'] ?? ''));

$md = $svc->digestMarkdown($asUser, 50);
check('markdown ✅ line: {title} — *{board}* — {actor}',
    preg_match('/^✅ E2E-DIG-hit-done — \*' . preg_quote($boardTitle, '/') . '\* — ' . preg_quote($asUser['name'], '/') . '/m', $md) === 1, 'md=' . $md);
check('markdown lists Done-ness ✅ line', str_contains($md, '✅ E2E-DIG-hit-doneness'));
check('markdown excludes Completed / no-op rows',
    !str_contains($md, 'E2E-DIG-not-completed') && !str_contains($md, '✅ E2E-DIG-noop-done-done'));

// Out-of-window moves are excluded: a card moved to Done TODAY (now) must not
// appear even though the log row exists.
$logAt($completedCard, $laneA, 'Alpha', $laneD, 'Done', date('Y-m-d H:i:s'));
$dNow = $svc->digest($asUser, 50);
$nowIds = array_map(fn($i) => $i['card_id'], $dNow['done_yesterday']);
check('move-to-Done today (not yesterday) NOT listed', !in_array($completedCard, $nowIds, true), 'ids=' . json_encode($nowIds));

// ---------------------------------------------------------------------------
echo "\n[5] BOARD-04b — inaccessible board omitted, never an error\n";
$dDeny = $svcDeny->digest($asUser, 50);
check('deny: top empty', $dDeny['top'] === []);
check('deny: done_yesterday empty', $dDeny['done_yesterday'] === []);
check('deny: window still present', isset($dDeny['window']['since'], $dDeny['window']['until']));

// ---------------------------------------------------------------------------
echo "\n[6] activity service not wired → RuntimeException (→ 503 in controller)\n";
$svcNoAct = new \Shuffle\Service\PriorityService($userPrio, $card, $lane, $board, $authAll, null, $lang);
$threw = false;
try { $svcNoAct->digest($asUser, 5); } catch (\RuntimeException $e) { $threw = true; }
check('unwired digest() throws RuntimeException', $threw);
$mdThrew = false;
try { $svcNoAct->digestMarkdown($asUser, 5); } catch (\RuntimeException $e) { $mdThrew = true; }
check('unwired digestMarkdown() throws RuntimeException', $mdThrew);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('—', 50) . "\n";
if ($failures === 0) { echo "ALL $checks DIGEST CHECKS PASSED\n"; exit(0); }
echo "$failures of $checks checks FAILED\n";
exit(1);
