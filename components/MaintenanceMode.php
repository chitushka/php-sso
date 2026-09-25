<?php

namespace app\components;

use yii\web\Request;
use yii\web\Response;

class MaintenanceMode
{
    /** @var bool */
    private static $blockedRequest = false;

    public static function isActive(): bool
    {
        $flagPath = trim((string)(getenv('SSO_MAINTENANCE_FILE') ?: ''));
        return $flagPath !== '' && is_file($flagPath);
    }

    public static function apply(Request $request, Response $response): bool
    {
        if (!self::isActive()) {
            self::$blockedRequest = false;
            return false;
        }

        self::$blockedRequest = true;
        $response->format = Response::FORMAT_JSON;
        if ($request->pathInfo === 'health/live' && $request->isGet) {
            $response->statusCode = 200;
            $response->data = ['status' => 'ok'];
        } else {
            $response->statusCode = 503;
            $response->data = ['error' => 'service unavailable'];
        }

        return true;
    }

    public static function blockedCurrentRequest(): bool
    {
        return self::$blockedRequest;
    }
}
