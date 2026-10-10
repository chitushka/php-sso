<?php

namespace app\services;

use app\models\OneTimeToken;
use app\models\RefreshToken;
use app\models\Session;
use app\models\User;
use yii\db\Expression;

class AccountService
{
    private const RESET_TTL = 3600;
    private const VERIFY_TTL = 86400;

    public function forgotPassword(string $login): void
    {
        $login = trim($login);
        if ($login === '') {
            return;
        }

        // Login identifier имеет явный приоритет, чтобы совпадение username одной
        // учётной записи с email другой не приводило к недетерминированному выбору.
        $user = User::findOne(['username' => $login]);
        if (!$user) {
            $user = User::findOne(['email' => User::normalizeEmail($login)]);
        }
        if (!$user || $user->source !== User::SOURCE_LOCAL || $user->status !== User::STATUS_ACTIVE || $user->email === '') {
            return;
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $token = $this->createToken($user, OneTimeToken::PURPOSE_PASSWORD_RESET, self::RESET_TTL);
            AuditService::record($user->id, 'password_reset_requested', 'user', $user->id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
        $link = $this->issuerUrl('/reset-password?token=' . rawurlencode($token));
        $body = sprintf(
            "Hello %s,\n\nA password reset was requested for your account.\nOpen the link below within 1 hour to set a new password:\n\n%s\n\nIf you did not request this, ignore this message.",
            $user->username,
            $link
        );

        (new MailService())->send((string)$user->email, 'Password reset', $body);
    }

    public function resetPassword(string $token, string $password): array
    {
        $passwordError = PasswordPolicy::validate($password);
        if ($passwordError !== null) {
            return ['status' => 400, 'data' => ['error' => $passwordError]];
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $user = $this->consumeToken(OneTimeToken::PURPOSE_PASSWORD_RESET, $token);
            if (!$user || $user->source !== User::SOURCE_LOCAL) {
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid or expired token']];
            }

            $user->password_hash = PasswordHasher::hash($password);
            $user->tokens_invalid_before = new Expression('now()');
            if (!$user->save(false, ['password_hash', 'tokens_invalid_before'])) {
                throw new \RuntimeException('failed to update password');
            }

            // Reset считается компрометацией старых credentials: отзываем все сессии и refresh tokens.
            Session::updateAll(['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]);
            RefreshToken::updateAll(['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]);
            AuditService::record($user->id, 'password_reset_completed', 'user', $user->id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to reset password']];
        }

        return ['status' => 200, 'data' => ['status' => 'password_reset']];
    }

    public function changePassword(User $user, string $oldPassword, string $newPassword): array
    {
        $passwordError = PasswordPolicy::validate($newPassword);
        if ($passwordError !== null) {
            return ['status' => 400, 'data' => ['error' => $passwordError]];
        }

        if ($user->source !== User::SOURCE_LOCAL || !$user->password_hash) {
            return ['status' => 400, 'data' => ['error' => 'password is managed externally for this account']];
        }

        if (!PasswordHasher::verify($oldPassword, (string)$user->password_hash)) {
            return ['status' => 400, 'data' => ['error' => 'current password is incorrect']];
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $user->password_hash = PasswordHasher::hash($newPassword);
            $user->tokens_invalid_before = new Expression('now()');
            if (!$user->save(false, ['password_hash', 'tokens_invalid_before'])) {
                throw new \RuntimeException('failed to update password');
            }
            Session::updateAll(['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]);
            RefreshToken::updateAll(['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]);
            AuditService::record($user->id, 'password_changed', 'user', $user->id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to change password']];
        }

        return ['status' => 200, 'data' => ['status' => 'password_changed']];
    }

    public function updateProfile(User $user, array $body): array
    {
        foreach (['first_name', 'last_name'] as $field) {
            if (array_key_exists($field, $body)) {
                $user->{$field} = trim((string)$body[$field]);
            }
        }

        if (array_key_exists('attributes', $body)) {
            $attributes = $this->normalizeAttributes($body['attributes']);
            if ($attributes === null) {
                return ['status' => 400, 'data' => ['error' => 'attributes must be an object with scalar values']];
            }
            $user->attributes = json_encode($attributes);
        }

        $user->updated_at = new Expression('now()');

        if (!$user->save(false, ['first_name', 'last_name', 'attributes', 'updated_at'])) {
            return ['status' => 400, 'data' => ['error' => 'invalid profile payload']];
        }
        $user->refresh();

        AuditService::record($user->id, 'profile_updated', 'user', $user->id);

        return ['status' => 200, 'data' => $user->publicData()];
    }

    private function normalizeAttributes($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $attributes = [];
        foreach ($value as $key => $item) {
            if (!is_scalar($item) && $item !== null) {
                return null;
            }
            $attributes[(string)$key] = $item === null ? '' : (string)$item;
        }

        return $attributes;
    }

    public function requestEmailVerification(User $user): array
    {
        if ((bool)$user->email_verified) {
            return ['status' => 400, 'data' => ['error' => 'email already verified']];
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $token = $this->createToken($user, OneTimeToken::PURPOSE_EMAIL_VERIFY, self::VERIFY_TTL);
            AuditService::record($user->id, 'email_verification_requested', 'user', $user->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to create verification token']];
        }
        $link = $this->issuerUrl('/verify-email?token=' . rawurlencode($token));
        $body = sprintf(
            "Hello %s,\n\nConfirm your email address by opening the link below within 24 hours:\n\n%s",
            $user->username,
            $link
        );

        if (!(new MailService())->send((string)$user->email, 'Verify your email', $body)) {
            return ['status' => 500, 'data' => ['error' => 'failed to send verification email']];
        }

        return ['status' => 200, 'data' => ['status' => 'sent']];
    }

    public function verifyEmail(string $token): array
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $user = $this->consumeToken(OneTimeToken::PURPOSE_EMAIL_VERIFY, $token);
            if (!$user) {
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid or expired token']];
            }

            $user->email_verified = true;
            $user->updated_at = new Expression('now()');
            $fields = ['email_verified', 'updated_at'];
            if ($user->status === User::STATUS_PENDING) {
                $user->status = User::STATUS_ACTIVE;
                $fields[] = 'status';
            }
            if (!$user->save(false, $fields)) {
                throw new \RuntimeException('failed to verify email');
            }
            AuditService::record($user->id, 'email_verified', 'user', $user->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to verify email']];
        }

        return ['status' => 200, 'data' => ['status' => 'email_verified']];
    }

    private function createToken(User $user, string $purpose, int $ttl): string
    {
        $token = JwtService::base64url(random_bytes(32));
        $model = new OneTimeToken();
        $model->user_id = $user->id;
        $model->purpose = $purpose;
        $model->token_hash = hash('sha256', $token);
        $model->expires_at = gmdate('Y-m-d H:i:sP', time() + $ttl);
        if (!$model->save(false)) {
            throw new \RuntimeException('failed to persist one-time token');
        }

        return $token;
    }

    private function consumeToken(string $purpose, string $token): ?User
    {
        if ($token === '') {
            return null;
        }

        $userId = ApplicationRuntime::db()->createCommand(
            'UPDATE one_time_tokens SET used_at = now() WHERE purpose = :purpose AND token_hash = :hash AND used_at IS NULL AND expires_at > now() RETURNING user_id',
            [
                ':purpose' => $purpose,
                ':hash' => hash('sha256', $token),
            ]
        )->queryScalar();

        return $userId ? User::findOne(['id' => $userId]) : null;
    }

    private function issuerUrl(string $path): string
    {
        return rtrim((string)ApplicationRuntime::param('issuer'), '/') . $path;
    }
}
