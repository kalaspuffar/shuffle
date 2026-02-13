<?php
namespace Shuffle\Core;

/**
 * Authentication and authorization service.
 *
 * Handles login/logout, session-based user retrieval, role checks,
 * and board-level access control.
 */
class Auth
{
    private Database $db;
    private Session $session;

    /** Role hierarchy: higher index = more privileges */
    private const ROLE_HIERARCHY = [
        'viewer' => 0,
        'member' => 1,
        'admin'  => 2,
    ];

    /**
     * @param Database $db      Database instance
     * @param Session  $session Session instance
     */
    public function __construct(Database $db, Session $session)
    {
        $this->db = $db;
        $this->session = $session;
    }

    /**
     * Returns the currently authenticated user, or null if not logged in.
     *
     * @return array|null User row (without password_hash) or null
     */
    public function currentUser(): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }

        $user = $this->db->fetch(
            'SELECT id, username, name, email, role, organization_id, status
             FROM users WHERE id = ? AND status = ?',
            [(int) $userId, 'active']
        );

        return $user ?: null;
    }

    /**
     * Authenticates a user by username and password.
     *
     * On success: regenerates session ID, stores user_id in session, returns user.
     * On failure: returns null.
     *
     * @param string $username The username
     * @param string $password The plaintext password
     * @return array|null User row on success, null on failure
     */
    public function login(string $username, string $password): ?array
    {
        $user = $this->db->fetch(
            'SELECT id, username, password_hash, name, email, role, organization_id, status
             FROM users WHERE username = ?',
            [$username]
        );

        if ($user === null) {
            // Run password_verify against a dummy hash to prevent timing attacks
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$dW5rbm93bg$dW5rbm93bg');
            return null;
        }

        if ($user['status'] !== 'active') {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];

        // Return user without password_hash
        unset($user['password_hash']);
        return $user;
    }

    /**
     * Logs out the current user by destroying the session.
     */
    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly'  => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
    }

    /**
     * Requires authentication. Returns the current user or halts execution.
     *
     * For API requests (Accept: application/json), sends a 401 JSON response.
     * For web requests, redirects to the login page.
     *
     * @return array The authenticated user
     */
    public function requireAuth(): array
    {
        $user = $this->currentUser();
        if ($user !== null) {
            return $user;
        }

        if ($this->isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }

        header('Location: /login.php');
        exit;
    }

    /**
     * Requires a minimum role level. Returns user or halts execution.
     *
     * @param string $role Required minimum role (viewer, member, admin)
     * @return array The authenticated user
     */
    public function requireRole(string $role): array
    {
        $user = $this->requireAuth();

        $requiredLevel = self::ROLE_HIERARCHY[$role] ?? 0;
        $userLevel = self::ROLE_HIERARCHY[$user['role']] ?? 0;

        if ($userLevel < $requiredLevel) {
            if ($this->isApiRequest()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Access denied']);
                exit;
            }

            http_response_code(403);
            echo 'Access denied.';
            exit;
        }

        return $user;
    }

    /**
     * Checks whether the current user can access a specific board.
     *
     * Access is granted if:
     * - The board is private and the user created it, OR
     * - The board is organization-visible and the user belongs to that org
     *
     * @param int $boardId Board ID to check
     * @return bool True if the current user can access the board
     */
    public function canAccessBoard(int $boardId): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }

        // Admins can access all boards
        if ($user['role'] === 'admin') {
            return true;
        }

        $row = $this->db->fetch(
            'SELECT b.id FROM boards b
             LEFT JOIN board_organizations bo ON b.id = bo.board_id
             WHERE b.id = ?
               AND (
                 (b.visibility = ? AND b.created_by = ?)
                 OR (b.visibility = ? AND bo.organization_id = ?)
               )',
            [$boardId, 'private', $user['id'], 'organization', $user['organization_id']]
        );

        return $row !== null;
    }

    /**
     * Checks if the current user is an admin.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        $user = $this->currentUser();
        return $user !== null && $user['role'] === 'admin';
    }

    /**
     * Checks if the current user is at least a member (member or admin).
     *
     * @return bool
     */
    public function isMember(): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }
        $level = self::ROLE_HIERARCHY[$user['role']] ?? 0;
        return $level >= self::ROLE_HIERARCHY['member'];
    }

    /**
     * Determines if the current request is an API request.
     *
     * @return bool
     */
    private function isApiRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || str_starts_with($uri, '/v1/');
    }
}
