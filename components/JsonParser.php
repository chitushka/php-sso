<?php

namespace app\components;

use yii\web\BadRequestHttpException;
use yii\web\JsonParser as YiiJsonParser;

class JsonParser extends YiiJsonParser
{
    public function parse($rawBody, $contentType)
    {
        try {
            $params = parent::parse($rawBody, $contentType);
        } catch (BadRequestHttpException $exception) {
            throw new BadRequestHttpException('invalid json body', 0, $exception);
        }

        $trimmedBody = ltrim($rawBody);
        if (!is_array($params) || ($trimmedBody !== 'null' && substr($trimmedBody, 0, 1) !== '{')) {
            throw new BadRequestHttpException('invalid json body');
        }

        return $params;
    }
}
