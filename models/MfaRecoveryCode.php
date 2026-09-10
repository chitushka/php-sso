<?php

namespace app\models;

use yii\db\ActiveRecord;

class MfaRecoveryCode extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'mfa_recovery_codes';
    }
}
