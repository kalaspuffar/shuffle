<?php
declare(strict_types=1);

namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\LabelService;

/**
 * Label management API controller (LABEL-01..03, §5.15).
 *
 * Board-level label CRUD (create/rename/delete) requires Admin or Member
 * (requireRole('member') — Viewer is below member in the hierarchy and
 * receives 403). Attach/detach on a card is available to Viewer too
 * (requireRole('viewer') is the floor = any authenticated user), gated by
 * per-board access (BOARD-04b).
 *
 * Access to a board is enforced per-endpoint via Auth::canAccessBoard;
 * boards the user cannot see return 404 (not 403) per strict isolation.
 */
class LabelController
{
    private Auth $auth;
    private LabelService $labelService;

    public function __construct(Auth $auth, LabelService $labelService)
    {
        $this->auth         = $auth;
        $this->labelService = $labelService;
    }

    /**
     * GET /v1/boards/{boardId}/labels
     *
     * Returns the board's labels with card_count. Read-only for any role
     * that can access the board (Viewer included).
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $boardId = (int) ($params['boardId'] ?? 0);
        if ($this->auth->currentUser() === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Board not found', 404);
            return;
        }
        $labels = $this->labelService->listForBoard($boardId);
        $response->json(['labels' => $labels]);
    }

    /**
     * POST /v1/boards/{boardId}/labels
     *
     * Creates a label (Admin or Member). 400 on invalid name/color,
     * 409 on duplicate name, 201 on success.
     */
    public function create(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');   // Viewer → 403
        $boardId = (int) ($params['boardId'] ?? 0);
        if (!$this->auth->canAccessBoard($boardId)) {
            $response->error('Board not found', 404);
            return;
        }

        $body = $request->getBody();
        try {
            $label = $this->labelService->create($boardId, $body);
            $response->json(['label' => $label], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 409);   // duplicate name
        }
    }

    /**
     * PUT /v1/labels/{id}
     *
     * Renames / re-colors a label (Admin or Member). 400 on invalid,
     * 409 on duplicate name, 404 on not found.
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');   // Viewer → 403
        $id = (int) ($params['id'] ?? 0);

        // Board access check: resolve the label's board via the service.
        $existing = $this->labelService->peekBoardId($id);
        if ($existing === null || !$this->auth->canAccessBoard($existing)) {
            $response->error('Label not found', 404);
            return;
        }

        $body = $request->getBody();
        try {
            $label = $this->labelService->update($id, $body);
            $response->json(['label' => $label]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // Distinguish not-found (404) from duplicate-name (409).
            if ($message === 'Label not found') {
                $response->error($message, 404);
            } else {
                $response->error($message, 409);
            }
        }
    }

    /**
     * DELETE /v1/labels/{id}
     *
     * Deletes a label (Admin or Member). 204 on success, 404 on missing.
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');   // Viewer → 403
        $id = (int) ($params['id'] ?? 0);

        $existing = $this->labelService->peekBoardId($id);
        if ($existing === null || !$this->auth->canAccessBoard($existing)) {
            $response->error('Label not found', 404);
            return;
        }

        try {
            $this->labelService->delete($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/cards/{cardId}/labels/{labelId}
     *
     * Attaches a label to a card (any role with board access, incl. Viewer).
     * 204 on success; 404 card/label not found; 400 cross-board.
     */
    public function attach(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('viewer');   // floor: any authenticated user
        $cardId  = (int) ($params['cardId'] ?? 0);
        $labelId = (int) ($params['labelId'] ?? 0);

        $cardBoard = $this->labelService->peekCardBoardId($cardId);
        if ($cardBoard === null || !$this->auth->canAccessBoard($cardBoard)) {
            $response->error('Card not found', 404);
            return;
        }

        try {
            $this->labelService->attach($cardId, $labelId);
            $response->noContent();
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * DELETE /v1/cards/{cardId}/labels/{labelId}
     *
     * Detaches a label (any role with board access, incl. Viewer). 204 no-op
     * safe; 404 not found.
     */
    public function detach(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('viewer');   // floor: any authenticated user
        $cardId  = (int) ($params['cardId'] ?? 0);
        $labelId = (int) ($params['labelId'] ?? 0);

        $cardBoard = $this->labelService->peekCardBoardId($cardId);
        if ($cardBoard === null || !$this->auth->canAccessBoard($cardBoard)) {
            $response->error('Card not found', 404);
            return;
        }

        try {
            $this->labelService->detach($cardId, $labelId);
            $response->noContent();
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
