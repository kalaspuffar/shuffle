<?php
declare(strict_types=1);
/**
 * HTTP E2E for the Personal Priority List.
 *
 * Creates a TEMPORARY fixture (user:org, board:organization, lanes, cards,
 * assignment), then curls through the real PHP stack (login → API → page
 * render). Cleans up EVERYTHING it created in `finally` + shutdown.
 *
 *   Usage:  php tests/http-e2e.php [password]   (default: a random one is set)
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

$runId = bin2hex(random_bytes(4));
$pw    = $argv[1] ?? ('prio-' . $runId);

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$orgName  = 'PrioOrg-' . $runId;
$userName = 'prio_user_' . $runId;
$boardTtl = 'Prio Board ' . $runId;
$laneInbox  = 'Inbox';
$laneProg   = 'In Progress';

$created = ['board_id' => null, 'lane_inbox' => null, 'lane_prog' => null,
            'cards' => [], 'user_id' => null, 'org_id' => null];

try {
    $org = $db->fetch('SELECT id FROM organizations WHERE name = ?', [$orgName]);
    if ($org === null) {
        $db->execute('INSERT INTO organizations (name, created_at, updated_at) VALUES (?, NOW(), NOW())', [$orgName]);
        $orgId = (int) $db->lastInsertId();
    } else {
        $orgId = (int) $org['id'];
    }
    $created['org_id'] = $orgId;

    // Temp user (member)
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $db->execute(
        'INSERT INTO users (username, password_hash, name, email, role, organization_id, is_placeholder, status)
         VALUES (?, ?, ?, ?, ?, ?, 0, "active")',
        [$userName, $hash, 'Prio Temp', 'prio-' . $runId . '@example.invalid', 'member', $orgId]
    );
    $uid = (int) $db->lastInsertId();
    $created['user_id'] = $uid;

    // Org-visible board owned by the temp user
    $db->execute(
        'INSERT INTO boards (title, visibility, is_archived, version, created_by) VALUES (?, "organization", 0, 1, ?)',
        [$boardTtl, $uid]
    );
    $bid = (int) $db->lastInsertId();
    $created['board_id'] = $bid;
    $db->execute('INSERT INTO board_organizations (board_id, organization_id) VALUES (?, ?)', [$bid, $orgId]);

    // Lanes: Inbox + In Progress
    $db->execute('INSERT INTO lanes (board_id, title, icon, position) VALUES (?, ?, ?, 1000)', [$bid, $laneInbox, '📥']);
    $li = (int) $db->lastInsertId();
    $created['lane_inbox'] = $li;
    $db->execute('INSERT INTO lanes (board_id, title, icon, position) VALUES (?, ?, ?, 2000)', [$bid, $laneProg, '🔨']);
    $lp = (int) $db->lastInsertId();
    $created['lane_prog'] = $lp;

    // Cards: 2 in Inbox, 1 in In Progress, all assigned to the temp user
    $cardTitles = [
        'Prio A ' . $runId => $li,
        'Prio B ' . $runId => $li,
        'Prio C ' . $runId => $lp,
    ];
    $pos = 1000;
    foreach ($cardTitles as $ttl => $laneId) {
        $db->execute(
            'INSERT INTO cards (lane_id, title, description, due_date, position, is_archived, created_by)
             VALUES (?, ?, "", NULL, ?, 0, ?)',
            [$laneId, $ttl, $pos, $uid]
        );
        $cid = (int) $db->lastInsertId();
        $created['cards'][] = $cid;
        $db->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cid, $uid]);
        $pos += 1000;
    }

    // ---------------------------------------------------------------------
    // Run the curl-based HTTP test
    // ---------------------------------------------------------------------
    $base = 'http://127.0.0.1:' . (getenv('PORT') ?: '8599');
    $cookieJar = tempnam(sys_get_temp_dir(), 'prio-cookies-');

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

        return ['status' => $status, 'body' => $respBody, 'headers' => $respHdrs, 'error' => $curlErr];
    }

    $checks = 0; $failures = 0;
    function check(string $name, bool $ok, string $detail = ''): void
    {
        global $checks, $failures;
        $checks++;
        if ($ok) {
            echo "  ok:   $name\n";
        } else {
            $failures++;
            echo "  FAIL: $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        }
    }

    // ---------------------------------------------------------------
    echo "[A] Unauthenticated\n";
    $r = http('GET', $base . '/v1/priority', [], null, $cookieJar);
    check('GET /v1/priority unauth → 401', $r['status'] === 401, "got {$r['status']} {$r['error']}");

    // ---------------------------------------------------------------
    echo "\n[B] Login\n";
    $r = http('POST', $base . '/v1/auth/login', [], ['username' => $userName, 'password' => $pw], $cookieJar);
    check('login 200', $r['status'] === 200, "got {$r['status']} {$r['error']} body=" . substr($r['body'], 0, 200));
    $login = json_decode($r['body'], true) ?: [];
    $csrf = (string) ($login['csrf_token'] ?? '');
    check('login returns csrf_token', strlen($csrf) > 0);
    check('login returns user with id', (int) ($login['user']['id'] ?? 0) > 0);

    // ---------------------------------------------------------------
    echo "\n[C] GET /v1/priority (authed)\n";
    $r = http('GET', $base . '/v1/priority', [], null, $cookieJar);
    check('authed GET 200', $r['status'] === 200, "got {$r['status']} {$r['error']}");
    $list = json_decode($r['body'], true);
    check('response has inbox + prioritized', is_array($list) && array_key_exists('inbox', $list) && array_key_exists('prioritized', $list));
    check('inbox has 3', is_array($list['inbox'] ?? null) && count($list['inbox']) === 3, 'count=' . (is_array($list['inbox'] ?? null) ? count($list['inbox']) : '?'));
    check('prioritized starts empty', (is_array($list['prioritized'] ?? null) && $list['prioritized'] === []));

    // Tier ordering: In Progress (tier 1) before Inbox (tier 2)
    $tiers = array_map(fn($i) => $i['tier'] ?? 0, (array)($list['inbox'] ?? []));
    $sorted = $tiers; sort($sorted);
    check('inbox tiers non-decreasing', $tiers === $sorted, json_encode($tiers));

    // ---------------------------------------------------------------
    echo "\n[D] Prioritize via API\n";
    // Find a card from the inbox
    $inbox = (array)($list['inbox'] ?? []);
    $c1 = $inbox[0]['card_id'] ?? null;
    $c2 = $inbox[1]['card_id'] ?? null;

    // No CSRF → 403
    $r = http('POST', $base . "/v1/priority/inbox/$c1", [], null, $cookieJar);
    check('POST without CSRF → 403', $r['status'] === 403, "got {$r['status']}");

    // With CSRF → 200
    $r = http('POST', $base . "/v1/priority/inbox/$c1", ['X-CSRF-Token' => $csrf], null, $cookieJar);
    check('prioritize c1 → 200 with position', $r['status'] === 200 && isset(json_decode($r['body'], true)['position']), "got {$r['status']} " . substr($r['body'],0,120));

    $r = http('POST', $base . "/v1/priority/inbox/$c2", ['X-CSRF-Token' => $csrf], null, $cookieJar);
    check('prioritize c2 → 200', $r['status'] === 200, "got {$r['status']}");

    // Idempotent: re-prioritize c1 → still ok
    $r = http('POST', $base . "/v1/priority/inbox/$c1", ['X-CSRF-Token' => $csrf], null, $cookieJar);
    check('re-prioritize c1 idempotent → 200', $r['status'] === 200, "got {$r['status']}");

    // ---------------------------------------------------------------
    echo "\n[E] Verify list updated\n";
    $r = http('GET', $base . '/v1/priority', [], null, $cookieJar);
    $list2 = json_decode($r['body'], true);
    $prio = (array)($list2['prioritized'] ?? []);
    check('prioritized now has 2', count($prio) === 2, 'count=' . count($prio));
    $gotIds = array_map(fn($i) => (int)$i['card_id'], $prio);
    sort($gotIds);
    $want = [(int)$c1, (int)$c2]; sort($want);
    check('prioritized set == {c1, c2}', $gotIds === $want, 'got=' . json_encode($gotIds) . " want=" . json_encode($want));
    check('inbox shrank to 1', count((array)($list2['inbox'] ?? [])) === 1, 'count=' . count((array)($list2['inbox'] ?? [])));

    // ---------------------------------------------------------------
    echo "\n[F] Reorder\n";
    // Move c2 to top (after=null)
    $r = http('PUT', $base . '/v1/priority/position', ['X-CSRF-Token' => $csrf],
              ['card_id' => (int)$c2, 'after_card_id' => null], $cookieJar);
    check('reorder c2 to top → 200', $r['status'] === 200, "got {$r['status']} " . substr($r['body'],0,120));

    $r = http('GET', $base . '/v1/priority', [], null, $cookieJar);
    $l3 = json_decode($r['body'], true);
    $p3 = (array)($l3['prioritized'] ?? []);
    check('c2 is now first', (int)($p3[0]['card_id'] ?? 0) === (int)$c2);

    // Move c2 back after c1
    $r = http('PUT', $base . '/v1/priority/position', ['X-CSRF-Token' => $csrf],
              ['card_id' => (int)$c2, 'after_card_id' => (int)$c1], $cookieJar);
    check('reorder c2 after c1 → 200', $r['status'] === 200);

    $r = http('GET', $base . '/v1/priority', [], null, $cookieJar);
    $l4 = json_decode($r['body'], true);
    $p4 = (array)($l4['prioritized'] ?? []);
    check('c1 first, c2 second', (int)($p4[0]['card_id'] ?? 0) === (int)$c1 && (int)($p4[1]['card_id'] ?? 0) === (int)$c2);

    // Self-move → 400
    $r = http('PUT', $base . '/v1/priority/position', ['X-CSRF-Token' => $csrf],
              ['card_id' => (int)$c1, 'after_card_id' => (int)$c1], $cookieJar);
    check('self-move → 400', $r['status'] === 400, "got {$r['status']}");

    // ---------------------------------------------------------------
    echo "\n[G] Unknown card → 404\n";
    $r = http('POST', $base . '/v1/priority/inbox/99999999', ['X-CSRF-Token' => $csrf], null, $cookieJar);
    check('prioritize unknown → 404', $r['status'] === 404, "got {$r['status']}");

    // ---------------------------------------------------------------
    echo "\n[H] Page render (priority.php)\n";
    // Use same cookie jar (session)
    $ch = curl_init($base . '/priority.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 15,
    ]);
    $page = curl_exec($ch);
    $pStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $pUrl    = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    check('page 200', $pStatus === 200, "got {$pStatus} url=$pUrl");
    check('page has priority-page div', str_contains($page, 'priority-page'));
    check('page has "My Priority List"', str_contains($page, 'My Priority List'));
    check('page includes c1 link card.php?id=' . $c1, str_contains($page, 'card.php?id=' . $c1));
    check('page includes c2', str_contains($page, 'id="' . $c2 . '"') || str_contains($page, 'data-card-id="' . $c2 . '"'));
    check('page has prioritize button on inbox item', str_contains($page, 'data-priority-action="prioritize"'));
    check('page has remove button on prioritized item', str_contains($page, 'data-priority-action="remove"'));
    check('page links to /js/priority.js', str_contains($page, '/js/priority.js'));

    // ---------------------------------------------------------------
    echo "\n[I] Header nav link\n";
    $ch = curl_init($base . '/boards.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
    ]);
    $boards = curl_exec($ch);
    curl_close($ch);
    check('boards page has /priority.php nav link', str_contains($boards, '/priority.php'));

    // ---------------------------------------------------------------
    echo "\n[J] Logout\n";
    $r = http('GET', $base . '/v1/auth/session', [], null, $cookieJar);
    // (session info, expected 200 while authed)
    $r = http('POST', $base . '/v1/auth/logout', ['X-CSRF-Token' => $csrf], null, $cookieJar);
    check('logout 200/204', in_array($r['status'], [200, 204], true), "got {$r['status']}");

    @unlink($cookieJar);

    // -----------------------------------------------------------------
} finally {
    // ---------------------------------------------------------------------
    // RESTORE — remove every fixture row we created
    // ---------------------------------------------------------------------
    echo "\n[Cleanup]\n";
    try {
        // cards + assignments
        if ($created['cards']) {
            $in = implode(',', array_fill(0, count($created['cards']), '?'));
            $db->execute("DELETE FROM card_assignments WHERE card_id IN ($in)", array_map('intval', $created['cards']));
            $db->execute("DELETE FROM cards WHERE id IN ($in)", array_map('intval', $created['cards']));
        }
        // lanes
        foreach ([$created['lane_inbox'], $created['lane_prog']] as $lid) {
            if ($lid) $db->execute('DELETE FROM lanes WHERE id = ?', [$lid]);
        }
        // board_organizations + board
        if ($created['board_id']) {
            $db->execute('DELETE FROM board_organizations WHERE board_id = ?', [$created['board_id']]);
            $db->execute('DELETE FROM boards WHERE id = ?', [$created['board_id']]);
        }
        // user
        if ($created['user_id']) {
            $db->execute('DELETE FROM users WHERE id = ?', [$created['user_id']]);
        }
        // organization (only if name matches our temp)
        if ($created['org_id']) {
            $db->execute('DELETE FROM organizations WHERE id = ? AND name LIKE ?', [$created['org_id'], 'PrioOrg-%']);
        }
        // session rows for the deleted user (or the temp jar sid)
        if ($created['user_id']) {
            $db->execute('DELETE FROM sessions WHERE user_id = ?', [$created['user_id']]);
        }
        echo "  cleanup done\n";
    } catch (\Throwable $e) {
        echo "  CLEANUP WARNING: " . $e->getMessage() . "\n";
    }
}

echo "\n-----------------------------------\n";
echo "http checks: $checks, failures: $failures\n";
echo "status: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
exit($failures === 0 ? 0 : 1);
