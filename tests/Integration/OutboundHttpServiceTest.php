<?php

namespace tests\Integration;

use app\services\OutboundHttpService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class OutboundHttpServiceTest extends TestCase
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
        Yii::$app->params['outboundHostAllowlist'] = '';
    }

    public function testEndpointValidationRejectsUnsafeSchemesAndCredentials(): void
    {
        $service = new OutboundHttpService();

        $this->assertTrue($service->isValidEndpoint('https://idp.example.test/token'));
        $this->assertFalse($service->isValidEndpoint('http://idp.example.test/token'));
        $this->assertFalse($service->isValidEndpoint('https://user:pass@idp.example.test/token'));
        $this->assertFalse($service->isValidEndpoint('https://idp.example.test/token#fragment'));
        $this->assertFalse($service->isValidEndpoint('file:///etc/passwd'));
    }

    public function testExplicitAllowlistIsRequiredForLocalHttpEndpoint(): void
    {
        $service = new OutboundHttpService();
        $this->assertFalse($service->isValidEndpoint('http://127.0.0.1:8082/token'));

        Yii::$app->params['outboundHostAllowlist'] = '127.0.0.1';
        $this->assertTrue($service->isValidEndpoint('http://127.0.0.1:8082/token'));
        $this->assertFalse($service->isValidEndpoint('http://127.0.0.2:8082/token'));
    }

    public function testPrivateAndReservedAddressesAreNotPublic(): void
    {
        $service = new OutboundHttpService();
        $method = new \ReflectionMethod($service, 'isPublicAddress');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, '127.0.0.1'));
        $this->assertFalse($method->invoke($service, '169.254.169.254'));
        $this->assertFalse($method->invoke($service, '::1'));
        $this->assertTrue($method->invoke($service, '93.184.216.34'));
    }
}
