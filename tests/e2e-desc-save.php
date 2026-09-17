<?php
declare(strict_types=1);
/**
 * E2E check for CARD-14 Stage B (description-local Save, §5.21 staging).
 *
 * The new #cm-desc-save button issues a DESCRIPTION-ONLY PUT against
 * PUT /v1/cards/{id}. This suite pins the server contract that button
 * depends on (the client flow — dirty-tracking, return-to-Preview, no-op
 * short-circuit — is UI behavior covered by the HTTP E2E + the browser
 * walkthrough):
 *
 *   § 1. description-only update: description written, title + due_date
 *         UNCHANGED, a card_edited activity row with `description` as the
 *         single changed field, board version bumped
 *   § 2. no-op (same description): the row is re-written to the same value,
 *         NO activity row is added (logCardEdit early-returns on a non-change),
 *         but the board version IS still bumped — the server bumps on any
 *         received field; the zero-bump-on-noop guarantee is a CLIENT
 *         contract (saveDesc short-circuits an unchanged draft before the
 *         round-trip)
 *   § 3. clear description (empty string) → description NULL + activity row
 *   § 4. description_html on the returned record matches Markdown::render of
 *         the new description (the preview seed contract)
 *
 * Usage: php tests/e2e-desc-save.php
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
$boardModel   = new \Shuffle\Model\Board($db2);
$laneModel    = new \Shuffle\Model\Lane($db2);
$cardModel    = new \Shuffle\Model\Card($db2);
$userModel    = new \Shuffle\Model\User($db2);
$activityModel = new \Shuffle\Model\CardActivity($db2);
$actor        = ['id' => 1];

$cardService = new \Shuffle\Service\CardService($cardModel, $boardModel);
$activityService = new \Shuffle\Service\CardActivityService($activityModel, $cardModel, $laneModel, $userModel);
$cardService->setActivityService($activityService);

// ---------------------------------------------------------------------------
// Fixture: one board, one lane, one card with title + due date + description.
// Created via the DAO (not the service) so NO activity row auto-logs and the
// countForCard baseline is a clean 0.
// ---------------------------------------------------------------------------
$board = $boardModel->create(['title' => 'Mya E2E DescSave', 'visibility' => 'private', 'created_by' => 1]);
$lane  = $laneModel->create(['board_id' => $board, 'title' => 'Inbox', 'position' => 1000]);
$cardId = $cardModel->create([
    'lane_id' => $lane, 'title' => 'Desc save fixture', 'description' => '# old desc',
    'due_date' => '2026-12-31', 'created_by' => 1,
]);

$activityCount = static function (int $cid) use ($activityModel): int {
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
// § 1. description-only update
// ---------------------------------------------------------------------------
$countBefore = $activityCount($cardId);
$versionBefore = $version($board);

$after = $cardService->updateCard($cardId, ['description' => '# **new** desc'], $actor);

check('§1 description-only: description updated', $after['description'] === '# **new** desc');
check('§1 description-only: title UNCHANGED', $after['title'] === 'Desc save fixture');
check('§1 description-only: due_date UNCHANGED', $after['due_date'] === '2026-12-31');
check('§1 one card_edited activity row appended', $activityCount($cardId) === $countBefore + 1);
check('§1 board version bumped', $version($board) === $versionBefore + 1);
$last1 = $latestEdited($cardId);
check('§1 activity: single changed field = description',
    $last1 !== null && array_values($last1['detail']['fields_changed'] ?? []) === ['description']);

// ---------------------------------------------------------------------------
// § 2. no-op (same description): row re-written, no activity row, version +1
// ---------------------------------------------------------------------------
$countBefore2 = $activityCount($cardId);
$versionBefore2 = $version($board);

$noop = $cardService->updateCard($cardId, ['description' => '# **new** desc'], $actor);

check('§2 no-op: description unchanged', $noop['description'] === '# **new** desc');
check('§2 no-op: NO new activity row (non-change not logged)', $activityCount($cardId) === $countBefore2);
check('§2 no-op: board version IS bumped (server bumps on any received field; zero-bump-on-noop is a client contract)',
    $version($board) === $versionBefore2 + 1);

// ---------------------------------------------------------------------------
// § 3. clear description (empty string) → NULL + activity row
// ---------------------------------------------------------------------------
$cleared = $cardService->updateCard($cardId, ['description' => ''], $actor);
check('§3 clear: description emptied ("" — the service never coerces to NULL)', ($cleared['description'] ?? '') === '');
check('§3 clear: activity row appended', $activityCount($cardId) === $countBefore2 + 1);
$last3 = $latestEdited($cardId);
check('§3 clear: changed field = description',
    $last3 !== null && array_values($last3['detail']['fields_changed'] ?? []) === ['description']);

// ---------------------------------------------------------------------------
// § 4. description_html matches the shared Markdown pipeline
// ---------------------------------------------------------------------------
$markdown = "# Header\n\n- item with **bold**\n\n`inline code`";
$withHtml = $cardService->updateCard($cardId, ['description' => $markdown], $actor);
$expected = \Shuffle\Core\Markdown::render($markdown);
check('§4 description_html matches Markdown::render', ($withHtml['description_html'] ?? '') === $expected && $expected !== '');
check('§4 preview seed is non-empty rendered HTML', strpos($withHtml['description_html'] ?? '', '<li>') !== false);

// ---------------------------------------------------------------------------
// Cleanup (fixture is self-contained)
// ---------------------------------------------------------------------------
$cardService->deleteCard($cardId);
foreach ($laneModel->findByBoard($board) as $l) { $laneModel->delete((int)$l['id']); }
$boardModel->delete($board);

check('cleanup: card row gone', $cardModel->findById($cardId) === null);
check('cleanup: board row gone', $boardModel->findById($board) === null);

echo "\nRESULT: $checks checks, $failures failures\n";
exit($failures ? 1 : 0);
