<?php

require_once dirname(__DIR__, 2) . '/scripts/ci/OpenApiValidator.php';

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

/**
 * Завершает проверку с ошибкой, не раскрывая HTTP body с токенами или секретами.
 */
function deploymentFail(string $message): void
{
    fwrite(STDERR, "FAIL: " . $message . "\n");
    exit(1);
}

function deploymentAssert($condition, string $message): void
{
    if (!$condition) {
        deploymentFail($message);
    }
}

function deploymentEnv(string $name, bool $required = true): string
{
    $value = trim((string)(getenv($name) ?: ''));
    if ($required && $value === '') {
        deploymentFail($name . ' is required');
    }
    return $value;
}

function deploymentEnvBool(string $name): bool
{
    $value = strtolower(deploymentEnv($name, false));
    if ($value === '' || in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    deploymentFail($name . ' must be true or false');
}

function deploymentRequest(string $method, string $url, bool $tlsInsecure, array $options = []): array
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

function deploymentJsonRequest(
    string $method,
    string $url,
    bool $tlsInsecure,
    array $payload,
    array $headers = [],
    array $cookies = []
): array {
    $json = json_encode($payload);
    deploymentAssert($json !== false, 'cannot encode JSON request');
    return deploymentRequest($method, $url, $tlsInsecure, [
        'headers' => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        'body' => $json,
        'cookies' => $cookies,
    ]);
}

function deploymentFormRequest(
    string $url,
    bool $tlsInsecure,
    array $payload,
    array $headers = []
): array {
    return deploymentRequest('POST', $url, $tlsInsecure, [
        'headers' => array_merge(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $headers),
        'body' => http_build_query($payload),
    ]);
}

function deploymentClientAuthentication(string $clientId, string $clientSecret, array &$payload): array
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
function deploymentTransportUrl(string $publicUrl, string $expectedIssuer, string $baseUrl): string
{
    deploymentAssert(strpos($publicUrl, $expectedIssuer . '/') === 0, 'Discovery endpoint uses an unexpected issuer');
    return $baseUrl . substr($publicUrl, strlen($expectedIssuer));
}

$baseUrl = rtrim(deploymentEnv('SSO_TEST_BASE_URL', false), '/');
if ($baseUrl === '') {
    $baseUrl = rtrim(deploymentEnv('SSO_ISSUER'), '/');
}
$expectedIssuer = rtrim(deploymentEnv('SSO_TEST_EXPECTED_ISSUER', false), '/');
if ($expectedIssuer === '') {
    $expectedIssuer = rtrim(deploymentEnv('SSO_ISSUER'), '/');
}
$username = deploymentEnv('SSO_DEPLOYMENT_SMOKE_USERNAME');
$password = deploymentEnv('SSO_DEPLOYMENT_SMOKE_PASSWORD');
$mfaCode = deploymentEnv('SSO_DEPLOYMENT_SMOKE_MFA_CODE', false);
$clientId = deploymentEnv('SSO_DEPLOYMENT_SMOKE_CLIENT_ID');
$clientSecret = deploymentEnv('SSO_DEPLOYMENT_SMOKE_CLIENT_SECRET', false);
$redirectUri = deploymentEnv('SSO_DEPLOYMENT_SMOKE_REDIRECT_URI');
$logoutRedirectUri = deploymentEnv('SSO_DEPLOYMENT_SMOKE_LOGOUT_REDIRECT_URI');
$scope = deploymentEnv('SSO_DEPLOYMENT_SMOKE_SCOPE', false) ?: 'openid profile email';
$grantConsent = deploymentEnvBool('SSO_DEPLOYMENT_SMOKE_GRANT_CONSENT');
$tlsInsecure = deploymentEnvBool('SSO_TEST_TLS_INSECURE');
$root = dirname(__DIR__, 2);
$adminContract = new OpenApiValidator($root . '/contracts/openapi-admin.json');
$oauthContract = new OpenApiValidator($root . '/contracts/openapi-oauth.json');

deploymentAssert(parse_url($baseUrl, PHP_URL_SCHEME) === 'https', 'authenticated deployment smoke must use HTTPS');
deploymentAssert(parse_url($expectedIssuer, PHP_URL_SCHEME) === 'https', 'expected issuer must use HTTPS');
deploymentAssert(in_array('openid', preg_split('/\s+/', trim($scope)), true), 'smoke scope must contain openid');
if ($tlsInsecure) {
    $host = strtolower((string)parse_url($expectedIssuer, PHP_URL_HOST));
    deploymentAssert(
        in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        'TLS verification can be disabled only for a loopback issuer'
    );
}

$discovery = deploymentRequest('GET', $baseUrl . '/.well-known/openid-configuration', $tlsInsecure, [
    'headers' => ['Accept: application/json'],
]);
deploymentAssert($discovery['status'] === 200, 'OIDC Discovery must return HTTP 200');
deploymentAssert(is_array($discovery['json']), 'OIDC Discovery must return a JSON object');
deploymentAssert(($discovery['json']['issuer'] ?? '') === $expectedIssuer, 'OIDC issuer differs from the expected issuer');
foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'end_session_endpoint'] as $endpoint) {
    deploymentAssert(is_string($discovery['json'][$endpoint] ?? null), 'Discovery is missing ' . $endpoint);
    deploymentAssert(strpos($discovery['json'][$endpoint], $expectedIssuer . '/') === 0, $endpoint . ' uses an unexpected issuer');
}
$authorizationEndpoint = deploymentTransportUrl(
    $discovery['json']['authorization_endpoint'],
    $expectedIssuer,
    $baseUrl
);
$tokenEndpoint = deploymentTransportUrl($discovery['json']['token_endpoint'], $expectedIssuer, $baseUrl);
$userinfoEndpoint = deploymentTransportUrl($discovery['json']['userinfo_endpoint'], $expectedIssuer, $baseUrl);
$logoutEndpoint = deploymentTransportUrl($discovery['json']['end_session_endpoint'], $expectedIssuer, $baseUrl);

$loginPayload = [
    'username' => $username,
    'password' => $password,
];
$adminContract->validateRequest('POST', '/api/v1/auth/login', 'application/json', $loginPayload);
$login = deploymentJsonRequest('POST', $baseUrl . '/api/v1/auth/login', $tlsInsecure, $loginPayload);
deploymentAssert($login['status'] === 200, 'local login must return HTTP 200');
$adminContract->validateResponse('POST', '/api/v1/auth/login', $login['status'], $login['json']);
if (($login['json']['mfa_required'] ?? false) === true) {
    deploymentAssert($mfaCode !== '', 'SSO_DEPLOYMENT_SMOKE_MFA_CODE is required for this user');
    deploymentAssert(is_string($login['json']['mfa_token'] ?? null), 'MFA login did not return mfa_token');
    $mfaPayload = [
        'mfa_token' => $login['json']['mfa_token'],
        'code' => $mfaCode,
    ];
    $adminContract->validateRequest('POST', '/api/v1/auth/mfa/verify', 'application/json', $mfaPayload);
    $login = deploymentJsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', $tlsInsecure, $mfaPayload);
    deploymentAssert($login['status'] === 200, 'MFA verification must return HTTP 200');
    $adminContract->validateResponse('POST', '/api/v1/auth/mfa/verify', $login['status'], $login['json']);
}

deploymentAssert(is_string($login['json']['access_token'] ?? null), 'login did not return access_token');
deploymentAssert(is_string($login['cookies']['sso_session'] ?? null), 'login did not set sso_session');
$accessToken = $login['json']['access_token'];
$sessionCookie = ['sso_session' => $login['cookies']['sso_session']];

$me = deploymentRequest('GET', $baseUrl . '/api/v1/auth/me', $tlsInsecure, [
    'headers' => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
]);
deploymentAssert($me['status'] === 200, '/api/v1/auth/me must return HTTP 200');
$adminContract->validateResponse('GET', '/api/v1/auth/me', $me['status'], $me['json']);
deploymentAssert(($me['json']['username'] ?? '') === $username, '/api/v1/auth/me returned another user');

$suffix = bin2hex(random_bytes(8));
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = 'deployment-' . $suffix;
$nonce = 'deployment-' . bin2hex(random_bytes(8));
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
$authorize = deploymentRequest('GET', $authorizeUrl, $tlsInsecure, ['cookies' => $sessionCookie]);
if ($authorize['status'] === 403 && ($authorize['json']['error'] ?? '') === 'consent_required') {
    deploymentAssert(
        $grantConsent,
        'OAuth consent is required; pre-grant it or set SSO_DEPLOYMENT_SMOKE_GRANT_CONSENT=true'
    );
    $consent = deploymentJsonRequest(
        'POST',
        $baseUrl . '/oauth2/consent',
        $tlsInsecure,
        ['client_id' => $clientId, 'scope' => $scope],
        [],
        $sessionCookie
    );
    deploymentAssert($consent['status'] === 200, 'OAuth consent grant must return HTTP 200');
    $authorize = deploymentRequest('GET', $authorizeUrl, $tlsInsecure, ['cookies' => $sessionCookie]);
}
deploymentAssert($authorize['status'] >= 300 && $authorize['status'] < 400, 'authorize must redirect to the client');
deploymentAssert(strpos($authorize['location'], $redirectUri) === 0, 'authorize redirected to an unexpected URI');
$callback = parse_url($authorize['location']);
parse_str($callback['query'] ?? '', $callbackQuery);
deploymentAssert(is_string($callbackQuery['code'] ?? null), 'authorize callback did not contain code');
deploymentAssert(($callbackQuery['state'] ?? '') === $state, 'authorize callback did not preserve state');

$tokenPayload = [
    'grant_type' => 'authorization_code',
    'code' => $callbackQuery['code'],
    'redirect_uri' => $redirectUri,
    'code_verifier' => $verifier,
];
$tokenHeaders = deploymentClientAuthentication($clientId, $clientSecret, $tokenPayload);
$oauthContract->validateRequest('POST', '/oauth2/token', 'application/x-www-form-urlencoded', $tokenPayload);
$token = deploymentFormRequest($tokenEndpoint, $tlsInsecure, $tokenPayload, $tokenHeaders);
deploymentAssert($token['status'] === 200, 'authorization code exchange must return HTTP 200');
$oauthContract->validateResponse('POST', '/oauth2/token', $token['status'], $token['json']);
foreach (['access_token', 'refresh_token', 'id_token'] as $field) {
    deploymentAssert(is_string($token['json'][$field] ?? null), 'token response is missing ' . $field);
}

$userinfo = deploymentRequest('GET', $userinfoEndpoint, $tlsInsecure, [
    'headers' => ['Authorization: Bearer ' . $token['json']['access_token'], 'Accept: application/json'],
]);
deploymentAssert($userinfo['status'] === 200, 'UserInfo must return HTTP 200');
$oauthContract->validateResponse('GET', '/oauth2/userinfo', $userinfo['status'], $userinfo['json']);
deploymentAssert(is_string($userinfo['json']['sub'] ?? null), 'UserInfo did not return sub');

$refreshPayload = [
    'grant_type' => 'refresh_token',
    'refresh_token' => $token['json']['refresh_token'],
];
$refreshHeaders = deploymentClientAuthentication($clientId, $clientSecret, $refreshPayload);
$oauthContract->validateRequest('POST', '/oauth2/token', 'application/x-www-form-urlencoded', $refreshPayload);
$refresh = deploymentFormRequest($tokenEndpoint, $tlsInsecure, $refreshPayload, $refreshHeaders);
deploymentAssert($refresh['status'] === 200, 'refresh token exchange must return HTTP 200');
$oauthContract->validateResponse('POST', '/oauth2/token', $refresh['status'], $refresh['json']);
deploymentAssert(is_string($refresh['json']['access_token'] ?? null), 'refresh response is missing access_token');
deploymentAssert(is_string($refresh['json']['refresh_token'] ?? null), 'refresh response is missing refresh_token');
deploymentAssert($refresh['json']['refresh_token'] !== $token['json']['refresh_token'], 'refresh token was not rotated');

$logoutState = 'deployment-logout-' . $suffix;
$logout = deploymentRequest(
    'GET',
    $logoutEndpoint . '?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => $logoutRedirectUri,
        'state' => $logoutState,
    ]),
    $tlsInsecure,
    ['cookies' => $sessionCookie]
);
deploymentAssert($logout['status'] >= 300 && $logout['status'] < 400, 'OIDC logout must redirect to the client');
deploymentAssert(strpos($logout['location'], $logoutRedirectUri) === 0, 'OIDC logout redirected to an unexpected URI');
$logoutLocation = parse_url($logout['location']);
parse_str($logoutLocation['query'] ?? '', $logoutQuery);
deploymentAssert(($logoutQuery['state'] ?? '') === $logoutState, 'OIDC logout did not preserve state');

fwrite(STDOUT, "Authenticated deployment smoke passed\n");
fwrite(STDOUT, "Verified login, MFA when enabled, /auth/me, Authorization Code + PKCE, UserInfo, refresh rotation and OIDC logout\n");
