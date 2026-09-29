<?php

namespace app\models;

class MfaRecoveryCode extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'mfa_recovery_codes';
    }
}
