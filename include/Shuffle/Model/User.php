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
    private const SELECT_COLUMNS = 'id, username, name, email, role, organization_id, is_placeholder, status, created_at, updated_at';

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
        $allowedFields = ['name', 'email', 'role', 'organization_id', 'status', 'username', 'password_hash'];
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
