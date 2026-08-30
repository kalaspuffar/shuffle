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
