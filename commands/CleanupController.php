<?php

namespace app\commands;

use Yii;
use yii\console\Controller;

class CleanupController extends Controller
{
    private const LOCK_ID = 1397968721;

    public function actionRun(): int
    {
        $locked = (bool)Yii::$app->db->createCommand(
            'SELECT pg_try_advisory_lock(:lock_id)', [':lock_id' => self::LOCK_ID]
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
                'audit_logs' => "created_at < now() - make_interval(days => {$retention})",
            ];
            foreach ($predicates as $table => $predicate) {
                $this->stdout($this->deleteBatches($table, $predicate) . " {$table} rows deleted\n");
            }
        } finally {
            Yii::$app->db->createCommand(
                'SELECT pg_advisory_unlock(:lock_id)', [':lock_id' => self::LOCK_ID]
            )->queryScalar();
        }
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
