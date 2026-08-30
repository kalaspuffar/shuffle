<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Model\CardActivity;
use Shuffle\Model\Card;
use Shuffle\Model\Lane;
use Shuffle\Model\User;

/**
 * Card activity service (ACTIVITY-01..03).
 *
 * Two responsibilities:
 *
 *   log()  — write a single activity row for a notable card event, called
 *            INSIDE the action that triggered it (one small INSERT per
 *            card lifecycle step). Snapshot the lane/user names into the
 *            payload so the record survives later renames / deletions.
 *
 *   feed() — read the feed for a card (newest first, `?limit` + `?before`
 *            paging), joining actor names and projecting the payload per
 *            event type. The UI (www/card.php History tab) renders this;
 *            prio-digest "Done yesterday" reads via the board-scoped helper
 *            `feedForBoardEvent()`.
 *
 * Failure policy (decision §5.5):
 *   - CardService (move, edit, assign, archive, restore) — log failure
 *     rolls back the surrounding transaction for hard-fail semantics.
 *   - Drag-drop (high-frequency, UX-critical) — the move handler catches
 *     the log exception, logs to the PHP error log, and lets the move
 *     commit anyway. This is the one non-fatal exception.
 *
 * Comment hooks (CommentService) — log failure rolls back the action
 * (comments are less hot than card moves, but still non-fatal-safe:
 * we catch in the controller, not here).
 */
class CardActivityService
{
    /** Max feed rows per page (ACTIVITY-03: capped at 500). */
    public const MAX_LIMIT = 500;

    /** Default feed page size. */
    public const DEFAULT_LIMIT = 50;

    private CardActivity $activity;
    private Card $card;
    private Lane $lane;
    private User $user;

    /**
     * @param Activity $activity Activity DAO
     * @param Card     $card     Card DAO (board lookup)
     * @param Lane     $lane     Lane DAO (title/icon snapshot)
     * @param User     $user     User DAO (name snapshot)
     */
    public function __construct(CardActivity $activity, Card $card, Lane $lane, User $user)
    {
        $this->activity = $activity;
        $this->card = $card;
        $this->lane = $lane;
        $this->user = $user;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Writes a single activity row.
     *
     * @param int              $cardId    Card ID
     * @param string           $event     Event name (see Activity model docblock)
     * @param int              $actorId   User ID of the actor
     * @param array|null       $payload   Event-specific snapshot (lane/user/label names)
     * @return int The new row's ID
     */
    public function log(int $cardId, string $event, int $actorId, ?array $payload = null): int
    {
        $boardId = (int) ($this->card->getBoardId($cardId) ?? 0);
        if ($boardId === 0) {
            throw new \RuntimeException("Card $cardId has no accessible board — cannot log $event");
        }

        return $this->activity->insert($cardId, $boardId, $event, $actorId, $payload);
    }

    /**
     * Snapshots a lane row into the minimal shape a log payload needs.
     * (id + title + icon — enough for the feed to render "Inbox → In Progress".)
     *
     * @param array|null $lane Lane row from the Lane model
     * @return array|null {id, title, icon} or null if lane absent
     */
    public function laneSnapshot(?array $lane): ?array
    {
        if ($lane === null) {
            return null;
        }

        return [
            'id'    => (int) $lane['id'],
            'title' => (string) $lane['title'],
            'icon'  => $lane['icon'] !== null ? (string) $lane['icon'] : null,
        ];
    }

    /**
     * Snapshots a user row into the minimal shape a log payload needs.
     * (id + name.)
     *
     * @param array|null $user User row from the User model
     * @return array|null {id, name} or null
     */
    public function userSnapshot(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id'   => (int) $user['id'],
            'name' => (string) $user['name'],
        ];
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Returns the activity feed for a card (newest first).
     *
     * @param int      $cardId   Card ID
     * @param int      $limit    Max rows (clamped to [1, MAX_LIMIT])
     * @param int|null $beforeId Only rows with id < $beforeId (scrolling older)
     * @return array{items: array, has_more: bool}
     */
    public function feed(int $cardId, int $limit = 50, ?int $beforeId = null): array
    {
        $limit = max(1, min((int) $limit, self::MAX_LIMIT));

        $rows = $this->activity->feedForCard($cardId, $limit + 1, $beforeId);
        $hasMore = count($rows) > $limit;

        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $items[] = $this->projectRow($row);
        }

        return ['items' => $items, 'has_more' => $hasMore];
    }

    /**
     * Returns card activity for a board filtered by event + time window.
     * Used by prio-digest "Done yesterday" (feature/prio-digest).
     *
     * @param int         $boardId
     * @param string      $event
     * @param string|null $since
     * @param string|null $until
     * @return array Array of project activity rows
     */
    public function feedForBoardEvent(int $boardId, string $event, ?string $since, ?string $until): array
    {
        $rows = $this->activity->feedForBoardEvent($boardId, $event, $since, $until);

        $items = array_map(
            fn ($r) => $this->projectRow($r),
            $rows
        );

        return $items;
    }

    /**
     * Projects a raw activity row into the public feed item
     * (SPECIFICATION.md §5.16, API §5.4 of the activity route).
     *
     * The `detail` field is the decoded payload, projected per event type:
     *   - card_moved:  {from_lane, to_lane}
     *   - card_edited: {fields_changed, before?, after?}
     *   - assigned:    {user}
     *   - unassigned:  {user}
     *   - comment_created: {comment_id, author}
     *   - comment_edited:  {comment_id, author}
     *   - comment_deleted: {comment_id, author, body_excerpt}
     *   - card_archived / card_restored: {} (no extra detail)
     *   - card_created: {} (no extra detail)
     *
     * @param array<string, mixed> $row Raw card_activity row + actor_name
     * @return array<string, mixed> Public feed item
     */
    public function projectRow(array $row): array
    {
        $payload = null;
        if ($row['payload_json'] !== null) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $event = (string) $row['event'];
        $detail = $this->projectDetail($event, $payload);

        return [
            'id'         => (int) $row['id'],
            'event'      => $event,
            'actor'      => [
                'id'   => (int) $row['actor_id'],
                'name' => (string) ($row['actor_name'] ?? ''),
            ],
            'created_at' => (string) $row['created_at'],
            // Contract: detail is null (event carries no data) or an object.
            // Never [] — the spec and JS both branch on null/object.
            'detail'     => $detail === [] ? null : $detail,
        ];
    }

    /**
     * Per-event detail projection — keeps the public shape stable as the
     * payload evolves internally (v2 can add fields without breaking the
     * API contract).
     *
     * @param array|null $payload Decoded payload
     * @return array<string, mixed>
     */
    private function projectDetail(string $event, ?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        switch ($event) {
            case 'card_moved':
                return array_intersect_key($payload, ['from_lane' => 1, 'to_lane' => 1]);

            case 'card_edited':
                $out = [];
                if (isset($payload['fields_changed']) && is_array($payload['fields_changed'])) {
                    $out['fields_changed'] = array_values($payload['fields_changed']);
                }
                if (array_key_exists('before', $payload)) {
                    $out['before'] = $payload['before'];
                }
                if (array_key_exists('after', $payload)) {
                    $out['after'] = $payload['after'];
                }
                return $out;

            case 'assigned':
            case 'unassigned':
                if (isset($payload['user']) && is_array($payload['user'])) {
                    return ['user' => $payload['user']];
                }
                return [];

            case 'comment_created':
            case 'comment_edited':
            case 'comment_deleted':
                $out = [];
                if (isset($payload['comment_id'])) {
                    $out['comment_id'] = (int) $payload['comment_id'];
                }
                if (isset($payload['author']) && is_array($payload['author'])) {
                    $out['author'] = $payload['author'];
                }
                if (isset($payload['body_excerpt'])) {
                    $out['body_excerpt'] = (string) $payload['body_excerpt'];
                }
                return $out;

            // No detail shape yet — keep the raw payload (v1: card_archived,
            // card_restored, card_created are empty; labels/merged are
            // reserved for later features)
            default:
                return $payload;
        }
    }

}
