<?php

namespace app\models;

use yii\db\ActiveRecord;

class Group extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'groups';
    }
}
