<?php

namespace app\services;

use app\components\MetricRoute;
use Yii;
use yii\web\Request;
use yii\web\Response;

class MetricsService
{
    private const KEY_REQUESTS = 'metrics:v2:http_requests';
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

        $key = $method . ' ' . $route . ' ' . $status;
        $this->incrementMany([
            self::KEY_REQUESTS . ':' . $key => 1,
            self::KEY_TOTAL => 1,
            self::KEY_DURATION_SUM => $duration,
            self::KEY_INFLIGHT => -1,
        ]);
    }

    public function expose(): string
    {
        $requests = Yii::$app->db->createCommand(
            'SELECT metric_key, metric_value FROM metric_counters WHERE metric_key LIKE :prefix ORDER BY metric_key',
            [':prefix' => self::KEY_REQUESTS . ':%']
        )->queryAll();
        $lines = [
            '# TYPE sso_http_requests_total counter',
        ];

        foreach ($requests as $row) {
            $key = substr((string)$row['metric_key'], strlen(self::KEY_REQUESTS) + 1);
            $count = $row['metric_value'];
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
        $lines[] = 'sso_http_inflight ' . max(0, (int)$this->value(self::KEY_INFLIGHT));
        $lines[] = '# TYPE sso_http_request_duration_seconds_sum counter';
        $lines[] = 'sso_http_request_duration_seconds_sum ' . sprintf('%.6F', $this->value(self::KEY_DURATION_SUM));
        $lines[] = '# TYPE sso_http_requests_processed_total counter';
        $lines[] = 'sso_http_requests_processed_total ' . (int)$this->value(self::KEY_TOTAL);

        return implode("\n", $lines) . "\n";
    }

    private function increment(string $key, $delta): void
    {
        Yii::$app->db->createCommand(
            'INSERT INTO metric_counters(metric_key, metric_value) VALUES (:key, :delta)
             ON CONFLICT (metric_key) DO UPDATE SET metric_value = metric_counters.metric_value + :delta, updated_at = now()',
            [':key' => $key, ':delta' => $delta]
        )->execute();
    }

    private function incrementMany(array $deltas): void
    {
        $values = [];
        $params = [];
        $index = 0;
        foreach ($deltas as $key => $delta) {
            $values[] = "(:key{$index}, :delta{$index})";
            $params[":key{$index}"] = $key;
            $params[":delta{$index}"] = $delta;
            $index++;
        }
        Yii::$app->db->createCommand(
            'INSERT INTO metric_counters(metric_key, metric_value) VALUES ' . implode(', ', $values)
            . ' ON CONFLICT (metric_key) DO UPDATE SET '
            . 'metric_value = metric_counters.metric_value + EXCLUDED.metric_value, updated_at = now()',
            $params
        )->execute();
    }

    private function value(string $key): float
    {
        $value = Yii::$app->db->createCommand(
            'SELECT metric_value FROM metric_counters WHERE metric_key = :key',
            [':key' => $key]
        )->queryScalar();
        return $value === false ? 0.0 : (float)$value;
    }

    private function routeLabel(): string
    {
        return MetricRoute::label(
            (string)Yii::$app->requestedRoute,
            (string)Yii::$app->request->pathInfo
        );
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\n', '\"'], $value);
    }
}
