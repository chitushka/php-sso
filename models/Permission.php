<?php

namespace app\models;

use app\components\Rfc3339;
class Permission extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'permissions';
    }

    public function publicData(): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'resource' => $this->resource,
            'action' => $this->action,
            'created_at' => Rfc3339::format($this->created_at),
        ];

        if ((string)$this->description !== '') {
            $data['description'] = (string)$this->description;
        }

        return $data;
    }
}
