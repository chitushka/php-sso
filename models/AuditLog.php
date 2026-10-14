<?php

namespace app\models;

use app\components\Rfc3339;

class AuditLog extends ValidatedActiveRecord
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
        $data['actor'] = ['type' => (string)$this->getAttribute('actor_type')];
        $actorId = $this->actor_user_id ?? $this->getAttribute('actor_id');
        if ($actorId !== null) {
            $data['actor']['id'] = (string)$actorId;
        }

        return $data;
    }
}
