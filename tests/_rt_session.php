<?php
// Helper for the BOARD REAL-TIME SYNC HTTP test (tests/http-board-sync.sh).
//
// Commands (all run from anywhere; $SH is ~/shuffle):
//   mint <uid>                  -> prints a new DB session id for that user
//   cleanup <sid>               -> deletes that session row
//   http <url> <sid> <bodyOut> [header]
//                                -> performs GET <url>; writes response BODY to
//                                   <bodyOut>, prints the HTTP status (int, one
//                                   line) to stdout. [header] = "Name: value".
//
// Session: mints a fresh row for the harness user (default mya=user 4) — it
// never reads Daniel's (user 1) session. Row format matches the app's `php`
// serialize handler (see include/Shuffle/Core/Session.php read/write):
//   key|type:len:value;
require __DIR__ . '/../include/bootstrap.php';

$pdo = $db->getPdo();
$cmd = $argv[1] ?? '';

switch ($cmd) {

    case 'mint':
        $uid = (int) ($argv[2] ?? 4);
        if (!$db->fetch('SELECT id FROM users WHERE id = ? AND status = ?', [$uid, 'active'])) {
            fwrite(STDERR, "user $uid not active\n"); exit(3);
        }
        $data = 'csrf_token|s:64:"' . str_repeat('a', 64) . '";user_id|i:' . $uid . ';';
        $sid  = bin2hex(random_bytes(16));
        $now  = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO sessions (id, user_id, data, last_activity, created_at) VALUES (?,?,?,?,?)')
            ->execute([$sid, $uid, $data, $now, $now]);
        echo $sid . "\n";
        break;

    case 'cleanup':
        $pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$argv[2] ?? '']);
        echo "ok\n";
        break;

    case 'http':
        $url = $argv[2] ?? '';
        $sid = $argv[3] !== '' ? $argv[3] : null;   // null => no cookie at all
        $out = $argv[4] ?? 'php://output';
        $hdr = $argv[5] ?? null;     // "Name: value"
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($hdr) $opts[CURLOPT_HTTPHEADER] = [$hdr];
        if ($sid !== null) $opts[CURLOPT_COOKIE] = 'shuffle_session=' . $sid;
        else $opts[CURLOPT_COOKIE] = 'shuffle_session=__unauth_probe__'; // definitely no real session
        curl_setopt_array($ch, $opts);
        $resp  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $body = substr($resp, $hsize);
        file_put_contents($out, $body);
        echo $code . "\n";          // stdout = status only
        break;

    default:
        fwrite(STDERR, "unknown cmd: $cmd\n"); exit(2);
}
