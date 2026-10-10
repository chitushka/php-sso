<?php

namespace tests\Integration;

use app\models\Session;
use app\models\AuditLog;
use app\models\User;
use app\services\RateLimitService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\web\Request;

class ProductionHardeningTest extends TestCase
{
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

    public function testDomainRulesRejectInvalidEmailStatusSourceAndUuid(): void
    {
        $user = new User();
        $user->email = 'not-an-email';
        $user->status = 'unknown';
        $user->source = 'unknown';

        $this->assertFalse($user->validate(['email', 'status', 'source']));
        $this->assertArrayHasKey('email', $user->errors);
        $this->assertArrayHasKey('status', $user->errors);
        $this->assertArrayHasKey('source', $user->errors);

        $session = new Session();
        $session->id = 'not-a-uuid';
        $this->assertFalse($session->validate(['id']));
    }

    public function testSchemaRequiredRulesRejectNullForNotNullColumns(): void
    {
        $audit = new AuditLog();

        $this->assertFalse($audit->validate(['action', 'target_type', 'target_id']));
        $this->assertArrayHasKey('action', $audit->errors);
        $this->assertArrayHasKey('target_type', $audit->errors);
        $this->assertArrayHasKey('target_id', $audit->errors);
    }

    public function testDatabaseRejectsInvalidInvariantValues(): void
    {
        $this->expectException(\yii\db\IntegrityException::class);
        Yii::$app->db->createCommand()->insert('login_attempts', [
            'username' => 'negative-attempts-' . bin2hex(random_bytes(4)),
            'ip' => '192.0.2.10',
            'attempts' => -1,
        ])->execute();
    }

    public function testCleanupAndAdminQueriesUseExpectedIndexes(): void
    {
        Yii::$app->db->createCommand('SET enable_seqscan = off')->execute();
        try {
            $cleanupPlan = Yii::$app->db->createCommand(
                "EXPLAIN SELECT ctid FROM sessions
                 WHERE expires_at < now() - interval '24 hours' LIMIT 1000"
            )->queryColumn();
            $adminPlan = Yii::$app->db->createCommand(
                'EXPLAIN SELECT id FROM users ORDER BY created_at DESC, id DESC LIMIT 50'
            )->queryColumn();
            $auditPlan = Yii::$app->db->createCommand(
                "EXPLAIN SELECT id FROM audit_logs
                 WHERE actor_user_id = '00000000-0000-4000-8000-000000000000'
                 ORDER BY created_at DESC, id DESC LIMIT 50"
            )->queryColumn();
        } finally {
            Yii::$app->db->createCommand('RESET enable_seqscan')->execute();
        }

        $this->assertStringContainsString('idx_sessions_cleanup', implode("\n", $cleanupPlan));
        $this->assertStringContainsString('idx_users_admin_list', implode("\n", $adminPlan));
        $this->assertStringContainsString('idx_audit_logs_actor_list', implode("\n", $auditPlan));
    }

    public function testRateLimitBucketConsumesBurstAtomically(): void
    {
        Yii::$app->params['rateLimitedRoutes'] = ['auth/login'];
        Yii::$app->params['rateLimitBurst'] = 2;
        Yii::$app->params['rateLimitPerMinute'] = 1;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
        $request = new Request([
            'cookieValidationKey' => 'production-hardening-test',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $key = sha1('auth/login|198.51.100.77');
        Yii::$app->db->createCommand()->delete('rate_limit_buckets', ['bucket_key' => $key])->execute();

        try {
            $limiter = new RateLimitService();
            $this->assertTrue($limiter->check('auth/login', $request)['allowed']);
            $this->assertTrue($limiter->check('auth/login', $request)['allowed']);
            $third = $limiter->check('auth/login', $request);
            $this->assertFalse($third['allowed']);
            $this->assertGreaterThanOrEqual(1, $third['retry_after']);
        } finally {
            Yii::$app->db->createCommand()->delete('rate_limit_buckets', ['bucket_key' => $key])->execute();
        }
    }
}
