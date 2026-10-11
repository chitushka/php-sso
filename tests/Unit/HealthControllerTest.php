<?php

namespace tests\Unit;

use app\controllers\HealthController;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yii;
use yii\base\Component;
use yii\web\Application;

class HealthControllerTest extends TestCase
{
    /** @var mixed */
    private $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Yii::$app;

        new Application([
            'id' => 'sso-health-unit',
            'basePath' => dirname(__DIR__, 2),
            'components' => [
                'request' => [
                    'class' => \yii\web\Request::class,
                    'cookieValidationKey' => 'health-unit-cookie-key',
                    'scriptFile' => __FILE__,
                    'scriptUrl' => '/index.php',
                ],
                'response' => \yii\web\Response::class,
                'db' => HealthCheckDatabase::class,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Yii::$app = $this->previousApplication;
        parent::tearDown();
    }

    public function testReadyReturnsContractCompliantServiceUnavailableResponse(): void
    {
        Yii::$app->db->available = false;

        $result = (new HealthController('health', Yii::$app))->actionReady();

        $this->assertSame(503, Yii::$app->response->statusCode);
        $this->assertSame([
            'error' => 'database unavailable',
            'code' => 'database_unavailable',
        ], $result);
    }

    public function testReadyReturnsReadyWhenDatabaseResponds(): void
    {
        Yii::$app->db->available = true;

        $result = (new HealthController('health', Yii::$app))->actionReady();

        $this->assertSame(200, Yii::$app->response->statusCode);
        $this->assertSame(['status' => 'ready'], $result);
    }
}

class HealthCheckDatabase extends Component
{
    /** @var bool */
    public $available = true;

    public function createCommand(string $sql): HealthCheckCommand
    {
        return new HealthCheckCommand($this->available);
    }
}

class HealthCheckCommand
{
    /** @var bool */
    private $available;

    public function __construct(bool $available)
    {
        $this->available = $available;
    }

    public function queryScalar()
    {
        if (!$this->available) {
            throw new RuntimeException('database unavailable');
        }

        return 1;
    }
}
