<?php

namespace app\controllers;

use Yii;

class HealthController extends ApiController
{
    public function actionLive(): array
    {
        return ['status' => 'ok'];
    }

    public function actionReady(): array
    {
        Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        return ['status' => 'ready'];
    }

    public function actionVersion(): array
    {
        return ['version' => Yii::$app->params['version']];
    }
}
