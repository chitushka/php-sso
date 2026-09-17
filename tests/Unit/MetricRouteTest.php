<?php

namespace tests\Unit;

use app\components\MetricRoute;
use PHPUnit\Framework\TestCase;

class MetricRouteTest extends TestCase
{
    public function testStaticRouteUsesPublicHttpPath(): void
    {
        $this->assertSame(
            '/api/v1/auth/login',
            MetricRoute::label('auth/login', 'api/v1/auth/login')
        );
    }

    public function testDynamicRoutesUseGoParameterNames(): void
    {
        $this->assertSame(
            '/api/v1/oauth/clients/{id}',
            MetricRoute::label(
                'oauth/get-client',
                'api/v1/oauth/clients/00000000-0000-0000-0000-000000000000'
            )
        );
        $this->assertSame(
            '/api/v1/users/{userID}/roles/{roleID}',
            MetricRoute::label('rbac/remove-user-role', 'api/v1/users/user-id/roles/role-id')
        );
        $this->assertSame(
            '/oauth2/broker/{code}/callback',
            MetricRoute::label('broker/callback', 'oauth2/broker/example/callback')
        );
    }

    public function testSpaFallbackUsesGoOtherLabel(): void
    {
        $this->assertSame('other', MetricRoute::label('admin/index', 'unknown/path'));
        $this->assertSame('other', MetricRoute::label('admin/index', ''));
    }
}
