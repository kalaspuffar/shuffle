<?php
namespace Shuffle\Service;

use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Checklist;
use Shuffle\Model\ChecklistItem;

/**
 * Checklist business logic service.
 *
 * Handles checklist and checklist item CRUD, validation,
 * and board version bumping.
 */
class ChecklistService
{
    private Checklist $checklistModel;
    private ChecklistItem $itemModel;
    private Card $cardModel;
    private Board $boardModel;

    /**
     * @param Checklist     $checklistModel Checklist data access instance
     * @param ChecklistItem $itemModel      ChecklistItem data access instance
     * @param Card          $cardModel      Card data access instance (for board ID lookup)
     * @param Board         $boardModel     Board data access instance (for version bumping)
     */
    public function __construct(
        Checklist $checklistModel,
        ChecklistItem $itemModel,
        Card $cardModel,
        Board $boardModel
    ) {
        $this->checklistModel = $checklistModel;
        $this->itemModel = $itemModel;
        $this->cardModel = $cardModel;
        $this->boardModel = $boardModel;
    }

    /**
     * Retrieves all checklists for a card with their items.
     *
     * @param int $cardId Card ID
     * @return array Array of checklists, each with an 'items' key
     */
    public function getChecklistsForCard(int $cardId): array
    {
        $checklists = $this->checklistModel->findByCard($cardId);

        foreach ($checklists as &$checklist) {
            $checklist['items'] = $this->itemModel->findByChecklist((int) $checklist['id']);
        }

        return $checklists;
    }

    /**
     * Creates a new checklist on a card.
     *
     * @param int   $cardId Card ID
     * @param array $data   Checklist data: title
     * @return array The created checklist with items
     * @throws \InvalidArgumentException If validation fails
     */
    public function createChecklist(int $cardId, array $data): array
    {
        $this->validateChecklistTitle($data);

        $checklistId = $this->checklistModel->create([
            'card_id' => $cardId,
            'title'   => trim($data['title']),
        ]);

        $this->bumpBoardVersionForCard($cardId);

        $checklist = $this->checklistModel->findById($checklistId);
        $checklist['items'] = [];

        return $checklist;
    }

    /**
     * Updates a checklist's title.
     *
     * @param int   $checklistId Checklist ID
     * @param array $data        Update data: title
     * @return array The updated checklist with items
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If checklist not found
     */
    public function updateChecklist(int $checklistId, array $data): array
    {
        $checklist = $this->checklistModel->findById($checklistId);
        if ($checklist === null) {
            throw new \RuntimeException('Checklist not found');
        }

        $this->validateChecklistTitle($data);

        $this->checklistModel->update($checklistId, trim($data['title']));

        $this->bumpBoardVersionForCard((int) $checklist['card_id']);

        $updated = $this->checklistModel->findById($checklistId);
        $updated['items'] = $this->itemModel->findByChecklist($checklistId);

        return $updated;
    }

    /**
     * Deletes a checklist and all its items.
     *
     * @param int $checklistId Checklist ID
     * @throws \RuntimeException If checklist not found
     */
    public function deleteChecklist(int $checklistId): void
    {
        $checklist = $this->checklistModel->findById($checklistId);
        if ($checklist === null) {
            throw new \RuntimeException('Checklist not found');
        }

        $cardId = (int) $checklist['card_id'];
        $this->checklistModel->delete($checklistId);
        $this->bumpBoardVersionForCard($cardId);
    }

    /**
     * Creates a new item in a checklist.
     *
     * @param int   $checklistId Checklist ID
     * @param array $data        Item data: title, assigned_user_id (optional)
     * @return array The created item
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If checklist not found
     */
    public function createItem(int $checklistId, array $data): array
    {
        $checklist = $this->checklistModel->findById($checklistId);
        if ($checklist === null) {
            throw new \RuntimeException('Checklist not found');
        }

        $this->validateItemTitle($data);

        $itemId = $this->itemModel->create([
            'checklist_id'     => $checklistId,
            'title'            => trim($data['title']),
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
        ]);

        $this->bumpBoardVersionForCard((int) $checklist['card_id']);

        return $this->itemModel->findById($itemId);
    }

    /**
     * Updates a checklist item's fields.
     *
     * @param int   $itemId Item ID
     * @param array $data   Fields to update: title, is_checked, assigned_user_id
     * @return array The updated item
     * @throws \RuntimeException If item not found
     */
    public function updateItem(int $itemId, array $data): array
    {
        $item = $this->itemModel->findById($itemId);
        if ($item === null) {
            throw new \RuntimeException('Checklist item not found');
        }

        if (isset($data['title'])) {
            $this->validateItemTitle($data);
            $data['title'] = trim($data['title']);
        }

        if (isset($data['is_checked'])) {
            $data['is_checked'] = $data['is_checked'] ? 1 : 0;
        }

        $updateData = [];
        $allowedFields = ['title', 'is_checked', 'assigned_user_id'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $this->itemModel->update($itemId, $updateData);

            $checklistId = (int) $item['checklist_id'];
            $cardId = $this->checklistModel->getCardId($checklistId);
            if ($cardId !== null) {
                $this->bumpBoardVersionForCard($cardId);
            }
        }

        return $this->itemModel->findById($itemId);
    }

    /**
     * Repositions a checklist item.
     *
     * @param int      $itemId      Item ID
     * @param int|null $afterItemId Place after this item (null = move to top)
     * @return array The repositioned item
     * @throws \RuntimeException If item not found
     */
    public function repositionItem(int $itemId, ?int $afterItemId): array
    {
        $item = $this->itemModel->findById($itemId);
        if ($item === null) {
            throw new \RuntimeException('Checklist item not found');
        }

        $this->itemModel->reposition($itemId, $afterItemId);

        $checklistId = (int) $item['checklist_id'];
        $cardId = $this->checklistModel->getCardId($checklistId);
        if ($cardId !== null) {
            $this->bumpBoardVersionForCard($cardId);
        }

        return $this->itemModel->findById($itemId);
    }

    /**
     * Deletes a checklist item.
     *
     * @param int $itemId Item ID
     * @throws \RuntimeException If item not found
     */
    public function deleteItem(int $itemId): void
    {
        $item = $this->itemModel->findById($itemId);
        if ($item === null) {
            throw new \RuntimeException('Checklist item not found');
        }

        $checklistId = (int) $item['checklist_id'];
        $cardId = $this->checklistModel->getCardId($checklistId);

        $this->itemModel->delete($itemId);

        if ($cardId !== null) {
            $this->bumpBoardVersionForCard($cardId);
        }
    }

    /**
     * Returns the card ID for a checklist.
     *
     * @param int $checklistId Checklist ID
     * @return int|null Card ID or null
     */
    public function getCardIdForChecklist(int $checklistId): ?int
    {
        return $this->checklistModel->getCardId($checklistId);
    }

    /**
     * Returns the card ID for a checklist item (via its checklist).
     *
     * @param int $itemId Item ID
     * @return int|null Card ID or null
     */
    public function getCardIdForItem(int $itemId): ?int
    {
        $checklistId = $this->itemModel->getChecklistId($itemId);
        if ($checklistId === null) {
            return null;
        }

        return $this->checklistModel->getCardId($checklistId);
    }

    /**
     * Validates checklist title data.
     *
     * @param array $data Checklist data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateChecklistTitle(array $data): void
    {
        if (empty($data['title']) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('Checklist title is required');
        }

        if (mb_strlen(trim($data['title']), 'UTF-8') > 255) {
            throw new \InvalidArgumentException('Checklist title must be no more than 255 characters');
        }
    }

    /**
     * Validates checklist item title data.
     *
     * @param array $data Item data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateItemTitle(array $data): void
    {
        if (empty($data['title']) || trim($data['title']) === '') {
            throw new \InvalidArgumentException('Item title is required');
        }

        if (mb_strlen(trim($data['title']), 'UTF-8') > 255) {
            throw new \InvalidArgumentException('Item title must be no more than 255 characters');
        }
    }

    /**
     * Bumps the board version for a given card.
     *
     * @param int $cardId Card ID
     */
    private function bumpBoardVersionForCard(int $cardId): void
    {
        $boardId = $this->cardModel->getBoardId($cardId);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }
}
