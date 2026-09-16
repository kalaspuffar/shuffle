<?php
// Helper for Shuffle live-Apache HTTP tests (tests/http-*.sh).
//
// Commands (all run from anywhere; $SH is ~/shuffle):
//   mint <uid>                  -> prints a new DB session id for that user
//   cleanup <sid>               -> deletes that session row
//   http <url> <sid> <bodyOut>  -> performs GET <url>; writes response BODY to
//                                  <bodyOut>, prints the HTTP status (int, one
//                                  line) to stdout. (sid "" = unauth probe)
//   post <url> <sid> <bodyOut> <jsonBody>
//                                -> performs POST with the JSON body; sid is
//                                   "" for an unauth probe. Auto-attaches the
//                                   session's X-CSRF-Token (read back from the
//                                   minted row, so it always matches).
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
        // http <url> <sid> <bodyOut> ["Name: value"]
        _rt_do_request($argv, 'GET', null, $argv[5] ?? null);
        break;

    case 'post':
        // post <url> <sid> <bodyOut> <jsonBody>
        _rt_do_request($argv, 'POST', $argv[5] ?? '{}', null);
        break;

    default:
        fwrite(STDERR, "unknown cmd: $cmd\n"); exit(2);
}

/**
 * Shared curl driver for http/post.
 *   _rt_do_request($argv, method, jsonBody, extraHeader)
 *     argv = [script, cmd, url, sid, bodyOut, ...]
 *   - jsonBody   : POST body (null for GET)
 *   - extraHeader: "Name: value" (GET only; e.g. If-None-Match)
 * Response BODY written to bodyOut; the HTTP status (int, one line) is
 * printed to stdout. sid "" (or null) => no real session (unauth probe).
 */
function _rt_do_request(array $argv, string $method, ?string $jsonBody, ?string $extraHeader): void
{
    $url = $argv[2] ?? '';
    $sid = $argv[3] !== '' ? $argv[3] : null;
    $out = $argv[4] ?? 'php://output';

    // The token must match the session row's csrf_token — read it back from
    // the DB (mint writes it there), never trust a stale constant.
    $csrf = '';
    if ($sid !== null) {
        $row = $GLOBALS['db']->fetch('SELECT data FROM sessions WHERE id = ?', [$sid]);
        if ($row && isset($row['data'])
                && preg_match('/csrf_token\|s:64:"([a-f0-9]{64})"/', $row['data'], $m)) {
            $csrf = $m[1];
        }
    }

    $ch   = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    $hdrs = [];
    if ($csrf !== '') $hdrs[] = 'X-CSRF-Token: ' . $csrf;
    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        $hdrs[] = 'Content-Type: application/json';
    }
    // GET extra-header support (board-sync If-None-Match, etc.).
    if ($method === 'GET' && $extraHeader !== null && $extraHeader !== '') {
        $hdrs[] = $extraHeader;
    }
    if ($hdrs) $opts[CURLOPT_HTTPHEADER] = $hdrs;
    if ($sid !== null) $opts[CURLOPT_COOKIE] = 'shuffle_session=' . $sid;
    else              $opts[CURLOPT_COOKIE] = 'shuffle_session=__unauth_probe__';
    curl_setopt_array($ch, $opts);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    file_put_contents($out, is_string($resp) ? substr($resp, $hsize) : '');
    echo $code . "\n";            // stdout = status only
}
