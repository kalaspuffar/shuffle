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

        // Email — any user can update their own
        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('A valid email address is required');
            }
            $existing = $this->userModel->findByEmail($data['email']);
            if ($existing !== null && $existing['id'] != $id) {
                throw new \RuntimeException('This email address is already in use');
            }
            $updateData['email'] = $data['email'];
        }

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
