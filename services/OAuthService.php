<?php

namespace app\services;

use app\models\OAuthAuthorizationCode;
use app\models\OAuthClient;
use app\models\RefreshToken;
use app\models\User;
use Yii;
use yii\db\Expression;
use yii\web\Request;

class OAuthService
{
    public function listClients(): array
    {
        return array_map(static function (OAuthClient $client) {
            return $client->publicData();
        }, OAuthClient::find()->orderBy(['created_at' => SORT_DESC])->all());
    }

    public function getClient(string $id): ?array
    {
        $client = OAuthClient::findOne(['id' => $id]);
        return $client ? $client->publicData() : null;
    }

    public function createClient(array $body): array
    {
        $client = new OAuthClient();
        $client->client_id = (string)($body['client_id'] ?? '');
        $client->name = (string)($body['name'] ?? '');
        $client->type = (string)($body['type'] ?? OAuthClient::TYPE_CONFIDENTIAL);
        $client->redirect_uris = OAuthClient::toPgArray((array)($body['redirect_uris'] ?? []));
        $client->allowed_scopes = OAuthClient::toPgArray((array)($body['allowed_scopes'] ?? []));
        $client->post_logout_redirect_uris = OAuthClient::toPgArray((array)($body['post_logout_redirect_uris'] ?? []));
        $client->backchannel_logout_uri = (string)($body['backchannel_logout_uri'] ?? '');
        $client->skip_consent = (bool)($body['skip_consent'] ?? false);
        $client->enabled = (bool)($body['enabled'] ?? false);

        $plainSecret = null;
        if ($client->type === OAuthClient::TYPE_CONFIDENTIAL) {
            $plainSecret = (new AuthService())->randomToken(32);
            $client->client_secret_hash = PasswordHasher::hash($plainSecret);
        }

        if (!$client->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid client payload', 'details' => $client->errors]];
        }
        $client->refresh();

        $data = $client->publicData();
        if ($plainSecret !== null) {
            $data['client_secret'] = $plainSecret;
        }

        return ['status' => 201, 'data' => $data];
    }

    public function updateClient(string $id, array $body): array
    {
        $client = OAuthClient::findOne(['id' => $id]);
        if (!$client) {
            return ['status' => 404, 'data' => ['error' => 'client not found']];
        }

        $client->name = (string)($body['name'] ?? '');
        $client->redirect_uris = OAuthClient::toPgArray((array)($body['redirect_uris'] ?? []));
        $client->allowed_scopes = OAuthClient::toPgArray((array)($body['allowed_scopes'] ?? []));
        $client->post_logout_redirect_uris = OAuthClient::toPgArray((array)($body['post_logout_redirect_uris'] ?? []));
        $client->backchannel_logout_uri = (string)($body['backchannel_logout_uri'] ?? '');
        $client->skip_consent = (bool)($body['skip_consent'] ?? false);
        $client->enabled = (bool)($body['enabled'] ?? false);
        $client->updated_at = new Expression('now()');

        if (!$client->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid client payload', 'details' => $client->errors]];
        }
        $client->refresh();

        AuditService::record(null, 'oauth_client_updated', 'oauth_client', $client->client_id);
        return ['status' => 200, 'data' => $client->publicData()];
    }

    public function deleteClient(string $id): bool
    {
        $client = OAuthClient::findOne(['id' => $id]);
        if (!$client) {
            return false;
        }

        $client->delete();
        AuditService::record(null, 'oauth_client_deleted', 'oauth_client', $id);
        return true;
    }

    public function rotateSecret(string $id): array
    {
        $client = OAuthClient::findOne(['id' => $id, 'type' => OAuthClient::TYPE_CONFIDENTIAL]);
        if (!$client) {
            return ['status' => 404, 'data' => ['error' => 'confidential client not found']];
        }

        $secret = (new AuthService())->randomToken(32);
        OAuthClient::updateAll([
            'client_secret_hash' => PasswordHasher::hash($secret),
            'updated_at' => new Expression('now()'),
        ], ['id' => $client->id]);
        $client->refresh();

        AuditService::record(null, 'oauth_client_secret_rotated', 'oauth_client', $client->client_id);

        return ['status' => 200, 'data' => array_merge($client->publicData(), ['client_secret' => $secret])];
    }

    public function authorize(Request $request): array
    {
        $client = OAuthClient::findOne(['client_id' => $request->get('client_id'), 'enabled' => true]);
        if (!$client || $request->get('response_type') !== 'code') {
            return $this->oauthFail(400, 'invalid_request', 'client or response_type is invalid');
        }

        $redirectUri = (string)$request->get('redirect_uri');
        if (!in_array($redirectUri, OAuthClient::parsePgArray($client->redirect_uris), true)) {
            return $this->oauthFail(400, 'invalid_request', 'redirect_uri is not registered');
        }

        if (!$this->scopeAllowed($client, (string)$request->get('scope', ''))) {
            return $this->oauthFail(400, 'invalid_scope', 'requested scope is not allowed for this client');
        }

        if ($client->type === OAuthClient::TYPE_PUBLIC
            && ((string)$request->get('code_challenge', '') === '' || (string)$request->get('code_challenge_method', '') !== 'S256')) {
            return $this->oauthFail(400, 'invalid_request', 'PKCE S256 required');
        }

        $user = (new AuthService())->userBySession($request->cookies->getValue('sso_session', ''));
        if (!$user) {
            return $this->oauthFail(401, 'login_required', 'no active session, authenticate first');
        }

        if (!$client->skip_consent && !$this->hasConsent($user->id, $client->client_id, (string)$request->get('scope', ''))) {
            return $this->oauthFail(403, 'consent_required', 'user consent is required for the requested scopes');
        }

        $code = (new AuthService())->randomToken(32);
        $model = new OAuthAuthorizationCode();
        $model->code_hash = hash('sha256', $code);
        $model->client_id = $client->client_id;
        $model->user_id = $user->id;
        $model->redirect_uri = $redirectUri;
        $model->scope = (string)$request->get('scope', '');
        $model->code_challenge = (string)$request->get('code_challenge', '');
        $model->code_challenge_method = (string)$request->get('code_challenge_method', '');
        $model->nonce = (string)$request->get('nonce', '');
        $model->expires_at = gmdate('Y-m-d H:i:sP', time() + 300);
        $model->save(false);

        $query = ['code' => $code];
        if ($request->get('state') !== null) {
            $query['state'] = (string)$request->get('state');
        }

        $redirect = $this->withQueryParameters($redirectUri, $query);
        if ($redirect === null) {
            return $this->oauthFail(400, 'invalid_request', 'redirect_uri is invalid');
        }

        return ['ok' => true, 'redirect' => $redirect];
    }

    public function token(Request $request): array
    {
        $grantType = (string)$request->post('grant_type');
        $client = $this->clientFromRequest($request);

        if (!$client) {
            return $this->oauthFail(401, 'invalid_client', 'client authentication failed');
        }

        if ($grantType === 'authorization_code') {
            return $this->authorizationCodeToken($request, $client);
        }

        if ($grantType === 'refresh_token') {
            return $this->refreshToken($request, $client);
        }

        if ($grantType === 'client_credentials') {
            return $this->clientCredentialsToken($request, $client);
        }

        return $this->oauthFail(400, 'unsupported_grant_type', 'grant_type is not supported');
    }

    public function revoke(Request $request): array
    {
        $client = $this->clientFromRequest($request);
        if (!$client) {
            return ['ok' => false];
        }

        $token = RefreshToken::findOne([
            'token_hash' => hash('sha256', (string)$request->post('token')),
        ]);
        if (!$token || $token->client_id !== $client->client_id) {
            return ['ok' => true];
        }

        RefreshToken::updateAll(
            ['revoked_at' => new Expression('now()')],
            ['family_id' => $token->family_id, 'revoked_at' => null]
        );
        AuditService::record(null, 'refresh_token_revoked', 'oauth_client', $client->client_id);

        return ['ok' => true];
    }

    public function introspect(Request $request): array
    {
        if (!$this->clientFromRequest($request)) {
            return ['ok' => false];
        }

        $rawToken = (string)$request->post('token');
        $refreshToken = RefreshToken::findOne(['token_hash' => hash('sha256', $rawToken)]);
        if ($refreshToken) {
            if ($refreshToken->revoked_at
                || $refreshToken->rotated_at
                || strtotime((string)$refreshToken->expires_at) <= time()) {
                return ['ok' => true, 'data' => ['active' => false]];
            }

            return ['ok' => true, 'data' => [
                'active' => true,
                'token_type' => 'refresh_token',
                'client_id' => $refreshToken->client_id,
                'sub' => $refreshToken->user_id,
                'scope' => $refreshToken->scope,
                'exp' => strtotime((string)$refreshToken->expires_at),
                'iat' => strtotime((string)$refreshToken->created_at),
            ]];
        }

        $claims = (new JwtService())->verify($rawToken);
        if (!$claims) {
            return ['ok' => true, 'data' => ['active' => false]];
        }

        $data = [
            'active' => true,
            'token_type' => 'Bearer',
            'client_id' => (string)($claims['client_id'] ?? ''),
            'sub' => (string)($claims['sub'] ?? ''),
            'username' => (string)($claims['username'] ?? ''),
            'scope' => (string)($claims['scope'] ?? ''),
        ];
        if (isset($claims['exp'])) {
            $data['exp'] = (int)$claims['exp'];
        }

        return [
            'ok' => true,
            'data' => $data,
        ];
    }

    public function consentInfo(Request $request): array
    {
        $user = (new AuthService())->userBySession($request->cookies->getValue('sso_session', ''));
        if (!$user) {
            return $this->oauthFail(401, 'login_required', 'no active session');
        }

        $client = OAuthClient::findOne([
            'client_id' => $request->get('client_id'),
            'enabled' => true,
        ]);
        if (!$client) {
            return $this->oauthFail(400, 'invalid_request', 'invalid client credentials');
        }

        $scope = (string)$request->get('scope', '');
        if (!$this->scopeAllowed($client, $scope)) {
            return $this->oauthFail(400, 'invalid_scope', 'requested scope is not allowed for this client');
        }

        $granted = Yii::$app->db->createCommand(
            'SELECT scopes FROM user_consents WHERE user_id = :user_id AND client_id = :client_id',
            [':user_id' => $user->id, ':client_id' => $client->client_id]
        )->queryScalar();

        return [
            'ok' => true,
            'data' => [
                'client_id' => $client->client_id,
                'client_name' => $client->name,
                'requested_scopes' => $this->splitScopes($scope),
                'granted_scopes' => $this->splitScopes($granted === false ? '' : (string)$granted),
            ],
        ];
    }

    public function grantConsent(Request $request, array $body): array
    {
        $user = (new AuthService())->userBySession($request->cookies->getValue('sso_session', ''));
        if (!$user) {
            return $this->oauthFail(401, 'login_required', 'no active session');
        }

        $client = OAuthClient::findOne([
            'client_id' => $body['client_id'] ?? '',
            'enabled' => true,
        ]);
        if (!$client) {
            return $this->oauthFail(400, 'invalid_request', 'invalid client credentials');
        }

        $scope = (string)($body['scope'] ?? '');
        if (!$this->scopeAllowed($client, $scope)) {
            return $this->oauthFail(400, 'invalid_scope', 'requested scope is not allowed for this client');
        }

        $existing = Yii::$app->db->createCommand(
            'SELECT scopes FROM user_consents WHERE user_id = :user_id AND client_id = :client_id',
            [':user_id' => $user->id, ':client_id' => $client->client_id]
        )->queryScalar();
        $scopes = implode(' ', array_unique(array_merge(
            $this->splitScopes($existing === false ? '' : (string)$existing),
            $this->splitScopes($scope)
        )));
        Yii::$app->db->createCommand(
            'INSERT INTO user_consents(user_id, client_id, scopes) VALUES (:user_id, :client_id, :scopes)
             ON CONFLICT (user_id, client_id) DO UPDATE SET scopes = :scopes, updated_at = now()',
            [':user_id' => $user->id, ':client_id' => $client->client_id, ':scopes' => $scopes]
        )->execute();
        AuditService::record($user->id, 'consent_granted', 'oauth_client', $client->client_id);

        return ['ok' => true];
    }

    public function logout(Request $request): array
    {
        $sessionToken = $request->cookies->getValue('sso_session', '');
        $user = (new AuthService())->userBySession($sessionToken);
        $hint = $this->logoutParameter($request, 'id_token_hint');
        $redirect = $this->logoutParameter($request, 'post_logout_redirect_uri');
        $state = $this->logoutParameter($request, 'state');
        $client = null;
        $subject = '';

        if ($hint !== '') {
            $claims = (new OidcService())->verifyIdTokenHint($hint);
            if ($claims) {
                $subject = (string)$claims['sub'];
                $client = OAuthClient::findOne(['client_id' => (string)$claims['aud']]);
                if (!$user && !empty($claims['sub'])) {
                    $user = User::findOne(['id' => (string)$claims['sub'], 'status' => User::STATUS_ACTIVE]);
                }
            }
        }

        if ($sessionToken !== '') {
            (new AuthService())->logout($sessionToken);
        }
        if ($user) {
            RefreshToken::updateAll(
                ['revoked_at' => new Expression('now()')],
                ['user_id' => $user->id, 'revoked_at' => null]
            );
            AuditService::record($user->id, 'logout', 'user', $user->id);
            if ($subject === '') {
                $subject = $user->id;
            }
        }

        if ($client && $client->backchannel_logout_uri && $subject !== '') {
            (new OidcService())->sendBackchannelLogout(
                $subject,
                $client->client_id,
                (string)$client->backchannel_logout_uri
            );
        }

        if ($redirect === '') {
            return ['ok' => true, 'data' => ['status' => 'logged_out']];
        }

        if (!$client || !in_array($redirect, OAuthClient::parsePgArray($client->post_logout_redirect_uris), true)) {
            return $this->oauthFail(400, 'invalid_request', 'invalid post_logout_redirect_uri');
        }

        if ($state !== '') {
            $redirect = $this->withQueryParameters($redirect, ['state' => $state]);
            if ($redirect === null) {
                return $this->oauthFail(400, 'invalid_request', 'invalid post_logout_redirect_uri');
            }
        }

        return ['ok' => true, 'redirect' => $redirect];
    }

    private function logoutParameter(Request $request, string $name): string
    {
        $bodyValue = (string)$request->post($name, '');
        return $bodyValue !== '' ? $bodyValue : (string)$request->get($name, '');
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

        $replaced = array_fill_keys(array_keys($parameters), true);
        $parts = [];
        foreach ($rawQuery === '' ? [] : explode('&', $rawQuery) as $part) {
            $encodedKey = explode('=', $part, 2)[0];
            $key = rawurldecode(str_replace('+', ' ', $encodedKey));
            if (!isset($replaced[$key])) {
                $parts[] = $part;
            }
        }

        foreach ($parameters as $key => $value) {
            $parts[] = http_build_query([(string)$key => (string)$value]);
        }

        return $base . '?' . implode('&', $parts) . $fragment;
    }

    private function authorizationCodeToken(Request $request, OAuthClient $client): array
    {
        $code = Yii::$app->db->createCommand(
            'UPDATE oauth_authorization_codes
             SET used_at = now()
             WHERE code_hash = :code_hash AND used_at IS NULL AND expires_at > now()
             RETURNING client_id, user_id, redirect_uri, scope, code_challenge, code_challenge_method, nonce, created_at',
            [':code_hash' => hash('sha256', (string)$request->post('code'))]
        )->queryOne();

        if (!$code || $code['client_id'] !== $client->client_id || $code['redirect_uri'] !== $request->post('redirect_uri')) {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        if (!$this->verifyPkce($code, (string)$request->post('code_verifier', ''))) {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        return $this->tokenResponse(
            (string)$code['user_id'],
            $client,
            (string)$code['scope'],
            (string)$code['nonce'],
            null,
            strtotime((string)$code['created_at'])
        );
    }

    private function refreshToken(Request $request, OAuthClient $client): array
    {
        $refreshToken = (string)$request->post('refresh_token');
        if ($refreshToken === '') {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        $old = RefreshToken::find()
            ->where(['token_hash' => hash('sha256', $refreshToken), 'client_id' => $client->client_id])
            ->one();

        if (!$old) {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        if ($old->revoked_at || strtotime($old->expires_at) <= time()) {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        if ($old->rotated_at) {
            $this->revokeRefreshFamily((string)$old->family_id, $client);
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        $user = User::findOne(['id' => $old->user_id, 'status' => User::STATUS_ACTIVE]);
        if (!$user) {
            $this->revokeRefreshFamily((string)$old->family_id, $client);
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        // Атомарный guard: только один конкурентный refresh может пометить token rotated.
        $rotated = RefreshToken::updateAll(
            ['rotated_at' => new Expression('now()')],
            [
                'and',
                ['id' => $old->id, 'rotated_at' => null, 'revoked_at' => null],
                ['>', 'expires_at', new Expression('now()')],
            ]
        );
        if ($rotated === 0) {
            $this->revokeRefreshFamily((string)$old->family_id, $client);
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        return $this->tokenResponse(
            $old->user_id,
            $client,
            $old->scope,
            '',
            (string)$old->family_id,
            strtotime((string)$old->created_at)
        );
    }

    private function clientCredentialsToken(Request $request, OAuthClient $client): array
    {
        if ($client->type !== OAuthClient::TYPE_CONFIDENTIAL) {
            return $this->oauthFail(401, 'invalid_client', 'client authentication failed');
        }

        $scope = (string)$request->post('scope', '');
        if (!$this->scopeAllowed($client, $scope)) {
            return $this->oauthFail(400, 'invalid_scope', 'requested scope is not allowed for this client');
        }

        $claims = [
            'client_id' => $client->client_id,
            'username' => $client->client_id,
            'email' => '',
            'source' => 'client',
            'purpose' => 'client_credentials',
        ];
        if ($scope !== '') {
            $claims['scope'] = $scope;
        }
        $token = (new JwtService())->issue($client->id, $claims);

        $data = [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => Yii::$app->params['accessTokenTtl'],
        ];
        if ($scope !== '') {
            $data['scope'] = $scope;
        }

        return ['ok' => true, 'data' => $data];
    }

    private function tokenResponse(
        string $userId,
        OAuthClient $client,
        string $scope,
        string $nonce,
        ?string $familyId,
        int $authTime
    ): array
    {
        $user = User::findOne(['id' => $userId]);
        $claims = [
            'client_id' => $client->client_id,
            'username' => $user ? $user->username : '',
            'email' => $user ? $user->email : '',
            'source' => $user ? $user->source : '',
        ];
        if ($scope !== '') {
            $claims['scope'] = $scope;
        }
        $accessToken = (new JwtService())->issue($userId, $claims);
        $refreshToken = (new AuthService())->randomToken(32);

        $rt = new RefreshToken();
        $rt->token_hash = hash('sha256', $refreshToken);
        $rt->family_id = $familyId ?: Yii::$app->db->createCommand('SELECT gen_random_uuid()')->queryScalar();
        $rt->user_id = $userId;
        $rt->client_id = $client->client_id;
        $rt->scope = $scope;
        $rt->expires_at = gmdate('Y-m-d H:i:sP', time() + Yii::$app->params['refreshTokenTtl']);
        $rt->save(false);

        $data = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => Yii::$app->params['accessTokenTtl'],
            'refresh_token' => $refreshToken,
            'scope' => $scope,
        ];

        if (in_array('openid', preg_split('/\s+/', trim($scope)), true)) {
            $data['id_token'] = (new OidcService())->issueIdToken(
                $userId,
                $client->client_id,
                $nonce,
                $authTime
            );
        }

        return ['ok' => true, 'data' => $data];
    }

    private function revokeRefreshFamily(string $familyId, OAuthClient $client): void
    {
        RefreshToken::updateAll(
            ['revoked_at' => new Expression('now()')],
            ['family_id' => $familyId, 'revoked_at' => null]
        );
        AuditService::record(null, 'refresh_token_reuse_detected', 'oauth_client', $client->client_id);
    }

    private function clientFromRequest(Request $request): ?OAuthClient
    {
        $clientId = (string)$request->post('client_id', '');
        $clientSecret = (string)$request->post('client_secret', '');

        $authorization = (string)$request->headers->get('Authorization', '');
        if (strlen($authorization) >= 6 && strcasecmp(substr($authorization, 0, 6), 'Basic ') === 0) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [$basicId, $basicSecret] = explode(':', $decoded, 2);
                // RFC 6749 §2.3.1 использует application/x-www-form-urlencoded,
                // поэтому знак "+" декодируется как пробел, как url.QueryUnescape в Go.
                $clientId = urldecode($basicId);
                $clientSecret = urldecode($basicSecret);
            }
        }

        $client = OAuthClient::findOne(['client_id' => $clientId, 'enabled' => true]);
        if (!$client) {
            return null;
        }

        if ($client->type === OAuthClient::TYPE_CONFIDENTIAL
            && !PasswordHasher::verify($clientSecret, (string)$client->client_secret_hash)) {
            return null;
        }

        return $client;
    }

    private function scopeAllowed(OAuthClient $client, string $scope): bool
    {
        $requested = $this->splitScopes($scope);
        $allowed = OAuthClient::parsePgArray($client->allowed_scopes);

        return empty(array_diff($requested, $allowed));
    }

    private function splitScopes(string $scope): array
    {
        if (trim($scope) === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($scope))));
    }

    private function hasConsent(string $userId, string $clientId, string $scope): bool
    {
        $row = Yii::$app->db->createCommand('SELECT scopes FROM user_consents WHERE user_id = :user_id AND client_id = :client_id', [
            ':user_id' => $userId,
            ':client_id' => $clientId,
        ])->queryOne();

        if (!$row) {
            return false;
        }

        $requested = array_filter(preg_split('/\s+/', trim($scope)));
        $granted = array_filter(preg_split('/\s+/', trim((string)$row['scopes'])));
        return empty(array_diff($requested, $granted));
    }

    private function verifyPkce(array $code, string $verifier): bool
    {
        if (empty($code['code_challenge'])) {
            return true;
        }

        return hash_equals(
            (string)$code['code_challenge'],
            JwtService::base64url(hash('sha256', $verifier, true))
        );
    }

    private function oauthFail(int $status, string $error, string $description): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'description' => $description];
    }
}
