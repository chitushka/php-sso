<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

new yii\console\Application(require __DIR__ . '/../../config/console.php');

use app\services\CryptoService;

$db = Yii::$app->db;
$baseUrl = rtrim(getenv('SSO_TEST_BASE_URL') ?: 'http://127.0.0.1:8081', '/');
$providerUrl = rtrim(getenv('SSO_TEST_PROVIDER_URL') ?: 'http://127.0.0.1:8082', '/');
$suffix = strtolower(bin2hex(random_bytes(4)));
$providerCode = 'mock-' . $suffix;
$externalCode = 'broker-' . $suffix;
$providerId = null;
$userId = null;
$localUserId = null;
$failure = null;

function failTest(string $message): void
{
    throw new RuntimeException($message);
}

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function httpRequest(
    string $method,
    string $url,
    array $cookies = [],
    ?string $csrfToken = null,
    bool $omitCsrf = false
): array
{
    $headers = [];
    if (!empty($cookies)) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
        if (isset($cookies['sso_csrf']) && !$omitCsrf) {
            $headers[] = 'X-CSRF-Token: ' . ($csrfToken ?? $cookies['sso_csrf']);
        }
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 15,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    $location = '';
    $cookies = [];

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int)$matches[1];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $matches)) {
            $cookies[$matches[1]] = $matches[2];
        }
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'location' => $location,
        'cookies' => $cookies,
        'body' => $raw,
    ];
}

try {
    $providerId = $db->createCommand(
        "INSERT INTO identity_providers(code,name,type,client_id,client_secret,authorize_url,token_url,userinfo_url,scopes,enabled)
         VALUES (:code,'Mock Broker Provider','oidc',:client_id,:client_secret,:authorize_url,:token_url,:userinfo_url,'openid profile email',true)
         RETURNING id",
        [
            ':code' => $providerCode,
            ':client_id' => 'mock-client-' . $suffix,
            ':client_secret' => (new CryptoService())->encrypt('mock-secret-' . $suffix),
            ':authorize_url' => $providerUrl . '/authorize?prompt=login&client_id=stale#provider-fragment',
            ':token_url' => $providerUrl . '/token',
            ':userinfo_url' => $providerUrl . '/userinfo',
        ]
    )->queryScalar();

    $localUserId = (string)$db->createCommand(
        "INSERT INTO users(username,email,password_hash,status,source,email_verified)
         VALUES (:username,:email,:password_hash,'active','local',true)
         RETURNING id",
        [
            ':username' => 'local-' . $suffix,
            ':email' => $externalCode . '@broker.example.test',
            ':password_hash' => password_hash('local-password-123', PASSWORD_DEFAULT),
        ]
    )->queryScalar();

    $providerError = httpRequest(
        'GET',
        $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/callback?error=access_denied'
    );
    assertTrue($providerError['status'] === 302, 'broker provider error must redirect');
    assertTrue(
        $providerError['location'] === '/login?broker_error=access_denied'
            || $providerError['location'] === $baseUrl . '/login?broker_error=access_denied',
        'broker provider error must redirect to the contract-compliant login route'
    );

    $failedCallback = httpRequest(
        'GET',
        $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/callback'
    );
    assertTrue($failedCallback['status'] === 302, 'invalid broker callback must redirect');
    assertTrue(
        $failedCallback['location'] === '/login?broker_error=failed'
            || $failedCallback['location'] === $baseUrl . '/login?broker_error=failed',
        'invalid broker callback must redirect to the contract-compliant login route'
    );

    $login = httpRequest('GET', $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/login?continue=' . rawurlencode('/broker-finished'));
    assertTrue($login['status'] >= 300 && $login['status'] < 400, 'broker login must redirect to provider');
    assertTrue(strpos($login['location'], $providerUrl . '/authorize?') === 0, 'broker login must target mock provider');

    $authorizeParts = parse_url($login['location']);
    parse_str($authorizeParts['query'] ?? '', $authorizeQuery);
    assertTrue(($authorizeParts['fragment'] ?? '') === 'provider-fragment', 'authorize redirect must preserve provider URI fragment');
    assertTrue(substr_count((string)$login['location'], 'client_id=') === 1, 'authorize redirect must replace stale client_id');
    assertTrue(($authorizeQuery['prompt'] ?? '') === 'login', 'authorize redirect must preserve existing query parameters');
    assertTrue(($authorizeQuery['client_id'] ?? '') === 'mock-client-' . $suffix, 'authorize redirect must include client_id');
    assertTrue(($authorizeQuery['redirect_uri'] ?? '') === $baseUrl . '/oauth2/broker/' . $providerCode . '/callback', 'authorize redirect must include callback URL');
    assertTrue(!empty($authorizeQuery['state']), 'authorize redirect must include signed state');

    $collisionCallback = httpRequest(
        'GET',
        $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/callback?' . http_build_query([
            'state' => $authorizeQuery['state'],
            'code' => $externalCode,
        ])
    );
    assertTrue($collisionCallback['status'] === 302, 'email collision must redirect');
    assertTrue(
        $collisionCallback['location'] === '/login?broker_error=failed'
            || $collisionCallback['location'] === $baseUrl . '/login?broker_error=failed',
        'email collision must fail without linking the local account'
    );
    $unexpectedLink = (int)$db->createCommand(
        'SELECT COUNT(*) FROM federated_identities WHERE provider_id = :provider_id AND external_subject = :subject',
        [':provider_id' => $providerId, ':subject' => $externalCode]
    )->queryScalar();
    assertTrue($unexpectedLink === 0, 'email collision must not create a federated identity link');

    $db->createCommand()->delete('users', ['id' => $localUserId])->execute();
    $localUserId = null;

    $retryLogin = httpRequest(
        'GET',
        $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/login?continue=' . rawurlencode('/broker-finished')
    );
    assertTrue($retryLogin['status'] >= 300 && $retryLogin['status'] < 400, 'broker retry must redirect to provider');
    $retryAuthorizeParts = parse_url($retryLogin['location']);
    parse_str($retryAuthorizeParts['query'] ?? '', $retryAuthorizeQuery);

    $callback = httpRequest(
        'GET',
        $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/callback?' . http_build_query([
            'state' => $retryAuthorizeQuery['state'] ?? '',
            'code' => $externalCode,
        ])
    );

    assertTrue(
        $callback['status'] >= 300 && $callback['status'] < 400,
        'broker callback must redirect back to continue URL, got '
            . $callback['status'] . ': ' . substr((string)$callback['body'], 0, 500)
    );
    $expectedRelative = '/broker-finished';
    $expectedAbsolute = $baseUrl . $expectedRelative;
    assertTrue(
        $callback['location'] === $expectedRelative || $callback['location'] === $expectedAbsolute,
        'broker callback must not expose an access token in the URL, got ' . $callback['location']
    );
    assertTrue(!empty($callback['cookies']['sso_session']), 'broker callback must set SSO session cookie');

    $sessionToken = httpRequest('POST', $baseUrl . '/api/v1/auth/session-token', $callback['cookies']);
    $sessionPayload = json_decode((string)$sessionToken['body'], true);
    assertTrue(
        $sessionToken['status'] === 200,
        'broker session cookie must mint an in-memory access token, got '
            . $sessionToken['status'] . ': ' . substr((string)$sessionToken['body'], 0, 500)
    );
    assertTrue(!empty($sessionPayload['access_token']), 'session-token response must include access_token');

    $missingSessionCsrf = httpRequest(
        'POST',
        $baseUrl . '/api/v1/auth/session-token',
        $callback['cookies'],
        null,
        true
    );
    assertTrue($missingSessionCsrf['status'] === 403, 'federated session restore without CSRF header must return 403');

    $mismatchedSessionCsrf = httpRequest(
        'POST',
        $baseUrl . '/api/v1/auth/session-token',
        $callback['cookies'],
        'mismatched-csrf-token'
    );
    assertTrue(
        $mismatchedSessionCsrf['status'] === 403,
        'federated session restore with mismatched CSRF token must return 403'
    );

    $user = $db->createCommand(
        'SELECT id, username, email, source, email_verified FROM users WHERE email = :email AND source = :source',
        [':email' => $externalCode . '@broker.example.test', ':source' => 'federated']
    )->queryOne();
    assertTrue((bool)$user, 'broker callback must provision federated user');
    assertTrue($user['source'] === 'federated', 'broker user must be federated');
    assertTrue((bool)$user['email_verified'] === false, 'broker provision must not mark an unverified external email as verified');
    $userId = $user['id'];

    $linked = (int)$db->createCommand(
        'SELECT COUNT(*) FROM federated_identities WHERE provider_id = :provider_id AND external_subject = :subject AND user_id = :user_id',
        [
            ':provider_id' => $providerId,
            ':subject' => $externalCode,
            ':user_id' => $userId,
        ]
    )->queryScalar();
    assertTrue($linked === 1, 'broker callback must link federated identity');

    $audit = (int)$db->createCommand(
        "SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = :user_id AND action = 'broker_login_success' AND target_id = :provider",
        [':user_id' => $userId, ':provider' => $providerCode]
    )->queryScalar();
    assertTrue($audit === 1, 'broker callback must audit success');

} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($userId === null) {
        $fixtureUserId = $db->createCommand(
            'SELECT id FROM users WHERE email = :email AND source = :source',
            [':email' => $externalCode . '@broker.example.test', ':source' => 'federated']
        )->queryScalar();
        $userId = $fixtureUserId === false ? null : $fixtureUserId;
    }
    if ($userId !== null) {
        $db->createCommand()->delete('sessions', ['user_id' => $userId])->execute();
        $db->createCommand()->delete('federated_identities', ['user_id' => $userId])->execute();
        $db->createCommand()->delete('audit_logs', ['or', ['actor_user_id' => $userId], ['target_id' => $userId]])->execute();
        $db->createCommand()->delete('users', ['id' => $userId])->execute();
    }
    if ($providerId !== null) {
        $db->createCommand()->delete('audit_logs', ['target_type' => 'identity_provider', 'target_id' => $providerId])->execute();
        $db->createCommand()->delete('identity_providers', ['id' => $providerId])->execute();
    }
    if ($localUserId !== null) {
        $db->createCommand()->delete('users', ['id' => $localUserId])->execute();
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}

echo "Broker mock flow passed\n";
