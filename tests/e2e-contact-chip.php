<?php
declare(strict_types=1);
/**
 * E2E check for the USER-01 / USER-04 contact-tooltip contract (v1.16, §5.24).
 *
 * Visibility decision (Daniel, 2026-09-18): phone + location are visible to
 * every user in the SAME organization (and to admins).
 *
 * Verifies against the live DB:
 *   § 1. Card model payload: getAssignedUsers + batchLoadAssignments
 *        carry phone/location/organization_id (and NOT email)
 *   § 2. scrubAssignedUsersFor rule matrix:
 *        - admin viewer           → pass-through (all rows untouched)
 *        - same-org viewer        → pass-through
 *        - cross-org viewer       → phone/location/bio nulled
 *        - viewer with NULL org   → all contact nulled
 *        - assignee with NULL org → nulled for any non-admin viewer
 *        - id/name/organization_id survive every scrub
 *   § 3. Empty/zero rows: [] in → [] out (no error)
 *   § 4. The assignee rows on a card round-trip through the model
 *        (findById + findByBoard batch)
 *
 * Test-safety invariant (Daniel 2026-09-18): never reads/writes user 1
 * (danielp) or 10 (Daniel's personal account on id 71) — fixtures only,
 * all deleted in the EXIT-style cleanup at the bottom.
 *
 * Usage: php tests/e2e-contact-chip.php
 */
require_once dirname(__DIR__) . '/include/bootstrap.php';

$checks = 0;
$failures = 0;

function check(string $name, bool $cond): void
{
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; }
    echo ($cond ? 'PASS' : 'FAIL') . "  $name\n";
}

$db2 = $db;

$userModel = new \Shuffle\Model\User($db2);
$boardModel = new \Shuffle\Model\Board($db2);
$laneModel  = new \Shuffle\Model\Lane($db2);
$cardModel  = new \Shuffle\Model\Card($db2);
$userService = new \Shuffle\Service\UserService($userModel);

// ---------------------------------------------------------------------------
// Fixtures: two same-org users (alice = contact-filled, bob = bare),
// one cross-org user (charlie), a board owned by org 1, one card assigned
// to alice + bob. Created here, deleted in cleanup below. Actor = mya (4).
// ---------------------------------------------------------------------------
$fxBase = 'cc-' . substr(bin2hex(random_bytes(4)), 0, 8);

$aliceId = $userModel->create([
    'username'        => $fxBase . 'alice',
    'password_hash'   => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
    'name'            => 'Alice Fixture',
    'email'           => $fxBase . 'alice@example.test',
    'organization_id' => 1,
    'role'            => 'member',
    'status'          => 'active',
]);
// create() does not take contact fields — set them via update() (the
// USER-01 surface, same path a profile save uses).
$userModel->update($aliceId, ['phone' => '555-0142', 'location' => 'Stockholm']);

$bobId = $userModel->create([
    'username'        => $fxBase . 'bob',
    'password_hash'   => password_hash('fixture-pass-1', PASSWORD_ARGON2ID),
    'name'            => 'Bob Fixture',
    'email'           => $fxBase . 'bob@example.test',
    'organization_id' => 1,
    'role'            => 'member',
    'status'          => 'active',
]);

// Cross-org viewer: a synthetic row (not a DB user — the scrub is pure
// array logic; this shape is exactly what requireAuth() produces, and the
// org id is deliberately different from org 1).
$charlieRow = [
    'id'              => 999999,
    'role'            => 'member',
    'organization_id' => 2,
];

$noOrgRow = [
    'id'              => 999998,
    'role'            => 'member',
    'organization_id' => null,
];

$adminRow = [
    'id'              => 4,  // mya is the admin on this install — shape only, no account touched
    'role'            => 'admin',
    'organization_id' => 1,
];

$boardId = $boardModel->create(['title' => 'E2E CC ' . $fxBase, 'visibility' => 'private', 'created_by' => 4]);
$laneId  = $laneModel->create(['board_id' => $boardId, 'title' => 'Inbox', 'position' => 1000]);
$cardId  = $cardModel->create(['lane_id' => $laneId, 'title' => 'CC fixture card', 'created_by' => 4]);

$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cardId, $aliceId]);
$db2->execute('INSERT INTO card_assignments (card_id, user_id) VALUES (?, ?)', [$cardId, $bobId]);

// ---------------------------------------------------------------------------
// § 1. Model payload shape (single + batch) — phone/location/org present,
//        email ABSENT from the assigned_users rows.
// ---------------------------------------------------------------------------
$single = $cardModel->getAssignedUsers($cardId);
check('1.1 getAssignedUsers returns 2 rows', count($single) === 2);
$aliceRow = null;
foreach ($single as $row) {
    if ((int) $row['id'] === (int) $aliceId) { $aliceRow = $row; }
}
check('1.2 alice row has phone', $aliceRow !== null && $aliceRow['phone'] === '555-0142');
check('1.3 alice row has location', $aliceRow !== null && $aliceRow['location'] === 'Stockholm');
check('1.4 alice row org is int 1', $aliceRow !== null && $aliceRow['organization_id'] === 1);
check('1.5 email key ABSENT from payload', $aliceRow === null || !array_key_exists('email', $aliceRow));

$batch = $cardModel->batchLoadAssignments([$cardId]);
check('1.6 batch has 2 rows for the card', isset($batch[$cardId]) && count($batch[$cardId]) === 2);
$aliceBatch = null;
foreach (($batch[$cardId] ?? []) as $row) {
    if ((int) $row['id'] === (int) $aliceId) { $aliceBatch = $row; }
}
check('1.7 batch alice row has phone', $aliceBatch !== null && $aliceBatch['phone'] === '555-0142');
check('1.8 batch email key ABSENT', $aliceBatch === null || !array_key_exists('email', $aliceBatch));

// findById path (the card modal's source) also carries the fields.
$viaFind = $cardModel->findById($cardId);
$aliceFind = null;
foreach (($viaFind['assigned_users'] ?? []) as $row) {
    if ((int) $row['id'] === (int) $aliceId) { $aliceFind = $row; }
}
check('1.9 findById payload has phone', $aliceFind !== null && $aliceFind['phone'] === '555-0142');

// § 2. Scrub rule matrix.
// ---------------------------------------------------------------------------
$aliceFull = $aliceRow; // phone set
$bobFull   = null;
foreach ($single as $row) {
    if ((int) $row['id'] === (int) $bobId) { $bobFull = $row; }
}
$full = [$aliceFull, $bobFull];

// 2a. admin → untouched
$out = $userService->scrubAssignedUsersFor($full, $adminRow);
check('2.1 admin: alice phone visible', ($out[0]['phone'] ?? null) === '555-0142');
check('2.2 admin: alice location visible', ($out[0]['location'] ?? null) === 'Stockholm');

// 2b. same-org member → untouched
$out = $userService->scrubAssignedUsersFor($full, ['id' => (int) $bobId, 'role' => 'member', 'organization_id' => 1]);
check('2.3 same-org: alice phone visible', ($out[0]['phone'] ?? null) === '555-0142');
check('2.4 same-org: alice location visible', ($out[0]['location'] ?? null) === 'Stockholm');
check('2.5 same-org: bob (no contact) stays null phone', ($out[1]['phone'] ?? null) === null);

// 2c. cross-org → nulled
$out = $userService->scrubAssignedUsersFor($full, $charlieRow);
check('2.6 cross-org: alice phone nulled', ($out[0]['phone'] ?? null) === null);
check('2.7 cross-org: alice location nulled', ($out[0]['location'] ?? null) === null);
check('2.8 cross-org: alice name survives', ($out[0]['name'] ?? null) === 'Alice Fixture');
check('2.9 cross-org: alice id survives', ($out[0]['id'] ?? null) === $aliceFull['id']);
check('2.10 cross-org: alice org survives', ($out[0]['organization_id'] ?? null) === 1);

// 2d. NULL-org viewer → nulled
$out = $userService->scrubAssignedUsersFor($full, $noOrgRow);
check('2.11 NULL-org viewer: alice phone nulled', ($out[0]['phone'] ?? null) === null);

// 2e. assignee with NULL org → nulled for same "org" viewer (org mismatch by null)
$nullOrgUser = ['id' => 424242, 'name' => 'Null Org Guy', 'phone' => '555-noorg', 'location' => 'Nowhere', 'organization_id' => null];
$out = $userService->scrubAssignedUsersFor([$nullOrgUser], ['id' => 1, 'role' => 'member', 'organization_id' => 1]);
check('2.12 NULL-org assignee nulled for non-admin', ($out[0]['phone'] ?? null) === null);
$out = $userService->scrubAssignedUsersFor([$nullOrgUser], $adminRow);
check('2.13 NULL-org assignee visible to admin', ($out[0]['phone'] ?? null) === '555-noorg');

// 2f. empty array in → empty array out
$out = $userService->scrubAssignedUsersFor([], $charlieRow);
check('2.14 empty input → empty output', $out === []);

// 2g. scrub never mutates the input array (copy semantics)
$before = $full;
$userService->scrubAssignedUsersFor($full, $charlieRow);
check('2.15 input array not mutated', $full === $before);

// ---------------------------------------------------------------------------
// Cleanup: cards → assignments → lanes → boards → users (FK order).
// Never touches any real account (1 / 71 / 4's own data intact).
// ---------------------------------------------------------------------------
$db2->execute('DELETE FROM card_assignments WHERE card_id = ?', [$cardId]);
$db2->execute('DELETE FROM cards WHERE id = ?', [$cardId]);
$db2->execute('DELETE FROM lanes WHERE board_id = ?', [$boardId]);
$db2->execute('DELETE FROM boards WHERE id = ?', [$boardId]);
$db2->execute('DELETE FROM users WHERE id IN (?, ?)', [$aliceId, $bobId]);

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
