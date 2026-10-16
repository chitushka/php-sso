<?php

namespace app\models;

use app\components\Rfc3339;
use yii\db\ArrayExpression;

class OAuthClient extends ValidatedActiveRecord
{
    public const TYPE_CONFIDENTIAL = 'confidential';
    public const TYPE_PUBLIC = 'public';

    public static function tableName(): string
    {
        return 'oauth_clients';
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['type'], 'in', 'range' => [self::TYPE_CONFIDENTIAL, self::TYPE_PUBLIC], 'skipOnEmpty' => false],
            [['client_secret_hash'], 'required', 'when' => static function (self $client): bool {
                return $client->getAttribute('type') === self::TYPE_CONFIDENTIAL;
            }],
            [['allow_loopback_redirects'], 'boolean'],
            // Политику HTTPS/private networks проверяет OutboundHttpService с учётом явного allowlist.
            [['backchannel_logout_uri'], 'url', 'validSchemes' => ['http', 'https'], 'skipOnEmpty' => true],
        ]);
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
            'allow_loopback_redirects' => (bool)$this->allow_loopback_redirects,
            'enabled' => (bool)$this->enabled,
            'created_at' => Rfc3339::format($this->created_at),
            'updated_at' => Rfc3339::format($this->updated_at),
        ];
    }

    public static function parsePgArray($value): array
    {
        if ($value instanceof ArrayExpression) {
            $value = $value->getValue();
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                if ($item === null && is_string($key)) {
                    $item = $key;
                }
                if ($item instanceof ArrayExpression) {
                    $item = $item->getValue();
                }
                if (is_array($item)) {
                    $items = array_merge($items, self::parsePgArray($item));
                    continue;
                }
                $normalized = trim((string)$item, "{}\" ");
                if ($normalized !== '') {
                    $items[] = $normalized;
                }
            }

            return $items;
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

    public static function toPgArray(array $items): ArrayExpression
    {
        return new ArrayExpression(array_values(array_map('strval', $items)), 'text');
    }
}
