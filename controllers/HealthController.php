<?php

namespace app\controllers;

use Throwable;
use Yii;

class HealthController extends ApiController
{
    public function actionLive(): array
    {
        return ['status' => 'ok'];
    }

    public function actionReady(): array
    {
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        } catch (Throwable $exception) {
            return $this->fail(503, 'database unavailable');
        }

        return ['status' => 'ready'];
    }

    public function actionVersion(): array
    {
        return ['version' => Yii::$app->params['version']];
    }
}
