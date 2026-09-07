<?php

namespace app\models;

use yii\db\ActiveRecord;

class RefreshToken extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'refresh_tokens';
    }
}
