<?php
declare(strict_types=1);

namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\SearchService;

/**
 * Search API controller.
 *
 * Handles the search endpoint for full-text card search.
 * Requires authentication; results are filtered by board access.
 */
class SearchController
{
    private Auth $auth;
    private SearchService $searchService;

    /**
     * @param Auth          $auth          Auth service
     * @param SearchService $searchService Search business logic service
     */
    public function __construct(Auth $auth, SearchService $searchService)
    {
        $this->auth = $auth;
        $this->searchService = $searchService;
    }

    /**
     * GET /v1/search
     *
     * Searches cards using MySQL full-text search.
     *
     * Query parameters:
     *   q                — Search query (required, minimum 3 characters)
     *   board_id         — Restrict to a specific board (optional)
     *   include_archived — "true" to include archived cards/boards (default: true)
     */
    public function search(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();

        $query = $request->getQuery('q') ?? '';
        $boardId = $request->getQuery('board_id');
        $includeArchived = ($request->getQuery('include_archived') ?? 'true') !== 'false';

        $boardIdInt = $boardId !== null && $boardId !== '' ? (int) $boardId : null;

        try {
            $results = $this->searchService->search(
                $query,
                $currentUser,
                $boardIdInt,
                $includeArchived
            );

            $response->json($results);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }
}
