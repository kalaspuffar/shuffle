<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Model\Board;
use Shuffle\Model\Card;
use Shuffle\Model\Label;

/**
 * Label business logic service (LABEL-01..03, §5.15).
 *
 * Handles board-level label CRUD, validation (name + color), attach/detach
 * on cards, and the card_count projection for the management UI.
 *
 * PALETTE is the single source of truth for the 12 preset colors
 * (served to the UI via <script id="board-script" data-label-palette="…">
 * by board.php — mirrors the data-lane-templates pattern for LANE-10/11).
 */
class LabelService
{
    /**
     * Curated label color palette (LABEL-02 §5.15).
     *
     * 12 preset colors. The UI renders these as swatches; a free-hex
     * escape input also accepts any value matching the validation regex.
     * Single source — served to the client; the server validates both.
     */
    public const PALETTE = [
        ['hex' => '#F44336', 'name' => 'red'],
        ['hex' => '#FF5722', 'name' => 'orange'],
        ['hex' => '#FFC107', 'name' => 'amber'],
        ['hex' => '#FF9800', 'name' => 'deep orange'],
        ['hex' => '#4CAF50', 'name' => 'green'],
        ['hex' => '#00BCD4', 'name' => 'cyan'],
        ['hex' => '#2196F3', 'name' => 'blue'],
        ['hex' => '#3F51B5', 'name' => 'indigo'],
        ['hex' => '#9C27B0', 'name' => 'purple'],
        ['hex' => '#E91E63', 'name' => 'pink'],
        ['hex' => '#795548', 'name' => 'brown'],
        ['hex' => '#607D8B', 'name' => 'blue grey'],
    ];

    /** Valid #RRGGBB values (case-insensitive hex digits). */
    public const COLOR_REGEX = '/^#[0-9A-Fa-f]{6}$/';

    /** Max name length (matches labels.name VARCHAR(64)). */
    public const NAME_MAX = 64;

    private Label $labelModel;
    private Board $boardModel;
    private Card $cardModel;

    public function __construct(Label $labelModel, Board $boardModel, Card $cardModel)
    {
        $this->labelModel = $labelModel;
        $this->boardModel = $boardModel;
        $this->cardModel  = $cardModel;
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * Validates a name (non-empty, ≤ LABEL-02 §5.15).
     * Throws InvalidArgumentException with a human-readable message.
     */
    public function validateName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Label name is required');
        }
        if (mb_strlen($trimmed) > self::NAME_MAX) {
            throw new \InvalidArgumentException('Label name is too long (max ' . self::NAME_MAX . ' chars)');
        }
        return $trimmed;
    }

    /**
     * Validates a color (LABEL-02 §5.15).
     * Throws InvalidArgumentException on failure.
     */
    public function validateColor(string $color): string
    {
        if (!preg_match(self::COLOR_REGEX, $color)) {
            throw new \InvalidArgumentException('Invalid color');
        }
        return strtoupper($color);
    }

    // ----------------------------------------------------------------
    // Access-check helpers (used by the controller before mutating)
    // ----------------------------------------------------------------

    /**
     * Returns the board_id a label belongs to, or null if it does not
     * exist. Lets the controller enforce BOARD-04b (404-not-403) before
     * a mutating action.
     */
    public function peekBoardId(int $labelId): ?int
    {
        $row = $this->labelModel->findById($labelId);
        return $row !== null ? (int) $row['board_id'] : null;
    }

    /**
     * Returns the board_id a card belongs to, or null if it does not
     * exist.
     */
    public function peekCardBoardId(int $cardId): ?int
    {
        return $this->cardModel->getBoardId($cardId);
    }

    // ----------------------------------------------------------------
    // Board-level CRUD (LABEL-02) — called from LabelController
    // ----------------------------------------------------------------

    /**
     * Lists labels for a board with their card_count.
     *
     * @return array array of {id, name, color, card_count}
     */
    public function listForBoard(int $boardId): array
    {
        $labels     = $this->labelModel->findByBoard($boardId);
        $counts     = $this->labelModel->cardCountsForBoard($boardId);
        foreach ($labels as &$l) {
            $l['card_count'] = $counts[(int)$l['id']] ?? 0;
        }
        unset($l);
        return $labels;
    }

    /**
     * Creates a label on a board.
     *
     * @throws \InvalidArgumentException 400 (name/color)
     * @throws \RuntimeException         409 (duplicate name)
     * @return array created label with card_count=0
     */
    public function create(int $boardId, array $data): array
    {
        $name  = $this->validateName((string)($data['name'] ?? ''));
        $color = $this->validateColor((string)($data['color'] ?? ''));

        if ($this->labelModel->existsNameOnBoard($name, $boardId)) {
            throw new \RuntimeException('A label with this name already exists on this board');
        }

        $id = $this->labelModel->create([
            'board_id' => $boardId,
            'name'     => $name,
            'color'    => $color,
        ]);

        $this->boardModel->incrementVersion($boardId);

        return [
            'id'         => $id,
            'board_id'   => $boardId,
            'name'       => $name,
            'color'      => $color,
            'card_count' => 0,
        ];
    }

    /**
     * Renames / re-colors a label (partial update).
     *
     * @param array $fields subset of {name, color}
     * @throws \InvalidArgumentException 400
     * @throws \RuntimeException         409 (duplicate name) OR 404 (not found)
     * @return array updated label
     */
    public function update(int $id, array $fields): array
    {
        $existing = $this->labelModel->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('Label not found');
        }

        $newFields = [];
        if (array_key_exists('name', $fields)) {
            $newName = $this->validateName((string) $fields['name']);
            if ($newName !== $existing['name'] &&
                $this->labelModel->existsNameOnBoard($newName, (int)$existing['board_id'], $id)) {
                throw new \RuntimeException('A label with this name already exists on this board');
            }
            $newFields['name'] = $newName;
        }
        if (array_key_exists('color', $fields)) {
            $newFields['color'] = $this->validateColor((string) $fields['color']);
        }

        if ($newFields) {
            $this->labelModel->update($id, $newFields);
            $this->boardModel->incrementVersion((int)$existing['board_id']);
        }

        $row = $this->labelModel->findById($id);
        $row['card_count'] = $this->labelModel->cardCount($id);
        return $row;
    }

    /**
     * Deletes a label. All card_labels rows cascade.
     *
     * @throws \RuntimeException 404 (not found)
     */
    public function delete(int $id): void
    {
        $label = $this->labelModel->findById($id);
        if ($label === null) {
            throw new \RuntimeException('Label not found');
        }
        $this->labelModel->delete($id);
        $this->boardModel->incrementVersion((int)$label['board_id']);
    }

    // ----------------------------------------------------------------
    // Card-level attach/detach (LABEL-01) — called from LabelController
    // ----------------------------------------------------------------

    /**
     * Returns the (attached) labels for a card.
     *
     * @return array
     */
    public function labelsForCard(int $cardId): array
    {
        return $this->labelModel->labelsForCard($cardId);
    }

    /**
     * Attaches a label to a card.
     *
     * Same-board invariant: the label's board_id must equal the card's
     * board_id, otherwise 400 (LABEL-01 §5.15 — not 404, per the
     * strict-board-isolation decision in the spec: board access exists,
     * only the label's membership is out of scope).
     *
     * Idempotent — re-attaching is a no-op (INSERT IGNORE).
     *
     * @throws \RuntimeException         404 (card or label not found)
     * @throws \InvalidArgumentException 400 (cross-board)
     */
    public function attach(int $cardId, int $labelId): void
    {
        $card = $this->cardModel->findById($cardId);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }
        $cardBoard = $this->cardModel->getBoardId($cardId);

        $label = $this->labelModel->findById($labelId);
        if ($label === null) {
            throw new \RuntimeException('Label not found');
        }

        if ((int)$cardBoard !== (int)$label['board_id']) {
            throw new \InvalidArgumentException('Label belongs to a different board');
        }

        $this->labelModel->attach($cardId, $labelId);
        $this->boardModel->incrementVersion((int)$cardBoard);
    }

    /**
     * Detaches a label from a card.
     *
     * Idempotent — detaching a non-attached label is a no-op.
     *
     * @throws \RuntimeException 404 (card or label not found)
     */
    public function detach(int $cardId, int $labelId): void
    {
        $card = $this->cardModel->findById($cardId);
        if ($card === null) {
            throw new \RuntimeException('Card not found');
        }
        $cardBoard = $this->cardModel->getBoardId($cardId);

        $label = $this->labelModel->findById($labelId);
        if ($label === null) {
            throw new \RuntimeException('Label not found');
        }
        if ((int)$cardBoard !== (int)$label['board_id']) {
            throw new \InvalidArgumentException('Label belongs to a different board');
        }

        $this->labelModel->detach($cardId, $labelId);
        $this->boardModel->incrementVersion((int)$cardBoard);
    }
}
