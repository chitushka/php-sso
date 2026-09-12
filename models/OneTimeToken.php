<?php

namespace app\models;

use yii\db\ActiveRecord;

class OneTimeToken extends ActiveRecord
{
    public const PURPOSE_MFA_LOGIN = 'mfa_login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_EMAIL_VERIFY = 'email_verify';

    public static function tableName(): string
    {
        return 'one_time_tokens';
    }
}
