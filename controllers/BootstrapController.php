<?php

namespace app\controllers;

use app\models\Role;
use app\models\User;
use app\services\AuditService;
use app\services\PasswordPolicy;
use Yii;

class BootstrapController extends ApiController
{
    public function actionStatus(): array
    {
        return [
            'initialized' => User::find()->exists(),
        ];
    }

    public function actionCreate(): array
    {
        if (User::find()->exists()) {
            return $this->fail(409, 'system already initialized');
        }

        $body = $this->body();
        $password = (string)($body['password'] ?? '');
        $passwordError = PasswordPolicy::validate($password);

        if ($passwordError !== null) {
            return $this->fail(400, $passwordError);
        }

        $user = new User();
        $user->username = trim((string)($body['username'] ?? ''));
        $user->email = trim((string)($body['email'] ?? ''));
        $user->password_hash = password_hash($password, PASSWORD_DEFAULT);
        $user->status = User::STATUS_ACTIVE;
        $user->source = User::SOURCE_LOCAL;
        $user->email_verified = true;

        if (!$user->username || !$user->email || !$user->save()) {
            return $this->fail(400, 'invalid admin payload', ['details' => $user->errors]);
        }

        $admin = Role::findOne(['code' => 'admin']);
        if ($admin) {
            Yii::$app->db->createCommand()->insert('user_roles', [
                'user_id' => $user->id,
                'role_id' => $admin->id,
            ])->execute();
        }

        AuditService::record($user->id, 'bootstrap_admin_created', 'user', $user->id);
        Yii::$app->response->statusCode = 201;
        return $user->publicData();
    }
}
