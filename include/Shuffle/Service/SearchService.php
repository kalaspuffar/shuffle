<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Database;

/**
 * Search business logic service.
 *
 * Provides full-text search across cards using MySQL FULLTEXT indexes.
 * Results are filtered to only include cards on boards the user can access.
 */
class SearchService
{
    private Database $db;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Searches cards using MySQL FULLTEXT search.
     *
     * Results are filtered by board access:
     * - Admins see all cards
     * - Other users see cards on boards they created (private) or
     *   boards shared with their organization
     *
     * @param string   $query           Search query (minimum 3 characters)
     * @param array    $currentUser     Authenticated user
     * @param int|null $boardId         Optional board filter
     * @param bool     $includeArchived Whether to include archived cards/boards
     * @param int      $limit           Maximum results
     * @return array   ['results' => [...], 'total' => int]
     * @throws \InvalidArgumentException If query is too short
     */
    public function search(
        string $query,
        array $currentUser,
        ?int $boardId = null,
        bool $includeArchived = true,
        int $limit = 50
    ): array {
        $query = trim($query);

        if (mb_strlen($query, 'UTF-8') < 3) {
            throw new \InvalidArgumentException('Search query must be at least 3 characters');
        }

        // Build the FULLTEXT search query in boolean mode
        $searchTerm = $this->buildSearchTerm($query);

        $sql = 'SELECT c.id AS card_id, c.title AS card_title, c.description,
                       c.is_archived, b.id AS board_id, b.title AS board_title,
                       b.is_archived AS board_is_archived,
                       l.id AS lane_id, l.title AS lane_title,
                       MATCH(c.title, c.description) AGAINST(? IN BOOLEAN MODE) AS relevance
                FROM cards c
                JOIN lanes l ON c.lane_id = l.id
                JOIN boards b ON l.board_id = b.id';

        $params = [$searchTerm];

        // Access control: admins see everything, others see only accessible boards
        if ($currentUser['role'] !== 'admin') {
            $sql .= ' LEFT JOIN board_organizations bo ON b.id = bo.board_id';
            $sql .= ' WHERE (
                        (b.visibility = ? AND b.created_by = ?)
                        OR (b.visibility = ? AND bo.organization_id = ?)
                      )';
            $params[] = 'private';
            $params[] = (int) $currentUser['id'];
            $params[] = 'organization';
            $params[] = $currentUser['organization_id'];
            $sql .= ' AND MATCH(c.title, c.description) AGAINST(? IN BOOLEAN MODE)';
            $params[] = $searchTerm;
        } else {
            $sql .= ' WHERE MATCH(c.title, c.description) AGAINST(? IN BOOLEAN MODE)';
            $params[] = $searchTerm;
        }

        // Optional board filter
        if ($boardId !== null) {
            $sql .= ' AND b.id = ?';
            $params[] = $boardId;
        }

        // Archive filtering
        if (!$includeArchived) {
            $sql .= ' AND c.is_archived = 0 AND b.is_archived = 0';
        }

        $sql .= ' ORDER BY relevance DESC LIMIT ?';
        $params[] = $limit;

        $rows = $this->db->fetchAll($sql, $params);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'card_id'                    => (int) $row['card_id'],
                'card_title'                 => $row['card_title'],
                'card_description_excerpt'   => $this->buildExcerpt($row['description'] ?? '', $query),
                'board_id'                   => (int) $row['board_id'],
                'board_title'                => $row['board_title'],
                'lane_id'                    => (int) $row['lane_id'],
                'lane_title'                 => $row['lane_title'],
                'is_archived'                => (bool) $row['is_archived'],
                'board_is_archived'          => (bool) $row['board_is_archived'],
            ];
        }

        return [
            'results' => $results,
            'total'   => count($results),
        ];
    }

    /**
     * Builds a MySQL boolean mode search term from user input.
     *
     * Splits input into words and prefixes each with '+' for AND logic.
     * Appends '*' for prefix matching on each word.
     *
     * @param string $query Raw user query
     * @return string Boolean mode search term
     */
    private function buildSearchTerm(string $query): string
    {
        // Remove special boolean mode characters to prevent injection
        $cleaned = preg_replace('/[+\-><()~*"@]/', ' ', $query);
        $words = preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return '';
        }

        // Each word required (+) with prefix matching (*)
        $terms = array_map(function (string $word): string {
            return '+' . $word . '*';
        }, $words);

        return implode(' ', $terms);
    }

    /**
     * Builds an excerpt around the first occurrence of a search term.
     *
     * Returns approximately 200 characters of context around the match.
     *
     * @param string $text  Full text to excerpt
     * @param string $query Search query for highlighting context
     * @return string Excerpt with ellipsis if truncated
     */
    private function buildExcerpt(string $text, string $query): string
    {
        if ($text === '') {
            return '';
        }

        $maxLength = 200;
        $text = strip_tags($text);

        // Find the first word of the query in the text
        $firstWord = preg_split('/\s+/', trim($query))[0] ?? '';
        $position = mb_stripos($text, $firstWord, 0, 'UTF-8');

        if ($position === false) {
            // Query not found in description; return start of text
            if (mb_strlen($text, 'UTF-8') <= $maxLength) {
                return $text;
            }
            return mb_substr($text, 0, $maxLength, 'UTF-8') . '...';
        }

        // Center the excerpt around the match position
        $contextChars = (int) floor($maxLength / 2);
        $start = max(0, $position - $contextChars);
        $excerpt = mb_substr($text, $start, $maxLength, 'UTF-8');

        $prefix = $start > 0 ? '...' : '';
        $suffix = ($start + $maxLength) < mb_strlen($text, 'UTF-8') ? '...' : '';

        return $prefix . $excerpt . $suffix;
    }
}
