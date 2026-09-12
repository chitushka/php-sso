<?php

namespace app\commands;

use Yii;
use yii\console\Controller;

class CleanupController extends Controller
{
    public function actionRun(): int
    {
        $statements = [
            "DELETE FROM sessions WHERE expires_at < now() - interval '24 hours' OR (revoked_at IS NOT NULL AND revoked_at < now() - interval '24 hours')",
            "DELETE FROM oauth_authorization_codes WHERE expires_at < now() - interval '1 hour'",
            "DELETE FROM refresh_tokens WHERE expires_at < now() - interval '7 days' OR (revoked_at IS NOT NULL AND revoked_at < now() - interval '7 days')",
            "DELETE FROM one_time_tokens WHERE expires_at < now() - interval '7 days' OR (used_at IS NOT NULL AND used_at < now() - interval '7 days')",
            "DELETE FROM login_attempts WHERE updated_at < now() - interval '24 hours'",
        ];

        foreach ($statements as $statement) {
            $deleted = Yii::$app->db->createCommand($statement)->execute();
            $this->stdout($deleted . " rows deleted\n");
        }

        return self::EXIT_CODE_NORMAL;
    }
}
