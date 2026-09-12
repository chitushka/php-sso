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

function formRequest(string $url, array $payload): array
{
    return httpRequest('POST', $url, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        'body' => http_build_query($payload),
    ]);
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

    $client = jsonRequest('POST', $baseUrl . '/api/v1/oauth/clients', [
        'name' => 'Integration test client ' . $suffix,
        'client_id' => 'itest-client-' . $suffix,
        'type' => 'confidential',
        'redirect_uris' => ['http://localhost/cb'],
        'allowed_scopes' => ['openid', 'profile', 'email'],
        'skip_consent' => true,
    ], $adminToken);
    assertTrue($client['status'] === 201, 'create oauth client must return 201');
    assertTrue(!empty($client['json']['client_secret']), 'create oauth client must return client_secret');
    $createdClientIds[] = $client['json']['id'];

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

    $plainLogin = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $userUsername,
        'password' => $password,
    ]);
    assertTrue($plainLogin['status'] === 200, 'plain user login must return 200');
    $rbac = httpRequest('GET', $baseUrl . '/api/v1/users', [
        'headers' => ['Authorization: Bearer ' . $plainLogin['json']['access_token'], 'Accept: application/json'],
    ]);
    assertTrue($rbac['status'] === 403, 'plain user must not list users');

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
    assertTrue($badSecret['status'] === 401, 'bad client secret must return 401');
    assertTrue(($badSecret['json']['error'] ?? '') === 'invalid_client', 'bad client secret must return invalid_client');

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

    fwrite(STDOUT, "OK: integration OAuth/OIDC smoke flow passed\n");
} finally {
    foreach ($createdClientIds as $clientId) {
        $db->createCommand('DELETE FROM oauth_clients WHERE id = :id', [':id' => $clientId])->execute();
    }
    foreach ($createdUserIds as $userId) {
        $db->createCommand('DELETE FROM users WHERE id = :id', [':id' => $userId])->execute();
    }
}
