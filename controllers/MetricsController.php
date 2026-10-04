<?php

namespace app\controllers;

use app\services\MetricsService;
use app\services\MetricsAccessService;
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
        if (!(new MetricsAccessService())->allowed(Yii::$app->request)) {
            Yii::$app->response->statusCode = 403;
            return "forbidden\n";
        }

        return (new MetricsService())->expose();
    }
}
