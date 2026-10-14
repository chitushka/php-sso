<?php

namespace app\services;

use app\models\User;

final class AuthorizationService
{
    private const MACHINE_PERMISSIONS = [
        'users:read',
        'roles:read',
        'permissions:read',
        'groups:read',
        'oauth_clients:read',
        'identity_providers:read',
        'ldap:read',
        'ldap:test',
        'audit:read',
        'federated_identities:read',
        'operations:read',
        'signing_keys:read',
        'signing_keys:rotate',
    ];

    public function machineClientAllows(string $resource, string $action): bool
    {
        return in_array($resource . ':' . $action, self::MACHINE_PERMISSIONS, true);
    }

    public function userHasPermission(User $user, string $resource, string $action): bool
    {
        return (bool)ApplicationRuntime::db()->createCommand(
            'SELECT 1
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id AND p.resource = :resource AND p.action = :action
             UNION
             SELECT 1
             FROM user_groups ug
             JOIN group_roles gr ON gr.group_id = ug.group_id
             JOIN role_permissions rp ON rp.role_id = gr.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ug.user_id = :user_id AND p.resource = :resource AND p.action = :action
             LIMIT 1',
            [
                ':user_id' => $user->id,
                ':resource' => $resource,
                ':action' => $action,
            ]
        )->queryScalar();
    }
}
