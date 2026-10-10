<?php

namespace tests\Unit;

use app\services\CryptoService;
use PHPUnit\Framework\TestCase;

class CryptoServiceTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const CIPHERTEXT_VECTOR = 'enc:v1:AAECAwQFBgcICQoLM4E3LdVLwNk7mHkSjuqBalx7gHGfGM3eOHSm0zmbF8IXmfy1mILq/Q';

    public function testDecryptsVersionOneCiphertext(): void
    {
        $this->assertSame(
            'rolling-migration-secret',
            (new CryptoService(self::KEY))->decrypt(self::CIPHERTEXT_VECTOR)
        );
    }

    public function testEncryptWritesVersionOneFormat(): void
    {
        $crypto = new CryptoService(self::KEY);
        $encrypted = $crypto->encrypt('shared-secret');

        $this->assertStringStartsWith('enc:v1:', $encrypted);
        $this->assertStringNotContainsString('=', $encrypted);
        $this->assertSame('shared-secret', $crypto->decrypt($encrypted));
    }

    public function testRejectsPlaintext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plaintext secret is not allowed');
        (new CryptoService(self::KEY))->decrypt('plaintext-secret');
    }

    public function testRejectsCiphertextWithPadding(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CryptoService(self::KEY))->decrypt(self::CIPHERTEXT_VECTOR . '=');
    }

    public function testRejectsCiphertextEncryptedWithAnotherKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CryptoService(str_repeat('x', 32)))->decrypt(self::CIPHERTEXT_VECTOR);
    }
}
