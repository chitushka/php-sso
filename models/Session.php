<?php

namespace app\models;

class Session extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'sessions';
    }
}
