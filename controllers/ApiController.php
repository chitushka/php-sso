<?php

namespace app\controllers;

use app\services\RateLimitService;
use app\services\AuthService;
use app\services\RbacService;
use Yii;
use yii\web\Controller;
use yii\web\Response;

class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $maxBodyBytes = (int)(Yii::$app->params['maxBodyBytes'] ?? 1048576);

        if ((int)Yii::$app->request->headers->get('Content-Length', 0) > $maxBodyBytes) {
            Yii::$app->response->statusCode = 413;
            Yii::$app->response->data = ['error' => 'request body is too large'];
            return false;
        }

        $limit = (new RateLimitService())->check($this->route, Yii::$app->request);
        if (!$limit['allowed']) {
            Yii::$app->response->statusCode = 429;
            Yii::$app->response->headers->set('Retry-After', (string)$limit['retry_after']);
            Yii::$app->response->data = ['error' => 'rate limit exceeded'];
            return false;
        }

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

    protected function isUuid(string $value): bool
    {
        return (bool)preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
            $value
        );
    }

    protected function requirePermission(string $resource, string $action)
    {
        $user = (new AuthService())->currentUser(Yii::$app->request);
        if (!$user) {
            return $this->fail(401, 'invalid token');
        }

        if (!(new RbacService())->userHasPermission($user, $resource, $action)) {
            return $this->fail(403, 'permission denied');
        }

        return $user;
    }
}
