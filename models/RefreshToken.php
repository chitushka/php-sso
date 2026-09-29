<?php

namespace app\models;

class RefreshToken extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'refresh_tokens';
    }
}
