<?php

namespace app\services;

use app\models\User;

class OidcService
{
    private const MAX_LOGOUT_HINT_AGE = 86400;
    private const KEY_MAX_AGE_DAYS = 30;
    private const RETIRING_GRACE_HOURS = 24;
    private const KEY_ROTATION_LOCK = 1397968720;
    private const CLOCK_SKEW = 60;

    public function listSigningKeys(): array
    {
        $rows = ApplicationRuntime::db()->createCommand(
            'SELECT kid, alg, status, created_at, expires_at
             FROM oidc_signing_keys
             ORDER BY CASE status WHEN \'active\' THEN 0 WHEN \'retiring\' THEN 1 ELSE 2 END,
                      created_at DESC,
                      kid ASC'
        )->queryAll();

        return array_map([$this, 'publicSigningKey'], $rows);
    }

    public function rotateSigningKey(?string $actorUserId = null): array
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            ApplicationRuntime::db()->createCommand(
                'SELECT pg_advisory_xact_lock(:lock_id)',
                [':lock_id' => self::KEY_ROTATION_LOCK]
            )->queryScalar();
            $this->retireExpiredKeys();

            $retiringSeconds = max(
                self::RETIRING_GRACE_HOURS * 3600,
                (int)ApplicationRuntime::param('accessTokenTtl') + self::CLOCK_SKEW
            );
            ApplicationRuntime::db()->createCommand(
                "UPDATE oidc_signing_keys
                 SET status = 'retiring', expires_at = now() + make_interval(secs => :seconds)
                 WHERE status = 'active'",
                [':seconds' => $retiringSeconds]
            )->execute();

            $key = $this->createActiveKey();
            AuditService::record($actorUserId, 'signing_key_rotated', 'signing_key', (string)$key['kid']);
            $transaction->commit();

            return $this->publicSigningKey($key);
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    public function configuration(): array
    {
        $issuer = ApplicationRuntime::param('issuer');

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
            'backchannel_logout_supported' => true,
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    public function jwks(): array
    {
        $this->activeKey();
        $keys = ApplicationRuntime::db()->createCommand(
            "SELECT kid, public_key_pem FROM oidc_signing_keys
             WHERE status IN ('active', 'retiring')
             ORDER BY created_at DESC"
        )->queryAll();

        return [
            'keys' => array_map(function (array $key): array {
                $details = openssl_pkey_get_details(openssl_pkey_get_public($key['public_key_pem']));

                return [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'kid' => $key['kid'],
                    'alg' => 'RS256',
                    'n' => JwtService::base64url($details['rsa']['n']),
                    'e' => JwtService::base64url($details['rsa']['e']),
                ];
            }, $keys),
        ];
    }

    public function signingReady(): bool
    {
        try {
            $key = $this->activeKey();
            $payload = random_bytes(32);
            $signature = '';
            if (!openssl_sign($payload, $signature, $this->privateKey($key), OPENSSL_ALGO_SHA256)) {
                return false;
            }

            return openssl_verify(
                $payload,
                $signature,
                (string)$key['public_key_pem'],
                OPENSSL_ALGO_SHA256
            ) === 1;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function issueIdToken(
        string $userId,
        string $audience,
        string $nonce = '',
        ?int $authTime = null
    ): string {
        $key = $this->activeKey();
        $user = User::findOne(['id' => $userId]);
        $now = time();
        $payload = [
            'iss' => ApplicationRuntime::param('issuer'),
            'sub' => $userId,
            'aud' => $audience,
            'azp' => $audience,
            'iat' => $now,
            'exp' => $now + (int)ApplicationRuntime::param('accessTokenTtl'),
            'auth_time' => $authTime ?? $now,
            'preferred_username' => $user ? $user->username : '',
            'email' => $user ? $user->email : '',
        ];
        if ($nonce !== '') {
            $payload['nonce'] = $nonce;
        }

        $header = JwtService::base64url(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key['kid']]));
        $body = JwtService::base64url(json_encode($payload));
        if (!openssl_sign($header . '.' . $body, $signature, $this->privateKey($key), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('failed to sign id token');
        }

        return $header . '.' . $body . '.' . JwtService::base64url($signature);
    }

    public function issueLogoutToken(string $subject, string $clientId): string
    {
        $key = $this->activeKey();
        $now = time();
        $payload = [
            'iss' => ApplicationRuntime::param('issuer'),
            'sub' => $subject,
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => (string)ApplicationRuntime::db()->createCommand('SELECT gen_random_uuid()')->queryScalar(),
            'events' => [
                'http://schemas.openid.net/event/backchannel-logout' => new \stdClass(),
            ],
        ];

        $header = JwtService::base64url(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key['kid']]));
        $body = JwtService::base64url(json_encode($payload));
        if (!openssl_sign($header . '.' . $body, $signature, $this->privateKey($key), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('failed to sign logout token');
        }

        return $header . '.' . $body . '.' . JwtService::base64url($signature);
    }

    public function sendBackchannelLogout(string $subject, string $clientId, string $uri): void
    {
        $body = http_build_query([
            'logout_token' => $this->issueLogoutToken($subject, $clientId),
        ]);
        // Ошибка уведомления RP не должна отменять локальный logout.
        (new OutboundHttpService())->request(
            $uri,
            'POST',
            $body,
            ['Content-Type: application/x-www-form-urlencoded']
        );
    }

    public function verifyIdTokenHint(string $token): ?array
    {
        $this->retireExpiredKeys();

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

        $key = ApplicationRuntime::db()->createCommand(
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

        if (($payload['iss'] ?? '') !== ApplicationRuntime::param('issuer')) {
            return null;
        }
        if (empty($payload['sub']) || !is_string($payload['aud']) || $payload['aud'] === '') {
            return null;
        }
        if (isset($payload['azp']) && (!is_string($payload['azp']) || $payload['azp'] !== $payload['aud'])) {
            return null;
        }
        $now = time();
        if (
            !isset($payload['exp']) || !is_numeric($payload['exp'])
            || (int)$payload['exp'] < $now - self::CLOCK_SKEW
        ) {
            return null;
        }
        if (
            !isset($payload['iat']) || !is_numeric($payload['iat'])
            || (int)$payload['iat'] > $now + self::CLOCK_SKEW
            || $now - (int)$payload['iat'] > self::MAX_LOGOUT_HINT_AGE
        ) {
            return null;
        }

        return $payload;
    }

    private function activeKey(): array
    {
        $transaction = ApplicationRuntime::db()->beginTransaction();
        try {
            // Advisory lock не даёт нескольким PHP workers одновременно создать active key.
            ApplicationRuntime::db()->createCommand(
                'SELECT pg_advisory_xact_lock(:lock_id)',
                [':lock_id' => self::KEY_ROTATION_LOCK]
            )->queryScalar();
            $this->retireExpiredKeys();

            $row = ApplicationRuntime::db()->createCommand(
                "SELECT * FROM oidc_signing_keys
                 WHERE status = 'active'
                   AND created_at > now() - interval '" . self::KEY_MAX_AGE_DAYS . " days'
                 ORDER BY created_at DESC LIMIT 1"
            )->queryOne();
            if ($row) {
                $transaction->commit();
                return $row;
            }

            // Старый ключ остаётся в JWKS на время жизни уже выданных токенов.
            $retiringSeconds = max(
                self::RETIRING_GRACE_HOURS * 3600,
                (int)ApplicationRuntime::param('accessTokenTtl') + self::CLOCK_SKEW
            );
            ApplicationRuntime::db()->createCommand(
                "UPDATE oidc_signing_keys
                 SET status = 'retiring', expires_at = now() + make_interval(secs => :seconds)
                 WHERE status = 'active'",
                [':seconds' => $retiringSeconds]
            )->execute();

            $row = $this->createActiveKey();
            $transaction->commit();
            return $row;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $exception;
        }
    }

    private function retireExpiredKeys(): void
    {
        ApplicationRuntime::db()->createCommand(
            "UPDATE oidc_signing_keys
             SET status = 'retired'
             WHERE status = 'retiring' AND expires_at IS NOT NULL AND expires_at < now()"
        )->execute();
    }

    private function createActiveKey(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false || !openssl_pkey_export($resource, $private)) {
            throw new \RuntimeException('failed to generate OIDC signing key');
        }
        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || empty($details['key'])) {
            throw new \RuntimeException('failed to read OIDC signing key');
        }
        $kid = (string)ApplicationRuntime::db()->createCommand('SELECT gen_random_uuid()')->queryScalar();

        ApplicationRuntime::db()->createCommand()->insert('oidc_signing_keys', [
            'kid' => $kid,
            'alg' => 'RS256',
            'private_key_pem' => (new CryptoService())->encrypt($private),
            'public_key_pem' => $details['key'],
            'status' => 'active',
        ])->execute();

        return ApplicationRuntime::db()->createCommand(
            'SELECT * FROM oidc_signing_keys WHERE kid = :kid',
            [':kid' => $kid]
        )->queryOne();
    }

    private function publicSigningKey(array $key): array
    {
        return [
            'kid' => (string)$key['kid'],
            'alg' => (string)$key['alg'],
            'status' => (string)$key['status'],
            'created_at' => \app\components\Rfc3339::format($key['created_at']),
            'expires_at' => empty($key['expires_at'])
                ? null
                : \app\components\Rfc3339::format($key['expires_at']),
        ];
    }

    private function privateKey(array $key): string
    {
        $stored = (string)($key['private_key_pem'] ?? '');
        if (strpos($stored, 'enc:v1:') !== 0) {
            throw new \RuntimeException('OIDC private key is not encrypted');
        }

        return (new CryptoService())->decrypt($stored);
    }
}
