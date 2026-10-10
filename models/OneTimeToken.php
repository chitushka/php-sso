<?php

namespace app\models;

class OneTimeToken extends ValidatedActiveRecord
{
    public const PURPOSE_MFA_LOGIN = 'mfa_login';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_EMAIL_VERIFY = 'email_verify';

    public static function tableName(): string
    {
        return 'one_time_tokens';
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['purpose'], 'in', 'range' => [self::PURPOSE_MFA_LOGIN, self::PURPOSE_PASSWORD_RESET, self::PURPOSE_EMAIL_VERIFY], 'skipOnEmpty' => false],
        ]);
    }
}
