<?php

namespace app\components;

use Yii;
use yii\web\Request;

final class CorrelationId
{
    public static function begin(Request $request): string
    {
        $candidate = trim((string)$request->headers->get('X-Request-ID', ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/D', $candidate) !== 1) {
            $candidate = bin2hex(random_bytes(16));
        }
        Yii::$app->params['_requestId'] = $candidate;

        return $candidate;
    }
}
