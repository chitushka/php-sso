<?php

namespace app\services;

use app\components\Rfc3339;
use app\models\LdapGroupMapping;
use app\models\LdapProvider;
use app\models\User;
use Yii;
use yii\db\Expression;
use yii\db\IntegrityException;

class LdapService
{
    public function listProviders(): array
    {
        return array_map(function (LdapProvider $provider) {
            return $this->publicProvider($provider);
        }, LdapProvider::find()->orderBy(['created_at' => SORT_DESC])->all());
    }

    public function createProvider(array $body, ?string $actorId): array
    {
        $provider = new LdapProvider();
        try {
            $this->fillProvider($provider, $body, false);
            if (!$provider->save()) {
                return ['status' => 500, 'data' => ['error' => 'failed to create provider']];
            }
        } catch (\Throwable $exception) {
            return ['status' => 500, 'data' => ['error' => 'failed to create provider']];
        }
        $provider->refresh();

        return ['status' => 201, 'data' => $this->publicProvider($provider)];
    }

    public function updateProvider(string $id, array $body, ?string $actorId): array
    {
        $provider = LdapProvider::findOne(['id' => $id]);
        if (!$provider) {
            return ['status' => 404, 'data' => ['error' => 'provider not found']];
        }

        try {
            $this->fillProvider($provider, $body, true);
            $provider->updated_at = new Expression('now()');
            if (!$provider->save()) {
                return ['status' => 500, 'data' => ['error' => 'failed to update provider']];
            }
        } catch (\Throwable $exception) {
            return ['status' => 500, 'data' => ['error' => 'failed to update provider']];
        }
        $provider->refresh();

        AuditService::record($actorId, 'ldap_provider_updated', 'ldap_provider', $provider->id);
        return ['status' => 200, 'data' => $this->publicProvider($provider)];
    }

    public function deleteProvider(string $id, ?string $actorId): bool
    {
        // Go repository не различает удаление существующего и уже отсутствующего провайдера.
        Yii::$app->db->createCommand()->delete('ldap_providers', ['id' => $id])->execute();
        AuditService::record($actorId, 'ldap_provider_deleted', 'ldap_provider', $id);
        return true;
    }

    public function test(array $body): array
    {
        if (!function_exists('ldap_connect')) {
            return ['ok' => false, 'error' => 'php ldap extension is not installed'];
        }

        $host = trim((string)($body['host'] ?? ''));
        $port = (int)($body['port'] ?? 389);
        $useTls = (bool)($body['use_tls'] ?? false);
        $startTls = (bool)($body['start_tls'] ?? false);
        $bindDn = (string)($body['bind_dn'] ?? '');
        $bindPassword = (string)($body['bind_password'] ?? '');

        if ($host === '' || $bindDn === '' || $bindPassword === '') {
            return ['ok' => false, 'error' => 'host, bind_dn and bind_password are required'];
        }

        if (!$this->ldapEndpointAllowed($host, $useTls, $startTls)) {
            return ['ok' => false, 'error' => 'LDAP host or transport is not allowed'];
        }

        $uri = ($useTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) {
            return ['ok' => false, 'error' => 'ldap connection failed'];
        }
        if (!$this->configureTls($conn, $host)) {
            return ['ok' => false, 'error' => 'ldap TLS configuration failed'];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)(Yii::$app->params['ldapNetworkTimeout'] ?? 5));

        if ($startTls && !@ldap_start_tls($conn)) {
            return ['ok' => false, 'error' => 'ldap start_tls failed'];
        }

        if (!@ldap_bind($conn, $bindDn, $bindPassword)) {
            return ['ok' => false, 'error' => 'ldap bind failed'];
        }

        return ['ok' => true, 'data' => ['status' => 'ok']];
    }

    public function authenticate(string $login, string $password): ?User
    {
        if ($login === '' || $password === '' || !function_exists('ldap_connect')) {
            return null;
        }

        $provider = LdapProvider::find()->where(['enabled' => true])->orderBy(['created_at' => SORT_ASC])->one();
        if (!$provider) {
            return null;
        }

        $user = $this->authenticateWithProvider($provider, $login, $password);
        if ($user === null) {
            AuditService::record(null, 'ldap_login_failed', 'user', $login);
        }

        return $user;
    }

    public function listGroupMappings(string $providerId): array
    {
        return array_map(function (LdapGroupMapping $mapping): array {
            return $this->publicGroupMapping($mapping);
        }, LdapGroupMapping::find()
            ->where(['provider_id' => $providerId])
            ->orderBy(['ldap_group' => SORT_ASC])
            ->all());
    }

    public function createGroupMapping(string $providerId, array $body, ?string $actorId): array
    {
        $mapping = new LdapGroupMapping();
        $mapping->provider_id = $providerId;
        $mapping->ldap_group = trim((string)($body['ldap_group'] ?? ''));
        $mapping->group_id = (string)($body['group_id'] ?? '');

        try {
            if (!$mapping->save()) {
                return ['status' => 500, 'data' => ['error' => 'failed to create mapping']];
            }
        } catch (IntegrityException $exception) {
            if (($exception->errorInfo[0] ?? '') === '23505') {
                return ['status' => 409, 'data' => ['error' => 'mapping already exists']];
            }
            return ['status' => 500, 'data' => ['error' => 'failed to create mapping']];
        } catch (\Throwable $exception) {
            return ['status' => 500, 'data' => ['error' => 'failed to create mapping']];
        }
        $mapping->refresh();

        AuditService::record($actorId, 'ldap_group_mapping_created', 'ldap_provider', $providerId);
        return ['status' => 201, 'data' => $this->publicGroupMapping($mapping)];
    }

    public function deleteGroupMapping(string $mappingId, ?string $actorId): bool
    {
        $mapping = LdapGroupMapping::findOne(['id' => $mappingId]);
        if (!$mapping) {
            return false;
        }

        $mapping->delete();
        AuditService::record($actorId, 'ldap_group_mapping_deleted', 'ldap_group_mapping', $mappingId);
        return true;
    }

    private function authenticateWithProvider(LdapProvider $provider, string $login, string $password): ?User
    {
        $conn = $this->connect($provider);
        if (!$conn) {
            return null;
        }

        $bindPassword = (new CryptoService())->decrypt((string)$provider->bind_password);
        if ($bindPassword === '' || !@ldap_bind($conn, (string)$provider->bind_dn, $bindPassword)) {
            return null;
        }

        $filter = str_replace(
            ['{username}', '{login}'],
            $this->escapeFilterValue($login),
            (string)$provider->user_filter
        );
        $search = @ldap_search($conn, (string)$provider->base_dn, $filter, [
            (string)$provider->username_attribute,
            (string)$provider->email_attribute,
            (string)$provider->display_name_attribute,
            (string)$provider->group_attribute,
            'dn',
        ]);
        if (!$search) {
            return null;
        }

        $entries = @ldap_get_entries($conn, $search);
        if (!is_array($entries) || (int)($entries['count'] ?? 0) !== 1) {
            return null;
        }

        $entry = $entries[0];
        $userDn = (string)($entry['dn'] ?? '');
        if ($userDn === '' || !@ldap_bind($conn, $userDn, $password)) {
            return null;
        }

        $user = $this->upsertLdapUser($provider, $entry, $userDn, $login);
        if ($user === null) {
            return null;
        }

        $this->syncMappedGroups($provider, $user, $this->attributeValues($entry, (string)$provider->group_attribute));
        AuditService::record($user->id, 'ldap_login_success', 'user', $user->id);

        return $user;
    }

    private function connect(LdapProvider $provider)
    {
        if (!$this->ldapEndpointAllowed((string)$provider->host, (bool)$provider->use_tls, (bool)$provider->start_tls)) {
            return null;
        }
        $uri = ($provider->use_tls ? 'ldaps://' : 'ldap://') . $provider->host . ':' . $provider->port;
        $conn = @ldap_connect($uri);
        if (!$conn) {
            return null;
        }
        if (!$this->configureTls($conn, (string)$provider->host)) {
            return null;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)(Yii::$app->params['ldapNetworkTimeout'] ?? 5));

        if ($provider->start_tls && !@ldap_start_tls($conn)) {
            return null;
        }

        return $conn;
    }

    private function ldapEndpointAllowed(string $host, bool $useTls, bool $startTls): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if (
            $host === ''
            || preg_match('/[\x00-\x20]/', $host)
            || strpos($host, '\\') !== false
            || strpos($host, '/') !== false
        ) {
            return false;
        }
        $allowed = array_filter(array_map(static function (string $value): string {
            return strtolower(rtrim(trim($value), '.'));
        }, explode(',', (string)(Yii::$app->params['ldapAllowedHosts'] ?? ''))));
        if ($allowed === [] && strtolower((string)getenv('SSO_ENV')) === 'prod') {
            return false;
        }
        if ($allowed !== [] && !in_array($host, $allowed, true)) {
            return false;
        }
        if (!(bool)(Yii::$app->params['ldapAllowInsecure'] ?? false) && !$useTls && !$startTls) {
            return false;
        }
        return true;
    }

    private function configureTls($connection, string $host): bool
    {
        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_DEMAND')) {
            if (!@ldap_set_option($connection, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) {
                return false;
            }
        }
        if (defined('LDAP_OPT_X_TLS_REQUIRE_SAN') && defined('LDAP_OPT_X_TLS_DEMAND')) {
            if (!@ldap_set_option($connection, LDAP_OPT_X_TLS_REQUIRE_SAN, LDAP_OPT_X_TLS_DEMAND)) {
                return false;
            }
        }
        if (
            defined('LDAP_OPT_X_TLS_HOSTNAME')
            && !@ldap_set_option($connection, LDAP_OPT_X_TLS_HOSTNAME, $host)
        ) {
            return false;
        }
        $ca = (string)(Yii::$app->params['ldapCaCertificate'] ?? '');
        if ($ca !== '' && defined('LDAP_OPT_X_TLS_CACERTFILE')) {
            if (!@ldap_set_option($connection, LDAP_OPT_X_TLS_CACERTFILE, $ca)) {
                return false;
            }
        }
        if (defined('LDAP_OPT_X_TLS_NEWCTX') && !@ldap_set_option($connection, LDAP_OPT_X_TLS_NEWCTX, 0)) {
            return false;
        }

        return true;
    }

    private function upsertLdapUser(LdapProvider $provider, array $entry, string $userDn, string $login): ?User
    {
        $username = $this->firstAttribute($entry, (string)$provider->username_attribute) ?: $login;
        $email = $this->firstAttribute($entry, (string)$provider->email_attribute) ?: $username . '@ldap.local';
        $user = User::findOne(['ldap_provider_id' => $provider->id, 'ldap_dn' => $userDn]);
        $existingByUsername = User::findOne(['username' => $username]);
        if (!$user && $existingByUsername) {
            $user = $existingByUsername;
        }

        if (!$user) {
            $user = new User();
            $user->username = $username;
            $user->status = User::STATUS_ACTIVE;
            $user->source = User::SOURCE_LDAP;
            $user->email_verified = false;
        }

        $user->email = $email;
        $user->source = User::SOURCE_LDAP;
        $user->ldap_provider_id = $provider->id;
        $user->ldap_dn = $userDn;
        $user->save(false);

        return $user;
    }

    private function syncMappedGroups(LdapProvider $provider, User $user, array $ldapGroups): void
    {
        Yii::$app->db->createCommand()->delete('user_groups', [
            'user_id' => $user->id,
            'source' => 'ldap',
        ])->execute();

        if (empty($ldapGroups)) {
            return;
        }

        $mappings = LdapGroupMapping::find()
            ->where(['provider_id' => $provider->id, 'ldap_group' => $ldapGroups])
            ->all();

        foreach ($mappings as $mapping) {
            $this->insertIgnore('user_groups', [
                'user_id' => $user->id,
                'group_id' => $mapping->group_id,
                'source' => 'ldap',
            ]);
        }
    }

    private function firstAttribute(array $entry, string $attribute): string
    {
        $values = $this->attributeValues($entry, $attribute);
        return $values[0] ?? '';
    }

    private function attributeValues(array $entry, string $attribute): array
    {
        $key = strtolower($attribute);
        if (!isset($entry[$key]) || !is_array($entry[$key])) {
            return [];
        }

        $values = [];
        $count = (int)($entry[$key]['count'] ?? 0);
        for ($index = 0; $index < $count; $index++) {
            if (isset($entry[$key][$index]) && $entry[$key][$index] !== '') {
                $values[] = (string)$entry[$key][$index];
            }
        }

        return $values;
    }

    private function escapeFilterValue(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return strtr($value, [
            '\\' => '\\5c',
            '*' => '\\2a',
            '(' => '\\28',
            ')' => '\\29',
            "\x00" => '\\00',
        ]);
    }

    private function fillProvider(LdapProvider $provider, array $body, bool $isUpdate): void
    {
        foreach (['name', 'host', 'bind_dn', 'base_dn', 'user_filter', 'username_attribute', 'email_attribute', 'display_name_attribute', 'group_attribute'] as $field) {
            // Go декодирует вход в новую Provider struct: отсутствующие поля получают zero values.
            $provider->{$field} = (string)($body[$field] ?? '');
        }

        $provider->port = (int)($body['port'] ?? 0);
        $provider->use_tls = (bool)($body['use_tls'] ?? false);
        $provider->start_tls = (bool)($body['start_tls'] ?? false);
        $provider->enabled = (bool)($body['enabled'] ?? false);

        if (!$isUpdate) {
            if ($provider->port === 0) {
                $provider->port = 389;
            }
            if ($provider->user_filter === '') {
                $provider->user_filter = '(&(objectClass=user)(sAMAccountName={username}))';
            }
            if ($provider->username_attribute === '') {
                $provider->username_attribute = 'sAMAccountName';
            }
            if ($provider->email_attribute === '') {
                $provider->email_attribute = 'mail';
            }
            if ($provider->display_name_attribute === '') {
                $provider->display_name_attribute = 'displayName';
            }
        }

        if (!$isUpdate || !empty($body['bind_password'])) {
            $provider->bind_password = (new CryptoService())->encrypt((string)($body['bind_password'] ?? ''));
        }
    }

    private function publicProvider(LdapProvider $provider): array
    {
        $data = $provider->toArray();
        unset($data['bind_password']);
        $data['created_at'] = Rfc3339::format($provider->created_at);
        $data['updated_at'] = Rfc3339::format($provider->updated_at);
        return $data;
    }

    private function publicGroupMapping(LdapGroupMapping $mapping): array
    {
        $data = $mapping->toArray();
        $data['created_at'] = Rfc3339::format($mapping->created_at);
        return $data;
    }

    private function insertIgnore(string $table, array $data): void
    {
        $columns = array_keys($data);
        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $data[$column];
        }

        Yii::$app->db->createCommand(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ') ON CONFLICT DO NOTHING',
            $params
        )->execute();
    }
}
