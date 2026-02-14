<?php
namespace Shuffle\Service;

use Shuffle\Core\Markdown;
use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Service\CommentService;
use Shuffle\Service\ChecklistService;

/**
 * Card business logic service.
 *
 * Handles card CRUD, move, archive/restore, validation,
 * Markdown rendering, and board version bumping.
 */
class CardService
{
    private Card $cardModel;
    private Board $boardModel;
    private ?CommentService $commentService = null;
    private ?ChecklistService $checklistService = null;

    /**
     * @param Card  $cardModel  Card data access instance
     * @param Board $boardModel Board data access instance (for version bumping)
     */
    public function __construct(Card $cardModel, Board $boardModel)
    {
        $this->cardModel = $cardModel;
        $this->boardModel = $boardModel;
    }

    /**
     * Injects the CommentService for loading card comments.
     *
     * @param CommentService $commentService Comment service instance
     */
    public function setCommentService(CommentService $commentService): void
    {
        $this->commentService = $commentService;
    }

    /**
     * Injects the ChecklistService for loading card checklists.
     *
     * @param ChecklistService $checklistService Checklist service instance
     */
    public function setChecklistService(ChecklistService $checklistService): void
    {
        $this->checklistService = $checklistService;
    }

    /**
     * Retrieves a single card by ID with rendered Markdown description.
     *
     * @param int $id Card ID
     * @return array|null Card with description_html, or null
     */
    public function getCard(int $id): ?array
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            return null;
        }

        $card['description_html'] = Markdown::render($card['description']);
        $card['comment_count'] = $this->cardModel->getCommentCount($id);
        $card['attachment_count'] = $this->cardModel->getAttachmentCount($id);
        $card['checklist_progress'] = $this->cardModel->getChecklistProgress($id);

        // Load comments with rendered Markdown
        $card['comments'] = $this->commentService !== null
            ? $this->commentService->getCommentsForCard($id)
            : [];

        // Load checklists with items
        $card['checklists'] = $this->checklistService !== null
            ? $this->checklistService->getChecklistsForCard($id)
            : [];

        // TODO: Phase 6+ — populate when Attachments feature is implemented
        $card['attachments'] = [];
        // TODO: Post-MVP — populate when Labels feature is implemented
        $card['labels'] = [];

        return $card;
    }

    /**
     * Creates a new card in a lane.
     *
     * @param int   $boardId     Board ID (for version bumping)
     * @param int   $laneId      Lane ID
     * @param array $data        Card data: title, description, due_date
     * @param array $currentUser Authenticated user
     * @return array The created card record
     * @throws \InvalidArgumentException If validation fails
     */
    public function createCard(int $boardId, int $laneId, array $data, array $currentUser): array
    {
        $this->validateCard($data);

        $cardId = $this->cardModel->create([
            'lane_id'     => $laneId,
            'title'       => trim($data['title']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'due_date'    => $data['due_date'] ?? null,
            'created_by'  => (int) $currentUser['id'],
        ]);

        $this->boardModel->incrementVersion($boardId);

        return $this->getCard($cardId);
    }

    /**
     * Updates a card's fields.
     *
     * @param int   $id   Card ID
     * @param array $data Fields to update: title, description, due_date
     * @return array The updated card record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If card not found
     */
    public function updateCard(int $id, array $data): array
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        // Validate with merged data
        $mergedData = array_merge($card, $data);
        $this->validateCard($mergedData);

        $updateData = [];

        if (isset($data['title'])) {
            $updateData['title'] = trim($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'] !== null ? trim($data['description']) : null;
        }
        if (array_key_exists('due_date', $data)) {
            $updateData['due_date'] = $data['due_date'];
        }

        if (!empty($updateData)) {
            $this->cardModel->update($id, $updateData);
            $boardId = $this->cardModel->getBoardId($id);
            if ($boardId !== null) {
                $this->boardModel->incrementVersion($boardId);
            }
        }

        return $this->getCard($id);
    }

    /**
     * Moves a card to a new lane and/or position.
     *
     * @param int      $id          Card ID
     * @param int      $laneId      Target lane ID
     * @param int|null $afterCardId Place after this card (null = top)
     * @return array The moved card record
     * @throws \RuntimeException If card not found
     */
    public function moveCard(int $id, int $laneId, ?int $afterCardId): array
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $this->cardModel->move($id, $laneId, $afterCardId);

        $boardId = $this->cardModel->getBoardId($id);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }

        return $this->getCard($id);
    }

    /**
     * Archives a card.
     *
     * @param int $id Card ID
     * @throws \RuntimeException If card not found
     */
    public function archiveCard(int $id): void
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $this->cardModel->archive($id);

        $boardId = $this->cardModel->getBoardId($id);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }

    /**
     * Restores an archived card.
     *
     * @param int $id Card ID
     * @throws \RuntimeException If card not found
     */
    public function restoreCard(int $id): void
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $this->cardModel->restore($id);

        $boardId = $this->cardModel->getBoardId($id);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }

    /**
     * Deletes a card permanently.
     *
     * @param int $id Card ID
     * @throws \RuntimeException If card not found
     */
    public function deleteCard(int $id): void
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $boardId = $this->cardModel->getBoardId($id);
        $this->cardModel->delete($id);

        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }

    /**
     * Returns the board ID for a given card.
     *
     * @param int $id Card ID
     * @return int|null Board ID or null
     */
    public function getBoardIdForCard(int $id): ?int
    {
        return $this->cardModel->getBoardId($id);
    }

    /**
     * Validates card data.
     *
     * @param array $data Card data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateCard(array $data): void
    {
        if (empty($data['title']) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('Card title is required');
        }

        if (mb_strlen(trim($data['title']), 'UTF-8') > 255) {
            throw new \InvalidArgumentException('Card title must be no more than 255 characters');
        }

        if (isset($data['due_date']) && $data['due_date'] !== null) {
            $date = \DateTime::createFromFormat('Y-m-d', $data['due_date']);
            if ($date === false || $date->format('Y-m-d') !== $data['due_date']) {
                throw new \InvalidArgumentException('Due date must be in YYYY-MM-DD format');
            }
        }
    }
}
