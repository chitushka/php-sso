<?php

namespace app\controllers;

use app\services\LdapService;
use app\services\LdapProviderAdminService;
use Yii;
use yii\base\Module;

class LdapController extends ApiController
{
    private LdapService $ldap;
    private LdapProviderAdminService $providers;

    public function __construct(
        string $id,
        Module $module,
        LdapService $ldap,
        LdapProviderAdminService $providers,
        array $config = []
    ) {
        $this->ldap = $ldap;
        $this->providers = $providers;
        parent::__construct($id, $module, $config);
    }

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

    public function actionListProviders(): array
    {
        $auth = $this->requirePermission('ldap', 'read');
        return is_array($auth) ? $auth : $this->providers->list();
    }

    public function actionCreateProvider(): array
    {
        $auth = $this->requirePermission('ldap', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->providers->create(
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

        $result = $this->providers->update(
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

        return $this->providers->delete($id, $auth->id) ? ['status' => 'deleted'] : $this->fail(404, 'provider not found');
    }

    public function actionTest(): array
    {
        $auth = $this->requirePermission('ldap', 'test');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->ldap->test($this->body(self::PROVIDER_FIELDS, self::PROVIDER_TYPES));
        if (!$result->isSuccess()) {
            return $this->fail($result->status, $result->error->message);
        }

        return $result->data;
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

        return $this->providers->listMappings($id);
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

        $result = $this->providers->createMapping($id, $body, $auth->id);
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

        return $this->providers->deleteMapping($mappingID, $auth->id) ? ['status' => 'deleted'] : $this->fail(404, 'mapping not found');
    }
}
