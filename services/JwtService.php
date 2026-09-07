<?php

namespace app\services;

use Yii;

class JwtService
{
    private string $secret;
    private string $issuer;

    public function __construct()
    {
        $this->secret = Yii::$app->params['jwtSecret'];
        $this->issuer = Yii::$app->params['issuer'];
    }

    public function issue(string $subject, array $claims = [], ?int $ttl = null): string
    {
        $now = time();
        $ttl = $ttl ?? Yii::$app->params['accessTokenTtl'];
        $payload = array_merge($claims, [
            'iss' => $this->issuer,
            'sub' => $subject,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        return $this->encode(['typ' => 'JWT', 'alg' => 'HS256'], $payload);
    }

    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = self::base64url(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode(self::base64urlDecode($payload), true);
        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    private function encode(array $header, array $payload): string
    {
        $head = self::base64url(json_encode($header));
        $body = self::base64url(json_encode($payload));
        $signature = self::base64url(hash_hmac('sha256', $head . '.' . $body, $this->secret, true));

        return $head . '.' . $body . '.' . $signature;
    }

    public static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
