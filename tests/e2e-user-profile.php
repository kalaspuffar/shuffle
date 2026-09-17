<?php
declare(strict_types=1);
/**
 * E2E service-contract suite for USER-01..03 (spec v1.14 §5.22).
 *
 * Covers the service layer against the live DB, with a DEDICATED fixture
 * user (never Daniel's id 1, never mya's id 4 — both are real accounts with
 * real credentials that must not change):
 *
 *   § 1. updateMe / updateUser — field set+clear, blank-clears, length caps,
 *         non-string reject, email-immutable (self path)
 *   § 2. changeMyPassword — shape 400 class, wrong-current 403 class,
 *         successful set + re-verify
 *   § 3. adminResetPassword — non-admin denied, shape, unknown target,
 *         successful reset + login-as-target semantics (password_verify)
 *
 * The fixture user is created at start (random username) and DELETED at the
 * end — verified gone. All assertions are self-contained.
 *
 * Usage: php tests/e2e-user-profile.php
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

// ---------------------------------------------------------------------------
// Fixture: a dedicated member account.
// ---------------------------------------------------------------------------
$fixtureName = 'e2e-profile-' . substr(bin2hex(random_bytes(4)), 0, 8);
$fixtureEmail = $fixtureName . '@example.test';

$fixtureId = $userModel->create([
    'username'      => $fixtureName,
    'password_hash' => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
    'name'          => 'Profile Fixture',
    'email'         => $fixtureEmail,
    'role'          => 'member',
    'status'        => 'active',
]);

check('fixture user created', $fixtureId > 0);

// Baseline: new fixture has NULL profile fields.
$before = $userModel->findById($fixtureId);
check('fixture baseline phone NULL', ($before['phone'] ?? null) === null);
check('fixture baseline location NULL', ($before['location'] ?? null) === null);
check('fixture baseline bio NULL', ($before['bio'] ?? null) === null);

$actor = ['id' => (int) $fixtureId, 'role' => 'member'];

/* ============================ § 1 profile fields ========================= */

// Happy path: set all three + name.
$r = $userService->updateUser($fixtureId, [
    'name'     => 'Updated Name',
    'phone'    => '+46 70 000 1234',
    'location' => 'Stockholm, SE',
    'bio'      => '  Working on things  ',   // outer whitespace must be trimmed
], $actor);
check('1.1 set: name stored',   $r['name'] === 'Updated Name');
check('1.2 set: phone stored',  $r['phone'] === '+46 70 000 1234');
check('1.3 set: location stored', $r['location'] === 'Stockholm, SE');
check('1.4 set: bio outer-trimmed', $r['bio'] === 'Working on things');

// Only-provided-fields contract: touching only bio must not erase phone.
$r2 = $userService->updateUser($fixtureId, ['bio' => 'Only bio changed'], $actor);
check('1.5 only-provided: bio updated', $r2['bio'] === 'Only bio changed');
check('1.6 only-provided: phone retained', $r2['phone'] === '+46 70 000 1234');

// Blank-string clear + null clear + re-set.
$r3 = $userService->updateUser($fixtureId, ['phone' => ''], $actor);
check('1.7 blank-string clears phone', $r3['phone'] === null);
$r4 = $userService->updateUser($fixtureId, ['location' => null], $actor);
check('1.8 null clears location', $r4['location'] === null);
$r5 = $userService->updateUser($fixtureId, ['phone' => '+1 555 0100'], $actor);
check('1.9 re-set after clear', $r5['phone'] === '+1 555 0100');

// Length caps (phone 32 / location 120 / bio 500) — over-length must 400-class.
$over = str_repeat('x', 40);
try {
    $userService->updateUser($fixtureId, ['phone' => $over], $actor);
    check('1.10 phone over-length rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.10 phone over-length rejected', true);
}
$over = str_repeat('y', 130);
try {
    $userService->updateUser($fixtureId, ['location' => $over], $actor);
    check('1.11 location over-length rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.11 location over-length rejected', true);
}
$over = str_repeat('z', 700);
try {
    $userService->updateUser($fixtureId, ['bio' => $over], $actor);
    check('1.12 bio over-length rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.12 bio over-length rejected', true);
}
// Exact caps are legal (32 / 120 / 500).
$edge = $userService->updateUser($fixtureId, [
    'phone'    => str_repeat('a', 32),
    'location' => str_repeat('b', 120),
    'bio'      => str_repeat('c', 500),
], $actor);
check('1.13 edge: phone 32 legal', $edge['phone'] === str_repeat('a', 32));
check('1.14 edge: location 120 legal', $edge['location'] === str_repeat('b', 120));
check('1.15 edge: bio 500 legal', $edge['bio'] === str_repeat('c', 500));

// Non-string field must 400-class.
try {
    $userService->updateUser($fixtureId, ['phone' => 12345], $actor);
    check('1.16 non-string phone rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.16 non-string phone rejected', true);
}

// Email immutable (USER-02 / AUTH-04) — 400-class, on the SELF path.
try {
    $userService->updateUser($fixtureId, ['email' => 'hacker@example.com'], $actor);
    check('1.17 self email immutable rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.17 self email immutable rejected', true);
}
// …and on the ADMIN path (email must be rejected for an admin too).
$adminActor = ['id' => 4, 'role' => 'admin'];
try {
    $userService->updateUser($fixtureId, ['email' => 'hacker@example.com'], $adminActor);
    check('1.18 admin email immutable rejected', false);
} catch (\InvalidArgumentException $e) {
    check('1.18 admin email immutable rejected', true);
}
// …and the value in the DB must be unchanged.
$afterEmail = $userModel->findById($fixtureId);
check('1.19 email value unchanged', $afterEmail['email'] === $fixtureEmail);

// Admin field access: role change by an admin still works (existing §5.3 path,
// exercised here to prove the profile-field additions didn't break it).
$r6 = $userService->updateUser($fixtureId, ['role' => 'viewer'], $adminActor);
check('1.20 admin role change still works', $r6['role'] === 'viewer');
$r7 = $userService->updateUser($fixtureId, ['role' => 'member'], $adminActor);
check('1.21 admin role restored', $r7['role'] === 'member');

/* ==================== § 2 changeMyPassword (USER-02) ===================== */

$fixtureId = (int) $fixtureId;

// Shape error: new password missing/short → InvalidArgumentException (400).
try {
    $userService->changeMyPassword($fixtureId, ['current_password' => 'whatever', 'new_password' => 'short']);
    check('2.1 short new password rejected', false);
} catch (\InvalidArgumentException $e) {
    check('2.1 short new password rejected', true);
}

// Wrong current → RuntimeException "Current password is incorrect" (403 class).
try {
    $userService->changeMyPassword($fixtureId, ['current_password' => 'totally-wrong-pass', 'new_password' => 'newpass-99']);
    check('2.2 wrong current rejected', false);
} catch (\RuntimeException $e) {
    check('2.2 wrong current rejected', true);
}

// Missing current (unset) — must NOT accidentally succeed.
try {
    $userService->changeMyPassword($fixtureId, ['new_password' => 'newpass-99']);
    check('2.3 missing current rejected', false);
} catch (\RuntimeException $e) {
    check('2.3 missing current rejected', true);
}

// Happy path: correct current → new password is set; verify it actually
// round-trips through password_verify against the stored hash.
$userService->changeMyPassword($fixtureId, ['current_password' => 'fixture-pass-1', 'new_password' => 'brand-new-pass']);
$hashNow = $userModel->findPasswordHashById($fixtureId);
check('2.4 new password stored (verify true)', $hashNow !== null && password_verify('brand-new-pass', $hashNow));
check('2.5 old password no longer valid', $hashNow === null || !password_verify('fixture-pass-1', $hashNow));

// Unknown actor → "User not found" (RuntimeException/404 class).
try {
    $userService->changeMyPassword(999999, ['current_password' => 'x', 'new_password' => 'newpass-99']);
    check('2.6 unknown actor rejected', false);
} catch (\RuntimeException $e) {
    check('2.6 unknown actor rejected', $e->getMessage() === 'User not found');
}

/* ================ § 3 adminResetPassword (USER-03) ====================== */

// Non-admin actor → "Access denied" (403 class).
$memberActor = ['id' => (int) $fixtureId, 'role' => 'member'];
try {
    $userService->adminResetPassword((int) $fixtureId, (int) $fixtureId, ['new_password' => 'forced-pass-99']);
    check('3.1 non-admin reset rejected', false);
} catch (\RuntimeException $e) {
    check('3.1 non-admin reset rejected', str_starts_with($e->getMessage(), 'Access denied'));
}
// …and the password must NOT have changed.
$hashBefore = $userModel->findPasswordHashById($fixtureId);
check('3.2 non-admin reset: hash unchanged', password_verify('brand-new-pass', $hashBefore));

// Short new password → InvalidArgumentException (400) even for an admin.
try {
    $userService->adminResetPassword(4, (int) $fixtureId, ['new_password' => 'short']);
    check('3.3 admin short password rejected', false);
} catch (\InvalidArgumentException $e) {
    check('3.3 admin short password rejected', true);
}

// Unknown target → "User not found" (404 class).
try {
    $userService->adminResetPassword(4, 999999, ['new_password' => 'valid-pass-99']);
    check('3.4 unknown target rejected', false);
} catch (\RuntimeException $e) {
    check('3.4 unknown target rejected', $e->getMessage() === 'User not found');
}

// Happy path: admin sets a new password (no current needed).
$userService->adminResetPassword(4, (int) $fixtureId, ['new_password' => 'admin-set-pass']);
$hashAfter = $userModel->findPasswordHashById($fixtureId);
check('3.5 admin reset: new password valid', $hashAfter !== null && password_verify('admin-set-pass', $hashAfter));
check('3.6 admin reset: prior password invalid', !password_verify('brand-new-pass', $hashAfter));

/* =============================== cleanup ================================ */

$fixtureId = (int) $fixtureId;
$userService->deleteUser($fixtureId);
$gone = $userModel->findById($fixtureId);
check('cleanup: fixture deleted', $gone === null);

/* ======================================================================= */
echo "\n";
echo "USER-PROFILE_E2E  $checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
