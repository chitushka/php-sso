<?php

namespace tests\Unit;

use app\services\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function testRejectsPasswordShorterThanTwelveCharacters(): void
    {
        $this->assertSame(
            'password must contain at least 12 characters',
            PasswordPolicy::validate('short')
        );
    }

    public function testAcceptsBoundaryLengths(): void
    {
        $this->assertNull(PasswordPolicy::validate(str_repeat('a', 12)));
        $this->assertNull(PasswordPolicy::validate(str_repeat('a', 128)));
    }

    public function testRejectsPasswordLongerThanOneHundredTwentyEightCharacters(): void
    {
        $this->assertSame(
            'password must not exceed 128 characters',
            PasswordPolicy::validate(str_repeat('a', 129))
        );
    }
}
