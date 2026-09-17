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

        $result = $this->service()->createRole($this->body());
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

        $result = $this->service()->updateRole($roleID, $this->body());
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

        $permissionId = (string)($this->body()['permission_id'] ?? '');
        if ($permissionId !== '' && !$this->isUuid($permissionId)) {
            return $this->fail(400, 'invalid json body');
        }

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
        if (!$this->isUuid($permissionID)) {
            return $this->fail(400, 'invalid permission id');
        }

        $this->service()->removePermissionFromRole($roleID, $permissionID);
        return ['status' => 'removed'];
    }

    public function actionListUserRoles(string $userID): array
    {
        $auth = $this->requirePermission('roles', 'read');
        return is_array($auth) ? $auth : $this->service()->listUserRoles($userID);
    }

    public function actionAssignUserRole(string $userID): array
    {
        $auth = $this->requirePermission('roles', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        $this->service()->assignRoleToUser($userID, (string)($this->body()['role_id'] ?? ''));
        return ['status' => 'assigned'];
    }

    public function actionRemoveUserRole(string $userID, string $roleID): array
    {
        $auth = $this->requirePermission('roles', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        $this->service()->removeRoleFromUser($userID, $roleID);
        return ['status' => 'removed'];
    }
}
