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

        $fields = ['code', 'name', 'description'];
        $result = $this->service()->createGroup($this->body($fields, array_fill_keys($fields, 'string')));
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
        $groupID = $this->canonicalUuid($groupID);

        $fields = ['name', 'description'];
        $result = $this->service()->updateGroup($groupID, $this->body($fields, array_fill_keys($fields, 'string')));
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
        $groupID = $this->canonicalUuid($groupID);

        $result = $this->service()->deleteGroup($groupID);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
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
        $groupID = $this->canonicalUuid($groupID);

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
        $groupID = $this->canonicalUuid($groupID);

        $roleId = (string)($this->body(['role_id'], ['role_id' => 'uuid'])['role_id'] ?? '');

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
        $groupID = $this->canonicalUuid($groupID);
        if (!$this->isUuid($roleID)) {
            return $this->fail(400, 'invalid role id');
        }
        $roleID = $this->canonicalUuid($roleID);

        return $this->service()->removeRoleFromGroup($groupID, $roleID)
            ? ['status' => 'removed']
            : $this->fail(409, 'at least one active administrator is required');
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
        $userID = $this->canonicalUuid($userID);

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
        $userID = $this->canonicalUuid($userID);

        $groupId = (string)($this->body(['group_id'], ['group_id' => 'uuid'])['group_id'] ?? '');

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
        $userID = $this->canonicalUuid($userID);
        if (!$this->isUuid($groupID)) {
            return $this->fail(400, 'invalid group id');
        }
        $groupID = $this->canonicalUuid($groupID);

        return $this->service()->removeGroupFromUser($userID, $groupID)
            ? ['status' => 'removed']
            : $this->fail(409, 'at least one active administrator is required');
    }
}
