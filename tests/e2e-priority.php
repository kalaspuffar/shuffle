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

$asUserId = isset($argv[1]) ? (int) $argv[1] : 1;

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
    // Snapshot for restore
    $snapshotUserPrio = $db->fetchAll('SELECT * FROM user_prio');

    $asUser = $db->fetch('SELECT id, name, role, status, organization_id FROM users WHERE id = ?', [$asUserId]);
    if ($asUser === null) {
        die("No user with id $asUserId\n");
    }

    $cleanUp = function () use ($db, $asUser, $snapshotUserPrio) {
        global $restored;
        if ($restored) return;
        $restored = true;
        // Remove any user_prio row we added (restore snapshot exactly)
        foreach ($db->fetchAll('SELECT id FROM user_prio') as $row) {
            $keep = false;
            foreach ($snapshotUserPrio as $s) {
                if ((int)$s['id'] === (int)$row['id']) { $keep = true; break; }
            }
            if (!$keep) {
                $db->execute('DELETE FROM user_prio WHERE id = ?', [(int)$row['id']]);
            }
        }
        // Remove temporary E2E card if one was created
        $db->execute("DELETE FROM cards WHERE title LIKE 'E2E-PRIO-temp-%'");
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
    echo "\n[1] Initial state\n";
    $list0 = $svc->getList($asUser);
    check('prioritized starts empty', $list0['prioritized'] === []);
    check('inbox non-empty', count($list0['inbox']) > 0, 'count=' . count($list0['inbox']));

    // Tier ordering: every tier-1 before every tier-2 before every tier-3
    $tiers = array_map(fn($i) => $i['tier'], $list0['inbox']);
    $sorted = $tiers; sort($sorted);
    check('inbox tiers monotonically non-decreasing', $tiers === $sorted, json_encode($tiers));

    // Every inbox card is non-archived, on an accessible board, not on a Done lane
    $allOk = true;
    foreach ($list0['inbox'] as $item) {
        if (preg_match('/\bdone\b/iu', $item['lane_title'])) { $allOk = false; }
    }
    check('no Done-lane cards in inbox', $allOk);

    // Every item has the required shape
    $shapeOk = true;
    foreach (array_merge($list0['inbox'], $list0['prioritized']) as $item) {
        foreach (['card_id','card_title','board_id','board_title','lane_id','lane_title','lane_icon','state_marker','due_date','card_html'] as $k) {
            if (!array_key_exists($k, $item)) { $shapeOk = false; break; }
        }
        if (!str_starts_with($item['card_html'] ?? '', '/card.php?id=')) { $shapeOk = false; }
        if (!is_string($item['state_marker'] ?? '')) { $shapeOk = false; }
    }
    check('item shape complete (all keys + card_html + state_marker)', $shapeOk);

    // ------------------------------------------------------------------
    echo "\n[2] Prioritize\n";
    $tier1 = array_values(array_filter($list0['inbox'], fn($i) => $i['tier'] === 1));
    $tier2 = array_values(array_filter($list0['inbox'], fn($i) => $i['tier'] === 2));
    $pickA = $tier1[0] ?? $tier2[0] ?? $list0['inbox'][0];
    $pickB = (!empty($tier2) && $tier2[0]['card_id'] !== $pickA['card_id']) ? $tier2[0]
            : (!empty($list0['inbox'][1]) ? $list0['inbox'][1] : null);

    $r = $svc->prioritize($asUser, $pickA['card_id']);
    check('prioritize returns int position', is_int($r['position']) && $r['position'] > 0);

    $list1 = $svc->getList($asUser);
    check('after prioritize: prioritized has 1', count($list1['prioritized']) === 1);
    check('after prioritize: prioritized card is pickA', (int)$list1['prioritized'][0]['card_id'] === (int)$pickA['card_id']);
    check('after prioritize: inbox shrank by 1', count($list1['inbox']) === count($list0['inbox']) - 1);

    // Idempotency
    $r2 = $svc->prioritize($asUser, $pickA['card_id']);
    check('re-prioritize is idempotent (same position)', $r2['position'] === $r['position']);
    check('re-prioritize does not duplicate', count($svc->getList($asUser)['prioritized']) === 1);

    if ($pickB !== null) {
        $svc->prioritize($asUser, $pickB['card_id']);
        $list2 = $svc->getList($asUser);
        check('re-prioritize: second card appended (pickB second)', count($list2['prioritized']) === 2
            && (int)$list2['prioritized'][1]['card_id'] === (int)$pickB['card_id']);
    }

    // ------------------------------------------------------------------
    echo "\n[3] Reorder\n";
    if ($pickB !== null) {
        // Move pickB before pickA (after=null = top)
        $svc->reorder($asUser, $pickB['card_id'], null);
        $l = $svc->getList($asUser);
        check('reorder to top', (int)$l['prioritized'][0]['card_id'] === (int)$pickB['card_id']);

        // Move pickA after pickB (adjacency regression: midpoint must not
        // collapse into the moved item's own old position)
        $svc->reorder($asUser, $pickA['card_id'], $pickB['card_id']);
        $l = $svc->getList($asUser);
        check('reorder pickA after pickB', (int)$l['prioritized'][1]['card_id'] === (int)$pickA['card_id']);

        // Move-to-end regression (semantics = insert AFTER target):
        // move the current first item just after the second → it should end last.
        $cur = $svc->getList($asUser);
        $c0 = (int) $cur['prioritized'][0]['card_id'];
        $c1 = (int) $cur['prioritized'][1]['card_id'];
        $svc->reorder($asUser, $c0, $c1);
        $after = $svc->getList($asUser);
        check('reorder first→after second puts it last', (int)$after['prioritized'][1]['card_id'] === $c0);

        // Self-move is rejected at the service level (controller also guards it).
        $threw = false;
        try { $svc->reorder($asUser, $c0, $c0); } catch (\RuntimeException $e) { $threw = true; }
        check('reorder after itself → RuntimeException', $threw);
    }

    // ------------------------------------------------------------------
    echo "\n[4] Error paths\n";
    // Unknown card
    $threw = false;
    try { $svc->prioritize($asUser, 99999999); } catch (\RuntimeException $e) { $threw = true; }
    check('prioritize unknown card → RuntimeException', $threw);

    // Card not assigned to me
    // Find a card that exists but is not assigned to this user (or any)
    $unassignedId = $db->fetch(
        "SELECT c.id FROM cards c
         WHERE c.is_archived = 0 AND NOT EXISTS (
             SELECT 1 FROM card_assignments ca WHERE ca.card_id = c.id AND ca.user_id = ?
         ) LIMIT 1", [$asUserId]
    );
    if ($unassignedId !== null) {
        $threw = false;
        try { $svc->prioritize($asUser, (int)$unassignedId['id']); } catch (\RuntimeException $e) { $threw = true; }
        check('prioritize card not assigned to me → RuntimeException', $threw);
    } else {
        echo "  (skip) no unassigned card found\n";
    }

    // Done-lane card
    $doneLaneRow = null;
    foreach ($db->fetchAll('SELECT id, title FROM lanes') as $l) {
        if (preg_match('/\bdone\b/iu', $l['title'])) { $doneLaneRow = $l; break; }
    }
    if ($doneLaneRow !== null) {
        // Create a temporary card on that lane, assign to user, expect 409, then restore
        $tmpTitle = 'E2E-PRIO-temp-' . bin2hex(random_bytes(4));
        $tmpLane = $doneLaneRow;
        $tmp = $card->create([
            'lane_id'     => (int)$tmpLane['id'],
            'title'       => $tmpTitle,
            'description' => '',
            'due_date'    => null,
            'created_by'  => (int)$asUserId,
        ]);
        $db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$tmp, (int)$asUserId]);

        $threw = false;
        $msg = '';
        try { $svc->prioritize($asUser, $tmp); }
        catch (\LogicException $e) { $threw = true; $msg = $e->getMessage(); }
        check('prioritize Done-lane card → LogicException (409)', $threw, $msg);

        // Cleanup: remove the temp card + its assignment
        $db->execute('DELETE FROM card_assignments WHERE card_id = ?', [$tmp]);
        // Use Card model delete if available, else raw
        $hasDelete = method_exists($card, 'delete');
        if ($hasDelete) { $card->delete($tmp); }
        else { $db->execute('DELETE FROM cards WHERE id = ?', [$tmp]); }

        // Confirm the temp card is gone
        $stillThere = $db->fetch('SELECT id FROM cards WHERE id = ?', [$tmp]);
        check('temp Done-lane card restored (deleted)', $stillThere === null);
    } else {
        echo "  (skip) no Done lane found\n";
    }

    // ------------------------------------------------------------------
    echo "\n[5] Reorder stress (150 iterations)\n";
    // Ensure we have at least 3 items in the list for a meaningful shuffle
    $l = $svc->getList($asUser);
    $prioritizedIds = array_map(fn($i) => (int)$i['card_id'], $l['prioritized']);
    $inboxIds = array_map(fn($i) => (int)$i['card_id'], $l['inbox']);
    // Top-up: prioritize up to 5 inbox items
    $topUp = 0;
    while (count($prioritizedIds) < 5 && $inboxIds) {
        $id = array_pop($inboxIds);
        if (in_array($id, $prioritizedIds, true)) continue;
        $svc->prioritize($asUser, $id);
        $prioritizedIds[] = $id;
        $topUp++;
    }
    check('stress: have >=3 items to shuffle', count($prioritizedIds) >= 3, 'count=' . count($prioritizedIds));

    $errors = 0;
    for ($i = 0; $i < 150; $i++) {
        try {
            $moving = $prioritizedIds[array_rand($prioritizedIds)];
            $targetIds = array_diff($prioritizedIds, [$moving]);
            $after = count($targetIds) ? $targetIds[array_rand($targetIds)] : null;
            $svc->reorder($asUser, $moving, $after);

            // Invariant: the service-returned list still contains exactly the
            // same set of card IDs (no dup, no loss).
            $cur = array_map(fn($x) => (int)$x['card_id'], $svc->getList($asUser)['prioritized']);
            sort($cur);
            $expected = $prioritizedIds; sort($expected);
            if ($cur !== $expected) { $errors++; continue; }

            // Invariant: no duplicate positions, all > 0
            $pos = $db->fetchAll('SELECT position FROM user_prio WHERE user_id = ?', [(int)$asUserId]);
            $flat = array_map(fn($p) => (int)$p['position'], $pos);
            sort($flat);
            $expectedFlat = $flat; sort($expectedFlat);
            if (count(array_unique($flat)) !== count($flat)) { $errors++; continue; }
            if (min($flat) < 1) { $errors++; continue; }
        } catch (\Throwable $t) {
            $errors++;
        }
    }
    check('stress: 150 random reorders with no dup/loss/negative-position', $errors === 0, "errors=$errors");

    // ------------------------------------------------------------------
    echo "\n[6] Deprioritize\n";
    if ($prioritizedIds) {
        $target = array_pop($prioritizedIds);
        $svc->deprioritize($asUser, $target);
        $l = $svc->getList($asUser);
        $got = array_map(fn($x) => (int)$x['card_id'], $l['prioritized']);
        check('deprioritize: card removed from prioritized', !in_array($target, $got, true), 'lost=' . $target);
        check('deprioritize: card appears in inbox again', in_array($target, array_map(fn($x)=>(int)$x['card_id'], $l['inbox']), true));

        // Idempotent no-op on non-member
        $svc->deprioritize($asUser, $target);
        check('deprioritize again is a no-op', true);
    }

    // ------------------------------------------------------------------
    echo "\n[7] State restore verification\n";
    $cleanUp(); // run cleanup twice is safe (idempotent register_shutdown_function)
    $now = $db->fetchAll('SELECT * FROM user_prio');
    $countNow = count($now);
    $countBefore = count($snapshotUserPrio);
    check('user_prio row count restored to pre-test level', $countNow === $countBefore, "before=$countBefore after=$countNow");

    $leftover = $db->fetch("SELECT id FROM cards WHERE title LIKE 'E2E-PRIO-temp-%'");
    check('no temp E2E cards remain', $leftover === null);

} finally {
    // Ensure cleanup runs even on fatal
    if (isset($cleanUp)) { try { $cleanUp(); } catch (\Throwable $e) {} }
}

// ---------------------------------------------------------------------------
echo "\n-----------------------------------\n";
echo "checks: $checks, failures: $failures\n";
echo "status: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
exit($failures === 0 ? 0 : 1);
