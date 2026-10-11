<?php

namespace app\services\results;

use app\components\ApiResponse;
use yii\web\Response;

final class HttpResultMapper
{
    public function map(ServiceResult $result, Response $response): array
    {
        $response->statusCode = $result->status;
        if ($result->isSuccess()) {
            return $result->data;
        }

        return ApiResponse::normalizeError(array_merge(
            $result->data,
            ['code' => $result->error->code]
        ));
    }
}
