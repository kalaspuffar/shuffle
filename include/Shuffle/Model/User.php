<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * User data access layer.
 *
 * Provides CRUD operations for the users table. All queries use
 * parameterized placeholders via the Database wrapper.
 */
class User
{
    private Database $db;

    /** Columns returned in standard user queries (excludes password_hash) */
    private const SELECT_COLUMNS = 'id, username, name, email, phone, location, bio, email_notifications, theme_preference, role, organization_id, is_placeholder, status, created_at, updated_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds a user by ID.
     *
     * @param int $id User ID
     * @return array|null User row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE id = ?',
            [$id]
        );
    }

    /**
     * Batch email + opt-in lookup (NOTIF-06, spec v1.18 §5.26).
     *
     * Returns `id => ['email' => string, 'email_notifications' => int(0|1)]`
     * for the subset of $userIds that exist. ONE indexed IN-query — the
     * notification fan-out must not N+1-resolve recipients.
     *
     * @param array<int> $userIds Raw (possibly uncast/duplicated) user ids
     * @return array<int, array{email: string, email_notifications: int}>
     */
    public function emailPrefsByIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $v): bool => $v > 0)));

        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, email, email_notifications FROM users WHERE id IN ($placeholders)",
            $userIds
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = [
                'email'               => (string) $row['email'],
                'email_notifications' => (int) $row['email_notifications'],
            ];
        }

        return $out;
    }

    /**
     * Finds a user by username.
     *
     * @param string $username Username
     * @return array|null User row or null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE username = ?',
            [$username]
        );
    }

    /**
     * Finds a user by email address.
     *
     * @param string $email Email address
     * @return array|null User row or null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE email = ?',
            [$email]
        );
    }

    /**
     * Returns the stored password hash for a user.
     *
     * Password hashes never appear in SELECT_COLUMNS (API safety). This is the
     * only sanctioned read path for password verification (login flows,
     * USER-02 current-password confirmation, §5.22).
     *
     * @param int $id User ID
     * @return string|null The hashed password, or null if the user does not exist
     */
    public function findPasswordHashById(int $id): ?string
    {
        $row = $this->db->fetch(
            'SELECT password_hash FROM users WHERE id = ?',
            [$id]
        );

        return $row['password_hash'] ?? null;
    }

    /**
     * Finds a user by invite token.
     *
     * @param string $token Invite token
     * @return array|null User row or null
     */
    public function findByInviteToken(string $token): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ', invite_token, invite_token_expires_at
             FROM users WHERE invite_token = ?',
            [$token]
        );
    }

    /**
     * Retrieves all users, optionally filtered by status and/or organization.
     *
     * @param array $filters Optional filters: status, organization_id
     * @return array Array of user rows
     */
    public function findAll(array $filters = []): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['organization_id'])) {
            $sql .= ' AND organization_id = ?';
            $params[] = (int) $filters['organization_id'];
        }

        $sql .= ' ORDER BY name ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Creates a new user.
     *
     * @param array $data User data: name, email, role, organization_id,
     *                    and optionally username, password_hash, status,
     *                    invite_token, invite_token_expires_at
     * @return int The new user's ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO users (username, password_hash, name, email, role, organization_id, status, invite_token, invite_token_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['username'] ?? '',
                $data['password_hash'] ?? '',
                $data['name'],
                $data['email'],
                $data['role'] ?? 'member',
                $data['organization_id'] ?? null,
                $data['status'] ?? 'active',
                $data['invite_token'] ?? null,
                $data['invite_token_expires_at'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates an existing user.
     *
     * Only provided fields are updated. Supports: name, email, role,
     * organization_id, status, username, password_hash.
     *
     * @param int   $id   User ID
     * @param array $data Fields to update
     */
    public function update(int $id, array $data): void
    {
        $allowedFields = ['name', 'email', 'phone', 'location', 'bio', 'email_notifications', 'theme_preference', 'role', 'organization_id', 'status', 'username', 'password_hash'];
        $setClauses = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "`{$field}` = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return;
        }

        // Always update the modification timestamp when other fields change
        $setClauses[] = "`updated_at` = NOW()";

        $params[] = $id;
        $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $this->db->execute($sql, $params);
    }

    /**
     * Deletes a user by ID.
     *
     * @param int $id User ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    /**
     * Activates an invited user by setting their username, password, and status.
     *
     * Clears the invite token after activation.
     *
     * @param int    $id           User ID
     * @param string $username     Chosen username
     * @param string $passwordHash Hashed password
     */
    public function activate(int $id, string $username, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE users
             SET username = ?, password_hash = ?, status = ?, invite_token = NULL, invite_token_expires_at = NULL
             WHERE id = ?',
            [$username, $passwordHash, 'active', $id]
        );
    }

    /**
     * Finds all placeholder users (created during Trello import).
     *
     * Placeholder users have `is_placeholder = 1` and are used to map
     * Trello members who don't yet have a Shuffle account.
     *
     * @return array Array of placeholder user rows
     */
    public function findPlaceholders(): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE is_placeholder = 1'
        );
    }

    /**
     * Deactivates a user by setting their status to inactive.
     *
     * @param int $id User ID
     */
    public function deactivate(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET status = ? WHERE id = ?',
            ['inactive', $id]
        );
    }
}
