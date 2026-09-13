<?php

namespace app\services;

use Yii;
use yii\web\Request;
use yii\web\Response;

class MetricsService
{
    private const KEY_REQUESTS = 'metrics:http_requests';
    private const KEY_INFLIGHT = 'metrics:http_inflight';
    private const KEY_DURATION_SUM = 'metrics:http_duration_sum';
    private const KEY_TOTAL = 'metrics:http_total';

    public function begin(): void
    {
        Yii::$app->params['_metricsStartedAt'] = microtime(true);
        $this->increment(self::KEY_INFLIGHT, 1);
    }

    public function end(Request $request, Response $response): void
    {
        $startedAt = (float)(Yii::$app->params['_metricsStartedAt'] ?? microtime(true));
        $duration = max(0.0, microtime(true) - $startedAt);
        $route = $this->routeLabel();
        $status = (string)$response->statusCode;
        $method = $request->method;

        $requests = Yii::$app->cache->get(self::KEY_REQUESTS);
        if (!is_array($requests)) {
            $requests = [];
        }

        $key = $method . ' ' . $route . ' ' . $status;
        $requests[$key] = (int)($requests[$key] ?? 0) + 1;
        Yii::$app->cache->set(self::KEY_REQUESTS, $requests, 0);

        $this->increment(self::KEY_TOTAL, 1);
        $this->increment(self::KEY_DURATION_SUM, $duration);
        $this->increment(self::KEY_INFLIGHT, -1);
    }

    public function expose(): string
    {
        $requests = Yii::$app->cache->get(self::KEY_REQUESTS);
        if (!is_array($requests)) {
            $requests = [];
        }

        ksort($requests);
        $lines = [
            '# TYPE sso_http_requests_total counter',
        ];

        foreach ($requests as $key => $count) {
            $parts = explode(' ', (string)$key, 3);
            if (count($parts) !== 3) {
                continue;
            }

            [$method, $route, $status] = $parts;
            $lines[] = sprintf(
                'sso_http_requests_total{method="%s",route="%s",status="%s"} %d',
                $this->escapeLabel($method),
                $this->escapeLabel($route),
                $this->escapeLabel($status),
                (int)$count
            );
        }

        $lines[] = '# TYPE sso_http_inflight gauge';
        $lines[] = 'sso_http_inflight ' . max(0, (int)Yii::$app->cache->get(self::KEY_INFLIGHT));
        $lines[] = '# TYPE sso_http_request_duration_seconds_sum counter';
        $lines[] = 'sso_http_request_duration_seconds_sum ' . sprintf('%.6F', (float)Yii::$app->cache->get(self::KEY_DURATION_SUM));
        $lines[] = '# TYPE sso_http_requests_processed_total counter';
        $lines[] = 'sso_http_requests_processed_total ' . (int)Yii::$app->cache->get(self::KEY_TOTAL);

        return implode("\n", $lines) . "\n";
    }

    private function increment(string $key, $delta): void
    {
        $value = Yii::$app->cache->get($key);
        if (!is_numeric($value)) {
            $value = 0;
        }

        Yii::$app->cache->set($key, $value + $delta, 0);
    }

    private function routeLabel(): string
    {
        $route = (string)(Yii::$app->requestedRoute ?: Yii::$app->request->pathInfo);
        return $route !== '' ? $route : '/';
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\n', '\"'], $value);
    }
}
