<?php

namespace tests\Integration;

require_once __DIR__ . '/ConcurrentProcessTrait.php';

use app\models\User;
use app\services\MfaService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class MfaServiceTest extends TestCase
{
    use ConcurrentProcessTrait;
    private ?string $userId = null;
    private string $username;
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

        $suffix = bin2hex(random_bytes(4));
        $this->username = 'phpunit_mfa_' . $suffix;
        $this->clientId = 'phpunit-mfa-' . $suffix;
        $this->userId = Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source,email_verified)
             VALUES (:username,:email,:password_hash,'active','local',true)
             RETURNING id",
            [
                ':username' => $this->username,
                ':email' => $this->username . '@example.test',
                ':password_hash' => password_hash('correct-password-123', PASSWORD_DEFAULT),
            ]
        )->queryScalar();
        Yii::$app->db->createCommand()->insert('oauth_clients', [
            'client_id' => $this->clientId,
            'name' => 'PHPUnit MFA client',
            'type' => 'public',
        ])->execute();
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            Yii::$app->db->createCommand()->delete('mfa_recovery_codes', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('one_time_tokens', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('oauth_clients', ['client_id' => $this->clientId])->execute();
        }

        parent::tearDown();
    }

    public function testEnrollEnableChallengeRecoveryCodeAndDisable(): void
    {
        $service = new MfaService();
        $user = User::findOne(['id' => $this->userId]);

        $setup = $service->setup($user);
        $this->assertNotEmpty($setup['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_url']);
        $this->createActiveCredentials();

        $enabled = $service->enable(User::findOne(['id' => $this->userId]), $setup['secret'], $this->totpCode($setup['secret']));
        $this->assertSame(200, $enabled['status']);
        $this->assertSame('mfa_enabled', $enabled['data']['status']);
        $this->assertCount(8, $enabled['data']['recovery_codes']);
        foreach ($enabled['data']['recovery_codes'] as $recoveryCode) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{4}(?:-[a-f0-9]{4}){7}$/D', $recoveryCode);
        }
        $this->assertSame(8, $this->activeRecoveryCodeCount());
        $this->assertSame(8, $this->argonRecoveryCodeCount());
        $this->assertCredentialsRevoked();
        $this->assertArrayNotHasKey('secret', $enabled['data']);
        $this->assertSame(1, $this->auditCount('mfa_enabled'));
        $auditPayload = $this->auditPayload();
        $this->assertStringNotContainsString($setup['secret'], $auditPayload);
        foreach ($enabled['data']['recovery_codes'] as $recoveryCode) {
            $this->assertStringNotContainsString($recoveryCode, $auditPayload);
        }

        $alreadyEnabled = $service->enable(
            User::findOne(['id' => $this->userId]),
            $setup['secret'],
            $this->totpCode($setup['secret'], (int)floor(time() / 30) + 1)
        );
        $this->assertSame(409, $alreadyEnabled['status']);
        $this->assertSame('enroll first', $alreadyEnabled['data']['error']);

        $challenge = $service->createLoginChallenge(User::findOne(['id' => $this->userId]));
        $this->assertTrue($challenge['mfa_required']);
        $this->assertSame(['mfa_required', 'mfa_token'], array_keys($challenge));
        $this->assertSame(1, $this->activeChallengeCount());

        $verified = $service->verifyLoginChallenge($challenge['mfa_token'], $enabled['data']['recovery_codes'][0]);
        $this->assertSame($this->userId, $verified->id);
        $this->assertSame(0, $this->activeChallengeCount());
        $this->assertSame(7, $this->activeRecoveryCodeCount());
        $this->assertSame(0, $this->auditCount('mfa_login_verified'));
        $this->assertNull(
            $service->verifyLoginChallenge($challenge['mfa_token'], $enabled['data']['recovery_codes'][1])
        );
        $this->assertSame(7, $this->activeRecoveryCodeCount());

        $this->createActiveCredentials();
        $invalidDisable = $service->disable(User::findOne(['id' => $this->userId]), 'not-a-code');
        $this->assertSame(400, $invalidDisable['status']);
        $this->assertSame('invalid code', $invalidDisable['data']['error']);
        $this->assertSame(1, $this->activeSessionCount());
        $this->assertSame(1, $this->activeRefreshTokenCount());

        $disabled = $service->disable(User::findOne(['id' => $this->userId]), $enabled['data']['recovery_codes'][2]);
        $this->assertSame(200, $disabled['status']);
        $this->assertSame('mfa_disabled', $disabled['data']['status']);
        $this->assertSame(0, $this->activeRecoveryCodeCount());
        $disabledUser = User::findOne(['id' => $this->userId]);
        $this->assertFalse((bool)$disabledUser->mfa_enabled);
        $this->assertNotNull($disabledUser->tokens_invalid_before);
        $this->assertCredentialsRevoked();
        $this->assertArrayNotHasKey('secret', $disabled['data']);
        $this->assertArrayNotHasKey('mfa_secret', $disabledUser->publicData());
        $this->assertSame(1, $this->auditCount('mfa_disabled'));

        $alreadyDisabled = $service->disable($disabledUser, 'not-a-code');
        $this->assertSame(400, $alreadyDisabled['status']);
        $this->assertSame('mfa is not in the required state', $alreadyDisabled['data']['error']);
    }

    public function testConcurrentTotpCanCompleteOnlyOneOfTwoChallenges(): void
    {
        $service = new MfaService();
        $user = User::findOne(['id' => $this->userId]);
        $setup = $service->setup($user);
        $enabled = $service->enable(
            User::findOne(['id' => $this->userId]),
            $setup['secret'],
            $this->totpCode($setup['secret'])
        );
        $firstChallenge = $service->createLoginChallenge(User::findOne(['id' => $this->userId]));
        $secondChallenge = $service->createLoginChallenge(User::findOne(['id' => $this->userId]));
        $nextCode = $this->totpCode($setup['secret'], (int)floor(time() / 30) + 1);

        $results = $this->runConcurrentWorkers([
            ['mfa-login', $firstChallenge['mfa_token'], $nextCode],
            ['mfa-login', $secondChallenge['mfa_token'], $nextCode],
        ]);
        sort($results);

        $this->assertSame(['rejected', 'success'], $results);
        $this->assertSame(1, $this->activeChallengeCount());
        $this->assertSame(8, $this->activeRecoveryCodeCount());
    }

    public function testConcurrentRecoveryCodeCanCompleteOnlyOneOfTwoChallenges(): void
    {
        $service = new MfaService();
        $setup = $service->setup(User::findOne(['id' => $this->userId]));
        $enabled = $service->enable(
            User::findOne(['id' => $this->userId]),
            $setup['secret'],
            $this->totpCode($setup['secret'])
        );
        $firstChallenge = $service->createLoginChallenge(User::findOne(['id' => $this->userId]));
        $secondChallenge = $service->createLoginChallenge(User::findOne(['id' => $this->userId]));
        $recoveryCode = $enabled['data']['recovery_codes'][0];

        $results = $this->runConcurrentWorkers([
            ['mfa-login', $firstChallenge['mfa_token'], $recoveryCode],
            ['mfa-login', $secondChallenge['mfa_token'], $recoveryCode],
        ]);
        sort($results);

        $this->assertSame(['rejected', 'success'], $results);
        $this->assertSame(1, $this->activeChallengeCount());
        $this->assertSame(7, $this->activeRecoveryCodeCount());
    }

    private function totpCode(string $secret, ?int $counter = null): string
    {
        $counter = $counter ?? (int)floor(time() / 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $this->base32Decode($secret), true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($value)) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }

    private function activeRecoveryCodeCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = :user_id AND used_at IS NULL',
            [':user_id' => $this->userId]
        )->queryScalar();
    }

    private function argonRecoveryCodeCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM mfa_recovery_codes
             WHERE user_id = :user_id AND code_hash LIKE '\$argon2id\$%'",
            [':user_id' => $this->userId]
        )->queryScalar();
    }

    private function createActiveCredentials(): void
    {
        Yii::$app->db->createCommand()->insert('sessions', [
            'user_id' => $this->userId,
            'token_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
        ])->execute();
        Yii::$app->db->createCommand()->insert('refresh_tokens', [
            'token_hash' => hash('sha256', random_bytes(32)),
            'family_id' => Yii::$app->db->createCommand('SELECT gen_random_uuid()')->queryScalar(),
            'user_id' => $this->userId,
            'client_id' => $this->clientId,
            'expires_at' => gmdate('Y-m-d H:i:sP', time() + 3600),
        ])->execute();
    }

    private function assertCredentialsRevoked(): void
    {
        $invalidBefore = Yii::$app->db->createCommand(
            'SELECT tokens_invalid_before FROM users WHERE id = :user_id',
            [':user_id' => $this->userId]
        )->queryScalar();

        $this->assertSame(0, $this->activeSessionCount());
        $this->assertSame(0, $this->activeRefreshTokenCount());
        $this->assertNotFalse($invalidBefore);
        $this->assertNotNull($invalidBefore);
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

    private function activeChallengeCount(): int
    {
        return (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM one_time_tokens WHERE user_id = :user_id AND purpose = 'mfa_login' AND used_at IS NULL",
            [':user_id' => $this->userId]
        )->queryScalar();
    }

    private function auditCount(string $action): int
    {
        return (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :user_id AND action = :action',
            [':user_id' => $this->userId, ':action' => $action]
        )->queryScalar();
    }

    private function auditPayload(): string
    {
        return (string)json_encode(Yii::$app->db->createCommand(
            'SELECT action, target_type, target_id, ip, user_agent
             FROM audit_logs WHERE actor_user_id = :user_id',
            [':user_id' => $this->userId]
        )->queryAll(), JSON_UNESCAPED_SLASHES);
    }
}
