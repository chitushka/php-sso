<?php

namespace app\models;

use yii\db\ActiveRecord;

class FederatedIdentity extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'federated_identities';
    }
}
