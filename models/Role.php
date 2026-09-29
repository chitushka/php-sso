<?php

namespace app\models;

use app\components\Rfc3339;
class Role extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'roles';
    }

    public function publicData(): array
    {
        $data = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'created_at' => Rfc3339::format($this->created_at),
            'updated_at' => Rfc3339::format($this->updated_at),
        ];

        if ((string)$this->description !== '') {
            $data['description'] = (string)$this->description;
        }

        return $data;
    }
}
