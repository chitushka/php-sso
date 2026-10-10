<?php

namespace app\services;

use app\models\Role;
use app\models\User;

class BootstrapService
{
    private const BOOTSTRAP_LOCK = 1397968719;

    public function status(): array
    {
        return ['initialized' => User::find()->exists()];
    }

    public function createAdmin(array $body): array
    {
        $username = (string)($body['username'] ?? '');
        $email = (string)($body['email'] ?? '');
        if (trim($username) === '' || trim($email) === '') {
            return ['status' => 400, 'data' => ['error' => 'username and email are required']];
        }

        $password = (string)($body['password'] ?? '');
        $passwordError = PasswordPolicy::validate($password);
        if ($passwordError !== null) {
            return ['status' => 400, 'data' => ['error' => $passwordError]];
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            ApplicationRuntime::db()->createCommand(
                'SELECT pg_advisory_xact_lock(:lock_id)',
                [':lock_id' => self::BOOTSTRAP_LOCK]
            )->queryScalar();
            if (User::find()->exists()) {
                $transaction->rollBack();
                return ['status' => 409, 'data' => ['error' => 'system already initialized']];
            }

            $admin = Role::findOne(['code' => 'admin']);
            if (!$admin) {
                throw new \RuntimeException('admin role is missing');
            }

            $user = new User();
            $user->username = $username;
            $user->email = $email;
            $user->password_hash = PasswordHasher::hash($password);
            $user->status = User::STATUS_ACTIVE;
            $user->source = User::SOURCE_LOCAL;
            $user->email_verified = false;
            if (!$user->save()) {
                $details = $user->errors;
                $transaction->rollBack();
                return ['status' => 400, 'data' => ['error' => 'invalid admin payload', 'details' => $details]];
            }

            ApplicationRuntime::db()->createCommand()->insert('user_roles', [
                'user_id' => $user->id,
                'role_id' => $admin->id,
            ])->execute();
            AuditService::record($user->id, 'bootstrap_completed', 'user', $user->id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            AuditService::record(null, 'bootstrap_failed', 'user', $username, true);
            return ['status' => 500, 'data' => ['error' => 'failed to initialize system']];
        }

        return ['status' => 201, 'data' => $user->publicData()];
    }
}
