<?php
namespace Shuffle\Service;

use Shuffle\Core\Markdown;
use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Comment;

/**
 * Comment business logic service.
 *
 * Handles comment CRUD, validation, Markdown rendering,
 * ownership checks, and board version bumping.
 */
class CommentService
{
    private Comment $commentModel;
    private Card $cardModel;
    private Board $boardModel;

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

        $this->commentModel->update($commentId, trim($data['body']));

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
        $this->commentModel->delete($commentId);

        if ($boardId !== null) {
            $this->boardModel->incrementVersion($boardId);
        }
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
