<?php

namespace app\services;

use app\models\Role;
use app\models\User;
use Yii;

class BootstrapService
{
    public function status(): array
    {
        return ['initialized' => User::find()->exists()];
    }

    public function createAdmin(array $body): array
    {
        if (User::find()->exists()) {
            return ['status' => 409, 'data' => ['error' => 'system already initialized']];
        }

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

        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->password_hash = password_hash($password, PASSWORD_DEFAULT);
        $user->status = User::STATUS_ACTIVE;
        $user->source = User::SOURCE_LOCAL;
        $user->email_verified = false;

        if (!$user->save()) {
            AuditService::record(null, 'bootstrap_failed', 'user', $username);
            return ['status' => 400, 'data' => ['error' => 'invalid admin payload', 'details' => $user->errors]];
        }

        $admin = Role::findOne(['code' => 'admin']);
        if ($admin) {
            Yii::$app->db->createCommand()->insert('user_roles', [
                'user_id' => $user->id,
                'role_id' => $admin->id,
            ])->execute();
        }

        AuditService::record($user->id, 'bootstrap_completed', 'user', $user->id);
        return ['status' => 201, 'data' => $user->publicData()];
    }
}
