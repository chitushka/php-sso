<?php

namespace app\controllers;

use app\services\LdapService;
use Yii;

class LdapController extends ApiController
{
    private const PROVIDER_FIELDS = [
        'id',
        'name',
        'host',
        'port',
        'use_tls',
        'start_tls',
        'bind_dn',
        'bind_password',
        'base_dn',
        'user_filter',
        'username_attribute',
        'email_attribute',
        'display_name_attribute',
        'group_attribute',
        'enabled',
        'created_at',
        'updated_at',
    ];

    private const GROUP_MAPPING_FIELDS = [
        'id',
        'provider_id',
        'ldap_group',
        'group_id',
        'created_at',
    ];

    private const PROVIDER_TYPES = [
        'id' => 'uuid',
        'name' => 'string',
        'host' => 'string',
        'port' => 'int',
        'use_tls' => 'bool',
        'start_tls' => 'bool',
        'bind_dn' => 'string',
        'bind_password' => 'string',
        'base_dn' => 'string',
        'user_filter' => 'string',
        'username_attribute' => 'string',
        'email_attribute' => 'string',
        'display_name_attribute' => 'string',
        'group_attribute' => 'string',
        'enabled' => 'bool',
        'created_at' => 'time',
        'updated_at' => 'time',
    ];

    private const GROUP_MAPPING_TYPES = [
        'id' => 'uuid',
        'provider_id' => 'uuid',
        'ldap_group' => 'string',
        'group_id' => 'uuid',
        'created_at' => 'time',
    ];

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

        $result = $this->service()->createProvider(
            $this->body(self::PROVIDER_FIELDS, self::PROVIDER_TYPES),
            $auth->id
        );
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
        $id = $this->canonicalUuid($id);

        $result = $this->service()->updateProvider(
            $id,
            $this->body(self::PROVIDER_FIELDS, self::PROVIDER_TYPES),
            $auth->id
        );
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
        $id = $this->canonicalUuid($id);

        return $this->service()->deleteProvider($id, $auth->id) ? ['status' => 'deleted'] : $this->fail(404, 'provider not found');
    }

    public function actionTest(): array
    {
        $auth = $this->requirePermission('ldap', 'test');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->service()->test($this->body(self::PROVIDER_FIELDS, self::PROVIDER_TYPES));
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
        $id = $this->canonicalUuid($id);

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
        $id = $this->canonicalUuid($id);

        $body = $this->body(self::GROUP_MAPPING_FIELDS, self::GROUP_MAPPING_TYPES);

        $result = $this->service()->createGroupMapping($id, $body, $auth->id);
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
        $mappingID = $this->canonicalUuid($mappingID);

        return $this->service()->deleteGroupMapping($mappingID, $auth->id) ? ['status' => 'deleted'] : $this->fail(404, 'mapping not found');
    }
}
