<?php

namespace app\services;

use app\models\FederatedIdentity;
use app\models\IdentityProvider;
use app\models\User;
use app\services\contracts\ClockInterface;
use app\services\contracts\RandomGeneratorInterface;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use Yii;
use yii\web\Cookie;

class BrokerService
{
    private const STATE_TTL = 600;
    public const CONTEXT_COOKIE = 'sso_broker_context';

    private AuthService $auth;
    private CryptoService $crypto;
    private OutboundHttpService $outboundHttp;
    private ClockInterface $clock;
    private RandomGeneratorInterface $random;
    private IdentityProviderAdminService $providers;
    private IdentityProviderPolicy $providerPolicy;

    public function __construct(
        ?AuthService $auth = null,
        ?CryptoService $crypto = null,
        ?OutboundHttpService $outboundHttp = null,
        ?ClockInterface $clock = null,
        ?RandomGeneratorInterface $random = null,
        ?IdentityProviderAdminService $providers = null,
        ?IdentityProviderPolicy $providerPolicy = null
    ) {
        $this->auth = $auth ?? new AuthService();
        $this->crypto = $crypto ?? new CryptoService();
        $this->outboundHttp = $outboundHttp ?? new OutboundHttpService();
        $this->clock = $clock ?? new SystemClock();
        $this->random = $random ?? new SecureRandomGenerator();
        $this->providerPolicy = $providerPolicy ?? new IdentityProviderPolicy($this->outboundHttp);
        $this->providers = $providers ?? new IdentityProviderAdminService($this->crypto, $this->providerPolicy);
    }

    public function publicProviders(): array
    {
        return $this->providers->publicProviders();
    }

    public function listProviders(): array
    {
        return $this->providers->list();
    }

    public function createProvider(array $body): ServiceResult
    {
        return $this->providers->create($body);
    }

    public function updateProvider(string $id, array $body): ServiceResult
    {
        return $this->providers->update($id, $body);
    }

    public function deleteProvider(string $id): ServiceResult
    {
        return $this->providers->delete($id);
    }

    public function start(string $code, string $continueUrl, string $browserContext = ''): ServiceResult
    {
        $provider = IdentityProvider::findOne(['code' => $code, 'enabled' => true]);
        if (!$provider) {
            return $this->failure('unknown_identity_provider', 'unknown identity provider', 404);
        }

        if (!$this->providerPolicy->endpointsAreSafe($provider)) {
            return $this->failure('unknown_identity_provider', 'unknown identity provider', 404);
        }
        if (!$this->isValidBrowserContext($browserContext)) {
            $browserContext = $this->random->token(32);
        }
        $continueUrl = $this->localContinueUrl($continueUrl);
        $expiresAt = $this->clock->now()->getTimestamp() + self::STATE_TTL;
        $state = $this->signState([
            'p' => $provider->code,
            'n' => $this->random->token(16),
            'c' => $continueUrl,
            'e' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ]);

        try {
            ApplicationRuntime::db()->createCommand()->insert('broker_login_states', [
                'state_hash' => hash('sha256', $state),
                'browser_hash' => hash('sha256', $browserContext),
                'provider_id' => (string)$provider->getAttribute('id'),
                'expires_at' => gmdate('Y-m-d H:i:sP', $expiresAt),
            ])->execute();
        } catch (\Throwable $exception) {
            Yii::error('broker state persistence failed', 'broker');
            return $this->failure('broker_start_failed', 'failed to start external authentication', 500);
        }

        $redirect = $this->withQueryParameters((string)$provider->authorize_url, [
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $this->callbackUrl((string)$provider->code),
            'scope' => $provider->scopes,
            'state' => $state,
        ]);
        if ($redirect === null) {
            return $this->failure('broker_start_failed', 'failed to start external authentication', 500);
        }

        return ServiceResult::success(['redirect' => $redirect], 200, [
            'context_cookie' => new Cookie([
                'name' => self::CONTEXT_COOKIE,
                'value' => $browserContext,
                'httpOnly' => true,
                'secure' => (bool)ApplicationRuntime::param('cookieSecure'),
                'sameSite' => Cookie::SAME_SITE_LAX,
                'path' => '/oauth2/broker',
                'expire' => $expiresAt,
            ]),
        ]);
    }

    public function callback(
        string $providerCode,
        string $rawState,
        string $code,
        string $browserContext = ''
    ): ServiceResult {
        $state = $this->verifyState($rawState);
        if (!$state || ($state['p'] ?? '') !== $providerCode || $code === '') {
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }

        $provider = IdentityProvider::findOne(['code' => $providerCode, 'enabled' => true]);
        if (!$provider) {
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }

        if (!$this->consumeState($provider, $rawState, $browserContext)) {
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }

        if (!$this->providerPolicy->endpointsAreSafe($provider)) {
            Yii::warning('broker provider endpoint policy failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }
        $identity = $this->fetchIdentity($provider, $code);
        if (!$identity) {
            Yii::warning('broker identity fetch failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }

        $user = $this->resolveUser($provider, $identity);
        if (!$user) {
            Yii::warning('broker user resolution failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }

        $auth = $this->auth->issueFederatedLogin($user, ApplicationRuntime::request());
        if (!$auth->isSuccess()) {
            Yii::warning('broker session issuance failed', 'broker');
            AuditService::record(null, 'broker_login_failed', 'identity_provider', $providerCode, true);
            return $this->failure('broker_authentication_failed', 'external authentication failed');
        }
        AuditService::record($user->id, 'broker_login_success', 'identity_provider', $providerCode, true);

        return ServiceResult::success([
            'continue' => $this->localContinueUrl((string)($state['c'] ?? '')),
        ], 200, [
            'cookie' => $auth->metadata['cookie'],
            'csrf_cookie' => $auth->metadata['csrf_cookie'],
        ]);
    }

    public function consumeRejectedCallback(
        string $providerCode,
        string $rawState,
        string $browserContext = ''
    ): bool {
        $state = $this->verifyState($rawState);
        if (!$state || ($state['p'] ?? '') !== $providerCode) {
            return false;
        }

        $provider = IdentityProvider::findOne(['code' => $providerCode, 'enabled' => true]);
        return $provider ? $this->consumeState($provider, $rawState, $browserContext) : false;
    }

    private function consumeState(
        IdentityProvider $provider,
        string $rawState,
        string $browserContext
    ): bool {
        if (!$this->isValidBrowserContext($browserContext)) {
            return false;
        }

        // Условное обновление делает callback одноразовым даже при параллельных запросах.
        return ApplicationRuntime::db()->createCommand(
            'UPDATE broker_login_states
             SET consumed_at = now()
             WHERE state_hash = :state_hash
               AND browser_hash = :browser_hash
               AND provider_id = :provider_id
               AND consumed_at IS NULL
               AND expires_at > now()',
            [
                ':state_hash' => hash('sha256', $rawState),
                ':browser_hash' => hash('sha256', $browserContext),
                ':provider_id' => (string)$provider->getAttribute('id'),
            ]
        )->execute() === 1;
    }

    private function isValidBrowserContext(string $browserContext): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $browserContext) === 1;
    }

    private function callbackUrl(string $code): string
    {
        return rtrim((string)ApplicationRuntime::param('issuer'), '/')
            . '/oauth2/broker/' . rawurlencode($code) . '/callback';
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
        $secret = $this->crypto->decrypt((string)$provider->client_secret);
        $tokenBody = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl((string)$provider->code),
            'client_id' => $provider->client_id,
            'client_secret' => $secret,
        ]);
        $token = $this->outboundHttp->requestJson((string)$provider->token_url, 'POST', $tokenBody, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        if (!$token || empty($token['access_token'])) {
            Yii::warning('broker token exchange failed', 'broker');
            return null;
        }

        $info = $this->outboundHttp->requestJson((string)$provider->userinfo_url, 'GET', null, [
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

        $transaction = ApplicationRuntime::db()->beginTransaction();
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

        return $username . '-' . $this->random->token(3);
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

    private function signState(array $state): string
    {
        $payload = json_encode($state);
        $signature = hash_hmac('sha256', $payload, (string)ApplicationRuntime::param('jwtSecret'), true);

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

        $expected = hash_hmac('sha256', $payload, (string)ApplicationRuntime::param('jwtSecret'), true);
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
        if ($expiresAt <= $this->clock->now()) {
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

    private function failure(string $code, string $message, int $status = 401): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, $status));
    }
}
