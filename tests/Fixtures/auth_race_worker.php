<?php

use app\models\User;
use app\services\AccountService;
use app\services\AuthService;
use app\services\MfaService;
use app\services\OAuthService;
use yii\console\Application;
use yii\web\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

new Application(require dirname(__DIR__, 2) . '/config/console.php');
Yii::$app->params = array_merge(require dirname(__DIR__, 2) . '/config/params.php', Yii::$app->params);

$startAt = (float)$argv[count($argv) - 1];
while (microtime(true) < $startAt) {
    usleep(1000);
}

switch ((string)($argv[1] ?? '')) {
    case 'password-reset':
        $result = (new AccountService())->resetPassword((string)$argv[2], (string)$argv[3]);
        echo (string)$result['status'];
        break;

    case 'mfa-login':
        $user = (new MfaService())->verifyLoginChallenge((string)$argv[2], (string)$argv[3]);
        echo $user ? 'success' : 'rejected';
        break;

    case 'oauth-refresh':
        $request = new Request([
            'enableCookieValidation' => false,
            'cookieValidationKey' => 'race-worker-cookie-key',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ]);
        $request->setBodyParams([
            'grant_type' => 'refresh_token',
            'client_id' => (string)$argv[2],
            'client_secret' => (string)$argv[3],
            'refresh_token' => (string)$argv[4],
        ]);
        $result = (new OAuthService())->token($request);
        echo $result['ok'] ? 'success' : (string)$result['error'];
        break;

    case 'logout':
        (new AuthService())->logout((string)$argv[2]);
        echo 'success';
        break;

    case 'revoke-all':
        $user = User::findOne(['id' => (string)$argv[2]]);
        if (!$user) {
            throw new RuntimeException('race worker user not found');
        }
        (new AuthService())->revokeAllSessions($user);
        echo 'success';
        break;

    default:
        throw new RuntimeException('unknown race worker mode');
}
