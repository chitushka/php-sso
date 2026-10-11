<?php

namespace app\services;

use app\components\Rfc3339;

final class OperationalStatusService
{
    public function status(): array
    {
        $databaseAvailable = false;
        try {
            ApplicationRuntime::db()->createCommand('SELECT 1')->queryScalar();
            $databaseAvailable = true;
        } catch (\Throwable $exception) {
            // Статус остаётся fail-closed и не раскрывает текст ошибки БД.
        }

        $signing = $databaseAvailable ? $this->signingSummary() : [
            'ready' => false,
            'active' => 0,
            'retiring' => 0,
            'retired' => 0,
        ];

        return [
            'status' => $databaseAvailable && $signing['ready'] ? 'ready' : 'degraded',
            'version' => (string)ApplicationRuntime::param('version'),
            'environment' => (string)ApplicationRuntime::param('environment'),
            'database' => ['ready' => $databaseAvailable],
            'signing_keys' => $signing,
            'cleanup' => $databaseAvailable ? $this->cleanupStatus() : ['last_success_at' => null],
        ];
    }

    private function signingSummary(): array
    {
        $counts = ['active' => 0, 'retiring' => 0, 'retired' => 0];
        $rows = ApplicationRuntime::db()->createCommand(
            'SELECT status, COUNT(*) AS count FROM oidc_signing_keys GROUP BY status'
        )->queryAll();
        foreach ($rows as $row) {
            if (array_key_exists((string)$row['status'], $counts)) {
                $counts[(string)$row['status']] = (int)$row['count'];
            }
        }

        return array_merge(['ready' => $counts['active'] === 1], $counts);
    }

    private function cleanupStatus(): array
    {
        $timestamp = ApplicationRuntime::db()->createCommand(
            "SELECT metric_value FROM metric_counters
             WHERE metric_key = 'operations:cleanup_last_success_timestamp_seconds'"
        )->queryScalar();

        return [
            'last_success_at' => $timestamp === false || (float)$timestamp <= 0
                ? null
                : Rfc3339::format(gmdate('Y-m-d H:i:s', (int)$timestamp) . '+00'),
        ];
    }
}
