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
     * Lists all organizations.
     *
     * @return array Array of organization records
     */
    public function listOrganizations(): array
    {
        $orgs = $this->orgModel->findAll();

        // Attach member count to each organization
        foreach ($orgs as &$org) {
            $org['member_count'] = $this->orgModel->getMemberCount((int) $org['id']);
        }
        unset($org);

        return $orgs;
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

        $id = $this->orgModel->create([
            'name' => trim($data['name']),
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

        $this->orgModel->update($id, [
            'name' => trim($data['name']),
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

        if (strlen(trim($data['name'])) > 128) {
            throw new \InvalidArgumentException('Organization name must be no more than 128 characters');
        }
    }
}
