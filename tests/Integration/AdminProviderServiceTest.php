<?php

namespace tests\Integration;

use app\models\Group;
use app\models\IdentityProvider;
use app\models\LdapProvider;
use app\services\BrokerService;
use app\services\CryptoService;
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
        ]);
        $this->assertSame(201, $created['status']);
        $this->assertArrayNotHasKey('client_secret', $created['data']);
        $this->assertSame(strtolower($code), $created['data']['code']);
        $this->identityProviderIds[] = $created['data']['id'];

        $provider = IdentityProvider::findOne(['id' => $created['data']['id']]);
        $this->assertSame('super-secret', (new CryptoService())->decrypt((string)$provider->client_secret));

        $public = $service->publicProviders();
        $this->assertContains(strtolower($code), array_column($public, 'code'));
        $loginUrl = $service->start(strtolower($code), 'https://evil.example.test/path');
        $this->assertStringStartsWith('https://idp.example.test/authorize?', $loginUrl);
        $this->assertStringContainsString('redirect_uri=', $loginUrl);

        $updated = $service->updateProvider($created['data']['id'], [
            'name' => 'PHPUnit IdP Updated',
            'client_secret' => '',
        ]);
        $this->assertSame(200, $updated['status']);
        $provider->refresh();
        $this->assertSame('super-secret', (new CryptoService())->decrypt((string)$provider->client_secret));

        $deleted = $service->deleteProvider($created['data']['id']);
        $this->assertSame(200, $deleted['status']);
        $this->identityProviderIds = [];
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
        $this->ldapProviderIds[] = $created['data']['id'];

        $provider = LdapProvider::findOne(['id' => $created['data']['id']]);
        $this->assertSame('ldap-secret', (new CryptoService())->decrypt((string)$provider->bind_password));

        $updated = $service->updateProvider($created['data']['id'], [
            'name' => 'PHPUnit LDAP Updated',
            'bind_password' => '',
        ], null);
        $this->assertSame(200, $updated['status']);
        $provider->refresh();
        $this->assertSame('ldap-secret', (new CryptoService())->decrypt((string)$provider->bind_password));

        $mapping = $service->createGroupMapping($created['data']['id'], [
            'ldap_group' => 'cn=admins,dc=example,dc=test',
            'group_id' => $this->groupId,
        ], null);
        $this->assertSame(201, $mapping['status']);
        $this->assertCount(1, $service->listGroupMappings($created['data']['id']));
        $this->assertTrue($service->deleteGroupMapping($mapping['data']['id'], null));
        $this->assertCount(0, $service->listGroupMappings($created['data']['id']));

        $this->assertTrue($service->deleteProvider($created['data']['id'], null));
        $this->ldapProviderIds = [];
        $this->assertNull(LdapProvider::findOne(['id' => $created['data']['id']]));
    }
}
