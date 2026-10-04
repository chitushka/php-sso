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
        $this->assertFalse($service->isValidEndpoint("https://idp.example.test/token\r\nHost: attacker.test"));
        $this->assertFalse($service->isValidEndpoint('https://idp.example.test\\@attacker.test/token'));
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

        $this->assertFalse($method->invoke($service, '127.0.0.1'));
        $this->assertFalse($method->invoke($service, '169.254.169.254'));
        $this->assertFalse($method->invoke($service, '::1'));
        $this->assertFalse($method->invoke($service, '::ffff:127.0.0.1'));
        $this->assertFalse($method->invoke($service, '::ffff:169.254.169.254'));
        $this->assertFalse($method->invoke($service, '::ffff:10.0.0.1'));
        $this->assertTrue($method->invoke($service, '93.184.216.34'));
        $this->assertTrue($method->invoke($service, '::ffff:93.184.216.34'));
    }

    /**
     * @dataProvider oversizedResponseProvider
     */
    public function testResponseLimitAppliesWhileReadingLengthAndChunkedBodies(string $mode): void
    {
        Yii::$app->params['outboundHostAllowlist'] = '127.0.0.1';
        Yii::$app->params['outboundMaxResponseBytes'] = 64;
        $command = [PHP_BINARY, dirname(__DIR__) . '/Fixtures/http_response_server.php', $mode];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $port = trim((string)fgets($pipes[1]));

        try {
            $this->assertMatchesRegularExpression('/^\d+$/D', $port);
            $this->assertNull((new OutboundHttpService())->request(
                'http://127.0.0.1:' . $port . '/response',
                'GET',
                null,
                []
            ));
        } finally {
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
    }

    public function oversizedResponseProvider(): array
    {
        return [['length'], ['chunked']];
    }
}
