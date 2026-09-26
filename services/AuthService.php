<?php

namespace app\services;

use app\components\Rfc3339;
use app\models\LoginAttempt;
use app\models\OAuthClient;
use app\models\Session;
use app\models\User;
use Yii;
use yii\db\Expression;
use yii\web\Cookie;
use yii\web\Request;

class AuthService
{
    public function login(array $body, Request $request): array
    {
        $login = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $ip = (string)$request->userIP;

        if ($this->isLocked($login, $ip)) {
            return ['ok' => false, 'status' => 429, 'error' => 'too many failed attempts, try again later'];
        }

        if ($password === '') {
            $this->registerLoginFailure($login, $ip);
            return ['ok' => false, 'status' => 401, 'error' => 'invalid credentials'];
        }

        $user = User::findOne(['username' => $login]);

        if ($user && $user->source === User::SOURCE_LOCAL) {
            if ($user->status !== User::STATUS_ACTIVE || !$user->password_hash) {
                return ['ok' => false, 'status' => 401, 'error' => 'invalid credentials'];
            }
            if (!PasswordHasher::verify($password, (string)$user->password_hash)) {
                $this->registerLoginFailure($login, $ip);
                return ['ok' => false, 'status' => 401, 'error' => 'invalid credentials'];
            }
        } else {
            $user = (new LdapService())->authenticate($login, $password);
        }

        if (!$user || $user->status !== User::STATUS_ACTIVE) {
            $this->registerLoginFailure($login, $ip);
            return ['ok' => false, 'status' => 401, 'error' => 'invalid credentials'];
        }

        if ($user->mfa_enabled) {
            return ['ok' => true, 'data' => (new MfaService())->createLoginChallenge($user)];
        }

        $this->resetFailures($login, $ip);
        return $this->issueLogin($user, $request);
    }

    public function verifyMfaLogin(array $body, Request $request): array
    {
        $mfaToken = (string)($body['mfa_token'] ?? $body['challenge_token'] ?? '');
        $mfa = new MfaService();
        $user = $mfa->loginChallengeUser($mfaToken);
        if (!$user) {
            return ['ok' => false, 'status' => 401, 'error' => 'invalid mfa token'];
        }

        $login = (string)$user->username;
        $ip = (string)$request->userIP;
        if ($this->isLocked($login, $ip)) {
            return ['ok' => false, 'status' => 429, 'error' => 'too many failed attempts, try again later'];
        }

        if (!$mfa->completeLoginChallenge($mfaToken, $user, (string)($body['code'] ?? ''))) {
            $this->registerLoginFailure($login, $ip);
            return ['ok' => false, 'status' => 401, 'error' => 'invalid code'];
        }

        $this->resetFailures($login, $ip);
        return $this->issueLogin($user, $request);
    }

    private function issueLogin(User $user, Request $request): array
    {
        $sessionToken = $this->randomToken();
        $sessionExpiresAt = time() + Yii::$app->params['sessionTtl'];
        $session = new Session();
        $session->user_id = $user->id;
        $session->token_hash = hash('sha256', $sessionToken);
        $session->ip = $request->userIP;
        $session->user_agent = $request->userAgent;
        $session->expires_at = gmdate('Y-m-d H:i:sP', $sessionExpiresAt);
        $session->save(false);

        User::updateAll(['last_login_at' => new Expression('now()')], ['id' => $user->id]);
        AuditService::record($user->id, 'login_success', 'user', $user->id, true);

        $issuedAt = time();
        if ($user->tokens_invalid_before) {
            $issuedAt = max($issuedAt, strtotime((string)$user->tokens_invalid_before) + 1);
        }

        $jwt = $this->issueAccessToken($user, $issuedAt);

        return [
            'ok' => true,
            'cookie' => new Cookie([
                'name' => 'sso_session',
                'value' => $sessionToken,
                'httpOnly' => true,
                'secure' => Yii::$app->params['cookieSecure'],
                'sameSite' => Cookie::SAME_SITE_LAX,
                'path' => '/',
                'expire' => time() + Yii::$app->params['sessionTtl'],
            ]),
            'data' => [
                'access_token' => $jwt,
                'access_token_expires_at' => gmdate(
                    'Y-m-d\\TH:i:s\\Z',
                    $issuedAt + Yii::$app->params['accessTokenTtl']
                ),
                'session_token' => $sessionToken,
                'session_expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $sessionExpiresAt),
                'user' => $user->publicData(),
            ],
        ];
    }

    public function issueFederatedLogin(User $user, Request $request): array
    {
        return $this->issueLogin($user, $request);
    }

    public function issueAccessToken(User $user, ?int $issuedAt = null): string
    {
        $issuedAt = $issuedAt ?? time();
        if ($user->tokens_invalid_before) {
            $issuedAt = max($issuedAt, strtotime((string)$user->tokens_invalid_before) + 1);
        }

        return (new JwtService())->issue($user->id, [
            'username' => $user->username,
            'email' => $user->email,
            'source' => $user->source,
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

        $claims = (new JwtService())->verify(trim(substr($header, 7)));
        if (!$claims || empty($claims['sub']) || !empty($claims['purpose'])) {
            return ['user' => null, 'claims' => null, 'error' => 'invalid token'];
        }

        if (!empty($claims['client_id'])) {
            $client = OAuthClient::findOne([
                'client_id' => (string)$claims['client_id'],
                'enabled' => true,
            ]);
            if (!$client || ($claims['aud'] ?? '') !== Yii::$app->params['apiAudience']) {
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

        if ($user->tokens_invalid_before
            && ($claims['iat'] ?? 0) <= strtotime($user->tokens_invalid_before)) {
            return ['user' => null, 'claims' => null, 'error' => 'token revoked'];
        }

        return ['user' => $user, 'claims' => $claims, 'error' => null];
    }

    public function currentClaims(Request $request): ?array
    {
        $header = (string)$request->headers->get('Authorization', '');
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $claims = (new JwtService())->verify(trim(substr($header, 7)));
        if (!$claims || empty($claims['sub'])) {
            return null;
        }

        return $claims;
    }

    public function tokenAllowsPermission(array $claims, string $resource, string $action): bool
    {
        if (empty($claims['client_id'])) {
            return true;
        }

        $scopes = preg_split('/\s+/', trim((string)($claims['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        return in_array($resource . ':' . $action, $scopes, true);
    }

    public function userBySession(string $sessionToken): ?User
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

        return $session ? User::findOne(['id' => $session->user_id, 'status' => User::STATUS_ACTIVE]) : null;
    }

    public function logout(string $sessionToken): void
    {
        if ($sessionToken === '') {
            return;
        }

        Session::updateAll(['revoked_at' => new Expression('now()')], ['token_hash' => hash('sha256', $sessionToken), 'revoked_at' => null]);
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
        return Session::updateAll(['revoked_at' => new Expression('now()')], [
            'id' => $sessionId,
            'user_id' => $userId,
            'revoked_at' => null,
        ]) > 0;
    }

    public function revokeAllSessions(User $user): void
    {
        Session::updateAll(['revoked_at' => new Expression('now()')], ['user_id' => $user->id, 'revoked_at' => null]);
        $user->tokens_invalid_before = new Expression('now()');
        $user->save(false, ['tokens_invalid_before']);
    }

    public function randomToken(int $bytes = 32): string
    {
        return JwtService::base64url(random_bytes($bytes));
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

        return strtotime($attempt->locked_until) > time();
    }

    private function recordFailure(string $username, string $ip): void
    {
        if ($username === '') {
            return;
        }

        $attempt = LoginAttempt::findOne(['username' => $username, 'ip' => $ip]);
        if (!$attempt) {
            $attempt = new LoginAttempt();
            $attempt->username = $username;
            $attempt->ip = $ip;
            $attempt->attempts = 0;
        }

        // Как в Go-сервисе: после 5 ошибок включается экспоненциальная блокировка до 15 минут.
        $attempt->attempts = ((int)$attempt->attempts) + 1;
        $attempt->updated_at = new Expression('now()');
        $locked = $attempt->attempts >= 5;
        if ($locked) {
            $power = max(0, $attempt->attempts - 5);
            $seconds = min(900, 60 * (2 ** $power));
            $attempt->locked_until = gmdate('Y-m-d H:i:sP', time() + $seconds);
        }

        $attempt->save(false);
        if ($locked) {
            AuditService::record(null, 'login_locked', 'user', $username, true);
        }
    }

    private function registerLoginFailure(string $username, string $ip): void
    {
        AuditService::record(null, 'login_failed', 'user', $username, true);
        $this->recordFailure($username, $ip);
    }

    private function resetFailures(string $username, string $ip): void
    {
        if ($username === '') {
            return;
        }

        LoginAttempt::deleteAll(['username' => $username, 'ip' => $ip]);
    }
}
