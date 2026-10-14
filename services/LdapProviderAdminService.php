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
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $this->fillProvider($provider, $body, false);
            if (!$provider->save()) {
                $transaction->rollBack();
                return $this->failure('provider_create_failed', 'failed to create provider', 500);
            }
            $provider->refresh();
            AuditService::record(
                $actorId,
                'ldap_provider_created',
                'ldap_provider',
                (string)$provider->getAttribute('id')
            );
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('provider_create_failed', 'failed to create provider', 500);
        }
        return ServiceResult::success($this->publicProvider($provider), 201);
    }

    public function update(string $id, array $body, ?string $actorId): ServiceResult
    {
        $provider = LdapProvider::findOne(['id' => $id]);
        if (!$provider) {
            return $this->failure('provider_not_found', 'provider not found', 404);
        }
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $this->fillProvider($provider, $body, true);
            $provider->updated_at = new Expression('now()');
            if (!$provider->save()) {
                $transaction->rollBack();
                return $this->failure('provider_update_failed', 'failed to update provider', 500);
            }
            $provider->refresh();
            AuditService::record($actorId, 'ldap_provider_updated', 'ldap_provider', $provider->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('provider_update_failed', 'failed to update provider', 500);
        }
        return ServiceResult::success($this->publicProvider($provider));
    }

    public function delete(string $id, ?string $actorId): bool
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $deleted = ApplicationRuntime::db()->createCommand()
                ->delete('ldap_providers', ['id' => $id])
                ->execute();
            if ($deleted === 0) {
                $transaction->rollBack();
                return false;
            }
            AuditService::record($actorId, 'ldap_provider_deleted', 'ldap_provider', $id);
            $transaction->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function listMappings(string $providerId, array $options = []): ServiceResult
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if (!$this->lockProvider($providerId)) {
                $transaction->rollBack();
                return $this->failure('provider_not_found', 'provider not found', 404);
            }
            $mappings = array_map(function (LdapGroupMapping $mapping): array {
                return $this->publicMapping($mapping);
            }, LdapGroupMapping::find()
                ->where(['provider_id' => $providerId])
                ->andFilterWhere(['ilike', 'ldap_group', $options['q'] ?? null])
                ->orderBy(['ldap_group' => SORT_ASC, 'id' => SORT_ASC])
                ->limit($options['limit'] ?? 50)
                ->offset($options['offset'] ?? 0)
                ->all());
            $transaction->commit();
            return ServiceResult::success($mappings);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function createMapping(string $providerId, array $body, ?string $actorId): ServiceResult
    {
        $mapping = new LdapGroupMapping();
        $mapping->provider_id = $providerId;
        $mapping->ldap_group = trim((string)($body['ldap_group'] ?? ''));
        $mapping->group_id = (string)($body['group_id'] ?? '');

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if (!$this->lockProvider($providerId)) {
                $transaction->rollBack();
                return $this->failure('provider_not_found', 'provider not found', 404);
            }
            if (!$mapping->save()) {
                $transaction->rollBack();
                return $this->failure('mapping_create_failed', 'failed to create mapping', 500);
            }
            $mapping->refresh();
            AuditService::record($actorId, 'ldap_group_mapping_created', 'ldap_provider', $providerId);
            $transaction->commit();
        } catch (IntegrityException $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return ($exception->errorInfo[0] ?? '') === '23505'
                ? $this->failure('mapping_conflict', 'mapping already exists', 409)
                : $this->failure('mapping_create_failed', 'failed to create mapping', 500);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('mapping_create_failed', 'failed to create mapping', 500);
        }
        return ServiceResult::success($this->publicMapping($mapping), 201);
    }

    public function deleteMapping(string $providerId, string $mappingId, ?string $actorId): bool
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $deleted = ApplicationRuntime::db()->createCommand()->delete('ldap_group_mappings', [
                'id' => $mappingId,
                'provider_id' => $providerId,
            ])->execute();
            if ($deleted === 0) {
                $transaction->rollBack();
                return false;
            }
            AuditService::record($actorId, 'ldap_group_mapping_deleted', 'ldap_group_mapping', $mappingId);
            $transaction->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
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
            if (!$isUpdate || array_key_exists($field, $body)) {
                $provider->{$field} = (string)($body[$field] ?? '');
            }
        }
        foreach (['port' => 389, 'use_tls' => false, 'start_tls' => false, 'enabled' => false] as $field => $default) {
            if (!$isUpdate || array_key_exists($field, $body)) {
                $provider->{$field} = $field === 'port'
                    ? (int)($body[$field] ?? $default)
                    : (bool)($body[$field] ?? $default);
            }
        }

        if (!$isUpdate) {
            $provider->user_filter = $provider->user_filter ?: '(&(objectClass=user)(sAMAccountName={username}))';
            $provider->username_attribute = $provider->username_attribute ?: 'sAMAccountName';
            $provider->email_attribute = $provider->email_attribute ?: 'mail';
            $provider->display_name_attribute = $provider->display_name_attribute ?: 'displayName';
        }
        if (!$isUpdate || !empty($body['bind_password'])) {
            $provider->bind_password = $this->crypto->encrypt((string)($body['bind_password'] ?? ''));
        }
    }

    private function lockProvider(string $providerId): bool
    {
        return ApplicationRuntime::db()->createCommand(
            'SELECT id FROM ldap_providers WHERE id = :id FOR KEY SHARE',
            [':id' => $providerId]
        )->queryScalar() !== false;
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
