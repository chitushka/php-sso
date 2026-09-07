<?php

namespace app\models;

use yii\db\ActiveRecord;

class User extends ActiveRecord
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELETED = 'deleted';

    public const SOURCE_LOCAL = 'local';
    public const SOURCE_LDAP = 'ldap';
    public const SOURCE_FEDERATED = 'federated';

    public static function tableName(): string
    {
        return 'users';
    }

    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'source' => $this->source,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'attributes' => $this->decodeJson($this->attributes),
            'email_verified' => (bool)$this->email_verified,
            'mfa_enabled' => (bool)$this->mfa_enabled,
            'ldap_provider_id' => $this->ldap_provider_id,
            'ldap_dn' => $this->ldap_dn,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'last_login_at' => $this->last_login_at,
        ];
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
