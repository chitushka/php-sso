<?php

namespace app\components;

final class ConfigValidator
{
    public static function url(string $name, string $value, bool $httpsOnly = false): string
    {
        $parts = parse_url($value);
        if (
            !is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new \RuntimeException($name . ' must be an absolute URL without credentials or fragment');
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || ($httpsOnly && $scheme !== 'https')) {
            throw new \RuntimeException($name . ($httpsOnly ? ' must use HTTPS' : ' must use HTTP or HTTPS'));
        }

        return rtrim($value, '/');
    }

    public static function oneOf(string $name, string $value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new \RuntimeException($name . ' must be one of: ' . implode(', ', $allowed));
        }

        return $value;
    }

    public static function cidrList(string $name, string $value): string
    {
        foreach (array_filter(array_map('trim', explode(',', $value))) as $cidr) {
            $parts = explode('/', $cidr, 2);
            $packed = @inet_pton($parts[0]);
            if ($packed === false) {
                throw new \RuntimeException($name . ' contains an invalid network address');
            }
            $maximum = strlen($packed) * 8;
            if (
                isset($parts[1])
                && (preg_match('/^\d+$/D', $parts[1]) !== 1 || (int)$parts[1] > $maximum)
            ) {
                throw new \RuntimeException($name . ' contains an invalid prefix length');
            }
        }

        return $value;
    }

    public static function readableFile(string $name, string $value): string
    {
        if ($value !== '' && (!is_file($value) || !is_readable($value))) {
            throw new \RuntimeException($name . ' must point to a readable file');
        }

        return $value;
    }
}
