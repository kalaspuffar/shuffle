<?php
namespace Shuffle\Core;

use SessionHandlerInterface;

/**
 * MySQL-backed session handler.
 *
 * Implements SessionHandlerInterface for custom session storage in the
 * sessions table. Supports admin session revocation via destroyByUserId().
 */
class Session implements SessionHandlerInterface
{
    private Database $db;
    private int $lifetime;

    /**
     * @param Database $db       Database instance
     * @param array    $config   Session configuration with keys: lifetime, cookie_name
     */
    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->lifetime = $config['lifetime'] ?? 86400;

        // Register this handler before starting the session
        session_set_save_handler($this, true);

        // Configure session cookie parameters
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $cookieName = $config['cookie_name'] ?? 'shuffle_session';

        session_name($cookieName);
        session_set_cookie_params([
            'lifetime' => $this->lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Open callback — no action needed (DB already connected).
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Close callback — no action needed.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Read session data from the sessions table.
     *
     * @param string $id Session ID
     * @return string    Serialized session data, or empty string
     */
    public function read(string $id): string
    {
        $row = $this->db->fetch(
            'SELECT data FROM sessions WHERE id = ? AND last_activity >= ?',
            [$id, date('Y-m-d H:i:s', time() - $this->lifetime)]
        );
        return $row !== null ? $row['data'] : '';
    }

    /**
     * Write session data to the sessions table.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior.
     *
     * @param string $id   Session ID
     * @param string $data Serialized session data
     * @return bool
     */
    public function write(string $id, string $data): bool
    {
        $now = date('Y-m-d H:i:s');

        // Extract user_id from session data if available
        $userId = $_SESSION['user_id'] ?? null;

        $this->db->execute(
            'INSERT INTO sessions (id, user_id, data, last_activity, created_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                data = VALUES(data),
                last_activity = VALUES(last_activity)',
            [$id, $userId, $data, $now, $now]
        );
        return true;
    }

    /**
     * Destroy a session by its ID.
     *
     * @param string $id Session ID
     * @return bool
     */
    public function destroy(string $id): bool
    {
        $this->db->execute('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    /**
     * Garbage collection: delete sessions older than the configured lifetime.
     *
     * @param int $maxLifetime Maximum session lifetime (from php.ini, overridden by our config)
     * @return int|false       Number of deleted sessions
     */
    public function gc(int $maxLifetime): int|false
    {
        $expiry = date('Y-m-d H:i:s', time() - $this->lifetime);
        return $this->db->execute('DELETE FROM sessions WHERE last_activity < ?', [$expiry]);
    }

    /**
     * Destroy all sessions for a specific user.
     *
     * Used by admin for session revocation (e.g., deactivating a user).
     *
     * @param int $userId User ID whose sessions should be destroyed
     * @return int        Number of sessions destroyed
     */
    public function destroyByUserId(int $userId): int
    {
        return $this->db->execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }
}
