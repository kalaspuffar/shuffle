<?php
declare(strict_types=1);

namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\PriorityService;

/**
 * Personal priority list API controller (PRIO-01..11, SPECIFICATION §5.13).
 *
 * Every route acts on the authenticated user's own list only — no user_id
 * parameter is ever accepted. Error mapping (consistent with the rest of the
 * API):
 *
 *   \RuntimeException → 404 Card not found / not in your list (BOARD-04b:
 *                           existence of cards on inaccessible boards is not
 *                           revealed).
 *   \LogicException   → 409 Business-rule conflict (e.g. card on a Done lane).
 *   \InvalidArgumentException → 400 Bad request payload.
 */
class PriorityController
{
    private Auth $auth;
    private PriorityService $priorityService;

    public function __construct(Auth $auth, PriorityService $priorityService)
    {
        $this->auth = $auth;
        $this->priorityService = $priorityService;
    }

    /**
     * GET /v1/priority
     *
     * Returns the acting user's {inbox, prioritized} (PRIO-01/03/06/09).
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $user = $this->auth->requireAuth();

        // Viewers can view their own list (PRIO-01: every authenticated role).
        $list = $this->priorityService->getList($user);

        $response->json($list);
    }

    /**
     * POST /v1/priority/inbox/{cardId}
     *
     * Adds the card to the user's prioritized section (PRIO-05).
     * Idempotent: an already-prioritized card is a 200 no-op.
     */
    public function prioritize(Request $request, Response $response, array $params): void
    {
        $user = $this->auth->requireAuth();
        $cardId = (int) ($params['cardId'] ?? 0);

        if ($cardId <= 0) {
            $response->error('Invalid card id', 404);
            return;
        }

        try {
            $result = $this->priorityService->prioritize($user, $cardId);
            $response->json(['card' => $cardId, 'position' => $result['position']]);
        } catch (\LogicException $e) {
            $response->error($e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * DELETE /v1/priority/inbox/{cardId}
     *
     * Removes the card from the user's prioritized section (PRIO-05).
     * No-op 204 when the card is not in the list.
     */
    public function deprioritize(Request $request, Response $response, array $params): void
    {
        $user = $this->auth->requireAuth();
        $cardId = (int) ($params['cardId'] ?? 0);

        if ($cardId <= 0) {
            $response->noContent();
            return;
        }

        $this->priorityService->deprioritize($user, $cardId);
        $response->noContent();
    }

    /**
     * PUT /v1/priority/position
     *
     * Reorders a prioritized card relative to another (PRIO-06).
     * Body: { "card_id": int, "after_card_id": int|null }
     */
    public function reorder(Request $request, Response $response, array $params): void
    {
        $user = $this->auth->requireAuth();
        $body = $request->getBody();

        $cardId = (int) ($body['card_id'] ?? 0);

        if ($cardId <= 0) {
            $response->error('card_id is required', 400);
            return;
        }

        $afterCardId = null;
        if (array_key_exists('after_card_id', $body) && $body['after_card_id'] !== null) {
            $afterCardId = (int) $body['after_card_id'];
            if ($afterCardId <= 0) {
                $response->error('after_card_id must be a positive integer or null', 400);
                return;
            }
        }

        if ($afterCardId === $cardId) {
            $response->error('A card cannot be positioned after itself', 400);
            return;
        }

        try {
            $result = $this->priorityService->reorder($user, $cardId, $afterCardId);
            $response->json(['card' => $cardId, 'position' => $result['position']]);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * GET /v1/priority/digest
     *
     * The acting user's priority digest (PRIO-12..14, SPECIFICATION §5.16).
     *
     * Query: ?n (default 5, clamped to 1–50 — never a 400)
     *        &format=json (default) | markdown
     *
     * Read-only: no CSRF (consistent with GET /v1/priority).
     *
     * `format=json`     → 200 {n, top, done_since, window}
     * `format=markdown` → 200 text/markdown paste-ready body
     *
     * Unknown `format` values 400 (it is an enum, not a filter — a typo
     * should surface). Out-of-range `n` clamps (PRIO-13).
     */
    public function digest(Request $request, Response $response, array $params): void
    {
        $user = $this->auth->requireAuth();

        $rawN = (int) ($request->getQuery('n') ?? PriorityService::DIGEST_DEFAULT_N);
        $n = PriorityService::clampDigestN($rawN);

        $rawFormat = $request->getQuery('format');
        $format = $rawFormat === null || $rawFormat === '' ? 'json' : (string) $rawFormat;

        if ($format !== 'json' && $format !== 'markdown') {
            $response->error('format must be "json" or "markdown"', 400);
            return;
        }

        try {
            if ($format === 'markdown') {
                $body = $this->priorityService->digestMarkdown($user, $n);
                $response->text($body, 'text/markdown');
            } else {
                $response->json($this->priorityService->digest($user, $n));
            }
        } catch (\RuntimeException $e) {
            // Activity log not wired (shouldn't happen on a wired install).
            $response->error('Digest is not available', 503);
        }
    }
}
