<?php
/**
 * tests/e2e-pwa.php — PWA PHP-side contracts (spec v1.21 §5.28).
 *
 * The PWA surface splits cleanly across two test files:
 *   - tests/pwa-sw.test.js  — JS service-worker contracts (PWA-03/04/05/06)
 *   - tests/http-pwa.sh     — HTTP render + installability contracts (PWA-01/02/07/08)
 * and this file covers what only the PHP side can assert:
 *   (a) every `pwa.*` lang key resolves (returns its value, not itself);
 *   (b) /offline.php renders those exact strings — a broken key in en.json
 *       would print the key name to a user who is offline, and no HTTP
 *       contract would catch that because the page still 200s.
 *
 * No fixtures, no writes: this suite is pure read.
 */

require_once __DIR__ . '/../include/bootstrap.php';

$pass = 0;
$fail = 0;

function ck(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name" . ($detail !== '' ? " — $detail" : "") . "\n";
    }
}

// ------------------------------------------------------------------ (a) --
$required = [
    'pwa.offline_title',
    'pwa.offline_heading',
    'pwa.offline_body_1',
    'pwa.offline_body_2',
    'pwa.offline_body_3',
    'pwa.offline_cta',
];
foreach ($required as $key) {
    $value = $lang->get($key);
    ck("lang $key resolves (does not fall back to itself)",
        is_string($value) && $value !== $key && $value !== '',
        'value=' . var_export($value, true));
}

// ------------------------------------------------------------------ (b) --
// Assert the offline page's body text matches the lang values, not the key
// names. We do this by rendering the page's string slots — they are exactly
// the six keys above, so if any of them falls back to itself, the rendered
// page will literally say the key name.
// (bootstrap already ran via the top-of-file require; its variables are
// global, so declare them here — the CLI process ignores header() calls.)
global $lang, $csrf, $auth;
$html = '';
ob_start();
try {
    include __DIR__ . '/../www/offline.php';
} finally {
    $html = (string) ob_get_clean();
    // offline.php calls exit()/return-style flow at its end on some paths —
    // if it terminated this process earlier we would not get here, so this
    // finally is the one place we can safely clean up the output buffer.
}
$page = $html;

ck('/offline.php renders (non-empty HTML body)',
    strlen($page) > 200, 'len=' . strlen($page));
ck('/offline.php renders data-theme dark (THEME-03)',
    strpos($page, 'data-theme="dark"') !== false);
foreach ($required as $key) {
    ck("/offline.php does not emit the key name '$key' as visible text",
        strpos($page, $key) === false,
        'page slice=' . substr($page, 0, 400));
}
ck('/offline.php: pwa.offline_body_1 text present',
    strpos($page, htmlspecialchars($lang->get('pwa.offline_body_1'), ENT_QUOTES, 'UTF-8')) !== false);
ck('/offline.php: pwa.offline_cta text present',
    strpos($page, htmlspecialchars($lang->get('pwa.offline_cta'), ENT_QUOTES, 'UTF-8')) !== false);

echo "\nPWA E2E (PHP side): PASS=$pass FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
