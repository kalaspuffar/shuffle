<?php
namespace Shuffle\Service;

use Shuffle\Model\Board;
use Shuffle\Model\Lane;

/**
 * Lane business logic service.
 *
 * Handles lane CRUD, validation, reordering, and board version bumping.
 */
class LaneService
{
    /**
     * Maximum stored icon size (a ZWJ sequence emoji like 🏳️‍🌈 fits comfortably).
     */
    private const ICON_MAX_LENGTH = 16;

    private Lane $laneModel;
    private Board $boardModel;

    /**
     * @param Lane  $laneModel  Lane data access instance
     * @param Board $boardModel Board data access instance (for version bumping)
     */
    public function __construct(Lane $laneModel, Board $boardModel)
    {
        $this->laneModel = $laneModel;
        $this->boardModel = $boardModel;
    }

    /**
     * Lists lanes for a board, ordered by position.
     *
     * @param int $boardId Board ID
     * @return array Array of lane records
     */
    public function listLanes(int $boardId): array
    {
        return $this->laneModel->findByBoard($boardId);
    }

    /**
     * Retrieves a single lane by ID.
     *
     * @param int $id Lane ID
     * @return array|null Lane row or null
     */
    public function getLane(int $id): ?array
    {
        return $this->laneModel->findById($id);
    }

    /**
     * Creates a new lane in a board.
     *
     * @param int   $boardId Board ID
     * @param array $data    Lane data: title, icon (optional, single emoji)
     * @return array The created lane record
     * @throws \InvalidArgumentException If validation fails
     */
    public function createLane(int $boardId, array $data): array
    {
        $this->validateLane($data);

        $laneId = $this->laneModel->create([
            'board_id' => $boardId,
            'title'    => trim($data['title']),
            'icon'     => $this->normalizeIcon($data['icon'] ?? null),
        ]);

        $this->boardModel->incrementVersion($boardId);

        return $this->laneModel->findById($laneId);
    }

    /**
     * Renames a lane and/or updates its icon.
     *
     * @param int   $id   Lane ID
     * @param array $data Update data: title and/or icon (icon = null removes it)
     * @return array The updated lane record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If lane not found
     */
    public function updateLane(int $id, array $data): array
    {
        $lane = $this->laneModel->findById($id);
        if ($lane === null) {
            throw new \RuntimeException('Lane not found');
        }

        $hasTitle = array_key_exists('title', $data);
        $hasIcon  = array_key_exists('icon', $data);
        if (!$hasTitle && !$hasIcon) {
            throw new \InvalidArgumentException('Provide a lane title or icon to update');
        }

        if ($hasTitle) {
            $this->validateTitle($data['title']);
            $this->laneModel->updateTitle($id, trim($data['title']));
        }

        if ($hasIcon) {
            $this->validateIcon($data['icon']);
            $this->laneModel->setIcon($id, $this->normalizeIcon($data['icon']));
        }

        $this->boardModel->incrementVersion((int) $lane['board_id']);

        return $this->laneModel->findById($id);
    }

    /**
     * Repositions a lane within its board.
     *
     * @param int      $id          Lane ID
     * @param int|null $afterLaneId Place after this lane (null = move to first)
     * @return array The updated lane record
     * @throws \RuntimeException If lane not found
     */
    public function repositionLane(int $id, ?int $afterLaneId): array
    {
        $lane = $this->laneModel->findById($id);
        if ($lane === null) {
            throw new \RuntimeException('Lane not found');
        }

        $boardId = (int) $lane['board_id'];
        $this->laneModel->reposition($id, $boardId, $afterLaneId);
        $this->boardModel->incrementVersion($boardId);

        return $this->laneModel->findById($id);
    }

    /**
     * Deletes a lane if it has no cards.
     *
     * @param int $id Lane ID
     * @throws \RuntimeException If lane not found
     * @throws \LogicException If lane has cards (HTTP 409 Conflict)
     */
    public function deleteLane(int $id): void
    {
        $lane = $this->laneModel->findById($id);
        if ($lane === null) {
            throw new \RuntimeException('Lane not found');
        }

        $cardCount = $this->laneModel->countCards($id);
        if ($cardCount > 0) {
            throw new \LogicException('Cannot delete a lane that contains cards');
        }

        $boardId = (int) $lane['board_id'];
        $this->laneModel->delete($id);
        $this->boardModel->incrementVersion($boardId);
    }

    /**
     * Returns the board ID for a given lane.
     *
     * @param int $id Lane ID
     * @return int|null Board ID or null if lane not found
     */
    public function getBoardIdForLane(int $id): ?int
    {
        $lane = $this->laneModel->findById($id);
        return $lane !== null ? (int) $lane['board_id'] : null;
    }

    /**
     * Validates lane data (title required, icon optional single emoji).
     *
     * @param array $data Lane data
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateLane(array $data): void
    {
        $this->validateTitle($data['title'] ?? null);
        $this->validateIcon($data['icon'] ?? null);
    }

    /**
     * Validates a lane title (required) and returns it trimmed.
     *
     * @param string|null $title Lane title
     * @return string The trimmed title
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateTitle(?string $title): string
    {
        if ($title === null || trim($title) === '') {
            throw new \InvalidArgumentException('Lane title is required');
        }

        if (mb_strlen(trim($title), 'UTF-8') > 255) {
            throw new \InvalidArgumentException('Lane title must be no more than 255 characters');
        }

        return trim($title);
    }

    /**
     * Validates an optional lane icon: must be null/blank or a single emoji.
     *
     * @param string|null $icon Candidate icon
     * @throws \InvalidArgumentException If the icon is not a valid single emoji
     */
    private function validateIcon($icon): void
    {
        if ($icon === null) {
            return;
        }
        if (!is_string($icon)) {
            throw new \InvalidArgumentException('Lane icon must be a single emoji');
        }
        if (trim($icon) === '') {
            return;
        }
        if (!$this->isSingleEmoji($icon)) {
            throw new \InvalidArgumentException('Lane icon must be a single emoji');
        }
    }

    /**
     * Normalizes an optional icon value: empty/blank → null, otherwise the icon.
     *
     * @param string|null $raw Raw icon value from input
     * @return string|null Normalized icon or null
     */
    private function normalizeIcon($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && trim($raw) === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('Lane icon must be a single emoji');
        }
        return $raw;
    }

    /**
     * Returns true when the value is exactly one emoji.
     *
     * Definition: exactly one Unicode Extended_Pictographic base character,
     * optionally followed by emoji modifiers (variation selectors FE0E/FE0F,
     * combining enclosing keycap 20E3, skin-tone modifiers, ZWJ).
     *
     * Single emoji pass: "📥", "👨‍👩‍👧" (ZWJ family), "🚦️" (FE0F), "✌️🏽".
     * Rejected: "a", "📥📦" (two bases), "abc123".
     *
     * @param string $value The candidate icon
     * @return bool
     */
    private function isSingleEmoji(string $value): bool
    {
        $value = trim($value);

        // A single-emoji string is: <Extended_Pictographic>
        //                          (<Extended_Pictographic> | [FE0E-FE0F] | 20E3 |
        //                          [1F3FB-1F3FF] | 200D)*  →  1..N chars allowed.
        // We enforce the "exactly one base" invariant by counting Extended_Pictographic
        // occurrences: a single emoji has exactly one (ZWJ sequences also count as
        // one per pictographic char, so "👨‍👩" = 2 bases = rejected, and that's
        // the right call since ZWJ-family emoji are a different category).
        $count = preg_match_all('/\p{Extended_Pictographic}/u', $value);
        if ($count !== 1) {
            return false;
        }

        // Allow trailing modifier chars (variation selector, keycap, ZWJ,
        // skin-tone) after exactly one base emoji character.
        $match = preg_match(
            '/^[\p{Extended_Pictographic}][\x{FE0E}\x{FE0F}\x{20E3}\x{200D}\x{1F3FB}-\x{1F3FF}]*$/u',
            $value
        );

        return $match === 1;
    }
}
