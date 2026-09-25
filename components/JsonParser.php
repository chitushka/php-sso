<?php

namespace app\components;

use yii\web\BadRequestHttpException;
use yii\web\JsonParser as YiiJsonParser;

class JsonParser extends YiiJsonParser
{
    public function parse($rawBody, $contentType)
    {
        $firstValue = GoJson::firstValue($rawBody);
        try {
            $params = parent::parse($firstValue, $contentType);
        } catch (BadRequestHttpException $exception) {
            throw new BadRequestHttpException('invalid json body', 0, $exception);
        }

        $trimmedBody = ltrim($firstValue);
        if (!is_array($params) || ($trimmedBody !== 'null' && substr($trimmedBody, 0, 1) !== '{')) {
            throw new BadRequestHttpException('invalid json body');
        }

        return $params;
    }
}
