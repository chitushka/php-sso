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

function failTest(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function httpRequest(string $method, string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
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
            ':authorize_url' => $providerUrl . '/authorize',
            ':token_url' => $providerUrl . '/token',
            ':userinfo_url' => $providerUrl . '/userinfo',
        ]
    )->queryScalar();

    $login = httpRequest('GET', $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/login?continue=' . rawurlencode('/broker-finished'));
    assertTrue($login['status'] >= 300 && $login['status'] < 400, 'broker login must redirect to provider');
    assertTrue(strpos($login['location'], $providerUrl . '/authorize?') === 0, 'broker login must target mock provider');

    $authorizeParts = parse_url($login['location']);
    parse_str($authorizeParts['query'] ?? '', $authorizeQuery);
    assertTrue(($authorizeQuery['client_id'] ?? '') === 'mock-client-' . $suffix, 'authorize redirect must include client_id');
    assertTrue(($authorizeQuery['redirect_uri'] ?? '') === $baseUrl . '/oauth2/broker/' . $providerCode . '/callback', 'authorize redirect must include callback URL');
    assertTrue(!empty($authorizeQuery['state']), 'authorize redirect must include signed state');

    $callback = httpRequest(
        'GET',
        $baseUrl . '/oauth2/broker/' . rawurlencode($providerCode) . '/callback?' . http_build_query([
            'state' => $authorizeQuery['state'],
            'code' => $externalCode,
        ])
    );

    assertTrue($callback['status'] >= 300 && $callback['status'] < 400, 'broker callback must redirect back to continue URL, got ' . $callback['status']);
    $expectedRelative = '/broker-finished#broker_token=';
    $expectedAbsolute = $baseUrl . $expectedRelative;
    assertTrue(
        strpos($callback['location'], $expectedRelative) === 0 || strpos($callback['location'], $expectedAbsolute) === 0,
        'broker callback must include broker token fragment, got ' . $callback['location']
    );
    assertTrue(!empty($callback['cookies']['sso_session']), 'broker callback must set SSO session cookie');

    $user = $db->createCommand(
        'SELECT id, username, email, source, email_verified FROM users WHERE email = :email',
        [':email' => $externalCode . '@broker.example.test']
    )->queryOne();
    assertTrue((bool)$user, 'broker callback must provision federated user');
    assertTrue($user['source'] === 'federated', 'broker user must be federated');
    assertTrue((bool)$user['email_verified'] === true, 'broker user email must be verified');
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

    echo "Broker mock flow passed\n";
} finally {
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
}
