<?php

namespace app\models;

use yii\db\ActiveRecord;

class OneTimeToken extends ActiveRecord
{
    public const PURPOSE_MFA_LOGIN = 'mfa_login';

    public static function tableName(): string
    {
        return 'one_time_tokens';
    }
}
