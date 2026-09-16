<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

use app\components\TrustedRequest;
use app\services\PasswordPolicy;
use yii\web\Application;

function failTest(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        failTest($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

new Application([
    'id' => 'sso-unit',
    'basePath' => dirname(__DIR__, 2),
    'components' => [
        'request' => yii\web\Request::class,
    ],
    'params' => [
        'trustedProxies' => '',
    ],
]);

function resolvedIp(string $remoteAddr, string $forwardedFor, string $trustedProxies): string
{
    Yii::$app->params['trustedProxies'] = $trustedProxies;
    $_SERVER['REMOTE_ADDR'] = $remoteAddr;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
    $request = new TrustedRequest([
        'cookieValidationKey' => 'unit-test-cookie-validation-key',
        'scriptFile' => __FILE__,
        'scriptUrl' => '/index.php',
    ]);
    $request->headers->set('X-Forwarded-For', $forwardedFor);

    return $request->getUserIP();
}

assertSameValue(
    'password must contain at least 12 characters',
    PasswordPolicy::validate('short'),
    'short password must be rejected'
);
assertSameValue(null, PasswordPolicy::validate(str_repeat('a', 12)), '12-char password must be accepted');
assertSameValue(null, PasswordPolicy::validate(str_repeat('a', 128)), '128-char password must be accepted');
assertSameValue(
    'password must not exceed 128 characters',
    PasswordPolicy::validate(str_repeat('a', 129)),
    '129-char password must be rejected'
);

assertSameValue(
    '198.51.100.10',
    resolvedIp('198.51.100.10', '203.0.113.7', ''),
    'untrusted peer must ignore X-Forwarded-For'
);
assertSameValue(
    '203.0.113.7',
    resolvedIp('10.10.1.5', '203.0.113.7, 10.10.1.4', '10.10.0.0/16'),
    'trusted proxy must select rightmost untrusted X-Forwarded-For address'
);
assertSameValue(
    '2001:db8::15',
    resolvedIp('fd00::1', '2001:db8::15, fd00::2', 'fd00::/8'),
    'trusted IPv6 proxy must support CIDR matching'
);
assertSameValue(
    '203.0.113.7',
    resolvedIp('198.51.100.10:52311', '203.0.113.7', '198.51.100.0/24'),
    'remote address with port must be normalized before trusted proxy lookup'
);

echo "Unit security checks passed\n";
