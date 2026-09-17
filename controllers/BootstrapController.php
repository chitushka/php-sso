<?php

namespace app\controllers;

use app\services\BootstrapService;
use Yii;

class BootstrapController extends ApiController
{
    public function actionStatus(): array
    {
        return (new BootstrapService())->status();
    }

    public function actionCreate(): array
    {
        $result = (new BootstrapService())->createAdmin($this->body(['username', 'email', 'password']));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
