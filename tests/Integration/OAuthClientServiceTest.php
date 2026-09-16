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
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertNotEmpty($result['data']['client_secret']);
        $this->assertSame(['openid', 'profile'], $result['data']['allowed_scopes']);
        $this->assertSame(['https://rp.example.test/logout'], $result['data']['post_logout_redirect_uris']);
        $this->assertTrue($result['data']['skip_consent']);

        $client = OAuthClient::findOne(['client_id' => $clientId]);
        $this->assertNotNull($client);
        $this->assertTrue(password_verify($result['data']['client_secret'], (string)$client->client_secret_hash));
        $this->assertSame(['https://rp.example.test/callback', 'https://rp.example.test/alt'], OAuthClient::parsePgArray($client->redirect_uris));

        $auditCount = (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM audit_logs WHERE target_type = :target_type AND target_id = :target_id AND action = :action',
            [
                ':target_type' => 'oauth_client',
                ':target_id' => $client->id,
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
        ]);
        $this->assertSame(201, $created['status']);
        $this->assertArrayNotHasKey('client_secret', $created['data']);

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
    }
}
