<?php
// Fixture for http-labels.sh — scratch board (org visibility, org 1,
// created_by admin) + 2 cards + a temp viewer user in a temp org (org 999
// created here) so the viewer has NO access to the main board (used for
// the BOARD-04b 404 / role 403 negative tests). Emits JSON ids.
require dirname(__DIR__) . '/include/bootstrap.php';
$bm = new \Shuffle\Model\Board($db);
$lm = new \Shuffle\Model\Lane($db);
$cm = new \Shuffle\Model\Card($db);
$board = $bm->create(['title' => 'HTTP LABELS ' . time(), 'visibility' => 'organization', 'created_by' => 1]);
$lane  = $lm->create(['board_id' => $board, 'title' => 'L1', 'position' => 1000]);
$c1    = $cm->create(['lane_id' => $lane, 'title' => 'LBL src card', 'created_by' => 1]);
$c2    = $cm->create(['lane_id' => $lane, 'title' => 'LBL dest card', 'created_by' => 1]);
// Temp org (id may not be 999 — we capture whatever the PK is) + temp viewer.
$db->execute('INSERT INTO organizations (name) VALUES (?)', ['LBL temp org ' . time()]);
$org = $db->fetchAll('SELECT id FROM organizations ORDER BY id DESC LIMIT 1');
$org = (int)$org[0]['id'];
$uname = 'lbl-viewer-' . time();
$db->execute(
    'INSERT INTO users (username, password_hash, name, email, role, organization_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$uname, password_hash('LblViewer!x', PASSWORD_ARGON2ID), 'LBL Viewer', 'lbl-' . time() . '@t.test', 'viewer', $org, 'active']
);
$uid = $db->fetchAll('SELECT id FROM users WHERE username = ? ORDER BY id DESC', [$uname]);
$uid = (int)($uid[0]['id'] ?? 0);
echo json_encode(['board'=>$board,'lane'=>$lane,'c1'=>$c1,'c2'=>$c2,'viewer'=>$uid,'uname'=>$uname,'org'=>$org]);
