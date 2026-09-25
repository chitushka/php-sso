<?php

namespace app\controllers;

use app\services\BrokerService;
use Yii;
use yii\web\Response;

class BrokerController extends ApiController
{
    private const PROVIDER_FIELDS = [
        'id',
        'code',
        'name',
        'type',
        'client_id',
        'client_secret',
        'authorize_url',
        'token_url',
        'userinfo_url',
        'scopes',
        'enabled',
        'created_at',
        'updated_at',
    ];

    public function actionPublicProviders(): array
    {
        return (new BrokerService())->publicProviders();
    }

    public function actionLogin(string $providerCode): Response
    {
        $continue = (string)Yii::$app->request->get('continue', '/');
        $redirect = (new BrokerService())->start($providerCode, $continue);
        if (!$redirect) {
            Yii::$app->response->statusCode = 404;
            Yii::$app->response->format = Response::FORMAT_JSON;
            Yii::$app->response->data = ['error' => 'unknown identity provider'];
            return Yii::$app->response;
        }

        return Yii::$app->response->redirect($redirect, 302);
    }

    public function actionCallback(string $providerCode): Response
    {
        if ((string)Yii::$app->request->get('error', '') !== '') {
            return Yii::$app->response->redirect('/login?broker_error=' . rawurlencode((string)Yii::$app->request->get('error')), 302);
        }

        $result = (new BrokerService())->callback(
            $providerCode,
            (string)Yii::$app->request->get('state', ''),
            (string)Yii::$app->request->get('code', '')
        );
        if (!$result['ok']) {
            return Yii::$app->response->redirect('/login?broker_error=failed', 302);
        }

        Yii::$app->response->cookies->add($result['cookie']);

        return Yii::$app->response->redirect($result['continue'] . '#broker_token=' . rawurlencode($result['access_token']), 302);
    }

    public function actionListProviders(): array
    {
        $user = $this->requirePermission('identity_providers', 'read');
        if (is_array($user)) {
            return $user;
        }

        return (new BrokerService())->listProviders();
    }

    public function actionCreateProvider(): array
    {
        $user = $this->requirePermission('identity_providers', 'create');
        if (is_array($user)) {
            return $user;
        }

        $result = (new BrokerService())->createProvider($this->body(self::PROVIDER_FIELDS));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionUpdateProvider(string $id): array
    {
        $user = $this->requirePermission('identity_providers', 'update');
        if (is_array($user)) {
            return $user;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $result = (new BrokerService())->updateProvider($id, $this->body(self::PROVIDER_FIELDS));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }

    public function actionDeleteProvider(string $id): array
    {
        $user = $this->requirePermission('identity_providers', 'delete');
        if (is_array($user)) {
            return $user;
        }

        if (!$this->isUuid($id)) {
            return $this->fail(400, 'invalid id');
        }

        $result = (new BrokerService())->deleteProvider($id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
