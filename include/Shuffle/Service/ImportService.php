<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Database;
use Shuffle\Core\S3Client;

/**
 * Trello JSON import service.
 *
 * Parses Trello board export files and maps entities to Shuffle:
 *   - Trello Board   → Board (with trello_id for idempotency)
 *   - Trello List    → Lane  (position normalized to gap scheme)
 *   - Trello Card    → Card  (title, description, due date, trello_id)
 *   - Trello Member  → User  (placeholder, matched by trello_id)
 *   - Trello Comment → Comment
 *   - Trello Checklist → Checklist + ChecklistItems
 *   - Trello Attachment → Attachment (downloaded from CDN, re-uploaded to S3)
 *
 * All operations are wrapped in a single transaction for atomicity.
 * The importer is idempotent: re-running with the same JSON will detect
 * the existing board via trello_id and skip re-importing.
 */
class ImportService
{
    private Database $db;
    private ?S3Client $s3;
    private int $chunkSize;

    /** Gap between position values for lanes and cards */
    private const POSITION_GAP = 1000;

    /**
     * @param Database      $db        Database instance
     * @param S3Client|null $s3        S3 client for attachment uploads (null = skip attachments)
     * @param int           $chunkSize Multipart chunk size in bytes
     */
    public function __construct(Database $db, ?S3Client $s3, int $chunkSize = 5242880)
    {
        $this->db        = $db;
        $this->s3        = $s3;
        $this->chunkSize = $chunkSize;
    }

    /**
     * Imports a Trello JSON export into a Shuffle board.
     *
     * @param string $jsonPath       Path to the Trello JSON export file
     * @param int    $orgId          Organization ID to assign the board to
     * @param int    $importerUserId User ID of the person running the import
     * @return int The Shuffle board ID that was created
     * @throws \InvalidArgumentException If the JSON is invalid or file not found
     * @throws \RuntimeException If an existing board with this trello_id already exists
     */
    public function importTrelloBoard(string $jsonPath, int $orgId, int $importerUserId): int
    {
        if (!file_exists($jsonPath)) {
            throw new \InvalidArgumentException('File not found: ' . $jsonPath);
        }

        $json = file_get_contents($jsonPath);
        if ($json === false) {
            throw new \InvalidArgumentException('Cannot read file: ' . $jsonPath);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        $this->validateTrelloJson($data);

        $trelloBoardId = $data['id'] ?? null;

        // Idempotency check — skip if already imported
        if ($trelloBoardId !== null) {
            $existing = $this->db->fetch(
                'SELECT id FROM boards WHERE trello_id = ?',
                [$trelloBoardId]
            );
            if ($existing !== null) {
                throw new \RuntimeException(
                    'Board already imported (Shuffle board ID: ' . $existing['id'] . ')'
                );
            }
        }

        $this->out('Starting import of "' . ($data['name'] ?? 'Untitled') . '"...');

        $this->db->beginTransaction();
        try {
            // Step 1: Create/find placeholder users from board members
            $this->out('Importing members...');
            $userMap = $this->importMembers($data['members'] ?? []);

            // Step 2: Create the board
            $this->out('Creating board...');
            $boardId = $this->createBoard($data, $importerUserId, $trelloBoardId);

            // Step 3: Assign board to organization
            $this->db->execute(
                'INSERT INTO board_organizations (board_id, organization_id) VALUES (?, ?)',
                [$boardId, $orgId]
            );

            // Step 4: Create lanes from Trello lists
            $this->out('Importing lanes...');
            $laneMap = $this->importLanes($data['lists'] ?? [], $boardId);

            // Build checklist map from board data (Trello puts checklists at board level)
            $checklistMap = [];
            foreach ($data['checklists'] ?? [] as $cl) {
                $checklistMap[$cl['id']] = $cl;
            }

            // Step 5: Create cards
            $this->out('Importing cards...');
            $cardMap = $this->importCards($data['cards'] ?? [], $laneMap, $userMap, $importerUserId);

            // Step 6: Import checklists for each card
            $this->out('Importing checklists...');
            $this->importChecklists($data['cards'] ?? [], $cardMap, $checklistMap);

            // Step 7: Import comments from actions
            $this->out('Importing comments...');
            $this->importComments($data['actions'] ?? [], $cardMap, $userMap, $importerUserId);

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Step 8: Import attachments outside the transaction.
        // Attachment downloads (Trello CDN) and S3 uploads are long-running network
        // operations that must not hold a DB transaction open (risks exceeding
        // innodb_lock_wait_timeout). Failures are non-fatal and logged per the
        // resilience policy ("failed downloads are skipped").
        $this->out('Importing attachments (this may take a while)...');
        $this->importAttachments($data['cards'] ?? [], $cardMap, $boardId, $importerUserId);

        $this->out('Import complete! Board ID: ' . $boardId);
        return $boardId;
    }

    /**
     * Creates placeholder users for Trello members not yet in the system.
     *
     * Matches existing users by trello_id. Creates new placeholder users
     * for unknown members with username prefixed 'trello_' to avoid
     * collision with real usernames.
     *
     * @param array $members Trello members array from JSON
     * @return array Map of Trello member ID => Shuffle user ID
     */
    private function importMembers(array $members): array
    {
        $userMap = [];

        foreach ($members as $member) {
            $trelloId = $member['id'] ?? null;
            if (!$trelloId) {
                continue;
            }

            // Check if we already have this Trello user
            $existing = $this->db->fetch(
                'SELECT id FROM users WHERE trello_id = ?',
                [$trelloId]
            );

            if ($existing !== null) {
                $userMap[$trelloId] = (int) $existing['id'];
                continue;
            }

            // Generate a unique username: trello_{username}
            $baseUsername = 'trello_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $member['username'] ?? $trelloId);
            $username = $this->uniqueUsername($baseUsername);

            $email = $member['email'] ?? ($username . '@trello.placeholder');
            $name  = $member['fullName'] ?? $member['username'] ?? $trelloId;

            // Create placeholder user (inactive, no real password)
            $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);

            $this->db->execute(
                'INSERT INTO users (username, password_hash, name, email, role, is_placeholder, status, trello_id)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                [$username, $passwordHash, $name, $email, 'member', 'inactive', $trelloId]
            );

            $userId = (int) $this->db->lastInsertId();
            $userMap[$trelloId] = $userId;

            $this->out('  Created placeholder user: ' . $username . ' (' . $name . ')');
        }

        return $userMap;
    }

    /**
     * Creates the Shuffle board from Trello board data.
     *
     * @param array  $data          Trello board JSON data
     * @param int    $importerUserId Creator user ID
     * @param string|null $trelloId  Trello board ID for dedup
     * @return int The new board ID
     */
    private function createBoard(array $data, int $importerUserId, ?string $trelloId): int
    {
        $this->db->execute(
            'INSERT INTO boards (title, description, visibility, created_by, trello_id)
             VALUES (?, ?, ?, ?, ?)',
            [
                $data['name'] ?? 'Imported Board',
                $data['desc'] ?? null,
                'organization',
                $importerUserId,
                $trelloId,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Creates lanes from Trello lists.
     *
     * Trello lists that are archived (closed) are skipped.
     * Position is normalized to the gap scheme (1000, 2000, ...).
     *
     * @param array $lists   Trello lists array
     * @param int   $boardId Shuffle board ID
     * @return array Map of Trello list ID => Shuffle lane ID
     */
    private function importLanes(array $lists, int $boardId): array
    {
        $laneMap = [];

        // Sort by Trello pos to preserve ordering
        usort($lists, function (array $a, array $b): int {
            return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0);
        });

        $position = self::POSITION_GAP;
        foreach ($lists as $list) {
            if ($list['closed'] ?? false) {
                continue; // Skip archived lists
            }

            $listId = $list['id'] ?? null;
            if (!$listId) {
                continue;
            }

            $this->db->execute(
                'INSERT INTO lanes (board_id, title, position) VALUES (?, ?, ?)',
                [$boardId, $list['name'] ?? 'Untitled', $position]
            );

            $laneMap[$listId] = (int) $this->db->lastInsertId();
            $position += self::POSITION_GAP;
        }

        return $laneMap;
    }

    /**
     * Creates cards from Trello cards.
     *
     * Archived cards are skipped. Card positions are normalized.
     * Card assignments (idMembers) are imported.
     *
     * @param array $cards          Trello cards array
     * @param array $laneMap        Map of Trello list ID => Shuffle lane ID
     * @param array $userMap        Map of Trello member ID => Shuffle user ID
     * @param int   $importerUserId Creator user ID for cards without owners
     * @return array Map of Trello card ID => Shuffle card ID
     */
    private function importCards(array $cards, array $laneMap, array $userMap, int $importerUserId): array
    {
        $cardMap = [];

        // Group cards by list to normalize positions per lane
        $cardsByList = [];
        foreach ($cards as $card) {
            if ($card['closed'] ?? false) {
                continue; // Skip archived cards
            }
            $listId = $card['idList'] ?? null;
            if (!$listId || !isset($laneMap[$listId])) {
                continue;
            }
            $cardsByList[$listId][] = $card;
        }

        foreach ($cardsByList as $listId => $listCards) {
            // Sort by Trello pos
            usort($listCards, function (array $a, array $b): int {
                return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0);
            });

            $laneId = $laneMap[$listId];
            $position = self::POSITION_GAP;

            foreach ($listCards as $card) {
                $trelloCardId = $card['id'] ?? null;
                if (!$trelloCardId) {
                    continue;
                }

                $dueDate = null;
                if (!empty($card['due'])) {
                    $dt = new \DateTime($card['due']);
                    $dueDate = $dt->format('Y-m-d');
                }

                $this->db->execute(
                    'INSERT INTO cards (lane_id, title, description, due_date, position, created_by, trello_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $laneId,
                        $card['name'] ?? 'Untitled',
                        $card['desc'] ?? null,
                        $dueDate,
                        $position,
                        $importerUserId,
                        $trelloCardId,
                    ]
                );

                $cardId = (int) $this->db->lastInsertId();
                $cardMap[$trelloCardId] = $cardId;

                // Import card member assignments
                foreach ($card['idMembers'] ?? [] as $trelloMemberId) {
                    if (isset($userMap[$trelloMemberId])) {
                        $this->db->execute(
                            'INSERT IGNORE INTO card_assignments (card_id, user_id) VALUES (?, ?)',
                            [$cardId, $userMap[$trelloMemberId]]
                        );
                    }
                }

                $position += self::POSITION_GAP;
            }
        }

        return $cardMap;
    }

    /**
     * Imports checklists for all cards.
     *
     * @param array $cards        Trello cards array
     * @param array $cardMap      Map of Trello card ID => Shuffle card ID
     * @param array $checklistMap Map of Trello checklist ID => checklist data
     */
    private function importChecklists(array $cards, array $cardMap, array $checklistMap): void
    {
        foreach ($cards as $card) {
            $trelloCardId = $card['id'] ?? null;
            if (!$trelloCardId || !isset($cardMap[$trelloCardId])) {
                continue;
            }

            $cardId = $cardMap[$trelloCardId];
            $checklistIds = $card['idChecklists'] ?? [];
            $position = self::POSITION_GAP;

            foreach ($checklistIds as $clId) {
                $cl = $checklistMap[$clId] ?? null;
                if (!$cl) {
                    continue;
                }

                $this->db->execute(
                    'INSERT INTO checklists (card_id, title, position) VALUES (?, ?, ?)',
                    [$cardId, $cl['name'] ?? 'Checklist', $position]
                );

                $checklistId = (int) $this->db->lastInsertId();
                $position += self::POSITION_GAP;

                // Sort items by pos
                $items = $cl['checkItems'] ?? [];
                usort($items, function (array $a, array $b): int {
                    return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0);
                });

                $itemPosition = self::POSITION_GAP;
                foreach ($items as $item) {
                    $isChecked = ($item['state'] ?? 'incomplete') === 'complete' ? 1 : 0;

                    $this->db->execute(
                        'INSERT INTO checklist_items (checklist_id, title, is_checked, position)
                         VALUES (?, ?, ?, ?)',
                        [$checklistId, $item['name'] ?? '', $isChecked, $itemPosition]
                    );

                    $itemPosition += self::POSITION_GAP;
                }
            }
        }
    }

    /**
     * Imports comments from Trello action log.
     *
     * Filters actions to type=commentCard only.
     * Preserves original author and timestamp.
     *
     * @param array $actions       Trello actions array
     * @param array $cardMap       Map of Trello card ID => Shuffle card ID
     * @param array $userMap       Map of Trello member ID => Shuffle user ID
     * @param int   $importerUserId Fallback user ID when member not found
     */
    private function importComments(array $actions, array $cardMap, array $userMap, int $importerUserId): void
    {
        // Trello actions are newest-first; reverse so comments appear in chronological order
        $comments = array_filter($actions, function (array $action): bool {
            return ($action['type'] ?? '') === 'commentCard';
        });

        $comments = array_reverse(array_values($comments));

        foreach ($comments as $action) {
            $trelloCardId = $action['data']['card']['id'] ?? null;
            if (!$trelloCardId || !isset($cardMap[$trelloCardId])) {
                continue;
            }

            $cardId = $cardMap[$trelloCardId];
            $body   = $action['data']['text'] ?? '';

            if (trim($body) === '') {
                continue;
            }

            $trelloMemberId = $action['memberCreator']['id'] ?? null;
            $userId = isset($trelloMemberId, $userMap[$trelloMemberId])
                ? $userMap[$trelloMemberId]
                : $importerUserId;

            $createdAt = isset($action['date'])
                ? (new \DateTime($action['date']))->format('Y-m-d H:i:s')
                : null;

            $this->db->execute(
                'INSERT INTO comments (card_id, user_id, body, created_at, updated_at)
                 VALUES (?, ?, ?, COALESCE(?, NOW()), COALESCE(?, NOW()))',
                [$cardId, $userId, $body, $createdAt, $createdAt]
            );
        }
    }

    /**
     * Downloads attachments from Trello CDN and re-uploads to S3.
     *
     * Skips attachments with non-HTTP URLs (e.g., internal Trello power-up links).
     * Failed downloads are logged and skipped (non-fatal).
     *
     * @param array $cards         Trello cards array
     * @param array $cardMap       Map of Trello card ID => Shuffle card ID
     * @param int   $boardId       Shuffle board ID (for S3 key prefix)
     * @param int   $importerUserId Uploader user ID
     */
    private function importAttachments(array $cards, array $cardMap, int $boardId, int $importerUserId): void
    {
        if ($this->s3 === null) {
            $this->out('  Skipping attachments — S3 client not configured.');
            return;
        }

        foreach ($cards as $card) {
            $trelloCardId = $card['id'] ?? null;
            if (!$trelloCardId || !isset($cardMap[$trelloCardId])) {
                continue;
            }

            $cardId = $cardMap[$trelloCardId];

            foreach ($card['attachments'] ?? [] as $attachment) {
                $url      = $attachment['url'] ?? null;
                $fileName = $attachment['name'] ?? 'attachment';
                $mimeType = $attachment['mimeType'] ?? 'application/octet-stream';

                if (!$url || !str_starts_with($url, 'http')) {
                    continue; // Skip non-HTTP URLs
                }

                $this->out('  Downloading: ' . $fileName);

                try {
                    $fileData = $this->downloadUrl($url);
                    $fileSize = strlen($fileData);

                    if ($fileSize === 0) {
                        $this->out('    Skipping empty file: ' . $fileName);
                        continue;
                    }

                    // Generate S3 key
                    $uuid = bin2hex(random_bytes(16));
                    $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($fileName));
                    $s3Key = $boardId . '/' . $cardId . '/' . $uuid . '_' . $safeFileName;

                    // php://temp spills to disk above 2 MB, avoiding double-buffering large
                    // attachments entirely in memory (file string + stream simultaneously).
                    $stream = fopen('php://temp', 'rb+');
                    fwrite($stream, $fileData);
                    rewind($stream);

                    if ($fileSize <= $this->chunkSize) {
                        $this->s3->putObject($s3Key, $stream, $fileSize, $mimeType);
                    } else {
                        // Multipart upload for large files
                        rewind($stream);
                        $uploadId = $this->s3->createMultipartUpload($s3Key, $mimeType);
                        try {
                            $parts = [];
                            $partNum = 1;
                            while (!feof($stream)) {
                                $chunk = fread($stream, $this->chunkSize);
                                if ($chunk === false || $chunk === '') break;
                                $etag = $this->s3->uploadPart($s3Key, $uploadId, $partNum, $chunk);
                                $parts[] = ['partNumber' => $partNum, 'etag' => $etag];
                                $partNum++;
                            }
                            $this->s3->completeMultipartUpload($s3Key, $uploadId, $parts);
                        } catch (\RuntimeException $e) {
                            $this->s3->abortMultipartUpload($s3Key, $uploadId);
                            throw $e;
                        }
                    }

                    fclose($stream);

                    // Store attachment metadata
                    $this->db->execute(
                        'INSERT INTO attachments (card_id, user_id, file_name, file_size, s3_key, mime_type)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$cardId, $importerUserId, $fileName, $fileSize, $s3Key, $mimeType]
                    );

                    $this->out('    Uploaded: ' . $fileName . ' (' . $this->formatSize($fileSize) . ')');
                } catch (\Exception $e) {
                    $this->out('    WARNING: Failed to import attachment "' . $fileName . '": ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Downloads a URL and returns the body as a string.
     *
     * @param string $url URL to download
     * @return string File contents
     * @throws \RuntimeException If download fails
     */
    private function downloadUrl(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 30,
                'header'  => "User-Agent: Shuffle-Import/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('Failed to download: ' . $url);
        }

        // Check HTTP status
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $statusLine, $m) && (int) $m[1] >= 400) {
            throw new \RuntimeException('HTTP ' . $m[1] . ' downloading: ' . $url);
        }

        return $body;
    }

    /**
     * Finds a unique username by appending a counter suffix if needed.
     *
     * @param string $base Base username
     * @return string Unique username
     */
    private function uniqueUsername(string $base): string
    {
        // Truncate to max 60 chars so counter fits within 64
        $base = substr($base, 0, 60);
        $username = $base;
        $counter = 1;

        while ($this->db->fetch('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            $username = $base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Validates that the JSON contains required Trello board fields.
     *
     * @param array $data Decoded JSON data
     * @throws \InvalidArgumentException If required fields are missing
     */
    private function validateTrelloJson(array $data): void
    {
        // Required keys must match what the CLI dry-run checks to guarantee
        // a file that passes dry-run will also pass full import validation.
        $required = ['id', 'name', 'lists', 'cards', 'members', 'actions'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new \InvalidArgumentException(
                    'Invalid Trello export: missing required field "' . $field . '"'
                );
            }
        }

        if (!is_array($data['lists']) || !is_array($data['cards'])) {
            throw new \InvalidArgumentException(
                'Invalid Trello export: "lists" and "cards" must be arrays'
            );
        }
    }

    /**
     * Formats bytes to a human-readable string.
     *
     * @param int $bytes Byte count
     * @return string Formatted size
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * Prints a progress message to stdout.
     *
     * @param string $message Message to print
     */
    private function out(string $message): void
    {
        echo $message . "\n";
    }
}
