<?php
namespace Shuffle\Controller;

use Shuffle\Core\Auth;
use Shuffle\Core\Request;
use Shuffle\Core\Response;
use Shuffle\Service\OrganizationService;

/**
 * Organization management API controller.
 *
 * Handles CRUD operations and member listing. All endpoints are admin-only.
 */
class OrganizationController
{
    private Auth $auth;
    private OrganizationService $orgService;

    /**
     * @param Auth                $auth       Auth service
     * @param OrganizationService $orgService Organization business logic service
     */
    public function __construct(Auth $auth, OrganizationService $orgService)
    {
        $this->auth = $auth;
        $this->orgService = $orgService;
    }

    /**
     * GET /v1/organizations
     *
     * Lists all organizations. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function index(Request $request, Response $response): void
    {
        $this->auth->requireRole('admin');

        $organizations = $this->orgService->listOrganizations();

        $response->json(['organizations' => $organizations]);
    }

    /**
     * GET /v1/organizations/{id}
     *
     * Returns a single organization. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function show(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $org = $this->orgService->getOrganization($id);

        if ($org === null) {
            $response->error('Organization not found', 404);
            return;
        }

        $response->json(['organization' => $org]);
    }

    /**
     * POST /v1/organizations
     *
     * Creates a new organization. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     */
    public function create(Request $request, Response $response): void
    {
        $this->auth->requireRole('admin');

        $body = $request->getBody();

        try {
            $org = $this->orgService->createOrganization($body);
            $response->json(['organization' => $org], 201);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /v1/organizations/{id}
     *
     * Updates an organization. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function update(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        $body = $request->getBody();

        try {
            $org = $this->orgService->updateOrganization($id, $body);
            $response->json(['organization' => $org]);
        } catch (\InvalidArgumentException $e) {
            $response->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $status = ($e->getMessage() === 'Organization not found') ? 404 : 400;
            $response->error($e->getMessage(), $status);
        }
    }

    /**
     * DELETE /v1/organizations/{id}
     *
     * Deletes an organization. Admin only. Returns 409 if members exist.
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
            $this->orgService->deleteOrganization($id);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if ($message === 'Organization not found') {
                $response->error($message, 404);
            } else {
                // "Cannot delete organization with active members"
                $response->error($message, 409);
            }
        }
    }

    /**
     * GET /v1/organizations/{id}/members
     *
     * Returns the members of an organization. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function members(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $id = (int) ($params['id'] ?? 0);

        try {
            $members = $this->orgService->getOrganizationMembers($id);
            $response->json(['members' => $members]);
        } catch (\RuntimeException $e) {
            $response->error($e->getMessage(), 404);
        }
    }

    /**
     * POST /v1/organizations/{id}/members
     *
     * Assigns a user to this organization. Admin only.
     * Body: { "user_id": <int> }
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters
     */
    public function addMember(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $orgId  = (int) ($params['id'] ?? 0);
        $body   = $request->getBody();
        $userId = (int) ($body['user_id'] ?? 0);

        if ($userId <= 0) {
            $response->error('user_id is required', 400);
            return;
        }

        try {
            $this->orgService->addMember($orgId, $userId);
            $members = $this->orgService->getOrganizationMembers($orgId);
            $response->json(['members' => $members]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $response->error($e->getMessage(), $status);
        }
    }

    /**
     * DELETE /v1/organizations/{id}/members/{userId}
     *
     * Removes a user from this organization. Admin only.
     *
     * @param Request  $request  HTTP request
     * @param Response $response HTTP response
     * @param array    $params   Route parameters: id, userId
     */
    public function removeMember(Request $request, Response $response, array $params): void
    {
        $this->auth->requireRole('admin');
        $orgId  = (int) ($params['id'] ?? 0);
        $userId = (int) ($params['userId'] ?? 0);

        try {
            $this->orgService->removeMember($orgId, $userId);
            $response->noContent();
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $response->error($e->getMessage(), $status);
        }
    }
}
