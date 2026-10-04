<?php

namespace app\services;

use app\models\OAuthAuthorizationCode;
use app\models\OAuthClient;
use app\models\RefreshToken;
use app\models\Session;
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
        $client->allow_loopback_redirects = (bool)($body['allow_loopback_redirects'] ?? false);
        $client->enabled = (bool)($body['enabled'] ?? false);

        if (!$this->redirectUrisAreSafe($client)) {
            return ['status' => 400, 'data' => ['error' => 'redirect URIs violate the client redirect policy']];
        }
        if (!$this->backchannelLogoutUriIsSafe((string)$client->backchannel_logout_uri)) {
            return ['status' => 400, 'data' => ['error' => 'backchannel_logout_uri must be a trusted HTTPS URL']];
        }

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
        $client->allow_loopback_redirects = (bool)($body['allow_loopback_redirects'] ?? false);
        $client->enabled = (bool)($body['enabled'] ?? false);
        $client->updated_at = new Expression('now()');

        if (!$this->redirectUrisAreSafe($client)) {
            return ['status' => 400, 'data' => ['error' => 'redirect URIs violate the client redirect policy']];
        }
        if (!$this->backchannelLogoutUriIsSafe((string)$client->backchannel_logout_uri)) {
            return ['status' => 400, 'data' => ['error' => 'backchannel_logout_uri must be a trusted HTTPS URL']];
        }

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
        if (!$client) {
            return $this->oauthFail(400, 'invalid_request', 'client is invalid');
        }

        $redirectUri = (string)$request->get('redirect_uri');
        if (!$this->redirectUriAllowed($client, $redirectUri)) {
            return $this->oauthFail(400, 'invalid_request', 'redirect_uri is not registered');
        }

        $state = (string)$request->get('state', '');
        if ($state === '' || strlen($state) > 512 || preg_match('/[\x00-\x1F\x7F]/', $state)) {
            return $this->authorizeRedirectError($redirectUri, $state, 'invalid_request', 'state is required');
        }
        if ($request->get('response_type') !== 'code') {
            return $this->authorizeRedirectError(
                $redirectUri,
                $state,
                'unsupported_response_type',
                'only authorization code response_type is supported'
            );
        }

        $scope = (string)$request->get('scope', '');
        if (!$this->scopeAllowed($client, (string)$request->get('scope', ''))) {
            return $this->authorizeRedirectError(
                $redirectUri,
                $state,
                'invalid_scope',
                'requested scope is not allowed for this client'
            );
        }

        $challenge = (string)$request->get('code_challenge', '');
        if (
            preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1
            || (string)$request->get('code_challenge_method', '') !== 'S256'
        ) {
            return $this->authorizeRedirectError($redirectUri, $state, 'invalid_request', 'PKCE S256 required');
        }

        $nonce = (string)$request->get('nonce', '');
        if (
            in_array('openid', $this->splitScopes($scope), true)
            && ($nonce === '' || strlen($nonce) > 512 || preg_match('/[\x00-\x1F\x7F]/', $nonce))
        ) {
            return $this->authorizeRedirectError(
                $redirectUri,
                $state,
                'invalid_request',
                'nonce is required for openid scope'
            );
        }

        $user = (new AuthService())->userBySession($request->cookies->getValue('sso_session', ''));
        if (!$user) {
            return $this->authorizeRedirectError(
                $redirectUri,
                $state,
                'login_required',
                'no active session, authenticate first'
            );
        }

        if (!$client->skip_consent && !$this->hasConsent($user->id, $client->client_id, $scope)) {
            return $this->authorizeRedirectError(
                $redirectUri,
                $state,
                'consent_required',
                'user consent is required for the requested scopes'
            );
        }

        $code = (new AuthService())->randomToken(32);
        $query = ['code' => $code, 'state' => $state, 'iss' => (string)Yii::$app->params['issuer']];
        $redirect = $this->withQueryParameters($redirectUri, $query);
        if ($redirect === null) {
            return $this->oauthFail(400, 'invalid_request', 'redirect_uri is invalid');
        }

        $model = new OAuthAuthorizationCode();
        $model->code_hash = hash('sha256', $code);
        $model->client_id = $client->client_id;
        $model->user_id = $user->id;
        $model->redirect_uri = $redirectUri;
        $model->scope = $scope;
        $model->code_challenge = $challenge;
        $model->code_challenge_method = 'S256';
        $model->nonce = $nonce;
        $model->expires_at = gmdate('Y-m-d H:i:sP', time() + 300);
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$model->save(false)) {
                throw new \RuntimeException('failed to persist authorization code');
            }
            AuditService::record($user->id, 'authorization_code_issued', 'oauth_client', $client->client_id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->authorizeRedirectError(
                $redirectUri,
                $state,
                'server_error',
                'authorization code could not be issued'
            );
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

        $transaction = Yii::$app->db->beginTransaction();
        try {
            RefreshToken::updateAll(
                ['revoked_at' => new Expression('now()')],
                ['family_id' => $token->family_id, 'revoked_at' => null]
            );
            AuditService::record(null, 'refresh_token_revoked', 'oauth_client', $client->client_id);
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->oauthFail(500, 'server_error', 'token revocation failed');
        }

        return ['ok' => true];
    }

    public function introspect(Request $request): array
    {
        $caller = $this->clientFromRequest($request);
        if (!$caller) {
            return ['ok' => false];
        }

        $rawToken = (string)$request->post('token');
        $refreshToken = RefreshToken::findOne(['token_hash' => hash('sha256', $rawToken)]);
        if ($refreshToken) {
            if ($refreshToken->client_id !== $caller->client_id) {
                return ['ok' => true, 'data' => ['active' => false]];
            }
            if (
                $refreshToken->revoked_at
                || $refreshToken->rotated_at
                || strtotime((string)$refreshToken->expires_at) <= time()
            ) {
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
        if (
            !$claims
            || ($claims['client_id'] ?? '') !== $caller->client_id
            || ($claims['aud'] ?? '') !== Yii::$app->params['apiAudience']
        ) {
            return ['ok' => true, 'data' => ['active' => false]];
        }

        $authentication = $this->authenticateRawAccessToken($rawToken);
        if (!$authentication) {
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

    public function listUserConsents(string $userId): array
    {
        $rows = Yii::$app->db->createCommand(
            'SELECT c.client_id, c.scopes, c.granted_at, c.updated_at, oc.name AS client_name
             FROM user_consents c
             JOIN oauth_clients oc ON oc.client_id = c.client_id
             WHERE c.user_id = :user_id
             ORDER BY c.updated_at DESC, c.client_id ASC',
            [':user_id' => $userId]
        )->queryAll();

        return array_map(function (array $row): array {
            return [
                'client_id' => (string)$row['client_id'],
                'client_name' => (string)$row['client_name'],
                'scopes' => $this->splitScopes((string)$row['scopes']),
                'granted_at' => \app\components\Rfc3339::format($row['granted_at']),
                'updated_at' => \app\components\Rfc3339::format($row['updated_at']),
            ];
        }, $rows);
    }

    public function revokeUserConsent(string $userId, string $clientId): bool
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $deleted = Yii::$app->db->createCommand()->delete('user_consents', [
                'user_id' => $userId,
                'client_id' => $clientId,
            ])->execute();
            if ($deleted > 0) {
                RefreshToken::updateAll(
                    ['revoked_at' => new Expression('now()')],
                    ['user_id' => $userId, 'client_id' => $clientId, 'revoked_at' => null]
                );
                AuditService::record($userId, 'consent_revoked', 'oauth_client', $clientId);
            }
            $transaction->commit();
            return $deleted > 0;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
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

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $existing = Yii::$app->db->createCommand(
                'SELECT scopes FROM user_consents WHERE user_id = :user_id AND client_id = :client_id FOR UPDATE',
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
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->oauthFail(500, 'server_error', 'consent could not be persisted');
        }

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
        $hintClaims = null;

        if ($hint !== '') {
            $hintClaims = (new OidcService())->verifyIdTokenHint($hint);
            if ($hintClaims) {
                $subject = (string)$hintClaims['sub'];
                $client = OAuthClient::findOne(['client_id' => (string)$hintClaims['aud'], 'enabled' => true]);
                if (!$user && !empty($hintClaims['sub']) && $request->isPost) {
                    $user = User::findOne(['id' => (string)$hintClaims['sub'], 'status' => User::STATUS_ACTIVE]);
                }
            }
        }

        // Top-level GET допустим только как RP-initiated logout, привязанный к текущей browser-сессии.
        if (
            $request->isGet
            && (!$user || !$hintClaims || !$client || !hash_equals((string)$user->id, (string)$hintClaims['sub']))
        ) {
            return $this->oauthFail(400, 'invalid_request', 'GET logout requires a valid id_token_hint for the active session');
        }
        if (
            $redirect !== ''
            && (!$client || !in_array($redirect, OAuthClient::parsePgArray($client->post_logout_redirect_uris), true))
        ) {
            return $this->oauthFail(400, 'invalid_request', 'invalid post_logout_redirect_uri');
        }
        if (strlen($state) > 512 || preg_match('/[\x00-\x1F\x7F]/', $state)) {
            return $this->oauthFail(400, 'invalid_request', 'invalid state');
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if ($sessionToken !== '') {
                Session::updateAll(
                    ['revoked_at' => new Expression('now()')],
                    ['token_hash' => hash('sha256', $sessionToken), 'revoked_at' => null]
                );
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
            $transaction->commit();
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->oauthFail(500, 'server_error', 'logout failed');
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

        if ($state !== '') {
            $redirect = $this->withQueryParameters($redirect, ['state' => $state]);
            if ($redirect === null) {
                return $this->oauthFail(400, 'invalid_request', 'invalid post_logout_redirect_uri');
            }
        }

        return ['ok' => true, 'redirect' => $redirect];
    }

    private function authorizeRedirectError(
        string $redirectUri,
        string $state,
        string $error,
        string $description
    ): array {
        $redirect = $this->withQueryParameters($redirectUri, [
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
            'iss' => (string)Yii::$app->params['issuer'],
        ]);
        if ($redirect === null) {
            return $this->oauthFail(400, $error, $description);
        }

        return ['ok' => true, 'redirect' => $redirect];
    }

    private function redirectUriAllowed(OAuthClient $client, string $requested): bool
    {
        foreach (OAuthClient::parsePgArray($client->redirect_uris) as $registered) {
            if (hash_equals($registered, $requested)) {
                return true;
            }
            if (
                !$client->allow_loopback_redirects
                || !$this->redirectUriIsSafe($registered, true)
                || !$this->redirectUriIsSafe($requested, true)
            ) {
                continue;
            }
            $registeredParts = parse_url($registered);
            $requestedParts = parse_url($requested);
            if (
                strtolower((string)($registeredParts['scheme'] ?? '')) !== 'http'
                || strtolower((string)($requestedParts['scheme'] ?? '')) !== 'http'
                || !$this->isLoopbackHost((string)($registeredParts['host'] ?? ''))
                || !$this->isLoopbackHost((string)($requestedParts['host'] ?? ''))
            ) {
                continue;
            }
            unset($registeredParts['port'], $requestedParts['port']);
            if ($registeredParts === $requestedParts) {
                return true;
            }
        }

        return false;
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
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $code = Yii::$app->db->createCommand(
                'SELECT id, client_id, user_id, redirect_uri, scope, code_challenge, code_challenge_method, nonce, created_at
                 FROM oauth_authorization_codes
                 WHERE code_hash = :code_hash AND used_at IS NULL AND expires_at > now()
                 FOR UPDATE',
                [':code_hash' => hash('sha256', (string)$request->post('code'))]
            )->queryOne();
            if (
                !$code || $code['client_id'] !== $client->client_id || $code['redirect_uri'] !== $request->post('redirect_uri')
                || !$this->verifyPkce($code, (string)$request->post('code_verifier', ''))
            ) {
                $transaction->rollBack();
                return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
            }
            $consumed = Yii::$app->db->createCommand()->update(
                'oauth_authorization_codes',
                ['used_at' => new Expression('now()')],
                ['id' => $code['id'], 'used_at' => null]
            )->execute();
            if ($consumed !== 1) {
                throw new \RuntimeException('failed to consume authorization code');
            }
            $result = $this->tokenResponse(
                (string)$code['user_id'],
                $client,
                (string)$code['scope'],
                (string)$code['nonce'],
                null,
                strtotime((string)$code['created_at'])
            );
            $transaction->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->oauthFail(500, 'server_error', 'token exchange failed');
        }
    }

    private function refreshToken(Request $request, OAuthClient $client): array
    {
        $refreshToken = (string)$request->post('refresh_token');
        if ($refreshToken === '') {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        $transaction = Yii::$app->db->beginTransaction();
        $oldId = Yii::$app->db->createCommand(
            'SELECT id FROM refresh_tokens WHERE token_hash = :hash AND client_id = :client_id FOR UPDATE',
            [':hash' => hash('sha256', $refreshToken), ':client_id' => $client->client_id]
        )->queryScalar();
        $old = $oldId ? RefreshToken::findOne(['id' => $oldId]) : null;

        if (!$old) {
            $transaction->rollBack();
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        if ($old->revoked_at || strtotime($old->expires_at) <= time()) {
            $transaction->rollBack();
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        if ($old->rotated_at) {
            $this->revokeRefreshFamily((string)$old->family_id, $client);
            $transaction->commit();
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        $user = User::findOne(['id' => $old->user_id, 'status' => User::STATUS_ACTIVE]);
        if (!$user) {
            $this->revokeRefreshFamily((string)$old->family_id, $client);
            $transaction->commit();
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
            $transaction->commit();
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        try {
            $result = $this->tokenResponse(
                $old->user_id,
                $client,
                $old->scope,
                '',
                (string)$old->family_id,
                strtotime((string)$old->created_at)
            );
            $transaction->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            return $this->oauthFail(500, 'server_error', 'token rotation failed');
        }
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
            'iss' => Yii::$app->params['issuer'],
            'client_id' => $client->client_id,
            'aud' => Yii::$app->params['apiAudience'],
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
    ): array {
        $user = User::findOne(['id' => $userId]);
        $claims = [
            'iss' => Yii::$app->params['issuer'],
            'client_id' => $client->client_id,
            'aud' => Yii::$app->params['apiAudience'],
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
        if (!$rt->save(false)) {
            throw new \RuntimeException('failed to persist refresh token');
        }

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
        $body = $request->getBodyParams();
        $body = is_array($body) ? $body : [];

        $authorization = (string)$request->headers->get('Authorization', '');
        $usesBasic = $authorization !== '';
        if ($usesBasic) {
            if (
                strlen($authorization) < 7
                || strcasecmp(substr($authorization, 0, 6), 'Basic ') !== 0
                || array_key_exists('client_id', $body)
                || array_key_exists('client_secret', $body)
            ) {
                return null;
            }
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [$basicId, $basicSecret] = explode(':', $decoded, 2);
                // RFC 6749 §2.3.1 использует application/x-www-form-urlencoded,
                // поэтому знак "+" декодируется как пробел, как url.QueryUnescape в Go.
                $clientId = urldecode($basicId);
                $clientSecret = urldecode($basicSecret);
            } else {
                return null;
            }
        }

        $client = OAuthClient::findOne(['client_id' => $clientId, 'enabled' => true]);
        if (!$client) {
            return null;
        }

        if ($client->type === OAuthClient::TYPE_CONFIDENTIAL) {
            if (
                $clientSecret === ''
                || !PasswordHasher::verify($clientSecret, (string)$client->client_secret_hash)
            ) {
                return null;
            }
        } elseif ($usesBasic || $clientSecret !== '') {
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

    private function backchannelLogoutUriIsSafe(string $uri): bool
    {
        return $uri === '' || (new OutboundHttpService())->isValidEndpoint($uri);
    }

    private function redirectUrisAreSafe(OAuthClient $client): bool
    {
        if ($client->allow_loopback_redirects && $client->type !== OAuthClient::TYPE_PUBLIC) {
            return false;
        }

        foreach (
            array_merge(
                OAuthClient::parsePgArray($client->redirect_uris),
                OAuthClient::parsePgArray($client->post_logout_redirect_uris)
            ) as $uri
        ) {
            if (!$this->redirectUriIsSafe($uri, (bool)$client->allow_loopback_redirects)) {
                return false;
            }
        }

        return true;
    }

    private function redirectUriIsSafe(string $uri, bool $allowLoopback): bool
    {
        if ($uri === '' || preg_match('/[\x00-\x20]/', $uri) || strpos($uri, '\\') !== false) {
            return false;
        }
        $parts = parse_url($uri);
        if (
            !is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && $allowLoopback && $this->isLoopbackHost((string)$parts['host']);
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
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
        if (
            ($code['code_challenge_method'] ?? '') !== 'S256'
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1
        ) {
            return false;
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

    private function authenticateRawAccessToken(string $token): bool
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $authentication = (new AuthService())->authenticate($request);
        return !empty($authentication['user']) || !empty($authentication['client']);
    }
}
