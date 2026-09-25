<?php

namespace app\services;

use app\models\AuditLog;
use Yii;

class AuditService
{
    public static function record(
        ?string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        bool $includeRequestContext = false
    ): void
    {
        $log = new AuditLog();
        $log->actor_user_id = $actorUserId;
        $log->action = $action;
        $log->target_type = $targetType;
        $log->target_id = $targetId;
        $hasRequest = $includeRequestContext && Yii::$app instanceof \yii\web\Application;
        $log->ip = $hasRequest ? Yii::$app->request->userIP : null;
        $log->user_agent = $hasRequest ? Yii::$app->request->userAgent : null;
        $log->save(false);
    }
}
