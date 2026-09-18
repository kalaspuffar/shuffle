<?php
declare(strict_types=1);
/**
 * E2E test for FILE-06/07 (v1.15, spec §5.23) — file previews.
 *
 * Exercises the service layer against the live DB + the real S3 endpoint:
 *   - Attachment::PREVIEWABLE_MIME + firstPreviewableByCards() "first wins"
 *   - AttachmentService::preview() full-object and ranged paths
 *   - exception mapping: PreviewTypeException (415), RangeException (416),
 *     RuntimeException (404)
 *   - S3Client::getObjectRange byte-exact delivery (S3 Range support)
 *   - BoardService wiring: preview_attachment key present for cards with a
 *     previewable attachment, ABSENT otherwise (incl. the no-injection path)
 *
 * Fixtures are self-cleaning: a scratch board + lane + 3 cards, uploads go
 * through the real AttachmentService::upload() (S3 object + DB row + board
 * version bump all on the production path). A shutdown hook removes S3
 * objects and every DB row on clean exit AND fatal errors alike.
 *
 * Usage: php tests/e2e-file-preview.php
 */
require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0; $fails = 0;
function check(string $n, bool $c): void {
    global $checks, $fails;
    $checks++;
    if (!$c) $fails++;
    echo ($c ? 'PASS ' : 'FAIL ') . "$n\n";
}

// ---------- fixture state (shared with the shutdown hook) --------------
$fpState = [
    's3_keys'         => [],
    'attachment_ids'  => [],
    'card_ids'        => [],
    'lane_id'         => null,
    'board_id'        => null,
];
register_shutdown_function(function () use (&$fpState, $db): void {
    $s3 = new \Shuffle\Core\S3Client($GLOBALS['config']['s3'] ?? []);
    $errors = 0;
    foreach ($fpState['s3_keys'] as $key) {
        try { $s3->deleteObject($key); } catch (\Throwable $e) { $errors++; }
    }
    $att   = new \Shuffle\Model\Attachment($db);
    $card  = new \Shuffle\Model\Card($db);
    $lane  = new \Shuffle\Model\Lane($db);
    $board = new \Shuffle\Model\Board($db);
    foreach ($fpState['attachment_ids'] as $aid) {
        try { $att->delete((int) $aid); } catch (\Throwable $e) { $errors++; }
    }
    foreach ($fpState['card_ids'] as $cid) {
        try { $card->delete((int) $cid); } catch (\Throwable $e) { $errors++; }
    }
    if ($fpState['lane_id'] !== null) {
        try { $lane->delete((int) $fpState['lane_id']); } catch (\Throwable $e) { $errors++; }
    }
    if ($fpState['board_id'] !== null) {
        try { $board->delete((int) $fpState['board_id']); } catch (\Throwable $e) { $errors++; }
    }
    if ($errors > 0) fwrite(STDERR, "fixture cleanup: $errors errors\n");
});

// ---------- fixture: board + lane + 3 cards -----------------------------
$admin = ['id' => 1, 'username' => 'admin', 'name' => 'Admin',
          'email' => 'admin@example.com', 'role' => 'admin', 'organization_id' => 1];

$boardModel  = new \Shuffle\Model\Board($db);
$laneModel   = new \Shuffle\Model\Lane($db);
$cardModel   = new \Shuffle\Model\Card($db);
$attachmentModel = new \Shuffle\Model\Attachment($db);
$s3          = new \Shuffle\Core\S3Client($config['s3'] ?? []);

$boardService = new \Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);
$board = $boardService->createBoard(['title' => 'FILEPREV-E2E-' . time()], $admin, false);
$fpState['board_id'] = (int) $board['id'];
$boardId = $fpState['board_id'];

$laneService = new \Shuffle\Service\LaneService($laneModel, $boardModel);
$lane = $laneService->createLane($boardId, ['title' => 'Smoke', 'description' => '', 'icon' => '']);
$fpState['lane_id'] = (int) $lane['id'];
$laneId = $fpState['lane_id'];

$cardService = new \Shuffle\Service\CardService($cardModel, $boardModel);
$attachmentService = new \Shuffle\Service\AttachmentService(
    $attachmentModel, $cardModel, $boardModel, $s3
);

function fpUpload(int $cardId, \Shuffle\Service\AttachmentService $svc,
                  array &$fpState, string $bytes, string $name, string $mime): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);
    $row = $svc->upload($cardId, 1, $name, strlen($bytes), $mime, $stream);
    fclose($stream);
    $fpState['s3_keys'][] = (string) $row['s3_key'];
    $fpState['attachment_ids'][] = (int) $row['id'];
    return $row;
}

// Card A: PNG (previewable) + zip (not) — first previewable must be the PNG.
$cardA = $cardService->createCard($boardId, $laneId, ['title' => 'FP Card A'], $admin);
$fpState['card_ids'][] = (int) $cardA['id'];
$cardAId = (int) $cardA['id'];

// A minimal valid 1x1 transparent PNG (67 bytes).
$pngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk' .
    'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    true
);
$attA1 = fpUpload($cardAId, $attachmentService, $fpState, $pngBytes, 'diagram.png', 'image/png');
$zipBytes = 'PK\x03\x04' . str_repeat('x', 28);
$attA2 = fpUpload($cardAId, $attachmentService, $fpState, $zipBytes, 'bundle.zip', 'application/zip');

// Card B: zip only → no previewable attachment.
$cardB = $cardService->createCard($boardId, $laneId, ['title' => 'FP Card B'], $admin);
$fpState['card_ids'][] = (int) $cardB['id'];
$cardBId = (int) $cardB['id'];
$attB1 = fpUpload($cardBId, $attachmentService, $fpState, $zipBytes, 'zip-only.zip', 'application/zip');

// Card C: zero attachments.
$cardC = $cardService->createCard($boardId, $laneId, ['title' => 'FP Card C'], $admin);
$fpState['card_ids'][] = (int) $cardC['id'];
$cardCId = (int) $cardC['id'];

$pngId = (int) $attA1['id'];
$zipId = (int) $attA2['id'];
$pngSize = (int) $attA1['file_size'];

// ========================================================================
// Tests
// ========================================================================

// --- 1. allowlist constant (server single source of truth) ---
check('PREVIEWABLE_MIME is the spec §5.23 set',
    \Shuffle\Model\Attachment::PREVIEWABLE_MIME === [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf',
    ]);

// --- 2. firstPreviewableByCards: grouping + first-wins + absences ---
$map = $attachmentModel->firstPreviewableByCards([$cardAId, $cardBId, $cardCId]);
check('card A: exactly one previewable row', isset($map[$cardAId]) && is_array($map[$cardAId]));
check('card A: first previewable is the PNG row (earliest id)',
    isset($map[$cardAId]) && (int) $map[$cardAId]['id'] === $pngId);
check('card A: carries file_name + mime_type',
    isset($map[$cardAId]) && $map[$cardAId]['file_name'] === 'diagram.png'
        && $map[$cardAId]['mime_type'] === 'image/png');
check('card B (zip-only): no key in the map', !array_key_exists($cardBId, $map));
check('card C (no attachments): no key in the map', !array_key_exists($cardCId, $map));
check('empty cardIds short-circuits to []', $attachmentModel->firstPreviewableByCards([]) === []);

// --- 3. type gate: non-previewable → PreviewTypeException (415 path) ---
try {
    $attachmentService->preview($zipId);
    check('zip preview() throws PreviewTypeException', false);
} catch (\Shuffle\Core\PreviewTypeException $e) {
    check('zip preview() throws PreviewTypeException', true);
    check('exception names the MIME', str_contains($e->getMessage(), 'application/zip'));
    check('NOT a RuntimeException (separate 415 ≠ 404 paths)', !($e instanceof \RuntimeException));
}

// 3b. SVG is deliberately OUT of the preview set (§5.23 decision note):
//     a browser sanitizer makes it "safe" in <img>, but the preview URL
//     contract must assume the worst consumer (an <embed>/iframe surface),
//     so image/svg+xml → 415. Pin it via a direct service call with a
//     crafted attachment row whose mime is svg (no fixture upload needed —
//     the type gate fires before any S3 touch).
$svgId = $attachmentModel->create([
    'card_id'   => $cardAId,
    'user_id'   => 1,
    'file_name' => 'icon.svg',
    'file_size' => 4,
    's3_key'    => '__no_object_never_fetched__',
    'mime_type' => 'image/svg+xml',
]);
$fpState['attachment_ids'][] = (int) $svgId;
$svgThrew = false; $svgIsNarrow = true;
try { $attachmentService->preview((int) $svgId); }
catch (\Shuffle\Core\PreviewTypeException $e) { $svgThrew = true; }
catch (\Throwable $e) { $svgThrew = true; $svgIsNarrow = false; }
check('svg+xml → PreviewTypeException (415 path, not 404)', $svgThrew && $svgIsNarrow);

// --- 4. unknown id → RuntimeException (404 path) ---
try {
    $attachmentService->preview(987654321);
    check('unknown id throws RuntimeException', false);
} catch (\RuntimeException $e) {
    check('unknown id throws RuntimeException', true);
}

// --- 5. full-object path (no Range) ---
$full = $attachmentService->preview($pngId);
check('full: range is null', $full['range'] === null);
check('full: attachment row id round-trips', (int) $full['attachment']['id'] === $pngId);
check('full: stream is a resource', is_resource($full['stream']));
if (is_resource($full['stream'])) {
    $body = stream_get_contents($full['stream']);
    fclose($full['stream']);
    check('full: body byte-identical to the uploaded PNG', $body === $pngBytes);
} else {
    check('full: body byte-identical to the uploaded PNG', false);
}

// --- 6. range validation (service level, before any S3 round-trip) ---
function fpExpectRangeException(string $n, callable $fn): void {
    try {
        $fn();
        check($n . ' → RangeException', false);
    } catch (\Shuffle\Core\RangeException $e) {
        check($n . ' → RangeException', true);
    }
}
fpExpectRangeException('start > end', fn() => $attachmentService->preview($pngId, 10, 5));
fpExpectRangeException('negative start', fn() => $attachmentService->preview($pngId, -1, 5));
fpExpectRangeException('end >= file_size', fn() => $attachmentService->preview($pngId, 0, $pngSize));
fpExpectRangeException('open range (end null)', fn() => $attachmentService->preview($pngId, 0, null));

// start === end (single byte) is legal — must NOT throw
$single = $attachmentService->preview($pngId, 0, 0);
check('single-byte range (0,0) is legal',
    $single['range'] === ['start' => 0, 'end' => 0, 'total' => $pngSize]);
if (is_resource($single['stream'])) {
    $b1 = stream_get_contents($single['stream']);
    fclose($single['stream']);
    check('single-byte body === first PNG byte', is_string($b1) && strlen($b1) === 1 && $b1 === substr($pngBytes, 0, 1));
} else {
    check('single-byte body === first PNG byte', false);
}

// --- 7. valid range: S3 206 round-trip, byte-exact ---
$r = $attachmentService->preview($pngId, 0, 10);
check('ranged: range metadata correct',
    $r['range'] === ['start' => 0, 'end' => 10, 'total' => $pngSize]);
if (is_resource($r['stream'])) {
    $rb = stream_get_contents($r['stream']);
    fclose($r['stream']);
    check('ranged: EXACTLY 11 bytes', is_string($rb) && strlen($rb) === 11);
    check('ranged: bytes === PNG[0..10]', is_string($rb) && $rb === substr($pngBytes, 0, 11));
} else {
    check('ranged: EXACTLY 11 bytes', false);
    check('ranged: bytes === PNG[0..10]', false);
}

// mid-object range
$r2 = $attachmentService->preview($pngId, 4, 7);
if (is_resource($r2['stream'])) {
    $rb2 = stream_get_contents($r2['stream']);
    fclose($r2['stream']);
    check('mid-range [4-7]: 4 bytes, matching object slice',
        is_string($rb2) && strlen($rb2) === 4 && $rb2 === substr($pngBytes, 4, 4));
} else {
    check('mid-range [4-7]: 4 bytes, matching object slice', false);
}

// --- 8. S3Client::getObjectRange directly (the 206 plumbing) ---
$r3 = $s3->getObjectRange((string) $attA1['s3_key'], 0, 3);
check('S3Client::getObjectRange: 4-byte size reported', is_array($r3) && (int) $r3['size'] === 4);
if (is_resource($r3['stream'])) {
    $rb3 = stream_get_contents($r3['stream']);
    fclose($r3['stream']);
    check('S3Client::getObjectRange: bytes === PNG[0..3]', is_string($rb3) && $rb3 === substr($pngBytes, 0, 4));
} else {
    check('S3Client::getObjectRange: bytes === PNG[0..3]', false);
}

// --- 9. BoardService wiring: preview_attachment present/absent ---
$boardService->setAttachmentModel($attachmentModel);
$boardData = $boardService->getBoardWithLanesAndCards($boardId, false);
$cardsById = [];
foreach ((array) ($boardData['lanes'] ?? []) as $laneData) {
    foreach ((array) ($laneData['cards'] ?? []) as $c) {
        $cardsById[(int) $c['id']] = $c;
    }
}
$pa = $cardsById[$cardAId]['preview_attachment'] ?? null;
check('board payload: card A HAS preview_attachment', isset($pa['id']) && isset($pa['file_name']));
check('board payload: card A → the PNG row (id + name)',
    isset($pa['id']) && (int) $pa['id'] === $pngId && $pa['file_name'] === 'diagram.png');
check('board payload: card B (zip-only) has NO preview_attachment key',
    !array_key_exists('preview_attachment', $cardsById[$cardBId] ?? []));
check('board payload: card C (no attachments) has NO preview_attachment key',
    !array_key_exists('preview_attachment', $cardsById[$cardCId] ?? []));

// --- 10. no-injection path: key absent for everyone ---
$boardServiceNoInject = new \Shuffle\Service\BoardService($boardModel, $laneModel, $cardModel);
$boardData2 = $boardServiceNoInject->getBoardWithLanesAndCards($boardId, false);
$cardsNoInject = [];
foreach ((array) ($boardData2['lanes'] ?? []) as $laneData) {
    foreach ((array) ($laneData['cards'] ?? []) as $c) {
        $cardsNoInject[(int) $c['id']] = $c;
    }
}
check('no-injection: card A has NO preview_attachment key (byte-identical output contract)',
    !array_key_exists('preview_attachment', $cardsNoInject[$cardAId] ?? []));

// ---------- summary ------------------------------------------------------
echo str_repeat('=', 64) . "\n";
echo "file-preview e2e: $checks checks, $fails failure(s)\n";
exit($fails === 0 ? 0 : 1);
