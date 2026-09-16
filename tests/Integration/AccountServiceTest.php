<?php

namespace tests\Integration;

use app\models\OneTimeToken;
use app\models\User;
use app\services\AccountService;
use app\services\AuthService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\web\Request;

class AccountServiceTest extends TestCase
{
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

        parent::tearDown();
    }

    public function testResetPasswordConsumesTokenRevokesSessionsRefreshTokensAndJwt(): void
    {
        $auth = new AuthService();
        $login = $auth->login([
            'username' => $this->username,
            'password' => 'old-password-123',
        ], $this->request());
        $this->assertTrue($login['ok']);
        $this->assertSame(1, $this->activeSessionCount());

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

        $updated = User::findOne(['id' => $this->userId]);
        $this->assertTrue(password_verify('new-password-123', (string)$updated->password_hash));
        $this->assertSame(1, $this->auditCount('password_reset_completed'));

        $reuse = (new AccountService())->resetPassword($rawToken, 'another-password-123');
        $this->assertSame(400, $reuse['status']);
        $this->assertSame('invalid or expired token', $reuse['data']['error']);
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

    public function testUpdateProfileNormalizesScalarAttributesAndRejectsNestedValues(): void
    {
        $service = new AccountService();
        $user = User::findOne(['id' => $this->userId]);

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
