<?php

namespace app\services\results;

use yii\web\Response;

final class HttpResultMapper
{
    public function map(ServiceResult $result, Response $response): array
    {
        $response->statusCode = $result->status;
        return $result->data;
    }
}
