<?php

namespace app\services;

use app\components\Rfc3339;

final class OperationalStatusService
{
    private const CLEANUP_STALE_MULTIPLIER = 2;
    private const CLOCK_SKEW_SECONDS = 60;

    public function status(): array
    {
        $databaseAvailable = false;
        try {
            ApplicationRuntime::db()->createCommand('SELECT 1')->queryScalar();
            $databaseAvailable = true;
        } catch (\Throwable $exception) {
            // Статус остаётся fail-closed и не раскрывает текст ошибки БД.
        }

        $signing = $this->unavailableSigningSummary();
        $cleanup = $this->unavailableCleanupStatus();
        if ($databaseAvailable) {
            try {
                $signing = $this->signingSummary();
            } catch (\Throwable $exception) {
                // Последующий отказ БД не должен раскрывать исключение или оставлять ложный ready.
                $databaseAvailable = false;
            }
        }
        if ($databaseAvailable) {
            try {
                $cleanup = $this->cleanupStatus();
            } catch (\Throwable $exception) {
                // Все DB-backed компоненты fail-closed даже после успешного первоначального probe.
                $databaseAvailable = false;
            }
        }

        $database = [
            'status' => $databaseAvailable ? 'ready' : 'degraded',
            'ready' => $databaseAvailable,
        ];

        return [
            'status' => $databaseAvailable && $signing['ready'] && $cleanup['ready']
                ? 'ready'
                : 'degraded',
            'version' => (string)ApplicationRuntime::param('version'),
            'environment' => (string)ApplicationRuntime::param('environment'),
            'database' => $database,
            'signing_keys' => $signing,
            'cleanup' => $cleanup,
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

        $ready = $counts['active'] === 1 && (new OidcService())->signingReady();

        return array_merge([
            'status' => $ready ? 'ready' : 'degraded',
            'ready' => $ready,
        ], $counts);
    }

    private function cleanupStatus(): array
    {
        $timestamp = ApplicationRuntime::db()->createCommand(
            "SELECT metric_value FROM metric_counters
             WHERE metric_key = 'operations:cleanup_last_success_timestamp_seconds'"
        )->queryScalar();

        $interval = max(60, (int)ApplicationRuntime::param('cleanupInterval', 3600));
        $maxAge = $interval * self::CLEANUP_STALE_MULTIPLIER;
        $lastSuccess = $timestamp === false ? 0 : (int)$timestamp;
        $now = time();
        $validTimestamp = $lastSuccess > 0 && $lastSuccess <= $now + self::CLOCK_SKEW_SECONDS;
        $age = $validTimestamp ? max(0, $now - $lastSuccess) : null;
        $ready = $age !== null && $age <= $maxAge;

        return [
            'status' => $ready ? 'ready' : 'degraded',
            'ready' => $ready,
            'stale' => !$ready,
            'last_success_at' => $lastSuccess > 0
                ? Rfc3339::format(gmdate('Y-m-d H:i:s', $lastSuccess) . '+00')
                : null,
            'age_seconds' => $age,
            'interval_seconds' => $interval,
            'max_age_seconds' => $maxAge,
        ];
    }

    private function unavailableSigningSummary(): array
    {
        return [
            'status' => 'degraded',
            'ready' => false,
            'active' => 0,
            'retiring' => 0,
            'retired' => 0,
        ];
    }

    private function unavailableCleanupStatus(): array
    {
        $interval = max(60, (int)ApplicationRuntime::param('cleanupInterval', 3600));

        return [
            'status' => 'degraded',
            'ready' => false,
            'stale' => true,
            'last_success_at' => null,
            'age_seconds' => null,
            'interval_seconds' => $interval,
            'max_age_seconds' => $interval * self::CLEANUP_STALE_MULTIPLIER,
        ];
    }
}
