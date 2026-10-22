<?php

require_once dirname(__DIR__, 2) . '/scripts/ci/OpenApiValidator.php';

$baseUrl = rtrim((string)(getenv('SSO_TEST_BASE_URL') ?: getenv('SSO_ISSUER')), '/');
$expectedIssuer = rtrim((string)(getenv('SSO_TEST_EXPECTED_ISSUER') ?: getenv('SSO_ISSUER')), '/');
$tlsInsecure = filter_var(getenv('SSO_TEST_TLS_INSECURE') ?: false, FILTER_VALIDATE_BOOLEAN);
$root = dirname(__DIR__, 2);
$adminContract = new OpenApiValidator($root . '/contracts/openapi-admin.json');
$oauthContract = new OpenApiValidator($root . '/contracts/openapi-oauth.json');

function failSmoke(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSmoke($condition, string $message): void
{
    if (!$condition) {
        failSmoke($message);
    }
}

function smokeRequest(string $url, bool $tlsInsecure): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: sso-deployment-smoke/1.0",
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => !$tlsInsecure,
            'verify_peer_name' => !$tlsInsecure,
            'allow_self_signed' => $tlsInsecure,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int)$matches[1];
        }
    }

    if ($body === false && $status === 0) {
        failSmoke('request failed: ' . $url);
    }

    $json = json_decode((string)$body, true);
    return [
        'status' => $status,
        'body' => (string)$body,
        'json' => is_array($json) ? $json : null,
        'headers' => $headers,
    ];
}

function smokeHeader(array $response, string $name): string
{
    foreach ($response['headers'] ?? [] as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return '';
}

function smokeJson(string $baseUrl, string $path, bool $tlsInsecure): array
{
    $response = smokeRequest($baseUrl . $path, $tlsInsecure);
    assertSmoke($response['status'] === 200, $path . ' must return HTTP 200');
    assertSmoke(is_array($response['json']), $path . ' must return a JSON object or array');
    return $response['json'];
}

assertSmoke($baseUrl !== '', 'SSO_TEST_BASE_URL or SSO_ISSUER is required');
assertSmoke($expectedIssuer !== '', 'SSO_TEST_EXPECTED_ISSUER or SSO_ISSUER is required');
assertSmoke(parse_url($baseUrl, PHP_URL_SCHEME) === 'https', 'deployment smoke base URL must use HTTPS');
assertSmoke(parse_url($expectedIssuer, PHP_URL_SCHEME) === 'https', 'expected issuer must use HTTPS');

$expectedHost = strtolower((string)parse_url($expectedIssuer, PHP_URL_HOST));
if ($tlsInsecure) {
    $baseHost = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    assertSmoke(
        in_array($expectedHost, ['localhost', '127.0.0.1', '::1'], true),
        'TLS verification may be disabled only for a loopback issuer'
    );
    assertSmoke(
        in_array($baseHost, ['localhost', '127.0.0.1', '::1', 'https-proxy'], true),
        'TLS verification may be disabled only for the local HTTPS proxy'
    );
}

$hstsProbe = smokeRequest($baseUrl . '/health/live', $tlsInsecure);
assertSmoke($hstsProbe['status'] === 200, 'HTTPS liveness probe must return HTTP 200');
assertSmoke(
    preg_match('/^max-age=\d+(?:;|$)/i', smokeHeader($hstsProbe, 'Strict-Transport-Security')) === 1,
    'production HTTPS response must include HSTS'
);
$live = $hstsProbe['json'];
assertSmoke(is_array($live), '/health/live must return a JSON object');
assertSmoke(($live['status'] ?? '') === 'ok', 'liveness payload must be contract-compliant');

$ready = smokeJson($baseUrl, '/health/ready', $tlsInsecure);
assertSmoke(($ready['status'] ?? '') === 'ready', 'readiness payload must confirm PostgreSQL availability');

$version = smokeJson($baseUrl, '/health/version', $tlsInsecure);
assertSmoke(is_string($version['version'] ?? null) && $version['version'] !== '', 'version must be non-empty');

$bootstrap = smokeJson($baseUrl, '/api/v1/bootstrap/status', $tlsInsecure);
$adminContract->validateResponse('GET', '/api/v1/bootstrap/status', 200, $bootstrap);
assertSmoke(is_bool($bootstrap['initialized'] ?? null), 'bootstrap status must contain a boolean initialized field');

$providers = smokeJson($baseUrl, '/api/v1/broker/providers', $tlsInsecure);
$adminContract->validateResponse('GET', '/api/v1/broker/providers', 200, $providers);
foreach ($providers as $provider) {
    assertSmoke(is_array($provider), 'public identity provider must be an object');
    assertSmoke(isset($provider['code'], $provider['name'], $provider['type']), 'public provider fields are incomplete');
    assertSmoke(!array_key_exists('client_secret', $provider), 'public provider must not expose client_secret');
}

$discovery = smokeJson($baseUrl, '/.well-known/openid-configuration', $tlsInsecure);
$oauthContract->validateResponse('GET', '/.well-known/openid-configuration', 200, $discovery);
assertSmoke(($discovery['issuer'] ?? '') === $expectedIssuer, 'OIDC issuer differs from the expected issuer');

$endpointPaths = [
    'authorization_endpoint' => '/oauth2/authorize',
    'token_endpoint' => '/oauth2/token',
    'userinfo_endpoint' => '/oauth2/userinfo',
    'revocation_endpoint' => '/oauth2/revoke',
    'introspection_endpoint' => '/oauth2/introspect',
    'jwks_uri' => '/.well-known/jwks.json',
    'end_session_endpoint' => '/oauth2/logout',
];
foreach ($endpointPaths as $field => $path) {
    assertSmoke(
        ($discovery[$field] ?? '') === $expectedIssuer . $path,
        'OIDC discovery field ' . $field . ' does not use the expected issuer'
    );
}

assertSmoke(in_array('code', $discovery['response_types_supported'] ?? [], true), 'authorization code flow is missing');
assertSmoke(in_array('RS256', $discovery['id_token_signing_alg_values_supported'] ?? [], true), 'RS256 is missing');

$jwks = smokeJson($baseUrl, '/.well-known/jwks.json', $tlsInsecure);
$oauthContract->validateResponse('GET', '/.well-known/jwks.json', 200, $jwks);
assertSmoke(isset($jwks['keys']) && is_array($jwks['keys']) && count($jwks['keys']) > 0, 'JWKS must publish keys');
$activeSigningKeyFound = false;
foreach ($jwks['keys'] as $key) {
    if (($key['kty'] ?? '') === 'RSA'
        && ($key['use'] ?? '') === 'sig'
        && ($key['alg'] ?? '') === 'RS256'
        && is_string($key['kid'] ?? null)
        && ($key['kid'] ?? '') !== ''
        && is_string($key['n'] ?? null)
        && ($key['n'] ?? '') !== ''
        && is_string($key['e'] ?? null)
        && ($key['e'] ?? '') !== '') {
        $activeSigningKeyFound = true;
        break;
    }
}
assertSmoke($activeSigningKeyFound, 'JWKS does not contain a usable RS256 signing key');

echo "Deployment HTTPS smoke passed\n";
echo "Verified health, readiness, version, bootstrap status, public providers, issuer, discovery and JWKS\n";
