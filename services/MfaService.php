<?php

namespace app\services;

use app\models\MfaRecoveryCode;
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
        $user->save(false, ['mfa_secret', 'mfa_enabled', 'mfa_last_used_counter']);

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

        $counter = null;
        if (!$this->verifyTotp($secret, $code, (int)$user->mfa_last_used_counter, $counter)) {
            return ['status' => 400, 'data' => ['error' => 'invalid code']];
        }

        $user->mfa_secret = (new CryptoService())->encrypt($secret);
        $user->mfa_enabled = true;
        $user->mfa_last_used_counter = $counter;
        $user->save(false, ['mfa_secret', 'mfa_enabled', 'mfa_last_used_counter']);

        $codes = $this->replaceRecoveryCodes($user);
        AuditService::record($user->id, 'mfa_enabled', 'user', $user->id);

        return ['status' => 200, 'data' => ['status' => 'mfa_enabled', 'recovery_codes' => $codes]];
    }

    public function disable(User $user, string $code): array
    {
        if (!$user->mfa_enabled) {
            return ['status' => 400, 'data' => ['error' => 'mfa is not in the required state']];
        }

        if (!$this->verifyUserCode($user, $code)) {
            return ['status' => 400, 'data' => ['error' => 'invalid code']];
        }

        $user->mfa_enabled = false;
        $user->mfa_secret = '';
        $user->mfa_last_used_counter = 0;
        $user->save(false, ['mfa_enabled', 'mfa_secret', 'mfa_last_used_counter']);
        MfaRecoveryCode::deleteAll(['user_id' => $user->id]);
        AuditService::record($user->id, 'mfa_disabled', 'user', $user->id);

        return ['status' => 200, 'data' => ['status' => 'mfa_disabled']];
    }

    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (!$this->verifyUserCode($user, $code)) {
            return ['status' => 400, 'data' => ['error' => 'invalid mfa code']];
        }

        $codes = $this->replaceRecoveryCodes($user);
        AuditService::record($user->id, 'mfa_recovery_codes_regenerated', 'user', $user->id);

        return ['status' => 200, 'data' => ['recovery_codes' => $codes]];
    }

    public function createLoginChallenge(User $user): array
    {
        $token = (new JwtService())->issue($user->id, [
            'username' => $user->username,
            'email' => '',
            'source' => '',
            'purpose' => 'mfa',
        ], self::CHALLENGE_TTL);

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
        $claims = (new JwtService())->verify($challengeToken);
        if (!$claims || ($claims['purpose'] ?? '') !== 'mfa' || empty($claims['sub'])) {
            return null;
        }

        return User::findOne([
            'id' => $claims['sub'],
            'status' => User::STATUS_ACTIVE,
            'mfa_enabled' => true,
        ]);
    }

    public function completeLoginChallenge(string $challengeToken, User $user, string $code): bool
    {
        $challengeUser = $this->loginChallengeUser($challengeToken);
        if (!$challengeUser || $challengeUser->id !== $user->id || !$this->verifyUserCode($user, $code)) {
            return false;
        }

        return true;
    }

    private function verifyUserCode(User $user, string $code): bool
    {
        $secret = (new CryptoService())->decrypt((string)$user->mfa_secret);
        $counter = null;
        if ($secret !== '' && $this->verifyTotp($secret, $code, (int)$user->mfa_last_used_counter, $counter)) {
            $user->mfa_last_used_counter = $counter;
            $user->save(false, ['mfa_last_used_counter']);
            return true;
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
        MfaRecoveryCode::deleteAll(['user_id' => $user->id]);

        $codes = [];
        for ($index = 0; $index < self::RECOVERY_CODES_COUNT; $index++) {
            $plain = bin2hex(random_bytes(5));
            $codes[] = $plain;

            $model = new MfaRecoveryCode();
            $model->user_id = $user->id;
            $model->code_hash = hash('sha256', $plain);
            $model->save(false);
        }

        return $codes;
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
