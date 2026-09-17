<?php

namespace app\controllers;

use app\services\LdapService;
use Yii;

class LdapController extends ApiController
{
    private function service(): LdapService
    {
        return new LdapService();
    }

    public function actionListProviders(): array
    {
        $auth = $this->requirePermission('ldap', 'read');
        return is_array($auth) ? $auth : $this->service()->listProviders();
    }

    public function actionCreateProvider(): array
    {
        $auth = $this->requirePermission('ldap', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->service()->createProvider($this->body(), $auth->id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdateProvider(string $id): array
    {
        $auth = $this->requirePermission('ldap', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $result = $this->service()->updateProvider($id, $this->body(), $auth->id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDeleteProvider(string $id): array
    {
        $auth = $this->requirePermission('ldap', 'delete');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        return $this->service()->deleteProvider($id, $auth->id) ? ['status' => 'deleted'] : $this->fail(404, 'provider not found');
    }

    public function actionTest(): array
    {
        $auth = $this->requirePermission('ldap', 'test');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->service()->test($this->body());
        if (!$result['ok']) {
            return $this->fail(400, $result['error']);
        }

        return $result['data'];
    }

    public function actionListGroupMappings(string $id): array
    {
        $auth = $this->requirePermission('ldap', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        return $this->service()->listGroupMappings($id);
    }

    public function actionCreateGroupMapping(string $id): array
    {
        $auth = $this->requirePermission('ldap', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $result = $this->service()->createGroupMapping($id, $this->body(), $auth->id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDeleteGroupMapping(string $id, string $mappingID): array
    {
        $auth = $this->requirePermission('ldap', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($mappingID)) {
            return $this->fail(400, 'invalid mapping id');
        }

        return $this->service()->deleteGroupMapping($mappingID, $auth->id) ? ['status' => 'deleted'] : $this->fail(404, 'mapping not found');
    }
}
