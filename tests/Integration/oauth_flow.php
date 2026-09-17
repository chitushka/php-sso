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
    $adminId = createUser($db, $adminUsername, $password);
    $createdUserIds[] = $adminId;
    $db->createCommand(
        "INSERT INTO user_roles(user_id, role_id)
         SELECT :user_id, id FROM roles WHERE code = 'admin'
         ON CONFLICT DO NOTHING",
        [':user_id' => $adminId]
    )->execute();

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
    ], $adminToken);
    assertTrue($client['status'] === 201, 'create oauth client must return 201');
    assertTrue(!empty($client['json']['client_secret']), 'create oauth client must return client_secret');
    $createdClientIds[] = $client['json']['id'];

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

    $introspection = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => $token['json']['access_token'],
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($introspection['status'] === 200, 'introspection must return 200');
    assertTrue(($introspection['json']['active'] ?? false) === true, 'introspection must mark issued access token active');
    assertTrue(($introspection['json']['sub'] ?? '') === $adminId, 'introspection must include access token subject');
    assertTrue(($introspection['json']['client_id'] ?? '') === $client['json']['client_id'], 'introspection must include client_id');

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

    $reuse = formRequest($baseUrl . '/oauth2/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $firstRefresh,
        'client_id' => $client['json']['client_id'],
        'client_secret' => $client['json']['client_secret'],
    ]);
    assertTrue($reuse['status'] === 400, 'reusing rotated refresh token must fail');
    assertTrue(($reuse['json']['error'] ?? '') === 'invalid_grant', 'reuse must return invalid_grant');

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
    ], $adminToken);
    assertTrue($consentClient['status'] === 201, 'create consent oauth client must return 201');
    $createdClientIds[] = $consentClient['json']['id'];

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

    $consentInfo = httpRequest('GET', $baseUrl . '/oauth2/consent?' . http_build_query([
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid profile',
    ]), ['headers' => ['Accept: application/json']]);
    assertTrue($consentInfo['status'] === 200, 'consent info must return 200');
    assertTrue(($consentInfo['json']['client_id'] ?? '') === $consentClient['json']['client_id'], 'consent info must include client_id');
    assertTrue(in_array('openid', $consentInfo['json']['scopes'] ?? [], true), 'consent info must include requested openid scope');

    $grantConsent = jsonRequest('POST', $baseUrl . '/oauth2/consent', [
        'client_id' => $consentClient['json']['client_id'],
        'scope' => 'openid profile',
    ], null, $sessionCookie);
    assertTrue($grantConsent['status'] === 200, 'grant consent must return 200');
    assertTrue(($grantConsent['json']['status'] ?? '') === 'granted', 'grant consent must return granted status');

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

    $duplicateUser = jsonRequest('POST', $baseUrl . '/api/v1/users', [
        'username' => $plainLogin['json']['user']['username'],
        'email' => 'duplicate-' . $suffix . '@example.test',
        'password' => $password,
    ], $adminToken);
    assertTrue($duplicateUser['status'] === 409, 'duplicate username must return 409');
    assertTrue(($duplicateUser['json']['error'] ?? '') === 'user already exists', 'duplicate username must match Go error');

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

    $missingRoleFields = jsonRequest('POST', $baseUrl . '/api/v1/roles', [
        'code' => '',
        'name' => '',
    ], $adminToken);
    assertTrue($missingRoleFields['status'] === 400, 'create role without code/name must return 400');
    assertTrue(($missingRoleFields['json']['error'] ?? '') === 'role code and name are required', 'create role without code/name must match Go error');

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

    $badIntrospectClient = formRequest($baseUrl . '/oauth2/introspect', [
        'token' => 'not-a-token',
        'client_id' => $client['json']['client_id'],
        'client_secret' => 'wrong-secret',
    ]);
    assertOAuthError($badIntrospectClient, 401, 'invalid_client', 'introspect with bad client secret');

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
    ], $adminToken);
    assertTrue($publicClient['status'] === 201, 'create public oauth client must return 201');
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
