<?php
// Push contract THROUGH the Apache proxy (port 80, Host: shuffle.ea.org).
// Companion to tests/ws_contract.php (which talks straight to the daemon on
// 8701) — this one verifies the tunnel Daniel enabled: mod_rewrite [P] +
// mod_proxy_wstunnel in www/.htaccess.
$root   = dirname(__DIR__); // repo root (this file lives in tests/)
$helper = $root . '/tests/_rt_session.php';
$sid    = trim((string) shell_exec('php ' . escapeshellarg($helper) . ' mint 4 2>/dev/null'));
echo "session=$sid\n";
if ($sid === '') exit("no session\n");

$sock = stream_socket_client('tcp://127.0.0.1:80', $en, $es, 4.0);
if (!$sock) exit("no connect\n");
stream_set_blocking($sock, false);

$key = base64_encode(random_bytes(16));
$req = "GET /ws?board=1 HTTP/1.1\r\n"
    . "Host: shuffle.ea.org\r\n"
    . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
    . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n"
    . "Cookie: shuffle_session=$sid\r\n\r\n";
fwrite($sock, $req);

$head = '';
$dl = microtime(true) + 3.0;
while (!str_contains($head, "\r\n\r\n") && microtime(true) < $dl) {
    $c = @fread($sock, 8192);
    if ($c !== false && $c !== '') { $head .= $c; } else { usleep(20000); }
}
$status = substr($head, 0, 12);
echo "status: $status\n";
if ($status !== 'HTTP/1.1 101') exit("expected 101 (proxy did not switch protocols)\n");

function frame2($s, ?string &$buf, float $t = 6.0): ?array
{
    if ($buf === null) { $buf = ''; }
    $dl = microtime(true) + $t;
    while (true) {
        if (strlen($buf) >= 2) {
            $ln = ord($buf[1]) & 0x7F; $off = 2;
            if ($ln === 126) {
                if (strlen($buf) < 4) { /* need more bytes */ }
                else { $ln = unpack('n', substr($buf, 2, 2))[1]; $off = 4; }
            }
            if ($ln >= 0 && strlen($buf) >= $off + $ln) {
                $op = ord($buf[0]) & 0x0F;
                $p = substr($buf, $off, $ln);
                $buf = substr($buf, $off + $ln);
                return ['op' => $op, 'p' => $p];
            }
        }
        $c = @fread($s, 65536);
        if ($c !== false && $c !== '') { $buf .= $c; continue; }
        if (feof($s)) { return null; }
        if (microtime(true) >= $dl) { return null; }
        usleep(20000);
    }
}

$rest = '';
$parts = explode("\r\n\r\n", $head, 2);
if (count($parts) === 2) { $rest = $parts[1]; }

// Subscribe-ack (daemon sends the current board version immediately).
$f = frame2($sock, $rest);
if (!$f) exit("no ack frame\n");
$j = json_decode($f['p'], true);
echo "ack: " . $f['p'] . "\n";
$old = (int) $j['version'];

// Bump the board (separate DB connection) via the dedicated test helper.
shell_exec('php ' . escapeshellarg($root . '/bin/test-bump.php') . ' 1 2>&1');
$expectNew = $old + 1;

// The pushed frame must arrive within the 5 s poll window — the whole point
// of RT-03 is that it is far faster than that.
$t0 = microtime(true);
$f2 = frame2($sock, $rest, 8.0);
if (!$f2) exit("no push frame (daemon did not push within timeout)\n");
$latency = microtime(true) - $t0;
echo "push: " . $f2['p'] . " (latency " . sprintf('%.0f', $latency * 1000) . " ms)\n";
$j2 = json_decode($f2['p'], true);
if (($j2['version'] ?? 0) !== $expectNew) {
    exit("FAIL push version " . ($j2['version'] ?? '?') . " != $expectNew\n");
}
echo "PASS  push contract THROUGH APACHE PROXY (" . sprintf('%.0f', $latency * 1000) . " ms — well under the 15 s poll)\n";
fclose($sock);
shell_exec('php ' . escapeshellarg($helper) . " cleanup $sid 2>/dev/null");
exit(0);
