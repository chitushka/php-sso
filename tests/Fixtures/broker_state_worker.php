<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

new yii\console\Application(require __DIR__ . '/../../config/console.php');

$providerCode = (string)($argv[1] ?? '');
$state = (string)($argv[2] ?? '');
$browserContext = (string)($argv[3] ?? '');
$startAt = (float)($argv[4] ?? microtime(true));

while (microtime(true) < $startAt) {
    usleep(1000);
}

$consumed = (new app\services\BrokerService())->consumeRejectedCallback(
    $providerCode,
    $state,
    $browserContext
);

echo $consumed ? '1' : '0';
