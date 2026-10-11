<?php

namespace app\controllers;

use app\services\BrokerService;
use app\services\MetricsService;
use app\services\IdentityProviderAdminService;
use Yii;
use yii\base\Module;
use yii\web\Response;

class BrokerController extends ApiController
{
    private BrokerService $broker;
    private MetricsService $metrics;
    private IdentityProviderAdminService $providers;

    public function __construct(
        string $id,
        Module $module,
        BrokerService $broker,
        MetricsService $metrics,
        IdentityProviderAdminService $providers,
        array $config = []
    ) {
        $this->broker = $broker;
        $this->metrics = $metrics;
        $this->providers = $providers;
        parent::__construct($id, $module, $config);
    }

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

    private const PROVIDER_TYPES = [
        'id' => 'uuid',
        'code' => 'string',
        'name' => 'string',
        'type' => 'string',
        'client_id' => 'string',
        'client_secret' => 'string',
        'authorize_url' => 'string',
        'token_url' => 'string',
        'userinfo_url' => 'string',
        'scopes' => 'string',
        'enabled' => 'bool',
        'created_at' => 'time',
        'updated_at' => 'time',
    ];

    public function actionPublicProviders(): array
    {
        return $this->providers->publicProviders();
    }

    public function actionLogin(string $providerCode): Response
    {
        $continue = (string)Yii::$app->request->get('continue', '/');
        $redirect = $this->broker->start($providerCode, $continue);
        if (!$redirect) {
            $this->metrics->event('provider', 'failure');
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
            $this->metrics->event('provider', 'failure');
            return Yii::$app->response->redirect('/login?broker_error=' . rawurlencode((string)Yii::$app->request->get('error')), 302);
        }

        $result = $this->broker->callback(
            $providerCode,
            (string)Yii::$app->request->get('state', ''),
            (string)Yii::$app->request->get('code', '')
        );
        if (!$result->isSuccess()) {
            $this->metrics->event('provider', 'failure');
            return Yii::$app->response->redirect('/login?broker_error=failed', 302);
        }

        $this->metrics->event('provider', 'success');

        Yii::$app->response->cookies->add($result->metadata['cookie']);
        Yii::$app->response->cookies->add($result->metadata['csrf_cookie']);

        return Yii::$app->response->redirect($result->data['continue'], 302);
    }

    public function actionListProviders(): array
    {
        $user = $this->requirePermission('identity_providers', 'read');
        if (is_array($user)) {
            return $user;
        }

        $options = $this->adminListOptions(['type']);
        return $this->page($this->providers->list($options), $options);
    }

    public function actionCreateProvider(): array
    {
        $user = $this->requirePermission('identity_providers', 'create');
        if (is_array($user)) {
            return $user;
        }

        $result = $this->providers->create(
            $this->body(self::PROVIDER_FIELDS, self::PROVIDER_TYPES)
        );
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
        $id = $this->canonicalUuid($id);

        $result = $this->providers->update(
            $id,
            $this->body(self::PROVIDER_FIELDS, self::PROVIDER_TYPES)
        );
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
        $id = $this->canonicalUuid($id);

        $result = $this->providers->delete($id);
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
