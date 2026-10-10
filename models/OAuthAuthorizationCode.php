<?php

namespace app\models;

class OAuthAuthorizationCode extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'oauth_authorization_codes';
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['code_challenge_method'], 'in', 'range' => ['S256'], 'skipOnEmpty' => true],
        ]);
    }
}
