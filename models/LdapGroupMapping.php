<?php

namespace app\models;

use yii\db\ActiveRecord;

class LdapGroupMapping extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'ldap_group_mappings';
    }
}
