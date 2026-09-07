<?php

namespace app\services;

use Yii;
use yii\web\Request;

class RateLimitService
{
    public function check(string $route, Request $request): array
    {
        $routes = Yii::$app->params['rateLimitedRoutes'] ?? [];
        if (!in_array($route, $routes, true)) {
            return ['allowed' => true];
        }

        $capacity = (float)(Yii::$app->params['rateLimitBurst'] ?? 10);
        $refillPerSecond = ((float)(Yii::$app->params['rateLimitPerMinute'] ?? 30)) / 60.0;
        $key = 'rate_limit:' . sha1($route . '|' . $request->userIP);
        $now = microtime(true);
        $state = Yii::$app->cache->get($key);

        if (!is_array($state)) {
            $state = ['tokens' => $capacity, 'updated_at' => $now];
        }

        $elapsed = max(0.0, $now - (float)$state['updated_at']);
        $tokens = min($capacity, (float)$state['tokens'] + $elapsed * $refillPerSecond);

        if ($tokens < 1.0) {
            Yii::$app->cache->set($key, ['tokens' => $tokens, 'updated_at' => $now], 120);
            $retryAfter = (int)ceil((1.0 - $tokens) / $refillPerSecond);

            return [
                'allowed' => false,
                'retry_after' => max(1, $retryAfter),
            ];
        }

        Yii::$app->cache->set($key, ['tokens' => $tokens - 1.0, 'updated_at' => $now], 120);
        return ['allowed' => true];
    }
}
