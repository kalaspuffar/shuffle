<?php
namespace Shuffle\Service;

use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Lane;

/**
 * Board business logic service.
 *
 * Handles board CRUD, archive/restore, access filtering,
 * and validation. Supports full board view with nested lanes and cards.
 */
class BoardService
{
    /**
     * Default lane set seeded into every new board (LANE-07, LANE-09).
     *
     * Order matters — lanes are created in this order, position 1000, 2000, ...
     */
    public const DEFAULT_LANES = [
        ['title' => 'Inbox',               'icon' => '📥', 'desc' => 'Untouched ideas and capture'],
        ['title' => 'Resources',           'icon' => '🔖', 'desc' => 'Reference material for the project'],
        ['title' => 'Backlog',             'icon' => '⏳', 'desc' => 'Known work, not yet planned'],
        ['title' => 'Up Next',             'icon' => '🚦', 'desc' => 'Committed for the near term, unstarted'],
        ['title' => 'In Progress',         'icon' => '🔨', 'desc' => 'Active work'],
        ['title' => 'Blocked',             'icon' => '⛔', 'desc' => 'Waiting on someone else\'s action'],
        ['title' => 'In Review',           'icon' => '👀', 'desc' => 'Awaiting review'],
        ['title' => 'Waiting for release', 'icon' => '📦', 'desc' => 'Done, waiting to ship with a release'],
        ['title' => 'QA',                  'icon' => '🧪', 'desc' => 'Quality assurance'],
        ['title' => 'Done',                'icon' => '✅', 'desc' => 'Complete'],
        ['title' => "Won't fix",           'icon' => '🚫', 'desc' => 'Rejected or dropped'],
    ];

    private Board $boardModel;
    private ?Lane $laneModel = null;
    private ?Card $cardModel = null;

    /**
     * @param Board     $boardModel Board data access instance
     * @param Lane|null $laneModel  Lane data access instance (optional, for full view)
     * @param Card|null $cardModel  Card data access instance (optional, for full view)
     */
    public function __construct(Board $boardModel, ?Lane $laneModel = null, ?Card $cardModel = null)
    {
        $this->boardModel = $boardModel;
        $this->laneModel = $laneModel;
        $this->cardModel = $cardModel;
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
     * Seeding of the default 11-lane set is optional (LANE-07, LANE-09).
     * If `laneModel` was injected at construction time and
     * `$seedDefaultLanes` is true, the standard lane set is created
     * immediately after the board, in position order.
     *
     * @param array $data        Board data: title, description, visibility, organization_ids
     * @param array $currentUser The authenticated user (used for created_by)
     * @return array The created board record
     * @throws \InvalidArgumentException If validation fails
     */
    public function createBoard(array $data, array $currentUser, bool $seedDefaultLanes = true): array
    {
        $this->validateBoard($data);

        $boardId = $this->boardModel->create([
            'title'            => trim($data['title']),
            'description'      => isset($data['description']) ? trim($data['description']) : null,
            'visibility'       => $data['visibility'] ?? 'private',
            'created_by'       => (int) $currentUser['id'],
            'organization_ids' => $data['organization_ids'] ?? [],
        ]);

        if ($seedDefaultLanes && $this->laneModel !== null) {
            foreach (self::DEFAULT_LANES as $lane) {
                $this->laneModel->create([
                    'board_id' => $boardId,
                    'title'    => $lane['title'],
                    'icon'     => $lane['icon'],
                ]);
            }
            $this->boardModel->incrementVersion($boardId);
        }

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

        // TODO: cascade S3 attachment cleanup when AttachmentService is available
        $this->boardModel->delete($id);
    }

    /**
     * Archives a board.
     *
     * @param int $id Board ID
     * @throws \RuntimeException If board not found
     */
    public function archiveBoard(int $id): void
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            throw new \RuntimeException('Board not found');
        }

        $this->boardModel->archive($id);
    }

    /**
     * Restores an archived board.
     *
     * @param int $id Board ID
     * @throws \RuntimeException If board not found
     */
    public function restoreBoard(int $id): void
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            throw new \RuntimeException('Board not found');
        }

        $this->boardModel->restore($id);
    }

    /**
     * Returns a board with its lanes and cards nested.
     *
     * Each lane includes its ordered cards with metadata (comment count,
     * checklist progress, attachment count, assigned users).
     *
     * @param int $id Board ID
     * @return array|null Board with lanes and cards, or null
     */
    public function getBoardWithLanesAndCards(int $id): ?array
    {
        $board = $this->boardModel->findById($id);
        if ($board === null) {
            return null;
        }

        $board['lanes'] = [];

        if ($this->laneModel === null || $this->cardModel === null) {
            return $board;
        }

        $lanes = $this->laneModel->findByBoard($id);
        $allCards = $this->cardModel->findByBoard($id);

        // Group cards by lane_id
        $cardsByLane = [];
        foreach ($allCards as $card) {
            $cardsByLane[(int) $card['lane_id']][] = $card;
        }

        // Batch-load metadata for all cards to avoid N+1 queries
        $allCardIds = array_map(function ($c) {
            return (int) $c['id'];
        }, $allCards);

        $assignmentMap = $this->cardModel->batchLoadAssignments($allCardIds);
        $commentCountMap = $this->cardModel->batchLoadCommentCounts($allCardIds);
        $attachmentCountMap = $this->cardModel->batchLoadAttachmentCounts($allCardIds);
        $checklistMap = $this->cardModel->batchLoadChecklistProgress($allCardIds);

        foreach ($lanes as &$lane) {
            $laneId = (int) $lane['id'];
            $laneCards = $cardsByLane[$laneId] ?? [];

            // Enrich each card with metadata
            foreach ($laneCards as &$card) {
                $cid = (int) $card['id'];
                $card['assigned_users'] = $assignmentMap[$cid] ?? [];
                $card['comment_count'] = $commentCountMap[$cid] ?? 0;
                $card['attachment_count'] = $attachmentCountMap[$cid] ?? 0;
                $card['checklist_progress'] = $checklistMap[$cid] ?? ['total' => 0, 'done' => 0];
            }
            unset($card);

            $lane['cards'] = $laneCards;
        }
        unset($lane);

        $board['lanes'] = $lanes;

        return $board;
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

        if (mb_strlen(trim($data['title']), 'UTF-8') > 255) {
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
