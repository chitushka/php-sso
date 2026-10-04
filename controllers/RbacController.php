<?php

namespace app\controllers;

use app\services\RbacService;
use Yii;

class RbacController extends ApiController
{
    private function service(): RbacService
    {
        return new RbacService();
    }

    public function actionListRoles(): array
    {
        $auth = $this->requirePermission('roles', 'read');
        return is_array($auth) ? $auth : $this->service()->listRoles();
    }

    public function actionCreateRole(): array
    {
        $auth = $this->requirePermission('roles', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $fields = ['code', 'name', 'description'];
        $result = $this->service()->createRole($this->body($fields, array_fill_keys($fields, 'string')));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdateRole(string $roleID): array
    {
        $auth = $this->requirePermission('roles', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);

        $fields = ['name', 'description'];
        $result = $this->service()->updateRole($roleID, $this->body($fields, array_fill_keys($fields, 'string')));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDeleteRole(string $roleID): array
    {
        $auth = $this->requirePermission('roles', 'delete');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);

        $result = $this->service()->deleteRole($roleID);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionListPermissions(): array
    {
        $auth = $this->requirePermission('permissions', 'read');
        return is_array($auth) ? $auth : $this->service()->listPermissions();
    }

    public function actionListRolePermissions(string $roleID): array
    {
        $auth = $this->requirePermission('permissions', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);

        return $this->service()->listRolePermissions($roleID);
    }

    public function actionAssignPermission(string $roleID): array
    {
        $auth = $this->requirePermission('permissions', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);

        $permissionId = (string)($this->body(['permission_id'], ['permission_id' => 'uuid'])['permission_id'] ?? '');

        $this->service()->assignPermissionToRole($roleID, $permissionId);
        return ['status' => 'assigned'];
    }

    public function actionRemovePermission(string $roleID, string $permissionID): array
    {
        $auth = $this->requirePermission('permissions', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);
        if (!$this->isUuid($permissionID)) {
            return $this->fail(400, 'invalid permission id');
        }
        $permissionID = $this->canonicalUuid($permissionID);

        return $this->service()->removePermissionFromRole($roleID, $permissionID)
            ? ['status' => 'removed']
            : $this->fail(409, 'built-in admin permissions cannot be removed');
    }

    public function actionListUserRoles(string $userID): array
    {
        $auth = $this->requirePermission('roles', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }
        $userID = $this->canonicalUuid($userID);

        return $this->service()->listUserRoles($userID);
    }

    public function actionAssignUserRole(string $userID): array
    {
        $auth = $this->requirePermission('roles', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }
        $userID = $this->canonicalUuid($userID);

        $roleId = (string)($this->body(['role_id'], ['role_id' => 'uuid'])['role_id'] ?? '');

        $this->service()->assignRoleToUser($userID, $roleId);
        return ['status' => 'assigned'];
    }

    public function actionRemoveUserRole(string $userID, string $roleID): array
    {
        $auth = $this->requirePermission('roles', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }
        $userID = $this->canonicalUuid($userID);
        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);

        return $this->service()->removeRoleFromUser($userID, $roleID)
            ? ['status' => 'removed']
            : $this->fail(409, 'at least one active administrator is required');
    }
}
