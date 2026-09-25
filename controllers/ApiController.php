<?php

namespace app\controllers;

use app\components\GoJson;
use app\services\RateLimitService;
use app\services\AuthService;
use app\services\RbacService;
use Yii;
use yii\web\BadRequestHttpException;
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

    /**
     * Декодирует тело так же строго, как Go encoding/json для типизированной структуры.
     * null допустим: для непointer-полей Go оставляет нулевое значение без ошибки.
     */
    protected function body(array $allowedFields = [], array $fieldTypes = []): array
    {
        $rawBody = GoJson::firstValue(Yii::$app->request->rawBody);
        $decoded = json_decode($rawBody);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BadRequestHttpException('invalid json body');
        }
        if ($decoded === null) {
            return [];
        }
        if (!is_object($decoded)) {
            throw new BadRequestHttpException('invalid json body');
        }

        $decodedArray = json_decode($rawBody, true);
        $canonicalFields = array_combine(array_map('strtolower', $allowedFields), $allowedFields);
        $body = [];
        foreach (get_object_vars($decoded) as $rawField => $value) {
            $field = $canonicalFields[strtolower($rawField)] ?? null;
            if ($field === null) {
                throw new BadRequestHttpException('invalid json body');
            }
            $body[$field] = $decodedArray[$rawField];
        }

        if ($fieldTypes !== []) {
            $canonicalTypes = array_combine(array_map('strtolower', array_keys($fieldTypes)), array_keys($fieldTypes));
            foreach (get_object_vars($decoded) as $rawField => $value) {
                $field = $canonicalTypes[strtolower($rawField)] ?? null;
                if ($field === null || $value === null) {
                    continue;
                }
                $type = $fieldTypes[$field];
                if (!$this->matchesJsonType($value, $type)) {
                    throw new BadRequestHttpException('invalid json body');
                }
                if ($type === 'uuid' && is_string($value)) {
                    $body[$field] = $this->canonicalUuid($value);
                }
                if (($type === 'strings' || $type === 'string_map') && isset($body[$field])) {
                    $body[$field] = array_map(static function ($item): string {
                        return $item === null ? '' : $item;
                    }, (array)$body[$field]);
                }
            }
        }

        return $body;
    }

    private function matchesJsonType($value, string $type): bool
    {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'int':
                return is_int($value);
            case 'bool':
                return is_bool($value);
            case 'uuid':
                return is_string($value) && $this->isUuid($value);
            case 'time':
                return is_string($value) && $this->isRfc3339($value);
            case 'strings':
                if (!is_array($value)) {
                    return false;
                }
                foreach ($value as $item) {
                    if ($item !== null && !is_string($item)) {
                        return false;
                    }
                }
                return true;
            case 'string_map':
                if (!is_object($value)) {
                    return false;
                }
                foreach (get_object_vars($value) as $item) {
                    if ($item !== null && !is_string($item)) {
                        return false;
                    }
                }
                return true;
            default:
                throw new \LogicException('Unknown JSON field type: ' . $type);
        }
    }

    protected function fail(int $status, string $message, array $extra = []): array
    {
        Yii::$app->response->statusCode = $status;
        return array_merge(['error' => $message], $extra);
    }

    protected function isUuid(string $value): bool
    {
        // google/uuid.Parse принимает стандартную, raw-hex, URN и Microsoft-формы.
        return (bool)preg_match('/^[0-9a-fA-F]{32}$/', $value)
            || (bool)preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/', $value)
            || (bool)preg_match('/^\{[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\}$/', $value)
            || (bool)preg_match('/^urn:uuid:[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/i', $value);
    }

    protected function canonicalUuid(string $value): string
    {
        $hex = strtolower(str_replace('-', '', preg_replace('/^(?:urn:uuid:)|[{}]/i', '', $value) ?? ''));
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function isRfc3339(string $value): bool
    {
        if (!preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/D',
            $value,
            $matches
        )) {
            return false;
        }

        $fraction = $matches[2] ?? '';
        $zone = $matches[3] === 'Z' ? '+00:00' : $matches[3];
        $normalized = $matches[1] . $zone;
        $format = '!Y-m-d\TH:i:sP';
        if ($fraction !== '') {
            $normalized = $matches[1] . '.' . substr(str_pad($fraction, 6, '0'), 0, 6) . $zone;
            $format = '!Y-m-d\TH:i:s.uP';
        }

        $parsed = \DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    protected function parseGoInt(string $value): int
    {
        if (!preg_match('/^[+-]?\d+$/D', $value)) {
            return 0;
        }

        $negative = isset($value[0]) && $value[0] === '-';
        $digits = ltrim($value, '+-0');
        if ($digits === '') {
            return 0;
        }

        $maximum = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            return 0;
        }

        $parsed = (int)$digits;
        return $negative ? -$parsed : $parsed;
    }

    protected function requirePermission(string $resource, string $action)
    {
        $authentication = (new AuthService())->authenticate(Yii::$app->request);
        if (!$authentication['user']) {
            return $this->fail(401, $authentication['error']);
        }
        $user = $authentication['user'];

        if (!(new RbacService())->userHasPermission($user, $resource, $action)) {
            return $this->fail(403, 'permission denied');
        }

        return $user;
    }

    protected function requireUser()
    {
        $authentication = (new AuthService())->authenticate(Yii::$app->request);
        if (!$authentication['user']) {
            return $this->fail(401, $authentication['error']);
        }

        return $authentication['user'];
    }
}
