<?php

namespace app\services;

use app\models\Group;
use app\models\Permission;
use app\models\Role;
use app\models\User;
use Yii;
use yii\db\Expression;
use yii\db\IntegrityException;

class RbacService
{
    public function userHasPermission(User $user, string $resource, string $action): bool
    {
        $exists = Yii::$app->db->createCommand(
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

        return (bool)$exists;
    }

    public function listRoles(): array
    {
        return array_map(static function (Role $role): array {
            return $role->publicData();
        }, Role::find()->orderBy(['code' => SORT_ASC])->all());
    }

    public function createRole(array $body): array
    {
        $role = new Role();
        $role->code = trim((string)($body['code'] ?? ''));
        $role->name = trim((string)($body['name'] ?? ''));
        $role->description = (string)($body['description'] ?? '');

        if ($role->code === '' || $role->name === '') {
            return ['status' => 400, 'data' => ['error' => 'role code and name are required']];
        }

        if (Role::find()->where(['code' => $role->code])->exists()) {
            return ['status' => 409, 'data' => ['error' => 'role already exists']];
        }

        try {
            if (!$role->save()) {
                return ['status' => 400, 'data' => ['error' => 'invalid role payload', 'details' => $role->errors]];
            }
        } catch (IntegrityException $exception) {
            return ['status' => 409, 'data' => ['error' => 'role already exists']];
        }

        if ($role->hasErrors()) {
            return ['status' => 400, 'data' => ['error' => 'invalid role payload', 'details' => $role->errors]];
        }

        $role->refresh();
        AuditService::record(null, 'role_created', 'role', $role->id);
        return ['status' => 201, 'data' => $role->publicData()];
    }

    public function updateRole(string $id, array $body): array
    {
        $role = Role::findOne(['id' => $id]);
        if (!$role) {
            return ['status' => 404, 'data' => ['error' => 'role not found']];
        }

        $role->name = trim((string)($body['name'] ?? ''));
        $role->description = (string)($body['description'] ?? '');

        if ($role->name === '') {
            return ['status' => 400, 'data' => ['error' => 'role name is required']];
        }

        $role->updated_at = new Expression('now()');
        if (!$role->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid role payload', 'details' => $role->errors]];
        }

        $role->refresh();
        AuditService::record(null, 'role_updated', 'role', $role->id);
        return ['status' => 200, 'data' => $role->publicData()];
    }

    public function deleteRole(string $id): array
    {
        $role = Role::findOne(['id' => $id]);
        if (!$role) {
            return ['status' => 404, 'data' => ['error' => 'role not found']];
        }

        if (in_array($role->code, ['admin', 'user'], true)) {
            return ['status' => 409, 'data' => ['error' => 'built-in role cannot be deleted']];
        }

        $role->delete();
        AuditService::record(null, 'role_deleted', 'role', $id);
        return ['status' => 200, 'data' => ['status' => 'deleted']];
    }

    public function listPermissions(): array
    {
        return array_map(static function (Permission $permission): array {
            return $permission->publicData();
        }, Permission::find()->orderBy(['resource' => SORT_ASC, 'action' => SORT_ASC])->all());
    }

    public function listRolePermissions(string $roleId): array
    {
        $permissions = Permission::find()
            ->alias('p')
            ->innerJoin('role_permissions rp', 'rp.permission_id = p.id')
            ->where(['rp.role_id' => $roleId])
            ->orderBy(['p.resource' => SORT_ASC, 'p.action' => SORT_ASC])
            ->all();

        return array_map(static function (Permission $permission): array {
            return $permission->publicData();
        }, $permissions);
    }

    public function assignPermissionToRole(string $roleId, string $permissionId): void
    {
        $this->auditMutation(function () use ($roleId, $permissionId): int {
            return $this->insertIgnore('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
        }, 'permission_assigned', 'role', $roleId);
    }

    public function removePermissionFromRole(string $roleId, string $permissionId): void
    {
        $this->auditMutation(function () use ($roleId, $permissionId): int {
            return Yii::$app->db->createCommand()->delete('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId])->execute();
        }, 'permission_removed', 'role', $roleId);
    }

    public function listUserRoles(string $userId): array
    {
        $roles = Role::find()
            ->alias('r')
            ->innerJoin('user_roles ur', 'ur.role_id = r.id')
            ->where(['ur.user_id' => $userId])
            ->orderBy(['r.code' => SORT_ASC])
            ->all();

        return array_map(static function (Role $role): array {
            return $role->publicData();
        }, $roles);
    }

    public function assignRoleToUser(string $userId, string $roleId): void
    {
        $this->auditMutation(function () use ($userId, $roleId): int {
            return $this->insertIgnore('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
        }, 'role_assigned', 'user', $userId);
    }

    public function removeRoleFromUser(string $userId, string $roleId): void
    {
        $this->auditMutation(function () use ($userId, $roleId): int {
            return Yii::$app->db->createCommand()->delete('user_roles', ['user_id' => $userId, 'role_id' => $roleId])->execute();
        }, 'role_removed', 'user', $userId);
    }

    public function listGroups(): array
    {
        return array_map(static function (Group $group): array {
            return $group->publicData();
        }, Group::find()->orderBy(['code' => SORT_ASC])->all());
    }

    public function createGroup(array $body): array
    {
        $group = new Group();
        $group->code = trim((string)($body['code'] ?? ''));
        $group->name = trim((string)($body['name'] ?? ''));
        $group->description = (string)($body['description'] ?? '');

        if ($group->code === '' || $group->name === '') {
            return ['status' => 400, 'data' => ['error' => 'group code and name are required']];
        }

        if (Group::find()->where(['code' => $group->code])->exists()) {
            return ['status' => 409, 'data' => ['error' => 'group already exists']];
        }

        try {
            if (!$group->save()) {
                return ['status' => 400, 'data' => ['error' => 'invalid group payload', 'details' => $group->errors]];
            }
        } catch (IntegrityException $exception) {
            return ['status' => 409, 'data' => ['error' => 'group already exists']];
        }

        if ($group->hasErrors()) {
            return ['status' => 400, 'data' => ['error' => 'invalid group payload', 'details' => $group->errors]];
        }

        $group->refresh();
        AuditService::record(null, 'group_created', 'group', $group->id);
        return ['status' => 201, 'data' => $group->publicData()];
    }

    public function updateGroup(string $id, array $body): array
    {
        $group = Group::findOne(['id' => $id]);
        if (!$group) {
            return ['status' => 404, 'data' => ['error' => 'group not found']];
        }

        $group->name = trim((string)($body['name'] ?? ''));
        $group->description = (string)($body['description'] ?? '');

        if ($group->name === '') {
            return ['status' => 400, 'data' => ['error' => 'group name is required']];
        }

        $group->updated_at = new Expression('now()');
        if (!$group->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid group payload', 'details' => $group->errors]];
        }

        $group->refresh();
        AuditService::record(null, 'group_updated', 'group', $group->id);
        return ['status' => 200, 'data' => $group->publicData()];
    }

    public function deleteGroup(string $id): bool
    {
        $group = Group::findOne(['id' => $id]);
        if (!$group) {
            return false;
        }

        $group->delete();
        AuditService::record(null, 'group_deleted', 'group', $id);
        return true;
    }

    public function listGroupRoles(string $groupId): array
    {
        $roles = Role::find()
            ->alias('r')
            ->innerJoin('group_roles gr', 'gr.role_id = r.id')
            ->where(['gr.group_id' => $groupId])
            ->orderBy(['r.code' => SORT_ASC])
            ->all();

        return array_map(static function (Role $role): array {
            return $role->publicData();
        }, $roles);
    }

    public function assignRoleToGroup(string $groupId, string $roleId): void
    {
        $this->auditMutation(function () use ($groupId, $roleId): int {
            return $this->insertIgnore('group_roles', ['group_id' => $groupId, 'role_id' => $roleId]);
        }, 'group_role_assigned', 'group', $groupId);
    }

    public function removeRoleFromGroup(string $groupId, string $roleId): void
    {
        $this->auditMutation(function () use ($groupId, $roleId): int {
            return Yii::$app->db->createCommand()->delete('group_roles', ['group_id' => $groupId, 'role_id' => $roleId])->execute();
        }, 'group_role_removed', 'group', $groupId);
    }

    public function listUserGroups(string $userId): array
    {
        $groups = Group::find()
            ->alias('g')
            ->innerJoin('user_groups ug', 'ug.group_id = g.id')
            ->where(['ug.user_id' => $userId])
            ->orderBy(['g.code' => SORT_ASC])
            ->all();

        return array_map(static function (Group $group): array {
            return $group->publicData();
        }, $groups);
    }

    public function assignGroupToUser(string $userId, string $groupId): void
    {
        $this->auditMutation(function () use ($userId, $groupId): int {
            return $this->insertIgnore('user_groups', ['user_id' => $userId, 'group_id' => $groupId, 'source' => 'manual']);
        }, 'group_assigned', 'user', $userId);
    }

    public function removeGroupFromUser(string $userId, string $groupId): void
    {
        $this->auditMutation(function () use ($userId, $groupId): int {
            return Yii::$app->db->createCommand()->delete('user_groups', ['user_id' => $userId, 'group_id' => $groupId])->execute();
        }, 'group_removed', 'user', $userId);
    }

    private function insertIgnore(string $table, array $data): int
    {
        // Yii2 не имеет кросс-DB метода ON CONFLICT, поэтому для PostgreSQL используем явный SQL.
        $columns = array_keys($data);
        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $data[$column];
        }

        return Yii::$app->db->createCommand(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ') ON CONFLICT DO NOTHING',
            $params
        )->execute();
    }

    private function auditMutation(callable $mutation, string $action, string $targetType, string $targetId): void
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ((int)$mutation() > 0) {
                AuditService::record(null, $action, $targetType, $targetId);
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }
}
