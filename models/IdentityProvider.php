<?php

namespace app\models;

class IdentityProvider extends ValidatedActiveRecord
{
    public static function tableName(): string
    {
        return 'identity_providers';
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['type'], 'in', 'range' => ['oidc'], 'skipOnEmpty' => false],
        ]);
    }
}
