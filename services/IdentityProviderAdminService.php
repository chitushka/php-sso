<?php

namespace app\services;

use app\models\IdentityProvider;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use yii\db\Exception;
use yii\db\Expression;

final class IdentityProviderAdminService
{
    private CryptoService $crypto;
    private IdentityProviderPolicy $policy;

    public function __construct(?CryptoService $crypto = null, ?IdentityProviderPolicy $policy = null)
    {
        $this->crypto = $crypto ?? new CryptoService();
        $this->policy = $policy ?? new IdentityProviderPolicy();
    }

    public function publicProviders(): array
    {
        $providers = IdentityProvider::find()->where(['enabled' => true])->orderBy(['code' => SORT_ASC])->all();
        return array_map(static function (IdentityProvider $provider): array {
            return ['code' => $provider->code, 'name' => $provider->name, 'type' => $provider->type];
        }, $providers);
    }

    public function list(array $options = []): array
    {
        $query = IdentityProvider::find();
        if (($options['q'] ?? '') !== '') {
            $query->andWhere(['or',
                ['ilike', 'code', $options['q']],
                ['ilike', 'name', $options['q']],
            ]);
        }
        if (($options['type'] ?? '') !== '') {
            $query->andWhere(['type' => $options['type']]);
        }
        return array_map(function (IdentityProvider $provider): array {
            return $this->policy->publicData($provider);
        }, $query->orderBy(['code' => SORT_ASC])
            ->limit($options['limit'] ?? 50)
            ->offset($options['offset'] ?? 0)
            ->all());
    }

    public function create(array $body): ServiceResult
    {
        $provider = new IdentityProvider();
        $provider->code = trim(strtolower((string)($body['code'] ?? '')));
        return $this->save($provider, $body, false, 'identity_provider_created', 201);
    }

    public function update(string $id, array $body): ServiceResult
    {
        $provider = IdentityProvider::findOne(['id' => $id]);
        return $provider
            ? $this->save($provider, $body, true, 'identity_provider_updated', 200)
            : $this->failure('provider_not_found', 'provider not found', 404);
    }

    public function delete(string $id): ServiceResult
    {
        $provider = IdentityProvider::findOne(['id' => $id]);
        if (!$provider) {
            return $this->failure('provider_not_found', 'provider not found', 404);
        }
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $provider->delete();
            AuditService::record(null, 'identity_provider_deleted', 'identity_provider', $id);
            $transaction->commit();
            return ServiceResult::success(['status' => 'deleted']);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('provider_delete_failed', 'failed to delete identity provider', 500);
        }
    }

    private function save(
        IdentityProvider $provider,
        array $body,
        bool $keepEmptySecret,
        string $auditAction,
        int $status
    ): ServiceResult {
        $isNewRecord = $provider->isNewRecord;
        if (!$keepEmptySecret && $provider->code === '') {
            return $this->failure('invalid_provider', 'code and client_id are required', 400);
        }

        foreach (['name', 'type', 'client_id', 'authorize_url', 'token_url', 'userinfo_url', 'scopes'] as $field) {
            if (array_key_exists($field, $body)) {
                $provider->$field = (string)$body[$field];
            }
        }
        if (array_key_exists('enabled', $body)) {
            $provider->enabled = (bool)$body['enabled'];
        }
        if ($isNewRecord && trim((string)$provider->type) === '') {
            $provider->type = IdentityProvider::TYPE_OIDC;
        }
        if (!in_array($provider->type, IdentityProvider::SUPPORTED_TYPES, true)) {
            return $this->failure('invalid_provider', 'unsupported identity provider type', 400);
        }

        if ($isNewRecord && $provider->client_id === '') {
            return $this->failure('invalid_provider', 'code and client_id are required', 400);
        }
        if ($isNewRecord && (!$provider->authorize_url || !$provider->token_url || !$provider->userinfo_url)) {
            return $this->failure(
                'invalid_provider',
                'authorize_url, token_url and userinfo_url are required for type oidc',
                400
            );
        }
        if (!$this->policy->endpointsAreSafe($provider)) {
            return $this->failure(
                'invalid_provider_endpoint',
                'identity provider endpoints must use trusted HTTPS URLs',
                400
            );
        }
        if ($isNewRecord && IdentityProvider::find()->where(['code' => $provider->code])->exists()) {
            return $this->failure('provider_conflict', 'provider already exists', 409);
        }
        if (!$keepEmptySecret || !empty($body['client_secret'])) {
            $provider->client_secret = $this->crypto->encrypt((string)($body['client_secret'] ?? ''));
        }
        if (!$isNewRecord) {
            $provider->updated_at = new Expression('now()');
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if (!$provider->save()) {
                $transaction->rollBack();
                return $this->failure('invalid_provider', 'failed to save identity provider', 400);
            }
            $provider->refresh();
            AuditService::record(null, $auditAction, 'identity_provider', $provider->id);
            $transaction->commit();
        } catch (Exception $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('provider_conflict', 'provider already exists', 409);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('provider_save_failed', 'failed to save identity provider', 500);
        }

        return ServiceResult::success($this->policy->publicData($provider), $status);
    }

    private function failure(string $code, string $message, int $status): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, $status));
    }
}
