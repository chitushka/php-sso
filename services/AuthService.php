<?php

namespace app\services;

use app\components\Rfc3339;
use app\models\LoginAttempt;
use app\models\OAuthClient;
use app\models\Session;
use app\models\User;
use app\services\contracts\ClockInterface;
use app\services\contracts\RandomGeneratorInterface;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use yii\db\Expression;
use yii\web\Cookie;
use yii\web\Request;

class AuthService
{
    private LdapService $ldap;
    private MetricsService $metrics;
    private MfaService $mfa;
    private JwtService $jwt;
    private ClockInterface $clock;
    private RandomGeneratorInterface $random;

    public function __construct(
        ?LdapService $ldap = null,
        ?MetricsService $metrics = null,
        ?MfaService $mfa = null,
        ?JwtService $jwt = null,
        ?ClockInterface $clock = null,
        ?RandomGeneratorInterface $random = null
    ) {
        $this->ldap = $ldap ?? new LdapService();
        $this->metrics = $metrics ?? new MetricsService();
        $this->mfa = $mfa ?? new MfaService();
        $this->jwt = $jwt ?? new JwtService();
        $this->clock = $clock ?? new SystemClock();
        $this->random = $random ?? new SecureRandomGenerator();
    }

    public function login(array $body, Request $request): ServiceResult
    {
        $login = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $ip = (string)$request->userIP;

        if ($this->isLocked($login, $ip)) {
            return $this->failure('login_rate_limited', 'too many failed attempts, try again later', 429);
        }

        if ($password === '') {
            $this->registerLoginFailure($login, $ip);
            return $this->failure('invalid_credentials', 'invalid credentials', 401);
        }

        $user = User::findOne(['username' => $login]);

        if ($user && $user->source === User::SOURCE_LOCAL) {
            if ($user->status !== User::STATUS_ACTIVE || !$user->password_hash) {
                return $this->failure('invalid_credentials', 'invalid credentials', 401);
            }
            if (!PasswordHasher::verify($password, (string)$user->password_hash)) {
                $this->registerLoginFailure($login, $ip);
                return $this->failure('invalid_credentials', 'invalid credentials', 401);
            }
        } else {
            $user = $this->ldap->authenticate($login, $password);
            $this->metrics->event('ldap', $user ? 'success' : 'failure');
        }

        if (!$user || $user->status !== User::STATUS_ACTIVE) {
            $this->registerLoginFailure($login, $ip);
            return $this->failure('invalid_credentials', 'invalid credentials', 401);
        }

        if ($user->mfa_enabled) {
            return ServiceResult::success($this->mfa->createLoginChallenge($user));
        }

        $this->resetFailures($login, $ip);
        return $this->issueLogin($user, $request);
    }

    public function verifyMfaLogin(array $body, Request $request): ServiceResult
    {
        $mfaToken = (string)($body['mfa_token'] ?? $body['challenge_token'] ?? '');
        $mfa = $this->mfa;
        $user = $mfa->loginChallengeUser($mfaToken);
        if (!$user) {
            return $this->failure('invalid_mfa_token', 'invalid mfa token', 401);
        }

        $login = (string)$user->username;
        $ip = (string)$request->userIP;
        if ($this->isLocked($login, $ip)) {
            return $this->failure('login_rate_limited', 'too many failed attempts, try again later', 429);
        }

        if (!$mfa->completeLoginChallenge($mfaToken, $user, (string)($body['code'] ?? ''))) {
            $this->registerLoginFailure($login, $ip);
            return $this->failure('invalid_mfa_code', 'invalid code', 401);
        }

        $this->resetFailures($login, $ip);
        return $this->issueLogin($user, $request);
    }

    private function issueLogin(User $user, Request $request): ServiceResult
    {
        $sessionToken = $this->randomToken();
        $csrfToken = $this->randomToken();
        $now = $this->clock->now()->getTimestamp();
        $sessionExpiresAt = $now + (int)ApplicationRuntime::param('sessionTtl');
        $session = new Session();
        $session->user_id = $user->id;
        $session->token_hash = hash('sha256', $sessionToken);
        $session->ip = $request->userIP;
        $session->user_agent = $request->userAgent;
        $session->expires_at = gmdate('Y-m-d H:i:sP', $sessionExpiresAt);
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            if (!$session->save(false)) {
                throw new \RuntimeException('failed to persist session');
            }
            User::updateAll(['last_login_at' => new Expression('now()')], ['id' => $user->id]);
            AuditService::record($user->id, 'login_success', 'user', $user->id, true);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->failure('session_create_failed', 'failed to create session', 500);
        }

        $issuedAt = $now;
        if ($user->tokens_invalid_before) {
            $issuedAt = max($issuedAt, strtotime((string)$user->tokens_invalid_before) + 1);
        }

        $jwt = $this->issueAccessToken($user, $issuedAt, $now);

        return ServiceResult::success([
            'access_token' => $jwt,
            'access_token_expires_at' => gmdate(
                'Y-m-d\\TH:i:s\\Z',
                $issuedAt + (int)ApplicationRuntime::param('accessTokenTtl')
            ),
            'session_expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $sessionExpiresAt),
            'csrf_token' => $csrfToken,
            'user' => $user->publicData(),
        ], 200, [
            'cookie' => new Cookie([
                'name' => 'sso_session',
                'value' => $sessionToken,
                'httpOnly' => true,
                'secure' => (bool)ApplicationRuntime::param('cookieSecure'),
                'sameSite' => Cookie::SAME_SITE_LAX,
                'path' => '/',
                'expire' => $sessionExpiresAt,
            ]),
            'csrf_cookie' => new Cookie([
                'name' => BrowserSecurityService::CSRF_COOKIE,
                'value' => $csrfToken,
                'httpOnly' => false,
                'secure' => (bool)ApplicationRuntime::param('cookieSecure'),
                'sameSite' => Cookie::SAME_SITE_LAX,
                'path' => '/',
                'expire' => $sessionExpiresAt,
            ]),
        ]);
    }

    public function issueFederatedLogin(User $user, Request $request): ServiceResult
    {
        return $this->issueLogin($user, $request);
    }

    public function issueAccessToken(User $user, ?int $issuedAt = null, ?int $authTime = null): string
    {
        $issuedAt = $issuedAt ?? $this->clock->now()->getTimestamp();
        if ($user->tokens_invalid_before) {
            $issuedAt = max($issuedAt, strtotime((string)$user->tokens_invalid_before) + 1);
        }

        return $this->jwt->issue($user->id, [
            'iss' => ApplicationRuntime::param('issuer'),
            'aud' => ApplicationRuntime::param('apiAudience'),
            'purpose' => JwtService::PURPOSE_FIRST_PARTY,
            'username' => $user->username,
            'email' => $user->email,
            'source' => $user->source,
            'auth_time' => $authTime ?? $issuedAt,
        ], null, $issuedAt);
    }

    public function currentUser(Request $request): ?User
    {
        return $this->authenticate($request)['user'];
    }

    public function authenticate(Request $request): array
    {
        $header = (string)$request->headers->get('Authorization', '');
        if (stripos($header, 'Bearer ') !== 0) {
            return ['user' => null, 'claims' => null, 'error' => 'missing bearer token'];
        }

        $claims = $this->jwt->verify(trim(substr($header, 7)));
        if (!$claims || !$this->hasValidAccessTokenProfile($claims)) {
            return ['user' => null, 'claims' => null, 'error' => 'invalid token'];
        }

        if (
            (bool)ApplicationRuntime::db()->createCommand(
                'SELECT EXISTS(SELECT 1 FROM revoked_access_tokens WHERE jti = :jti AND expires_at > now())',
                [':jti' => (string)$claims['jti']]
            )->queryScalar()
        ) {
            return ['user' => null, 'claims' => null, 'error' => 'token revoked'];
        }

        $purpose = (string)$claims['purpose'];
        if ($purpose === JwtService::PURPOSE_CLIENT_CREDENTIALS) {
            $client = OAuthClient::findOne([
                'id' => (string)$claims['sub'],
                'client_id' => (string)($claims['client_id'] ?? ''),
                'enabled' => true,
            ]);
            if (!$client) {
                return ['user' => null, 'claims' => null, 'error' => 'invalid token'];
            }
            return ['user' => null, 'client' => $client, 'claims' => $claims, 'error' => null];
        }

        if ($purpose === JwtService::PURPOSE_OAUTH_USER) {
            $client = OAuthClient::findOne([
                'client_id' => (string)$claims['client_id'],
                'enabled' => true,
            ]);
            if (!$client) {
                return ['user' => null, 'claims' => null, 'error' => 'invalid token'];
            }
        }

        $user = User::findOne(['id' => $claims['sub']]);
        if (!$user) {
            return ['user' => null, 'claims' => null, 'error' => 'invalid token'];
        }
        if ($user->status !== User::STATUS_ACTIVE) {
            return ['user' => null, 'claims' => null, 'error' => 'account is not active'];
        }

        if (
            $user->tokens_invalid_before
            && ($claims['iat'] ?? 0) <= strtotime($user->tokens_invalid_before)
        ) {
            return ['user' => null, 'claims' => null, 'error' => 'token revoked'];
        }

        return ['user' => $user, 'client' => null, 'claims' => $claims, 'error' => null];
    }

    public function revokeAccessToken(Request $request): void
    {
        $authentication = $this->authenticate($request);
        $claims = $authentication['claims'];
        if (!$claims) {
            return;
        }
        ApplicationRuntime::db()->createCommand(
            'INSERT INTO revoked_access_tokens(jti, expires_at) VALUES (:jti, to_timestamp(:exp)) ON CONFLICT (jti) DO NOTHING',
            [':jti' => (string)$claims['jti'], ':exp' => (int)$claims['exp']]
        )->execute();
    }

    public function currentClaims(Request $request): ?array
    {
        return $this->authenticate($request)['claims'];
    }

    public function tokenAllowsPermission(array $claims, string $resource, string $action): bool
    {
        if (($claims['purpose'] ?? '') === JwtService::PURPOSE_FIRST_PARTY) {
            return true;
        }

        $scopes = preg_split('/\s+/', trim((string)($claims['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        return in_array($resource . ':' . $action, $scopes, true);
    }

    private function hasValidAccessTokenProfile(array $claims): bool
    {
        if (
            ($claims['iss'] ?? null) !== ApplicationRuntime::param('issuer')
            || ($claims['aud'] ?? null) !== ApplicationRuntime::param('apiAudience')
        ) {
            return false;
        }

        $purpose = $claims['purpose'] ?? null;
        if ($purpose === JwtService::PURPOSE_FIRST_PARTY) {
            return !array_key_exists('client_id', $claims);
        }
        if ($purpose === JwtService::PURPOSE_OAUTH_USER) {
            return is_string($claims['client_id'] ?? null) && $claims['client_id'] !== '';
        }
        if ($purpose === JwtService::PURPOSE_CLIENT_CREDENTIALS) {
            return is_string($claims['client_id'] ?? null) && $claims['client_id'] !== '';
        }

        return false;
    }

    public function userBySession(string $sessionToken): ?User
    {
        $authentication = $this->sessionAuthentication($sessionToken);
        return $authentication ? $authentication['user'] : null;
    }

    public function sessionAuthentication(string $sessionToken): ?array
    {
        if ($sessionToken === '') {
            return null;
        }

        $session = Session::find()
            ->where([
                'token_hash' => hash('sha256', $sessionToken),
                'revoked_at' => null,
            ])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->one();

        if (!$session) {
            return null;
        }
        $user = User::findOne(['id' => $session->user_id, 'status' => User::STATUS_ACTIVE]);

        return $user ? [
            'user' => $user,
            'auth_time' => strtotime((string)$session->getAttribute('created_at')),
        ] : null;
    }

    public function logout(string $sessionToken): void
    {
        if ($sessionToken === '') {
            return;
        }

        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $session = ApplicationRuntime::db()->createCommand(
                'SELECT id, user_id FROM sessions
                 WHERE token_hash = :token_hash AND revoked_at IS NULL
                 FOR UPDATE',
                [':token_hash' => hash('sha256', $sessionToken)]
            )->queryOne();
            if ($session) {
                Session::updateAll(['revoked_at' => new Expression('now()')], ['id' => $session['id'], 'revoked_at' => null]);
                AuditService::record((string)$session['user_id'], 'logout', 'user', (string)$session['user_id']);
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function listSessions(string $userId): array
    {
        $sessions = Session::find()
            ->select(['id', 'user_id', 'ip', 'user_agent', 'expires_at', 'created_at'])
            ->where(['user_id' => $userId, 'revoked_at' => null])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->orderBy(['created_at' => SORT_DESC])
            ->asArray()
            ->all();

        return array_map(static function (array $session): array {
            $session['expires_at'] = Rfc3339::format($session['expires_at']);
            $session['created_at'] = Rfc3339::format($session['created_at']);
            return $session;
        }, $sessions);
    }

    public function revokeSession(string $userId, string $sessionId): bool
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            $updated = Session::updateAll(['revoked_at' => new Expression('now()')], [
                'id' => $sessionId,
                'user_id' => $userId,
                'revoked_at' => null,
            ]);
            if ($updated > 0) {
                AuditService::record($userId, 'session_revoked', 'session', $sessionId);
            }
            $transaction->commit();
            return $updated > 0;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeAllSessions(User $user): void
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            Session::updateAll(['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]);
            $user->tokens_invalid_before = new Expression('now()');
            if (!$user->save(false, ['tokens_invalid_before'])) {
                throw new \RuntimeException('failed to revoke user tokens');
            }
            AuditService::record($user->id, 'sessions_revoked_all', 'user', $user->id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function randomToken(int $bytes = 32): string
    {
        return $this->random->token($bytes);
    }

    private function isLocked(string $username, string $ip): bool
    {
        if ($username === '') {
            return false;
        }

        $attempt = LoginAttempt::findOne(['username' => $username, 'ip' => $ip]);
        if (!$attempt || !$attempt->locked_until) {
            return false;
        }

        return strtotime($attempt->locked_until) > $this->clock->now()->getTimestamp();
    }

    private function recordFailure(string $username, string $ip): int
    {
        if ($username === '') {
            return 0;
        }

        // Один UPSERT не теряет инкременты при параллельных неуспешных входах.
        return (int)ApplicationRuntime::db()->createCommand(
            'INSERT INTO login_attempts(username, ip, attempts, locked_until, updated_at)
             VALUES (:username, :ip, 1, NULL, now())
             ON CONFLICT (username, ip) DO UPDATE SET
                 attempts = login_attempts.attempts + 1,
                 locked_until = CASE
                     WHEN login_attempts.attempts + 1 >= 5 THEN now() + make_interval(secs => LEAST(
                         900,
                         (60 * power(2, LEAST(4, login_attempts.attempts + 1 - 5)))::integer
                     ))
                     ELSE NULL
                 END,
                 updated_at = now()
             RETURNING attempts',
            [':username' => $username, ':ip' => $ip]
        )->queryScalar();
    }

    public function registerLoginFailure(string $username, string $ip): void
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            AuditService::record(null, 'login_failed', 'user', $username, true);
            $attempts = $this->recordFailure($username, $ip);
            if ($attempts >= 5) {
                AuditService::record(null, 'login_locked', 'user', $username, true);
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function resetFailures(string $username, string $ip): void
    {
        if ($username === '') {
            return;
        }

        LoginAttempt::deleteAll(['username' => $username, 'ip' => $ip]);
    }

    private function failure(string $code, string $message, int $status): ServiceResult
    {
        return ServiceResult::failure(new ServiceError($code, $message, $status));
    }
}
