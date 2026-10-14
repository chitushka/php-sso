<?php

namespace app\services;

use app\models\LdapGroupMapping;
use app\models\LdapProvider;
use app\models\User;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;

class LdapService
{
    private ?CryptoService $crypto;
    private ?LdapProviderAdminService $providers;

    public function __construct(?CryptoService $crypto = null, ?LdapProviderAdminService $providers = null)
    {
        $this->crypto = $crypto;
        $this->providers = $providers;
    }

    public function listProviders(): array
    {
        return $this->providers()->list();
    }

    public function createProvider(array $body, ?string $actorId): ServiceResult
    {
        return $this->providers()->create($body, $actorId);
    }

    public function updateProvider(string $id, array $body, ?string $actorId): ServiceResult
    {
        return $this->providers()->update($id, $body, $actorId);
    }

    public function deleteProvider(string $id, ?string $actorId): bool
    {
        return $this->providers()->delete($id, $actorId);
    }

    public function test(array $body): ServiceResult
    {
        if (!function_exists('ldap_connect')) {
            return $this->testFailure('php_ldap_unavailable', 'php ldap extension is not installed');
        }

        $host = trim((string)($body['host'] ?? ''));
        $port = (int)($body['port'] ?? 389);
        $useTls = (bool)($body['use_tls'] ?? false);
        $startTls = (bool)($body['start_tls'] ?? false);
        $bindDn = (string)($body['bind_dn'] ?? '');
        $bindPassword = (string)($body['bind_password'] ?? '');

        if ($host === '' || $bindDn === '' || $bindPassword === '') {
            return $this->testFailure('invalid_ldap_configuration', 'host, bind_dn and bind_password are required');
        }

        if (!$this->ldapEndpointAllowed($host, $useTls, $startTls)) {
            return $this->testFailure('ldap_endpoint_not_allowed', 'LDAP host or transport is not allowed');
        }

        $uri = ($useTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) {
            return $this->testFailure('ldap_connection_failed', 'ldap connection failed');
        }
        if (!$this->configureTls($conn, $host)) {
            return $this->testFailure('ldap_tls_configuration_failed', 'ldap TLS configuration failed');
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)ApplicationRuntime::param('ldapNetworkTimeout', 5));

        if ($startTls && !@ldap_start_tls($conn)) {
            return $this->testFailure('ldap_start_tls_failed', 'ldap start_tls failed');
        }

        if (!@ldap_bind($conn, $bindDn, $bindPassword)) {
            return $this->testFailure('ldap_bind_failed', 'ldap bind failed');
        }

        return ServiceResult::success(['status' => 'ok']);
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

    public function listGroupMappings(string $providerId): ServiceResult
    {
        return $this->providers()->listMappings($providerId);
    }

    public function createGroupMapping(string $providerId, array $body, ?string $actorId): ServiceResult
    {
        return $this->providers()->createMapping($providerId, $body, $actorId);
    }

    public function deleteGroupMapping(string $providerId, string $mappingId, ?string $actorId): bool
    {
        return $this->providers()->deleteMapping($providerId, $mappingId, $actorId);
    }

    private function authenticateWithProvider(LdapProvider $provider, string $login, string $password): ?User
    {
        $conn = $this->connect($provider);
        if (!$conn) {
            return null;
        }

        $bindPassword = $this->crypto()->decrypt((string)$provider->bind_password);
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
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int)ApplicationRuntime::param('ldapNetworkTimeout', 5));

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
        }, explode(',', (string)ApplicationRuntime::param('ldapAllowedHosts', ''))));
        if ($allowed === [] && strtolower((string)getenv('SSO_ENV')) === 'prod') {
            return false;
        }
        if ($allowed !== [] && !in_array($host, $allowed, true)) {
            return false;
        }
        if (!(bool)ApplicationRuntime::param('ldapAllowInsecure', false) && !$useTls && !$startTls) {
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
        $ca = (string)ApplicationRuntime::param('ldapCaCertificate', '');
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
        ApplicationRuntime::db()->createCommand()->delete('user_groups', [
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

    private function crypto(): CryptoService
    {
        return $this->crypto ??= new CryptoService();
    }

    private function providers(): LdapProviderAdminService
    {
        return $this->providers ??= new LdapProviderAdminService($this->crypto());
    }

    private function testFailure(string $code, string $message): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, 400));
    }

    private function insertIgnore(string $table, array $data): void
    {
        $columns = array_keys($data);
        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $data[$column];
        }

        ApplicationRuntime::db()->createCommand(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ') ON CONFLICT DO NOTHING',
            $params
        )->execute();
    }
}
