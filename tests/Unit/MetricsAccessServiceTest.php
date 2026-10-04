<?php

namespace tests\Unit;

use app\services\MetricsAccessService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\web\Application;
use yii\web\Request;

class MetricsAccessServiceTest extends TestCase
{
    /** @var mixed */
    private $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Yii::$app;
        new Application([
            'id' => 'metrics-access-unit',
            'basePath' => dirname(__DIR__, 2),
            'components' => [
                'request' => [
                    'class' => Request::class,
                    'cookieValidationKey' => 'metrics-access-unit-key',
                    'scriptFile' => __FILE__,
                    'scriptUrl' => '/index.php',
                ],
            ],
            'params' => [
                'metricsAllowedCidrs' => '10.0.0.0/8',
                'metricsBearerToken' => 'unit-test-bearer-token',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Yii::$app = $this->previousApplication;
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function testAllowsConfiguredNetwork(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
        $request = $this->request();

        $this->assertTrue((new MetricsAccessService())->allowed($request));
    }

    public function testRejectsUnknownNetworkWithoutToken(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $request = $this->request();

        $this->assertFalse((new MetricsAccessService())->allowed($request));
    }

    public function testAllowsBearerToken(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $request = $this->request();
        $request->headers->set('Authorization', 'Bearer unit-test-bearer-token');

        $this->assertTrue((new MetricsAccessService())->allowed($request));
    }

    private function request(): Request
    {
        return new Request([
            'cookieValidationKey' => 'metrics-access-unit-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
    }
}

