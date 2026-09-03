<?php
declare(strict_types=1);
/**
 * Smoke test for the Labels backend (LABEL-01..03) against the live DB.
 * Self-cleaning — creates a scratch board + card + labels, exercises the
 * service, and removes everything it made.
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

// --- fixture: scratch board + lane + card ---
$bs = new \Shuffle\Service\BoardService(new \Shuffle\Model\Board($db),
                                       new \Shuffle\Model\Lane($db),
                                       new \Shuffle\Model\Card($db));
$board = $bs->createBoard(['title' => 'LABEL-SMOKE-' . time()], $admin, false);
$boardId = (int) $board['id'];
// board created with seedDefaultLanes=false → create a manual lane
$laneService = new \Shuffle\Service\LaneService(new \Shuffle\Model\Lane($db), new \Shuffle\Model\Board($db));
$lane = $laneService->createLane($boardId, ['title' => 'Smoke', 'description' => '', 'icon' => '']);
$laneId = (int) $lane['id'];
$cs = new \Shuffle\Service\CardService(new \Shuffle\Model\Card($db), new \Shuffle\Model\Board($db));
$card = $cs->createCard($boardId, $laneId, ['title' => 'Smoke card'], $admin);
$cardId = (int) $card['id'];

$labelService = new \Shuffle\Service\LabelService(
    new \Shuffle\Model\Label($db), new \Shuffle\Model\Board($db), new \Shuffle\Model\Card($db)
);

// --- PALETTE shape ---
check('PALETTE has 12 entries', count(\Shuffle\Service\LabelService::PALETTE) === 12);
check('PALETTE entries have hex+name',
    array_reduce(\Shuffle\Service\LabelService::PALETTE, function ($acc, $e) {
        return $acc && isset($e['hex']) && isset($e['name']);
    }, true));

// --- validation ---
try { $labelService->validateName(''); check('empty name rejected', false); }
catch (\InvalidArgumentException $e) { check('empty name rejected', true); }
try { $labelService->validateColor('F44336'); check('no-# color rejected', false); }
catch (\InvalidArgumentException $e) { check('no-# color rejected', true); }
try { $labelService->validateColor('#zzz'); check('non-hex rejected', false); }
catch (\InvalidArgumentException $e) { check('non-hex rejected', true); }
check('#F44336 accepted', $labelService->validateColor('#f44336') === '#F44336');
check('#000 accepted', $labelService->validateColor('#000000') === '#000000');

// --- create ---
$bug = $labelService->create($boardId, ['name' => 'Bug', 'color' => '#F44336']);
check('create returns id', is_int($bug['id']) && $bug['id'] > 0);
check('create card_count=0', (int) $bug['card_count'] === 0);
$feat = $labelService->create($boardId, ['name' => 'Feature', 'color' => '#2196F3']);

// --- duplicate name → RuntimeException ---
try { $labelService->create($boardId, ['name' => 'Bug', 'color' => '#000000']);
      check('duplicate name rejected', false); }
catch (\RuntimeException $e) { check('duplicate name rejected', true); }

// --- case-INsensitive uniqueness: 'bug' collides with 'Bug' → 409 ---
try { $labelService->create($boardId, ['name' => 'bug', 'color' => '#00FF00']);
      check('case-insensitive: lowercase "bug" is a duplicate of "Bug"', false); }
catch (\RuntimeException $e) { check('case-insensitive: lowercase "bug" is a duplicate of "Bug"', true); }

// --- list with card_count ---
$list = $labelService->listForBoard($boardId);
check('list has 2 labels', count($list) === 2);
$bugRow = null; $featRow = null;
foreach ($list as $r) {
    if ($r['name'] === 'Bug') $bugRow = $r;
    elseif ($r['name'] === 'Feature') $featRow = $r;
}
check('all names surfaced', $bugRow && $featRow);
check('card_count default 0', (int) $bugRow['card_count'] === 0);

// --- attach / detach on card ---
$labelService->attach($cardId, (int) $bugRow['id']);
$list = $labelService->listForBoard($boardId);
foreach ($list as $r) if ($r['name'] === 'Bug') check('card_count after attach = 1', (int)$r['card_count'] === 1);
$labelsFor = $labelService->labelsForCard($cardId);
check('labelsForCard returns 1', count($labelsFor) === 1);
check('labelsForCard is Bug', $labelsFor[0]['name'] === 'Bug');

// --- idempotent attach (re-attach is no-op, count stays 1) ---
$labelService->attach($cardId, (int) $bugRow['id']);
$list = $labelService->listForBoard($boardId);
foreach ($list as $r) if ($r['name'] === 'Bug') check('idempotent attach keeps count 1', (int)$r['card_count'] === 1);

// --- detach ---
$labelService->detach($cardId, (int) $bugRow['id']);
check('labelsForCard empty after detach', count($labelService->labelsForCard($cardId)) === 0);

// --- cross-board attach → 400 InvalidArgumentException ---
// Build a second scratch board for a cross-board label
$board2 = $bs->createBoard(['title' => 'LABEL-SMOKE2-' . time()], $admin, false);
$board2Id = (int) $board2['id'];
$otherLabel = $labelService->create($board2Id, ['name' => 'Cross', 'color' => '#FF0000']);
try { $labelService->attach($cardId, (int) $otherLabel['id']);
      check('cross-board attach rejected', false); }
catch (\InvalidArgumentException $e) { check('cross-board attach rejected', true); }

// --- rename (partial update) ---
$renamed = $labelService->update((int) $bugRow['id'], ['name' => 'Bugfix']);
check('rename works', $renamed['name'] === 'Bugfix');
// rename to existing name on same board → 409
try { $labelService->update((int) $renamed['id'], ['name' => 'Feature']);
      check('rename to duplicate rejected', false); }
catch (\RuntimeException $e) { check('rename to duplicate rejected', true); }

// --- re-color (partial) ---
$recolor = $labelService->update((int) $bugRow['id'], ['color' => '#00AA00']);
check('recolor works', strtoupper($recolor['color']) === '#00AA00');
check('recolor kept name', $recolor['name'] === 'Bugfix');

// --- delete a label — cascade clears card_labels ---
$labelService->attach($cardId, (int) $bugRow['id']);
$countBefore = (int) ($labelService->listForBoard($boardId)[0]['card_count']);
$labelService->delete((int) $bugRow['id']);
check('deleted label removed from list',
    count(array_filter($labelService->listForBoard($boardId), fn($r) => $r['name'] === 'Bugfix')) === 0);
check('card_labels row gone after delete', count($labelService->labelsForCard($cardId)) === 0);

// --- delete nonexistent → RuntimeException ---
try { $labelService->delete(99999999); check('delete nonexistent rejected', false); }
catch (\RuntimeException $e) { check('delete nonexistent rejected', true); }

// --- update nonexistent → RuntimeException ---
try { $labelService->update(99999999, ['name' => 'X']); check('update nonexistent rejected', false); }
catch (\RuntimeException $e) { check('update nonexistent rejected', true); }

// --- unionToCard (LABEL-03) ---
// Create a second card, attach two labels to it, then union to a third card.
$card2 = $cs->createCard($boardId, $laneId, ['title' => 'Card two'], $admin);
$card2Id = (int) $card2['id'];
$card3 = $cs->createCard($boardId, $laneId, ['title' => 'Card three'], $admin);
$card3Id = (int) $card3['id'];
// Attach two labels to card2 (both from board1)
$allLabels = $labelService->listForBoard($boardId);
$first  = $allLabels[0];
$second = null;
foreach ($allLabels as $r) if ($r['id'] !== $first['id']) { $second = $r; break; }
$labelService->attach($card2Id, (int) $first['id']);
if ($second) $labelService->attach($card2Id, (int) $second['id']);
$labelModel = new \Shuffle\Model\Label($db);
$unionCount = $labelModel->unionToCard($card2Id, $card3Id);
$labelsFor3 = $labelService->labelsForCard($card3Id);
check('union moved labels to card3', count($labelsFor3) === $unionCount);
// idempotent re-union is a no-op
$unionCount2 = $labelModel->unionToCard($card2Id, $card3Id);
check('idempotent union', count($labelService->labelsForCard($card3Id)) === count($labelsFor3));

// --- cleanup: delete scratch boards (FK CASCADE removes lanes, cards, labels) ---
$bs->deleteBoard($boardId);
$bs->deleteBoard($board2Id);
$leftover = $db->fetch('SELECT COUNT(*) c FROM boards WHERE id IN (?, ?)', [$boardId, $board2Id]);
check('cleanup: both boards gone', (int) $leftover['c'] === 0);
$leftLabels = $db->fetch('SELECT COUNT(*) c FROM labels l JOIN boards b ON b.id=l.board_id WHERE b.id IN (?, ?)', [$boardId, $board2Id]);
check('cleanup: no labels remain on deleted boards', (int) $leftLabels['c'] === 0);

echo "\n";
echo "$checks checks, $fails failures\n";
exit($fails ? 1 : 0);
