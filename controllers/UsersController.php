<?php

namespace app\controllers;

use app\services\UserService;
use Yii;
use yii\base\Module;

final class UsersController extends ApiController
{
    private UserService $users;

    public function __construct(
        string $id,
        Module $module,
        UserService $users,
        array $config = []
    ) {
        $this->users = $users;
        parent::__construct($id, $module, $config);
    }

    public function actionIndex(): array
    {
        $auth = $this->requirePermission('users', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        $limit = $this->parseQueryInteger((string)Yii::$app->request->get('limit', ''));
        if ($limit <= 0 || $limit > 100) {
            $limit = 50;
        }
        $offset = max($this->parseQueryInteger((string)Yii::$app->request->get('offset', '')), 0);

        return $this->respond($this->users->list($limit, $offset));
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

        return $this->respond($this->users->find($this->canonicalUuid($id)));
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

        return $this->respond($this->users->create($body));
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

        $fields = ['username', 'email', 'status', 'first_name', 'last_name', 'attributes'];
        $body = $this->body($fields, [
            'username' => 'string',
            'email' => 'string',
            'status' => 'string',
            'first_name' => 'string',
            'last_name' => 'string',
            'attributes' => 'string_map',
        ]);

        return $this->respond($this->users->update($this->canonicalUuid($id), $body));
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

        return $this->respond($this->users->delete($this->canonicalUuid($id)));
    }
}
