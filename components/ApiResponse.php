<?php

namespace app\components;

final class ApiResponse
{
    public static function error(string $message, ?string $code = null, array $details = []): array
    {
        $payload = [
            'error' => $message,
            'code' => $code ?? self::codeFromMessage($message),
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return $payload;
    }

    public static function normalizeError(array $payload): array
    {
        if (!isset($payload['error']) || !is_string($payload['error'])) {
            if (!isset($payload['message']) || !is_string($payload['message'])) {
                return $payload;
            }

            return self::error($payload['message']);
        }

        $payload['code'] = isset($payload['code']) && is_string($payload['code']) && $payload['code'] !== ''
            ? $payload['code']
            : self::codeFromMessage($payload['error']);

        return $payload;
    }

    public static function page(array $items, int $limit, int $offset): array
    {
        return [
            'items' => array_values($items),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
            ],
        ];
    }

    private static function codeFromMessage(string $message): string
    {
        $code = preg_replace('/[^a-z0-9]+/', '_', strtolower($message));
        $code = trim((string)$code, '_');

        return $code !== '' ? $code : 'request_failed';
    }
}
