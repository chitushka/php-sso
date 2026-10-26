<?php

namespace tests\Unit;

use app\models\OAuthClient;
use app\services\OAuthRedirectPolicy;
use PHPUnit\Framework\TestCase;

final class OAuthRedirectPolicyTest extends TestCase
{
    public function testRequiresExactRegisteredRedirectForRegularClients(): void
    {
        $client = $this->client(['https://client.example.test/callback'], false);
        $policy = new OAuthRedirectPolicy();

        $this->assertTrue($policy->redirectUriAllowed($client, 'https://client.example.test/callback'));
        $this->assertFalse($policy->redirectUriAllowed($client, 'https://client.example.test/other'));
        $this->assertFalse($policy->redirectUriAllowed($client, 'https://CLIENT.example.test/callback'));
    }

    public function testAllowsOnlyLoopbackPortVariationForOptedInPublicClient(): void
    {
        $client = $this->client(['http://127.0.0.1:49152/callback'], true);
        $policy = new OAuthRedirectPolicy();

        $this->assertTrue($policy->redirectUriAllowed($client, 'http://127.0.0.1:54321/callback'));
        $this->assertFalse($policy->redirectUriAllowed($client, 'http://127.0.0.1:54321/other'));
        $this->assertFalse($policy->redirectUriAllowed($client, 'http://localhost:54321/callback'));
        $this->assertFalse($policy->redirectUriAllowed($client, 'http://192.168.1.10:54321/callback'));
    }

    public function testRejectsLoopbackOptInForConfidentialClient(): void
    {
        $client = $this->client(['http://127.0.0.1:49152/callback'], true);
        $client->type = OAuthClient::TYPE_CONFIDENTIAL;

        $this->assertFalse((new OAuthRedirectPolicy())->clientUrisAreSafe($client));
    }

    private function client(array $redirectUris, bool $allowLoopback): OAuthClient
    {
        $client = new PolicyOAuthClient();
        $client->type = OAuthClient::TYPE_PUBLIC;
        $client->redirect_uris = OAuthClient::toPgArray($redirectUris);
        $client->post_logout_redirect_uris = OAuthClient::toPgArray([]);
        $client->allow_loopback_redirects = $allowLoopback;

        return $client;
    }
}

final class PolicyOAuthClient extends OAuthClient
{
    public function attributes(): array
    {
        return [
            'type',
            'redirect_uris',
            'post_logout_redirect_uris',
            'allow_loopback_redirects',
        ];
    }
}
