<?php
namespace Shuffle\Service;

use Shuffle\Model\Board;
use Shuffle\Model\Lane;

/**
 * Lane business logic service.
 *
 * Handles lane CRUD, validation, reordering, and board version bumping.
 */
class LaneService
{
    private Lane $laneModel;
    private Board $boardModel;

    /**
     * @param Lane  $laneModel  Lane data access instance
     * @param Board $boardModel Board data access instance (for version bumping)
     */
    public function __construct(Lane $laneModel, Board $boardModel)
    {
        $this->laneModel = $laneModel;
        $this->boardModel = $boardModel;
    }

    /**
     * Lists lanes for a board, ordered by position.
     *
     * @param int $boardId Board ID
     * @return array Array of lane records
     */
    public function listLanes(int $boardId): array
    {
        return $this->laneModel->findByBoard($boardId);
    }

    /**
     * Retrieves a single lane by ID.
     *
     * @param int $id Lane ID
     * @return array|null Lane row or null
     */
    public function getLane(int $id): ?array
    {
        return $this->laneModel->findById($id);
    }

    /**
     * Creates a new lane in a board.
     *
     * @param int   $boardId Board ID
     * @param array $data    Lane data: title
     * @return array The created lane record
     * @throws \InvalidArgumentException If validation fails
     */
    public function createLane(int $boardId, array $data): array
    {
        $this->validateLane($data);

        $laneId = $this->laneModel->create([
            'board_id' => $boardId,
            'title'    => trim($data['title']),
        ]);

        $this->boardModel->incrementVersion($boardId);

        return $this->laneModel->findById($laneId);
    }

    /**
     * Renames a lane.
     *
     * @param int   $id   Lane ID
     * @param array $data Update data: title
     * @return array The updated lane record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If lane not found
     */
    public function updateLane(int $id, array $data): array
    {
        $lane = $this->laneModel->findById($id);
        if ($lane === null) {
            throw new \RuntimeException('Lane not found');
        }

        $this->validateLane($data);

        $this->laneModel->updateTitle($id, trim($data['title']));
        $this->boardModel->incrementVersion((int) $lane['board_id']);

        return $this->laneModel->findById($id);
    }

    /**
     * Repositions a lane within its board.
     *
     * @param int      $id          Lane ID
     * @param int|null $afterLaneId Place after this lane (null = move to first)
     * @return array The updated lane record
     * @throws \RuntimeException If lane not found
     */
    public function repositionLane(int $id, ?int $afterLaneId): array
    {
        $lane = $this->laneModel->findById($id);
        if ($lane === null) {
            throw new \RuntimeException('Lane not found');
        }

        $boardId = (int) $lane['board_id'];
        $this->laneModel->reposition($id, $boardId, $afterLaneId);
        $this->boardModel->incrementVersion($boardId);

        return $this->laneModel->findById($id);
    }

    /**
     * Deletes a lane if it has no cards.
     *
     * @param int $id Lane ID
     * @throws \RuntimeException If lane not found
     * @throws \LogicException If lane has cards (HTTP 409 Conflict)
     */
    public function deleteLane(int $id): void
    {
        $lane = $this->laneModel->findById($id);
        if ($lane === null) {
            throw new \RuntimeException('Lane not found');
        }

        $cardCount = $this->laneModel->countCards($id);
        if ($cardCount > 0) {
            throw new \LogicException('Cannot delete a lane that contains cards');
        }

        $boardId = (int) $lane['board_id'];
        $this->laneModel->delete($id);
        $this->boardModel->incrementVersion($boardId);
    }

    /**
     * Returns the board ID for a given lane.
     *
     * @param int $id Lane ID
     * @return int|null Board ID or null if lane not found
     */
    public function getBoardIdForLane(int $id): ?int
    {
        $lane = $this->laneModel->findById($id);
        return $lane !== null ? (int) $lane['board_id'] : null;
    }

    /**
     * Validates lane data.
     *
     * @param array $data Lane data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateLane(array $data): void
    {
        if (empty($data['title']) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('Lane title is required');
        }

        if (mb_strlen(trim($data['title']), 'UTF-8') > 255) {
            throw new \InvalidArgumentException('Lane title must be no more than 255 characters');
        }
    }
}
