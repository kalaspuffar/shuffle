<?php
// Test helper: bump one board's version (used by tests/ws_proxy_e2e.php).
require dirname(__DIR__) . '/include/bootstrap.php';
(new \Shuffle\Model\Board($db))->incrementVersion((int) $argv[1]);
echo "bumped\n";
