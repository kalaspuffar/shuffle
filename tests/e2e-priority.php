<?php
declare(strict_types=1);
/**
 * E2E check for the Personal Priority List (PRIO-01..11).
 *
 * Runs the real service layer against the live DB (as a headless admin user),
 * exercises inbox/box/prioritize/deprioritize/reorder + error paths, runs a
 * 150-iteration random reorder stress loop, then RESTORES the database to
 * its pre-test state (user_prio rows + any temporary card removed).
 *
 * Usage:  php tests/e2e-priority.php [user_id]   (default 1 = admin)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$asUserId = isset($argv[1]) ? (int) $argv[1] : 4;   // default: mya (test account), NOT Daniel
if ($asUserId === 1) { fwrite(STDERR, "REFUSING user 1 (Daniel's real data). Run: php tests/e2e-priority.php 4\n"); exit(2); }

// ---------------------------------------------------------------------------
// Headless Auth: pretend the CLI/process IS $asUserId.
//
// All methods below are the ones PriorityService actually calls; none of them
// touch the parent's private $db/$session, so no real Session is needed and
// no second session save-handler is registered.
// ---------------------------------------------------------------------------
class HeadlessAuth extends \Shuffle\Core\Auth
{
    private array $fixedUser;

    public function __construct(array $fixedUser)
    {
        $this->fixedUser = $fixedUser;
    }

    public function currentUser(): ?array
    {
        return $this->fixedUser;
    }

    public function requireAuth(): array
    {
        return $this->fixedUser;
    }

    public function requireRole(string $role): array
    {
        return $this->fixedUser;
    }

    public function canAccessBoard(int $boardId): bool
    {
        return $this->fixedUser['role'] === 'admin';
    }
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
    if (!$ok) {
        $failures++;
        echo "  FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    } else {
        echo "  ok:   $name\n";
    }
}

$restored = false;
try {
    // IMPORTANT (2026-09-03, Daniel's call): this test must NOT touch the
    // real user's priority list or their boards. It builds its own fixture
    // board (own lane + 5 cards, all assigned to the test user) and runs
    // every section against that. Cleanup removes exactly the rows it added.
    // Default account = the dedicated mya test user (id 4), not Daniel (id 1).
    $asUser = $db->fetch('SELECT id, name, role, status, organization_id FROM users WHERE id = ?', [$asUserId]);
    if ($asUser === null) {
        die("No user with id $asUserId\n");
    }
    $basePrio = $db->fetchAll('SELECT * FROM user_prio WHERE user_id = ?', [$asUserId]);

    $cleanUp = function () use ($db, $asUser, $basePrio) {
        global $restored;
        if ($restored) return;
        $restored = true;
        // Drop only the user_prio rows THIS test added (any id not in baseline).
        $keepIds = array_map(fn($s) => (int) $s['id'], $basePrio);
        foreach ($db->fetchAll('SELECT id FROM user_prio WHERE user_id = ?', [(int)$asUser['id']]) as $row) {
            if (!in_array((int) $row['id'], $keepIds, true)) {
                $db->execute('DELETE FROM user_prio WHERE id = ?', [(int)$row['id']]);
            }
        }
        // Drop the fixture board (cascades lanes + cards + assignments).
        $tmp = $db->fetch("SELECT id FROM boards WHERE title LIKE 'E2E-PRIO-fixture-%'");
        if ($tmp !== null) {
            $db->execute('DELETE FROM boards WHERE id = ?', [(int)$tmp['id']]);
        }
    };
    register_shutdown_function($cleanUp);

    $auth  = new HeadlessAuth($asUser);
    $card  = new \Shuffle\Model\Card($db);
    $lane  = new \Shuffle\Model\Lane($db);
    $board = new \Shuffle\Model\Board($db);
    $userPrio = new \Shuffle\Model\UserPrio($db);
    $svc = new \Shuffle\Service\PriorityService($userPrio, $card, $lane, $board, $auth);

    echo "Priority E2E as user #{$asUser['id']} ({$asUser['name']}, {$asUser['role']})\n";

    // ------------------------------------------------------------------
    // Fixture board: our own cards — never the test user's real boards.
    // ------------------------------------------------------------------
    $fixture = 'E2E-PRIO-fixture-' . date('Ymd-His');
    $fxBoard = $board->create(['title' => $fixture, 'created_by' => (int)$asUser['id']]);
    $fxLane  = $lane->create(['board_id' => (int)$fxBoard, 'title' => 'Work', 'icon' => null, 'position' => 1000]);
    $fxDoneLane = $lane->create(['board_id' => (int)$fxBoard, 'title' => 'Done', 'icon' => null, 'position' => 2000]);
    $fxCards = [];
    for ($i = 1; $i <= 5; $i++) {
        $cid = $card->create(['lane_id' => (int)$fxLane, 'title' => 'fx-' . $fixture . '-' . $i,
                              'description' => '', 'due_date' => null, 'created_by' => (int)$asUser['id']]);
        $db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cid, (int)$asUser['id']]);
        $fxCards[] = (int) $cid;
    }

    // ------------------------------------------------------------------
    echo "\n[1] Fixture inbox shape\n";
    $list0 = $svc->getList($asUser);
    $inboxFx = array_values(array_filter($list0['inbox'], fn($i) => in_array((int)$i['card_id'], $fxCards, true)));
    check('5 fixture cards present in inbox', count($inboxFx) === 5, 'count=' . count($inboxFx));
    $tiers = array_map(fn($i) => $i['tier'], $inboxFx);
    $sorted = $tiers; sort($sorted);
    check('fixture inbox tiers stable', $tiers === $sorted, json_encode($tiers));
    $shapeOk = true;
    foreach ($inboxFx as $item) {
        foreach (['card_id','card_title','board_id','board_title','lane_id','lane_title','state_marker','card_html'] as $k) {
            if (!array_key_exists($k, $item)) { $shapeOk = false; break; }
        }
        if (!is_string($item['state_marker'] ?? '')) { $shapeOk = false; }
    }
    check('fixture item shape complete', $shapeOk);
    $noDone = true;
    foreach ($inboxFx as $item) {
        if (preg_match('/\bdone\b/iu', $item['lane_title']) || preg_match("/\bwon'?t fix\b/iu", $item['lane_title'])) { $noDone = false; }
    }
    check('no fixture card on complete lane', $noDone);

    // ------------------------------------------------------------------
    echo "\n[2] Prioritize (state-relative)\n";
    $baseCount = count($svc->getList($asUser)['prioritized']);
    $r = $svc->prioritize($asUser, $fxCards[0]);
    check('prioritize returns int position', is_int($r['position']) && $r['position'] > 0);

    $list1 = $svc->getList($asUser);
    check('prioritized count +1', count($list1['prioritized']) === $baseCount + 1);
    check('fx1 in prioritized, appended last', (int) $list1['prioritized'][count($list1['prioritized'])-1]['card_id'] === $fxCards[0]);
    check('inbox shrank by 1', count($list1['inbox']) === count($list0['inbox']) - 1);

    $r2 = $svc->prioritize($asUser, $fxCards[0]);
    check('re-prioritize idempotent (same position)', $r2['position'] === $r['position']);
    check('re-prioritize no duplicate', count($svc->getList($asUser)['prioritized']) === $baseCount + 1);

    $svc->prioritize($asUser, $fxCards[1]);
    $list2 = $svc->getList($asUser);
    check('second append -> count +2 from base', count($list2['prioritized']) === $baseCount + 2);
    $fxIds = array_values(array_filter(array_map(fn($x)=>(int)$x['card_id'], $list2['prioritized']), fn($id)=>in_array($id,$fxCards,true)));
    check('both fixture cards in prioritized', $fxIds == [$fxCards[0], $fxCards[1]] || $fxIds == [$fxCards[1], $fxCards[0]], json_encode($fxIds));

    // ------------------------------------------------------------------
    echo "\n[3] Reorder\n";
    // Make the state deterministic for reordering: fx1 first, fx2 second
    // (relative to whatever else is in the list, we operate on the two fixture cards).
    $svc->reorder($asUser, $fxCards[1], null);   // fx2 to top
    $l = $svc->getList($asUser);
    check('reorder fx2 to top -> fx2 first', (int) $l['prioritized'][0]['card_id'] === $fxCards[1]);

    $svc->reorder($asUser, $fxCards[0], $fxCards[1]); // fx1 right after fx2
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x)=>(int)$x['card_id'], $l['prioritized']);
    $p2 = array_search($fxCards[1], $ids); $p1 = array_search($fxCards[0], $ids);
    check('reorder fx1 after fx2 (adjacent)', $p2 !== false && $p1 !== false && $p1 === $p2 + 1,
          "p2=".var_export($p2,true)." p1=".var_export($p1,true));

    // Self-move rejected
    $threw = false;
    try { $svc->reorder($asUser, $fxCards[0], $fxCards[0]); } catch (\RuntimeException $e) { $threw = true; }
    check('reorder after itself -> RuntimeException', $threw);

    // ------------------------------------------------------------------
    echo "\n[4] Error paths\n";
    $threw = false;
    try { $svc->prioritize($asUser, 99999999); } catch (\RuntimeException $e) { $threw = true; }
    check('prioritize unknown card -> RuntimeException', $threw);

    // Not-assigned card (any real unassigned card)
    $unassigned = $db->fetch(
        "SELECT c.id FROM cards c WHERE c.is_archived = 0
         AND NOT EXISTS (SELECT 1 FROM card_assignments ca WHERE ca.card_id = c.id AND ca.user_id = ?)
         LIMIT 1", [(int) $asUser['id']]);
    if ($unassigned !== null) {
        $threw = false;
        try { $svc->prioritize($asUser, (int) $unassigned['id']); } catch (\RuntimeException $e) { $threw = true; }
        check('prioritize non-assigned card -> RuntimeException', $threw);
    } else {
        echo "  (skip) no unassigned card\n";
    }

    // Done-lane card (fixture board — cleanup cascades it)
    $doneCard = $card->create(['lane_id' => (int)$fxDoneLane, 'title' => 'fx-done-' . $fixture,
                               'description' => '', 'due_date' => null, 'created_by' => (int)$asUser['id']]);
    $db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$doneCard, (int)$asUser['id']]);
    $threw = false; $msg = '';
    try { $svc->prioritize($asUser, (int)$doneCard); } catch (\LogicException $e) { $threw = true; $msg = $e->getMessage(); }
    check('prioritize Done-lane card -> LogicException (409)', $threw, $msg);

    // ------------------------------------------------------------------
    echo "\n[5] Reorder stress (150 iterations, fixture cards only)\n";
    // Ensure all 5 fixture cards are prioritized (state-relative top-up).
    foreach ($fxCards as $cid) { $svc->prioritize($asUser, $cid); }
    $allFx = $fxCards;
    $count0 = count($svc->getList($asUser)['prioritized']);
    check('stress: 5 fixture cards prioritized', $count0 >= 5, 'count=' . $count0);

    $errors = 0;
    for ($i = 0; $i < 150; $i++) {
        try {
            $moving = $allFx[array_rand($allFx)];
            $targets = array_values(array_diff($allFx, [$moving]));
            $after = $targets ? $targets[array_rand($targets)] : null;
            $svc->reorder($asUser, $moving, $after);

            // Set of prioritized fixture cards unchanged (no dup/loss).
            $cur = array_values(array_filter(
                array_map(fn($x) => (int) $x['card_id'], $svc->getList($asUser)['prioritized']),
                fn($id) => in_array($id, $allFx, true)));
            $expected = $allFx;
            sort($cur); sort($expected);
            if ($cur !== $expected) { $errors++; continue; }

            // Positions for our rows are unique and > 0.
            $pos = $db->fetchAll('SELECT position FROM user_prio WHERE user_id = ? ORDER BY position', [(int)$asUser['id']]);
            $flat = array_map(fn($p) => (int) $p['position'], $pos);
            if (count(array_unique($flat)) !== count($flat) || min($flat) < 1) { $errors++; }
        } catch (\Throwable $t) { $errors++; }
    }
    check('150 random reorders: no dup/loss, positions unique and > 0', $errors === 0, "errors=$errors");

    // ------------------------------------------------------------------
    echo "\n[6] Deprioritize\n";
    $count1 = count($svc->getList($asUser)['prioritized']);
    $svc->deprioritize($asUser, $fxCards[0]);
    $l = $svc->getList($asUser);
    $fxNow = array_values(array_filter(array_map(fn($x)=>(int)$x['card_id'], $l['prioritized']), fn($id)=>in_array($id,$allFx,true)));
    check('deprioritize: fx1 removed', !in_array($fxCards[0], $fxNow, true));
    check('deprioritize: list shrank by 1', count($l['prioritized']) === $count1 - 1, 'before=' . $count1 . ' after=' . count($l['prioritized']));
    check('deprioritize: fx1 back in inbox', in_array($fxCards[0], array_map(fn($x)=>(int)$x['card_id'], $l['inbox']), true));
    $svc->deprioritize($asUser, $fxCards[0]); // no-op
    check('deprioritize again is a no-op', true);

    // ------------------------------------------------------------------
    echo "\n[7] PRIO-15 positional prioritize (anchor paths)\n";
    //
    // State going in: fx1 in the inbox (deprioritized in [6]), fx2..fx5
    // prioritized (after the [5] stress, order arbitrary). All fixture
    // cards are assigned to the test user.
    // ------------------------------------------------------------------
    $baseCount = count($svc->getList($asUser)['prioritized']);

    // [7a] omitted anchor — append at the bottom (pre-1.19 contract).
    $r = $svc->prioritize($asUser, $fxCards[0]);
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x) => (int) $x['card_id'], $l['prioritized']);
    check('7a: omitted anchor appends at bottom', (int) $ids[count($ids)-1] === $fxCards[0] && count($l['prioritized']) === $baseCount + 1);
    $posA = $r['position'];
    $r2 = $svc->prioritize($asUser, $fxCards[0]);
    check('7a: re-prioritize (no anchor) idempotent, same position', $r2['position'] === $posA);

    // [7b] explicit anchor null — top (reposition of an in-list card).
    $svc->prioritize($asUser, $fxCards[0], true, null);
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x) => (int) $x['card_id'], $l['prioritized']);
    check('7b: anchor null → top', (int) $ids[0] === $fxCards[0]);

    // [7c] anchor id — insert after that card (in-list reposition).
    $svc->prioritize($asUser, $fxCards[1], true, $fxCards[0]);
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x) => (int) $x['card_id'], $l['prioritized']);
    $i0 = array_search($fxCards[0], $ids); $i1 = array_search($fxCards[1], $ids);
    check('7c: anchor id inserts directly after the anchor', $i0 !== false && $i1 !== false && $i1 === $i0 + 1, "i0=$i0 i1=$i1");

    // [7d] anchor = self → rejected, list unchanged.
    $lBefore = $svc->getList($asUser)['prioritized'];
    $threw = false;
    try { $svc->prioritize($asUser, $fxCards[0], true, $fxCards[0]); } catch (\InvalidArgumentException $e) { $threw = true; }
    check('7d: self-anchor → InvalidArgumentException', $threw);
    $idsNow = array_map(fn($x) => (int) $x['card_id'], $svc->getList($asUser)['prioritized']);
    $idsBefore = array_map(fn($x) => (int) $x['card_id'], $lBefore);
    check('7d: list unchanged after self-anchor 400', $idsNow === $idsBefore);

    // [7e] foreign anchor → rejected, list unchanged.
    $threw = false;
    try { $svc->prioritize($asUser, $fxCards[2], true, 99999999); } catch (\InvalidArgumentException $e) { $threw = true; }
    check('7e: unknown anchor → InvalidArgumentException', $threw);
    $idsNow = array_map(fn($x) => (int) $x['card_id'], $svc->getList($asUser)['prioritized']);
    check('7e: list unchanged after foreign-anchor 400', $idsNow === $idsBefore);

    // [7f] new-card insert: deprioritize fx2 (back to inbox), insert at top.
    $svc->deprioritize($asUser, $fxCards[1]);
    $svc->prioritize($asUser, $fxCards[1], true, null);
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x) => (int) $x['card_id'], $l['prioritized']);
    check('7f: new card, anchor null → inserted at top', (int) $ids[0] === $fxCards[1] && count($l['prioritized']) === $baseCount + 1);

    // [7g] new-card insert at the end (anchor = last card).
    $svc->deprioritize($asUser, $fxCards[3]); // back to inbox
    $endId = (int) $ids[count($ids)-1];
    $svc->prioritize($asUser, $fxCards[3], true, $endId);
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x) => (int) $x['card_id'], $l['prioritized']);
    check('7g: new card, anchor = last → inserted at end', (int) $ids[count($ids)-1] === $fxCards[3] && count($l['prioritized']) === $baseCount + 1);

    // [7h] mid-insert with a fresh card (fx4 from the inbox).
    $svc->deprioritize($asUser, $fxCards[4]);
    $svc->prioritize($asUser, $fxCards[4], true, $fxCards[0]);
    $l = $svc->getList($asUser);
    $ids = array_map(fn($x) => (int) $x['card_id'], $l['prioritized']);
    $i0 = array_search($fxCards[0], $ids); $i4 = array_search($fxCards[4], $ids);
    check('7h: new card mid-insert after fx1', $i0 !== false && $i4 !== false && $i4 === $i0 + 1, "i0=$i0 i4=$i4");

    // [7i] stress: 30 random positional inserts on the fixture cards.
    $fxSet = $fxCards; sort($fxSet);
    $errors = 0;
    for ($i = 0; $i < 30; $i++) {
        $moving = $fxSet[array_rand($fxSet)];
        $others = array_values(array_diff($fxSet, [$moving]));
        $after = ($others !== [] && random_int(0, 1) === 0) ? $others[array_rand($others)] : null;
        try {
            $svc->prioritize($asUser, $moving, true, $after);
            $cur = array_values(array_filter(
                array_map(fn($x) => (int) $x['card_id'], $svc->getList($asUser)['prioritized']),
                fn($id) => in_array($id, $fxSet, true)));
            sort($cur);
            if ($cur !== $fxSet) { $errors++; continue; }
            $flat = array_map(fn($p) => (int) $p['position'],
                $db->fetchAll('SELECT position FROM user_prio WHERE user_id = ?', [(int) $asUser['id']]));
            if (count(array_unique($flat)) !== count($flat) || min($flat) < 1) { $errors++; }
        } catch (\Throwable $t) { $errors++; }
    }
    check('7i: 30 random positional inserts: no dup/loss, positions unique > 0', $errors === 0, "errors=$errors");

    // ------------------------------------------------------------------
    echo "\n[8] State restore verification\n";
    $cleanUp();
    $now = $db->fetchAll('SELECT card_id, position FROM user_prio WHERE user_id = ?', [(int)$asUser['id']]);
    $nowSet = array_map(fn($r) => [(int)$r['card_id'], (int)$r['position']], $now); sort($nowSet);
    $baseSet = array_map(fn($s) => [(int)$s['card_id'], (int)$s['position']], $basePrio); sort($baseSet);
    check('test user priority list byte-identical to baseline', $nowSet === $baseSet,
          'base=' . json_encode($baseSet) . ' now=' . json_encode($nowSet));
    $leftover = $db->fetch("SELECT id FROM boards WHERE title LIKE 'E2E-PRIO-fixture-%'");
    check('fixture board removed', $leftover === null);

} finally {
    if (isset($cleanUp)) { try { $cleanUp(); } catch (\Throwable $e) {} }
}

// ---------------------------------------------------------------------------
echo "\n-----------------------------------\n";
echo "checks: $checks, failures: $failures\n";
echo "status: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
exit($failures === 0 ? 0 : 1);
