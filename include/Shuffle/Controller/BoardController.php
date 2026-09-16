<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\BoardService;

/**
 * Board management API controller.
 *
 * Handles CRUD, archive/restore, and version polling endpoints.
 * Access is enforced via Auth service — admins see everything,
 * members/viewers see only boards they have access to.
 */
class BoardController
{
    private Auth $auth;
    private BoardService $boardService;

    /**
     * @param Auth         $auth         Auth service
     * @param BoardService $boardService Board business logic service
     */
    public function __construct(Auth $auth, BoardService $boardService)
    {
        $this->auth = $auth;
        $this->boardService = $boardService;
    }

    /**
     * GET /v1/boards
     *
     * Lists boards accessible by the current user.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function index(Request $request, Response $response): void
    {
        $currentUser = $this->auth->requireAuth();

        $includeArchived = in_array($request->getQuery('include_archived'), ['1', 'true'], true);

        $boards = $this->boardService->listBoards($currentUser, $includeArchived);

        $response->json(['boards' => $boards]);
    }

    /**
     * GET /v1/boards/{id}
     *
     * Returns a single board. Access checked via Auth::canAccessBoard.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function show(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        if (!$this->auth->canAccessBoard($id)) {
            $response->error('Board not found', 404);
            return;
        }

        // Include nested lanes and cards for the full board view
        $includeLanes = in_array($request->getQuery('include_lanes'), ['1', 'true'], true);

        if ($includeLanes) {
            $board = $this->boardService->getBoardWithLanesAndCards($id);
        } else {
            $board = $this->boardService->getBoard($id);
        }

        if ($board === null) {
            $response->error('Board not found', 404);
            return;
        }

        $response->json(['board' => $board]);
    }

    /**
     * POST /v1/boards
     *
     * Creates a new board. Requires member or admin role.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function create(Request $request, Response $response): void
    {
        $currentUser = $this->auth->requireRole('member');

        $body = $request->getBody();

        try {
            $board = $this->boardService->createBoard($body, $currentUser);
            $response->json(['board' => $board], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/boards/{id}
     *
     * Updates a board. Requires member or admin role with access.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        if (!$this->auth->canAccessBoard($id)) {
            $response->error('Board not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $board = $this->boardService->updateBoard($id, $body);
            $response->json(['board' => $board]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * DELETE /v1/boards/{id}
     *
     * Deletes a board. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->boardService->deleteBoard($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/boards/{id}/archive
     *
     * Archives a board. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function archive(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->boardService->archiveBoard($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/boards/{id}/restore
     *
     * Restores an archived board. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function restore(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->boardService->restoreBoard($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * GET /v1/boards/{id}/region
     *
     * Board Real-Time Sync (RT-04/05, SPECIFICATION section 5.19).
     *
     * Returns the server-rendered HTML fragment for the board region
     * (lanes + cards + add-lane ghost) — produced by the SAME shared
     * renderer (include/templates/board-region.php) that www/board.php
     * uses, so the fragment is drop-in-identical to the board page.
     *
     * Headers: Content-Type: text/html, Cache-Control: no-cache,
     * ETag = board version (If-None-Match match => 304, empty body).
     * Access: same rules as the board (404 for boards the caller cannot
     * access — BOARD-04b, never a 403 leak). ?include_archived=1 mirrors
     * the board page filter.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function region(Request $request, Response $response, array $params): void
    {
        // Shared renderer scope: board.php top-level scope has these globals
        // in scope. Inside this method they don't — bind explicitly.
        global $lang;
        $currentUser = $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        if (!$this->auth->canAccessBoard($id)) {
            $response->error('Board not found', 404);
            return;
        }

        $includeArchived = in_array($request->getQuery('include_archived'), ['1', 'true'], true);

        $board = $this->boardService->getBoardWithLanesAndCards($id, $includeArchived);
        if ($board === null) {
            $response->error('Board not found', 404);
            return;
        }

        // ETag = board version (the RT-02 cheap-change signal already used
        // by /version) — a synced client sends It, gets 304 when unchanged.
        $etag = '"' . ((int) $board['version']) . '"';
        $ifNoneMatch = trim((string) $request->getHeader('If-None-Match', ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: no-cache');
            return;
        }

        // Shared renderer scope (www/board.php contract): $board, $canEdit, $lang,
        // $boardId. ($lang is a bootstrapped global from include/bootstrap.php.)
        $canEdit = in_array($currentUser['role'], ['admin', 'member'], true);
        $boardId = $id;

        ob_start();
        require ROOT_DIR . '/include/templates/board-region.php';
        $fragment = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        header('ETag: ' . $etag);
        echo $fragment;
    }

    /**
     * GET /v1/boards/{id}/version
     *
     * Returns the current board version for polling. Supports ETag/If-None-Match.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function version(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        if (!$this->auth->canAccessBoard($id)) {
            $response->error('Board not found', 404);
            return;
        }

        $version = $this->boardService->getBoardVersion($id);

        if ($version === null) {
            $response->error('Board not found', 404);
            return;
        }

        $etag = '"' . $version . '"';

        // Check If-None-Match for conditional response
        $ifNoneMatch = $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
            header('ETag: ' . $etag);
            $response->notModified();
            return;
        }

        header('ETag: ' . $etag);
        $response->json(['version' => $version]);
    }
}
