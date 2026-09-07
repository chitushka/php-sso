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
    'components' => [
        'db' => $db,
    ],
];
