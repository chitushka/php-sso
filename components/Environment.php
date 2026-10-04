<?php

namespace app\components;

final class Environment
{
    public static function value(string $name, ?string $default = null): ?string
    {
        $direct = getenv($name);
        $file = getenv($name . '_FILE');
        if ($direct !== false && $direct !== '' && $file !== false && $file !== '') {
            throw new \RuntimeException($name . ' and ' . $name . '_FILE cannot be used together');
        }
        if ($file !== false && $file !== '') {
            if (!is_file($file) || !is_readable($file)) {
                throw new \RuntimeException($name . '_FILE is not readable');
            }
            $value = file_get_contents($file);
            if ($value === false) {
                throw new \RuntimeException($name . '_FILE cannot be read');
            }
            return preg_replace('/(?:\r\n|\r|\n)\z/', '', $value);
        }

        return $direct === false || $direct === '' ? $default : $direct;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = self::value($name);
        if ($value === null) {
            return $default;
        }
        $normalized = strtolower($value);
        if (!in_array($normalized, ['true', 'false', '1', '0'], true)) {
            throw new \RuntimeException($name . ' must be a boolean');
        }

        return $normalized === 'true' || $normalized === '1';
    }

    public static function int(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = self::value($name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^\d+$/D', $value) !== 1) {
            throw new \RuntimeException($name . ' must be an integer');
        }
        $integer = (int)$value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new \RuntimeException($name . " must be between {$minimum} and {$maximum}");
        }

        return $integer;
    }
}
