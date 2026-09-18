<?php
declare(strict_types=1);

namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Attachment data access layer.
 *
 * Provides CRUD operations for the attachments table. Each attachment
 * references a card and stores file metadata along with the S3 object key.
 */
class Attachment
{
    private Database $db;

    /**
     * Previewable MIME types (FILE-06/07, §5.23) — the SINGLE source of
     * truth shared by the model query, AttachmentService::preview() and
     * (mirrored client-side) card-modal.js PREVIEWABLE_MIME.
     *
     * SVG is deliberately excluded (it can carry script; the preview
     * surface is shared with <embed>, where <img> sanitization does not
     * apply — spec §5.23 decision note).
     */
    public const PREVIEWABLE_MIME = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    // uploaded_at is aliased to created_at to match the documented API response shape
    private const SELECT_COLUMNS = 'id, card_id, user_id, file_name, file_size, s3_key, mime_type, uploaded_at AS created_at';

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Finds an attachment by ID.
     *
     * @param int $id Attachment ID
     * @return array|null Attachment row or null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM attachments WHERE id = ?',
            [$id]
        );
    }

    /**
     * Returns all attachments for a card, ordered by upload time.
     *
     * @param int $cardId Card ID
     * @return array Array of attachment rows
     */
    public function findByCard(int $cardId): array
    {
        return $this->db->fetchAll(
            'SELECT a.' . str_replace(', ', ', a.', self::SELECT_COLUMNS) . ', u.name AS user_name'
            . ' FROM attachments a'
            . ' JOIN users u ON a.user_id = u.id'
            . ' WHERE a.card_id = ?'
            . ' ORDER BY a.uploaded_at ASC',
            [$cardId]
        );
    }

    /**
     * Creates an attachment record.
     *
     * @param array $data Attachment data: card_id, user_id, file_name, file_size, s3_key, mime_type
     * @return int The new attachment's ID
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO attachments (card_id, user_id, file_name, file_size, s3_key, mime_type)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['card_id'],
                $data['user_id'],
                $data['file_name'],
                $data['file_size'],
                $data['s3_key'],
                $data['mime_type'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deletes an attachment record by ID.
     *
     * @param int $id Attachment ID
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM attachments WHERE id = ?', [$id]);
    }

    /**
     * Returns the board ID that contains the given attachment.
     *
     * Uses a single JOIN across attachments → cards → lanes to avoid two
     * round-trips on every download and delete access-control check.
     *
     * @param int $id Attachment ID
     * @return int|null Board ID or null if attachment does not exist
     */
    public function getBoardId(int $id): ?int
    {
        $row = $this->db->fetch(
            'SELECT l.board_id
             FROM attachments a
             JOIN cards c ON a.card_id = c.id
             JOIN lanes l ON c.lane_id = l.id
             WHERE a.id = ?',
            [$id]
        );

        return $row !== null ? (int) $row['board_id'] : null;
    }

    /**
     * Returns all S3 keys for attachments belonging to cards in a board.
     *
     * Used for cascading board deletion to clean up S3 objects.
     *
     * @param int $boardId Board ID
     * @return array Array of S3 key strings
     */
    public function findS3KeysByBoard(int $boardId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.s3_key FROM attachments a
             JOIN cards c ON a.card_id = c.id
             JOIN lanes l ON c.lane_id = l.id
             WHERE l.board_id = ?',
            [$boardId]
        );

        return array_column($rows, 's3_key');
    }

    /**
     * Returns all S3 keys for attachments on a specific card.
     *
     * @param int $cardId Card ID
     * @return array Array of S3 key strings
     */
    public function findS3KeysByCard(int $cardId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s3_key FROM attachments WHERE card_id = ?',
            [$cardId]
        );

        return array_column($rows, 's3_key');
    }

    /**
     * Returns the FIRST previewable attachment per card (FILE-06, §5.23).
     *
     * One grouped query over all card ids (no N+1). "First" = MIN(id) —
     * attachment ids are monotonic with upload time, so this is the
     * earliest uploaded previewable file on the card. Used for the board
     * tile thumbnail (board-region.php).
     *
     * @param array $cardIds Card ids (empty array → empty result, no SQL)
     * @return array<int, array{id: int, file_name: string, mime_type: string}>
     */
    public function firstPreviewableByCards(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $cardIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $mimeList = self::PREVIEWABLE_MIME;
        $typePlaceholders = implode(',', array_fill(0, count($mimeList), '?'));

        // First previewable (MIN(id)) per card, joined back so file_name /
        // mime_type come from THAT row (correlated subquery keeps this
        // safe under MariaDB's ONLY_FULL_GROUP_BY — a plain
        // ANY_VALUE(MIN-join) is not the same row guarantee).
        $rows = $this->db->fetchAll(
            "SELECT a.card_id AS card_id, a.id AS id, a.file_name AS file_name,
                    a.mime_type AS mime_type
             FROM attachments a
             JOIN (
                 SELECT card_id, MIN(id) AS first_id
                 FROM attachments
                 WHERE card_id IN ($placeholders)
                   AND mime_type IN ($typePlaceholders)
                 GROUP BY card_id
             ) AS f ON f.card_id = a.card_id AND f.first_id = a.id",
            array_merge($ids, array_values($mimeList))
        );

        $byCard = [];
        foreach ($rows as $row) {
            $byCard[(int) $row['card_id']] = [
                'id'        => (int) $row['id'],
                'file_name' => (string) $row['file_name'],
                'mime_type' => (string) $row['mime_type'],
            ];
        }
        return $byCard;
    }

    /**
     * Repoints every attachment on one card to another (CARD-10, §5.17).
     *
     * No S3 object is copied (the key is just a pointer to the same
     * stored object — see AttachmentService::download for the stream
     * path). When the destination already has an attachment with the
     * **same s3_key**, the source row is dropped instead of re-pointed
     * (no duplicate rows for one stored object).
     *
     * @param int   $fromCardId  Source card (whose attachments move)
     * @param int   $toCardId    Destination card (the survivor)
     * @param array $existingKeys Existing s3_keys on the destination
     *                            (caller loads them once via
     *                            findS3KeysByCard to avoid an N+1).
     * @return array{repointed: int, dropped: int}
     */
    public function repointTo(int $fromCardId, int $toCardId, array $existingKeys = []): array
    {
        if ($fromCardId === $toCardId) {
            return ['repointed' => 0, 'dropped' => 0];
        }

        $existingSet = array_flip(array_map('strval', $existingKeys));

        $sourceRows = $this->db->fetchAll(
            'SELECT id, s3_key FROM attachments WHERE card_id = ?',
            [$fromCardId]
        );

        $repointed = 0;
        $dropped = 0;
        foreach ($sourceRows as $row) {
            $id = (int) $row['id'];
            if (isset($existingSet[(string) $row['s3_key']])) {
                // Survivor already has this same object — kill the
                // duplicate row, keep the S3 key on the survivor's row.
                $this->db->execute('DELETE FROM attachments WHERE id = ?', [$id]);
                $dropped++;
            } else {
                $this->db->execute(
                    'UPDATE attachments SET card_id = ? WHERE id = ?',
                    [$toCardId, $id]
                );
                $repointed++;
            }
        }

        return ['repointed' => $repointed, 'dropped' => $dropped];
    }
}
