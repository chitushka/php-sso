<?php

namespace app\services\results;

/**
 * @implements \ArrayAccess<string, mixed>
 *
 * ArrayAccess временно сохраняет совместимость вызовов во время поэтапного перевода use cases.
 */
final class ServiceResult implements \ArrayAccess
{
    private function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly ?ServiceError $error,
        public readonly array $metadata
    ) {
    }

    public static function success(array $data, int $status = 200, array $metadata = []): self
    {
        return new self($status, $data, null, $metadata);
    }

    public static function failure(ServiceError $error): self
    {
        return new self($error->status, $error->payload(), $error, []);
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['status', 'data', 'error', 'metadata'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'status' => $this->status,
            'data' => $this->data,
            'error' => $this->error,
            'metadata' => $this->metadata,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }
}
