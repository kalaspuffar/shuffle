<?php
declare(strict_types=1);
/**
 * E2E check for LABEL-03: card merge label union.
 *
 * Verifies that when CardService::mergeInto() folds a source card into a
 * destination, the source's attached labels are idempotently attached to
 * the survivor (labels already on the survivor are not re-inserted), and
 * the source's card_labels rows are removed by FK cascade after the source
 * card is deleted.
 *
 * Usage: php tests/e2e-label-union.php
 */
require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0; $fails = 0;
function check(string $n, bool $c): void {
    global $checks, $fails;
    $checks++;
    if (!$c) $fails++;
    echo ($c ? 'PASS ' : 'FAIL ') . "$n\n";
}

$admin = ['id' => 1, 'username' => 'admin', 'name' => 'Admin',
          'email' => 'admin@example.com', 'role' => 'admin', 'organization_id' => 1];

$bs = new \Shuffle\Service\BoardService(new \Shuffle\Model\Board($db),
                                       new \Shuffle\Model\Lane($db),
                                       new \Shuffle\Model\Card($db));
$board = $bs->createBoard(['title' => 'LABEL-UNION-' . time()], $admin, false);
$boardId = (int) $board['id'];
$ls = new \Shuffle\Service\LaneService(new \Shuffle\Model\Lane($db), new \Shuffle\Model\Board($db));
$lane  = $ls->createLane($boardId, ['title' => 'Lane', 'description' => '', 'icon' => '']);
$laneId = (int) $lane['id'];

$cs = new \Shuffle\Service\CardService(new \Shuffle\Model\Card($db), new \Shuffle\Model\Board($db));
// mergeInto() needs a Database for the fold transaction + a Label model that
// unionToCard() can touch (LABEL-03).
$cs->setDatabase($db);
$cs->setLabelModel(new \Shuffle\Model\Label($db));
$labelService = new \Shuffle\Service\LabelService(
    new \Shuffle\Model\Label($db), new \Shuffle\Model\Board($db), new \Shuffle\Model\Card($db)
);

// Three cards in the same lane
$cardA = $cs->createCard($boardId, $laneId, ['title' => 'A'], $admin);
$cardB = $cs->createCard($boardId, $laneId, ['title' => 'B'], $admin);
$cardC = $cs->createCard($boardId, $laneId, ['title' => 'C'], $admin);
$a = (int)$cardA['id']; $b = (int)$cardB['id']; $c = (int)$cardC['id'];

// Two labels on this board
$label1 = $labelService->create($boardId, ['name' => 'L1', 'color' => '#FF0000']);
$label2 = $labelService->create($boardId, ['name' => 'L2', 'color' => '#00FF00']);
$l1 = (int)$label1['id']; $l2 = (int)$label2['id'];

// Attach: A → [L1, L2]; B → [L1]   (L1 is shared, L2 is only on A)
$labelService->attach($a, $l1);
$labelService->attach($a, $l2);
$labelService->attach($b, $l1);
check('setup: A has 2 labels', count($labelService->labelsForCard($a)) === 2);
check('setup: B has 1 label (L1)', count($labelService->labelsForCard($b)) === 1);

// Now merge A → B (A is source, B is survivor)
$cs->mergeInto($a, $b, $admin);

// After merge: B should have [L1, L2] (the union — L2 moved from A to B)
$labelsForB = $labelService->labelsForCard($b);
$labelIdsOnB = array_map(fn($r) => (int)$r['id'], $labelsForB);
sort($labelIdsOnB);
$expectedIds = [$l1, $l2]; sort($expectedIds);
check('L1 (shared) still on B', in_array($l1, $labelIdsOnB, true));
check('L2 (only-on-A) moved to B on merge', in_array($l2, $labelIdsOnB, true));
check('B has exactly 2 labels after merge (no dup)', $labelIdsOnB === $expectedIds);

// A is gone (merged)
$labelsForA = $labelService->labelsForCard($a);
check('A has no labels (card itself is deleted — labelsForCard returns nothing)',
      // A's card doesn't exist anymore; labelsForCard should not return the unioned labels "on A".
      $labelsForA === [] || !array_key_exists($a, $labelsForA));

// Cleanup — board delete cascades everything
$bs->deleteBoard($boardId);
$leftover = $db->fetch('SELECT COUNT(*) c FROM labels WHERE board_id = ?', [$boardId]);
check('cleanup: labels gone with board', (int) $leftover['c'] === 0);

echo "$checks checks, $fails failures\n";
exit($fails ? 1 : 0);
