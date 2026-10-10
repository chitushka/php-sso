<?php

namespace app\services;

class CryptoService
{
    private const ENCRYPTED_PREFIX = 'enc:v1:';

    /** @var string */
    private $encryptionKey;

    public function __construct(?string $encryptionKey = null)
    {
        $this->encryptionKey = $encryptionKey ?? (string)ApplicationRuntime::param('encryptionKey');
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(12);
        $key = $this->key();
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new \RuntimeException('failed to encrypt secret');
        }

        // Формат хранит nonce, затем ciphertext и authentication tag.
        return self::ENCRYPTED_PREFIX . rtrim(base64_encode($iv . $cipher . $tag), '=');
    }

    public function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strpos($value, self::ENCRYPTED_PREFIX) !== 0) {
            throw new \RuntimeException('plaintext secret is not allowed');
        }

        return $this->decryptEncryptedFormat(substr($value, strlen(self::ENCRYPTED_PREFIX)));
    }

    private function decryptEncryptedFormat(string $encoded): string
    {
        // Формат использует стандартный Base64 alphabet без padding.
        if ($encoded === '' || strpos($encoded, '=') !== false || !preg_match('/^[A-Za-z0-9+\/]+$/', $encoded)) {
            throw new \RuntimeException('invalid encrypted secret');
        }

        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            throw new \RuntimeException('invalid encrypted secret');
        }

        $raw = base64_decode($encoded . str_repeat('=', (4 - $remainder) % 4), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('invalid encrypted secret');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $cipher = substr($raw, 12, -16);
        return $this->open($cipher, $iv, $tag);
    }

    private function open(string $cipher, string $iv, string $tag): string
    {
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('failed to decrypt secret');
        }

        return $plain;
    }

    private function key(): string
    {
        // Делаем ключ фиксированной длины AES-256 из SSO_ENCRYPTION_KEY.
        return hash('sha256', $this->encryptionKey, true);
    }
}
