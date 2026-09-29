<?php

namespace app\controllers;

use app\models\User;
use app\services\AuditService;
use app\services\PasswordPolicy;
use app\services\PasswordHasher;
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

        $limit = $this->parseGoInt((string)Yii::$app->request->get('limit', ''));
        if ($limit <= 0 || $limit > 100) {
            $limit = 50;
        }
        $offset = max($this->parseGoInt((string)Yii::$app->request->get('offset', '')), 0);

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
        $id = $this->canonicalUuid($id);

        $user = User::findOne(['id' => $id]);
        return $user ? $user->publicData() : $this->fail(404, 'user not found');
    }

    public function actionCreate(): array
    {
        $auth = $this->requirePermission('users', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $fields = [
            'username',
            'email',
            'password',
            'status',
            'first_name',
            'last_name',
            'attributes',
        ];
        $body = $this->body($fields, [
            'username' => 'string',
            'email' => 'string',
            'password' => 'string',
            'status' => 'string',
            'first_name' => 'string',
            'last_name' => 'string',
            'attributes' => 'string_map',
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
        $user->password_hash = PasswordHasher::hash($password);
        $user->source = User::SOURCE_LOCAL;

        if (!$user->save()) {
            if ($user->hasErrors('username')) {
                return $this->fail(409, 'user already exists');
            }

            return $this->fail(400, 'invalid user payload', ['details' => $user->errors]);
        }

        AuditService::record(null, 'user_created', 'user', $user->id, true);
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
        $id = $this->canonicalUuid($id);

        $fields = [
            'username',
            'email',
            'status',
            'first_name',
            'last_name',
            'attributes',
        ];
        $body = $this->body($fields, [
            'username' => 'string',
            'email' => 'string',
            'status' => 'string',
            'first_name' => 'string',
            'last_name' => 'string',
            'attributes' => 'string_map',
        ]);
        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return $this->fail(500, 'failed to update user');
        }

        $previousStatus = (string)$user->status;
        $user->username = trim((string)($body['username'] ?? ''));
        $user->email = trim((string)($body['email'] ?? ''));
        $user->status = trim((string)($body['status'] ?? ''));
        $user->first_name = trim((string)($body['first_name'] ?? ''));
        $user->last_name = trim((string)($body['last_name'] ?? ''));
        $user->attributes = json_encode((array)($body['attributes'] ?? []));

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ($previousStatus !== (string)$user->status) {
                $user->tokens_invalid_before = new Expression('now()');
            }
            if (!$user->save()) {
                $transaction->rollBack();
                return $this->fail(500, 'failed to update user');
            }
            if ($previousStatus !== (string)$user->status) {
                Yii::$app->db->createCommand()->update(
                    'sessions', ['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]
                )->execute();
                Yii::$app->db->createCommand()->update(
                    'refresh_tokens', ['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]
                )->execute();
            }
            AuditService::record(null, 'user_updated', 'user', $id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->fail(500, 'failed to update user');
        }

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
        $id = $this->canonicalUuid($id);

        $user = User::findOne(['id' => $id]);
        if (!$user) {
            return $this->fail(404, 'user not found');
        }

        $user->status = User::STATUS_DELETED;
        $user->tokens_invalid_before = new Expression('now()');
        $user->save(false, ['status', 'tokens_invalid_before']);
        AuditService::record(null, 'user_deleted', 'user', $id, true);

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
