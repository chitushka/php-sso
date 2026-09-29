<?php

namespace tests\Integration;

use app\models\Session;
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
