<?php

namespace app\services;

use app\components\Rfc3339;
use app\models\FederatedIdentity;
use app\models\IdentityProvider;
use app\models\User;
use Yii;
use yii\db\Exception;
use yii\db\Expression;

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
        if (!$this->providerEndpointsAreSafe($provider)) {
            return null;
        }
        $continueUrl = $this->localContinueUrl($continueUrl);
        $state = $this->signState([
            'p' => $provider->code,
            'n' => JwtService::base64url(random_bytes(16)),
            'c' => $continueUrl,
            'e' => gmdate('Y-m-d\TH:i:s\Z', time() + self::STATE_TTL),
        ]);

        return $this->withQueryParameters((string)$provider->authorize_url, [
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $this->callbackUrl((string)$provider->code),
            'scope' => $provider->scopes,
            'state' => $state,
        ]);
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
            Yii::warning('broker identity fetch failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return ['ok' => false, 'error' => 'external authentication failed'];
        }

        $user = $this->resolveUser($provider, $identity);
        if (!$user) {
            Yii::warning('broker user resolution failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return ['ok' => false, 'error' => 'external authentication failed'];
        }

        $auth = (new AuthService())->issueFederatedLogin($user, Yii::$app->request);
        if (!$auth['ok']) {
            Yii::warning('broker session issuance failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return ['ok' => false, 'error' => 'external authentication failed'];
        }
        AuditService::record($user->id, 'broker_login_success', 'identity_provider', $providerCode, true);

        return [
            'ok' => true,
            'continue' => $this->localContinueUrl((string)($state['c'] ?? '')),
            'cookie' => $auth['cookie'],
            'csrf_cookie' => $auth['csrf_cookie'],
        ];
    }

    private function saveProvider(IdentityProvider $provider, array $body, bool $keepEmptySecret, string $auditAction, int $status): array
    {
        $isNewRecord = $provider->isNewRecord;
        if (!$keepEmptySecret && $provider->code === '') {
            return ['status' => 400, 'data' => ['error' => 'code and client_id are required']];
        }

        if ($isNewRecord) {
            foreach (['name', 'type', 'client_id', 'authorize_url', 'token_url', 'userinfo_url', 'scopes'] as $field) {
                $provider->$field = (string)($body[$field] ?? '');
            }
            $provider->enabled = (bool)($body['enabled'] ?? false);
            if ($provider->type === '') {
                $provider->type = 'oidc';
            }

            $provider = $this->applyPreset($provider);
            if ($provider->client_id === '') {
                return ['status' => 400, 'data' => ['error' => 'code and client_id are required']];
            }
            if (!$provider->authorize_url || !$provider->token_url || !$provider->userinfo_url) {
                return ['status' => 400, 'data' => ['error' => 'authorize_url, token_url and userinfo_url are required for type oidc']];
            }
        } else {
            // Go декодирует PUT в новую Provider struct: пропущенные поля получают zero values.
            foreach (['name', 'type', 'client_id', 'authorize_url', 'token_url', 'userinfo_url', 'scopes'] as $field) {
                $provider->$field = (string)($body[$field] ?? '');
            }
            $provider->enabled = (bool)($body['enabled'] ?? false);
            $provider = $this->applyPreset($provider);
        }

        if (!$this->providerEndpointsAreSafe($provider)) {
            return ['status' => 400, 'data' => ['error' => 'identity provider endpoints must use trusted HTTPS URLs']];
        }

        if ($isNewRecord && IdentityProvider::find()->where(['code' => $provider->code])->exists()) {
            return ['status' => 409, 'data' => ['error' => 'provider already exists']];
        }

        if (!$keepEmptySecret || !empty($body['client_secret'])) {
            $provider->client_secret = (new CryptoService())->encrypt((string)($body['client_secret'] ?? ''));
        }
        if (!$isNewRecord) {
            $provider->updated_at = new Expression('now()');
        }

        try {
            if (!$provider->save()) {
                return ['status' => 400, 'data' => ['error' => 'failed to save identity provider']];
            }
            $provider->refresh();
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
            'created_at' => Rfc3339::format($provider->created_at),
            'updated_at' => Rfc3339::format($provider->updated_at),
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

    private function withQueryParameters(string $uri, array $parameters): ?string
    {
        if (parse_url($uri) === false) {
            return null;
        }

        $fragment = '';
        $fragmentPosition = strpos($uri, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($uri, $fragmentPosition);
            $uri = substr($uri, 0, $fragmentPosition);
        }

        $base = $uri;
        $rawQuery = '';
        $queryPosition = strpos($uri, '?');
        if ($queryPosition !== false) {
            $base = substr($uri, 0, $queryPosition);
            $rawQuery = substr($uri, $queryPosition + 1);
        }

        $query = [];
        foreach ($rawQuery === '' ? [] : explode('&', $rawQuery) as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $value = rawurldecode(str_replace('+', ' ', $rawValue));
            $query[$key][] = $value;
        }
        foreach ($parameters as $key => $value) {
            $query[(string)$key] = [(string)$value];
        }
        ksort($query, SORT_STRING);

        $parts = [];
        foreach ($query as $key => $values) {
            foreach ($values as $value) {
                $parts[] = http_build_query([$key => $value]);
            }
        }

        return $base . '?' . implode('&', $parts) . $fragment;
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
        $token = (new OutboundHttpService())->requestJson((string)$provider->token_url, 'POST', $tokenBody, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        if (!$token || empty($token['access_token'])) {
            Yii::warning('broker token exchange failed', 'broker');
            return null;
        }

        $info = (new OutboundHttpService())->requestJson((string)$provider->userinfo_url, 'GET', null, [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json',
            'User-Agent: sso-php',
        ]);
        if (!$info) {
            Yii::warning('broker userinfo request failed', 'broker');
            return null;
        }

        $subject = isset($info['sub']) ? (string)$info['sub'] : (isset($info['id']) ? (string)$info['id'] : '');
        if ($subject === '') {
            Yii::warning('broker userinfo subject is missing', 'broker');
            return null;
        }

        return [
            'subject' => $subject,
            'email' => (string)($info['email'] ?? ''),
            'email_verified' => ($info['email_verified'] ?? false) === true,
            'username' => (string)($info['preferred_username'] ?? $info['login'] ?? $info['name'] ?? ''),
        ];
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

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Совпадение email не доказывает владение локальной учётной записью.
            $user = $this->provisionUser($provider, $identity);
            if (!$user) {
                $transaction->rollBack();
                return null;
            }

            $link = new FederatedIdentity();
            $link->provider_id = $provider->id;
            $link->external_subject = $identity['subject'];
            $link->user_id = $user->id;
            $link->email = $identity['email'];
            if (!$link->save(false)) {
                throw new \RuntimeException('failed to link federated identity');
            }
            AuditService::record($user->id, 'identity_linked', 'identity_provider', $provider->code);
            $transaction->commit();
            return $user;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }

            $linked = FederatedIdentity::findOne([
                'provider_id' => $provider->id,
                'external_subject' => $identity['subject'],
            ]);
            return $linked
                ? User::findOne(['id' => $linked->user_id, 'status' => User::STATUS_ACTIVE])
                : null;
        }
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
        $user->email = strtolower(trim((string)$identity['email']));
        $user->status = User::STATUS_ACTIVE;
        $user->source = User::SOURCE_FEDERATED;
        $user->email_verified = false;
        if (!$user->save(false)) {
            return null;
        }

        AuditService::record($user->id, 'user_created', 'user', $user->id);

        return $user;
    }

    private function uniqueUsername(string $username): string
    {
        if (!User::findOne(['username' => $username])) {
            return $username;
        }

        return $username . '-' . JwtService::base64url(random_bytes(3));
    }

    private function localContinueUrl(string $url): string
    {
        if ($url === '' || strpos($url, '/') !== 0 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '/';
        }

        $decoded = $url;
        for ($iteration = 0; $iteration < 3; $iteration++) {
            if (strpos($decoded, '//') === 0 || strpos($decoded, '\\') !== false) {
                return '/';
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return '/';
        }

        return $url;
    }

    private function providerEndpointsAreSafe(IdentityProvider $provider): bool
    {
        if (
            !(bool)$provider->enabled
            && (string)$provider->authorize_url === ''
            && (string)$provider->token_url === ''
            && (string)$provider->userinfo_url === ''
        ) {
            return true;
        }

        $http = new OutboundHttpService();
        return $http->isValidEndpoint((string)$provider->authorize_url, true)
            && $http->isValidEndpoint((string)$provider->token_url)
            && $http->isValidEndpoint((string)$provider->userinfo_url);
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
        if (
            !is_array($state) || !is_string($state['e'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $state['e']) !== 1
        ) {
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
