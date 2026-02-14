<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Board data access layer.
 *
 * Provides CRUD operations for the boards table and the board_organizations
 * junction table. All queries use parameterized placeholders.
 */
class Board
{
    private Database $db;

    private const SELECT_COLUMNS = 'id, title, description, visibility, is_archived, version, created_by, created_at, updated_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds a board by ID.
     *
     * @param int $id Board ID
     * @return array|null Board row or null
     */
    public function findById(int $id): ?array
    {
        $board = $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM boards WHERE id = ?',
            [$id]
        );

        if ($board !== null) {
            $board['organizations'] = $this->getOrganizationIds($id);
        }

        return $board;
    }

    /**
     * Returns boards accessible by the given user.
     *
     * Admins see all boards. Members/viewers see boards they created (private)
     * or boards shared with their organization.
     *
     * @param int    $userId         User ID
     * @param int|null $orgId        User's organization ID
     * @param string $role           User's role
     * @param bool   $includeArchived Whether to include archived boards
     * @return array Array of board rows
     */
    public function findAccessible(int $userId, ?int $orgId, string $role, bool $includeArchived = false): array
    {
        $params = [];

        if ($role === 'admin') {
            // No JOINs for admin, so DISTINCT is unnecessary
            $sql = 'SELECT b.' . self::SELECT_COLUMNS . ' FROM boards b';
            if (!$includeArchived) {
                $sql .= ' WHERE b.is_archived = 0';
            }
            $sql .= ' ORDER BY b.title ASC';
        } else {
            $sql = 'SELECT DISTINCT b.' . self::SELECT_COLUMNS
                 . ' FROM boards b'
                 . ' LEFT JOIN board_organizations bo ON b.id = bo.board_id'
                 . ' WHERE ('
                 . '   (b.visibility = ? AND b.created_by = ?)'
                 . '   OR (b.visibility = ? AND bo.organization_id = ?)'
                 . ' )';
            $params = ['private', $userId, 'organization', $orgId];

            if (!$includeArchived) {
                $sql .= ' AND b.is_archived = 0';
            }
            $sql .= ' ORDER BY b.title ASC';
        }

        $boards = $this->db->fetchAll($sql, $params);

        if (empty($boards)) {
            return $boards;
        }

        // Batch-load organization IDs to avoid N+1 queries
        $boardIds = array_map(function ($b) {
            return (int) $b['id'];
        }, $boards);
        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $orgRows = $this->db->fetchAll(
            'SELECT board_id, organization_id FROM board_organizations WHERE board_id IN (' . $placeholders . ')',
            $boardIds
        );

        $orgMap = [];
        foreach ($orgRows as $row) {
            $orgMap[(int) $row['board_id']][] = (int) $row['organization_id'];
        }

        foreach ($boards as &$board) {
            $board['organizations'] = $orgMap[(int) $board['id']] ?? [];
        }
        unset($board);

        return $boards;
    }

    /**
     * Creates a new board.
     *
     * @param array $data Board data: title, description, visibility, created_by
     * @return int The new board's ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO boards (title, description, visibility, created_by)
             VALUES (?, ?, ?, ?)',
            [
                $data['title'],
                $data['description'] ?? null,
                $data['visibility'] ?? 'private',
                $data['created_by'],
            ]
        );

        $boardId = (int) $this->db->lastInsertId();

        // Sync board_organizations junction
        if (!empty($data['organization_ids'])) {
            $this->syncOrganizations($boardId, $data['organization_ids']);
        }

        return $boardId;
    }

    /**
     * Updates an existing board.
     *
     * Only provided fields are updated. Supports: title, description, visibility.
     *
     * @param int   $id   Board ID
     * @param array $data Fields to update
     */
    public function update(int $id, array $data): void
    {
        $allowedFields = ['title', 'description', 'visibility'];
        $setClauses = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "`{$field}` = ?";
                $params[] = $data[$field];
            }
        }

        if (!empty($setClauses)) {
            // Increment version on every update
            $setClauses[] = '`version` = `version` + 1';
            $setClauses[] = '`updated_at` = NOW()';
            $params[] = $id;
            $sql = 'UPDATE boards SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $this->db->execute($sql, $params);
        }

        // Sync board_organizations if provided
        if (array_key_exists('organization_ids', $data)) {
            $this->syncOrganizations($id, $data['organization_ids'] ?? []);
        }
    }

    /**
     * Deletes a board by ID.
     *
     * @param int $id Board ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM boards WHERE id = ?', [$id]);
    }

    /**
     * Archives a board.
     *
     * @param int $id Board ID
     */
    public function archive(int $id): void
    {
        $this->db->execute(
            'UPDATE boards SET is_archived = 1, version = version + 1, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Restores an archived board.
     *
     * @param int $id Board ID
     */
    public function restore(int $id): void
    {
        $this->db->execute(
            'UPDATE boards SET is_archived = 0, version = version + 1, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns the current version number of a board.
     *
     * @param int $id Board ID
     * @return int|null Version number or null if board not found
     */
    public function getVersion(int $id): ?int
    {
        $row = $this->db->fetch(
            'SELECT version FROM boards WHERE id = ?',
            [$id]
        );

        return $row !== null ? (int) $row['version'] : null;
    }

    /**
     * Increments the board version counter.
     *
     * @param int $id Board ID
     */
    public function incrementVersion(int $id): void
    {
        $this->db->execute(
            'UPDATE boards SET version = version + 1, updated_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns the organization IDs linked to a board.
     *
     * @param int $boardId Board ID
     * @return array Array of organization IDs
     */
    public function getOrganizationIds(int $boardId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT organization_id FROM board_organizations WHERE board_id = ?',
            [$boardId]
        );

        return array_map(function ($row) {
            return (int) $row['organization_id'];
        }, $rows);
    }

    /**
     * Synchronizes the board_organizations junction table.
     *
     * Removes existing associations and inserts the new set.
     *
     * @param int   $boardId         Board ID
     * @param array $organizationIds Array of organization IDs
     */
    private function syncOrganizations(int $boardId, array $organizationIds): void
    {
        $this->db->beginTransaction();
        try {
            // Remove existing associations
            $this->db->execute(
                'DELETE FROM board_organizations WHERE board_id = ?',
                [$boardId]
            );

            // Insert new associations
            foreach ($organizationIds as $orgId) {
                $this->db->execute(
                    'INSERT INTO board_organizations (board_id, organization_id) VALUES (?, ?)',
                    [$boardId, (int) $orgId]
                );
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
