<?php

namespace app\models;

class IdentityProvider extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'identity_providers';
    }
}
