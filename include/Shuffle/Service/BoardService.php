<?php
namespace Shuffle\Service;

use Shuffle\Model\Board;

/**
 * Board business logic service.
 *
 * Handles board CRUD, archive/restore, access filtering,
 * and validation.
 */
class BoardService
{
    private Board $boardModel;

    /**
     * @param Board $boardModel Board data access instance
     */
    public function __construct(Board $boardModel)
    {
        $this->boardModel = $boardModel;
    }

    /**
     * Lists boards accessible by the current user.
     *
     * @param array $currentUser     The authenticated user
     * @param bool  $includeArchived Whether to include archived boards
     * @return array Array of board records
     */
    public function listBoards(array $currentUser, bool $includeArchived = false): array
    {
        return $this->boardModel->findAccessible(
            (int) $currentUser['id'],
            $currentUser['organization_id'] !== null ? (int) $currentUser['organization_id'] : null,
            $currentUser['role'],
            $includeArchived
        );
    }

    /**
     * Retrieves a single board by ID.
     *
     * @param int $id Board ID
     * @return array|null Board row or null if not found
     */
    public function getBoard(int $id): ?array
    {
        return $this->boardModel->findById($id);
    }

    /**
     * Creates a new board.
     *
     * @param array $data        Board data: title, description, visibility, organization_ids
     * @param array $currentUser The authenticated user (used for created_by)
     * @return array The created board record
     * @throws \InvalidArgumentException If validation fails
     */
    public function createBoard(array $data, array $currentUser): array
    {
        $this->validateBoard($data);

        $boardId = $this->boardModel->create([
            'title'            => trim($data['title']),
            'description'      => isset($data['description']) ? trim($data['description']) : null,
            'visibility'       => $data['visibility'] ?? 'private',
            'created_by'       => (int) $currentUser['id'],
            'organization_ids' => $data['organization_ids'] ?? [],
        ]);

        return $this->boardModel->findById($boardId);
    }

    /**
     * Updates an existing board.
     *
     * @param int   $id   Board ID
     * @param array $data Fields to update
     * @return array The updated board record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If board not found
     */
    public function updateBoard(int $id, array $data): array
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            throw new \RuntimeException('Board not found');
        }

        // Merge existing values for validation context
        $mergedData = array_merge($board, $data);
        $this->validateBoard($mergedData);

        $updateData = [];

        if (isset($data['title'])) {
            $updateData['title'] = trim($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'] !== null ? trim($data['description']) : null;
        }
        if (isset($data['visibility'])) {
            $updateData['visibility'] = $data['visibility'];
        }
        if (array_key_exists('organization_ids', $data)) {
            $updateData['organization_ids'] = $data['organization_ids'];
        }

        if (!empty($updateData)) {
            $this->boardModel->update($id, $updateData);
        }

        return $this->boardModel->findById($id);
    }

    /**
     * Deletes a board.
     *
     * @param int $id Board ID
     * @throws \RuntimeException If board not found
     */
    public function deleteBoard(int $id): void
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            throw new \RuntimeException('Board not found');
        }

        $this->boardModel->delete($id);
    }

    /**
     * Archives a board.
     *
     * @param int $id Board ID
     * @return array The updated board record
     * @throws \RuntimeException If board not found
     */
    public function archiveBoard(int $id): array
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            throw new \RuntimeException('Board not found');
        }

        $this->boardModel->archive($id);

        return $this->boardModel->findById($id);
    }

    /**
     * Restores an archived board.
     *
     * @param int $id Board ID
     * @return array The updated board record
     * @throws \RuntimeException If board not found
     */
    public function restoreBoard(int $id): array
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            throw new \RuntimeException('Board not found');
        }

        $this->boardModel->restore($id);

        return $this->boardModel->findById($id);
    }

    /**
     * Returns the current version of a board for polling/ETag.
     *
     * @param int $id Board ID
     * @return int|null Version number or null if board not found
     */
    public function getBoardVersion(int $id): ?int
    {
        return $this->boardModel->getVersion($id);
    }

    /**
     * Validates board data.
     *
     * @param array $data Board data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateBoard(array $data): void
    {
        if (empty($data['title']) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('Board title is required');
        }

        if (strlen(trim($data['title'])) > 255) {
            throw new \InvalidArgumentException('Board title must be no more than 255 characters');
        }

        $validVisibility = ['private', 'organization'];
        $visibility = $data['visibility'] ?? 'private';
        if (!in_array($visibility, $validVisibility, true)) {
            throw new \InvalidArgumentException('Visibility must be private or organization');
        }

        // Organization visibility requires at least one organization
        if ($visibility === 'organization') {
            $orgIds = $data['organization_ids'] ?? [];
            if (empty($orgIds)) {
                throw new \InvalidArgumentException('Organization visibility requires at least one organization');
            }
        }
    }
}
