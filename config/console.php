<?php

$db = require __DIR__ . '/db.php';

return [
    'id' => 'sso-php-console',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'params' => require __DIR__ . '/params.php',
    'components' => [
        'db' => $db,
        'cache' => (getenv('SSO_CACHE_DRIVER') === 'db')
            ? [
                'class' => yii\caching\DbCache::class,
                'db' => 'db',
                'cacheTable' => 'cache',
            ]
            : [
                'class' => yii\caching\FileCache::class,
            ],
    ],
];
