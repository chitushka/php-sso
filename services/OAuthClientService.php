<?php

namespace app\services;

use app\models\OAuthClient;
use app\services\contracts\RandomGeneratorInterface;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use yii\db\Expression;

final class OAuthClientService
{
    private OAuthRedirectPolicy $redirectPolicy;
    private RandomGeneratorInterface $random;

    public function __construct(
        ?OAuthRedirectPolicy $redirectPolicy = null,
        ?RandomGeneratorInterface $random = null
    ) {
        $this->redirectPolicy = $redirectPolicy ?? new OAuthRedirectPolicy();
        $this->random = $random ?? new SecureRandomGenerator();
    }

    public function list(): array
    {
        return array_map(static function (OAuthClient $client): array {
            return $client->publicData();
        }, OAuthClient::find()->orderBy(['created_at' => SORT_DESC])->all());
    }

    public function get(string $id): ?array
    {
        $client = OAuthClient::findOne(['id' => $id]);
        return $client ? $client->publicData() : null;
    }

    public function create(array $body): ServiceResult
    {
        $client = new OAuthClient();
        $client->client_id = (string)($body['client_id'] ?? '');
        $client->name = (string)($body['name'] ?? '');
        $client->type = (string)($body['type'] ?? OAuthClient::TYPE_CONFIDENTIAL);
        $this->fillMutableFields($client, $body);

        $invalid = $this->validateUris($client);
        if ($invalid !== null) {
            return $invalid;
        }

        $plainSecret = null;
        if ($client->type === OAuthClient::TYPE_CONFIDENTIAL) {
            $plainSecret = $this->random->token();
            $client->client_secret_hash = PasswordHasher::hash($plainSecret);
        }
        if (!$client->save()) {
            return $this->failure('invalid_client', 'invalid client payload', 400, ['details' => $client->errors]);
        }
        $client->refresh();

        $data = $client->publicData();
        if ($plainSecret !== null) {
            $data['client_secret'] = $plainSecret;
        }
        return ServiceResult::success($data, 201);
    }

    public function update(string $id, array $body): ServiceResult
    {
        $client = OAuthClient::findOne(['id' => $id]);
        if (!$client) {
            return $this->failure('client_not_found', 'client not found', 404);
        }

        $this->fillMutableFields($client, $body);
        $client->updated_at = new Expression('now()');
        $invalid = $this->validateUris($client);
        if ($invalid !== null) {
            return $invalid;
        }
        if (!$client->save()) {
            return $this->failure('invalid_client', 'invalid client payload', 400, ['details' => $client->errors]);
        }
        $client->refresh();
        AuditService::record(null, 'oauth_client_updated', 'oauth_client', $client->client_id);
        return ServiceResult::success($client->publicData());
    }

    public function delete(string $id): bool
    {
        $client = OAuthClient::findOne(['id' => $id]);
        if (!$client) {
            return false;
        }
        $client->delete();
        AuditService::record(null, 'oauth_client_deleted', 'oauth_client', $id);
        return true;
    }

    public function rotateSecret(string $id): ServiceResult
    {
        $client = OAuthClient::findOne(['id' => $id, 'type' => OAuthClient::TYPE_CONFIDENTIAL]);
        if (!$client) {
            return $this->failure('client_not_found', 'confidential client not found', 404);
        }

        $secret = $this->random->token();
        OAuthClient::updateAll([
            'client_secret_hash' => PasswordHasher::hash($secret),
            'updated_at' => new Expression('now()'),
        ], ['id' => $client->id]);
        $client->refresh();
        AuditService::record(null, 'oauth_client_secret_rotated', 'oauth_client', $client->client_id);

        return ServiceResult::success(array_merge($client->publicData(), ['client_secret' => $secret]));
    }

    private function fillMutableFields(OAuthClient $client, array $body): void
    {
        $client->redirect_uris = OAuthClient::toPgArray((array)($body['redirect_uris'] ?? []));
        $client->allowed_scopes = OAuthClient::toPgArray((array)($body['allowed_scopes'] ?? []));
        $client->post_logout_redirect_uris = OAuthClient::toPgArray((array)($body['post_logout_redirect_uris'] ?? []));
        $client->backchannel_logout_uri = (string)($body['backchannel_logout_uri'] ?? '');
        $client->skip_consent = (bool)($body['skip_consent'] ?? false);
        $client->allow_loopback_redirects = (bool)($body['allow_loopback_redirects'] ?? false);
        $client->enabled = (bool)($body['enabled'] ?? false);
        if (array_key_exists('name', $body)) {
            $client->name = (string)$body['name'];
        }
    }

    private function validateUris(OAuthClient $client): ?ServiceResult
    {
        if (!$this->redirectPolicy->clientUrisAreSafe($client)) {
            return $this->failure(
                'invalid_redirect_uri',
                'redirect URIs violate the client redirect policy',
                400
            );
        }
        if (!$this->redirectPolicy->backchannelUriIsSafe((string)$client->backchannel_logout_uri)) {
            return $this->failure(
                'invalid_backchannel_uri',
                'backchannel_logout_uri must be a trusted HTTPS URL',
                400
            );
        }
        return null;
    }

    private function failure(string $code, string $message, int $status, array $details = []): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, $status, $details));
    }
}
