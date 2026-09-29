<?php

namespace app\models;

class LdapProvider extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'ldap_providers';
    }
}
