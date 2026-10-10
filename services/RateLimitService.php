<?php

namespace app\services;

use yii\web\Request;

class RateLimitService
{
    public function check(string $route, Request $request): array
    {
        $routes = ApplicationRuntime::param('rateLimitedRoutes', []);
        if (!in_array($route, $routes, true)) {
            return ['allowed' => true];
        }

        $capacity = max(1.0, (float)ApplicationRuntime::param('rateLimitBurst', 10));
        $refillPerSecond = max(
            1.0 / 3600.0,
            ((float)ApplicationRuntime::param('rateLimitPerMinute', 30)) / 60.0
        );
        $key = sha1($route . '|' . $request->userIP);
        $now = microtime(true);
        $state = ApplicationRuntime::db()->createCommand(<<<'SQL'
INSERT INTO rate_limit_buckets(bucket_key, tokens, updated_at, expires_at, last_allowed)
VALUES (:key, :capacity - 1, :now, now() + interval '2 minutes', true)
ON CONFLICT (bucket_key) DO UPDATE SET
    tokens = CASE
        WHEN LEAST(:capacity, rate_limit_buckets.tokens + GREATEST(0, :now - rate_limit_buckets.updated_at) * :refill) >= 1
        THEN LEAST(:capacity, rate_limit_buckets.tokens + GREATEST(0, :now - rate_limit_buckets.updated_at) * :refill) - 1
        ELSE LEAST(:capacity, rate_limit_buckets.tokens + GREATEST(0, :now - rate_limit_buckets.updated_at) * :refill)
    END,
    updated_at = :now,
    expires_at = now() + interval '2 minutes',
    last_allowed = LEAST(:capacity, rate_limit_buckets.tokens + GREATEST(0, :now - rate_limit_buckets.updated_at) * :refill) >= 1
RETURNING last_allowed AS allowed, tokens AS available
SQL, [':key' => $key, ':capacity' => $capacity, ':now' => $now, ':refill' => $refillPerSecond])->queryOne();

        if (!filter_var($state['allowed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $retryAfter = (int)ceil((1.0 - (float)$state['available']) / $refillPerSecond);

            return [
                'allowed' => false,
                'retry_after' => max(1, $retryAfter),
            ];
        }

        return ['allowed' => true];
    }
}
