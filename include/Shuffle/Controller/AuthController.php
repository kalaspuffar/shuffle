<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Csrf;
use Shuffle\Core\Request;
use Shuffle\Core\Response;

/**
 * Authentication API controller.
 *
 * Handles login, logout, and session status endpoints.
 */
class AuthController
{
    private Auth $auth;
    private Csrf $csrf;

    /**
     * @param Auth $auth Auth service
     * @param Csrf $csrf CSRF manager
     */
    public function __construct(Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
    }

    /**
     * POST /v1/auth/login
     *
     * Authenticates a user and creates a session.
     *
     * TODO: Implement rate limiting per spec Section 6.8 — 10 failed attempts
     *       per IP per 15-minute window, returning 429 Too Many Requests.
     *       Use the `login_attempts` table approach described in the specification.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function login(Request $request, Response $response): void
    {
        $body = $request->getBody();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        if ($username === '' || $password === '') {
            $response->error('Username and password are required', 400);
            return;
        }

        $user = $this->auth->login($username, $password);

        if ($user === null) {
            $response->error('Invalid credentials', 401);
            return;
        }

        // Regenerate CSRF token after login to prevent token fixation
        $csrfToken = $this->csrf->regenerate();

        $response->json([
            'user'       => $user,
            'csrf_token' => $csrfToken,
        ]);
    }

    /**
     * POST /v1/auth/logout
     *
     * Destroys the current session.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function logout(Request $request, Response $response): void
    {
        $this->auth->logout();
        $response->noContent();
    }

    /**
     * GET /v1/auth/session
     *
     * Returns the current session state (user and CSRF token).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function session(Request $request, Response $response): void
    {
        $user = $this->auth->currentUser();

        if ($user === null) {
            $response->error('Not authenticated', 401);
            return;
        }

        $response->json([
            'user'       => $user,
            'csrf_token' => $this->csrf->getToken(),
        ]);
    }
}
