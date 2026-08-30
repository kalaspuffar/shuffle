<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\CardService;
use Shuffle\Service\ChecklistService;

/**
 * Checklist management API controller.
 *
 * Handles checklist and checklist item CRUD endpoints.
 * Access is enforced via Auth::canAccessBoard on the parent card's board.
 */
class ChecklistController
{
    private Auth $auth;
    private ChecklistService $checklistService;
    private CardService $cardService;

    /**
     * @param Auth             $auth             Auth service
     * @param ChecklistService $checklistService Checklist business logic service
     * @param CardService      $cardService      Card service (for board access checks)
     */
    public function __construct(Auth $auth, ChecklistService $checklistService, CardService $cardService)
    {
        $this->auth = $auth;
        $this->checklistService = $checklistService;
        $this->cardService = $cardService;
    }

    /**
     * POST /v1/cards/{cardId}/checklists
     *
     * Creates a new checklist on a card.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function create(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $cardId = (int) ($params['cardId'] ?? 0);

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $checklist = $this->checklistService->createChecklist($cardId, $body, $this->auth->currentUser() ?? []);
            $response->json(['checklist' => $checklist], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/checklists/{id}
     *
     * Updates a checklist's title.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->checklistService->getCardIdForChecklist($id);
        if ($cardId === null) {
            $response->error('Checklist not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Checklist not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $checklist = $this->checklistService->updateChecklist($id, $body, $this->auth->currentUser() ?? []);
            $response->json(['checklist' => $checklist]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * DELETE /v1/checklists/{id}
     *
     * Deletes a checklist and all its items.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->checklistService->getCardIdForChecklist($id);
        if ($cardId === null) {
            $response->error('Checklist not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Checklist not found', 404);
            return;
        }

        try {
            $this->checklistService->deleteChecklist($id, $this->auth->currentUser() ?? []);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/checklists/{checklistId}/items
     *
     * Creates a new item in a checklist.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function createItem(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $checklistId = (int) ($params['checklistId'] ?? 0);

        $cardId = $this->checklistService->getCardIdForChecklist($checklistId);
        if ($cardId === null) {
            $response->error('Checklist not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Checklist not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $item = $this->checklistService->createItem($checklistId, $body);
            $response->json(['item' => $item], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * PUT /v1/checklist-items/{id}
     *
     * Updates a checklist item's fields (title, is_checked, assigned_user_id).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function updateItem(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->checklistService->getCardIdForItem($id);
        if ($cardId === null) {
            $response->error('Checklist item not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Checklist item not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $item = $this->checklistService->updateItem($id, $body);
            $response->json(['item' => $item]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * PUT /v1/checklist-items/{id}/position
     *
     * Repositions a checklist item within its checklist.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function repositionItem(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->checklistService->getCardIdForItem($id);
        if ($cardId === null) {
            $response->error('Checklist item not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Checklist item not found', 404);
            return;
        }

        $body = $request->getBody();
        $afterItemId = isset($body['after_item_id']) ? (int) $body['after_item_id'] : null;

        try {
            $item = $this->checklistService->repositionItem($id, $afterItemId);
            $response->json(['item' => $item]);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * DELETE /v1/checklist-items/{id}
     *
     * Deletes a checklist item.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function deleteItem(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $cardId = $this->checklistService->getCardIdForItem($id);
        if ($cardId === null) {
            $response->error('Checklist item not found', 404);
            return;
        }

        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Checklist item not found', 404);
            return;
        }

        try {
            $this->checklistService->deleteItem($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
