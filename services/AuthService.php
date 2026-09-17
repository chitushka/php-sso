<?php

namespace app\services;

use app\models\LoginAttempt;
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
            AuditService::record(null, 'login_locked', 'user', $login);
            return ['ok' => false, 'status' => 429, 'error' => 'too many failed attempts, try again later'];
        }

        $user = User::find()
            ->where(['or', ['username' => $login], ['email' => $login]])
            ->one();

        $localPasswordValid = $user
            && $user->status === User::STATUS_ACTIVE
            && $user->password_hash
            && password_verify($password, (string)$user->password_hash);

        if (!$localPasswordValid) {
            $user = (new LdapService())->authenticate($login, $password);
        }

        if (!$user || $user->status !== User::STATUS_ACTIVE) {
            $this->recordFailure($login, $ip);
            AuditService::record($user ? $user->id : null, 'login_failed', 'user', $login);
            return ['ok' => false, 'status' => 401, 'error' => 'invalid credentials'];
        }

        if ($user->mfa_enabled) {
            $this->resetFailures($login, $ip);
            AuditService::record($user->id, 'mfa_login_challenge_created', 'user', $user->id);
            return ['ok' => true, 'data' => (new MfaService())->createLoginChallenge($user)];
        }

        $this->resetFailures($login, $ip);
        return $this->issueLogin($user, $request);
    }

    public function verifyMfaLogin(array $body, Request $request): array
    {
        $mfaToken = (string)($body['mfa_token'] ?? $body['challenge_token'] ?? '');
        $user = (new MfaService())->verifyLoginChallenge(
            $mfaToken,
            (string)($body['code'] ?? '')
        );
        if (!$user) {
            return ['ok' => false, 'status' => 401, 'error' => 'invalid mfa challenge or code'];
        }

        return $this->issueLogin($user, $request);
    }

    private function issueLogin(User $user, Request $request): array
    {
        $sessionToken = $this->randomToken();
        $session = new Session();
        $session->user_id = $user->id;
        $session->token_hash = hash('sha256', $sessionToken);
        $session->ip = $request->userIP;
        $session->user_agent = $request->userAgent;
        $session->expires_at = gmdate('Y-m-d H:i:sP', time() + Yii::$app->params['sessionTtl']);
        $session->save(false);

        $user->last_login_at = new Expression('now()');
        $user->save(false, ['last_login_at']);
        AuditService::record($user->id, 'login_success', 'user', $user->id);

        $jwt = (new JwtService())->issue($user->id, [
            'username' => $user->username,
            'source' => $user->source,
        ]);

        return [
            'ok' => true,
            'cookie' => new Cookie([
                'name' => 'sso_session',
                'value' => $sessionToken,
                'httpOnly' => true,
                'secure' => Yii::$app->params['cookieSecure'],
                'sameSite' => Cookie::SAME_SITE_LAX,
                'expire' => time() + Yii::$app->params['sessionTtl'],
            ]),
            'data' => [
                'access_token' => $jwt,
                'token_type' => 'Bearer',
                'expires_in' => Yii::$app->params['accessTokenTtl'],
                'session_expires_at' => $session->expires_at,
                'user' => $user->publicData(),
            ],
        ];
    }

    public function issueFederatedLogin(User $user, Request $request): array
    {
        return $this->issueLogin($user, $request);
    }

    public function currentUser(Request $request): ?User
    {
        $claims = $this->currentClaims($request);
        if (!$claims || empty($claims['sub'])) {
            return null;
        }

        $user = User::findOne(['id' => $claims['sub']]);
        if (!$user || $user->status !== User::STATUS_ACTIVE) {
            return null;
        }

        if ($user->tokens_invalid_before && ($claims['iat'] ?? 0) <= strtotime($user->tokens_invalid_before)) {
            return null;
        }

        return $user;
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
        return Session::find()
            ->select(['id', 'user_id', 'ip', 'user_agent', 'expires_at', 'revoked_at', 'created_at'])
            ->where(['user_id' => $userId, 'revoked_at' => null])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->orderBy(['created_at' => SORT_DESC])
            ->asArray()
            ->all();
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
        AuditService::record($user->id, 'sessions_revoked_all', 'user', $user->id);
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
        if ($attempt->attempts >= 5) {
            $power = max(0, $attempt->attempts - 5);
            $seconds = min(900, 60 * (2 ** $power));
            $attempt->locked_until = gmdate('Y-m-d H:i:sP', time() + $seconds);
        }

        $attempt->save(false);
    }

    private function resetFailures(string $username, string $ip): void
    {
        if ($username === '') {
            return;
        }

        LoginAttempt::deleteAll(['username' => $username, 'ip' => $ip]);
    }
}
