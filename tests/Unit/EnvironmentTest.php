<?php

namespace tests\Unit;

use app\components\ConfigValidator;
use app\components\Environment;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    private const NAME = 'SSO_UNIT_ENVIRONMENT_VALUE';

    protected function tearDown(): void
    {
        putenv(self::NAME);
        putenv(self::NAME . '_FILE');
        parent::tearDown();
    }

    public function testSecretCanBeReadFromFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sso-secret-');
        $this->assertNotFalse($path);
        file_put_contents($path, "secret-value\n");
        putenv(self::NAME . '_FILE=' . $path);

        try {
            $this->assertSame('secret-value', Environment::value(self::NAME));
        } finally {
            unlink($path);
        }
    }

    public function testDirectAndFileValuesAreMutuallyExclusive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sso-secret-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'secret-value');
        putenv(self::NAME . '=direct-value');
        putenv(self::NAME . '_FILE=' . $path);

        try {
            $this->expectException(\RuntimeException::class);
            Environment::value(self::NAME);
        } finally {
            unlink($path);
        }
    }

    public function testTypedValuesFailClosed(): void
    {
        putenv(self::NAME . '=sometimes');
        $this->expectException(\RuntimeException::class);
        Environment::bool(self::NAME, false);
    }

    public function testConfigurationValidatorsRejectUnsafeValues(): void
    {
        $this->assertSame(
            '10.0.0.0/8,::1/128',
            ConfigValidator::cidrList('CIDRS', '10.0.0.0/8,::1/128')
        );

        $this->expectException(\RuntimeException::class);
        ConfigValidator::cidrList('CIDRS', '10.0.0.0/99');
    }
}

