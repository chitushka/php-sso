<?php

namespace app\models;

use yii\db\ActiveRecord;

class Permission extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'permissions';
    }
}
