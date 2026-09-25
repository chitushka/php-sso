<?php

namespace tests\Unit;

use app\components\MaintenanceMode;
use PHPUnit\Framework\TestCase;
use yii\web\Request;
use yii\web\Response;

class MaintenanceModeTest extends TestCase
{
    /** @var string|false */
    private $previousFlagPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFlagPath = getenv('SSO_MAINTENANCE_FILE');
    }

    protected function tearDown(): void
    {
        if ($this->previousFlagPath === false) {
            putenv('SSO_MAINTENANCE_FILE');
        } else {
            putenv('SSO_MAINTENANCE_FILE=' . $this->previousFlagPath);
        }
        parent::tearDown();
    }

    public function testInactiveWithoutFlagFile(): void
    {
        putenv('SSO_MAINTENANCE_FILE=/tmp/sso-maintenance-missing-' . bin2hex(random_bytes(4)));
        $this->assertFalse(MaintenanceMode::isActive());
    }

    public function testActiveModeAllowsOnlyLiveness(): void
    {
        $flagPath = tempnam(sys_get_temp_dir(), 'sso-maintenance-');
        $this->assertNotFalse($flagPath);
        putenv('SSO_MAINTENANCE_FILE=' . $flagPath);
        $previousRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $liveRequest = new Request();
            $liveRequest->setPathInfo('health/live');
            $liveResponse = new Response();
            $this->assertTrue(MaintenanceMode::apply($liveRequest, $liveResponse));
            $this->assertTrue(MaintenanceMode::blockedCurrentRequest());
            $this->assertSame(200, $liveResponse->statusCode);
            $this->assertSame(['status' => 'ok'], $liveResponse->data);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $apiRequest = new Request();
            $apiRequest->setPathInfo('api/v1/auth/login');
            $apiResponse = new Response();
            $this->assertTrue(MaintenanceMode::apply($apiRequest, $apiResponse));
            $this->assertTrue(MaintenanceMode::blockedCurrentRequest());
            $this->assertSame(503, $apiResponse->statusCode);
            $this->assertSame(['error' => 'service unavailable'], $apiResponse->data);
        } finally {
            unlink($flagPath);
            if ($previousRequestMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousRequestMethod;
            }
        }
    }
}
