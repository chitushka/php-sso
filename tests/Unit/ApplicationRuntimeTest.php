<?php

namespace tests\Unit;

use app\services\ApplicationRuntime;
use app\services\contracts\ApplicationRuntimeInterface;
use PHPUnit\Framework\TestCase;
use yii\db\Connection;
use yii\web\Request;

final class ApplicationRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        ApplicationRuntime::replace(null);
        parent::tearDown();
    }

    public function testApplicationServicesCanUseAnIsolatedRuntime(): void
    {
        $runtime = new class implements ApplicationRuntimeInterface {
            private array $state = [];

            public function db(): Connection
            {
                return new Connection();
            }

            public function param(string $name, mixed $default = null): mixed
            {
                return ['issuer' => 'https://issuer.example.test'][$name] ?? $default;
            }

            public function setState(string $name, mixed $value): void
            {
                $this->state[$name] = $value;
            }

            public function state(string $name, mixed $default = null): mixed
            {
                return $this->state[$name] ?? $default;
            }

            public function request(): Request
            {
                return new Request();
            }

            public function webRequest(): ?Request
            {
                return null;
            }

            public function requestedRoute(): string
            {
                return 'test/runtime';
            }
        };

        ApplicationRuntime::replace($runtime);
        ApplicationRuntime::setState('startedAt', 42.5);

        $this->assertSame('https://issuer.example.test', ApplicationRuntime::param('issuer'));
        $this->assertSame('fallback', ApplicationRuntime::param('missing', 'fallback'));
        $this->assertSame(42.5, ApplicationRuntime::state('startedAt'));
        $this->assertSame('test/runtime', ApplicationRuntime::requestedRoute());
        $this->assertNull(ApplicationRuntime::webRequest());
    }
}
