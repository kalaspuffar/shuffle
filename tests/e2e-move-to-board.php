<?php
declare(strict_types=1);
/**
 * E2E check for Card Move Between Boards (CARD-26, §5.18).
 *
 * Verifies against the live DB:
 *   § 1. Re-homing: the card KEEPS its id; assignees/comments/checklists/
 *         attachments/user_prio all survive (they key on the card id)
 *   § 2. Placement: the card lands at the BOTTOM of the destination lane
 *   § 3. Labels: matched by name onto the destination board's set
 *         (case-insensitive); unmatched dropped — never copied
 *   § 4. Activity: a card_moved_board row lands on the CARD with
 *         from_board/to_board/from_lane/to_lane snapshots (to_lane is
 *         projected; to_board is omitted from the feed detail)
 *   § 5. Both boards get a version bump
 *   § 6. Rejections: same-board / missing board_id / lane not on the
 *         destination board / nonexistent card
 *
 * Test-safety invariant (Daniel 2026-09-03): never runs as user 1 —
 * actor = user 4 (mya), fixtures fully deleted at the end.
 *
 * Usage: php tests/e2e-move-to-board.php
 */
require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0;
$failures = 0;

function check(string $name, bool $cond): void
{
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  $name\n";
}

$actor = ['id' => 4];   // mya — NEVER user 1 (test-safety invariant)

$db2 = $db;
$boardModel   = new \Shuffle\Model\Board($db2);
$laneModel    = new \Shuffle\Model\Lane($db2);
$cardModel    = new \Shuffle\Model\Card($db2);
$commentModel = new \Shuffle\Model\Comment($db2);
$checklistModel = new \Shuffle\Model\Checklist($db2);
$attachmentModel = new \Shuffle\Model\Attachment($db2);
$labelModel = new \Shuffle\Model\Label($db2);
$userModelForLog = new \Shuffle\Model\User($db2);
$activityModel  = new \Shuffle\Model\CardActivity($db2);

// ---------------------------------------------------------------------------
// Fixture: a dedicated second user (assignee/comment/priority roles).
// Never a real account (Daniel/mya) — created here, deleted in cleanup.
// ---------------------------------------------------------------------------
$fixtureUserModel = new \Shuffle\Model\User($db2);
$fxNameM = 'e2e-move-' . substr(bin2hex(random_bytes(4)), 0, 8);
$fixtureUserIdM = $fixtureUserModel->create([
    'username'      => $fxNameM,
    'password_hash' => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
    'name'          => 'Move Fixture',
    'email'         => $fxNameM . '@example.test',
    'organization_id' => 1,
    'role'          => 'member',
    'status'        => 'active',
]);

// ---------------------------------------------------------------------------
// Fixtures: source board (Misc-like) + destination board with 2 lanes +
// a label on each board (one matching by name, one not)
// ---------------------------------------------------------------------------
$srcBoard = $boardModel->create(['title' => 'E2E Move SRC', 'visibility' => 'private', 'created_by' => 4]);
$srcLane  = $laneModel->create(['board_id' => $srcBoard, 'title' => 'Inbox', 'position' => 1000]);

$dstBoard = $boardModel->create(['title' => 'E2E Move DST', 'visibility' => 'private', 'created_by' => 4]);
$dstLaneA = $laneModel->create(['board_id' => $dstBoard, 'title' => 'Inbox', 'position' => 1000]);
$dstLaneB = $laneModel->create(['board_id' => $dstBoard, 'title' => 'Backlog', 'position' => 2000]);

// Existing card in dstLaneB (the moved card must land BELOW it)
$existingCard = $cardModel->create(['lane_id' => $dstLaneB, 'title' => 'DST existing', 'created_by' => 4]);

// Labels: SRC 'Bug' + 'Misc'; DST 'bug' (case-insensitive match for 'Bug')
// + 'Deploy' (no match for 'Misc') — plus 'Misc' on DST too for a second match.
$srcBug  = $labelModel->create(['board_id' => $srcBoard, 'name' => 'Bug', 'color' => '#FF0000']);
$srcMisc = $labelModel->create(['board_id' => $srcBoard, 'name' => 'Misc', 'color' => '#00FF00']);
$dstBug  = $labelModel->create(['board_id' => $dstBoard, 'name' => 'bug', 'color' => '#0000FF']);
$dstMisc = $labelModel->create(['board_id' => $dstBoard, 'name' => 'MISC', 'color' => '#000000']);

$movedCard = $cardModel->create(['lane_id' => $srcLane, 'title' => 'MOVING CARD', 'created_by' => 4]);
$labelModel->attach($movedCard, $srcBug);
$labelModel->attach($movedCard, $srcMisc);

// Assignees: fixture user + user 4 (mya)
$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$movedCard, $fixtureUserIdM]);
$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$movedCard, 4]);

// Comment + checklist + attachment keyed on the card id (must all survive)
$commentModel->create(['card_id' => $movedCard, 'user_id' => $fixtureUserIdM, 'body' => 'move e2e comment']);
$checklistModel->create(['card_id' => $movedCard, 'title' => 'move e2e checklist']);
$attachmentModel->create(['card_id' => $movedCard, 'user_id' => 4, 'file_name' => 'm.txt', 'file_size' => 5, 's3_key' => 'move-e2e/m-' . $movedCard . '.txt', 'mime_type' => 'text/plain']);

// user_prio entries for user 4 and the fixture user (a move must NOT clear them —
// re-homing keeps the id, unlike CARD-13 merge semantics)
$db2->execute('INSERT INTO user_prio (user_id, card_id, position) VALUES (?, ?, 1000)', [4, $movedCard]);
$db2->execute('INSERT INTO user_prio (user_id, card_id, position) VALUES (?, ?, 1000)', [$fixtureUserIdM, $movedCard]);

// ---------------------------------------------------------------------------
// Wire the service (mirrors www/v1/index.php)
// ---------------------------------------------------------------------------
$cardService = new \Shuffle\Service\CardService($cardModel, $boardModel);
$cardService->setLabelModel($labelModel);
$cardService->setDatabase($db2);
$cardService->setLaneModel($laneModel);
$cardService->setCommentModel($commentModel);
$cardService->setChecklistModel($checklistModel);
$cardService->setAttachmentModel($attachmentModel);
$activityService = new \Shuffle\Service\CardActivityService($activityModel, $cardModel, $laneModel, $userModelForLog);
$cardService->setActivityService($activityService);

// ---------------------------------------------------------------------------
// § 1. Rejection cases (before the move)
// ---------------------------------------------------------------------------
try {
    $cardService->moveToBoard($movedCard, $srcBoard, null, $actor);
    check('reject: same-board destination → 400', false);
} catch (\InvalidArgumentException $e) {
    check('reject: same-board destination → 400', true);
} catch (\Throwable $e) {
    check('reject: same-board destination → 400', false);
}

try {
    $cardService->moveToBoard($movedCard, 0, null, $actor);
    check('reject: missing board_id → 400', false);
} catch (\InvalidArgumentException $e) {
    check('reject: missing board_id → 400', true);
} catch (\Throwable $e) {
    check('reject: missing board_id → 400', false);
}

try {
    // Lane $srcLane belongs to the SRC board, not $dstBoard.
    $cardService->moveToBoard($movedCard, $dstBoard, $srcLane, $actor);
    check('reject: lane_id not on destination board → 400', false);
} catch (\InvalidArgumentException $e) {
    check('reject: lane_id not on destination board → 400', true);
} catch (\Throwable $e) {
    check('reject: lane_id not on destination board → 400', false);
}

try {
    $cardService->moveToBoard(99999999, $dstBoard, null, $actor);
    check('reject: nonexistent card → 404', false);
} catch (\RuntimeException $e) {
    check('reject: nonexistent card → 404', true);
} catch (\Throwable $e) {
    check('reject: nonexistent card → 404', false);
}

// ---------------------------------------------------------------------------
// § 2. THE MOVE — to $dstLaneB (explicit lane; the card must land BELOW
//      the existing card there)
// ---------------------------------------------------------------------------
$srcVersionBefore = $boardModel->getVersion($srcBoard);
$dstVersionBefore = $boardModel->getVersion($dstBoard);
$cardIdBefore = $movedCard;

$movedCardRow = $cardService->moveToBoard($cardIdBefore, $dstBoard, $dstLaneB, $actor);

// § 1. Identity preserved
check('re-home: same card id returned', (int) ($movedCardRow['id'] ?? 0) === $cardIdBefore);
check('re-home: card row still exists (not deleted)', $cardModel->findById($cardIdBefore) !== null);
$onBoard = $cardModel->getBoardId($cardIdBefore);
check('re-home: card now on the destination board', (int) $onBoard === (int) $dstBoard);

// § 2. Placement: bottom of $dstLaneB (below $existingCard)
$laneB = $cardModel->findByLane($dstLaneB, true);
check('placement: lane B now has 2 cards (existing + moved)', count($laneB) === 2);
if (count($laneB) === 2) {
    check('placement: moved card is LAST (bottom) in the lane',
        (int) $laneB[count($laneB) - 1]['id'] === $cardIdBefore);
} else {
    check('placement: moved card is LAST (bottom) in the lane', false);
}

// § 1. Content preserved (all key on the card id)
$assigned = array_map(static fn ($u) => (int) $u['id'], $cardModel->getAssignedUsers($cardIdBefore));
sort($assigned);
$expectedAssigned = [4, $fixtureUserIdM];
sort($expectedAssigned);
check('survives: assignees [fx,mya] intact', $assigned === $expectedAssigned);
check('survives: comment intact', count($commentModel->findByCard($cardIdBefore)) === 1);
check('survives: checklist intact', count($checklistModel->findByCard($cardIdBefore)) === 1);
check('survives: attachment intact', count($attachmentModel->findByCard($cardIdBefore)) === 1);
foreach ([4, $fixtureUserIdM] as $uid) {
    check('survives: user_prio preserved for user ' . $uid,
        $db2->fetch('SELECT id FROM user_prio WHERE user_id=? AND card_id=?', [$uid, $cardIdBefore]) !== null);
}

// § 3. Labels: 'Bug' → dst board's 'bug'; 'Misc' → dst board's 'MISC';
//      the SRC label rows themselves are no longer attached (card left that
//      board); no NEW label rows were created on DST.
$cardLabelIds = $labelModel->labelIdsForCard($cardIdBefore);
sort($cardLabelIds);
check('labels: card now attached to exactly the 2 matching DST labels',
    $cardLabelIds === array_map('intval', [$dstBug, $dstMisc]) && $cardLabelIds[0] === (int) $dstBug,
);
$dstLabelCount = count($labelModel->findByBoard($dstBoard));
check('labels: no new label rows created on DST (still 2)', $dstLabelCount === 2);
$srcStillHasIts = count($labelModel->findByBoard($srcBoard));
check('labels: SRC label rows untouched on SRC board (still 2)', $srcStillHasIts === 2);

// § 4. Activity row on the card
$actRow = $db2->fetch(
    'SELECT event, board_id, payload_json FROM card_activity
     WHERE card_id = ? AND event = ? ORDER BY id DESC LIMIT 1',
    [$cardIdBefore, 'card_moved_board']
);
check('CARD-26: card_moved_board row exists on the card', $actRow !== null);
if ($actRow !== null) {
    $payload = json_decode($actRow['payload_json'], true);
    check('CARD-26: payload from_board + to_board snapshotted',
        (int) ($payload['from_board']['id'] ?? 0) === (int) $srcBoard
        && (int) ($payload['to_board']['id'] ?? 0) === (int) $dstBoard
        && ($payload['to_board']['title'] ?? '') === 'E2E Move DST');
    check('CARD-26: payload from_lane + to_lane snapshotted',
        (int) ($payload['from_lane']['id'] ?? 0) === (int) $srcLane
        && (int) ($payload['to_lane']['id'] ?? 0) === (int) $dstLaneB);
    // Feed projection: to_board omitted, the other three present.
    $feed = $activityService->feed($cardIdBefore, 5);
    $row = null;
    foreach ($feed['items'] as $r) { if ($r['event'] === 'card_moved_board') { $row = $r; break; } }
    check('CARD-26: feed projection has from_board/from_lane/to_lane',
        $row !== null
        && array_key_exists('from_board', $row['detail'] ?? [])
        && array_key_exists('from_lane', $row['detail'] ?? [])
        && array_key_exists('to_lane', $row['detail'] ?? []));
    check('CARD-26: feed projection omits to_board',
        $row !== null && !array_key_exists('to_board', $row['detail'] ?? []));
}

// § 5. Both boards bumped
check('version: source board bumped', $boardModel->getVersion($srcBoard) > $srcVersionBefore);
check('version: destination board bumped', $boardModel->getVersion($dstBoard) > $dstVersionBefore);

// ---------------------------------------------------------------------------
// § 3. Move back (default lane = first lane of SRC board) + cleanup
// ---------------------------------------------------------------------------
$back = $cardService->moveToBoard($cardIdBefore, $srcBoard, null, $actor);
check('move back: default lane = source board first lane',
    (int) ($cardModel->findById($cardIdBefore)['lane_id'] ?? 0) === (int) $srcLane);

$boardServiceCleanup = new \Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);
$boardServiceCleanup->deleteBoard($dstBoard);
$boardServiceCleanup->deleteBoard($srcBoard);
$leftovers = $db2->fetch(
    'SELECT COUNT(*) AS c FROM boards WHERE id IN (?, ?)',
    [$srcBoard, $dstBoard]
);
check('cleanup: both fixture boards gone', (int) $leftovers['c'] === 0);
$leftoverCards = $db2->fetch('SELECT COUNT(*) AS c FROM cards WHERE id = ?', [$cardIdBefore]);
check('cleanup: moved card cascade-deleted with its board', (int) $leftoverCards['c'] === 0);

// Fixture user
$fixtureUserModel->delete($fixtureUserIdM);
check('cleanup: fixture user gone', $fixtureUserModel->findById($fixtureUserIdM) === null);

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
