<?php

namespace app\models;

use yii\db\ActiveRecord;

class AuditLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'audit_logs';
    }
}
