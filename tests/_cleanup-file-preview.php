<?php
declare(strict_types=1);

/**
 * Cleanup for tests/http-file-preview.sh (companion to _fixture-file-preview).
 *
 * Argument: the JSON object printed by the fixture script (board id + the
 * S3 keys + attachment ids it created). Deletes in FK-safe order:
 * S3 objects (best-effort), attachments, cards + their child rows, lanes,
 * board. Idempotent — safe to run when the fixture is already gone.
 *
 * Usage: php tests/_cleanup-file-preview.php '<json>'
 */
if ($argc < 2) { fwrite(STDERR, "usage: _cleanup-file-preview.php '<json>'\n"); exit(2); }
require __DIR__ . '/../include/bootstrap.php';

$fx = json_decode($argv[1], true);
if (!is_array($fx)) exit(3);
$boardId = (int) ($fx['board'] ?? 0);
$attIds  = array_map('intval', $fx['attIds'] ?? []);
$s3Keys  = array_map('strval', $fx['s3Keys'] ?? []);
if ($boardId <= 0) exit(0);

$s3    = new \Shuffle\Core\S3Client($config['s3'] ?? []);
$errors = 0;

foreach ($s3Keys as $key) {
    try { $s3->deleteObject($key); } catch (\Throwable $e) { $errors++; }
}

// Cards that live on this board (the fixture's lane(s) only) — child rows
// first, then the cards, lanes, and finally the board (same order as the
// http-board-sync.sh cleanup, plus attachments which the preview fixture
// carries).
$laneIds = array_map('intval', $db->fetchAll('SELECT id FROM lanes WHERE board_id = ?', [$boardId]));
if ($laneIds) {
    $in = implode(',', $laneIds);
    $cardIds = array_map('intval', $db->fetchAll("SELECT id FROM cards WHERE lane_id IN ($in)"));
    if ($cardIds) {
        $in2 = implode(',', $cardIds);
        foreach ([
            'DELETE FROM comments WHERE card_id IN (' . $in2 . ')',
            'DELETE FROM checklist_items WHERE checklist_id IN (SELECT id FROM checklists WHERE card_id IN (' . $in2 . '))',
            'DELETE FROM checklists WHERE card_id IN (' . $in2 . ')',
            'DELETE FROM attachments WHERE card_id IN (' . $in2 . ')',
            'DELETE FROM card_assignments WHERE card_id IN (' . $in2 . ')',
            'DELETE FROM card_activity WHERE card_id IN (' . $in2 . ')',
            'DELETE FROM cards WHERE id IN (' . $in2 . ')',
        ] as $sql) {
            try { $db->execute($sql); } catch (\Throwable $e) { $errors++; }
        }
    }
    try { $db->execute('DELETE FROM lanes WHERE board_id = ?', [$boardId]); } catch (\Throwable $e) { $errors++; }
}
try { $db->execute('DELETE FROM board_organizations WHERE board_id = ?', [$boardId]); } catch (\Throwable $e) { $errors++; }
try { $db->execute('DELETE FROM boards WHERE id = ?', [$boardId]); } catch (\Throwable $e) { $errors++; }

if ($errors > 0) fwrite(STDERR, "cleanup: $errors non-fatal error(s)\n");
echo "ok\n";
