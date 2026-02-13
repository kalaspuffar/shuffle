<?php
namespace Shuffle\Core;

/**
 * CSRF token management.
 *
 * Generates, validates, and renders CSRF tokens stored in the PHP session.
 * Tokens use 128-bit random values (32 hex characters).
 */
class Csrf
{
    /** Session key where the CSRF token is stored */
    private const SESSION_KEY = 'csrf_token';

    /**
     * Generates a new CSRF token if one does not already exist.
     *
     * @return string The current CSRF token
     */
    public function generate(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Regenerates the CSRF token, replacing any existing token.
     *
     * Should be called after privilege-level changes (e.g., login) to prevent
     * session fixation attacks from carrying over pre-authentication CSRF tokens.
     *
     * @return string The new CSRF token
     */
    public function regenerate(): string
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Validates a submitted token against the session token.
     *
     * Uses hash_equals() for timing-safe comparison to prevent timing attacks.
     *
     * @param string $token The submitted CSRF token
     * @return bool True if valid
     */
    public function validate(string $token): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        if ($sessionToken === '' || $token === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }

    /**
     * Returns an HTML hidden input containing the current CSRF token.
     *
     * @return string HTML <input> element
     */
    public function getTokenField(): string
    {
        $token = htmlspecialchars($this->generate(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    /**
     * Returns the current token value (generating one if needed).
     *
     * @return string The CSRF token
     */
    public function getToken(): string
    {
        return $this->generate();
    }
}
