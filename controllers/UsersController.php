<?php

namespace app\controllers;

use app\models\User;
use app\services\AuditService;
use app\services\PasswordPolicy;
use Yii;
use yii\db\Expression;

class UsersController extends ApiController
{
    public function actionIndex(): array
    {
        $auth = $this->requirePermission('users', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        $limit = (int)Yii::$app->request->get('limit', 50);
        if ($limit <= 0 || $limit > 100) {
            $limit = 50;
        }
        $offset = max((int)Yii::$app->request->get('offset', 0), 0);

        return array_map(static function (User $user) {
            return $user->publicData();
        }, User::find()->orderBy(['created_at' => SORT_DESC])->limit($limit)->offset($offset)->all());
    }

    public function actionView(string $id): array
    {
        $auth = $this->requirePermission('users', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $user = User::findOne(['id' => $id]);
        return $user ? $user->publicData() : $this->fail(404, 'user not found');
    }

    public function actionCreate(): array
    {
        $auth = $this->requirePermission('users', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $body = $this->body([
            'username',
            'email',
            'password',
            'status',
            'first_name',
            'last_name',
            'attributes',
        ]);
        if (trim((string)($body['username'] ?? '')) === '' || trim((string)($body['email'] ?? '')) === '') {
            return $this->fail(400, 'username and email are required');
        }

        if (User::find()->where(['username' => trim((string)$body['username'])])->exists()) {
            return $this->fail(409, 'user already exists');
        }

        $password = (string)($body['password'] ?? '');
        $passwordError = PasswordPolicy::validate($password);
        if ($passwordError !== null) {
            return $this->fail(400, $passwordError);
        }

        $user = new User();
        $this->fillUser($user, $body);
        $user->password_hash = password_hash($password, PASSWORD_DEFAULT);
        $user->source = User::SOURCE_LOCAL;

        if (!$user->save()) {
            if ($user->hasErrors('username')) {
                return $this->fail(409, 'user already exists');
            }

            return $this->fail(400, 'invalid user payload', ['details' => $user->errors]);
        }

        AuditService::record($auth->id, 'user_created', 'user', $user->id);
        Yii::$app->response->statusCode = 201;
        return $user->publicData();
    }

    public function actionUpdate(string $id): array
    {
        $auth = $this->requirePermission('users', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $body = $this->body([
            'username',
            'email',
            'status',
            'first_name',
            'last_name',
            'attributes',
        ]);
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return $this->fail(404, 'user not found');
        }

        $this->fillUser($user, $body);

        if (!$user->save()) {
            return $this->fail(400, 'invalid user payload', ['details' => $user->errors]);
        }

        AuditService::record($auth->id, 'user_updated', 'user', $user->id);
        return $user->publicData();
    }

    public function actionDelete(string $id): array
    {
        $auth = $this->requirePermission('users', 'delete');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return $this->fail(404, 'user not found');
        }

        $user->status = User::STATUS_DELETED;
        $user->tokens_invalid_before = new Expression('now()');
        $user->save(false, ['status', 'tokens_invalid_before']);
        AuditService::record($auth->id, 'user_deleted', 'user', $id);

        return ['status' => 'deleted'];
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

        if (array_key_exists('email_verified', $body)) {
            $user->email_verified = (bool)$body['email_verified'];
        }

        if (array_key_exists('attributes', $body)) {
            $user->attributes = json_encode((array)$body['attributes']);
        }
    }
}
