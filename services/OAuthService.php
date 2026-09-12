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
        $client->client_id = trim((string)($body['client_id'] ?? '')) ?: 'client_' . strtolower(bin2hex(random_bytes(6)));
        $client->name = trim((string)($body['name'] ?? $client->client_id));
        $client->type = (string)($body['type'] ?? OAuthClient::TYPE_CONFIDENTIAL);
        $client->redirect_uris = OAuthClient::toPgArray((array)($body['redirect_uris'] ?? []));
        $client->allowed_scopes = OAuthClient::toPgArray((array)($body['allowed_scopes'] ?? ['openid', 'profile', 'email']));
        $client->post_logout_redirect_uris = OAuthClient::toPgArray((array)($body['post_logout_redirect_uris'] ?? []));
        $client->backchannel_logout_uri = (string)($body['backchannel_logout_uri'] ?? '');
        $client->skip_consent = (bool)($body['skip_consent'] ?? false);
        $client->enabled = true;

        $plainSecret = null;
        if ($client->type === OAuthClient::TYPE_CONFIDENTIAL) {
            $plainSecret = (new AuthService())->randomToken(32);
            $client->client_secret_hash = password_hash($plainSecret, PASSWORD_DEFAULT);
        }

        if (!$client->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid client payload', 'details' => $client->errors]];
        }

        $data = $client->publicData();
        if ($plainSecret !== null) {
            $data['client_secret'] = $plainSecret;
        }

        AuditService::record(null, 'oauth_client_created', 'oauth_client', $client->id);
        return ['status' => 201, 'data' => $data];
    }

    public function updateClient(string $id, array $body): array
    {
        $client = OAuthClient::findOne(['id' => $id]);
        if (!$client) {
            return ['status' => 404, 'data' => ['error' => 'client not found']];
        }

        foreach (['name', 'type', 'backchannel_logout_uri', 'enabled', 'skip_consent'] as $field) {
            if (array_key_exists($field, $body)) {
                $client->{$field} = $body[$field];
            }
        }

        foreach (['redirect_uris', 'allowed_scopes', 'post_logout_redirect_uris'] as $field) {
            if (array_key_exists($field, $body)) {
                $client->{$field} = OAuthClient::toPgArray((array)$body[$field]);
            }
        }

        if (!$client->save()) {
            return ['status' => 400, 'data' => ['error' => 'invalid client payload', 'details' => $client->errors]];
        }

        AuditService::record(null, 'oauth_client_updated', 'oauth_client', $client->id);
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
        $client->client_secret_hash = password_hash($secret, PASSWORD_DEFAULT);
        $client->save(false, ['client_secret_hash', 'updated_at']);

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
            $query['state'] = $request->get('state');
        }

        return ['ok' => true, 'redirect' => $redirectUri . (strpos($redirectUri, '?') === false ? '?' : '&') . http_build_query($query)];
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

        RefreshToken::updateAll(['revoked_at' => new Expression('now()')], [
            'token_hash' => hash('sha256', (string)$request->post('token')),
            'client_id' => $client->client_id,
            'revoked_at' => null,
        ]);

        return ['ok' => true];
    }

    public function introspect(Request $request): array
    {
        if (!$this->clientFromRequest($request)) {
            return ['ok' => false];
        }

        $claims = (new JwtService())->verify((string)$request->post('token'));
        return [
            'ok' => true,
            'data' => $claims ? array_merge(['active' => true], $claims) : ['active' => false],
        ];
    }

    public function consentInfo(Request $request): array
    {
        $client = OAuthClient::findOne(['client_id' => $request->get('client_id')]);
        return [
            'client_id' => $request->get('client_id'),
            'client_name' => $client ? $client->name : '',
            'scope' => $request->get('scope', ''),
            'scopes' => preg_split('/\s+/', trim((string)$request->get('scope', ''))) ?: [],
        ];
    }

    public function grantConsent(Request $request, array $body): array
    {
        $user = (new AuthService())->userBySession($request->cookies->getValue('sso_session', ''));
        $client = OAuthClient::findOne(['client_id' => $body['client_id'] ?? '']);

        if (!$user) {
            return $this->oauthFail(401, 'login_required', 'no active session');
        }

        if (!$client || !$this->scopeAllowed($client, (string)($body['scope'] ?? ''))) {
            return $this->oauthFail(400, 'invalid_scope', 'requested scope is not allowed for this client');
        }

        $scopes = implode(' ', array_unique(array_filter(preg_split('/\s+/', trim((string)$body['scope'])))));
        Yii::$app->db->createCommand(
            'INSERT INTO user_consents(user_id, client_id, scopes) VALUES (:user_id, :client_id, :scopes)
             ON CONFLICT (user_id, client_id) DO UPDATE SET scopes = :scopes, updated_at = now()',
            [':user_id' => $user->id, ':client_id' => $client->client_id, ':scopes' => $scopes]
        )->execute();

        return ['ok' => true];
    }

    public function logout(Request $request): array
    {
        $sessionToken = $request->cookies->getValue('sso_session', '');
        $user = (new AuthService())->userBySession($sessionToken);
        $hint = (string)$request->get('id_token_hint', $request->post('id_token_hint', ''));
        $redirect = (string)$request->get('post_logout_redirect_uri', $request->post('post_logout_redirect_uri', ''));
        $state = (string)$request->get('state', $request->post('state', ''));
        $client = null;

        if ($hint !== '') {
            $claims = (new OidcService())->verifyIdTokenHint($hint);
            if ($claims) {
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
        }

        if ($redirect === '') {
            return ['ok' => true, 'data' => ['status' => 'logged_out']];
        }

        if (!$client || !in_array($redirect, OAuthClient::parsePgArray($client->post_logout_redirect_uris), true)) {
            return $this->oauthFail(400, 'invalid_request', 'invalid post_logout_redirect_uri');
        }

        if ($state !== '') {
            $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . http_build_query(['state' => $state]);
        }

        return ['ok' => true, 'redirect' => $redirect];
    }

    private function authorizationCodeToken(Request $request, OAuthClient $client): array
    {
        $code = OAuthAuthorizationCode::find()
            ->where(['code_hash' => hash('sha256', (string)$request->post('code')), 'used_at' => null])
            ->andWhere(['>', 'expires_at', new Expression('now()')])
            ->one();

        if (!$code || $code->client_id !== $client->client_id || $code->redirect_uri !== $request->post('redirect_uri')) {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        if (!$this->verifyPkce($code, (string)$request->post('code_verifier', ''))) {
            return $this->oauthFail(400, 'invalid_grant', 'grant is invalid, expired or revoked');
        }

        $code->used_at = new Expression('now()');
        $code->save(false, ['used_at']);

        return $this->tokenResponse($code->user_id, $client, $code->scope, $code->nonce, null);
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

        return $this->tokenResponse($old->user_id, $client, $old->scope, '', (string)$old->family_id);
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

        $token = (new JwtService())->issue($client->id, [
            'client_id' => $client->client_id,
            'scope' => $scope,
            'purpose' => 'client_credentials',
        ]);

        return ['ok' => true, 'data' => [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => Yii::$app->params['accessTokenTtl'],
        ]];
    }

    private function tokenResponse(string $userId, OAuthClient $client, string $scope, string $nonce, ?string $familyId): array
    {
        $accessToken = (new JwtService())->issue($userId, [
            'client_id' => $client->client_id,
            'scope' => $scope,
        ]);
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
            $data['id_token'] = (new OidcService())->issueIdToken($userId, $client->client_id, $nonce);
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

        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            $clientId = rawurldecode((string)$_SERVER['PHP_AUTH_USER']);
            $clientSecret = rawurldecode((string)($_SERVER['PHP_AUTH_PW'] ?? ''));
        }

        $client = OAuthClient::findOne(['client_id' => $clientId, 'enabled' => true]);
        if (!$client) {
            return null;
        }

        if ($client->type === OAuthClient::TYPE_CONFIDENTIAL && !password_verify($clientSecret, (string)$client->client_secret_hash)) {
            return null;
        }

        return $client;
    }

    private function scopeAllowed(OAuthClient $client, string $scope): bool
    {
        $requested = array_filter(preg_split('/\s+/', trim($scope)));
        $allowed = OAuthClient::parsePgArray($client->allowed_scopes);

        return empty(array_diff($requested, $allowed));
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

    private function verifyPkce(OAuthAuthorizationCode $code, string $verifier): bool
    {
        if (!$code->code_challenge) {
            return true;
        }

        if ($code->code_challenge_method === 'S256') {
            return hash_equals($code->code_challenge, JwtService::base64url(hash('sha256', $verifier, true)));
        }

        return hash_equals($code->code_challenge, $verifier);
    }

    private function oauthFail(int $status, string $error, string $description): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'description' => $description];
    }
}
