<?php

namespace app\services;

final class RoleService
{
    private RbacService $rbac;

    public function __construct(?RbacService $rbac = null)
    {
        $this->rbac = $rbac ?? new RbacService();
    }

    public function list(): array
    {
        return $this->rbac->listRoles();
    }

    public function create(array $body): array
    {
        return $this->rbac->createRole($body);
    }

    public function update(string $id, array $body): array
    {
        return $this->rbac->updateRole($id, $body);
    }

    public function delete(string $id): array
    {
        return $this->rbac->deleteRole($id);
    }

    public function listPermissions(): array
    {
        return $this->rbac->listPermissions();
    }

    public function listPermissionsForRole(string $roleId): array
    {
        return $this->rbac->listRolePermissions($roleId);
    }

    public function assignPermission(string $roleId, string $permissionId): void
    {
        $this->rbac->assignPermissionToRole($roleId, $permissionId);
    }

    public function removePermission(string $roleId, string $permissionId): bool
    {
        return $this->rbac->removePermissionFromRole($roleId, $permissionId);
    }

    public function listForUser(string $userId): array
    {
        return $this->rbac->listUserRoles($userId);
    }

    public function assignToUser(string $userId, string $roleId): void
    {
        $this->rbac->assignRoleToUser($userId, $roleId);
    }

    public function removeFromUser(string $userId, string $roleId): bool
    {
        return $this->rbac->removeRoleFromUser($userId, $roleId);
    }
}
