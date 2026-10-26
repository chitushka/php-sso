<?php

namespace tests\Integration;

use app\models\Group;
use app\models\IdentityProvider;
use app\models\LdapGroupMapping;
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

        foreach (['google', 'github'] as $unsupportedType) {
            $unsupported = $service->createProvider([
                'code' => $unsupportedType . '-' . $code,
                'name' => 'Unsupported provider',
                'type' => $unsupportedType,
                'client_id' => 'client-' . $unsupportedType,
                'authorize_url' => 'https://idp.example.test/authorize',
                'token_url' => 'https://idp.example.test/token',
                'userinfo_url' => 'https://idp.example.test/userinfo',
            ]);
            $this->assertSame(400, $unsupported['status']);
            $this->assertSame('unsupported identity provider type', $unsupported['data']['error']);
        }

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

        $emptyEndpoints = $service->updateProvider($created['data']['id'], [
            'enabled' => false,
            'authorize_url' => '',
            'token_url' => '',
            'userinfo_url' => '',
        ]);
        $this->assertSame(400, $emptyEndpoints['status']);
        $this->assertSame('invalid_provider_endpoint', $emptyEndpoints->error?->code);

        $public = $service->publicProviders();
        $this->assertContains(strtolower($code), array_column($public, 'code'));
        $browserContext = str_repeat('a', 43);
        $loginResult = $service->start(
            strtolower($code),
            'https://evil.example.test/path',
            $browserContext
        );
        $this->assertTrue($loginResult->isSuccess());
        $loginUrl = $loginResult->data['redirect'];
        $this->assertStringStartsWith('https://idp.example.test/authorize?', $loginUrl);
        $this->assertStringContainsString('redirect_uri=', $loginUrl);
        $loginParts = parse_url($loginUrl);
        parse_str($loginParts['query'] ?? '', $loginQuery);
        $verifyState = new \ReflectionMethod($service, 'verifyState');
        $this->assertSame('/', $verifyState->invoke($service, $loginQuery['state'])['c']);
        $this->assertFalse($service->consumeRejectedCallback(
            strtolower($code),
            $loginQuery['state'],
            str_repeat('b', 43)
        ));
        $this->assertSame(
            ['0', '1'],
            $this->consumeStateConcurrently(strtolower($code), $loginQuery['state'], $browserContext)
        );

        $localContinue = new \ReflectionMethod($service, 'localContinueUrl');
        $this->assertSame('/', $localContinue->invoke($service, '//evil.example.test/path'));
        $this->assertSame('/', $localContinue->invoke($service, '/\\evil.example.test/path'));
        $this->assertSame('/', $localContinue->invoke($service, '/%2f%2fevil.example.test/path'));
        $this->assertSame('/', $localContinue->invoke($service, '/%5cevil.example.test/path'));
        $this->assertSame('/users?active=1', $localContinue->invoke($service, '/users?active=1'));

        Yii::$app->db->createCommand()->update('identity_providers', [
            'authorize_url' => 'https://idp.example.test/authorize?prompt=login&client_id=stale#provider-fragment',
        ], ['id' => $created['data']['id']])->execute();
        $provider->refresh();
        $loginUrlWithQuery = $service->start(
            strtolower($code),
            '/users',
            $browserContext
        )->data['redirect'];
        $this->assertStringEndsWith('#provider-fragment', $loginUrlWithQuery);
        $this->assertSame(1, substr_count($loginUrlWithQuery, 'client_id='));
        $loginPartsWithQuery = parse_url($loginUrlWithQuery);
        parse_str($loginPartsWithQuery['query'] ?? '', $loginQueryWithQuery);
        $this->assertSame('login', $loginQueryWithQuery['prompt'] ?? null);
        $this->assertSame('client-' . $code, $loginQueryWithQuery['client_id'] ?? null);
        $this->assertSame('code', $loginQueryWithQuery['response_type'] ?? null);

        Yii::$app->db->createCommand()->update('broker_login_states', [
            'expires_at' => '2000-01-01 00:00:00+00',
        ], [
            'state_hash' => hash('sha256', $loginQueryWithQuery['state']),
        ])->execute();
        $this->assertFalse($service->consumeRejectedCallback(
            strtolower($code),
            $loginQueryWithQuery['state'],
            $browserContext
        ));

        Yii::$app->db->createCommand()->update(
            'identity_providers',
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $created['data']['id']]
        )->execute();

        $updated = $service->updateProvider($created['data']['id'], [
            'name' => 'PHPUnit IdP Updated',
            'client_secret' => '',
        ]);
        $this->assertSame(200, $updated['status'], json_encode($updated));
        $this->assertGreaterThan(strtotime('2020-01-01T00:00:00Z'), strtotime($updated['data']['updated_at']));
        $this->assertSame('oidc', $updated['data']['type']);
        $this->assertSame('client-' . $code, $updated['data']['client_id']);
        $this->assertStringStartsWith('https://idp.example.test/authorize', $updated['data']['authorize_url']);
        $this->assertSame('https://idp.example.test/token', $updated['data']['token_url']);
        $this->assertSame('https://idp.example.test/userinfo', $updated['data']['userinfo_url']);
        $this->assertSame('openid email', $updated['data']['scopes']);
        $this->assertTrue($updated['data']['enabled']);
        $provider->refresh();
        $this->assertSame('super-secret', (new CryptoService())->decrypt((string)$provider->client_secret));

        Yii::$app->db->createCommand()->update(
            'identity_providers',
            ['token_url' => ''],
            ['id' => $created['data']['id']]
        )->execute();
        $unsafeRuntimeProvider = $service->start(strtolower($code), '/', $browserContext);
        $this->assertSame(404, $unsafeRuntimeProvider['status']);
        Yii::$app->db->createCommand()->update(
            'identity_providers',
            ['token_url' => 'https://idp.example.test/token'],
            ['id' => $created['data']['id']]
        )->execute();

        $deleted = $service->deleteProvider($created['data']['id']);
        $this->assertSame(200, $deleted['status']);
        $this->identityProviderIds = [];
    }

    public function testBrokerStateMatchesSignedStateContract(): void
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
            $verifyState = new \ReflectionMethod($service, 'verifyState');

            $state = [
                'p' => 'provider-code',
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
            $this->assertNull($verifyState->invoke($service, $this->signedStateForTest($expiredState, $jwtSecret)));

            $stateSignedWithWrongKey = $this->signedStateForTest($state, $encryptionKey);
            $this->assertNull($verifyState->invoke($service, $stateSignedWithWrongKey));
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
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            "SELECT count(*) FROM audit_logs WHERE action = 'ldap_provider_created' AND target_id = :id",
            [':id' => $created['data']['id']]
        )->queryScalar());

        $invalidCreate = $service->createProvider([
            'name' => 'Invalid PHPUnit LDAP',
            'host' => 'ldap.example.test',
            'port' => -1,
        ], null);
        $this->assertSame(500, $invalidCreate['status']);
        $this->assertSame('failed to create provider', $invalidCreate['data']['error']);

        $provider = LdapProvider::findOne(['id' => $created['data']['id']]);
        $this->assertSame('ldap-secret', (new CryptoService())->decrypt((string)$provider->bind_password));

        Yii::$app->db->createCommand()->update(
            'ldap_providers',
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $created['data']['id']]
        )->execute();

        $invalidUpdate = $service->updateProvider($created['data']['id'], [
            'name' => 'Invalid PHPUnit LDAP update',
            'port' => 0,
        ], null);
        $this->assertSame(500, $invalidUpdate['status']);
        $this->assertSame('failed to update provider', $invalidUpdate['data']['error']);

        $updated = $service->updateProvider($created['data']['id'], [
            'name' => 'PHPUnit LDAP Updated',
            'port' => 389,
            'bind_password' => '',
        ], null);
        $this->assertSame(200, $updated['status'], json_encode($updated));
        $this->assertGreaterThan(strtotime('2020-01-01T00:00:00Z'), strtotime($updated['data']['updated_at']));
        $this->assertSame('ldap.example.test', $updated['data']['host']);
        $this->assertSame('cn=service,dc=example,dc=test', $updated['data']['bind_dn']);
        $this->assertSame('dc=example,dc=test', $updated['data']['base_dn']);
        $this->assertSame('(&(objectClass=user)(sAMAccountName={username}))', $updated['data']['user_filter']);
        $this->assertSame('sAMAccountName', $updated['data']['username_attribute']);
        $this->assertSame('mail', $updated['data']['email_attribute']);
        $this->assertSame('displayName', $updated['data']['display_name_attribute']);
        $this->assertSame('', $updated['data']['group_attribute']);
        $this->assertFalse($updated['data']['use_tls']);
        $this->assertFalse($updated['data']['start_tls']);
        $this->assertTrue($updated['data']['enabled']);
        $provider->refresh();
        $this->assertSame('ldap-secret', (new CryptoService())->decrypt((string)$provider->bind_password));
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            "SELECT count(*) FROM audit_logs WHERE action = 'ldap_provider_updated' AND target_id = :id AND actor_user_id IS NULL",
            [':id' => $created['data']['id']]
        )->queryScalar());

        $missingProviderId = '00000000-0000-0000-0000-000000000000';
        $missingList = $service->listGroupMappings($missingProviderId);
        $this->assertSame(404, $missingList->status);
        $missingCreate = $service->createGroupMapping($missingProviderId, [
            'ldap_group' => 'cn=missing,dc=example,dc=test',
            'group_id' => $this->groupId,
        ], null);
        $this->assertSame(404, $missingCreate->status);

        $otherProvider = $service->createProvider([
            'name' => 'PHPUnit LDAP Other ' . $suffix,
            'host' => 'ldap-other.example.test',
            'bind_dn' => 'cn=service,dc=example,dc=test',
            'bind_password' => 'other-secret',
            'base_dn' => 'dc=example,dc=test',
        ], null);
        $this->assertSame(201, $otherProvider->status);
        $this->ldapProviderIds[] = $otherProvider->data['id'];

        $mapping = $service->createGroupMapping($created['data']['id'], [
            'ldap_group' => 'cn=admins,dc=example,dc=test',
            'group_id' => $this->groupId,
        ], null);
        $this->assertSame(201, $mapping['status']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $mapping['data']['created_at']
        );
        $duplicateMapping = $service->createGroupMapping($created['data']['id'], [
            'ldap_group' => 'cn=admins,dc=example,dc=test',
            'group_id' => $this->groupId,
        ], null);
        $this->assertSame(409, $duplicateMapping['status']);
        $this->assertSame('mapping already exists', $duplicateMapping['data']['error']);
        $secondMapping = $service->createGroupMapping($created['data']['id'], [
            'ldap_group' => 'cn=aardvarks,dc=example,dc=test',
            'group_id' => $this->groupId,
        ], null);
        $this->assertSame(201, $secondMapping['status']);
        $mappings = $service->listGroupMappings($created['data']['id']);
        $this->assertSame(200, $mappings->status);
        $this->assertCount(2, $mappings->data);
        $this->assertSame([
            'cn=aardvarks,dc=example,dc=test',
            'cn=admins,dc=example,dc=test',
        ], array_column($mappings->data, 'ldap_group'));
        $this->assertFalse($service->deleteGroupMapping(
            $otherProvider->data['id'],
            $mapping->data['id'],
            null
        ));
        $this->assertNotNull(LdapGroupMapping::findOne(['id' => $mapping->data['id']]));

        $function = 'fail_ldap_mapping_audit_' . bin2hex(random_bytes(4));
        $trigger = 'fail_ldap_mapping_audit_' . bin2hex(random_bytes(4));
        Yii::$app->db->createCommand(
            "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS '
             BEGIN
                 IF NEW.action = ''ldap_group_mapping_deleted'' THEN
                     RAISE EXCEPTION ''forced audit failure'';
                 END IF;
                 RETURN NEW;
             END'"
        )->execute();
        Yii::$app->db->createCommand(
            "CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_logs
             FOR EACH ROW EXECUTE FUNCTION {$function}()"
        )->execute();
        try {
            try {
                $service->deleteGroupMapping($created->data['id'], $mapping->data['id'], null);
                $this->fail('mandatory audit failure must abort LDAP mapping deletion');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString('forced audit failure', $exception->getMessage());
            }
            $this->assertNotNull(LdapGroupMapping::findOne(['id' => $mapping->data['id']]));
        } finally {
            Yii::$app->db->createCommand("DROP TRIGGER IF EXISTS {$trigger} ON audit_logs")->execute();
            Yii::$app->db->createCommand("DROP FUNCTION IF EXISTS {$function}() ")->execute();
        }

        $this->assertTrue($service->deleteGroupMapping(
            $created->data['id'],
            $mapping->data['id'],
            null
        ));
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            "SELECT count(*) FROM audit_logs WHERE action = 'ldap_group_mapping_deleted' AND target_type = 'ldap_group_mapping' AND target_id = :id AND actor_user_id IS NULL",
            [':id' => $mapping['data']['id']]
        )->queryScalar());
        $this->assertTrue($service->deleteGroupMapping(
            $created->data['id'],
            $secondMapping->data['id'],
            null
        ));
        $remainingMappings = $service->listGroupMappings($created->data['id']);
        $this->assertSame(200, $remainingMappings->status);
        $this->assertCount(0, $remainingMappings->data);

        $this->assertTrue($service->deleteProvider($created['data']['id'], null));
        $this->assertFalse($service->deleteProvider($created['data']['id'], null));
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            "SELECT count(*) FROM audit_logs WHERE action = 'ldap_provider_deleted' AND target_id = :id AND actor_user_id IS NULL",
            [':id' => $created['data']['id']]
        )->queryScalar());
        $this->assertNull(LdapProvider::findOne(['id' => $created['data']['id']]));
        Yii::$app->db->createCommand()->delete('audit_logs', [
            'target_type' => 'ldap_group_mapping',
            'target_id' => [$mapping['data']['id'], $secondMapping['data']['id']],
        ])->execute();
    }

    private function signedStateForTest(array $state, string $secret): string
    {
        $payload = json_encode($state);

        return JwtService::base64url($payload) . '.' . JwtService::base64url(
            hash_hmac('sha256', $payload, $secret, true)
        );
    }

    private function consumeStateConcurrently(
        string $providerCode,
        string $state,
        string $browserContext
    ): array {
        $script = dirname(__DIR__) . '/Fixtures/broker_state_worker.php';
        $startAt = sprintf('%.6F', microtime(true) + 0.75);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $workers = [];

        for ($index = 0; $index < 2; $index++) {
            $process = proc_open(
                [PHP_BINARY, $script, $providerCode, $state, $browserContext, $startAt],
                $descriptorSpec,
                $pipes
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }

        $outputs = [];
        foreach ($workers as [$process, $stdout, $stderr]) {
            $output = stream_get_contents($stdout);
            $error = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $this->assertSame(0, proc_close($process), $error);
            $outputs[] = trim($output);
        }
        sort($outputs);

        return $outputs;
    }
}
