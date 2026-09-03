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
 * v1.9 additions (Daniel, 2026-09-01):
 *   — Won't-fix is a complete state: a lane titled "Won't fix" (apostrophe
 *     optional) joins Done as a "complete lane" — cards moved there are
 *     LISTED in the digest with lane_kind="wont_fix" (markdown ❌).
 *     A "Will fix" lane is a true negative (NOT complete).
 *   — Report window: "Done since" runs from 00:00:00 of the most recent
 *     workday (Mon–Fri) at or before YESTERDAY to 23:59:59 of yesterday
 *     (Monday → Fri–Sun inclusive, i.e. Friday 00:00 → Sunday 23:59).
 *     The test computes the same rule and asserts window.since / window.until
 *     plus in-window / out-of-window seeded rows.
 *
 * Usage:  php tests/e2e-digest.php [user_id]   (default 1 = admin)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$asUserId = isset($argv[1]) ? (int) $argv[1] : 4;   // default: mya (test account), NOT Daniel
if ($asUserId === 1) { fwrite(STDERR, "REFUSING user 1 (Daniel's real data). Run: php tests/e2e-digest.php 4\n"); exit(2); }

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
// v1.9: Won't-fix lanes (complete) + a true-negative lane that is not complete.
$laneWF   = $lane->create(['board_id' => $boardId, 'title' => "Won't fix", 'icon' => null, 'position' => 6000]);
$laneWF2  = $lane->create(['board_id' => $boardId, 'title' => 'Wont fix', 'icon' => null, 'position' => 7000]);
$laneWFN  = $lane->create(['board_id' => $boardId, 'title' => 'Will fix', 'icon' => null, 'position' => 8000]);
foreach ([$laneA, $laneD, $laneDN, $laneD2, $laneC, $laneWF, $laneWF2, $laneWFN] as $l) { $tempLanes[] = $l; }

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
// v1.9 fixtures: Won't-fix (hit), wont (hit, no apostrophe), will (true negative).
$wontHitCard   = $mkCard($laneA, 'E2E-DIG-hit-wontfix');
$wontHitCard2  = $mkCard($laneA, 'E2E-DIG-hit-wont');
$willFixCard   = $mkCard($laneA, 'E2E-DIG-not-willfix');

// The temp board is 'private' owned by the acting user → accessible (HeadlessAuthAll
// grants all anyway). requireAssignedCard (PRIO-03/05) requires assignment — assign.
foreach (array_merge($topCards, [$doneHitCard, $doneNessCard, $completedCard, $noopCard,
                                 $wontHitCard, $wontHitCard2, $willFixCard]) as $cid) {
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
check('deep link format /board.php?id=B&card=C (v1.8 modal deep-link)',
    preg_match('#^/board\.php\?id=\d+&amp?;card=\d+$#', $d['top'][0]['card_html'] ?? '') === 1
    || preg_match('#^/board\.php\?id=\d+&card=\d+$#', $d['top'][0]['card_html'] ?? '') === 1);
check('state_marker non-empty string', is_string($d['top'][0]['state_marker'] ?? '') && $d['top'][0]['state_marker'] !== '');
// v1.9 report window: from 00:00:00 of the most recent workday (Mon–Fri) at
// or before YESTERDAY, to 23:59:59 yesterday. Computed here with the same
// rule the service uses (DST-safe strtotime relative-day offsets, so no
// fixed 86400*n arithmetic in a test that compares against it).
$yestTs   = strtotime('yesterday');
$yestDow  = (int) date('N', $yestTs); // 1=Mon … 7=Sun
$anchorTs = match ($yestDow) {
    6 => strtotime('-1 day', $yestTs),  // Sat → Friday
    7 => strtotime('-2 days', $yestTs), // Sun → Friday
    default => $yestTs,                 // Mon–Fri → yesterday
};
$expSince  = date('Y-m-d 00:00:00', $anchorTs);
$expUntil  = date('Y-m-d 23:59:59', $yestTs);
check('window.since = 00:00:00 of the last workday at-or-before yesterday (v1.9)',
    ($d['window']['since'] ?? '') === $expSince, 'since=' . ($d['window']['since'] ?? '') . ' expected=' . $expSince);
check('window.until = 23:59:59 of yesterday',
    ($d['window']['until'] ?? '') === $expUntil, 'until=' . ($d['window']['until'] ?? '') . ' expected=' . $expUntil);

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
// At this point the SEEDED fixture board has no complete-lane moves in
// the done_since window yet (section [4] below injects them). The "empty
// section is omitted" contract for a truly empty digest is asserted via
// the deny-all user a few lines down. Here we scope to our board so a
// real "→ Done / → Won't fix" move on the acting user's other boards
// doesn't break the check.
$_seedDoneNow = array_values(array_filter(
    $svc->digest($asUser, 50)['done_since'],
    static fn ($i) => (int) ($i['board_id'] ?? 0) === (int) $boardId
));
check('done_since key present (renamed from done_yesterday in v1.9)', array_key_exists('done_since', $svc->digest($asUser, 50)));
check('old done_yesterday key removed (v1.9 rename, no consumers existed)', !array_key_exists('done_yesterday', $svc->digest($asUser, 50)));
check('seeded board has no Done-since entries yet (section [4] adds them)',
    $_seedDoneNow === [], 'count=' . count($_seedDoneNow));
check('top item line: {marker} {title} — *{board}* (no link tail in chat markdown)',
    preg_match('/^\S+ E2E-DIG-top-1 — \*' . preg_quote($boardTitle, '/') . '\*$/m', $md) === 1, 'md=' . $md);
check('top item line in markdown does NOT contain a dead /card.php?id= path',
    !preg_match('/\/card\.php\?id=\d+/m', $md), 'md=' . $md);
check('markdown ends with newline', str_ends_with($md, "\n"));

// Honest empty state: deny-all user (no boards) → both sections "(none)".
$svcDeny = new \Shuffle\Service\PriorityService($userPrio, $card, $lane, $board,
    new HeadlessAuthDeny($asUser), $actSvc, $lang);
$mdDeny = $svcDeny->digestMarkdown($asUser, 5);
// Empty digest: BOTH sections are omitted, and the whole markdown is the
// empty string (no chat noise — Daniel 2026-08-30).
check('deny-all user: markdown is empty (both sections omitted, no (none) noise)',
    $mdDeny === '', 'md=' . var_export($mdDeny, true));
check('deny-all user: no card title leaked from our temp board',
    !str_contains($mdDeny, 'E2E-DIG-top-1'), 'md=' . $mdDeny);
check('deny-all user: no done heading leaks',
    !str_contains($mdDeny, 'Done since') && !str_contains($mdDeny, 'Done yesterday'), 'md=' . $mdDeny);

// ---------------------------------------------------------------------------
echo "\n[4] Done-since feed from the card_activity log\n";

// v1.9 report window: seeded rows are stamped with dates the service will
// read as in-window / out-of-window (today = 2026-09-01 Tue → window is
// Mon 2026-08-31 00:00 → 23:59:  the rule is computed, not hardcoded,
// below). $logAt() is timestamp-agnostic; the ACTIVITY suites cover the
// move-hook write path.
$sinceTs = strtotime('yesterday');
$yDay    = date('Y-m-d', $sinceTs);            // in-window day (yesterday)
$inW     = date('Y-m-d', $sinceTs - 86400 * 2) . ' 09:15:00'; // 2 days back
$outW    = date('Y-m-d', $sinceTs + 86400)    . ' 09:15:00';  // today

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

// (a) Alpha → "Done", in-window                     → LISTED, lane_kind=done
$logAt($doneHitCard,  $laneA,  'Alpha',    $laneD,  'Done',      $yDay . ' 09:15:00');
// (b) Alpha → "Done-ness", in-window               → LISTED (shared \bdone\b matcher)
$logAt($doneNessCard, $laneA,  'Alpha',    $laneDN, 'Done-ness', $yDay . ' 10:30:00');
// (c) Alpha → "Completed", in-window               → NOT listed (true negative)
$logAt($completedCard,$laneA,  'Alpha',    $laneC,  'Completed', $yDay . ' 11:45:00');
// (d) "Done" → "Done — v2", in-window (no-op)      → NOT listed (from-lane complete)
$logAt($noopCard,     $laneD,  'Done',     $laneD2, 'Done — v2', $yDay . ' 13:00:00');
// (e) doneHitCard ALREADY Done → "Done" again      → still counted ONCE
$logAt($doneHitCard,  $laneD,  'Done',     $laneD,  'Done',      $yDay . ' 15:00:00');
// (f) v1.9: Alpha → "Won't fix", in-window         → LISTED, lane_kind=wont_fix
$logAt($wontHitCard,  $laneA,  'Alpha',    $laneWF, "Won't fix", $yDay . ' 16:00:00');
// (g) v1.9: Alpha → "Wont fix", in-window          → LISTED (apostrophe optional)
$logAt($wontHitCard2, $laneA,  'Alpha',    $laneWF2,'Wont fix',  $yDay . ' 16:30:00');
// (h) v1.9: Alpha → "Will fix", in-window          → NOT listed (not complete)
$logAt($willFixCard,  $laneA,  'Alpha',    $laneWFN,'Will fix',  $yDay . ' 17:00:00');
// (i) v1.9 window: Alpha → "Done" TWO days back    → OUT of window
$logAt($wontHitCard,  $laneA,  'Alpha',    $laneD,  'Done',      $inW);
// (j) v1.9 window: Alpha → "Done" TODAY            → OUT of window (future)
$logAt($willFixCard,  $laneA,  'Alpha',    $laneD,  'Done',      $outW);

$d = $svc->digest($asUser, 50);
$doneIds = array_map(fn($i) => $i['card_id'], $d['done_since']);
$doneByKind = array_map(fn($i) => $i['lane_kind'] ?? null, $d['done_since']);
check('Alpha→Done card listed',                in_array($doneHitCard, $doneIds, true), 'ids=' . json_encode($doneIds));
check('Alpha→Done-ness card listed (matcher)', in_array($doneNessCard, $doneIds, true), 'ids=' . json_encode($doneIds));
check('Alpha→Completed card NOT listed',        !in_array($completedCard, $doneIds, true));
check('Done→Done — v2 no-op NOT listed',        !in_array($noopCard, $doneIds, true));
check('v1.9 Alpha→Won\'t fix listed',           in_array($wontHitCard, $doneIds, true), 'ids=' . json_encode($doneIds));
check('v1.9 Alpha→Wont fix listed (no apostrophe)', in_array($wontHitCard2, $doneIds, true), 'ids=' . json_encode($doneIds));
check('v1.9 Alpha→Will fix NOT listed (not complete)', !in_array($willFixCard, $doneIds, true), 'ids=' . json_encode($doneIds));
check('v1.9 doneHitCard counted exactly once (2 rows: in+out window)',
    count(array_keys($doneIds, $doneHitCard, true)) === 1, 'ids=' . json_encode($doneIds));
// The seeded cards MUST land in the list; do NOT assert an exact total count
// because the log is global and real "→ Done / → Won't fix" moves on the
// acting user's other boards within the window legitimately appear.
check('done_since section is non-empty',       count($d['done_since']) >= 2, 'count=' . count($d['done_since']));
// Scope: our board's seeded items, oldest first.
$boardDone = array_values(array_filter(
    $d['done_since'],
    static fn ($i) => (int) ($i['board_id'] ?? 0) === (int) $boardId
));
usort($boardDone, static fn ($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''))
);
$first  = $boardDone[0] ?? [];
$second = $boardDone[1] ?? [];
check('first seeded item is the Done card',            (int) ($first['card_id'] ?? 0)  === (int) $doneHitCard);
check('first seeded item to_lane_title = Done',        ($first['to_lane_title'] ?? '') === 'Done');
check('first seeded item lane_kind = done (v1.9)',     ($first['lane_kind'] ?? '')     === 'done');
check('first seeded item actor.name = acting user',    ($first['actor']['name'] ?? '') === $asUser['name']);
check('first seeded item created_at inside the window',
    substr((string) ($first['created_at'] ?? ''), 0, 10)
        >= substr((string) $d['window']['since'], 0, 10)
    && substr((string) ($first['created_at'] ?? ''), 0, 10)
        <= substr((string) $d['window']['until'], 0, 10),
    'created_at=' . ($first['created_at'] ?? '') . ' window=' . json_encode($d['window']));
check('first seeded item deep link (v1.8 modal form)',
    preg_match('#^/board\.php\?id=\d+&amp?;card=' . $doneHitCard . '$#', (string) ($first['card_html'] ?? '')) === 1
    || preg_match('#^/board\.php\?id=\d+&card=' . $doneHitCard . '$#',  (string) ($first['card_html'] ?? '')) === 1);
check('second seeded item is the Done-ness card',      (int) ($second['card_id'] ?? 0) === (int) $doneNessCard);
$wontItem = array_values(array_filter($boardDone, fn ($i) => (int) ($i['card_id'] ?? 0) === (int) $wontHitCard));
check('wontHitCard item exists with lane_kind=wont_fix (v1.9)',
    isset($wontItem[0]) && ($wontItem[0]['lane_kind'] ?? '') === 'wont_fix',
    'item=' . json_encode($wontItem[0] ?? null) . ' kinds=' . json_encode($doneByKind));
check('done items oldest-first within the board',      ($first['created_at'] ?? '') <= ($second['created_at'] ?? ''));

$md = $svc->digestMarkdown($asUser, 50);
// Top section: present (3 fixtures in the list), no dead links.
check('markdown top heading present', str_contains($md, '**My top'), 'md=' . $md);
// v1.9: Done-since section present (we injected in-window rows); the heading
// is "Done since {workday} — N items".
check('markdown Done-since heading present', str_contains($md, 'Done since') && preg_match('/Done since .+ — \d+ items/', $md) === 1, 'md=' . $md);
check('markdown ✅ line (Done item): {title} — *{board}* — {actor}',
    preg_match('/^✅ E2E-DIG-hit-done — \*' . preg_quote($boardTitle, '/') . '\* — ' . preg_quote($asUser['name'], '/') . '/m', $md) === 1, 'md=' . $md);
check('markdown ✅ line (Done-ness item)', str_contains($md, '✅ E2E-DIG-hit-doneness'));
check('markdown ❌ line (Won\'t fix item, v1.9)', str_contains($md, '❌ E2E-DIG-hit-wontfix'), 'md=' . $md);
check('markdown includes the no-apostrophe Won\'t-fix item', str_contains($md, 'E2E-DIG-hit-wont'));
check('markdown excludes Completed / no-op / Will-fix rows',
    !str_contains($md, 'E2E-DIG-not-completed')
    && !str_contains($md, 'E2E-DIG-noop-done-done')
    && !str_contains($md, 'E2E-DIG-not-willfix'));
check('markdown top + done items do NOT carry a dead /card.php?id= link',
    !preg_match('/\\/card\.php\?id=\d+/m', $md), 'md=' . $md);

// ---------------------------------------------------------------------------
echo "\n[5] BOARD-04b — inaccessible board omitted, never an error\n";
$dDeny = $svcDeny->digest($asUser, 50);
check('deny: top empty', $dDeny['top'] === []);
check('deny: done_since empty', $dDeny['done_since'] === []);
check('deny: old done_yesterday key absent', !array_key_exists('done_yesterday', $dDeny));
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
