<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Organization data access layer.
 *
 * Provides CRUD operations for the organizations table and related
 * membership queries. All queries use parameterized placeholders.
 */
class Organization
{
    private Database $db;

    private const SELECT_COLUMNS = 'id, name, created_at, updated_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds an organization by ID.
     *
     * @param int $id Organization ID
     * @return array|null Organization row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM organizations WHERE id = ?',
            [$id]
        );
    }

    /**
     * Finds an organization by name.
     *
     * @param string $name Organization name
     * @return array|null Organization row or null
     */
    public function findByName(string $name): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM organizations WHERE name = ?',
            [$name]
        );
    }

    /**
     * Retrieves all organizations ordered by name.
     *
     * @return array Array of organization rows
     */
    public function findAll(): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM organizations ORDER BY name ASC'
        );
    }

    /**
     * Retrieves all organizations with member counts in a single query.
     *
     * Avoids N+1 by using a LEFT JOIN with COUNT instead of
     * querying member count per organization.
     *
     * @return array Array of organization rows with 'member_count' field
     */
    public function findAllWithMemberCount(): array
    {
        return $this->db->fetchAll(
            'SELECT o.id, o.name, o.created_at, o.updated_at, COUNT(u.id) AS member_count
             FROM organizations o
             LEFT JOIN users u ON u.organization_id = o.id AND u.status = ?
             GROUP BY o.id
             ORDER BY o.name ASC',
            ['active']
        );
    }

    /**
     * Creates a new organization.
     *
     * @param array $data Organization data: name
     * @return int The new organization's ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO organizations (name) VALUES (?)',
            [$data['name']]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates an existing organization.
     *
     * @param int   $id   Organization ID
     * @param array $data Fields to update (name)
     */
    public function update(int $id, array $data): void
    {
        $setClauses = [];
        $params = [];

        if (array_key_exists('name', $data)) {
            $setClauses[] = '`name` = ?';
            $params[] = $data['name'];
        }

        if (empty($setClauses)) {
            return;
        }

        $setClauses[] = '`updated_at` = NOW()';
        $params[] = $id;

        $sql = 'UPDATE organizations SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $this->db->execute($sql, $params);
    }

    /**
     * Deletes an organization by ID.
     *
     * @param int $id Organization ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM organizations WHERE id = ?', [$id]);
    }

    /**
     * Returns the count of active members in an organization.
     *
     * @param int $id Organization ID
     * @return int Member count
     */
    public function getMemberCount(int $id): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM users WHERE organization_id = ? AND status = ?',
            [$id, 'active']
        );

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Returns all users belonging to an organization.
     *
     * @param int $id Organization ID
     * @return array Array of user rows (without password_hash)
     */
    public function getMembers(int $id): array
    {
        return $this->db->fetchAll(
            'SELECT id, username, name, email, role, organization_id, is_placeholder, status, created_at, updated_at
             FROM users WHERE organization_id = ? ORDER BY name ASC',
            [$id]
        );
    }
}
