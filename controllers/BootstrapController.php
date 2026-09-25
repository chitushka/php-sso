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
        $fields = ['username', 'email', 'password'];
        $result = (new BootstrapService())->createAdmin($this->body($fields, array_fill_keys($fields, 'string')));
        Yii::$app->response->statusCode = $result['status'];
        return $result['data'];
    }
}
