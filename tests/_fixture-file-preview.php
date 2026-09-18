<?php
declare(strict_types=1);

/**
 * Fixture for tests/http-file-preview.sh (user 4 = mya, never Daniel).
 *
 * Creates: board (owned by user 4) + lane + card A (PNG image attachment)
 * + card B (zip attachment). Both files are uploaded through the real
 * AttachmentService (S3 object + DB row + board version bump on the
 * production path).
 *
 * Prints a single JSON object:
 *   {"board": int, "cardA": int, "cardB": int, "attImg": int, "attZip": int,
 *    "pngSize": int, "s3Keys": [ ... ], "attIds": [ ... ]}
 *
 * The shell script runs the matching cleanup (cleanup_filepreview) on exit,
 * so re-running the test against a stale fixture is safe: any fixture rows
 * from a crashed prior run are deleted before the new one is created.
 *
 * Usage: php tests/_fixture-file-preview.php
 */
require __DIR__ . '/../include/bootstrap.php';

$uid = 4;
if (!$db->fetch('SELECT id FROM users WHERE id = ? AND status = ?', [$uid, 'active'])) {
    fwrite(STDERR, "user $uid not active\n");
    exit(3);
}

$boardModel  = new \Shuffle\Model\Board($db);
$laneModel   = new \Shuffle\Model\Lane($db);
$cardModel   = new \Shuffle\Model\Card($db);
$attModel    = new \Shuffle\Model\Attachment($db);
$s3          = new \Shuffle\Core\S3Client($config['s3'] ?? []);
$attachmentService = new \Shuffle\Service\AttachmentService(
    $attModel, $cardModel, $boardModel, $s3
);

// ---- clean up any stale fixture from a crashed prior run ----------------
$stale = $db->fetchAll("SELECT id FROM boards WHERE title LIKE 'FP HTTP FIXTURE %'");
foreach ($stale as $row) {
    try { $boardModel->delete((int) $row['id']); } catch (\Throwable $e) { /* best effort */ }
}

// ---- fresh fixture -------------------------------------------------------
$board = $boardModel->create([
    'title'            => 'FP HTTP FIXTURE ' . date('Ymd-His'),
    'visibility'       => 'private',
    'created_by'       => $uid,
    'organization_ids' => [],
]);
$lane  = $laneModel->create(['board_id' => $board, 'title' => 'Inbox', 'icon' => '']);
$cardA = $cardModel->create(['lane_id' => $lane, 'title' => 'FP IMG ' . time(), 'created_by' => $uid]);
$cardB = $cardModel->create(['lane_id' => $lane, 'title' => 'FP ZIP ' . time(), 'created_by' => $uid]);

// 1x1 transparent PNG (67 bytes) — the same bytes the e2e test uses, so a
// cross-suite byte-compare against this file is exact.
$pngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk' .
    'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    true
);
$pngFile = sys_get_temp_dir() . '/fp-fixture-' . $board . '.png';
file_put_contents($pngFile, $pngBytes);

// zip (28+4 bytes: PK header + filler) — previewable-set → 415.
$zipBytes = 'PK\x03\x04' . str_repeat('x', 28);
$zipFile = sys_get_temp_dir() . '/fp-fixture-' . $board . '.zip';
file_put_contents($zipFile, $zipBytes);

function fp_upload(\Shuffle\Service\AttachmentService $svc, int $cardId, string $path, string $mime, string $name): array
{
    $bytes = file_get_contents($path);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);
    $row = $svc->upload($cardId, 1, $name, strlen($bytes), $mime, $stream);
    fclose($stream);
    return $row;
}

$imgRow  = fp_upload($attachmentService, (int) $cardA, $pngFile, 'image/png', 'diagram.png');
$zipRow  = fp_upload($attachmentService, (int) $cardB, $zipFile, 'application/zip', 'bundle.zip');

unlink($pngFile);
unlink($zipFile);

echo json_encode([
    'board'   => (int) $board,
    'cardA'   => (int) $cardA,
    'cardB'   => (int) $cardB,
    'attImg'  => (int) $imgRow['id'],
    'attZip'  => (int) $zipRow['id'],
    'pngSize' => (int) $imgRow['file_size'],
    's3Keys'  => [$imgRow['s3_key'], $zipRow['s3_key']],
    'attIds'  => [(int) $imgRow['id'], (int) $zipRow['id']],
]);
