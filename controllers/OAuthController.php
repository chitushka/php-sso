<?php

namespace app\controllers;

use app\services\AuthService;
use app\services\OAuthService;
use Yii;
use yii\web\BadRequestHttpException;

class OAuthController extends ApiController
{
    private const CREATE_CLIENT_FIELDS = [
        'client_id',
        'name',
        'type',
        'redirect_uris',
        'allowed_scopes',
        'post_logout_redirect_uris',
        'backchannel_logout_uri',
        'skip_consent',
        'allow_loopback_redirects',
        'enabled',
    ];

    private const UPDATE_CLIENT_FIELDS = [
        'name',
        'redirect_uris',
        'allowed_scopes',
        'post_logout_redirect_uris',
        'backchannel_logout_uri',
        'skip_consent',
        'allow_loopback_redirects',
        'enabled',
    ];

    private const CREATE_CLIENT_TYPES = [
        'client_id' => 'string',
        'name' => 'string',
        'type' => 'string',
        'redirect_uris' => 'strings',
        'allowed_scopes' => 'strings',
        'post_logout_redirect_uris' => 'strings',
        'backchannel_logout_uri' => 'string',
        'skip_consent' => 'bool',
        'allow_loopback_redirects' => 'bool',
        'enabled' => 'bool',
    ];

    private const UPDATE_CLIENT_TYPES = [
        'name' => 'string',
        'redirect_uris' => 'strings',
        'allowed_scopes' => 'strings',
        'post_logout_redirect_uris' => 'strings',
        'backchannel_logout_uri' => 'string',
        'skip_consent' => 'bool',
        'allow_loopback_redirects' => 'bool',
        'enabled' => 'bool',
    ];

    public function actionListClients(): array
    {
        $auth = $this->requirePermission('oauth_clients', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        return (new OAuthService())->listClients();
    }

    public function actionCreateClient(): array
    {
        $auth = $this->requirePermission('oauth_clients', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $result = (new OAuthService())->createClient(
            $this->body(self::CREATE_CLIENT_FIELDS, self::CREATE_CLIENT_TYPES)
        );
        Yii::$app->response->statusCode = $result['status'];
        if ($result['status'] !== 201) {
            return $result['data'];
        }

        $client = $result['data'];
        $secret = $client['client_secret'] ?? null;
        unset($client['client_secret']);

        $data = ['client' => $client];
        if ($secret !== null) {
            $data['client_secret'] = $secret;
        }
        return $data;
    }

    public function actionGetClient(string $id): array
    {
        $auth = $this->requirePermission('oauth_clients', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }
        $id = $this->canonicalUuid($id);

        $client = (new OAuthService())->getClient($id);
        if (!$client) {
            return $this->fail(404, 'client not found');
        }

        return $client;
    }

    public function actionUpdateClient(string $id): array
    {
        $auth = $this->requirePermission('oauth_clients', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }
        $id = $this->canonicalUuid($id);

        $result = (new OAuthService())->updateClient(
            $id,
            $this->body(self::UPDATE_CLIENT_FIELDS, self::UPDATE_CLIENT_TYPES)
        );
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDeleteClient(string $id): array
    {
        $auth = $this->requirePermission('oauth_clients', 'delete');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }
        $id = $this->canonicalUuid($id);

        return (new OAuthService())->deleteClient($id)
            ? ['status' => 'deleted']
            : $this->fail(404, 'client not found');
    }

    public function actionRotateSecret(string $id): array
    {
        $auth = $this->requirePermission('oauth_clients', 'update');
        if (is_array($auth)) {
            return $auth;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }
        $id = $this->canonicalUuid($id);

        $result = (new OAuthService())->rotateSecret($id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionAuthorize()
    {
        $result = (new OAuthService())->authorize(Yii::$app->request);

        if (!$result['ok']) {
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        return $this->redirect($result['redirect']);
    }

    public function actionToken(): array
    {
        $result = (new OAuthService())->token(Yii::$app->request);
        if (!$result['ok']) {
            if ($result['error'] === 'invalid_client') {
                Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="sso"');
            }
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        return $result['data'];
    }

    public function actionRevoke()
    {
        $result = (new OAuthService())->revoke(Yii::$app->request);
        if (!$result['ok']) {
            if (($result['error'] ?? '') === 'invalid_client' || !isset($result['error'])) {
                Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="sso"');
            }
            return $this->oauthError(
                (int)($result['status'] ?? 401),
                (string)($result['error'] ?? 'invalid_client'),
                (string)($result['description'] ?? 'client authentication failed')
            );
        }

        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        return '';
    }

    public function actionIntrospect(): array
    {
        $result = (new OAuthService())->introspect(Yii::$app->request);
        if (!$result['ok']) {
            Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="sso"');
            return $this->oauthError(401, 'invalid_client', 'client authentication failed');
        }

        return $result['data'];
    }

    public function actionConsent(): array
    {
        $result = (new OAuthService())->consentInfo(Yii::$app->request);
        if (!$result['ok']) {
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        return $result['data'];
    }

    public function actionListUserConsents(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        return (new OAuthService())->listUserConsents($user->id);
    }

    public function actionRevokeUserConsent(string $clientId): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        if (!(new OAuthService())->revokeUserConsent($user->id, $clientId)) {
            return $this->fail(404, 'consent not found');
        }

        return ['status' => 'revoked'];
    }

    public function actionGrantConsent(): array
    {
        try {
            $body = $this->body(
                ['client_id', 'scope'],
                ['client_id' => 'string', 'scope' => 'string']
            );
        } catch (BadRequestHttpException $exception) {
            return $this->oauthError(400, 'invalid_request', 'invalid json body');
        }

        $result = (new OAuthService())->grantConsent(Yii::$app->request, $body);
        if (!$result['ok']) {
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        return ['status' => 'granted'];
    }

    public function actionLogout()
    {
        $result = (new OAuthService())->logout(Yii::$app->request);
        if (!$result['ok']) {
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        Yii::$app->response->cookies->remove('sso_session');
        Yii::$app->response->cookies->remove(\app\services\BrowserSecurityService::CSRF_COOKIE);

        if (isset($result['redirect'])) {
            return $this->redirect($result['redirect']);
        }

        return $result['data'];
    }

    private function oauthError(int $status, string $error, string $description): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $error, 'error_description' => $description];
    }
}
