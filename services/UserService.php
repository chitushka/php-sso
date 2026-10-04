<?php

namespace app\services;

use app\models\OneTimeToken;
use app\models\RefreshToken;
use app\models\Session;
use app\models\User;
use Yii;
use yii\db\Expression;
use yii\db\IntegrityException;

class UserService
{
    public function update(string $id, array $body): array
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return ['status' => 404, 'data' => ['error' => 'user not found']];
        }

        $previousStatus = (string)$user->status;
        $previousEmail = User::normalizeEmail((string)$user->email);
        $nextEmail = User::normalizeEmail((string)($body['email'] ?? ''));
        $emailChanged = $previousEmail !== $nextEmail;
        $statusChanged = $previousStatus !== trim((string)($body['status'] ?? ''));
        $removesAdminAccess = $previousStatus === User::STATUS_ACTIVE
            && trim((string)($body['status'] ?? '')) !== User::STATUS_ACTIVE
            && (new AdminInvariantService())->userIsActiveAdmin($id);

        $user->username = trim((string)($body['username'] ?? ''));
        $user->email = $nextEmail;
        $user->status = trim((string)($body['status'] ?? ''));
        $user->first_name = trim((string)($body['first_name'] ?? ''));
        $user->last_name = trim((string)($body['last_name'] ?? ''));
        $user->attributes = json_encode((array)($body['attributes'] ?? []));
        $user->updated_at = new Expression('now()');
        if ($emailChanged) {
            $user->email_verified = false;
        }
        if (
            User::find()->where(['username' => $user->username])->andWhere(['<>', 'id', $id])->exists()
            || User::find()->where(['email' => $user->email])->andWhere(['<>', 'id', $id])->exists()
        ) {
            return ['status' => 409, 'data' => ['error' => 'username or email already exists']];
        }
        if ($statusChanged || $emailChanged) {
            $this->revokeCredentials($user);
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $guard = new AdminInvariantService();
            if ($removesAdminAccess) {
                $guard->lock();
            }
            if (!$user->save()) {
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid user payload', 'details' => $user->errors]];
            }

            if ($emailChanged) {
                OneTimeToken::deleteAll([
                    'user_id' => $user->id,
                    'purpose' => OneTimeToken::PURPOSE_EMAIL_VERIFY,
                ]);
            }

            if ($removesAdminAccess && !$guard->hasActiveAdmin()) {
                $transaction->rollBack();
                return ['status' => 409, 'data' => ['error' => 'at least one active administrator is required']];
            }

            AuditService::record(null, 'user_updated', 'user', $id, true);
            $transaction->commit();
            $user->refresh();
        } catch (IntegrityException $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 409, 'data' => ['error' => 'username or email already exists']];
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to update user']];
        }

        return ['status' => 200, 'data' => $user->publicData()];
    }

    public function delete(string $id): array
    {
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return ['status' => 404, 'data' => ['error' => 'user not found']];
        }

        $guard = new AdminInvariantService();
        $removesAdminAccess = $guard->userIsActiveAdmin($id);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ($removesAdminAccess) {
                $guard->lock();
            }
            $user->status = User::STATUS_DELETED;
            $user->updated_at = new Expression('now()');
            $this->revokeCredentials($user);
            if (!$user->save(false, ['status', 'tokens_invalid_before', 'updated_at'])) {
                throw new \RuntimeException('failed to delete user');
            }
            if ($removesAdminAccess && !$guard->hasActiveAdmin()) {
                $transaction->rollBack();
                return ['status' => 409, 'data' => ['error' => 'at least one active administrator is required']];
            }
            AuditService::record(null, 'user_deleted', 'user', $id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ['status' => 500, 'data' => ['error' => 'failed to delete user']];
        }

        return ['status' => 200, 'data' => ['status' => 'deleted']];
    }

    private function revokeCredentials(User $user): void
    {
        $user->tokens_invalid_before = new Expression('now()');
        Session::updateAll(
            ['revoked_at' => new Expression('now()')],
            ['user_id' => $user->id, 'revoked_at' => null]
        );
        RefreshToken::updateAll(
            ['revoked_at' => new Expression('now()')],
            ['user_id' => $user->id, 'revoked_at' => null]
        );
    }
}
