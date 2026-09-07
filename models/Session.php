<?php

namespace app\models;

use yii\db\ActiveRecord;

class Session extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'sessions';
    }
}
