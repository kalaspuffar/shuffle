<?php
require dirname(__DIR__) . '/include/bootstrap.php';
$boardModel = new \Shuffle\Model\Board($db);
$laneModel  = new \Shuffle\Model\Lane($db);
$cardModel  = new \Shuffle\Model\Card($db);
$main    = $boardModel->create(['title' => 'HTTP Merge Main', 'visibility' => 'private', 'created_by' => 1]);
$lm      = $laneModel->create(['board_id' => $main, 'title' => 'Lane A', 'position' => 1000]);
$c1      = $cardModel->create(['lane_id' => $lm, 'title' => 'HTTP merge SOURCE', 'created_by' => 1]);
$c2      = $cardModel->create(['lane_id' => $lm, 'title' => 'HTTP merge DEST', 'created_by' => 1]);
$db->execute('INSERT INTO comments (card_id, user_id, body) VALUES (?, ?, ?)', [$c1, 2, 'http src comment']);
$other   = $boardModel->create(['title' => 'HTTP Merge Other', 'visibility' => 'private', 'created_by' => 1]);
$lo      = $laneModel->create(['board_id' => $other, 'title' => 'L', 'position' => 1000]);
$cx      = $cardModel->create(['lane_id' => $lo, 'title' => 'HTTP merge CROSS', 'created_by' => 1]);
echo json_encode(['main' => $main, 'c1' => $c1, 'c2' => $c2, 'other' => $other, 'cx' => $cx]);
