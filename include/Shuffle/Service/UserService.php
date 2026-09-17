<?php
namespace Shuffle\Service;

use Shuffle\Core\Mailer;
use Shuffle\Model\User;

/**
 * User business logic service.
 *
 * Handles invitation, activation, and user management operations
 * with proper validation and permission enforcement.
 */
class UserService
{
    private User $userModel;

    /**
     * @param User $userModel User data access instance
     */
    public function __construct(User $userModel)
    {
        $this->userModel = $userModel;
    }

    /**
     * Invites a new user by creating an inactive account and sending an invitation email.
     *
     * @param array  $data   User data: email, name, role, organization_id
     * @param Mailer $mailer Mailer instance for sending the invitation
     * @param string $appUrl Application base URL (e.g., https://shuffle.example.com)
     * @return array The created user record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If the email is already in use
     */
    public function invite(array $data, Mailer $mailer, string $appUrl): array
    {
        // Validate required fields
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email address is required');
        }
        if (empty($data['name']) || strlen($data['name']) < 1 || strlen($data['name']) > 128) {
            throw new \InvalidArgumentException('Name is required and must be 1-128 characters');
        }

        $validRoles = ['admin', 'member', 'viewer'];
        $role = $data['role'] ?? 'member';
        if (!in_array($role, $validRoles, true)) {
            throw new \InvalidArgumentException('Invalid role');
        }

        // Check for duplicate email
        $existing = $this->userModel->findByEmail($data['email']);
        if ($existing !== null) {
            throw new \RuntimeException('This email address is already in use');
        }

        // Generate a 128-bit random invite token
        $inviteToken = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + (72 * 3600)); // 72 hours

        $userId = $this->userModel->create([
            'username'                 => '',
            'password_hash'            => '',
            'name'                     => $data['name'],
            'email'                    => $data['email'],
            'role'                     => $role,
            'organization_id'          => $data['organization_id'] ?? null,
            'status'                   => 'inactive',
            'invite_token'             => $inviteToken,
            'invite_token_expires_at'  => $expiresAt,
        ]);

        // Build invitation email
        $activateUrl = rtrim($appUrl, '/') . '/activate.php?token=' . urlencode($inviteToken);
        $escapedName = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
        $escapedUrl = htmlspecialchars($activateUrl, ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body>
<p>Hello {$escapedName},</p>
<p>You have been invited to join Shuffle. Click the link below to set up your account:</p>
<p><a href="{$escapedUrl}">{$escapedUrl}</a></p>
<p>This invitation expires in 72 hours.</p>
<p>— The Shuffle Team</p>
</body>
</html>
HTML;

        $textBody = <<<TEXT
Hello {$data['name']},

You have been invited to join Shuffle. Visit the link below to set up your account:

{$activateUrl}

This invitation expires in 72 hours.

— The Shuffle Team
TEXT;

        $mailer->send($data['email'], 'You have been invited to Shuffle', $htmlBody, $textBody);

        return $this->userModel->findById($userId);
    }

    /**
     * Activates an invited user account.
     *
     * Validates the invite token and expiry, then sets the username and password.
     *
     * @param string $token    Invite token
     * @param string $username Chosen username
     * @param string $password Chosen password (plaintext; will be hashed)
     * @return array The activated user record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If token is invalid or expired
     */
    public function activateUser(string $token, string $username, string $password): array
    {
        if (empty($token)) {
            throw new \InvalidArgumentException('Token is required');
        }

        $user = $this->userModel->findByInviteToken($token);
        if ($user === null) {
            throw new \RuntimeException('Invalid or expired invitation token');
        }

        // Check expiry
        if (!empty($user['invite_token_expires_at'])) {
            $expiresAt = strtotime($user['invite_token_expires_at']);
            if ($expiresAt !== false && time() > $expiresAt) {
                throw new \RuntimeException('Invitation token has expired');
            }
        }

        // Validate username
        $this->validateUsername($username);

        // Check for duplicate username
        $existingUser = $this->userModel->findByUsername($username);
        if ($existingUser !== null) {
            throw new \RuntimeException('This username is already taken');
        }

        // Validate password
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $this->userModel->activate($user['id'], $username, $passwordHash);

        return $this->userModel->findById($user['id']);
    }

    /**
     * Updates a user account with permission enforcement.
     *
     * Admins can update any field. Non-admins can only update their own name and email.
     *
     * @param int   $id          User ID to update
     * @param array $data        Fields to update
     * @param array $currentUser The currently authenticated user
     * @return array The updated user record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If permission denied or user not found
     */
    public function updateUser(int $id, array $data, array $currentUser): array
    {
        $targetUser = $this->userModel->findById($id);
        if ($targetUser === null) {
            throw new \RuntimeException('User not found');
        }

        $isAdmin = ($currentUser['role'] === 'admin');
        $isSelf = ($currentUser['id'] == $id);

        if (!$isAdmin && !$isSelf) {
            throw new \RuntimeException('Access denied');
        }

        $updateData = [];

        // Name — any user can update their own
        if (isset($data['name'])) {
            if (strlen($data['name']) < 1 || strlen($data['name']) > 128) {
                throw new \InvalidArgumentException('Name must be 1-128 characters');
            }
            $updateData['name'] = $data['name'];
        }

        // Email — immutable in the v1 surface (USER-01..03 / AUTH-04: the email
        // is the identity anchor set at invite; changing it is a later admin
        // flow, out of scope). Reject on every path (self or admin).
        if (array_key_exists('email', $data)) {
            throw new \InvalidArgumentException('Email is immutable in the v1 surface (identity anchor — see AUTH-04/USER-02)');
        }

        // Profile contact fields (USER-01) — self via /v1/me, any user by an
        // admin via /v1/admin/users/{id} (both funnel through updateMeFields()).
        $updateData = array_merge($updateData, $this->updateMeFields($data));

        // Admin-only fields: role, organization_id, status
        if ($isAdmin) {
            if (isset($data['role'])) {
                $validRoles = ['admin', 'member', 'viewer'];
                if (!in_array($data['role'], $validRoles, true)) {
                    throw new \InvalidArgumentException('Invalid role');
                }
                $updateData['role'] = $data['role'];
            }
            if (array_key_exists('organization_id', $data)) {
                $updateData['organization_id'] = $data['organization_id'];
            }
            if (isset($data['status'])) {
                $validStatuses = ['active', 'inactive'];
                if (!in_array($data['status'], $validStatuses, true)) {
                    throw new \InvalidArgumentException('Invalid status');
                }
                $updateData['status'] = $data['status'];
            }
        } elseif (isset($data['role']) || isset($data['organization_id']) || isset($data['status'])) {
            throw new \RuntimeException('Access denied: only admins can change role, organization, or status');
        }

        if (!empty($updateData)) {
            $this->userModel->update($id, $updateData);
        }

        return $this->userModel->findById($id);
    }

    /**
     * Deletes a user.
     *
     * @param int $id User ID to delete
     * @throws \RuntimeException If user not found
     */
    public function deleteUser(int $id): void
    {
        $user = $this->userModel->findById($id);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }
        $this->userModel->delete($id);
    }

    /**
     * Retrieves a single user by ID.
     *
     * @param int $id User ID
     * @return array|null User row or null if not found
     */
    public function getUser(int $id): ?array
    {
        return $this->userModel->findById($id);
    }

    /**
     * Lists users with optional filters.
     *
     * @param array $filters Optional filters: status, organization_id
     * @return array Array of user records
     */
    public function listUsers(array $filters = []): array
    {
        return $this->userModel->findAll($filters);
    }

    /**
     * Self-service profile update (USER-02, §5.22): PUT /v1/me.
     *
     * Updates the actor's own `name` in addition to the profile fields
     * (`phone`, `location`, `bio`). Email is rejected (immutable in v1 —
     * identity anchor, AUTH-04). Only provided fields are written; a blank
     * string clears a nullable field; `null` is a clear for all three.
     *
     * @param int   $actorId The authenticated actor's user id
     * @param array $data    Fields to update
     * @return array The updated user record
     * @throws \InvalidArgumentException On an invalid / immutable / over-length field
     */
    public function updateMe(int $actorId, array $data): array
    {
        return $this->updateUser($actorId, $data, ['id' => $actorId, 'role' => 'member']);
    }

    /**
     * Change one's own password (USER-02, §5.22): PUT /v1/me/password.
     *
     * Verifies the current password before writing the new one. New password
     * must be at least 8 characters (same rule as activation — AUTH-03).
     *
     * @param int    $actorId        The authenticated actor's user id
     * @param array  $data           `{current_password, new_password}`
     * @return void
     * @throws \InvalidArgumentException If the new password is missing/short (shape error)
     * @throws \RuntimeException         "Current password is incorrect" on a wrong current password (403)
     */
    public function changeMyPassword(int $actorId, array $data): void
    {
        $target = $this->userModel->findById($actorId);
        if ($target === null) {
            throw new \RuntimeException('User not found');
        }

        $newPassword  = isset($data['new_password']) ? (string) $data['new_password'] : '';
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters');
        }

        $currentPassword = isset($data['current_password']) ? (string) $data['current_password'] : '';
        $hash = $this->userModel->findPasswordHashById($actorId);

        if ($hash === null || !password_verify($currentPassword, $hash)) {
            throw new \RuntimeException('Current password is incorrect');
        }

        $this->userModel->update($actorId, [
            'password_hash' => password_hash($newPassword, PASSWORD_ARGON2ID),
        ]);
    }

    /**
     * Admin reset of any user's password (USER-03, §5.22):
     * POST /v1/admin/users/{id}/reset-password.
     *
     * The admin does NOT need the user's current password. Requires admin
     * role on the actor (enforced here AND by the controller — defense in
     * depth; the controller is the single HTTP boundary and already guards
     * this, but the service guard means a future internal caller cannot
     * accidentally bypass it).
     *
     * @param int    $adminId      The admin's own user id
     * @param array  $data         `{new_password}`
     * @return void
     * @throws \InvalidArgumentException If new password is missing/short
     * @throws \RuntimeException         "Access denied" for a non-admin actor, or
     *                                   "User not found" for an unknown target
     */
    public function adminResetPassword(int $adminId, int $targetId, array $data): void
    {
        $admin = $this->userModel->findById($adminId);
        if ($admin === null || $admin['role'] !== 'admin') {
            throw new \RuntimeException('Access denied');
        }

        if ($this->userModel->findById($targetId) === null) {
            throw new \RuntimeException('User not found');
        }

        $newPassword = isset($data['new_password']) ? (string) $data['new_password'] : '';
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters');
        }

        $this->userModel->update($targetId, [
            'password_hash' => password_hash($newPassword, PASSWORD_ARGON2ID),
        ]);
    }

    /**
     * Validates + normalizes the USER-01 profile contact fields
     * (`phone`, `location`, `bio`). Shared by `updateUser()` (admin and
     * self paths) so the validation contract is not duplicated.
     *
     * Semantics:
     *   - omitted key            → field untouched
     *   - null                   → cleared (NULL in DB)
     *   - '' (empty string)      → cleared (same as null — convenient for UIs
     *                              that can't send null through a form)
     *   - any other string       → validated for length, stored as-is
     *
     * Lengths (all UTF-8 aware; bio is free-form prose):
     *   phone     ≤ 32 (a phone line with country code — not a full dial string)
     *   location  ≤ 120 (a one-line location)
     *   bio       ≤ 500 (a short profile blurb — longer is a page, not a bio)
     *
     * @param array $data Input fields
     * @return array The validated subset of $data keyed by column name
     * @throws \InvalidArgumentException If a provided field is not a string / is over-length
     */
    private function updateMeFields(array $data): array
    {
        $limit = [
            'phone'    => 32,
            'location' => 120,
            'bio'      => 500,
        ];

        $out = [];
        foreach ($limit as $field => $max) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $val = $data[$field];

            if ($val === null || $val === '') {
                $out[$field] = null;
                continue;
            }

            if (!is_string($val)) {
                throw new \InvalidArgumentException("$field must be a string");
            }
            if (mb_strlen($val, 'UTF-8') > $max) {
                throw new \InvalidArgumentException("$field must be $max characters or fewer");
            }
            // Trim trailing whitespace on store (UIs often leave a stray space).
            // Leading whitespace on a phone/location is a sign of an editor
            // accident, not a signal. Trim both — bio keeps its internal
            // whitespace but also trims the outer edges.
            $trimmed = trim($val);
            if (mb_strlen($trimmed, 'UTF-8') > $max) {
                throw new \InvalidArgumentException("$field must be $max characters or fewer");
            }
            $out[$field] = $trimmed;
        }

        return $out;
    }
    /**
     * Validates a username against format and length rules.
     *
     * @param string $username Username to validate
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateUsername(string $username): void
    {
        if (strlen($username) < 3 || strlen($username) > 64) {
            throw new \InvalidArgumentException('Username must be between 3 and 64 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            throw new \InvalidArgumentException('Username may only contain letters, numbers, dots, hyphens, and underscores');
        }
    }
}
