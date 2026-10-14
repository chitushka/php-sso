<?php

namespace tests\Integration;

use app\models\OAuthClient;
use app\services\OAuthService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class OAuthClientServiceTest extends TestCase
{
    private string $prefix;
    private array $clientIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }

        $this->prefix = 'phpunit_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!empty($this->clientIds)) {
            Yii::$app->db->createCommand()
                ->delete('audit_logs', ['target_type' => 'oauth_client', 'target_id' => $this->clientIds])
                ->execute();
            Yii::$app->db->createCommand()
                ->delete('oauth_clients', ['client_id' => $this->clientIds])
                ->execute();
        }

        parent::tearDown();
    }

    public function testCreateConfidentialClientPersistsArraysSecretAndAuditLog(): void
    {
        $clientId = $this->prefix . '_confidential';
        $this->clientIds[] = $clientId;

        $result = (new OAuthService())->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit confidential client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => ['https://rp.example.test/callback', 'https://rp.example.test/alt'],
            'allowed_scopes' => ['openid', 'profile'],
            'post_logout_redirect_uris' => ['https://rp.example.test/logout'],
            'skip_consent' => true,
            'enabled' => true,
        ]);

        $this->assertSame(201, $result['status'], json_encode($result));
        $this->assertNotEmpty($result['data']['client_secret']);
        $this->assertSame(['openid', 'profile'], $result['data']['allowed_scopes']);
        $this->assertSame(['https://rp.example.test/logout'], $result['data']['post_logout_redirect_uris']);
        $this->assertTrue($result['data']['skip_consent']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $result['data']['created_at']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/',
            $result['data']['updated_at']
        );

        $client = OAuthClient::findOne(['client_id' => $clientId]);
        $this->assertNotNull($client);
        $this->assertTrue(password_verify($result['data']['client_secret'], (string)$client->client_secret_hash));
        $this->assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=2$', (string)$client->client_secret_hash);
        $this->assertSame(['https://rp.example.test/callback', 'https://rp.example.test/alt'], OAuthClient::parsePgArray($client->redirect_uris));

        $auditCount = (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE target_type = :target_type AND target_id = :target_id AND action = :action',
            [
                ':target_type' => 'oauth_client',
                ':target_id' => $clientId,
                ':action' => 'oauth_client_created',
            ]
        )->queryScalar();
        $this->assertSame(1, $auditCount);
    }

    public function testUpdateClientReplacesArrayFields(): void
    {
        $clientId = $this->prefix . '_public';
        $this->clientIds[] = $clientId;
        $service = new OAuthService();

        $created = $service->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit public client',
            'type' => OAuthClient::TYPE_PUBLIC,
            'redirect_uris' => ['https://rp.example.test/callback'],
            'allowed_scopes' => ['openid'],
            'enabled' => true,
        ]);
        $this->assertSame(201, $created['status']);
        $this->assertArrayNotHasKey('client_secret', $created['data']);
        Yii::$app->db->createCommand()->update(
            'oauth_clients',
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $created['data']['id']]
        )->execute();

        $updated = $service->updateClient((string)$created['data']['id'], [
            'name' => 'PHPUnit public client updated',
            'redirect_uris' => ['https://rp.example.test/new-callback'],
            'allowed_scopes' => ['openid', 'email'],
            'post_logout_redirect_uris' => ['https://rp.example.test/signed-out'],
            'skip_consent' => true,
        ]);

        $this->assertSame(200, $updated['status']);
        $this->assertSame('PHPUnit public client updated', $updated['data']['name']);
        $this->assertSame(['https://rp.example.test/new-callback'], $updated['data']['redirect_uris']);
        $this->assertSame(['openid', 'email'], $updated['data']['allowed_scopes']);
        $this->assertSame(['https://rp.example.test/signed-out'], $updated['data']['post_logout_redirect_uris']);
        $this->assertTrue($updated['data']['skip_consent']);
        $this->assertFalse($updated['data']['enabled']);
        $this->assertSame(OAuthClient::TYPE_PUBLIC, $updated['data']['type']);
        $this->assertGreaterThan(strtotime('2020-01-01T00:00:00Z'), strtotime($updated['data']['updated_at']));
    }

    public function testRotateSecretAdvancesUpdatedAt(): void
    {
        $clientId = $this->prefix . '_rotate';
        $this->clientIds[] = $clientId;
        $service = new OAuthService();
        $created = $service->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit rotate client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
        ]);
        Yii::$app->db->createCommand()->update(
            'oauth_clients',
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $created['data']['id']]
        )->execute();

        $rotated = $service->rotateSecret((string)$created['data']['id']);

        $this->assertSame(200, $rotated['status']);
        $this->assertNotSame($created['data']['client_secret'], $rotated['data']['client_secret']);
        $this->assertGreaterThan(strtotime('2020-01-01T00:00:00Z'), strtotime($rotated['data']['updated_at']));
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :action AND target_id = :target_id',
            [
                ':action' => 'oauth_client_secret_rotated',
                ':target_id' => $clientId,
            ]
        )->queryScalar());
    }

    public function testCreateClientUsesDocumentedDefaults(): void
    {
        $clientId = $this->prefix . '_defaults';
        $this->clientIds[] = $clientId;

        $created = (new OAuthService())->createClient([
            'client_id' => $clientId,
            'name' => 'PHPUnit default client',
        ]);

        $this->assertSame(201, $created['status']);
        $this->assertSame(OAuthClient::TYPE_CONFIDENTIAL, $created['data']['type']);
        $this->assertFalse($created['data']['enabled']);
        $this->assertSame([], $created['data']['redirect_uris']);
        $this->assertSame([], $created['data']['allowed_scopes']);
        $this->assertSame([], $created['data']['post_logout_redirect_uris']);
    }

    public function testCreateRollsBackWhenMandatoryAuditFails(): void
    {
        $clientId = $this->prefix . '_audit_rollback';
        $function = 'fail_oauth_client_audit_' . bin2hex(random_bytes(4));
        $trigger = 'fail_oauth_client_audit_' . bin2hex(random_bytes(4));
        Yii::$app->db->createCommand(
            "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS '
             BEGIN
                 IF NEW.action = ''oauth_client_created'' THEN
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
                (new OAuthService())->createClient([
                    'client_id' => $clientId,
                    'name' => 'Audit rollback client',
                ]);
                $this->fail('mandatory audit failure must abort client creation');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString('forced audit failure', $exception->getMessage());
            }
            $this->assertFalse(OAuthClient::find()->where(['client_id' => $clientId])->exists());
        } finally {
            Yii::$app->db->createCommand("DROP TRIGGER IF EXISTS {$trigger} ON audit_logs")->execute();
            Yii::$app->db->createCommand("DROP FUNCTION IF EXISTS {$function}() ")->execute();
        }
    }

    /**
     * @dataProvider unsafeRedirectProvider
     */
    public function testClientRegistrationRejectsUnsafeRedirects(string $uri): void
    {
        $result = (new OAuthService())->createClient([
            'client_id' => $this->prefix . '_unsafe_' . bin2hex(random_bytes(2)),
            'name' => 'Unsafe redirect client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => [$uri],
            'enabled' => true,
        ]);

        $this->assertSame(400, $result['status']);
        $this->assertSame('redirect URIs violate the client redirect policy', $result['data']['error']);
    }

    public function unsafeRedirectProvider(): array
    {
        return [
            'plain HTTP' => ['http://rp.example.test/callback'],
            'credentials' => ['https://user:pass@rp.example.test/callback'],
            'fragment' => ['https://rp.example.test/callback#token'],
            'backslash' => ['https://rp.example.test/callback\\evil'],
        ];
    }

    public function testLoopbackRedirectRequiresExplicitPublicClientPolicy(): void
    {
        $service = new OAuthService();
        $publicId = $this->prefix . '_loopback_public';
        $this->clientIds[] = $publicId;
        $public = $service->createClient([
            'client_id' => $publicId,
            'name' => 'Native loopback client',
            'type' => OAuthClient::TYPE_PUBLIC,
            'redirect_uris' => ['http://127.0.0.1/callback'],
            'allow_loopback_redirects' => true,
            'enabled' => true,
        ]);
        $this->assertSame(201, $public['status']);
        $this->assertTrue($public['data']['allow_loopback_redirects']);

        $confidential = $service->createClient([
            'client_id' => $this->prefix . '_loopback_confidential',
            'name' => 'Invalid loopback client',
            'type' => OAuthClient::TYPE_CONFIDENTIAL,
            'redirect_uris' => ['http://127.0.0.1/callback'],
            'allow_loopback_redirects' => true,
            'enabled' => true,
        ]);
        $this->assertSame(400, $confidential['status']);
    }
}
