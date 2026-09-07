<?php

namespace app\models;

use yii\db\ActiveRecord;

class OAuthClient extends ActiveRecord
{
    public const TYPE_CONFIDENTIAL = 'confidential';
    public const TYPE_PUBLIC = 'public';

    public static function tableName(): string
    {
        return 'oauth_clients';
    }

    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'type' => $this->type,
            'redirect_uris' => self::parsePgArray($this->redirect_uris),
            'allowed_scopes' => self::parsePgArray($this->allowed_scopes),
            'post_logout_redirect_uris' => self::parsePgArray($this->post_logout_redirect_uris),
            'backchannel_logout_uri' => $this->backchannel_logout_uri,
            'skip_consent' => (bool)$this->skip_consent,
            'enabled' => (bool)$this->enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function parsePgArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string)$value);
        if ($value === '' || $value === '{}') {
            return [];
        }

        $value = trim($value, '{}');
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item) {
            return trim($item, "\" ");
        }, str_getcsv($value))));
    }

    public static function toPgArray(array $items): string
    {
        $quoted = array_map(static function ($item) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$item) . '"';
        }, array_values($items));

        return '{' . implode(',', $quoted) . '}';
    }
}
