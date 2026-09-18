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

    case 'put':
        // put <url> <sid> <bodyOut> <filePath> <mimeType> <originalName>
        //
        // Raw-binary POST matching the attachment upload contract
        // (POST /v1/cards/{cardId}/attachments, §5.10): the file bytes are
        // the body, metadata rides the X-File-Name / X-File-Size /
        // Content-Type headers, the session's CSRF token is attached.
        // Response body (the 201 JSON with the created attachment) goes to
        // bodyOut so a caller can extract the new id; status to stdout.
        _rt_put_file_upload($argv);
        break;

    case 'hdrs':
        // hdrs <url> <sid> <hdrsOut> [extraHeader]
        // GET request; writes RESPONSE HEADERS (one "Name: value" per line)
        // to hdrsOut and prints the HTTP status to stdout — assertion
        // helper for Content-Disposition / Content-Range / Content-Type
        // (the preview endpoint contract, FILE-06/07 §5.23).
        _rt_get_headers($argv);
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
    echo $code . "\n";
}

/**
 * GET driver that returns RESPONSE HEADERS (not the body).
 *
 *   hdrs <url> <sid> <hdrsOut> [extraHeader]
 *
 * Writes "Name: value" lines to hdrsOut, prints the status (int) to
 * stdout. Uses the same session/CSRF rules as the http driver (sid ""
 * = unauth probe).
 */
function _rt_get_headers(array $argv): void
{
    $url = $argv[2] ?? '';
    $sid = $argv[3] !== '' ? $argv[3] : null;
    $out = $argv[4] ?? 'php://output';

    $csrf = '';
    if ($sid !== null) {
        $row = $GLOBALS['db']->fetch('SELECT data FROM sessions WHERE id = ?', [$sid]);
        if ($row && isset($row['data'])
                && preg_match('/csrf_token\|s:64:"([a-f0-9]{64})"/', $row['data'], $m)) {
            $csrf = $m[1];
        }
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 60,
    ];
    $hdrs = [];
    if ($csrf !== '') $hdrs[] = 'X-CSRF-Token: ' . $csrf;
    if (isset($argv[5]) && $argv[5] !== '') $hdrs[] = $argv[5];
    if ($hdrs) $opts[CURLOPT_HTTPHEADER] = $hdrs;
    if ($sid !== null) $opts[CURLOPT_COOKIE] = 'shuffle_session=' . $sid;
    else               $opts[CURLOPT_COOKIE] = 'shuffle_session=__unauth_probe__';
    curl_setopt_array($ch, $opts);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlock = is_string($resp) ? substr($resp, 0, $hsize) : '';
    // Drop the status line itself; keep the raw header lines — the test
    // asserts on exact names/values (case-insensitive grep from its side).
    $lines = array_filter(explode("\r\n", $headerBlock), function ($l) {
        $l = trim($l);
        return $l !== '' && !preg_match('/^HTTP\//', $l);
    });
    file_put_contents($out, implode("\n", $lines) . "\n");
    echo $code . "\n";
}

/**
 * Raw-binary upload driver for the attachment endpoint (§5.10 contract).
 *
 *   put <url> <sid> <bodyOut> <filePath> <mimeType> <originalName>
 *
 * Reads the local file, posts the bytes as the request body with the
 * server's attachment contract — Content-Type (the MIME), X-File-Name
 * (URL-encoded original name), X-File-Size (byte count) plus the session
 * CSRF token. Sid "" = no session (unauth probe). Response body →
 * bodyOut; status (int) → stdout.
 */
function _rt_put_file_upload(array $argv): void
{
    $url      = $argv[2] ?? '';
    $sid      = $argv[3] !== '' ? $argv[3] : null;
    $out      = $argv[4] ?? 'php://output';
    $filePath = $argv[5] ?? '';
    $mimeType = $argv[6] ?? 'application/octet-stream';
    $name     = $argv[7] ?? 'upload';

    if (!is_file($filePath)) { fwrite(STDERR, "put: no such file: $filePath\n"); exit(3); }
    $bytes = file_get_contents($filePath);
    if ($bytes === false) { fwrite(STDERR, "put: cannot read $filePath\n"); exit(3); }
    $size = strlen($bytes);

    $csrf = '';
    if ($sid !== null) {
        $row = $GLOBALS['db']->fetch('SELECT data FROM sessions WHERE id = ?', [$sid]);
        if ($row && isset($row['data'])
                && preg_match('/csrf_token\|s:64:"([a-f0-9]{64})"/', $row['data'], $m)) {
            $csrf = $m[1];
        }
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POSTFIELDS     => $bytes,
    ];
    $hdrs = [
        'Content-Type: ' . $mimeType,
        'X-File-Name: ' . rawurlencode($name),
        'X-File-Size: ' . $size,
    ];
    if ($csrf !== '') $hdrs[] = 'X-CSRF-Token: ' . $csrf;
    $opts[CURLOPT_HTTPHEADER] = $hdrs;
    if ($sid !== null) $opts[CURLOPT_COOKIE] = 'shuffle_session=' . $sid;
    else               $opts[CURLOPT_COOKIE] = 'shuffle_session=__unauth_probe__';
    curl_setopt_array($ch, $opts);
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    file_put_contents($out, is_string($resp) ? substr($resp, $hsize) : '');
    echo $code . "\n";
}
