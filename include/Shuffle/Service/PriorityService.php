<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Auth;
use Shuffle\Core\Lang;
use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Lane;
use Shuffle\Model\UserPrio;
use Shuffle\Service\CardActivityService;

/**
 * Personal priority list service (PRIO-01..14).
 *
 * The priority list is a per-user VIEW, never a source of truth (PRIO-02/08):
 * `user_prio` stores only (user, card) membership plus the user's custom
 * order. Every other field (title, lane, board, due date) is read live from
 * the board on each call. The inbox section is computed on the fly and is
 * never stored.
 *
 * The digest (PRIO-12..14) is built on the same live-read principle plus
 * the card_activity log (ACTIVITY-01) for "Done yesterday" — the log is
 * the single source of truth (the original `card_moves` table plan was
 * superseded by it).
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

    /** Digest (PRIO-12..14) — injected when the activity log is wired. */
    private ?CardActivityService $activityService = null;
    private ?Lang $lang = null;

    /** Default top-N for the digest (PRIO-12/13/14). */
    public const DIGEST_DEFAULT_N = 5;

    /** Digest top-N bounds (PRIO-13: 1–50, clamped not rejected). */
    public const DIGEST_MIN_N = 1;
    public const DIGEST_MAX_N = 50;

    public function __construct(
        UserPrio $userPrio,
        Card $card,
        Lane $lane,
        Board $board,
        Auth $auth,
        ?CardActivityService $activityService = null,
        ?Lang $lang = null
    ) {
        $this->userPrio = $userPrio;
        $this->card = $card;
        $this->lane = $lane;
        $this->board = $board;
        $this->auth = $auth;
        $this->activityService = $activityService;
        $this->lang = $lang;
    }

    /**
     * Attaches the card activity service for digest "Done yesterday"
     * (PRIO-14). Optional — the list-only methods never need it.
     */
    public function setActivityService(CardActivityService $activityService): void
    {
        $this->activityService = $activityService;
    }

    /**
     * Attaches the Lang instance so digest markdown headings follow i18n
     * (no hardcoded strings — PRIO-12, repo doctrine).
     */
    public function setLang(Lang $lang): void
    {
        $this->lang = $lang;
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
        if ($lane !== null && $this->isCompleteLane($lane['title'])) {
            throw new \LogicException('Card is on a complete lane (Done or Won\'t fix) — remove it from the board first');
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

            if ($this->isCompleteLane($laneTitle)) {
                continue; // PRIO-09 (v1.9): Done-lane AND Won't-fix-lane
                          // cards stay out of the inbox.
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
    // ------------------------------------------------------------------
    // Priority digest (PRIO-12..14)
    // ------------------------------------------------------------------

    /**
     * Builds the acting user's digest (PRIO-12/14).
     *
     * Returns the user's top-N prioritized cards (live-read, their own
     * order) plus the "Done since" list — cards that moved to a COMPLETE
     * lane (Done or Won't-fix) within the report window, across all boards
     * the user can access. Inaccessible cards are OMITTED, never revealed
     * (BOARD-04b).
     *
     * Report window (v1.9 — Daniel does not report on weekends): from
     * 00:00:00 of the most recent workday (Mon–Fri) at or before YESTERDAY,
     * to 23:59:59 of yesterday:
     *   Mon → Fri 00:00 → Sun 23:59   (Fri work + all of the weekend)
     *   Tue–Fri → yesterday 00:00 → 23:59 (the last full reporting day)
     *   Sat → Fri 00:00 → Fri 23:59
     *   Sun → Fri 00:00 → Sat 23:59   (Sunday's own work lands in Monday's)
     *
     * Always live (PRIO-13): no caching, no stored digest.
     *
     * @param array $user Acting user row
     * @param int   $n    Top-N (1–50; clamped here, not rejected)
     * @return array{
     *   n: int,
     *   top: array<int, array>,
     *   done_since: array<int, array>,
     *   window: array{since: string, until: string}
     * }
     *
     * @throws \RuntimeException When the activity log is not wired (the digest
     *                           needs it — controller 503s, the list still works)
     */
    public function digest(array $user, int $n = self::DIGEST_DEFAULT_N): array
    {
        if ($this->activityService === null) {
            throw new \RuntimeException('Card activity log is not available for the digest');
        }

        $n = $this->clampDigestN($n);

        // v1.9 report window (server local TZ). Yesterday is the anchor
        // day; if it (or the days walked back from it) fell on the weekend,
        // the window's start rolls back to the last workday (Mon–Fri).
        // strtotime()'s relative-day math is DST-safe (fixed 86400* n
        // offsets are not, so they are deliberately avoided here).
        $yestTs = strtotime('yesterday');
        $yestDow = (int) date('N', $yestTs); // 1=Mon … 7=Sun
        $anchorTs = match ($yestDow) {
            6      => strtotime('-1 day', $yestTs),  // Sat → Friday
            7      => strtotime('-2 days', $yestTs), // Sun → Friday
            default => $yestTs,                       // Mon–Fri → yesterday
        };
        $since = date('Y-m-d 00:00:00', $anchorTs);
        $until = date('Y-m-d 23:59:59', $yestTs);

        return [
            'n'          => $n,
            'top'        => $this->digestTop($user, $n),
            'done_since' => $this->digestDoneSince($user, $since, $until),
            'window'     => ['since' => $since, 'until' => $until],
        ];
    }

    /**
     * Render the digest as paste-ready Markdown (PRIO-12 markdown contract).
     *
     * Headings come from i18n: top via `priority.digest.top_heading`,
     * the done section via `priority.digest.done_since_heading` (v1.9:
     * "Done since {workday}", {0} = the human workday since the window
     * was anchored). No hardcoded strings ship (repo doctrine); a missing
     * key renders the key name — loud, not silent.
     */
    public function digestMarkdown(array $user, int $n = self::DIGEST_DEFAULT_N): string
    {
        $digest = $this->digest($user, $n);

        // Headings (spec §5.16: bold lines, not ATX).
        $topHeading  = '**' . $this->langLabel('priority.digest.top_heading', [(string) count($digest['top'])]) . '**';
        // v1.9 heading: "Done since {workday} — N items", the workday being
        // the window's start (Fri on a Monday digest, yesterday on the rest).
        $sinceHuman  = date('l, M j', strtotime($digest['window']['since']));
        $doneHeading = '**' . $this->langLabel(
            'priority.digest.done_since_heading',
            [(string) $sinceHuman, (string) count($digest['done_since'])]
        ) . '**';

        // Top items: `marker title — *board*`. The deep link stays in the JSON
        // payload (card_html) but is NOT rendered into the chat markdown —
        // a non-clickable path is noise in a chat client (Daniel, 2026-08-30).
        $topLines = array_map(
            static fn (array $item): string => sprintf(
                '%s %s — *%s*',
                $item['state_marker'],
                $item['card_title'],
                $item['board_title']
            ),
            $digest['top']
        );
        // v1.9: Done-lane items render ✅, Won't-fix items render ❌ (lane_kind).
        $doneLines = array_map(
            static fn (array $item): string => sprintf(
                '%s %s — *%s* — %s',
                ($item['lane_kind'] ?? 'done') === 'wont_fix' ? '❌' : '✅',
                $item['card_title'],
                $item['board_title'],
                $item['actor']['name']
            ),
            $digest['done_since']
        );

        // Sections with zero items are OMITTED entirely: an empty "Done
        // since — (none)" heading is just noise in a chat (Daniel,
        // 2026-08-30). If BOTH are empty the digest is the empty string and
        // the page-level surface handles that (no clipboard, quiet hint).
        $sections = [];
        if ($digest['top'] !== []) {
            $sections[] = array_merge(
                [$topHeading],
                array_values($topLines)
            );
        }
        if ($digest['done_since'] !== []) {
            $sections[] = array_merge(
                [$doneHeading],
                array_values($doneLines)
            );
        }
        if ($sections === []) {
            return '';
        }
        $body = [];
        foreach ($sections as $s) {
            foreach ($s as $line) {
                $body[] = $line;
            }
            $body[] = '';
        }
        // Trim the trailing blank separator and end with a single newline.
        while (end($body) === '') { array_pop($body); }
        if ($body === []) { return ''; }
        return implode("\n", $body) . "\n";
    }

    /**
     * Clamps a digest N into 1–50 (PRIO-13: clamped, not rejected).
     */
    public static function clampDigestN(int $n): int
    {
        return max(self::DIGEST_MIN_N, min(self::DIGEST_MAX_N, $n));
    }

    /**
     * Top-N prioritized cards (their own order), live state, access-filtered
     * (PRIO-12). Reuses the same shape / filtering as getList()'s prioritized.
     *
     * @param array $user Acting user row
     * @param int   $n    Already-clamped count
     * @return array<int, array>
     */
    private function digestTop(array $user, int $n): array
    {
        $prioritized = $this->loadPrioritized($user);
        return array_slice($prioritized, 0, $n);
    }

    /**
     * Cards moved to a COMPLETE lane (Done **or** Won't-fix — v1.9) in the
     * [since, until] window, across all boards the user can access,
     * oldest first (PRIO-12/14).
     *
     * Filters:
     *   - event = card_moved, to_lane (snapshot) matches a complete lane —
     *     the shared \bdone\b matcher (Done) or \bwon'?t fix\b (Won't-fix);
     *     from_lane also matching is a no-op re-lane, skipped;
     *   - board accessible to the acting user (BOARD-04b — omit, never reveal);
     *   - cards on archived boards are omitted (BOARD-06d consistency with
     *     the prioritized list, which dropped those cards entirely).
     *
     * Each item carries `lane_kind` ("done" | "wont_fix") so the markdown
     * renderer picks ✅ vs ❌ (v1.9); top-list markers use the same
     * "complete lane → ✅" rule via deriveStateMarker().
     *
     * @param array  $user  Acting user row
     * @param string $since Window start (Y-m-d H:i:s)
     * @param string $until Window end (Y-m-d H:i:s)
     * @return array<int, array>
     */
    private function digestDoneSince(array $user, string $since, string $until): array
    {
        $rows = $this->activityService
            ->activity()
            ->eventBetween('card_moved', $since, $until);

        $items = [];
        $seenCards = [];
        foreach ($rows as $row) {
            $payload = null;
            if ($row['payload_json'] !== null) {
                $decoded = json_decode((string) $row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $toLane   = $payload['to_lane'] ?? null;
            $fromLane = $payload['from_lane'] ?? null;

            // Must have landed in a complete lane (Done or Won't-fix, v1.9),
            // not just been sitting in one.
            if (!is_array($toLane) || !$this->isCompleteLane((string) ($toLane['title'] ?? ''))) {
                continue;
            }
            if (is_array($fromLane) && $this->isCompleteLane((string) ($fromLane['title'] ?? ''))) {
                continue; // Moved between two complete lanes — no new conclusion.
            }

            $boardId = (int) $row['board_id'];
            $cardId  = (int) $row['card_id'];

            if (!$this->auth->canAccessBoard($boardId)) {
                continue; // BOARD-04b: omit, don't reveal.
            }

            if (isset($seenCards[$cardId])) {
                continue; // Same card completed twice — count the first landing.
            }
            $seenCards[$cardId] = true;

            $toTitle = (string) ($toLane['title'] ?? '');
            $items[] = [
                'card_id'       => $cardId,
                'card_title'    => (string) ($row['card_title'] ?? ''),
                'board_id'      => $boardId,
                'board_title'   => (string) ($row['board_title'] ?? ''),
                'to_lane_title' => $toTitle,
                // v1.9: the ✅ / ❌ discriminator (markdown + API consumers).
                'lane_kind'     => $this->isWontFixLane($toTitle) ? 'wont_fix' : 'done',
                'actor'         => [
                    'id'   => (int) $row['actor_id'],
                    'name' => (string) ($row['actor_name'] ?? ''),
                ],
                'created_at'    => (string) $row['created_at'],
                // v1.8 CARD-15 / NOTIF-09: deep-link to the board-page modal
                // (card.php is retired); the JS opens & renders the modal.
                'card_html'     => '/board.php?id=' . (int) $boardId . '&card=' . (int) $cardId,
            ];
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // Private helpers (unchanged from PRIO-01..11)
    // ------------------------------------------------------------------

    /**
     * Resolves a digest markdown heading via Lang (i18n) with positional
     * {0},{1},… parameters; falls back to the key when Lang is unwired
     * (tests).
     *
     * @param string     $key    i18n key
     * @param string[]   $params Positional placeholder values
     */
    private function langLabel(string $key, array $params = []): string
    {
        if ($this->lang === null) {
            return $key;
        }

        return $this->lang->get($key, $params);
    }

    /**
     * Markdown heading (spec §5.16: `**bold**` line, not an ATX heading —
     * chat clients render bold inline but swallow `##`).
     */
    private function markdownHeading(string $text): string
    {
        return '**' . $text . '**';
    }

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
            // v1.8 CARD-15: the card.php page is retired; the board-page
            // modal is the surface (the ?card= param makes card-modal.js
            // open it automatically on load).
            'card_html' => '/board.php?id=' . (int) $row['board_id'] . '&card=' . (int) $row['card_id'],
        ];

        if ($tier !== null) {
            $item['tier'] = $tier;
        }

        return $item;
    }

    /**
     * State marker per PRIO-07 (v1.9): In Progress → 🔨, Inbox → 📥,
     * a COMPLETE lane (Done **or** Won't-fix) → ✅,
     * otherwise the lane's own icon, or a neutral marker when it has none.
     * Lane names are matched case-insensitively against the default set (PRIO-04).
     * In the digest markdown the same lane is rendered ✅ (Done) / ❌ (Won't-fix)
     * via `lane_kind` — see digestDoneSince().
     */
    private function deriveStateMarker(string $laneTitle, ?string $laneIcon): string
    {
        $lower = mb_strtolower(trim($laneTitle));

        if ($this->isCompleteLane($laneTitle)) {
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
     * Lane-title matchers (PRIO-04 + v1.9 complete-lane rule):
     * case-insensitive, anchored, word-bounded.
     *   isDoneLane     — "Done" / "Done-ness" / "done — v2" match (hyphen IS a
     *                    word boundary); "In Progress" / "Wont fix" do not.
     *   isWontFixLane  — "Won't fix" / "Wont fix" / "won't fix (won't repro)"
     *                    match; "Will fix" / "Don't fix" do not.
     *   isCompleteLane — the union (v1.9). A card on EITHER lane is out of the
     *                    active work (PRIO-09 inbox exclusion, PRIO-12 digest).
     */
    private function isDoneLane(string $title): bool
    {
        return preg_match('/\bdone\b/iu', trim($title)) === 1;
    }

    private function isWontFixLane(string $title): bool
    {
        return preg_match("/\bwon'?t fix\b/iu", trim($title)) === 1;
    }

    private function isCompleteLane(string $title): bool
    {
        return $this->isDoneLane($title) || $this->isWontFixLane($title);
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
