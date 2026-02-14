<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\CardService;
use Shuffle\Service\CommentService;

/**
 * Comment management API controller.
 *
 * Handles comment CRUD endpoints.
 * Access is enforced via Auth::canAccessBoard on the parent card's board.
 */
class CommentController
{
    private Auth $auth;
    private CommentService $commentService;
    private CardService $cardService;

    /**
     * @param Auth           $auth           Auth service
     * @param CommentService $commentService Comment business logic service
     * @param CardService    $cardService    Card service (for board access checks)
     */
    public function __construct(Auth $auth, CommentService $commentService, CardService $cardService)
    {
        $this->auth = $auth;
        $this->commentService = $commentService;
        $this->cardService = $cardService;
    }

    /**
     * POST /v1/cards/{cardId}/comments
     *
     * Creates a new comment on a card.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function create(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $cardId = (int) ($params['cardId'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $comment = $this->commentService->createComment($cardId, $body, $currentUser);
            $response->json(['comment' => $comment], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/comments/{id}
     *
     * Updates a comment's body. Only the author or admin may update.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->commentService->getCardIdForComment($id);
        if ($cardId === null) {
            $response->error('Comment not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Comment not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $comment = $this->commentService->updateComment($id, $body, $currentUser);
            $response->json(['comment' => $comment]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $code = $msg === 'Permission denied' ? 403 : 404;
            $response->error($msg, $code);
        }
    }

    /**
     * DELETE /v1/comments/{id}
     *
     * Deletes a comment. Only the author or admin may delete.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->commentService->getCardIdForComment($id);
        if ($cardId === null) {
            $response->error('Comment not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Comment not found', 404);
            return;
        }

        try {
            $this->commentService->deleteComment($id, $currentUser);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $code = $msg === 'Permission denied' ? 403 : 404;
            $response->error($msg, $code);
        }
    }
}
