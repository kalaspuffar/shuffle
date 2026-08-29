<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Auth;
use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Lane;
use Shuffle\Model\UserPrio;

/**
 * Personal priority list service (PRIO-01..11).
 *
 * The priority list is a per-user VIEW, never a source of truth (PRIO-02/08):
 * `user_prio` stores only (user, card) membership plus the user's custom
 * order. Every other field (title, lane, board, due date) is read live from
 * the board on each call. The inbox section is computed on the fly and is
 * never stored.
 *
 * Error conventions (matched to existing controllers):
 *   \RuntimeException — "not found / not accessible" (controller maps to 404)
 *   \LogicException   — business rule conflict, e.g. Done-lane card (→ 409)
 */
class PriorityService
{
    private const POSITION_GAP = 1000;

    /** Neutral state marker when a lane carries no icon of its own (PRIO-07). */
    private const NEUTRAL_STATE_MARKER = '•';

    private UserPrio $userPrio;
    private Card $card;
    private Lane $lane;
    private Board $board;
    private Auth $auth;

    public function __construct(
        UserPrio $userPrio,
        Card $card,
        Lane $lane,
        Board $board,
        Auth $auth
    ) {
        $this->userPrio = $userPrio;
        $this->card = $card;
        $this->lane = $lane;
        $this->board = $board;
        $this->auth = $auth;
    }

    /**
     * Returns the acting user's priority list: `inbox` + `prioritized` (PRIO-01/03/06/09).
     *
     * @return array{inbox: array, prioritized: array}
     */
    public function getList(array $user): array
    {
        $priorityCardIds = array_map(
            static fn (array $row): int => (int) $row['card_id'],
            $this->userPrio->findByUser((int) $user['id'])
        );

        $inbox = $this->computeInbox($user, $priorityCardIds);
        $prioritized = $this->loadPrioritized($user);

        return ['inbox' => $inbox, 'prioritized' => $prioritized];
    }

    /**
     * Adds a card to the user's prioritized section (PRIO-05).
     *
     * Idempotent: a card already prioritized is a no-op success (position returned as-is).
     *
     * @throws \RuntimeException If the card is unknown or on an inaccessible board (404)
     * @throws \LogicException   If the card is on a Done lane (409)
     * @return array{position: int}
     */
    public function prioritize(array $user, int $cardId): array
    {
        $this->requireAssignedCard($user, $cardId);

        $existing = $this->userPrio->findByCardAndUser((int) $user['id'], $cardId);

        if ($existing !== null) {
            return ['position' => (int) $existing['position']];
        }

        $position = $this->userPrio->maxPosition((int) $user['id']) + self::POSITION_GAP;

        $this->userPrio->add((int) $user['id'], $cardId, $position);

        return ['position' => $position];
    }

    /**
     * Removes a card from the user's prioritized section (PRIO-05).
     *
     * No-op (still 204) when the card is not in the user's list.
     */
    public function deprioritize(array $user, int $cardId): void
    {
        $this->userPrio->remove((int) $user['id'], $cardId);
    }

    /**
     * Reorders a prioritized card relative to another (PRIO-06).
     * `afterCardId == null` moves the card to the top.
     *
     * @throws \RuntimeException If the card is not in this user's prioritized section (404)
     * @return array{position: int}
     */
    public function reorder(array $user, int $cardId, ?int $afterCardId): array
    {
        $userId = (int) $user['id'];

        $moving = $this->userPrio->findByCardAndUser($userId, $cardId);
        if ($moving === null) {
            throw new \RuntimeException('Card is not in your priority list');
        }

        $position = $this->userPrio->reposition($userId, $cardId, $afterCardId);

        return ['position' => $position];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Validates that $cardId exists, is on a board the user can access,
     * is assigned to the user, and is not on a Done lane (PRIO-03/05).
     *
     * @throws \RuntimeException Card not found or board inaccessible (404)
     * @throws \LogicException   Card is on a Done lane (409)
     */
    private function requireAssignedCard(array $user, int $cardId): void
    {
        $card = $this->card->findById($cardId);

        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }

        $boardId = (int) $this->card->getBoardId($cardId);

        if (!$this->auth->canAccessBoard($boardId)) {
            // Don't reveal the card exists but is not accessible (BOARD-04b).
            throw new \RuntimeException('Card not found');
        }

        // PRIO-05: you can only prioritize cards assigned to you.
        $assignedIds = array_map(
            static fn (array $u): int => (int) $u['id'],
            $card['assigned_users'] ?? []
        );

        if (!in_array((int) $user['id'], $assignedIds, true)) {
            throw new \RuntimeException('Card is not assigned to you');
        }

        $lane = $this->lane->findById((int) $card['lane_id']);
        if ($lane !== null && $this->isDoneLane($lane['title'])) {
            throw new \LogicException('Card is on a Done lane — remove it from the board first');
        }
    }

    /**
     * Builds the computed inbox list (PRIO-03/04): assigned, accessible,
     * non-Done-lane, non-prioritized cards, tiered and stably merged in
     * board-creation order within a tier.
     *
     * @return array<int, array<string, mixed>>
     */
    private function computeInbox(array $user, array $excludedCardIds): array
    {
        // Query order is already board → lane position → card position
        // (PRIO-04 in-board ordering), so we only partition into tiers.
        $rows = $this->card->findInboxCandidates((int) $user['id'], $excludedCardIds);

        // Cache per-board access checks (BOARD-04b: never leak).
        $accessCache = [];
        $canAccess = function (int $boardId) use (&$accessCache): bool {
            if (!array_key_exists($boardId, $accessCache)) {
                $accessCache[$boardId] = $this->auth->canAccessBoard($boardId);
            }

            return $accessCache[$boardId];
        };

        $tiers = [[], [], []]; // index = tier - 1
        foreach ($rows as $row) {
            if (!$canAccess((int) $row['board_id'])) {
                continue; // Inaccessible board — drop silently (BOARD-04b).
            }

            $laneTitle = (string) ($row['lane_title'] ?? '');

            if ($this->isDoneLane($laneTitle)) {
                continue; // PRIO-09: Done-lane cards stay out of the inbox.
            }

            $tier = $this->isInProgressLane($laneTitle) ? 1
                : ($this->isInboxLane($laneTitle) ? 2 : 3);

            $tiers[$tier - 1][] = $this->toItem($row, $tier);
        }

        return array_merge($tiers[0], $tiers[1], $tiers[2]);
    }

    /**
     * Loads the user's prioritized section, joined live to card/lane/board
     * (PRIO-07). Order follows `user_prio.position`.
     *
     * Rows where the card was deleted or the board is inaccessible are
     * omitted (PRIO-08); rows where the card moved to Done remain visible,
     * marked via the live lane (PRIO-09).
     *
     * @param array $user User row
     * @return array<int, array<string, mixed>>
     */
    private function loadPrioritized(array $user): array
    {
        $entries = $this->userPrio->findByUser((int) $user['id']);
        if ($entries === []) {
            return [];
        }

        $cardIds = array_map(
            static fn (array $row): int => (int) $row['card_id'],
            $entries
        );

        $rowsByCard = $this->card->findWithBoardForUserList($cardIds);

        $accessCache = [];
        $canAccess = function (int $boardId) use (&$accessCache): bool {
            if (!array_key_exists($boardId, $accessCache)) {
                $accessCache[$boardId] = $this->auth->canAccessBoard($boardId);
            }

            return $accessCache[$boardId];
        };

        $items = [];
        foreach ($cardIds as $cardId) {
            if (!isset($rowsByCard[$cardId])) {
                continue; // Card deleted, board archived (BOARD-06d), or lane/board gone (PRIO-08).
            }

            $row = $rowsByCard[$cardId];
            if ((int) ($row['is_archived'] ?? 0) === 1) {
                continue; // Archived cards leave the list (PRIO-08).
            }

            if (!$canAccess((int) $row['board_id'])) {
                continue; // Board no longer accessible (PRIO-08) — omit, never an error.
            }

            $items[] = $this->toItem($row, null);
        }

        return $items;
    }

    /**
     * Shapes a joined card row into the public item (SPECIFICATION.md §5.13).
     *
     * @param array<string, mixed> $row
     * @param int|null $tier PRIO-04 tier (inbox items only; null otherwise)
     */
    private function toItem(array $row, ?int $tier): array
    {
        $laneIcon = $row['lane_icon'] !== null ? (string) $row['lane_icon'] : null;
        $stateMarker = $this->deriveStateMarker((string) ($row['lane_title'] ?? ''), $laneIcon);

        $item = [
            'card_id' => (int) $row['card_id'],
            'card_title' => (string) ($row['title'] ?? ''),
            'board_id' => (int) $row['board_id'],
            'board_title' => (string) ($row['board_title'] ?? ''),
            'lane_id' => (int) $row['lane_id'],
            'lane_title' => (string) ($row['lane_title'] ?? ''),
            'lane_icon' => $laneIcon,
            'state_marker' => $stateMarker,
            'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
            'card_html' => '/card.php?id=' . (int) $row['card_id'],
        ];

        if ($tier !== null) {
            $item['tier'] = $tier;
        }

        return $item;
    }

    /**
     * State marker per PRIO-07: In Progress → 🔨, Inbox → 📥, Done → ✅,
     * otherwise the lane's own icon, or a neutral marker when it has none.
     * Lane names are matched case-insensitively against the default set (PRIO-04).
     */
    private function deriveStateMarker(string $laneTitle, ?string $laneIcon): string
    {
        $lower = mb_strtolower(trim($laneTitle));

        if ($this->isDoneLane($laneTitle)) {
            return '✅';
        }
        if ($this->isInProgressLane($laneTitle)) {
            return '🔨';
        }
        if ($this->isInboxLane($laneTitle)) {
            return '📥';
        }

        return $laneIcon ?? self::NEUTRAL_STATE_MARKER;
    }

    /**
     * Lane-title matchers (PRIO-04): case-insensitive, anchored, word-bounded.
     * A lane named "In Progress (Web)" still matches; "Done-ness" does not.
     */
    private function isDoneLane(string $title): bool
    {
        return preg_match('/\bdone\b/iu', trim($title)) === 1;
    }

    private function isInProgressLane(string $title): bool
    {
        return preg_match('/\bin progress\b/iu', trim($title)) === 1;
    }

    private function isInboxLane(string $title): bool
    {
        return preg_match('/\binbox\b/iu', trim($title)) === 1;
    }
}
