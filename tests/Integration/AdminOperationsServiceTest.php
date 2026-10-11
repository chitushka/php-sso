<?php

namespace tests\Integration;

use app\services\FederatedIdentityService;
use app\services\OidcService;
use app\services\OperationalStatusService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Transaction;

final class AdminOperationsServiceTest extends TestCase
{
    private Transaction $transaction;

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
        $this->transaction = Yii::$app->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive) {
            $this->transaction->rollBack();
        }
        parent::tearDown();
    }

    public function testFederatedIdentityCanBeListedAndUnlinkedWithAudit(): void
    {
        [$actorId, $userId, $providerId] = $this->fixtures(true, 'local');
        $service = new FederatedIdentityService();

        $listed = $service->listForUser($userId);
        $this->assertTrue($listed->isSuccess());
        $this->assertSame('subject-1', $listed->data[0]['external_subject']);
        $this->assertArrayNotHasKey('client_secret', $listed->data[0]);

        $unlinked = $service->unlink($userId, $providerId, 'subject-1', $actorId);
        $this->assertTrue($unlinked->isSuccess());
        $this->assertSame(0, (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM federated_identities WHERE user_id = :user_id',
            [':user_id' => $userId]
        )->queryScalar());
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM audit_logs
             WHERE actor_user_id = :actor_id AND action = 'federated_identity_unlinked'",
            [':actor_id' => $actorId]
        )->queryScalar());
    }

    public function testLastFederatedLoginMethodCannotBeRemoved(): void
    {
        [$actorId, $userId, $providerId] = $this->fixtures(false, 'federated');

        $result = (new FederatedIdentityService())->unlink(
            $userId,
            $providerId,
            'subject-1',
            $actorId
        );

        $this->assertFalse($result->isSuccess());
        $this->assertSame(409, $result->status);
        $this->assertSame('last_login_method', $result->error->code);
        $this->assertSame(1, (int)Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM federated_identities WHERE user_id = :user_id',
            [':user_id' => $userId]
        )->queryScalar());
    }

    public function testSigningRotationAndOperationalStatusExposeOnlySafeMetadata(): void
    {
        [$actorId] = $this->fixtures(true, 'local', false);
        $oidc = new OidcService();
        $oidc->jwks();
        $rotated = $oidc->rotateSigningKey($actorId);
        $keys = $oidc->listSigningKeys();
        $status = (new OperationalStatusService())->status();

        $this->assertSame('active', $rotated['status']);
        $this->assertSame($rotated['kid'], $keys[0]['kid']);
        $this->assertArrayNotHasKey('private_key_pem', $rotated);
        $this->assertArrayNotHasKey('public_key_pem', $rotated);
        $this->assertSame('ready', $status['status']);
        $this->assertTrue($status['database']['ready']);
        $this->assertTrue($status['signing_keys']['ready']);
    }

    private function fixtures(bool $withPassword, string $source, bool $withIdentity = true): array
    {
        $suffix = bin2hex(random_bytes(5));
        $actorId = (string)Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source)
             VALUES (:username,:email,:password_hash,'active','local') RETURNING id",
            [
                ':username' => 'operations-actor-' . $suffix,
                ':email' => 'operations-actor-' . $suffix . '@example.test',
                ':password_hash' => password_hash('actor-password-123', PASSWORD_DEFAULT),
            ]
        )->queryScalar();
        $userId = (string)Yii::$app->db->createCommand(
            "INSERT INTO users(username,email,password_hash,status,source)
             VALUES (:username,:email,:password_hash,'active',:source) RETURNING id",
            [
                ':username' => 'operations-user-' . $suffix,
                ':email' => 'operations-user-' . $suffix . '@example.test',
                ':password_hash' => $withPassword ? password_hash('user-password-123', PASSWORD_DEFAULT) : null,
                ':source' => $source,
            ]
        )->queryScalar();
        $providerId = (string)Yii::$app->db->createCommand(
            "INSERT INTO identity_providers(
                code,name,type,client_id,authorize_url,token_url,userinfo_url
             ) VALUES (
                :code,'Operations provider','oidc',:client_id,
                'https://idp.example.test/authorize','https://idp.example.test/token',
                'https://idp.example.test/userinfo'
             ) RETURNING id",
            [':code' => 'operations-' . $suffix, ':client_id' => 'operations-client-' . $suffix]
        )->queryScalar();
        if ($withIdentity) {
            Yii::$app->db->createCommand()->insert('federated_identities', [
                'provider_id' => $providerId,
                'external_subject' => 'subject-1',
                'user_id' => $userId,
                'email' => 'external@example.test',
            ])->execute();
        }

        return [$actorId, $userId, $providerId];
    }
}
