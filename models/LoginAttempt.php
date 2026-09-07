<?php

namespace app\models;

use yii\db\ActiveRecord;

class LoginAttempt extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'login_attempts';
    }

    public static function primaryKey(): array
    {
        return ['username', 'ip'];
    }
}
