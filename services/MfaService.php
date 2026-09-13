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
            'otpauth_uri' => 'otpauth://totp/' . $label . '?' . $query,
            'otpauth_url' => 'otpauth://totp/' . $label . '?' . $query,
        ];
    }

    public function enable(User $user, string $secret, string $code): array
    {
        if ($user->mfa_enabled) {
            return ['status' => 409, 'data' => ['error' => 'mfa already enabled']];
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
            return ['status' => 409, 'data' => ['error' => 'mfa is not enabled']];
        }

        if (!$this->verifyUserCode($user, $code)) {
            return ['status' => 400, 'data' => ['error' => 'invalid code']];
        }

        $user->mfa_enabled = false;
        $user->mfa_secret = '';
        $user->mfa_last_used_counter = 0;
        $user->tokens_invalid_before = new Expression('now()');
        $user->save(false, ['mfa_enabled', 'mfa_secret', 'mfa_last_used_counter', 'tokens_invalid_before']);
        MfaRecoveryCode::deleteAll(['user_id' => $user->id]);
        AuditService::record($user->id, 'mfa_disabled', 'user', $user->id);

        return ['status' => 200, 'data' => ['status' => 'disabled']];
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
        OneTimeToken::deleteAll([
            'user_id' => $user->id,
            'purpose' => OneTimeToken::PURPOSE_MFA_LOGIN,
            'used_at' => null,
        ]);

        $token = JwtService::base64url(random_bytes(32));
        $challenge = new OneTimeToken();
        $challenge->user_id = $user->id;
        $challenge->purpose = OneTimeToken::PURPOSE_MFA_LOGIN;
        $challenge->token_hash = hash('sha256', $token);
        $challenge->expires_at = gmdate('Y-m-d H:i:sP', time() + self::CHALLENGE_TTL);
        $challenge->save(false);

        return [
            'mfa_required' => true,
            'mfa_token' => $token,
            'challenge_token' => $token,
            'expires_in' => self::CHALLENGE_TTL,
        ];
    }

    public function verifyLoginChallenge(string $challengeToken, string $code): ?User
    {
        if ($challengeToken === '' || $code === '') {
            return null;
        }

        $challenge = OneTimeToken::find()
            ->where([
                'token_hash' => hash('sha256', $challengeToken),
                'purpose' => OneTimeToken::PURPOSE_MFA_LOGIN,
                'used_at' => null,
            ])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->one();
        if (!$challenge) {
            return null;
        }

        $user = User::findOne(['id' => $challenge->user_id, 'status' => User::STATUS_ACTIVE]);
        if (!$user || !$user->mfa_enabled || !$this->verifyUserCode($user, $code)) {
            return null;
        }

        $challenge->used_at = new Expression('now()');
        $challenge->save(false, ['used_at']);
        AuditService::record($user->id, 'mfa_login_verified', 'user', $user->id);

        return $user;
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
        $normalized = preg_replace('/\D+/', '', $code);
        if (!is_string($normalized) || strlen($normalized) !== self::TOTP_DIGITS) {
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

            if (hash_equals($this->totp($secretBytes, $counter), $normalized)) {
                $matchedCounter = $counter;
                return true;
            }
        }

        return false;
    }

    private function verifyRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        $codes = MfaRecoveryCode::find()
            ->where(['user_id' => $user->id, 'used_at' => null])
            ->all();
        foreach ($codes as $recoveryCode) {
            if (password_verify($normalized, (string)$recoveryCode->code_hash)) {
                $recoveryCode->used_at = new Expression('now()');
                $recoveryCode->save(false, ['used_at']);
                AuditService::record($user->id, 'mfa_recovery_code_used', 'user', $user->id);
                return true;
            }
        }

        return false;
    }

    private function replaceRecoveryCodes(User $user): array
    {
        MfaRecoveryCode::deleteAll(['user_id' => $user->id]);

        $codes = [];
        for ($index = 0; $index < self::RECOVERY_CODES_COUNT; $index++) {
            $plain = strtoupper(substr(chunk_split(bin2hex(random_bytes(6)), 4, '-'), 0, 14));
            $codes[] = $plain;

            $model = new MfaRecoveryCode();
            $model->user_id = $user->id;
            $model->code_hash = password_hash($this->normalizeRecoveryCode($plain), PASSWORD_DEFAULT);
            $model->save(false);
        }

        return $codes;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $code) ?? '');
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
