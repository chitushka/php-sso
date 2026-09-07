<?php

namespace app\services;

use app\models\AuditLog;
use Yii;

class AuditService
{
    public static function record(?string $actorUserId, string $action, string $targetType, string $targetId): void
    {
        $log = new AuditLog();
        $log->actor_user_id = $actorUserId;
        $log->action = $action;
        $log->target_type = $targetType;
        $log->target_id = $targetId;
        $log->ip = Yii::$app instanceof \yii\web\Application ? Yii::$app->request->userIP : null;
        $log->user_agent = Yii::$app instanceof \yii\web\Application ? Yii::$app->request->userAgent : null;
        $log->save(false);
    }
}
