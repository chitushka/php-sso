<?php

namespace app\services;

use app\models\FederatedIdentity;
use app\models\IdentityProvider;
use app\models\User;
use Yii;
use yii\db\Exception;

class BrokerService
{
    private const STATE_TTL = 600;

    public function publicProviders(): array
    {
        $providers = IdentityProvider::find()
            ->where(['enabled' => true])
            ->orderBy(['code' => SORT_ASC])
            ->all();

        return array_map(function (IdentityProvider $provider): array {
            return [
                'code' => $provider->code,
                'name' => $provider->name,
                'type' => $provider->type,
            ];
        }, $providers);
    }

    public function listProviders(): array
    {
        return array_map(function (IdentityProvider $provider): array {
            return $this->publicProviderData($provider);
        }, IdentityProvider::find()->orderBy(['code' => SORT_ASC])->all());
    }

    public function createProvider(array $body): array
    {
        $provider = new IdentityProvider();
        $provider->code = trim(strtolower((string)($body['code'] ?? '')));

        return $this->saveProvider($provider, $body, false, 'identity_provider_created', 201);
    }

    public function updateProvider(string $id, array $body): array
    {
        $provider = IdentityProvider::findOne(['id' => $id]);
        if (!$provider) {
            return ['status' => 404, 'data' => ['error' => 'provider not found']];
        }

        return $this->saveProvider($provider, $body, true, 'identity_provider_updated', 200);
    }

    public function deleteProvider(string $id): array
    {
        $provider = IdentityProvider::findOne(['id' => $id]);
        if (!$provider) {
            return ['status' => 404, 'data' => ['error' => 'provider not found']];
        }

        $provider->delete();
        AuditService::record(null, 'identity_provider_deleted', 'identity_provider', $id);

        return ['status' => 200, 'data' => ['status' => 'deleted']];
    }

    public function start(string $code, string $continueUrl): ?string
    {
        $provider = IdentityProvider::findOne(['code' => $code, 'enabled' => true]);
        if (!$provider) {
            return null;
        }

        $provider = $this->applyPreset($provider);
        $continueUrl = strpos($continueUrl, '/') === 0 ? $continueUrl : '/';
        $state = $this->signState([
            'p' => $provider->code,
            'n' => JwtService::base64url(random_bytes(16)),
            'c' => $continueUrl,
            'e' => gmdate('Y-m-d\TH:i:s\Z', time() + self::STATE_TTL),
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $this->callbackUrl((string)$provider->code),
            'scope' => $provider->scopes,
            'state' => $state,
        ]);

        return $provider->authorize_url . (strpos($provider->authorize_url, '?') === false ? '?' : '&') . $query;
    }

    public function callback(string $providerCode, string $rawState, string $code): array
    {
        $state = $this->verifyState($rawState);
        if (!$state || ($state['p'] ?? '') !== $providerCode || $code === '') {
            return ['ok' => false, 'error' => 'external authentication failed'];
        }

        $provider = IdentityProvider::findOne(['code' => $providerCode, 'enabled' => true]);
        if (!$provider) {
            return ['ok' => false, 'error' => 'external authentication failed'];
        }

        $provider = $this->applyPreset($provider);
        $identity = $this->fetchIdentity($provider, $code);
        if (!$identity) {
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode);
            return ['ok' => false, 'error' => 'external authentication failed'];
        }

        $user = $this->resolveUser($provider, $identity);
        if (!$user) {
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode);
            return ['ok' => false, 'error' => 'external authentication failed'];
        }

        $auth = (new AuthService())->issueFederatedLogin($user, Yii::$app->request);
        AuditService::record($user->id, 'broker_login_success', 'identity_provider', $providerCode);

        return [
            'ok' => true,
            'continue' => strpos((string)($state['c'] ?? ''), '/') === 0 ? (string)$state['c'] : '/',
            'cookie' => $auth['cookie'],
            'access_token' => $auth['data']['access_token'],
        ];
    }

    private function saveProvider(IdentityProvider $provider, array $body, bool $keepEmptySecret, string $auditAction, int $status): array
    {
        if (!$keepEmptySecret && $provider->code === '') {
            return ['status' => 400, 'data' => ['error' => 'code and client_id are required']];
        }

        foreach (['name', 'type', 'client_id', 'authorize_url', 'token_url', 'userinfo_url', 'scopes'] as $field) {
            if (array_key_exists($field, $body)) {
                $provider->$field = trim((string)$body[$field]);
            }
        }
        if (array_key_exists('enabled', $body)) {
            $provider->enabled = (bool)$body['enabled'];
        } elseif ($provider->isNewRecord) {
            $provider->enabled = true;
        }
        if (!$provider->type) {
            $provider->type = 'oidc';
        }
        if (!$provider->scopes) {
            $provider->scopes = 'openid profile email';
        }

        $provider = $this->applyPreset($provider);
        if (!$provider->code || !$provider->client_id) {
            return ['status' => 400, 'data' => ['error' => 'code and client_id are required']];
        }
        if (!$provider->authorize_url || !$provider->token_url || !$provider->userinfo_url) {
            return ['status' => 400, 'data' => ['error' => 'authorize_url, token_url and userinfo_url are required for type oidc']];
        }

        if ($provider->isNewRecord && IdentityProvider::find()->where(['code' => $provider->code])->exists()) {
            return ['status' => 409, 'data' => ['error' => 'provider already exists']];
        }

        if (!$keepEmptySecret || !empty($body['client_secret'])) {
            $provider->client_secret = (new CryptoService())->encrypt((string)($body['client_secret'] ?? ''));
        }

        try {
            if (!$provider->save()) {
                return ['status' => 400, 'data' => ['error' => 'failed to save identity provider']];
            }
        } catch (Exception $exception) {
            return ['status' => 409, 'data' => ['error' => 'provider already exists']];
        }

        AuditService::record(null, $auditAction, 'identity_provider', $provider->id);

        return ['status' => $status, 'data' => $this->publicProviderData($provider)];
    }

    private function publicProviderData(IdentityProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'type' => $provider->type,
            'client_id' => $provider->client_id,
            'authorize_url' => $provider->authorize_url,
            'token_url' => $provider->token_url,
            'userinfo_url' => $provider->userinfo_url,
            'scopes' => $provider->scopes,
            'enabled' => (bool)$provider->enabled,
            'created_at' => $provider->created_at,
            'updated_at' => $provider->updated_at,
        ];
    }

    private function applyPreset(IdentityProvider $provider): IdentityProvider
    {
        if ($provider->type === 'google') {
            $provider->authorize_url = $provider->authorize_url ?: 'https://accounts.google.com/o/oauth2/v2/auth';
            $provider->token_url = $provider->token_url ?: 'https://oauth2.googleapis.com/token';
            $provider->userinfo_url = $provider->userinfo_url ?: 'https://openidconnect.googleapis.com/v1/userinfo';
        }
        if ($provider->type === 'github') {
            $provider->authorize_url = $provider->authorize_url ?: 'https://github.com/login/oauth/authorize';
            $provider->token_url = $provider->token_url ?: 'https://github.com/login/oauth/access_token';
            $provider->userinfo_url = $provider->userinfo_url ?: 'https://api.github.com/user';
            if (!$provider->scopes || $provider->scopes === 'openid profile email') {
                $provider->scopes = 'read:user user:email';
            }
        }

        return $provider;
    }

    private function callbackUrl(string $code): string
    {
        return rtrim((string)Yii::$app->params['issuer'], '/') . '/oauth2/broker/' . rawurlencode($code) . '/callback';
    }

    private function fetchIdentity(IdentityProvider $provider, string $code): ?array
    {
        $secret = (new CryptoService())->decrypt((string)$provider->client_secret);
        $tokenBody = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl((string)$provider->code),
            'client_id' => $provider->client_id,
            'client_secret' => $secret,
        ]);
        $token = $this->jsonRequest((string)$provider->token_url, 'POST', $tokenBody, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        if (!$token || empty($token['access_token'])) {
            return null;
        }

        $info = $this->jsonRequest((string)$provider->userinfo_url, 'GET', null, [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json',
            'User-Agent: sso-php',
        ]);
        if (!$info) {
            return null;
        }

        $subject = isset($info['sub']) ? (string)$info['sub'] : (isset($info['id']) ? (string)$info['id'] : '');
        if ($subject === '') {
            return null;
        }

        return [
            'subject' => $subject,
            'email' => (string)($info['email'] ?? ''),
            'email_verified' => ($info['email_verified'] ?? false) === true,
            'username' => (string)($info['preferred_username'] ?? $info['login'] ?? $info['name'] ?? ''),
        ];
    }

    private function jsonRequest(string $url, string $method, ?string $body, array $headers): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || strlen($raw) > 1048576) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function resolveUser(IdentityProvider $provider, array $identity): ?User
    {
        $linked = FederatedIdentity::findOne([
            'provider_id' => $provider->id,
            'external_subject' => $identity['subject'],
        ]);
        if ($linked) {
            return User::findOne(['id' => $linked->user_id, 'status' => User::STATUS_ACTIVE]);
        }

        $user = null;
        if ($identity['email'] !== '' && $identity['email_verified']) {
            $user = User::findOne(['email' => $identity['email']]);
            if ($user && (bool)$user->mfa_enabled) {
                return null;
            }
        }
        if (!$user) {
            $user = $this->provisionUser($provider, $identity);
        }
        if (!$user) {
            return null;
        }

        $link = new FederatedIdentity();
        $link->provider_id = $provider->id;
        $link->external_subject = $identity['subject'];
        $link->user_id = $user->id;
        $link->email = $identity['email'];
        $link->save(false);
        AuditService::record($user->id, 'identity_linked', 'identity_provider', $provider->code);

        return $user;
    }

    private function provisionUser(IdentityProvider $provider, array $identity): ?User
    {
        $username = $identity['username'];
        if ($username === '' && $identity['email'] !== '' && strpos($identity['email'], '@') !== false) {
            $username = substr($identity['email'], 0, strpos($identity['email'], '@'));
        }
        if ($username === '') {
            $username = $provider->code . '-' . $identity['subject'];
        }

        $user = new User();
        $user->username = $this->uniqueUsername($username);
        $user->email = $identity['email'];
        $user->status = User::STATUS_ACTIVE;
        $user->source = User::SOURCE_FEDERATED;
        $user->email_verified = (bool)$identity['email_verified'];
        if (!$user->save(false)) {
            return null;
        }

        AuditService::record($user->id, 'user_created', 'user', $user->id);

        return $user;
    }

    private function uniqueUsername(string $username): string
    {
        $base = strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $username) ?: 'user');
        $candidate = trim($base, '-_.') ?: 'user';
        if (!User::findOne(['username' => $candidate])) {
            return $candidate;
        }

        return $candidate . '-' . JwtService::base64url(random_bytes(3));
    }

    private function signState(array $state): string
    {
        $payload = json_encode($state);
        $signature = hash_hmac('sha256', $payload, (string)Yii::$app->params['jwtSecret'], true);

        return JwtService::base64url($payload) . '.' . JwtService::base64url($signature);
    }

    private function verifyState(string $raw): ?array
    {
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $payload = $this->decodeStateSegment($parts[0]);
        $signature = $this->decodeStateSegment($parts[1]);
        if ($payload === null || $signature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, (string)Yii::$app->params['jwtSecret'], true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $state = json_decode($payload, true);
        if (!is_array($state) || !is_string($state['e'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $state['e']) !== 1) {
            return null;
        }

        try {
            $expiresAt = new \DateTimeImmutable($state['e']);
        } catch (\Exception $exception) {
            return null;
        }
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
            return null;
        }
        if ($expiresAt <= new \DateTimeImmutable('now')) {
            return null;
        }

        return $state;
    }

    private function decodeStateSegment(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }

        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
