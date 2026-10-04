<?php

namespace app\services;

use Yii;

class JwtService
{
    private string $secret;

    public function __construct()
    {
        $this->secret = Yii::$app->params['jwtSecret'];
    }

    public function issue(string $subject, array $claims = [], ?int $ttl = null, ?int $issuedAt = null): string
    {
        $now = $issuedAt ?? time();
        $ttl = $ttl ?? Yii::$app->params['accessTokenTtl'];
        $payload = array_merge($claims, [
            'sub' => $subject,
            'jti' => $claims['jti'] ?? self::base64url(random_bytes(16)),
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
        $decodedHeader = self::decodeJwtSegment($header);
        $decodedPayload = self::decodeJwtSegment($payload);
        $decodedSignature = self::decodeJwtSegment($signature);
        if ($decodedHeader === null || $decodedPayload === null || $decodedSignature === null) {
            return null;
        }

        $headerData = json_decode($decodedHeader, true);
        if (!is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', $header . '.' . $payload, $this->secret, true);

        if (!hash_equals($expected, $decodedSignature)) {
            return null;
        }

        $data = json_decode($decodedPayload, true);
        if (!is_array($data)) {
            return null;
        }

        $now = time();
        $expiresAt = $data['exp'] ?? null;
        if (
            (!is_int($expiresAt) && !is_float($expiresAt))
            || $expiresAt <= $now
        ) {
            return null;
        }

        if (
            array_key_exists('nbf', $data)
            && ((!is_int($data['nbf']) && !is_float($data['nbf'])) || $data['nbf'] > $now)
        ) {
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

    private static function decodeJwtSegment(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }

        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
