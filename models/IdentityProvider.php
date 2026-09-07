<?php

namespace app\models;

use yii\db\ActiveRecord;

class IdentityProvider extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'identity_providers';
    }
}
