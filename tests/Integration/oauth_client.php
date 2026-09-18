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
$username = 'rp-client-user-' . $suffix;
$password = 'IntegrationPass123!';
$clientId = 'rp-client-' . $suffix;
$clientSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$redirectUri = 'http://external-client.example.test/callback';
$logoutRedirectUri = 'http://external-client.example.test/logout';
$createdUserIds = [];
$createdClientDbIds = [];

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
        'cookies' => $setCookies,
        'location' => $location,
        'body' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function jsonRequest(string $method, string $url, array $payload, array $cookies = []): array
{
    return httpRequest($method, $url, [
        'headers' => ['Content-Type: application/json', 'Accept: application/json'],
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

function jwtPart(string $jwt, int $index): array
{
    $parts = explode('.', $jwt);
    assertTrue(count($parts) === 3, 'JWT must contain three parts');
    $decoded = base64_decode(strtr($parts[$index], '-_', '+/'));
    $json = json_decode((string)$decoded, true);
    assertTrue(is_array($json), 'JWT part must contain JSON object');
    return $json;
}

try {
    $userId = $db->createCommand(
        "INSERT INTO users(username,email,password_hash,status,source,email_verified)
         VALUES(:username,:email,:password_hash,'active','local',true)
         RETURNING id",
        [
            ':username' => $username,
            ':email' => $username . '@example.test',
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]
    )->queryScalar();
    $createdUserIds[] = (string)$userId;

    $client = new \app\models\OAuthClient();
    $client->client_id = $clientId;
    $client->name = 'RP external client ' . $suffix;
    $client->client_secret_hash = password_hash($clientSecret, PASSWORD_DEFAULT);
    $client->type = \app\models\OAuthClient::TYPE_CONFIDENTIAL;
    $client->redirect_uris = \app\models\OAuthClient::toPgArray([$redirectUri]);
    $client->allowed_scopes = \app\models\OAuthClient::toPgArray(['openid', 'profile', 'email']);
    $client->post_logout_redirect_uris = \app\models\OAuthClient::toPgArray([$logoutRedirectUri]);
    $client->skip_consent = true;
    $client->enabled = true;
    assertTrue($client->save(), 'RP client fixture must be saved');
    $createdClientDbIds[] = (string)$client->id;

    $discovery = httpRequest('GET', $baseUrl . '/.well-known/openid-configuration', [
        'headers' => ['Accept: application/json'],
    ]);
    assertTrue($discovery['status'] === 200, 'RP client must load discovery');
    assertTrue(!empty($discovery['json']['authorization_endpoint']), 'discovery must include authorization_endpoint');
    assertTrue(!empty($discovery['json']['token_endpoint']), 'discovery must include token_endpoint');
    assertTrue(!empty($discovery['json']['userinfo_endpoint']), 'discovery must include userinfo_endpoint');
    assertTrue(!empty($discovery['json']['end_session_endpoint']), 'discovery must include end_session_endpoint');

    $login = jsonRequest('POST', $baseUrl . '/api/v1/auth/login', [
        'username' => $username,
        'password' => $password,
    ]);
    assertTrue($login['status'] === 200, 'browser login must return 200');
    assertTrue(!empty($login['cookies']['sso_session']), 'browser login must set sso_session cookie');
    $sessionCookie = ['sso_session' => $login['cookies']['sso_session']];

    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $state = 'rp-state-' . $suffix;
    $nonce = 'rp-nonce-' . $suffix;
    $authorizeUrl = $discovery['json']['authorization_endpoint'] . '?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid profile email',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    $authorize = httpRequest('GET', $authorizeUrl, ['cookies' => $sessionCookie]);
    assertTrue(
        $authorize['status'] >= 300 && $authorize['status'] < 400,
        'authorize must redirect to RP callback, got status ' . $authorize['status'] . ' body ' . (string)$authorize['body']
    );
    assertTrue(strpos($authorize['location'], $redirectUri) === 0, 'authorize redirect must target RP callback');

    $callback = parse_url($authorize['location']);
    parse_str($callback['query'] ?? '', $callbackQuery);
    assertTrue(!empty($callbackQuery['code']), 'RP callback must include code');
    assertTrue(($callbackQuery['state'] ?? '') === $state, 'RP callback must preserve state');

    $token = formRequest($discovery['json']['token_endpoint'], [
        'grant_type' => 'authorization_code',
        'code' => $callbackQuery['code'],
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
    ], [basicAuthHeader($clientId, $clientSecret)]);
    assertTrue($token['status'] === 200, 'RP token exchange must return 200');
    assertTrue(!empty($token['json']['access_token']), 'RP token response must include access_token');
    assertTrue(!empty($token['json']['refresh_token']), 'RP token response must include refresh_token');
    assertTrue(!empty($token['json']['id_token']), 'RP token response must include id_token');

    $idToken = jwtPart($token['json']['id_token'], 1);
    assertTrue(($idToken['iss'] ?? '') === $discovery['json']['issuer'], 'RP id_token must match issuer');
    assertTrue(($idToken['aud'] ?? '') === $clientId, 'RP id_token must match audience');
    assertTrue(($idToken['nonce'] ?? '') === $nonce, 'RP id_token must match nonce');
    assertTrue(is_int($idToken['auth_time'] ?? null), 'RP id_token must include auth_time');

    $userinfo = httpRequest('GET', $discovery['json']['userinfo_endpoint'], [
        'headers' => ['Authorization: Bearer ' . $token['json']['access_token'], 'Accept: application/json'],
    ]);
    assertTrue($userinfo['status'] === 200, 'RP userinfo must return 200');
    assertTrue(($userinfo['json']['sub'] ?? '') === (string)$userId, 'RP userinfo must include subject');
    assertTrue(($userinfo['json']['preferred_username'] ?? '') === $username, 'RP userinfo must include profile claim');
    assertTrue(($userinfo['json']['email'] ?? '') === $username . '@example.test', 'RP userinfo must include email claim');

    $refresh = formRequest($discovery['json']['token_endpoint'], [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token['json']['refresh_token'],
    ], [basicAuthHeader($clientId, $clientSecret)]);
    assertTrue($refresh['status'] === 200, 'RP refresh token exchange must return 200');
    assertTrue(!empty($refresh['json']['access_token']), 'RP refresh response must include access_token');
    assertTrue(!empty($refresh['json']['refresh_token']), 'RP refresh response must include rotated refresh_token');
    assertTrue($refresh['json']['refresh_token'] !== $token['json']['refresh_token'], 'RP refresh response must rotate refresh_token');
    $refreshIdToken = jwtPart($refresh['json']['id_token'], 1);
    assertTrue(($refreshIdToken['nonce'] ?? null) === '', 'RP refreshed id_token must carry empty nonce');
    assertTrue(is_int($refreshIdToken['auth_time'] ?? null), 'RP refreshed id_token must include auth_time');

    $logout = httpRequest('GET', $discovery['json']['end_session_endpoint'] . '?' . http_build_query([
        'id_token_hint' => $token['json']['id_token'],
        'post_logout_redirect_uri' => $logoutRedirectUri,
        'state' => 'rp-logout-' . $suffix,
    ]), ['cookies' => $sessionCookie]);
    assertTrue($logout['status'] >= 300 && $logout['status'] < 400, 'RP logout must redirect');
    assertTrue(strpos($logout['location'], $logoutRedirectUri) === 0, 'RP logout must target post_logout_redirect_uri');
    assertTrue(strpos($logout['location'], 'state=rp-logout-' . $suffix) !== false, 'RP logout must preserve state');

    fwrite(STDOUT, "OK: external OAuth/OIDC client flow passed\n");
} finally {
    foreach ($createdClientDbIds as $id) {
        $db->createCommand('DELETE FROM oauth_clients WHERE id = :id', [':id' => $id])->execute();
    }
    foreach ($createdUserIds as $id) {
        $db->createCommand('DELETE FROM users WHERE id = :id', [':id' => $id])->execute();
    }
}
