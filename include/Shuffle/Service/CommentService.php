<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Markdown;
use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Comment;
use Shuffle\Service\NotificationService;
use Shuffle\Service\CardActivityService;

/**
 * Comment business logic service.
 *
 * Handles comment CRUD, validation, Markdown rendering,
 * ownership checks, board version bumping, and comment notifications.
 */
class CommentService
{
    private Comment $commentModel;
    private Card $cardModel;
    private Board $boardModel;
    private ?NotificationService $notificationService = null;
    private ?CardActivityService $activityService = null;

    /**
     * @param Comment $commentModel Comment data access instance
     * @param Card    $cardModel    Card data access instance (for board ID lookup)
     * @param Board   $boardModel   Board data access instance (for version bumping)
     */
    public function __construct(Comment $commentModel, Card $cardModel, Board $boardModel)
    {
        $this->commentModel = $commentModel;
        $this->cardModel = $cardModel;
        $this->boardModel = $boardModel;
    }

    /**
     * Injects the NotificationService for comment notifications.
     *
     * @param NotificationService $notificationService Notification service instance
     */
    public function setNotificationService(NotificationService $notificationService): void
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Injects the CardActivityService for comment lifecycle logging (ACTIVITY-01).
     *
     * Optional — when not injected, comment lifecycle events are not logged.
     * The comment hooks are the second-most-frequent log writers after
     * card moves, but they still use the default hard-fail policy: a
     * log-write failure here surfaces back to the caller (unlike card
     * moves, which catch and swallow per decision §5.5).
     *
     * @param CardActivityService $activityService Activity service instance
     */
    public function setActivityService(CardActivityService $activityService): void
    {
        $this->activityService = $activityService;
    }

    /**
     * Retrieves all comments for a card with rendered Markdown bodies.
     *
     * @param int $cardId Card ID
     * @return array Array of comments with body_html
     */
    public function getCommentsForCard(int $cardId): array
    {
        $comments = $this->commentModel->findByCard($cardId);

        foreach ($comments as &$comment) {
            $comment['body_html'] = Markdown::render($comment['body']);
        }

        return $comments;
    }

    /**
     * Creates a new comment on a card.
     *
     * @param int   $cardId      Card ID
     * @param array $data        Comment data: body
     * @param array $currentUser Authenticated user
     * @return array The created comment with body_html
     * @throws \InvalidArgumentException If validation fails
     */
    public function createComment(int $cardId, array $data, array $currentUser): array
    {
        $this->validateComment($data);

        $commentId = $this->commentModel->create([
            'card_id' => $cardId,
            'user_id' => (int) $currentUser['id'],
            'body'    => trim($data['body']),
        ]);

        $boardId = $this->cardModel->getBoardId($cardId);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }

        // ACTIVITY-01: comment_created — author name snapshot.
        // The actor and the author are the same user here (you can only
        // create a comment as yourself).
        $this->logCommentEvent($cardId, (int) $currentUser['id'], 'comment_created', [
            'comment_id' => $commentId,
            'author'     => ['id' => (int) $currentUser['id'], 'name' => $currentUser['name'] ?? null],
        ]);

        // Notify all users assigned to the card (except the comment author);
        // v1.8: also notify the creator (NOTIF-07) and stamp the new
        // comment's id on every row (NOTIF-09 deep link).
        if ($this->notificationService !== null) {
            $card = $this->cardModel->findById($cardId);
            if ($card !== null) {
                $this->notificationService->notifyComment(
                    $cardId,
                    (int) $currentUser['id'],
                    $card['title'],
                    $currentUser['name'],
                    $commentId,
                    $card
                );
            }
        }

        $comment = $this->commentModel->findById($commentId);
        $comment['body_html'] = Markdown::render($comment['body']);

        return $comment;
    }

    /**
     * Updates a comment's body.
     *
     * Only the comment author or an admin may update a comment.
     *
     * @param int   $commentId   Comment ID
     * @param array $data        Update data: body
     * @param array $currentUser Authenticated user
     * @return array The updated comment with body_html
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If comment not found or permission denied
     */
    public function updateComment(int $commentId, array $data, array $currentUser): array
    {
        $comment = $this->commentModel->findById($commentId);
        if ($comment === null) {
            throw new \RuntimeException('Comment not found');
        }

        // Ownership check: author or admin
        if ((int) $comment['user_id'] !== (int) $currentUser['id']
            && $currentUser['role'] !== 'admin') {
            throw new \RuntimeException('Permission denied');
        }

        $this->validateComment($data);

        // ACTIVITY-01: comment_edited — only on a real body change
        // (no-op edits must NOT log; decision: noise reduction).
        $newBody = trim($data['body']);
        if ($newBody !== (string) $comment['body']) {
            $this->commentModel->update($commentId, $newBody);
            $this->logCommentEvent((int) $comment['card_id'], (int) $currentUser['id'], 'comment_edited', [
                'comment_id' => (int) $commentId,
                'author'     => ['id' => (int) $comment['user_id'], 'name' => $comment['user_name'] ?? null],
            ]);
        } else {
            $this->commentModel->update($commentId, $newBody);
        }

        $boardId = $this->cardModel->getBoardId((int) $comment['card_id']);
        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }

        $updated = $this->commentModel->findById($commentId);
        $updated['body_html'] = Markdown::render($updated['body']);

        return $updated;
    }

    /**
     * Deletes a comment.
     *
     * Only the comment author or an admin may delete a comment.
     *
     * @param int   $commentId   Comment ID
     * @param array $currentUser Authenticated user
     * @throws \RuntimeException If comment not found or permission denied
     */
    public function deleteComment(int $commentId, array $currentUser): void
    {
        $comment = $this->commentModel->findById($commentId);
        if ($comment === null) {
            throw new \RuntimeException('Comment not found');
        }

        // Ownership check: author or admin
        if ((int) $comment['user_id'] !== (int) $currentUser['id']
            && $currentUser['role'] !== 'admin') {
            throw new \RuntimeException('Permission denied');
        }

        $boardId = $this->cardModel->getBoardId((int) $comment['card_id']);

        // ACTIVITY-01: comment_deleted — capture a body excerpt BEFORE the
        // delete so the log row is proof the comment existed (decision §5.3:
        // "a deleted comment keeps an 80-char body excerpt").
        $bodyExcerpt = mb_substr((string) $comment['body'], 0, 80, 'UTF-8');

        $this->commentModel->delete($commentId);

        $this->logCommentEvent((int) $comment['card_id'], (int) $currentUser['id'], 'comment_deleted', [
            'comment_id'   => (int) $commentId,
            'author'       => ['id' => (int) $comment['user_id'], 'name' => $comment['user_name'] ?? null],
            'body_excerpt' => $bodyExcerpt,
        ]);

        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
    }

    /**
     * Emits a comment lifecycle activity row (comment_created /
     * comment_edited / comment_deleted — ACTIVITY-01).
     *
     * The actor is the user who performed the action ($actorId — the
     * current user; for created events this is the same as the comment
     * author since you can only create a comment as yourself).
     *
     * The payload's `author` field is the original comment author,
     * which may differ from the actor when an admin edits or deletes
     * somebody else's comment.
     *
     * Hard-fail policy: a log-write failure propagates to the caller.
     * The payload carries the comment_id + author snapshot; on delete
     * also an 80-char body_excerpt as proof the comment existed
     * (decision §5.3).
     *
     * @param int   $cardId  Card the comment belongs to
     * @param int   $actorId User id of the actor (the current user)
     * @param string $event  comment_created|comment_edited|comment_deleted
     * @param array $payload Event-specific payload (comment_id,
     *                       author {id,name}; on delete body_excerpt)
     */
    private function logCommentEvent(int $cardId, int $actorId, string $event, array $payload): void
    {
        if ($this->activityService === null) {
            return;
        }

        $this->activityService->log($cardId, $event, $actorId, $payload);
    }

    /**
     * Returns the card ID for a comment.
     *
     * @param int $commentId Comment ID
     * @return int|null Card ID or null
     */
    public function getCardIdForComment(int $commentId): ?int
    {
        return $this->commentModel->getCardId($commentId);
    }

    /**
     * Validates comment data.
     *
     * @param array $data Comment data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateComment(array $data): void
    {
        if (empty($data['body']) || trim($data['body']) === '') {
            throw new \InvalidArgumentException('Comment body is required');
        }

        if (mb_strlen(trim($data['body']), 'UTF-8') > 10000) {
            throw new \InvalidArgumentException('Comment body must be no more than 10000 characters');
        }
    }
}
