<?php

namespace app\controllers;

use app\services\RbacService;
use Yii;

class GroupsController extends ApiController
{
    private function service(): RbacService
    {
        return new RbacService();
    }

    public function actionIndex(): array
    {
        $auth = $this->requirePermission('groups', 'read');
        return is_array($auth) ? $auth : $this->service()->listGroups();
    }

    public function actionCreate(): array
    {
        $auth = $this->requirePermission('groups', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->service()->createGroup($this->body(['code', 'name', 'description']));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdate(string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }

        $result = $this->service()->updateGroup($groupID, $this->body(['name', 'description']));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDelete(string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'delete');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }

        return $this->service()->deleteGroup($groupID) ? ['status' => 'deleted'] : $this->fail(404, 'group not found');
    }

    public function actionListRoles(string $groupID): array
    {
        $auth = $this->requirePermission('roles', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }

        return $this->service()->listGroupRoles($groupID);
    }

    public function actionAssignRole(string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }

        $roleId = (string)($this->body(['role_id'])['role_id'] ?? '');
        if ($roleId !== '' && !$this->isUuid($roleId)) {
            return $this->fail(400, 'invalid json body');
        }

        $this->service()->assignRoleToGroup($groupID, $roleId);
        return ['status' => 'assigned'];
    }

    public function actionRemoveRole(string $groupID, string $roleID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }
        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }

        $this->service()->removeRoleFromGroup($groupID, $roleID);
        return ['status' => 'removed'];
    }

    public function actionListUserGroups(string $userID): array
    {
        $auth = $this->requirePermission('groups', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }

        return $this->service()->listUserGroups($userID);
    }

    public function actionAssignUserGroup(string $userID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }

        $groupId = (string)($this->body(['group_id'])['group_id'] ?? '');
        if ($groupId !== '' && !$this->isUuid($groupId)) {
            return $this->fail(400, 'invalid json body');
        }

        $this->service()->assignGroupToUser($userID, $groupId);
        return ['status' => 'assigned'];
    }

    public function actionRemoveUserGroup(string $userID, string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }
        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }

        $this->service()->removeGroupFromUser($userID, $groupID);
        return ['status' => 'removed'];
    }
}
