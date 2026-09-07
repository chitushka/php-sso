<?php

namespace app\models;

use yii\db\ActiveRecord;

class Role extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'roles';
    }
}
