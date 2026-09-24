<?php

namespace app\models;

use app\components\Rfc3339;
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
        $attributes = $this->decodeJson($this->attributes);
        $data = [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'source' => $this->source,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'attributes' => $attributes === [] ? new \stdClass() : $attributes,
            'email_verified' => (bool)$this->email_verified,
            'mfa_enabled' => (bool)$this->mfa_enabled,
            'created_at' => Rfc3339::format($this->created_at),
            'updated_at' => Rfc3339::format($this->updated_at),
        ];

        if ($this->ldap_provider_id !== null) {
            $data['ldap_provider_id'] = $this->ldap_provider_id;
        }
        if ($this->ldap_dn !== null) {
            $data['ldap_dn'] = $this->ldap_dn;
        }
        if ($this->last_login_at !== null) {
            $data['last_login_at'] = Rfc3339::format($this->last_login_at);
        }

        return $data;
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
