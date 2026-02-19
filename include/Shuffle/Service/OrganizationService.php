<?php
namespace Shuffle\Service;

use Shuffle\Model\Organization;

/**
 * Organization business logic service.
 *
 * Handles CRUD operations for organizations with validation
 * and referential integrity checks.
 */
class OrganizationService
{
    private Organization $orgModel;

    /**
     * @param Organization $orgModel Organization data access instance
     */
    public function __construct(Organization $orgModel)
    {
        $this->orgModel = $orgModel;
    }

    /**
     * Lists all organizations with member counts.
     *
     * Uses a single JOIN query to avoid N+1 performance issues.
     *
     * @return array Array of organization records with 'member_count'
     */
    public function listOrganizations(): array
    {
        return $this->orgModel->findAllWithMemberCount();
    }

    /**
     * Retrieves a single organization by ID.
     *
     * @param int $id Organization ID
     * @return array|null Organization row or null if not found
     */
    public function getOrganization(int $id): ?array
    {
        $org = $this->orgModel->findById($id);
        if ($org !== null) {
            $org['member_count'] = $this->orgModel->getMemberCount($id);
        }
        return $org;
    }

    /**
     * Creates a new organization.
     *
     * @param array $data Organization data: name
     * @return array The created organization record
     * @throws \InvalidArgumentException If validation fails
     */
    public function createOrganization(array $data): array
    {
        $this->validateName($data);

        $trimmedName = trim($data['name']);
        $existing = $this->orgModel->findByName($trimmedName);
        if ($existing !== null) {
            throw new \InvalidArgumentException('An organization with this name already exists');
        }

        $id = $this->orgModel->create([
            'name' => $trimmedName,
        ]);

        return $this->orgModel->findById($id);
    }

    /**
     * Updates an existing organization.
     *
     * @param int   $id   Organization ID
     * @param array $data Fields to update
     * @return array The updated organization record
     * @throws \InvalidArgumentException If validation fails
     * @throws \RuntimeException If organization not found
     */
    public function updateOrganization(int $id, array $data): array
    {
        $org = $this->orgModel->findById($id);
        if ($org === null) {
            throw new \RuntimeException('Organization not found');
        }

        $this->validateName($data);

        $trimmedName = trim($data['name']);
        $existing = $this->orgModel->findByName($trimmedName);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            throw new \InvalidArgumentException('An organization with this name already exists');
        }

        $this->orgModel->update($id, [
            'name' => $trimmedName,
        ]);

        return $this->orgModel->findById($id);
    }

    /**
     * Deletes an organization.
     *
     * Fails if the organization still has active members assigned to it.
     *
     * @param int $id Organization ID
     * @throws \RuntimeException If organization not found or has members
     */
    public function deleteOrganization(int $id): void
    {
        $org = $this->orgModel->findById($id);
        if ($org === null) {
            throw new \RuntimeException('Organization not found');
        }

        $memberCount = $this->orgModel->getMemberCount($id);
        if ($memberCount > 0) {
            throw new \RuntimeException('Cannot delete organization with active members');
        }

        $this->orgModel->delete($id);
    }

    /**
     * Returns the members of an organization.
     *
     * @param int $id Organization ID
     * @return array Array of user records
     * @throws \RuntimeException If organization not found
     */
    public function getOrganizationMembers(int $id): array
    {
        $org = $this->orgModel->findById($id);
        if ($org === null) {
            throw new \RuntimeException('Organization not found');
        }

        return $this->orgModel->getMembers($id);
    }

    /**
     * Returns active non-placeholder users not assigned to any organization.
     *
     * @return array Array of user records
     */
    public function getUnassignedUsers(): array
    {
        return $this->orgModel->getUnassignedUsers();
    }

    /**
     * Assigns a user to an organization.
     *
     * Rejects the operation if the user is already a member of a different
     * organization — the caller must remove them first.
     *
     * @param int $orgId  Organization ID
     * @param int $userId User ID
     * @throws \RuntimeException If org or user not found, or user already assigned elsewhere
     */
    public function addMember(int $orgId, int $userId): void
    {
        $org = $this->orgModel->findById($orgId);
        if ($org === null) {
            throw new \RuntimeException('Organization not found');
        }

        // Fetch the user to check current assignment
        $users = $this->orgModel->getUnassignedUsers();
        $userIds = array_column($users, 'id');

        // Also fetch current members to check if already in this org
        $currentMembers = $this->orgModel->getMembers($orgId);
        $memberIds = array_column($currentMembers, 'id');

        if (in_array($userId, $memberIds, true)) {
            // Already a member of this org — no-op is fine, but be explicit
            return;
        }

        if (!in_array($userId, $userIds, true)) {
            // User is not in the unassigned pool, meaning they belong to another org
            throw new \RuntimeException('User is already assigned to an organization');
        }

        $this->orgModel->addMember($orgId, $userId);
    }

    /**
     * Removes a user from an organization.
     *
     * @param int $orgId  Organization ID
     * @param int $userId User ID
     * @throws \RuntimeException If org not found or user is not a member of this org
     */
    public function removeMember(int $orgId, int $userId): void
    {
        $org = $this->orgModel->findById($orgId);
        if ($org === null) {
            throw new \RuntimeException('Organization not found');
        }

        $currentMembers = $this->orgModel->getMembers($orgId);
        $memberIds = array_column($currentMembers, 'id');

        if (!in_array($userId, $memberIds, true)) {
            throw new \RuntimeException('This user is not a member of this organization');
        }

        $this->orgModel->removeMember($orgId, $userId);
    }

    /**
     * Validates the organization name field.
     *
     * @param array $data Input data
     * @throws \InvalidArgumentException If name is missing or too long
     */
    private function validateName(array $data): void
    {
        if (empty($data['name']) || trim($data['name']) === '') {
            throw new \InvalidArgumentException('Organization name is required');
        }

        if (mb_strlen(trim($data['name']), 'UTF-8') > 128) {
            throw new \InvalidArgumentException('Organization name must be no more than 128 characters');
        }
    }
}
