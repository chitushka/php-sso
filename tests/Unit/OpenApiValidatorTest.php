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
