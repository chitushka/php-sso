<?php

namespace tests\Integration;

use app\models\User;
use app\services\RbacService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class RbacServiceTest extends TestCase
{
    private ?string $userId = null;
    private ?string $roleId = null;
    private ?string $groupId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }

        $suffix = bin2hex(random_bytes(4));
        $this->userId = Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source,email_verified)
             VALUES (:username,:email,:password_hash,'active','local',true)
             RETURNING id",
            [
                ':username' => 'phpunit_rbac_' . $suffix,
                ':email' => 'phpunit_rbac_' . $suffix . '@example.test',
                ':password_hash' => password_hash('correct-password-123', PASSWORD_DEFAULT),
            ]
        )->queryScalar();
    }

    protected function tearDown(): void
    {
        if ($this->groupId !== null) {
            Yii::$app->db->createCommand()->delete('groups', ['id' => $this->groupId])->execute();
        }
        if ($this->roleId !== null) {
            Yii::$app->db->createCommand()->delete('roles', ['id' => $this->roleId])->execute();
        }
        if ($this->userId !== null) {
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
        }

        parent::tearDown();
    }

    public function testRoleGroupAssignmentsGrantInheritedPermissionAndAreIdempotent(): void
    {
        $service = new RbacService();
        $suffix = bin2hex(random_bytes(4));

        $role = $service->createRole([
            'code' => 'phpunit_role_' . $suffix,
            'name' => 'PHPUnit Role',
        ]);
        $this->assertSame(201, $role['status']);
        $this->roleId = $role['data']['id'];

        $duplicateRole = $service->createRole([
            'code' => 'phpunit_role_' . $suffix,
            'name' => 'Duplicate',
        ]);
        $this->assertSame(409, $duplicateRole['status']);

        $group = $service->createGroup([
            'code' => 'phpunit_group_' . $suffix,
            'name' => 'PHPUnit Group',
        ]);
        $this->assertSame(201, $group['status']);
        $this->groupId = $group['data']['id'];

        $permissionId = (string)Yii::$app->db->createCommand(
            "SELECT id FROM permissions WHERE code = 'users:read'"
        )->queryScalar();

        $service->assignPermissionToRole($this->roleId, $permissionId);
        $service->assignPermissionToRole($this->roleId, $permissionId);
        $service->assignRoleToGroup($this->groupId, $this->roleId);
        $service->assignRoleToGroup($this->groupId, $this->roleId);
        $service->assignGroupToUser($this->userId, $this->groupId);
        $service->assignGroupToUser($this->userId, $this->groupId);

        $user = User::findOne(['id' => $this->userId]);
        $this->assertTrue($service->userHasPermission($user, 'users', 'read'));
        $this->assertSame(1, $this->relationCount('role_permissions', ['role_id' => $this->roleId, 'permission_id' => $permissionId]));
        $this->assertSame(1, $this->relationCount('group_roles', ['group_id' => $this->groupId, 'role_id' => $this->roleId]));
        $this->assertSame(1, $this->relationCount('user_groups', ['user_id' => $this->userId, 'group_id' => $this->groupId]));

        $service->removeGroupFromUser($this->userId, $this->groupId);
        $this->assertFalse($service->userHasPermission($user, 'users', 'read'));

        $builtInDelete = $service->deleteRole((string)Yii::$app->db->createCommand("SELECT id FROM roles WHERE code = 'admin'")->queryScalar());
        $this->assertSame(409, $builtInDelete['status']);
    }

    private function relationCount(string $table, array $where): int
    {
        return (int)(new \yii\db\Query())->from($table)->where($where)->count('*', Yii::$app->db);
    }
}
