<?php

namespace tests\Unit;

use app\services\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    public function testMachinePermissionMatrixIsExplicitAndFailClosed(): void
    {
        $authorization = new AuthorizationService();

        $this->assertTrue($authorization->machineClientAllows('operations', 'read'));
        $this->assertTrue($authorization->machineClientAllows('signing_keys', 'rotate'));
        $this->assertTrue($authorization->machineClientAllows('ldap', 'test'));
        $this->assertFalse($authorization->machineClientAllows('users', 'create'));
        $this->assertFalse($authorization->machineClientAllows('federated_identities', 'delete'));
        $this->assertFalse($authorization->machineClientAllows('unknown', 'read'));
    }
}
