<?php
declare(strict_types=1);
/**
 * E2E check for the ACTIVITY-01 audit hooks added to the attachment and
 * checklist services (the Daniel 2026-08-30 addendum) plus the
 * archived-cards board view.
 *
 * Scenario:
 *   1. attachment_added  — AttachmentService::upload writes a row.
 *   2. attachment_removed — AttachmentService::deleteAttachment writes a row
 *      with the file name+size snapshot AND the original uploader snapshot
 *      (so an admin removing another user's file is distinguishable).
 *   3. checklist_added   — ChecklistService::createChecklist writes a row.
 *   4. checklist_renamed — ChecklistService::updateChecklist writes a row
 *      ONLY on a real title change; a no-op rename writes nothing.
 *   5. checklist_removed — ChecklistService::deleteChecklist writes a row
 *      carrying the checklist title snapshot.
 *   6. Archived-cards view — Card::findByBoard(id, includeArchived) excludes
 *      archived cards by default and includes them when the flag is set
 *      (the board.php "Show archived" toggle data path).
 *
 * All mutations run against the live DB as a headless admin (user_id from
 * argv[1], default 1). Temp board + card + objects deleted at end.
 *
 * Usage:  php tests/e2e-activity-audit.php [user_id]   (default 1 = admin)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$asUserId = isset($argv[1]) ? (int) $argv[1] : 1;

// ---------------------------------------------------------------------------
// Headless double for the S3 object store — no real object is written; the
// audit hook under test writes a DB row that references the attachment id.
// ---------------------------------------------------------------------------
class FakeS3 extends \Shuffle\Core\S3Client
{
    public function __construct() { parent::__construct([]); }
    public function putObject(string $key, $stream, int $size, string $contentType): void {}
    public function deleteObject(string $key): void {}
    public function objectExists(string $key): bool { return false; }
}

$asUser = $db->fetch('SELECT id, name, role, status, organization_id FROM users WHERE id = ?', [$asUserId]);
if ($asUser === null) {
    fwrite(STDERR, "FATAL: user id $asUserId not found\n");
    exit(1);
}
$asUser['id'] = (int) $asUser['id'];

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

// A fresh user (the "uploader") to attach a file as, so we can verify the
// attachment_removed.uploader snapshot differs from the actor who removes it.
$uploaderRow = $db->fetch('SELECT id, name, role, status, organization_id FROM users WHERE id != ? ORDER BY id LIMIT 1', [$asUserId]);
$uploader = $uploaderRow ? array_merge((array) $uploaderRow, ['id' => (int) $uploaderRow['id']]) : $asUser;

$boardModel = new \Shuffle\Model\Board($db);
$laneModel  = new \Shuffle\Model\Lane($db);
$cardModel  = new \Shuffle\Model\Card($db);
$userModel  = new \Shuffle\Model\User($db);
$commentModel = new \Shuffle\Model\Comment($db);
$activityModel = new \Shuffle\Model\CardActivity($db);
$attachModel  = new \Shuffle\Model\Attachment($db);
$checklistModel = new \Shuffle\Model\Checklist($db);
$checklistItemModel = new \Shuffle\Model\ChecklistItem($db);

$activityService = new \Shuffle\Service\CardActivityService($activityModel, $cardModel, $laneModel, $userModel);

$attachService = new \Shuffle\Service\AttachmentService($attachModel, $cardModel, $boardModel, new FakeS3());
$attachService->setActivityService($activityService);
$attachService->setUserModel($userModel);

$checklistService = new \Shuffle\Service\ChecklistService($checklistModel, $checklistItemModel, $cardModel, $boardModel);
$checklistService->setActivityService($activityService);

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------
$ts = uniqid('act-audit-');
$boardId = $boardModel->create(['title' => "ACTIVITY_AUDIT_$ts", 'visibility' => 'private', 'created_by' => $asUser['id']]);
$laneId = $laneModel->create(['board_id' => $boardId, 'title' => 'Inbox', 'position' => 1000]);
$cardId = (int) $cardModel->create(['lane_id' => $laneId, 'title' => 'AUDIT seed card', 'description' => null, 'due_date' => null, 'created_by' => $asUser['id']]);

$cleanup = function () use ($boardModel, $boardId) {
    $boardModel->delete((int) $boardId);
};
register_shutdown_function($cleanup);

try {
    // ------------------------------------------------------------------
    // 1. attachment_added
    // ------------------------------------------------------------------
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'audit test file');
    rewind($stream);
    $attachRes = $attachService->upload($cardId, (int) $uploader['id'], 'spec-audit.pdf', 15, 'application/pdf', $stream);
    fclose($stream);
    $attId = (int) ($attachRes['id'] ?? 0);
    $row = $activityModel->feedForCard($cardId, 200)[0] ?? null;
    check('attachment_added: row exists after upload()', $row && $row['event'] === 'attachment_added', json_encode($row['event'] ?? null));
    $p = json_decode((string) ($row['payload_json'] ?? '{}'), true);
    check('attachment_added: file.name snapshot', ($p['file']['name'] ?? null) === 'spec-audit.pdf', json_encode($p['file'] ?? null));
    check('attachment_added: uploader id in the attachment (not the log actor)', (int) ($attachRes['user_id'] ?? 0) === (int) $uploader['id']);
    check('attachment_added: actor is the uploader', (int) ($row['actor_id'] ?? -1) === (int) $uploader['id']);

    // ------------------------------------------------------------------
    // 2. attachment_removed (actor = $asUser, uploader = possibly another)
    // ------------------------------------------------------------------
    $before = count($activityModel->feedForCard($cardId, 300));
    $attachService->deleteAttachment($attId, $asUser);
    $rows = $activityModel->feedForCard($cardId, 300);
    $row = $rows[0] ?? null;
    check('attachment_removed: row exists after deleteAttachment()', $row && $row['event'] === 'attachment_removed', json_encode($row['event'] ?? null));
    check('attachment_removed: actor is the caller (remover)', (int) ($row['actor_id'] ?? -1) === (int) $asUser['id']);
    $p = json_decode((string) ($row['payload_json'] ?? '{}'), true);
    check('attachment_removed: file.name snapshot kept', ($p['file']['name'] ?? null) === 'spec-audit.pdf', json_encode($p['file'] ?? null));
    // uploader snapshot: present and == the original uploader (even if the
    // remover is a different user).
    check('attachment_removed: uploader snapshot present', isset($p['uploader']['id'], $p['uploader']['name']), json_encode($p['uploader'] ?? null));
    check('attachment_removed: uploader is the original uploader', ($p['uploader']['id'] ?? null) === (int) $uploader['id']);
    check('attachment_removed: exactly 1 new row for the deletion', count($rows) === $before + 1);

    // ------------------------------------------------------------------
    // 3. checklist_added
    // ------------------------------------------------------------------
    $checklistService->createChecklist($cardId, ['title' => 'Audit steps'], $asUser);
    $clList = $checklistModel->findByCard($cardId);
    check('checklist_added: a checklist row was created', count($clList) === 1, 'got ' . count($clList));
    $row = $activityModel->feedForCard($cardId, 300)[0] ?? null;
    check('checklist_added: activity row exists', $row && $row['event'] === 'checklist_added', json_encode($row['event'] ?? null));
    $p = json_decode((string) ($row['payload_json'] ?? '{}'), true);
    check('checklist_added: title snapshot', ($p['checklist']['title'] ?? null) === 'Audit steps', json_encode($p['checklist'] ?? null));

    // Get the checklist id for the rename/delete steps.
    $clId = (int) ($clList[0]['id'] ?? 0);

    // ------------------------------------------------------------------
    // 4a. checklist_renamed — real change
    // ------------------------------------------------------------------
    $before = count($activityModel->feedForCard($cardId, 400));
    $checklistService->updateChecklist($clId, ['title' => 'Audit steps v2'], $asUser);
    $rows = $activityModel->feedForCard($cardId, 400);
    $renamed = array_values(array_filter($rows, fn ($r) => $r['event'] === 'checklist_renamed'));
    check('checklist_renamed: exactly 1 row for a real rename', count($renamed) === 1, 'got ' . count($renamed));
    $p = json_decode((string) ($renamed[0]['payload_json'] ?? '{}'), true);
    check('checklist_renamed: before + after recorded', ($p['checklist']['before'] ?? null) === 'Audit steps' && ($p['checklist']['after'] ?? null) === 'Audit steps v2', json_encode($p['checklist'] ?? null));

    // 4b. checklist_renamed — no-op (same title) must NOT log
    $before = count($activityModel->feedForCard($cardId, 400));
    $checklistService->updateChecklist($clId, ['title' => 'Audit steps v2'], $asUser);
    check('checklist_renamed (no-op): no row for an unchanged title',
        count($activityModel->feedForCard($cardId, 400)) === $before);

    // ------------------------------------------------------------------
    // 5. checklist_removed
    // ------------------------------------------------------------------
    $before = count($activityModel->feedForCard($cardId, 500));
    $checklistService->deleteChecklist($clId, $asUser);
    $row = $activityModel->feedForCard($cardId, 500)[0] ?? null;
    check('checklist_removed: row exists after deleteChecklist()', $row && $row['event'] === 'checklist_removed', json_encode($row['event'] ?? null));
    $p = json_decode((string) ($row['payload_json'] ?? '{}'), true);
    check('checklist_removed: title snapshot (the title at delete time)', ($p['checklist']['title'] ?? null) === 'Audit steps v2', json_encode($p['checklist'] ?? null));
    check('checklist_removed: exactly 1 new row', count($activityModel->feedForCard($cardId, 500)) === $before + 1);
    check('checklist_removed: the checklist row itself is gone', count($checklistModel->findByCard($cardId)) === 0);

    // ------------------------------------------------------------------
    // 6. Archived-cards view data path
    // ------------------------------------------------------------------
    // Archive this card, then confirm findByBoard filters it out by default
    // and includes it with the flag — the exact data path the board.php
    // "Show archived" toggle drives.
    $cardModel->archive($cardId);
    $cardModel->restore($cardId); // no-op sanity; ensure it's currently restored
    $activeCount = count($cardModel->findByBoard($boardId));            // default: archived excluded
    $cardModel->archive($cardId);
    $withFlag = count($cardModel->findByBoard($boardId, true));          // include archived
    $default =  count($cardModel->findByBoard($boardId));                // default again
    // The card was created + archived. With the flag it's present; without it, absent.
    check('archived view: findByBoard() excludes the archived card', $default === 0, 'default count=' . $default);
    check('archived view: findByBoard(id, true) includes the archived card', $withFlag === 1, 'flagged count=' . $withFlag);
    check('archived view: flag is off by default', $activeCount === 1, 'active count=' . $activeCount);
} finally {
    $cleanup();
}

echo "\nResult: $checks checks, $failures failed\n";
exit($failures > 0 ? 1 : 0);
