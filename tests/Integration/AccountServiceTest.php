<?php

namespace tests\Integration;

require_once __DIR__ . '/ConcurrentProcessTrait.php';

use app\models\OneTimeToken;
use app\models\User;
use app\services\AccountService;
use app\services\AuthService;
use app\services\JwtService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\web\Request;

class AccountServiceTest extends TestCase
{
    use ConcurrentProcessTrait;
    private string $username;
    private ?string $userId = null;

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

        $suffix = bin2hex(random_bytes(4));
        $this->username = 'phpunit_account_' . $suffix;
        $this->userId = Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source,email_verified)
             VALUES (:username,:email,:password_hash,'active','local',false)
             RETURNING id",
            [
                ':username' => $this->username,
                ':email' => $this->username . '@example.test',
                ':password_hash' => password_hash('old-password-123', PASSWORD_DEFAULT),
            ]
        )->queryScalar();
        Yii::$app->db->createCommand()->batchInsert(
            'oauth_clients',
            ['client_id', 'name', 'type', 'client_secret_hash', 'enabled'],
            [
                ['account-test-client', 'Account test client', 'confidential', password_hash('account-client-secret', PASSWORD_DEFAULT), true],
                ['change-password-client', 'Change password test client', 'confidential', password_hash('change-client-secret', PASSWORD_DEFAULT), true],
            ]
        )->execute();
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            Yii::$app->db->createCommand()->delete('one_time_tokens', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('refresh_tokens', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('sessions', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
        }
        Yii::$app->db->createCommand()->delete(
            'oauth_clients',
            ['client_id' => ['account-test-client', 'change-password-client']]
        )->execute();

        parent::tearDown();
    }

    public function testResetPasswordConsumesTokenRevokesSessionsRefreshTokensAndJwt(): void
    {
        $auth = new AuthService();
        $login = $auth->login([
            'username' => $this->username,
            'password' => 'old-password-123',
        ], $this->request());
        $this->assertTrue($login->isSuccess());
        $this->assertSame(1, $this->activeSessionCount());
        $externalAccessToken = (new JwtService())->issue($this->userId, [
            'iss' => Yii::$app->params['issuer'],
            'aud' => Yii::$app->params['apiAudience'],
            'purpose' => JwtService::PURPOSE_OAUTH_USER,
            'client_id' => 'account-test-client',
            'scope' => 'openid profile',
        ]);
        $this->assertSame($this->userId, $auth->currentUser($this->bearerRequest($externalAccessToken))->id);

        Yii::$app->db->createCommand(
            "INSERT INTO refresh_tokens(token_hash,family_id,user_id,client_id,scope,expires_at)
             VALUES (:token_hash, gen_random_uuid(), :user_id, 'account-test-client', 'profile', now() + interval '1 hour')",
            [
                ':token_hash' => hash('sha256', 'account-refresh-token'),
                ':user_id' => $this->userId,
            ]
        )->execute();
        $this->assertSame(1, $this->activeRefreshTokenCount());

        $rawToken = 'reset-' . bin2hex(random_bytes(16));
        $this->insertOneTimeToken(OneTimeToken::PURPOSE_PASSWORD_RESET, $rawToken);

        $reset = (new AccountService())->resetPassword($rawToken, 'new-password-123');
        $this->assertSame(200, $reset['status']);
        $this->assertSame('password_reset', $reset['data']['status']);
        $this->assertSame(1, $this->usedTokenCount(OneTimeToken::PURPOSE_PASSWORD_RESET));
        $this->assertSame(0, $this->activeSessionCount());
        $this->assertSame(0, $this->activeRefreshTokenCount());
        $this->assertNull($auth->currentUser($this->bearerRequest($login['data']['access_token'])));
        $this->assertNull($auth->currentUser($this->bearerRequest($externalAccessToken)));

        $updated = User::findOne(['id' => $this->userId]);
        $this->assertTrue(password_verify('new-password-123', (string)$updated->password_hash));
        $this->assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=2$', (string)$updated->password_hash);
        $this->assertSame(1, $this->auditCount('password_reset_completed'));

        $reuse = (new AccountService())->resetPassword($rawToken, 'another-password-123');
        $this->assertSame(400, $reuse['status']);
        $this->assertSame('invalid or expired token', $reuse['data']['error']);

        Yii::$app->db->createCommand()->update('users', [
            'status' => User::STATUS_BLOCKED,
        ], ['id' => $this->userId])->execute();
        $this->assertNull($auth->currentUser($this->bearerRequest($externalAccessToken)));
    }

    public function testVerifyEmailConsumesTokenAndActivatesPendingUser(): void
    {
        Yii::$app->db->createCommand()->update('users', [
            'status' => User::STATUS_PENDING,
            'email_verified' => false,
        ], ['id' => $this->userId])->execute();

        $rawToken = 'verify-' . bin2hex(random_bytes(16));
        $this->insertOneTimeToken(OneTimeToken::PURPOSE_EMAIL_VERIFY, $rawToken);

        $verify = (new AccountService())->verifyEmail($rawToken);
        $this->assertSame(200, $verify['status']);
        $this->assertSame('email_verified', $verify['data']['status']);

        $user = User::findOne(['id' => $this->userId]);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertTrue((bool)$user->email_verified);
        $this->assertSame(1, $this->usedTokenCount(OneTimeToken::PURPOSE_EMAIL_VERIFY));
        $this->assertSame(1, $this->auditCount('email_verified'));
    }

    public function testConcurrentPasswordResetConsumesTokenOnce(): void
    {
        $rawToken = 'concurrent-reset-' . bin2hex(random_bytes(16));
        $this->insertOneTimeToken(OneTimeToken::PURPOSE_PASSWORD_RESET, $rawToken);

        $results = $this->runConcurrentWorkers([
            ['password-reset', $rawToken, 'concurrent-password-123'],
            ['password-reset', $rawToken, 'concurrent-password-123'],
        ]);
        sort($results);

        $this->assertSame(['200', '400'], $results);
        $this->assertSame(1, $this->usedTokenCount(OneTimeToken::PURPOSE_PASSWORD_RESET));
        $this->assertSame(1, $this->auditCount('password_reset_completed'));
    }

    public function testChangePasswordRevokesSessionsRefreshTokensAndJwt(): void
    {
        $auth = new AuthService();
        $login = $auth->login([
            'username' => $this->username,
            'password' => 'old-password-123',
        ], $this->request());
        Yii::$app->db->createCommand(
            "INSERT INTO refresh_tokens(token_hash,family_id,user_id,client_id,scope,expires_at)
             VALUES (:hash, gen_random_uuid(), :user_id, 'change-password-client', '', now() + interval '1 hour')",
            [':hash' => hash('sha256', 'change-password-refresh'), ':user_id' => $this->userId]
        )->execute();

        $result = (new AccountService())->changePassword(
            User::findOne(['id' => $this->userId]),
            'old-password-123',
            'changed-password-123'
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $this->activeSessionCount());
        $this->assertSame(0, $this->activeRefreshTokenCount());
        $this->assertNull($auth->currentUser($this->bearerRequest($login['data']['access_token'])));
        $this->assertTrue(password_verify(
            'changed-password-123',
            (string)User::findOne(['id' => $this->userId])->password_hash
        ));
    }

    public function testUpdateProfileNormalizesScalarAttributesAndRejectsNestedValues(): void
    {
        $service = new AccountService();
        $user = User::findOne(['id' => $this->userId]);
        Yii::$app->db->createCommand(
            "UPDATE users SET updated_at = now() - interval '1 day' WHERE id = :id",
            [':id' => $this->userId]
        )->execute();
        $user->refresh();
        $previousUpdatedAt = strtotime((string)$user->updated_at);

        $invalid = $service->updateProfile($user, [
            'attributes' => ['department' => ['nested']],
        ]);
        $this->assertSame(400, $invalid['status']);
        $this->assertSame('attributes must be an object with scalar values', $invalid['data']['error']);

        $valid = $service->updateProfile($user, [
            'first_name' => '  Ada ',
            'last_name' => ' Lovelace ',
            'attributes' => ['department' => 'Security', 'level' => 3, 'empty' => null],
        ]);

        $this->assertSame(200, $valid['status']);
        $this->assertSame('Ada', $valid['data']['first_name']);
        $this->assertSame('Lovelace', $valid['data']['last_name']);
        $this->assertSame(['department' => 'Security', 'level' => '3', 'empty' => ''], $valid['data']['attributes']);
        $this->assertGreaterThan($previousUpdatedAt, strtotime((string)$valid['data']['updated_at']));
        $this->assertSame(1, $this->auditCount('profile_updated'));
    }

    private function insertOneTimeToken(string $purpose, string $token): void
    {
        Yii::$app->db->createCommand(
            "INSERT INTO one_time_tokens(user_id,purpose,token_hash,expires_at)
             VALUES (:user_id,:purpose,:token_hash,now() + interval '1 hour')",
            [
                ':user_id' => $this->userId,
                ':purpose' => $purpose,
                ':token_hash' => hash('sha256', $token),
            ]
        )->execute();
    }

    private function request(): Request
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.47';
        $_COOKIE = [];
        return new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
    }

    private function bearerRequest(string $token): Request
    {
        $request = $this->request();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function activeSessionCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM sessions WHERE user_id = :user_id AND revoked_at IS NULL',
            [':user_id' => $this->userId]
        )->queryScalar();
    }

    private function activeRefreshTokenCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = :user_id AND revoked_at IS NULL',
            [':user_id' => $this->userId]
        )->queryScalar();
    }

    private function usedTokenCount(string $purpose): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM one_time_tokens WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NOT NULL',
            [':user_id' => $this->userId, ':purpose' => $purpose]
        )->queryScalar();
    }

    private function auditCount(string $action): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :user_id AND action = :action',
            [':user_id' => $this->userId, ':action' => $action]
        )->queryScalar();
    }
}
