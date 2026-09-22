<?php
declare(strict_types=1);
/**
 * E2E service-contract suite for INTL-01..10 (spec v1.22 §5.29).
 *
 * Covers the service layer against the live DB, with a DEDICATED fixture
 * user (never Daniel's id 1, never mya's id 4 — both are real accounts):
 *
 *   § 1. language round-trip via UserService::updateUser (updateMe funnel):
 *         null → NULL stored; "sv" → stored; "zz" → 400-class; "SV" → 400-class
 *         (case-sensitive); 123 → 400-class (non-string); omitted key →
 *         value unchanged (array_key_exists contract)
 *   § 2. Lang fallback contract (INTL-04) in a TEMP lang dir — locale-file
 *         key wins, English backfills a missing key, raw key only when both
 *         lack it, {0} substitution preserved. (Uses a temp dir so the tests
 *         do NOT depend on any key being omitted from the shipped sv.json,
 *         which ships complete per INTL-07.)
 *   § 3. Lang::availableLanguages() == {en, sv} on the shipped repo, sorted
 *         by native name; __native_name stays out of the string surface.
 *   § 4. key parity — sv.json covers EVERY en.json key (INTL-07's "complete"
 *         claim is machine-checked), and placeholder parity ({0} positions).
 *
 * The fixture user is created at start and DELETED at the end — verified
 * gone. All assertions are self-contained.
 *
 * Usage: php tests/e2e-i18n.php
 */
require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0;
$failures = 0;

function check(string $name, bool $cond): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  $name\n";
}

$userModel   = new \Shuffle\Model\User($db);
$userService = new \Shuffle\Service\UserService($userModel);
$enJson      = json_decode(file_get_contents(ROOT_DIR . '/include/lang/en.json'), true);
$svJson      = json_decode(file_get_contents(ROOT_DIR . '/include/lang/sv.json'), true);

// ---------------------------------------------------------------------------
// Fixture: a dedicated member account.
// ---------------------------------------------------------------------------
$fixtureName  = 'e2e-i18n-' . substr(bin2hex(random_bytes(4)), 0, 8);
$fixtureEmail = $fixtureName . '@example.test';

$fixtureId = $userModel->create([
    'username'      => $fixtureName,
    'password_hash' => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
    'name'          => 'I18n Fixture',
    'email'         => $fixtureEmail,
    'role'          => 'member',
    'status'        => 'active',
]);
$fixtureId = (int) $fixtureId;
check('fixture user created', $fixtureId > 0);

$before = $userModel->findById($fixtureId);
check('fixture baseline language NULL', array_key_exists('language', $before) && $before['language'] === null);

$actor = ['id' => $fixtureId, 'role' => 'member'];

/* ============================ § 1 language round-trip ===================== */

// null → stored as NULL (explicit reset), and the row echoes it.
$r = $userService->updateUser($fixtureId, ['language' => null], $actor);
check('1.1 null → stored NULL', array_key_exists('language', $r) && $r['language'] === null);

// Valid code → stored.
$r = $userService->updateUser($fixtureId, ['language' => 'sv'], $actor);
check('1.2 "sv" stored', $r['language'] === 'sv');
check('1.3 stored in DB', $userModel->findById($fixtureId)['language'] === 'sv');

// Unknown code → 400-class (file does not exist).
try {
    $userService->updateUser($fixtureId, ['language' => 'zz'], $actor);
    check('1.4 unknown code rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.4 unknown code rejected', $e->getMessage() === 'Invalid language');
}
check('1.5 state unchanged after reject', $userModel->findById($fixtureId)['language'] === 'sv');

// Case-sensitive: 'SV' is not a locale file → 400 (no case folding).
try {
    $userService->updateUser($fixtureId, ['language' => 'SV'], $actor);
    check('1.6 uppercase code rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.6 uppercase code rejected', $e->getMessage() === 'Invalid language');
}

// Non-string → 400 (array, int, bool all cover the is_string gate).
foreach ([123, true, ['en']] as $i => $bad) {
    try {
        $userService->updateUser($fixtureId, ['language' => $bad], $actor);
        check("1." . (7 + $i) . " non-string code rejected", false);
    } catch (\InvalidArgumentException $e) {
        check("1." . (7 + $i) . " non-string code rejected", true);
    }
}

// Omitted key → value unchanged (array_key_exists contract — never writes).
$userService->updateUser($fixtureId, ['bio' => 'touched-bio'], $actor);
check('1.10 omitted language key leaves value', $userModel->findById($fixtureId)['language'] === 'sv');

// Switch back to the app default (null) — the explicit reset round-trips.
$r = $userService->updateUser($fixtureId, ['language' => null], $actor);
check('1.11 reset → NULL again', array_key_exists('language', $r) && $r['language'] === null);

/* ==================== § 2 Lang fallback contract (INTL-04) =============== */

// Temp lang dir: a real English base + a MINIMAL 'zz' locale file that
// deliberately omits keys (simulating a community file in progress).
$tmp  = sys_get_temp_dir() . '/shuffle-i18n-test-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
file_put_contents($tmp . '/en.json', json_encode([
    'profile.save_profile' => 'Save profile',
    'card.title'           => 'Card Title',
    'activity.assigned'    => 'assigned {0}',
    'app.name'             => 'Shuffle',
], JSON_PRETTY_PRINT));
file_put_contents($tmp . '/zz.json', json_encode([
    'profile.save_profile' => 'Spara profiilen',   // overrides en
    'zz.only'              => 'Zzy key {0}',       // only in the locale file
], JSON_PRETTY_PRINT));
@mkdir($tmp . '/sub', 0700, true);
file_put_contents($tmp . '/sub/zz.json', '{"x":"y"}');           // nested = not a locale
file_put_contents($tmp . '/README.json', '{"x":"y"}');           // not a locale code
file_put_contents($tmp . '/en-XX.json', 'not-json');             // invalid → skipped

use \Shuffle\Core\Lang;

$zz = new Lang('zz', $tmp, 'en');
check('2.1 locale-file key wins', $zz->get('profile.save_profile') === 'Spara profiilen');
check('2.2 missing key → English (not the raw key)', $zz->get('card.title') === 'Card Title');
check('2.3 missing key has() is TRUE (usable in this locale)', $zz->has('card.title'));
check('2.4 key only in the locale file resolves (unsubstituted {0} intact)', $zz->get('zz.only') === 'Zzy key {0}');
check('2.5 missing key: en fallback + placeholder substitution preserved', $zz->get('activity.assigned', ['Alice']) === 'assigned Alice');
check('2.6 key missing from BOTH layers → the raw key (contract floor)', $zz->get('zz.missing') === 'zz.missing');
check('2.7 has() false only when both layers lack it', $zz->has('zz.missing') === false);
check('2.8 getLocale() is the effective code', $zz->getLocale() === 'zz');

// Reserved metadata: __native_name is never returned as a string value.
file_put_contents($tmp . '/en.json', json_encode([
    'en.__native_name' => 'English',
    'profile.save_profile' => 'Save profile',
]));
$en2 = new Lang('en', $tmp);
check('2.9 en.__native_name not in the string surface', $en2->get('en.__native_name') === 'en.__native_name');
check('2.10 normal key still resolves when en.json shrinks', $en2->get('profile.save_profile') === 'Save profile');

// en constructed without a fallback must not break (the base case).
$en3 = new Lang('en', ROOT_DIR . '/include/lang');
check('2.11 en base loads with 600+ keys', $en3->get('profile.save_profile') === 'Save profile');

// ---------------------------------------------------------------------------
// § 3 availableLanguages (INTL-06)
// ---------------------------------------------------------------------------
$avail = Lang::availableLanguages($tmp);
check('3.1 temp dir lists only valid locales (en, zz)', array_keys($avail) === ['en', 'zz']);
check('3.2 native-name read from __native_name', ($avail['en'] ?? '') === 'English');
check('3.3 missing __native_name degrades to the code', ($avail['zz'] ?? '') === 'zz');

$ship = Lang::availableLanguages(ROOT_DIR . '/include/lang');
check('3.4 shipped repo lists exactly en + sv', array_keys($ship) === ['en', 'sv']);
check('3.5 shipped native names', ($ship['en'] ?? '') === 'English' && ($ship['sv'] ?? '') === 'Svenska');

// ---------------------------------------------------------------------------
// § 4 key parity — INTL-07 is machine-checked, not asserted
// ---------------------------------------------------------------------------
$enKeys = array_keys($enJson);
$svKeys = array_keys($svJson);
$missingFromSv = array_diff($enKeys, $svKeys);
check('4.1 sv.json covers every en.json key', $missingFromSv === []);
check('4.2 only extra key in sv.json is sv.__native_name', array_values(array_diff($svKeys, $enKeys)) === ['sv.__native_name']);

// Placeholder parity on every shared key (INTL-07: {0} must survive translation).
$phBad = [];
foreach ($enKeys as $k) {
    if (!isset($svJson[$k])) { continue; }
    preg_match_all('/\{\d+\}/', (string) $enJson[$k], $me);
    preg_match_all('/\{\d+\}/', (string) $svJson[$k], $ms);
    sort($me[0]);
    sort($ms[0]);
    if ($me[0] !== $ms[0]) { $phBad[] = $k; }
}
check('4.3 placeholder parity across all shared keys', $phBad === []);

/* =============================== cleanup ================================ */

$userService->deleteUser($fixtureId);
check('cleanup: fixture deleted', $userModel->findById($fixtureId) === null);

// Remove the temp lang dir.
array_map('unlink', array_merge(
    (array) glob($tmp . '/*.json'),
    (array) glob($tmp . '/sub/*.json')
));
@rmdir($tmp . '/sub');
@rmdir($tmp);

/* ======================================================================= */
echo "\n";
echo "I18N_E2E  $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
