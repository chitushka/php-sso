<?php

namespace tests\Integration;

use app\models\OneTimeToken;
use app\models\User;
use app\services\AccountService;
use app\services\AuthService;
use app\services\PasswordHasher;
use app\services\RbacService;
use app\services\UserService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\web\Request;

class UserLifecycleServiceTest extends TestCase
{
    private $transaction;
    private string $userId;
    private string $otherUserId;
    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }
        Yii::$app->params = array_merge(require dirname(__DIR__, 2) . '/config/params.php', Yii::$app->params);
        $this->transaction = Yii::$app->db->beginTransaction();

        $suffix = bin2hex(random_bytes(4));
        $this->userId = $this->insertUser(
            'phpunit_lifecycle_' . $suffix,
            'Verified.User.' . $suffix . '@Example.Test'
        );
        $this->otherUserId = $this->insertUser(
            'phpunit_lifecycle_other_' . $suffix,
            'other.' . $suffix . '@example.test'
        );
        $this->clientId = 'lifecycle-client-' . $suffix;
        Yii::$app->db->createCommand()->insert('oauth_clients', [
            'client_id' => $this->clientId,
            'name' => 'Lifecycle test client',
            'type' => 'confidential',
            'client_secret_hash' => PasswordHasher::hash('lifecycle-client-secret'),
            'enabled' => true,
        ])->execute();
    }

    protected function tearDown(): void
    {
        if ($this->transaction !== null && $this->transaction->isActive) {
            $this->transaction->rollBack();
        }
        parent::tearDown();
    }

    public function testEmailChangeNormalizesAddressResetsVerificationAndRevokesCredentials(): void
    {
        $rawVerificationToken = 'verify-' . bin2hex(random_bytes(12));
        Yii::$app->db->createCommand()->insert('one_time_tokens', [
            'user_id' => $this->userId,
            'purpose' => OneTimeToken::PURPOSE_EMAIL_VERIFY,
            'token_hash' => hash('sha256', $rawVerificationToken),
            'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
        ])->execute();
        Yii::$app->db->createCommand()->insert('sessions', [
            'user_id' => $this->userId,
            'token_hash' => hash('sha256', 'lifecycle-session'),
            'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
        ])->execute();
        Yii::$app->db->createCommand()->insert('refresh_tokens', [
            'token_hash' => hash('sha256', 'lifecycle-refresh'),
            'family_id' => Yii::$app->db->createCommand('SELECT gen_random_uuid()')->queryScalar(),
            'user_id' => $this->userId,
            'client_id' => $this->clientId,
            'scope' => 'openid',
            'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
        ])->execute();
        Yii::$app->db->createCommand(
            "UPDATE users SET updated_at = now() - interval '1 day' WHERE id = :id",
            [':id' => $this->userId]
        )->execute();

        $auth = new AuthService();
        $accessToken = $auth->issueAccessToken(User::findOne(['id' => $this->userId]), time() - 10);
        $before = User::findOne(['id' => $this->userId]);
        $result = (new UserService())->update($this->userId, $this->updateBody([
            'email' => '  NEW.Address@Example.TEST  ',
        ]));

        $this->assertSame(200, $result['status']);
        $updated = User::findOne(['id' => $this->userId]);
        $this->assertSame('new.address@example.test', $updated->email);
        $this->assertFalse((bool)$updated->email_verified);
        $this->assertGreaterThan(strtotime((string)$before->updated_at), strtotime((string)$updated->updated_at));
        $this->assertSame(0, $this->countRows('one_time_tokens', [
            'user_id' => $this->userId,
            'purpose' => OneTimeToken::PURPOSE_EMAIL_VERIFY,
        ]));
        $this->assertSame(0, $this->activeCount('sessions'));
        $this->assertSame(0, $this->activeCount('refresh_tokens'));
        $this->assertNull($auth->currentUser($this->bearerRequest($accessToken)));
        $oldVerification = (new AccountService())->verifyEmail($rawVerificationToken);
        $this->assertSame(400, $oldVerification['status']);
        $this->assertSame('invalid or expired token', $oldVerification['data']['error']);
    }

    public function testDuplicateNormalizedEmailIsRejectedWithoutChangingUser(): void
    {
        $other = User::findOne(['id' => $this->otherUserId]);
        $result = (new UserService())->update($this->userId, $this->updateBody([
            'email' => '  ' . strtoupper((string)$other->email) . '  ',
        ]));

        $this->assertSame(409, $result['status']);
        $this->assertSame('username or email already exists', $result['data']['error']);
        $unchanged = User::findOne(['id' => $this->userId]);
        $this->assertNotSame(User::normalizeEmail((string)$other->email), $unchanged->email);
        $this->assertTrue((bool)$unchanged->email_verified);
    }

    public function testPasswordResetLookupPrioritizesExactUsernameOverEmail(): void
    {
        $emailOwner = User::findOne(['id' => $this->userId]);
        $usernameOwner = User::findOne(['id' => $this->otherUserId]);
        $usernameOwner->username = (string)$emailOwner->email;
        $this->assertTrue($usernameOwner->save());

        (new AccountService())->forgotPassword((string)$emailOwner->email);

        $this->assertSame(0, $this->countRows('one_time_tokens', [
            'user_id' => $this->userId,
            'purpose' => OneTimeToken::PURPOSE_PASSWORD_RESET,
        ]));
        $this->assertSame(1, $this->countRows('one_time_tokens', [
            'user_id' => $this->otherUserId,
            'purpose' => OneTimeToken::PURPOSE_PASSWORD_RESET,
        ]));
    }

    public function testBlockingAndDeletingUsersRevokeCredentials(): void
    {
        foreach ([$this->userId, $this->otherUserId] as $userId) {
            Yii::$app->db->createCommand()->insert('sessions', [
                'user_id' => $userId,
                'token_hash' => hash('sha256', 'session-' . $userId),
                'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
            ])->execute();
            Yii::$app->db->createCommand()->insert('refresh_tokens', [
                'token_hash' => hash('sha256', 'refresh-' . $userId),
                'family_id' => Yii::$app->db->createCommand('SELECT gen_random_uuid()')->queryScalar(),
                'user_id' => $userId,
                'client_id' => $this->clientId,
                'scope' => '',
                'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
            ])->execute();
        }

        $blocked = (new UserService())->update($this->userId, $this->updateBody([
            'status' => User::STATUS_BLOCKED,
        ]));
        $deleted = (new UserService())->delete($this->otherUserId);

        $this->assertSame(200, $blocked['status']);
        $this->assertSame(200, $deleted['status']);
        $this->assertSame(User::STATUS_BLOCKED, User::findOne(['id' => $this->userId])->status);
        $this->assertSame(User::STATUS_DELETED, User::findOne(['id' => $this->otherUserId])->status);
        foreach ([$this->userId, $this->otherUserId] as $userId) {
            $this->assertSame(0, $this->activeCount('sessions', $userId));
            $this->assertSame(0, $this->activeCount('refresh_tokens', $userId));
            $this->assertNotNull(User::findOne(['id' => $userId])->tokens_invalid_before);
        }
    }

    public function testLastActiveAdministratorCannotBeBlockedDeletedOrUnassigned(): void
    {
        $adminRoleId = (string)Yii::$app->db->createCommand(
            "SELECT id FROM roles WHERE code = 'admin'"
        )->queryScalar();
        Yii::$app->db->createCommand()->insert('user_roles', [
            'user_id' => $this->userId,
            'role_id' => $adminRoleId,
        ])->execute();
        Yii::$app->db->createCommand(
            "UPDATE users SET status = 'blocked'
             WHERE id <> :id AND status = 'active' AND (
                 EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = users.id AND r.code = 'admin')
                 OR EXISTS (SELECT 1 FROM user_groups ug JOIN group_roles gr ON gr.group_id = ug.group_id JOIN roles r ON r.id = gr.role_id WHERE ug.user_id = users.id AND r.code = 'admin')
             )",
            [':id' => $this->userId]
        )->execute();

        $service = new UserService();
        $blocked = $service->update($this->userId, $this->updateBody(['status' => User::STATUS_BLOCKED]));
        $deleted = $service->delete($this->userId);
        $roleRemoved = (new RbacService())->removeRoleFromUser($this->userId, $adminRoleId);

        $this->assertSame(409, $blocked['status']);
        $this->assertSame(409, $deleted['status']);
        $this->assertFalse($roleRemoved);
        $this->assertSame(User::STATUS_ACTIVE, User::findOne(['id' => $this->userId])->status);
        $this->assertSame(1, $this->countRows('user_roles', [
            'user_id' => $this->userId,
            'role_id' => $adminRoleId,
        ]));

        $rbac = new RbacService();
        $group = $rbac->createGroup([
            'code' => 'phpunit_last_admin_' . bin2hex(random_bytes(3)),
            'name' => 'Last administrator group',
        ]);
        $groupId = (string)$group['data']['id'];
        $rbac->assignRoleToGroup($groupId, $adminRoleId);
        $rbac->assignGroupToUser($this->userId, $groupId);
        $this->assertTrue($rbac->removeRoleFromUser($this->userId, $adminRoleId));
        $this->assertFalse($rbac->removeGroupFromUser($this->userId, $groupId));
        $this->assertFalse($rbac->removeRoleFromGroup($groupId, $adminRoleId));
        $this->assertSame(409, $rbac->deleteGroup($groupId)['status']);

        $permissionId = (string)Yii::$app->db->createCommand(
            "SELECT id FROM permissions WHERE code = 'users:read'"
        )->queryScalar();
        $this->assertFalse($rbac->removePermissionFromRole($adminRoleId, $permissionId));
    }

    private function insertUser(string $username, string $email): string
    {
        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->password_hash = PasswordHasher::hash('correct-password-123');
        $user->status = User::STATUS_ACTIVE;
        $user->source = User::SOURCE_LOCAL;
        $user->email_verified = true;
        $this->assertTrue($user->save(), json_encode($user->errors));
        return (string)$user->id;
    }

    private function updateBody(array $overrides): array
    {
        $user = User::findOne(['id' => $this->userId]);
        return array_merge([
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'attributes' => [],
        ], $overrides);
    }

    private function activeCount(string $table, ?string $userId = null): int
    {
        return (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = :user_id AND revoked_at IS NULL",
            [':user_id' => $userId ?? $this->userId]
        )->queryScalar();
    }

    private function countRows(string $table, array $where): int
    {
        return (int)(new \yii\db\Query())->from($table)->where($where)->count('*', Yii::$app->db);
    }

    private function bearerRequest(string $token): Request
    {
        $request = new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'lifecycle-test',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        return $request;
    }
}
