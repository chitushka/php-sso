<?php

namespace app\services\results;

/**
 * @property-read string $code
 * @property-read string $message
 * @property-read int $status
 * @property-read array $details
 */
final class ServiceError implements \JsonSerializable
{
    private string $code;
    private string $message;
    private int $status;
    private array $details;

    public function __construct(
        string $code,
        string $message,
        int $status,
        array $details = []
    ) {
        $this->code = $code;
        $this->message = $message;
        $this->status = $status;
        $this->details = $details;
    }

    public function __get(string $name)
    {
        if (!in_array($name, ['code', 'message', 'status', 'details'], true)) {
            throw new \LogicException('Unknown ServiceError property: ' . $name);
        }
        return $this->$name;
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['code', 'message', 'status', 'details'], true);
    }

    public function __set(string $name, $value): void
    {
        throw new \LogicException('ServiceError is immutable');
    }

    public function __unset(string $name): void
    {
        throw new \LogicException('ServiceError is immutable');
    }

    public function payload(): array
    {
        return array_merge(['error' => $this->message], $this->details);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
