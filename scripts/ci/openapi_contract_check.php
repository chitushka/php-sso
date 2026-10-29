<?php

$root = dirname(__DIR__, 2);
$documents = [
    $root . '/contracts/openapi-admin.json',
    $root . '/contracts/openapi-oauth.json',
];
$methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
$permissionProtectedTags = ['Users', 'RBAC', 'Audit', 'Operations', 'LDAP', 'Federation', 'OAuth clients'];
$operations = [];
$operationResponses = [];
$operationIds = [];
$operationKeysById = [];
$jsonRequestOperations = [];
$uuidPathOperations = [];
$serverFailureOperations = [];

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
    $securitySchemes = $document['components']['securitySchemes'] ?? [];
    if (!is_array($securitySchemes)) {
        throw new RuntimeException(basename($file) . ' has invalid securitySchemes');
    }

    $validateSecurity = static function ($requirements, string $location) use ($securitySchemes): void {
        if (!is_array($requirements)) {
            throw new RuntimeException('Invalid security requirements at ' . $location);
        }
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                throw new RuntimeException('Invalid security requirement at ' . $location);
            }
            foreach ($requirement as $scheme => $scopes) {
                if (!isset($securitySchemes[$scheme])) {
                    throw new RuntimeException('Unknown security scheme ' . $scheme . ' at ' . $location);
                }
                if (!is_array($scopes)) {
                    throw new RuntimeException('Security scopes must be an array at ' . $location);
                }
            }
        }
    };
    if (array_key_exists('security', $document)) {
        $validateSecurity($document['security'], basename($file));
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
            $location = strtoupper($method) . ' ' . $path;
            $isPublicOperation = array_key_exists('security', $operation) && $operation['security'] === [];
            $tags = is_array($operation['tags'] ?? null) ? $operation['tags'] : [];
            if (
                basename($file) === 'openapi-admin.json'
                && !$isPublicOperation
                && array_intersect($tags, $permissionProtectedTags) !== []
            ) {
                foreach (['401', '403'] as $requiredStatus) {
                    if (!array_key_exists($requiredStatus, $operation['responses'])) {
                        throw new RuntimeException(
                            'Permission-protected operation must declare '
                            . $requiredStatus . ' at ' . $location
                        );
                    }
                }
            }
            if (isset($operation['requestBody'])) {
                $requestBody = $operation['requestBody'];
                if (isset($requestBody['$ref'])) {
                    $requestBody = $resolvePointer($document, $requestBody['$ref']);
                }
                if (isset($requestBody['content']['application/json'])) {
                    $jsonRequestOperations[$operationId] = true;
                }
            }
            if (
                basename($file) === 'openapi-admin.json'
                && $method !== 'get'
                && array_intersect($tags, $permissionProtectedTags) !== []
                && $operationId !== 'testLdapProvider'
            ) {
                $serverFailureOperations[$operationId] = true;
            }
            if (array_key_exists('security', $operation)) {
                $validateSecurity($operation['security'], $location);
            }
            foreach ($operation['responses'] as $status => $response) {
                if ($status !== 'default' && preg_match('/^[1-5](?:\d{2}|XX)$/', (string)$status) !== 1) {
                    throw new RuntimeException('Invalid response status ' . $status . ' at ' . $location);
                }
                if (isset($response['$ref'])) {
                    $response = $resolvePointer($document, $response['$ref']);
                }
                if (!is_array($response) || !is_string($response['description'] ?? null)) {
                    throw new RuntimeException('Invalid response object for status ' . $status . ' at ' . $location);
                }
                foreach ($response['content'] ?? [] as $mediaType => $media) {
                    if (!is_array($media) || !is_array($media['schema'] ?? null)) {
                        throw new RuntimeException('Missing schema for ' . $mediaType . ' response ' . $status . ' at ' . $location);
                    }
                }
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
                        if (($parameter['schema']['$ref'] ?? '') === '#/components/schemas/Uuid') {
                            $uuidPathOperations[$operationId] = true;
                        }
                    }
                }
                if (!$found) {
                    throw new RuntimeException('Undeclared path parameter {' . $name . '} at ' . $path);
                }
            }
            $operationIds[$operationId] = true;
            $operationKey = strtoupper($method) . ' ' . $normalizePath($path);
            $operations[$operationKey] = basename($file);
            $operationResponses[$operationKey] = array_fill_keys(array_keys($operation['responses']), true);
            $operationKeysById[$operationId] = $operationKey;
        }
    }
}

$router = (string)file_get_contents($root . '/config/web.php');
preg_match_all(
    "/'(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) ((?:api\/v1|oauth2|\\.well-known)[^']*)'\s*=>\s*'([^']+)'/",
    $router,
    $matches,
    PREG_SET_ORDER
);
$routes = [];
$routesByTarget = [];
foreach ($matches as $match) {
    $operationKey = $match[1] . ' ' . $normalizePath($match[2]);
    $routes[$operationKey] = true;
    $routesByTarget[$match[3]] = $operationKey;
}

$missing = array_diff_key($routes, $operations);
$stale = array_diff_key($operations, $routes);
if ($missing !== [] || $stale !== []) {
    throw new RuntimeException(
        'OpenAPI/router drift. Missing: ' . implode(', ', array_keys($missing))
        . '; stale: ' . implode(', ', array_keys($stale))
    );
}

$notFoundOperations = array_fill_keys([
    'revokeAccountSession',
    'revokeAccountConsent',
    'getUser',
    'updateUser',
    'deleteUser',
    'listUserFederatedIdentities',
    'unlinkUserFederatedIdentity',
    'updateRole',
    'deleteRole',
    'updateGroup',
    'deleteGroup',
    'updateLdapProvider',
    'deleteLdapProvider',
    'listLdapGroupMappings',
    'createLdapGroupMapping',
    'deleteLdapGroupMapping',
    'updateIdentityProvider',
    'deleteIdentityProvider',
    'getOAuthClient',
    'updateOAuthClient',
    'deleteOAuthClient',
    'rotateOAuthClientSecret',
    'startBrokerLogin',
], true);
$conflictOperations = array_fill_keys([
    'bootstrapSystem',
    'activateMfa',
    'enableMfaAlias',
    'createUser',
    'updateUser',
    'deleteUser',
    'unlinkUserFederatedIdentity',
    'createRole',
    'deleteRole',
    'removeRolePermission',
    'removeUserRole',
    'removeUserGroup',
    'createGroup',
    'deleteGroup',
    'removeGroupRole',
    'createLdapGroupMapping',
    'createIdentityProvider',
    'createOAuthClient',
], true);
$serverFailureOperations += array_fill_keys([
    'login',
    'resetPassword',
    'changePassword',
    'updateProfile',
    'verifyEmail',
    'verifyMfaChallenge',
    'enrollMfa',
    'activateMfa',
    'setupMfaAlias',
    'enableMfaAlias',
    'disableMfa',
    'regenerateMfaRecoveryCodes',
    'revokeAllAccountSessions',
    'revokeAccountSession',
    'revokeAccountConsent',
    'grantConsent',
], true);

$requiredErrorResponses = [
    '400' => $jsonRequestOperations + $uuidPathOperations,
    '404' => $notFoundOperations,
    '409' => $conflictOperations,
    '500' => $serverFailureOperations,
];
$missingErrorResponses = [];
foreach ($requiredErrorResponses as $status => $requiredOperations) {
    foreach (array_keys($requiredOperations) as $operationId) {
        $operationKey = $operationKeysById[$operationId] ?? null;
        if ($operationKey === null) {
            throw new RuntimeException('Error response policy references unknown operationId ' . $operationId);
        }
        if (!isset($operationResponses[$operationKey][$status])) {
            $missingErrorResponses[] = $status . ' ' . $operationId;
        }
    }
}
if ($missingErrorResponses !== []) {
    throw new RuntimeException(
        'Operations are missing applicable error responses: ' . implode(', ', $missingErrorResponses)
    );
}

$params = (string)file_get_contents($root . '/config/params.php');
if (preg_match("/'rateLimitedRoutes'\s*=>\s*\[(.*?)\],/s", $params, $rateLimitBlock) !== 1) {
    throw new RuntimeException('Unable to read rateLimitedRoutes from config/params.php');
}
preg_match_all("/'([^']+)'/", $rateLimitBlock[1], $rateLimitRoutes);
$rateLimitedOperationKeys = [];
foreach ($rateLimitRoutes[1] as $route) {
    $operationKey = $routesByTarget[$route] ?? null;
    if ($operationKey === null) {
        throw new RuntimeException('Rate-limited controller route has no HTTP route: ' . $route);
    }
    if (!isset($operationResponses[$operationKey][429])) {
        throw new RuntimeException('Rate-limited operation must declare 429 at ' . $operationKey);
    }
    $rateLimitedOperationKeys[$operationKey] = true;
}
foreach ($operationResponses as $operationKey => $responses) {
    if (isset($responses[429]) && !isset($rateLimitedOperationKeys[$operationKey])) {
        throw new RuntimeException('Non-rate-limited operation declares 429 at ' . $operationKey);
    }
}

echo 'OpenAPI 3.1 contracts match ' . count($routes) . " HTTP routes\n";
