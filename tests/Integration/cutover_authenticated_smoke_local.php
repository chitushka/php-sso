<?php

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

$app = new yii\console\Application(require __DIR__ . '/../../config/console.php');
$db = Yii::$app->db;
$suffix = strtolower(bin2hex(random_bytes(5)));
$username = 'cutover-smoke-' . $suffix;
$password = 'Cutover-' . bin2hex(random_bytes(16));
$clientId = 'cutover-smoke-client-' . $suffix;
$clientSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$redirectUri = 'https://client.example.test/callback/' . $suffix;
$logoutRedirectUri = 'https://client.example.test/logout/' . $suffix;
$userId = null;
$clientDbId = null;

// Shutdown handler очищает fixtures, даже если основной smoke завершится через exit(1).
register_shutdown_function(static function () use ($db, &$userId, &$clientDbId): void {
    if ($clientDbId !== null) {
        $db->createCommand('DELETE FROM oauth_clients WHERE id = :id', [':id' => $clientDbId])->execute();
    }
    if ($userId !== null) {
        $db->createCommand('DELETE FROM users WHERE id = :id', [':id' => $userId])->execute();
    }
});

$userId = (string)$db->createCommand(
    "INSERT INTO users(username,email,password_hash,status,source,email_verified)
     VALUES(:username,:email,:password_hash,'active','local',true)
     RETURNING id",
    [
        ':username' => $username,
        ':email' => $username . '@example.test',
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]
)->queryScalar();

$client = new app\models\OAuthClient();
$client->client_id = $clientId;
$client->name = 'Authenticated cutover smoke fixture ' . $suffix;
$client->client_secret_hash = password_hash($clientSecret, PASSWORD_DEFAULT);
$client->type = app\models\OAuthClient::TYPE_CONFIDENTIAL;
$client->redirect_uris = app\models\OAuthClient::toPgArray([$redirectUri]);
$client->allowed_scopes = app\models\OAuthClient::toPgArray(['openid', 'profile', 'email']);
$client->post_logout_redirect_uris = app\models\OAuthClient::toPgArray([$logoutRedirectUri]);
$client->skip_consent = true;
$client->enabled = true;
if (!$client->save()) {
    throw new RuntimeException('cannot create local OAuth client fixture');
}
$clientDbId = (string)$client->id;

putenv('SSO_TEST_BASE_URL=https://https-proxy');
putenv('SSO_TEST_EXPECTED_ISSUER=https://localhost:8443');
putenv('SSO_TEST_TLS_INSECURE=true');
putenv('SSO_CUTOVER_SMOKE_USERNAME=' . $username);
putenv('SSO_CUTOVER_SMOKE_PASSWORD=' . $password);
putenv('SSO_CUTOVER_SMOKE_CLIENT_ID=' . $clientId);
putenv('SSO_CUTOVER_SMOKE_CLIENT_SECRET=' . $clientSecret);
putenv('SSO_CUTOVER_SMOKE_REDIRECT_URI=' . $redirectUri);
putenv('SSO_CUTOVER_SMOKE_LOGOUT_REDIRECT_URI=' . $logoutRedirectUri);
putenv('SSO_CUTOVER_SMOKE_SCOPE=openid profile email');
putenv('SSO_CUTOVER_SMOKE_GRANT_CONSENT=false');

require __DIR__ . '/cutover_authenticated_smoke.php';
