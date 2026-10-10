<?php

namespace app\services;

final class GroupService
{
    private RbacService $rbac;

    public function __construct(?RbacService $rbac = null)
    {
        $this->rbac = $rbac ?? new RbacService();
    }

    public function list(array $options = []): array
    {
        return $this->rbac->listGroups($options);
    }

    public function create(array $body): array
    {
        return $this->rbac->createGroup($body);
    }

    public function update(string $id, array $body): array
    {
        return $this->rbac->updateGroup($id, $body);
    }

    public function delete(string $id): array
    {
        return $this->rbac->deleteGroup($id);
    }

    public function listRoles(string $groupId): array
    {
        return $this->rbac->listGroupRoles($groupId);
    }

    public function assignRole(string $groupId, string $roleId): void
    {
        $this->rbac->assignRoleToGroup($groupId, $roleId);
    }

    public function removeRole(string $groupId, string $roleId): bool
    {
        return $this->rbac->removeRoleFromGroup($groupId, $roleId);
    }

    public function listForUser(string $userId): array
    {
        return $this->rbac->listUserGroups($userId);
    }

    public function assignToUser(string $userId, string $groupId): void
    {
        $this->rbac->assignGroupToUser($userId, $groupId);
    }

    public function removeFromUser(string $userId, string $groupId): bool
    {
        return $this->rbac->removeGroupFromUser($userId, $groupId);
    }
}
