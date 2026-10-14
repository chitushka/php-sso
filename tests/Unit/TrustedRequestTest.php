<?php

namespace tests\Unit;

use app\components\TrustedRequest;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Application;

class TrustedRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Yii::$app === null) {
            new Application([
                'id' => 'sso-unit',
                'basePath' => dirname(__DIR__, 2),
                'components' => [
                    'request' => \yii\web\Request::class,
                ],
                'params' => [
                    'trustedProxies' => '',
                ],
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ([
            'HTTPS',
            'HTTP_HOST',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED_HOST',
            'HTTP_X_FORWARDED_PROTO',
            'HTTP_X_FORWARDED_PORT',
            'REMOTE_ADDR',
            'SERVER_PORT',
        ] as $name) {
            unset($_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function testUntrustedPeerIgnoresForwardedFor(): void
    {
        $this->assertSame(
            '198.51.100.10',
            $this->resolvedIp('198.51.100.10', '203.0.113.7', '')
        );
    }

    public function testTrustedProxySelectsRightmostUntrustedForwardedIp(): void
    {
        $this->assertSame(
            '203.0.113.7',
            $this->resolvedIp('10.10.1.5', '203.0.113.7, 10.10.1.4', '10.10.0.0/16')
        );
    }

    public function testTrustedProxySupportsIpv6Cidr(): void
    {
        $this->assertSame(
            '2001:db8::15',
            $this->resolvedIp('fd00::1', '2001:db8::15, fd00::2', 'fd00::/8')
        );
    }

    public function testPeerAddressWithPortIsNormalizedBeforeTrustLookup(): void
    {
        $this->assertSame(
            '203.0.113.7',
            $this->resolvedIp('198.51.100.10:52311', '203.0.113.7', '198.51.100.0/24')
        );
    }

    /**
     * @dataProvider invalidCidrProvider
     */
    public function testInvalidOrNonCanonicalTrustedProxyCidrIsRejected(string $cidr): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->resolvedIp('10.0.0.1', '203.0.113.7', $cidr);
    }

    public function invalidCidrProvider(): array
    {
        return [
            'IPv4 prefix overflow' => ['10.0.0.0/33'],
            'IPv6 prefix overflow' => ['2001:db8::/129'],
            'negative prefix' => ['10.0.0.0/-1'],
            'non-numeric prefix' => ['10.0.0.0/example'],
            'IPv4 host bits' => ['10.0.0.1/24'],
            'IPv6 host bits' => ['2001:db8::1/64'],
        ];
    }

    public function testInvalidForwardedAddressIsNeverReturnedAsClientIp(): void
    {
        $this->assertSame(
            '198.51.100.10',
            $this->resolvedIp('198.51.100.10', 'not-an-ip', '198.51.100.0/24')
        );
    }

    public function testTrustedProxyProvidesForwardedSchemeHostAndPort(): void
    {
        $request = $this->forwardedRequest('10.10.1.5', '10.10.0.0/16');

        $this->assertTrue($request->isSecureConnection);
        $this->assertSame('https://sso.example.test:8443', $request->hostInfo);
        $this->assertSame(8443, $request->serverPort);
        $this->assertSame('203.0.113.7', $request->userIP);
    }

    public function testUntrustedPeerCannotSpoofForwardedSchemeHostOrPort(): void
    {
        $request = $this->forwardedRequest('198.51.100.10', '10.10.0.0/16');

        $this->assertFalse($request->isSecureConnection);
        $this->assertSame('http://internal:8080', $request->hostInfo);
        $this->assertSame(8080, $request->serverPort);
        $this->assertSame('198.51.100.10', $request->userIP);
    }

    private function resolvedIp(string $remoteAddr, string $forwardedFor, string $trustedProxies): string
    {
        Yii::$app->params['trustedProxies'] = $trustedProxies;
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;

        $request = new TrustedRequest([
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
            'trustedHosts' => TrustedRequest::trustedHostsFromCsv($trustedProxies),
        ]);
        $request->headers->set('X-Forwarded-For', $forwardedFor);

        return $request->getUserIP();
    }

    private function forwardedRequest(string $remoteAddr, string $trustedProxies): TrustedRequest
    {
        Yii::$app->params['trustedProxies'] = $trustedProxies;
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['HTTP_HOST'] = 'internal:8080';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.10.1.4';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'sso.example.test:8443';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '8443';
        unset($_SERVER['HTTPS']);

        return new TrustedRequest([
            'cookieValidationKey' => 'unit-test-cookie-validation-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
            'trustedHosts' => TrustedRequest::trustedHostsFromCsv($trustedProxies),
        ]);
    }
}
