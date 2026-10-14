<?php

namespace app\commands;

use app\services\MetricsService;
use Yii;
use yii\console\Controller;

class CleanupController extends Controller
{
    private const LOCK_ID = 1397968721;

    public function actionRun(): int
    {
        $startedAt = microtime(true);
        $locked = (bool)Yii::$app->db->createCommand(
            'SELECT pg_try_advisory_lock(:lock_id)',
            [':lock_id' => self::LOCK_ID]
        )->queryScalar();
        if (!$locked) {
            $this->stdout("cleanup already running\n");
            return self::EXIT_CODE_NORMAL;
        }

        try {
            $retention = max(1, (int)(Yii::$app->params['auditRetentionDays'] ?? 365));
            $predicates = [
                'sessions' => "expires_at < now() - interval '24 hours' OR revoked_at < now() - interval '24 hours'",
                'oauth_authorization_codes' => "expires_at < now() - interval '1 hour'",
                'refresh_tokens' => "expires_at < now() - interval '7 days' OR revoked_at < now() - interval '7 days'",
                'one_time_tokens' => "expires_at < now() - interval '7 days' OR used_at < now() - interval '7 days'",
                'login_attempts' => "updated_at < now() - interval '24 hours'",
                'revoked_access_tokens' => 'expires_at <= now()',
                'rate_limit_buckets' => 'expires_at <= now()',
                'broker_login_states' => "expires_at < now() - interval '24 hours'",
                'audit_logs' => "created_at < now() - make_interval(days => {$retention})",
            ];
            $total = 0;
            foreach ($predicates as $table => $predicate) {
                $deleted = $this->deleteBatches($table, $predicate);
                $total += $deleted;
                $this->stdout($deleted . " {$table} rows deleted\n");
            }
            $metrics = new MetricsService();
            $metrics->setGauge('cleanup_last_success_timestamp_seconds', time());
            $metrics->setGauge('cleanup_duration_seconds', microtime(true) - $startedAt);
            $metrics->setGauge('cleanup_rows_deleted', $total);
            $metrics->event('cleanup', 'success');
            return self::EXIT_CODE_NORMAL;
        } catch (\Throwable $exception) {
            (new MetricsService())->event('cleanup', 'failure');
            Yii::error('cleanup run failed', 'cleanup');
            return self::EXIT_CODE_ERROR;
        } finally {
            Yii::$app->db->createCommand(
                'SELECT pg_advisory_unlock(:lock_id)',
                [':lock_id' => self::LOCK_ID]
            )->queryScalar();
        }
    }

    public function actionStatus(int $maxAge = 7200): int
    {
        $lastSuccess = Yii::$app->db->createCommand(
            'SELECT metric_value FROM metric_counters WHERE metric_key = :key',
            [':key' => 'operations:cleanup_last_success_timestamp_seconds']
        )->queryScalar();
        if ($lastSuccess === false || (float)$lastSuccess < time() - max(60, $maxAge)) {
            $this->stderr("cleanup has no recent successful run\n");
            return self::EXIT_CODE_ERROR;
        }

        $this->stdout("cleanup is healthy\n");
        return self::EXIT_CODE_NORMAL;
    }

    private function deleteBatches(string $table, string $predicate): int
    {
        $batch = max(1, min(10000, (int)(Yii::$app->params['cleanupBatchSize'] ?? 1000)));
        $total = 0;
        do {
            $deleted = Yii::$app->db->createCommand(
                "DELETE FROM {$table} WHERE ctid IN (SELECT ctid FROM {$table} WHERE {$predicate} LIMIT {$batch})"
            )->execute();
            $total += $deleted;
        } while ($deleted === $batch);
        return $total;
    }
}
