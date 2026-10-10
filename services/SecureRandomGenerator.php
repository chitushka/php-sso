<?php

namespace app\services;

use app\services\contracts\RandomGeneratorInterface;

final class SecureRandomGenerator implements RandomGeneratorInterface
{
    public function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
