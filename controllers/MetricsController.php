<?php

namespace app\controllers;

use app\services\MetricsService;
use Yii;
use yii\web\Controller;
use yii\web\Response;

class MetricsController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex(): string
    {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->headers->set('Content-Type', 'text/plain; version=0.0.4');

        return (new MetricsService())->expose();
    }
}
