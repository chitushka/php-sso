<?php

namespace app\models;

use app\components\Rfc3339;
use yii\db\ActiveRecord;

class AuditLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'audit_logs';
    }

    public function publicData(): array
    {
        $data = [
            'id' => $this->id,
            'action' => $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'ip' => (string)$this->ip,
            'user_agent' => (string)$this->user_agent,
            'created_at' => Rfc3339::format($this->created_at),
        ];

        if ($this->actor_user_id !== null) {
            $data['actor_user_id'] = $this->actor_user_id;
        }

        return $data;
    }
}
