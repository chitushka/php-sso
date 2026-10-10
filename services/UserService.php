<?php

namespace app\services;

use app\models\OneTimeToken;
use app\models\RefreshToken;
use app\models\Session;
use app\models\User;
use app\services\contracts\ClockInterface;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use yii\db\IntegrityException;

final class UserService
{
    private AdminInvariantService $adminInvariant;
    private ClockInterface $clock;

    public function __construct(
        ?AdminInvariantService $adminInvariant = null,
        ?ClockInterface $clock = null
    ) {
        $this->adminInvariant = $adminInvariant ?? new AdminInvariantService();
        $this->clock = $clock ?? new SystemClock();
    }

    public function list(int $limit, int $offset): ServiceResult
    {
        $users = User::find()
            ->orderBy(['created_at' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return ServiceResult::success(array_map(static function (User $user): array {
            return $user->publicData();
        }, $users));
    }

    public function find(string $id): ServiceResult
    {
        $user = User::findOne(['id' => $id]);
        return $user
            ? ServiceResult::success($user->publicData())
            : $this->failure('user_not_found', 'user not found', 404);
    }

    public function create(array $body): ServiceResult
    {
        $username = trim((string)($body['username'] ?? ''));
        $email = User::normalizeEmail((string)($body['email'] ?? ''));
        if ($username === '' || $email === '') {
            return $this->failure('invalid_user', 'username and email are required', 400);
        }

        if (
            User::find()->where(['username' => $username])->exists()
            || User::find()->where(['email' => $email])->exists()
        ) {
            return $this->failure('user_conflict', 'user already exists', 409);
        }

        $password = (string)($body['password'] ?? '');
        $passwordError = PasswordPolicy::validate($password);
        if ($passwordError !== null) {
            return $this->failure('invalid_password', $passwordError, 400);
        }

        $user = new User();
        $this->fillUser($user, $body);
        $user->email = $email;
        $user->password_hash = PasswordHasher::hash($password);
        $user->source = User::SOURCE_LOCAL;

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if (!$user->save()) {
                $transaction->rollBack();
                return $this->failure(
                    'invalid_user',
                    'invalid user payload',
                    400,
                    ['details' => $user->errors]
                );
            }
            AuditService::record(null, 'user_created', 'user', $user->id, true);
            $transaction->commit();
        } catch (IntegrityException $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('user_conflict', 'user already exists', 409);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('user_create_failed', 'failed to create user', 500);
        }

        return ServiceResult::success($user->publicData(), 201);
    }

    public function update(string $id, array $body): ServiceResult
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return $this->failure('user_not_found', 'user not found', 404);
        }

        $previousStatus = (string)$user->status;
        $previousEmail = User::normalizeEmail((string)$user->email);
        $nextEmail = User::normalizeEmail((string)($body['email'] ?? ''));
        $emailChanged = $previousEmail !== $nextEmail;
        $statusChanged = $previousStatus !== trim((string)($body['status'] ?? ''));
        $removesAdminAccess = $previousStatus === User::STATUS_ACTIVE
            && trim((string)($body['status'] ?? '')) !== User::STATUS_ACTIVE
            && $this->adminInvariant->userIsActiveAdmin($id);

        $this->fillUser($user, $body);
        $user->email = $nextEmail;
        $user->updated_at = $this->databaseNow();
        if ($emailChanged) {
            $user->email_verified = false;
        }
        if (
            User::find()->where(['username' => $user->username])->andWhere(['<>', 'id', $id])->exists()
            || User::find()->where(['email' => $user->email])->andWhere(['<>', 'id', $id])->exists()
        ) {
            return $this->failure('user_conflict', 'username or email already exists', 409);
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if ($removesAdminAccess) {
                $this->adminInvariant->lock();
            }
            if ($statusChanged || $emailChanged) {
                $this->revokeCredentials($user);
            }
            if (!$user->save()) {
                $transaction->rollBack();
                return $this->failure(
                    'invalid_user',
                    'invalid user payload',
                    400,
                    ['details' => $user->errors]
                );
            }

            if ($emailChanged) {
                OneTimeToken::deleteAll([
                    'user_id' => $user->id,
                    'purpose' => OneTimeToken::PURPOSE_EMAIL_VERIFY,
                ]);
            }

            if ($removesAdminAccess && !$this->adminInvariant->hasActiveAdmin()) {
                $transaction->rollBack();
                return $this->failure(
                    'last_administrator',
                    'at least one active administrator is required',
                    409
                );
            }

            AuditService::record(null, 'user_updated', 'user', $id, true);
            $transaction->commit();
            $user->refresh();
        } catch (IntegrityException $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('user_conflict', 'username or email already exists', 409);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('user_update_failed', 'failed to update user', 500);
        }

        return ServiceResult::success($user->publicData());
    }

    public function delete(string $id): ServiceResult
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return $this->failure('user_not_found', 'user not found', 404);
        }

        $removesAdminAccess = $this->adminInvariant->userIsActiveAdmin($id);
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if ($removesAdminAccess) {
                $this->adminInvariant->lock();
            }
            $user->status = User::STATUS_DELETED;
            $user->updated_at = $this->databaseNow();
            $this->revokeCredentials($user);
            if (!$user->save(false, ['status', 'tokens_invalid_before', 'updated_at'])) {
                throw new \RuntimeException('failed to delete user');
            }
            if ($removesAdminAccess && !$this->adminInvariant->hasActiveAdmin()) {
                $transaction->rollBack();
                return $this->failure(
                    'last_administrator',
                    'at least one active administrator is required',
                    409
                );
            }
            AuditService::record(null, 'user_deleted', 'user', $id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('user_delete_failed', 'failed to delete user', 500);
        }

        return ServiceResult::success(['status' => 'deleted']);
    }

    private function fillUser(User $user, array $body): void
    {
        foreach (['username', 'email', 'first_name', 'last_name', 'status'] as $field) {
            if (array_key_exists($field, $body)) {
                $user->{$field} = trim((string)$body[$field]);
            }
        }

        if (!$user->status) {
            $user->status = User::STATUS_ACTIVE;
        }
        if (array_key_exists('attributes', $body)) {
            $user->attributes = json_encode((array)$body['attributes']);
        }
    }

    private function revokeCredentials(User $user): void
    {
        $now = $this->databaseNow();
        $user->tokens_invalid_before = $now;
        Session::updateAll(['revoked_at' => $now], ['user_id' => $user->id, 'revoked_at' => null]);
        RefreshToken::updateAll(['revoked_at' => $now], ['user_id' => $user->id, 'revoked_at' => null]);
    }

    private function databaseNow(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:sP');
    }

    private function failure(
        string $code,
        string $message,
        int $status,
        array $details = []
    ): ServiceResult {
        return ServiceResult::failure(new ServiceError($code, $message, $status, $details));
    }
}
