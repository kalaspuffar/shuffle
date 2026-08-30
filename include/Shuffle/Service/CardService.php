<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Markdown;
use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Lane;
use Shuffle\Model\User;
use Shuffle\Service\AttachmentService;
use Shuffle\Service\CommentService;
use Shuffle\Service\ChecklistService;
use Shuffle\Service\NotificationService;
use Shuffle\Service\CardActivityService;

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
    private ?AttachmentService $attachmentService = null;
    private ?NotificationService $notificationService = null;
    private ?CardActivityService $activityService = null;
    private ?User $userModel = null;
    private ?Lane $laneModel = null;

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
     * Injects the AttachmentService for loading card attachments.
     *
     * @param AttachmentService $attachmentService Attachment service instance
     */
    public function setAttachmentService(AttachmentService $attachmentService): void
    {
        $this->attachmentService = $attachmentService;
    }

    /**
     * Injects the NotificationService for assignment notifications.
     *
     * @param NotificationService $notificationService Notification service instance
     */
    public function setNotificationService(NotificationService $notificationService): void
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Injects the CardActivityService for card lifecycle logging (ACTIVITY-01).
     *
     * The service is optional — if not injected, the log writes are skipped.
     * This preserves backward compatibility with the legacy E2E harness
     * that constructs CardService without the activity stack.
     *
     * @param CardActivityService $activityService Activity service instance
     */
    public function setActivityService(CardActivityService $activityService): void
    {
        $this->activityService = $activityService;
    }

    /**
     * Injects the User model (used by assignment hooks to snapshot
     * user names for the activity payload).
     *
     * @param User $user User data access instance
     */
    public function setUserModel(User $user): void
    {
        $this->userModel = $user;
    }

    /**
     * Injects the Lane model (used by the move hook to snapshot from/to
     * lane titles into the activity payload).
     *
     * @param Lane $lane Lane data access instance
     */
    public function setLaneModel(Lane $lane): void
    {
        $this->laneModel = $lane;
    }

    /**
     * Retrieves a single card by ID with rendered Markdown description.
     *
     * Requires CommentService and ChecklistService to be injected via
     * setCommentService() and setChecklistService() for full card data.
     * If not injected, comments and checklists will be empty arrays and
     * a warning will be logged to help catch misconfiguration.
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
        if ($this->commentService !== null) {
            $card['comments'] = $this->commentService->getCommentsForCard($id);
        } else {
            $card['comments'] = [];
            error_log('CardService::getCard() — CommentService not injected; comments will be empty');
        }

        // Load checklists with items
        if ($this->checklistService !== null) {
            $card['checklists'] = $this->checklistService->getChecklistsForCard($id);
        } else {
            $card['checklists'] = [];
            error_log('CardService::getCard() — ChecklistService not injected; checklists will be empty');
        }

        // Load attachments
        if ($this->attachmentService !== null) {
            $card['attachments'] = $this->attachmentService->getAttachmentsForCard($id);
        } else {
            $card['attachments'] = [];
            error_log('CardService::getCard() — AttachmentService not injected; attachments will be empty');
        }
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

        // Sync assignments and notify newly assigned users
        if (isset($data['assigned_user_ids']) && is_array($data['assigned_user_ids'])) {
            $newlyAssigned = $this->cardModel->syncAssignments($cardId, $data['assigned_user_ids']);

            if (!empty($newlyAssigned) && $this->notificationService !== null) {
                $this->notificationService->notifyAssignment(
                    $cardId,
                    $newlyAssigned,
                    (int) $currentUser['id'],
                    trim($data['title'])
                );
            }
        }

        $this->boardModel->incrementVersion($boardId);
        $this->logCardCreated($cardId, $currentUser);

        return $this->getCard($cardId);
    }

    /**
     * Emits a card_created activity row (ACTIVITY-01) after a successful
     * card creation. Skipped when the CardActivityService has not been
     * injected (legacy E2E harness path).
     *
     * @param int   $cardId      Card ID that was created
     * @param array $currentUser Actor
     */
    private function logCardCreated(int $cardId, array $currentUser): void
    {
        if ($this->activityService === null) {
            return;
        }

        try {
            $this->activityService->log($cardId, 'card_created', (int) $currentUser['id'], null);
        } catch (\Throwable $e) {
            // Hard-fail policy (decision §5.5): a card creation without its
            // log row is a bug. But — to avoid the log write taking down
            // an otherwise-successful card creation due to a DB quirk at
            // this exact moment — we surface to error_log + rethrow.
            error_log('CardService::logCardCreated failed for card ' . $cardId . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Emits a card_moved activity row (ACTIVITY-01) with from/to lane snapshots.
     *
     * Decision §5.5: this is the ONE non-fatal hook — the caller (moveCard)
     * catches the exception so a log-write failure never blocks a UI
     * drag-drop. The from-lane snapshot is captured BEFORE the move.
     */
    private function logCardMoved(int $cardId, ?array $fromLane, array $toLane, array $currentUser): void
    {
        if ($this->activityService === null) {
            return;
        }

        $payload = [
            'from_lane' => $this->activityService->laneSnapshot($fromLane),
            'to_lane'   => $this->activityService->laneSnapshot($toLane),
        ];

        $this->activityService->log($cardId, 'card_moved', (int) $currentUser['id'], $payload);
    }

    /**
     * Emits card_edited / assigned / unassigned activity rows (ACTIVITY-01)
     * based on the update payload. Only the fields that actually changed
     * are recorded (title stored, description sha1 + changed flag, due_date
     * stored as before/after).
     */
    private function logCardEdit(int $cardId, array $oldCard, array $updateData, array $currentUser): void
    {
        if ($this->activityService === null) {
            return;
        }

        $changedFields = [];
        $before = null;
        $after = null;

        $normalizedOldTitle = $oldCard['title'] ?? null;

        if (array_key_exists('title', $updateData) && trim($updateData['title']) !== ($normalizedOldTitle ?? '')) {
            $changedFields[] = 'title';
            $before = ['title' => $normalizedOldTitle];
            $after  = ['title' => trim($updateData['title'])];
        }

        if (array_key_exists('due_date', $updateData) && $updateData['due_date'] !== ($oldCard['due_date'] ?? null)) {
            $changedFields[] = 'due_date';
            $before = $before ?? [];
            $after  = $after ?? [];
            $before['due_date'] = $oldCard['due_date'] ?? null;
            $after['due_date']  = $updateData['due_date'];
        }

        if (array_key_exists('description', $updateData)) {
            $newDesc = $updateData['description'] !== null ? trim($updateData['description']) : null;
            $oldDesc = $oldCard['description'] ?? null;
            $oldNorm = $oldDesc !== null ? trim($oldDesc) : null;

            if ($newDesc !== $oldNorm) {
                $changedFields[] = 'description';
                $before = $before ?? [];
                $after  = $after ?? [];
                $before['description_sha1'] = $oldDesc !== null ? sha1($oldDesc) : null;
                $after['description_sha1']  = $newDesc !== null ? sha1($newDesc) : null;
                $before['description_changed'] = true;
            }
        }

        if ($changedFields === []) {
            return; // no-op edit
        }

        $payload = ['fields_changed' => $changedFields];
        if ($before !== null) {
            $payload['before'] = $before;
        }
        if ($after !== null) {
            $payload['after'] = $after;
        }

        $this->activityService->log($cardId, 'card_edited', (int) $currentUser['id'], $payload);
    }

    /**
     * Emits assigned/unassigned rows based on the assignment delta.
     *
     * @param int         $cardId
     * @param array|int[] $oldUserIds User IDs previously assigned
     * @param array|int[] $newUserIds User IDs requested
     * @param array       $currentUser Actor
     */
    private function logAssignments(int $cardId, array $oldUserIds, array $newUserIds, array $currentUser): void
    {
        if ($this->activityService === null) {
            return;
        }

        $oldIds = array_map('intval', $oldUserIds);
        $newIds = array_map('intval', $newUserIds);

        $added   = array_values(array_diff($newIds, $oldIds));
        $removed = array_values(array_diff($oldIds, $newIds));

        foreach ($added as $uid) {
            $snap = $this->userModel !== null ? $this->userModel->findById($uid) : null;
            $this->activityService->log(
                $cardId,
                'assigned',
                (int) $currentUser['id'],
                ['user' => $this->activityService->userSnapshot($snap)]
            );
        }

        foreach ($removed as $uid) {
            $snap = $this->userModel !== null ? $this->userModel->findById($uid) : null;
            $this->activityService->log(
                $cardId,
                'unassigned',
                (int) $currentUser['id'],
                ['user' => $this->activityService->userSnapshot($snap)]
            );
        }
    }

    /**
     * Emits a card_archived / card_restored row.
     */
    private function logArchive(int $cardId, bool $archived, array $currentUser): void
    {
        if ($this->activityService === null) {
            return;
        }

        $this->activityService->log(
            $cardId,
            $archived ? 'card_archived' : 'card_restored',
            (int) $currentUser['id'],
            null
        );
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
    public function updateCard(int $id, array $data, array $currentUser = []): array
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
            // Log AFTER the update commits (ACTIVITY-01: a failed action is
            // not logged, and a logged action always happened).
            $this->logCardEdit($id, $card, $updateData, $currentUser);
        }

        // Sync assignments and notify newly assigned users
        if (isset($data['assigned_user_ids']) && is_array($data['assigned_user_ids'])) {
            $oldAssignedIds = array_map(
                static fn (array $u): int => (int) $u['id'],
                $card['assigned_users'] ?? []
            );
            $newAssignedIds = array_map('intval', array_values($data['assigned_user_ids']));

            $newlyAssigned = $this->cardModel->syncAssignments($id, $data['assigned_user_ids']);

            // Log AFTER the assignment sync (ACTIVITY-01: a failed action
            // is not logged, and a logged action always happened).
            $this->logAssignments($id, $oldAssignedIds, $newAssignedIds, $currentUser);

            if (!empty($newlyAssigned) && $this->notificationService !== null) {
                $cardTitle = $updateData['title'] ?? $card['title'];
                $assignerUserId = !empty($currentUser) ? (int) $currentUser['id'] : 0;

                $this->notificationService->notifyAssignment(
                    $id,
                    $newlyAssigned,
                    $assignerUserId,
                    $cardTitle
                );
            }
        }

        $boardId = $this->cardModel->getBoardId($id);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }

        return $this->getCard($id);
    }

    /**
     * Moves a card to a new lane and/or position.
     *
     * Activity log (ACTIVITY-01): records card_moved with from/to lane
     * snapshots. This is the ONE non-fatal log hook (decision §5.5):
     * a log-write failure is caught, written to the PHP error log, and
     * the move still commits — drag-drop must never be blocked by the
     * audit trail.
     *
     * @param int      $id          Card ID
     * @param int      $laneId      Target lane ID
     * @param int|null $afterCardId Place after this card (null = top)
     * @param array    $currentUser Acting user (for the log; [] = skip log)
     * @return array The moved card record
     * @throws \RuntimeException If card not found
     */
    public function moveCard(int $id, int $laneId, ?int $afterCardId, array $currentUser = []): array
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        // Capture the from-lane BEFORE the move (it may differ from the
        // target lane; the snapshot is what the feed renders).
        $fromLane = null;
        $toLane = null;
        if ($this->laneModel !== null) {
            $fromLane = $this->laneModel->findById((int) $card['lane_id']);
            $toLane = $this->laneModel->findById($laneId);
        }

        $this->cardModel->move($id, $laneId, $afterCardId);

        $boardId = $this->cardModel->getBoardId($id);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }

        // Non-fatal log hook (decision §5.5, the drag-drop exception).
        if ($this->activityService !== null && !empty($currentUser)) {
            try {
                $this->logCardMoved($id, $fromLane, $toLane ?? [], $currentUser);
            } catch (\Throwable $e) {
                error_log('CardService::moveCard activity log failed for card ' . $id . ': ' . $e->getMessage());
            }
        }

        return $this->getCard($id);
    }

    /**
     * Archives a card.
     *
     * @param int   $id          Card ID
     * @param array $currentUser Acting user (for the activity log)
     * @throws \RuntimeException If card not found
     */
    public function archiveCard(int $id, array $currentUser = []): void
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $this->cardModel->archive($id);

        $this->logArchive($id, true, $currentUser);

        $boardId = $this->cardModel->getBoardId($id);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }

    /**
     * Restores an archived card.
     *
     * @param int   $id          Card ID
     * @param array $currentUser Acting user (for the activity log)
     * @throws \RuntimeException If card not found
     */
    public function restoreCard(int $id, array $currentUser = []): void
    {
        $card = $this->cardModel->findById($id);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $this->cardModel->restore($id);

        $this->logArchive($id, false, $currentUser);

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
