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

    public function testAdminContractHasNoGenericPayloadSchemas(): void
    {
        $contract = (string)file_get_contents(dirname(__DIR__, 2) . '/contracts/openapi-admin.json');

        foreach (['GenericObject', 'JsonObject', 'JsonSuccess', 'JsonArray', 'JsonPage'] as $name) {
            $this->assertStringNotContainsString('"' . $name . '"', $contract);
        }
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

    public function testValidatesUserAndRbacFixtures(): void
    {
        $this->admin->validateRequest('POST', '/api/v1/users', 'application/json', [
            'username' => 'operator',
            'email' => 'operator@example.org',
            'password' => 'strong-password',
            'attributes' => ['department' => 'security'],
        ]);
        $this->admin->validateRequest('POST', '/api/v1/roles', 'application/json', [
            'code' => 'auditor',
            'name' => 'Auditor',
        ]);
        $this->admin->validateRequest(
            'POST',
            '/api/v1/roles/{roleID}/permissions',
            'application/json',
            ['permission_id' => '550e8400-e29b-41d4-a716-446655440002']
        );
        $this->admin->validateResponse('GET', '/api/v1/roles', 200, [
            'items' => [[
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'code' => 'auditor',
                'name' => 'Auditor',
                'created_at' => '2026-10-22T12:00:00+00:00',
                'updated_at' => '2026-10-23T12:00:00+00:00',
            ]],
            'pagination' => ['limit' => 50, 'offset' => 0, 'count' => 1],
        ]);
        $this->admin->validateResponse(
            'GET',
            '/api/v1/users/{userID}/federated-identities',
            200,
            [[
                'provider_id' => '550e8400-e29b-41d4-a716-446655440003',
                'provider_code' => 'corporate',
                'provider_name' => 'Corporate IdP',
                'external_subject' => 'subject-1',
                'email' => 'operator@example.org',
                'created_at' => '2026-10-23T12:00:00+00:00',
            ]]
        );

        $this->addToAssertionCount(5);
    }

    /**
     * @dataProvider invalidUserAndRbacPayloads
     */
    public function testRejectsUserAndRbacContractDrift(
        string $kind,
        string $method,
        string $path,
        int $status,
        array $payload
    ): void
    {
        $this->expectException(RuntimeException::class);

        if ($kind === 'request') {
            $this->admin->validateRequest($method, $path, 'application/json', $payload);
            return;
        }
        $this->admin->validateResponse($method, $path, $status, $payload);
    }

    public function invalidUserAndRbacPayloads(): array
    {
        return [
            'user create missing password' => ['request', 'POST', '/api/v1/users', 0, [
                'username' => 'operator',
                'email' => 'operator@example.org',
            ]],
            'user create secret field' => ['request', 'POST', '/api/v1/users', 0, [
                'username' => 'operator',
                'email' => 'operator@example.org',
                'password' => 'strong-password',
                'password_hash' => 'secret',
            ]],
            'role create missing code' => ['request', 'POST', '/api/v1/roles', 0, [
                'name' => 'Auditor',
            ]],
            'wrong assignment field' => [
                'request',
                'POST',
                '/api/v1/roles/{roleID}/permissions',
                0,
                ['role_id' => '550e8400-e29b-41d4-a716-446655440002'],
            ],
            'role page secret field' => ['response', 'GET', '/api/v1/roles', 200, [
                'items' => [[
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'code' => 'auditor',
                    'name' => 'Auditor',
                    'created_at' => '2026-10-22T12:00:00+00:00',
                    'updated_at' => '2026-10-23T12:00:00+00:00',
                    'internal' => true,
                ]],
                'pagination' => ['limit' => 50, 'offset' => 0, 'count' => 1],
            ]],
            'federated identity missing provider' => [
                'response',
                'GET',
                '/api/v1/users/{userID}/federated-identities',
                200,
                [[
                    'provider_code' => 'corporate',
                    'provider_name' => 'Corporate IdP',
                    'external_subject' => 'subject-1',
                    'email' => 'operator@example.org',
                    'created_at' => '2026-10-23T12:00:00+00:00',
                ]],
            ],
        ];
    }

    public function testValidatesProviderAndOAuthClientFixtures(): void
    {
        $this->admin->validateRequest('POST', '/api/v1/ldap/providers', 'application/json', [
            'name' => 'Corporate LDAP',
            'host' => 'ldap.example.org',
            'bind_dn' => 'cn=service,dc=example,dc=org',
            'bind_password' => 'secret',
            'base_dn' => 'dc=example,dc=org',
        ]);
        $this->admin->validateRequest('POST', '/api/v1/identity-providers', 'application/json', [
            'code' => 'corporate',
            'name' => 'Corporate IdP',
            'client_id' => 'sso',
            'authorize_url' => 'https://idp.example.org/authorize',
            'token_url' => 'https://idp.example.org/token',
            'userinfo_url' => 'https://idp.example.org/userinfo',
        ]);
        $this->admin->validateRequest('POST', '/api/v1/oauth/clients', 'application/json', [
            'client_id' => 'portal',
            'name' => 'Portal',
            'type' => 'public',
            'redirect_uris' => ['https://portal.example.org/callback'],
        ]);
        $this->admin->validateResponse('GET', '/api/v1/ldap/providers', 200, [
            'items' => [$this->ldapProviderFixture()],
            'pagination' => ['limit' => 50, 'offset' => 0, 'count' => 1],
        ]);
        $this->admin->validateResponse('GET', '/api/v1/identity-providers', 200, [
            'items' => [$this->identityProviderFixture()],
            'pagination' => ['limit' => 50, 'offset' => 0, 'count' => 1],
        ]);
        $this->admin->validateResponse('POST', '/api/v1/oauth/clients', 201, [
            'client' => $this->oauthClientFixture('public'),
        ]);
        $rotated = $this->oauthClientFixture('confidential');
        $rotated['client_secret'] = 'one-time-secret';
        $this->admin->validateResponse('POST', '/api/v1/oauth/clients/{id}/rotate-secret', 200, $rotated);

        $this->addToAssertionCount(7);
    }

    /**
     * @dataProvider invalidProviderAndOAuthClientPayloads
     */
    public function testRejectsProviderAndOAuthClientContractDrift(
        string $kind,
        string $method,
        string $path,
        int $status,
        array $payload
    ): void
    {
        $this->expectException(RuntimeException::class);

        if ($kind === 'request') {
            $this->admin->validateRequest($method, $path, 'application/json', $payload);
            return;
        }
        $this->admin->validateResponse($method, $path, $status, $payload);
    }

    public function invalidProviderAndOAuthClientPayloads(): array
    {
        $ldap = $this->ldapProviderFixture();
        $ldap['bind_password'] = 'must-not-leak';
        $identityProvider = $this->identityProviderFixture();
        $identityProvider['client_secret'] = 'must-not-leak';
        $client = $this->oauthClientFixture('confidential');
        $client['client_secret_hash'] = 'must-not-leak';

        return [
            'LDAP create missing secret' => ['request', 'POST', '/api/v1/ldap/providers', 0, [
                'name' => 'Corporate LDAP',
                'host' => 'ldap.example.org',
                'bind_dn' => 'cn=service,dc=example,dc=org',
                'base_dn' => 'dc=example,dc=org',
            ]],
            'IdP create missing endpoint' => ['request', 'POST', '/api/v1/identity-providers', 0, [
                'code' => 'corporate',
                'name' => 'Corporate IdP',
                'client_id' => 'sso',
                'authorize_url' => 'https://idp.example.org/authorize',
                'token_url' => 'https://idp.example.org/token',
            ]],
            'OAuth client create missing name' => ['request', 'POST', '/api/v1/oauth/clients', 0, [
                'client_id' => 'portal',
            ]],
            'LDAP bind password leak' => ['response', 'POST', '/api/v1/ldap/providers', 201, $ldap],
            'IdP client secret leak' => [
                'response',
                'POST',
                '/api/v1/identity-providers',
                201,
                $identityProvider,
            ],
            'OAuth client hash leak' => ['response', 'GET', '/api/v1/oauth/clients/{id}', 200, $client],
            'rotated secret missing' => [
                'response',
                'POST',
                '/api/v1/oauth/clients/{id}/rotate-secret',
                200,
                $this->oauthClientFixture('confidential'),
            ],
        ];
    }

    private function ldapProviderFixture(): array
    {
        return [
            'id' => '550e8400-e29b-41d4-a716-446655440010',
            'name' => 'Corporate LDAP',
            'host' => 'ldap.example.org',
            'port' => 389,
            'use_tls' => false,
            'start_tls' => true,
            'bind_dn' => 'cn=service,dc=example,dc=org',
            'base_dn' => 'dc=example,dc=org',
            'user_filter' => '(uid={username})',
            'username_attribute' => 'uid',
            'email_attribute' => 'mail',
            'display_name_attribute' => 'displayName',
            'group_attribute' => 'memberOf',
            'enabled' => true,
            'created_at' => '2026-10-22T12:00:00+00:00',
            'updated_at' => '2026-10-23T12:00:00+00:00',
        ];
    }

    private function identityProviderFixture(): array
    {
        return [
            'id' => '550e8400-e29b-41d4-a716-446655440011',
            'code' => 'corporate',
            'name' => 'Corporate IdP',
            'type' => 'oidc',
            'client_id' => 'sso',
            'authorize_url' => 'https://idp.example.org/authorize',
            'token_url' => 'https://idp.example.org/token',
            'userinfo_url' => 'https://idp.example.org/userinfo',
            'scopes' => 'openid profile email',
            'enabled' => true,
            'created_at' => '2026-10-22T12:00:00+00:00',
            'updated_at' => '2026-10-23T12:00:00+00:00',
        ];
    }

    private function oauthClientFixture(string $type): array
    {
        return [
            'id' => '550e8400-e29b-41d4-a716-446655440012',
            'client_id' => 'portal',
            'name' => 'Portal',
            'type' => $type,
            'redirect_uris' => ['https://portal.example.org/callback'],
            'allowed_scopes' => ['openid', 'profile'],
            'post_logout_redirect_uris' => ['https://portal.example.org/logout'],
            'backchannel_logout_uri' => '',
            'skip_consent' => false,
            'allow_loopback_redirects' => false,
            'enabled' => true,
            'created_at' => '2026-10-22T12:00:00+00:00',
            'updated_at' => '2026-10-23T12:00:00+00:00',
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
