<?php

namespace app\services;

final class PasswordHasher
{
    private const OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 3,
        'threads' => 2,
    ];

    public static function hash(string $value): string
    {
        // Параметры задают минимальный профиль Argon2id для секретов приложения.
        return password_hash($value, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public static function verify(string $value, string $encoded): bool
    {
        return password_verify($value, $encoded);
    }
}
