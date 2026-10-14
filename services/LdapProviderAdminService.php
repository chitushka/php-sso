<?php

namespace app\services;

use app\components\Rfc3339;
use app\models\LdapGroupMapping;
use app\models\LdapProvider;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use yii\db\Expression;
use yii\db\IntegrityException;

final class LdapProviderAdminService
{
    private CryptoService $crypto;

    public function __construct(?CryptoService $crypto = null)
    {
        $this->crypto = $crypto ?? new CryptoService();
    }

    public function list(array $options = []): array
    {
        $query = LdapProvider::find();
        if (($options['q'] ?? '') !== '') {
            $query->andWhere(['or',
                ['ilike', 'name', $options['q']],
                ['ilike', 'host', $options['q']],
                ['ilike', 'base_dn', $options['q']],
            ]);
        }
        if (isset($options['enabled'])) {
            $query->andWhere(['enabled' => $options['enabled'] === 'true']);
        }
        return array_map(function (LdapProvider $provider): array {
            return $this->publicProvider($provider);
        }, $query->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($options['limit'] ?? 50)
            ->offset($options['offset'] ?? 0)
            ->all());
    }

    public function create(array $body, ?string $actorId): ServiceResult
    {
        $provider = new LdapProvider();
        try {
            $this->fillProvider($provider, $body, false);
            if (!$provider->save()) {
                return $this->failure('provider_create_failed', 'failed to create provider', 500);
            }
        } catch (\Throwable $exception) {
            return $this->failure('provider_create_failed', 'failed to create provider', 500);
        }
        $provider->refresh();
        return ServiceResult::success($this->publicProvider($provider), 201);
    }

    public function update(string $id, array $body, ?string $actorId): ServiceResult
    {
        $provider = LdapProvider::findOne(['id' => $id]);
        if (!$provider) {
            return $this->failure('provider_not_found', 'provider not found', 404);
        }
        try {
            $this->fillProvider($provider, $body, true);
            $provider->updated_at = new Expression('now()');
            if (!$provider->save()) {
                return $this->failure('provider_update_failed', 'failed to update provider', 500);
            }
        } catch (\Throwable $exception) {
            return $this->failure('provider_update_failed', 'failed to update provider', 500);
        }
        $provider->refresh();
        AuditService::record($actorId, 'ldap_provider_updated', 'ldap_provider', $provider->id);
        return ServiceResult::success($this->publicProvider($provider));
    }

    public function delete(string $id, ?string $actorId): bool
    {
        ApplicationRuntime::db()->createCommand()->delete('ldap_providers', ['id' => $id])->execute();
        AuditService::record($actorId, 'ldap_provider_deleted', 'ldap_provider', $id);
        return true;
    }

    public function listMappings(string $providerId, array $options = []): array
    {
        return array_map(function (LdapGroupMapping $mapping): array {
            return $this->publicMapping($mapping);
        }, LdapGroupMapping::find()
            ->where(['provider_id' => $providerId])
            ->andFilterWhere(['ilike', 'ldap_group', $options['q'] ?? null])
            ->orderBy(['ldap_group' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($options['limit'] ?? 50)
            ->offset($options['offset'] ?? 0)
            ->all());
    }

    public function createMapping(string $providerId, array $body, ?string $actorId): ServiceResult
    {
        $mapping = new LdapGroupMapping();
        $mapping->provider_id = $providerId;
        $mapping->ldap_group = trim((string)($body['ldap_group'] ?? ''));
        $mapping->group_id = (string)($body['group_id'] ?? '');

        try {
            if (!$mapping->save()) {
                return $this->failure('mapping_create_failed', 'failed to create mapping', 500);
            }
        } catch (IntegrityException $exception) {
            return ($exception->errorInfo[0] ?? '') === '23505'
                ? $this->failure('mapping_conflict', 'mapping already exists', 409)
                : $this->failure('mapping_create_failed', 'failed to create mapping', 500);
        } catch (\Throwable $exception) {
            return $this->failure('mapping_create_failed', 'failed to create mapping', 500);
        }
        $mapping->refresh();
        AuditService::record($actorId, 'ldap_group_mapping_created', 'ldap_provider', $providerId);
        return ServiceResult::success($this->publicMapping($mapping), 201);
    }

    public function deleteMapping(string $mappingId, ?string $actorId): bool
    {
        $mapping = LdapGroupMapping::findOne(['id' => $mappingId]);
        if (!$mapping) {
            return false;
        }
        $mapping->delete();
        AuditService::record($actorId, 'ldap_group_mapping_deleted', 'ldap_group_mapping', $mappingId);
        return true;
    }

    private function fillProvider(LdapProvider $provider, array $body, bool $isUpdate): void
    {
        $fields = [
            'name',
            'host',
            'bind_dn',
            'base_dn',
            'user_filter',
            'username_attribute',
            'email_attribute',
            'display_name_attribute',
            'group_attribute',
        ];
        foreach ($fields as $field) {
            $provider->{$field} = (string)($body[$field] ?? '');
        }
        $provider->port = (int)($body['port'] ?? 0);
        $provider->use_tls = (bool)($body['use_tls'] ?? false);
        $provider->start_tls = (bool)($body['start_tls'] ?? false);
        $provider->enabled = (bool)($body['enabled'] ?? false);

        if (!$isUpdate) {
            $provider->port = $provider->port ?: 389;
            $provider->user_filter = $provider->user_filter ?: '(&(objectClass=user)(sAMAccountName={username}))';
            $provider->username_attribute = $provider->username_attribute ?: 'sAMAccountName';
            $provider->email_attribute = $provider->email_attribute ?: 'mail';
            $provider->display_name_attribute = $provider->display_name_attribute ?: 'displayName';
        }
        if (!$isUpdate || !empty($body['bind_password'])) {
            $provider->bind_password = $this->crypto->encrypt((string)($body['bind_password'] ?? ''));
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

    private function publicMapping(LdapGroupMapping $mapping): array
    {
        $data = $mapping->toArray();
        $data['created_at'] = Rfc3339::format($mapping->created_at);
        return $data;
    }

    private function failure(string $code, string $message, int $status): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, $status));
    }
}
