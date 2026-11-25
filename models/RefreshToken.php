<?php

namespace app\models;

/**
 * @property int|null $auth_time Исходное время OAuth grant, неизменное при ротации.
 * @property string $family_id
 * @property string $scope
 */
class RefreshToken extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'refresh_tokens';
    }
}
