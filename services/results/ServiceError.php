<?php

namespace app\services\results;

final class ServiceError
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status,
        public readonly array $details = []
    ) {
    }

    public function payload(): array
    {
        return array_merge(['error' => $this->message], $this->details);
    }
}
