<?php

namespace app\services;

use app\models\User;
use Yii;

class OidcService
{
    private const MAX_LOGOUT_HINT_AGE = 86400;

    public function configuration(): array
    {
        $issuer = Yii::$app->params['issuer'];

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth2/authorize',
            'token_endpoint' => $issuer . '/oauth2/token',
            'userinfo_endpoint' => $issuer . '/oauth2/userinfo',
            'revocation_endpoint' => $issuer . '/oauth2/revoke',
            'introspection_endpoint' => $issuer . '/oauth2/introspect',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'end_session_endpoint' => $issuer . '/oauth2/logout',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'email', 'preferred_username'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'backchannel_logout_supported' => true,
        ];
    }

    public function jwks(): array
    {
        $key = $this->activeKey();
        $details = openssl_pkey_get_details(openssl_pkey_get_public($key['public_key_pem']));

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => $key['kid'],
                'alg' => 'RS256',
                'n' => JwtService::base64url($details['rsa']['n']),
                'e' => JwtService::base64url($details['rsa']['e']),
            ]],
        ];
    }

    public function issueIdToken(string $userId, string $audience, string $nonce = ''): string
    {
        $key = $this->activeKey();
        $user = User::findOne(['id' => $userId]);
        $now = time();
        $payload = [
            'iss' => Yii::$app->params['issuer'],
            'sub' => $userId,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + Yii::$app->params['accessTokenTtl'],
            'preferred_username' => $user ? $user->username : '',
            'email' => $user ? $user->email : '',
            'email_verified' => $user ? (bool)$user->email_verified : false,
        ];

        if ($nonce !== '') {
            $payload['nonce'] = $nonce;
        }

        $header = JwtService::base64url(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key['kid']]));
        $body = JwtService::base64url(json_encode($payload));
        openssl_sign($header . '.' . $body, $signature, $key['private_key_pem'], OPENSSL_ALGO_SHA256);

        return $header . '.' . $body . '.' . JwtService::base64url($signature);
    }

    public function verifyIdTokenHint(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode(JwtService::base64urlDecode($encodedHeader), true);
        $payload = json_decode(JwtService::base64urlDecode($encodedPayload), true);
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'RS256') {
            return null;
        }

        $key = Yii::$app->db->createCommand(
            "SELECT public_key_pem FROM oidc_signing_keys WHERE kid = :kid AND status IN ('active', 'retiring')",
            [':kid' => (string)($header['kid'] ?? '')]
        )->queryOne();
        if (!$key) {
            return null;
        }

        $signature = JwtService::base64urlDecode($encodedSignature);
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $key['public_key_pem'],
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1) {
            return null;
        }

        if (($payload['iss'] ?? '') !== Yii::$app->params['issuer']) {
            return null;
        }
        if (empty($payload['sub']) || empty($payload['aud'])) {
            return null;
        }
        if (!empty($payload['iat']) && time() - (int)$payload['iat'] > self::MAX_LOGOUT_HINT_AGE) {
            return null;
        }

        return $payload;
    }

    private function activeKey(): array
    {
        $row = Yii::$app->db->createCommand("SELECT * FROM oidc_signing_keys WHERE status = 'active' ORDER BY created_at DESC LIMIT 1")->queryOne();
        if ($row) {
            return $row;
        }

        // При первом запуске создаём RSA-ключ, аналогично EnsureActiveKey в Go-сервисе.
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        $public = $details['key'];
        $kid = 'kid_' . bin2hex(random_bytes(8));

        Yii::$app->db->createCommand()->insert('oidc_signing_keys', [
            'kid' => $kid,
            'alg' => 'RS256',
            'private_key_pem' => $private,
            'public_key_pem' => $public,
            'status' => 'active',
        ])->execute();

        return Yii::$app->db->createCommand('SELECT * FROM oidc_signing_keys WHERE kid = :kid', [':kid' => $kid])->queryOne();
    }
}
