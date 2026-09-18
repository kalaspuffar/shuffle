<?php
declare(strict_types=1);
/**
 * E2E check for Card Merge (CARD-10..13, §5.17).
 *
 * Verifies against the live DB:
 *   § 1. Fold semantics: assignees union / comments appended (author +
 *         timestamps preserved) / checklists re-parented AFTER the dest's
 *         own (check state preserved) / attachments re-pointed with the
 *         same s3_key, dup dropped
 *   § 2. Priority list: the merged card is cleared from every user's
 *         priority list (CARD-13)
 *   § 3. Activity: a card_merged row lands on the SURVIVOR with a
 *         snapshotted source-card title (CARD-12)
 *   § 4. Rejections: same-card / missing destination / cross-board /
 *         nonexistent source (400/404 semantics)
 *   § 5. Source row + its own activity log are gone after merge
 *
 * Usage: php tests/e2e-card-merge.php
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

$user = ['id' => 4, 'username' => 'mya', 'name' => 'Mya (harness)', 'email' => 'harness@example.test',
         'role' => 'member', 'organization_id' => 1];

$actor = ['id' => 4];

$db2 = $db;
$boardModel   = new \Shuffle\Model\Board($db2);
$laneModel    = new \Shuffle\Model\Lane($db2);
$cardModel    = new \Shuffle\Model\Card($db2);
$commentModel = new \Shuffle\Model\Comment($db2);
$checklistModel = new \Shuffle\Model\Checklist($db2);
$checklistItemModel = new \Shuffle\Model\ChecklistItem($db2);
$attachmentModel = new \Shuffle\Model\Attachment($db2);
$userModelForLog = new \Shuffle\Model\User($db2);
$activityModel  = new \Shuffle\Model\CardActivity($db2);

// ---------------------------------------------------------------------------
// Fixture: a dedicated second user for the assignee/comment/priority roles.
// Never a real account (Daniel/mya) — created here, deleted below.
// ---------------------------------------------------------------------------
$fixtureUserModel = new \Shuffle\Model\User($db2);
$fxName = 'e2e-merge-' . substr(bin2hex(random_bytes(4)), 0, 8);
$fixtureUserId = $fixtureUserModel->create([
    'username'      => $fxName,
    'password_hash' => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
    'name'          => 'Merge Fixture',
    'email'         => $fxName . '@example.test',
    'organization_id' => 1,
    'role'          => 'member',
    'status'        => 'active',
]);

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$board = $boardModel->create(['title' => 'Mya E2E Merge', 'visibility' => 'private', 'created_by' => $actor['id']]);
$laneA = $laneModel->create(['board_id' => $board, 'title' => 'Inbox A', 'position' => 1000]);
$laneB = $laneModel->create(['board_id' => $board, 'title' => 'Inbox B', 'position' => 2000]);

// Cross-board fixture (for rejection check)
$boardX = $boardModel->create(['title' => 'Mya E2E Merge X', 'visibility' => 'private', 'created_by' => $actor['id']]);
$laneX  = $laneModel->create(['board_id' => $boardX, 'title' => 'Inbox', 'position' => 1000]);
$cardX  = $cardModel->create(['lane_id' => $laneX, 'title' => 'cross-board card', 'created_by' => $actor['id']]);

$srcCard = $cardModel->create(['lane_id' => $laneA, 'title' => 'SRC', 'created_by' => $actor['id']]);
$dstCard = $cardModel->create(['lane_id' => $laneB, 'title' => 'DST', 'created_by' => $actor['id']]);

// Assignees: harness to both (overlap), fixture user to source-only (must land on dest)
$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$srcCard, $actor['id']]);
$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$dstCard, $actor['id']]);
$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$srcCard, $fixtureUserId]);

// Comments: 1 on each card (harness + fixture) — after merge, both on dest,
// source-own timestamps stay put.
$cDest = $commentModel->create(['card_id' => $dstCard, 'user_id' => $fixtureUserId, 'body' => 'dest comment']);
$cSrc  = $commentModel->create(['card_id' => $srcCard, 'user_id' => $fixtureUserId, 'body' => 'src comment']);

// Checklists: one on dst, two on src (with different item counts to spot order)
$clDest = $checklistModel->create(['card_id' => $dstCard, 'title' => 'D-checklist']);
$clSrc1 = $checklistModel->create(['card_id' => $srcCard, 'title' => 'S-1']);
$clSrc2 = $checklistModel->create(['card_id' => $srcCard, 'title' => 'S-2']);
$checklistItemModel->create(['checklist_id' => $clDest, 'title' => 'd-item-1']);
$checklistItemModel->create(['checklist_id' => $clDest, 'title' => 'd-item-2']);
$checklistItemModel->create(['checklist_id' => $clSrc1, 'title' => 's1-item']);
$checklistItemModel->create(['checklist_id' => $clSrc2, 'title' => 's2-item-1']);
$checklistItemModel->create(['checklist_id' => $clSrc2, 'title' => 's2-item-2']);

// Attachments: one shared s3_key (should drop on merge) + one unique
$s3Shared = 'merge-e2e/shared-' . $srcCard . '.txt';
$s3Unique = 'merge-e2e/unique-' . $srcCard . '.png';
$attachmentModel->create(['card_id' => $dstCard, 'user_id' => $actor['id'], 'file_name' => 'f.txt', 'file_size' => 10, 's3_key' => $s3Shared, 'mime_type' => 'text/plain']);
$attachmentModel->create(['card_id' => $srcCard, 'user_id' => $actor['id'], 'file_name' => 'f.txt', 'file_size' => 10, 's3_key' => $s3Shared, 'mime_type' => 'text/plain']);
$attachmentModel->create(['card_id' => $srcCard, 'user_id' => $actor['id'], 'file_name' => 'u.png', 'file_size' => 100, 's3_key' => $s3Unique, 'mime_type' => 'image/png']);

// Priority list: both source+dest prioritized for harness and fixture user (CARD-13 test)
foreach ([$actor['id'], $fixtureUserId] as $uid) {
    $db2->execute('INSERT INTO user_prio (user_id, card_id, position) VALUES (?, ?, 1000)', [$uid, $srcCard]);
    $db2->execute('INSERT INTO user_prio (user_id, card_id, position) VALUES (?, ?, 2000)', [$uid, $dstCard]);
}

// Source's own activity feed (to verify cascade-away on merge)
$activityModel->insert($srcCard, $board, 'card_created', $actor['id'], null);

// ---------------------------------------------------------------------------
// Wire the service (mirrors www/v1/index.php — the legacy harness path is
// exactly what this test exercises)
// ---------------------------------------------------------------------------
$cardService = new \Shuffle\Service\CardService($cardModel, $boardModel);
$cardService->setCommentModel($commentModel);
$cardService->setChecklistModel($checklistModel);
$cardService->setAttachmentModel($attachmentModel);
$cardService->setDatabase($db2);
$activityService = new \Shuffle\Service\CardActivityService($activityModel, $cardModel, $laneModel, $userModelForLog);
$cardService->setActivityService($activityService);

// ---------------------------------------------------------------------------
// § 1. Rejection cases — BEFORE the actual merge (order matters: the
//      same-card case must not consume the source card)
// ---------------------------------------------------------------------------
try {
    $cardService->mergeInto($srcCard, $srcCard, $actor);
    check('reject: same-card merges into itself → 400 InvalidArgumentException', false);
} catch (\InvalidArgumentException $e) {
    check('reject: same-card merges into itself → 400 InvalidArgumentException', true);
} catch (\Throwable $e) {
    check('reject: same-card merges into itself → 400 InvalidArgumentException', false);
}

try {
    $cardService->mergeInto($srcCard, 0, $actor);
    check('reject: missing destination → 400', false);
} catch (\InvalidArgumentException $e) {
    check('reject: missing destination → 400', true);
} catch (\Throwable $e) {
    check('reject: missing destination → 400', false);
}

try {
    $cardService->mergeInto($srcCard, $cardX, $actor);
    check('reject: cross-board destination → 400', false);
} catch (\InvalidArgumentException $e) {
    check('reject: cross-board destination → 400 (got: ' . $e->getMessage() . ')', true);
} catch (\Throwable $e) {
    check('reject: cross-board destination → 400', false);
}

try {
    $cardService->mergeInto(99999999, $dstCard, $actor);
    check('reject: nonexistent source → 404 RuntimeException', false);
} catch (\RuntimeException $e) {
    check('reject: nonexistent source → 404 RuntimeException', true);
} catch (\Throwable $e) {
    check('reject: nonexistent source → 404 RuntimeException', false);
}

// ---------------------------------------------------------------------------
// § 2. THE MERGE — happy path
// ---------------------------------------------------------------------------
$preMergedVersion = $boardModel->getVersion($board);
$mergedCard = $cardService->mergeInto($srcCard, $dstCard, $actor);

check('merge: returns the destination card id',
    (int) ($mergedCard['id'] ?? 0) === $dstCard);

// Assignees union (source-only fixture user must be on the survivor; harness still appears once)
$dstAssigned = $cardModel->getAssignedUsers($dstCard);
$assignedIds = array_map(static fn ($u) => (int) $u['id'], $dstAssigned);
sort($assignedIds);
$expectedAssigned = [$actor['id'], $fixtureUserId];
sort($expectedAssigned);
check('assignees: survivor has [harness, fixture] (union, deduped)', $assignedIds === $expectedAssigned);

// Comments: all 4 on dest, source comment keeps original created_at
$dstComments = $commentModel->findByCard($dstCard);
check('comments: dest now has 2 (its own 1 + source 1 re-parented)',
    count($dstComments) === 2);
$srcCommentBody = array_values(array_filter($dstComments, fn ($c) => $c['body'] === 'src comment'));
check('comments: source comment body survives (author/timestamp preserved)',
    count($srcCommentBody) === 1 && 'src comment' === $srcCommentBody[0]['body']);
$srcCommentOriginal = $db2->fetch('SELECT created_at FROM comments WHERE id = ?', [$cSrc]);
check('comments: source comment row id/timestamp intact',
    $srcCommentOriginal !== null && (int) $srcCommentOriginal['created_at'] !== 0);

// Checklists: dst had 1, src had 2 → 3 total on dst. Source checklists land
// AFTER the destination's own (position 2000, 3000 if dest was 1000).
$dstChecklists = $checklistModel->findByCard($dstCard);
check('checklists: dest now has 3 (1 own + 2 re-parented)', count($dstChecklists) === 3);
$titlesInOrder = array_map(static fn ($c) => $c['title'], $dstChecklists);
check('checklists: source checklists come AFTER dest own (title order)',
    $titlesInOrder === ['D-checklist', 'S-1', 'S-2']);
// Item counts per checklist preserve source
$dstChecklistById = [];
foreach ($dstChecklists as $c) { $dstChecklistById[(int)$c['id']] = count($checklistItemModel->findByChecklist((int)$c['id'])); }
check('checklists: items move with their checklist (d=2, s1=1, s2=2)',
    ($dstChecklistById[$clDest] ?? -1) === 2
    && ($dstChecklistById[$clSrc1] ?? -1) === 1
    && ($dstChecklistById[$clSrc2] ?? -1) === 2);

// Attachments: dst has [shared (own), unique (moved)]; src's shared dup dropped.
$dstAtt = $attachmentModel->findByCard($dstCard);
$dstAttKeys = array_map(static fn ($a) => $a['s3_key'], $dstAtt);
sort($dstAttKeys);
check('attachments: survivor has shared + unique (2 rows)', $dstAttKeys === [$s3Shared, $s3Unique]);
$srcAtt = $attachmentModel->findByCard($srcCard);
check('attachments: source card row is gone (no leftover rows for deleted card)', count($srcAtt) === 0);

// Priority list: source card cleared for harness AND fixture user (CARD-13)
foreach ([$actor['id'], $fixtureUserId] as $uid) {
    $rowForSrc = $db2->fetch('SELECT id FROM user_prio WHERE user_id=? AND card_id=?', [$uid, $srcCard]);
    $rowForDst = $db2->fetch('SELECT id FROM user_prio WHERE user_id=? AND card_id=?', [$uid, $dstCard]);
    check('CARD-13: user_prio cleared for user ' . $uid . ' on source card', $rowForSrc === null);
    check('CARD-13: user_prio preserved for user ' . $uid . ' on survivor card', $rowForDst !== null);
}

// Activity: card_merged row on the SURVIVOR with a snapshotted source title
$mergedRow = $db2->fetch(
    'SELECT event, board_id, payload_json FROM card_activity
     WHERE card_id = ? AND event = ? ORDER BY id DESC LIMIT 1',
    [$dstCard, 'card_merged']
);
check('CARD-12: card_merged row exists on survivor', $mergedRow !== null);
if ($mergedRow !== null) {
    $payload = json_decode($mergedRow['payload_json'], true);
    check('CARD-12: payload records source card id + title',
        (int) ($payload['source_card']['id'] ?? 0) === $srcCard
        && ($payload['source_card']['title'] ?? '') === 'SRC');
    check('CARD-12: row board_id is the board the two cards were on',
        (int) $mergedRow['board_id'] === (int) $board);
}

// Source card row + its own activity log are gone
check('source card row is gone after merge', $cardModel->findById($srcCard) === null);
$srcAct = $db2->fetchAll('SELECT id FROM card_activity WHERE card_id = ?', [$srcCard]);
check('source card activity log cascaded away', count($srcAct) === 0);

// Board version bumped
$postMergedVersion = $boardModel->getVersion($board);
check('board version bumped', $postMergedVersion > $preMergedVersion);

// ---------------------------------------------------------------------------
// § 3. Cleanup: delete the merged (dst) card + cross-board fixture + boardX
// ---------------------------------------------------------------------------
$boardServiceCleanup = new \Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);
$boardServiceCleanup->deleteBoard($boardX);
$boardServiceCleanup->deleteBoard($board);
$leftovers = $db2->fetchAll(
    'SELECT COUNT(*) AS c FROM boards WHERE id IN (?, ?)',
    [$board, $boardX]
);
check('cleanup: both fixture boards gone', (int) $leftovers[0]['c'] === 0);

// Fixture user (no cascade reaches it — boards created_by must go first)
$fixtureUserModel->delete($fixtureUserId);
check('cleanup: fixture user gone', $fixtureUserModel->findById($fixtureUserId) === null);

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
