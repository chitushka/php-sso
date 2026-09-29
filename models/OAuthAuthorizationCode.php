<?php

namespace app\models;

class OAuthAuthorizationCode extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'oauth_authorization_codes';
    }
}
