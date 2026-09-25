<?php

namespace tests\Integration;

use app\models\User;
use app\services\MfaService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class MfaServiceTest extends TestCase
{
    private ?string $userId = null;
    private string $username;

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
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            Yii::$app->db->createCommand()->delete('mfa_recovery_codes', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('one_time_tokens', ['user_id' => $this->userId])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $this->userId], ['target_id' => $this->userId]])->execute();
            Yii::$app->db->createCommand()->delete('users', ['id' => $this->userId])->execute();
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

        $enabled = $service->enable(User::findOne(['id' => $this->userId]), $setup['secret'], $this->totpCode($setup['secret']));
        $this->assertSame(200, $enabled['status']);
        $this->assertSame('mfa_enabled', $enabled['data']['status']);
        $this->assertCount(8, $enabled['data']['recovery_codes']);
        $this->assertSame(8, $this->activeRecoveryCodeCount());
        $this->assertSame(1, $this->auditCount('mfa_enabled'));

        $alreadyEnabled = $service->enable(
            User::findOne(['id' => $this->userId]),
            $setup['secret'],
            $this->totpCode($setup['secret'], (int)floor(time() / 30) + 1)
        );
        $this->assertSame(409, $alreadyEnabled['status']);
        $this->assertSame('enroll first', $alreadyEnabled['data']['error']);

        $challenge = $service->createLoginChallenge(User::findOne(['id' => $this->userId]));
        $this->assertTrue($challenge['mfa_required']);
        $this->assertArrayNotHasKey('challenge_token', $challenge);
        $this->assertSame('0001-01-01T00:00:00Z', $challenge['session_expires_at']);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $challenge['user']['id']);
        $this->assertSame(0, $this->activeChallengeCount());

        $verified = $service->verifyLoginChallenge($challenge['mfa_token'], $enabled['data']['recovery_codes'][0]);
        $this->assertSame($this->userId, $verified->id);
        $this->assertSame(0, $this->activeChallengeCount());
        $this->assertSame(7, $this->activeRecoveryCodeCount());
        $this->assertSame(0, $this->auditCount('mfa_login_verified'));
        $this->assertSame(
            $this->userId,
            $service->verifyLoginChallenge($challenge['mfa_token'], $enabled['data']['recovery_codes'][1])->id
        );
        $this->assertSame(6, $this->activeRecoveryCodeCount());

        $invalidDisable = $service->disable(User::findOne(['id' => $this->userId]), 'not-a-code');
        $this->assertSame(400, $invalidDisable['status']);
        $this->assertSame('invalid code', $invalidDisable['data']['error']);

        $disabled = $service->disable(User::findOne(['id' => $this->userId]), $enabled['data']['recovery_codes'][2]);
        $this->assertSame(200, $disabled['status']);
        $this->assertSame('mfa_disabled', $disabled['data']['status']);
        $this->assertSame(0, $this->activeRecoveryCodeCount());
        $disabledUser = User::findOne(['id' => $this->userId]);
        $this->assertFalse((bool)$disabledUser->mfa_enabled);
        $this->assertNull($disabledUser->tokens_invalid_before);
        $this->assertSame(1, $this->auditCount('mfa_disabled'));

        $alreadyDisabled = $service->disable($disabledUser, 'not-a-code');
        $this->assertSame(400, $alreadyDisabled['status']);
        $this->assertSame('mfa is not in the required state', $alreadyDisabled['data']['error']);
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
}
