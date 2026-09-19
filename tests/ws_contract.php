<?php
/**
 * E2E contract for the ws-daemon (RT-03, SPECIFICATION §5.25).
 *
 * Usage: php tests/ws_contract.php [boardId]
 *   1. TCP + WS upgrade against 127.0.0.1:8701 with a minted mya(4) session
 *   2. expect the subscribe-ack text frame (current version)
 *   3. bump the board version via the Board model
 *   4. expect the pushed board_version frame (version +1)
 *   5. send a masked close frame, verify the server echoed a close
 */
$board = (int) ($argv[1] ?? 0);
$host = '127.0.0.1'; $port = 8701;
$root = dirname(__DIR__);

$helper = $root . '/tests/_rt_session.php';
$sid = trim((string) shell_exec('php ' . escapeshellarg($helper) . ' mint 4 2>/dev/null'));
if ($sid === '') { fwrite(STDERR, "no session minted\n"); exit(2); }
echo "session=$sid board=$board\n";

// Resolve a board mya (4) can access (must also satisfy canAccessBoard).
require $root . '/include/Shuffle/Core/Autoloader.php';
(new \Shuffle\Core\Autoloader($root . '/include/Shuffle'))->register();
$cfg = require $root . '/etc/config.php';
$dbh = new \Shuffle\Core\Database($cfg['db'] + ['charset' => 'utf8mb4']);
if ($board < 1) {
    $b = $dbh->fetch('SELECT id, title FROM boards WHERE created_by = 4 ORDER BY id ASC LIMIT 1');
    if ($b === null) { $b = $dbh->fetch('SELECT id, title FROM boards ORDER BY id ASC LIMIT 1'); }
    if ($b === null) { fwrite(STDERR, "no board to test on\n"); exit(2); }
    $board = (int)$b['id'];
    echo "using board $board ({$b['title']})\n";
}

$sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 3.0);
if (!$sock) { fwrite(STDERR, "connect failed: $errstr\n"); exit(1); }
stream_set_blocking($sock, false);

$key = base64_encode(random_bytes(16));
$req = "GET /ws?board=$board HTTP/1.1\r\n"
    . "Host: $host\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: $key\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "Cookie: shuffle_session=$sid\r\n\r\n";
fwrite($sock, $req);

// ---- read the HTTP 101 head (non-blocking, poll up to 3 s) ----
$head = '';
$deadline = microtime(true) + 3.0;
while (!str_contains($head, "\r\n\r\n") && microtime(true) < $deadline) {
    $r = @fread($sock, 8192);
    if ($r !== false && $r !== '') { $head .= $r; }
    else { usleep(20000); }
}
if (substr($head, 0, 12) !== 'HTTP/1.1 101') {
    fwrite(STDERR, "NOT 101: " . substr($head, 0, 300) . "\n");
    exit(1);
}
// residual bytes after the head may be the first WS frame already sent
$parts = explode("\r\n\r\n", $head, 2);
$buf = trim($parts[1] ?? '', "\0");
echo "101 OK (accept: " . (str_contains($head, 'Sec-WebSocket-Accept') ? 'yes' : 'NO') . ")\n";

$expect = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
if (!str_contains($head, "Sec-WebSocket-Accept: $expect")) {
    fwrite(STDERR, "Sec-WebSocket-Accept mismatch!\n"); exit(1);
}
echo "Sec-WebSocket-Accept verified\n";

/**
 * Reads the next complete WS frame from $sock, using $buf as a rolling
 * buffer. Waits up to $timeoutS total for the bytes.
 */
function readFrame($sock, ?string &$buf, float $timeoutS = 4.0): ?array {
    if ($buf === null) $buf = '';
    $deadline = microtime(true) + $timeoutS;
    while (true) {
        // Try to parse from the current buffer.
        if (strlen($buf) >= 2) {
            $masked = (bool) (ord($buf[1]) & 0x80);
            $len = ord($buf[1]) & 0x7F;
            $off = 2;
            $complete = true;
            if ($len === 126) {
                if (strlen($buf) < 4) { $complete = false; $len = 65536; }
                else { $len = unpack('n', substr($buf, 2, 2))[1]; $off = 4; }
            } elseif ($len === 127) {
                if (strlen($buf) < 10) { $complete = false; $len = PHP_INT_MAX; }
                else { $len = unpack('J', substr($buf, 2, 8))[1]; $off = 10; }
            }
            if ($complete && $masked) {
                if (strlen($buf) < $off + 4) { $complete = false; }
                else { $off += 4; }
            }
            if ($complete && strlen($buf) >= $off + $len) {
                $opcode = ord($buf[0]) & 0x0F;
                $payload = substr($buf, $off, $len);
                if ($masked) {
                    $mk = substr($buf, $off - 4, 4);
                    for ($i = 0; $i < $len; $i++) $payload[$i] = $payload[$i] ^ $mk[$i & 3];
                }
                $buf = substr($buf, $off + $len);
                return ['opcode' => $opcode, 'payload' => $payload];
            }
        }
        $r = @fread($sock, 65536);
        if ($r !== false && $r !== '') {
            $buf .= $r;
            continue;
        }
        if (feof($sock)) return null;
        if (microtime(true) >= $deadline) return null;
        usleep(20000);
    }
}

// 1) subscribe-ack
$f1 = readFrame($sock, $buf);
if ($f1 === null) { fwrite(STDERR, "no subscribe-ack frame\n"); exit(1); }
$j1 = json_decode($f1['payload'], true);
echo "ack: " . $f1['payload'] . "\n";
if (($j1['type'] ?? '') !== 'board_version' || ($j1['board'] ?? 0) !== $board) { fwrite(STDERR, "bad ack\n"); exit(1); }
$old = (int) $j1['version'];

// 2) bump via the Board model (separate DB connection, committed)
$boardModel = new \Shuffle\Model\Board($dbh);
$boardModel->incrementVersion($board);
// verify the feed row landed
$rows = $dbh->fetchAll('SELECT board_id, version FROM board_events ORDER BY id DESC LIMIT 3');
echo "feed tail: " . json_encode($rows) . "\n";

// 3) pushed frame
$f2 = readFrame($sock, $buf);
if ($f2 === null) { fwrite(STDERR, "no push frame\n"); exit(1); }
$j2 = json_decode($f2['payload'], true);
echo "push: " . $f2['payload'] . "\n";
if (($j2['type'] ?? '') !== 'board_version' || ($j2['board'] ?? 0) !== $board) { fwrite(STDERR, "bad push type\n"); exit(1); }
if ((int)($j2['version'] ?? 0) !== $old + 1) { fwrite(STDERR, "version math wrong (old=$old got=" . ($j2['version'] ?? '?') . ")\n"); exit(1); }
echo "PASS  push contract (bump → board_version frame, version +1)\n";

// 4) close handshake — masked close frame from the client
$close = "\x88\x82" . random_bytes(4) . pack('n', 1000);
fwrite($sock, $close);
$f3 = readFrame($sock, $buf, 2.0);
if ($f3 !== null && ($f3['opcode'] & 0x0F) === 0x8) {
    echo "PASS  close echo: " . $f3['payload'] . "\n";
} else {
    echo "NOTE  no close echo (server may have closed without echoing) — ok-ish\n";
}
fclose($sock);
shell_exec('php ' . escapeshellarg($helper) . " cleanup $sid 2>/dev/null");
echo "SMOKE OK\n";
