<?php

namespace app\services;

use app\models\User;

final class AuthorizationService
{
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
