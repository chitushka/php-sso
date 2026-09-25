<?php

namespace tests\Integration;

use app\models\Group;
use app\models\IdentityProvider;
use app\models\LdapProvider;
use app\services\BrokerService;
use app\services\CryptoService;
use app\services\JwtService;
use app\services\LdapService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class AdminProviderServiceTest extends TestCase
{
    private array $identityProviderIds = [];
    private array $ldapProviderIds = [];
    private ?string $groupId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }
        Yii::$app->params = array_merge(require dirname(__DIR__, 2) . '/config/params.php', Yii::$app->params);
    }

    protected function tearDown(): void
    {
        if ($this->groupId !== null) {
            Yii::$app->db->createCommand()->delete('groups', ['id' => $this->groupId])->execute();
        }
        if (!empty($this->identityProviderIds)) {
            Yii::$app->db->createCommand()->delete('identity_providers', ['id' => $this->identityProviderIds])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['target_type' => 'identity_provider', 'target_id' => $this->identityProviderIds])->execute();
        }
        if (!empty($this->ldapProviderIds)) {
            Yii::$app->db->createCommand()->delete('ldap_providers', ['id' => $this->ldapProviderIds])->execute();
            Yii::$app->db->createCommand()->delete('audit_logs', ['target_type' => 'ldap_provider', 'target_id' => $this->ldapProviderIds])->execute();
        }

        parent::tearDown();
    }

    public function testIdentityProviderCrudHidesAndPreservesClientSecret(): void
    {
        $service = new BrokerService();
        $code = 'phpunit-idp-' . bin2hex(random_bytes(4));

        $missing = $service->createProvider(['code' => $code, 'client_id' => 'client']);
        $this->assertSame(400, $missing['status']);
        $this->assertSame('authorize_url, token_url and userinfo_url are required for type oidc', $missing['data']['error']);

        $created = $service->createProvider([
            'code' => strtoupper($code),
            'name' => 'PHPUnit IdP',
            'type' => 'oidc',
            'client_id' => 'client-' . $code,
            'client_secret' => 'super-secret',
            'authorize_url' => 'https://idp.example.test/authorize',
            'token_url' => 'https://idp.example.test/token',
            'userinfo_url' => 'https://idp.example.test/userinfo',
            'scopes' => 'openid email',
            'enabled' => true,
        ]);
        $this->assertSame(201, $created['status']);
        $this->assertArrayNotHasKey('client_secret', $created['data']);
        $this->assertSame(strtolower($code), $created['data']['code']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $created['data']['created_at']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $created['data']['updated_at']
        );
        $this->identityProviderIds[] = $created['data']['id'];

        $provider = IdentityProvider::findOne(['id' => $created['data']['id']]);
        $this->assertSame('super-secret', (new CryptoService())->decrypt((string)$provider->client_secret));

        $public = $service->publicProviders();
        $this->assertContains(strtolower($code), array_column($public, 'code'));
        $loginUrl = $service->start(strtolower($code), 'https://evil.example.test/path');
        $this->assertStringStartsWith('https://idp.example.test/authorize?', $loginUrl);
        $this->assertStringContainsString('redirect_uri=', $loginUrl);

        Yii::$app->db->createCommand()->update(
            'identity_providers',
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $created['data']['id']]
        )->execute();

        $updated = $service->updateProvider($created['data']['id'], [
            'name' => 'PHPUnit IdP Updated',
            'client_secret' => '',
        ]);
        $this->assertSame(200, $updated['status']);
        $this->assertGreaterThan(strtotime('2020-01-01T00:00:00Z'), strtotime($updated['data']['updated_at']));
        $this->assertSame('', $updated['data']['type']);
        $this->assertSame('', $updated['data']['client_id']);
        $this->assertSame('', $updated['data']['authorize_url']);
        $this->assertSame('', $updated['data']['token_url']);
        $this->assertSame('', $updated['data']['userinfo_url']);
        $this->assertSame('', $updated['data']['scopes']);
        $this->assertFalse($updated['data']['enabled']);
        $provider->refresh();
        $this->assertSame('super-secret', (new CryptoService())->decrypt((string)$provider->client_secret));

        $deleted = $service->deleteProvider($created['data']['id']);
        $this->assertSame(200, $deleted['status']);
        $this->identityProviderIds = [];
    }

    public function testBrokerStateMatchesGoWireContract(): void
    {
        $jwtSecret = 'phpunit-jwt-secret-32-characters-long';
        $encryptionKey = 'different-encryption-key-32-chars';
        $originalJwtSecret = Yii::$app->params['jwtSecret'];
        $originalEncryptionKey = Yii::$app->params['encryptionKey'];
        Yii::$app->params['jwtSecret'] = $jwtSecret;
        Yii::$app->params['encryptionKey'] = $encryptionKey;

        try {
            $service = new BrokerService();
            $signState = new \ReflectionMethod($service, 'signState');
            $signState->setAccessible(true);
            $verifyState = new \ReflectionMethod($service, 'verifyState');
            $verifyState->setAccessible(true);

            $state = [
                'p' => 'google',
                'n' => 'nonce',
                'c' => '/users',
                'e' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
            ];
            $signed = $signState->invoke($service, $state);
            [$encodedPayload, $encodedSignature] = explode('.', $signed, 2);
            $payload = JwtService::base64urlDecode($encodedPayload);
            $signature = JwtService::base64urlDecode($encodedSignature);

            $this->assertSame($state, json_decode($payload, true));
            $this->assertTrue(hash_equals(hash_hmac('sha256', $payload, $jwtSecret, true), $signature));
            $this->assertFalse(hash_equals(hash_hmac('sha256', $payload, $encryptionKey, true), $signature));
            $this->assertSame($state, $verifyState->invoke($service, $signed));

            $expiredState = $state;
            $expiredState['e'] = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
            $this->assertNull($verifyState->invoke($service, $this->goSignedState($expiredState, $jwtSecret)));

            $legacyState = $this->goSignedState($state, $encryptionKey);
            $this->assertNull($verifyState->invoke($service, $legacyState));
            $this->assertNull($verifyState->invoke($service, $encodedPayload . '=.' . $encodedSignature));
        } finally {
            Yii::$app->params['jwtSecret'] = $originalJwtSecret;
            Yii::$app->params['encryptionKey'] = $originalEncryptionKey;
        }
    }

    public function testLdapProviderCrudHidesPasswordAndManagesGroupMappings(): void
    {
        $service = new LdapService();
        $suffix = bin2hex(random_bytes(4));

        $this->groupId = Yii::$app->db->createCommand(
            "INSERT INTO groups(code,name,description) VALUES (:code,'PHPUnit LDAP Group','') RETURNING id",
            [':code' => 'phpunit_ldap_group_' . $suffix]
        )->queryScalar();

        $created = $service->createProvider([
            'name' => 'PHPUnit LDAP',
            'host' => 'ldap.example.test',
            'port' => 389,
            'bind_dn' => 'cn=service,dc=example,dc=test',
            'bind_password' => 'ldap-secret',
            'base_dn' => 'dc=example,dc=test',
            'enabled' => true,
        ], null);
        $this->assertSame(201, $created['status']);
        $this->assertArrayNotHasKey('bind_password', $created['data']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $created['data']['created_at']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $created['data']['updated_at']
        );
        $this->ldapProviderIds[] = $created['data']['id'];

        $provider = LdapProvider::findOne(['id' => $created['data']['id']]);
        $this->assertSame('ldap-secret', (new CryptoService())->decrypt((string)$provider->bind_password));

        Yii::$app->db->createCommand()->update(
            'ldap_providers',
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $created['data']['id']]
        )->execute();

        $updated = $service->updateProvider($created['data']['id'], [
            'name' => 'PHPUnit LDAP Updated',
            'port' => 389,
            'bind_password' => '',
        ], null);
        $this->assertSame(200, $updated['status']);
        $this->assertGreaterThan(strtotime('2020-01-01T00:00:00Z'), strtotime($updated['data']['updated_at']));
        foreach (['host', 'bind_dn', 'base_dn', 'user_filter', 'username_attribute', 'email_attribute', 'display_name_attribute', 'group_attribute'] as $field) {
            $this->assertSame('', $updated['data'][$field]);
        }
        $this->assertFalse($updated['data']['use_tls']);
        $this->assertFalse($updated['data']['start_tls']);
        $this->assertFalse($updated['data']['enabled']);
        $provider->refresh();
        $this->assertSame('ldap-secret', (new CryptoService())->decrypt((string)$provider->bind_password));

        $mapping = $service->createGroupMapping($created['data']['id'], [
            'ldap_group' => 'cn=admins,dc=example,dc=test',
            'group_id' => $this->groupId,
        ], null);
        $this->assertSame(201, $mapping['status']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $mapping['data']['created_at']
        );
        $mappings = $service->listGroupMappings($created['data']['id']);
        $this->assertCount(1, $mappings);
        $this->assertSame($mapping['data']['created_at'], $mappings[0]['created_at']);
        $this->assertTrue($service->deleteGroupMapping($mapping['data']['id'], null));
        $this->assertCount(0, $service->listGroupMappings($created['data']['id']));

        $this->assertTrue($service->deleteProvider($created['data']['id'], null));
        $this->ldapProviderIds = [];
        $this->assertNull(LdapProvider::findOne(['id' => $created['data']['id']]));
    }

    private function goSignedState(array $state, string $secret): string
    {
        $payload = json_encode($state);

        return JwtService::base64url($payload) . '.' . JwtService::base64url(
            hash_hmac('sha256', $payload, $secret, true)
        );
    }
}
