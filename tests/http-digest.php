<?php
declare(strict_types=1);
/**
 * HTTP E2E for the Priority Digest API (PRIO-12..14, §5.16).
 *
 * Requires the php -S test stack:
 *   php -S 127.0.0.1:8599 -t www tests/router.php
 * (or any matching PORT env var)
 *
 * Exercises:
 *   [A] GET /v1/priority/digest unauth  → 401
 *   [B] Login (temp user)               → 200 + csrf_token
 *   [C] Auth GET (default n, json)      → 200, shape verified
 *   [D] Auth GET n=1 / n=0 / n=999      → clamp behavior (0→1, 999→50)
 *   [E] Auth GET format=markdown        → 200, text/markdown, non-empty
 *   [F] Auth GET format=banana          → 400 (enum, not a filter)
 *   [G] Priority page renders digest bar markup
 *
 * Fixtures (temp user + board + lanes + cards + prio rows) are created
 * and cleaned up in finally/shutdown.  Usage:
 *   php tests/http-digest.php [password]   (default: random)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$runId = bin2hex(random_bytes(4));
$pw    = $argv[1] ?? ('dig-' . $runId);

$orgName  = 'DigOrg-' . $runId;
$userName = 'dig_user_' . $runId;
$boardTtl = 'Dig Board ' . $runId;

$created = ['board_id' => null, 'lanes' => [], 'lane_done' => null,
            'cards' => [], 'user_id' => null, 'org_id' => null];

function cleanup(): void
{
    global $db, $created;
    if ($created['cards']) {
        $ph = implode(',', array_fill(0, count($created['cards']), '?'));
        $db->execute("DELETE FROM cards WHERE id IN ($ph)", $created['cards']);
    }
    if ($created['lanes']) {
        $ph = implode(',', array_fill(0, count($created['lanes']), '?'));
        $db->execute("DELETE FROM lanes WHERE id IN ($ph)", $created['lanes']);
    }
    if ($created['board_id']) {
        $db->execute('DELETE FROM boards WHERE id = ?', [$created['board_id']]);
    }
    if ($created['user_id']) {
        $db->execute('DELETE FROM users WHERE id = ?', [$created['user_id']]);
    }
    if ($created['org_id']) {
        $db->execute('DELETE FROM organizations WHERE id = ?', [$created['org_id']]);
    }
}
register_shutdown_function('cleanup');

// ---------------------------------------------------------------------------
// Fixtures: temp org + member + board + 2 lanes (Inbox + Done) + 2 cards,
// both cards prioritized for the temp user.
// ---------------------------------------------------------------------------
$org = $db->fetch('SELECT id FROM organizations WHERE name = ?', [$orgName]);
if ($org === null) {
    $db->execute('INSERT INTO organizations (name, created_at, updated_at) VALUES (?, NOW(), NOW())', [$orgName]);
    $orgId = (int) $db->lastInsertId();
} else {
    $orgId = (int) $org['id'];
}
$created['org_id'] = $orgId;

$hash = password_hash($pw, PASSWORD_DEFAULT);
$db->execute(
    'INSERT INTO users (username, password_hash, name, email, role, organization_id, is_placeholder, status)
     VALUES (?, ?, ?, ?, ?, ?, 0, "active")',
    [$userName, $hash, 'Digest Temp', 'dig-' . $runId . '@example.invalid', 'member', $orgId]
);
$uid = (int) $db->lastInsertId();
$created['user_id'] = $uid;

$db->execute(
    'INSERT INTO boards (title, visibility, is_archived, version, created_by) VALUES (?, "organization", 0, 1, ?)',
    [$boardTtl, $uid]
);
$bid = (int) $db->lastInsertId();
$created['board_id'] = $bid;
$db->execute('INSERT INTO board_organizations (board_id, organization_id) VALUES (?, ?)', [$bid, $orgId]);

$db->execute('INSERT INTO lanes (board_id, title, icon, position) VALUES (?, ?, ?, 1000)', [$bid, 'Inbox', '📥']);
$li    = (int) $db->lastInsertId();
$db->execute('INSERT INTO lanes (board_id, title, icon, position) VALUES (?, ?, ?, 2000)', [$bid, 'Done', '✅']);
$ld    = (int) $db->lastInsertId();
$created['lanes'] = [$li, $ld];
$created['lane_done'] = $ld;

for ($n = 1; $n <= 2; $n++) {
    $db->execute(
        'INSERT INTO cards (lane_id, title, description, due_date, position, created_by)
         VALUES (?, ?, NULL, NULL, ?, ?)',
        [$li, "Dig #$n $runId", $n * 1000, $uid]
    );
    $cid = (int) $db->lastInsertId();
    $created['cards'][] = $cid;
    $db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cid, $uid]);
    $db->execute(
        'INSERT INTO user_prio (user_id, card_id, position) VALUES (?, ?, ?)',
        [$uid, $cid, $n * 1000]
    );
}

// ---------------------------------------------------------------------------
// HTTP harness
// ---------------------------------------------------------------------------
$base = 'http://127.0.0.1:' . (getenv('PORT') ?: '8599');
$cookieJar = tempnam(sys_get_temp_dir(), 'dig-cookies-');

function http(string $method, string $url, array $headers, ?array $body, string $jar): array
{
    $ch = curl_init($url);
    $hdrs = ['Accept: application/json', 'Content-Type: application/json'];
    foreach ($headers as $k => $v) { $hdrs[] = "$k: $v"; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $sep = strpos($raw, "\r\n\r\n");
    $respHdrs = $sep !== false ? substr($raw, 0, $sep) : '';
    $respBody = $sep !== false ? substr($raw, $sep + 4) : $raw;
    $ct = '';
    foreach (explode("\r\n", $respHdrs) as $line) {
        if (stripos($line, 'content-type:') === 0) { $ct = trim(substr($line, 13)); }
    }
    return ['status' => $status, 'body' => $respBody, 'headers' => $respHdrs, 'ct' => $ct, 'error' => $curlErr];
}

$checks = 0; $failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) { echo "  ok:   $name\n"; }
    else     { $failures++; echo "  FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function httpDigest(string $query, ?array $headers, ?array $body, string $jar, string $base): array
{
    return http('GET', $base . '/v1/priority/digest' . ($query ? '?' . $query : ''),
                $headers ?? [], $body, $jar);
}

try {
    // [A] unauth
    echo "\n[A] Unauthenticated\n";
    $r = httpDigest('', null, null, $cookieJar, $base);
    check('GET /v1/priority/digest unauth → 401', $r['status'] === 401, "got {$r['status']}");

    // [B] login
    echo "\n[B] Login\n";
    $r = http('POST', $base . '/v1/auth/login', [], ['username' => $userName, 'password' => $pw], $cookieJar);
    check('login 200', $r['status'] === 200, "got {$r['status']} body=" . substr($r['body'], 0, 120));
    $login = json_decode($r['body'], true) ?: [];
    $csrf  = (string) ($login['csrf_token'] ?? '');
    check('csrf_token returned', strlen($csrf) > 0);
    $uidApi = (int) ($login['user']['id'] ?? 0);
    check('user id matches fixture', $uidApi === (int) $created['user_id'], "got $uidApi expected " . (int) $created['user_id']);

    // [C] auth default
    echo "\n[C] GET default (n=5, json)\n";
    $r = httpDigest('', null, null, $cookieJar, $base);
    check('GET default 200', $r['status'] === 200, "got {$r['status']} body={$r['body']}");
    $data = json_decode($r['body'], true) ?: [];
    check('n field present', array_key_exists('n', $data), 'keys=' . implode(',', array_keys($data)));
    $top = (array) ($data['top'] ?? []);
    $done = (array) ($data['done_yesterday'] ?? []);
    check('top has 2 cards (fixture)', count($top) === 2, 'count=' . count($top));
    check('top card_id[0] in fixture set', in_array((int) ($top[0]['card_id'] ?? 0), array_map('intval', $created['cards']), true));
    check('top card_title matches', isset($top[0]['card_title']) && str_starts_with((string) $top[0]['card_title'], 'Dig #1'), 'title=' . ($top[0]['card_title'] ?? ''));
    check('top card_html is deep link', str_starts_with((string) ($top[0]['card_html'] ?? ''), '/card.php?id='));
    check('state_marker string present', is_string($top[0]['state_marker'] ?? null));
    check('done_yesterday is array (empty is fine — no yesterday log)', is_array($done));
    check('window present with since/until', isset($data['window']['since'], $data['window']['until']));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    check('window since is yesterday', substr((string) ($data['window']['since'] ?? ''), 0, 10) === $yesterday, 'since=' . ($data['window']['since'] ?? ''));

    // [D] clamping
    echo "\n[D] Clamping\n";
    $r1 = httpDigest('n=1', null, null, $cookieJar, $base);
    check('n=1 → 200, top=1', $r1['status'] === 200 && count(json_decode($r1['body'], true)['top'] ?? []) === 1,
          "status={$r1['status']} body={$r1['body']}");

    $r0 = httpDigest('n=0', null, null, $cookieJar, $base);
    check('n=0 → 200 (clamped to 1, not 400)', $r0['status'] === 200, "got {$r0['status']}");
    check('n=0 → n field = 1 (clamped)', ((int) (json_decode($r0['body'], true)['n'] ?? 0)) === 1,
          'n=' . (json_decode($r0['body'], true)['n'] ?? null));

    $r999 = httpDigest('n=999', null, null, $cookieJar, $base);
    check('n=999 → 200, n clamped to 50', $r999['status'] === 200
          && ((int) (json_decode($r999['body'], true)['n'] ?? 0)) === 50,
          "n=" . (json_decode($r999['body'], true)['n'] ?? null));

    $rNeg = httpDigest('n=-5', null, null, $cookieJar, $base);
    check('n=-5 → 200, n clamped to 1', $rNeg['status'] === 200
          && ((int) (json_decode($rNeg['body'], true)['n'] ?? 0)) === 1,
          "n=" . (json_decode($rNeg['body'], true)['n'] ?? null));

    // [E] markdown
    echo "\n[E] format=markdown\n";
    $rMd = httpDigest('format=markdown', null, null, $cookieJar, $base);
    check('markdown 200', $rMd['status'] === 200, "got {$rMd['status']}");
    check('content-type text/markdown', stripos($rMd['ct'], 'text/markdown') !== false, 'ct=' . $rMd['ct']);
    $md = $rMd['body'];
    check('markdown body non-empty', strlen(trim($md)) > 0);
    check('markdown has "My top" heading', str_contains($md, '**My top'), 'md=' . $md);
    check('markdown has "Done yesterday" heading', str_contains($md, 'Done yesterday'), 'md=' . $md);
    check('markdown done section has "(none)" (no yesterday log)', str_contains($md, '(none)'), 'md=' . $md);
    check('markdown top card title visible', str_contains($md, 'Dig #1 ' . $runId), 'md=' . $md);
    check('markdown ends with newline', str_ends_with($md, "\n"));

    // markdown with n=1
    $rMd1 = httpDigest('n=1&format=markdown', null, null, $cookieJar, $base);
    check('markdown n=1 200', $rMd1['status'] === 200, 'status=' . $rMd1['status']);
    $md1 = $rMd1['body'];
    check('markdown n=1 heading "My top 1"', str_contains($md1, '**My top 1**'), 'md=' . $md1);
    check('markdown n=1 shows Dig #1', str_contains($md1, 'Dig #1 ' . $runId));
    check('markdown n=1 does NOT show Dig #2 in top section', !preg_match('/Dig #2 ' . preg_quote($runId, '/') . ' — \*/m', $md1), 'md=' . $md1);

    // [F] bad format
    echo "\n[F] Invalid format\n";
    $rBad = httpDigest('format=banana', null, null, $cookieJar, $base);
    check('format=banana → 400 (enum, not a filter)', $rBad['status'] === 400, "got {$rBad['status']} body={$rBad['body']}");
    $badData = json_decode($rBad['body'], true) ?: [];
    check('error message mentions format', stripos((string) ($badData['error'] ?? ''), 'format') !== false,
          'error=' . ($badData['error'] ?? ''));

    // [G] page renders digest bar
    echo "\n[G] Priority page renders digest bar\n";
    $rPage = http('GET', $base . '/priority.php', [], null, $cookieJar);
    check('priority.php 200', $rPage['status'] === 200, "got {$rPage['status']}");
    check('digest bar container present', str_contains($rPage['body'], 'id="priority-digest"'), 'snippet=' . substr($rPage['body'], 0, 200));
    check('digest N input present', str_contains($rPage['body'], 'id="priority-digest-n"'));
    check('digest copy button present', str_contains($rPage['body'], 'id="priority-digest-copy"'));
    check('digest fallback <pre> present', str_contains($rPage['body'], 'id="priority-digest-body"'));
    check('priority.js script tag present', str_contains($rPage['body'], '/js/priority.js'));

    // [H] JS bundle has digest handler
    echo "\n[H] priority.js has digest handler\n";
    $rJs = http('GET', $base . '/js/priority.js', [], null, $cookieJar);
    check('priority.js 200', $rJs['status'] === 200, 'status=' . $rJs['status']);
    check('JS references /v1/priority/digest', str_contains($rJs['body'], '/v1/priority/digest'));
    check('JS has clampN function', str_contains($rJs['body'], 'clampN'));
    check('JS has navigator.clipboard', str_contains($rJs['body'], 'navigator.clipboard'));
    check('JS has initDigest IIFE', str_contains($rJs['body'], 'initDigest'));
    check('JS has copyBtn.addEventListener', str_contains($rJs['body'], 'copyBtn.addEventListener'));

    // [I] Response::text content-type override (spec: text/markdown;charset)
    echo "\n[I] Response::text contract\n";
    $rMd2 = httpDigest('format=markdown', null, null, $cookieJar, $base);
    check('charset=utf-8 in content-type', stripos($rMd2['ct'], 'charset=utf-8') !== false, 'ct=' . $rMd2['ct']);
    check('cache-control no-cache present', stripos($rMd2['headers'], 'no-cache') !== false, 'hdrs=' . $rMd2['headers']);

    // [J] Priority page's digest <pre> is server-rendered (not JS-injected).
    echo "\n[J] Fallback body is server-rendered\n";
    check('digest <pre> markup present on the page', str_contains($rPage['body'], 'class="priority-digest-body" id="priority-digest-body"')
        || str_contains($rPage['body'], 'id="priority-digest-body"'), 'body=' . substr($rPage['body'], 0, 300));

} finally {
    cleanup();
}

echo "\n" . str_repeat('—', 50) . "\n";
if ($failures === 0) { echo "ALL $checks HTTP-DIGEST CHECKS PASSED\n"; exit(0); }
echo "$failures of $checks HTTP-DIGEST CHECKS FAILED\n";
exit(1);
