<?php

namespace app\services;

use app\models\MfaRecoveryCode;
use app\models\OneTimeToken;
use app\models\User;
use Yii;
use yii\db\Expression;

class MfaService
{
    private const TOTP_PERIOD = 30;
    private const TOTP_DIGITS = 6;
    private const TOTP_WINDOW = 1;
    private const CHALLENGE_TTL = 300;
    private const RECOVERY_CODES_COUNT = 8;

    public function setup(User $user): array
    {
        $secret = $this->createEnrollmentSecret($user);

        return $this->enrollmentPayload($user, $secret);
    }

    public function createEnrollmentSecret(User $user): string
    {
        $secret = $this->base32Encode(random_bytes(20));
        $user->mfa_secret = (new CryptoService())->encrypt($secret);
        $user->mfa_enabled = false;
        $user->mfa_last_used_counter = 0;
        if (!$user->save(false, ['mfa_secret', 'mfa_enabled', 'mfa_last_used_counter'])) {
            throw new \RuntimeException('failed to persist MFA enrollment');
        }

        return $secret;
    }

    private function enrollmentPayload(User $user, string $secret): array
    {
        $issuer = parse_url((string)Yii::$app->params['issuer'], PHP_URL_HOST) ?: 'SSO';
        $label = rawurlencode($issuer . ':' . $user->username);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::TOTP_DIGITS,
            'period' => self::TOTP_PERIOD,
        ]);

        return [
            'secret' => $secret,
            'otpauth_url' => 'otpauth://totp/' . $label . '?' . $query,
        ];
    }

    public function enable(User $user, string $secret, string $code): array
    {
        if ($user->mfa_enabled) {
            return ['status' => 409, 'data' => ['error' => 'enroll first']];
        }

        if ($secret === '') {
            $secret = (new CryptoService())->decrypt((string)$user->mfa_secret);
        }

        if ($secret === '') {
            return ['status' => 409, 'data' => ['error' => 'enroll first']];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $counter = null;
            if (!$this->verifyTotp($secret, $code, (int)$user->mfa_last_used_counter, $counter)) {
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid code']];
            }
            $user->mfa_secret = (new CryptoService())->encrypt($secret);
            $user->mfa_enabled = true;
            $user->mfa_last_used_counter = $counter;
            if (!$user->save(false, ['mfa_secret', 'mfa_enabled', 'mfa_last_used_counter'])) {
                throw new \RuntimeException('failed to enable MFA');
            }
            $codes = $this->replaceRecoveryCodes($user);
            AuditService::record($user->id, 'mfa_enabled', 'user', $user->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to enable mfa']];
        }

        return ['status' => 200, 'data' => ['status' => 'mfa_enabled', 'recovery_codes' => $codes]];
    }

    public function disable(User $user, string $code): array
    {
        if (!$user->mfa_enabled) {
            return ['status' => 400, 'data' => ['error' => 'mfa is not in the required state']];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$this->verifyUserCode($user, $code)) {
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid code']];
            }
            $user->mfa_enabled = false;
            $user->mfa_secret = '';
            $user->mfa_last_used_counter = 0;
            if (!$user->save(false, ['mfa_enabled', 'mfa_secret', 'mfa_last_used_counter'])) {
                throw new \RuntimeException('failed to disable MFA');
            }
            MfaRecoveryCode::deleteAll(['user_id' => $user->id]);
            AuditService::record($user->id, 'mfa_disabled', 'user', $user->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to disable mfa']];
        }

        return ['status' => 200, 'data' => ['status' => 'mfa_disabled']];
    }

    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$this->verifyUserCode($user, $code)) {
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid mfa code']];
            }
            $codes = $this->replaceRecoveryCodes($user);
            AuditService::record($user->id, 'mfa_recovery_codes_regenerated', 'user', $user->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to regenerate recovery codes']];
        }

        return ['status' => 200, 'data' => ['recovery_codes' => $codes]];
    }

    public function createLoginChallenge(User $user): array
    {
        $token = JwtService::base64url(random_bytes(32));
        $challenge = new OneTimeToken();
        $challenge->user_id = $user->id;
        $challenge->purpose = OneTimeToken::PURPOSE_MFA_LOGIN;
        $challenge->token_hash = hash('sha256', $token);
        $challenge->expires_at = gmdate('Y-m-d H:i:sP', time() + self::CHALLENGE_TTL);
        if (!$challenge->save(false)) {
            throw new \RuntimeException('failed to create MFA challenge');
        }

        return [
            'user' => [
                'id' => '00000000-0000-0000-0000-000000000000',
                'username' => '',
                'email' => '',
                'status' => '',
                'source' => '',
                'first_name' => '',
                'last_name' => '',
                'attributes' => null,
                'email_verified' => false,
                'mfa_enabled' => false,
                'created_at' => '0001-01-01T00:00:00Z',
                'updated_at' => '0001-01-01T00:00:00Z',
            ],
            'access_token_expires_at' => '0001-01-01T00:00:00Z',
            'session_expires_at' => '0001-01-01T00:00:00Z',
            'mfa_required' => true,
            'mfa_token' => $token,
        ];
    }

    public function verifyLoginChallenge(string $challengeToken, string $code): ?User
    {
        $user = $this->loginChallengeUser($challengeToken);
        if (!$user || !$this->completeLoginChallenge($challengeToken, $user, $code)) {
            return null;
        }

        return $user;
    }

    public function loginChallengeUser(string $challengeToken): ?User
    {
        if ($challengeToken === '') {
            return null;
        }
        return User::find()
            ->innerJoin('one_time_tokens ott', 'ott.user_id = users.id')
            ->where([
                'ott.purpose' => OneTimeToken::PURPOSE_MFA_LOGIN,
                'ott.token_hash' => hash('sha256', $challengeToken),
                'ott.used_at' => null,
                'users.status' => User::STATUS_ACTIVE,
                'users.mfa_enabled' => true,
            ])
            ->andWhere(['>', 'ott.expires_at', new Expression('now()')])
            ->one();
    }

    public function completeLoginChallenge(string $challengeToken, User $user, string $code): bool
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $challengeUser = $this->loginChallengeUser($challengeToken);
            if (!$challengeUser || $challengeUser->id !== $user->id || !$this->verifyUserCode($user, $code)) {
                $transaction->rollBack();
                return false;
            }
            $consumed = OneTimeToken::updateAll(
                ['used_at' => new Expression('now()')],
                [
                    'and',
                    ['purpose' => OneTimeToken::PURPOSE_MFA_LOGIN, 'token_hash' => hash('sha256', $challengeToken), 'used_at' => null],
                    ['>', 'expires_at', new Expression('now()')],
                ]
            );
            if ($consumed !== 1) {
                $transaction->rollBack();
                return false;
            }
            $transaction->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return false;
        }
    }

    private function verifyUserCode(User $user, string $code): bool
    {
        $secret = (new CryptoService())->decrypt((string)$user->mfa_secret);
        $counter = null;
        if ($secret !== '' && $this->verifyTotp($secret, $code, (int)$user->mfa_last_used_counter, $counter)) {
            $updated = User::updateAll(
                ['mfa_last_used_counter' => $counter],
                ['and', ['id' => $user->id], ['<', 'mfa_last_used_counter', $counter]]
            );
            if ($updated === 1) {
                $user->mfa_last_used_counter = $counter;
                return true;
            }
            return false;
        }

        return $this->verifyRecoveryCode($user, $code);
    }

    private function verifyTotp(string $secret, string $code, int $lastUsedCounter, ?int &$matchedCounter): bool
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }

        $secretBytes = $this->base32Decode($secret);
        if ($secretBytes === '') {
            return false;
        }

        $currentCounter = (int)floor(time() / self::TOTP_PERIOD);
        for ($counter = $currentCounter - self::TOTP_WINDOW; $counter <= $currentCounter + self::TOTP_WINDOW; $counter++) {
            if ($counter <= $lastUsedCounter) {
                continue;
            }

            if (hash_equals($this->totp($secretBytes, $counter), $code)) {
                $matchedCounter = $counter;
                return true;
            }
        }

        return false;
    }

    private function verifyRecoveryCode(User $user, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        return MfaRecoveryCode::updateAll(
            ['used_at' => new Expression('now()')],
            ['user_id' => $user->id, 'code_hash' => hash('sha256', $code), 'used_at' => null]
        ) > 0;
    }

    private function replaceRecoveryCodes(User $user): array
    {
        $ownsTransaction = Yii::$app->db->getTransaction() === null;
        $transaction = $ownsTransaction ? Yii::$app->db->beginTransaction() : null;

        try {
            MfaRecoveryCode::deleteAll(['user_id' => $user->id]);
            $codes = [];
            for ($index = 0; $index < self::RECOVERY_CODES_COUNT; $index++) {
                $plain = bin2hex(random_bytes(5));
                $codes[] = $plain;

                $model = new MfaRecoveryCode();
                $model->user_id = $user->id;
                $model->code_hash = hash('sha256', $plain);
                if (!$model->save(false)) {
                    throw new \RuntimeException('failed to create recovery code');
                }
            }
            if ($transaction) {
                $transaction->commit();
            }
            return $codes;
        } catch (\Throwable $exception) {
            if ($transaction && $transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function totp(string $secretBytes, int $counter): string
    {
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $secretBytes, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string)($value % (10 ** self::TOTP_DIGITS)), self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($index = 0; $index < strlen($bytes); $index++) {
            $bits .= str_pad(decbin(ord($bytes[$index])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $clean = strtoupper(preg_replace('/[^A-Z2-7]+/', '', $value) ?? '');
        $bits = '';
        for ($index = 0; $index < strlen($clean); $index++) {
            $position = strpos($alphabet, $clean[$index]);
            if ($position === false) {
                return '';
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
}
