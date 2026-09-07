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

        $result = $this->service()->createGroup($this->body());
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdate(string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->service()->updateGroup($groupID, $this->body());
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDelete(string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'delete');
        if (is_array($auth)) {
            return $auth;
        }

        return $this->service()->deleteGroup($groupID) ? ['status' => 'deleted'] : $this->fail(404, 'group not found');
    }

    public function actionListRoles(string $groupID): array
    {
        $auth = $this->requirePermission('roles', 'read');
        return is_array($auth) ? $auth : $this->service()->listGroupRoles($groupID);
    }

    public function actionAssignRole(string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        $this->service()->assignRoleToGroup($groupID, (string)($this->body()['role_id'] ?? ''));
        return ['status' => 'assigned'];
    }

    public function actionRemoveRole(string $groupID, string $roleID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        $this->service()->removeRoleFromGroup($groupID, $roleID);
        return ['status' => 'removed'];
    }

    public function actionListUserGroups(string $userID): array
    {
        $auth = $this->requirePermission('groups', 'read');
        return is_array($auth) ? $auth : $this->service()->listUserGroups($userID);
    }

    public function actionAssignUserGroup(string $userID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        $this->service()->assignGroupToUser($userID, (string)($this->body()['group_id'] ?? ''));
        return ['status' => 'assigned'];
    }

    public function actionRemoveUserGroup(string $userID, string $groupID): array
    {
        $auth = $this->requirePermission('groups', 'assign');
        if (is_array($auth)) {
            return $auth;
        }

        $this->service()->removeGroupFromUser($userID, $groupID);
        return ['status' => 'removed'];
    }
}
