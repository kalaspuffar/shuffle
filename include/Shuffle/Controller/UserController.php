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
     * Returns a single user. Admin can view any user; others can view themselves.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function show(Request $request, Response $response, array $params): void
    {
        $currentUser = $this->auth->requireAuth();
        $id = (int) ($params['id'] ?? 0);

        // Non-admins can only view themselves
        if ($currentUser['role'] !== 'admin' && $currentUser['id'] != $id) {
            $response->error('Access denied', 403);
            return;
        }

        $user = $this->userService->getUser($id);

        if ($user === null) {
            $response->error('User not found', 404);
            return;
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

        try {
            $user = $this->userService->updateMe((int) $currentUser['id'], $request->getBody());
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
