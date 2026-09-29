<?php

namespace app\models;

class LoginAttempt extends ValidatedActiveRecord
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
