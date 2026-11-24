<?php

namespace app\services\results;

/**
 * @implements \ArrayAccess<string, mixed>
 * @property-read int $status
 * @property-read array $data
 * @property-read ServiceError|null $error
 * @property-read array $metadata
 *
 * ArrayAccess временно сохраняет совместимость вызовов во время поэтапного перевода use cases.
 */
final class ServiceResult implements \ArrayAccess, \JsonSerializable
{
    private int $status;
    private array $data;
    private ?ServiceError $error;
    private array $metadata;

    private function __construct(
        int $status,
        array $data,
        ?ServiceError $error,
        array $metadata
    ) {
        $this->status = $status;
        $this->data = $data;
        $this->error = $error;
        $this->metadata = $metadata;
    }

    public function __get(string $name)
    {
        if (!$this->offsetExists($name)) {
            throw new \LogicException('Unknown ServiceResult property: ' . $name);
        }
        return $this->$name;
    }

    public function __isset(string $name): bool
    {
        return $this->offsetExists($name) && $this->$name !== null;
    }

    public function __set(string $name, $value): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }

    public function __unset(string $name): void
    {
        throw new \LogicException('ServiceResult is immutable');
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

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }

    public function offsetExists($offset): bool
    {
        return in_array($offset, ['status', 'data', 'error', 'metadata'], true);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->offsetExists($offset) ? $this->$offset : null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }

    public function offsetUnset($offset): void
    {
        throw new \LogicException('ServiceResult is immutable');
    }
}
