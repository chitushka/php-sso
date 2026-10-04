<?php

namespace tests\Unit;

use app\services\LdapService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class LdapServiceSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Yii::$app = null;
        new Application([
            'id' => 'ldap-security-unit',
            'basePath' => dirname(__DIR__, 2),
            'params' => [
                'ldapAllowedHosts' => 'ldap.example.test',
                'ldapAllowInsecure' => false,
                'ldapNetworkTimeout' => 3,
            ],
        ]);
    }

    public function testEndpointPolicyRequiresAllowlistedTlsHost(): void
    {
        $method = new \ReflectionMethod(new LdapService(), 'ldapEndpointAllowed');

        $this->assertTrue($method->invoke(new LdapService(), 'ldap.example.test', true, false));
        $this->assertTrue($method->invoke(new LdapService(), 'LDAP.EXAMPLE.TEST.', false, true));
        $this->assertFalse($method->invoke(new LdapService(), 'evil.example.test', true, false));
        $this->assertFalse($method->invoke(new LdapService(), 'ldap.example.test', false, false));
        $this->assertFalse($method->invoke(new LdapService(), "ldap.example.test\nattacker", true, false));
        $this->assertFalse($method->invoke(new LdapService(), 'ldap.example.test/path', true, false));
    }

    public function testTlsOptionsAreAppliedToConnectionInsteadOfGlobalState(): void
    {
        $connection = ldap_connect('ldap://127.0.0.1:389');
        $this->assertNotFalse($connection);
        $method = new \ReflectionMethod(new LdapService(), 'configureTls');

        $this->assertTrue($method->invoke(new LdapService(), $connection, 'ldap.example.test'));
        $this->assertSame(3, Yii::$app->params['ldapNetworkTimeout']);
    }
}
