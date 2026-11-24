<?php

namespace app\controllers;

use app\services\OidcService;
use app\services\OperationalStatusService;
use Yii;
use yii\base\Module;

final class OperationsController extends ApiController
{
    private OidcService $oidc;
    private OperationalStatusService $operations;

    public function __construct(
        string $id,
        Module $module,
        OidcService $oidc,
        OperationalStatusService $operations,
        array $config = []
    ) {
        $this->oidc = $oidc;
        $this->operations = $operations;
        parent::__construct($id, $module, $config);
    }

    public function actionStatus(): array
    {
        $auth = $this->requirePermission('operations', 'read');
        return is_array($auth) ? $auth : $this->operations->status();
    }

    public function actionSigningKeys(): array
    {
        $auth = $this->requirePermission('signing_keys', 'read');
        return is_array($auth) ? $auth : $this->oidc->listSigningKeys();
    }

    public function actionRotateSigningKey(): array
    {
        $auth = $this->requirePermission('signing_keys', 'rotate');
        if (is_array($auth)) {
            return $auth;
        }

        Yii::$app->response->statusCode = 201;
        return $this->oidc->rotateSigningKey();
    }
}
