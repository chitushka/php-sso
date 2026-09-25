<?php

namespace tests\Unit;

use app\services\CryptoService;
use PHPUnit\Framework\TestCase;

class CryptoServiceTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private const GO_VECTOR = 'enc:v1:AAECAwQFBgcICQoLM4E3LdVLwNk7mHkSjuqBalx7gHGfGM3eOHSm0zmbF8IXmfy1mILq/Q';
    private const LEGACY_PHP_VECTOR = 'v1:AAECAwQFBgcICQoLOHSm0zmbF8IXmfy1mILq/TOBNy3VS8DZO5h5Eo7qgWpce4BxnxjN3g==';

    public function testDecryptsCiphertextProducedByGoService(): void
    {
        $this->assertSame(
            'rolling-migration-secret',
            (new CryptoService(self::KEY))->decrypt(self::GO_VECTOR)
        );
    }

    public function testEncryptWritesGoCompatibleWireFormat(): void
    {
        $crypto = new CryptoService(self::KEY);
        $encrypted = $crypto->encrypt('shared-secret');

        $this->assertStringStartsWith('enc:v1:', $encrypted);
        $this->assertStringNotContainsString('=', $encrypted);
        $this->assertSame('shared-secret', $crypto->decrypt($encrypted));
    }

    public function testReadsLegacyPhpCiphertextDuringRollingMigration(): void
    {
        $this->assertSame(
            'rolling-migration-secret',
            (new CryptoService(self::KEY))->decrypt(self::LEGACY_PHP_VECTOR)
        );
    }

    public function testKeepsPreEncryptionPlaintextCompatible(): void
    {
        $this->assertSame('legacy-plaintext', (new CryptoService(self::KEY))->decrypt('legacy-plaintext'));
    }

    public function testRejectsGoCiphertextWithPadding(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CryptoService(self::KEY))->decrypt(self::GO_VECTOR . '=');
    }

    public function testRejectsCiphertextEncryptedWithAnotherKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CryptoService(str_repeat('x', 32)))->decrypt(self::GO_VECTOR);
    }
}
