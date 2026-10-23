<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/ci/OpenApiValidator.php';

final class OpenApiValidatorTest extends TestCase
{
    private OpenApiValidator $admin;
    private OpenApiValidator $oauth;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->admin = new OpenApiValidator($root . '/contracts/openapi-admin.json');
        $this->oauth = new OpenApiValidator($root . '/contracts/openapi-oauth.json');
    }

    public function testValidatesConcreteResponseAndRequestFixtures(): void
    {
        $this->admin->validateResponse('GET', '/api/v1/bootstrap/status', 200, ['initialized' => true]);
        $this->admin->validateRequest('POST', '/api/v1/auth/login', 'application/json', [
            'username' => 'admin',
            'password' => 'secret',
        ]);
        $this->oauth->validateResponse('GET', '/.well-known/jwks.json', 200, [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'key-1',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => 'modulus',
                'e' => 'AQAB',
            ]],
        ]);

        $this->addToAssertionCount(4);
    }

    public function testValidatesAccountRequestAndResponseFixtures(): void
    {
        $this->admin->validateRequest('POST', '/api/v1/auth/password/reset', 'application/json', [
            'token' => 'reset-token',
            'password' => 'new-password',
        ]);
        $this->admin->validateRequest('PUT', '/api/v1/auth/profile', 'application/json', [
            'first_name' => 'Ada',
            'attributes' => ['department' => 'security'],
        ]);
        $this->admin->validateResponse('POST', '/api/v1/auth/mfa/enroll', 200, [
            'secret' => 'BASE32SECRET',
            'otpauth_url' => 'otpauth://totp/SSO%3Aada?secret=BASE32SECRET',
        ]);
        $this->admin->validateResponse('GET', '/api/v1/auth/sessions', 200, [[
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'user_id' => '550e8400-e29b-41d4-a716-446655440001',
            'ip' => null,
            'user_agent' => 'browser',
            'expires_at' => '2026-10-24T12:00:00+00:00',
            'created_at' => '2026-10-23T12:00:00+00:00',
        ]]);
        $this->admin->validateResponse('GET', '/api/v1/auth/consents', 200, [[
            'client_id' => 'portal',
            'client_name' => 'Portal',
            'scopes' => ['openid', 'profile'],
            'granted_at' => '2026-10-22T12:00:00+00:00',
            'updated_at' => '2026-10-23T12:00:00+00:00',
        ]]);

        $this->addToAssertionCount(5);
    }

    /**
     * @dataProvider invalidAccountPayloads
     */
    public function testRejectsAccountContractDrift(
        string $kind,
        string $method,
        string $path,
        array $payload
    ): void
    {
        $this->expectException(RuntimeException::class);

        if ($kind === 'request') {
            $this->admin->validateRequest($method, $path, 'application/json', $payload);
            return;
        }
        $this->admin->validateResponse($method, $path, 200, $payload);
    }

    public function invalidAccountPayloads(): array
    {
        return [
            'missing reset token' => ['request', 'POST', '/api/v1/auth/password/reset', [
                'password' => 'new-password',
            ]],
            'profile secret field' => ['request', 'PUT', '/api/v1/auth/profile', [
                'first_name' => 'Ada',
                'password_hash' => 'secret',
            ]],
            'profile attribute object' => ['request', 'PUT', '/api/v1/auth/profile', [
                'attributes' => ['nested' => ['secret' => true]],
            ]],
            'MFA secret field' => ['response', 'POST', '/api/v1/auth/mfa/enroll', [
                'secret' => 'BASE32SECRET',
                'otpauth_url' => 'otpauth://totp/SSO%3Aada?secret=BASE32SECRET',
                'recovery_codes' => ['must-not-leak'],
            ]],
            'session token hash' => ['response', 'GET', '/api/v1/auth/sessions', [[
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'user_id' => '550e8400-e29b-41d4-a716-446655440001',
                'ip' => null,
                'user_agent' => null,
                'expires_at' => '2026-10-24T12:00:00+00:00',
                'created_at' => '2026-10-23T12:00:00+00:00',
                'token_hash' => 'secret',
            ]]],
            'consent missing scopes' => ['response', 'GET', '/api/v1/auth/consents', [[
                'client_id' => 'portal',
                'client_name' => 'Portal',
                'granted_at' => '2026-10-22T12:00:00+00:00',
                'updated_at' => '2026-10-23T12:00:00+00:00',
            ]]],
        ];
    }

    /**
     * @dataProvider invalidBootstrapResponses
     */
    public function testRejectsResponseFieldDrift(array $payload): void
    {
        $this->expectException(RuntimeException::class);
        $this->admin->validateResponse('GET', '/api/v1/bootstrap/status', 200, $payload);
    }

    public function invalidBootstrapResponses(): array
    {
        return [
            'renamed field' => [['is_initialized' => true]],
            'wrong type' => [['initialized' => 'true']],
            'secret field' => [['initialized' => true, 'encryption_key' => 'secret']],
        ];
    }

    public function testRejectsUndeclaredStatusCode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->admin->validateResponse('GET', '/api/v1/bootstrap/status', 201, ['initialized' => true]);
    }
}
