<?php

namespace tests\Unit;

use app\services\PasswordPolicy;
use app\services\PasswordHasher;
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

    public function testHasherUsesGoCompatibleArgon2idParameters(): void
    {
        $hash = PasswordHasher::hash('compatible-password');

        $this->assertStringStartsWith('$argon2id$v=19$m=65536,t=3,p=2$', $hash);
        $this->assertTrue(PasswordHasher::verify('compatible-password', $hash));
        $this->assertFalse(PasswordHasher::verify('wrong-password', $hash));
    }
}
