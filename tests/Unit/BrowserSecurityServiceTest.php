<?php

namespace tests\Unit;

use app\services\BrowserSecurityService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\web\Application;
use yii\web\Request;

class BrowserSecurityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Yii::$app !== null) {
            Yii::$app = null;
        }
        new Application([
            'id' => 'browser-security-unit',
            'basePath' => dirname(__DIR__, 2),
            'components' => ['request' => Request::class],
            'params' => ['issuer' => 'https://sso.example.test'],
        ]);
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    public function testCookieAuthenticatedMutationRequiresMatchingTokenAndSameOrigin(): void
    {
        $service = new BrowserSecurityService();
        $request = $this->request(['sso_csrf' => 'token-value']);
        $request->headers->set('X-CSRF-Token', 'token-value');
        $request->headers->set('Origin', 'https://sso.example.test');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        $this->assertTrue($service->requiresCsrf('oauth/grant-consent', $request));
        $this->assertTrue($service->validateCsrf($request));

        $request->headers->set('Origin', 'https://attacker.example');
        $this->assertFalse($service->validateCsrf($request));
        $request->headers->set('Origin', 'https://sso.example.test');
        $request->headers->set('Sec-Fetch-Site', 'cross-site');
        $this->assertFalse($service->validateCsrf($request));
    }

    public function testBearerRequestAndGetDoNotUseDoubleSubmitCsrf(): void
    {
        $service = new BrowserSecurityService();
        $request = $this->request([]);
        $request->headers->set('Authorization', 'Bearer token');
        $this->assertFalse($service->requiresCsrf('auth/logout', $request));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $get = $this->request([]);
        $this->assertFalse($service->requiresCsrf('oauth/logout', $get));
    }

    private function request(array $cookies): Request
    {
        $_COOKIE = $cookies;
        return new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'browser-security-cookie-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
    }
}
