<?php
// Teardown for http-labels.sh — deletes board (cascades labels + comments via FK)
// + temp viewer user + temp org. Args: board viewer uid org
require dirname(__DIR__) . '/include/bootstrap.php';
$board = (int)$argv[1];
$uid   = (int)$argv[2];
$org   = (int)($argv[3] ?? 0);
if ($board > 0) {
    (new \Shuffle\Service\BoardService(
        new \Shuffle\Model\Board($db), new \Shuffle\Model\Lane($db), new \Shuffle\Model\Card($db)
    ))->deleteBoard($board);
}
if ($uid > 0) {
    $db->execute('DELETE FROM sessions WHERE user_id = ?', [$uid]);
    $db->execute('DELETE FROM users WHERE id = ?', [$uid]);
}
if ($org > 0) { $db->execute('DELETE FROM organizations WHERE id = ?', [$org]); }
echo "cleaned board={$board} viewer={$uid} org={$org}\n";
