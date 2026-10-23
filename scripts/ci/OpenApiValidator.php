<?php

final class OpenApiValidator
{
    private array $document;

    public function __construct(string $file)
    {
        try {
            $this->document = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(basename($file) . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function validateResponse(string $method, string $path, int $status, $body): void
    {
        $operation = $this->operation($method, $path);
        $response = $operation['responses'][(string)$status] ?? $operation['responses']['default'] ?? null;
        if (!is_array($response)) {
            throw new RuntimeException(sprintf('%s %s does not declare response %d', strtoupper($method), $path, $status));
        }
        $response = $this->resolve($response);
        $schema = $response['content']['application/json']['schema'] ?? null;
        if (!is_array($schema)) {
            if ($body !== null && $body !== '') {
                throw new RuntimeException(sprintf('%s %s response %d does not declare a JSON body', strtoupper($method), $path, $status));
            }
            return;
        }
        $this->validate($body, $schema, '$response');
    }

    public function validateRequest(string $method, string $path, string $contentType, $body): void
    {
        $operation = $this->operation($method, $path);
        $requestBody = $operation['requestBody'] ?? null;
        if (!is_array($requestBody)) {
            throw new RuntimeException(sprintf('%s %s does not declare a request body', strtoupper($method), $path));
        }
        $requestBody = $this->resolve($requestBody);
        $schema = $requestBody['content'][$contentType]['schema'] ?? null;
        if (!is_array($schema)) {
            throw new RuntimeException(sprintf('%s %s does not accept %s', strtoupper($method), $path, $contentType));
        }
        $this->validate($body, $schema, '$request');
    }

    private function operation(string $method, string $path): array
    {
        $method = strtolower($method);
        $operation = $this->document['paths'][$path][$method] ?? null;
        if (!is_array($operation)) {
            throw new RuntimeException(sprintf('OpenAPI operation not found: %s %s', strtoupper($method), $path));
        }
        return $operation;
    }

    private function validate($value, array $schema, string $pointer): void
    {
        $schema = $this->resolve($schema);
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $candidate) {
                try {
                    $this->validate($value, $candidate, $pointer);
                    $matches++;
                } catch (RuntimeException $exception) {
                    // Ошибки отдельных веток ожидаемы: oneOf требует ровно одно совпадение.
                }
            }
            if ($matches !== 1) {
                throw new RuntimeException($pointer . ' must match exactly one oneOf schema');
            }
            return;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            throw new RuntimeException($pointer . ' does not match const');
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new RuntimeException($pointer . ' is not in enum');
        }
        if (isset($schema['type']) && !$this->matchesType($value, $schema['type'])) {
            throw new RuntimeException($pointer . ' has an invalid type');
        }

        if ($this->hasType($schema, 'object') && is_array($value)) {
            if ($value !== [] && array_is_list($value)) {
                throw new RuntimeException($pointer . ' must be an object');
            }
            foreach ($schema['required'] ?? [] as $property) {
                if (!array_key_exists($property, $value)) {
                    throw new RuntimeException($pointer . '.' . $property . ' is required');
                }
            }
            $properties = $schema['properties'] ?? [];
            foreach ($value as $property => $item) {
                if (isset($properties[$property])) {
                    $this->validate($item, $properties[$property], $pointer . '.' . $property);
                    continue;
                }
                $additional = $schema['additionalProperties'] ?? true;
                if ($additional === false) {
                    throw new RuntimeException($pointer . '.' . $property . ' is not allowed');
                }
                if (is_array($additional)) {
                    $this->validate($item, $additional, $pointer . '.' . $property);
                }
            }
        }

        if ($this->hasType($schema, 'array') && is_array($value)) {
            if (!array_is_list($value)) {
                throw new RuntimeException($pointer . ' must be an array');
            }
            if (isset($schema['items'])) {
                foreach ($value as $index => $item) {
                    $this->validate($item, $schema['items'], $pointer . '[' . $index . ']');
                }
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                throw new RuntimeException($pointer . ' is shorter than minLength');
            }
            if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~D', $value) !== 1) {
                throw new RuntimeException($pointer . ' does not match pattern');
            }
            if (($schema['format'] ?? null) === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException($pointer . ' must be an email');
            }
            if (($schema['format'] ?? null) === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException($pointer . ' must be a URI');
            }
            if (($schema['format'] ?? null) === 'uuid'
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) !== 1) {
                throw new RuntimeException($pointer . ' must be a UUID');
            }
        }
    }

    private function matchesType($value, $types): bool
    {
        foreach ((array)$types as $type) {
            if (($type === 'null' && $value === null)
                || ($type === 'object' && is_array($value) && ($value === [] || !array_is_list($value)))
                || ($type === 'array' && is_array($value) && array_is_list($value))
                || ($type === 'string' && is_string($value))
                || ($type === 'integer' && is_int($value))
                || ($type === 'number' && (is_int($value) || is_float($value)))
                || ($type === 'boolean' && is_bool($value))) {
                return true;
            }
        }
        return false;
    }

    private function hasType(array $schema, string $expected): bool
    {
        return in_array($expected, (array)($schema['type'] ?? []), true);
    }

    private function resolve(array $value): array
    {
        if (!isset($value['$ref'])) {
            return $value;
        }
        $reference = $value['$ref'];
        if (!is_string($reference) || strpos($reference, '#/') !== 0) {
            throw new RuntimeException('Only local OpenAPI references are supported: ' . (string)$reference);
        }
        $resolved = $this->document;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($resolved) || !array_key_exists($segment, $resolved)) {
                throw new RuntimeException('Broken OpenAPI reference: ' . $reference);
            }
            $resolved = $resolved[$segment];
        }
        if (!is_array($resolved)) {
            throw new RuntimeException('OpenAPI reference does not resolve to an object: ' . $reference);
        }
        return $resolved;
    }
}
