<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\LaneService;

/**
 * Lane management API controller.
 *
 * Handles lane CRUD and reordering endpoints.
 * All operations require board-level access via Auth::canAccessBoard.
 */
class LaneController
{
    private Auth $auth;
    private LaneService $laneService;

    /**
     * @param Auth        $auth        Auth service
     * @param LaneService $laneService Lane business logic service
     */
    public function __construct(Auth $auth, LaneService $laneService)
    {
        $this->auth = $auth;
        $this->laneService = $laneService;
    }

    /**
     * GET /v1/boards/{boardId}/lanes
     *
     * Lists lanes for a board, ordered by position.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();
        $boardId = (int) ($params['boardId'] ?? 0);

        if (!$this->auth->canAccessBoard($boardId)) {
            $response->error('Board not found', 404);
            return;
        }

        $lanes = $this->laneService->listLanes($boardId);
        $response->json(['lanes' => $lanes]);
    }

    /**
     * POST /v1/boards/{boardId}/lanes
     *
     * Creates a new lane in the board.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function create(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $boardId = (int) ($params['boardId'] ?? 0);

        if (!$this->auth->canAccessBoard($boardId)) {
            $response->error('Board not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $lane = $this->laneService->createLane($boardId, $body);
            $response->json(['lane' => $lane], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/lanes/{id}
     *
     * Renames a lane.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->laneService->getBoardIdForLane($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Lane not found', 404);
            return;
        }

        $body = $request->getBody();

        try {
            $lane = $this->laneService->updateLane($id, $body);
            $response->json(['lane' => $lane]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * PUT /v1/lanes/{id}/position
     *
     * Reorders a lane within its board.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function reposition(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->laneService->getBoardIdForLane($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Lane not found', 404);
            return;
        }

        $body = $request->getBody();
        $afterLaneId = isset($body['after_lane_id']) ? (int) $body['after_lane_id'] : null;

        try {
            $lane = $this->laneService->repositionLane($id, $afterLaneId);
            $response->json(['lane' => $lane]);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * DELETE /v1/lanes/{id}
     *
     * Deletes a lane. Returns 409 if the lane has cards.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('member');
        $id = (int) ($params['id'] ?? 0);

        $boardId = $this->laneService->getBoardIdForLane($id);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Lane not found', 404);
            return;
        }

        try {
            $this->laneService->deleteLane($id);
            $response->noContent();
        } catch (\LogicException $e) {
            $response->error($e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }
}
