<?php

namespace app\controllers;

use app\services\AuthService;
use app\services\OAuthService;
use Yii;

class OAuthController extends ApiController
{
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

        $result = (new OAuthService())->createClient($this->body());
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
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

        $result = (new OAuthService())->updateClient($id, $this->body());
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
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        return $result['data'];
    }

    public function actionRevoke(): array
    {
        $result = (new OAuthService())->revoke(Yii::$app->request);
        if (!$result['ok']) {
            return $this->oauthError(401, 'invalid_client', 'client authentication failed');
        }

        return [];
    }

    public function actionIntrospect(): array
    {
        $result = (new OAuthService())->introspect(Yii::$app->request);
        if (!$result['ok']) {
            return $this->oauthError(401, 'invalid_client', 'client authentication failed');
        }

        return $result['data'];
    }

    public function actionConsent(): array
    {
        return (new OAuthService())->consentInfo(Yii::$app->request);
    }

    public function actionGrantConsent(): array
    {
        $result = (new OAuthService())->grantConsent(Yii::$app->request, $this->body());
        if (!$result['ok']) {
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

        return ['status' => 'granted'];
    }

    public function actionLogout()
    {
        $result = (new OAuthService())->logout(Yii::$app->request);
        Yii::$app->response->cookies->remove('sso_session');

        if (!$result['ok']) {
            return $this->oauthError($result['status'], $result['error'], $result['description']);
        }

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
