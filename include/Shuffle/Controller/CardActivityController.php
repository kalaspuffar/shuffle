<?php
declare(strict_types=1);

namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\CardActivityService;
use Shuffle\Service\CardService;

/**
 * Card activity API controller (ACTIVITY-03, SPECIFICATION.md §5.14).
 *
 * One route:
 *   GET /v1/cards/{id}/activity
 *
 * Auth: session (any role) + card's parent board is accessible
 * (BOARD-04b: 404, not 403 — do not reveal the card exists).
 * Query: ?limit (default 50, max 500 · ACTIVITY-03),
 *        ?before=<id> for scrolling older.
 *
 * The response is a public projection — `detail` per event,
 * decoded from `payload_json` — not the raw JSON (SPECIFICATION
 * §5.16.3). The client does not need to know the payload schema.
 */
class CardActivityController
{
    private Auth $auth;
    private CardService $cardService;
    private CardActivityService $activityService;

    /**
     * @param Auth                    $auth            Auth service
     * @param CardService             $cardService     Card service (board lookup)
     * @param CardActivityService     $activityService Activity business logic
     */
    public function __construct(Auth $auth, CardService $cardService, CardActivityService $activityService)
    {
        $this->auth = $auth;
        $this->cardService = $cardService;
        $this->activityService = $activityService;
    }

    /**
     * GET /v1/cards/{id}/activity
     *
     * Returns the card's activity feed (newest first).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function feed(Request $request, Response $response, array $params): void
    {
        $this->auth->requireAuth();

        $cardId = (int) ($params['id'] ?? 0);

        if ($cardId < 1) {
            $response->error('Invalid card id', 400);
            return;
        }

        // Board access check (BOARD-04b) BEFORE touching the log at all.
        $boardId = $this->cardService->getBoardIdForCard($cardId);
        if ($boardId === null || !$this->auth->canAccessBoard($boardId)) {
            $response->error('Card not found', 404);
            return;
        }

        // ?limit clamp: [1, MAX_LIMIT]
        $limit = (int) ($request->getQuery('limit') ?? CardActivityService::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = CardActivityService::DEFAULT_LIMIT;
        }
        if ($limit > CardActivityService::MAX_LIMIT) {
            $limit = CardActivityService::MAX_LIMIT;
        }

        // ?before=<id> for scrolling older
        $beforeId = null;
        $rawBefore = $request->getQuery('before');
        if ($rawBefore !== null && $rawBefore !== '') {
            $candidate = (int) $rawBefore;
            if ($candidate >= 1) {
                $beforeId = $candidate;
            }
        }

        $result = $this->activityService->feed($cardId, $limit, $beforeId);

        $response->json([
            'card'     => $cardId,
            'items'    => $result['items'],
            'has_more' => $result['has_more'],
        ]);
    }
}
