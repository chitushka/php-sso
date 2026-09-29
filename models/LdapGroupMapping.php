<?php

namespace app\models;

class LdapGroupMapping extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'ldap_group_mappings';
    }
}
