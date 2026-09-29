<?php

namespace app\models;

class FederatedIdentity extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'federated_identities';
    }
}
