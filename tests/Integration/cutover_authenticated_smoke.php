<?php

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

/**
 * Завершает проверку с ошибкой, не раскрывая HTTP body с токенами или секретами.
 */
function cutoverFail(string $message): void
{
    fwrite(STDERR, "FAIL: " . $message . "\n");
    exit(1);
}

function cutoverAssert($condition, string $message): void
{
    if (!$condition) {
        cutoverFail($message);
    }
}

function cutoverEnv(string $name, bool $required = true): string
{
    $value = trim((string)(getenv($name) ?: ''));
    if ($required && $value === '') {
        cutoverFail($name . ' is required');
    }
    return $value;
}

function cutoverEnvBool(string $name): bool
{
    $value = strtolower(cutoverEnv($name, false));
    if ($value === '' || in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    cutoverFail($name . ' must be true or false');
}

function cutoverRequest(string $method, string $url, bool $tlsInsecure, array $options = []): array
{
    $headers = $options['headers'] ?? [];
    $cookies = $options['cookies'] ?? [];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $options['body'] ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 15,
        ],
        'ssl' => [
            'verify_peer' => !$tlsInsecure,
            'verify_peer_name' => !$tlsInsecure,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $location = '';
    $setCookies = [];
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int)$matches[1];
        } elseif (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        } elseif (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $matches)) {
            $setCookies[$matches[1]] = $matches[2];
        }
    }

    $json = json_decode((string)$raw, true);
    return [
        'status' => $status,
        'location' => $location,
        'cookies' => $setCookies,
        'json' => is_array($json) ? $json : null,
    ];
}

function cutoverJsonRequest(
    string $method,
    string $url,
    bool $tlsInsecure,
    array $payload,
    array $headers = [],
    array $cookies = []
): array {
    $json = json_encode($payload);
    cutoverAssert($json !== false, 'cannot encode JSON request');
    return cutoverRequest($method, $url, $tlsInsecure, [
        'headers' => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        'body' => $json,
        'cookies' => $cookies,
    ]);
}

function cutoverFormRequest(
    string $url,
    bool $tlsInsecure,
    array $payload,
    array $headers = []
): array {
    return cutoverRequest('POST', $url, $tlsInsecure, [
        'headers' => array_merge(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $headers),
        'body' => http_build_query($payload),
    ]);
}

function cutoverClientAuthentication(string $clientId, string $clientSecret, array &$payload): array
{
    if ($clientSecret === '') {
        $payload['client_id'] = $clientId;
        return [];
    }

    return ['Authorization: Basic ' . base64_encode(urlencode($clientId) . ':' . urlencode($clientSecret))];
}

/**
 * Для Docker-репетиции заменяет только проверенный public issuer на внутренний transport URL.
 */
function cutoverTransportUrl(string $publicUrl, string $expectedIssuer, string $baseUrl): string
{
    cutoverAssert(strpos($publicUrl, $expectedIssuer . '/') === 0, 'Discovery endpoint uses an unexpected issuer');
    return $baseUrl . substr($publicUrl, strlen($expectedIssuer));
}

$baseUrl = rtrim(cutoverEnv('SSO_TEST_BASE_URL', false), '/');
if ($baseUrl === '') {
    $baseUrl = rtrim(cutoverEnv('SSO_ISSUER'), '/');
}
$expectedIssuer = rtrim(cutoverEnv('SSO_TEST_EXPECTED_ISSUER', false), '/');
if ($expectedIssuer === '') {
    $expectedIssuer = rtrim(cutoverEnv('SSO_ISSUER'), '/');
}
$username = cutoverEnv('SSO_CUTOVER_SMOKE_USERNAME');
$password = cutoverEnv('SSO_CUTOVER_SMOKE_PASSWORD');
$mfaCode = cutoverEnv('SSO_CUTOVER_SMOKE_MFA_CODE', false);
$clientId = cutoverEnv('SSO_CUTOVER_SMOKE_CLIENT_ID');
$clientSecret = cutoverEnv('SSO_CUTOVER_SMOKE_CLIENT_SECRET', false);
$redirectUri = cutoverEnv('SSO_CUTOVER_SMOKE_REDIRECT_URI');
$logoutRedirectUri = cutoverEnv('SSO_CUTOVER_SMOKE_LOGOUT_REDIRECT_URI');
$scope = cutoverEnv('SSO_CUTOVER_SMOKE_SCOPE', false) ?: 'openid profile email';
$grantConsent = cutoverEnvBool('SSO_CUTOVER_SMOKE_GRANT_CONSENT');
$tlsInsecure = cutoverEnvBool('SSO_TEST_TLS_INSECURE');

cutoverAssert(parse_url($baseUrl, PHP_URL_SCHEME) === 'https', 'authenticated cutover smoke must use HTTPS');
cutoverAssert(parse_url($expectedIssuer, PHP_URL_SCHEME) === 'https', 'expected issuer must use HTTPS');
cutoverAssert(in_array('openid', preg_split('/\s+/', trim($scope)), true), 'smoke scope must contain openid');
if ($tlsInsecure) {
    $host = strtolower((string)parse_url($expectedIssuer, PHP_URL_HOST));
    cutoverAssert(
        in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        'TLS verification can be disabled only for a loopback issuer'
    );
}

$discovery = cutoverRequest('GET', $baseUrl . '/.well-known/openid-configuration', $tlsInsecure, [
    'headers' => ['Accept: application/json'],
]);
cutoverAssert($discovery['status'] === 200, 'OIDC Discovery must return HTTP 200');
cutoverAssert(is_array($discovery['json']), 'OIDC Discovery must return a JSON object');
cutoverAssert(($discovery['json']['issuer'] ?? '') === $expectedIssuer, 'OIDC issuer differs from the expected issuer');
foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'end_session_endpoint'] as $endpoint) {
    cutoverAssert(is_string($discovery['json'][$endpoint] ?? null), 'Discovery is missing ' . $endpoint);
    cutoverAssert(strpos($discovery['json'][$endpoint], $expectedIssuer . '/') === 0, $endpoint . ' uses an unexpected issuer');
}
$authorizationEndpoint = cutoverTransportUrl(
    $discovery['json']['authorization_endpoint'],
    $expectedIssuer,
    $baseUrl
);
$tokenEndpoint = cutoverTransportUrl($discovery['json']['token_endpoint'], $expectedIssuer, $baseUrl);
$userinfoEndpoint = cutoverTransportUrl($discovery['json']['userinfo_endpoint'], $expectedIssuer, $baseUrl);
$logoutEndpoint = cutoverTransportUrl($discovery['json']['end_session_endpoint'], $expectedIssuer, $baseUrl);

$login = cutoverJsonRequest('POST', $baseUrl . '/api/v1/auth/login', $tlsInsecure, [
    'username' => $username,
    'password' => $password,
]);
cutoverAssert($login['status'] === 200, 'local login must return HTTP 200');
if (($login['json']['mfa_required'] ?? false) === true) {
    cutoverAssert($mfaCode !== '', 'SSO_CUTOVER_SMOKE_MFA_CODE is required for this user');
    cutoverAssert(is_string($login['json']['mfa_token'] ?? null), 'MFA login did not return mfa_token');
    $login = cutoverJsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', $tlsInsecure, [
        'mfa_token' => $login['json']['mfa_token'],
        'code' => $mfaCode,
    ]);
    cutoverAssert($login['status'] === 200, 'MFA verification must return HTTP 200');
}

cutoverAssert(is_string($login['json']['access_token'] ?? null), 'login did not return access_token');
cutoverAssert(is_string($login['cookies']['sso_session'] ?? null), 'login did not set sso_session');
$accessToken = $login['json']['access_token'];
$sessionCookie = ['sso_session' => $login['cookies']['sso_session']];

$me = cutoverRequest('GET', $baseUrl . '/api/v1/auth/me', $tlsInsecure, [
    'headers' => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
]);
cutoverAssert($me['status'] === 200, '/api/v1/auth/me must return HTTP 200');
cutoverAssert(($me['json']['username'] ?? '') === $username, '/api/v1/auth/me returned another user');

$suffix = bin2hex(random_bytes(8));
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = 'cutover-' . $suffix;
$nonce = 'cutover-' . bin2hex(random_bytes(8));
$authorizeUrl = $authorizationEndpoint . '?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scope,
    'state' => $state,
    'nonce' => $nonce,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
]);
$authorize = cutoverRequest('GET', $authorizeUrl, $tlsInsecure, ['cookies' => $sessionCookie]);
if ($authorize['status'] === 403 && ($authorize['json']['error'] ?? '') === 'consent_required') {
    cutoverAssert(
        $grantConsent,
        'OAuth consent is required; pre-grant it or set SSO_CUTOVER_SMOKE_GRANT_CONSENT=true'
    );
    $consent = cutoverJsonRequest(
        'POST',
        $baseUrl . '/oauth2/consent',
        $tlsInsecure,
        ['client_id' => $clientId, 'scope' => $scope],
        [],
        $sessionCookie
    );
    cutoverAssert($consent['status'] === 200, 'OAuth consent grant must return HTTP 200');
    $authorize = cutoverRequest('GET', $authorizeUrl, $tlsInsecure, ['cookies' => $sessionCookie]);
}
cutoverAssert($authorize['status'] >= 300 && $authorize['status'] < 400, 'authorize must redirect to the client');
cutoverAssert(strpos($authorize['location'], $redirectUri) === 0, 'authorize redirected to an unexpected URI');
$callback = parse_url($authorize['location']);
parse_str($callback['query'] ?? '', $callbackQuery);
cutoverAssert(is_string($callbackQuery['code'] ?? null), 'authorize callback did not contain code');
cutoverAssert(($callbackQuery['state'] ?? '') === $state, 'authorize callback did not preserve state');

$tokenPayload = [
    'grant_type' => 'authorization_code',
    'code' => $callbackQuery['code'],
    'redirect_uri' => $redirectUri,
    'code_verifier' => $verifier,
];
$tokenHeaders = cutoverClientAuthentication($clientId, $clientSecret, $tokenPayload);
$token = cutoverFormRequest($tokenEndpoint, $tlsInsecure, $tokenPayload, $tokenHeaders);
cutoverAssert($token['status'] === 200, 'authorization code exchange must return HTTP 200');
foreach (['access_token', 'refresh_token', 'id_token'] as $field) {
    cutoverAssert(is_string($token['json'][$field] ?? null), 'token response is missing ' . $field);
}

$userinfo = cutoverRequest('GET', $userinfoEndpoint, $tlsInsecure, [
    'headers' => ['Authorization: Bearer ' . $token['json']['access_token'], 'Accept: application/json'],
]);
cutoverAssert($userinfo['status'] === 200, 'UserInfo must return HTTP 200');
cutoverAssert(is_string($userinfo['json']['sub'] ?? null), 'UserInfo did not return sub');

$refreshPayload = [
    'grant_type' => 'refresh_token',
    'refresh_token' => $token['json']['refresh_token'],
];
$refreshHeaders = cutoverClientAuthentication($clientId, $clientSecret, $refreshPayload);
$refresh = cutoverFormRequest($tokenEndpoint, $tlsInsecure, $refreshPayload, $refreshHeaders);
cutoverAssert($refresh['status'] === 200, 'refresh token exchange must return HTTP 200');
cutoverAssert(is_string($refresh['json']['access_token'] ?? null), 'refresh response is missing access_token');
cutoverAssert(is_string($refresh['json']['refresh_token'] ?? null), 'refresh response is missing refresh_token');
cutoverAssert($refresh['json']['refresh_token'] !== $token['json']['refresh_token'], 'refresh token was not rotated');

$logoutState = 'cutover-logout-' . $suffix;
$logout = cutoverRequest(
    'GET',
    $logoutEndpoint . '?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => $logoutRedirectUri,
        'state' => $logoutState,
    ]),
    $tlsInsecure,
    ['cookies' => $sessionCookie]
);
cutoverAssert($logout['status'] >= 300 && $logout['status'] < 400, 'OIDC logout must redirect to the client');
cutoverAssert(strpos($logout['location'], $logoutRedirectUri) === 0, 'OIDC logout redirected to an unexpected URI');
$logoutLocation = parse_url($logout['location']);
parse_str($logoutLocation['query'] ?? '', $logoutQuery);
cutoverAssert(($logoutQuery['state'] ?? '') === $logoutState, 'OIDC logout did not preserve state');

fwrite(STDOUT, "Authenticated cutover smoke passed\n");
fwrite(STDOUT, "Verified login, MFA when enabled, /auth/me, Authorization Code + PKCE, UserInfo, refresh rotation and OIDC logout\n");
