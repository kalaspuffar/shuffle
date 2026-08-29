<?php
declare(strict_types=1);
/**
 * E2E check for Board Delete UI support (BOARD-06a/06b).
 *
 * Verifies against the live DB:
 *  - Board::countCardsByBoard() batch counts (incl. archived cards, empty list)
 *  - BoardService::listBoards() attaches card_count to every row
 *  - A fixture board is created fully (lanes+cards) and fully deleted,
 *    verifying the DB cascade (lanes, cards, card assignments) clears.
 *
 * Usage: php tests/e2e-board-delete.php
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0;
$failures = 0;

function ok(string $name, bool $cond): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  $name\n";
}

$user = ['id' => 1, 'username' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.com',
         'role' => 'admin', 'organization_id' => 1];

$boardModel = new \Shuffle\Model\Board($db);
$laneModel  = new \Shuffle\Model\Lane($db);
$cardModel  = new \Shuffle\Model\Card($db);
$boardService = new \Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);

// ---- 1. Fixture: board A with 2 lanes, 3 active cards + 1 archived; board B empty
$boardA = $boardModel->create(['title' => 'Mya E2E del A', 'visibility' => 'private', 'created_by' => 1]);
$lane1  = $laneModel->create(['board_id' => $boardA, 'title' => 'Inbox', 'position' => 1000]);
$lane2  = $laneModel->create(['board_id' => $boardA, 'title' => 'Done',  'position' => 2000]);
$c1 = $cardModel->create(['lane_id' => $lane1, 'title' => 'Card one', 'created_by' => 1]);
$c2 = $cardModel->create(['lane_id' => $lane1, 'title' => 'Card two', 'created_by' => 1]);
$c3 = $cardModel->create(['lane_id' => $lane2, 'title' => 'Card three', 'created_by' => 1]);
$cardModel->archive($c3); // archived card must count for blast-radius

$boardB = $boardModel->create(['title' => 'Mya E2E del B', 'visibility' => 'private', 'created_by' => 1]);
$laneB  = $laneModel->create(['board_id' => $boardB, 'title' => 'Inbox', 'position' => 1000]);

// ---- 2. countCardsByBoard()
$counts = $boardModel->countCardsByBoard([$boardA, $boardB]);
ok('countCardsByBoard: board A counts 3 cards incl archived', ($counts[$boardA] ?? -1) === 3);
ok('countCardsByBoard: board B counts 0', ($counts[$boardB] ?? -1) === 0);
ok('countCardsByBoard: empty input => empty map', $boardModel->countCardsByBoard([]) === []);

// ---- 3. listBoards attaches card_count
$boards = $boardService->listBoards($user, true);
$byId = [];
foreach ($boards as $b) { $byId[(int)$b['id']] = $b; }
ok('listBoards: every row has card_count key',
    (bool) array_filter($boards, fn($b) => !array_key_exists('card_count', $b)) === false);
ok('listBoards: board A card_count = 3', ($byId[$boardA]['card_count'] ?? -1) === 3);
ok('listBoards: board B card_count = 0', ($byId[$boardB]['card_count'] ?? -1) === 0);

// ---- 4. Delete board A + verify cascade
$boardService->deleteBoard($boardA);

$leftBoard = $boardModel->findById($boardA);
ok('deleteBoard: board row gone', $leftBoard === null);
$leftLanes  = $db->fetchAll('SELECT id FROM lanes WHERE board_id = ?', [$boardA]);
$leftCards  = $db->fetchAll('SELECT id FROM cards WHERE id IN (' . implode(',', [$c1,$c2,$c3]) . ')');
$leftPrio   = $db->fetchAll('SELECT id FROM user_prio WHERE card_id IN (' . implode(',', [$c1,$c2,$c3]) . ')');
ok('deleteBoard: lanes cascade-deleted', count($leftLanes) === 0);
ok('deleteBoard: cards cascade-deleted', count($leftCards) === 0);
ok('deleteBoard: user_prio refs cascade-deleted', count($leftPrio) === 0);

// ---- 5. Cleanup board B
$boardService->deleteBoard($boardB);
ok('cleanup: board B gone', $boardModel->findById($boardB) === null);
$leftLanesB = $db->fetchAll('SELECT id FROM lanes WHERE board_id = ?', [$boardB]);
ok('cleanup: board B lanes gone', count($leftLanesB) === 0);

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
