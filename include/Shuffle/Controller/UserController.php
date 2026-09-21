<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Mailer;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Model\User;
use Shuffle\Service\UserService;

/**
 * User management API controller.
 *
 * Handles CRUD operations, invitation, and activation endpoints.
 */
class UserController
{
    private Auth $auth;
    private UserService $userService;
    private Mailer $mailer;
    private string $appUrl;

    /**
     * @param Auth        $auth        Auth service
     * @param UserService $userService User business logic service
     * @param Mailer      $mailer      SMTP mailer
     * @param string      $appUrl      Application base URL
     */
    public function __construct(Auth $auth, UserService $userService, Mailer $mailer, string $appUrl)
    {
        $this->auth = $auth;
        $this->userService = $userService;
        $this->mailer = $mailer;
        $this->appUrl = $appUrl;
    }

    /**
     * GET /v1/users
     *
     * Lists all users. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function index(Request $request, Response $response): void
    {
        $this->auth->requireRole('admin');

        $filters = [];
        $status = $request->getQuery('status');
        if ($status !== null) {
            $filters['status'] = $status;
        }
        $orgId = $request->getQuery('organization_id');
        if ($orgId !== null) {
            $filters['organization_id'] = (int) $orgId;
        }

        $users = $this->userService->listUsers($filters);

        $response->json(['users' => $users]);
    }

    /**
     * GET /v1/users/{id}
     *
     * Returns a single user. Visibility (USER-01, v1.16 §5.24):
     *   - admin → any user, full row (incl. email)
     *   - self  → own row, full row (incl. email)
     *   - same-org non-admin → row with `email = null`
     *                        (phone/location/bio visible — org-scoped)
     *   - anything else (cross-org, NULL org, unknown id) → 404
     * The 404 (never a 403) for cross-org users is the BOARD-04b / no-
     * enumeration convention: a non-visible user is indistinguishable
     * from a non-existent one.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function show(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        // Admin and self: full visibility (unchanged §5.22 contract).
        $selfOrAdmin = $currentUser['role'] === 'admin' || $currentUser['id'] == $id;

        // Same-org visibility must compare the TARGET row's org — decide
        // after the lookup. Cross-org / NULL-org then falls through to the
        // 404 below (no existence leak: unknown id and cross-org user are
        // indistinguishable — BOARD-04b convention).
        $user = $this->userService->getUser($id);

        if ($user === null) {
            $response->error('User not found', 404);
            return;
        }

        if (!$selfOrAdmin) {
            $viewerOrg = $currentUser['organization_id'] ?? null;
            $targetOrg = $user['organization_id'] ?? null;

            if ($viewerOrg === null || $targetOrg === null || (int) $viewerOrg !== (int) $targetOrg) {
                // Cross-org (or either org NULL): non-visible → 404, never
                // a 403 (no user-id enumeration).
                $response->error('User not found', 404);
                return;
            }

            // Same-org non-admin: email is the identity anchor (AUTH-04) —
            // the §5.22 contract keeps it admin/self-only. Null it (not
            // unset) so the key stays present in the JSON shape.
            $user['email'] = null;
            // v1.18 NOTIF-06: email_notifications is the user's own
            // preference — admin/self-only, the same privacy class as email.
            $user['email_notifications'] = null;
        }

        $response->json(['user' => $user]);
    }

    /**
     * POST /v1/users/invite
     *
     * Invites a new user via email. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function invite(Request $request, Response $response): void
    {
        $this->auth->requireRole('admin');

        $body = $request->getBody();

        try {
            $user = $this->userService->invite($body, $this->mailer, $this->appUrl);
            $response->json([
                'user'    => $user,
                'message' => 'Invitation sent',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 409);
        }
    }

    /**
     * POST /v1/users/activate
     *
     * Activates an invited user. No authentication required — uses invite token.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function activate(Request $request, Response $response): void
    {
        $body = $request->getBody();
        $token = $body['token'] ?? '';
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';

        try {
            $user = $this->userService->activateUser($token, $username, $password);
            $response->json(['user' => $user]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/users/{id}
     *
     * Updates a user. Admins can update any user; non-admins only themselves.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        $body = $request->getBody();

        try {
            $user = $this->userService->updateUser($id, $body, $currentUser);
            $response->json(['user' => $user]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $status = ($message === 'Access denied' || str_starts_with($message, 'Access denied:')) ? 403 : 400;
            if ($message === 'User not found') {
                $status = 404;
            }
            $response->error($message, $status);
        }
    }

    /**
     * DELETE /v1/users/{id}
     *
     * Deletes a user. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function delete(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->userService->deleteUser($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * PUT /v1/me
     *
     * Self-service profile update (USER-02, §5.22). Updates the actor's own
     * `name`, `phone`, `location`, `bio` — only provided fields. Email is
     * rejected (immutable in v1 — identity anchor, AUTH-04).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function updateMe(Request $request, Response $response): void
    {
        $currentUser = $this->auth->requireAuth();

        // THEME-02 (v1.20 §5.27): the ergonomic alias `theme` is accepted in
        // the request body and mapped to the canonical column name `theme_preference`
        // so API clients (and JS helpers) can send either spelling. `theme_preference`
        // is the single source of truth for the server schema; `theme` is a
        // convenience for the UI's `PUT /v1/me` calls.
        // (This mapping is applied at the controller — the service only sees
        // the canonical name — so the admin `PUT /v1/users/{id}` path is
        // unaffected. The service still validates the value regardless of which
        // spelling was used.)
        $body = $request->getBody();
        if (is_array($body) && array_key_exists('theme', $body) && !array_key_exists('theme_preference', $body)) {
            $body['theme_preference'] = $body['theme'];
            unset($body['theme']);
        }

        try {
            $user = $this->userService->updateMe((int) $currentUser['id'], $body);
            $response->json(['user' => $user]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/me/password
     *
     * Change one's own password (USER-02, §5.22). Requires the current
     * password for identity confirmation. 403 (not 400) when the current
     * password is wrong so the client can surface "wrong current password"
     * distinctly from a malformed request.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function changeMyPassword(Request $request, Response $response): void
    {
        $currentUser = $this->auth->requireAuth();

        try {
            $this->userService->changeMyPassword((int) $currentUser['id'], $request->getBody());
            $response->json(['message' => 'Password changed']);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $status = ($message === 'Current password is incorrect') ? 403 : 404;
            $response->error($message, $status);
        }
    }

    /**
     * POST /v1/admin/users/{id}/reset-password
     *
     * Admin reset of any user's password (USER-03, §5.22). The admin does not
     * need the user's current password. 404 for an unknown user id; 400 for a
     * malformed password; 204 on success (no body — the new password must not
     * be echoed back).
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function resetPassword(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->userService->adminResetPassword((int) $currentUser['id'], $id, $request->getBody());
            $response->noContent();
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if (str_starts_with($message, 'Access denied')) {
                $status = 403;
            } elseif ($message === 'User not found') {
                $status = 404;
            } else {
                $status = 400;
            }
            $response->error($message, $status);
        }
    }
}
