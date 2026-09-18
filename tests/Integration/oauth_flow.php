<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../../config/console.php';
$app = new yii\console\Application($config);
$db = Yii::$app->db;
$baseUrl = rtrim(getenv('SSO_TEST_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$suffix = strtolower(bin2hex(random_bytes(4)));
$adminUsername = 'itest-admin-' . $suffix;
$userUsername = 'itest-user-' . $suffix;
$password = 'IntegrationPass123!';
$createdUserIds = [];
$createdClientIds = [];
$createdGroupIds = [];
$createdProviderIds = [];
$createdAuditActions = [];
$createdAuditTargets = [];

function failTest(string $message): void
{
    fwrite(STDERR, "FAIL: " . $message . "\n");
    exit(1);
}

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function assertOAuthError(array $response, int $status, string $error, string $message): void
{
    assertTrue($response['status'] === $status, $message . ' must return ' . $status);
    assertTrue(($response['json']['error'] ?? '') === $error, $message . ' must return ' . $error);
    assertTrue(isset($response['json']['error_description']), $message . ' must include error_description');
}

function httpRequest(string $method, string $url, array $options = []): array
{
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
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
            'content' => $body ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 15,
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
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $matches)) {
            $setCookies[$matches[1]] = $matches[2];
        }
    }

    $json = json_decode((string)$raw, true);
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'cookies' => $setCookies,
        'location' => $location,
        'body' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function jsonRequest(string $method, string $url, array $payload, ?string $token = null, array $cookies = []): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    return httpRequest($method, $url, [
        'headers' => $headers,
        'body' => json_encode($payload),
        'cookies' => $cookies,
    ]);
}

function formRequest(string $url, array $payload, array $headers = []): array
{
    return httpRequest('POST', $url, [
        'headers' => array_merge(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $headers),
        'body' => http_build_query($payload),
    ]);
}

function basicAuthHeader(string $username, string $password): string
{
    return 'Authorization: Basic ' . base64_encode(rawurlencode($username) . ':' . rawurlencode($password));
}

function responseHeader(array $response, string $name): string
{
    foreach ($response['headers'] as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return '';
}

function base32DecodeValue(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]+/', '', $value) ?? '');
    $bits = '';
    for ($index = 0; $index < strlen($clean); $index++) {
        $position = strpos($alphabet, $clean[$index]);
        assertTrue($position !== false, 'base32 secret must contain valid characters');
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }

    $decoded = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $decoded .= chr(bindec($chunk));
        }
    }

    return $decoded;
}

function totpCode(string $secret, ?int $counter = null): string
{
    $secretBytes = base32DecodeValue($secret);
    $counter = $counter ?? (int)floor(time() / 30);
    $binaryCounter = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $secretBytes, true);
    $offset = ord(substr($hash, -1)) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function jwtPart(string $jwt, int $index): array
{
    $parts = explode('.', $jwt);
    assertTrue(count($parts) === 3, 'JWT must contain three parts');
    $decoded = base64_decode(strtr($parts[$index], '-_', '+/'));
    $json = json_decode((string)$decoded, true);
    assertTrue(is_array($json), 'JWT part must contain JSON object');
    return $json;
}

function createUser(\yii\db\Connection $db, string $username, string $password): string
{
    $id = $db->createCommand(
        "INSERT INTO users(username,email,password_hash,status,source,email_verified)
         VALUES(:username,:email,:password_hash,'active','local',true)
         RETURNING id",
        [
            ':username' => $username,
            ':email' => $username . '@example.test',
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]
    )->queryScalar();

    return (string)$id;
}

try {
    $live = httpRequest('GET', $baseUrl . '/health/live');
    assertTrue($live['status'] === 200, 'liveness endpoint must return 200');
    assertTrue(($live['json']['status'] ?? '') === 'ok', 'liveness endpoint must return Go-compatible status');

    $ready = httpRequest('GET', $baseUrl . '/health/ready');
    assertTrue($ready['status'] === 200, 'readiness endpoint must return 200 while PostgreSQL is available');
    assertTrue(($ready['json']['status'] ?? '') === 'ready', 'readiness endpoint must return Go-compatible status');

    $version = httpRequest('GET', $baseUrl . '/health/version');
    assertTrue($version['status'] === 200, 'version endpoint must return 200');
    assertTrue(is_string($version['json']['version'] ?? null), 'version endpoint must return a version string');

    $adminId = createUser($db, $adminUsername, $password);
    $createdUserIds[] = $adminId;
    $db->createCommand(
        "INSERT INTO user_roles(user_id, role_id)
         SELECT :user_id, id FROM roles WHERE code = 'admin'
         ON CONFLICT DO NOTHING",
        [':user_id' => $adminId]
    )->execute();

    $bootstrapStatus = httpRequest('GET', $baseUrl . '/api/v1/bootstrap/status');
    assertTrue($bootstrapStatus['status'] === 200, 'bootstrap status must return 200');
    assertTrue(($bootstrapStatus['json']['initialized'] ?? false) === true, 'bootstrap status must report initialized system');

    $repeatedBootstrap = jsonRequest('POST', $baseUrl . '/api/v1/bootstrap', [
        'username' => 'second-bootstrap-admin',
        'email' => 'second-bootstrap@example.test',
        'password' => $password,
    ]);
    assertTrue($repeatedBootstrap['status'] === 409, 'repeated bootstrap must return 409');
    assertTrue(($repeatedBootstrap['json']['error'] ?? '') === 'system already initialized', 'repeated bootstrap must match Go error');

    $plainUserId = createUser($db, $userUsername, $password);
    $createdUserIds[] = $plainUserId;

    $login = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($login['status'] === 200, 'admin login must return 200');
    assertTrue(!empty($login['json']['access_token']), 'admin login must return access_token');
    assertTrue(!empty($login['cookies']['sso_session']), 'admin login must set sso_session cookie');

    $adminToken = $login['json']['access_token'];
    $sessionCookie = ['sso_session' => $login['cookies']['sso_session']];

    $missingBearer = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', []);
    assertTrue($missingBearer['status'] === 401, 'protected endpoint without bearer token must return 401');
    assertTrue(($missingBearer['json']['error'] ?? '') === 'missing bearer token', 'missing bearer token must match Go error');

    $invalidBearer = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], 'not-a-jwt');
    assertTrue($invalidBearer['status'] === 401, 'protected endpoint with invalid bearer token must return 401');
    assertTrue(($invalidBearer['json']['error'] ?? '') === 'invalid token', 'invalid bearer token must match Go error');

    $missingAdminBearer = jsonRequest('GET', $baseUrl . '/api/v1/users', []);
    assertTrue($missingAdminBearer['status'] === 401, 'admin endpoint without bearer token must return 401');
    assertTrue(($missingAdminBearer['json']['error'] ?? '') === 'missing bearer token', 'admin bearer middleware must match Go error');

    $missingUserinfoBearer = jsonRequest('GET', $baseUrl . '/oauth2/userinfo', []);
    assertTrue($missingUserinfoBearer['status'] === 401, 'userinfo without bearer token must return 401');
    assertTrue(($missingUserinfoBearer['json']['error'] ?? '') === 'missing bearer token', 'userinfo bearer middleware must match Go error');

    $malformedLogin = httpRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '{"username":',
    ]);
    assertTrue($malformedLogin['status'] === 400, 'malformed login JSON must return 400');
    assertTrue(($malformedLogin['json']['error'] ?? '') === 'invalid json body', 'malformed login JSON must match Go error');

    $malformedForgot = httpRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '{"login":',
    ]);
    assertTrue($malformedForgot['status'] === 400, 'malformed password forgot JSON must return 400');
    assertTrue(($malformedForgot['json']['error'] ?? '') === 'login is required', 'malformed password forgot JSON must match Go error');

    $arrayForgot = httpRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '[]',
    ]);
    assertTrue($arrayForgot['status'] === 400, 'non-object password forgot JSON must return 400');
    assertTrue(($arrayForgot['json']['error'] ?? '') === 'login is required', 'non-object password forgot JSON must match Go error');

    $unknownForgotField = jsonRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', [
        'login' => $adminUsername,
        'unexpected' => true,
    ]);
    assertTrue($unknownForgotField['status'] === 400, 'password forgot JSON with unknown field must return 400');
    assertTrue(($unknownForgotField['json']['error'] ?? '') === 'login is required', 'password forgot decode failure must match Go error');

    $arrayResetBody = httpRequest('POST', $baseUrl . '/api/v1/auth/password/reset', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '[]',
    ]);
    assertTrue($arrayResetBody['status'] === 400, 'non-object account JSON must return 400');
    assertTrue(($arrayResetBody['json']['error'] ?? '') === 'invalid json body', 'non-object account JSON must match Go error');

    $unknownLoginField = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
        'unexpected' => true,
    ]);
    assertTrue($unknownLoginField['status'] === 400, 'login JSON with unknown field must return 400');
    assertTrue(($unknownLoginField['json']['error'] ?? '') === 'invalid json body', 'unknown login field must match Go error');

    $unknownResetField = jsonRequest('POST', $baseUrl . '/api/v1/auth/password/reset', [
        'token' => 'unknown-token',
        'password' => $password,
        'unexpected' => true,
    ]);
    assertTrue($unknownResetField['status'] === 400, 'password reset JSON with unknown field must return 400');
    assertTrue(($unknownResetField['json']['error'] ?? '') === 'invalid json body', 'unknown password reset field must match Go error');

    $secondLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($secondLogin['status'] === 200, 'second admin login must return 200');

    $sessions = jsonRequest('GET', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue($sessions['status'] === 200, 'session list must return 200');
    assertTrue(count($sessions['json'] ?? []) === 2, 'session list must include both active sessions');
    assertTrue(($sessions['json'][0]['user_id'] ?? '') === $adminId, 'session list must include Go-compatible user_id');
    assertTrue(!array_key_exists('token_hash', $sessions['json'][0]), 'session list must not expose token hash');

    $invalidSession = jsonRequest('DELETE', $baseUrl . '/api/v1/auth/sessions/not-a-uuid', [], $adminToken);
    assertTrue($invalidSession['status'] === 400, 'invalid session id must return 400');
    assertTrue(($invalidSession['json']['error'] ?? '') === 'invalid session id', 'invalid session id must match Go error');

    $revokedSession = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/auth/sessions/' . rawurlencode($sessions['json'][0]['id']),
        [],
        $adminToken
    );
    assertTrue($revokedSession['status'] === 200, 'owned session revoke must return 200');
    assertTrue(($revokedSession['json']['status'] ?? '') === 'revoked', 'owned session revoke must return revoked status');

    $remainingSessions = jsonRequest('GET', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue(count($remainingSessions['json'] ?? []) === 1, 'revoked session must disappear from active session list');

    $revokeAll = jsonRequest('DELETE', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue($revokeAll['status'] === 200, 'revoke all sessions must return 200');
    assertTrue(($revokeAll['json']['status'] ?? '') === 'all_revoked', 'revoke all sessions must return all_revoked status');

    $revokedToken = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $adminToken);
    assertTrue($revokedToken['status'] === 401, 'revoke all sessions must invalidate existing bearer token');
    assertTrue(($revokedToken['json']['error'] ?? '') === 'token revoked', 'revoked first-party token must match Go error');

    $login = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($login['status'] === 200, 'admin relogin after revoke all must return 200');
    $adminToken = $login['json']['access_token'];
    $sessionCookie = ['sso_session' => $login['cookies']['sso_session']];

    $client = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Integration test client ' . $suffix,
        'client_id' => 'itest-client-' . $suffix,
        'type' => 'confidential',
        'redirect_uris' => ['http://localhost/cb'],
        'allowed_scopes' => ['openid', 'profile', 'email'],
        'post_logout_redirect_uris' => ['http://localhost/logout-cb'],
        'skip_consent' => true,
        'enabled' => true,
    ], $adminToken);
    assertTrue($client['status'] === 201, 'create oauth client must return 201');
    assertTrue(!empty($client['json']['client_secret']), 'create oauth client must return client_secret');
    assertTrue(isset($client['json']['client']), 'create oauth client must return Go-compatible nested client');
    $clientSecret = $client['json']['client_secret'];
    $client['json'] = $client['json']['client'];
    $client['json']['client_secret'] = $clientSecret;
    $createdClientIds[] = $client['json']['id'];
    $createdAuditTargets[] = ['action' => 'refresh_token_revoked', 'target_id' => $client['json']['client_id']];

    $unknownOAuthClientField = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Unknown field client ' . $suffix,
        'client_id' => 'unknown-field-client-' . $suffix,
        'type' => 'confidential',
        'redirect_uris' => ['http://localhost/unknown-field-cb'],
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownOAuthClientField['status'] === 400, 'OAuth client JSON with unknown field must return 400');
    assertTrue(($unknownOAuthClientField['json']['error'] ?? '') === 'invalid json body', 'unknown OAuth client field must match Go error');

    foreach ([
        ['GET', '/api/v1/oauth/clients/not-a-uuid'],
        ['PUT', '/api/v1/oauth/clients/not-a-uuid'],
        ['DELETE', '/api/v1/oauth/clients/not-a-uuid'],
        ['POST', '/api/v1/oauth/clients/not-a-uuid/rotate-secret'],
    ] as $invalidClientRequest) {
        [$method, $path] = $invalidClientRequest;
        $invalidClientId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidClientId['status'] === 400, $method . ' OAuth client with invalid id must return 400');
        assertTrue(($invalidClientId['json']['error'] ?? '') === 'invalid id', $method . ' OAuth client must match Go error');
    }

    $missingClient = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/oauth/clients/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingClient['status'] === 404, 'missing OAuth client must return 404');
    assertTrue(($missingClient['json']['error'] ?? '') === 'client not found', 'missing OAuth client must match Go error');

    $unknownOAuthUpdateField = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/oauth/clients/00000000-0000-0000-0000-000000000000',
        ['type' => 'public'],
        $adminToken
    );
    assertTrue($unknownOAuthUpdateField['status'] === 400, 'OAuth client update with Go-unknown type field must return 400');
    assertTrue(($unknownOAuthUpdateField['json']['error'] ?? '') === 'invalid json body', 'unknown OAuth client update field must match Go error');

    $missingConfidentialClient = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/oauth/clients/00000000-0000-0000-0000-000000000000/rotate-secret',
        [],
        $adminToken
    );
    assertTrue($missingConfidentialClient['status'] === 404, 'missing confidential OAuth client must return 404');
    assertTrue(($missingConfidentialClient['json']['error'] ?? '') === 'confidential client not found', 'missing confidential OAuth client must match Go error');

    $configuration = httpRequest('GET', $baseUrl . '/.well-known/openid-configuration', [
        'headers' => ['Accept: application/json'],
    ]);
    assertTrue($configuration['status'] === 200, 'OIDC discovery must return 200');
    assertTrue(!empty($configuration['json']['issuer']), 'OIDC discovery must include issuer');
    assertTrue(($configuration['json']['authorization_endpoint'] ?? '') === $configuration['json']['issuer'] . '/oauth2/authorize', 'OIDC discovery must include authorization endpoint');
    assertTrue(($configuration['json']['token_endpoint'] ?? '') === $configuration['json']['issuer'] . '/oauth2/token', 'OIDC discovery must include token endpoint');
    assertTrue(($configuration['json']['revocation_endpoint'] ?? '') === $configuration['json']['issuer'] . '/oauth2/revoke', 'OIDC discovery must include revocation endpoint');
    assertTrue(($configuration['json']['introspection_endpoint'] ?? '') === $configuration['json']['issuer'] . '/oauth2/introspect', 'OIDC discovery must include introspection endpoint');
    assertTrue(in_array('authorization_code', $configuration['json']['grant_types_supported'] ?? [], true), 'OIDC discovery must support authorization_code');
    assertTrue(in_array('RS256', $configuration['json']['id_token_signing_alg_values_supported'] ?? [], true), 'OIDC discovery must support RS256');

    $metrics = httpRequest('GET', $baseUrl . '/metrics', [
        'headers' => ['Accept: text/plain'],
    ]);
    assertTrue($metrics['status'] === 200, 'metrics endpoint must return 200');
    assertTrue(strpos((string)$metrics['body'], '# TYPE sso_http_requests_total counter') !== false, 'metrics endpoint must expose request counter');
    assertTrue(strpos((string)$metrics['body'], 'sso_http_requests_processed_total') !== false, 'metrics endpoint must expose processed counter');
    assertTrue(strpos((string)$metrics['body'], 'route="/api/v1/oauth/clients/{id}"') !== false, 'metrics must expose Go-compatible dynamic route template');
    assertTrue(strpos((string)$metrics['body'], 'route="/api/v1/oauth/clients/{id}/rotate-secret"') !== false, 'metrics must preserve nested Go route template');
    assertTrue(strpos((string)$metrics['body'], 'route="oauth/get-client"') === false, 'metrics must not expose internal Yii route names');
    assertTrue(strpos((string)$metrics['body'], '00000000-0000-0000-0000-000000000000') === false, 'metrics route labels must not contain resource UUIDs');

    $jwks = httpRequest('GET', $baseUrl . '/.well-known/jwks.json', [
        'headers' => ['Accept: application/json'],
    ]);
    assertTrue($jwks['status'] === 200, 'JWKS must return 200');
    assertTrue(!empty($jwks['json']['keys'][0]['kid']), 'JWKS must include key id');
    assertTrue(($jwks['json']['keys'][0]['kty'] ?? '') === 'RSA', 'JWKS key must be RSA');
    assertTrue(($jwks['json']['keys'][0]['alg'] ?? '') === 'RS256', 'JWKS key must use RS256');

    $loginRequiredUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'openid',
    ]);
    $loginRequired = httpRequest('GET', $loginRequiredUrl, ['headers' => ['Accept: application/json']]);
    assertOAuthError($loginRequired, 401, 'login_required', 'authorize without SSO session');

    $badRedirectUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/not-registered',
        'scope' => 'openid',
    ]);
    $badRedirect = httpRequest('GET', $badRedirectUrl, ['cookies' => $sessionCookie]);
    assertOAuthError($badRedirect, 400, 'invalid_request', 'authorize with unregistered redirect_uri');

    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $authorizeUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'openid profile email',
        'state' => 'state-' . $suffix,
        'nonce' => 'nonce-' . $suffix,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    $authorize = httpRequest('GET', $authorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue(
        $authorize['status'] >= 300 && $authorize['status'] < 400,
        'authorize must redirect, got status ' . $authorize['status'] . ' body ' . (string)$authorize['body']
    );

    $locationParts = parse_url($authorize['location']);
    parse_str($locationParts['query'] ?? '', $query);
    assertTrue(!empty($query['code']), 'authorize redirect must include code');
    assertTrue(($query['state'] ?? '') === 'state-' . $suffix, 'authorize redirect must preserve state');

    $token = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => 'http://localhost/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => $verifier,
    ]);
    assertTrue($token['status'] === 200, 'authorization code token must return 200');
    assertTrue(!empty($token['json']['access_token']), 'token response must include access_token');
    assertTrue(!empty($token['json']['refresh_token']), 'token response must include refresh_token');
    assertTrue(!empty($token['json']['id_token']), 'openid token response must include id_token');

    $idTokenHeader = jwtPart($token['json']['id_token'], 0);
    $idTokenPayload = jwtPart($token['json']['id_token'], 1);
    assertTrue(($idTokenHeader['alg'] ?? '') === 'RS256', 'id_token must use RS256');
    assertTrue(($idTokenHeader['kid'] ?? '') === $jwks['json']['keys'][0]['kid'], 'id_token kid must match JWKS');
    assertTrue(($idTokenPayload['iss'] ?? '') === $configuration['json']['issuer'], 'id_token issuer must match discovery');
    assertTrue(($idTokenPayload['sub'] ?? '') === $adminId, 'id_token subject must match user');
    assertTrue(($idTokenPayload['aud'] ?? '') === $client['json']['client_id'], 'id_token audience must match client');
    assertTrue(($idTokenPayload['nonce'] ?? '') === 'nonce-' . $suffix, 'id_token nonce must match authorize request');

    $codeReuse = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => 'http://localhost/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => $verifier,
    ]);
    assertTrue($codeReuse['status'] === 400, 'authorization code reuse must return 400');
    assertTrue(($codeReuse['json']['error'] ?? '') === 'invalid_grant', 'authorization code reuse must return invalid_grant');

    $burnVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $burnChallenge = rtrim(strtr(base64_encode(hash('sha256', $burnVerifier, true)), '+/', '-_'), '=');
    $burnAuthorize = httpRequest('GET', $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'profile',
        'code_challenge' => $burnChallenge,
        'code_challenge_method' => 'S256',
    ]), ['cookies' => $sessionCookie]);
    $burnLocationParts = parse_url($burnAuthorize['location']);
    parse_str($burnLocationParts['query'] ?? '', $burnQuery);
    assertTrue(!empty($burnQuery['code']), 'PKCE burn scenario must issue authorization code');

    $wrongPkce = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $burnQuery['code'],
        'redirect_uri' => 'http://localhost/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => 'wrong-verifier',
    ]);
    assertOAuthError($wrongPkce, 400, 'invalid_grant', 'authorization code with invalid PKCE verifier');

    $correctAfterWrongPkce = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $burnQuery['code'],
        'redirect_uri' => 'http://localhost/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => $burnVerifier,
    ]);
    assertOAuthError($correctAfterWrongPkce, 400, 'invalid_grant', 'authorization code consumed by failed PKCE exchange');

    $plainVerifier = 'plain-verifier-' . $suffix;
    $plainAuthorize = httpRequest('GET', $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'profile',
        'code_challenge' => $plainVerifier,
        'code_challenge_method' => 'plain',
    ]), ['cookies' => $sessionCookie]);
    $plainLocationParts = parse_url($plainAuthorize['location']);
    parse_str($plainLocationParts['query'] ?? '', $plainQuery);
    assertTrue(!empty($plainQuery['code']), 'confidential client plain PKCE authorize must match Go behavior');

    $plainExchange = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $plainQuery['code'],
        'redirect_uri' => 'http://localhost/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => $plainVerifier,
    ]);
    assertOAuthError($plainExchange, 400, 'invalid_grant', 'plain PKCE exchange');

    $introspection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $token['json']['access_token'],
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($introspection['status'] === 200, 'introspection must return 200');
    assertTrue(($introspection['json']['active'] ?? false) === true, 'introspection must mark issued access token active');
    assertTrue(($introspection['json']['token_type'] ?? '') === 'Bearer', 'access token introspection must return Bearer token type');
    assertTrue(($introspection['json']['sub'] ?? '') === $adminId, 'introspection must include access token subject');
    assertTrue(($introspection['json']['client_id'] ?? '') === $client['json']['client_id'], 'introspection must include client_id');
    assertTrue(($introspection['json']['username'] ?? '') === $adminUsername, 'introspection must include access token username');
    assertTrue(!array_key_exists('iss', $introspection['json']), 'introspection must not leak JWT-only issuer claim');
    assertTrue(!array_key_exists('iat', $introspection['json']), 'access token introspection must match Go claim set');

    $refreshIntrospection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $token['json']['refresh_token'],
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($refreshIntrospection['status'] === 200, 'refresh token introspection must return 200');
    assertTrue(($refreshIntrospection['json']['active'] ?? false) === true, 'fresh refresh token must be active');
    assertTrue(($refreshIntrospection['json']['token_type'] ?? '') === 'refresh_token', 'refresh introspection must identify token type');
    assertTrue(($refreshIntrospection['json']['sub'] ?? '') === $adminId, 'refresh introspection must include subject');
    assertTrue(($refreshIntrospection['json']['client_id'] ?? '') === $client['json']['client_id'], 'refresh introspection must include client_id');
    assertTrue(($refreshIntrospection['json']['exp'] ?? 0) > time(), 'refresh introspection must include future expiration');
    assertTrue(($refreshIntrospection['json']['iat'] ?? 0) <= time(), 'refresh introspection must include issued-at timestamp');

    $inactiveIntrospection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => 'not-a-token',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($inactiveIntrospection['status'] === 200, 'inactive introspection must return 200');
    assertTrue(($inactiveIntrospection['json']['active'] ?? true) === false, 'introspection must mark invalid token inactive');

    $basicVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $basicChallenge = rtrim(strtr(base64_encode(hash('sha256', $basicVerifier, true)), '+/', '-_'), '=');
    $basicAuthorizeUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'openid profile',
        'code_challenge' => $basicChallenge,
        'code_challenge_method' => 'S256',
    ]);
    $basicAuthorize = httpRequest('GET', $basicAuthorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue($basicAuthorize['status'] >= 300 && $basicAuthorize['status'] < 400, 'basic-auth authorize must redirect');
    $basicLocationParts = parse_url($basicAuthorize['location']);
    parse_str($basicLocationParts['query'] ?? '', $basicQuery);
    $basicToken = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $basicQuery['code'] ?? '',
        'redirect_uri' => 'http://localhost/cb',
        'code_verifier' => $basicVerifier,
    ], [basicAuthHeader($client['json']['client_id'], $client['json']['client_secret'])]);
    assertTrue($basicToken['status'] === 200, 'client_secret_basic token exchange must return 200');
    assertTrue(!empty($basicToken['json']['access_token']), 'client_secret_basic token exchange must include access_token');

    $userinfo = httpRequest('GET', $baseUrl . '/oauth2/userinfo', [
        'headers' => ['Authorization: Bearer ' . $token['json']['access_token'], 'Accept: application/json'],
    ]);
    assertTrue($userinfo['status'] === 200, 'userinfo with oauth access token must return 200');
    assertTrue(($userinfo['json']['sub'] ?? '') === $adminId, 'userinfo must return subject');
    assertTrue(($userinfo['json']['preferred_username'] ?? '') === $adminUsername, 'userinfo profile scope must return preferred_username');
    assertTrue(($userinfo['json']['email'] ?? '') === $adminUsername . '@example.test', 'userinfo email scope must return email');

    $firstRefresh = $token['json']['refresh_token'];
    $refresh = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $firstRefresh,
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($refresh['status'] === 200, 'refresh token grant must return 200');
    assertTrue(!empty($refresh['json']['refresh_token']), 'refresh grant must return rotated refresh_token');
    assertTrue($refresh['json']['refresh_token'] !== $firstRefresh, 'refresh grant must rotate token value');

    $rotatedIntrospection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $firstRefresh,
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue(($rotatedIntrospection['json']['active'] ?? true) === false, 'rotated refresh token introspection must be inactive');

    $descendantIntrospection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $refresh['json']['refresh_token'],
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue(($descendantIntrospection['json']['active'] ?? false) === true, 'current refresh family descendant must be active');

    $reuse = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $firstRefresh,
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($reuse['status'] === 400, 'reusing rotated refresh token must fail');
    assertTrue(($reuse['json']['error'] ?? '') === 'invalid_grant', 'reuse must return invalid_grant');

    $revokedDescendantIntrospection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $refresh['json']['refresh_token'],
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue(($revokedDescendantIntrospection['json']['active'] ?? true) === false, 'reuse detection must make refresh family inactive');

    $descendant = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh['json']['refresh_token'],
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($descendant['status'] === 400, 'descendant refresh token must be revoked after reuse detection');

    $profileOnlyVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $profileOnlyChallenge = rtrim(strtr(base64_encode(hash('sha256', $profileOnlyVerifier, true)), '+/', '-_'), '=');
    $profileOnlyAuthorizeUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'openid profile',
        'code_challenge' => $profileOnlyChallenge,
        'code_challenge_method' => 'S256',
    ]);
    $profileOnlyAuthorize = httpRequest('GET', $profileOnlyAuthorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue($profileOnlyAuthorize['status'] >= 300 && $profileOnlyAuthorize['status'] < 400, 'profile-only authorize must redirect');
    $profileLocationParts = parse_url($profileOnlyAuthorize['location']);
    parse_str($profileLocationParts['query'] ?? '', $profileQuery);
    $profileOnlyToken = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $profileQuery['code'] ?? '',
        'redirect_uri' => 'http://localhost/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => $profileOnlyVerifier,
    ]);
    assertTrue($profileOnlyToken['status'] === 200, 'profile-only token exchange must return 200');
    $profileOnlyUserinfo = httpRequest('GET', $baseUrl . '/oauth2/userinfo', [
        'headers' => ['Authorization: Bearer ' . $profileOnlyToken['json']['access_token'], 'Accept: application/json'],
    ]);
    assertTrue($profileOnlyUserinfo['status'] === 200, 'profile-only userinfo must return 200');
    assertTrue(isset($profileOnlyUserinfo['json']['preferred_username']), 'profile-only userinfo must include profile claim');
    assertTrue(!array_key_exists('email', $profileOnlyUserinfo['json']), 'profile-only userinfo must not include email claim');

    $revoke = formRequest($baseUrl . '/oauth2/revoke', [
        'token' => $profileOnlyToken['json']['refresh_token'] ?? '',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($revoke['status'] === 200, 'revocation must return 200 for authenticated client');
    assertTrue($revoke['body'] === '', 'successful RFC 7009 revocation must return an empty body');

    $revokedRefreshIntrospection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $profileOnlyToken['json']['refresh_token'] ?? '',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue(($revokedRefreshIntrospection['json']['active'] ?? true) === false, 'revoked refresh token introspection must be inactive');

    $revokedRefresh = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $profileOnlyToken['json']['refresh_token'] ?? '',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($revokedRefresh['status'] === 400, 'revoked refresh token must return 400');
    assertTrue(($revokedRefresh['json']['error'] ?? '') === 'invalid_grant', 'revoked refresh token must return invalid_grant');

    $consentClient = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Integration consent client ' . $suffix,
        'client_id' => 'itest-consent-' . $suffix,
        'type' => 'confidential',
        'redirect_uris' => ['http://localhost/consent-cb'],
        'allowed_scopes' => ['openid', 'profile'],
        'skip_consent' => false,
        'enabled' => true,
    ], $adminToken);
    assertTrue($consentClient['status'] === 201, 'create consent oauth client must return 201');
    assertTrue(isset($consentClient['json']['client']), 'create consent client must return nested client');
    $consentClient['json'] = $consentClient['json']['client'];
    $createdClientIds[] = $consentClient['json']['id'];
    $createdAuditTargets[] = ['action' => 'consent_granted', 'target_id' => $consentClient['json']['client_id']];

    $consentAuthorizeUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $consentClient['json']['client_id'],
        'redirect_uri' => 'http://localhost/consent-cb',
        'scope' => 'openid profile',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    $consentRequired = httpRequest('GET', $consentAuthorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue($consentRequired['status'] === 403, 'client without consent must return 403');
    assertTrue(($consentRequired['json']['error'] ?? '') === 'consent_required', 'client without consent must return consent_required');

    $consentInfoUrl = $baseUrl . '/oauth2/consent?' . http_build_query([
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid profile',
    ]);
    $anonymousConsentInfo = httpRequest('GET', $consentInfoUrl, ['headers' => ['Accept: application/json']]);
    assertOAuthError($anonymousConsentInfo, 401, 'login_required', 'anonymous consent info');
    assertTrue(($anonymousConsentInfo['json']['error_description'] ?? '') === 'no active session', 'anonymous consent info must match Go description');

    $unknownConsentClient = httpRequest('GET', $baseUrl . '/oauth2/consent?' . http_build_query([
        'client_id' => 'missing-client-' . $suffix,
        'scope' => 'openid',
    ]), ['headers' => ['Accept: application/json'], 'cookies' => $sessionCookie]);
    assertOAuthError($unknownConsentClient, 400, 'invalid_request', 'unknown consent client');
    assertTrue(($unknownConsentClient['json']['error_description'] ?? '') === 'invalid client credentials', 'unknown consent client must match Go description');

    $invalidConsentScope = httpRequest('GET', $baseUrl . '/oauth2/consent?' . http_build_query([
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'admin',
    ]), ['headers' => ['Accept: application/json'], 'cookies' => $sessionCookie]);
    assertOAuthError($invalidConsentScope, 400, 'invalid_scope', 'invalid consent info scope');

    $consentInfo = httpRequest('GET', $consentInfoUrl, [
        'headers' => ['Accept: application/json'],
        'cookies' => $sessionCookie,
    ]);
    assertTrue($consentInfo['status'] === 200, 'consent info must return 200');
    assertTrue(($consentInfo['json']['client_id'] ?? '') === $consentClient['json']['client_id'], 'consent info must include client_id');
    assertTrue(($consentInfo['json']['requested_scopes'] ?? []) === ['openid', 'profile'], 'consent info must include requested scopes');
    assertTrue(($consentInfo['json']['granted_scopes'] ?? null) === [], 'consent info must initially report no granted scopes');

    $malformedConsent = httpRequest('POST', $baseUrl . '/oauth2/consent', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '{"client_id":',
        'cookies' => $sessionCookie,
    ]);
    assertOAuthError($malformedConsent, 400, 'invalid_request', 'malformed consent JSON');
    assertTrue(($malformedConsent['json']['error_description'] ?? '') === 'invalid json body', 'malformed consent JSON must match Go description');

    $arrayConsent = httpRequest('POST', $baseUrl . '/oauth2/consent', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '[]',
        'cookies' => $sessionCookie,
    ]);
    assertOAuthError($arrayConsent, 400, 'invalid_request', 'non-object consent JSON');
    assertTrue(($arrayConsent['json']['error_description'] ?? '') === 'invalid json body', 'non-object consent JSON must match Go description');

    $unknownConsentField = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid profile',
        'unexpected' => true,
    ], null, $sessionCookie);
    assertOAuthError($unknownConsentField, 400, 'invalid_request', 'consent JSON with unknown field');
    assertTrue(($unknownConsentField['json']['error_description'] ?? '') === 'invalid json body', 'unknown consent field must match Go description');

    $invalidConsentClientGrant = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => 'missing-client-' . $suffix,
        'scope' => 'openid',
    ], null, $sessionCookie);
    assertOAuthError($invalidConsentClientGrant, 400, 'invalid_request', 'grant consent for unknown client');
    assertTrue(($invalidConsentClientGrant['json']['error_description'] ?? '') === 'invalid client credentials', 'unknown grant consent client must match Go description');

    $grantOpenidConsent = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid',
    ], null, $sessionCookie);
    assertTrue($grantOpenidConsent['status'] === 200, 'grant initial consent must return 200');
    assertTrue(($grantOpenidConsent['json']['status'] ?? '') === 'granted', 'grant initial consent must return granted status');

    $partialConsentInfo = httpRequest('GET', $consentInfoUrl, [
        'headers' => ['Accept: application/json'],
        'cookies' => $sessionCookie,
    ]);
    assertTrue(($partialConsentInfo['json']['granted_scopes'] ?? []) === ['openid'], 'consent info must expose already granted scopes');

    $grantProfileConsent = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'profile',
    ], null, $sessionCookie);
    assertTrue($grantProfileConsent['status'] === 200, 'grant additional consent must return 200');

    $mergedConsentInfo = httpRequest('GET', $consentInfoUrl, [
        'headers' => ['Accept: application/json'],
        'cookies' => $sessionCookie,
    ]);
    assertTrue(($mergedConsentInfo['json']['granted_scopes'] ?? []) === ['openid', 'profile'], 'additional consent must merge with existing scopes');

    $consentAuditCount = (int)$db->createCommand(
        "SELECT count(*) FROM audit_logs WHERE actor_user_id = :user_id AND action = 'consent_granted' AND target_id = :client_id",
        [':user_id' => $adminId, ':client_id' => $consentClient['json']['client_id']]
    )->queryScalar();
    assertTrue($consentAuditCount === 2, 'each consent grant must write a Go-compatible audit event');

    $consentedAuthorize = httpRequest('GET', $consentAuthorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue($consentedAuthorize['status'] >= 300 && $consentedAuthorize['status'] < 400, 'authorize after consent must redirect');
    $consentedLocationParts = parse_url($consentedAuthorize['location']);
    parse_str($consentedLocationParts['query'] ?? '', $consentedQuery);
    assertTrue(!empty($consentedQuery['code']), 'authorize after consent must include code');

    $plainLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $userUsername,
        'password' => $password,
    ]);
    assertTrue($plainLogin['status'] === 200, 'plain user login must return 200');
    $rbac = httpRequest('GET', $baseUrl . '/api/v1/users', [
        'headers' => ['Authorization: Bearer ' . $plainLogin['json']['access_token'], 'Accept: application/json'],
    ]);
    assertTrue($rbac['status'] === 403, 'plain user must not list users');

    $missingUserFields = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => '',
        'email' => '',
        'password' => $password,
    ], $adminToken);
    assertTrue($missingUserFields['status'] === 400, 'create user without username/email must return 400');
    assertTrue(($missingUserFields['json']['error'] ?? '') === 'username and email are required', 'create user without username/email must match Go error');

    $unknownUserField = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => 'unknown-field-' . $suffix,
        'email' => 'unknown-field-' . $suffix . '@example.test',
        'password' => $password,
        'email_verified' => true,
    ], $adminToken);
    assertTrue($unknownUserField['status'] === 400, 'create user JSON with unknown field must return 400');
    assertTrue(($unknownUserField['json']['error'] ?? '') === 'invalid json body', 'unknown user field must match Go error');

    $duplicateUser = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => $plainLogin['json']['user']['username'],
        'email' => 'duplicate-' . $suffix . '@example.test',
        'password' => $password,
    ], $adminToken);
    assertTrue($duplicateUser['status'] === 409, 'duplicate username must return 409');
    assertTrue(($duplicateUser['json']['error'] ?? '') === 'user already exists', 'duplicate username must match Go error');

    $db->createCommand()->update('users', ['status' => 'blocked'], ['id' => $plainUserId])->execute();
    $blockedAccount = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $plainLogin['json']['access_token']);
    assertTrue($blockedAccount['status'] === 401, 'blocked account bearer token must return 401');
    assertTrue(($blockedAccount['json']['error'] ?? '') === 'account is not active', 'blocked account must match Go error');

    foreach (['GET', 'PUT', 'DELETE'] as $method) {
        $invalidUserId = jsonRequest($method, $baseUrl . '/api/v1/users/not-a-uuid', [], $adminToken);
        assertTrue($invalidUserId['status'] === 400, $method . ' user with invalid id must return 400');
        assertTrue(($invalidUserId['json']['error'] ?? '') === 'invalid id', $method . ' user with invalid id must match Go error');
    }

    $missingUser = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingUser['status'] === 404, 'missing user must return 404');
    assertTrue(($missingUser['json']['error'] ?? '') === 'user not found', 'missing user must match Go error');

    $unknownUserUpdateField = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000',
        ['password' => $password],
        $adminToken
    );
    assertTrue($unknownUserUpdateField['status'] === 400, 'update user JSON with Go-unknown password field must return 400');
    assertTrue(($unknownUserUpdateField['json']['error'] ?? '') === 'invalid json body', 'unknown user update field must match Go error');

    $missingRoleFields = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => '',
        'name' => '',
    ], $adminToken);
    assertTrue($missingRoleFields['status'] === 400, 'create role without code/name must return 400');
    assertTrue(($missingRoleFields['json']['error'] ?? '') === 'role code and name are required', 'create role without code/name must match Go error');

    $unknownRoleField = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => 'unknown-role-' . $suffix,
        'name' => 'Unknown role field',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownRoleField['status'] === 400, 'create role JSON with unknown field must return 400');
    assertTrue(($unknownRoleField['json']['error'] ?? '') === 'invalid json body', 'unknown role field must match Go error');

    $duplicateRole = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => 'admin',
        'name' => 'Duplicate admin',
    ], $adminToken);
    assertTrue($duplicateRole['status'] === 409, 'duplicate role code must return 409');
    assertTrue(($duplicateRole['json']['error'] ?? '') === 'role already exists', 'duplicate role code must match Go error');

    foreach ([
        ['PUT', '/api/v1/roles/not-a-uuid'],
        ['DELETE', '/api/v1/roles/not-a-uuid'],
        ['GET', '/api/v1/roles/not-a-uuid/permissions'],
        ['POST', '/api/v1/roles/not-a-uuid/permissions'],
        ['DELETE', '/api/v1/roles/not-a-uuid/permissions/not-a-uuid'],
    ] as $invalidRoleRequest) {
        [$method, $path] = $invalidRoleRequest;
        $invalidRoleId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidRoleId['status'] === 400, $method . ' role path with invalid id must return 400');
        assertTrue(($invalidRoleId['json']['error'] ?? '') === 'invalid role id', $method . ' role path must match Go error');
    }

    $invalidPermissionPath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/roles/00000000-0000-0000-0000-000000000000/permissions/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidPermissionPath['status'] === 400, 'invalid permission path id must return 400');
    assertTrue(($invalidPermissionPath['json']['error'] ?? '') === 'invalid permission id', 'invalid permission path id must match Go error');

    $invalidPermissionBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/roles/00000000-0000-0000-0000-000000000000/permissions',
        ['permission_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidPermissionBody['status'] === 400, 'invalid permission body id must return 400');
    assertTrue(($invalidPermissionBody['json']['error'] ?? '') === 'invalid json body', 'invalid permission body id must match Go error');

    foreach ([
        ['/api/v1/roles/00000000-0000-0000-0000-000000000000/permissions', 'permission_id'],
        ['/api/v1/users/00000000-0000-0000-0000-000000000000/roles', 'role_id'],
        ['/api/v1/groups/00000000-0000-0000-0000-000000000000/roles', 'role_id'],
        ['/api/v1/users/00000000-0000-0000-0000-000000000000/groups', 'group_id'],
    ] as $unknownAssignmentRequest) {
        [$path, $idField] = $unknownAssignmentRequest;
        $unknownAssignmentField = jsonRequest('POST', $baseUrl . $path, [
            $idField => '00000000-0000-0000-0000-000000000000',
            'unexpected' => true,
        ], $adminToken);
        assertTrue($unknownAssignmentField['status'] === 400, $path . ' with unknown field must return 400');
        assertTrue(($unknownAssignmentField['json']['error'] ?? '') === 'invalid json body', $path . ' unknown field must match Go error');
    }

    foreach ([
        ['GET', '/api/v1/users/not-a-uuid/roles'],
        ['POST', '/api/v1/users/not-a-uuid/roles'],
        ['DELETE', '/api/v1/users/not-a-uuid/roles/not-a-uuid'],
    ] as $invalidUserRoleRequest) {
        [$method, $path] = $invalidUserRoleRequest;
        $invalidUserId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidUserId['status'] === 400, $method . ' user-role path with invalid user id must return 400');
        assertTrue(($invalidUserId['json']['error'] ?? '') === 'invalid user id', $method . ' user-role path must match Go error');
    }

    $invalidUserRolePath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/roles/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidUserRolePath['status'] === 400, 'invalid user-role role id must return 400');
    assertTrue(($invalidUserRolePath['json']['error'] ?? '') === 'invalid role id', 'invalid user-role role id must match Go error');

    $invalidUserRoleBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/roles',
        ['role_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidUserRoleBody['status'] === 400, 'invalid user-role body id must return 400');
    assertTrue(($invalidUserRoleBody['json']['error'] ?? '') === 'invalid json body', 'invalid user-role body id must match Go error');

    $group = jsonRequest('POST', $baseUrl . '/api/v1/groups', [
        'code' => 'itest-group-' . $suffix,
        'name' => 'Integration group ' . $suffix,
    ], $adminToken);
    assertTrue($group['status'] === 201, 'create group must return 201');
    $createdGroupIds[] = $group['json']['id'];

    $unknownGroupField = jsonRequest('POST', $baseUrl . '/api/v1/groups', [
        'code' => 'unknown-group-' . $suffix,
        'name' => 'Unknown group field',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownGroupField['status'] === 400, 'create group JSON with unknown field must return 400');
    assertTrue(($unknownGroupField['json']['error'] ?? '') === 'invalid json body', 'unknown group field must match Go error');

    $duplicateGroup = jsonRequest('POST', $baseUrl . '/api/v1/groups', [
        'code' => $group['json']['code'],
        'name' => 'Duplicate group',
    ], $adminToken);
    assertTrue($duplicateGroup['status'] === 409, 'duplicate group code must return 409');
    assertTrue(($duplicateGroup['json']['error'] ?? '') === 'group already exists', 'duplicate group code must match Go error');

    foreach ([
        ['PUT', '/api/v1/groups/not-a-uuid'],
        ['DELETE', '/api/v1/groups/not-a-uuid'],
        ['GET', '/api/v1/groups/not-a-uuid/roles'],
        ['POST', '/api/v1/groups/not-a-uuid/roles'],
        ['DELETE', '/api/v1/groups/not-a-uuid/roles/not-a-uuid'],
    ] as $invalidGroupRequest) {
        [$method, $path] = $invalidGroupRequest;
        $invalidGroupId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidGroupId['status'] === 400, $method . ' group path with invalid id must return 400');
        assertTrue(($invalidGroupId['json']['error'] ?? '') === 'invalid group id', $method . ' group path must match Go error');
    }

    $invalidGroupRolePath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/groups/00000000-0000-0000-0000-000000000000/roles/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidGroupRolePath['status'] === 400, 'invalid group role path id must return 400');
    assertTrue(($invalidGroupRolePath['json']['error'] ?? '') === 'invalid role id', 'invalid group role path id must match Go error');

    $invalidGroupRoleBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/groups/00000000-0000-0000-0000-000000000000/roles',
        ['role_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidGroupRoleBody['status'] === 400, 'invalid group role body id must return 400');
    assertTrue(($invalidGroupRoleBody['json']['error'] ?? '') === 'invalid json body', 'invalid group role body id must match Go error');

    foreach ([
        ['GET', '/api/v1/users/not-a-uuid/groups'],
        ['POST', '/api/v1/users/not-a-uuid/groups'],
        ['DELETE', '/api/v1/users/not-a-uuid/groups/not-a-uuid'],
    ] as $invalidUserGroupRequest) {
        [$method, $path] = $invalidUserGroupRequest;
        $invalidUserId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidUserId['status'] === 400, $method . ' user-group path with invalid user id must return 400');
        assertTrue(($invalidUserId['json']['error'] ?? '') === 'invalid user id', $method . ' user-group path must match Go error');
    }

    $invalidUserGroupPath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/groups/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidUserGroupPath['status'] === 400, 'invalid user-group group id must return 400');
    assertTrue(($invalidUserGroupPath['json']['error'] ?? '') === 'invalid group id', 'invalid user-group group id must match Go error');

    $invalidUserGroupBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/groups',
        ['group_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidUserGroupBody['status'] === 400, 'invalid user-group body id must return 400');
    assertTrue(($invalidUserGroupBody['json']['error'] ?? '') === 'invalid json body', 'invalid user-group body id must match Go error');

    $badAuditActor = httpRequest('GET', $baseUrl . '/api/v1/audit?actor=not-a-uuid', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($badAuditActor['status'] === 400, 'audit with invalid actor must return 400');
    assertTrue(($badAuditActor['json']['error'] ?? '') === 'invalid actor id', 'audit invalid actor must match Go error');

    $malformedAuditActor = httpRequest('GET', $baseUrl . '/api/v1/audit?actor=------------------------------------', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($malformedAuditActor['status'] === 400, 'audit with malformed 36-character actor must return 400');
    assertTrue(($malformedAuditActor['json']['error'] ?? '') === 'invalid actor id', 'malformed audit actor must match Go error');

    $badAuditFrom = httpRequest('GET', $baseUrl . '/api/v1/audit?from=not-a-date', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($badAuditFrom['status'] === 400, 'audit with invalid from must return 400');
    assertTrue(($badAuditFrom['json']['error'] ?? '') === 'invalid from, expected RFC3339', 'audit invalid from must match Go error');

    $badAuditTo = httpRequest('GET', $baseUrl . '/api/v1/audit?to=not-a-date', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($badAuditTo['status'] === 400, 'audit with invalid to must return 400');
    assertTrue(($badAuditTo['json']['error'] ?? '') === 'invalid to, expected RFC3339', 'audit invalid to must match Go error');

    $fractionalAuditRange = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'from' => '2000-01-01T00:00:00.123456789Z',
        'to' => '2999-01-01T00:00:00.987654321+00:00',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($fractionalAuditRange['status'] === 200, 'audit must accept Go-compatible fractional RFC3339 timestamps');

    $auditLimitAction = 'integration_audit_limit_' . $suffix;
    $createdAuditActions[] = $auditLimitAction;
    $auditRows = [];
    for ($index = 0; $index < 55; $index++) {
        $auditRows[] = [$auditLimitAction, 'integration', (string)$index];
    }
    $db->createCommand()->batchInsert(
        'audit_logs',
        ['action', 'target_type', 'target_id'],
        $auditRows
    )->execute();

    foreach (['201', '-1', '12invalid'] as $invalidAuditLimit) {
        $auditDefaultLimit = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
            'action' => $auditLimitAction,
            'limit' => $invalidAuditLimit,
        ]), [
            'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
        ]);
        assertTrue($auditDefaultLimit['status'] === 200, 'audit with invalid limit must return 200');
        assertTrue(count($auditDefaultLimit['json'] ?? []) === 50, 'invalid audit limit must use Go default of 50');
    }

    $auditMaxLimit = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'action' => $auditLimitAction,
        'limit' => '200',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($auditMaxLimit['status'] === 200, 'audit with maximum limit must return 200');
    assertTrue(count($auditMaxLimit['json'] ?? []) === 55, 'audit limit 200 must not be reduced to the default');

    $auditLeadingZeroLimit = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'action' => $auditLimitAction,
        'limit' => '000200',
        'offset' => '12invalid',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($auditLeadingZeroLimit['status'] === 200, 'audit must accept a numeric limit with leading zeroes');
    assertTrue(count($auditLeadingZeroLimit['json'] ?? []) === 55, 'invalid audit offset must use Go default of zero');

    $missingIdentityFields = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => '',
        'client_id' => '',
    ], $adminToken);
    assertTrue($missingIdentityFields['status'] === 400, 'identity provider without code/client_id must return 400');
    assertTrue(($missingIdentityFields['json']['error'] ?? '') === 'code and client_id are required', 'identity provider missing fields must match Go error');

    $missingIdentityUrls = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => 'itest-oidc-missing-' . $suffix,
        'name' => 'Missing OIDC URLs',
        'type' => 'oidc',
        'client_id' => 'itest-idp-client-' . $suffix,
        'client_secret' => 'secret',
    ], $adminToken);
    assertTrue($missingIdentityUrls['status'] === 400, 'OIDC provider without URLs must return 400');
    assertTrue(($missingIdentityUrls['json']['error'] ?? '') === 'authorize_url, token_url and userinfo_url are required for type oidc', 'OIDC provider missing URLs must match Go error');

    $unknownIdentityProviderField = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => 'unknown-idp-' . $suffix,
        'client_id' => 'unknown-idp-client-' . $suffix,
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownIdentityProviderField['status'] === 400, 'identity provider JSON with unknown field must return 400');
    assertTrue(($unknownIdentityProviderField['json']['error'] ?? '') === 'invalid json body', 'unknown identity provider field must match Go error');

    foreach (['PUT', 'DELETE'] as $method) {
        $invalidIdentityProviderId = jsonRequest(
            $method,
            $baseUrl . '/api/v1/identity-providers/not-a-uuid',
            [],
            $adminToken
        );
        assertTrue($invalidIdentityProviderId['status'] === 400, $method . ' identity provider with invalid id must return 400');
        assertTrue(($invalidIdentityProviderId['json']['error'] ?? '') === 'invalid id', $method . ' identity provider must match Go error');
    }

    foreach (['PUT', 'DELETE'] as $method) {
        $missingIdentityProvider = $method === 'PUT'
            ? httpRequest($method, $baseUrl . '/api/v1/identity-providers/00000000-0000-0000-0000-000000000000', [
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $adminToken],
                'body' => '{}',
            ])
            : jsonRequest(
                $method,
                $baseUrl . '/api/v1/identity-providers/00000000-0000-0000-0000-000000000000',
                [],
                $adminToken
            );
        assertTrue($missingIdentityProvider['status'] === 404, $method . ' missing identity provider must return 404');
        assertTrue(($missingIdentityProvider['json']['error'] ?? '') === 'provider not found', $method . ' missing identity provider must match Go error');
    }

    $identityProvider = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => 'itest-idp-' . $suffix,
        'name' => 'Integration identity provider ' . $suffix,
        'type' => 'oidc',
        'client_id' => 'itest-idp-client-' . $suffix,
        'client_secret' => 'secret',
        'authorize_url' => 'https://idp.example.test/authorize',
        'token_url' => 'https://idp.example.test/token',
        'userinfo_url' => 'https://idp.example.test/userinfo',
        'scopes' => 'openid profile email',
        'enabled' => true,
    ], $adminToken);
    assertTrue($identityProvider['status'] === 201, 'create identity provider must return 201');
    $createdProviderIds[] = $identityProvider['json']['id'];
    assertTrue(!array_key_exists('client_secret', $identityProvider['json']), 'identity provider response must not expose client_secret');

    $duplicateIdentityProvider = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => $identityProvider['json']['code'],
        'name' => 'Duplicate identity provider',
        'type' => 'oidc',
        'client_id' => 'duplicate-idp-client-' . $suffix,
        'client_secret' => 'secret',
        'authorize_url' => 'https://idp.example.test/authorize',
        'token_url' => 'https://idp.example.test/token',
        'userinfo_url' => 'https://idp.example.test/userinfo',
    ], $adminToken);
    assertTrue($duplicateIdentityProvider['status'] === 409, 'duplicate identity provider code must return 409');
    assertTrue(($duplicateIdentityProvider['json']['error'] ?? '') === 'provider already exists', 'duplicate identity provider code must match Go error');

    $unknownIdentityUpdateField = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/identity-providers/00000000-0000-0000-0000-000000000000',
        ['unexpected' => true],
        $adminToken
    );
    assertTrue($unknownIdentityUpdateField['status'] === 400, 'identity provider update with unknown field must return 400');
    assertTrue(($unknownIdentityUpdateField['json']['error'] ?? '') === 'invalid json body', 'unknown identity provider update field must match Go error');

    $unknownLdapProviderField = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers', [
        'name' => 'Unknown LDAP field',
        'host' => 'ldap.example.test',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownLdapProviderField['status'] === 400, 'LDAP provider JSON with unknown field must return 400');
    assertTrue(($unknownLdapProviderField['json']['error'] ?? '') === 'invalid json body', 'unknown LDAP provider field must match Go error');

    $unknownLdapTestField = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers/test', [
        'host' => 'ldap.example.test',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownLdapTestField['status'] === 400, 'LDAP test JSON with unknown field must return 400');
    assertTrue(($unknownLdapTestField['json']['error'] ?? '') === 'invalid json body', 'unknown LDAP test field must match Go error');

    $unknownLdapMappingField = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000/group-mappings',
        [
            'ldap_group' => 'cn=unknown,dc=example,dc=test',
            'group_id' => '00000000-0000-0000-0000-000000000000',
            'unexpected' => true,
        ],
        $adminToken
    );
    assertTrue($unknownLdapMappingField['status'] === 400, 'LDAP mapping JSON with unknown field must return 400');
    assertTrue(($unknownLdapMappingField['json']['error'] ?? '') === 'invalid json body', 'unknown LDAP mapping field must match Go error');

    foreach ([
        ['PUT', '/api/v1/ldap/providers/not-a-uuid'],
        ['DELETE', '/api/v1/ldap/providers/not-a-uuid'],
        ['GET', '/api/v1/ldap/providers/not-a-uuid/group-mappings'],
        ['POST', '/api/v1/ldap/providers/not-a-uuid/group-mappings'],
    ] as $invalidLdapProviderRequest) {
        [$method, $path] = $invalidLdapProviderRequest;
        $invalidLdapProviderId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidLdapProviderId['status'] === 400, $method . ' LDAP provider path with invalid id must return 400');
        assertTrue(($invalidLdapProviderId['json']['error'] ?? '') === 'invalid id', $method . ' LDAP provider path must match Go error');
    }

    foreach (['PUT', 'DELETE'] as $method) {
        $missingLdapProvider = $method === 'PUT'
            ? httpRequest($method, $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000', [
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $adminToken],
                'body' => '{}',
            ])
            : jsonRequest(
                $method,
                $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000',
                [],
                $adminToken
            );
        assertTrue($missingLdapProvider['status'] === 404, $method . ' missing LDAP provider must return 404');
        assertTrue(($missingLdapProvider['json']['error'] ?? '') === 'provider not found', $method . ' missing LDAP provider must match Go error');
    }

    $invalidLdapMappingId = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/not-a-uuid/group-mappings/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidLdapMappingId['status'] === 400, 'LDAP mapping delete with invalid mapping id must return 400');
    assertTrue(($invalidLdapMappingId['json']['error'] ?? '') === 'invalid mapping id', 'LDAP mapping delete must match Go mapping id error');

    $missingLdapMapping = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/not-a-uuid/group-mappings/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingLdapMapping['status'] === 404, 'LDAP mapping delete must ignore parent id like Go and return 404');
    assertTrue(($missingLdapMapping['json']['error'] ?? '') === 'mapping not found', 'missing LDAP mapping must match Go error');

    $mfaEnroll = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/enroll', [], $adminToken);
    assertTrue($mfaEnroll['status'] === 200, 'Go-compatible MFA enroll must return 200');
    assertTrue(!empty($mfaEnroll['json']['secret']), 'MFA enroll must return secret');
    assertTrue(!empty($mfaEnroll['json']['otpauth_url']), 'MFA enroll must return Go-compatible otpauth_url');
    assertTrue(!empty($mfaEnroll['json']['otpauth_uri']), 'MFA enroll must keep PHP UI otpauth_uri');

    $mfaCounter = (int)floor(time() / 30);
    $mfaActivate = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/activate', [
        'code' => totpCode($mfaEnroll['json']['secret'], $mfaCounter),
    ], $adminToken);
    assertTrue($mfaActivate['status'] === 200, 'Go-compatible MFA activate must return 200');
    assertTrue(($mfaActivate['json']['status'] ?? '') === 'mfa_enabled', 'MFA activate must return mfa_enabled status');
    assertTrue(count($mfaActivate['json']['recovery_codes'] ?? []) === 8, 'MFA activate must return Go-compatible recovery code count');

    $repeatedMfaActivate = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/activate', [
        'code' => 'not-a-code',
    ], $adminToken);
    assertTrue($repeatedMfaActivate['status'] === 409, 'repeated MFA activate must return 409');
    assertTrue(($repeatedMfaActivate['json']['error'] ?? '') === 'enroll first', 'repeated MFA activate must match Go error');

    $mfaLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($mfaLogin['status'] === 200, 'MFA password step must return 200');
    assertTrue(($mfaLogin['json']['mfa_required'] ?? false) === true, 'MFA password step must require MFA');
    assertTrue(!empty($mfaLogin['json']['mfa_token']), 'MFA password step must return Go-compatible mfa_token');
    assertTrue(($mfaLogin['json']['challenge_token'] ?? '') === $mfaLogin['json']['mfa_token'], 'MFA password step must keep challenge_token alias');

    $unknownMfaField = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaLogin['json']['mfa_token'],
        'code' => 'not-a-code',
        'challenge_token' => $mfaLogin['json']['mfa_token'],
    ]);
    assertTrue($unknownMfaField['status'] === 400, 'MFA verify JSON with unknown field must return 400');
    assertTrue(($unknownMfaField['json']['error'] ?? '') === 'invalid json body', 'unknown MFA verify field must match Go error');

    $invalidMfaToken = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => 'unknown-token',
        'code' => 'not-a-code',
    ]);
    assertTrue($invalidMfaToken['status'] === 401, 'invalid MFA token must return 401');
    assertTrue(($invalidMfaToken['json']['error'] ?? '') === 'invalid mfa token', 'invalid MFA token must match Go error');

    $invalidMfaCode = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaLogin['json']['mfa_token'],
        'code' => 'not-a-code',
    ]);
    assertTrue($invalidMfaCode['status'] === 401, 'invalid MFA code must return 401');
    assertTrue(($invalidMfaCode['json']['error'] ?? '') === 'invalid code', 'invalid MFA code must match Go error');

    $mfaVerify = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaLogin['json']['mfa_token'],
        'code' => totpCode($mfaEnroll['json']['secret'], $mfaCounter + 1),
    ]);
    assertTrue($mfaVerify['status'] === 200, 'MFA verify with mfa_token must return 200');
    assertTrue(!empty($mfaVerify['json']['access_token']), 'MFA verify must issue access token');

    $unsupported = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'password',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($unsupported['status'] === 400, 'unsupported grant_type must return 400');
    assertTrue(($unsupported['json']['error'] ?? '') === 'unsupported_grant_type', 'unsupported grant_type must return unsupported_grant_type');

    $badSecret = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client['json']['client_id'],
        'client_secret' => 'wrong-secret',
    ]);
    assertOAuthError($badSecret, 401, 'invalid_client', 'bad client secret');
    assertTrue(responseHeader($badSecret, 'WWW-Authenticate') === 'Basic realm="sso"', 'token invalid_client must advertise Basic authentication');

    $basicOverridesForm = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ], [basicAuthHeader('', 'wrong-secret')]);
    assertOAuthError($basicOverridesForm, 401, 'invalid_client', 'Basic credentials overriding form credentials');

    $clientCredentials = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'scope' => 'profile',
    ]);
    assertTrue($clientCredentials['status'] === 200, 'client_credentials must return 200');
    assertTrue(!empty($clientCredentials['json']['access_token']), 'client_credentials must include access_token');
    assertTrue(($clientCredentials['json']['token_type'] ?? '') === 'Bearer', 'client_credentials must return Bearer token');
    assertTrue(empty($clientCredentials['json']['refresh_token']), 'client_credentials must not include refresh_token');
    assertTrue(empty($clientCredentials['json']['id_token']), 'client_credentials must not include id_token');

    $clientCredentialsClaims = jwtPart($clientCredentials['json']['access_token'], 1);
    assertTrue(($clientCredentialsClaims['client_id'] ?? '') === $client['json']['client_id'], 'client_credentials token must include client_id');
    assertTrue(($clientCredentialsClaims['purpose'] ?? '') === 'client_credentials', 'client_credentials token must include purpose');

    $badClientCredentialsScope = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'scope' => 'not-allowed',
    ]);
    assertOAuthError($badClientCredentialsScope, 400, 'invalid_scope', 'client_credentials with invalid scope');

    $badRevokeClient = formRequest($baseUrl . '/oauth2/revoke', [
        'token' => 'not-a-token',
        'client_id' => $client['json']['client_id'],
        'client_secret' => 'wrong-secret',
    ]);
    assertOAuthError($badRevokeClient, 401, 'invalid_client', 'revoke with bad client secret');
    assertTrue(responseHeader($badRevokeClient, 'WWW-Authenticate') === 'Basic realm="sso"', 'revoke invalid_client must advertise Basic authentication');

    $badIntrospectClient = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => 'not-a-token',
        'client_id' => $client['json']['client_id'],
        'client_secret' => 'wrong-secret',
    ]);
    assertOAuthError($badIntrospectClient, 401, 'invalid_client', 'introspect with bad client secret');
    assertTrue(responseHeader($badIntrospectClient, 'WWW-Authenticate') === 'Basic realm="sso"', 'introspect invalid_client must advertise Basic authentication');

    $badScopeUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'http://localhost/cb',
        'scope' => 'not-allowed',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    $badScope = httpRequest('GET', $badScopeUrl, ['cookies' => $sessionCookie]);
    assertTrue($badScope['status'] === 400, 'invalid authorize scope must return 400');
    assertTrue(($badScope['json']['error'] ?? '') === 'invalid_scope', 'invalid authorize scope must return invalid_scope');

    $publicClient = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Integration public client ' . $suffix,
        'client_id' => 'itest-public-' . $suffix,
        'type' => 'public',
        'redirect_uris' => ['http://localhost/public-cb'],
        'allowed_scopes' => ['openid', 'profile'],
        'skip_consent' => true,
        'enabled' => true,
    ], $adminToken);
    assertTrue($publicClient['status'] === 201, 'create public oauth client must return 201');
    assertTrue(isset($publicClient['json']['client']), 'create public client must return nested client');
    $publicClient['json'] = $publicClient['json']['client'];
    $createdClientIds[] = $publicClient['json']['id'];

    $missingPkceUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $publicClient['json']['client_id'],
        'redirect_uri' => 'http://localhost/public-cb',
        'scope' => 'openid profile',
    ]);
    $missingPkce = httpRequest('GET', $missingPkceUrl, ['cookies' => $sessionCookie]);
    assertTrue($missingPkce['status'] === 400, 'public client without PKCE S256 must return 400');
    assertTrue(($missingPkce['json']['error'] ?? '') === 'invalid_request', 'public client without PKCE must return invalid_request');
    assertTrue(($missingPkce['json']['error_description'] ?? '') === 'PKCE S256 required', 'public client without PKCE must describe PKCE S256 requirement');

    $publicClientCredentials = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $publicClient['json']['client_id'],
        'scope' => 'profile',
    ]);
    assertOAuthError($publicClientCredentials, 401, 'invalid_client', 'public client_credentials grant');

    $badLogout = httpRequest('GET', $baseUrl . '/oauth2/logout?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => 'http://localhost/not-registered',
    ]), ['cookies' => $sessionCookie]);
    assertTrue($badLogout['status'] === 400, 'unregistered post_logout_redirect_uri must return 400');
    assertTrue(($badLogout['json']['error'] ?? '') === 'invalid_request', 'unregistered post_logout_redirect_uri must return invalid_request');

    $logout = httpRequest('GET', $baseUrl . '/oauth2/logout?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => 'http://localhost/logout-cb',
        'state' => 'logout-state-' . $suffix,
    ]));
    assertTrue($logout['status'] >= 300 && $logout['status'] < 400, 'registered logout redirect must redirect');
    assertTrue(strpos($logout['location'], 'http://localhost/logout-cb') === 0, 'logout must redirect to registered uri');
    assertTrue(strpos($logout['location'], 'state=logout-state-' . $suffix) !== false, 'logout redirect must preserve state');

    $invalidMfaDisable = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/disable', [
        'code' => 'not-a-code',
    ], $mfaVerify['json']['access_token']);
    assertTrue($invalidMfaDisable['status'] === 400, 'invalid MFA disable code must return 400');
    assertTrue(($invalidMfaDisable['json']['error'] ?? '') === 'invalid code', 'invalid MFA disable code must match Go error');

    $mfaDisable = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/disable', [
        'code' => $mfaActivate['json']['recovery_codes'][0],
    ], $mfaVerify['json']['access_token']);
    assertTrue($mfaDisable['status'] === 200, 'MFA disable must return 200');
    assertTrue(($mfaDisable['json']['status'] ?? '') === 'mfa_disabled', 'MFA disable must return Go-compatible status');

    $tokenAfterMfaDisable = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $mfaVerify['json']['access_token']);
    assertTrue($tokenAfterMfaDisable['status'] === 200, 'MFA disable must keep the current Bearer token valid like Go');

    $repeatedMfaDisable = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/disable', [
        'code' => 'not-a-code',
    ], $mfaVerify['json']['access_token']);
    assertTrue($repeatedMfaDisable['status'] === 400, 'repeated MFA disable must return 400');
    assertTrue(($repeatedMfaDisable['json']['error'] ?? '') === 'mfa is not in the required state', 'repeated MFA disable must match Go error');

    $finalRevokeAll = jsonRequest('DELETE', $baseUrl . '/api/v1/auth/sessions', [], $mfaVerify['json']['access_token']);
    assertTrue($finalRevokeAll['status'] === 200, 'final revoke all must return 200');

    $firstPartyAfterRevokeAll = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $mfaVerify['json']['access_token']);
    assertTrue($firstPartyAfterRevokeAll['status'] === 401, 'revoke all must invalidate first-party token');

    $externalTokenAfterRevokeAll = jsonRequest('GET', $baseUrl . '/oauth2/userinfo', [], $token['json']['access_token']);
    assertTrue($externalTokenAfterRevokeAll['status'] === 200, 'revoke all must preserve external OAuth access token');
    assertTrue(($externalTokenAfterRevokeAll['json']['sub'] ?? '') === $adminId, 'preserved external token must still resolve its user');

    fwrite(STDOUT, "OK: integration OAuth/OIDC smoke flow passed\n");
} finally {
    foreach ($createdAuditTargets as $auditTarget) {
        $db->createCommand()->delete('audit_logs', $auditTarget)->execute();
    }
    foreach ($createdAuditActions as $action) {
        $db->createCommand()->delete('audit_logs', ['action' => $action])->execute();
    }
    foreach ($createdClientIds as $clientId) {
        $db->createCommand('DELETE FROM oauth_clients WHERE id = :id', [':id' => $clientId])->execute();
    }
    foreach ($createdGroupIds as $groupId) {
        $db->createCommand('DELETE FROM groups WHERE id = :id', [':id' => $groupId])->execute();
    }
    foreach ($createdProviderIds as $providerId) {
        $db->createCommand('DELETE FROM identity_providers WHERE id = :id', [':id' => $providerId])->execute();
    }
    foreach ($createdUserIds as $userId) {
        $db->createCommand('DELETE FROM users WHERE id = :id', [':id' => $userId])->execute();
    }
}
