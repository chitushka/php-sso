<?php

namespace app\models;

use yii\db\ActiveRecord;

class LdapProvider extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'ldap_providers';
    }
}
