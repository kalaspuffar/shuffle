<?php
declare(strict_types=1);

namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Label data access layer (LABEL-01..03, §5.15).
 *
 * DAO for the `labels` and `card_labels` tables.
 * All card_labels row management (attach/detach/union) is also exposed
 * here so CardService::mergeInto and LabelService share one code path.
 */
class Label
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ---------------------------------------------------------------
    // CRUD on labels
    // ---------------------------------------------------------------

    /**
     * Finds a single label by ID.
     *
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT id, board_id, name, color, created_at FROM labels WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns all labels for a board, ordered by name.
     *
     * @return array
     */
    public function findByBoard(int $boardId): array
    {
        return $this->db->fetchAll(
            'SELECT id, board_id, name, color, created_at
             FROM labels WHERE board_id = ?
             ORDER BY name ASC',
            [$boardId]
        );
    }

    /**
     * Checks whether a label with this (name, board_id) already exists
     * (case-sensitive, v1 — no lowercasing), excluding the given $excludeId
     * for the rename case.
     *
     * @return bool true if a conflicting row exists
     */
    public function existsNameOnBoard(string $name, int $boardId, int $excludeId = 0): bool
    {
        $params = [$name, $boardId];
        $excl = $excludeId > 0 ? ' AND id <> ' . (int)$excludeId : '';
        // No explicit COLLATE — the column's default utf8mb4_unicode_ci is
        // case-insensitive and matches the UNIQUE KEY's implicit collation,
        // so the app-level "is a duplicate?" check agrees with the storage
        // layer. 'Bug' and 'bug' are the same label (LABEL-02, §5.15). A clean
        // 409 comes back from the service instead of a 500 from the DB.
        $row = $this->db->fetch(
            'SELECT id FROM labels WHERE name = ? AND board_id = ?' . $excl,
            $params
        );
        return $row !== null;
    }

    /**
     * Creates a label.
     *
     * @param array $data board_id, name, color
     * @return int new label ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO labels (board_id, name, color) VALUES (?, ?, ?)',
            [$data['board_id'], $data['name'], $data['color']]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Renames and/or re-colors a label (partial update — pass only the
     * fields that changed).
     *
     * @param array $fields subset of ['name' => …, 'color' => …]
     */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [];
        if (array_key_exists('name', $fields)) {
            $sets[] = 'name = ?';
            $params[] = $fields['name'];
        }
        if (array_key_exists('color', $fields)) {
            $sets[] = 'color = ?';
            $params[] = $fields['color'];
        }
        if (!$sets) {
            return;
        }
        $params[] = $id;
        $this->db->execute(
            'UPDATE labels SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    /**
     * Deletes a label. All card_labels rows cascade (FK ON DELETE CASCADE).
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM labels WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------
    // card_labels (attachment)
    // ---------------------------------------------------------------

    /**
     * Returns the label_ids attached to a card.
     *
     * @return int[]
     */
    public function labelIdsForCard(int $cardId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT label_id FROM card_labels WHERE card_id = ? ORDER BY label_id ASC',
            [$cardId]
        );
        return array_map('intval', array_column($rows, 'label_id'));
    }

    /**
     * Returns full label rows for a single card.
     *
     * @return array
     */
    public function labelsForCard(int $cardId): array
    {
        return $this->db->fetchAll(
            'SELECT l.id, l.board_id, l.name, l.color, l.created_at
             FROM labels l
             JOIN card_labels cl ON cl.label_id = l.id
             WHERE cl.card_id = ?
             ORDER BY l.name ASC',
            [$cardId]
        );
    }

    /**
     * Attaches a label to a card. Idempotent (INSERT IGNORE).
     */
    public function attach(int $cardId, int $labelId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO card_labels (card_id, label_id) VALUES (?, ?)',
            [$cardId, $labelId]
        );
    }

    /**
     * Detaches a label from a card. Idempotent (no-op if not present).
     */
    public function detach(int $cardId, int $labelId): void
    {
        $this->db->execute(
            'DELETE FROM card_labels WHERE card_id = ? AND label_id = ?',
            [$cardId, $labelId]
        );
    }

    /**
     * Returns the number of cards a label is attached to (for the
     * management UI "N cards" badge).
     *
     * @return int
     */
    public function cardCount(int $labelId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS c FROM card_labels WHERE label_id = ?',
            [$labelId]
        );
        return $row !== null ? (int) $row['c'] : 0;
    }

    /**
     * Returns all card_label counts for every label on a board in a
     * single grouped query (avoids N+1 in the management listing).
     *
     * @param int $boardId
     * @return array  labelId => count
     */
    public function cardCountsForBoard(int $boardId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT cl.label_id, COUNT(*) AS c
             FROM card_labels cl
             JOIN labels l ON l.id = cl.label_id
             WHERE l.board_id = ?
             GROUP BY cl.label_id',
            [$boardId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['label_id']] = (int)$r['c'];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Merge union (LABEL-03) — called by CardService::mergeInto
    // ---------------------------------------------------------------

    /**
     * Copies every label attached to $fromCardId to $toCardId
     * (idempotent — labels already on the survivor are not re-inserted).
     *
     * The source's card_labels rows are left in place here; the caller
     * deletes the source card afterwards, and the FK cascade removes them.
     *
     * @return int number of labels actually attached to $toCardId in this call
     */
    public function unionToCard(int $fromCardId, int $toCardId): int
    {
        if ($fromCardId === $toCardId) {
            return 0;
        }
        $srcLabelIds = $this->labelIdsForCard($fromCardId);
        $attached = 0;
        foreach ($srcLabelIds as $labelId) {
            // attach() is INSERT IGNORE — a no-op if the survivor already has it.
            $this->attach($toCardId, $labelId);
            $attached++;
        }
        return $attached;
    }
}
