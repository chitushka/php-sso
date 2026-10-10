<?php

$root = dirname(__DIR__, 2);
$documents = [
    $root . '/contracts/openapi-admin.json',
    $root . '/contracts/openapi-oauth.json',
];
$methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
$operations = [];
$operationIds = [];

$normalizePath = static function (string $path): string {
    $path = '/' . ltrim($path, '/');
    $path = preg_replace('/<[^>]+>/', '{}', $path);
    return preg_replace('/\{[^}]+\}/', '{}', $path);
};

$resolvePointer = static function (array $document, string $reference) {
    if (strpos($reference, '#/') !== 0) {
        throw new RuntimeException('Only local OpenAPI references are allowed: ' . $reference);
    }
    $value = $document;
    foreach (explode('/', substr($reference, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            throw new RuntimeException('Broken OpenAPI reference: ' . $reference);
        }
        $value = $value[$segment];
    }
    return $value;
};

foreach ($documents as $file) {
    try {
        $document = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(basename($file) . ': ' . $exception->getMessage(), 0, $exception);
    }
    if (!preg_match('/^3\.1\./', (string)($document['openapi'] ?? ''))) {
        throw new RuntimeException(basename($file) . ' must use OpenAPI 3.1');
    }
    if (($document['jsonSchemaDialect'] ?? '') !== 'https://json-schema.org/draft/2020-12/schema') {
        throw new RuntimeException(basename($file) . ' must declare JSON Schema 2020-12');
    }
    if (!is_array($document['paths'] ?? null) || $document['paths'] === []) {
        throw new RuntimeException(basename($file) . ' has no paths');
    }

    $walk = static function ($value) use (&$walk, $document, $resolvePointer): void {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if ($key === '$ref' && is_string($item)) {
                $resolvePointer($document, $item);
            } else {
                $walk($item);
            }
        }
    };
    $walk($document);

    foreach ($document['paths'] as $path => $pathItem) {
        if (!is_string($path) || strpos($path, '/') !== 0 || !is_array($pathItem)) {
            throw new RuntimeException(basename($file) . ' contains an invalid path');
        }
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $pathParameters = array_merge($pathItem['parameters'] ?? [], []);
        foreach ($methods as $method) {
            if (!isset($pathItem[$method])) {
                continue;
            }
            $operation = $pathItem[$method];
            $operationId = (string)($operation['operationId'] ?? '');
            if ($operationId === '' || isset($operationIds[$operationId])) {
                throw new RuntimeException('Missing or duplicate operationId at ' . strtoupper($method) . ' ' . $path);
            }
            if (!is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                throw new RuntimeException('Missing responses at ' . strtoupper($method) . ' ' . $path);
            }
            $parameters = array_merge($pathParameters, $operation['parameters'] ?? []);
            foreach ($matches[1] as $name) {
                $found = false;
                foreach ($parameters as $parameter) {
                    if (isset($parameter['$ref'])) {
                        $parameter = $resolvePointer($document, $parameter['$ref']);
                    }
                    if (($parameter['in'] ?? '') === 'path' && ($parameter['name'] ?? '') === $name && ($parameter['required'] ?? false)) {
                        $found = true;
                    }
                }
                if (!$found) {
                    throw new RuntimeException('Undeclared path parameter {' . $name . '} at ' . $path);
                }
            }
            $operationIds[$operationId] = true;
            $operations[strtoupper($method) . ' ' . $normalizePath($path)] = basename($file);
        }
    }
}

$router = (string)file_get_contents($root . '/config/web.php');
preg_match_all(
    "/'(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) ((?:api\/v1|oauth2|\\.well-known)[^']*)'\s*=>/",
    $router,
    $matches,
    PREG_SET_ORDER
);
$routes = [];
foreach ($matches as $match) {
    $routes[$match[1] . ' ' . $normalizePath($match[2])] = true;
}

$missing = array_diff_key($routes, $operations);
$stale = array_diff_key($operations, $routes);
if ($missing !== [] || $stale !== []) {
    throw new RuntimeException(
        'OpenAPI/router drift. Missing: ' . implode(', ', array_keys($missing))
        . '; stale: ' . implode(', ', array_keys($stale))
    );
}

echo 'OpenAPI 3.1 contracts match ' . count($routes) . " HTTP routes\n";
