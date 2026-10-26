<?php

namespace app\models;

class IdentityProvider extends ValidatedActiveRecord
{
    public const TYPE_OIDC = 'oidc';
    public const SUPPORTED_TYPES = [self::TYPE_OIDC];

    public static function tableName(): string
    {
        return 'identity_providers';
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['type'], 'in', 'range' => self::SUPPORTED_TYPES, 'skipOnEmpty' => false],
        ]);
    }
}
