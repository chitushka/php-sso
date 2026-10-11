<?php

namespace app\controllers;

use app\services\AuthService;
use app\services\OAuthService;
use app\services\OAuthClientService;
use Yii;
use yii\base\Module;
use yii\web\BadRequestHttpException;

class OAuthController extends ApiController
{
    private OAuthService $oauth;
    private OAuthClientService $clients;

    public function __construct(
        string $id,
        Module $module,
        OAuthService $oauth,
        OAuthClientService $clients,
        array $config = []
    ) {
        $this->oauth = $oauth;
        $this->clients = $clients;
        parent::__construct($id, $module, $config);
    }

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

        $options = $this->adminListOptions(['type']);
        return $this->page($this->clients->list($options), $options);
    }

    public function actionCreateClient(): array
    {
        $auth = $this->requirePermission('oauth_clients', 'create');
        if (is_array($auth)) {
            return $auth;
        }

        $result = $this->clients->create(
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

        $client = $this->clients->get($id);
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

        $result = $this->clients->update(
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

        return $this->clients->delete($id)
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

        $result = $this->clients->rotateSecret($id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionAuthorize()
    {
        $result = $this->oauth->authorize(Yii::$app->request);

        if (!$result->isSuccess()) {
            return $this->oauthError($result->status, $result->error->code, $result->error->message);
        }

        return $this->redirect($result->metadata['redirect']);
    }

    public function actionToken(): array
    {
        $result = $this->oauth->token(Yii::$app->request);
        if (!$result->isSuccess()) {
            if ($result->error->code === 'invalid_client') {
                Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="sso"');
            }
            return $this->oauthError($result->status, $result->error->code, $result->error->message);
        }

        return $result->data;
    }

    public function actionRevoke()
    {
        $result = $this->oauth->revoke(Yii::$app->request);
        if (!$result->isSuccess()) {
            if ($result->error->code === 'invalid_client') {
                Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="sso"');
            }
            return $this->oauthError(
                $result->status,
                $result->error->code,
                $result->error->message
            );
        }

        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        return '';
    }

    public function actionIntrospect(): array
    {
        $result = $this->oauth->introspect(Yii::$app->request);
        if (!$result->isSuccess()) {
            Yii::$app->response->headers->set('WWW-Authenticate', 'Basic realm="sso"');
            return $this->oauthError(401, 'invalid_client', 'client authentication failed');
        }

        return $result->data;
    }

    public function actionConsent(): array
    {
        $result = $this->oauth->consentInfo(Yii::$app->request);
        if (!$result->isSuccess()) {
            return $this->oauthError($result->status, $result->error->code, $result->error->message);
        }

        return $result->data;
    }

    public function actionListUserConsents(): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        return $this->oauth->listUserConsents($user->id);
    }

    public function actionRevokeUserConsent(string $clientId): array
    {
        $user = $this->requireUser();
        if (is_array($user)) {
            return $user;
        }

        if (!$this->oauth->revokeUserConsent($user->id, $clientId)) {
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

        $result = $this->oauth->grantConsent(Yii::$app->request, $body);
        if (!$result->isSuccess()) {
            return $this->oauthError($result->status, $result->error->code, $result->error->message);
        }

        return $result->data;
    }

    public function actionLogout()
    {
        $result = $this->oauth->logout(Yii::$app->request);
        if (!$result->isSuccess()) {
            return $this->oauthError($result->status, $result->error->code, $result->error->message);
        }

        Yii::$app->response->cookies->remove('sso_session');
        Yii::$app->response->cookies->remove(\app\services\BrowserSecurityService::CSRF_COOKIE);

        if (isset($result->metadata['redirect'])) {
            return $this->redirect($result->metadata['redirect']);
        }

        return $result->data;
    }

    private function oauthError(int $status, string $error, string $description): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => $error, 'error_description' => $description];
    }
}
