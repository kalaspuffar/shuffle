<?php
declare(strict_types=1);
/**
 * E2E check for CARD-14 Stages C/D (inline title/due autosave, §5.21 staging).
 *
 * Stage D's client autosave (title only / due date only, field by field)
 * PUTs a SINGLE changed field against PUT /v1/cards/{id}. This suite pins
 * the server contract the autosave depends on:
 *
 *   § 1. title-only update: title written, due_date + description
 *         UNCHANGED, one card_edited row with `title` as the single changed
 *         field, board version bumped
 *   § 2. due-date-only update (set a new date): due_date written, title
 *         UNCHANGED, `due_date` the single changed field
 *   § 3. clear the due date (null): due_date cleared, still a changed
 *         field — distinct from an omitted field
 *   § 4. empty title is rejected (the client-side guard mirrors this):
 *         an empty/null title in the payload is a 422-class failure,
 *         the stored title is untouched
 *   § 5. no-op round-trip (same-value title re-sent): no activity row
 *         (logCardEdit skips non-changes), board version still bumps
 *         (server contract: any RECEIVED field bumps; zero-bump-on-noop
 *         is a CLIENT contract — the autosave's real-change guard)
 *
 * Same fixtures as tests/e2e-desc-save.php. Usage: php tests/e2e-autosave.php
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

$db2 = $db;
$boardModel    = new \Shuffle\Model\Board($db2);
$laneModel     = new \Shuffle\Model\Lane($db2);
$cardModel     = new \Shuffle\Model\Card($db2);
$userModel     = new \Shuffle\Model\User($db2);
$activityModel = new \Shuffle\Model\CardActivity($db2);
$actor         = ['id' => 1];

$cardService = new \Shuffle\Service\CardService($cardModel, $boardModel);
$activityService = new \Shuffle\Service\CardActivityService($activityModel, $cardModel, $laneModel, $userModel);
$cardService->setActivityService($activityService);

// Fixture: one card with title + due date + description (all three fields
// set, so "unchanged" is a meaningful assertion per § 1/§ 2/§ 3).
$board = $boardModel->create(['title' => 'Mya E2E Autosave', 'visibility' => 'private', 'created_by' => 1]);
$lane  = $laneModel->create(['board_id' => $board, 'title' => 'Inbox', 'position' => 1000]);
$cardId = $cardModel->create([
    'lane_id' => $lane, 'title' => 'Autosave fixture', 'description' => 'desc',
    'due_date' => '2026-12-31', 'created_by' => 1,
]);

$count = static function (int $cid) use ($activityModel): int {
    return (int) $activityModel->countForCard($cid);
};
$version = static function (int $bid) use ($boardModel): int {
    $b = $boardModel->findById($bid);
    return $b ? (int) $b['version'] : 0;
};
$latestEdited = static function (int $cid) use ($activityService): ?array {
    $feed = $activityService->feed($cid);
    foreach ($feed['items'] as $item) {
        if ($item['event'] === 'card_edited') return $item;
    }
    return null;
};

// ---------------------------------------------------------------------------
// § 1. title-only update
// ---------------------------------------------------------------------------
$countBefore = $count($cardId);
$versionBefore = $version($board);

$after = $cardService->updateCard($cardId, ['title' => 'New autosave title'], $actor);

check('§1 title-only: title updated', $after['title'] === 'New autosave title');
check('§1 title-only: due_date UNCHANGED', $after['due_date'] === '2026-12-31');
check('§1 title-only: description UNCHANGED', $after['description'] === 'desc');
check('§1 one card_edited activity row appended', $count($cardId) === $countBefore + 1);
check('§1 board version bumped', $version($board) === $versionBefore + 1);
$last1 = $latestEdited($cardId);
check('§1 activity: single changed field = title',
    $last1 !== null && array_values($last1['detail']['fields_changed'] ?? []) === ['title']);

// ---------------------------------------------------------------------------
// § 2. due-date-only update (new date)
// ---------------------------------------------------------------------------
$countBefore2 = $count($cardId);
$after2 = $cardService->updateCard($cardId, ['due_date' => '2027-01-15'], $actor);

check('§2 due-only: due_date updated', $after2['due_date'] === '2027-01-15');
check('§2 due-only: title UNCHANGED', $after2['title'] === 'New autosave title');
check('§2 one card_edited activity row appended', $count($cardId) === $countBefore2 + 1);
$last2 = $latestEdited($cardId);
check('§2 activity: single changed field = due_date',
    $last2 !== null && array_values($last2['detail']['fields_changed'] ?? []) === ['due_date']);

// ---------------------------------------------------------------------------
// § 3. clear the due date (null)
// ---------------------------------------------------------------------------
$countBefore3 = $count($cardId);
$after3 = $cardService->updateCard($cardId, ['due_date' => null], $actor);

check('§3 clear: due_date cleared to null', $after3['due_date'] === null);
check('§3 clear: title untouched', $after3['title'] === 'New autosave title');
check('§3 clear: one activity row appended', $count($cardId) === $countBefore3 + 1);
$last3 = $latestEdited($cardId);
check('§3 clear: changed field = due_date',
    $last3 !== null && array_values($last3['detail']['fields_changed'] ?? []) === ['due_date']);

// ---------------------------------------------------------------------------
// § 4. empty title rejected; stored title untouched
// ---------------------------------------------------------------------------
$countBefore4 = $count($cardId);
$rejected = false;
try {
    $cardService->updateCard($cardId, ['title' => '   '], $actor);
} catch (\Throwable $e) {
    $rejected = true;
}
$stored = $cardModel->findById($cardId);
check('§4 empty title: server rejects the update', $rejected);
check('§4 empty title: stored title untouched', ($stored['title'] ?? '') === 'New autosave title');
check('§4 empty title: no activity row for the rejected write', $count($cardId) === $countBefore4);

// ---------------------------------------------------------------------------
// § 5. no-op (same-value title re-sent): no row, version still bumps
// ---------------------------------------------------------------------------
$countBefore5 = $count($cardId);
$versionBefore5 = $version($board);
$noop = $cardService->updateCard($cardId, ['title' => 'New autosave title'], $actor);

check('§5 no-op: title unchanged', $noop['title'] === 'New autosave title');
check('§5 no-op: NO activity row (non-change not logged)', $count($cardId) === $countBefore5);
check('§5 no-op: board version IS bumped (zero-bump-on-noop is a client contract)',
    $version($board) === $versionBefore5 + 1);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
$cardService->deleteCard($cardId);
foreach ($laneModel->findByBoard($board) as $l) { $laneModel->delete((int)$l['id']); }
$boardModel->delete($board);

check('cleanup: card row gone', $cardModel->findById($cardId) === null);
check('cleanup: board row gone', $boardModel->findById($board) === null);

echo "\nRESULT: $checks checks, $failures failures\n";
exit($failures ? 1 : 0);
