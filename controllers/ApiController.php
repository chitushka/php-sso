<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    protected function body(): array
    {
        return Yii::$app->request->bodyParams ?: [];
    }

    protected function fail(int $status, string $message, array $extra = []): array
    {
        Yii::$app->response->statusCode = $status;
        return array_merge(['error' => $message], $extra);
    }
}
