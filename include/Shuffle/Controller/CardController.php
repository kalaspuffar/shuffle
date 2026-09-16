<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\CardService;

/**
 * Card management API controller.
 *
 * Handles card CRUD, move, archive/restore endpoints.
 * Access is enforced via Auth::canAccessBoard on the card's parent board.
 */
class CardController
{
    private Auth $auth;
    private CardService $cardService;

    /**
     * @param Auth        $auth        Auth service
     * @param CardService $cardService Card business logic service
     */
    public function __construct(Auth $auth, CardService $cardService)
    {
        $this->auth = $auth;
        $this->cardService = $cardService;
    }

    /**
     * GET /v1/cards/{id}
     *
     * Returns a single card with rendered Markdown description.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function show(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $card = $this->cardService->getCard($id);
        if ($card === null) {
            $response->error('Card not found', 404);
            return;
        }

        $response->json(['card' => $card]);
    }

    /**
     * POST /v1/boards/{boardId}/lanes/{laneId}/cards
     *
     * Creates a new card in a lane.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function create(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $boardId = (int) ($params['boardId'] ?? 0);
        $laneId = (int) ($params['laneId'] ?? 0);

        if (!$this->auth->canAccessBoard($boardId)) {
            $response->error('Board not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $card = $this->cardService->createCard($boardId, $laneId, $body, $currentUser);
            $response->json(['card' => $card], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/cards/{id}
     *
     * Updates a card's title, description, or due date.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $card = $this->cardService->updateCard($id, $body, $currentUser);
            $response->json(['card' => $card]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * PUT /v1/cards/{id}/move
     *
     * Moves a card to a new lane and/or position.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function move(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $body = $request->getBody();
        $laneId = (int) ($body['lane_id'] ?? 0);
        $afterCardId = isset($body['after_card_id']) ? (int) $body['after_card_id'] : null;

        if ($laneId < 1) {
            $response->error('lane_id is required', 400);
            return;
        }

        try {
            $currentUser = $this->auth->currentUser() ?? [];
            $card = $this->cardService->moveCard($id, $laneId, $afterCardId, $currentUser);
            $response->json(['card' => $card]);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/cards/{id}/merge  (CARD-10..13, §5.17)
     *
     * Merges card `{id}` (the source) into `destination_card_id` from the
     * request body (the survivor). Same-board only; irreversible.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function merge(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $sourceCardId = (int) ($params['id'] ?? 0);
        $destinationCardId = (int) ($request->getBody()['destination_card_id'] ?? 0);

        // Access + visibility on the source card's board first. If the
        // caller cannot see the source's board at all, 404 (don't leak).
        $boardId = $this->cardService->getBoardIdForCard($sourceCardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        try {
            $card = $this->cardService->mergeInto($sourceCardId, $destinationCardId, $currentUser);
            $response->json(['card' => $card]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/cards/{id}/move-to-board  (CARD-26, §5.18)
     *
     * Moves card `{id}` to another board (re-homing — the card keeps its
     * id; its content stays with it). Body:
     *   board_id  (int, required)   destination board
     *   lane_id   (int, optional)   destination lane (default: the
     *                               destination board's first lane)
     *
     * Access: member + canAccessBoard() on BOTH the card's current board
     * and the destination board (BOARD-04b: an inaccessible destination is
     * a 404, never a 400 leak).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function moveToBoard(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('member');
        $cardId = (int) ($params['id'] ?? 0);

        $sourceBoardId = $this->cardService->getBoardIdForCard($cardId);
        if ($sourceBoardId === null || !$this->auth->canAccessBoard($sourceBoardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $body = $request->getBody();
        $boardId  = (int) ($body['board_id'] ?? 0);
        $laneIdRaw = $body['lane_id'] ?? null;
        $laneId = $laneIdRaw === null ? null : (int) $laneIdRaw;

        // BOARD-04b: a destination board the caller cannot access is a 404,
        // never a 400 that would confirm its existence.
        if ($boardId > 0 && !$this->auth->canAccessBoard($boardId)) {
            $response->error('Board not found', 404);
            return;
        }

        try {
            $card = $this->cardService->moveToBoard($cardId, $boardId, $laneId, $currentUser);
            $response->json(['card' => $card]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/cards/{id}/archive
     *
     * Archives a card.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function archive(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        try {
            $currentUser = $this->auth->currentUser() ?? [];
            $this->cardService->archiveCard($id, $currentUser);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/cards/{id}/restore
     *
     * Restores an archived card.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function restore(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        try {
            $currentUser = $this->auth->currentUser() ?? [];
            $this->cardService->restoreCard($id, $currentUser);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/markdown/render
     *
     * Server-side Markdown → HTML preview for the card modal's
     * description preview toggle (CARD-14). Same Markdown pipeline as
     * the card/comment APIs (Parsedown safe mode) — the client never
     * parses Markdown itself (XSS safety, §5.2 / SEC-04).
     *
     * Access: any authenticated user (no board scope: it renders a
     * client-supplied markdown string, not a stored card).
     *
     * Body:  { "markdown": "…" }   (empty string → 200 with empty html)
     * Resp:  { "html": "…" }
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function renderMarkdown(Request $request, Response $response): void
    {
        $this->auth->requireAuth();

        $body = $request->getBody();
        $markdown = $body['markdown'] ?? '';
        if (!is_string($markdown)) {
            $response->error('Invalid request', 422);
            return;
        }

        $response->json(['html' => \Shuffle\Core\Markdown::render($markdown)]);
    }

    /**
     * DELETE /v1/cards/{id}
     *
     * Permanently deletes a card. Requires admin role since this is a
     * destructive action that cannot be undone (unlike archiving).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        try {
            $this->cardService->deleteCard($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
