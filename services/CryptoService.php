<?php

namespace app\services;

use Yii;

class CryptoService
{
    private const PREFIX = 'v1:';

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(12);
        $key = $this->key();
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $value): string
    {
        if ($value === '' || strpos($value, self::PREFIX) !== 0) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    private function key(): string
    {
        // Делаем ключ фиксированной длины AES-256 из SSO_ENCRYPTION_KEY.
        return hash('sha256', Yii::$app->params['encryptionKey'], true);
    }
}
