<?php
declare(strict_types=1);
/**
 * E2E check for BOARD-06d — archived boards must not appear in any user's
 * Personal Priority List (inbox or prioritized).
 *
 * Scenario (per SPECIFICATION.md §5.5):
 *   1. Create a fresh board + Inbox lane + an assigned card.
 *   2. Prioritize the card → it appears in `prioritized`.
 *   3. Archive the board → card must vanish from BOTH `prioritized` and `inbox`.
 *   4. Restore the board → card returns to `inbox` (live recompute);
 *      it is NOT auto-returned to `prioritized`.
 *   5. Cleanup: remove the board + its card; restore user_prio.
 *
 * All mutations run against the live DB as a headless admin (user_id passed
 * as argv[1], default 1). Uses BoardService::archiveBoard/restoreBoard — the
 * same code path the API hits via BoardController (which is role-gated but
 * the service does not re-check roles — that's the controller's job).
 *
 * The temp board is deleted at the end regardless of outcome (finally +
 * register_shutdown_function double-safety).
 *
 * Usage:  php tests/e2e-board-archive.php [user_id]   (default 1 = admin)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$asUserId = isset($argv[1]) ? (int) $argv[1] : 1;

// ---------------------------------------------------------------------------
// Headless Auth (same pattern as tests/e2e-priority.php)
// ---------------------------------------------------------------------------
class HeadlessAuth extends \Shuffle\Core\Auth
{
    private array $fixedUser;
    public function __construct(array $fixedUser) { $this->fixedUser = $fixedUser; }
    public function currentUser(): ?array { return $this->fixedUser; }
    public function requireAuth(): array { return $this->fixedUser; }
    public function requireRole(string $role): array { return $this->fixedUser; }
    public function canAccessBoard(int $boardId): bool { return $this->fixedUser['role'] === 'admin'; }
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
    else      { echo "  ok:   $name\n"; }
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
$asUser = $db->fetch('SELECT id, name, role, status, organization_id FROM users WHERE id = ?', [$asUserId]);
if ($asUser === null) {
    die("No user with id $asUserId\n");
}

$snapshotUserPrio = $db->fetchAll('SELECT * FROM user_prio');
$restoreBoardArchive = 0; // 0 = don't touch; 1 = we archived, must restore on cleanup
$cleanUp = function () use ($db, $asUser, $snapshotUserPrio, &$restoreBoardArchive): void {
    // Delete the temp board (cascades lanes, cards, card_assignments, user_prio)
    $tmp = $db->fetch("SELECT id FROM boards WHERE title LIKE 'E2E-BA-board-%'");
    if ($tmp !== null) {
        $tmpId = (int) $tmp['id'];
        // Remove user_prio rows pointing at cards on the temp board
        $db->execute('DELETE FROM user_prio WHERE card_id IN (
                SELECT c.id FROM cards c
                JOIN lanes l ON l.id = c.lane_id
                AND l.board_id = ?)', [$tmpId]);
        // Reset any board-archive flag we set
        if ($restoreBoardArchive) {
            $db->execute('UPDATE boards SET is_archived = 0 WHERE id = ?', [$tmpId]);
        }
        // Board model's delete cascades
        (new \Shuffle\Model\Board($db))->delete($tmpId);
    }
    // Safety: restore user_prio to pre-test row set
    $now = $db->fetchAll('SELECT * FROM user_prio');
    $keepIds = array_map(fn($s) => (int) $s['id'], $snapshotUserPrio);
    foreach ($now as $row) {
        if (!in_array((int) $row['id'], $keepIds, true)) {
            $db->execute('DELETE FROM user_prio WHERE id = ?', [(int) $row['id']]);
        }
    }
};
register_shutdown_function($cleanUp);

$auth    = new HeadlessAuth($asUser);
$card    = new \Shuffle\Model\Card($db);
$lane    = new \Shuffle\Model\Lane($db);
$board   = new \Shuffle\Model\Board($db);
$userPrio = new \Shuffle\Model\UserPrio($db);
// UserPrio injected so archiveBoard() runs the same code path as the API
// (www/v1/index.php wires it the same way) — BOARD-06d row cleanup.
$boardSvc = new \Shuffle\Service\BoardService($board, $lane, $card, $userPrio);
$prioSvc  = new \Shuffle\Service\PriorityService($userPrio, $card, $lane, $board, $auth);

echo "BOARD-06d E2E as user #{$asUser['id']} ({$asUser['name']}, {$asUser['role']})\n";

// ------------------------------------------------------------------
$timestamp = date('Ymd-His');
$tmpTitle  = 'E2E-BA-board-' . $timestamp;
$cardTitle = 'E2E-BA-card-' . $timestamp;

$boardId = $board->create([
    'title'            => $tmpTitle,
    'description'      => '',
    'visibility'       => 'private',
    'created_by'       => (int) $asUser['id'],
    'organization_ids' => [],
]);
$inboxLaneId = $lane->create(['board_id' => $boardId, 'title' => 'Inbox', 'position' => 1000]);
$inProgressLaneId = $lane->create(['board_id' => $boardId, 'title' => 'In Progress', 'position' => 2000]);
$cardId  = $card->create([
    'lane_id'     => $inProgressLaneId,
    'title'       => $cardTitle,
    'description' => '',
    'due_date'    => null,
    'created_by'  => (int) $asUser['id'],
]);
$db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cardId, (int) $asUser['id']]);

check('fixture: board created', $boardId > 0, 'boardId=' . $boardId);
check('fixture: card created + assigned', $cardId > 0, 'cardId=' . $cardId);

// ------------------------------------------------------------------
echo "\n[1] Prioritize the card → it appears in `prioritized`\n";
$before = $prioSvc->getList($asUser);
$beforeInbox = array_map(fn($i) => (int) $i['card_id'], $before['inbox']);
check('prior card is in inbox before prioritizing (sanity)',
    in_array((int) $cardId, $beforeInbox, true), 'ids=' . json_encode($beforeInbox));
$prioSvc->prioritize($asUser, $cardId);
$l = $prioSvc->getList($asUser);
$inPrio = array_map(fn($i) => (int) $i['card_id'], $l['prioritized']);
check('prioritized contains the card', in_array((int) $cardId, $inPrio, true), 'ids=' . json_encode($inPrio));
$inboxIds = array_map(fn($i) => (int) $i['card_id'], $l['inbox']);
check('inbox does NOT contain the prioritized card', !in_array((int) $cardId, $inboxIds, true));

// ------------------------------------------------------------------
echo "\n[2] Archive the board\n";
$boardSvc->archiveBoard($boardId);
$restoreBoardArchive = 1; // cleanup safety net
$boardRow = $board->findById($boardId);
check('board is_archived=1 after archiveBoard()', (int) ($boardRow['is_archived'] ?? 0) === 1,
    'row=' . json_encode($boardRow));

// ------------------------------------------------------------------
echo "\n[3] After archiving: card must vanish from BOTH lanes of the prio list\n";
$l = $prioSvc->getList($asUser);
$afterPrioIds = array_map(fn($i) => (int) $i['card_id'], $l['prioritized']);
$afterInboxIds = array_map(fn($i) => (int) $i['card_id'], $l['inbox']);
check('prioritized no longer contains the card (BOARD-06d)', !in_array((int) $cardId, $afterPrioIds, true),
    'ids=' . json_encode($afterPrioIds));
check('inbox no longer contains the card (BOARD-06d)', !in_array((int) $cardId, $afterInboxIds, true),
    'ids=' . json_encode($afterInboxIds));

// BOARD-06d: archiving is the "off the rack" — the user_prio row is cleared
// (BoardService::archiveBoard → UserPrio::removeForBoard) so that a later
// restore re-includes the card in the inbox only, never in the prioritized
// lane. Retaining the row would let the card silently re-enter the
// prioritized list on restore, which is the behaviour the spec forbids.
$stillInUserPrio = $userPrio->findByCardAndUser((int) $asUser['id'], (int) $cardId);
check('user_prio row is cleared on archive (restore-inbox-only semantics)', $stillInUserPrio === null);

// ------------------------------------------------------------------
echo "\n[4] Board is not in the default board list (includeArchived=false)\n";
$defaultList = $boardSvc->listBoards($asUser, false);
$ids = array_map(fn($b) => (int) $b['id'], $defaultList);
check('archived board NOT in the default listing', !in_array((int) $boardId, $ids, true),
    'ids=' . json_encode($ids));

$withArchived = $boardSvc->listBoards($asUser, true);
$ids2 = array_map(fn($b) => (int) $b['id'], $withArchived);
check('archived board IS in the ?include_archived=1 listing', in_array((int) $boardId, $ids2, true),
    'ids=' . json_encode($ids2));

// ------------------------------------------------------------------
echo "\n[5] Restore the board → card returns to inbox, NOT to prioritized\n";
$boardSvc->restoreBoard($boardId);
$restoreBoardArchive = 0; // cleanup: board is not archived anymore
$boardRow = $board->findById($boardId);
check('board is_archived=0 after restoreBoard()', (int) ($boardRow['is_archived'] ?? 0) === 0,
    'row=' . json_encode($boardRow));

$l = $prioSvc->getList($asUser);
$restoredInboxIds = array_map(fn($i) => (int) $i['card_id'], $l['inbox']);
$restoredPrioIds  = array_map(fn($i) => (int) $i['card_id'], $l['prioritized']);
check('inbox re-includes the card after restore (live recompute)',
    in_array((int) $cardId, $restoredInboxIds, true), 'ids=' . json_encode($restoredInboxIds));
// BOARD-06d: the user_prio row was removed on archive, so the card must NOT
// be back in the prioritized lane — the user has to re-prioritize it.
$stillThere = $userPrio->findByCardAndUser((int) $asUser['id'], (int) $cardId);
check('user_prio row was cleared on archive (not resurrected on restore)', $stillThere === null);
check('prioritized lane does NOT auto-restore the card (BOARD-06d)',
    !in_array((int) $cardId, $restoredPrioIds, true), 'ids=' . json_encode($restoredPrioIds));

// ------------------------------------------------------------------
echo "\n[6] Clean up — delete the fixture board and card, restore user_prio\n";
$cleanUp();

$boardStill = $db->fetch('SELECT id FROM boards WHERE id = ?', [(int) $boardId]);
check('fixture board deleted', $boardStill === null, 'row=' . json_encode($boardStill));
$leftoverPrio = $db->fetchAll('SELECT * FROM user_prio WHERE user_id = ?', [(int) $asUserId]);
$leftoverIds  = array_map(fn($r) => (int) $r['id'], $leftoverPrio);
$expectIds    = array_map(fn($s) => (int) $s['id'], $snapshotUserPrio);
sort($leftoverIds); sort($expectIds);
check('user_prio restored to pre-test row set', $leftoverIds === $expectIds,
    'leftover=' . json_encode($leftoverIds) . ' expected=' . json_encode($expectIds));

// ---------------------------------------------------------------------------
echo "\n-----------------------------------\n";
echo "checks: $checks, failures: $failures\n";
echo "status: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
exit($failures === 0 ? 0 : 1);
