<?php

$db = require __DIR__ . '/db.php';
$params = require __DIR__ . '/params.php';

return [
    'id' => 'sso-php-console',
    'basePath' => dirname(__DIR__),
    'container' => [
        'definitions' => [
            app\services\contracts\ClockInterface::class => app\services\SystemClock::class,
            app\services\contracts\RandomGeneratorInterface::class => app\services\SecureRandomGenerator::class,
        ],
    ],
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'params' => $params,
    'components' => [
        'db' => $db,
        'cache' => ($params['cacheDriver'] === 'db')
            ? [
                'class' => yii\caching\DbCache::class,
                'db' => 'db',
                'cacheTable' => 'cache',
            ]
            : [
                'class' => yii\caching\FileCache::class,
            ],
        'log' => [
            'targets' => [[
                'class' => app\components\JsonLogTarget::class,
                'levels' => ['error', 'warning', 'info'],
                'except' => ['yii\\db\\Command*', 'yii\\db\\Connection*'],
                'logVars' => [],
            ]],
        ],
    ],
    'bootstrap' => ['log'],
];
