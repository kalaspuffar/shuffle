<?php
require dirname(__DIR__) . '/include/bootstrap.php';
$boardModel = new \Shuffle\Model\Board($db);
$laneModel  = new \Shuffle\Model\Lane($db);
$cardModel  = new \Shuffle\Model\Card($db);
$board = $boardModel->create(['title' => 'HTTP Merge single', 'visibility' => 'private', 'created_by' => 1]);
$lane  = $laneModel->create(['board_id' => $board, 'title' => 'L', 'position' => 1000]);
$card  = $cardModel->create(['lane_id' => $lane, 'title' => 'only card', 'created_by' => 1]);
echo $card . "\n" . $board . "\n";
