<?php

namespace app\services;

use app\models\LdapGroupMapping;
use app\models\LdapProvider;
use Yii;

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
        $this->fillProvider($provider, $body, false);

        if (!$provider->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid provider payload', 'details' => $provider->errors]];
        }

        AuditService::record($actorId, 'ldap_provider_created', 'ldap_provider', $provider->id);
        return ['status' => 201, 'data' => $this->publicProvider($provider)];
    }

    public function updateProvider(string $id, array $body, ?string $actorId): array
    {
        $provider = LdapProvider::findOne(['id' => $id]);
        if (!$provider) {
            return ['status' => 404, 'data' => ['error' => 'provider not found']];
        }

        $this->fillProvider($provider, $body, true);

        if (!$provider->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid provider payload', 'details' => $provider->errors]];
        }

        AuditService::record($actorId, 'ldap_provider_updated', 'ldap_provider', $provider->id);
        return ['status' => 200, 'data' => $this->publicProvider($provider)];
    }

    public function deleteProvider(string $id, ?string $actorId): bool
    {
        $provider = LdapProvider::findOne(['id' => $id]);
        if (!$provider) {
            return false;
        }

        $provider->delete();
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

        $uri = ($useTls ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (!$conn) {
            return ['ok' => false, 'error' => 'ldap connection failed'];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

        if ($startTls && !@ldap_start_tls($conn)) {
            return ['ok' => false, 'error' => 'ldap start_tls failed'];
        }

        if (!@ldap_bind($conn, $bindDn, $bindPassword)) {
            return ['ok' => false, 'error' => 'ldap bind failed'];
        }

        return ['ok' => true, 'data' => ['status' => 'ok']];
    }

    public function listGroupMappings(string $providerId): array
    {
        return LdapGroupMapping::find()
            ->where(['provider_id' => $providerId])
            ->orderBy(['created_at' => SORT_DESC])
            ->asArray()
            ->all();
    }

    public function createGroupMapping(string $providerId, array $body, ?string $actorId): array
    {
        $mapping = new LdapGroupMapping();
        $mapping->provider_id = $providerId;
        $mapping->ldap_group = trim((string)($body['ldap_group'] ?? ''));
        $mapping->group_id = (string)($body['group_id'] ?? '');

        if ($mapping->ldap_group === '' || $mapping->group_id === '' || !$mapping->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid mapping payload', 'details' => $mapping->errors]];
        }

        AuditService::record($actorId, 'ldap_group_mapping_created', 'ldap_provider', $providerId);
        return ['status' => 201, 'data' => $mapping->toArray()];
    }

    public function deleteGroupMapping(string $mappingId, ?string $actorId): bool
    {
        $mapping = LdapGroupMapping::findOne(['id' => $mappingId]);
        if (!$mapping) {
            return false;
        }

        $providerId = $mapping->provider_id;
        $mapping->delete();
        AuditService::record($actorId, 'ldap_group_mapping_deleted', 'ldap_provider', $providerId);
        return true;
    }

    private function fillProvider(LdapProvider $provider, array $body, bool $keepEmptyPassword): void
    {
        foreach (['name', 'host', 'bind_dn', 'base_dn', 'user_filter', 'username_attribute', 'email_attribute', 'display_name_attribute', 'group_attribute'] as $field) {
            if (array_key_exists($field, $body)) {
                $provider->{$field} = trim((string)$body[$field]);
            }
        }

        $provider->port = (int)($body['port'] ?? ($provider->port ?: 389));
        $provider->use_tls = (bool)($body['use_tls'] ?? $provider->use_tls);
        $provider->start_tls = (bool)($body['start_tls'] ?? $provider->start_tls);
        $provider->enabled = array_key_exists('enabled', $body) ? (bool)$body['enabled'] : ($provider->enabled ?? true);

        if (!$provider->user_filter) {
            $provider->user_filter = '(&(objectClass=user)(sAMAccountName={username}))';
        }
        if (!$provider->username_attribute) {
            $provider->username_attribute = 'sAMAccountName';
        }
        if (!$provider->email_attribute) {
            $provider->email_attribute = 'mail';
        }
        if (!$provider->display_name_attribute) {
            $provider->display_name_attribute = 'displayName';
        }
        if (!$provider->group_attribute) {
            $provider->group_attribute = 'memberOf';
        }

        if (!$keepEmptyPassword || !empty($body['bind_password'])) {
            $provider->bind_password = (new CryptoService())->encrypt((string)($body['bind_password'] ?? ''));
        }
    }

    private function publicProvider(LdapProvider $provider): array
    {
        $data = $provider->toArray();
        unset($data['bind_password']);
        return $data;
    }
}
