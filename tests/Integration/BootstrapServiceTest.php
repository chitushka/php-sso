<?php

namespace tests\Integration;

use app\services\BootstrapService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Transaction;

class BootstrapServiceTest extends TestCase
{
    /** @var Transaction */
    private $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }

        $this->transaction = Yii::$app->db->beginTransaction();
        Yii::$app->db->createCommand('DELETE FROM users')->execute();
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive) {
            $this->transaction->rollBack();
        }

        parent::tearDown();
    }

    public function testCreateAdminMatchesGoDefaultsRoleAndAudit(): void
    {
        $result = (new BootstrapService())->createAdmin([
            'username' => ' bootstrap-admin ',
            'email' => ' bootstrap@example.test ',
            'password' => 'bootstrap-password-123',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(' bootstrap-admin ', $result['data']['username']);
        $this->assertSame(' bootstrap@example.test ', $result['data']['email']);
        $this->assertFalse($result['data']['email_verified']);

        $userId = $result['data']['id'];
        $roleCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.code = 'admin'",
            [':user_id' => $userId]
        )->queryScalar();
        $this->assertSame(1, $roleCount);

        $auditCount = (int)Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM audit_logs
             WHERE actor_user_id = :actor_user_id AND action = 'bootstrap_completed'
               AND target_type = 'user' AND target_id = :target_id",
            [':actor_user_id' => $userId, ':target_id' => $userId]
        )->queryScalar();
        $this->assertSame(1, $auditCount);

        $second = (new BootstrapService())->createAdmin([
            'username' => 'second-admin',
            'email' => 'second@example.test',
            'password' => 'bootstrap-password-123',
        ]);
        $this->assertSame(409, $second['status']);
        $this->assertSame(['error' => 'system already initialized'], $second['data']);
    }

    public function testRequiredIdentityFieldsAreValidatedBeforePassword(): void
    {
        $result = (new BootstrapService())->createAdmin([
            'username' => ' ',
            'email' => '',
            'password' => 'short',
        ]);

        $this->assertSame(400, $result['status']);
        $this->assertSame(['error' => 'username and email are required'], $result['data']);
    }
}
