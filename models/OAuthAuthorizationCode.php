<?php

namespace app\models;

use yii\db\ActiveRecord;

class OAuthAuthorizationCode extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'oauth_authorization_codes';
    }
}
