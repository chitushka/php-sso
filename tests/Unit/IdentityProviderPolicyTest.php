<?php

namespace tests\Unit;

use app\models\IdentityProvider;
use app\services\IdentityProviderPolicy;
use PHPUnit\Framework\TestCase;

final class IdentityProviderPolicyTest extends TestCase
{
    public function testOnlyExplicitSafeOidcEndpointsAreAccepted(): void
    {
        $provider = new PolicyIdentityProvider();
        $provider->type = IdentityProvider::TYPE_OIDC;
        $provider->enabled = true;
        $provider->authorize_url = 'https://idp.example.test/authorize#login';
        $provider->token_url = 'https://idp.example.test/token';
        $provider->userinfo_url = 'https://idp.example.test/userinfo';
        $policy = new IdentityProviderPolicy();

        $this->assertSame([IdentityProvider::TYPE_OIDC], IdentityProvider::SUPPORTED_TYPES);
        $this->assertTrue($policy->endpointsAreSafe($provider));

        $provider->token_url = 'https://idp.example.test/token#fragment';
        $this->assertFalse($policy->endpointsAreSafe($provider));
    }

    public function testDisabledProviderStillRequiresExplicitEndpoints(): void
    {
        $provider = new PolicyIdentityProvider();
        $provider->type = IdentityProvider::TYPE_OIDC;
        $provider->enabled = false;
        $provider->authorize_url = '';
        $provider->token_url = '';
        $provider->userinfo_url = '';

        $this->assertFalse((new IdentityProviderPolicy())->endpointsAreSafe($provider));
    }
}

final class PolicyIdentityProvider extends IdentityProvider
{
    public function attributes(): array
    {
        return ['type', 'enabled', 'authorize_url', 'token_url', 'userinfo_url'];
    }
}
