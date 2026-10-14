<?php

namespace app\controllers;

use app\components\ApiResponse;
use app\services\RateLimitService;
use app\services\AuditService;
use app\services\AuthorizationService;
use app\services\AuthService;
use app\services\BrowserSecurityService;
use app\services\RbacService;
use app\services\results\HttpResultMapper;
use app\services\results\ServiceResult;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        AuditService::setCurrentActor(null);
        Yii::$app->response->format = Response::FORMAT_JSON;
        $maxBodyBytes = (int)(Yii::$app->params['maxBodyBytes'] ?? 1048576);

        if ((int)Yii::$app->request->headers->get('Content-Length', 0) > $maxBodyBytes) {
            Yii::$app->response->statusCode = 413;
            Yii::$app->response->data = ApiResponse::error('request body is too large', 'request_body_too_large');
            return false;
        }
        if (strlen(Yii::$app->request->rawBody) > $maxBodyBytes) {
            Yii::$app->response->statusCode = 413;
            Yii::$app->response->data = ApiResponse::error('request body is too large', 'request_body_too_large');
            return false;
        }

        $browserSecurity = new BrowserSecurityService();
        if (
            $browserSecurity->requiresCsrf($this->route, Yii::$app->request)
            && !$browserSecurity->validateCsrf(Yii::$app->request)
        ) {
            Yii::$app->response->statusCode = 403;
            Yii::$app->response->data = ApiResponse::error('csrf validation failed', 'csrf_validation_failed');
            return false;
        }

        $limit = (new RateLimitService())->check($this->route, Yii::$app->request);
        if (!$limit['allowed']) {
            (new \app\services\MetricsService())->event('rate_limit', 'blocked');
            Yii::$app->response->statusCode = 429;
            Yii::$app->response->headers->set('Retry-After', (string)$limit['retry_after']);
            Yii::$app->response->data = ApiResponse::error('rate limit exceeded', 'rate_limit_exceeded');
            return false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Декодирует тело согласно строгому JSON-контракту API.
     * null допустим и означает отсутствие значений тела запроса.
     */
    protected function body(array $allowedFields = [], array $fieldTypes = []): array
    {
        $rawBody = Yii::$app->request->rawBody;
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
        $body = [];
        foreach (get_object_vars($decoded) as $rawField => $value) {
            if (!in_array($rawField, $allowedFields, true)) {
                throw new BadRequestHttpException('invalid json body');
            }
            $body[$rawField] = $decodedArray[$rawField];
        }

        if ($fieldTypes !== []) {
            foreach (get_object_vars($decoded) as $rawField => $value) {
                if (!array_key_exists($rawField, $fieldTypes) || $value === null) {
                    continue;
                }
                $type = $fieldTypes[$rawField];
                if (!$this->matchesJsonType($value, $type)) {
                    throw new BadRequestHttpException('invalid json body');
                }
                if ($type === 'uuid' && is_string($value)) {
                    $body[$rawField] = $this->canonicalUuid($value);
                }
                if (($type === 'strings' || $type === 'string_map') && isset($body[$rawField])) {
                    $body[$rawField] = array_map(static function ($item): string {
                        return $item === null ? '' : $item;
                    }, (array)$body[$rawField]);
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
        $code = isset($extra['code']) && is_string($extra['code']) ? $extra['code'] : null;
        unset($extra['code']);

        return array_merge(ApiResponse::error($message, $code), $extra);
    }

    protected function respond(ServiceResult $result): array
    {
        return (new HttpResultMapper())->map($result, Yii::$app->response);
    }

    protected function isUuid(string $value): bool
    {
        // Контракт принимает стандартную, raw-hex, URN и фигурную формы UUID.
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
        if (
            !preg_match(
                '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/D',
                $value,
                $matches
            )
        ) {
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

    protected function parseQueryInteger(string $value): int
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
        if (
            strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            return 0;
        }

        $parsed = (int)$digits;
        return $negative ? -$parsed : $parsed;
    }

    protected function adminListOptions(array $filterNames = []): array
    {
        $limit = $this->parseQueryInteger((string)Yii::$app->request->get('limit', ''));
        if ($limit <= 0 || $limit > 100) {
            $limit = 50;
        }
        $offset = max($this->parseQueryInteger((string)Yii::$app->request->get('offset', '')), 0);
        $options = ['limit' => $limit, 'offset' => $offset];

        foreach (array_merge(['q'], $filterNames) as $name) {
            $value = trim((string)Yii::$app->request->get($name, ''));
            if ($value !== '') {
                $options[$name] = mb_substr($value, 0, 200);
            }
        }

        return $options;
    }

    protected function page(array $items, array $options): array
    {
        return ApiResponse::page($items, (int)$options['limit'], (int)$options['offset']);
    }

    protected function requirePermission(string $resource, string $action)
    {
        $authService = new AuthService();
        $authentication = $authService->authenticate(Yii::$app->request);
        if (!$authentication['user'] && empty($authentication['client'])) {
            return $this->fail(401, $authentication['error']);
        }
        $principal = $authentication['user'] ?: $authentication['client'];
        if (
            $authentication['client']
            && !(new AuthorizationService())->machineClientAllows($resource, $action)
        ) {
            return $this->fail(403, 'user principal required');
        }
        AuditService::setCurrentPrincipal(
            $authentication['user'] ? AuditService::ACTOR_USER : AuditService::ACTOR_CLIENT,
            (string)$principal->id
        );

        if (!$authService->tokenAllowsPermission($authentication['claims'] ?? [], $resource, $action)) {
            return $this->fail(403, 'insufficient scope');
        }

        if ($authentication['user'] && !(new RbacService())->userHasPermission($authentication['user'], $resource, $action)) {
            return $this->fail(403, 'permission denied');
        }

        return $principal;
    }

    public function actionNotFound(): array
    {
        return $this->fail(404, 'not found');
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
