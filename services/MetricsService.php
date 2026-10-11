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
    private const KEY_ROUTE_DURATION_SUM = 'metrics:v3:http_duration_sum';
    private const KEY_ROUTE_DURATION_COUNT = 'metrics:v3:http_duration_count';
    private const KEY_TOTAL = 'metrics:http_total';
    private const KEY_DURATION_BUCKET = 'metrics:v3:http_duration_bucket';
    private const KEY_EVENTS = 'metrics:v3:events';
    private const DURATION_BUCKETS = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];
    private const EVENT_VALUES = [
        'auth' => ['success', 'failure', 'mfa_required'],
        'rate_limit' => ['blocked'],
        'mail' => ['success', 'failure'],
        'ldap' => ['success', 'failure'],
        'provider' => ['success', 'failure'],
        'cleanup' => ['success', 'failure'],
    ];

    public function begin(): void
    {
        ApplicationRuntime::setState('metricsStartedAt', microtime(true));
        $this->increment(self::KEY_INFLIGHT, 1);
    }

    public function end(Request $request, Response $response): void
    {
        $startedAt = (float)ApplicationRuntime::state('metricsStartedAt', microtime(true));
        $duration = max(0.0, microtime(true) - $startedAt);
        $route = $this->routeLabel();
        $status = (string)$response->statusCode;
        $method = $request->method;

        $key = $method . ' ' . $route . ' ' . $status;
        $deltas = [
            self::KEY_REQUESTS . ':' . $key => 1,
            self::KEY_TOTAL => 1,
            self::KEY_ROUTE_DURATION_SUM . ':' . $method . ' ' . $route => $duration,
            self::KEY_ROUTE_DURATION_COUNT . ':' . $method . ' ' . $route => 1,
        ];
        foreach (self::DURATION_BUCKETS as $bucket) {
            if ($duration <= $bucket) {
                $deltas[self::KEY_DURATION_BUCKET . ':' . $method . ' ' . $route . ' ' . $bucket] = 1;
            }
        }
        $deltas[self::KEY_DURATION_BUCKET . ':' . $method . ' ' . $route . ' +Inf'] = 1;
        $this->incrementMany($deltas);
        $this->decrementInflight();
    }

    public function event(string $family, string $outcome, float $delta = 1.0): void
    {
        if (
            !isset(self::EVENT_VALUES[$family])
            || !in_array($outcome, self::EVENT_VALUES[$family], true)
        ) {
            throw new \InvalidArgumentException('unsupported metric event');
        }
        try {
            $this->increment(self::KEY_EVENTS . ':' . $family . ':' . $outcome, $delta);
        } catch (\Throwable $exception) {
            Yii::warning('metric event could not be persisted', 'metrics');
        }
    }

    public function setGauge(string $name, float $value): void
    {
        if (preg_match('/^[a-z_]{1,64}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('invalid gauge name');
        }
        ApplicationRuntime::db()->createCommand(
            'INSERT INTO metric_counters(metric_key, metric_value) VALUES (:key, :value)
             ON CONFLICT (metric_key) DO UPDATE SET metric_value = EXCLUDED.metric_value, updated_at = now()',
            [':key' => 'operations:' . $name, ':value' => $value]
        )->execute();
    }

    public function expose(): string
    {
        $requests = ApplicationRuntime::db()->createCommand(
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

        $buckets = ApplicationRuntime::db()->createCommand(
            'SELECT metric_key, metric_value FROM metric_counters WHERE metric_key LIKE :prefix ORDER BY metric_key',
            [':prefix' => self::KEY_DURATION_BUCKET . ':%']
        )->queryAll();
        $lines[] = '# TYPE sso_http_request_duration_seconds histogram';
        foreach ($buckets as $row) {
            $key = substr((string)$row['metric_key'], strlen(self::KEY_DURATION_BUCKET) + 1);
            $parts = explode(' ', $key, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$method, $route, $le] = $parts;
            $lines[] = sprintf(
                'sso_http_request_duration_seconds_bucket{method="%s",route="%s",le="%s"} %d',
                $this->escapeLabel($method),
                $this->escapeLabel($route),
                $this->escapeLabel($le),
                (int)$row['metric_value']
            );
        }

        foreach (
            [
            '_sum' => self::KEY_ROUTE_DURATION_SUM,
            '_count' => self::KEY_ROUTE_DURATION_COUNT,
            ] as $suffix => $prefix
        ) {
            $values = ApplicationRuntime::db()->createCommand(
                'SELECT metric_key, metric_value FROM metric_counters WHERE metric_key LIKE :prefix ORDER BY metric_key',
                [':prefix' => $prefix . ':%']
            )->queryAll();
            foreach ($values as $row) {
                $key = substr((string)$row['metric_key'], strlen($prefix) + 1);
                $parts = explode(' ', $key, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $lines[] = sprintf(
                    'sso_http_request_duration_seconds%s{method="%s",route="%s"} %s',
                    $suffix,
                    $this->escapeLabel($parts[0]),
                    $this->escapeLabel($parts[1]),
                    $suffix === '_count' ? (string)(int)$row['metric_value'] : sprintf('%.6F', $row['metric_value'])
                );
            }
        }

        $lines[] = '# TYPE sso_http_inflight gauge';
        $lines[] = 'sso_http_inflight ' . max(0, (int)$this->value(self::KEY_INFLIGHT));
        $lines[] = '# TYPE sso_http_requests_processed_total counter';
        $lines[] = 'sso_http_requests_processed_total ' . (int)$this->value(self::KEY_TOTAL);

        $events = ApplicationRuntime::db()->createCommand(
            'SELECT metric_key, metric_value FROM metric_counters WHERE metric_key LIKE :prefix ORDER BY metric_key',
            [':prefix' => self::KEY_EVENTS . ':%']
        )->queryAll();
        $lines[] = '# TYPE sso_events_total counter';
        foreach ($events as $row) {
            $parts = explode(':', substr((string)$row['metric_key'], strlen(self::KEY_EVENTS) + 1), 2);
            if (count($parts) === 2) {
                $lines[] = sprintf(
                    'sso_events_total{family="%s",outcome="%s"} %d',
                    $this->escapeLabel($parts[0]),
                    $this->escapeLabel($parts[1]),
                    (int)$row['metric_value']
                );
            }
        }
        foreach (
            [
            'cleanup_last_success_timestamp_seconds',
            'cleanup_duration_seconds',
            'cleanup_rows_deleted',
            ] as $gauge
        ) {
            $lines[] = '# TYPE sso_' . $gauge . ' gauge';
            $lines[] = 'sso_' . $gauge . ' ' . sprintf('%.6F', $this->value('operations:' . $gauge));
        }

        return implode("\n", $lines) . "\n";
    }

    private function increment(string $key, $delta): void
    {
        ApplicationRuntime::db()->createCommand(
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
        ApplicationRuntime::db()->createCommand(
            'INSERT INTO metric_counters(metric_key, metric_value) VALUES ' . implode(', ', $values)
            . ' ON CONFLICT (metric_key) DO UPDATE SET '
            . 'metric_value = metric_counters.metric_value + EXCLUDED.metric_value, updated_at = now()',
            $params
        )->execute();
    }

    private function decrementInflight(): void
    {
        // Отдельный UPDATE не создаёт отрицательную EXCLUDED-строку и сохраняет DB CHECK invariant.
        ApplicationRuntime::db()->createCommand(
            'UPDATE metric_counters
             SET metric_value = GREATEST(metric_value - 1, 0), updated_at = now()
             WHERE metric_key = :key',
            [':key' => self::KEY_INFLIGHT]
        )->execute();
    }

    private function value(string $key): float
    {
        $value = ApplicationRuntime::db()->createCommand(
            'SELECT metric_value FROM metric_counters WHERE metric_key = :key',
            [':key' => $key]
        )->queryScalar();
        return $value === false ? 0.0 : (float)$value;
    }

    private function routeLabel(): string
    {
        return MetricRoute::label(
            ApplicationRuntime::requestedRoute(),
            (string)ApplicationRuntime::request()->pathInfo
        );
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\n', '\"'], $value);
    }
}
