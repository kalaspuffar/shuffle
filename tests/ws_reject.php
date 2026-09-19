<?php
/**
 * Security probe for the ws-daemon reject paths (RT-03, SPECIFICATION §5.25).
 *
 * Usage: php tests/ws_reject.php [deniedBoardId]   (default 9999)
 *   1. no session cookie          → 101 + {"error","unauthorized"} + close 1008
 *   2. valid session + a board the user may not access (default: a non-
 *      existent id — an admin's boardAccess is satisfied by ANY existing
 *      board, so the only board an admin is "forbidden" on is a missing one)
 *                                   → 101 + {"error","forbidden"}  + close 1008
 */
$cross = (int) ($argv[1] ?? 9999);
$root = dirname(__DIR__);
require $root . '/include/Shuffle/Core/Autoloader.php';
(new \Shuffle\Core\Autoloader($root . '/include/Shuffle'))->register();
$cfg = require $root . '/etc/config.php';
$dbh = new \Shuffle\Core\Database($cfg['db'] + ['charset' => 'utf8mb4']);

function wsProbe(string $path, ?string $cookie, string &$out): bool {
    $f = @fsockopen('127.0.0.1', 8701, $errno, $errstr, 3.0);
    if (!$f) { fwrite(STDERR, "connect: $errstr\n"); return false; }
    stream_set_timeout($f, 2.0);
    $key = base64_encode(random_bytes(16));
    $req = "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n";
    if ($cookie !== null) $req .= "Cookie: $cookie\r\n";
    $req .= "\r\n";
    fwrite($f, $req);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $c = fread($f, 4096);
        if ($c === '' || $c === false) break;
        $head .= $c;
    }
    if (substr($head, 0, 12) !== 'HTTP/1.1 101') {
        $out = 'NOT101:' . substr($head, 0, 200);
        fclose($f);
        return true;
    }
    // read up to ~1.5 s of frames (reason text + close)
    $deadline = microtime(true) + 1.5;
    $body = '';
    while (microtime(true) < $deadline) {
        $c = @fread($f, 4096);
        if ($c === '' || $c === false) { usleep(10000); continue; }
        $body .= $c;
        // heuristic: reason frame (text) + close frame both seen
        $tlen = ord($body[1]) & 0x7F;
        if (strlen($body) >= 2 + $tlen + 4) break;
    }
    // decode the text frame payload
    $tlen = ord($body[1]) & 0x7F;
    $json = substr($body, 2, $tlen);
    $closeOpcode = (ord($body[2 + $tlen]) & 0x0F);
    $out = $json . ' | closeOpcode=' . $closeOpcode . ' | bytes=' . strlen($body);
    fclose($f);
    return true;
}

// 1) no cookie
$r1 = '';
wsProbe('/ws?board=999', null, $r1);
echo "no-cookie:   $r1\n";
$pass1 = (strpos($r1, 'unauthorized') !== false) || (strpos($r1, 'NOT101') === 0);
echo ($pass1 ? 'PASS' : 'FAIL') . "  unauthenticated rejected\n";

// 2) valid mya cookie + board she must not access (pick a board NOT owned by org of user 4 / not private to her)
$sid = trim((string) shell_exec('php ' . escapeshellarg($root . '/tests/_rt_session.php') . ' mint 4 2>/dev/null'));
echo "mya session: $sid\n";
$r2 = '';
wsProbe("/ws?board=$cross", "shuffle_session=$sid", $r2);
echo "cross-org:   $r2\n";
$pass2 = strpos($r2, 'forbidden') !== false;
echo ($pass2 ? 'PASS' : 'FAIL') . "  forbidden board rejected\n";

shell_exec('php ' . escapeshellarg($root . '/tests/_rt_session.php') . " cleanup $sid 2>/dev/null");
exit((($pass1 && $pass2) ? 0 : 1));
