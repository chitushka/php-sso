<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../../scripts/ci/OpenApiValidator.php';

$config = require __DIR__ . '/../../config/console.php';
$app = new yii\console\Application($config);
$db = Yii::$app->db;
$baseUrl = rtrim(getenv('SSO_TEST_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$backchannelUrl = rtrim(getenv('SSO_TEST_BACKCHANNEL_URL') ?: 'http://127.0.0.1:8082', '/');
$suffix = strtolower(bin2hex(random_bytes(4)));
$adminUsername = 'itest-admin-' . $suffix;
$userUsername = 'itest-user-' . $suffix;
$password = 'IntegrationPass123!';
$createdUserIds = [];
$createdClientIds = [];
$createdRoleIds = [];
$createdGroupIds = [];
$createdProviderIds = [];
$createdLdapProviderIds = [];
$createdAuditActions = [];
$createdAuditTargets = [];
$adminContract = new OpenApiValidator(__DIR__ . '/../../contracts/openapi-admin.json');

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
        if (strtoupper($method) !== 'GET' && isset($cookies['sso_csrf']) && !($options['omit_csrf'] ?? false)) {
            $headers[] = 'X-CSRF-Token: ' . $cookies['sso_csrf'];
        }
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
    return 'Authorization: Basic ' . base64_encode(urlencode($username) . ':' . urlencode($password));
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

function responseCookieHasAttribute(array $response, string $cookieName, string $attribute): bool
{
    foreach ($response['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*' . preg_quote($cookieName, '/') . '=/i', $header) !== 1) {
            continue;
        }
        if (preg_match('/;\s*' . preg_quote($attribute, '/') . '(?:[=;]|$)/i', $header) === 1) {
            return true;
        }
    }

    return false;
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
    if ((string)getenv('SSO_CACHE_DRIVER') === 'db') {
        assertTrue(Yii::$app->cache instanceof yii\caching\DbCache, 'DB cache driver must use Yii DbCache');
        $cacheKey = 'integration-cache-' . $suffix;
        assertTrue(Yii::$app->cache->set($cacheKey, 'ok', 60), 'DB cache must accept writes');
        assertTrue(Yii::$app->cache->get($cacheKey) === 'ok', 'DB cache must return written values');
        Yii::$app->cache->delete($cacheKey);
    }

    $live = httpRequest('GET', $baseUrl . '/health/live');
    assertTrue($live['status'] === 200, 'liveness endpoint must return 200');
    assertTrue(($live['json']['status'] ?? '') === 'ok', 'liveness endpoint must return contract-compliant status');

    $ready = httpRequest('GET', $baseUrl . '/health/ready');
    assertTrue($ready['status'] === 200, 'readiness endpoint must return 200 while PostgreSQL is available');
    assertTrue(($ready['json']['status'] ?? '') === 'ready', 'readiness endpoint must return contract-compliant status');

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
    assertTrue(($repeatedBootstrap['json']['error'] ?? '') === 'system already initialized', 'repeated bootstrap must match API contract');

    $plainUserId = createUser($db, $userUsername, $password);
    $createdUserIds[] = $plainUserId;

    $login = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($login['status'] === 200, 'admin login must return 200');
    assertTrue(!empty($login['json']['access_token']), 'admin login must return access_token');
    $firstPartyClaims = jwtPart($login['json']['access_token'], 1);
    assertTrue(($firstPartyClaims['iss'] ?? '') === Yii::$app->params['issuer'], 'first-party token issuer must match configuration');
    assertTrue(($firstPartyClaims['aud'] ?? '') === Yii::$app->params['apiAudience'], 'first-party token audience must match API');
    assertTrue(($firstPartyClaims['purpose'] ?? '') === \app\services\JwtService::PURPOSE_FIRST_PARTY, 'first-party token must have an explicit purpose');
    assertTrue(($firstPartyClaims['nbf'] ?? null) === ($firstPartyClaims['iat'] ?? null), 'first-party token must have a consistent nbf');
    assertTrue(strpos((string)responseHeader($login, 'Cache-Control'), 'no-store') !== false, 'login response must not be cached');
    assertTrue(responseHeader($login, 'Pragma') === 'no-cache', 'login response must disable shared caches');
    assertTrue(responseHeader($login, 'Permissions-Policy') !== null, 'login response must include Permissions-Policy');
    assertTrue(!array_key_exists('session_token', $login['json']), 'login must not expose session_token to JavaScript');
    assertTrue(!empty($login['json']['csrf_token']), 'login must return csrf_token');
    assertTrue(
        ($login['json']['csrf_token'] ?? '') === ($login['cookies']['sso_csrf'] ?? ''),
        'login csrf_token must match sso_csrf cookie'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($login['json']['access_token_expires_at'] ?? '')) === 1,
        'login access_token_expires_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($login['json']['session_expires_at'] ?? '')) === 1,
        'login session_expires_at must use contract-compliant RFC3339'
    );
    assertTrue(!array_key_exists('token_type', $login['json']), 'login must not return OAuth-only token_type');
    assertTrue(!array_key_exists('expires_in', $login['json']), 'login must not return OAuth-only expires_in');
    assertTrue(strpos((string)$login['body'], '"attributes":{}') !== false, 'empty user attributes must be a contract-compliant JSON object');
    assertTrue(!array_key_exists('ldap_provider_id', $login['json']['user']), 'local user must omit empty ldap_provider_id');
    assertTrue(!array_key_exists('ldap_dn', $login['json']['user']), 'local user must omit empty ldap_dn');
    assertTrue(!array_key_exists('last_login_at', $login['json']['user']), 'first login user must omit empty last_login_at');
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($login['json']['user']['created_at'] ?? '')) === 1,
        'user created_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($login['json']['user']['updated_at'] ?? '')) === 1,
        'user updated_at must use contract-compliant RFC3339'
    );
    assertTrue(!empty($login['cookies']['sso_session']), 'admin login must set sso_session cookie');
    if ((bool)(Yii::$app->params['cookieSecure'] ?? false)) {
        assertTrue(
            responseCookieHasAttribute($login, 'sso_session', 'Secure'),
            'HTTPS profile must set Secure on sso_session cookie'
        );
    }

    $adminToken = $login['json']['access_token'];
    $sessionCookie = [
        'sso_session' => $login['cookies']['sso_session'],
        'sso_csrf' => $login['cookies']['sso_csrf'],
    ];

    $anonymousOperations = jsonRequest('GET', $baseUrl . '/api/v1/operations/status', []);
    assertTrue($anonymousOperations['status'] === 401, 'operational status must require authentication');
    $operationalStatus = jsonRequest('GET', $baseUrl . '/api/v1/operations/status', [], $adminToken);
    assertTrue($operationalStatus['status'] === 200, 'admin must read operational status');
    assertTrue(($operationalStatus['json']['status'] ?? '') === 'degraded', 'missing cleanup history must degrade operational status');
    assertTrue(($operationalStatus['json']['database']['status'] ?? '') === 'ready', 'operational status must expose database component state');
    assertTrue(($operationalStatus['json']['database']['ready'] ?? false) === true, 'operational status must report database readiness');
    assertTrue(($operationalStatus['json']['signing_keys']['status'] ?? '') === 'ready', 'operational status must verify the active signing key');
    assertTrue(($operationalStatus['json']['cleanup']['status'] ?? '') === 'degraded', 'missing cleanup timestamp must be degraded');
    assertTrue(($operationalStatus['json']['cleanup']['stale'] ?? false) === true, 'missing cleanup timestamp must be stale');
    assertTrue(($operationalStatus['json']['cleanup']['interval_seconds'] ?? 0) > 0, 'operational status must expose cleanup interval');
    $rotatedSigningKey = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/operations/signing-keys/rotate',
        [],
        $adminToken
    );
    assertTrue($rotatedSigningKey['status'] === 201, 'admin must rotate the signing key');
    assertTrue(($rotatedSigningKey['json']['status'] ?? '') === 'active', 'rotated signing key must be active');
    assertTrue(!array_key_exists('private_key_pem', $rotatedSigningKey['json']), 'signing API must not expose private key material');
    assertTrue(!array_key_exists('public_key_pem', $rotatedSigningKey['json']), 'signing metadata API must not expose PEM material');

    $missingBearer = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', []);
    assertTrue($missingBearer['status'] === 401, 'protected endpoint without bearer token must return 401');
    assertTrue(($missingBearer['json']['error'] ?? '') === 'missing bearer token', 'missing bearer token must match API contract');

    $invalidBearer = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], 'not-a-jwt');
    assertTrue($invalidBearer['status'] === 401, 'protected endpoint with invalid bearer token must return 401');
    assertTrue(($invalidBearer['json']['error'] ?? '') === 'invalid token', 'invalid bearer token must match API contract');

    $missingAdminBearer = jsonRequest('GET', $baseUrl . '/api/v1/users', []);
    assertTrue($missingAdminBearer['status'] === 401, 'admin endpoint without bearer token must return 401');
    assertTrue(($missingAdminBearer['json']['error'] ?? '') === 'missing bearer token', 'admin bearer middleware must match API contract');

    $missingUserinfoBearer = jsonRequest('GET', $baseUrl . '/oauth2/userinfo', []);
    assertTrue($missingUserinfoBearer['status'] === 401, 'userinfo without bearer token must return 401');
    assertTrue(($missingUserinfoBearer['json']['error'] ?? '') === 'missing bearer token', 'userinfo bearer middleware must match API contract');

    $malformedLogin = httpRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '{"username":',
    ]);
    assertTrue($malformedLogin['status'] === 400, 'malformed login JSON must return 400');
    assertTrue(($malformedLogin['json']['error'] ?? '') === 'invalid json body', 'malformed login JSON must match API contract');

    foreach (['{"username":"admin"}{"password":"ignored"}', '{"username":"admin"} trailing'] as $invalidJsonStream) {
        $streamLogin = httpRequest('POST', $baseUrl . '/api/v1/auth/login', [
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            'body' => $invalidJsonStream,
        ]);
        assertTrue($streamLogin['status'] === 400, 'request body with trailing JSON data must return 400');
        assertTrue(
            ($streamLogin['json']['error'] ?? '') === 'invalid json body',
            'request body with trailing JSON data must match strict JSON contract'
        );
    }

    $malformedForgot = httpRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '{"login":',
    ]);
    assertTrue($malformedForgot['status'] === 400, 'malformed password forgot JSON must return 400');
    assertTrue(($malformedForgot['json']['error'] ?? '') === 'login is required', 'malformed password forgot JSON must match API contract');

    $arrayForgot = httpRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '[]',
    ]);
    assertTrue($arrayForgot['status'] === 400, 'non-object password forgot JSON must return 400');
    assertTrue(($arrayForgot['json']['error'] ?? '') === 'login is required', 'non-object password forgot JSON must match API contract');

    $unknownForgotField = jsonRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', [
        'login' => $adminUsername,
        'unexpected' => true,
    ]);
    assertTrue($unknownForgotField['status'] === 400, 'password forgot JSON with unknown field must return 400');
    assertTrue(($unknownForgotField['json']['error'] ?? '') === 'login is required', 'password forgot decode failure must match API contract');

    $arrayResetBody = httpRequest('POST', $baseUrl . '/api/v1/auth/password/reset', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '[]',
    ]);
    assertTrue($arrayResetBody['status'] === 400, 'non-object account JSON must return 400');
    assertTrue(($arrayResetBody['json']['error'] ?? '') === 'invalid json body', 'non-object account JSON must match API contract');

    $unknownLoginField = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
        'unexpected' => true,
    ]);
    assertTrue($unknownLoginField['status'] === 400, 'login JSON with unknown field must return 400');
    assertTrue(($unknownLoginField['json']['error'] ?? '') === 'invalid json body', 'unknown login field must match API contract');

    $typedLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => 42,
        'password' => $password,
    ]);
    assertTrue($typedLogin['status'] === 400, 'login JSON with numeric username must return 400');
    assertTrue(($typedLogin['json']['error'] ?? '') === 'invalid json body', 'typed login error must match JSON contract');

    $caseInsensitiveLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'Username' => $adminUsername,
        'Password' => $password,
    ]);
    assertTrue($caseInsensitiveLogin['status'] === 400, 'JSON field names must be case-sensitive');
    assertTrue(
        ($caseInsensitiveLogin['json']['error'] ?? '') === 'invalid json body',
        'case-mismatched JSON fields must be rejected by the API contract'
    );

    $contentTypeIndependentLogin = httpRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'headers' => ['Accept: application/json'],
        'body' => json_encode(['username' => $adminUsername, 'password' => $password]),
    ]);
    assertTrue($contentTypeIndependentLogin['status'] === 200, 'API JSON decoding must not depend on Content-Type');
    httpRequest('POST', $baseUrl . '/api/v1/auth/logout', [
        'cookies' => [
            'sso_session' => $contentTypeIndependentLogin['cookies']['sso_session'],
            'sso_csrf' => $contentTypeIndependentLogin['cookies']['sso_csrf'],
        ],
        'headers' => ['Accept: application/json'],
    ]);

    $nullLogin = httpRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => 'null',
    ]);
    assertTrue($nullLogin['status'] === 401, 'literal null must decode as an empty login request');

    $emptyLogin = httpRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '',
    ]);
    assertTrue($emptyLogin['status'] === 400, 'empty JSON body must reject an empty JSON document');
    assertTrue(($emptyLogin['json']['error'] ?? '') === 'invalid json body', 'empty JSON body must match API contract');

    $unknownResetField = jsonRequest('POST', $baseUrl . '/api/v1/auth/password/reset', [
        'token' => 'unknown-token',
        'password' => $password,
        'unexpected' => true,
    ]);
    assertTrue($unknownResetField['status'] === 400, 'password reset JSON with unknown field must return 400');
    assertTrue(($unknownResetField['json']['error'] ?? '') === 'invalid json body', 'unknown password reset field must match API contract');

    $secondLoginPayload = [
        'username' => $adminUsername,
        'password' => $password,
    ];
    $adminContract->validateRequest('POST', '/api/v1/auth/login', 'application/json', $secondLoginPayload);
    $secondLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', $secondLoginPayload);
    assertTrue($secondLogin['status'] === 200, 'second admin login must return 200');
    $adminContract->validateResponse(
        'POST',
        '/api/v1/auth/login',
        $secondLogin['status'],
        $secondLogin['json']
    );

    $sessions = jsonRequest('GET', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue($sessions['status'] === 200, 'session list must return 200');
    $adminContract->validateResponse('GET', '/api/v1/auth/sessions', $sessions['status'], $sessions['json']);
    assertTrue(count($sessions['json'] ?? []) === 2, 'session list must include both active sessions');
    assertTrue(($sessions['json'][0]['user_id'] ?? '') === $adminId, 'session list must include contract-compliant user_id');
    assertTrue(!array_key_exists('token_hash', $sessions['json'][0]), 'session list must not expose token hash');
    assertTrue(!array_key_exists('revoked_at', $sessions['json'][0]), 'active session must omit empty revoked_at');
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($sessions['json'][0]['expires_at'] ?? '')) === 1,
        'session expires_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($sessions['json'][0]['created_at'] ?? '')) === 1,
        'session created_at must use contract-compliant RFC3339'
    );

    $invalidSession = jsonRequest('DELETE', $baseUrl . '/api/v1/auth/sessions/not-a-uuid', [], $adminToken);
    assertTrue($invalidSession['status'] === 400, 'invalid session id must return 400');
    $adminContract->validateResponse(
        'DELETE',
        '/api/v1/auth/sessions/{id}',
        $invalidSession['status'],
        $invalidSession['json']
    );
    assertTrue(($invalidSession['json']['error'] ?? '') === 'invalid session id', 'invalid session id must match API contract');

    $revokedSession = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/auth/sessions/' . rawurlencode($sessions['json'][0]['id']),
        [],
        $adminToken
    );
    assertTrue($revokedSession['status'] === 200, 'owned session revoke must return 200');
    $adminContract->validateResponse(
        'DELETE',
        '/api/v1/auth/sessions/{id}',
        $revokedSession['status'],
        $revokedSession['json']
    );
    assertTrue(($revokedSession['json']['status'] ?? '') === 'revoked', 'owned session revoke must return revoked status');

    $remainingSessions = jsonRequest('GET', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue($remainingSessions['status'] === 200, 'remaining session list must return 200');
    $adminContract->validateResponse(
        'GET',
        '/api/v1/auth/sessions',
        $remainingSessions['status'],
        $remainingSessions['json']
    );
    assertTrue(count($remainingSessions['json'] ?? []) === 1, 'revoked session must disappear from active session list');

    $revokeAll = jsonRequest('DELETE', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue($revokeAll['status'] === 200, 'revoke all sessions must return 200');
    $adminContract->validateResponse(
        'DELETE',
        '/api/v1/auth/sessions',
        $revokeAll['status'],
        $revokeAll['json']
    );
    assertTrue(($revokeAll['json']['status'] ?? '') === 'all_revoked', 'revoke all sessions must return all_revoked status');

    $revokedToken = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $adminToken);
    assertTrue($revokedToken['status'] === 401, 'revoke all sessions must invalidate existing bearer token');
    $adminContract->validateResponse(
        'GET',
        '/api/v1/auth/me',
        $revokedToken['status'],
        $revokedToken['json']
    );
    assertTrue(($revokedToken['json']['error'] ?? '') === 'token revoked', 'revoked first-party token must match API contract');

    $reloginPayload = [
        'username' => $adminUsername,
        'password' => $password,
    ];
    $adminContract->validateRequest('POST', '/api/v1/auth/login', 'application/json', $reloginPayload);
    $login = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', $reloginPayload);
    assertTrue($login['status'] === 200, 'admin relogin after revoke all must return 200');
    $adminContract->validateResponse('POST', '/api/v1/auth/login', $login['status'], $login['json']);
    $adminToken = $login['json']['access_token'];
    $sessionCookie = [
        'sso_session' => $login['cookies']['sso_session'],
        'sso_csrf' => $login['cookies']['sso_csrf'],
    ];

    $accountUsername = 'itest-account-' . $suffix;
    $accountPassword = 'AccountPass123!';
    $resetPassword = 'ResetPass123!';
    $changedPassword = 'ChangedPass123!';
    $accountId = createUser($db, $accountUsername, $accountPassword);
    $createdUserIds[] = $accountId;

    $accountLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $accountUsername,
        'password' => $accountPassword,
    ]);
    assertTrue($accountLogin['status'] === 200, 'account contract login must return 200');
    $accountToken = $accountLogin['json']['access_token'];

    $profilePayload = [
        'first_name' => ' Ada ',
        'last_name' => ' Lovelace ',
        'attributes' => ['department' => 'Security', 'level' => '3'],
    ];
    $adminContract->validateRequest('PUT', '/api/v1/auth/profile', 'application/json', $profilePayload);
    $profile = jsonRequest('PUT', $baseUrl . '/api/v1/auth/profile', $profilePayload, $accountToken);
    assertTrue($profile['status'] === 200, 'profile update must return 200');
    $adminContract->validateResponse('PUT', '/api/v1/auth/profile', $profile['status'], $profile['json']);
    assertTrue(($profile['json']['first_name'] ?? '') === 'Ada', 'profile update must trim first_name');
    assertTrue(($profile['json']['last_name'] ?? '') === 'Lovelace', 'profile update must trim last_name');

    $db->createCommand()->update('users', ['email_verified' => false], ['id' => $accountId])->execute();
    $emailRequest = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/auth/email/request',
        [],
        $accountToken
    );
    assertTrue($emailRequest['status'] === 500, 'email request without configured SMTP must return 500');
    $adminContract->validateResponse(
        'POST',
        '/api/v1/auth/email/request',
        $emailRequest['status'],
        $emailRequest['json']
    );

    $emailToken = 'verify-' . bin2hex(random_bytes(16));
    $db->createCommand(
        "INSERT INTO one_time_tokens(user_id,purpose,token_hash,expires_at)
         VALUES (:user_id,'email_verify',:token_hash,now() + interval '1 hour')",
        [':user_id' => $accountId, ':token_hash' => hash('sha256', $emailToken)]
    )->execute();
    $emailVerifyPayload = ['token' => $emailToken];
    $adminContract->validateRequest(
        'POST',
        '/api/v1/auth/email/verify',
        'application/json',
        $emailVerifyPayload
    );
    $emailVerify = jsonRequest('POST', $baseUrl . '/api/v1/auth/email/verify', $emailVerifyPayload);
    assertTrue($emailVerify['status'] === 200, 'email verification must return 200');
    $adminContract->validateResponse(
        'POST',
        '/api/v1/auth/email/verify',
        $emailVerify['status'],
        $emailVerify['json']
    );

    $forgotPayload = ['login' => $accountUsername];
    $adminContract->validateRequest(
        'POST',
        '/api/v1/auth/password/forgot',
        'application/json',
        $forgotPayload
    );
    $forgot = jsonRequest('POST', $baseUrl . '/api/v1/auth/password/forgot', $forgotPayload);
    assertTrue($forgot['status'] === 200, 'password forgot must return 200');
    $adminContract->validateResponse(
        'POST',
        '/api/v1/auth/password/forgot',
        $forgot['status'],
        $forgot['json']
    );

    $resetToken = 'reset-' . bin2hex(random_bytes(16));
    $db->createCommand(
        "INSERT INTO one_time_tokens(user_id,purpose,token_hash,expires_at)
         VALUES (:user_id,'password_reset',:token_hash,now() + interval '1 hour')",
        [':user_id' => $accountId, ':token_hash' => hash('sha256', $resetToken)]
    )->execute();
    $resetPayload = ['token' => $resetToken, 'password' => $resetPassword];
    $adminContract->validateRequest(
        'POST',
        '/api/v1/auth/password/reset',
        'application/json',
        $resetPayload
    );
    $reset = jsonRequest('POST', $baseUrl . '/api/v1/auth/password/reset', $resetPayload);
    assertTrue($reset['status'] === 200, 'password reset must return 200');
    $adminContract->validateResponse(
        'POST',
        '/api/v1/auth/password/reset',
        $reset['status'],
        $reset['json']
    );

    $accountLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $accountUsername,
        'password' => $resetPassword,
    ]);
    assertTrue($accountLogin['status'] === 200, 'login after password reset must return 200');
    $accountToken = $accountLogin['json']['access_token'];

    $changePasswordPayload = [
        'old_password' => $resetPassword,
        'new_password' => $changedPassword,
    ];
    $adminContract->validateRequest(
        'POST',
        '/api/v1/auth/password/change',
        'application/json',
        $changePasswordPayload
    );
    $passwordChange = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/auth/password/change',
        $changePasswordPayload,
        $accountToken
    );
    assertTrue($passwordChange['status'] === 200, 'password change must return 200');
    $adminContract->validateResponse(
        'POST',
        '/api/v1/auth/password/change',
        $passwordChange['status'],
        $passwordChange['json']
    );

    $backchannelReset = httpRequest('POST', $backchannelUrl . '/reset');
    assertTrue($backchannelReset['status'] === 204, 'back-channel logout fixture must be available');

    $client = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Integration test client ' . $suffix,
        'client_id' => 'itest-client-' . $suffix,
        'type' => 'confidential',
        'redirect_uris' => ['https://rp.example.test/cb'],
        'allowed_scopes' => ['openid', 'profile', 'email', 'signing_keys:rotate', 'users:create'],
        'post_logout_redirect_uris' => ['https://rp.example.test/logout-cb'],
        'backchannel_logout_uri' => $backchannelUrl . '/logout',
        'skip_consent' => true,
        'enabled' => true,
    ], $adminToken);
    assertTrue(
        $client['status'] === 201,
        'create oauth client must return 201: ' . json_encode($client, JSON_UNESCAPED_SLASHES)
    );
    assertTrue(!empty($client['json']['client_secret']), 'create oauth client must return client_secret');
    assertTrue(isset($client['json']['client']), 'create oauth client must return contract-compliant nested client');
    $clientSecret = $client['json']['client_secret'];
    $client['json'] = $client['json']['client'];
    $client['json']['client_secret'] = $clientSecret;
    $createdClientIds[] = $client['json']['id'];
    $createdAuditTargets[] = ['action' => 'refresh_token_revoked', 'target_id' => $client['json']['client_id']];
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($client['json']['created_at'] ?? '')) === 1,
        'OAuth client created_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($client['json']['updated_at'] ?? '')) === 1,
        'OAuth client updated_at must use contract-compliant RFC3339'
    );

    $db->createCommand()->update(
        'oauth_clients',
        ['updated_at' => '2000-01-01 00:00:00+00'],
        ['id' => $client['json']['id']]
    )->execute();
    $updatedClient = jsonRequest('PUT', $baseUrl . '/api/v1/oauth/clients/' . $client['json']['id'], [
        'name' => $client['json']['name'],
        'redirect_uris' => $client['json']['redirect_uris'],
        'allowed_scopes' => $client['json']['allowed_scopes'],
        'post_logout_redirect_uris' => $client['json']['post_logout_redirect_uris'],
        'backchannel_logout_uri' => $client['json']['backchannel_logout_uri'],
        'skip_consent' => $client['json']['skip_consent'],
        'enabled' => true,
    ], $adminToken);
    assertTrue($updatedClient['status'] === 200, 'OAuth client update must return 200');
    assertTrue(strtotime((string)$updatedClient['json']['updated_at']) > strtotime('2020-01-01T00:00:00Z'), 'OAuth client update must advance updated_at');

    $rotatedClient = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/oauth/clients/' . $client['json']['id'] . '/rotate-secret',
        [],
        $adminToken
    );
    assertTrue($rotatedClient['status'] === 200, 'OAuth client secret rotation must return 200');
    assertTrue(!empty($rotatedClient['json']['client_secret']), 'OAuth client secret rotation must return new secret');
    assertTrue($rotatedClient['json']['client_secret'] !== $clientSecret, 'OAuth client secret rotation must replace secret');
    assertTrue(strtotime((string)$rotatedClient['json']['updated_at']) > strtotime('2020-01-01T00:00:00Z'), 'OAuth client secret rotation must advance updated_at');
    $oldSecretAfterRotation = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $clientSecret,
        'scope' => 'profile',
    ]);
    assertOAuthError($oldSecretAfterRotation, 401, 'invalid_client', 'rotated client secret');
    $client['json']['client_secret'] = $rotatedClient['json']['client_secret'];

    $unknownOAuthClientField = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Unknown field client ' . $suffix,
        'client_id' => 'unknown-field-client-' . $suffix,
        'type' => 'confidential',
        'redirect_uris' => ['https://rp.example.test/unknown-field-cb'],
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownOAuthClientField['status'] === 400, 'OAuth client JSON with unknown field must return 400');
    assertTrue(($unknownOAuthClientField['json']['error'] ?? '') === 'invalid json body', 'unknown OAuth client field must match API contract');

    $typedOAuthClient = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'client_id' => 'typed-client-' . $suffix,
        'name' => 'Typed client',
        'redirect_uris' => ['https://rp.example.test/typed', 7],
        'enabled' => 'false',
    ], $adminToken);
    assertTrue($typedOAuthClient['status'] === 400, 'OAuth client with invalid slice/bool types must return 400');
    assertTrue(($typedOAuthClient['json']['error'] ?? '') === 'invalid json body', 'typed OAuth client error must match JSON contract');

    foreach ([
        ['GET', '/api/v1/oauth/clients/not-a-uuid'],
        ['PUT', '/api/v1/oauth/clients/not-a-uuid'],
        ['DELETE', '/api/v1/oauth/clients/not-a-uuid'],
        ['POST', '/api/v1/oauth/clients/not-a-uuid/rotate-secret'],
    ] as $invalidClientRequest) {
        [$method, $path] = $invalidClientRequest;
        $invalidClientId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidClientId['status'] === 400, $method . ' OAuth client with invalid id must return 400');
        assertTrue(($invalidClientId['json']['error'] ?? '') === 'invalid id', $method . ' OAuth client must match API contract');
    }

    $missingClient = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/oauth/clients/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingClient['status'] === 404, 'missing OAuth client must return 404');
    assertTrue(($missingClient['json']['error'] ?? '') === 'client not found', 'missing OAuth client must match API contract');

    $unknownOAuthUpdateField = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/oauth/clients/00000000-0000-0000-0000-000000000000',
        ['type' => 'public'],
        $adminToken
    );
    assertTrue($unknownOAuthUpdateField['status'] === 400, 'OAuth client update with unsupported type field must return 400');
    assertTrue(($unknownOAuthUpdateField['json']['error'] ?? '') === 'invalid json body', 'unknown OAuth client update field must match API contract');

    $missingConfidentialClient = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/oauth/clients/00000000-0000-0000-0000-000000000000/rotate-secret',
        [],
        $adminToken
    );
    assertTrue($missingConfidentialClient['status'] === 404, 'missing confidential OAuth client must return 404');
    assertTrue(($missingConfidentialClient['json']['error'] ?? '') === 'confidential client not found', 'missing confidential OAuth client must match API contract');

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
    assertTrue(strpos((string)$metrics['body'], 'route="/api/v1/oauth/clients/{id}"') !== false, 'metrics must expose contract-compliant dynamic route template');
    assertTrue(strpos((string)$metrics['body'], 'route="/api/v1/oauth/clients/{id}/rotate-secret"') !== false, 'metrics must preserve normalized nested route template');
    assertTrue(strpos((string)$metrics['body'], 'route="oauth/get-client"') === false, 'metrics must not expose internal Yii route names');
    assertTrue(strpos((string)$metrics['body'], '00000000-0000-0000-0000-000000000000') === false, 'metrics route labels must not contain resource UUIDs');

    $jwks = httpRequest('GET', $baseUrl . '/.well-known/jwks.json', [
        'headers' => ['Accept: application/json'],
    ]);
    assertTrue($jwks['status'] === 200, 'JWKS must return 200');
    assertTrue(!empty($jwks['json']['keys'][0]['kid']), 'JWKS must include key id');
    assertTrue(($jwks['json']['keys'][0]['kty'] ?? '') === 'RSA', 'JWKS key must be RSA');
    assertTrue(($jwks['json']['keys'][0]['alg'] ?? '') === 'RS256', 'JWKS key must use RS256');

    $preflightChallenge = rtrim(strtr(base64_encode(hash('sha256', 'preflight-verifier-' . $suffix, true)), '+/', '-_'), '=');
    $loginRequiredUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'https://rp.example.test/cb',
        'scope' => 'openid',
        'state' => 'login-required-' . $suffix,
        'nonce' => 'login-required-' . $suffix,
        'code_challenge' => $preflightChallenge,
        'code_challenge_method' => 'S256',
    ]);
    $loginRequired = httpRequest('GET', $loginRequiredUrl, ['headers' => ['Accept: application/json']]);
    assertTrue($loginRequired['status'] >= 300 && $loginRequired['status'] < 400, 'authorize without SSO session must redirect');
    $loginRequiredParts = parse_url($loginRequired['location']);
    parse_str($loginRequiredParts['query'] ?? '', $loginRequiredQuery);
    assertTrue(($loginRequiredQuery['error'] ?? '') === 'login_required', 'authorize without SSO session must return login_required');

    $badRedirectUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'https://evil.example.test/not-registered',
        'scope' => 'openid',
        'state' => 'bad-redirect-' . $suffix,
    ]);
    $badRedirect = httpRequest('GET', $badRedirectUrl, ['cookies' => $sessionCookie]);
    assertOAuthError($badRedirect, 400, 'invalid_request', 'authorize with unregistered redirect_uri');

    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $authorizeUrl = $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'https://rp.example.test/cb',
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
    assertTrue(($query['iss'] ?? '') === $configuration['json']['issuer'], 'authorize redirect must identify the issuer');

    $token = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => 'https://rp.example.test/cb',
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
    assertTrue(($idTokenPayload['azp'] ?? '') === $client['json']['client_id'], 'id_token authorized party must match client');
    assertTrue(($idTokenPayload['nonce'] ?? '') === 'nonce-' . $suffix, 'id_token nonce must match authorize request');
    assertTrue(is_int($idTokenPayload['auth_time'] ?? null), 'id_token must include integer auth_time');

    $codeReuse = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'],
        'redirect_uri' => 'https://rp.example.test/cb',
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
        'redirect_uri' => 'https://rp.example.test/cb',
        'scope' => 'profile',
        'state' => 'burn-' . $suffix,
        'code_challenge' => $burnChallenge,
        'code_challenge_method' => 'S256',
    ]), ['cookies' => $sessionCookie]);
    $burnLocationParts = parse_url($burnAuthorize['location']);
    parse_str($burnLocationParts['query'] ?? '', $burnQuery);
    assertTrue(!empty($burnQuery['code']), 'PKCE burn scenario must issue authorization code');

    $wrongPkce = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $burnQuery['code'],
        'redirect_uri' => 'https://rp.example.test/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => 'wrong-verifier',
    ]);
    assertOAuthError($wrongPkce, 400, 'invalid_grant', 'authorization code with invalid PKCE verifier');

    $correctAfterWrongPkce = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'authorization_code',
        'code' => $burnQuery['code'],
        'redirect_uri' => 'https://rp.example.test/cb',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'code_verifier' => $burnVerifier,
    ]);
    assertTrue($correctAfterWrongPkce['status'] === 200, 'authorization code must remain usable after failed PKCE validation');

    $plainVerifier = 'plain-verifier-' . $suffix;
    $plainAuthorize = httpRequest('GET', $baseUrl . '/oauth2/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $client['json']['client_id'],
        'redirect_uri' => 'https://rp.example.test/cb',
        'scope' => 'profile',
        'state' => 'plain-' . $suffix,
        'code_challenge' => $plainVerifier,
        'code_challenge_method' => 'plain',
    ]), ['cookies' => $sessionCookie]);
    $plainLocationParts = parse_url($plainAuthorize['location']);
    parse_str($plainLocationParts['query'] ?? '', $plainQuery);
    assertTrue(($plainQuery['error'] ?? '') === 'invalid_request', 'plain PKCE must be rejected');
    assertTrue(($plainQuery['state'] ?? '') === 'plain-' . $suffix, 'plain PKCE error must preserve state');

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
    assertTrue(($introspection['json']['iat'] ?? 0) <= time(), 'access token introspection must include issued-at timestamp');
    $accessTokenClaims = jwtPart($token['json']['access_token'], 1);
    assertTrue(($accessTokenClaims['purpose'] ?? '') === \app\services\JwtService::PURPOSE_OAUTH_USER, 'OAuth user token must have an explicit purpose');
    assertTrue(($accessTokenClaims['iss'] ?? '') === Yii::$app->params['issuer'], 'OAuth user token issuer must match configuration');
    assertTrue(($accessTokenClaims['aud'] ?? '') === Yii::$app->params['apiAudience'], 'OAuth user token audience must match API');
    assertTrue(($accessTokenClaims['nbf'] ?? null) === ($accessTokenClaims['iat'] ?? null), 'OAuth user token must have a consistent nbf');
    assertTrue(($accessTokenClaims['email'] ?? '') === $adminUsername . '@example.test', 'OAuth access token must include user email');
    assertTrue(($accessTokenClaims['source'] ?? '') === 'local', 'OAuth access token must include user source');
    assertTrue(
        ($accessTokenClaims['aud'] ?? '') === $configuration['json']['issuer'] . '/api',
        'OAuth access token must target the API audience'
    );
    $oauthAdminDenied = jsonRequest('GET', $baseUrl . '/api/v1/users', [], $token['json']['access_token']);
    assertTrue($oauthAdminDenied['status'] === 403, 'standard OAuth scopes must not authorize admin API access');
    assertTrue(
        ($oauthAdminDenied['json']['error'] ?? '') === 'insufficient scope',
        'admin API must report insufficient OAuth scope before RBAC'
    );
    assertTrue(($accessTokenClaims['iss'] ?? '') === $configuration['json']['issuer'], 'OAuth access token issuer must match discovery');

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
        'redirect_uri' => 'https://rp.example.test/cb',
        'scope' => 'openid profile',
        'state' => 'basic-' . $suffix,
        'nonce' => 'basic-' . $suffix,
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
        'redirect_uri' => 'https://rp.example.test/cb',
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
    $refreshIdToken = jwtPart($refresh['json']['id_token'], 1);
    assertTrue(!array_key_exists('nonce', $refreshIdToken), 'refreshed id_token must omit nonce');
    assertTrue(is_int($refreshIdToken['auth_time'] ?? null), 'refreshed id_token must include auth_time');

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
        'redirect_uri' => 'https://rp.example.test/cb',
        'scope' => 'openid profile',
        'state' => 'profile-only-' . $suffix,
        'nonce' => 'profile-only-' . $suffix,
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
        'redirect_uri' => 'https://rp.example.test/cb',
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
        'redirect_uris' => ['https://rp.example.test/consent-cb'],
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
        'redirect_uri' => 'https://rp.example.test/consent-cb',
        'scope' => 'openid profile',
        'state' => 'consent-' . $suffix,
        'nonce' => 'consent-' . $suffix,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    $consentRequired = httpRequest('GET', $consentAuthorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue($consentRequired['status'] >= 300 && $consentRequired['status'] < 400, 'client without consent must redirect');
    $consentRequiredParts = parse_url($consentRequired['location']);
    parse_str($consentRequiredParts['query'] ?? '', $consentRequiredQuery);
    assertTrue(($consentRequiredQuery['error'] ?? '') === 'consent_required', 'client without consent must return consent_required');

    $consentInfoUrl = $baseUrl . '/oauth2/consent?' . http_build_query([
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid profile',
    ]);
    $anonymousConsentInfo = httpRequest('GET', $consentInfoUrl, ['headers' => ['Accept: application/json']]);
    assertOAuthError($anonymousConsentInfo, 401, 'login_required', 'anonymous consent info');
    assertTrue(($anonymousConsentInfo['json']['error_description'] ?? '') === 'no active session', 'anonymous consent info must match OAuth error contract');

    $unknownConsentClient = httpRequest('GET', $baseUrl . '/oauth2/consent?' . http_build_query([
        'client_id' => 'missing-client-' . $suffix,
        'scope' => 'openid',
    ]), ['headers' => ['Accept: application/json'], 'cookies' => $sessionCookie]);
    assertOAuthError($unknownConsentClient, 400, 'invalid_request', 'unknown consent client');
    assertTrue(($unknownConsentClient['json']['error_description'] ?? '') === 'invalid client credentials', 'unknown consent client must match OAuth error contract');

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

    $missingConsentCsrf = httpRequest('POST', $baseUrl . '/oauth2/consent', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => json_encode([
            'client_id' => $consentClient['json']['client_id'],
            'scope' => 'openid',
        ]),
        'cookies' => $sessionCookie,
        'omit_csrf' => true,
    ]);
    assertTrue($missingConsentCsrf['status'] === 403, 'cookie-authenticated consent without CSRF token must return 403');

    $malformedConsent = httpRequest('POST', $baseUrl . '/oauth2/consent', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '{"client_id":',
        'cookies' => $sessionCookie,
    ]);
    assertOAuthError($malformedConsent, 400, 'invalid_request', 'malformed consent JSON');
    assertTrue(($malformedConsent['json']['error_description'] ?? '') === 'invalid json body', 'malformed consent JSON must match OAuth error contract');

    $arrayConsent = httpRequest('POST', $baseUrl . '/oauth2/consent', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
        'body' => '[]',
        'cookies' => $sessionCookie,
    ]);
    assertOAuthError($arrayConsent, 400, 'invalid_request', 'non-object consent JSON');
    assertTrue(($arrayConsent['json']['error_description'] ?? '') === 'invalid json body', 'non-object consent JSON must match OAuth error contract');

    $unknownConsentField = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid profile',
        'unexpected' => true,
    ], null, $sessionCookie);
    assertOAuthError($unknownConsentField, 400, 'invalid_request', 'consent JSON with unknown field');
    assertTrue(($unknownConsentField['json']['error_description'] ?? '') === 'invalid json body', 'unknown consent field must match OAuth error contract');

    $invalidConsentClientGrant = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => 'missing-client-' . $suffix,
        'scope' => 'openid',
    ], null, $sessionCookie);
    assertOAuthError($invalidConsentClientGrant, 400, 'invalid_request', 'grant consent for unknown client');
    assertTrue(($invalidConsentClientGrant['json']['error_description'] ?? '') === 'invalid client credentials', 'unknown grant consent client must match OAuth error contract');

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
    assertTrue($consentAuditCount === 2, 'each consent grant must write a contract-compliant audit event');

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
    assertTrue(($missingUserFields['json']['error'] ?? '') === 'username and email are required', 'create user without username/email must match API contract');
    assertTrue(($missingUserFields['json']['code'] ?? '') === 'invalid_user', 'validation error must expose a stable code');

    $unknownUserField = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => 'unknown-field-' . $suffix,
        'email' => 'unknown-field-' . $suffix . '@example.test',
        'password' => $password,
        'email_verified' => true,
    ], $adminToken);
    assertTrue($unknownUserField['status'] === 400, 'create user JSON with unknown field must return 400');
    assertTrue(($unknownUserField['json']['error'] ?? '') === 'invalid json body', 'unknown user field must match API contract');

    $typedUserAttributes = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => 'typed-user-' . $suffix,
        'email' => 'typed-user-' . $suffix . '@example.test',
        'password' => $password,
        'attributes' => [],
    ], $adminToken);
    assertTrue($typedUserAttributes['status'] === 400, 'user attributes JSON array must return 400');
    assertTrue(($typedUserAttributes['json']['error'] ?? '') === 'invalid json body', 'typed attributes error must match JSON map contract');

    $duplicateUser = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => $plainLogin['json']['user']['username'],
        'email' => 'duplicate-' . $suffix . '@example.test',
        'password' => $password,
    ], $adminToken);
    assertTrue($duplicateUser['status'] === 409, 'duplicate username must return 409');
    assertTrue(($duplicateUser['json']['error'] ?? '') === 'user already exists', 'duplicate username must match API contract');
    assertTrue(($duplicateUser['json']['code'] ?? '') === 'user_conflict', 'conflict must expose a stable code');

    $db->createCommand()->update('users', [
        'first_name' => 'Old',
        'last_name' => 'Name',
        'attributes' => json_encode(['department' => 'value']),
    ], ['id' => $plainUserId])->execute();
    $replacedUser = jsonRequest('PUT', $baseUrl . '/api/v1/users/' . $plainUserId, [
        'username' => $userUsername,
        'email' => $userUsername . '@example.test',
        'status' => 'active',
    ], $adminToken);
    assertTrue($replacedUser['status'] === 200, 'user update must return 200');
    assertTrue(($replacedUser['json']['first_name'] ?? null) === 'Old', 'user update must preserve omitted first_name');
    assertTrue(($replacedUser['json']['last_name'] ?? null) === 'Name', 'user update must preserve omitted last_name');
    assertTrue(($replacedUser['json']['attributes']['department'] ?? null) === 'value', 'user update must preserve omitted attributes');

    $db->createCommand()->update('users', ['status' => 'blocked'], ['id' => $plainUserId])->execute();
    $blockedAccount = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $plainLogin['json']['access_token']);
    assertTrue($blockedAccount['status'] === 401, 'blocked account bearer token must return 401');
    assertTrue(($blockedAccount['json']['error'] ?? '') === 'account is not active', 'blocked account must match API contract');

    foreach (['GET', 'PUT', 'DELETE'] as $method) {
        $invalidUserId = jsonRequest($method, $baseUrl . '/api/v1/users/not-a-uuid', [], $adminToken);
        assertTrue($invalidUserId['status'] === 400, $method . ' user with invalid id must return 400');
        assertTrue(($invalidUserId['json']['error'] ?? '') === 'invalid id', $method . ' user with invalid id must match API contract');
    }

    $missingUser = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingUser['status'] === 404, 'missing user must return 404');
    assertTrue(($missingUser['json']['error'] ?? '') === 'user not found', 'missing user must match API contract');
    assertTrue(($missingUser['json']['code'] ?? '') === 'user_not_found', 'not-found must expose a stable code');

    $rawUuidUser = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/users/' . str_replace('-', '', $adminId),
        [],
        $adminToken
    );
    assertTrue($rawUuidUser['status'] === 200, 'compact UUID path must be accepted by the documented UUID parser');
    assertTrue(($rawUuidUser['json']['id'] ?? '') === $adminId, 'raw-hex UUID path must be canonicalized');

    $unknownUserUpdateField = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000',
        ['password' => $password],
        $adminToken
    );
    assertTrue($unknownUserUpdateField['status'] === 400, 'update user JSON with unsupported password field must return 400');
    assertTrue(($unknownUserUpdateField['json']['error'] ?? '') === 'invalid json body', 'unknown user update field must match API contract');

    $missingUserUpdate = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000',
        ['username' => 'missing', 'email' => 'missing@example.test', 'status' => 'active'],
        $adminToken
    );
    assertTrue($missingUserUpdate['status'] === 404, 'missing user update must return 404');
    assertTrue(($missingUserUpdate['json']['error'] ?? '') === 'user not found', 'missing user update must identify the absent user');

    $missingRoleFields = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => '',
        'name' => '',
    ], $adminToken);
    assertTrue($missingRoleFields['status'] === 400, 'create role without code/name must return 400');
    assertTrue(($missingRoleFields['json']['error'] ?? '') === 'role code and name are required', 'create role without code/name must match API contract');

    $unknownRoleField = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => 'unknown-role-' . $suffix,
        'name' => 'Unknown role field',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownRoleField['status'] === 400, 'create role JSON with unknown field must return 400');
    assertTrue(($unknownRoleField['json']['error'] ?? '') === 'invalid json body', 'unknown role field must match API contract');

    $duplicateRole = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => 'admin',
        'name' => 'Duplicate admin',
    ], $adminToken);
    assertTrue($duplicateRole['status'] === 409, 'duplicate role code must return 409');
    assertTrue(($duplicateRole['json']['error'] ?? '') === 'role already exists', 'duplicate role code must match API contract');

    $role = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => 'itest-role-' . $suffix,
        'name' => 'Integration role ' . $suffix,
        'description' => 'Updated description',
    ], $adminToken);
    assertTrue($role['status'] === 201, 'create role must return 201');
    $createdRoleIds[] = $role['json']['id'];
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($role['json']['created_at'] ?? '')) === 1,
        'role created_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($role['json']['updated_at'] ?? '')) === 1,
        'role updated_at must use contract-compliant RFC3339'
    );

    $db->createCommand()->update('roles', [
        'updated_at' => '2000-01-01 00:00:00+00',
    ], ['id' => $role['json']['id']])->execute();
    $updatedRole = jsonRequest('PUT', $baseUrl . '/api/v1/roles/' . $role['json']['id'], [
        'name' => 'Updated integration role ' . $suffix,
    ], $adminToken);
    assertTrue($updatedRole['status'] === 200, 'update role must return 200');
    assertTrue(!array_key_exists('description', $updatedRole['json']), 'role update must clear omitted description under the current API contract');
    assertTrue(strtotime((string)$updatedRole['json']['updated_at']) > strtotime('2020-01-01T00:00:00Z'), 'role update must advance updated_at');

    $permissions = jsonRequest('GET', $baseUrl . '/api/v1/permissions', [], $adminToken);
    assertTrue($permissions['status'] === 200 && !empty($permissions['json']['items']), 'permissions list must return seeded permissions');
    assertTrue(
        ($permissions['json']['pagination'] ?? null) === ['limit' => 50, 'offset' => 0, 'count' => count($permissions['json']['items'])],
        'permissions list must expose pagination metadata'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($permissions['json']['items'][0]['created_at'] ?? '')) === 1,
        'permission created_at must use contract-compliant RFC3339'
    );

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
        assertTrue(($invalidRoleId['json']['error'] ?? '') === 'invalid role id', $method . ' role path must match API contract');
    }

    $invalidPermissionPath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/roles/00000000-0000-0000-0000-000000000000/permissions/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidPermissionPath['status'] === 400, 'invalid permission path id must return 400');
    assertTrue(($invalidPermissionPath['json']['error'] ?? '') === 'invalid permission id', 'invalid permission path id must match API contract');

    $invalidPermissionBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/roles/00000000-0000-0000-0000-000000000000/permissions',
        ['permission_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidPermissionBody['status'] === 400, 'invalid permission body id must return 400');
    assertTrue(($invalidPermissionBody['json']['error'] ?? '') === 'invalid json body', 'invalid permission body id must match API contract');

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
        assertTrue(($unknownAssignmentField['json']['error'] ?? '') === 'invalid json body', $path . ' unknown field must match API contract');
    }

    foreach ([
        ['GET', '/api/v1/users/not-a-uuid/roles'],
        ['POST', '/api/v1/users/not-a-uuid/roles'],
        ['DELETE', '/api/v1/users/not-a-uuid/roles/not-a-uuid'],
    ] as $invalidUserRoleRequest) {
        [$method, $path] = $invalidUserRoleRequest;
        $invalidUserId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidUserId['status'] === 400, $method . ' user-role path with invalid user id must return 400');
        assertTrue(($invalidUserId['json']['error'] ?? '') === 'invalid user id', $method . ' user-role path must match API contract');
    }

    $invalidUserRolePath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/roles/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidUserRolePath['status'] === 400, 'invalid user-role role id must return 400');
    assertTrue(($invalidUserRolePath['json']['error'] ?? '') === 'invalid role id', 'invalid user-role role id must match API contract');

    $invalidUserRoleBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/roles',
        ['role_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidUserRoleBody['status'] === 400, 'invalid user-role body id must return 400');
    assertTrue(($invalidUserRoleBody['json']['error'] ?? '') === 'invalid json body', 'invalid user-role body id must match API contract');

    $group = jsonRequest('POST', $baseUrl . '/api/v1/groups', [
        'code' => 'itest-group-' . $suffix,
        'name' => 'Integration group ' . $suffix,
    ], $adminToken);
    assertTrue($group['status'] === 201, 'create group must return 201');
    $createdGroupIds[] = $group['json']['id'];
    assertTrue(($group['json']['description'] ?? null) === '', 'group must preserve documented empty string for empty description');
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($group['json']['created_at'] ?? '')) === 1,
        'group created_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($group['json']['updated_at'] ?? '')) === 1,
        'group updated_at must use contract-compliant RFC3339'
    );

    $db->createCommand()->update('groups', [
        'description' => 'Updated description',
        'updated_at' => '2000-01-01 00:00:00+00',
    ], ['id' => $group['json']['id']])->execute();
    $updatedGroup = jsonRequest('PUT', $baseUrl . '/api/v1/groups/' . $group['json']['id'], [
        'name' => 'Updated integration group ' . $suffix,
    ], $adminToken);
    assertTrue($updatedGroup['status'] === 200, 'update group must return 200');
    assertTrue(($updatedGroup['json']['description'] ?? null) === '', 'group update must clear omitted description under the current API contract');
    assertTrue(strtotime((string)$updatedGroup['json']['updated_at']) > strtotime('2020-01-01T00:00:00Z'), 'group update must advance updated_at');

    $unknownGroupField = jsonRequest('POST', $baseUrl . '/api/v1/groups', [
        'code' => 'unknown-group-' . $suffix,
        'name' => 'Unknown group field',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownGroupField['status'] === 400, 'create group JSON with unknown field must return 400');
    assertTrue(($unknownGroupField['json']['error'] ?? '') === 'invalid json body', 'unknown group field must match API contract');

    $duplicateGroup = jsonRequest('POST', $baseUrl . '/api/v1/groups', [
        'code' => $group['json']['code'],
        'name' => 'Duplicate group',
    ], $adminToken);
    assertTrue($duplicateGroup['status'] === 409, 'duplicate group code must return 409');
    assertTrue(($duplicateGroup['json']['error'] ?? '') === 'group already exists', 'duplicate group code must match API contract');

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
        assertTrue(($invalidGroupId['json']['error'] ?? '') === 'invalid group id', $method . ' group path must match API contract');
    }

    $invalidGroupRolePath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/groups/00000000-0000-0000-0000-000000000000/roles/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidGroupRolePath['status'] === 400, 'invalid group role path id must return 400');
    assertTrue(($invalidGroupRolePath['json']['error'] ?? '') === 'invalid role id', 'invalid group role path id must match API contract');

    $invalidGroupRoleBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/groups/00000000-0000-0000-0000-000000000000/roles',
        ['role_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidGroupRoleBody['status'] === 400, 'invalid group role body id must return 400');
    assertTrue(($invalidGroupRoleBody['json']['error'] ?? '') === 'invalid json body', 'invalid group role body id must match API contract');

    foreach ([
        ['GET', '/api/v1/users/not-a-uuid/groups'],
        ['POST', '/api/v1/users/not-a-uuid/groups'],
        ['DELETE', '/api/v1/users/not-a-uuid/groups/not-a-uuid'],
    ] as $invalidUserGroupRequest) {
        [$method, $path] = $invalidUserGroupRequest;
        $invalidUserId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidUserId['status'] === 400, $method . ' user-group path with invalid user id must return 400');
        assertTrue(($invalidUserId['json']['error'] ?? '') === 'invalid user id', $method . ' user-group path must match API contract');
    }

    $invalidUserGroupPath = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/groups/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidUserGroupPath['status'] === 400, 'invalid user-group group id must return 400');
    assertTrue(($invalidUserGroupPath['json']['error'] ?? '') === 'invalid group id', 'invalid user-group group id must match API contract');

    $invalidUserGroupBody = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/users/00000000-0000-0000-0000-000000000000/groups',
        ['group_id' => 'not-a-uuid'],
        $adminToken
    );
    assertTrue($invalidUserGroupBody['status'] === 400, 'invalid user-group body id must return 400');
    assertTrue(($invalidUserGroupBody['json']['error'] ?? '') === 'invalid json body', 'invalid user-group body id must match API contract');

    $badAuditActor = httpRequest('GET', $baseUrl . '/api/v1/audit?actor=not-a-uuid', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($badAuditActor['status'] === 400, 'audit with invalid actor must return 400');
    assertTrue(($badAuditActor['json']['error'] ?? '') === 'invalid actor id', 'audit invalid actor must match API contract');

    $malformedAuditActor = httpRequest('GET', $baseUrl . '/api/v1/audit?actor=------------------------------------', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($malformedAuditActor['status'] === 400, 'audit with malformed 36-character actor must return 400');
    assertTrue(($malformedAuditActor['json']['error'] ?? '') === 'invalid actor id', 'malformed audit actor must match API contract');

    $badAuditFrom = httpRequest('GET', $baseUrl . '/api/v1/audit?from=not-a-date', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($badAuditFrom['status'] === 400, 'audit with invalid from must return 400');
    assertTrue(($badAuditFrom['json']['error'] ?? '') === 'invalid from, expected RFC3339', 'audit invalid from must match API contract');

    $badAuditTo = httpRequest('GET', $baseUrl . '/api/v1/audit?to=not-a-date', [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($badAuditTo['status'] === 400, 'audit with invalid to must return 400');
    assertTrue(($badAuditTo['json']['error'] ?? '') === 'invalid to, expected RFC3339', 'audit invalid to must match API contract');

    $fractionalAuditRange = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'from' => '2000-01-01T00:00:00.123456789Z',
        'to' => '2999-01-01T00:00:00.987654321+00:00',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($fractionalAuditRange['status'] === 200, 'audit must accept contract-compliant fractional RFC3339 timestamps');

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

    $auditWire = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'action' => $auditLimitAction,
        'limit' => '1',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($auditWire['status'] === 200, 'audit wire-format request must return 200');
    assertTrue(count($auditWire['json']['items'] ?? []) === 1, 'audit wire-format request must return one event');
    assertTrue(($auditWire['json']['pagination'] ?? null) === ['limit' => 1, 'offset' => 0, 'count' => 1], 'audit response must expose pagination metadata');
    assertTrue(!array_key_exists('actor_user_id', $auditWire['json']['items'][0]), 'audit event must omit empty actor_user_id');
    assertTrue(($auditWire['json']['items'][0]['ip'] ?? null) === '', 'audit event must use documented empty value for empty ip');
    assertTrue(($auditWire['json']['items'][0]['user_agent'] ?? null) === '', 'audit event must use documented empty value for empty user_agent');
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($auditWire['json']['items'][0]['created_at'] ?? '')) === 1,
        'audit created_at must use contract-compliant RFC3339'
    );

    foreach (['201', '-1', '12invalid'] as $invalidAuditLimit) {
        $auditDefaultLimit = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
            'action' => $auditLimitAction,
            'limit' => $invalidAuditLimit,
        ]), [
            'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
        ]);
        assertTrue($auditDefaultLimit['status'] === 200, 'audit with invalid limit must return 200');
        assertTrue(count($auditDefaultLimit['json']['items'] ?? []) === 50, 'invalid audit limit must use the default of 50');
    }

    $auditMaxLimit = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'action' => $auditLimitAction,
        'limit' => '200',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($auditMaxLimit['status'] === 200, 'audit with maximum limit must return 200');
    assertTrue(count($auditMaxLimit['json']['items'] ?? []) === 50, 'audit limit above 100 must use the default');

    $auditLeadingZeroLimit = httpRequest('GET', $baseUrl . '/api/v1/audit?' . http_build_query([
        'action' => $auditLimitAction,
        'limit' => '000200',
        'offset' => '12invalid',
    ]), [
        'headers' => ['Authorization: Bearer ' . $adminToken, 'Accept: application/json'],
    ]);
    assertTrue($auditLeadingZeroLimit['status'] === 200, 'audit must accept a numeric limit with leading zeroes');
    assertTrue(count($auditLeadingZeroLimit['json']['items'] ?? []) === 50, 'invalid audit offset must use the default of zero');

    $missingIdentityFields = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => '',
        'client_id' => '',
    ], $adminToken);
    assertTrue($missingIdentityFields['status'] === 400, 'identity provider without code/client_id must return 400');
    assertTrue(($missingIdentityFields['json']['error'] ?? '') === 'code and client_id are required', 'identity provider missing fields must match API contract');

    $missingIdentityUrls = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => 'itest-oidc-missing-' . $suffix,
        'name' => 'Missing OIDC URLs',
        'type' => 'oidc',
        'client_id' => 'itest-idp-client-' . $suffix,
        'client_secret' => 'secret',
    ], $adminToken);
    assertTrue($missingIdentityUrls['status'] === 400, 'OIDC provider without URLs must return 400');
    assertTrue(($missingIdentityUrls['json']['error'] ?? '') === 'authorize_url, token_url and userinfo_url are required for type oidc', 'OIDC provider missing URLs must match API contract');

    $unknownIdentityProviderField = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => 'unknown-idp-' . $suffix,
        'client_id' => 'unknown-idp-client-' . $suffix,
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownIdentityProviderField['status'] === 400, 'identity provider JSON with unknown field must return 400');
    assertTrue(($unknownIdentityProviderField['json']['error'] ?? '') === 'invalid json body', 'unknown identity provider field must match API contract');

    $typedIdentityProvider = jsonRequest('POST', $baseUrl . '/api/v1/identity-providers', [
        'code' => 'typed-idp-' . $suffix,
        'client_id' => 'typed-idp-client-' . $suffix,
        'enabled' => 1,
    ], $adminToken);
    assertTrue($typedIdentityProvider['status'] === 400, 'identity provider with numeric bool must return 400');
    assertTrue(($typedIdentityProvider['json']['error'] ?? '') === 'invalid json body', 'typed identity provider error must match JSON contract');

    foreach (['PUT', 'DELETE'] as $method) {
        $invalidIdentityProviderId = jsonRequest(
            $method,
            $baseUrl . '/api/v1/identity-providers/not-a-uuid',
            [],
            $adminToken
        );
        assertTrue($invalidIdentityProviderId['status'] === 400, $method . ' identity provider with invalid id must return 400');
        assertTrue(($invalidIdentityProviderId['json']['error'] ?? '') === 'invalid id', $method . ' identity provider must match API contract');
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
        assertTrue(($missingIdentityProvider['json']['error'] ?? '') === 'provider not found', $method . ' missing identity provider must match API contract');
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
    ], $adminToken);
    assertTrue($identityProvider['status'] === 201, 'create identity provider must return 201');
    $createdProviderIds[] = $identityProvider['json']['id'];
    assertTrue(!array_key_exists('client_secret', $identityProvider['json']), 'identity provider response must not expose client_secret');
    assertTrue(
        ($identityProvider['json']['scopes'] ?? null) === 'openid profile email',
        'identity provider create must apply the documented default scopes'
    );
    assertTrue(
        ($identityProvider['json']['enabled'] ?? null) === true,
        'identity provider create must enable a provider by default'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($identityProvider['json']['created_at'] ?? '')) === 1,
        'identity provider created_at must use contract-compliant RFC3339'
    );
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($identityProvider['json']['updated_at'] ?? '')) === 1,
        'identity provider updated_at must use contract-compliant RFC3339'
    );

    $db->createCommand()->update(
        'identity_providers',
        ['updated_at' => '2000-01-01 00:00:00+00'],
        ['id' => $identityProvider['json']['id']]
    )->execute();
    $updatedIdentityProvider = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/identity-providers/' . $identityProvider['json']['id'],
        [
            'name' => 'Updated identity provider ' . $suffix,
            'client_secret' => '',
        ],
        $adminToken
    );
    assertTrue($updatedIdentityProvider['status'] === 200, 'identity provider update must return 200');
    foreach (['type', 'client_id', 'authorize_url', 'token_url', 'userinfo_url', 'scopes'] as $preservedField) {
        assertTrue(
            ($updatedIdentityProvider['json'][$preservedField] ?? null) === ($identityProvider['json'][$preservedField] ?? null),
            'identity provider update must preserve omitted ' . $preservedField
        );
    }
    assertTrue(
        ($updatedIdentityProvider['json']['enabled'] ?? null) === $identityProvider['json']['enabled'],
        'identity provider update must preserve omitted enabled flag'
    );
    assertTrue(
        strtotime((string)$updatedIdentityProvider['json']['updated_at']) > strtotime('2020-01-01T00:00:00Z'),
        'identity provider update must advance updated_at'
    );

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
    assertTrue(($duplicateIdentityProvider['json']['error'] ?? '') === 'provider already exists', 'duplicate identity provider code must match API contract');

    $unknownIdentityUpdateField = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/identity-providers/00000000-0000-0000-0000-000000000000',
        ['unexpected' => true],
        $adminToken
    );
    assertTrue($unknownIdentityUpdateField['status'] === 400, 'identity provider update with unknown field must return 400');
    assertTrue(($unknownIdentityUpdateField['json']['error'] ?? '') === 'invalid json body', 'unknown identity provider update field must match API contract');

    $unknownLdapProviderField = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers', [
        'name' => 'Unknown LDAP field',
        'host' => 'ldap.example.test',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownLdapProviderField['status'] === 400, 'LDAP provider JSON with unknown field must return 400');
    assertTrue(($unknownLdapProviderField['json']['error'] ?? '') === 'invalid json body', 'unknown LDAP provider field must match API contract');

    $typedLdapProvider = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers', [
        'name' => 'Typed LDAP provider',
        'host' => 'ldap.example.test',
        'port' => '389',
        'created_at' => 'not-rfc3339',
    ], $adminToken);
    assertTrue($typedLdapProvider['status'] === 400, 'LDAP provider with invalid int/time types must return 400');
    assertTrue(($typedLdapProvider['json']['error'] ?? '') === 'invalid json body', 'typed LDAP provider error must match JSON contract');

    $unknownLdapTestField = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers/test', [
        'host' => 'ldap.example.test',
        'unexpected' => true,
    ], $adminToken);
    assertTrue($unknownLdapTestField['status'] === 400, 'LDAP test JSON with unknown field must return 400');
    assertTrue(($unknownLdapTestField['json']['error'] ?? '') === 'invalid json body', 'unknown LDAP test field must match API contract');

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
    assertTrue(($unknownLdapMappingField['json']['error'] ?? '') === 'invalid json body', 'unknown LDAP mapping field must match API contract');

    $invalidLdapMappingGroup = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000/group-mappings',
        [
            'ldap_group' => 'cn=invalid,dc=example,dc=test',
            'group_id' => 'not-a-uuid',
        ],
        $adminToken
    );
    assertTrue($invalidLdapMappingGroup['status'] === 400, 'LDAP mapping with invalid group id must return 400');
    assertTrue(($invalidLdapMappingGroup['json']['error'] ?? '') === 'invalid json body', 'LDAP mapping invalid group id must match JSON contract');

    foreach ([
        ['PUT', '/api/v1/ldap/providers/not-a-uuid'],
        ['DELETE', '/api/v1/ldap/providers/not-a-uuid'],
        ['GET', '/api/v1/ldap/providers/not-a-uuid/group-mappings'],
        ['POST', '/api/v1/ldap/providers/not-a-uuid/group-mappings'],
    ] as $invalidLdapProviderRequest) {
        [$method, $path] = $invalidLdapProviderRequest;
        $invalidLdapProviderId = jsonRequest($method, $baseUrl . $path, [], $adminToken);
        assertTrue($invalidLdapProviderId['status'] === 400, $method . ' LDAP provider path with invalid id must return 400');
        assertTrue(($invalidLdapProviderId['json']['error'] ?? '') === 'invalid id', $method . ' LDAP provider path must match API contract');
    }

    $missingLdapProviderUpdate = httpRequest('PUT', $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000', [
        'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $adminToken],
        'body' => '{}',
    ]);
    assertTrue($missingLdapProviderUpdate['status'] === 404, 'PUT missing LDAP provider must return 404');
    assertTrue(($missingLdapProviderUpdate['json']['error'] ?? '') === 'provider not found', 'PUT missing LDAP provider must match API contract');

    $missingLdapProviderDelete = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingLdapProviderDelete['status'] === 404, 'DELETE missing LDAP provider must return 404');
    assertTrue(($missingLdapProviderDelete['json']['error'] ?? '') === 'provider not found', 'DELETE missing LDAP provider must match API contract');

    $invalidLdapMappingId = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/not-a-uuid/group-mappings/not-a-uuid',
        [],
        $adminToken
    );
    assertTrue($invalidLdapMappingId['status'] === 400, 'LDAP mapping delete with invalid mapping id must return 400');
    assertTrue(($invalidLdapMappingId['json']['error'] ?? '') === 'invalid id', 'LDAP mapping delete must validate parent id first');

    $missingLdapMapping = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/not-a-uuid/group-mappings/00000000-0000-0000-0000-000000000000',
        [],
        $adminToken
    );
    assertTrue($missingLdapMapping['status'] === 400, 'LDAP mapping delete must reject an invalid parent id');
    assertTrue(($missingLdapMapping['json']['error'] ?? '') === 'invalid id', 'LDAP mapping delete must enforce the parent-id contract');

    $missingLdapMappings = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000/group-mappings',
        [],
        $adminToken
    );
    assertTrue($missingLdapMappings['status'] === 404, 'list mappings for a missing LDAP provider must return 404');
    assertTrue(($missingLdapMappings['json']['error'] ?? '') === 'provider not found', 'missing LDAP mapping parent must match API contract');

    $missingLdapMappingCreate = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/ldap/providers/00000000-0000-0000-0000-000000000000/group-mappings',
        [
            'ldap_group' => 'cn=missing,dc=example,dc=test',
            'group_id' => $group['json']['id'],
        ],
        $adminToken
    );
    assertTrue($missingLdapMappingCreate['status'] === 404, 'create mapping for a missing LDAP provider must return 404');
    assertTrue(($missingLdapMappingCreate['json']['error'] ?? '') === 'provider not found', 'missing LDAP mapping create parent must match API contract');

    $ldapProvider = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers', [
        'name' => 'Integration LDAP ' . $suffix,
        'host' => 'ldap.example.test',
        'port' => 389,
        'use_tls' => false,
        'start_tls' => false,
        'bind_dn' => 'cn=service,dc=example,dc=test',
        'bind_password' => 'integration-ldap-secret',
        'base_dn' => 'dc=example,dc=test',
        'user_filter' => '(&(objectClass=user)(sAMAccountName={username}))',
        'username_attribute' => 'sAMAccountName',
        'email_attribute' => 'mail',
        'display_name_attribute' => 'displayName',
    ], $adminToken);
    assertTrue($ldapProvider['status'] === 201, 'create LDAP provider must return 201');
    $createdLdapProviderIds[] = $ldapProvider['json']['id'];
    $createdAuditTargets[] = ['action' => 'ldap_provider_created', 'target_id' => $ldapProvider['json']['id']];
    $createdAuditTargets[] = ['action' => 'ldap_provider_updated', 'target_id' => $ldapProvider['json']['id']];
    $createdAuditTargets[] = ['action' => 'ldap_provider_deleted', 'target_id' => $ldapProvider['json']['id']];
    $createdAuditTargets[] = ['action' => 'ldap_group_mapping_created', 'target_id' => $ldapProvider['json']['id']];
    assertTrue(!array_key_exists('bind_password', $ldapProvider['json']), 'LDAP provider must not expose bind password');
    assertTrue(($ldapProvider['json']['group_attribute'] ?? null) === '', 'LDAP provider create must preserve documented empty value for omitted group_attribute');
    assertTrue(($ldapProvider['json']['enabled'] ?? null) === false, 'LDAP provider create must preserve documented empty value for omitted enabled');
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($ldapProvider['json']['created_at'] ?? '')) === 1,
        'LDAP provider created_at must use contract-compliant RFC3339'
    );
    $ldapCreateAuditCount = (int)$db->createCommand(
        "SELECT count(*) FROM audit_logs WHERE action = 'ldap_provider_created' AND target_id = :id",
        [':id' => $ldapProvider['json']['id']]
    )->queryScalar();
    assertTrue($ldapCreateAuditCount === 1, 'LDAP provider create must write exactly one audit event');

    $otherLdapProvider = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers', [
        'name' => 'Other integration LDAP ' . $suffix,
        'host' => 'ldap-other.example.test',
        'bind_dn' => 'cn=service,dc=example,dc=test',
        'bind_password' => 'other-integration-secret',
        'base_dn' => 'dc=example,dc=test',
    ], $adminToken);
    assertTrue($otherLdapProvider['status'] === 201, 'second LDAP provider must be created');
    $createdLdapProviderIds[] = $otherLdapProvider['json']['id'];
    $createdAuditTargets[] = [
        'action' => 'ldap_provider_created',
        'target_id' => $otherLdapProvider['json']['id'],
    ];

    $failedLdapProvider = jsonRequest('POST', $baseUrl . '/api/v1/ldap/providers', [
        'name' => 'Invalid integration LDAP ' . $suffix,
        'host' => 'ldap.example.test',
        'port' => -1,
    ], $adminToken);
    assertTrue($failedLdapProvider['status'] === 500, 'invalid LDAP provider persistence must return 500');
    assertTrue(($failedLdapProvider['json']['error'] ?? '') === 'failed to create provider', 'LDAP provider create failure must hide database details');

    $db->createCommand()->update(
        'ldap_providers',
        ['updated_at' => '2000-01-01 00:00:00+00'],
        ['id' => $ldapProvider['json']['id']]
    )->execute();
    $failedLdapUpdate = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'],
        [
            'name' => 'Invalid LDAP update ' . $suffix,
            'port' => 0,
        ],
        $adminToken
    );
    assertTrue($failedLdapUpdate['status'] === 500, 'invalid LDAP provider update persistence must return 500');
    assertTrue(($failedLdapUpdate['json']['error'] ?? '') === 'failed to update provider', 'LDAP provider update failure must hide database details');

    $updatedLdapProvider = jsonRequest(
        'PUT',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'],
        [
            'name' => 'Updated LDAP ' . $suffix,
            'port' => 389,
            'bind_password' => '',
        ],
        $adminToken
    );
    assertTrue($updatedLdapProvider['status'] === 200, 'LDAP provider update must return 200');
    assertTrue(($updatedLdapProvider['json']['host'] ?? '') === 'ldap.example.test', 'LDAP provider update must preserve omitted host');
    assertTrue(($updatedLdapProvider['json']['bind_dn'] ?? '') === 'cn=service,dc=example,dc=test', 'LDAP provider update must preserve omitted bind_dn');
    assertTrue(($updatedLdapProvider['json']['base_dn'] ?? '') === 'dc=example,dc=test', 'LDAP provider update must preserve omitted base_dn');
    assertTrue(($updatedLdapProvider['json']['user_filter'] ?? '') === '(&(objectClass=user)(sAMAccountName={username}))', 'LDAP provider update must preserve omitted user_filter');
    assertTrue(($updatedLdapProvider['json']['username_attribute'] ?? '') === 'sAMAccountName', 'LDAP provider update must preserve omitted username_attribute');
    assertTrue(($updatedLdapProvider['json']['email_attribute'] ?? '') === 'mail', 'LDAP provider update must preserve omitted email_attribute');
    assertTrue(($updatedLdapProvider['json']['display_name_attribute'] ?? '') === 'displayName', 'LDAP provider update must preserve omitted display_name_attribute');
    assertTrue(($updatedLdapProvider['json']['group_attribute'] ?? null) === '', 'LDAP provider update must preserve omitted group_attribute');
    assertTrue(($updatedLdapProvider['json']['use_tls'] ?? null) === false, 'LDAP provider update must preserve omitted use_tls');
    assertTrue(($updatedLdapProvider['json']['start_tls'] ?? null) === false, 'LDAP provider update must preserve omitted start_tls');
    assertTrue(($updatedLdapProvider['json']['enabled'] ?? null) === false, 'LDAP provider update must preserve omitted enabled');
    assertTrue(
        strtotime((string)$updatedLdapProvider['json']['updated_at']) > strtotime('2020-01-01T00:00:00Z'),
        'LDAP provider update must advance updated_at'
    );
    $ldapUpdateAuditCount = (int)$db->createCommand(
        "SELECT count(*) FROM audit_logs WHERE action = 'ldap_provider_updated' AND target_id = :id AND actor_user_id = :actor_id",
        [':id' => $ldapProvider['json']['id'], ':actor_id' => $adminId]
    )->queryScalar();
    assertTrue($ldapUpdateAuditCount === 1, 'LDAP provider update audit must preserve authenticated actor');

    $ldapMapping = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'] . '/group-mappings',
        [
            'ldap_group' => 'cn=integration,dc=example,dc=test',
            'group_id' => $group['json']['id'],
        ],
        $adminToken
    );
    assertTrue($ldapMapping['status'] === 201, 'create LDAP group mapping must return 201');
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', (string)($ldapMapping['json']['created_at'] ?? '')) === 1,
        'LDAP group mapping created_at must use contract-compliant RFC3339'
    );

    $crossProviderDelete = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/' . $otherLdapProvider['json']['id'] . '/group-mappings/' . $ldapMapping['json']['id'],
        [],
        $adminToken
    );
    assertTrue($crossProviderDelete['status'] === 404, 'LDAP mapping cannot be deleted through another provider');
    assertTrue(($crossProviderDelete['json']['error'] ?? '') === 'mapping not found', 'cross-provider mapping delete must use the not-found contract');

    $duplicateLdapMapping = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'] . '/group-mappings',
        [
            'ldap_group' => 'cn=integration,dc=example,dc=test',
            'group_id' => $group['json']['id'],
        ],
        $adminToken
    );
    assertTrue($duplicateLdapMapping['status'] === 409, 'duplicate LDAP mapping must return 409');
    assertTrue(($duplicateLdapMapping['json']['error'] ?? '') === 'mapping already exists', 'duplicate LDAP mapping must match API contract');

    $secondLdapMapping = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'] . '/group-mappings',
        [
            'ldap_group' => 'cn=aardvarks,dc=example,dc=test',
            'group_id' => $group['json']['id'],
        ],
        $adminToken
    );
    assertTrue($secondLdapMapping['status'] === 201, 'create second LDAP mapping must return 201');

    $listedLdapMappings = jsonRequest(
        'GET',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'] . '/group-mappings',
        [],
        $adminToken
    );
    assertTrue($listedLdapMappings['status'] === 200, 'list LDAP mappings must return 200');
    assertTrue(
        array_column($listedLdapMappings['json']['items'] ?? [], 'ldap_group') === [
            'cn=aardvarks,dc=example,dc=test',
            'cn=integration,dc=example,dc=test',
        ],
        'LDAP mappings must use contract-compliant ldap_group ordering'
    );
    assertTrue(
        ($listedLdapMappings['json']['pagination'] ?? null) === ['limit' => 50, 'offset' => 0, 'count' => 2],
        'LDAP mappings must expose pagination metadata'
    );

    foreach ([$ldapMapping, $secondLdapMapping] as $mappingToDelete) {
        $createdAuditTargets[] = [
            'action' => 'ldap_group_mapping_deleted',
            'target_id' => $mappingToDelete['json']['id'],
        ];
        $deletedLdapMapping = jsonRequest(
            'DELETE',
            $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'] . '/group-mappings/' . $mappingToDelete['json']['id'],
            [],
            $adminToken
        );
        assertTrue($deletedLdapMapping['status'] === 200, 'delete LDAP mapping must return 200');
        $mappingDeleteAuditCount = (int)$db->createCommand(
            "SELECT count(*) FROM audit_logs WHERE action = 'ldap_group_mapping_deleted' AND target_type = 'ldap_group_mapping' AND target_id = :id AND actor_user_id = :actor_id",
            [':id' => $mappingToDelete['json']['id'], ':actor_id' => $adminId]
        )->queryScalar();
        assertTrue($mappingDeleteAuditCount === 1, 'LDAP mapping delete audit must target the mapping with empty actor');
    }

    $deletedLdapProvider = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'],
        [],
        $adminToken
    );
    assertTrue($deletedLdapProvider['status'] === 200, 'existing LDAP provider delete must return 200');
    $repeatedLdapProviderDelete = jsonRequest(
        'DELETE',
        $baseUrl . '/api/v1/ldap/providers/' . $ldapProvider['json']['id'],
        [],
        $adminToken
    );
    assertTrue($repeatedLdapProviderDelete['status'] === 404, 'repeated LDAP provider delete must return 404');
    $ldapDeleteAuditCount = (int)$db->createCommand(
        "SELECT count(*) FROM audit_logs WHERE action = 'ldap_provider_deleted' AND target_id = :id AND actor_user_id = :actor_id",
        [':id' => $ldapProvider['json']['id'], ':actor_id' => $adminId]
    )->queryScalar();
    assertTrue($ldapDeleteAuditCount === 1, 'only successful LDAP provider delete must write an audit event');

    $staleMfaToken = (new \app\services\AuthService())->issueAccessToken(
        \app\models\User::findOne(['id' => $adminId]),
        null,
        time() - 301
    );
    $staleMfaEnroll = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/enroll', [], $staleMfaToken);
    assertTrue($staleMfaEnroll['status'] === 401, 'MFA changes must require authentication not older than five minutes');
    assertTrue(($staleMfaEnroll['json']['error'] ?? '') === 'recent authentication required', 'stale MFA authentication must return the documented error');
    $oauthMfaEnroll = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/enroll', [], $token['json']['access_token']);
    assertTrue($oauthMfaEnroll['status'] === 401, 'OAuth resource tokens must not authorize MFA changes');
    assertTrue(($oauthMfaEnroll['json']['error'] ?? '') === 'recent authentication required', 'OAuth MFA rejection must not disclose token details');

    $mfaEnroll = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/enroll', [], $adminToken);
    assertTrue($mfaEnroll['status'] === 200, 'contract-compliant MFA enroll must return 200');
    assertTrue(!empty($mfaEnroll['json']['secret']), 'MFA enroll must return secret');
    assertTrue(!empty($mfaEnroll['json']['otpauth_url']), 'MFA enroll must return contract-compliant otpauth_url');
    assertTrue(!array_key_exists('otpauth_uri', $mfaEnroll['json']), 'MFA enroll must expose only the documented URI field');

    $mfaCounter = (int)floor(time() / 30);
    $mfaActivate = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/activate', [
        'code' => totpCode($mfaEnroll['json']['secret'], $mfaCounter),
    ], $adminToken);
    assertTrue($mfaActivate['status'] === 200, 'contract-compliant MFA activate must return 200');
    assertTrue(($mfaActivate['json']['status'] ?? '') === 'mfa_enabled', 'MFA activate must return mfa_enabled status');
    assertTrue(count($mfaActivate['json']['recovery_codes'] ?? []) === 8, 'MFA activate must return contract-compliant recovery code count');
    foreach ($mfaActivate['json']['recovery_codes'] as $recoveryCode) {
        assertTrue(preg_match('/^[a-f0-9]{4}(?:-[a-f0-9]{4}){7}$/D', $recoveryCode) === 1, 'MFA recovery codes must contain 128 bits of entropy');
    }
    $revokedAfterMfaEnable = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $adminToken);
    assertTrue($revokedAfterMfaEnable['status'] === 401, 'MFA enable must invalidate the previous access token');

    $mfaLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($mfaLogin['status'] === 200, 'MFA password step must return 200');
    assertTrue(($mfaLogin['json']['mfa_required'] ?? false) === true, 'MFA password step must require MFA');
    assertTrue(!empty($mfaLogin['json']['mfa_token']), 'MFA password step must return contract-compliant mfa_token');
    assertTrue(array_keys($mfaLogin['json']) === ['mfa_required', 'mfa_token'], 'MFA password step must return only the typed challenge fields');

    $unknownMfaField = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaLogin['json']['mfa_token'],
        'code' => 'not-a-code',
        'challenge_token' => $mfaLogin['json']['mfa_token'],
    ]);
    assertTrue($unknownMfaField['status'] === 400, 'MFA verify JSON with unknown field must return 400');
    assertTrue(($unknownMfaField['json']['error'] ?? '') === 'invalid json body', 'unknown MFA verify field must match API contract');

    $invalidMfaToken = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => 'unknown-token',
        'code' => 'not-a-code',
    ]);
    assertTrue($invalidMfaToken['status'] === 401, 'invalid MFA token must return 401');
    assertTrue(($invalidMfaToken['json']['error'] ?? '') === 'invalid mfa token', 'invalid MFA token must match API contract');

    $invalidMfaCode = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaLogin['json']['mfa_token'],
        'code' => 'not-a-code',
    ]);
    assertTrue($invalidMfaCode['status'] === 401, 'invalid MFA code must return 401');
    assertTrue(($invalidMfaCode['json']['error'] ?? '') === 'invalid code', 'invalid MFA code must match API contract');

    $mfaVerify = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaLogin['json']['mfa_token'],
        'code' => totpCode($mfaEnroll['json']['secret'], $mfaCounter + 1),
    ]);
    assertTrue($mfaVerify['status'] === 200, 'MFA verify with mfa_token must return 200');
    assertTrue(!empty($mfaVerify['json']['access_token']), 'MFA verify must issue access token');
    assertTrue(!empty($mfaVerify['cookies']['sso_session']), 'MFA verify must create a new session');
    $adminToken = $mfaVerify['json']['access_token'];
    $sessionCookie = [
        'sso_session' => $mfaVerify['cookies']['sso_session'],
        'sso_csrf' => $mfaVerify['cookies']['sso_csrf'],
    ];

    $repeatedMfaActivate = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/activate', [
        'code' => 'not-a-code',
    ], $adminToken);
    assertTrue($repeatedMfaActivate['status'] === 409, 'repeated MFA activate must return 409');
    assertTrue(($repeatedMfaActivate['json']['error'] ?? '') === 'enroll first', 'repeated MFA activate must match API contract');

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
    assertTrue(($clientCredentials['json']['scope'] ?? '') === 'profile', 'client_credentials must echo granted scope');
    assertTrue(empty($clientCredentials['json']['refresh_token']), 'client_credentials must not include refresh_token');
    assertTrue(empty($clientCredentials['json']['id_token']), 'client_credentials must not include id_token');

    $clientCredentialsClaims = jwtPart($clientCredentials['json']['access_token'], 1);
    assertTrue(($clientCredentialsClaims['client_id'] ?? '') === $client['json']['client_id'], 'client_credentials token must include client_id');
    assertTrue(($clientCredentialsClaims['purpose'] ?? '') === \app\services\JwtService::PURPOSE_CLIENT_CREDENTIALS, 'client_credentials token must include purpose');
    assertTrue(($clientCredentialsClaims['aud'] ?? '') === Yii::$app->params['apiAudience'], 'client_credentials audience must match API');
    assertTrue(($clientCredentialsClaims['nbf'] ?? null) === ($clientCredentialsClaims['iat'] ?? null), 'client_credentials token must have a consistent nbf');
    assertTrue(($clientCredentialsClaims['email'] ?? null) === '', 'client_credentials token must include empty email');
    assertTrue(($clientCredentialsClaims['source'] ?? '') === 'client', 'client_credentials token must identify client source');
    assertTrue(($clientCredentialsClaims['iss'] ?? '') === $configuration['json']['issuer'], 'client_credentials token issuer must match discovery');

    $adminClientCredentials = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
        'scope' => 'signing_keys:rotate users:create',
    ]);
    assertTrue($adminClientCredentials['status'] === 200, 'scoped machine token must be issued');
    $machineToken = $adminClientCredentials['json']['access_token'];
    $machineRotation = jsonRequest(
        'POST',
        $baseUrl . '/api/v1/operations/signing-keys/rotate',
        [],
        $machineToken
    );
    assertTrue($machineRotation['status'] === 201, 'machine client may rotate signing keys');
    $machineAudit = $db->createCommand(
        "SELECT actor_type, actor_id, actor_user_id FROM audit_logs
         WHERE action = 'signing_key_rotated' AND target_id = :kid",
        [':kid' => $machineRotation['json']['kid'] ?? '']
    )->queryOne();
    assertTrue(($machineAudit['actor_type'] ?? '') === 'client', 'machine audit must store client principal type');
    assertTrue(($machineAudit['actor_id'] ?? '') === $client['json']['id'], 'machine audit must store client principal id');
    assertTrue(($machineAudit['actor_user_id'] ?? null) === null, 'machine audit must not use actor_user_id');
    $machineUserCreate = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => 'machine-created-' . $suffix,
        'email' => 'machine-created-' . $suffix . '@example.test',
        'password' => 'Machine-created-password-123',
    ], $machineToken);
    assertTrue($machineUserCreate['status'] === 403, 'machine client must not create users');
    assertTrue(
        ($machineUserCreate['json']['error'] ?? '') === 'user principal required',
        'forbidden machine mutation must report the principal boundary'
    );

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
        'redirect_uri' => 'https://rp.example.test/cb',
        'scope' => 'not-allowed',
        'state' => 'bad-scope-' . $suffix,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    $badScope = httpRequest('GET', $badScopeUrl, ['cookies' => $sessionCookie]);
    assertTrue($badScope['status'] >= 300 && $badScope['status'] < 400, 'invalid authorize scope must redirect');
    $badScopeParts = parse_url($badScope['location']);
    parse_str($badScopeParts['query'] ?? '', $badScopeQuery);
    assertTrue(($badScopeQuery['error'] ?? '') === 'invalid_scope', 'invalid authorize scope must return invalid_scope');

    $publicClient = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Integration public client ' . $suffix,
        'client_id' => 'itest-public-' . $suffix,
        'type' => 'public',
        'redirect_uris' => ['http://localhost/public-cb'],
        'allow_loopback_redirects' => true,
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
        'state' => 'missing-pkce-' . $suffix,
        'nonce' => 'missing-pkce-' . $suffix,
    ]);
    $missingPkce = httpRequest('GET', $missingPkceUrl, ['cookies' => $sessionCookie]);
    assertTrue($missingPkce['status'] >= 300 && $missingPkce['status'] < 400, 'public client without PKCE S256 must redirect');
    $missingPkceParts = parse_url($missingPkce['location']);
    parse_str($missingPkceParts['query'] ?? '', $missingPkceQuery);
    assertTrue(($missingPkceQuery['error'] ?? '') === 'invalid_request', 'public client without PKCE must return invalid_request');
    assertTrue(($missingPkceQuery['error_description'] ?? '') === 'PKCE S256 required', 'public client without PKCE must describe PKCE S256 requirement');

    $publicClientCredentials = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $publicClient['json']['client_id'],
        'scope' => 'profile',
    ]);
    assertOAuthError($publicClientCredentials, 401, 'invalid_client', 'public client_credentials grant');

    $badLogout = httpRequest('GET', $baseUrl . '/oauth2/logout?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => 'https://evil.example.test/not-registered',
    ]), ['cookies' => $sessionCookie]);
    assertTrue($badLogout['status'] === 400, 'unregistered post_logout_redirect_uri must return 400');
    assertTrue(($badLogout['json']['error'] ?? '') === 'invalid_request', 'unregistered post_logout_redirect_uri must return invalid_request');
    assertTrue(!array_key_exists('sso_session', $badLogout['cookies']), 'invalid GET logout must not clear the active session');

    $cookieOnlyLogout = httpRequest('GET', $baseUrl . '/oauth2/logout', ['cookies' => $sessionCookie]);
    assertTrue($cookieOnlyLogout['status'] === 400, 'cookie-only GET logout must be rejected');
    assertTrue(!array_key_exists('sso_session', $cookieOnlyLogout['cookies']), 'cookie-only GET logout must not clear the active session');

    $backchannelReset = httpRequest('POST', $backchannelUrl . '/reset');
    assertTrue($backchannelReset['status'] === 204, 'back-channel fixture reset must return 204');

    $logout = httpRequest('GET', $baseUrl . '/oauth2/logout?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => 'https://rp.example.test/logout-cb',
        'state' => 'logout-state-' . $suffix,
    ]), ['cookies' => $sessionCookie]);
    assertTrue($logout['status'] >= 300 && $logout['status'] < 400, 'registered logout redirect must redirect');
    assertTrue(strpos($logout['location'], 'https://rp.example.test/logout-cb') === 0, 'logout must redirect to registered uri');
    assertTrue(strpos($logout['location'], 'state=logout-state-' . $suffix) !== false, 'logout redirect must preserve state');

    $backchannel = httpRequest('GET', $backchannelUrl . '/last', [
        'headers' => ['Accept: application/json'],
    ]);
    assertTrue($backchannel['status'] === 200, 'back-channel fixture must expose the last notification');
    assertTrue(strpos((string)($backchannel['json']['content_type'] ?? ''), 'application/x-www-form-urlencoded') === 0, 'back-channel logout must use form encoding');
    $logoutToken = (string)($backchannel['json']['logout_token'] ?? '');
    assertTrue($logoutToken !== '', 'back-channel logout must send logout_token');
    $logoutHeader = jwtPart($logoutToken, 0);
    $logoutClaims = jwtPart($logoutToken, 1);
    assertTrue(($logoutHeader['alg'] ?? '') === 'RS256', 'logout_token must use RS256');
    assertTrue(($logoutClaims['iss'] ?? '') === $configuration['json']['issuer'], 'logout_token issuer must match discovery');
    assertTrue(($logoutClaims['sub'] ?? '') === $adminId, 'logout_token subject must match user');
    assertTrue(($logoutClaims['aud'] ?? '') === $client['json']['client_id'], 'logout_token audience must match client');
    assertTrue(!empty($logoutClaims['jti']), 'logout_token must include jti');
    assertTrue(isset($logoutClaims['events']['http://schemas.openid.net/event/backchannel-logout']), 'logout_token must include back-channel logout event');
    $logoutTokenParts = explode('.', $logoutToken);
    $logoutPublicKey = $db->createCommand(
        "SELECT public_key_pem FROM oidc_signing_keys WHERE kid = :kid AND status IN ('active', 'retiring')",
        [':kid' => $logoutHeader['kid'] ?? '']
    )->queryScalar();
    assertTrue($logoutPublicKey !== false, 'logout_token kid must reference a stored OIDC key');
    assertTrue(openssl_verify(
        $logoutTokenParts[0] . '.' . $logoutTokenParts[1],
        \app\services\JwtService::base64urlDecode($logoutTokenParts[2]),
        $logoutPublicKey,
        OPENSSL_ALGO_SHA256
    ) === 1, 'logout_token signature must validate against stored OIDC key');

    $invalidMfaDisable = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/disable', [
        'code' => 'not-a-code',
    ], $adminToken);
    assertTrue($invalidMfaDisable['status'] === 400, 'invalid MFA disable code must return 400');
    assertTrue(($invalidMfaDisable['json']['error'] ?? '') === 'invalid code', 'invalid MFA disable code must match API contract');

    $mfaDisable = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/disable', [
        'code' => $mfaActivate['json']['recovery_codes'][0],
    ], $adminToken);
    assertTrue($mfaDisable['status'] === 200, 'MFA disable must return 200');
    assertTrue(($mfaDisable['json']['status'] ?? '') === 'mfa_disabled', 'MFA disable must return contract-compliant status');

    $tokenAfterMfaDisable = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $adminToken);
    assertTrue($tokenAfterMfaDisable['status'] === 401, 'MFA disable must invalidate the current access token');

    $postMfaLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $adminUsername,
        'password' => $password,
    ]);
    assertTrue($postMfaLogin['status'] === 200, 'login after MFA disable must succeed');
    $adminToken = $postMfaLogin['json']['access_token'];

    $repeatedMfaDisable = jsonRequest('POST', $baseUrl . '/api/v1/auth/mfa/disable', [
        'code' => 'not-a-code',
    ], $adminToken);
    assertTrue($repeatedMfaDisable['status'] === 400, 'repeated MFA disable must return 400');
    assertTrue(($repeatedMfaDisable['json']['error'] ?? '') === 'mfa is not in the required state', 'repeated MFA disable must match API contract');

    $finalRevokeAll = jsonRequest('DELETE', $baseUrl . '/api/v1/auth/sessions', [], $adminToken);
    assertTrue($finalRevokeAll['status'] === 200, 'final revoke all must return 200');

    $firstPartyAfterRevokeAll = jsonRequest('GET', $baseUrl . '/api/v1/auth/me', [], $adminToken);
    assertTrue($firstPartyAfterRevokeAll['status'] === 401, 'revoke all must invalidate first-party token');

    $externalTokenAfterRevokeAll = jsonRequest('GET', $baseUrl . '/oauth2/userinfo', [], $token['json']['access_token']);
    assertTrue($externalTokenAfterRevokeAll['status'] === 401, 'revoke all must invalidate external OAuth access token');

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
    foreach ($createdRoleIds as $roleId) {
        $db->createCommand('DELETE FROM roles WHERE id = :id', [':id' => $roleId])->execute();
    }
    foreach ($createdGroupIds as $groupId) {
        $db->createCommand('DELETE FROM groups WHERE id = :id', [':id' => $groupId])->execute();
    }
    foreach ($createdProviderIds as $providerId) {
        $db->createCommand('DELETE FROM identity_providers WHERE id = :id', [':id' => $providerId])->execute();
    }
    foreach ($createdLdapProviderIds as $providerId) {
        $db->createCommand('DELETE FROM ldap_providers WHERE id = :id', [':id' => $providerId])->execute();
    }
    foreach ($createdUserIds as $userId) {
        $db->createCommand('DELETE FROM users WHERE id = :id', [':id' => $userId])->execute();
    }
}
