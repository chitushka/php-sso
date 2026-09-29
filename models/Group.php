<?php

namespace app\models;

use app\components\Rfc3339;
class Group extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'groups';
    }

    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => (string)$this->description,
            'created_at' => Rfc3339::format($this->created_at),
            'updated_at' => Rfc3339::format($this->updated_at),
        ];
    }
}
